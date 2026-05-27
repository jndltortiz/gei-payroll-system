/* ============================================================
   payroll-settings.js
   GEI HR System — Payroll Settings Page
   ============================================================ */

'use strict';

const PS_BASE_URL = window.BASE_URL || '';
const PS_SETTINGS = window.PAYROLL_SETTINGS || {};
const PS_SSS_RATES = window.SSS_RATES || [];
const PS_SSS_TOTAL = Number(window.SSS_TOTAL || 0);
const PS_PHIL_RATES = window.PHIL_RATES || [];
const PS_PAGIBIG_RATES = window.PAGIBIG_RATES || [];

// ─── Bootstrap modals ───────────────────────────────────────
const _modal = id => {
    const el = document.getElementById(id);
    return el && window.bootstrap ? new bootstrap.Modal(el) : null;
};

let modalAddDed    = null;
let modalEditDed   = null;
let modalAddAl     = null;
let modalEditAl    = null;
let modalAssign    = null;
let modalDelete    = null;
let modalSave      = null;
let modalRateTbls  = null;

document.addEventListener('DOMContentLoaded', () => {
    if (!document.querySelector('.ps-content')) return;

    modalAddDed   = _modal('modalAddDeduction');
    modalEditDed  = _modal('modalEditDeduction');
    modalAddAl    = _modal('modalAddAllowance');
    modalEditAl   = _modal('modalEditAllowance');
    modalAssign   = _modal('modalAssign');
    modalDelete   = _modal('modalDelete');
    modalSave     = _modal('modalSaveConfirm');
    modalRateTbls = _modal('modalRateTables');

    initCollapse();
    initDirtyTracking();
    initCutoffDescriptions();
    initLoanToggles();
    initAssignRadioCards();
    initGovModeToggle();
    initGovManualToggles();
    initRateTables();
    updateFrequencyCutoff();
});

// ─── Collapsible Cards ───────────────────────────────────────
function initCollapse() {
    document.querySelectorAll('[data-toggle]').forEach(header => {
        const bodyId  = header.dataset.toggle;
        const body    = document.getElementById(bodyId);
        const icon    = document.getElementById(`icon-${bodyId}`);
        if (!body) return;

        const collapsed = body.classList.contains('d-none');
        if (icon) icon.style.transform = collapsed ? 'rotate(180deg)' : '';

        header.addEventListener('click', e => {
            if (e.target.closest('button') || e.target.closest('select') || e.target.closest('input')) return;
            const isHidden = body.classList.toggle('d-none');
            if (icon) icon.style.transform = isHidden ? 'rotate(180deg)' : '';
        });
    });
}

// ─── Dirty Tracking ──────────────────────────────────────────
let isDirty = false;

function markDirty() {
    if (isDirty) return;
    isDirty = true;
    const bar  = document.getElementById('savebar');
    const text = document.getElementById('saveBarText');
    bar.classList.add('dirty');
    text.innerHTML = '<i class="bi bi-exclamation-circle me-1"></i><strong>Unsaved Changes</strong> &nbsp; You have unsaved changes. Save to apply them to future payroll generation.';
    text.classList.add('dirty');
}

function markClean() {
    isDirty = false;
    const bar  = document.getElementById('savebar');
    const text = document.getElementById('saveBarText');
    bar.classList.remove('dirty');
    text.innerHTML = '<strong>Ready to save?</strong> Changes will apply starting the next payroll generation.';
    text.classList.remove('dirty');
}

function initDirtyTracking() {
    const track = ['payrollFrequency','workingDays','cutoff1Start','cutoff1End','cutoff2Start','cutoff2End',
                   'weekendRule','attendanceSource'];
    track.forEach(id => {
        const el = document.getElementById(id);
        if (el) el.addEventListener('change', markDirty);
    });
}

// ─── Cutoff Descriptions ─────────────────────────────────────
function initCutoffDescriptions() {
    ['cutoff1Start','cutoff1End','cutoff2Start','cutoff2End'].forEach(id => {
        const el = document.getElementById(id);
        if (el) el.addEventListener('change', updateCutoffDesc);
    });
    ['cutoff1End','cutoff2End','weekendRule'].forEach(id => {
        const el = document.getElementById(id);
        if (el) el.addEventListener('change', updateWeekendPreview);
    });
    updateCutoffDesc();
    updateWeekendPreview();
}

