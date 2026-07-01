<?php

declare(strict_types=1);

namespace AwsDash;

/**
 * Persists the account list (config/accounts.json) and runtime state (config/state.json).
 *
 * accounts.json holds user config and MAY contain TOTP seeds, so it is gitignored and
 * written 0600. state.json holds the CSRF app-token and the last-known session expiry per
 * account (so the dashboard can show a live countdown without re-reading the secret-bearing
 * credentials file).
 */
final class Store
{
    private string $accountsPath;
    private string $statePath;
    private string $settingsPath;
    private string $mattermostPath;
    private string $listenerStatusPath;
    private string $projectsPath;
    private string $redminePath;

    /** AWS STS get-session-token allows 900s (15 min) to 129600s (36 h) for an IAM user. */
    public const MIN_DURATION = 900;
    public const MAX_DURATION = 129600;
    public const DEFAULT_DURATION = 129600;

    /** Expected worked hours per day, used by the weekly summary's "did I log a full day?" check. */
    public const DEFAULT_TARGET_HOURS = 8.0;

    public function __construct(string $configDir)
    {
        $this->accountsPath = $configDir . '/accounts.json';
        $this->statePath = $configDir . '/state.json';
        $this->settingsPath = $configDir . '/settings.json';
        $this->mattermostPath = $configDir . '/mattermost.json';
        $this->listenerStatusPath = $configDir . '/mm-listen.status';
        $this->projectsPath = $configDir . '/projects.json';
        $this->redminePath = $configDir . '/redmine.json';
        if (!is_dir($configDir)) {
            mkdir($configDir, 0700, true);
        }
    }

    // ---- global settings --------------------------------------------------

    /** @return array{duration_seconds:int} global settings shared by every account */
    /** @return array{duration_seconds:int,daily_target_hours:float,target_weekdays_only:bool} */
    public function settings(): array
    {
        $s = $this->readJson($this->settingsPath);
        $dur = (int) ($s['duration_seconds'] ?? self::DEFAULT_DURATION);
        if ($dur < self::MIN_DURATION || $dur > self::MAX_DURATION) {
            $dur = self::DEFAULT_DURATION;
        }
        $hours = (float) ($s['daily_target_hours'] ?? self::DEFAULT_TARGET_HOURS);
        if ($hours < 0 || $hours > 24) {
            $hours = self::DEFAULT_TARGET_HOURS;
        }
        return [
            'duration_seconds' => $dur,
            'daily_target_hours' => $hours,
            'target_weekdays_only' => (bool) ($s['target_weekdays_only'] ?? true),
        ];
    }

    public function durationSeconds(): int
    {
        return $this->settings()['duration_seconds'];
    }

    /**
     * Merge-update global settings (preserves keys the caller doesn't send, so saving the token
     * lifetime never wipes the weekly-summary target, and vice-versa).
     *
     * @param array<string,mixed> $input
     * @return array{duration_seconds:int,daily_target_hours:float,target_weekdays_only:bool}
     */
    public function saveSettings(array $input): array
    {
        $cur = $this->settings();

        $dur = array_key_exists('duration_seconds', $input) ? (int) $input['duration_seconds'] : $cur['duration_seconds'];
        if ($dur < self::MIN_DURATION || $dur > self::MAX_DURATION) {
            throw new \InvalidArgumentException(
                'Token lifetime must be between ' . self::MIN_DURATION . ' and ' . self::MAX_DURATION . ' seconds.'
            );
        }
        $hours = array_key_exists('daily_target_hours', $input) ? (float) $input['daily_target_hours'] : $cur['daily_target_hours'];
        if ($hours < 0 || $hours > 24) {
            throw new \InvalidArgumentException('Daily target hours must be between 0 and 24.');
        }
        $weekdaysOnly = array_key_exists('target_weekdays_only', $input)
            ? (bool) $input['target_weekdays_only']
            : $cur['target_weekdays_only'];

        $this->writeJson($this->settingsPath, [
            'duration_seconds' => $dur,
            'daily_target_hours' => $hours,
            'target_weekdays_only' => $weekdaysOnly,
        ]);
        return $this->settings();
    }

