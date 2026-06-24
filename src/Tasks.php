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

    /** Time is logged in 15-minute units; a worked block rounds UP to the next quarter-hour. */
    private const QUARTER = 900;
    /** A start→stop shorter than this is treated as an accidental tap and banks no time. */
    private const MIN_SESSION = 30;

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

    public function create(string $title, string $description, string $status = 'pending', string $project = ''): array
    {
        $lock = $this->acquireLock();
        $title = trim($title);
        if ($title === '') {
            throw new \InvalidArgumentException('Task title is required.');
        }
        if (!in_array($status, self::STATUSES, true)) {
            $status = 'pending';
        }
        $nowIso = gmdate('c');
        $task = [
            'id' => 't-' . bin2hex(random_bytes(6)),
            'title' => $title,
            'project' => mb_substr(trim($project), 0, 120),
            'description' => trim($description),
            'status' => $status,
            'created_at' => $nowIso,
            'updated_at' => $nowIso,
            'status_since' => $nowIso,
            'history' => [['status' => $status, 'at' => $nowIso]],
            'sessions' => [],
            'timer_started' => null,
            'attachments' => [],
            'notes' => [],
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
            if (array_key_exists('project', $fields)) {
                $t['project'] = mb_substr(trim((string) $fields['project']), 0, 120);
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

    /**
     * Move a task to $status and position it (drag-and-drop). The board renders tasks in storage
     * order filtered by column, so "position" is the task's place in the global array: we pull the
     * task out, optionally change its status (recording the transition), then splice it back in
     * immediately before $beforeId — or, when $beforeId is empty/unknown, at the bottom of the
     * target column. A same-column reorder changes only order (no history entry, no created_at
     * touch), so re-ordering or renaming never makes a task look newly added.
     */
    public function move(string $id, string $status, string $beforeId = ''): array
    {
        $lock = $this->acquireLock();
        if (!in_array($status, self::STATUSES, true)) {
            throw new \InvalidArgumentException('Unknown status: ' . $status);
        }
        $moved = null;
        $rest = [];
        foreach ($this->raw() as $t) {
            if (($t['id'] ?? null) === $id) {
                $moved = $t;
            } else {
                $rest[] = $t;
            }
        }
        if ($moved === null) {
            throw new \RuntimeException('Unknown task: ' . $id);
        }
        if ($status !== ($moved['status'] ?? '')) {
            $nowIso = gmdate('c');
            $moved['status'] = $status;
            $moved['status_since'] = $nowIso;
            $moved['history'][] = ['status' => $status, 'at' => $nowIso];
        }
        $moved['updated_at'] = gmdate('c');

        // Where to reinsert: before $beforeId if it's a real other task, else after the last task
        // already in $status (bottom of that column), else at the very end.
        $insertAt = null;
        if ($beforeId !== '' && $beforeId !== $id) {
            foreach ($rest as $i => $t) {
                if (($t['id'] ?? null) === $beforeId) {
                    $insertAt = $i;
                    break;
                }
            }
        }
        if ($insertAt === null) {
            $insertAt = count($rest);
            for ($i = count($rest) - 1; $i >= 0; $i--) {
                if (($rest[$i]['status'] ?? '') === $status) {
                    $insertAt = $i + 1;
                    break;
                }
            }
        }
        array_splice($rest, $insertAt, 0, [$moved]);
        $this->write($rest);
        return $this->serialize($moved, time());
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

    /**
     * Set a specific local DAY's worked time to $targetSeconds ($date = 'YYYY-MM-DD'). The target is
     * snapped to a 15-minute unit (the logging granularity). That day's timer sessions are kept; we
     * drop any earlier same-day manual log and add one dated adjustment (anchored at noon of that
     * day, never in the future) so the day lands exactly on the target. The delta also flows into
     * the lifetime "Worked total". Future days are rejected.
     */
    public function setWorkedForDay(string $id, string $date, int $targetSeconds): array
    {
        $lock = $this->acquireLock();
        $now = time();
        $targetSeconds = max(0, min($targetSeconds, 100_000_000));
        $targetSeconds = (int) (round($targetSeconds / self::QUARTER) * self::QUARTER); // 15-min units
        if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $date)) {
            throw new \InvalidArgumentException('A valid date (YYYY-MM-DD) is required.');
        }
        $dayStart = strtotime($date . ' 00:00:00');
        if ($dayStart === false) {
            throw new \InvalidArgumentException('Invalid date.');
        }
        if ($dayStart > $now) {
            throw new \InvalidArgumentException("Can't log time for a future day.");
        }
        $dayEnd = $dayStart + 86400;
        $tasks = $this->raw();
        foreach ($tasks as $i => $t) {
            if (($t['id'] ?? null) === $id) {
                // keep adjustments outside this day, then add one to hit the target for this day
                $kept = [];
                foreach (($t['adjustments'] ?? []) as $a) {
                    $at = strtotime((string) ($a['at'] ?? ''));
                    if (!($at !== false && $at >= $dayStart && $at < $dayEnd)) {
                        $kept[] = $a;
                    }
                }
                $t['adjustments'] = $kept;
                $base = $this->workedInWindow($t, $dayStart, $dayEnd, $now); // sessions + running in that day
                $anchor = min($dayStart + 43200, $now); // noon of that day, but never the future
                $t['adjustments'][] = ['at' => gmdate('c', $anchor), 'seconds' => $targetSeconds - $base];
                $t['updated_at'] = gmdate('c', $now);
                $tasks[$i] = $t;
                $this->write($tasks);
                return $this->serialize($t, $now);
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

    // ---- notes (per-task running log) -------------------------------------

    /**
     * Append a timestamped note to a task — a running log you add to over time (e.g. "backend bug
     * found, fixing then re-testing the original flow"), reviewable later without spawning subtasks.
     */
    public function addNote(string $id, string $text): array
    {
        $lock = $this->acquireLock();
        $text = trim($text);
        if ($text === '') {
            throw new \InvalidArgumentException('A note cannot be empty.');
        }
        $text = mb_substr($text, 0, 5000); // sane cap
        $now = time();
        $tasks = $this->raw();
        foreach ($tasks as $i => $t) {
            if (($t['id'] ?? null) === $id) {
                $t['notes'][] = ['id' => 'n-' . bin2hex(random_bytes(6)), 'at' => gmdate('c', $now), 'text' => $text];
                $t['updated_at'] = gmdate('c', $now);
                $tasks[$i] = $t;
                $this->write($tasks);
                return $this->serialize($t, $now);
            }
        }
        throw new \RuntimeException('Unknown task: ' . $id);
    }

    public function deleteNote(string $id, string $noteId): array
    {
        $lock = $this->acquireLock();
        $tasks = $this->raw();
        foreach ($tasks as $i => $t) {
            if (($t['id'] ?? null) === $id) {
                $t['notes'] = array_values(array_filter(
                    $t['notes'] ?? [],
                    static fn ($n): bool => ($n['id'] ?? null) !== $noteId
                ));
                $t['updated_at'] = gmdate('c');
                $tasks[$i] = $t;
                $this->write($tasks);
                return $this->serialize($t, time());
            }
        }
        throw new \RuntimeException('Unknown task: ' . $id);
    }

    // ---- weekly summary ---------------------------------------------------

    /**
     * Aggregate work for the week (Mon 00:00 .. next Mon 00:00, local time) containing $refTs,
     * broken down DAY -> PROJECT -> TASK. Each task's per-day seconds come from workedInWindow(),
     * so the numbers include BOTH timer sessions AND dated manual time (the "set this day's time"
     * adjustments) — not just the auto-recorded timer. The grand total is the sum across all days.
     *
     * (The legacy undated `worked_adjust` lifetime correction has no date, so it can't be attributed
     * to a day/week and is intentionally excluded here — same as the per-day breakdown elsewhere.)
     *
     * @return array<string,mixed>
     */
    public function weekSummary(int $refTs, ?int $now = null): array
    {
        $now ??= time();
        $dow = (int) date('N', $refTs);              // 1=Mon..7=Sun
        $weekStartTs = strtotime('today', $refTs) - ($dow - 1) * 86400;
        // Pin to local midnight, then walk 7 DST-aware day boundaries (a day may be 23/24/25h).
        $weekStart = strtotime(date('Y-m-d', $weekStartTs) . ' 00:00:00') ?: $weekStartTs;
        $dayBounds = [];
        $cur = $weekStart;
        for ($i = 0; $i < 7; $i++) {
            $d = date('Y-m-d', $cur);
            $next = strtotime($d . ' 00:00:00 +1 day');
            $end = ($next === false || $next <= $cur) ? $cur + 86400 : $next;
            $dayBounds[] = ['date' => $d, 'start' => $cur, 'end' => $end];
            $cur = $end;
        }
        $weekEnd = $cur; // next Monday 00:00 (DST-aware)

        $raw = $this->raw();
        $sortGroups = static function (array $a, array $b): int { // "Other" last, else by seconds desc
            if (($a['project'] ?? '') === 'Other' && ($b['project'] ?? '') !== 'Other') {
                return 1;
            }
            if (($b['project'] ?? '') === 'Other' && ($a['project'] ?? '') !== 'Other') {
                return -1;
            }
            return $b['seconds'] <=> $a['seconds'];
        };

        $days = [];
        $projectTotals = [];
        $total = 0;
        foreach ($dayBounds as $db) {
            $projects = []; // project key => ['project','seconds','tasks'[]]
            $daySecs = 0;
            foreach ($raw as $t) {
                $secs = $this->workedInWindow($t, $db['start'], $db['end'], $now); // sessions + running + dated manual
                if ($secs <= 0) {
                    continue;
                }
                $proj = trim((string) ($t['project'] ?? ''));
                $key = $proj !== '' ? $proj : 'Other';
                if (!isset($projects[$key])) {
                    $projects[$key] = ['project' => $key, 'seconds' => 0, 'tasks' => []];
                }
                $projects[$key]['seconds'] += $secs;
                $projects[$key]['tasks'][] = [
                    'id' => (string) $t['id'],
                    'title' => (string) $t['title'],
                    'status' => (string) ($t['status'] ?? ''),
                    'seconds' => $secs,
                ];
                $daySecs += $secs;
                $projectTotals[$key] = ($projectTotals[$key] ?? 0) + $secs;
                $total += $secs;
            }
            if ($daySecs <= 0) {
                continue; // skip days with no work
            }
            $projList = array_values($projects);
            usort($projList, $sortGroups);
            foreach ($projList as &$p) {
                usort($p['tasks'], static fn ($a, $b) => $b['seconds'] <=> $a['seconds']);
            }
            unset($p);
            $days[] = [
                'date' => $db['date'],
                'label' => date('D, M j', $db['start']),
                'seconds' => $daySecs,
                'projects' => $projList,
            ];
        }

        $projectTotalsList = [];
        foreach ($projectTotals as $name => $secs) {
            $projectTotalsList[] = ['project' => $name, 'seconds' => $secs];
        }
        usort($projectTotalsList, $sortGroups);

        // Tasks finished (-> done) within the week, newest first.
        $completed = [];
        foreach ($raw as $t) {
            foreach (($t['history'] ?? []) as $h) {
                if (($h['status'] ?? '') === 'done') {
                    $at = strtotime((string) ($h['at'] ?? ''));
                    if ($at !== false && $at >= $weekStart && $at < $weekEnd) {
                        $completed[] = [
                            'id' => (string) $t['id'],
                            'title' => (string) $t['title'],
                            'project' => (string) ($t['project'] ?? ''),
                            'at' => (string) $h['at'],
                        ];
                    }
                }
            }
        }
        usort($completed, static fn ($a, $b) => strcmp($b['at'], $a['at']));

        return [
            'week_start' => date('c', $weekStart),
            'week_end' => date('c', $weekEnd),
            'week_label' => date('M j', $weekStart) . ' – ' . date('M j, Y', $dayBounds[6]['start']),
            'total_seconds' => $total,
            'project_totals' => $projectTotalsList,
            'days' => $days,
            'completed' => $completed,
        ];
    }

    /**
     * Tasks that transitioned INTO 'done' at or after $sinceTs (e.g. local midnight today, for a
     * daily check-out). One entry per such task, most-recently-completed first, carrying the task's
     * TODAY worked seconds (a multi-day task reports only today's hours here; the board still shows
     * the lifetime total).
     *
     * @return array<int,array{id:string,title:string,project:string,at:string,worked_seconds:int}>
     */
    public function completedSince(int $sinceTs, ?int $now = null): array
    {
        $now ??= time();
        $out = [];
        foreach ($this->raw() as $t) {
            $doneAt = false;
            foreach (($t['history'] ?? []) as $h) {
                if (($h['status'] ?? '') === 'done') {
                    $at = strtotime((string) ($h['at'] ?? ''));
                    if ($at !== false && $at >= $sinceTs && ($doneAt === false || $at > $doneAt)) {
                        $doneAt = $at; // keep the latest "-> done" transition inside the window
                    }
                }
            }
            if ($doneAt === false) {
                continue;
            }
            // The check-out reports each finished task's TODAY hours (a task can span several days).
            $out[] = [
                'id' => (string) $t['id'],
                'title' => (string) $t['title'],
                'project' => (string) ($t['project'] ?? ''),
                'at' => gmdate('c', $doneAt),
                'worked_seconds' => $this->workedTodaySeconds($t, $now),
            ];
        }
        usort($out, fn ($a, $b) => strcmp($b['at'], $a['at']));
        return $out;
    }

    // ---- helpers ----------------------------------------------------------

    private function bankTimer(array $t, int $now): array
    {
        if (empty($t['timer_started'])) {
            return $t;
        }
        $start = strtotime((string) $t['timer_started']);
        $raw = $start !== false ? max(0, $now - $start) : 0;
        // The user logs in 15-minute units ("a small task is at least 15 min"), so a worked block is
        // billed UP to the next quarter-hour. A near-instant start→stop is an accident — bank nothing.
        if ($raw < self::MIN_SESSION) {
            $t['timer_started'] = null;
            $t['updated_at'] = gmdate('c', $now);
            return $t;
        }
        $billed = (int) (ceil($raw / self::QUARTER) * self::QUARTER);
        // Record the BILLED end (= start + billed, so it may sit a few minutes ahead of "now"). The
        // window/day functions trust a closed session's own end, so 'seconds', 'end' and every
        // breakdown all agree on the rounded figure.
        $t['sessions'][] = [
            'start' => $t['timer_started'],
            'end' => gmdate('c', ($start !== false ? $start : $now) + $billed),
            'seconds' => $billed,
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

    /**
     * Total (lifetime) worked seconds: banked sessions + the live running portion + dated
     * adjustments[]. This is the single source of truth for "Worked total", and by construction it
     * equals the sum of the per-day breakdown — both ignore the legacy undated worked_adjust, so the
     * total always reconciles with the "Hours per day" rows the user actually sees and edits.
     */
    private function workedSeconds(array $t, int $now): int
    {
        $worked = 0;
        foreach (($t['sessions'] ?? []) as $s) {
            $worked += (int) ($s['seconds'] ?? 0);
        }
        if (!empty($t['timer_started'])) {
            $start = strtotime((string) $t['timer_started']);
            if ($start !== false) {
                $worked += max(0, $now - $start);
            }
        }
        foreach (($t['adjustments'] ?? []) as $a) {
            $worked += (int) ($a['seconds'] ?? 0);
        }
        return max(0, $worked);
    }

    /**
     * Worked seconds inside an arbitrary [start, end) window: each session's overlap, the running
     * timer's overlap (capped at "now"), plus dated adjustments[] whose timestamp falls in the
     * window. A banked (closed) session's own end is authoritative — it carries the rounded 15-min
     * figure and may sit slightly ahead of "now", so it is NOT re-clamped to now (only the live
     * running timer is). The undated legacy worked_adjust is never counted here.
     */
    private function workedInWindow(array $t, int $start, int $end, int $now): int
    {
        $secs = 0;
        foreach (($t['sessions'] ?? []) as $s) {
            $ss = isset($s['start']) ? strtotime((string) $s['start']) : false;
            $se = isset($s['end']) && $s['end'] ? strtotime((string) $s['end']) : false;
            if ($ss === false) {
                continue;
            }
            if ($se === false) {
                $se = $now;
            }
            $secs += max(0, min($se, $end) - max($ss, $start));
        }
        if (!empty($t['timer_started'])) {
            $ts = strtotime((string) $t['timer_started']);
            if ($ts !== false) {
                $secs += max(0, min($now, $end) - max($ts, $start));
            }
        }
        foreach (($t['adjustments'] ?? []) as $a) {
            $at = strtotime((string) ($a['at'] ?? ''));
            if ($at !== false && $at >= $start && $at < $end) {
                $secs += (int) ($a['seconds'] ?? 0);
            }
        }
        return max(0, $secs);
    }

    /** Worked seconds today (local-midnight .. now) — what the check-out + select-compare use. */
    private function workedTodaySeconds(array $t, int $now): int
    {
        $midnight = strtotime('today');
        return $this->workedInWindow($t, $midnight, $midnight + 86400, $now);
    }

    /**
     * Worked seconds bucketed by local day: sessions (split across midnight, honoring each closed
     * session's own rounded end), the running timer, and dated adjustments. The rows sum exactly to
     * the lifetime "Worked total"; the legacy undated worked_adjust is ignored everywhere.
     *
     * @return array<int,array{date:string,seconds:int}> most-recent first
     */
    private function dailyBreakdown(array $t, int $now): array
    {
        $byDay = [];
        $add = static function (int $from, int $to) use (&$byDay): void {
            $cur = $from;
            while ($cur < $to) {
                $d = date('Y-m-d', $cur);
                // DST-aware next local midnight (the day may be 23/24/25h) — '+1 day' is always > $cur,
                // so the loop terminates even on the fall-back day; a fixed +86400 would spin forever.
                $next = strtotime($d . ' 00:00:00 +1 day');
                $segEnd = ($next === false || $next <= $cur) ? $to : min($to, $next);
                $byDay[$d] = ($byDay[$d] ?? 0) + max(0, $segEnd - $cur);
                $cur = $segEnd;
            }
        };
        foreach (($t['sessions'] ?? []) as $s) {
            $ss = isset($s['start']) ? strtotime((string) $s['start']) : false;
            $se = isset($s['end']) && $s['end'] ? strtotime((string) $s['end']) : false;
            if ($ss === false) {
                continue;
            }
            $se = $se === false ? $now : $se; // a closed session's recorded (rounded) end is authoritative
            if ($se > $ss) {
                $add($ss, $se);
            }
        }
        if (!empty($t['timer_started'])) {
            $ts = strtotime((string) $t['timer_started']);
            if ($ts !== false && $now > $ts) {
                $add($ts, $now);
            }
        }
        foreach (($t['adjustments'] ?? []) as $a) {
            $at = strtotime((string) ($a['at'] ?? ''));
            if ($at !== false) {
                $d = date('Y-m-d', $at);
                $byDay[$d] = ($byDay[$d] ?? 0) + (int) ($a['seconds'] ?? 0);
            }
        }
        // Keep values SIGNED so the per-day rows always sum to workedSeconds() (the read-only
        // "Worked total"); a dated correction may be negative.
        $out = [];
        foreach ($byDay as $d => $secs) {
            if ((int) $secs !== 0) {
                $out[] = ['date' => (string) $d, 'seconds' => (int) $secs];
            }
        }
        usort($out, static fn ($a, $b) => strcmp($b['date'], $a['date']));
        return $out;
    }

    private function serialize(array $t, int $now): array
    {
        $running = !empty($t['timer_started']);
        $statusSince = strtotime((string) ($t['status_since'] ?? $t['created_at'] ?? '')) ?: $now;

        return [
            'id' => $t['id'],
            'title' => $t['title'],
            'project' => (string) ($t['project'] ?? ''),
            'description' => $t['description'] ?? '',
            'status' => $t['status'],
            'created_at' => $t['created_at'] ?? null,
            'updated_at' => $t['updated_at'] ?? null,
            'status_since' => $t['status_since'] ?? null,
            'status_seconds' => max(0, $now - $statusSince),
            'status_totals' => $this->statusTotals($t, $now),
            'history' => array_values($t['history'] ?? []), // status transitions, for the History log

            'worked_seconds' => $this->workedSeconds($t, $now),
            'today_seconds' => $this->workedTodaySeconds($t, $now),
            'done_today' => $this->doneToday($t),
            'days' => $this->dailyBreakdown($t, $now),
            'running' => $running,
            'timer_started' => $t['timer_started'] ?? null,
            'attachments' => array_values($t['attachments'] ?? []),
            'notes' => array_values($t['notes'] ?? []),
        ];
    }

    /**
     * True if this task transitioned INTO 'done' at or after local midnight today. Mirrors the
     * window used by completedSince()/buildCheckoutMessage() so the board's "Today" view and the
     * posted check-out digest agree on what counts as finished today.
     */
    private function doneToday(array $t): bool
    {
        $since = strtotime('today') ?: 0;
        foreach (($t['history'] ?? []) as $h) {
            if (($h['status'] ?? '') === 'done') {
                $at = strtotime((string) ($h['at'] ?? ''));
                if ($at !== false && $at >= $since) {
                    return true;
                }
            }
        }
        return false;
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
