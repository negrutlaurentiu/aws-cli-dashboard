<?php

declare(strict_types=1);

namespace AwsDash;

require __DIR__ . '/../src/bootstrap.php';

$app = new App();
$app->assertTrustedHost();

$method = $_SERVER['REQUEST_METHOD'] ?? 'GET';
$path = rtrim((string) parse_url((string) ($_SERVER['REQUEST_URI'] ?? '/'), PHP_URL_PATH), '/');
if ($path === '') {
    $path = '/';
}

try {
    switch (true) {
        case $method === 'GET' && $path === '/':
            renderPage($app);
            break;

        case $method === 'GET' && $path === '/api/accounts':
            $app->json([
                'ok' => true,
                'now' => time(),
                'duration_seconds' => $app->store->durationSeconds(),
                'accounts' => accountViews($app),
            ]);
            break;

        case $method === 'GET' && $path === '/api/settings':
            $app->json(['ok' => true, 'settings' => $app->store->settings()]);
            break;

        case $method === 'POST' && $path === '/api/settings':
            $app->assertCsrf();
            $app->json(['ok' => true, 'settings' => $app->store->saveSettings($app->jsonBody())]);
            break;

        case $method === 'GET' && $path === '/api/profiles':
            $app->json([
                'ok' => true,
                'profiles' => Sts::listProfiles($app->credentialsPath, $app->awsConfigPath),
            ]);
            break;

        case $method === 'POST' && $path === '/api/accounts':
            $app->assertCsrf();
            $record = $app->store->saveAccount($app->jsonBody());
            $app->json(['ok' => true, 'account' => publicAccount($record, $app)]);
            break;

        case $method === 'POST' && $path === '/api/accounts/delete':
            $app->assertCsrf();
            $id = trim((string) ($app->jsonBody()['id'] ?? ''));
            if ($id === '') {
                $app->fail(400, 'Missing account id.');
            }
            $app->store->deleteAccount($id);
            $app->json(['ok' => true]);
            break;

        case $method === 'POST' && $path === '/api/refresh':
            $app->assertCsrf();
            refresh($app);
            break;

        case $method === 'POST' && $path === '/api/whoami':
            $app->assertCsrf();
            whoami($app);
            break;

        case $method === 'POST' && $path === '/api/set-default':
            $app->assertCsrf();
            setDefault($app);
            break;

        case $method === 'GET' && $path === '/s3':
            renderS3($app);
            break;

        case $method === 'POST' && $path === '/api/s3/buckets':
            $app->assertCsrf();
            s3Buckets($app);
            break;

        case $method === 'POST' && $path === '/api/s3/list':
            $app->assertCsrf();
            s3List($app);
            break;

        case $method === 'GET' && $path === '/api/s3/object':
            $app->assertCsrfQuery();
            s3Object($app);
            break;

        case $method === 'POST' && $path === '/api/s3/download':
            $app->assertCsrf();
            s3Download($app);
            break;

        case $method === 'POST' && $path === '/api/s3/download-status':
            $app->assertCsrf();
            s3DownloadStatus($app);
            break;

        case $method === 'POST' && $path === '/api/profiles/delete':
            $app->assertCsrf();
            deleteProfile($app);
            break;

        case $method === 'GET' && $path === '/tasks':
            renderTasks($app);
            break;

        case $method === 'GET' && $path === '/api/tasks':
            $app->json(['ok' => true, 'now' => time(), 'tasks' => $app->tasks->all()]);
            break;

        case $method === 'GET' && $path === '/api/tasks/summary':
            $off = (int) ($_GET['week'] ?? 0);
            $set = $app->store->settings();
            $app->json(['ok' => true, 'summary' => $app->tasks->weekSummary(
                time() + $off * 7 * 86400,
                null,
                (float) $set['daily_target_hours'],
                (bool) $set['target_weekdays_only']
            )]);
            break;

        case $method === 'GET' && $path === '/api/screentime':
            $app->json(['ok' => true, 'screentime' => (new ScreenTime(App::homeDir()))->today()]);
            break;

        case $method === 'POST' && $path === '/api/tasks':
            $app->assertCsrf();
            $tb = $app->jsonBody();
            $app->json(['ok' => true, 'task' => $app->tasks->create(
                (string) ($tb['title'] ?? ''),
                (string) ($tb['description'] ?? ''),
                (string) ($tb['status'] ?? 'pending'),
                (string) ($tb['project'] ?? ''),
                (string) ($tb['label'] ?? ''),
                (string) ($tb['priority'] ?? 'medium')
            )]);
            break;

        case $method === 'POST' && $path === '/api/tasks/update':
            $app->assertCsrf();
            $tb = $app->jsonBody();
            $tid = trim((string) ($tb['id'] ?? ''));
            if ($tid === '') {
                $app->fail(400, 'Missing task id.');
            }
            unset($tb['id']);
            $app->json(['ok' => true, 'task' => $app->tasks->update($tid, $tb)]);
            break;

        case $method === 'POST' && $path === '/api/tasks/move':
            $app->assertCsrf();
            $tb = $app->jsonBody();
            $tid = trim((string) ($tb['id'] ?? ''));
            if ($tid === '') {
                $app->fail(400, 'Missing task id.');
            }
            $app->json(['ok' => true, 'task' => $app->tasks->move(
                $tid,
                (string) ($tb['status'] ?? ''),
                trim((string) ($tb['before_id'] ?? ''))
            )]);
            break;

        case $method === 'POST' && $path === '/api/tasks/delete':
            $app->assertCsrf();
            $tid = trim((string) ($app->jsonBody()['id'] ?? ''));
            if ($tid === '') {
                $app->fail(400, 'Missing task id.');
            }
            $app->tasks->delete($tid);
            $app->json(['ok' => true]);
            break;

        case $method === 'POST' && $path === '/api/tasks/timer':
            $app->assertCsrf();
            $tb = $app->jsonBody();
            $tid = trim((string) ($tb['id'] ?? ''));
            if ($tid === '') {
                $app->fail(400, 'Missing task id.');
            }
            $task = (string) ($tb['action'] ?? '') === 'stop'
                ? $app->tasks->timerStop($tid)
                : $app->tasks->timerStart($tid);
            $app->json(['ok' => true, 'task' => $task]);
            break;

        case $method === 'POST' && $path === '/api/tasks/worked':
            $app->assertCsrf();
            $tb = $app->jsonBody();
            $tid = trim((string) ($tb['id'] ?? ''));
            if ($tid === '') {
                $app->fail(400, 'Missing task id.');
            }
            $secs = (int) ($tb['seconds'] ?? 0);
            // Worked time is always logged against a specific local day (YYYY-MM-DD).
            $task = $app->tasks->setWorkedForDay($tid, (string) ($tb['date'] ?? ''), $secs);
            $app->json(['ok' => true, 'task' => $task]);
            break;

        case $method === 'POST' && $path === '/api/tasks/upload':
            $app->assertCsrf();
            taskUpload($app);
            break;

        case $method === 'GET' && $path === '/api/tasks/file':
            $app->assertCsrfQuery();
            taskFile($app);
            break;

        case $method === 'POST' && $path === '/api/tasks/note':
            $app->assertCsrf();
            $tb = $app->jsonBody();
            $tid = trim((string) ($tb['id'] ?? ''));
            if ($tid === '') {
                $app->fail(400, 'Missing task id.');
            }
            $app->json(['ok' => true, 'task' => $app->tasks->addNote($tid, (string) ($tb['text'] ?? ''))]);
            break;

        case $method === 'POST' && $path === '/api/tasks/note-delete':
            $app->assertCsrf();
            $tb = $app->jsonBody();
            $tid = trim((string) ($tb['task_id'] ?? ''));
            $nid = trim((string) ($tb['note_id'] ?? ''));
            if ($tid === '' || $nid === '') {
                $app->fail(400, 'Missing ids.');
            }
            $app->json(['ok' => true, 'task' => $app->tasks->deleteNote($tid, $nid)]);
            break;

        case $method === 'POST' && $path === '/api/tasks/file-delete':
            $app->assertCsrf();
            $tb = $app->jsonBody();
            $tid = trim((string) ($tb['task_id'] ?? ''));
            $fid = trim((string) ($tb['file_id'] ?? ''));
            if ($tid === '' || $fid === '') {
                $app->fail(400, 'Missing ids.');
            }
            $app->tasks->removeAttachment($tid, $fid);
            $app->json(['ok' => true]);
            break;

        case $method === 'GET' && $path === '/api/mattermost/settings':
            $app->json(['ok' => true, 'settings' => mattermostSettingsView($app)]);
            break;

        case $method === 'POST' && $path === '/api/mattermost/settings':
            $app->assertCsrf();
            $app->store->saveMattermost($app->jsonBody());
            $app->json(['ok' => true, 'settings' => mattermostSettingsView($app)]);
            break;

        case $method === 'POST' && $path === '/api/mattermost/test':
            $app->assertCsrf();
            mattermostTest($app);
            break;

        case $method === 'POST' && $path === '/api/mattermost/checkin':
            $app->assertCsrf();
            mattermostPost($app, 'checkin');
            break;

        case $method === 'POST' && $path === '/api/mattermost/checkout':
            $app->assertCsrf();
            mattermostPost($app, 'checkout');
            break;

        case $method === 'POST' && $path === '/api/mattermost/weekly':
            $app->assertCsrf();
            mattermostWeekly($app);
            break;

        // @Claude intake listener (bin/mm-listen) — health + manual (re)start/stop.
        case $method === 'GET' && $path === '/api/mattermost/listener':
            $app->json(['ok' => true, 'listener' => mattermostListenerView($app)]);
            break;

        case $method === 'POST' && $path === '/api/mattermost/listener/start':
            $app->assertCsrf();
            mattermostListenerStart($app);
            break;

        case $method === 'POST' && $path === '/api/mattermost/listener/stop':
            $app->assertCsrf();
            mattermostListenerStop($app);
            break;

        // ---- projects + Redmine reconciliation ----
        case $method === 'GET' && $path === '/projects':
            renderProjects($app);
            break;

        case $method === 'GET' && $path === '/api/projects':
            $app->json(['ok' => true] + projectsView($app));
            break;

        case $method === 'POST' && $path === '/api/projects':
            $app->assertCsrf();
            $app->store->saveProjects($app->jsonBody());
            $app->json(['ok' => true] + projectsView($app));
            break;

        case $method === 'POST' && $path === '/api/redmine/keys':
            $app->assertCsrf();
            $rb = $app->jsonBody();
            $app->store->saveRedmineKeys(
                is_array($rb['keys'] ?? null) ? $rb['keys'] : [],
                is_array($rb['clear'] ?? null) ? $rb['clear'] : []
            );
            $app->json(['ok' => true] + projectsView($app));
            break;

        case $method === 'GET' && $path === '/api/projects/redmine-status':
            redmineStatus($app);
            break;

        default:
            $app->fail(404, 'Not found: ' . $method . ' ' . $path);
    }
} catch (\InvalidArgumentException $e) {
    $app->fail(400, $e->getMessage());
} catch (\Throwable $e) {
    $app->fail(500, $e->getMessage());
}