    // ---- mattermost integration -------------------------------------------

    /**
     * Mattermost connection config (config/mattermost.json, 0600). Holds the bearer access
     * token (personal OR bot — same API), the team + channel slugs, and cached channel ids.
     * The token is a secret and is NEVER returned to the browser by the API layer.
     *
     * The `intake_*` keys drive the @Claude listener (bin/mm-listen): when enabled, it watches the
     * operator's own Mattermost messages over the WebSocket and turns those that start with the
     * trigger tag into tasks. `intake_channel` (a channel slug) optionally limits intake to one
     * channel; blank means any channel the account is in.
     *
     * `intake_llm` adds an optional Claude pass: when on, the listener interprets the message into a
     * structured task (title/project/desc/status) using the operator's LOCAL `claude` CLI (the
     * subscription they're logged into — no API key) instead of the built-in heuristic.
     *
     * @return array{base_url:string,team:string,checkin_channel:string,checkout_channel:string,token:string,checkin_channel_id:string,checkout_channel_id:string,checkout_show_hours:bool,intake_enabled:bool,intake_tag:string,intake_project:string,intake_channel:string,intake_llm:bool}
     */
    public function mattermost(): array
    {
        $m = $this->readJson($this->mattermostPath);
        $tag = trim((string) ($m['intake_tag'] ?? ''));
        return [
            'base_url' => (string) ($m['base_url'] ?? ''),
            'team' => (string) ($m['team'] ?? ''),
            'checkin_channel' => (string) ($m['checkin_channel'] ?? ''),
            'checkout_channel' => (string) ($m['checkout_channel'] ?? ''),
            'token' => (string) ($m['token'] ?? ''),
            'checkin_channel_id' => (string) ($m['checkin_channel_id'] ?? ''),
            'checkout_channel_id' => (string) ($m['checkout_channel_id'] ?? ''),
            // "Show worked hours" in the check-out post — gates BOTH the per-task times and the
            // daily total. Renamed from the old `checkout_show_total` (which only hid the total
            // line); fall back to it so an existing config migrates seamlessly on the next save.
            'checkout_show_hours' => (bool) ($m['checkout_show_hours'] ?? $m['checkout_show_total'] ?? true),
            'intake_enabled' => (bool) ($m['intake_enabled'] ?? false),
            'intake_tag' => $tag !== '' ? $tag : '@Claude',
            'intake_project' => (string) ($m['intake_project'] ?? ''),
            'intake_channel' => (string) ($m['intake_channel'] ?? ''),
            'intake_llm' => (bool) ($m['intake_llm'] ?? false),
            // Read-only auto-responder: reply to COLLEAGUES' status commands with task titles. Opt-in.
            'autoresponder_enabled' => (bool) ($m['autoresponder_enabled'] ?? false),
            // Allowlist of usernames who may pull the WEEKLY HOURS report (week/lastweek) — that command
            // shares worked hours, so it is restricted (titles-only commands stay open to everyone).
            'autoresponder_week_allow' => array_values(array_filter(
                (array) ($m['autoresponder_week_allow'] ?? []),
                static fn ($u) => is_string($u) && $u !== ''
            )),
        ];
    }

