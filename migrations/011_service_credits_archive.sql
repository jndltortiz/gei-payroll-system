-- migrations/011_service_credits_archive.sql
-- Adds ARCHIVED status, archived_at timestamp, and created_by (user_id) to service_credits.
-- Safe: all changes use information_schema checks before altering.

-- 1. Add ARCHIVED to service_credits.status enum (if not already present)
SET @col_type = (
    SELECT COLUMN_TYPE FROM information_schema.COLUMNS
    WHERE TABLE_SCHEMA = DATABASE()
      AND TABLE_NAME   = 'service_credits'
      AND COLUMN_NAME  = 'status'
);
SET @sql1 = IF(
    LOCATE('ARCHIVED', @col_type) = 0,
    "ALTER TABLE service_credits MODIFY COLUMN status ENUM('DRAFT','PENDING','APPROVED','APPLIED','RELEASED','REJECTED','ARCHIVED') NOT NULL DEFAULT 'DRAFT'",
    "SELECT 'ARCHIVED already in status enum' AS notice"
);
PREPARE _s FROM @sql1; EXECUTE _s; DEALLOCATE PREPARE _s;

-- 2. Add archived_at DATETIME column
SET @has_archived_at = (
    SELECT COUNT(*) FROM information_schema.COLUMNS
    WHERE TABLE_SCHEMA = DATABASE()
      AND TABLE_NAME   = 'service_credits'
      AND COLUMN_NAME  = 'archived_at'
);
SET @sql2 = IF(
    @has_archived_at = 0,
    'ALTER TABLE service_credits ADD COLUMN archived_at DATETIME NULL DEFAULT NULL AFTER updated_at',
    "SELECT 'archived_at already exists' AS notice"
);
PREPARE _s FROM @sql2; EXECUTE _s; DEALLOCATE PREPARE _s;

-- 3. Add created_by INT column (tracks which user_id created the record)
SET @has_created_by = (
    SELECT COUNT(*) FROM information_schema.COLUMNS
    WHERE TABLE_SCHEMA = DATABASE()
      AND TABLE_NAME   = 'service_credits'
      AND COLUMN_NAME  = 'created_by'
);
SET @sql3 = IF(
    @has_created_by = 0,
    'ALTER TABLE service_credits ADD COLUMN created_by INT NULL DEFAULT NULL AFTER approved_by',
    "SELECT 'created_by already exists' AS notice"
);
PREPARE _s FROM @sql3; EXECUTE _s; DEALLOCATE PREPARE _s;

-- Verify
SELECT
    COLUMN_NAME, COLUMN_TYPE, IS_NULLABLE
FROM information_schema.COLUMNS
WHERE TABLE_SCHEMA = DATABASE()
  AND TABLE_NAME   = 'service_credits'
  AND COLUMN_NAME  IN ('status','archived_at','created_by')
ORDER BY ORDINAL_POSITION;
