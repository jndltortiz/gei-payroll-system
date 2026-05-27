/**
 * principal-loan-approval.js
 * Principal Portal — Loan Approval page JS
 *
 * Extends the shared loans.js helpers (peso, esc, fmt, loanInitials, showLoansFlash).
 * Follows the same pattern as loans.js:
 *   - openX / closeX functions per modal
 *   - fetch → build content pattern
 *   - submitX functions posting to loans-action.php
 *
 * This file is loaded AFTER loans.js so shared helpers are available.
 */
'use strict';

// ── State ────────────────────────────────────────────────────────
let _currentReviewLoan = null; // loan object being reviewed

// ═══════════════════════════════════════════════════════════════
// REVIEW MODAL  (Pending tab → Review & Approve)
// ═══════════════════════════════════════════════════════════════
function openPrincipalReview(loanId) {
    document.getElementById('principalReviewOverlay').style.display = 'flex';
    document.getElementById('principalReviewBody').innerHTML =
        '<div class="loading-state"><i class="fa fa-spinner fa-spin"></i> Loading…</div>';

    fetch(`${BASE_URL}actions/loans-action.php?action=get_review&loan_id=${loanId}`)
        .then(r => r.json())
        .then(data => {
            if (!data.success) {
                document.getElementById('principalReviewBody').innerHTML =
                    `<p style="color:red">${esc(data.message)}</p>`;
                return;
            }
            _currentReviewLoan = data.loan;
            buildPrincipalReviewContent(data.loan, data.active_loans);
        })
        .catch(() => {
            document.getElementById('principalReviewBody').innerHTML =
                '<p style="color:red">Network error. Please try again.</p>';
        });
}

function closePrincipalReview() {
    document.getElementById('principalReviewOverlay').style.display = 'none';
    _currentReviewLoan = null;
}

