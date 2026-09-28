<?php
/**
 * actions/dtr-reconcile.php
 * Admin review workflow for uploaded DTR backup files.
 *
 * A DTR upload represents one offline/crash day. Reconciliation can create or
 * correct many employee attendance rows for that fixed upload date.
 */

require_once __DIR__ . '/../config/config.php';
require_once __DIR__ . '/../includes/auth.php';

header('Content-Type: application/json');

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    echo json_encode(['success' => false, 'message' => 'Invalid method.']);
    exit;
}

requireAdminAction();
requireCsrfToken();

$userId = $_SESSION['user']['user_id'] ?? null;
$action = trim($_POST['action'] ?? '');
$attachmentId = (int)($_POST['attachment_id'] ?? 0);

function dtrJsonError(string $message, int $status = 200): void
{
    global $pdo;
    if ($pdo instanceof PDO && $pdo->inTransaction()) {
        $pdo->rollBack();
    }
    http_response_code($status);
    echo json_encode(['success' => false, 'message' => $message]);
    exit;
}

function dtrTableExists(PDO $pdo, string $table): bool
{
    $stmt = $pdo->prepare("
        SELECT COUNT(*)
        FROM information_schema.TABLES
        WHERE TABLE_SCHEMA = DATABASE()
          AND TABLE_NAME = ?
    ");
    $stmt->execute([$table]);
    return (bool)$stmt->fetchColumn();
}

function dtrColumnExists(PDO $pdo, string $table, string $column): bool
{
    $stmt = $pdo->prepare("
        SELECT COUNT(*)
        FROM information_schema.COLUMNS
        WHERE TABLE_SCHEMA = DATABASE()
          AND TABLE_NAME = ?
          AND COLUMN_NAME = ?
    ");
    $stmt->execute([$table, $column]);
    return (bool)$stmt->fetchColumn();
}

function dtrNormalizeTime(?string $value): ?string
{
    $value = trim((string)$value);
    if ($value === '') {
        return null;
    }
    if (!preg_match('/^\d{2}:\d{2}(:\d{2})?$/', $value)) {
        dtrJsonError('Invalid time format. Use HH:MM.');
    }
    return strlen($value) === 5 ? $value . ':00' : $value;
}

function dtrToMinutes(?string $time): int
{
    if (!$time) {
        return 0;
    }
    $parts = explode(':', $time);
    return ((int)$parts[0] * 60) + (int)$parts[1];
}

function dtrAttendanceCalculations(PDO $pdo, int $employeeId, string $date, ?string $timeIn, ?string $timeOut): array
{
    $lateMinutes = 0;
    $overtimeMinutes = 0;

    $shiftStmt = $pdo->prepare("
        SELECT s.start_time, s.grace_period_minutes, s.end_time
        FROM employees e
        LEFT JOIN shifts s ON e.shift_id = s.shift_id
        WHERE e.employee_id = ?
    ");
    $shiftStmt->execute([$employeeId]);
    $shift = $shiftStmt->fetch(PDO::FETCH_ASSOC);

    if ($shift && $timeIn && $shift['start_time']) {
        $shiftStart = strtotime($date . ' ' . $shift['start_time']);
        $actualIn = strtotime($date . ' ' . $timeIn);
        $grace = (int)($shift['grace_period_minutes'] ?? 0);
        $diff = ($actualIn - $shiftStart - ($grace * 60)) / 60;
        $lateMinutes = $diff > 0 ? (int)$diff : 0;
    }

    if ($shift && $timeOut && $shift['end_time']) {
        $overtimeMinutes = max(0, dtrToMinutes($timeOut) - dtrToMinutes($shift['end_time']));
    }

    return [$lateMinutes, $overtimeMinutes];
}

if (!$attachmentId) {
    dtrJsonError('DTR upload ID is required.');
}

if (!dtrColumnExists($pdo, 'dtr_attachments', 'reconciliation_status')) {
    dtrJsonError('DTR reconciliation requires database migration 031. Please run the migration first.', 500);
}

if (!dtrTableExists($pdo, 'dtr_reconciliation_entries')) {
    dtrJsonError('DTR reconciliation requires database migration 032. Please run the migration first.', 500);
}

$validActions = ['reconcile_batch', 'reject'];
if (!in_array($action, $validActions, true)) {
    dtrJsonError('Unknown DTR review action.');
}

$validStatuses = ['PRESENT', 'ABSENT', 'LATE', 'HALF_DAY', 'INCOMPLETE', 'LEAVE', 'HOLIDAY'];
$hasOtCol = dtrColumnExists($pdo, 'attendance_records', 'overtime_minutes');

try {
    $pdo->beginTransaction();

    $dtrStmt = $pdo->prepare("
        SELECT *
        FROM dtr_attachments
        WHERE attachment_id = ?
        FOR UPDATE
    ");
    $dtrStmt->execute([$attachmentId]);
    $dtr = $dtrStmt->fetch(PDO::FETCH_ASSOC);

    if (!$dtr) {
        $pdo->rollBack();
        dtrJsonError('DTR upload not found.');
    }

    if (($dtr['reconciliation_status'] ?? 'pending') !== 'pending') {
        $pdo->rollBack();
        dtrJsonError('This DTR upload has already been reviewed.');
    }

    if ($action === 'reject') {
        $reason = trim($_POST['reason'] ?? '');
        $reasonError = validateRequiredReason($reason, 'Rejection reason');
        if ($reasonError !== null) {
            $pdo->rollBack();
            dtrJsonError($reasonError);
        }

        $pdo->prepare("
            UPDATE dtr_attachments
            SET reconciliation_status = 'rejected',
                rejection_reason = ?,
                reconciliation_notes = NULL,
                linked_attendance_id = NULL,
                reviewed_by = ?,
                reviewed_at = NOW()
            WHERE attachment_id = ?
        ")->execute([$reason, $userId, $attachmentId]);

        $pdo->prepare("
            INSERT INTO audit_logs (user_id, action, table_name, record_id, description, created_at)
            VALUES (?, 'REJECT', 'dtr_attachments', ?, ?, NOW())
        ")->execute([$userId, $attachmentId, "Rejected DTR upload #{$attachmentId}: {$reason}"]);

        $pdo->commit();
        echo json_encode(['success' => true, 'message' => 'DTR upload rejected.']);
        exit;
    }

    if (($dtr['cutoff_start'] ?? '') !== ($dtr['cutoff_end'] ?? '')) {
        $pdo->rollBack();
        dtrJsonError('This DTR upload covers multiple dates. Re-upload one file per unavailable day before reconciliation.');
    }

    $attendanceDate = $dtr['cutoff_start'] ?? '';
    if (!$attendanceDate || !preg_match('/^\d{4}-\d{2}-\d{2}$/', $attendanceDate)) {
        $pdo->rollBack();
        dtrJsonError('This DTR upload does not have a valid fixed attendance date.');
    }
    if ($attendanceDate > date('Y-m-d')) {
        $pdo->rollBack();
        dtrJsonError('Attendance date cannot be in the future.');
    }

    $entriesJson = $_POST['entries_json'] ?? '';
    $entries = json_decode($entriesJson, true);
    if (!is_array($entries) || empty($entries)) {
        $pdo->rollBack();
        dtrJsonError('Add at least one employee entry before marking the DTR upload reconciled.');
    }

    $seenEmployees = [];
    $processed = 0;
    $created = 0;
    $updated = 0;
    $attendanceIds = [];

    foreach ($entries as $idx => $entry) {
        $rowNumber = $idx + 1;
        if (!is_array($entry)) {
            $pdo->rollBack();
            dtrJsonError("Entry {$rowNumber} is invalid.");
        }

        $entryAction = strtolower(trim($entry['entry_action'] ?? ''));
        $employeeId = (int)($entry['employee_id'] ?? 0);
        $timeIn = dtrNormalizeTime($entry['time_in'] ?? null);
        $timeOut = dtrNormalizeTime($entry['time_out'] ?? null);
        $status = strtoupper(trim($entry['attendance_status'] ?? 'PRESENT'));
        $reason = trim($entry['reason'] ?? '');
        $remarks = trim($entry['remarks'] ?? '') ?: null;
        if ($remarks !== null) {
            $remarks = substr($remarks, 0, 255);
        }
        $source = 'MANUAL';

        if (!in_array($entryAction, ['create', 'update'], true)) {
            $pdo->rollBack();
            dtrJsonError("Entry {$rowNumber}: choose Create New or Correct Existing.");
        }
        if (!$employeeId) {
            $pdo->rollBack();
            dtrJsonError("Entry {$rowNumber}: employee is required.");
        }
        if (isset($seenEmployees[$employeeId])) {
            $pdo->rollBack();
            dtrJsonError("Entry {$rowNumber}: duplicate employee selected for this DTR date.");
        }
        $seenEmployees[$employeeId] = true;
        if (!in_array($status, $validStatuses, true)) {
            $pdo->rollBack();
            dtrJsonError("Entry {$rowNumber}: invalid attendance status.");
        }
        $reasonError = validateRequiredReason($reason, "Entry {$rowNumber} reason");
        if ($reasonError !== null) {
            $pdo->rollBack();
            dtrJsonError($reasonError);
        }

        $empStmt = $pdo->prepare("
            SELECT employee_id, department_id, employment_type
            FROM employees
            WHERE employee_id = ?
        ");
        $empStmt->execute([$employeeId]);
        $employee = $empStmt->fetch(PDO::FETCH_ASSOC);
        if (!$employee) {
            $pdo->rollBack();
            dtrJsonError("Entry {$rowNumber}: selected employee was not found.");
        }
        if (!empty($dtr['employee_id']) && (int)$dtr['employee_id'] !== $employeeId) {
            $pdo->rollBack();
            dtrJsonError("Entry {$rowNumber}: selected employee does not match this employee-scoped DTR upload.");
        }
        if (!empty($dtr['department_id']) && (int)$dtr['department_id'] !== (int)($employee['department_id'] ?? 0)) {
            $pdo->rollBack();
            dtrJsonError("Entry {$rowNumber}: selected employee is outside the DTR upload department scope.");
        }
        if (($employee['employment_type'] ?? 'FULL_TIME') === 'PART_TIME' && $status === 'HALF_DAY') {
            $pdo->rollBack();
            dtrJsonError("Entry {$rowNumber}: part-time employees cannot be classified as Half Day.");
        }

        [$lateMinutes, $overtimeMinutes] = dtrAttendanceCalculations($pdo, $employeeId, $attendanceDate, $timeIn, $timeOut);

        $existingStmt = $pdo->prepare("
            SELECT attendance_id, time_in, time_out, attendance_status, attendance_source, remarks
            FROM attendance_records
            WHERE employee_id = ? AND attendance_date = ?
            FOR UPDATE
        ");
        $existingStmt->execute([$employeeId, $attendanceDate]);
        $existing = $existingStmt->fetch(PDO::FETCH_ASSOC);

        if ($entryAction === 'create') {
            if ($existing) {
                $pdo->rollBack();
                dtrJsonError("Entry {$rowNumber}: an attendance record already exists. Use Correct Existing instead.");
            }

            if ($hasOtCol) {
                $pdo->prepare("
                    INSERT INTO attendance_records
                        (employee_id, attendance_date, time_in, time_out, overtime_minutes,
                         late_minutes, attendance_status, attendance_source, remarks)
                    VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)
                ")->execute([
                    $employeeId, $attendanceDate, $timeIn, $timeOut, $overtimeMinutes,
                    $lateMinutes, $status, $source, $remarks,
                ]);
            } else {
                $pdo->prepare("
                    INSERT INTO attendance_records
                        (employee_id, attendance_date, time_in, time_out,
                         late_minutes, attendance_status, attendance_source, remarks)
                    VALUES (?, ?, ?, ?, ?, ?, ?, ?)
                ")->execute([
                    $employeeId, $attendanceDate, $timeIn, $timeOut,
                    $lateMinutes, $status, $source, $remarks,
                ]);
            }

            $attendanceId = (int)$pdo->lastInsertId();
            $pdo->prepare("
                INSERT INTO attendance_audit_log
                    (attendance_id, changed_by, action_type,
                     new_time_in, new_time_out, new_status, new_method, new_remarks, reason)
                VALUES (?, ?, 'CREATE', ?, ?, ?, ?, ?, ?)
            ")->execute([
                $attendanceId, $userId,
                $timeIn, $timeOut, $status, $source, $remarks,
                'DTR upload reconciliation #' . $attachmentId . ': ' . $reason,
            ]);
            $created++;
        } else {
            if (!$existing) {
                $pdo->rollBack();
                dtrJsonError("Entry {$rowNumber}: no existing attendance record found. Use Create New instead.");
            }

            $attendanceId = (int)$existing['attendance_id'];
            if ($hasOtCol) {
                $pdo->prepare("
                    UPDATE attendance_records
                    SET time_in = ?, time_out = ?, attendance_status = ?,
                        attendance_source = ?, late_minutes = ?, overtime_minutes = ?,
                        remarks = ?, updated_at = NOW()
                    WHERE attendance_id = ?
                ")->execute([
                    $timeIn, $timeOut, $status,
                    $source, $lateMinutes, $overtimeMinutes,
                    $remarks, $attendanceId,
                ]);
            } else {
                $pdo->prepare("
                    UPDATE attendance_records
                    SET time_in = ?, time_out = ?, attendance_status = ?,
                        attendance_source = ?, late_minutes = ?,
                        remarks = ?, updated_at = NOW()
                    WHERE attendance_id = ?
                ")->execute([
                    $timeIn, $timeOut, $status,
                    $source, $lateMinutes,
                    $remarks, $attendanceId,
                ]);
            }

            $pdo->prepare("
                INSERT INTO attendance_audit_log
                    (attendance_id, changed_by, action_type,
                     old_time_in, old_time_out, old_status, old_method, old_remarks,
                     new_time_in, new_time_out, new_status, new_method, new_remarks, reason)
                VALUES (?, ?, 'EDIT', ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)
            ")->execute([
                $attendanceId, $userId,
                $existing['time_in'], $existing['time_out'], $existing['attendance_status'],
                $existing['attendance_source'], $existing['remarks'],
                $timeIn, $timeOut, $status, $source, $remarks,
                'DTR upload reconciliation #' . $attachmentId . ': ' . $reason,
            ]);
            $updated++;
        }

        $pdo->prepare("
            INSERT INTO dtr_reconciliation_entries
                (attachment_id, attendance_id, employee_id, attendance_date, entry_action,
                 time_in, time_out, attendance_status, remarks, reason, created_by)
            VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)
        ")->execute([
            $attachmentId, $attendanceId, $employeeId, $attendanceDate, $entryAction,
            $timeIn, $timeOut, $status, $remarks, $reason, $userId,
        ]);

        $attendanceIds[] = $attendanceId;
        $processed++;
    }

    $summary = "Reconciled {$processed} employee entr" . ($processed === 1 ? 'y' : 'ies') .
        " for {$attendanceDate} ({$created} created, {$updated} corrected).";

    $pdo->prepare("
        UPDATE dtr_attachments
        SET reconciliation_status = 'reconciled',
            linked_attendance_id = NULL,
            reconciliation_notes = ?,
            rejection_reason = NULL,
            reviewed_by = ?,
            reviewed_at = NOW()
        WHERE attachment_id = ?
    ")->execute([$summary, $userId, $attachmentId]);

    $pdo->prepare("
        INSERT INTO audit_logs (user_id, action, table_name, record_id, description, created_at)
        VALUES (?, 'RECONCILE', 'dtr_attachments', ?, ?, NOW())
    ")->execute([
        $userId,
        $attachmentId,
        "Reconciled DTR upload #{$attachmentId}: {$summary}",
    ]);

    $pdo->commit();
    echo json_encode([
        'success' => true,
        'message' => $summary,
        'attendance_ids' => $attendanceIds,
    ]);

} catch (Throwable $e) {
    if ($pdo->inTransaction()) {
        $pdo->rollBack();
    }
    error_log('[dtr] Reconciliation failed: ' . $e->getMessage());
    http_response_code(500);
    echo json_encode(['success' => false, 'message' => 'DTR review failed. No changes were saved.']);
}
