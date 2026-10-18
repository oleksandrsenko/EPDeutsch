<?php
/** Маршрутизатор /api/*: порт Handler.route из server.py с изменениями A–C (см. docs/php-migration/inventory.md). */
class Api
{
    /** Хеш-пустышка: чтобы вход с неизвестной почтой занимал столько же времени, сколько вход с известной. */
    private const DUMMY_HASH = '$2y$12$Q5zYlkfexEfn0KjFbAC1puC8AkaZ4pg2I/sj9B4jy/qU/byzTibUm';

    public static function handle(string $method, string $path): void
    {
        // защита от CSRF: чужой сайт не может отправить свой заголовок без CORS, а CORS мы не включаем
        if ($method !== 'GET' && $method !== 'HEAD' && Http::header('X-USD') !== '1') { throw new ApiError(403, 'Запрос отклонён'); }
        Db::begin();
        $headers = [];
        try {
            $res = self::route($method, substr($path, 4), $headers);
            Db::finish(true);
        } catch (Throwable $e) {
            Db::finish(false);
            throw $e;
        }
        Maintenance::maybeRun();
        Http::send(200, $res, $headers);
    }

    private static function needUser(bool $allowMustChange = false): array
    {
        $u = Auth::currentUser();
        if (!$u) { throw new ApiError(401, 'Нужно войти в аккаунт'); }
        if ($u['must_change_password'] && !$allowMustChange) {
            throw new ApiError(403, 'Нужно сменить временный пароль', ['must_change_password' => true]);
        }
        return $u;
    }
    private static function needAdmin(): array
    {
        $u = self::needUser();
        if (!in_array($u['role'], ['admin', 'owner'], true)) { throw new ApiError(403, 'Доступ только для администраторов'); }
        return $u;
    }

    private static function sessionPayload(array $u): array
    {
        $pr = Db::one('SELECT data,updated FROM progress WHERE user_id=?', [$u['id']]);
        return ['user' => Users::json($u), 'progress' => $pr ? Util::jd($pr['data']) : null, 'updated' => $pr ? (int)$pr['updated'] : 0];
    }

