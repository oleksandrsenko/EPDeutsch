<?php
/** «Практика с ИИ»: тема от студента → теория и задания (Grammatik), статья (Lesen), аудио (Hören), задание для разговора (Sprechen).
 *  ИИ возвращает данные в формате, близком к data/de/*.json; здесь они проверяются и приводятся к нему один в один,
 *  поэтому обычные экраны модулей показывают их без отдельного кода. */
class AiGen
{
    const MODEL = 'claude-haiku-5-5';
    const MODULES = ['sprechen'];
    const MODES = ['new', 'more', 'harder', 'easier'];
    const FEMALE = ['sage', 'coral', 'nova'];
    const MALE = ['onyx', 'echo', 'ash'];

    /** Практика с ИИ для Sprechen (Lesen, Hören и Grammatik идут короткими шагами через Gen). @return array{0:string,1:string,2:array,3:int} */
    public static function request(array $d): array
    {
        $mod = Util::str($d['module'] ?? '', 20);
        $topic = Util::str($d['topic'] ?? '', 200);
        if (!in_array($mod, self::MODULES, true) || mb_strlen($topic) < 3) { throw new ApiError(400, 'Напиши тему хотя бы в пару слов'); }
        $mode = in_array($d['mode'] ?? '', self::MODES, true) ? $d['mode'] : 'new';
        $level = in_array($d['level'] ?? '', ['B2', 'C1'], true) ? $d['level'] : 'B2';
        $diff = max(-2, min(2, (int)($d['diff'] ?? 0)));
        $prev = [];
        foreach (array_slice(is_array($d['prev'] ?? null) ? $d['prev'] : [], 0, 12) as $x) { $s = Util::str($x, 160); if ($s !== '') { $prev[] = $s; } }
        $content = "Тема: $topic\nУровень: $level\nСложность: $diff\nРежим: $mode"
            . ($prev ? "\nУже было (не повторяй):\n- " . implode("\n- ", $prev) : '');
        return [Lernen::prompt('lernen_aigen_' . $mod), $content, Lernen::schema('lernen_aigen_' . $mod), 1800];
    }

    public static function normalize(string $mod, array $r, string $topic, string $level, string $mode): array
    {
        $s = fn($v, $n = 600) => Util::str($v, $n);
        $list = fn($v, $n, $m) => array_values(array_filter(array_map(fn($x) => $s($x, $m), array_slice(is_array($v) ? $v : [], 0, $n)), fn($x) => $x !== ''));
        $title = $s($r['title'] ?? '', 140) ?: $topic;
        $kind = in_array($r['kind'] ?? '', ['These', 'Text', 'Zitat'], true) ? $r['kind'] : 'These';
        $imp = $s($r['impulse'] ?? '', 1200);
        $lf = $list($r['leitfragen'] ?? [], 6, 300);
        if ($imp === '' || count($lf) < 2) { throw new ApiError(502, 'ИИ вернул неполное задание, попробуй ещё раз'); }
        return ['id' => 'ai', 'topic' => $topic, 'kind' => $kind, 'title' => $title, 'ru' => $s($r['ru'] ?? '', 240), 'impulse' => $imp,
                'leitfragen' => $lf, 'fragen' => $list($r['fragen'] ?? [], 6, 300), 'redemittel' => $list($r['redemittel'] ?? [], 10, 120)];
    }

    /** Пробный экзамен по лексике: 12 заданий «слово в контексте» (a–d) и 8 пропусков с русской подсказкой. */
    public static function wexamRequest(array $d): array
    {
        $topic = Util::str($d['topic'] ?? '', 120);
        $level = in_array($d['level'] ?? '', ['B2', 'C1'], true) ? $d['level'] : 'B2';
        $prev = [];
        foreach (array_slice(is_array($d['prev'] ?? null) ? $d['prev'] : [], 0, 30) as $x) { $x = Util::str($x, 120); if ($x !== '') { $prev[] = $x; } }
        $content = "Тема: " . ($topic !== '' ? $topic : '(не указана)') . "\nУровень: $level" . ($prev ? "\nУже было (не повторяй):\n- " . implode("\n- ", $prev) : '');
        return [Lernen::prompt('lernen_wexam'), $content, Lernen::schema('lernen_wexam'), 4500];
    }

