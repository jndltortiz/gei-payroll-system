/**
 * assets/js/principal-payroll-approval.js
 * Handles all interactivity for the Principal Payroll Approval page.
 */

let _activePeriod      = null;
let _activePeriodLabel = '';

// ─────────────────────────────────────────────────────────────────────────────
// TOAST  (mirrors global.css .toast / #toast-container styles)
// ─────────────────────────────────────────────────────────────────────────────
function showToast(message, type = 'success') {
    let container = document.getElementById('toast-container');
    if (!container) {
        container = document.createElement('div');
        container.id = 'toast-container';
        document.body.appendChild(container);
    }
    const icons = { success: 'fa-circle-check', error: 'fa-circle-xmark',
                    warning: 'fa-triangle-exclamation', info: 'fa-circle-info' };
    const toast = document.createElement('div');
    toast.className = `toast toast--${type}`;
    toast.innerHTML = `<i class="fa ${icons[type] || icons.success}"></i>
                       <span>${message}</span>
                       <button class="toast-close" onclick="this.parentElement.remove()">&#x2715;</button>`;
    container.appendChild(toast);
    requestAnimationFrame(() => requestAnimationFrame(() => toast.classList.add('toast--show')));
    setTimeout(() => {
        toast.classList.remove('toast--show');
        setTimeout(() => toast.remove(), 280);
    }, 4500);
}

// ─────────────────────────────────────────────────────────────────────────────
// HELPERS
// ─────────────────────────────────────────────────────────────────────────────
function fmtNum(v) {
    return (parseFloat(v) || 0).toLocaleString('en-PH', {
        minimumFractionDigits: 2,
        maximumFractionDigits: 2
    });
}

function escH(s) {
    return String(s || '')
        .replace(/&/g, '&amp;')
        .replace(/</g, '&lt;')
        .replace(/>/g, '&gt;');
}

function formatPeriodLabel(start, end) {
    const s = new Date(start + 'T00:00:00');
    const e = new Date(end   + 'T00:00:00');
    const M = ['Jan','Feb','Mar','Apr','May','Jun','Jul','Aug','Sep','Oct','Nov','Dec'];
    if (s.getMonth() === e.getMonth() && s.getFullYear() === e.getFullYear()) {
        return `${M[s.getMonth()]} ${s.getDate()}–${e.getDate()}, ${e.getFullYear()}`;
    }
    return `${M[s.getMonth()]} ${s.getDate()} – ${M[e.getMonth()]} ${e.getDate()}, ${e.getFullYear()}`;
}

function showOverlay(id)   { document.getElementById(id).style.display = 'flex'; }
function closeOverlay(id)  { document.getElementById(id).style.display = 'none'; }

