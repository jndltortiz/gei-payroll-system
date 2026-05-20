<?php
/**
 * actions/payroll-update.php
 * Updates allowances, deductions, and recomputed totals for one payroll record.
 * Only DRAFT records whose period is still OPEN may be edited.
 *
 * POST params: payroll_id, employee_id, basic, assign, rice, laundry,
 *              peraa_premium, peraa_loan, hdmf_premium, hdmf_loan,
 *              philhealth, sss_premium, sss_loan
 *
 * Redirects back to admin payroll index on success.
 */

// FIX #6a — was '../config/database.php' which does not exist; use config.php
require_once __DIR__ . '/../config/config.php';
require_once __DIR__ . '/../includes/auth.php';
requireAdminAction(); // Admin only — principals cannot edit payroll

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    header('Location: ' . BASE_URL . 'modules/payroll/index.php');
    exit;
}

$payrollId = (int)($_POST['payroll_id'] ?? 0);

if (!$payrollId) {
    header('Location: ' . BASE_URL . 'modules/payroll/index.php?error=no_id');
    exit;
}

// FIX #6b — guard: reject edits on non-DRAFT records or non-OPEN periods
$guard = $pdo->prepare("
    SELECT pr.payroll_status, pp.status AS period_status
    FROM payroll_records pr
    JOIN payroll_periods pp ON pr.period_id = pp.period_id
    WHERE pr.payroll_id = ?
");
$guard->execute([$payrollId]);
$guardRow = $guard->fetch();

if (!$guardRow) {
    header('Location: ' . BASE_URL . 'modules/payroll/index.php?error=not_found');
    exit;
}
if ($guardRow['payroll_status'] !== 'DRAFT' || $guardRow['period_status'] !== 'OPEN') {
    // Silently redirect — cannot edit a submitted / approved / released record
    header('Location: ' . BASE_URL . 'modules/payroll/index.php?error=locked');
    exit;
}

// ── Collect inputs ────────────────────────────────────────────────────────────
$basic         = (float)($_POST['basic']         ?? 0);
$assign        = (float)($_POST['assign']        ?? 0);
$rice          = (float)($_POST['rice']          ?? 0);
$laundry       = (float)($_POST['laundry']       ?? 0);

$peraa_premium = (float)($_POST['peraa_premium'] ?? 0);
$peraa_loan    = (float)($_POST['peraa_loan']    ?? 0);
$hdmf_premium  = (float)($_POST['hdmf_premium']  ?? 0);
$hdmf_loan     = (float)($_POST['hdmf_loan']     ?? 0);
$philhealth    = (float)($_POST['philhealth']    ?? 0);
$sss_premium   = (float)($_POST['sss_premium']   ?? 0);
$sss_loan      = (float)($_POST['sss_loan']      ?? 0);

// ── Update allowances ─────────────────────────────────────────────────────────
$pdo->prepare("
    UPDATE payroll_allowances pa
    JOIN allowance_types atype ON pa.allowance_type_id = atype.allowance_type_id
    SET pa.amount = CASE
        WHEN atype.allowance_name = 'Additional Assignment Pay' THEN ?
        WHEN atype.allowance_name = 'Rice Subsidy'              THEN ?
        WHEN atype.allowance_name = 'Laundry Allowance'         THEN ?
        ELSE pa.amount
    END
    WHERE pa.payroll_id = ?
")->execute([$assign, $rice, $laundry, $payrollId]);

// ── Update deductions ─────────────────────────────────────────────────────────
$pdo->prepare("
    UPDATE payroll_deductions pd
    JOIN deduction_types dt ON pd.deduction_type_id = dt.deduction_type_id
    SET pd.amount = CASE
        WHEN dt.deduction_name = 'PERAA Premium' THEN ?
        WHEN dt.deduction_name = 'PERAA Loan'    THEN ?
        WHEN dt.deduction_name = 'HDMF Premium'  THEN ?
        WHEN dt.deduction_name = 'HDMF Loan'     THEN ?
        WHEN dt.deduction_name = 'PhilHealth'    THEN ?
        WHEN dt.deduction_name = 'SSS Premium'   THEN ?
        WHEN dt.deduction_name = 'SSS Loan'      THEN ?
        ELSE pd.amount
    END
    WHERE pd.payroll_id = ?
")->execute([
    $peraa_premium, $peraa_loan,
    $hdmf_premium,  $hdmf_loan,
    $philhealth,
    $sss_premium,   $sss_loan,
    $payrollId
]);

// ── Recompute totals ──────────────────────────────────────────────────────────
$total_allowances = $assign + $rice + $laundry;
$gross            = $basic + $total_allowances;
$total_ded        = $peraa_premium + $peraa_loan
                  + $hdmf_premium  + $hdmf_loan
                  + $philhealth
                  + $sss_premium   + $sss_loan;
$net              = $gross - $total_ded;

$pdo->prepare("
    UPDATE payroll_records
    SET basic_pay         = ?,
        gross_pay         = ?,
        total_allowances  = ?,
        total_deductions  = ?,
        net_pay           = ?,
        updated_at        = NOW()
    WHERE payroll_id = ?
")->execute([$basic, $gross, $total_allowances, $total_ded, $net, $payrollId]);

// ── Audit ─────────────────────────────────────────────────────────────────────
$uid = $_SESSION['user']['user_id'] ?? null;
if ($uid) {
    $pdo->prepare("
        INSERT INTO audit_logs (user_id, action, table_name, record_id, description)
        VALUES (?, 'UPDATE', 'payroll_records', ?, ?)
    ")->execute([
        $uid,
        $payrollId,
        "Manually updated payroll record #{$payrollId} — net pay: ₱" . number_format($net, 2)
    ]);
}

header('Location: ' . BASE_URL . 'modules/payroll/index.php?updated=1');
exit;