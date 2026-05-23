<?php
/**
 * actions/add-attendance.php
 * "Log Employee In" — records TIME IN for one or more employees.
 *
 * Key behavioural rules (per GEI attendance workflow):
 *  - time_in is ALWAYS the current server timestamp (not user-supplied)
 *  - attendance_date is ALWAYS today (not user-supplied)
 *  - attendance_source is always MANUAL_ADMIN
 *  - time_out is NOT set here; use timeout-attendance.php
 *  - Status is auto-computed from shift schedule (same logic as before)
 *
 * POST params:
 *   employee_ids[]   — one or more employee IDs (required)
 *   remarks          — optional notes
 *
 * Returns JSON:
 *   { success, inserted, skipped, errors[], message }
 */
header('Content-Type: application/json');
require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../includes/auth.php';
requireLogin();

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    echo json_encode(['success' => false, 'message' => 'Invalid request method.']);
    exit;
}

$rawIds = $_POST['employee_ids'] ?? [];
if (!is_array($rawIds) || empty($rawIds)) {
    echo json_encode(['success' => false, 'message' => 'At least one employee must be selected.']);
    exit;
}

$employeeIds = array_values(array_unique(array_filter(array_map('intval', $rawIds))));
if (empty($employeeIds)) {
    echo json_encode(['success' => false, 'message' => 'Invalid employee selection.']);
    exit;
}

$remarks    = trim($_POST['remarks'] ?? '') ?: null;
$date       = date('Y-m-d');       // Always today — enforced server-side
$timeIn     = date('H:i:s');       // Current server time
$userId     = $_SESSION['user']['user_id'] ?? null;

// ── Holiday check — takes priority over all other status logic ────────────────
$holCheck = $pdo->prepare("SELECT holiday_name FROM holidays WHERE holiday_date = ?");
$holCheck->execute([$date]);
$holidayRow   = $holCheck->fetch();
$isHoliday    = (bool)$holidayRow;
$holidayName  = $holidayRow['holiday_name'] ?? null;

// ── Helper: compute status from shift ────────────────────────────────────────
$toMins = fn($t) => $t ? (int)explode(':', $t)[0] * 60 + (int)explode(':', $t)[1] : 0;

$inserted = 0;
$skipped  = 0;
$errors   = [];

foreach ($employeeIds as $empId) {
    // ── Duplicate guard ───────────────────────────────────────────────────────
    $dup = $pdo->prepare(
        "SELECT attendance_id FROM attendance_records WHERE employee_id = ? AND attendance_date = ?"
    );
    $dup->execute([$empId, $date]);
    if ($dup->fetchColumn()) {
        $skipped++;
        $errors[] = "Employee #{$empId} already has an attendance record for today.";
        continue;
    }

    // ── Approved leave guard ──────────────────────────────────────────────────
    // Employees on approved leave for today cannot be logged in.
    $hasLeave = false;
    try {
        $lv = $pdo->prepare("
            SELECT COUNT(*)
            FROM leave_requests lr
            JOIN leave_request_dates lrd ON lr.request_id = lrd.request_id
            WHERE lr.employee_id = ? AND lrd.leave_date = ? AND lr.status = 'APPROVED'
        ");
        $lv->execute([$empId, $date]);
        $hasLeave = (bool)$lv->fetchColumn();
    } catch (PDOException $lvEx) {
        // leave_request_dates table not available — fall back to date-range check
        try {
            $lv2 = $pdo->prepare("
                SELECT COUNT(*) FROM leave_requests
                WHERE employee_id = ? AND ? BETWEEN start_date AND end_date AND status = 'APPROVED'
            ");
            $lv2->execute([$empId, $date]);
            $hasLeave = (bool)$lv2->fetchColumn();
        } catch (PDOException $lv2Ex) {
            $hasLeave = false;
        }
    }

    if ($hasLeave) {
        $skipped++;
        $errors[] = "Employee #{$empId} has approved leave for today and cannot be logged in.";
        continue;
    }

    // ── Status determination ──────────────────────────────────────────────────
    $status  = 'PRESENT';
    $empRemarks = $remarks;

    if ($isHoliday) {
        $status     = 'HOLIDAY';
        $empRemarks = $empRemarks ?? $holidayName;
    } else {
        // Fetch shift details
        $shiftStmt = $pdo->prepare("
            SELECT e.employment_type,
                   s.start_time, s.grace_period_minutes, s.half_day_time
            FROM employees e
            LEFT JOIN shifts s ON e.shift_id = s.shift_id
            WHERE e.employee_id = ?
        ");
        $shiftStmt->execute([$empId]);
        $emp = $shiftStmt->fetch(PDO::FETCH_ASSOC);

        if ($emp && $emp['start_time']) {
            $shiftStart  = $toMins($emp['start_time']);
            $timeInMins  = $toMins($timeIn);
            $isPartTime  = ($emp['employment_type'] === 'PART_TIME');

            if ($isPartTime) {
                $status = $timeInMins <= $shiftStart ? 'PRESENT' : 'LATE';
            } else {
                $halfDayMins = $emp['half_day_time']
                    ? $toMins($emp['half_day_time'])
                    : 9 * 60;

                if ($timeInMins <= $shiftStart) {
                    $status = 'PRESENT';
                } elseif ($timeInMins < $halfDayMins) {
                    $status = 'LATE';
                } else {
                    $status = 'HALF_DAY';
                }
            }
        }
    }

    // ── Validate status enum ──────────────────────────────────────────────────
    $allowed = ['PRESENT', 'ABSENT', 'LATE', 'HALF_DAY', 'INCOMPLETE', 'LEAVE', 'HOLIDAY'];
    if (!in_array($status, $allowed, true)) $status = 'PRESENT';

    // ── Insert ────────────────────────────────────────────────────────────────
    try {
        $pdo->prepare("
            INSERT INTO attendance_records
                (employee_id, attendance_date, time_in,
                 attendance_status, attendance_source, remarks)
            VALUES (?, ?, ?, ?, 'MANUAL_ADMIN', ?)
        ")->execute([$empId, $date, $timeIn, $status, $empRemarks]);

        $newId = (int)$pdo->lastInsertId();

        // Audit log — CREATE action (requires migration 007; silently skipped if not yet applied)
        if ($userId) {
            try {
                $pdo->prepare("
                    INSERT INTO attendance_audit_log
                        (attendance_id, changed_by, action_type,
                         new_time_in, new_status, new_method, new_remarks, reason)
                    VALUES (?, ?, 'CREATE', ?, ?, 'MANUAL_ADMIN', ?, 'Logged in via Admin')
                ")->execute([$newId, $userId, $timeIn, $status, $empRemarks]);
            } catch (PDOException $auditEx) {
                // Audit table absent (migration 007 not yet applied) — non-fatal
            }
        }

        $inserted++;
    } catch (PDOException $e) {
        if ($e->getCode() === '23000') {
            $skipped++;
            $errors[] = "Employee #{$empId}: duplicate record.";
        } else {
            $errors[] = "Employee #{$empId}: " . $e->getMessage();
        }
    }
}

echo json_encode([
    'success'  => $inserted > 0,
    'inserted' => $inserted,
    'skipped'  => $skipped,
    'errors'   => $errors,
    'message'  => $inserted > 0
        ? "Logged in {$inserted} employee(s) at " . date('h:i A') . ($skipped > 0 ? " ({$skipped} skipped)" : '')
        : 'No records were inserted. ' . implode(' ', $errors),
]);
