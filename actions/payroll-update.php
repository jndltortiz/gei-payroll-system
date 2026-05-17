<?php
require_once __DIR__ . '/../config/database.php';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {

    $payroll_id = $_POST['payroll_id'];

    // BASIC
    $basic = $_POST['basic'];
    $assign = $_POST['assign'];
    $rice = $_POST['rice'];
    $laundry = $_POST['laundry'];

    // DEDUCTIONS
    $peraa_premium = $_POST['peraa_premium'];
    $peraa_loan = $_POST['peraa_loan'];
    $hdmf_premium = $_POST['hdmf_premium'];
    $hdmf_loan = $_POST['hdmf_loan'];
    $philhealth = $_POST['philhealth'];
    $sss_premium = $_POST['sss_premium'];
    $sss_loan = $_POST['sss_loan'];

    // ======================
    // UPDATE ALLOWANCES
    // ======================
    $updateAllow = $pdo->prepare("
        UPDATE payroll_allowances pa
        JOIN allowance_types atype 
        ON pa.allowance_type_id = atype.allowance_type_id
        SET pa.amount = CASE
            WHEN atype.allowance_name = 'Additional Assignment Pay' THEN ?
            WHEN atype.allowance_name = 'Rice Subsidy' THEN ?
            WHEN atype.allowance_name = 'Laundry Allowance' THEN ?
            ELSE pa.amount
        END
        WHERE pa.payroll_id = ?
    ");
    $updateAllow->execute([$assign, $rice, $laundry, $payroll_id]);

    // ======================
    // UPDATE DEDUCTIONS
    // ======================
    $updateDed = $pdo->prepare("
        UPDATE payroll_deductions pd
        JOIN deduction_types dt 
        ON pd.deduction_type_id = dt.deduction_type_id
        SET pd.amount = CASE
            WHEN dt.deduction_name = 'PERAA Premium' THEN ?
            WHEN dt.deduction_name = 'PERAA Loan' THEN ?
            WHEN dt.deduction_name = 'HDMF Premium' THEN ?
            WHEN dt.deduction_name = 'HDMF Loan' THEN ?
            WHEN dt.deduction_name = 'PhilHealth' THEN ?
            WHEN dt.deduction_name = 'SSS Premium' THEN ?
            WHEN dt.deduction_name = 'SSS Loan' THEN ?
            ELSE pd.amount
        END
        WHERE pd.payroll_id = ?
    ");
    $updateDed->execute([
        $peraa_premium, $peraa_loan,
        $hdmf_premium, $hdmf_loan,
        $philhealth,
        $sss_premium, $sss_loan,
        $payroll_id
    ]);

    // ======================
    // RECOMPUTE
    // ======================
    $total_allowances = $assign + $rice + $laundry;
    $gross = $basic + $total_allowances;
    $total_ded = $peraa_premium + $peraa_loan + $hdmf_premium + $hdmf_loan + $philhealth + $sss_premium + $sss_loan;
    $net = $gross - $total_ded;

    // UPDATE MAIN
    $updateMain = $pdo->prepare("
        UPDATE payroll_records
        SET basic_pay=?, gross_pay=?, total_allowances=?, total_deductions=?, net_pay=?
        WHERE payroll_id=?
    ");
    $updateMain->execute([$basic, $gross, $total_allowances, $total_ded, $net, $payroll_id]);


    // REMOVE the rowCount check entirely
    // Just always redirect
    header("Location: /gei-payroll-system/modules/payroll/index.php?updated=1");
    exit;
}