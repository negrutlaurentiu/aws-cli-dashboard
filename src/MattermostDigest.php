<?php

declare(strict_types=1);

namespace AwsDash;

/**
 * Builds the Mattermost check-in / check-out digest messages from the task board. Extracted from the
 * web front controller so both the `/api/mattermost/checkin|checkout` routes AND the @Claude agent's
 * `checkin`/`checkout` MCP tools render the exact same message. Pure (takes a Tasks store, returns a
 * Markdown string) — no I/O of its own.
 */
final class MattermostDigest
{
    /**
     * Check-in: In Progress, In Review, and Pending as separate sections (no worked-time — that's
     * check-out). Order mirrors the board columns (active work → awaiting review → queued).
     */
    public static function checkin(Tasks $tasks): string
    {
        $all = $tasks->all();
        $byStatus = static fn (string $s) => array_values(array_filter($all, static fn ($t) => ($t['status'] ?? '') === $s));

        $lines = ['**:inbox_tray: Check-in — ' . date('D, M j') . '**', ''];
        foreach (['in_progress' => 'In Progress', 'review' => 'In Review', 'pending' => 'Pending'] as $status => $heading) {
            $lines[] = '**' . $heading . '**';
            foreach (self::taskLines($byStatus($status)) as $l) {
                $lines[] = $l;
            }
            $lines[] = '';
        }
        array_pop($lines); // drop the trailing blank after the last section

        return implode("\n", $lines);
    }

    /**
     * Check-out: "Done today" (tasks moved to Done since local midnight) followed by every OTHER task
     * worked on today — bucketed by board column (In progress / In review / Pending / …), grouped by
     * project within each, with each task's TODAY worked-time and a grand total.
     *
     * The grand total reconciles with the board's "Today worked" header, which counts work on a task in
     * ANY column. The previous version only listed in_progress tasks, so time logged on Review (or
     * pending) tasks vanished from the digest while still showing on the board. When $includeHours is
     * false, per-task times and the total are omitted.
     */
    public static function checkout(Tasks $tasks, bool $includeHours, int $dayOffset = 0): string
    {
        $now = time();
        // Resolve the target day's LOCAL window [midnight, next midnight). strtotime keeps it DST-safe
        // ($dayOffset 0 = today, -1 = yesterday — for when the operator forgot to post yesterday).
        $base = strtotime('today');
        $base = $base !== false ? $base : $now;
        $targetDate = date('Y-m-d', strtotime($dayOffset . ' day', $base) ?: $base);
        $dayStart = strtotime($targetDate . ' 00:00:00');
        $dayStart = $dayStart !== false ? $dayStart : ($base + $dayOffset * 86400);
        $dayEnd = strtotime($targetDate . ' 00:00:00 +1 day');
        $dayEnd = $dayEnd !== false ? $dayEnd : ($dayStart + 86400);
        $isToday = $dayOffset === 0;
        $dayWord = $isToday ? 'today' : ($dayOffset === -1 ? 'yesterday' : date('D, M j', $dayStart));

        $done = $tasks->completedBetween($dayStart, $dayEnd, $now);
        // Tasks already under "Done" must not be re-counted in the open-work buckets below — a task
        // bounced done→in_progress on the same day would otherwise appear (and total) twice.
        $doneIds = array_fill_keys(array_map(static fn ($t) => (string) $t['id'], $done), true);

        // Everything ELSE worked on the target day, regardless of column. Each serialized task carries a
        // per-local-day breakdown ('days'); we read the target day's seconds from it (clamped to >= 0 to
        // match the board's "Today worked" semantics; for today this already includes the live timer).
        $open = [];
        foreach ($tasks->all($now) as $t) {
            if (isset($doneIds[(string) ($t['id'] ?? '')])) {
                continue;
            }
            $secs = 0;
            foreach (($t['days'] ?? []) as $d) {
                if (($d['date'] ?? '') === $targetDate) {
                    $secs = max(0, (int) ($d['seconds'] ?? 0));
                    break;
                }
            }
            if ($secs > 0 || ($isToday && !empty($t['running']))) {
                $t['_day_seconds'] = $secs;
                $open[] = $t;
            }
        }
        // Most-worked first, so within each column's project groups the bigger items lead.
        usort($open, static fn ($a, $b) => (int) ($b['_day_seconds'] ?? 0) <=> (int) ($a['_day_seconds'] ?? 0));

        $lines = ['**:white_check_mark: Check-out — ' . date('D, M j', $dayStart) . '**', ''];
        $grandTotal = 0;

        // ---- Done ---- (completedBetween() carries each task's worked seconds FOR THAT DAY)
        $lines[] = '**Done ' . $dayWord . '**';
        if (!$done) {
            $lines[] = '_No tasks completed ' . $dayWord . '._';
        } else {
            [$sectionLines, $sectionTotal] = self::checkoutSection($done, 'worked_seconds', $includeHours);
            array_push($lines, ...$sectionLines);
            $grandTotal += $sectionTotal;
        }

        // ---- Still-open work, one section per board column (only columns with work appear) ----
        // 'done' here = a task finished on an EARLIER day but logged more time on the target day (rare)
        // — kept so the grand total still reconciles with the day's worked time.
        $columns = [
            'in_progress' => 'In progress',
            'review' => 'In review',
            'pending' => 'Pending',
            'done' => 'Done earlier (logged ' . $dayWord . ')',
            'archived' => 'Archived',
        ];
        foreach ($columns as $status => $heading) {
            $bucket = array_values(array_filter($open, static fn ($t) => ($t['status'] ?? '') === $status));
            if (!$bucket) {
                continue;
            }
            $lines[] = '';
            $lines[] = '**' . $heading . '**';
            [$sectionLines, $sectionTotal] = self::checkoutSection($bucket, '_day_seconds', $includeHours);
            array_push($lines, ...$sectionLines);
            $grandTotal += $sectionTotal;
        }

        // Catch-all: any worked task whose status isn't one of the known columns (a corrupt, foreign,
        // or future status the column map forgot) still gets reported and totalled — so the grand total
        // ALWAYS equals the day's worked time, which is status-agnostic. Without this, such a task's
        // time would silently vanish — the exact bug class this section exists to kill.
        $other = array_values(array_filter($open, static fn ($t) => !isset($columns[(string) ($t['status'] ?? '')])));
        if ($other) {
            $lines[] = '';
            $lines[] = '**Other**';
            [$sectionLines, $sectionTotal] = self::checkoutSection($other, '_day_seconds', $includeHours);
            array_push($lines, ...$sectionLines);
            $grandTotal += $sectionTotal;
        }

        if ($includeHours) {
            $lines[] = '';
            $lines[] = '**Total worked ' . $dayWord . ': ' . self::fmtDur($grandTotal) . '**';
        }

        return implode("\n", $lines);
    }