// ---------------------------------------------------------------------------

/** Build the per-account view objects for the dashboard (never leaks the TOTP seed). */
function accountViews(App $app): array
{
    $now = time();
    $existing = [];
    if (is_file($app->credentialsPath)) {
        $existing = (new CredentialsFile($app->credentialsPath))->profileNames();
    }

    $views = [];
    foreach ($app->store->accounts() as $a) {
        $view = publicAccount($a, $app);
        $view['target_exists'] = in_array((string) ($a['target_profile'] ?? ''), $existing, true);

        if (!empty($a['totp_secret'])) {
            try {
                $view['totp'] = Totp::generate((string) $a['totp_secret'], $now);
            } catch (\Throwable $e) {
                $view['totp'] = null;
                $view['totp_error'] = $e->getMessage();
            }
        }

        $session = $app->store->sessionFor((string) $a['id']);
        if ($session !== null) {
            $expiresUnix = isset($session['expiration']) ? strtotime((string) $session['expiration']) : false;
            $view['session'] = [
                'expiration' => $session['expiration'] ?? null,
                'refreshed_at' => $session['refreshed_at'] ?? null,
                'expires_unix' => $expiresUnix === false ? null : $expiresUnix,
                'expires_in' => $expiresUnix === false ? null : ($expiresUnix - $now),
            ];
        } else {
            $view['session'] = null;
        }

        $views[] = $view;
    }
    return $views;
}

