<?php
/** Админка: сводка, список пользователей и действия админа (всё действия пишутся в audit_log, пароли — никогда). */
class Admin
{
    private static function countBy(array $rows, string $key): array
    {
        $out = [];
        foreach ($rows as $r) { $k = ($r[$key] ?? '') ?: '—'; $out[$k] = ($out[$k] ?? 0) + 1; }
        arsort($out);
        $res = [];
        foreach ($out as $k => $v) { $res[] = [(string)$k, $v]; }
        return $res;
    }

    private static function progressRows(): array
    {
        $out = [];
        foreach (Db::all('SELECT user_id,data FROM progress') as $r) { $out[(int)$r['user_id']] = Util::progressSummary(json_decode($r['data'], true)); }
        return $out;
    }

    public static function overview(int $n): array
    {
        $now = time();
        $today = Util::localDay($now);
        $since = $today - $n + 1;
        $users = Db::all('SELECT id,name,email,created,last_seen,tz,device FROM users');
        $prog = self::progressRows();
        $bw = Util::bookWords();
        $pres = Db::all('SELECT * FROM presence WHERE ts>?', [$now - 150]);
        $names = array_column($users, 'name', 'id');
        $online = array_map(fn($r) => ['uid' => $r['uid'], 'name' => $names[$r['uid']] ?? null, 'tab' => $r['tab'], 'what' => $r['what'] ?? '', 'device' => $r['device'],
            'country' => Util::country($r['tz']), 'ago' => $now - $r['ts']], $pres);
        $hits = Db::all('SELECT day,vkey,uid,path,ref,device,os,browser,tz FROM hits WHERE day>=?', [$since]);
        $vis = []; $pv = []; $first = [];
        foreach ($hits as $h) {
            $vis[$h['day']][$h['vkey']] = 1;
            $pv[$h['day']] = ($pv[$h['day']] ?? 0) + 1;
            $first[$h['vkey']] = $first[$h['vkey']] ?? $h;     // один посетитель — одна запись в разбивках
        }
        $fv = array_values($first);
        $regs = [];
        foreach ($users as $u) { $d = Util::localDay((int)$u['created']); if ($d >= $since) { $regs[$d] = ($regs[$d] ?? 0) + 1; } }
        $learners = []; $answers = [];
        foreach ($prog as $s) {
            foreach ($s['days'] as $d => $rec) {
                if ($d >= $since) { $learners[$d] = ($learners[$d] ?? 0) + 1; $answers[$d] = ($answers[$d] ?? 0) + (int)($rec['a'] ?? 0); }
            }
        }
        $series = [];
        for ($d = $since; $d <= $today; $d++) {
            $series[] = ['day' => $d, 'visitors' => count($vis[$d] ?? []), 'views' => $pv[$d] ?? 0, 'regs' => $regs[$d] ?? 0, 'learners' => $learners[$d] ?? 0, 'answers' => $answers[$d] ?? 0];
        }
        $newIds = [];
        foreach ($users as $u) { if (Util::localDay((int)$u['created']) >= $since) { $newIds[] = (int)$u['id']; } }
        $started = array_filter($newIds, fn($i) => !empty($prog[$i]['answers']));
        $returned = array_filter($newIds, fn($i) => ($prog[$i]['active_days'] ?? 0) >= 2);
        $funnel = [['Посетители', count($first)], ['Регистрации', count($newIds)], ['Начали учить', count($started)], ['Вернулись ещё раз', count($returned)]];
        $miss = []; $modes = []; $secs = [];
        foreach ($prog as $s) {
            foreach ($s['l'] as $wid => $k) { if (is_int($k)) { $miss[$wid] = ($miss[$wid] ?? 0) + $k; } }
            foreach ($s['ms'] as $m => $v) {
                if (is_array($v) && count($v) > 1) { $modes[$m] = $modes[$m] ?? [0, 0]; $modes[$m][0] += $v[0]; $modes[$m][1] += $v[1]; }
            }
            foreach (array_unique(array_merge([$s['cur']], $s['recent'])) as $sid) { if ($sid) { $secs[$sid] = ($secs[$sid] ?? 0) + 1; } }
        }
        arsort($miss);
        $hard = [];
        foreach (array_slice($miss, 0, 15, true) as $wid => $k) { if (isset($bw['w'][$wid])) { $hard[] = ['de' => $bw['w'][$wid][0], 'ru' => $bw['w'][$wid][1], 'miss' => $k]; } }
        arsort($secs);
        $popular = [];
        foreach (array_slice($secs, 0, 10, true) as $k => $v) { $popular[] = [$bw['sec'][$k] ?? (str_starts_with((string)$k, 'u:') ? 'Свой набор' : $k), $v]; }
        $day7 = $today - 6;
        $active7 = count(array_filter($users, fn($u) => ($u['last_seen'] ?? 0) && Util::localDay((int)$u['last_seen']) >= $day7));
        $active1 = count(array_filter($users, fn($u) => ($u['last_seen'] ?? 0) && Util::localDay((int)$u['last_seen']) === $today));
        $totalAns = array_sum(array_column($prog, 'answers'));
        $totalOk = array_sum(array_column($prog, 'ok'));
        uasort($modes, fn($a, $b) => $b[1] <=> $a[1]);
        $modesOut = [];
        foreach ($modes as $k => $v) { $modesOut[] = [(string)$k, $v[0], $v[1]]; }
        usort($online, fn($a, $b) => $a['ago'] <=> $b['ago']);
        return [
            'now' => $now, 'today' => $today, 'days' => $n,
            'kpi' => ['users' => count($users), 'new7' => count(array_filter($users, fn($u) => Util::localDay((int)$u['created']) >= $day7)),
                'newToday' => $regs[$today] ?? 0, 'active1' => $active1, 'active7' => $active7,
                'visitorsToday' => count($vis[$today] ?? []), 'viewsToday' => $pv[$today] ?? 0,
                'online' => count($online), 'onlineUsers' => count(array_filter($online, fn($o) => $o['uid'])),
                'answers' => $totalAns, 'acc' => $totalAns ? (int)round($totalOk / $totalAns * 100) : 0, 'answersToday' => $answers[$today] ?? 0,
                'tour' => count(array_filter($prog, fn($s) => $s['tour'])), 'withPlan' => count(array_filter($prog, fn($s) => $s['exam']))],
            'series' => $series, 'online' => $online,
            'devices' => self::countBy($fv, 'device'), 'os' => self::countBy($fv, 'os'), 'browsers' => self::countBy($fv, 'browser'),
            'countries' => self::countBy(array_map(fn($h) => ['c' => Util::country($h['tz'])], $fv), 'c'),
            'refs' => array_slice(self::countBy(array_filter($fv, fn($h) => $h['ref']), 'ref'), 0, 12),
            'pages' => array_slice(self::countBy($hits, 'path'), 0, 10),
            'funnel' => $funnel, 'hard' => $hard, 'popular' => $popular, 'modes' => $modesOut,
            'owners' => (int)Db::val("SELECT COUNT(*) FROM users WHERE role='owner'"),
            'ai' => Ai::stats($n), 'schreiben' => self::schreiben($n),
        ];
    }

