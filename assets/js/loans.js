/* loans.js — GEI HR System */
'use strict';

const peso = v => '₱' + (parseFloat(v)||0).toLocaleString('en-PH',{minimumFractionDigits:2,maximumFractionDigits:2});
const esc  = s => String(s||'').replace(/&/g,'&amp;').replace(/</g,'&lt;').replace(/>/g,'&gt;').replace(/"/g,'&quot;');
const fmt  = d => d ? new Date(d).toLocaleDateString('en-PH',{year:'numeric',month:'short',day:'numeric'}) : '—';

// ─── Add Loan ────────────────────────────────────────────────────────────────
function openAddLoan()  { document.getElementById('addLoanOverlay').style.display='flex'; }
function closeAddLoan() { document.getElementById('addLoanOverlay').style.display='none'; }

function updateStatusHint() {
    const isPending = document.querySelector('input[name="al_status"][value="PENDING"]')?.checked;
    const submitBtn = document.getElementById('al-submit-btn');
    if (submitBtn) {
        submitBtn.innerHTML = isPending
            ? '<i class="fa fa-clock"></i> Submit for Approval'
            : '<i class="fa fa-plus"></i> Add Loan (Active)';
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
                  + `(₱${alreadyPaid.toLocaleString('en-PH', {minimumFractionDigits:2, maximumFractionDigits:2})}) `
                  + `will automatically show as <strong>Paid</strong> in the payment schedule.`
                : `Balance matches the total — no payments recorded yet.`);
    } else {
        hint.style.display = 'none';
    }
}

function computeAddLoan() {
    const amount   = parseFloat(document.getElementById('al-amount')?.value   || 0) || 0;
    const interest = parseFloat(document.getElementById('al-interest')?.value || 0) || 0;
    const term     = parseFloat(document.getElementById('al-term')?.value     || 0) || 0;

    // Auto-fill balance field if it hasn't been manually changed
    const balField = document.getElementById('al-current-balance');
    if (balField && !balField.dataset.manuallySet) {
        balField.value = amount ? amount.toFixed(2) : '';
    }
    if (amount && term) {
        const payable = amount + (amount * (interest/100) * (term/12));
        const monthly = payable / term;
        document.getElementById('al-monthly').value       = monthly.toFixed(2);
        document.getElementById('al-total-payable').value = payable.toFixed(2);
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
            warn.textContent   = `⚠️ Monthly deduction is ${pct.toFixed(1)}% of salary — exceeds recommended 20%.`;
        } else {
            warn.style.display = 'none';
        }
    }
}

async function submitAddLoan() {
    const status = document.querySelector('input[name="al_status"]:checked')?.value || 'ACTIVE';
    const fields = {
        employee_id:       document.getElementById('al-employee')?.value,
        loan_type_id:      document.getElementById('al-type')?.value,
        total_amount:      document.getElementById('al-amount')?.value,
        monthly_deduction: document.getElementById('al-monthly')?.value,
        interest_rate:     document.getElementById('al-interest')?.value || '0',
        total_payable:     document.getElementById('al-total-payable')?.value || document.getElementById('al-amount')?.value,
        start_date:        document.getElementById('al-start')?.value,
        account_reference: document.getElementById('al-ref')?.value,
        reason:            document.getElementById('al-reason')?.value,
        status:            status,
        current_balance:   document.getElementById('al-current-balance')?.value || '',
    };

    if (!fields.employee_id || !fields.loan_type_id || !fields.total_amount || !fields.monthly_deduction || !fields.start_date) {
        showLoansFlash('al-flash', 'Please fill in all required fields.', false); return;
    }

    const btn  = document.getElementById('al-submit-btn');
    btn.disabled = true;
    btn.innerHTML = '<i class="fa fa-spinner fa-spin"></i> Adding…';

    const fd = new FormData(); fd.append('action', 'add');
    Object.entries(fields).forEach(([k,v]) => fd.append(k, v));

    try {
        const res  = await fetch(`${BASE_URL}actions/loans-action.php`, {method:'POST', body:fd});
        const data = await res.json();
        showLoansFlash('al-flash', data.message, data.success);
        if (data.success) setTimeout(() => { closeAddLoan(); location.reload(); }, 1200);
        else { btn.disabled=false; btn.innerHTML='<i class="fa fa-plus"></i> Add Loan'; }
    } catch {
        showLoansFlash('al-flash', 'Network error.', false);
        btn.disabled=false; btn.innerHTML='<i class="fa fa-plus"></i> Add Loan';
    }
}

