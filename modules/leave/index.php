<?php
require_once __DIR__ . '/../../config/config.php';

$pageTitle = 'Leave Management';

// ── Migration guard ──────────────────────────────────────────────────────────
$hasMig016 = (bool)$pdo->query("SHOW COLUMNS FROM `leave_requests` LIKE 'workflow_status'")->fetch();

// ── Current tab ─────────────────────────────────────────────────────────────
$tab = $_GET['tab'] ?? 'pending_review';
$validTabs = ['pending_review','awaiting','approved','rejected','history'];
if (!in_array($tab, $validTabs)) $tab = 'pending_review';

// ── Filters ──────────────────────────────────────────────────────────────────
$fDept   = (int)($_GET['dept']   ?? 0);
$fType   = (int)($_GET['type']   ?? 0);
$fMonth  = $_GET['month']  ?? '';
$fSY     = (int)($_GET['sy']    ?? 0);
$fSearch = trim($_GET['search'] ?? '');

// ── Helper: build common WHERE conditions ────────────────────────────────────
function buildFilters(bool $hasMig016, int $fDept, int $fType, string $fMonth, int $fSY, string $fSearch, array &$params): string {
    $where = [];
    if ($fDept)   { $where[] = 'e.department_id = ?'; $params[] = $fDept; }
    if ($fType)   { $where[] = 'lr.leave_type_id = ?'; $params[] = $fType; }
    if ($fMonth)  { $where[] = 'DATE_FORMAT(lr.created_at, \'%Y-%m\') = ?'; $params[] = $fMonth; }
    if ($fSY)     { $where[] = 'lr.school_year_id = ?'; $params[] = $fSY; }
    if ($fSearch) {
        $where[] = '(CONCAT(e.first_name,\' \',e.last_name) LIKE ? OR e.employee_no LIKE ?)';
        $params[] = '%' . $fSearch . '%';
        $params[] = '%' . $fSearch . '%';
    }
    return $where ? ' AND ' . implode(' AND ', $where) : '';
}

$today   = date('Y-m-d');
$thisM   = date('Y-m');
$nextM   = date('Y-m', strtotime('+1 month'));

// ── Dashboard stats ──────────────────────────────────────────────────────────
if ($hasMig016) {
    $sPendingReview = $pdo->query("SELECT COUNT(*) FROM leave_requests WHERE workflow_status='PENDING_REVIEW'")->fetchColumn();
    $sAwaiting      = $pdo->query("SELECT COUNT(*) FROM leave_requests WHERE workflow_status='FORWARDED' AND status='PENDING'")->fetchColumn();
} else {
    $sPendingReview = $pdo->query("SELECT COUNT(*) FROM leave_requests WHERE status='PENDING'")->fetchColumn();
    $sAwaiting      = 0;
}

