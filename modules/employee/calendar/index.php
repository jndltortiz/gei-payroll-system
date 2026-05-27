<?php
/**
 * modules/employee/calendar/index.php
 * Employee Portal — My Calendar
 * Views: Month (default) | Week | Day | Year
 * Data: Holidays from system + personal notes/todos/reminders
 */
require_once __DIR__ . '/../../../config/config.php';
require_once __DIR__ . '/../../../includes/auth.php';
requireEmployeeAccess();

$empId = (int)($_SESSION['user']['employee_id'] ?? 0);
$today = date('Y-m-d');

$hasTable = (bool)$pdo->query("
    SELECT COUNT(*) FROM information_schema.TABLES
    WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'employee_calendar_entries'
")->fetchColumn();

// ── Initial data: current month ───────────────────────────────────────────────
$initStart = date('Y-m-01');
$initEnd   = date('Y-m-t');

$hStmt = $pdo->prepare("
    SELECT holiday_id, holiday_name, holiday_date, holiday_type,
           COALESCE(notes,'') AS notes
    FROM holidays
    WHERE holiday_date BETWEEN ? AND ?
    ORDER BY holiday_date
");
$hStmt->execute([$initStart, $initEnd]);
$initHolidays = $hStmt->fetchAll();

$initEntries = [];
if ($hasTable) {
    $eStmt = $pdo->prepare("
        SELECT entry_id, title, COALESCE(description,'') AS description,
               type, entry_date, entry_time, recurrence, is_done
        FROM employee_calendar_entries
        WHERE employee_id = ?
          AND (
            (recurrence = 'NONE' AND entry_date BETWEEN ? AND ?)
            OR (recurrence != 'NONE' AND entry_date <= ?)
          )
        ORDER BY entry_date, entry_time
    ");
    $eStmt->execute([$empId, $initStart, $initEnd, $initEnd]);
    $initEntries = $eStmt->fetchAll();
}

// Initial: approved leave dates for this month
$initLeaveDates = [];
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
    $ldStmt->execute([$empId, $initStart, $initEnd]);
    $initLeaveDates = $ldStmt->fetchAll();
} catch (Exception $e) {}

// Initial: released payroll periods for this month
$initPayrollReleases = [];
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
    $prStmt->execute([$empId, $initStart, $initEnd]);
    $initPayrollReleases = $prStmt->fetchAll();
} catch (Exception $e) {}

$pageTitle = 'My Calendar — Employee Portal';
$extraCSS  = [BASE_URL . 'assets/css/employee-portal.css'];
require_once __DIR__ . '/../../../includes/head.php';
?>
<style>
/* ── Employee Calendar styles (ecal- prefix) ─────────────────────────────────── */
.emp-page { max-width: 1240px; }

/* Toolbar */
.ecal-toolbar {
    display: flex;
    align-items: center;
    gap: 10px;
    margin-bottom: 14px;
    flex-wrap: wrap;
}
.ecal-view-switcher {
    display: flex;
    border: 1px solid #e2e8f0;
    border-radius: 8px;
    overflow: hidden;
    flex-shrink: 0;
}
.ecal-view-btn {
    padding: 7px 15px;
    background: #fff;
    border: none;
    border-right: 1px solid #e2e8f0;
    cursor: pointer;
    font-size: 12px;
    font-weight: 600;
    color: #64748b;
    font-family: inherit;
    transition: background .15s, color .15s;
}
.ecal-view-btn:last-child { border-right: none; }
.ecal-view-btn.active { background: var(--accent); color: #fff; }
.ecal-view-btn:not(.active):hover { background: #f1f5f9; color: var(--sidebar-bg); }

.ecal-nav-group {
    display: flex;
    align-items: center;
    gap: 4px;
    flex-shrink: 0;
}
.ecal-nav-btn {
    width: 30px;
    height: 30px;
    border-radius: 7px;
    border: 1px solid #e2e8f0;
    background: #fff;
    cursor: pointer;
    display: flex;
    align-items: center;
    justify-content: center;
    color: #374151;
    font-size: 12px;
    transition: all .15s;
}
.ecal-nav-btn:hover { background: var(--accent-light); border-color: var(--accent-mid); color: var(--accent); }
.ecal-today-btn {
    padding: 6px 13px;
    border-radius: 7px;
    border: 1px solid var(--accent);
    background: var(--accent-light);
    color: var(--accent);
    font-size: 12px;
    font-weight: 600;
    cursor: pointer;
    font-family: inherit;
    transition: all .15s;
}
.ecal-today-btn:hover { background: var(--accent); color: #fff; }
.ecal-date-display {
    font-size: 15px;
    font-weight: 700;
    color: #0f172a;
    margin-left: 4px;
    flex: 1;
}

/* Add button */
.ecal-btn-add {
    display: inline-flex;
    align-items: center;
    gap: 7px;
    padding: 8px 16px;
    background: var(--accent);
    color: #fff;
    border: none;
    border-radius: 8px;
    font-size: 13px;
    font-weight: 600;
    cursor: pointer;
    font-family: inherit;
    transition: background .15s;
    white-space: nowrap;
}
.ecal-btn-add:hover { background: #17a085; }

/* Legend */
.ecal-legend {
    display: flex;
    flex-wrap: wrap;
    gap: 14px;
    margin-bottom: 14px;
    padding: 10px 14px;
    background: #fff;
    border: 1px solid #e2e8f0;
    border-radius: 10px;
}
.ecal-legend-item {
    display: flex;
    align-items: center;
    gap: 6px;
    font-size: 12px;
    color: #475569;
    font-weight: 500;
}
.ecal-dot {
    width: 9px;
    height: 9px;
    border-radius: 50%;
    display: inline-block;
    flex-shrink: 0;
}
.ecal-legend-holiday-regular .ecal-dot { background: #ef4444; }
.ecal-legend-holiday-special .ecal-dot { background: #f97316; }
.ecal-legend-holiday-school  .ecal-dot { background: #8b5cf6; }
.ecal-legend-entry-note      .ecal-dot { background: var(--accent); }
.ecal-legend-entry-todo      .ecal-dot { background: #059669; }
.ecal-legend-entry-reminder  .ecal-dot { background: #d97706; }
.ecal-legend-leave           .ecal-dot { background: #0d9488; }
.ecal-legend-payroll         .ecal-dot { background: #4f46e5; }

/* Calendar card */
.ecal-card {
    background: #fff;
    border: 1px solid #e2e8f0;
    border-radius: 14px;
    overflow: hidden;
    box-shadow: 0 1px 4px rgba(0,0,0,.06);
}

/* Loading spinner */
.ecal-loading {
    text-align: center;
    padding: 70px 0;
    color: #94a3b8;
    font-size: 14px;
}

/* ── Month view ───────────────────────────────────────────────────────────── */
.ecal-month-head {
    display: grid;
    grid-template-columns: repeat(7,1fr);
    background: #f8fafc;
    border-bottom: 1px solid #e2e8f0;
}
.ecal-day-label {
    text-align: center;
    padding: 10px 4px;
    font-size: 11px;
    font-weight: 700;
    color: #94a3b8;
    letter-spacing: .5px;
    text-transform: uppercase;
}
.ecal-month-grid { display: grid; grid-template-columns: repeat(7,1fr); }
.ecal-cell {
    min-height: 105px;
    border-right: 1px solid #f1f5f9;
    border-bottom: 1px solid #f1f5f9;
    padding: 5px 5px 4px;
    position: relative;
    transition: background .1s;
    cursor: pointer;
    vertical-align: top;
}
.ecal-cell:hover { background: #f8fafc; }
.ecal-cell.other-month { background: #fafafa; }
.ecal-cell.today { background: var(--accent-light); }
.ecal-cell.today .ecal-cell-num { background: var(--accent); color: #fff; border-radius: 50%; }
.ecal-cell-num {
    display: inline-flex;
    align-items: center;
    justify-content: center;
    width: 26px;
    height: 26px;
    font-size: 13px;
    font-weight: 600;
    color: #374151;
    margin-bottom: 3px;
}
.ecal-cell.other-month .ecal-cell-num { color: #cbd5e1; }

/* Event pills inside cells */
.ecal-pill {
    display: block;
    margin: 2px 0;
    padding: 2px 7px;
    border-radius: 4px;
    font-size: 11px;
    font-weight: 600;
    white-space: nowrap;
    overflow: hidden;
    text-overflow: ellipsis;
    cursor: pointer;
    line-height: 1.5;
    transition: opacity .1s;
}
.ecal-pill:hover { opacity: .8; }
.ecal-pill.holiday-regular { background: #fee2e2; color: #b91c1c; }
.ecal-pill.holiday-special { background: #ffedd5; color: #c2410c; }
.ecal-pill.holiday-school  { background: #ede9fe; color: #6d28d9; }
.ecal-pill.entry-NOTE      { background: var(--accent-light); color: var(--accent); }
.ecal-pill.entry-TODO      { background: #d1fae5; color: #047857; }
.ecal-pill.entry-TODO.done { background: #f1f5f9; color: #94a3b8; text-decoration: line-through; }
.ecal-pill.entry-REMINDER  { background: #fef3c7; color: #92400e; }
.ecal-pill.leave-date      { background: #ccfbf1; color: #0d9488; }
.ecal-pill.payroll-release { background: #ede9fe; color: #4f46e5; }
.ecal-more {
    font-size: 10px;
    color: var(--accent);
    cursor: pointer;
    font-weight: 600;
    margin-top: 2px;
    display: block;
    padding: 0 4px;
}
.ecal-more:hover { text-decoration: underline; }

/* ── Week view ────────────────────────────────────────────────────────────── */
.ecal-week-head {
    display: grid;
    grid-template-columns: repeat(7,1fr);
    border-bottom: 2px solid #e2e8f0;
}
.ecal-week-hcol {
    text-align: center;
    padding: 14px 6px 10px;
    border-right: 1px solid #f1f5f9;
}
.ecal-week-hcol:last-child { border-right: none; }
.ecal-week-hcol.today { background: var(--accent-light); }
.ecal-wh-day  { font-size: 10px; font-weight: 700; color: #94a3b8; text-transform: uppercase; letter-spacing: .5px; }
.ecal-wh-date { font-size: 22px; font-weight: 700; color: #374151; line-height: 1.15; margin: 2px 0; }
.ecal-wh-mon  { font-size: 11px; color: #94a3b8; }
.ecal-week-hcol.today .ecal-wh-date { color: var(--accent); }
.ecal-week-body { display: grid; grid-template-columns: repeat(7,1fr); min-height: 220px; }
.ecal-week-col {
    border-right: 1px solid #f1f5f9;
    padding: 8px 6px;
    cursor: pointer;
    transition: background .1s;
}
.ecal-week-col:last-child { border-right: none; }
.ecal-week-col:hover { background: #f8fafc; }
.ecal-week-col.today { background: var(--accent-light); }
.ecal-no-ev { color: #cbd5e1; font-size: 11px; text-align: center; padding: 18px 0; }

/* ── Day view ─────────────────────────────────────────────────────────────── */
.ecal-day-hdr {
    padding: 20px 24px 14px;
    border-bottom: 1px solid #e2e8f0;
    display: flex;
    align-items: center;
    justify-content: space-between;
    gap: 12px;
    flex-wrap: wrap;
}
.ecal-day-hdr-date { font-size: 19px; font-weight: 700; color: #0f172a; }
.ecal-day-hdr-sub  { font-size: 12px; color: #64748b; margin-top: 2px; }
.ecal-day-add-btn {
    display: inline-flex;
    align-items: center;
    gap: 6px;
    padding: 7px 14px;
    background: var(--accent);
    color: #fff;
    border: none;
    border-radius: 7px;
    font-size: 12px;
    font-weight: 600;
    cursor: pointer;
    font-family: inherit;
    transition: background .15s;
}
.ecal-day-add-btn:hover { background: #17a085; }
.ecal-day-events { padding: 12px 24px; }
.ecal-day-ev {
    display: flex;
    align-items: flex-start;
    gap: 12px;
    padding: 12px 14px;
    border-radius: 10px;
    margin-bottom: 8px;
    border: 1px solid #f1f5f9;
    cursor: pointer;
    transition: background .1s;
}
.ecal-day-ev:hover { background: #f8fafc; }
.ecal-day-ev-dot {
    width: 10px;
    height: 10px;
    border-radius: 50%;
    flex-shrink: 0;
    margin-top: 4px;
}
.ecal-day-ev-title { font-size: 14px; font-weight: 600; color: #0f172a; }
.ecal-day-ev-sub   { font-size: 12px; color: #64748b; margin-top: 3px; }
.ecal-day-ev-dot.holiday-regular { background: #ef4444; }
.ecal-day-ev-dot.holiday-special { background: #f97316; }
.ecal-day-ev-dot.holiday-school  { background: #8b5cf6; }
.ecal-day-ev-dot.entry-NOTE      { background: var(--accent); }
.ecal-day-ev-dot.entry-TODO      { background: #059669; }
.ecal-day-ev-dot.entry-REMINDER  { background: #d97706; }
.ecal-day-ev-dot.leave-date      { background: #0d9488; }
.ecal-day-ev-dot.payroll-release { background: #4f46e5; }
.ecal-day-empty {
    text-align: center;
    padding: 50px 20px;
    color: #94a3b8;
}
.ecal-day-empty p { margin-bottom: 14px; font-size: 14px; }

/* ── Year view ────────────────────────────────────────────────────────────── */
.ecal-year-grid {
    display: grid;
    grid-template-columns: repeat(4,1fr);
    gap: 1px;
    background: #f1f5f9;
}
.ecal-mini-month {
    background: #fff;
    padding: 14px 12px 10px;
}
.ecal-mini-title {
    font-size: 12px;
    font-weight: 700;
    color: #374151;
    text-align: center;
    margin-bottom: 6px;
    cursor: pointer;
    padding: 2px 4px;
    border-radius: 4px;
    transition: background .1s;
}
.ecal-mini-title:hover { background: var(--accent-light); color: var(--accent); }
.ecal-mini-grid {
    display: grid;
    grid-template-columns: repeat(7,1fr);
    gap: 1px;
}
.ecal-mini-dlabel {
    text-align: center;
    font-size: 8px;
    font-weight: 700;
    color: #cbd5e1;
    padding: 1px 0 3px;
    text-transform: uppercase;
}
.ecal-mini-day {
    text-align: center;
    font-size: 10px;
    color: #475569;
    padding: 2px 1px;
    border-radius: 50%;
    cursor: pointer;
    position: relative;
    line-height: 1.6;
    transition: background .1s;
}
.ecal-mini-day:hover { background: #f1f5f9; }
.ecal-mini-day.today { background: var(--accent); color: #fff; }
.ecal-mini-day.other { color: #e2e8f0; cursor: default; }
.ecal-mini-day.other:hover { background: transparent; }
.ecal-mini-day::after {
    content: '';
    display: block;
    width: 3px;
    height: 3px;
    border-radius: 50%;
    margin: 0 auto;
    background: transparent;
}
.ecal-mini-day.has-holiday::after { background: #ef4444; }
.ecal-mini-day.has-leave::after   { background: #0d9488; }
.ecal-mini-day.has-payroll::after { background: #4f46e5; }
.ecal-mini-day.has-entry::after   { background: var(--accent); }
.ecal-mini-day.today::after       { background: #fff; }

/* ── Modals ───────────────────────────────────────────────────────────────── */
.ecal-backdrop {
    display: none;
    position: fixed;
    inset: 0;
    background: rgba(0,0,0,.45);
    z-index: 1050;
    align-items: center;
    justify-content: center;
    padding: 16px;
}
.ecal-backdrop.open { display: flex; }
.ecal-modal {
    background: #fff;
    border-radius: 14px;
    width: 100%;
    max-width: 480px;
    max-height: 90vh;
    overflow-y: auto;
    box-shadow: 0 8px 32px rgba(0,0,0,.2);
}
.ecal-mhdr {
    display: flex;
    align-items: center;
    justify-content: space-between;
    padding: 18px 20px 14px;
    border-bottom: 1px solid #e2e8f0;
    position: sticky;
    top: 0;
    background: #fff;
    z-index: 1;
}
.ecal-mtitle { font-size: 15px; font-weight: 700; color: #0f172a; }
.ecal-mclose {
    width: 28px; height: 28px;
    border-radius: 6px;
    border: 1px solid #e2e8f0;
    background: #f8fafc;
    cursor: pointer;
    display: flex;
    align-items: center;
    justify-content: center;
    color: #64748b;
    font-size: 14px;
    transition: all .15s;
}
.ecal-mclose:hover { background: #fee2e2; border-color: #fca5a5; color: #dc2626; }
.ecal-mbody  { padding: 18px 20px; }
.ecal-mfooter {
    padding: 13px 20px;
    border-top: 1px solid #e2e8f0;
    display: flex;
    justify-content: flex-end;
    gap: 8px;
    flex-wrap: wrap;
}

/* Detail modal content */
.ecal-type-badge {
    display: inline-flex;
    align-items: center;
    gap: 5px;
    padding: 4px 11px;
    border-radius: 20px;
    font-size: 11px;
    font-weight: 700;
    margin-bottom: 12px;
}
.ecal-type-badge.holiday-regular { background: #fee2e2; color: #b91c1c; }
.ecal-type-badge.holiday-special { background: #ffedd5; color: #c2410c; }
.ecal-type-badge.holiday-school  { background: #ede9fe; color: #6d28d9; }
.ecal-type-badge.entry-NOTE      { background: var(--accent-light); color: var(--accent); }
.ecal-type-badge.entry-TODO      { background: #d1fae5; color: #047857; }
.ecal-type-badge.entry-REMINDER  { background: #fef3c7; color: #92400e; }
.ecal-type-badge.leave-date      { background: #ccfbf1; color: #0d9488; }
.ecal-type-badge.payroll-release { background: #ede9fe; color: #4f46e5; }
.ecal-detail-title { font-size: 18px; font-weight: 700; color: #0f172a; margin-bottom: 14px; line-height: 1.3; }
.ecal-detail-row   { display: flex; align-items: flex-start; gap: 10px; margin-bottom: 10px; font-size: 13px; }
.ecal-detail-icon  { color: #94a3b8; width: 15px; flex-shrink: 0; margin-top: 2px; }
.ecal-detail-val   { color: #374151; line-height: 1.5; }

/* Form inside modal */
.ecal-frow { margin-bottom: 14px; }
.ecal-frow:last-child { margin-bottom: 0; }
.ecal-flabel { display: block; font-size: 12px; font-weight: 600; color: #374151; margin-bottom: 5px; }
.ecal-finput, .ecal-fselect, .ecal-ftextarea {
    width: 100%;
    padding: 8px 10px;
    border: 1px solid #e2e8f0;
    border-radius: 8px;
    font-size: 13px;
    font-family: inherit;
    color: #0f172a;
    background: #fff;
    transition: border-color .15s;
    box-sizing: border-box;
}
.ecal-finput:focus, .ecal-fselect:focus, .ecal-ftextarea:focus {
    outline: none;
    border-color: var(--accent);
    box-shadow: 0 0 0 3px rgba(29,184,154,.12);
}
.ecal-ftextarea { resize: vertical; min-height: 68px; }
.ecal-frow-2 { display: grid; grid-template-columns: 1fr 1fr; gap: 10px; }

/* Buttons */
.ecal-btn {
    padding: 8px 15px;
    border-radius: 7px;
    font-size: 12px;
    font-weight: 600;
    cursor: pointer;
    border: 1px solid transparent;
    font-family: inherit;
    transition: all .15s;
    white-space: nowrap;
}
.ecal-btn-primary   { background: var(--accent); color: #fff; border-color: var(--accent); }
.ecal-btn-primary:hover { background: #17a085; }
.ecal-btn-secondary { background: #f8fafc; color: #374151; border-color: #e2e8f0; }
.ecal-btn-secondary:hover { background: #e2e8f0; }
.ecal-btn-success   { background: #d1fae5; color: #047857; border-color: #a7f3d0; }
.ecal-btn-success:hover { background: #a7f3d0; }
.ecal-btn-danger    { background: #fee2e2; color: #dc2626; border-color: #fca5a5; }
.ecal-btn-danger:hover { background: #fecaca; }
.ecal-btn:disabled  { opacity: .6; cursor: not-allowed; }

/* ── Responsive ───────────────────────────────────────────────────────────── */
@media (max-width: 900px) {
    .ecal-year-grid { grid-template-columns: repeat(3,1fr); }
}
@media (max-width: 700px) {
    .ecal-cell { min-height: 70px; }
    .ecal-year-grid { grid-template-columns: repeat(2,1fr); }
    .ecal-toolbar { gap: 6px; }
    .ecal-view-btn { padding: 6px 10px; font-size: 11px; }
    .ecal-frow-2 { grid-template-columns: 1fr; }
}
@media (max-width: 480px) {
    .ecal-year-grid { grid-template-columns: 1fr; }
}
</style>
<body>
<div class="layout">
<?php
if (isAdmin()):
    include __DIR__ . '/../../../includes/sidebar.php';
elseif (isPrincipalRole()):
    include __DIR__ . '/../../../includes/principal-sidebar.php';
else:
    include __DIR__ . '/../../../includes/employee-sidebar.php';
endif;
?>

<div class="main">

  <?php $empPortalIcon = 'fa-calendar-days'; include __DIR__ . '/../../../includes/employee-header.php'; ?>

  <div class="main-content">
  <div class="emp-page">

    <!-- ── Greeting ─────────────────────────────────────────────────────────── -->
    <div class="emp-greeting">
      <div>
        <h1>My Calendar</h1>
        <p>Holidays, important dates, and personal notes</p>
      </div>
      <?php if ($hasTable): ?>
        <button class="ecal-btn-add" onclick="CAL.openAddModal(null)">
          <i class="fa fa-plus"></i> Add Entry
        </button>
      <?php else: ?>
        <div class="emp-greeting-pill" title="Run migrations/018_employee_calendar_entries.sql to enable">
          <i class="fa fa-calendar-check"></i> Holidays &amp; system dates visible
        </div>
      <?php endif; ?>
    </div>

    <!-- ── Toolbar ───────────────────────────────────────────────────────────── -->
    <div class="ecal-toolbar">
      <div class="ecal-view-switcher">
        <button class="ecal-view-btn active" id="btn-month" onclick="CAL.setView('month')">Month</button>
        <button class="ecal-view-btn"        id="btn-week"  onclick="CAL.setView('week')">Week</button>
        <button class="ecal-view-btn"        id="btn-day"   onclick="CAL.setView('day')">Day</button>
        <button class="ecal-view-btn"        id="btn-year"  onclick="CAL.setView('year')">Year</button>
      </div>

      <div class="ecal-nav-group">
        <button class="ecal-nav-btn" onclick="CAL.navigate(-1)" title="Previous"><i class="fa fa-chevron-left"></i></button>
        <button class="ecal-today-btn" onclick="CAL.goToday()">Today</button>
        <button class="ecal-nav-btn" onclick="CAL.navigate(1)"  title="Next"><i class="fa fa-chevron-right"></i></button>
      </div>

      <span class="ecal-date-display" id="ecal-label">Loading…</span>
    </div>

    <!-- ── Legend ────────────────────────────────────────────────────────────── -->
    <div class="ecal-legend">
      <span class="ecal-legend-item ecal-legend-holiday-regular"><span class="ecal-dot"></span>Regular Holiday</span>
      <span class="ecal-legend-item ecal-legend-holiday-special"><span class="ecal-dot"></span>Special Holiday</span>
      <span class="ecal-legend-item ecal-legend-holiday-school" ><span class="ecal-dot"></span>School Holiday</span>
      <span class="ecal-legend-item ecal-legend-leave"  ><span class="ecal-dot"></span>Approved Leave</span>
      <span class="ecal-legend-item ecal-legend-payroll"><span class="ecal-dot"></span>Payroll Released</span>
      <?php if ($hasTable): ?>
        <span class="ecal-legend-item ecal-legend-entry-note"    ><span class="ecal-dot"></span>Note</span>
        <span class="ecal-legend-item ecal-legend-entry-todo"    ><span class="ecal-dot"></span>To-Do</span>
        <span class="ecal-legend-item ecal-legend-entry-reminder"><span class="ecal-dot"></span>Reminder</span>
      <?php endif; ?>
    </div>

    <!-- ── Calendar area ─────────────────────────────────────────────────────── -->
    <div class="ecal-card">
      <div id="ecal-root">
        <div class="ecal-loading"><i class="fa fa-circle-notch fa-spin"></i></div>
      </div>
    </div>

  </div><!-- .emp-page -->
  </div><!-- .main-content -->
</div><!-- .main -->
</div><!-- .layout -->


<!-- ═══════════════════════════════════════════════════════════════════
     DETAIL MODAL  (holiday + entry view)
════════════════════════════════════════════════════════════════════ -->
<div class="ecal-backdrop" id="detail-modal" onclick="CAL.closeDetail(event)">
  <div class="ecal-modal" onclick="event.stopPropagation()">
    <div class="ecal-mhdr">
      <div class="ecal-mtitle">Event Details</div>
      <button class="ecal-mclose" onclick="CAL.closeDetail(null)"><i class="fa fa-times"></i></button>
    </div>
    <div class="ecal-mbody" id="detail-body"><!-- filled by JS --></div>
    <div class="ecal-mfooter" id="detail-footer"><!-- filled by JS --></div>
  </div>
</div>


<!-- ═══════════════════════════════════════════════════════════════════
     ADD / EDIT ENTRY MODAL
════════════════════════════════════════════════════════════════════ -->
<div class="ecal-backdrop" id="entry-modal" onclick="CAL.closeEntry(event)">
  <div class="ecal-modal" onclick="event.stopPropagation()">
    <div class="ecal-mhdr">
      <div class="ecal-mtitle" id="entry-modal-title">Add Calendar Entry</div>
      <button class="ecal-mclose" onclick="CAL.closeEntry(null)"><i class="fa fa-times"></i></button>
    </div>
    <form id="entry-form" onsubmit="event.preventDefault(); CAL.saveEntry();">
      <input type="hidden" name="entry_id" id="f-entry-id">
      <div class="ecal-mbody">

        <div class="ecal-frow">
          <label class="ecal-flabel" for="f-title">Title <span style="color:#ef4444;">*</span></label>
          <input type="text" class="ecal-finput" id="f-title" name="title"
                 placeholder="What's this about?" required autocomplete="off">
        </div>

        <div class="ecal-frow ecal-frow-2">
          <div>
            <label class="ecal-flabel" for="f-type">Type</label>
            <select class="ecal-fselect" id="f-type" name="type">
              <option value="NOTE">📝 Note</option>
              <option value="TODO">✅ To-Do</option>
              <option value="REMINDER">🔔 Reminder</option>
            </select>
          </div>
          <div>
            <label class="ecal-flabel" for="f-recurrence">Recurrence</label>
            <select class="ecal-fselect" id="f-recurrence" name="recurrence">
              <option value="NONE">One-time</option>
              <option value="DAILY">Daily</option>
              <option value="WEEKLY">Weekly</option>
              <option value="MONTHLY">Monthly</option>
            </select>
          </div>
        </div>

        <div class="ecal-frow ecal-frow-2">
          <div>
            <label class="ecal-flabel" for="f-date">Date <span style="color:#ef4444;">*</span></label>
            <input type="date" class="ecal-finput" id="f-date" name="entry_date" required>
          </div>
          <div>
            <label class="ecal-flabel" for="f-time">Time <span style="color:#94a3b8;font-weight:400;">(optional)</span></label>
            <input type="time" class="ecal-finput" id="f-time" name="entry_time">
          </div>
        </div>

        <div class="ecal-frow">
          <label class="ecal-flabel" for="f-desc">Description <span style="color:#94a3b8;font-weight:400;">(optional)</span></label>
          <textarea class="ecal-ftextarea" id="f-desc" name="description"
                    placeholder="Additional notes…"></textarea>
        </div>

        <div class="ecal-frow">
          <label class="ecal-flabel" for="f-reminder">Reminder Alert</label>
          <select class="ecal-fselect" id="f-reminder" name="reminder_offset" onchange="CAL.toggleNotifyRow()">
            <option value="none">None</option>
            <option value="at_time">At time of event</option>
            <option value="10min">10 minutes before</option>
            <option value="30min">30 minutes before</option>
            <option value="1hour">1 hour before</option>
            <option value="1day">1 day before</option>
          </select>
        </div>

        <div class="ecal-frow" id="f-notify-row" style="display:none;">
          <label style="display:flex;align-items:center;gap:8px;cursor:pointer;font-size:13px;font-weight:500;color:#374151;">
            <input type="checkbox" id="f-notify" name="notify_in_system" value="1"
                   style="width:15px;height:15px;accent-color:var(--accent);"
                   onchange="this.dataset.userTouched='1'">
            Show in notifications
          </label>
          <div style="font-size:11px;color:#94a3b8;margin-top:4px;">
            A notification will appear when the reminder time is reached.
          </div>
        </div>

      </div><!-- .ecal-mbody -->
      <div class="ecal-mfooter">
        <button type="button" class="ecal-btn ecal-btn-secondary" onclick="CAL.closeEntry(null)">Cancel</button>
        <button type="submit" class="ecal-btn ecal-btn-primary"   id="save-btn">Save Entry</button>
      </div>
    </form>
  </div>
</div>


<script>
/* ══════════════════════════════════════════════════════════════════════
   CALENDAR — Employee Portal
   Views: Month | Week | Day | Year
   Data: Holidays (system) + Personal entries (employee only)
══════════════════════════════════════════════════════════════════════ */

const CAL_TODAY  = '<?= $today ?>';
const CAL_TABLE  = <?= $hasTable ? 'true' : 'false' ?>;
const CAL_INIT   = <?= json_encode([
    'start'            => $initStart,
    'end'              => $initEnd,
    'holidays'         => $initHolidays,
    'entries'          => $initEntries,
    'leave_dates'      => $initLeaveDates,
    'payroll_releases' => $initPayrollReleases,
]) ?>;

const CAL = (() => {
    const DAYS   = ['Sun','Mon','Tue','Wed','Thu','Fri','Sat'];
    const MONTHS = ['January','February','March','April','May','June',
                    'July','August','September','October','November','December'];

    let view     = 'month';
    let focal    = new Date();  // focal date for navigation
    let cache    = {};          // data cache keyed by 'start_end'
    let evMap    = {};          // event lookup: 'h-id' | 'e-id-date' → object

    /* ─── Helpers ────────────────────────────────────────────────────────── */

    function fmt(d) {
        const y = d.getFullYear();
        const m = String(d.getMonth() + 1).padStart(2, '0');
        const day = String(d.getDate()).padStart(2, '0');
        return `${y}-${m}-${day}`;
    }
    function parseD(s) {
        const [y, m, d] = s.split('-').map(Number);
        return new Date(y, m - 1, d);
    }
    function isToday(d) { return fmt(d) === CAL_TODAY; }
    function esc(s) {
        return String(s ?? '')
            .replace(/&/g,'&amp;').replace(/</g,'&lt;')
            .replace(/>/g,'&gt;').replace(/"/g,'&quot;');
    }
    function fmtTime(t) {
        if (!t) return '';
        const parts = t.split(':').map(Number);
        const h = parts[0], m = parts[1] ?? 0;
        const ap = h >= 12 ? 'PM' : 'AM';
        return `${h % 12 || 12}:${String(m).padStart(2,'0')} ${ap}`;
    }
    function fmtDateLong(s) {
        return parseD(s).toLocaleDateString('en-US', {weekday:'long', year:'numeric', month:'long', day:'numeric'});
    }
    function viewLabel() {
        if (view === 'month') return MONTHS[focal.getMonth()] + ' ' + focal.getFullYear();
        if (view === 'year')  return String(focal.getFullYear());
        if (view === 'week') {
            const s = weekStart(focal);
            const e = new Date(s); e.setDate(e.getDate() + 6);
            if (s.getMonth() === e.getMonth())
                return `${MONTHS[s.getMonth()]} ${s.getDate()}–${e.getDate()}, ${s.getFullYear()}`;
            return `${MONTHS[s.getMonth()]} ${s.getDate()} – ${MONTHS[e.getMonth()]} ${e.getDate()}, ${e.getFullYear()}`;
        }
        if (view === 'day')
            return focal.toLocaleDateString('en-US', {weekday:'long', month:'long', day:'numeric', year:'numeric'});
    }
    function weekStart(d) {
        const s = new Date(d);
        s.setDate(s.getDate() - s.getDay());
        return s;
    }

    /* ─── Date range for current view ───────────────────────────────────── */

    function getRange() {
        if (view === 'month') return [
            new Date(focal.getFullYear(), focal.getMonth(), 1),
            new Date(focal.getFullYear(), focal.getMonth() + 1, 0)
        ];
        if (view === 'week') {
            const s = weekStart(focal);
            const e = new Date(s); e.setDate(e.getDate() + 6);
            return [s, e];
        }
        if (view === 'day') return [focal, focal];
        if (view === 'year') return [
            new Date(focal.getFullYear(), 0, 1),
            new Date(focal.getFullYear(), 11, 31)
        ];
    }

    /* ─── Recurring entry expansion ──────────────────────────────────────── */

    function expandEntries(raw, rangeStart, rangeEnd) {
        const result = [];
        for (const e of raw) {
            if (e.recurrence === 'NONE') { result.push(e); continue; }
            const base = parseD(e.entry_date);
            const cur  = new Date(Math.max(rangeStart.getTime(), base.getTime()));
            while (cur <= rangeEnd) {
                let match = false;
                if (e.recurrence === 'DAILY')   match = true;
                else if (e.recurrence === 'WEEKLY')  match = cur.getDay() === base.getDay();
                else if (e.recurrence === 'MONTHLY') match = cur.getDate() === base.getDate();
                if (match) result.push({...e, entry_date: fmt(new Date(cur))});
                cur.setDate(cur.getDate() + 1);
            }
        }
        return result;
    }

    /* ─── Event map (key → event object) ────────────────────────────────── */

    function buildMap(data) {
        evMap = {};
        for (const h of data.holidays)         evMap['h-'  + h.holiday_id] = h;
        for (const e of data.entries)          evMap['e-'  + e.entry_id + '-' + e.entry_date] = e;
        for (const l of data.leave_dates)      evMap['ld-' + l.leave_id + '-' + l.leave_date] = l;
        for (const p of data.payroll_releases) evMap['pp-' + p.payroll_id] = p;
    }

    /* ─── Data loading ───────────────────────────────────────────────────── */

    function cacheKey(s, e) { return fmt(s) + '_' + fmt(e); }

    async function loadData(rangeStart, rangeEnd) {
        const key = cacheKey(rangeStart, rangeEnd);
        if (cache[key]) return cache[key];
        try {
            const url = `${window.BASE_URL}actions/employee-calendar-action.php`
                      + `?action=get_entries&start=${fmt(rangeStart)}&end=${fmt(rangeEnd)}`;
            const res  = await fetch(url);
            const data = await res.json();
            if (data.success) {
                const expanded = {
                    holidays:         data.holidays,
                    entries:          expandEntries(data.entries, rangeStart, rangeEnd),
                    leave_dates:      data.leave_dates      || [],
                    payroll_releases: data.payroll_releases || [],
                };
                cache[key] = expanded;
                return expanded;
            }
        } catch(err) { console.error('Calendar fetch error:', err); }
        return {holidays: [], entries: [], leave_dates: [], payroll_releases: []};
    }

    function seedCache(init) {
        const s = parseD(init.start), e = parseD(init.end);
        cache[cacheKey(s, e)] = {
            holidays:         init.holidays,
            entries:          expandEntries(init.entries, s, e),
            leave_dates:      init.leave_dates      || [],
            payroll_releases: init.payroll_releases || [],
        };
    }

    function bust() { cache = {}; }

    /* ─── Events on a specific date ─────────────────────────────────────── */

    function eventsOn(dateStr, data) {
        const list = [];
        for (const h of data.holidays)         if (h.holiday_date  === dateStr) list.push({_k:'holiday', ...h});
        for (const l of data.leave_dates)      if (l.leave_date    === dateStr) list.push({_k:'leave',   ...l});
        for (const p of data.payroll_releases) if (p.release_date  === dateStr) list.push({_k:'payroll', ...p});
        for (const e of data.entries)          if (e.entry_date    === dateStr) list.push({_k:'entry',   ...e});
        return list;
    }

    /* ─── Pill / event row HTML ──────────────────────────────────────────── */

    function pillHtml(ev) {
        if (ev._k === 'holiday') {
            const k   = 'h-' + ev.holiday_id;
            const cls = 'holiday-' + ev.holiday_type.toLowerCase();
            return `<span class="ecal-pill ${cls}" onclick="event.stopPropagation();CAL.openByKey('${k}')" title="${esc(ev.holiday_name)}">${esc(ev.holiday_name)}</span>`;
        }
        if (ev._k === 'leave') {
            const k = 'ld-' + ev.leave_id + '-' + ev.leave_date;
            return `<span class="ecal-pill leave-date" onclick="event.stopPropagation();CAL.openByKey('${k}')" title="On Leave: ${esc(ev.leave_name)}">📋 ${esc(ev.leave_name)}</span>`;
        }
        if (ev._k === 'payroll') {
            const k = 'pp-' + ev.payroll_id;
            return `<span class="ecal-pill payroll-release" onclick="event.stopPropagation();CAL.openByKey('${k}')" title="Payroll Released: ${esc(ev.period_name)}">💰 Payroll Released</span>`;
        }
        const k   = 'e-' + ev.entry_id + '-' + ev.entry_date;
        const cls = 'entry-' + ev.type + (ev.is_done ? ' done' : '');
        return `<span class="ecal-pill ${cls}" onclick="event.stopPropagation();CAL.openByKey('${k}')" title="${esc(ev.title)}">${esc(ev.title)}</span>`;
    }

    function dayDotCls(ev) {
        if (ev._k === 'holiday') return 'holiday-' + ev.holiday_type.toLowerCase();
        if (ev._k === 'leave')   return 'leave-date';
        if (ev._k === 'payroll') return 'payroll-release';
        return 'entry-' + ev.type;
    }

    /* ─── Render functions ───────────────────────────────────────────────── */

    function renderMonth(data) {
        const y = focal.getFullYear(), m = focal.getMonth();
        const first   = new Date(y, m, 1).getDay();
        const daysM   = new Date(y, m + 1, 0).getDate();
        const daysP   = new Date(y, m, 0).getDate();
        const cells   = Math.ceil((first + daysM) / 7) * 7;

        let h = '<div>';
        // Day-of-week header
        h += '<div class="ecal-month-head">';
        DAYS.forEach(d => { h += `<div class="ecal-day-label">${d}</div>`; });
        h += '</div>';
        // Grid
        h += '<div class="ecal-month-grid">';
        for (let i = 0; i < cells; i++) {
            let cd, other;
            if      (i < first)            { cd = new Date(y, m - 1, daysP - first + i + 1); other = true; }
            else if (i >= first + daysM)   { cd = new Date(y, m + 1, i - first - daysM + 1); other = true; }
            else                           { cd = new Date(y, m, i - first + 1);              other = false; }

            const ds = fmt(cd);
            const todCls   = isToday(cd) && !other ? 'today'       : '';
            const otherCls = other                 ? 'other-month' : '';
            const evs = other ? [] : eventsOn(ds, data);
            const MAX = 3;

            h += `<div class="ecal-cell ${todCls} ${otherCls}" onclick="CAL.onCellClick('${ds}')">`;
            h += `<span class="ecal-cell-num">${cd.getDate()}</span>`;
            evs.slice(0, MAX).forEach(ev => { h += pillHtml(ev); });
            if (evs.length > MAX) {
                h += `<span class="ecal-more" onclick="event.stopPropagation();CAL.goDay('${ds}')">+${evs.length - MAX} more</span>`;
            }
            h += '</div>';
        }
        h += '</div></div>';
        return h;
    }

    function renderWeek(data) {
        const start = weekStart(focal);
        let h = '<div>';
        // Header
        h += '<div class="ecal-week-head">';
        for (let i = 0; i < 7; i++) {
            const d = new Date(start); d.setDate(d.getDate() + i);
            const tc = isToday(d) ? 'today' : '';
            h += `<div class="ecal-week-hcol ${tc}">
                <div class="ecal-wh-day">${DAYS[d.getDay()]}</div>
                <div class="ecal-wh-date">${d.getDate()}</div>
                <div class="ecal-wh-mon">${MONTHS[d.getMonth()].slice(0,3)}</div>
            </div>`;
        }
        h += '</div>';
        // Body
        h += '<div class="ecal-week-body">';
        for (let i = 0; i < 7; i++) {
            const d = new Date(start); d.setDate(d.getDate() + i);
            const ds = fmt(d);
            const tc = isToday(d) ? 'today' : '';
            const evs = eventsOn(ds, data);
            h += `<div class="ecal-week-col ${tc}" onclick="CAL.onCellClick('${ds}')">`;
            if (evs.length === 0) {
                h += '<div class="ecal-no-ev">—</div>';
            } else {
                evs.forEach(ev => { h += pillHtml(ev); });
            }
            h += '</div>';
        }
        h += '</div></div>';
        return h;
    }

    function renderDay(data) {
        const ds  = fmt(focal);
        const evs = eventsOn(ds, data);
        const dateLabel = focal.toLocaleDateString('en-US', {weekday:'long', month:'long', day:'numeric', year:'numeric'});
        let h = `<div>
            <div class="ecal-day-hdr">
                <div>
                    <div class="ecal-day-hdr-date">${esc(dateLabel)}</div>
                    <div class="ecal-day-hdr-sub">${evs.length} event${evs.length !== 1 ? 's' : ''}</div>
                </div>`;
        if (CAL_TABLE) {
            h += `<button class="ecal-day-add-btn" onclick="CAL.openAddModal('${ds}')"><i class="fa fa-plus"></i> Add Entry</button>`;
        }
        h += '</div>';
        h += '<div class="ecal-day-events">';
        if (evs.length === 0) {
            h += `<div class="ecal-day-empty">
                <p>No events scheduled for this day.</p>
                ${CAL_TABLE ? `<button class="ecal-btn ecal-btn-primary" onclick="CAL.openAddModal('${ds}')"><i class="fa fa-plus"></i> Add Entry</button>` : ''}
            </div>`;
        } else {
            evs.forEach(ev => {
                const dotCls = dayDotCls(ev);
                const title  = ev._k === 'holiday' ? ev.holiday_name : ev.title;
                const key    = ev._k === 'holiday' ? 'h-' + ev.holiday_id : 'e-' + ev.entry_id + '-' + ev.entry_date;
                let sub = '';
                if (ev._k === 'entry' && ev.entry_time) sub += '⏰ ' + fmtTime(ev.entry_time) + '  ';
                if (ev._k === 'entry' && ev.description) sub += esc(ev.description);
                if (ev._k === 'holiday' && ev.notes)     sub += esc(ev.notes);
                h += `<div class="ecal-day-ev" onclick="CAL.openByKey('${key}')">
                    <div class="ecal-day-ev-dot ${dotCls}"></div>
                    <div style="flex:1;min-width:0;">
                        <div class="ecal-day-ev-title">${esc(title)}</div>
                        ${sub ? `<div class="ecal-day-ev-sub">${sub}</div>` : ''}
                    </div>
                </div>`;
            });
        }
        h += '</div></div>';
        return h;
    }

    function renderYear(data) {
        const y = focal.getFullYear();
        let h = '<div class="ecal-year-grid">';
        for (let mo = 0; mo < 12; mo++) {
            const firstDay = new Date(y, mo, 1).getDay();
            const daysM    = new Date(y, mo + 1, 0).getDate();
            const moStr    = String(mo + 1).padStart(2, '0');
            h += `<div class="ecal-mini-month">
                <div class="ecal-mini-title" onclick="CAL.goMonth(${y},${mo})">${MONTHS[mo]}</div>
                <div class="ecal-mini-grid">`;
            ['S','M','T','W','T','F','S'].forEach(dl => {
                h += `<div class="ecal-mini-dlabel">${dl}</div>`;
            });
            for (let i = 0; i < firstDay; i++) h += '<div class="ecal-mini-day other"></div>';
            for (let d = 1; d <= daysM; d++) {
                const ds = `${y}-${moStr}-${String(d).padStart(2,'0')}`;
                const todCls  = ds === CAL_TODAY ? 'today' : '';
                const hasHol  = data.holidays.some(hh => hh.holiday_date === ds);
                const hasLeave = data.leave_dates.some(ll => ll.leave_date === ds);
                const hasPay  = data.payroll_releases.some(pp => pp.release_date === ds);
                const hasEnt  = data.entries.some(ee => ee.entry_date === ds);
                const dotCls  = hasHol ? 'has-holiday'
                              : hasLeave ? 'has-leave'
                              : hasPay   ? 'has-payroll'
                              : hasEnt   ? 'has-entry' : '';
                h += `<div class="ecal-mini-day ${todCls} ${dotCls}" onclick="CAL.goDay('${ds}')" title="${ds}">${d}</div>`;
            }
            h += '</div></div>';
        }
        h += '</div>';
        return h;
    }

    /* ─── Main render ────────────────────────────────────────────────────── */

    async function render() {
        const root  = document.getElementById('ecal-root');
        const label = document.getElementById('ecal-label');
        if (!root) return;

        root.innerHTML = '<div class="ecal-loading"><i class="fa fa-circle-notch fa-spin"></i></div>';
        if (label) label.textContent = viewLabel();

        // Sync view button states
        ['month','week','day','year'].forEach(v => {
            const b = document.getElementById('btn-' + v);
            if (b) b.classList.toggle('active', v === view);
        });

        const [rs, re] = getRange();
        const data = await loadData(rs, re);
        buildMap(data);

        if      (view === 'month') root.innerHTML = renderMonth(data);
        else if (view === 'week')  root.innerHTML = renderWeek(data);
        else if (view === 'day')   root.innerHTML = renderDay(data);
        else if (view === 'year')  root.innerHTML = renderYear(data);
    }

    /* ─── Navigation ─────────────────────────────────────────────────────── */

    function navigate(dir) {
        const d = new Date(focal);
        if      (view === 'month') d.setMonth(d.getMonth()  + dir);
        else if (view === 'week')  d.setDate(d.getDate()    + dir * 7);
        else if (view === 'day')   d.setDate(d.getDate()    + dir);
        else if (view === 'year')  d.setFullYear(d.getFullYear() + dir);
        focal = d;
        render();
    }

    function goToday() { focal = new Date(); render(); }
    function setView(v) { view = v; render(); }

    function goDay(dateStr) {
        focal = parseD(dateStr);
        view  = 'day';
        render();
    }
    function goMonth(y, m) {
        focal = new Date(y, m, 1);
        view  = 'month';
        render();
    }

    function onCellClick(dateStr) {
        // Month/week cell click → switch to day view
        focal = parseD(dateStr);
        view  = 'day';
        render();
    }

    /* ─── Detail modal ───────────────────────────────────────────────────── */

    function openByKey(key) {
        const ev = evMap[key];
        if (!ev) return;
        if      (key.startsWith('h-'))  showHoliday(ev);
        else if (key.startsWith('ld-')) showLeave(ev);
        else if (key.startsWith('pp-')) showPayroll(ev);
        else                            showEntry(ev);
    }

    function showHoliday(h) {
        const typeLabel = {REGULAR:'Regular Holiday', SPECIAL:'Special (Non-Working)', SCHOOL:'School Holiday'}[h.holiday_type] ?? h.holiday_type;
        const badgeCls  = 'holiday-' + h.holiday_type.toLowerCase();
        document.getElementById('detail-body').innerHTML = `
            <span class="ecal-type-badge ${badgeCls}"><i class="fa fa-umbrella-beach"></i> ${esc(typeLabel)}</span>
            <div class="ecal-detail-title">${esc(h.holiday_name)}</div>
            <div class="ecal-detail-row">
                <i class="fa fa-calendar-day ecal-detail-icon"></i>
                <span class="ecal-detail-val">${esc(fmtDateLong(h.holiday_date))}</span>
            </div>
            ${h.notes ? `<div class="ecal-detail-row">
                <i class="fa fa-note-sticky ecal-detail-icon"></i>
                <span class="ecal-detail-val">${esc(h.notes)}</span>
            </div>` : ''}
        `;
        document.getElementById('detail-footer').innerHTML =
            '<button class="ecal-btn ecal-btn-secondary" onclick="CAL.closeDetail(null)">Close</button>';
        document.getElementById('detail-modal').classList.add('open');
    }

    function showEntry(e) {
        const typeLabel = {NOTE:'Note', TODO:'To-Do', REMINDER:'Reminder'}[e.type] ?? e.type;
        const recLabel  = {NONE:'One-time', DAILY:'Daily', WEEKLY:'Weekly', MONTHLY:'Monthly'}[e.recurrence] ?? e.recurrence;
        const badgeCls  = 'entry-' + e.type;
        const doneNote  = e.is_done ? '<span style="margin-left:6px;font-size:11px;color:#94a3b8;font-weight:600;">✓ Done</span>' : '';
        document.getElementById('detail-body').innerHTML = `
            <span class="ecal-type-badge ${badgeCls}">
                <i class="fa ${e.type==='NOTE'?'fa-note-sticky':e.type==='TODO'?'fa-square-check':'fa-bell'}"></i>
                ${esc(typeLabel)}
            </span>${doneNote}
            <div class="ecal-detail-title" style="${e.is_done?'text-decoration:line-through;color:#94a3b8;':''}">${esc(e.title)}</div>
            <div class="ecal-detail-row">
                <i class="fa fa-calendar-day ecal-detail-icon"></i>
                <span class="ecal-detail-val">${esc(fmtDateLong(e.entry_date))}${e.entry_time ? ' &nbsp;⏰ ' + esc(fmtTime(e.entry_time)) : ''}</span>
            </div>
            ${e.recurrence !== 'NONE' ? `<div class="ecal-detail-row">
                <i class="fa fa-rotate ecal-detail-icon"></i>
                <span class="ecal-detail-val">Repeats ${esc(recLabel.toLowerCase())}</span>
            </div>` : ''}
            ${e.description ? `<div class="ecal-detail-row">
                <i class="fa fa-align-left ecal-detail-icon"></i>
                <span class="ecal-detail-val">${esc(e.description)}</span>
            </div>` : ''}
        `;

        let foot = '<button class="ecal-btn ecal-btn-secondary" onclick="CAL.closeDetail(null)">Close</button>';
        if (CAL_TABLE) {
            if (e.type === 'TODO') {
                foot += `<button class="ecal-btn ${e.is_done?'ecal-btn-secondary':'ecal-btn-success'}" onclick="CAL.toggleDone(${e.entry_id})">
                    ${e.is_done ? 'Mark Incomplete' : '✓ Mark Done'}
                </button>`;
            }
            foot += `<button class="ecal-btn ecal-btn-secondary" onclick="CAL.editEntry(${e.entry_id},'${e.entry_date}')"><i class="fa fa-pen"></i> Edit</button>`;
            foot += `<button class="ecal-btn ecal-btn-danger" onclick="CAL.deleteEntry(${e.entry_id})"><i class="fa fa-trash"></i> Delete</button>`;
        }
        document.getElementById('detail-footer').innerHTML = foot;
        document.getElementById('detail-modal').classList.add('open');
    }

    function showLeave(l) {
        document.getElementById('detail-body').innerHTML = `
            <span class="ecal-type-badge leave-date"><i class="fa fa-calendar-minus"></i> Approved Leave</span>
            <div class="ecal-detail-title">${esc(l.leave_name)}</div>
            <div class="ecal-detail-row">
                <i class="fa fa-calendar-day ecal-detail-icon"></i>
                <span class="ecal-detail-val">${esc(fmtDateLong(l.leave_date))}</span>
            </div>
            ${l.reason ? `<div class="ecal-detail-row">
                <i class="fa fa-comment-dots ecal-detail-icon"></i>
                <span class="ecal-detail-val">${esc(l.reason)}</span>
            </div>` : ''}
            <div class="ecal-detail-row" style="margin-top:4px;">
                <i class="fa fa-circle-info ecal-detail-icon" style="color:#0d9488;"></i>
                <span class="ecal-detail-val" style="color:#64748b;font-size:12px;">Approved — view full history in Leave Balance &amp; History</span>
            </div>
        `;
        document.getElementById('detail-footer').innerHTML = `
            <button class="ecal-btn ecal-btn-secondary" onclick="CAL.closeDetail(null)">Close</button>
            <a href="${window.BASE_URL}modules/employee/leave/index.php"
               class="ecal-btn ecal-btn-primary" style="text-decoration:none;">
                <i class="fa fa-calendar-days"></i> My Leave
            </a>
        `;
        document.getElementById('detail-modal').classList.add('open');
    }

    function showPayroll(p) {
        const net  = parseFloat(p.net_pay || 0);
        const netFmt = '₱' + net.toLocaleString('en-PH', {minimumFractionDigits:2, maximumFractionDigits:2});
        const ps   = parseD(p.pay_period_start).toLocaleDateString('en-US', {month:'short', day:'numeric'});
        const pe   = parseD(p.pay_period_end).toLocaleDateString('en-US',   {month:'short', day:'numeric', year:'numeric'});
        document.getElementById('detail-body').innerHTML = `
            <span class="ecal-type-badge payroll-release"><i class="fa fa-money-bill-wave"></i> Payroll Released</span>
            <div class="ecal-detail-title">${esc(p.period_name)}</div>
            <div class="ecal-detail-row">
                <i class="fa fa-calendar-day ecal-detail-icon"></i>
                <span class="ecal-detail-val">Released: ${esc(fmtDateLong(p.release_date))}</span>
            </div>
            <div class="ecal-detail-row">
                <i class="fa fa-calendar-week ecal-detail-icon"></i>
                <span class="ecal-detail-val">Pay Period: ${esc(ps)} – ${esc(pe)}</span>
            </div>
            <div class="ecal-detail-row" style="margin-top:6px;padding:10px 12px;background:#ede9fe;border-radius:8px;">
                <i class="fa fa-peso-sign ecal-detail-icon" style="color:#4f46e5;"></i>
                <span class="ecal-detail-val" style="font-size:17px;font-weight:700;color:#4f46e5;">${netFmt} <span style="font-size:12px;font-weight:500;color:#6d28d9;">net pay</span></span>
            </div>
        `;
        document.getElementById('detail-footer').innerHTML = `
            <button class="ecal-btn ecal-btn-secondary" onclick="CAL.closeDetail(null)">Close</button>
            <a href="${window.BASE_URL}modules/employee/payslips/index.php"
               class="ecal-btn ecal-btn-primary" style="text-decoration:none;">
                <i class="fa fa-file-invoice-dollar"></i> View Payslip
            </a>
        `;
        document.getElementById('detail-modal').classList.add('open');
    }

    function closeDetail(event) {
        if (!event || event.target === document.getElementById('detail-modal')) {
            document.getElementById('detail-modal').classList.remove('open');
        }
    }

    /* ─── Add / Edit modal ───────────────────────────────────────────────── */

    function toggleNotifyRow() {
        const offset    = document.getElementById('f-reminder').value;
        const notifRow  = document.getElementById('f-notify-row');
        const notifyEl  = document.getElementById('f-notify');
        if (!notifRow) return;
        const hasReminder = offset !== 'none';
        notifRow.style.display = hasReminder ? 'block' : 'none';
        // Auto-check when a reminder is first selected; uncheck when cleared
        if (notifyEl && hasReminder && !notifyEl.dataset.userTouched) {
            notifyEl.checked = true;
        }
        if (notifyEl && !hasReminder) {
            notifyEl.checked = false;
            delete notifyEl.dataset.userTouched;
        }
    }

    function openAddModal(dateStr) {
        if (!CAL_TABLE) { showToast('Run migration 018 to enable personal entries.','info'); return; }
        const d = dateStr || fmt(focal);
        document.getElementById('entry-modal-title').textContent = 'Add Calendar Entry';
        document.getElementById('f-entry-id').value   = '';
        document.getElementById('f-title').value      = '';
        document.getElementById('f-type').value       = 'NOTE';
        document.getElementById('f-recurrence').value = 'NONE';
        document.getElementById('f-date').value       = d;
        document.getElementById('f-time').value       = '';
        document.getElementById('f-desc').value       = '';
        const reminderEl = document.getElementById('f-reminder');
        const notifyEl   = document.getElementById('f-notify');
        if (reminderEl) reminderEl.value = 'none';
        if (notifyEl)   notifyEl.checked = false;
        toggleNotifyRow();
        document.getElementById('save-btn').textContent = 'Save Entry';
        document.getElementById('entry-modal').classList.add('open');
        setTimeout(() => document.getElementById('f-title').focus(), 80);
    }

    function editEntry(entryId, entryDate) {
        const key = 'e-' + entryId + '-' + entryDate;
        const e   = evMap[key];
        if (!e) { showToast('Could not load entry data.','error'); return; }
        closeDetail(null);
        document.getElementById('entry-modal-title').textContent = 'Edit Calendar Entry';
        document.getElementById('f-entry-id').value   = e.entry_id;
        document.getElementById('f-title').value      = e.title;
        document.getElementById('f-type').value       = e.type;
        document.getElementById('f-recurrence').value = e.recurrence;
        document.getElementById('f-date').value       = e.entry_date;
        document.getElementById('f-time').value       = e.entry_time || '';
        document.getElementById('f-desc').value       = e.description || '';
        const reminderEl = document.getElementById('f-reminder');
        const notifyEl   = document.getElementById('f-notify');
        if (reminderEl) reminderEl.value = e.reminder_offset || 'none';
        if (notifyEl)   notifyEl.checked = parseInt(e.notify_in_system) === 1;
        toggleNotifyRow();
        document.getElementById('save-btn').textContent = 'Update Entry';
        document.getElementById('entry-modal').classList.add('open');
        setTimeout(() => document.getElementById('f-title').focus(), 80);
    }

    function closeEntry(event) {
        if (!event || event.target === document.getElementById('entry-modal')) {
            document.getElementById('entry-modal').classList.remove('open');
        }
    }

    async function saveEntry() {
        const title = document.getElementById('f-title').value.trim();
        if (!title) { showToast('Title is required.','error'); return; }

        const btn = document.getElementById('save-btn');
        btn.disabled = true;
        const orig = btn.textContent;
        btn.textContent = 'Saving…';

        const fd = new FormData(document.getElementById('entry-form'));
        fd.set('action', 'save_entry');

        try {
            const res  = await fetch(`${window.BASE_URL}actions/employee-calendar-action.php`, {method:'POST', body:fd});
            const data = await res.json();
            if (data.success) {
                showToast(data.message || 'Saved!', 'success');
                closeEntry(null);
                bust();
                render();
            } else {
                showToast(data.message || 'Error saving entry.', 'error');
            }
        } catch(e) {
            showToast('Network error.', 'error');
        } finally {
            btn.disabled = false;
            btn.textContent = orig;
        }
    }

    async function deleteEntry(entryId) {
        if (!confirm('Delete this entry? This cannot be undone.')) return;
        closeDetail(null);
        const fd = new FormData();
        fd.append('action', 'delete_entry');
        fd.append('entry_id', entryId);
        try {
            const res  = await fetch(`${window.BASE_URL}actions/employee-calendar-action.php`, {method:'POST', body:fd});
            const data = await res.json();
            if (data.success) { showToast('Entry deleted.','success'); bust(); render(); }
            else               showToast(data.message || 'Error.','error');
        } catch(e) { showToast('Network error.','error'); }
    }

    async function toggleDone(entryId) {
        const fd = new FormData();
        fd.append('action', 'toggle_done');
        fd.append('entry_id', entryId);
        try {
            const res  = await fetch(`${window.BASE_URL}actions/employee-calendar-action.php`, {method:'POST', body:fd});
            const data = await res.json();
            if (data.success) { closeDetail(null); bust(); render(); }
        } catch(e) { showToast('Network error.','error'); }
    }

    /* ─── Init ───────────────────────────────────────────────────────────── */

    function init() {
        seedCache(CAL_INIT);
        render();
    }

    return {
        init, render,
        setView, navigate, goToday, goDay, goMonth,
        onCellClick, openByKey, openAddModal, editEntry,
        closeDetail, closeEntry, saveEntry, deleteEntry, toggleDone,
        toggleNotifyRow,
    };
})();

document.addEventListener('DOMContentLoaded', () => CAL.init());
</script>

<?php require_once __DIR__ . '/../../../includes/footer.php'; ?>
