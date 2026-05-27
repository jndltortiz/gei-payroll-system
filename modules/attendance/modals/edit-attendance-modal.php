<?php /** modal: edit-attendance-modal.php — full attendance edit with audit trail */ ?>

<div id="editAttendanceModal" class="att-modal" style="display:none;">
  <div class="att-modal-box">

    <div class="att-modal-header" style="background:#1d4ed8;">
      <div>
        <h3><i class="fa fa-pen-to-square"></i> Edit Attendance</h3>
        <p id="editModalSubtitle" style="font-size:13px;opacity:0.85;margin-top:2px;"></p>
      </div>
      <button type="button" class="att-modal-close" onclick="closeEditModal()">
        <i class="fa fa-times"></i>
      </button>
    </div>

    <div id="editAttError" style="display:none;margin:12px 16px 0;padding:10px 14px;
         background:#fef2f2;border:1px solid #fecaca;border-radius:8px;color:#991b1b;font-size:13px;"></div>

    <form id="editAttForm" onsubmit="submitEditAttendance(event)">
      <div class="att-modal-body">

        <input type="hidden" name="attendance_id" id="edit_id">

        <!-- Current record snapshot (read-only context) -->
        <div id="editOldValues" class="att-edit-old-values">
          <span class="att-edit-old-label">Current Record</span>
          <div class="att-edit-old-grid">
            <span>Time In: <strong id="editOldTimeIn">—</strong></span>
            <span>Time Out: <strong id="editOldTimeOut">—</strong></span>
            <span>Status: <strong id="editOldStatus">—</strong></span>
            <span>Method: <strong id="editOldMethod">—</strong></span>
          </div>
        </div>

        <!-- Employee (read-only) -->
        <div class="att-field">
          <label>Employee</label>
          <input type="text" id="edit_employee" disabled
                 style="background:#f9fafb;color:#374151;font-weight:600;">
        </div>

        <!-- Date -->
        <div class="att-field">
          <label>Date <span class="req">*</span></label>
          <input type="date" name="date" id="edit_date" required>
        </div>

        <!-- Time In / Out -->
        <div class="att-field-row">
          <div class="att-field">
            <label>Time In</label>
            <input type="time" name="time_in" id="edit_time_in">
          </div>
          <div class="att-field">
            <label>Time Out</label>
            <input type="time" name="time_out" id="edit_time_out">
          </div>
        </div>

        <!-- Status + Method -->
        <div class="att-field-row">
          <div class="att-field">
            <label>Status <span class="req">*</span></label>
            <select name="status" id="edit_status" required>
              <option value="PRESENT">Present</option>
              <option value="LATE">Late</option>
              <option value="HALF_DAY">Half Day</option>
              <option value="ABSENT">Absent</option>
              <option value="LEAVE">On Leave</option>
              <option value="INCOMPLETE">Incomplete</option>
              <option value="HOLIDAY">Holiday</option>
            </select>
          </div>
          <div class="att-field">
            <label>Method / Source</label>
            <select name="source" id="edit_source">
              <option value="MANUAL_ADMIN">Manual</option>
              <option value="RFID">RFID</option>
              <option value="FACIAL_RECOGNITION">Facial Recognition</option>
              <option value="AUTO">Auto-tagged</option>
            </select>
          </div>
        </div>

        <!-- Notes -->
        <div class="att-field">
          <label>Notes</label>
          <textarea name="remarks" id="edit_remarks" rows="2"
                    placeholder="Additional notes or comments…"></textarea>
        </div>

        <!-- Reason for modification (required for audit) -->
        <div class="att-field">
          <label>Reason for Modification <span class="req">*</span></label>
          <textarea name="reason" id="edit_reason" rows="2" required
                    placeholder="Required: explain why this record is being corrected (e.g. wrong input, missed time-in, verification adjustment)…"
                    style="border-color:#f97316;"></textarea>
          <small style="font-size:11px;color:#b45309;">
            <i class="fa fa-triangle-exclamation"></i> This reason is stored in the audit log and cannot be removed.
          </small>
        </div>

      </div><!-- /modal-body -->

      <div class="att-modal-footer">
        <button type="button" class="att-btn outline" onclick="closeEditModal()">Cancel</button>
        <button type="submit" class="att-btn primary" id="editAttSubmitBtn">
          <i class="fa fa-floppy-disk"></i> Update Attendance
        </button>
      </div>
    </form>
  </div>