/** A safe, secret-free representation of an account record. */
function publicAccount(array $a, App $app): array
{
    return [
        'id' => (string) ($a['id'] ?? ''),
        'label' => (string) ($a['label'] ?? ''),
        'source_profile' => (string) ($a['source_profile'] ?? ''),
        'target_profile' => (string) ($a['target_profile'] ?? ''),
        'mfa_serial' => (string) ($a['mfa_serial'] ?? ''),
        'duration_seconds' => (int) ($a['duration_seconds'] ?? 129600),
        'region' => (string) ($a['region'] ?? ''),
        'has_secret' => !empty($a['totp_secret']),
    ];
}

function refresh(App $app): void
{
    $body = $app->jsonBody();
    $id = trim((string) ($body['id'] ?? ''));
    $account = $app->store->findAccount($id);
    if ($account === null) {
        $app->fail(404, 'Unknown account: ' . $id);
    }

    $code = trim((string) ($body['code'] ?? ''));
    $usedStoredSecret = false;
    if ($code === '' && !empty($account['totp_secret'])) {
        $code = Totp::generate((string) $account['totp_secret'])['code'];
        $usedStoredSecret = true;
    }
    if (!preg_match('/^\d{6,8}$/', $code)) {
        $app->fail(400, 'Provide a valid MFA code (6 digits), or store the MFA secret for this account.');
    }

    $sts = new Sts($app->awsBin);
    $creds = $sts->getSessionToken(
        (string) $account['mfa_serial'],
        (string) $account['source_profile'],
        $code,
        $app->store->durationSeconds() // global token lifetime, shared by all accounts
    );

    $target = (string) $account['target_profile'];
    $app->withCredentialsLock(function () use ($app, $target, $creds, $account): void {
        $file = new CredentialsFile($app->credentialsPath);
        $file->setProfile($target, [
            'aws_access_key_id' => $creds['AccessKeyId'],
            'aws_secret_access_key' => $creds['SecretAccessKey'],
            'aws_session_token' => $creds['SessionToken'],
        ]);
        if (!empty($account['region'])) {
            $file->setProfile($target, ['region' => (string) $account['region']]);
        }
        $file->save();
    });

    $app->store->recordSession($id, $target, $creds['Expiration']);

    $app->json([
        'ok' => true,
        'target_profile' => $target,
        'expiration' => $creds['Expiration'],
        'access_key_id' => maskKey($creds['AccessKeyId']),
        'used_stored_secret' => $usedStoredSecret,
        'backup' => $app->credentialsPath . '.bak',
    ]);
}

/** Run get-caller-identity on a profile to report who/what it is and whether it's valid. */
function whoami(App $app): void
{
    $profile = trim((string) ($app->jsonBody()['profile'] ?? ''));
    if ($profile === '') {
        $app->fail(400, 'Missing profile.');
    }

    $sts = new Sts($app->awsBin);
    try {
        $id = $sts->getCallerIdentity($profile);
        $app->json([
            'ok' => true,
            'valid' => true,
            'profile' => $profile,
            'account' => $id['Account'],
            'arn' => $id['Arn'],
            'user_id' => $id['UserId'],
        ]);
    } catch (\Throwable $e) {
        $msg = $e->getMessage();
        $app->json([
            'ok' => true,
            'valid' => false,
            'profile' => $profile,
            'expired' => (bool) preg_match('/expired/i', $msg),
            'error' => $msg,
        ]);
    }
}

/** Copy a profile's credentials into [default], so unscoped aws commands use them. */
function setDefault(App $app): void
{
    $profile = trim((string) ($app->jsonBody()['profile'] ?? ''));
    if ($profile === '') {
        $app->fail(400, 'Missing profile.');
    }
    if ($profile === 'default') {
        $app->fail(400, 'That profile is already the default.');
    }

    $app->withCredentialsLock(function () use ($app, $profile): void {
        $file = new CredentialsFile($app->credentialsPath);
        $vals = $file->getProfile($profile);
        if ($vals === null) {
            throw new \RuntimeException("Profile [{$profile}] is not in the credentials file.");
        }
        $akid = $vals['aws_access_key_id'] ?? '';
        $secret = $vals['aws_secret_access_key'] ?? '';
        if ($akid === '' || $secret === '') {
            throw new \RuntimeException("Profile [{$profile}] has no access key/secret to copy.");
        }

        $copy = ['aws_access_key_id' => $akid, 'aws_secret_access_key' => $secret];
        // The source may carry its token under the canonical or the legacy alias.
        $token = (string) ($vals['aws_session_token'] ?? $vals['aws_security_token'] ?? '');
        if ($token !== '') {
            $copy['aws_session_token'] = $token;
        }
        $file->setProfile('default', $copy);
        if ($token === '') {
            // Long-term keys → no session token should linger in [default].
            $file->removeKey('default', 'aws_session_token');
        }
        // We only ever write the canonical aws_session_token, but botocore reads the legacy
        // aws_security_token first — a stale alias would shadow our value, so always strip it.
        $file->removeKey('default', 'aws_security_token');
        if (!empty($vals['region'])) {
            $file->setProfile('default', ['region' => (string) $vals['region']]);
        }
        $file->save();
    });

    $app->json(['ok' => true, 'profile' => $profile]);
}

