-- ─────────────────────────────────────────────────────────────────────────────
-- Migration 004: Absence → Payroll Deduction Integration
--
-- Adds:
--   deduction_types.is_absence_deduction  — marks the auto-computed absence type;
--                                           excluded from the normal auto-apply loop
--   payroll_deductions.absence_days       — excess leave days this row covers
--   payroll_deductions.notes              — JSON breakdown for payslip display
--   Seeded row: "Absence Deduction" deduction type
--
-- Prerequisites : migrations 001, 002, 003
-- Run against   : gei_payroll_system database
-- Safe to re-run: all DDL steps guarded with information_schema checks
-- ─────────────────────────────────────────────────────────────────────────────

-- ── Step 1: Add is_absence_deduction to deduction_types ──────────────────────
SET @ex1 = (
    SELECT COUNT(*) FROM information_schema.COLUMNS
    WHERE TABLE_SCHEMA = DATABASE()
      AND TABLE_NAME   = 'deduction_types'
      AND COLUMN_NAME  = 'is_absence_deduction'
);
SET @s1 = IF(@ex1 = 0,
    'ALTER TABLE deduction_types ADD COLUMN is_absence_deduction TINYINT(1) NOT NULL DEFAULT 0 AFTER is_loan',
    'SELECT "is_absence_deduction already exists" AS notice'
);
PREPARE _s FROM @s1; EXECUTE _s; DEALLOCATE PREPARE _s;

-- ── Step 2: Add absence_days to payroll_deductions ───────────────────────────
SET @ex2 = (
    SELECT COUNT(*) FROM information_schema.COLUMNS
    WHERE TABLE_SCHEMA = DATABASE()
      AND TABLE_NAME   = 'payroll_deductions'
      AND COLUMN_NAME  = 'absence_days'
);
SET @s2 = IF(@ex2 = 0,
    'ALTER TABLE payroll_deductions ADD COLUMN absence_days DECIMAL(5,2) NULL DEFAULT NULL AFTER amount',
    'SELECT "absence_days already exists" AS notice'
);
PREPARE _s FROM @s2; EXECUTE _s; DEALLOCATE PREPARE _s;

-- ── Step 3: Add notes to payroll_deductions ──────────────────────────────────
SET @ex3 = (
    SELECT COUNT(*) FROM information_schema.COLUMNS
    WHERE TABLE_SCHEMA = DATABASE()
      AND TABLE_NAME   = 'payroll_deductions'
      AND COLUMN_NAME  = 'notes'
);
SET @s3 = IF(@ex3 = 0,
    'ALTER TABLE payroll_deductions ADD COLUMN notes TEXT NULL DEFAULT NULL AFTER absence_days',
    'SELECT "notes already exists" AS notice'
);
PREPARE _s FROM @s3; EXECUTE _s; DEALLOCATE PREPARE _s;

-- ── Step 4: Seed "Absence Deduction" deduction type ──────────────────────────
-- Inserts only if no row with this name already exists.
INSERT INTO deduction_types
    (deduction_name, deduction_value_type, deduction_amount, deduction_rate,
     is_government, is_loan, is_absence_deduction, is_active)
SELECT 'Absence Deduction', 'FIXED', 0.00, 0.00, 0, 0, 1, 1
WHERE NOT EXISTS (
    SELECT 1 FROM deduction_types WHERE deduction_name = 'Absence Deduction'
);

-- ── Verification ──────────────────────────────────────────────────────────────
SELECT 'deduction_types' AS checked,
       SUM(is_absence_deduction) AS absence_deduction_types,
       COUNT(*)                  AS total_active
FROM deduction_types
WHERE is_active = 1;

SELECT TABLE_NAME, COLUMN_NAME, COLUMN_TYPE, IS_NULLABLE
FROM information_schema.COLUMNS
WHERE TABLE_SCHEMA = DATABASE()
  AND TABLE_NAME   IN ('deduction_types', 'payroll_deductions')
  AND COLUMN_NAME  IN ('is_absence_deduction', 'absence_days', 'notes')
ORDER BY TABLE_NAME, ORDINAL_POSITION;
