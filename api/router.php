<?php
declare(strict_types=1);

// Every supported entry point uses canonical auth and session revalidation.
if (!defined('ALTERNATECH_INTEGRATED_ROUTER')) return require __DIR__.'/integration-router.php';

// Development server only: document root must be public/, never the repository root.
$path = parse_url($_SERVER['REQUEST_URI'], PHP_URL_PATH);

if (preg_match('~^/api/auth/login/?$~', $path)) {
    require __DIR__ . '/auth/login.php';
    return true;
}

if (preg_match('~^/api/auth/session/?$~', $path)) {
    require __DIR__ . '/auth/session.php';
    return true;
}

if (preg_match('~^/api/auth/logout/?$~', $path)) {
    require __DIR__ . '/auth/logout.php';
    return true;
}

if (preg_match('~^/api/auth/google(?:/|$)~', $path)) {
    require __DIR__ . '/auth/google/index.php';
    return true;
}

if (preg_match('~^/api/companies/mine/?$~', $path)) {
    require __DIR__ . '/companies/mine.php';
    return true;
}

if (preg_match('~^/api/companies/mine/ads/?$~', $path)) {
    require __DIR__ . '/companies/mine-ads.php';
    return true;
}

if (preg_match('~^/api/companies/mine/applications/?$~', $path)) {
    require __DIR__ . '/applications/company.php';
    return true;
}

if (preg_match('~^/api/applications/mine/?$~', $path)) {
    require __DIR__ . '/applications/index.php';
    return true;
}

if (preg_match('~^/api/ads/([1-9][0-9]*)/applications/?$~', $path, $matches)) {
    $_GET['advertisement_id'] = $matches[1];
    require __DIR__ . '/applications/index.php';
    return true;
}

if (preg_match('~^/api/ads(?:/|$)~', $path)) {
    require __DIR__ . '/ads/index.php';
    return true;
}

if (str_starts_with($path, '/api/')) {
    header('Content-Type: application/json');
    http_response_code(404);
    echo '{"error":"Route not found"}';
    return true;
}

$public = realpath(dirname(__DIR__) . '/public');
$file = realpath($public . '/' . rawurldecode($path));

if ($path === '/' || ($file !== false && str_starts_with($file, $public . DIRECTORY_SEPARATOR) && is_file($file))) {
    return false;
}

http_response_code(404);
header('Content-Type: text/plain; charset=utf-8');
echo 'Not found';
return true;
