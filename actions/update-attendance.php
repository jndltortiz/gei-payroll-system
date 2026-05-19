<?php
/**
 * actions/update-attendance.php
 * Updates a single attendance_records row.
 * Returns JSON — called by the edit-attendance modal via fetch().
 */
require_once __DIR__ . '/../config/config.php';
require_once __DIR__ . '/../includes/auth.php';

header('Content-Type: application/json');
requireLogin();

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    echo json_encode(['success' => false, 'message' => 'Invalid request method.']);
    exit;
}

$attendanceId = (int)($_POST['attendance_id'] ?? 0);
$date         = trim($_POST['date']           ?? '');
$timeIn       = trim($_POST['time_in']        ?? '') ?: null;
$timeOut      = trim($_POST['time_out']       ?? '') ?: null;
$status       = trim($_POST['status']         ?? 'PRESENT');
$remarks      = trim($_POST['remarks']        ?? '') ?: null;

$validStatuses = ['PRESENT', 'ABSENT', 'LATE', 'HALF_DAY', 'INCOMPLETE', 'LEAVE', 'HOLIDAY'];

if (!$attendanceId) {
    echo json_encode(['success' => false, 'message' => 'Invalid attendance record ID.']);
    exit;
}
if (!$date || !preg_match('/^\d{4}-\d{2}-\d{2}$/', $date)) {
    echo json_encode(['success' => false, 'message' => 'Invalid date.']);
    exit;
}
if (!in_array($status, $validStatuses)) {
    echo json_encode(['success' => false, 'message' => 'Invalid status.']);
    exit;
}

// Calculate late_minutes if time_in provided and we can get the shift
$lateMinutes = 0;
if ($timeIn) {
    $row = $pdo->prepare("
        SELECT s.start_time, s.grace_period_minutes
        FROM attendance_records ar
        JOIN employees e ON ar.employee_id = e.employee_id
        LEFT JOIN shifts s ON e.shift_id = s.shift_id
        WHERE ar.attendance_id = :id
    ");
    $row->execute([':id' => $attendanceId]);
    $shift = $row->fetch();
    if ($shift && $shift['start_time']) {
        $shiftStart  = strtotime($date . ' ' . $shift['start_time']);
        $grace       = (int)($shift['grace_period_minutes'] ?? 0);
        $actualIn    = strtotime($date . ' ' . $timeIn);
        $diff        = ($actualIn - $shiftStart - ($grace * 60)) / 60;
        $lateMinutes = $diff > 0 ? (int)$diff : 0;
    }
}

try {
    $stmt = $pdo->prepare("
        UPDATE attendance_records SET
            attendance_date    = :date,
            time_in            = :time_in,
            time_out           = :time_out,
            attendance_status  = :status,
            late_minutes       = :late_minutes,
            remarks            = :remarks,
            updated_at         = NOW()
        WHERE attendance_id = :id
    ");
    $stmt->execute([
        ':date'         => $date,
        ':time_in'      => $timeIn,
        ':time_out'     => $timeOut,
        ':status'       => $status,
        ':late_minutes' => $lateMinutes,
        ':remarks'      => $remarks,
        ':id'           => $attendanceId,
    ]);

    // Audit log
    $userId = $_SESSION['user']['user_id'] ?? null;
    if ($userId) {
        $pdo->prepare("
            INSERT INTO audit_logs (user_id, action, table_name, record_id, description)
            VALUES (?, 'UPDATE', 'attendance_records', ?, ?)
        ")->execute([$userId, $attendanceId, "Updated attendance #{$attendanceId} to {$status}"]);
    }

    echo json_encode(['success' => true, 'message' => 'Attendance updated successfully.']);

} catch (PDOException $e) {
    echo json_encode(['success' => false, 'message' => 'Database error: ' . $e->getMessage()]);
}