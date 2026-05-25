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

// Payslip list with period_name for search
$stmt = $pdo->prepare("
    SELECT pr.payroll_id, pr.basic_pay, pr.gross_pay, pr.total_deductions, pr.net_pay,
           pr.released_at,
           pp.period_name, pp.pay_period_start, pp.pay_period_end, pp.payroll_number
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
      <!-- Filters -->
      <form method="get" style="display:flex;align-items:center;gap:8px;flex-wrap:wrap;">
        <input type="text" id="psSearch" placeholder="Search period…"
               style="padding:7px 12px;border-radius:8px;border:1px solid var(--border);font-size:13px;background:#fff;width:170px;"
               oninput="filterPayslips()">
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
      <table style="width:100%;border-collapse:collapse;font-size:13px;" id="payslipTable">
        <thead>
          <tr style="background:#f8fafc;border-bottom:1px solid var(--border);">
            <th style="padding:12px 16px;text-align:left;font-size:11px;font-weight:700;color:#64748b;text-transform:uppercase;letter-spacing:0.4px;">Payroll #</th>
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
            $pn = htmlspecialchars($ps['period_name'] ?? $pl);
        ?>
        <tr style="border-bottom:1px solid var(--border);"
            class="payslip-row"
            data-period="<?= strtolower($pn) ?>">
          <td style="padding:14px 16px;">
            <code style="font-size:11px;color:#64748b;background:#f1f5f9;padding:2px 7px;border-radius:4px;">
              <?= htmlspecialchars($ps['payroll_number'] ?? ('PR-' . str_pad($ps['payroll_id'], 5, '0', STR_PAD_LEFT))) ?>
            </code>
          </td>
          <td style="padding:14px 16px;">
            <div style="font-weight:600;color:#0f172a;"><?= $pn ?></div>
            <div style="font-size:11px;color:#94a3b8;margin-top:2px;"><?= htmlspecialchars($pl) ?></div>
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
      <div id="psNoResults" style="display:none;padding:32px;text-align:center;color:#94a3b8;font-size:13px;">
        No payslips match your search.
      </div>
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
<div id="payslipOverlay" class="ps-overlay">
  <div class="ps-modal" id="ps-printable">

    <!-- Modal Header -->
    <div class="ps-modal-header">
      <div class="ps-header-left">
        <div class="ps-school-name">Great Eastern Institute</div>
        <div class="ps-modal-title">Employee Payslip</div>
        <div class="ps-period-label" id="ps-period"></div>
      </div>
      <button onclick="closePayslipModal()" class="ps-close-btn no-print">
        <i class="fa fa-times"></i>
      </button>
    </div>

    <!-- Loading state -->
    <div id="ps-loading" class="ps-loading">
      <i class="fa fa-spinner fa-spin"></i>
      Loading payslip…
    </div>

    <!-- Content -->
    <div id="ps-content" style="display:none;padding:20px 22px;">

      <!-- Payroll / Payslip reference numbers -->
      <div id="ps-numbers" style="display:flex;gap:12px;flex-wrap:wrap;align-items:center;font-size:12px;color:#64748b;padding:8px 14px 0;"></div>

      <!-- Employee info -->
      <div class="ps-emp-info">
        <div class="ps-emp-name" id="ps-empname"></div>
        <div class="ps-emp-id-row">
          <span class="ps-emp-id-label">Employee ID:</span>
          <code class="ps-emp-id" id="ps-empid"></code>
        </div>
        <div class="ps-emp-meta-row">
          <span><strong>Position:</strong> <span id="ps-position"></span></span>
          <span class="ps-meta-sep">|</span>
          <span><strong>Dept:</strong> <span id="ps-dept"></span></span>
          <span class="ps-meta-sep">|</span>
          <span><strong>Type:</strong> <span id="ps-emptype"></span></span>
        </div>
        <div class="ps-emp-meta-row" style="margin-top:4px;">
          <span><strong>Salary Rate:</strong> <span id="ps-salary-rate"></span></span>
          <span class="ps-meta-sep">|</span>
          <span><strong>Date Generated:</strong> <span id="ps-date-generated"></span></span>
        </div>
      </div>

      <!-- Attendance Summary -->
      <div id="ps-att-section" style="display:none;margin-bottom:14px;">
        <div class="ps-section-label ps-section-label--blue">
          <i class="fa fa-calendar-check"></i> Attendance Summary
        </div>
        <div class="ps-att-grid">
          <div class="ps-att-cell">
            <div class="ps-att-num" id="ps-att-present">—</div>
            <div class="ps-att-lbl">Present</div>
          </div>
          <div class="ps-att-cell">
            <div class="ps-att-num ps-att-num--amber" id="ps-att-late">—</div>
            <div class="ps-att-lbl">Late</div>
          </div>
          <div class="ps-att-cell">
            <div class="ps-att-num ps-att-num--orange" id="ps-att-halfday">—</div>
            <div class="ps-att-lbl">Half-Day</div>
          </div>
          <div class="ps-att-cell">
            <div class="ps-att-num ps-att-num--red" id="ps-att-absent">—</div>
            <div class="ps-att-lbl">Absent</div>
          </div>
          <div class="ps-att-cell">
            <div class="ps-att-num ps-att-num--teal" id="ps-att-leave">—</div>
            <div class="ps-att-lbl">Leave</div>
          </div>
        </div>
        <div style="font-size:11px;color:#94a3b8;margin-top:6px;font-style:italic;">
          Attendance shown for reference. Late/undertime incur no salary deduction per GEI policy.
        </div>
      </div>

      <!-- Earnings -->
      <div style="margin-bottom:14px;">
        <div class="ps-section-label ps-section-label--green">
          <i class="fa fa-circle-plus"></i> Earnings
        </div>
        <div id="ps-earnings" class="ps-row-list ps-row-list--green"></div>
        <div class="ps-total-bar ps-total-bar--green">
          <span>Gross Pay</span>
          <span id="ps-gross"></span>
        </div>
      </div>

      <!-- Deductions -->
      <div style="margin-bottom:14px;">
        <div class="ps-section-label ps-section-label--red">
          <i class="fa fa-circle-minus"></i> Deductions
        </div>
        <div id="ps-deductions" class="ps-row-list ps-row-list--red"></div>
        <div class="ps-total-bar ps-total-bar--red">
          <span>Total Deductions</span>
          <span id="ps-totalded"></span>
        </div>
        <div id="ps-gov-note" style="font-size:11px;color:#94a3b8;margin-top:5px;font-style:italic;"></div>
      </div>

      <!-- Net Pay -->
      <div class="ps-net-box ps-net-box--blue">
        <span class="ps-net-label">NET PAY</span>
        <span class="ps-net-amount" id="ps-net"></span>
      </div>

      <!-- Post-Deduction Additions (Rice Subsidy, Laundry) -->
      <div id="ps-postded-section" style="display:none;margin-top:14px;">
        <div class="ps-section-label ps-section-label--purple">
          <i class="fa fa-circle-plus"></i> Additional Allowances
        </div>
        <div id="ps-postded" class="ps-row-list ps-row-list--purple"></div>
        <div class="ps-total-bar ps-total-bar--purple">
          <span>Total Additions</span>
          <span id="ps-postded-total"></span>
        </div>
      </div>

      <!-- Total Take-Home Pay -->
      <div id="ps-takehome-box" class="ps-net-box ps-net-box--purple" style="display:none;margin-top:8px;">
        <span class="ps-net-label">TOTAL TAKE-HOME PAY</span>
        <span class="ps-net-amount" id="ps-takehome"></span>
      </div>

      <!-- Released info -->
      <div class="ps-released-info" id="ps-released-info"></div>
      <div class="ps-print-footer">
        This payslip is system-generated. Great Eastern Institute, La Paz, Tarlac.
      </div>

    </div>

    <!-- Modal footer -->
    <div class="ps-modal-footer no-print">
      <button onclick="closePayslipModal()" class="ps-btn ps-btn--outline">Close</button>
      <button onclick="window.print()" class="ps-btn ps-btn--primary">
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

function esc(s) {
    const d = document.createElement('div'); d.textContent = s; return d.innerHTML;
}

function fmtEmpType(t) {
    if (!t) return '—';
    if (t === 'FULL_TIME')  return 'Full-Time';
    if (t === 'PART_TIME')  return 'Part-Time';
    return t.replace(/_/g,' ').replace(/\b\w/g, c => c.toUpperCase());
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
            const r   = data.record;
            const att = data.attendance || {};

            // Header period
            const startFmt = new Date(r.pay_period_start + 'T00:00').toLocaleDateString('en-PH',{month:'short',day:'numeric'});
            const endFmt   = new Date(r.pay_period_end   + 'T00:00').toLocaleDateString('en-PH',{month:'short',day:'numeric',year:'numeric'});
            document.getElementById('ps-period').textContent =
                (r.period_name || 'Pay Period') + ' · ' + startFmt + ' – ' + endFmt;

            // Payroll # / Payslip # — payroll_number is the batch number from payroll_periods
            const payrollNo  = r.payroll_number || ('PR-' + String(r.payroll_id).padStart(5, '0'));
            const payslipNo  = r.payslip_number || ('PS-' + String(r.payroll_id).padStart(6, '0'));
            const psNumEl    = document.getElementById('ps-numbers');
            if (psNumEl) psNumEl.innerHTML =
                `<span>Payroll #: <code style="font-size:11px;background:#e2e8f0;padding:1px 6px;border-radius:4px;">${payrollNo}</code></span>` +
                `<span style="color:#d1d5db;">|</span>` +
                `<span>Payslip #: <code style="font-size:11px;background:#e2e8f0;padding:1px 6px;border-radius:4px;">${payslipNo}</code></span>`;

            // Employee info
            document.getElementById('ps-empname').textContent    = r.employee_name;
            document.getElementById('ps-empid').textContent      = r.employee_no || '—';
            document.getElementById('ps-position').textContent   = r.position_name || '—';
            document.getElementById('ps-dept').textContent       = r.department_name || '—';
            document.getElementById('ps-emptype').textContent    = fmtEmpType(r.employment_type);
            document.getElementById('ps-salary-rate').textContent =
                parseFloat(r.monthly_salary || 0) > 0 ? fmt(r.monthly_salary) + ' / mo' : '—';
            document.getElementById('ps-date-generated').textContent =
                r.released_at
                    ? new Date(r.released_at).toLocaleDateString('en-PH',{month:'long',day:'numeric',year:'numeric'})
                    : new Date().toLocaleDateString('en-PH',{month:'long',day:'numeric',year:'numeric'});

            // Attendance summary
            const attSection = document.getElementById('ps-att-section');
            if (att && parseInt(att.total_records || 0) > 0) {
                document.getElementById('ps-att-present').textContent = att.days_present || '0';
                document.getElementById('ps-att-late').textContent    = att.days_late    || '0';
                document.getElementById('ps-att-halfday').textContent = att.days_halfday || '0';
                document.getElementById('ps-att-absent').textContent  = att.days_absent  || '0';
                document.getElementById('ps-att-leave').textContent   = att.days_leave   || '0';
                attSection.style.display = 'block';
            } else {
                attSection.style.display = 'none';
            }

            // Classify allowances
            const POST_DED = /rice|laundry/i;
            const postDedAllowances = data.allowances.filter(a => POST_DED.test(a.name));
            const coreAllowances    = data.allowances.filter(a => !POST_DED.test(a.name));

            const postDedTotal    = postDedAllowances.reduce((s, a) => s + parseFloat(a.amount || 0), 0);
            const coreGross       = parseFloat(r.gross_pay) - postDedTotal;
            const intermediateNet = parseFloat(r.net_pay) - postDedTotal;
            const finalTakeHome   = parseFloat(r.net_pay);

            document.getElementById('ps-gross').textContent    = fmt(coreGross);
            document.getElementById('ps-totalded').textContent = fmt(r.total_deductions);
            document.getElementById('ps-net').textContent      = fmt(intermediateNet);

            // Earnings rows
            const earningsEl = document.getElementById('ps-earnings');
            earningsEl.innerHTML = '';
            if (parseFloat(r.basic_pay) > 0) {
                earningsEl.innerHTML += rowHtml('Basic Pay', r.basic_pay);
            }
            coreAllowances.forEach(a => {
                let label = a.name;
                if (/additional assignment/i.test(a.name)) label += ' (Overload)';
                if (parseInt(a.is_service_credit || 0)) label += ' (Service Credit)';
                earningsEl.innerHTML += rowHtml(label, a.amount);
            });
            if (!earningsEl.innerHTML) {
                earningsEl.innerHTML = '<div style="padding:10px 14px;color:#94a3b8;font-size:12px;">No earnings recorded.</div>';
            }

            // Deduction rows
            const dedEl = document.getElementById('ps-deductions');
            dedEl.innerHTML = '';
            let hasGovDeductions = false;
            data.deductions.forEach(d => {
                if (/sss|philhealth|pag.?ibig/i.test(d.name)) hasGovDeductions = true;
                dedEl.innerHTML += rowHtml(d.name, d.amount);
            });
            if (!data.deductions.length) {
                dedEl.innerHTML = '<div style="padding:10px 14px;color:#94a3b8;font-size:12px;">No deductions</div>';
            }

            // Government deduction footnote
            const govNote = document.getElementById('ps-gov-note');
            if (hasGovDeductions) {
                govNote.textContent = '* SSS, PhilHealth, and Pag-IBIG amounts reflect employee share only.';
            } else {
                govNote.textContent = '';
            }

            // Post-deduction additions
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

            // Released
            if (r.released_at) {
                document.getElementById('ps-released-info').textContent =
                    'Released on ' + new Date(r.released_at).toLocaleDateString('en-PH', {month:'long',day:'numeric',year:'numeric'});
            } else {
                document.getElementById('ps-released-info').textContent = '';
            }

            document.getElementById('ps-loading').style.display = 'none';
            document.getElementById('ps-content').style.display  = 'block';
        })
        .catch(() => { alert('Failed to load payslip.'); closePayslipModal(); });
}