    private static function route(string $method, string $p, array &$headers): array
    {
        $now = time();

        if ($method === 'GET' && $p === '/me') {
            $u = Auth::currentUser();
            if (!$u) { return ['user' => null]; }
            $pr = Db::one('SELECT data,updated FROM progress WHERE user_id=?', [$u['id']]);
            return ['user' => Users::json($u), 'mail' => false, 'progress' => $pr ? Util::jd($pr['data']) : null, 'updated' => $pr ? (int)$pr['updated'] : 0];
        }

        // A: подтверждение почты и восстановление по почте убраны
        if (in_array($p, ['/verify', '/verify/resend'], true)) { throw new ApiError(410, 'Подтверждение почты отключено: аккаунт активен сразу'); }
        if (in_array($p, ['/forgot', '/reset'], true)) {
            throw new ApiError(410, 'Восстановление по почте отключено. Если забыл пароль, попроси администратора выдать временный пароль.');
        }

        if ($method === 'GET' && $p === '/unsub') {
            $uid = Util::int($_GET['u'] ?? 0);
            $sig = substr(hash_hmac('sha256', 'unsub:' . $uid, Auth::secret()), 0, 24);
            $ok = hash_equals($sig, (string)($_GET['s'] ?? ''));
            if ($ok) { Db::run('UPDATE users SET news=0 WHERE id=?', [$uid]); }
            header('Content-Type: text/html; charset=utf-8');
            [$h, $t] = $ok ? ['Готово, ты отписан(а)', 'Рассылка больше не придёт. Включить её снова можно в профиле.'] : ['Ссылка не сработала', 'Отписаться можно в профиле на сайте.'];
            Db::finish(true);
            echo '<!doctype html><meta charset=utf-8><meta name=viewport content="width=device-width,initial-scale=1"><title>EPDeutsch</title>'
                . '<body style="font-family:-apple-system,Segoe UI,Arial,sans-serif;background:#F6F7FB;color:#282E3E;display:grid;place-items:center;min-height:90vh;margin:0">'
                . '<div style="background:#fff;border-radius:22px;padding:34px 32px;max-width:420px"><h1 style="margin:0 0 10px;font-size:23px">' . $h . '</h1>'
                . '<p style="color:#586380;line-height:1.6">' . $t . '</p><a href="/" style="color:#6C5CAC;font-weight:700">Открыть EPDeutsch</a></div></body>';
            exit;
        }

        if ($method === 'POST' && $p === '/register') return self::register($headers);
        if ($method === 'POST' && $p === '/login') return self::login($headers);

        if ($method === 'POST' && $p === '/logout') {
            if (!empty($_COOKIE['sid']) && is_string($_COOKIE['sid'])) { Db::run('DELETE FROM sessions WHERE token=?', [Util::sha($_COOKIE['sid'])]); }
            $headers[] = Auth::dropSessionCookie();
            return ['ok' => true];
        }

        if ($method === 'POST' && $p === '/password') return self::changePassword($headers);

        if ($method === 'PUT' && $p === '/progress') {
            $u = self::needUser();
            $body = Http::jsonObj();
            if (!isset($body->data) || !is_object($body->data)) { throw new ApiError(400, 'Нет данных прогресса'); }
            $uid = (int)$u['id'];
            Db::run('INSERT INTO progress(user_id,data,updated) VALUES(?,?,?) ON DUPLICATE KEY UPDATE data=VALUES(data), updated=VALUES(updated)',
                [$uid, json_encode($body->data, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES), $now]);
            // «последний раз занимался» двигаем, только когда стало больше ответов на карточки (а не при смене темы или настроек)
            $ans = Util::progressSummary(json_decode(json_encode($body->data), true))['answers'];
            $old = Db::one('SELECT answers FROM stats WHERE user_id=?', [$uid]);
            if ($old === null || $ans > (int)$old['answers']) {
                Db::run('INSERT INTO stats(user_id,answers,studied) VALUES(?,?,?) ON DUPLICATE KEY UPDATE answers=VALUES(answers), studied=VALUES(studied)', [$uid, $ans, $ans ? $now : 0]);
            } elseif ($ans < (int)$old['answers']) {      // прогресс сбросили или загрузили старый: запоминаем число, дату не трогаем
                Db::run('UPDATE stats SET answers=? WHERE user_id=?', [$ans, $uid]);
            }
            $st = (array)($body->stats ?? new stdClass());
            Db::run('INSERT INTO stats(user_id,xp,week_xp,streak,learned,acc,today,level,updated) VALUES(?,?,?,?,?,?,?,?,?)
                ON DUPLICATE KEY UPDATE xp=VALUES(xp),week_xp=VALUES(week_xp),streak=VALUES(streak),learned=VALUES(learned),acc=VALUES(acc),today=VALUES(today),level=VALUES(level),updated=VALUES(updated)',
                [$uid, Util::int($st['xp'] ?? 0), Util::int($st['week_xp'] ?? 0), Util::int($st['streak'] ?? 0, 0, 100000), Util::int($st['learned'] ?? 0, 0, 100000),
                 Util::int($st['acc'] ?? 0, 0, 100), Util::int($st['today'] ?? 0, 0, 100000), Util::int($st['level'] ?? 1, 1, 10000), $now]);
            return ['ok' => true, 'updated' => $now];
        }

        if ($method === 'PATCH' && $p === '/profile') {
            $u = self::needUser();
            $d = Http::json();
            $name = Util::str($d['name'] ?? '', 40) ?: $u['name'];
            $avatar = Util::int($d['avatar'] ?? $u['avatar'], 0, Util::AVATAR_MAX);
            $news = array_key_exists('news', $d) ? ($d['news'] === true ? 1 : 0) : (int)$u['news'];
            $lang = in_array($d['lang'] ?? null, Util::LANGS, true) ? $d['lang'] : ($u['ui_lang'] ?: 'ru');
            Db::run('UPDATE users SET name=?, avatar=?, news=?, ui_lang=? WHERE id=?', [$name, $avatar, $news, $lang, $u['id']]);
            return ['user' => Users::json(Db::one('SELECT * FROM users WHERE id=?', [$u['id']]))];
        }

        if ($method === 'DELETE' && $p === '/account') {
            $u = self::needUser();
            if ($u['role'] === 'owner') { throw new ApiError(400, 'Владельца удалить нельзя. Сначала передай права владельца в админке'); }
            $pw = (string)(Http::json()['password'] ?? '');
            $key = Http::ip() . '|del|' . $u['id'];
            if (Auth::tooMany($key)) { throw new ApiError(429, 'Слишком много попыток. Подожди 10 минут'); }
            if (!Auth::checkPw($pw, $u['pw'])) { Auth::fail($key); throw new ApiError(401, 'Неверный пароль'); }
            Users::delete((int)$u['id']);
            Auth::clear('f:' . $key);
            $headers[] = Auth::dropSessionCookie();
            return ['ok' => true];
        }

        if ($p === '/friends' && $method === 'GET') { return ['friends' => Users::friendRows((int)self::needUser()['id'])]; }
        if ($p === '/friends' && $method === 'POST') {
            $u = self::needUser();
            $code = strtoupper(str_replace(' ', '', Util::str(Http::json()['code'] ?? '', 12)));
            $f = Db::one('SELECT id,name FROM users WHERE code=?', [$code]);
            if (!$f) { throw new ApiError(404, 'Такого кода нет. Проверь его у друга'); }
            if ((int)$f['id'] === (int)$u['id']) { throw new ApiError(400, 'Это твой собственный код'); }
            foreach ([[$u['id'], $f['id']], [$f['id'], $u['id']]] as [$a, $b]) {
                Db::run('INSERT IGNORE INTO friends(user_id,friend_id,created) VALUES(?,?,?)', [$a, $b, $now]);
            }
            return ['ok' => true, 'name' => $f['name']];
        }
        if ($method === 'DELETE' && preg_match('#^/friends/(\d+)$#', $p, $m)) {
            $u = self::needUser();
            $fid = (int)$m[1];
            Db::run('DELETE FROM friends WHERE (user_id=? AND friend_id=?) OR (user_id=? AND friend_id=?)', [$u['id'], $fid, $fid, $u['id']]);
            return ['ok' => true];
        }

        if ($p === '/races' && $method === 'GET') { return ['races' => Users::raceRows((int)self::needUser()['id']), 'metrics' => Users::RACE_METRICS]; }
        if ($p === '/races' && $method === 'POST') {
            $u = self::needUser();
            $d = Http::json();
            $fid = Util::int($d['friend'] ?? 0); $metric = Util::str($d['metric'] ?? '', 10); $days = Util::int($d['days'] ?? 0, 3, 14);
            if (!isset(Users::RACE_METRICS[$metric])) { throw new ApiError(400, 'Такого забега нет'); }
            if (!Db::one('SELECT 1 FROM friends WHERE user_id=? AND friend_id=?', [$u['id'], $fid])) { throw new ApiError(400, 'Забег можно начать только с другом'); }
            if (Db::one('SELECT 1 FROM races WHERE ((a=? AND b=?) OR (a=? AND b=?)) AND `end`>?', [$u['id'], $fid, $fid, $u['id'], $now])) { throw new ApiError(409, 'С этим другом забег уже идёт'); }
            Users::raceStart((int)$u['id'], $fid, $metric, $days);
            return ['races' => Users::raceRows((int)$u['id'])];
        }
        if ($method === 'DELETE' && preg_match('#^/races/(\d+)$#', $p, $m)) {
            $u = self::needUser();
            Db::run('DELETE FROM races WHERE id=? AND (a=? OR b=?)', [(int)$m[1], $u['id'], $u['id']]);
            return ['ok' => true];
        }

        // слова, сохранённые в других модулях: Wörter забирает их в набор
        if ($p === '/words' && $method === 'GET') {
            $u = self::needUser();
            return ['words' => Db::all('SELECT id,de,ru,ctx,ts FROM word_inbox WHERE user_id=? ORDER BY id', [$u['id']])];
        }
        if ($p === '/words' && $method === 'POST') {
            $u = self::needUser();
            $d = Http::json();
            $de = Util::str($d['de'] ?? '', 120); $ru = Util::str($d['ru'] ?? '', 200);
            if ($de === '' || $ru === '') { throw new ApiError(400, 'Нужны слово и перевод'); }
            if ((int)Db::val('SELECT COUNT(*) FROM word_inbox WHERE user_id=?', [$u['id']]) >= 500) { throw new ApiError(400, 'Слишком много несохранённых слов. Открой Wörter, они перенесутся в набор'); }
            if (!Db::one('SELECT 1 FROM word_inbox WHERE user_id=? AND lower(de)=lower(?)', [$u['id'], $de])) {
                Db::run('INSERT INTO word_inbox(user_id,de,ru,ctx,ts) VALUES(?,?,?,?,?)', [$u['id'], $de, $ru, Util::str($d['ctx'] ?? '', 300), $now]);
            }
            return ['ok' => true];
        }
        if ($p === '/words' && $method === 'DELETE') {
            $u = self::needUser();
            Db::run('DELETE FROM word_inbox WHERE user_id=? AND id<=?', [$u['id'], Util::int(Http::json()['upto'] ?? 0)]);
            return ['ok' => true];
        }

        if ($method === 'POST' && $p === '/share') {
            $u = self::needUser();
            $d = Http::json();
            $name = Util::str($d['name'] ?? '', 80) ?: 'Набор';
            $cards = [];
            foreach (array_slice(is_array($d['cards'] ?? null) ? $d['cards'] : [], 0, 2000) as $x) {
                if (is_array($x)) {
                    $de = Util::str($x['de'] ?? '', 300); $ru = Util::str($x['ru'] ?? '', 300);
                    if ($de !== '' && $ru !== '') { $cards[] = ['de' => $de, 'ru' => $ru, 'ex' => Util::str($x['ex'] ?? '', 600)]; }
                }
            }
            if (!$cards) { throw new ApiError(400, 'В наборе нет карточек'); }
            $code = Util::newCode('shares', 'code', 8);
            Db::run('INSERT INTO shares(code,owner_id,name,descr,cards,created) VALUES(?,?,?,?,?,?)',
                [$code, $u['id'], $name, Util::str($d['desc'] ?? '', 200), json_encode($cards, JSON_UNESCAPED_UNICODE), $now]);
            return ['code' => $code];
        }

        if ($method === 'POST' && $p === '/translate') {
            $d = Http::json();
            $text = Util::str($d['text'] ?? '', 1500); $sl = Util::str($d['from'] ?? '', 2); $tl = Util::str($d['to'] ?? '', 2);
            if (!in_array($sl, ['de', 'ru', 'en'], true) || !in_array($tl, ['de', 'ru', 'en'], true) || $sl === $tl) { throw new ApiError(400, 'Неверная пара языков'); }
            if ($text === '') { throw new ApiError(400, 'Пустой текст'); }
            if ($row = Ai::trGet($text, $sl, $tl)) { return ['text' => $row['out'], 'provider' => $row['prov']]; }
            if (Ai::trLimited()) { throw new ApiError(429, 'Слишком много переводов подряд, подожди минуту'); }
            try { [$out, $prov] = Ai::translate($text, $sl, $tl); }
            catch (Throwable $e) { error_log('translate error: ' . $e->getMessage()); throw new ApiError(502, 'Переводчик сейчас недоступен'); }
            Ai::trPut($text, $sl, $tl, $out, $prov);
            return ['text' => $out, 'provider' => $prov];
        }

        if ($method === 'POST' && $p === '/article') {
            $word = Util::str(Http::json()['word'] ?? '', 60);
            if (!preg_match('/^[A-ZÄÖÜ][a-zäöüß]+(?:-[A-ZÄÖÜa-zäöüß]+)*$/u', $word)) { throw new ApiError(400, 'Нужно одно существительное с большой буквы'); }
            if ($row = Ai::trGet($word, 'de', 'gn')) { return ['article' => $row['out']]; }
            if (Ai::trLimited()) { throw new ApiError(429, 'Слишком много запросов подряд, подожди минуту'); }
            try { $art = Ai::wikiGender($word); }
            catch (Throwable $e) { error_log('article error: ' . $e->getMessage()); throw new ApiError(502, 'Словарь сейчас недоступен'); }
            Ai::trPut($word, 'de', 'gn', $art, 'wiktionary');
            return ['article' => $art];
        }

        if (str_starts_with($p, '/de/')) { return Lernen::route(self::needUser(), $method, substr($p, 3)); }
        if ($method === 'GET' && $p === '/regopen') { return ['open' => Lernen::registrationOpen()]; }
        if (str_starts_with($p, '/schreiben/')) { return Schreiben::route(self::needUser(), $method, substr($p, strlen('/schreiben/'))); }
        if ($method === 'POST' && $p === '/practice/check') { return Ai::practiceCheck(self::needUser(), Http::json()); }

        if ($method === 'GET' && preg_match('#^/share/([A-Z0-9]{4,12})$#', $p, $m)) {
            $r = Db::one('SELECT s.*, u.name AS owner FROM shares s JOIN users u ON u.id=s.owner_id WHERE s.code=?', [$m[1]]);
            if (!$r) { throw new ApiError(404, 'Набор не найден. Возможно, ссылка неполная'); }
            return ['name' => $r['name'], 'desc' => $r['descr'], 'owner' => $r['owner'], 'cards' => Util::jd($r['cards'])];
        }

        // ---------- аналитика: просмотр страницы и «я на сайте» ----------
        if ($method === 'POST' && in_array($p, ['/hit', '/ping'], true)) {
            $ua = $_SERVER['HTTP_USER_AGENT'] ?? '';
            if (preg_match('/bot|crawl|spider|slurp|preview|facebookexternalhit|headless|lighthouse/i', $ua)) { return ['ok' => true]; }
            $d = Http::json();
            $u = Auth::currentUser();
            $uid = $u ? (int)$u['id'] : null;
            $vkey = Util::visitorKey(Http::ip(), $ua);
            [$dev, $os, $br] = Util::parseUa($ua);
            $tz = Util::str($d['tz'] ?? '', 64); $tab = Util::str($d['tab'] ?? '', 20); $what = Util::str($d['what'] ?? '', 90);
            if ($p === '/hit') {
                $ref = Util::str($d['ref'] ?? '', 300);
                $host = str_starts_with($ref, 'http') ? strtolower((string)parse_url($ref, PHP_URL_HOST)) : '';
                if (str_starts_with($host, 'www.')) { $host = substr($host, 4); }
                $me = strtolower(explode(':', $_SERVER['HTTP_HOST'] ?? '')[0]);
                if ($host && $me && $host === $me) { $host = ''; }
                Db::run('INSERT INTO hits(ts,day,vkey,uid,path,ref,device,os,browser,tz,lang) VALUES(?,?,?,?,?,?,?,?,?,?,?)',
                    [$now, Util::localDay($now), $vkey, $uid, Util::str($d['path'] ?? '', 60) ?: '/', $host, $dev, $os, $br, $tz, Util::str($d['lang'] ?? '', 12)]);
            }
            Db::run('INSERT INTO presence(vkey,uid,ts,tab,device,tz,what) VALUES(?,?,?,?,?,?,?) ON DUPLICATE KEY UPDATE uid=VALUES(uid),ts=VALUES(ts),tab=VALUES(tab),device=VALUES(device),tz=VALUES(tz),what=VALUES(what)',
                [$vkey, $uid, $now, $tab, $dev, $tz, $uid ? $what : '']);
            if ($u) {
                Db::run("UPDATE users SET last_seen=?, tz=COALESCE(NULLIF(?,''),tz), device=?, lang=COALESCE(NULLIF(?,''),lang) WHERE id=?",
                    [$now, $tz, $dev . ' · ' . $os, Util::str($d['lang'] ?? '', 12), $uid]);
            }
            return ['ok' => true];
        }

        // ---------- админка ----------
        if ($method === 'GET' && str_starts_with($p, '/admin/')) {
            self::needAdmin();
            if ($p === '/admin/overview') { return Admin::overview(Util::int($_GET['days'] ?? 30, 7, 365)); }
            if ($p === '/admin/users') { return ['users' => Admin::users()]; }
            if ($p === '/admin/audit') { return ['log' => Admin::auditList(Util::int($_GET['limit'] ?? 200, 1, 500))]; }
            if ($p === '/admin/diag') { return self::diag(); }
            if (preg_match('#^/admin/user/(\d+)$#', $p, $m)) { return Admin::user((int)$m[1]); }
        }

        if (in_array($method, ['POST', 'DELETE'], true) && preg_match('#^/admin/user/(\d+)(/reset|/role|/owner|/plan|/block)?$#', $p, $m)) {
            $me = self::needAdmin();
            $id = (int)$m[1]; $act = $m[2] ?? '';
            if ($method === 'POST' && $act === '/reset') { return Admin::resetPassword($me, $id); }
            if ($method === 'POST' && $act === '/role') { return Admin::setRole($me, $id, Util::str(Http::json()['role'] ?? '', 10)); }
            if ($method === 'POST' && $act === '/owner') { return Admin::transferOwner($me, $id, (string)(Http::json()['password'] ?? '')); }
            if ($method === 'POST' && $act === '/plan') { $d = Http::json(); return Admin::setPlan($me, $id, Util::str($d['plan'] ?? '', 10), Util::int($d['days'] ?? 0, 0, 3650)); }
            if ($method === 'POST' && $act === '/block') { $d = Http::json(); return Admin::setBlocked($me, $id, !isset($d['blocked']) || (bool)$d['blocked']); }
            if ($method === 'DELETE' && $act === '') { return Admin::deleteUser($me, $id, Util::str(Http::json()['email'] ?? '', 254)); }
        }

        throw new ApiError(404, 'Не найдено');
    }

