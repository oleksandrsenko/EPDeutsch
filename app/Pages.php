<?php
/** HTML-страницы, словарь Wörter (BOOK выносится в отдельный файл с хэшем) и генерируемые файлы. Порт static_asset/route_static. */
class Pages
{
    const YEAR = 31536000;
    const OLD_PAGES = ['/index.html', '/en', '/epd', '/terms', '/privacy', '/start', '/admin.html'];
    const FILES = ['/' => 'start.html', '/de' => 'de.html', '/schreiben' => 'schreiben.html', '/admin' => 'admin.html'];

    /** woerter.html → страница без словаря, js словаря и его хэш. Результат кэшируется в storage/cache. */
    public static function woerter(): array
    {
        static $r = null;
        if ($r !== null) { return $r; }
        $src = EPD_PUBLIC . '/woerter.html';
        $mt = (int)filemtime($src);
        $cache = EPD_STORAGE . '/cache/woerter.' . $mt . '.php';
        if (is_file($cache)) { return $r = (require $cache); }
        $html = (string)file_get_contents($src);
        $book = ''; $hash = '';
        if (preg_match('/<script>\n(const BOOK = [^\n]*;)\n/', $html, $m, PREG_OFFSET_CAPTURE)) {
            $book = $m[1][0] . "\n";
            $hash = substr(sha1($book), 0, 12);
            $html = substr($html, 0, $m[0][1]) . '<script src="/assets/book.' . $hash . ".js\"></script>\n<script>\n" . substr($html, $m[0][1] + strlen($m[0][0]));
        }
        $r = ['html' => $html, 'book' => $book, 'hash' => $hash, 'mt' => $mt];
        if (!is_dir(dirname($cache))) { @mkdir(dirname($cache), 0775, true); }
        foreach (glob(EPD_STORAGE . '/cache/woerter.*.php') ?: [] as $old) { @unlink($old); }
        @file_put_contents($cache, '<?php return ' . var_export($r, true) . ';');
        return $r;
    }

    /** Нужные блоки <!--B:ru-->…<!--/B:ru--> остаются, остальные уходят; язык страницы и адрес сайта подставляются. */
    private static function woerterPage(string $site): string
    {
        $pg = self::woerter();
        $html = preg_replace_callback('/<!--B:(\w+)-->(.*?)<!--\/B:\1-->/s', fn($m) => $m[1] === 'ru' ? $m[2] : '', $pg['html']);
        $html = preg_replace('/<html lang="ru">/', '<html lang="ru">', $html, 1);
        $meta = '';
        foreach (['google-site-verification' => 'google_site_verification', 'yandex-verification' => 'yandex_verification', 'msvalidate.01' => 'bing_verification'] as $n => $k) {
            if ($v = cfg($k)) { $meta .= '<meta name="' . $n . '" content="' . str_replace('"', '', (string)$v) . "\">\n"; }
        }
        return str_replace('%SITE%', $site, str_replace("<!--VERIFY-->\n", $meta, $html));
    }

    private static function send(string $body, string $ctype, string $cache, bool $head = false): void
    {
        $etag = '"' . substr(sha1($body), 0, 20) . '"';
        header('ETag: ' . $etag);
        header('Cache-Control: ' . $cache);
        header('X-Content-Type-Options: nosniff');
        header('Referrer-Policy: strict-origin-when-cross-origin');
        header('Vary: Accept-Encoding');
        if (($_SERVER['HTTP_IF_NONE_MATCH'] ?? '') === $etag) { http_response_code(304); return; }
        header('Content-Type: ' . $ctype);
        if (strlen($body) > 1024 && str_contains($_SERVER['HTTP_ACCEPT_ENCODING'] ?? '', 'gzip') && function_exists('gzencode')) {
            $body = gzencode($body, 6);
            header('Content-Encoding: gzip');
        }
        header('Content-Length: ' . strlen($body));
        if (!$head) { echo $body; }
    }

