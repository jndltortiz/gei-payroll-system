-- migrations/010_payroll_settings_ext.sql
-- Adds weekend_pay_date_rule and attendance_source columns to payroll_settings.
-- Safe to re-run (checks column existence before ALTER).

-- weekend_pay_date_rule: controls whether generated pay dates are advanced to
-- the previous Friday when they fall on a Saturday or Sunday.
SET @hasPPRule = (
    SELECT COUNT(*) FROM information_schema.COLUMNS
    WHERE TABLE_SCHEMA = DATABASE()
      AND TABLE_NAME   = 'payroll_settings'
      AND COLUMN_NAME  = 'weekend_pay_date_rule'
);
SET @sqlPPRule = IF(@hasPPRule = 0,
    'ALTER TABLE payroll_settings ADD COLUMN weekend_pay_date_rule ENUM(''EXACT'',''ADVANCE'') NOT NULL DEFAULT ''ADVANCE'' AFTER cutoff2_end_day',
    'SELECT ''weekend_pay_date_rule already exists'' AS notice'
);
PREPARE _s FROM @sqlPPRule; EXECUTE _s; DEALLOCATE PREPARE _s;

-- attendance_source: documents how attendance data is used during payroll editing.
-- Informational policy field; does not drive automatic computation.
SET @hasAttSrc = (
    SELECT COUNT(*) FROM information_schema.COLUMNS
    WHERE TABLE_SCHEMA = DATABASE()
      AND TABLE_NAME   = 'payroll_settings'
      AND COLUMN_NAME  = 'attendance_source'
);
SET @sqlAttSrc = IF(@hasAttSrc = 0,
    'ALTER TABLE payroll_settings ADD COLUMN attendance_source ENUM(''MANUAL'',''REFERENCE'',''AUTO'') NOT NULL DEFAULT ''REFERENCE'' AFTER weekend_pay_date_rule',
    'SELECT ''attendance_source already exists'' AS notice'
);
PREPARE _s FROM @sqlAttSrc; EXECUTE _s; DEALLOCATE PREPARE _s;
