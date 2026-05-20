<?php
/**
 * actions/service-credits-action.php
 * Service Credit CRUD + approval workflow.
 *
 * Actions:
 *   save_draft  — save as DRAFT (not yet submitted)
 *   submit      — save as PENDING (submit for Principal approval)
 *   resubmit    — convert DRAFT or REJECTED → PENDING
 *   edit        — update a DRAFT or REJECTED record
 *   delete      — delete DRAFT only
 *   approve     — PENDING → APPROVED (Principal/Admin)
 *   reject      — PENDING → REJECTED with reason
 */
require_once __DIR__ . '/../config/config.php';

if (!isset($_SESSION['user'])) {
    header('Location: ' . BASE_URL . 'modules/auth/login.php'); exit;
}

$action = trim($_POST['action'] ?? '');
$uid    = $_SESSION['user']['user_id'] ?? null;

// ── Redirect destination ───────────────────────────────────────────────────
// Pages pass their own URL so the action returns the user to the right portal
$back = !empty($_POST['redirect'])
    ? filter_var($_POST['redirect'], FILTER_SANITIZE_URL)
    : BASE_URL . 'modules/service-credits/index.php';

// ── Shared input parsing ───────────────────────────────────────────────────
$scId     = (int)($_POST['service_credit_id'] ?? 0);
$empId    = (int)($_POST['employee_id']        ?? 0);
$days     = (float)($_POST['days']             ?? 0);
$workDate = trim($_POST['work_date']            ?? '');
$remarks  = trim($_POST['remarks']              ?? '');
$equivPay = (float)($_POST['equivalent_pay']   ?? 0);

