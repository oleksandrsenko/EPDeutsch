<?php
/**
 * Одноразовый перенос данных из SQLite (db/unisicher.db) в MySQL/MariaDB.
 * Запускается ЛОКАЛЬНО: php scripts/migrate_sqlite_to_php.php --sqlite=db/unisicher.db <режим>
 *
 * Исходная база открывается только на чтение и не меняется.
 *
 * Режимы:
 *   --dump=out.sql[.gz]   записать SQL-файл для импорта через phpMyAdmin на хостинге
 *                         (InfinityFree не пускает к MySQL снаружи, поэтому для боевой базы нужен именно он);
 *   --dsn="mysql:host=127.0.0.1;port=33306;dbname=epd" --user=root --pass=...
 *                         залить прямо в локальную MySQL (для проверки миграции);
 *                         добавь --truncate, чтобы сначала очистить таблицы назначения.
 *
 * В конце печатается отчёт: число строк в SQLite и (в режиме --dsn) в MySQL по каждой таблице; код выхода 1 при расхождении.
 * В режиме --dump в конец файла добавляется запрос сверки: выполни его в phpMyAdmin после импорта.
 *
 * Что меняется при переносе:
 *   • таблицы verifies и resets не переносятся (письма отключены);
 *   • users.verified = 1 у всех, must_change_password = 0, blocked = 0;
 *   • пароли (PBKDF2 "соль$хеш") копируются как есть и проверяются прежним алгоритмом, при входе перехешируются;
 *   • translations получает src_hash = sha1(src).
 */
if (PHP_SAPI !== 'cli') { exit("Только из командной строки\n"); }
foreach (['pdo_sqlite'] as $ext) { if (!extension_loaded($ext)) { fwrite(STDERR, "Нужно расширение PHP: $ext\n"); exit(2); } }

$opt = getopt('', ['sqlite:', 'dump:', 'dsn:', 'user:', 'pass:', 'truncate', 'help']);
if (isset($opt['help']) || empty($opt['sqlite']) || (empty($opt['dump']) && empty($opt['dsn']))) {
    fwrite(STDERR, "Использование: php scripts/migrate_sqlite_to_php.php --sqlite=db/unisicher.db (--dump=out.sql.gz | --dsn=... --user=... --pass=... [--truncate])\n");
    exit(2);
}
$src = $opt['sqlite'];
if (!is_file($src)) { fwrite(STDERR, "Нет файла $src\n"); exit(2); }

// Только чтение: открываем с флагом READONLY и режимом query_only
$lite = new PDO('sqlite:' . $src, null, null, [
    PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
    PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
    // PHP 8.5 переименовал константы (старые дают предупреждение)
    (class_exists('Pdo\\Sqlite') ? constant('Pdo\\Sqlite::ATTR_OPEN_FLAGS') : PDO::SQLITE_ATTR_OPEN_FLAGS)
        => (class_exists('Pdo\\Sqlite') ? constant('Pdo\\Sqlite::OPEN_READONLY') : PDO::SQLITE_OPEN_READONLY),
]);
$lite->exec('PRAGMA query_only = 1');

// Порядок учитывает внешние ключи. null в списке колонок = взять из SQLite как есть.
$tables = ['users', 'sessions', 'progress', 'stats', 'friends', 'shares', 'translations', 'hits', 'presence', 'ai_log', 'ai_cache',
           'schreiben_state', 'schreiben_essays', 'races', 'word_inbox', 'kv', 'invites',
           'personal_settings', 'personal_plan', 'personal_attempts', 'personal_usage', 'personal_vocab'];
$skipped = ['verifies', 'resets'];

$liteTables = $lite->query("SELECT name FROM sqlite_master WHERE type='table' AND name NOT LIKE 'sqlite_%'")->fetchAll(PDO::FETCH_COLUMN);
$unknown = array_diff($liteTables, $tables, $skipped);
if ($unknown) { fwrite(STDERR, "ВНИМАНИЕ: в SQLite есть таблицы, которых нет в плане переноса: " . implode(', ', $unknown) . "\n"); }

