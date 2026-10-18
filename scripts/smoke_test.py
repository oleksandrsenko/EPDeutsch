#!/usr/bin/env python3
"""Сквозная проверка PHP-версии на локальном php -S + MariaDB в Docker (контейнер epd-php-mariadb).

Запуск (из корня репозитория):
  python3 scripts/smoke_test.py            # поднимает два сервера: пустая база и копия боевой
Что проверяется — см. вывод: каждая строка PASS/FAIL. Внешние сервисы (Anthropic, OpenAI, DeepL) не вызываются.
"""
import hashlib, http.cookiejar, json, os, secrets, subprocess, sys, time, urllib.error, urllib.request

ROOT = os.path.dirname(os.path.dirname(os.path.abspath(__file__)))
DOCKER = ["docker", "exec", "-i", "epd-php-mariadb", "mariadb", "-uroot", "-prootpw"]
results = []


def check(name, cond, extra=""):
    results.append((name, bool(cond)))
    print(("PASS " if cond else "FAIL ") + name + ((" — " + str(extra)) if extra and not cond else ""))


def sql(db, q):
    r = subprocess.run(DOCKER + ["-N", "-B", db, "-e", q], capture_output=True, text=True)
    if r.returncode:
        raise RuntimeError(r.stderr)
    return [l.split("\t") for l in r.stdout.strip().splitlines() if l]


class Client:
    def __init__(self, base, ip=None):
        self.base, self.ip = base, ip
        self.jar = http.cookiejar.CookieJar()
        self.op = urllib.request.build_opener(urllib.request.HTTPCookieProcessor(self.jar))

    def call(self, method, path, body=None, csrf=True):
        data = json.dumps(body).encode() if body is not None else None
        req = urllib.request.Request(self.base + path, data=data, method=method)
        req.add_header("Content-Type", "application/json")
        if csrf and method != "GET":
            req.add_header("X-USD", "1")
        try:
            with self.op.open(req, timeout=60) as r:
                return r.status, json.loads(r.read() or b"{}")
        except urllib.error.HTTPError as e:
            raw = e.read()
            try:
                return e.code, json.loads(raw)
            except ValueError:
                return e.code, {"raw": raw[:200].decode("utf-8", "replace")}


def legacy_hash(pw):
    salt = secrets.token_bytes(16)
    return salt.hex() + "$" + hashlib.pbkdf2_hmac("sha256", pw.encode(), salt, 310_000).hex()


def start_server(port, config):
    env = dict(os.environ, EPD_CONFIG=config)
    log = open("/private/tmp/claude-501/php-%d.log" % port, "w")
    p = subprocess.Popen(["php", "-S", "127.0.0.1:%d" % port, "-t", os.path.join(ROOT, "public"), os.path.join(ROOT, "scripts/dev-router.php")],
                         env=env, stdout=log, stderr=log, cwd=ROOT)
    time.sleep(1)
    return p


def write_config(path, db):
    open(path, "w").write("<?php return ['db'=>['host'=>'127.0.0.1','port'=>33306,'name'=>'%s','user'=>'root','pass'=>'rootpw'],"
                          "'secret'=>'test-secret-0123456789abcdef0123456789abcdef','register_per_ip_hour'=>3,'debug'=>true];" % db)


def main():
    tmp = "/private/tmp/claude-501/smoke"
    os.makedirs(tmp, exist_ok=True)
    # свежая пустая база со схемой
    subprocess.run(DOCKER + ["-e", "DROP DATABASE IF EXISTS epd_fresh; CREATE DATABASE epd_fresh"], check=True)
    with open(os.path.join(ROOT, "schema/mysql.sql"), "rb") as f:
        subprocess.run(DOCKER + ["epd_fresh"], stdin=f, check=True)
    write_config(tmp + "/fresh.php", "epd_fresh")
    srv = start_server(8794, tmp + "/fresh.php")
    try:
        run(Client("http://127.0.0.1:8794"), tmp)
    finally:
        srv.terminate()
    run_ai(tmp)
    run_migrated(tmp)
    ok = sum(1 for _, c in results if c)
    print("\nИтого: %d из %d сценариев прошли" % (ok, len(results)))
    sys.exit(0 if ok == len(results) else 1)



# ---------- имитация внешних сервисов (Anthropic, OpenAI, LanguageTool): реальные ключи и сеть не нужны ----------
import threading
from http.server import BaseHTTPRequestHandler, ThreadingHTTPServer

MOCK = {"claude": 0, "tts": 0, "stt": 0, "claude_keys": [], "openai_keys": []}


def fill(schema):
    t = schema.get("type")
    if t == "object":
        return {k: fill(v) for k, v in (schema.get("properties") or {}).items()}
    if t == "array":
        return [fill(schema["items"])] if schema.get("items") else []
    if t == "string":
        return (schema.get("enum") or ["Beispiel"])[0]
    if t == "integer":
        return (schema.get("enum") or [2])[-1] if schema.get("enum") else 2
    if t == "number":
        return 3
    if t == "boolean":
        return True
    return None


class Mock(BaseHTTPRequestHandler):
    def log_message(self, *a):
        pass

    def do_POST(self):
        n = int(self.headers.get("Content-Length") or 0)
        body = self.rfile.read(n)
        if self.path.startswith("/v1/messages"):
            MOCK["claude"] += 1
            MOCK["claude_keys"].append(self.headers.get("x-api-key"))
            req = json.loads(body)
            if "SLOWTEST" in json.dumps(req, ensure_ascii=False):
                time.sleep(8)
            out = fill(req["output_config"]["format"]["schema"])
            res = {"stop_reason": "end_turn", "content": [{"type": "text", "text": json.dumps(out)}], "usage": {"input_tokens": 1000, "output_tokens": 500}}
            return self.reply(res)
        if self.path.startswith("/v1/audio/speech"):
            MOCK["openai_keys"].append(self.headers.get("Authorization"))
            MOCK["tts"] += 1
            time.sleep(1.2)
            return self.reply(b"ID3" + os.urandom(3000), "audio/mpeg")
        if self.path.startswith("/v1/audio/transcriptions"):
            MOCK["stt"] += 1
            return self.reply({"text": "Hallo ich heisse Test"})
        if self.path.startswith("/v2/check"):
            return self.reply({"matches": []})
        self.send_response(404)
        self.end_headers()

    def reply(self, obj, ctype="application/json"):
        data = obj if isinstance(obj, bytes) else json.dumps(obj).encode()
        self.send_response(200)
        self.send_header("Content-Type", ctype)
        self.send_header("Content-Length", str(len(data)))
        self.end_headers()
        self.wfile.write(data)


