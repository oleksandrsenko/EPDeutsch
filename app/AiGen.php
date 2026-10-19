<?php
/** «Практика с ИИ»: тема от студента → теория и задания (Grammatik), статья (Lesen), аудио (Hören), задание для разговора (Sprechen).
 *  ИИ возвращает данные в формате, близком к data/de/*.json; здесь они проверяются и приводятся к нему один в один,
 *  поэтому обычные экраны модулей показывают их без отдельного кода. */
class AiGen
{
    const MODEL = 'claude-haiku-5-5';
    const MODULES = ['grammatik', 'lesen', 'hoeren', 'sprechen'];
    const MODES = ['new', 'more', 'harder', 'easier'];
    const FEMALE = ['sage', 'coral', 'nova'];
    const MALE = ['onyx', 'echo', 'ash'];

    /** @return array{0:string,1:string,2:array,3:int} [system, user, schema, max_tokens] */
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
        $tokens = ['grammatik' => 4500, 'lesen' => 4200, 'hoeren' => 4200, 'sprechen' => 1800][$mod];
        return [Lernen::prompt('lernen_aigen_' . $mod), $content, Lernen::schema('lernen_aigen_' . $mod), $tokens];
    }

    public static function normalize(string $mod, array $r, string $topic, string $level, string $mode): array
    {
        $s = fn($v, $n = 600) => Util::str($v, $n);
        $list = fn($v, $n, $m) => array_values(array_filter(array_map(fn($x) => $s($x, $m), array_slice(is_array($v) ? $v : [], 0, $n)), fn($x) => $x !== ''));
        $title = $s($r['title'] ?? '', 140) ?: $topic;
        if ($mod === 'grammatik') {
            $items = self::tasks($r['items'] ?? [], ['mc', 'gap', 'tr'], 10, true);
            if (count($items) < 6) { throw new ApiError(502, 'ИИ вернул слишком мало заданий, попробуй ещё раз'); }
            return ['id' => 'ai', 'title' => $title, 'ru' => $s($r['ru'] ?? '', 200), 'level' => $level, 'tag' => 'ИИ', 'theory' => $mode === 'new' ? self::cleanHtml((string)($r['theory'] ?? '')) : '', 'items' => $items];
        }
        if ($mod === 'lesen') {
            $paras = $list($r['paras'] ?? [], 8, 1800);
            $tasks = self::tasks($r['tasks'] ?? [], ['mc', 'tf', 'open'], 10, false);
            if (count($paras) < 3 || count($tasks) < 4) { throw new ApiError(502, 'ИИ вернул неполный текст, попробуй ещё раз'); }
            $words = array_sum(array_map(fn($p) => count(preg_split('/\s+/u', $p, -1, PREG_SPLIT_NO_EMPTY)), $paras));
            return ['id' => 'ai', 'level' => $level, 'topic' => $topic, 'kicker' => $s($r['kicker'] ?? '', 60) ?: $topic, 'title' => $title, 'lead' => $s($r['lead'] ?? '', 400),
                    'paras' => $paras, 'tasks' => $tasks, 'src' => 'Текст от ИИ в стиле der Standard', 'words' => $words, 'minutes' => max(5, (int)round($words / 28))];
        }
        if ($mod === 'hoeren') {
            $voices = []; $f = 0; $m = 0;
            foreach (array_slice(is_array($r['speakers'] ?? null) ? $r['speakers'] : [], 0, 3) as $sp) {
                if (!is_array($sp)) { continue; }
                $id = mb_substr(preg_replace('/[^A-Za-z]/', '', (string)($sp['id'] ?? '')), 0, 1);
                if ($id === '' || isset($voices[$id])) { continue; }
                $fem = !empty($sp['female']);
                $voices[$id] = ['voice' => $fem ? self::FEMALE[$f++ % 3] : self::MALE[$m++ % 3], 'name' => $s($sp['name'] ?? '', 40) ?: $id,
                                'style' => 'Sprich natürliches Hochdeutsch mit leichter österreichischer Färbung, lebendig wie in einem echten Gespräch oder Radiobeitrag.'];
            }
            $lines = [];
            foreach (array_slice(is_array($r['lines'] ?? null) ? $r['lines'] : [], 0, 30) as $ln) {
                $sp = mb_substr(preg_replace('/[^A-Za-z]/', '', (string)($ln['s'] ?? '')), 0, 1); $tx = $s($ln['text'] ?? '', 1200);
                if ($tx === '') { continue; }
                if (!isset($voices[$sp])) { $sp = array_key_first($voices) ?? 'A'; if (!isset($voices[$sp])) { $voices[$sp] = ['voice' => 'sage', 'name' => 'Sprecher', 'style' => '']; } }
                $lines[] = [$sp, $tx];
            }
            $tasks = self::tasks($r['tasks'] ?? [], ['mc', 'tf', 'open'], 8, false, true);
            if (count($lines) < 3 || count($tasks) < 3) { throw new ApiError(502, 'ИИ вернул неполную запись, попробуй ещё раз'); }
            $words = array_sum(array_map(fn($l) => count(preg_split('/\s+/u', $l[1], -1, PREG_SPLIT_NO_EMPTY)), $lines));
            return ['id' => 'ai', 'level' => $level, 'kind' => $s($r['kind'] ?? '', 40) ?: 'Audio', 'topic' => $topic, 'title' => $title, 'intro' => $s($r['intro'] ?? '', 300),
                    'voices' => $voices, 'lines' => $lines, 'tasks' => $tasks, 'words' => $words, 'minutes' => max(2, (int)round($words / 130))];
        }
        // sprechen
        $kind = in_array($r['kind'] ?? '', ['These', 'Text', 'Zitat'], true) ? $r['kind'] : 'These';
        $imp = $s($r['impulse'] ?? '', 1200);
        $lf = $list($r['leitfragen'] ?? [], 6, 300);
        if ($imp === '' || count($lf) < 2) { throw new ApiError(502, 'ИИ вернул неполное задание, попробуй ещё раз'); }
        return ['id' => 'ai', 'topic' => $topic, 'kind' => $kind, 'title' => $title, 'ru' => $s($r['ru'] ?? '', 240), 'impulse' => $imp,
                'leitfragen' => $lf, 'fragen' => $list($r['fragen'] ?? [], 6, 300), 'redemittel' => $list($r['redemittel'] ?? [], 10, 120)];
    }

    /** Приводит задания к формату data/de/*.json и отбрасывает битые. */
    private static function tasks($raw, array $types, int $max, bool $lv, bool $two = false): array
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