    private static function schreiben(int $n): array
    {
        $today = Util::localDay();
        $since = time() - $n * 86400;
        $rows = Db::all('SELECT e.user_id,e.ts,e.typ,e.points,e.max_points,e.words,u.name FROM schreiben_essays e LEFT JOIN users u ON u.id=e.user_id WHERE e.ts>?', [$since]);
        $days = []; $by = [];
        foreach ($rows as $r) {
            $d = Util::localDay((int)$r['ts']);
            $days[$d] = ($days[$d] ?? 0) + 1;
            $k = (string)$r['user_id'];
            $by[$k] = $by[$k] ?? [$r['name'] ?? 'Удалён', 0, 0.0];
            $by[$k][1]++; $by[$k][2] += $r['points'] / $r['max_points'];
        }
        $passed = count(array_filter($rows, fn($r) => $r['points'] >= ($r['typ'] === 'stell' ? 12 : 10)));
        $g = Db::one("SELECT COUNT(*) n, COALESCE(AVG(cost),0) a, COALESCE(AVG(ms),0) t FROM ai_log WHERE kind='schreiben_grade' AND err='' AND ts>?", [$since]);
        $series = [];
        for ($d = $today - $n + 1; $d <= $today; $d++) { $series[] = ['day' => $d, 'n' => $days[$d] ?? 0]; }
        $byUser = array_map(fn($v) => [$v[0], $v[1], (int)round($v[2] / $v[1] * 100)], array_values($by));
        usort($byUser, fn($a, $b) => $b[1] <=> $a[1]);
        $cnt = count($rows);
        return [
            'total' => (int)Db::val('SELECT COUNT(*) FROM schreiben_essays'), 'period' => $cnt, 'writers' => count($by),
            'avg' => $cnt ? (int)round(array_sum(array_map(fn($r) => $r['points'] / $r['max_points'], $rows)) / $cnt * 100) : 0,
            'passed' => $passed, 'words' => $cnt ? (int)round(array_sum(array_column($rows, 'words')) / $cnt) : 0,
            'gradeUsd' => round($g['a'] / 1e6, 4), 'grades' => (int)$g['n'], 'gradeSec' => (int)round($g['t'] / 1000),
            'series' => $series, 'byUser' => array_slice($byUser, 0, 10),
        ];
    }

