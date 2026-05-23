-- ─────────────────────────────────────────────────────────────────────────────
-- Migration 008: DTR Upload — department_id column
--
-- Adds optional department_id to dtr_attachments so uploads can be scoped
-- to a department.  Safe to re-run.
--
-- Prerequisite: migration 007 (creates dtr_attachments table)
-- ─────────────────────────────────────────────────────────────────────────────

-- Only proceed if the table already exists (migration 007 applied)
SET @tbl = (
    SELECT COUNT(*) FROM information_schema.TABLES
    WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'dtr_attachments'
);

SET @col = IF(@tbl = 0, 1, (
    SELECT COUNT(*) FROM information_schema.COLUMNS
    WHERE TABLE_SCHEMA = DATABASE()
      AND TABLE_NAME   = 'dtr_attachments'
      AND COLUMN_NAME  = 'department_id'
));

SET @s = IF(@col = 0,
    'ALTER TABLE dtr_attachments ADD COLUMN department_id INT UNSIGNED NULL DEFAULT NULL COMMENT ''Optional department scope for this upload'' AFTER employee_id',
    'SELECT "department_id already exists or dtr_attachments absent" AS notice'
);
PREPARE _s FROM @s; EXECUTE _s; DEALLOCATE PREPARE _s;

-- Verify
SELECT COLUMN_NAME, COLUMN_TYPE, IS_NULLABLE
FROM information_schema.COLUMNS
WHERE TABLE_SCHEMA = DATABASE()
  AND TABLE_NAME   = 'dtr_attachments'
ORDER BY ORDINAL_POSITION;
