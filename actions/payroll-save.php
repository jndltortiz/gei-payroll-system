<?php
/**
 * actions/payroll-save.php
 * Submits an OPEN payroll period for Principal approval (OPEN → PROCESSING).
 * Logs a SUBMITTED event to payroll_workflow_log.
 */
require_once __DIR__ . '/../config/config.php';
require_once __DIR__ . '/../includes/auth.php';
header('Content-Type: application/json');
requireAdminAction();

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    echo json_encode(['success' => false, 'message' => 'Invalid request method.']); exit;
}

$periodId = (int)($_POST['period_id'] ?? 0);
$uid      = $_SESSION['user']['user_id'] ?? null;

if (!$periodId) {
    echo json_encode(['success' => false, 'message' => 'No period specified.']); exit;
}

$stmt = $pdo->prepare("SELECT * FROM payroll_periods WHERE period_id = ?");
$stmt->execute([$periodId]);
$period = $stmt->fetch();

if (!$period) {
    echo json_encode(['success' => false, 'message' => 'Payroll period not found.']); exit;
}
if ($period['status'] !== 'OPEN') {
    echo json_encode(['success' => false,
        'message' => 'Only OPEN periods can be submitted. Current status: ' . $period['status'] . '.']); exit;
}

// Snapshot totals for the workflow log
$snap = $pdo->prepare("
    SELECT COUNT(*) AS emp_count, SUM(gross_pay) AS gross, SUM(net_pay) AS net
    FROM payroll_records WHERE period_id = ?
");
$snap->execute([$periodId]);
$snapshot = $snap->fetch();

if ((int)$snapshot['emp_count'] === 0) {
    echo json_encode(['success' => false,
        'message' => 'No payroll records found. Please generate payroll first.']); exit;
}

// Performer display name
$nameRow = $pdo->prepare("
    SELECT CONCAT(e.first_name,' ',e.last_name) AS full_name
    FROM users u LEFT JOIN employees e ON u.employee_id = e.employee_id
    WHERE u.user_id = ?
");
$nameRow->execute([$uid]);
$performerName = $nameRow->fetchColumn() ?: 'Admin';

try {
    $pdo->prepare("UPDATE payroll_periods SET status='PROCESSING', updated_at=NOW() WHERE period_id=?")
        ->execute([$periodId]);

    // Write structured workflow event
    $pdo->prepare("
        INSERT INTO payroll_workflow_log
            (period_id, event_type, performed_by, performer_name, gross_total, net_total, emp_count)
        VALUES (?, 'SUBMITTED', ?, ?, ?, ?, ?)
    ")->execute([$periodId, $uid, $performerName,
                 $snapshot['gross'], $snapshot['net'], $snapshot['emp_count']]);

    // Keep audit_logs in sync
    if ($uid) $pdo->prepare("
        INSERT INTO audit_logs(user_id,action,table_name,record_id,description) VALUES(?,?,?,?,?)
    ")->execute([$uid,'SUBMIT','payroll_periods',$periodId,
        "Submitted \"{$period['period_name']}\" for principal approval ({$snapshot['emp_count']} records)"]);

    echo json_encode([
        'success'      => true,
        'message'      => "\"{$period['period_name']}\" submitted to the Principal for approval.",
        'record_count' => (int)$snapshot['emp_count'],
    ]);
} catch (PDOException $e) {
    echo json_encode(['success' => false, 'message' => 'Database error: ' . $e->getMessage()]);
}