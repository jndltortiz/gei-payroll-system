<?php
/**
 * actions/leave-get-expand.php
 * Returns leave balance + per-date breakdown (from leave_request_dates).
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

try {
    // ── Fetch leave request + employee info ──────────────────
    $stmtLR = $pdo->prepare("
        SELECT lr.*, e.first_name, e.last_name, e.employee_id, lt.leave_type_id
        FROM leave_requests lr
        JOIN employees e  ON lr.employee_id   = e.employee_id
        JOIN leave_types lt ON lr.leave_type_id = lt.leave_type_id
        WHERE lr.leave_id = ?
    ");
    $stmtLR->execute([$leaveId]);
    $leave = $stmtLR->fetch();

    if (!$leave) {
        echo json_encode(['success' => false, 'message' => 'Leave request not found.']);
        exit;
    }

    $empId = $leave['employee_id'];

    // ── Leave Balance ────────────────────────────────────────
    // Prefer the new employee_leave_credits system when:
    //   (a) this leave request has a school_year_id, AND
    //   (b) a credit row exists for this employee/year/type.
    // Fall back to the legacy payroll_settings + SUM(total_days) calculation
    // so existing records continue to display correctly.
    $schoolYearId = $leave['school_year_id'] ?? null;
    $leaveTypeId  = $leave['leave_type_id']  ?? null;
    $balance      = null;

    if ($schoolYearId && $leaveTypeId) {
        $stmtCredit = $pdo->prepare("
            SELECT allocated_days, used_days,
                   (allocated_days - used_days) AS remaining_days
            FROM   employee_leave_credits
            WHERE  employee_id    = ?
              AND  school_year_id = ?
              AND  leave_type_id  = ?
        ");
        $stmtCredit->execute([$empId, $schoolYearId, $leaveTypeId]);
        $credit = $stmtCredit->fetch();

        if ($credit) {
            $balance = [
                'employee_name'      => trim($leave['first_name'] . ' ' . $leave['last_name']),
                'standard_leave'     => number_format((float)$credit['allocated_days'], 1),
                'days_used'          => number_format((float)$credit['used_days'], 1),
                'remaining'          => number_format((float)$credit['remaining_days'], 1),
                'service_credits'    => null, // not applicable in new system
                'uses_credit_system' => true,
            ];
        }
    }

    // Legacy fallback: payroll_settings default + SUM of approved requests
    if (!$balance) {
        $stmtSettings = $pdo->query("SELECT default_paid_leave_days FROM payroll_settings LIMIT 1");
        $settings     = $stmtSettings->fetch();
        $standardDays = (float)($settings['default_paid_leave_days'] ?? 30);

        $stmtUsed = $pdo->prepare("
            SELECT COALESCE(SUM(total_days), 0)
            FROM   leave_requests
            WHERE  employee_id = ? AND status = 'APPROVED'
        ");
        $stmtUsed->execute([$empId]);
        $daysUsed = (float)$stmtUsed->fetchColumn();

        $stmtSC = $pdo->prepare("
            SELECT COALESCE(SUM(days), 0)
            FROM   service_credits
            WHERE  employee_id = ? AND is_approved = 1
        ");
        $stmtSC->execute([$empId]);
        $serviceCredits = (float)$stmtSC->fetchColumn();

        $balance = [
            'employee_name'      => trim($leave['first_name'] . ' ' . $leave['last_name']),
            'standard_leave'     => number_format($standardDays, 0),
            'service_credits'    => number_format($serviceCredits, 0),
            'days_used'          => number_format($daysUsed, 1),
            'remaining'          => number_format($standardDays - $daysUsed + $serviceCredits, 1),
            'uses_credit_system' => false,
        ];
    }

    // ── Per-date breakdown from leave_request_dates ──────────
    $stmtDates = $pdo->prepare("
        SELECT
            lrd.date_id,
            lrd.leave_date,
            DATE_FORMAT(lrd.leave_date, '%b %d, %Y') AS date_formatted,
            lrd.status,
            lrd.remarks
        FROM leave_request_dates lrd
        WHERE lrd.leave_id = ?
        ORDER BY lrd.leave_date ASC
    ");
    $stmtDates->execute([$leaveId]);
    $dates = $stmtDates->fetchAll();

    // Fallback: if leave_request_dates is empty (legacy records),
    // derive dates from start_date → end_date with the parent status
    if (empty($dates)) {
        $start    = new DateTime($leave['start_date']);
        $end      = new DateTime($leave['end_date']);
        $interval = new DateInterval('P1D');
        $period   = new DatePeriod($start, $interval, (clone $end)->modify('+1 day'));

        $dates = [];
        foreach ($period as $dt) {
            $dates[] = [
                'date_id'        => null,           // no per-date record
                'leave_date'     => $dt->format('Y-m-d'),
                'date_formatted' => $dt->format('M d, Y'),
                'status'         => $leave['status'],
                'remarks'        => null,
            ];
        }
    }

    echo json_encode([
        'success' => true,
        'balance' => $balance,
        'dates'   => $dates,
    ]);

} catch (PDOException $e) {
    echo json_encode(['success' => false, 'message' => 'Database error: ' . $e->getMessage()]);
}