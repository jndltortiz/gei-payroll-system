<?php
/**
 * actions/add-attendance.php
 * Inserts a manual attendance record.
 * Returns JSON { success: true } or { success: false, message: '...' }
 */
header('Content-Type: application/json');
require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../includes/auth.php';
requireLogin();

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    echo json_encode(['success' => false, 'message' => 'Invalid request method.']);
    exit;
}

$employeeId = (int)($_POST['employee_id'] ?? 0);
$date       = trim($_POST['date']       ?? '');
$timeIn     = trim($_POST['time_in']    ?? '') ?: null;
$timeOut    = trim($_POST['time_out']   ?? '') ?: null;
$status     = trim($_POST['status']     ?? 'PRESENT');
$remarks    = trim($_POST['remarks']    ?? '') ?: null;

// Basic validation
if (!$employeeId || !$date || !$status) {
    echo json_encode(['success' => false, 'message' => 'Employee, date, and status are required.']);
    exit;
}

// ── Holiday check — takes priority over all other status logic ────────────────
// If the date is recorded in the holidays table, force HOLIDAY status.
$holCheck = $pdo->prepare("SELECT holiday_name FROM holidays WHERE holiday_date = ?");
$holCheck->execute([$date]);
if ($holRow = $holCheck->fetch()) {
    $status  = 'HOLIDAY';
    $remarks = $remarks ?? $holRow['holiday_name'];
}

// ── Server-side status auto-compute (mirrors front-end logic) ─────────────────
// If time_in is provided, recompute status so it is always consistent
// even if the user somehow bypassed the JS auto-suggest.
// Skipped when the date is a holiday (status already forced above).
if ($timeIn && $status !== 'HOLIDAY') {
    $empStmt = $pdo->prepare("
        SELECT e.employment_type,
               s.start_time, s.grace_period_minutes, s.half_day_time
        FROM employees e
        LEFT JOIN shifts s ON e.shift_id = s.shift_id
        WHERE e.employee_id = ?
    ");
    $empStmt->execute([$employeeId]);
    $emp = $empStmt->fetch(PDO::FETCH_ASSOC);

    if ($emp && $emp['start_time']) {
        $toMins = fn($t) => (int)explode(':', $t)[0] * 60 + (int)explode(':', $t)[1];

        $shiftStart  = $toMins($emp['start_time']);
        $timeInMins  = $toMins($timeIn);
        $isPartTime  = ($emp['employment_type'] === 'PART_TIME');

        if ($isPartTime) {
            // Part-time: only PRESENT / LATE
            $status = $timeInMins <= $shiftStart ? 'PRESENT' : 'LATE';
        } else {
            // Full-time: PRESENT / LATE / HALF_DAY
            $halfDayMins = $emp['half_day_time']
                ? $toMins($emp['half_day_time'])
                : 9 * 60; // default 9:00 AM

            if ($timeInMins <= $shiftStart) {
                $status = 'PRESENT';
            } elseif ($timeInMins < $halfDayMins) {
                $status = 'LATE';
            } else {
                $status = 'HALF_DAY';
            }
        }
    }
}

// Validate status is an allowed enum value
$allowedStatuses = ['PRESENT', 'ABSENT', 'LATE', 'HALF_DAY', 'INCOMPLETE', 'LEAVE', 'HOLIDAY'];
if (!in_array($status, $allowedStatuses, true)) {
    echo json_encode(['success' => false, 'message' => 'Invalid attendance status.']);
    exit;
}

try {
    // Check for duplicate (same employee + same date)
    $check = $pdo->prepare("
        SELECT attendance_id FROM attendance_records
        WHERE employee_id = ? AND attendance_date = ?
    ");
    $check->execute([$employeeId, $date]);
    if ($check->fetch()) {
        echo json_encode([
            'success' => false,
            'message' => 'An attendance record for this employee on this date already exists.'
        ]);
        exit;
    }

    $stmt = $pdo->prepare("
        INSERT INTO attendance_records
            (employee_id, attendance_date, time_in, time_out,
             attendance_status, attendance_source, remarks)
        VALUES (?, ?, ?, ?, ?, 'MANUAL', ?)
    ");
    $stmt->execute([$employeeId, $date, $timeIn, $timeOut, $status, $remarks]);

    echo json_encode(['success' => true]);

} catch (PDOException $e) {
    // Duplicate key from DB constraint
    if ($e->getCode() === '23000') {
        echo json_encode([
            'success' => false,
            'message' => 'An attendance record for this employee on this date already exists.'
        ]);
    } else {
        http_response_code(500);
        echo json_encode(['success' => false, 'message' => 'Database error: ' . $e->getMessage()]);
    }
}