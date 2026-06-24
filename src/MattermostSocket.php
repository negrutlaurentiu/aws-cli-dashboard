<?php

declare(strict_types=1);

namespace AwsDash;

/**
 * Minimal RFC 6455 WebSocket CLIENT over a stock TLS stream — no Composer/PECL deps (this app
 * deliberately ships none). It exists for ONE job: hold an authenticated socket open to Mattermost
 * and read `posted` events so bin/mm-listen can turn "@Claude …" messages into tasks. Webhooks and
 * slash commands can't be used (they POST a callback TO us, and the dashboard binds 127.0.0.1 only,
 * unreachable from the remote server); the WebSocket is opened OUTBOUND from this machine, so no
 * inbound reachability is needed.
 *
 * Security:
 *  - TLS peer + hostname verification are ALWAYS on (verify_peer / verify_peer_name) — the bearer
 *    token rides this connection, so it is never downgraded, mirroring Mattermost.php's curl setup.
 *  - The token is sent only in the post-handshake `authentication_challenge` frame, only to the
 *    operator-validated host (Store::normalizeBaseUrl guarantees https + host-only), and is never
 *    logged or echoed.
 *  - We connect to the same host the REST client already trusts; no new outbound host is introduced.
 *
 * Scope: handles the subset of RFC 6455 a client needs against Mattermost — masked client frames,
 * unmasked server frames, text + control (ping/pong/close) opcodes, 7/16/64-bit lengths, and
 * (defensively) continuation frames. Payloads are tiny JSON, so a per-byte mask loop is fine.
 */
final class MattermostSocket
{
    /** RFC 6455 §4.2.2 — magic GUID concatenated to the client key to derive Sec-WebSocket-Accept. */
    private const GUID = '258EAFA5-E914-47DA-95CA-C5AB0DC85B11';

    private const OP_CONT  = 0x0;
    private const OP_TEXT  = 0x1;
    private const OP_CLOSE = 0x8;
    private const OP_PING  = 0x9;
    private const OP_PONG  = 0xA;

    /** Hard ceiling on a single frame's payload, so a buggy/hostile server can't exhaust memory. */
    private const MAX_FRAME = 8 * 1024 * 1024;

    private string $host;
    private int $port;
    private string $token;
    private int $readTimeout;

    /** @var resource|null */
    private $sock = null;
    /** Bytes already read from the socket but not yet consumed by the frame parser. */
    private string $buf = '';
    private int $seq = 1;

    public function __construct(string $baseUrl, string $token, int $readTimeout = 10)
    {
        // $baseUrl is the already-validated https host-only origin (Store::normalizeBaseUrl).
        $p = parse_url($baseUrl);
        $this->host = strtolower((string) ($p['host'] ?? ''));
        $this->port = (int) ($p['port'] ?? 443);
        $this->token = $token;
        $this->readTimeout = max(1, $readTimeout);
    }

    /** Open the TLS socket and perform the HTTP/1.1 Upgrade handshake. Throws on any failure. */
    public function connect(int $connectTimeout = 10): void
    {
        if ($this->host === '') {
            throw new \RuntimeException('Mattermost server URL is not configured.');
        }
        $ctx = stream_context_create(['ssl' => [
            'verify_peer' => true,        // never downgrade — the token rides this connection
            'verify_peer_name' => true,
            'peer_name' => $this->host,
            'SNI_enabled' => true,
        ]]);
        $errno = 0;
        $errstr = '';
        $sock = @stream_socket_client(
            'ssl://' . $this->host . ':' . $this->port,
            $errno,
            $errstr,
            $connectTimeout,
            STREAM_CLIENT_CONNECT,
            $ctx
        );
        if ($sock === false) {
            throw new \RuntimeException('WebSocket connect failed: ' . ($errstr !== '' ? $errstr : ('errno ' . $errno)));
        }
        $this->sock = $sock;
        stream_set_blocking($this->sock, true);
        $this->handshake();
        stream_set_timeout($this->sock, $this->readTimeout);
    }

    /**
     * Authenticate with the personal/bot bearer token and wait for the server `hello`. Throws if no
     * hello arrives (a bad/expired token makes Mattermost reject auth and close the socket).
     *
     * @return array<string,mixed> the hello event
     */
    public function authenticate(): array
    {
        $payload = json_encode([
            'seq' => $this->seq++,
            'action' => 'authentication_challenge',
            'data' => ['token' => $this->token],
        ], JSON_UNESCAPED_SLASHES);
        $this->sendText((string) $payload);

        // hello normally arrives in well under a second; bound the wait so we never hang here.
        for ($i = 0; $i < 6; $i++) {
            $evt = $this->read();
            if ($evt === null) {
                continue; // idle read timeout — keep waiting (bounded)
            }
            if (($evt['event'] ?? '') === 'hello') {
                return $evt;
            }
            if (($evt['status'] ?? '') === 'FAIL') {
                throw new \RuntimeException('Mattermost rejected the access token.');
            }
        }
        throw new \RuntimeException('No hello received after authentication (token may be invalid).');
    }

