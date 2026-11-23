-- dirks3n – Update 004: Ziel-Chat (Gespräche + strukturierte Ziele)
-- Einmalig in phpMyAdmin ausführen (Reiter "SQL"). Bei Neuinstallation bereits in schema.sql enthalten.

SET NAMES utf8mb4;

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
