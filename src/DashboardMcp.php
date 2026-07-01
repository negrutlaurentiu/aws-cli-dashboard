<?php

declare(strict_types=1);

namespace AwsDash;

/**
 * Stdio MCP server (JSON-RPC 2.0 over stdin/stdout) that exposes the dashboard's task board to the
 * local `claude` CLI, so the @Claude intake listener can run Claude AGENTICALLY: Claude reads the
 * Mattermost message and calls these tools to create / update / delete tasks, attach a file from the
 * triggering message, post a reply in the thread, or run a check-in/out digest.
 *
 * No Composer / MCP SDK — the protocol is small and hand-rolled (initialize / tools/list /
 * tools/call, plus ping and the `notifications/initialized` notification).
 *
 * Scope & safety: the server only ever touches THIS dashboard's task board and the ONE Mattermost
 * thread the request came from. The trigger context (channel / thread / post / allowed file ids) is
 * passed in via environment variables by the listener — never chosen by the model — so:
 *   - `reply` can only post into the originating channel/thread,
 *   - `attach_file` can only pull a file id that was actually present on the triggering message.
 * The Mattermost token is read server-side (Store) and never exposed to the model. Each tool is
 * wrapped so a failure returns an MCP error result (the agent sees it) rather than crashing.
 */
final class DashboardMcp
{
    private const PROTOCOL = '2024-11-05';

    /** Bound destructive actions per agent run, so a runaway/prompt-injected agent can't wipe the board. */
    private const MAX_DELETES = 5;

    private App $app;
    private int $deletes = 0;
    /** @var resource */
    private $in;
    /** @var resource */
    private $out;

    // Per-invocation Mattermost trigger context (from env; set by bin/mm-listen).
    private string $channelId;
    private string $rootId;
    private string $postId;
    /** @var list<string> file ids that were attached to the triggering message (allow-list) */
    private array $fileIds;
    private string $tag;

    public function __construct(App $app)
    {
        $this->app = $app;
        $this->in = STDIN;
        $this->out = STDOUT;
        $this->channelId = (string) (getenv('DASH_MM_CHANNEL_ID') ?: '');
        $this->rootId = (string) (getenv('DASH_MM_ROOT_ID') ?: '');
        $this->postId = (string) (getenv('DASH_MM_POST_ID') ?: '');
        $ids = (string) (getenv('DASH_MM_FILE_IDS') ?: '');
        $this->fileIds = array_values(array_filter(array_map('trim', explode(',', $ids)), static fn ($s) => $s !== ''));
        $this->tag = (string) (getenv('DASH_MM_TAG') ?: '');
    }

    public function run(): void
    {
        while (($line = fgets($this->in)) !== false) {
            $line = trim($line);
            if ($line === '') {
                continue;
            }
            $req = json_decode($line, true);
            if (!is_array($req)) {
                continue; // ignore unparseable lines
            }
            $id = $req['id'] ?? null;
            $method = (string) ($req['method'] ?? '');

            // Notifications carry no id and expect no response.
            if ($id === null) {
                continue;
            }

            try {
                $result = $this->dispatch($method, is_array($req['params'] ?? null) ? $req['params'] : []);
                if ($result === null) {
                    $this->send(['jsonrpc' => '2.0', 'id' => $id, 'error' => ['code' => -32601, 'message' => 'Method not found: ' . $method]]);
                } else {
                    $this->send(['jsonrpc' => '2.0', 'id' => $id, 'result' => $result]);
                }
            } catch (\Throwable $e) {
                $this->send(['jsonrpc' => '2.0', 'id' => $id, 'error' => ['code' => -32603, 'message' => $e->getMessage()]]);
            }
        }
    }

    /** @param array<string,mixed> $params @return array<string,mixed>|null null ⇒ method not found */
    private function dispatch(string $method, array $params): ?array
    {
        switch ($method) {
            case 'initialize':
                $ver = (string) ($params['protocolVersion'] ?? self::PROTOCOL);
                return [
                    'protocolVersion' => $ver !== '' ? $ver : self::PROTOCOL,
                    'capabilities' => ['tools' => new \stdClass()],
                    'serverInfo' => ['name' => 'dashboard', 'version' => '1.0.0'],
                ];
            case 'ping':
                return []; // empty result
            case 'tools/list':
                return ['tools' => $this->tools()];
            case 'tools/call':
                return $this->callTool(
                    (string) ($params['name'] ?? ''),
                    is_array($params['arguments'] ?? null) ? $params['arguments'] : []
                );
            default:
                return null;
        }
    }