function buildPrincipalReviewContent(loan, activeLoans) {
    const sal   = parseFloat(loan.monthly_salary || 0);
    const ded   = parseFloat(loan.monthly_deduction || 0);
    const pct   = sal > 0 ? (ded / sal * 100).toFixed(1) : 0;
    const init  = loanInitials(loan.employee_name);
    const term  = ded > 0 ? Math.ceil(parseFloat(loan.total_payable || loan.total_amount) / ded) : 0;

    // Net pay after loan deduction (using monthly_salary as proxy for net pay)
    // In a full system this would come from the latest payslip
    const currentNet  = sal;
    const afterDeduct = sal - ded;
    const firstPay    = loan.start_date
        ? new Date(loan.start_date).toLocaleDateString('en-PH', { year:'numeric', month:'long', day:'numeric' })
        : '—';

    const pctSalClass = parseFloat(pct) > 20 ? 'elig-item--warn' : 'elig-item--ok';
    const pctSalIcon  = parseFloat(pct) > 20 ? 'fa-triangle-exclamation' : 'fa-circle-check';

    const activeLoanHtml = activeLoans.length
        ? activeLoans.map(l =>
            `<div class="review-active-loan">
               <span>${esc(l.loan_name)}</span>
               <strong>${peso(l.balance_amount)} remaining</strong>
             </div>`).join('')
        : '<div class="review-no-loans">No other active loans</div>';

    const _reviewLoanId = loan.loan_id;
    document.getElementById('principalReviewBody').innerHTML = `
      <!-- App ID + status badge -->
      <div class="review-app-id" style="display:flex;align-items:center;justify-content:space-between;flex-wrap:wrap;gap:8px;margin-bottom:14px;">
        <span>Application ID: <strong>${esc(loan.account_reference || 'LA-' + String(loan.loan_id).padStart(7,'0'))}</strong></span>
        <span class="pla-badge pla-badge--pending">Awaiting Verification</span>
      </div>

      <!-- Employee card -->
      <div class="review-emp-card">
        <div class="emp-avatar">${init}</div>
        <div>
          <strong>${esc(loan.employee_name)}</strong>
          <span style="font-size:11px;color:#94a3b8;font-weight:600;">${empId(loan.employee_id)}</span>
          <span>${esc(loan.position_name||'')} &bull; ${esc(loan.department_name||'')}</span>
          <div class="review-emp-meta">
            <span>Employed since: <strong>${fmt(loan.hire_date)}</strong></span>
            <span>Current salary: <strong>${peso(loan.monthly_salary)}/month</strong></span>
          </div>
        </div>
      </div>

      <!-- Loan details grid (matches screenshot layout) -->
      <div class="review-grid">
        <div><small>LOAN TYPE</small><strong>${esc(loan.loan_name)}</strong></div>
        <div><small>MONTHLY AMORTIZATION</small><strong class="text-teal">${peso(loan.monthly_deduction)}</strong></div>
        <div><small>AMOUNT REQUESTED</small><strong style="font-size:1.25rem">${peso(loan.total_amount)}</strong></div>
        <div><small>PERCENTAGE OF SALARY</small><strong>${pct}%</strong></div>
        <div><small>REQUESTED TERMS</small><strong>${term} months</strong></div>
        <div><small>TOTAL PAYABLE</small><strong>${peso(loan.total_payable || loan.total_amount)}</strong></div>
        ${loan.reason
            ? `<div class="review-grid-full">
                 <small>PURPOSE / REASON</small>
                 <div style="background:#f3f4f6;border-radius:8px;padding:8px 10px;font-size:13px;line-height:1.5;margin-top:4px;">
                   ${esc(loan.reason)}
                 </div>
               </div>`
            : ''}
        <div><small>FIRST DEDUCTION DATE</small><strong>${firstPay}</strong></div>
      </div>

      <!-- Financial Impact Assessment -->
      <div class="eligibility-box pla-fia-box">
        <div class="elig-header">
          <i class="fa fa-triangle-exclamation"></i> Financial Impact Assessment
        </div>
        <div style="display:flex;justify-content:space-between;font-size:13px;padding:5px 0;border-bottom:1px solid #fde68a;">
          <span>Current Net Pay:</span><span>${peso(currentNet)}</span>
        </div>
        <div style="display:flex;justify-content:space-between;font-size:13px;padding:5px 0;border-bottom:1px solid #fde68a;">
          <span>After Loan Deduction:</span><span>${peso(afterDeduct)}</span>
        </div>
        <div style="display:flex;justify-content:space-between;font-size:13px;font-weight:600;padding:5px 0;">
          <span>Reduction in Take-Home:</span>
          <span style="color:#dc2626">${peso(ded)}/month</span>
        </div>
      </div>

      <!-- Eligibility -->
      <div class="eligibility-box">
        <div class="elig-header"><i class="fa fa-circle-check"></i> Eligibility Check</div>
        <div class="elig-item elig-item--ok">
          <i class="fa fa-circle-check"></i> Loan paperwork received from employee
        </div>
        <div class="elig-item ${pctSalClass}">
          <i class="fa ${pctSalIcon}"></i>
          Monthly amortization is ${pct}% of salary${parseFloat(pct) > 20 ? ' — exceeds recommended 20%' : ''}
        </div>
      </div>

      <!-- Active loans -->
      <div>
        <div class="review-section-label">ACTIVE LOANS STATUS</div>
        <div class="review-active-loans-box">${activeLoanHtml}</div>
      </div>

      <!-- Attached Documents -->
      <div>
        <div class="review-section-label">ATTACHED DOCUMENTS</div>
        <div id="principal-review-docs-area" style="min-height:30px;">
          <div style="font-size:12px;color:#94a3b8;"><i class="fa fa-spinner fa-spin"></i> Loading…</div>
        </div>
      </div>

      <!-- Principal notes -->
      <div>
        <div class="review-section-label">PRINCIPAL NOTES (OPTIONAL)</div>
        <textarea id="principal-review-notes" rows="2"
                  placeholder="Add your comments about this decision…"
                  style="width:100%;padding:10px;border:1px solid #e5e7eb;border-radius:8px;font-size:13px;resize:vertical;"></textarea>
      </div>

      <div id="principal-review-flash" style="display:none" class="loan-flash"></div>

      <!-- Action buttons -->
      <div class="loan-modal-footer" style="margin:0;padding-top:16px;flex-wrap:wrap;gap:8px;">
        <button class="btn-deny" onclick="openDenyReason(${_reviewLoanId},'${esc(loan.employee_name)}')">
          <i class="fa fa-times"></i> Reject Record
        </button>
        <button class="btn-outline" onclick="openReturnForCorrection(${_reviewLoanId},'${esc(loan.employee_name)}')"
                style="color:#b45309;border-color:#f59e0b;background:#fffbeb;">
          <i class="fa fa-rotate-left"></i> Return for Correction
        </button>
        <button class="btn-approve" onclick="openConfirmApprove(${_reviewLoanId})">
          <i class="fa fa-check"></i> Approve for Payroll Deduction
        </button>
      </div>
    `;

    // Load documents into review modal
    _loadPrincipalDocs(_reviewLoanId, 'principal-review-docs-area');
}

