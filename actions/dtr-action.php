<?php
/**
 * actions/dtr-action.php
 * Manage DTR attachment records.
 *
 * POST params:
 *   action        = 'archive' | 'unarchive' | 'delete'
 *   attachment_id = int
 *
 * archive   — soft-archive: sets is_archived=1, audit-logged, file kept on disk.
 * unarchive — restore archived record: is_archived=0.
 * delete    — hard-delete: removes DB row + physical file, audit-logged.
 *             Only allowed on already-archived records to prevent accidental loss.
 */

require_once __DIR__ . '/../config/config.php';
require_once __DIR__ . '/../includes/auth.php';

header('Content-Type: application/json');

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    echo json_encode(['success' => false, 'message' => 'Invalid method.']);
    exit;
}

requireLogin();

$role = strtolower($_SESSION['user']['role'] ?? '');
if ($role === 'employee') {
    echo json_encode(['success' => false, 'message' => 'Not authorised.']);
    exit;
}

$action = trim($_POST['action'] ?? '');
$attId  = (int)($_POST['attachment_id'] ?? 0);
$userId = $_SESSION['user']['user_id'] ?? null;

if (!$attId) {
    echo json_encode(['success' => false, 'message' => 'Attachment ID required.']);
    exit;
}

// Migration 023 guard
$hasMig023 = (bool)$pdo->query("
    SELECT COUNT(*) FROM information_schema.COLUMNS
    WHERE TABLE_SCHEMA = DATABASE()
      AND TABLE_NAME   = 'dtr_attachments'
      AND COLUMN_NAME  = 'is_archived'
")->fetchColumn();

try {
    $row = $pdo->prepare("SELECT * FROM dtr_attachments WHERE attachment_id = ?");
    $row->execute([$attId]);
    $dtr = $row->fetch();

    if (!$dtr) {
        echo json_encode(['success' => false, 'message' => 'Attachment not found.']);
        exit;
    }

    if ($action === 'archive') {
        if ($hasMig023) {
            $pdo->prepare("
                UPDATE dtr_attachments
                SET is_archived = 1, archived_at = NOW(), archived_by = ?
                WHERE attachment_id = ?
            ")->execute([$userId, $attId]);
        }

        $pdo->prepare("
            INSERT INTO audit_logs (user_id, action, table_name, record_id, description, created_at)
            VALUES (?, 'ARCHIVE', 'dtr_attachments', ?, ?, NOW())
        ")->execute([
            $userId, $attId,
            "Archived DTR attachment #{$attId} ({$dtr['file_name']}) — file retained on disk",
        ]);

        echo json_encode(['success' => true, 'message' => 'DTR upload archived successfully.']);

    } elseif ($action === 'unarchive') {
        if (!$hasMig023) {
            echo json_encode(['success' => false, 'message' => 'Archive feature not available (migration 023 not applied).']);
            exit;
        }

        $pdo->prepare("
            UPDATE dtr_attachments
            SET is_archived = 0, archived_at = NULL, archived_by = NULL
            WHERE attachment_id = ?
        ")->execute([$attId]);

        $pdo->prepare("
            INSERT INTO audit_logs (user_id, action, table_name, record_id, description, created_at)
            VALUES (?, 'UNARCHIVE', 'dtr_attachments', ?, ?, NOW())
        ")->execute([
            $userId, $attId,
            "Restored archived DTR attachment #{$attId} ({$dtr['file_name']})",
        ]);

        echo json_encode(['success' => true, 'message' => 'DTR upload restored.']);

    } elseif ($action === 'delete') {
        // Only allow hard-delete on archived records (prevents accidental permanent loss)
        if ($hasMig023 && !(int)($dtr['is_archived'] ?? 0)) {
            echo json_encode(['success' => false, 'message' => 'Archive the record first before permanently deleting.']);
            exit;
        }

        // Remove physical file if it exists
        $filePath = __DIR__ . '/../' . ltrim($dtr['file_path'] ?? '', '/');
        $fileRemoved = false;
        if ($dtr['file_path'] && file_exists($filePath)) {
            $fileRemoved = @unlink($filePath);
        }

        $pdo->prepare("DELETE FROM dtr_attachments WHERE attachment_id = ?")->execute([$attId]);

        $pdo->prepare("
            INSERT INTO audit_logs (user_id, action, table_name, record_id, description, created_at)
            VALUES (?, 'DELETE', 'dtr_attachments', ?, ?, NOW())
        ")->execute([
            $userId, $attId,
            "Permanently deleted DTR attachment #{$attId} ({$dtr['file_name']})"
            . ($fileRemoved ? ' — file removed from disk' : ' — file already absent'),
        ]);

        echo json_encode(['success' => true, 'message' => 'DTR upload permanently deleted.']);

    } else {
        echo json_encode(['success' => false, 'message' => 'Unknown action.']);
    }

} catch (PDOException $e) {
    echo json_encode(['success' => false, 'message' => 'Database error: ' . $e->getMessage()]);
}