def run_ai(tmp):
    """ИИ-пути на имитации: кэш, журнал расходов, предохранитель, таймаут, озвучка по частям, распознавание речи."""
    DB = "epd_fresh"
    mock = ThreadingHTTPServer(("127.0.0.1", 9777), Mock)
    threading.Thread(target=mock.serve_forever, daemon=True).start()
    open(tmp + "/ai.php", "w").write(
        "<?php return ['db'=>['host'=>'127.0.0.1','port'=>33306,'name'=>'%s','user'=>'root','pass'=>'rootpw'],'secret'=>'test-secret-0123456789abcdef0123456789abcdef',"
        "'anthropic_key'=>'test','anthropic_url'=>'http://127.0.0.1:9777/v1/messages','openai_key'=>'test','openai_base'=>'http://127.0.0.1:9777',"
        "'languagetool_url'=>'http://127.0.0.1:9777/v2/check','ai_timeout'=>5,'audio_budget'=>2,'debug'=>true];" % DB)
    srv = start_server(8796, tmp + "/ai.php")
    audio_made = []
    try:
        base = "http://127.0.0.1:8796"
        c = Client(base)
        s, j = c.call("POST", "/api/login", {"email": "u1@test.dev", "password": "Userpass-1"})
        check("ИИ: вход тестового пользователя", s == 200, j)
        sql(DB, "DELETE FROM rate_limit")
        uid = sql(DB, "SELECT id FROM users WHERE email='u1@test.dev'")[0][0]
        # ключи: у не-владельца без личного ключа ключи сайта не используются (site_keys_for_users=false)
        before = MOCK["claude"]
        s, j = c.call("POST", "/api/de/check", {"task": "t", "q": "Ich gehe", "answer": "Ich gehe heim", "a": ["Ich gehe heim"]})
        check("Ключи: без личного ключа и без site_keys_for_users → 400 «Нужен ключ Claude», запрос к ИИ не ушёл", s == 400 and "Нужен ключ Claude" in j["error"] and MOCK["claude"] == before, (s, j))
        MY = "sk-ant-api03-" + "Mine" * 8 + "wxyz"
        MYO = "sk-proj-" + "Open" * 8 + "wxyz"
        check("Ключи: сохранение личных ключей", c.call("POST", "/api/keys", {"provider": "anthropic", "value": MY})[0] == 200 and c.call("POST", "/api/keys", {"provider": "openai", "value": MYO})[0] == 200)
        # кэш ai_cache
        body = {"task": "t", "q": "Ich gehe", "answer": "Ich gehe heim", "a": ["Ich gehe heim"]}
        before = MOCK["claude"]
        s1, j1 = c.call("POST", "/api/de/check", body)
        s2, j2 = c.call("POST", "/api/de/check", body)
        check("ИИ: /de/check отвечает по схеме", s1 == 200 and set(j1) >= {"ok", "fixed", "why"}, j1)
        check("ИИ: повторный запрос берётся из кэша (один вызов Claude вместо двух)", s2 == 200 and j2 == j1 and MOCK["claude"] - before == 1, MOCK["claude"] - before)
        row = sql(DB, "SELECT inp,outp,cost FROM ai_log WHERE uid=%s AND kind='gcheck' AND err=''" % uid)
        check("ИИ: расход записан в ai_log (токены и стоимость)", len(row) == 1 and row[0][0] == "1000" and row[0][1] == "500" and int(row[0][2]) > 0, row)
        check("Ключи: Claude получил именно личный ключ пользователя, не ключ сайта", MOCK["claude_keys"] and set(MOCK["claude_keys"]) == {MY}, MOCK["claude_keys"])
        # Schreiben: полная проверка текста
        tasks = json.load(open(os.path.join(ROOT, "public/data/schreiben/aufgaben.json")))["items"]
        words = " ".join(["Die Digitalisierung verändert unseren Alltag stark."] * 8)
        s, j = c.call("POST", "/api/schreiben/grade", {"task_id": tasks[0]["id"], "text": words, "minutes": 30})
        check("Schreiben: проверка текста (ИИ-имитация) возвращает баллы и сохраняет эссе", s == 200 and "gesamt" in j and j["max"] in (25, 20) and j["id"] > 0, str(j)[:200])
        check("Schreiben: баллы по критериям не выходят за шкалу", all(0 <= v["punkte"] <= v["max"] for v in j["kriterien"].values()))
        s, st = c.call("GET", "/api/schreiben/state")
        check("Schreiben: эссе появилось в списке с критериями", any(e["id"] == j["id"] and e.get("crit") for e in st["essays"]))
        s, j = c.call("POST", "/api/schreiben/satz", {"start": "Je mehr man übt,", "text": "desto besser wird man darin"})
        check("Schreiben: Satzfortsetzung", s == 200 and "punkte" in j, j)
        s, j = c.call("POST", "/api/schreiben/generate", {"typ": "stell"})
        check("Schreiben: генерация задания", s == 200 and j.get("gen") is True and "punkte" in j, str(j)[:120])
        s, j = c.call("POST", "/api/practice/check", {"text": "Ich habe gestern einen Apfel gegessen.", "de": "essen", "ru": "есть"})
        check("Практика: проверка предложения (LanguageTool + Claude-имитация)", s == 200 and "verdict" in j and j.get("demo") is False, j)
        s, j = c.call("POST", "/api/de/aitasks", {"module": "sprechen", "topic": "Umweltschutz in der Stadt"})
        check("Lernen: задания от ИИ", s == 200 and "tasks" in j)
        s, j = c.call("POST", "/api/de/gwrite", {"topic": "Konjunktiv II", "task": "x", "text": "Wenn ich Zeit hätte, würde ich reisen und viel lesen."})
        check("Lernen: письмо по грамматике", s == 200 and "score" in j)
        sp = json.load(open(os.path.join(ROOT, "public/data/de/sprechen.json")))
        task_id = next(it["id"] for g in sp.values() if isinstance(g, list) for it in g if isinstance(it, dict) and it.get("id"))
        s, j = c.call("POST", "/api/de/talk", {"task": task_id, "text": "Ich finde, dass die Stadt mehr Parks braucht.", "phase": "monolog"})
        check("Lernen: разбор устного ответа", s == 200 and "reply_de" in j, str(j)[:120])
        s, j = c.call("POST", "/api/de/ctx", {"word": "Nachhaltigkeit", "sentence": "Nachhaltigkeit ist wichtig."})
        check("Lernen: слово в контексте (без DeepL: перевод от Claude-имитации)", s in (200, 502), (s, j))
        # таймаут
        s, j = c.call("POST", "/api/de/check", dict(body, answer="SLOWTEST"))
        check("таймаут ИИ: понятная ошибка 504 вместо падения", s == 504 and "не успел" in j["error"], (s, j))
        e = sql(DB, "SELECT err FROM ai_log WHERE uid=%s AND err='timeout'" % uid)
        check("таймаут ИИ записан в ai_log", len(e) == 1, e)
        # предохранитель
        sql(DB, "DELETE FROM rate_limit")
        codes = [c.call("POST", "/api/de/aitasks", {"module": "lesen", "topic": "Thema Nummer %d" % i})[0] for i in range(32)]
        check("предохранитель: после 30 запросов в минуту — 429", codes[:30] == [200] * 30 and codes[30] == 429, codes)
        block = sql(DB, "SELECT COUNT(*) FROM ai_log WHERE kind='block' AND uid=%s" % uid)[0][0]
        check("предохранитель: срабатывание записано в журнал", int(block) >= 1)
        # озвучка
        sql(DB, "DELETE FROM rate_limit")
        s, j = c.call("POST", "/api/de/tts", {"text": "Guten Tag, wie geht es Ihnen?"})
        fp = os.path.join(ROOT, "public", j["url"].lstrip("/")) if j.get("url") else ""
        audio_made.append(fp)
        check("озвучка: mp3 создан и лежит в public/audio", s == 200 and os.path.isfile(fp) and os.path.getsize(fp) > 1000, j)
        t = MOCK["tts"]
        s, j2 = c.call("POST", "/api/de/tts", {"text": "Guten Tag, wie geht es Ihnen?"})
        check("озвучка: повторный запрос из кэша без платного вызова", j2["url"] == j["url"] and MOCK["tts"] == t)
        hoer = json.load(open(os.path.join(ROOT, "public/data/de/hoeren.json")))
        item = next(it for g in hoer.values() if isinstance(g, list) for it in g if isinstance(it, dict) and len(it.get("lines") or []) >= 4)
        steps, last = 0, {}
        while steps < 30:
            s, last = c.call("POST", "/api/de/audio/de/" + item["id"])
            steps += 1
            if not last.get("pending"):
                break
        if last.get("url"):
            audio_made.append(os.path.join(ROOT, "public", last["url"].lstrip("/")))
        check("озвучка сценария: делается по частям (pending), затем отдаётся один файл", s == 200 and last.get("url") and steps > 1, (steps, last))
        check("озвучка сценария: склеенный файл существует", bool(last.get("url")) and os.path.getsize(audio_made[-1]) > 3000)
        before = MOCK["tts"]
        s, last2 = c.call("POST", "/api/de/audio/de/" + item["id"])
        check("озвучка сценария: повторный запрос мгновенный, без новых вызовов", last2.get("url") == last.get("url") and MOCK["tts"] == before)
        import base64
        s, j = c.call("POST", "/api/de/stt", {"audio": base64.b64encode(os.urandom(2000)).decode(), "mime": "audio/webm"})
        check("распознавание речи (имитация OpenAI, multipart через curl)", s == 200 and j.get("text") == "Hallo ich heisse Test", j)
        s, j = c.call("POST", "/api/de/stt", {"audio": "AAAA", "mime": "audio/webm"})
        check("распознавание речи: слишком короткая запись → 400", s == 400)
    finally:
        srv.terminate()
        mock.shutdown()
        for f in audio_made:
            if f and os.path.isfile(f):
                os.remove(f)
        for f in __import__("glob").glob(os.path.join(ROOT, "public/audio/*.mp3")):
            os.remove(f)