    /** @return list<array<string,mixed>> tool schemas */
    private function tools(): array
    {
        $str = static fn (string $d) => ['type' => 'string', 'description' => $d];
        $statusEnum = ['type' => 'string', 'enum' => Tasks::STATUSES, 'description' => 'Task status.'];
        return [
            [
                'name' => 'list_tasks',
                'description' => 'List tasks on the board (most recently updated first). Use this to find a task to update — e.g. "the task you just created" or one the user refers to.',
                'inputSchema' => ['type' => 'object', 'properties' => [
                    'status' => ['type' => 'string', 'enum' => Tasks::STATUSES, 'description' => 'Optional: only this status.'],
                    'project' => $str('Optional: only this project (case-insensitive).'),
                    'query' => $str('Optional: only tasks whose title contains this text (case-insensitive).'),
                    'limit' => ['type' => 'integer', 'description' => 'Max results (default 30).'],
                ], 'additionalProperties' => false],
            ],
            [
                'name' => 'create_task',
                'description' => 'Create a new task on the board.',
                'inputSchema' => ['type' => 'object', 'properties' => [
                    'title' => $str('Concise imperative title.'),
                    'description' => $str('Optional details.'),
                    'project' => $str('Optional project name.'),
                    'status' => $statusEnum,
                ], 'required' => ['title'], 'additionalProperties' => false],
            ],
            [
                'name' => 'update_task',
                'description' => 'Update fields of an existing task (only the fields you pass change). Get the id from list_tasks.',
                'inputSchema' => ['type' => 'object', 'properties' => [
                    'id' => $str('Task id (e.g. t-abc123…).'),
                    'title' => $str('New title.'),
                    'description' => $str('New description.'),
                    'project' => $str('New project.'),
                    'status' => $statusEnum,
                ], 'required' => ['id'], 'additionalProperties' => false],
            ],
            [
                'name' => 'delete_task',
                'description' => 'Permanently delete a task. To merely shelve it, prefer update_task with status "archived".',
                'inputSchema' => ['type' => 'object', 'properties' => [
                    'id' => $str('Task id to delete.'),
                ], 'required' => ['id'], 'additionalProperties' => false],
            ],
            [
                'name' => 'attach_file',
                'description' => 'Attach a file that was uploaded in the triggering Mattermost message to a task. file_id must be one of the ids given in the request context.',
                'inputSchema' => ['type' => 'object', 'properties' => [
                    'task_id' => $str('Task id to attach to.'),
                    'file_id' => $str('Mattermost file id (must be one listed in the context).'),
                ], 'required' => ['task_id', 'file_id'], 'additionalProperties' => false],
            ],
            [
                'name' => 'reply',
                'description' => 'Post a short reply in the Mattermost thread this request came from. Use it to confirm what you did or to answer a question. Keep it to 1–2 sentences.',
                'inputSchema' => ['type' => 'object', 'properties' => [
                    'text' => $str('The reply text.'),
                ], 'required' => ['text'], 'additionalProperties' => false],
            ],
            [
                'name' => 'checkin',
                'description' => 'Post the daily CHECK-IN digest (your In Progress + Pending tasks) to the configured check-in channel.',
                'inputSchema' => ['type' => 'object', 'properties' => new \stdClass(), 'additionalProperties' => false],
            ],
            [
                'name' => 'checkout',
                'description' => 'Post the daily CHECK-OUT digest (tasks done today + still in progress) to the configured check-out channel.',
                'inputSchema' => ['type' => 'object', 'properties' => new \stdClass(), 'additionalProperties' => false],
            ],
            [
                'name' => 'get_time_today',
                'description' => 'Report how much time the operator has LOGGED ON TASKS today (since local midnight): the total, plus a per-task and per-project breakdown with each task\'s status. Use this to ANSWER questions like "how many hours did I log today?", "how long on the billing task today?", or "what did I work on today?". Read-only — does not change the board.',
                'inputSchema' => ['type' => 'object', 'properties' => new \stdClass(), 'additionalProperties' => false],
            ],
            [
                'name' => 'get_screen_time',
                'description' => 'Report the operator\'s macOS computer-active time today and where it went: total active time, top apps, and top websites. Use this to ANSWER questions like "how much screen time today?" or "what websites did I spend the most time on?". Read-only.',
                'inputSchema' => ['type' => 'object', 'properties' => new \stdClass(), 'additionalProperties' => false],
            ],
        ];
    }

