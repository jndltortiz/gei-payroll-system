// ================================
// MODAL OPEN / CLOSE
// ================================
window.openModal = function(id) {
    const modal = document.getElementById(id);
    if (modal) modal.classList.add('active');
};
window.closeModal = function(id) {
    const modal = document.getElementById(id);
    if (modal) modal.classList.remove('active');
};
document.addEventListener('click', function(e) {
    if (e.target.classList.contains('modal')) {
        e.target.classList.remove('active');
    }
});

// ================================
// WIZARD STATE
// ================================
const _wizState = {};
const WIZ_TOTAL = 5;

function _wizGetState(modalId) {
    if (!_wizState[modalId]) _wizState[modalId] = { step: 1 };
    return _wizState[modalId];
}

function _wizUpdateNav(modalId) {
    const state = _wizGetState(modalId);
    const step  = state.step;
    const modal = document.getElementById(modalId);
    if (!modal) return;

    const prevBtn = modal.querySelector('[id$="WizPrev"]');
    const nextBtn = modal.querySelector('[id$="WizNext"]');
    const saveBtn = modal.querySelector('[id$="WizSave"]');

    if (prevBtn) prevBtn.style.display = step === 1 ? 'none' : '';
    if (nextBtn) nextBtn.style.display = step === WIZ_TOTAL ? 'none' : '';
    if (saveBtn) saveBtn.style.display = step === WIZ_TOTAL ? '' : 'none';
}

function _wizShowStep(modalId, step) {
    const modal = document.getElementById(modalId);
    if (!modal) return;

    // Hide all panels
    modal.querySelectorAll('.wiz-panel').forEach(p => p.classList.remove('active'));
    // Show target
    const panel = modal.querySelector(`#${modalId}Step${step}`);
    if (panel) panel.classList.add('active');

    // Update step indicators
    modal.querySelectorAll('.wiz-step-item').forEach(item => {
        item.classList.remove('active', 'completed');
        const s = parseInt(item.dataset.step);
        if (s === step) item.classList.add('active');
        if (s < step)  item.classList.add('completed');
    });

    // Scroll modal body to top
    const body = modal.querySelector('.modal-body');
    if (body) body.scrollTop = 0;
}

// Per-step required field validation
function _wizValidateStep(modalId, step) {
    const panel = document.getElementById(`${modalId}Step${step}`);
    if (!panel) return true;

    const required = panel.querySelectorAll('[data-req="1"]');
    let valid = true;
    const errors = [];

    required.forEach(el => {
        // Clear previous error state
        el.classList.remove('input-error');
        const errEl = el.parentElement.querySelector('.field-error');
        if (errEl) errEl.remove();

        const val = el.value ? el.value.trim() : '';
        if (!val) {
            valid = false;
            el.classList.add('input-error');
            const label = el.dataset.label || 'This field';
            errors.push(label);
            const msg = document.createElement('span');
            msg.className = 'field-error';
            msg.innerHTML = `<i class="fa fa-triangle-exclamation"></i> ${label} is required`;
            el.parentElement.appendChild(msg);
        }
    });

    if (!valid && typeof showToast === 'function') {
        showToast('Please fill in: ' + errors.slice(0,3).join(', ') + (errors.length > 3 ? '…' : ''), 'error');
    }
    return valid;
}

window.wizNav = function(modalId, dir) {
    const state = _wizGetState(modalId);
    const next  = state.step + dir;
    if (next < 1 || next > WIZ_TOTAL) return;

    // Forward navigation requires current step to be valid
    if (dir > 0 && !_wizValidateStep(modalId, state.step)) return;

    state.step = next;
    _wizShowStep(modalId, next);
    _wizUpdateNav(modalId);
};

function _wizReset(modalId) {
    _wizGetState(modalId).step = 1;
    _wizShowStep(modalId, 1);
    _wizUpdateNav(modalId);
}

// ================================
// OPEN / CLOSE ADD WIZARD
// ================================
window.openAddWizard = function() {
    const form = document.getElementById('addEmployeeForm');
    if (form) form.reset();
    // Reset edu/doc containers
    const eduC = document.getElementById('addEduEntriesContainer');
    if (eduC) {
        eduC.innerHTML = _buildEduEntryHTML(0, 1);
    }
    const docC = document.getElementById('addDocUploadList');
    if (docC) {
        docC.innerHTML = _buildDocRowHTML(0);
    }
    // Reset shift preview
    ['addShiftPreview','editShiftPreview'].forEach(id => {
        const el = document.getElementById(id);
        if (el) el.style.display = 'none';
    });
    ['addShiftEmpty','editShiftEmpty'].forEach(id => {
        const el = document.getElementById(id);
        if (el) el.style.display = '';
    });

    _wizReset('addEmployeeModal');
    openModal('addEmployeeModal');
};

window.closeAddWizard = function() {
    closeModal('addEmployeeModal');
};

