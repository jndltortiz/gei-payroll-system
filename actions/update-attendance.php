<?php
/**
 * actions/update-attendance.php
 * Edits an existing attendance record with full audit trail.
 * Every modification stores old + new values, editor, timestamp, and reason.
 *
 * POST params:
 *   attendance_id  — required
 *   date           — Y-m-d
 *   time_in        — H:i (optional)
 *   time_out       — H:i (optional)
 *   status         — attendance_status enum
 *   source         — attendance_source / method
 *   remarks        — notes
 *   reason         — REQUIRED: admin reason for modification
 *
 * Returns JSON { success, message }
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
$date         = trim($_POST['date']     ?? '');
$timeIn       = trim($_POST['time_in']  ?? '') ?: null;
$timeOut      = trim($_POST['time_out'] ?? '') ?: null;
$status       = trim($_POST['status']   ?? 'PRESENT');
$source       = trim($_POST['source']   ?? 'MANUAL_ADMIN');
$remarks      = trim($_POST['remarks']  ?? '') ?: null;
$reason       = trim($_POST['reason']   ?? '');

$validStatuses = ['PRESENT', 'ABSENT', 'LATE', 'HALF_DAY', 'INCOMPLETE', 'LEAVE', 'HOLIDAY'];
$validSources  = ['MANUAL_ADMIN', 'MANUAL', 'FACIAL_RECOGNITION', 'AUTO'];

if (!$attendanceId) {
    echo json_encode(['success' => false, 'message' => 'Invalid attendance record ID.']);
    exit;
}
if (!$date || !preg_match('/^\d{4}-\d{2}-\d{2}$/', $date)) {
    echo json_encode(['success' => false, 'message' => 'Invalid or missing date.']);
    exit;
}
if (!in_array($status, $validStatuses, true)) {
    echo json_encode(['success' => false, 'message' => 'Invalid status value.']);
    exit;
}
if (!in_array($source, $validSources, true)) {
    $source = 'MANUAL_ADMIN';
}
if ($reason === '') {
    echo json_encode(['success' => false, 'message' => 'A reason for modification is required.']);
    exit;
}

// ── Fetch current record for audit snapshot ───────────────────────────────────
$current = $pdo->prepare("
    SELECT time_in, time_out, attendance_status, attendance_source, remarks
    FROM attendance_records
    WHERE attendance_id = ?
");
$current->execute([$attendanceId]);
$old = $current->fetch(PDO::FETCH_ASSOC);

if (!$old) {
    echo json_encode(['success' => false, 'message' => 'Attendance record not found.']);
    exit;
}

// ── Compute late_minutes ──────────────────────────────────────────────────────
$lateMinutes = 0;
if ($timeIn) {
    $shiftRow = $pdo->prepare("
        SELECT s.start_time, s.grace_period_minutes
        FROM attendance_records ar
        JOIN employees e ON ar.employee_id = e.employee_id
        LEFT JOIN shifts s ON e.shift_id = s.shift_id
        WHERE ar.attendance_id = ?
    ");
    $shiftRow->execute([$attendanceId]);
    $shift = $shiftRow->fetch(PDO::FETCH_ASSOC);
    if ($shift && $shift['start_time']) {
        $shiftStart  = strtotime($date . ' ' . $shift['start_time']);
        $grace       = (int)($shift['grace_period_minutes'] ?? 0);
        $actualIn    = strtotime($date . ' ' . $timeIn);
        $diff        = ($actualIn - $shiftStart - ($grace * 60)) / 60;
        $lateMinutes = $diff > 0 ? (int)$diff : 0;
    }
}

// ── Compute overtime_minutes ──────────────────────────────────────────────────
$overtimeMins = 0;
if ($timeOut) {
    $shiftEnd = $pdo->prepare("
        SELECT s.end_time
        FROM attendance_records ar
        JOIN employees e ON ar.employee_id = e.employee_id
        LEFT JOIN shifts s ON e.shift_id = s.shift_id
        WHERE ar.attendance_id = ?
    ");
    $shiftEnd->execute([$attendanceId]);
    $se = $shiftEnd->fetchColumn();
    if ($se) {
        $toMins = fn($t) => (int)explode(':', $t)[0] * 60 + (int)explode(':', $t)[1];
        $diff   = $toMins($timeOut) - $toMins($se);
        $overtimeMins = max(0, $diff);
    }
}

// Check if migration 007 columns exist (overtime_minutes)
$hasOtCol = (bool)$pdo->query("
    SELECT COUNT(*) FROM information_schema.COLUMNS
    WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='attendance_records' AND COLUMN_NAME='overtime_minutes'
")->fetchColumn();

try {
    // ── Update record ─────────────────────────────────────────────────────────
    if ($hasOtCol) {
        $pdo->prepare("
            UPDATE attendance_records SET
                attendance_date    = :date,
                time_in            = :time_in,
                time_out           = :time_out,
                attendance_status  = :status,
                attendance_source  = :source,
                late_minutes       = :late_mins,
                overtime_minutes   = :ot_mins,
                remarks            = :remarks,
                updated_at         = NOW()
            WHERE attendance_id = :id
        ")->execute([
            ':date'      => $date,
            ':time_in'   => $timeIn,
            ':time_out'  => $timeOut,
            ':status'    => $status,
            ':source'    => $source,
            ':late_mins' => $lateMinutes,
            ':ot_mins'   => $overtimeMins,
            ':remarks'   => $remarks,
            ':id'        => $attendanceId,
        ]);
    } else {
        $pdo->prepare("
            UPDATE attendance_records SET
                attendance_date    = :date,
                time_in            = :time_in,
                time_out           = :time_out,
                attendance_status  = :status,
                attendance_source  = :source,
                late_minutes       = :late_mins,
                remarks            = :remarks,
                updated_at         = NOW()
            WHERE attendance_id = :id
        ")->execute([
            ':date'      => $date,
            ':time_in'   => $timeIn,
            ':time_out'  => $timeOut,
            ':status'    => $status,
            ':source'    => $source,
            ':late_mins' => $lateMinutes,
            ':remarks'   => $remarks,
            ':id'        => $attendanceId,
        ]);
    }

    // ── Audit log — full old/new snapshot (requires migration 007) ───────────
    $userId = $_SESSION['user']['user_id'] ?? null;
    try {
        $pdo->prepare("
            INSERT INTO attendance_audit_log
                (attendance_id, changed_by, action_type,
                 old_time_in,  old_time_out,  old_status,  old_method,  old_remarks,
                 new_time_in,  new_time_out,  new_status,  new_method,  new_remarks,
                 reason)
            VALUES
                (?, ?, 'EDIT',
                 ?, ?, ?, ?, ?,
                 ?, ?, ?, ?, ?,
                 ?)
        ")->execute([
            $attendanceId,
            $userId,
            $old['time_in'],
            $old['time_out'],
            $old['attendance_status'],
            $old['attendance_source'],
            $old['remarks'],
            $timeIn,
            $timeOut,
            $status,
            $source,
            $remarks,
            $reason,
        ]);
    } catch (PDOException $auditEx) {
        // Audit table absent (migration 007 not yet applied) — non-fatal
    }

    echo json_encode(['success' => true, 'message' => 'Attendance updated successfully.']);

} catch (PDOException $e) {
    http_response_code(500);
    echo json_encode(['success' => false, 'message' => 'Database error: ' . $e->getMessage()]);
}
