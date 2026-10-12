<?php

declare(strict_types=1);

require_once dirname(__DIR__) . '/config/database.php';

ini_set('display_errors', '0');

header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store');
header('X-Content-Type-Options: nosniff');

function respond(int $status, array $data): never
{
    http_response_code($status);
    echo json_encode(
        $data,
        JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE
    );
    exit;
}

$method = $_SERVER['REQUEST_METHOD'];

if (!in_array($method, ['GET', 'PATCH'], true)) {
    header('Allow: GET, PATCH');
    respond(405, ['error' => 'Method not allowed']);
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
    respond(401, ['error' => 'Authentication required']);
}

$userId = $_SESSION['user_id'];

// PATCH : modifier le statut d'une candidature reçue.
if ($method === 'PATCH') {
    // Vérification CSRF.
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

    // Vérifier le type et la taille du corps.
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

    $applicationId = $body->application_id ?? null;
    $status = $body->status ?? null;

    if (!is_int($applicationId) || $applicationId < 1) {
        respond(422, [
            'error' => 'application_id must be a positive integer',
        ]);
    }

    $allowedStatuses = ['pending', 'reviewed', 'accepted', 'rejected'];

    if (!is_string($status) || !in_array($status, $allowedStatuses, true)) {
        respond(422, [
            'error' => 'Invalid status',
            'allowed_statuses' => $allowedStatuses,
        ]);
    }

    try {
        $db = database();

        // Vérifier que le recruteur gère bien l'entreprise
        // associée à cette candidature.
        $check = $db->prepare(
            "SELECT a.id
             FROM applications AS a
             JOIN advertisements AS ad
                ON ad.id = a.advertisement_id
             JOIN company_members AS cm
                ON cm.company_id = ad.company_id
             WHERE a.id = ?
               AND cm.person_id = ?
               AND cm.role = 'recruiter'"
        );

        $check->execute([$applicationId, $userId]);

        if (!$check->fetch()) {
            respond(404, ['error' => 'Application not found']);
        }

        $update = $db->prepare(
            'UPDATE applications SET status = ? WHERE id = ?'
        );
        $update->execute([$status, $applicationId]);

        respond(200, [
            'application_id' => $applicationId,
            'status' => $status,
        ]);
    } catch (Throwable $e) {
        error_log('Application status update failure: ' . get_class($e));
        respond(500, ['error' => 'Unable to update application status']);
    }
}

try {
    $db = database();

    // Renvoyer uniquement les candidatures des entreprises
    // pour lesquelles l'utilisateur possède le rôle recruiter.
    $stmt = $db->prepare(
        "SELECT
            a.id AS application_id,
            a.advertisement_id,
            a.person_id AS candidate_id,
            a.message,
            a.application_date,
            a.status,
            ad.title AS advertisement_title,
            c.id AS company_id,
            c.name AS company
         FROM applications AS a
         JOIN advertisements AS ad
            ON ad.id = a.advertisement_id
         JOIN companies AS c
            ON c.id = ad.company_id
         JOIN company_members AS cm
            ON cm.company_id = c.id
         WHERE cm.person_id = ?
           AND cm.role = 'recruiter'
         ORDER BY a.application_date DESC"
    );

    $stmt->execute([$userId]);

    respond(200, [
        'applications' => $stmt->fetchAll(PDO::FETCH_ASSOC),
    ]);
} catch (Throwable $e) {
    error_log('Company applications listing failure: ' . get_class($e));
    respond(500, ['error' => 'Unable to retrieve company applications']);
}