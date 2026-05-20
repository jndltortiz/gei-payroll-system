<?php
/**
 * actions/payroll-approve.php
 * Handles payroll period approval workflow:
 *   action=approve  → PROCESSING → APPROVED  (Principal approves)
 *   action=return   → PROCESSING → OPEN      (Return for revision)
 *   action=release  → APPROVED   → RELEASED  (Accounting releases)
 */
require_once __DIR__ . '/../config/config.php';
require_once __DIR__ . '/../includes/auth.php';
header('Content-Type: application/json');
requireLogin();

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    echo json_encode(['success' => false, 'message' => 'Invalid request.']); exit;
}

$action   = $_POST['action']    ?? '';
$periodId = (int)($_POST['period_id'] ?? 0);
$notes    = trim($_POST['notes'] ?? '');
$uid      = $_SESSION['user']['user_id'] ?? null;

if (!$periodId) {
    echo json_encode(['success' => false, 'message' => 'No period specified.']); exit;
}

$period = $pdo->prepare("SELECT * FROM payroll_periods WHERE period_id = ?");
$period->execute([$periodId]);
$period = $period->fetch();
if (!$period) {
    echo json_encode(['success' => false, 'message' => 'Period not found.']); exit;
}

try {
    switch ($action) {

        case 'approve':
            if ($period['status'] !== 'PROCESSING') {
                echo json_encode(['success' => false,
                    'message' => 'Only PROCESSING periods can be approved.']); exit;
            }
            // Approve: mark period APPROVED, mark all DRAFT records as APPROVED
            $pdo->prepare("UPDATE payroll_periods SET status='APPROVED', updated_at=NOW() WHERE period_id=?")
                ->execute([$periodId]);
            $pdo->prepare("UPDATE payroll_records SET payroll_status='APPROVED', approved_by=?, approved_at=NOW() WHERE period_id=? AND payroll_status='DRAFT'")
                ->execute([$uid, $periodId]);
            if ($uid) $pdo->prepare("INSERT INTO audit_logs(user_id,action,table_name,record_id,description) VALUES(?,?,?,?,?)")
                ->execute([$uid,'APPROVE','payroll_periods',$periodId,"Approved payroll period #{$periodId} — {$period['period_name']}" . ($notes ? " | Notes: $notes" : '')]);
            echo json_encode(['success' => true,
                'message' => "\"{$period['period_name']}\" approved. Accounting can now release payroll."]);
            break;

        case 'return':
            if ($period['status'] !== 'PROCESSING') {
                echo json_encode(['success' => false,
                    'message' => 'Only PROCESSING periods can be returned.']); exit;
            }
            // Return for revision: back to OPEN, records back to DRAFT
            $pdo->prepare("UPDATE payroll_periods SET status='OPEN', updated_at=NOW() WHERE period_id=?")
                ->execute([$periodId]);
            if ($uid) $pdo->prepare("INSERT INTO audit_logs(user_id,action,table_name,record_id,description) VALUES(?,?,?,?,?)")
                ->execute([$uid,'RETURN','payroll_periods',$periodId,"Returned period #{$periodId} for revision" . ($notes ? " | Reason: $notes" : '')]);
            echo json_encode(['success' => true,
                'message' => "\"{$period['period_name']}\" returned for revision." . ($notes ? " Reason: $notes" : '')]);
            break;

        case 'release':
            if ($period['status'] !== 'APPROVED') {
                echo json_encode(['success' => false,
                    'message' => 'Only APPROVED periods can be released.']); exit;
            }
            $pdo->prepare("UPDATE payroll_periods SET status='RELEASED', updated_at=NOW() WHERE period_id=?")
                ->execute([$periodId]);
            $pdo->prepare("UPDATE payroll_records SET payroll_status='RELEASED', released_by=?, released_at=NOW() WHERE period_id=? AND payroll_status='APPROVED'")
                ->execute([$uid, $periodId]);
            if ($uid) $pdo->prepare("INSERT INTO audit_logs(user_id,action,table_name,record_id,description) VALUES(?,?,?,?,?)")
                ->execute([$uid,'RELEASE','payroll_periods',$periodId,"Released payroll period #{$periodId} — {$period['period_name']}"]);
            echo json_encode(['success' => true,
                'message' => "\"{$period['period_name']}\" released. Payslips can now be printed."]);
            break;

        default:
            echo json_encode(['success' => false, 'message' => 'Unknown action.']);
    }
} catch (PDOException $e) {
    echo json_encode(['success' => false, 'message' => 'DB error: ' . $e->getMessage()]);
}