// ================================
// OPEN / CLOSE EDIT WIZARD
// ================================
window.openEditWizard = function(id) {
    fetch(BASE_URL + 'actions/get-employee.php?id=' + id)
        .then(res => res.json())
        .then(d => {
            if (!d) return;

            const setId = (elId, val) => {
                const el = document.getElementById(elId);
                if (el) el.value = val ?? '';
            };

            // Hidden
            setId('editEmployeeId', d.employee_id);
            // Personal
            setId('editFirstName',   d.first_name);
            setId('editMiddleName',  d.middle_name);
            setId('editLastName',    d.last_name);
            setId('editSuffix',      d.suffix);
            setId('editSex',         d.sex);
            setId('editCivilStatus', d.civil_status);
            setId('editBirthDate',   d.birth_date);
            setId('editContactNo',   d.contact_no);
            setId('editPersonalEmail', d.personal_email);
            setId('editAddress',     d.address);
            setId('editEcName',      d.emergency_contact_name);
            setId('editEcRelation',  d.emergency_contact_relation);
            setId('editEcNumber',    d.emergency_contact_number);
            // Job
            setId('editEmployeeNo',       d.employee_no);
            setId('editDeptSelect',       d.department_id);
            setId('editEmploymentType',   d.employment_type);
            setId('editHireDate',         d.hire_date);
            setId('editEmployeeStatus',   d.employee_status);
            setId('editBasicSalary',      d.monthly_salary);
            loadPositions('editDeptSelect', 'editPosSelect', d.position_id);
            setId('editShiftSelect', d.shift_id);
            previewShift('editShiftSelect','editShiftPreview','editShiftEmpty');
            // Gov IDs
            setId('editSssNo',       d.sss_no);
            setId('editPhilhealthNo',d.philhealth_no);
            setId('editPagibigNo',   d.pagibig_no);
            setId('editTinNo',       d.tin_no);
            setId('editPeraaNo',     d.peraa_no);
            // Account
            setId('editEmail',    d.email);
            setId('editUsername', d.username);
            setId('editRole',     d.role_name);

            // Force-change toggle — reflect current DB state
            const forceToggle = document.getElementById('editForceToggle');
            const forceHidden = document.getElementById('editForceHidden');
            const mustChange  = parseInt(d.must_change_password ?? 0);
            if (forceToggle && forceHidden) {
                if (mustChange === 1) {
                    forceToggle.classList.add('on');
                    forceHidden.value = '1';
                } else {
                    forceToggle.classList.remove('on');
                    forceHidden.value = '0';
                }
            }

            // Clear any previous email/username inline errors
            ['editEmailError','editUsernameWarn'].forEach(id => {
                const el = document.getElementById(id);
                if (el) { el.style.display = 'none'; el.textContent = ''; }
            });
            const editEmailEl = document.getElementById('editEmail');
            if (editEmailEl) editEmailEl.classList.remove('input-error');

            // Education entries
            const eduC = document.getElementById('editEduEntriesContainer');
            if (eduC) {
                eduC.innerHTML = '';
                const entries = d.education || [];
                if (entries.length === 0) {
                    eduC.innerHTML = _buildEduEntryHTML(0, 1);
                } else {
                    entries.forEach((edu, i) => {
                        const div = document.createElement('div');
                        div.innerHTML = _buildEduEntryHTML(i, i + 1);
                        const entry = div.firstElementChild;
                        // Parse description: "Degree — Course"
                        const parts = (edu.description || '').split(' — ');
                        const deg  = parts[0] || '';
                        const crs  = parts.slice(1).join(' — ') || '';
                        const sel  = entry.querySelector('select[name="edu_degree[]"]');
                        const iCrs = entry.querySelector('input[name="edu_course[]"]');
                        const iSch = entry.querySelector('input[name="edu_school[]"]');
                        const iYr  = entry.querySelector('input[name="edu_year[]"]');
                        if (sel)  sel.value  = deg;
                        if (iCrs) iCrs.value = crs;
                        if (iSch) iSch.value = edu.institution || '';
                        if (iYr)  iYr.value  = edu.year_obtained || '';
                        eduC.appendChild(entry);
                    });
                    if (entries.length > 1) {
                        eduC.querySelectorAll('.edu-remove-btn').forEach(b => b.style.display = 'inline-flex');
                    }
                }
            }

            // Existing documents list
            const existingDocsWrap = document.getElementById('editExistingDocs');
            const existingDocsList = document.getElementById('editExistingDocsList');
            const docs = d.documents || [];
            if (existingDocsWrap && existingDocsList) {
                if (docs.length > 0) {
                    existingDocsWrap.style.display = '';
                    existingDocsList.innerHTML = docs.map(doc =>
                        `<div class="existing-doc-item">
                           <i class="fa fa-file"></i>
                           <a href="${BASE_URL}${escHtml(doc.file_path)}" target="_blank"
                              style="flex:1;color:#0369a1;text-decoration:none;font-size:13px;">
                             ${escHtml(doc.doc_type || 'Document')}: ${escHtml(doc.doc_name)}
                           </a>
                           <span class="existing-doc-size">${formatFileSize(doc.file_size)}</span>
                         </div>`
                    ).join('');
                } else {
                    existingDocsWrap.style.display = 'none';
                }
            }

            // Reset doc upload list
            const docC = document.getElementById('editDocUploadList');
            if (docC) docC.innerHTML = _buildDocRowHTML(0);

            // Subtitle
            const subtitle = document.getElementById('editModalSubtitle');
            if (subtitle) subtitle.textContent = 'Editing: ' + (d.first_name || '') + ' ' + (d.last_name || '');

            // Store ID for openEditFromView
            window._editingEmployeeId = id;

            _wizReset('editEmployeeModal');
            openModal('editEmployeeModal');
        })
        .catch(err => {
            console.error('openEditWizard error:', err);
            if (typeof showToast === 'function') showToast('Failed to load employee data.', 'error');
        });
};

