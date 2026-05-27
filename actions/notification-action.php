<?php
/**
 * actions/notification-action.php
 * In-system notification AJAX handler.
 *
 * GET  action=get_preview  → unread_count + latest 8 notifications (also fires reminder check)
 * GET  action=get_all      → paginated list (filter: all|unread|read)
 * POST action=mark_read    → marks one notification read
 * POST action=mark_all_read → marks all for current user read
 */
require_once __DIR__ . '/../config/config.php';
require_once __DIR__ . '/../includes/auth.php';
requireLogin();

header('Content-Type: application/json');

$userId = (int)($_SESSION['user']['user_id']     ?? 0);
$empId  = (int)($_SESSION['user']['employee_id'] ?? 0);
$action = $_POST['action'] ?? $_GET['action'] ?? '';

function notifOut(array $d): void { echo json_encode($d); exit; }

// ── Check if notifications table exists ───────────────────────────────────────
$hasNotifTable = (bool)$GLOBALS['pdo']->query("
    SELECT COUNT(*) FROM information_schema.TABLES
    WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'notifications'
")->fetchColumn();

if (!$hasNotifTable) {
    notifOut(['success' => false, 'message' => 'Run migration 027 to enable notifications.', 'unread_count' => 0, 'notifications' => []]);
}

// ── Reminder check (lazy, called during get_preview) ─────────────────────────
function checkCalendarReminders(PDO $pdo, int $userId, int $empId): void
{
    if (!$empId) return;

    // Check if calendar table + reminder columns exist
    $hasTable = (bool)$pdo->query("
        SELECT COUNT(*) FROM information_schema.TABLES
        WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'employee_calendar_entries'
    ")->fetchColumn();
    if (!$hasTable) return;

    $hasReminderCol = (bool)$pdo->query("
        SELECT COUNT(*) FROM information_schema.COLUMNS
        WHERE TABLE_SCHEMA = DATABASE()
          AND TABLE_NAME = 'employee_calendar_entries'
          AND COLUMN_NAME = 'reminder_offset'
    ")->fetchColumn();
    if (!$hasReminderCol) return;

    // Find entries with pending reminders whose trigger time has passed
    $now = date('Y-m-d H:i:s');
    $stmt = $pdo->prepare("
        SELECT entry_id, title, entry_date, entry_time, reminder_offset
        FROM employee_calendar_entries
        WHERE employee_id     = ?
          AND notify_in_system = 1
          AND reminder_sent   = 0
          AND reminder_offset != 'none'
          AND entry_date      >= CURDATE()
    ");
    $stmt->execute([$empId]);
    $entries = $stmt->fetchAll();

    foreach ($entries as $e) {
        $baseTime  = $e['entry_date'] . ' ' . ($e['entry_time'] ?: '00:00:00');
        $baseDt    = new DateTime($baseTime);

        $offsets = [
            'at_time' => 0,
            '10min'   => -10,
            '30min'   => -30,
            '1hour'   => -60,
            '1day'    => -1440,
        ];
        $minutes = $offsets[$e['reminder_offset']] ?? null;
        if ($minutes === null) continue;

        $triggerDt = clone $baseDt;
        if ($minutes !== 0) {
            $triggerDt->modify("{$minutes} minutes");
        }

        if ($triggerDt->format('Y-m-d H:i:s') <= $now) {
            $dateLabel  = (new DateTime($e['entry_date']))->format('M j, Y');
            $timeLabel  = $e['entry_time'] ? date('g:i A', strtotime($e['entry_time'])) : '';
            $msgParts   = ["Scheduled for {$dateLabel}"];
            if ($timeLabel) $msgParts[] = "at {$timeLabel}";

            try {
                $pdo->prepare("
                    INSERT INTO notifications (user_id, title, message, type, link_url, related_id)
                    VALUES (?, ?, ?, 'calendar',
                            ?, ?)
                ")->execute([
                    $userId,
                    $e['title'],
                    implode(' ', $msgParts),
                    BASE_URL . 'modules/employee/calendar/index.php',
                    (int)$e['entry_id'],
                ]);

                $pdo->prepare("
                    UPDATE employee_calendar_entries SET reminder_sent = 1 WHERE entry_id = ?
                ")->execute([$e['entry_id']]);
            } catch (Exception $ignored) {
                // Silent — don't break the page if notification insert fails
            }
        }
    }
}

// ── GET: preview (unread count + latest 8) ────────────────────────────────────
if ($action === 'get_preview') {
    // Fire reminder check on every preview poll
    checkCalendarReminders($pdo, $userId, $empId);

    $cntStmt = $pdo->prepare("SELECT COUNT(*) FROM notifications WHERE user_id = ? AND is_read = 0");
    $cntStmt->execute([$userId]);
    $unread = (int)$cntStmt->fetchColumn();

    $listStmt = $pdo->prepare("
        SELECT notification_id, title, message, type, link_url, is_read, created_at
        FROM notifications
        WHERE user_id = ?
        ORDER BY created_at DESC
        LIMIT 8
    ");
    $listStmt->execute([$userId]);
    $notifications = $listStmt->fetchAll();

    notifOut(['success' => true, 'unread_count' => $unread, 'notifications' => $notifications]);
}

// ── GET: all notifications (paginated, filterable) ────────────────────────────
if ($action === 'get_all') {
    $filter = $_GET['filter'] ?? 'all';
    $page   = max(1, (int)($_GET['page'] ?? 1));
    $limit  = 20;
    $offset = ($page - 1) * $limit;

    $where = 'WHERE user_id = ?';
    if ($filter === 'unread') $where .= ' AND is_read = 0';
    if ($filter === 'read')   $where .= ' AND is_read = 1';

    $totalStmt = $pdo->prepare("SELECT COUNT(*) FROM notifications {$where}");
    $totalStmt->execute([$userId]);
    $total = (int)$totalStmt->fetchColumn();

    $listStmt = $pdo->prepare("
        SELECT notification_id, title, message, type, link_url, is_read, created_at
        FROM notifications {$where}
        ORDER BY created_at DESC
        LIMIT {$limit} OFFSET {$offset}
    ");
    $listStmt->execute([$userId]);
    $notifications = $listStmt->fetchAll();

    $cntStmt = $pdo->prepare("SELECT COUNT(*) FROM notifications WHERE user_id = ? AND is_read = 0");
    $cntStmt->execute([$userId]);
    $unread = (int)$cntStmt->fetchColumn();

    notifOut([
        'success'       => true,
        'notifications' => $notifications,
        'unread_count'  => $unread,
        'total'         => $total,
        'page'          => $page,
        'pages'         => (int)ceil($total / $limit),
    ]);
}

// ── POST: mark one notification read ─────────────────────────────────────────
if ($action === 'mark_read' && $_SERVER['REQUEST_METHOD'] === 'POST') {
    $nId = (int)($_POST['notification_id'] ?? 0);
    if (!$nId) notifOut(['success' => false, 'message' => 'Missing notification_id.']);

    $stmt = $pdo->prepare("
        UPDATE notifications SET is_read = 1 WHERE notification_id = ? AND user_id = ?
    ");
    $stmt->execute([$nId, $userId]);

    notifOut(['success' => true]);
}

// ── POST: mark all read ───────────────────────────────────────────────────────
if ($action === 'mark_all_read' && $_SERVER['REQUEST_METHOD'] === 'POST') {
    $stmt = $pdo->prepare("
        UPDATE notifications SET is_read = 1 WHERE user_id = ? AND is_read = 0
    ");
    $stmt->execute([$userId]);

    notifOut(['success' => true, 'marked' => $stmt->rowCount()]);
}

notifOut(['success' => false, 'message' => 'Unknown action.']);