    /**
     * Read the next application (text) message as a decoded JSON array. Returns null when the read
     * idled out with no frame waiting (so the caller can do periodic housekeeping and loop). Handles
     * ping→pong and throws on a close frame / dropped connection (caller reconnects with backoff).
     *
     * @return array<string,mixed>|null
     */
    public function read(): ?array
    {
        // Bound the control/ignored frames handled per call. A hostile/buggy server that streams
        // nothing but ping/pong/binary frames must not trap us in this loop forever — the supervisor
        // (bin/mm-listen) only regains control BETWEEN read() calls to heartbeat, honour a stop, and
        // re-read config. When the budget runs out we return null (an idle tick) and the caller simply
        // calls read() again, keeping the daemon responsive and killable under a flood.
        $controlBudget = 64;
        while (true) {
            $frame = $this->readFrame(true);
            if ($frame === null) {
                return null; // idle: nothing waiting
            }
            switch ($frame['op']) {
                case self::OP_PING:
                    $this->sendFrame(self::OP_PONG, $frame['payload']);
                    if (--$controlBudget <= 0) {
                        return null;
                    }
                    continue 2;
                case self::OP_PONG:
                    if (--$controlBudget <= 0) {
                        return null;
                    }
                    continue 2;
                case self::OP_CLOSE:
                    throw new \RuntimeException('Server sent a close frame.');
                case self::OP_TEXT:
                case self::OP_CONT:
                    $data = $frame['payload'];
                    while (!$frame['fin']) {           // reassemble a fragmented message
                        $frame = $this->readFrame(false);
                        if ($frame === null) {
                            throw new \RuntimeException('Connection stalled mid-message.');
                        }
                        if ($frame['op'] === self::OP_PING) {
                            $this->sendFrame(self::OP_PONG, $frame['payload']);
                            continue;
                        }
                        if ($frame['op'] === self::OP_CLOSE) {
                            throw new \RuntimeException('Server sent a close frame.');
                        }
                        $data .= $frame['payload'];
                        // MAX_FRAME bounds a SINGLE frame (readFrame); it does NOT bound a flood of
                        // continuation frames, which could otherwise exhaust memory and crash us.
                        if (strlen($data) > self::MAX_FRAME) {
                            throw new \RuntimeException('Reassembled message exceeds the size limit.');
                        }
                    }
                    $decoded = json_decode($data, true);
                    return is_array($decoded) ? $decoded : []; // unparseable → empty (caller ignores)
                default:
                    if (--$controlBudget <= 0) {
                        return null;
                    }
                    continue 2; // binary / reserved — ignore
            }
        }
    }

    public function ping(): void
    {
        $this->sendFrame(self::OP_PING, '');
    }

    public function sendText(string $text): void
    {
        $this->sendFrame(self::OP_TEXT, $text);
    }

    public function close(): void
    {
        if (is_resource($this->sock)) {
            try {
                $this->sendFrame(self::OP_CLOSE, pack('n', 1000)); // 1000 = normal closure
            } catch (\Throwable) {
                // best-effort; socket may already be gone
            }
            @fclose($this->sock);
        }
        $this->sock = null;
        $this->buf = '';
    }

    // ---- handshake & framing ----------------------------------------------

    private function handshake(): void
    {
        $key = base64_encode(random_bytes(16));
        $authority = $this->host . ($this->port !== 443 ? ':' . $this->port : '');
        $req = "GET /api/v4/websocket HTTP/1.1\r\n"
             . "Host: {$authority}\r\n"
             . "User-Agent: aws-cli-dashboard\r\n"
             . "Connection: Upgrade\r\n"
             . "Upgrade: websocket\r\n"
             . "Sec-WebSocket-Key: {$key}\r\n"
             . "Sec-WebSocket-Version: 13\r\n"
             // Mattermost checks Origin == Host (CSRF guard); send our own origin to pass it.
             . "Origin: https://{$authority}\r\n"
             . "\r\n";
        stream_set_timeout($this->sock, 10);
        $this->writeAll($req);

        $resp = '';
        while (!str_contains($resp, "\r\n\r\n")) {
            $chunk = fread($this->sock, 1024);
            if ($chunk === false || $chunk === '') {
                $meta = stream_get_meta_data($this->sock);
                if (!empty($meta['timed_out'])) {
                    throw new \RuntimeException('WebSocket handshake timed out.');
                }
                if (feof($this->sock)) {
                    throw new \RuntimeException('Server closed the connection during the handshake.');
                }
                continue;
            }
            $resp .= $chunk;
            if (strlen($resp) > 16384) {
                throw new \RuntimeException('Oversized handshake response.');
            }
        }
        $pos = strpos($resp, "\r\n\r\n");
        $headers = substr($resp, 0, (int) $pos);
        $this->buf = substr($resp, (int) $pos + 4); // any frame bytes that trailed the headers

        if (!preg_match('#^HTTP/1\.1 101#', $headers)) {
            throw new \RuntimeException('WebSocket upgrade refused: ' . (string) strtok($headers, "\r\n"));
        }
        if (!preg_match('/Sec-WebSocket-Accept:\s*(\S+)/i', $headers, $m)) {
            throw new \RuntimeException('Handshake missing Sec-WebSocket-Accept.');
        }
        $expected = base64_encode(sha1($key . self::GUID, true));
        if (!hash_equals($expected, trim($m[1]))) {
            throw new \RuntimeException('Handshake integrity check failed (bad Sec-WebSocket-Accept).');
        }
    }