    /**
     * Validate and persist Mattermost config. The token follows the same "leave blank to keep"
     * rule as the TOTP seed (saveAccount), so re-saving other fields never requires re-typing it.
     * Cached channel ids are cleared whenever the address they resolved from changes, so a stale
     * id can't silently target the wrong channel.
     *
     * @param array<string,mixed> $input
     * @return array<string,string>
     */
    public function saveMattermost(array $input): array
    {
        $existing = $this->mattermost();
        $baseUrl = $this->normalizeBaseUrl((string) ($input['base_url'] ?? ''));
        $team = trim((string) ($input['team'] ?? ''));
        $checkin = trim((string) ($input['checkin_channel'] ?? ''));
        $checkout = trim((string) ($input['checkout_channel'] ?? ''));
        $token = trim((string) ($input['token'] ?? ''));
        $clearToken = !empty($input['clear_token']);

        if ($baseUrl === '') {
            throw new \InvalidArgumentException('A server URL like https://mattermost.example.com is required.');
        }
        if ($team === '') {
            throw new \InvalidArgumentException('Team is required (the slug in the channel URL, e.g. hypersense-software).');
        }
        if ($checkin === '' || $checkout === '') {
            throw new \InvalidArgumentException('Both the check-in and check-out channel names are required.');
        }
        // "leave blank to keep" — preserve the stored token unless explicitly cleared.
        if ($token === '' && !$clearToken && $existing['token'] !== '') {
            $token = $existing['token'];
        }

        // A cached channel id is only valid while the (base_url, team, channel) it resolved from
        // is unchanged — otherwise drop it so the next post/Test re-resolves.
        $sameServer = $baseUrl === $existing['base_url'] && $team === $existing['team'];
        $checkinId = ($sameServer && $checkin === $existing['checkin_channel']) ? $existing['checkin_channel_id'] : '';
        $checkoutId = ($sameServer && $checkout === $existing['checkout_channel']) ? $existing['checkout_channel_id'] : '';

        $showHours = array_key_exists('checkout_show_hours', $input)
            ? (bool) $input['checkout_show_hours']
            : ($existing['checkout_show_hours'] ?? true);

        // ---- @Claude intake (listener) settings ----
        $intakeEnabled = array_key_exists('intake_enabled', $input)
            ? (bool) $input['intake_enabled']
            : $existing['intake_enabled'];
        $intakeTag = trim((string) ($input['intake_tag'] ?? ''));
        if ($intakeTag === '') {
            $intakeTag = $existing['intake_tag']; // never blank (defaults to @Claude)
        }
        // The tag is matched as a leading whitespace-delimited token, so it must be a single token.
        if (preg_match('/\s/u', $intakeTag)) {
            throw new \InvalidArgumentException('The @Claude trigger tag must be a single word (no spaces).');
        }
        $intakeTag = mb_substr($intakeTag, 0, 40);
        $intakeProject = mb_substr(trim((string) ($input['intake_project'] ?? $existing['intake_project'])), 0, 120);
        $intakeChannel = trim((string) ($input['intake_channel'] ?? $existing['intake_channel']));

        // ---- Optional Claude interpretation (via the local `claude` CLI — no key) ----
        $intakeLlm = array_key_exists('intake_llm', $input) ? (bool) $input['intake_llm'] : $existing['intake_llm'];

        // ---- Read-only colleague auto-responder ----
        $autoresponder = array_key_exists('autoresponder_enabled', $input)
            ? (bool) $input['autoresponder_enabled']
            : $existing['autoresponder_enabled'];
        // Allowlist for the weekly-hours command: accept a comma/space/newline-separated string (or a
        // list), normalise to lowercase usernames without a leading @, dedupe, and cap the count.
        if (array_key_exists('autoresponder_week_allow', $input)) {
            $raw = $input['autoresponder_week_allow'];
            $parts = is_array($raw) ? $raw : preg_split('/[\s,;]+/', (string) $raw);
            $allow = [];
            foreach ($parts ?: [] as $u) {
                $u = strtolower(ltrim(trim((string) $u), '@'));
                if ($u !== '' && !in_array($u, $allow, true)) {
                    $allow[] = mb_substr($u, 0, 64);
                }
            }
            $weekAllow = array_slice($allow, 0, 50);
        } else {
            $weekAllow = $existing['autoresponder_week_allow'];
        }

        $record = [
            'base_url' => $baseUrl,
            'team' => $team,
            'checkin_channel' => $checkin,
            'checkout_channel' => $checkout,
            'token' => $token,
            'checkin_channel_id' => $checkinId,
            'checkout_channel_id' => $checkoutId,
            'checkout_show_hours' => $showHours,
            'intake_enabled' => $intakeEnabled,
            'intake_tag' => $intakeTag,
            'intake_project' => $intakeProject,
            'intake_channel' => $intakeChannel,
            'intake_llm' => $intakeLlm,
            'autoresponder_enabled' => $autoresponder,
            'autoresponder_week_allow' => $weekAllow,
        ];
        $this->writeJson($this->mattermostPath, $record);
        return $record;
    }

