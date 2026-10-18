<?php
/** EP Schreiben: порт schreiben_api.py (/api/schreiben/*). */
class Schreiben
{
    const GRADE_MODEL = 'claude-sonnet-5-5';
    const FAST_MODEL = 'claude-haiku-4-5';
    const MAX_STATE = 1048576;
    const TYPES = [
        'stell' => ['name' => 'Stellungnahme', 'min' => 230, 'max' => 250, 'pass' => 12, 'crit' => ['inhalt' => 5, 'aufbau' => 6, 'wortschatz' => 6, 'sprachrichtigkeit' => 8]],
        'eroert' => ['name' => 'Erörterung', 'min' => 200, 'max' => 220, 'pass' => 10, 'crit' => ['inhalt' => 4, 'aufbau' => 5, 'wortschatz' => 5, 'sprachrichtigkeit' => 6]],
    ];
    const LANG_DE = ['ru' => 'Russisch', 'en' => 'Englisch'];

    private static function load(string $name): array
    {
        return json_decode((string)file_get_contents(EPD_PUBLIC . '/schreiben/data/' . $name), true) ?: [];
    }

    public static function wordCount(string $text): int
    {
        return (int)preg_match_all("/[0-9A-Za-zÄÖÜäöüß]+(?:[-'’][0-9A-Za-zÄÖÜäöüß]+)*/u", $text);
    }

    private static function gradeSchema(array $crit): array
    {
        $s = Lernen::schema('schreiben_grade');
        $k = [];
        foreach ($crit as $name => $_) {
            $k[$name] = ['type' => 'object', 'additionalProperties' => false, 'required' => ['punkte', 'begruendung'],
                         'properties' => ['punkte' => ['type' => 'number'], 'begruendung' => ['type' => 'string']]];
        }
        $s['properties']['kriterien'] = ['type' => 'object', 'additionalProperties' => false, 'required' => array_keys($crit), 'properties' => $k];
        return $s;
    }

    private static function taskText(array $t): string
    {
        $imp = $t['impuls'] ?? [];
        $out = ['Thema: ' . ($t['titel'] ?? '')];
        if (($imp['art'] ?? '') === 'grafik') {
            $ch = $imp['chart'] ?? [];
            $out[] = 'Impuls: Grafik „' . ($imp['titel'] ?? '') . '“ (' . ($imp['quelle'] ?? '') . ')';
            $unit = $ch['unit'] ?? '';
            if (!empty($ch['series'])) {
                foreach ($ch['series'] as $sr) {
                    $pairs = [];
                    foreach (array_map(null, $ch['labels'] ?? [], $sr['values']) as [$l, $v]) { if ($l !== null && $v !== null) { $pairs[] = "$l $v$unit"; } }
                    $out[] = '  ' . $sr['name'] . ': ' . implode(', ', $pairs);
                }
            } else {
                $pairs = [];
                foreach (array_map(null, $ch['labels'] ?? [], $ch['values'] ?? []) as [$l, $v]) { if ($l !== null && $v !== null) { $pairs[] = "$l: $v$unit"; } }
                $out[] = '  ' . implode('; ', $pairs);
            }
        } else {
            $out[] = 'Impuls: „' . ($imp['titel'] ?? '') . "“\n" . ($imp['text'] ?? '');
        }
        $out[] = 'Leitpunkte:';
        foreach ($t['punkte'] ?? [] as $i => $p) { $out[] = '(' . ($i + 1) . ') ' . $p; }
        return implode("\n", $out);
    }

    private static function findTask(array $d): array
    {
        $tid = Util::str($d['task_id'] ?? '', 80);
        foreach (self::load('aufgaben.json')['items'] ?? [] as $t) { if ($t['id'] === $tid) { return $t; } }
        $t = $d['task'] ?? null;     // своё или сгенерированное задание приходит целиком
        if (!is_array($t) || !isset(self::TYPES[$t['typ'] ?? ''])) { throw new ApiError(400, 'Задание не найдено'); }
        $raw = json_encode($t, JSON_UNESCAPED_UNICODE);
        if (strlen($raw) > 6000) { throw new ApiError(400, 'Задание слишком длинное'); }
        $t['id'] = $tid ?: 'custom';
        return $t;
    }

    private static function noteFor(float $points, string $typ): int
    {
        if ($typ === 'stell') { $p = $points / 25 * 75; return $p >= 69 ? 1 : ($p >= 61 ? 2 : ($p >= 53 ? 3 : ($p >= 45 ? 4 : 5))); }
        $p = $points / 20 * 55;
        return $p >= 51 ? 1 : ($p >= 45 ? 2 : ($p >= 39 ? 3 : ($p >= 33 ? 4 : 5)));
    }

