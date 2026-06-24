<?php

declare(strict_types=1);

namespace AwsDash;

/**
 * Interprets an @Claude intake message using the operator's LOCAL `claude` CLI (Claude Code) instead
 * of the Anthropic API — so it uses the subscription the operator is already logged into, with NO
 * API key to store. Runs `claude -p '<prompt>' --output-format json` and reads the structured task
 * out of the result envelope.
 *
 * Security: the CLI is invoked via proc_open with an ARGV ARRAY — the (untrusted) message text is a
 * single argument, NEVER interpolated into a shell command line, so there is no command-injection
 * surface (the binary path is a fixed/resolved path, not client input either). It runs in a neutral
 * working directory so it does NOT load this project's CLAUDE.md / MCP config, and with a hard
 * timeout; the caller treats any failure as non-fatal and falls back to the built-in parser, so a
 * slow or missing CLI never wedges the listener.
 */
final class ClaudeCli
{
    private const STATUSES = ['pending', 'in_progress', 'review', 'done'];

    /** Hard cap on captured stdout — a valid task envelope is tiny; anything larger is pathological
     *  (a runaway CLI). Bounds memory so a broken local `claude` can't OOM the listener. */
    private const MAX_OUTPUT = 2 * 1024 * 1024;

    private string $bin;
    private int $timeout;

    public function __construct(string $bin = '', int $timeout = 90)
    {
        $this->bin = $bin !== '' ? $bin : self::resolveBin();
        $this->timeout = max(10, $timeout);
    }

    /** Resolve the `claude` binary: env override, then common install locations, then PATH lookup. */
    public static function resolveBin(): string
    {
        $env = getenv('CLAUDE_CLI_BIN');
        if (is_string($env) && $env !== '' && is_executable($env)) {
            return $env;
        }
        $home = rtrim((string) (getenv('HOME') ?: ''), '/');
        $candidates = [
            $home . '/.local/bin/claude',
            $home . '/.claude/local/claude',
            '/opt/homebrew/bin/claude',
            '/usr/local/bin/claude',
            $home . '/.npm-global/bin/claude',
        ];
        foreach ($candidates as $c) {
            if ($home !== '' && is_file($c) && is_executable($c)) {
                return $c;
            }
        }
        return 'claude'; // last resort: rely on PATH
    }

    /** Best-effort: is a resolved, executable binary present? (A bare 'claude' on PATH reads as available.) */
    public function isAvailable(): bool
    {
        return $this->bin === 'claude' || is_executable($this->bin);
    }

    public function bin(): string
    {
        return $this->bin;
    }

    /**
     * Turn a chat instruction (and an optional referenced/colleague message) into one task.
     *
     * @param ?callable $onTick called periodically while waiting (so the caller can keep a heartbeat
     *                          fresh during a slow CLI call)
     * @return array{title:string,description:string,project:string,status:string}
     */
    public function extractTask(string $request, string $context = '', ?callable $onTick = null): array
    {
        $request = trim($request);
        $context = trim($context);

        $prompt = "You convert a chat message into ONE task for a personal Kanban board.\n"
            . "Output ONLY a single JSON object and nothing else — no markdown fences, no prose:\n"
            . '{"title": "...", "project": "...", "description": "...", "status": "pending|in_progress|review|done"}' . "\n"
            . "Rules: title = a concise imperative (no trailing punctuation, ideally under 100 chars); "
            . "project = a short name if the message names or clearly implies one, else \"\"; "
            . "description = 1-3 sentences of useful detail (fold in the referenced message when present); "
            . "status defaults to \"pending\" unless the message clearly says the work is already in progress, "
            . "in review, or done. Use only information present in the message — do not invent facts.\n\n";
        if ($context !== '') {
            $prompt .= "Referenced message I'm acting on:\n\"\"\"\n" . $context . "\n\"\"\"\n\n";
        }
        $prompt .= 'Message:' . "\n" . $request;

        $envelope = $this->run(['-p', $prompt, '--output-format', 'json'], $onTick);

        if (!is_array($envelope) || ($envelope['is_error'] ?? false) === true) {
            throw new \RuntimeException('claude CLI returned an error.');
        }
        $resultText = (string) ($envelope['result'] ?? '');
        $data = self::decodeTaskJson($resultText);
        if ($data === null) {
            throw new \RuntimeException('claude CLI did not return a task.');
        }

        $statusVal = (string) ($data['status'] ?? 'pending');
        return [
            'title' => trim((string) ($data['title'] ?? '')),
            'description' => trim((string) ($data['description'] ?? '')),
            'project' => trim((string) ($data['project'] ?? '')),
            'status' => in_array($statusVal, self::STATUSES, true) ? $statusVal : 'pending',
        ];
    }

