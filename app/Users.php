<?php
/** Пользователи, друзья, забеги: общие функции server.py. */
class Users
{
    const RACE_METRICS = ['xp' => 'XP во всех модулях', 'texts' => 'Тексты в Schreiben'];

    public static function json(array $u): array
    {
        return ['id' => (int)$u['id'], 'name' => $u['name'], 'email' => $u['email'], 'avatar' => (int)$u['avatar'], 'code' => $u['code'],
                'owner' => $u['role'] === 'owner', 'admin' => in_array($u['role'], ['admin', 'owner'], true), 'role' => $u['role'],
                'news' => (bool)$u['news'], 'verified' => true, 'plan' => $u['plan'] ?: 'free', 'lang' => $u['ui_lang'] ?: 'ru',
                'must_change_password' => (bool)$u['must_change_password']];
    }

    /** Почты создателя сайта (метка «Owner»): owner_emails в конфиге. */
    public static function ownerEmails(): array
    {
        $v = cfg('owner_emails', []);
        $v = is_array($v) ? $v : preg_split('/[,\s]+/', (string)$v);
        return array_values(array_filter(array_map(fn($e) => strtolower(trim($e)), $v)));
    }

    /** Если в базе ещё нет владельца, им становится аккаунт с почтой из owner_emails, а иначе тот, кто зарегистрировался первым. */
    public static function ensureOwner(): void
    {
        if (Db::one("SELECT 1 FROM users WHERE role='owner'")) { return; }
        foreach (self::ownerEmails() as $e) {
            if (Db::run("UPDATE users SET role='owner' WHERE lower(email)=?", [$e])) { return; }
        }
        Db::run("UPDATE users SET role='owner' WHERE id=(SELECT m FROM (SELECT MIN(id) m FROM users) t)");
    }

    /** Удаляет аккаунт и всё, что с ним связано. Владельца удалить нельзя (то же правит триггер owner_no_delete в базе). */
    public static function delete(int $uid): void
    {
        $role = Db::val('SELECT role FROM users WHERE id=?', [$uid]);
        if ($role === 'owner') { throw new ApiError(400, 'Владельца удалить нельзя'); }
        Db::run('DELETE FROM sessions WHERE user_id=?', [$uid]);
        Db::run('DELETE FROM progress WHERE user_id=?', [$uid]);
        Db::run('DELETE FROM stats WHERE user_id=?', [$uid]);
        Db::run('DELETE FROM friends WHERE user_id=? OR friend_id=?', [$uid, $uid]);
        Db::run('DELETE FROM shares WHERE owner_id=?', [$uid]);
        Db::run("UPDATE presence SET uid=NULL, what='' WHERE uid=?", [$uid]);
        Db::run('UPDATE ai_log SET uid=NULL WHERE uid=?', [$uid]);
        Db::run('DELETE FROM users WHERE id=?', [$uid]);
    }

    public static function schreibenXp(int $uid): int
    {
        $r = Db::one('SELECT data FROM schreiben_state WHERE user_id=?', [$uid]);
        if (!$r) { return 0; }
        $d = json_decode($r['data'], true);
        return is_array($d) ? Util::int($d['xp'] ?? 0) : 0;
    }

    /** XP, набранный в модулях EPD (Grammatik, Lesen, Hören, Sprechen, Свободный): число лежит в состоянии под ключом xp. */
    public static function deXp(int $uid): int
    {
        $r = Db::one("SELECT v FROM kv WHERE user_id=? AND k='xp'", [$uid]);
        return $r ? max(0, (int)json_decode((string)$r['v'], true)) : 0;
    }

    /** Для друзей и рейтинга: тексты за неделю и всего, средний процент, XP в Schreiben. */
    public static function schreibenSummary(int $uid): array
    {
        $week = time() - 7 * 86400;
        $r = Db::one('SELECT COUNT(*) n, COALESCE(AVG(points/max_points),0) a, COALESCE(SUM(ts>?),0) w FROM schreiben_essays WHERE user_id=?', [$week, $uid]);
        return ['s_xp' => self::schreibenXp($uid), 's_week' => (int)$r['w'], 's_total' => (int)$r['n'], 's_avg' => (int)round((float)$r['a'] * 100)];
    }