    /** @param array<string,mixed> $args @return array<string,mixed> MCP tool result */
    private function callTool(string $name, array $args): array
    {
        try {
            $text = match ($name) {
                'list_tasks' => $this->toolListTasks($args),
                'create_task' => $this->toolCreateTask($args),
                'update_task' => $this->toolUpdateTask($args),
                'delete_task' => $this->toolDeleteTask($args),
                'attach_file' => $this->toolAttachFile($args),
                'reply' => $this->toolReply($args),
                'checkin' => $this->toolCheck('checkin'),
                'checkout' => $this->toolCheck('checkout'),
                'get_time_today' => $this->toolGetTimeToday(),
                'get_screen_time' => $this->toolGetScreenTime(),
                default => throw new \RuntimeException('Unknown tool: ' . $name),
            };
            return ['content' => [['type' => 'text', 'text' => $text]], 'isError' => false];
        } catch (\Throwable $e) {
            return ['content' => [['type' => 'text', 'text' => 'Error: ' . $e->getMessage()]], 'isError' => true];
        }
    }

    // ---- tool implementations ---------------------------------------------

    /** @param array<string,mixed> $a */
    private function toolListTasks(array $a): string
    {
        $status = isset($a['status']) ? (string) $a['status'] : '';
        $project = isset($a['project']) ? strtolower(trim((string) $a['project'])) : '';
        $query = isset($a['query']) ? strtolower(trim((string) $a['query'])) : '';
        $limit = max(1, min(100, (int) ($a['limit'] ?? 30)));

        $tasks = $this->app->tasks->all();
        // most recently updated first
        usort($tasks, static fn ($x, $y) => strcmp((string) ($y['updated_at'] ?? ''), (string) ($x['updated_at'] ?? '')));
        $out = [];
        foreach ($tasks as $t) {
            if ($status !== '' && ($t['status'] ?? '') !== $status) {
                continue;
            }
            if ($project !== '' && strtolower((string) ($t['project'] ?? '')) !== $project) {
                continue;
            }
            if ($query !== '' && !str_contains(strtolower((string) ($t['title'] ?? '')), $query)) {
                continue;
            }
            $out[] = [
                'id' => $t['id'] ?? '',
                'title' => $t['title'] ?? '',
                'project' => $t['project'] ?? '',
                'status' => $t['status'] ?? '',
                'attachments' => count($t['attachments'] ?? []),
                'updated_at' => $t['updated_at'] ?? '',
            ];
            if (count($out) >= $limit) {
                break;
            }
        }
        return json_encode(['tasks' => $out], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) ?: '{"tasks":[]}';
    }

    /** @param array<string,mixed> $a */
    private function toolCreateTask(array $a): string
    {
        $title = trim((string) ($a['title'] ?? ''));
        if ($title === '') {
            throw new \RuntimeException('title is required.');
        }
        $task = $this->app->tasks->create(
            $title,
            trim((string) ($a['description'] ?? '')),
            (string) ($a['status'] ?? 'pending'),
            trim((string) ($a['project'] ?? ''))
        );
        return 'Created task ' . ($task['id'] ?? '') . ' — "' . ($task['title'] ?? '') . '"'
            . (($task['project'] ?? '') !== '' ? ' in ' . $task['project'] : '') . ' [' . ($task['status'] ?? '') . '].';
    }

    /** @param array<string,mixed> $a */
    private function toolUpdateTask(array $a): string
    {
        $id = trim((string) ($a['id'] ?? ''));
        if ($id === '') {
            throw new \RuntimeException('id is required.');
        }
        $fields = [];
        foreach (['title', 'description', 'project', 'status'] as $k) {
            if (array_key_exists($k, $a)) {
                $fields[$k] = (string) $a[$k];
            }
        }
        if ($fields === []) {
            throw new \RuntimeException('Nothing to update — pass at least one of title/description/project/status.');
        }
        $task = $this->app->tasks->update($id, $fields);
        return 'Updated task ' . $id . ' — now "' . ($task['title'] ?? '') . '"'
            . (($task['project'] ?? '') !== '' ? ' in ' . $task['project'] : '') . ' [' . ($task['status'] ?? '') . '].';
    }

