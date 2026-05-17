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

        // Update all PENDING date rows for this leave
        $pdo->prepare("
            UPDATE leave_request_dates
            SET status      = ?,
                actioned_by = ?,
                actioned_at = NOW(),
                remarks     = ?
            WHERE leave_id = ? AND status = 'PENDING'
        ")->execute([$newDateStatus, $userId, $notes ?: null, $leaveId]);

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

        // Fetch the date row to get its parent leave_id
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

        // Update this single date row
        $pdo->prepare("
            UPDATE leave_request_dates
            SET status      = ?,
                actioned_by = ?,
                actioned_at = NOW(),
                remarks     = ?
            WHERE date_id   = ?
        ")->execute([$newDateStatus, $userId, $notes ?: null, $dateId]);

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