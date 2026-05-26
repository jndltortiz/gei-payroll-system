<?php
/**
 * actions/leave-get-details.php
 * Returns full leave request details for View modals (admin, principal, employee).
 * GET: leave_id
 */

require_once __DIR__ . '/../config/config.php';
require_once __DIR__ . '/../includes/auth.php';

header('Content-Type: application/json');

requireLogin();

$leaveId = (int)($_GET['leave_id'] ?? 0);
if (!$leaveId) {
    echo json_encode(['success' => false, 'message' => 'Invalid leave ID.']);
    exit;
}

// Migration guards
$hasMig016   = (bool)$pdo->query("SHOW COLUMNS FROM `leave_requests` LIKE 'workflow_status'")->fetch();
$hasMig017LR = (bool)$pdo->query("SHOW COLUMNS FROM `leave_requests` LIKE 'filing_mode'")->fetch();

// Employee-portal scope: only their own requests
$role   = strtolower($_SESSION['user']['role'] ?? '');
$myEmpId = (int)($_SESSION['user']['employee_id'] ?? 0);

try {
    // ── Full leave request with employee info ────────────────────────────────
    $workflowSel = $hasMig016
        ? ', lr.workflow_status, lr.admin_note, lr.forwarded_at, lr.is_backdated, lr.backdate_reason'
        : ", 'PENDING_REVIEW' AS workflow_status, NULL AS admin_note, NULL AS forwarded_at, 0 AS is_backdated, NULL AS backdate_reason";

    $workflowSel .= $hasMig017LR
        ? ', lr.filing_mode'
        : ", 'MULTIPLE' AS filing_mode";

    $stmt = $pdo->prepare("
        SELECT
            lr.*
            {$workflowSel},
            CONCAT(e.first_name, ' ', e.last_name)   AS employee_name,
            e.employee_no,
            e.employee_id,
            d.department_name,
            p.position_name,
            lt.leave_name,
            lt.leave_type_id,
            DATE_FORMAT(lr.created_at, '%b %d, %Y')  AS applied_date,
            DATE_FORMAT(lr.created_at, '%Y-%m-%d')   AS applied_date_raw,
            CONCAT(ae.first_name, ' ', ae.last_name)  AS approver_name,
            CONCAT(fe.first_name, ' ', fe.last_name)  AS forwarded_by_name
        FROM leave_requests lr
        JOIN employees e    ON lr.employee_id    = e.employee_id
        JOIN departments d  ON e.department_id   = d.department_id
        JOIN positions   p  ON e.position_id     = p.position_id
        JOIN leave_types lt ON lr.leave_type_id  = lt.leave_type_id
        LEFT JOIN users   u  ON lr.approved_by   = u.user_id
        LEFT JOIN employees ae ON u.employee_id  = ae.employee_id
        LEFT JOIN users   fu  ON lr.forwarded_by = fu.user_id
        LEFT JOIN employees fe ON fu.employee_id = fe.employee_id
        WHERE lr.leave_id = ?
    ");
    $stmt->execute([$leaveId]);
    $record = $stmt->fetch();

    if (!$record) {
        echo json_encode(['success' => false, 'message' => 'Leave request not found.']);
        exit;
    }

    // Scope-guard for employee role
    if ($role === 'employee' && (int)$record['employee_id'] !== $myEmpId) {
        echo json_encode(['success' => false, 'message' => 'Access denied.']);
        exit;
    }

    // ── Per-date rows ────────────────────────────────────────────────────────
    $stmtDates = $pdo->prepare("
        SELECT
            lrd.date_id,
            lrd.leave_date,
            DATE_FORMAT(lrd.leave_date, '%b %d, %Y') AS date_formatted,
            lrd.status,
            lrd.remarks                               AS principal_note,
            DATE_FORMAT(lrd.actioned_at, '%b %d, %Y %H:%i') AS actioned_at_fmt,
            CONCAT(ae2.first_name,' ',ae2.last_name) AS actioned_by_name
        FROM leave_request_dates lrd
        LEFT JOIN users       u2  ON lrd.actioned_by = u2.user_id
        LEFT JOIN employees   ae2 ON u2.employee_id  = ae2.employee_id
        WHERE lrd.leave_id = ?
        ORDER BY lrd.leave_date ASC
    ");
    $stmtDates->execute([$leaveId]);
    $dates = $stmtDates->fetchAll();

    // Legacy fallback for records without leave_request_dates rows
    if (empty($dates)) {
        $start    = new DateTime($record['start_date']);
        $end      = new DateTime($record['end_date']);
        $interval = new DateInterval('P1D');
        $period   = new DatePeriod($start, $interval, (clone $end)->modify('+1 day'));
        $dates    = [];
        foreach ($period as $dt) {
            $dates[] = [
                'date_id'        => null,
                'leave_date'     => $dt->format('Y-m-d'),
                'date_formatted' => $dt->format('M d, Y'),
                'status'         => $record['status'],
                'principal_note' => null,
                'actioned_at_fmt'   => null,
                'actioned_by_name'  => null,
            ];
        }
    }

    // ── Leave balance for this employee + type ────────────────────────────────
    $balance = null;
    $schoolYearId = $record['school_year_id'] ?? null;
    $leaveTypeId  = $record['leave_type_id']  ?? null;
    $empId        = (int)$record['employee_id'];

    // If the leave request has no school_year_id, fall back to the active school year
    if (!$schoolYearId && $leaveTypeId) {
        $syRow = $pdo->query("SELECT school_year_id FROM school_years WHERE is_active = 1 LIMIT 1")->fetch();
        if ($syRow) $schoolYearId = $syRow['school_year_id'];
    }

    if ($schoolYearId && $leaveTypeId) {
        $stmtBal = $pdo->prepare("
            SELECT allocated_days, used_days,
                   (allocated_days - used_days) AS remaining_days
            FROM   employee_leave_credits
            WHERE  employee_id    = ?
              AND  school_year_id = ?
              AND  leave_type_id  = ?
        ");
        $stmtBal->execute([$empId, $schoolYearId, $leaveTypeId]);
        $balRow = $stmtBal->fetch();

        if ($balRow) {
            $balance = [
                'allocated' => number_format((float)$balRow['allocated_days'], 1),
                'used'      => number_format((float)$balRow['used_days'], 1),
                'remaining' => number_format((float)$balRow['remaining_days'], 1),
                'source'    => 'credits',
            ];
        }
    }

    if (!$balance) {
        $stmtSettings = $pdo->query("SELECT default_paid_leave_days FROM payroll_settings LIMIT 1");
        $settings     = $stmtSettings->fetch();
        $defaultDays  = (float)($settings['default_paid_leave_days'] ?? 30);

        $stmtUsed = $pdo->prepare("
            SELECT COALESCE(SUM(total_days), 0)
            FROM   leave_requests
            WHERE  employee_id = ? AND status = 'APPROVED'
        ");
        $stmtUsed->execute([$empId]);
        $daysUsed = (float)$stmtUsed->fetchColumn();

        $balance = [
            'allocated' => number_format($defaultDays, 0),
            'used'      => number_format($daysUsed, 1),
            'remaining' => number_format($defaultDays - $daysUsed, 1),
            'source'    => 'legacy',
        ];
    }

    // ── Attachments ──────────────────────────────────────────────────────────
    $attachments = [];
    try {
        $stmtAtt = $pdo->prepare("
            SELECT attachment_id, file_name, file_path, file_type, file_size, uploaded_at
            FROM   leave_attachments
            WHERE  leave_id = ?
            ORDER  BY uploaded_at ASC
        ");
        $stmtAtt->execute([$leaveId]);
        $attachments = $stmtAtt->fetchAll();
    } catch (PDOException $e) {
        // Table may not exist on older installs; silently continue
    }

    echo json_encode([
        'success'     => true,
        'record'      => $record,
        'dates'       => $dates,
        'balance'     => $balance,
        'attachments' => $attachments,
    ]);

} catch (PDOException $e) {
    echo json_encode(['success' => false, 'message' => 'Database error: ' . $e->getMessage()]);
}
