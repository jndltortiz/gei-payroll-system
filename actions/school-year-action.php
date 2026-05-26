<?php
/**
 * actions/school-year-action.php
 * Handles school year CRUD: create, update, delete, set_active.
 * POST-only; redirects back to modules/school-years/index.php.
 */
require_once __DIR__ . '/../config/config.php';
require_once __DIR__ . '/../includes/auth.php';
requireLogin();

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    header('Location: ' . BASE_URL . 'modules/school-years/index.php');
    exit;
}

$action = $_POST['action'] ?? '';
$id     = (int)($_POST['school_year_id'] ?? 0);
$back   = BASE_URL . 'modules/school-years/index.php';

function syRedirect(string $url, bool $ok, string $msg): never
{
    $key = $ok ? 'sy_success' : 'sy_error';
    $_SESSION[$key] = $msg;
    header('Location: ' . $url);
    exit;
}

try {
    switch ($action) {

        // ── Create ────────────────────────────────────────────────────────
        case 'create': {
            $yearName  = trim($_POST['year_name']  ?? '');
            $startDate = trim($_POST['start_date'] ?? '');
            $endDate   = trim($_POST['end_date']   ?? '');
            $isActive  = !empty($_POST['is_active']) ? 1 : 0;

            if (!$yearName || !$startDate || !$endDate) {
                syRedirect($back, false, 'Year name, start date, and end date are required.');
            }
            if ($startDate >= $endDate) {
                syRedirect($back, false, 'End date must be after start date.');
            }

            // Duplicate name
            $dupName = $pdo->prepare("SELECT school_year_id FROM school_years WHERE year_name = ?");
            $dupName->execute([$yearName]);
            if ($dupName->fetch()) {
                syRedirect($back, false, "School year \"{$yearName}\" already exists.");
            }

            // Overlapping date range
            $over = $pdo->prepare("
                SELECT year_name FROM school_years
                WHERE start_date <= ? AND end_date >= ?
            ");
            $over->execute([$endDate, $startDate]);
            if ($row = $over->fetch()) {
                syRedirect($back, false, "Date range overlaps with existing school year \"{$row['year_name']}\".");
            }

            $pdo->beginTransaction();

            if ($isActive) {
                $pdo->exec("UPDATE school_years SET is_active = 0");
            }

            $pdo->prepare("
                INSERT INTO school_years (year_name, start_date, end_date, is_active)
                VALUES (?, ?, ?, ?)
            ")->execute([$yearName, $startDate, $endDate, $isActive]);

            $pdo->commit();
            syRedirect($back, true, "School year \"{$yearName}\" created successfully.");
        }

        // ── Update ────────────────────────────────────────────────────────
        case 'update': {
            if (!$id) syRedirect($back, false, 'Invalid school year.');

            $yearName  = trim($_POST['year_name']  ?? '');
            $startDate = trim($_POST['start_date'] ?? '');
            $endDate   = trim($_POST['end_date']   ?? '');
            $isActive  = !empty($_POST['is_active']) ? 1 : 0;

            if (!$yearName || !$startDate || !$endDate) {
                syRedirect($back, false, 'Year name, start date, and end date are required.');
            }
            if ($startDate >= $endDate) {
                syRedirect($back, false, 'End date must be after start date.');
            }

            // Duplicate name (excluding self)
            $dupName = $pdo->prepare("SELECT school_year_id FROM school_years WHERE year_name = ? AND school_year_id != ?");
            $dupName->execute([$yearName, $id]);
            if ($dupName->fetch()) {
                syRedirect($back, false, "School year \"{$yearName}\" already exists.");
            }

            // Overlapping date range (excluding self)
            $over = $pdo->prepare("
                SELECT year_name FROM school_years
                WHERE school_year_id != ? AND start_date <= ? AND end_date >= ?
            ");
            $over->execute([$id, $endDate, $startDate]);
            if ($row = $over->fetch()) {
                syRedirect($back, false, "Date range overlaps with existing school year \"{$row['year_name']}\".");
            }

            $pdo->beginTransaction();

            if ($isActive) {
                $pdo->prepare("UPDATE school_years SET is_active = 0 WHERE school_year_id != ?")
                    ->execute([$id]);
            }

            $pdo->prepare("
                UPDATE school_years
                SET year_name = ?, start_date = ?, end_date = ?, is_active = ?
                WHERE school_year_id = ?
            ")->execute([$yearName, $startDate, $endDate, $isActive, $id]);

            $pdo->commit();
            syRedirect($back, true, "School year \"{$yearName}\" updated.");
        }

        // ── Set Active ────────────────────────────────────────────────────
        case 'set_active': {
            if (!$id) syRedirect($back, false, 'Invalid school year.');

            $pdo->beginTransaction();
            $pdo->exec("UPDATE school_years SET is_active = 0");
            $pdo->prepare("UPDATE school_years SET is_active = 1 WHERE school_year_id = ?")
                ->execute([$id]);
            $pdo->commit();

            $stmt = $pdo->prepare("SELECT year_name FROM school_years WHERE school_year_id = ?");
            $stmt->execute([$id]);
            $name = $stmt->fetchColumn() ?: 'selected year';
            syRedirect($back, true, "\"{$name}\" is now the active school year.");
        }

        // ── Delete ────────────────────────────────────────────────────────
        case 'delete': {
            if (!$id) syRedirect($back, false, 'Invalid school year.');

            // Safety: refuse to delete if it has linked leave data
            $stmtCheck = $pdo->prepare("
                SELECT
                    (SELECT COUNT(*) FROM employee_leave_credits WHERE school_year_id = ?) AS credits,
                    (SELECT COUNT(*) FROM leave_requests          WHERE school_year_id = ?) AS leaves,
                    (SELECT is_active FROM school_years           WHERE school_year_id = ?) AS active_flag
            ");
            $stmtCheck->execute([$id, $id, $id]);
            $check = $stmtCheck->fetch();

            if ((int)$check['credits'] > 0 || (int)$check['leaves'] > 0) {
                syRedirect($back, false, 'Cannot delete a school year that has linked leave records or credit allocations.');
            }
            if ((int)$check['active_flag']) {
                syRedirect($back, false, 'Cannot delete the active school year. Deactivate it first.');
            }

            $pdo->prepare("DELETE FROM school_years WHERE school_year_id = ?")->execute([$id]);
            syRedirect($back, true, 'School year deleted.');
        }

        default:
            syRedirect($back, false, 'Unknown action.');
    }

} catch (PDOException $e) {
    if ($pdo->inTransaction()) $pdo->rollBack();
    syRedirect($back, false, 'Database error: ' . $e->getMessage());
}