    public static function wexamNormalize(array $r): array
    {
        $out = self::tasks($r['tasks'] ?? [], ['mc', 'gap'], 20, false);
        $mc = array_values(array_filter($out, fn($x) => $x['t'] === 'mc' && count($x['o']) === 4));
        $gp = array_values(array_filter($out, fn($x) => $x['t'] === 'gap'));
        if (count($mc) < 8 || count($gp) < 5) { throw new ApiError(502, 'ИИ вернул неполный экзамен, попробуй ещё раз'); }
        return array_merge(array_slice($mc, 0, 12), array_slice($gp, 0, 8));
    }

    /** Приводит задания к формату data/de/*.json и отбрасывает битые. */
    public static function tasks($raw, array $types, int $max, bool $lv, bool $two = false, ?string $src = null): array
    {
        $out = [];
        foreach (array_slice(is_array($raw) ? $raw : [], 0, $max + 4) as $x) {
            if (!is_array($x) || !in_array($x['t'] ?? '', $types, true)) { continue; }
            $q = Util::str($x['q'] ?? '', 500); if ($q === '') { continue; }
            $at = array_values(array_filter(array_map(fn($a) => Util::str($a, 300), is_array($x['at'] ?? null) ? array_slice($x['at'], 0, 4) : []), fn($a) => $a !== ''));
            $t = $x['t']; $o = ['t' => $t];
            if ($lv) { $o['lv'] = 1; }
            $o['q'] = $q;
            if ($t === 'mc') {
                $opts = array_values(array_filter(array_map(fn($a) => Util::str($a, 300), is_array($x['o'] ?? null) ? array_slice($x['o'], 0, 6) : []), fn($a) => $a !== ''));
                $ai = is_numeric($x['ai'] ?? null) ? (int)$x['ai'] : -1;
                if (count($opts) < 2 || $ai < 0 || $ai >= count($opts)) { continue; }
                $o['o'] = $opts; $o['a'] = $ai;
            } elseif ($t === 'tf') {
                $a = $at[0] ?? ''; if (!in_array($a, ['r', 'f', 'n'], true)) { continue; }
                if ($two && $a === 'n') { continue; }
                $o['a'] = $a; if ($two) { $o['two'] = true; }
                if ($src !== null) { $ev = self::evidence($x['ev'] ?? '', $src); if ($ev !== null) { $o['ev'] = $ev; } }
            } elseif ($t === 'rfb') {
                $a = $at[0] ?? ''; if (!in_array($a, ['r', 'f'], true)) { continue; }
                $ev = self::evidence($x['ev'] ?? '', $src); if ($ev === null) { continue; }
                $o['a'] = $a; $o['ev'] = $ev;
            } elseif ($t === 'qs') {
                if (!$at) { continue; }
                $o['a'] = $at; $o['max'] = 4;
                $ev = self::evidence($x['ev'] ?? '', $src); if ($ev !== null) { $o['ev'] = $ev; }
            } elseif ($t === 'open') {
                if (!$at) { continue; }
                $o['a'] = $at[0];
            } else {   // gap, tr
                if (!$at) { continue; }
                if ($t === 'gap' && !str_contains($q, '___')) { continue; }
                $o['a'] = $at;
                if ($t === 'tr') { $o['s'] = Util::str($x['s'] ?? '', 200); $o['task'] = Util::str($x['task'] ?? '', 300); }
                else { $o['hint'] = Util::str($x['hint'] ?? '', 200); }
            }
            $o['ex'] = Util::str($x['ex'] ?? '', 400);
            $out[] = $o;
        }
        return array_slice($out, 0, $max);
    }

    public static function norm(string $s): string
    {
        return trim(preg_replace('/\s+/u', ' ', preg_replace('/[^\p{L}\p{N} ]+/u', ' ', mb_strtolower($s))));
    }

    /** Опорная фраза (первые слова предложения) должна стоять в тексте буква в букву; иначе null. Без текста принимаем как есть. */
    public static function evidence($ev, ?string $src): ?string
    {
        $ev = Util::str($ev, 120);
        if (count(preg_split('/\s+/u', $ev, -1, PREG_SPLIT_NO_EMPTY)) < 3) { return null; }
        if ($src !== null && !str_contains(self::norm($src), self::norm($ev))) { return null; }
        return $ev;
    }

