<?php
/**
 * includes/payroll-utils.php
 * Shared helper functions for payroll calculations.
 * Include this in any action file that touches payroll_records.
 */

/**
 * Recompute a payroll record's totals from its child allowance/deduction rows.
 *
 * Always call this after inserting, updating, or deleting rows in
 * payroll_allowances or payroll_deductions — never trust arithmetic done
 * in PHP on individual fields, because manual overrides and late service-credit
 * applications all modify those child rows independently.
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
            )
        WHERE pr.payroll_id = ?
    ")->execute([$payrollId]);
}