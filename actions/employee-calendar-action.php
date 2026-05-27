<?php
/**
 * actions/employee-calendar-action.php
 * Employee Portal — Calendar CRUD + data-fetch endpoint.
 * All queries are anchored to the session employee_id.
 */
require_once __DIR__ . '/../config/config.php';
require_once __DIR__ . '/../includes/auth.php';
requireEmployeeAjax();

header('Content-Type: application/json');

$empId  = (int)($_SESSION['user']['employee_id'] ?? 0);
$action = $_POST['action'] ?? $_GET['action'] ?? '';

$hasTable = (bool)$pdo->query("
    SELECT COUNT(*) FROM information_schema.TABLES
    WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'employee_calendar_entries'
")->fetchColumn();

// Check if reminder columns exist (migration 027)
$hasReminderCols = $hasTable && (bool)$pdo->query("
    SELECT COUNT(*) FROM information_schema.COLUMNS
    WHERE TABLE_SCHEMA = DATABASE()
      AND TABLE_NAME   = 'employee_calendar_entries'
      AND COLUMN_NAME  = 'reminder_offset'
")->fetchColumn();

function out(array $d): void { echo json_encode($d); exit; }

/* ── GET: fetch calendar data for a date range ───────────────────────────── */
if ($action === 'get_entries') {
    $start = $_GET['start'] ?? '';
    $end   = $_GET['end']   ?? '';

    if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $start)) $start = date('Y-m-01');
    if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $end))   $end   = date('Y-m-t');

    // Holidays
    $hStmt = $pdo->prepare("
        SELECT holiday_id, holiday_name, holiday_date, holiday_type,
               COALESCE(notes, '') AS notes
        FROM holidays
        WHERE holiday_date BETWEEN ? AND ?
        ORDER BY holiday_date
    ");
    $hStmt->execute([$start, $end]);
    $holidays = $hStmt->fetchAll();

    // Personal entries (non-recurring in range + all recurring with base ≤ end)
    $entries = [];
    if ($hasTable) {
        $reminderSel = $hasReminderCols
            ? ", COALESCE(reminder_offset,'none') AS reminder_offset, notify_in_system"
            : ", 'none' AS reminder_offset, 0 AS notify_in_system";
        $eStmt = $pdo->prepare("
            SELECT entry_id, title, COALESCE(description,'') AS description,
                   type, entry_date, entry_time, recurrence, is_done
                   {$reminderSel}
            FROM employee_calendar_entries
            WHERE employee_id = ?
              AND (
                (recurrence = 'NONE' AND entry_date BETWEEN ? AND ?)
                OR (recurrence != 'NONE' AND entry_date <= ?)
              )
            ORDER BY entry_date, entry_time
        ");
        $eStmt->execute([$empId, $start, $end, $end]);
        $entries = $eStmt->fetchAll();
    }

    // Approved leave dates for this employee
    $leaveDates = [];
    try {
        $ldStmt = $pdo->prepare("
            SELECT lrd.leave_date,
                   lr.leave_id,
                   lt.leave_name,
                   COALESCE(lr.reason, '') AS reason
            FROM leave_request_dates lrd
            JOIN leave_requests lr ON lrd.leave_id = lr.leave_id
            JOIN leave_types lt    ON lr.leave_type_id = lt.leave_type_id
            WHERE lr.employee_id = ?
              AND lrd.status = 'APPROVED'
              AND lrd.leave_date BETWEEN ? AND ?
            ORDER BY lrd.leave_date
        ");
        $ldStmt->execute([$empId, $start, $end]);
        $leaveDates = $ldStmt->fetchAll();
    } catch (Exception $e) { /* leave_request_dates may not exist on older installs */ }

    // Released payroll periods for this employee
    $payrollReleases = [];
    try {
        $prStmt = $pdo->prepare("
            SELECT pr.payroll_id,
                   COALESCE(DATE(pr.released_at), pp.pay_period_end) AS release_date,
                   pp.period_name,
                   pp.pay_period_start,
                   pp.pay_period_end,
                   pr.net_pay
            FROM payroll_records pr
            JOIN payroll_periods pp ON pr.period_id = pp.period_id
            WHERE pr.employee_id = ?
              AND pp.status = 'RELEASED'
              AND COALESCE(DATE(pr.released_at), pp.pay_period_end) BETWEEN ? AND ?
            ORDER BY COALESCE(pr.released_at, pp.pay_period_end)
        ");
        $prStmt->execute([$empId, $start, $end]);
        $payrollReleases = $prStmt->fetchAll();
    } catch (Exception $e) { /* safe fallback */ }

    out([
        'success'          => true,
        'holidays'         => $holidays,
        'entries'          => $entries,
        'leave_dates'      => $leaveDates,
        'payroll_releases' => $payrollReleases,
    ]);
}

/* ── POST: save entry (create or update) ────────────────────────────────── */
if ($action === 'save_entry' && $_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!$hasTable) out(['success' => false, 'message' => 'Run migration 018 to enable personal calendar entries.']);

    $entryId        = (int)($_POST['entry_id']        ?? 0);
    $title          = trim($_POST['title']             ?? '');
    $desc           = trim($_POST['description']       ?? '');
    $type           = $_POST['type']                   ?? 'NOTE';
    $date           = $_POST['entry_date']             ?? '';
    $time           = trim($_POST['entry_time']        ?? '') ?: null;
    $recurrence     = $_POST['recurrence']             ?? 'NONE';
    $reminderOffset = $_POST['reminder_offset']        ?? 'none';
    $notifySystem   = isset($_POST['notify_in_system']) ? 1 : 0;

    if (!$title) out(['success' => false, 'message' => 'Title is required.']);
    if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $date)) out(['success' => false, 'message' => 'Invalid date.']);
    if (!in_array($type, ['NOTE','TODO','REMINDER']))               $type = 'NOTE';
    if (!in_array($recurrence, ['NONE','DAILY','WEEKLY','MONTHLY'])) $recurrence = 'NONE';
    $validOffsets = ['none','at_time','10min','30min','1hour','1day'];
    if (!in_array($reminderOffset, $validOffsets)) $reminderOffset = 'none';
    if ($reminderOffset === 'none') $notifySystem = 0;

    if ($entryId > 0) {
        // Verify ownership before updating
        $own = $pdo->prepare("SELECT employee_id FROM employee_calendar_entries WHERE entry_id = ?");
        $own->execute([$entryId]);
        $row = $own->fetch();
        if (!$row || (int)$row['employee_id'] !== $empId) {
            out(['success' => false, 'message' => 'Entry not found.']);
        }

        if ($hasReminderCols) {
            $pdo->prepare("
                UPDATE employee_calendar_entries
                SET title=?, description=?, type=?, entry_date=?, entry_time=?, recurrence=?,
                    reminder_offset=?, notify_in_system=?, reminder_sent=0
                WHERE entry_id=? AND employee_id=?
            ")->execute([$title, $desc, $type, $date, $time, $recurrence,
                         $reminderOffset, $notifySystem, $entryId, $empId]);
        } else {
            $pdo->prepare("
                UPDATE employee_calendar_entries
                SET title=?, description=?, type=?, entry_date=?, entry_time=?, recurrence=?
                WHERE entry_id=? AND employee_id=?
            ")->execute([$title, $desc, $type, $date, $time, $recurrence, $entryId, $empId]);
        }

        out(['success' => true, 'message' => 'Entry updated.']);
    } else {
        if ($hasReminderCols) {
            $pdo->prepare("
                INSERT INTO employee_calendar_entries
                    (employee_id, title, description, type, entry_date, entry_time, recurrence,
                     reminder_offset, notify_in_system)
                VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)
            ")->execute([$empId, $title, $desc, $type, $date, $time, $recurrence,
                         $reminderOffset, $notifySystem]);
        } else {
            $pdo->prepare("
                INSERT INTO employee_calendar_entries
                    (employee_id, title, description, type, entry_date, entry_time, recurrence)
                VALUES (?, ?, ?, ?, ?, ?, ?)
            ")->execute([$empId, $title, $desc, $type, $date, $time, $recurrence]);
        }

        out(['success' => true, 'message' => 'Entry added.', 'entry_id' => (int)$pdo->lastInsertId()]);
    }
}

