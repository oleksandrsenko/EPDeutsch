#!/usr/bin/env python3
"""EPDeutsch: сервер аккаунтов, синхронизации прогресса, друзей и общих наборов.

Запуск:  python3 server.py [порт]      (по умолчанию 8765, можно задать PORT=…)
Открыть: http://localhost:8765
Другая база: UNISICHER_DB=/путь/к/файлу.db python3 server.py

Переводчик для редактора карточек:
  • DeepL API Free (лучшее качество): ключ с https://www.deepl.com/pro-api —
    положи его в файл db/deepl.key или задай DEEPL_API_KEY=… при запуске;
  • без ключа — бесплатный MyMemory (https://mymemory.translated.net), качество ниже.

На хостинге задай адрес сайта для поисковиков (canonical, sitemap.xml, картинка для соцсетей):
  SITE_URL=https://твой-домен python3 server.py
Без него адрес берётся из заголовка Host запроса.

Страница отдаётся сжатой (gzip) с ETag; словарь (BOOK) вынесен в /assets/book.<хэш>.js и кэшируется
браузером навсегда. Иконки и картинка для соцсетей — в папке static/.

Письма (восстановление пароля) уходят через SMTP, если он настроен:
  SMTP_HOST=smtp.… SMTP_PORT=587 SMTP_USER=… SMTP_PASS=… MAIL_FROM="EPDeutsch <noreply@…>"
  или файл db/smtp.json с теми же ключами (host, port, user, pass, from).
Без SMTP ссылка для сброса пароля печатается в консоль сервера (удобно для проверки на своём компьютере).
Ссылку в письме строим от SITE_URL; без него письма отправляются только при открытии через localhost.

Только стандартная библиотека Python. База — db/unisicher.db (SQLite).
Пароли хранятся как PBKDF2-SHA256 с солью, сессии — как SHA-256 от токена.
"""
import gzip, hashlib, hmac, json, os, re, secrets, smtplib, sqlite3, ssl, sys, threading, time, urllib.parse, urllib.request
from email.message import EmailMessage
from http.cookies import SimpleCookie
from http.server import BaseHTTPRequestHandler, ThreadingHTTPServer
from urllib.parse import urlparse

ROOT = os.path.dirname(os.path.abspath(__file__))
DB = os.environ.get("UNISICHER_DB") or os.path.join(ROOT, "db", "unisicher.db")
PORT = int(sys.argv[1]) if len(sys.argv) > 1 else int(os.environ.get("PORT", 8765))
SESSION_DAYS = 30
MAX_BODY = 5 * 1024 * 1024
PBKDF2_ITER = 310_000
EMAIL_RE = re.compile(r"^[^@\s]{1,64}@[^@\s]{1,190}\.[^@\s]{2,}$")
CODE_ABC = "ABCDEFGHJKLMNPQRSTUVWXYZ23456789"
KEY_FILE = os.path.join(ROOT, "db", "deepl.key")
# за HTTPS-прокси (Caddy/nginx) на хостинге: PROXY=1 — брать IP из X-Forwarded-For и ставить cookie с Secure
PROXY = os.environ.get("PROXY") == "1"
OWNER_FILE = os.path.join(ROOT, "db", "owner.txt")
SMTP_FILE = os.path.join(ROOT, "db", "smtp.json")
RESET_MINUTES = 60


def owner_emails():
    """Почты создателя сайта (метка «Owner»): OWNER_EMAILS=a@b,c@d или файл db/owner.txt, по одной на строку."""
    v = os.environ.get("OWNER_EMAILS", "")
    if not v and os.path.exists(OWNER_FILE):
        with open(OWNER_FILE, encoding="utf-8") as f:
            v = f.read()
    return {e.strip().lower() for e in re.split(r"[,\s]+", v) if e.strip()}


def ensure_owner(c):
    """Если в базе ещё нет владельца, им становится аккаунт с почтой из OWNER_EMAILS / db/owner.txt.
    Дальше владелец меняется только передачей прав в админке (файл больше ни на что не влияет)."""
    if c.execute("SELECT 1 FROM users WHERE role='owner'").fetchone():
        return
    for e in owner_emails():
        if c.execute("UPDATE users SET role='owner' WHERE lower(email)=?", (e,)).rowcount:
            return


def deepl_key():
    k = os.environ.get("DEEPL_API_KEY", "").strip()
    if not k and os.path.exists(KEY_FILE):
        with open(KEY_FILE, encoding="utf-8") as f:
            k = f.read().strip()
    return k

SCHEMA = """
CREATE TABLE IF NOT EXISTS users(
  id INTEGER PRIMARY KEY,
  email TEXT UNIQUE NOT NULL,
  name TEXT NOT NULL,
  avatar INTEGER NOT NULL DEFAULT 0,
  pw TEXT NOT NULL,
  code TEXT UNIQUE NOT NULL,
  created INTEGER NOT NULL
);
CREATE TABLE IF NOT EXISTS sessions(
  token TEXT PRIMARY KEY,
  user_id INTEGER NOT NULL REFERENCES users(id) ON DELETE CASCADE,
  expires INTEGER NOT NULL
);
CREATE TABLE IF NOT EXISTS verifies(
  token TEXT PRIMARY KEY,
  user_id INTEGER NOT NULL REFERENCES users(id) ON DELETE CASCADE,
  expires INTEGER NOT NULL
);
CREATE TABLE IF NOT EXISTS resets(
  token TEXT PRIMARY KEY,
  user_id INTEGER NOT NULL REFERENCES users(id) ON DELETE CASCADE,
  expires INTEGER NOT NULL
);
CREATE TABLE IF NOT EXISTS progress(
  user_id INTEGER PRIMARY KEY REFERENCES users(id) ON DELETE CASCADE,
  data TEXT NOT NULL,
  updated INTEGER NOT NULL
);
CREATE TABLE IF NOT EXISTS stats(
  user_id INTEGER PRIMARY KEY REFERENCES users(id) ON DELETE CASCADE,
  xp INTEGER NOT NULL DEFAULT 0,
  week_xp INTEGER NOT NULL DEFAULT 0,
  streak INTEGER NOT NULL DEFAULT 0,
  learned INTEGER NOT NULL DEFAULT 0,
  acc INTEGER NOT NULL DEFAULT 0,
  today INTEGER NOT NULL DEFAULT 0,
  level INTEGER NOT NULL DEFAULT 1,
  updated INTEGER NOT NULL DEFAULT 0
);
CREATE TABLE IF NOT EXISTS friends(
  user_id INTEGER NOT NULL REFERENCES users(id) ON DELETE CASCADE,
  friend_id INTEGER NOT NULL REFERENCES users(id) ON DELETE CASCADE,
  created INTEGER NOT NULL,
  PRIMARY KEY(user_id, friend_id)
);
CREATE TABLE IF NOT EXISTS translations(
  src TEXT NOT NULL,
  sl TEXT NOT NULL,
  tl TEXT NOT NULL,
  out TEXT NOT NULL,
  prov TEXT NOT NULL,
  PRIMARY KEY(src, sl, tl)
);
CREATE TABLE IF NOT EXISTS shares(
  code TEXT PRIMARY KEY,
  owner_id INTEGER NOT NULL REFERENCES users(id) ON DELETE CASCADE,
  name TEXT NOT NULL,
  descr TEXT NOT NULL DEFAULT '',
  cards TEXT NOT NULL,
  created INTEGER NOT NULL
);
CREATE TABLE IF NOT EXISTS hits(
  id INTEGER PRIMARY KEY,
  ts INTEGER NOT NULL,
  day INTEGER NOT NULL,
  vkey TEXT NOT NULL,
  uid INTEGER,
  path TEXT, ref TEXT, device TEXT, os TEXT, browser TEXT, tz TEXT, lang TEXT
);
CREATE INDEX IF NOT EXISTS hits_day ON hits(day);
CREATE TABLE IF NOT EXISTS presence(
  vkey TEXT PRIMARY KEY,
  uid INTEGER,
  ts INTEGER NOT NULL,
  tab TEXT, device TEXT, tz TEXT
);
"""


# ---------- статика: страница, словарь, файлы для поисковиков ----------
# Адрес сайта для canonical/sitemap/Open Graph. На хостинге задай SITE_URL=https://твой-домен (без / в конце).
SITE_URL = os.environ.get("SITE_URL", "").rstrip("/")
# Адреса, по которым открывается сама страница (лендинг и правовые страницы)
# Адреса страниц → какие блоки <!--B:имя-->…<!--/B:имя--> из index.html в них остаются и язык страницы.
# Так у лендинга, английской версии и гида по EPD свой <head> (title, description, canonical) и свой текст.
PAGES = {"/": ("ru", "ru"), "/terms": ("ru", "ru"), "/privacy": ("ru", "ru"), "/en": ("en", "en"), "/epd": ("epd", "ru")}
BLOCK_RE = re.compile(r"<!--B:(\w+)-->(.*?)<!--/B:\1-->", re.S)
# Подтверждение прав на сайт в поисковиках: GOOGLE_SITE_VERIFICATION=…, YANDEX_VERIFICATION=…, BING_VERIFICATION=…
VERIFY = [("google-site-verification", "GOOGLE_SITE_VERIFICATION"), ("yandex-verification", "YANDEX_VERIFICATION"), ("msvalidate.01", "BING_VERIFICATION")]
STATIC_DIR = os.path.join(ROOT, "static")
YEAR = 365 * 86400
STATIC_FILES = {
    "/robots.txt": "text/plain; charset=utf-8",
    "/sitemap.xml": "application/xml; charset=utf-8",
    "/manifest.webmanifest": "application/manifest+json; charset=utf-8",
    "/icon.svg": "image/svg+xml",
    "/og.png": "image/png",
    "/apple-touch-icon.png": "image/png",
    "/icon-192.png": "image/png",
    "/icon-512.png": "image/png",
}
_page_cache = {}
_gz_cache = {}


def gzip_bytes(body):
    k = hashlib.sha1(body).digest()
    if k not in _gz_cache:
        if len(_gz_cache) > 32:
            _gz_cache.clear()
        _gz_cache[k] = gzip.compress(body, 6, mtime=0)
    return _gz_cache[k]


