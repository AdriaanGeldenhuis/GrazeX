-- =====================================================================
-- GrazeX migration 002 — keep track of which migrations have run.
-- Paste in phpMyAdmin (SQL tab) on the live database. Safe to run twice.
-- =====================================================================

CREATE TABLE IF NOT EXISTS schema_migrations (
  version     VARCHAR(100) NOT NULL,
  applied_at  DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (version)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

INSERT IGNORE INTO schema_migrations (version) VALUES ('001_install'), ('002_schema_migrations');

-- Check: SELECT * FROM schema_migrations ORDER BY version;