    /** Теория приходит от ИИ как HTML: оставляем только безопасные теги без атрибутов, кроме class у b/div. */
    public static function cleanHtml(string $h): string
    {
        $h = mb_substr($h, 0, 6000);
        $h = preg_replace('#<(script|style|iframe|object|embed)\b.*?</\1>#is', '', $h);
        return preg_replace_callback('#<(/?)([a-z0-9]+)([^>]*)>#i', function ($m) {
            $tag = strtolower($m[2]);
            if (!in_array($tag, ['p', 'h3', 'b', 'i', 'ul', 'li', 'table', 'tr', 'th', 'td', 'div', 'br'], true)) { return ''; }
            if ($m[1] === '/') { return "</$tag>"; }
            $cls = '';
            if (in_array($tag, ['b', 'div'], true) && preg_match('/class\s*=\s*["\']?(k|ex|tip)["\']?/i', $m[3], $c)) { $cls = ' class="' . strtolower($c[1]) . '"'; }
            return "<$tag$cls>";
        }, $h);
    }

    // ---------- «Задания как на EPD» к готовому тексту Lesen ----------
    const LKINDS = ['tf', 'rfb', 'mc3', 'qs', 'head', 'zu2', 'box', 'cl4', 'sum', 'ref', 'who', 'para', 'sdis'];

    public static function ltasksRequest(array $d): array
    {
        $kind = Util::str($d['kind'] ?? '', 10);
        if (!in_array($kind, self::LKINDS, true)) { throw new ApiError(400, 'Неизвестный тип задания'); }
        $paras = self::paras($d['paras'] ?? []);
        if (count($paras) < 2) { throw new ApiError(400, 'Нужен текст'); }
        $txt = '';
        foreach ($paras as $i => $p) { $txt .= 'Absatz ' . ($i + 1) . ': ' . $p . "\n"; }
        $tokens = ['tf' => 2000, 'rfb' => 2000, 'mc3' => 2400, 'qs' => 2000, 'head' => 1500, 'zu2' => 1500, 'box' => 2200, 'cl4' => 2200, 'sum' => 2200,
                   'ref' => 1200, 'who' => 1200, 'para' => 1500, 'sdis' => 700][$kind];
        $extra = '';
        if ($kind === 'sdis') {
            $rm = array_values(array_filter(array_map(fn($x) => Util::str($x, 400), array_slice(is_array($d['removed'] ?? null) ? $d['removed'] : [], 0, 12)), fn($x) => $x !== ''));
            if (count($rm) < 3) { throw new ApiError(400, 'Нужны вынесенные предложения'); }
            $extra = "\nEntfernte Sätze (stammen aus dem Text):\n- " . implode("\n- ", $rm) . "\n";
        }
        return [Gen::sys('lernen_ltasks'), "kind: $kind\n\nText:\n" . $txt . $extra, Lernen::schema('lernen_ltasks'), $tokens];
    }

    public static function paras($v): array
    {
        $out = [];
        foreach (array_slice(is_array($v) ? $v : [], 0, 16) as $p) { $p = Util::str($p, 1800); if ($p !== '') { $out[] = $p; } }
        return $out;
    }

    public static function words(string $s): array
    {
        return array_values(array_filter(preg_split('/\s+/u', self::norm($s)), fn($w) => $w !== ''));
    }