    /**
     * Compose the read-only reply for a COLLEAGUE'S auto-responder command. This is the ONLY surface a
     * colleague can pull, so it is deliberately limited to task TITLES and status — never worked hours,
     * never screen-time/website data, never descriptions/notes/attachments. A footer marks it automated.
     *
     *   'active' → In Progress + In Review     'plate' → + Pending     'help' → the command list
     */
    public static function colleagueStatus(Tasks $tasks, string $command): string
    {
        if ($command === 'help') {
            return implode("\n", [
                ':robot_face: **Auto-status** — mention me (or DM me) with one of:',
                '• `status` — what I’m working on right now',
                '• `tasks` — my active + queued list',
                '• `help` — this message',
                '_Automated reply from my task board (titles only)._',
            ]);
        }

        $all = $tasks->all();
        $byStatus = static fn (string $s) => array_values(array_filter($all, static fn ($t) => ($t['status'] ?? '') === $s));
        $sections = $command === 'plate'
            ? ['in_progress' => 'In progress', 'review' => 'In review', 'pending' => 'Pending']
            : ['in_progress' => 'In progress', 'review' => 'In review'];

        $body = [];
        $any = false;
        foreach ($sections as $status => $heading) {
            $items = $byStatus($status);
            if (!$items) {
                continue;
            }
            $any = true;
            $body[] = '**' . $heading . '**';
            foreach (self::taskLines($items) as $l) {
                $body[] = $l;
            }
        }
        if (!$any) {
            return "Nothing on my board right now. :tada:\n_Automated reply from my task board._";
        }
        $header = $command === 'plate' ? "Here’s my plate right now:" : "Here’s what I’m working on right now:";
        return $header . "\n" . implode("\n", $body) . "\n_Automated reply from my task board (titles only)._";
    }