function maskKey(string $key): string
{
    if (strlen($key) <= 8) {
        return $key;
    }
    return substr($key, 0, 4) . str_repeat('•', max(0, strlen($key) - 8)) . substr($key, -4);
}

function renderPage(App $app): never
{
    renderTemplate($app, 'page.html', "default-src 'self'; style-src 'self' 'unsafe-inline'; img-src 'self' data:");
}

function renderS3(App $app): never
{
    // The S3 viewer embeds object bytes via same-origin <img>/<iframe>; allow those, plus
    // blob: for client-built previews. The object responses themselves carry their own
    // strict, script-free CSP (see s3Object).
    renderTemplate($app, 's3.html', "default-src 'self'; style-src 'self' 'unsafe-inline'; img-src 'self' data: blob:; frame-src 'self'; object-src 'self'");
}

function renderTasks(App $app): never
{
    renderTemplate($app, 'tasks.html', "default-src 'self'; style-src 'self' 'unsafe-inline'; img-src 'self' data: blob:; frame-src 'self'; object-src 'self'");
}

function renderProjects(App $app): never
{
    renderTemplate($app, 'projects.html', "default-src 'self'; style-src 'self' 'unsafe-inline'; img-src 'self' data:");
}

function renderTemplate(App $app, string $file, string $csp): never
{
    $template = file_get_contents(__DIR__ . '/../src/' . $file);
    if ($template === false) {
        http_response_code(500);
        echo 'Template missing.';
        exit;
    }
    // Cache-buster for the static JS/CSS: the newest mtime across our assets. The page HTML is
    // no-store (below), so every reload re-reads this and a changed file gets a fresh `?v=` URL —
    // no-build means there's nothing else to stop a long-lived tab from running stale JS, and PHP's
    // built-in server serves these assets without any cache validators.
    $assetVer = 0;
    foreach (array_merge([__DIR__ . '/styles.css'], glob(__DIR__ . '/*.js') ?: []) as $asset) {
        $m = @filemtime($asset);
        if ($m !== false && $m > $assetVer) {
            $assetVer = $m;
        }
    }
    $html = strtr($template, [
        '__APP_TOKEN__' => htmlspecialchars($app->store->appToken(), ENT_QUOTES),
        '__HOST__' => App::HOST,
        '__PORT__' => (string) App::PORT,
        '__ASSET_VER__' => (string) $assetVer,
    ]);
    header('Content-Type: text/html; charset=utf-8');
    header('Cache-Control: no-store');
    header('X-Content-Type-Options: nosniff');
    header('X-Frame-Options: DENY');
    header('Content-Security-Policy: ' . $csp);
    header('Referrer-Policy: no-referrer');
    echo $html;
    exit;
}

// ---- S3 browser ------------------------------------------------------------

function s3Buckets(App $app): void
{
    $profile = trim((string) ($app->jsonBody()['profile'] ?? ''));
    if ($profile === '') {
        $app->fail(400, 'Missing profile.');
    }
    $app->json(['ok' => true, 'buckets' => (new S3($app->awsBin))->listBuckets($profile)]);
}

function s3List(App $app): void
{
    $b = $app->jsonBody();
    $profile = trim((string) ($b['profile'] ?? ''));
    $bucket = trim((string) ($b['bucket'] ?? ''));
    $prefix = (string) ($b['prefix'] ?? '');
    $token = (string) ($b['token'] ?? '');
    $max = (int) ($b['max'] ?? 200);
    if ($profile === '' || $bucket === '') {
        $app->fail(400, 'Missing profile or bucket.');
    }
    $app->json(['ok' => true] + (new S3($app->awsBin))->listObjects($profile, $bucket, $prefix, $token, $max));
}

/** Stream a single object with strict, script-free headers (view inline or download). */
function s3Object(App $app): void
{
    $profile = trim((string) ($_GET['profile'] ?? ''));
    $bucket = trim((string) ($_GET['bucket'] ?? ''));
    $key = (string) ($_GET['key'] ?? '');
    $dl = (string) ($_GET['dl'] ?? '') === '1';
    if ($profile === '' || $bucket === '' || $key === '') {
        http_response_code(400);
        header('Content-Type: text/plain; charset=utf-8');
        echo 'Missing parameters.';
        return;
    }

    $s3 = new S3($app->awsBin);
    try {
        $head = $s3->headObject($profile, $bucket, $key);
    } catch (\Throwable $e) {
        http_response_code(404);
        header('Content-Type: text/plain; charset=utf-8');
        header('X-Content-Type-Options: nosniff');
        echo 'Cannot access object: ' . $e->getMessage();
        return;
    }

    [$mime, $inlineSafe] = s3Mime($key);
    $disposition = (!$dl && $inlineSafe) ? 'inline' : 'attachment';
    $ctype = ($disposition === 'inline') ? $mime : 'application/octet-stream';

    $base = $key;
    $slash = strrpos($base, '/');
    if ($slash !== false) {
        $base = substr($base, $slash + 1);
    }
    $filename = preg_replace('/[\r\n"\\\\]+/', '_', $base);

    header('Content-Type: ' . $ctype);
    header('Content-Disposition: ' . $disposition . '; filename="' . $filename . '"');
    if ($head['ContentLength'] > 0) {
        header('Content-Length: ' . $head['ContentLength']);
    }
    // Defense in depth: never let object bytes run script in our origin, never sniff text
    // as html. No script-src at all (default-src 'none') => any rendered doc is inert.
    header('X-Content-Type-Options: nosniff');
    header("Content-Security-Policy: default-src 'none'; img-src 'self' data:; style-src 'unsafe-inline'");
    header('Cache-Control: no-store');

    if (!$s3->streamObject($profile, $bucket, $key)) {
        // headers already sent; nothing more we can do but stop
        return;
    }
}

