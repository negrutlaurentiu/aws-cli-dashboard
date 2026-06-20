<?php

declare(strict_types=1);

namespace AwsDash;

/**
 * A tiny local task manager: a Kanban-style board (pending / in_progress / review / done /
 * archived), per-task work timer with session history, time-in-status tracking, file
 * attachments, and weekly work summaries. Everything is stored in config/tasks.json (0600,
 * gitignored) plus attachment blobs under data/task-files/<taskId>/.
 */
final class Tasks
{
    public const STATUSES = ['pending', 'in_progress', 'review', 'done', 'archived'];

    private string $tasksPath;
    private string $filesDir;

    public function __construct(string $configDir, string $filesDir)
    {
        $this->tasksPath = $configDir . '/tasks.json';
        $this->filesDir = $filesDir;
        if (!is_dir($configDir)) {
            mkdir($configDir, 0700, true);
        }
    }

    /**
     * Acquire an exclusive advisory lock for the duration of the caller's scope (the returned
     * object releases it in its destructor). Makes each read-modify-write of tasks.json atomic
     * across the PHP server's worker processes, so concurrent edits can't lose updates.
     */
    private function acquireLock(): object
    {
        $lp = $this->tasksPath . '.lock';
        $fh = @fopen($lp, 'c');
        if ($fh !== false) {
            @chmod($lp, 0600);
            @flock($fh, LOCK_EX);
        }
        return new class ($fh) {
            /** @param resource|false $fh */
            public function __construct(private $fh)
            {
            }

            public function __destruct()
            {
                if ($this->fh !== false) {
                    @flock($this->fh, LOCK_UN);
                    @fclose($this->fh);
                }
            }
        };
    }

    // ---- queries ----------------------------------------------------------

    /** @return array<int,array<string,mixed>> serialized tasks with computed time fields */
    public function all(?int $now = null): array
    {
        $now ??= time();
        return array_map(fn ($t) => $this->serialize($t, $now), $this->raw());
    }

    public function find(string $id): ?array
    {
        foreach ($this->raw() as $t) {
            if (($t['id'] ?? null) === $id) {
                return $t;
            }
        }
        return null;
    }

    // ---- mutations --------------------------------------------------------

    public function create(string $title, string $description): array
    {
        $lock = $this->acquireLock();
        $title = trim($title);
        if ($title === '') {
            throw new \InvalidArgumentException('Task title is required.');
        }
        $nowIso = gmdate('c');
        $task = [
            'id' => 't-' . bin2hex(random_bytes(6)),
            'title' => $title,
            'description' => trim($description),
            'status' => 'pending',
            'created_at' => $nowIso,
            'updated_at' => $nowIso,
            'status_since' => $nowIso,
            'history' => [['status' => 'pending', 'at' => $nowIso]],
            'sessions' => [],
            'timer_started' => null,
            'attachments' => [],
        ];
        $tasks = $this->raw();
        $tasks[] = $task;
        $this->write($tasks);
        return $this->serialize($task, time());
    }

    /** @param array<string,mixed> $fields */
    public function update(string $id, array $fields): array
    {
        $lock = $this->acquireLock();
        $tasks = $this->raw();
        foreach ($tasks as $i => $t) {
            if (($t['id'] ?? null) !== $id) {
                continue;
            }
            if (array_key_exists('title', $fields)) {
                $title = trim((string) $fields['title']);
                if ($title === '') {
                    throw new \InvalidArgumentException('Task title cannot be empty.');
                }
                $t['title'] = $title;
            }
            if (array_key_exists('description', $fields)) {
                $t['description'] = trim((string) $fields['description']);
            }
            if (array_key_exists('status', $fields)) {
                $status = (string) $fields['status'];
                if (!in_array($status, self::STATUSES, true)) {
                    throw new \InvalidArgumentException('Unknown status: ' . $status);
                }
                if ($status !== $t['status']) {
                    $nowIso = gmdate('c');
                    $t['status'] = $status;
                    $t['status_since'] = $nowIso;
                    $t['history'][] = ['status' => $status, 'at' => $nowIso];
                }
            }
            $t['updated_at'] = gmdate('c');
            $tasks[$i] = $t;
            $this->write($tasks);
            return $this->serialize($t, time());
        }
        throw new \RuntimeException('Unknown task: ' . $id);
    }

