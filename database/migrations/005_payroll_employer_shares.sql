-- =============================================================================
-- Migration 005: Employer Contribution Columns in payroll_records
-- Adds employer_sss_share, employer_philhealth_share, employer_pagibig_share
-- to payroll_records for informational display in Admin/Principal portals.
--
-- SAFE TO RUN: additive only — DEFAULT 0.00 means existing records are unaffected.
-- These columns do NOT affect net_pay or total_deductions.
-- payroll-utils.php recalculatePayrollTotals() does NOT touch these columns.
-- =============================================================================

ALTER TABLE payroll_records
    ADD COLUMN employer_sss_share        DECIMAL(10,2) NOT NULL DEFAULT 0.00 AFTER net_pay,
    ADD COLUMN employer_philhealth_share DECIMAL(10,2) NOT NULL DEFAULT 0.00 AFTER employer_sss_share,
    ADD COLUMN employer_pagibig_share    DECIMAL(10,2) NOT NULL DEFAULT 0.00 AFTER employer_philhealth_share;

-- Verify
-- SELECT payroll_id, employer_sss_share, employer_philhealth_share, employer_pagibig_share
-- FROM payroll_records LIMIT 5;
