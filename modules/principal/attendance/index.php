<?php
/**
 * modules/principal/attendance/index.php
 * Principal Portal — Attendance Monitor
 * Principal may: view, filter, search, and submit attendance reviews.
 * Principal CANNOT: add, edit, time-out, or trigger auto-absent.
 */
require_once __DIR__ . '/../../../config/config.php';
require_once __DIR__ . '/../../../includes/auth.php';
requirePrincipal();

$today = date('Y-m-d');
$tab   = $_GET['tab'] ?? 'today';

// ── Migration 007 detection ───────────────────────────────────────────────────
$hasMig007 = (bool)$pdo->query("
    SELECT COUNT(*) FROM information_schema.COLUMNS
    WHERE TABLE_SCHEMA = DATABASE()
      AND TABLE_NAME   = 'attendance_records'
      AND COLUMN_NAME  = 'overtime_minutes'
")->fetchColumn();

$selEmpNo     = $hasMig007 ? 'e.employee_no,' : "'' AS employee_no,";
$selRevStatus = $hasMig007 ? 'ar.review_status,' : "'PENDING' AS review_status,";

// ── Today's summary stats (always anchored to today) ─────────────────────────
$statStmt = $pdo->prepare("
    SELECT
        SUM(attendance_status = 'PRESENT')  AS present_cnt,
        SUM(attendance_status = 'LATE')     AS late_cnt,
        SUM(attendance_status = 'HALF_DAY') AS halfday_cnt,
        SUM(attendance_status = 'ABSENT')   AS absent_cnt,
        SUM(attendance_status = 'LEAVE')    AS leave_cnt
    FROM attendance_records
    WHERE attendance_date = :today
");
$statStmt->execute([':today' => $today]);
$stats = $statStmt->fetch(PDO::FETCH_ASSOC);

$presentCount = (int)($stats['present_cnt']  ?? 0);
$lateCount    = (int)($stats['late_cnt']     ?? 0);
$halfDayCount = (int)($stats['halfday_cnt']  ?? 0);
$absentCount  = (int)($stats['absent_cnt']   ?? 0);
$leaveCount   = (int)($stats['leave_cnt']    ?? 0);

$totalActive   = (int)$pdo->query("SELECT COUNT(*) FROM employees WHERE employee_status = 'ACTIVE'")->fetchColumn();
$attendedToday = $presentCount + $lateCount + $halfDayCount;
$attendanceRate = $totalActive > 0 ? round(($attendedToday / $totalActive) * 100) : 0;

// Review queue count (pending reviews only if mig007 applied)
$reviewQueueCount = 0;
if ($hasMig007) {
    $reviewQueueCount = (int)$pdo->query("
        SELECT COUNT(*) FROM attendance_records WHERE review_status = 'PENDING'
    ")->fetchColumn();
}

// ── Departments for filters ───────────────────────────────────────────────────
$depts = $pdo->query("SELECT department_id, department_name FROM departments ORDER BY department_name")->fetchAll();

// ── TODAY TAB ─────────────────────────────────────────────────────────────────
$filterDate   = $_GET['date']   ?? $today;
$filterStatus = $_GET['status'] ?? '';
$filterDept   = (int)($_GET['dept'] ?? 0);
$filterRv     = $_GET['rv'] ?? '';
$search       = trim($_GET['search'] ?? '');

$limit  = 20;
$page   = max(1, (int)($_GET['page'] ?? 1));
$offset = ($page - 1) * $limit;

$where  = "WHERE ar.attendance_date = :adate";
$params = [':adate' => $filterDate];

if ($search !== '') {
    $where   .= " AND (e.first_name LIKE :s OR e.last_name LIKE :s
                   OR CONCAT(e.first_name,' ',e.last_name) LIKE :s
                   OR e.employee_no LIKE :s)";
    $params[':s'] = '%' . $search . '%';
}
if ($filterStatus !== '') {
    $where .= " AND ar.attendance_status = :st";
    $params[':st'] = $filterStatus;
}
if ($filterDept > 0) {
    $where .= " AND e.department_id = :dept";
    $params[':dept'] = $filterDept;
}
if ($hasMig007 && $filterRv !== '') {
    $where .= " AND ar.review_status = :rv";
    $params[':rv'] = $filterRv;
}

$countStmt = $pdo->prepare("
    SELECT COUNT(*)
    FROM attendance_records ar
    JOIN employees e ON ar.employee_id = e.employee_id
    LEFT JOIN departments d ON e.department_id = d.department_id
    $where
");
$countStmt->execute($params);
$totalRecords = (int)$countStmt->fetchColumn();
$totalPages   = max(1, ceil($totalRecords / $limit));

$recStmt = $pdo->prepare("
    SELECT
        ar.attendance_id,
        ar.attendance_date,
        ar.time_in,
        ar.time_out,
        ar.attendance_status,
        ar.attendance_source,
        ar.remarks,
        {$selEmpNo}
        {$selRevStatus}
        e.first_name,
        e.last_name,
        d.department_name,
        p.position_name,
        s.shift_name,
        s.start_time AS shift_start,
        s.end_time   AS shift_end,
        CASE
            WHEN ar.attendance_status IN ('LATE','HALF_DAY')
             AND ar.time_in IS NOT NULL
             AND s.start_time IS NOT NULL
            THEN GREATEST(0, TIMESTAMPDIFF(MINUTE,
                CONCAT(ar.attendance_date,' ',s.start_time),
                CONCAT(ar.attendance_date,' ',ar.time_in)))
            ELSE 0
        END AS late_minutes,
        CASE
            WHEN ar.time_out IS NOT NULL AND s.end_time IS NOT NULL
             AND TIME(ar.time_out) < s.end_time
            THEN GREATEST(0, TIMESTAMPDIFF(MINUTE,
                CONCAT(ar.attendance_date,' ',ar.time_out),
                CONCAT(ar.attendance_date,' ',s.end_time)))
            ELSE 0
        END AS undertime_minutes
    FROM attendance_records ar
    JOIN employees e ON ar.employee_id = e.employee_id
    LEFT JOIN departments d ON e.department_id = d.department_id
    LEFT JOIN positions   p ON e.position_id   = p.position_id
    LEFT JOIN shifts      s ON e.shift_id      = s.shift_id
    $where
    ORDER BY e.last_name ASC, e.first_name ASC
    LIMIT :lim OFFSET :off
");
foreach ($params as $k => $v) $recStmt->bindValue($k, $v);
$recStmt->bindValue(':lim',  $limit,  PDO::PARAM_INT);
$recStmt->bindValue(':off',  $offset, PDO::PARAM_INT);
$recStmt->execute();
$rows = $recStmt->fetchAll(PDO::FETCH_ASSOC);

// ── CUTOFF TAB ────────────────────────────────────────────────────────────────
$cutoffPeriod = $_GET['cutoff'] ?? '';
$cutoffDept   = $_GET['cdept']  ?? '';
$cutoffSearch = trim($_GET['csearch'] ?? '');

$cutoffOptions = [];
for ($i = 0; $i < 6; $i++) {
    $ts   = strtotime("-$i months");
    $yr   = date('Y', $ts);
    $mo   = date('m', $ts);
    $last = date('t', mktime(0, 0, 0, $mo, 1, $yr));
    $cutoffOptions[] = ['label' => date('M', $ts) . " 1–15, $yr",     'val' => "$yr-$mo-01|$yr-$mo-15"];
    $cutoffOptions[] = ['label' => date('M', $ts) . " 16–$last, $yr", 'val' => "$yr-$mo-16|$yr-$mo-$last"];
}

if ($cutoffPeriod === '') {
    $day = (int)date('d');
    $cutoffPeriod = $day <= 15
        ? date('Y-m') . '-01|' . date('Y-m') . '-15'
        : date('Y-m') . '-16|' . date('Y-m') . '-' . date('t');
}
[$cutoffStart, $cutoffEnd] = explode('|', $cutoffPeriod) + [date('Y-m-01'), date('Y-m-15')];

$workingDays = 0;
$d = strtotime($cutoffStart);
while ($d <= strtotime($cutoffEnd)) { if (date('N', $d) < 7) $workingDays++; $d = strtotime('+1 day', $d); }

$ctWhere  = "WHERE ar.attendance_date BETWEEN :cs AND :ce";
$ctParams = [':cs' => $cutoffStart, ':ce' => $cutoffEnd];
if ($cutoffDept !== '') { $ctWhere .= " AND e.department_id = :cd"; $ctParams[':cd'] = $cutoffDept; }
if ($cutoffSearch !== '') { $ctWhere .= " AND (e.first_name LIKE :csr OR e.last_name LIKE :csr OR e.employee_no LIKE :csr)"; $ctParams[':csr'] = '%' . $cutoffSearch . '%'; }

$ctStmt = $pdo->prepare("
    SELECT
        SUM(ar.attendance_status = 'PRESENT')  AS tp,
        SUM(ar.attendance_status = 'LATE')     AS tl,
        SUM(ar.attendance_status = 'HALF_DAY') AS thd,
        SUM(ar.attendance_status = 'ABSENT')   AS ta,
        COUNT(DISTINCT ar.employee_id)         AS te
    FROM attendance_records ar
    JOIN employees e ON ar.employee_id = e.employee_id
    $ctWhere
");
$ctStmt->execute($ctParams);
$ct = $ctStmt->fetch(PDO::FETCH_ASSOC);

$avgRate = ($ct['te'] > 0 && $workingDays > 0)
    ? round((($ct['tp'] + $ct['tl'] + $ct['thd']) / ($ct['te'] * $workingDays)) * 100) : 0;

// Per-employee cutoff summary
$empWhere  = "WHERE e.employee_status = 'ACTIVE'";
$empParams = [':cs2' => $cutoffStart, ':ce2' => $cutoffEnd];
if ($cutoffDept !== '') { $empWhere .= " AND e.department_id = :cd2"; $empParams[':cd2'] = $cutoffDept; }
if ($cutoffSearch !== '') { $empWhere .= " AND (e.first_name LIKE :csr2 OR e.last_name LIKE :csr2 OR e.employee_no LIKE :csr2)"; $empParams[':csr2'] = '%' . $cutoffSearch . '%'; }

$empStmt = $pdo->prepare("
    SELECT
        e.employee_id, e.first_name, e.last_name, {$selEmpNo}
        d.department_name, s.shift_name,
        SUM(ar.attendance_status = 'PRESENT')  AS ep,
        SUM(ar.attendance_status = 'LATE')     AS el,
        SUM(ar.attendance_status = 'HALF_DAY') AS ehd,
        SUM(ar.attendance_status = 'ABSENT')   AS ea,
        SUM(ar.attendance_status = 'LEAVE')    AS ev
    FROM employees e
    LEFT JOIN attendance_records ar
        ON e.employee_id = ar.employee_id
        AND ar.attendance_date BETWEEN :cs2 AND :ce2
    LEFT JOIN departments d ON e.department_id = d.department_id
    LEFT JOIN shifts      s ON e.shift_id      = s.shift_id
    $empWhere
    GROUP BY e.employee_id
    ORDER BY e.last_name ASC, e.first_name ASC
");
$empStmt->execute($empParams);
$empRows = $empStmt->fetchAll(PDO::FETCH_ASSOC);

// ── HISTORY TAB ───────────────────────────────────────────────────────────────
$hStart   = $_GET['hstart']  ?? date('Y-m-01');
$hEnd     = $_GET['hend']    ?? $today;
$hDept    = (int)($_GET['hdept']   ?? 0);
$hStatus  = $_GET['hstatus'] ?? '';
$hRv      = $_GET['hrv']     ?? '';
$hSearch  = trim($_GET['hsearch'] ?? '');
$hPage    = max(1, (int)($_GET['hpage'] ?? 1));
$hLimit   = 20;
$hOffset  = ($hPage - 1) * $hLimit;

$hWhere  = "WHERE ar.attendance_date BETWEEN :hs AND :he";
$hParams = [':hs' => $hStart, ':he' => $hEnd];
if ($hSearch !== '') {
    $hWhere .= " AND (e.first_name LIKE :hsr OR e.last_name LIKE :hsr
                  OR CONCAT(e.first_name,' ',e.last_name) LIKE :hsr
                  OR e.employee_no LIKE :hsr)";
    $hParams[':hsr'] = '%' . $hSearch . '%';
}
if ($hStatus !== '') { $hWhere .= " AND ar.attendance_status = :hst"; $hParams[':hst'] = $hStatus; }
if ($hDept > 0)      { $hWhere .= " AND e.department_id = :hdp";      $hParams[':hdp'] = $hDept; }
if ($hasMig007 && $hRv !== '') { $hWhere .= " AND ar.review_status = :hrv"; $hParams[':hrv'] = $hRv; }

$hCountStmt = $pdo->prepare("
    SELECT COUNT(*)
    FROM attendance_records ar
    JOIN employees e ON ar.employee_id = e.employee_id
    $hWhere
");
$hCountStmt->execute($hParams);
$hTotal     = (int)$hCountStmt->fetchColumn();
$hTotalPages = max(1, ceil($hTotal / $hLimit));

$hStmt = $pdo->prepare("
    SELECT
        ar.attendance_id, ar.attendance_date,
        ar.time_in, ar.time_out,
        ar.attendance_status, ar.attendance_source, ar.remarks,
        {$selEmpNo}
        {$selRevStatus}
        e.first_name, e.last_name,
        d.department_name, p.position_name, s.shift_name
    FROM attendance_records ar
    JOIN employees e ON ar.employee_id = e.employee_id
    LEFT JOIN departments d ON e.department_id = d.department_id
    LEFT JOIN positions   p ON e.position_id   = p.position_id
    LEFT JOIN shifts      s ON e.shift_id      = s.shift_id
    $hWhere
    ORDER BY ar.attendance_date DESC, e.last_name ASC
    LIMIT :hlim OFFSET :hoff
");
foreach ($hParams as $k => $v) $hStmt->bindValue($k, $v);
$hStmt->bindValue(':hlim',  $hLimit,  PDO::PARAM_INT);
$hStmt->bindValue(':hoff',  $hOffset, PDO::PARAM_INT);
$hStmt->execute();
$hRows = $hStmt->fetchAll(PDO::FETCH_ASSOC);

// ── Helpers ───────────────────────────────────────────────────────────────────
$STATUS_LABELS = [
    'PRESENT'    => 'Present',
    'LATE'       => 'Late',
    'HALF_DAY'   => 'Half Day',
    'ABSENT'     => 'Absent',
    'LEAVE'      => 'On Leave',
    'INCOMPLETE' => 'Incomplete',
    'HOLIDAY'    => 'Holiday',
];

$RV_LABELS = [
    'PENDING'   => 'Pending',
    'REVIEWED'  => 'Reviewed',
    'APPROVED'  => 'Verified',
    'FLAGGED'   => 'Needs Correction',
    'CORRECTED' => 'Corrected',
];

// ── DTR Attachments ───────────────────────────────────────────────────────────
$dtrFiles = [];
if ($hasMig007) {
    try {
        $hasDeptColDtr = (bool)$pdo->query("
            SELECT COUNT(*) FROM information_schema.COLUMNS
            WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='dtr_attachments' AND COLUMN_NAME='department_id'
        ")->fetchColumn();
        $dtrDeptCol  = $hasDeptColDtr ? 'da.department_id, dept.department_name AS dtr_dept_name,' : "NULL AS department_id, NULL AS dtr_dept_name,";
        $dtrDeptJoin = $hasDeptColDtr ? "LEFT JOIN departments dept ON da.department_id = dept.department_id" : "";
        $dtrStmt = $pdo->query("
            SELECT da.attachment_id, da.cutoff_start, da.cutoff_end,
                   da.file_name, da.file_path, da.file_type, da.notes, da.created_at,
                   {$dtrDeptCol}
                   COALESCE(CONCAT(emp.first_name,' ',emp.last_name), u.username, 'System') AS uploaded_by_name
            FROM dtr_attachments da
            LEFT JOIN users u ON da.uploaded_by = u.user_id
            LEFT JOIN employees emp ON u.employee_id = emp.employee_id
            {$dtrDeptJoin}
            ORDER BY da.created_at DESC
            LIMIT 50
        ");
        $dtrFiles = $dtrStmt->fetchAll(PDO::FETCH_ASSOC);
    } catch (PDOException $dtrEx) { $dtrFiles = []; }
}

$pageTitle = 'Attendance Monitor — Principal Portal';
$extraCSS  = [
    BASE_URL . 'assets/css/principal.css',
    BASE_URL . 'assets/css/attendance.css',
];
require_once __DIR__ . '/../../../includes/head.php';
?>
<style>
/* ── Principal Attendance overrides (patt- prefix) ── */
.patt-readonly-badge {
    display: inline-flex; align-items: center; gap: 6px;
    padding: 5px 12px; border-radius: 99px;
    background: #f1f5f9; border: 1px solid #e2e8f0;
    font-size: 11px; font-weight: 600; color: #64748b;
}
.patt-review-queue-btn {
    display: inline-flex; align-items: center; gap: 8px;
    padding: 8px 16px; border-radius: 8px;
    background: #0f766e; color: #fff; border: none;
    font-size: 13px; font-weight: 600; cursor: pointer;
    transition: background 0.15s;
    text-decoration: none;
}
.patt-review-queue-btn:hover { background: #0d5f58; color: #fff; }
.patt-queue-badge {
    display: inline-flex; align-items: center; justify-content: center;
    background: #ef4444; color: #fff;
    border-radius: 99px; font-size: 11px; font-weight: 700;
    min-width: 20px; height: 20px; padding: 0 5px;
}

/* Summary cards 5-column */
.att-summary-cards { grid-template-columns: repeat(5, 1fr); }
@media (max-width: 1100px) { .att-summary-cards { grid-template-columns: repeat(3, 1fr); } }
@media (max-width: 680px)  { .att-summary-cards { grid-template-columns: repeat(2, 1fr); } }

/* Today rate bar */
.patt-rate-bar-wrap {
    display: flex; align-items: center; gap: 12px;
    padding: 10px 16px; background: #f0fdfa;
    border: 1px solid #ccfbf1; border-radius: 10px; font-size: 13px;
}
.patt-rate-bar   { flex: 1; height: 8px; background: #e2e8f0; border-radius: 99px; overflow: hidden; max-width: 180px; }
.patt-rate-fill  { height: 100%; border-radius: 99px; background: #0f766e; }
.patt-rate-val   { font-size: 16px; font-weight: 800; color: #0f766e; white-space: nowrap; }

/* Viewing non-today notice */
.patt-date-notice {
    display: flex; align-items: center; gap: 8px;
    padding: 8px 14px; margin-bottom: 12px;
    background: #fefce8; border: 1px solid #fde68a;
    border-radius: 8px; font-size: 12px; color: #92400e; font-weight: 500;
}

/* Filter row */
.patt-filters-row {
    display: flex; align-items: center; gap: 10px;
    margin-bottom: 14px; flex-wrap: wrap;
}
.patt-filters-row .att-search-box { flex: 2; min-width: 200px; }
.patt-filters-row select, .patt-date-input {
    padding: 9px 12px; border-radius: 8px;
    border: 1px solid #d1d5db; font-size: 13px;
    background: #fff; cursor: pointer;
}
.patt-filters-row select:focus, .patt-date-input:focus { outline: none; border-color: #0f766e; }
.patt-apply-btn {
    padding: 9px 16px; border-radius: 8px;
    background: #0f766e; color: #fff; border: none;
    font-size: 13px; font-weight: 600; cursor: pointer;
    display: inline-flex; align-items: center; gap: 6px;
    transition: background 0.15s;
}
.patt-apply-btn:hover { background: #0d5f58; }
.patt-reset-link { font-size: 12px; color: #64748b; text-decoration: none; white-space: nowrap; }
.patt-reset-link:hover { color: #0f766e; }

/* Chips */
.patt-late-chip {
    display: inline-block; padding: 2px 8px; border-radius: 99px;
    font-size: 10px; font-weight: 700; background: #fef3c7; color: #92400e;
}
.patt-undertime-chip {
    display: inline-block; padding: 2px 8px; border-radius: 99px;
    font-size: 10px; font-weight: 700; background: #f1f5f9; color: #64748b;
}
.att-shift-tag { display: inline-flex; align-items: center; gap: 4px; font-size: 11px; color: #6366f1; font-weight: 500; }
.att-table tbody tr { cursor: default; }
.att-main-card { border-radius: var(--radius, 12px); margin-top: 0; }

/* Review status badges */
.att-rv-badge {
    display: inline-flex; align-items: center; gap: 4px;
    padding: 2px 9px; border-radius: 99px;
    font-size: 10px; font-weight: 700; white-space: nowrap;
}
.att-rv-badge.rv-pending   { background: #f1f5f9; color: #64748b; }
.att-rv-badge.rv-reviewed  { background: #dbeafe; color: #1d4ed8; }
.att-rv-badge.rv-approved  { background: #dcfce7; color: #15803d; }
.att-rv-badge.rv-flagged   { background: #fee2e2; color: #b91c1c; }
.att-rv-badge.rv-corrected { background: #f3e8ff; color: #7c3aed; }

/* Review action button */
.patt-review-btn {
    display: inline-flex; align-items: center; gap: 5px;
    padding: 4px 12px; border-radius: 6px;
    border: 1px solid #0f766e; background: #f0fdfa;
    color: #0f766e; font-size: 12px; font-weight: 600;
    cursor: pointer; transition: all 0.15s; white-space: nowrap;
}
.patt-review-btn:hover { background: #0f766e; color: #fff; }

/* Review modal */
.patt-review-modal-backdrop {
    display: none; position: fixed; inset: 0;
    background: rgba(0,0,0,0.45); z-index: 1050;
    align-items: center; justify-content: center;
}
.patt-review-modal-backdrop.open { display: flex; }
.patt-review-modal-box {
    background: #fff; border-radius: 14px;
    width: 100%; max-width: 520px; max-height: 90vh;
    overflow-y: auto; padding: 28px;
    box-shadow: 0 8px 32px rgba(0,0,0,0.18);
}
.patt-review-modal-title {
    font-size: 16px; font-weight: 700; color: #0f172a;
    margin-bottom: 16px; display: flex; align-items: center; gap: 8px;
}
.patt-review-detail-grid {
    display: grid; grid-template-columns: 1fr 1fr;
    gap: 8px 16px; background: #f8fafc; border: 1px solid #e2e8f0;
    border-radius: 10px; padding: 14px; margin-bottom: 18px;
}
.patt-review-detail-item label {
    display: block; font-size: 10px; font-weight: 700;
    color: #94a3b8; text-transform: uppercase; letter-spacing: .04em;
    margin-bottom: 3px;
}
.patt-review-detail-item span { font-size: 13px; font-weight: 600; color: #1e293b; }
.patt-review-radio-group { margin-bottom: 16px; }
.patt-review-radio-group legend {
    font-size: 12px; font-weight: 700; color: #374151;
    text-transform: uppercase; letter-spacing: .04em;
    margin-bottom: 10px;
}
.patt-review-radio-option {
    display: flex; align-items: flex-start; gap: 10px;
    padding: 10px 14px; border-radius: 8px; margin-bottom: 6px;
    border: 2px solid #e2e8f0; cursor: pointer;
    transition: border-color 0.15s, background 0.15s;
}
.patt-review-radio-option:hover { border-color: #0f766e; background: #f0fdfa; }
.patt-review-radio-option.selected { border-color: #0f766e; background: #f0fdfa; }
.patt-review-radio-option input[type="radio"] { margin-top: 2px; accent-color: #0f766e; }
.patt-review-radio-option .opt-label { font-size: 13px; font-weight: 600; color: #1e293b; }
.patt-review-radio-option .opt-desc  { font-size: 11px; color: #64748b; margin-top: 1px; }
.patt-review-remark-label { font-size: 12px; font-weight: 700; color: #374151; margin-bottom: 6px; display: block; }
.patt-review-remark {
    width: 100%; padding: 10px 12px; border-radius: 8px;
    border: 1px solid #d1d5db; font-size: 13px; resize: vertical;
    min-height: 72px; box-sizing: border-box;
    font-family: inherit;
}
.patt-review-remark:focus { outline: none; border-color: #0f766e; }
.patt-review-modal-actions {
    display: flex; gap: 10px; justify-content: flex-end; margin-top: 18px;
}
.patt-review-cancel-btn {
    padding: 9px 20px; border-radius: 8px;
    background: #f1f5f9; border: 1px solid #e2e8f0;
    color: #64748b; font-size: 13px; font-weight: 600; cursor: pointer;
}
.patt-review-save-btn {
    padding: 9px 20px; border-radius: 8px;
    background: #0f766e; border: none;
    color: #fff; font-size: 13px; font-weight: 600; cursor: pointer;
    transition: background 0.15s;
}
.patt-review-save-btn:hover { background: #0d5f58; }
.patt-review-save-btn:disabled { opacity: 0.6; cursor: not-allowed; }

/* Toast */
.patt-toast {
    position: fixed; bottom: 24px; right: 24px; z-index: 2000;
    padding: 12px 20px; border-radius: 10px;
    font-size: 13px; font-weight: 600; color: #fff;
    box-shadow: 0 4px 16px rgba(0,0,0,0.18);
    animation: pattToastIn 0.2s ease;
    display: none;
}
.patt-toast.show  { display: block; }
.patt-toast.ok    { background: #0f766e; }
.patt-toast.error { background: #dc2626; }
@keyframes pattToastIn { from { opacity: 0; transform: translateY(12px); } to { opacity: 1; transform: none; } }

/* Empno small */
.patt-empno { font-size: 10px; color: #94a3b8; font-weight: 500; }
</style>

<body>
<div class="layout">
<?php include __DIR__ . '/../../../includes/principal-sidebar.php'; ?>

<div class="main">

  <!-- Header -->
  <div class="header">
    <div style="display:flex;align-items:center;gap:10px;">
      <i class="fa fa-user-check" style="color:#0f766e;font-size:18px;"></i>
      <div>
        <div style="font-size:15px;font-weight:700;color:#0f172a;">Principal Portal</div>
        <div style="font-size:12px;color:#64748b;">Great Eastern Institute</div>
      </div>
    </div>
    <div style="margin-left:auto;display:flex;align-items:center;gap:12px;">
      <button style="background:none;border:none;cursor:pointer;">
        <i class="fa fa-bell" style="font-size:16px;color:#94a3b8;"></i>
      </button>
      <div style="text-align:right;">
        <div style="font-size:14px;font-weight:600;color:#0f172a;">
          <?= htmlspecialchars(($_SESSION['user']['first_name'] ?? '') . ' ' . ($_SESSION['user']['last_name'] ?? '')) ?>
        </div>
        <div style="font-size:11px;color:#64748b;"><?= htmlspecialchars($_SESSION['user']['role_name'] ?? 'Principal') ?></div>
      </div>
      <div class="header-avatar">
        <?= strtoupper(substr($_SESSION['user']['first_name'] ?? 'P', 0, 1) . substr($_SESSION['user']['last_name'] ?? 'R', 0, 1)) ?>
      </div>
    </div>
  </div>

  <div class="main-content">
  <div class="principal-page">

    <!-- Page Header -->
    <div class="principal-page-header" style="display:flex;align-items:flex-start;justify-content:space-between;flex-wrap:wrap;gap:12px;">
      <div>
        <h1>Attendance Monitor</h1>
        <p>View, filter, and review employee attendance records.</p>
      </div>
      <div style="display:flex;align-items:center;gap:10px;flex-wrap:wrap;">
        <div class="patt-rate-bar-wrap">
          <span style="font-size:12px;color:#64748b;font-weight:600;white-space:nowrap;">Today's Rate</span>
          <div class="patt-rate-bar">
            <div class="patt-rate-fill" style="width:<?= $attendanceRate ?>%;"></div>
          </div>
          <span class="patt-rate-val"><?= $attendanceRate ?>%</span>
        </div>
        <?php if ($hasMig007): ?>
        <button class="patt-review-queue-btn" onclick="switchTabByName('history');scrollToReviewFilter()">
          <i class="fa fa-clipboard-check"></i>
          Review Queue
          <?php if ($reviewQueueCount > 0): ?>
          <span class="patt-queue-badge"><?= $reviewQueueCount ?></span>
          <?php endif; ?>
        </button>
        <?php endif; ?>
        <span class="patt-readonly-badge"><i class="fa fa-eye"></i> View Only</span>
      </div>
    </div>

    <!-- Summary Cards -->
    <div class="att-summary-cards">
      <div class="att-sum-card green">
        <div><div class="att-sum-label">PRESENT TODAY</div><div class="att-sum-val"><?= $presentCount ?></div></div>
        <div class="att-sum-icon"><i class="fa fa-circle-check"></i></div>
      </div>
      <div class="att-sum-card yellow">
        <div><div class="att-sum-label">LATE TODAY</div><div class="att-sum-val"><?= $lateCount ?></div></div>
        <div class="att-sum-icon"><i class="fa fa-clock"></i></div>
      </div>
      <div class="att-sum-card orange">
        <div><div class="att-sum-label">HALF DAY</div><div class="att-sum-val"><?= $halfDayCount ?></div></div>
        <div class="att-sum-icon"><i class="fa fa-circle-half-stroke"></i></div>
      </div>
      <div class="att-sum-card red">
        <div><div class="att-sum-label">ABSENT TODAY</div><div class="att-sum-val"><?= $absentCount ?></div></div>
        <div class="att-sum-icon"><i class="fa fa-circle-xmark"></i></div>
      </div>
      <div class="att-sum-card blue">
        <div><div class="att-sum-label">ON LEAVE</div><div class="att-sum-val"><?= $leaveCount ?></div></div>
        <div class="att-sum-icon"><i class="fa fa-plane-departure"></i></div>
      </div>
    </div>

    <!-- Main Card -->
    <div class="att-main-card">

      <!-- Tabs -->
      <div class="att-tabs-row">
        <button class="att-tab <?= $tab === 'today'   ? 'active' : '' ?>" onclick="switchTab('today', this)">
          <i class="fa fa-calendar-day"></i> Daily View
          <span class="tab-date"><?= date('M j, Y') ?></span>
        </button>
        <button class="att-tab <?= $tab === 'cutoff'  ? 'active' : '' ?>" onclick="switchTab('cutoff', this)">
          <i class="fa fa-calendar-days"></i> By Cutoff Period
        </button>
        <button class="att-tab <?= $tab === 'history' ? 'active' : '' ?>" onclick="switchTab('history', this)" id="tabBtnHistory">
          <i class="fa fa-clock-rotate-left"></i> Attendance History
        </button>
      </div>

      <!-- ═══ TODAY TAB ══════════════════════════════════════════════════════ -->
      <div id="tabToday" class="att-tab-pane <?= $tab !== 'today' ? 'hidden' : '' ?>">

        <form method="GET" id="todayForm">
          <input type="hidden" name="tab" value="today">
          <div class="patt-filters-row">
            <input type="date" name="date" class="patt-date-input"
                   value="<?= htmlspecialchars($filterDate) ?>"
                   onchange="document.getElementById('todayForm').submit()">
            <select name="dept" onchange="this.form.submit()">
              <option value="">All Departments</option>
              <?php foreach ($depts as $dep): ?>
              <option value="<?= $dep['department_id'] ?>" <?= $filterDept == $dep['department_id'] ? 'selected' : '' ?>>
                <?= htmlspecialchars($dep['department_name']) ?>
              </option>
              <?php endforeach; ?>
            </select>
            <select name="status" onchange="this.form.submit()">
              <option value="">All Status</option>
              <option value="PRESENT"    <?= $filterStatus === 'PRESENT'    ? 'selected' : '' ?>>Present</option>
              <option value="LATE"       <?= $filterStatus === 'LATE'       ? 'selected' : '' ?>>Late</option>
              <option value="HALF_DAY"   <?= $filterStatus === 'HALF_DAY'   ? 'selected' : '' ?>>Half Day</option>
              <option value="ABSENT"     <?= $filterStatus === 'ABSENT'     ? 'selected' : '' ?>>Absent</option>
              <option value="LEAVE"      <?= $filterStatus === 'LEAVE'      ? 'selected' : '' ?>>On Leave</option>
              <option value="INCOMPLETE" <?= $filterStatus === 'INCOMPLETE' ? 'selected' : '' ?>>Incomplete</option>
            </select>
            <?php if ($hasMig007): ?>
            <select name="rv" onchange="this.form.submit()">
              <option value="">All Review Status</option>
              <option value="PENDING"   <?= $filterRv === 'PENDING'   ? 'selected' : '' ?>>Pending Review</option>
              <option value="REVIEWED"  <?= $filterRv === 'REVIEWED'  ? 'selected' : '' ?>>Reviewed</option>
              <option value="APPROVED"  <?= $filterRv === 'APPROVED'  ? 'selected' : '' ?>>Verified</option>
              <option value="FLAGGED"   <?= $filterRv === 'FLAGGED'   ? 'selected' : '' ?>>Needs Correction</option>
              <option value="CORRECTED" <?= $filterRv === 'CORRECTED' ? 'selected' : '' ?>>Corrected</option>
            </select>
            <?php endif; ?>
            <div class="att-search-box">
              <i class="fa fa-magnifying-glass"></i>
              <input type="text" name="search" placeholder="Search name or employee no..."
                     value="<?= htmlspecialchars($search) ?>" oninput="debounceSubmit('todayForm', 400)">
            </div>
            <button type="submit" class="patt-apply-btn"><i class="fa fa-filter"></i> Apply</button>
            <?php if ($filterDate !== $today || $filterStatus !== '' || $filterDept > 0 || $search !== '' || $filterRv !== ''): ?>
            <a href="?tab=today" class="patt-reset-link"><i class="fa fa-rotate-left"></i> Reset</a>
            <?php endif; ?>
          </div>
        </form>

        <?php if ($filterDate !== $today): ?>
        <div class="patt-date-notice">
          <i class="fa fa-calendar-days"></i>
          Viewing records for <strong><?= date('l, F j, Y', strtotime($filterDate)) ?></strong>
          — Summary cards above always show today.
        </div>
        <?php endif; ?>

        <div style="overflow-x:auto;">
        <table class="att-table">
          <thead>
            <tr>
              <th>Employee</th>
              <th>Department</th>
              <th>Shift</th>
              <th>Time In</th>
              <th>Time Out</th>
              <th>Status</th>
              <th>Late</th>
              <th>Undertime</th>
              <?php if ($hasMig007): ?><th>Review</th><th></th><?php endif; ?>
            </tr>
          </thead>
          <tbody>
          <?php if (empty($rows)): ?>
          <tr>
            <td colspan="<?= $hasMig007 ? 10 : 8 ?>" class="att-no-data">
              <i class="fa fa-calendar-xmark" style="font-size:24px;color:#cbd5e1;display:block;margin-bottom:8px;"></i>
              No attendance records for <strong><?= date('M j, Y', strtotime($filterDate)) ?></strong>.
            </td>
          </tr>
          <?php else: foreach ($rows as $r):
              $stRaw   = $r['attendance_status'];
              $stClass = strtolower($stRaw);
              $stLabel = $STATUS_LABELS[$stRaw] ?? ucfirst(strtolower($stRaw));
              $rvRaw   = $r['review_status'] ?? 'PENDING';
              $rvClass = 'rv-' . strtolower($rvRaw);
              $rvLabel = $RV_LABELS[$rvRaw] ?? $rvRaw;
              $tin     = $r['time_in']  ? date('h:i A', strtotime($r['time_in']))  : '—';
              $tout    = $r['time_out'] ? date('h:i A', strtotime($r['time_out'])) : '—';
              $timeClass = match($stClass) { 'late' => 'late', 'half_day' => 'half_day', 'present' => 'ok', default => '' };
              $lateMin = (int)($r['late_minutes']     ?? 0);
              $utMin   = (int)($r['undertime_minutes'] ?? 0);
              $initials = strtoupper(substr($r['first_name'], 0, 1) . substr($r['last_name'], 0, 1));
              $empNo   = htmlspecialchars($r['employee_no'] ?? '');
              // JS data attributes (escaped)
              $jsName  = htmlspecialchars($r['first_name'] . ' ' . $r['last_name'], ENT_QUOTES);
              $jsDate  = htmlspecialchars($r['attendance_date'], ENT_QUOTES);
              $jsTin   = htmlspecialchars($r['time_in'] ?? '', ENT_QUOTES);
              $jsTout  = htmlspecialchars($r['time_out'] ?? '', ENT_QUOTES);
              $jsSrc   = htmlspecialchars($r['attendance_source'] ?? '', ENT_QUOTES);
              $jsRem   = htmlspecialchars($r['remarks'] ?? '', ENT_QUOTES);
          ?>
          <tr>
            <td>
              <div class="att-emp-cell">
                <div class="att-av"><?= $initials ?></div>
                <div>
                  <div style="font-weight:600;"><?= htmlspecialchars($r['first_name'] . ' ' . $r['last_name']) ?></div>
                  <?php if ($empNo): ?><div class="patt-empno"><?= $empNo ?></div><?php endif; ?>
                  <?php if ($r['position_name']): ?>
                  <span class="att-role-tag"><?= htmlspecialchars($r['position_name']) ?></span>
                  <?php endif; ?>
                </div>
              </div>
            </td>
            <td><?= $r['department_name'] ? '<span class="att-dept-tag">' . htmlspecialchars($r['department_name']) . '</span>' : '—' ?></td>
            <td><?= $r['shift_name'] ? '<span class="att-shift-tag"><i class="fa fa-clock"></i> ' . htmlspecialchars($r['shift_name']) . '</span>' : '<span style="color:#94a3b8;font-size:12px;">—</span>' ?></td>
            <td><span class="att-time <?= $timeClass ?>"><?= $tin ?></span></td>
            <td><?= $tout ?></td>
            <td><span class="att-badge <?= $stClass ?>"><?= $stLabel ?></span></td>
            <td><?= $lateMin > 0 ? '<span class="patt-late-chip">' . $lateMin . 'm late</span>' : '<span style="color:#94a3b8;font-size:12px;">—</span>' ?></td>
            <td><?= $utMin  > 0 ? '<span class="patt-undertime-chip">' . $utMin . 'm early</span>' : '<span style="color:#94a3b8;font-size:12px;">—</span>' ?></td>
            <?php if ($hasMig007): ?>
            <td><span class="att-rv-badge <?= $rvClass ?>"><?= $rvLabel ?></span></td>
            <td>
              <button class="patt-review-btn"
                      onclick="openReviewModal(
                        <?= $r['attendance_id'] ?>,
                        '<?= $jsName ?>',
                        '<?= $empNo ?>',
                        '<?= $jsDate ?>',
                        '<?= $jsTin ?>',
                        '<?= $jsTout ?>',
                        '<?= $stLabel ?>',
                        '<?= $jsSrc ?>',
                        '<?= $jsRem ?>',
                        '<?= $rvRaw ?>'
                      )">
                <i class="fa fa-clipboard-check"></i> Review
              </button>
            </td>
            <?php endif; ?>
          </tr>
          <?php endforeach; endif; ?>
          </tbody>
        </table>
        </div>

        <!-- Pagination -->
        <div class="att-pagination">
          <span>Showing <?= $totalRecords > 0 ? $offset + 1 : 0 ?>–<?= min($offset + $limit, $totalRecords) ?> of <?= $totalRecords ?> record<?= $totalRecords !== 1 ? 's' : '' ?></span>
          <div class="att-pg-btns">
            <a class="att-pg <?= $page <= 1 ? 'disabled' : '' ?>" href="?<?= http_build_query(array_merge($_GET, ['page' => max(1, $page - 1)])) ?>">
              <i class="fa fa-chevron-left" style="font-size:10px;"></i> Prev
            </a>
            <?php foreach (range(max(1, $page - 2), min($totalPages, $page + 2)) as $pg): ?>
            <a class="att-pg <?= $pg === $page ? 'active' : '' ?>" href="?<?= http_build_query(array_merge($_GET, ['page' => $pg])) ?>"><?= $pg ?></a>
            <?php endforeach; ?>
            <a class="att-pg <?= $page >= $totalPages ? 'disabled' : '' ?>" href="?<?= http_build_query(array_merge($_GET, ['page' => min($totalPages, $page + 1)])) ?>">
              Next <i class="fa fa-chevron-right" style="font-size:10px;"></i>
            </a>
          </div>
        </div>

      </div><!-- #tabToday -->

      <!-- ═══ CUTOFF TAB ═════════════════════════════════════════════════════ -->
      <div id="tabCutoff" class="att-tab-pane <?= $tab !== 'cutoff' ? 'hidden' : '' ?>">

        <form method="GET" id="cutoffForm">
          <input type="hidden" name="tab" value="cutoff">
          <div class="patt-filters-row">
            <div class="att-cutoff-sel-wrap">
              <i class="fa fa-calendar-days"></i>
              <select name="cutoff" onchange="this.form.submit()">
                <?php foreach ($cutoffOptions as $co): ?>
                <option value="<?= $co['val'] ?>" <?= $cutoffPeriod === $co['val'] ? 'selected' : '' ?>><?= $co['label'] ?></option>
                <?php endforeach; ?>
              </select>
            </div>
            <select name="cdept" onchange="this.form.submit()">
              <option value="">All Departments</option>
              <?php foreach ($depts as $dep): ?>
              <option value="<?= $dep['department_id'] ?>" <?= $cutoffDept == $dep['department_id'] ? 'selected' : '' ?>>
                <?= htmlspecialchars($dep['department_name']) ?>
              </option>
              <?php endforeach; ?>
            </select>
            <div class="att-search-box">
              <i class="fa fa-magnifying-glass"></i>
              <input type="text" name="csearch" placeholder="Search employee..." value="<?= htmlspecialchars($cutoffSearch) ?>">
            </div>
            <button type="submit" class="patt-apply-btn"><i class="fa fa-filter"></i> Apply</button>
            <?php if ($cutoffDept !== '' || $cutoffSearch !== ''): ?>
            <a href="?tab=cutoff&cutoff=<?= urlencode($cutoffPeriod) ?>" class="patt-reset-link"><i class="fa fa-rotate-left"></i> Reset</a>
            <?php endif; ?>
          </div>
        </form>

        <div class="att-cutoff-stats">
          <div class="co-stat"><div class="co-label">WORKING DAYS</div><div class="co-val"><?= $workingDays ?></div></div>
          <div class="co-stat green"><div class="co-label">TOTAL PRESENT</div><div class="co-val"><?= (int)($ct['tp'] ?? 0) ?></div></div>
          <div class="co-stat yellow"><div class="co-label">TOTAL LATE</div><div class="co-val"><?= (int)($ct['tl'] ?? 0) ?></div></div>
          <div class="co-stat orange"><div class="co-label">TOTAL HALF DAY</div><div class="co-val"><?= (int)($ct['thd'] ?? 0) ?></div></div>
          <div class="co-stat red"><div class="co-label">TOTAL ABSENT</div><div class="co-val"><?= (int)($ct['ta'] ?? 0) ?></div></div>
          <div class="co-stat blue"><div class="co-label">AVG ATTENDANCE</div><div class="co-val"><?= $avgRate ?>%</div></div>
        </div>

        <p class="att-period-note">
          <i class="fa fa-circle-info"></i>
          Period: <strong><?= date('M j', strtotime($cutoffStart)) ?> – <?= date('M j, Y', strtotime($cutoffEnd)) ?></strong>
          &nbsp;·&nbsp; <?= $workingDays ?> working days &nbsp;·&nbsp; <?= count($empRows) ?> employee<?= count($empRows) !== 1 ? 's' : '' ?>
        </p>

        <div style="overflow-x:auto;">
        <table class="att-table">
          <thead>
            <tr>
              <th>Employee</th>
              <th>Department</th>
              <th>Shift</th>
              <th class="th-present">Present</th>
              <th class="th-late">Late</th>
              <th style="color:#ea580c;">Half Day</th>
              <th class="th-absent">Absent</th>
              <th class="th-leave">On Leave</th>
              <th>Attendance Rate</th>
            </tr>
          </thead>
          <tbody>
          <?php if (empty($empRows)): ?>
          <tr><td colspan="9" class="att-no-data">No employee records found for this period.</td></tr>
          <?php else: foreach ($empRows as $er):
              $total = max($workingDays, 1);
              $rate  = min(100, round((((int)$er['ep'] + (int)$er['el'] + (int)$er['ehd']) / $total) * 100));
              $rateClass = $rate >= 90 ? 'rc-green' : ($rate >= 75 ? 'rc-yellow' : 'rc-red');
              $empNo = htmlspecialchars($er['employee_no'] ?? '');
          ?>
          <tr>
            <td>
              <div class="att-emp-cell">
                <div class="att-av"><?= strtoupper(substr($er['first_name'], 0, 1) . substr($er['last_name'], 0, 1)) ?></div>
                <div>
                  <strong><?= htmlspecialchars($er['first_name'] . ' ' . $er['last_name']) ?></strong>
                  <?php if ($empNo): ?><div class="patt-empno"><?= $empNo ?></div><?php endif; ?>
                </div>
              </div>
            </td>
            <td><?= htmlspecialchars($er['department_name'] ?? '—') ?></td>
            <td><?= $er['shift_name'] ? '<span class="att-shift-tag"><i class="fa fa-clock"></i> ' . htmlspecialchars($er['shift_name']) . '</span>' : '<span style="color:#94a3b8;font-size:12px;">—</span>' ?></td>
            <td><span class="co-num green"><?= (int)$er['ep'] ?></span></td>
            <td><span class="co-num yellow"><?= (int)$er['el'] ?></span></td>
            <td><span class="co-num orange"><?= (int)$er['ehd'] ?></span></td>
            <td><span class="co-num red"><?= (int)$er['ea'] ?></span></td>
            <td><span class="co-num blue"><?= (int)$er['ev'] ?></span></td>
            <td>
              <div class="att-rate-row">
                <div class="att-rate-bar"><div class="att-rate-fill <?= $rateClass ?>" style="width:<?= $rate ?>%;"></div></div>
                <span><?= $rate ?>%</span>
              </div>
            </td>
          </tr>
          <?php endforeach; endif; ?>
          </tbody>
        </table>
        </div>

      </div><!-- #tabCutoff -->

      <!-- ═══ HISTORY TAB ════════════════════════════════════════════════════ -->
      <div id="tabHistory" class="att-tab-pane <?= $tab !== 'history' ? 'hidden' : '' ?>">

        <form method="GET" id="historyForm">
          <input type="hidden" name="tab" value="history">
          <div class="patt-filters-row">
            <input type="date" name="hstart" class="patt-date-input" value="<?= htmlspecialchars($hStart) ?>">
            <span style="font-size:12px;color:#64748b;">to</span>
            <input type="date" name="hend"   class="patt-date-input" value="<?= htmlspecialchars($hEnd) ?>">
            <select name="hdept">
              <option value="">All Departments</option>
              <?php foreach ($depts as $dep): ?>
              <option value="<?= $dep['department_id'] ?>" <?= $hDept == $dep['department_id'] ? 'selected' : '' ?>>
                <?= htmlspecialchars($dep['department_name']) ?>
              </option>
              <?php endforeach; ?>
            </select>
            <select name="hstatus">
              <option value="">All Status</option>
              <option value="PRESENT"    <?= $hStatus === 'PRESENT'    ? 'selected' : '' ?>>Present</option>
              <option value="LATE"       <?= $hStatus === 'LATE'       ? 'selected' : '' ?>>Late</option>
              <option value="HALF_DAY"   <?= $hStatus === 'HALF_DAY'   ? 'selected' : '' ?>>Half Day</option>
              <option value="ABSENT"     <?= $hStatus === 'ABSENT'     ? 'selected' : '' ?>>Absent</option>
              <option value="LEAVE"      <?= $hStatus === 'LEAVE'      ? 'selected' : '' ?>>On Leave</option>
              <option value="INCOMPLETE" <?= $hStatus === 'INCOMPLETE' ? 'selected' : '' ?>>Incomplete</option>
            </select>
            <?php if ($hasMig007): ?>
            <select name="hrv" id="hrvFilter">
              <option value="">All Review Status</option>
              <option value="PENDING"   <?= $hRv === 'PENDING'   ? 'selected' : '' ?>>Pending Review</option>
              <option value="REVIEWED"  <?= $hRv === 'REVIEWED'  ? 'selected' : '' ?>>Reviewed</option>
              <option value="APPROVED"  <?= $hRv === 'APPROVED'  ? 'selected' : '' ?>>Verified</option>
              <option value="FLAGGED"   <?= $hRv === 'FLAGGED'   ? 'selected' : '' ?>>Needs Correction</option>
              <option value="CORRECTED" <?= $hRv === 'CORRECTED' ? 'selected' : '' ?>>Corrected</option>
            </select>
            <?php endif; ?>
            <div class="att-search-box">
              <i class="fa fa-magnifying-glass"></i>
              <input type="text" name="hsearch" placeholder="Search name or employee no..."
                     value="<?= htmlspecialchars($hSearch) ?>" oninput="debounceSubmit('historyForm', 400)">
            </div>
            <button type="submit" class="patt-apply-btn"><i class="fa fa-filter"></i> Apply</button>
            <?php if ($hStart !== date('Y-m-01') || $hEnd !== $today || $hDept > 0 || $hStatus !== '' || $hRv !== '' || $hSearch !== ''): ?>
            <a href="?tab=history" class="patt-reset-link"><i class="fa fa-rotate-left"></i> Reset</a>
            <?php endif; ?>
          </div>
        </form>

        <p class="att-period-note">
          <i class="fa fa-circle-info"></i>
          <?= $hTotal ?> record<?= $hTotal !== 1 ? 's' : '' ?> from
          <strong><?= date('M j, Y', strtotime($hStart)) ?></strong> to
          <strong><?= date('M j, Y', strtotime($hEnd)) ?></strong>
        </p>

        <div style="overflow-x:auto;">
        <table class="att-table">
          <thead>
            <tr>
              <th>Employee</th>
              <th>Date</th>
              <th>Department</th>
              <th>Time In</th>
              <th>Time Out</th>
              <th>Status</th>
              <th>Method</th>
              <?php if ($hasMig007): ?><th>Review</th><th></th><?php endif; ?>
            </tr>
          </thead>
          <tbody>
          <?php if (empty($hRows)): ?>
          <tr>
            <td colspan="<?= $hasMig007 ? 9 : 7 ?>" class="att-no-data">
              <i class="fa fa-clock-rotate-left" style="font-size:24px;color:#cbd5e1;display:block;margin-bottom:8px;"></i>
              No records found for the selected filters.
            </td>
          </tr>
          <?php else: foreach ($hRows as $r):
              $stRaw   = $r['attendance_status'];
              $stClass = strtolower($stRaw);
              $stLabel = $STATUS_LABELS[$stRaw] ?? ucfirst(strtolower($stRaw));
              $rvRaw   = $r['review_status'] ?? 'PENDING';
              $rvClass = 'rv-' . strtolower($rvRaw);
              $rvLabel = $RV_LABELS[$rvRaw] ?? $rvRaw;
              $tin     = $r['time_in']  ? date('h:i A', strtotime($r['time_in']))  : '—';
              $tout    = $r['time_out'] ? date('h:i A', strtotime($r['time_out'])) : '—';
              $initials = strtoupper(substr($r['first_name'], 0, 1) . substr($r['last_name'], 0, 1));
              $empNo   = htmlspecialchars($r['employee_no'] ?? '');
              $srcLabel = match($r['attendance_source'] ?? '') {
                  'MANUAL_ADMIN'      => 'Manual',
                  'MANUAL'            => 'Manual',
                  'RFID'              => 'RFID',
                  'FACIAL_RECOGNITION'=> 'Biometric',
                  'AUTO'              => 'Auto',
                  default             => ($r['attendance_source'] ?? 'Manual'),
              };
              $jsName  = htmlspecialchars($r['first_name'] . ' ' . $r['last_name'], ENT_QUOTES);
              $jsDate  = htmlspecialchars($r['attendance_date'], ENT_QUOTES);
              $jsTin   = htmlspecialchars($r['time_in'] ?? '', ENT_QUOTES);
              $jsTout  = htmlspecialchars($r['time_out'] ?? '', ENT_QUOTES);
              $jsSrc   = htmlspecialchars($r['attendance_source'] ?? '', ENT_QUOTES);
              $jsRem   = htmlspecialchars($r['remarks'] ?? '', ENT_QUOTES);
          ?>
          <tr>
            <td>
              <div class="att-emp-cell">
                <div class="att-av"><?= $initials ?></div>
                <div>
                  <div style="font-weight:600;"><?= htmlspecialchars($r['first_name'] . ' ' . $r['last_name']) ?></div>
                  <?php if ($empNo): ?><div class="patt-empno"><?= $empNo ?></div><?php endif; ?>
                  <?php if ($r['position_name']): ?><span class="att-role-tag"><?= htmlspecialchars($r['position_name']) ?></span><?php endif; ?>
                </div>
              </div>
            </td>
            <td style="white-space:nowrap;"><?= date('M j, Y', strtotime($r['attendance_date'])) ?></td>
            <td><?= $r['department_name'] ? '<span class="att-dept-tag">' . htmlspecialchars($r['department_name']) . '</span>' : '—' ?></td>
            <td><?= $tin ?></td>
            <td><?= $tout ?></td>
            <td><span class="att-badge <?= $stClass ?>"><?= $stLabel ?></span></td>
            <td><span class="att-method-tag"><?= htmlspecialchars($srcLabel) ?></span></td>
            <?php if ($hasMig007): ?>
            <td><span class="att-rv-badge <?= $rvClass ?>"><?= $rvLabel ?></span></td>
            <td>
              <button class="patt-review-btn"
                      onclick="openReviewModal(
                        <?= $r['attendance_id'] ?>,
                        '<?= $jsName ?>',
                        '<?= $empNo ?>',
                        '<?= $jsDate ?>',
                        '<?= $jsTin ?>',
                        '<?= $jsTout ?>',
                        '<?= $stLabel ?>',
                        '<?= $jsSrc ?>',
                        '<?= $jsRem ?>',
                        '<?= $rvRaw ?>'
                      )">
                <i class="fa fa-clipboard-check"></i> Review
              </button>
            </td>
            <?php endif; ?>
          </tr>
          <?php endforeach; endif; ?>
          </tbody>
        </table>
        </div>

        <!-- History Pagination -->
        <div class="att-pagination">
          <span>Showing <?= $hTotal > 0 ? $hOffset + 1 : 0 ?>–<?= min($hOffset + $hLimit, $hTotal) ?> of <?= $hTotal ?> record<?= $hTotal !== 1 ? 's' : '' ?></span>
          <div class="att-pg-btns">
            <a class="att-pg <?= $hPage <= 1 ? 'disabled' : '' ?>" href="?<?= http_build_query(array_merge($_GET, ['tab' => 'history', 'hpage' => max(1, $hPage - 1)])) ?>">
              <i class="fa fa-chevron-left" style="font-size:10px;"></i> Prev
            </a>
            <?php foreach (range(max(1, $hPage - 2), min($hTotalPages, $hPage + 2)) as $pg): ?>
            <a class="att-pg <?= $pg === $hPage ? 'active' : '' ?>" href="?<?= http_build_query(array_merge($_GET, ['tab' => 'history', 'hpage' => $pg])) ?>"><?= $pg ?></a>
            <?php endforeach; ?>
            <a class="att-pg <?= $hPage >= $hTotalPages ? 'disabled' : '' ?>" href="?<?= http_build_query(array_merge($_GET, ['tab' => 'history', 'hpage' => min($hTotalPages, $hPage + 1)])) ?>">
              Next <i class="fa fa-chevron-right" style="font-size:10px;"></i>
            </a>
          </div>
        </div>

      </div><!-- #tabHistory -->

    </div><!-- .att-main-card -->

    <?php if ($hasMig007): ?>
    <!-- ── DTR Backup Files — View Only for Principal ───────────────────────── -->
    <div class="att-dtr-outer" style="margin-top:20px;">
      <div class="att-dtr-section-title">
        <i class="fa fa-paperclip" style="color:#1d4ed8;"></i>
        DTR Backup Files
        <?php if (!empty($dtrFiles)): ?>
        <span class="att-dtr-count"><?= count($dtrFiles) ?></span>
        <?php endif; ?>
        <span class="patt-readonly-badge" style="margin-left:auto;">
          <i class="fa fa-eye"></i> View Only — Uploaded by Admin
        </span>
      </div>
      <?php if (!empty($dtrFiles)): ?>
      <div class="att-dtr-list">
      <?php foreach ($dtrFiles as $df):
          $ftIcon  = match($df['file_type']) { 'EXCEL'=>'fa-file-excel','PDF'=>'fa-file-pdf','IMAGE'=>'fa-file-image',default=>'fa-file' };
          $ftColor = match($df['file_type']) { 'EXCEL'=>'#16a34a','PDF'=>'#dc2626','IMAGE'=>'#7c3aed',default=>'#6b7280' };
      ?>
      <div class="att-dtr-item">
        <i class="fa <?= $ftIcon ?>" style="color:<?= $ftColor ?>;font-size:22px;flex-shrink:0;"></i>
        <div class="att-dtr-info">
          <div class="att-dtr-filename"><?= htmlspecialchars($df['file_name']) ?></div>
          <div class="att-dtr-meta">
            <strong>Period:</strong>
            <?= date('M j, Y', strtotime($df['cutoff_start'])) ?>
            <?= $df['cutoff_start'] !== $df['cutoff_end'] ? ' – ' . date('M j, Y', strtotime($df['cutoff_end'])) : '' ?>
            <?php if (!empty($df['dtr_dept_name'])): ?>
            &nbsp;·&nbsp; <span class="att-dept-tag" style="font-size:10px;"><?= htmlspecialchars($df['dtr_dept_name']) ?></span>
            <?php endif; ?>
            &nbsp;·&nbsp; Uploaded by <?= htmlspecialchars($df['uploaded_by_name'] ?? 'System') ?>
            on <?= date('M j, Y g:i A', strtotime($df['created_at'])) ?>
            <?php if ($df['notes']): ?><br><em><?= htmlspecialchars($df['notes']) ?></em><?php endif; ?>
          </div>
        </div>
        <a href="<?= BASE_URL ?>actions/download-dtr.php?id=<?= $df['attachment_id'] ?>"
           target="_blank" class="att-btn outline"
           style="font-size:12px;padding:6px 12px;white-space:nowrap;flex-shrink:0;">
          <i class="fa fa-download"></i> Download
        </a>
      </div>
      <?php endforeach; ?>
      </div>
      <?php else: ?>
      <div class="att-dtr-empty">
        <i class="fa fa-folder-open" style="opacity:.25;font-size:24px;"></i>
        <span>No DTR backup files have been uploaded yet. Admin will upload them here when available.</span>
      </div>
      <?php endif; ?>
    </div>
    <?php endif; ?>

  </div><!-- .principal-page -->
  </div><!-- .main-content -->
</div><!-- .main -->
</div><!-- .layout -->

<?php if ($hasMig007): ?>
<!-- ── Review Modal ──────────────────────────────────────────────────────────── -->
<div class="patt-review-modal-backdrop" id="reviewModalBackdrop" onclick="closeReviewModal(event)">
  <div class="patt-review-modal-box" onclick="event.stopPropagation()">
    <div class="patt-review-modal-title">
      <i class="fa fa-clipboard-check" style="color:#0f766e;"></i>
      <span>Attendance Review</span>
    </div>

    <!-- Employee info -->
    <div style="display:flex;align-items:center;gap:10px;margin-bottom:14px;">
      <div style="width:38px;height:38px;border-radius:50%;background:#0f766e;color:#fff;display:flex;align-items:center;justify-content:center;font-size:14px;font-weight:700;" id="rvInitials">—</div>
      <div>
        <div style="font-weight:700;font-size:14px;color:#0f172a;" id="rvName">—</div>
        <div style="font-size:11px;color:#94a3b8;" id="rvEmpNo"></div>
      </div>
    </div>

    <!-- Read-only attendance snapshot -->
    <div class="patt-review-detail-grid">
      <div class="patt-review-detail-item"><label>Date</label><span id="rvDate">—</span></div>
      <div class="patt-review-detail-item"><label>Status</label><span id="rvStatus">—</span></div>
      <div class="patt-review-detail-item"><label>Time In</label><span id="rvTimeIn">—</span></div>
      <div class="patt-review-detail-item"><label>Time Out</label><span id="rvTimeOut">—</span></div>
      <div class="patt-review-detail-item" style="grid-column:span 2;"><label>Remarks</label><span id="rvRemarks">—</span></div>
    </div>

    <!-- Current review status -->
    <div style="font-size:11px;color:#64748b;margin-bottom:12px;">
      Current review status: <span id="rvCurrentBadge" class="att-rv-badge" style="vertical-align:middle;">—</span>
    </div>

    <!-- Review decision -->
    <fieldset class="patt-review-radio-group" style="border:none;padding:0;margin:0;">
      <legend>Review Decision</legend>

      <label class="patt-review-radio-option" onclick="selectReviewOption(this, 'REVIEWED')">
        <input type="radio" name="review_status" value="REVIEWED">
        <div>
          <div class="opt-label"><i class="fa fa-eye" style="color:#1d4ed8;margin-right:4px;"></i> Reviewed</div>
          <div class="opt-desc">Acknowledged and reviewed — no issues found.</div>
        </div>
      </label>

      <label class="patt-review-radio-option" onclick="selectReviewOption(this, 'APPROVED')">
        <input type="radio" name="review_status" value="APPROVED">
        <div>
          <div class="opt-label"><i class="fa fa-circle-check" style="color:#15803d;margin-right:4px;"></i> Verified</div>
          <div class="opt-desc">Confirmed correct — attendance is accurately recorded.</div>
        </div>
      </label>

      <label class="patt-review-radio-option" onclick="selectReviewOption(this, 'FLAGGED')">
        <input type="radio" name="review_status" value="FLAGGED">
        <div>
          <div class="opt-label"><i class="fa fa-flag" style="color:#b91c1c;margin-right:4px;"></i> Needs Correction</div>
          <div class="opt-desc">Flag for admin to review and edit — record may be incorrect.</div>
        </div>
      </label>
    </fieldset>

    <label class="patt-review-remark-label" style="margin-top:14px;" for="rvRemark">
      Comment <span style="color:#94a3b8;font-weight:400;">(optional)</span>
    </label>
    <textarea id="rvRemark" class="patt-review-remark" placeholder="Add a comment or reason for this review decision..."></textarea>

    <div class="patt-review-modal-actions">
      <button class="patt-review-cancel-btn" onclick="closeReviewModalDirect()">Cancel</button>
      <button class="patt-review-save-btn" id="rvSaveBtn" onclick="submitReview()" disabled>
        <i class="fa fa-save"></i> Save Review
      </button>
    </div>
  </div>
</div>
<div id="pattToast" class="patt-toast"></div>
<?php endif; ?>

<script>
// ── Tab switching ───────────────────────────────────────────────────────────
const _tabMap = { today: 'Today', cutoff: 'Cutoff', history: 'History' };
function switchTab(name, btn) {
    document.querySelectorAll('.att-tab-pane').forEach(p => p.classList.add('hidden'));
    document.querySelectorAll('.att-tab').forEach(t => t.classList.remove('active'));
    document.getElementById('tab' + _tabMap[name]).classList.remove('hidden');
    if (btn) btn.classList.add('active');
}
function switchTabByName(name) {
    const btn = { today: null, cutoff: null, history: document.getElementById('tabBtnHistory') }[name];
    switchTab(name, btn);
    if (!btn) {
        document.querySelectorAll('.att-tab').forEach(t => {
            if (t.textContent.toLowerCase().includes(name)) t.classList.add('active');
        });
    }
}
function scrollToReviewFilter() {
    const el = document.getElementById('hrvFilter');
    if (el) { el.scrollIntoView({ behavior: 'smooth', block: 'center' }); el.focus(); }
}

// ── Debounce submit ─────────────────────────────────────────────────────────
const _dbTimers = {};
function debounceSubmit(formId, ms) {
    clearTimeout(_dbTimers[formId]);
    _dbTimers[formId] = setTimeout(() => document.getElementById(formId)?.submit(), ms);
}

<?php if ($hasMig007): ?>
// ── Review modal ────────────────────────────────────────────────────────────
let _rvAttId = null;
let _rvChoice = null;

function fmt12h(t) {
    if (!t) return '—';
    const parts = t.split(':');
    let h = parseInt(parts[0]), m = parts[1] || '00';
    const ampm = h >= 12 ? 'PM' : 'AM';
    h = h % 12 || 12;
    return h + ':' + m + ' ' + ampm;
}

function openReviewModal(id, name, empNo, date, timeIn, timeOut, status, source, remarks, currentRv) {
    _rvAttId  = id;
    _rvChoice = null;

    document.getElementById('rvInitials').textContent =
        name.split(' ').map(w => w[0] || '').join('').slice(0, 2).toUpperCase();
    document.getElementById('rvName').textContent    = name;
    document.getElementById('rvEmpNo').textContent   = empNo || '';
    document.getElementById('rvDate').textContent    = date;
    document.getElementById('rvStatus').textContent  = status;
    document.getElementById('rvTimeIn').textContent  = fmt12h(timeIn);
    document.getElementById('rvTimeOut').textContent = fmt12h(timeOut);
    document.getElementById('rvRemarks').textContent = remarks || '—';

    const badge = document.getElementById('rvCurrentBadge');
    const rvMap = { PENDING: 'rv-pending', REVIEWED: 'rv-reviewed', APPROVED: 'rv-approved', FLAGGED: 'rv-flagged', CORRECTED: 'rv-corrected' };
    const rvLabels = { PENDING: 'Pending', REVIEWED: 'Reviewed', APPROVED: 'Verified', FLAGGED: 'Needs Correction', CORRECTED: 'Corrected' };
    badge.className = 'att-rv-badge ' + (rvMap[currentRv] || 'rv-pending');
    badge.textContent = rvLabels[currentRv] || currentRv;

    // Reset options
    document.querySelectorAll('.patt-review-radio-option').forEach(opt => opt.classList.remove('selected'));
    document.querySelectorAll('input[name="review_status"]').forEach(r => r.checked = false);
    document.getElementById('rvRemark').value = '';
    document.getElementById('rvSaveBtn').disabled = true;

    document.getElementById('reviewModalBackdrop').classList.add('open');
}

function selectReviewOption(label, value) {
    document.querySelectorAll('.patt-review-radio-option').forEach(opt => opt.classList.remove('selected'));
    label.classList.add('selected');
    _rvChoice = value;
    document.getElementById('rvSaveBtn').disabled = false;
}

function closeReviewModal(e) {
    if (e.target === document.getElementById('reviewModalBackdrop')) closeReviewModalDirect();
}
function closeReviewModalDirect() {
    document.getElementById('reviewModalBackdrop').classList.remove('open');
    _rvAttId  = null;
    _rvChoice = null;
}

function showToast(msg, type) {
    const t = document.getElementById('pattToast');
    t.textContent = msg;
    t.className = 'patt-toast show ' + (type === 'ok' ? 'ok' : 'error');
    setTimeout(() => t.classList.remove('show'), 3500);
}

function submitReview() {
    if (!_rvAttId || !_rvChoice) return;
    const btn = document.getElementById('rvSaveBtn');
    btn.disabled = true;
    btn.innerHTML = '<i class="fa fa-spinner fa-spin"></i> Saving...';

    const fd = new FormData();
    fd.append('attendance_id',  _rvAttId);
    fd.append('review_status',  _rvChoice);
    fd.append('review_remark',  document.getElementById('rvRemark').value.trim());

    fetch('<?= BASE_URL ?>actions/principal-review-attendance.php', { method: 'POST', body: fd })
        .then(r => r.json())
        .then(data => {
            if (data.success) {
                showToast(data.message || 'Review saved.', 'ok');
                closeReviewModalDirect();
                setTimeout(() => location.reload(), 900);
            } else {
                showToast(data.message || 'Failed to save review.', 'error');
                btn.disabled = false;
                btn.innerHTML = '<i class="fa fa-save"></i> Save Review';
            }
        })
        .catch(() => {
            showToast('Network error. Please try again.', 'error');
            btn.disabled = false;
            btn.innerHTML = '<i class="fa fa-save"></i> Save Review';
        });
}
<?php endif; ?>
</script>

<?php include __DIR__ . '/../../../includes/footer.php'; ?>
