-- Migration 015: Add personal_email column to employees table
-- Run once. Safe to skip if already applied.

ALTER TABLE employees
    ADD COLUMN IF NOT EXISTS personal_email VARCHAR(191) NULL DEFAULT NULL
        COMMENT 'Personal (non-school) email address, editable by employee'
    AFTER email;