function s3Download(App $app): void
{
    $b = $app->jsonBody();
    $profile = trim((string) ($b['profile'] ?? ''));
    $bucket = trim((string) ($b['bucket'] ?? ''));
    $prefix = (string) ($b['prefix'] ?? '');
    if ($profile === '' || $bucket === '') {
        $app->fail(400, 'Missing profile or bucket.');
    }
    $jobId = bin2hex(random_bytes(8));
    $res = (new S3($app->awsBin))->startDownload($profile, $bucket, $prefix, $app->downloadsRoot, $jobId);
    $app->json(['ok' => true, 'job' => $res['job'], 'dest' => $res['dest']]);
}

function s3DownloadStatus(App $app): void
{
    $job = trim((string) ($app->jsonBody()['job'] ?? ''));
    if (!preg_match('/^[a-f0-9]{16}$/', $job)) {
        $app->fail(400, 'Bad job id.');
    }
    $app->json(['ok' => true] + (new S3($app->awsBin))->downloadStatus($job));
}

/**
 * Delete an AWS profile from ~/.aws/credentials (and ~/.aws/config). The client-supplied name is
 * only ever matched as a section name (string equality), never used to build a path — so there is
 * no traversal. Each file is backed up to .bak before its atomic rewrite (CredentialsFile::save),
 * and the read-modify-write runs under the credentials lock so a concurrent refresh can't clobber it.
 */
function deleteProfile(App $app): void
{
    $name = trim((string) ($app->jsonBody()['profile'] ?? ''));
    if ($name === '') {
        $app->fail(400, 'Missing profile name.');
    }
    if ($name === 'default') {
        $app->fail(400, 'The [default] profile cannot be deleted here.');
    }

    $removed = $app->withCredentialsLock(function () use ($app, $name): array {
        $out = [];
        if (is_file($app->credentialsPath)) {
            $cred = new CredentialsFile($app->credentialsPath);
            $n = $cred->removeProfile($name);
            if ($n > 0) {
                $cred->save();
                $out['credentials'] = $n;
            }
        }
        // The config file names profiles [profile <name>] — match only that form (never a bare
        // [name], which in config is a non-profile section), whitespace-tolerant.
        if (is_file($app->awsConfigPath)) {
            $cfg = new CredentialsFile($app->awsConfigPath);
            $n = $cfg->removeConfigProfile($name);
            if ($n > 0) {
                $cfg->save();
                $out['config'] = $n;
            }
        }
        return $out;
    });

    if (empty($removed)) {
        $app->fail(404, "Profile '{$name}' was not found in the credentials or config file.");
    }
    $app->json(['ok' => true, 'profile' => $name, 'removed' => $removed]);
}

/** @return array{0:string,1:bool} [mime, inline-safe] */
function s3Mime(string $key): array
{
    $ext = strtolower(pathinfo($key, PATHINFO_EXTENSION));
    $img = [
        'png' => 'image/png', 'jpg' => 'image/jpeg', 'jpeg' => 'image/jpeg', 'gif' => 'image/gif',
        'webp' => 'image/webp', 'bmp' => 'image/bmp', 'svg' => 'image/svg+xml', 'ico' => 'image/x-icon',
        'avif' => 'image/avif', 'tif' => 'image/tiff', 'tiff' => 'image/tiff',
    ];
    if (isset($img[$ext])) {
        return [$img[$ext], true];
    }
    if ($ext === 'pdf') {
        return ['application/pdf', true];
    }
    // Everything text-like is served as text/plain (so e.g. .html is shown as source, never
    // rendered) and previewed as text.
    $text = ['txt', 'log', 'md', 'markdown', 'csv', 'tsv', 'json', 'xml', 'yml', 'yaml', 'ini',
        'conf', 'cfg', 'env', 'sh', 'sql', 'js', 'ts', 'css', 'html', 'htm', 'php', 'py', 'rb',
        'go', 'java', 'c', 'h', 'cpp', 'toml', 'properties', 'gitignore'];
    if (in_array($ext, $text, true)) {
        return ['text/plain; charset=utf-8', true];
    }
    return ['application/octet-stream', false];
}

// ---- task attachments ------------------------------------------------------

function taskUpload(App $app): void
{
    $taskId = trim((string) ($_POST['task_id'] ?? ''));
    if ($taskId === '') {
        $app->fail(400, 'Missing task id.');
    }
    if (!isset($_FILES['file']) || !is_array($_FILES['file'])) {
        $app->fail(400, 'No file uploaded.');
    }
    $f = $_FILES['file'];
    $err = (int) ($f['error'] ?? UPLOAD_ERR_NO_FILE);
    if ($err !== UPLOAD_ERR_OK) {
        $hint = ($err === UPLOAD_ERR_INI_SIZE || $err === UPLOAD_ERR_FORM_SIZE) ? ' (file too large)' : '';
        $app->fail(400, 'Upload failed' . $hint . '.');
    }
    $meta = $app->tasks->addAttachment(
        $taskId,
        (string) $f['tmp_name'],
        (string) ($f['name'] ?? 'file'),
        (string) ($f['type'] ?? ''),
        (int) ($f['size'] ?? 0)
    );
    $app->json(['ok' => true, 'attachment' => $meta]);
}