function updateWeekendPreview() {
    const ruleEl = document.getElementById('weekendRule');
    const descEl = document.getElementById('weekendRuleDesc');
    if (!ruleEl || !descEl) return;

    if (ruleEl.value === 'EXACT') {
        descEl.textContent = 'Pay date will be set to the exact cutoff end date.';
        return;
    }

    // Preview: compute this month's 2nd cutoff end and see if it needs advancing
    const c2e     = parseInt(document.getElementById('cutoff2End')?.value || 31, 10);
    const now     = new Date();
    const yr      = now.getFullYear();
    const mo      = now.getMonth(); // 0-indexed
    const lastDay = new Date(yr, mo + 1, 0).getDate();
    const day     = Math.min(c2e, lastDay);
    const dt      = new Date(yr, mo, day);
    const dow     = dt.getDay(); // 0=Sun, 6=Sat
    const days    = ['Sunday','Monday','Tuesday','Wednesday','Thursday','Friday','Saturday'];
    const fmt     = d => d.toLocaleDateString('en-PH', { month: 'short', day: 'numeric' });

    let msg = `This month: cutoff ends ${fmt(dt)} (${days[dow]})`;
    if (dow === 6) {
        const fri = new Date(dt); fri.setDate(fri.getDate() - 1);
        msg += ` → pay date advances to ${fmt(fri)} (Friday).`;
    } else if (dow === 0) {
        const fri = new Date(dt); fri.setDate(fri.getDate() - 2);
        msg += ` → pay date advances to ${fmt(fri)} (Friday).`;
    } else {
        msg += ` — no adjustment needed.`;
    }
    descEl.textContent = msg;
}

function updateCutoffDesc() {
    const s1 = document.getElementById('cutoff1Start')?.value;
    const e1 = document.getElementById('cutoff1End')?.value;
    const s2 = document.getElementById('cutoff2Start')?.value;
    const e2 = document.getElementById('cutoff2End')?.value;
    const d1 = document.getElementById('cutoff1Desc');
    const d2 = document.getElementById('cutoff2Desc');
    if (d1) d1.textContent = `Day ${s1} to Day ${e1} of each month`;
    if (d2) d2.textContent = `Day ${s2} to Day ${e2} of each month`;
}

function updateFrequencyCutoff() {
    const freq   = document.getElementById('payrollFrequency');
    const c2     = document.getElementById('cutoff2Block');
    if (!freq || !c2) return;
    freq.addEventListener('change', () => {
        c2.style.display = freq.value === 'MONTHLY' ? 'none' : '';
    });
    c2.style.display = freq.value === 'MONTHLY' ? 'none' : '';
}

// ─── Loan Toggles ────────────────────────────────────────────
function initLoanToggles() {
    document.querySelectorAll('.loan-toggle').forEach(chk => {
        chk.addEventListener('change', () => {
            const row       = chk.closest('tr');
            const hint      = row.querySelector('.loan-status-hint');
            const isOn      = chk.checked;

            // Visual muted state
            row.classList.toggle('ps-row-muted', !isOn);

            // Update the status hint text
            if (hint) {
                hint.innerHTML = isOn
                    ? '<i class="bi bi-check-circle-fill text-teal"></i> Auto-deducted during payroll'
                    : '<i class="bi bi-dash-circle text-muted"></i> Not included in payroll';
            }

            markDirty();
        });
    });
}

// ─── Government Mode Toggle ──────────────────────────────────
function initGovModeToggle() {
    const btn    = document.getElementById('btnGovMode');
    const stdView = document.getElementById('govStandardView');
    const manView = document.getElementById('govManualView');
    if (!btn) return;

    btn.addEventListener('click', () => {
        const isStd = btn.dataset.mode === 'STANDARD';
        const newMode = isStd ? 'MANUAL' : 'STANDARD';
        btn.dataset.mode = newMode;

        if (newMode === 'STANDARD') {
            btn.innerHTML = '<i class="bi bi-check-circle-fill"></i> Enabled';
            btn.classList.add('active');
            stdView.classList.remove('d-none');
            manView.classList.add('d-none');
            document.querySelector('.ps-setting-sub').textContent = 'Contributions auto-calculated using 2024 official rates';
        } else {
            btn.innerHTML = '<i class="bi bi-x-circle"></i> Manual Mode';
            btn.classList.remove('active');
            stdView.classList.add('d-none');
            manView.classList.remove('d-none');
            document.querySelector('.ps-setting-sub').textContent = 'Manual mode: Enter custom rates for each contribution';
        }
        markDirty();
    });

    document.getElementById('btnViewRateTables')?.addEventListener('click', e => {
        e.preventDefault();
        modalRateTbls.show();
    });
}

