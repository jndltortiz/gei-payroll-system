<?php
/**
 * actions/verify-attendance.php
 * Marks one or multiple attendance records as verified.
 * POST: action (verify_one|verify_period|unverify)
 */
require_once __DIR__ . '/../config/config.php';
require_once __DIR__ . '/../includes/auth.php';
header('Content-Type: application/json');
requireLogin();

$action  = $_POST['action'] ?? '';
$uid     = $_SESSION['user']['user_id'] ?? null;

if ($action === 'verify_one') {
    $id = (int)($_POST['attendance_id'] ?? 0);
    if (!$id) { echo json_encode(['success'=>false,'message'=>'Invalid ID.']); exit; }
    $pdo->prepare("
        UPDATE attendance_records
        SET verification_status='VERIFIED', verified_by=?, verified_at=NOW()
        WHERE attendance_id=?
    ")->execute([$uid, $id]);
    echo json_encode(['success'=>true,'message'=>'Record verified.']);

} elseif ($action === 'unverify') {
    $id = (int)($_POST['attendance_id'] ?? 0);
    if (!$id) { echo json_encode(['success'=>false,'message'=>'Invalid ID.']); exit; }
    $pdo->prepare("
        UPDATE attendance_records
        SET verification_status='PENDING', verified_by=NULL, verified_at=NULL
        WHERE attendance_id=?
    ")->execute([$id]);
    echo json_encode(['success'=>true,'message'=>'Verification removed.']);

} elseif ($action === 'verify_period') {
    // Bulk verify all records in a date range
    $start = trim($_POST['start_date'] ?? '');
    $end   = trim($_POST['end_date']   ?? '');
    if (!$start || !$end) { echo json_encode(['success'=>false,'message'=>'Date range required.']); exit; }
    $stmt = $pdo->prepare("
        UPDATE attendance_records
        SET verification_status='VERIFIED', verified_by=?, verified_at=NOW()
        WHERE attendance_date BETWEEN ? AND ?
          AND verification_status='PENDING'
    ");
    $stmt->execute([$uid, $start, $end]);
    $count = $stmt->rowCount();

    if ($uid) $pdo->prepare("INSERT INTO audit_logs(user_id,action,table_name,record_id,description) VALUES(?,?,?,?,?)")
        ->execute([$uid,'VERIFY','attendance_records',0,"Bulk verified {$count} records: {$start} to {$end}"]);

    echo json_encode(['success'=>true,'message'=>"{$count} record(s) verified for the period."]);

} else {
    echo json_encode(['success'=>false,'message'=>'Unknown action.']);
}