<?php
/** Генерация ИИ короткими шагами: хостинг обрывает запрос примерно через полминуты, поэтому длинный текст, запись или набор заданий
 *  собираются в браузере из нескольких коротких запросов (план → абзацы/блоки → задания), каждый из которых успевает за 5–15 секунд. */
class Gen
{
    const MODEL = 'claude-haiku-5-5';
    const STEPS = ['lplan', 'lpara', 'hplan', 'hseg', 'hmc', 'hnotes', 'gtheory', 'gitems'];

    /** Системная подсказка шага + общая приписка про австрийский немецкий. */
    public static function sys(string $name): string
    {
        return Lernen::prompt($name) . "\n\n" . Lernen::prompt('gen_austrian');
    }

    private static function call(array $u, string $name, string $content, int $tokens, string $kind): array
    {
        return Ai::ask($u, $kind, self::MODEL, self::sys($name), $content, Lernen::schema($name), $tokens, 'low', 25, 'ИИ сейчас недоступен, попробуй ещё раз');
    }

    private static function brief(array $d): string
    {
        $topic = Util::str($d['topic'] ?? '', 200);
        $level = in_array($d['level'] ?? '', ['B2', 'C1'], true) ? $d['level'] : 'B2';
        $diff = max(-2, min(2, (int)($d['diff'] ?? 0)));
        $prev = [];
        foreach (array_slice(is_array($d['prev'] ?? null) ? $d['prev'] : [], 0, 20) as $x) { $x = Util::str($x, 160); if ($x !== '') { $prev[] = $x; } }
        return 'Тема: ' . ($topic !== '' ? $topic : '(не указана, выбери сам)') . "\nУровень: $level\nСложность: $diff"
            . ($prev ? "\nУже было (не повторяй):\n- " . implode("\n- ", $prev) : '');
    }

    private static function list($v, int $n, int $m): array
    {
        return array_values(array_filter(array_map(fn($x) => Util::str($x, $m), array_slice(is_array($v) ? $v : [], 0, $n)), fn($x) => $x !== ''));
    }

    private static function wc(string $s): int { return count(preg_split('/\s+/u', trim($s), -1, PREG_SPLIT_NO_EMPTY)); }

    public static function route(array $u, array $d): array
    {
        $step = Util::str($d['step'] ?? '', 12);
        if (!in_array($step, self::STEPS, true)) { throw new ApiError(400, 'Неизвестный шаг'); }
        return self::$step($u, $d);
    }

    // ---------- Lesen: план → абзацы ----------
    private static function lplan(array $u, array $d): array
    {
        $topic = Util::str($d['topic'] ?? '', 200);
        if (mb_strlen($topic) < 3) { throw new ApiError(400, 'Напиши тему хотя бы в пару слов'); }
        $r = self::call($u, 'gen_lplan', self::brief($d), 1800, 'lplan');
        $paras = self::list($r['paras'] ?? [], 9, 500);
        $title = Util::str($r['title'] ?? '', 140);
        if (count($paras) < 7 || $title === '') { throw new ApiError(502, 'ИИ вернул неполный план текста, попробуй ещё раз', ['retry' => true]); }
        return ['title' => $title, 'kicker' => Util::str($r['kicker'] ?? '', 60) ?: $topic, 'lead' => Util::str($r['lead'] ?? '', 400), 'paras' => array_slice($paras, 0, 8)];
    }

    private static function lpara(array $u, array $d): array
    {
        $plan = is_array($d['plan'] ?? null) ? $d['plan'] : [];
        $bp = self::list($plan['paras'] ?? [], 9, 500);
        $i = Util::int($d['i'] ?? 0, 1, 9);
        if (count($bp) < 7 || $i > count($bp)) { throw new ApiError(400, 'Нужен план текста'); }
        $content = 'Заголовок: ' . Util::str($plan['title'] ?? '', 140) . "\nПодводка: " . Util::str($plan['lead'] ?? '', 400) . "\nПлан абзацев:\n";
        foreach ($bp as $k => $b) { $content .= ($k + 1) . '. ' . $b . "\n"; }
        $level = in_array($d['level'] ?? '', ['B2', 'C1'], true) ? $d['level'] : 'B2';
        $content .= "\nУровень: $level\nСложность: " . max(-2, min(2, (int)($d['diff'] ?? 0))) . "\nНапиши абзац номер $i из " . count($bp) . '.';
        $r = self::call($u, 'gen_lpara', $content, 900, 'lpara');
        $text = Util::str($r['text'] ?? '', 1800);
        $text = trim(preg_replace('/^\s*(Absatz\s*)?\d+[.:)]\s*/u', '', $text));
        if (self::wc($text) < 70) { throw new ApiError(502, 'ИИ вернул слишком короткий абзац, попробуй ещё раз', ['retry' => true]); }
        return ['text' => $text];
    }

