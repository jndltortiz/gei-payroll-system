-- migrations/020_accrued_pay.sql
-- Introduces ACCRUED_PAY payroll period type for GEI end-of-school-year settlement.
-- Adds leave allocation setting and settlement deduction types.
-- Run once manually. ALTER TABLE statements are NOT idempotent; skip columns
-- that already exist if re-running.

-- 1. Distinguish regular semi-monthly periods from the annual EOSY settlement period
ALTER TABLE `payroll_periods`
    ADD COLUMN `period_type` ENUM('REGULAR','ACCRUED_PAY') NOT NULL DEFAULT 'REGULAR'
    AFTER `period_name`;

-- 2. Configurable annual leave allocation (GEI default = 30 days = 1 calendar month)
--    Excess leave beyond this threshold is deducted in the ACCRUED_PAY period.
ALTER TABLE `payroll_settings`
    ADD COLUMN `leave_allocation_days` DECIMAL(5,2) NOT NULL DEFAULT 30.00
    AFTER `default_paid_leave_days`;

-- 3. Flag to mark deduction types that are ONLY applied in ACCRUED_PAY periods
--    (half-day settlement, excess leave settlement).
--    Regular deduction types keep is_accrued_pay_deduction = 0.
ALTER TABLE `deduction_types`
    ADD COLUMN `is_accrued_pay_deduction` TINYINT(1) NOT NULL DEFAULT 0
    AFTER `is_absence_deduction`;

-- 4. Half-Day Settlement deduction type (ACCRUED_PAY only)
--    Amount is computed dynamically (half_day_count × daily_rate ÷ 2); stored as 0 here.
INSERT INTO `deduction_types`
    (`deduction_name`, `deduction_value_type`, `deduction_amount`, `deduction_rate`,
     `is_government`, `is_loan`, `is_absence_deduction`, `is_accrued_pay_deduction`, `is_active`)
SELECT 'Half-Day Settlement', 'FIXED', 0.00, 0.00, 0, 0, 0, 1, 1
FROM DUAL
WHERE NOT EXISTS (
    SELECT 1 FROM `deduction_types` WHERE `deduction_name` = 'Half-Day Settlement'
);

-- 5. Excess Leave Settlement deduction type (ACCRUED_PAY only)
--    Amount is computed dynamically (excess_days × daily_rate); stored as 0 here.
INSERT INTO `deduction_types`
    (`deduction_name`, `deduction_value_type`, `deduction_amount`, `deduction_rate`,
     `is_government`, `is_loan`, `is_absence_deduction`, `is_accrued_pay_deduction`, `is_active`)
SELECT 'Excess Leave Settlement', 'FIXED', 0.00, 0.00, 0, 0, 0, 1, 1
FROM DUAL
WHERE NOT EXISTS (
    SELECT 1 FROM `deduction_types` WHERE `deduction_name` = 'Excess Leave Settlement'
);
