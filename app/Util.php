<?php
/** Мелкие помощники из server.py. */
class Util
{
    public const CODE_ABC = 'ABCDEFGHJKLMNPQRSTUVWXYZ23456789';
    public const AVATAR_MAX = 63;
    public const LANGS = ['ru', 'en'];

    public static function str($v, int $n): string
    {
        if (is_array($v) || is_object($v)) { return ''; }
        return mb_substr(trim((string)($v ?? '')), 0, $n);
    }

    public static function int($v, int $lo = 0, int $hi = 1000000000): int
    {
        if (!is_numeric($v)) { return $lo; }
        return max($lo, min($hi, (int)$v));
    }

    public static function sha(string $s): string { return hash('sha256', $s); }

    public static function newCode(string $table, string $col, int $n): string
    {
        while (true) {
            $code = '';
            for ($i = 0; $i < $n; $i++) { $code .= self::CODE_ABC[random_int(0, strlen(self::CODE_ABC) - 1)]; }
            if (!Db::one("SELECT 1 FROM `$table` WHERE `$col`=?", [$code])) { return $code; }
        }
    }

    public static function localDay(?int $ts = null): int
    {
        $ts = $ts ?? time();
        static $tz = null;
        $tz = $tz ?? new DateTimeZone('Europe/Vienna');
        return intdiv($ts + $tz->getOffset(new DateTime('@' . $ts)), 86400);
    }

    public const TZ_COUNTRY = [
        'Europe/Vienna' => 'Австрия', 'Europe/Berlin' => 'Германия', 'Europe/Zurich' => 'Швейцария', 'Europe/Kiev' => 'Украина',
        'Europe/Kyiv' => 'Украина', 'Europe/Uzhgorod' => 'Украина', 'Europe/Zaporozhye' => 'Украина', 'Europe/Moscow' => 'Россия',
        'Europe/Samara' => 'Россия', 'Europe/Volgograd' => 'Россия', 'Europe/Kaliningrad' => 'Россия', 'Asia/Yekaterinburg' => 'Россия',
        'Asia/Omsk' => 'Россия', 'Asia/Novosibirsk' => 'Россия', 'Asia/Krasnoyarsk' => 'Россия', 'Asia/Irkutsk' => 'Россия',
        'Asia/Vladivostok' => 'Россия', 'Europe/Minsk' => 'Беларусь', 'Asia/Almaty' => 'Казахстан', 'Asia/Qostanay' => 'Казахстан',
        'Asia/Aqtobe' => 'Казахстан', 'Asia/Aqtau' => 'Казахстан', 'Asia/Oral' => 'Казахстан', 'Asia/Tashkent' => 'Узбекистан',
        'Asia/Samarkand' => 'Узбекистан', 'Asia/Bishkek' => 'Кыргызстан', 'Asia/Dushanbe' => 'Таджикистан', 'Asia/Ashgabat' => 'Туркменистан',
        'Asia/Tbilisi' => 'Грузия', 'Asia/Yerevan' => 'Армения', 'Asia/Baku' => 'Азербайджан', 'Europe/Chisinau' => 'Молдова',
        'Europe/Riga' => 'Латвия', 'Europe/Vilnius' => 'Литва', 'Europe/Tallinn' => 'Эстония', 'Europe/Warsaw' => 'Польша',
        'Europe/Prague' => 'Чехия', 'Europe/Bratislava' => 'Словакия', 'Europe/Budapest' => 'Венгрия', 'Europe/Belgrade' => 'Сербия',
        'Europe/Sofia' => 'Болгария', 'Europe/Bucharest' => 'Румыния', 'Europe/Istanbul' => 'Турция', 'Europe/Rome' => 'Италия',
        'Europe/Paris' => 'Франция', 'Europe/London' => 'Великобритания', 'Europe/Madrid' => 'Испания', 'Europe/Amsterdam' => 'Нидерланды',
        'Europe/Brussels' => 'Бельгия', 'Europe/Stockholm' => 'Швеция', 'Europe/Helsinki' => 'Финляндия', 'Europe/Athens' => 'Греция',
        'Asia/Jerusalem' => 'Израиль', 'Asia/Dubai' => 'ОАЭ', 'America/New_York' => 'США', 'America/Chicago' => 'США',
        'America/Los_Angeles' => 'США', 'America/Toronto' => 'Канада', 'Asia/Shanghai' => 'Китай', 'Asia/Tokyo' => 'Япония',
        'Asia/Kolkata' => 'Индия', 'Asia/Seoul' => 'Корея',
    ];

    public static function country($tz): string
    {
        $tz = (string)($tz ?? '');
        if (isset(self::TZ_COUNTRY[$tz])) { return self::TZ_COUNTRY[$tz]; }
        return $tz === '' ? '—' : explode('/', $tz)[0];
    }