window.closeEditWizard = function() {
    closeModal('editEmployeeModal');
};

// ================================
// VIEW EMPLOYEE — FULL PROFILE
// ================================
window.openViewProfile = function(id) {
    window._viewingEmployeeId = id;

    fetch(BASE_URL + 'actions/get-employee-profile.php?id=' + id)
        .then(res => res.json())
        .then(data => {
            const d = data.employee || {};
            if (!d.employee_id) return;

            // Header
            const initials = ((d.first_name || '')[0] || '') + ((d.last_name || '')[0] || '');
            setTxt('profileAvatar', initials.toUpperCase());

            const fullName = [d.first_name, d.middle_name, d.last_name, d.suffix].filter(Boolean).join(' ');
            setTxt('profileName', fullName || '—');
            setTxt('profileSubtitle', [(d.position_name || ''), (d.department_name || '')].filter(Boolean).join(' · ') || '—');

            const badge = document.getElementById('profileStatusBadge');
            if (badge) {
                const st = (d.employee_status || 'unknown').toLowerCase();
                badge.textContent = ucFirst(st);
                badge.className   = 'badge ' + st;
            }

            // ── Overview tab ──
            const legalName = [d.last_name, d.first_name, d.middle_name].filter(Boolean).join(', ') + (d.suffix ? ' ' + d.suffix : '');
            setTxt('pvLegalName',     legalName || '—');
            setTxt('pvBirthDate',     d.birth_date ? formatDate(d.birth_date) : '—');
            setTxt('pvSex',           d.sex ? ucFirst(d.sex.toLowerCase()) : '—');
            setTxt('pvCivilStatus',   d.civil_status ? ucFirst(d.civil_status.toLowerCase()) : '—');
            setTxt('pvContact',       d.contact_no || '—');
            setTxt('pvPersonalEmail', d.personal_email || '—');
            setTxt('pvSchoolEmail',   d.email || '—');
            setTxt('pvAddress',       d.address || '—');
            setTxt('pvEcName',        d.emergency_contact_name || '—');
            setTxt('pvEcRelation',    d.emergency_contact_relation || '—');
            setTxt('pvEcNumber',      d.emergency_contact_number || '—');

            // ── Job & Payroll tab ──
            setTxt('pvEmpNo',         d.employee_no || '—');
            setTxt('pvDept',          d.department_name || '—');
            setTxt('pvPosition',      d.position_name || '—');
            setTxt('pvEmpType',       d.employment_type === 'FULL_TIME' ? 'Full-time' : (d.employment_type === 'PART_TIME' ? 'Part-time' : '—'));
            setTxt('pvHireDate',      d.hire_date ? formatDate(d.hire_date) : '—');
            setTxt('pvShift',         fmtShift(d.shift_start, d.shift_end));
            setTxt('pvSalary',        d.monthly_salary ? '₱' + fmtNum(d.monthly_salary) : '—');
            setTxt('pvDailyRate',     d.daily_rate ? '₱' + fmtNum(d.daily_rate) : '—');

            const ps = data.last_payslip;
            if (ps) {
                setTxt('pvLastPayslip', ps.period_label || (formatDate(ps.period_start) + ' – ' + formatDate(ps.period_end)));
                setTxt('pvLastNetPay',  ps.net_pay ? '₱' + fmtNum(ps.net_pay) : '—');
            } else {
                setTxt('pvLastPayslip', 'No payslip yet');
                setTxt('pvLastNetPay',  '—');
            }

            // ── Government IDs tab ──
            setTxt('pvSss',        d.sss_no        || '—');
            setTxt('pvPhilhealth', d.philhealth_no  || '—');
            setTxt('pvPagibig',    d.pagibig_no     || '—');
            setTxt('pvTin',        d.tin_no         || '—');
            setTxt('pvPeraa',      d.peraa_no       || '—');

            // ── Education & Documents tab ──
            const eduEl = document.getElementById('pvEduList');
            if (eduEl) {
                const edu = data.education || [];
                if (edu.length > 0) {
                    eduEl.innerHTML = edu.map(e =>
                        `<div class="pv-edu-item">
                           <strong>${escHtml(e.description || '—')}</strong>
                           <span>${escHtml(e.institution || '—')}${e.year_obtained ? ' · ' + e.year_obtained : ''}</span>
                         </div>`
                    ).join('');
                } else {
                    eduEl.innerHTML = '<p class="pv-empty-note">No education records on file.</p>';
                }
            }

            const docEl = document.getElementById('pvDocList');
            if (docEl) {
                const docs = data.documents || [];
                if (docs.length > 0) {
                    docEl.innerHTML = docs.map(doc =>
                        `<div class="pv-doc-item">
                           <i class="fa fa-file-lines"></i>
                           <div>
                             <strong>${escHtml(doc.doc_type || 'Document')}</strong>
                             <a href="${BASE_URL}${escHtml(doc.file_path)}" target="_blank"
                                style="display:block;font-size:12px;color:#0369a1;text-decoration:none;word-break:break-all;">
                               ${escHtml(doc.doc_name)} · ${formatFileSize(doc.file_size)}
                             </a>
                           </div>
                         </div>`
                    ).join('');
                } else {
                    docEl.innerHTML = '<p class="pv-empty-note">No documents on file.</p>';
                }
            }

            // ── Leave & Loans tab ──
            const leaveEl = document.getElementById('pvLeaveBalances');
            if (leaveEl) {
                const lb = data.leave_balances || [];
                if (lb.length > 0) {
                    leaveEl.innerHTML = lb.map(l =>
                        `<div class="pv-leave-item">
                           <span class="pv-leave-name">${escHtml(l.leave_name)}</span>
                           <div class="pv-leave-bar-wrap">
                             <div class="pv-leave-bar" style="width:${Math.max(0, Math.min(100, (parseFloat(l.used_days) / (parseFloat(l.allocated_days) || 1)) * 100))}%"></div>
                           </div>
                           <span class="pv-leave-numbers">${parseFloat(l.remaining).toFixed(1)} / ${parseFloat(l.allocated_days).toFixed(1)} days remaining</span>
                         </div>`
                    ).join('');
                } else {
                    leaveEl.innerHTML = '<p class="pv-empty-note">No leave balance data available.</p>';
                }
            }

            const loanEl = document.getElementById('pvLoans');
            if (loanEl) {
                const loans = data.loans || [];
                if (loans.length > 0) {
                    loanEl.innerHTML = loans.map(ln =>
                        `<div class="pv-loan-item">
                           <strong>${escHtml(ln.loan_type || 'Loan')}</strong>
                           <span>Balance: ₱${fmtNum(ln.outstanding_balance)} of ₱${fmtNum(ln.principal_amount)}</span>
                           <span class="badge ${(ln.status || '').toLowerCase()}">${ucFirst((ln.status || '').toLowerCase())}</span>
                         </div>`
                    ).join('');
                } else {
                    loanEl.innerHTML = '<p class="pv-empty-note">No active loans.</p>';
                }
            }

            const scEl = document.getElementById('pvServiceCredits');
            if (scEl) {
                const sc = data.sc_summary;
                if (sc && parseInt(sc.total_entries) > 0) {
                    scEl.innerHTML = `
                        <div class="profile-detail-grid">
                          <div class="profile-detail-row"><span class="pdr-label">Total Entries</span><span class="pdr-val">${sc.total_entries}</span></div>
                          <div class="profile-detail-row"><span class="pdr-label">Paid Days</span><span class="pdr-val">${parseFloat(sc.paid_days || 0).toFixed(2)} days</span></div>
                          <div class="profile-detail-row"><span class="pdr-label">Pending/Unpaid</span><span class="pdr-val">${parseFloat(sc.unpaid_days || 0).toFixed(2)} days</span></div>
                        </div>`;
                } else {
                    scEl.innerHTML = '<p class="pv-empty-note">No service credit data available.</p>';
                }
            }

            // ── Account tab ──
            setTxt('pvUsername',      d.username || '—');
            setTxt('pvAccountEmail',  d.email    || '—');
            setTxt('pvRole',          d.role_name || '—');
            const accStatusEl = document.getElementById('pvAccountStatus');
            if (accStatusEl) {
                const active = d.user_is_active === 1 || d.user_is_active === '1';
                accStatusEl.innerHTML = active
                    ? '<span class="badge active">Active</span>'
                    : '<span class="badge inactive">Inactive</span>';
            }

            // Reset to first tab
            switchProfileTab(
                document.querySelector('#viewEmployeeModal .ptab'),
                'ptab-overview'
            );

            openModal('viewEmployeeModal');
        })
        .catch(err => {
            console.error('openViewProfile error:', err);
            if (typeof showToast === 'function') showToast('Failed to load employee profile.', 'error');
        });
};