function taskFile(App $app): void
{
    $taskId = trim((string) ($_GET['task_id'] ?? ''));
    $fileId = trim((string) ($_GET['file_id'] ?? ''));
    $dl = (string) ($_GET['dl'] ?? '') === '1';
    if ($taskId === '' || $fileId === '') {
        http_response_code(400);
        header('Content-Type: text/plain; charset=utf-8');
        echo 'Missing parameters.';
        return;
    }
    $att = $app->tasks->attachment($taskId, $fileId);
    if ($att === null) {
        http_response_code(404);
        header('Content-Type: text/plain; charset=utf-8');
        header('X-Content-Type-Options: nosniff');
        echo 'Attachment not found.';
        return;
    }
    serveLocalFile($att['path'], (string) ($att['meta']['filename'] ?? 'file'), $dl);
}

/** Stream a local file with the same script-free hardening as the S3 object viewer. */
function serveLocalFile(string $path, string $displayName, bool $dl): void
{
    [$mime, $inlineSafe] = s3Mime($displayName);
    $disposition = (!$dl && $inlineSafe) ? 'inline' : 'attachment';
    $ctype = ($disposition === 'inline') ? $mime : 'application/octet-stream';
    $filename = preg_replace('/[\r\n"\\\\]+/', '_', $displayName);

    header('Content-Type: ' . $ctype);
    header('Content-Disposition: ' . $disposition . '; filename="' . $filename . '"');
    $size = @filesize($path);
    if ($size !== false) {
        header('Content-Length: ' . $size);
    }
    header('X-Content-Type-Options: nosniff');
    header("Content-Security-Policy: default-src 'none'; img-src 'self' data:; style-src 'unsafe-inline'");
    header('Cache-Control: no-store');

    $fh = @fopen($path, 'rb');
    if ($fh === false) {
        http_response_code(500);
        return;
    }
    while (!feof($fh)) {
        echo fread($fh, 1 << 16);
        flush();
    }
    fclose($fh);
}

// ---- Mattermost integration ------------------------------------------------

/** Secret-free view of the Mattermost config for the browser (never leaks the token). */
function mattermostSettingsView(App $app): array
{
    $m = $app->store->mattermost();
    return [
        'base_url' => $m['base_url'],
        'team' => $m['team'],
        'checkin_channel' => $m['checkin_channel'],
        'checkout_channel' => $m['checkout_channel'],
        'has_token' => $m['token'] !== '',
        'channels_resolved' => $m['checkin_channel_id'] !== '' && $m['checkout_channel_id'] !== '',
        'configured' => $m['base_url'] !== '' && $m['token'] !== '',
        'checkout_show_hours' => $m['checkout_show_hours'],
        // @Claude intake config (never the token; the listener reads that server-side).
        'intake_enabled' => $m['intake_enabled'],
        'intake_tag' => $m['intake_tag'],
        'intake_project' => $m['intake_project'],
        'intake_channel' => $m['intake_channel'],
        // Optional Claude interpretation via the local `claude` CLI (no API key). Report whether the
        // CLI is found, so the UI can warn if it isn't.
        'intake_llm' => $m['intake_llm'],
        'claude_available' => (new ClaudeCli())->isAvailable(),
        'claude_bin' => (new ClaudeCli())->bin(),
        // Read-only colleague auto-responder (status replies; titles only).
        'autoresponder_enabled' => $m['autoresponder_enabled'],
        // Allowlist (usernames) for the weekly-HOURS command, joined for the text input.
        'autoresponder_week_allow' => implode(', ', $m['autoresponder_week_allow']),
    ];
}

/**
 * Health of the @Claude intake listener for the status dot. Returns the heartbeat state and whether
 * intake is enabled/configured — no token, no message content ever leaves here.
 *
 * @return array<string,mixed>
 */
function mattermostListenerView(App $app): array
{
    $st = $app->store->listenerStatus();
    $m = $app->store->mattermost();
    return [
        'running' => $st['running'],
        'state' => $st['state'], // connecting|connected|disabled|error|stale|stopped
        'age' => $st['age'],
        'error' => $st['error'],
        'intake_enabled' => $m['intake_enabled'],
        'intake_tag' => $m['intake_tag'],
        'autoresponder_enabled' => $m['autoresponder_enabled'],
        'configured' => $m['base_url'] !== '' && $m['token'] !== '',
    ];
}

/**
 * (Re)start the listener if it isn't already heartbeating. The daemon is a flock singleton, so a
 * duplicate launch is harmless. Spawns a fixed binary with fixed redirects — no client input ever
 * reaches the shell.
 */
function mattermostListenerStart(App $app): void
{
    $st = $app->store->listenerStatus();
    if ($st['running']) {
        $app->json(['ok' => true, 'already' => true, 'state' => $st['state']]);
    }
    $bin = $app->projectDir . '/bin/mm-listen';
    if (!is_file($bin)) {
        $app->fail(500, 'Listener binary not found.');
    }
    $log = $app->configDir . '/mm-listen.log';
    $cmd = 'nohup ' . escapeshellarg(PHP_BINARY) . ' ' . escapeshellarg($bin)
         . ' >> ' . escapeshellarg($log) . ' 2>&1 &';
    exec($cmd);
    $app->json(['ok' => true, 'started' => true]);
}

/** Stop the listener (best-effort, by the pid it recorded). The pid is cast to int — never shelled raw. */
function mattermostListenerStop(App $app): void
{
    $pid = (int) @file_get_contents($app->configDir . '/mm-listen.pid');
    if ($pid > 1) {
        if (function_exists('posix_kill')) {
            @posix_kill($pid, 15); // SIGTERM → clean shutdown
        } else {
            exec('kill ' . $pid . ' 2>/dev/null');
        }
    }
    $app->json(['ok' => true, 'stopped' => true]);
}

