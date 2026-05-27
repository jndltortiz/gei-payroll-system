<?php
// actions/save-payroll-settings.php

require_once __DIR__ . '/../config/config.php';
require_once __DIR__ . '/../includes/auth.php';

header('Content-Type: application/json');
requireAdminAction();

$body = json_decode(file_get_contents('php://input'), true);
if (!$body) {
    echo json_encode(['success' => false, 'message' => 'Invalid request body.']);
    exit;
}

// Sanitize / cast
$payrollFrequency    = in_array($body['payroll_frequency'] ?? '', ['SEMI_MONTHLY','MONTHLY'])
                        ? $body['payroll_frequency'] : 'SEMI_MONTHLY';
$workingDays         = max(1, min(7, (int)($body['working_days_per_week'] ?? 5)));
$cutoff1Start        = max(1, min(31, (int)($body['cutoff1_start_day'] ?? 1)));
$cutoff1End          = max(1, min(31, (int)($body['cutoff1_end_day']   ?? 15)));
$cutoff2Start        = max(1, min(31, (int)($body['cutoff2_start_day'] ?? 16)));
$cutoff2End          = max(1, min(31, (int)($body['cutoff2_end_day']   ?? 31)));
$autoAllowances      = isset($body['auto_apply_allowances']) ? (int)(bool)$body['auto_apply_allowances'] : 1;
$autoDeductions      = isset($body['auto_apply_deductions']) ? (int)(bool)$body['auto_apply_deductions'] : 1;
$allowOverride       = isset($body['allow_manual_override'])  ? (int)(bool)$body['allow_manual_override']  : 1;
$govCalcMode         = in_array($body['government_calc_mode'] ?? '', ['STANDARD','MANUAL'])
                        ? $body['government_calc_mode'] : 'STANDARD';
$useGovTables        = $govCalcMode === 'STANDARD' ? 1 : 0;