    /**
     * Build the postable WEEKLY SUMMARY digest from a Tasks::weekSummary() result: each day's logged
     * time grouped by project, flagged ✅ (met the daily target) or ⚠️ (short, with how much), and a
     * grand total of logged-vs-expected. Made to help a teammate reconcile against Redmine — which days
     * are fully logged and which are missing hours.
     *
     * @param array<string,mixed> $summary
     */
    public static function weekly(array $summary): string
    {
        $days = is_array($summary['days'] ?? null) ? $summary['days'] : [];
        $total = (int) ($summary['total_seconds'] ?? 0);
        $expected = (int) ($summary['expected_seconds'] ?? 0);
        $short = (int) ($summary['short_seconds'] ?? 0);

        $lines = ['**:date: Weekly summary — ' . (string) ($summary['week_label'] ?? '') . '**', ''];
        if (!$days) {
            $lines[] = '_No tracked work this week._';
        }
        foreach ($days as $d) {
            $secs = (int) ($d['seconds'] ?? 0);
            $working = (bool) ($d['working_day'] ?? true);
            if (!$working) {
                $flag = ' _(weekend)_';
            } elseif (!empty($d['in_progress'])) {
                $flag = ' _(today, in progress)_'; // not yet over — never flagged short
            } elseif (!empty($d['complete'])) {
                $flag = ' :white_check_mark:';
            } elseif ($secs <= 0) {
                $flag = ' :warning: no time logged';
            } else {
                $flag = ' :warning: ' . self::fmtDur((int) ($d['short_seconds'] ?? 0)) . ' short';
            }
            $lines[] = '**' . (string) ($d['label'] ?? '') . '** — ' . self::fmtDur($secs) . $flag;

            $projs = is_array($d['projects'] ?? null) ? $d['projects'] : [];
            if ($projs) {
                $parts = [];
                foreach ($projs as $p) {
                    $name = trim((string) ($p['project'] ?? ''));
                    $parts[] = '`' . self::mmText($name !== '' ? $name : 'Other') . '` ' . self::fmtDur((int) ($p['seconds'] ?? 0));
                }
                $lines[] = '    ' . implode(' · ', $parts);
            }
        }

        // Count under-target days. Note worked-total and per-day shortfall are reported SEPARATELY: a
        // day OVER target doesn't offset a short day, so "worked + short" need not equal "expected".
        $shortDays = 0;
        foreach ($days as $d) {
            if (!empty($d['working_day']) && (int) ($d['short_seconds'] ?? 0) > 0) {
                $shortDays++;
            }
        }
        $lines[] = '';
        $lines[] = '**Worked ' . self::fmtDur($total) . '**' . ($expected > 0 ? ' · target ' . self::fmtDur($expected) : '');
        if ($expected > 0) {
            $lines[] = $short <= 0
                ? ':white_check_mark: Every working day met its target.'
                : ':warning: ' . $shortDays . ' day' . ($shortDays === 1 ? '' : 's') . ' under target — ' . self::fmtDur($short) . ' missing.';
        }
        return implode("\n", $lines);
    }

    /**
     * Render one check-out section: tasks grouped by project (no-project tasks under "Other", last),
     * each a bullet carrying its TODAY worked time (from $secondsKey) when $includeHours is on.
     *
     * @param array<int,array<string,mixed>> $tasks
     * @return array{0:array<int,string>,1:int} [lines, total seconds]
     */
    private static function checkoutSection(array $tasks, string $secondsKey, bool $includeHours): array
    {
        $groups = [];
        foreach ($tasks as $t) {
            $proj = trim((string) ($t['project'] ?? ''));
            $groups[$proj !== '' ? $proj : 'Other'][] = $t;
        }
        uksort($groups, static function (string $a, string $b): int {
            if ($a === 'Other') {
                return 1;
            }
            if ($b === 'Other') {
                return -1;
            }
            return strcasecmp($a, $b);
        });

        $lines = [];
        $total = 0;
        foreach ($groups as $proj => $items) {
            $lines[] = '**' . self::mmText((string) $proj) . '**';
            foreach ($items as $t) {
                $secs = (int) ($t[$secondsKey] ?? 0);
                $total += $secs;
                $line = '- ' . self::mmText((string) ($t['title'] ?? ''));
                if ($includeHours && $secs > 0) {
                    $line .= ' _(:hourglass_flowing_sand: ' . self::fmtDur($secs) . ')_';
                }
                $lines[] = $line;
            }
            $lines[] = ''; // blank between project groups
        }
        array_pop($lines); // drop the trailing blank — caller owns spacing between sections

        return [$lines, $total];
    }

    /**
     * @param array<int,array<string,mixed>> $tasks
     * @return array<int,string>
     */
    private static function taskLines(array $tasks): array
    {
        if (!$tasks) {
            return ['_none_'];
        }
        $out = [];
        foreach ($tasks as $t) {
            $proj = trim((string) ($t['project'] ?? ''));
            $prefix = $proj !== '' ? '`' . self::mmText($proj) . '` ' : '';
            $out[] = '- ' . $prefix . self::mmText((string) ($t['title'] ?? ''));
        }
        return $out;
    }

    /**
     * Neutralize a title for a Markdown channel message: collapse whitespace to one line and defuse
     * channel-wide mentions (@channel/@here/@all) with a zero-width space so a title can't mass-ping.
     */
    public static function mmText(string $s): string
    {
        return self::defuseMentions((string) preg_replace('/\s+/u', ' ', trim($s)));
    }

    /**
     * Defuse channel-wide mentions (@channel/@here/@all) with a zero-width space so a message can't
     * mass-ping — WITHOUT collapsing whitespace (preserves newlines, unlike mmText). Used for the
     * agent's free-text reply, which is otherwise model-controlled (and could be prompt-injected).
     */
    public static function defuseMentions(string $s): string
    {
        $zwsp = "\xE2\x80\x8B"; // U+200B — invisible, but breaks the @mention token
        return preg_replace('/@(channel|here|all)\b/i', '@' . $zwsp . '$1', $s) ?? $s;
    }

    public static function fmtDur(int $s): string
    {
        $s = max(0, $s);
        $h = intdiv($s, 3600);
        $m = intdiv($s % 3600, 60);
        if ($h > 0) {
            return $h . 'h ' . $m . 'm';
        }
        if ($m > 0) {
            return $m . 'm';
        }
        return $s . 's';
    }
}