// ─────────────────────────────────────────────────────────────────────────────
// VIEW PAYROLL SUMMARY
// ─────────────────────────────────────────────────────────────────────────────
async function openSummaryModal(periodId) {
    showOverlay('summaryOverlay');
    document.getElementById('summaryBody').innerHTML =
        '<div class="pr-loading"><i class="fa fa-spinner fa-spin"></i> Loading…</div>';

    try {
        const res  = await fetch(`${BASE_URL}actions/get-payroll-summary.php?period_id=${periodId}&type=summary`);
        const data = await res.json();
        if (!data.success) throw new Error(data.message || 'Failed to load');

        const p     = data.period;
        const t     = data.totals;
        const label    = formatPeriodLabel(p.pay_period_start, p.pay_period_end);
        const batchNo  = p.payroll_number ? ` [${p.payroll_number}]` : '';
        document.getElementById('summaryTitle').textContent = 'Payroll Summary — ' + label + batchNo;

        const deptRows = (data.departments || []).map(d => `
            <div class="pr-dept-row">
                <div>
                    <strong>${escH(d.department_name)}</strong>
                    <small>${d.emp_count} employee${d.emp_count != 1 ? 's' : ''}</small>
                </div>
                <span>₱${fmtNum(d.gross_total)}</span>
            </div>`).join('');

        // Previous period comparison
        const prev = data.prev_period;
        let compHtml = '';
        if (prev) {
            const diffGross = parseFloat(t.total_gross) - parseFloat(prev.total_gross);
            const diffNet   = parseFloat(t.total_net)   - parseFloat(prev.total_net);
            const diffEmp   = parseInt(t.emp_count)     - parseInt(prev.emp_count);
            function diffBadge(val) {
                const sign = val >= 0 ? '+' : '';
                const cls  = val >= 0 ? 'color:#059669;background:#d1fae5;' : 'color:#dc2626;background:#fee2e2;';
                return `<span style="${cls}padding:2px 8px;border-radius:12px;font-size:11px;font-weight:700;margin-left:6px;">${sign}${val >= 0 || val < -999 ? '₱' + fmtNum(Math.abs(val)) : '₱' + fmtNum(Math.abs(val))}${typeof val === 'number' && !String(val).includes('.') && Math.abs(val) < 100 ? '' : ''}</span>`;
            }
            function numBadge(val) {
                const sign = val >= 0 ? '+' : '';
                const cls  = val >= 0 ? 'color:#059669;background:#d1fae5;' : 'color:#dc2626;background:#fee2e2;';
                return `<span style="${cls}padding:2px 8px;border-radius:12px;font-size:11px;font-weight:700;margin-left:6px;">${sign}${val}</span>`;
            }
            const prevLabel = prev.period_name ||
                formatPeriodLabel(prev.pay_period_start, prev.pay_period_start);
            compHtml = `
            <div class="pr-compare-box">
              <div class="pr-compare-title"><i class="fa fa-arrow-right-arrow-left"></i> vs Previous Payroll <span style="font-weight:400;color:#94a3b8;">(${escH(prevLabel)})</span></div>
              <div class="pr-compare-row">
                <span>Gross Pay</span>
                <span>₱${fmtNum(prev.total_gross)}
                  <span style="${diffGross >= 0 ? 'color:#059669;' : 'color:#dc2626;'}font-size:11px;font-weight:700;margin-left:6px;">${diffGross >= 0 ? '+' : ''}₱${fmtNum(Math.abs(diffGross))}</span>
                </span>
              </div>
              <div class="pr-compare-row">
                <span>Net Pay</span>
                <span>₱${fmtNum(prev.total_net)}
                  <span style="${diffNet >= 0 ? 'color:#059669;' : 'color:#dc2626;'}font-size:11px;font-weight:700;margin-left:6px;">${diffNet >= 0 ? '+' : ''}₱${fmtNum(Math.abs(diffNet))}</span>
                </span>
              </div>
              <div class="pr-compare-row">
                <span>Employees</span>
                <span>${prev.emp_count}
                  <span style="${diffEmp >= 0 ? 'color:#059669;' : 'color:#dc2626;'}font-size:11px;font-weight:700;margin-left:6px;">${diffEmp >= 0 ? '+' : ''}${diffEmp}</span>
                </span>
              </div>
            </div>`;
        }

        // Alert warnings
        const alerts = data.alerts || {};
        let alertHtml = '';
        if (parseInt(alerts.neg_net_count || 0) > 0 || parseInt(alerts.zero_basic_count || 0) > 0) {
            const chips = [];
            if (parseInt(alerts.neg_net_count) > 0)
                chips.push(`<span class="pr-alert-chip pr-alert-chip--red">${alerts.neg_net_count} employee${parseInt(alerts.neg_net_count) > 1 ? 's' : ''} with negative net pay</span>`);
            if (parseInt(alerts.zero_basic_count) > 0)
                chips.push(`<span class="pr-alert-chip pr-alert-chip--yellow">${alerts.zero_basic_count} employee${parseInt(alerts.zero_basic_count) > 1 ? 's' : ''} with zero basic pay</span>`);
            alertHtml = `<div class="pr-alerts-strip" style="margin-bottom:14px;">
                <i class="fa fa-triangle-exclamation"></i>
                <strong>Warnings:</strong> ${chips.join(' ')}
            </div>`;
        }

        document.getElementById('summaryBody').innerHTML = `
            ${alertHtml}
            <div class="pr-summary-grid">
                <div class="pr-summary-card">
                    <small>PAY PERIOD</small>
                    <strong>${label}</strong>
                </div>
                <div class="pr-summary-card">
                    <small>TOTAL EMPLOYEES</small>
                    <strong>${t.emp_count} Employees</strong>
                </div>
                <div class="pr-summary-card pr-summary-card--green">
                    <small>TOTAL GROSS PAY</small>
                    <strong>₱${fmtNum(t.total_gross)}</strong>
                </div>
                <div class="pr-summary-card pr-summary-card--red">
                    <small>TOTAL DEDUCTIONS</small>
                    <strong>₱${fmtNum(t.total_deductions)}</strong>
                </div>
            </div>
            <div class="pr-net-box">
                <div>TOTAL NET PAYABLE</div>
                <strong>₱${fmtNum(t.total_net)}</strong>
            </div>
            ${compHtml}
            <div class="pr-dept-section">
                <h4>Department Breakdown</h4>
                ${deptRows || '<p style="color:#9ca3af;font-size:13px;">No breakdown available.</p>'}
            </div>
            <div style="display:flex;justify-content:flex-end;gap:10px;margin-top:20px;padding-top:16px;border-top:1px solid var(--border);">
                <button class="pr-btn-cancel" onclick="closeSummary()">Close</button>
                <button class="pr-btn-approve-confirm"
                        onclick="closeSummary();openRegisterModal(${periodId})">
                    <i class="fa fa-table-list"></i> View Full Register
                </button>
            </div>`;
    } catch (err) {
        document.getElementById('summaryBody').innerHTML =
            `<p style="color:#dc2626;padding:20px;font-size:13px;">
                <i class="fa fa-circle-xmark"></i> Failed to load payroll summary. Please try again.
             </p>`;
    }
}

