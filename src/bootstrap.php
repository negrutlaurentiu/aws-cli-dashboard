<?php

declare(strict_types=1);

namespace AwsDash;

require __DIR__ . '/Totp.php';
require __DIR__ . '/CredentialsFile.php';
require __DIR__ . '/Sts.php';
require __DIR__ . '/Store.php';

/**
 * Shared configuration + security helpers for the dashboard. Everything here assumes a
 * single trusted local user; the protections below exist to stop *other* web pages or
 * other machines from driving the dashboard, not to authenticate the operator.
 */
final class App
{
    public const HOST = '127.0.0.1';
    public const PORT = 8010;

    public string $projectDir;
    public string $configDir;
    public string $awsDir;
    public string $credentialsPath;
    public string $awsConfigPath;
    public Store $store;
    public string $awsBin;

    public function __construct()
    {
        $this->projectDir = \dirname(__DIR__);
        $this->configDir = $this->projectDir . '/config';

        $home = self::homeDir();
        $this->awsDir = $home . '/.aws';
        $this->credentialsPath = getenv('AWS_SHARED_CREDENTIALS_FILE') ?: ($this->awsDir . '/credentials');
        $this->awsConfigPath = getenv('AWS_CONFIG_FILE') ?: ($this->awsDir . '/config');

        $this->store = new Store($this->configDir);
        $this->awsBin = $this->resolveAwsBin();
    }

    public static function homeDir(): string
    {
        $home = getenv('HOME');
        if (is_string($home) && $home !== '') {
            return rtrim($home, '/');
        }
        return rtrim((string) ($_SERVER['HOME'] ?? sys_get_temp_dir()), '/');
    }

    private function resolveAwsBin(): string
    {
        $configured = getenv('AWS_CLI_BIN');
        if (is_string($configured) && $configured !== '') {
            return $configured;
        }
        foreach (['/opt/homebrew/bin/aws', '/usr/local/bin/aws', '/usr/bin/aws'] as $candidate) {
            if (is_file($candidate) && is_executable($candidate)) {
                return $candidate;
            }
        }
        return 'aws'; // fall back to PATH lookup
    }

    /**
     * Reject requests that did not originate from a browser tab pointed at this exact
     * localhost origin. This is the primary defense against DNS-rebinding: a rebinding
     * attacker keeps their own hostname in the Host header, so it will never match.
     */
    public function assertTrustedHost(): void
    {
        $host = (string) ($_SERVER['HTTP_HOST'] ?? '');
        $allowed = [
            self::HOST . ':' . self::PORT,
            'localhost:' . self::PORT,
        ];
        if (!in_array($host, $allowed, true)) {
            $this->fail(403, 'Untrusted Host header: ' . ($host === '' ? '(none)' : $host));
        }
    }

    /** Mutating requests must echo back the app token issued to the rendered page. */
    public function assertCsrf(): void
    {
        $sent = (string) ($_SERVER['HTTP_X_CSRF_TOKEN'] ?? '');
        if (!hash_equals($this->store->appToken(), $sent)) {
            $this->fail(403, 'Missing or invalid CSRF token. Reload the dashboard.');
        }
    }

    /** @param array<string,mixed> $payload */
    public function json(array $payload, int $status = 200): never
    {
        http_response_code($status);
        header('Content-Type: application/json; charset=utf-8');
        header('Cache-Control: no-store');
        header('X-Content-Type-Options: nosniff');
        echo json_encode($payload, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
        exit;
    }

    public function fail(int $status, string $message): never
    {
        $this->json(['ok' => false, 'error' => $message], $status);
    }

    /**
     * Run $fn while holding an exclusive advisory lock, so two concurrent refreshes (two
     * browser tabs, or the UI plus the CLI) can't perform overlapping read-modify-write
     * cycles on ~/.aws/credentials and lose each other's updates. Best-effort: if the lock
     * can't be taken we still proceed (the atomic rename in save() prevents torn files).
     *
     * @template T
     * @param callable():T $fn
     * @return T
     */
    public function withCredentialsLock(callable $fn)
    {
        if (!is_dir($this->awsDir)) {
            @mkdir($this->awsDir, 0700, true);
        }
        $lockPath = $this->awsDir . '/.aws-cli-dashboard.lock';
        $fh = @fopen($lockPath, 'c');
        if ($fh === false) {
            return $fn();
        }
        @chmod($lockPath, 0600);
        $locked = flock($fh, LOCK_EX);
        try {
            return $fn();
        } finally {
            if ($locked) {
                flock($fh, LOCK_UN);
            }
            fclose($fh);
        }
    }

    /** @return array<string,mixed> */
    public function jsonBody(): array
    {
        $raw = file_get_contents('php://input') ?: '';
        if (trim($raw) === '') {
            return [];
        }
        $data = json_decode($raw, true);
        if (!is_array($data)) {
            $this->fail(400, 'Request body must be a JSON object.');
        }
        return $data;
    }
}