$sApprovedMonth = $pdo->query("
    SELECT COUNT(*) FROM leave_requests
    WHERE status = 'APPROVED' AND DATE_FORMAT(updated_at,'%Y-%m') = '{$thisM}'
")->fetchColumn();

$sRejectedMonth = $pdo->query("
    SELECT COUNT(*) FROM leave_requests
    WHERE status = 'REJECTED' AND DATE_FORMAT(updated_at,'%Y-%m') = '{$thisM}'
")->fetchColumn();

$sOnLeaveThisMonth = $pdo->query("
    SELECT COUNT(DISTINCT lr.employee_id)
    FROM leave_requests lr
    JOIN leave_request_dates lrd ON lr.leave_id = lrd.leave_id
    WHERE lrd.status = 'APPROVED' AND DATE_FORMAT(lrd.leave_date,'%Y-%m') = '{$thisM}'
")->fetchColumn();

$sOnLeaveNextMonth = $pdo->query("
    SELECT COUNT(DISTINCT lr.employee_id)
    FROM leave_requests lr
    JOIN leave_request_dates lrd ON lr.leave_id = lrd.leave_id
    WHERE lrd.status = 'APPROVED' AND DATE_FORMAT(lrd.leave_date,'%Y-%m') = '{$nextM}'
")->fetchColumn();

// ── Tab counts for badges ────────────────────────────────────────────────────
$cPendingReview = (int)$sPendingReview;
$cAwaiting      = (int)$sAwaiting;

// ── Filter options ────────────────────────────────────────────────────────────
$departments = $pdo->query("SELECT department_id, department_name FROM departments ORDER BY department_name")->fetchAll();
$leaveTypes  = $pdo->query("SELECT leave_type_id, leave_name FROM leave_types ORDER BY leave_name")->fetchAll();
$schoolYears = $pdo->query("SELECT school_year_id, year_name FROM school_years ORDER BY year_name DESC")->fetchAll();

// ── Tab-specific query ────────────────────────────────────────────────────────
$workflowSel = $hasMig016
    ? ', lr.workflow_status, lr.admin_note, lr.forwarded_at, lr.is_backdated'
    : ", 'PENDING_REVIEW' AS workflow_status, NULL AS admin_note, NULL AS forwarded_at, 0 AS is_backdated";

$baseSelect = "
    SELECT
        lr.leave_id,
        lr.employee_id,
        CONCAT(e.first_name,' ',e.last_name)  AS employee_name,
        e.employee_no,
        d.department_name,
        p.position_name,
        lt.leave_name,
        lr.leave_type_id,
        lr.reason,
        lr.start_date, lr.end_date, lr.total_days,
        lr.status,
        lr.created_at,
        lr.approved_at,
        lr.remarks
        {$workflowSel},
        CONCAT(LEFT(e.first_name,1),LEFT(e.last_name,1)) AS initials
    FROM leave_requests lr
    JOIN employees  e  ON lr.employee_id  = e.employee_id
    JOIN departments d ON e.department_id = d.department_id
    JOIN positions   p ON e.position_id   = p.position_id
    JOIN leave_types lt ON lr.leave_type_id = lt.leave_type_id
";

$records = [];
$params  = [];

if ($tab === 'pending_review') {
    $wClause = $hasMig016 ? "lr.workflow_status = 'PENDING_REVIEW'" : "lr.status = 'PENDING'";
    $extra   = buildFilters($hasMig016, $fDept, $fType, $fMonth, $fSY, $fSearch, $params);
    $sql     = $baseSelect . " WHERE ({$wClause}){$extra} ORDER BY lr.created_at ASC";

} elseif ($tab === 'awaiting') {
    $wClause = $hasMig016
        ? "lr.workflow_status = 'FORWARDED' AND lr.status = 'PENDING'"
        : "lr.status = 'PENDING'";
    $extra   = buildFilters($hasMig016, $fDept, $fType, $fMonth, $fSY, $fSearch, $params);
    $sql     = $baseSelect . " WHERE ({$wClause}){$extra} ORDER BY lr.created_at ASC";

} elseif ($tab === 'approved') {
    $extra = buildFilters($hasMig016, $fDept, $fType, $fMonth, $fSY, $fSearch, $params);
    $sql   = $baseSelect . " WHERE lr.status = 'APPROVED'{$extra} ORDER BY lr.updated_at DESC";

} elseif ($tab === 'rejected') {
    $extra = buildFilters($hasMig016, $fDept, $fType, $fMonth, $fSY, $fSearch, $params);
    $sql   = $baseSelect . " WHERE lr.status = 'REJECTED'{$extra} ORDER BY lr.updated_at DESC";

} else { // history
    $extra = buildFilters($hasMig016, $fDept, $fType, $fMonth, $fSY, $fSearch, $params);
    $sql   = $baseSelect . " WHERE 1=1{$extra} ORDER BY lr.created_at DESC LIMIT 200";
}

$stmt = $pdo->prepare($sql);
$stmt->execute($params);
$records = $stmt->fetchAll();

// ── Approved-date counts for each request (for display) ──────────────────────
$approvedCounts = [];
if ($records) {
    $ids    = implode(',', array_map('intval', array_column($records, 'leave_id')));
    $acRows = $pdo->query("
        SELECT leave_id, COUNT(*) AS approved_cnt, SUM(status='PENDING') AS pending_cnt, SUM(status='REJECTED') AS rejected_cnt
        FROM leave_request_dates WHERE leave_id IN ({$ids}) GROUP BY leave_id
    ")->fetchAll();
    foreach ($acRows as $r) {
        $approvedCounts[$r['leave_id']] = $r;
    }
}

$pageTitle     = 'Leave Management';
$extraCSS      = [BASE_URL . 'assets/css/leave.css'];
$loadBootstrap = true;
require_once __DIR__ . '/../../includes/head.php';
?>
<body>
<div class="layout">
<?php include __DIR__ . '/../../includes/sidebar.php'; ?>
<div class="main-wrapper">
<?php include __DIR__ . '/../../includes/header.php'; ?>

<main class="main-content">

    <!-- PAGE HEADER -->
    <div class="page-header">
        <div>
            <h1 class="page-title">Leave Management</h1>
            <p class="page-subtitle">Review, forward, and record leave requests across all staff.</p>
        </div>
    </div>

    <!-- STAT CARDS -->
    <div class="lm-stat-row">
        <a href="?tab=pending_review" class="lm-stat-card lm-stat-card--blue <?= $tab==='pending_review'?'lm-stat-card--active':'' ?>">
            <div class="lm-stat-icon"><i class="fa fa-inbox"></i></div>
            <div>
                <div class="lm-stat-label">Pending Review</div>
                <div class="lm-stat-value"><?= (int)$sPendingReview ?></div>
                <div class="lm-stat-sub">Awaiting admin review</div>
            </div>
        </a>
        <a href="?tab=awaiting" class="lm-stat-card lm-stat-card--amber <?= $tab==='awaiting'?'lm-stat-card--active':'' ?>">
            <div class="lm-stat-icon"><i class="fa fa-clock"></i></div>
            <div>
                <div class="lm-stat-label">Awaiting Principal</div>
                <div class="lm-stat-value"><?= (int)$sAwaiting ?></div>
                <div class="lm-stat-sub">Forwarded, pending decision</div>
            </div>
        </a>
        <a href="?tab=approved" class="lm-stat-card lm-stat-card--green <?= $tab==='approved'?'lm-stat-card--active':'' ?>">
            <div class="lm-stat-icon"><i class="fa fa-circle-check"></i></div>
            <div>
                <div class="lm-stat-label">Approved This Month</div>
                <div class="lm-stat-value"><?= (int)$sApprovedMonth ?></div>
                <div class="lm-stat-sub"><?= date('F Y') ?></div>
            </div>
        </a>
        <a href="?tab=rejected" class="lm-stat-card lm-stat-card--red <?= $tab==='rejected'?'lm-stat-card--active':'' ?>">
            <div class="lm-stat-icon"><i class="fa fa-circle-xmark"></i></div>
            <div>
                <div class="lm-stat-label">Rejected This Month</div>
                <div class="lm-stat-value"><?= (int)$sRejectedMonth ?></div>
                <div class="lm-stat-sub"><?= date('F Y') ?></div>
            </div>
        </a>
        <div class="lm-stat-card lm-stat-card--teal">
            <div class="lm-stat-icon"><i class="fa fa-user-clock"></i></div>
            <div>
                <div class="lm-stat-label">On Leave This Month</div>
                <div class="lm-stat-value"><?= (int)$sOnLeaveThisMonth ?></div>
                <div class="lm-stat-sub">Staff — <?= date('F Y') ?></div>
            </div>
        </div>
        <div class="lm-stat-card lm-stat-card--purple">
            <div class="lm-stat-icon"><i class="fa fa-calendar-days"></i></div>
            <div>
                <div class="lm-stat-label">On Leave Next Month</div>
                <div class="lm-stat-value"><?= (int)$sOnLeaveNextMonth ?></div>
                <div class="lm-stat-sub">Staff — <?= date('F Y', strtotime('+1 month')) ?></div>
            </div>
        </div>
    </div>

    <!-- TABS -->
    <div class="lm-tabs">
        <a href="?tab=pending_review<?= $fDept?"&dept={$fDept}":'' ?>" class="lm-tab <?= $tab==='pending_review'?'lm-tab--active':'' ?>">
            Pending Review
            <?php if ($cPendingReview > 0): ?><span class="lm-tab-badge lm-tab-badge--amber"><?= $cPendingReview ?></span><?php endif; ?>
        </a>
        <a href="?tab=awaiting<?= $fDept?"&dept={$fDept}":'' ?>" class="lm-tab <?= $tab==='awaiting'?'lm-tab--active':'' ?>">
            Awaiting Principal
            <?php if ($cAwaiting > 0): ?><span class="lm-tab-badge lm-tab-badge--blue"><?= $cAwaiting ?></span><?php endif; ?>
        </a>
        <a href="?tab=approved<?= $fDept?"&dept={$fDept}":'' ?>" class="lm-tab <?= $tab==='approved'?'lm-tab--active':'' ?>">Approved</a>
        <a href="?tab=rejected<?= $fDept?"&dept={$fDept}":'' ?>" class="lm-tab <?= $tab==='rejected'?'lm-tab--active':'' ?>">Rejected</a>
        <a href="?tab=history" class="lm-tab <?= $tab==='history'?'lm-tab--active':'' ?>">
            <i class="fa fa-clock-rotate-left"></i> History
        </a>
    </div>

    <!-- FILTERS -->
    <form class="lm-filter-bar" method="GET" action="">
        <input type="hidden" name="tab" value="<?= htmlspecialchars($tab) ?>">
        <input type="text" name="search" class="lm-filter-input" placeholder="Search name or employee no."
               value="<?= htmlspecialchars($fSearch) ?>">
        <select name="dept" class="lm-filter-select">
            <option value="">All Departments</option>
            <?php foreach ($departments as $dep): ?>
                <option value="<?= $dep['department_id'] ?>" <?= $fDept==$dep['department_id']?'selected':'' ?>>
                    <?= htmlspecialchars($dep['department_name']) ?>
                </option>
            <?php endforeach; ?>
        </select>
        <select name="type" class="lm-filter-select">
            <option value="">All Leave Types</option>
            <?php foreach ($leaveTypes as $lt): ?>
                <option value="<?= $lt['leave_type_id'] ?>" <?= $fType==$lt['leave_type_id']?'selected':'' ?>>
                    <?= htmlspecialchars($lt['leave_name']) ?>
                </option>
            <?php endforeach; ?>
        </select>
        <input type="month" name="month" class="lm-filter-input" value="<?= htmlspecialchars($fMonth) ?>" title="Filter by filing month">
        <?php if (!empty($schoolYears)): ?>
        <select name="sy" class="lm-filter-select">
            <option value="">All School Years</option>
            <?php foreach ($schoolYears as $sy): ?>
                <option value="<?= $sy['school_year_id'] ?>" <?= $fSY==$sy['school_year_id']?'selected':'' ?>>
                    <?= htmlspecialchars($sy['year_name']) ?>
                </option>
            <?php endforeach; ?>
        </select>
        <?php endif; ?>
        <button type="submit" class="lm-filter-btn"><i class="fa fa-filter"></i> Filter</button>
        <a href="?tab=<?= $tab ?>" class="lm-filter-reset">Clear</a>
    </form>

    <!-- LEAVE TABLE -->
    <div class="lm-table-card">
        <div class="lm-table-header">
            <div>
                <div class="lm-table-title">
                    <?php
                    $tabLabels = ['pending_review'=>'Pending Admin Review','awaiting'=>'Awaiting Principal Decision','approved'=>'Approved Requests','rejected'=>'Rejected Requests','history'=>'Leave History'];
                    echo htmlspecialchars($tabLabels[$tab] ?? 'Leave Records');
                    ?>
                </div>
                <div class="lm-table-sub"><?= count($records) ?> record<?= count($records)!==1?'s':'' ?></div>
            </div>
        </div>

        <?php if (empty($records)): ?>
        <div class="lm-empty">
            <i class="fa fa-calendar-xmark"></i>
            <p>No leave records found<?= ($fSearch||$fDept||$fType||$fMonth) ? ' matching the filters' : '' ?>.</p>
        </div>
        <?php else: ?>
        <div class="lm-table-wrap">
        <table class="lm-table">
            <thead>
                <tr>
                    <th style="width:200px;">Employee</th>
                    <th>Leave Type</th>
                    <th>Filed</th>
                    <th>Dates</th>
                    <th class="text-center">Days</th>
                    <th class="text-center">Status</th>
                    <?php if ($hasMig016): ?><th class="text-center">Workflow</th><?php endif; ?>
                    <th>Actions</th>
                </tr>
            </thead>
            <tbody>
            <?php foreach ($records as $r):
                $wfStatus   = $r['workflow_status'] ?? 'PENDING_REVIEW';
                $dateFrom   = date('M d', strtotime($r['start_date']));
                $dateTo     = date('M d, Y', strtotime($r['end_date']));
                $dateStr    = ($r['start_date'] === $r['end_date']) ? date('M d, Y', strtotime($r['start_date'])) : "{$dateFrom}–{$dateTo}";
                $ac         = $approvedCounts[$r['leave_id']] ?? [];
                $isBackdated = !empty($r['is_backdated']);

                $statusBadge = match(strtoupper($r['status'])) {
                    'APPROVED' => ['lm-badge--approved','Approved'],
                    'REJECTED' => ['lm-badge--rejected','Rejected'],
                    default    => ['lm-badge--pending','Pending'],
                };
                $wfBadge = match($wfStatus) {
                    'FORWARDED' => ['lm-wf--forwarded','Forwarded'],
                    'RECORDED'  => ['lm-wf--recorded','Recorded'],
                    default     => ['lm-wf--review','Pending Review'],
                };
            ?>
            <tr class="lm-row">
                <td>
                    <div class="lm-emp-cell">
                        <div class="lm-avatar"><?= htmlspecialchars($r['initials']) ?></div>
                        <div class="lm-emp-info">
                            <div class="lm-emp-name"><?= htmlspecialchars($r['employee_name']) ?></div>
                            <div class="lm-emp-sub"><?= htmlspecialchars($r['employee_no'] ?? '—') ?></div>
                            <div class="lm-emp-sub"><?= htmlspecialchars($r['department_name']) ?></div>
                            <div class="lm-emp-sub lm-emp-pos"><?= htmlspecialchars($r['position_name']) ?></div>
                        </div>
                    </div>
                </td>
                <td>
                    <?= htmlspecialchars($r['leave_name']) ?>
                    <?php if ($isBackdated): ?>
                        <span class="lm-badge lm-badge--backdated" title="Backdated filing">Backdated</span>
                    <?php endif; ?>
                </td>
                <td style="white-space:nowrap;color:#64748b;font-size:13px;"><?= date('M d, Y', strtotime($r['created_at'])) ?></td>
                <td style="white-space:nowrap;">
                    <?= htmlspecialchars($dateStr) ?>
                    <?php if (!empty($ac['pending_cnt']) && $ac['pending_cnt'] > 0): ?>
                        <span class="lm-date-pill lm-date-pill--pending"><?= $ac['pending_cnt'] ?> pending</span>
                    <?php endif; ?>
                </td>
                <td class="text-center font-bold"><?= (int)$r['total_days'] ?></td>
                <td class="text-center">
                    <span class="lm-badge <?= $statusBadge[0] ?>"><?= $statusBadge[1] ?></span>
                </td>
                <?php if ($hasMig016): ?>
                <td class="text-center">
                    <span class="lm-wf-badge <?= $wfBadge[0] ?>"><?= $wfBadge[1] ?></span>
                </td>
                <?php endif; ?>
                <td>
                    <div class="lm-action-group">
                        <button class="lm-btn lm-btn--view" onclick="openViewModal(<?= $r['leave_id'] ?>)">
                            <i class="fa fa-eye"></i> View
                        </button>
                        <?php if ($wfStatus === 'PENDING_REVIEW'): ?>
                            <button class="lm-btn lm-btn--forward"
                                    onclick="openForwardModal(<?= $r['leave_id'] ?>, <?= htmlspecialchars(json_encode($r['employee_name'])) ?>)">
                                <i class="fa fa-paper-plane"></i> Forward
                            </button>
                        <?php elseif ($wfStatus === 'FORWARDED' && strtoupper($r['status']) !== 'PENDING'): ?>
                            <?php
                            // All dates decided — admin can now record result
                            $pendingLeft = (int)($ac['pending_cnt'] ?? 0);
                            if ($pendingLeft === 0):
                            ?>
                            <button class="lm-btn lm-btn--record"
                                    onclick="doRecord(<?= $r['leave_id'] ?>)">
                                <i class="fa fa-check-double"></i> Record
                            </button>
                            <?php endif; ?>
                        <?php elseif ($wfStatus === 'FORWARDED' && !empty($ac) && ($ac['pending_cnt'] ?? 0) == 0): ?>
                            <button class="lm-btn lm-btn--record"
                                    onclick="doRecord(<?= $r['leave_id'] ?>)">
                                <i class="fa fa-check-double"></i> Record
                            </button>
                        <?php endif; ?>
                    </div>
                </td>
            </tr>
            <?php endforeach; ?>
            </tbody>
        </table>
        </div>
        <?php endif; ?>
    </div>

</main>
</div>
</div>

<!-- MODALS -->
<?php include __DIR__ . '/modals/view-leave.php'; ?>
<?php include __DIR__ . '/modals/forward-modal.php'; ?>
<?php include __DIR__ . '/modals/confirm-action.php'; ?>

<script>
const BASE_URL   = '<?= BASE_URL ?>';
const HAS_MIG016 = <?= $hasMig016 ? 'true' : 'false' ?>;
</script>
<script src="<?= BASE_URL ?>assets/js/leave.js"></script>
<?php include __DIR__ . '/../../includes/footer.php'; ?>