    /** @param array<string,mixed> $a */
    private function toolDeleteTask(array $a): string
    {
        $id = trim((string) ($a['id'] ?? ''));
        if ($id === '') {
            throw new \RuntimeException('id is required.');
        }
        if ($this->deletes >= self::MAX_DELETES) {
            throw new \RuntimeException('Delete limit reached for this request (max ' . self::MAX_DELETES . '). Refusing further deletes.');
        }
        $task = $this->app->tasks->find($id);
        if ($task === null) {
            throw new \RuntimeException('Unknown task: ' . $id);
        }
        // Hard delete is irreversible (drops the task + its attachment blobs), so require it to be
        // archived first. This makes destruction a deliberate two-step, so a single (possibly
        // prompt-injected) instruction can't irreversibly destroy live tasks.
        if (($task['status'] ?? '') !== 'archived') {
            throw new \RuntimeException('Refusing to delete a task that is not archived. Archive it first (update_task with status="archived"), then delete — or just archive it if that\'s enough.');
        }
        $this->deletes++;
        $this->app->tasks->delete($id);
        return 'Deleted archived task ' . $id . '.';
    }

    /** @param array<string,mixed> $a */
    private function toolAttachFile(array $a): string
    {
        $taskId = trim((string) ($a['task_id'] ?? ''));
        $fileId = trim((string) ($a['file_id'] ?? ''));
        if ($taskId === '' || $fileId === '') {
            throw new \RuntimeException('task_id and file_id are required.');
        }
        if (!in_array($fileId, $this->fileIds, true)) {
            throw new \RuntimeException('That file_id was not attached to this message; only these are available: ' . implode(', ', $this->fileIds));
        }
        if ($this->app->tasks->find($taskId) === null) {
            throw new \RuntimeException('Unknown task: ' . $taskId);
        }
        $mm = $this->mattermost();
        $info = $mm->getFileInfo($fileId);
        $name = (string) ($info['name'] ?? ($fileId . '.bin'));
        $mime = (string) ($info['mime_type'] ?? 'application/octet-stream');
        $bytes = $mm->downloadFile($fileId);

        $tmp = tempnam(sys_get_temp_dir(), 'mmfile-');
        if ($tmp === false || @file_put_contents($tmp, $bytes) === false) {
            throw new \RuntimeException('Could not buffer the downloaded file.');
        }
        try {
            $meta = $this->app->tasks->addAttachment($taskId, $tmp, $name, $mime, strlen($bytes));
        } finally {
            @unlink($tmp);
        }
        return 'Attached "' . ($meta['filename'] ?? $name) . '" (' . strlen($bytes) . ' bytes) to task ' . $taskId . '.';
    }

    /** @param array<string,mixed> $a */
    private function toolReply(array $a): string
    {
        $text = trim((string) ($a['text'] ?? ''));
        if ($text === '') {
            throw new \RuntimeException('text is required.');
        }
        if ($this->channelId === '') {
            throw new \RuntimeException('No Mattermost thread context to reply to.');
        }
        // The reply text is model-controlled (and may echo prompt-injected content), so neutralise
        // channel-wide mentions and cap the length before posting.
        $text = MattermostDigest::defuseMentions(mb_substr($text, 0, 4000));
        // The reply is posted as the operator, so if it started with the trigger tag it would
        // re-trigger intake (a loop). Break a leading tag with a zero-width space.
        if ($this->tag !== '' && Intake::matchTag($text, $this->tag) !== null) {
            $text = "\xE2\x80\x8B" . $text;
        }
        // Thread the reply under the conversation it came from.
        $root = $this->rootId !== '' ? $this->rootId : $this->postId;
        $this->mattermost()->post($this->channelId, $text, $root);
        return 'Replied in the thread.';
    }

    private function toolCheck(string $which): string
    {
        $m = $this->app->store->mattermost();
        $mm = $this->mattermost();
        $channelName = $which === 'checkin' ? $m['checkin_channel'] : $m['checkout_channel'];
        if ($channelName === '') {
            throw new \RuntimeException('No ' . $which . ' channel configured.');
        }
        $message = $which === 'checkin'
            ? MattermostDigest::checkin($this->app->tasks)
            : MattermostDigest::checkout($this->app->tasks, (bool) $m['checkout_show_hours']);

        $cachedId = $which === 'checkin' ? $m['checkin_channel_id'] : $m['checkout_channel_id'];
        $channelId = $cachedId !== '' ? $cachedId : $mm->resolveChannelId($m['team'], $channelName);
        $mm->post($channelId, $message);
        return ucfirst($which) . ' digest posted to #' . $channelName . '.';
    }

