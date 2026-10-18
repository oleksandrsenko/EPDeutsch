-- EPDeutsch: схема MySQL / MariaDB (InnoDB, utf8mb4). Импорт: phpMyAdmin → Импорт → этот файл.
-- Время везде хранится целым числом (секунды Unix), как в SQLite, чтобы данные переносились без преобразований.
-- Таблицы verifies и resets НЕ переносятся (регистрация без подтверждения почты, пароль сбрасывает админ).
SET NAMES utf8mb4;

CREATE TABLE IF NOT EXISTS users(
  id BIGINT AUTO_INCREMENT PRIMARY KEY,
  email VARCHAR(190) NOT NULL,
  name VARCHAR(120) NOT NULL,
  avatar INT NOT NULL DEFAULT 0,
  pw VARCHAR(255) NOT NULL,                 -- password_hash() (Argon2id/bcrypt) или старый PBKDF2 "соль$хеш" до первого входа
  code VARCHAR(16) NOT NULL,
  created BIGINT NOT NULL,
  last_seen BIGINT NULL,
  tz VARCHAR(64) NULL,
  device VARCHAR(120) NULL,
  lang VARCHAR(16) NULL,
  news TINYINT NOT NULL DEFAULT 0,
  plan VARCHAR(16) NOT NULL DEFAULT 'free',
  plan_until BIGINT NULL,
  role VARCHAR(10) NOT NULL DEFAULT 'user',
  verified TINYINT NOT NULL DEFAULT 1,      -- всегда 1: подтверждения почты нет
  last_nudge BIGINT NULL,
  ui_lang VARCHAR(4) NOT NULL DEFAULT 'ru',
  must_change_password TINYINT NOT NULL DEFAULT 0,
  blocked TINYINT NOT NULL DEFAULT 0,       -- 1 = вход заблокирован админом
  UNIQUE KEY users_email (email),
  UNIQUE KEY users_code (code)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS sessions(
  token CHAR(64) NOT NULL PRIMARY KEY,      -- SHA-256 от токена из cookie
  user_id BIGINT NOT NULL,
  expires BIGINT NOT NULL,
  KEY sessions_user (user_id),
  KEY sessions_exp (expires),
  CONSTRAINT fk_sessions_user FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS progress(
  user_id BIGINT NOT NULL PRIMARY KEY,
  data MEDIUMTEXT NOT NULL,
  updated BIGINT NOT NULL,
  CONSTRAINT fk_progress_user FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS stats(
  user_id BIGINT NOT NULL PRIMARY KEY,
  xp BIGINT NOT NULL DEFAULT 0,
  week_xp BIGINT NOT NULL DEFAULT 0,
  streak INT NOT NULL DEFAULT 0,
  learned INT NOT NULL DEFAULT 0,
  acc INT NOT NULL DEFAULT 0,
  today INT NOT NULL DEFAULT 0,
  level INT NOT NULL DEFAULT 1,
  updated BIGINT NOT NULL DEFAULT 0,
  answers BIGINT NOT NULL DEFAULT 0,
  studied BIGINT NOT NULL DEFAULT 0,
  CONSTRAINT fk_stats_user FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS friends(
  user_id BIGINT NOT NULL,
  friend_id BIGINT NOT NULL,
  created BIGINT NOT NULL,
  PRIMARY KEY(user_id, friend_id),
  CONSTRAINT fk_friends_user FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE,
  CONSTRAINT fk_friends_friend FOREIGN KEY (friend_id) REFERENCES users(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS shares(
  code VARCHAR(16) NOT NULL PRIMARY KEY,
  owner_id BIGINT NOT NULL,
  name VARCHAR(255) NOT NULL,
  descr VARCHAR(600) NOT NULL DEFAULT '',
  cards MEDIUMTEXT NOT NULL,
  created BIGINT NOT NULL,
  KEY shares_owner (owner_id),
  CONSTRAINT fk_shares_owner FOREIGN KEY (owner_id) REFERENCES users(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- В SQLite ключом был (src, sl, tl). src до 1500 символов в индекс не влезает, поэтому ключ (src_hash, sl, tl),
-- где src_hash = SHA-1 от src (латинские и кириллические слова различаются, регистр учитывается).
CREATE TABLE IF NOT EXISTS translations(
  src_hash CHAR(40) NOT NULL,
  sl VARCHAR(8) NOT NULL,
  tl VARCHAR(8) NOT NULL,
  src TEXT NOT NULL,
  `out` TEXT NOT NULL,
  prov VARCHAR(32) NOT NULL,
  PRIMARY KEY(src_hash, sl, tl)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_bin;

CREATE TABLE IF NOT EXISTS hits(
  id BIGINT AUTO_INCREMENT PRIMARY KEY,
  ts BIGINT NOT NULL,
  day INT NOT NULL,
  vkey VARCHAR(32) NOT NULL,
  uid BIGINT NULL,
  path VARCHAR(100) NULL, ref VARCHAR(300) NULL, device VARCHAR(40) NULL, os VARCHAR(40) NULL,
  browser VARCHAR(40) NULL, tz VARCHAR(64) NULL, lang VARCHAR(16) NULL,
  KEY hits_day (day)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS presence(
  vkey VARCHAR(32) NOT NULL PRIMARY KEY,
  uid BIGINT NULL,
  ts BIGINT NOT NULL,
  tab VARCHAR(40) NULL, device VARCHAR(40) NULL, tz VARCHAR(64) NULL,
  what VARCHAR(200) NULL,
  KEY presence_ts (ts)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS ai_log(
  id BIGINT AUTO_INCREMENT PRIMARY KEY,
  ts BIGINT NOT NULL,
  day INT NOT NULL,
  uid BIGINT NULL,
  kind VARCHAR(32) NOT NULL,
  model VARCHAR(64) NOT NULL DEFAULT '',
  inp BIGINT NOT NULL DEFAULT 0,
  outp BIGINT NOT NULL DEFAULT 0,
  cost BIGINT NOT NULL DEFAULT 0,
  ms BIGINT NOT NULL DEFAULT 0,
  err VARCHAR(300) NOT NULL DEFAULT '',
  KEY ai_log_day (day),
  KEY ai_log_uid_ts (uid, ts)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS ai_cache(
  `key` VARCHAR(100) NOT NULL PRIMARY KEY,
  `out` MEDIUMTEXT NOT NULL,
  ts BIGINT NOT NULL,
  KEY ai_cache_ts (ts)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_bin;

CREATE TABLE IF NOT EXISTS schreiben_state(
  user_id BIGINT NOT NULL PRIMARY KEY,
  data MEDIUMTEXT NOT NULL,
  updated BIGINT NOT NULL,
  CONSTRAINT fk_sstate_user FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS schreiben_essays(
  id BIGINT AUTO_INCREMENT PRIMARY KEY,
  user_id BIGINT NOT NULL,
  ts BIGINT NOT NULL,
  task_id VARCHAR(100) NOT NULL,
  typ VARCHAR(16) NOT NULL,
  title VARCHAR(255) NOT NULL,
  text MEDIUMTEXT NOT NULL,
  words INT NOT NULL,
  minutes INT NOT NULL DEFAULT 0,
  points DOUBLE NOT NULL,
  max_points INT NOT NULL,
  task MEDIUMTEXT NOT NULL,
  result MEDIUMTEXT NOT NULL,
  KEY schreiben_essays_user (user_id, ts),
  CONSTRAINT fk_sessays_user FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS races(
  id BIGINT AUTO_INCREMENT PRIMARY KEY,
  a BIGINT NOT NULL,
  b BIGINT NOT NULL,
  metric VARCHAR(16) NOT NULL,
  `start` BIGINT NOT NULL,
  `end` BIGINT NOT NULL,
  a0 BIGINT NOT NULL DEFAULT 0,
  b0 BIGINT NOT NULL DEFAULT 0,
  created BIGINT NOT NULL,
  KEY races_a (a), KEY races_b (b),
  CONSTRAINT fk_races_a FOREIGN KEY (a) REFERENCES users(id) ON DELETE CASCADE,
  CONSTRAINT fk_races_b FOREIGN KEY (b) REFERENCES users(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS word_inbox(
  id BIGINT AUTO_INCREMENT PRIMARY KEY,
  user_id BIGINT NOT NULL,
  de VARCHAR(255) NOT NULL,
  ru VARCHAR(400) NOT NULL,
  ctx VARCHAR(600) NOT NULL DEFAULT '',
  ts BIGINT NOT NULL,
  KEY word_inbox_user (user_id),
  CONSTRAINT fk_winbox_user FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS kv(
  user_id BIGINT NOT NULL,
  k VARCHAR(48) NOT NULL,
  v MEDIUMTEXT NOT NULL,
  updated BIGINT NOT NULL,
  PRIMARY KEY(user_id, k),
  CONSTRAINT fk_kv_user FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS invites(
  code VARCHAR(16) NOT NULL PRIMARY KEY,
  created_by BIGINT NULL,
  created BIGINT NOT NULL,
  used_by BIGINT NULL,
  used BIGINT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- личный словарь и план (в живой базе пока пусты; HTTP-эндпоинтов в коде main нет)
CREATE TABLE IF NOT EXISTS personal_settings(
  user_id BIGINT NOT NULL PRIMARY KEY,
  data MEDIUMTEXT NOT NULL,
  CONSTRAINT fk_pset_user FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS personal_plan(
  user_id BIGINT NOT NULL,
  track VARCHAR(32) NOT NULL,
  data MEDIUMTEXT NOT NULL,
  PRIMARY KEY(user_id, track),
  CONSTRAINT fk_pplan_user FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS personal_attempts(
  id BIGINT AUTO_INCREMENT PRIMARY KEY,
  user_id BIGINT NOT NULL,
  track VARCHAR(32) NOT NULL,
  kind VARCHAR(32) NOT NULL,
  lesson VARCHAR(100) NOT NULL,
  ts BIGINT NOT NULL,
  score DOUBLE NOT NULL,
  data MEDIUMTEXT NOT NULL,
  KEY pattempts_user (user_id),
  CONSTRAINT fk_pattempts_user FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS personal_usage(
  user_id BIGINT NOT NULL,
  day VARCHAR(10) NOT NULL,
  n INT NOT NULL,
  PRIMARY KEY(user_id, day),
  CONSTRAINT fk_pusage_user FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS personal_vocab(
  id BIGINT AUTO_INCREMENT PRIMARY KEY,
  user_id BIGINT NOT NULL,
  track VARCHAR(32) NOT NULL,
  de VARCHAR(190) NOT NULL,
  ru VARCHAR(400) NOT NULL,
  ctx VARCHAR(600) NOT NULL,
  box INT NOT NULL DEFAULT 0,
  due VARCHAR(10) NOT NULL,
  UNIQUE KEY pvocab_uniq (user_id, track, de),
  CONSTRAINT fk_pvocab_user FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ---------- новое ----------
-- Журнал действий админа (пароли в details никогда не пишутся)
CREATE TABLE IF NOT EXISTS audit_log(
  id BIGINT AUTO_INCREMENT PRIMARY KEY,
  admin_id BIGINT NULL,
  action VARCHAR(40) NOT NULL,
  target_user_id BIGINT NULL,
  created BIGINT NOT NULL,
  details TEXT NULL,                        -- JSON
  KEY audit_created (created),
  KEY audit_target (target_user_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Счётчики попыток вместо памяти процесса (в PHP её нет): неудачные входы, регистрации с IP, лимиты ИИ и переводов
CREATE TABLE IF NOT EXISTS rate_limit(
  id BIGINT AUTO_INCREMENT PRIMARY KEY,
  bucket VARCHAR(120) NOT NULL,
  ts BIGINT NOT NULL,
  KEY rate_bucket (bucket, ts)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_bin;

-- ---------- защита владельца (в SQLite были триггеры owner_no_delete и owner_keep_role) ----------
-- Если на хостинге нет права TRIGGER, этот блок вернёт ошибку: остальная схема уже создана, а те же проверки есть в PHP-коде.
DROP TRIGGER IF EXISTS owner_no_delete;
DROP TRIGGER IF EXISTS owner_keep_role;
DELIMITER $$
CREATE TRIGGER owner_no_delete BEFORE DELETE ON users FOR EACH ROW
BEGIN
  IF OLD.role = 'owner' THEN
    SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'owner is protected';
  END IF;
END$$
CREATE TRIGGER owner_keep_role BEFORE UPDATE ON users FOR EACH ROW
BEGIN
  IF OLD.role = 'owner' AND NEW.role <> 'owner'
     AND (SELECT COUNT(*) FROM users WHERE role = 'owner' AND id <> OLD.id) = 0 THEN
    SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'last owner';
  END IF;
END$$
DELIMITER ;
