-- dirks3n – Update 002: Fitbit Charge 6 über die Google Health API
-- Einmalig in phpMyAdmin ausführen (Reiter "SQL"), wenn du schema.sql schon früher eingespielt hast.
-- Bei einer Neuinstallation ist alles bereits in schema.sql enthalten.

SET NAMES utf8mb4;

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

-- Durchschnittspuls pro Training
ALTER TABLE d3_workouts ADD COLUMN avg_hr DOUBLE NULL AFTER distance_km;
