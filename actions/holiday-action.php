<?php
/**
 * actions/holiday-action.php
 * Handles Holiday CRUD: create, update, delete, import_ph,
 * bulk_delete, bulk_link, bulk_type.
 * POST-only; redirects back to modules/holidays/index.php.
 */
require_once __DIR__ . '/../config/config.php';
require_once __DIR__ . '/../includes/auth.php';
requireAdminPage();

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    header('Location: ' . BASE_URL . 'modules/holidays/index.php');
    exit;
}

$action = $_POST['action'] ?? '';
$id     = (int)($_POST['holiday_id'] ?? 0);
$back   = $_POST['redirect'] ?? BASE_URL . 'modules/holidays/index.php';

function holRedirect(string $url, bool $ok, string $msg): never
{
    $_SESSION[$ok ? 'hol_success' : 'hol_error'] = $msg;
    header('Location: ' . $url);
    exit;
}

// ── Easter Sunday (Anonymous Gregorian algorithm) ─────────────────────────
function easterSunday(int $year): string
{
    $a = $year % 19;
    $b = (int)($year / 100);
    $c = $year % 100;
    $d = (int)($b / 4);
    $e = $b % 4;
    $f = (int)(($b + 8) / 25);
    $g = (int)(($b - $f + 1) / 3);
    $h = (19 * $a + $b - $d - $g + 15) % 30;
    $i = (int)($c / 4);
    $k = $c % 4;
    $l = (32 + 2 * $e + 2 * $i - $h - $k) % 7;
    $m = (int)(($a + 11 * $h + 22 * $l) / 451);
    $month = (int)(($h + $l - 7 * $m + 114) / 31);
    $day   = (($h + $l - 7 * $m + 114) % 31) + 1;
    return sprintf('%04d-%02d-%02d', $year, $month, $day);
}

// ── Official Philippine holiday list for a given year ─────────────────────
function generatePHHolidays(int $year): array
{
    $easter      = easterSunday($year);
    $maundyDate  = date('Y-m-d', strtotime($easter . ' -3 days'));
    $goodFriDate = date('Y-m-d', strtotime($easter . ' -2 days'));
    $blackSat    = date('Y-m-d', strtotime($easter . ' -1 day'));

    // Last Monday of August = National Heroes Day
    $ts = mktime(0, 0, 0, 8, 31, $year);
    while ((int)date('N', $ts) !== 1) { $ts -= 86400; }
    $heroesDay = date('Y-m-d', $ts);

    return [
        // ── Regular Holidays ───────────────────────────────────────────────
        ['holiday_name' => "New Year's Day",      'holiday_date' => "{$year}-01-01", 'holiday_type' => 'REGULAR'],
        ['holiday_name' => "Araw ng Kagitingan",  'holiday_date' => "{$year}-04-09", 'holiday_type' => 'REGULAR'],
        ['holiday_name' => "Maundy Thursday",     'holiday_date' => $maundyDate,     'holiday_type' => 'REGULAR'],
        ['holiday_name' => "Good Friday",         'holiday_date' => $goodFriDate,    'holiday_type' => 'REGULAR'],
        ['holiday_name' => "Labor Day",           'holiday_date' => "{$year}-05-01", 'holiday_type' => 'REGULAR'],
        ['holiday_name' => "Independence Day",    'holiday_date' => "{$year}-06-12", 'holiday_type' => 'REGULAR'],
        ['holiday_name' => "National Heroes Day", 'holiday_date' => $heroesDay,      'holiday_type' => 'REGULAR'],
        ['holiday_name' => "Bonifacio Day",       'holiday_date' => "{$year}-11-30", 'holiday_type' => 'REGULAR'],
        ['holiday_name' => "Christmas Day",       'holiday_date' => "{$year}-12-25", 'holiday_type' => 'REGULAR'],
        ['holiday_name' => "Rizal Day",           'holiday_date' => "{$year}-12-30", 'holiday_type' => 'REGULAR'],
        // ── Special (Non-Working) Holidays ────────────────────────────────
        ['holiday_name' => "EDSA People Power Revolution Anniversary", 'holiday_date' => "{$year}-02-25", 'holiday_type' => 'SPECIAL'],
        ['holiday_name' => "Black Saturday",                           'holiday_date' => $blackSat,       'holiday_type' => 'SPECIAL'],
        ['holiday_name' => "Ninoy Aquino Day",                         'holiday_date' => "{$year}-08-21", 'holiday_type' => 'SPECIAL'],
        ['holiday_name' => "All Saints' Day",                          'holiday_date' => "{$year}-11-01", 'holiday_type' => 'SPECIAL'],
        ['holiday_name' => "Feast of the Immaculate Conception",       'holiday_date' => "{$year}-12-08", 'holiday_type' => 'SPECIAL'],
        ['holiday_name' => "Last Day of the Year",                     'holiday_date' => "{$year}-12-31", 'holiday_type' => 'SPECIAL'],
    ];
}

