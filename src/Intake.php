<?php

declare(strict_types=1);

namespace AwsDash;

/**
 * Pure, dependency-free parsing for the @Claude task intake (bin/mm-listen). Kept separate from the
 * listener loop so it has no I/O and can be unit-tested directly. Turns a Mattermost message into a
 * task:
 *
 *   "@Claude proj:S3 !progress fix pagination bug\nreproduces on >1000 objects"
 *      → title "fix pagination bug", project "S3", status in_progress, description "reproduces on…"
 *
 * The convention is intentionally tiny and all-optional: bare text after the tag just becomes the
 * title. Inline directives on the FIRST line — proj:foo / proj:"two words" / #foo set the project;
 * !progress|!review|!done|!pending set the status (unknown !x is left in the title untouched). The
 * title is always sanitized (control chars stripped, whitespace collapsed, length capped) because it
 * originates from chat — untrusted text, even though the board renders it via textContent.
 */
final class Intake
{
    /** !alias → task status. Anything else after "!" is left alone (not a status directive). */
    private const STATUS_ALIASES = [
        'pending' => 'pending', 'todo' => 'pending',
        'progress' => 'in_progress', 'doing' => 'in_progress', 'wip' => 'in_progress',
        'review' => 'review',
        'done' => 'done', 'complete' => 'done',
    ];

    private const TITLE_MAX = 200;

    /**
     * If $message (after leading whitespace) begins with $tag as a whole token — case-insensitive,
     * followed by whitespace or end-of-string — return the text after it; otherwise null. The
     * whole-token rule stops "support@claude.com" or "x@claudexyz" from matching "@Claude".
     */
    public static function matchTag(string $message, string $tag): ?string
    {
        $tagLen = strlen($tag);
        if ($tagLen === 0) {
            return null;
        }
        $lead = ltrim($message);
        if (strncasecmp($lead, $tag, $tagLen) !== 0) {
            return null;
        }
        if (strlen($lead) > $tagLen && !ctype_space($lead[$tagLen])) {
            return null; // tag is a prefix of a longer token (e.g. an email) — not a trigger
        }
        return ltrim(substr($lead, $tagLen));
    }

    /**
     * Filter a Mattermost WebSocket event down to a trigger, WITHOUT parsing it. Returns the source
     * post id, the thread root id (for pulling referenced context), and the raw text after the tag —
     * or null to ignore the event. Pure (no I/O), so the matching rules are unit-testable; the daemon
     * decides whether to parse heuristically or hand the text to Claude.
     *
     * Acts only on `posted` events for the operator's OWN ($myUserId), non-system messages that match
     * the trigger tag (and the optional channel slug). Mattermost double-encodes the post: `data.post`
     * is a JSON STRING that must be decoded again.
     *
     * @param array<string,mixed> $evt the decoded WebSocket event
     * @return array{post_id:string,root_id:string,channel_id:string,rest:string,files:list<array{id:string,name:string}>}|null
     */
    public static function matchPostedEvent(array $evt, string $myUserId, string $tag, string $channel): ?array
    {
        if (($evt['event'] ?? '') !== 'posted') {
            return null;
        }
        $data = is_array($evt['data'] ?? null) ? $evt['data'] : [];
        $postRaw = $data['post'] ?? null;
        if (!is_string($postRaw)) {
            return null;
        }
        $post = json_decode($postRaw, true);
        if (!is_array($post)) {
            return null;
        }
        if ((string) ($post['user_id'] ?? '') !== $myUserId) {
            return null; // only the operator's own messages create tasks
        }
        if ((string) ($post['type'] ?? '') !== '') {
            return null; // skip system messages (joins, header changes, …)
        }
        if ($channel !== '' && (string) ($data['channel_name'] ?? '') !== $channel) {
            return null; // restricted to one channel slug
        }
        $rest = self::matchTag((string) ($post['message'] ?? ''), $tag);
        if ($rest === null) {
            return null;
        }
        return [
            'post_id' => (string) ($post['id'] ?? ''),
            'root_id' => (string) ($post['root_id'] ?? ''),
            'channel_id' => (string) ($post['channel_id'] ?? ''),
            'rest' => $rest,
            'files' => self::filesOf($post),
        ];
    }

    /**
     * Extract {id,name} for each file attached to a post (from metadata.files if present, else the
     * bare file_ids).
     *
     * @param array<string,mixed> $post
     * @return list<array{id:string,name:string}>
     */
    public static function filesOf(array $post): array
    {
        $out = [];
        $meta = $post['metadata']['files'] ?? null;
        if (is_array($meta)) {
            foreach ($meta as $f) {
                if (is_array($f) && !empty($f['id'])) {
                    $out[] = ['id' => (string) $f['id'], 'name' => (string) ($f['name'] ?? '')];
                }
            }
        }
        if ($out === [] && is_array($post['file_ids'] ?? null)) {
            foreach ($post['file_ids'] as $fid) {
                if ($fid !== '') {
                    $out[] = ['id' => (string) $fid, 'name' => ''];
                }
            }
        }
        return $out;
    }

