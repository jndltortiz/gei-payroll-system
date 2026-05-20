<?php
require_once __DIR__ . '/../../includes/auth.php';
require_once __DIR__ . '/../../config/database.php';
requireLogin();

// ── Pay periods ──────────────────────────────────────────────────────────────
$allPeriods = $pdo->query("SELECT * FROM payroll_periods ORDER BY pay_period_start DESC")->fetchAll();

$selectedId = (int)($_GET['period_id'] ?? 0);
if (!$selectedId) {
    foreach ($allPeriods as $p) {
        if ($p['status'] === 'OPEN') { $selectedId = $p['period_id']; break; }
    }
    if (!$selectedId && !empty($allPeriods)) $selectedId = $allPeriods[0]['period_id'];
}

$selectedPeriod = null;
foreach ($allPeriods as $p) {
    if ($p['period_id'] == $selectedId) { $selectedPeriod = $p; break; }
}
$periodIsEditable = $selectedPeriod && $selectedPeriod['status'] === 'OPEN';

// ── Filters ──────────────────────────────────────────────────────────────────
$search      = trim($_GET['search']   ?? '');
$deptFilter  = (int)($_GET['dept_id'] ?? 0);
$validStatus = ['DRAFT','APPROVED','RELEASED'];
$statFilter  = in_array($_GET['status'] ?? '', $validStatus) ? $_GET['status'] : '';

// ── Filter data ──────────────────────────────────────────────────────────────
$departments = $pdo->query("SELECT department_id, department_name FROM departments ORDER BY department_name")->fetchAll();

// ── Payroll records with filters ─────────────────────────────────────────────
$records = [];
$totalGross = $totalDed = $totalNet = 0;

if ($selectedId) {
    $where  = "WHERE pr.period_id = :pid";
    $params = [':pid' => $selectedId];

    if ($search !== '') {
        $where .= " AND (e.first_name LIKE :s OR e.last_name LIKE :s
                    OR CONCAT(e.first_name,' ',e.last_name) LIKE :s)";
        $params[':s'] = "%$search%";
    }
    if ($deptFilter) {
        $where .= " AND e.department_id = :dept";
        $params[':dept'] = $deptFilter;
    }
    if ($statFilter) {
        $where .= " AND pr.payroll_status = :pstat";
        $params[':pstat'] = $statFilter;
    }

    $stmt = $pdo->prepare("
        SELECT pr.payroll_id, pr.employee_id, pr.basic_pay, pr.gross_pay,
               pr.total_allowances, pr.total_deductions, pr.net_pay, pr.payroll_status,
               CONCAT(e.first_name,' ',e.last_name) AS employee_name,
               p.position_name, d.department_name,

               (SELECT COALESCE(SUM(pa.amount),0) FROM payroll_allowances pa
                JOIN allowance_types at2 ON pa.allowance_type_id=at2.allowance_type_id
                WHERE pa.payroll_id=pr.payroll_id
                  AND at2.allowance_name LIKE '%Additional Assignment%') AS addl_assign,

               (SELECT COALESCE(SUM(pa.amount),0) FROM payroll_allowances pa
                JOIN allowance_types at2 ON pa.allowance_type_id=at2.allowance_type_id
                WHERE pa.payroll_id=pr.payroll_id
                  AND at2.allowance_name LIKE '%Rice%') AS rice_subsidy,

               (SELECT COALESCE(SUM(pa.amount),0) FROM payroll_allowances pa
                JOIN allowance_types at2 ON pa.allowance_type_id=at2.allowance_type_id
                WHERE pa.payroll_id=pr.payroll_id
                  AND at2.allowance_name LIKE '%Laundry%') AS laundry,

               (SELECT COALESCE(SUM(pd.amount),0) FROM payroll_deductions pd
                JOIN deduction_types dt ON pd.deduction_type_id=dt.deduction_type_id
                WHERE pd.payroll_id=pr.payroll_id AND dt.deduction_name LIKE '%PERAA%Premium%') AS peraa_premium,
               (SELECT COALESCE(SUM(pd.amount),0) FROM payroll_deductions pd
                JOIN deduction_types dt ON pd.deduction_type_id=dt.deduction_type_id
                WHERE pd.payroll_id=pr.payroll_id AND dt.deduction_name LIKE '%PERAA%Loan%') AS peraa_loan,
               (SELECT COALESCE(SUM(pd.amount),0) FROM payroll_deductions pd
                JOIN deduction_types dt ON pd.deduction_type_id=dt.deduction_type_id
                WHERE pd.payroll_id=pr.payroll_id AND dt.deduction_name LIKE '%HDMF%Premium%') AS hdmf_premium,
               (SELECT COALESCE(SUM(pd.amount),0) FROM payroll_deductions pd
                JOIN deduction_types dt ON pd.deduction_type_id=dt.deduction_type_id
                WHERE pd.payroll_id=pr.payroll_id AND dt.deduction_name LIKE '%HDMF%Loan%') AS hdmf_loan,
               (SELECT COALESCE(SUM(pd.amount),0) FROM payroll_deductions pd
                JOIN deduction_types dt ON pd.deduction_type_id=dt.deduction_type_id
                WHERE pd.payroll_id=pr.payroll_id
                  AND (dt.deduction_name LIKE '%Phil%' OR dt.deduction_name LIKE '%PhilHealth%')) AS philhealth,
               (SELECT COALESCE(SUM(pd.amount),0) FROM payroll_deductions pd
                JOIN deduction_types dt ON pd.deduction_type_id=dt.deduction_type_id
                WHERE pd.payroll_id=pr.payroll_id AND dt.deduction_name LIKE '%SSS%Premium%') AS sss_premium,
               (SELECT COALESCE(SUM(pd.amount),0) FROM payroll_deductions pd
                JOIN deduction_types dt ON pd.deduction_type_id=dt.deduction_type_id
                WHERE pd.payroll_id=pr.payroll_id AND dt.deduction_name LIKE '%SSS%Loan%') AS sss_loan

        FROM payroll_records pr
        JOIN employees e ON pr.employee_id = e.employee_id
        LEFT JOIN positions p ON e.position_id = p.position_id
        LEFT JOIN departments d ON e.department_id = d.department_id
        $where
        ORDER BY e.last_name, e.first_name
    ");
    $stmt->execute($params);
    $records = $stmt->fetchAll();

    foreach ($records as $r) {
        $totalGross += $r['gross_pay'];
        $totalDed   += $r['total_deductions'];
        $totalNet   += $r['net_pay'];
    }
}

