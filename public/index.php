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

        case $method === 'GET' && $path === '/tasks':
            renderTasks($app);
            break;

        case $method === 'GET' && $path === '/api/tasks':
            $app->json(['ok' => true, 'now' => time(), 'tasks' => $app->tasks->all()]);
            break;

        case $method === 'GET' && $path === '/api/tasks/summary':
            $off = (int) ($_GET['week'] ?? 0);
            $app->json(['ok' => true, 'summary' => $app->tasks->weekSummary(time() + $off * 7 * 86400)]);
            break;

        case $method === 'POST' && $path === '/api/tasks':
            $app->assertCsrf();
            $tb = $app->jsonBody();
            $app->json(['ok' => true, 'task' => $app->tasks->create((string) ($tb['title'] ?? ''), (string) ($tb['description'] ?? ''))]);
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

        case $method === 'POST' && $path === '/api/tasks/upload':
            $app->assertCsrf();
            taskUpload($app);
            break;

        case $method === 'GET' && $path === '/api/tasks/file':
            $app->assertCsrfQuery();
            taskFile($app);
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

function renderTemplate(App $app, string $file, string $csp): never
{
    $template = file_get_contents(__DIR__ . '/../src/' . $file);
    if ($template === false) {
        http_response_code(500);
        echo 'Template missing.';
        exit;
    }
    $html = strtr($template, [
        '__APP_TOKEN__' => htmlspecialchars($app->store->appToken(), ENT_QUOTES),
        '__HOST__' => App::HOST,
        '__PORT__' => (string) App::PORT,
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