    /**
     * Recognised colleague auto-responder COMMANDS (keyword → command). A colleague mentions the
     * operator (or DMs them) with one of these as the FIRST word and gets a read-only status reply.
     * Intentionally tiny, leading-token-only, and deterministic (no LLM, no free-text guessing), so a
     * keyword buried in ordinary chat — "are you busy?", "did you finish the todo list?" — never fires.
     */
    private const AUTO_COMMANDS = [
        'active' => ['status', 'working', 'busy', 'wip'],
        'plate' => ['tasks', 'todo', 'backlog', 'workload'],
        'help' => ['help', 'commands'],
        // Weekly HOURS report — allowlist-gated by the caller (shares worked hours, not just titles).
        'week' => ['week', 'weekly', 'thisweek'],
        'lastweek' => ['lastweek'],
    ];

    /** Two-word leading phrases → command (so "last week" / "this week" read naturally). */
    private const AUTO_PHRASES = [
        'last week' => 'lastweek',
        'prev week' => 'lastweek',
        'previous week' => 'lastweek',
        'past week' => 'lastweek',
        'this week' => 'week',
    ];

    /**
     * Map $text to a command iff its FIRST meaningful token(s) form a command — 'active' | 'plate' |
     * 'help' | 'week' | 'lastweek', else null. Leading-only so it reads as an intentional command
     * ("status", "tasks please", "last week") and not "a sentence that happens to contain the word".
     */
    public static function autoCommand(string $text): ?string
    {
        $toks = array_values(array_filter(
            preg_split('/[^a-z0-9]+/i', strtolower(trim($text))) ?: [],
            static fn ($t) => $t !== ''
        ));
        if (!$toks) {
            return null;
        }
        if (count($toks) >= 2 && isset(self::AUTO_PHRASES[$toks[0] . ' ' . $toks[1]])) {
            return self::AUTO_PHRASES[$toks[0] . ' ' . $toks[1]];
        }
        $map = [];
        foreach (self::AUTO_COMMANDS as $cmd => $words) {
            foreach ($words as $w) {
                $map[$w] = $cmd;
            }
        }
        return $map[$toks[0]] ?? null; // the FIRST token decides — command or nothing
    }

    /**
     * Match a `posted` event as a COLLEAGUE'S status request for the read-only auto-responder. Returns
     * the command + thread coordinates, or null to ignore. Pure (no I/O), so the (security-relevant)
     * gating is unit-testable.
     *
     * Fires ONLY for: another user's ($userId !== $myUserId) non-system, non-bot/webhook message that is
     * EITHER a 1:1 DM OR explicitly @-mentions the operator by username (never via @channel/@here/@all),
     * AND contains a recognised command word. Everything else is ignored — so normal chatter, broadcasts,
     * and the operator's own posts never trigger a reply.
     *
     * @param array<string,mixed> $evt
     * @return array{command:string,channel_id:string,root_id:string,post_id:string,user_id:string,sender:string}|null
     */
    public static function matchColleagueQuery(array $evt, string $myUserId, string $myUsername): ?array
    {
        if (($evt['event'] ?? '') !== 'posted') {
            return null;
        }
        $data = is_array($evt['data'] ?? null) ? $evt['data'] : [];
        $postRaw = $data['post'] ?? null;
        if (!is_string($postRaw)) {
            return null;
        }
        $post = json_decode($postRaw, true);
        if (!is_array($post)) {
            return null;
        }
        $userId = (string) ($post['user_id'] ?? '');
        if ($userId === '' || $userId === $myUserId) {
            return null; // colleagues only — the operator's own posts go through the @Claude path
        }
        if ((string) ($post['type'] ?? '') !== '') {
            return null; // skip system messages (joins, header changes, …)
        }
        // Skip bot / webhook / integration posts so we never reply to (or loop with) another automation.
        $props = is_array($post['props'] ?? null) ? $post['props'] : [];
        if (!empty($props['from_bot']) || !empty($props['from_webhook']) || !empty($props['from_oauth_app'])) {
            return null;
        }
        $message = (string) ($post['message'] ?? '');
        if (trim($message) === '') {
            return null;
        }
        // Context gate: a 1:1 DM, OR an EXPLICIT mention of the operator — never a channel-wide
        // @channel/@here/@all broadcast. Two mention signals (either suffices):
        //   1. Mattermost's own server-computed mention list (data.mentions, a JSON-string array of
        //      user ids) — robust to any username/display rendering; gated on "not a broadcast".
        //   2. An explicit @username text match — a fallback if the event omits `mentions`.
        $isDm = (string) ($data['channel_type'] ?? '') === 'D';
        $broadcast = preg_match('/@(?:channel|here|all)\b/i', $message) === 1;
        $mentions = $data['mentions'] ?? null;
        if (is_string($mentions)) {
            $decoded = json_decode($mentions, true);
            $mentions = is_array($decoded) ? $decoded : [];
        } elseif (!is_array($mentions)) {
            $mentions = [];
        }
        $inMentionList = in_array($myUserId, array_map('strval', $mentions), true) && !$broadcast;
        $textMention = $myUsername !== ''
            && preg_match('/(?<![\w.@-])@' . preg_quote($myUsername, '/') . '(?![\w.-])/i', $message) === 1;
        if (!$isDm && !$inMentionList && !$textMention) {
            return null;
        }
        // Strip any LEADING @mentions (the operator's, plus any others before the command) so the
        // command word is the first real token — however the mention rendered in the raw text.
        $scan = (string) preg_replace('/^\s*(?:@[\w.\-]+[^\w@]*)+/', '', $message);
        $command = self::autoCommand($scan);
        if ($command === null) {
            return null;
        }
        return [
            'command' => $command,
            'channel_id' => (string) ($post['channel_id'] ?? ''),
            'root_id' => (string) ($post['root_id'] ?? ''),
            'post_id' => (string) ($post['id'] ?? ''),
            'user_id' => $userId,
            // The sender's username (Mattermost stamps it on the event) — used to gate the weekly report.
            // Normalised the same way as the stored allowlist: trimmed, @-stripped, lowercased.
            'sender' => strtolower(ltrim(trim((string) ($data['sender_name'] ?? '')), '@')),
        ];
    }

