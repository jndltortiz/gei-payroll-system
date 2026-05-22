<?php
/**
 * actions/auto-absent.php
 * Auto-tags employees as ABSENT (or ON_LEAVE if approved leave exists)
 * for a given date, only when no attendance record has been logged yet.
 *
 * IMPORTANT: This does NOT automatically deduct payroll.
 * Deductions only happen after leave review and approval per GEI policy.
 *
 * POST params:
 *   date  — target date (Y-m-d), defaults to today
 *
 * Returns JSON:
 *   { success, tagged, skipped, on_leave, message }
 */
header('Content-Type: application/json');
require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../includes/auth.php';
requireLogin();

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    echo json_encode(['success' => false, 'message' => 'Invalid request method.']);
    exit;
}

$date = trim($_POST['date'] ?? date('Y-m-d'));

if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $date)) {
    echo json_encode(['success' => false, 'message' => 'Invalid date format.']);
    exit;
}
if ($date > date('Y-m-d')) {
    echo json_encode(['success' => false, 'message' => 'Cannot auto-tag absent for a future date.']);
    exit;
}

// Holiday check — if it's a holiday, skip auto-absent entirely
$isHoliday = $pdo->prepare("SELECT COUNT(*) FROM holidays WHERE holiday_date = ?");
$isHoliday->execute([$date]);
if ($isHoliday->fetchColumn() > 0) {
    echo json_encode([
        'success' => false,
        'message' => "Date {$date} is a holiday. Auto-absent skipped.",
    ]);
    exit;
}

$userId  = $_SESSION['user']['user_id'] ?? null;
$tagged  = 0;
$onLeave = 0;
$skipped = 0;

// All active employees
$employees = $pdo->query(
    "SELECT employee_id FROM employees WHERE employee_status = 'ACTIVE'"
)->fetchAll(PDO::FETCH_COLUMN);

foreach ($employees as $empId) {
    // Skip if attendance record already exists
    $exists = $pdo->prepare(
        "SELECT attendance_id FROM attendance_records WHERE employee_id = ? AND attendance_date = ?"
    );
    $exists->execute([$empId, $date]);
    if ($exists->fetchColumn()) { $skipped++; continue; }

    // Check for approved leave covering this date.
    // Tries leave_request_dates (precise per-day table) first; falls back to
    // leave_requests.start_date / end_date range for older schema versions.
    $hasLeave = false;
    try {
        $leaveCheck = $pdo->prepare("
            SELECT lr.request_id
            FROM leave_requests lr
            JOIN leave_request_dates lrd ON lr.request_id = lrd.request_id
            WHERE lr.employee_id = ?
              AND lrd.leave_date  = ?
              AND lr.status       = 'APPROVED'
            LIMIT 1
        ");
        $leaveCheck->execute([$empId, $date]);
        $hasLeave = (bool)$leaveCheck->fetchColumn();
    } catch (PDOException $e) {
        // leave_request_dates table absent — fall back to date range
        try {
            $lb = $pdo->prepare("
                SELECT request_id FROM leave_requests
                WHERE employee_id = ? AND ? BETWEEN start_date AND end_date AND status = 'APPROVED'
                LIMIT 1
            ");
            $lb->execute([$empId, $date]);
            $hasLeave = (bool)$lb->fetchColumn();
        } catch (PDOException $e2) {
            $hasLeave = false;
        }
    }

    $status  = $hasLeave ? 'LEAVE'  : 'ABSENT';
    $remarks = $hasLeave
        ? 'Auto-tagged: approved leave'
        : 'Auto-tagged: no attendance logged';

    try {
        $pdo->prepare("
            INSERT INTO attendance_records
                (employee_id, attendance_date, attendance_status, attendance_source, remarks)
            VALUES (?, ?, ?, 'AUTO', ?)
        ")->execute([$empId, $date, $status, $remarks]);

        if ($hasLeave) $onLeave++; else $tagged++;
    } catch (PDOException $e) {
        // Duplicate key — already handled above, but just in case
        $skipped++;
    }
}

echo json_encode([
    'success'  => true,
    'tagged'   => $tagged,
    'on_leave' => $onLeave,
    'skipped'  => $skipped,
    'message'  => "Auto-tagged for {$date}: {$tagged} absent, {$onLeave} on leave, {$skipped} skipped (already recorded).",
]);
