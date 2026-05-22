<?php
/**
 * actions/shift-action.php
 * Handles Shift CRUD: create, update, delete, toggle_active.
 * POST-only; redirects back to modules/shifts/index.php.
 */
require_once __DIR__ . '/../config/config.php';
require_once __DIR__ . '/../includes/auth.php';
requireLogin();

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    header('Location: ' . BASE_URL . 'modules/shifts/index.php');
    exit;
}

$action = $_POST['action'] ?? '';
$id     = (int)($_POST['shift_id'] ?? 0);
$back   = BASE_URL . 'modules/shifts/index.php';

function shRedirect(string $url, bool $ok, string $msg): never
{
    $_SESSION[$ok ? 'sh_success' : 'sh_error'] = $msg;
    header('Location: ' . $url);
    exit;
}

function parseTime(string $raw): ?string
{
    $raw = trim($raw);
    if ($raw === '') return null;
    // Accept HH:MM or HH:MM:SS — normalise to HH:MM:SS
    if (preg_match('/^\d{2}:\d{2}$/', $raw)) return $raw . ':00';
    if (preg_match('/^\d{2}:\d{2}:\d{2}$/', $raw)) return $raw;
    return null;
}

try {
    switch ($action) {

        // ── Create ────────────────────────────────────────────────────────────
        case 'create': {
            $name        = trim($_POST['shift_name']           ?? '');
            $startTime   = parseTime($_POST['start_time']      ?? '');
            $endTime     = parseTime($_POST['end_time']        ?? '');
            $grace       = max(0, (int)($_POST['grace_period_minutes'] ?? 0));
            $halfDay     = parseTime($_POST['half_day_time']   ?? '') ?: null;
            $isActive    = !empty($_POST['is_active']) ? 1 : 0;

            if (!$name || !$startTime || !$endTime) {
                shRedirect($back, false, 'Shift name, start time, and end time are required.');
            }
            if ($startTime >= $endTime) {
                shRedirect($back, false, 'End time must be after start time.');
            }

            $dup = $pdo->prepare("SELECT shift_id FROM shifts WHERE shift_name = ?");
            $dup->execute([$name]);
            if ($dup->fetch()) {
                shRedirect($back, false, "A shift named \"{$name}\" already exists.");
            }

            $pdo->prepare("
                INSERT INTO shifts (shift_name, start_time, end_time, grace_period_minutes, half_day_time, is_active)
                VALUES (?, ?, ?, ?, ?, ?)
            ")->execute([$name, $startTime, $endTime, $grace, $halfDay, $isActive]);

            shRedirect($back, true, "Shift \"{$name}\" created successfully.");
        }

        // ── Update ────────────────────────────────────────────────────────────
        case 'update': {
            if (!$id) shRedirect($back, false, 'Invalid shift.');

            $name      = trim($_POST['shift_name']           ?? '');
            $startTime = parseTime($_POST['start_time']      ?? '');
            $endTime   = parseTime($_POST['end_time']        ?? '');
            $grace     = max(0, (int)($_POST['grace_period_minutes'] ?? 0));
            $halfDay   = parseTime($_POST['half_day_time']   ?? '') ?: null;
            $isActive  = !empty($_POST['is_active']) ? 1 : 0;

            if (!$name || !$startTime || !$endTime) {
                shRedirect($back, false, 'Shift name, start time, and end time are required.');
            }
            if ($startTime >= $endTime) {
                shRedirect($back, false, 'End time must be after start time.');
            }

            $dup = $pdo->prepare("SELECT shift_id FROM shifts WHERE shift_name = ? AND shift_id != ?");
            $dup->execute([$name, $id]);
            if ($dup->fetch()) {
                shRedirect($back, false, "Another shift named \"{$name}\" already exists.");
            }

            $pdo->prepare("
                UPDATE shifts
                SET shift_name=?, start_time=?, end_time=?,
                    grace_period_minutes=?, half_day_time=?, is_active=?
                WHERE shift_id=?
            ")->execute([$name, $startTime, $endTime, $grace, $halfDay, $isActive, $id]);

            shRedirect($back, true, "Shift \"{$name}\" updated.");
        }

        // ── Toggle active ─────────────────────────────────────────────────────
        case 'toggle_active': {
            if (!$id) shRedirect($back, false, 'Invalid shift.');

            $row = $pdo->prepare("SELECT shift_name, is_active FROM shifts WHERE shift_id=?");
            $row->execute([$id]);
            $shift = $row->fetch();
            if (!$shift) shRedirect($back, false, 'Shift not found.');

            $newState = $shift['is_active'] ? 0 : 1;
            $pdo->prepare("UPDATE shifts SET is_active=? WHERE shift_id=?")->execute([$newState, $id]);

            $label = $newState ? 'activated' : 'deactivated';
            shRedirect($back, true, "Shift \"{$shift['shift_name']}\" {$label}.");
        }

        // ── Delete ────────────────────────────────────────────────────────────
        case 'delete': {
            if (!$id) shRedirect($back, false, 'Invalid shift.');

            $row = $pdo->prepare("SELECT shift_name FROM shifts WHERE shift_id=?");
            $row->execute([$id]);
            $shift = $row->fetch();
            if (!$shift) shRedirect($back, false, 'Shift not found.');

            // Refuse if employees are assigned to this shift
            $empCheck = $pdo->prepare("SELECT COUNT(*) FROM employees WHERE shift_id=? AND employee_status='ACTIVE'");
            $empCheck->execute([$id]);
            if ((int)$empCheck->fetchColumn() > 0) {
                shRedirect($back, false,
                    "Cannot delete \"{$shift['shift_name']}\": active employees are assigned to this shift. " .
                    'Re-assign them first.');
            }

            $pdo->prepare("DELETE FROM shifts WHERE shift_id=?")->execute([$id]);
            shRedirect($back, true, "Shift \"{$shift['shift_name']}\" deleted.");
        }

        default:
            shRedirect($back, false, 'Unknown action.');
    }

} catch (PDOException $e) {
    shRedirect($back, false, 'Database error: ' . $e->getMessage());
}
