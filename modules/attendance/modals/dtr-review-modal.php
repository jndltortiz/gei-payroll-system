<?php
/** modal: dtr-review-modal.php - reconcile or reject one single-day DTR upload */

$dtrEmployeeOptions = array_map(static function ($emp): array {
    $label = trim(($emp['employee_no'] ?? '') . ' ' . ($emp['first_name'] ?? '') . ' ' . ($emp['last_name'] ?? ''));
    if (!empty($emp['department_name'])) {
        $label .= ' - ' . $emp['department_name'];
    }

    return [
        'id' => (int)$emp['employee_id'],
        'label' => $label,
    ];
}, $dtrReviewEmployees ?? []);
?>

<style>
.dtr-review-fullscreen {
    width: 94vw;
    height: 92vh;
    max-width: none;
    display: flex;
    flex-direction: column;
    overflow: hidden;
}
.dtr-review-header {
    flex: 0 0 auto;
}
.dtr-review-meta {
    display: grid;
    grid-template-columns: 1.4fr 180px 1fr 160px;
    gap: 10px;
    padding: 12px 16px;
    border-bottom: 1px solid #d1fae5;
    background: #f8fafc;
}
.dtr-review-meta-item label {
    display: block;
    font-size: 10px;
    font-weight: 800;
    color: #64748b;
    text-transform: uppercase;
    margin-bottom: 3px;
}
.dtr-review-meta-item div {
    min-height: 22px;
    font-size: 13px;
    font-weight: 700;
    color: #334155;
    overflow: hidden;
    text-overflow: ellipsis;
    white-space: nowrap;
}
.dtr-review-body {
    flex: 1 1 auto;
    min-height: 0;
    display: flex;
    flex-direction: column;
    gap: 10px;
    padding: 12px 16px;
    overflow: hidden;
}
.dtr-review-table-wrap {
    flex: 1 1 auto;
    min-height: 220px;
    border: 1px solid #d1fae5;
    border-radius: 8px;
    overflow: auto;
    background: #fff;
}
.dtr-review-grid {
    display: grid;
    grid-template-columns: 130px minmax(250px,1.5fr) 105px 105px 135px minmax(270px,1.2fr) minmax(190px,.8fr) 46px;
    gap: 8px;
    align-items: center;
    min-width: 1220px;
}
.dtr-review-table-head {
    position: sticky;
    top: 0;
    z-index: 1;
    padding: 8px 10px;
    background: #ecfdf5;
    border-bottom: 1px solid #bbf7d0;
    font-size: 10px;
    font-weight: 800;
    color: #115e59;
    text-transform: uppercase;
}
.dtr-review-entry-row {
    padding: 8px 10px;
    border-bottom: 1px solid #e5e7eb;
    background: #fff;
}
.dtr-review-entry-row:nth-child(even) {
    background: #f8fafc;
}
.dtr-review-entry-row select,
.dtr-review-entry-row input {
    width: 100%;
    height: 34px;
    box-sizing: border-box;
    border: 1px solid #d1d5db;
    border-radius: 7px;
    padding: 6px 8px;
    font-size: 12px;
    background: #fff;
}
.dtr-review-table-actions {
    flex: 0 0 auto;
    display: flex;
    align-items: center;
    justify-content: space-between;
    gap: 10px;
}
.dtr-review-footer {
    flex: 0 0 auto;
    justify-content: space-between;
    gap: 12px;
    flex-wrap: wrap;
    border-top: 1px solid #e5e7eb;
}
.dtr-review-reject {
    display: flex;
    align-items: center;
    gap: 8px;
    min-width: min(100%, 520px);
}
.dtr-review-reject input {
    min-width: 260px;
    flex: 1;
    height: 34px;
    border: 1px solid #fed7aa;
    border-radius: 7px;
    padding: 6px 9px;
    font-size: 12px;
}
@media (max-width: 900px) {
    .dtr-review-fullscreen {
        width: 98vw;
        height: 96vh;
    }
    .dtr-review-meta {
        grid-template-columns: 1fr 1fr;
    }
}
</style>

