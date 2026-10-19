# Инвентаризация: что есть в Python-версии и что с этим делает PHP-версия

Источники: `server.py` (2018 строк), `lernen_api.py` (593), `../EPSchreiben/schreiben_api.py` (381, лежит в соседнем репозитории и в PHP-версию копируется).
Метка в колонке «PHP»: **=** переносится без изменений ответа, **A/B/C** — меняется по пунктам задачи (A — регистрация без почты, B — пароли, C — админка), **−** убрано, **~** меняется из-за хостинга.

Общие правила, которые сохраняются: все не-GET запросы к `/api/*` требуют заголовок `X-USD: 1` (защита от CSRF); ответы JSON `{"error": "..."}` с HTTP-кодом при ошибке; сессия — cookie `sid` (HttpOnly, SameSite=Lax, 30 дней), в базе хранится SHA-256 токена; лимит неудачных входов 8 за 10 минут.

## 1. Эндпоинты `server.py`

| Метод | Путь | Что делает | Таблицы | PHP |
|---|---|---|---|---|
| GET | `/api/me` | текущий пользователь + прогресс Wörter; поле `mail` — настроена ли почта | sessions, users, progress | **A**: `mail` всегда `false`, `verified` всегда `true`; **C**: в `user` добавлен `must_change_password` |
| POST | `/api/verify` | подтверждение почты по токену | verifies, users | **A: удалён** (отвечает 410 «подтверждение почты отключено») |
| POST | `/api/verify/resend` | повторное письмо с подтверждением | verifies | **A: удалён** (410) |
| GET | `/api/unsub` | отписка от рассылки по подписанной ссылке (HMAC от `db/mail.key`) | users.news | **=** (работает без почты: ссылки из старых писем остаются рабочими; ключ HMAC берётся из конфига `mail_secret`) |
| POST | `/api/register` | регистрация: имя, почта, пароль (≥8 символов, заглавная), код приглашения; первый пользователь — владелец | users, stats, invites, verifies, sessions | **A**: сразу `verified=1`, письмо не шлётся, `verifies` не трогается; **A**: лимит регистраций по IP (3 в час, таблица `reg_attempts`), проверка формата почты; **B**: `password_hash` |
| POST | `/api/login` | вход по почте и паролю | users, sessions | **B**: проверка Argon2id/bcrypt или старого PBKDF2 с перехешированием; **C**: блокировка входа (`users.blocked`), флаг `must_change_password` |
| POST | `/api/forgot` | письмо со ссылкой сброса пароля | resets, users | **A/C: удалён** (410: «восстановление только через администратора») |
| POST | `/api/reset` | установка нового пароля по токену из письма | resets, users, sessions | **A/C: удалён** (410). Таблица `resets` в MySQL не переносится |
| POST | `/api/logout` | выход, удаление сессии | sessions | **=** |
| PUT | `/api/progress` | сохранение прогресса Wörter + статистика (xp, streak …) | progress, stats | **=** (`ON CONFLICT` → `ON DUPLICATE KEY UPDATE`) |
| PATCH | `/api/profile` | имя, аватар, рассылка, язык интерфейса | users | **=** |
| **POST** | `/api/password` | **новый:** смена своего пароля (нужен текущий; обязателен после сброса админом) | users, sessions | **B/C: новый** |
| DELETE | `/api/account` | удаление своего аккаунта с паролем | много таблиц (`delete_user`) | **=**, **B**: проверка нового формата хеша |
| GET/POST | `/api/friends` | список друзей со статистикой / добавить по коду | friends, users, stats, schreiben_* | **=** |
| DELETE | `/api/friends/{id}` | убрать друга | friends | **=** |
| GET/POST | `/api/races` | дружеские забеги | races, stats, schreiben_* | **=** |
| DELETE | `/api/races/{id}` | отменить забег | races | **=** |
| GET/POST/DELETE | `/api/words` | «почтовый ящик» слов, сохранённых в других модулях | word_inbox | **=** |
| POST | `/api/share` | опубликовать набор карточек по коду | shares | **=** |
| GET | `/api/share/{code}` | открыть чужой набор | shares, users | **=** |
| POST | `/api/translate` | перевод (DeepL, иначе MyMemory), кэш | translations | **=**, ИИ/внешний API (см. §4) |
| POST | `/api/article` | артикль существительного (de.wiktionary.org), кэш в `translations` (tl=`gn`) | translations | **=** |
| POST | `/api/practice/check` | проверка предложения: LanguageTool + Claude Haiku, кэш | ai_cache, ai_log | **=**, **~** таймаут (см. §4) |
| GET | `/api/regopen` | регистрация открыта, пока нет ни одного пользователя | users | **=** |
| POST | `/api/hit`, `/api/ping` | аналитика посещений и «онлайн» | hits, presence, users | **=** |
| GET | `/api/admin/overview?days=` | сводка для админки | почти все | **=** |
| GET | `/api/admin/users` | список пользователей | users, progress, stats, schreiben_* | **C**: добавлены дата регистрации, последний вход, блокировка, `must_change_password`; пароль/хеш не отдаётся никогда |
| GET | `/api/admin/user/{id}` | карточка пользователя | то же | **C** |
| POST | `/api/admin/user/{id}/reset` | **было:** письмо со ссылкой сброса | resets | **C: меняется** — генерирует временный пароль 12+ символов, показывает один раз, `must_change_password=1`, закрывает сессии, пишет в `audit_log` |
| POST | `/api/admin/user/{id}/role` | роль admin/user (только владелец) | users | **=**, **C**: запись в `audit_log` |
| POST | `/api/admin/user/{id}/owner` | передача прав владельца (нужен пароль владельца) | users | **=**, **B**: проверка пароля новым хешем, `audit_log` |
| DELETE | `/api/admin/user/{id}` | удалить аккаунт (нужно впечатать почту) | много таблиц | **=**, `audit_log` |
| POST | `/api/admin/user/{id}/plan` | сменить план (free/premium) | users.plan, plan_until | **C: новый** |
| POST | `/api/admin/user/{id}/block` | заблокировать/разблокировать вход | users.blocked, sessions | **C: новый** |
| GET | `/api/admin/audit` | журнал действий админа | audit_log | **C: новый** |

