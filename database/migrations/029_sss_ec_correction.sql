-- =============================================================================
-- Migration 029: Add EC (Employees' Compensation) to SSS employer_share
-- =============================================================================
--
-- Migration 028 inserted SSS brackets with employer_share = MSC × 10% only,
-- but the SSS official schedule also includes a mandatory EC fund contribution
-- paid exclusively by the employer:
--   ₱10 per month  for MSC < ₱15,000
--   ₱30 per month  for MSC ≥ ₱15,000
--
-- This does NOT affect employees — EC is employer-only.
-- It corrects the employer_sss_share stored in payroll_records for
-- newly generated payrolls. Existing released records are informational
-- and do not need to be changed.
-- =============================================================================

-- MSC ≥ ₱15,000 → EC = ₱30
UPDATE sss_contribution_table
SET employer_share     = employer_share     + 30.00,
    total_contribution = total_contribution + 30.00
WHERE is_active = 1
  AND monthly_salary_credit >= 15000.00;

-- MSC < ₱15,000 → EC = ₱10
UPDATE sss_contribution_table
SET employer_share     = employer_share     + 10.00,
    total_contribution = total_contribution + 10.00
WHERE is_active = 1
  AND monthly_salary_credit < 15000.00;

-- Verify (run as SELECT after commit)
-- SELECT monthly_salary_credit, employee_share, employer_share, total_contribution
-- FROM sss_contribution_table WHERE is_active = 1 ORDER BY monthly_salary_credit;
-- Expected top bracket (MSC 35000): EE=1750, ER=3530, Total=5280
