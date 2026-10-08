<?php
declare(strict_types=1);
// Development server only: document root must be public/, never the repository root.
$path = parse_url($_SERVER['REQUEST_URI'], PHP_URL_PATH);
if (preg_match('~^/api/ads(?:/|$)~', $path)) { require __DIR__.'/ads/index.php'; return true; }
if (str_starts_with($path, '/api/')) {
    header('Content-Type: application/json'); http_response_code(404); echo '{"error":"Route not found"}'; return true;
}
$public = realpath(dirname(__DIR__).'/public');
$file = realpath($public.'/'.rawurldecode($path));
if ($path === '/' || ($file !== false && str_starts_with($file, $public.DIRECTORY_SEPARATOR) && is_file($file))) return false;
http_response_code(404); header('Content-Type: text/plain; charset=utf-8'); echo 'Not found'; return true;