    // ---------- Hören: план → блоки → задания ----------
    private static function hplan(array $u, array $d): array
    {
        $r = self::call($u, 'gen_hplan', self::brief($d), 2000, 'hplan');
        $sp = []; $seen = [];
        foreach (array_slice(is_array($r['speakers'] ?? null) ? $r['speakers'] : [], 0, 4) as $x) {
            if (!is_array($x)) { continue; }
            $id = strtoupper(mb_substr(preg_replace('/[^A-Za-z]/', '', (string)($x['id'] ?? '')), 0, 1));
            if ($id === '' || isset($seen[$id])) { continue; }
            $seen[$id] = 1;
            $sp[] = ['id' => $id, 'name' => Util::str($x['name'] ?? '', 40) ?: $id, 'female' => !empty($x['female'])];
        }
        $seg = self::list($r['segments'] ?? [], 9, 600);
        if (count($sp) < 1 || count($seg) < 7) { throw new ApiError(502, 'ИИ вернул неполный план записи, попробуй ещё раз', ['retry' => true]); }
        return ['kind' => Util::str($r['kind'] ?? '', 40) ?: 'Radiointerview', 'title' => Util::str($r['title'] ?? '', 140) ?: 'Hörverstehen', 'intro' => Util::str($r['intro'] ?? '', 300),
                'speakers' => $sp, 'segments' => array_slice($seg, 0, 8)];
    }

    private static function hseg(array $u, array $d): array
    {
        $plan = is_array($d['plan'] ?? null) ? $d['plan'] : [];
        $seg = self::list($plan['segments'] ?? [], 9, 600);
        $i = Util::int($d['i'] ?? 0, 1, 9);
        $sp = [];
        foreach (array_slice(is_array($plan['speakers'] ?? null) ? $plan['speakers'] : [], 0, 4) as $x) {
            if (is_array($x) && !empty($x['id'])) { $sp[strtoupper(mb_substr((string)$x['id'], 0, 1))] = Util::str($x['name'] ?? '', 40) . (!empty($x['female']) ? ' (weiblich)' : ' (männlich)'); }
        }
        if (count($seg) < 7 || $i > count($seg) || !$sp) { throw new ApiError(400, 'Нужен план записи'); }
        $content = 'Формат: ' . Util::str($plan['kind'] ?? '', 40) . "\nНазвание: " . Util::str($plan['title'] ?? '', 140) . "\nГоворящие:\n";
        foreach ($sp as $id => $nm) { $content .= "$id — $nm\n"; }
        $content .= "План блоков:\n";
        foreach ($seg as $k => $b) { $content .= ($k + 1) . '. ' . $b . "\n"; }
        $level = in_array($d['level'] ?? '', ['B2', 'C1'], true) ? $d['level'] : 'B2';
        $content .= "\nУровень: $level\nСложность: " . max(-2, min(2, (int)($d['diff'] ?? 0))) . "\nНапиши реплики для блока номер $i из " . count($seg) . '.';
        $r = self::call($u, 'gen_hseg', $content, 1400, 'hseg');
        $first = array_key_first($sp);
        $lines = []; $words = 0;
        foreach (array_slice(is_array($r['lines'] ?? null) ? $r['lines'] : [], 0, 10) as $ln) {
            if (!is_array($ln)) { continue; }
            $s = strtoupper(mb_substr(preg_replace('/[^A-Za-z]/', '', (string)($ln['s'] ?? '')), 0, 1));
            $t = Util::str($ln['text'] ?? '', 1500);
            $t = trim(preg_replace('/\([^)]{0,60}\)|\*/u', '', $t));
            if ($t === '') { continue; }
            $lines[] = [isset($sp[$s]) ? $s : $first, $t]; $words += self::wc($t);
        }
        if ($words < 100) { throw new ApiError(502, 'ИИ вернул слишком короткий блок записи, попробуй ещё раз', ['retry' => true]); }
        return ['lines' => $lines, 'words' => $words];
    }

