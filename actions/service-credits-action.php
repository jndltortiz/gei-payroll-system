<?php
/**
 * actions/service-credits-action.php
 * Service Credit CRUD + approval workflow.
 *
 * Actions:
 *   save_draft  — save as DRAFT
 *   submit      — save as PENDING (submit for Principal approval)
 *   resubmit    — DRAFT or REJECTED → PENDING
 *   edit        — update a DRAFT or REJECTED record
 *   delete      — delete DRAFT only
 *   approve     — PENDING → APPROVED (Principal/Admin)
 *   reject      — PENDING → REJECTED with reason
 *
 * Multi-date POST arrays (save_draft / submit / edit):
 *   work_dates[]     — ISO date strings (YYYY-MM-DD)
 *   days_per_date[]  — day count per date (parallel index)
 *   pay_per_date[]   — equivalent pay per date (parallel index)
 */
require_once __DIR__ . '/../config/config.php';

if (!isset($_SESSION['user'])) {
    header('Location: ' . BASE_URL . 'modules/auth/login.php'); exit;
}

$action = trim($_POST['action'] ?? '');
$uid    = $_SESSION['user']['user_id'] ?? null;

$back = !empty($_POST['redirect'])
    ? filter_var($_POST['redirect'], FILTER_SANITIZE_URL)
    : BASE_URL . 'modules/service-credits/index.php';

$scId    = (int)($_POST['service_credit_id'] ?? 0);
$empId   = (int)($_POST['employee_id']        ?? 0);
$remarks = trim($_POST['remarks']              ?? '');

// ── Parse multi-date arrays ────────────────────────────────────────────────
$rawDates = is_array($_POST['work_dates']    ?? null) ? $_POST['work_dates']    : [];
$rawDays  = is_array($_POST['days_per_date'] ?? null) ? $_POST['days_per_date'] : [];
$rawPay   = is_array($_POST['pay_per_date']  ?? null) ? $_POST['pay_per_date']  : [];

$dateRows  = [];
$totalDays = 0.0;
$totalPay  = 0.0;

foreach ($rawDates as $i => $wd) {
    $wd  = trim($wd);
    $d   = isset($rawDays[$i]) ? max(0.5, (float)$rawDays[$i]) : 1.0;
    $pay = isset($rawPay[$i])  ? max(0.0, (float)$rawPay[$i])  : 0.0;
    if ($wd && preg_match('/^\d{4}-\d{2}-\d{2}$/', $wd)) {
        $dateRows[]  = ['date' => $wd, 'days' => $d, 'pay' => $pay];
        $totalDays  += $d;
        $totalPay   += $pay;
    }
}

// Backward-compat fallback: single work_date/days/equivalent_pay fields
if (empty($dateRows)) {
    $singleDate = trim($_POST['work_date']      ?? '');
    $singleDays = (float)($_POST['days']        ?? 0);
    $singlePay  = (float)($_POST['equivalent_pay'] ?? 0);
    if ($singleDate && $singleDays > 0) {
        $dateRows[] = ['date' => $singleDate, 'days' => $singleDays, 'pay' => $singlePay];
        $totalDays   = $singleDays;
        $totalPay    = $singlePay;
    }
}

// Legacy work_date column = first (earliest) date in the request
$firstDate = $dateRows[0]['date'] ?? date('Y-m-d');

