-- =====================================================================
-- ONLY needed if you ALREADY imported the old sql/schema.sql before the PHP update.
-- phpMyAdmin: select the quiz_reviewer database > SQL tab > paste this > Go.
-- (If you import the new sql/schema.sql instead, you do NOT need this file.)
-- =====================================================================
USE quiz_reviewer;

CREATE TABLE IF NOT EXISTS login_fails (
  email      VARCHAR(190) PRIMARY KEY,
  fail_count INT UNSIGNED NOT NULL DEFAULT 0,
  first_at   DATETIME NOT NULL
) ENGINE=InnoDB;

CREATE TABLE IF NOT EXISTS attempts (
  id         INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  subject    VARCHAR(40) NOT NULL,
  percentage DECIMAL(5,2) NOT NULL,
  created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  INDEX idx_attempts_subject (subject)
) ENGINE=InnoDB;
