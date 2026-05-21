<?php
/**
 * actions/leave-credits-action.php
 * Handles leave credit allocation: save (single), bulk_allocate.
 * POST-only; redirects back to the URL in $_POST['redirect'].
 */
require_once __DIR__ . '/../config/config.php';
require_once __DIR__ . '/../includes/auth.php';
requireLogin();

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    header('Location: ' . BASE_URL . 'modules/leave-credits/index.php');
    exit;
}

$action   = $_POST['action']   ?? '';
$redirect = $_POST['redirect'] ?? BASE_URL . 'modules/leave-credits/index.php';

function lcRedirect(string $url, bool $ok, string $msg): never
{
    $key = $ok ? 'lc_success' : 'lc_error';
    $_SESSION[$key] = $msg;
    header('Location: ' . $url);
    exit;
}

try {
    switch ($action) {

        // ── Save single credit row ─────────────────────────────────────────
        case 'save': {
            $creditId     = (int)($_POST['credit_id']     ?? 0);
            $employeeId   = (int)($_POST['employee_id']   ?? 0);
            $schoolYearId = (int)($_POST['school_year_id'] ?? 0);
            $leaveTypeId  = (int)($_POST['leave_type_id'] ?? 0);
            $allocated    = (float)($_POST['allocated_days'] ?? 0);
            $used         = (float)($_POST['used_days']      ?? 0);
            $notes        = trim($_POST['notes'] ?? '') ?: null;

            if (!$employeeId || !$schoolYearId || !$leaveTypeId) {
                lcRedirect($redirect, false, 'Missing required fields.');
            }
            if ($allocated < 0 || $used < 0) {
                lcRedirect($redirect, false, 'Days cannot be negative.');
            }

            if ($creditId > 0) {
                // Update existing
                $pdo->prepare("
                    UPDATE employee_leave_credits
                    SET allocated_days = ?, used_days = ?, notes = ?
                    WHERE credit_id = ?
                ")->execute([$allocated, $used, $notes, $creditId]);
            } else {
                // Insert (or replace if race condition)
                $pdo->prepare("
                    INSERT INTO employee_leave_credits
                        (employee_id, school_year_id, leave_type_id, allocated_days, used_days, notes)
                    VALUES (?, ?, ?, ?, ?, ?)
                    ON DUPLICATE KEY UPDATE
                        allocated_days = VALUES(allocated_days),
                        used_days      = VALUES(used_days),
                        notes          = VALUES(notes)
                ")->execute([$employeeId, $schoolYearId, $leaveTypeId, $allocated, $used, $notes]);
            }

            lcRedirect($redirect, true, 'Leave credit updated.');
        }

        // ── Bulk allocate ──────────────────────────────────────────────────
        case 'bulk_allocate': {
            $schoolYearId = (int)($_POST['school_year_id'] ?? 0);
            $leaveTypeId  = (int)($_POST['leave_type_id']  ?? 0);
            $allocated    = (float)($_POST['allocated_days'] ?? -1);
            $deptId       = (int)($_POST['department_id']  ?? 0);
            $notes        = trim($_POST['notes'] ?? '') ?: null;

            if (!$schoolYearId || !$leaveTypeId) {
                lcRedirect($redirect, false, 'School year and leave type are required.');
            }
            if ($allocated < 0) {
                lcRedirect($redirect, false, 'Allocated days must be 0 or greater.');
            }

            // Fetch matching employees
            $sql    = "SELECT employee_id FROM employees WHERE employee_status = 'ACTIVE'";
            $params = [];
            if ($deptId) { $sql .= " AND department_id = ?"; $params[] = $deptId; }
            $stmt = $pdo->prepare($sql);
            $stmt->execute($params);
            $employees = $stmt->fetchAll(PDO::FETCH_COLUMN);

            if (empty($employees)) {
                lcRedirect($redirect, false, 'No active employees found for the selected criteria.');
            }

            $pdo->beginTransaction();

            $upsert = $pdo->prepare("
                INSERT INTO employee_leave_credits
                    (employee_id, school_year_id, leave_type_id, allocated_days, used_days, notes)
                VALUES (?, ?, ?, ?, 0, ?)
                ON DUPLICATE KEY UPDATE
                    allocated_days = VALUES(allocated_days),
                    notes          = VALUES(notes)
            ");

            foreach ($employees as $empId) {
                $upsert->execute([$empId, $schoolYearId, $leaveTypeId, $allocated, $notes]);
            }

            $pdo->commit();

            $count = count($employees);
            lcRedirect($redirect, true, "Bulk allocation applied to {$count} employee(s): {$allocated} days per employee.");
        }

        default:
            lcRedirect($redirect, false, 'Unknown action.');
    }

} catch (PDOException $e) {
    if ($pdo->inTransaction()) $pdo->rollBack();
    lcRedirect($redirect, false, 'Database error: ' . $e->getMessage());
}