    /** Список всех пользователей. Пароли и их хеши сюда не попадают (в запрос не включены). */
    public static function users(): array
    {
        $prog = self::progressRows();
        $bw = Util::bookWords();
        $st = [];
        foreach (Db::all('SELECT * FROM stats') as $r) { $st[(int)$r['user_id']] = $r; }
        $out = [];
        foreach (Db::all('SELECT id,name,email,avatar,code,created,last_seen,tz,device,lang,news,plan,plan_until,role,ui_lang,must_change_password,blocked FROM users ORDER BY created DESC') as $u) {
            $id = (int)$u['id'];
            $s = $prog[$id] ?? Util::progressSummary([]);
            $t = $st[$id] ?? null;
            $row = [
                'id' => $id, 'name' => $u['name'], 'email' => $u['email'], 'avatar' => (int)$u['avatar'], 'code' => $u['code'],
                'owner' => $u['role'] === 'owner', 'role' => $u['role'], 'verified' => true,
                'created' => (int)$u['created'], 'last_seen' => (int)($u['last_seen'] ?: ($t['updated'] ?? 0)),
                'country' => Util::country($u['tz']), 'tz' => $u['tz'] ?: '', 'device' => $u['device'] ?: '',
                'xp' => $s['xp'], 'level' => (int)($t['level'] ?? 1), 'streak' => (int)($t['streak'] ?? 0),
                'learned' => $s['learned'], 'started' => $s['started'], 'answers' => $s['answers'], 'acc' => $s['acc'],
                'active_days' => $s['active_days'], 'tour' => $s['tour'], 'exam' => $s['exam'],
                'cur' => $bw['sec'][$s['cur']] ?? (str_starts_with((string)$s['cur'], 'u:') ? 'Свой набор' : $s['cur']),
                'usets' => $s['usets'], 'news' => (bool)$u['news'], 'plan' => $u['plan'] ?: 'free', 'plan_until' => (int)($u['plan_until'] ?: 0),
                'lang' => $u['ui_lang'] ?: 'ru', 'blocked' => (bool)$u['blocked'], 'must_change_password' => (bool)$u['must_change_password'],
            ];
            $out[] = $row + Users::schreibenSummary($id);
        }
        return $out;
    }