// ═══════════════════════════════════════════════════════════════
// DENY FLOW  (opens sub-modal for reason input)
// ═══════════════════════════════════════════════════════════════
let _denyLoanId = null;

function openDenyReason(loanId, empName) {
    _denyLoanId = loanId;
    document.getElementById('deny-emp-name-display').textContent = empName;
    document.getElementById('principal-deny-reason').value = '';
    document.getElementById('deny-reason-error').style.display = 'none';
    document.getElementById('deny-reason-flash').style.display = 'none';

    // Keep review modal open underneath; raise z-index of deny modal
    document.getElementById('denyReasonOverlay').style.display = 'flex';
}

function closeDenyReason() {
    document.getElementById('denyReasonOverlay').style.display = 'none';
    _denyLoanId = null;
}

async function confirmPrincipalDeny() {
    const reason = document.getElementById('principal-deny-reason').value.trim();
    if (!reason) {
        document.getElementById('deny-reason-error').style.display = 'block';
        return;
    }
    document.getElementById('deny-reason-error').style.display = 'none';

    const btn = document.getElementById('confirm-deny-btn');
    btn.disabled = true;
    btn.innerHTML = '<i class="fa fa-spinner fa-spin"></i> Processing…';

    const fd = new FormData();
    fd.append('action', 'deny');
    fd.append('loan_id', _denyLoanId);
    fd.append('denied_reason', reason);

    try {
        const res  = await fetch(`${BASE_URL}actions/loans-action.php`, { method: 'POST', body: fd });
        const data = await res.json();
        showLoansFlash('deny-reason-flash', data.message, data.success);
        if (data.success) {
            setTimeout(() => {
                closeDenyReason();
                closePrincipalReview();
                location.reload();
            }, 1200);
        } else {
            btn.disabled = false;
            btn.innerHTML = '<i class="fa fa-times"></i> Confirm Rejection';
        }
    } catch {
        showLoansFlash('deny-reason-flash', 'Network error. Please try again.', false);
        btn.disabled = false;
        btn.innerHTML = '<i class="fa fa-times"></i> Confirm Rejection';
    }
}

// ═══════════════════════════════════════════════════════════════
// RETURN FOR CORRECTION FLOW  (opens sub-modal for reason input)
// ═══════════════════════════════════════════════════════════════
let _returnLoanId = null;

function openReturnForCorrection(loanId, empName) {
    _returnLoanId = loanId;
    document.getElementById('return-emp-name-display').textContent = empName;
    document.getElementById('principal-return-reason').value = '';
    document.getElementById('return-reason-error').style.display = 'none';
    document.getElementById('return-reason-flash').style.display = 'none';
    document.getElementById('returnForCorrectionOverlay').style.display = 'flex';
}

function closeReturnForCorrection() {
    document.getElementById('returnForCorrectionOverlay').style.display = 'none';
    _returnLoanId = null;
}