### Статика и страницы (`Handler.route_static`)
| Путь | Что | PHP |
|---|---|---|
| `/` | `start.html` (вход, выбор языка) | **=** |
| `/de` | `de.html` | **=** |
| `/woerter` | `index.html`, из него вынимается словарь BOOK в `/assets/book.<хэш>.js` | **=** (PHP делает ту же нарезку, кэш в `storage/cache`) |
| `/index.html`, `/en`, `/epd`, `/terms`, `/privacy`, `/start`, `/admin` | 301/302 на `/` | **=**, кроме `/admin`: отдаётся `admin.html` (иначе адаптированную админку нельзя открыть; см. «Открытые вопросы») |
| `/schreiben`, `/data/schreiben/*.json` | модуль Schreiben | **=** (файлы скопированы из `../EPSchreiben`) |
| `/data/(de|frei)/*.json` | данные модулей | **=** (отдаются Apache напрямую) |
| `/audio/<hash>.mp3` | кэш озвучки с поддержкой Range | **=** (PHP-раздача из `storage/audio`, Range и кэш сохранены) |
| `/ep.js`, `/mail/*.png`, `/icon*.png`, `/og.png`, `/icon.svg`, `/apple-touch-icon.png` | статика | **=** |
| `/robots.txt`, `/sitemap.xml`, `/manifest.webmanifest` | генерируются | **=** |
| `/assets/book.js` | 302 на актуальный хэш | **=** |

## 2. Эндпоинты `lernen_api.py` (`/api/de/*`, все требуют входа)