    // ---------- вход, регистрация, смена пароля ----------
    private static function register(array &$headers): array
    {
        $d = Http::json();
        $name = Util::str($d['name'] ?? '', 40); $email = strtolower(Util::str($d['email'] ?? '', 254)); $pw = (string)($d['password'] ?? '');
        if ($name === '') { throw new ApiError(400, 'Введи имя'); }
        if (!preg_match(Auth::EMAIL_RE, $email)) { throw new ApiError(400, 'Проверь адрес почты'); }
        if ($bad = Auth::pwProblem($pw)) { throw new ApiError(400, $bad); }
        $invite = strtoupper(Util::str($d['invite'] ?? '', 20));
        if (!Lernen::registrationOpen() && !Lernen::inviteOk($invite)) { throw new ApiError(403, 'Регистрация только по приглашению. Попроси код у владельца'); }
        // A: защита от спама — не больше N регистраций с одного IP в час (считаются и неудачные попытки с верным приглашением)
        $ipKey = 'reg:' . Http::ip();
        if (Auth::count($ipKey, 3600) >= (int)cfg('register_per_ip_hour', 3)) { throw new ApiError(429, 'Слишком много регистраций с одного адреса. Попробуй через час'); }
        if (Db::one('SELECT 1 FROM users WHERE email=?', [$email])) { Auth::hit($ipKey); throw new ApiError(409, 'Аккаунт с этой почтой уже есть, просто войди'); }
        $now = time();
        Db::run('INSERT INTO users(email,name,avatar,pw,code,created,news,verified) VALUES(?,?,?,?,?,?,?,1)',
            [$email, $name, random_int(0, 7), Auth::hashPw($pw), Util::newCode('users', 'code', 6), $now, 1]);
        $uid = Db::insertId();
        if ($invite) { Lernen::inviteUse($invite, $uid); }
        Users::ensureOwner();
        Db::run('INSERT INTO stats(user_id,updated) VALUES(?,?)', [$uid, $now]);
        Auth::hit($ipKey);
        $headers[] = Auth::startSession($uid);
        return ['user' => Users::json(Db::one('SELECT * FROM users WHERE id=?', [$uid])), 'progress' => null, 'updated' => 0];
    }