window.switchProfileTab = function(btn, tabId) {
    const modal = document.getElementById('viewEmployeeModal');
    if (!modal) return;
    modal.querySelectorAll('.ptab').forEach(t => t.classList.remove('active'));
    modal.querySelectorAll('.ptab-panel').forEach(p => p.classList.remove('active'));
    if (btn) btn.classList.add('active');
    const panel = document.getElementById(tabId);
    if (panel) panel.classList.add('active');
};

window.openEditFromView = function() {
    closeModal('viewEmployeeModal');
    if (window._viewingEmployeeId) {
        openEditWizard(window._viewingEmployeeId);
    }
};

// Backwards-compat shim so other code that calls viewEmployee() still works
window.viewEmployee = function(id) { openViewProfile(id); };
window.editEmployee = function(id) { openEditWizard(id); };

// ================================
// DEACTIVATE
// ================================
window.setDeactivate = function(id, name, dept) {
    document.getElementById('deact_id').value = id;

    const snap = document.getElementById('deactEmployeeSnap');
    const snapAvatar = document.getElementById('deactSnapAvatar');
    const snapName   = document.getElementById('deactSnapName');
    const snapDept   = document.getElementById('deactSnapDept');

    if (snap && snapAvatar && snapName) {
        const parts = (name || '').split(' ');
        snapAvatar.textContent = ((parts[0] || '')[0] || '') + ((parts[parts.length - 1] || '')[0] || '');
        snapName.textContent   = name || '—';
        if (snapDept) snapDept.textContent = dept || '';
        snap.style.display = '';
    }

    const msg = document.getElementById('deactMsg');
    if (msg) {
        msg.textContent = `Are you sure you want to deactivate ${name || 'this employee'}? They will lose system access.`;
    }

    openModal('deactEmployeeModal');
};

