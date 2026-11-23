-- dirks3n – Datenbankschema (MySQL 5.7+ / MariaDB 10.3+)
-- Einmalig in phpMyAdmin ausführen (Reiter "SQL" → Inhalt einfügen → OK).
-- Alle Tabellen haben das Präfix "d3_", damit sie sich nicht mit anderen Tabellen mischen.

SET NAMES utf8mb4;

-- Benutzer (es gibt nur dich, aber sauber als Tabelle)
CREATE TABLE IF NOT EXISTS d3_users (
  id            INT UNSIGNED NOT NULL AUTO_INCREMENT,
  username      VARCHAR(60)  NOT NULL,
  password_hash VARCHAR(255) NOT NULL,
  created_at    DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  UNIQUE KEY uq_username (username)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Fehlgeschlagene Logins (Schutz gegen Passwort-Raten)
CREATE TABLE IF NOT EXISTS d3_login_attempts (
  id           INT UNSIGNED NOT NULL AUTO_INCREMENT,
  ip_hash      CHAR(64)     NOT NULL,
  attempted_at DATETIME     NOT NULL,
  PRIMARY KEY (id),
  KEY idx_time (attempted_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Einstellungen / Profil (Schlüssel → JSON-Wert)
CREATE TABLE IF NOT EXISTS d3_settings (
  k          VARCHAR(64) NOT NULL,
  v          MEDIUMTEXT  NOT NULL,
  updated_at DATETIME    NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (k)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Rohdaten aus Apple Health (und später Garmin/Google über Apple Health)
-- Eine Zeile = ein Messwert. Doppelte Übertragungen werden über den UNIQUE-Schlüssel erkannt.
CREATE TABLE IF NOT EXISTS d3_health_samples (
  id         BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  metric     VARCHAR(40)  NOT NULL,           -- z. B. hrv, resting_hr, steps, sleep
  source     VARCHAR(60)  NOT NULL DEFAULT '', -- Quell-App (falls übertragen)
  start_at   DATETIME     NOT NULL,
  end_at     DATETIME     NOT NULL,
  value      DOUBLE       NULL,               -- Zahlenwert
  text_value VARCHAR(40)  NOT NULL DEFAULT '', -- z. B. Schlafphase "deep"
  created_at DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  UNIQUE KEY uq_sample (metric, source, start_at, end_at, text_value),
  KEY idx_metric_time (metric, start_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Trainings (aus Apple Health; Google Health / Garmin schreiben dorthin)
CREATE TABLE IF NOT EXISTS d3_workouts (
  id           BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  source       VARCHAR(60)  NOT NULL DEFAULT '',
  activity     VARCHAR(80)  NOT NULL,
  category     VARCHAR(20)  NOT NULL DEFAULT 'other', -- strength, cardio, hiit, mobility, walk, other
  start_at     DATETIME     NOT NULL,
  end_at       DATETIME     NOT NULL,
  duration_min DOUBLE       NOT NULL DEFAULT 0,
  energy_kcal  DOUBLE       NULL,
  distance_km  DOUBLE       NULL,
  avg_hr       DOUBLE       NULL,
  load_score   DOUBLE       NOT NULL DEFAULT 0,       -- berechnete Belastung
  created_at   DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  UNIQUE KEY uq_workout (start_at, activity),
  KEY idx_start (start_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Tageswerte (aus den Rohdaten berechnet) – Grundlage für Diagramme
CREATE TABLE IF NOT EXISTS d3_daily_metrics (
  day    DATE        NOT NULL,
  metric VARCHAR(40) NOT NULL,
  value  DOUBLE      NOT NULL,
  PRIMARY KEY (day, metric),
  KEY idx_metric_day (metric, day)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Tägliche Trainingsentscheidung
CREATE TABLE IF NOT EXISTS d3_decisions (
  day          DATE         NOT NULL,
  readiness    INT          NULL,
  train        TINYINT(1)   NOT NULL DEFAULT 0,
  kind         VARCHAR(40)  NOT NULL,
  focus        VARCHAR(160) NOT NULL DEFAULT '',
  intensity    VARCHAR(20)  NOT NULL DEFAULT '',
  duration_min INT          NOT NULL DEFAULT 0,
  time_hint    VARCHAR(120) NOT NULL DEFAULT '',
  reasoning    TEXT         NOT NULL,
  details_json MEDIUMTEXT   NULL,
  engine       VARCHAR(10)  NOT NULL DEFAULT 'rules', -- 'ai' oder 'rules'
  revisions    INT          NOT NULL DEFAULT 0,
  created_at   DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at   DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (day)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Kalendertermine (aus ICS-Link und später aus dem Wochen-Chat)
CREATE TABLE IF NOT EXISTS d3_calendar_events (
  id         BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  origin     VARCHAR(20)  NOT NULL DEFAULT 'ics', -- 'ics' oder 'app'
  uid        VARCHAR(255) NOT NULL,
  title      VARCHAR(255) NOT NULL DEFAULT '',
  location   VARCHAR(255) NOT NULL DEFAULT '',
  start_at   DATETIME     NOT NULL,
  end_at     DATETIME     NOT NULL,
  all_day    TINYINT(1)   NOT NULL DEFAULT 0,
  created_at DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  UNIQUE KEY uq_event (origin, uid, start_at),
  KEY idx_start (start_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- KI-Kosten (für die Monatsobergrenze)
CREATE TABLE IF NOT EXISTS d3_ai_usage (
  id            BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  created_at    DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
  purpose       VARCHAR(40)  NOT NULL,
  model         VARCHAR(60)  NOT NULL,
  input_tokens  INT          NOT NULL DEFAULT 0,
  output_tokens INT          NOT NULL DEFAULT 0,
  cache_read    INT          NOT NULL DEFAULT 0,
  cache_write   INT          NOT NULL DEFAULT 0,
  cost_usd      DECIMAL(10,6) NOT NULL DEFAULT 0,
  PRIMARY KEY (id),
  KEY idx_created (created_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Protokoll der Datenübertragungen (für "Letzte Synchronisation")
CREATE TABLE IF NOT EXISTS d3_sync_log (
  id          BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  received_at DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
  kind        VARCHAR(20)  NOT NULL,   -- 'health', 'calendar', 'cron'
  items       INT          NOT NULL DEFAULT 0,
  info        VARCHAR(500) NOT NULL DEFAULT '',
  PRIMARY KEY (id),
  KEY idx_kind_time (kind, received_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ===== Fitbit / Google Health (seit Update 002) =====

-- Google-Zugang (Tokens werden VERSCHLÜSSELT gespeichert)
CREATE TABLE IF NOT EXISTS d3_oauth_tokens (
  provider      VARCHAR(20)  NOT NULL,              -- 'google'
  access_token  TEXT         NULL,                  -- verschlüsselt
  refresh_token TEXT         NULL,                  -- verschlüsselt
  expires_at    DATETIME     NULL,
  scopes        TEXT         NULL,
  account_id    VARCHAR(64)  NOT NULL DEFAULT '',   -- healthUserId von Google
  status        VARCHAR(20)  NOT NULL DEFAULT 'connected', -- connected | reauth
  last_error    VARCHAR(500) NOT NULL DEFAULT '',
  connected_at  DATETIME     NULL,
  updated_at    DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (provider)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Einmal-Codes für den Google-Login (10 Minuten gültig)
CREATE TABLE IF NOT EXISTS d3_oauth_states (
  state         CHAR(64)     NOT NULL,
  code_verifier VARCHAR(128) NOT NULL,
  created_at    DATETIME     NOT NULL,
  PRIMARY KEY (state)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Abruf-Fortschritt je Datenart (für das Nachladen früherer Tage)
CREATE TABLE IF NOT EXISTS d3_google_sync (
  data_type     VARCHAR(40)  NOT NULL,
  backfill_from DATE         NULL,                  -- ältester bereits geladener Tag
  last_sync_at  DATETIME     NULL,
  last_error    VARCHAR(500) NOT NULL DEFAULT '',
  PRIMARY KEY (data_type)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Herzfrequenz in 5-Minuten-Fenstern (Ø / min / max)
CREATE TABLE IF NOT EXISTS d3_heart_rate (
  ts      DATETIME NOT NULL,   -- Beginn des 5-Minuten-Fensters (Ortszeit)
  bpm_avg DOUBLE   NOT NULL,
  bpm_min DOUBLE   NOT NULL,
  bpm_max DOUBLE   NOT NULL,
  PRIMARY KEY (ts)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ===== Workout-Tracking (seit Update 003) =====

-- Übungsbibliothek (wird beim ersten Öffnen automatisch aus private/data/exercises.json befüllt)
CREATE TABLE IF NOT EXISTS d3_exercises (
  id                VARCHAR(100) NOT NULL,
  name              VARCHAR(160) NOT NULL,
  name_de           VARCHAR(160) NULL,
  category          VARCHAR(40)  NOT NULL DEFAULT '',
  equipment         VARCHAR(40)  NOT NULL DEFAULT '',
  level             VARCHAR(20)  NOT NULL DEFAULT '',
  mechanic          VARCHAR(20)  NOT NULL DEFAULT '',
  primary_muscles   VARCHAR(255) NOT NULL DEFAULT '',
  secondary_muscles VARCHAR(255) NOT NULL DEFAULT '',
  instructions      TEXT         NULL,
  instructions_de   TEXT         NULL,
  image_count       TINYINT      NOT NULL DEFAULT 0,
  curated           TINYINT(1)   NOT NULL DEFAULT 0,
  preference        TINYINT      NOT NULL DEFAULT 0,  -- -1 mag ich nicht, 0 neutral, 1 mag ich (Onboarding)
  PRIMARY KEY (id),
  KEY idx_curated (curated)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Trainingseinheiten
CREATE TABLE IF NOT EXISTS d3_training_sessions (
  id          INT UNSIGNED NOT NULL AUTO_INCREMENT,
  day         DATE         NOT NULL,
  title       VARCHAR(160) NOT NULL DEFAULT '',
  origin      VARCHAR(20)  NOT NULL DEFAULT 'plan',   -- plan | free
  status      VARCHAR(20)  NOT NULL DEFAULT 'active', -- active | done
  started_at  DATETIME     NOT NULL,
  finished_at DATETIME     NULL,
  notes       TEXT         NULL,
  PRIMARY KEY (id),
  KEY idx_day (day),
  KEY idx_status (status)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Sätze
CREATE TABLE IF NOT EXISTS d3_training_sets (
  id            INT UNSIGNED NOT NULL AUTO_INCREMENT,
  session_id    INT UNSIGNED NOT NULL,
  exercise_id   VARCHAR(100) NOT NULL,
  ex_order      SMALLINT     NOT NULL DEFAULT 0,
  set_no        SMALLINT     NOT NULL DEFAULT 1,
  target_reps_min SMALLINT   NULL,
  target_reps_max SMALLINT   NULL,
  target_weight DOUBLE       NULL,
  reps          SMALLINT     NULL,
  weight        DOUBLE       NULL,
  done          TINYINT(1)   NOT NULL DEFAULT 0,
  done_at       DATETIME     NULL,
  rest_sec      SMALLINT     NOT NULL DEFAULT 90,
  PRIMARY KEY (id),
  KEY idx_session (session_id, ex_order, set_no),
  KEY idx_exercise (exercise_id, done)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ===== Ziel-Chat (seit Update 004) =====

-- Chat-Verläufe (Ziel-Chat, später auch Coach)
CREATE TABLE IF NOT EXISTS d3_chat_messages (
  id         BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  thread     VARCHAR(20)  NOT NULL,            -- 'goals' | 'coach'
  role       VARCHAR(12)  NOT NULL,            -- 'user' | 'assistant'
  content    TEXT         NOT NULL,
  meta_json  MEDIUMTEXT   NULL,                -- z. B. vorgeschlagene Ziele
  created_at DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  KEY idx_thread (thread, id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Strukturierte Ziele
CREATE TABLE IF NOT EXISTS d3_goals (
  id         INT UNSIGNED NOT NULL AUTO_INCREMENT,
  status     VARCHAR(12)  NOT NULL DEFAULT 'active', -- active | archived
  category   VARCHAR(20)  NOT NULL,                  -- strength | muscle | running_speed | endurance | fat_loss | health | other
  title      VARCHAR(160) NOT NULL,
  metric     VARCHAR(120) NOT NULL DEFAULT '',       -- z. B. "5-km-Zeit"
  target     VARCHAR(80)  NOT NULL DEFAULT '',       -- z. B. "unter 20:00 min"
  baseline   VARCHAR(80)  NOT NULL DEFAULT '',       -- z. B. "aktuell 23:30 min"
  deadline   DATE         NULL,
  priority   TINYINT      NOT NULL DEFAULT 2,        -- 1 = wichtigstes Ziel
  notes      VARCHAR(500) NOT NULL DEFAULT '',
  created_at DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
  archived_at DATETIME    NULL,
  PRIMARY KEY (id),
  KEY idx_status (status, priority)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