// Колонки назначения (для выбора пересечения с SQLite и для вставки)
$target = [
    'users' => ['id','email','name','avatar','pw','code','created','last_seen','tz','device','lang','news','plan','plan_until','role','verified','last_nudge','ui_lang','must_change_password','blocked'],
    'sessions' => ['token','user_id','expires'],
    'progress' => ['user_id','data','updated'],
    'stats' => ['user_id','xp','week_xp','streak','learned','acc','today','level','updated','answers','studied'],
    'friends' => ['user_id','friend_id','created'],
    'shares' => ['code','owner_id','name','descr','cards','created'],
    'translations' => ['src_hash','sl','tl','src','out','prov'],
    'hits' => ['id','ts','day','vkey','uid','path','ref','device','os','browser','tz','lang'],
    'presence' => ['vkey','uid','ts','tab','device','tz','what'],
    'ai_log' => ['id','ts','day','uid','kind','model','inp','outp','cost','ms','err'],
    'ai_cache' => ['key','out','ts'],
    'schreiben_state' => ['user_id','data','updated'],
    'schreiben_essays' => ['id','user_id','ts','task_id','typ','title','text','words','minutes','points','max_points','task','result'],
    'races' => ['id','a','b','metric','start','end','a0','b0','created'],
    'word_inbox' => ['id','user_id','de','ru','ctx','ts'],
    'kv' => ['user_id','k','v','updated'],
    'invites' => ['code','created_by','created','used_by','used'],
    'personal_settings' => ['user_id','data'],
    'personal_plan' => ['user_id','track','data'],
    'personal_attempts' => ['id','user_id','track','kind','lesson','ts','score','data'],
    'personal_usage' => ['user_id','day','n'],
    'personal_vocab' => ['id','user_id','track','de','ru','ctx','box','due'],
];
$defaults = ['verified' => 1, 'must_change_password' => 0, 'blocked' => 0];

function q(string $c): string { return '`' . $c . '`'; }

/** Преобразует строку SQLite в строку для MySQL. */
function convert(string $table, array $row): array
{
    global $defaults;
    if ($table === 'users') {
        $row['verified'] = 1;               // подтверждения почты больше нет
        $row['must_change_password'] = 0;
        $row['blocked'] = 0;
        foreach (['plan', 'role', 'ui_lang'] as $k) { if (($row[$k] ?? '') === '') { $row[$k] = ['plan' => 'free', 'role' => 'user', 'ui_lang' => 'ru'][$k]; } }
    }
    if ($table === 'translations') { $row['src_hash'] = sha1((string)$row['src']); }
    foreach ($defaults as $k => $v) { if ($table === 'users' && !array_key_exists($k, $row)) { $row[$k] = $v; } }
    return $row;
}

$mode = !empty($opt['dump']) ? 'dump' : 'dsn';
$my = null; $out = null; $gz = false;
if ($mode === 'dsn') {
    $my = new PDO($opt['dsn'] . ';charset=utf8mb4', $opt['user'] ?? 'root', $opt['pass'] ?? '', [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION, PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC]);
    $my->exec('SET NAMES utf8mb4');
    $have = (int)$my->query('SELECT COUNT(*) FROM users')->fetchColumn();
    if ($have > 0 && !isset($opt['truncate'])) { fwrite(STDERR, "В MySQL уже есть пользователи ($have). Добавь --truncate, если нужно перезаписать.\n"); exit(2); }
    $my->exec('SET FOREIGN_KEY_CHECKS=0');
    if (isset($opt['truncate'])) {
        // триггер owner_no_delete мешает DELETE, но не TRUNCATE
        foreach (array_reverse($tables) as $t) { $my->exec('TRUNCATE TABLE ' . q($t)); }
        $my->exec('TRUNCATE TABLE audit_log'); $my->exec('TRUNCATE TABLE rate_limit');
    }
} else {
    $gz = str_ends_with($opt['dump'], '.gz');
    $out = $gz ? gzopen($opt['dump'], 'wb9') : fopen($opt['dump'], 'wb');
    $w = function (string $s) use ($out, $gz) { $gz ? gzwrite($out, $s) : fwrite($out, $s); };
    $w("-- Данные EPDeutsch из SQLite → MySQL. Сначала импортируй schema/mysql.sql, потом этот файл.\n-- Секретный файл: внутри хеши паролей, не публикуй.\nSET NAMES utf8mb4;\nSET FOREIGN_KEY_CHECKS=0;\nSET SQL_MODE='NO_AUTO_VALUE_ON_ZERO';\nSTART TRANSACTION;\n");
}

function sqlval($v, ?PDO $pdo): string
{
    if ($v === null) { return 'NULL'; }
    if (is_int($v) || is_float($v)) { return (string)$v; }
    // строки: экранируем сами, чтобы дамп не зависел от соединения
    return "'" . strtr((string)$v, ["\\" => "\\\\", "'" => "\\'", "\0" => "\\0", "\n" => "\\n", "\r" => "\\r", "\x1a" => "\\Z"]) . "'";
}

