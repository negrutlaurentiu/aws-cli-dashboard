<?php

declare(strict_types=1);

namespace AwsDash;

/**
 * Runs the operator's LOCAL `claude` CLI (Claude Code) as an AGENT for @Claude intake — using the
 * subscription they're logged into, with NO API key. The listener hands Claude the Mattermost
 * message + thread context and a scoped tool set (the dashboard MCP server, bin/dashboard-mcp), and
 * Claude decides what to do: create / update / delete tasks, attach a file, reply in the thread, or
 * post a check-in/out digest.
 *
 * Security: the CLI is invoked via proc_open with an ARGV ARRAY (the untrusted message is a single
 * argument — never shell-interpolated); it runs in a neutral cwd (so it ignores this project's
 * CLAUDE.md/MCP) with `--strict-mcp-config` (ignores the user's global MCP servers), and is locked
 * down to ONLY the dashboard tools via `--allowedTools` + a `--disallowedTools` denylist of the
 * built-ins (no Bash/Edit/Write/web/etc.). `bypassPermissions` makes it non-interactive (no prompt,
 * no hang). A hard timeout + output cap bound it; the caller treats any failure as non-fatal.
 */
final class ClaudeCli
{
    /** Hard cap on captured stdout, so a runaway CLI can't exhaust memory. */
    private const MAX_OUTPUT = 8 * 1024 * 1024;

    private string $bin;
    private int $timeout;

    public function __construct(string $bin = '', int $timeout = 120)
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

    /** Best-effort: is a resolved, executable binary present? */
    public function isAvailable(): bool
    {
        return $this->bin === 'claude' || is_executable($this->bin);
    }

    public function bin(): string
    {
        return $this->bin;
    }

    /**
     * Run Claude agentically against the dashboard MCP server. $mcpEnv carries the per-request
     * Mattermost trigger context (DASH_MM_* — channel/thread/post/file ids) to the MCP server.
     * $allowedTools is the list of `mcp__dashboard__*` tool names to permit. $onTick is called every
     * few seconds during the (multi-second) run so the caller can keep a heartbeat fresh.
     *
     * Returns Claude's final text (e.g. a summary of what it did). Throws on missing CLI / timeout /
     * non-zero exit / agent error — the caller falls back to the built-in parser.
     *
     * @param array<string,string> $mcpEnv
     * @param list<string>          $allowedTools
     */
    public function runAgent(string $prompt, array $mcpEnv, array $allowedTools, ?callable $onTick = null): string
    {
        if (!$this->isAvailable()) {
            throw new \RuntimeException('claude CLI not found.');
        }
        if ($allowedTools === []) {
            throw new \RuntimeException('No tools allowed.');
        }

        $cfgPath = $this->writeMcpConfig($mcpEnv);
        try {
            // `--tools` is a DEFAULT-DENY allow-list: it sets the ONLY tools that exist for this run,
            // so no built-in (Bash, Read, Monitor, ToolSearch, Workflow, …) is reachable — and it
            // stays safe as new built-ins ship each release (a denylist would not). `--strict-mcp-config`
            // ignores the user's global MCP servers; bypassPermissions auto-approves with no prompt/TTY.
            $args = ['-p', $prompt, '--output-format', 'json',
                '--mcp-config', $cfgPath, '--strict-mcp-config',
                '--permission-mode', 'bypassPermissions'];
            $args = array_merge($args, ['--tools'], $allowedTools);

            $envelope = $this->run($args, $onTick);
            if (!is_array($envelope) || ($envelope['is_error'] ?? false) === true) {
                $msg = is_array($envelope) ? (string) ($envelope['result'] ?? 'agent error') : 'no result';
                throw new \RuntimeException('claude agent error: ' . $msg);
            }
            return trim((string) ($envelope['result'] ?? ''));
        } finally {
            @unlink($cfgPath);
        }
    }

