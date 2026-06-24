<?php

declare(strict_types=1);

namespace AwsDash;

/**
 * Reads today's macOS app-usage from the Screen Time database
 * (~/Library/Application Support/Knowledge/knowledgeC.db). We sum the `/app/usage` intervals
 * (per-app foreground time) since local midnight — the same data Screen Time's category bars are
 * built from. (The `/display/isBacklit` "screen-on" stream is logged too sparsely on some Macs to
 * be reliable, so app-usage is the dependable "computer-active time" signal.)
 *
 * Browser time is special. knowledgeC only records time per *app*, so a whole browser collapses to
 * one opaque blob — which is why the old "Browsing" bucket lumped AWS docs, Google Sheets and
 * Facebook together and made real work look like idle web time. We fix that: the browser's
 * authoritative foreground seconds still come from knowledgeC, but we *apportion* them across
 * categories using the browser's own history DB (which domains, and for how long). So reading AWS
 * docs counts as Productivity, scrolling LinkedIn counts as Social, and only genuinely
 * unclassified sites land in "Browsing".
 *
 * Categories (ours, not Apple's — the proprietary Screen Time mapping isn't exposed to third
 * parties): Productivity (dev tools, AWS, Google Docs/Sheets, Redmine, GitHub, the company's own
 * domains…), Comms (chat / mail / calls), Social (social media), Browsing (web we couldn't
 * classify), Other (Finder, System Settings, misc apps).
 *
 * Access/security: knowledgeC.db is TCC-protected — open() is denied unless the process (the
 * operator's terminal that ran ./start.sh) has Full Disk Access; we report needs_fda in that case.
 * Every DB is opened READ-ONLY; all paths are fixed (derived from $HOME + known profile-dir names,
 * never client input — the /api/screentime endpoint takes no parameters); and every SQL statement
 * contains only server-computed integer timestamps (no injection surface). Browser history files
 * are held locked by the running browser, so PHP's SQLite3 can't open them — we read them with the
 * `sqlite3` CLI in immutable read-only mode (proc_open with an argv array, NEVER a shell), which
 * reads the live file in place without copying the (often 100 MB+) DB.
 */
final class ScreenTime
{
    private const APPLE_EPOCH = 978307200;      // seconds 1970-01-01 .. 2001-01-01 (knowledgeC, Safari)
    private const CHROME_EPOCH = 11644473600;   // seconds 1601-01-01 .. 1970-01-01 (Chromium visit_time)

    /** A single page visit's duration is capped before it weighs the split, so one pinned/background
     *  tab (Chromium logs "active tab" time even while the browser is in the background) can't eat a
     *  whole browser's foreground time. 30 min is generous for one genuine reading session. */
    private const VISIT_CAP = 1800;

    private const CATEGORIES = ['Productivity', 'Comms', 'Social', 'Browsing', 'Other'];

    /** Explicit, exact bundle id => bucket for native apps we already know (browsers are NOT here —
     *  they're pooled and split by history, see browserKey()). */
    private const BUNDLE_CATEGORY = [
        'com.microsoft.VSCode' => 'Productivity',
        'com.apple.Terminal' => 'Productivity',
        'com.googlecode.iterm2' => 'Productivity',
        'com.postmanlabs.mac' => 'Productivity',
        'com.sublimetext.4' => 'Productivity',
        'com.openai.codex' => 'Productivity',
        'com.anthropic.claudefordesktop' => 'Productivity',
        'com.apple.dt.Xcode' => 'Productivity',
        'org.pgadmin.pgadmin4' => 'Productivity',
        'com.tinyapp.TablePlus' => 'Productivity',
        'com.apple.mail' => 'Comms',
        'Mattermost.Desktop' => 'Comms',
        'net.whatsapp.WhatsApp' => 'Comms',
        'com.apple.MobileSMS' => 'Comms',
        'us.zoom.xos' => 'Comms',
        'com.tinyspeck.slackmacgap' => 'Comms',
        'com.apple.finder' => 'Other',
        'com.apple.systempreferences' => 'Other',
        'com.apple.Preview' => 'Other',
        'com.apple.archiveutility' => 'Other',
    ];

