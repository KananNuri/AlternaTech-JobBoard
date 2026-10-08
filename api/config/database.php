<?php
declare(strict_types=1);
function envValue(string $key, string $default = ''): string {
    static $values = null;
    if ($values === null) {
        $values = [];
        $file = dirname(__DIR__, 2) . '/.env';
        if (is_file($file)) foreach (file($file, FILE_IGNORE_NEW_LINES) as $line) {
            $line = trim($line);
            if ($line === '' || str_starts_with($line, '#') || !str_contains($line, '=')) continue;
            [$name, $value] = explode('=', $line, 2);
            $values[trim($name)] = trim(trim($value), "\"'");
        }
    }
    $value = getenv($key);
    return $value === false ? ($values[$key] ?? $default) : $value;
}
function database(): PDO {
    static $pdo = null;
    if ($pdo instanceof PDO) return $pdo;
    $host = envValue('DB_HOST', '127.0.0.1');
    $port = envValue('DB_PORT', '3306');
    $name = envValue('DB_NAME', 'alternatech');
    if (!preg_match('/^[a-zA-Z0-9_.-]+$/', $host) || !ctype_digit($port) || !preg_match('/^[a-zA-Z0-9_]+$/', $name)) throw new RuntimeException('Invalid database configuration');
    return $pdo = new PDO("mysql:host=$host;port=$port;dbname=$name;charset=utf8mb4", envValue('DB_USER', 'alternatech'), envValue('DB_PASSWORD'), [
        PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
        PDO::ATTR_EMULATE_PREPARES => false,
    ]);
}