    public static function parseUa(string $ua): array
    {
        if (preg_match('/iPad|Tablet/', $ua) || (str_contains($ua, 'Android') && !str_contains($ua, 'Mobile'))) { $dev = 'Планшет'; }
        elseif (preg_match('/Mobile|iPhone|Android/', $ua)) { $dev = 'Телефон'; }
        else { $dev = 'Компьютер'; }
        $os = preg_match('/iPhone|iPad|iPod/', $ua) ? 'iOS' : (str_contains($ua, 'Android') ? 'Android' : (str_contains($ua, 'Windows') ? 'Windows'
            : (str_contains($ua, 'Mac OS X') ? 'macOS' : (str_contains($ua, 'CrOS') ? 'ChromeOS' : (str_contains($ua, 'Linux') ? 'Linux' : 'Другая')))));
        $br = str_contains($ua, 'Edg/') ? 'Edge' : (str_contains($ua, 'OPR/') ? 'Opera' : (str_contains($ua, 'YaBrowser') ? 'Яндекс'
            : (str_contains($ua, 'SamsungBrowser') ? 'Samsung' : (str_contains($ua, 'Firefox/') ? 'Firefox'
            : ((str_contains($ua, 'Chrome/') || str_contains($ua, 'CriOS')) ? 'Chrome' : (str_contains($ua, 'Safari/') ? 'Safari' : 'Другой'))))));
        return [$dev, $os, $br];
    }

    public static function visitorKey(string $ip, string $ua): string
    {
        $salt = hash_hmac('sha256', (string)self::localDay(), Auth::secret(), true);   // соль меняется каждый день, IP не сохраняется
        return substr(hash('sha256', $salt . $ip . '|' . $ua), 0, 16);
    }

    /** Краткая сводка по прогрессу (JSON из браузера). $data — массив, полученный json_decode(..., true). */
    public static function progressSummary($data): array
    {
        $d = is_array($data) ? $data : [];
        $days = is_array($d['days'] ?? null) ? $d['days'] : [];
        $ms = is_array($d['ms'] ?? null) ? $d['ms'] : [];
        $b = is_array($d['b'] ?? null) ? $d['b'] : [];
        $answers = 0; $ok = 0;
        foreach ($ms as $m) {
            $answers += (is_array($m) && count($m) > 1) ? (int)$m[1] : 0;
            $ok += (is_array($m) && $m) ? (int)$m[0] : 0;
        }
        $active = [];
        foreach ($days as $k => $v) {
            if (is_numeric($k) && is_array($v) && !empty($v['a'])) { $active[(int)$k] = $v; }
        }
        $plan = is_array($d['plan'] ?? null) ? $d['plan'] : null;
        $learned = 0;
        foreach ($b as $r) { if (is_array($r) && $r && $r[0] >= 3) { $learned++; } }
        return [
            'answers' => $answers, 'ok' => $ok, 'acc' => $answers ? (int)round($ok / $answers * 100) : 0,
            'learned' => $learned, 'started' => count($b), 'active_days' => count($active), 'days' => $active,
            'cur' => $d['cur'] ?? '', 'recent' => is_array($d['recent'] ?? null) ? $d['recent'] : [], 'xp' => $d['xp'] ?? 0,
            'goal' => $d['goal'] ?? 20, 'tour' => !empty($d['tourDone']), 'usets' => count($d['usets'] ?? []),
            'folders' => count($d['folders'] ?? []), 'exam' => ($plan['exam'] ?? null) ?: (($plan['date'] ?? null) ?: ''),
            'ms' => $ms, 'l' => is_array($d['l'] ?? null) ? $d['l'] : [], 'theme' => $d['theme'] ?? '', 'accent' => $d['accent'] ?? '',
        ];
    }

    /** id карточки → слово; id раздела → название (из словаря BOOK, вынесенного из woerter.html). */
    public static function bookWords(): array
    {
        static $res = null;
        if ($res !== null) { return $res; }
        $res = ['w' => [], 'sec' => []];
        try {
            $raw = Pages::woerter()['book'];
            $i = strpos($raw, '['); $j = strrpos($raw, ']');
            $book = $i !== false && $j !== false ? json_decode(substr($raw, $i, $j - $i + 1), true) : [];
            foreach ($book ?: [] as $s) {
                $res['sec'][$s['id']] = $s['id'] . ' ' . $s['title'];
                foreach ($s['sets'] as $st) {
                    foreach ($st['items'] as $n => $it) { $res['w'][$s['id'] . '/' . $st['id'] . '/' . $n] = [$it[0], $it[1]]; }
                }
            }
        } catch (Throwable $e) { error_log('book parse: ' . $e->getMessage()); }
        return $res;
    }

    public static function jd($s)
    {
        return json_decode((string)$s);   // объекты остаются объектами: {} не превращается в []
    }
}