    private static function lang(array $u, array $d): string
    {
        $v = $d['lang'] ?? null;
        if (!isset(self::LANG_DE[$v])) { $v = $u['ui_lang'] ?? 'ru'; }
        return isset(self::LANG_DE[$v]) ? $v : 'ru';
    }

    private static function inLang(string $system, string $lang): string
    {
        return $lang === 'ru' ? $system : str_replace(['Russisch', 'по-русски'], [self::LANG_DE[$lang], 'по-английски'], $system);
    }

    private static function grade(array $u, array $d): array
    {
        $t = self::findTask($d);
        $typ = $t['typ'];
        $spec = self::TYPES[$typ];
        $text = trim(Util::str($d['text'] ?? '', 6000));
        $words = self::wordCount($text);
        if ($words < 40) { throw new ApiError(400, 'Слишком короткий текст: напиши хотя бы 40 слов'); }
        $minutes = max(0, min(600, (int)($d['minutes'] ?? 0)));
        $crit = [];
        foreach ($spec['crit'] as $k => $v) { $crit[] = ucfirst($k) . ' max. ' . $v; }
        $content = sprintf("Textsorte: %s, %d–%d Wörter, insgesamt %d Punkte (%s). Mindestens %d Punkte zum Bestehen dieses Teils.\nDer Kandidat hat %d Wörter geschrieben%s.\n\n%s\n\n--- Text des Kandidaten ---\n%s\n--- Ende ---",
            $spec['name'], $spec['min'], $spec['max'], array_sum($spec['crit']), implode(', ', $crit), $spec['pass'], $words,
            $minutes ? " in $minutes Minuten" : '', self::taskText($t), $text);
        $res = Ai::ask($u, 'schreiben_grade', cfg('grade_model', self::GRADE_MODEL), self::inLang(Lernen::prompt('schreiben_grade'), self::lang($u, $d)),
            $content, self::gradeSchema($spec['crit']), (int)cfg('grade_max_tokens', 6000), cfg('grade_effort', 'medium'), 150,
            'ИИ сейчас не отвечает, попробуй ещё раз через минуту');
        $total = 0.0;
        foreach ($spec['crit'] as $k => $mx) {      // ИИ не может выйти за рамки шкалы
            $p = $res['kriterien'][$k] ?? ['punkte' => 0, 'begruendung' => ''];
            $p['punkte'] = max(0.0, min((float)$mx, round((float)$p['punkte'] * 2) / 2));
            $p['max'] = $mx;
            $res['kriterien'][$k] = $p;
            $total += $p['punkte'];
        }
        $res = array_merge($res, ['gesamt' => $total, 'max' => array_sum($spec['crit']), 'bestanden' => $total >= $spec['pass'], 'mindest' => $spec['pass'],
            'note' => self::noteFor($total, $typ), 'words' => $words, 'min_words' => $spec['min'], 'max_words' => $spec['max'], 'typ' => $typ]);
        $pr = $d['protokoll'] ?? null;
        if (is_array($pr)) {       // пробный экзамен: протокол честности хранится вместе с разбором
            $num = fn($v) => is_numeric($v) ? max(0, min(1000000, (int)$v)) : 0;
            $ev = [];
            foreach (array_slice(is_array($pr['ev'] ?? null) ? $pr['ev'] : [], 0, 300) as $e) {
                if (is_array($e)) { $ev[] = ['type' => Util::str($e['type'] ?? '', 12), 'at' => $num($e['at'] ?? 0), 's' => $num($e['s'] ?? 0), 'n' => $num($e['n'] ?? 0)]; }
            }
            $res['protokoll'] = ['mode' => 'exam', 'dur' => $num($pr['dur'] ?? 0), 'used' => $num($pr['used'] ?? 0), 'auto' => !empty($pr['auto']), 'ev' => $ev];
        }
        Db::run('INSERT INTO schreiben_essays(user_id,ts,task_id,typ,title,text,words,minutes,points,max_points,task,result) VALUES(?,?,?,?,?,?,?,?,?,?,?,?)',
            [$u['id'], time(), $t['id'], $typ, mb_substr($t['titel'] ?? '', 0, 120), $text, $words, $minutes, $total, $res['max'],
             json_encode($t, JSON_UNESCAPED_UNICODE), json_encode($res, JSON_UNESCAPED_UNICODE | JSON_PRESERVE_ZERO_FRACTION)]);
        $res['id'] = Db::insertId();
        return $res;
    }

