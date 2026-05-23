/* loans.js — GEI HR System — Loan Records Management */
'use strict';

const peso = v => '₱' + (parseFloat(v)||0).toLocaleString('en-PH',{minimumFractionDigits:2,maximumFractionDigits:2});
const esc  = s => String(s||'').replace(/&/g,'&amp;').replace(/</g,'&lt;').replace(/>/g,'&gt;').replace(/"/g,'&quot;');
const fmt  = d => d ? new Date(d).toLocaleDateString('en-PH',{year:'numeric',month:'short',day:'numeric'}) : '—';

// ─── Add Loan Record ──────────────────────────────────────────────────────────
function openAddLoan()  { document.getElementById('addLoanOverlay').style.display='flex'; }
function closeAddLoan() { document.getElementById('addLoanOverlay').style.display='none'; }

function loanStatusLabel(status) {
    const map = {
        PENDING:   'Pending Principal Review',
        ACTIVE:    'Verified for Payroll Deduction',
        RETURNED:  'Returned for Correction',
        DENIED:    'Rejected',
        PAUSED:    'Paused',
        COMPLETED: 'Completed',
        CANCELLED: 'Cancelled',
        ARCHIVED:  'Archived',
    };
    return map[(status||'').toUpperCase()] || status;
}

function onLoanTypeChange() {
    const sel  = document.getElementById('al-type');
    const opt  = sel?.options[sel.selectedIndex];
    if (!opt || !opt.value) return;
    const name = (opt.dataset.name || '').toLowerCase();
    // Auto-fill provider for government loans
    const providerEl = document.getElementById('al-provider');
    if (providerEl && !providerEl.value) {
        if (name.includes('sss'))       providerEl.value = 'Social Security System (SSS)';
        else if (name.includes('pag-ibig') || name.includes('hdmf')) providerEl.value = 'Pag-IBIG Fund (HDMF)';
        else if (name.includes('peraa')) providerEl.value = 'PERAA';
        else if (name.includes('rural')) providerEl.value = 'Rural Bank of La Paz';
    }
}

function onAddLoanEmployeeChange(sel) {
    const opt  = sel.options[sel.selectedIndex];
    const card = document.getElementById('al-emp-card');
    if (!sel.value) { card.style.display='none'; return; }

    const name = opt.text.trim();
    const init = name.split(' ').map(w=>w[0]).slice(0,2).join('').toUpperCase();
    document.getElementById('al-initials').textContent   = init;
    document.getElementById('al-emp-name').textContent   = name;
    document.getElementById('al-emp-sub').textContent    = opt.dataset.position + ' — ' + opt.dataset.dept;
    document.getElementById('al-hire-date').textContent  = fmt(opt.dataset.hire);
    document.getElementById('al-salary').textContent     = peso(opt.dataset.salary || 0);
    card.style.display = 'flex';
    computeAddLoan();
}

function updateBalanceHint() {
    const total   = parseFloat(document.getElementById('al-amount')?.value || 0) || 0;
    const balance = parseFloat(document.getElementById('al-current-balance')?.value || 0) || 0;
    const monthly = parseFloat(document.getElementById('al-monthly')?.value || 0) || 0;
    const hint    = document.getElementById('al-balance-hint');
    if (!hint) return;

    if (total && balance < total && monthly) {
        const alreadyPaid = total - balance;
        const paysMade    = Math.floor(alreadyPaid / monthly);
        hint.style.display = 'block';
        hint.innerHTML = `<i class="fa fa-circle-info"></i> `
            + (paysMade > 0
                ? `Based on this balance, <strong>${paysMade} payment${paysMade > 1 ? 's' : ''}</strong> `
                  + `(${peso(alreadyPaid)}) will automatically show as Paid in the payment log.`
                : `Balance matches the total — no payments recorded yet.`);
    } else {
        hint.style.display = 'none';
    }
}

function computeAddLoan() {
    const amount   = parseFloat(document.getElementById('al-amount')?.value   || 0) || 0;
    const interest = parseFloat(document.getElementById('al-interest')?.value || 0) || 0;
    const term     = parseFloat(document.getElementById('al-term')?.value     || 0) || 0;

    const balField = document.getElementById('al-current-balance');
    if (balField && !balField.dataset.manuallySet) {
        balField.value = amount ? amount.toFixed(2) : '';
    }
    if (amount && term) {
        const payable = amount + (amount * (interest/100) * (term/12));
        const monthly = payable / term;
        document.getElementById('al-monthly').value       = monthly.toFixed(2);
        document.getElementById('al-total-payable').value = payable.toFixed(2);
        // Auto-deduction per payroll = monthly ÷ 2 (semi-monthly)
        const autoEl = document.getElementById('al-auto-deduction');
        if (autoEl) autoEl.value = (monthly / 2).toFixed(2);
        checkSalaryWarning(monthly);
    }
}

document.addEventListener('DOMContentLoaded', () => {
    const balField = document.getElementById('al-current-balance');
    if (balField) {
        balField.addEventListener('input', () => {
            balField.dataset.manuallySet = 'true';
            updateBalanceHint();
        });
    }
});

function computeAddLoanFromMonthly() {
    const amount   = parseFloat(document.getElementById('al-amount')?.value   || 0) || 0;
    const interest = parseFloat(document.getElementById('al-interest')?.value || 0) || 0;
    const monthly  = parseFloat(document.getElementById('al-monthly')?.value  || 0) || 0;
    if (amount && monthly) {
        const payable = amount + (amount * (interest/100));
        const term    = Math.ceil(payable / monthly);
        document.getElementById('al-term').value          = term;
        document.getElementById('al-total-payable').value = payable.toFixed(2);
        const autoEl = document.getElementById('al-auto-deduction');
        if (autoEl) autoEl.value = (monthly / 2).toFixed(2);
        checkSalaryWarning(monthly);
    }
}

function checkSalaryWarning(monthly) {
    const sel  = document.getElementById('al-employee');
    const opt  = sel?.options[sel.selectedIndex];
    const sal  = parseFloat(opt?.dataset?.salary || 0) || 0;
    const warn = document.getElementById('al-salary-warning');
    if (!warn) return;
    if (sal && monthly) {
        const pct = (monthly / sal) * 100;
        if (pct > 20) {
            warn.style.display = 'block';
            warn.textContent   = `⚠️ Monthly amortization is ${pct.toFixed(1)}% of salary — exceeds recommended 20%.`;
        } else {
            warn.style.display = 'none';
        }
    }
}

async function submitAddLoan() {
    const provider = document.getElementById('al-provider')?.value?.trim();
    const fields = {
        employee_id:       document.getElementById('al-employee')?.value,
        loan_type_id:      document.getElementById('al-type')?.value,
        provider_name:     provider,
        account_reference: document.getElementById('al-ref')?.value,
        total_amount:      document.getElementById('al-amount')?.value,
        monthly_deduction: document.getElementById('al-monthly')?.value,
        interest_rate:     document.getElementById('al-interest')?.value || '0',
        total_payable:     document.getElementById('al-total-payable')?.value || document.getElementById('al-amount')?.value,
        start_date:        document.getElementById('al-start')?.value,
        reason:            document.getElementById('al-reason')?.value,
        current_balance:   document.getElementById('al-current-balance')?.value || '',
    };

    if (!fields.employee_id || !fields.loan_type_id || !fields.provider_name ||
        !fields.total_amount || !fields.monthly_deduction || !fields.start_date) {
        showLoansFlash('al-flash', 'Please fill in all required fields (employee, loan type, provider, amount, amortization, start date).', false);
        return;
    }

    const btn  = document.getElementById('al-submit-btn');
    btn.disabled = true;
    btn.innerHTML = '<i class="fa fa-spinner fa-spin"></i> Submitting…';

    const fd = new FormData(); fd.append('action', 'add');
    Object.entries(fields).forEach(([k,v]) => fd.append(k, v));

    try {
        const res  = await fetch(`${BASE_URL}actions/loans-action.php`, {method:'POST', body:fd});
        const data = await res.json();
        showLoansFlash('al-flash', data.message, data.success);
        if (data.success) {
            setTimeout(() => { closeAddLoan(); location.reload(); }, 1500);
        } else {
            btn.disabled = false;
            btn.innerHTML = '<i class="fa fa-paper-plane"></i> Submit for Principal Review';
        }
    } catch {
        showLoansFlash('al-flash', 'Network error.', false);
        btn.disabled = false;
        btn.innerHTML = '<i class="fa fa-paper-plane"></i> Submit for Principal Review';
    }
}

// ─── Review Modal (Submitted Loan Documents) ──────────────────────────────────
function openReview(loanId) {
    document.getElementById('reviewOverlay').style.display = 'flex';
    document.getElementById('reviewBody').innerHTML = '<div class="loading-state"><i class="fa fa-spinner fa-spin"></i> Loading…</div>';
    document.querySelector('#reviewOverlay .loan-modal-header h3').textContent = 'Review Submitted Loan Document';

    fetch(`${BASE_URL}actions/loans-action.php?action=get_review&loan_id=${loanId}`)
        .then(r => r.json())
        .then(data => {
            if (!data.success) { document.getElementById('reviewBody').innerHTML = `<p style="color:red">${esc(data.message)}</p>`; return; }
            buildReviewContent(data.loan, data.active_loans);
        })
        .catch(() => {
            document.getElementById('reviewBody').innerHTML = '<p style="color:red">Network error.</p>';
        });
}

function closeReview() { document.getElementById('reviewOverlay').style.display='none'; }

function buildReviewContent(loan, activeLoans) {
    const sal    = parseFloat(loan.monthly_salary || 0);
    const ded    = parseFloat(loan.monthly_deduction || 0);
    const auto   = ded / 2;
    const pct    = sal > 0 ? (ded/sal*100).toFixed(1) : 0;
    const init   = loanInitials(loan.employee_name);
    const term   = ded > 0 ? Math.ceil(parseFloat(loan.total_payable||loan.total_amount)/ded) : 0;
    const firstPay = loan.start_date ? new Date(loan.start_date).toLocaleDateString('en-PH',{year:'numeric',month:'long',day:'numeric'}) : '—';

    const activeLoanHtml = activeLoans.length
        ? activeLoans.map(l => `<div class="review-active-loan"><span>${esc(l.loan_name)}</span><strong>${peso(l.balance_amount)} remaining</strong></div>`).join('')
        : '<div class="review-no-loans">No active loans</div>';

    const eligWarn = pct > 20
        ? `<div class="elig-item elig-item--warn"><i class="fa fa-triangle-exclamation"></i> Monthly amortization is ${pct}% of salary</div>`
        : `<div class="elig-item elig-item--ok"><i class="fa fa-circle-check"></i> Monthly amortization is ${pct}% of salary</div>`;

    document.getElementById('reviewBody').innerHTML = `
      <div class="review-app-id">Reference: <strong>${esc(loan.account_reference || 'LA-' + String(loan.loan_id).padStart(7,'0'))}</strong></div>

      <div class="review-emp-card">
        <div class="emp-avatar">${init}</div>
        <div>
          <strong>${esc(loan.employee_name)}</strong>
          <span>${esc(loan.position_name||'')} • ${esc(loan.department_name||'')}</span>
          <div class="review-emp-meta">
            <span>Employed since: <strong>${fmt(loan.hire_date)}</strong></span>
            <span>Current salary: <strong>${peso(loan.monthly_salary)}/month</strong></span>
          </div>
        </div>
      </div>

      <div class="review-grid">
        <div><small>LOAN TYPE</small><strong>${esc(loan.loan_name)}</strong></div>
        <div><small>PROVIDER</small><strong>${esc(loan.provider_name||'—')}</strong></div>
        <div><small>AMOUNT</small><strong>${peso(loan.total_amount)}</strong></div>
        <div><small>INTEREST RATE</small><strong>${parseFloat(loan.interest_rate||0)}% per annum</strong></div>
        <div><small>MONTHLY AMORTIZATION</small><strong class="text-teal">${peso(loan.monthly_deduction)}</strong></div>
        <div><small>AUTO-DEDUCTION / PAYROLL</small><strong style="color:#0369a1">${peso(auto)}</strong></div>
        <div><small>TERM</small><strong>${term} months</strong></div>
        <div><small>TOTAL PAYABLE</small><strong>${peso(loan.total_payable||loan.total_amount)}</strong></div>
        ${loan.reason ? `<div class="review-grid-full"><small>NOTES / PURPOSE</small><p>${esc(loan.reason)}</p></div>` : ''}
        <div><small>FIRST DEDUCTION DATE</small><strong>${firstPay}</strong></div>
      </div>

      <div class="eligibility-box">
        <div class="elig-header"><i class="fa fa-shield-halved"></i> Pre-Verification Check</div>
        <div class="elig-item elig-item--ok"><i class="fa fa-circle-check"></i> Loan document received from employee</div>
        <div class="elig-item elig-item--ok"><i class="fa fa-circle-check"></i> Loan approved by external institution (${esc(loan.provider_name||'SSS/HDMF/PERAA/Bank')})</div>
        ${eligWarn}
      </div>

      <div>
        <div class="review-section-label">EMPLOYEE'S OTHER ACTIVE LOANS</div>
        <div class="review-active-loans-box">${activeLoanHtml}</div>
      </div>

      <div>
        <div class="review-section-label">ADMIN NOTES (OPTIONAL)</div>
        <textarea id="review-notes" rows="2" placeholder="Add comments about this decision…"
                  style="width:100%;padding:10px;border:1px solid #e5e7eb;border-radius:8px;font-size:13px;resize:vertical;"></textarea>
      </div>

      <div id="review-flash" style="display:none" class="loan-flash"></div>

      <div class="loan-modal-footer" style="margin:0;padding-top:16px;">
        <button class="btn-deny" onclick="submitApproval(${loan.loan_id},'deny')">
          <i class="fa fa-times"></i> Reject Document
        </button>
        <button class="btn-approve" onclick="submitApproval(${loan.loan_id},'approve')">
          <i class="fa fa-check"></i> Verify &amp; Activate
        </button>
      </div>
    `;
}

async function submitApproval(loanId, decision) {
    const notes  = document.getElementById('review-notes')?.value || '';
    const fd     = new FormData();
    fd.append('action', decision === 'approve' ? 'approve' : 'deny');
    fd.append('loan_id', loanId);
    fd.append(decision === 'approve' ? 'notes' : 'denied_reason', notes);

    try {
        const res  = await fetch(`${BASE_URL}actions/loans-action.php`, {method:'POST',body:fd});
        const data = await res.json();
        showLoansFlash('review-flash', data.message, data.success);
        if (data.success) setTimeout(() => { closeReview(); location.reload(); }, 1200);
    } catch { showLoansFlash('review-flash','Network error.',false); }
}

// ─── Loan Details Modal ───────────────────────────────────────────────────────
function openLoanDetails(loanId) {
    document.getElementById('detailsOverlay').style.display='flex';
    document.getElementById('detailsBody').innerHTML='<div class="loading-state"><i class="fa fa-spinner fa-spin"></i> Loading…</div>';

    fetch(`${BASE_URL}actions/loans-action.php?action=get_details&loan_id=${loanId}`)
        .then(r=>r.json())
        .then(data => {
            if (!data.success) { document.getElementById('detailsBody').innerHTML=`<p style="color:red">${esc(data.message)}</p>`; return; }
            buildDetailsContent(data.loan, data.schedule, data.payment_log || []);
        })
        .catch(()=>{ document.getElementById('detailsBody').innerHTML='<p style="color:red">Network error.</p>'; });
}

function closeDetails() { document.getElementById('detailsOverlay').style.display='none'; }

function buildDetailsContent(loan, schedule, paymentLog) {
    const paid   = parseFloat(loan.total_amount) - parseFloat(loan.balance_amount);
    const pct    = parseFloat(loan.total_amount) > 0 ? Math.round(paid/parseFloat(loan.total_amount)*100) : 0;
    const init   = loanInitials(loan.employee_name);
    const auto   = parseFloat(loan.monthly_deduction) / 2;
    const status = (loan.status || '').toUpperCase();

    const approver = loan.approved_by_name
        ? `Verified by <strong>${esc(loan.approved_by_name)}</strong> on ${fmt(loan.approved_at)}`
        : '';

    // Payment schedule (monthly)
    const schedHtml = schedule.map(s => `
      <tr>
        <td><strong>Month ${s.month}</strong></td>
        <td>${s.date}</td>
        <td>${peso(s.amount)}</td>
        <td><span class="sched-status sched-status--${s.status}">${
          s.status === 'paid'     ? '<i class="fa fa-circle-check"></i> Paid' :
          s.status === 'due'      ? '<i class="fa fa-clock"></i> Due' : 'Upcoming'
        }</span></td>
      </tr>`).join('');

    // Payment log (actual recorded payments)
    const payLogHtml = paymentLog.length
        ? paymentLog.map(p => `
          <tr>
            <td>${fmt(p.payment_date)}</td>
            <td>${peso(p.amount)}</td>
            <td><span style="font-size:11px;background:#f1f5f9;padding:2px 6px;border-radius:4px;">${esc(p.payment_channel||'MANUAL')}</span></td>
            <td style="font-size:12px;color:#64748b">${esc(p.receipt_number||'—')}</td>
            <td style="font-size:12px;color:#64748b">${esc(p.notes||'—')}</td>
            <td style="font-size:12px;color:#94a3b8">${esc(p.recorded_by_name||'—')}</td>
          </tr>`).join('')
        : `<tr><td colspan="6" style="text-align:center;color:#94a3b8;padding:14px">No payment records yet.</td></tr>`;

    // Action buttons depend on loan status
    const actionBtns = buildActionButtons(loan);

    document.getElementById('detailsBody').innerHTML = `
      <div class="detail-loan-id">
        Loan Record: <strong>${esc(loan.account_reference || 'LN-' + String(loan.loan_id).padStart(7,'0'))}</strong>
        <span class="loan-status-badge loan-status--${status.toLowerCase()}" style="margin-left:8px">${esc(loanStatusLabel(status))}</span>
      </div>

      <div class="review-emp-card">
        <div class="emp-avatar">${init}</div>
        <div>
          <strong>${esc(loan.employee_name)}</strong>
          <span>${esc(loan.loan_name)} — ${esc(loan.provider_name||'')}</span>
          <small style="color:#94a3b8;font-size:11px;">${approver}</small>
          ${loan.return_reason ? `<div style="margin-top:6px;padding:6px 10px;background:#fef3c7;border:1px solid #fcd34d;border-radius:6px;font-size:12px;color:#92400e;"><i class="fa fa-rotate-left"></i> <strong>Returned for correction:</strong> ${esc(loan.return_reason)}</div>` : ''}
        </div>
      </div>

      <div class="detail-stats">
        <div>
          <small>ORIGINAL AMOUNT</small>
          <strong>${peso(loan.total_amount)}</strong>
          <span>Start: ${fmt(loan.start_date)}</span>
          <span>Term: ~${schedule.length} months</span>
        </div>
        <div>
          <small>AMOUNT PAID</small>
          <strong class="text-teal">${peso(paid)}</strong>
          <span>Remaining: <strong style="color:#ef4444">${peso(loan.balance_amount)}</strong></span>
          <div class="progress-bar-wrap" style="margin-top:6px">
            <div class="progress-bar-fill" style="width:${pct}%"></div>
          </div>
          <span>${pct}% paid</span>
        </div>
        <div>
          <small>MONTHLY AMORTIZATION</small>
          <strong>${peso(loan.monthly_deduction)}</strong>
          <small style="color:#0369a1;display:block;margin-top:3px;">Auto-deduction: <strong>${peso(auto)}</strong>/payroll</small>
          <span>End: ${fmt(loan.end_date)}</span>
        </div>
      </div>

      <!-- Payment History (actual recorded payments) -->
      <div class="sched-label" style="margin-top:14px">PAYMENT HISTORY &amp; AUDIT LOG</div>
      <div class="sched-table-wrap">
        <table class="sched-table">
          <thead><tr><th>Date</th><th>Amount</th><th>Channel</th><th>Receipt #</th><th>Notes</th><th>Recorded By</th></tr></thead>
          <tbody>${payLogHtml}</tbody>
        </table>
      </div>

      <!-- Monthly Schedule (synthetic projection) -->
      <div class="sched-label" style="margin-top:14px">MONTHLY AMORTIZATION SCHEDULE</div>
      <div class="sched-table-wrap">
        <table class="sched-table">
          <thead><tr><th>Month</th><th>Due Date</th><th>Amortization</th><th>Status</th></tr></thead>
          <tbody>${schedHtml}</tbody>
        </table>
      </div>

      <div id="details-flash" style="display:none" class="loan-flash"></div>
      <div id="details-action-area">${actionBtns}</div>
    `;
}

function buildActionButtons(loan) {
    const status = (loan.status || '').toUpperCase();
    const loanId = loan.loan_id;
    const monthly = parseFloat(loan.monthly_deduction) || 0;
    const endDate = loan.end_date || '';

    if (status === 'PENDING') {
        return `<div class="loan-modal-footer" style="margin:0;padding-top:16px;">
          <div style="flex:1;font-size:12px;color:#64748b;display:flex;align-items:center;gap:6px;">
            <i class="fa fa-circle-info" style="color:#2563eb;"></i>
            Awaiting Principal review — deductions not active
          </div>
          <button class="btn-outline btn-sm" onclick="cancelLoan(${loanId})" style="color:#64748b;border-color:#cbd5e1">
            <i class="fa fa-ban"></i> Cancel Submission
          </button>
        </div>`;
    }
    if (status === 'RETURNED') {
        return `<div class="loan-modal-footer" style="margin:0;padding-top:16px;">
          <div style="flex:1;font-size:12px;color:#b45309;display:flex;align-items:center;gap:6px;">
            <i class="fa fa-rotate-left" style="color:#f59e0b;"></i>
            Returned for correction — check return reason and resubmit or cancel
          </div>
          <button class="btn-outline btn-sm" onclick="cancelLoan(${loanId})" style="color:#64748b;border-color:#cbd5e1">
            <i class="fa fa-ban"></i> Cancel
          </button>
        </div>`;
    }
    if (status === 'ACTIVE') {
        return `<div class="loan-modal-footer" style="margin:0;padding-top:16px;flex-wrap:wrap;gap:8px;">
          <button class="btn-outline btn-sm" onclick="showRecordPayment(${loanId},${monthly})">
            <i class="fa fa-money-bill"></i> Record Manual Payment
          </button>
          <button class="btn-outline btn-sm" onclick="showAdjustTerms(${loanId},${monthly},'${endDate}')">
            <i class="fa fa-sliders"></i> Adjust Loan Terms
          </button>
          <button class="btn-outline btn-sm" onclick="pauseLoan(${loanId})" style="color:#f59e0b;border-color:#f59e0b">
            <i class="fa fa-pause"></i> Pause Deduction
          </button>
          <button class="btn-outline btn-sm" onclick="cancelLoan(${loanId})" style="color:#64748b;border-color:#cbd5e1">
            <i class="fa fa-ban"></i> Cancel Loan
          </button>
          <button class="btn-outline btn-sm btn-danger-outline" onclick="closeLoanPaid(${loanId})">
            <i class="fa fa-flag-checkered"></i> Close Loan (Paid)
          </button>
        </div>`;
    }
    if (status === 'PAUSED') {
        return `<div class="loan-modal-footer" style="margin:0;padding-top:16px;flex-wrap:wrap;gap:8px;">
          <button class="btn-outline btn-sm" onclick="showRecordPayment(${loanId},${monthly})">
            <i class="fa fa-money-bill"></i> Record Manual Payment
          </button>
          <button class="btn-outline btn-sm" onclick="resumeLoan(${loanId})" style="color:#059669;border-color:#059669">
            <i class="fa fa-play"></i> Resume Deduction
          </button>
          <button class="btn-outline btn-sm" onclick="cancelLoan(${loanId})" style="color:#64748b;border-color:#cbd5e1">
            <i class="fa fa-ban"></i> Cancel Loan
          </button>
          <button class="btn-outline btn-sm btn-danger-outline" onclick="closeLoanPaid(${loanId})">
            <i class="fa fa-flag-checkered"></i> Close Loan (Paid)
          </button>
        </div>`;
    }
    if (status === 'COMPLETED' || status === 'CANCELLED' || status === 'DENIED' || status === 'RETURNED') {
        return `<div class="loan-modal-footer" style="margin:0;padding-top:16px;">
          <button class="btn-outline btn-sm" onclick="archiveLoan(${loanId})" style="color:#64748b">
            <i class="fa fa-box-archive"></i> Archive Record
          </button>
        </div>`;
    }
    return '';
}

// ─── Record Manual Payment (sub-form) ────────────────────────────────────────
function showRecordPayment(loanId, suggested) {
    const area = document.getElementById('details-action-area');
    area.innerHTML = `
      <div class="action-subform">
        <strong style="font-size:13px"><i class="fa fa-money-bill" style="color:#059669"></i> Record Manual Payment</strong>
        <div class="lf-row" style="margin-top:10px;">
          <div class="lf-field">
            <label>Payment Date <span class="req">*</span></label>
            <input type="date" id="pay-date" value="${new Date().toISOString().slice(0,10)}">
          </div>
          <div class="lf-field">
            <label>Amount <span class="req">*</span></label>
            <div class="peso-input"><span>₱</span>
              <input type="number" id="pay-amount" value="${parseFloat(suggested).toFixed(2)}" min="0" step="0.01">
            </div>
          </div>
        </div>
        <div class="lf-row">
          <div class="lf-field">
            <label>Payment Channel</label>
            <select id="pay-channel">
              <option value="MANUAL">Manual / Cash</option>
              <option value="BANK_DEPOSIT">Bank Deposit</option>
              <option value="ONLINE_TRANSFER">Online Transfer</option>
              <option value="CHECK">Check</option>
              <option value="OTHER">Other</option>
            </select>
          </div>
          <div class="lf-field">
            <label>Receipt / Reference #</label>
            <input type="text" id="pay-receipt" placeholder="Optional">
          </div>
        </div>
        <div class="lf-field" style="margin-top:4px;">
          <label>Notes</label>
          <input type="text" id="pay-notes" placeholder="Optional notes">
        </div>
        <div class="loan-modal-footer" style="margin:0;padding-top:10px;">
          <button class="btn-outline btn-sm" onclick="openLoanDetails(${loanId})">Cancel</button>
          <button class="btn-primary btn-sm" onclick="submitRecordPayment(${loanId})">
            <i class="fa fa-save"></i> Record Payment
          </button>
        </div>
      </div>`;
}

async function submitRecordPayment(loanId) {
    const amount  = parseFloat(document.getElementById('pay-amount')?.value||0)||0;
    const date    = document.getElementById('pay-date')?.value || new Date().toISOString().slice(0,10);
    const channel = document.getElementById('pay-channel')?.value || 'MANUAL';
    const receipt = document.getElementById('pay-receipt')?.value || '';
    const notes   = document.getElementById('pay-notes')?.value || '';
    if (!amount) return;

    const fd = new FormData();
    fd.append('action','record_payment'); fd.append('loan_id',loanId);
    fd.append('amount',amount); fd.append('payment_date',date);
    fd.append('payment_channel',channel); fd.append('receipt_number',receipt);
    fd.append('notes',notes);

    try {
        const res  = await fetch(`${BASE_URL}actions/loans-action.php`,{method:'POST',body:fd});
        const data = await res.json();
        showLoansFlash('details-flash',data.message,data.success);
        if (data.success) setTimeout(()=>{ closeDetails(); location.reload(); },1200);
    } catch { showLoansFlash('details-flash','Network error.',false); }
}

// ─── Adjust Loan Terms (sub-form) ────────────────────────────────────────────
function showAdjustTerms(loanId, monthly, endDate) {
    const area = document.getElementById('details-action-area');
    area.innerHTML = `
      <div class="action-subform">
        <strong style="font-size:13px"><i class="fa fa-sliders" style="color:#0369a1"></i> Adjust Loan Terms</strong>
        <div class="lf-row" style="margin-top:10px;">
          <div class="lf-field">
            <label>Monthly Amortization <span class="req">*</span></label>
            <div class="peso-input"><span>₱</span>
              <input type="number" id="adj-monthly" value="${parseFloat(monthly).toFixed(2)}" min="0" step="0.01">
            </div>
          </div>
          <div class="lf-field">
            <label>End Date</label>
            <input type="date" id="adj-end" value="${endDate||''}">
          </div>
        </div>
        <div class="loan-modal-footer" style="margin:0;padding-top:10px;">
          <button class="btn-outline btn-sm" onclick="openLoanDetails(${loanId})">Cancel</button>
          <button class="btn-primary btn-sm" onclick="submitAdjust(${loanId})">
            <i class="fa fa-save"></i> Save Changes
          </button>
        </div>
      </div>`;
}

async function submitAdjust(loanId) {
    const monthly = document.getElementById('adj-monthly')?.value;
    const endDate = document.getElementById('adj-end')?.value;
    const fd = new FormData();
    fd.append('action','adjust'); fd.append('loan_id',loanId);
    fd.append('monthly_deduction',monthly); fd.append('end_date',endDate);
    try {
        const res  = await fetch(`${BASE_URL}actions/loans-action.php`,{method:'POST',body:fd});
        const data = await res.json();
        showLoansFlash('details-flash',data.message,data.success);
        if (data.success) setTimeout(()=>{ closeDetails(); location.reload(); },1200);
    } catch { showLoansFlash('details-flash','Network error.',false); }
}

// ─── Pause / Resume / Cancel / Archive / Close ───────────────────────────────
async function pauseLoan(loanId) {
    try {
        await GEI.confirm({
            title: 'Pause Loan Deduction',
            message: 'Deductions for this loan will be suspended until manually resumed.',
            type: 'warning', confirmText: 'Pause Deduction',
        });
    } catch { return; }
    const fd = new FormData(); fd.append('action','pause'); fd.append('loan_id',loanId);
    await _loanPost(fd, 'details-flash');
}

async function resumeLoan(loanId) {
    try {
        await GEI.confirm({
            title: 'Resume Loan Deduction',
            message: 'Deductions will resume on the next payroll run.',
            type: 'info', confirmText: 'Resume',
        });
    } catch { return; }
    const fd = new FormData(); fd.append('action','resume'); fd.append('loan_id',loanId);
    await _loanPost(fd, 'details-flash');
}

async function cancelLoan(loanId) {
    let reason = '';
    try {
        reason = await GEI.prompt({
            title: 'Cancel Loan Record',
            message: 'Provide a reason for cancellation (required):',
            placeholder: 'Reason for cancellation…',
        });
    } catch { return; }
    if (!reason || !reason.trim()) {
        showLoansFlash('details-flash', 'Cancellation reason is required.', false);
        return;
    }
    const fd = new FormData();
    fd.append('action','cancel'); fd.append('loan_id',loanId); fd.append('cancelled_reason',reason);
    await _loanPost(fd, 'details-flash');
}

async function archiveLoan(loanId) {
    try {
        await GEI.confirm({
            title: 'Archive Loan Record',
            message: 'The loan record will be moved to the archive. It remains searchable in Loan History.',
            type: 'info', confirmText: 'Archive',
        });
    } catch { return; }
    const fd = new FormData(); fd.append('action','archive'); fd.append('loan_id',loanId);
    await _loanPost(fd, 'details-flash');
}

async function closeLoanPaid(loanId) {
    try {
        await GEI.confirm({
            title: 'Close Loan (Paid)',
            message: 'This sets the outstanding balance to ₱0.00 and marks the loan as fully paid. Any remaining balance will be written off.',
            note: 'This action cannot be undone.',
            type: 'warning', confirmText: 'Close Loan',
        });
    } catch { return; }
    const fd = new FormData(); fd.append('action','complete'); fd.append('loan_id',loanId);
    await _loanPost(fd, 'details-flash');
}

async function _loanPost(fd, flashId) {
    try {
        const res  = await fetch(`${BASE_URL}actions/loans-action.php`,{method:'POST',body:fd});
        const data = await res.json();
        showLoansFlash(flashId, data.message, data.success);
        if (data.success) setTimeout(()=>{ closeDetails(); location.reload(); },1200);
    } catch { showLoansFlash(flashId,'Network error.',false); }
}

// ─── Helpers ─────────────────────────────────────────────────────────────────
function loanInitials(name) {
    const p = String(name||'').trim().split(' ');
    return (p[0]?.[0]||'') + (p[p.length-1]?.[0]||'');
}

function showLoansFlash(elId, msg, ok) {
    const el = document.getElementById(elId);
    if (!el) return;
    el.style.cssText = `display:block;padding:10px 14px;border-radius:8px;font-size:13px;margin-top:10px;
        ${ok?'background:#d1fae5;color:#065f46;border:1px solid #6ee7b7':'background:#fee2e2;color:#991b1b;border:1px solid #fca5a5'}`;
    el.textContent = msg;
}