// ── SAVE DRAFT ────────────────────────────────────────────────────────────
if ($action === 'save_draft') {
    if (!$empId || empty($dateRows)) {
        $_SESSION['sc_error'] = 'Please select an employee and add at least one work date.';
        header("Location: $back"); exit;
    }
    try {
        $pdo->beginTransaction();
        $pdo->prepare("
            INSERT INTO service_credits
                (employee_id, work_date, days, equivalent_pay, remarks,
                 status, is_approved, approved_by)
            VALUES (?,?,?,?,?,'DRAFT',0,NULL)
        ")->execute([$empId, $firstDate, $totalDays, $totalPay, $remarks]);
        $scId = (int)$pdo->lastInsertId();
        scInsertDateRows($pdo, $scId, $dateRows);
        $pdo->commit();
    } catch (Throwable $e) {
        $pdo->rollBack();
        $_SESSION['sc_error'] = 'Failed to save draft: ' . $e->getMessage();
        header("Location: $back"); exit;
    }
    if ($uid) scLogAudit($pdo, $uid, 'CREATE', $scId,
        "Saved draft SC #{$scId} for emp #{$empId} — {$totalDays} day(s) / ₱" . number_format($totalPay, 2));
    $_SESSION['sc_success'] = 'Draft saved. Submit it for approval when ready.';
    header("Location: $back"); exit;
}

// ── SUBMIT ────────────────────────────────────────────────────────────────
if ($action === 'submit') {
    if (!$empId || empty($dateRows)) {
        $_SESSION['sc_error'] = 'Please select an employee and add at least one work date.';
        header("Location: $back"); exit;
    }
    try {
        $pdo->beginTransaction();
        $pdo->prepare("
            INSERT INTO service_credits
                (employee_id, work_date, days, equivalent_pay, remarks,
                 status, is_approved, approved_by)
            VALUES (?,?,?,?,?,'PENDING',0,NULL)
        ")->execute([$empId, $firstDate, $totalDays, $totalPay, $remarks]);
        $scId = (int)$pdo->lastInsertId();
        scInsertDateRows($pdo, $scId, $dateRows);
        $pdo->commit();
    } catch (Throwable $e) {
        $pdo->rollBack();
        $_SESSION['sc_error'] = 'Failed to submit: ' . $e->getMessage();
        header("Location: $back"); exit;
    }
    if ($uid) scLogAudit($pdo, $uid, 'SUBMIT', $scId,
        "Submitted SC #{$scId} for emp #{$empId} — ₱" . number_format($totalPay, 2));
    $_SESSION['sc_success'] = 'Service credit submitted for Principal approval.';
    header("Location: $back"); exit;
}

// ── RESUBMIT ──────────────────────────────────────────────────────────────
if ($action === 'resubmit') {
    if (!$scId) { $_SESSION['sc_error'] = 'Invalid record.'; header("Location: $back"); exit; }
    $check = $pdo->prepare("SELECT status FROM service_credits WHERE service_credit_id=?");
    $check->execute([$scId]);
    $existing = $check->fetch();
    if (!$existing || !in_array($existing['status'], ['DRAFT','REJECTED'])) {
        $_SESSION['sc_error'] = 'Only Draft or Rejected credits can be resubmitted.';
        header("Location: $back"); exit;
    }
    $pdo->prepare("
        UPDATE service_credits
        SET status='PENDING', rejection_reason=NULL, updated_at=NOW()
        WHERE service_credit_id=?
    ")->execute([$scId]);
    if ($uid) scLogAudit($pdo, $uid, 'RESUBMIT', $scId, "Resubmitted SC #{$scId}");
    $_SESSION['sc_success'] = 'Service credit resubmitted for approval.';
    header("Location: $back"); exit;
}

// ── EDIT ──────────────────────────────────────────────────────────────────
if ($action === 'edit') {
    if (!$scId || !$empId || empty($dateRows)) {
        $_SESSION['sc_error'] = 'Please fill in all required fields.';
        header("Location: $back"); exit;
    }
    $check = $pdo->prepare("SELECT status FROM service_credits WHERE service_credit_id=?");
    $check->execute([$scId]);
    $existing = $check->fetch();
    if (!$existing || !in_array($existing['status'], ['DRAFT','REJECTED'])) {
        $_SESSION['sc_error'] = 'Only Draft or Rejected credits can be edited.';
        header("Location: $back"); exit;
    }
    try {
        $pdo->beginTransaction();
        $pdo->prepare("
            UPDATE service_credits
            SET employee_id=?, work_date=?, days=?, equivalent_pay=?, remarks=?, updated_at=NOW()
            WHERE service_credit_id=?
        ")->execute([$empId, $firstDate, $totalDays, $totalPay, $remarks, $scId]);
        // Rebuild child rows (FK cascade would handle deletes, but explicit is safer)
        $pdo->prepare("DELETE FROM service_credit_dates WHERE service_credit_id=?")->execute([$scId]);
        scInsertDateRows($pdo, $scId, $dateRows);
        $pdo->commit();
    } catch (Throwable $e) {
        $pdo->rollBack();
        $_SESSION['sc_error'] = 'Failed to update: ' . $e->getMessage();
        header("Location: $back"); exit;
    }
    if ($uid) scLogAudit($pdo, $uid, 'UPDATE', $scId, "Edited SC #{$scId}");
    $_SESSION['sc_success'] = 'Service credit updated.';
    header("Location: $back"); exit;
}

// ── DELETE ────────────────────────────────────────────────────────────────
if ($action === 'delete') {
    if (!$scId) { $_SESSION['sc_error'] = 'Invalid record.'; header("Location: $back"); exit; }
    $check = $pdo->prepare("SELECT status FROM service_credits WHERE service_credit_id=?");
    $check->execute([$scId]);
    $row = $check->fetch();
    if (!$row || $row['status'] !== 'DRAFT') {
        $_SESSION['sc_error'] = 'Only Draft credits can be deleted.';
        header("Location: $back"); exit;
    }
    // FK ON DELETE CASCADE removes service_credit_dates rows automatically
    $pdo->prepare("DELETE FROM service_credits WHERE service_credit_id=?")->execute([$scId]);
    if ($uid) scLogAudit($pdo, $uid, 'DELETE', $scId, "Deleted draft SC #{$scId}");
    $_SESSION['sc_success'] = 'Draft deleted.';
    header("Location: $back"); exit;
}

// ── APPROVE ───────────────────────────────────────────────────────────────
if ($action === 'approve') {
    if (!$scId) { $_SESSION['sc_error'] = 'Invalid record.'; header("Location: $back"); exit; }
    $stmt = $pdo->prepare("
        UPDATE service_credits
        SET status='APPROVED', is_approved=1, approved_by=?, approved_at=NOW(), updated_at=NOW()
        WHERE service_credit_id=? AND status='PENDING'
    ");
    $stmt->execute([$uid, $scId]);
    if ($stmt->rowCount() === 0) {
        $_SESSION['sc_error'] = 'Could not approve — credit may have already been processed.';
        header("Location: $back"); exit;
    }
    if ($uid) scLogAudit($pdo, $uid, 'APPROVE', $scId,
        "Approved SC #{$scId} — will merge into Additional Assignment Payment in next payroll");
    $_SESSION['sc_success'] = 'Approved. Will be included in the next payroll as Additional Assignment Payment.';
    header("Location: $back"); exit;
}

// ── REJECT ────────────────────────────────────────────────────────────────
if ($action === 'reject') {
    $reason = trim($_POST['rejection_reason'] ?? '');
    if (!$scId) { $_SESSION['sc_error'] = 'Invalid record.'; header("Location: $back"); exit; }
    if (!$reason) { $_SESSION['sc_error'] = 'Please provide a rejection reason.'; header("Location: $back"); exit; }
    $pdo->prepare("
        UPDATE service_credits
        SET status='REJECTED', is_approved=0, rejection_reason=?, updated_at=NOW()
        WHERE service_credit_id=? AND status='PENDING'
    ")->execute([$reason, $scId]);
    if ($uid) scLogAudit($pdo, $uid, 'REJECT', $scId, "Rejected SC #{$scId}: {$reason}");
    $_SESSION['sc_success'] = 'Service credit rejected.';
    header("Location: $back"); exit;
}

// ── Unknown ───────────────────────────────────────────────────────────────
$_SESSION['sc_error'] = 'Unknown action.';
header("Location: $back"); exit;

// ── Helpers ───────────────────────────────────────────────────────────────
function scInsertDateRows(PDO $pdo, int $scId, array $rows): void
{
    $ins = $pdo->prepare("
        INSERT INTO service_credit_dates (service_credit_id, work_date, days, equivalent_pay)
        VALUES (?, ?, ?, ?)
    ");
    foreach ($rows as $row) {
        $ins->execute([$scId, $row['date'], $row['days'], $row['pay']]);
    }
}

function scLogAudit(PDO $pdo, int $uid, string $action, int $scId, string $desc): void
{
    $pdo->prepare("
        INSERT INTO audit_logs (user_id, action, table_name, record_id, description)
        VALUES (?, ?, 'service_credits', ?, ?)
    ")->execute([$uid, $action, $scId, $desc]);
}
