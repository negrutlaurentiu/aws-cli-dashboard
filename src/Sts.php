<?php

declare(strict_types=1);

namespace AwsDash;

/**
 * Thin wrapper around `aws sts get-session-token`. Every dynamic value is passed through
 * escapeshellarg(), so a maliciously-crafted profile name / serial / code cannot break out
 * of the argument list.
 */
final class Sts
{
    public function __construct(private string $awsBin = 'aws')
    {
    }

    /**
     * @return array{AccessKeyId:string,SecretAccessKey:string,SessionToken:string,Expiration:string}
     */
    public function getSessionToken(
        string $serialNumber,
        string $sourceProfile,
        string $tokenCode,
        int $durationSeconds
    ): array {
        $args = [
            $this->awsBin,
            'sts', 'get-session-token',
            '--serial-number', $serialNumber,
            '--profile', $sourceProfile,
            '--token-code', $tokenCode,
            '--duration-seconds', (string) $durationSeconds,
            '--output', 'json',
        ];

        $cmd = implode(' ', array_map('escapeshellarg', $args));
        $result = $this->run($cmd);

        if ($result['code'] !== 0) {
            $msg = trim($result['stderr']) !== '' ? trim($result['stderr']) : trim($result['stdout']);
            if ($msg === '') {
                $msg = "aws CLI exited with status {$result['code']}";
            }
            throw new \RuntimeException($msg);
        }

        $data = json_decode($result['stdout'], true);
        if (!is_array($data) || !isset($data['Credentials']) || !is_array($data['Credentials'])) {
            throw new \RuntimeException('Unexpected aws CLI output: ' . substr($result['stdout'], 0, 500));
        }

        $c = $data['Credentials'];
        foreach (['AccessKeyId', 'SecretAccessKey', 'SessionToken', 'Expiration'] as $k) {
            if (!isset($c[$k]) || !is_string($c[$k]) || $c[$k] === '') {
                throw new \RuntimeException("aws CLI response missing {$k}");
            }
        }

        return [
            'AccessKeyId' => $c['AccessKeyId'],
            'SecretAccessKey' => $c['SecretAccessKey'],
            'SessionToken' => $c['SessionToken'],
            'Expiration' => $c['Expiration'],
        ];
    }

    /** @return array{code:int,stdout:string,stderr:string} */
    private function run(string $cmd): array
    {
        $descriptors = [
            0 => ['pipe', 'r'],
            1 => ['pipe', 'w'],
            2 => ['pipe', 'w'],
        ];
        $proc = proc_open($cmd, $descriptors, $pipes);
        if (!is_resource($proc)) {
            throw new \RuntimeException('Failed to launch aws CLI. Is it installed and on PATH?');
        }

        fclose($pipes[0]);
        $stdout = stream_get_contents($pipes[1]) ?: '';
        $stderr = stream_get_contents($pipes[2]) ?: '';
        fclose($pipes[1]);
        fclose($pipes[2]);
        $code = proc_close($proc);

        return ['code' => $code, 'stdout' => $stdout, 'stderr' => $stderr];
    }

    /**
     * Discover existing profile names from the shared credentials and config files, so the
     * UI can offer them as suggestions. Never returns secret values — names only.
     *
     * @return string[]
     */
    public static function listProfiles(string $credentialsPath, string $configPath): array
    {
        $names = [];

        if (is_file($credentialsPath)) {
            foreach (preg_split("/\r\n|\n|\r/", (string) file_get_contents($credentialsPath)) ?: [] as $line) {
                if (preg_match('/^\s*\[(.+?)\]\s*$/', $line, $m)) {
                    $names[] = trim($m[1]);
                }
            }
        }

        if (is_file($configPath)) {
            foreach (preg_split("/\r\n|\n|\r/", (string) file_get_contents($configPath)) ?: [] as $line) {
                if (preg_match('/^\s*\[(?:profile\s+)?(.+?)\]\s*$/', $line, $m)) {
                    $names[] = trim($m[1]);
                }
            }
        }

        $names = array_values(array_unique($names));
        sort($names, SORT_NATURAL | SORT_FLAG_CASE);
        return $names;
    }
}
