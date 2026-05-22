-- 006_manual_adjustments.sql
-- Adds is_adjustment + adjustment_label columns to payroll_allowances and
-- payroll_deductions so the admin can attach one-time, period-only entries
-- directly in the payroll edit modal without touching global type tables.
--
-- Two reserved types (is_active=0) are inserted as FK anchors for manual rows.
-- is_active=0 means they are NEVER auto-applied during payroll generation.
--
-- Run ONCE. Safe to skip if columns already exist (check beforehand).

ALTER TABLE payroll_allowances
  ADD COLUMN is_adjustment    TINYINT(1)   NOT NULL DEFAULT 0   AFTER amount,
  ADD COLUMN adjustment_label VARCHAR(120) NULL                 AFTER is_adjustment;

ALTER TABLE payroll_deductions
  ADD COLUMN is_adjustment    TINYINT(1)   NOT NULL DEFAULT 0   AFTER amount,
  ADD COLUMN adjustment_label VARCHAR(120) NULL                 AFTER is_adjustment;

-- Reserved allowance type for manual entries (is_active=0 → never auto-generated)
INSERT INTO allowance_types (allowance_name, default_amount, is_active, is_service_credit_target)
VALUES ('Adjustment', 0.00, 0, 0);

-- Reserved deduction type for manual entries (is_active=0 → never auto-generated)
INSERT INTO deduction_types (deduction_name, deduction_value_type, deduction_amount, deduction_rate, is_active, is_government, is_loan, is_absence_deduction)
VALUES ('Adjustment', 'FIXED', 0.00, 0.00, 0, 0, 0, 0);
