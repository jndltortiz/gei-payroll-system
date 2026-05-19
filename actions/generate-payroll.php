<?php
/**
 * actions/generate-payroll.php
 * Generates payroll records for a pay period.
 *
 * Scope options (POST: scope):
 *   all        → all active employees
 *   department → employees in department_id
 *   position   → employees with position_id
 *   specific   → employees in employee_ids[] (one or many)
 *
 * Optional: force_regenerate=1 → delete & rebuild existing records
 * Returns JSON.
 */
require_once __DIR__ . '/../config/config.php';
require_once __DIR__ . '/../includes/auth.php';
header('Content-Type: application/json');
requireLogin();

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    echo json_encode(['success' => false, 'message' => 'Invalid request method.']);
    exit;
}

$periodId        = (int)($_POST['period_id'] ?? 0);
$scope           = in_array($_POST['scope'] ?? '', ['all','department','position','specific'])
                   ? $_POST['scope'] : 'all';
$deptId          = (int)($_POST['department_id'] ?? 0);
$posId           = (int)($_POST['position_id']   ?? 0);
$empIds          = array_values(array_filter(array_map('intval', $_POST['employee_ids'] ?? [])));
$forceRegenerate = !empty($_POST['force_regenerate']);

// ── Validate period ───────────────────────────────────────────────────────────
if (!$periodId) {
    echo json_encode(['success' => false, 'message' => 'No pay period selected.']);
    exit;
}

$period = $pdo->prepare("SELECT * FROM payroll_periods WHERE period_id = ? AND status = 'OPEN'");
$period->execute([$periodId]);
$period = $period->fetch();
if (!$period) {
    echo json_encode(['success' => false, 'message' => 'Pay period not found or not open.']);
    exit;
}

// ── Validate scope sub-selection ──────────────────────────────────────────────
if ($scope === 'department' && !$deptId) {
    echo json_encode(['success' => false, 'message' => 'Please select a department.']);
    exit;
}
if ($scope === 'position' && !$posId) {
    echo json_encode(['success' => false, 'message' => 'Please select a position.']);
    exit;
}
if ($scope === 'specific' && empty($empIds)) {
    echo json_encode(['success' => false, 'message' => 'Please select at least one employee.']);
    exit;
}

// ── Build employee list ───────────────────────────────────────────────────────
$baseSQL = "
    SELECT e.employee_id, e.department_id, e.position_id, ec.monthly_salary, ec.daily_rate
    FROM employees e
    JOIN employee_compensations ec ON e.employee_id = ec.employee_id AND ec.is_active = 1
    WHERE e.employee_status = 'ACTIVE'
";

switch ($scope) {
    case 'department':
        $empStmt = $pdo->prepare($baseSQL . " AND e.department_id = ?");
        $empStmt->execute([$deptId]);
        $scopeLabel = "department #{$deptId}";
        break;

    case 'position':
        $empStmt = $pdo->prepare($baseSQL . " AND e.position_id = ?");
        $empStmt->execute([$posId]);
        $scopeLabel = "position #{$posId}";
        break;

    case 'specific':
        $ph = implode(',', array_fill(0, count($empIds), '?'));
        $empStmt = $pdo->prepare($baseSQL . " AND e.employee_id IN ($ph)");
        $empStmt->execute($empIds);
        $scopeLabel = count($empIds) . ' selected employee(s)';
        break;

    default: // all
        $empStmt = $pdo->query($baseSQL);
        $scopeLabel = 'all employees';
}

$employees = $empStmt->fetchAll();

if (empty($employees)) {
    echo json_encode(['success' => false,
        'message' => 'No active employees with compensation records found for the selected scope.']);
    exit;
}

// ── Pre-fetch deduction types (same for all employees) ────────────────────────
$dtypes = $pdo->query("SELECT * FROM deduction_types WHERE is_active = 1")->fetchAll();