    private static function login(array &$headers): array
    {
        $d = Http::json();
        $email = strtolower(Util::str($d['email'] ?? '', 254)); $pw = (string)($d['password'] ?? '');
        $key = Http::ip() . '|' . $email;
        if (Auth::tooMany($key)) { throw new ApiError(429, 'Слишком много попыток. Подожди 10 минут'); }
        $u = Db::one('SELECT * FROM users WHERE email=?', [$email]);
        // одинаковое время ответа для неизвестной почты: считаем хеш и в этом случае
        $ok = $u ? Auth::checkPw($pw, $u['pw']) : (password_verify($pw, self::DUMMY_HASH) && false);
        if (!$u || !$ok) { Auth::fail($key); throw new ApiError(401, 'Неверная почта или пароль'); }
        if ($u['blocked']) { throw new ApiError(403, 'Вход в этот аккаунт заблокирован администратором'); }
        if (Auth::needsRehash($u['pw'])) {      // B: старый PBKDF2 → новый формат
            $new = Auth::hashPw($pw);
            Db::run('UPDATE users SET pw=? WHERE id=?', [$new, $u['id']]);
            $u['pw'] = $new;
        }
        Auth::clear('f:' . $key);
        $headers[] = Auth::startSession((int)$u['id']);
        return self::sessionPayload($u);
    }

