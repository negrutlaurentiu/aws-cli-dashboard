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
            $app->json(['ok' => true, 'now' => time(), 'accounts' => accountViews($app)]);
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
        (int) $account['duration_seconds']
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

function maskKey(string $key): string
{
    if (strlen($key) <= 8) {
        return $key;
    }
    return substr($key, 0, 4) . str_repeat('•', max(0, strlen($key) - 8)) . substr($key, -4);
}

function renderPage(App $app): never
{
    $template = file_get_contents(__DIR__ . '/../src/page.html');
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
    header("Content-Security-Policy: default-src 'self'; style-src 'self' 'unsafe-inline'; img-src 'self' data:");
    header('Referrer-Policy: no-referrer');
    echo $html;
    exit;
}
