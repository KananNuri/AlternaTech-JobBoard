<?php
declare(strict_types=1);

require_once dirname(__DIR__) . '/config/database.php';

ini_set('display_errors', '0');

header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store');
header('X-Content-Type-Options: nosniff');

function sessionReply(int $status, array $data): never
{
    http_response_code($status);
    echo json_encode(
        $data,
        JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE
    );
    exit;
}

if ($_SERVER['REQUEST_METHOD'] !== 'GET') {
    header('Allow: GET');
    sessionReply(405, ['error' => 'Method not allowed']);
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

if (!isset($_SESSION['user_id']) || !is_int($_SESSION['user_id'])) {
    sessionReply(200, ['user' => null]);
}

try {
    $query = database()->prepare(
        'SELECT id, first_name, last_name, email, role
         FROM people
         WHERE id = ?
         LIMIT 1'
    );
    $query->execute([$_SESSION['user_id']]);
    $user = $query->fetch();

    if (!$user) {
        $_SESSION = [];
        session_destroy();
        sessionReply(200, ['user' => null]);
    }

    sessionReply(200, [
        'user' => $user,
        'csrf_token' => $_SESSION['csrf_token'] ?? null,
    ]);
} catch (Throwable $e) {
    error_log('Login session failure: ' . get_class($e));
    sessionReply(503, ['error' => 'Session temporarily unavailable']);
}