/** Validate the token (GET /users/me) and resolve + cache both channel ids. */
function mattermostTest(App $app): void
{
    $m = $app->store->mattermost();
    $mm = new Mattermost($m);
    if (!$mm->isConfigured()) {
        $app->fail(400, 'Set the server URL and access token, save, then test.');
    }
    $me = $mm->me();
    $checkinId = $mm->resolveChannelId($m['team'], $m['checkin_channel']);
    $checkoutId = $mm->resolveChannelId($m['team'], $m['checkout_channel']);
    $app->store->saveMattermostChannelIds($checkinId, $checkoutId);
    $app->json([
        'ok' => true,
        'username' => (string) ($me['username'] ?? '(unknown)'),
        'checkin_channel_id' => $checkinId,
        'checkout_channel_id' => $checkoutId,
    ]);
}

/**
 * Build the digest for $which ('checkin'|'checkout'). With {"preview":true} returns the composed
 * message WITHOUT posting (so the operator reviews it first — the manual-trigger model they chose);
 * otherwise posts it to the mapped channel and returns the post id.
 */
function mattermostPost(App $app, string $which): void
{
    $m = $app->store->mattermost();
    $channelName = $which === 'checkin' ? $m['checkin_channel'] : $m['checkout_channel'];
    $body = $app->jsonBody();

    // Per-checkout "include worked hours" override. The client may flip the toggle in the preview
    // (`include_hours`); absent that, fall back to the operator's saved default. (Check-in has no
    // hours, so this only affects check-out.)
    $includeHours = array_key_exists('include_hours', $body)
        ? (bool) $body['include_hours']
        : $m['checkout_show_hours'];

    // Which day's check-out: 0 = today, -1 = yesterday (for when a check-out was missed). Check-out
    // only; clamped to the last fortnight. Check-in is always "now", so it ignores this.
    $dayOffset = $which === 'checkout' ? max(-14, min(0, (int) ($body['day_offset'] ?? 0))) : 0;

    // Preview: return the freshly-composed digest WITHOUT posting, so the operator reviews (and
    // can edit) it first. This is the only path that runs for a preview request.
    if (!empty($body['preview'])) {
        $app->json([
            'ok' => true,
            'preview' => true,
            'channel' => $channelName,
            'message' => $which === 'checkin' ? MattermostDigest::checkin($app->tasks) : MattermostDigest::checkout($app->tasks, $includeHours, $dayOffset),
            'include_hours' => $which === 'checkout' ? $includeHours : null,
            'day_offset' => $which === 'checkout' ? $dayOffset : null,
            'configured' => (new Mattermost($m))->isConfigured(),
        ]);
    }

    // Send: post the operator-reviewed message from the client if present (they may have edited
    // it in the preview), otherwise (re)build it. The operator is trusted, so their text is sent
    // as-is — only length is bounded (Mattermost rejects oversized posts anyway).
    $message = isset($body['message']) && is_string($body['message']) && trim($body['message']) !== ''
        ? trim($body['message'])
        : ($which === 'checkin' ? MattermostDigest::checkin($app->tasks) : MattermostDigest::checkout($app->tasks, $includeHours, $dayOffset));
    if ($message === '') {
        $app->fail(400, 'Nothing to post.');
    }
    if (mb_strlen($message) > 16383) {
        $app->fail(400, 'Message is too long to post (16,383 character limit).');
    }

    $mm = new Mattermost($m);
    if (!$mm->isConfigured()) {
        $app->fail(400, 'Mattermost is not configured — open settings and add your token.');
    }
    $cachedId = $which === 'checkin' ? $m['checkin_channel_id'] : $m['checkout_channel_id'];
    $channelId = $cachedId;
    if ($channelId === '') {
        // Resolve on demand and cache it, so subsequent posts skip the extra lookup.
        $channelId = $mm->resolveChannelId($m['team'], $channelName);
        if ($which === 'checkin') {
            $app->store->saveMattermostChannelIds($channelId, $m['checkout_channel_id']);
        } else {
            $app->store->saveMattermostChannelIds($m['checkin_channel_id'], $channelId);
        }
    }
    $postId = $mm->post($channelId, $message);
    $app->json(['ok' => true, 'posted' => true, 'channel' => $channelName, 'post_id' => $postId]);
}

/**
 * Preview/post the WEEKLY SUMMARY digest (hours vs the daily target, by day & project — for a teammate
 * reconciling against Redmine). Defaults to LAST week. Posts to the check-in channel (same destination
 * as the daily status). Preview returns the text without posting so the operator can review/edit/copy.
 */
function mattermostWeekly(App $app): void
{
    $m = $app->store->mattermost();
    $set = $app->store->settings();
    $body = $app->jsonBody();
    $off = (int) ($body['week_offset'] ?? -1); // default: last completed week

    $summary = $app->tasks->weekSummary(
        time() + $off * 7 * 86400,
        null,
        (float) $set['daily_target_hours'],
        (bool) $set['target_weekdays_only']
    );

    if (!empty($body['preview'])) {
        $app->json([
            'ok' => true,
            'preview' => true,
            'channel' => $m['checkin_channel'],
            'message' => MattermostDigest::weekly($summary),
            'configured' => (new Mattermost($m))->isConfigured(),
        ]);
    }

    // Send the operator-reviewed text (they may have edited it), else (re)build it.
    $message = isset($body['message']) && is_string($body['message']) && trim($body['message']) !== ''
        ? trim($body['message'])
        : MattermostDigest::weekly($summary);
    if ($message === '') {
        $app->fail(400, 'Nothing to post.');
    }
    if (mb_strlen($message) > 16383) {
        $app->fail(400, 'Message is too long to post (16,383 character limit).');
    }
    $mm = new Mattermost($m);
    if (!$mm->isConfigured()) {
        $app->fail(400, 'Mattermost is not configured — open settings and add your token.');
    }
    $channelName = $m['checkin_channel'];
    $channelId = $m['checkin_channel_id'];
    if ($channelId === '') {
        $channelId = $mm->resolveChannelId($m['team'], $channelName);
        $app->store->saveMattermostChannelIds($channelId, $m['checkout_channel_id']);
    }
    $postId = $mm->post($channelId, $message);
    $app->json(['ok' => true, 'posted' => true, 'channel' => $channelName, 'post_id' => $postId]);
}

