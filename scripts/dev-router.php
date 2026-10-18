<?php
/** Роутер для локального php -S: php -S 127.0.0.1:8793 -t public scripts/dev-router.php (имитирует .htaccess). */
$p = rawurldecode((string)parse_url($_SERVER['REQUEST_URI'], PHP_URL_PATH));
$f = __DIR__ . '/../public' . $p;
if ($p !== '/' && is_file($f) && !str_ends_with($p, '.php')) {
    if (preg_match('#^/(start|de|woerter|admin|schreiben|hub|epmathe-demo)\.html$#', $p)) { http_response_code(404); return true; }
    return false;     // обычный файл отдаёт сам встроенный сервер
}
require __DIR__ . '/../public/index.php';
