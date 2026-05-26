<?php
/**
 * actions/leave-admin-action.php
 * Admin workflow actions for leave management.
 *
 * POST params:
 *   action    = 'mark_reviewed' | 'forward' | 'record'
 *   leave_id  = int
 *   note      = string (admin note, optional)
 *
 * mark_reviewed – saves admin note, marks request as internally reviewed.
 * forward       – sets workflow_status = FORWARDED, sends to principal queue.
 * record        – sets workflow_status = RECORDED after principal has decided;
 *                 syncs any ABSENT attendance records → LEAVE for approved dates.
 */

require_once __DIR__ . '/../config/config.php';
require_once __DIR__ . '/../includes/auth.php';

header('Content-Type: application/json');

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    echo json_encode(['success' => false, 'message' => 'Invalid method.']);
    exit;
}

requireLogin();

$role   = strtolower($_SESSION['user']['role'] ?? '');
if ($role === 'employee') {
    echo json_encode(['success' => false, 'message' => 'Not authorised.']);
    exit;
}

$action  = trim($_POST['action']  ?? '');
$leaveId = (int)($_POST['leave_id'] ?? 0);
$note    = trim($_POST['note']    ?? '');
$userId  = $_SESSION['user']['user_id'] ?? null;

if (!$leaveId) {
    echo json_encode(['success' => false, 'message' => 'Leave ID required.']);
    exit;
}

// Migration guard — workflow_status column may not exist on older installs
$hasMig016 = (bool)$pdo->query("SHOW COLUMNS FROM `leave_requests` LIKE 'workflow_status'")->fetch();

try {
    $stmtLR = $pdo->prepare("SELECT * FROM leave_requests WHERE leave_id = ?");
    $stmtLR->execute([$leaveId]);
    $leave = $stmtLR->fetch();

    if (!$leave) {
        echo json_encode(['success' => false, 'message' => 'Leave request not found.']);
        exit;
    }

    if ($action === 'mark_reviewed') {
        if ($hasMig016) {
            $pdo->prepare("
                UPDATE leave_requests
                SET admin_note = ?, updated_at = NOW()
                WHERE leave_id = ?
            ")->execute([$note ?: null, $leaveId]);
        }

        $pdo->prepare("
            INSERT INTO audit_logs (user_id, action, table_name, record_id, description, created_at)
            VALUES (?, 'ADMIN_REVIEW', 'leave_requests', ?, ?, NOW())
        ")->execute([
            $userId, $leaveId,
            "Admin reviewed leave #{$leaveId}" . ($note ? ": {$note}" : ''),
        ]);

        echo json_encode(['success' => true, 'message' => 'Note saved successfully.']);

    } elseif ($action === 'forward') {
        if ($hasMig016) {
            $pdo->prepare("
                UPDATE leave_requests
                SET workflow_status = 'FORWARDED',
                    forwarded_at    = NOW(),
                    forwarded_by    = ?,
                    admin_note      = COALESCE(NULLIF(?, ''), admin_note),
                    updated_at      = NOW()
                WHERE leave_id = ?
            ")->execute([$userId, $note, $leaveId]);
        }

        $pdo->prepare("
            INSERT INTO audit_logs (user_id, action, table_name, record_id, description, created_at)
            VALUES (?, 'FORWARD_PRINCIPAL', 'leave_requests', ?, ?, NOW())
        ")->execute([
            $userId, $leaveId,
            "Forwarded leave #{$leaveId} to principal for review" . ($note ? ": {$note}" : ''),
        ]);

        echo json_encode(['success' => true, 'message' => 'Leave request forwarded to principal.']);

    } elseif ($action === 'record') {
        // Validate: principal should have acted on all dates (no PENDING dates remaining)
        $stmtPending = $pdo->prepare("
            SELECT COUNT(*) FROM leave_request_dates WHERE leave_id = ? AND status = 'PENDING'
        ");
        $stmtPending->execute([$leaveId]);
        $pendingLeft = (int)$stmtPending->fetchColumn();

        if ($pendingLeft > 0) {
            echo json_encode([
                'success' => false,
                'message' => "Cannot record: {$pendingLeft} date(s) still pending principal decision.",
            ]);
            exit;
        }

        if ($hasMig016) {
            $pdo->prepare("
                UPDATE leave_requests SET workflow_status = 'RECORDED', updated_at = NOW()
                WHERE leave_id = ?
            ")->execute([$leaveId]);
        }

        // Attendance sync: ABSENT → LEAVE for all approved dates
        $stmtApproved = $pdo->prepare("
            SELECT leave_date FROM leave_request_dates WHERE leave_id = ? AND status = 'APPROVED'
        ");
        $stmtApproved->execute([$leaveId]);
        $approvedDates = $stmtApproved->fetchAll(PDO::FETCH_COLUMN);

        $empId  = (int)$leave['employee_id'];
        $synced = 0;

        foreach ($approvedDates as $date) {
            $stmtSync = $pdo->prepare("
                UPDATE attendance_records
                SET attendance_status = 'LEAVE', updated_at = NOW()
                WHERE employee_id = ? AND attendance_date = ? AND attendance_status = 'ABSENT'
            ");
            $stmtSync->execute([$empId, $date]);
            $synced += $stmtSync->rowCount();
        }

        $pdo->prepare("
            INSERT INTO audit_logs (user_id, action, table_name, record_id, description, created_at)
            VALUES (?, 'RECORD_RESULT', 'leave_requests', ?, ?, NOW())
        ")->execute([
            $userId, $leaveId,
            "Recorded result for leave #{$leaveId}. Attendance synced: {$synced} record(s) updated to LEAVE.",
        ]);

        echo json_encode([
            'success' => true,
            'message' => 'Result recorded.' . ($synced > 0 ? " {$synced} attendance record(s) updated to On Leave." : ''),
            'synced'  => $synced,
        ]);

    } else {
        echo json_encode(['success' => false, 'message' => 'Unknown action.']);
    }

} catch (PDOException $e) {
    echo json_encode(['success' => false, 'message' => 'Database error: ' . $e->getMessage()]);
}
