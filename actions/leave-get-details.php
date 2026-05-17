<?php
/**
 * actions/leave-get-details.php
 * Returns full leave request details for the View modal.
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
    // ── Full leave request ───────────────────────────────────
    $stmt = $pdo->prepare("
        SELECT
            lr.*,
            CONCAT(e.first_name, ' ', e.last_name)   AS employee_name,
            e.employee_id,
            lt.leave_name,
            DATE_FORMAT(lr.created_at, '%b %d, %Y')  AS applied_date,
            CONCAT(ae.first_name, ' ', ae.last_name)  AS approver_name
        FROM leave_requests lr
        JOIN employees e    ON lr.employee_id   = e.employee_id
        JOIN leave_types lt ON lr.leave_type_id = lt.leave_type_id
        LEFT JOIN users u   ON lr.approved_by   = u.user_id
        LEFT JOIN employees ae ON u.employee_id = ae.employee_id
        WHERE lr.leave_id = ?
    ");
    $stmt->execute([$leaveId]);
    $record = $stmt->fetch();

    if (!$record) {
        echo json_encode(['success' => false, 'message' => 'Leave request not found.']);
        exit;
    }

    // ── Per-date rows from leave_request_dates ───────────────
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

    // Fallback for legacy records without leave_request_dates rows
    if (empty($dates)) {
        $start    = new DateTime($record['start_date']);
        $end      = new DateTime($record['end_date']);
        $interval = new DateInterval('P1D');
        $period   = new DatePeriod($start, $interval, (clone $end)->modify('+1 day'));

        $dates = [];
        foreach ($period as $dt) {
            $dates[] = [
                'date_id'        => null,
                'leave_date'     => $dt->format('Y-m-d'),
                'date_formatted' => $dt->format('M d, Y'),
                'status'         => $record['status'],
                'remarks'        => null,
            ];
        }
    }

    echo json_encode([
        'success' => true,
        'record'  => $record,
        'dates'   => $dates,
    ]);

} catch (PDOException $e) {
    echo json_encode(['success' => false, 'message' => 'Database error: ' . $e->getMessage()]);
}