// ── Generate records ──────────────────────────────────────────────────────────
try {
    $pdo->beginTransaction();
    $generated = 0;
    $skipped   = 0;
    $replaced  = 0;

    foreach ($employees as $emp) {
        $employeeId = $emp['employee_id'];
        $basic      = (float)($emp['monthly_salary'] ?? 0);

        // Check for existing record
        $dup = $pdo->prepare("
            SELECT payroll_id FROM payroll_records
            WHERE employee_id = ? AND period_id = ?
        ");
        $dup->execute([$employeeId, $periodId]);
        $existingId = $dup->fetchColumn();

        if ($existingId) {
            if ($forceRegenerate) {
                $pdo->prepare("DELETE FROM payroll_allowances WHERE payroll_id=?")->execute([$existingId]);
                $pdo->prepare("DELETE FROM payroll_deductions WHERE payroll_id=?")->execute([$existingId]);
                $pdo->prepare("DELETE FROM payroll_records WHERE payroll_id=?")->execute([$existingId]);
                $replaced++;
            } else {
                $skipped++;
                continue;
            }
        }

        // Insert base payroll record
        $pdo->prepare("
            INSERT INTO payroll_records
                (period_id, employee_id, basic_pay, gross_pay,
                 total_allowances, total_deductions, net_pay, payroll_status)
            VALUES (?, ?, ?, 0, 0, 0, 0, 'DRAFT')
        ")->execute([$periodId, $employeeId, $basic]);
        $payrollId = (int)$pdo->lastInsertId();

        // Allowances — based on assignment rules
        $atypes = $pdo->prepare("
            SELECT DISTINCT at2.allowance_type_id, at2.default_amount
            FROM allowance_types at2
            JOIN allowance_assignments aa ON at2.allowance_type_id = aa.allowance_type_id
            WHERE at2.is_active = 1 AND aa.is_active = 1
              AND (
                    aa.applies_to = 'ALL'
                 OR (aa.applies_to = 'EMPLOYEE'   AND aa.target_id = :eid)
                 OR (aa.applies_to = 'DEPARTMENT' AND aa.target_id = :dept)
                 OR (aa.applies_to = 'POSITION'   AND aa.target_id = :pos)
              )
        ");
        $atypes->execute([
            ':eid'  => $employeeId,
            ':dept' => $emp['department_id'],
            ':pos'  => $emp['position_id'],
        ]);

        $totalAllowances = 0;
        $insA = $pdo->prepare("
            INSERT INTO payroll_allowances (payroll_id, allowance_type_id, amount)
            VALUES (?,?,?)
        ");
        foreach ($atypes->fetchAll() as $type) {
            $amt = (float)$type['default_amount'];
            $insA->execute([$payrollId, $type['allowance_type_id'], $amt]);
            $totalAllowances += $amt;
        }

        // Deductions — use government bracket tables where applicable
        $totalDeductions = 0;
        $insD = $pdo->prepare("
            INSERT INTO payroll_deductions (payroll_id, deduction_type_id, amount)
            VALUES (?,?,?)
        ");

        foreach ($dtypes as $type) {
            $amt  = 0;
            $name = strtolower($type['deduction_name']);

            if ($type['is_government']) {
                if (strpos($name, 'sss') !== false && strpos($name, 'loan') === false) {
                    $row = $pdo->prepare("
                        SELECT employee_share FROM sss_contribution_table
                        WHERE min_salary <= ? AND max_salary >= ? AND is_active = 1
                        ORDER BY effective_date DESC LIMIT 1
                    ");
                    $row->execute([$basic, $basic]);
                    $r = $row->fetch();
                    $amt = $r ? (float)$r['employee_share'] : round($basic * 0.045, 2);

                } elseif (strpos($name, 'philhealth') !== false || strpos($name, 'phil') !== false) {
                    $row = $pdo->prepare("
                        SELECT employee_share FROM philhealth_contribution_table
                        WHERE min_salary <= ? AND max_salary >= ? AND is_active = 1
                        ORDER BY effective_date DESC LIMIT 1
                    ");
                    $row->execute([$basic, $basic]);
                    $r = $row->fetch();
                    $amt = $r ? (float)$r['employee_share'] : round($basic * 0.025, 2);

                } elseif (strpos($name, 'pag-ibig') !== false || strpos($name, 'hdmf') !== false) {
                    $row = $pdo->prepare("
                        SELECT employee_share FROM pagibig_contribution_table
                        WHERE min_salary <= ? AND max_salary >= ? AND is_active = 1
                        ORDER BY effective_date DESC LIMIT 1
                    ");
                    $row->execute([$basic, $basic]);
                    $r = $row->fetch();
                    $amt = $r ? (float)$r['employee_share'] : 200;
                }
                // Loans (sss loan, hdmf loan, peraa loan) start at 0

            } elseif ($type['deduction_value_type'] === 'PERCENTAGE') {
                $amt = round($basic * ($type['deduction_rate'] / 100), 2);
            } elseif ($type['deduction_value_type'] === 'FIXED') {
                $amt = (float)$type['deduction_amount'];
            }

            $insD->execute([$payrollId, $type['deduction_type_id'], $amt]);
            $totalDeductions += $amt;
        }

        // Final totals
        $gross = $basic + $totalAllowances;
        $net   = $gross - $totalDeductions;

        $pdo->prepare("
            UPDATE payroll_records
            SET gross_pay=?, total_allowances=?, total_deductions=?, net_pay=?
            WHERE payroll_id=?
        ")->execute([$gross, $totalAllowances, $totalDeductions, $net, $payrollId]);

        $generated++;
    }

    $pdo->commit();

    // Audit
    $uid = $_SESSION['user']['user_id'] ?? null;
    if ($uid) {
        $pdo->prepare("
            INSERT INTO audit_logs (user_id, action, table_name, record_id, description)
            VALUES (?,?,?,?,?)
        ")->execute([$uid, 'GENERATE', 'payroll_records', $periodId,
                     "Generated payroll for {$scopeLabel} — period #{$periodId}"]);
    }

    $parts = [];
    if ($generated) $parts[] = "{$generated} record(s) generated";
    if ($replaced)  $parts[] = "{$replaced} re-generated";
    if ($skipped)   $parts[] = "{$skipped} already existed (skipped)";

    echo json_encode([
        'success'   => true,
        'message'   => implode(', ', $parts) . ".",
        'generated' => $generated,
        'replaced'  => $replaced,
        'skipped'   => $skipped,
    ]);

} catch (PDOException $e) {
    $pdo->rollBack();
    echo json_encode(['success' => false, 'message' => 'Database error: ' . $e->getMessage()]);
}