<div id="dtrReviewModal" class="att-modal" style="display:none;">
  <div class="att-modal-box dtr-review-fullscreen">
    <div class="att-modal-header dtr-review-header" style="background:#0f766e;">
      <div style="min-width:0;">
        <h3><i class="fa fa-clipboard-check"></i> Review DTR Upload</h3>
        <p id="dtrReviewSubtitle" style="font-size:12px;opacity:0.85;margin-top:2px;"></p>
      </div>
      <button type="button" class="att-modal-close" onclick="closeDtrReviewModal()">
        <i class="fa fa-times"></i>
      </button>
    </div>

    <form id="dtrReviewForm" class="dtr-review-body" onsubmit="return false;">
      <?= csrfInput() ?>
      <input type="hidden" name="attachment_id" id="dtrReviewId">

      <div id="dtrReviewError" style="display:none;padding:10px 14px;
           background:#fef2f2;border:1px solid #fecaca;border-radius:8px;color:#991b1b;font-size:13px;"></div>

      <div class="dtr-review-meta">
        <div class="dtr-review-meta-item">
          <label>File</label>
          <div id="dtrReviewFileLabel"></div>
        </div>
        <div class="dtr-review-meta-item">
          <label>Fixed Date</label>
          <div id="dtrReviewDateLabel"></div>
        </div>
        <div class="dtr-review-meta-item">
          <label>Department Scope</label>
          <div id="dtrReviewDepartmentLabel"></div>
        </div>
        <div class="dtr-review-meta-item">
          <label>Entries</label>
          <div id="dtrReviewEntryCount">0 employee entries</div>
        </div>
      </div>

      <div class="dtr-review-table-wrap">
        <div class="dtr-review-grid dtr-review-table-head">
          <div>Action</div>
          <div>Employee</div>
          <div>Time In</div>
          <div>Time Out</div>
          <div>Status</div>
          <div>Reason</div>
          <div>Remarks</div>
          <div></div>
        </div>
        <div id="dtrReviewRows"></div>
      </div>

      <div class="dtr-review-table-actions">
        <span style="font-size:12px;color:#64748b;">Add one row for each employee in the uploaded DTR file.</span>
        <button type="button" class="att-btn outline" onclick="addDtrReviewEntryRow()"
                style="font-size:12px;padding:6px 12px;color:#0f766e;border-color:#5eead4;">
          <i class="fa fa-plus"></i> Add Employee Entry
        </button>
      </div>
    </form>

    <div class="att-modal-footer dtr-review-footer">
      <div class="dtr-review-reject">
        <input type="text" id="dtrReviewRejectReason" minlength="10" placeholder="Rejection reason, required only when rejecting">
        <button type="button" class="att-btn outline" onclick="submitDtrReject()"
                style="color:#dc2626;border-color:#fca5a5;">
          <i class="fa fa-ban"></i> Reject Upload
        </button>
      </div>
      <div style="display:flex;gap:8px;flex-wrap:wrap;">
        <button type="button" class="att-btn outline" onclick="closeDtrReviewModal()">Cancel</button>
        <button type="button" class="att-btn primary" id="dtrReviewSubmitBtn" onclick="submitDtrReconciliation()">
          <i class="fa fa-check"></i> Mark as Reconciled
        </button>
      </div>
    </div>
  </div>
</div>

<script>
const DTR_REVIEW_EMPLOYEES = <?= json_encode($dtrEmployeeOptions, JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_HEX_AMP) ?>;
let _dtrReviewPayload = null;
let _dtrReviewRowSeq = 0;

function setDtrReviewButtonsDisabled(disabled) {
    document.querySelectorAll('#dtrReviewModal button').forEach(btn => btn.disabled = disabled);
}

function showDtrReviewError(message) {
    const errBox = document.getElementById('dtrReviewError');
    errBox.textContent = message;
    errBox.style.display = 'block';
}

function clearDtrReviewError() {
    const errBox = document.getElementById('dtrReviewError');
    errBox.textContent = '';
    errBox.style.display = 'none';
}

function formatDtrReviewDate(value) {
    if (!value) return '';
    const parts = value.split('-');
    if (parts.length !== 3) return value;
    return `${parts[1]}/${parts[2]}/${parts[0]}`;
}

function updateDtrReviewEntryCount() {
    const count = document.querySelectorAll('#dtrReviewRows .dtr-review-entry-row').length;
    document.getElementById('dtrReviewEntryCount').textContent =
        `${count} employee ${count === 1 ? 'entry' : 'entries'}`;
}

function employeeOptionsHtml(selectedId) {
    const options = ['<option value="">Select employee</option>'];
    DTR_REVIEW_EMPLOYEES.forEach(emp => {
        const selected = String(emp.id) === String(selectedId || '') ? ' selected' : '';
        options.push(`<option value="${emp.id}"${selected}>${escapeHtml(emp.label)}</option>`);
    });
    return options.join('');
}

