<?php
/**
 * actions/loan-document-action.php
 * Handles loan document uploads and deletions.
 *
 * POST action=upload  — employee or admin uploads documents for a loan
 * POST action=delete  — admin deletes a document (document_id)
 * GET  action=list    — list documents for a loan (loan_id)
 *
 * Upload accepts: PDF, JPG, JPEG, PNG — max 5 MB each, up to 5 files.
 * Files saved to: uploads/loan-documents/{loan_id}/
 * Returns JSON.
 */
require_once __DIR__ . '/../config/config.php';
require_once __DIR__ . '/../includes/auth.php';
header('Content-Type: application/json');
requireLogin();

$action      = $_POST['action'] ?? ($_GET['action'] ?? '');
$uid         = $_SESSION['user']['user_id']     ?? null;
$empId       = $_SESSION['user']['employee_id'] ?? null;

$isAdmin     = isAdmin();
$isPrincipal = isPrincipalRole();
$isEmployee  = isEmployee();

// ── Check migration 019 ───────────────────────────────────────────────────────
$hasTable = (bool)$pdo->query("
    SELECT COUNT(*) FROM information_schema.TABLES
    WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'loan_documents'
")->fetchColumn();

if (!$hasTable) {
    echo json_encode(['success'=>false,'message'=>'Document upload requires migration 019. Ask the administrator to run the database migration.']);
    exit;
}

// ── LIST documents ────────────────────────────────────────────────────────────
if ($action === 'list') {
    $loanId = (int)($_GET['loan_id'] ?? $_POST['loan_id'] ?? 0);
    if (!$loanId) { echo json_encode(['success'=>false,'message'=>'Invalid loan ID.']); exit; }

    // Employees can only see documents for their own loans
    if ($isEmployee) {
        $check = $pdo->prepare("SELECT loan_id FROM employee_loans WHERE loan_id=? AND employee_id=?");
        $check->execute([$loanId, $empId]);
        if (!$check->fetchColumn()) {
            echo json_encode(['success'=>false,'message'=>'Access denied.']); exit;
        }
    }

    $stmt = $pdo->prepare("
        SELECT ld.*, u.username AS uploaded_by_name
        FROM loan_documents ld
        LEFT JOIN users u ON ld.uploaded_by = u.user_id
        WHERE ld.loan_id = ?
        ORDER BY ld.created_at DESC
    ");
    $stmt->execute([$loanId]);
    $docs = $stmt->fetchAll(PDO::FETCH_ASSOC);

    foreach ($docs as &$doc) {
        $doc['url'] = BASE_URL . 'uploads/loan-documents/' . $doc['loan_id'] . '/' . $doc['file_name'];
        $doc['size_kb'] = round($doc['file_size'] / 1024, 1);
    }
    echo json_encode(['success'=>true,'documents'=>$docs]);
    exit;
}

// ── DELETE document ───────────────────────────────────────────────────────────
if ($action === 'delete') {
    if (!$isAdmin && !$isPrincipal) {
        echo json_encode(['success'=>false,'message'=>'Only Admin can delete documents.']); exit;
    }
    if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
        echo json_encode(['success'=>false,'message'=>'Invalid request method.']); exit;
    }

    $docId = (int)($_POST['document_id'] ?? 0);
    if (!$docId) { echo json_encode(['success'=>false,'message'=>'Invalid document ID.']); exit; }

    $stmt = $pdo->prepare("SELECT * FROM loan_documents WHERE document_id=?");
    $stmt->execute([$docId]);
    $doc = $stmt->fetch(PDO::FETCH_ASSOC);
    if (!$doc) { echo json_encode(['success'=>false,'message'=>'Document not found.']); exit; }

    $absPath = __DIR__ . '/../uploads/loan-documents/' . $doc['loan_id'] . '/' . $doc['file_name'];
    if (file_exists($absPath)) {
        unlink($absPath);
    }

    $pdo->prepare("DELETE FROM loan_documents WHERE document_id=?")->execute([$docId]);

    if ($uid) $pdo->prepare("INSERT INTO audit_logs(user_id,action,table_name,record_id,description) VALUES(?,?,?,?,?)")
        ->execute([$uid,'DELETE','loan_documents',$docId,
                   "Deleted document '{$doc['original_name']}' from loan #{$doc['loan_id']}"]);

    echo json_encode(['success'=>true,'message'=>'Document deleted.']);
    exit;
}

// ── UPLOAD documents ──────────────────────────────────────────────────────────
if ($action === 'upload') {
    if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
        echo json_encode(['success'=>false,'message'=>'Invalid request method.']); exit;
    }

    $loanId = (int)($_POST['loan_id'] ?? 0);
    if (!$loanId) { echo json_encode(['success'=>false,'message'=>'Loan ID is required.']); exit; }

    // Employees can only upload to their own loans
    if ($isEmployee) {
        $check = $pdo->prepare("SELECT loan_id FROM employee_loans WHERE loan_id=? AND employee_id=?");
        $check->execute([$loanId, $empId]);
        if (!$check->fetchColumn()) {
            echo json_encode(['success'=>false,'message'=>'Access denied.']); exit;
        }
    }

    // Collect uploaded files (supports single 'loan_doc' or array 'loan_docs[]')
    $files = [];
    if (!empty($_FILES['loan_docs']['name'])) {
        $fileCount = is_array($_FILES['loan_docs']['name']) ? count($_FILES['loan_docs']['name']) : 1;
        for ($i = 0; $i < $fileCount; $i++) {
            if (is_array($_FILES['loan_docs']['name'])) {
                $files[] = [
                    'name'     => $_FILES['loan_docs']['name'][$i],
                    'tmp_name' => $_FILES['loan_docs']['tmp_name'][$i],
                    'error'    => $_FILES['loan_docs']['error'][$i],
                    'size'     => $_FILES['loan_docs']['size'][$i],
                ];
            } else {
                $files[] = $_FILES['loan_docs'];
            }
        }
    } elseif (!empty($_FILES['loan_doc'])) {
        $files[] = $_FILES['loan_doc'];
    }

    if (empty($files)) {
        echo json_encode(['success'=>false,'message'=>'No files were uploaded.']); exit;
    }
    if (count($files) > 5) {
        echo json_encode(['success'=>false,'message'=>'Maximum 5 files per upload.']); exit;
    }

    $allowedExts  = ['pdf','jpg','jpeg','png'];
    $allowedMimes = ['application/pdf','image/jpeg','image/png'];
    $maxBytes     = 5 * 1024 * 1024; // 5 MB

    $uploadDir = __DIR__ . '/../uploads/loan-documents/' . $loanId . '/';
    if (!is_dir($uploadDir)) {
        mkdir($uploadDir, 0755, true);
    }

    $savedDocs = [];
    $finfo     = new finfo(FILEINFO_MIME_TYPE);
    $filedRole = $isEmployee ? 'EMPLOYEE' : 'ADMIN';

    foreach ($files as $file) {
        if ($file['error'] !== UPLOAD_ERR_OK) continue;
        if ($file['size'] > $maxBytes) {
            echo json_encode(['success'=>false,'message'=>"File '{$file['name']}' exceeds 5 MB limit."]);
            exit;
        }

        $ext = strtolower(pathinfo($file['name'], PATHINFO_EXTENSION));
        if (!in_array($ext, $allowedExts, true)) {
            echo json_encode(['success'=>false,'message'=>"File type '.{$ext}' is not allowed. Accepted: PDF, JPG, PNG."]);
            exit;
        }

        $mimeType = $finfo->file($file['tmp_name']);
        if (!in_array($mimeType, $allowedMimes, true)) {
            echo json_encode(['success'=>false,'message'=>"File content type not allowed for '{$file['name']}'."]);
            exit;
        }

        $originalName = basename($file['name']);
        $safeName     = preg_replace('/[^a-z0-9_\-]/', '_', strtolower(pathinfo($originalName, PATHINFO_FILENAME)));
        $safeName     = substr($safeName, 0, 60);
        $fileName     = 'loan_' . $loanId . '_' . time() . '_' . uniqid() . '_' . $safeName . '.' . $ext;
        $absPath      = $uploadDir . $fileName;

        if (!move_uploaded_file($file['tmp_name'], $absPath)) {
            echo json_encode(['success'=>false,'message'=>"Failed to save file '{$originalName}'. Check server permissions."]);
            exit;
        }

        $pdo->prepare("
            INSERT INTO loan_documents
                (loan_id, file_name, original_name, file_size, mime_type, uploaded_by, filed_by_role)
            VALUES (?,?,?,?,?,?,?)
        ")->execute([$loanId, $fileName, $originalName, $file['size'], $mimeType, $uid, $filedRole]);

        $savedDocs[] = [
            'document_id'   => (int)$pdo->lastInsertId(),
            'file_name'     => $fileName,
            'original_name' => $originalName,
            'file_size'     => $file['size'],
            'url'           => BASE_URL . 'uploads/loan-documents/' . $loanId . '/' . $fileName,
        ];
    }

    if (empty($savedDocs)) {
        echo json_encode(['success'=>false,'message'=>'No files were saved.']);
        exit;
    }

    if ($uid) $pdo->prepare("INSERT INTO audit_logs(user_id,action,table_name,record_id,description) VALUES(?,?,?,?,?)")
        ->execute([$uid,'UPLOAD','loan_documents',$loanId,
                   "Uploaded " . count($savedDocs) . " document(s) for loan #{$loanId}"]);

    echo json_encode(['success'=>true,'message'=>count($savedDocs) . ' document(s) uploaded.','documents'=>$savedDocs]);
    exit;
}

echo json_encode(['success'=>false,'message'=>'Unknown action.']);
