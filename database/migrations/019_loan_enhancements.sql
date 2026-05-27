-- migrations/019_loan_enhancements.sql
-- Loan Management enhancements: employee self-service, document uploads,
-- auto balance reconciliation, and one-time payroll deduction control.
-- Run once manually. ALTER TABLE statements are NOT idempotent; skip columns
-- that already exist if re-running.

-- 1. Track who filed the loan: ADMIN (HR entered it) or EMPLOYEE (self-service)
ALTER TABLE `employee_loans`
    ADD COLUMN `filed_by` ENUM('ADMIN','EMPLOYEE') NOT NULL DEFAULT 'ADMIN'
    AFTER `status`;

-- 2. Admin flag: skip the very next payroll deduction for this loan
ALTER TABLE `employee_loans`
    ADD COLUMN `skip_next_deduction` TINYINT(1) NOT NULL DEFAULT 0
    AFTER `filed_by`;

-- 3. Admin override: use this amount instead of the computed amount on the next payroll run only
ALTER TABLE `employee_loans`
    ADD COLUMN `next_deduction_override` DECIMAL(10,2) DEFAULT NULL
    AFTER `skip_next_deduction`;

-- 4. Link payment log entries back to the payroll record that generated them
ALTER TABLE `loan_payment_log`
    ADD COLUMN `payroll_id` INT DEFAULT NULL
    AFTER `loan_id`;

-- 5. Optional index for payroll-linked payment log lookups
ALTER TABLE `loan_payment_log`
    ADD KEY `idx_lpl_payroll` (`payroll_id`);

-- 6. Loan documents table (attachments uploaded by employee or admin)
CREATE TABLE IF NOT EXISTS `loan_documents` (
    `document_id`    INT UNSIGNED    NOT NULL AUTO_INCREMENT,
    `loan_id`        INT             NOT NULL,
    `file_name`      VARCHAR(255)    NOT NULL,
    `original_name`  VARCHAR(255)    NOT NULL,
    `file_size`      INT UNSIGNED    NOT NULL DEFAULT 0,
    `mime_type`      VARCHAR(100)    NOT NULL DEFAULT 'application/octet-stream',
    `uploaded_by`    INT             DEFAULT NULL,
    `filed_by_role`  ENUM('ADMIN','EMPLOYEE') NOT NULL DEFAULT 'ADMIN',
    `created_at`     TIMESTAMP       NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (`document_id`),
    KEY `idx_loan_doc` (`loan_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
