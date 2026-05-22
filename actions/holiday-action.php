<?php
/**
 * actions/holiday-action.php
 * Handles Holiday CRUD: create, update, delete.
 * POST-only; redirects back to modules/holidays/index.php.
 */
require_once __DIR__ . '/../config/config.php';
require_once __DIR__ . '/../includes/auth.php';
requireLogin();

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    header('Location: ' . BASE_URL . 'modules/holidays/index.php');
    exit;
}

$action = $_POST['action'] ?? '';
$id     = (int)($_POST['holiday_id'] ?? 0);
$back   = BASE_URL . 'modules/holidays/index.php';

function holRedirect(string $url, bool $ok, string $msg): never
{
    $_SESSION[$ok ? 'hol_success' : 'hol_error'] = $msg;
    header('Location: ' . $url);
    exit;
}

try {
    switch ($action) {

        // ── Create ────────────────────────────────────────────────────────────
        case 'create': {
            $name  = trim($_POST['holiday_name']   ?? '');
            $date  = trim($_POST['holiday_date']   ?? '');
            $type  = trim($_POST['holiday_type']   ?? 'REGULAR');
            $syId  = (int)($_POST['school_year_id'] ?? 0) ?: null;
            $notes = trim($_POST['notes']           ?? '') ?: null;

            if (!$name || !$date) {
                holRedirect($back, false, 'Holiday name and date are required.');
            }
            if (!in_array($type, ['REGULAR', 'SPECIAL', 'SCHOOL'], true)) {
                $type = 'REGULAR';
            }

            $dup = $pdo->prepare("SELECT holiday_id FROM holidays WHERE holiday_date = ?");
            $dup->execute([$date]);
            if ($dup->fetch()) {
                holRedirect($back, false, 'A holiday already exists on ' . date('M j, Y', strtotime($date)) . '.');
            }

            $pdo->prepare("
                INSERT INTO holidays (holiday_name, holiday_date, holiday_type, school_year_id, notes)
                VALUES (?, ?, ?, ?, ?)
            ")->execute([$name, $date, $type, $syId, $notes]);

            holRedirect($back, true, "Holiday \"{$name}\" added successfully.");
        }

        // ── Update ────────────────────────────────────────────────────────────
        case 'update': {
            if (!$id) holRedirect($back, false, 'Invalid holiday.');

            $name  = trim($_POST['holiday_name']   ?? '');
            $date  = trim($_POST['holiday_date']   ?? '');
            $type  = trim($_POST['holiday_type']   ?? 'REGULAR');
            $syId  = (int)($_POST['school_year_id'] ?? 0) ?: null;
            $notes = trim($_POST['notes']           ?? '') ?: null;

            if (!$name || !$date) {
                holRedirect($back, false, 'Holiday name and date are required.');
            }
            if (!in_array($type, ['REGULAR', 'SPECIAL', 'SCHOOL'], true)) {
                $type = 'REGULAR';
            }

            $dup = $pdo->prepare("SELECT holiday_id FROM holidays WHERE holiday_date = ? AND holiday_id != ?");
            $dup->execute([$date, $id]);
            if ($dup->fetch()) {
                holRedirect($back, false, 'Another holiday already exists on ' . date('M j, Y', strtotime($date)) . '.');
            }

            $pdo->prepare("
                UPDATE holidays
                SET holiday_name=?, holiday_date=?, holiday_type=?, school_year_id=?, notes=?
                WHERE holiday_id=?
            ")->execute([$name, $date, $type, $syId, $notes, $id]);

            holRedirect($back, true, "Holiday \"{$name}\" updated.");
        }

        // ── Delete ────────────────────────────────────────────────────────────
        case 'delete': {
            if (!$id) holRedirect($back, false, 'Invalid holiday.');

            $row = $pdo->prepare("SELECT holiday_name, holiday_date FROM holidays WHERE holiday_id = ?");
            $row->execute([$id]);
            $hol = $row->fetch();
            if (!$hol) holRedirect($back, false, 'Holiday not found.');

            // Refuse if attendance records are already marked HOLIDAY for this date
            $attCheck = $pdo->prepare("
                SELECT COUNT(*) FROM attendance_records
                WHERE attendance_date = ? AND attendance_status = 'HOLIDAY'
            ");
            $attCheck->execute([$hol['holiday_date']]);
            if ((int)$attCheck->fetchColumn() > 0) {
                holRedirect($back, false,
                    'Cannot delete: attendance records are already marked HOLIDAY on ' .
                    date('M j, Y', strtotime($hol['holiday_date'])) . '.');
            }

            $pdo->prepare("DELETE FROM holidays WHERE holiday_id = ?")->execute([$id]);
            holRedirect($back, true, 'Holiday deleted.');
        }

        default:
            holRedirect($back, false, 'Unknown action.');
    }

} catch (PDOException $e) {
    holRedirect($back, false, 'Database error: ' . $e->getMessage());
}