// ─── Gov Type Toggles (manual mode) ─────────────────────────
function setGovType(prefix, type) {
    const tgl   = document.getElementById(`${prefix}TypeTgl`);
    const input = document.getElementById(`${prefix}Rate`);
    const hidden = document.getElementById(`${prefix}Type`);
    if (!tgl) return;

    tgl.querySelectorAll('.ps-type-btn').forEach(btn => {
        btn.classList.toggle('active', btn.dataset.type === type);
    });

    hidden.value = type;

    if (type === 'pct') {
        input.max  = 100;
        input.step = '0.01';
    } else {
        input.removeAttribute('max');
        input.step = '0.01';
    }
    markDirty();
}

// ─── Deduction Type (modal) ──────────────────────────────────
function setDedType(ctx, type) {
    const prefix = ctx === 'add' ? 'add' : 'edit';
    const tgl    = document.getElementById(`${prefix}DedTypeTgl`);
    const hidden = document.getElementById(`${prefix}DedType`);
    const label  = document.getElementById(`${prefix}DedAmtLabel`);
    const input  = document.getElementById(`${prefix}DedAmount`);

    tgl.querySelectorAll('.ps-type-btn-lg').forEach(btn => {
        btn.classList.toggle('active', btn.dataset.type === type);
    });

    hidden.value = type;

    if (type === 'FIXED') {
        label.innerHTML = 'Amount (₱) <span class="text-danger">*</span>';
        input.placeholder = '0.00';
        input.removeAttribute('max');
    } else {
        label.innerHTML = 'Rate (%) <span class="text-danger">*</span>';
        input.placeholder = '0.00';
        input.max = 100;
    }
}

// ─── Assign Radio Cards ──────────────────────────────────────
function initAssignRadioCards() {
    document.querySelectorAll('.ps-radio-card').forEach(card => {
        // Prevent select clicks from re-triggering card click
        card.querySelectorAll('select').forEach(sel => {
            sel.addEventListener('click', e => e.stopPropagation());
            sel.addEventListener('change', e => e.stopPropagation());
        });

        card.addEventListener('click', e => {
            // Don't re-trigger when clicking the select itself
            if (e.target.tagName === 'SELECT' || e.target.tagName === 'OPTION') return;

            // Deselect all
            document.querySelectorAll('.ps-radio-card').forEach(c => {
                c.classList.remove('selected');
                const s = c.querySelector('.ps-scope-select');
                if (s) { s.classList.add('d-none'); s.disabled = true; }
            });

            // Select this card
            card.classList.add('selected');
            const radio = card.querySelector('input[type="radio"]');
            if (radio) radio.checked = true;

            const select = card.querySelector('.ps-scope-select');
            if (select) { select.classList.remove('d-none'); select.disabled = false; }
        });
    });
}

// Init gov manual toggles
function initGovManualToggles() {
    document.querySelectorAll('.gov-manual-toggle').forEach(chk => {
        chk.addEventListener('change', () => {
            const contrib = chk.dataset.contrib;
            const statusEl = document.getElementById(`${contrib}ManualStatus`);
            const rateInput = document.getElementById(`${contrib}Rate`);
            if (statusEl) {
                statusEl.textContent = chk.checked ? 'Enabled' : 'Disabled';
                statusEl.className   = 'ps-status-badge ' + (chk.checked ? 'active' : 'inactive');
            }
            if (rateInput) rateInput.disabled = !chk.checked;
            markDirty();
        });
    });
}

