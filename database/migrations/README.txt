Database changes after the first install come here as numbered SQL files (002_..., 003_...).

How to apply (Xneelo: konsoleH -> phpMyAdmin -> choose the database -> SQL tab):
  1. Check which ones already ran:   SELECT * FROM schema_migrations ORDER BY version;
     (If that table does not exist yet, start with 002_schema_migrations.sql.)
  2. Paste each missing file, in number order, and click Go.
  3. Do this BEFORE merging the pull request that needs it — every merge to main deploys at once.

Every file is safe to run twice and works on MariaDB and MySQL.
install.sql is only for a brand-new, empty database (it already contains every migration).
