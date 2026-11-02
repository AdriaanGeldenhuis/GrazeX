-- =====================================================================
-- GrazeX migration 005 — Phase 3 checkout & payments.
-- Paste in phpMyAdmin (SQL tab) on the live database BEFORE merging the code. Safe to run twice.
-- Works on MariaDB and MySQL.
-- =====================================================================

-- Livestock order lines store "breed · category", which can be longer than 100 characters
ALTER TABLE order_items MODIFY variant_name VARCHAR(200) NULL;

-- After a proof of payment is rejected, the stock stays reserved this many extra hours for a new upload
INSERT IGNORE INTO settings (setting_key, setting_value, value_type, group_name, label) VALUES
('pop_reupload_hours', '24', 'int', 'orders', 'Extra hours to upload a new proof of payment after one is rejected');

INSERT IGNORE INTO schema_migrations (version) VALUES ('005_checkout');

-- Check: SHOW COLUMNS FROM order_items LIKE 'variant_name';  SELECT * FROM schema_migrations;
