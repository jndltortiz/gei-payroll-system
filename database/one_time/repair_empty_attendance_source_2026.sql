-- ONE-TIME REPAIR: ONLY for the current live gei_payroll_system database
-- audited on 2026-09-28, or its verified disposable copy gei_test.
-- NOT a general migration. Expected: exactly 36 evidenced empty-source rows.
-- Select the target database externally. Apply 035 first, then this file,
-- then 036. Pause attendance writes and stop on any SQL error.
-- Preserve historical audit entries; append SOURCE_REPAIR records.
-- action_type must be VARCHAR(30); changed_by must permit NULL.
-- Do not rerun after success: count checks deliberately reject a second run.

DELIMITER //
CREATE PROCEDURE gei_repair_attendance_source_2026()
BEGIN
    DECLARE empty_count INT DEFAULT 0;
    DECLARE eligible_count INT DEFAULT 0;
    DECLARE repaired_count INT DEFAULT 0;
    DECLARE audited_count INT DEFAULT 0;
    DECLARE EXIT HANDLER FOR SQLEXCEPTION
    BEGIN
        ROLLBACK;
        DROP TEMPORARY TABLE IF EXISTS source_repair_2026;
        RESIGNAL;
    END;

    IF DATABASE() NOT IN ('gei_payroll_system', 'gei_test') THEN
        SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'Unexpected database; stop and re-audit.';
    END IF;
    IF NOT EXISTS (
        SELECT 1 FROM information_schema.COLUMNS
        WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='attendance_audit_log'
          AND COLUMN_NAME='action_type' AND DATA_TYPE='varchar'
          AND CHARACTER_MAXIMUM_LENGTH >= 13
    ) OR NOT EXISTS (
        SELECT 1 FROM information_schema.COLUMNS
        WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='attendance_audit_log'
          AND COLUMN_NAME='changed_by' AND IS_NULLABLE='YES'
    ) THEN
        SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'Audit schema incompatible with SOURCE_REPAIR / NULL actor.';
    END IF;

    START TRANSACTION;
    CREATE TEMPORARY TABLE source_repair_2026 (
        attendance_id INT NOT NULL PRIMARY KEY,
        evidence_audit_id INT UNSIGNED NOT NULL
    ) ENGINE=InnoDB;

    INSERT INTO source_repair_2026 (attendance_id, evidence_audit_id)
    SELECT r.attendance_id, a.audit_id
    FROM attendance_records r
    JOIN attendance_audit_log a ON a.audit_id = (
        SELECT MAX(b.audit_id) FROM attendance_audit_log b
        WHERE b.attendance_id=r.attendance_id
          AND b.action_type IN ('CREATE', 'EDIT')
    )
    WHERE r.attendance_source=''
      AND a.new_method='MANUAL_ADMIN'
      AND (r.attendance_id BETWEEN 3899 AND 3927
           OR r.attendance_id BETWEEN 3931 AND 3937);

    SELECT COUNT(*) INTO empty_count FROM attendance_records WHERE attendance_source='';
    SELECT COUNT(*) INTO eligible_count FROM source_repair_2026;
    IF empty_count <> 36 OR eligible_count <> 36 THEN
        SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'Expected exactly 36 audited empty rows; stop and re-audit.';
    END IF;

    INSERT INTO attendance_audit_log (
        attendance_id, changed_by, action_type,
        old_time_in, old_time_out, old_status, old_method, old_remarks,
        new_time_in, new_time_out, new_status, new_method, new_remarks, reason
    )
    SELECT r.attendance_id, NULL, 'SOURCE_REPAIR',
        r.time_in, r.time_out, r.attendance_status, r.attendance_source, r.remarks,
        r.time_in, r.time_out, r.attendance_status, 'MANUAL', r.remarks,
        CONCAT('One-time repair 2026: empty source restored to MANUAL; evidence audit #',
               c.evidence_audit_id, ' recorded MANUAL_ADMIN.')
    FROM attendance_records r JOIN source_repair_2026 c USING (attendance_id)
    WHERE r.attendance_source='';
    SET audited_count=ROW_COUNT();

    UPDATE attendance_records r JOIN source_repair_2026 c USING (attendance_id)
    SET r.attendance_source='MANUAL', r.updated_at=CURRENT_TIMESTAMP
    WHERE r.attendance_source='';
    SET repaired_count=ROW_COUNT();

    IF repaired_count <> 36 OR audited_count <> 36 THEN
        SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'Repair/audit count mismatch; transaction rolled back.';
    END IF;
    COMMIT;
    DROP TEMPORARY TABLE source_repair_2026;
    SELECT DATABASE() AS target_database, repaired_count AS repaired_rows,
           audited_count AS new_source_repair_audits;
END//
DELIMITER ;
CALL gei_repair_attendance_source_2026();
-- Run only after the CALL succeeds; do not continue after an SQL error.
DROP PROCEDURE gei_repair_attendance_source_2026;