    private static function satz(array $u, array $d): array
    {
        $start = Util::str($d['start'] ?? '', 200); $text = Util::str($d['text'] ?? '', 400);
        if (self::wordCount($text) < 2) { throw new ApiError(400, 'Продолжи предложение'); }
        return Ai::ask($u, 'schreiben_satz', self::FAST_MODEL, self::inLang(Lernen::prompt('schreiben_satz'), self::lang($u, $d)),
            "Satzanfang: $start\nFortsetzung des Kandidaten: $text", Lernen::schema('schreiben_satz'), 1200, null, 45, 'ИИ сейчас не отвечает, попробуй ещё раз через минуту');
    }

    private static function generate(array $u, array $d): array
    {
        $typ = isset(self::TYPES[$d['typ'] ?? '']) ? $d['typ'] : 'stell';
        $data = self::load('aufgaben.json');
        $thema = isset($data['themen'][$d['thema'] ?? '']) ? $d['thema'] : null;
        $art = in_array($d['art'] ?? '', ['text', 'grafik'], true) ? $d['art'] : null;
        $have = implode(', ', array_column($data['items'], 'titel')) . ', ' . Util::str($d['have'] ?? '', 600);
        $req = "typ: $typ\nThema: " . ($thema ? $data['themen'][$thema] : 'frei wählbar aus den 10 EPD-Themen') . "\n"
            . (($art && $typ === 'stell') ? "Impuls: $art\n" : '') . "Vorhandene Aufgaben: $have";
        $t = Ai::ask($u, 'schreiben_gen', self::FAST_MODEL, Lernen::prompt('schreiben_gen'), $req, Lernen::schema('schreiben_gen'), 2500, null, 45,
            'ИИ сейчас не отвечает, попробуй ещё раз через минуту');
        if (($t['impuls']['art'] ?? '') !== 'grafik') { unset($t['impuls']['chart']); }
        return array_merge($t, ['id' => 'gen-' . (int)(microtime(true) * 1000), 'typ' => $typ, 'thema' => $thema ?: '', 'gen' => true]);
    }

    /** $sub — путь после /api/schreiben/. */
    public static function route(array $u, string $method, string $sub): array
    {
        $uid = (int)$u['id'];
        if ($sub === 'state' && $method === 'GET') {
            $r = Db::one('SELECT data FROM schreiben_state WHERE user_id=?', [$uid]);
            $rows = Db::all('SELECT id,ts,task_id,typ,title,words,minutes,points,max_points,result FROM schreiben_essays WHERE user_id=? ORDER BY ts DESC LIMIT 200', [$uid]);
            $essays = [];
            foreach ($rows as $e) {      // баллы по критериям нужны главной и «прогрессу» на любом устройстве
                $res = json_decode($e['result'], true);
                unset($e['result']);
                if (is_array($res) && isset($res['kriterien']) && is_array($res['kriterien'])) {
                    $crit = [];
                    foreach ($res['kriterien'] as $k => $v) { $crit[$k] = [$v['punkte'], $v['max']]; }
                    $e['crit'] = $crit;
                }
                $essays[] = $e;
            }
            return ['data' => $r ? Util::jd($r['data']) : null, 'essays' => $essays, 'ai' => (bool)Ai::key()];
        }
        if ($sub === 'state' && $method === 'PUT') {
            $body = Http::jsonObj();
            $raw = json_encode($body->data ?? new stdClass(), JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
            if ($raw === '[]' || $raw === 'null' || $raw === 'false' || $raw === '0' || $raw === '""') { $raw = '{}'; }
            if (strlen($raw) > self::MAX_STATE) { throw new ApiError(413, 'Слишком много данных'); }
            Db::run('REPLACE INTO schreiben_state(user_id,data,updated) VALUES(?,?,?)', [$uid, $raw, time()]);
            return ['ok' => true];
        }
        if (preg_match('#^essay/(\d+)$#', $sub, $m)) {
            if ($method === 'GET') {
                $r = Db::one('SELECT * FROM schreiben_essays WHERE id=? AND user_id=?', [(int)$m[1], $uid]);
                if (!$r) { throw new ApiError(404, 'Текст не найден'); }
                $r['task'] = Util::jd($r['task']); $r['result'] = Util::jd($r['result']);
                return $r;
            }
            if ($method === 'DELETE') { Db::run('DELETE FROM schreiben_essays WHERE id=? AND user_id=?', [(int)$m[1], $uid]); return ['ok' => true]; }
        }
        if ($method === 'POST' && in_array($sub, ['grade', 'satz', 'generate'], true)) {
            $d = Http::json();
            return $sub === 'grade' ? self::grade($u, $d) : ($sub === 'satz' ? self::satz($u, $d) : self::generate($u, $d));
        }
        throw new ApiError(404, 'Нет такого метода');
    }
}
