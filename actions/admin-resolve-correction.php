<?php
/**
 * actions/admin-resolve-correction.php
 * Admin updates the status of an attendance correction request.
 */
require_once __DIR__ . '/../config/config.php';
require_once __DIR__ . '/../includes/auth.php';
requireAdminAction();

header('Content-Type: application/json');

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['success' => false, 'message' => 'Method not allowed.']);
    exit;
}

$corrId   = (int)($_POST['correction_id'] ?? 0);
$status   = trim($_POST['status']         ?? '');
$adminNote= trim($_POST['admin_note']     ?? '');

$validStatuses = ['REVIEWED', 'RESOLVED', 'DISMISSED'];
if (!$corrId || !in_array($status, $validStatuses, true)) {
    echo json_encode(['success' => false, 'message' => 'Invalid request.']);
    exit;
}

// Table must exist
$hasTable = (bool)$pdo->query("
    SELECT COUNT(*) FROM information_schema.TABLES
    WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'attendance_corrections'
")->fetchColumn();
if (!$hasTable) {
    echo json_encode(['success' => false, 'message' => 'Corrections table not found.']);
    exit;
}

try {
    $stmt = $pdo->prepare("
        UPDATE attendance_corrections
        SET status = ?, admin_note = ?, updated_at = NOW()
        WHERE correction_id = ?
    ");
    $stmt->execute([$status, $adminNote ?: null, $corrId]);

    if ($stmt->rowCount() === 0) {
        echo json_encode(['success' => false, 'message' => 'Correction request not found.']);
        exit;
    }

    $labels = ['REVIEWED' => 'Reviewed', 'RESOLVED' => 'Resolved', 'DISMISSED' => 'Dismissed'];
    echo json_encode(['success' => true, 'message' => 'Request marked as ' . $labels[$status] . '.']);
} catch (PDOException $e) {
    echo json_encode(['success' => false, 'message' => 'Database error. Please try again.']);
}
exit;
