<?php

declare(strict_types=1);

namespace AwsDash;

/**
 * A line-preserving editor for the AWS shared credentials file (~/.aws/credentials).
 *
 * parse_ini_file() is lossy (drops comments, mangles some values, and cannot round-trip),
 * and we must never corrupt the user's other profiles. So we keep the file as an ordered
 * list of sections, each section being the raw [header] line plus the raw lines beneath it.
 * Updating a profile only rewrites the keys we own and leaves every other profile, comment,
 * blank line and header byte-for-byte intact.
 */
final class CredentialsFile
{
    private string $path;
    /** @var string[] raw lines that appear before the first [section] */
    private array $preamble = [];
    /** @var array<int,array{name:string,header:?string,lines:string[]}> ordered sections */
    private array $sections = [];

    public function __construct(string $path)
    {
        $this->path = $path;
        $this->load();
    }

    private function load(): void
    {
        $this->preamble = [];
        $this->sections = [];

        if (!is_file($this->path)) {
            return;
        }

        $raw = file_get_contents($this->path);
        if ($raw === false) {
            throw new \RuntimeException("Unable to read {$this->path}");
        }

        $lines = preg_split("/\r\n|\n|\r/", $raw);
        if ($lines === false) {
            $lines = [];
        }
        // preg_split on a trailing newline yields a final empty element; drop it so we
        // don't accumulate blank lines every time we round-trip the file.
        if ($lines !== [] && end($lines) === '') {
            array_pop($lines);
        }

        $current = null; // index into $this->sections
        foreach ($lines as $line) {
            if (preg_match('/^\s*\[(.+?)\]\s*$/', $line, $m)) {
                // Keep the original header line verbatim so untouched profiles round-trip
                // byte-for-byte (any unusual spacing inside the brackets is preserved).
                $this->sections[] = ['name' => trim($m[1]), 'header' => $line, 'lines' => []];
                $current = array_key_last($this->sections);
                continue;
            }
            if ($current === null) {
                $this->preamble[] = $line;
            } else {
                $this->sections[$current]['lines'][] = $line;
            }
        }
    }

    public function hasProfile(string $name): bool
    {
        foreach ($this->sections as $s) {
            if ($s['name'] === $name) {
                return true;
            }
        }
        return false;
    }

    /** @return string[] section names, in file order */
    public function profileNames(): array
    {
        return array_map(static fn (array $s): string => $s['name'], $this->sections);
    }

    /**
     * Set key/value pairs inside a profile, creating the profile if needed. Keys present in
     * $values replace any existing line for that key; existing keys not listed are kept.
     *
     * @param array<string,string> $values
     */
    public function setProfile(string $name, array $values): void
    {
        // If the same profile name appears more than once (legal in the shared file), the
        // aws CLI / botocore resolve it last-wins. We must therefore update the LAST
        // occurrence, otherwise our fresh credentials would be shadowed by a later, stale
        // block and the CLI would keep using the old token.
        $index = null;
        foreach ($this->sections as $i => $s) {
            if ($s['name'] === $name) {
                $index = $i;
            }
        }

        if ($index === null) {
            $this->sections[] = ['name' => $name, 'header' => null, 'lines' => []];
            $index = array_key_last($this->sections);
        }

        $lines = $this->sections[$index]['lines'];
        foreach ($values as $key => $value) {
            // A newline in a value would inject extra INI lines / a fake [section]. AWS
            // credential values never contain newlines, so refuse them defensively.
            if (preg_match('/[\r\n]/', $value)) {
                throw new \RuntimeException("Refusing to write a newline in value for '{$key}'.");
            }
            $rendered = $key . ' = ' . $value;
            $found = false;
            foreach ($lines as $li => $line) {
                if (preg_match('/^\s*' . preg_quote($key, '/') . '\s*=/', $line)) {
                    $lines[$li] = $rendered;
                    $found = true;
                    break;
                }
            }
            if (!$found) {
                $lines[] = $rendered;
            }
        }

        $this->sections[$index]['lines'] = $lines;
    }

