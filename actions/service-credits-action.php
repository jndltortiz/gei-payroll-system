<?php
/**
 * actions/service-credits-action.php
 * Service Credit CRUD + approval workflow (per-date granularity).
 *
 * Actions (parent-level):
 *   save_draft  — save as DRAFT
 *   submit      — save as PENDING
 *   resubmit    — DRAFT or REJECTED → PENDING
 *   edit        — update a DRAFT or REJECTED record
 *   approve     — PENDING → APPROVED (all dates approved in bulk)
 *   reject      — PENDING → REJECTED (all dates rejected in bulk)
 *   archive     — soft-delete eligible records
 *   restore     — ARCHIVED → DRAFT
 *
 * Actions (per-date):
 *   approve_date — approve a single service_credit_dates row
 *   reject_date  — reject a single service_credit_dates row with reason
 *
 * Multi-date POST arrays (save_draft / submit / edit):
 *   work_dates[]     — ISO date strings (YYYY-MM-DD)
 *   days_per_date[]  — day count per date (parallel index)
 *   pay_per_date[]   — equivalent pay per date (parallel index)
 */
require_once __DIR__ . '/../config/config.php';
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/notif-utils.php';

if (!isset($_SESSION['user'])) {
    header('Location: ' . BASE_URL . 'modules/auth/login.php'); exit;
}

$action = trim($_POST['action'] ?? '');
$uid    = $_SESSION['user']['user_id'] ?? null;

$back = !empty($_POST['redirect'])
    ? filter_var($_POST['redirect'], FILTER_SANITIZE_URL)
    : BASE_URL . 'modules/service-credits/index.php';