// ================================
// SAVE CONFIRMATION (2-step)
// ================================
let _pendingFormId   = null;
let _pendingSaveType = null;

window.prepareSave = function(formId, type) {
    const form = document.getElementById(formId);
    if (!form) return;

    // Full-form required validation (catch any step we might have skipped)
    const allReq  = form.querySelectorAll('[data-req="1"]');
    const missing = [];
    allReq.forEach(el => {
        if (!el.value || !el.value.trim()) {
            missing.push(el.dataset.label || 'field');
        }
    });
    if (missing.length > 0) {
        if (typeof showToast === 'function') {
            showToast('Please complete: ' + missing.slice(0,4).join(', ') + (missing.length > 4 ? '…' : ''), 'error', 5000);
        }
        return;
    }

    _pendingFormId   = formId;
    _pendingSaveType = type ?? 'add';

    if (_pendingSaveType === 'edit') {
        openModal('saveEditModal');
    } else {
        const firstName = form.querySelector('[name="first_name"]')?.value ?? '';
        const lastName  = form.querySelector('[name="last_name"]')?.value  ?? '';
        const msg = document.getElementById('saveAddMsg');
        if (msg) {
            msg.textContent = `Add ${firstName} ${lastName} as a new employee?`;
        }
        openModal('saveAddModal');
    }
};

window.confirmSave = async function(type) {
    if (!_pendingFormId) return;
    const form = document.getElementById(_pendingFormId);
    if (!form) return;

    if (type === 'add') {
        closeModal('saveAddModal');
        const btn = form.querySelector('.btn-save');
        if (btn) { btn.disabled = true; btn.innerHTML = '<i class="fa fa-spinner fa-spin"></i> Saving…'; }

        try {
            const res  = await fetch(BASE_URL + 'actions/employee-save.php',
                                     { method: 'POST', body: new FormData(form) });
            const data = await res.json();
            if (data.success) {
                if (typeof showToast === 'function') showToast(data.message, 'success', 5000);
                setTimeout(() => location.reload(), 1200);
            } else {
                if (typeof showToast === 'function') showToast(data.message || 'Failed to save.', 'error');
                if (btn) { btn.disabled = false; btn.innerHTML = '<i class="fa fa-floppy-disk"></i> Save Employee'; }
            }
        } catch {
            if (typeof showToast === 'function') showToast('Network error. Please try again.', 'error');
            if (btn) { btn.disabled = false; btn.innerHTML = '<i class="fa fa-floppy-disk"></i> Save Employee'; }
        }
    } else {
        // Edit — AJAX (so we can handle duplicate-email / username errors)
        closeModal('saveEditModal');
        const btn = form.querySelector('.btn-save');
        if (btn) { btn.disabled = true; btn.innerHTML = '<i class="fa fa-spinner fa-spin"></i> Saving…'; }

        try {
            const res  = await fetch(BASE_URL + 'actions/employee-update.php',
                                     { method: 'POST', body: new FormData(form) });
            const data = await res.json();
            if (data.success) {
                if (typeof showToast === 'function') showToast(data.message, 'success', 5000);
                setTimeout(() => location.reload(), 1200);
            } else {
                if (typeof showToast === 'function') showToast(data.message || 'Failed to update.', 'error');
                if (btn) { btn.disabled = false; btn.innerHTML = '<i class="fa fa-floppy-disk"></i> Update Employee'; }
                openModal('editEmployeeModal');
            }
        } catch {
            if (typeof showToast === 'function') showToast('Network error. Please try again.', 'error');
            if (btn) { btn.disabled = false; btn.innerHTML = '<i class="fa fa-floppy-disk"></i> Update Employee'; }
            openModal('editEmployeeModal');
        }
    }
};

// ================================
// DEPARTMENT → POSITION CASCADE
// ================================
window.loadPositions = function(deptSelectId, posSelectId, preselectId) {
    const deptEl = document.getElementById(deptSelectId);
    const posEl  = document.getElementById(posSelectId);
    if (!deptEl || !posEl) return;

    const deptId   = parseInt(deptEl.value);
    const options  = posEl.querySelectorAll('option');
    let firstVisible = null;

    options.forEach(opt => {
        if (!opt.value) return;
        const optDept = parseInt(opt.getAttribute('data-department'));
        const show    = (!deptId || optDept === deptId);
        opt.style.display = show ? '' : 'none';
        if (show && !firstVisible) firstVisible = opt.value;
    });

    const placeholder = posEl.querySelector('option[value=""]');
    if (placeholder) {
        placeholder.textContent = deptId ? 'Select position...' : 'Select department first...';
    }

    if (preselectId) {
        posEl.value = preselectId;
    } else {
        posEl.value = '';
    }
};

