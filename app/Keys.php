<?php
/** Личные ключи ИИ: шифрование в БД (sodium secretbox), проверка формата, выбор ключа для запроса. */
class Keys
{
    const PROVIDERS = ['anthropic', 'openai', 'deepl'];
    const FORMAT = [
        'anthropic' => ['/^sk-ant-[A-Za-z0-9_-]{20,300}$/', 'Ключ Anthropic начинается с sk-ant-'],
        'openai' => ['/^sk-[A-Za-z0-9_-]{20,300}$/', 'Ключ OpenAI начинается с sk-'],
        'deepl' => ['/^[0-9A-Fa-f-]{20,60}(:fx)?$/', 'Ключ DeepL выглядит как xxxxxxxx-xxxx-…:fx'],
    ];
    const NEED_AGAIN = 'Ключ нужно ввести заново';
    const SAVE_PER_HOUR = 12;

    /** Пользователь текущего запроса: Api::needUser() кладёт его сюда, Ai::key() и другие берут оттуда. */
    public static ?array $user = null;
    private static bool $ready = false;

    private static function ensure(): void
    {
        if (self::$ready) { return; }
        self::$ready = true;
        try { Db::val('SELECT 1 FROM user_keys LIMIT 1'); return; } catch (Throwable $e) { /* таблицы нет: создаём */ }
        Db::pdo()->exec('CREATE TABLE IF NOT EXISTS user_keys(
            user_id BIGINT NOT NULL, provider VARCHAR(16) NOT NULL, key_enc TEXT NOT NULL, key_hint VARCHAR(8) NOT NULL DEFAULT \'\',
            updated BIGINT NOT NULL, PRIMARY KEY (user_id, provider)) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_bin');
        Db::begin();
    }

    private static function box(): string
    {
        if (!function_exists('sodium_crypto_secretbox')) { throw new ApiError(503, 'На хостинге не включено расширение sodium: личные ключи недоступны'); }
        return sodium_crypto_generichash('epd-user-keys-v1|' . Auth::secret(), '', SODIUM_CRYPTO_SECRETBOX_KEYBYTES);
    }

    public static function encrypt(string $plain): string
    {
        $nonce = random_bytes(SODIUM_CRYPTO_SECRETBOX_NONCEBYTES);
        return base64_encode($nonce . sodium_crypto_secretbox($plain, $nonce, self::box()));
    }

    /** null, если не расшифровывается (сменили secret или запись испорчена). */
    public static function decrypt(string $enc): ?string
    {
        $raw = base64_decode($enc, true);
        if ($raw === false || strlen($raw) <= SODIUM_CRYPTO_SECRETBOX_NONCEBYTES) { return null; }
        $plain = sodium_crypto_secretbox_open(substr($raw, SODIUM_CRYPTO_SECRETBOX_NONCEBYTES), substr($raw, 0, SODIUM_CRYPTO_SECRETBOX_NONCEBYTES), self::box());
        return $plain === false ? null : $plain;
    }

    public static function normalize(string $provider, string $value): string
    {
        if (!in_array($provider, self::PROVIDERS, true)) { throw new ApiError(400, 'Неизвестный сервис'); }
        $value = trim($value);
        [$re, $hint] = self::FORMAT[$provider];
        if (!preg_match($re, $value)) { throw new ApiError(400, $hint); }
        return $value;
    }

    public static function save(int $uid, string $provider, string $value): void
    {
        self::ensure();
        $value = self::normalize($provider, $value);
        Db::run('REPLACE INTO user_keys(user_id,provider,key_enc,key_hint,updated) VALUES(?,?,?,?,?)',
            [$uid, $provider, self::encrypt($value), substr($value, -4), time()]);
    }

    public static function delete(int $uid, string $provider): void
    {
        if (!in_array($provider, self::PROVIDERS, true)) { throw new ApiError(400, 'Неизвестный сервис'); }
        self::ensure();
        Db::run('DELETE FROM user_keys WHERE user_id=? AND provider=?', [$uid, $provider]);
    }

