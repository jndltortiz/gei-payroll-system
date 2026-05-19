<?php
// actions/service_credits_action.php
require_once '../config/database.php';
require_once '../config/session.php';

requireLogin();
requireAdmin();

$action = $_POST['action'] ?? '';

// ── Shared validation helper ───────────────────────────────────────────────
function redirectBack(string $status, string $message): void
{
    $_SESSION['sc_' . $status] = $message;
    header('Location: ../modules/service_credits/index.php');
    exit;
}

// ──────────────────────────────────────────────────────────────────────────────
// ADD
// ──────────────────────────────────────────────────────────────────────────────
if ($action === 'add') {
    $employeeId      = (int)($_POST['employee_id']      ?? 0);
    $days            = (float)($_POST['days']           ?? 0);
    $workDate        = trim($_POST['work_date']          ?? '');
    $remarks         = trim($_POST['remarks']            ?? '');
    $approvedByRole  = trim($_POST['approved_by_role']   ?? '');

    // Basic validation
    if (!$employeeId || $days <= 0 || !$workDate) {
        redirectBack('error', 'Please fill in all required fields.');
    }

    // Validate date is not in the future
    if (strtotime($workDate) > time()) {
        redirectBack('error', 'Date earned cannot be in the future.');
    }

    // Resolve approver user_id from role label (best-effort lookup)
    $approvedBy = null;
    if ($approvedByRole) {
        // Map role label → role_id (assumes roles table has matching names)
        $stmtRole = $pdo->prepare("SELECT role_id FROM roles WHERE role_name LIKE :role LIMIT 1");
        $stmtRole->execute([':role' => '%' . $approvedByRole . '%']);
        $role = $stmtRole->fetch(PDO::FETCH_ASSOC);
        if ($role) {
            $stmtUser = $pdo->prepare("SELECT user_id FROM users WHERE role_id = :rid AND is_active = 1 LIMIT 1");
            $stmtUser->execute([':rid' => $role['role_id']]);
            $user = $stmtUser->fetch(PDO::FETCH_ASSOC);
            $approvedBy = $user['user_id'] ?? null;
        }
    }

    // Calculate equivalent_pay from daily_rate if available
    $stmtComp = $pdo->prepare("
        SELECT daily_rate FROM employee_compensations
        WHERE employee_id = :eid AND is_active = 1
        ORDER BY effective_date DESC LIMIT 1
    ");
    $stmtComp->execute([':eid' => $employeeId]);
    $comp = $stmtComp->fetch(PDO::FETCH_ASSOC);
    $dailyRate       = (float)($comp['daily_rate'] ?? 0);
    $equivalentPay   = $dailyRate * $days;

    try {
        $stmt = $pdo->prepare("
            INSERT INTO service_credits
                (employee_id, work_date, days, equivalent_pay, is_approved, approved_by, remarks)
            VALUES
                (:eid, :work_date, :days, :equiv_pay, :is_approved, :approved_by, :remarks)
        ");
        $stmt->execute([
            ':eid'         => $employeeId,
            ':work_date'   => $workDate,
            ':days'        => $days,
            ':equiv_pay'   => $equivalentPay,
            ':is_approved' => 1,
            ':approved_by' => $approvedBy,
            ':remarks'     => $remarks ?: null,
        ]);

        // Log the action
        logAction($pdo, $_SESSION['user_id'], 'ADD_SERVICE_CREDIT', 'service_credits',
            (int)$pdo->lastInsertId(),
            "Added {$days} service credit(s) for employee #{$employeeId}");

        redirectBack('success', 'Service credit added successfully.');
    } catch (PDOException $e) {
        redirectBack('error', 'Database error: ' . $e->getMessage());
    }
}

// ──────────────────────────────────────────────────────────────────────────────
// EDIT
// ──────────────────────────────────────────────────────────────────────────────
if ($action === 'edit') {
    $creditId    = (int)($_POST['service_credit_id'] ?? 0);
    $employeeId  = (int)($_POST['employee_id']       ?? 0);
    $days        = (float)($_POST['days']            ?? 0);
    $workDate    = trim($_POST['work_date']           ?? '');
    $remarks     = trim($_POST['remarks']             ?? '');

    if (!$creditId || !$employeeId || $days <= 0 || !$workDate) {
        redirectBack('error', 'Please fill in all required fields.');
    }

    if (strtotime($workDate) > time()) {
        redirectBack('error', 'Date earned cannot be in the future.');
    }

    // Recalculate equivalent_pay
    $stmtComp = $pdo->prepare("
        SELECT daily_rate FROM employee_compensations
        WHERE employee_id = :eid AND is_active = 1
        ORDER BY effective_date DESC LIMIT 1
    ");
    $stmtComp->execute([':eid' => $employeeId]);
    $comp          = $stmtComp->fetch(PDO::FETCH_ASSOC);
    $equivalentPay = (float)($comp['daily_rate'] ?? 0) * $days;

    try {
        $stmt = $pdo->prepare("
            UPDATE service_credits
            SET employee_id    = :eid,
                work_date      = :work_date,
                days           = :days,
                equivalent_pay = :equiv_pay,
                remarks        = :remarks,
                updated_at     = NOW()
            WHERE service_credit_id = :id
        ");
        $stmt->execute([
            ':eid'       => $employeeId,
            ':work_date' => $workDate,
            ':days'      => $days,
            ':equiv_pay' => $equivalentPay,
            ':remarks'   => $remarks ?: null,
            ':id'        => $creditId,
        ]);

        logAction($pdo, $_SESSION['user_id'], 'EDIT_SERVICE_CREDIT', 'service_credits',
            $creditId, "Updated service credit #{$creditId}");

        redirectBack('success', 'Service credit updated successfully.');
    } catch (PDOException $e) {
        redirectBack('error', 'Database error: ' . $e->getMessage());
    }
}

// ──────────────────────────────────────────────────────────────────────────────
// DELETE
// ──────────────────────────────────────────────────────────────────────────────
if ($action === 'delete') {
    $creditId = (int)($_POST['service_credit_id'] ?? 0);

    if (!$creditId) {
        redirectBack('error', 'Invalid credit ID.');
    }

    try {
        $stmt = $pdo->prepare("DELETE FROM service_credits WHERE service_credit_id = :id");
        $stmt->execute([':id' => $creditId]);

        logAction($pdo, $_SESSION['user_id'], 'DELETE_SERVICE_CREDIT', 'service_credits',
            $creditId, "Deleted service credit #{$creditId}");

        redirectBack('success', 'Service credit deleted successfully.');
    } catch (PDOException $e) {
        redirectBack('error', 'Database error: ' . $e->getMessage());
    }
}

// Fallback
redirectBack('error', 'Invalid action.');

// ── Audit log helper ──────────────────────────────────────────────────────────
function logAction(PDO $pdo, int $userId, string $action, string $table,
                   int $recordId, string $description): void
{
    try {
        $s = $pdo->prepare("
            INSERT INTO audit_logs (user_id, action, table_name, record_id, description)
            VALUES (:uid, :action, :table, :rid, :desc)
        ");
        $s->execute([
            ':uid'    => $userId,
            ':action' => $action,
            ':table'  => $table,
            ':rid'    => $recordId,
            ':desc'   => $description,
        ]);
    } catch (PDOException) {
        // Non-fatal – audit log failure should not break the main flow
    }
}