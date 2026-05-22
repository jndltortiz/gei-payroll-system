<?php
/**
 * modules/employee/payslips/index.php
 * Employee Portal — My Payslips (RELEASED periods only)
 */
require_once __DIR__ . '/../../../config/config.php';
require_once __DIR__ . '/../../../includes/auth.php';
requireEmployee();

$empId = (int)($_SESSION['user']['employee_id'] ?? 0);

// Year filter
$years = [];
$allYears = $pdo->prepare("
    SELECT DISTINCT YEAR(pp.pay_period_start) AS yr
    FROM payroll_records pr
    JOIN payroll_periods pp ON pr.period_id = pp.period_id
    WHERE pr.employee_id = ? AND pp.status = 'RELEASED'
    ORDER BY yr DESC
");
$allYears->execute([$empId]);
$years = $allYears->fetchAll(PDO::FETCH_COLUMN);

$filterYear = isset($_GET['year']) && in_array((int)$_GET['year'], $years)
    ? (int)$_GET['year']
    : (int)(date('Y'));

// Payslip list
$stmt = $pdo->prepare("
    SELECT pr.payroll_id, pr.basic_pay, pr.gross_pay, pr.total_deductions, pr.net_pay,
           pr.released_at,
           pp.pay_period_start, pp.pay_period_end
    FROM payroll_records pr
    JOIN payroll_periods pp ON pr.period_id = pp.period_id
    WHERE pr.employee_id = ?
      AND pp.status = 'RELEASED'
      AND YEAR(pp.pay_period_start) = ?
    ORDER BY pp.pay_period_start DESC
");
$stmt->execute([$empId, $filterYear]);
$payslips = $stmt->fetchAll(PDO::FETCH_ASSOC);

// Year-to-date totals
$ytd = ['gross' => 0, 'deductions' => 0, 'net' => 0];
foreach ($payslips as $p) {
    $ytd['gross']      += (float)$p['gross_pay'];
    $ytd['deductions'] += (float)$p['total_deductions'];
    $ytd['net']        += (float)$p['net_pay'];
}

$pageTitle = 'My Payslips — Employee Portal';
$extraCSS  = [BASE_URL . 'assets/css/employee-portal.css'];
require_once __DIR__ . '/../../../includes/head.php';
?>
<body>
<div class="layout">
<?php include __DIR__ . '/../../../includes/employee-sidebar.php'; ?>

<div class="main">
  <div class="header">
    <div style="display:flex;align-items:center;gap:10px;">
      <i class="fa fa-file-invoice-dollar" style="color:#2563eb;font-size:18px;"></i>
      <div>
        <div style="font-size:15px;font-weight:700;color:#0f172a;">Employee Portal</div>
        <div style="font-size:12px;color:#64748b;">Great Eastern Institute</div>
      </div>
    </div>
    <div style="margin-left:auto;display:flex;align-items:center;gap:12px;">
      <div style="text-align:right;">
        <div style="font-size:14px;font-weight:600;color:#0f172a;">
          <?= htmlspecialchars(($_SESSION['user']['first_name'] ?? '') . ' ' . ($_SESSION['user']['last_name'] ?? '')) ?>
        </div>
        <div style="font-size:11px;color:#64748b;">Employee</div>
      </div>
      <div class="header-avatar">
        <?= strtoupper(substr($_SESSION['user']['first_name'] ?? 'E', 0, 1) . substr($_SESSION['user']['last_name'] ?? 'M', 0, 1)) ?>
      </div>
    </div>
  </div>

  <div class="main-content">
  <div class="emp-page">

    <!-- Page Header -->
    <div style="display:flex;align-items:flex-start;justify-content:space-between;flex-wrap:wrap;gap:12px;margin-bottom:20px;">
      <div>
        <h1 style="font-size:18px;font-weight:700;color:#0f172a;margin:0 0 2px;">My Payslips</h1>
        <p style="font-size:12px;color:#94a3b8;margin:0;">Viewing released payslips for payroll periods.</p>
      </div>
      <!-- Year filter -->
      <form method="get" style="display:flex;align-items:center;gap:8px;">
        <label style="font-size:12px;font-weight:600;color:#64748b;">Year</label>
        <select name="year" onchange="this.form.submit()"
                style="padding:7px 12px;border-radius:8px;border:1px solid var(--border);font-size:13px;background:#fff;">
          <?php foreach ($years as $yr): ?>
          <option value="<?= $yr ?>" <?= $yr == $filterYear ? 'selected' : '' ?>><?= $yr ?></option>
          <?php endforeach; ?>
          <?php if (empty($years)): ?>
          <option value="<?= date('Y') ?>"><?= date('Y') ?></option>
          <?php endif; ?>
        </select>
      </form>
    </div>

    <?php if (!empty($payslips)): ?>

    <!-- YTD Summary Cards -->
    <div class="emp-kpi-row" style="grid-template-columns:repeat(3,1fr);margin-bottom:16px;">
      <div class="emp-kpi-card emp-kpi--blue">
        <div class="emp-kpi-icon"><i class="fa fa-coins"></i></div>
        <div class="emp-kpi-body">
          <div class="emp-kpi-value">₱<?= number_format($ytd['gross'], 2) ?></div>
          <div class="emp-kpi-label">YTD Gross Pay</div>
          <div class="emp-kpi-sub"><?= count($payslips) ?> period<?= count($payslips) !== 1 ? 's' : '' ?> in <?= $filterYear ?></div>
        </div>
      </div>
      <div class="emp-kpi-card emp-kpi--red">
        <div class="emp-kpi-icon"><i class="fa fa-circle-minus"></i></div>
        <div class="emp-kpi-body">
          <div class="emp-kpi-value">₱<?= number_format($ytd['deductions'], 2) ?></div>
          <div class="emp-kpi-label">YTD Deductions</div>
          <div class="emp-kpi-sub">All mandatory &amp; loan deductions</div>
        </div>
      </div>
      <div class="emp-kpi-card emp-kpi--green">
        <div class="emp-kpi-icon"><i class="fa fa-money-bill-wave"></i></div>
        <div class="emp-kpi-body">
          <div class="emp-kpi-value">₱<?= number_format($ytd['net'], 2) ?></div>
          <div class="emp-kpi-label">YTD Net Received</div>
          <div class="emp-kpi-sub">Total take-home pay</div>
        </div>
      </div>
    </div>

    <!-- Payslip Table -->
    <div class="emp-panel" style="padding:0;overflow:hidden;">
      <table style="width:100%;border-collapse:collapse;font-size:13px;">
        <thead>
          <tr style="background:#f8fafc;border-bottom:1px solid var(--border);">
            <th style="padding:12px 16px;text-align:left;font-size:11px;font-weight:700;color:#64748b;text-transform:uppercase;letter-spacing:0.4px;">Pay Period</th>
            <th style="padding:12px 16px;text-align:right;font-size:11px;font-weight:700;color:#64748b;text-transform:uppercase;letter-spacing:0.4px;">Gross Pay</th>
            <th style="padding:12px 16px;text-align:right;font-size:11px;font-weight:700;color:#64748b;text-transform:uppercase;letter-spacing:0.4px;">Deductions</th>
            <th style="padding:12px 16px;text-align:right;font-size:11px;font-weight:700;color:#64748b;text-transform:uppercase;letter-spacing:0.4px;">Net Pay</th>
            <th style="padding:12px 16px;text-align:center;font-size:11px;font-weight:700;color:#64748b;text-transform:uppercase;letter-spacing:0.4px;">Released</th>
            <th style="padding:12px 16px;text-align:center;font-size:11px;font-weight:700;color:#64748b;text-transform:uppercase;letter-spacing:0.4px;"></th>
          </tr>
        </thead>
        <tbody>
        <?php foreach ($payslips as $ps):
            $pl = date('M j', strtotime($ps['pay_period_start'])) . ' – ' . date('M j, Y', strtotime($ps['pay_period_end']));
        ?>
        <tr style="border-bottom:1px solid var(--border);" class="payslip-row">
          <td style="padding:14px 16px;">
            <div style="font-weight:600;color:#0f172a;"><?= htmlspecialchars($pl) ?></div>
          </td>
          <td style="padding:14px 16px;text-align:right;font-weight:600;color:#374151;">
            ₱<?= number_format((float)$ps['gross_pay'], 2) ?>
          </td>
          <td style="padding:14px 16px;text-align:right;color:#dc2626;font-weight:600;">
            ₱<?= number_format((float)$ps['total_deductions'], 2) ?>
          </td>
          <td style="padding:14px 16px;text-align:right;font-weight:700;color:#2563eb;font-size:14px;">
            ₱<?= number_format((float)$ps['net_pay'], 2) ?>
          </td>
          <td style="padding:14px 16px;text-align:center;color:#64748b;font-size:12px;">
            <?= $ps['released_at'] ? date('M j, Y', strtotime($ps['released_at'])) : '—' ?>
          </td>
          <td style="padding:14px 16px;text-align:center;">
            <button class="emp-link-btn"
                    onclick="viewPayslip(<?= $ps['payroll_id'] ?>)">
              <i class="fa fa-eye"></i> View
            </button>
          </td>
        </tr>
        <?php endforeach; ?>
        </tbody>
      </table>
    </div>

    <?php else: ?>
    <div class="emp-panel">
      <div class="emp-empty" style="padding:60px 20px;">
        <i class="fa fa-file-invoice-dollar"></i>
        <p>No released payslips found for <?= $filterYear ?>.<br>
           <?= empty($years) ? 'Payslips will appear here once payroll is processed and released.' : 'Try selecting a different year.' ?></p>
      </div>
    </div>
    <?php endif; ?>

  </div>
  </div>
</div>
</div>

<!-- ── Payslip Detail Modal ─────────────────────────────────────────────────── -->
<div id="payslipOverlay" style="display:none;position:fixed;inset:0;background:rgba(0,0,0,0.45);z-index:9999;align-items:center;justify-content:center;">
  <div style="background:#fff;border-radius:14px;width:520px;max-width:95%;max-height:90vh;overflow-y:auto;box-shadow:0 20px 60px rgba(0,0,0,0.2);">

    <!-- Modal Header -->
    <div style="background:#1e3a5f;color:#fff;padding:18px 22px;border-radius:14px 14px 0 0;display:flex;align-items:center;justify-content:space-between;">
      <div>
        <div style="font-size:15px;font-weight:700;">Employee Payslip</div>
        <div id="ps-period" style="font-size:12px;opacity:0.8;margin-top:2px;"></div>
      </div>
      <button onclick="closePayslipModal()"
              style="background:rgba(255,255,255,0.2);border:none;color:#fff;width:28px;height:28px;border-radius:50%;cursor:pointer;font-size:14px;">
        <i class="fa fa-times"></i>
      </button>
    </div>

    <!-- Loading state -->
    <div id="ps-loading" style="padding:48px;text-align:center;color:#94a3b8;">
      <i class="fa fa-spinner fa-spin" style="font-size:22px;margin-bottom:10px;display:block;"></i>
      Loading payslip…
    </div>

    <!-- Content -->
    <div id="ps-content" style="display:none;padding:20px 22px;">

      <!-- Employee info -->
      <div style="background:#f8fafc;border-radius:8px;padding:12px 14px;margin-bottom:16px;font-size:13px;">
        <div style="font-weight:700;font-size:14px;color:#0f172a;margin-bottom:6px;" id="ps-empname"></div>
        <div style="display:flex;gap:16px;flex-wrap:wrap;color:#64748b;">
          <span><strong style="color:#374151;">Position:</strong> <span id="ps-position"></span></span>
          <span><strong style="color:#374151;">Dept:</strong> <span id="ps-dept"></span></span>
        </div>
      </div>

      <!-- Earnings -->
      <div style="margin-bottom:14px;">
        <div style="font-size:11px;font-weight:700;text-transform:uppercase;letter-spacing:0.5px;color:#059669;margin-bottom:8px;">
          <i class="fa fa-circle-plus"></i> Earnings
        </div>
        <div id="ps-earnings" style="border:1px solid #d1fae5;border-radius:8px;overflow:hidden;"></div>
        <div style="display:flex;justify-content:space-between;padding:10px 14px;background:#d1fae5;border-radius:0 0 8px 8px;font-weight:700;font-size:13px;color:#065f46;margin-top:-1px;">
          <span>Gross Pay</span>
          <span id="ps-gross"></span>
        </div>
      </div>

      <!-- Deductions -->
      <div style="margin-bottom:14px;">
        <div style="font-size:11px;font-weight:700;text-transform:uppercase;letter-spacing:0.5px;color:#dc2626;margin-bottom:8px;">
          <i class="fa fa-circle-minus"></i> Deductions
        </div>
        <div id="ps-deductions" style="border:1px solid #fee2e2;border-radius:8px;overflow:hidden;"></div>
        <div style="display:flex;justify-content:space-between;padding:10px 14px;background:#fee2e2;border-radius:0 0 8px 8px;font-weight:700;font-size:13px;color:#991b1b;margin-top:-1px;">
          <span>Total Deductions</span>
          <span id="ps-totalded"></span>
        </div>
      </div>

      <!-- Net Pay (before post-deduction additions) -->
      <div style="background:#eff6ff;border:2px solid #bfdbfe;border-radius:10px;padding:14px 18px;display:flex;justify-content:space-between;align-items:center;">
        <span style="font-size:14px;font-weight:700;color:#1e40af;">NET PAY</span>
        <span style="font-size:20px;font-weight:800;color:#2563eb;" id="ps-net"></span>
      </div>

      <!-- Post-Deduction Additions (Rice Subsidy, Laundry — shown only when present) -->
      <div id="ps-postded-section" style="display:none;margin-top:14px;">
        <div style="font-size:11px;font-weight:700;text-transform:uppercase;letter-spacing:0.5px;color:#7c3aed;margin-bottom:8px;">
          <i class="fa fa-circle-plus"></i> Additional Allowances
        </div>
        <div id="ps-postded" style="border:1px solid #ede9fe;border-radius:8px 8px 0 0;overflow:hidden;"></div>
        <div style="display:flex;justify-content:space-between;padding:10px 14px;background:#ede9fe;border-radius:0 0 8px 8px;font-weight:700;font-size:13px;color:#5b21b6;">
          <span>Total Additions</span>
          <span id="ps-postded-total"></span>
        </div>
      </div>

      <!-- Total Take-Home Pay (shown when post-deduction additions exist) -->
      <div id="ps-takehome-box" style="display:none;background:#f5f3ff;border:2px solid #c4b5fd;border-radius:10px;padding:14px 18px;justify-content:space-between;align-items:center;margin-top:8px;">
        <span style="font-size:14px;font-weight:700;color:#5b21b6;">TOTAL TAKE-HOME PAY</span>
        <span style="font-size:20px;font-weight:800;color:#7c3aed;" id="ps-takehome"></span>
      </div>

      <!-- Released -->
      <div style="margin-top:12px;font-size:11px;color:#94a3b8;text-align:right;" id="ps-released-info"></div>

    </div>

    <!-- Modal footer -->
    <div style="padding:14px 22px;border-top:1px solid var(--border);display:flex;justify-content:flex-end;gap:8px;">
      <button onclick="closePayslipModal()"
              style="padding:8px 18px;border-radius:8px;border:1px solid var(--border);background:#fff;font-size:13px;cursor:pointer;">
        Close
      </button>
      <button onclick="window.print()"
              style="padding:8px 18px;border-radius:8px;border:none;background:#2563eb;color:#fff;font-size:13px;font-weight:600;cursor:pointer;">
        <i class="fa fa-print"></i> Print
      </button>
    </div>
  </div>
</div>

<script>
const BASE_URL = '<?= BASE_URL ?>';

function fmt(n) {
    return '₱' + parseFloat(n || 0).toLocaleString('en-PH', {minimumFractionDigits: 2, maximumFractionDigits: 2});
}

function viewPayslip(payrollId) {
    const overlay = document.getElementById('payslipOverlay');
    overlay.style.display = 'flex';
    document.getElementById('ps-loading').style.display = 'block';
    document.getElementById('ps-content').style.display  = 'none';

    fetch(BASE_URL + 'actions/employee-payslip-get.php?payroll_id=' + payrollId)
        .then(r => r.json())
        .then(data => {
            if (!data.success) { alert(data.message); closePayslipModal(); return; }
            const r = data.record;

            document.getElementById('ps-period').textContent =
                'Pay Period: ' + new Date(r.pay_period_start + 'T00:00').toLocaleDateString('en-PH', {month:'short', day:'numeric'}) +
                ' – ' + new Date(r.pay_period_end + 'T00:00').toLocaleDateString('en-PH', {month:'short', day:'numeric', year:'numeric'});
            document.getElementById('ps-empname').textContent   = r.employee_name;
            document.getElementById('ps-position').textContent  = r.position_name || '—';
            document.getElementById('ps-dept').textContent      = r.department_name || '—';

            if (r.released_at) {
                document.getElementById('ps-released-info').textContent =
                    'Released on ' + new Date(r.released_at).toLocaleDateString('en-PH', {month:'long', day:'numeric', year:'numeric'});
            }

            // Classify allowances: post-deduction additions vs core earnings (GEI payslip format)
            const POST_DED = /rice|laundry/i;
            const postDedAllowances = data.allowances.filter(a => POST_DED.test(a.name));
            const coreAllowances    = data.allowances.filter(a => !POST_DED.test(a.name));

            const postDedTotal   = postDedAllowances.reduce((s, a) => s + parseFloat(a.amount || 0), 0);
            const coreGross      = parseFloat(r.gross_pay) - postDedTotal;
            const intermediateNet = parseFloat(r.net_pay) - postDedTotal;
            const finalTakeHome  = parseFloat(r.net_pay);

            document.getElementById('ps-gross').textContent    = fmt(coreGross);
            document.getElementById('ps-totalded').textContent = fmt(r.total_deductions);
            document.getElementById('ps-net').textContent      = fmt(intermediateNet);

            // Earnings rows (core only — Basic Pay + Additional Assignment + others)
            const earningsEl = document.getElementById('ps-earnings');
            earningsEl.innerHTML = '';
            if (parseFloat(r.basic_pay) > 0) {
                earningsEl.innerHTML += rowHtml('Basic Pay', r.basic_pay);
            }
            coreAllowances.forEach(a => {
                const label = /additional assignment/i.test(a.name) ? a.name + ' (Overload)' : a.name;
                earningsEl.innerHTML += rowHtml(label, a.amount);
            });

            // Deduction rows
            const dedEl = document.getElementById('ps-deductions');
            dedEl.innerHTML = '';
            data.deductions.forEach(d => {
                dedEl.innerHTML += rowHtml(d.name, d.amount);
            });
            if (!data.deductions.length) {
                dedEl.innerHTML = '<div style="padding:10px 14px;color:#94a3b8;font-size:12px;">No deductions</div>';
            }

            // Post-deduction additions section (Rice Subsidy, Laundry Allowance)
            const postDedSection = document.getElementById('ps-postded-section');
            const takeHomeBox    = document.getElementById('ps-takehome-box');
            if (postDedTotal > 0) {
                const postDedEl = document.getElementById('ps-postded');
                postDedEl.innerHTML = '';
                postDedAllowances.forEach(a => { postDedEl.innerHTML += rowHtml(a.name, a.amount); });
                document.getElementById('ps-postded-total').textContent = fmt(postDedTotal);
                document.getElementById('ps-takehome').textContent      = fmt(finalTakeHome);
                postDedSection.style.display = 'block';
                takeHomeBox.style.display    = 'flex';
            } else {
                postDedSection.style.display = 'none';
                takeHomeBox.style.display    = 'none';
            }

            document.getElementById('ps-loading').style.display = 'none';
            document.getElementById('ps-content').style.display  = 'block';
        })
        .catch(() => { alert('Failed to load payslip.'); closePayslipModal(); });
}

function rowHtml(label, amount) {
    return '<div style="display:flex;justify-content:space-between;padding:9px 14px;font-size:13px;border-bottom:1px solid rgba(0,0,0,0.05);">' +
           '<span style="color:#374151;">' + label + '</span>' +
           '<span style="font-weight:600;color:#0f172a;">' + fmt(amount) + '</span></div>';
}

function closePayslipModal() {
    document.getElementById('payslipOverlay').style.display = 'none';
}

document.getElementById('payslipOverlay').addEventListener('click', function(e) {
    if (e.target === this) closePayslipModal();
});

// Hover effect on rows
document.querySelectorAll('.payslip-row').forEach(tr => {
    tr.addEventListener('mouseenter', () => tr.style.background = '#f8fafc');
    tr.addEventListener('mouseleave', () => tr.style.background = '');
});
</script>

<?php include __DIR__ . '/../../../includes/footer.php'; ?>
