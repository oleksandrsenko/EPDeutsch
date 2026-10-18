<?php
/** Модули Grammatik / Lesen / Hören / Sprechen и настройки: порт lernen_api.py (/api/de/*). */
class Lernen
{
    const TTS_MODEL = 'gpt-4o-mini-tts';
    const STT_MODEL = 'gpt-4o-transcribe';
    const TALK_MODEL = 'claude-sonnet-5-5';
    const CTX_MODEL = 'claude-haiku-5-5';
    const TTS_MICRO_PER_CHAR = 17;
    const STT_MICRO_PER_KB = 12;
    const VOICES = ['alloy', 'ash', 'ballad', 'coral', 'echo', 'fable', 'nova', 'onyx', 'sage', 'shimmer', 'verse'];
    const DEFAULT_STYLE = 'Sprich natürliches, klares Hochdeutsch mit leichter österreichischer Färbung, in normalem Sprechtempo wie im Radio (Ö1). Keine übertriebene Betonung.';
    const KV_RE = '/^[a-z0-9_.-]{1,48}$/';

    public static function prompt(string $name): string { return trim((string)file_get_contents(EPD_APP . '/prompts/' . $name . '.txt')); }
    public static function schema(string $name): array { return json_decode((string)file_get_contents(EPD_APP . '/prompts/' . $name . '.schema.json'), true); }

    public static function keyStatus(): array
    {
        return ['anthropic' => (bool)Ai::key(), 'openai' => (bool)Ai::openaiKey(), 'deepl' => (bool)Ai::deeplKey()];
    }

    // ---------- приглашения ----------
    public static function inviteOk(string $code): bool
    {
        $code = strtoupper(trim($code));
        if ($code === '') { return false; }
        $r = Db::one('SELECT used_by FROM invites WHERE code=?', [$code]);
        return $r !== null && $r['used_by'] === null;
    }
    public static function inviteUse(string $code, int $uid): void
    {
        Db::run('UPDATE invites SET used_by=?, used=? WHERE code=?', [$uid, time(), strtoupper(trim($code))]);
    }
    /** Регистрация открыта, только пока в базе нет ни одного пользователя (первый — владелец). Дальше по приглашению. */
    public static function registrationOpen(): bool { return !Db::one('SELECT 1 FROM users LIMIT 1'); }

    // ---------- данные модулей ----------
    private static function loadData(string $area, string $name): ?array
    {
        if (!in_array($area, ['de', 'frei'], true) || !preg_match('/^[a-z_]+$/', $name)) { return null; }
        $fp = EPD_PUBLIC . '/data/' . $area . '/' . $name . '.json';
        return is_file($fp) ? json_decode((string)file_get_contents($fp), true) : null;
    }
    public static function findItem(string $area, string $name, string $id): ?array
    {
        foreach (self::loadData($area, $name) ?: [] as $group) {
            if (is_array($group) && array_is_list($group)) {
                foreach ($group as $it) { if (is_array($it) && ($it['id'] ?? null) === $id) { return $it; } }
            }
        }
        return null;
    }

    // ---------- OpenAI ----------
    private static function ttsName(string $text, ?string $voice, ?string $style): string
    {
        $voice = in_array($voice, self::VOICES, true) ? $voice : 'sage';
        $style = $style ?: self::DEFAULT_STYLE;
        return substr(hash('sha256', json_encode([self::TTS_MODEL, $voice, $style, $text], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES)), 0, 32) . '.mp3';
    }

