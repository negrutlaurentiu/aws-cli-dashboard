<?php

declare(strict_types=1);

namespace AwsDash;

/**
 * Persists the account list (config/accounts.json) and runtime state (config/state.json).
 *
 * accounts.json holds user config and MAY contain TOTP seeds, so it is gitignored and
 * written 0600. state.json holds the CSRF app-token and the last-known session expiry per
 * account (so the dashboard can show a live countdown without re-reading the secret-bearing
 * credentials file).
 */
final class Store
{
    private string $accountsPath;
    private string $statePath;

    public function __construct(string $configDir)
    {
        $this->accountsPath = $configDir . '/accounts.json';
        $this->statePath = $configDir . '/state.json';
        if (!is_dir($configDir)) {
            mkdir($configDir, 0700, true);
        }
    }

    // ---- accounts ---------------------------------------------------------

    /** @return array<int,array<string,mixed>> */
    public function accounts(): array
    {
        $data = $this->readJson($this->accountsPath);
        $accounts = $data['accounts'] ?? [];
        return is_array($accounts) ? array_values($accounts) : [];
    }

    public function findAccount(string $id): ?array
    {
        foreach ($this->accounts() as $a) {
            if (($a['id'] ?? null) === $id) {
                return $a;
            }
        }
        return null;
    }

    /**
     * Insert or update an account. Returns the stored record.
     *
     * @param array<string,mixed> $input
     * @return array<string,mixed>
     */
    public function saveAccount(array $input): array
    {
        $label = trim((string) ($input['label'] ?? ''));
        $source = trim((string) ($input['source_profile'] ?? ''));
        $target = trim((string) ($input['target_profile'] ?? ''));
        $serial = trim((string) ($input['mfa_serial'] ?? ''));
        $region = trim((string) ($input['region'] ?? ''));
        $secret = trim((string) ($input['totp_secret'] ?? ''));
        $clearSecret = !empty($input['clear_secret']);
        $duration = (int) ($input['duration_seconds'] ?? 129600);

        if ($label === '') {
            throw new \InvalidArgumentException('Label is required.');
        }
        if ($source === '') {
            throw new \InvalidArgumentException('Source profile is required.');
        }
        if ($target === '') {
            $target = $source;
        }
        if ($serial === '') {
            throw new \InvalidArgumentException('MFA serial (ARN) is required.');
        }
        // AWS allows 900s..129600s for an IAM-user session token.
        if ($duration < 900 || $duration > 129600) {
            throw new \InvalidArgumentException('Duration must be between 900 and 129600 seconds.');
        }
        if ($secret !== '' && !Totp::looksLikeSecret($secret)) {
            throw new \InvalidArgumentException('That does not look like a valid base32 MFA secret.');
        }

        $id = trim((string) ($input['id'] ?? ''));
        $accounts = $this->accounts();

        if ($id === '') {
            $id = $this->slug($label, $accounts);
        }

        $record = [
            'id' => $id,
            'label' => $label,
            'source_profile' => $source,
            'target_profile' => $target,
            'mfa_serial' => $serial,
            'duration_seconds' => $duration,
            'region' => $region,
            'totp_secret' => $secret,
        ];

        $replaced = false;
        foreach ($accounts as $i => $a) {
            if (($a['id'] ?? null) === $id) {
                // "leave blank to keep" — preserve an existing secret unless the field was
                // blank AND the operator explicitly asked to forget it.
                if ($secret === '' && !$clearSecret && !empty($a['totp_secret'])) {
                    $record['totp_secret'] = (string) $a['totp_secret'];
                }
                $accounts[$i] = $record;
                $replaced = true;
                break;
            }
        }
        if (!$replaced) {
            $accounts[] = $record;
        }

        $this->writeJson($this->accountsPath, ['accounts' => array_values($accounts)]);
        return $record;
    }

    public function deleteAccount(string $id): void
    {
        $accounts = array_values(array_filter(
            $this->accounts(),
            static fn (array $a): bool => ($a['id'] ?? null) !== $id
        ));
        $this->writeJson($this->accountsPath, ['accounts' => $accounts]);

        $state = $this->state();
        unset($state['sessions'][$id]);
        $this->writeState($state);
    }

    // ---- state ------------------------------------------------------------

    /** @return array<string,mixed> */
    public function state(): array
    {
        $state = $this->readJson($this->statePath);
        if (!isset($state['sessions']) || !is_array($state['sessions'])) {
            $state['sessions'] = [];
        }
        return $state;
    }

    public function appToken(): string
    {
        $state = $this->state();
        if (empty($state['app_token']) || !is_string($state['app_token'])) {
            $state['app_token'] = bin2hex(random_bytes(32));
            $this->writeState($state);
        }
        return $state['app_token'];
    }

    public function recordSession(string $accountId, string $targetProfile, string $expirationIso): void
    {
        $state = $this->state();
        $state['sessions'][$accountId] = [
            'target_profile' => $targetProfile,
            'expiration' => $expirationIso,
            'refreshed_at' => gmdate('c'),
        ];
        $this->writeState($state);
    }

    public function sessionFor(string $accountId): ?array
    {
        $state = $this->state();
        $s = $state['sessions'][$accountId] ?? null;
        return is_array($s) ? $s : null;
    }

    private function writeState(array $state): void
    {
        $this->writeJson($this->statePath, $state);
    }

    // ---- helpers ----------------------------------------------------------

    private function slug(string $label, array $existing): string
    {
        $base = strtolower((string) preg_replace('/[^a-zA-Z0-9]+/', '-', $label));
        $base = trim($base, '-');
        if ($base === '') {
            $base = 'account';
        }
        $ids = array_map(static fn (array $a): string => (string) ($a['id'] ?? ''), $existing);
        $id = $base;
        $n = 2;
        while (in_array($id, $ids, true)) {
            $id = $base . '-' . $n;
            $n++;
        }
        return $id;
    }

    /** @return array<string,mixed> */
    private function readJson(string $path): array
    {
        if (!is_file($path)) {
            return [];
        }
        $raw = file_get_contents($path);
        if ($raw === false || trim($raw) === '') {
            return [];
        }
        $data = json_decode($raw, true);
        return is_array($data) ? $data : [];
    }

    private function writeJson(string $path, array $data): void
    {
        $json = json_encode($data, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
        if ($json === false) {
            throw new \RuntimeException('Failed to encode JSON for ' . $path);
        }
        $tmp = $path . '.tmp.' . getmypid();
        if (file_put_contents($tmp, $json . "\n", LOCK_EX) === false) {
            @unlink($tmp);
            throw new \RuntimeException('Failed to write ' . $path);
        }
        @chmod($tmp, 0600);
        if (!@rename($tmp, $path)) {
            @unlink($tmp);
            throw new \RuntimeException('Failed to replace ' . $path);
        }
        @chmod($path, 0600);
    }
}