    private static function transcript(array $d): string
    {
        $voices = is_array($d['voices'] ?? null) ? $d['voices'] : [];
        $txt = '';
        foreach (array_slice(is_array($d['lines'] ?? null) ? $d['lines'] : [], 0, 90) as $ln) {
            if (!is_array($ln) || count($ln) < 2) { continue; }
            $name = Util::str(is_array($voices[$ln[0]] ?? null) ? ($voices[$ln[0]]['name'] ?? $ln[0]) : $ln[0], 40);
            $t = Util::str($ln[1], 1500);
            if ($t !== '') { $txt .= $name . ': ' . $t . "\n"; }
        }
        if (self::wc($txt) < 600) { throw new ApiError(400, 'Нужен текст записи'); }
        return $txt;
    }

    private static function hmc(array $u, array $d): array
    {
        $txt = self::transcript($d);
        $r = self::call($u, 'gen_hmc', "Транскрипт:\n" . $txt, 2400, 'hmc');
        $items = [];
        foreach (array_slice(is_array($r['items'] ?? null) ? $r['items'] : [], 0, 9) as $x) {
            if (!is_array($x)) { continue; }
            $o = self::list($x['o'] ?? [], 6, 300); $ai = is_numeric($x['ai'] ?? null) ? (int)$x['ai'] : -1; $q = Util::str($x['q'] ?? '', 400);
            if ($q === '' || count($o) !== 4 || $ai < 0 || $ai > 3 || count(array_unique($o)) !== 4) { continue; }
            $right = $o[$ai];
            mt_srand((int)(crc32($q . $right) & 0x7fffffff)); shuffle($o); mt_srand();      // верный вариант не прячем всегда на одном месте
            $items[] = ['q' => $q, 'o' => $o, 'a' => array_search($right, $o, true), 'ex' => Util::str($x['ex'] ?? '', 400)];
        }
        if (count($items) < 6) { throw new ApiError(502, 'ИИ вернул слишком мало вопросов, попробуй ещё раз', ['retry' => true]); }
        return ['items' => array_slice($items, 0, 7)];
    }

    private static function hnotes(array $u, array $d): array
    {
        $txt = self::transcript($d);
        $r = self::call($u, 'gen_hnotes', "Транскрипт:\n" . $txt, 2200, 'hnotes');
        $srcWords = array_flip(AiGen::words($txt));
        $inText = fn(string $form) => $form !== '' && !array_diff(AiGen::words($form), array_keys($srcWords));
        $notes = Util::str($r['notes'] ?? '', 2000);
        preg_match_all('/\[\[(\d+)\]\]/', $notes, $mm);
        $gapsRaw = array_values(array_filter(is_array($r['gaps'] ?? null) ? $r['gaps'] : [], 'is_array'));
        if (count($mm[1]) < 6 || count($mm[1]) !== count($gapsRaw)) { throw new ApiError(502, 'ИИ вернул неполный конспект, попробуй ещё раз', ['retry' => true]); }
        $keep = []; $gaps = [];
        foreach ($gapsRaw as $k => $x) {
            $at = self::list($x['at'] ?? [], 3, 60);
            $forms = array_values(array_filter($at, fn($a) => $inText($a) && count(AiGen::words($a)) <= 3));
            if ($forms) { $gaps[] = $forms; $keep[$k + 1] = count($gaps); }
            else { $keep[$k + 1] = $at[0] ?? false; }   // слова нет в записи дословно: подставляем в текст готовым
        }
        if (count($gaps) < 6) { throw new ApiError(502, 'ИИ вернул неполный конспект, попробуй ещё раз', ['retry' => true]); }
        $notes = preg_replace_callback('/\[\[(\d+)\]\]/', function ($m) use ($keep) {
            $v = $keep[(int)$m[1]] ?? false;
            return is_int($v) ? '[[' . $v . ']]' : (string)$v;
        }, $notes);
        return ['task' => ['t' => 'sum', 'q' => 'Aufgabe 2: Hören Sie den Beitrag und ergänzen Sie die Notizen. Schreiben Sie das Wort oder die Wörter, die Sie hören (höchstens zwei).', 'text' => $notes, 'a' => $gaps]];
    }

