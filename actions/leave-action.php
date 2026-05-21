<?php
/**
 * actions/leave-action.php
 * Approves or rejects a leave request at two levels:
 *   level = 'all'  → update all PENDING dates in leave_request_dates,
 *                     then set parent leave_requests.status accordingly.
 *   level = 'date' → update one row in leave_request_dates,
 *                     then recalculate parent status.
 *
 * POST params:
 *   action   = 'approve' | 'reject'
 *   level    = 'all' | 'date'
 *   leave_id = int   (required for level=all)
 *   date_id  = int   (required for level=date, leave_request_dates.date_id)
 *   notes    = string (optional remarks)
 */

require_once __DIR__ . '/../config/config.php';
require_once __DIR__ . '/../includes/auth.php';

header('Content-Type: application/json');

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    echo json_encode(['success' => false, 'message' => 'Invalid request method.']);
    exit;
}

requireLogin();

$action  = $_POST['action']   ?? '';
$level   = $_POST['level']    ?? 'all';
$leaveId = (int)($_POST['leave_id'] ?? 0);
$dateId  = (int)($_POST['date_id']  ?? 0);
$notes   = trim($_POST['notes'] ?? '');
$userId  = $_SESSION['user']['user_id'] ?? null;

if (!in_array($action, ['approve', 'reject'], true)) {
    echo json_encode(['success' => false, 'message' => 'Invalid action.']);
    exit;
}

$newDateStatus = $action === 'approve' ? 'APPROVED' : 'REJECTED';

