<?php

declare(strict_types=1);

namespace AwsDash;

/**
 * Thin wrapper over the `aws s3` / `aws s3api` CLI for the S3 browser. Every dynamic value
 * (profile, bucket, key, prefix, destination) is passed through escapeshellarg().
 */
final class S3
{
    public function __construct(private string $awsBin = 'aws')
    {
    }

    /** @return array<int,array{name:string,created:?string}> */
    public function listBuckets(string $profile): array
    {
        $out = $this->json(['s3api', 'list-buckets', '--profile', $profile, '--output', 'json'], $profile);
        $buckets = [];
        foreach (($out['Buckets'] ?? []) as $b) {
            if (isset($b['Name'])) {
                $buckets[] = ['name' => (string) $b['Name'], 'created' => $b['CreationDate'] ?? null];
            }
        }
        usort($buckets, static fn ($a, $b) => strcasecmp($a['name'], $b['name']));
        return $buckets;
    }

    /**
     * List one "folder" level under $prefix using the delimiter, so navigation is folder-like.
     * Paginates via the aws CLI's --max-items / --starting-token (folders + files count
     * together toward the page size); $startingToken is the opaque NextToken from a prior page.
     *
     * @return array{prefixes:array<int,string>,objects:array<int,array{key:string,name:string,size:int,modified:?string}>,next_token:string}
     */
    public function listObjects(string $profile, string $bucket, string $prefix, string $startingToken = '', int $maxItems = 200): array
    {
        $maxItems = max(10, min(1000, $maxItems));
        $args = [
            's3api', 'list-objects-v2',
            '--bucket', $bucket,
            '--delimiter', '/',
            '--max-items', (string) $maxItems,
            '--profile', $profile,
            '--output', 'json',
        ];
        if ($prefix !== '') {
            $args[] = '--prefix';
            $args[] = $prefix;
        }
        if ($startingToken !== '') {
            $args[] = '--starting-token';
            $args[] = $startingToken;
        }
        $out = $this->json($args, $profile);

        $prefixes = [];
        foreach (($out['CommonPrefixes'] ?? []) as $p) {
            if (isset($p['Prefix'])) {
                $prefixes[] = (string) $p['Prefix'];
            }
        }

        $objects = [];
        foreach (($out['Contents'] ?? []) as $o) {
            $key = (string) ($o['Key'] ?? '');
            if ($key === '' || $key === $prefix) {
                continue; // skip the folder placeholder object
            }
            $objects[] = [
                'key' => $key,
                'name' => $this->basename($key),
                'size' => (int) ($o['Size'] ?? 0),
                'modified' => $o['LastModified'] ?? null,
            ];
        }

        sort($prefixes, SORT_NATURAL | SORT_FLAG_CASE);
        usort($objects, static fn ($a, $b) => strcasecmp($a['name'], $b['name']));

        return [
            'prefixes' => $prefixes,
            'objects' => $objects,
            'next_token' => (string) ($out['NextToken'] ?? ''),
        ];
    }

    /** @return array{ContentLength:int,ContentType:string} */
    public function headObject(string $profile, string $bucket, string $key): array
    {
        $out = $this->json([
            's3api', 'head-object',
            '--bucket', $bucket, '--key', $key,
            '--profile', $profile, '--output', 'json',
        ], $profile);
        return [
            'ContentLength' => (int) ($out['ContentLength'] ?? 0),
            'ContentType' => (string) ($out['ContentType'] ?? ''),
        ];
    }

    /**
     * Stream an object's bytes to php://output. Caller MUST have already sent headers.
     * Returns false on spawn failure.
     */
    public function streamObject(string $profile, string $bucket, string $key): bool
    {
        $cmd = $this->cmd(['s3', 'cp', "s3://{$bucket}/{$key}", '-', '--profile', $profile]);
        $proc = proc_open($cmd, [1 => ['pipe', 'w'], 2 => ['file', '/dev/null', 'a']], $pipes);
        if (!is_resource($proc)) {
            return false;
        }
        stream_set_chunk_size($pipes[1], 1 << 16);
        while (!feof($pipes[1])) {
            echo fread($pipes[1], 1 << 16);
            flush();
        }
        fclose($pipes[1]);
        proc_close($proc);
        return true;
    }