// ================================
// SHIFT PREVIEW
// ================================
window.previewShift = function(selectId, previewId, emptyId) {
    const sel     = document.getElementById(selectId);
    const preview = document.getElementById(previewId);
    const empty   = document.getElementById(emptyId);
    if (!sel || !preview || !empty) return;

    const opt = sel.options[sel.selectedIndex];
    if (!opt || !opt.value) {
        preview.style.display = 'none';
        empty.style.display   = '';
        return;
    }

    const prefix = selectId.replace('ShiftSelect', 'Shift');
    document.getElementById(selectId.replace('ShiftSelect','ShiftPreviewTitle'))
            ?.setAttribute && null;
    const titleEl = document.getElementById(prefix + 'PreviewTitle');
    if (titleEl) titleEl.textContent = 'SHIFT — ' + (opt.getAttribute('data-name') || '').toUpperCase();

    const setEl = (suffix, val) => {
        const el = document.getElementById(prefix + suffix);
        if (el) el.textContent = val;
    };
    setEl('Start', opt.getAttribute('data-start') ? fmtTime(opt.getAttribute('data-start')) : '—');
    setEl('End',   opt.getAttribute('data-end')   ? fmtTime(opt.getAttribute('data-end'))   : '—');
    setEl('Grace', opt.getAttribute('data-grace') || '—');

    preview.style.display = '';
    empty.style.display   = 'none';
};

// ================================
// AUTO-FILL USERNAME FROM EMAIL
// ================================
window.autoFillUsername = function(emailId, usernameId) {
    const email    = document.getElementById(emailId);
    const username = document.getElementById(usernameId);
    if (!email || !username) return;
    const prefix = email.value.split('@')[0];
    username.value = prefix.toLowerCase().replace(/[^a-z0-9.]/g, '');
};

// ================================
// EMAIL / USERNAME DUPLICATE CHECK
// ================================
/**
 * Called on blur of email fields.
 * prefix: 'add' | 'edit'
 * employeeId: numeric id when editing, 0 for create
 */
async function _checkEmailUniqueness(prefix, employeeId) {
    const emailEl    = document.getElementById(prefix + 'Email');
    const emailErr   = document.getElementById(prefix + 'EmailError');
    const userWarn   = document.getElementById(prefix + 'UsernameWarn');
    const userInput  = document.getElementById(prefix + 'Username');
    if (!emailEl) return;

    const email = emailEl.value.trim();

    // Clear previous messages
    if (emailErr)  { emailErr.style.display = 'none';  emailErr.textContent  = ''; }
    if (userWarn)  { userWarn.style.display = 'none';  userWarn.textContent  = ''; }
    if (emailEl)    emailEl.classList.remove('input-error');

    if (!email) return;

    try {
        const params = new URLSearchParams({ email });
        if (employeeId) params.set('employee_id', employeeId);
        const res  = await fetch(BASE_URL + 'actions/check-email.php?' + params.toString());
        const data = await res.json();

        // Email error
        if (!data.email_ok && emailErr) {
            emailErr.innerHTML  = '<i class="fa fa-triangle-exclamation"></i> ' + escHtml(data.email_error);
            emailErr.style.display = '';
            emailEl.classList.add('input-error');
        }

        // Username conflict — update the hidden field to the safe suggestion
        if (data.suggested_username && userInput) {
            userInput.value = data.suggested_username;
        }
        if (!data.username_ok && userWarn) {
            const orig = (email.split('@')[0] || '').toLowerCase().replace(/[^a-z0-9.]/g, '');
            userWarn.innerHTML  = '<i class="fa fa-info-circle"></i> "' + escHtml(orig)
                + '" is taken — will use "' + escHtml(data.suggested_username) + '"';
            userWarn.style.display = '';
        }
    } catch (e) {
        // Network failure — silent; backend will catch it
    }
}

document.addEventListener('DOMContentLoaded', () => {
    // ── Add modal email blur ──────────────────────────────────────────────────
    const addEmailEl = document.getElementById('addEmail');
    if (addEmailEl) {
        addEmailEl.addEventListener('blur', function() {
            // Auto-complete domain
            if (this.value && !this.value.includes('@')) {
                this.value = this.value.toLowerCase() + '@gei.edu.ph';
                autoFillUsername('addEmail', 'addUsername');
            } else if (this.value.includes('@') && !this.value.toLowerCase().endsWith('@gei.edu.ph')) {
                const prefix = this.value.split('@')[0];
                this.value   = prefix.toLowerCase() + '@gei.edu.ph';
            }
            autoFillUsername('addEmail', 'addUsername');
            _checkEmailUniqueness('add', 0);
        });
        addEmailEl.addEventListener('focus', function() {
            if (!this.value) this.placeholder = 'firstname.lastname';
        });
    }

    // ── Edit modal email blur ─────────────────────────────────────────────────
    const editEmailEl = document.getElementById('editEmail');
    if (editEmailEl) {
        editEmailEl.addEventListener('blur', function() {
            if (this.value && !this.value.includes('@')) {
                this.value = this.value.toLowerCase() + '@gei.edu.ph';
                autoFillUsername('editEmail', 'editUsername');
            } else if (this.value.includes('@') && !this.value.toLowerCase().endsWith('@gei.edu.ph')) {
                const prefix = this.value.split('@')[0];
                this.value   = prefix.toLowerCase() + '@gei.edu.ph';
            }
            autoFillUsername('editEmail', 'editUsername');
            const empId = parseInt(document.getElementById('editEmployeeId')?.value || '0');
            _checkEmailUniqueness('edit', empId);
        });
        editEmailEl.addEventListener('focus', function() {
            if (!this.value) this.placeholder = 'firstname.lastname';
        });
    }
});

