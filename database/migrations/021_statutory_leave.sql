-- migrations/021_statutory_leave.sql
-- Marks government-mandated leave types as statutory so they are excluded from
-- the EOSY excess leave settlement and absence deduction calculations.
--
-- Philippine statutory leaves covered:
--   RA 11210  — Expanded Maternity Leave (105 days / 120 days solo mother / 60 days miscarriage)
--   RA 8187   — Paternity Leave (7 days)
--   RA 8972   — Solo Parent Leave (7 days)
--   RA 9262   — Violence Against Women & Children (VAWC) Leave (10 days)
--
-- Run once manually. The ALTER is NOT idempotent; skip if column already exists.

-- 1. Add is_statutory flag to leave_types
ALTER TABLE `leave_types`
    ADD COLUMN `is_statutory` TINYINT(1) NOT NULL DEFAULT 0
    AFTER `leave_name`;

-- 2. Seed known statutory leave types by name pattern (case-insensitive)
--    Matches any existing leave type whose name contains one of these keywords.
UPDATE `leave_types`
SET `is_statutory` = 1
WHERE LOWER(`leave_name`) LIKE '%matern%'
   OR LOWER(`leave_name`) LIKE '%patern%'
   OR LOWER(`leave_name`) LIKE '%solo parent%'
   OR LOWER(`leave_name`) LIKE '%vawc%'
   OR LOWER(`leave_name`) LIKE '%violence against women%';