    /** Статус для интерфейса: только факт и последние 4 символа. broken = ключ есть, но не расшифровывается. */
    public static function status(int $uid): array
    {
        self::ensure();
        $out = [];
        foreach (self::PROVIDERS as $p) { $out[$p] = ['set' => false, 'hint' => '', 'broken' => false]; }
        foreach (Db::all('SELECT provider,key_enc,key_hint FROM user_keys WHERE user_id=?', [$uid]) as $r) {
            if (!isset($out[$r['provider']])) { continue; }
            $ok = self::decrypt($r['key_enc']) !== null;
            $out[$r['provider']] = ['set' => $ok, 'hint' => $ok ? $r['key_hint'] : '', 'broken' => !$ok];
        }
        return $out;
    }

    /** Для админки: только да/нет. */
    public static function presence(): array
    {
        self::ensure();
        $out = [];
        foreach (Db::all('SELECT user_id,provider FROM user_keys') as $r) { $out[(int)$r['user_id']][$r['provider']] = true; }
        return $out;
    }

    /** Ключ сайта из config.local.php / окружения. */
    public static function site(string $provider): string
    {
        $env = ['anthropic' => 'ANTHROPIC_API_KEY', 'openai' => 'OPENAI_API_KEY', 'deepl' => 'DEEPL_API_KEY'][$provider];
        return trim((string)(cfg($provider . '_key') ?: getenv($env) ?: ''));
    }

    /** Можно ли этому пользователю тратить ключи сайта: владельцу всегда, остальным (и гостям) при site_keys_for_users. */
    public static function siteAllowed(?array $u): bool
    {
        return ($u['role'] ?? '') === 'owner' || (bool)cfg('site_keys_for_users', false);
    }

    /**
     * Порядок: личный ключ → ключ сайта (если разрешено) → ''.
     * Возвращает [ключ, источник user|site|none, битый ли личный ключ]. Не бросает исключений.
     */
    public static function resolve(string $provider, ?array $u = null): array
    {
        $u = $u ?? self::$user;
        if ($u !== null) {
            self::ensure();
            $r = Db::one('SELECT key_enc FROM user_keys WHERE user_id=? AND provider=?', [(int)$u['id'], $provider]);
            if ($r) {
                $plain = self::decrypt($r['key_enc']);
                if ($plain !== null) { return [$plain, 'user', false]; }
                return ['', 'none', true];      // не подменяем молча ключом сайта
            }
        }
        if (self::siteAllowed($u) && ($k = self::site($provider)) !== '') { return [$k, 'site', false]; }
        return ['', 'none', false];
    }

    /** Ключ для запроса или ApiError, если личный ключ не расшифровывается. '' = ключа нет. */
    public static function get(string $provider, ?array $u = null): string
    {
        [$k, , $broken] = self::resolve($provider, $u);
        if ($broken) { throw new ApiError(409, self::NEED_AGAIN . ' (Настройки → Ключи)'); }
        return $k;
    }

    /** Что показывать в настройках как «ключ подключён»: bool на провайдера (для проверок в интерфейсе). */
    public static function available(?array $u = null): array
    {
        $o = [];
        foreach (self::PROVIDERS as $p) { $o[$p] = self::resolve($p, $u)[0] !== ''; }
        return $o;
    }

    /** POST /api/keys: лимит частоты, формат, шифрованное сохранение. */
    public static function handleSave(array $u, array $d): array
    {
        $uid = (int)$u['id'];
        if (Auth::count('keys:' . $uid, 3600) >= self::SAVE_PER_HOUR) { throw new ApiError(429, 'Слишком много попыток сохранить ключ. Подожди немного'); }
        Auth::hit('keys:' . $uid);
        self::save($uid, (string)($d['provider'] ?? ''), (string)($d['value'] ?? ''));
        return ['ok' => true, 'keys' => self::status($uid)];
    }
}
