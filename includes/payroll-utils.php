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