try {
    // Check if settings row exists
    $exists = $pdo->query("SELECT setting_id FROM payroll_settings LIMIT 1")->fetchColumn();

    if ($exists) {
        $stmt = $pdo->prepare("
            UPDATE payroll_settings SET
                payroll_frequency       = :payroll_frequency,
                working_days_per_week   = :working_days_per_week,
                cutoff1_start_day       = :cutoff1_start_day,
                cutoff1_end_day         = :cutoff1_end_day,
                cutoff2_start_day       = :cutoff2_start_day,
                cutoff2_end_day         = :cutoff2_end_day,
                auto_apply_allowances   = :auto_apply_allowances,
                auto_apply_deductions   = :auto_apply_deductions,
                allow_manual_override   = :allow_manual_override,
                use_government_tables   = :use_government_tables,
                government_calc_mode    = :government_calc_mode,
                updated_at              = NOW()
            WHERE setting_id = :setting_id
        ");
        $stmt->execute([
            ':payroll_frequency'     => $payrollFrequency,
            ':working_days_per_week' => $workingDays,
            ':cutoff1_start_day'     => $cutoff1Start,
            ':cutoff1_end_day'       => $cutoff1End,
            ':cutoff2_start_day'     => $cutoff2Start,
            ':cutoff2_end_day'       => $cutoff2End,
            ':auto_apply_allowances' => $autoAllowances,
            ':auto_apply_deductions' => $autoDeductions,
            ':allow_manual_override' => $allowOverride,
            ':use_government_tables' => $useGovTables,
            ':government_calc_mode'  => $govCalcMode,
            ':setting_id'            => $exists,
        ]);
    } else {
        $stmt = $pdo->prepare("
            INSERT INTO payroll_settings
                (payroll_frequency, working_days_per_week,
                 cutoff1_start_day, cutoff1_end_day,
                 cutoff2_start_day, cutoff2_end_day,
                 auto_apply_allowances, auto_apply_deductions,
                 allow_manual_override, use_government_tables, government_calc_mode)
            VALUES
                (:payroll_frequency, :working_days_per_week,
                 :cutoff1_start_day, :cutoff1_end_day,
                 :cutoff2_start_day, :cutoff2_end_day,
                 :auto_apply_allowances, :auto_apply_deductions,
                 :allow_manual_override, :use_government_tables, :government_calc_mode)
        ");
        $stmt->execute([
            ':payroll_frequency'     => $payrollFrequency,
            ':working_days_per_week' => $workingDays,
            ':cutoff1_start_day'     => $cutoff1Start,
            ':cutoff1_end_day'       => $cutoff1End,
            ':cutoff2_start_day'     => $cutoff2Start,
            ':cutoff2_end_day'       => $cutoff2End,
            ':auto_apply_allowances' => $autoAllowances,
            ':auto_apply_deductions' => $autoDeductions,
            ':allow_manual_override' => $allowOverride,
            ':use_government_tables' => $useGovTables,
            ':government_calc_mode'  => $govCalcMode,
        ]);
    }

    // Save loan auto-deduct toggles (amounts live in employee_loans, not here)
    if (!empty($body['loans'])) {
        $loans = json_decode($body['loans'], true);
        if (is_array($loans)) {
            $loanStmt = $pdo->prepare("
                UPDATE loan_types SET is_active = :is_active WHERE loan_type_id = :id
            ");
            foreach ($loans as $loan) {
                $loanStmt->execute([
                    ':is_active' => (int)(bool)$loan['is_active'],
                    ':id'        => (int)$loan['id'],
                ]);
            }
        }
    }

    // Save manual government contribution values into deduction_types. Payroll
    // generation reads these when government_calc_mode = MANUAL.
    if ($govCalcMode === 'MANUAL') {
        $manualGov = [
            ['key' => 'sss',  'patterns' => ['%SSS%']],
            ['key' => 'phil', 'patterns' => ['%Phil%']],
            ['key' => 'pag',  'patterns' => ['%Pag-IBIG%', '%Pagibig%', '%HDMF%']],
        ];

        foreach ($manualGov as $gov) {
            $inputType = ($body[$gov['key'] . '_type'] ?? '') === 'pct' ? 'PERCENTAGE' : 'FIXED';
            $inputVal  = max(0, (float)($body[$gov['key'] . '_rate'] ?? 0));
            $amount    = $inputType === 'FIXED' ? $inputVal : 0;
            $rate      = $inputType === 'PERCENTAGE' ? $inputVal : 0;

            $nameWhere = implode(' OR ', array_fill(0, count($gov['patterns']), 'deduction_name LIKE ?'));
            $params = array_merge([$inputType, $amount, $rate], $gov['patterns']);
            $pdo->prepare("
                UPDATE deduction_types
                SET deduction_value_type = ?,
                    deduction_amount = ?,
                    deduction_rate = ?
                WHERE is_government = 1
                  AND is_loan = 0
                  AND ($nameWhere)
            ")->execute($params);
        }
    }

    // Migration 010: save weekend_pay_date_rule + attendance_source if columns exist
    $hasMig010 = false;
    try {
        $hasMig010 = (bool)$pdo->query("
            SELECT COUNT(*) FROM information_schema.COLUMNS
            WHERE TABLE_SCHEMA = DATABASE()
              AND TABLE_NAME   = 'payroll_settings'
              AND COLUMN_NAME  = 'weekend_pay_date_rule'
        ")->fetchColumn();
    } catch (PDOException $_e) {}

    if ($hasMig010) {
        $weekendRule   = in_array($body['weekend_pay_date_rule'] ?? '', ['EXACT','ADVANCE'])
                         ? $body['weekend_pay_date_rule'] : 'ADVANCE';
        $attendanceSrc = in_array($body['attendance_source'] ?? '', ['MANUAL','REFERENCE','AUTO'])
                         ? $body['attendance_source'] : 'REFERENCE';
        $pdo->prepare("
            UPDATE payroll_settings
            SET weekend_pay_date_rule = ?, attendance_source = ?
            WHERE setting_id = ?
        ")->execute([$weekendRule, $attendanceSrc, $exists ?: $pdo->lastInsertId()]);
    }

    // Migration 020: save leave_allocation_days if column exists
    $hasMig020 = false;
    try {
        $hasMig020 = (bool)$pdo->query("
            SELECT COUNT(*) FROM information_schema.COLUMNS
            WHERE TABLE_SCHEMA = DATABASE()
              AND TABLE_NAME   = 'payroll_settings'
              AND COLUMN_NAME  = 'leave_allocation_days'
        ")->fetchColumn();
    } catch (PDOException $_e) {}

    if ($hasMig020) {
        $leaveAllocationDays = max(0.0, (float)($body['leave_allocation_days'] ?? 30.00));
        $pdo->prepare("
            UPDATE payroll_settings
            SET leave_allocation_days = ?
            WHERE setting_id = ?
        ")->execute([$leaveAllocationDays, $exists ?: $pdo->lastInsertId()]);
    }

    // Audit log
    $userId = $_SESSION['user']['user_id'] ?? null;
    if ($userId) {
        $audit = $pdo->prepare("
            INSERT INTO audit_logs (user_id, action, table_name, record_id, description)
            VALUES (:uid, 'UPDATE', 'payroll_settings', :rid, 'Payroll settings updated')
        ");
        $audit->execute([':uid' => $userId, ':rid' => $exists ?: $pdo->lastInsertId()]);
    }

    echo json_encode(['success' => true, 'message' => 'Payroll settings saved successfully.']);

} catch (PDOException $e) {
    echo json_encode(['success' => false, 'message' => 'Database error: ' . $e->getMessage()]);
}