function escapeHtml(value) {
    return String(value ?? '')
        .replace(/&/g, '&amp;')
        .replace(/</g, '&lt;')
        .replace(/>/g, '&gt;')
        .replace(/"/g, '&quot;')
        .replace(/'/g, '&#039;');
}

window.addDtrReviewEntryRow = function(defaultEmployeeId = '') {
    _dtrReviewRowSeq++;
    const row = document.createElement('div');
    row.className = 'dtr-review-entry-row';
    row.dataset.rowId = String(_dtrReviewRowSeq);
    row.innerHTML = `
      <div class="dtr-review-grid">
        <select class="dtr-entry-action" aria-label="Action">
          <option value="create">Create New</option>
          <option value="update">Correct Existing</option>
        </select>
        <select class="dtr-entry-employee" aria-label="Employee">${employeeOptionsHtml(defaultEmployeeId)}</select>
        <input type="time" class="dtr-entry-time-in" aria-label="Time In">
        <input type="time" class="dtr-entry-time-out" aria-label="Time Out">
        <select class="dtr-entry-status" aria-label="Status">
          <option value="PRESENT">Present</option>
          <option value="LATE">Late</option>
          <option value="HALF_DAY">Half Day</option>
          <option value="INCOMPLETE">Incomplete</option>
          <option value="ABSENT">Absent</option>
          <option value="LEAVE">On Leave</option>
          <option value="HOLIDAY">Holiday</option>
        </select>
        <div>
          <select class="dtr-entry-reason-preset" aria-label="Reason" onchange="toggleDtrReasonOther(this)">
            <option value="">Select reason</option>
            <option value="System outage">System outage</option>
            <option value="Power outage">Power outage</option>
            <option value="Employee forgot to log in">Employee forgot to log in</option>
            <option value="Biometric/device malfunction">Biometric/device malfunction</option>
            <option value="Internet connectivity issue">Internet connectivity issue</option>
            <option value="__other">Other (specify)</option>
          </select>
          <input type="text" class="dtr-entry-reason-other" minlength="10" placeholder="Specify reason" aria-label="Custom reason" style="display:none;margin-top:6px;">
        </div>
        <input type="text" class="dtr-entry-remarks" placeholder="Optional remarks" aria-label="Remarks">
        <button type="button" class="att-action-btn delete" title="Remove row" onclick="removeDtrReviewEntryRow(this)"
                style="width:34px;height:34px;padding:0;display:inline-flex;align-items:center;justify-content:center;">
          <i class="fa fa-trash"></i>
        </button>
      </div>
    `;
    document.getElementById('dtrReviewRows').appendChild(row);
    updateDtrReviewEntryCount();
};

window.toggleDtrReasonOther = function(select) {
    const wrap = select.closest('div');
    const input = wrap ? wrap.querySelector('.dtr-entry-reason-other') : null;
    if (!input) return;
    const isOther = select.value === '__other';
    input.style.display = isOther ? 'block' : 'none';
    input.required = isOther;
    if (!isOther) input.value = '';
};

window.removeDtrReviewEntryRow = function(button) {
    const row = button.closest('.dtr-review-entry-row');
    if (row) row.remove();
    updateDtrReviewEntryCount();
};

window.openDtrReviewModal = function(payload) {
    _dtrReviewPayload = payload || {};
    _dtrReviewRowSeq = 0;
    clearDtrReviewError();
    setDtrReviewButtonsDisabled(false);

    document.getElementById('dtrReviewId').value = _dtrReviewPayload.id || '';
    document.getElementById('dtrReviewSubtitle').textContent =
        `${_dtrReviewPayload.file || 'DTR upload'} - ${_dtrReviewPayload.date_from || ''}`;
    document.getElementById('dtrReviewFileLabel').textContent = _dtrReviewPayload.file || 'DTR upload';
    document.getElementById('dtrReviewDateLabel').textContent = formatDtrReviewDate(_dtrReviewPayload.date_from || '');
    document.getElementById('dtrReviewDepartmentLabel').textContent = _dtrReviewPayload.department || 'All departments';
    document.getElementById('dtrReviewRejectReason').value = '';
    document.getElementById('dtrReviewRows').innerHTML = '';

    if (_dtrReviewPayload.date_from && _dtrReviewPayload.date_to && _dtrReviewPayload.date_from !== _dtrReviewPayload.date_to) {
        showDtrReviewError('This upload covers multiple dates. Please split or re-upload one file per unavailable day before reconciliation.');
    } else {
        addDtrReviewEntryRow(_dtrReviewPayload.employee_id || '');
    }

    updateDtrReviewEntryCount();
    document.getElementById('dtrReviewModal').style.display = 'flex';
};

window.closeDtrReviewModal = function() {
    document.getElementById('dtrReviewModal').style.display = 'none';
    _dtrReviewPayload = null;
    document.getElementById('dtrReviewRows').innerHTML = '';
    clearDtrReviewError();
    setDtrReviewButtonsDisabled(false);
};

function collectDtrReviewEntries() {
    const rows = Array.from(document.querySelectorAll('#dtrReviewRows .dtr-review-entry-row'));
    const seenEmployees = new Set();
    const entries = [];

    if (rows.length === 0) {
        throw new Error('Add at least one employee entry before marking this upload reconciled.');
    }

    rows.forEach((row, index) => {
        const rowNumber = index + 1;
        const employeeId = row.querySelector('.dtr-entry-employee').value;
        const reasonPreset = row.querySelector('.dtr-entry-reason-preset').value;
        const reasonOther = row.querySelector('.dtr-entry-reason-other').value.trim();
        const reason = reasonPreset === '__other' ? reasonOther : reasonPreset;

        if (!employeeId) {
            throw new Error(`Entry ${rowNumber}: employee is required.`);
        }
        if (seenEmployees.has(employeeId)) {
            throw new Error(`Entry ${rowNumber}: duplicate employee selected for this DTR date.`);
        }
        const reasonError = window.GEI && GEI.validateReason
            ? GEI.validateReason(reason, `Entry ${rowNumber} reason`)
            : (!reason ? `Entry ${rowNumber}: reason is required.` : '');
        if (reasonError) {
            throw new Error(reasonError);
        }

        seenEmployees.add(employeeId);
        entries.push({
            entry_action: row.querySelector('.dtr-entry-action').value,
            employee_id: employeeId,
            time_in: row.querySelector('.dtr-entry-time-in').value,
            time_out: row.querySelector('.dtr-entry-time-out').value,
            attendance_status: row.querySelector('.dtr-entry-status').value,
            reason: reason,
            remarks: row.querySelector('.dtr-entry-remarks').value.trim()
        });
    });

    return entries;
}

window.submitDtrReconciliation = function() {
    clearDtrReviewError();
    if (!_dtrReviewPayload || (_dtrReviewPayload.date_from && _dtrReviewPayload.date_to && _dtrReviewPayload.date_from !== _dtrReviewPayload.date_to)) {
        showDtrReviewError('This upload must represent exactly one attendance date before it can be reconciled.');
        return;
    }

    let entries;
    try {
        entries = collectDtrReviewEntries();
    } catch (err) {
        showDtrReviewError(err.message || 'Please check the employee entries.');
        return;
    }

    const form = document.getElementById('dtrReviewForm');
    const fd = new FormData(form);
    fd.append('action', 'reconcile_batch');
    fd.append('entries_json', JSON.stringify(entries));

    setDtrReviewButtonsDisabled(true);

    fetch('<?= BASE_URL ?>actions/dtr-reconcile.php', { method: 'POST', body: fd })
        .then(r => r.json())
        .then(data => {
            if (data.success) {
                showToast(data.message || 'DTR upload reconciled.', 'success', 5000);
                setTimeout(() => location.reload(), 900);
                return;
            }
            showDtrReviewError(data.message || 'DTR reconciliation failed.');
            setDtrReviewButtonsDisabled(false);
        })
        .catch(() => {
            showDtrReviewError('Network error. Please try again.');
            setDtrReviewButtonsDisabled(false);
        });
};

window.submitDtrReject = function() {
    clearDtrReviewError();
    const reason = document.getElementById('dtrReviewRejectReason').value.trim();
    const reasonError = window.GEI && GEI.validateReason
        ? GEI.validateReason(reason, 'Rejection reason')
        : (!reason ? 'Rejection reason is required.' : '');
    if (reasonError) {
        showDtrReviewError(reasonError);
        return;
    }

    const form = document.getElementById('dtrReviewForm');
    const fd = new FormData(form);
    fd.append('action', 'reject');
    fd.append('reason', reason);

    setDtrReviewButtonsDisabled(true);

    fetch('<?= BASE_URL ?>actions/dtr-reconcile.php', { method: 'POST', body: fd })
        .then(r => r.json())
        .then(data => {
            if (data.success) {
                showToast(data.message || 'DTR upload rejected.', 'success', 5000);
                setTimeout(() => location.reload(), 900);
                return;
            }
            showDtrReviewError(data.message || 'DTR rejection failed.');
            setDtrReviewButtonsDisabled(false);
        })
        .catch(() => {
            showDtrReviewError('Network error. Please try again.');
            setDtrReviewButtonsDisabled(false);
        });
};
</script>