    public function delete(string $id): void
    {
        $lock = $this->acquireLock();
        $tasks = array_values(array_filter($this->raw(), fn ($t) => ($t['id'] ?? null) !== $id));
        $this->write($tasks);
        $dir = $this->filesDir . '/' . $id;
        if (is_dir($dir)) {
            foreach ((array) glob($dir . '/*') as $f) {
                @unlink($f);
            }
            @rmdir($dir);
        }
    }

    /**
     * Start the work timer on a task. Only one timer runs at a time, so any other running
     * timer is stopped first (its elapsed time is banked as a session).
     */
    public function timerStart(string $id): array
    {
        $lock = $this->acquireLock();
        $now = time();
        $nowIso = gmdate('c');
        $tasks = $this->raw();
        $found = false;
        foreach ($tasks as $i => $t) {
            if (!empty($t['timer_started']) && ($t['id'] ?? null) !== $id) {
                $tasks[$i] = $this->bankTimer($t, $now);
            }
            if (($t['id'] ?? null) === $id) {
                $found = true;
            }
        }
        foreach ($tasks as $i => $t) {
            if (($t['id'] ?? null) === $id) {
                if (empty($t['timer_started'])) {
                    $t['timer_started'] = $nowIso;
                    $t['updated_at'] = $nowIso;
                    $tasks[$i] = $t;
                }
            }
        }
        if (!$found) {
            throw new \RuntimeException('Unknown task: ' . $id);
        }
        $this->write($tasks);
        return $this->serialize($this->pick($tasks, $id), $now);
    }

    public function timerStop(string $id): array
    {
        $lock = $this->acquireLock();
        $now = time();
        $tasks = $this->raw();
        foreach ($tasks as $i => $t) {
            if (($t['id'] ?? null) === $id) {
                $tasks[$i] = $this->bankTimer($t, $now);
                $this->write($tasks);
                return $this->serialize($tasks[$i], $now);
            }
        }
        throw new \RuntimeException('Unknown task: ' . $id);
    }

    // ---- attachments ------------------------------------------------------

    /**
     * Move an uploaded temp file into the task's folder and record its metadata.
     * @return array{id:string,filename:string,mime:string,size:int,at:string}
     */
    public function addAttachment(string $taskId, string $tmpPath, string $originalName, string $mime, int $size): array
    {
        $lock = $this->acquireLock();
        $task = $this->find($taskId);
        if ($task === null) {
            throw new \RuntimeException('Unknown task: ' . $taskId);
        }
        $fileId = 'f-' . bin2hex(random_bytes(8));
        $dir = $this->filesDir . '/' . $taskId;
        $old = umask(0077);
        try {
            if (!is_dir($dir) && !mkdir($dir, 0700, true) && !is_dir($dir)) {
                throw new \RuntimeException('Unable to create attachment dir.');
            }
            $dest = $dir . '/' . $fileId;
            if (!@move_uploaded_file($tmpPath, $dest) && !@rename($tmpPath, $dest)) {
                throw new \RuntimeException('Unable to store the uploaded file.');
            }
            @chmod($dest, 0600);
        } finally {
            umask($old);
        }

        $meta = [
            'id' => $fileId,
            'filename' => $this->safeName($originalName),
            'mime' => $mime,
            'size' => $size,
            'at' => gmdate('c'),
        ];
        $tasks = $this->raw();
        foreach ($tasks as $i => $t) {
            if (($t['id'] ?? null) === $taskId) {
                $t['attachments'][] = $meta;
                $t['updated_at'] = gmdate('c');
                $tasks[$i] = $t;
                $this->write($tasks);
                break;
            }
        }
        return $meta;
    }