    public static function friendRows(int $uid): array
    {
        $rows = Db::all('SELECT u.id,u.name,u.role,u.avatar,u.code,s.xp,s.week_xp,s.streak,s.learned,s.acc,s.today,s.level,s.updated
            FROM users u LEFT JOIN stats s ON s.user_id=u.id
            WHERE u.id=? OR u.id IN (SELECT friend_id FROM friends WHERE user_id=?)', [$uid, $uid]);
        $out = [];
        foreach ($rows as $r) {
            $d = $r;
            $d['owner'] = $d['role'] === 'owner'; unset($d['role']);      // почту друзей наружу не отдаём
            foreach (['xp', 'week_xp', 'streak', 'learned', 'acc', 'today', 'updated'] as $k) { $d[$k] = (int)($d[$k] ?? 0); }
            $d['level'] = (int)($d['level'] ?: 1);
            $d['me'] = (int)$r['id'] === $uid;
            $d['de_xp'] = self::deXp((int)$r['id']);
            $out[] = $d + self::schreibenSummary((int)$r['id']) + ['xp_total' => $d['xp'] + self::schreibenXp((int)$r['id']) + $d['de_xp']];
        }
        return $out;
    }

    // ---------- забеги ----------
    private static function raceValue(int $uid, string $metric, int $start, int $end): int
    {
        if ($metric === 'texts') { return (int)Db::val('SELECT COUNT(*) FROM schreiben_essays WHERE user_id=? AND ts BETWEEN ? AND ?', [$uid, $start, $end]); }
        return (int)Db::val('SELECT xp FROM stats WHERE user_id=?', [$uid]) + self::schreibenXp($uid) + self::deXp($uid);
    }

    /** У закончившихся XP-забегов сохраняем итог (иначе после финиша XP продолжал бы расти). */
    public static function finishRaces(): void
    {
        foreach (Db::all("SELECT * FROM races WHERE metric='xp' AND `end`<=? AND created>0", [time()]) as $r) {
            $va = max(0, self::raceValue((int)$r['a'], 'xp', 0, 0) - (int)$r['a0']);
            $vb = max(0, self::raceValue((int)$r['b'], 'xp', 0, 0) - (int)$r['b0']);
            Db::run('UPDATE races SET a0=?, b0=?, created=0 WHERE id=?', [$va, $vb, $r['id']]);
        }
    }

    public static function raceRows(int $uid): array
    {
        self::finishRaces();
        $now = time();
        $rows = Db::all('SELECT r.*, ua.name an, ua.avatar aa, ub.name bn, ub.avatar ba FROM races r
            JOIN users ua ON ua.id=r.a JOIN users ub ON ub.id=r.b
            WHERE (r.a=? OR r.b=?) AND r.`end`>? ORDER BY r.`end` DESC', [$uid, $uid, $now - 14 * 86400]);
        $out = [];
        foreach ($rows as $r) {
            $live = $r['end'] > $now;
            if ($r['metric'] === 'texts') {
                $va = self::raceValue((int)$r['a'], 'texts', (int)$r['start'], (int)$r['end']); $vb = self::raceValue((int)$r['b'], 'texts', (int)$r['start'], (int)$r['end']);
            } else {   // XP считаем от снимка на старте; после финиша — из сохранённого итога
                $va = $live ? max(0, self::raceValue((int)$r['a'], 'xp', 0, 0) - (int)$r['a0']) : (int)$r['a0'];
                $vb = $live ? max(0, self::raceValue((int)$r['b'], 'xp', 0, 0) - (int)$r['b0']) : (int)$r['b0'];
            }
            $meA = (int)$r['a'] === $uid;
            $out[] = ['id' => (int)$r['id'], 'metric' => $r['metric'], 'metricName' => self::RACE_METRICS[$r['metric']] ?? $r['metric'],
                'start' => (int)$r['start'], 'end' => (int)$r['end'], 'live' => $live,
                'me' => ['id' => $uid, 'name' => $meA ? $r['an'] : $r['bn'], 'avatar' => (int)($meA ? $r['aa'] : $r['ba']), 'v' => $meA ? $va : $vb],
                'rival' => ['id' => (int)($meA ? $r['b'] : $r['a']), 'name' => $meA ? $r['bn'] : $r['an'], 'avatar' => (int)($meA ? $r['ba'] : $r['aa']), 'v' => $meA ? $vb : $va]];
        }
        return $out;
    }

    public static function raceStart(int $uid, int $fid, string $metric, int $days): void
    {
        $now = time();
        Db::run('INSERT INTO races(a,b,metric,`start`,`end`,a0,b0,created) VALUES(?,?,?,?,?,?,?,?)',
            [$uid, $fid, $metric, $now, $now + $days * 86400,
             $metric === 'xp' ? self::raceValue($uid, 'xp', 0, 0) : 0, $metric === 'xp' ? self::raceValue($fid, 'xp', 0, 0) : 0, $now]);
    }
}
