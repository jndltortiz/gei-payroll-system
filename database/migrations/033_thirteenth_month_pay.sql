-- Migration 033: 13th month payroll period type
-- Adds a standalone period type for annual 13th month pay generation.

ALTER TABLE `payroll_periods`
  MODIFY COLUMN `period_type` ENUM('REGULAR','ACCRUED_PAY','THIRTEENTH_MONTH') NOT NULL DEFAULT 'REGULAR';