    /** @return array{path:string,meta:array<string,mixed>}|null */
    public function attachment(string $taskId, string $fileId): ?array
    {
        $task = $this->find($taskId);
        if ($task === null) {
            return null;
        }
        foreach (($task['attachments'] ?? []) as $a) {
            if (($a['id'] ?? null) === $fileId) {
                $path = $this->filesDir . '/' . $taskId . '/' . $fileId;
                return is_file($path) ? ['path' => $path, 'meta' => $a] : null;
            }
        }
        return null;
    }

    public function removeAttachment(string $taskId, string $fileId): void
    {
        $lock = $this->acquireLock();
        $tasks = $this->raw();
        foreach ($tasks as $i => $t) {
            if (($t['id'] ?? null) === $taskId) {
                $t['attachments'] = array_values(array_filter(
                    $t['attachments'] ?? [],
                    fn ($a) => ($a['id'] ?? null) !== $fileId
                ));
                $t['updated_at'] = gmdate('c');
                $tasks[$i] = $t;
                $this->write($tasks);
                break;
            }
        }
        $path = $this->filesDir . '/' . $taskId . '/' . $fileId;
        if (is_file($path)) {
            @unlink($path);
        }
    }

    // ---- weekly summary ---------------------------------------------------

    /**
     * Aggregate work for the ISO week (Mon 00:00 .. next Mon 00:00, local time) containing
     * $refTs. Worked seconds are the portion of each session overlapping the week.
     *
     * @return array<string,mixed>
     */
    public function weekSummary(int $refTs, ?int $now = null): array
    {
        $now ??= time();
        $dow = (int) date('N', $refTs);              // 1=Mon..7=Sun
        $weekStart = strtotime('today', $refTs) - ($dow - 1) * 86400;
        $weekEnd = $weekStart + 7 * 86400;

        $perTask = [];
        $total = 0;
        $completed = [];
        $created = [];

        foreach ($this->raw() as $t) {
            $secs = 0;
            foreach (($t['sessions'] ?? []) as $s) {
                $start = isset($s['start']) ? strtotime((string) $s['start']) : false;
                $end = isset($s['end']) && $s['end'] ? strtotime((string) $s['end']) : false;
                if ($start === false) {
                    continue;
                }
                if ($end === false) {
                    $end = $now;
                }
                $secs += max(0, min($end, $weekEnd) - max($start, $weekStart));
            }
            if (!empty($t['timer_started'])) {
                $start = strtotime((string) $t['timer_started']);
                if ($start !== false) {
                    $secs += max(0, min($now, $weekEnd) - max($start, $weekStart));
                }
            }
            if ($secs > 0) {
                $perTask[] = ['id' => $t['id'], 'title' => $t['title'], 'status' => $t['status'], 'seconds' => $secs];
                $total += $secs;
            }
            foreach (($t['history'] ?? []) as $h) {
                if (($h['status'] ?? '') === 'done') {
                    $at = strtotime((string) ($h['at'] ?? ''));
                    if ($at !== false && $at >= $weekStart && $at < $weekEnd) {
                        $completed[] = ['id' => $t['id'], 'title' => $t['title'], 'at' => $h['at']];
                    }
                }
            }
            $cat = strtotime((string) ($t['created_at'] ?? ''));
            if ($cat !== false && $cat >= $weekStart && $cat < $weekEnd) {
                $created[] = ['id' => $t['id'], 'title' => $t['title'], 'at' => $t['created_at']];
            }
        }

        usort($perTask, fn ($a, $b) => $b['seconds'] <=> $a['seconds']);

        return [
            'week_start' => date('c', $weekStart),
            'week_end' => date('c', $weekEnd),
            'week_label' => date('M j', $weekStart) . ' – ' . date('M j, Y', $weekEnd - 86400),
            'total_seconds' => $total,
            'per_task' => $perTask,
            'completed' => $completed,
            'created' => $created,
        ];
    }

    // ---- helpers ----------------------------------------------------------

    private function bankTimer(array $t, int $now): array
    {
        if (empty($t['timer_started'])) {
            return $t;
        }
        $start = strtotime((string) $t['timer_started']);
        $secs = $start !== false ? max(0, $now - $start) : 0;
        $t['sessions'][] = [
            'start' => $t['timer_started'],
            'end' => gmdate('c', $now),
            'seconds' => $secs,
        ];
        $t['timer_started'] = null;
        $t['updated_at'] = gmdate('c', $now);
        return $t;
    }

