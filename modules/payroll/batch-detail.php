<?php
/**
 * modules/payroll/batch-detail.php
 * Payroll Batch Details — tabbed view accessible by both Admin and Principal.
 * Role determines which action buttons are visible; data is shared.
 */
require_once __DIR__ . '/../../config/config.php';
require_once __DIR__ . '/../../includes/auth.php';
requireLogin();

$periodId = (int)($_GET['period_id'] ?? 0);
if (!$periodId) { header('Location: ' . BASE_URL . 'modules/payroll/index.php'); exit; }

// ── Period ────────────────────────────────────────────────────────────────────
$period = $pdo->prepare("SELECT * FROM payroll_periods WHERE period_id = ?");
$period->execute([$periodId]);
$period = $period->fetch();
if (!$period) { header('Location: ' . BASE_URL . 'modules/payroll/index.php'); exit; }

// ── Totals ────────────────────────────────────────────────────────────────────
$totRow = $pdo->prepare("
    SELECT COUNT(*) AS emp_count, SUM(basic_pay) AS basic,
           SUM(total_allowances) AS allowances, SUM(gross_pay) AS gross,
           SUM(total_deductions) AS deductions, SUM(net_pay) AS net
    FROM payroll_records WHERE period_id = ?
");
$totRow->execute([$periodId]);
$totals = $totRow->fetch();

// ── Payroll records (with pivoted allowances/deductions) ──────────────────────
$recStmt = $pdo->prepare("
    SELECT pr.*,
           CONCAT(e.first_name,' ',COALESCE(e.middle_name,''),' ',e.last_name) AS employee_name,
           e.employee_no,
           p.position_name, d.department_name,
           COALESCE(MAX(CASE WHEN at2.allowance_name LIKE '%Additional Assignment%' THEN pa.amount END),0) AS addl_assign,
           COALESCE(MAX(CASE WHEN at2.allowance_name LIKE '%Rice%'    THEN pa.amount END),0) AS rice_sub,
           COALESCE(MAX(CASE WHEN at2.allowance_name LIKE '%Laundry%' THEN pa.amount END),0) AS laundry,
           COALESCE(MAX(CASE WHEN dt.deduction_name LIKE '%PERAA%Premium%' THEN pd.amount END),0) AS peraa_p,
           COALESCE(MAX(CASE WHEN dt.deduction_name LIKE '%PERAA%Loan%'    THEN pd.amount END),0) AS peraa_l,
           COALESCE(MAX(CASE WHEN dt.deduction_name LIKE '%HDMF%Premium%'  THEN pd.amount END),0) AS hdmf_p,
           COALESCE(MAX(CASE WHEN dt.deduction_name LIKE '%HDMF%Loan%'     THEN pd.amount END),0) AS hdmf_l,
           COALESCE(MAX(CASE WHEN dt.deduction_name LIKE '%Phil%'           THEN pd.amount END),0) AS philhealth,
           COALESCE(MAX(CASE WHEN dt.deduction_name LIKE '%SSS%Premium%'   THEN pd.amount END),0) AS sss_p,
           COALESCE(MAX(CASE WHEN dt.deduction_name LIKE '%SSS%Loan%'      THEN pd.amount END),0) AS sss_l
    FROM payroll_records pr
    JOIN employees e ON pr.employee_id = e.employee_id
    LEFT JOIN positions p   ON e.position_id   = p.position_id
    LEFT JOIN departments d ON e.department_id = d.department_id
    LEFT JOIN payroll_allowances pa ON pr.payroll_id = pa.payroll_id
    LEFT JOIN allowance_types at2   ON pa.allowance_type_id = at2.allowance_type_id
    LEFT JOIN payroll_deductions pd ON pr.payroll_id = pd.payroll_id
    LEFT JOIN deduction_types dt    ON pd.deduction_type_id = dt.deduction_type_id
    WHERE pr.period_id = ?
    GROUP BY pr.payroll_id, e.employee_no, e.first_name, e.middle_name, e.last_name,
             p.position_name, d.department_name,
             pr.basic_pay, pr.gross_pay, pr.total_allowances, pr.total_deductions, pr.net_pay, pr.payroll_status
    ORDER BY e.last_name, e.first_name
");
$recStmt->execute([$periodId]);
$records = $recStmt->fetchAll();

$allowanceDetails = [];
$deductionDetails = [];
if (!empty($records)) {
    $detailStmt = $pdo->prepare("
        SELECT pr.payroll_id, at2.allowance_name AS name, pa.amount
        FROM payroll_records pr
        JOIN payroll_allowances pa ON pr.payroll_id = pa.payroll_id
        JOIN allowance_types at2 ON pa.allowance_type_id = at2.allowance_type_id
        WHERE pr.period_id = ?
        ORDER BY at2.allowance_name
    ");
    $detailStmt->execute([$periodId]);
    foreach ($detailStmt->fetchAll() as $d) {
        $allowanceDetails[$d['payroll_id']][] = [
            'name' => $d['name'],
            'amount' => (float)$d['amount'],
        ];
    }

    $detailStmt = $pdo->prepare("
        SELECT pr.payroll_id, dt.deduction_name AS name, pd.amount
        FROM payroll_records pr
        JOIN payroll_deductions pd ON pr.payroll_id = pd.payroll_id
        JOIN deduction_types dt ON pd.deduction_type_id = dt.deduction_type_id
        WHERE pr.period_id = ?
        ORDER BY dt.deduction_name
    ");
    $detailStmt->execute([$periodId]);
    foreach ($detailStmt->fetchAll() as $d) {
        $deductionDetails[$d['payroll_id']][] = [
            'name' => $d['name'],
            'amount' => (float)$d['amount'],
        ];
    }
}

// ── Workflow log ──────────────────────────────────────────────────────────────
$wlStmt = $pdo->prepare("
    SELECT wl.*, r.role_name AS performer_role
    FROM payroll_workflow_log wl
    JOIN users u   ON wl.performed_by = u.user_id
    JOIN roles r   ON u.role_id = r.role_id
    WHERE wl.period_id = ?
    ORDER BY wl.created_at ASC
");
$wlStmt->execute([$periodId]);
$workflowLog = $wlStmt->fetchAll();

// ── Latest rejection remarks (for admin banner) ───────────────────────────────
$latestReturn = null;
if ($period['status'] === 'OPEN') {
    foreach (array_reverse($workflowLog) as $ev) {
        if ($ev['event_type'] === 'RETURNED') { $latestReturn = $ev; break; }
    }
}

// ── Build revision cycles ─────────────────────────────────────────────────────
// Each SUBMITTED event starts a new round; subsequent events belong to that round
$revisionCycles = [];
$currentCycle   = null;
foreach ($workflowLog as $ev) {
    if ($ev['event_type'] === 'SUBMITTED') {
        if ($currentCycle) $revisionCycles[] = $currentCycle;
        $currentCycle = ['submitted' => $ev, 'events' => []];
    } elseif ($currentCycle) {
        $currentCycle['events'][] = $ev;
    }
}
if ($currentCycle) $revisionCycles[] = $currentCycle;

// ── Role flags ────────────────────────────────────────────────────────────────
$userIsAdmin     = isAdmin();
$userIsPrincipal = isPrincipalRole();
$canEdit         = $userIsAdmin && $period['status'] === 'OPEN';

$pageTitle = 'Payroll Batch — ' . $period['period_name'];
// Load principal.css when accessed by Principal — it contains .pr-modal-overlay positioning
// (position:fixed; inset:0) that the approve/reject modals depend on.
// Without it the overlays have no fixed positioning and render inline side-by-side.
$extraCSS  = [BASE_URL . 'assets/css/payroll.css', BASE_URL . 'assets/css/batch-detail.css'];
if ($userIsPrincipal) {
    $extraCSS[] = BASE_URL . 'assets/css/principal.css';
}
require_once __DIR__ . '/../../includes/head.php';

// ── Helpers ───────────────────────────────────────────────────────────────────
function peso($n)   { return '₱' . number_format((float)$n, 2); }
function fmtDt($d)  { return $d ? date('M j, Y g:i A', strtotime($d)) : '—'; }
function fmtD($d)   { return $d ? date('M j, Y', strtotime($d)) : '—'; }
?>
<body>
<div class="layout">
<?php
// Render correct sidebar depending on role
if ($userIsPrincipal) include __DIR__ . '/../../includes/principal-sidebar.php';
else                  include __DIR__ . '/../../includes/sidebar.php';
?>
<div class="main">
<?php include __DIR__ . '/../../includes/header.php'; ?>
<div class="main-content">

<!-- ── Breadcrumb ── -->
<nav class="bd-breadcrumb">
    <a href="<?= $userIsPrincipal ? BASE_URL.'modules/principal/payroll-approval/index.php' : BASE_URL.'modules/payroll/index.php' ?>">
        <i class="fa fa-chevron-left"></i>
        <?= $userIsPrincipal ? 'Payroll Approvals' : 'Payroll Management' ?>
    </a>
    <span>/</span>
    <span><?= htmlspecialchars($period['period_name']) ?></span>
</nav>

<!-- ── Batch Header ── -->
<div class="bd-header">
    <div class="bd-header-left">
        <div class="bd-period-name"><?= htmlspecialchars($period['period_name']) ?></div>
        <div class="bd-period-dates">
            <?= fmtD($period['pay_period_start']) ?> – <?= fmtD($period['pay_period_end']) ?>
            &nbsp;·&nbsp; Pay Date: <?= fmtD($period['pay_date']) ?>
        </div>
    </div>
    <div class="bd-header-right">
        <span class="bd-status-badge bd-status-<?= strtolower($period['status']) ?>">
            <?= ucfirst(strtolower($period['status'])) ?>
        </span>
        <!-- Role-based action buttons -->
        <?php if ($userIsAdmin): ?>
            <?php if ($period['status'] === 'OPEN' && !empty($records)): ?>
            <button class="btn-primary" onclick="confirmPayrollAction('submit',<?= $periodId ?>)">
                <i class="fa fa-paper-plane"></i> Submit for Approval
            </button>
            <?php elseif ($period['status'] === 'APPROVED'): ?>
            <button class="btn-primary" style="background:#7c3aed;"
                    onclick="confirmPayrollAction('release',<?= $periodId ?>)">
                <i class="fa fa-money-bill-wave"></i> Release Payroll
            </button>
            <?php elseif ($period['status'] === 'RELEASED'): ?>
            <button class="btn-outline" onclick="window.print()">
                <i class="fa fa-print"></i> Print Payslips
            </button>
            <?php endif; ?>
        <?php endif; ?>
        <?php if ($userIsPrincipal && $period['status'] === 'PROCESSING'): ?>
            <button class="pr-btn-approve"
                    onclick="openApproveConfirm(<?= $periodId ?>, '<?= htmlspecialchars($period['period_name'], ENT_QUOTES) ?>')">
                <i class="fa fa-circle-check"></i> Approve
            </button>
            <button class="pr-btn-reject"
                    onclick="openRejectModal(<?= $periodId ?>, '<?= htmlspecialchars($period['period_name'], ENT_QUOTES) ?>')">
                <i class="fa fa-circle-xmark"></i> Reject
            </button>
        <?php endif; ?>
    </div>
</div>

<!-- ── Stats ── -->
<div class="bd-stats-row">
    <div class="bd-stat"><div class="bd-stat-label">Employees</div><div class="bd-stat-val"><?= (int)$totals['emp_count'] ?></div></div>
    <div class="bd-stat"><div class="bd-stat-label">Total Gross</div><div class="bd-stat-val"><?= peso($totals['gross']) ?></div></div>
    <div class="bd-stat bd-stat--red"><div class="bd-stat-label">Total Deductions</div><div class="bd-stat-val"><?= peso($totals['deductions']) ?></div></div>
    <div class="bd-stat bd-stat--teal"><div class="bd-stat-label">Total Net Pay</div><div class="bd-stat-val"><?= peso($totals['net']) ?></div></div>
</div>

<!-- ── Rejection Alert (Admin sees this when payroll was returned) ── -->
<?php if ($latestReturn && $userIsAdmin): ?>
<div class="bd-rejection-alert">
    <div class="bd-rejection-icon"><i class="fa fa-triangle-exclamation"></i></div>
    <div class="bd-rejection-body">
        <div class="bd-rejection-title">
            Returned for Revision
            <span class="bd-rejection-meta">by <?= htmlspecialchars($latestReturn['performer_name'] ?? 'Principal') ?> on <?= fmtDt($latestReturn['created_at']) ?></span>
        </div>
        <?php if ($latestReturn['remarks']): ?>
        <div class="bd-rejection-remarks">"<?= htmlspecialchars($latestReturn['remarks']) ?>"</div>
        <?php else: ?>
        <div class="bd-rejection-remarks bd-rejection-remarks--empty">No remarks were provided.</div>
        <?php endif; ?>
        <div class="bd-rejection-hint">
            <i class="fa fa-circle-info"></i>
            Edit the payroll below, then click <strong>Submit for Approval</strong> when ready.
        </div>
    </div>
</div>
<?php endif; ?>

<!-- ── Tabs ── -->
<div class="bd-tabs">
    <button class="bd-tab active" data-tab="overview">
        <i class="fa fa-chart-pie"></i> Overview
    </button>
    <button class="bd-tab" data-tab="employees">
        <i class="fa fa-users"></i> Employees
        <span class="bd-tab-count"><?= (int)$totals['emp_count'] ?></span>
    </button>
    <button class="bd-tab" data-tab="history">
        <i class="fa fa-clock-rotate-left"></i> Approval History
        <span class="bd-tab-count"><?= count($workflowLog) ?></span>
    </button>
    <button class="bd-tab" data-tab="revisions">
        <i class="fa fa-code-compare"></i> Revision History
        <span class="bd-tab-count"><?= count($revisionCycles) ?></span>
    </button>
    <button class="bd-tab" data-tab="payslips">
        <i class="fa fa-receipt"></i> Payslips
    </button>
</div>

<!-- ══════════════════════════════════════════════════════════════════════════ -->
<!-- TAB: OVERVIEW -->
<!-- ══════════════════════════════════════════════════════════════════════════ -->
<div class="bd-tab-content active" id="tab-overview">
    <div class="bd-section-title">Status Timeline</div>
    <div class="bd-timeline">
        <?php
        $statusSteps = [
            'OPEN'       => ['OPEN',       'Generated',  'fa-file-circle-plus'],
            'PROCESSING' => ['PROCESSING', 'Submitted',  'fa-paper-plane'],
            'APPROVED'   => ['APPROVED',   'Approved',   'fa-circle-check'],
            'RELEASED'   => ['RELEASED',   'Released',   'fa-money-bill-wave'],
        ];
        $statusOrder = ['OPEN','PROCESSING','APPROVED','RELEASED'];
        $currentIdx  = array_search($period['status'], $statusOrder);
        foreach ($statusOrder as $i => $st):
            $done    = $i < $currentIdx;
            $current = $i === $currentIdx;
            $icon    = $statusSteps[$st][2];
            $label   = $statusSteps[$st][1];
            // Find matching log event
            $logMatch = null;
            $evMap = ['PROCESSING'=>'SUBMITTED','APPROVED'=>'APPROVED','RELEASED'=>'RELEASED'];
            foreach ($workflowLog as $ev) {
                if (isset($evMap[$st]) && $ev['event_type'] === $evMap[$st]) $logMatch = $ev;
            }
        ?>
        <div class="bd-timeline-step <?= $done?'done':($current?'current':'') ?>">
            <div class="bd-tl-dot"><i class="fa <?= $icon ?>"></i></div>
            <div class="bd-tl-label"><?= $label ?></div>
            <?php if ($logMatch): ?>
            <div class="bd-tl-meta"><?= fmtD($logMatch['created_at']) ?></div>
            <div class="bd-tl-who"><?= htmlspecialchars($logMatch['performer_name'] ?? '') ?></div>
            <?php endif; ?>
        </div>
        <?php if ($i < count($statusOrder)-1): ?>
        <div class="bd-timeline-line <?= $done?'done':'' ?>"></div>
        <?php endif; ?>
        <?php endforeach; ?>
    </div>

    <!-- Returned steps (show inline rejections if any) -->
    <?php $returnedEvents = array_filter($workflowLog, fn($e) => $e['event_type'] === 'RETURNED'); ?>
    <?php if (!empty($returnedEvents)): ?>
    <div class="bd-section-title" style="margin-top:28px;">Rejection Events</div>
    <?php foreach ($returnedEvents as $ret): ?>
    <div class="bd-rejection-card">
        <div class="bd-rejection-card-header">
            <span class="bd-badge-returned"><i class="fa fa-rotate-left"></i> Returned</span>
            <span class="bd-rejection-card-who">
                by <?= htmlspecialchars($ret['performer_name'] ?? 'Principal') ?>
                &nbsp;·&nbsp; <?= fmtDt($ret['created_at']) ?>
            </span>
        </div>
        <?php if ($ret['remarks']): ?>
        <div class="bd-rejection-card-remarks"><?= htmlspecialchars($ret['remarks']) ?></div>
        <?php else: ?>
        <div class="bd-rejection-card-remarks bd-empty">No remarks provided.</div>
        <?php endif; ?>
    </div>
    <?php endforeach; ?>
    <?php endif; ?>
</div>

<!-- ══════════════════════════════════════════════════════════════════════════ -->
<!-- TAB: EMPLOYEES -->
<!-- ══════════════════════════════════════════════════════════════════════════ -->
<div class="bd-tab-content" id="tab-employees">
    <div class="table-wrapper">
    <table class="payroll-table">
        <thead>
            <tr>
                <th>Employee</th>
                <th>Dept</th>
                <th>Basic</th>
                <th>Add'l</th>
                <th>Rice</th>
                <th>Laundry</th>
                <th>Gross</th>
                <th>PERAA-P</th><th>PERAA-L</th>
                <th>HDMF-P</th><th>HDMF-L</th>
                <th>PhilHealth</th>
                <th>SSS-P</th><th>SSS-L</th>
                <th>Total Ded.</th>
                <th>Net Pay</th>
                <th>Status</th>
                <?php if ($canEdit): ?><th>Actions</th><?php endif; ?>
            </tr>
        </thead>
        <tbody>
        <?php if (empty($records)): ?>
            <tr><td colspan="18" style="text-align:center;padding:40px;color:#9ca3af;">No payroll records.</td></tr>
        <?php else: ?>
        <?php foreach ($records as $r): ?>
        <?php
            $allowanceJson = htmlspecialchars(json_encode($allowanceDetails[$r['payroll_id']] ?? []), ENT_QUOTES, 'UTF-8');
            $deductionJson = htmlspecialchars(json_encode($deductionDetails[$r['payroll_id']] ?? []), ENT_QUOTES, 'UTF-8');
        ?>
        <tr data-payroll-id="<?= $r['payroll_id'] ?>">
            <td>
                <strong><?= htmlspecialchars(trim($r['employee_name'])) ?></strong><br>
                <small style="color:#9ca3af"><?= htmlspecialchars($r['position_name'] ?? '') ?></small>
            </td>
            <td><?= htmlspecialchars($r['department_name'] ?? '—') ?></td>
            <td><?= peso($r['basic_pay']) ?></td>
            <td><?= peso($r['addl_assign']) ?></td>
            <td><?= peso($r['rice_sub']) ?></td>
            <td><?= peso($r['laundry']) ?></td>
            <td><strong><?= peso($r['gross_pay']) ?></strong></td>
            <td><?= peso($r['peraa_p']) ?></td>
            <td><?= peso($r['peraa_l']) ?></td>
            <td><?= peso($r['hdmf_p']) ?></td>
            <td><?= peso($r['hdmf_l']) ?></td>
            <td><?= peso($r['philhealth']) ?></td>
            <td><?= peso($r['sss_p']) ?></td>
            <td><?= peso($r['sss_l']) ?></td>
            <td style="color:#ef4444"><?= peso($r['total_deductions']) ?></td>
            <td><strong style="color:#0f766e"><?= peso($r['net_pay']) ?></strong></td>
            <td><span class="badge badge--<?= strtolower($r['payroll_status']) ?>"><?= $r['payroll_status'] ?></span></td>
            <?php if ($canEdit): ?>
            <td class="row-actions">
                <button class="btn-icon" title="View Payslip" onclick="openPayslip(this)"
                    data-name="<?= htmlspecialchars(trim($r['employee_name'])) ?>"
                    data-position="<?= htmlspecialchars($r['position_name'] ?? '') ?>"
                    data-dept="<?= htmlspecialchars($r['department_name'] ?? '') ?>"
                    data-empid="<?= $r['employee_id'] ?>"
                    data-basic="<?= $r['basic_pay'] ?>" data-assign="<?= $r['addl_assign'] ?>"
                    data-rice="<?= $r['rice_sub'] ?>" data-laundry="<?= $r['laundry'] ?>"
                    data-peraa-premium="<?= $r['peraa_p'] ?>" data-peraa-loan="<?= $r['peraa_l'] ?>"
                    data-hdmf-premium="<?= $r['hdmf_p'] ?>" data-hdmf-loan="<?= $r['hdmf_l'] ?>"
                    data-philhealth="<?= $r['philhealth'] ?>"
                    data-sss-premium="<?= $r['sss_p'] ?>" data-sss-loan="<?= $r['sss_l'] ?>"
                    data-period-label="<?= htmlspecialchars($period['period_name']) ?>"
                    data-allowances="<?= $allowanceJson ?>"
                    data-deductions="<?= $deductionJson ?>"
                    data-gross="<?= $r['gross_pay'] ?>"
                    data-total-deductions="<?= $r['total_deductions'] ?>"
                    data-net="<?= $r['net_pay'] ?>">
                    <i class="fa fa-eye"></i>
                </button>
                <button class="btn-icon btn-icon--edit" title="Edit" onclick="openEdit(this)"
                    data-payroll-id="<?= $r['payroll_id'] ?>"
                    data-name="<?= htmlspecialchars(trim($r['employee_name'])) ?>"
                    data-position="<?= htmlspecialchars($r['position_name'] ?? '') ?>"
                    data-dept="<?= htmlspecialchars($r['department_name'] ?? '') ?>"
                    data-empid="<?= $r['employee_id'] ?>"
                    data-basic="<?= $r['basic_pay'] ?>" data-assign="<?= $r['addl_assign'] ?>"
                    data-rice="<?= $r['rice_sub'] ?>" data-laundry="<?= $r['laundry'] ?>"
                    data-peraa-premium="<?= $r['peraa_p'] ?>" data-peraa-loan="<?= $r['peraa_l'] ?>"
                    data-hdmf-premium="<?= $r['hdmf_p'] ?>" data-hdmf-loan="<?= $r['hdmf_l'] ?>"
                    data-philhealth="<?= $r['philhealth'] ?>"
                    data-sss-premium="<?= $r['sss_p'] ?>" data-sss-loan="<?= $r['sss_l'] ?>">
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
</div>

<!-- ══════════════════════════════════════════════════════════════════════════ -->
<!-- TAB: APPROVAL HISTORY -->
<!-- ══════════════════════════════════════════════════════════════════════════ -->
<div class="bd-tab-content" id="tab-history">
    <?php if (empty($workflowLog)): ?>
        <p style="color:#9ca3af;padding:32px 0;text-align:center;">No workflow events recorded yet.</p>
    <?php else: ?>
    <div class="bd-event-timeline">
        <?php
        $eventMeta = [
            'GENERATED' => ['fa-file-circle-plus','#0f766e','Generated'],
            'SUBMITTED' => ['fa-paper-plane',      '#2563eb','Submitted for Approval'],
            'APPROVED'  => ['fa-circle-check',     '#059669','Approved'],
            'RETURNED'  => ['fa-rotate-left',      '#d97706','Returned for Revision'],
            'RELEASED'  => ['fa-money-bill-wave',  '#7c3aed','Released'],
        ];
        foreach ($workflowLog as $i => $ev):
            [$icon, $color, $label] = $eventMeta[$ev['event_type']] ?? ['fa-circle','#9ca3af','Unknown'];
        ?>
        <div class="bd-event-item">
            <div class="bd-event-dot" style="background:<?= $color ?>;">
                <i class="fa <?= $icon ?>"></i>
            </div>
            <?php if ($i < count($workflowLog)-1): ?><div class="bd-event-line"></div><?php endif; ?>
            <div class="bd-event-body">
                <div class="bd-event-header">
                    <span class="bd-event-label" style="color:<?= $color ?>;"><?= $label ?></span>
                    <span class="bd-event-date"><?= fmtDt($ev['created_at']) ?></span>
                </div>
                <div class="bd-event-who">
                    <i class="fa fa-user" style="font-size:11px;margin-right:4px;"></i>
                    <?= htmlspecialchars($ev['performer_name'] ?? '—') ?>
                    <span class="bd-event-role"><?= htmlspecialchars($ev['performer_role'] ?? '') ?></span>
                </div>
                <?php if ($ev['remarks']): ?>
                <div class="bd-event-remarks">
                    <i class="fa fa-comment-dots"></i>
                    <?= htmlspecialchars($ev['remarks']) ?>
                </div>
                <?php endif; ?>
                <?php if ($ev['gross_total']): ?>
                <div class="bd-event-snapshot">
                    <?= (int)$ev['emp_count'] ?> employees &nbsp;·&nbsp;
                    Gross: <?= peso($ev['gross_total']) ?> &nbsp;·&nbsp;
                    Net: <?= peso($ev['net_total']) ?>
                </div>
                <?php endif; ?>
            </div>
        </div>
        <?php endforeach; ?>
    </div>
    <?php endif; ?>
</div>

<!-- ══════════════════════════════════════════════════════════════════════════ -->
<!-- TAB: REVISION HISTORY -->
<!-- ══════════════════════════════════════════════════════════════════════════ -->
<div class="bd-tab-content" id="tab-revisions">
    <?php if (empty($revisionCycles)): ?>
        <p style="color:#9ca3af;padding:32px 0;text-align:center;">No revision cycles recorded yet.</p>
    <?php else: ?>
    <?php foreach (array_reverse($revisionCycles) as $ci => $cycle):
        $cycleNum  = count($revisionCycles) - $ci;
        $submitted = $cycle['submitted'];
        $returned  = null; $approved = null;
        foreach ($cycle['events'] as $ev) {
            if ($ev['event_type'] === 'RETURNED') $returned = $ev;
            if ($ev['event_type'] === 'APPROVED')  $approved = $ev;
        }
    ?>
    <div class="bd-revision-card <?= $returned && !$approved ? 'bd-revision-card--rejected' : ($approved ? 'bd-revision-card--approved' : '') ?>">
        <div class="bd-revision-header">
            <span class="bd-revision-num">Submission #<?= $cycleNum ?></span>
            <span class="bd-revision-date"><?= fmtDt($submitted['created_at']) ?></span>
            <?php if ($approved): ?>
                <span class="bd-revision-outcome bd-outcome--approved"><i class="fa fa-check"></i> Approved</span>
            <?php elseif ($returned): ?>
                <span class="bd-revision-outcome bd-outcome--returned"><i class="fa fa-rotate-left"></i> Returned</span>
            <?php else: ?>
                <span class="bd-revision-outcome bd-outcome--pending"><i class="fa fa-clock"></i> Pending</span>
            <?php endif; ?>
        </div>
        <div class="bd-revision-row">
            <span class="bd-revision-lbl">Submitted by</span>
            <span><?= htmlspecialchars($submitted['performer_name'] ?? '—') ?></span>
        </div>
        <?php if ($submitted['gross_total']): ?>
        <div class="bd-revision-row">
            <span class="bd-revision-lbl">Submitted totals</span>
            <span><?= (int)$submitted['emp_count'] ?> employees &nbsp;·&nbsp;
                  Gross <?= peso($submitted['gross_total']) ?> &nbsp;·&nbsp;
                  Net <?= peso($submitted['net_total']) ?></span>
        </div>
        <?php endif; ?>
        <?php if ($returned): ?>
        <div class="bd-revision-row bd-revision-row--rejection">
            <span class="bd-revision-lbl">Rejected by</span>
            <span><?= htmlspecialchars($returned['performer_name'] ?? '—') ?> on <?= fmtDt($returned['created_at']) ?></span>
        </div>
        <?php if ($returned['remarks']): ?>
        <div class="bd-revision-remarks">
            <i class="fa fa-comment-dots"></i>
            "<?= htmlspecialchars($returned['remarks']) ?>"
        </div>
        <?php endif; ?>
        <?php endif; ?>
        <?php if ($approved): ?>
        <div class="bd-revision-row bd-revision-row--approved">
            <span class="bd-revision-lbl">Approved by</span>
            <span><?= htmlspecialchars($approved['performer_name'] ?? '—') ?> on <?= fmtDt($approved['created_at']) ?></span>
        </div>
        <?php endif; ?>
    </div>
    <?php endforeach; ?>
    <?php endif; ?>
</div>

<!-- ══════════════════════════════════════════════════════════════════════════ -->
<!-- TAB: PAYSLIPS -->
<!-- ══════════════════════════════════════════════════════════════════════════ -->
<div class="bd-tab-content" id="tab-payslips">
    <?php if (empty($records)): ?>
        <p style="color:#9ca3af;padding:32px 0;text-align:center;">No payroll records found.</p>
    <?php else: ?>
    <div class="bd-payslip-grid">
        <?php foreach ($records as $r): ?>
        <?php
            $allowanceJson = htmlspecialchars(json_encode($allowanceDetails[$r['payroll_id']] ?? []), ENT_QUOTES, 'UTF-8');
            $deductionJson = htmlspecialchars(json_encode($deductionDetails[$r['payroll_id']] ?? []), ENT_QUOTES, 'UTF-8');
        ?>
        <div class="bd-payslip-card">
            <div class="bd-ps-avatar"><?= strtoupper(substr(trim($r['employee_name']), 0, 2)) ?></div>
            <div class="bd-ps-info">
                <strong><?= htmlspecialchars(trim($r['employee_name'])) ?></strong>
                <span><?= htmlspecialchars($r['position_name'] ?? '') ?></span>
            </div>
            <div class="bd-ps-amounts">
                <div><small>Gross</small><strong><?= peso($r['gross_pay']) ?></strong></div>
                <div><small>Net Pay</small><strong style="color:#0f766e"><?= peso($r['net_pay']) ?></strong></div>
            </div>
            <button class="btn-icon" onclick="openPayslip(this)"
                data-name="<?= htmlspecialchars(trim($r['employee_name'])) ?>"
                data-position="<?= htmlspecialchars($r['position_name'] ?? '') ?>"
                data-dept="<?= htmlspecialchars($r['department_name'] ?? '') ?>"
                data-empid="<?= $r['employee_id'] ?>"
                data-basic="<?= $r['basic_pay'] ?>" data-assign="<?= $r['addl_assign'] ?>"
                data-rice="<?= $r['rice_sub'] ?>" data-laundry="<?= $r['laundry'] ?>"
                data-peraa-premium="<?= $r['peraa_p'] ?>" data-peraa-loan="<?= $r['peraa_l'] ?>"
                data-hdmf-premium="<?= $r['hdmf_p'] ?>" data-hdmf-loan="<?= $r['hdmf_l'] ?>"
                data-philhealth="<?= $r['philhealth'] ?>"
                data-sss-premium="<?= $r['sss_p'] ?>" data-sss-loan="<?= $r['sss_l'] ?>"
                data-period-label="<?= htmlspecialchars($period['period_name']) ?>"
                data-allowances="<?= $allowanceJson ?>"
                data-deductions="<?= $deductionJson ?>"
                data-gross="<?= $r['gross_pay'] ?>"
                data-total-deductions="<?= $r['total_deductions'] ?>"
                data-net="<?= $r['net_pay'] ?>">
                <i class="fa fa-eye"></i>
            </button>
        </div>
        <?php endforeach; ?>
    </div>
    <?php endif; ?>
</div>

</div><!-- .main-content -->
</div><!-- .main -->
</div><!-- .layout -->

<?php include __DIR__ . '/modals/view-modal.php'; ?>
<?php if ($canEdit): ?>
<?php include __DIR__ . '/modals/edit-modal.php'; ?>
<?php endif; ?>
<?php if ($userIsPrincipal && $period['status'] === 'PROCESSING'): ?>
<?php include __DIR__ . '/../principal/payroll-approval/modals/modal-approve.php'; ?>
<?php include __DIR__ . '/../principal/payroll-approval/modals/modal-reject.php'; ?>
<?php endif; ?>

<!-- Payroll confirmation modal (admin workflow actions) -->
<?php if ($userIsAdmin): ?>
<div id="payrollConfirmModal" style="display:none;position:fixed;inset:0;background:rgba(0,0,0,0.4);z-index:1000;align-items:center;justify-content:center;">
  <div style="background:#fff;border-radius:14px;padding:24px;width:420px;max-width:95%;box-shadow:0 16px 48px rgba(0,0,0,.12);">
    <h3 id="pcm-title" style="font-size:16px;font-weight:700;margin:0 0 8px;"></h3>
    <p id="pcm-desc" style="font-size:13px;color:#64748b;margin:0 0 14px;line-height:1.6;"></p>
    <div style="display:flex;justify-content:flex-end;gap:10px;">
      <button class="btn-outline" style="padding:9px 16px;font-size:13px;" onclick="closePayrollConfirm()">Cancel</button>
      <button id="pcm-confirm-btn" class="btn-primary" style="padding:9px 20px;font-size:13px;" onclick="executePayrollAction()">Confirm</button>
    </div>
  </div>
</div>
<?php endif; ?>

<script>
const BASE_URL = '<?= BASE_URL ?>';

// ── Tab switching ─────────────────────────────────────────────────────────────
document.querySelectorAll('.bd-tab').forEach(btn => {
    btn.addEventListener('click', function () {
        document.querySelectorAll('.bd-tab,.bd-tab-content').forEach(el => el.classList.remove('active'));
        this.classList.add('active');
        document.getElementById('tab-' + this.dataset.tab)?.classList.add('active');
        // Highlight active tab in URL (no reload)
        history.replaceState(null,'', location.pathname + '?period_id=<?= $periodId ?>&tab=' + this.dataset.tab);
    });
});
// Restore tab from URL
const urlTab = new URLSearchParams(location.search).get('tab');
if (urlTab) document.querySelector(`.bd-tab[data-tab="${urlTab}"]`)?.click();

// ── Admin payroll action modal ────────────────────────────────────────────────
const _pcmConfig = {
    submit:  { title:'Submit for Principal Approval', desc:'Lock payroll from editing and send to Principal for review.', btnLabel:'Submit', btnColor:'#0f766e', url: BASE_URL+'actions/payroll-save.php', isSubmit:true },
    release: { title:'Release Payroll', desc:'Mark as released — salaries have been distributed to employees.', btnLabel:'Release', btnColor:'#7c3aed', url: BASE_URL+'actions/payroll-approve.php', isSubmit:false }
};
let _pcmAction = null, _pcmPeriodId = null;

function confirmPayrollAction(action, periodId) {
    const cfg = _pcmConfig[action]; if (!cfg) return;
    _pcmAction = action; _pcmPeriodId = periodId;
    document.getElementById('pcm-title').textContent = cfg.title;
    document.getElementById('pcm-desc').textContent  = cfg.desc;
    const btn = document.getElementById('pcm-confirm-btn');
    btn.textContent = cfg.btnLabel; btn.style.background = cfg.btnColor; btn.disabled = false;
    document.getElementById('payrollConfirmModal').style.display = 'flex';
}
function closePayrollConfirm() { document.getElementById('payrollConfirmModal').style.display = 'none'; }

async function executePayrollAction() {
    const cfg = _pcmConfig[_pcmAction]; if (!cfg || !_pcmPeriodId) return;
    const btn = document.getElementById('pcm-confirm-btn');
    btn.disabled = true; btn.textContent = 'Processing…';
    const fd = new FormData();
    fd.append('period_id', _pcmPeriodId);
    if (!cfg.isSubmit) fd.append('action', _pcmAction);
    try {
        const res  = await fetch(cfg.url, { method:'POST', body: fd });
        const data = await res.json();
        closePayrollConfirm();
        if (data.success) { setTimeout(() => location.reload(), 1200); }
        else { alert(data.message); btn.disabled = false; btn.textContent = cfg.btnLabel; }
    } catch { alert('Network error.'); btn.disabled = false; }
}
</script>
<?php if ($userIsPrincipal && $period['status'] === 'PROCESSING'): ?>
<script src="<?= BASE_URL ?>assets/js/principal-payroll-approval.js?v=<?= time() ?>"></script>
<?php endif; ?>
<script src="<?= BASE_URL ?>assets/js/payroll.js?v=<?= time() ?>"></script>
<?php include __DIR__ . '/../../includes/footer.php'; ?>
