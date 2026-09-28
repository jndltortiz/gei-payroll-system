-- Migration 034: Employee status effective date
-- Tracks when the current employment status took effect, so payroll generation
-- can include employees who resigned/terminated partway through a pay period.

ALTER TABLE `employees`
  MODIFY COLUMN `employee_status` ENUM('ACTIVE','RESIGNED','TERMINATED') NOT NULL DEFAULT 'ACTIVE',
  ADD COLUMN `status_effective_date` DATE DEFAULT NULL AFTER `employee_status`,
  ADD KEY `idx_employee_status_effective_date` (`employee_status`, `status_effective_date`);