    /**
     * Run the CLI with the given args and return the decoded JSON envelope. proc_open with an argv
     * array (no shell), stdin closed, a hard timeout, in a neutral cwd. Throws on spawn failure /
     * timeout / non-zero exit.
     *
     * @param list<string> $args
     * @return array<string,mixed>
     */
    private function run(array $args, ?callable $onTick): array
    {
        $cmd = array_merge([$this->bin], $args);
        $descriptors = [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']];
        // Neutral cwd so the CLI doesn't pick up THIS project's CLAUDE.md / .mcp config.
        $cwd = sys_get_temp_dir();
        $proc = @proc_open($cmd, $descriptors, $pipes, $cwd);
        if (!is_resource($proc)) {
            throw new \RuntimeException('Could not start the claude CLI (' . $this->bin . ').');
        }
        fclose($pipes[0]); // no stdin — the prompt is an argument
        stream_set_blocking($pipes[1], false);
        stream_set_blocking($pipes[2], false);

        $out = '';
        $deadline = microtime(true) + $this->timeout;
        $lastTick = microtime(true);
        $kill = function (string $why) use ($proc, $pipes): void {
            @proc_terminate($proc, 9);
            @fclose($pipes[1]);
            @fclose($pipes[2]);
            @proc_close($proc);
            throw new \RuntimeException($why);
        };
        while (true) {
            $status = proc_get_status($proc);
            $out .= (string) stream_get_contents($pipes[1]);
            // drain stderr so the pipe buffer can't fill and block the child
            stream_get_contents($pipes[2]);
            if (strlen($out) > self::MAX_OUTPUT) {
                $kill('claude CLI produced too much output.');
            }
            if (!$status['running']) {
                break;
            }
            if (microtime(true) > $deadline) {
                $kill('claude CLI timed out after ' . $this->timeout . 's.');
            }
            if ($onTick !== null && microtime(true) - $lastTick >= 5.0) {
                $onTick();
                $lastTick = microtime(true);
            }
            usleep(100000); // 100ms
        }
        $out .= (string) stream_get_contents($pipes[1]);
        fclose($pipes[1]);
        fclose($pipes[2]);
        $exit = proc_close($proc);
        if ($exit !== 0) {
            throw new \RuntimeException('claude CLI exited with code ' . $exit . '.');
        }

        $decoded = json_decode(trim($out), true);
        return is_array($decoded) ? $decoded : [];
    }

    /**
     * Pull the task object out of the model's result text — tolerant of stray markdown fences or
     * surrounding prose (extract the first balanced-looking {...} and decode it).
     *
     * @return array<string,mixed>|null
     */
    private static function decodeTaskJson(string $text): ?array
    {
        $text = trim($text);
        // Strip ```json … ``` fences if present.
        $text = (string) preg_replace('/^```[a-zA-Z]*\s*|\s*```$/m', '', $text);
        $data = json_decode(trim($text), true);
        if (is_array($data)) {
            return $data;
        }
        // Fallback: grab the first { … last } span and try that.
        $start = strpos($text, '{');
        $end = strrpos($text, '}');
        if ($start !== false && $end !== false && $end > $start) {
            $data = json_decode(substr($text, $start, $end - $start + 1), true);
            if (is_array($data)) {
                return $data;
            }
        }
        return null;
    }
}
