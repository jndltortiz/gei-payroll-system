<?php
/**
 * actions/payroll-update.php
 * Updates allowances, deductions, and recomputed totals for one payroll record.
 * Only DRAFT records whose period is still OPEN may be edited.
 *
 * POST params:
 *   payroll_id    — record to update
 *   basic         — updated basic pay
 *   pa[{type_id}] — allowance amounts keyed by allowance_type_id
 *   pd[{type_id}] — deduction amounts keyed by deduction_type_id
 *
 * Redirects back to admin payroll index on completion.
 */

require_once __DIR__ . '/../config/config.php';
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/payroll-utils.php';

requireAdminAction();

function payrollUpdateWantsJson(): bool
{
    $requestedWith = strtolower((string)($_SERVER['HTTP_X_REQUESTED_WITH'] ?? ''));
    $accept = strtolower((string)($_SERVER['HTTP_ACCEPT'] ?? ''));

    return $requestedWith === 'xmlhttprequest' || strpos($accept, 'application/json') !== false;
}

function payrollUpdateFail(string $message, string $code = 'error', int $status = 422): void
{
    if (payrollUpdateWantsJson()) {
        http_response_code($status);
        header('Content-Type: application/json');
        echo json_encode([
            'success' => false,
            'message' => $message,
            'code' => $code,
        ]);
        exit;
    }

    $_SESSION['payroll_error'] = $message;
    header('Location: ' . BASE_URL . 'modules/payroll/index.php?error=' . urlencode($code));
    exit;
}

function payrollUpdateSuccess(string $message): void
{
    if (payrollUpdateWantsJson()) {
        header('Content-Type: application/json');
        echo json_encode([
            'success' => true,
            'message' => $message,
        ]);
        exit;
    }

    header('Location: ' . BASE_URL . 'modules/payroll/index.php?updated=1');
    exit;
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    payrollUpdateFail('Invalid request method.', 'invalid_method', 405);
}

$payrollId = (int)($_POST['payroll_id'] ?? 0);
$adjustmentReason = trim($_POST['adjustment_reason'] ?? '');

if (!$payrollId) {
    payrollUpdateFail('Payroll record ID is required.', 'no_id');
}
$adjustmentReasonError = validateRequiredReason($adjustmentReason, 'Adjustment reason');
if ($adjustmentReasonError !== null) {
    payrollUpdateFail($adjustmentReasonError, 'adjustment_reason_required');
}

