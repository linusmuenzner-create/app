-- dirks3n – Update 003: Workout-Tracking (Übungen, Trainingseinheiten, Sätze)
-- Einmalig in phpMyAdmin ausführen (Reiter "SQL"). Bei Neuinstallation bereits in schema.sql enthalten.

SET NAMES utf8mb4;

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
