<?php
class Http
{
    public static function ip(): string
    {
        $hdr = cfg('ip_header', 'REMOTE_ADDR');            // за прокси хостинга: HTTP_X_FORWARDED_FOR или HTTP_CF_CONNECTING_IP
        $v = $_SERVER[$hdr] ?? ($_SERVER['REMOTE_ADDR'] ?? '0.0.0.0');
        if ($hdr === 'HTTP_X_FORWARDED_FOR') { $parts = explode(',', $v); $v = trim(end($parts)); }
        return substr(preg_replace('/[^0-9a-fA-F:.]/', '', $v) ?: '0.0.0.0', 0, 45);
    }

    public static function header(string $name): string
    {
        return (string)($_SERVER['HTTP_' . strtoupper(str_replace('-', '_', $name))] ?? '');
    }

    public static function body(): string
    {
        static $raw = null;
        if ($raw === null) {
            $max = 12 * 1024 * 1024;
            if ((int)($_SERVER['CONTENT_LENGTH'] ?? 0) > $max) { throw new ApiError(413, 'Слишком большой запрос'); }
            $raw = (string)file_get_contents('php://input');
            if (strlen($raw) > $max) { throw new ApiError(413, 'Слишком большой запрос'); }
        }
        return $raw;
    }

    /** Тело запроса как ассоциативный массив (для проверок). */
    public static function json(): array
    {
        $raw = self::body();
        if ($raw === '') { return []; }
        $d = json_decode($raw, true);
        if (!is_array($d) || ($d && array_is_list($d))) { throw new ApiError(400, 'Неверный формат данных'); }
        return $d;
    }

    /** То же, но объекты остаются объектами: пустое {} не превращается в [] при сохранении (прогресс, kv, состояние Schreiben). */
    public static function jsonObj(): object
    {
        $raw = self::body();
        $d = $raw === '' ? new stdClass() : json_decode($raw);
        if (!is_object($d)) { throw new ApiError(400, 'Неверный формат данных'); }
        return $d;
    }

    public static function send(int $status, $obj, array $headers = []): void
    {
        if (!headers_sent()) {
            http_response_code($status);
            header('Content-Type: application/json; charset=utf-8');
            header('Cache-Control: no-store');
            foreach ($headers as $h) { header($h, false); }
        }
        echo json_encode($obj, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PARTIAL_OUTPUT_ON_ERROR | JSON_PRESERVE_ZERO_FRACTION);
    }

    public static function fail(ApiError $e): void
    {
        self::send($e->status, ['error' => $e->getMessage()] + $e->extra);
        exit;
    }

    public static function secure(): bool
    {
        return (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off')
            || strtolower(self::header('X-Forwarded-Proto')) === 'https' || ($_SERVER['SERVER_PORT'] ?? '') == 443;
    }

    public static function sessionCookie(string $token, int $maxAge): string
    {
        return 'Set-Cookie: sid=' . $token . '; HttpOnly; SameSite=Lax; Path=/; Max-Age=' . $maxAge . (self::secure() ? '; Secure' : '');
    }

    /** Адрес сайта для canonical и sitemap: site_url из конфига или из заголовков (только для картинок/карты сайта). */
    public static function siteUrl(): string
    {
        $u = rtrim((string)cfg('site_url', ''), '/');
        if ($u) { return $u; }
        $host = preg_replace('/[^A-Za-z0-9.:\-\[\]]/', '', $_SERVER['HTTP_HOST'] ?? 'localhost');
        return (self::secure() ? 'https' : 'http') . '://' . $host;
    }
}