    // ---------- Grammatik: теория + три вида заданий (C-Test, Umformen, Rektion / Lückentext) ----------
    private static function gtheory(array $u, array $d): array
    {
        $topic = Util::str($d['topic'] ?? '', 200);
        if (mb_strlen($topic) < 3) { throw new ApiError(400, 'Напиши тему хотя бы в пару слов'); }
        $r = self::call($u, 'gen_gtheory', self::brief($d), 1800, 'gtheory');
        $theory = AiGen::cleanHtml((string)($r['theory'] ?? ''));
        if (mb_strlen(strip_tags($theory)) < 200) { throw new ApiError(502, 'ИИ вернул слишком короткую теорию, попробуй ещё раз', ['retry' => true]); }
        return ['title' => Util::str($r['title'] ?? '', 140) ?: $topic, 'ru' => Util::str($r['ru'] ?? '', 200), 'theory' => $theory];
    }

    private static function gitems(array $u, array $d): array
    {
        $kind = Util::str($d['kind'] ?? '', 4);
        if (!in_array($kind, ['ct', 'lt', 'uf', 'rk'], true)) { throw new ApiError(400, 'Неизвестный тип заданий'); }
        $r = self::call($u, 'gen_gitems', 'kind = "' . $kind . "\"\n" . self::brief($d), 2600, 'gitems');
        $rows = array_values(array_filter(is_array($r['items'] ?? null) ? $r['items'] : [], 'is_array'));
        $out = [];
        if ($kind === 'ct') {
            $x = $rows[0] ?? [];
            $q = Util::str($x['q'] ?? '', 900);
            preg_match_all('/\[\[(\d+)\]\]/', $q, $mm);
            $a = self::list($x['a'] ?? [], 8, 40);
            if (count($mm[1]) < 4 || count($mm[1]) !== count($a)) { throw new ApiError(502, 'ИИ вернул неполный C-Test, попробуй ещё раз', ['retry' => true]); }
            foreach ($a as $w) { if (mb_strlen($w) < 4 || str_contains($w, ' ')) { throw new ApiError(502, 'ИИ вернул неполный C-Test, попробуй ещё раз', ['retry' => true]); } }
            return ['items' => [['t' => 'ct', 'lv' => 3, 'q' => $q, 'a' => $a, 'ex' => Util::str($x['ex'] ?? '', 400), 'task' => 'C-Test: ergänzen Sie die fehlenden Buchstaben']]];
        }
        foreach ($rows as $x) {
            $q = Util::str($x['q'] ?? '', 600);
            $a = self::list($x['a'] ?? [], 10, 300);
            if ($q === '' || !$a) { continue; }
            $ex = Util::str($x['ex'] ?? '', 400);
            if ($kind === 'uf') {
                if (!preg_match('/^(.*?)\[\[(.+?)\]\](.*)$/su', $q, $m)) { continue; }
                [$pre, $post] = [$m[1], $m[3]];
                if (($x['dir'] ?? '') === 'v2n') {                 // в ответ идёт Nominalgruppe: запятая у границы не нужна
                    if (trim($pre) === '') { $post = ' ' . ltrim($post, ', '); }
                    $pre = preg_replace('/,\s*$/u', ' ', $pre);
                } elseif (trim($pre) === '' && !preg_match('/^\s*,/u', $post)) { $post = ',' . $post; }   // в ответ идёт Nebensatz: запятая нужна
                $out[] = ['t' => 'nf', 'lv' => 3, 'q' => $q, 'pre' => $pre, 'post' => $post, 'a' => $a, 'ex' => $ex, 'task' => 'Formen Sie den unterstrichenen Textteil um, ohne den Inhalt zu verändern'];
            } else {
                if (!str_contains($q, '___')) { continue; }
                $o = ['t' => 'gap', 'lv' => 3, 'q' => $q, 'a' => $a, 'hint' => Util::str($x['hint'] ?? '', 200), 'ex' => $ex];
                if ($kind === 'rk') { $o['rk'] = true; $o['task'] = 'Rektion: ergänzen Sie Präposition und Kasus'; }
                $out[] = $o;
            }
        }
        if (count($out) < 4) { throw new ApiError(502, 'ИИ вернул слишком мало заданий, попробуй ещё раз', ['retry' => true]); }
        return ['items' => array_slice($out, 0, 5)];
    }
}