// ── SAVE DRAFT ────────────────────────────────────────────────────────────
if ($action === 'save_draft') {
    if (!$empId || $days <= 0 || !$workDate) {
        $_SESSION['sc_error'] = 'Please fill in Employee, Work Date, and Days.';
        header("Location: $back"); exit;
    }
    $pdo->prepare("
        INSERT INTO service_credits
            (employee_id, work_date, days, equivalent_pay, remarks,
             status, is_approved, approved_by)
        VALUES (?,?,?,?,?,'DRAFT',0,NULL)
    ")->execute([$empId,$workDate,$days,$equivPay,$remarks]);
    $scId = (int)$pdo->lastInsertId();
    if ($uid) logAudit($pdo,$uid,'CREATE','service_credits',$scId,
        "Saved draft service credit #{$scId} for employee #{$empId}");
    $_SESSION['sc_success'] = 'Draft saved. Submit it for approval when ready.';
    header("Location: $back"); exit;
}

// ── SUBMIT (new entry directly as PENDING) ────────────────────────────────
if ($action === 'submit') {
    if (!$empId || $days <= 0 || !$workDate) {
        $_SESSION['sc_error'] = 'Please fill in all required fields.';
        header("Location: $back"); exit;
    }
    $pdo->prepare("
        INSERT INTO service_credits
            (employee_id, work_date, days, equivalent_pay, remarks,
             status, is_approved, approved_by)
        VALUES (?,?,?,?,?,'PENDING',0,NULL)
    ")->execute([$empId,$workDate,$days,$equivPay,$remarks]);
    $scId = (int)$pdo->lastInsertId();
    if ($uid) logAudit($pdo,$uid,'SUBMIT','service_credits',$scId,
        "Submitted service credit #{$scId} for employee #{$empId} — ₱" . number_format($equivPay,2));
    $_SESSION['sc_success'] = 'Service credit submitted for Principal approval.';
    header("Location: $back"); exit;
}

// ── RESUBMIT (DRAFT or REJECTED → PENDING) ────────────────────────────────
if ($action === 'resubmit') {
    if (!$scId) { $_SESSION['sc_error']='Invalid record.'; header("Location: $back"); exit; }
    $check = $pdo->prepare("SELECT status FROM service_credits WHERE service_credit_id=?");
    $check->execute([$scId]);
    $existing = $check->fetch();
    if (!$existing || !in_array($existing['status'],['DRAFT','REJECTED'])) {
        $_SESSION['sc_error'] = 'Only Draft or Rejected credits can be resubmitted.';
        header("Location: $back"); exit;
    }
    $pdo->prepare("
        UPDATE service_credits
        SET status='PENDING', rejection_reason=NULL, updated_at=NOW()
        WHERE service_credit_id=?
    ")->execute([$scId]);
    if ($uid) logAudit($pdo,$uid,'RESUBMIT','service_credits',$scId,
        "Resubmitted service credit #{$scId} for approval");
    $_SESSION['sc_success'] = 'Service credit resubmitted for approval.';
    header("Location: $back"); exit;
}

// ── EDIT (DRAFT or REJECTED only) ─────────────────────────────────────────
if ($action === 'edit') {
    if (!$scId || !$empId || $days <= 0 || !$workDate) {
        $_SESSION['sc_error'] = 'Please fill in all required fields.';
        header("Location: $back"); exit;
    }
    $check = $pdo->prepare("SELECT status FROM service_credits WHERE service_credit_id=?");
    $check->execute([$scId]);
    $existing = $check->fetch();
    if (!$existing || !in_array($existing['status'],['DRAFT','REJECTED'])) {
        $_SESSION['sc_error'] = 'Only Draft or Rejected credits can be edited.';
        header("Location: $back"); exit;
    }
    $pdo->prepare("
        UPDATE service_credits
        SET employee_id=?,work_date=?,days=?,equivalent_pay=?,remarks=?,updated_at=NOW()
        WHERE service_credit_id=?
    ")->execute([$empId,$workDate,$days,$equivPay,$remarks,$scId]);
    if ($uid) logAudit($pdo,$uid,'UPDATE','service_credits',$scId,"Edited service credit #{$scId}");
    $_SESSION['sc_success'] = 'Service credit updated.';
    header("Location: $back"); exit;
}

// ── DELETE (DRAFT only) ───────────────────────────────────────────────────
if ($action === 'delete') {
    if (!$scId) { $_SESSION['sc_error']='Invalid record.'; header("Location: $back"); exit; }
    $check = $pdo->prepare("SELECT status FROM service_credits WHERE service_credit_id=?");
    $check->execute([$scId]);
    $row = $check->fetch();
    if (!$row || $row['status'] !== 'DRAFT') {
        $_SESSION['sc_error'] = 'Only Draft credits can be deleted.';
        header("Location: $back"); exit;
    }
    $pdo->prepare("DELETE FROM service_credits WHERE service_credit_id=?")->execute([$scId]);
    if ($uid) logAudit($pdo,$uid,'DELETE','service_credits',$scId,"Deleted draft service credit #{$scId}");
    $_SESSION['sc_success'] = 'Draft deleted.';
    header("Location: $back"); exit;
}

// ── APPROVE ───────────────────────────────────────────────────────────────
if ($action === 'approve') {
    if (!$scId) { $_SESSION['sc_error']='Invalid record.'; header("Location: $back"); exit; }
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
    if ($uid) logAudit($pdo,$uid,'APPROVE','service_credits',$scId,
        "Approved service credit #{$scId} — will merge into Additional Assignment Payment in next payroll");
    $_SESSION['sc_success'] = 'Approved. This credit will be included in the next payroll as Additional Assignment Payment.';
    header("Location: $back"); exit;
}

// ── REJECT ────────────────────────────────────────────────────────────────
if ($action === 'reject') {
    $reason = trim($_POST['rejection_reason'] ?? '');
    if (!$scId) { $_SESSION['sc_error']='Invalid record.'; header("Location: $back"); exit; }
    if (!$reason) { $_SESSION['sc_error']='Please provide a rejection reason.'; header("Location: $back"); exit; }
    $pdo->prepare("
        UPDATE service_credits
        SET status='REJECTED', is_approved=0, rejection_reason=?, updated_at=NOW()
        WHERE service_credit_id=? AND status='PENDING'
    ")->execute([$reason, $scId]);
    if ($uid) logAudit($pdo,$uid,'REJECT','service_credits',$scId,
        "Rejected service credit #{$scId}. Reason: {$reason}");
    $_SESSION['sc_success'] = 'Service credit rejected.';
    header("Location: $back"); exit;
}

// ── Unknown ───────────────────────────────────────────────────────────────
$_SESSION['sc_error'] = 'Unknown action.';
header("Location: $back"); exit;

// ── Audit helper ──────────────────────────────────────────────────────────
function logAudit(PDO $pdo, int $uid, string $action, string $table, int $recordId, string $desc): void {
    $pdo->prepare("INSERT INTO audit_logs(user_id,action,table_name,record_id,description) VALUES(?,?,?,?,?)")
        ->execute([$uid, $action, $table, $recordId, $desc]);
}