    /**
     * Fallback for unknown native apps: keywords matched against WHOLE dot-segments of the bundle id
     * (exact segment equality), so 'word' can't match 1pass*word* and 'mail' can't match g*mail*.
     */
    private const FALLBACK_KEYWORDS = [
        'Comms'        => ['whatsapp', 'mattermost', 'slack', 'telegram', 'discord', 'teams', 'zoom', 'skype', 'webex', 'signal', 'messenger', 'mobilesms', 'messages', 'mail', 'outlook', 'spark'],
        'Productivity' => ['vscode', 'terminal', 'iterm', 'iterm2', 'postman', 'sublimetext', 'xcode', 'jetbrains', 'intellij', 'pycharm', 'webstorm', 'goland', 'datagrip', 'cursor', 'codex', 'notion', 'word', 'excel', 'powerpoint', 'pages', 'numbers', 'keynote', 'notes', 'figma', 'sketch', 'jira', 'pgadmin', 'pgadmin4', 'tableplus', 'sequel', 'dbeaver', 'docker', 'tower', 'fork', 'sourcetree', 'linear'],
    ];

    /** Bundle id => friendly name for common apps (fallback prettifies the bundle id). */
    private const APP_NAMES = [
        'com.microsoft.VSCode' => 'Visual Studio Code',
        'com.google.Chrome' => 'Chrome',
        'com.apple.Safari' => 'Safari',
        'com.microsoft.edgemac' => 'Edge',
        'com.brave.Browser' => 'Brave',
        'company.thebrowser.Browser' => 'Arc',
        'org.mozilla.firefox' => 'Firefox',
        'com.apple.Terminal' => 'Terminal',
        'Mattermost.Desktop' => 'Mattermost',
        'net.whatsapp.WhatsApp' => 'WhatsApp',
        'com.anthropic.claudefordesktop' => 'Claude',
        'com.apple.mail' => 'Mail',
        'com.postmanlabs.mac' => 'Postman',
        'com.sublimetext.4' => 'Sublime Text',
        'com.openai.codex' => 'Codex',
        'com.apple.finder' => 'Finder',
        'com.apple.systempreferences' => 'System Settings',
        'com.apple.Preview' => 'Preview',
        'com.apple.dt.Xcode' => 'Xcode',
        'org.pgadmin.pgadmin4' => 'pgAdmin',
        'com.googlecode.iterm2' => 'iTerm',
        'com.tinyspeck.slackmacgap' => 'Slack',
        'us.zoom.xos' => 'Zoom',
    ];

    /** Domain rules for classifying a browser host. Checked social → comms → work; longest match
     *  wins via suffix equality (host === d || host ends with ".$d"). */
    private const SOCIAL_HOSTS = ['facebook.com', 'fb.com', 'messenger.com', 'linkedin.com', 'instagram.com', 'twitter.com', 'x.com', 'reddit.com', 'tiktok.com', 'youtube.com', 'youtu.be', 'pinterest.com', 'threads.net', 'snapchat.com', 'twitch.tv', '9gag.com', 'tumblr.com', 'mastodon.social', 'bsky.app'];
    private const COMMS_HOSTS  = ['mail.google.com', 'gmail.com', 'meet.google.com', 'calendar.google.com', 'chat.google.com', 'outlook.com', 'outlook.office.com', 'outlook.office365.com', 'office.com', 'teams.microsoft.com', 'teams.live.com', 'slack.com', 'web.whatsapp.com', 'web.telegram.org', 'discord.com', 'zoom.us', 'webex.com'];
    private const WORK_HOSTS   = ['aws.amazon.com', 'amazonaws.com', 'console.aws.amazon.com', 'signin.aws.amazon.com', 'docs.aws.amazon.com', 'github.com', 'githubusercontent.com', 'gitlab.com', 'bitbucket.org', 'stackoverflow.com', 'stackexchange.com', 'serverfault.com', 'superuser.com', 'docs.google.com', 'sheets.google.com', 'drive.google.com', 'script.google.com', 'colab.research.google.com', 'claude.ai', 'claude.com', 'anthropic.com', 'chatgpt.com', 'openai.com', 'vercel.com', 'netlify.app', 'netlify.com', 'npmjs.com', 'developer.mozilla.org', 'php.net', 'w3schools.com', 'digitalocean.com', 'cloudflare.com', 'sentry.io', 'datadoghq.com', 'grafana.com', 'postman.com', 'getpostman.com', 'figma.com', 'notion.so', 'atlassian.net', 'hypersense-software.com'];
    /** Substrings (matched anywhere in the host) that mark a host as work even on an unknown domain. */
    private const WORK_SUBSTRINGS  = ['redmine', 'jira', 'confluence', 'gitlab', 'jenkins', 'grafana', 'kibana', 'phpmyadmin'];

