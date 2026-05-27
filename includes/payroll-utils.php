<?php
/**
 * includes/payroll-utils.php
 * Shared payroll helper functions.
 * Include with require_once in every action file that touches payroll_records.
 */

/**
 * Recompute a payroll record's totals from its child allowance/deduction rows.
 *
 * Call this after any INSERT, UPDATE, or DELETE on payroll_allowances or
 * payroll_deductions — never sum in PHP, because manual overrides and
 * service-credit applications all modify those rows independently.
 */
function recalculatePayrollTotals(PDO $pdo, int $payrollId): void
{
    $pdo->prepare("
        UPDATE payroll_records pr
        SET
            total_allowances = (
                SELECT COALESCE(SUM(pa.amount), 0)
                FROM payroll_allowances pa
                WHERE pa.payroll_id = pr.payroll_id
            ),
            total_deductions = (
                SELECT COALESCE(SUM(pd.amount), 0)
                FROM payroll_deductions pd
                WHERE pd.payroll_id = pr.payroll_id
            ),
            gross_pay = pr.basic_pay + (
                SELECT COALESCE(SUM(pa.amount), 0)
                FROM payroll_allowances pa
                WHERE pa.payroll_id = pr.payroll_id
            ),
            net_pay = (
                pr.basic_pay
                + (SELECT COALESCE(SUM(pa.amount), 0) FROM payroll_allowances pa WHERE pa.payroll_id = pr.payroll_id)
                - (SELECT COALESCE(SUM(pd.amount), 0) FROM payroll_deductions pd WHERE pd.payroll_id = pr.payroll_id)
            ),
            updated_at = NOW()
        WHERE pr.payroll_id = ?
    ")->execute([$payrollId]);
}

/**
 * Compute the absence deduction for one employee in one pay period.
 *
 * Logic:
 *   total_excess  = SUM per leave type of MAX(0, used_days - allocated_days)
 *   already_taken = absence_days already deducted in OTHER periods this school year
 *   new_days      = MAX(0, total_excess - already_taken)
 *   amount        = new_days × daily_rate
 *
 * Returns an array [days, amount, notes] or null when nothing to deduct.
 * Requires migration 004 (is_absence_deduction, absence_days, notes columns).
 */
function computeAbsenceDeduction(
    PDO $pdo,
    int $employeeId,
    int $periodId,
    float $dailyRate
): ?array {
    $syRow = $pdo->query("SELECT school_year_id FROM school_years WHERE is_active = 1 LIMIT 1")->fetch();
    if (!$syRow) return null;
    $schoolYearId = (int)$syRow['school_year_id'];

    $stmt = $pdo->prepare("
        SELECT lt.leave_name,
               elc.allocated_days,
               elc.used_days,
               GREATEST(elc.used_days - elc.allocated_days, 0.00) AS excess_days
        FROM employee_leave_credits elc
        JOIN leave_types lt ON elc.leave_type_id = lt.leave_type_id
        WHERE elc.employee_id    = ?
          AND elc.school_year_id = ?
          AND COALESCE(lt.is_statutory, 0) = 0
    ");
    $stmt->execute([$employeeId, $schoolYearId]);
    $rows = $stmt->fetchAll();

    $totalExcess = 0.0;
    $details     = [];
    foreach ($rows as $row) {
        $excess = (float)$row['excess_days'];
        if ($excess > 0.0) {
            $totalExcess += $excess;
            $details[] = [
                'leave_type' => $row['leave_name'],
                'allocated'  => (float)$row['allocated_days'],
                'used'       => (float)$row['used_days'],
                'excess'     => $excess,
            ];
        }
    }

    if ($totalExcess <= 0.0) return null;

    // Absence days already deducted in OTHER payroll periods for this employee.
    // Excludes current period so force-regeneration recalculates correctly.
    $prevStmt = $pdo->prepare("
        SELECT COALESCE(SUM(pd.absence_days), 0.00)
        FROM payroll_deductions pd
        JOIN payroll_records  pr ON pd.payroll_id        = pr.payroll_id
        JOIN deduction_types  dt ON pd.deduction_type_id = dt.deduction_type_id
        WHERE pr.employee_id          = ?
          AND dt.is_absence_deduction = 1
          AND pr.period_id            != ?
    ");
    $prevStmt->execute([$employeeId, $periodId]);
    $alreadyDeducted = (float)$prevStmt->fetchColumn();

    $newDays = round(max(0.0, $totalExcess - $alreadyDeducted), 2);
    if ($newDays <= 0.0) return null;

    return [
        'days'   => $newDays,
        'amount' => round($newDays * $dailyRate, 2),
        'notes'  => json_encode([
            'excess_days' => $newDays,
            'daily_rate'  => $dailyRate,
            'details'     => $details,
        ]),
    ];
}

/**
 * Compute the half-day settlement for one employee for the ACCRUED_PAY period.
 *
 * GEI policy (TOR Q19–Q22): HALF_DAY records accumulate throughout the school year
 * and are only settled during the EOSY accrued pay run — not in regular payroll.
 *
 * Deduction = half_day_count × (daily_rate ÷ 2)
 *
 * Excludes half-days already settled in a previous ACCRUED_PAY run for the same
 * school year (safe for force-regeneration).
 *
 * Returns [days, amount, notes] or null when nothing to settle.
 * Requires migration 020 (is_accrued_pay_deduction column).
 */
function computeHalfDaySettlement(
    PDO $pdo,
    int $employeeId,
    int $periodId,
    float $dailyRate
): ?array {
    // Resolve active school year
    $syRow = $pdo->query("SELECT school_year_id FROM school_years WHERE is_active = 1 LIMIT 1")->fetch();
    if (!$syRow) return null;
    $schoolYearId = (int)$syRow['school_year_id'];

    // Derive school-year date range (June 1 → May 31)
    $syYearRow = $pdo->prepare("SELECT school_year FROM school_years WHERE school_year_id = ?");
    $syYearRow->execute([$schoolYearId]);
    $syLabel = $syYearRow->fetchColumn(); // e.g. "2024-2025"
    if (!$syLabel) return null;

    [$y1, $y2] = array_map('intval', explode('-', $syLabel));
    $syStart = "{$y1}-06-01";
    $syEnd   = "{$y2}-05-31";

    // Count HALF_DAY records for this employee in the school year
    $cntStmt = $pdo->prepare("
        SELECT COUNT(*) FROM attendance_records
        WHERE employee_id    = ?
          AND attendance_date BETWEEN ? AND ?
          AND attendance_status = 'HALF_DAY'
    ");
    $cntStmt->execute([$employeeId, $syStart, $syEnd]);
    $totalHalfDays = (float)$cntStmt->fetchColumn();

    if ($totalHalfDays <= 0.0) return null;

    // Half-days already settled in another ACCRUED_PAY period for the same school year
    // (prevents double-deduction when force-regenerating)
    $prevStmt = $pdo->prepare("
        SELECT COALESCE(SUM(pd.absence_days), 0.00)
        FROM payroll_deductions pd
        JOIN payroll_records  pr ON pd.payroll_id        = pr.payroll_id
        JOIN payroll_periods  pp ON pr.period_id         = pp.period_id
        JOIN deduction_types  dt ON pd.deduction_type_id = dt.deduction_type_id
        WHERE pr.employee_id              = ?
          AND pp.period_type              = 'ACCRUED_PAY'
          AND dt.is_accrued_pay_deduction = 1
          AND dt.deduction_name           = 'Half-Day Settlement'
          AND pr.period_id               != ?
          AND pp.pay_period_start        BETWEEN ? AND ?
    ");
    $prevStmt->execute([$employeeId, $periodId, $syStart, $syEnd]);
    $alreadySettled = (float)$prevStmt->fetchColumn();

    $newHalfDays = max(0.0, $totalHalfDays - $alreadySettled);
    if ($newHalfDays <= 0.0) return null;

    return [
        'days'   => $newHalfDays,
        'amount' => round($newHalfDays * ($dailyRate / 2), 2),
        'notes'  => json_encode([
            'school_year'      => $syLabel,
            'total_half_days'  => $totalHalfDays,
            'already_settled'  => $alreadySettled,
            'new_half_days'    => $newHalfDays,
            'daily_rate'       => $dailyRate,
            'rate_applied'     => $dailyRate / 2,
        ]),
    ];
}

/**
 * Compute the excess leave settlement for one employee for the ACCRUED_PAY period.
 *
 * Threshold priority:
 *   1. SUM(employee_leave_credits.allocated_days) for this employee + school year
 *      — the authoritative per-employee entitlement set on the Leave Credits page.
 *   2. $leaveAllocationDays (global default from payroll_settings.leave_allocation_days)
 *      — fallback when the employee has no credit records (e.g. mid-year hire).
 *
 * Excess = MAX(0, total_used - threshold)
 * Deduction = excess × daily_rate
 *
 * Keeps the per-type leave credit rows intact (used_days are not modified here).
 * Returns [days, amount, notes] or null when nothing to settle.
 * Requires migration 020 (is_accrued_pay_deduction column).
 */
function computeExcessLeaveSettlement(
    PDO $pdo,
    int $employeeId,
    int $periodId,
    float $dailyRate,
    float $leaveAllocationDays
): ?array {
    // Resolve active school year
    $syRow = $pdo->query("SELECT school_year_id FROM school_years WHERE is_active = 1 LIMIT 1")->fetch();
    if (!$syRow) return null;
    $schoolYearId = (int)$syRow['school_year_id'];

    // Per-employee total allocation for non-statutory leave types (primary threshold).
    // Statutory leave (maternity, paternity, solo parent, VAWC) is excluded — those
    // entitlements are government-mandated and outside the annual leave pool.
    $allocStmt = $pdo->prepare("
        SELECT COALESCE(SUM(elc.allocated_days), 0.00)
        FROM employee_leave_credits elc
        JOIN leave_types lt ON elc.leave_type_id = lt.leave_type_id
        WHERE elc.employee_id    = ?
          AND elc.school_year_id = ?
          AND COALESCE(lt.is_statutory, 0) = 0
    ");
    $allocStmt->execute([$employeeId, $schoolYearId]);
    $employeeAllocation = (float)$allocStmt->fetchColumn();

    // Fall back to global default only when no non-statutory credit records exist
    $threshold = $employeeAllocation > 0.0 ? $employeeAllocation : $leaveAllocationDays;

    // Total non-statutory leave used this school year
    $usedStmt = $pdo->prepare("
        SELECT COALESCE(SUM(elc.used_days), 0.00)
        FROM employee_leave_credits elc
        JOIN leave_types lt ON elc.leave_type_id = lt.leave_type_id
        WHERE elc.employee_id    = ?
          AND elc.school_year_id = ?
          AND COALESCE(lt.is_statutory, 0) = 0
    ");
    $usedStmt->execute([$employeeId, $schoolYearId]);
    $totalUsed = (float)$usedStmt->fetchColumn();

    $totalExcess = max(0.0, $totalUsed - $threshold);
    if ($totalExcess <= 0.0) return null;

    // Get school year label for date range guard (reuse for prev-settlement query)
    $syYearRow = $pdo->prepare("SELECT school_year FROM school_years WHERE school_year_id = ?");
    $syYearRow->execute([$schoolYearId]);
    $syLabel = $syYearRow->fetchColumn();
    if (!$syLabel) return null;
    [$y1, $y2] = array_map('intval', explode('-', $syLabel));
    $syStart = "{$y1}-06-01";
    $syEnd   = "{$y2}-05-31";

    // Days already settled in another ACCRUED_PAY run for the same school year
    $prevStmt = $pdo->prepare("
        SELECT COALESCE(SUM(pd.absence_days), 0.00)
        FROM payroll_deductions pd
        JOIN payroll_records  pr ON pd.payroll_id        = pr.payroll_id
        JOIN payroll_periods  pp ON pr.period_id         = pp.period_id
        JOIN deduction_types  dt ON pd.deduction_type_id = dt.deduction_type_id
        WHERE pr.employee_id              = ?
          AND pp.period_type              = 'ACCRUED_PAY'
          AND dt.is_accrued_pay_deduction = 1
          AND dt.deduction_name           = 'Excess Leave Settlement'
          AND pr.period_id               != ?
          AND pp.pay_period_start        BETWEEN ? AND ?
    ");
    $prevStmt->execute([$employeeId, $periodId, $syStart, $syEnd]);
    $alreadySettled = (float)$prevStmt->fetchColumn();

    $newExcess = round(max(0.0, $totalExcess - $alreadySettled), 2);
    if ($newExcess <= 0.0) return null;

    return [
        'days'   => $newExcess,
        'amount' => round($newExcess * $dailyRate, 2),
        'notes'  => json_encode([
            'school_year_id'          => $schoolYearId,
            'total_used_days'         => $totalUsed,
            'threshold_source'        => $employeeAllocation > 0.0 ? 'employee_leave_credits' : 'global_default',
            'employee_allocation'     => $employeeAllocation,
            'global_default'          => $leaveAllocationDays,
            'threshold_applied'       => $threshold,
            'total_excess'            => $totalExcess,
            'already_settled'         => $alreadySettled,
            'new_excess_days'         => $newExcess,
            'daily_rate'              => $dailyRate,
        ]),
    ];
}