    /**
     * Return a profile's key/value pairs (last-wins on duplicate sections, matching the aws
     * CLI), or null if the profile does not exist. Comments and blank lines are ignored.
     *
     * @return array<string,string>|null
     */
    public function getProfile(string $name): ?array
    {
        $index = null;
        foreach ($this->sections as $i => $s) {
            if ($s['name'] === $name) {
                $index = $i;
            }
        }
        if ($index === null) {
            return null;
        }

        $values = [];
        foreach ($this->sections[$index]['lines'] as $line) {
            if (preg_match('/^\s*([^#;=\s][^=]*?)\s*=\s*(.*)$/', $line, $m)) {
                $values[trim($m[1])] = trim($m[2]);
            }
        }
        return $values;
    }

    /** Remove a single key from a profile (all occurrences), if present. */
    public function removeKey(string $name, string $key): void
    {
        foreach ($this->sections as $i => $s) {
            if ($s['name'] !== $name) {
                continue;
            }
            $this->sections[$i]['lines'] = array_values(array_filter(
                $s['lines'],
                static fn (string $line): bool =>
                    !preg_match('/^\s*' . preg_quote($key, '/') . '\s*=/', $line)
            ));
        }
    }

    public function render(): string
    {
        $out = [];
        foreach ($this->preamble as $line) {
            $out[] = $line;
        }
        foreach ($this->sections as $s) {
            // Re-emit the original header for existing sections; synthesize one only for
            // profiles we created.
            $out[] = $s['header'] ?? ('[' . $s['name'] . ']');
            foreach ($s['lines'] as $line) {
                $out[] = $line;
            }
        }
        return implode("\n", $out) . "\n";
    }

    /**
     * Persist changes atomically: back up the current file, write to a temp file in the
     * same directory, then rename over the original (preserving 0600). Callers should hold
     * an advisory lock (see App::withCredentialsLock) around the load()+save() cycle so two
     * concurrent refreshes cannot lose each other's updates.
     */
    public function save(): void
    {
        // Force every file we create here to be 0600 at *creation* time. Otherwise the
        // default umask (commonly 0644) leaves the secret-bearing backup/temp briefly
        // world/group-readable before the later chmod, which another local user on a shared
        // host could race to read. umask is process-global, so restore it in finally.
        $oldUmask = umask(0077);
        try {
            $dir = \dirname($this->path);
            if (!is_dir($dir) && !mkdir($dir, 0700, true) && !is_dir($dir)) {
                throw new \RuntimeException("Unable to create {$dir}");
            }

            if (is_file($this->path)) {
                $backup = $this->path . '.bak';
                @unlink($backup); // never copy through a pre-existing/symlinked backup path
                if (!@copy($this->path, $backup)) {
                    throw new \RuntimeException("Unable to write backup {$backup}");
                }
                @chmod($backup, 0600);
            }

            $tmp = $this->path . '.tmp.' . getmypid();
            @unlink($tmp); // clear any stale/planted temp path first
            // 'x' = O_CREAT|O_EXCL: refuses a pre-existing or symlinked path, and under the
            // umask above the file is 0600 from the very first byte written.
            $fh = @fopen($tmp, 'xb');
            if ($fh === false) {
                throw new \RuntimeException("Unable to create temp credentials file {$tmp}");
            }
            if (@fwrite($fh, $this->render()) === false) {
                fclose($fh);
                @unlink($tmp);
                throw new \RuntimeException("Unable to write temp credentials file {$tmp}");
            }
            fclose($fh);
            @chmod($tmp, 0600);

            if (!@rename($tmp, $this->path)) {
                @unlink($tmp);
                throw new \RuntimeException("Unable to replace {$this->path}");
            }
            @chmod($this->path, 0600);
        } finally {
            umask($oldUmask);
        }
    }
}