// ── Helper: recalculate parent leave_requests.status ────────
// Rules:
//   All APPROVED             → APPROVED
//   All REJECTED             → REJECTED
//   Any PENDING remaining    → PENDING
//   Mixed APPROVED+REJECTED  → APPROVED  (partial approval)
function recalcParentStatus(PDO $pdo, int $leaveId): string
{
    $stmt = $pdo->prepare("
        SELECT
            COUNT(*)                                         AS total,
            SUM(status = 'APPROVED')                         AS approved,
            SUM(status = 'REJECTED')                         AS rejected,
            SUM(status = 'PENDING')                          AS pending
        FROM leave_request_dates
        WHERE leave_id = ?
    ");
    $stmt->execute([$leaveId]);
    $row = $stmt->fetch();

    if ($row['pending'] > 0)                          return 'PENDING';
    if ($row['approved'] > 0 && $row['rejected'] > 0) return 'APPROVED'; // partial
    if ($row['approved'] == $row['total'])             return 'APPROVED';
    return 'REJECTED';
}

// ── Helper: update used_days in employee_leave_credits ───────
// delta > 0 = more days used (approvals), delta < 0 = fewer days used (reversals).
// Only fires when leave_requests.school_year_id is set (new-system requests).
function adjustLeaveCredits(PDO $pdo, int $leaveId, float $delta): void
{
    if ($delta == 0) return;

    $stmt = $pdo->prepare("
        SELECT lr.school_year_id, lr.leave_type_id, lr.employee_id
        FROM   leave_requests lr
        WHERE  lr.leave_id = ?
    ");
    $stmt->execute([$leaveId]);
    $lr = $stmt->fetch();

    if (!$lr || !$lr['school_year_id']) return; // legacy request — skip

    $pdo->prepare("
        UPDATE employee_leave_credits
        SET    used_days = GREATEST(0, used_days + ?)
        WHERE  employee_id    = ?
          AND  school_year_id = ?
          AND  leave_type_id  = ?
    ")->execute([
        $delta,
        $lr['employee_id'],
        $lr['school_year_id'],
        $lr['leave_type_id'],
    ]);
}

try {
    $pdo->beginTransaction();

    if ($level === 'all') {
        // ── Validate leave_id ────────────────────────────────
        if (!$leaveId) {
            echo json_encode(['success' => false, 'message' => 'Leave ID is required.']);
            exit;
        }

        $stmtGet = $pdo->prepare("SELECT * FROM leave_requests WHERE leave_id = ?");
        $stmtGet->execute([$leaveId]);
        $leave = $stmtGet->fetch();

        if (!$leave) {
            echo json_encode(['success' => false, 'message' => 'Leave request not found.']);
            exit;
        }

        // Count PENDING dates before update (needed for credit adjustment)
        $stmtPending = $pdo->prepare("
            SELECT COUNT(*) FROM leave_request_dates WHERE leave_id = ? AND status = 'PENDING'
        ");
        $stmtPending->execute([$leaveId]);
        $pendingCount = (int)$stmtPending->fetchColumn();

        // Update all PENDING date rows for this leave
        $pdo->prepare("
            UPDATE leave_request_dates
            SET status      = ?,
                actioned_by = ?,
                actioned_at = NOW(),
                remarks     = ?
            WHERE leave_id = ? AND status = 'PENDING'
        ")->execute([$newDateStatus, $userId, $notes ?: null, $leaveId]);

        // Adjust leave credits:
        //   approve → each PENDING date becomes APPROVED → used_days +N
        //   reject  → each PENDING date becomes REJECTED → used_days unchanged (never counted)
        if ($action === 'approve' && $pendingCount > 0) {
            adjustLeaveCredits($pdo, $leaveId, (float)$pendingCount);
        }

        // Recalculate parent status
        $parentStatus = recalcParentStatus($pdo, $leaveId);

        $pdo->prepare("
            UPDATE leave_requests
            SET status      = ?,
                approved_by = ?,
                approved_at = NOW(),
                remarks     = ?,
                updated_at  = NOW()
            WHERE leave_id  = ?
        ")->execute([$parentStatus, $userId, $notes ?: null, $leaveId]);

        // Audit
        $verb = strtoupper($action);
        $pdo->prepare("
            INSERT INTO audit_logs (user_id, action, table_name, record_id, description, created_at)
            VALUES (?, ?, 'leave_requests', ?, ?, NOW())
        ")->execute([
            $userId,
            $verb . '_ALL',
            $leaveId,
            "{$verb}D all dates for leave request #{$leaveId}" . ($notes ? " — {$notes}" : ''),
        ]);

        $pdo->commit();

        $friendlyVerb = $action === 'approve' ? 'approved' : 'rejected';
        echo json_encode(['success' => true, 'message' => "Leave request {$friendlyVerb} successfully."]);

    } else {
        // ── level = 'date' ───────────────────────────────────
        if (!$dateId) {
            echo json_encode(['success' => false, 'message' => 'Date ID is required.']);
            exit;
        }

        // Fetch the date row to get its parent leave_id and previous status
        $stmtDate = $pdo->prepare("
            SELECT * FROM leave_request_dates WHERE date_id = ?
        ");
        $stmtDate->execute([$dateId]);
        $dateRow = $stmtDate->fetch();

        if (!$dateRow) {
            echo json_encode(['success' => false, 'message' => 'Date record not found.']);
            exit;
        }

        $parentLeaveId = (int)$dateRow['leave_id'];
        $prevStatus    = $dateRow['status'];

        // Update this single date row
        $pdo->prepare("
            UPDATE leave_request_dates
            SET status      = ?,
                actioned_by = ?,
                actioned_at = NOW(),
                remarks     = ?
            WHERE date_id   = ?
        ")->execute([$newDateStatus, $userId, $notes ?: null, $dateId]);

        // Adjust leave credits based on status transition:
        //   PENDING  → APPROVED : +1 (newly approved)
        //   PENDING  → REJECTED : 0  (was never counted)
        //   APPROVED → REJECTED : -1 (reversal)
        //   APPROVED → APPROVED : 0  (no change)
        $creditDelta = 0;
        if ($prevStatus === 'PENDING'  && $newDateStatus === 'APPROVED') $creditDelta = +1;
        if ($prevStatus === 'APPROVED' && $newDateStatus === 'REJECTED') $creditDelta = -1;
        adjustLeaveCredits($pdo, $parentLeaveId, (float)$creditDelta);

        // Recalculate parent status
        $parentStatus = recalcParentStatus($pdo, $parentLeaveId);

        $pdo->prepare("
            UPDATE leave_requests
            SET status      = ?,
                approved_by = ?,
                approved_at = NOW(),
                updated_at  = NOW()
            WHERE leave_id  = ?
        ")->execute([$parentStatus, $userId, $parentLeaveId]);

        // Audit
        $verb = strtoupper($action);
        $pdo->prepare("
            INSERT INTO audit_logs (user_id, action, table_name, record_id, description, created_at)
            VALUES (?, ?, 'leave_request_dates', ?, ?, NOW())
        ")->execute([
            $userId,
            $verb . '_DATE',
            $dateId,
            "{$verb}D date_id #{$dateId} (leave #{$parentLeaveId})",
        ]);

        $pdo->commit();

        $friendlyVerb = $action === 'approve' ? 'approved' : 'rejected';
        echo json_encode(['success' => true, 'message' => "Date {$friendlyVerb} successfully."]);
    }

} catch (PDOException $e) {
    $pdo->rollBack();
    echo json_encode(['success' => false, 'message' => 'Database error: ' . $e->getMessage()]);
}