<?php
/**
 * actions/upload-dtr.php
 * Admin: Upload a DTR backup file (Excel / PDF / Image) for audit history.
 * Does NOT modify any attendance_records rows — purely supplementary documentation.
 *
 * POST params (multipart/form-data):
 *   date_from     — Y-m-d (required) — start of the period this file covers
 *   date_to       — Y-m-d (optional) — end of period; defaults to date_from
 *   department_id — int  (optional)
 *   employee_id   — int  (optional)  — set only when file covers a single employee
 *   notes         — text (optional)
 *   dtr_file      — uploaded file    (required)
 *
 * Accepted file types: .xls .xlsx .pdf .jpg .jpeg .png
 * Max size: 10 MB
 *
 * Returns JSON { success, message, attachment_id? }
 */

header('Content-Type: application/json');
require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../includes/auth.php';
requireLogin();

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    echo json_encode(['success' => false, 'message' => 'Invalid request method.']);
    exit;
}

// ── Check migration 007 (dtr_attachments table) ───────────────────────────────
$hasDtrTable = (bool)$pdo->query("
    SELECT COUNT(*) FROM information_schema.TABLES
    WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'dtr_attachments'
")->fetchColumn();

if (!$hasDtrTable) {
    echo json_encode(['success' => false, 'message' => 'DTR upload requires database migration 007. Contact the administrator.']);
    exit;
}

// ── Input validation ──────────────────────────────────────────────────────────
$dateFrom    = trim($_POST['date_from']    ?? '');
$dateTo      = trim($_POST['date_to']      ?? '') ?: $dateFrom;
$departmentId = (int)($_POST['department_id'] ?? 0) ?: null;
$employeeId   = (int)($_POST['employee_id']   ?? 0) ?: null;
$notes        = trim($_POST['notes']          ?? '') ?: null;
$userId       = $_SESSION['user']['user_id']  ?? null;

if (!$dateFrom || !preg_match('/^\d{4}-\d{2}-\d{2}$/', $dateFrom)) {
    echo json_encode(['success' => false, 'message' => 'A valid start date is required.']);
    exit;
}
if (!$dateTo || !preg_match('/^\d{4}-\d{2}-\d{2}$/', $dateTo)) {
    $dateTo = $dateFrom;
}
if ($dateTo < $dateFrom) {
    echo json_encode(['success' => false, 'message' => 'End date cannot be before start date.']);
    exit;
}

// ── File validation ───────────────────────────────────────────────────────────
if (empty($_FILES['dtr_file']) || $_FILES['dtr_file']['error'] !== UPLOAD_ERR_OK) {
    $errMsg = match($_FILES['dtr_file']['error'] ?? UPLOAD_ERR_NO_FILE) {
        UPLOAD_ERR_NO_FILE  => 'No file was uploaded.',
        UPLOAD_ERR_INI_SIZE,
        UPLOAD_ERR_FORM_SIZE => 'File is too large.',
        UPLOAD_ERR_PARTIAL  => 'File was only partially uploaded.',
        default             => 'File upload failed.',
    };
    echo json_encode(['success' => false, 'message' => $errMsg]);
    exit;
}

$maxBytes = 10 * 1024 * 1024; // 10 MB
if ($_FILES['dtr_file']['size'] > $maxBytes) {
    echo json_encode(['success' => false, 'message' => 'File exceeds 10 MB limit.']);
    exit;
}

$originalName = $_FILES['dtr_file']['name'];
$ext          = strtolower(pathinfo($originalName, PATHINFO_EXTENSION));

$allowedExts  = ['xls', 'xlsx', 'pdf', 'jpg', 'jpeg', 'png'];
$allowedMimes = [
    'application/vnd.ms-excel',
    'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
    'application/pdf',
    'image/jpeg',
    'image/png',
];

if (!in_array($ext, $allowedExts, true)) {
    echo json_encode(['success' => false, 'message' => 'File type not allowed. Accepted: Excel (.xls, .xlsx), PDF, or Image (.jpg, .png).']);
    exit;
}

$finfo    = new finfo(FILEINFO_MIME_TYPE);
$mimeType = $finfo->file($_FILES['dtr_file']['tmp_name']);
if (!in_array($mimeType, $allowedMimes, true)) {
    echo json_encode(['success' => false, 'message' => 'File content type is not allowed.']);
    exit;
}

// ── Determine file_type enum ──────────────────────────────────────────────────
$fileType = match(true) {
    in_array($ext, ['xls', 'xlsx'], true) => 'EXCEL',
    $ext === 'pdf'                        => 'PDF',
    in_array($ext, ['jpg','jpeg','png'], true) => 'IMAGE',
    default                               => 'OTHER',
};

// ── Build upload path ─────────────────────────────────────────────────────────
$uploadRoot = __DIR__ . '/../uploads/dtr/';
$subDir     = date('Y-m', strtotime($dateFrom)) . '/';
$uploadDir  = $uploadRoot . $subDir;

if (!is_dir($uploadDir)) {
    mkdir($uploadDir, 0755, true);
}

$safeName = preg_replace('/[^a-z0-9_\-]/', '_', strtolower(pathinfo($originalName, PATHINFO_FILENAME)));
$safeName = substr($safeName, 0, 60);
$fileName = 'dtr_' . date('Ymd') . '_' . uniqid() . '_' . $safeName . '.' . $ext;
$filePath = 'uploads/dtr/' . $subDir . $fileName;      // Relative to project root
$absPath  = $uploadDir . $fileName;

if (!move_uploaded_file($_FILES['dtr_file']['tmp_name'], $absPath)) {
    echo json_encode(['success' => false, 'message' => 'Failed to save uploaded file. Check server permissions.']);
    exit;
}

// ── Check if department_id column exists (migration 008) ─────────────────────
$hasDeptCol = (bool)$pdo->query("
    SELECT COUNT(*) FROM information_schema.COLUMNS
    WHERE TABLE_SCHEMA = DATABASE()
      AND TABLE_NAME   = 'dtr_attachments'
      AND COLUMN_NAME  = 'department_id'
")->fetchColumn();

// ── Insert DB record ──────────────────────────────────────────────────────────
try {
    if ($hasDeptCol) {
        $pdo->prepare("
            INSERT INTO dtr_attachments
                (cutoff_start, cutoff_end, employee_id, department_id,
                 file_name, file_path, file_type, uploaded_by, notes)
            VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)
        ")->execute([
            $dateFrom, $dateTo, $employeeId, $departmentId,
            $originalName, $filePath, $fileType, $userId, $notes,
        ]);
    } else {
        $pdo->prepare("
            INSERT INTO dtr_attachments
                (cutoff_start, cutoff_end, employee_id,
                 file_name, file_path, file_type, uploaded_by, notes)
            VALUES (?, ?, ?, ?, ?, ?, ?, ?)
        ")->execute([
            $dateFrom, $dateTo, $employeeId,
            $originalName, $filePath, $fileType, $userId, $notes,
        ]);
    }

    $attachmentId = (int)$pdo->lastInsertId();

    echo json_encode([
        'success'       => true,
        'message'       => 'DTR file uploaded successfully.',
        'attachment_id' => $attachmentId,
        'file_name'     => $originalName,
        'file_type'     => $fileType,
    ]);

} catch (PDOException $e) {
    // Roll back file if DB insert fails
    @unlink($absPath);
    http_response_code(500);
    echo json_encode(['success' => false, 'message' => 'Database error: ' . $e->getMessage()]);
}
