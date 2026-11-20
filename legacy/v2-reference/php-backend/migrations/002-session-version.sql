-- Safe to run once on an existing 3Food POS database.
-- Fresh installs already receive this column from schema.sql.
SET @has_session_version = (
  SELECT COUNT(*)
  FROM information_schema.COLUMNS
  WHERE TABLE_SCHEMA = DATABASE()
    AND TABLE_NAME = 'app_users'
    AND COLUMN_NAME = 'session_version'
);
SET @session_version_sql = IF(
  @has_session_version = 0,
  'ALTER TABLE app_users ADD COLUMN session_version BIGINT UNSIGNED NOT NULL DEFAULT 0 AFTER must_change_password',
  'SELECT 1'
);
PREPARE session_version_stmt FROM @session_version_sql;
EXECUTE session_version_stmt;
DEALLOCATE PREPARE session_version_stmt;