    /** Приводит ответ ИИ к заданиям движка Lesen (формат tasks[]). */
    public static function ltasksNormalize(string $kind, array $r, array $paras): array
    {
        $src = implode(' ', $paras);
        $np = count($paras);
        $srcWords = array_flip(self::words($src));
        $items = array_values(array_filter(is_array($r['items'] ?? null) ? $r['items'] : [], 'is_array'));
        $out = [];
        $at = fn($x) => array_values(array_filter(array_map(fn($a) => Util::str($a, 200), is_array($x['at'] ?? null) ? array_slice($x['at'], 0, 4) : []), fn($a) => $a !== ''));
        $inText = fn(string $form) => $form !== '' && !array_diff(self::words($form), array_keys($srcWords));
        $few = fn() => new ApiError(502, 'ИИ вернул слишком мало заданий, попробуй ещё раз');
        $shuf = function (array $a, string $seed): array { mt_srand((int)(crc32($seed) & 0x7fffffff)); shuffle($a); mt_srand(); return $a; };
        if ($kind === 'sdis') {
            $out = array_values(array_filter(array_map(fn($x) => Util::str($x['q'] ?? '', 400), $items), fn($x) => mb_strlen($x) > 25));
            if (count($out) < 2) { throw $few(); }
            return array_slice($out, 0, 3);
        }
        if (in_array($kind, ['tf', 'rfb', 'mc3', 'qs'], true)) {
            $map = ['tf' => 'tf', 'rfb' => 'rfb', 'mc3' => 'mc', 'qs' => 'qs'][$kind];
            $out = self::tasks(array_map(function ($x) use ($map, $at) {
                return ['t' => $map, 'q' => $x['q'] ?? '', 'o' => $x['o'] ?? [], 'ai' => $x['ai'] ?? -1, 'at' => $at($x), 'ev' => $x['ev'] ?? '', 'ex' => ''];
            }, $items), [$map], 10, false, false, $src);
            if (count($out) < 6) { throw $few(); }
            foreach ($out as &$o) { if ($kind === 'mc3' && count($o['o']) > 3) { $o['o'] = array_slice($o['o'], 0, max(3, $o['a'] + 1)); } }
            return $out;
        }
        if ($kind === 'head' || $kind === 'zu2') {
            $byPara = []; $dist = [];
            foreach ($items as $x) {
                $q = Util::str($x['q'] ?? '', 300); $n = is_numeric($x['ai'] ?? null) ? (int)$x['ai'] : 0;
                if ($q === '') { continue; }
                if ($n >= 1 && $n <= $np && !isset($byPara[$n])) { $byPara[$n] = $q; } elseif ($n === 0) { $dist[] = $q; }
            }
            if (count($byPara) < 6) { throw $few(); }
            ksort($byPara);
            $nums = array_keys($byPara);
            if ($kind === 'head') {
                $heads = array_merge(array_values($byPara), array_slice($dist, 0, 2));
                $heads = $shuf($heads, implode('|', $heads));
                $a = array_map(fn($n) => array_search($byPara[$n], $heads, true), $nums);
                return [['t' => 'head', 'q' => 'Welche Überschrift passt zu welchem Absatz? Zwei Überschriften bleiben übrig.', 'paras' => $nums, 'heads' => $heads, 'a' => $a, 'ex' => '']];
            }
            // Aussagen → Absätze: варианты ответа — номера абзацев (выбранные и пара лишних)
            $opts = $nums;
            foreach (range(1, $np) as $n) { if (count($opts) >= count($nums) + 2) { break; } if (!in_array($n, $opts, true)) { $opts[] = $n; } }
            sort($opts);
            $stm = array_values($byPara);
            $ord = $shuf(array_keys($stm), implode('|', $stm));
            $items2 = array_map(fn($i) => $stm[$i], $ord);
            $a = array_map(fn($i) => array_search($nums[$i], $opts, true), $ord);
            return [['t' => 'match', 'q' => 'Ordnen Sie jede Aussage dem passenden Absatz zu. Einige Absätze bleiben übrig.', 'items' => $items2, 'opts' => array_map(fn($n) => 'Absatz ' . $n, $opts), 'a' => $a, 'ex' => '']];
        }
        if ($kind === 'ref' || $kind === 'who' || $kind === 'para') {
            foreach ($items as $x) {
                $q = Util::str($x['q'] ?? '', 400); $a = $at($x);
                if ($q === '' || !$a) { continue; }
                if ($kind === 'ref' && !preg_match('/\[\[.+?\]\]/u', $q)) { continue; }
                if ($kind === 'para') { $a = array_values(array_filter($a, $inText)); if (!$a) { continue; } }
                $o = ['t' => 'short', 'q' => $q, 'a' => $a];
                $o['task'] = ['ref' => 'Worauf bezieht sich das unterstrichene Wort?', 'who' => 'Von wem / woher stammt die Aussage?', 'para' => 'Welches Wort im Text bedeutet das Gleiche?'][$kind];
                if ($kind === 'para' && (int)($r['sec'] ?? 0) > 0) { $o['sec'] = (int)$r['sec']; }
                $out[] = $o;
            }
            if (count($out) < 4) { throw $few(); }
            return array_slice($out, 0, $kind === 'para' ? 8 : 6);
        }
        // sum / box / cl4: связный текст с пропусками [[n]]
        $text = Util::str($r['text'] ?? '', 2600);
        preg_match_all('/\[\[(\d+)\]\]/', $text, $mm);
        $n = count($mm[1]);
        if ($n < 6 || $n !== count($items)) { throw new ApiError(502, 'ИИ вернул неполное задание, попробуй ещё раз'); }
        if ($kind === 'cl4') {
            $O = []; $A = [];
            foreach ($items as $x) {
                $o = array_values(array_filter(array_map(fn($a) => Util::str($a, 60), is_array($x['o'] ?? null) ? array_slice($x['o'], 0, 4) : []), fn($a) => $a !== ''));
                $ai = is_numeric($x['ai'] ?? null) ? (int)$x['ai'] : -1;
                if (count($o) !== 4 || $ai < 0 || $ai > 3) { throw new ApiError(502, 'ИИ вернул неполное задание, попробуй ещё раз'); }
                $O[] = $o; $A[] = $ai;
            }
            return [['t' => 'cl4', 'q' => 'Wählen Sie für jede Lücke das passende Wort (a–d).', 'text' => $text, 'o' => $O, 'a' => $A]];
        }
        if ($kind === 'box') {
            $words = array_values(array_unique(array_filter(array_map(fn($w) => Util::str($w, 40), is_array($r['words'] ?? null) ? $r['words'] : []), fn($w) => $w !== '')));
            $A = [];
            foreach ($items as $x) { $a = $at($x)[0] ?? ''; if ($a === '' || !in_array($a, $words, true)) { throw new ApiError(502, 'ИИ вернул неполное задание, попробуй ещё раз'); } $A[] = $a; }
            if (count(array_unique($A)) !== count($A) || count($words) < $n) { throw new ApiError(502, 'ИИ вернул неполное задание, попробуй ещё раз'); }
            $words = $shuf($words, $text);
            return [['t' => 'box', 'q' => 'Fügen Sie aus dem Wortkasten die passenden Wörter in den Text ein. Einige Wörter bleiben übrig.', 'text' => $text, 'words' => $words, 'a' => $A]];
        }
        // sum: слова из оригинального текста; пропуски с «не из текста» ответом заполняем сразу и перенумеровываем
        $keep = []; $gaps = [];
        foreach ($items as $k => $x) {
            $forms = array_values(array_filter($at($x), $inText));
            if ($forms) { $gaps[] = $forms; $keep[$k + 1] = count($gaps); }
            else { $f = $at($x)[0] ?? ''; $keep[$k + 1] = $f === '' ? false : $f; }
        }
        if (count($gaps) < 5) { throw new ApiError(502, 'ИИ вернул неполное задание, попробуй ещё раз'); }
        $text = preg_replace_callback('/\[\[(\d+)\]\]/', function ($m) use ($keep) {
            $v = $keep[(int)$m[1]] ?? false;
            return is_int($v) ? '[[' . $v . ']]' : (string)$v;
        }, $text);
        return [['t' => 'sum', 'q' => 'Lesen Sie die Zusammenfassung zum Text und ergänzen Sie die Lücken jeweils mit einem passenden Wort aus dem Originaltext.', 'text' => $text, 'a' => $gaps]];
    }

    /** Задание для /talk, присланное браузером (id «ai»): оставляем только известные строковые поля. */
    public static function inlineTask($v): ?array
    {
        if (!is_array($v)) { return null; }
        $o = [];
        foreach (['topic', 'title', 'impulse', 'these', 'situation', 'persona'] as $k) { if (isset($v[$k])) { $o[$k] = Util::str($v[$k], 1200); } }
        foreach (['leitfragen', 'fragen'] as $k) {
            $o[$k] = array_values(array_filter(array_map(fn($x) => Util::str($x, 300), is_array($v[$k] ?? null) ? array_slice($v[$k], 0, 8) : []), fn($x) => $x !== ''));
        }
        return $o;
    }
}
