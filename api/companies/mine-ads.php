<?php

declare(strict_types=1);

require_once dirname(__DIR__) . '/config/database.php';

ini_set('display_errors', '0');

header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store');
header('X-Content-Type-Options: nosniff');

if ($_SERVER['REQUEST_METHOD'] !== 'GET') {
    header('Allow: GET');
    http_response_code(405);
    echo json_encode(['error' => 'Method not allowed']);
    exit;
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

$userId = $_SESSION['user_id'] ?? null;

if (!is_int($userId)) {
    http_response_code(401);
    echo json_encode(['error' => 'Authentication required']);
    exit;
}

try {
    $db = database();

    $query = $db->prepare(
        "SELECT a.id, a.title, a.short_description, a.description,
                a.salary, a.location, a.working_time,
                a.company_id, a.category_id,
                c.name AS company, cat.name AS category
         FROM advertisements AS a
         JOIN companies AS c ON c.id = a.company_id
         LEFT JOIN categories AS cat ON cat.id = a.category_id
         JOIN company_members AS cm ON cm.company_id = a.company_id
         WHERE cm.person_id = ?
           AND cm.role = 'recruiter'
         ORDER BY a.id DESC"
    );

    $query->execute([$userId]);

    echo json_encode(
        $query->fetchAll(),
        JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE
    );
} catch (Throwable $e) {
    error_log('Recruiter advertisements lookup failed; SQLSTATE ' . $e->getCode());

    http_response_code(503);
    echo json_encode(['error' => 'Unable to load recruiter advertisements']);
}