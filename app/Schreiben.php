<?php
/** EP Schreiben: порт schreiben_api.py (/api/schreiben/*). */
class Schreiben
{
    const GRADE_MODEL = 'claude-sonnet-5-5';
    const FAST_MODEL = 'claude-haiku-5-5';
    const MAX_STATE = 1048576;
    const TYPES = [
        'stell' => ['name' => 'Stellungnahme', 'min' => 230, 'max' => 250, 'pass' => 12, 'crit' => ['inhalt' => 5, 'aufbau' => 6, 'wortschatz' => 6, 'sprachrichtigkeit' => 8]],
        'eroert' => ['name' => 'Erörterung', 'min' => 200, 'max' => 220, 'pass' => 12, 'crit' => ['inhalt' => 5, 'aufbau' => 6, 'wortschatz' => 6, 'sprachrichtigkeit' => 8]],
    ];
    const LANG_DE = ['ru' => 'Russisch', 'en' => 'Englisch'];

    private static function load(string $name): array
    {
        return json_decode((string)file_get_contents(EPD_PUBLIC . '/data/schreiben/' . $name), true) ?: [];
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

    /** Схема оценки только с нужными полями (оценка идёт частями, чтобы каждая часть успевала за лимит хостинга). */
    private static function partSchema(array $crit, array $keys): array
    {
        $full = self::gradeSchema($crit);
        $props = array_intersect_key($full['properties'], array_flip($keys));
        return ['type' => 'object', 'additionalProperties' => false, 'required' => array_keys($props), 'properties' => $props];
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

    /** Schreiben — всегда 25 баллов (Inhalt 5, Aufbau 6, Wortschatz 6, Sprachrichtigkeit 8); оценка по шкале всей письменной части (75 баллов). */
    private static function noteFor(float $points, string $typ): int
    {
        $p = $points / 25 * 75;
        return $p >= 69 ? 1 : ($p >= 61 ? 2 : ($p >= 53 ? 3 : ($p >= 45 ? 4 : 5)));
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

    /** Части оценки: errors (ошибки в половине текста), rewrite (исправленный текст и «как на C1»), save (склейка в сохранённый разбор). */
    private static function gradePart(array $u, array $d, string $part): array
    {
        $id = Util::int($d['id'] ?? 0, 0, 2000000000);
        $row = Db::one('SELECT * FROM schreiben_essays WHERE id=? AND user_id=?', [$id, $u['id']]);
        if (!$row) { throw new ApiError(404, 'Текст не найден'); }
        $t = Util::jd($row['task']);
        $typ = $row['typ'];
        $spec = self::TYPES[$typ] ?? self::TYPES['stell'];
        if ($part === 'save') {
            $res = Util::jd($row['result']);
            foreach (['fehler', 'korrigiert', 'besser'] as $k) { if (array_key_exists($k, $d)) { $res[$k] = $d[$k]; } }
            Db::run('UPDATE schreiben_essays SET result=? WHERE id=? AND user_id=?', [json_encode($res, JSON_UNESCAPED_UNICODE | JSON_PRESERVE_ZERO_FRACTION), $id, $u['id']]);
            return ['ok' => true];
        }
        $text = (string)$row['text'];
        $sys = self::inLang(Lernen::prompt('schreiben_grade'), self::lang($u, $d));
        $head = 'Aufgabe:\n' . self::taskText($t) . "\n\n--- Text des Kandidaten ---\n" . $text . "\n--- Ende ---\n";
        if ($part === 'errors') {
            $half = max(1, min(2, (int)($d['half'] ?? 1)));
            $sents = preg_split('/(?<=[.!?])\s+/u', $text, -1, PREG_SPLIT_NO_EMPTY);
            $cut = (int)ceil(count($sents) / 2);
            $section = implode(' ', $half === 1 ? array_slice($sents, 0, $cut) : array_slice($sents, $cut));
            $content = $head . "\nNUR TEIL «fehler»: Suche sprachliche Fehler ausschließlich im folgenden Abschnitt (Abschnitt $half von 2), höchstens 14, in der Reihenfolge des Textes. Alle anderen Felder bleiben leer.\n--- Abschnitt ---\n" . $section . "\n--- Ende ---";
            $res = Ai::ask($u, 'schreiben_err', self::FAST_MODEL, $sys, $content, self::partSchema($spec['crit'], ['fehler']), 2600, 'low', 25, 'ИИ сейчас не отвечает, попробуй ещё раз');
            return ['fehler' => $res['fehler'] ?? []];
        }
        if ($part === 'rewrite') {
            $content = $head . "\nNUR TEIL «korrigiert» und «besser»: korrigiere den ganzen Text mit minimalen Eingriffen (Absätze beibehalten) und gib 3–5 Stellen für «besser». Alle anderen Felder bleiben leer.";
            $res = Ai::ask($u, 'schreiben_rew', self::FAST_MODEL, $sys, $content, self::partSchema($spec['crit'], ['korrigiert', 'besser']), 2800, 'low', 25, 'ИИ сейчас не отвечает, попробуй ещё раз');
            return ['korrigiert' => $res['korrigiert'] ?? '', 'besser' => $res['besser'] ?? []];
        }
        throw new ApiError(400, 'Неизвестная часть оценки');
    }

    private static function grade(array $u, array $d): array
    {
        $part = Util::str($d['part'] ?? 'score', 8);
        if ($part !== 'score') { return self::gradePart($u, $d, $part); }
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
            $content . "\n\nNUR TEIL «Bewertung»: fülle kriterien, leitpunkte, staerken, tipps, schablone, pruefer_kommentar und fazit. Die Felder fehler, korrigiert und besser werden separat erstellt: gib dafür leere Werte zurück.",
            self::partSchema($spec['crit'], ['kriterien', 'leitpunkte', 'staerken', 'tipps', 'schablone', 'pruefer_kommentar', 'fazit']), (int)cfg('grade_max_tokens', 3000), cfg('grade_effort', 'low'), 25,
            'ИИ сейчас не отвечает, попробуй ещё раз через минуту');
        $total = 0.0;
        foreach ($spec['crit'] as $k => $mx) {      // ИИ не может выйти за рамки шкалы
            $p = $res['kriterien'][$k] ?? ['punkte' => 0, 'begruendung' => ''];
            $p['punkte'] = max(0.0, min((float)$mx, round((float)$p['punkte'] * 2) / 2));
            $p['max'] = $mx;
            $res['kriterien'][$k] = $p;
            $total += $p['punkte'];
        }
        $res = array_merge(['fehler' => [], 'korrigiert' => '', 'besser' => []], $res, ['gesamt' => $total, 'max' => array_sum($spec['crit']), 'bestanden' => $total >= $spec['pass'], 'mindest' => $spec['pass'],
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
            return ['data' => $r ? Util::jd($r['data']) : null, 'essays' => $essays, 'ai' => Ai::keyStatus()['anthropic']];
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