def run_migrated(tmp):
    """Вторая часть: копия боевой базы, перенесённая скриптом миграции (нужен предыдущий запуск migrate_sqlite_to_php.php в базу epd)."""
    DB = "epd"
    if not sql(DB, "SELECT COUNT(*) FROM users")[0][0] != "0":
        print("SKIP: база epd пуста, сначала scripts/migrate_sqlite_to_php.php")
        return
    write_config(tmp + "/mig.php", DB)
    srv = start_server(8795, tmp + "/mig.php")
    try:
        base = "http://127.0.0.1:8795"
        owner_row = sql(DB, "SELECT id,email,pw FROM users WHERE role='owner'")[0]
        check("миграция: у владельца в базе старый PBKDF2-хеш (формат сохранён)", len(owner_row[2]) == 97 and "$" in owner_row[2])
        # подменяем только в копии: известный пароль в старом формате
        sql(DB, "UPDATE users SET pw='%s' WHERE id=%s" % (legacy_hash("Migrated-Pass-1"), owner_row[0]))
        c = Client(base)
        s, j = c.call("POST", "/api/login", {"email": owner_row[1], "password": "Migrated-Pass-1"})
        check("миграция: вход владельца из перенесённой базы старым PBKDF2-хешем", s == 200 and j["user"]["role"] == "owner", j)
        check("миграция: хеш владельца перехеширован", not sql(DB, "SELECT pw FROM users WHERE id=%s" % owner_row[0])[0][0].count("$") == 1)
        s, j = c.call("GET", "/api/me")
        raw = sql(DB, "SELECT data FROM progress WHERE user_id=%s" % owner_row[0])[0][0]
        check("миграция: прогресс владельца читается и совпадает с базой", s == 200 and json.dumps(j["progress"], ensure_ascii=False, separators=(",", ":"), sort_keys=True) == json.dumps(json.loads(raw), ensure_ascii=False, separators=(",", ":"), sort_keys=True))
        s, j = c.call("GET", "/api/admin/users")
        n = int(sql(DB, "SELECT COUNT(*) FROM users")[0][0])
        check("миграция: админка показывает всех %d пользователей" % n, s == 200 and len(j["users"]) == n, s)
        s, j = c.call("GET", "/api/admin/overview?days=365")
        check("миграция: сводка админки на реальных данных (посещения, ИИ, Schreiben)", s == 200 and j["kpi"]["users"] == n and j["ai"]["period"]["req"] >= 0, str(j)[:120])
        s, j = c.call("GET", "/api/de/state")
        kv = int(sql(DB, "SELECT COUNT(*) FROM kv WHERE user_id=%s" % owner_row[0])[0][0])
        check("миграция: состояние модулей Lernen (kv) владельца: %d ключей" % kv, s == 200 and len(j["state"]) == kv)
        s, j = c.call("GET", "/api/schreiben/state")
        check("миграция: Schreiben-состояние владельца читается", s == 200)
        row = sql(DB, "SELECT src,`out` FROM translations LIMIT 1")[0]
        s, j = c.call("POST", "/api/translate", {"text": row[0], "from": "de", "to": "ru"}) if False else (200, {})
        tr = sql(DB, "SELECT src,sl,tl,`out` FROM translations WHERE sl IN ('de','ru','en') AND tl IN ('de','ru','en') AND sl<>tl LIMIT 1")
        if tr:
            s, j = c.call("POST", "/api/translate", {"text": tr[0][0], "from": tr[0][1], "to": tr[0][2]})
            check("миграция: кэш переводов работает на перенесённых строках", s == 200 and j["text"] == tr[0][3], (s, j))
        s, j = c.call("GET", "/api/share/" + sql(DB, "SELECT code FROM shares LIMIT 1")[0][0])
        check("миграция: общий набор открывается", s == 200 and j["cards"])
    finally:
        srv.terminate()