    /**
     * Match + heuristically parse an event into a task (the built-in, no-LLM path). Returns the task
     * plus the source post id, or null to ignore the event.
     *
     * @param array<string,mixed> $evt
     * @return array{post_id:string,title:string,description:string,project:string,status:string}|null
     */
    public static function fromPostedEvent(
        array $evt,
        string $myUserId,
        string $tag,
        string $channel,
        string $defaultProject = ''
    ): ?array {
        $m = self::matchPostedEvent($evt, $myUserId, $tag, $channel);
        if ($m === null) {
            return null;
        }
        $parsed = self::parse($m['rest'], $defaultProject);
        if ($parsed === null) {
            return null;
        }
        return ['post_id' => $m['post_id']] + $parsed;
    }

    /**
     * Parse the post-tag text into a task. Returns null if there's nothing usable as a title.
     *
     * @return array{title:string,description:string,project:string,status:string}|null
     */
    public static function parse(string $rest, string $defaultProject = ''): ?array
    {
        $rest = trim($rest);
        if ($rest === '') {
            return null;
        }

        $nl = strpos($rest, "\n");
        $first = $nl === false ? $rest : substr($rest, 0, $nl);
        $description = $nl === false ? '' : trim(substr($rest, $nl + 1));

        $project = '';
        $status = 'pending';

        // project: proj:"two words" | proj:word | #word  (first match wins)
        if (preg_match('/(?:^|\s)proj:"([^"]+)"/u', $first, $m)) {
            $project = trim($m[1]);
            $first = str_replace($m[0], ' ', $first);
        } elseif (preg_match('/(?:^|\s)proj:(\S+)/u', $first, $m)) {
            $project = $m[1];
            $first = str_replace($m[0], ' ', $first);
        } elseif (preg_match('/(?:^|\s)#(\S+)/u', $first, $m)) {
            $project = $m[1];
            $first = str_replace($m[0], ' ', $first);
        }

        // status: !alias (only when the alias is recognised; otherwise left in the title)
        if (preg_match('/(?:^|\s)!([a-z_]+)/i', $first, $m)) {
            $key = strtolower($m[1]);
            if (isset(self::STATUS_ALIASES[$key])) {
                $status = self::STATUS_ALIASES[$key];
                $first = str_replace($m[0], ' ', $first);
            }
        }

        $title = self::sanitizeTitle($first);
        if ($title === '') {
            $title = self::sanitizeTitle($rest); // directives ate the line — keep the raw text
        }
        if ($title === '') {
            return null;
        }

        if ($project === '' && $defaultProject !== '') {
            $project = $defaultProject;
        }

        return [
            'title' => $title,
            'description' => $description,
            'project' => $project,
            'status' => $status,
        ];
    }

    /** Strip control/format chars, collapse whitespace, trim, and cap the length. */
    public static function sanitizeTitle(string $s): string
    {
        $s = (string) preg_replace('/[\x00-\x08\x0B\x0C\x0E-\x1F\x7F]+/u', ' ', $s);
        $s = (string) preg_replace('/\s+/u', ' ', $s);
        return mb_substr(trim($s), 0, self::TITLE_MAX);
    }

    /** Like sanitizeTitle but keeps newlines (descriptions are multi-line); caps length generously. */
    public static function sanitizeDescription(string $s): string
    {
        // Strip control chars EXCEPT tab (\x09) and newline (\x0A); normalise CRLF first.
        $s = str_replace(["\r\n", "\r"], "\n", $s);
        $s = (string) preg_replace('/[\x00-\x08\x0B\x0C\x0E-\x1F\x7F]+/u', '', $s);
        return mb_substr(trim($s), 0, 4000);
    }
}