    /** B/C: смена своего пароля; обязательна после сброса админом. */
    private static function changePassword(array &$headers): array
    {
        $u = self::needUser(true);
        $d = Http::json();
        $cur = (string)($d['current'] ?? ''); $new = (string)($d['password'] ?? '');
        $key = Http::ip() . '|pw|' . $u['id'];
        if (Auth::tooMany($key)) { throw new ApiError(429, 'Слишком много попыток. Подожди 10 минут'); }
        if (!Auth::checkPw($cur, $u['pw'])) { Auth::fail($key); throw new ApiError(401, 'Текущий пароль неверный'); }
        if ($bad = Auth::pwProblem($new)) { throw new ApiError(400, $bad); }
        if (hash_equals($cur, $new)) { throw new ApiError(400, 'Новый пароль должен отличаться от временного'); }
        Db::run('UPDATE users SET pw=?, must_change_password=0 WHERE id=?', [Auth::hashPw($new), $u['id']]);
        Db::run('DELETE FROM sessions WHERE user_id=?', [$u['id']]);       // входы на других устройствах закрываем
        Auth::clear('f:' . $key);
        $headers[] = Auth::startSession((int)$u['id']);
        return self::sessionPayload(Db::one('SELECT * FROM users WHERE id=?', [$u['id']]));
    }