// ─── Rate Tables ─────────────────────────────────────────────
function initRateTables() {
    // SSS
    const sssBody = document.getElementById('sssRateRows');
    if (sssBody && PS_SSS_RATES.length) {
        sssBody.innerHTML = PS_SSS_RATES.map(r => `
            <tr>
                <td>₱${fmt(r.min_salary)} – ₱${fmt(r.max_salary)}</td>
                <td>₱${fmt(r.monthly_salary_credit)}</td>
                <td class="text-blue">₱${fmt(r.employee_share)}</td>
                <td>₱${fmt(r.employer_share)}</td>
            </tr>
        `).join('');
        document.getElementById('sssTotalNote').innerHTML =
            `<strong>Note:</strong> Full table has ${PS_SSS_TOTAL} salary brackets. Employee share: 5%, Employer share: 10% (SSS Circular 2024-006, effective Jan 2025)`;
    }

    // PhilHealth
    const philBody = document.getElementById('philRateRows');
    if (philBody && PS_PHIL_RATES.length) {
        philBody.innerHTML = PS_PHIL_RATES.map(r => {
            const empVal = parseFloat(r.employee_share) > 0
                ? `₱${fmt(r.employee_share)}`
                : `Salary × ${pct(r.employee_share_rate)}`;
            const emplrVal = parseFloat(r.employer_share) > 0
                ? `₱${fmt(r.employer_share)}`
                : `Salary × ${pct(r.employer_share_rate)}`;
            return `
                <tr>
                    <td>₱${fmt(r.min_salary)} – ₱${fmt(r.max_salary)}</td>
                    <td class="text-green">${empVal}</td>
                    <td>${emplrVal}</td>
                </tr>
            `;
        }).join('');
        document.getElementById('philExample').innerHTML =
            '<strong>Example:</strong> ₱25,000 salary → Employee: ₱625.00, Employer: ₱625.00';
    }

    // Pag-IBIG
    const pagBody = document.getElementById('pagibigRateRows');
    if (pagBody && PS_PAGIBIG_RATES.length) {
        pagBody.innerHTML = PS_PAGIBIG_RATES.map(r => `
            <tr>
                <td>₱${fmt(r.min_salary)} – ₱${fmt(r.max_salary)}</td>
                <td class="text-amber">${pct(r.employee_rate)}</td>
                <td>${pct(r.employer_rate)}</td>
                <td>${r.max_employee_contribution ? '₱'+fmt(r.max_employee_contribution) : 'No cap'}</td>
            </tr>
        `).join('');
        document.getElementById('pagibigExample').innerHTML =
            '<strong>Example:</strong> ₱25,000 salary → Employee: ₱200.00 (capped), Employer: ₱200.00 (capped)';
    }
}

function fmt(n) {
    return parseFloat(n || 0).toLocaleString('en-PH', {minimumFractionDigits:2, maximumFractionDigits:2});
}

function pct(n) {
    return (parseFloat(n || 0) * 100).toFixed(1).replace(/\.0$/, '') + '%';
}

// ─── Open Modals ─────────────────────────────────────────────
function openAddDeductionModal() {
    document.getElementById('formAddDeduction').reset();
    setDedType('add', 'FIXED');
    modalAddDed.show();
}

function openEditDeductionModal(id, name, type, amount, rate, appliesTo, isActive) {
    document.getElementById('editDedId').value      = id;
    document.getElementById('editDedName').value    = name;
    document.getElementById('editDedStatus').value  = isActive;
    setDedType('edit', type);
    document.getElementById('editDedAmount').value  = type === 'FIXED' ? amount : rate;
    modalEditDed.show();
}

function openAddAllowanceModal() {
    document.getElementById('formAddAllowance').reset();
    modalAddAl.show();
}

function openEditAllowanceModal(id, name, amount, isTaxable, isActive) {
    document.getElementById('editAlId').value      = id;
    document.getElementById('editAlName').value    = name;
    document.getElementById('editAlAmount').value  = amount;
    document.getElementById('editAlTaxable').value = isTaxable;
    document.getElementById('editAlStatus').value  = isActive;
    modalEditAl.show();
}

function openAssignModal(type, id, name) {
    document.getElementById('assignItemType').value = type;
    document.getElementById('assignItemId').value   = id;
    document.getElementById('assignModalTitle').textContent = `Assign "${name}" to...`;

    // Reset all cards to unselected, selects to hidden+disabled
    document.querySelectorAll('.ps-radio-card').forEach((card, i) => {
        const radio  = card.querySelector('input[type="radio"]');
        const select = card.querySelector('.ps-scope-select');
        const isFirst = (i === 0);

        card.classList.toggle('selected', isFirst);
        if (radio)  radio.checked = isFirst;
        if (select) {
            select.classList.add('d-none');
            select.disabled = true;
            select.value = '';
        }
    });

    modalAssign.show();
}

// Delete modal
let _deleteType = '';
let _deleteId   = 0;

function openDeleteModal(type, id, name) {
    _deleteType = type;
    _deleteId   = id;
    const label = type === 'deduction' ? 'Deduction' : 'Allowance';
    document.getElementById('deleteModalTitle').textContent = `Delete ${label}`;
    document.getElementById('deleteModalBody').textContent  =
        `Remove "${name}" from the ${type} list? This will no longer be applied in future payroll computations.`;
    modalDelete.show();
}