async function confirmPrincipalReturn() {
    const reason = document.getElementById('principal-return-reason').value.trim();
    if (!reason) {
        document.getElementById('return-reason-error').style.display = 'block';
        return;
    }
    document.getElementById('return-reason-error').style.display = 'none';

    const btn = document.getElementById('confirm-return-btn');
    btn.disabled = true;
    btn.innerHTML = '<i class="fa fa-spinner fa-spin"></i> Processing…';

    const fd = new FormData();
    fd.append('action', 'return_for_correction');
    fd.append('loan_id', _returnLoanId);
    fd.append('return_reason', reason);

    try {
        const res  = await fetch(`${BASE_URL}actions/loans-action.php`, { method: 'POST', body: fd });
        const data = await res.json();
        showLoansFlash('return-reason-flash', data.message, data.success);
        if (data.success) {
            setTimeout(() => {
                closeReturnForCorrection();
                closePrincipalReview();
                location.reload();
            }, 1200);
        } else {
            btn.disabled = false;
            btn.innerHTML = '<i class="fa fa-rotate-left"></i> Return for Correction';
        }
    } catch {
        showLoansFlash('return-reason-flash', 'Network error. Please try again.', false);
        btn.disabled = false;
        btn.innerHTML = '<i class="fa fa-rotate-left"></i> Return for Correction';
    }
}

// ═══════════════════════════════════════════════════════════════
// APPROVE FLOW  (opens confirm sub-modal)
// ═══════════════════════════════════════════════════════════════
let _approveLoanId = null;

function openConfirmApprove(loanId) {
    _approveLoanId = loanId;
    const loan = _currentReviewLoan;
    if (!loan) return;

    const term = loan.monthly_deduction > 0
        ? Math.ceil(parseFloat(loan.total_payable || loan.total_amount) / parseFloat(loan.monthly_deduction)) : 0;

    document.getElementById('ca-loan-type').textContent = loan.loan_name || '—';
    document.getElementById('ca-emp-name').textContent  = loan.employee_name || '—';
    document.getElementById('ca-amount').textContent    = peso(loan.total_amount);
    document.getElementById('ca-monthly').textContent   = peso(loan.monthly_deduction) + '/month';
    document.getElementById('ca-term').textContent      = term + ' months';
    document.getElementById('confirm-approve-flash').style.display = 'none';

    document.getElementById('confirmApproveOverlay').style.display = 'flex';
}

function closeConfirmApprove() {
    document.getElementById('confirmApproveOverlay').style.display = 'none';
    _approveLoanId = null;
}

async function submitPrincipalApprove() {
    const notes = document.getElementById('principal-review-notes')?.value || '';

    const btn = document.getElementById('confirm-approve-btn');
    btn.disabled = true;
    btn.innerHTML = '<i class="fa fa-spinner fa-spin"></i> Approving…';

    const fd = new FormData();
    fd.append('action', 'approve');
    fd.append('loan_id', _approveLoanId);
    fd.append('notes', notes);

    try {
        const res  = await fetch(`${BASE_URL}actions/loans-action.php`, { method: 'POST', body: fd });
        const data = await res.json();
        showLoansFlash('confirm-approve-flash', data.message, data.success);
        if (data.success) {
            setTimeout(() => {
                closeConfirmApprove();
                closePrincipalReview();
                location.reload();
            }, 1200);
        } else {
            btn.disabled = false;
            btn.innerHTML = '<i class="fa fa-check"></i> Approve for Payroll Deduction';
        }
    } catch {
        showLoansFlash('confirm-approve-flash', 'Network error. Please try again.', false);
        btn.disabled = false;
        btn.innerHTML = '<i class="fa fa-check"></i> Approve for Payroll Deduction';
    }
}