// ---- projects + Redmine reconciliation -------------------------------------

/**
 * Secret-free view of the project registry + Redmine instances for the browser. Returns the
 * projects (name + Redmine URL + derived host/identifier) and, per Redmine host referenced by a
 * project (or already holding a key), only whether a key is stored — NEVER the key itself.
 *
 * @return array{projects:array<int,array<string,mixed>>,instances:array<int,array{host:string,has_key:bool}>}
 */
function projectsView(App $app): array
{
    $instances = $app->store->redmineInstances();
    $hosts = [];
    $views = [];
    foreach ($app->store->projects() as $p) {
        $host = $p['redmine']['host'] ?? '';
        if ($host !== '') {
            $hosts[$host] = true;
        }
        $views[] = [
            'id' => $p['id'],
            'name' => $p['name'],
            'redmine_url' => $p['redmine_url'],
            'redmine_host' => $host,
            'redmine_identifier' => $p['redmine']['identifier'] ?? '',
        ];
    }
    // Surface every host a project points at, plus any host that already has a stored key.
    foreach (array_keys($instances) as $h) {
        $hosts[$h] = true;
    }
    $instanceViews = [];
    foreach (array_keys($hosts) as $h) {
        $instanceViews[] = ['host' => $h, 'has_key' => isset($instances[$h])];
    }
    return ['projects' => $views, 'instances' => $instanceViews];
}

/**
 * Reconcile dashboard-tracked hours against Redmine-logged hours for a week (default LAST week, the
 * one the operator files in Redmine). Per project: ✅ when the hours YOU logged in Redmine for that
 * Mon–Sun window are ≥ the hours the dashboard tracked. Read-only; the API key is read server-side
 * and only ever sent to its own host (Redmine client: TLS-verified, no redirects).
 */
function redmineStatus(App $app): void
{
    $off = (int) ($_GET['week'] ?? -1); // default: last completed week
    $off = max(-520, min(520, $off));   // clamp to ±10y so an absurd ?week can't skew strtotime
    $set = $app->store->settings();
    $summary = $app->tasks->weekSummary(
        time() + $off * 7 * 86400,
        null,
        (float) $set['daily_target_hours'],
        (bool) $set['target_weekdays_only']
    );

    // Dashboard hours per project NAME for this week (project_totals seconds → hours).
    $dash = [];
    foreach ($summary['project_totals'] as $pt) {
        $dash[(string) $pt['project']] = ((int) $pt['seconds']) / 3600;
    }
    // Inclusive Redmine date window: Monday .. Sunday of the summarised week.
    $from = substr((string) $summary['week_start'], 0, 10);
    $to = date('Y-m-d', strtotime((string) $summary['week_end']) - 1);

    $instances = $app->store->redmineInstances();
    $userIds = [];  // host => int|false, resolved once per host (false = lookup failed; don't retry)
    $userErr = [];  // host => the resolve error message, reused for every project on that host
    $rows = [];
    foreach ($app->store->projects() as $p) {
        $name = $p['name'];
        $dashHours = round($dash[$name] ?? 0.0, 2);
        $row = [
            'id' => $p['id'],
            'name' => $name,
            'redmine_url' => $p['redmine_url'],
            'dashboard_hours' => $dashHours,
        ];
        $ref = $p['redmine'];
        if ($ref === null) {
            $row['status'] = 'no_url';
        } elseif (($instances[$ref['host']]['api_key'] ?? '') === '') {
            $row['status'] = 'no_key';
            $row['host'] = $ref['host'];
        } elseif ($dashHours <= 0) {
            $row['status'] = 'none'; // nothing tracked this week → nothing to reconcile
        } else {
            $client = new Redmine($ref['base_url'], $instances[$ref['host']]['api_key']);
            // Resolve the user once per host, caching the FAILURE too (false) so a bad key / down host
            // isn't re-probed (a full timeout) for every project that points at it.
            if (!array_key_exists($ref['host'], $userIds)) {
                try {
                    $userIds[$ref['host']] = $client->currentUserId();
                } catch (\Throwable $e) {
                    $userIds[$ref['host']] = false;
                    $userErr[$ref['host']] = $e->getMessage();
                }
            }
            if ($userIds[$ref['host']] === false) {
                $row['status'] = 'error';
                $row['error'] = $userErr[$ref['host']] ?? 'Could not identify the Redmine user for this key.';
            } else {
                try {
                    $logged = round($client->loggedHours($ref['identifier'], $userIds[$ref['host']], $from, $to), 2);
                    $row['redmine_hours'] = $logged;
                    // Small epsilon so e.g. 8.00 vs 7.999 rounding doesn't read as short.
                    $row['status'] = ($logged + 0.001 >= $dashHours) ? 'ok' : 'short';
                    $row['short_hours'] = max(0.0, round($dashHours - $logged, 2));
                } catch (\Throwable $e) {
                    $row['status'] = 'error';
                    $row['error'] = $e->getMessage();
                }
            }
        }
        $rows[] = $row;
    }

    $app->json([
        'ok' => true,
        'week_label' => $summary['week_label'],
        'week_offset' => $off,
        'from' => $from,
        'to' => $to,
        'projects' => $rows,
    ]);
}