    /** Cache the resolved channel ids (called after a successful Test connection). */
    public function saveMattermostChannelIds(string $checkinId, string $checkoutId): void
    {
        $m = $this->mattermost();
        $m['checkin_channel_id'] = $checkinId;
        $m['checkout_channel_id'] = $checkoutId;
        $this->writeJson($this->mattermostPath, $m);
    }

    /**
     * Read the @Claude listener heartbeat (config/mm-listen.status). The daemon refreshes it each
     * loop iteration; a heartbeat older than $staleAfter means the process died without writing a
     * clean 'stopped', so we report it as stale (the UI shows it as not running). Carries no secret.
     *
     * @return array{running:bool,state:string,at:int,age:int,error:string}
     */
    public function listenerStatus(int $staleAfter = 45): array
    {
        $s = $this->readJson($this->listenerStatusPath);
        $state = (string) ($s['state'] ?? 'stopped');
        $at = (int) ($s['at'] ?? 0);
        $age = $at > 0 ? max(0, time() - $at) : -1;
        $alive = $at > 0 && $age <= $staleAfter;
        if (!$alive && $state !== 'stopped') {
            $state = 'stale';
        }
        return [
            'running' => $alive && in_array($state, ['connecting', 'connected', 'disabled'], true),
            'state' => $state,
            'at' => $at,
            'age' => $age,
            'error' => (string) ($s['error'] ?? ''),
        ];
    }

    /** Heartbeat written by bin/mm-listen — state + timestamp only, NEVER the token or message text. */
    public function writeListenerStatus(string $state, string $error = ''): void
    {
        $this->writeJson($this->listenerStatusPath, [
            'state' => $state,
            'at' => time(),
            'error' => $error,
        ]);
    }

    /**
     * Accept only a bare https origin (scheme + host + optional port). Rejecting any path/query/
     * fragment/userinfo guarantees the bearer token can only ever be sent to the host the
     * operator named, never to an attacker-chosen endpoint smuggled into the "URL".
     */
    private function normalizeBaseUrl(string $url): string
    {
        $url = trim($url);
        if ($url === '') {
            return '';
        }
        $p = parse_url($url);
        if ($p === false || ($p['scheme'] ?? '') !== 'https' || empty($p['host'])) {
            throw new \InvalidArgumentException('Server URL must be a full https:// address.');
        }
        if (!empty($p['user']) || !empty($p['pass']) || !empty($p['query']) || !empty($p['fragment'])) {
            throw new \InvalidArgumentException('Server URL must not include credentials, a query, or a fragment.');
        }
        if (isset($p['path']) && rtrim($p['path'], '/') !== '') {
            throw new \InvalidArgumentException('Server URL must be the host only, with no path.');
        }
        $port = isset($p['port']) ? ':' . (int) $p['port'] : '';
        return 'https://' . strtolower((string) $p['host']) . $port;
    }

    // ---- projects + redmine -----------------------------------------------

    /**
     * Managed projects (config/projects.json). Each is a display NAME that must match the `project`
     * string tasks are tagged with (so the weekly summary's per-project hours join to it) plus an
     * optional Redmine project URL. The Redmine host + project identifier (slug) are derived from
     * that URL on read (see redmineRef); the raw URL is kept for display/linking.
     *
     * @return array<int,array{id:string,name:string,redmine_url:string,redmine:?array{host:string,base_url:string,identifier:string}}>
     */
    public function projects(): array
    {
        $data = $this->readJson($this->projectsPath);
        $list = is_array($data['projects'] ?? null) ? $data['projects'] : [];
        $out = [];
        foreach ($list as $p) {
            if (!is_array($p)) {
                continue;
            }
            $name = trim((string) ($p['name'] ?? ''));
            if ($name === '') {
                continue;
            }
            $url = trim((string) ($p['redmine_url'] ?? ''));
            $out[] = [
                'id' => (string) ($p['id'] ?? ''),
                'name' => $name,
                'redmine_url' => $url,
                'redmine' => self::redmineRef($url),
            ];
        }
        return $out;
    }

