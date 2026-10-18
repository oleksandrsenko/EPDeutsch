<?php
/** «Ленивая» замена cron: чистка выполняется при обычных запросах, не чаще раза в час. */
class Maintenance
{
    public static function maybeRun(): void
    {
        $stamp = EPD_STORAGE . '/maintenance.stamp';
        $last = is_file($stamp) ? (int)@file_get_contents($stamp) : 0;
        if (time() - $last < 3600 || random_int(1, 20) !== 1) { return; }
        @file_put_contents($stamp, (string)time());      // метка до начала работы: параллельные запросы не дублируют чистку
        try { self::run(); } catch (Throwable $e) { error_log('maintenance: ' . $e->getMessage()); }
    }

    public static function run(): void
    {
        $now = time();
        Db::begin();
        Db::run('DELETE FROM sessions WHERE expires<?', [$now]);
        Db::run('DELETE FROM rate_limit WHERE ts<?', [$now - 86400]);
        Db::run('DELETE FROM hits WHERE ts<?', [$now - 400 * 86400]);        // посещения храним 13 месяцев
        Db::run('DELETE FROM presence WHERE ts<?', [$now - 86400]);          // «онлайн» — сутки
        Db::run('DELETE FROM ai_log WHERE ts<?', [$now - 400 * 86400]);
        Db::run('DELETE FROM ai_cache WHERE ts<?', [$now - 30 * 86400]);
        Users::finishRaces();
        Db::finish(true);
    }
}
