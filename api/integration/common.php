<?php
declare(strict_types=1);
require_once dirname(__DIR__) . '/config/database.php';

function reply(int $status, mixed $data = null): never {
    http_response_code($status);
    header('Content-Type: application/json; charset=utf-8');
    header('Cache-Control: no-store');
    header('X-Content-Type-Options: nosniff');
    if ($status !== 204) echo json_encode($data, JSON_THROW_ON_ERROR | JSON_INVALID_UTF8_SUBSTITUTE | JSON_UNESCAPED_UNICODE);
    exit;
}
function methods(array $allowed): string {
    $method = $_SERVER['REQUEST_METHOD'];
    if (!in_array($method, $allowed, true)) { header('Allow: ' . implode(', ', $allowed)); reply(405, ['error'=>'Method not allowed']); }
    return $method;
}
function jsonBody(int $limit = 65536): array {
    if (strtolower(trim(explode(';', $_SERVER['CONTENT_TYPE'] ?? '')[0])) !== 'application/json') reply(415, ['error'=>'Use application/json']);
    $raw = file_get_contents('php://input', false, null, 0, $limit + 1);
    if ($raw === false || strlen($raw) > $limit) reply(413, ['error'=>'Request too large']);
    try { $body = json_decode($raw, false, 32, JSON_THROW_ON_ERROR); }
    catch (JsonException) { reply(400, ['error'=>'Invalid JSON']); }
    if (!$body instanceof stdClass) reply(422, ['error'=>'JSON object required']);
    return (array)$body;
}
function startSession(): void {
    if (session_status() === PHP_SESSION_ACTIVE) return;
    ini_set('session.use_strict_mode', '1'); ini_set('session.use_only_cookies', '1');
    session_name('alternatech_login');
    session_set_cookie_params(['lifetime'=>0,'path'=>'/','secure'=>!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS']!=='off','httponly'=>true,'samesite'=>'Lax']);
    session_start();
}
function csrf(): void {
    startSession();
    $token = $_SERVER['HTTP_X_CSRF_TOKEN'] ?? '';
    if (!is_string($token) || empty($_SESSION['csrf_token']) || !hash_equals($_SESSION['csrf_token'], $token)) reply(403, ['error'=>'Invalid CSRF token']);
}
function profileFor(PDO $db, array $user): int {
    // One canonical account, one profile. Charles's unchanged handlers use the profile ID.
    $q = $db->prepare('INSERT INTO people (user_id,firstname,lastname,email,phone) VALUES (?,?,?,?,?) ON DUPLICATE KEY UPDATE firstname=VALUES(firstname),lastname=VALUES(lastname),email=VALUES(email),phone=VALUES(phone)');
    $q->execute([$user['id'],$user['firstname'],$user['lastname'],$user['email'],$user['phone'] ?? null]);
    $q = $db->prepare('SELECT id FROM people WHERE user_id=?'); $q->execute([$user['id']]);
    // Never hand out profile 0: if the upsert hit another unique key, the account has no linked profile.
    $person = (int)$q->fetchColumn();
    if ($person < 1) throw new RuntimeException('Account has no linked profile');
    return $person;
}
function signIn(PDO $db, array $user): void {
    startSession(); session_regenerate_id(true);
    $_SESSION = ['account_id'=>(int)$user['id'],'user_id'=>profileFor($db,$user),'csrf_token'=>bin2hex(random_bytes(32)),'expires'=>time()+3600];
}
function currentUser(bool $required = true): ?array {
    startSession();
    if (!is_int($_SESSION['account_id'] ?? null) || ($_SESSION['expires'] ?? 0) <= time()) {
        if (isset($_SESSION['account_id']) || isset($_SESSION['user_id'])) $_SESSION=[];
        if ($required) reply(401,['error'=>'Authentication required']);
        return null;
    }
    $q = database()->prepare('SELECT id,firstname,lastname,email,phone,role FROM users WHERE id=?'); $q->execute([$_SESSION['account_id']]);
    $user=$q->fetch();
    if (!$user) { $_SESSION=[]; if ($required) reply(401,['error'=>'Authentication required']); return null; }
    $user['person_id']=(int)$_SESSION['user_id'];
    // Aliases preserve Charles's login UI and canonical names support Google/account pages.
    $user['first_name']=$user['firstname']; $user['last_name']=$user['lastname'];
    return $user;
}
function adminUser(): array { $user=currentUser(); if ($user['role']!=='admin') reply(403,['error'=>'Administrator access required']); return $user; }
function positiveId(mixed $value): int {
    if ((!is_string($value) && !is_int($value)) || !preg_match('/^[1-9][0-9]*$/D',(string)$value) || (float)$value>4294967295) reply(422,['error'=>'Positive integer ID required']);
    return (int)$value;
}
function textField(array $body, string $key, int $limit, bool $required = true): ?string {
    $v=$body[$key] ?? null;
    if (!$required && ($v===null || $v==='')) return null;
    if (!is_string($v) || !preg_match('//u',$v) || ($required && trim($v)==='') || iconv_strlen($v,'UTF-8')>$limit) reply(422,['error'=>"Invalid $key (maximum $limit characters)"]);
    return trim($v);
}
function pagination(): array {
    foreach (['page'=>1000000,'per_page'=>100] as $key=>$max) {
        if (isset($_GET[$key]) && (!is_string($_GET[$key]) || !ctype_digit($_GET[$key]) || (int)$_GET[$key]<1 || (int)$_GET[$key]>$max)) reply(422,['error'=>"Invalid $key"]);
    }
    $page=(int)($_GET['page']??1); $size=(int)($_GET['per_page']??20);
    return [$page,$size,($page-1)*$size];
}
function dbFailure(Throwable $e): never {
    error_log('Integration failure: '.get_class($e).' '.$e->getCode());
    if ($e instanceof PDOException && $e->getCode()==='23000') reply(409,['error'=>'Record conflicts with an existing record or database relationship']);
    reply(503,['error'=>'Service temporarily unavailable']);
}
