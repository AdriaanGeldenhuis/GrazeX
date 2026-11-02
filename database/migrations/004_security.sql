-- =====================================================================
-- GrazeX migration 004 — Phase 2 security.
-- Paste in phpMyAdmin (SQL tab) on the live database BEFORE merging the code. Safe to run twice.
-- Works on MariaDB and MySQL.
-- =====================================================================

-- 2FA secrets are now stored encrypted (longer than the old 64-character limit)
ALTER TABLE admin_users MODIFY totp_secret VARCHAR(255) NULL;

-- 2FA: each code works only once (replay protection)
SET @sql = (SELECT IF(COUNT(*) = 0,
  'ALTER TABLE admin_users ADD COLUMN totp_last_step INT UNSIGNED NULL AFTER totp_enabled',
  'SELECT 1') FROM information_schema.COLUMNS
  WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'admin_users' AND COLUMN_NAME = 'totp_last_step');
PREPARE gx_stmt FROM @sql; EXECUTE gx_stmt; DEALLOCATE PREPARE gx_stmt;

-- 2FA: one-time recovery codes (stored as hashes)
SET @sql = (SELECT IF(COUNT(*) = 0,
  'ALTER TABLE admin_users ADD COLUMN totp_recovery TEXT NULL AFTER totp_last_step',
  'SELECT 1') FROM information_schema.COLUMNS
  WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'admin_users' AND COLUMN_NAME = 'totp_recovery');
PREPARE gx_stmt FROM @sql; EXECUTE gx_stmt; DEALLOCATE PREPARE gx_stmt;

INSERT IGNORE INTO schema_migrations (version) VALUES ('004_security');

-- Check: SHOW COLUMNS FROM admin_users LIKE 'totp%';  SELECT * FROM schema_migrations;