    /**
     * Replace the whole project list (the UI edits it as one form). Every project needs a name; the
     * Redmine URL is optional but, when present, must be a https .../projects/<identifier> URL —
     * validated so the API key can later only ever be sent to that operator-named host.
     *
     * @param array<string,mixed> $input expects {projects: [{id?,name,redmine_url?}]}
     * @return array<int,array{id:string,name:string,redmine_url:string}>
     */
    public function saveProjects(array $input): array
    {
        $list = is_array($input['projects'] ?? null) ? $input['projects'] : [];
        $records = [];
        foreach ($list as $p) {
            if (!is_array($p)) {
                continue;
            }
            $name = mb_substr(trim((string) ($p['name'] ?? '')), 0, 120);
            if ($name === '') {
                throw new \InvalidArgumentException('Every project needs a name (the same name you tag tasks with).');
            }
            $url = trim((string) ($p['redmine_url'] ?? ''));
            if ($url !== '' && self::redmineRef($url) === null) {
                throw new \InvalidArgumentException("“{$name}”: the Redmine URL must look like https://redmine.example.com/projects/the-slug.");
            }
            $id = trim((string) ($p['id'] ?? ''));
            $taken = array_map(static fn (array $r): string => $r['id'], $records);
            if ($id === '' || in_array($id, $taken, true)) {
                $id = $this->slug($name, $records);
            }
            $records[] = ['id' => $id, 'name' => $name, 'redmine_url' => $url];
        }
        if (count($records) > 200) {
            throw new \InvalidArgumentException('Too many projects.');
        }
        $this->writeJson($this->projectsPath, ['projects' => $records]);
        return $records;
    }

    /**
     * Redmine API keys, keyed by HOST (config/redmine.json). One key per Redmine instance
     * (designli, hypersense, …) is shared by all projects on that host. The key is a SECRET: stored
     * 0600, only ever sent to its own host (over the TLS-verified Redmine client), and never
     * returned to the browser (the API exposes has_key only).
     *
     * @return array<string,array{api_key:string}>
     */
    public function redmineInstances(): array
    {
        $data = $this->readJson($this->redminePath);
        $inst = is_array($data['instances'] ?? null) ? $data['instances'] : [];
        $out = [];
        foreach ($inst as $host => $v) {
            $host = strtolower(trim((string) $host));
            if ($host === '' || !is_array($v)) {
                continue;
            }
            $key = (string) ($v['api_key'] ?? '');
            if ($key !== '') {
                $out[$host] = ['api_key' => $key];
            }
        }
        return $out;
    }

    public function redmineApiKeyForHost(string $host): string
    {
        return $this->redmineInstances()[strtolower(trim($host))]['api_key'] ?? '';
    }

    /**
     * Merge-update the per-host Redmine API keys. Follows the same "leave blank to keep" rule as the
     * Mattermost token, so re-saving never forces re-typing a key; a host listed in $clear is
     * forgotten. Hosts are validated as bare hostnames, keys as Redmine-style tokens.
     *
     * @param array<string,mixed> $keys  host => api_key ('' = keep existing)
     * @param array<int,string>   $clear hosts to remove
     * @return array<string,array{api_key:string}>
     */
    public function saveRedmineKeys(array $keys, array $clear = []): array
    {
        $cur = $this->redmineInstances();
        foreach ($keys as $host => $key) {
            $host = strtolower(trim((string) $host));
            if ($host === '' || !preg_match('/^[a-z0-9.-]+$/', $host)) {
                continue;
            }
            $key = trim((string) $key);
            if ($key === '') {
                continue; // leave blank to keep the stored key
            }
            if (!preg_match('/^[A-Za-z0-9]{8,128}$/', $key)) {
                throw new \InvalidArgumentException("That does not look like a Redmine API key for {$host} (the access key from your Redmine account page).");
            }
            $cur[$host] = ['api_key' => $key];
        }
        foreach ($clear as $host) {
            $host = strtolower(trim((string) $host));
            if ($host !== '') {
                unset($cur[$host]);
            }
        }
        $this->writeJson($this->redminePath, ['instances' => $cur]);
        return $cur;
    }

