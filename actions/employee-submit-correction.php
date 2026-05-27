<?php
/**
 * actions/employee-submit-correction.php
 * Employee submits an attendance correction request.
 * Requires Employee role.
 */
require_once __DIR__ . '/../config/config.php';
require_once __DIR__ . '/../includes/auth.php';
requireEmployeeAjax();

header('Content-Type: application/json');

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['success' => false, 'message' => 'Method not allowed.']);
    exit;
}

$empId = (int)($_SESSION['user']['employee_id'] ?? 0);
if (!$empId) {
    echo json_encode(['success' => false, 'message' => 'Session error. Please log in again.']);
    exit;
}

// Check table exists (migration 009)
$hasTable = (bool)$pdo->query("
    SELECT COUNT(*) FROM information_schema.TABLES
    WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'attendance_corrections'
")->fetchColumn();
if (!$hasTable) {
    echo json_encode(['success' => false, 'message' => 'Correction system not yet set up. Contact admin.']);
    exit;
}

$attendanceId = (int)($_POST['attendance_id'] ?? 0) ?: null;
$date         = trim($_POST['attendance_date'] ?? '');
$issueType    = trim($_POST['issue_type'] ?? 'OTHER');
$explanation  = trim($_POST['explanation'] ?? '');

$validIssues = ['MISSING_TIME_IN', 'MISSING_TIME_OUT', 'WRONG_STATUS', 'LATE_INCORRECT', 'OTHER'];
if (!in_array($issueType, $validIssues, true)) $issueType = 'OTHER';

if (!$date || !preg_match('/^\d{4}-\d{2}-\d{2}$/', $date)) {
    echo json_encode(['success' => false, 'message' => 'A valid date is required.']);
    exit;
}
if (strlen($explanation) < 5) {
    echo json_encode(['success' => false, 'message' => 'Please provide a more detailed explanation (at least 5 characters).']);
    exit;
}

// If attendance_id given, verify it belongs to this employee
if ($attendanceId) {
    $chk = $pdo->prepare("SELECT employee_id FROM attendance_records WHERE attendance_id = ?");
    $chk->execute([$attendanceId]);
    $owner = (int)$chk->fetchColumn();
    if ($owner !== $empId) {
        echo json_encode(['success' => false, 'message' => 'Invalid attendance record.']);
        exit;
    }
}

// Handle optional file upload
$attachmentPath = null;
$attachmentName = null;
if (!empty($_FILES['attachment']['name'])) {
    $file    = $_FILES['attachment'];
    $allowed = ['jpg', 'jpeg', 'png', 'pdf'];
    $ext     = strtolower(pathinfo($file['name'], PATHINFO_EXTENSION));

    if (!in_array($ext, $allowed, true)) {
        echo json_encode(['success' => false, 'message' => 'Attachment must be JPG, PNG, or PDF.']);
        exit;
    }
    if ($file['size'] > 5 * 1024 * 1024) {
        echo json_encode(['success' => false, 'message' => 'Attachment must be under 5 MB.']);
        exit;
    }
    if ($file['error'] !== UPLOAD_ERR_OK) {
        echo json_encode(['success' => false, 'message' => 'File upload error. Please try again.']);
        exit;
    }

    $subDir = date('Y-m');
    $dir    = __DIR__ . '/../uploads/corrections/' . $subDir . '/';
    if (!is_dir($dir)) mkdir($dir, 0755, true);

    $saveName = 'corr_' . $empId . '_' . date('Ymd') . '_' . uniqid() . '.' . $ext;
    $dest     = $dir . $saveName;
    if (!move_uploaded_file($file['tmp_name'], $dest)) {
        echo json_encode(['success' => false, 'message' => 'Failed to save file. Please try again.']);
        exit;
    }
    $attachmentPath = 'uploads/corrections/' . $subDir . '/' . $saveName;
    $attachmentName = basename($file['name']);
}

try {
    $ins = $pdo->prepare("
        INSERT INTO attendance_corrections
            (attendance_id, employee_id, attendance_date, issue_type, explanation, attachment_path, attachment_name)
        VALUES (?, ?, ?, ?, ?, ?, ?)
    ");
    $ins->execute([$attendanceId, $empId, $date, $issueType, $explanation, $attachmentPath, $attachmentName]);
    echo json_encode(['success' => true, 'message' => 'Correction request submitted. Admin will review it shortly.']);
} catch (PDOException $e) {
    if ($attachmentPath) @unlink(__DIR__ . '/../' . $attachmentPath);
    echo json_encode(['success' => false, 'message' => 'Database error. Please try again.']);
}
exit;