    /**
     * Read one frame. With $allowIdle, an idle read timeout on the FIRST byte returns null (no frame
     * waiting); once any header byte is read, the rest of the frame must complete or we throw.
     *
     * @return array{fin:bool,op:int,payload:string}|null
     */
    private function readFrame(bool $allowIdle): ?array
    {
        $h = $this->readExactly(2, $allowIdle);
        if ($h === null) {
            return null;
        }
        $b0 = ord($h[0]);
        $b1 = ord($h[1]);
        $fin = ($b0 & 0x80) !== 0;
        $op = $b0 & 0x0F;
        $masked = ($b1 & 0x80) !== 0;
        $len = $b1 & 0x7F;

        if ($len === 126) {
            $ext = $this->readExactly(2, false);
            $len = (int) unpack('n', (string) $ext)[1];
        } elseif ($len === 127) {
            $ext = $this->readExactly(8, false);
            $parts = unpack('N2', (string) $ext);
            $len = ($parts[1] << 32) | $parts[2]; // PHP ints are 64-bit on this platform
        }
        if ($masked) {
            // RFC 6455 §5.1: server-to-client frames MUST NOT be masked.
            throw new \RuntimeException('Server sent a masked frame (protocol violation).');
        }
        if ($len < 0 || $len > self::MAX_FRAME) {
            throw new \RuntimeException('Frame payload length out of range.');
        }
        $payload = $len > 0 ? (string) $this->readExactly($len, false) : '';
        return ['fin' => $fin, 'op' => $op, 'payload' => $payload];
    }

    private function readExactly(int $n, bool $allowIdle): ?string
    {
        $out = '';
        if ($this->buf !== '') {
            if (strlen($this->buf) >= $n) {
                $out = substr($this->buf, 0, $n);
                $this->buf = substr($this->buf, $n);
                return $out;
            }
            $out = $this->buf;
            $this->buf = '';
        }
        while (strlen($out) < $n) {
            $chunk = fread($this->sock, $n - strlen($out));
            if ($chunk === false || $chunk === '') {
                $meta = stream_get_meta_data($this->sock);
                if (!empty($meta['timed_out'])) {
                    if ($allowIdle && $out === '') {
                        return null; // idle: no frame had started
                    }
                    throw new \RuntimeException('Read timed out mid-frame.');
                }
                if (feof($this->sock)) {
                    throw new \RuntimeException('Connection closed by server.');
                }
                continue;
            }
            $out .= $chunk;
        }
        return $out;
    }

    private function sendFrame(int $op, string $payload): void
    {
        if (!is_resource($this->sock)) {
            throw new \RuntimeException('Socket is not open.');
        }
        $len = strlen($payload);
        $header = chr(0x80 | ($op & 0x0F)); // FIN=1, single frame
        if ($len < 126) {
            $header .= chr(0x80 | $len); // MASK=1 (client frames MUST be masked)
        } elseif ($len < 65536) {
            $header .= chr(0x80 | 126) . pack('n', $len);
        } else {
            $header .= chr(0x80 | 127) . pack('J', $len);
        }
        $mask = random_bytes(4);
        $header .= $mask;
        $masked = '';
        for ($i = 0; $i < $len; $i++) {
            $masked .= $payload[$i] ^ $mask[$i % 4];
        }
        $this->writeAll($header . $masked);
    }

    private function writeAll(string $data): void
    {
        $total = strlen($data);
        $written = 0;
        while ($written < $total) {
            $n = @fwrite($this->sock, substr($data, $written));
            if ($n === false || $n === 0) {
                $meta = stream_get_meta_data($this->sock);
                if (!empty($meta['timed_out'])) {
                    throw new \RuntimeException('Write timed out.');
                }
                throw new \RuntimeException('Failed to write to the socket.');
            }
            $written += $n;
        }
    }
}
