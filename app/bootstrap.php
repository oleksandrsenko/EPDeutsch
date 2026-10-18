<?php
/**
 * Общая загрузка: конфиг, подключение к базе, автозагрузка классов.
 * Требуется PHP 8.0+ с расширениями pdo_mysql, curl, mbstring.
 */
if (PHP_VERSION_ID < 80000) {
    http_response_code(500);
    header('Content-Type: application/json; charset=utf-8');
    exit(json_encode(['error' => 'Нужен PHP 8.0 или новее (сейчас ' . PHP_VERSION . ')']));
}
mb_internal_encoding('UTF-8');
date_default_timezone_set('UTC');

define('EPD_APP', __DIR__);
define('EPD_ROOT', dirname(__DIR__));              // папка, где лежат app/, config/, storage/
if (!defined('EPD_PUBLIC')) {
    define('EPD_PUBLIC', is_dir(EPD_ROOT . '/public') ? EPD_ROOT . '/public' : EPD_ROOT);
}
define('EPD_STORAGE', EPD_ROOT . '/storage');
define('EPD_AUDIO', EPD_PUBLIC . '/audio');       // кэш озвучки; Apache отдаёт файлы напрямую (Range, кэш)

spl_autoload_register(function ($c) {
    $f = EPD_APP . '/' . $c . '.php';
    if (preg_match('/^[A-Za-z]+$/', $c) && is_file($f)) { require $f; }
});

/** Настройка: config/config.local.php (или файл из переменной EPD_CONFIG), поверх значения по умолчанию. */
function cfg(string $key, $default = null)
{
    static $conf = null;
    if ($conf === null) {
        $file = getenv('EPD_CONFIG') ?: EPD_ROOT . '/config/config.local.php';
        $conf = is_file($file) ? (require $file) : [];
        if (!is_array($conf)) { $conf = []; }
    }
    $v = $conf;
    foreach (explode('.', $key) as $part) {
        if (!is_array($v) || !array_key_exists($part, $v)) { return $default; }
        $v = $v[$part];
    }
    return ($v === '' || $v === null) ? $default : $v;
}

set_exception_handler(function (Throwable $e) {
    if ($e instanceof ApiError) { Http::fail($e); }
    error_log('EPD error: ' . $e->getMessage() . ' @ ' . $e->getFile() . ':' . $e->getLine());
    if (!headers_sent()) { http_response_code(500); header('Content-Type: application/json; charset=utf-8'); }
    echo json_encode(['error' => 'Ошибка сервера'] + (cfg('debug') ? ['debug' => $e->getMessage()] : []), JSON_UNESCAPED_UNICODE);
    exit;
});

if (!function_exists('array_is_list')) {          // PHP 8.0
    function array_is_list(array $a): bool { return $a === [] || array_keys($a) === range(0, count($a) - 1); }
}
