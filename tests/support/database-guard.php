<?php
declare(strict_types=1);
require_once dirname(__DIR__, 2).'/api/config/database.php';

function testDatabaseName(): string {
    $name = getenv('DB_NAME');
    if (PHP_SAPI !== 'cli' && PHP_SAPI !== 'cli-server') throw new RuntimeException('Test database requires CLI');
    if (!is_string($name) || !preg_match('/^[a-zA-Z0-9_]+_test$/D', $name)) throw new RuntimeException('Explicit DB_NAME ending in _test required; alternatech is forbidden');
    return $name;
}
function testRunId(): string {
    $id = getenv('TEST_RUN_ID');
    if (!is_string($id) || !preg_match('/^[a-f0-9]{32,64}$/D', $id)) throw new RuntimeException('TEST_RUN_ID must be 32-64 random hexadecimal characters');
    return $id;
}
function testDatabaseGuard(string $flag): PDO {
    if (getenv($flag) !== '1') throw new RuntimeException("Explicit $flag=1 required");
    $name = testDatabaseName();
    testRunId();
    $db = database();
    if ($db->query('SELECT DATABASE()')->fetchColumn() !== $name) throw new RuntimeException('Connected database does not match test database');
    return $db;
}
