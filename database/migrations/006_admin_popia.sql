-- =====================================================================
-- GrazeX migration 006 — Phase 4 admin, email, PDF, POPIA, cron.
-- Paste in phpMyAdmin (SQL tab) on the live database BEFORE merging the code. Safe to run twice.
-- Works on MariaDB and MySQL.
-- =====================================================================

SET NAMES utf8mb4;

-- 1. Email queue: when a send was claimed (stuck "sending" rows are re-queued by cron),
--    and the one-click unsubscribe URL for alert emails (List-Unsubscribe header).
SET @sql = (SELECT IF(COUNT(*) = 0,
  'ALTER TABLE email_queue ADD COLUMN list_unsubscribe VARCHAR(500) NULL AFTER attachments',
  'SELECT 1') FROM information_schema.COLUMNS
  WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'email_queue' AND COLUMN_NAME = 'list_unsubscribe');
PREPARE gx_stmt FROM @sql; EXECUTE gx_stmt; DEALLOCATE PREPARE gx_stmt;

SET @sql = (SELECT IF(COUNT(*) = 0,
  'ALTER TABLE email_queue ADD COLUMN claimed_at DATETIME NULL AFTER send_after',
  'SELECT 1') FROM information_schema.COLUMNS
  WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'email_queue' AND COLUMN_NAME = 'claimed_at');
PREPARE gx_stmt FROM @sql; EXECUTE gx_stmt; DEALLOCATE PREPARE gx_stmt;

-- 2. Livestock alerts: double opt-in for guests (an alert only sends after the email link is clicked)
SET @sql = (SELECT IF(COUNT(*) = 0,
  'ALTER TABLE livestock_alerts ADD COLUMN confirmed_at DATETIME NULL AFTER is_active',
  'SELECT 1') FROM information_schema.COLUMNS
  WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'livestock_alerts' AND COLUMN_NAME = 'confirmed_at');
PREPARE gx_stmt FROM @sql; EXECUTE gx_stmt; DEALLOCATE PREPARE gx_stmt;

SET @sql = (SELECT IF(COUNT(*) = 0,
  'ALTER TABLE livestock_alerts ADD COLUMN confirm_token CHAR(64) NULL AFTER confirmed_at, ADD UNIQUE KEY uq_livestock_alerts_confirm (confirm_token)',
  'SELECT 1') FROM information_schema.COLUMNS
  WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'livestock_alerts' AND COLUMN_NAME = 'confirm_token');
PREPARE gx_stmt FROM @sql; EXECUTE gx_stmt; DEALLOCATE PREPARE gx_stmt;

SET @sql = (SELECT IF(COUNT(*) = 0,
  'ALTER TABLE livestock_alerts ADD COLUMN confirm_sent_at DATETIME NULL AFTER confirm_token',
  'SELECT 1') FROM information_schema.COLUMNS
  WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'livestock_alerts' AND COLUMN_NAME = 'confirm_sent_at');
PREPARE gx_stmt FROM @sql; EXECUTE gx_stmt; DEALLOCATE PREPARE gx_stmt;

-- Existing alerts of customers who verified that same email count as confirmed. Every other existing alert
-- (anyone could have typed that address) gets ONE "confirm your alert" email from the cron and only continues
-- if it is confirmed; switched-off ones without proof of consent are removed by the cron.
UPDATE livestock_alerts a JOIN customers c ON c.id = a.customer_id
   SET a.confirmed_at = a.created_at
 WHERE a.confirmed_at IS NULL AND a.confirm_token IS NULL AND c.email_verified_at IS NOT NULL AND c.email = a.email;

-- 3. Terms & conditions: which version a customer accepted at registration and on each order
SET @sql = (SELECT IF(COUNT(*) = 0,
  'ALTER TABLE customers ADD COLUMN terms_accepted_at DATETIME NULL AFTER marketing_opt_in_at, ADD COLUMN terms_version VARCHAR(40) NULL AFTER terms_accepted_at',
  'SELECT 1') FROM information_schema.COLUMNS
  WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'customers' AND COLUMN_NAME = 'terms_accepted_at');
PREPARE gx_stmt FROM @sql; EXECUTE gx_stmt; DEALLOCATE PREPARE gx_stmt;

SET @sql = (SELECT IF(COUNT(*) = 0,
  'ALTER TABLE orders ADD COLUMN terms_accepted_at DATETIME NULL AFTER transport_note, ADD COLUMN terms_version VARCHAR(40) NULL AFTER terms_accepted_at',
  'SELECT 1') FROM information_schema.COLUMNS
  WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'orders' AND COLUMN_NAME = 'terms_accepted_at');
PREPARE gx_stmt FROM @sql; EXECUTE gx_stmt; DEALLOCATE PREPARE gx_stmt;