document.addEventListener('DOMContentLoaded', () => {
    document.getElementById('btnConfirmDelete')?.addEventListener('click', () => {
        if (!_deleteId) return;
        const action = _deleteType === 'deduction'
            ? `${PS_BASE_URL}actions/delete-deduction.php`
            : `${PS_BASE_URL}actions/delete-allowance.php`;
        fetchAction(action, { id: _deleteId }, data => {
            if (data.success) {
                const row = document.querySelector(`tr[data-${_deleteType === 'deduction' ? 'ded' : 'al'}-id="${_deleteId}"]`);
                row?.remove();
                modalDelete.hide();
                showToast('Deleted successfully.', 'success');
            } else {
                showToast(data.message || 'Failed to delete.', 'error');
            }
        });
    });
});

// Save confirm
function openSaveConfirmModal() {
    modalSave.show();
}

// ─── Form Submissions ─────────────────────────────────────────

function submitAddDeduction(e) {
    e.preventDefault();
    const fd = new FormData(e.target);
    fetchAction(`${PS_BASE_URL}actions/save-deduction.php`, Object.fromEntries(fd), data => {
        if (data.success) {
            location.reload(); // simplest for now; can do DOM insert
        } else {
            showToast(data.message || 'Failed to save.', 'error');
        }
    });
}

function submitEditDeduction(e) {
    e.preventDefault();
    const fd = new FormData(e.target);
    fetchAction(`${PS_BASE_URL}actions/save-deduction.php`, Object.fromEntries(fd), data => {
        if (data.success) {
            location.reload();
        } else {
            showToast(data.message || 'Failed to save.', 'error');
        }
    });
}

function submitAddAllowance(e) {
    e.preventDefault();
    const fd = new FormData(e.target);
    fetchAction(`${PS_BASE_URL}actions/save-allowance.php`, Object.fromEntries(fd), data => {
        if (data.success) {
            location.reload();
        } else {
            showToast(data.message || 'Failed to save.', 'error');
        }
    });
}

function submitEditAllowance(e) {
    e.preventDefault();
    const fd = new FormData(e.target);
    fetchAction(`${PS_BASE_URL}actions/save-allowance.php`, Object.fromEntries(fd), data => {
        if (data.success) {
            location.reload();
        } else {
            showToast(data.message || 'Failed to save.', 'error');
        }
    });
}

function submitAssignment(e) {
    e.preventDefault();
    const fd = new FormData(e.target);
    const scope = fd.get('applies_to');
    let targetId = null;
    if (scope === 'DEPARTMENT') targetId = fd.get('target_department');
    if (scope === 'POSITION')   targetId = fd.get('target_position');
    if (scope === 'EMPLOYEE')   targetId = fd.get('target_employee');
    const payload = {
        item_type:  fd.get('item_type'),
        item_id:    fd.get('item_id'),
        applies_to: scope,
        target_id:  targetId,
    };
    fetchAction(`${PS_BASE_URL}actions/save-assignment.php`, payload, data => {
        if (data.success) {
            modalAssign.hide();
            showToast('Assignment saved.', 'success');
            setTimeout(() => location.reload(), 600);
        } else {
            showToast(data.message || 'Failed to save.', 'error');
        }
    });
}

function submitPayrollSettings() {
    // Collect all settings
    const payload = {
        payroll_frequency:      document.getElementById('payrollFrequency')?.value,
        working_days_per_week:  document.getElementById('workingDays')?.value,
        cutoff1_start_day:      document.getElementById('cutoff1Start')?.value,
        cutoff1_end_day:        document.getElementById('cutoff1End')?.value,
        cutoff2_start_day:      document.getElementById('cutoff2Start')?.value,
        cutoff2_end_day:        document.getElementById('cutoff2End')?.value,
        government_calc_mode:    document.getElementById('btnGovMode')?.dataset.mode || 'STANDARD',
        weekend_pay_date_rule:   document.getElementById('weekendRule')?.value       || 'ADVANCE',
        // Manual gov rates (if manual mode)
        sss_rate:  document.getElementById('sssRate')?.value,
        sss_type:  document.getElementById('sssType')?.value,
        phil_rate: document.getElementById('philRate')?.value,
        phil_type: document.getElementById('philType')?.value,
        pag_rate:  document.getElementById('pagRate')?.value,
        pag_type:  document.getElementById('pagType')?.value,
    };

    // Collect loan auto-deduct toggles only (amounts are per-employee in Loans Management)
    const loans = [];
    document.querySelectorAll('.loan-toggle').forEach(chk => {
        loans.push({ id: chk.dataset.id, is_active: chk.checked ? 1 : 0 });
    });
    payload.loans = JSON.stringify(loans);

    fetchAction(`${PS_BASE_URL}actions/save-payroll-settings.php`, payload, data => {
        if (data.success) {
            document.getElementById('modalSaveConfirm') && modalSave.hide();
            markClean();
            showToast('Payroll settings saved successfully!', 'success');
        } else {
            showToast(data.message || 'Failed to save settings.', 'error');
        }
    });
}

