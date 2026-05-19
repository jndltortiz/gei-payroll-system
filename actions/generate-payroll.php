<?php
/**
 * actions/generate-payroll.php
 * Generates payroll records for all active employees for a given pay period.
 * Returns JSON — called via fetch() from the generate modal.
 */
require_once __DIR__ . '/../config/config.php';
require_once __DIR__ . '/../includes/auth.php';

header('Content-Type: application/json');
requireLogin();

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    echo json_encode(['success' => false, 'message' => 'Invalid request method.']);
    exit;
}

$periodId = (int)($_POST['period_id'] ?? 0);
if (!$periodId) {
    echo json_encode(['success' => false, 'message' => 'No pay period selected.']);
    exit;
}

// Verify period exists and is OPEN
$period = $pdo->prepare("SELECT * FROM payroll_periods WHERE period_id = ? AND status = 'OPEN'");
$period->execute([$periodId]);
$period = $period->fetch();
if (!$period) {
    echo json_encode(['success' => false, 'message' => 'Pay period not found or not open.']);
    exit;
}

try {
    $pdo->beginTransaction();

    // Get all active employees with salary
    $employees = $pdo->query("
        SELECT e.employee_id, ec.monthly_salary, ec.daily_rate
        FROM employees e
        JOIN employee_compensations ec ON e.employee_id = ec.employee_id
        WHERE e.employee_status = 'ACTIVE' AND ec.is_active = 1
    ")->fetchAll();

    $generated = 0;
    $skipped   = 0;

    foreach ($employees as $emp) {
        $employeeId = $emp['employee_id'];
        $basic      = (float)($emp['monthly_salary'] ?? 0);

        // Skip if payroll already exists for this period
        $check = $pdo->prepare("SELECT payroll_id FROM payroll_records WHERE employee_id = ? AND period_id = ?");
        $check->execute([$employeeId, $periodId]);
        if ($check->fetch()) {
            $skipped++;
            continue;
        }

        // Insert base payroll record
        $pdo->prepare("
            INSERT INTO payroll_records
                (period_id, employee_id, basic_pay, gross_pay, total_allowances, total_deductions, net_pay, payroll_status)
            VALUES (?, ?, ?, 0, 0, 0, 0, 'DRAFT')
        ")->execute([$periodId, $employeeId, $basic]);
        $payrollId = (int)$pdo->lastInsertId();

        // Allowances — only those assigned to this employee
        $atypes = $pdo->prepare("
            SELECT at.allowance_type_id, at.default_amount
            FROM allowance_types at
            JOIN allowance_assignments aa ON at.allowance_type_id = aa.allowance_type_id
            WHERE at.is_active = 1 AND aa.is_active = 1
              AND (aa.applies_to = 'ALL'
                   OR (aa.applies_to = 'EMPLOYEE' AND aa.target_id = :eid))
        ");
        $atypes->execute([':eid' => $employeeId]);
        $totalAllowances = 0;
        foreach ($atypes->fetchAll() as $type) {
            $amt = (float)$type['default_amount'];
            $totalAllowances += $amt;
            $pdo->prepare("INSERT INTO payroll_allowances (payroll_id, allowance_type_id, amount) VALUES (?,?,?)")
                ->execute([$payrollId, $type['allowance_type_id'], $amt]);
        }

        // Deductions — government contributions using official logic
        $dtypes = $pdo->query("SELECT * FROM deduction_types WHERE is_active = 1")->fetchAll();
        $totalDeductions = 0;
        foreach ($dtypes as $type) {
            $amt = 0;
            $name = $type['deduction_name'];

            if ($name === 'SSS Premium') {
                // Look up bracket from sss_contribution_table
                $sss = $pdo->prepare("
                    SELECT employee_share FROM sss_contribution_table
                    WHERE min_salary <= :sal AND max_salary >= :sal AND is_active = 1
                    ORDER BY effective_date DESC LIMIT 1
                ");
                $sss->execute([':sal' => $basic]);
                $row = $sss->fetch();
                $amt = $row ? (float)$row['employee_share'] : round($basic * 0.045, 2);
            } elseif ($name === 'PhilHealth') {
                $ph = $pdo->prepare("
                    SELECT employee_share FROM philhealth_contribution_table
                    WHERE min_salary <= :sal AND max_salary >= :sal AND is_active = 1
                    ORDER BY effective_date DESC LIMIT 1
                ");
                $ph->execute([':sal' => $basic]);
                $row = $ph->fetch();
                $amt = $row ? (float)$row['employee_share'] : round($basic * 0.025, 2);
            } elseif ($name === 'Pag-IBIG' || $name === 'HDMF Premium') {
                $pi = $pdo->prepare("
                    SELECT employee_share FROM pagibig_contribution_table
                    WHERE min_salary <= :sal AND max_salary >= :sal AND is_active = 1
                    ORDER BY effective_date DESC LIMIT 1
                ");
                $pi->execute([':sal' => $basic]);
                $row = $pi->fetch();
                $amt = $row ? (float)$row['employee_share'] : 200;
            } elseif ($type['deduction_value_type'] === 'FIXED') {
                $amt = (float)$type['deduction_amount'];
            } elseif ($type['deduction_value_type'] === 'PERCENTAGE') {
                $amt = round($basic * ($type['deduction_rate'] / 100), 2);
            }
            // Loans default to 0

            $totalDeductions += $amt;
            $pdo->prepare("INSERT INTO payroll_deductions (payroll_id, deduction_type_id, amount) VALUES (?,?,?)")
                ->execute([$payrollId, $type['deduction_type_id'], $amt]);
        }

        // Final totals
        $gross = $basic + $totalAllowances;
        $net   = $gross - $totalDeductions;
        $pdo->prepare("
            UPDATE payroll_records
            SET gross_pay=?, total_allowances=?, total_deductions=?, net_pay=?
            WHERE payroll_id=?
        ")->execute([$gross, $totalAllowances, $totalDeductions, $net, $payrollId]);

        $generated++;
    }

    $pdo->commit();

    $msg = "Payroll generated for {$generated} employee(s).";
    if ($skipped) $msg .= " {$skipped} already existed and were skipped.";

    echo json_encode(['success' => true, 'message' => $msg, 'generated' => $generated]);

} catch (PDOException $e) {
    $pdo->rollBack();
    echo json_encode(['success' => false, 'message' => 'Database error: ' . $e->getMessage()]);
}