$scId        = (int)($_POST['service_credit_id'] ?? 0);
$scDateId    = (int)($_POST['sc_date_id']         ?? 0);
$empId       = (int)($_POST['employee_id']         ?? 0);
$remarks     = trim($_POST['remarks']              ?? '');
$targetPerId = (int)($_POST['target_period_id']    ?? 0) ?: null;

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
                (employee_id, work_date, days, equivalent_pay, remarks, target_period_id,
                 status, is_approved, approved_by, created_by)
            VALUES (?,?,?,?,?,?,'DRAFT',0,NULL,?)
        ")->execute([$empId, $firstDate, $totalDays, $totalPay, $remarks, $targetPerId, $uid]);
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
                (employee_id, work_date, days, equivalent_pay, remarks, target_period_id,
                 status, is_approved, approved_by, created_by)
            VALUES (?,?,?,?,?,?,'PENDING',0,NULL,?)
        ")->execute([$empId, $firstDate, $totalDays, $totalPay, $remarks, $targetPerId, $uid]);
        $scId = (int)$pdo->lastInsertId();
        scInsertDateRows($pdo, $scId, $dateRows);
        $pdo->commit();
    } catch (Throwable $e) {
        $pdo->rollBack();
        $_SESSION['sc_error'] = 'Failed to submit: ' . $e->getMessage();
        header("Location: $back"); exit;
    }
    // Notify principals about the new service credit pending approval
    try {
        $empNameSC = getEmployeeName($pdo, $empId);
        notifPrincipals($pdo,
            "Service Credit Submitted for Approval",
            "{$empNameSC} submitted a service credit for {$totalDays} day(s) (₱" . number_format($totalPay, 2) . ") awaiting your approval.",
            'service_credit',
            BASE_URL . 'modules/service-credits/index.php',
            $scId
        );
    } catch (Exception $ignored) {}

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
    $pdo->beginTransaction();
    $pdo->prepare("
        UPDATE service_credits
        SET status='PENDING', rejection_reason=NULL, updated_at=NOW()
        WHERE service_credit_id=?
    ")->execute([$scId]);
    // Reset all date rows to PENDING so per-date review can start fresh
    $pdo->prepare("
        UPDATE service_credit_dates
        SET status='PENDING', rejection_reason=NULL, approved_by=NULL, approved_at=NULL
        WHERE service_credit_id=?
    ")->execute([$scId]);
    $pdo->commit();
    // Notify principals it's back in the queue
    try {
        $scResubStmt = $pdo->prepare("SELECT employee_id, days, equivalent_pay FROM service_credits WHERE service_credit_id=?");
        $scResubStmt->execute([$scId]);
        $scri = $scResubStmt->fetch();
        if ($scri) {
            $empNameResubSC = getEmployeeName($pdo, (int)$scri['employee_id']);
            notifPrincipals($pdo,
                "Service Credit Resubmitted",
                "{$empNameResubSC} resubmitted a service credit for {$scri['days']} day(s) (₱" . number_format($scri['equivalent_pay'], 2) . ") awaiting your approval.",
                'service_credit',
                BASE_URL . 'modules/service-credits/index.php',
                $scId
            );
        }
    } catch (Exception $ignored) {}

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
            SET employee_id=?, work_date=?, days=?, equivalent_pay=?,
                remarks=?, target_period_id=?, updated_at=NOW()
            WHERE service_credit_id=?
        ")->execute([$empId, $firstDate, $totalDays, $totalPay, $remarks, $targetPerId, $scId]);
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

// ── APPROVE (bulk — all dates, principal only) ────────────────────────────
if ($action === 'approve') {
    if (!isPrincipalRole()) { $_SESSION['sc_error'] = 'Only the Principal can approve service credits.'; header("Location: $back"); exit; }
    if (!$scId) { $_SESSION['sc_error'] = 'Invalid record.'; header("Location: $back"); exit; }
    $pdo->beginTransaction();
    $stmt = $pdo->prepare("
        UPDATE service_credits
        SET status='APPROVED', is_approved=1, approved_by=?, approved_at=NOW(), updated_at=NOW()
        WHERE service_credit_id=? AND status='PENDING'
    ");
    $stmt->execute([$uid, $scId]);
    if ($stmt->rowCount() === 0) {
        $pdo->rollBack();
        $_SESSION['sc_error'] = 'Could not approve — credit may have already been processed.';
        header("Location: $back"); exit;
    }
    $pdo->prepare("
        UPDATE service_credit_dates
        SET status='APPROVED', approved_by=?, approved_at=NOW()
        WHERE service_credit_id=? AND status='PENDING'
    ")->execute([$uid, $scId]);
    $pdo->commit();

    // Notify the employee
    try {
        $scAppStmt = $pdo->prepare("SELECT employee_id, days, equivalent_pay FROM service_credits WHERE service_credit_id=?");
        $scAppStmt->execute([$scId]);
        $scApp = $scAppStmt->fetch();
        if ($scApp) {
            $scEmpUid = getEmployeeUserId($pdo, (int)$scApp['employee_id']);
            sendNotif($pdo, $scEmpUid,
                "Service Credit Approved",
                "Your service credit for {$scApp['days']} day(s) (₱" . number_format($scApp['equivalent_pay'], 2) . ") has been approved. It will be released during the EOSY Accrued Pay run.",
                'service_credit',
                BASE_URL . 'modules/employee/service-credits/index.php',
                $scId
            );
        }
    } catch (Exception $ignored) {}

    if ($uid) scLogAudit($pdo, $uid, 'APPROVE', $scId,
        "Approved SC #{$scId} (all dates) — will be released in EOSY Accrued Pay run");
    $_SESSION['sc_success'] = 'Approved. Service credit will be released during the EOSY Accrued Pay run as Additional Assignment Payment.';
    header("Location: $back"); exit;
}

// ── REJECT (bulk — all pending dates, principal only) ─────────────────────
if ($action === 'reject') {
    if (!isPrincipalRole()) { $_SESSION['sc_error'] = 'Only the Principal can reject service credits.'; header("Location: $back"); exit; }
    $reason = trim($_POST['rejection_reason'] ?? '');
    if (!$scId) { $_SESSION['sc_error'] = 'Invalid record.'; header("Location: $back"); exit; }
    if (!$reason) { $_SESSION['sc_error'] = 'Please provide a rejection reason.'; header("Location: $back"); exit; }
    $pdo->beginTransaction();
    $pdo->prepare("
        UPDATE service_credits
        SET status='REJECTED', is_approved=0, rejection_reason=?, updated_at=NOW()
        WHERE service_credit_id=? AND status='PENDING'
    ")->execute([$reason, $scId]);
    $pdo->prepare("
        UPDATE service_credit_dates
        SET status='REJECTED', rejection_reason=?, approved_by=?, approved_at=NOW()
        WHERE service_credit_id=? AND status='PENDING'
    ")->execute([$reason, $uid, $scId]);
    $pdo->commit();

    // Notify the employee
    try {
        $scRejStmt = $pdo->prepare("SELECT employee_id FROM service_credits WHERE service_credit_id=?");
        $scRejStmt->execute([$scId]);
        $scRej = $scRejStmt->fetch();
        if ($scRej) {
            $scEmpUid2 = getEmployeeUserId($pdo, (int)$scRej['employee_id']);
            sendNotif($pdo, $scEmpUid2,
                "Service Credit Rejected",
                "Your service credit request has been rejected." . ($reason ? " Reason: {$reason}" : ''),
                'service_credit',
                BASE_URL . 'modules/employee/service-credits/index.php',
                $scId
            );
        }
    } catch (Exception $ignored) {}

    if ($uid) scLogAudit($pdo, $uid, 'REJECT', $scId, "Rejected SC #{$scId}: {$reason}");
    $_SESSION['sc_success'] = 'Service credit rejected.';
    header("Location: $back"); exit;
}

// ── APPROVE DATE (per-date, principal only) ───────────────────────────────
if ($action === 'approve_date') {
    if (!isPrincipalRole()) { $_SESSION['sc_error'] = 'Only the Principal can approve individual work dates.'; header("Location: $back"); exit; }
    if (!$scDateId || !$scId) { $_SESSION['sc_error'] = 'Invalid parameters.'; header("Location: $back"); exit; }
    $check = $pdo->prepare("
        SELECT sc_date_id FROM service_credit_dates
        WHERE sc_date_id=? AND service_credit_id=? AND status='PENDING'
    ");
    $check->execute([$scDateId, $scId]);
    if (!$check->fetch()) {
        $_SESSION['sc_error'] = 'Date not found or already reviewed.';
        header("Location: $back"); exit;
    }
    $pdo->beginTransaction();
    $pdo->prepare("
        UPDATE service_credit_dates
        SET status='APPROVED', approved_by=?, approved_at=NOW()
        WHERE sc_date_id=?
    ")->execute([$uid, $scDateId]);
    $newStatus = recomputeParentStatus($pdo, $scId, $uid);
    $pdo->commit();
    if ($uid) scLogAudit($pdo, $uid, 'APPROVE_DATE', $scId,
        "Approved date #$scDateId of SC #$scId → parent now $newStatus");
    $msg = ($newStatus === 'PENDING')
        ? 'Date approved.'
        : 'Review complete — service credit processed.';
    $_SESSION['sc_success'] = $msg;
    header("Location: $back"); exit;
}

// ── REJECT DATE (per-date, principal only) ────────────────────────────────
if ($action === 'reject_date') {
    if (!isPrincipalRole()) { $_SESSION['sc_error'] = 'Only the Principal can reject individual work dates.'; header("Location: $back"); exit; }
    $reason = trim($_POST['rejection_reason'] ?? '');
    if (!$scDateId || !$scId) { $_SESSION['sc_error'] = 'Invalid parameters.'; header("Location: $back"); exit; }
    if (!$reason) { $_SESSION['sc_error'] = 'Please provide a rejection reason.'; header("Location: $back"); exit; }
    $check = $pdo->prepare("
        SELECT sc_date_id FROM service_credit_dates
        WHERE sc_date_id=? AND service_credit_id=? AND status='PENDING'
    ");
    $check->execute([$scDateId, $scId]);
    if (!$check->fetch()) {
        $_SESSION['sc_error'] = 'Date not found or already reviewed.';
        header("Location: $back"); exit;
    }
    $pdo->beginTransaction();
    $pdo->prepare("
        UPDATE service_credit_dates
        SET status='REJECTED', rejection_reason=?, approved_by=?, approved_at=NOW()
        WHERE sc_date_id=?
    ")->execute([$reason, $uid, $scDateId]);
    $newStatus = recomputeParentStatus($pdo, $scId, $uid);
    $pdo->commit();
    if ($uid) scLogAudit($pdo, $uid, 'REJECT_DATE', $scId,
        "Rejected date #$scDateId of SC #$scId: $reason → parent now $newStatus");
    $_SESSION['sc_success'] = 'Date rejected.';
    header("Location: $back"); exit;
}

// ── ARCHIVE ───────────────────────────────────────────────────────────────
if ($action === 'archive') {
    if (!$scId) { $_SESSION['sc_error'] = 'Invalid record.'; header("Location: $back"); exit; }
    $check = $pdo->prepare("SELECT status FROM service_credits WHERE service_credit_id=?");
    $check->execute([$scId]);
    $row = $check->fetch();
    if (!$row) { $_SESSION['sc_error'] = 'Record not found.'; header("Location: $back"); exit; }
    if (!in_array($row['status'], ['DRAFT','REJECTED','PARTIALLY_APPROVED','APPLIED','RELEASED'])) {
        $_SESSION['sc_error'] = 'Only Draft, Rejected, Applied, or Released credits can be archived.';
        header("Location: $back"); exit;
    }
    $pdo->prepare("
        UPDATE service_credits SET status='ARCHIVED', archived_at=NOW(), updated_at=NOW()
        WHERE service_credit_id=?
    ")->execute([$scId]);
    if ($uid) scLogAudit($pdo, $uid, 'ARCHIVE', $scId, "Archived SC #{$scId}");
    $_SESSION['sc_success'] = 'Record archived.';
    header("Location: $back"); exit;
}

// ── RESTORE ───────────────────────────────────────────────────────────────
if ($action === 'restore') {
    if (!$scId) { $_SESSION['sc_error'] = 'Invalid record.'; header("Location: $back"); exit; }
    $check = $pdo->prepare("SELECT status FROM service_credits WHERE service_credit_id=?");
    $check->execute([$scId]);
    $row = $check->fetch();
    if (!$row || $row['status'] !== 'ARCHIVED') {
        $_SESSION['sc_error'] = 'Only archived records can be restored.';
        header("Location: $back"); exit;
    }
    $pdo->prepare("
        UPDATE service_credits SET status='DRAFT', archived_at=NULL, updated_at=NOW()
        WHERE service_credit_id=?
    ")->execute([$scId]);
    if ($uid) scLogAudit($pdo, $uid, 'RESTORE', $scId, "Restored SC #{$scId} to Draft");
    $_SESSION['sc_success'] = 'Record restored to Draft.';
    header("Location: $back"); exit;
}

// ── DELETE (legacy — draft only) ──────────────────────────────────────────
if ($action === 'delete') {
    if (!$scId) { $_SESSION['sc_error'] = 'Invalid record.'; header("Location: $back"); exit; }
    $check = $pdo->prepare("SELECT status FROM service_credits WHERE service_credit_id=?");
    $check->execute([$scId]);
    $row = $check->fetch();
    if (!$row || $row['status'] !== 'DRAFT') {
        $_SESSION['sc_error'] = 'Only Draft credits can be deleted.';
        header("Location: $back"); exit;
    }
    $pdo->prepare("DELETE FROM service_credit_dates WHERE service_credit_id=?")->execute([$scId]);
    $pdo->prepare("DELETE FROM service_credits WHERE service_credit_id=?")->execute([$scId]);
    if ($uid) scLogAudit($pdo, $uid, 'DELETE', $scId, "Deleted draft SC #{$scId}");
    $_SESSION['sc_success'] = 'Draft deleted.';
    header("Location: $back"); exit;
}

// ── Unknown ───────────────────────────────────────────────────────────────
$_SESSION['sc_error'] = 'Unknown action.';
header("Location: $back"); exit;

// ── Helpers ───────────────────────────────────────────────────────────────

/**
 * Insert per-date rows. Status defaults to PENDING (DB default).
 */
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

/**
 * Recompute parent status after a per-date action.
 * - Any PENDING dates remaining  → parent stays PENDING.
 * - All APPROVED                 → parent APPROVED.
 * - All REJECTED                 → parent REJECTED.
 * - Mixed (some APPROVED + some REJECTED) → split: original becomes APPROVED
 *   for the approved dates; a new sibling record is created as REJECTED
 *   for the rejected dates. PARTIALLY_APPROVED is never written.
 */
function recomputeParentStatus(PDO $pdo, int $scId, int $uid): string
{
    $row = $pdo->prepare("
        SELECT
            SUM(status = 'PENDING')  AS pending_cnt,
            SUM(status = 'APPROVED') AS approved_cnt,
            SUM(status = 'REJECTED') AS rejected_cnt,
            SUM(CASE WHEN status = 'APPROVED' THEN days           ELSE 0 END) AS approved_days,
            SUM(CASE WHEN status = 'APPROVED' THEN equivalent_pay ELSE 0 END) AS approved_pay,
            SUM(CASE WHEN status = 'REJECTED' THEN days           ELSE 0 END) AS rejected_days,
            SUM(CASE WHEN status = 'REJECTED' THEN equivalent_pay ELSE 0 END) AS rejected_pay
        FROM service_credit_dates
        WHERE service_credit_id = ?
    ");
    $row->execute([$scId]);
    $c = $row->fetch(PDO::FETCH_ASSOC);

    $pendingCnt   = (int)$c['pending_cnt'];
    $approvedCnt  = (int)$c['approved_cnt'];
    $rejectedCnt  = (int)$c['rejected_cnt'];
    $approvedDays = (float)$c['approved_days'];
    $approvedPay  = (float)$c['approved_pay'];
    $rejectedDays = (float)$c['rejected_days'];
    $rejectedPay  = (float)$c['rejected_pay'];

    if ($pendingCnt > 0) {
        return 'PENDING';
    }

    if ($approvedCnt > 0 && $rejectedCnt > 0) {
        // Split: keep original for approved dates, create sibling for rejected dates
        $parent = $pdo->prepare("SELECT * FROM service_credits WHERE service_credit_id = ?");
        $parent->execute([$scId]);
        $p = $parent->fetch(PDO::FETCH_ASSOC);

        $firstRej = $pdo->prepare("
            SELECT work_date FROM service_credit_dates
            WHERE service_credit_id = ? AND status = 'REJECTED'
            ORDER BY work_date ASC LIMIT 1
        ");
        $firstRej->execute([$scId]);
        $rejWorkDate = $firstRej->fetchColumn() ?: $p['work_date'];

        $pdo->prepare("
            INSERT INTO service_credits
                (employee_id, work_date, days, equivalent_pay, remarks, target_period_id,
                 status, is_approved, approved_by, approved_at, created_by, updated_at)
            VALUES (?, ?, ?, ?, ?, ?, 'REJECTED', 0, ?, NOW(), ?, NOW())
        ")->execute([
            $p['employee_id'], $rejWorkDate, $rejectedDays, $rejectedPay,
            $p['remarks'], $p['target_period_id'], $uid, $p['created_by'],
        ]);
        $newScId = (int)$pdo->lastInsertId();

        $pdo->prepare("
            UPDATE service_credit_dates
            SET service_credit_id = ?
            WHERE service_credit_id = ? AND status = 'REJECTED'
        ")->execute([$newScId, $scId]);

        $pdo->prepare("
            UPDATE service_credits
            SET status = 'APPROVED', is_approved = 1, days = ?, equivalent_pay = ?,
                approved_by = ?, approved_at = NOW(), updated_at = NOW()
            WHERE service_credit_id = ?
        ")->execute([$approvedDays, $approvedPay, $uid, $scId]);

        scLogAudit($pdo, $uid, 'APPROVE', $scId,
            "Approved — rejected dates split to SC #{$newScId}");
        scLogAudit($pdo, $uid, 'REJECT', $newScId,
            "Rejected — split from SC #{$scId}");

        return 'APPROVED';
    }

    if ($approvedCnt > 0) {
        $pdo->prepare("
            UPDATE service_credits
            SET status = 'APPROVED', is_approved = 1, days = ?, equivalent_pay = ?,
                approved_by = ?, approved_at = NOW(), updated_at = NOW()
            WHERE service_credit_id = ?
        ")->execute([$approvedDays, $approvedPay, $uid, $scId]);
        return 'APPROVED';
    }

    $pdo->prepare("
        UPDATE service_credits
        SET status = 'REJECTED', is_approved = 0, updated_at = NOW()
        WHERE service_credit_id = ?
    ")->execute([$scId]);
    return 'REJECTED';
}

function scLogAudit(PDO $pdo, int $uid, string $action, int $scId, string $desc): void
{
    $pdo->prepare("
        INSERT INTO audit_logs (user_id, action, table_name, record_id, description)
        VALUES (?, ?, 'service_credits', ?, ?)
    ")->execute([$uid, $action, $scId, $desc]);
}