// Guard: only DRAFT records in OPEN periods may be edited
$guard = $pdo->prepare("
    SELECT pr.payroll_status, pp.status AS period_status, pp.period_type
    FROM payroll_records pr
    JOIN payroll_periods pp ON pr.period_id = pp.period_id
    WHERE pr.payroll_id = ?
");
$guard->execute([$payrollId]);
$guardRow = $guard->fetch();

if (!$guardRow) {
    payrollUpdateFail('Payroll record not found.', 'not_found', 404);
}
if ($guardRow['payroll_status'] !== 'DRAFT' || $guardRow['period_status'] !== 'OPEN') {
    payrollUpdateFail('Only draft payroll records in open periods can be edited.', 'locked');
}

$periodType = $guardRow['period_type'] ?? 'REGULAR';
$isRegularPeriod = $periodType === 'REGULAR';
$isThirteenthMonthPeriod = $periodType === 'THIRTEENTH_MONTH';
function payrollPolicyLockedDeductionName(string $name): bool
{
    return (bool)preg_match('/attendance|tardy|tardiness|late|undertime|absence|absent|half[\s-]?day|eosy balance recovery/i', $name);
}

if ($isThirteenthMonthPeriod) {
    payrollUpdateFail(
        '13th month pay is policy-locked. Regenerate the 13th month period from released regular basic pay instead of editing it manually.',
        'policy_locked_thirteenth_month'
    );
}

if ($isRegularPeriod) {
    $postedDeductionIds = array_filter(array_map('intval', array_keys($_POST['pd'] ?? [])));
    if (!empty($postedDeductionIds)) {
        $ph = implode(',', array_fill(0, count($postedDeductionIds), '?'));
        $lockStmt = $pdo->prepare("
            SELECT deduction_name
            FROM deduction_types
            WHERE deduction_type_id IN ($ph)
              AND (
                    COALESCE(is_absence_deduction, 0) = 1
                 OR COALESCE(is_accrued_pay_deduction, 0) = 1
                 OR deduction_name REGEXP 'Attendance|Tardy|Tardiness|Late|Undertime|Absence|Absent|Half[ -]?Day|EOSY Balance Recovery'
              )
            LIMIT 1
        ");
        $lockStmt->execute($postedDeductionIds);
        if ($lockStmt->fetchColumn()) {
            payrollUpdateFail('Attendance, tardiness, undertime, absence, half-day, and EOSY recovery deductions are policy-locked for regular payroll.', 'policy_locked_deduction');
        }
    }

    foreach (($_POST['new_pd'] ?? []) as $item) {
        if (payrollPolicyLockedDeductionName(trim($item['label'] ?? ''))) {
            payrollUpdateFail('Attendance, tardiness, undertime, absence, half-day, and EOSY recovery deductions are policy-locked for regular payroll.', 'policy_locked_deduction');
        }
    }
}

try {
    $pdo->beginTransaction();

// ── Basic pay ─────────────────────────────────────────────────────────────────
$basic = max(0.0, (float)($_POST['basic'] ?? 0));
$pdo->prepare("UPDATE payroll_records SET basic_pay = ? WHERE payroll_id = ?")
    ->execute([$basic, $payrollId]);

// ── Allowances — update by allowance_type_id (not by name) ───────────────────
$allowances = $_POST['pa'] ?? [];
if (!empty($allowances) && is_array($allowances)) {
    $updAllowance = $pdo->prepare("
        UPDATE payroll_allowances
        SET    amount = ?
        WHERE  payroll_id = ? AND allowance_type_id = ?
    ");
    foreach ($allowances as $typeId => $amount) {
        $typeId = (int)$typeId;
        $amount = max(0.0, (float)$amount);
        if ($typeId > 0) {
            $updAllowance->execute([$amount, $payrollId, $typeId]);
        }
    }
}

// ── Deductions — update by deduction_type_id (not by name) ───────────────────
$deductions = $_POST['pd'] ?? [];
if (!empty($deductions) && is_array($deductions)) {
    $updDeduction = $pdo->prepare("
        UPDATE payroll_deductions
        SET    amount = ?
        WHERE  payroll_id = ? AND deduction_type_id = ?
    ");
    foreach ($deductions as $typeId => $amount) {
        $typeId = (int)$typeId;
        $amount = max(0.0, (float)$amount);
        if ($typeId > 0) {
            $updDeduction->execute([$amount, $payrollId, $typeId]);
        }
    }
}

// ── New manual allowance adjustments ─────────────────────────────────────────
// Sent as new_pa[n][label] + new_pa[n][amount]. Uses the reserved 'Adjustment'
// allowance type (is_active=0) so these never appear in auto-generation.
$newAllowances = $_POST['new_pa'] ?? [];
if (!empty($newAllowances) && is_array($newAllowances)) {
    $adjTypeRow = $pdo->query("
        SELECT allowance_type_id FROM allowance_types
        WHERE allowance_name = 'Adjustment' AND is_active = 0 LIMIT 1
    ")->fetch();
    if ($adjTypeRow) {
        $adjTypeId = (int)$adjTypeRow['allowance_type_id'];
        $insAdj = $pdo->prepare("
            INSERT INTO payroll_allowances
                (payroll_id, allowance_type_id, amount, is_adjustment, adjustment_label)
            VALUES (?, ?, ?, 1, ?)
        ");
        foreach ($newAllowances as $item) {
            $label  = trim($item['label'] ?? '');
            $amount = max(0.0, (float)($item['amount'] ?? 0));
            if ($label !== '' && $amount > 0) {
                $insAdj->execute([$payrollId, $adjTypeId, $amount, $label]);
            }
        }
    }
}

// ── New manual deduction adjustments ─────────────────────────────────────────
$newDeductions = $_POST['new_pd'] ?? [];
if (!empty($newDeductions) && is_array($newDeductions)) {
    $adjTypeRow = $pdo->query("
        SELECT deduction_type_id FROM deduction_types
        WHERE deduction_name = 'Adjustment' AND is_active = 0 LIMIT 1
    ")->fetch();
    if ($adjTypeRow) {
        $adjTypeId = (int)$adjTypeRow['deduction_type_id'];
        $insAdj = $pdo->prepare("
            INSERT INTO payroll_deductions
                (payroll_id, deduction_type_id, amount, is_adjustment, adjustment_label)
            VALUES (?, ?, ?, 1, ?)
        ");
        foreach ($newDeductions as $item) {
            $label  = trim($item['label'] ?? '');
            $amount = max(0.0, (float)($item['amount'] ?? 0));
            if ($label !== '' && $amount > 0) {
                $insAdj->execute([$payrollId, $adjTypeId, $amount, $label]);
            }
        }
    }
}

// ── Recompute totals from DB rows (uses shared helper from payroll-utils.php) ─
recalculatePayrollTotals($pdo, $payrollId);

// ── Audit ─────────────────────────────────────────────────────────────────────
$netStmt = $pdo->prepare("SELECT net_pay FROM payroll_records WHERE payroll_id = ?");
$netStmt->execute([$payrollId]);
$netPay = (float)($netStmt->fetchColumn() ?? 0);

$uid = $_SESSION['user']['user_id'] ?? null;
if ($uid) {
    $pdo->prepare("
        INSERT INTO audit_logs (user_id, action, table_name, record_id, description)
        VALUES (?, 'UPDATE', 'payroll_records', ?, ?)
    ")->execute([
        $uid,
        $payrollId,
        "Manually updated payroll record #{$payrollId} - net pay: PHP " . number_format($netPay, 2)
            . ". Reason: {$adjustmentReason}",
    ]);
}

$pdo->commit();

payrollUpdateSuccess('Payroll record updated successfully.');
} catch (Throwable $e) {
    if ($pdo->inTransaction()) {
        $pdo->rollBack();
    }
    error_log('[payroll] Manual update failed: ' . $e->getMessage());
    payrollUpdateFail('Manual payroll update failed. No changes were saved.', 'manual_update_failed', 500);
}