| Метод | Путь | Что делает | Таблицы / внешние сервисы | PHP |
|---|---|---|---|---|
| GET | `/api/de/state` | весь прогресс модулей + статус ключей | kv | **=** |
| PUT | `/api/de/state/{k}` | записать ключ прогресса (≤400 КБ) | kv | **=** |
| POST | `/api/de/ctx` | перевод слова в контексте: DeepL/MyMemory + Claude Haiku | translations, ai_cache, ai_log; **DeepL, Anthropic** | **=** |
| POST | `/api/de/check` | проверка ответа в упражнении (Haiku) | ai_cache, ai_log; **Anthropic** | **=** |
| POST | `/api/de/aitasks` | 3 задания от ИИ по теме (Sonnet) | ai_cache, ai_log; **Anthropic** | **~** самый длинный запрос, см. §4 |
| POST | `/api/de/gwrite` | проверка письма по грамматике (Sonnet) | ai_cache, ai_log; **Anthropic** | **~** |
| POST | `/api/de/tts` | озвучка текста, mp3 в кэш | ai_log; **OpenAI TTS** | **=** (кэш в `storage/audio`) |
| POST | `/api/de/audio/{area}/{id}` | озвучка сценария Hören из нескольких реплик, склейка mp3 | ai_log; **OpenAI TTS** | **~** много реплик — несколько вызовов подряд; см. §4 |
| POST | `/api/de/stt` | распознавание речи (base64 до 12 МБ) | ai_log; **OpenAI** | **~** лимит `post_max_size`/`upload` на хостинге |
| POST | `/api/de/talk` | разбор устного ответа и реплика экзаменатора (Sonnet) | ai_log; **Anthropic** | **~** timeout 120 с в Python |
| GET | `/api/de/settings` | статус ключей, расходы, приглашения (для владельца) | ai_log, invites | **=** |
| POST | `/api/de/settings/key` | владелец записывает ключ в `db/*.key` | файл | **~/B** на хостинге ключи лежат в `config/config.local.php`; эндпоинт отвечает 409 «задай ключ в config.local.php», значения ключей никогда не возвращаются |
| POST | `/api/de/invites` | создать код приглашения (владелец) | invites | **=** |
| DELETE | `/api/de/invites/{code}` | удалить неиспользованное приглашение | invites | **=** |

Эндпоинтов «личный словарь и план» (`personal_vocab`, `personal_plan`, `personal_attempts`, `personal_settings`, `personal_usage`) **в коде `main` нет**: таблицы есть только в живой базе (пустые, 0 строк; код остался в неслитой ветке). В PHP-версии таблицы переносятся в схему и в скрипт миграции, а HTTP-эндпоинтов для них нет — отметить в отчёте.

## 3. Эндпоинты Schreiben (`schreiben_api.py`, `/api/schreiben/*`)

| Метод | Путь | Что делает | Таблицы / сервисы | PHP |
|---|---|---|---|---|
| GET | `/api/schreiben/state` | состояние + список проверенных текстов (200) | schreiben_state, schreiben_essays | **=** |
| PUT | `/api/schreiben/state` | сохранить состояние (≤1 МБ) | schreiben_state | **=** (`INSERT OR REPLACE` → `REPLACE`/`ON DUPLICATE`) |
| GET | `/api/schreiben/essay/{id}` | один текст с разбором | schreiben_essays | **=** |
| DELETE | `/api/schreiben/essay/{id}` | удалить текст | schreiben_essays | **=** |
| POST | `/api/schreiben/grade` | оценка полного текста по шкале VWU (Sonnet, effort=medium, до 150 с) | schreiben_essays, ai_log; **Anthropic** | **~** главный риск таймаута, см. §4 |
| POST | `/api/schreiben/satz` | проверка Satzfortsetzung (Haiku) | ai_log; **Anthropic** | **=** |
| POST | `/api/schreiben/generate` | новое задание (Haiku) | ai_log; **Anthropic** | **=** |

## 4. ИИ и внешние сервисы

| Где | Что | Модель/сервис | Риск на InfinityFree |
|---|---|---|---|
| `practice_check` | проверка предложения | LanguageTool + Haiku 4.5, кэш `ai_cache` | короткий ответ; кэш по хэшу |
| `lernen /ctx`, `/check` | слово в контексте, проверка упражнения | Haiku 5.5 effort=low, DeepL | короткие ответы |
| `lernen /aitasks`, `/gwrite` | задания и письмо по грамматике | Sonnet 5.5, max_tokens 1500 | средний; кэш |
| `lernen /talk` | разбор речи | Sonnet 5.5, max_tokens 4000, effort=medium, timeout 120 | **высокий** |
| `lernen /tts`, `/audio/*` | озвучка | OpenAI gpt-4o-mini-tts | сценарий из N реплик = N запросов: делается по одной реплике за вызов (см. deploy) |
| `lernen /stt` | речь→текст | OpenAI gpt-4o-transcribe | размер тела запроса |
| `schreiben /grade` | оценка текста | Sonnet 5.5, effort=medium, max_tokens 16000, timeout 150 | **самый высокий**: бесплатный PHP обрывает скрипт примерно на 30–60 с (точное значение хостинг не раскрывает, проверить после выгрузки) |
| `schreiben /satz`, `/generate` | мини-проверки | Haiku | низкий |
| `translate` | перевод | DeepL (ключ) либо MyMemory | низкий |
| `wiki_gender` | артикль | de.wiktionary.org | низкий |
| `ai_guard` | предохранитель: 30 запросов/мин и 450/день на аккаунт, $3/день на сайт | `ai_log` | минутный лимит хранился в памяти процесса — в PHP считается по `ai_log` |