function closeSummary() { closeOverlay('summaryOverlay'); }

// ─────────────────────────────────────────────────────────────────────────────
// VIEW DETAILED REGISTER
// ─────────────────────────────────────────────────────────────────────────────
async function openRegisterModal(periodId) {
    showOverlay('registerOverlay');
    document.getElementById('registerBody').innerHTML =
        '<div class="pr-loading"><i class="fa fa-spinner fa-spin"></i> Loading…</div>';

    try {
        const res  = await fetch(`${BASE_URL}actions/get-payroll-summary.php?period_id=${periodId}&type=register`);
        const data = await res.json();
        if (!data.success) throw new Error(data.message || 'Failed');

        const label    = formatPeriodLabel(data.period.pay_period_start, data.period.pay_period_end);
        const batchNo  = data.period.payroll_number ? ` [${data.period.payroll_number}]` : '';
        document.getElementById('registerTitle').textContent = 'Detailed Payroll Register — ' + label + batchNo;

        const rows = data.records.map(r => {
            const netCls = parseFloat(r.net_pay) < 0 ? 'style="color:#dc2626;"' : '';
            return `<tr>
                <td>
                    <div style="font-size:10px;color:#9ca3af;font-family:monospace;">${escH(r.employee_no || '')}</div>
                    <strong>${escH(r.employee_name)}</strong>
                    <div style="font-size:11px;color:#9ca3af;">${escH(r.position_name || '')}</div>
                </td>
                <td>₱${fmtNum(r.basic_pay)}</td>
                <td>₱${fmtNum(r.addl_assign)}</td>
                <td>₱${fmtNum(r.rice_sub)}</td>
                <td>₱${fmtNum(r.laundry)}</td>
                <td class="reg-col-gross">₱${fmtNum(r.gross_pay)}</td>
                <td class="reg-col-ded">₱${fmtNum(r.peraa_p)}</td>
                <td class="reg-col-ded">₱${fmtNum(r.peraa_l)}</td>
                <td class="reg-col-ded">₱${fmtNum(r.hdmf_p)}</td>
                <td class="reg-col-ded">₱${fmtNum(r.hdmf_l)}</td>
                <td class="reg-col-ded">₱${fmtNum(r.philhealth)}</td>
                <td class="reg-col-ded">₱${fmtNum(r.sss_p)}</td>
                <td class="reg-col-ded">₱${fmtNum(r.sss_l)}</td>
                <td class="reg-col-total-ded"><strong>₱${fmtNum(r.total_deductions)}</strong></td>
                <td class="reg-col-net"><strong ${netCls}>₱${fmtNum(r.net_pay)}</strong></td>
            </tr>`;
        }).join('');

        const t = data.totals;
        document.getElementById('registerBody').innerHTML = `
            <div class="reg-table-wrap">
              <table class="reg-table">
                <thead>
                  <tr>
                    <th style="text-align:left;">Employee</th>
                    <th>Basic</th>
                    <th>Add'l Assign.</th>
                    <th>Rice Sub.</th>
                    <th>Laundry</th>
                    <th class="reg-col-gross">Gross</th>
                    <th class="reg-col-ded">PERAA-P</th>
                    <th class="reg-col-ded">PERAA-L</th>
                    <th class="reg-col-ded">HDMF-P</th>
                    <th class="reg-col-ded">HDMF-L</th>
                    <th class="reg-col-ded">PhilHealth</th>
                    <th class="reg-col-ded">SSS-P</th>
                    <th class="reg-col-ded">SSS-L</th>
                    <th class="reg-col-total-ded">Total Ded.</th>
                    <th class="reg-col-net">Net Pay</th>
                  </tr>
                </thead>
                <tbody>${rows || '<tr><td colspan="15" style="text-align:center;color:#9ca3af;padding:24px;">No records found.</td></tr>'}</tbody>
              </table>
            </div>
            <div class="reg-totals-bar">
              <div>
                <small>TOTAL GROSS</small>
                <strong>₱${fmtNum(t.gross)}</strong>
              </div>
              <div>
                <small>TOTAL DEDUCTIONS</small>
                <strong style="color:#ef4444;">₱${fmtNum(t.deductions)}</strong>
              </div>
              <div>
                <small>TOTAL NET PAY</small>
                <strong style="color:#0f766e;">₱${fmtNum(t.net)}</strong>
              </div>
            </div>
            <div style="display:flex;justify-content:flex-end;margin-top:12px;">
                <button class="pr-btn-cancel" onclick="closeRegister()">Close</button>
            </div>`;
    } catch (err) {
        document.getElementById('registerBody').innerHTML =
            `<p style="color:#dc2626;padding:20px;font-size:13px;">
                <i class="fa fa-circle-xmark"></i> Failed to load register. Please try again.
             </p>`;
    }
}

