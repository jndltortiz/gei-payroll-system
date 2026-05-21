<?php
require_once '../../config/config.php';
if (!isset($_SESSION['user'])) {
    header('Location: ' . BASE_URL . 'modules/auth/login.php');
    exit;
}

/*
 * REQUIRED SQL MIGRATIONS (run once if columns don't exist):
 *
 * ALTER TABLE deduction_types
 *   ADD COLUMN deduction_value_type ENUM('FIXED','PERCENTAGE') NOT NULL DEFAULT 'FIXED' AFTER deduction_name,
 *   ADD COLUMN deduction_amount DECIMAL(10,2) NOT NULL DEFAULT 0.00 AFTER deduction_value_type,
 *   ADD COLUMN deduction_rate DECIMAL(5,2) NOT NULL DEFAULT 0.00 AFTER deduction_amount;
 *
 * ALTER TABLE loan_types
 *   ADD COLUMN is_active TINYINT(1) NOT NULL DEFAULT 1 AFTER loan_name;
 *
 * ALTER TABLE payroll_settings
 *   ADD COLUMN working_days_per_week TINYINT NOT NULL DEFAULT 5 AFTER payroll_frequency,
 *   ADD COLUMN cutoff1_start_day TINYINT NOT NULL DEFAULT 1 AFTER working_days_per_week,
 *   ADD COLUMN cutoff1_end_day TINYINT NOT NULL DEFAULT 15 AFTER cutoff1_start_day,
 *   ADD COLUMN cutoff2_start_day TINYINT NOT NULL DEFAULT 16 AFTER cutoff1_end_day,
 *   ADD COLUMN cutoff2_end_day TINYINT NOT NULL DEFAULT 31 AFTER cutoff2_start_day;
 */

// Fetch or initialize payroll settings
$settings = $pdo->query("SELECT * FROM payroll_settings LIMIT 1")->fetch();
if (!$settings) {
    $pdo->exec("INSERT INTO payroll_settings (attendance_affects_payroll, enable_undertime_deduction, enable_overtime_pay, payroll_frequency, default_paid_leave_days, auto_apply_allowances, auto_apply_deductions, allow_manual_override, use_government_tables, government_calc_mode) VALUES (0,0,0,'SEMI_MONTHLY',30.00,1,1,1,1,'STANDARD')");
    $settings = $pdo->query("SELECT * FROM payroll_settings LIMIT 1")->fetch();
}

// Helper: get assignment label for a type
function getAssignmentLabel(PDO $pdo, string $table, string $idCol, int $id): string {
    $assignTables = [
        'allowance_types' => ['allowance_assignments', 'allowance_type_id'],
        'deduction_types' => ['deduction_assignments', 'deduction_type_id'],
        'loan_types'      => ['loan_type_assignments', 'loan_type_id'],
    ];
    if (!isset($assignTables[$table])) return 'All Employees';
    [$aTable, $aCol] = $assignTables[$table];
    $stmt = $pdo->prepare("SELECT applies_to, target_id FROM $aTable WHERE $aCol = ? ORDER BY assignment_id DESC LIMIT 1");
    $stmt->execute([$id]);
    $row = $stmt->fetch();
    if (!$row) return 'All Employees';
    switch ($row['applies_to']) {
        case 'ALL': return 'All Employees';
        case 'DEPARTMENT':
            $d = $pdo->prepare("SELECT department_name FROM departments WHERE department_id = ?");
            $d->execute([$row['target_id']]);
            $dept = $d->fetchColumn();
            return $dept ? "1 Department" : 'All Employees';
        case 'POSITION':
            $p = $pdo->prepare("SELECT position_name FROM positions WHERE position_id = ?");
            $p->execute([$row['target_id']]);
            $pos = $p->fetchColumn();
            return $pos ? "1 Position" : 'All Employees';
        case 'EMPLOYEE':
            $cnt = $pdo->prepare("SELECT COUNT(*) FROM $aTable WHERE $aCol = ? AND applies_to = 'EMPLOYEE'");
            $cnt->execute([$id]);
            $n = $cnt->fetchColumn();
            return "$n " . ($n == 1 ? 'Employee' : 'Employees');
        default: return 'All Employees';
    }
}

// Fetch allowances
$allowances = $pdo->query("SELECT * FROM allowance_types ORDER BY allowance_type_id")->fetchAll();

// Fetch other deductions (non-government, non-loan)
$deductions = $pdo->query("SELECT * FROM deduction_types WHERE is_government = 0 AND is_loan = 0 ORDER BY deduction_type_id")->fetchAll();

