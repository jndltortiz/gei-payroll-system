<?php
/**
 * actions/download-dtr.php
 * Securely serves a DTR attachment file.
 * Admin and Principal roles only; employees cannot access.
 *
 * GET params:
 *   id — attachment_id (required)
 */
require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../includes/auth.php';
requireLogin();

// Employees cannot access DTR files
$role = $_SESSION['user']['role'] ?? '';
if ($role === 'Employee') {
    http_response_code(403);
    exit('Access denied.');
}

$id = (int)($_GET['id'] ?? 0);
if (!$id) {
    http_response_code(400);
    exit('Invalid attachment ID.');
}

// Fetch record
$stmt = $pdo->prepare("
    SELECT file_name, file_path, file_type
    FROM dtr_attachments
    WHERE attachment_id = ?
");
$stmt->execute([$id]);
$row = $stmt->fetch(PDO::FETCH_ASSOC);

if (!$row) {
    http_response_code(404);
    exit('Attachment not found.');
}

// Resolve absolute path from project root
$projectRoot = realpath(__DIR__ . '/..');
$absPath     = $projectRoot . DIRECTORY_SEPARATOR . str_replace('/', DIRECTORY_SEPARATOR, $row['file_path']);

if (!$absPath || !is_file($absPath)) {
    http_response_code(404);
    exit('File not found on server.');
}

// Validate the resolved path stays within uploads/dtr/
$uploadsRoot = realpath($projectRoot . '/uploads/dtr');
if (!$uploadsRoot || strpos(realpath($absPath), $uploadsRoot) !== 0) {
    http_response_code(403);
    exit('Access denied.');
}

// MIME type map
$ext = strtolower(pathinfo($absPath, PATHINFO_EXTENSION));
$mimeMap = [
    'xls'  => 'application/vnd.ms-excel',
    'xlsx' => 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
    'pdf'  => 'application/pdf',
    'jpg'  => 'image/jpeg',
    'jpeg' => 'image/jpeg',
    'png'  => 'image/png',
];
$contentType = $mimeMap[$ext] ?? 'application/octet-stream';

// Inline preview for PDF and images; force download for spreadsheets
$disposition = in_array($ext, ['pdf', 'jpg', 'jpeg', 'png'], true) ? 'inline' : 'attachment';
$safeFileName = rawurlencode($row['file_name']);

header('Content-Type: '        . $contentType);
header('Content-Length: '      . filesize($absPath));
header('Content-Disposition: ' . $disposition . '; filename="' . $row['file_name'] . '"; filename*=UTF-8\'\'' . $safeFileName);
header('Cache-Control: private, no-cache');
readfile($absPath);
exit;