    /**
     * Parse a Redmine project URL into {host, base_url, identifier}, or null if it isn't a
     * https://…/projects/<identifier> URL. Used both to validate on save and to build the API base
     * URL + project id at call time — so the key is only ever sent to this exact host. Rejecting
     * any userinfo and requiring https is the same defence as Store::normalizeBaseUrl.
     *
     * @return array{host:string,base_url:string,identifier:string}|null
     */
    public static function redmineRef(string $url): ?array
    {
        $url = trim($url);
        if ($url === '') {
            return null;
        }
        $p = parse_url($url);
        if ($p === false || ($p['scheme'] ?? '') !== 'https' || empty($p['host'])) {
            return null;
        }
        if (!empty($p['user']) || !empty($p['pass'])) {
            return null;
        }
        $path = trim((string) ($p['path'] ?? ''), '/');
        // …/projects/<identifier>[/anything] — Redmine identifiers are [a-z0-9_-] (lower-case slug).
        if (!preg_match('#^projects/([A-Za-z0-9][A-Za-z0-9_-]*)#', $path, $m)) {
            return null;
        }
        $host = strtolower((string) $p['host']);
        // Keep the host charset in lockstep with saveRedmineKeys's check so a project can never point
        // at a host that could never hold a key (e.g. an underscore/punycode host parse_url accepts).
        if (!preg_match('/^[a-z0-9.-]+$/', $host)) {
            return null;
        }
        $port = isset($p['port']) ? ':' . (int) $p['port'] : '';
        return [
            'host' => $host,
            'base_url' => 'https://' . $host . $port,
            'identifier' => $m[1],
        ];
    }

    // ---- accounts ---------------------------------------------------------

    /** @return array<int,array<string,mixed>> */
    public function accounts(): array
    {
        $data = $this->readJson($this->accountsPath);
        $accounts = $data['accounts'] ?? [];
        return is_array($accounts) ? array_values($accounts) : [];
    }

    public function findAccount(string $id): ?array
    {
        foreach ($this->accounts() as $a) {
            if (($a['id'] ?? null) === $id) {
                return $a;
            }
        }
        return null;
    }

    /**
     * Insert or update an account. Returns the stored record.
     *
     * @param array<string,mixed> $input
     * @return array<string,mixed>
     */
    public function saveAccount(array $input): array
    {
        $label = trim((string) ($input['label'] ?? ''));
        $source = trim((string) ($input['source_profile'] ?? ''));
        $target = trim((string) ($input['target_profile'] ?? ''));
        $serial = trim((string) ($input['mfa_serial'] ?? ''));
        $region = trim((string) ($input['region'] ?? ''));
        $secret = trim((string) ($input['totp_secret'] ?? ''));
        $clearSecret = !empty($input['clear_secret']);
        $duration = (int) ($input['duration_seconds'] ?? 129600);

        if ($label === '') {
            throw new \InvalidArgumentException('Label is required.');
        }
        if ($source === '') {
            throw new \InvalidArgumentException('Source profile is required.');
        }
        if ($target === '') {
            $target = $source;
        }
        if ($serial === '') {
            throw new \InvalidArgumentException('MFA serial (ARN) is required.');
        }
        // AWS allows 900s..129600s for an IAM-user session token.
        if ($duration < 900 || $duration > 129600) {
            throw new \InvalidArgumentException('Duration must be between 900 and 129600 seconds.');
        }
        if ($secret !== '' && !Totp::looksLikeSecret($secret)) {
            throw new \InvalidArgumentException('That does not look like a valid base32 MFA secret.');
        }

        $id = trim((string) ($input['id'] ?? ''));
        $accounts = $this->accounts();

        if ($id === '') {
            $id = $this->slug($label, $accounts);
        }

        $record = [
            'id' => $id,
            'label' => $label,
            'source_profile' => $source,
            'target_profile' => $target,
            'mfa_serial' => $serial,
            'duration_seconds' => $duration,
            'region' => $region,
            'totp_secret' => $secret,
        ];

        $replaced = false;
        foreach ($accounts as $i => $a) {
            if (($a['id'] ?? null) === $id) {
                // "leave blank to keep" — preserve an existing secret unless the field was
                // blank AND the operator explicitly asked to forget it.
                if ($secret === '' && !$clearSecret && !empty($a['totp_secret'])) {
                    $record['totp_secret'] = (string) $a['totp_secret'];
                }
                $accounts[$i] = $record;
                $replaced = true;
                break;
            }
        }
        if (!$replaced) {
            $accounts[] = $record;
        }

        $this->writeJson($this->accountsPath, ['accounts' => array_values($accounts)]);
        return $record;
    }

