<?php
require_once __DIR__ . '/../config/database.php';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {

    $period_id = $_POST['period_id'];

    // 🟢 GET EMPLOYEES WITH SALARY
    $employees = $pdo->query("
        SELECT 
            e.employee_id,
            ec.monthly_salary
        FROM employees e
        LEFT JOIN employee_compensations ec
            ON e.employee_id = ec.employee_id
        WHERE ec.is_active = 1
    ");

    foreach ($employees as $emp) {

        $employee_id = $emp['employee_id'];
        $basic = $emp['monthly_salary'] ?? 0;

        // 🔒 PREVENT DUPLICATE PAYROLL
        $check = $pdo->prepare("
            SELECT payroll_id 
            FROM payroll_records 
            WHERE employee_id = ? AND period_id = ?
        ");
        $check->execute([$employee_id, $period_id]);

        if ($check->rowCount() > 0) {
            continue;
        }

        // 🟢 INSERT PAYROLL RECORD
        $stmt = $pdo->prepare("
            INSERT INTO payroll_records
            (period_id, employee_id, basic_pay, gross_pay, total_deductions, net_pay, payroll_status)
            VALUES (?, ?, ?, 0, 0, 0, 'DRAFT')
        ");
        $stmt->execute([$period_id, $employee_id, $basic]);

        $payroll_id = $pdo->lastInsertId();

        // =========================
        // 🔵 ALLOWANCES (FROM DB)
        // =========================
        $atypes = $pdo->query("
            SELECT * FROM allowance_types WHERE is_active = 1
        ");

        $total_allowances = 0;

        foreach ($atypes as $type) {

            $amount = $type['default_amount'];
            $total_allowances += $amount;

            $insertA = $pdo->prepare("
                INSERT INTO payroll_allowances
                (payroll_id, allowance_type_id, amount)
                VALUES (?, ?, ?)
            ");

            $insertA->execute([
                $payroll_id,
                $type['allowance_type_id'],
                $amount
            ]);
        }

        // =========================
        // 🔴 AUTO DEDUCTIONS
        // =========================
        $types = $pdo->query("
            SELECT * FROM deduction_types WHERE is_active = 1
        ");

        $total_deductions = 0;

        foreach ($types as $type) {

            $amount = 0;

            // 💡 AUTO COMPUTE BASED ON SALARY
            if ($type['deduction_name'] == 'SSS Premium') {
                $amount = $basic * 0.045; // 4.5%
            }

            if ($type['deduction_name'] == 'PhilHealth') {
                $amount = $basic * 0.03; // 3%
            }

            if ($type['deduction_name'] == 'HDMF Premium') {
                $amount = 200; // fixed
            }

            if ($type['deduction_name'] == 'PERAA Premium') {
                $amount = 500; // fixed
            }

            // loans default 0
            if ($type['deduction_name'] == 'SSS Loan') $amount = 0;
            if ($type['deduction_name'] == 'HDMF Loan') $amount = 0;
            if ($type['deduction_name'] == 'PERAA Loan') $amount = 0;

            $total_deductions += $amount;

            $insertD = $pdo->prepare("
                INSERT INTO payroll_deductions
                (payroll_id, deduction_type_id, amount)
                VALUES (?, ?, ?)
            ");

            $insertD->execute([
                $payroll_id,
                $type['deduction_type_id'],
                $amount
            ]);
        }

        // =========================
        // 🔥 FINAL COMPUTATION
        // =========================
        $gross = $basic + $total_allowances;
        $net = $gross - $total_deductions;

        $update = $pdo->prepare("
            UPDATE payroll_records
            SET gross_pay = ?, total_deductions = ?, net_pay = ?
            WHERE payroll_id = ?
        ");

        $update->execute([
            $gross,
            $total_deductions,
            $net,
            $payroll_id
        ]);
    }

    header("Location: ../modules/payroll/index.php");
    exit;
}
?>