</div>

<script>
// openEditModal called from index.php table rows
window.openEditModal = function(id, name, empNo, date, timeIn, timeOut, status, remarks, source) {
    document.getElementById('edit_id').value       = id;
    document.getElementById('edit_employee').value = empNo ? `${name}  (${empNo})` : name;
    document.getElementById('edit_date').value     = date  || '';
    document.getElementById('edit_time_in').value  = timeIn  ? timeIn.substring(0, 5)  : '';
    document.getElementById('edit_time_out').value = timeOut ? timeOut.substring(0, 5) : '';
    document.getElementById('edit_status').value   = status || 'PRESENT';
    document.getElementById('edit_source').value   = 'MANUAL_ADMIN';
    document.getElementById('edit_remarks').value  = remarks || '';
    document.getElementById('edit_reason').value   = '';

    // Fill old-values snapshot
    document.getElementById('editOldTimeIn').textContent  = timeIn  ? fmt12h(timeIn)  : '—';
    document.getElementById('editOldTimeOut').textContent = timeOut ? fmt12h(timeOut) : '—';
    document.getElementById('editOldStatus').textContent  = {
        PRESENT:'Present', LATE:'Late', HALF_DAY:'Half Day',
        ABSENT:'Absent', LEAVE:'On Leave', INCOMPLETE:'Incomplete', HOLIDAY:'Holiday',
    }[status] || status || '—';
    document.getElementById('editOldMethod').textContent  = {
        MANUAL_ADMIN:'Manual', MANUAL:'Manual',
        RFID:'RFID', FACIAL_RECOGNITION:'Face ID', AUTO:'Auto',
    }[source] || source || 'Manual';

    document.getElementById('editModalSubtitle').textContent =
        `Editing record for ${name}${empNo ? ' (' + empNo + ')' : ''} · ${date}`;

    document.getElementById('editAttError').style.display = 'none';

    const modal = document.getElementById('editAttendanceModal');
    modal.style.display = 'flex';
};

window.closeEditModal = function() {
    document.getElementById('editAttendanceModal').style.display = 'none';
    document.getElementById('editAttForm').reset();
    document.getElementById('editAttError').style.display = 'none';
};

function fmt12h(t) {
    if (!t) return '—';
    const parts = t.split(':');
    let h = parseInt(parts[0], 10);
    const m = parts[1] || '00';
    const ampm = h >= 12 ? 'PM' : 'AM';
    h = h > 12 ? h - 12 : (h === 0 ? 12 : h);
    return `${h}:${m} ${ampm}`;
}

function submitEditAttendance(e) {
    e.preventDefault();

    const form   = document.getElementById('editAttForm');
    const btn    = document.getElementById('editAttSubmitBtn');
    const errBox = document.getElementById('editAttError');

    errBox.style.display = 'none';
    btn.disabled         = true;
    btn.innerHTML        = '<i class="fa fa-spinner fa-spin"></i> Saving…';

    fetch('<?= BASE_URL ?>actions/update-attendance.php', {
        method: 'POST',
        body:   new FormData(form),
    })
    .then(r => {
        const ct = r.headers.get('content-type') || '';
        if (!ct.includes('application/json')) {
            return r.text().then(t => { throw new Error(t.substring(0, 200)); });
        }
        return r.json();
    })
    .then(data => {
        if (data.success) {
            closeEditModal();
            location.reload();
        } else {
            errBox.textContent   = data.message || 'Could not update attendance.';
            errBox.style.display = 'block';
            btn.disabled         = false;
            btn.innerHTML        = '<i class="fa fa-floppy-disk"></i> Update Attendance';
        }
    })
    .catch(err => {
        errBox.textContent   = err.message || 'A network error occurred.';
        errBox.style.display = 'block';
        btn.disabled         = false;
        btn.innerHTML        = '<i class="fa fa-floppy-disk"></i> Update Attendance';
    });
}
</script>
