<?php
declare(strict_types=1);

require_once dirname(__DIR__) . '/config/database.php';

ini_set('display_errors', '0');
header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store');
header('X-Content-Type-Options: nosniff');

function loginReply(int $status, array $data): never
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
    loginReply(405, ['error' => 'Method not allowed']);
}

$contentType = strtolower(trim(explode(';', $_SERVER['CONTENT_TYPE'] ?? '')[0]));
if ($contentType !== 'application/json') {
    loginReply(415, ['error' => 'Use application/json']);
}

$raw = file_get_contents('php://input', false, null, 0, 8193);
if (strlen($raw) > 8192) {
    loginReply(413, ['error' => 'Request too large']);
}

try {
    $input = json_decode($raw, true, 16, JSON_THROW_ON_ERROR);
} catch (JsonException) {
    loginReply(400, ['error' => 'Invalid JSON']);
}

if (
    !is_array($input)
    || !isset($input['email'], $input['password'])
    || !is_string($input['email'])
    || !is_string($input['password'])
    || !filter_var($input['email'], FILTER_VALIDATE_EMAIL)
    || strlen($input['email']) > 255
    || $input['password'] === ''
    || strlen($input['password']) > 1024
) {
    loginReply(422, ['error' => 'Valid email and password are required']);
}

try {
    $db = database();
    $query = $db->prepare(
        'SELECT id, first_name, last_name, email, password, role
         FROM people
         WHERE email = ?
         LIMIT 1'
    );
    $query->execute([trim($input['email'])]);
    $user = $query->fetch();

    if (!$user || !password_verify($input['password'], $user['password'])) {
        loginReply(401, ['error' => 'Invalid email or password']);
    }

    if (password_needs_rehash($user['password'], PASSWORD_DEFAULT)) {
        $update = $db->prepare('UPDATE people SET password = ? WHERE id = ?');
        $update->execute([
            password_hash($input['password'], PASSWORD_DEFAULT),
            $user['id']
        ]);
    }

    ini_set('session.use_strict_mode', '1');
    ini_set('session.use_only_cookies', '1');
    session_name('alternatech_login');
    session_set_cookie_params([
        'lifetime' => 0,
        'path' => '/',
        'secure' => !empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off',
        'httponly' => true,
        'samesite' => 'Lax'
    ]);
    session_start();
    session_regenerate_id(true);

    $_SESSION['user_id'] = (int) $user['id'];
    $_SESSION['csrf_token'] = bin2hex(random_bytes(32));

    unset($user['password']);

    loginReply(200, [
        'message' => 'Login successful',
        'user' => $user,
        'csrf_token' => $_SESSION['csrf_token']
    ]);
} catch (Throwable $e) {
    error_log('Password login failure: ' . get_class($e));
    loginReply(503, ['error' => 'Authentication temporarily unavailable']);
}