// ─── Utility ────────────────────────────────────────────────
function fetchAction(url, payload, callback) {
    fetch(url, {
        method: 'POST',
        headers: { 'Content-Type': 'application/json' },
        body: JSON.stringify(payload),
    })
    .then(r => r.json())
    .then(callback)
    .catch(() => showToast('Network error. Please try again.', 'error'));
}

function showToast(msg, type = 'success') {
    let container = document.getElementById('toastContainer');
    if (!container) {
        container = document.createElement('div');
        container.id = 'toastContainer';
        container.style.cssText = 'position:fixed;top:20px;right:20px;z-index:9999;display:flex;flex-direction:column;gap:8px;';
        document.body.appendChild(container);
    }

    const toast = document.createElement('div');
    toast.style.cssText = `
        background:${type === 'success' ? '#0d9488' : '#dc2626'};
        color:#fff;padding:12px 18px;border-radius:9px;
        font-size:13.5px;font-weight:500;
        box-shadow:0 4px 16px rgba(0,0,0,.15);
        display:flex;align-items:center;gap:8px;
        animation:slideIn .2s ease;
        font-family:'Plus Jakarta Sans',sans-serif;
    `;

    const icon = type === 'success' ? 'bi-check-circle-fill' : 'bi-x-circle-fill';
    toast.innerHTML = `<i class="bi ${icon}"></i> ${msg}`;

    container.appendChild(toast);

    setTimeout(() => {
        toast.style.opacity = '0';
        toast.style.transform = 'translateX(20px)';
        toast.style.transition = 'all .2s ease';
        setTimeout(() => toast.remove(), 200);
    }, 3000);
}

// ─── Pay Period Manager ───────────────────────────────────────────────────────

let modalPayPeriods    = null;
let modalAccruedPay    = null;

document.addEventListener('DOMContentLoaded', () => {
    // Init Bootstrap modal for pay periods
    const ppEl = document.getElementById('modalPayPeriods');
    if (ppEl) {
        modalPayPeriods = new bootstrap.Modal(ppEl);
        ppEl.addEventListener('hidden.bs.modal', () => {
            document.getElementById('ppModalFlash').style.display = 'none';
            const btn = document.getElementById('ppCreateBtn');
            if (btn) { btn.disabled = false; btn.innerHTML = '<i class="bi bi-plus-lg"></i> Create'; }
        });
    }

    // Init accrued pay modal
    const apEl = document.getElementById('modalAccruedPayPeriod');
    if (apEl) {
        modalAccruedPay = new bootstrap.Modal(apEl);
        apEl.addEventListener('hidden.bs.modal', () => {
            document.getElementById('apFlash').style.display = 'none';
            const btn = document.getElementById('apCreateBtn');
            if (btn) { btn.disabled = false; btn.innerHTML = '<i class="bi bi-plus-lg"></i> Create Accrued Pay Period'; }
        });
    }

    // Scope radio toggle (month vs year)
    document.querySelectorAll('input[name="ppCreateFor"]').forEach(radio => {
        radio.addEventListener('change', function () {
            const isMonth = this.value === 'month';
            document.getElementById('ppMonthRow').style.display    = isMonth ? 'flex' : 'none';
            document.getElementById('ppYearOnlyRow').style.display = isMonth ? 'none' : 'block';
        });
    });
});

function openCreatePeriodsModal() {
    if (modalPayPeriods) modalPayPeriods.show();
}