-- 4. Breeds: kind of animal, printed on the document of identification (Stock Theft Act)
SET @sql = (SELECT IF(COUNT(*) = 0,
  'ALTER TABLE breeds ADD COLUMN kind VARCHAR(40) NOT NULL DEFAULT ''Sheep'' AFTER name',
  'SELECT 1') FROM information_schema.COLUMNS
  WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'breeds' AND COLUMN_NAME = 'kind');
PREPARE gx_stmt FROM @sql; EXECUTE gx_stmt; DEALLOCATE PREPARE gx_stmt;

-- 5. Admin "forgot password" links (stored as hashes, one hour, single use)
CREATE TABLE IF NOT EXISTS admin_tokens (
  id             INT UNSIGNED NOT NULL AUTO_INCREMENT,
  admin_user_id  INT UNSIGNED NOT NULL,
  purpose        ENUM('reset_password') NOT NULL DEFAULT 'reset_password',
  token_hash     CHAR(64)     NOT NULL,
  expires_at     DATETIME     NOT NULL,
  used_at        DATETIME     NULL,
  created_at     DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  UNIQUE KEY uq_admin_tokens_hash (token_hash),
  KEY idx_admin_tokens_user (admin_user_id, purpose),
  CONSTRAINT fk_admin_tokens_user FOREIGN KEY (admin_user_id) REFERENCES admin_users (id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- 6. Indexes for reports and privacy look-ups
SET @sql = (SELECT IF(COUNT(*) = 0, 'ALTER TABLE payments ADD KEY idx_payments_created (created_at)', 'SELECT 1')
  FROM information_schema.STATISTICS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'payments' AND INDEX_NAME = 'idx_payments_created');
PREPARE gx_stmt FROM @sql; EXECUTE gx_stmt; DEALLOCATE PREPARE gx_stmt;

SET @sql = (SELECT IF(COUNT(*) = 0, 'ALTER TABLE orders ADD KEY idx_orders_delivery (delivery_date)', 'SELECT 1')
  FROM information_schema.STATISTICS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'orders' AND INDEX_NAME = 'idx_orders_delivery');
PREPARE gx_stmt FROM @sql; EXECUTE gx_stmt; DEALLOCATE PREPARE gx_stmt;

SET @sql = (SELECT IF(COUNT(*) = 0, 'ALTER TABLE enquiries ADD KEY idx_enquiries_email (email)', 'SELECT 1')
  FROM information_schema.STATISTICS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'enquiries' AND INDEX_NAME = 'idx_enquiries_email');
PREPARE gx_stmt FROM @sql; EXECUTE gx_stmt; DEALLOCATE PREPARE gx_stmt;

-- 7. Settings: how long the activity log and enquiries are kept (POPIA: no longer than needed)
INSERT IGNORE INTO settings (setting_key, setting_value, value_type, group_name, label) VALUES
('retention_audit_days',     '730', 'int', 'system', 'Days the admin activity log is kept'),
('retention_enquiries_days', '730', 'int', 'system', 'Days enquiries (contact messages) are kept');

-- 8. Email templates
--    "Payment received" says which documents are really attached (a receipt, or a receipt and tax invoice)
UPDATE email_templates SET body = REPLACE(body, 'Your invoice is attached.', '{{docs_note_md}}')
 WHERE template_key = 'payment_received' AND body NOT LIKE '%{{docs_note_md}}%';
INSERT IGNORE INTO email_templates (template_key, name, subject, body) VALUES
('livestock_alert_confirm', 'Confirm livestock alert', 'Confirm your GrazeX Ranch livestock alert',
 'Hi,\n\nPlease confirm that you want an email when new livestock matching your alert ({{alert}}) is available:\n\n{{link}}\n\nIf you did not ask for this, ignore this email — you will not get any alerts.'),
('account_deleted', 'Account deleted', 'Your GrazeX account has been deleted',
 'Hi {{first_name}},\n\nAs you asked, we have deleted your GrazeX account and the personal information linked to it.\n\nInvoices that the law requires us to keep for 5 years are kept with only what a tax invoice must show (name, billing address and VAT number).\n\nIf you did not ask for this, please contact us.'),
('admin_password_reset', 'Admin: reset password', 'Reset your GrazeX admin password',
 'Hi {{first_name}},\n\nUse this link to choose a new admin password (valid for 1 hour): {{link}}\n\nIf you did not ask for this, ignore this email and tell the owner.');

INSERT IGNORE INTO schema_migrations (version) VALUES ('006_admin_popia');

-- Check: SHOW COLUMNS FROM livestock_alerts;  SHOW CREATE TABLE admin_tokens;  SELECT * FROM schema_migrations;
