-- =============================================================================
-- SSS 2023 Schedule Extension Migration
-- SSS Circular No. 2022-033, effective January 2023
-- Extends MSC ceiling from PHP 20,000 to PHP 30,000
-- Employee rate: 4.5% of MSC (unchanged)
-- Employer rate: 9.5% of MSC (corrected from old 8.5%)
-- =============================================================================
-- SAFE TO RUN: only narrows one existing row and appends new rows.
-- Released payrolls are not affected (amounts frozen in payroll_deductions).
-- =============================================================================

START TRANSACTION;

-- Step 1: Narrow the old catch-all bracket so it no longer swallows salaries above 20,249.99.
-- Before: min=19750.00, max=999999.99, MSC=20000, employee=900.00
-- After : min=19750.00, max=20249.99,  MSC=20000, employee=900.00 (unchanged)
UPDATE sss_contribution_table
SET max_salary = 20249.99
WHERE range_id = 33
  AND min_salary = 19750.00
  AND max_salary = 999999.99;   -- guard: only runs if row is exactly as expected

-- Step 2: Insert new brackets for MSC 20,500 – 30,000 (2023 schedule extension).
-- Employee share = MSC * 0.045
-- Employer share = MSC * 0.095
-- Total          = MSC * 0.140
INSERT INTO sss_contribution_table
    (min_salary, max_salary, monthly_salary_credit,
     employee_share, employer_share, total_contribution,
     effective_date, is_active)
VALUES
    (20250.00, 20749.99, 20500.00,  922.50, 1947.50, 2870.00, '2023-01-01', 1),
    (20750.00, 21249.99, 21000.00,  945.00, 1995.00, 2940.00, '2023-01-01', 1),
    (21250.00, 21749.99, 21500.00,  967.50, 2042.50, 3010.00, '2023-01-01', 1),
    (21750.00, 22249.99, 22000.00,  990.00, 2090.00, 3080.00, '2023-01-01', 1),
    (22250.00, 22749.99, 22500.00, 1012.50, 2137.50, 3150.00, '2023-01-01', 1),
    (22750.00, 23249.99, 23000.00, 1035.00, 2185.00, 3220.00, '2023-01-01', 1),
    (23250.00, 23749.99, 23500.00, 1057.50, 2232.50, 3290.00, '2023-01-01', 1),
    (23750.00, 24249.99, 24000.00, 1080.00, 2280.00, 3360.00, '2023-01-01', 1),
    (24250.00, 24749.99, 24500.00, 1102.50, 2327.50, 3430.00, '2023-01-01', 1),
    (24750.00, 25249.99, 25000.00, 1125.00, 2375.00, 3500.00, '2023-01-01', 1),
    (25250.00, 25749.99, 25500.00, 1147.50, 2422.50, 3570.00, '2023-01-01', 1),
    (25750.00, 26249.99, 26000.00, 1170.00, 2470.00, 3640.00, '2023-01-01', 1),
    (26250.00, 26749.99, 26500.00, 1192.50, 2517.50, 3710.00, '2023-01-01', 1),
    (26750.00, 27249.99, 27000.00, 1215.00, 2565.00, 3780.00, '2023-01-01', 1),
    (27250.00, 27749.99, 27500.00, 1237.50, 2612.50, 3850.00, '2023-01-01', 1),
    (27750.00, 28249.99, 28000.00, 1260.00, 2660.00, 3920.00, '2023-01-01', 1),
    (28250.00, 28749.99, 28500.00, 1282.50, 2707.50, 3990.00, '2023-01-01', 1),
    (28750.00, 29249.99, 29000.00, 1305.00, 2755.00, 4060.00, '2023-01-01', 1),
    (29250.00, 29749.99, 29500.00, 1327.50, 2802.50, 4130.00, '2023-01-01', 1),
    (29750.00, 999999.99, 30000.00, 1350.00, 2850.00, 4200.00, '2023-01-01', 1);

-- Verification: confirm no gaps or overlaps in the critical 19750–30000 band
-- Run this SELECT after committing to validate; expected: 21 contiguous rows.
-- SELECT range_id, min_salary, max_salary, monthly_salary_credit, employee_share
-- FROM sss_contribution_table
-- WHERE min_salary >= 19750.00 AND is_active = 1
-- ORDER BY min_salary;

COMMIT;
