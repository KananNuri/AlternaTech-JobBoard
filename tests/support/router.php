<?php
declare(strict_types=1);
require_once __DIR__.'/database-guard.php';
try {
    if (PHP_SAPI !== 'cli-server' || getenv('ISOLATED_TEST_SERVER') !== '1' || in_array((int)($_SERVER['SERVER_PORT'] ?? 0), [0,8080,8086], true)) throw new RuntimeException('Dedicated test server required');
    $db = testDatabaseGuard('ISOLATED_TEST_SERVER');
    if (parse_url($_SERVER['REQUEST_URI'], PHP_URL_PATH) === '/__isolation/db') {
        header('Content-Type: application/json'); header('Cache-Control: no-store');
        echo json_encode(['select_database'=>$db->query('SELECT DATABASE()')->fetchColumn(), 'test_run_id'=>testRunId()], JSON_THROW_ON_ERROR);
        return true;
    }
    return require dirname(__DIR__, 2).'/api/integration-router.php';
} catch (Throwable $e) {
    http_response_code(503); header('Content-Type: application/json');
    echo '{"error":"Isolated test server configuration refused"}'; return true;
}
