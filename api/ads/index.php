<?php
declare(strict_types=1);
require_once dirname(__DIR__) . '/config/database.php';
require_once __DIR__ . '/validation.php';
ini_set('display_errors', '0');
header('Content-Type: application/json; charset=utf-8');
header('X-Content-Type-Options: nosniff');
header('Cache-Control: no-store');
function respond(int $status, mixed $data): never {
    http_response_code($status);
    if ($status !== 204) echo json_encode($data, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE);
    exit;
}
$path = parse_url($_SERVER['REQUEST_URI'], PHP_URL_PATH);
if (!preg_match('~^/api/ads(?:/([1-9][0-9]*))?/?$~', $path, $match)) respond(404, ['error'=>'Route not found']);
$id = isset($match[1]) ? filter_var($match[1], FILTER_VALIDATE_INT, ['options'=>['min_range'=>1,'max_range'=>4294967295]]) : null;
if ($id === false) respond(404,['error'=>'Advertisement not found']);
$method = $_SERVER['REQUEST_METHOD'];
$allowed = $id === null ? ['GET','POST'] : ['GET','PUT','PATCH','DELETE'];
if (!in_array($method, $allowed, true)) { header('Allow: '.implode(', ', $allowed)); respond(405,['error'=>'Method not allowed']); }
if ($method !== 'GET') {
    $token = envValue('ADS_WRITE_TOKEN');
    if (strlen($token) < 32) respond(503,['error'=>'Advertisement writes are disabled']);
    if (!hash_equals('Bearer '.$token, $_SERVER['HTTP_AUTHORIZATION'] ?? '')) { header('WWW-Authenticate: Bearer'); respond(401,['error'=>'Valid write token required']); }
}
$data = [];
if (in_array($method,['POST','PUT','PATCH'],true)) {
    if (strtolower(trim(explode(';', $_SERVER['CONTENT_TYPE'] ?? '')[0])) !== 'application/json') respond(415,['error'=>'Use application/json']);
    $raw = file_get_contents('php://input', false, null, 0, 65537);
    if (strlen($raw) > 65536) respond(413,['error'=>'Request too large']);
    try { $object = json_decode($raw, false, 32, JSON_THROW_ON_ERROR); } catch (JsonException) { respond(400,['error'=>'Invalid JSON']); }
    if (!$object instanceof stdClass) respond(422,['error'=>'JSON object required']);
    [$data,$errors] = validateAd((array)$object, $method === 'PATCH');
    if ($errors) respond(422,['error'=>'Validation failed','fields'=>$errors]);
}
try {
    $db = database();
    $select = 'SELECT a.*, c.name AS company, cat.name AS category FROM advertisements a JOIN companies c ON c.id=a.company_id LEFT JOIN categories cat ON cat.id=a.category_id';
    if ($id !== null) {
        $q = $db->prepare($select.' WHERE a.id=?'); $q->execute([$id]); $ad = $q->fetch();
        if (!$ad) respond(404,['error'=>'Advertisement not found']);
    }
    if ($method === 'GET') respond(200, $id === null ? $db->query($select.' ORDER BY a.id DESC')->fetchAll() : $ad);
    if ($method === 'DELETE') {
        $q=$db->prepare('DELETE FROM advertisements WHERE id=?'); $q->execute([$id]); respond(204,null);
    }
    foreach (['company_id'=>'companies','category_id'=>'categories'] as $field=>$table) if (isset($data[$field])) {
        $q=$db->prepare("SELECT id FROM $table WHERE id=?"); $q->execute([$data[$field]]);
        if (!$q->fetch()) respond(422,['error'=>'Validation failed','fields'=>[$field=>'Referenced record does not exist']]);
    }
    if ($method === 'POST') {
        $fields = array_keys($data);
        $q=$db->prepare('INSERT INTO advertisements ('.implode(',', $fields).') VALUES ('.implode(',',array_fill(0,count($fields),'?')).')');
        $q->execute(array_values($data)); $id=(int)$db->lastInsertId();
        header('Location: /api/ads/'.$id);
    } else {
        if ($method === 'PUT') foreach (['salary','working_time','contract_type','category_id'] as $field) $data[$field] ??= null;
        $q=$db->prepare('UPDATE advertisements SET '.implode(',',array_map(fn($field)=>$field.'=?',array_keys($data))).' WHERE id=?');
        $q->execute([...array_values($data), $id]);
    }
    $q=$db->prepare($select.' WHERE a.id=?'); $q->execute([$id]); respond($method === 'POST' ? 201 : 200,$q->fetch());
} catch (PDOException $e) {
    error_log('Advertisements database failure; SQLSTATE '.$e->getCode());
    if ($e->getCode() === '23000') respond(409,['error'=>'Database relationship conflict']);
    respond(503,['error'=>'Database temporarily unavailable']);
} catch (Throwable $e) {
    error_log('Advertisements failure: '.get_class($e)); respond(500,['error'=>'Internal server error']);
}