function closeRegister() { closeOverlay('registerOverlay'); }

function exportRegisterPDF(periodId) {
    // Can be called directly with a periodId (from history buttons)
    // or with no args after the register modal is already open
    if (periodId) {
        // Open register modal first, then user can print from the modal
        openRegisterModal(periodId);
        return;
    }
    const title = document.getElementById('registerTitle').textContent;
    const body  = document.getElementById('registerBody').innerHTML;
    const win   = window.open('', '_blank');
    win.document.write(`<!DOCTYPE html><html><head>
        <title>${title}</title>
        <style>
            body { font-family: Arial, sans-serif; font-size: 11px; padding: 20px; }
            h2 { font-size: 14px; margin-bottom: 12px; }
            table { width: 100%; border-collapse: collapse; }
            th, td { padding: 6px 8px; border: 1px solid #ddd; text-align: right; }
            th { background: #f1f5f9; font-size: 10px; text-transform: uppercase; }
            th:first-child, td:first-child { text-align: left; }
            .reg-col-gross { color: #0f766e; }
            .reg-col-ded, .reg-col-total-ded { color: #dc2626; }
            .reg-col-net { color: #0f766e; }
            .reg-totals-bar { margin-top: 12px; display: flex; gap: 24px; }
            .reg-totals-bar small { display: block; font-size: 9px; color: #666; }
            .reg-totals-bar strong { font-size: 13px; }
        </style>
    </head><body>
        <h2>${title}</h2>
        ${body}
    </body></html>`);
    win.document.close();
    win.focus();
    setTimeout(() => { win.print(); win.close(); }, 400);
}