    private string $dbPath;
    private string $homeDir;
    private ?string $sqliteBin;

    public function __construct(string $homeDir)
    {
        $this->homeDir = rtrim($homeDir, '/');
        $this->dbPath = $this->homeDir . '/Library/Application Support/Knowledge/knowledgeC.db';
        $this->sqliteBin = is_executable('/usr/bin/sqlite3') ? '/usr/bin/sqlite3' : null;
    }

    /**
     * Today's computer-active time (local-midnight .. now) plus per-category, per-app and per-site
     * breakdowns.
     *
     * @return array{ok:bool,seconds:?int,needs_fda:bool,error:?string,categories:array<int,array{name:string,seconds:int}>,apps:array<int,array{name:string,seconds:int}>,sites:array<int,array{name:string,seconds:int,category:string}>}
     */
    public function today(?int $now = null): array
    {
        $now ??= time();
        if (!is_file($this->dbPath)) {
            return $this->result(false, null, false, 'Screen Time database not found (is Screen Time enabled in System Settings?).');
        }

        $dayStart = strtotime('today');
        $midnightApple = $dayStart - self::APPLE_EPOCH;
        $nowApple = $now - self::APPLE_EPOCH;

        try {
            $db = new \SQLite3($this->dbPath, SQLITE3_OPEN_READONLY);
            $db->busyTimeout(1500);
            // Per-app foreground seconds overlapping [midnight, now]: clamp the end to now (COALESCE
            // handles a still-open interval) and the start to midnight (so a session that began
            // before midnight still counts its post-midnight portion instead of being dropped).
            $sql = 'SELECT ZVALUESTRING AS bundle,
                           CAST(SUM(MIN(COALESCE(ZENDDATE, ' . $nowApple . '), ' . $nowApple . ') - MAX(ZSTARTDATE, ' . $midnightApple . ')) AS INTEGER) AS secs
                    FROM ZOBJECT
                    WHERE ZSTREAMNAME = \'/app/usage\'
                      AND ZVALUESTRING IS NOT NULL
                      AND COALESCE(ZENDDATE, ' . $nowApple . ') > ' . $midnightApple . '
                      AND ZSTARTDATE < ' . $nowApple . '
                    GROUP BY ZVALUESTRING';
            $res = $db->query($sql);

            $apps = [];
            $cats = array_fill_keys(self::CATEGORIES, 0);
            $browserPools = [];   // browserKey => foreground seconds (to be split by history)
            $total = 0;
            while ($res !== false && ($row = $res->fetchArray(SQLITE3_ASSOC))) {
                $secs = max(0, (int) ($row['secs'] ?? 0));
                if ($secs <= 0) {
                    continue;
                }
                $bundle = (string) $row['bundle'];
                $total += $secs;
                $apps[] = ['name' => $this->appName($bundle), 'seconds' => $secs];
                $browser = $this->browserKey($bundle);
                if ($browser !== null) {
                    $browserPools[$browser] = ($browserPools[$browser] ?? 0) + $secs;
                } else {
                    $cats[$this->categoryFor($bundle)] += $secs;
                }
            }
            $db->close();

            // Split each browser's foreground seconds across categories using its own history.
            $sites = [];
            foreach ($browserPools as $browser => $poolSecs) {
                if ($poolSecs <= 0) {
                    continue;
                }
                [$poolCats, $poolSites] = $this->attributeBrowser($browser, $poolSecs, $dayStart, $now);
                foreach ($poolCats as $c => $s) {
                    $cats[$c] = ($cats[$c] ?? 0) + $s;
                }
                foreach ($poolSites as $host => $info) {
                    if (!isset($sites[$host])) {
                        $sites[$host] = ['name' => $host, 'seconds' => 0, 'category' => $info['category']];
                    }
                    $sites[$host]['seconds'] += $info['seconds'];
                }
            }

            usort($apps, static fn ($a, $b) => $b['seconds'] <=> $a['seconds']);

            $catList = [];
            foreach ($cats as $name => $secs) {
                if ($secs > 0) {
                    $catList[] = ['name' => $name, 'seconds' => $secs];
                }
            }
            usort($catList, static fn ($a, $b) => $b['seconds'] <=> $a['seconds']);

            $siteList = array_values($sites);
            usort($siteList, static fn ($a, $b) => $b['seconds'] <=> $a['seconds']);

            return [
                'ok' => true,
                'seconds' => $total,
                'needs_fda' => false,
                'error' => null,
                'categories' => $catList,
                'apps' => array_slice($apps, 0, 15),
                'sites' => array_slice($siteList, 0, 12),
            ];
        } catch (\Throwable $e) {
            $msg = $e->getMessage();
            $needsFda = stripos($msg, 'authoriz') !== false
                || stripos($msg, 'unable to open') !== false
                || stripos($msg, 'not permitted') !== false
                || stripos($msg, 'cantopen') !== false;
            return $this->result(false, null, $needsFda, $needsFda ? 'Full Disk Access required to read Screen Time.' : $msg);
        }
    }

