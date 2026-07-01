<?php

declare(strict_types=1);

namespace AwsDash;

/**
 * Minimal READ-ONLY Redmine REST client — reconciles dashboard-tracked hours against the hours
 * actually logged in Redmine. Mirrors Mattermost.php's hardened outbound shape: HTTPS with TLS
 * peer/host verification, no redirects (so the API key can't be replayed to another host), and a
 * hard timeout (a hung call would otherwise tie up one of the multi-worker server's processes).
 *
 * The API key is sent ONLY in the X-Redmine-API-Key header, only to the base URL derived from the
 * operator-configured project URL (validated https + host in Store::redmineRef), never logged and
 * never returned to the browser.
 */
final class Redmine
{
    private string $baseUrl;
    private string $apiKey;
    private int $timeout;

    public function __construct(string $baseUrl, string $apiKey, int $timeout = 15)
    {
        $this->baseUrl = rtrim($baseUrl, '/');
        $this->apiKey = $apiKey;
        $this->timeout = $timeout;
    }

    public function isConfigured(): bool
    {
        return $this->baseUrl !== '' && $this->apiKey !== '';
    }

    /**
     * GET /users/current.json — the user the key belongs to. Its id filters time entries to the
     * operator's own logged hours (the same login can be a different user id on each instance).
     */
    public function currentUserId(): int
    {
        [$status, $body] = $this->request('/users/current.json');
        if ($status !== 200 || !is_array($body) || !isset($body['user']['id'])) {
            throw new \RuntimeException($this->errMsg($status, $body, 'Could not identify the Redmine user for this key'));
        }
        return (int) $body['user']['id'];
    }

    /**
     * Sum the hours $userId logged on $projectIdentifier between $from..$to (inclusive YYYY-MM-DD),
     * paginating the time_entries listing. Returns total hours.
     */
    public function loggedHours(string $projectIdentifier, int $userId, string $from, string $to): float
    {
        $total = 0.0;
        $offset = 0;
        $limit = 100;
        // Hard page cap (2000 entries) so a pathological filter can't loop the worker forever.
        for ($page = 0; $page < 20; $page++) {
            $q = http_build_query([
                'user_id' => $userId,
                'project_id' => $projectIdentifier,
                'from' => $from,
                'to' => $to,
                'limit' => $limit,
                'offset' => $offset,
            ]);
            [$status, $body] = $this->request('/time_entries.json?' . $q);
            if ($status !== 200 || !is_array($body) || !is_array($body['time_entries'] ?? null)) {
                throw new \RuntimeException($this->errMsg($status, $body, 'Could not read Redmine time entries'));
            }
            foreach ($body['time_entries'] as $te) {
                if (is_array($te) && isset($te['hours'])) {
                    $total += (float) $te['hours'];
                }
            }
            $count = count($body['time_entries']);
            $totalCount = (int) ($body['total_count'] ?? ($offset + $count));
            $offset += $count;
            if ($count === 0 || $offset >= $totalCount) {
                return $total;
            }
        }
        // Hit the page cap (2000 entries) with more still unread: refuse to present a truncated sum
        // as a confident total — it would read as a false "short". Surface it as an error instead.
        throw new \RuntimeException('Too many Redmine time entries to total reliably for this window.');
    }

    /**
     * @return array{0:int,1:mixed} [http status, decoded JSON body or null]
     */
    private function request(string $path): array
    {
        if ($this->baseUrl === '' || $this->apiKey === '') {
            throw new \RuntimeException('Redmine is not configured (missing URL or API key).');
        }
        $ch = curl_init($this->baseUrl . $path);
        if ($ch === false) {
            throw new \RuntimeException('Failed to initialize the HTTP client.');
        }
        curl_setopt_array($ch, [
            CURLOPT_CUSTOMREQUEST => 'GET',
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_CONNECTTIMEOUT => min($this->timeout, 8),
            CURLOPT_TIMEOUT => $this->timeout,
            CURLOPT_SSL_VERIFYPEER => true, // never downgrade — the API key rides this connection
            CURLOPT_SSL_VERIFYHOST => 2,
            CURLOPT_FOLLOWLOCATION => false, // a redirect must not replay the key to another host
            CURLOPT_HTTPHEADER => ['X-Redmine-API-Key: ' . $this->apiKey, 'Accept: application/json'],
        ]);
        $raw = curl_exec($ch);
        if ($raw === false) {
            throw new \RuntimeException('Could not reach Redmine: ' . curl_error($ch));
        }
        $status = (int) curl_getinfo($ch, CURLINFO_RESPONSE_CODE);

        return [$status, json_decode((string) $raw, true)];
    }

    /** Surface Redmine's own error(s) ({"errors":[...]}) when present, never echoing the key. */
    private function errMsg(int $status, mixed $body, string $fallback): string
    {
        if (is_array($body) && !empty($body['errors']) && is_array($body['errors'])) {
            return implode('; ', array_map('strval', $body['errors'])) . ' (HTTP ' . $status . ')';
        }
        if ($status === 401 || $status === 403) {
            return $fallback . ' — check the API key (HTTP ' . $status . ').';
        }
        return $fallback . ' (HTTP ' . $status . ').';
    }
}