// ═══════════════════════════════════════════════════════════════
// DETAILS MODAL  (Active Loans tab → View)
// Read-only — principal cannot edit loan terms
// ═══════════════════════════════════════════════════════════════
function openPrincipalDetails(loanId) {
    document.getElementById('principalDetailsOverlay').style.display = 'flex';
    document.getElementById('principalDetailsBody').innerHTML =
        '<div class="loading-state"><i class="fa fa-spinner fa-spin"></i> Loading…</div>';

    fetch(`${BASE_URL}actions/loans-action.php?action=get_details&loan_id=${loanId}`)
        .then(r => r.json())
        .then(data => {
            if (!data.success) {
                document.getElementById('principalDetailsBody').innerHTML =
                    `<p style="color:red">${esc(data.message)}</p>`;
                return;
            }
            buildPrincipalDetailsContent(data.loan, data.schedule, data.payment_log || []);
        })
        .catch(() => {
            document.getElementById('principalDetailsBody').innerHTML =
                '<p style="color:red">Network error.</p>';
        });
}

function closePrincipalDetails() {
    document.getElementById('principalDetailsOverlay').style.display = 'none';
}

function buildPrincipalDetailsContent(loan, schedule, paymentLog) {
    const paid   = parseFloat(loan.total_amount) - parseFloat(loan.balance_amount);
    const pct    = parseFloat(loan.total_amount) > 0
        ? Math.round(paid / parseFloat(loan.total_amount) * 100) : 0;
    const init   = loanInitials(loan.employee_name);
    const status = (loan.status || 'ACTIVE').toUpperCase();
    const monthly = parseFloat(loan.monthly_deduction) || 0;
    const auto    = monthly / 2;

    const approver = loan.approved_by_name
        ? `Verified by <strong>${esc(loan.approved_by_name)}</strong> on ${fmt(loan.approved_at)}`
        : '<span style="color:#94a3b8">Not yet verified</span>';

    // Next deduction date estimate
    const paysMade = monthly > 0 ? Math.floor(paid / monthly) : 0;
    let nextDedDate = '—';
    if (loan.start_date && status === 'ACTIVE') {
        const nd = new Date(loan.start_date);
        nd.setMonth(nd.getMonth() + paysMade);
        nextDedDate = nd.toLocaleDateString('en-PH', {year:'numeric',month:'short',day:'numeric'});
    }

    const statusBadgeClass = {
        ACTIVE: 'pla-badge--active', PAUSED: 'pla-badge--pending',
        COMPLETED: 'pla-badge--completed', PENDING: 'pla-badge--pending',
    }[status] || 'pla-badge--muted';
    const statusLabel = {
        ACTIVE: 'Verified for Payroll Deduction', PAUSED: 'Paused',
        COMPLETED: 'Completed', PENDING: 'Pending Principal Review',
        RETURNED: 'Returned for Correction', DENIED: 'Rejected',
        CANCELLED: 'Cancelled', ARCHIVED: 'Archived',
    }[status] || status;

    const schedHtml = schedule.map(s => `
      <tr>
        <td><strong>Month ${s.month}</strong></td>
        <td>${s.date}</td>
        <td>${peso(s.amount)}</td>
        <td><span class="sched-status sched-status--${s.status}">
          ${s.status === 'paid'
              ? '<i class="fa fa-circle-check"></i> Paid'
              : s.status === 'due'
                  ? '<i class="fa fa-clock"></i> Due'
                  : 'Upcoming'}
        </span></td>
      </tr>`).join('');

    const payLogHtml = (paymentLog && paymentLog.length)
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

    document.getElementById('principalDetailsBody').innerHTML = `
      <div class="detail-loan-id" style="display:flex;align-items:center;justify-content:space-between;flex-wrap:wrap;gap:8px;margin-bottom:14px;">
        <span>Loan Record: <strong>${esc(loan.account_reference || 'LN-' + String(loan.loan_id).padStart(7,'0'))}</strong></span>
        <span class="pla-badge ${statusBadgeClass}">${esc(statusLabel)}</span>
      </div>

      <div class="review-emp-card">
        <div class="emp-avatar">${init}</div>
        <div>
          <strong>${esc(loan.employee_name)}</strong>
          <span style="font-size:11px;color:#94a3b8;font-weight:600;">${empId(loan.employee_id)}</span>
          <span>${esc(loan.position_name||'')}${loan.position_name && loan.department_name ? ' &bull; ' : ''}${esc(loan.department_name||'')}</span>
          <small style="color:#94a3b8;font-size:11px;">${approver}</small>
          ${loan.return_reason ? `<div style="margin-top:6px;padding:6px 10px;background:#fef3c7;border:1px solid #fcd34d;border-radius:6px;font-size:12px;color:#92400e;"><i class="fa fa-rotate-left"></i> <strong>Returned for correction:</strong> ${esc(loan.return_reason)}</div>` : ''}
        </div>
      </div>

      <!-- Full loan details grid -->
      <div class="review-grid" style="margin-bottom:14px;">
        <div><small>LOAN TYPE</small><strong>${esc(loan.loan_name)}</strong></div>
        <div><small>PROVIDER</small><strong>${esc(loan.provider_name||'—')}</strong></div>
        <div><small>EXTERNAL REF #</small><strong>${esc(loan.account_reference||'—')}</strong></div>
        <div><small>INTEREST RATE</small><strong>${parseFloat(loan.interest_rate||0)}% p.a.</strong></div>
        <div><small>ORIGINAL AMOUNT</small><strong>${peso(loan.total_amount)}</strong></div>
        <div><small>TOTAL PAYABLE</small><strong>${peso(loan.total_payable||loan.total_amount)}</strong></div>
        <div><small>AMOUNT PAID</small><strong class="text-teal">${peso(paid)}</strong></div>
        <div><small>REMAINING BALANCE</small><strong style="color:#ef4444">${peso(loan.balance_amount)}</strong></div>
        <div><small>MONTHLY AMORTIZATION</small><strong>${peso(monthly)}</strong></div>
        <div><small>AUTO-DEDUCTION / PAYROLL</small><strong style="color:#0369a1">${peso(auto)}</strong></div>
        <div><small>START DATE</small><strong>${fmt(loan.start_date)}</strong></div>
        <div><small>END DATE</small><strong>${fmt(loan.end_date)}</strong></div>
        <div><small>NEXT DEDUCTION DATE (EST.)</small><strong>${nextDedDate}</strong></div>
        ${loan.reason ? `<div class="review-grid-full"><small>NOTES / PURPOSE</small><p style="margin-top:4px;font-size:13px;color:#374151">${esc(loan.reason)}</p></div>` : ''}
      </div>

      <!-- Repayment progress -->
      <div style="background:#f9fafb;border:1px solid #e5e7eb;border-radius:10px;padding:14px 16px;margin-bottom:14px;">
        <div style="display:flex;justify-content:space-between;font-size:12px;color:#64748b;margin-bottom:6px;">
          <span>${peso(paid)} paid</span><span>${peso(loan.total_amount)} total</span>
        </div>
        <div class="progress-bar-wrap">
          <div class="progress-bar-fill" style="width:${pct}%"></div>
        </div>
        <div style="font-size:12px;color:#059669;font-weight:600;margin-top:4px;">${pct}% paid off — ${schedule.length} month term</div>
      </div>

      <!-- Payment history -->
      <div class="sched-label" style="margin-top:14px">PAYMENT HISTORY &amp; AUDIT LOG</div>
      <div class="sched-table-wrap">
        <table class="sched-table">
          <thead><tr><th>Date</th><th>Amount</th><th>Channel</th><th>Receipt #</th><th>Notes</th><th>Recorded By</th></tr></thead>
          <tbody>${payLogHtml}</tbody>
        </table>
      </div>

      <!-- Amortization schedule -->
      <div class="sched-label" style="margin-top:14px">MONTHLY AMORTIZATION SCHEDULE</div>
      <div class="sched-table-wrap">
        <table class="sched-table">
          <thead><tr><th>Month</th><th>Due Date</th><th>Amortization</th><th>Status</th></tr></thead>
          <tbody>${schedHtml}</tbody>
        </table>
      </div>

      <!-- Attached Documents (read-only) -->
      <div class="sched-label" style="margin-top:14px">ATTACHED DOCUMENTS</div>
      <div id="principal-details-docs-area" style="min-height:30px;">
        <div style="font-size:12px;color:#94a3b8;"><i class="fa fa-spinner fa-spin"></i> Loading…</div>
      </div>

      <div class="loan-modal-footer" style="margin:0;padding-top:16px;justify-content:flex-end;">
        <button class="btn-outline" onclick="closePrincipalDetails()">Close</button>
      </div>
    `;

    // Load documents into details modal
    _loadPrincipalDocs(loan.loan_id, 'principal-details-docs-area');
}