// ================================
// PASSWORD TOGGLE
// ================================
window.togglePassword = function(inputId, iconEl) {
    const input = document.getElementById(inputId);
    if (!input) return;
    if (input.type === 'password') {
        input.type = 'text';
        iconEl.querySelector('i').className = 'fa fa-eye-slash';
    } else {
        input.type = 'password';
        iconEl.querySelector('i').className = 'fa fa-eye';
    }
};

// ================================
// GENERATE STRONG PASSWORD
// ================================
window.generatePassword = function(inputId) {
    const chars = 'ABCDEFGHJKMNPQRSTUVWXYZabcdefghjkmnpqrstuvwxyz23456789!@#$%';
    let pw = '';
    for (let i = 0; i < 12; i++) pw += chars[Math.floor(Math.random() * chars.length)];
    const input = document.getElementById(inputId);
    if (input) { input.type = 'text'; input.value = pw; }
};

// ================================
// TOGGLE SWITCH (force password)
// ================================
window.toggleSwitch = function(el, hiddenId) {
    el.classList.toggle('on');
    const hidden = document.getElementById(hiddenId);
    if (hidden) hidden.value = el.classList.contains('on') ? '1' : '0';
};

// ================================
// SEARCH TABLE (client-side)
// ================================
window.searchTable = function() {
    const input   = document.getElementById('searchInput').value.toLowerCase();
    const rows    = document.querySelectorAll('#empTable tbody tr:not(#empNoResults)');
    let   visible = 0;

    rows.forEach(row => {
        const show = row.innerText.toLowerCase().includes(input);
        row.style.display = show ? '' : 'none';
        if (show) visible++;
    });

    let noRes = document.getElementById('empNoResults');
    if (input && visible === 0) {
        if (!noRes) {
            const tbody = document.querySelector('#empTable tbody');
            const tr    = document.createElement('tr');
            tr.id = 'empNoResults';
            tr.innerHTML = `<td colspan="9"><div class="empty-state" style="padding:40px 24px;">
                <i class="fa fa-magnifying-glass"></i>
                <p>No results for "${input}"</p>
                <small>Try a different name or employee number</small></div></td>`;
            tbody.appendChild(tr);
        } else {
            noRes.style.display = '';
        }
    } else if (noRes) {
        noRes.style.display = 'none';
    }
};

// ================================
// DEPARTMENT FILTER
// ================================
window.filterDept = function() {
    const dept   = document.getElementById('deptFilterSelect').value;
    const search = document.getElementById('searchInput').value;
    window.location.href = '?dept=' + dept + '&search=' + encodeURIComponent(search);
};

// ================================
// DYNAMIC EDUCATION ENTRIES
// ================================
let _addEduCount  = 1;
let _editEduCount = 0;

function _buildEduEntryHTML(index, num) {
    return `<div class="edu-entry" data-index="${index}">
      <div class="edu-entry-header">
        <span class="edu-entry-label">Entry ${num}</span>
        <button type="button" class="edu-remove-btn" onclick="removeEduEntry(this)" style="display:none;">
          <i class="fa fa-times"></i> Remove
        </button>
      </div>
      <div class="form-grid">
        <div class="form-group">
          <label>Level / Degree</label>
          <select name="edu_degree[]">
            <option value="">— Select —</option>
            <option>Bachelor's Degree</option><option>Master's Degree</option>
            <option>Doctorate</option><option>Senior High School</option>
            <option>High School</option><option>Vocational / Tech</option>
            <option>Elementary</option>
          </select>
        </div>
        <div class="form-group">
          <label>Course / Major</label>
          <input type="text" name="edu_course[]" placeholder="e.g. Bachelor of Education">
        </div>
        <div class="form-group">
          <label>School / University</label>
          <input type="text" name="edu_school[]" placeholder="e.g. Tarlac State University">
        </div>
        <div class="form-group">
          <label>Year Graduated</label>
          <input type="number" name="edu_year[]" min="1950" max="2030" placeholder="e.g. 2018">
        </div>
      </div>
    </div>`;
}

window.addEduEntry = function(containerId) {
    const container = document.getElementById(containerId);
    if (!container) return;
    const count = container.querySelectorAll('.edu-entry').length;
    const div   = document.createElement('div');
    div.innerHTML = _buildEduEntryHTML(count, count + 1);
    container.appendChild(div.firstElementChild);
    container.querySelectorAll('.edu-remove-btn').forEach(b => b.style.display = 'inline-flex');
};

window.removeEduEntry = function(btn) {
    const entry = btn.closest('.edu-entry');
    if (!entry) return;
    const container = entry.closest('[id$="EduEntriesContainer"]') || document.getElementById('addEduEntriesContainer') || document.getElementById('editEduEntriesContainer');
    entry.remove();
    if (!container) return;
    const entries = container.querySelectorAll('.edu-entry');
    if (entries.length === 1) entries[0].querySelector('.edu-remove-btn').style.display = 'none';
};

