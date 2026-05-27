<?php
/**
 * actions/employee-profile-update.php
 * Employee self-service profile update.
 * Allows employees to update ONLY their own:
 *   - contact_no, personal_email, address
 *   - emergency contact fields
 *   - upload one document
 *
 * Does NOT allow updating: department, position, salary, gov IDs,
 * school email, role, or any payroll-connected fields.
 */
require_once __DIR__ . '/../config/config.php';
require_once __DIR__ . '/../includes/auth.php';
requireEmployeeAccess();

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    header('Location: ' . BASE_URL . 'modules/employee/profile/index.php');
    exit;
}

$empId = (int)($_SESSION['user']['employee_id'] ?? 0);
if (!$empId) {
    setFlash('error', 'Session error. Please log in again.');
    header('Location: ' . BASE_URL . 'modules/employee/profile/index.php');
    exit;
}

try {
    // ── Normalize contact number ────────────────────────────────────────────
    $contact = trim($_POST['contact_no'] ?? '');
    if ($contact) {
        $c = preg_replace('/\s+/', '', $contact);
        if      (preg_match('/^\+639\d{9}$/', $c)) $contact = $c;
        elseif  (preg_match('/^639\d{9}$/', $c))   $contact = '+' . $c;
        elseif  (preg_match('/^09\d{9}$/', $c))    $contact = '+63' . substr($c, 1);
        else                                         $contact = $c;
    }

    // ── Check if personal_email column exists (migration 015) ───────────────
    $hasPE = false;
    try {
        $pdo->query("SELECT personal_email FROM employees LIMIT 1");
        $hasPE = true;
    } catch (PDOException $e) { /* column not yet migrated */ }

    // ── Update only allowed fields ──────────────────────────────────────────
    $peSet = $hasPE ? ", personal_email = :personal_email" : '';
    $stmt = $pdo->prepare("
        UPDATE employees SET
            contact_no                  = :contact_no
            $peSet,
            address                     = :address,
            emergency_contact_name      = :ec_name,
            emergency_contact_relation  = :ec_relation,
            emergency_contact_number    = :ec_number
        WHERE employee_id = :emp_id
    ");
    $profileParams = [
        ':contact_no'    => $contact ?: null,
        ':address'       => trim($_POST['address'] ?? '') ?: null,
        ':ec_name'       => trim($_POST['emergency_contact_name'] ?? '') ?: null,
        ':ec_relation'   => trim($_POST['emergency_contact_relation'] ?? '') ?: null,
        ':ec_number'     => trim($_POST['emergency_contact_number'] ?? '') ?: null,
        ':emp_id'        => $empId,
    ];
    if ($hasPE) {
        $profileParams[':personal_email'] = trim($_POST['personal_email'] ?? '') ?: null;
    }
    $stmt->execute($profileParams);

    // ── Document upload (single file) ───────────────────────────────────────
    $file    = $_FILES['doc_file'] ?? null;
    $docType = trim($_POST['doc_type'] ?? '');

    if ($file && $file['error'] === UPLOAD_ERR_OK && $file['name']) {
        if ($file['size'] > 5 * 1024 * 1024) {
            setFlash('error', 'File too large — maximum 5 MB.');
            header('Location: ' . BASE_URL . 'modules/employee/profile/index.php');
            exit;
        }
        $ext = strtolower(pathinfo($file['name'], PATHINFO_EXTENSION));
        if (!in_array($ext, ['pdf','jpg','jpeg','png'])) {
            setFlash('error', 'Invalid file type. Allowed: PDF, JPG, PNG.');
            header('Location: ' . BASE_URL . 'modules/employee/profile/index.php');
            exit;
        }
        $uploadDir = __DIR__ . '/../uploads/employees/';
        if (!is_dir($uploadDir)) mkdir($uploadDir, 0755, true);
        $stored   = 'emp_' . $empId . '_' . time() . '.' . $ext;
        $filePath = 'uploads/employees/' . $stored;
        if (move_uploaded_file($file['tmp_name'], $uploadDir . $stored)) {
            $uid = $_SESSION['user']['user_id'] ?? null;
            $pdo->prepare("
                INSERT INTO employee_documents (employee_id, doc_type, doc_name, file_path, file_size, uploaded_by)
                VALUES (?, ?, ?, ?, ?, ?)
            ")->execute([$empId, $docType ?: 'Document', $file['name'], $filePath, $file['size'], $uid]);
        }
    }

    setFlash('success', 'Profile updated successfully.');
    header('Location: ' . BASE_URL . 'modules/employee/profile/index.php');
    exit;

} catch (PDOException $e) {
    setFlash('error', 'Failed to update profile. Please try again.');
    header('Location: ' . BASE_URL . 'modules/employee/profile/index.php');
    exit;
}
