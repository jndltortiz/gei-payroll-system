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
        // Validate: principal must have acted on all dates
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

        $pdo->beginTransaction();

        $siblingId = null;

        // ── If mixed (PARTIALLY_APPROVED): split now into APPROVED + REJECTED records
        if ($leave['status'] === 'PARTIALLY_APPROVED') {
            $ri = $pdo->prepare("
                SELECT MIN(leave_date) AS start_d, MAX(leave_date) AS end_d, COUNT(*) AS cnt
                FROM leave_request_dates WHERE leave_id = ? AND status = 'REJECTED'
            ");
            $ri->execute([$leaveId]);
            $ri = $ri->fetch(PDO::FETCH_ASSOC);

            $ai = $pdo->prepare("
                SELECT MIN(leave_date) AS start_d, MAX(leave_date) AS end_d, COUNT(*) AS cnt
                FROM leave_request_dates WHERE leave_id = ? AND status = 'APPROVED'
            ");
            $ai->execute([$leaveId]);
            $ai = $ai->fetch(PDO::FETCH_ASSOC);

            // Insert REJECTED sibling
            $pdo->prepare("
                INSERT INTO leave_requests
                    (employee_id, leave_type_id, start_date, end_date, total_days,
                     reason, status, approved_by, approved_at, created_at, updated_at)
                VALUES (?, ?, ?, ?, ?, ?, 'REJECTED', ?, ?, ?, NOW())
            ")->execute([
                $leave['employee_id'], $leave['leave_type_id'],
                $ri['start_d'], $ri['end_d'], (int)$ri['cnt'],
                $leave['reason'],
                $leave['approved_by'],
                $leave['approved_at'],
                $leave['created_at'],
            ]);
            $siblingId = (int)$pdo->lastInsertId();

            // Copy optional migration columns to sibling
            try {
                $pdo->prepare("
                    UPDATE leave_requests
                    SET school_year_id      = ?,
                        is_backdated        = ?
                    WHERE leave_id = ?
                ")->execute([
                    $leave['school_year_id'] ?? null,
                    $leave['is_backdated']   ?? 0,
                    $siblingId,
                ]);
            } catch (PDOException $_e) {}

            // Move rejected dates to sibling
            $pdo->prepare("
                UPDATE leave_request_dates SET leave_id = ?
                WHERE leave_id = ? AND status = 'REJECTED'
            ")->execute([$siblingId, $leaveId]);

            // Narrow original to approved dates only
            $pdo->prepare("
                UPDATE leave_requests
                SET status     = 'APPROVED',
                    total_days = ?,
                    start_date = ?,
                    end_date   = ?,
                    updated_at = NOW()
                WHERE leave_id = ?
            ")->execute([(int)$ai['cnt'], $ai['start_d'], $ai['end_d'], $leaveId]);

            // Audit split
            $pdo->prepare("
                INSERT INTO audit_logs (user_id, action, table_name, record_id, description, created_at)
                VALUES (?, 'RECORD_SPLIT', 'leave_requests', ?, ?, NOW())
            ")->execute([
                $userId, $leaveId,
                "Mixed leave #{$leaveId} split at record time: approved portion kept, REJECTED sibling #{$siblingId} created.",
            ]);
        }

        // ── Mark original as RECORDED ────────────────────────────────────────────
        if ($hasMig016) {
            $pdo->prepare("
                UPDATE leave_requests SET workflow_status = 'RECORDED', updated_at = NOW()
                WHERE leave_id = ?
            ")->execute([$leaveId]);
        }
        try {
            $pdo->prepare("
                UPDATE leave_requests
                SET is_attendance_recorded = 1, recorded_at = NOW(), recorded_by = ?
                WHERE leave_id = ?
            ")->execute([$userId, $leaveId]);
        } catch (PDOException $_e) {}

        // ── Mark sibling as RECORDED too (if split occurred) ────────────────────
        if ($siblingId) {
            if ($hasMig016) {
                $pdo->prepare("
                    UPDATE leave_requests SET workflow_status = 'RECORDED', updated_at = NOW()
                    WHERE leave_id = ?
                ")->execute([$siblingId]);
            }
            try {
                $pdo->prepare("
                    UPDATE leave_requests
                    SET is_attendance_recorded = 1, recorded_at = NOW(), recorded_by = ?
                    WHERE leave_id = ?
                ")->execute([$userId, $siblingId]);
            } catch (PDOException $_e) {}
        }

        // ── Attendance sync: ABSENT → LEAVE for all APPROVED dates ───────────────
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
            "Recorded result for leave #{$leaveId}. Attendance synced: {$synced} record(s) updated to LEAVE."
            . ($siblingId ? " REJECTED sibling #{$siblingId} also recorded." : ''),
        ]);

        $pdo->commit();

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