try {
    switch ($action) {

        // ── Create ────────────────────────────────────────────────────────
        case 'create': {
            $name  = trim($_POST['holiday_name']    ?? '');
            $date  = trim($_POST['holiday_date']    ?? '');
            $type  = trim($_POST['holiday_type']    ?? 'REGULAR');
            $syId  = (int)($_POST['school_year_id'] ?? 0) ?: null;
            $notes = trim($_POST['notes']           ?? '') ?: null;

            if (!$name || !$date) {
                holRedirect($back, false, 'Holiday name and date are required.');
            }
            if (!in_array($type, ['REGULAR', 'SPECIAL', 'SCHOOL'], true)) {
                $type = 'REGULAR';
            }
            if ($type === 'SCHOOL' && !$syId) {
                holRedirect($back, false, 'School Calendar holidays must be linked to a school year.');
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

        // ── Update ────────────────────────────────────────────────────────
        case 'update': {
            if (!$id) holRedirect($back, false, 'Invalid holiday.');

            $name  = trim($_POST['holiday_name']    ?? '');
            $date  = trim($_POST['holiday_date']    ?? '');
            $type  = trim($_POST['holiday_type']    ?? 'REGULAR');
            $syId  = (int)($_POST['school_year_id'] ?? 0) ?: null;
            $notes = trim($_POST['notes']           ?? '') ?: null;

            if (!$name || !$date) {
                holRedirect($back, false, 'Holiday name and date are required.');
            }
            if (!in_array($type, ['REGULAR', 'SPECIAL', 'SCHOOL'], true)) {
                $type = 'REGULAR';
            }
            if ($type === 'SCHOOL' && !$syId) {
                holRedirect($back, false, 'School Calendar holidays must be linked to a school year.');
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

        // ── Delete ────────────────────────────────────────────────────────
        case 'delete': {
            if (!$id) holRedirect($back, false, 'Invalid holiday.');

            $row = $pdo->prepare("SELECT holiday_name, holiday_date FROM holidays WHERE holiday_id = ?");
            $row->execute([$id]);
            $hol = $row->fetch();
            if (!$hol) holRedirect($back, false, 'Holiday not found.');

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

        // ── Import Philippine Holidays ─────────────────────────────────────
        case 'import_ph': {
            $year = (int)($_POST['import_year']     ?? date('Y'));
            $syId = (int)($_POST['school_year_id']  ?? 0) ?: null;

            if ($year < 2000 || $year > 2100) {
                holRedirect($back, false, 'Invalid year selected for import.');
            }

            $toImport = generatePHHolidays($year);
            $check  = $pdo->prepare("SELECT holiday_id FROM holidays WHERE holiday_date = ?");
            $insert = $pdo->prepare("
                INSERT INTO holidays (holiday_name, holiday_date, holiday_type, school_year_id)
                VALUES (?, ?, ?, ?)
            ");

            $inserted = 0;
            $skipped  = 0;

            foreach ($toImport as $h) {
                $check->execute([$h['holiday_date']]);
                if ($check->fetch()) {
                    $skipped++;
                } else {
                    $insert->execute([$h['holiday_name'], $h['holiday_date'], $h['holiday_type'], $syId]);
                    $inserted++;
                }
            }

            $msg = "Imported {$inserted} Philippine holiday(s) for {$year}.";
            if ($skipped > 0) {
                $msg .= " {$skipped} date(s) skipped — a holiday already exists on those dates.";
            }
            holRedirect($back, true, $msg);
        }

        // ── Bulk Delete ───────────────────────────────────────────────────
        case 'bulk_delete': {
            $ids = array_map('intval', (array)($_POST['holiday_ids'] ?? []));
            $ids = array_filter($ids);
            if (empty($ids)) holRedirect($back, false, 'No holidays selected.');

            $deleted = 0;
            $blocked = [];

            foreach ($ids as $hid) {
                $row = $pdo->prepare("SELECT holiday_name, holiday_date FROM holidays WHERE holiday_id = ?");
                $row->execute([$hid]);
                $hol = $row->fetch();
                if (!$hol) continue;

                $attCheck = $pdo->prepare("
                    SELECT COUNT(*) FROM attendance_records
                    WHERE attendance_date = ? AND attendance_status = 'HOLIDAY'
                ");
                $attCheck->execute([$hol['holiday_date']]);
                if ((int)$attCheck->fetchColumn() > 0) {
                    $blocked[] = $hol['holiday_name'];
                } else {
                    $pdo->prepare("DELETE FROM holidays WHERE holiday_id = ?")->execute([$hid]);
                    $deleted++;
                }
            }

            $msg = "Deleted {$deleted} holiday(s).";
            if (!empty($blocked)) {
                $msg .= " Could not delete: " . implode(', ', $blocked) . " (attendance records exist on those dates).";
            }
            holRedirect($back, empty($blocked) ? true : false, $msg);
        }

        // ── Bulk Link to School Year ──────────────────────────────────────
        case 'bulk_link': {
            $ids  = array_map('intval', (array)($_POST['holiday_ids'] ?? []));
            $ids  = array_filter($ids);
            $syId = (int)($_POST['school_year_id'] ?? 0) ?: null;

            if (empty($ids)) holRedirect($back, false, 'No holidays selected.');

            $ph = implode(',', array_fill(0, count($ids), '?'));
            $pdo->prepare("UPDATE holidays SET school_year_id = ? WHERE holiday_id IN ({$ph})")
                ->execute(array_merge([$syId], array_values($ids)));

            $label = $syId ? 'linked to school year' : 'unlinked from school year';
            holRedirect($back, true, count($ids) . " holiday(s) {$label}.");
        }

        // ── Bulk Change Type ──────────────────────────────────────────────
        case 'bulk_type': {
            $ids  = array_map('intval', (array)($_POST['holiday_ids'] ?? []));
            $ids  = array_filter($ids);
            $type = trim($_POST['holiday_type'] ?? '');

            if (empty($ids)) holRedirect($back, false, 'No holidays selected.');
            if (!in_array($type, ['REGULAR', 'SPECIAL', 'SCHOOL'], true)) {
                holRedirect($back, false, 'Invalid holiday type.');
            }

            $ph = implode(',', array_fill(0, count($ids), '?'));
            $pdo->prepare("UPDATE holidays SET holiday_type = ? WHERE holiday_id IN ({$ph})")
                ->execute(array_merge([$type], array_values($ids)));

            $typeLabel = ['REGULAR' => 'Regular', 'SPECIAL' => 'Special', 'SCHOOL' => 'School'][$type];
            holRedirect($back, true, count($ids) . " holiday(s) changed to {$typeLabel}.");
        }

        default:
            holRedirect($back, false, 'Unknown action.');
    }

} catch (PDOException $e) {
    holRedirect($back, false, 'Database error: ' . $e->getMessage());
}