// ================================
// DYNAMIC DOCUMENT ROWS
// ================================
function _buildDocRowHTML(index) {
    return `<div class="doc-upload-row" data-index="${index}">
      <div class="form-grid" style="align-items:end;">
        <div class="form-group">
          <label>Document Type</label>
          <select name="doc_type[]">
            <option value="">— Select type —</option>
            <option>Diploma / Transcript</option><option>Employment Contract</option>
            <option>SSS Card / Number</option><option>PhilHealth Card</option>
            <option>Pag-IBIG Card</option><option>TIN Card</option>
            <option>Government ID</option><option>NBI Clearance</option>
            <option>Medical Certificate</option><option>Certificate of Employment</option>
            <option>Other</option>
          </select>
        </div>
        <div class="form-group">
          <label>File</label>
          <input type="file" name="doc_file[]" accept=".pdf,.jpg,.jpeg,.png" class="doc-file-input">
          <small>PDF, JPG, PNG — max 5MB</small>
        </div>
        <div class="form-group" style="flex:0;min-width:80px;">
          <button type="button" class="edu-remove-btn" onclick="removeDocRow(this)" style="display:none;margin-top:24px;">
            <i class="fa fa-trash"></i>
          </button>
        </div>
      </div>
    </div>`;
}

window.addDocRow = function(listId) {
    const list  = document.getElementById(listId || 'addDocUploadList');
    if (!list) return;
    const count = list.querySelectorAll('.doc-upload-row').length;
    const div   = document.createElement('div');
    div.innerHTML = _buildDocRowHTML(count);
    list.appendChild(div.firstElementChild);
    list.querySelectorAll('.edu-remove-btn').forEach(b => b.style.display = 'inline-flex');
};

window.removeDocRow = function(btn) {
    const row  = btn.closest('.doc-upload-row');
    const list = row?.parentElement;
    if (row) row.remove();
    if (!list) return;
    const rows = list.querySelectorAll('.doc-upload-row');
    if (rows.length === 1) rows[0].querySelector('.edu-remove-btn').style.display = 'none';
};

// ================================
// LEAVE FILE ATTACHMENT HELPERS
// ================================
function onLeaveFileSelected(input) {
    const file = input.files[0];
    if (!file) return;
    if (file.size > 5 * 1024 * 1024) {
        if (typeof showToast === 'function') showToast('File too large — maximum 5 MB.', 'error');
        input.value = ''; return;
    }
    document.getElementById('leaveFileName').textContent    = file.name + ' (' + (file.size/1024).toFixed(0) + ' KB)';
    document.getElementById('leaveFilePreview').style.display = 'flex';
    document.getElementById('leaveFileArea').style.borderColor = '#0f766e';
}
function handleLeaveFileDrop(e) {
    e.preventDefault();
    document.getElementById('leaveFileArea').classList.remove('drag-over');
    const dt = e.dataTransfer; if (!dt?.files?.length) return;
    document.getElementById('leaveFileInput').files = dt.files;
    onLeaveFileSelected(document.getElementById('leaveFileInput'));
}
function clearLeaveFile() {
    document.getElementById('leaveFileInput').value = '';
    document.getElementById('leaveFilePreview').style.display = 'none';
    document.getElementById('leaveFileArea').style.borderColor = '';
}

// ================================
// HELPERS
// ================================
function setTxt(id, val) {
    const el = document.getElementById(id);
    if (el) el.textContent = val ?? '—';
}
function ucFirst(str) {
    return str ? str.charAt(0).toUpperCase() + str.slice(1) : '';
}
function formatDate(dateStr) {
    if (!dateStr) return '—';
    const d = new Date(dateStr + 'T00:00:00');
    return d.toLocaleDateString('en-US', { month: 'short', day: 'numeric', year: 'numeric' });
}
function fmtTime(timeStr) {
    if (!timeStr) return '';
    const [hStr, mStr] = timeStr.split(':');
    let h = parseInt(hStr, 10), m = parseInt(mStr, 10);
    const ampm = h >= 12 ? 'PM' : 'AM';
    h = h % 12 || 12;
    return h + (m ? ':' + String(m).padStart(2, '0') : '') + ' ' + ampm;
}
function fmtShift(start, end) {
    if (!start && !end) return '—';
    if (!start || !end) return fmtTime(start || end);
    return fmtTime(start) + ' – ' + fmtTime(end);
}
function fmtNum(n) {
    return parseFloat(n).toLocaleString('en-PH', { minimumFractionDigits: 2 });
}
function escHtml(str) {
    return String(str || '').replace(/&/g,'&amp;').replace(/</g,'&lt;').replace(/>/g,'&gt;').replace(/"/g,'&quot;');
}
function formatFileSize(bytes) {
    if (!bytes) return '';
    if (bytes < 1024) return bytes + ' B';
    if (bytes < 1024 * 1024) return (bytes / 1024).toFixed(0) + ' KB';
    return (bytes / (1024 * 1024)).toFixed(1) + ' MB';
}