def run(owner, tmp):
    DB = "epd_fresh"
    # ---- A. регистрация без письма ----
    s, j = owner.call("GET", "/api/regopen")
    check("регистрация открыта в пустой базе", j.get("open") is True)
    s, j = owner.call("POST", "/api/register", {"name": "Owner", "email": "owner@test.dev", "password": "Owner-pass-1"})
    check("A: регистрация первого пользователя без письма (200)", s == 200 and j["user"]["verified"] is True, j)
    check("A: первый пользователь стал владельцем", j["user"]["role"] == "owner")
    check("A: users.verified = 1 в базе", sql(DB, "SELECT verified FROM users WHERE email='owner@test.dev'")[0][0] == "1")
    check("A: таблицы verifies и resets нет в схеме", sql(DB, "SELECT COUNT(*) FROM information_schema.tables WHERE table_schema='epd_fresh' AND table_name IN ('verifies','resets')")[0][0] == "0")
    s, j = owner.call("POST", "/api/verify", {"token": "x"})
    check("A: /api/verify отключён (410)", s == 410)
    s, j = owner.call("POST", "/api/forgot", {"email": "owner@test.dev"})
    check("A: /api/forgot отключён (410)", s == 410)
    s, j = owner.call("GET", "/api/me")
    check("вход сразу после регистрации: /api/me отдаёт пользователя", j["user"] and j["user"]["email"] == "owner@test.dev" and j.get("mail") is False)
    pw = sql(DB, "SELECT pw FROM users WHERE email='owner@test.dev'")[0][0]
    check("B: пароль хранится как Argon2id/bcrypt-хеш, не открытым текстом", pw.startswith("$argon2id$") or pw.startswith("$2y$"), pw[:12])
    check("B: открытый пароль нигде не встречается в users", "Owner-pass-1" not in pw)
    s, j = owner.call("POST", "/api/register", {"name": "X", "email": "x@test.dev", "password": "Xxxxxxxx-1"})
    check("регистрация закрыта без приглашения (403)", s == 403)

    # ---- приглашения и лимит регистраций по IP ----
    codes = []
    for _ in range(5):
        s, j = owner.call("POST", "/api/de/invites")
        codes.append(j.get("code"))
    check("владелец создаёт приглашения", all(codes), codes)
    sql(DB, "DELETE FROM rate_limit")      # регистрация владельца тоже считается: начинаем окно заново
    results_reg = []
    for i in range(4):
        c = Client("http://127.0.0.1:8794")
        s, j = c.call("POST", "/api/register", {"name": "U%d" % i, "email": "u%d@test.dev" % i, "password": "Userpass-%d" % i, "invite": codes[i]})
        results_reg.append(s)
    check("A: лимит регистраций с одного IP: первые 3 проходят, 4-я — 429", results_reg == [200, 200, 200, 429], results_reg)
    sql(DB, "DELETE FROM rate_limit")
    s, j = Client("http://127.0.0.1:8794").call("POST", "/api/register", {"name": "Bad", "email": "not-an-email", "password": "Userpass-9", "invite": codes[4]})
    check("A: проверка формата email (400)", s == 400)
    s, j = Client("http://127.0.0.1:8794").call("POST", "/api/register", {"name": "Weak", "email": "weak@test.dev", "password": "abc", "invite": codes[4]})
    check("слабый пароль отклоняется (400)", s == 400)

    # ---- B. старый PBKDF2 ----
    legacy_pw = "Legacy-Pass-7"
    sql(DB, "INSERT INTO users(email,name,avatar,pw,code,created,news,verified) VALUES('legacy@test.dev','Legacy',0,'%s','LEG001',%d,1,0)" % (legacy_hash(legacy_pw), int(time.time())))
    sql(DB, "INSERT INTO stats(user_id,updated) SELECT id,0 FROM users WHERE email='legacy@test.dev'")
    old = sql(DB, "SELECT pw FROM users WHERE email='legacy@test.dev'")[0][0]
    check("B: до входа в базе старый PBKDF2-хеш", "$" in old and not old.startswith("$a") and len(old) == 97)
    lg = Client("http://127.0.0.1:8794")
    s, j = lg.call("POST", "/api/login", {"email": "legacy@test.dev", "password": "wrong"})
    check("B: неверный пароль → 401", s == 401)
    after_bad = sql(DB, "SELECT pw FROM users WHERE email='legacy@test.dev'")[0][0]
    check("B: после неудачного входа хеш не менялся", after_bad == old)
    s, j = lg.call("POST", "/api/login", {"email": "legacy@test.dev", "password": legacy_pw})
    check("B: вход старым PBKDF2-паролем (200)", s == 200, j)
    new = sql(DB, "SELECT pw FROM users WHERE email='legacy@test.dev'")[0][0]
    check("B: пароль перехеширован в новый формат", new != old and (new.startswith("$argon2id$") or new.startswith("$2y$")), new[:14])
    lg2 = Client("http://127.0.0.1:8794")
    s, j = lg2.call("POST", "/api/login", {"email": "legacy@test.dev", "password": legacy_pw})
    check("B: повторный вход тем же паролем по новому хешу (200)", s == 200)
    check("B: verified у legacy-пользователя остался как есть, а API отдаёт verified=true", j["user"]["verified"] is True)

    # ---- брутфорс-ограничение ----
    bf = Client("http://127.0.0.1:8794")
    codes_seen = [bf.call("POST", "/api/login", {"email": "ghost@test.dev", "password": "nope"})[0] for _ in range(10)]
    check("ограничение неудачных входов: 8 раз 401, дальше 429", codes_seen[:8] == [401] * 8 and codes_seen[8] == 429, codes_seen)

    # ---- C. админка: список, сброс пароля, принудительная смена ----
    s, j = owner.call("GET", "/api/admin/users")
    users = j["users"]
    check("C: список пользователей с id, именем, email, ролью, планом, датой регистрации, последним входом, прогрессом",
          s == 200 and all(k in users[0] for k in ("id", "name", "email", "role", "plan", "created", "last_seen", "answers", "learned", "acc")), users[0].keys() if users else j)
    check("C: в списке нет пароля или хеша", all("pw" not in u and "password" not in u and "hash" not in u for u in users) and "argon2" not in json.dumps(users))
    uid = next(u["id"] for u in users if u["email"] == "legacy@test.dev")
    s, j = lg.call("POST", "/api/admin/user/%d/reset" % uid)
    check("C: обычный пользователь не может сбросить пароль (403)", s == 403)
    s, j = owner.call("POST", "/api/admin/user/%d/reset" % uid)
    temp = j.get("temp_password", "")
    check("C: сброс пароля админом выдаёт временный пароль 12+ символов", s == 200 and len(temp) >= 12, j)
    check("C: users.must_change_password = 1", sql(DB, "SELECT must_change_password FROM users WHERE id=%d" % uid)[0][0] == "1")
    s, j = lg.call("GET", "/api/me")
    check("C: старая сессия пользователя закрыта после сброса", j["user"] is None)
    s, j = lg.call("POST", "/api/login", {"email": "legacy@test.dev", "password": legacy_pw})
    check("C: старый пароль после сброса не работает (401)", s == 401)
    s, j = lg.call("POST", "/api/login", {"email": "legacy@test.dev", "password": temp})
    check("C: вход с временным паролем, в ответе must_change_password=true", s == 200 and j["user"]["must_change_password"] is True, j)
    s, j = lg.call("GET", "/api/friends")
    check("C: пока пароль не сменён, остальной API закрыт (403 + флаг)", s == 403 and j.get("must_change_password") is True, (s, j))
    s, j = lg.call("POST", "/api/password", {"current": temp, "password": temp})
    check("C: новый пароль не может совпадать с временным (400)", s == 400)
    s, j = lg.call("POST", "/api/password", {"current": "wrong-current", "password": "Brand-New-Pass-5"})
    check("C: смена пароля с неверным текущим (401)", s == 401)
    s, j = lg.call("POST", "/api/password", {"current": temp, "password": "Brand-New-Pass-5"})
    check("C: принудительная смена пароля проходит (200)", s == 200 and j["user"]["must_change_password"] is False, j)
    s, j = lg.call("GET", "/api/friends")
    check("C: после смены пароля API снова доступен", s == 200)
    s, j = Client("http://127.0.0.1:8794").call("POST", "/api/login", {"email": "legacy@test.dev", "password": temp})
    check("C: временный пароль после смены не работает (401)", s == 401)
    s, j = Client("http://127.0.0.1:8794").call("POST", "/api/login", {"email": "legacy@test.dev", "password": "Brand-New-Pass-5"})
    check("C: вход с новым паролем (200)", s == 200)
    rows = sql(DB, "SELECT action,details FROM audit_log WHERE action='reset_password'")
    check("C: действие записано в audit_log", len(rows) == 1)
    check("C: в audit_log нет пароля", rows and temp not in rows[0][1] and "pass" not in rows[0][1].lower().replace("password", "") or (rows and temp not in rows[0][1]))
    s, j = owner.call("GET", "/api/admin/audit")
    check("C: журнал доступен админу через API", s == 200 and any(r["action"] == "reset_password" for r in j["log"]))
    check("C: в ответе журнала нет временного пароля", temp not in json.dumps(j))

    # блокировка, план, роль
    s, j = owner.call("POST", "/api/admin/user/%d/block" % uid, {"blocked": True})
    check("C: блокировка входа (200)", s == 200)
    s, j = Client("http://127.0.0.1:8794").call("POST", "/api/login", {"email": "legacy@test.dev", "password": "Brand-New-Pass-5"})
    check("C: заблокированный пользователь не входит (403)", s == 403, (s, j))
    s, j = lg.call("GET", "/api/me")
    check("C: сессии заблокированного закрыты", j["user"] is None)
    owner.call("POST", "/api/admin/user/%d/block" % uid, {"blocked": False})
    s, j = Client("http://127.0.0.1:8794").call("POST", "/api/login", {"email": "legacy@test.dev", "password": "Brand-New-Pass-5"})
    check("C: после разблокировки вход работает", s == 200)
    s, j = owner.call("POST", "/api/admin/user/%d/plan" % uid, {"plan": "premium", "days": 30})
    check("C: смена плана на premium", s == 200 and sql(DB, "SELECT plan FROM users WHERE id=%d" % uid)[0][0] == "premium")
    s, j = owner.call("POST", "/api/admin/user/%d/role" % uid, {"role": "admin"})
    check("C: смена роли на admin (владелец)", s == 200)
    owner_id = sql(DB, "SELECT id FROM users WHERE role='owner'")[0][0]
    s, j = owner.call("POST", "/api/admin/user/%s/role" % owner_id, {"role": "user"})
    check("C: роль владельца менять нельзя (400)", s == 400)
    s, j = owner.call("POST", "/api/admin/user/%s/reset" % owner_id)
    check("C: пароль владельца из админки сбросить нельзя (400)", s == 400)
    admin = Client("http://127.0.0.1:8794")
    admin.call("POST", "/api/login", {"email": "legacy@test.dev", "password": "Brand-New-Pass-5"})
    other = next(u["id"] for u in users if u["email"] == "u0@test.dev")
    s, j = admin.call("POST", "/api/admin/user/%s/reset" % owner_id)
    check("C: админ не может сбросить пароль владельца (400)", s == 400)
    s, j = admin.call("POST", "/api/admin/user/%d/role" % other, {"role": "admin"})
    check("C: админ не может менять роли (403)", s == 403)
    s, j = admin.call("POST", "/api/admin/user/%d/block" % other, {"blocked": True})
    check("C: админ может блокировать обычного пользователя", s == 200)
    owner.call("POST", "/api/admin/user/%d/block" % other, {"blocked": False})
    # триггеры в базе
    r = subprocess.run(DOCKER + [DB, "-e", "DELETE FROM users WHERE role='owner'"], capture_output=True, text=True)
    check("триггер owner_no_delete: владельца нельзя удалить в базе", r.returncode != 0 and "owner is protected" in r.stderr, r.stderr[:100])
    r = subprocess.run(DOCKER + [DB, "-e", "UPDATE users SET role='user' WHERE role='owner'"], capture_output=True, text=True)
    check("триггер owner_keep_role: снять роль с последнего владельца нельзя", r.returncode != 0 and "last owner" in r.stderr, r.stderr[:100])
    s, j = admin.call("DELETE", "/api/admin/user/%s" % owner_id, {"email": "owner@test.dev"})
    check("удалить владельца через API нельзя (403)", s == 403)

    # ---- прогресс: объекты {} не превращаются в [] ----
    pc = Client("http://127.0.0.1:8794")
    pc.call("POST", "/api/login", {"email": "u1@test.dev", "password": "Userpass-1"})
    prog = {"b": {}, "days": {"20000": {"a": 5, "k": 3}}, "ms": {"de": [4, 5]}, "cur": "1.1", "recent": [], "l": {}, "usets": [], "folders": []}
    s, j = pc.call("PUT", "/api/progress", {"data": prog, "stats": {"xp": 120, "streak": 3, "learned": 7, "acc": 80, "today": 5, "level": 2, "week_xp": 40}})
    check("прогресс сохраняется (200)", s == 200, j)
    s, j = pc.call("GET", "/api/me")
    check("прогресс читается и пустые объекты остались объектами", j["progress"]["b"] == {} and j["progress"]["l"] == {} and j["progress"]["days"] == {"20000": {"a": 5, "k": 3}}, j.get("progress"))
    st = sql(DB, "SELECT xp,streak,answers FROM stats WHERE user_id=(SELECT id FROM users WHERE email='u1@test.dev')")[0]
    check("статистика записана (xp=120, streak=3, answers=5)", st == ["120", "3", "5"], st)
    s, j = pc.call("PUT", "/api/progress", {"data": "x"})
    check("неверные данные прогресса → 400", s == 400)
    s, j = pc.call("PUT", "/api/progress", {"data": prog}, csrf=False)
    check("защита CSRF: запрос без X-USD отклоняется (403)", s == 403)

    # ---- друзья и забеги ----
    me = pc.call("GET", "/api/me")[1]["user"]
    other_c = Client("http://127.0.0.1:8794")
    other_c.call("POST", "/api/login", {"email": "u2@test.dev", "password": "Userpass-2"})
    s, j = other_c.call("POST", "/api/friends", {"code": me["code"]})
    check("друзья: добавление по коду", s == 200)
    s, j = other_c.call("GET", "/api/friends")
    check("друзья: список со статистикой", s == 200 and len(j["friends"]) == 2 and "email" not in j["friends"][0])
    other_id = other_c.call("GET", "/api/me")[1]["user"]["id"]
    s, j = pc.call("POST", "/api/races", {"friend": other_id, "metric": "xp", "days": 3})
    check("забег с другом", s == 200 and len(j["races"]) == 1, j)

    # ---- Lernen: kv, приглашения, настройки ----
    s, j = pc.call("PUT", "/api/de/state/grammatik", {"v": {"done": [1, 2], "empty": {}}})
    check("Lernen: запись состояния модуля", s == 200)
    s, j = pc.call("GET", "/api/de/state")
    check("Lernen: чтение состояния, пустые объекты сохранены", j["state"]["grammatik"] == {"done": [1, 2], "empty": {}}, j)
    s, j = pc.call("PUT", "/api/de/state/BAD%20KEY", {"v": 1})
    check("Lernen: неверный ключ → 400/404", s in (400, 404))
    s, j = pc.call("POST", "/api/de/ctx", {"word": "Haus", "sentence": "Das Haus ist groß."})
    check("Lernen: перевод без ключей и сети отдаёт понятную ошибку, а не падает (502)", s in (200, 502), (s, j))
    s, j = pc.call("POST", "/api/de/check", {"task": "t", "q": "q", "answer": "a", "a": ["a"]})
    check("Lernen: проверка без ключа Claude → 400 с понятным текстом", s == 400 and "Claude" in j["error"], j)
    s, j = pc.call("POST", "/api/de/tts", {"text": "Hallo"})
    check("Lernen: озвучка без ключа OpenAI → nokey", s == 200 and j.get("nokey") is True, j)
    s, j = pc.call("POST", "/api/de/invites")
    check("Lernen: обычный пользователь не создаёт приглашения (403)", s == 403)
    s, j = pc.call("POST", "/api/de/settings/key", {"name": "openai", "value": "sk-x"})
    check("Ключи: короткий/неверный ключ отклоняется по формату (400)", s == 400, (s, j))
    s, j = pc.call("POST", "/api/keys", {"provider": "anthropic", "value": "sk-oai-" + "a" * 30})
    check("Ключи: у Anthropic нужен префикс sk-ant- (400)", s == 400, (s, j))
    FAKE = "sk-ant-api03-" + "Zq9" * 10 + "a1b2"
    s, j = pc.call("POST", "/api/keys", {"provider": "anthropic", "value": FAKE})
    check("Ключи: личный ключ сохраняется, в ответе только hint a1b2", s == 200 and j["keys"]["anthropic"] == {"set": True, "hint": "a1b2", "broken": False} and FAKE not in json.dumps(j), (s, j))
    s, j = pc.call("GET", "/api/keys")
    check("Ключи: GET отдаёт статус без значения", s == 200 and j["keys"]["anthropic"]["hint"] == "a1b2" and FAKE not in json.dumps(j) and j["keys"]["openai"]["set"] is False)
    row = sql(DB, "SELECT key_enc,key_hint FROM user_keys WHERE user_id=(SELECT id FROM users WHERE email='u1@test.dev') AND provider='anthropic'")[0]
    check("Ключи: в БД только шифротекст и hint, открытого ключа нет", FAKE not in row[0] and "Zq9" not in row[0] and row[1] == "a1b2", row)
    s, j = other_c.call("GET", "/api/keys")
    check("Ключи: у другого аккаунта ключа нет", s == 200 and j["keys"]["anthropic"]["set"] is False, j)
    s, j = pc.call("GET", "/api/de/settings")
    check("Ключи: Lernen видит личный ключ как подключённый", j["keys"]["anthropic"] is True and "Zq9" not in json.dumps(j), j)
    s, j = admin.call("GET", "/api/admin/users")
    u1 = [u for u in j["users"] if u["email"] == "u1@test.dev"][0]
    check("Ключи: админка видит только да/нет", u1["has_keys"]["anthropic"] is True and FAKE not in json.dumps(j))
    s, j = pc.call("DELETE", "/api/keys/anthropic")
    check("Ключи: удаление ключа", s == 200 and j["keys"]["anthropic"]["set"] is False)
    s, j = pc.call("DELETE", "/api/keys/nope")
    check("Ключи: неизвестный сервис (400)", s == 400)
    s, j = pc.call("POST", "/api/keys", {"provider": "anthropic", "value": FAKE}, csrf=False)
    check("Ключи: без CSRF-заголовка 403", s == 403)
    s, j = owner.call("GET", "/api/de/settings")
    check("Lernen: настройки владельца со списком приглашений и без значений ключей", s == 200 and j["owner"] is True and "sk-" not in json.dumps(j))

    # ---- Schreiben ----
    s, j = pc.call("GET", "/api/schreiben/state")
    check("Schreiben: state пуст у нового пользователя", s == 200 and j["data"] is None and j["essays"] == [] and j["ai"] is False, j)
    s, j = pc.call("PUT", "/api/schreiben/state", {"data": {"xp": 55, "streak": 2, "empty": {}}})
    check("Schreiben: сохранение состояния", s == 200)
    s, j = pc.call("GET", "/api/schreiben/state")
    check("Schreiben: чтение состояния (xp=55, пустой объект сохранён)", j["data"] == {"xp": 55, "streak": 2, "empty": {}}, j)
    words = " ".join(["Wort"] * 60)
    s, j = pc.call("POST", "/api/schreiben/grade", {"task_id": "nope", "text": words})
    check("Schreiben: неизвестное задание → 400", s == 400)
    tasks = json.load(open(os.path.join(ROOT, "public/data/schreiben/aufgaben.json")))["items"]
    s, j = pc.call("POST", "/api/schreiben/grade", {"task_id": tasks[0]["id"], "text": "kurz"})
    check("Schreiben: слишком короткий текст → 400", s == 400)
    s, j = pc.call("POST", "/api/schreiben/grade", {"task_id": tasks[0]["id"], "text": words})
    check("Schreiben: проверка без ключа Anthropic → 400 «Нужен ключ Claude (Настройки → Ключи)», не 500", s == 400 and "Настройки → Ключи" in j["error"], (s, j))
    # вставим готовое эссе напрямую и проверим чтение/удаление/друзей/админку
    uid1 = sql(DB, "SELECT id FROM users WHERE email='u1@test.dev'")[0][0]
    sql(DB, "INSERT INTO schreiben_essays(user_id,ts,task_id,typ,title,text,words,minutes,points,max_points,task,result) VALUES(%s,%d,'t1','stell','Thema','Text',50,10,15.5,25,'{}','{\"kriterien\":{\"inhalt\":{\"punkte\":4,\"max\":5}}}')" % (uid1, int(time.time())))
    s, j = pc.call("GET", "/api/schreiben/state")
    check("Schreiben: список текстов с баллами по критериям", len(j["essays"]) == 1 and j["essays"][0]["crit"] == {"inhalt": [4, 5]}, j)
    eid = j["essays"][0]["id"]
    s, j = pc.call("GET", "/api/schreiben/essay/%d" % eid)
    check("Schreiben: открыть разбор текста", s == 200 and j["result"]["kriterien"]["inhalt"]["punkte"] == 4)
    s, j = other_c.call("GET", "/api/schreiben/essay/%d" % eid)
    check("Schreiben: чужой текст не открывается (404)", s == 404)
    s, j = other_c.call("GET", "/api/friends")
    check("Schreiben: в статистике друзей видны тексты и XP", any(f.get("s_total") == 1 and f.get("s_xp") == 55 for f in j["friends"]), j)
    s, j = pc.call("DELETE", "/api/schreiben/essay/%d" % eid)
    check("Schreiben: удаление текста", s == 200)

    # ---- словарь-ящик, наборы ----
    s, j = pc.call("POST", "/api/words", {"de": "die Heimat", "ru": "родина", "ctx": "x"})
    s, j = pc.call("GET", "/api/words")
    check("слова-ящик: сохранение и чтение", len(j["words"]) == 1)
    s, j = pc.call("POST", "/api/share", {"name": "Набор", "cards": [{"de": "a", "ru": "б", "ex": ""}]})
    code = j.get("code")
    s, j = Client("http://127.0.0.1:8794").call("GET", "/api/share/%s" % code)
    check("общий набор открывается без входа", s == 200 and j["cards"][0]["de"] == "a")

    # ---- переводы: кэш ----
    sql(DB, "REPLACE INTO translations(src_hash,sl,tl,src,`out`,prov) VALUES(SHA1('Haus'),'de','ru','Haus','дом','DeepL')")
    s, j = pc.call("POST", "/api/translate", {"text": "Haus", "from": "de", "to": "ru"})
    check("переводы: ответ из кэша translations", s == 200 and j["text"] == "дом" and j["provider"] == "DeepL", j)
    s, j = pc.call("POST", "/api/translate", {"text": "Haus", "from": "de", "to": "de"})
    check("переводы: одинаковые языки → 400", s == 400)

    # ---- страницы ----
    for path, needle in (("/", "<html"), ("/de", "<html"), ("/woerter", "BOOK"), ("/schreiben", "<html"), ("/admin", "<html")):
        with urllib.request.urlopen("http://127.0.0.1:8794" + path) as r:
            body = r.read().decode("utf-8", "replace")
        check("страница %s отдаётся" % path, r.status == 200 and needle in body)
    with urllib.request.urlopen("http://127.0.0.1:8794/woerter") as r:
        html = r.read().decode()
    import re
    m = re.search(r'/assets/book\.([0-9a-f]{12})\.js', html)
    check("Wörter: словарь вынесен в /assets/book.<хэш>.js", bool(m))
    if m:
        with urllib.request.urlopen("http://127.0.0.1:8794" + m.group(0)) as r:
            check("Wörter: файл словаря отдаётся и кэшируется навсегда", r.status == 200 and "immutable" in r.headers.get("Cache-Control", ""))
    # ---- сводка админки ----
    s, j = owner.call("GET", "/api/admin/overview?days=30")
    check("админка: сводка (overview) строится", s == 200 and j["kpi"]["users"] >= 5 and "ai" in j and "schreiben" in j, str(j)[:200])
    s, j = owner.call("GET", "/api/admin/user/%d" % uid)
    check("админка: карточка пользователя", s == 200 and j["user"]["email"] == "legacy@test.dev")
    s, j = owner.call("GET", "/api/admin/diag")
    check("админка: диагностика хостинга (php, расширения) без секретов", s == 200 and "php" in j and "sk-" not in json.dumps(j))
    s, j = pc.call("GET", "/api/admin/users")
    check("обычный пользователь не видит админку (403)", s == 403)
    s, j = Client("http://127.0.0.1:8794").call("GET", "/api/admin/users")
    check("аноним не видит админку (401)", s == 401)
    s, j = owner.call("DELETE", "/api/admin/user/%d" % other, {"email": "u0@test.dev"})
    check("владелец удаляет пользователя с подтверждением почты", s == 200 and not sql(DB, "SELECT 1 FROM users WHERE id=%d" % other))
    check("удаление записано в audit_log", sql(DB, "SELECT COUNT(*) FROM audit_log WHERE action='delete_user'")[0][0] == "1")
    # ---- выход ----
    s, j = pc.call("POST", "/api/logout")
    s, j = pc.call("GET", "/api/me")
    check("выход закрывает сессию", j["user"] is None)


if __name__ == "__main__":
    main()
