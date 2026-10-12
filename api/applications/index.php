<?php

declare(strict_types=1);

require_once dirname(__DIR__) . '/config/database.php';

ini_set('display_errors', '0');

header('Content-Type: application/json; charset=utf-8');
header('X-Content-Type-Options: nosniff');
header('Cache-Control: no-store');

function respond(int $status, array $data): never
{
    http_response_code($status);
    echo json_encode(
        $data,
        JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE
    );
    exit;
}

// Cette route accepte GET et POST.
$method = $_SERVER['REQUEST_METHOD'];

if (!in_array($method, ['GET', 'POST'], true)) {
    header('Allow: GET, POST');
    respond(405, ['error' => 'Method not allowed']);
}

// Vérification de la session.
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
    respond(401, ['error' => 'Authentication required']);
}

// Protection CSRF uniquement pour les requêtes qui modifient les données.
if ($method !== 'GET') {
    $csrf = $_SERVER['HTTP_X_CSRF_TOKEN'] ?? '';
    $expected = $_SESSION['csrf_token'] ?? '';

    if (
        !is_string($csrf)
        || !is_string($expected)
        || $expected === ''
        || !hash_equals($expected, $csrf)
    ) {
        respond(403, ['error' => 'Invalid CSRF token']);
    }
}

$userId = $_SESSION['user_id'];

// GET : consulter les candidatures de l'utilisateur connecté.
if ($method === 'GET') {
    try {
        $db = database();

        $stmt = $db->prepare(
            'SELECT
                a.id,
                a.advertisement_id,
                a.message,
                a.application_date,
                a.status,
                ad.title AS advertisement_title,
                ad.location,
                c.name AS company
             FROM applications AS a
             JOIN advertisements AS ad
                ON ad.id = a.advertisement_id
             JOIN companies AS c
                ON c.id = ad.company_id
             WHERE a.person_id = ?
             ORDER BY a.application_date DESC'
        );

        $stmt->execute([$userId]);

        respond(200, [
            'applications' => $stmt->fetchAll(PDO::FETCH_ASSOC),
        ]);
    } catch (Throwable $e) {
        error_log('Applications listing failure: ' . get_class($e));
        respond(500, ['error' => 'Unable to retrieve applications']);
    }
}

// Le corps doit être du JSON.
$contentType = strtolower(
    trim(explode(';', $_SERVER['CONTENT_TYPE'] ?? '')[0])
);

if ($contentType !== 'application/json') {
    respond(415, ['error' => 'Use application/json']);
}

$raw = file_get_contents('php://input', false, null, 0, 65537);

if (strlen($raw) > 65536) {
    respond(413, ['error' => 'Request too large']);
}

try {
    $body = json_decode($raw, false, 32, JSON_THROW_ON_ERROR);
} catch (JsonException) {
    respond(400, ['error' => 'Invalid JSON']);
}

if (!$body instanceof stdClass) {
    respond(422, ['error' => 'JSON object required']);
}

// Validation de l'identifiant de l'annonce.
$advertisementId = $body->advertisement_id ?? null;

if (!is_int($advertisementId) || $advertisementId < 1) {
    respond(422, [
        'error' => 'Validation failed',
        'fields' => [
            'advertisement_id' => 'A positive integer is required',
        ],
    ]);
}

// Validation du message facultatif.
$message = $body->message ?? null;

if ($message !== null && !is_string($message)) {
    respond(422, [
        'error' => 'Validation failed',
        'fields' => ['message' => 'A string or null is required'],
    ]);
}

if (is_string($message)) {
    $message = trim($message);

    if (strlen($message) > 20000) {
        respond(422, [
            'error' => 'Validation failed',
            'fields' => ['message' => 'Maximum length is 20000 bytes'],
        ]);
    }

    if ($message === '') {
        $message = null;
    }
}

try {
    $db = database();

    // Vérifier que l'annonce existe.
    $check = $db->prepare(
        'SELECT id FROM advertisements WHERE id = ?'
    );
    $check->execute([$advertisementId]);

    if (!$check->fetch()) {
        respond(404, ['error' => 'Advertisement not found']);
    }

    // person_id vient exclusivement de la session.
    $insert = $db->prepare(
        'INSERT INTO applications (person_id, advertisement_id, message)
         VALUES (?, ?, ?)'
    );

    $insert->execute([$userId, $advertisementId, $message]);

    respond(201, [
        'id' => (int) $db->lastInsertId(),
        'person_id' => $userId,
        'advertisement_id' => $advertisementId,
        'message' => $message,
        'status' => 'pending',
    ]);
} catch (PDOException $e) {
    error_log('Applications database failure; SQLSTATE ' . $e->getCode());

    // La contrainte UNIQUE empêche les candidatures en double.
    if ($e->getCode() === '23000') {
        respond(409, ['error' => 'You have already applied to this advertisement']);
    }

    respond(503, ['error' => 'Database temporarily unavailable']);
} catch (Throwable $e) {
    error_log('Applications failure: ' . get_class($e));
    respond(500, ['error' => 'Internal server error']);
}