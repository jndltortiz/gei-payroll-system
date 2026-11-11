<?php
require_once __DIR__ . '/../../includes/auth.php';
header('Cache-Control: no-store');

$flash = getFlash();
$today = date('Y-m-d');
$tab = ($_GET['tab'] ?? 'kiosk') === 'records' ? 'records' : 'kiosk';
$allDates = isset($_GET['all_dates']) && $_GET['all_dates'] === '1';
$selectedDate = preg_match('/^\d{4}-\d{2}-\d{2}$/', (string)($_GET['date'] ?? ''))
    ? (string)$_GET['date'] : $today;
$recordSearch = trim((string)($_GET['search'] ?? ''));

$todayStmt = $pdo->prepare("SELECT
    COUNT(CASE WHEN time_in IS NOT NULL AND time_out IS NULL THEN 1 END) AS currently_in,
    COUNT(CASE WHEN time_out IS NOT NULL THEN 1 END) AS timed_out
    FROM attendance_records WHERE attendance_date = ?");
$todayStmt->execute([$today]);
$todaySummary = $todayStmt->fetch(PDO::FETCH_ASSOC) ?: ['currently_in' => 0, 'timed_out' => 0];
$activeEmployeeCount = (int)$pdo->query("SELECT COUNT(*) FROM employees WHERE employee_status = 'ACTIVE'")->fetchColumn();
$timedInEmployeeCount = (int)$pdo->query("SELECT COUNT(DISTINCT employee_id) FROM attendance_records WHERE attendance_date = " . $pdo->quote($today) . " AND time_in IS NOT NULL")->fetchColumn();
$notYetIn = max(0, $activeEmployeeCount - $timedInEmployeeCount);

$employeeStmt = $pdo->prepare("SELECT e.employee_id, e.employee_no, e.first_name, e.last_name,
        ar.time_in, ar.time_out, ar.attendance_status
    FROM employees e
    LEFT JOIN attendance_records ar ON ar.employee_id = e.employee_id AND ar.attendance_date = ?
    WHERE e.employee_status = 'ACTIVE' ORDER BY e.last_name, e.first_name");
$employeeStmt->execute([$today]);
$employeesToday = $employeeStmt->fetchAll(PDO::FETCH_ASSOC);

$recordSql = "SELECT aal.audit_id, ar.attendance_date, e.employee_no, e.first_name, e.last_name,
        CASE WHEN aal.action_type = 'TIMEOUT' OR aal.new_time_out IS NOT NULL
             THEN aal.new_time_out ELSE aal.new_time_in END AS transaction_time,
        CASE WHEN aal.action_type = 'TIMEOUT' OR aal.new_time_out IS NOT NULL
             THEN 'Time Out' ELSE 'Time In' END AS record_type
    FROM attendance_audit_log aal
    JOIN attendance_records ar ON ar.attendance_id = aal.attendance_id
    JOIN employees e ON e.employee_id = ar.employee_id
    WHERE (
        (aal.new_method = 'RFID' AND (aal.new_time_in IS NOT NULL OR aal.new_time_out IS NOT NULL))
        OR aal.reason IN ('RFID kiosk time-in', 'RFID kiosk time-out')
    )";
$recordParams = [];
if (!$allDates) { $recordSql .= ' AND ar.attendance_date = ?'; $recordParams[] = $selectedDate; }
if ($recordSearch !== '') {
    $recordSql .= ' AND (e.first_name LIKE ? OR e.last_name LIKE ? OR e.employee_no LIKE ? OR CONCAT(e.first_name, \' \', e.last_name) LIKE ?)';
    $searchLike = '%' . $recordSearch . '%';
    array_push($recordParams, $searchLike, $searchLike, $searchLike, $searchLike);
}
$recordSql .= ' ORDER BY ar.attendance_date DESC, transaction_time DESC, e.last_name, e.first_name';
$recordStmt = $pdo->prepare($recordSql);
$recordStmt->execute($recordParams);
$records = $recordStmt->fetchAll(PDO::FETCH_ASSOC);

$statusLabel = static function (?string $status, ?string $timeIn, ?string $timeOut): array {
    if (!$timeIn) return ['Not Yet In', 'pending'];
    if ($timeOut) return ['Timed Out', 'complete'];
    $labels = ['PRESENT' => 'Currently In', 'LATE' => 'Late', 'HALF_DAY' => 'Half Day'];
    return [$labels[$status ?? ''] ?? 'Currently In', strtolower($status ?: 'present')];
};
$formatTime = static fn (?string $time): string => $time ? date('g:i A', strtotime($time)) : '—';
$formatDate = static fn (?string $date): string => $date ? date('M j, Y', strtotime($date)) : '—';

$pageTitle = 'RFID Attendance';
$assetVersion = static fn (string $path): string => BASE_URL . $path . '?v=' . (@filemtime(__DIR__ . '/../../' . $path) ?: '1');
$extraCSS = [$assetVersion('assets/css/rfid-kiosk.css')];
?>
<!doctype html>
<html lang="en">
<head>
    <?php include __DIR__ . '/../../includes/head.php'; ?>
</head>
<body class="rfid-dashboard-body">
<main class="rfid-dashboard">
    <header class="rfid-header">
        <div>
            <a class="rfid-brand" href="<?= BASE_URL ?>modules/auth/login.php"><span class="rfid-brand-mark"><i class="fa fa-id-card"></i></span><span>GEI Attendance</span></a>
            <h1>RFID Attendance</h1>
            <p>Tap your card once to time in, and again when you leave to time out</p>
        </div>
        <a class="rfid-back-link" href="<?= BASE_URL ?>modules/auth/login.php"><i class="fa fa-arrow-left"></i> Back to Login</a>
    </header>

    <nav class="rfid-tabs" aria-label="Attendance sections">
        <a class="rfid-tab <?= $tab === 'kiosk' ? 'is-active' : '' ?>" href="?tab=kiosk" aria-current="<?= $tab === 'kiosk' ? 'page' : 'false' ?>"><i class="fa fa-id-card"></i> Kiosk</a>
        <a class="rfid-tab <?= $tab === 'records' ? 'is-active' : '' ?>" href="?tab=records" aria-current="<?= $tab === 'records' ? 'page' : 'false' ?>"><i class="fa fa-list-check"></i> Records</a>
    </nav>

    <?php if ($flash): ?><div class="rfid-alert <?= htmlspecialchars($flash['type'], ENT_QUOTES, 'UTF-8') ?>" role="alert"><i class="fa <?= $flash['type'] === 'danger' ? 'fa-circle-exclamation' : 'fa-circle-check' ?>"></i><span><?= htmlspecialchars($flash['message'], ENT_QUOTES, 'UTF-8') ?></span></div><?php endif; ?>

    <?php if ($tab === 'kiosk'): ?>
    <section class="rfid-kiosk-grid" aria-label="RFID kiosk dashboard">
        <article class="rfid-panel rfid-reader-panel">
            <div class="panel-heading"><div><span class="eyebrow">CARD READER</span><h2>Scan your RFID card</h2></div><span class="panel-heading-icon"><i class="fa fa-wifi"></i></span></div>
            <form class="rfid-form" action="<?= BASE_URL ?>actions/rfid-attendance.php" method="post">
                <input type="hidden" name="csrf_token" value="<?= htmlspecialchars(csrfToken(), ENT_QUOTES, 'UTF-8') ?>">
                <?php /* Card readers press Enter after the UID. A disabled first submit
                         button stops that Enter from defaulting to "Time In" (HTML
                         implicit-submission rule); rfid-kiosk.js submits the tap as "auto". */ ?>
                <button type="submit" disabled hidden aria-hidden="true" tabindex="-1"></button>
                <label class="sr-only" for="rfid_uid">RFID card number</label>
                <div class="rfid-input-wrap"><i class="fa fa-id-card input-icon"></i><input id="rfid_uid" name="rfid_uid" type="text" maxlength="100" autocomplete="off" autofocus required placeholder="Card reader input. Click here if tapping does nothing."></div>
                <div class="rfid-action-row"><button type="submit" name="attendance_action" value="time_in" class="rfid-action primary"><i class="fa fa-arrow-right-to-bracket"></i> Time In</button><button type="submit" name="attendance_action" value="time_out" class="rfid-action secondary"><i class="fa fa-arrow-right-from-bracket"></i> Time Out</button></div>
            </form>
            <div class="rfid-status-panel <?= $flash ? htmlspecialchars($flash['type'], ENT_QUOTES, 'UTF-8') : '' ?>" aria-live="polite"><span class="rfid-status-icon"><i class="fa <?= $flash && $flash['type'] === 'danger' ? 'fa-triangle-exclamation' : 'fa-id-card' ?>"></i></span><div><span class="status-kicker"><?= $flash ? ($flash['type'] === 'danger' ? 'Attention' : 'Attendance updated') : 'Ready' ?></span><strong><?= $flash ? htmlspecialchars($flash['message'], ENT_QUOTES, 'UTF-8') : 'Tap your card' ?></strong><small><?= $flash ? 'The reader is ready for the next scan.' : 'Waiting for the reader' ?></small></div></div>
            <p class="reader-help"><i class="fa fa-circle-info"></i> First tap of the day records Time In; tapping again at least 5 minutes later records Time Out. Use the buttons only when typing a card number.</p>
        </article>

        <article class="rfid-panel rfid-summary-panel">
            <div class="panel-heading"><div><span class="eyebrow">TODAY</span><h2>Today's Attendance</h2></div><span class="today-label"><?= $formatDate($today) ?></span></div>
            <div class="rfid-stat-grid"><div class="rfid-stat"><strong><?= (int)$todaySummary['currently_in'] ?></strong><span>Currently In</span></div><div class="rfid-stat"><strong><?= (int)$todaySummary['timed_out'] ?></strong><span>Timed Out</span></div><div class="rfid-stat"><strong><?= $notYetIn ?></strong><span>Not Yet In</span></div></div>
            <div class="employee-list-heading"><span>Employee status</span><span><?= count($employeesToday) ?> active</span></div>
            <div class="employee-list"><?php if (!$employeesToday): ?><div class="empty-state">No active employees found.</div><?php else: foreach ($employeesToday as $employee): [$label, $class] = $statusLabel($employee['attendance_status'], $employee['time_in'], $employee['time_out']); ?><div class="employee-row"><span class="employee-avatar"><?= htmlspecialchars(strtoupper(substr($employee['first_name'], 0, 1) . substr($employee['last_name'], 0, 1)), ENT_QUOTES, 'UTF-8') ?></span><span class="employee-identity"><strong><?= htmlspecialchars(trim($employee['first_name'] . ' ' . $employee['last_name']), ENT_QUOTES, 'UTF-8') ?></strong><small><?= htmlspecialchars($employee['employee_no'] ?: 'No ID', ENT_QUOTES, 'UTF-8') ?></small></span><span class="status-badge <?= htmlspecialchars($class, ENT_QUOTES, 'UTF-8') ?>"><?= htmlspecialchars($label, ENT_QUOTES, 'UTF-8') ?></span></div><?php endforeach; endif; ?></div>
        </article>
    </section>
    <?php else: ?>
    <section class="rfid-records-section" aria-labelledby="records-title">
        <div class="records-toolbar"><div><span class="eyebrow">HISTORY</span><h2 id="records-title">Attendance History</h2></div><form class="records-filters" method="get"><input type="hidden" name="tab" value="records"><input type="hidden" name="all_dates" value="<?= $allDates ? '1' : '0' ?>"><label for="record-date">Date</label><input id="record-date" name="date" type="date" value="<?= htmlspecialchars($selectedDate, ENT_QUOTES, 'UTF-8') ?>" <?= $allDates ? 'disabled' : '' ?>><label class="search-label" for="record-search">Search</label><input id="record-search" name="search" type="search" value="<?= htmlspecialchars($recordSearch, ENT_QUOTES, 'UTF-8') ?>" placeholder="Name or ID"><button class="outline-button" type="submit">Apply</button><a class="soft-button" href="?tab=records&amp;all_dates=1">Show All Dates</a><button class="soft-button" id="copyRecords" type="button"><i class="fa fa-copy"></i> Copy as CSV</button><button class="danger-button" id="clearRecords" type="button"><i class="fa fa-eraser"></i> Clear Records</button></form></div>
        <div class="records-table-wrap"><table class="records-table" id="recordsTable"><thead><tr><th>Employee</th><th>Employee ID</th><th>Date</th><th>Time</th><th>Record Type</th></tr></thead><tbody><?php if (!$records): ?><tr><td colspan="5"><div class="empty-state">No attendance records yet.</div></td></tr><?php else: foreach ($records as $record): ?><tr><td><?= htmlspecialchars(trim($record['first_name'] . ' ' . $record['last_name']), ENT_QUOTES, 'UTF-8') ?></td><td><?= htmlspecialchars($record['employee_no'] ?: 'No ID', ENT_QUOTES, 'UTF-8') ?></td><td><?= $formatDate($record['attendance_date']) ?></td><td><?= $formatTime($record['transaction_time']) ?></td><td><span class="status-badge <?= $record['record_type'] === 'Time In' ? 'time-in' : 'time-out' ?>"><?= htmlspecialchars($record['record_type'], ENT_QUOTES, 'UTF-8') ?></span></td></tr><?php endforeach; endif; ?></tbody></table><div class="records-cleared empty-state" id="recordsCleared" hidden>No attendance records yet.</div></div>
    </section>
    <?php endif; ?>
</main>
<script src="<?= htmlspecialchars($assetVersion('assets/js/rfid-kiosk.js'), ENT_QUOTES, 'UTF-8') ?>"></script>
</body>
</html>