    public static function user(int $uid): array
    {
        $u = null;
        foreach (self::users() as $x) { if ($x['id'] === $uid) { $u = $x; break; } }
        if (!$u) { throw new ApiError(404, 'Пользователь не найден'); }
        $r = Db::one('SELECT data FROM progress WHERE user_id=?', [$uid]);
        $s = Util::progressSummary($r ? json_decode($r['data'], true) : []);
        $bw = Util::bookWords();
        $today = Util::localDay();
        $days = [];
        for ($d = $today - 29; $d <= $today; $d++) { $days[] = ['day' => $d, 'a' => (int)($s['days'][$d]['a'] ?? 0), 'k' => (int)($s['days'][$d]['k'] ?? 0)]; }
        $l = array_filter($s['l'], 'is_int');
        arsort($l);
        $hard = [];
        foreach ($l as $w => $k) { if (isset($bw['w'][$w])) { $hard[] = ['de' => $bw['w'][$w][0], 'ru' => $bw['w'][$w][1], 'miss' => $k]; } }
        $recent = array_map(fn($x) => $bw['sec'][$x] ?? (str_starts_with((string)$x, 'u:') ? 'Свой набор' : $x), $s['recent']);
        $modes = [];
        foreach ($s['ms'] as $k => $v) { if (is_array($v) && count($v) > 1) { $modes[] = [(string)$k, $v[0], $v[1]]; } }
        usort($modes, fn($a, $b) => $b[2] <=> $a[2]);
        return ['user' => $u, 'days' => $days, 'hard' => array_slice($hard, 0, 12), 'recent' => $recent, 'modes' => $modes,
                'friends' => (int)Db::val('SELECT COUNT(*) FROM friends WHERE user_id=?', [$uid]),
                'shares' => (int)Db::val('SELECT COUNT(*) FROM shares WHERE owner_id=?', [$uid]),
                'folders' => $s['folders'], 'goal' => $s['goal']];
    }

    // ---------- журнал ----------
    /** Пишет действие админа. В details никогда не попадают пароли, хеши и токены. */
    public static function audit(int $adminId, string $action, ?int $target, array $details = []): void
    {
        $clean = [];
        foreach ($details as $k => $v) { if (!preg_match('/pass|pw|token|secret|hash/i', (string)$k)) { $clean[$k] = $v; } }
        Db::run('INSERT INTO audit_log(admin_id,action,target_user_id,created,details) VALUES(?,?,?,?,?)',
            [$adminId, $action, $target, time(), json_encode($clean ?: new stdClass(), JSON_UNESCAPED_UNICODE)]);
    }