Что делает PHP-версия для устойчивости: единый класс `Claude` с ограничением по времени (`ai_timeout`, по умолчанию 25 с, настраивается), `set_time_limit` там, где хостинг разрешает, одна повторная попытка только при быстрых 429/5xx, при таймауте — понятная ошибка 504 «ИИ не успел ответить, повтори — частичные результаты кэшируются», запись в `ai_log`, `max_tokens` для `grade` ограничен (по умолчанию 6000) конфигом.

## 5. Почта

| Где | Что | Решение |
|---|---|---|
| `send_welcome` (при регистрации и `/verify/resend`) | письмо с подтверждением | **A: удалено** |
| `send_reset` (`/forgot`, админка «сбросить пароль») | письмо со ссылкой сброса | **A/C: удалено**, заменено временным паролем админа |
| `reminder_loop` + `nudge_email` | напоминания тем, кто не учился 3–30 дней; запускается только при `REMINDERS=1` | **Не переносится** (без почты бессмысленно; в личной версии и так выключено). Не завязано на подтверждение, но шлёт только при `users.verified=1 AND news=1`; после A все `verified=1`. Если понадобятся новости, нужен внешний почтовый провайдер (см. отчёт) |
| `send_mail` / `smtp_conf` | SMTP-отправка | не переносится (на бесплатном тарифе нет `mail()` и, вероятно, исходящего SMTP) |
| `/api/unsub` | отписка по ссылке из письма | оставлена |
| `ensure_owner` | первый пользователь становится владельцем | оставлена |

## 6. Фоновая логика → «ленивая»

| Что было | Как |
|---|---|
| `init_db`: удаление просроченных сессий/resets/verifies; `hits` старше 400 дней, `presence` старше суток, `ai_log` старше 400 дней, `ai_cache` старше 30 дней | `Maintenance::run()` вызывается при ~1 из 50 запросов (и не чаще раза в час, метка в файле `storage/maintenance.stamp`) |
| `finish_races` (закрепление итогов XP-забегов) | вызывается при чтении `/api/races` и `/api/friends`, как и раньше |
| `reminder_loop` | не переносится |
| `TZ_LOCAL` (Europe/Vienna) для «локального дня» | `DateTimeZone('Europe/Vienna')` |
| Хранение в памяти: `FAILS` (неудачные входы), `TR_HITS` (лимит переводов), `AI_HITS` (минутный лимит ИИ), `_salts` (соль посетителя) | PHP не держит память между запросами: теперь таблица `rate_limit(bucket, ts)`; соль посетителя — `HMAC(secret, день)` |

## 7. Таблицы

Переносятся (с данными): users, sessions, progress, stats, friends, shares, translations, hits, presence, ai_log, ai_cache, schreiben_state, schreiben_essays, races, word_inbox, kv, invites, personal_settings, personal_plan, personal_attempts, personal_usage, personal_vocab.

**Не переносятся:** `verifies` (A), `resets` (A/C).

**Новые:** `audit_log` (C), `rate_limit` (замена памяти процесса), `reg_attempts` (лимит регистраций по IP).

**Новые колонки `users`:** `must_change_password` (C), `blocked` (C, блокировка входа), `pw_algo` (метка формата хеша, для отладки; необязательна).

Триггеры `owner_no_delete`, `owner_keep_role` переносятся на MySQL через `SIGNAL SQLSTATE '45000'`; в PHP те же проверки дублируются (на случай, если на хостинге нет права `TRIGGER`).