    /** mp3 для текста: из кэша или новый запрос. Возвращает [имя файла, был ли платный запрос]. */
    public static function ttsFile(string $text, ?string $voice, ?string $style): array
    {
        $voice = in_array($voice, self::VOICES, true) ? $voice : 'sage';
        $style = $style ?: self::DEFAULT_STYLE;
        $name = self::ttsName($text, $voice, $style);
        $fp = EPD_AUDIO . '/' . $name;
        if (is_file($fp) && filesize($fp) > 0) { return [$name, false]; }
        $key = Ai::openaiKey();
        if (!$key) { throw new LogicException('no key'); }
        if (!is_dir(EPD_AUDIO)) { @mkdir(EPD_AUDIO, 0775, true); }
        $body = json_encode(['model' => self::TTS_MODEL, 'voice' => $voice, 'input' => $text, 'instructions' => $style, 'response_format' => 'mp3'], JSON_UNESCAPED_UNICODE);
        [$code, $audio] = Ai::http('https://api.openai.com/v1/audio/speech', $body, ['Authorization: Bearer ' . $key, 'Content-Type: application/json'], Ai::timeout(60), 'POST');
        if ($code !== 200) { throw new RuntimeException('OpenAI HTTP ' . $code . ': ' . mb_substr($audio, 0, 300)); }
        $tmp = $fp . '.part';
        file_put_contents($tmp, $audio);
        rename($tmp, $fp);
        return [$name, true];
    }

    private static function transcribe(string $audio, string $mime): string
    {
        $key = Ai::openaiKey();
        if (!$key) { throw new LogicException('no key'); }
        $m = explode(';', $mime)[0] ?: 'audio/webm';
        $ext = ['audio/webm' => 'webm', 'audio/ogg' => 'ogg', 'audio/mp4' => 'm4a', 'audio/mpeg' => 'mp3', 'audio/wav' => 'wav'][$m] ?? 'webm';
        $tmp = tempnam(sys_get_temp_dir(), 'stt');
        file_put_contents($tmp, $audio);
        $ch = curl_init('https://api.openai.com/v1/audio/transcriptions');
        curl_setopt_array($ch, [CURLOPT_POST => true, CURLOPT_RETURNTRANSFER => true, CURLOPT_TIMEOUT => Ai::timeout(60),
            CURLOPT_HTTPHEADER => ['Authorization: Bearer ' . $key],
            CURLOPT_POSTFIELDS => ['model' => self::STT_MODEL, 'language' => 'de', 'response_format' => 'json',
                'prompt' => 'Transkribiere wörtlich, mit allen grammatischen Fehlern, Füllwörtern (äh, ähm) und Wiederholungen. Korrigiere nichts.',
                'file' => new CURLFile($tmp, $m, 'answer.' . $ext)]]);
        $raw = curl_exec($ch);
        $code = (int)curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
        $errno = curl_errno($ch);
        curl_close($ch);
        @unlink($tmp);
        if ($raw === false) { throw new RuntimeException('curl STT', $errno); }
        if ($code !== 200) { throw new RuntimeException('OpenAI HTTP ' . $code . ': ' . mb_substr((string)$raw, 0, 300)); }
        return trim((string)(json_decode((string)$raw, true)['text'] ?? ''));
    }

    private static function taskBrief(?array $item): string
    {
        if (!$item) { return 'Freies Gespräch.'; }
        $parts = ['Thema: ' . ($item['topic'] ?? $item['title'] ?? '')];
        if (!empty($item['title'])) { $parts[] = 'Aufgabe: ' . $item['title']; }
        if (!empty($item['impulse'])) { $parts[] = 'Impuls: ' . $item['impulse']; }
        if (!empty($item['chart'])) {
            $ch = $item['chart'];
            $parts[] = 'Grafik: ' . ($ch['title'] ?? '') . ' (' . ($ch['unit'] ?? '') . '): '
                . implode('; ', array_map(fn($b) => $b[0] . ' ' . $b[1], $ch['bars'] ?? [])) . '. Quelle: ' . ($ch['source'] ?? '');
        }
        if (!empty($item['leitfragen'])) { $parts[] = 'Leitfragen: ' . implode(' | ', $item['leitfragen']); }
        if (!empty($item['these'])) { $parts[] = 'These für den Dialog: ' . $item['these']; }
        if (!empty($item['fragen'])) { $parts[] = 'Mögliche Fragen der Kommission: ' . implode(' | ', $item['fragen']); }
        if (!empty($item['situation'])) { $parts[] = 'Situation: ' . $item['situation']; }
        if (!empty($item['persona'])) { $parts[] = 'Deine Rolle: ' . $item['persona']; }
        return implode("\n", $parts);
    }

    private static function needAiKey(string $msg = 'Нужен ключ Claude (Настройки → Ключи)'): void
    {
        if (!Ai::key()) { throw new ApiError(400, $msg); }
    }

