<?php

declare(strict_types=1);

/**
 * Router for PHP's built-in web server (`php -S 127.0.0.1:8010 -t public router.php`).
 * Real files under public/ (styles.css, app.js) are served directly; everything else is
 * dispatched through the front controller.
 */
$path = parse_url($_SERVER['REQUEST_URI'] ?? '/', PHP_URL_PATH) ?: '/';

if ($path !== '/' && $path !== '/index.php') {
    $candidate = __DIR__ . $path;
    if (is_file($candidate) && !is_dir($candidate)) {
        return false; // let the built-in server serve the static asset
    }
}

require __DIR__ . '/index.php';