// ─── Document viewer (read-only for principal) ───────────────────
async function _loadPrincipalDocs(loanId, containerId) {
    const area = document.getElementById(containerId);
    if (!area) return;
    try {
        const res  = await fetch(`${BASE_URL}actions/loan-document-action.php?action=list&loan_id=${loanId}`);
        const data = await res.json();
        if (!data.success) {
            area.innerHTML = `<div style="font-size:12px;color:#94a3b8;padding:4px 0;">${esc(data.message)}</div>`;
            return;
        }
        if (!data.documents || !data.documents.length) {
            area.innerHTML = `<div style="font-size:12px;color:#94a3b8;padding:4px 0;">No documents attached.</div>`;
            return;
        }
        area.innerHTML = data.documents.map(doc => `
          <div style="display:flex;align-items:center;gap:10px;padding:8px 12px;
                      border:1px solid var(--border);border-radius:8px;margin-bottom:6px;background:#fafafa;">
            <i class="fa ${doc.mime_type === 'application/pdf' ? 'fa-file-pdf' : 'fa-file-image'}"
               style="font-size:18px;color:${doc.mime_type === 'application/pdf' ? '#ef4444' : '#3b82f6'};flex-shrink:0;"></i>
            <div style="flex:1;min-width:0;">
              <a href="${esc(doc.url)}" target="_blank"
                 style="font-size:13px;font-weight:600;color:#0369a1;text-decoration:none;word-break:break-all;">
                ${esc(doc.original_name)}
              </a>
              <div style="font-size:11px;color:#94a3b8;margin-top:2px;">
                ${doc.size_kb} KB
                &bull; Uploaded ${fmt(doc.created_at)}
                ${doc.uploaded_by_name ? '&bull; by ' + esc(doc.uploaded_by_name) : ''}
                &bull; <span style="background:${doc.filed_by_role === 'EMPLOYEE' ? '#dbeafe' : '#f0fdf4'};
                              color:${doc.filed_by_role === 'EMPLOYEE' ? '#1d4ed8' : '#166534'};
                              padding:1px 5px;border-radius:3px;font-weight:600;font-size:10px;">
                  ${doc.filed_by_role === 'EMPLOYEE' ? 'EMPLOYEE' : 'ADMIN'}
                </span>
              </div>
            </div>
            <a href="${esc(doc.url)}" target="_blank" download
               title="Download"
               style="padding:6px 10px;background:#f0f9ff;border:1px solid #bae6fd;border-radius:6px;
                      color:#0369a1;font-size:12px;text-decoration:none;white-space:nowrap;flex-shrink:0;">
              <i class="fa fa-download"></i> Open
            </a>
          </div>`).join('');
    } catch {
        area.innerHTML = `<div style="font-size:12px;color:#94a3b8;">Could not load documents.</div>`;
    }
}

// ─── Close modals on overlay click ──────────────────────────────
document.addEventListener('DOMContentLoaded', () => {
    [
        ['principalReviewOverlay',       closePrincipalReview],
        ['principalDetailsOverlay',      closePrincipalDetails],
        ['denyReasonOverlay',            closeDenyReason],
        ['returnForCorrectionOverlay',   closeReturnForCorrection],
        ['confirmApproveOverlay',        closeConfirmApprove],
    ].forEach(([id, fn]) => {
        const el = document.getElementById(id);
        if (el) el.addEventListener('click', e => { if (e.target === el) fn(); });
    });
});