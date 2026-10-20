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
        return Ai::keyStatus();
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

    /** Папка с озвучкой должна существовать и принимать запись: иначе сайт отдаёт ссылку на несуществующий файл, и плеер падает с «ошибкой воспроизведения». */
    private static function audioDir(): void
    {
        if (!is_dir(EPD_AUDIO)) { @mkdir(EPD_AUDIO, 0775, true); }
        if (!is_dir(EPD_AUDIO) || !is_writable(EPD_AUDIO)) { throw new RuntimeException('на сервере нет доступа к папке audio (нужны права на запись)'); }
    }

    /** Реплика режется на куски до ~420 символов по границам предложений: каждый кусок озвучивается за несколько секунд и кэшируется отдельно. */
    public static function ttsSplit(string $text, int $max = 420): array
    {
        $text = trim((string)preg_replace('/\s+/u', ' ', $text));
        if (mb_strlen($text) <= $max) { return $text === '' ? [] : [$text]; }
        $out = []; $cur = '';
        foreach (preg_split('/(?<=[.!?…:;])\s+/u', $text, -1, PREG_SPLIT_NO_EMPTY) as $sn) {
            if ($cur !== '' && mb_strlen($cur) + 1 + mb_strlen($sn) > $max) { $out[] = $cur; $cur = ''; }
            $cur = $cur === '' ? $sn : $cur . ' ' . $sn;
            while (mb_strlen($cur) > $max) {
                $cut = mb_strrpos(mb_substr($cur, 0, $max), ' ') ?: $max;
                $out[] = trim(mb_substr($cur, 0, $cut)); $cur = trim(mb_substr($cur, $cut));
            }
        }
        if ($cur !== '') { $out[] = $cur; }
        return $out;
    }

    /** mp3 для куска текста: из кэша или новый запрос. Возвращает [имя файла, был ли платный запрос]. */
    public static function ttsFile(string $text, ?string $voice, ?string $style, int $timeout = 25): array
    {
        $voice = in_array($voice, self::VOICES, true) ? $voice : 'sage';
        $style = $style ?: self::DEFAULT_STYLE;
        $name = self::ttsName($text, $voice, $style);
        $fp = EPD_AUDIO . '/' . $name;
        if (is_file($fp) && filesize($fp) > 0) { return [$name, false]; }
        $key = Ai::openaiKey();
        if (!$key) { throw new LogicException('no key'); }
        self::audioDir();
        $body = json_encode(['model' => self::TTS_MODEL, 'voice' => $voice, 'input' => $text, 'instructions' => $style, 'response_format' => 'mp3'], JSON_UNESCAPED_UNICODE);
        [$code, $audio] = Ai::http(cfg('openai_base', 'https://api.openai.com') . '/v1/audio/speech', $body, ['Authorization: Bearer ' . $key, 'Content-Type: application/json'], Ai::timeout($timeout), 'POST');
        if ($code !== 200) { throw new RuntimeException('OpenAI HTTP ' . $code . ': ' . mb_substr($audio, 0, 300)); }
        if (strlen($audio) < 200) { throw new RuntimeException('OpenAI вернул пустой звук'); }
        $tmp = $fp . '.part';
        if (@file_put_contents($tmp, $audio) === false || !@rename($tmp, $fp)) { @unlink($tmp); throw new RuntimeException('не удалось сохранить звук в папку audio'); }
        return [$name, true];
    }

    /** Озвучивает куски по порядку, пока хватает времени запроса (хостинг обрывает долгие). [готовые имена, символов оплачено, ошибка]. */
    private static function ttsBatch(array $chunks, array $u, float $t0): array
    {
        $limit = (float)cfg('request_limit', 24);
        $names = []; $chars = 0; $err = null; $guarded = false;
        foreach ($chunks as [$text, $voice, $style]) {
            $name = self::ttsName($text, $voice, $style);
            $fp = EPD_AUDIO . '/' . $name;
            if (!(is_file($fp) && filesize($fp) > 0)) {
                $left = $limit - (microtime(true) - $t0);
                if ($left < 7) { break; }
                if (!$guarded) { Ai::guard($u); Db::commit(); $guarded = true; }
                try { self::ttsFile($text, $voice, $style, (int)min(25, $left + 3)); $chars += mb_strlen($text); }
                catch (Throwable $e) { $err = $e; break; }
            }
            $names[] = $name;
        }
        return [$names, $chars, $err];
    }

    /** Реплики [[спикер, текст]] + голоса → куски для озвучки; потом сборка одного mp3. Ответ: url или pending (страница повторяет запрос). */
    private static function ttsScript(array $u, array $lines, array $voices): array
    {
        $uid = (int)$u['id'];
        if (!Ai::openaiKey()) { return ['url' => null, 'nokey' => true]; }
        $chunks = [];
        foreach ($lines as $ln) {
            if (!is_array($ln) || count($ln) < 2) { continue; }
            $v = is_array($voices[$ln[0]] ?? null) ? $voices[$ln[0]] : [];
            foreach (self::ttsSplit((string)$ln[1]) as $part) { $chunks[] = [$part, $v['voice'] ?? null, $v['style'] ?? null]; }
        }
        if (!$chunks) { throw new ApiError(400, 'Пустой текст'); }
        $t0 = microtime(true);
        try { self::audioDir(); } catch (Throwable $e) { throw Ai::failure($e, 'Озвучка сейчас недоступна'); }
        [$names, $chars, $err] = self::ttsBatch($chunks, $u, $t0);
        $ms = (int)((microtime(true) - $t0) * 1000);
        if ($chars) { Ai::log($uid, 'tts', self::TTS_MODEL, 0, 0, $ms, '', $chars * self::TTS_MICRO_PER_CHAR); }
        if ($err) {
            Ai::log($uid, 'tts', self::TTS_MODEL, 0, 0, $ms, $err->getMessage(), 0);
            Db::commit();
            throw Ai::failure($err, 'Озвучка сейчас недоступна');
        }
        if (count($names) < count($chunks)) { return ['url' => null, 'pending' => true, 'done' => count($names), 'total' => count($chunks)]; }
        if (count($names) === 1) { return ['url' => '/audio/' . $names[0]]; }
        $name = 's' . substr(hash('sha256', implode('|', $names)), 0, 32) . '.mp3';
        $fp = EPD_AUDIO . '/' . $name;
        if (!(is_file($fp) && filesize($fp) > 0)) {
            $out = @fopen($fp . '.part', 'wb');
            if (!$out) { throw new ApiError(502, 'Озвучка сейчас недоступна (не удалось собрать файл в папке audio)'); }
            foreach ($names as $part) {
                $data = @file_get_contents(EPD_AUDIO . '/' . $part);
                if ($data === false || $data === '') { fclose($out); @unlink($fp . '.part'); throw new ApiError(502, 'Озвучка сейчас недоступна (потерялся кусок записи, попробуй ещё раз)'); }
                fwrite($out, $data);
            }
            fclose($out);
            if (!@rename($fp . '.part', $fp)) { @unlink($fp . '.part'); throw new ApiError(502, 'Озвучка сейчас недоступна (не удалось сохранить файл)'); }
        }
        return ['url' => '/audio/' . $name];
    }

    private static function transcribe(string $audio, string $mime): string
    {
        $key = Ai::openaiKey();
        if (!$key) { throw new LogicException('no key'); }
        $m = explode(';', $mime)[0] ?: 'audio/webm';
        $ext = ['audio/webm' => 'webm', 'audio/ogg' => 'ogg', 'audio/mp4' => 'm4a', 'audio/mpeg' => 'mp3', 'audio/wav' => 'wav'][$m] ?? 'webm';
        $tmp = tempnam(sys_get_temp_dir(), 'stt');
        file_put_contents($tmp, $audio);
        $ch = curl_init(cfg('openai_base', 'https://api.openai.com') . '/v1/audio/transcriptions');
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

    /** Проверка связи с ИИ: короткий запрос к Claude и к озвучке; показывает точную причину сбоя и скорость. */
    private static function diag(array $u): array
    {
        $out = [];
        $t0 = microtime(true);
        $key = Ai::key();
        if (!$key) { $out[] = ['name' => 'Claude', 'ok' => false, 'msg' => 'ключ не найден: добавь его в Настройки → Ключи']; }
        else {
            try {
                Ai::guard($u);
                [$res, $inp, $outp] = Ai::claude('Antworte nur im vorgegebenen JSON-Format.', 'Gib das Wort "bereit" im Feld w zurück.', ['type' => 'object', 'additionalProperties' => false, 'required' => ['w'], 'properties' => ['w' => ['type' => 'string']]], 80, AiGen::MODEL, 'low', 20);
                $ms = (int)((microtime(true) - $t0) * 1000);
                Ai::log((int)$u['id'], 'diag', AiGen::MODEL, $inp, $outp, $ms);
                $out[] = ['name' => 'Claude (' . AiGen::MODEL . ')', 'ok' => true, 'msg' => "работает, ответ за {$ms} мс"];
            } catch (Throwable $e) {
                Ai::log((int)$u['id'], 'diag', AiGen::MODEL, 0, 0, (int)((microtime(true) - $t0) * 1000), $e->getMessage());
                $out[] = ['name' => 'Claude (' . AiGen::MODEL . ')', 'ok' => false, 'msg' => $e instanceof ApiError ? $e->getMessage() : Ai::reason($e)];
            }
        }
        $dir = is_dir(EPD_AUDIO) ? (is_writable(EPD_AUDIO) ? 'есть, запись разрешена' : 'есть, но запись запрещена') : 'нет (будет создана)';
        $okDir = !is_dir(EPD_AUDIO) ? is_writable(dirname(EPD_AUDIO)) : is_writable(EPD_AUDIO);
        $out[] = ['name' => 'Папка audio', 'ok' => $okDir, 'msg' => $dir . ($okDir ? '' : ': без записи озвучка не сохранится')];
        $okk = Ai::openaiKey() !== '';
        $out[] = ['name' => 'Озвучка OpenAI', 'ok' => $okk, 'msg' => $okk ? 'ключ есть' : 'ключа нет, звучит голос браузера'];
        $out[] = ['name' => 'Лимит хостинга', 'ok' => true, 'msg' => 'один запрос не дольше ' . (int)cfg('ai_timeout', 28) . ' с, длинные тексты собираются из коротких шагов'];
        return ['checks' => $out];
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

        if ($p === '/trdict' && $method === 'POST') {   // словарная статья к слову из переводчика: другие значения, синонимы, примеры
            self::needAiKey();
            $d = Http::json();
            $word = Util::str($d['word'] ?? '', 60); $sl = Util::str($d['from'] ?? '', 2); $tl = Util::str($d['to'] ?? '', 2);
            if ($word === '' || count(preg_split('/\s+/u', $word)) > 4 || !in_array($sl, ['de', 'ru', 'en'], true) || !in_array($tl, ['de', 'ru', 'en'], true) || $sl === $tl) { throw new ApiError(400, 'Нужно слово или короткое выражение'); }
            $ck = 'trd:' . hash('sha256', json_encode([self::CTX_MODEL, $word, $sl, $tl], JSON_UNESCAPED_UNICODE));
            if ($c = Ai::cacheGet($ck)) { return $c; }
            $res = Ai::ask($u, 'trdict', self::CTX_MODEL, self::prompt('lernen_trdict'), "Слово: $word\nЯзык слова: $sl\nЯзык перевода: $tl",
                self::schema('lernen_trdict'), 900, 'low', 40, 'Словарь сейчас недоступен');
            $clean = fn($v, $n, $m) => array_values(array_filter(array_map(fn($x) => Util::str($x, $m), array_slice(is_array($v) ? $v : [], 0, $n)), fn($x) => $x !== ''));
            $ex = [];
            foreach (array_slice(is_array($res['examples'] ?? null) ? $res['examples'] : [], 0, 4) as $e) {
                $de = Util::str($e['de'] ?? '', 200); $tr = Util::str($e['tr'] ?? '', 200);
                if ($de !== '' && $tr !== '') { $ex[] = ['de' => $de, 'tr' => $tr]; }
            }
            $out = ['lemma' => Util::str($res['lemma'] ?? '', 80), 'pos' => Util::str($res['pos'] ?? '', 30), 'alts' => $clean($res['alts'] ?? [], 5, 80), 'syn' => $clean($res['syn'] ?? [], 6, 60), 'examples' => $ex];
            if ($out['alts'] || $out['syn'] || $ex) { Ai::cachePut($ck, $out); }
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

        if ($p === '/aigen' && $method === 'POST') {   // тема от студента → задание для разговора (Sprechen)
            self::needAiKey();
            $d = Http::json();
            [$system, $content, $schema, $tokens] = AiGen::request($d);
            $res = Ai::ask($u, 'aigen', AiGen::MODEL, $system, $content, $schema, $tokens, 'low', 25, 'ИИ сейчас недоступен, попробуй ещё раз');
            $mode = in_array($d['mode'] ?? '', AiGen::MODES, true) ? $d['mode'] : 'new';
            return AiGen::normalize((string)$d['module'], $res, Util::str($d['topic'] ?? '', 200), ($d['level'] ?? '') === 'C1' ? 'C1' : 'B2', $mode);
        }

        if ($p === '/gen' && $method === 'POST') {   // Lesen, Hören и Grammatik: один шаг генерации (план, абзац, блок записи, задания), браузер собирает результат
            self::needAiKey();
            return Gen::route($u, Http::json());
        }

        if ($p === '/aidiag' && $method === 'POST') {   // проверка связи: что именно отвечают Claude и OpenAI и как быстро
            return self::diag($u);
        }

        if ($p === '/ltasks' && $method === 'POST') {   // задания официальных типов EPD к тексту Lesen
            self::needAiKey();
            $d = Http::json();
            [$system, $content, $schema, $tokens] = AiGen::ltasksRequest($d);
            $res = Ai::ask($u, 'ltasks', AiGen::MODEL, $system, $content, $schema, $tokens, 'low', 25, 'ИИ сейчас недоступен, попробуй ещё раз');
            return ['tasks' => AiGen::ltasksNormalize((string)$d['kind'], $res, AiGen::paras($d['paras'] ?? []))];
        }

        if ($p === '/wexam' && $method === 'POST') {   // пробный экзамен по лексике (Wörter)
            self::needAiKey();
            $d = Http::json();
            [$system, $content, $schema, $tokens] = AiGen::wexamRequest($d);
            $res = Ai::ask($u, 'wexam', AiGen::MODEL, $system, $content, $schema, $tokens, 'low', 100, 'ИИ сейчас недоступен, попробуй ещё раз');
            return ['items' => AiGen::wexamNormalize($res)];
        }

        if ($p === '/lcheck' && $method === 'POST') {   // оценка открытых ответов Lesen (Kurzantworten и т.п.): 0 / 0,5 / 1
            self::needAiKey();
            $d = Http::json();
            $items = [];
            foreach (array_slice(is_array($d['items'] ?? null) ? $d['items'] : [], 0, 12) as $x) {
                if (!is_array($x)) { continue; }
                $items[] = ['q' => Util::str($x['q'] ?? '', 400), 'm' => array_values(array_filter(array_map(fn($a) => Util::str($a, 200), is_array($x['models'] ?? null) ? array_slice($x['models'], 0, 5) : []), fn($a) => $a !== '')),
                            'a' => Util::str($x['ans'] ?? '', 300), 'max' => Util::int($x['max'] ?? 0, 0, 12)];
            }
            if (!$items) { throw new ApiError(400, 'Нечего проверять'); }
            $ctx = Util::str(implode(' ', AiGen::paras($d['paras'] ?? [])), 6000);
            $out = []; $todo = [];
            foreach ($items as $i => $it) {
                if ($it['a'] === '') { $out[$i] = ['score' => 0, 'why' => 'Ответа нет.']; } else { $todo[] = $i; }
            }
            if ($todo) {
                $content = "Text:\n" . ($ctx ?: '—') . "\n\nAntworten zur Bewertung:\n";
                foreach ($todo as $n => $i) {
                    $it = $items[$i];
                    $content .= ($n + 1) . ". Frage: " . $it['q'] . ($it['max'] ? " (максимум {$it['max']} слов)" : '') . "\n   Эталоны: " . implode(' || ', $it['m']) . "\n   Ответ студента: " . $it['a'] . "\n";
                }
                $ck = 'lchk:' . hash('sha256', json_encode([AiGen::MODEL, $content], JSON_UNESCAPED_UNICODE));
                $res = Ai::cacheGet($ck);
                if (!$res) {
                    $res = Ai::ask($u, 'lcheck', AiGen::MODEL, self::prompt('lernen_lcheck'), $content, self::schema('lernen_lcheck'), 900, 'low', 45, 'ИИ сейчас недоступен, попробуй ещё раз');
                    Ai::cachePut($ck, $res);
                }
                foreach ($todo as $n => $i) {
                    $r = $res['results'][$n] ?? null;
                    $sc = $r ? (float)$r['score'] : 0;
                    $out[$i] = ['score' => in_array($sc, [0.0, 0.5, 1.0], true) ? $sc : 0, 'why' => Util::str($r['why'] ?? '', 400)];
                }
            }
            ksort($out);
            return ['results' => array_values($out)];
        }

        if ($p === '/sfort' && $method === 'POST') {   // «Satzanfänge fortsetzen»: оценка продолжения 0–3 по критериям EPD
            self::needAiKey();
            $d = Http::json();
            $q = Util::str($d['q'] ?? '', 300); $ans = Util::str($d['answer'] ?? '', 500); $model = Util::str($d['model'] ?? '', 400);
            if ($ans === '' || $q === '') { throw new ApiError(400, 'Пустой ответ'); }
            $ck = 'sf:' . hash('sha256', json_encode([AiGen::MODEL, $q, $ans, $model], JSON_UNESCAPED_UNICODE));
            if ($c = Ai::cacheGet($ck)) { return $c; }
            $res = Ai::ask($u, 'sfort', AiGen::MODEL, self::prompt('lernen_sfort'), "Satzanfang: $q\nПродолжение студента: $ans\nОбразец: " . ($model ?: '—'),
                self::schema('lernen_sfort'), 500, 'low', 45, 'ИИ сейчас недоступен, попробуй ещё раз');
            Ai::cachePut($ck, $res);
            return $res;
        }

        if ($p === '/gwrite' && $method === 'POST') {
            self::needAiKey();
            $d = Http::json();
            $topic = Util::str($d['topic'] ?? '', 200); $task = Util::str($d['task'] ?? '', 400); $text = Util::str($d['text'] ?? '', 2500);
            if (count(preg_split('/\s+/u', $text, -1, PREG_SPLIT_NO_EMPTY)) < 5) { throw new ApiError(400, 'Напиши хотя бы пару предложений'); }
            $ck = 'gw:' . hash('sha256', json_encode([self::CTX_MODEL, $topic, $task, $text], JSON_UNESCAPED_UNICODE));
            if ($c = Ai::cacheGet($ck)) { return $c; }
            $res = Ai::ask($u, 'gwrite', self::CTX_MODEL, self::prompt('lernen_gwrite'), "Тема грамматики: $topic\nЗадание: $task\nТекст студента:\n$text",
                self::schema('lernen_gwrite'), 1500, null, 45, 'ИИ сейчас недоступен, попробуй ещё раз');
            Ai::cachePut($ck, $res);
            return $res;
        }

        if ($p === '/tts' && $method === 'POST') {
            $d = Http::json();
            $text = Util::str($d['text'] ?? '', 1500);
            if ($text === '') { throw new ApiError(400, 'Пустой текст'); }
            $v = Util::str($d['voice'] ?? '', 20);
            return self::ttsScript($u, [['x', $text]], ['x' => ['voice' => $v, 'style' => Util::str($d['style'] ?? '', 400)]]);
        }

        if ($p === '/audiolines' && $method === 'POST') {   // запись от ИИ (реплики приходят из браузера): тот же путь, что и у готовых записей
            $d = Http::json();
            $voices = [];
            foreach (is_array($d['voices'] ?? null) ? array_slice($d['voices'], 0, 6, true) : [] as $k => $v) {
                if (is_array($v)) { $voices[mb_substr((string)$k, 0, 1)] = ['voice' => Util::str($v['voice'] ?? '', 20), 'style' => Util::str($v['style'] ?? '', 400)]; }
            }
            $lines = []; $total = 0;
            foreach (array_slice(is_array($d['lines'] ?? null) ? $d['lines'] : [], 0, 80) as $ln) {
                if (!is_array($ln) || count($ln) < 2) { continue; }
                $t = Util::str($ln[1], 1500); $total += mb_strlen($t);
                if ($t !== '') { $lines[] = [mb_substr((string)$ln[0], 0, 1), $t]; }
            }
            if (!$lines || $total > 14000) { throw new ApiError(400, 'Нужна запись до 14 000 знаков'); }
            return self::ttsScript($u, $lines, $voices);
        }

        if ($method === 'POST' && preg_match('#^/audio/(de|frei)/([a-z0-9_-]{1,40})$#', $p, $m)) {
            // Готовая запись из нескольких реплик. Хостинг обрывает долгие запросы, поэтому за один вызов озвучиваем столько кусков, сколько успеваем,
            // и отвечаем pending=true — страница повторяет запрос, готовые куски берутся из кэша.
            $item = self::findItem($m[1], 'hoeren', $m[2]);
            if (!$item) { throw new ApiError(404, 'Нет такого аудио'); }
            return self::ttsScript($u, $item['lines'] ?? [], $item['voices'] ?? []);
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
            $item = in_array($d['task'] ?? '', ['ai', 'sex1', 'sex2'], true) ? AiGen::inlineTask($d['item'] ?? null) : self::findItem($area, 'sprechen', Util::str($d['task'] ?? '', 40));
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
            // разбор и реплика экзаменатора просим двумя короткими запросами (клиент шлёт part=review и part=reply одновременно): один длинный не успевает за лимит хостинга
            $part = Util::str($d['part'] ?? '', 6);
            $sys = self::prompt($area === 'frei' ? 'lernen_talk_frei' : 'lernen_talk_epd');
            if ($part === 'review') {
                $sys .= "\n\nСЕЙЧАС ТОЛЬКО ЧАСТЬ 1 (разбор): заполни transcript, corrected, errors, better, redemittel, content, score. Поля reply_de и reply_ru верни пустыми строками.";
                return Ai::ask($u, 'talk', ($d['task'] ?? '') === 'ai' ? AiGen::MODEL : self::TALK_MODEL, $sys, $content, self::schema('lernen_talk'), 1800, 'low', 25, 'ИИ сейчас недоступен, попробуй ещё раз');
            }
            if ($part === 'reply') {
                $sys .= "\n\nСЕЙЧАС ТОЛЬКО ЧАСТЬ 2 (реплика): заполни reply_de и reply_ru. Остальные строки верни пустыми, массивы пустыми, score = 3.";
                return Ai::ask($u, 'talk', self::CTX_MODEL, $sys, $content, self::schema('lernen_talk'), 700, 'low', 25, 'ИИ сейчас недоступен, попробуй ещё раз');
            }
            return Ai::ask($u, 'talk', ($d['task'] ?? '') === 'ai' ? AiGen::MODEL : self::TALK_MODEL, $sys, $content,
                self::schema('lernen_talk'), (int)cfg('talk_max_tokens', 2500), 'low', 25, 'ИИ сейчас недоступен, попробуй ещё раз');
        }

        if ($p === '/settings' && $method === 'GET') {
            $day = Util::localDay($now);
            $spent = (float)Db::val('SELECT COALESCE(SUM(cost),0) FROM ai_log WHERE day=?', [$day]) / 1e6;
            $month = (float)Db::val('SELECT COALESCE(SUM(cost),0) FROM ai_log WHERE ts>?', [$now - 30 * 86400]) / 1e6;
            // расход за сегодня по сервисам: Claude (текст, разбор, словарь) и OpenAI (озвучка, распознавание речи); DeepL бесплатный и не считается
            $by = ['claude' => ['n' => 0, 'usd' => 0.0, 'kinds' => []], 'openai' => ['n' => 0, 'usd' => 0.0, 'kinds' => []]];
            foreach (Db::all("SELECT kind, model, COUNT(*) AS n, COALESCE(SUM(cost),0) AS cost FROM ai_log WHERE day=? AND kind<>'block' AND err='' GROUP BY kind, model", [$day]) as $r) {
                $k = str_starts_with((string)$r['model'], 'claude') ? 'claude' : 'openai';
                $by[$k]['n'] += (int)$r['n']; $by[$k]['usd'] += (float)$r['cost'] / 1e6;
                $by[$k]['kinds'][$r['kind']] = ['n' => (int)$r['n'], 'usd' => round((float)$r['cost'] / 1e6, 4)];
            }
            foreach ($by as &$b) { $b['usd'] = round($b['usd'], 4); }
            unset($b);
            $mine = (int)Db::val("SELECT COUNT(*) FROM ai_log WHERE uid=? AND day=? AND kind<>'block'", [$uid, $day]);
            $inv = [];
            if ($u['role'] === 'owner') {
                $inv = Db::all('SELECT i.code,i.created,i.used,u.name AS used_name FROM invites i LEFT JOIN users u ON u.id=i.used_by ORDER BY i.created DESC LIMIT 30');
            }
            return ['keys' => self::keyStatus(), 'mine' => Keys::status((int)$u['id']), 'site_keys' => (bool)cfg('site_keys_for_users', false) || $u['role'] === 'owner', 'owner' => $u['role'] === 'owner', 'invites' => $inv,
                    'spent_today' => round($spent, 3), 'spent_month' => round($month, 2), 'budget_day' => Ai::SITE_DAY_USD,
                    'by_service' => $by, 'my_requests_today' => $mine, 'limits' => ['user_day' => Ai::USER_DAY, 'user_min' => Ai::USER_MIN, 'site_usd' => Ai::SITE_DAY_USD],
                    'models' => ['talk' => self::TALK_MODEL, 'ctx' => self::CTX_MODEL, 'tts' => self::TTS_MODEL, 'stt' => self::STT_MODEL]];
        }

        // старый адрес: ключ сохраняется в профиле самого пользователя (зашифрованно), а не в config.local.php
        if ($p === '/settings/key' && $method === 'POST') {
            $d = Http::json();
            return Keys::handleSave($u, ['provider' => $d['name'] ?? '', 'value' => $d['value'] ?? ''])  + ['msg' => 'Ключ сохраняется в вашем профиле'];
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