// FIX: define $recordCount properly so it's available in PHP conditionals
$recordCount = count($records);

$pageTitle = 'Payroll Management';
$extraCSS  = [BASE_URL . 'assets/css/payroll.css'];
require_once __DIR__ . '/../../includes/head.php';
?>
<body>
<div class="layout">
<?php include __DIR__ . '/../../includes/sidebar.php'; ?>
<div class="main">
<?php include __DIR__ . '/../../includes/header.php'; ?>
<div class="main-content">
<div class="payroll-container">

    <!-- PAGE HEADER -->
    <div class="page-header">
        <div>
            <h2>Payroll Management</h2>
            <small>Generate payroll, edit allowances and deductions per period, then submit for approval.</small>
        </div>
        <div class="actions">
            <button class="btn-outline" id="btnExport" onclick="window.print()">
                <i class="fa fa-file-export"></i> Export
            </button>
            <?php if ($selectedId): ?>
            <a href="<?= BASE_URL ?>modules/payroll/batch-detail.php?period_id=<?= $selectedId ?>"
               class="btn-outline" style="text-decoration:none;display:inline-flex;align-items:center;gap:6px;">
                <i class="fa fa-layer-group"></i> Batch Details
            </a>
            <?php endif; ?>
            <?php if ($periodIsEditable): ?>
            <button class="btn-primary" onclick="openGenerateModal()">
                <i class="fa fa-play"></i> Generate Payroll
            </button>
            <?php endif; ?>
        </div>
    </div>

    <!-- PAY PERIOD SELECTOR -->
    <div class="pay-period-row">
        <div class="pay-period">
            <strong>Pay Period:</strong>
            <select id="periodSelect" onchange="changePeriod(this.value)">
                <?php if (empty($allPeriods)): ?>
                    <option value="">No pay periods found</option>
                <?php else: ?>
                    <?php foreach ($allPeriods as $p): ?>
                    <option value="<?= $p['period_id'] ?>" <?= $p['period_id'] == $selectedId ? 'selected' : '' ?>>
                        <?= htmlspecialchars($p['period_name']) ?>
                        (<?= ucfirst(strtolower($p['status'])) ?>)
                    </option>
                    <?php endforeach; ?>
                <?php endif; ?>
            </select>
        </div>
        <?php if ($selectedPeriod): ?>
        <span class="period-status-badge period-status--<?= strtolower($selectedPeriod['status']) ?>">
            <?= ucfirst(strtolower($selectedPeriod['status'])) ?>
        </span>
        <?php endif; ?>
    </div>

    <!-- FILTER BAR -->
    <form class="filter-bar" method="GET" action="">
        <input type="hidden" name="period_id" value="<?= $selectedId ?>">
        <div class="filter-search">
            <i class="fa fa-search filter-search-icon"></i>
            <input type="text" name="search" placeholder="Search employee…"
                   value="<?= htmlspecialchars($search) ?>">
        </div>
        <select name="dept_id">
            <option value="">All Departments</option>
            <?php foreach ($departments as $dept): ?>
            <option value="<?= $dept['department_id'] ?>"
                <?= $deptFilter == $dept['department_id'] ? 'selected' : '' ?>>
                <?= htmlspecialchars($dept['department_name']) ?>
            </option>
            <?php endforeach; ?>
        </select>
        <select name="status">
            <option value="">All Statuses</option>
            <?php foreach (['DRAFT','APPROVED','RELEASED'] as $s): ?>
            <option value="<?= $s ?>" <?= $statFilter === $s ? 'selected' : '' ?>>
                <?= ucfirst(strtolower($s)) ?>
            </option>
            <?php endforeach; ?>
        </select>
        <button type="submit" class="btn-primary btn-filter">
            <i class="fa fa-filter"></i> Filter
        </button>
        <?php if ($search || $deptFilter || $statFilter): ?>
        <a href="?period_id=<?= $selectedId ?>" class="btn-outline btn-filter">
            <i class="fa fa-times"></i> Clear
        </a>
        <?php endif; ?>
    </form>

    <!-- FLASH -->
    <div id="payrollFlash" style="display:none;margin-bottom:12px;"></div>

    <!-- REJECTION BANNER — shown to Admin when period was returned by Principal -->
    <?php
    $latestReturn = null;
    if ($selectedPeriod && $selectedPeriod['status'] === 'OPEN') {
        $retStmt = $pdo->prepare("
            SELECT wl.remarks, wl.created_at, wl.performer_name
            FROM payroll_workflow_log wl
            WHERE wl.period_id = ? AND wl.event_type = 'RETURNED'
            ORDER BY wl.created_at DESC LIMIT 1
        ");
        $retStmt->execute([$selectedId]);
        $latestReturn = $retStmt->fetch();
    }
    ?>
    <?php if ($latestReturn): ?>
    <div style="display:flex;gap:14px;background:#fffbeb;border:1.5px solid #fcd34d;border-left:4px solid #d97706;
                border-radius:12px;padding:16px 20px;margin-bottom:16px;align-items:flex-start;">
        <i class="fa fa-triangle-exclamation" style="color:#d97706;font-size:20px;flex-shrink:0;margin-top:2px;"></i>
        <div style="flex:1;">
            <div style="font-size:14px;font-weight:700;color:#92400e;margin-bottom:4px;display:flex;gap:10px;align-items:center;flex-wrap:wrap;">
                Returned for Revision
                <span style="font-size:12px;font-weight:400;color:#b45309;">
                    by <?= htmlspecialchars($latestReturn['performer_name'] ?? 'Principal') ?>
                    on <?= date('M j, Y g:i A', strtotime($latestReturn['created_at'])) ?>
                </span>
            </div>
            <?php if ($latestReturn['remarks']): ?>
            <div style="font-size:14px;font-style:italic;color:#78350f;background:rgba(251,191,36,.15);
                        border-radius:8px;padding:10px 14px;margin:8px 0;line-height:1.5;">
                "<?= htmlspecialchars($latestReturn['remarks']) ?>"
            </div>
            <?php else: ?>
            <div style="font-size:13px;color:#9ca3af;margin:6px 0;">No remarks were provided.</div>
            <?php endif; ?>
            <div style="font-size:12px;color:#92400e;margin-top:6px;">
                <i class="fa fa-circle-info"></i>
                Edit the payroll below to address the remarks, then click
                <strong>Submit for Principal Approval</strong> when ready.
                <a href="<?= BASE_URL ?>modules/payroll/batch-detail.php?period_id=<?= $selectedId ?>&tab=revisions"
                   style="color:#0f766e;margin-left:8px;font-weight:600;text-decoration:none;">
                   <i class="fa fa-clock-rotate-left"></i> View full revision history
                </a>
            </div>
        </div>
    </div>
    <?php endif; ?>

    <!-- TABLE -->
    <div class="table-wrapper">
        <table class="payroll-table">
            <thead>
                <tr>
                    <th>Employee</th>
                    <th>Department</th>
                    <th>Basic</th>
                    <th>Allowances</th>
                    <th>Gross</th>
                    <th>Deductions</th>
                    <th>Net Pay</th>
                    <th>Status</th>
                    <?php if ($periodIsEditable): ?><th>Actions</th><?php endif; ?>
                </tr>
            </thead>
            <tbody>
            <?php if (empty($records)): ?>
                <tr>
                    <td colspan="<?= $periodIsEditable ? 9 : 8 ?>"
                        style="text-align:center;padding:48px;color:#9ca3af;">
                        <?php if (!$selectedId): ?>
                            No pay period selected.
                        <?php elseif ($search || $deptFilter || $statFilter): ?>
                            No records match your filters.
                            <a href="?period_id=<?= $selectedId ?>" style="color:#0f766e;">Clear filters</a>
                        <?php else: ?>
                            No payroll records for this period.
                            <?php if ($periodIsEditable): ?>
                            Click <strong>Generate Payroll</strong> to create them.
                            <?php endif; ?>
                        <?php endif; ?>
                    </td>
                </tr>
            <?php else: ?>
            <?php $periodName = htmlspecialchars($selectedPeriod['period_name'] ?? ''); ?>
            <?php foreach ($records as $row): ?>
                <tr data-payroll-id="<?= $row['payroll_id'] ?>">
                    <td>
                        <strong><?= htmlspecialchars($row['employee_name']) ?></strong><br>
                        <small style="color:#9ca3af"><?= htmlspecialchars($row['position_name'] ?? '') ?></small>
                    </td>
                    <td><?= htmlspecialchars($row['department_name'] ?? '—') ?></td>
                    <td data-col="basic">₱<?= number_format($row['basic_pay'], 2) ?></td>
                    <td data-col="allowances">₱<?= number_format($row['total_allowances'], 2) ?></td>
                    <td data-col="gross"><strong>₱<?= number_format($row['gross_pay'], 2) ?></strong></td>
                    <td data-col="deductions">₱<?= number_format($row['total_deductions'], 2) ?></td>
                    <td data-col="net"><strong>₱<?= number_format($row['net_pay'], 2) ?></strong></td>
                    <td>
                        <span class="badge badge--<?= strtolower($row['payroll_status']) ?>">
                            <?= $row['payroll_status'] ?>
                        </span>
                    </td>
                    <?php if ($periodIsEditable): ?>
                    <td class="row-actions">
                        <!-- data-period-label added so view-modal.php ps-period-label gets populated -->
                        <button class="btn-icon" title="View Payslip"
                            onclick="openPayslip(this)"
                            data-payroll-id="<?= $row['payroll_id'] ?>"
                            data-name="<?= htmlspecialchars($row['employee_name']) ?>"
                            data-position="<?= htmlspecialchars($row['position_name'] ?? '') ?>"
                            data-dept="<?= htmlspecialchars($row['department_name'] ?? '') ?>"
                            data-empid="<?= $row['employee_id'] ?>"
                            data-basic="<?= $row['basic_pay'] ?>"
                            data-assign="<?= $row['addl_assign'] ?>"
                            data-rice="<?= $row['rice_subsidy'] ?>"
                            data-laundry="<?= $row['laundry'] ?>"
                            data-peraa-premium="<?= $row['peraa_premium'] ?>"
                            data-peraa-loan="<?= $row['peraa_loan'] ?>"
                            data-hdmf-premium="<?= $row['hdmf_premium'] ?>"
                            data-hdmf-loan="<?= $row['hdmf_loan'] ?>"
                            data-philhealth="<?= $row['philhealth'] ?>"
                            data-sss-premium="<?= $row['sss_premium'] ?>"
                            data-sss-loan="<?= $row['sss_loan'] ?>"
                            data-period-label="<?= $periodName ?>">
                            <i class="fa fa-eye"></i>
                        </button>
                        <button class="btn-icon btn-icon--edit" title="Edit (this period only)"
                            onclick="openEdit(this)"
                            data-payroll-id="<?= $row['payroll_id'] ?>"
                            data-name="<?= htmlspecialchars($row['employee_name']) ?>"
                            data-position="<?= htmlspecialchars($row['position_name'] ?? '') ?>"
                            data-dept="<?= htmlspecialchars($row['department_name'] ?? '') ?>"
                            data-empid="<?= $row['employee_id'] ?>"
                            data-basic="<?= $row['basic_pay'] ?>"
                            data-assign="<?= $row['addl_assign'] ?>"
                            data-rice="<?= $row['rice_subsidy'] ?>"
                            data-laundry="<?= $row['laundry'] ?>"
                            data-peraa-premium="<?= $row['peraa_premium'] ?>"
                            data-peraa-loan="<?= $row['peraa_loan'] ?>"
                            data-hdmf-premium="<?= $row['hdmf_premium'] ?>"
                            data-hdmf-loan="<?= $row['hdmf_loan'] ?>"
                            data-philhealth="<?= $row['philhealth'] ?>"
                            data-sss-premium="<?= $row['sss_premium'] ?>"
                            data-sss-loan="<?= $row['sss_loan'] ?>">
                            <i class="fa fa-pen"></i>
                        </button>
                    </td>
                    <?php endif; ?>
                </tr>
            <?php endforeach; ?>
            <?php endif; ?>
            </tbody>
        </table>
    </div>

    <!-- TOTALS + WORKFLOW ACTIONS -->
    <div class="totals-bar">
        <div class="total-item">
            <span>Total Gross</span>
            <strong id="total-gross">₱<?= number_format($totalGross, 2) ?></strong>
        </div>
        <div class="total-item">
            <span>Total Deductions</span>
            <strong id="total-ded" style="color:#ef4444">₱<?= number_format($totalDed, 2) ?></strong>
        </div>
        <div class="total-item total-item--net">
            <span>Total Net Pay</span>
            <strong id="total-net">₱<?= number_format($totalNet, 2) ?></strong>
        </div>

        <div class="payroll-flow-actions">
        <?php if ($selectedPeriod): ?>

          <?php if ($selectedPeriod['status'] === 'OPEN' && $recordCount > 0): ?>
            <!-- STEP 1: Admin submits to Principal -->
            <button class="btn-primary"
                    onclick="confirmPayrollAction('submit', <?= $selectedId ?>)">
              <i class="fa fa-paper-plane"></i> Submit for Principal Approval
            </button>

          <?php elseif ($selectedPeriod['status'] === 'PROCESSING'): ?>
            <!-- Awaiting Principal — show status only, no action for admin here -->
            <div class="processing-notice">
              <i class="fa fa-clock"></i> Awaiting Principal Approval
            </div>

          <?php elseif ($selectedPeriod['status'] === 'APPROVED'): ?>
            <!-- STEP 3: Admin releases and prints payslips -->
            <div class="approved-notice">
              <i class="fa fa-circle-check" style="color:#059669"></i> Approved — ready to release
            </div>
            <button class="btn-outline" onclick="window.print()">
              <i class="fa fa-print"></i> Print Payslips
            </button>
            <button class="btn-primary" onclick="confirmPayrollAction('release', <?= $selectedId ?>)"
                    style="background:#7c3aed;">
              <i class="fa fa-money-bill-wave"></i> Release Payroll
            </button>

          <?php elseif ($selectedPeriod['status'] === 'RELEASED'): ?>
            <!-- STEP 4: Released — archive/print only -->
            <div class="released-notice">
              <i class="fa fa-circle-check" style="color:#7c3aed"></i> Released
            </div>
            <button class="btn-outline" onclick="window.print()">
              <i class="fa fa-print"></i> Print Payslips
            </button>
          <?php endif; ?>

        <?php endif; ?>
        </div>
    </div>

    <!-- Payroll action confirmation modal (inline, no class dependency) -->
    <div id="payrollConfirmModal" style="display:none;position:fixed;inset:0;background:rgba(0,0,0,0.4);
         z-index:1000;align-items:center;justify-content:center;">
      <div style="background:#fff;border-radius:14px;padding:24px;width:420px;max-width:95%;
                  box-shadow:0 16px 48px rgba(0,0,0,0.12);">
        <h3 id="pcm-title" style="font-size:16px;font-weight:700;margin:0 0 8px;"></h3>
        <p id="pcm-desc" style="font-size:13px;color:#64748b;margin:0 0 14px;line-height:1.6;"></p>
        <div id="pcm-notes-wrap" style="display:none;margin-bottom:14px;">
          <label style="font-size:12px;font-weight:600;color:#374151;display:block;margin-bottom:4px;">
            Notes <span style="color:#9ca3af;font-weight:400;">(optional)</span>
          </label>
          <textarea id="pcm-notes" rows="2" placeholder="Add a note…"
                    style="width:100%;padding:9px 10px;border:1px solid #d1d5db;border-radius:8px;
                           font-size:13px;resize:vertical;font-family:inherit;"></textarea>
        </div>
        <div style="display:flex;justify-content:flex-end;gap:10px;">
          <button class="btn-outline" style="padding:9px 16px;font-size:13px;"
                  onclick="closePayrollConfirm()">Cancel</button>
          <button id="pcm-confirm-btn" class="btn-primary" style="padding:9px 20px;font-size:13px;"
                  onclick="executePayrollAction()">Confirm</button>
        </div>
      </div>
    </div>

</div><!-- .payroll-container -->
</div><!-- .main-content -->
</div><!-- .main -->
</div><!-- .layout -->

<?php include __DIR__ . '/modals/view-modal.php'; ?>
<?php include __DIR__ . '/modals/edit-modal.php'; ?>
<?php include __DIR__ . '/modals/generate-modal.php'; ?>

<script>
const BASE_URL       = '<?= BASE_URL ?>';
const PERIOD_ID      = <?= $selectedId ?: 0 ?>;
const PERIOD_IS_OPEN = <?= $periodIsEditable ? 'true' : 'false' ?>;

function changePeriod(id) {
    if (!id) return;
    const url = new URL(window.location.href);
    url.searchParams.set('period_id', id);
    window.location.href = url.toString();
}

function recalcFooterTotals() {
    let gross = 0, ded = 0, net = 0;
    document.querySelectorAll('tbody tr[data-payroll-id]').forEach(tr => {
        gross += parsePeso(tr.querySelector('[data-col="gross"]')?.textContent);
        ded   += parsePeso(tr.querySelector('[data-col="deductions"]')?.textContent);
        net   += parsePeso(tr.querySelector('[data-col="net"]')?.textContent);
    });
    const fmt = v => '₱' + v.toLocaleString('en-PH', {minimumFractionDigits:2, maximumFractionDigits:2});
    const g = document.getElementById('total-gross');
    const d = document.getElementById('total-ded');
    const n = document.getElementById('total-net');
    if (g) g.textContent = fmt(gross);
    if (d) d.textContent = fmt(ded);
    if (n) n.textContent = fmt(net);
}

function parsePeso(str) {
    return parseFloat((str || '0').replace(/[₱,]/g, '')) || 0;
}

function showFlash(msg, ok) {
    const el = document.getElementById('payrollFlash');
    if (!el) return;
    el.style.cssText = `display:block;padding:12px 16px;border-radius:8px;margin-bottom:12px;font-size:14px;
        ${ok ? 'background:#d1fae5;color:#065f46;border:1px solid #6ee7b7'
              : 'background:#fee2e2;color:#991b1b;border:1px solid #fca5a5'};`;
    el.textContent = msg;
    if (ok) setTimeout(() => el.style.display = 'none', 5000);
}

// ── Payroll workflow confirmation ─────────────────────────────────────────────
let _pcmAction = null, _pcmPeriodId = null;

const _pcmConfig = {
    // FIX: was incorrectly pointing to payroll-submit-approval.php (file doesn't exist)
    submit: {
        title: 'Submit for Principal Approval',
        desc:  'This will lock the payroll from further edits and send it to the Principal for review. Continue?',
        notes: false, btnLabel: 'Submit', btnColor: '#0f766e',
        url: BASE_URL + 'actions/payroll-save.php',
        field: 'period_id'
    },
    // approve and return intentionally omitted — Principal role only, not available to Admin
    release: {
        title: 'Release Payroll',
        desc:  'Mark this payroll as released? This confirms salaries have been distributed to employees.',
        notes: false, btnLabel: 'Release', btnColor: '#7c3aed',
        url: BASE_URL + 'actions/payroll-approve.php', field: 'period_id'
    }
};

function confirmPayrollAction(action, periodId) {
    const cfg = _pcmConfig[action];
    if (!cfg) return;
    _pcmAction = action;
    _pcmPeriodId = periodId;
    document.getElementById('pcm-title').textContent          = cfg.title;
    document.getElementById('pcm-desc').textContent           = cfg.desc;
    document.getElementById('pcm-notes-wrap').style.display   = cfg.notes ? 'block' : 'none';
    document.getElementById('pcm-notes').value                = '';
    const btn = document.getElementById('pcm-confirm-btn');
    btn.textContent      = cfg.btnLabel;
    btn.style.background = cfg.btnColor;
    btn.disabled         = false;
    document.getElementById('payrollConfirmModal').style.display = 'flex';
}

function closePayrollConfirm() {
    document.getElementById('payrollConfirmModal').style.display = 'none';
    _pcmAction = null;
    _pcmPeriodId = null;
}

async function executePayrollAction() {
    const cfg = _pcmConfig[_pcmAction];
    if (!cfg || !_pcmPeriodId) return;

    const btn = document.getElementById('pcm-confirm-btn');
    btn.disabled    = true;
    btn.textContent = 'Processing…';

    const fd = new FormData();
    fd.append(cfg.field, _pcmPeriodId);
    // payroll-approve.php needs an 'action' param; payroll-save.php does not
    if (_pcmAction !== 'submit') fd.append('action', _pcmAction);
    const notes = document.getElementById('pcm-notes').value.trim();
    if (notes) fd.append('notes', notes);

    try {
        const res  = await fetch(cfg.url, { method: 'POST', body: fd });
        const data = await res.json();
        closePayrollConfirm();
        showFlash(data.message, data.success);
        if (data.success) setTimeout(() => location.reload(), 1400);
        else {
            btn.disabled    = false;
            btn.textContent = cfg.btnLabel;
        }
    } catch {
        showFlash('Network error. Please try again.', false);
        btn.disabled    = false;
        btn.textContent = cfg.btnLabel;
    }
}

// ── Generate modal ────────────────────────────────────────────────────────────
window.openGenerateModal  = () => document.getElementById('generateModal').style.display = 'flex';
window.closeGenerateModal = () => document.getElementById('generateModal').style.display = 'none';

document.addEventListener('DOMContentLoaded', () => {
    const genForm = document.querySelector('#generateModal form');
    if (!genForm) return;

    genForm.addEventListener('submit', async e => {
        e.preventDefault();
        const btn = genForm.querySelector('[type="submit"]');
        btn.disabled = true;
        btn.innerHTML = '<i class="fa fa-spinner fa-spin"></i> Generating…';
        try {
            const res  = await fetch(`${BASE_URL}actions/generate-payroll.php`, {
                method: 'POST', body: new FormData(genForm)
            });
            const data = await res.json();
            closeGenerateModal();
            showFlash(data.message, data.success);
            if (data.success) setTimeout(() => location.reload(), 1400);
            else { btn.disabled = false; btn.innerHTML = '<i class="fa fa-play"></i> Generate'; }
        } catch {
            closeGenerateModal();
            showFlash('Network error. Please try again.', false);
            btn.disabled = false;
        }
    });
});
</script>
<script src="<?= BASE_URL ?>assets/js/payroll.js?v=<?= time() ?>"></script>
<?php include __DIR__ . '/../../includes/footer.php'; ?>