    // ---------- маршрутизатор /api/de/* ----------
    public static function route(array $u, string $method, string $p): array
    {
        $now = time();
        $uid = (int)$u['id'];

        if ($p === '/state' && $method === 'GET') {
            $state = new stdClass();
            foreach (Db::all('SELECT k,v FROM kv WHERE user_id=?', [$uid]) as $r) { $state->{$r['k']} = Util::jd($r['v']); }
            return ['state' => $state, 'keys' => self::keyStatus(), 'user' => Users::json($u)];
        }

        if ($method === 'PUT' && preg_match('#^/state/([^/]+)$#', $p, $m)) {
            $k = $m[1];
            if (!preg_match(self::KV_RE, $k)) { throw new ApiError(400, 'Неверный ключ'); }
            $body = Http::jsonObj();
            $v = json_encode($body->v ?? null, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
            if (mb_strlen($v) > 400000) { throw new ApiError(413, 'Слишком много данных'); }
            Db::run('INSERT INTO kv(user_id,k,v,updated) VALUES(?,?,?,?) ON DUPLICATE KEY UPDATE v=VALUES(v), updated=VALUES(updated)', [$uid, $k, $v, $now]);
            return ['ok' => true];
        }

        if ($p === '/ctx' && $method === 'POST') {
            $d = Http::json();
            $word = Util::str($d['word'] ?? '', 80); $sent = Util::str($d['sentence'] ?? '', 400);
            if ($word === '') { throw new ApiError(400, 'Пустое слово'); }
            $out = ['word' => $word, 'tr' => '', 'provider' => '', 'lemma' => '', 'note' => ''];
            if ($row = Ai::trGet($word, 'de', 'ru')) { $out['tr'] = $row['out']; $out['provider'] = $row['prov']; }
            elseif (Ai::deeplKey() || !Ai::key()) {
                try { [$out['tr'], $out['provider']] = Ai::translate($word, 'de', 'ru'); Ai::trPut($word, 'de', 'ru', $out['tr'], $out['provider']); }
                catch (Throwable $e) { error_log('ctx translate: ' . $e->getMessage()); }
            }
            if (Ai::key() && ($d['explain'] ?? true)) {
                $ck = 'ctx:' . hash('sha256', json_encode([self::CTX_MODEL, $word, $sent], JSON_UNESCAPED_UNICODE));
                $res = Ai::cacheGet($ck);
                if (!$res) {
                    Ai::guard($u);
                    Db::commit();
                    $t0 = microtime(true);
                    try {
                        [$res, $inp, $outp] = Ai::claude(self::prompt('lernen_ctx'), "Слово: $word\nПредложение: " . ($sent ?: $word), self::schema('lernen_ctx'), 400, self::CTX_MODEL, 'low');
                        Ai::log($uid, 'ctx', self::CTX_MODEL, $inp, $outp, (int)((microtime(true) - $t0) * 1000));
                        Ai::cachePut($ck, $res);
                    } catch (Throwable $e) {
                        Ai::log($uid, 'ctx', self::CTX_MODEL, 0, 0, (int)((microtime(true) - $t0) * 1000), $e->getMessage());
                        $res = null;
                    }
                }
                if ($res) { $out['lemma'] = $res['lemma']; $out['note'] = $res['note']; $out['tr_ctx'] = $res['tr']; }
                if ($out['tr'] === '' && !empty($out['tr_ctx'])) { $out['tr'] = $out['tr_ctx']; $out['provider'] = 'Claude'; }
            }
            if ($out['tr'] === '') { throw new ApiError(502, 'Переводчик сейчас недоступен'); }
            return $out;
        }

        if ($p === '/check' && $method === 'POST') {
            self::needAiKey();
            $d = Http::json();
            $task = Util::str($d['task'] ?? '', 300); $src = Util::str($d['q'] ?? '', 600); $ans = Util::str($d['answer'] ?? '', 600);
            $models = array_map(fn($x) => Util::str($x, 400), array_slice(is_array($d['a'] ?? null) ? $d['a'] : [], 0, 6));
            if ($ans === '') { throw new ApiError(400, 'Пустой ответ'); }
            $ck = 'chk:' . hash('sha256', json_encode([self::CTX_MODEL, $task, $src, $models, $ans], JSON_UNESCAPED_UNICODE));
            if ($c = Ai::cacheGet($ck)) { return $c; }
            $res = Ai::ask($u, 'gcheck', self::CTX_MODEL, self::prompt('lernen_check'),
                "Задание: $task\nИсходное: $src\nЭталоны: " . implode(' || ', $models) . "\nОтвет студента: $ans", self::schema('lernen_check'), 500, 'low', 45, 'ИИ сейчас недоступен, попробуй ещё раз');
            Ai::cachePut($ck, $res);
            return $res;
        }

        if ($p === '/aitasks' && $method === 'POST') {
            self::needAiKey();
            $d = Http::json();
            $mod = Util::str($d['module'] ?? '', 20); $topic = Util::str($d['topic'] ?? '', 200); $level = Util::str($d['level'] ?? '', 6) ?: 'B2';
            if (!in_array($mod, ['grammatik', 'lesen', 'hoeren', 'sprechen'], true) || mb_strlen($topic) < 3) { throw new ApiError(400, 'Напиши тему хотя бы в пару слов'); }
            $nonce = Util::str($d['n'] ?? '', 12);
            $ck = 'ait:' . hash('sha256', json_encode([self::TALK_MODEL, $mod, $topic, $level, $nonce], JSON_UNESCAPED_UNICODE));
            if ($c = Ai::cacheGet($ck)) { return $c; }
            $res = Ai::ask($u, 'aitasks', self::TALK_MODEL, self::prompt('lernen_aitask'), "Модуль: $mod\nУровень: $level\nТема: $topic",
                self::schema('lernen_aitask'), 1500, null, 45, 'ИИ сейчас недоступен, попробуй ещё раз');
            Ai::cachePut($ck, $res);
            return $res;
        }

        if ($p === '/gwrite' && $method === 'POST') {
            self::needAiKey();
            $d = Http::json();
            $topic = Util::str($d['topic'] ?? '', 200); $task = Util::str($d['task'] ?? '', 400); $text = Util::str($d['text'] ?? '', 2500);
            if (count(preg_split('/\s+/u', $text, -1, PREG_SPLIT_NO_EMPTY)) < 5) { throw new ApiError(400, 'Напиши хотя бы пару предложений'); }
            $ck = 'gw:' . hash('sha256', json_encode([self::TALK_MODEL, $topic, $task, $text], JSON_UNESCAPED_UNICODE));
            if ($c = Ai::cacheGet($ck)) { return $c; }
            $res = Ai::ask($u, 'gwrite', self::TALK_MODEL, self::prompt('lernen_gwrite'), "Тема грамматики: $topic\nЗадание: $task\nТекст студента:\n$text",
                self::schema('lernen_gwrite'), 1500, null, 45, 'ИИ сейчас недоступен, попробуй ещё раз');
            Ai::cachePut($ck, $res);
            return $res;
        }

        if ($p === '/tts' && $method === 'POST') {
            $d = Http::json();
            $text = Util::str($d['text'] ?? '', 1500);
            if ($text === '') { throw new ApiError(400, 'Пустой текст'); }
            if (!Ai::openaiKey()) { return ['url' => null, 'nokey' => true]; }
            Ai::guard($u);
            Db::commit();
            $t0 = microtime(true);
            try { [$name, $fresh] = self::ttsFile($text, Util::str($d['voice'] ?? '', 20), Util::str($d['style'] ?? '', 400)); }
            catch (Throwable $e) {
                Ai::log($uid, 'tts', self::TTS_MODEL, 0, 0, (int)((microtime(true) - $t0) * 1000), $e->getMessage(), 0);
                Db::commit();
                throw Ai::failure($e, 'Озвучка сейчас недоступна');
            }
            if ($fresh) { Ai::log($uid, 'tts', self::TTS_MODEL, 0, 0, (int)((microtime(true) - $t0) * 1000), '', mb_strlen($text) * self::TTS_MICRO_PER_CHAR); }
            return ['url' => '/audio/' . $name];
        }

        if ($method === 'POST' && preg_match('#^/audio/(de|frei)/([a-z0-9_-]{1,40})$#', $p, $m)) {
            // Сценарий из нескольких реплик. Хостинг обрывает долгие запросы, поэтому за один вызов делаем столько реплик, сколько успеваем
            // (бюджет audio_budget, по умолчанию 20 с), и отвечаем pending=true — страница повторяет запрос, готовые реплики берутся из кэша.
            $item = self::findItem($m[1], 'hoeren', $m[2]);
            if (!$item) { throw new ApiError(404, 'Нет такого аудио'); }
            if (!Ai::openaiKey()) { return ['url' => null, 'nokey' => true]; }
            $lines = $item['lines'] ?? [];
            $voices = $item['voices'] ?? [];
            $budget = (int)cfg('audio_budget', 20);
            $t0 = microtime(true);
            $parts = []; $chars = 0; $guarded = false; $lastErr = null;
            foreach ($lines as [$spk, $text]) {
                $v = $voices[$spk] ?? [];
                $name = self::ttsName($text, $v['voice'] ?? null, $v['style'] ?? null);
                if (!(is_file(EPD_AUDIO . '/' . $name) && filesize(EPD_AUDIO . '/' . $name) > 0)) {
                    if ((microtime(true) - $t0) > $budget) { break; }
                    if (!$guarded) { Ai::guard($u); Db::commit(); $guarded = true; }
                    try { self::ttsFile($text, $v['voice'] ?? null, $v['style'] ?? null); $chars += mb_strlen($text); }
                    catch (Throwable $e) { $lastErr = $e; break; }
                }
                $parts[] = $name;
            }
            if ($chars) { Ai::log($uid, 'tts', self::TTS_MODEL, 0, 0, (int)((microtime(true) - $t0) * 1000), '', $chars * self::TTS_MICRO_PER_CHAR); }
            if ($lastErr) {
                Ai::log($uid, 'tts', self::TTS_MODEL, 0, 0, (int)((microtime(true) - $t0) * 1000), $lastErr->getMessage(), 0);
                Db::commit();
                throw Ai::failure($lastErr, 'Озвучка сейчас недоступна');
            }
            if (count($parts) < count($lines)) { return ['url' => null, 'pending' => true, 'done' => count($parts), 'total' => count($lines)]; }
            $name = 's' . substr(hash('sha256', implode('|', $parts)), 0, 32) . '.mp3';
            $fp = EPD_AUDIO . '/' . $name;
            if (!is_file($fp)) {
                $out = fopen($fp . '.part', 'wb');
                foreach ($parts as $part) { fwrite($out, (string)file_get_contents(EPD_AUDIO . '/' . $part)); }
                fclose($out);
                rename($fp . '.part', $fp);
            }
            return ['url' => '/audio/' . $name];
        }

        if ($p === '/stt' && $method === 'POST') {
            $d = Http::json();
            if (!Ai::openaiKey()) { throw new ApiError(400, 'Для распознавания речи нужен ключ OpenAI (Настройки → Ключи)'); }
            $audio = base64_decode((string)($d['audio'] ?? ''), false);
            if ($audio === false) { throw new ApiError(400, 'Неверная запись'); }
            if (strlen($audio) < 800) { throw new ApiError(400, 'Запись слишком короткая'); }
            Ai::guard($u);
            Db::commit();
            $t0 = microtime(true);
            try { $text = self::transcribe($audio, Util::str($d['mime'] ?? '', 60) ?: 'audio/webm'); }
            catch (Throwable $e) {
                Ai::log($uid, 'stt', self::STT_MODEL, 0, 0, (int)((microtime(true) - $t0) * 1000), $e->getMessage(), 0);
                Db::commit();
                throw Ai::failure($e, 'Не получилось распознать речь, попробуй ещё раз');
            }
            Ai::log($uid, 'stt', self::STT_MODEL, 0, 0, (int)((microtime(true) - $t0) * 1000), '', intdiv(strlen($audio), 1024) * self::STT_MICRO_PER_KB);
            return ['text' => $text];
        }

        if ($p === '/talk' && $method === 'POST') {
            self::needAiKey('Для разбора нужен ключ Claude (Настройки → Ключи)');
            $d = Http::json();
            $area = ($d['area'] ?? '') === 'frei' ? 'frei' : 'de';
            $item = self::findItem($area, 'sprechen', Util::str($d['task'] ?? '', 40));
            $text = Util::str($d['text'] ?? '', 4000);
            if (mb_strlen($text) < 3) { throw new ApiError(400, 'Ответ пустой — скажи хотя бы пару предложений'); }
            $hist = [];
            foreach (array_slice(is_array($d['history'] ?? null) ? $d['history'] : [], -10) as $t) {
                if (is_array($t) && in_array($t['role'] ?? '', ['ex', 'me'], true)) {
                    $hist[] = ($t['role'] === 'ex' ? 'Экзаменатор/собеседник' : 'Студент') . ': ' . Util::str($t['text'] ?? '', 1500);
                }
            }
            $phase = Util::str($d['phase'] ?? '', 20);
            $content = "ЗАДАНИЕ\n" . self::taskBrief($item) . "\n\nЭТАП: " . (['monolog' => 'монолог-презентация', 'dialog' => 'диалог с комиссией'][$phase] ?? 'разговор')
                . "\n\nПРЕДЫДУЩИЙ РАЗГОВОР\n" . ($hist ? implode("\n", $hist) : '—') . "\n\nНОВЫЙ ОТВЕТ СТУДЕНТА (расшифровка)\n" . $text;
            return Ai::ask($u, 'talk', self::TALK_MODEL, self::prompt($area === 'frei' ? 'lernen_talk_frei' : 'lernen_talk_epd'), $content,
                self::schema('lernen_talk'), (int)cfg('talk_max_tokens', 2500), 'low', 120, 'ИИ сейчас недоступен, попробуй ещё раз');
        }

        if ($p === '/settings' && $method === 'GET') {
            $day = Util::localDay($now);
            $spent = (float)Db::val('SELECT COALESCE(SUM(cost),0) FROM ai_log WHERE day=?', [$day]) / 1e6;
            $month = (float)Db::val('SELECT COALESCE(SUM(cost),0) FROM ai_log WHERE ts>?', [$now - 30 * 86400]) / 1e6;
            $inv = [];
            if ($u['role'] === 'owner') {
                $inv = Db::all('SELECT i.code,i.created,i.used,u.name AS used_name FROM invites i LEFT JOIN users u ON u.id=i.used_by ORDER BY i.created DESC LIMIT 30');
            }
            return ['keys' => self::keyStatus(), 'owner' => $u['role'] === 'owner', 'invites' => $inv,
                    'spent_today' => round($spent, 3), 'spent_month' => round($month, 2), 'budget_day' => Ai::SITE_DAY_USD,
                    'models' => ['talk' => self::TALK_MODEL, 'ctx' => self::CTX_MODEL, 'tts' => self::TTS_MODEL, 'stt' => self::STT_MODEL]];
        }

        if ($p === '/settings/key' && $method === 'POST') {
            if ($u['role'] !== 'owner') { throw new ApiError(403, 'Ключи может менять только владелец'); }
            throw new ApiError(409, 'На хостинге ключи задаются в файле config/config.local.php (anthropic_key, openai_key, deepl_key), а не в админке. Значения ключей сайт не показывает.');
        }

        if ($p === '/invites' && $method === 'POST') {
            if ($u['role'] !== 'owner') { throw new ApiError(403, 'Приглашения создаёт только владелец'); }
            $code = Util::newCode('invites', 'code', 8);
            Db::run('INSERT INTO invites(code,created_by,created) VALUES(?,?,?)', [$code, $uid, $now]);
            return ['code' => $code];
        }

        if ($method === 'DELETE' && preg_match('#^/invites/([A-Z0-9]{4,12})$#', $p, $m)) {
            if ($u['role'] !== 'owner') { throw new ApiError(403, 'Только владелец'); }
            Db::run('DELETE FROM invites WHERE code=? AND used_by IS NULL', [$m[1]]);
            return ['ok' => true];
        }

        throw new ApiError(404, 'Не найдено');
    }
}
