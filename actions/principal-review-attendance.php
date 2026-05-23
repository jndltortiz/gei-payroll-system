<?php
/**
 * actions/principal-review-attendance.php
 * Principal-only: updates review_status on an attendance record.
 * Does NOT modify time_in / time_out / attendance_status — read-only for principal.
 *
 * POST params:
 *   attendance_id   — required (int)
 *   review_status   — REVIEWED | APPROVED | FLAGGED  (required)
 *   review_remark   — optional notes/comment
 *
 * Returns JSON { success, message }
 */
header('Content-Type: application/json');
require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../includes/auth.php';
requirePrincipal();

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    echo json_encode(['success' => false, 'message' => 'Invalid request method.']);
    exit;
}

$attendanceId  = (int)($_POST['attendance_id']  ?? 0);
$reviewStatus  = trim($_POST['review_status']   ?? '');
$reviewRemark  = trim($_POST['review_remark']   ?? '') ?: null;

$validStatuses = ['REVIEWED', 'APPROVED', 'FLAGGED'];

if (!$attendanceId) {
    echo json_encode(['success' => false, 'message' => 'Invalid attendance record ID.']);
    exit;
}
if (!in_array($reviewStatus, $validStatuses, true)) {
    echo json_encode(['success' => false, 'message' => 'Invalid review status. Must be REVIEWED, APPROVED, or FLAGGED.']);
    exit;
}

// Check migration 007 (review_status column must exist)
$hasMig007 = (bool)$pdo->query("
    SELECT COUNT(*) FROM information_schema.COLUMNS
    WHERE TABLE_SCHEMA = DATABASE()
      AND TABLE_NAME   = 'attendance_records'
      AND COLUMN_NAME  = 'review_status'
")->fetchColumn();

if (!$hasMig007) {
    echo json_encode(['success' => false, 'message' => 'Review feature requires database migration 007. Contact the administrator.']);
    exit;
}

// Fetch current record for audit snapshot
$curr = $pdo->prepare("
    SELECT review_status, attendance_status, time_in, time_out, attendance_source, remarks
    FROM attendance_records
    WHERE attendance_id = ?
");
$curr->execute([$attendanceId]);
$old = $curr->fetch(PDO::FETCH_ASSOC);

if (!$old) {
    echo json_encode(['success' => false, 'message' => 'Attendance record not found.']);
    exit;
}

$userId = $_SESSION['user']['user_id'] ?? null;

try {
    $pdo->prepare("
        UPDATE attendance_records
        SET review_status = :rs,
            updated_at    = NOW()
        WHERE attendance_id = :id
    ")->execute([':rs' => $reviewStatus, ':id' => $attendanceId]);

    // Audit log — REVIEW action
    $reason = $reviewStatus;
    if ($reviewRemark) $reason .= ': ' . $reviewRemark;

    try {
        $pdo->prepare("
            INSERT INTO attendance_audit_log
                (attendance_id, changed_by, action_type,
                 old_status, new_status,
                 old_method, new_method,
                 reason)
            VALUES (?, ?, 'REVIEW', ?, ?, ?, ?, ?)
        ")->execute([
            $attendanceId,
            $userId,
            $old['attendance_status'],
            $old['attendance_status'],
            $old['attendance_source'],
            $old['attendance_source'],
            $reason,
        ]);
    } catch (PDOException $auditEx) {
        // Audit table absent — non-fatal
    }

    $label = match($reviewStatus) {
        'REVIEWED' => 'Marked as Reviewed',
        'APPROVED' => 'Verified',
        'FLAGGED'  => 'Flagged for Admin Correction',
        default    => $reviewStatus,
    };

    echo json_encode(['success' => true, 'message' => $label . ' successfully.']);

} catch (PDOException $e) {
    http_response_code(500);
    echo json_encode(['success' => false, 'message' => 'Database error: ' . $e->getMessage()]);
}