/* ── POST: delete entry ─────────────────────────────────────────────────── */
if ($action === 'delete_entry' && $_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!$hasTable) out(['success' => false, 'message' => 'Feature not available.']);

    $entryId = (int)($_POST['entry_id'] ?? 0);
    if (!$entryId) out(['success' => false, 'message' => 'Missing entry ID.']);

    $stmt = $pdo->prepare("
        DELETE FROM employee_calendar_entries WHERE entry_id = ? AND employee_id = ?
    ");
    $stmt->execute([$entryId, $empId]);

    if ($stmt->rowCount() === 0) out(['success' => false, 'message' => 'Entry not found.']);
    out(['success' => true, 'message' => 'Entry deleted.']);
}

/* ── POST: toggle todo done/undone ──────────────────────────────────────── */
if ($action === 'toggle_done' && $_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!$hasTable) out(['success' => false, 'message' => 'Feature not available.']);

    $entryId = (int)($_POST['entry_id'] ?? 0);
    if (!$entryId) out(['success' => false, 'message' => 'Missing entry ID.']);

    $pdo->prepare("
        UPDATE employee_calendar_entries
        SET is_done = 1 - is_done
        WHERE entry_id = ? AND employee_id = ? AND type = 'TODO'
    ")->execute([$entryId, $empId]);

    out(['success' => true]);
}

out(['success' => false, 'message' => 'Invalid action.']);
