<?php
/** Единая точка входа: /api/* и страницы. Файлы, которые есть на диске (статика, аудио, data/*.json), отдаёт сам Apache. */
ini_set('display_errors', '0');
define('EPD_PUBLIC', __DIR__);
$app = is_file(__DIR__ . '/../app/bootstrap.php') ? __DIR__ . '/../app' : __DIR__ . '/app';
require $app . '/bootstrap.php';

$method = $_SERVER['REQUEST_METHOD'] ?? 'GET';
$path = rawurldecode((string)parse_url($_SERVER['REQUEST_URI'] ?? '/', PHP_URL_PATH));
$path = preg_replace('#/+#', '/', $path);

if ($path === '/api' || str_starts_with($path, '/api/')) {
    Api::handle($method, $path);
} else {
    Pages::serve($path, $method === 'HEAD');
}