def load_page():
    """index.html → (html без словаря, js словаря, хэш, mtime). Словарь (BOOK, ~0,5 МБ) отдаём отдельным
    файлом с хэшем в имени: браузер кэширует его навсегда, а страница остаётся лёгкой."""
    path = os.path.join(ROOT, "index.html")
    mt = os.path.getmtime(path)
    if _page_cache.get("mt") != mt:
        with open(path, encoding="utf-8") as f:
            html = f.read()
        book, h = "", ""
        m = re.search(r"<script>\n(const BOOK = [^\n]*;)\n", html)
        if m:
            book = m.group(1) + "\n"
            h = hashlib.sha1(book.encode()).hexdigest()[:12]
            html = html[:m.start()] + '<script src="/assets/book.%s.js"></script>\n<script>\n' % h + html[m.end():]
        _page_cache.update(mt=mt, html=html, book=book.encode(), hash=h, sites={})
    return _page_cache


def static_asset(key, site):
    """Возвращает (байты, Content-Type, Cache-Control)."""
    pg = load_page()
    if key in PAGES:
        ck = (site, key)
        if ck not in pg["sites"]:
            block, lang = PAGES[key]
            html = BLOCK_RE.sub(lambda m: m.group(2) if m.group(1) == block else "", pg["html"])
            html = html.replace('<html lang="ru">', '<html lang="%s">' % lang, 1)
            meta = "".join('<meta name="%s" content="%s">\n' % (n, os.environ[e].replace('"', "")) for n, e in VERIFY if os.environ.get(e))
            html = html.replace("<!--VERIFY-->\n", meta)
            pg["sites"][ck] = html.replace("%SITE%", site).encode()
        return pg["sites"][ck], "text/html; charset=utf-8", "no-cache"
    if key.startswith("/assets/book."):
        fresh = key == "/assets/book.%s.js" % pg["hash"]
        return pg["book"], "text/javascript; charset=utf-8", ("public, max-age=%d, immutable" % YEAR) if fresh else "no-cache"
    if key == "/robots.txt":
        body = "User-agent: *\nAllow: /\nDisallow: /api/\n\nSitemap: %s/sitemap.xml\n" % site
        return body.encode(), STATIC_FILES[key], "public, max-age=86400"
    if key == "/sitemap.xml":
        day = time.strftime("%Y-%m-%d", time.gmtime(pg["mt"]))
        alt = ('<xhtml:link rel="alternate" hreflang="ru" href="%s/"/><xhtml:link rel="alternate" hreflang="en" href="%s/en"/>'
               '<xhtml:link rel="alternate" hreflang="x-default" href="%s/"/>') % (site, site, site)
        urls = [("/", "1.0", "weekly", alt), ("/en", "0.9", "weekly", alt), ("/epd", "0.9", "monthly", ""),
                ("/terms", "0.3", "yearly", ""), ("/privacy", "0.3", "yearly", "")]
        body = ('<?xml version="1.0" encoding="UTF-8"?>\n<urlset xmlns="http://www.sitemaps.org/schemas/sitemap/0.9" '
                'xmlns:xhtml="http://www.w3.org/1999/xhtml">\n') + "".join(
            "  <url><loc>%s%s</loc><lastmod>%s</lastmod><changefreq>%s</changefreq><priority>%s</priority>%s</url>\n"
            % (site, u, day, cf, pr, a) for u, pr, cf, a in urls) + "</urlset>\n"
        return body.encode(), STATIC_FILES[key], "public, max-age=86400"
    if key == "/manifest.webmanifest":
        body = json.dumps({
            "name": "EPDeutsch Wörter: подготовка к EPD", "short_name": "EPDeutsch",
            "description": "Немецкие слова для Ergänzungsprüfung Deutsch: карточки, тесты и умные повторения.",
            "lang": "ru", "start_url": "/", "scope": "/", "display": "standalone",
            "background_color": "#F6F7FB", "theme_color": "#7E6FB8",
            "icons": [{"src": "/icon-192.png", "sizes": "192x192", "type": "image/png"},
                      {"src": "/icon-512.png", "sizes": "512x512", "type": "image/png"},
                      {"src": "/icon.svg", "sizes": "any", "type": "image/svg+xml"}],
        }, ensure_ascii=False).encode()
        return body, STATIC_FILES[key], "public, max-age=86400"
    with open(os.path.join(STATIC_DIR, key.lstrip("/")), "rb") as f:
        return f.read(), STATIC_FILES[key], "public, max-age=604800"


# ---------- данные для админки ----------
def _count(rows, key):
    out = {}
    for r in rows:
        k = r[key] or "—"
        out[k] = out.get(k, 0) + 1
    return sorted(([k, v] for k, v in out.items()), key=lambda x: -x[1])


def _progress_rows(c):
    return {r["user_id"]: progress_summary(json.loads(r["data"])) for r in c.execute("SELECT user_id,data FROM progress")}


def admin_overview(c, n):
    now = int(time.time())
    today = local_day(now)
    since = today - n + 1
    users = c.execute("SELECT id,name,email,created,last_seen,tz,device FROM users").fetchall()
    prog = _progress_rows(c)
    bw = book_words()
    # онлайн: был сигнал за последние 2,5 минуты
    pres = c.execute("SELECT * FROM presence WHERE ts>?", (now - 150,)).fetchall()
    names = {u["id"]: u["name"] for u in users}
    online = [{"uid": r["uid"], "name": names.get(r["uid"]), "tab": r["tab"], "what": r["what"] or "", "device": r["device"],
               "country": country(r["tz"]), "ago": now - r["ts"]} for r in pres]
    # посещаемость по дням
    hits = c.execute("SELECT day,vkey,uid,path,ref,device,os,browser,tz FROM hits WHERE day>=?", (since,)).fetchall()
    vis, pv = {}, {}
    for h in hits:
        vis.setdefault(h["day"], set()).add(h["vkey"])
        pv[h["day"]] = pv.get(h["day"], 0) + 1
    first = {}
    for h in hits:  # один посетитель — одна запись в разбивках
        first.setdefault(h["vkey"], h)
    fv = list(first.values())
    regs = {}
    for u in users:
        d = local_day(u["created"])
        if d >= since:
            regs[d] = regs.get(d, 0) + 1
    learners, answers = {}, {}
    for uid, s_ in prog.items():
        for d, rec in s_["days"].items():
            if d >= since:
                learners[d] = learners.get(d, 0) + 1
                answers[d] = answers.get(d, 0) + int(rec.get("a") or 0)
    series = [{"day": d, "visitors": len(vis.get(d, ())), "views": pv.get(d, 0), "regs": regs.get(d, 0),
               "learners": learners.get(d, 0), "answers": answers.get(d, 0)} for d in range(since, today + 1)]
    # воронка за период
    new_ids = [u["id"] for u in users if local_day(u["created"]) >= since]
    started = [i for i in new_ids if prog.get(i, {}).get("answers")]
    returned = [i for i in new_ids if prog.get(i, {}).get("active_days", 0) >= 2]
    funnel = [["Посетители", len(first)], ["Регистрации", len(new_ids)], ["Начали учить", len(started)], ["Вернулись ещё раз", len(returned)]]
    # обучение: трудные слова, режимы, разделы
    miss, modes, secs = {}, {}, {}
    for s_ in prog.values():
        for wid, k in s_["l"].items():
            if isinstance(k, int):
                miss[wid] = miss.get(wid, 0) + k
        for m, v in s_["ms"].items():
            if isinstance(v, list) and len(v) > 1:
                a = modes.setdefault(m, [0, 0])
                a[0] += v[0]
                a[1] += v[1]
        for sid in set([s_["cur"]] + list(s_["recent"])):
            if sid:
                secs[sid] = secs.get(sid, 0) + 1
    hard = []
    for wid, k in sorted(miss.items(), key=lambda x: -x[1])[:15]:
        w = bw["w"].get(wid)
        if w:
            hard.append({"de": w[0], "ru": w[1], "miss": k})
    popular = [[bw["sec"].get(k, "Свой набор" if str(k).startswith("u:") else k), v] for k, v in sorted(secs.items(), key=lambda x: -x[1])[:10]]
    day7 = today - 6
    active7 = sum(1 for u in users if (u["last_seen"] or 0) and local_day(u["last_seen"]) >= day7)
    active1 = sum(1 for u in users if (u["last_seen"] or 0) and local_day(u["last_seen"]) == today)
    total_ans = sum(s_["answers"] for s_ in prog.values())
    total_ok = sum(s_["ok"] for s_ in prog.values())
    return {
        "now": now, "today": today, "days": n,
        "kpi": {"users": len(users), "new7": sum(1 for u in users if local_day(u["created"]) >= day7),
                "newToday": regs.get(today, 0), "active1": active1, "active7": active7,
                "visitorsToday": len(vis.get(today, ())), "viewsToday": pv.get(today, 0),
                "online": len(online), "onlineUsers": sum(1 for o in online if o["uid"]),
                "answers": total_ans, "acc": round(total_ok / total_ans * 100) if total_ans else 0,
                "answersToday": answers.get(today, 0),
                "tour": sum(1 for s_ in prog.values() if s_["tour"]), "withPlan": sum(1 for s_ in prog.values() if s_["exam"])},
        "series": series, "online": sorted(online, key=lambda o: o["ago"]),
        "devices": _count(fv, "device"), "os": _count(fv, "os"), "browsers": _count(fv, "browser"),
        "countries": _count([{"c": country(h["tz"])} for h in fv], "c"),
        "refs": [x for x in _count([h for h in fv if h["ref"]], "ref")][:12],
        "pages": _count(hits, "path")[:10],
        "funnel": funnel, "hard": hard, "popular": popular,
        "modes": sorted(([k, v[0], v[1]] for k, v in modes.items()), key=lambda x: -x[2]),
        "owners": c.execute("SELECT COUNT(*) FROM users WHERE role='owner'").fetchone()[0],
    }


def admin_users(c):
    prog = _progress_rows(c)
    bw = book_words()
    st = {r["user_id"]: r for r in c.execute("SELECT * FROM stats")}
    out = []
    for u in c.execute("SELECT id,name,email,avatar,code,created,last_seen,tz,device,lang,news,plan,plan_until,role,verified FROM users ORDER BY created DESC"):
        s_ = prog.get(u["id"]) or progress_summary({})
        t = st.get(u["id"])
        out.append({
            "id": u["id"], "name": u["name"], "email": u["email"], "avatar": u["avatar"], "code": u["code"],
            "owner": u["role"] == "owner", "role": u["role"], "verified": bool(u["verified"]), "created": u["created"], "last_seen": u["last_seen"] or (t["updated"] if t else 0),
            "country": country(u["tz"]), "tz": u["tz"] or "", "device": u["device"] or "", "lang": u["lang"] or "",
            "xp": s_["xp"], "level": t["level"] if t else 1, "streak": t["streak"] if t else 0,
            "learned": s_["learned"], "started": s_["started"], "answers": s_["answers"], "acc": s_["acc"],
            "active_days": s_["active_days"], "tour": s_["tour"], "exam": s_["exam"],
            "cur": bw["sec"].get(s_["cur"], "Свой набор" if str(s_["cur"]).startswith("u:") else s_["cur"]),
            "usets": s_["usets"], "news": bool(u["news"]), "plan": u["plan"] or "free", "plan_until": u["plan_until"] or 0,
        })
    return out