// ─── Review Modal ─────────────────────────────────────────────────────────────
function openReview(loanId) {
    document.getElementById('reviewOverlay').style.display = 'flex';
    document.getElementById('reviewBody').innerHTML = '<div class="loading-state"><i class="fa fa-spinner fa-spin"></i> Loading…</div>';
    document.querySelector('#reviewOverlay .loan-modal-header h3').textContent = 'Confirm Loan Entry';

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
    const sal = parseFloat(loan.monthly_salary || 0);
    const ded = parseFloat(loan.monthly_deduction || 0);
    const pct = sal > 0 ? (ded/sal*100).toFixed(1) : 0;
    const init= loanInitials(loan.employee_name);
    const term= loan.monthly_deduction > 0 ? Math.ceil(parseFloat(loan.total_payable||loan.total_amount)/parseFloat(loan.monthly_deduction)) : 0;
    const firstPay = loan.start_date ? new Date(loan.start_date).toLocaleDateString('en-PH',{year:'numeric',month:'long',day:'numeric'}) : '—';

    const activeLoanHtml = activeLoans.length
        ? activeLoans.map(l => `<div class="review-active-loan"><span>${esc(l.loan_name)}</span><strong>${peso(l.balance_amount)} remaining</strong></div>`).join('')
        : '<div class="review-no-loans">No active loans</div>';

    const eligWarn = pct > 20
        ? `<div class="elig-item elig-item--warn"><i class="fa fa-triangle-exclamation"></i> Monthly deduction is ${pct}% of salary</div>`
        : `<div class="elig-item elig-item--ok"><i class="fa fa-circle-check"></i> Monthly deduction is ${pct}% of salary</div>`;

    document.getElementById('reviewBody').innerHTML = `
      <div class="review-app-id">Application ID: <strong>${esc(loan.account_reference || 'LA-' + String(loan.loan_id).padStart(7,'0'))}</strong></div>

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
        <div><small>MONTHLY DEDUCTION</small><strong class="text-teal">${peso(loan.monthly_deduction)}</strong></div>
        <div><small>AMOUNT REQUESTED</small><strong>${peso(loan.total_amount)}</strong></div>
        <div><small>INTEREST RATE</small><strong>${parseFloat(loan.interest_rate||0)}% per annum</strong></div>
        <div><small>REQUESTED TERMS</small><strong>${term} months</strong></div>
        <div><small>TOTAL PAYABLE</small><strong>${peso(loan.total_payable||loan.total_amount)}</strong></div>
        ${loan.reason ? `<div class="review-grid-full"><small>PURPOSE / REASON</small><p>${esc(loan.reason)}</p></div>` : ''}
        <div><small>FIRST PAYMENT</small><strong>${firstPay}</strong></div>
      </div>

      <div class="eligibility-box">
        <div class="elig-header"><i class="fa fa-circle-check"></i> Eligibility Check</div>
        <div class="elig-item elig-item--ok"><i class="fa fa-circle-check"></i> Loan paperwork received from employee</div>
        <div class="elig-item elig-item--ok"><i class="fa fa-circle-check"></i> Loan was approved by external institution (SSS/HDMF/PERAA/Rural Bank)</div>
        ${eligWarn}
      </div>

      <div>
        <div class="review-section-label">ACTIVE LOANS STATUS</div>
        <div class="review-active-loans-box">${activeLoanHtml}</div>
      </div>

      <div>
        <div class="review-section-label">ADMIN NOTES (OPTIONAL)</div>
        <textarea id="review-notes" rows="2" placeholder="Add comments about this decision…" style="width:100%;padding:10px;border:1px solid #e5e7eb;border-radius:8px;font-size:13px;resize:vertical;"></textarea>
      </div>

      <div id="review-flash" style="display:none" class="loan-flash"></div>

      <div class="loan-modal-footer" style="margin:0;padding-top:16px;">
        <button class="btn-deny" onclick="submitApproval(${loan.loan_id},'deny')">
          <i class="fa fa-times"></i> Reject Entry
        </button>
        <button class="btn-approve" onclick="submitApproval(${loan.loan_id},'approve')">
          <i class="fa fa-check"></i> Confirm & Activate
        </button>
      </div>
    `;
}

async function submitApproval(loanId, decision) {
    const notes  = document.getElementById('review-notes')?.value || '';
    const actionLabel = decision === 'approve' ? 'Confirm & Activate' : 'Reject';
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
            buildDetailsContent(data.loan, data.schedule);
        })
        .catch(()=>{ document.getElementById('detailsBody').innerHTML='<p style="color:red">Network error.</p>'; });
}

function closeDetails() { document.getElementById('detailsOverlay').style.display='none'; }