function rowHtml(label, amount) {
    return '<div style="display:flex;justify-content:space-between;padding:9px 14px;font-size:13px;border-bottom:1px solid rgba(0,0,0,0.05);">' +
           '<span style="color:#374151;">' + esc(label) + '</span>' +
           '<span style="font-weight:600;color:#0f172a;">' + fmt(amount) + '</span></div>';
}

function closePayslipModal() {
    document.getElementById('payslipOverlay').style.display = 'none';
}

document.getElementById('payslipOverlay').addEventListener('click', function(e) {
    if (e.target === this) closePayslipModal();
});

// Period name search filter
function filterPayslips() {
    const q = document.getElementById('psSearch').value.toLowerCase().trim();
    const rows = document.querySelectorAll('#payslipTable .payslip-row');
    let visible = 0;
    rows.forEach(tr => {
        const match = !q || tr.dataset.period.includes(q);
        tr.style.display = match ? '' : 'none';
        if (match) visible++;
    });
    document.getElementById('psNoResults').style.display = (!visible && rows.length > 0) ? 'block' : 'none';
}

// Hover effect on rows
document.querySelectorAll('.payslip-row').forEach(tr => {
    tr.addEventListener('mouseenter', () => { if (tr.style.display !== 'none') tr.style.background = '#f8fafc'; });
    tr.addEventListener('mouseleave', () => tr.style.background = '');
});
</script>

<?php include __DIR__ . '/../../../includes/footer.php'; ?>