def admin_user(c, uid):
    u = next((x for x in admin_users(c) if x["id"] == uid), None)
    if not u:
        raise ApiError(404, "Пользователь не найден")
    r = c.execute("SELECT data FROM progress WHERE user_id=?", (uid,)).fetchone()
    s_ = progress_summary(json.loads(r["data"]) if r else {})
    bw = book_words()
    today = local_day()
    days = [{"day": d, "a": int((s_["days"].get(d) or {}).get("a") or 0), "k": int((s_["days"].get(d) or {}).get("k") or 0)}
            for d in range(today - 29, today + 1)]
    hard = [{"de": bw["w"][w][0], "ru": bw["w"][w][1], "miss": k} for w, k in sorted(s_["l"].items(), key=lambda x: -x[1] if isinstance(x[1], int) else 0)
            if w in bw["w"]][:12]
    recent = [bw["sec"].get(x, "Свой набор" if str(x).startswith("u:") else x) for x in s_["recent"]]
    modes = sorted(([k, v[0], v[1]] for k, v in s_["ms"].items() if isinstance(v, list) and len(v) > 1), key=lambda x: -x[2])
    friends = c.execute("SELECT COUNT(*) FROM friends WHERE user_id=?", (uid,)).fetchone()[0]
    shares = c.execute("SELECT COUNT(*) FROM shares WHERE owner_id=?", (uid,)).fetchone()[0]
    return {"user": u, "days": days, "hard": hard, "recent": recent, "modes": modes, "friends": friends, "shares": shares,
            "folders": s_["folders"], "goal": s_["goal"]}


class ApiError(Exception):
    def __init__(self, status, msg):
        super().__init__(msg)
        self.status, self.msg = status, msg


def db():
    c = sqlite3.connect(DB, timeout=10)
    c.row_factory = sqlite3.Row
    c.execute("PRAGMA foreign_keys=ON")
    return c


def init_db():
    os.makedirs(os.path.dirname(DB), exist_ok=True)
    with db() as c:
        c.execute("PRAGMA journal_mode=WAL")
        c.executescript(SCHEMA)
        c.execute("DELETE FROM sessions WHERE expires<?", (int(time.time()),))
        c.execute("DELETE FROM resets WHERE expires<?", (int(time.time()),))
        c.execute("DELETE FROM verifies WHERE expires<?", (int(time.time()),))
        cols = {r[1] for r in c.execute("PRAGMA table_info(users)")}
        # news — согласие на рассылку (0/1, по умолчанию нет); plan/plan_until — тариф на будущее (free / premium до даты)
        for col, typ in (("last_seen", "INTEGER"), ("tz", "TEXT"), ("device", "TEXT"), ("lang", "TEXT"),
                         ("news", "INTEGER NOT NULL DEFAULT 0"), ("plan", "TEXT NOT NULL DEFAULT 'free'"), ("plan_until", "INTEGER"),
                         ("role", "TEXT NOT NULL DEFAULT 'user'"), ("verified", "INTEGER NOT NULL DEFAULT 0"), ("last_nudge", "INTEGER")):
            if col not in cols:
                c.execute("ALTER TABLE users ADD COLUMN %s %s" % (col, typ))
        if "verified" not in cols:
            # кто зарегистрировался до подтверждения почты, считаем подтверждёнными; письма им шлём, как обещали условия
            c.execute("UPDATE users SET verified=1, news=1")
        ensure_owner(c)
        # защита в самой базе: владельца нельзя удалить, и нельзя снять с него роль, пока нет другого владельца
        c.execute("""CREATE TRIGGER IF NOT EXISTS owner_no_delete BEFORE DELETE ON users WHEN OLD.role='owner'
                     BEGIN SELECT RAISE(ABORT, 'owner is protected'); END""")
        c.execute("""CREATE TRIGGER IF NOT EXISTS owner_keep_role BEFORE UPDATE OF role ON users
                     WHEN OLD.role='owner' AND NEW.role<>'owner' AND (SELECT COUNT(*) FROM users WHERE role='owner' AND id<>OLD.id)=0
                     BEGIN SELECT RAISE(ABORT, 'last owner'); END""")
        if "what" not in {r[1] for r in c.execute("PRAGMA table_info(presence)")}:
            c.execute("ALTER TABLE presence ADD COLUMN what TEXT")
        # посещения храним 13 месяцев, «онлайн» — сутки
        c.execute("DELETE FROM hits WHERE ts<?", (int(time.time()) - 400 * 86400,))
        c.execute("DELETE FROM presence WHERE ts<?", (int(time.time()) - 86400,))


# ---------- аналитика для админки ----------
# Посетителя узнаём без cookie и без хранилища браузера: хэш от IP + браузера + соли, которая меняется каждый день.
# Сам IP нигде не сохраняется. Страну определяем по часовому поясу браузера (без внешних сервисов).
try:
    from zoneinfo import ZoneInfo
    TZ_LOCAL = ZoneInfo("Europe/Vienna")
except Exception:  # noqa: BLE001
    TZ_LOCAL = None
_salts = {}
BOT_RE = re.compile(r"bot|crawl|spider|slurp|preview|facebookexternalhit|headless|lighthouse", re.I)
TZ_COUNTRY = {
    "Europe/Vienna": "Австрия", "Europe/Berlin": "Германия", "Europe/Zurich": "Швейцария", "Europe/Kiev": "Украина",
    "Europe/Kyiv": "Украина", "Europe/Uzhgorod": "Украина", "Europe/Zaporozhye": "Украина", "Europe/Moscow": "Россия",
    "Europe/Samara": "Россия", "Europe/Volgograd": "Россия", "Europe/Kaliningrad": "Россия", "Asia/Yekaterinburg": "Россия",
    "Asia/Omsk": "Россия", "Asia/Novosibirsk": "Россия", "Asia/Krasnoyarsk": "Россия", "Asia/Irkutsk": "Россия",
    "Asia/Vladivostok": "Россия", "Europe/Minsk": "Беларусь", "Asia/Almaty": "Казахстан", "Asia/Qostanay": "Казахстан",
    "Asia/Aqtobe": "Казахстан", "Asia/Aqtau": "Казахстан", "Asia/Oral": "Казахстан", "Asia/Tashkent": "Узбекистан",
    "Asia/Samarkand": "Узбекистан", "Asia/Bishkek": "Кыргызстан", "Asia/Dushanbe": "Таджикистан", "Asia/Ashgabat": "Туркменистан",
    "Asia/Tbilisi": "Грузия", "Asia/Yerevan": "Армения", "Asia/Baku": "Азербайджан", "Europe/Chisinau": "Молдова",
    "Europe/Riga": "Латвия", "Europe/Vilnius": "Литва", "Europe/Tallinn": "Эстония", "Europe/Warsaw": "Польша",
    "Europe/Prague": "Чехия", "Europe/Bratislava": "Словакия", "Europe/Budapest": "Венгрия", "Europe/Belgrade": "Сербия",
    "Europe/Sofia": "Болгария", "Europe/Bucharest": "Румыния", "Europe/Istanbul": "Турция", "Europe/Rome": "Италия",
    "Europe/Paris": "Франция", "Europe/London": "Великобритания", "Europe/Madrid": "Испания", "Europe/Amsterdam": "Нидерланды",
    "Europe/Brussels": "Бельгия", "Europe/Stockholm": "Швеция", "Europe/Helsinki": "Финляндия", "Europe/Athens": "Греция",
    "Asia/Jerusalem": "Израиль", "Asia/Dubai": "ОАЭ", "America/New_York": "США", "America/Chicago": "США",
    "America/Los_Angeles": "США", "America/Toronto": "Канада", "Asia/Shanghai": "Китай", "Asia/Tokyo": "Япония",
    "Asia/Kolkata": "Индия", "Asia/Seoul": "Корея",
}


