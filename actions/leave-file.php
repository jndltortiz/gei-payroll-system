<?php
/**
 * actions/leave-file.php
 * Handles submitting a new leave request.
 * Each selected date gets its own row in leave_request_dates (status = PENDING).
 *
 * POST: leave_type_id, dates[] (YYYY-MM-DD), reason
 */

require_once __DIR__ . '/../config/config.php';
require_once __DIR__ . '/../includes/auth.php';

header('Content-Type: application/json');

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    echo json_encode(['success' => false, 'message' => 'Invalid request method.']);
    exit;
}

requireLogin();

$employeeId  = $_SESSION['user']['employee_id'] ?? null;
$userId      = $_SESSION['user']['user_id'] ?? null;
$leaveTypeId = (int)($_POST['leave_type_id'] ?? 0);
$reason      = trim($_POST['reason'] ?? '');
$dates       = $_POST['dates'] ?? [];

// Validation
if (!$employeeId) {
    echo json_encode(['success' => false, 'message' => 'No employee linked to this account.']);
    exit;
}
if (!$leaveTypeId) {
    echo json_encode(['success' => false, 'message' => 'Please select a leave type.']);
    exit;
}
if (empty($dates)) {
    echo json_encode(['success' => false, 'message' => 'Please select at least one date.']);
    exit;
}
if (!$reason) {
    echo json_encode(['success' => false, 'message' => 'Please enter a reason.']);
    exit;
}

// Sanitise & sort dates
$cleanDates = [];
foreach ($dates as $d) {
    if (preg_match('/^\d{4}-\d{2}-\d{2}$/', $d)) {
        $cleanDates[] = $d;
    }
}
$cleanDates = array_unique($cleanDates);
sort($cleanDates);

if (empty($cleanDates)) {
    echo json_encode(['success' => false, 'message' => 'No valid dates provided.']);
    exit;
}

$startDate = $cleanDates[0];
$endDate   = end($cleanDates);
$totalDays = count($cleanDates);

try {
    $pdo->beginTransaction();

    // 1. Parent leave_request
    $stmtLR = $pdo->prepare("
        INSERT INTO leave_requests
            (employee_id, leave_type_id, reason, start_date, end_date,
             total_days, status, created_at, updated_at)
        VALUES (?, ?, ?, ?, ?, ?, 'PENDING', NOW(), NOW())
    ");
    $stmtLR->execute([$employeeId, $leaveTypeId, $reason, $startDate, $endDate, $totalDays]);
    $leaveId = (int)$pdo->lastInsertId();

    // 2. One row per date in leave_request_dates
    $stmtDate = $pdo->prepare("
        INSERT INTO leave_request_dates (leave_id, leave_date, status, created_at)
        VALUES (?, ?, 'PENDING', NOW())
    ");
    foreach ($cleanDates as $date) {
        $stmtDate->execute([$leaveId, $date]);
    }

    // 3. Audit log
    $pdo->prepare("
        INSERT INTO audit_logs (user_id, action, table_name, record_id, description, created_at)
        VALUES (?, 'CREATE', 'leave_requests', ?, ?, NOW())
    ")->execute([
        $userId,
        $leaveId,
        "Filed leave request #{$leaveId} for {$totalDays} date(s) starting {$startDate}"
    ]);

    $pdo->commit();

    echo json_encode([
        'success'  => true,
        'message'  => "Leave request submitted for {$totalDays} date(s)!",
        'leave_id' => $leaveId,
    ]);

} catch (PDOException $e) {
    $pdo->rollBack();
    echo json_encode(['success' => false, 'message' => 'Database error: ' . $e->getMessage()]);
}