    public static function auditList(int $limit = 200): array
    {
        $limit = max(1, min(500, $limit));
        $rows = Db::all("SELECT a.id,a.admin_id,a.action,a.target_user_id,a.created,a.details, ua.name admin_name, ut.name target_name, ut.email target_email
            FROM audit_log a LEFT JOIN users ua ON ua.id=a.admin_id LEFT JOIN users ut ON ut.id=a.target_user_id ORDER BY a.id DESC LIMIT $limit");
        foreach ($rows as &$r) { $r['details'] = Util::jd($r['details']) ?: new stdClass(); }
        return $rows;
    }

    // ---------- действия ----------
    /** Кого можно трогать: владельца никто, админа только владелец, обычных пользователей и админы, и владелец. */
    private static function target(array $me, int $id, bool $selfOk = false): array
    {
        $t = Db::one('SELECT * FROM users WHERE id=?', [$id]);
        if (!$t) { throw new ApiError(404, 'Пользователь не найден'); }
        if ((int)$t['id'] === (int)$me['id'] && !$selfOk) { throw new ApiError(400, 'Своим аккаунтом можно управлять в профиле'); }
        if ($t['role'] === 'owner') { throw new ApiError(400, 'Владельца нельзя менять из админки'); }
        if ($t['role'] === 'admin' && $me['role'] !== 'owner') { throw new ApiError(403, 'Администратора может менять только владелец'); }
        return $t;
    }

    public static function resetPassword(array $me, int $id): array
    {
        $t = self::target($me, $id);
        $tmp = Auth::tempPassword(14);
        Db::run('UPDATE users SET pw=?, must_change_password=1 WHERE id=?', [Auth::hashPw($tmp), $t['id']]);
        Db::run('DELETE FROM sessions WHERE user_id=?', [$t['id']]);     // старые входы закрываем
        self::audit((int)$me['id'], 'reset_password', (int)$t['id'], ['email' => $t['email']]);
        return ['ok' => true, 'temp_password' => $tmp, 'note' => 'Пароль показан один раз. Пользователь обязан сменить его при входе.'];
    }

    public static function setRole(array $me, int $id, string $role): array
    {
        if ($me['role'] !== 'owner') { throw new ApiError(403, 'Это может только владелец сайта'); }
        if (!in_array($role, ['admin', 'user'], true)) { throw new ApiError(400, 'Такой роли нет'); }
        $t = Db::one('SELECT * FROM users WHERE id=?', [$id]);
        if (!$t) { throw new ApiError(404, 'Пользователь не найден'); }
        if ($t['role'] === 'owner') { throw new ApiError(400, 'Роль владельца меняется только передачей прав'); }
        Db::run('UPDATE users SET role=? WHERE id=?', [$role, $id]);
        self::audit((int)$me['id'], 'set_role', $id, ['from' => $t['role'], 'to' => $role]);
        return ['ok' => true];
    }

    public static function setPlan(array $me, int $id, string $plan, int $days): array
    {
        if (!in_array($plan, ['free', 'premium'], true)) { throw new ApiError(400, 'Такого плана нет'); }
        $t = self::target($me, $id, true);
        $until = ($plan === 'premium' && $days > 0) ? time() + $days * 86400 : null;
        Db::run('UPDATE users SET plan=?, plan_until=? WHERE id=?', [$plan, $until, $id]);
        self::audit((int)$me['id'], 'set_plan', $id, ['from' => $t['plan'], 'to' => $plan, 'days' => $days]);
        return ['ok' => true, 'plan' => $plan, 'plan_until' => (int)$until];
    }

    public static function setBlocked(array $me, int $id, bool $blocked): array
    {
        $t = self::target($me, $id);
        Db::run('UPDATE users SET blocked=? WHERE id=?', [$blocked ? 1 : 0, $id]);
        if ($blocked) { Db::run('DELETE FROM sessions WHERE user_id=?', [$id]); }
        self::audit((int)$me['id'], $blocked ? 'block' : 'unblock', $id, ['email' => $t['email']]);
        return ['ok' => true, 'blocked' => $blocked];
    }

    public static function transferOwner(array $me, int $id, string $password): array
    {
        if ($me['role'] !== 'owner') { throw new ApiError(403, 'Это может только владелец сайта'); }
        if ($id === (int)$me['id']) { throw new ApiError(400, 'Ты уже владелец'); }
        $t = Db::one('SELECT * FROM users WHERE id=?', [$id]);
        if (!$t) { throw new ApiError(404, 'Пользователь не найден'); }
        $key = 'own:' . $me['id'];
        if (Auth::tooMany($key)) { throw new ApiError(429, 'Слишком много попыток. Подожди 10 минут'); }
        if (!Auth::checkPw($password, $me['pw'])) { Auth::fail($key); throw new ApiError(401, 'Неверный пароль'); }
        Db::run("UPDATE users SET role='owner' WHERE id=?", [$id]);
        Db::run("UPDATE users SET role='admin' WHERE id=?", [$me['id']]);
        self::audit((int)$me['id'], 'transfer_owner', $id, ['from_email' => $me['email'], 'to_email' => $t['email']]);
        return ['ok' => true];
    }

    public static function deleteUser(array $me, int $id, string $email): array
    {
        if ($me['role'] !== 'owner') { throw new ApiError(403, 'Это может только владелец сайта'); }
        $t = Db::one('SELECT * FROM users WHERE id=?', [$id]);
        if (!$t) { throw new ApiError(404, 'Пользователь не найден'); }
        if ($t['role'] === 'owner' || $id === (int)$me['id']) { throw new ApiError(400, 'Владельца удалить нельзя'); }
        if (strtolower($email) !== strtolower($t['email'])) { throw new ApiError(400, 'Для подтверждения впиши почту пользователя'); }
        self::audit((int)$me['id'], 'delete_user', $id, ['email' => $t['email'], 'name' => $t['name']]);
        Users::delete($id);
        return ['ok' => true];
    }
}