function openCreateAccruedPayModal() {
    // Pre-fill a sensible name and dates
    const yr = new Date().getFullYear();
    const nameEl = document.getElementById('apName');
    if (nameEl && !nameEl.value) nameEl.value = `EOSY Accrued Pay ${yr}`;
    if (modalAccruedPay) modalAccruedPay.show();
}

async function submitCreateAccruedPay() {
    const name    = (document.getElementById('apName')?.value    || '').trim();
    const start   = (document.getElementById('apStart')?.value   || '').trim();
    const end     = (document.getElementById('apEnd')?.value     || '').trim();
    const payDate = (document.getElementById('apPayDate')?.value || '').trim();
    const flash   = document.getElementById('apFlash');
    const btn     = document.getElementById('apCreateBtn');

    if (!name || !start || !end || !payDate) {
        flash.style.cssText = 'display:block;padding:10px 14px;border-radius:8px;font-size:13px;background:#fee2e2;color:#991b1b;border:1px solid #fca5a5;margin-top:4px;';
        flash.textContent   = 'All fields are required.';
        return;
    }
    if (start > end) {
        flash.style.cssText = 'display:block;padding:10px 14px;border-radius:8px;font-size:13px;background:#fee2e2;color:#991b1b;border:1px solid #fca5a5;margin-top:4px;';
        flash.textContent   = 'Period start must be before period end.';
        return;
    }

    btn.disabled  = true;
    btn.innerHTML = '<i class="bi bi-hourglass-split"></i> Creating…';
    flash.style.display = 'none';

    const fd = new FormData();
    fd.append('create_for',         'accrued_pay');
    fd.append('period_name',        name);
    fd.append('pay_period_start',   start);
    fd.append('pay_period_end',     end);
    fd.append('pay_date',           payDate);

    try {
        const res  = await fetch(`${PS_BASE_URL}actions/create-payroll-periods.php`, { method: 'POST', body: fd });
        const data = await res.json();

        flash.style.cssText = `display:block;padding:10px 14px;border-radius:8px;font-size:13px;margin-top:4px;
            ${data.success
              ? 'background:#d1fae5;color:#065f46;border:1px solid #6ee7b7;'
              : 'background:#fee2e2;color:#991b1b;border:1px solid #fca5a5;'}`;
        flash.textContent = data.message;

        if (data.success) {
            setTimeout(() => location.reload(), 1200);
        } else {
            btn.disabled  = false;
            btn.innerHTML = '<i class="bi bi-plus-lg"></i> Create Accrued Pay Period';
        }
    } catch {
        flash.style.cssText = 'display:block;padding:10px 14px;border-radius:8px;font-size:13px;background:#fee2e2;color:#991b1b;';
        flash.textContent   = 'Network error. Please try again.';
        btn.disabled  = false;
        btn.innerHTML = '<i class="bi bi-plus-lg"></i> Create Accrued Pay Period';
    }
}

async function submitCreatePeriods() {
    const createFor = document.querySelector('input[name="ppCreateFor"]:checked')?.value || 'month';
    const month     = document.getElementById('ppMonth')?.value;
    const year      = createFor === 'year'
        ? document.getElementById('ppYearOnly')?.value
        : document.getElementById('ppYear')?.value;

    const btn   = document.getElementById('ppCreateBtn');
    const flash = document.getElementById('ppModalFlash');

    btn.disabled  = true;
    btn.innerHTML = '<i class="bi bi-hourglass-split"></i> Creating…';
    flash.style.display = 'none';

    const fd = new FormData();
    fd.append('create_for', createFor);
    fd.append('month', month || '');
    fd.append('year',  year  || '');

    try {
        const res  = await fetch(`${PS_BASE_URL}actions/create-payroll-periods.php`, { method: 'POST', body: fd });
        const data = await res.json();

        flash.style.cssText = `display:block; padding:10px 14px; border-radius:8px;
            font-size:13px; margin-bottom:0; margin-top:4px;
            ${data.success
              ? 'background:#d1fae5; color:#065f46; border:1px solid #6ee7b7;'
              : 'background:#fee2e2; color:#991b1b; border:1px solid #fca5a5;'}`;
        flash.textContent = data.message;

        if (data.success && data.created > 0) {
            setTimeout(() => location.reload(), 1200);
        } else {
            btn.disabled  = false;
            btn.innerHTML = '<i class="bi bi-plus-lg"></i> Create';
        }
    } catch {
        flash.style.cssText = 'display:block; padding:10px 14px; border-radius:8px; font-size:13px; background:#fee2e2; color:#991b1b;';
        flash.textContent   = 'Network error. Please try again.';
        btn.disabled  = false;
        btn.innerHTML = '<i class="bi bi-plus-lg"></i> Create';
    }
}