    // ---- browser attribution ----------------------------------------------

    /** Browser family for a bundle id (covers Chrome/Edge/Brave PWA "app" shims), or null. */
    private function browserKey(string $bundle): ?string
    {
        if (str_starts_with($bundle, 'com.google.Chrome')) {
            return 'chrome';   // includes com.google.Chrome.app.<id> PWA shims
        }
        if ($bundle === 'com.apple.Safari' || $bundle === 'com.apple.SafariTechnologyPreview') {
            return 'safari';
        }
        if (str_starts_with($bundle, 'com.microsoft.edgemac')) {
            return 'edge';
        }
        if (str_starts_with($bundle, 'com.brave.Browser')) {
            return 'brave';
        }
        if ($bundle === 'company.thebrowser.Browser') {
            return 'arc';
        }
        if ($bundle === 'org.mozilla.firefox') {
            return 'firefox';
        }
        return null;
    }

    /**
     * Split one browser's foreground seconds across categories. Returns [catSecs, sites] where
     * catSecs sums to exactly $poolSecs and sites is host => {seconds, category} (scaled to the same
     * total, for the "Top sites" breakdown). If the history can't be read (no CLI, locked-out,
     * unsupported browser, or simply no browsing logged today) the whole pool falls back to
     * "Browsing" so the figure is never silently lost.
     *
     * @return array{0:array<string,int>,1:array<string,array{seconds:int,category:string}>}
     */
    private function attributeBrowser(string $browser, int $poolSecs, int $dayStart, int $now): array
    {
        $weights = [];      // category => weight
        $siteWeights = [];  // host => [weight, category]

        if ($this->sqliteBin !== null) {
            if ($browser === 'safari') {
                $this->collectSafari($dayStart, $weights, $siteWeights);
            } else {
                foreach ($this->historyPaths($browser, $dayStart) as $path) {
                    $this->collectChromium($path, $dayStart, $now, $weights, $siteWeights);
                }
            }
        }

        $totalW = array_sum($weights);
        if ($totalW <= 0) {
            return [['Browsing' => $poolSecs], []];   // unattributed — show honestly as Browsing
        }

        $catSecs = $this->scaleToTotal($weights, $poolSecs);

        // Scale the per-site weights to the same pool total for the breakdown.
        $sites = [];
        $siteW = [];
        foreach ($siteWeights as $host => $info) {
            $siteW[$host] = $info['weight'];
        }
        $siteSecs = $this->scaleToTotal($siteW, $poolSecs);
        foreach ($siteSecs as $host => $secs) {
            if ($secs > 0) {
                $sites[$host] = ['seconds' => $secs, 'category' => $siteWeights[$host]['category']];
            }
        }
        return [$catSecs, $sites];
    }