$report = [];
foreach ($tables as $t) {
    if (!in_array($t, $liteTables, true)) { $report[$t] = [0, null, 'нет таблицы в SQLite']; continue; }
    $cnt = (int)$lite->query('SELECT COUNT(*) FROM ' . q($t))->fetchColumn();
    $cols = $target[$t];
    $liteCols = array_column($lite->query('PRAGMA table_info(' . q($t) . ')')->fetchAll(), 'name');
    $stmt = $lite->query('SELECT * FROM ' . q($t));
    $colList = implode(',', array_map('q', $cols));
    $batch = []; $n = 0;
    $flush = function () use (&$batch, $t, $colList, $mode, $my, &$w) {
        if (!$batch) { return; }
        $sql = "INSERT INTO " . q($t) . " ($colList) VALUES\n" . implode(",\n", $batch) . ";\n";
        if ($mode === 'dsn') { $my->exec($sql); } else { $w($sql); }
        $batch = [];
    };
    $size = 0;
    foreach ($stmt as $row) {
        $row = convert($t, $row);
        $vals = [];
        foreach ($cols as $c) {
            $v = $row[$c] ?? null;
            if ($v === null && in_array($c, ['pw', 'name', 'email'], true)) { $v = ''; }
            $vals[] = sqlval($v, $my);
        }
        $line = '(' . implode(',', $vals) . ')';
        $batch[] = $line; $size += strlen($line); $n++;
        if (count($batch) >= 200 || $size > 800000) { $flush(); $size = 0; }
    }
    $flush();
    $report[$t] = [$cnt, $n, ''];
}

$bad = false;
if ($mode === 'dump') {
    // запрос сверки для phpMyAdmin
    $parts = [];
    foreach ($report as $t => [$cnt]) { $parts[] = "SELECT '$t' AS tbl, $cnt AS expected, (SELECT COUNT(*) FROM " . q($t) . ") AS actual"; }
    $w("COMMIT;\nSET FOREIGN_KEY_CHECKS=1;\n-- Сверка: после импорта выполни запрос ниже, expected и actual должны совпасть в каждой строке\n" . implode("\nUNION ALL ", $parts) . ";\n");
    $gz ? gzclose($out) : fclose($out);
} else {
    $my->exec('SET FOREIGN_KEY_CHECKS=1');
}

printf("%-20s %10s %10s %10s  %s\n", 'таблица', 'SQLite', 'записано', $mode === 'dsn' ? 'MySQL' : '-', 'статус');
foreach ($report as $t => [$cnt, $n, $note]) {
    $mysqlN = null;
    if ($mode === 'dsn' && $n !== null) { $mysqlN = (int)$my->query('SELECT COUNT(*) FROM ' . q($t))->fetchColumn(); }
    $ok = $note === '' && $n === $cnt && ($mode === 'dump' || $mysqlN === $cnt);
    if (!$ok && $note === '') { $bad = true; }
    printf("%-20s %10d %10s %10s  %s\n", $t, $cnt, $n ?? '-', $mysqlN ?? '-', $note !== '' ? $note : ($ok ? 'OK' : 'РАСХОЖДЕНИЕ'));
}
foreach ($skipped as $s) {
    $c = in_array($s, $liteTables, true) ? (int)$lite->query('SELECT COUNT(*) FROM ' . q($s))->fetchColumn() : 0;
    printf("%-20s %10d %10s %10s  %s\n", $s, $c, '-', '-', 'намеренно не переносится');
}
// Полное совпадение хешей паролей (формат и значения не трогаем)
if ($mode === 'dsn') {
    $a = $lite->query('SELECT id, pw FROM users ORDER BY id')->fetchAll(PDO::FETCH_KEY_PAIR);
    $b = $my->query('SELECT id, pw FROM users ORDER BY id')->fetchAll(PDO::FETCH_KEY_PAIR);
    $same = $a == $b;
    printf("хеши паролей users.pw: %s\n", $same ? 'совпадают с SQLite байт в байт' : 'РАСХОДЯТСЯ');
    if (!$same) { $bad = true; }
}
echo $bad ? "ИТОГ: есть расхождения\n" : "ИТОГ: всё сошлось\n";
if ($mode === 'dump') { echo "Файл: {$opt['dump']} (внутри хеши паролей — храни как секрет)\n"; }
exit($bad ? 1 : 0);
