-- migrations/013_service_credit_per_date_approval.sql
-- Per-date approval workflow for service credits + target payroll period field.
-- Safe to re-run: every ALTER is guarded with information_schema checks.
--
-- Changes:
--   service_credits:       +PARTIALLY_APPROVED enum value, +target_period_id INT NULL
--   service_credit_dates:  +status ENUM, +rejection_reason, +approved_by INT, +approved_at DATETIME
-- Backfill: sets service_credit_dates.status based on current parent status.

-- ── 1. Add PARTIALLY_APPROVED to service_credits.status enum ────────────────
SET @sc_col_type = (
    SELECT COLUMN_TYPE FROM information_schema.COLUMNS
    WHERE TABLE_SCHEMA = DATABASE()
      AND TABLE_NAME   = 'service_credits'
      AND COLUMN_NAME  = 'status'
);
SET @sql1 = IF(
    LOCATE('PARTIALLY_APPROVED', @sc_col_type) = 0,
    "ALTER TABLE service_credits MODIFY COLUMN status ENUM('DRAFT','PENDING','APPROVED','PARTIALLY_APPROVED','APPLIED','RELEASED','REJECTED','ARCHIVED') NOT NULL DEFAULT 'DRAFT'",
    "SELECT 'PARTIALLY_APPROVED already in service_credits.status' AS notice"
);
PREPARE _s FROM @sql1; EXECUTE _s; DEALLOCATE PREPARE _s;

-- ── 2. Add target_period_id to service_credits ───────────────────────────────
SET @has_tpid = (
    SELECT COUNT(*) FROM information_schema.COLUMNS
    WHERE TABLE_SCHEMA = DATABASE()
      AND TABLE_NAME   = 'service_credits'
      AND COLUMN_NAME  = 'target_period_id'
);
SET @sql2 = IF(
    @has_tpid = 0,
    'ALTER TABLE service_credits ADD COLUMN target_period_id INT NULL DEFAULT NULL AFTER archived_at',
    "SELECT 'target_period_id already exists on service_credits' AS notice"
);
PREPARE _s FROM @sql2; EXECUTE _s; DEALLOCATE PREPARE _s;

-- ── 3. Add status to service_credit_dates ───────────────────────────────────
SET @has_dstatus = (
    SELECT COUNT(*) FROM information_schema.COLUMNS
    WHERE TABLE_SCHEMA = DATABASE()
      AND TABLE_NAME   = 'service_credit_dates'
      AND COLUMN_NAME  = 'status'
);
SET @sql3 = IF(
    @has_dstatus = 0,
    "ALTER TABLE service_credit_dates ADD COLUMN status ENUM('PENDING','APPROVED','REJECTED') NOT NULL DEFAULT 'PENDING' AFTER equivalent_pay",
    "SELECT 'status already exists on service_credit_dates' AS notice"
);
PREPARE _s FROM @sql3; EXECUTE _s; DEALLOCATE PREPARE _s;

-- ── 4. Add rejection_reason to service_credit_dates ─────────────────────────
SET @has_drej = (
    SELECT COUNT(*) FROM information_schema.COLUMNS
    WHERE TABLE_SCHEMA = DATABASE()
      AND TABLE_NAME   = 'service_credit_dates'
      AND COLUMN_NAME  = 'rejection_reason'
);
SET @sql4 = IF(
    @has_drej = 0,
    'ALTER TABLE service_credit_dates ADD COLUMN rejection_reason VARCHAR(500) NULL DEFAULT NULL AFTER status',
    "SELECT 'rejection_reason already exists on service_credit_dates' AS notice"
);
PREPARE _s FROM @sql4; EXECUTE _s; DEALLOCATE PREPARE _s;

-- ── 5. Add approved_by to service_credit_dates ──────────────────────────────
SET @has_dappr_by = (
    SELECT COUNT(*) FROM information_schema.COLUMNS
    WHERE TABLE_SCHEMA = DATABASE()
      AND TABLE_NAME   = 'service_credit_dates'
      AND COLUMN_NAME  = 'approved_by'
);
SET @sql5 = IF(
    @has_dappr_by = 0,
    'ALTER TABLE service_credit_dates ADD COLUMN approved_by INT NULL DEFAULT NULL AFTER rejection_reason',
    "SELECT 'approved_by already exists on service_credit_dates' AS notice"
);
PREPARE _s FROM @sql5; EXECUTE _s; DEALLOCATE PREPARE _s;

-- ── 6. Add approved_at to service_credit_dates ──────────────────────────────
SET @has_dappr_at = (
    SELECT COUNT(*) FROM information_schema.COLUMNS
    WHERE TABLE_SCHEMA = DATABASE()
      AND TABLE_NAME   = 'service_credit_dates'
      AND COLUMN_NAME  = 'approved_at'
);
SET @sql6 = IF(
    @has_dappr_at = 0,
    'ALTER TABLE service_credit_dates ADD COLUMN approved_at DATETIME NULL DEFAULT NULL AFTER approved_by',
    "SELECT 'approved_at already exists on service_credit_dates' AS notice"
);
PREPARE _s FROM @sql6; EXECUTE _s; DEALLOCATE PREPARE _s;

-- ── 7. Backfill service_credit_dates.status from parent record ───────────────
-- APPROVED / PARTIALLY_APPROVED / APPLIED / RELEASED parents → dates get APPROVED
UPDATE service_credit_dates scd
JOIN   service_credits sc ON sc.service_credit_id = scd.service_credit_id
SET    scd.status = 'APPROVED'
WHERE  sc.status IN ('APPROVED','PARTIALLY_APPROVED','APPLIED','RELEASED')
  AND  scd.status = 'PENDING';

-- REJECTED parents → dates get REJECTED
UPDATE service_credit_dates scd
JOIN   service_credits sc ON sc.service_credit_id = scd.service_credit_id
SET    scd.status = 'REJECTED'
WHERE  sc.status = 'REJECTED'
  AND  scd.status = 'PENDING';

-- ── 8. Verify ────────────────────────────────────────────────────────────────
SELECT 'service_credits changes:' AS info;
SELECT COLUMN_NAME, COLUMN_TYPE, IS_NULLABLE, COLUMN_DEFAULT
FROM information_schema.COLUMNS
WHERE TABLE_SCHEMA = DATABASE()
  AND TABLE_NAME   = 'service_credits'
  AND COLUMN_NAME  IN ('status','target_period_id','archived_at')
ORDER BY ORDINAL_POSITION;

SELECT 'service_credit_dates changes:' AS info;
SELECT COLUMN_NAME, COLUMN_TYPE, IS_NULLABLE, COLUMN_DEFAULT
FROM information_schema.COLUMNS
WHERE TABLE_SCHEMA = DATABASE()
  AND TABLE_NAME   = 'service_credit_dates'
  AND COLUMN_NAME  IN ('status','rejection_reason','approved_by','approved_at')
ORDER BY ORDINAL_POSITION;
