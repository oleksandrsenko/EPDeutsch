<?php
/**
 * Скопируй этот файл в config/config.local.php и заполни. config.local.php в git не попадает.
 * Все значения необязательны, кроме db и secret.
 */
return [
    // MySQL из панели InfinityFree (MySQL Databases): хост вида sqlXXX.infinityfree.com, имя вида if0_XXXX_epd
    'db' => ['host' => '', 'port' => 3306, 'name' => '', 'user' => '', 'pass' => ''],

    // длинная случайная строка (32+ символов): подпись ссылок отписки, соль посетителей. Сгенерируй: php -r "echo bin2hex(random_bytes(32));"
    'secret' => '',

    // https://твой-домен без / в конце (для canonical, sitemap и картинки соцсетей); можно оставить пустым
    'site_url' => '',

    // почты владельца сайта, через запятую или массивом; если пусто, владельцем станет первый зарегистрированный
    'owner_emails' => [],

    // ключи внешних сервисов (секреты!). Пустое значение = сервис выключен
    'anthropic_key' => '',
    'openai_key' => '',
    'deepl_key' => '',

    // сколько секунд ждать ответ ИИ (бесплатный хостинг обрывает PHP примерно через 30–60 с)
    'ai_timeout' => 28,
    'audio_budget' => 20,          // секунд на генерацию озвучки за один запрос (остальное допроизводится повторным запросом)
    'grade_effort' => 'medium',    // 'low' быстрее и дешевле; ставь 'low', если проверка Schreiben упирается в таймаут
    'grade_model' => 'claude-sonnet-5-5',
    'grade_max_tokens' => 6000,
    'talk_max_tokens' => 2500,

    // регистрация: сколько новых аккаунтов можно создать с одного IP за час
    'register_per_ip_hour' => 3,

    // откуда брать IP посетителя: 'REMOTE_ADDR' или, если хостинг стоит за прокси, 'HTTP_X_FORWARDED_FOR' / 'HTTP_CF_CONNECTING_IP'
    // проверить: войти владельцем и открыть /api/admin/diag
    'ip_header' => 'REMOTE_ADDR',

    // 'bcrypt', если на хостинге Argon2id не хватает памяти (по умолчанию Argon2id, а без него bcrypt)
    'password_algo' => 'argon2id',

    'languagetool_url' => 'https://api.languagetool.org/v2/check',
    'debug' => false,
];