def local_day(ts=None):
    ts = time.time() if ts is None else ts
    off = TZ_LOCAL.utcoffset(__import__("datetime").datetime.utcfromtimestamp(ts)) if TZ_LOCAL else None
    return int((ts + (off.total_seconds() if off else 0)) // 86400)


def country(tz):
    tz = str(tz or "")
    if tz in TZ_COUNTRY:
        return TZ_COUNTRY[tz]
    return tz.split("/")[0] if tz else "—"


def parse_ua(ua):
    ua = ua or ""
    if re.search(r"iPad|Tablet", ua) or ("Android" in ua and "Mobile" not in ua):
        dev = "Планшет"
    elif re.search(r"Mobile|iPhone|Android", ua):
        dev = "Телефон"
    else:
        dev = "Компьютер"
    os_ = ("iOS" if re.search(r"iPhone|iPad|iPod", ua) else "Android" if "Android" in ua else "Windows" if "Windows" in ua
           else "macOS" if "Mac OS X" in ua else "ChromeOS" if "CrOS" in ua else "Linux" if "Linux" in ua else "Другая")
    br = ("Edge" if "Edg/" in ua else "Opera" if "OPR/" in ua else "Яндекс" if "YaBrowser" in ua else "Samsung" if "SamsungBrowser" in ua
          else "Firefox" if "Firefox/" in ua else "Chrome" if ("Chrome/" in ua or "CriOS" in ua) else "Safari" if "Safari/" in ua else "Другой")
    return dev, os_, br


def visitor_key(ip, ua):
    d = local_day()
    if d not in _salts:
        _salts.clear()
        _salts[d] = secrets.token_bytes(16)
    return hashlib.sha256(_salts[d] + (ip + "|" + (ua or "")).encode()).hexdigest()[:16]


_book_words = {"mt": None, "w": {}, "sec": {}}


def book_words():
    """id карточки → слово; id раздела → название (из словаря BOOK в index.html)."""
    pg = load_page()
    if _book_words["mt"] != pg["mt"]:
        w, sec = {}, {}
        try:
            raw = pg["book"].decode()
            book = json.loads(raw[raw.index("["):raw.rindex("]") + 1])
            for s_ in book:
                sec[s_["id"]] = s_["id"] + " " + s_["title"]
                for st in s_["sets"]:
                    for i, it in enumerate(st["items"]):
                        w["%s/%s/%d" % (s_["id"], st["id"], i)] = (it[0], it[1])
        except Exception as e:  # noqa: BLE001
            sys.stderr.write("book parse: %r\n" % e)
        _book_words.update(mt=pg["mt"], w=w, sec=sec)
    return _book_words


def progress_summary(data):
    """Краткая сводка по сохранённому прогрессу пользователя (JSON из браузера)."""
    d = data if isinstance(data, dict) else {}
    days = d.get("days") or {}
    ms = d.get("ms") or {}
    b = d.get("b") or {}
    answers = sum((m[1] if isinstance(m, list) and len(m) > 1 else 0) for m in ms.values())
    ok = sum((m[0] if isinstance(m, list) and m else 0) for m in ms.values())
    active = {int(k): v for k, v in days.items() if str(k).lstrip("-").isdigit() and isinstance(v, dict) and v.get("a")}
    plan = d.get("plan") if isinstance(d.get("plan"), dict) else None
    return {
        "answers": answers, "ok": ok, "acc": round(ok / answers * 100) if answers else 0,
        "learned": sum(1 for r in b.values() if isinstance(r, list) and r and r[0] >= 3),
        "started": len(b), "active_days": len(active), "days": active,
        "cur": d.get("cur") or "", "recent": d.get("recent") or [], "xp": d.get("xp") or 0,
        "goal": d.get("goal") or 20, "tour": bool(d.get("tourDone")), "usets": len(d.get("usets") or []),
        "folders": len(d.get("folders") or []), "exam": (plan or {}).get("exam") or (plan or {}).get("date") or "",
        "ms": ms, "l": d.get("l") or {}, "theme": d.get("theme") or "", "accent": d.get("accent") or "",
    }


def hash_pw(pw, salt=None):
    salt = salt or secrets.token_bytes(16)
    h = hashlib.pbkdf2_hmac("sha256", pw.encode(), salt, PBKDF2_ITER)
    return salt.hex() + "$" + h.hex()


def check_pw(pw, stored):
    salt, h = stored.split("$")
    return hmac.compare_digest(hash_pw(pw, bytes.fromhex(salt)).split("$")[1], h)


def pw_problem(pw):
    """Правила пароля (такие же проверяет форма на сайте). Возвращает текст ошибки или None."""
    if len(pw) < 8:
        return "Пароль должен быть не короче 8 символов"
    if len(pw) > 200:
        return "Слишком длинный пароль"
    if not any(ch.isupper() for ch in pw):
        return "Добавь в пароль хотя бы одну заглавную букву"
    return None


# ---------- почта ----------
def smtp_conf():
    conf = {}
    if os.path.exists(SMTP_FILE):
        with open(SMTP_FILE, encoding="utf-8") as f:
            conf = json.load(f)
    for k, e in (("host", "SMTP_HOST"), ("port", "SMTP_PORT"), ("user", "SMTP_USER"), ("pass", "SMTP_PASS"), ("from", "MAIL_FROM")):
        if os.environ.get(e):
            conf[k] = os.environ[e]
    return conf if conf.get("host") else None


MAIL_SECRET_FILE = os.path.join(ROOT, "db", "mail.key")


def mail_secret():
    if not os.path.exists(MAIL_SECRET_FILE):
        os.makedirs(os.path.dirname(MAIL_SECRET_FILE), exist_ok=True)
        with open(MAIL_SECRET_FILE, "w") as f:
            f.write(secrets.token_hex(32))
    with open(MAIL_SECRET_FILE) as f:
        return f.read().strip()


def unsub_link(base, uid):
    sig = hmac.new(mail_secret().encode(), b"unsub:%d" % uid, hashlib.sha256).hexdigest()[:24]
    return "%s/api/unsub?u=%d&s=%s" % (base, uid, sig)


def _esc(s):
    return str(s).replace("&", "&amp;").replace("<", "&lt;").replace(">", "&gt;").replace('"', "&quot;")


ART_COL = {"der": "#3D7BF7", "die": "#E8505B", "das": "#18AE79"}
# палитра писем: фирменный фиолетовый + цветовой треугольник от него (триада: 252°, 12°, 132°) и светлые оттенки для фона
TRIAD = ["#7E6FB8", "#D66D51", "#3FB45C"]
TRIAD_SOFT = ["#EFEBFA", "#FBEDE9", "#E9FBED"]


def pick_word(c=None, uid=None):
    """Слово для письма: самое трудное слово человека (чаще всего «Не знаю»), иначе случайное существительное с артиклем."""
    bw = book_words()["w"]
    if c is not None and uid:
        r = c.execute("SELECT data FROM progress WHERE user_id=?", (uid,)).fetchone()
        if r:
            miss = {k: v for k, v in progress_summary(json.loads(r["data"]))["l"].items() if isinstance(v, int) and k in bw}
            if miss:
                de, ru = bw[max(miss, key=miss.get)]
                return de.replace("*", "").strip(), ru, True
    pool = [v for v in bw.values() if v[0].split(" ")[0] in ART_COL and len(v[0]) <= 24 and len(v[1]) <= 42]
    if not pool:
        return "die Hochschule", "вуз", False
    de, ru = secrets.choice(pool)
    return de.replace("*", "").strip(), ru, False


def word_card(label, de, ru, hint=""):
    """Карточка слова как в тренажёре: цветная полоска рода, артикль цветом, перевод."""
    art = de.split(" ")[0]
    col = ART_COL.get(art, "#7E6FB8")
    word = ('<span style="color:%s">%s</span> %s' % (col, _esc(art), _esc(de[len(art):].strip()))) if art in ART_COL else _esc(de)
    return ('<table role="presentation" width="100%%" cellpadding="0" cellspacing="0" style="margin:24px 0 0;border-radius:18px;background:#F6F7FB;overflow:hidden"><tr>'
            '<td style="width:6px;background:%s"></td><td style="padding:18px 20px">'
            '<div style="font-size:11.5px;font-weight:800;letter-spacing:.08em;text-transform:uppercase;color:#8A93AD">%s</div>'
            '<div style="font-size:24px;font-weight:800;margin:6px 0 2px;color:#282E3E">%s</div>'
            '<div style="font-size:15px;color:#586380">%s</div>%s</td></tr></table>') % (
        col, _esc(label), word, _esc(ru), ('<div style="font-size:13px;color:#8A93AD;margin-top:8px">%s</div>' % hint) if hint else "")


def stat_row(items):
    """Ряд плиток с цифрами: [(число, подпись, цвет)]."""
    cells = "".join('<td width="%d%%" style="padding:0 5px"><div style="background:%s;border-radius:16px;padding:14px 10px;text-align:center">'
                    '<div style="font-size:24px;font-weight:800;color:#282E3E;line-height:1.2">%s</div>'
                    '<div style="font-size:12px;font-weight:700;color:#586380;margin-top:2px">%s</div></div></td>' % (100 // len(items), bg, v, _esc(k))
                    for v, k, bg in items)
    return '<table role="presentation" width="100%%" cellpadding="0" cellspacing="0" style="margin:22px 0 0"><tr>%s</tr></table>' % cells


def steps_list(items):
    """Нумерованные шаги с цветными кружками."""
    cols = TRIAD
    rows = "".join('<tr><td valign="top" style="padding:0 12px 12px 0"><div style="width:28px;height:28px;border-radius:50%%;background:%s;color:#fff;'
                   'font-weight:800;font-size:14px;line-height:28px;text-align:center">%s</div></td>'
                   '<td valign="top" style="padding:3px 0 12px;font-size:15px;line-height:1.5;color:#586380">%s</td></tr>' % (cols[n % 3], "✓" if done else n + 1, txt)
                   for n, (txt, done) in enumerate(items))  # noqa: E501
    return '<table role="presentation" cellpadding="0" cellspacing="0" style="margin:24px 0 0">%s</table>' % rows


def mail_html(base, preview, icon, title, body, button, link, note="", unsub="", kicker="", extra="", grad=("#7E6FB8", "#8B5CF6")):
    """Фирменное письмо: цветная шапка с артиклями, текст, кнопка, дополнительные блоки и футер.
    Только таблицы и стили в строке, так письмо одинаково выглядит в Gmail, Outlook и почте на телефоне."""
    chips = "".join('<span style="display:inline-block;margin:0 4px 6px 0;padding:4px 12px;border-radius:999px;background:rgba(255,255,255,.18);'
                    'color:#fff;font-size:12px;font-weight:800;letter-spacing:.02em">%s</span>' % w for w in ("der", "die", "das", "ä ö ü ß"))
    foot_links = ('<a href="%s" style="color:#6C5CAC;text-decoration:none;font-weight:700">Открыть EPDeutsch</a>'
                  ' &nbsp;·&nbsp; <a href="%s/terms" style="color:#8A93AD">Условия</a>'
                  ' &nbsp;·&nbsp; <a href="%s/privacy" style="color:#8A93AD">Конфиденциальность</a>') % (_esc(base), _esc(base), _esc(base))
    unsub_html = ('<br><a href="%s" style="color:#8A93AD">Отписаться от напоминаний и новостей</a>' % _esc(unsub)) if unsub else ""
    return """<!doctype html><html lang="ru"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1">
<meta name="color-scheme" content="light"><meta name="supported-color-schemes" content="light"><title>%(title)s</title></head>
<body style="margin:0;padding:0;background:#EEF0F7;font-family:Manrope,-apple-system,'Segoe UI',Roboto,Arial,sans-serif;color:#282E3E">
<div style="display:none;max-height:0;overflow:hidden">%(preview)s&nbsp;&zwnj;&nbsp;&zwnj;&nbsp;&zwnj;</div>
<table role="presentation" width="100%%" cellpadding="0" cellspacing="0" style="background:#EEF0F7"><tr><td align="center" style="padding:28px 12px 36px">
<table role="presentation" width="100%%" cellpadding="0" cellspacing="0" style="max-width:540px">
<tr><td style="padding:0 6px 16px"><table role="presentation" cellpadding="0" cellspacing="0"><tr>
<td style="background:#7E6FB8;border-radius:10px;width:34px;height:34px;text-align:center;font-weight:800;font-size:19px;color:#fff">D</td>
<td style="padding-left:10px;font-size:21px;font-weight:800;letter-spacing:-.02em">EP<span style="color:#6C5CAC">Deutsch</span></td></tr></table></td></tr>
<tr><td style="background:#ffffff;border-radius:26px;box-shadow:0 12px 40px rgba(40,46,62,.10);overflow:hidden">
<table role="presentation" width="100%%" cellpadding="0" cellspacing="0"><tr><td bgcolor="%(g1)s" style="background:%(g1)s;background-image:linear-gradient(135deg,%(g1)s,%(g2)s);padding:30px 32px 26px;color:#ffffff">
<table role="presentation" width="100%%" cellpadding="0" cellspacing="0"><tr><td valign="top">
<div style="font-size:12px;font-weight:800;letter-spacing:.12em;text-transform:uppercase;opacity:.85">%(kicker)s</div>
<h1 style="margin:10px 0 14px;font-size:27px;line-height:1.2;font-weight:800;letter-spacing:-.01em;color:#ffffff">%(title)s</h1>
<div>%(chips)s</div></td>
<td valign="top" align="right" style="width:74px"><div style="width:64px;height:64px;border-radius:20px;background:rgba(255,255,255,.2);text-align:center;line-height:64px;font-size:32px">%(icon)s</div></td></tr></table>
</td></tr></table>
<div style="padding:28px 32px 32px">
<div style="font-size:16px;line-height:1.65;color:#586380">%(body)s</div>
<table role="presentation" cellpadding="0" cellspacing="0" style="margin:24px 0 0"><tr><td bgcolor="#7E6FB8" style="border-radius:999px;background:#7E6FB8;box-shadow:0 10px 24px -8px rgba(126,111,184,.7)">
<a href="%(link)s" style="display:inline-block;padding:16px 32px;font-size:16px;font-weight:800;color:#ffffff;text-decoration:none;border-radius:999px">%(button)s &rarr;</a></td></tr></table>
%(extra)s%(note)s
<p style="margin:26px 0 0;font-size:15px;line-height:1.6;color:#586380">Viel Erfolg! 🍀<br><b style="color:#282E3E">Команда EPDeutsch</b></p>
<div style="margin:24px 0 0;padding:14px 16px;border:1px dashed #D9DDE8;border-radius:14px;font-size:12.5px;line-height:1.5;color:#8A93AD">Кнопка не нажимается? Скопируй ссылку в браузер:<br><a href="%(link)s" style="color:#6C5CAC;word-break:break-all">%(link)s</a></div>
</div></td></tr>
<tr><td style="padding:22px 10px 0;text-align:center;font-size:12.5px;line-height:1.7;color:#8A93AD">
%(foot)s<br>Это письмо пришло, потому что на EPDeutsch есть аккаунт с этой почтой.%(unsub)s<br>© EPDeutsch · подготовка к Ergänzungsprüfung Deutsch · Вена</td></tr>
</table></td></tr></table></body></html>""" % {"title": _esc(title), "preview": _esc(preview), "icon": icon, "body": body, "button": _esc(button),
                                                "link": _esc(link), "note": note, "foot": foot_links, "unsub": unsub_html, "kicker": _esc(kicker),
                                                "extra": extra, "chips": chips, "g1": grad[0], "g2": grad[1]}


def reset_email(name, base, link, minutes):
    text = ("Привет, %s!\n\nКто-то (надеемся, ты) попросил сбросить пароль на EPDeutsch.\nЧтобы придумать новый, открой ссылку:\n\n%s\n\n"
            "Ссылка работает %d минут и только один раз. Если ты ничего не просил(а), просто удали это письмо, пароль останется прежним.\n\nEPDeutsch"
            % (name, link, minutes))
    note = ('<table role="presentation" width="100%%" cellpadding="0" cellspacing="0" style="margin:24px 0 0"><tr>'
            '<td style="background:#EFEBFA;border-radius:16px;padding:14px 16px;font-size:14px;line-height:1.55;color:#4B3F86">'
            '⏳ Ссылка работает <b>%d минут</b> и только один раз.<br>🔒 Не просил(а) сбросить пароль? Просто удали письмо, пароль останется прежним.</td></tr></table>') % minutes
    html = mail_html(base, "Ссылка, чтобы придумать новый пароль. Работает %d минут." % minutes, "🔑", "Придумаем новый пароль",
                     "Привет, %s! Кто-то (надеемся, ты) попросил сбросить пароль на EPDeutsch. Нажми на кнопку, и откроется страница, где можно задать новый." % _esc(name),
                     "Задать новый пароль", link, note=note, kicker="Восстановление доступа")
    return text, html


def welcome_email(name, base, link):
    text = ("Привет, %s!\n\nДобро пожаловать в EPDeutsch. Подтверди, пожалуйста, почту, чтобы мы знали, что она твоя:\n\n%s\n\n"
            "Твой старт: 1) подтверди почту, 2) добавь первую тему на главной, 3) позанимайся 15 минут.\n\nViel Erfolg!\nКоманда EPDeutsch"
            % (name, link))
    extra = steps_list([("Создать аккаунт", True), ("<b style=\"color:#282E3E\">Подтвердить почту</b>: кнопка выше, это одна секунда", False),
                        ("Добавить первую тему на главной, например «Учёба и наука»", False), ("Позаниматься 15 минут. Дальше мы сами подскажем, что повторить", False)])
    html = mail_html(base, "Подтверди почту и начни готовиться к EPD.", "👋", "Добро пожаловать в EPDeutsch!",
                     "Привет, %s! Рады, что ты с нами. Подтверди, пожалуйста, почту одним нажатием: так мы будем знать, что она твоя, "
                     "и сможем вернуть доступ, если забудешь пароль." % _esc(name), "Подтвердить почту", link,
                     kicker="Твой путь к EPD начинается", extra=extra)
    return text, html


def ru_plural(n, one, few, many):
    n = abs(n) % 100
    return many if 11 <= n <= 19 else one if n % 10 == 1 else few if 2 <= n % 10 <= 4 else many


def nudge_email(name, base, days, streak, unsub, word=None):
    dw = ru_plural(days, "день", "дня", "дней")
    de, ru, hard = word or ("die Hochschule", "вуз", False)
    lost = ("Серия в %d дн. прервалась, но её легко начать заново." % streak) if streak else ""
    text = ("Привет, %s!\n\nТы не занимался(ась) уже %d %s. Слова начинают забываться именно сейчас, поэтому 10 минут сегодня сэкономят часы потом.\n%s\n\n"
            "Помнишь это слово? %s (%s)\n\nПродолжить: %s\n\nОтписаться от напоминаний: %s\n\nКоманда EPDeutsch" % (name, days, dw, lost, de, ru, base, unsub))
    extra = stat_row([(str(days), dw + " без занятий", TRIAD_SOFT[0]), ("🔥 %d" % streak if streak else "🔥 0", "была серия" if streak else "серия ждёт", TRIAD_SOFT[1]),
                      ("10", "минут хватит", TRIAD_SOFT[2])])
    extra += word_card("Твоё трудное слово" if hard else "Помнишь это слово?", de, ru,
                       "Ты чаще всего спотыкался(ась) на нём. Самое время закрепить." if hard else "Если вспомнил(а) с артиклем, ты молодец. Если нет, самое время повторить.")
    html = mail_html(base, "10 минут сегодня, и слова не забудутся.", "⏰", "Слова скучают по тебе",
                     "Привет, %s! Ты не занимался(ась) уже <b style=\"color:#282E3E\">%d %s</b>. Как раз сейчас слова начинают забываться, "
                     "поэтому 10 минут повторения сегодня сэкономят часы потом. %s" % (_esc(name), days, dw, _esc(lost)),
                     "Повторить 10 минут", base, unsub=unsub, kicker="Мы по тебе соскучились", extra=extra)
    return text, html


def send_mail(to, subject, text, html=None, unsub=None):
    """Отправляет письмо в фоне. Без настроенного SMTP печатает его в консоль сервера."""
    conf = smtp_conf()
    if not conf:
        sys.stderr.write("\n[почта не настроена] письмо для %s\nТема: %s\n%s\n\n" % (to, subject, text))
        return

    def run():
        try:
            msg = EmailMessage()
            msg["Subject"], msg["To"] = subject, to
            msg["From"] = conf.get("from") or conf.get("user")
            if unsub:
                msg["List-Unsubscribe"] = "<%s>" % unsub
            msg.set_content(text)
            if html:
                msg.add_alternative(html, subtype="html")
            port = int(conf.get("port") or 587)
            if port == 465:
                srv = smtplib.SMTP_SSL(conf["host"], port, context=ssl.create_default_context(), timeout=20)
            else:
                srv = smtplib.SMTP(conf["host"], port, timeout=20)
                srv.starttls(context=ssl.create_default_context())
            with srv:
                if conf.get("user"):
                    srv.login(conf["user"], conf.get("pass", ""))
                srv.send_message(msg)
        except Exception as e:  # noqa: BLE001
            sys.stderr.write("Письмо для %s не отправлено: %r\n" % (to, e))
    threading.Thread(target=run, daemon=True).start()


def delete_user(c, uid):
    """Удаляет аккаунт и всё, что с ним связано: прогресс, статистику, друзей, общие наборы, сессии, ссылки сброса."""
    for sql in ("DELETE FROM sessions WHERE user_id=?", "DELETE FROM resets WHERE user_id=?", "DELETE FROM progress WHERE user_id=?",
                "DELETE FROM stats WHERE user_id=?", "DELETE FROM friends WHERE user_id=? OR friend_id=?",
                "DELETE FROM shares WHERE owner_id=?", "UPDATE presence SET uid=NULL, what='' WHERE uid=?", "DELETE FROM users WHERE id=?"):
        c.execute(sql, (uid,) * sql.count("?"))


def send_reset(c, uid, name, email, base):
    tok = secrets.token_urlsafe(32)
    c.execute("DELETE FROM resets WHERE user_id=?", (uid,))
    c.execute("INSERT INTO resets(token,user_id,expires) VALUES(?,?,?)", (sha(tok), uid, int(time.time()) + RESET_MINUTES * 60))
    text, html = reset_email(name, base, "%s/#reset=%s" % (base, tok), RESET_MINUTES)
    send_mail(email, "EPDeutsch: новый пароль", text, html)


def send_welcome(c, uid, name, email, base):
    tok = secrets.token_urlsafe(32)
    c.execute("DELETE FROM verifies WHERE user_id=?", (uid,))
    c.execute("INSERT INTO verifies(token,user_id,expires) VALUES(?,?,?)", (sha(tok), uid, int(time.time()) + 14 * 86400))
    text, html = welcome_email(name, base, "%s/#verify=%s" % (base, tok))
    send_mail(email, "Добро пожаловать в EPDeutsch! Подтверди почту", text, html)


def reminder_loop():
    """Раз в час: тем, кто не занимался 3–30 дней, одно напоминание за каждый такой перерыв.
    Только при настроенном SMTP и SITE_URL; в каждом письме ссылка «Отписаться»."""
    time.sleep(60)
    while True:
        try:
            if smtp_conf() and SITE_URL:
                now = int(time.time())
                with db() as c:
                    rows = c.execute("""SELECT u.id,u.name,u.email,s.updated,s.streak FROM users u JOIN stats s ON s.user_id=u.id
                                        WHERE u.news=1 AND u.verified=1 AND s.updated BETWEEN ? AND ?
                                        AND (u.last_nudge IS NULL OR u.last_nudge<s.updated) LIMIT 50""",
                                     (now - 30 * 86400, now - 3 * 86400)).fetchall()
                    for r in rows:
                        un = unsub_link(SITE_URL, r["id"])
                        text, html = nudge_email(r["name"], SITE_URL, (now - r["updated"]) // 86400, r["streak"] or 0, un, pick_word(c, r["id"]))
                        send_mail(r["email"], "EPDeutsch: слова скучают по тебе", text, html, unsub=un)
                        c.execute("UPDATE users SET last_nudge=? WHERE id=?", (now, r["id"]))
        except Exception as e:  # noqa: BLE001
            sys.stderr.write("Напоминания: %r\n" % e)
        time.sleep(3600)


def sha(tok):
    return hashlib.sha256(tok.encode()).hexdigest()


def new_code(c, table, col, n):
    while True:
        code = "".join(secrets.choice(CODE_ABC) for _ in range(n))
        if not c.execute(f"SELECT 1 FROM {table} WHERE {col}=?", (code,)).fetchone():
            return code


# неудачные входы: ключ -> список времён (в памяти процесса)
FAILS = {}


def too_many(key):
    now = time.time()
    FAILS[key] = [t for t in FAILS.get(key, []) if now - t < 600]
    return len(FAILS[key]) >= 8


# ---------- переводчик ----------
TR_HITS = {}


def tr_limited(ip):
    now = time.time()
    TR_HITS[ip] = [t for t in TR_HITS.get(ip, []) if now - t < 60]
    if len(TR_HITS[ip]) >= 90:
        return True
    TR_HITS[ip].append(now)
    return False


def http_json(req):
    with urllib.request.urlopen(req, timeout=8) as r:
        return json.loads(r.read().decode("utf-8"))


def translate(text, sl, tl):
    key = deepl_key()
    if key:
        host = "api-free.deepl.com" if key.endswith(":fx") else "api.deepl.com"
        body = json.dumps({"text": [text], "source_lang": sl.upper(), "target_lang": tl.upper()}).encode()
        req = urllib.request.Request(f"https://{host}/v2/translate", data=body, method="POST",
                                     headers={"Authorization": "DeepL-Auth-Key " + key, "Content-Type": "application/json"})
        j = http_json(req)
        return j["translations"][0]["text"].strip(), "DeepL"
    q = urllib.parse.urlencode({"q": text, "langpair": f"{sl}|{tl}"})
    j = http_json(urllib.request.Request("https://api.mymemory.translated.net/get?" + q,
                                         headers={"User-Agent": "UniSicher/1"}))
    if int(j.get("responseStatus") or 0) != 200:
        raise ValueError(j.get("responseDetails") or "MyMemory error")
    out = (j.get("responseData") or {}).get("translatedText") or ""
    if not out or out.isupper() and not text.isupper():
        out = out.capitalize() if out else out
    return out.strip(), "MyMemory"


def s_int(v, lo=0, hi=10**9):
    try:
        return max(lo, min(hi, int(v)))
    except (TypeError, ValueError):
        return lo


def s_str(v, n):
    return str(v or "").strip()[:n]


def user_json(u):
    return {"id": u["id"], "name": u["name"], "email": u["email"], "avatar": u["avatar"], "code": u["code"],
            "owner": u["role"] == "owner", "admin": u["role"] in ("admin", "owner"), "role": u["role"],
            "news": bool(u["news"]), "verified": bool(u["verified"]), "plan": u["plan"] or "free"}


def friend_rows(c, uid):
    rows = c.execute(
        """SELECT u.id,u.name,u.role,u.avatar,u.code,s.xp,s.week_xp,s.streak,s.learned,s.acc,s.today,s.level,s.updated
           FROM users u LEFT JOIN stats s ON s.user_id=u.id
           WHERE u.id=? OR u.id IN (SELECT friend_id FROM friends WHERE user_id=?)""",
        (uid, uid),
    ).fetchall()
    out = []
    for r in rows:
        d = {k: r[k] for k in r.keys()}
        d["owner"] = d.pop("role") == "owner"  # почту друзей наружу не отдаём
        for k in ("xp", "week_xp", "streak", "learned", "acc", "today", "updated"):
            d[k] = d[k] or 0
        d["level"] = d["level"] or 1
        d["me"] = r["id"] == uid
        out.append(d)
    return out


class Handler(BaseHTTPRequestHandler):
    server_version = "UniSicher/1"

    def log_message(self, fmt, *args):
        line = str(args[0] if args else "")
        if "/api/" in line and "/api/ping" not in line and "/api/hit" not in line:
            sys.stderr.write("%s %s\n" % (self.log_date_time_string(), fmt % args))

    # ---------- ответы ----------
    def send_json(self, status, obj, cookie=None):
        body = json.dumps(obj, ensure_ascii=False).encode()
        self.send_response(status)
        self.send_header("Content-Type", "application/json; charset=utf-8")
        self.send_header("Content-Length", str(len(body)))
        self.send_header("Cache-Control", "no-store")
        if cookie:
            self.send_header("Set-Cookie", cookie)
        self.end_headers()
        self.wfile.write(body)

    def session_cookie(self, token, max_age):
        return f"sid={token}; HttpOnly; SameSite=Lax; Path=/; Max-Age={max_age}" + ("; Secure" if PROXY else "")

    def client_ip(self):
        if PROXY:
            fwd = self.headers.get("X-Forwarded-For", "").split(",")[-1].strip()
            if fwd:
                return fwd
        return self.client_address[0]

    def read_json(self):
        n = int(self.headers.get("Content-Length") or 0)
        if n > MAX_BODY:
            raise ApiError(413, "Слишком большой запрос")
        raw = self.rfile.read(n) if n else b"{}"
        try:
            data = json.loads(raw or b"{}")
        except ValueError:
            raise ApiError(400, "Неверный формат данных")
        if not isinstance(data, dict):
            raise ApiError(400, "Неверный формат данных")
        return data

    def current_user(self, c):
        ck = SimpleCookie(self.headers.get("Cookie") or "")
        if "sid" not in ck:
            return None
        return c.execute(
            "SELECT u.* FROM sessions s JOIN users u ON u.id=s.user_id WHERE s.token=? AND s.expires>?",
            (sha(ck["sid"].value), int(time.time())),
        ).fetchone()

    def need_owner(self, c):
        u = self.need_user(c)
        if u["role"] != "owner":
            raise ApiError(403, "Это может только владелец сайта")
        return u

    def need_admin(self, c):
        u = self.need_user(c)
        if u["role"] not in ("admin", "owner"):
            raise ApiError(403, "Доступ только для администраторов")
        return u

    def mail_base(self):
        """Адрес сайта для ссылок в письмах: SITE_URL, а без него только localhost (чтобы не подменили Host)."""
        base = SITE_URL or self.site_url()
        return base if SITE_URL or (urlparse(base).hostname or "") in ("localhost", "127.0.0.1", "::1") else None

    def need_user(self, c):
        u = self.current_user(c)
        if not u:
            raise ApiError(401, "Нужно войти в аккаунт")
        return u

    def start_session(self, c, uid):
        tok = secrets.token_urlsafe(32)
        c.execute("INSERT INTO sessions(token,user_id,expires) VALUES(?,?,?)",
                  (sha(tok), uid, int(time.time()) + SESSION_DAYS * 86400))
        return self.session_cookie(tok, SESSION_DAYS * 86400)

    # ---------- статика ----------
    def site_url(self):
        """Адрес сайта для canonical, sitemap и Open Graph: SITE_URL=https://… или из заголовков запроса."""
        if SITE_URL:
            return SITE_URL
        proto = (self.headers.get("X-Forwarded-Proto") or ("https" if PROXY else "http")).split(",")[0].strip()
        host = re.sub(r"[^A-Za-z0-9.:\-\[\]]", "", self.headers.get("Host") or "localhost:%d" % PORT)
        return proto + "://" + host

    def send_static(self, key, head=False):
        """Отдаёт файл из STATIC: gzip, ETag/304 и кэш (ассеты с хэшем в имени — навсегда)."""
        body, ctype, cache = static_asset(key, self.site_url())
        etag = '"%s"' % hashlib.sha1(body).hexdigest()[:20]
        gz = "gzip" in (self.headers.get("Accept-Encoding") or "") and len(body) > 1024
        if self.headers.get("If-None-Match") == etag:
            self.send_response(304)
            self.send_header("ETag", etag)
            self.send_header("Cache-Control", cache)
            self.end_headers()
            return
        out = gzip_bytes(body) if gz else body
        self.send_response(200)
        self.send_header("Content-Type", ctype)
        self.send_header("Content-Length", str(len(out)))
        self.send_header("Cache-Control", cache)
        self.send_header("ETag", etag)
        self.send_header("Vary", "Accept-Encoding")
        self.send_header("X-Content-Type-Options", "nosniff")
        self.send_header("Referrer-Policy", "strict-origin-when-cross-origin")
        if gz:
            self.send_header("Content-Encoding", "gzip")
        self.end_headers()
        if not head:
            self.wfile.write(out)

    def route_static(self, head=False):
        path = urlparse(self.path).path
        if path in PAGES:
            return self.send_static(path, head)
        if path.endswith("/") and path.rstrip("/") in PAGES:
            self.send_response(301)
            self.send_header("Location", path.rstrip("/"))
            self.end_headers()
            return
        if path == "/index.html":
            self.send_response(301)
            self.send_header("Location", "/")
            self.end_headers()
            return
        if path in STATIC_FILES or re.fullmatch(r"/assets/book\.[0-9a-f]{12}\.js", path):
            return self.send_static(path, head)
        self.send_error(404)

    # ---------- маршруты ----------
    def do_GET(self):
        path = urlparse(self.path).path
        if path.startswith("/api/"):
            return self.api("GET", path)
        self.route_static()

    def do_HEAD(self):
        self.route_static(head=True)

    def do_POST(self):
        self.api("POST", urlparse(self.path).path)

    def do_PUT(self):
        self.api("PUT", urlparse(self.path).path)

    def do_PATCH(self):
        self.api("PATCH", urlparse(self.path).path)

    def do_DELETE(self):
        self.api("DELETE", urlparse(self.path).path)

    def api(self, method, path):
        try:
            # защита от CSRF: чужой сайт не может отправить свой заголовок без CORS, а CORS мы не включаем
            if method != "GET" and self.headers.get("X-USD") != "1":
                raise ApiError(403, "Запрос отклонён")
            with db() as c:
                self.route(c, method, path)
        except ApiError as e:
            self.send_json(e.status, {"error": e.msg})
        except Exception as e:  # noqa: BLE001
            sys.stderr.write("API error: %r\n" % e)
            self.send_json(500, {"error": "Ошибка сервера"})

    def route(self, c, method, path):
        now = int(time.time())
        p = path[len("/api"):]

        if method == "GET" and p == "/me":
            u = self.current_user(c)
            if not u:
                return self.send_json(200, {"user": None})
            pr = c.execute("SELECT data,updated FROM progress WHERE user_id=?", (u["id"],)).fetchone()
            return self.send_json(200, {"user": user_json(u), "mail": bool(smtp_conf()),
                                        "progress": json.loads(pr["data"]) if pr else None,
                                        "updated": pr["updated"] if pr else 0})

        if method == "POST" and p == "/verify":
            r = c.execute("SELECT user_id FROM verifies WHERE token=? AND expires>?", (sha(s_str(self.read_json().get("token"), 100)), now)).fetchone()
            if not r:
                raise ApiError(400, "Ссылка устарела или уже использована. Отправь письмо ещё раз из профиля")
            c.execute("UPDATE users SET verified=1 WHERE id=?", (r["user_id"],))
            c.execute("DELETE FROM verifies WHERE user_id=?", (r["user_id"],))
            return self.send_json(200, {"ok": True})

        if method == "POST" and p == "/verify/resend":
            u = self.need_user(c)
            if u["verified"]:
                return self.send_json(200, {"ok": True})
            key = self.client_ip() + "|verify|" + str(u["id"])
            if too_many(key):
                raise ApiError(429, "Слишком много писем. Подожди 10 минут")
            FAILS.setdefault(key, []).append(time.time())
            base = self.mail_base()
            if not base:
                raise ApiError(400, "Письма пока не настроены")
            send_welcome(c, u["id"], u["name"], u["email"], base)
            return self.send_json(200, {"ok": True})

        if method == "GET" and p == "/unsub":
            q = urllib.parse.parse_qs(urlparse(self.path).query)
            uid = s_int((q.get("u") or ["0"])[0])
            ok = hmac.compare_digest((q.get("s") or [""])[0], unsub_link("", uid).split("s=")[1])
            if ok:
                c.execute("UPDATE users SET news=0 WHERE id=?", (uid,))
            body = ("<!doctype html><meta charset=utf-8><meta name=viewport content='width=device-width,initial-scale=1'><title>EPDeutsch</title>"
                    "<body style=\"font-family:-apple-system,Segoe UI,Arial,sans-serif;background:#F6F7FB;color:#282E3E;display:grid;place-items:center;min-height:90vh;margin:0\">"
                    "<div style=\"background:#fff;border-radius:22px;padding:34px 32px;max-width:420px;box-shadow:0 8px 30px rgba(40,46,62,.08)\"><h1 style=\"margin:0 0 10px;font-size:23px\">%s</h1>"
                    "<p style=\"color:#586380;line-height:1.6\">%s</p><a href=\"/\" style=\"color:#6C5CAC;font-weight:700\">Открыть EPDeutsch</a></div></body>") % (
                ("Готово, ты отписан(а)", "Напоминания и новости больше не придут. Письма о твоём аккаунте, например для сброса пароля, приходить будут. Включить рассылку снова можно в профиле.")
                if ok else ("Ссылка не сработала", "Отписаться можно в профиле на сайте."))
            b = body.encode()
            self.send_response(200)
            self.send_header("Content-Type", "text/html; charset=utf-8")
            self.send_header("Content-Length", str(len(b)))
            self.end_headers()
            self.wfile.write(b)
            return

        if method == "POST" and p == "/register":
            d = self.read_json()
            name, email, pw = s_str(d.get("name"), 40), s_str(d.get("email"), 254).lower(), str(d.get("password") or "")
            if not name:
                raise ApiError(400, "Введи имя")
            if not EMAIL_RE.match(email):
                raise ApiError(400, "Проверь адрес почты")
            bad = pw_problem(pw)
            if bad:
                raise ApiError(400, bad)
            if d.get("accept") is not True:
                raise ApiError(400, "Чтобы создать аккаунт, прими условия использования")
            if c.execute("SELECT 1 FROM users WHERE email=?", (email,)).fetchone():
                raise ApiError(409, "Аккаунт с этой почтой уже есть, просто войди")
            cur = c.execute("INSERT INTO users(email,name,avatar,pw,code,created,news) VALUES(?,?,?,?,?,?,?)",
                            (email, name, secrets.randbelow(8), hash_pw(pw), new_code(c, "users", "code", 6), now, 1))
            uid = cur.lastrowid
            ensure_owner(c)
            base = self.mail_base()
            if base:
                send_welcome(c, uid, name, email, base)
            c.execute("INSERT INTO stats(user_id,updated) VALUES(?,?)", (uid, now))
            cookie = self.start_session(c, uid)
            u = c.execute("SELECT * FROM users WHERE id=?", (uid,)).fetchone()
            return self.send_json(200, {"user": user_json(u), "progress": None, "updated": 0}, cookie)

        if method == "POST" and p == "/login":
            d = self.read_json()
            email, pw = s_str(d.get("email"), 254).lower(), str(d.get("password") or "")
            key = self.client_ip() + "|" + email
            if too_many(key):
                raise ApiError(429, "Слишком много попыток. Подожди 10 минут")
            u = c.execute("SELECT * FROM users WHERE email=?", (email,)).fetchone()
            if not u or not check_pw(pw, u["pw"]):
                FAILS.setdefault(key, []).append(time.time())
                raise ApiError(401, "Неверная почта или пароль")
            FAILS.pop(key, None)
            cookie = self.start_session(c, u["id"])
            pr = c.execute("SELECT data,updated FROM progress WHERE user_id=?", (u["id"],)).fetchone()
            return self.send_json(200, {"user": user_json(u),
                                        "progress": json.loads(pr["data"]) if pr else None,
                                        "updated": pr["updated"] if pr else 0}, cookie)

        if method == "POST" and p == "/forgot":
            # ответ всегда одинаковый, чтобы по нему нельзя было узнать, есть ли такой аккаунт
            email = s_str(self.read_json().get("email"), 254).lower()
            key = self.client_ip() + "|forgot"
            if too_many(key):
                raise ApiError(429, "Слишком много попыток. Подожди 10 минут")
            FAILS.setdefault(key, []).append(time.time())
            if not EMAIL_RE.match(email):
                raise ApiError(400, "Проверь адрес почты")
            u = c.execute("SELECT id,name FROM users WHERE email=?", (email,)).fetchone()
            base = self.mail_base()
            if u and base:
                send_reset(c, u["id"], u["name"], email, base)
            elif u:
                sys.stderr.write("Сброс пароля для %s не отправлен: задай SITE_URL, чтобы ссылка в письме вела на твой сайт\n" % email)
            return self.send_json(200, {"ok": True})

        if method == "POST" and p == "/reset":
            d = self.read_json()
            tok, pw = s_str(d.get("token"), 100), str(d.get("password") or "")
            r = c.execute("SELECT user_id FROM resets WHERE token=? AND expires>?", (sha(tok), now)).fetchone()
            if not r:
                raise ApiError(400, "Ссылка устарела или уже использована. Запроси новую")
            bad = pw_problem(pw)
            if bad:
                raise ApiError(400, bad)
            uid = r["user_id"]
            c.execute("UPDATE users SET pw=? WHERE id=?", (hash_pw(pw), uid))
            c.execute("DELETE FROM resets WHERE user_id=?", (uid,))
            c.execute("DELETE FROM sessions WHERE user_id=?", (uid,))  # старые входы на других устройствах закрываем
            cookie = self.start_session(c, uid)
            u = c.execute("SELECT * FROM users WHERE id=?", (uid,)).fetchone()
            pr = c.execute("SELECT data,updated FROM progress WHERE user_id=?", (uid,)).fetchone()
            return self.send_json(200, {"user": user_json(u),
                                        "progress": json.loads(pr["data"]) if pr else None,
                                        "updated": pr["updated"] if pr else 0}, cookie)

        if method == "POST" and p == "/logout":
            ck = SimpleCookie(self.headers.get("Cookie") or "")
            if "sid" in ck:
                c.execute("DELETE FROM sessions WHERE token=?", (sha(ck["sid"].value),))
            return self.send_json(200, {"ok": True}, self.session_cookie("", 0))

        if method == "PUT" and p == "/progress":
            u = self.need_user(c)
            d = self.read_json()
            data = d.get("data")
            if not isinstance(data, dict):
                raise ApiError(400, "Нет данных прогресса")
            c.execute("INSERT INTO progress(user_id,data,updated) VALUES(?,?,?) "
                      "ON CONFLICT(user_id) DO UPDATE SET data=excluded.data, updated=excluded.updated",
                      (u["id"], json.dumps(data, ensure_ascii=False), now))
            st = d.get("stats") or {}
            c.execute("""INSERT INTO stats(user_id,xp,week_xp,streak,learned,acc,today,level,updated) VALUES(?,?,?,?,?,?,?,?,?)
                         ON CONFLICT(user_id) DO UPDATE SET xp=excluded.xp,week_xp=excluded.week_xp,streak=excluded.streak,
                         learned=excluded.learned,acc=excluded.acc,today=excluded.today,level=excluded.level,updated=excluded.updated""",
                      (u["id"], s_int(st.get("xp")), s_int(st.get("week_xp")), s_int(st.get("streak"), 0, 100000),
                       s_int(st.get("learned"), 0, 100000), s_int(st.get("acc"), 0, 100), s_int(st.get("today"), 0, 100000),
                       s_int(st.get("level"), 1, 10000), now))
            return self.send_json(200, {"ok": True, "updated": now})

        if method == "PATCH" and p == "/profile":
            u = self.need_user(c)
            d = self.read_json()
            name = s_str(d.get("name"), 40) or u["name"]
            avatar = s_int(d.get("avatar", u["avatar"]), 0, 7)
            news = (1 if d["news"] is True else 0) if "news" in d else u["news"]
            c.execute("UPDATE users SET name=?, avatar=?, news=? WHERE id=?", (name, avatar, news, u["id"]))
            u = c.execute("SELECT * FROM users WHERE id=?", (u["id"],)).fetchone()
            return self.send_json(200, {"user": user_json(u)})

        if method == "DELETE" and p == "/account":
            # удаление аккаунта: нужен пароль; вместе с пользователем удаляются прогресс, статистика, друзья, общие наборы и сессии
            u = self.need_user(c)
            if u["role"] == "owner":
                raise ApiError(400, "Владельца удалить нельзя. Сначала передай права владельца в админке")
            pw = str(self.read_json().get("password") or "")
            key = self.client_ip() + "|del|" + str(u["id"])
            if too_many(key):
                raise ApiError(429, "Слишком много попыток. Подожди 10 минут")
            if not check_pw(pw, u["pw"]):
                FAILS.setdefault(key, []).append(time.time())
                raise ApiError(401, "Неверный пароль")
            delete_user(c, u["id"])
            FAILS.pop(key, None)
            return self.send_json(200, {"ok": True}, self.session_cookie("", 0))

        if method == "GET" and p == "/friends":
            u = self.need_user(c)
            return self.send_json(200, {"friends": friend_rows(c, u["id"])})

        if method == "POST" and p == "/friends":
            u = self.need_user(c)
            code = s_str(self.read_json().get("code"), 12).upper().replace(" ", "")
            f = c.execute("SELECT id,name FROM users WHERE code=?", (code,)).fetchone()
            if not f:
                raise ApiError(404, "Такого кода нет. Проверь его у друга")
            if f["id"] == u["id"]:
                raise ApiError(400, "Это твой собственный код")
            for a, b in ((u["id"], f["id"]), (f["id"], u["id"])):
                c.execute("INSERT OR IGNORE INTO friends(user_id,friend_id,created) VALUES(?,?,?)", (a, b, now))
            return self.send_json(200, {"ok": True, "name": f["name"]})

        m = re.fullmatch(r"/friends/(\d+)", p)
        if method == "DELETE" and m:
            u = self.need_user(c)
            fid = int(m.group(1))
            c.execute("DELETE FROM friends WHERE (user_id=? AND friend_id=?) OR (user_id=? AND friend_id=?)",
                      (u["id"], fid, fid, u["id"]))
            return self.send_json(200, {"ok": True})

        if method == "POST" and p == "/share":
            u = self.need_user(c)
            d = self.read_json()
            name = s_str(d.get("name"), 80) or "Набор"
            cards = []
            for x in (d.get("cards") or [])[:2000]:
                if isinstance(x, dict):
                    de, ru = s_str(x.get("de"), 300), s_str(x.get("ru"), 300)
                    if de and ru:
                        cards.append({"de": de, "ru": ru, "ex": s_str(x.get("ex"), 600)})
            if not cards:
                raise ApiError(400, "В наборе нет карточек")
            code = new_code(c, "shares", "code", 8)
            c.execute("INSERT INTO shares(code,owner_id,name,descr,cards,created) VALUES(?,?,?,?,?,?)",
                      (code, u["id"], name, s_str(d.get("desc"), 200), json.dumps(cards, ensure_ascii=False), now))
            return self.send_json(200, {"code": code})

        if method == "POST" and p == "/translate":
            d = self.read_json()
            text, sl, tl = s_str(d.get("text"), 300), s_str(d.get("from"), 2), s_str(d.get("to"), 2)
            if sl not in ("de", "ru") or tl not in ("de", "ru") or sl == tl:
                raise ApiError(400, "Неверная пара языков")
            if not text:
                raise ApiError(400, "Пустой текст")
            row = c.execute("SELECT out,prov FROM translations WHERE src=? AND sl=? AND tl=?", (text, sl, tl)).fetchone()
            if row:
                return self.send_json(200, {"text": row["out"], "provider": row["prov"]})
            if tr_limited(self.client_ip()):
                raise ApiError(429, "Слишком много переводов подряд, подожди минуту")
            try:
                out, prov = translate(text, sl, tl)
            except Exception as e:  # noqa: BLE001
                sys.stderr.write("translate error: %r\n" % e)
                raise ApiError(502, "Переводчик сейчас недоступен")
            c.execute("INSERT OR REPLACE INTO translations(src,sl,tl,out,prov) VALUES(?,?,?,?,?)", (text, sl, tl, out, prov))
            return self.send_json(200, {"text": out, "provider": prov})

        m = re.fullmatch(r"/share/([A-Z0-9]{4,12})", p)
        if method == "GET" and m:
            r = c.execute("SELECT s.*, u.name AS owner FROM shares s JOIN users u ON u.id=s.owner_id WHERE s.code=?",
                          (m.group(1),)).fetchone()
            if not r:
                raise ApiError(404, "Набор не найден. Возможно, ссылка неполная")
            return self.send_json(200, {"name": r["name"], "desc": r["descr"], "owner": r["owner"], "cards": json.loads(r["cards"])})

        # ---------- аналитика: просмотр страницы и «я на сайте» ----------
        if method == "POST" and p in ("/hit", "/ping"):
            ua = self.headers.get("User-Agent") or ""
            if BOT_RE.search(ua):
                return self.send_json(200, {"ok": True})
            d = self.read_json()
            u = self.current_user(c)
            uid = u["id"] if u else None
            vkey = visitor_key(self.client_ip(), ua)
            dev, os_, br = parse_ua(ua)
            tz, tab, what = s_str(d.get("tz"), 64), s_str(d.get("tab"), 20), s_str(d.get("what"), 90)
            if p == "/hit":
                ref = s_str(d.get("ref"), 300)
                host = urlparse(ref).netloc.lower() if ref.startswith("http") else ""
                if host.startswith("www."):
                    host = host[4:]
                me = (self.headers.get("Host") or "").split(":")[0].lower()
                if host and me and host.split(":")[0] == me:
                    host = ""
                c.execute("INSERT INTO hits(ts,day,vkey,uid,path,ref,device,os,browser,tz,lang) VALUES(?,?,?,?,?,?,?,?,?,?,?)",
                          (now, local_day(now), vkey, uid, s_str(d.get("path"), 60) or "/", host, dev, os_, br, tz,
                           s_str(d.get("lang"), 12)))
            c.execute("INSERT INTO presence(vkey,uid,ts,tab,device,tz,what) VALUES(?,?,?,?,?,?,?) ON CONFLICT(vkey) DO UPDATE SET "
                      "uid=excluded.uid,ts=excluded.ts,tab=excluded.tab,device=excluded.device,tz=excluded.tz,what=excluded.what",
                      (vkey, uid, now, tab, dev, tz, what if uid else ""))
            if u:
                c.execute("UPDATE users SET last_seen=?, tz=COALESCE(NULLIF(?,''),tz), device=?, lang=COALESCE(NULLIF(?,''),lang) WHERE id=?",
                          (now, tz, dev + " · " + os_, s_str(d.get("lang"), 12), uid))
            return self.send_json(200, {"ok": True})

        # ---------- админка (только для владельца сайта) ----------
        if method == "GET" and p.startswith("/admin/"):
            self.need_admin(c)
            q = urllib.parse.parse_qs(urlparse(self.path).query)
            if p == "/admin/overview":
                return self.send_json(200, admin_overview(c, s_int((q.get("days") or ["30"])[0], 7, 365)))
            if p == "/admin/users":
                return self.send_json(200, {"users": admin_users(c)})
            m = re.fullmatch(r"/admin/user/(\d+)", p)
            if m:
                return self.send_json(200, admin_user(c, int(m.group(1))))

        if method in ("POST", "DELETE") and p.startswith("/admin/user/"):
            me = self.need_owner(c)  # удалять, менять роли и передавать права может только владелец
            m = re.fullmatch(r"/admin/user/(\d+)(/reset|/role|/owner)?", p)
            if not m:
                raise ApiError(404, "Не найдено")
            u = c.execute("SELECT * FROM users WHERE id=?", (int(m.group(1)),)).fetchone()
            if not u:
                raise ApiError(404, "Пользователь не найден")
            act = m.group(2)
            if method == "POST" and act == "/reset":
                send_reset(c, u["id"], u["name"], u["email"], SITE_URL or self.site_url())
                return self.send_json(200, {"ok": True, "mail": bool(smtp_conf())})
            if method == "POST" and act == "/role":
                role = s_str(self.read_json().get("role"), 10)
                if role not in ("admin", "user"):
                    raise ApiError(400, "Такой роли нет")
                if u["role"] == "owner":
                    raise ApiError(400, "Роль владельца меняется только передачей прав")
                c.execute("UPDATE users SET role=? WHERE id=?", (role, u["id"]))
                return self.send_json(200, {"ok": True})
            if method == "POST" and act == "/owner":
                if u["id"] == me["id"]:
                    raise ApiError(400, "Ты уже владелец")
                if not check_pw(str(self.read_json().get("password") or ""), me["pw"]):
                    raise ApiError(401, "Неверный пароль")
                c.execute("UPDATE users SET role='owner' WHERE id=?", (u["id"],))
                c.execute("UPDATE users SET role='admin' WHERE id=?", (me["id"],))
                sys.stderr.write("Права владельца переданы: %s -> %s\n" % (me["email"], u["email"]))
                return self.send_json(200, {"ok": True})
            if method == "DELETE" and not act:
                if u["role"] == "owner" or u["id"] == me["id"]:
                    raise ApiError(400, "Владельца удалить нельзя")
                if s_str(self.read_json().get("email"), 254).lower() != u["email"].lower():
                    raise ApiError(400, "Для подтверждения впиши почту пользователя")
                delete_user(c, u["id"])
                sys.stderr.write("Владелец %s удалил аккаунт %s\n" % (me["email"], u["email"]))
                return self.send_json(200, {"ok": True})

        raise ApiError(404, "Не найдено")


if __name__ == "__main__":
    init_db()
    threading.Thread(target=reminder_loop, daemon=True).start()
    srv = ThreadingHTTPServer(("127.0.0.1", PORT), Handler)
    print(f"EPDeutsch: http://localhost:{PORT}  (база: {DB})")
    try:
        srv.serve_forever()
    except KeyboardInterrupt:
        pass