    private static function redirect(string $to, int $code = 302): void
    {
        http_response_code($code);
        header('Location: ' . $to);
    }

    public static function serve(string $path, bool $head = false): void
    {
        $site = Http::siteUrl();
        if ($path === '/woerter') { self::send(self::woerterPage($site), 'text/html; charset=utf-8', 'no-cache', $head); return; }
        if ($path !== '/' && str_ends_with($path, '/') && ($path === '/woerter/' || isset(self::FILES[rtrim($path, '/')]))) { self::redirect(rtrim($path, '/'), 301); return; }
        if (in_array($path, self::OLD_PAGES, true)) { self::redirect('/', $path === '/index.html' ? 301 : 302); return; }
        if (isset(self::FILES[$path])) {
            $fp = EPD_PUBLIC . '/' . self::FILES[$path];
            if (!is_file($fp)) { self::notFound(); return; }
            self::send((string)file_get_contents($fp), 'text/html; charset=utf-8', 'no-cache', $head);
            return;
        }
        if (preg_match('#^/assets/book\.([0-9a-f]{12})\.js$#', $path, $m)) {
            $pg = self::woerter();
            self::send($pg['book'], 'text/javascript; charset=utf-8', $m[1] === $pg['hash'] ? 'public, max-age=' . self::YEAR . ', immutable' : 'no-cache', $head);
            return;
        }
        if ($path === '/assets/book.js') { self::redirect('/assets/book.' . self::woerter()['hash'] . '.js'); header('Cache-Control: no-cache'); return; }
        if ($path === '/robots.txt') { self::send("User-agent: *\nDisallow: /\n", 'text/plain; charset=utf-8', 'public, max-age=86400', $head); return; }
        if ($path === '/sitemap.xml') {
            $day = gmdate('Y-m-d', self::woerter()['mt']);
            $urls = [['/', '1.0', 'weekly'], ['/en', '0.9', 'weekly'], ['/epd', '0.9', 'monthly'], ['/terms', '0.3', 'yearly'], ['/privacy', '0.3', 'yearly']];
            $x = '<?xml version="1.0" encoding="UTF-8"?>' . "\n" . '<urlset xmlns="http://www.sitemaps.org/schemas/sitemap/0.9">' . "\n";
            foreach ($urls as [$u, $pr, $cf]) { $x .= "  <url><loc>$site$u</loc><lastmod>$day</lastmod><changefreq>$cf</changefreq><priority>$pr</priority></url>\n"; }
            self::send($x . "</urlset>\n", 'application/xml; charset=utf-8', 'public, max-age=86400', $head);
            return;
        }
        if ($path === '/manifest.webmanifest') {
            $body = json_encode(['name' => 'Deutsch lernen', 'short_name' => 'Deutsch', 'description' => 'Личная платформа: подготовка к EPD и свободный немецкий.',
                'lang' => 'ru', 'start_url' => '/', 'scope' => '/', 'display' => 'standalone', 'background_color' => '#F6F7FB', 'theme_color' => '#7E6FB8',
                'icons' => [['src' => '/icon-192.png', 'sizes' => '192x192', 'type' => 'image/png'], ['src' => '/icon-512.png', 'sizes' => '512x512', 'type' => 'image/png'],
                            ['src' => '/icon.svg', 'sizes' => 'any', 'type' => 'image/svg+xml']]], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
            self::send($body, 'application/manifest+json; charset=utf-8', 'public, max-age=86400', $head);
            return;
        }
        self::notFound();
    }

    public static function notFound(): void
    {
        http_response_code(404);
        header('Content-Type: text/html; charset=utf-8');
        echo '<!doctype html><meta charset=utf-8><title>404</title><body style="font-family:system-ui;text-align:center;padding:12vh 20px;color:#282E3E"><h1>404</h1><p>Страница не найдена.</p><a href="/">На главную</a>';
    }
}