    public function deleteAccount(string $id): void
    {
        $accounts = array_values(array_filter(
            $this->accounts(),
            static fn (array $a): bool => ($a['id'] ?? null) !== $id
        ));
        $this->writeJson($this->accountsPath, ['accounts' => $accounts]);

        $state = $this->state();
        unset($state['sessions'][$id]);
        $this->writeState($state);
    }

    // ---- state ------------------------------------------------------------

    /** @return array<string,mixed> */
    public function state(): array
    {
        $state = $this->readJson($this->statePath);
        if (!isset($state['sessions']) || !is_array($state['sessions'])) {
            $state['sessions'] = [];
        }
        return $state;
    }

    public function appToken(): string
    {
        $state = $this->state();
        if (empty($state['app_token']) || !is_string($state['app_token'])) {
            $state['app_token'] = bin2hex(random_bytes(32));
            $this->writeState($state);
        }
        return $state['app_token'];
    }

    public function recordSession(string $accountId, string $targetProfile, string $expirationIso): void
    {
        $state = $this->state();
        $state['sessions'][$accountId] = [
            'target_profile' => $targetProfile,
            'expiration' => $expirationIso,
            'refreshed_at' => gmdate('c'),
        ];
        $this->writeState($state);
    }

    public function sessionFor(string $accountId): ?array
    {
        $state = $this->state();
        $s = $state['sessions'][$accountId] ?? null;
        return is_array($s) ? $s : null;
    }

    private function writeState(array $state): void
    {
        $this->writeJson($this->statePath, $state);
    }

    // ---- helpers ----------------------------------------------------------

    private function slug(string $label, array $existing): string
    {
        $base = strtolower((string) preg_replace('/[^a-zA-Z0-9]+/', '-', $label));
        $base = trim($base, '-');
        if ($base === '') {
            $base = 'account';
        }
        $ids = array_map(static fn (array $a): string => (string) ($a['id'] ?? ''), $existing);
        $id = $base;
        $n = 2;
        while (in_array($id, $ids, true)) {
            $id = $base . '-' . $n;
            $n++;
        }
        return $id;
    }

    /** @return array<string,mixed> */
    private function readJson(string $path): array
    {
        if (!is_file($path)) {
            return [];
        }
        $raw = file_get_contents($path);
        if ($raw === false || trim($raw) === '') {
            return [];
        }
        $data = json_decode($raw, true);
        return is_array($data) ? $data : [];
    }

    private function writeJson(string $path, array $data): void
    {
        $json = json_encode($data, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
        if ($json === false) {
            throw new \RuntimeException('Failed to encode JSON for ' . $path);
        }
        // accounts.json can hold the MFA seed — create the temp 0600 from the first byte
        // (umask) and via exclusive create so it's never world-readable or symlink-followed.
        $oldUmask = umask(0077);
        try {
            $tmp = $path . '.tmp.' . getmypid();
            @unlink($tmp);
            $fh = @fopen($tmp, 'xb');
            if ($fh === false) {
                throw new \RuntimeException('Failed to create temp file for ' . $path);
            }
            if (@fwrite($fh, $json . "\n") === false) {
                fclose($fh);
                @unlink($tmp);
                throw new \RuntimeException('Failed to write ' . $path);
            }
            fclose($fh);
            @chmod($tmp, 0600);
            if (!@rename($tmp, $path)) {
                @unlink($tmp);
                throw new \RuntimeException('Failed to replace ' . $path);
            }
            @chmod($path, 0600);
        } finally {
            umask($oldUmask);
        }
    }
}
