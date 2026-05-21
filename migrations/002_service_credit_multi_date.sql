-- ────────────────────────────────────────────────────────────────────────────
-- Migration 002: Service Credit Multi-Date Support
--
-- Adds service_credit_dates child table so a single service credit request
-- can cover multiple work dates. Existing single-date records are migrated
-- automatically — each becomes exactly one child row.
--
-- Prerequisites : migration 001_add_is_service_credit_target.sql
-- Run against   : gei_payroll_system database
-- Safe to re-run: Step 2 uses NOT EXISTS guard to skip already-migrated rows.
--
-- After this migration:
--   service_credits.work_date      = first (earliest) work date in the request
--   service_credits.days           = SUM of days across all date rows
--   service_credits.equivalent_pay = SUM of equivalent_pay across all date rows
-- ────────────────────────────────────────────────────────────────────────────

-- ── Step 1: Create child table ────────────────────────────────────────────
CREATE TABLE IF NOT EXISTS service_credit_dates (
    sc_date_id          INT UNSIGNED   NOT NULL AUTO_INCREMENT,
    service_credit_id   INT UNSIGNED   NOT NULL,
    work_date           DATE           NOT NULL,
    days                DECIMAL(5,2)   NOT NULL DEFAULT 1.00,
    equivalent_pay      DECIMAL(10,2)  NOT NULL DEFAULT 0.00,
    created_at          TIMESTAMP      NOT NULL DEFAULT CURRENT_TIMESTAMP,

    PRIMARY KEY (sc_date_id),
    INDEX idx_sc_id     (service_credit_id),
    INDEX idx_work_date (work_date),

    CONSTRAINT fk_sc_dates_sc
        FOREIGN KEY (service_credit_id)
        REFERENCES service_credits (service_credit_id)
        ON DELETE CASCADE
        ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ── Step 2: Migrate existing single-date records into the child table ─────
-- Each existing service_credits row becomes exactly one service_credit_dates row.
-- NOT EXISTS prevents duplicate rows if this migration is re-run.
INSERT INTO service_credit_dates
    (service_credit_id, work_date, days, equivalent_pay, created_at)
SELECT sc.service_credit_id,
       sc.work_date,
       sc.days,
       sc.equivalent_pay,
       sc.created_at
FROM   service_credits sc
WHERE  sc.work_date IS NOT NULL
  AND  NOT EXISTS (
           SELECT 1
           FROM   service_credit_dates scd
           WHERE  scd.service_credit_id = sc.service_credit_id
       );

-- ── Verification ──────────────────────────────────────────────────────────
-- Every service_credits row should now have ≥ 1 matching child row.
-- Rows with date_rows = 0 had a NULL work_date and need manual review.
SELECT sc.service_credit_id,
       sc.work_date          AS legacy_date,
       sc.days               AS legacy_days,
       sc.equivalent_pay     AS legacy_pay,
       sc.status,
       COUNT(scd.sc_date_id) AS date_rows
FROM   service_credits sc
LEFT   JOIN service_credit_dates scd
         ON scd.service_credit_id = sc.service_credit_id
GROUP  BY sc.service_credit_id
ORDER  BY sc.service_credit_id;