async function deletePeriod(id, name) {
    try {
        await GEI.confirm({
            title:       'Delete Payroll Period',
            message:     `Delete period "${name}"? This will only succeed if no payroll records exist for this period.`,
            note:        'This cannot be undone.',
            type:        'danger',
            confirmText: 'Delete Period',
        });
    } catch { return; }

    try {
        const fd = new FormData();
        fd.append('action', 'delete');
        fd.append('period_id', id);
        const res  = await fetch(`${PS_BASE_URL}actions/create-payroll-periods.php`, { method: 'POST', body: fd });
        const data = await res.json();
        if (data.success) {
            location.reload();
        } else {
            alert(data.message);
        }
    } catch {
        alert('Network error. Please try again.');
    }
}

// ─── Period Status + Year Filter ─────────────────────────────

let _ppActiveStatus = 'ALL';
let _ppActiveYear   = '';

function filterPeriodStatus(btn, status) {
    _ppActiveStatus = status;
    document.querySelectorAll('#ppStatusTabs .pp-st-tab').forEach(b => b.classList.remove('active'));
    btn.classList.add('active');
    applyPeriodFilters();
}

function filterPeriodYear(year) {
    _ppActiveYear = year;
    applyPeriodFilters();
}

function applyPeriodFilters() {
    const rows = document.querySelectorAll('.pp-table tbody tr[data-status]');
    let visible = 0;
    rows.forEach(row => {
        const showSt = _ppActiveStatus === 'ALL' || row.dataset.status === _ppActiveStatus;
        const showYr = !_ppActiveYear  || row.dataset.year  === _ppActiveYear;
        const show   = showSt && showYr;
        row.style.display = show ? '' : 'none';
        if (show) visible++;
    });
    const noResults = document.getElementById('ppNoResults');
    if (noResults) noResults.style.display = (visible === 0 && rows.length > 0) ? '' : 'none';
}

// ─── Edit Pay Period ──────────────────────────────────────────

let _editPeriodModal = null;

document.addEventListener('DOMContentLoaded', () => {
    const epEl = document.getElementById('modalEditPeriod');
    if (epEl && window.bootstrap) _editPeriodModal = new bootstrap.Modal(epEl);
});

function openEditPeriodModal(id, name, payDate, recordCount) {
    if (!_editPeriodModal) return;
    document.getElementById('epId').value      = id;
    document.getElementById('epName').value    = name;
    document.getElementById('epPayDate').value = payDate;

    const noteEl = document.getElementById('epRecordNote');
    const textEl = document.getElementById('epRecordNoteText');
    if (recordCount > 0) {
        textEl.textContent = `This period has ${recordCount} payroll record(s). Only the name and pay date can be changed.`;
        noteEl.style.display = '';
    } else {
        noteEl.style.display = 'none';
    }

    const btn = document.getElementById('epSaveBtn');
    if (btn) { btn.disabled = false; btn.innerHTML = '<i class="bi bi-floppy"></i> Save Changes'; }

    _editPeriodModal.show();
}

async function submitEditPeriod() {
    const name    = document.getElementById('epName')?.value.trim();
    const payDate = document.getElementById('epPayDate')?.value;
    const id      = document.getElementById('epId')?.value;
    const btn     = document.getElementById('epSaveBtn');

    if (!name || !payDate) { showToast('Name and pay date are required.', 'error'); return; }

    btn.disabled  = true;
    btn.innerHTML = '<i class="bi bi-hourglass-split"></i> Saving…';

    try {
        const fd = new FormData();
        fd.append('action',      'update');
        fd.append('period_id',   id);
        fd.append('period_name', name);
        fd.append('pay_date',    payDate);
        const res  = await fetch(`${PS_BASE_URL}actions/create-payroll-periods.php`, { method: 'POST', body: fd });
        const data = await res.json();
        if (data.success) {
            location.reload();
        } else {
            showToast(data.message || 'Failed to update period.', 'error');
            btn.disabled  = false;
            btn.innerHTML = '<i class="bi bi-floppy"></i> Save Changes';
        }
    } catch {
        showToast('Network error. Please try again.', 'error');
        btn.disabled  = false;
        btn.innerHTML = '<i class="bi bi-floppy"></i> Save Changes';
    }
}
