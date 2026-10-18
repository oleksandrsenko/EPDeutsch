<?php
/** Пароли, сессии, ограничения по частоте. Пароли хранятся только как хеш и нигде не логируются. */
class Auth
{
    public const SESSION_DAYS = 30;
    public const EMAIL_RE = '/^[^@\s]{1,64}@[^@\s]{1,190}\.[^@\s]{2,}$/';
    private const LEGACY_RE = '/^[0-9a-f]{32}\$[0-9a-f]{64}$/';   // PBKDF2-SHA256 из Python-версии: "соль$хеш"
    private const LEGACY_ITER = 310000;

    public static function secret(): string
    {
        $s = (string)cfg('secret', '');
        return $s !== '' ? $s : hash('sha256', 'epd|' . (string)cfg('db.pass', '') . '|' . (string)cfg('db.name', ''));
    }

    // ---------- пароли ----------
    public static function hashPw(string $pw): string
    {
        $algo = defined('PASSWORD_ARGON2ID') ? PASSWORD_ARGON2ID : PASSWORD_BCRYPT;
        $h = password_hash($pw, $algo);
        return $h !== false ? $h : password_hash($pw, PASSWORD_BCRYPT);
    }

    public static function isLegacy(string $stored): bool { return (bool)preg_match(self::LEGACY_RE, $stored); }

    public static function checkPw(string $pw, string $stored): bool
    {
        if (self::isLegacy($stored)) {
            [$salt, $h] = explode('$', $stored);
            return hash_equals($h, hash_pbkdf2('sha256', $pw, hex2bin($salt), self::LEGACY_ITER, 64, false));
        }
        return password_verify($pw, $stored);
    }

    /** Нужно ли перехешировать (старый формат или устаревшие параметры). */
    public static function needsRehash(string $stored): bool
    {
        if (self::isLegacy($stored)) { return true; }
        $algo = defined('PASSWORD_ARGON2ID') ? PASSWORD_ARGON2ID : PASSWORD_BCRYPT;
        return password_needs_rehash($stored, $algo);
    }

    /** Те же правила, что проверяет форма на сайте. Возвращает текст ошибки или null. */
    public static function pwProblem(string $pw): ?string
    {
        if (mb_strlen($pw) < 8) { return 'Пароль должен быть не короче 8 символов'; }
        if (mb_strlen($pw) > 200) { return 'Слишком длинный пароль'; }
        if (!preg_match('/\p{Lu}/u', $pw)) { return 'Добавь в пароль хотя бы одну заглавную букву'; }
        return null;
    }

    /** Временный пароль для сброса админом: 14 символов, без похожих (0/O, 1/l/I), гарантированно проходит pwProblem. */
    public static function tempPassword(int $len = 14): string
    {
        $up = 'ABCDEFGHJKLMNPQRSTUVWXYZ'; $lo = 'abcdefghijkmnopqrstuvwxyz'; $di = '23456789';
        $all = $up . $lo . $di;
        $pick = fn(string $s) => $s[random_int(0, strlen($s) - 1)];
        $chars = [$pick($up), $pick($lo), $pick($di)];
        while (count($chars) < $len) { $chars[] = $pick($all); }
        for ($i = count($chars) - 1; $i > 0; $i--) { $j = random_int(0, $i); [$chars[$i], $chars[$j]] = [$chars[$j], $chars[$i]]; }
        return implode('', $chars);
    }

    // ---------- сессии ----------
    public static function startSession(int $uid): string
    {
        $tok = rtrim(strtr(base64_encode(random_bytes(32)), '+/', '-_'), '=');
        Db::run('INSERT INTO sessions(token,user_id,expires) VALUES(?,?,?)', [Util::sha($tok), $uid, time() + self::SESSION_DAYS * 86400]);
        return Http::sessionCookie($tok, self::SESSION_DAYS * 86400);
    }

    public static function currentUser(): ?array
    {
        static $cache = [];
        $tok = $_COOKIE['sid'] ?? '';
        if (!is_string($tok) || $tok === '') { return null; }
        $h = Util::sha($tok);
        if (!array_key_exists($h, $cache)) {
            $cache[$h] = Db::one('SELECT u.* FROM sessions s JOIN users u ON u.id=s.user_id WHERE s.token=? AND s.expires>? AND u.blocked=0', [$h, time()]);
        }
        return $cache[$h];
    }

    public static function dropSessionCookie(): string { return Http::sessionCookie('', 0); }

    // ---------- ограничения по частоте (замена памяти процесса Python) ----------
    /** Запись сразу фиксируется: запрос дальше может завершиться ошибкой и откатиться, а счётчик должен остаться. */
    public static function hit(string $bucket): void
    {
        Db::run('INSERT INTO rate_limit(bucket,ts) VALUES(?,?)', [substr($bucket, 0, 120), time()]);
        Db::commit();
    }

    public static function count(string $bucket, int $window): int
    {
        return (int)Db::val('SELECT COUNT(*) FROM rate_limit WHERE bucket=? AND ts>?', [substr($bucket, 0, 120), time() - $window]);
    }

    public static function clear(string $bucket): void { Db::run('DELETE FROM rate_limit WHERE bucket=?', [substr($bucket, 0, 120)]); }

    /** Неудачные попытки: не больше 8 за 10 минут на ключ. */
    public static function tooMany(string $key): bool { return self::count('f:' . $key, 600) >= 8; }
    public static function fail(string $key): void { self::hit('f:' . $key); }
}
