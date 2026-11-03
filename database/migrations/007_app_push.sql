-- =====================================================================
-- GrazeX migration 007 — Phase 6 Android app: push notifications and "keep me logged in".
-- Paste in phpMyAdmin (SQL tab) on the live database BEFORE merging the code. Safe to run twice.
-- Works on MariaDB and MySQL.
-- =====================================================================

SET NAMES utf8mb4;

-- 1. App devices: which login a device was registered under (a password change, "log out everywhere" or a
--    disabled admin stops pushes to it) and which app version it runs
SET @sql = (SELECT IF(COUNT(*) = 0,
  'ALTER TABLE push_devices ADD COLUMN customer_sv INT UNSIGNED NULL AFTER customer_id',
  'SELECT 1') FROM information_schema.COLUMNS
  WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'push_devices' AND COLUMN_NAME = 'customer_sv');
PREPARE gx_stmt FROM @sql; EXECUTE gx_stmt; DEALLOCATE PREPARE gx_stmt;

SET @sql = (SELECT IF(COUNT(*) = 0,
  'ALTER TABLE push_devices ADD COLUMN admin_sv INT UNSIGNED NULL AFTER admin_user_id',
  'SELECT 1') FROM information_schema.COLUMNS
  WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'push_devices' AND COLUMN_NAME = 'admin_sv');
PREPARE gx_stmt FROM @sql; EXECUTE gx_stmt; DEALLOCATE PREPARE gx_stmt;

SET @sql = (SELECT IF(COUNT(*) = 0,
  'ALTER TABLE push_devices ADD COLUMN app_version VARCHAR(20) NULL AFTER platform',
  'SELECT 1') FROM information_schema.COLUMNS
  WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'push_devices' AND COLUMN_NAME = 'app_version');
PREPARE gx_stmt FROM @sql; EXECUTE gx_stmt; DEALLOCATE PREPARE gx_stmt;

-- 2. Push notifications waiting to be sent (same claim pattern as the email queue)
CREATE TABLE IF NOT EXISTS push_queue (
  id           INT UNSIGNED NOT NULL AUTO_INCREMENT,
  device_id    INT UNSIGNED NOT NULL,
  channel      VARCHAR(20)  NOT NULL DEFAULT 'orders',
  title        VARCHAR(120) NOT NULL,
  body         VARCHAR(400) NOT NULL,
  url          VARCHAR(500) NULL,
  tag          VARCHAR(64)  NULL,
  status       ENUM('queued','sending','sent','failed') NOT NULL DEFAULT 'queued',
  attempts     TINYINT UNSIGNED NOT NULL DEFAULT 0,
  last_error   VARCHAR(255) NULL,
  send_after   DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
  claimed_at   DATETIME     NULL,
  sent_at      DATETIME     NULL,
  created_at   DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  KEY idx_push_queue_status (status, send_after),
  KEY idx_push_queue_sent (sent_at),
  KEY idx_push_queue_device (device_id, created_at),
  CONSTRAINT fk_push_queue_device FOREIGN KEY (device_id) REFERENCES push_devices (id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- 3. "Keep me logged in": one row per device (the cookie holds the selector and a secret; only its hash is stored)
CREATE TABLE IF NOT EXISTS customer_remember_tokens (
  id                   INT UNSIGNED NOT NULL AUTO_INCREMENT,
  customer_id          INT UNSIGNED NOT NULL,
  selector             CHAR(24)     NOT NULL,
  validator_hash       CHAR(64)     NOT NULL,
  prev_validator_hash  CHAR(64)     NULL,
  rotated_at           DATETIME     NULL,
  session_version      INT UNSIGNED NOT NULL,
  user_agent           VARCHAR(255) NULL,
  expires_at           DATETIME     NOT NULL,
  last_used_at         DATETIME     NULL,
  created_at           DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  UNIQUE KEY uq_remember_selector (selector),
  KEY idx_remember_expires (expires_at),
  CONSTRAINT fk_remember_customer FOREIGN KEY (customer_id) REFERENCES customers (id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

INSERT IGNORE INTO schema_migrations (version) VALUES ('007_app_push');

-- Check: SHOW COLUMNS FROM push_devices;  SHOW CREATE TABLE push_queue;  SHOW CREATE TABLE customer_remember_tokens;