    /**
     * Kick off a detached recursive download to a local directory. Returns the log path and
     * resolved destination; progress is read from the log by downloadStatus().
     *
     * @return array{job:string,dest:string,log:string}
     */
    public function startDownload(string $profile, string $bucket, string $prefix, string $downloadsRoot, string $jobId): array
    {
        $safeBucket = $this->safeSegment($bucket);
        $safePrefix = $this->safeRelPath($prefix);
        $dest = rtrim($downloadsRoot, '/') . '/' . $safeBucket . ($safePrefix !== '' ? '/' . $safePrefix : '');
        if (!is_dir($dest) && !mkdir($dest, 0700, true) && !is_dir($dest)) {
            throw new \RuntimeException("Unable to create destination {$dest}");
        }

        $src = 's3://' . $bucket . '/' . $prefix;
        $log = sys_get_temp_dir() . '/awsdash-s3dl-' . $jobId . '.log';

        $awsCmd = $this->cmd(['s3', 'cp', $src, $dest, '--recursive', '--no-progress', '--profile', $profile]);
        $logArg = escapeshellarg($log);
        $script = $awsCmd . ' >> ' . $logArg . ' 2>&1; echo "__EXIT__:$?" >> ' . $logArg;
        $spawn = 'nohup sh -c ' . escapeshellarg($script) . ' >/dev/null 2>&1 &';
        // fire-and-forget; downloadStatus() polls the log
        exec($spawn);

        return ['job' => $jobId, 'dest' => $dest, 'log' => $log];
    }

    /** @return array{done:bool,exit:?int,files:int,tail:string} */
    public function downloadStatus(string $jobId): array
    {
        $log = sys_get_temp_dir() . '/awsdash-s3dl-' . $jobId . '.log';
        if (!is_file($log)) {
            return ['done' => false, 'exit' => null, 'files' => 0, 'tail' => ''];
        }
        $content = (string) file_get_contents($log);
        $exit = null;
        $done = false;
        if (preg_match('/__EXIT__:(\d+)/', $content, $m)) {
            $done = true;
            $exit = (int) $m[1];
        }
        $files = substr_count($content, 'download: ');
        $lines = array_filter(explode("\n", $content), static fn ($l) => trim($l) !== '');
        $tail = implode("\n", array_slice($lines, -6));
        return ['done' => $done, 'exit' => $exit, 'files' => $files, 'tail' => $tail];
    }

    // ---- helpers ----------------------------------------------------------

    /** @param string[] $args */
    private function cmd(array $args): string
    {
        array_unshift($args, $this->awsBin);
        return implode(' ', array_map('escapeshellarg', $args));
    }

    /**
     * Run an aws CLI subcommand and JSON-decode stdout.
     * @param string[] $args
     * @return array<string,mixed>
     */
    private function json(array $args, string $profile): array
    {
        $descriptors = [1 => ['pipe', 'w'], 2 => ['pipe', 'w']];
        $proc = proc_open($this->cmd($args), $descriptors, $pipes);
        if (!is_resource($proc)) {
            throw new \RuntimeException('Failed to launch aws CLI. Is it installed and on PATH?');
        }
        $stdout = stream_get_contents($pipes[1]) ?: '';
        $stderr = stream_get_contents($pipes[2]) ?: '';
        fclose($pipes[1]);
        fclose($pipes[2]);
        $code = proc_close($proc);

        if ($code !== 0) {
            $msg = trim($stderr) !== '' ? trim($stderr) : "aws CLI exited with status {$code}";
            throw new \RuntimeException($msg);
        }
        if (trim($stdout) === '') {
            return [];
        }
        $data = json_decode($stdout, true);
        if (!is_array($data)) {
            throw new \RuntimeException('Unexpected aws CLI output.');
        }
        return $data;
    }

    private function basename(string $key): string
    {
        $key = rtrim($key, '/');
        $pos = strrpos($key, '/');
        return $pos === false ? $key : substr($key, $pos + 1);
    }

    /** A single path segment with no slashes or traversal. */
    private function safeSegment(string $s): string
    {
        $s = str_replace(['/', '\\', "\0"], '', $s);
        $s = trim($s);
        return $s === '' || $s === '.' || $s === '..' ? 'bucket' : $s;
    }

    /** A relative path with traversal/empty segments stripped. */
    private function safeRelPath(string $s): string
    {
        $parts = [];
        foreach (explode('/', $s) as $seg) {
            $seg = str_replace(["\\", "\0"], '', $seg);
            if ($seg === '' || $seg === '.' || $seg === '..') {
                continue;
            }
            $parts[] = $seg;
        }
        return implode('/', $parts);
    }
}