    /**
     * Read a Chromium history DB (Chrome/Edge/Brave) for today's visits and fold each into the
     * category + per-host weight maps. The weight is the visit's foreground duration (capped). The
     * DB is held locked by the running browser, so we read it with the sqlite3 CLI in immutable
     * read-only mode (no copy of the 100 MB+ file).
     *
     * @param array<string,int>                                  $weights     category => weight (by ref)
     * @param array<string,array{weight:int,category:string}>    $siteWeights host => {weight,category} (by ref)
     */
    private function collectChromium(string $path, int $dayStart, int $now, array &$weights, array &$siteWeights): void
    {
        // Chromium visit_time is microseconds since 1601-01-01.
        $midMicro = ($dayStart + self::CHROME_EPOCH) * 1000000;
        $nowMicro = ($now + self::CHROME_EPOCH) * 1000000;
        $sql = 'SELECT u.url AS url, v.visit_duration AS dur'
             . ' FROM visits v JOIN urls u ON u.id = v.url'
             . ' WHERE v.visit_time > ' . $midMicro . ' AND v.visit_time <= ' . $nowMicro
             . ' AND v.visit_duration > 0';
        foreach ($this->sqliteRows($path, $sql) as $row) {
            $host = $this->hostOf((string) ($row['url'] ?? ''));
            if ($host === null) {
                continue;
            }
            $secs = min((int) ((int) ($row['dur'] ?? 0) / 1000000), self::VISIT_CAP);
            if ($secs <= 0) {
                continue;
            }
            $this->addWeight($host, $secs, $weights, $siteWeights);
        }
    }

    /**
     * Read Safari's History.db for today. Safari logs no per-visit duration, so each visit weighs 1
     * (count-based split) — coarser than Chromium, but still separates work sites from social.
     *
     * @param array<string,int>                               $weights     (by ref)
     * @param array<string,array{weight:int,category:string}> $siteWeights (by ref)
     */
    private function collectSafari(int $dayStart, array &$weights, array &$siteWeights): void
    {
        $path = $this->homeDir . '/Library/Safari/History.db';
        if (!is_file($path) || @filemtime($path) < $dayStart) {
            return;
        }
        $midApple = $dayStart - self::APPLE_EPOCH;  // Safari visit_time is CFAbsoluteTime (since 2001)
        $sql = 'SELECT i.url AS url'
             . ' FROM history_visits v JOIN history_items i ON i.id = v.history_item'
             . ' WHERE v.visit_time > ' . $midApple;
        foreach ($this->sqliteRows($path, $sql) as $row) {
            $host = $this->hostOf((string) ($row['url'] ?? ''));
            if ($host !== null) {
                $this->addWeight($host, 1, $weights, $siteWeights);
            }
        }
    }

    /**
     * @param array<string,int>                               $weights     (by ref)
     * @param array<string,array{weight:int,category:string}> $siteWeights (by ref)
     */
    private function addWeight(string $host, int $w, array &$weights, array &$siteWeights): void
    {
        $cat = $this->categoryForHost($host);
        $weights[$cat] = ($weights[$cat] ?? 0) + $w;
        if (!isset($siteWeights[$host])) {
            $siteWeights[$host] = ['weight' => 0, 'category' => $cat];
        }
        $siteWeights[$host]['weight'] += $w;
    }

    /** Active-today history DBs for a Chromium-family browser (skips inactive/Guest/System profiles). */
    private function historyPaths(string $browser, int $dayStart): array
    {
        $base = match ($browser) {
            'chrome' => $this->homeDir . '/Library/Application Support/Google/Chrome',
            'edge'   => $this->homeDir . '/Library/Application Support/Microsoft Edge',
            'brave'  => $this->homeDir . '/Library/Application Support/BraveSoftware/Brave-Browser',
            // Arc/Firefox use a different layout/schema — fall through to "Browsing" (no reader).
            default  => null,
        };
        if ($base === null) {
            return [];
        }
        $out = [];
        foreach ((array) glob($base . '/*/History') as $path) {
            $profile = basename(dirname((string) $path));
            if ($profile === 'System Profile' || $profile === 'Guest Profile') {
                continue;
            }
            if (is_file($path) && @filemtime($path) >= $dayStart) {
                $out[] = (string) $path;   // only profiles touched today (avoids reading stale 100 MB DBs)
            }
        }
        return $out;
    }

    /**
     * Run a read-only query against a (browser-locked) SQLite DB via the sqlite3 CLI in immutable
     * mode and return the decoded rows. proc_open with an ARGV ARRAY (no shell) — the only inputs
     * are a fixed binary, a server-built path, and a constant SQL string with integer literals.
     *
     * @return array<int,array<string,mixed>>
     */
    private function sqliteRows(string $path, string $sql): array
    {
        if ($this->sqliteBin === null || !is_file($path)) {
            return [];
        }
        $descriptors = [1 => ['pipe', 'w'], 2 => ['pipe', 'w']];
        $proc = @proc_open(
            [$this->sqliteBin, '-readonly', '-json', 'file:' . $path . '?immutable=1', $sql],
            $descriptors,
            $pipes
        );
        if (!is_resource($proc)) {
            return [];
        }
        $out = stream_get_contents($pipes[1]);
        fclose($pipes[1]);
        fclose($pipes[2]);
        proc_close($proc);
        $rows = json_decode((string) $out, true);
        return is_array($rows) ? $rows : [];   // sqlite3 -json prints "" for an empty result set
    }