// ─────────────────────────────────────────────────────────────────────────────
// APPROVE
// ─────────────────────────────────────────────────────────────────────────────
function openApproveConfirm(periodId, label) {
    closeReject();   // ensure reject modal is closed before opening approve
    _activePeriod      = periodId;
    _activePeriodLabel = label;
    document.getElementById('approveDesc').textContent =
        `You are about to approve and authorize disbursement of payroll for "${label}". ` +
        `This action will lock the payroll and cannot be undone.`;
    // Reset button state in case it was left in a disabled state
    const btn = document.getElementById('approveConfirmBtn');
    if (btn) { btn.disabled = false; btn.innerHTML = '<i class="fa fa-circle-check"></i> Yes, Approve &amp; Authorize'; }
    showOverlay('approveOverlay');
}

function closeApprove() { closeOverlay('approveOverlay'); }

async function submitApprove() {
    const btn = document.getElementById('approveConfirmBtn');
    btn.disabled = true;
    btn.innerHTML = '<i class="fa fa-spinner fa-spin"></i> Processing…';

    const fd = new FormData();
    fd.append('action', 'approve');
    fd.append('period_id', _activePeriod);

    try {
        const res  = await fetch(`${BASE_URL}actions/payroll-approve.php`, { method: 'POST', body: fd });
        const data = await res.json();
        closeApprove();
        showToast(data.message, data.success ? 'success' : 'error');
        if (data.success) setTimeout(() => location.reload(), 1500);
        else {
            btn.disabled = false;
            btn.innerHTML = '<i class="fa fa-circle-check"></i> Yes, Approve & Authorize';
        }
    } catch {
        showToast('Network error. Please try again.', 'error');
        btn.disabled = false;
        btn.innerHTML = '<i class="fa fa-circle-check"></i> Yes, Approve & Authorize';
    }
}

// ─────────────────────────────────────────────────────────────────────────────
// REJECT / RETURN
// ─────────────────────────────────────────────────────────────────────────────
function openRejectModal(periodId, label) {
    closeApprove();  // ensure approve modal is closed before opening reject
    _activePeriod      = periodId;
    _activePeriodLabel = label;
    document.getElementById('rejectRemarks').value = '';
    document.getElementById('rejectError').style.display = 'none';
    // Reset button state
    const btn = document.getElementById('rejectSubmitBtn');
    if (btn) { btn.disabled = false; btn.innerHTML = '<i class="fa fa-circle-xmark"></i> Submit Rejection'; }
    showOverlay('rejectOverlay');
    setTimeout(() => document.getElementById('rejectRemarks')?.focus(), 80);
}

function closeReject() { closeOverlay('rejectOverlay'); }

async function submitReject() {
    const remarks = document.getElementById('rejectRemarks').value.trim();
    if (!remarks) {
        document.getElementById('rejectError').style.display = 'block';
        return;
    }
    document.getElementById('rejectError').style.display = 'none';

    const btn = document.getElementById('rejectSubmitBtn');
    btn.disabled = true;
    btn.innerHTML = '<i class="fa fa-spinner fa-spin"></i> Submitting…';

    const fd = new FormData();
    fd.append('action', 'return');
    fd.append('period_id', _activePeriod);
    fd.append('notes', remarks);

    try {
        const res  = await fetch(`${BASE_URL}actions/payroll-approve.php`, { method: 'POST', body: fd });
        const data = await res.json();
        closeReject();
        showToast(data.message, data.success ? 'success' : 'error');
        if (data.success) setTimeout(() => location.reload(), 1500);
        else {
            btn.disabled = false;
            btn.innerHTML = '<i class="fa fa-circle-xmark"></i> Submit Rejection';
        }
    } catch {
        showToast('Network error. Please try again.', 'error');
        btn.disabled = false;
        btn.innerHTML = '<i class="fa fa-circle-xmark"></i> Submit Rejection';
    }
}

// ─────────────────────────────────────────────────────────────────────────────
// CLOSE ON BACKDROP CLICK
// ─────────────────────────────────────────────────────────────────────────────
document.addEventListener('DOMContentLoaded', () => {
    document.querySelectorAll('.pr-modal-overlay').forEach(overlay => {
        overlay.addEventListener('click', function (e) {
            if (e.target === this) this.style.display = 'none';
        });
    });
});