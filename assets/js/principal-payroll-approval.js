/**
 * assets/js/principal-payroll-approval.js
 * Handles all interactivity for the Principal Payroll Approval page.
 */

let _activePeriod      = null;
let _activePeriodLabel = '';

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
        const label = formatPeriodLabel(p.pay_period_start, p.pay_period_end);
        document.getElementById('summaryTitle').textContent = 'Payroll Summary — ' + label;

        const deptRows = (data.departments || []).map(d => `
            <div class="pr-dept-row">
                <div>
                    <strong>${escH(d.department_name)}</strong>
                    <small>${d.emp_count} employee${d.emp_count != 1 ? 's' : ''}</small>
                </div>
                <span>₱${fmtNum(d.gross_total)}</span>
            </div>`).join('');

        document.getElementById('summaryBody').innerHTML = `
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

        const label = formatPeriodLabel(data.period.pay_period_start, data.period.pay_period_end);
        document.getElementById('registerTitle').textContent = 'Detailed Payroll Register — ' + label;

        const rows = data.records.map(r => `
            <tr>
                <td>
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
                <td class="reg-col-net"><strong>₱${fmtNum(r.net_pay)}</strong></td>
            </tr>`).join('');

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

function exportRegisterPDF() {
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
    _activePeriod      = periodId;
    _activePeriodLabel = label;
    document.getElementById('approveDesc').textContent =
        `Are you sure you want to approve and authorize the disbursement of payroll for ${label}? ` +
        `This action will lock the payroll and cannot be undone.`;
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
    _activePeriod      = periodId;
    _activePeriodLabel = label;
    document.getElementById('rejectRemarks').value = '';
    document.getElementById('rejectError').style.display = 'none';
    showOverlay('rejectOverlay');
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