    /** Lower-cased registrable host from a URL (http/https only; '' for chrome://, file://, etc.). */
    private function hostOf(string $url): ?string
    {
        if (!preg_match('#^https?://#i', $url)) {
            return null;
        }
        $host = parse_url($url, PHP_URL_HOST);
        if (!is_string($host) || $host === '') {
            return null;
        }
        $host = strtolower($host);
        return str_starts_with($host, 'www.') ? substr($host, 4) : $host;
    }

    /** Classify a browser host into a category (social → comms → work → Browsing). */
    private function categoryForHost(string $host): string
    {
        $match = static function (string $h, array $domains): bool {
            foreach ($domains as $d) {
                if ($h === $d || str_ends_with($h, '.' . $d)) {
                    return true;
                }
            }
            return false;
        };
        if ($match($host, self::SOCIAL_HOSTS)) {
            return 'Social';
        }
        // Comms before work so e.g. mattermost.<company-domain> reads as Comms, not Productivity.
        if (str_contains($host, 'mattermost') || $match($host, self::COMMS_HOSTS)) {
            return 'Comms';
        }
        if ($host === 'localhost' || $host === '127.0.0.1' || str_starts_with($host, 'localhost')) {
            return 'Productivity';
        }
        foreach (self::WORK_SUBSTRINGS as $kw) {
            if (str_contains($host, $kw)) {
                return 'Productivity';
            }
        }
        if ($match($host, self::WORK_HOSTS)) {
            return 'Productivity';
        }
        return 'Browsing';
    }

    /**
     * Distribute $total across keys proportionally to their weights, as integers that sum to exactly
     * $total (any rounding remainder goes to the heaviest key).
     *
     * @param array<string,int|float> $weights
     * @return array<string,int>
     */
    private function scaleToTotal(array $weights, int $total): array
    {
        $sum = array_sum($weights);
        if ($sum <= 0 || $total <= 0) {
            return [];
        }
        $out = [];
        $acc = 0;
        foreach ($weights as $k => $w) {
            $v = (int) floor($w / $sum * $total);
            $out[$k] = $v;
            $acc += $v;
        }
        $rem = $total - $acc;
        if ($rem !== 0) {
            $heaviest = array_keys($weights, max($weights), true)[0] ?? array_key_first($weights);
            $out[$heaviest] += $rem;
        }
        return $out;
    }

    private function categoryFor(string $bundle): string
    {
        if (isset(self::BUNDLE_CATEGORY[$bundle])) {
            return self::BUNDLE_CATEGORY[$bundle];
        }
        $segments = explode('.', strtolower($bundle));
        foreach (self::FALLBACK_KEYWORDS as $cat => $keywords) {
            foreach ($keywords as $kw) {
                if (in_array($kw, $segments, true)) { // whole-segment match — no substring false positives
                    return $cat;
                }
            }
        }
        return 'Other';
    }

    private function appName(string $bundle): string
    {
        if (isset(self::APP_NAMES[$bundle])) {
            return self::APP_NAMES[$bundle];
        }
        // Chrome/Edge/Brave "app" shims look like com.google.Chrome.app.<hash> — collapse to a label.
        if (str_contains($bundle, '.Chrome.app.')) {
            return 'Chrome app';
        }
        if (str_contains($bundle, '.edgemac.app.') || str_contains($bundle, '.Browser.app.')) {
            return 'Web app';
        }
        $seg = strrchr($bundle, '.');
        $name = $seg === false ? $bundle : substr($seg, 1);
        return $name === '' ? $bundle : ucfirst($name);
    }

    /** @return array{ok:bool,seconds:?int,needs_fda:bool,error:?string,categories:array<int,mixed>,apps:array<int,mixed>,sites:array<int,mixed>} */
    private function result(bool $ok, ?int $seconds, bool $needsFda, ?string $error): array
    {
        return ['ok' => $ok, 'seconds' => $seconds, 'needs_fda' => $needsFda, 'error' => $error, 'categories' => [], 'apps' => [], 'sites' => []];
    }
}