    /**
     * Write a temp MCP config pointing the `claude` CLI at bin/dashboard-mcp (stdio), carrying the
     * per-request context in the server's `env` block (which Claude passes through to the server).
     *
     * @param array<string,string> $mcpEnv
     */
    private function writeMcpConfig(array $mcpEnv): string
    {
        $script = dirname(__DIR__) . '/bin/dashboard-mcp';
        $php = PHP_BINARY !== '' ? PHP_BINARY : 'php';
        $config = ['mcpServers' => ['dashboard' => [
            'type' => 'stdio',
            'command' => $php,
            'args' => [$script],
            'env' => (object) $mcpEnv,
        ]]];
        $json = json_encode($config, JSON_UNESCAPED_SLASHES);
        if ($json === false) {
            throw new \RuntimeException('Failed to encode MCP config.');
        }
        $old = umask(0077);
        try {
            $path = tempnam(sys_get_temp_dir(), 'dash-mcp-');
            if ($path === false || @file_put_contents($path, $json) === false) {
                throw new \RuntimeException('Failed to write MCP config.');
            }
            return $path;
        } finally {
            umask($old);
        }
    }

    /**
     * Spawn the CLI with the given args (argv array — no shell), stdin from /dev/null, a neutral cwd,
     * a hard timeout, an output cap, and a periodic heartbeat. Returns the decoded JSON envelope.
     *
     * stdout/stderr go to TEMP FILES, not pipes, on purpose: proc_open's parent-side pipe fds aren't
     * close-on-exec, so a long-lived process (the listener) that spawns claude more than once leaks
     * them into the next claude, wedging its MCP handshake. File descriptors leave nothing to inherit.
     *
     * @param list<string> $args
     * @return array<string,mixed>
     */
    private function run(array $args, ?callable $onTick): array
    {
        $cmd = array_merge([$this->bin], $args);
        $outFile = tempnam(sys_get_temp_dir(), 'claude-out-');
        $errFile = tempnam(sys_get_temp_dir(), 'claude-err-');
        if ($outFile === false || $errFile === false) {
            throw new \RuntimeException('Failed to create temp files for the claude CLI.');
        }
        $cleanup = static function () use ($outFile, $errFile): void {
            @unlink($outFile);
            @unlink($errFile);
        };

        $descriptors = [
            0 => ['file', '/dev/null', 'r'], // no stdin — the prompt is an argument
            1 => ['file', $outFile, 'w'],
            2 => ['file', $errFile, 'w'],
        ];
        $cwd = sys_get_temp_dir(); // neutral: don't load THIS project's CLAUDE.md / .mcp config
        $proc = @proc_open($cmd, $descriptors, $pipes, $cwd);
        if (!is_resource($proc)) {
            $cleanup();
            throw new \RuntimeException('Could not start the claude CLI (' . $this->bin . ').');
        }

        $deadline = microtime(true) + $this->timeout;
        $lastTick = microtime(true);
        $kill = function (string $why) use ($proc, $cleanup): void {
            @proc_terminate($proc, 9);
            @proc_close($proc);
            $cleanup();
            throw new \RuntimeException($why);
        };
        while (true) {
            $status = proc_get_status($proc);
            if (!$status['running']) {
                break;
            }
            if (microtime(true) > $deadline) {
                $kill('claude CLI timed out after ' . $this->timeout . 's.');
            }
            if ((int) @filesize($outFile) > self::MAX_OUTPUT) {
                $kill('claude CLI produced too much output.');
            }
            if ($onTick !== null && microtime(true) - $lastTick >= 5.0) {
                $onTick();
                $lastTick = microtime(true);
            }
            usleep(150000); // 150ms
        }
        $exit = proc_close($proc);
        $out = (string) @file_get_contents($outFile);
        $cleanup();
        if ($exit !== 0) {
            throw new \RuntimeException('claude CLI exited with code ' . $exit . '.');
        }

        $decoded = json_decode(trim($out), true);
        return is_array($decoded) ? $decoded : [];
    }
}