// Fetch loan types with live stats
$loans = $pdo->query("
    SELECT
        lt.loan_type_id,
        lt.loan_name,
        lt.is_active,
        COUNT(CASE WHEN el.status = 'ACTIVE' THEN 1 END)                      AS active_loan_count,
        COALESCE(SUM(CASE WHEN el.status = 'ACTIVE' THEN el.balance_amount    END), 0) AS total_outstanding,
        COALESCE(SUM(CASE WHEN el.status = 'ACTIVE' THEN el.monthly_deduction END), 0) AS total_monthly_deduction
    FROM loan_types lt
    LEFT JOIN employee_loans el ON lt.loan_type_id = el.loan_type_id
    GROUP BY lt.loan_type_id, lt.loan_name, lt.is_active
    ORDER BY lt.loan_type_id
")->fetchAll();

// Fetch government rate tables
$sssRates    = $pdo->query("SELECT * FROM sss_contribution_table WHERE is_active = 1 ORDER BY min_salary LIMIT 6")->fetchAll();
$sssTotal    = $pdo->query("SELECT COUNT(*) FROM sss_contribution_table WHERE is_active = 1")->fetchColumn();
$philRates   = $pdo->query("SELECT * FROM philhealth_contribution_table WHERE is_active = 1 ORDER BY min_salary")->fetchAll();
$pagibigRates= $pdo->query("SELECT * FROM pagibig_contribution_table WHERE is_active = 1 ORDER BY min_salary")->fetchAll();

// Fetch dropdown data for modals
$departments = $pdo->query("SELECT department_id, department_name FROM departments ORDER BY department_name")->fetchAll();
$positions   = $pdo->query("SELECT position_id, position_name FROM positions ORDER BY position_name")->fetchAll();
$employees   = $pdo->query("SELECT employee_id, CONCAT(first_name,' ',last_name) as full_name FROM employees WHERE employee_status='ACTIVE' ORDER BY last_name, first_name")->fetchAll();

$pageTitle     = 'Payroll Settings';
$extraCSS      = [BASE_URL . 'assets/css/payroll-settings.css'];
$loadBootstrap = true;
require_once __DIR__ . '/../../includes/head.php';
?>
<body>

<div class="layout">

    <?php include __DIR__ . '/../../includes/sidebar.php'; ?>

    <div class="main-wrapper">

        <?php include __DIR__ . '/../../includes/header.php'; ?>

        <div class="main-content">
        <div class="ps-content">

            <!-- Page Header -->
            <div class="ps-page-header">
                <div>
                    <h1 class="ps-page-title"><i class="bi bi-sliders"></i> Payroll Settings</h1>
                    <p class="ps-page-sub">Configure default payroll components applied automatically during payroll generation.</p>
                </div>
            </div>

            <!-- Info Banner -->
            <div class="ps-info-banner">
                <i class="bi bi-info-circle-fill"></i>
                <div>
                    <strong>These are default settings that apply automatically during payroll generation.</strong>
                    <span>Not all employees receive the same allowances or deductions. Use the "Assign" button to configure who receives each item. Actual values can be customized per employee during payroll editing.</span>
                </div>
            </div>

            <!-- ========== GOVERNMENT CONTRIBUTIONS ========== -->
            <div class="ps-card" id="card-gov">
                <div class="ps-card-header" data-toggle="card-gov-body">
                    <div class="ps-card-title-wrap">
                        <span class="ps-card-accent teal"></span>
                        <div>
                            <h2 class="ps-card-title">Government Contributions</h2>
                            <p class="ps-card-sub">Mandatory employee deductions — SSS, PhilHealth, Pag-IBIG.</p>
                        </div>
                    </div>
                    <i class="bi bi-chevron-up ps-collapse-icon" id="icon-card-gov-body"></i>
                </div>

                <div class="ps-card-body d-none" id="card-gov-body">
                    <div class="ps-gov-toggle-row">
                        <div>
                            <div class="ps-setting-label">Use Official Government Tables</div>
                            <div class="ps-setting-sub">
                                <?= $settings['government_calc_mode'] === 'STANDARD'
                                    ? 'Contributions auto-calculated using 2024 official rates'
                                    : 'Manual mode: Enter custom rates for each contribution' ?>
                            </div>
                        </div>
                        <button class="ps-gov-mode-btn <?= $settings['government_calc_mode'] === 'STANDARD' ? 'active' : '' ?>"
                                id="btnGovMode" data-mode="<?= $settings['government_calc_mode'] ?>">
                            <?php if ($settings['government_calc_mode'] === 'STANDARD'): ?>
                                <i class="bi bi-check-circle-fill"></i> Enabled
                            <?php else: ?>
                                <i class="bi bi-x-circle"></i> Manual Mode
                            <?php endif; ?>
                        </button>
                    </div>

                    <!-- STANDARD MODE -->
                    <div id="govStandardView" class="<?= $settings['government_calc_mode'] === 'STANDARD' ? '' : 'd-none' ?>">
                        <div class="ps-gov-info-row">
                            <div class="ps-gov-info-icon"><i class="bi bi-check-circle-fill text-teal"></i></div>
                            <div>
                                <div class="fw-semibold">Official 2024 Government Rates Active</div>
                                <div class="ps-gov-info-sub">
                                    SSS, PhilHealth, and Pag-IBIG contributions will be automatically calculated based on each employee's monthly salary using bracket-based official tables.
                                    <a href="#" class="ps-link-teal" id="btnViewRateTables">View rate tables →</a>
                                </div>
                            </div>
                        </div>
                        <table class="ps-gov-table">
                            <thead>
                                <tr><th></th><th>CONTRIBUTION</th><th>VALUE</th><th>COVERAGE</th><th>STATUS</th></tr>
                            </thead>
                            <tbody>
                                <?php foreach ([
                                    ['SSS Contribution',      'Social Security System — Employee Share'],
                                    ['PhilHealth Contribution','Philippine Health Insurance Corporation'],
                                    ['Pag-IBIG / HDMF',       'Home Development Mutual Fund (HDMF)'],
                                ] as $gov): ?>
                                <tr>
                                    <td>
                                        <label class="ps-toggle-switch ps-toggle-mandatory" title="Mandatory — cannot be disabled">
                                            <input type="checkbox" checked disabled>
                                            <span class="ps-toggle-slider"></span>
                                        </label>
                                    </td>
                                    <td>
                                        <div class="fw-semibold"><?= $gov[0] ?></div>
                                        <div class="ps-table-sub"><?= $gov[1] ?></div>
                                    </td>
                                    <td><span class="ps-auto-badge"><i class="bi bi-check-circle-fill"></i> Auto-calculated</span></td>
                                    <td><span class="ps-badge-outline">Mandatory - All Employees</span></td>
                                    <td><span class="ps-status-badge active">Enabled</span></td>
                                </tr>
                                <?php endforeach; ?>
                            </tbody>
                        </table>
                    </div>

                    <!-- MANUAL MODE -->
                    <div id="govManualView" class="<?= $settings['government_calc_mode'] === 'MANUAL' ? '' : 'd-none' ?>">
                        <div class="ps-warning-banner">
                            <i class="bi bi-exclamation-triangle-fill"></i>
                            <div>
                                <strong>Manual Override Active</strong><br>
                                <span>You are using custom rates. Make sure these comply with government regulations to avoid penalties.</span>
                            </div>
                        </div>
                        <table class="ps-gov-table">
                            <thead>
                                <tr><th></th><th>CONTRIBUTION</th><th>VALUE</th><th>COVERAGE</th><th>STATUS</th></tr>
                            </thead>
                            <tbody>
                                <?php
                                $govManual = [
                                    ['sss',  'SSS Contribution',       'Social Security System — Employee Share',  4.5,   'pct'],
                                    ['phil', 'PhilHealth Contribution', 'Philippine Health Insurance Corporation',  2.5,   'pct'],
                                    ['pag',  'Pag-IBIG / HDMF',        'Home Development Mutual Fund (HDMF)',      100.0, 'fixed'],
                                ];
                                foreach ($govManual as [$key, $name, $sub, $val, $type]): ?>
                                <tr>
                                    <td>
                                        <label class="ps-toggle-switch">
                                            <input type="checkbox" class="gov-manual-toggle" data-contrib="<?= $key ?>" checked>
                                            <span class="ps-toggle-slider"></span>
                                        </label>
                                    </td>
                                    <td>
                                        <div class="fw-semibold"><?= $name ?></div>
                                        <div class="ps-table-sub"><?= $sub ?></div>
                                    </td>
                                    <td>
                                        <div class="ps-manual-input-group">
                                            <div class="ps-type-toggle" id="<?= $key ?>TypeTgl">
                                                <button type="button" class="ps-type-btn <?= $type === 'fixed' ? 'active' : '' ?>" data-type="fixed" onclick="setGovType('<?= $key ?>','fixed')"><span>₱</span></button>
                                                <button type="button" class="ps-type-btn <?= $type === 'pct'   ? 'active' : '' ?>" data-type="pct"   onclick="setGovType('<?= $key ?>','pct')"><span>%</span></button>
                                            </div>
                                            <input type="number" class="ps-manual-input" id="<?= $key ?>Rate" name="<?= $key ?>_rate" value="<?= $val ?>" step="0.01" min="0" <?= $type === 'pct' ? 'max="100"' : '' ?>>
                                            <input type="hidden" name="<?= $key ?>_type" id="<?= $key ?>Type" value="<?= $type ?>">
                                        </div>
                                    </td>
                                    <td><span class="ps-badge-outline">Mandatory - All Employees</span></td>
                                    <td><span class="ps-status-badge active" id="<?= $key ?>ManualStatus">Enabled</span></td>
                                </tr>
                                <?php endforeach; ?>
                            </tbody>
                        </table>
                    </div>
                </div>
            </div>

            <!-- ========== LOAN DEDUCTIONS ========== -->
            <div class="ps-card" id="card-loans">
                <div class="ps-card-header" data-toggle="card-loans-body">
                    <div class="ps-card-title-wrap">
                        <span class="ps-card-accent orange"></span>
                        <div>
                            <h2 class="ps-card-title">Loan Deductions</h2>
                            <p class="ps-card-sub">Control which loan types are auto-deducted during payroll. Amounts are set per employee in Loans Management.</p>
                        </div>
                    </div>
                    <i class="bi bi-chevron-down ps-collapse-icon" id="icon-card-loans-body"></i>
                </div>
                <div class="ps-card-body d-none" id="card-loans-body">
                    <div class="ps-loan-info-banner">
                        <i class="bi bi-info-circle-fill"></i>
                        <div>
                            <strong>Amounts are managed individually.</strong>
                            Each employee's monthly deduction is configured in
                            <a href="<?= BASE_URL ?>modules/loans/index.php" class="ps-link-teal">Loans Management →</a>
                            Toggling a loan type here controls whether it is <em>automatically included</em> during payroll generation.
                        </div>
                    </div>
                    <?php if (empty($loans)): ?>
                    <div class="ps-empty-state"><i class="bi bi-journal-x"></i><span>No loan types configured.</span></div>
                    <?php else: ?>
                    <table class="ps-data-table">
                        <thead>
                            <tr>
                                <th class="ps-th-toggle">AUTO-DEDUCT</th>
                                <th>LOAN TYPE</th>
                                <th>ACTIVE LOANS</th>
                                <th>TOTAL MONTHLY DEDUCTION</th>
                                <th>ELIGIBLE FOR</th>
                                <th>ACTIONS</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($loans as $loan):
                                $autoDeduct   = (bool)($loan['is_active'] ?? 1);
                                $activeCount  = (int)$loan['active_loan_count'];
                                $totalMonthly = (float)$loan['total_monthly_deduction'];
                                $loanAssign   = getAssignmentLabel($pdo, 'loan_types', 'loan_type_id', $loan['loan_type_id']);
                                $assignStyle  = str_contains($loanAssign, 'All') ? 'all' : 'specific';
                            ?>
                            <tr data-loan-id="<?= $loan['loan_type_id'] ?>" class="<?= !$autoDeduct ? 'ps-row-muted' : '' ?>">
                                <td class="ps-td-toggle">
                                    <label class="ps-toggle-switch">
                                        <input type="checkbox" class="loan-toggle" data-id="<?= $loan['loan_type_id'] ?>" <?= $autoDeduct ? 'checked' : '' ?>>
                                        <span class="ps-toggle-slider"></span>
                                    </label>
                                </td>
                                <td>
                                    <div class="fw-semibold"><?= htmlspecialchars($loan['loan_name']) ?></div>
                                    <div class="ps-table-sub loan-status-hint">
                                        <?= $autoDeduct
                                            ? '<i class="bi bi-check-circle-fill text-teal"></i> Auto-deducted during payroll'
                                            : '<i class="bi bi-dash-circle text-muted"></i> Not included in payroll' ?>
                                    </div>
                                </td>
                                <td>
                                    <?php if ($activeCount > 0): ?>
                                        <a href="<?= BASE_URL ?>modules/loans/index.php?type=<?= $loan['loan_type_id'] ?>" class="ps-loan-count-badge">
                                            <i class="bi bi-people-fill"></i> <?= $activeCount ?> <?= $activeCount === 1 ? 'Employee' : 'Employees' ?>
                                        </a>
                                    <?php else: ?>
                                        <span class="ps-loan-count-empty"><i class="bi bi-dash"></i> No active loans</span>
                                    <?php endif; ?>
                                </td>
                                <td>
                                    <?php if ($activeCount > 0): ?>
                                        <span class="ps-loan-total">₱ <?= number_format($totalMonthly, 2) ?> <span class="ps-loan-total-sub">/ cutoff</span></span>
                                    <?php else: ?>
                                        <span class="text-muted">—</span>
                                    <?php endif; ?>
                                </td>
                                <td><span class="ps-badge-<?= $assignStyle ?>"><?= htmlspecialchars($loanAssign) ?></span></td>
                                <td></td>
                            </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                    <?php endif; ?>
                    <div class="ps-loan-footer">
                        To create new loan types, approve applications, or adjust individual repayment amounts, go to
                        <a href="<?= BASE_URL ?>modules/loans/index.php" class="ps-link-teal">Loans Management</a>.
                    </div>
                </div>
            </div>

            <!-- ========== OTHER DEDUCTIONS ========== -->
            <div class="ps-card" id="card-deductions">
                <div class="ps-card-header" data-toggle="card-deductions-body">
                    <div class="ps-card-title-wrap">
                        <span class="ps-card-accent orange"></span>
                        <div>
                            <h2 class="ps-card-title">Other Deductions</h2>
                            <p class="ps-card-sub">PERAA premiums and custom deductions applied during payroll.</p>
                        </div>
                    </div>
                    <div class="d-flex align-items-center gap-2">
                        <button class="ps-add-btn" onclick="openAddDeductionModal()"><i class="bi bi-plus"></i> Add Deduction</button>
                        <i class="bi bi-chevron-up ps-collapse-icon" id="icon-card-deductions-body"></i>
                    </div>
                </div>
                <div class="ps-card-body d-none" id="card-deductions-body">
                    <table class="ps-data-table">
                        <thead>
                            <tr><th>DEDUCTION NAME</th><th>AMOUNT / RATE</th><th>TYPE</th><th>APPLIES TO</th><th>STATUS</th><th>ACTIONS</th></tr>
                        </thead>
                        <tbody id="deductionTableBody">
                            <?php foreach ($deductions as $ded):
                                $dedAssign   = getAssignmentLabel($pdo, 'deduction_types', 'deduction_type_id', $ded['deduction_type_id']);
                                $assignStyle = str_contains($dedAssign, 'All') ? 'all' : 'specific';
                                $isFixed     = ($ded['deduction_value_type'] ?? 'FIXED') === 'FIXED';
                                $displayVal  = $isFixed
                                    ? '₱ ' . number_format((float)($ded['deduction_amount'] ?? 0), 2)
                                    : number_format((float)($ded['deduction_rate'] ?? 0), 2) . '%';
                            ?>
                            <tr data-ded-id="<?= $ded['deduction_type_id'] ?>">
                                <td class="fw-semibold"><?= htmlspecialchars($ded['deduction_name']) ?></td>
                                <td><?= $displayVal ?></td>
                                <td>
                                    <?php if ($isFixed): ?>
                                        <span class="ps-type-badge fixed"><i class="bi bi-currency-dollar"></i> Fixed</span>
                                    <?php else: ?>
                                        <span class="ps-type-badge pct"><i class="bi bi-percent"></i> Percentage</span>
                                    <?php endif; ?>
                                </td>
                                <td><span class="ps-badge-<?= $assignStyle ?>"><?= htmlspecialchars($dedAssign) ?></span></td>
                                <td><span class="ps-status-badge <?= $ded['is_active'] ? 'active' : 'inactive' ?>"><?= $ded['is_active'] ? 'Active' : 'Inactive' ?></span></td>
                                <td>
                                    <div class="ps-action-group">
                                        <button class="ps-action-btn" title="Assign"
                                            onclick="openAssignModal('deduction','<?= $ded['deduction_type_id'] ?>','<?= htmlspecialchars($ded['deduction_name']) ?>')">
                                            <i class="bi bi-person-plus"></i>
                                        </button>
                                        <button class="ps-action-btn" title="Edit"
                                            onclick="openEditDeductionModal(<?= $ded['deduction_type_id'] ?>, '<?= htmlspecialchars(addslashes($ded['deduction_name'])) ?>', '<?= $ded['deduction_value_type'] ?? 'FIXED' ?>', <?= $ded['deduction_amount'] ?? 0 ?>, <?= $ded['deduction_rate'] ?? 0 ?>, '<?= $dedAssign === 'All Employees' ? 'ALL' : strtoupper(str_replace(' ','_',$dedAssign)) ?>', <?= $ded['is_active'] ?>)">
                                            <i class="bi bi-pencil"></i>
                                        </button>
                                        <button class="ps-action-btn danger" title="Delete"
                                            onclick="openDeleteModal('deduction', <?= $ded['deduction_type_id'] ?>, '<?= htmlspecialchars(addslashes($ded['deduction_name'])) ?>')">
                                            <i class="bi bi-trash"></i>
                                        </button>
                                    </div>
                                </td>
                            </tr>
                            <?php endforeach; ?>
                            <?php if (empty($deductions)): ?>
                            <tr id="noDeductionsRow">
                                <td colspan="6" class="text-center text-muted py-3">No custom deductions added yet. Click "+ Add Deduction" to get started.</td>
                            </tr>
                            <?php endif; ?>
                        </tbody>
                    </table>
                </div>
            </div>

            <!-- ========== ALLOWANCES ========== -->
            <div class="ps-card" id="card-allowances">
                <div class="ps-card-header" data-toggle="card-allowances-body">
                    <div class="ps-card-title-wrap">
                        <span class="ps-card-accent blue"></span>
                        <div>
                            <h2 class="ps-card-title">Allowances</h2>
                            <p class="ps-card-sub">Default allowance types included in payroll computation.</p>
                        </div>
                    </div>
                    <div class="d-flex align-items-center gap-2">
                        <button class="ps-add-btn" onclick="openAddAllowanceModal()"><i class="bi bi-plus"></i> Add Allowance</button>
                        <i class="bi bi-chevron-up ps-collapse-icon" id="icon-card-allowances-body"></i>
                    </div>
                </div>
                <div class="ps-card-body d-none" id="card-allowances-body">
                    <table class="ps-data-table">
                        <thead>
                            <tr><th>ALLOWANCE NAME</th><th>DEFAULT AMOUNT</th><th>APPLIES TO</th><th>STATUS</th><th>ACTIONS</th></tr>
                        </thead>
                        <tbody id="allowanceTableBody">
                            <?php foreach ($allowances as $al):
                                $alAssign    = getAssignmentLabel($pdo, 'allowance_types', 'allowance_type_id', $al['allowance_type_id']);
                                $assignStyle = str_contains($alAssign, 'All') ? 'all' : 'specific';
                            ?>
                            <tr data-al-id="<?= $al['allowance_type_id'] ?>">
                                <td class="fw-semibold"><?= htmlspecialchars($al['allowance_name']) ?></td>
                                <td>₱ <?= number_format((float)$al['default_amount'], 2) ?></td>
                                <td><span class="ps-badge-<?= $assignStyle ?>"><?= htmlspecialchars($alAssign) ?></span></td>
                                <td><span class="ps-status-badge <?= $al['is_active'] ? 'active' : 'inactive' ?>"><?= $al['is_active'] ? 'Active' : 'Inactive' ?></span></td>
                                <td>
                                    <div class="ps-action-group">
                                        <button class="ps-action-btn" title="Assign"
                                            onclick="openAssignModal('allowance','<?= $al['allowance_type_id'] ?>','<?= htmlspecialchars($al['allowance_name']) ?>')">
                                            <i class="bi bi-person-plus"></i>
                                        </button>
                                        <button class="ps-action-btn" title="Edit"
                                            onclick="openEditAllowanceModal(<?= $al['allowance_type_id'] ?>, '<?= htmlspecialchars(addslashes($al['allowance_name'])) ?>', <?= $al['default_amount'] ?>, <?= $al['is_taxable'] ?>, <?= $al['is_active'] ?>)">
                                            <i class="bi bi-pencil"></i>
                                        </button>
                                        <button class="ps-action-btn danger" title="Delete"
                                            onclick="openDeleteModal('allowance', <?= $al['allowance_type_id'] ?>, '<?= htmlspecialchars(addslashes($al['allowance_name'])) ?>')">
                                            <i class="bi bi-trash"></i>
                                        </button>
                                    </div>
                                </td>
                            </tr>
                            <?php endforeach; ?>
                            <?php if (empty($allowances)): ?>
                            <tr id="noAllowancesRow">
                                <td colspan="5" class="text-center text-muted py-3">No allowances added yet. Click "+ Add Allowance" to get started.</td>
                            </tr>
                            <?php endif; ?>
                        </tbody>
                    </table>
                </div>
            </div>

            <!-- ========== PAYROLL RULES + CUTOFF (2-col) ========== -->
            <div class="row g-3 align-items-start mb-3">
                <div class="col-lg-6">
                    <div class="ps-card" id="card-rules">
                        <div class="ps-card-header" data-toggle="card-rules-body">
                            <div class="ps-card-title-wrap">
                                <span class="ps-card-accent teal"></span>
                                <div>
                                    <h2 class="ps-card-title">Payroll Rules</h2>
                                    <p class="ps-card-sub">Frequency and working day configuration.</p>
                                </div>
                            </div>
                            <i class="bi bi-chevron-up ps-collapse-icon" id="icon-card-rules-body"></i>
                        </div>
                        <div class="ps-card-body d-none" id="card-rules-body">
                            <div class="ps-form-group">
                                <label class="ps-form-label">Payroll Frequency</label>
                                <select class="ps-form-select" name="payroll_frequency" id="payrollFrequency">
                                    <option value="SEMI_MONTHLY" <?= ($settings['payroll_frequency'] ?? '') === 'SEMI_MONTHLY' ? 'selected' : '' ?>>Semi-monthly (twice a month)</option>
                                    <option value="MONTHLY"      <?= ($settings['payroll_frequency'] ?? '') === 'MONTHLY'      ? 'selected' : '' ?>>Monthly (once a month)</option>
                                </select>
                            </div>
                            <div class="ps-form-group mt-3">
                                <label class="ps-form-label">Working Days per Week</label>
                                <select class="ps-form-select" name="working_days_per_week" id="workingDays">
                                    <option value="5" <?= ($settings['working_days_per_week'] ?? 5) == 5 ? 'selected' : '' ?>>5 days (Mon–Fri)</option>
                                    <option value="6" <?= ($settings['working_days_per_week'] ?? 5) == 6 ? 'selected' : '' ?>>6 days (Mon–Sat)</option>
                                    <option value="7" <?= ($settings['working_days_per_week'] ?? 5) == 7 ? 'selected' : '' ?>>7 days</option>
                                </select>
                            </div>
                            <div class="ps-info-note mt-3">
                                <i class="bi bi-info-circle"></i>
                                Working days affect pro-rated salary and per-day computations. Ensure this matches your institution's schedule.
                            </div>
                        </div>
                    </div>
                </div>

                <div class="col-lg-6">
                    <div class="ps-card" id="card-cutoff">
                        <div class="ps-card-header" data-toggle="card-cutoff-body">
                            <div class="ps-card-title-wrap">
                                <span class="ps-card-accent teal"></span>
                                <div>
                                    <h2 class="ps-card-title">Payroll Cutoff Configuration</h2>
                                    <p class="ps-card-sub">Define start and end days for each payroll period.</p>
                                </div>
                            </div>
                            <i class="bi bi-chevron-up ps-collapse-icon" id="icon-card-cutoff-body"></i>
                        </div>
                        <div class="ps-card-body d-none" id="card-cutoff-body">
                            <div class="ps-cutoff-block">
                                <div class="ps-cutoff-label"><span class="ps-cutoff-num">1</span> 1st Cutoff Period</div>
                                <div class="row g-2 mt-1">
                                    <div class="col-6">
                                        <label class="ps-form-label">Start Day</label>
                                        <select class="ps-form-select" name="cutoff1_start_day" id="cutoff1Start">
                                            <?php for ($d=1;$d<=31;$d++): ?>
                                            <option value="<?=$d?>" <?= ($settings['cutoff1_start_day'] ?? 1) == $d ? 'selected':'' ?>>Day <?=$d?></option>
                                            <?php endfor; ?>
                                        </select>
                                    </div>
                                    <div class="col-6">
                                        <label class="ps-form-label">End Day</label>
                                        <select class="ps-form-select" name="cutoff1_end_day" id="cutoff1End">
                                            <?php for ($d=1;$d<=31;$d++): ?>
                                            <option value="<?=$d?>" <?= ($settings['cutoff1_end_day'] ?? 15) == $d ? 'selected':'' ?>>Day <?=$d?></option>
                                            <?php endfor; ?>
                                        </select>
                                    </div>
                                </div>
                                <div class="ps-cutoff-desc" id="cutoff1Desc">Day <?= $settings['cutoff1_start_day'] ?? 1 ?> to Day <?= $settings['cutoff1_end_day'] ?? 15 ?> of each month</div>
                            </div>
                            <div class="ps-cutoff-block mt-3" id="cutoff2Block">
                                <div class="ps-cutoff-label"><span class="ps-cutoff-num">2</span> 2nd Cutoff Period</div>
                                <div class="row g-2 mt-1">
                                    <div class="col-6">
                                        <label class="ps-form-label">Start Day</label>
                                        <select class="ps-form-select" name="cutoff2_start_day" id="cutoff2Start">
                                            <?php for ($d=1;$d<=31;$d++): ?>
                                            <option value="<?=$d?>" <?= ($settings['cutoff2_start_day'] ?? 16) == $d ? 'selected':'' ?>>Day <?=$d?></option>
                                            <?php endfor; ?>
                                        </select>
                                    </div>
                                    <div class="col-6">
                                        <label class="ps-form-label">End Day</label>
                                        <select class="ps-form-select" name="cutoff2_end_day" id="cutoff2End">
                                            <?php for ($d=1;$d<=31;$d++): ?>
                                            <option value="<?=$d?>" <?= ($settings['cutoff2_end_day'] ?? 31) == $d ? 'selected':'' ?>>Day <?=$d?></option>
                                            <?php endfor; ?>
                                        </select>
                                    </div>
                                </div>
                                <div class="ps-cutoff-desc" id="cutoff2Desc">Day <?= $settings['cutoff2_start_day'] ?? 16 ?> to Day <?= $settings['cutoff2_end_day'] ?? 31 ?> of each month</div>
                            </div>
                        </div>
                    </div>
                </div>
            </div>

            <!-- ========== AUTOMATION SETTINGS ========== -->
            <div class="ps-card" id="card-auto">
                <div class="ps-card-header" data-toggle="card-auto-body">
                    <div class="ps-card-title-wrap">
                        <span class="ps-card-accent teal"></span>
                        <div>
                            <h2 class="ps-card-title">Automation Settings</h2>
                            <p class="ps-card-sub">Control how payroll components are automatically applied during payroll generation.</p>
                        </div>
                    </div>
                    <i class="bi bi-chevron-up ps-collapse-icon" id="icon-card-auto-body"></i>
                </div>
                <div class="ps-card-body d-none" id="card-auto-body">
                    <div class="ps-toggle-row">
                        <div>
                            <div class="ps-setting-label">Auto-apply Allowances</div>
                            <div class="ps-setting-sub">Automatically include all active allowances when generating payroll.</div>
                        </div>
                        <label class="ps-toggle-switch">
                            <input type="checkbox" id="autoAllowances" <?= ($settings['auto_apply_allowances'] ?? 1) ? 'checked' : '' ?>>
                            <span class="ps-toggle-slider"></span>
                        </label>
                    </div>
                    <div class="ps-toggle-row">
                        <div>
                            <div class="ps-setting-label">Auto-apply Deductions</div>
                            <div class="ps-setting-sub">Automatically apply all active deductions and loan repayments.</div>
                        </div>
                        <label class="ps-toggle-switch">
                            <input type="checkbox" id="autoDeductions" <?= ($settings['auto_apply_deductions'] ?? 1) ? 'checked' : '' ?>>
                            <span class="ps-toggle-slider"></span>
                        </label>
                    </div>
                    <div class="ps-toggle-row" style="border-bottom:none;">
                        <div>
                            <div class="ps-setting-label">Allow Manual Override During Payroll Editing</div>
                            <div class="ps-setting-sub">Permit payroll editors to modify auto-applied values before finalizing.</div>
                        </div>
                        <label class="ps-toggle-switch">
                            <input type="checkbox" id="allowOverride" <?= ($settings['allow_manual_override'] ?? 1) ? 'checked' : '' ?>>
                            <span class="ps-toggle-slider"></span>
                        </label>
                    </div>
                </div>
            </div>


            <!-- ========== PAY PERIOD MANAGER ========== -->
            <div class="ps-card" id="card-periods">
                <div class="ps-card-header" data-toggle="card-periods-body">
                    <div class="ps-card-title-wrap">
                        <span class="ps-card-accent teal"></span>
                        <div>
                            <h2 class="ps-card-title">Pay Period Management</h2>
                            <p class="ps-card-sub">Create and manage payroll periods based on your cutoff configuration.</p>
                        </div>
                    </div>
                    <i class="bi bi-chevron-up ps-collapse-icon" id="icon-card-periods-body"></i>
                </div>
                <div class="ps-card-body d-none" id="card-periods-body">

                    <?php
                    $existingPeriods = $pdo->query("
                        SELECT * FROM payroll_periods
                        ORDER BY pay_period_start DESC
                        LIMIT 24
                    ")->fetchAll();
                    $statusColors = [
                        'OPEN'       => ['bg'=>'#d1fae5','color'=>'#065f46'],
                        'PROCESSING' => ['bg'=>'#fef3c7','color'=>'#92400e'],
                        'APPROVED'   => ['bg'=>'#dbeafe','color'=>'#1e40af'],
                        'RELEASED'   => ['bg'=>'#f3f4f6','color'=>'#374151'],
                    ];
                    ?>

                    <div class="pp-toolbar">
                        <div class="pp-summary">
                            <?php
                            $openCount = count(array_filter($existingPeriods, fn($p) => $p['status']==='OPEN'));
                            ?>
                            <span class="pp-summary-item">
                                <strong><?= count($existingPeriods) ?></strong> total periods
                            </span>
                            <span class="pp-summary-item pp-summary-item--open">
                                <strong><?= $openCount ?></strong> open
                            </span>
                        </div>
                        <button type="button" class="ps-btn-primary" onclick="openCreatePeriodsModal()">
                            <i class="bi bi-plus-lg"></i> Create Pay Periods
                        </button>
                    </div>

                    <?php if (empty($existingPeriods)): ?>
                    <div class="pp-empty">
                        <i class="bi bi-calendar-x" style="font-size:32px;color:#cbd5e1;"></i>
                        <p>No pay periods yet. Click <strong>Create Pay Periods</strong> to get started.</p>
                        <small>Periods are created based on your Payroll Cutoff Configuration above.</small>
                    </div>
                    <?php else: ?>
                    <table class="ps-data-table pp-table">
                        <thead>
                            <tr>
                                <th>Period Name</th>
                                <th>Start Date</th>
                                <th>End Date</th>
                                <th>Pay Date</th>
                                <th>Status</th>
                                <th>Actions</th>
                            </tr>
                        </thead>
                        <tbody>
                        <?php foreach ($existingPeriods as $p):
                            $sc = $statusColors[$p['status']] ?? ['bg'=>'#f3f4f6','color'=>'#374151'];
                        ?>
                        <tr>
                            <td><strong><?= htmlspecialchars($p['period_name']) ?></strong></td>
                            <td><?= date('M d, Y', strtotime($p['pay_period_start'])) ?></td>
                            <td><?= date('M d, Y', strtotime($p['pay_period_end'])) ?></td>
                            <td><?= date('M d, Y', strtotime($p['pay_date'])) ?></td>
                            <td>
                                <span style="background:<?= $sc['bg'] ?>;color:<?= $sc['color'] ?>;
                                      padding:3px 10px;border-radius:999px;font-size:11px;font-weight:600;">
                                    <?= ucfirst(strtolower($p['status'])) ?>
                                </span>
                            </td>
                            <td>
                                <?php if ($p['status'] === 'OPEN'): ?>
                                <button type="button" class="ps-action-btn ps-action-btn--danger"
                                        onclick="deletePeriod(<?= $p['period_id'] ?>, '<?= htmlspecialchars($p['period_name']) ?>')"
                                        title="Delete period">
                                    <i class="bi bi-trash"></i>
                                </button>
                                <?php else: ?>
                                <span style="font-size:12px;color:#94a3b8;">Locked</span>
                                <?php endif; ?>
                            </td>
                        </tr>
                        <?php endforeach; ?>
                        </tbody>
                    </table>
                    <?php endif; ?>

                    <div id="ppFlash" style="display:none;margin-top:12px;"></div>

                </div>
            </div>

            <!-- Spacer for floating save bar -->
            <div style="height:100px;"></div>

        </div><!-- .ps-content -->
        </div><!-- .main-content -->

        <!-- ========== FLOATING SAVE BAR ========== -->
        <div class="ps-save-bar" id="savebar">
            <div class="ps-save-bar-inner">
                <span class="ps-save-bar-text" id="saveBarText">
                    <strong>Ready to save?</strong> Changes will apply starting the next payroll generation.
                </span>
                <button class="ps-save-btn" id="btnSavePayroll" onclick="openSaveConfirmModal()">
                    <i class="bi bi-floppy"></i> Save Payroll Settings
                </button>
            </div>
        </div>

    </div><!-- .main-wrapper -->
</div><!-- .layout -->

<!-- ========== MODALS ========== -->
<?php include __DIR__ . '/modals/modal-pay-periods.php'; ?>
<?php include 'modals/modal-add-deduction.php'; ?>
<?php include 'modals/modal-edit-deduction.php'; ?>
<?php include 'modals/modal-add-allowance.php'; ?>
<?php include 'modals/modal-edit-allowance.php'; ?>
<?php include 'modals/modal-assign.php'; ?>
<?php include 'modals/modal-delete.php'; ?>
<?php include 'modals/modal-save-confirm.php'; ?>
<?php include 'modals/modal-rate-tables.php'; ?>

<script>
window.PAYROLL_SETTINGS = <?= json_encode([
    'setting_id'                 => $settings['setting_id'],
    'payroll_frequency'          => $settings['payroll_frequency'],
    'working_days_per_week'      => $settings['working_days_per_week'] ?? 5,
    'cutoff1_start_day'          => $settings['cutoff1_start_day'] ?? 1,
    'cutoff1_end_day'            => $settings['cutoff1_end_day'] ?? 15,
    'cutoff2_start_day'          => $settings['cutoff2_start_day'] ?? 16,
    'cutoff2_end_day'            => $settings['cutoff2_end_day'] ?? 31,
    'auto_apply_allowances'      => $settings['auto_apply_allowances'],
    'auto_apply_deductions'      => $settings['auto_apply_deductions'],
    'allow_manual_override'      => $settings['allow_manual_override'],
    'use_government_tables'      => $settings['use_government_tables'],
    'government_calc_mode'       => $settings['government_calc_mode'],
    'attendance_affects_payroll' => $settings['attendance_affects_payroll'],
    'enable_overtime_pay'        => $settings['enable_overtime_pay'],
]) ?>;

window.DEPARTMENTS   = <?= json_encode($departments) ?>;
window.POSITIONS     = <?= json_encode($positions) ?>;
window.EMPLOYEES     = <?= json_encode($employees) ?>;
window.SSS_RATES     = <?= json_encode($sssRates) ?>;
window.SSS_TOTAL     = <?= (int)$sssTotal ?>;
window.PHIL_RATES    = <?= json_encode($philRates) ?>;
window.PAGIBIG_RATES = <?= json_encode($pagibigRates) ?>;
</script>

<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js"></script>
<script src="<?= BASE_URL ?>assets/js/payroll-settings.js"></script>


<?php include __DIR__ . '/../../includes/footer.php'; ?>