    private function pick(array $tasks, string $id): array
    {
        foreach ($tasks as $t) {
            if (($t['id'] ?? null) === $id) {
                return $t;
            }
        }
        throw new \RuntimeException('Unknown task: ' . $id);
    }

    private function serialize(array $t, int $now): array
    {
        $worked = 0;
        foreach (($t['sessions'] ?? []) as $s) {
            $worked += (int) ($s['seconds'] ?? 0);
        }
        $running = !empty($t['timer_started']);
        if ($running) {
            $start = strtotime((string) $t['timer_started']);
            if ($start !== false) {
                $worked += max(0, $now - $start);
            }
        }
        $statusSince = strtotime((string) ($t['status_since'] ?? $t['created_at'] ?? '')) ?: $now;

        return [
            'id' => $t['id'],
            'title' => $t['title'],
            'description' => $t['description'] ?? '',
            'status' => $t['status'],
            'created_at' => $t['created_at'] ?? null,
            'updated_at' => $t['updated_at'] ?? null,
            'status_since' => $t['status_since'] ?? null,
            'status_seconds' => max(0, $now - $statusSince),
            'status_totals' => $this->statusTotals($t, $now),
            'worked_seconds' => $worked,
            'running' => $running,
            'timer_started' => $t['timer_started'] ?? null,
            'attachments' => array_values($t['attachments'] ?? []),
        ];
    }

    /** Cumulative seconds spent in each status, derived from the transition history. */
    private function statusTotals(array $t, int $now): array
    {
        $totals = array_fill_keys(self::STATUSES, 0);
        $hist = $t['history'] ?? [];
        $n = count($hist);
        for ($i = 0; $i < $n; $i++) {
            $st = (string) ($hist[$i]['status'] ?? '');
            $from = strtotime((string) ($hist[$i]['at'] ?? ''));
            if ($from === false || !isset($totals[$st])) {
                continue;
            }
            $to = ($i + 1 < $n) ? (strtotime((string) ($hist[$i + 1]['at'] ?? '')) ?: $now) : $now;
            $totals[$st] += max(0, $to - $from);
        }
        return $totals;
    }

    /** @return array<int,array<string,mixed>> */
    private function raw(): array
    {
        if (!is_file($this->tasksPath)) {
            return [];
        }
        $raw = file_get_contents($this->tasksPath);
        if ($raw === false || trim($raw) === '') {
            return [];
        }
        $data = json_decode($raw, true);
        $tasks = is_array($data) && isset($data['tasks']) && is_array($data['tasks']) ? $data['tasks'] : [];
        return array_values($tasks);
    }

    private function write(array $tasks): void
    {
        $json = json_encode(['tasks' => array_values($tasks)], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
        if ($json === false) {
            throw new \RuntimeException('Failed to encode tasks.');
        }
        $old = umask(0077);
        try {
            $tmp = $this->tasksPath . '.tmp.' . getmypid();
            @unlink($tmp);
            $fh = @fopen($tmp, 'xb');
            if ($fh === false) {
                throw new \RuntimeException('Failed to create temp tasks file.');
            }
            if (@fwrite($fh, $json . "\n") === false) {
                fclose($fh);
                @unlink($tmp);
                throw new \RuntimeException('Failed to write tasks.');
            }
            fclose($fh);
            @chmod($tmp, 0600);
            if (!@rename($tmp, $this->tasksPath)) {
                @unlink($tmp);
                throw new \RuntimeException('Failed to replace tasks file.');
            }
            @chmod($this->tasksPath, 0600);
        } finally {
            umask($old);
        }
    }

    private function safeName(string $name): string
    {
        $name = preg_replace('/[\r\n\x00]+/', '', $name) ?? '';
        $name = basename($name);
        $name = trim($name);
        return $name === '' ? 'file' : mb_substr($name, 0, 180);
    }
}
