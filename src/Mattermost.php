<?php

declare(strict_types=1);

namespace AwsDash;

/**
 * Minimal Mattermost REST v4 client — the only outbound-HTTP surface in the app.
 *
 * Posts task digests to the configured check-in / check-out channels using a bearer access
 * token. From the API's perspective a *personal* access token and a *bot* token are identical
 * (both `Authorization: Bearer <token>` + `POST /api/v4/posts`), so this one path serves either:
 * the operator pastes whichever their server lets them mint.
 *
 * Security: every call is HTTPS with TLS peer/host verification and a hard timeout (a hung
 * outbound call would otherwise tie up one of the multi-worker server's processes). The token
 * is sent ONLY in the Authorization header, only to the operator-configured base URL (validated
 * https + host-only at save time in Store), and is never written to a log or returned to the
 * browser.
 */
final class Mattermost
{
    private string $baseUrl;
    private string $token;
    private int $timeout;

    /** @param array<string,mixed> $config a record from Store::mattermost() */
    public function __construct(array $config, int $timeout = 10)
    {
        $this->baseUrl = rtrim((string) ($config['base_url'] ?? ''), '/');
        $this->token = (string) ($config['token'] ?? '');
        $this->timeout = $timeout;
    }

    public function isConfigured(): bool
    {
        return $this->baseUrl !== '' && $this->token !== '';
    }

    /** The validated https origin (host[:port]) — used to derive the wss:// socket URL. */
    public function baseUrl(): string
    {
        return $this->baseUrl;
    }

    public function token(): string
    {
        return $this->token;
    }

    /**
     * GET /api/v4/users/me — validates the token and reveals the identity posts are made AS.
     *
     * @return array<string,mixed>
     */
    public function me(): array
    {
        [$status, $body] = $this->request('GET', '/users/me');
        if ($status !== 200) {
            throw new \RuntimeException($this->errMsg($status, $body, 'Authentication failed — check the token'));
        }
        return is_array($body) ? $body : [];
    }

    /**
     * Resolve a channel id from the team + channel "name" (the slug used in the channel URL,
     * e.g. .../channels/check-in -> name "check-in"). Ids are then cached so a rename doesn't
     * silently break posting until the next Test.
     */
    public function resolveChannelId(string $team, string $channelName): string
    {
        $path = '/teams/name/' . rawurlencode($team) . '/channels/name/' . rawurlencode($channelName);
        [$status, $body] = $this->request('GET', $path);
        if ($status !== 200 || !is_array($body) || empty($body['id'])) {
            throw new \RuntimeException($this->errMsg(
                $status,
                $body,
                "Channel '{$channelName}' not found in team '{$team}' (is the channel name/slug correct and are you a member?)"
            ));
        }
        return (string) $body['id'];
    }

    /** POST /api/v4/posts — create a message in a channel. Returns the new post id. */
    public function post(string $channelId, string $message): string
    {
        [$status, $body] = $this->request('POST', '/posts', ['channel_id' => $channelId, 'message' => $message]);
        if ($status !== 201 || !is_array($body) || empty($body['id'])) {
            throw new \RuntimeException($this->errMsg($status, $body, 'Failed to post message'));
        }
        return (string) $body['id'];
    }

    /**
     * POST /api/v4/reactions — add an emoji reaction to a post. Used by the @Claude listener as a
     * quiet "captured" acknowledgement on the source message (a reaction, unlike a reply, does not
     * itself generate a `posted` event, so it cannot re-trigger intake). $userId must be the token
     * owner's id (from me()).
     */
    public function addReaction(string $postId, string $userId, string $emoji = 'white_check_mark'): void
    {
        [$status, $body] = $this->request('POST', '/reactions', [
            'user_id' => $userId,
            'post_id' => $postId,
            'emoji_name' => $emoji,
        ]);
        if ($status !== 200 && $status !== 201) {
            throw new \RuntimeException($this->errMsg($status, $body, 'Failed to add reaction'));
        }
    }

    /**
     * GET /api/v4/posts/{id} — fetch a single post. Used by the @Claude listener to pull the
     * message a command is replying to (its thread root), so Claude can use it as context.
     *
     * @return array<string,mixed>
     */
    public function getPost(string $postId): array
    {
        [$status, $body] = $this->request('GET', '/posts/' . rawurlencode($postId));
        if ($status !== 200 || !is_array($body)) {
            throw new \RuntimeException($this->errMsg($status, $body, 'Failed to fetch post'));
        }
        return $body;
    }

    /**
     * @param array<string,mixed>|null $json request body (null = no body)
     * @return array{0:int,1:mixed} [http status, decoded JSON body or null]
     */
    private function request(string $method, string $path, ?array $json = null): array
    {
        if ($this->baseUrl === '' || $this->token === '') {
            throw new \RuntimeException('Mattermost is not configured (set the server URL and token).');
        }
        $ch = curl_init($this->baseUrl . '/api/v4' . $path);
        if ($ch === false) {
            throw new \RuntimeException('Failed to initialize the HTTP client.');
        }
        $headers = ['Authorization: Bearer ' . $this->token, 'Accept: application/json'];
        $opts = [
            CURLOPT_CUSTOMREQUEST => $method,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_CONNECTTIMEOUT => $this->timeout,
            CURLOPT_TIMEOUT => $this->timeout,
            CURLOPT_SSL_VERIFYPEER => true, // never downgrade TLS verification — the token rides this connection
            CURLOPT_SSL_VERIFYHOST => 2,
            CURLOPT_FOLLOWLOCATION => false, // don't follow a redirect that could replay the bearer token elsewhere
        ];
        if ($json !== null) {
            $payload = json_encode($json, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
            if ($payload === false) {
                curl_close($ch);
                throw new \RuntimeException('Failed to encode the request payload.');
            }
            $opts[CURLOPT_POSTFIELDS] = $payload;
            $headers[] = 'Content-Type: application/json';
        }
        $opts[CURLOPT_HTTPHEADER] = $headers;
        curl_setopt_array($ch, $opts);

        $raw = curl_exec($ch);
        if ($raw === false) {
            $err = curl_error($ch);
            curl_close($ch);
            throw new \RuntimeException('Could not reach Mattermost: ' . $err);
        }
        $status = (int) curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
        curl_close($ch);

        return [$status, json_decode((string) $raw, true)];
    }

    /** Surface Mattermost's own error message when present, never echoing the request/token. */
    private function errMsg(int $status, mixed $body, string $fallback): string
    {
        if (is_array($body) && isset($body['message']) && is_string($body['message']) && $body['message'] !== '') {
            return $body['message'] . ' (HTTP ' . $status . ')';
        }
        return $fallback . ' (HTTP ' . $status . ').';
    }
}
