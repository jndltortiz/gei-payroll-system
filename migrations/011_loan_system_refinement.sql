-- ============================================================
-- Migration 011 — Loan System Refinement
-- Date   : 2026-05-24
-- Summary: Establishes proper FK links between loan_types and
--          deduction_types, adds loan_id traceability on payroll
--          deduction rows, introduces loan_payment_log for full
--          audit history, and adds PAUSED / ARCHIVED lifecycle
--          statuses plus provider_name to employee_loans.
-- ============================================================

-- 1. Link each loan type to its matching payroll deduction type
ALTER TABLE loan_types
  ADD COLUMN deduction_type_id INT NULL AFTER loan_type_id;

ALTER TABLE loan_types
  ADD CONSTRAINT fk_lt_deduction_type
      FOREIGN KEY (deduction_type_id) REFERENCES deduction_types(deduction_type_id);

-- Seed: map existing loan types to deduction types by keyword
UPDATE loan_types
SET deduction_type_id = (
    SELECT deduction_type_id FROM deduction_types
    WHERE deduction_name LIKE '%SSS%' AND is_loan = 1 LIMIT 1
)
WHERE loan_name LIKE '%SSS%';

UPDATE loan_types
SET deduction_type_id = (
    SELECT deduction_type_id FROM deduction_types
    WHERE (deduction_name LIKE '%HDMF%' OR deduction_name LIKE '%Pag-IBIG%' OR deduction_name LIKE '%pagibig%')
      AND is_loan = 1 LIMIT 1
)
WHERE loan_name LIKE '%Pag-IBIG%' OR loan_name LIKE '%HDMF%';

UPDATE loan_types
SET deduction_type_id = (
    SELECT deduction_type_id FROM deduction_types
    WHERE deduction_name LIKE '%PERAA%' AND is_loan = 1 LIMIT 1
)
WHERE loan_name LIKE '%PERAA%';

UPDATE loan_types
SET deduction_type_id = (
    SELECT deduction_type_id FROM deduction_types
    WHERE deduction_name LIKE '%Rural%' AND is_loan = 1 LIMIT 1
)
WHERE loan_name LIKE '%Rural%';

-- 2. Add loan_id FK to payroll_deductions for per-loan traceability
ALTER TABLE payroll_deductions
  ADD COLUMN loan_id INT NULL AFTER deduction_type_id;

ALTER TABLE payroll_deductions
  ADD CONSTRAINT fk_pd_loan
      FOREIGN KEY (loan_id) REFERENCES employee_loans(loan_id);

-- 3. Extend employee_loans with new fields and lifecycle columns
ALTER TABLE employee_loans
  ADD COLUMN provider_name     VARCHAR(200) NULL    AFTER account_reference,
  ADD COLUMN cancelled_reason  TEXT         NULL    AFTER denied_reason,
  ADD COLUMN paused_at         TIMESTAMP    NULL    AFTER updated_at,
  ADD COLUMN archived_at       TIMESTAMP    NULL    AFTER paused_at,
  ADD COLUMN archived_by       INT          NULL    AFTER archived_at;

ALTER TABLE employee_loans
  ADD CONSTRAINT fk_el_archived_by
      FOREIGN KEY (archived_by) REFERENCES users(user_id);

-- 4. Add PAUSED and ARCHIVED to the status enum
ALTER TABLE employee_loans
  MODIFY COLUMN status
    ENUM('PENDING','ACTIVE','PAUSED','COMPLETED','CANCELLED','DENIED','ARCHIVED')
    NOT NULL DEFAULT 'PENDING';

-- 5. Create loan_payment_log for full manual + payroll payment history
CREATE TABLE IF NOT EXISTS loan_payment_log (
  payment_id      INT            AUTO_INCREMENT PRIMARY KEY,
  loan_id         INT            NOT NULL,
  payment_date    DATE           NOT NULL,
  amount          DECIMAL(12,2)  NOT NULL,
  payment_channel VARCHAR(100)   NULL  COMMENT 'PAYROLL | MANUAL | BANK_DEPOSIT | etc.',
  receipt_number  VARCHAR(100)   NULL,
  notes           TEXT           NULL,
  encoded_by      INT            NULL,
  created_at      TIMESTAMP      NOT NULL DEFAULT CURRENT_TIMESTAMP,
  CONSTRAINT fk_plog_loan        FOREIGN KEY (loan_id)    REFERENCES employee_loans(loan_id),
  CONSTRAINT fk_plog_encoded_by  FOREIGN KEY (encoded_by) REFERENCES users(user_id)
);

-- ============================================================
-- IMPORTANT NOTE ON monthly_deduction VALUES
-- ============================================================
-- The monthly_deduction column stores the MONTHLY AMORTIZATION
-- (computed by the add-loan form as total_payable / term_months).
-- Prior to this migration, generate-payroll.php applied the full
-- monthly amount once per payroll run (bug for semi-monthly).
-- This migration DOES NOT change existing data — the stored values
-- are already monthly amortizations. The payroll generation fix
-- (dividing by periodDivisor) corrects the per-period computation.
-- No data backfill is required.
-- ============================================================
