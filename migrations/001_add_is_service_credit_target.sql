-- ============================================================
-- Migration 001 — Add is_service_credit_target to allowance_types
--
-- WHY: The payroll generation used strpos() on the allowance name
--      to detect which allowance type should receive merged service
--      credit pay. Renaming the allowance in settings broke payroll
--      silently. A DB flag is the correct approach.
--
-- HOW TO RUN: Execute this script once against gei_payroll_system.
--             Safe to run on a live database — ALTER TABLE on a small
--             lookup table is near-instant and non-destructive.
-- ============================================================

-- Step 1: Add the column (DEFAULT 0 = no existing row is marked yet)
ALTER TABLE allowance_types
    ADD COLUMN is_service_credit_target TINYINT(1) NOT NULL DEFAULT 0
    COMMENT 'Marks the allowance type that receives merged service credit pay during payroll generation. Only one row should be 1 at a time.'
    AFTER is_active;

-- Step 2: Mark the correct existing row.
--         Matches the same patterns the old strpos() code used, so this
--         covers "Additional Assignment Pay", "Add'l Assignment", "Addl Assign", etc.
--         Review the UPDATE result before running payroll to confirm exactly
--         one row is set to 1. If your allowance has a different name, adjust
--         the LIKE pattern below.
UPDATE allowance_types
SET    is_service_credit_target = 1
WHERE  is_active = 1
  AND  (
           allowance_name LIKE '%Additional%Assignment%'
        OR allowance_name LIKE '%Add_l%Assign%'
        OR allowance_name LIKE '%Addl%Assign%'
       );

-- Step 3: Verify — should return exactly ONE row with is_service_credit_target = 1.
--         If zero rows: update the LIKE pattern above to match your allowance name.
--         If more than one row: run  UPDATE allowance_types SET is_service_credit_target = 0;
--         then re-run Step 2 with a more specific pattern.
SELECT allowance_type_id, allowance_name, is_active, is_service_credit_target
FROM   allowance_types
WHERE  is_service_credit_target = 1;