    /**
     * Today's logged work, as JSON for the agent to phrase. Mirrors the board's "Today worked": every
     * task touched today (worked, running, or finished today), the grand total, and per-project totals.
     */
    private function toolGetTimeToday(): string
    {
        $now = time();
        $tasks = [];
        $byProject = [];
        $total = 0;
        foreach ($this->app->tasks->all($now) as $t) {
            $secs = (int) ($t['today_seconds'] ?? 0);
            // Same "touched today" set the board highlights, so the total reconciles with the header.
            if ($secs <= 0 && empty($t['done_today']) && empty($t['running'])) {
                continue;
            }
            $total += $secs;
            $proj = trim((string) ($t['project'] ?? ''));
            $key = $proj !== '' ? $proj : 'Other';
            $byProject[$key] = ($byProject[$key] ?? 0) + $secs;
            $tasks[] = [
                'title' => (string) ($t['title'] ?? ''),
                'project' => $proj,
                'status' => (string) ($t['status'] ?? ''),
                'today_seconds' => $secs,
                'today_human' => MattermostDigest::fmtDur($secs),
                'done_today' => (bool) ($t['done_today'] ?? false),
                'running' => (bool) ($t['running'] ?? false),
            ];
        }
        usort($tasks, static fn ($a, $b) => $b['today_seconds'] <=> $a['today_seconds']);

        $projects = [];
        foreach ($byProject as $name => $s) {
            $projects[] = ['project' => $name, 'seconds' => $s, 'human' => MattermostDigest::fmtDur($s)];
        }
        usort($projects, static fn ($a, $b) => $b['seconds'] <=> $a['seconds']);

        return json_encode([
            'date' => date('Y-m-d', $now),
            'total_seconds' => $total,
            'total_human' => MattermostDigest::fmtDur($total),
            'task_count' => count($tasks),
            'by_project' => $projects,
            'tasks' => $tasks,
        ], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) ?: '{"total_seconds":0}';
    }

    /**
     * Today's macOS computer-active time (total, top apps, top sites) as JSON. Reuses the same
     * read-only ScreenTime reader the dashboard uses; if the data can't be read (Full Disk Access not
     * granted to this process, Screen Time off) it returns {ok:false,reason:…} so the agent can say so
     * rather than fail.
     */
    private function toolGetScreenTime(): string
    {
        $data = (new ScreenTime(App::homeDir()))->today();
        if (empty($data['ok'])) {
            // Use a fixed reason rather than echoing the raw driver error — a SQLite/open error can carry
            // the home-dir path / macOS username, which shouldn't reach the model (or a thread).
            $reason = !empty($data['needs_fda'])
                ? 'Screen Time needs Full Disk Access for this process (System Settings → Privacy & Security → Full Disk Access).'
                : 'Screen Time is unavailable right now (it may be turned off in System Settings).';
            return json_encode(['ok' => false, 'reason' => $reason], JSON_UNESCAPED_SLASHES) ?: '{"ok":false}';
        }
        $fmt = static fn (array $rows): array => array_map(static fn ($r): array => array_filter([
            'name' => (string) ($r['name'] ?? ''),
            'seconds' => (int) ($r['seconds'] ?? 0),
            'human' => MattermostDigest::fmtDur((int) ($r['seconds'] ?? 0)),
            'category' => isset($r['category']) ? (string) $r['category'] : null,
        ], static fn ($v) => $v !== null), $rows);
        $total = (int) ($data['seconds'] ?? 0);
        return json_encode([
            'ok' => true,
            'date' => date('Y-m-d'),
            'total_seconds' => $total,
            'total_human' => MattermostDigest::fmtDur($total),
            'categories' => $fmt($data['categories'] ?? []),
            'apps' => $fmt(array_slice($data['apps'] ?? [], 0, 10)),
            'sites' => $fmt(array_slice($data['sites'] ?? [], 0, 10)),
        ], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) ?: '{"ok":true,"total_seconds":' . $total . '}';
    }

    private function mattermost(): Mattermost
    {
        $mm = new Mattermost($this->app->store->mattermost());
        if (!$mm->isConfigured()) {
            throw new \RuntimeException('Mattermost is not configured.');
        }
        return $mm;
    }

    /** @param array<string,mixed> $msg */
    private function send(array $msg): void
    {
        // Substitute invalid UTF-8 rather than fail (tool results can carry odd bytes); if encoding
        // still fails, emit a valid JSON-RPC error for the same id — never a blank/garbled line.
        $json = json_encode($msg, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE);
        if ($json === false) {
            $json = json_encode(['jsonrpc' => '2.0', 'id' => $msg['id'] ?? null, 'error' => ['code' => -32603, 'message' => 'response encoding failed']]);
            if ($json === false) {
                return;
            }
        }
        fwrite($this->out, $json . "\n");
        fflush($this->out);
    }
}
