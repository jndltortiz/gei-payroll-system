-- Migration 014: Add shared payroll_number to payroll_periods
-- A single payroll_number belongs to the entire batch (period), not individual employee records.
-- Format: PR-YYYY-MM-NNN (sequential per year-month, ordered by period_id).

ALTER TABLE payroll_periods
    ADD COLUMN payroll_number VARCHAR(20) NULL AFTER period_id;

-- Backfill existing periods: sequence resets per year-month, ordered by period_id
UPDATE payroll_periods p1
JOIN (
    SELECT p.period_id,
           CONCAT(
               'PR-',
               YEAR(p.pay_period_start), '-',
               LPAD(MONTH(p.pay_period_start), 2, '0'), '-',
               LPAD(
                   (SELECT COUNT(*) + 1
                    FROM payroll_periods p2
                    WHERE YEAR(p2.pay_period_start)  = YEAR(p.pay_period_start)
                      AND MONTH(p2.pay_period_start) = MONTH(p.pay_period_start)
                      AND p2.period_id < p.period_id),
                   3, '0'
               )
           ) AS pno
    FROM payroll_periods p
) sub ON p1.period_id = sub.period_id
SET p1.payroll_number = sub.pno
WHERE p1.payroll_number IS NULL;
