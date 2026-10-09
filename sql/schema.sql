-- =====================================================================
-- College Quiz Reviewer - MySQL schema
-- XAMPP / phpMyAdmin: open the SQL tab, paste this whole file, click Go.
-- (Works on MariaDB 10.4 and MySQL 8. Safe to run again on an existing database: it only adds what is missing.)
-- =====================================================================
CREATE DATABASE IF NOT EXISTS quiz_reviewer
  CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;
USE quiz_reviewer;

-- Accounts (admin and student). Passwords are stored as salted PBKDF2 hashes, never as plain text.
CREATE TABLE IF NOT EXISTS users (
  id             INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  full_name      VARCHAR(100) NOT NULL,
  email          VARCHAR(190) NOT NULL UNIQUE,
  password_hash  VARCHAR(255) NOT NULL,
  role           ENUM('admin','student') NOT NULL DEFAULT 'student',
  email_verified TINYINT(1) NOT NULL DEFAULT 0,
  created_at     TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB;

-- One-time codes sent to Gmail (stored as hashes, valid 10 minutes, max 5 tries)
CREATE TABLE IF NOT EXISTS email_otps (
  id         INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  user_id    INT UNSIGNED NOT NULL,
  code_hash  CHAR(64) NOT NULL,
  attempts   TINYINT UNSIGNED NOT NULL DEFAULT 0,
  expires_at DATETIME NOT NULL,
  created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  INDEX idx_otp_user (user_id),
  CONSTRAINT fk_otp_user FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
) ENGINE=InnoDB;

-- Login sessions (the browser cookie holds a random token; only its hash is stored)
CREATE TABLE IF NOT EXISTS sessions (
  token_hash CHAR(64) PRIMARY KEY,
  user_id    INT UNSIGNED NOT NULL,
  expires_at DATETIME NOT NULL,
  INDEX idx_sessions_exp (expires_at),
  CONSTRAINT fk_sess_user FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
) ENGINE=InnoDB;

-- Shared quizzes. If the uploader's account is deleted, the quiz stays (uploaded_by becomes NULL).
CREATE TABLE IF NOT EXISTS quizzes (
  id             INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  title          VARCHAR(80) NOT NULL,
  uploaded_by    INT UNSIGNED NULL,
  question_count INT UNSIGNED NOT NULL,
  created_at     TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  CONSTRAINT fk_quiz_user FOREIGN KEY (uploaded_by) REFERENCES users(id) ON DELETE SET NULL
) ENGINE=InnoDB;

CREATE TABLE IF NOT EXISTS questions (
  id             INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  quiz_id        INT UNSIGNED NOT NULL,
  sort_order     INT UNSIGNED NOT NULL,
  question_text  TEXT NOT NULL,
  choice_a       TEXT NOT NULL,
  choice_b       TEXT NOT NULL,
  choice_c       TEXT NOT NULL,
  choice_d       TEXT NOT NULL,
  correct_index  TINYINT UNSIGNED NOT NULL,   -- 0 = A, 1 = B, 2 = C, 3 = D
  explanation    TEXT NOT NULL,
  INDEX idx_q_quiz (quiz_id, sort_order),
  CONSTRAINT fk_q_quiz FOREIGN KEY (quiz_id) REFERENCES quizzes(id) ON DELETE CASCADE
) ENGINE=InnoDB;

-- Login protection: counts wrong passwords per e-mail (5 wrong tries lock that e-mail for 10 minutes)
CREATE TABLE IF NOT EXISTS login_fails (
  email      VARCHAR(190) PRIMARY KEY,
  fail_count INT UNSIGNED NOT NULL DEFAULT 0,
  first_at   DATETIME NOT NULL
) ENGINE=InnoDB;

-- Anonymous quiz statistics (subject + percentage only; no personal data)
CREATE TABLE IF NOT EXISTS attempts (
  id         INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  subject    VARCHAR(40) NOT NULL,
  percentage DECIMAL(5,2) NOT NULL,
  created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  INDEX idx_attempts_subject (subject)
) ENGINE=InnoDB;