    /** Для проверки после выгрузки на хостинг: версия PHP, расширения, лимиты, видимый IP. Секретов не содержит. */
    private static function diag(): array
    {
        $out = self::diagBase();
        if (!empty($_GET['net'])) {      // /api/admin/diag?net=1: достаёт ли хостинг до внешних сервисов (любой HTTP-ответ = достаёт)
            $net = [];
            foreach (['anthropic' => 'https://api.anthropic.com/', 'openai' => 'https://api.openai.com/', 'deepl' => 'https://api-free.deepl.com/',
                      'mymemory' => 'https://api.mymemory.translated.net/', 'languagetool' => 'https://api.languagetool.org/v2/languages',
                      'wiktionary' => 'https://de.wiktionary.org/'] as $k => $url) {
                $t0 = microtime(true);
                try { [$code] = Ai::http($url, null, ['User-Agent: EPDeutsch/1'], 6); $net[$k] = ['ok' => true, 'http' => $code, 'ms' => (int)((microtime(true) - $t0) * 1000)]; }
                catch (Throwable $e) { $net[$k] = ['ok' => false, 'error' => mb_substr($e->getMessage(), 0, 120)]; }
            }
            $out['net'] = $net;
        }
        return $out;
    }

    private static function diagBase(): array
    {
        return ['php' => PHP_VERSION, 'ext' => ['curl' => extension_loaded('curl'), 'sodium' => extension_loaded('sodium'), 'mbstring' => extension_loaded('mbstring'), 'pdo_mysql' => extension_loaded('pdo_mysql')],
            'argon2id' => defined('PASSWORD_ARGON2ID'), 'max_execution_time' => ini_get('max_execution_time'), 'post_max_size' => ini_get('post_max_size'),
            'upload_max_filesize' => ini_get('upload_max_filesize'), 'remote_addr' => $_SERVER['REMOTE_ADDR'] ?? '',
            'x_forwarded_for' => $_SERVER['HTTP_X_FORWARDED_FOR'] ?? '', 'cf_connecting_ip' => $_SERVER['HTTP_CF_CONNECTING_IP'] ?? '', 'ip_used' => Http::ip(),
            'https' => Http::secure(), 'audio_writable' => is_writable(EPD_AUDIO) || (!is_dir(EPD_AUDIO) && is_writable(dirname(EPD_AUDIO))),
            'storage_writable' => is_writable(EPD_STORAGE),
            'keys' => ['anthropic' => (bool)Ai::key(), 'openai' => (bool)Ai::openaiKey(), 'deepl' => (bool)Ai::deeplKey()]];
    }
}