## 8. Диалекты SQL, которые меняются

- `INSERT OR REPLACE` → `REPLACE INTO` / `ON DUPLICATE KEY UPDATE` (ai_cache, translations, schreiben_state).
- `INSERT OR IGNORE` → `INSERT IGNORE` (friends).
- `ON CONFLICT(...) DO UPDATE SET x=excluded.x` → `ON DUPLICATE KEY UPDATE x=VALUES(x)` (progress, stats, kv, presence).
- `lastrowid` → `PDO::lastInsertId()`.
- `lower(email)=?` остаётся; `COUNT(*) ... SUM(ts>?)` работает как есть; `points*1.0/max_points` → `points/max_points`.
- `strftime`: в коде не используется (даты считаются в Python), вызовов нет. Колонка `personal_usage.day` — TEXT с датой `YYYY-MM-DD`.
- Зарезервированные слова в MySQL: `key`, `out`, `end`, `start`, `desc` — в запросах берутся в обратные кавычки.
- Составной первичный ключ `translations(src, sl, tl)`: `src` до 1500 символов не помещается в ключ, поэтому ключ — `(src_hash, sl, tl)` (SHA-1 от текста), а `src` хранится как есть.

## 9. Что в итоге отличается от Python-версии

| Что | Как было | Как стало и почему |
|---|---|---|
| Регистрация | письмо с подтверждением, `verified=0` до перехода по ссылке | сразу `verified=1`; лимит регистраций с одного IP (3 в час, настраивается), проверка формата почты (A) |
| `/api/verify`, `/api/verify/resend`, `/api/forgot`, `/api/reset` | рабочие | отвечают 410 (A, C); страницы и тексты «Забыл пароль» переписаны: восстановление только через админа |
| Пароли | PBKDF2 310 000 итераций, формат `соль$хеш` | `password_hash` Argon2id (или bcrypt); старые хеши проверяются прежним алгоритмом и заменяются при входе (B) |
| `/api/me` | `mail` = настроена ли почта | `mail` всегда `false`; `user.verified` всегда `true`; в `user` добавлено `must_change_password` |
| Смена пароля | только через письмо | `POST /api/password` (нужен текущий пароль); после админского сброса обязательна, остальной API закрыт кодом 403 `must_change_password` |
| Админка | сброс = письмо со ссылкой; роли, удаление, передача прав | сброс = временный пароль (показан один раз); добавлены план, блокировка входа, журнал `audit_log`; админ (не только владелец) может сбрасывать пароли, блокировать и менять план обычных пользователей; роли, удаление и передача прав остались у владельца |
| Блокировка | не было | `users.blocked`: вход запрещён, сессии закрываются |
| `/admin` | редирект на `/` (страница не раздавалась) | отдаётся `admin.html` |
| `hub.html`, `epmathe-demo.html` | не раздавались | не раздаются (файлы лежат в `public/`, прямой доступ закрыт .htaccess) |
| Ключи API | файлы `db/*.key`, правятся из настроек владельцем | только `config/config.local.php`; `POST /api/de/settings/key` отвечает 409 |
| `talk` (разбор речи) | effort=medium, max_tokens 4000 | effort=low, max_tokens 2500 (настраивается `talk_max_tokens`): иначе не укладывается во время запроса |
| `schreiben/grade` | max_tokens 16000, timeout 150 с | max_tokens 6000 (`grade_max_tokens`), `grade_effort` настраивается, ожидание ограничено `ai_timeout` (28 с по умолчанию) |
| Озвучка сценария Hören | один длинный запрос | по частям с `pending: true`; страница (`de.html`) повторяет запрос сама |
| Лимиты (входы, переводы, ИИ в минуту), соль посетителя | память процесса | таблица `rate_limit` и HMAC от секрета |
| Фоновые задачи | поток-напоминалка и чистка при старте | чистка «лениво» (`Maintenance`), напоминания не переносятся |
| Personal-таблицы | таблицы есть, кода нет | то же: таблицы и данные переносятся, эндпоинтов нет |
| Старые Python-файлы | рабочие | остались в корне как справочные (`server.py`, `lernen_api.py`, `tools/`); из-за переноса страниц в `public/` на этой ветке они больше не запустятся без правки путей |
