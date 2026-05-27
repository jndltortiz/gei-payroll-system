<?php
/**
 * modules/payroll/archive.php
 * Payroll Archive — Released payrolls with search, sort, and year filter.
 * Accessible by Admin and Principal.
 */
require_once __DIR__ . '/../../config/config.php';
require_once __DIR__ . '/../../includes/auth.php';
requireLogin();

$userIsAdmin     = isAdmin();
$userIsPrincipal = isPrincipalRole();
if (!$userIsAdmin && !$userIsPrincipal) {
    header('Location: ' . BASE_URL); exit;
}

// ── Filters ───────────────────────────────────────────────────────────────────
$availYears = $pdo->query("
    SELECT DISTINCT YEAR(pay_period_start) AS yr
    FROM payroll_periods WHERE status = 'RELEASED'
    ORDER BY yr DESC
")->fetchAll(PDO::FETCH_COLUMN);

$filterYear = isset($_GET['year']) && in_array((int)$_GET['year'], $availYears)
    ? (int)$_GET['year'] : null;

$search = trim($_GET['q'] ?? '');
$sort   = $_GET['sort'] ?? 'date-desc';

$allowedSorts = ['date-desc','date-asc','net-desc','net-asc','emp-desc','name-asc','name-desc'];
if (!in_array($sort, $allowedSorts)) $sort = 'date-desc';

$orderMap = [
    'date-desc' => 'pp.pay_period_start DESC',
    'date-asc'  => 'pp.pay_period_start ASC',
    'net-desc'  => 'total_net DESC',
    'net-asc'   => 'total_net ASC',
    'emp-desc'  => 'emp_count DESC',
    'name-desc' => 'pp.period_name DESC',
    'name-asc'  => 'pp.period_name ASC',
];
$orderBy = $orderMap[$sort];

// ── Archive query ─────────────────────────────────────────────────────────────
$where  = ["pp.status = 'RELEASED'"];
$params = [];

if ($filterYear) {
    $where[]  = 'YEAR(pp.pay_period_start) = ?';
    $params[] = $filterYear;
}
if ($search !== '') {
    $where[]  = 'pp.period_name LIKE ?';
    $params[] = '%' . $search . '%';
}

$whereSQL = implode(' AND ', $where);
$stmt = $pdo->prepare("
    SELECT pp.period_id, pp.payroll_number, pp.period_name, pp.pay_period_start, pp.pay_period_end, pp.pay_date,
           pp.status, pp.created_at,
           COUNT(pr.payroll_id)     AS emp_count,
           SUM(pr.gross_pay)        AS total_gross,
           SUM(pr.total_deductions) AS total_deductions,
           SUM(pr.net_pay)          AS total_net,
           (SELECT wl.created_at FROM payroll_workflow_log wl
            WHERE wl.period_id = pp.period_id AND wl.event_type = 'RELEASED'
            ORDER BY wl.created_at DESC LIMIT 1) AS released_at,
           (SELECT wl.performer_name FROM payroll_workflow_log wl
            WHERE wl.period_id = pp.period_id AND wl.event_type = 'RELEASED'
            ORDER BY wl.created_at DESC LIMIT 1) AS released_by
    FROM payroll_periods pp
    LEFT JOIN payroll_records pr ON pp.period_id = pr.period_id
    WHERE $whereSQL
    GROUP BY pp.period_id, pp.payroll_number, pp.period_name, pp.pay_period_start, pp.pay_period_end,
             pp.pay_date, pp.status, pp.created_at
    ORDER BY $orderBy
");
$stmt->execute($params);
$periods = $stmt->fetchAll(PDO::FETCH_ASSOC);

// ── Year-to-date or all-time totals (for filtered result) ─────────────────────
$arcGross = 0; $arcNet = 0; $arcEmp = 0;
foreach ($periods as $p) {
    $arcGross += (float)$p['total_gross'];
    $arcNet   += (float)$p['total_net'];
    $arcEmp    = max($arcEmp, (int)$p['emp_count']);
}

$pageTitle = 'Payroll Archive';
$extraCSS  = [BASE_URL . 'assets/css/payroll.css'];
if ($userIsPrincipal) $extraCSS[] = BASE_URL . 'assets/css/principal.css';
require_once __DIR__ . '/../../includes/head.php';

function fmtD2($d) { return $d ? date('M j, Y', strtotime($d)) : '—'; }
function peso2($n) { return '₱' . number_format((float)$n, 2); }
?>
<body>
<div class="layout">
<?php
if ($userIsPrincipal) include __DIR__ . '/../../includes/principal-sidebar.php';
else                  include __DIR__ . '/../../includes/sidebar.php';
?>
<div class="main">
<?php include __DIR__ . '/../../includes/header.php'; ?>
<div class="main-content">

<!-- ── Page header ── -->
<div style="display:flex;align-items:flex-start;justify-content:space-between;flex-wrap:wrap;gap:12px;margin-bottom:20px;">
    <div>
        <h1 style="font-size:18px;font-weight:700;color:#0f172a;margin:0 0 2px;">
            Payroll Archive
        </h1>
        <p style="font-size:12px;color:#94a3b8;margin:0;">All released payroll periods — searchable and sortable.</p>
    </div>
    <!-- Summary pills -->
    <div style="display:flex;gap:10px;flex-wrap:wrap;">
        <div style="background:#f0fdf4;border:1px solid #bbf7d0;border-radius:8px;padding:8px 14px;font-size:12px;">
            <div style="font-weight:700;color:#065f46;font-size:15px;"><?= count($periods) ?></div>
            <div style="color:#059669;">Periods</div>
        </div>
        <div style="background:#eff6ff;border:1px solid #bfdbfe;border-radius:8px;padding:8px 14px;font-size:12px;">
            <div style="font-weight:700;color:#1e40af;font-size:15px;"><?= peso2($arcGross) ?></div>
            <div style="color:#2563eb;">Total Gross</div>
        </div>
        <div style="background:#f5f3ff;border:1px solid #ddd6fe;border-radius:8px;padding:8px 14px;font-size:12px;">
            <div style="font-weight:700;color:#5b21b6;font-size:15px;"><?= peso2($arcNet) ?></div>
            <div style="color:#7c3aed;">Total Net</div>
        </div>
    </div>
</div>

<!-- ── Search + filter bar ── -->
<form method="get" style="display:flex;gap:8px;flex-wrap:wrap;align-items:center;margin-bottom:16px;">
    <input type="text" name="q" value="<?= htmlspecialchars($search) ?>"
           placeholder="Search period name…"
           style="flex:1;min-width:200px;max-width:320px;padding:8px 12px;border-radius:8px;border:1px solid var(--border);font-size:13px;background:#fff;">
    <select name="year" style="padding:8px 12px;border-radius:8px;border:1px solid var(--border);font-size:13px;background:#fff;">
        <option value="">All Years</option>
        <?php foreach ($availYears as $yr): ?>
        <option value="<?= $yr ?>" <?= $filterYear == $yr ? 'selected' : '' ?>><?= $yr ?></option>
        <?php endforeach; ?>
    </select>
    <select name="sort" style="padding:8px 12px;border-radius:8px;border:1px solid var(--border);font-size:13px;background:#fff;">
        <option value="date-desc" <?= $sort==='date-desc'  ? 'selected' : '' ?>>Date (Newest First)</option>
        <option value="date-asc"  <?= $sort==='date-asc'   ? 'selected' : '' ?>>Date (Oldest First)</option>
        <option value="net-desc"  <?= $sort==='net-desc'   ? 'selected' : '' ?>>Net Pay (High→Low)</option>
        <option value="net-asc"   <?= $sort==='net-asc'    ? 'selected' : '' ?>>Net Pay (Low→High)</option>
        <option value="emp-desc"  <?= $sort==='emp-desc'   ? 'selected' : '' ?>>Employee Count</option>
        <option value="name-asc"  <?= $sort==='name-asc'   ? 'selected' : '' ?>>Period Name A→Z</option>
    </select>
    <button type="submit"
            style="padding:8px 16px;border-radius:8px;background:#2563eb;color:#fff;border:none;font-size:13px;font-weight:600;cursor:pointer;">
        <i class="fa fa-search"></i> Filter
    </button>
    <?php if ($search || $filterYear): ?>
    <a href="?sort=<?= urlencode($sort) ?>"
       style="padding:8px 12px;border-radius:8px;border:1px solid var(--border);background:#fff;font-size:12px;color:#64748b;text-decoration:none;">
        <i class="fa fa-xmark"></i> Clear
    </a>
    <?php endif; ?>
</form>

<?php if (empty($periods)): ?>
<div style="background:#fff;border:1px solid var(--border);border-radius:10px;padding:60px;text-align:center;color:#94a3b8;">
    <i class="fa fa-box-open" style="font-size:28px;margin-bottom:10px;display:block;opacity:0.4;"></i>
    <p style="font-size:13px;margin:0;">No released payroll periods found<?= $search ? " matching &ldquo;".htmlspecialchars($search)."&rdquo;" : ($filterYear ? " for $filterYear" : '') ?>.</p>
</div>
<?php else: ?>
<div style="background:#fff;border:1px solid var(--border);border-radius:10px;overflow:hidden;box-shadow:var(--shadow);">
<table style="width:100%;border-collapse:collapse;font-size:13px;">
    <thead>
        <tr style="background:#f8fafc;border-bottom:2px solid var(--border);">
            <th style="padding:12px 16px;text-align:left;font-size:11px;font-weight:700;color:#64748b;text-transform:uppercase;letter-spacing:0.4px;">Payroll #</th>
            <th style="padding:12px 16px;text-align:left;font-size:11px;font-weight:700;color:#64748b;text-transform:uppercase;letter-spacing:0.4px;">Period</th>
            <th style="padding:12px 16px;text-align:center;font-size:11px;font-weight:700;color:#64748b;text-transform:uppercase;letter-spacing:0.4px;">Employees</th>
            <th style="padding:12px 16px;text-align:right;font-size:11px;font-weight:700;color:#64748b;text-transform:uppercase;letter-spacing:0.4px;">Gross Pay</th>
            <th style="padding:12px 16px;text-align:right;font-size:11px;font-weight:700;color:#64748b;text-transform:uppercase;letter-spacing:0.4px;">Deductions</th>
            <th style="padding:12px 16px;text-align:right;font-size:11px;font-weight:700;color:#64748b;text-transform:uppercase;letter-spacing:0.4px;">Net Pay</th>
            <th style="padding:12px 16px;text-align:center;font-size:11px;font-weight:700;color:#64748b;text-transform:uppercase;letter-spacing:0.4px;">Released</th>
            <th style="padding:12px 16px;text-align:center;font-size:11px;font-weight:700;color:#64748b;text-transform:uppercase;letter-spacing:0.4px;">Released By</th>
            <th style="padding:12px 16px;text-align:center;font-size:11px;font-weight:700;color:#64748b;text-transform:uppercase;letter-spacing:0.4px;"></th>
        </tr>
    </thead>
    <tbody>
    <?php foreach ($periods as $i => $p):
        $dateRange = date('M j', strtotime($p['pay_period_start'])) . ' – ' . date('M j, Y', strtotime($p['pay_period_end']));
    ?>
    <tr style="border-bottom:1px solid var(--border);<?= $i % 2 === 1 ? 'background:#fafafa;' : '' ?>"
        onmouseenter="this.style.background='#f0f9ff'"
        onmouseleave="this.style.background='<?= $i % 2 === 1 ? '#fafafa' : '#fff' ?>'">
        <td style="padding:14px 16px;">
            <code style="font-size:11px;color:#64748b;background:#f1f5f9;padding:2px 7px;border-radius:4px;">
                <?= htmlspecialchars($p['payroll_number'] ?? '—') ?>
            </code>
        </td>
        <td style="padding:14px 16px;">
            <div style="font-weight:700;color:#0f172a;"><?= htmlspecialchars($p['period_name']) ?></div>
            <div style="font-size:11px;color:#94a3b8;margin-top:2px;"><?= $dateRange ?></div>
            <div style="font-size:11px;color:#94a3b8;">Pay Date: <?= fmtD2($p['pay_date']) ?></div>
        </td>
        <td style="padding:14px 16px;text-align:center;font-weight:600;color:#374151;"><?= (int)$p['emp_count'] ?></td>
        <td style="padding:14px 16px;text-align:right;font-weight:600;color:#374151;"><?= peso2($p['total_gross']) ?></td>
        <td style="padding:14px 16px;text-align:right;color:#dc2626;font-weight:600;"><?= peso2($p['total_deductions']) ?></td>
        <td style="padding:14px 16px;text-align:right;font-weight:700;color:#0f766e;font-size:14px;"><?= peso2($p['total_net']) ?></td>
        <td style="padding:14px 16px;text-align:center;font-size:12px;color:#64748b;"><?= fmtD2($p['released_at']) ?></td>
        <td style="padding:14px 16px;text-align:center;font-size:12px;color:#64748b;"><?= htmlspecialchars($p['released_by'] ?? '—') ?></td>
        <td style="padding:14px 16px;text-align:center;">
            <a href="<?= BASE_URL ?>modules/payroll/batch-detail.php?period_id=<?= $p['period_id'] ?>"
               style="display:inline-flex;align-items:center;gap:5px;font-size:12px;font-weight:600;color:#2563eb;text-decoration:none;padding:5px 10px;border-radius:6px;border:1px solid #bfdbfe;background:#eff6ff;">
                <i class="fa fa-layer-group"></i> Details
            </a>
        </td>
    </tr>
    <?php endforeach; ?>
    </tbody>
    <!-- Totals footer -->
    <tfoot>
        <tr style="background:#f8fafc;border-top:2px solid var(--border);">
            <td style="padding:12px 16px;font-weight:700;font-size:12px;color:#374151;" colspan="2">
                TOTALS (<?= count($periods) ?> period<?= count($periods) !== 1 ? 's' : '' ?>)
            </td>
            <td style="padding:12px 16px;text-align:right;font-weight:700;color:#374151;"><?= peso2($arcGross) ?></td>
            <td style="padding:12px 16px;text-align:right;font-weight:700;color:#dc2626;">
                <?= peso2(array_sum(array_column($periods, 'total_deductions'))) ?>
            </td>
            <td style="padding:12px 16px;text-align:right;font-weight:800;color:#0f766e;font-size:14px;"><?= peso2($arcNet) ?></td>
            <td colspan="3"></td>
        </tr>
    </tfoot>
</table>
</div>
<?php endif; ?>

</div><!-- .main-content -->
</div><!-- .main -->
</div><!-- .layout -->

<?php include __DIR__ . '/../../includes/footer.php'; ?>
