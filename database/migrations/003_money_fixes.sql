-- =====================================================================
-- GrazeX migration 003 — Phase 1 money & data fixes.
-- Paste in phpMyAdmin (SQL tab) on the live database BEFORE merging the code. Safe to run twice.
-- Works on MariaDB and MySQL.
-- =====================================================================

-- 1. Stock take: remember when each line was counted, so stock received / sold while the
--    count is open is not wiped out when the stock take is completed.
SET @sql = (SELECT IF(COUNT(*) = 0,
  'ALTER TABLE stock_take_items ADD COLUMN counted_at DATETIME NULL AFTER counted_qty',
  'SELECT 1') FROM information_schema.COLUMNS
  WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'stock_take_items' AND COLUMN_NAME = 'counted_at');
PREPARE gx_stmt FROM @sql; EXECUTE gx_stmt; DEALLOCATE PREPARE gx_stmt;

SET @sql = (SELECT IF(COUNT(*) = 0,
  'ALTER TABLE stock_take_items ADD COLUMN counted_mark BIGINT UNSIGNED NULL AFTER counted_at',
  'SELECT 1') FROM information_schema.COLUMNS
  WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'stock_take_items' AND COLUMN_NAME = 'counted_mark');
PREPARE gx_stmt FROM @sql; EXECUTE gx_stmt; DEALLOCATE PREPARE gx_stmt;

-- 2. Emails: payment instructions and bank details are trusted markdown (no more ∗∗garbled∗∗ text).
UPDATE email_templates SET body = REPLACE(body, '{{payment_instructions}}', '{{payment_instructions_md}}')
 WHERE template_key = 'order_placed';
UPDATE email_templates SET body = CONCAT(body, '\n\n{{bank_details_md}}')
 WHERE template_key = 'payment_reminder' AND body NOT LIKE '%{{bank_details_md}}%';
-- Refunds get their own email (with the credit note attached) instead of "Order cancelled".
INSERT IGNORE INTO email_templates (template_key, name, subject, body) VALUES
('order_refunded', 'Order refunded', 'Order {{order_no}} refunded',
 'Hi {{first_name}},\n\nWe have refunded order **{{order_no}}**. {{reason}}\n\nYour credit note is attached. The money is paid back the same way you paid — please allow a few working days for it to reflect.');

-- 3. VAT: a division that is NOT VAT registered no longer stores a 15% rate on its orders.
--    This only touches orders where no VAT was charged (vat_cents = 0) on a non-registered division.
--    Check first (expect 0 on a site without test orders):
--    SELECT o.order_no, o.vat_rate_bp FROM orders o JOIN divisions d ON d.id = o.division_id
--     WHERE d.vat_registered = 0 AND o.vat_rate_bp <> 0 AND o.vat_cents = 0;
UPDATE order_items oi JOIN orders o ON o.id = oi.order_id JOIN divisions d ON d.id = o.division_id
   SET oi.vat_rate_bp = 0
 WHERE d.vat_registered = 0 AND o.vat_cents = 0 AND oi.vat_rate_bp <> 0;
UPDATE orders o JOIN divisions d ON d.id = o.division_id
   SET o.vat_rate_bp = 0
 WHERE d.vat_registered = 0 AND o.vat_cents = 0 AND o.vat_rate_bp <> 0;

INSERT IGNORE INTO schema_migrations (version) VALUES ('003_money_fixes');

-- Check: SHOW COLUMNS FROM stock_take_items;  SELECT * FROM schema_migrations;
