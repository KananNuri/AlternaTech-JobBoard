<?php
declare(strict_types=1);

ini_set('display_errors', '0');

header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store');
header('X-Content-Type-Options: nosniff');

function logoutReply(int $status, array $data): never
{
    http_response_code($status);
    echo json_encode(
        $data,
        JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE
    );
    exit;
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    header('Allow: POST');
    logoutReply(405, ['error' => 'Method not allowed']);
}

ini_set('session.use_strict_mode', '1');
ini_set('session.use_only_cookies', '1');

session_name('alternatech_login');
session_set_cookie_params([
    'lifetime' => 0,
    'path' => '/',
    'secure' => !empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off',
    'httponly' => true,
    'samesite' => 'Lax',
]);

session_start();

$csrf = $_SERVER['HTTP_X_CSRF_TOKEN'] ?? '';
$expected = $_SESSION['csrf_token'] ?? '';

if (
    !isset($_SESSION['user_id'])
    || !is_string($csrf)
    || !is_string($expected)
    || $expected === ''
    || !hash_equals($expected, $csrf)
) {
    logoutReply(403, ['error' => 'Invalid session or CSRF token']);
}

$_SESSION = [];

if (ini_get('session.use_cookies')) {
    $params = session_get_cookie_params();

    setcookie(session_name(), '', [
        'expires' => time() - 42000,
        'path' => $params['path'],
        'domain' => $params['domain'],
        'secure' => $params['secure'],
        'httponly' => $params['httponly'],
        'samesite' => $params['samesite'] ?? 'Lax',
    ]);
}

session_destroy();

logoutReply(200, ['message' => 'Signed out']);