function buildDetailsContent(loan, schedule) {
    const paid    = parseFloat(loan.total_amount) - parseFloat(loan.balance_amount);
    const pct     = parseFloat(loan.total_amount) > 0 ? Math.round(paid/parseFloat(loan.total_amount)*100) : 0;
    const init    = loanInitials(loan.employee_name);
    const approver= loan.approved_by_name ? `Approved by <strong>${esc(loan.approved_by_name)}</strong> on ${fmt(loan.approved_at)}` : '';

    const schedHtml = schedule.map(s => `
      <tr>
        <td><strong>Month ${s.month}</strong></td>
        <td>${s.date}</td>
        <td>${peso(s.amount)}</td>
        <td><span class="sched-status sched-status--${s.status}">${s.status === 'paid' ? '<i class="fa fa-circle-check"></i> Paid' : s.status === 'due' ? '<i class="fa fa-clock"></i> Due' : 'Upcoming'}</span></td>
      </tr>`).join('');

    document.getElementById('detailsBody').innerHTML = `
      <div class="detail-loan-id">Loan ID: <strong>${esc(loan.account_reference || 'LN-' + String(loan.loan_id).padStart(7,'0'))}</strong></div>

      <div class="review-emp-card">
        <div class="emp-avatar">${init}</div>
        <div>
          <strong>${esc(loan.employee_name)}</strong>
          <span>${esc(loan.loan_name)}</span>
          <small style="color:#94a3b8;font-size:11px;">${approver}</small>
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
          <span>Outstanding: <strong style="color:#ef4444">${peso(loan.balance_amount)}</strong></span>
          <div class="progress-bar-wrap" style="margin-top:6px">
            <div class="progress-bar-fill" style="width:${pct}%"></div>
          </div>
          <span>${pct}% paid</span>
        </div>
        <div>
          <small>MONTHLY PAYMENT</small>
          <strong>${peso(loan.monthly_deduction)}</strong>
          <span>Next: ${fmt(loan.start_date)}</span>
          <span>End: ${fmt(loan.end_date)}</span>
        </div>
      </div>

      <div class="sched-label">PAYMENT SCHEDULE</div>
      <div class="sched-table-wrap">
        <table class="sched-table">
          <thead><tr><th>Month</th><th>Payment Date</th><th>Amount</th><th>Status</th></tr></thead>
          <tbody>${schedHtml}</tbody>
        </table>
      </div>

      <div id="details-flash" style="display:none" class="loan-flash"></div>

      <div id="details-action-area">
        <div class="loan-modal-footer" style="margin:0;padding-top:16px;flex-wrap:wrap;gap:8px;">
          <button class="btn-outline btn-sm" onclick="showRecordPayment(${loan.loan_id},${loan.monthly_deduction})">Record Manual Payment</button>
          <button class="btn-outline btn-sm" onclick="showAdjustTerms(${loan.loan_id},${loan.monthly_deduction},'${loan.end_date||''}')">Adjust Loan Terms</button>
          <button class="btn-outline btn-sm btn-danger-outline" onclick="markFullyPaid(${loan.loan_id})">Mark as Fully Paid</button>
        </div>
      </div>
    `;
}

function showRecordPayment(loanId, suggested) {
    const area = document.getElementById('details-action-area');
    area.innerHTML = `
      <div class="action-subform">
        <strong>Record Manual Payment</strong>
        <div class="lf-row" style="margin-top:8px;">
          <div class="lf-field">
            <label>Amount</label>
            <div class="peso-input">
              <span>₱</span>
              <input type="number" id="pay-amount" value="${parseFloat(suggested).toFixed(2)}" min="0" step="0.01">
            </div>
          </div>
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
    const amount = parseFloat(document.getElementById('pay-amount')?.value||0)||0;
    if (!amount) return;
    const fd = new FormData(); fd.append('action','record_payment'); fd.append('loan_id',loanId); fd.append('amount',amount);
    try {
        const res  = await fetch(`${BASE_URL}actions/loans-action.php`,{method:'POST',body:fd});
        const data = await res.json();
        showLoansFlash('details-flash',data.message,data.success);
        if (data.success) setTimeout(()=>{ closeDetails(); location.reload(); },1200);
    } catch { showLoansFlash('details-flash','Network error.',false); }
}

function showAdjustTerms(loanId, monthly, endDate) {
    const area = document.getElementById('details-action-area');
    area.innerHTML = `
      <div class="action-subform">
        <strong>Adjust Loan Terms</strong>
        <div class="lf-row" style="margin-top:8px;">
          <div class="lf-field">
            <label>Monthly Deduction</label>
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
    const monthly  = document.getElementById('adj-monthly')?.value;
    const endDate  = document.getElementById('adj-end')?.value;
    const fd = new FormData(); fd.append('action','adjust'); fd.append('loan_id',loanId); fd.append('monthly_deduction',monthly); fd.append('end_date',endDate);
    try {
        const res  = await fetch(`${BASE_URL}actions/loans-action.php`,{method:'POST',body:fd});
        const data = await res.json();
        showLoansFlash('details-flash',data.message,data.success);
        if (data.success) setTimeout(()=>{ closeDetails(); location.reload(); },1200);
    } catch { showLoansFlash('details-flash','Network error.',false); }
}

async function markFullyPaid(loanId) {
    if (!confirm('Mark this loan as fully paid? This sets the balance to ₱0.00 and closes the loan.')) return;
    const fd = new FormData(); fd.append('action','complete'); fd.append('loan_id',loanId);
    try {
        const res  = await fetch(`${BASE_URL}actions/loans-action.php`,{method:'POST',body:fd});
        const data = await res.json();
        showLoansFlash('details-flash',data.message,data.success);
        if (data.success) setTimeout(()=>{ closeDetails(); location.reload(); },1200);
    } catch { showLoansFlash('details-flash','Network error.',false); }
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