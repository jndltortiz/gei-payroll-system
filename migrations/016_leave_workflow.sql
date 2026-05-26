-- migrations/016_leave_workflow.sql
-- Enforces GEI's proper leave approval workflow:
--   Employee files → Admin reviews & forwards → Principal decides per date → Admin records
-- Safe to inspect before running; run once manually.

-- 1. Workflow tracking on leave_requests
ALTER TABLE leave_requests
  ADD COLUMN workflow_status  ENUM('PENDING_REVIEW','FORWARDED','RECORDED') NOT NULL DEFAULT 'PENDING_REVIEW' AFTER status,
  ADD COLUMN admin_note       TEXT NULL AFTER workflow_status,
  ADD COLUMN forwarded_at     DATETIME NULL AFTER admin_note,
  ADD COLUMN forwarded_by     INT NULL AFTER forwarded_at,
  ADD COLUMN is_backdated     TINYINT(1) NOT NULL DEFAULT 0 AFTER forwarded_by,
  ADD COLUMN backdate_reason  TEXT NULL AFTER is_backdated;

-- 2. Backdated filing flag per leave type
ALTER TABLE leave_types
  ADD COLUMN allow_backdated TINYINT(1) NOT NULL DEFAULT 0;

-- 3. Enable backdated filing for sick and emergency leave by default
UPDATE leave_types
SET allow_backdated = 1
WHERE LOWER(leave_name) LIKE '%sick%'
   OR LOWER(leave_name) LIKE '%emergency%';

-- 4. Leave attachments table (CREATE IF NOT EXISTS is safe for re-runs)
CREATE TABLE IF NOT EXISTS leave_attachments (
  attachment_id  INT AUTO_INCREMENT PRIMARY KEY,
  leave_id       INT NOT NULL,
  file_path      VARCHAR(500) NOT NULL,
  file_name      VARCHAR(255) NOT NULL,
  file_type      VARCHAR(100) NULL,
  file_size      INT NULL,
  uploaded_at    DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  INDEX idx_la_leave (leave_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- 5. Backfill workflow_status for existing records
--    Fully decided requests (APPROVED/REJECTED) → RECORDED
UPDATE leave_requests
SET workflow_status = 'RECORDED'
WHERE status IN ('APPROVED','REJECTED');

--    Pending requests where the principal has already acted on some dates → FORWARDED
UPDATE leave_requests lr
SET lr.workflow_status = 'FORWARDED'
WHERE lr.status = 'PENDING'
  AND EXISTS (
    SELECT 1 FROM leave_request_dates lrd
    WHERE lrd.leave_id = lr.leave_id
      AND lrd.status <> 'PENDING'
  );
