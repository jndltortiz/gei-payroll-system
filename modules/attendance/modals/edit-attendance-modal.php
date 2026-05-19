<div id="editAttendanceModal" class="att-modal">
  <div class="att-modal-box">

    <div class="att-modal-header">
      <div>
        <h3>Edit Attendance</h3>
        <p id="editModalSubtitle" style="font-size:13px;opacity:0.85;margin-top:2px;"></p>
      </div>
      <button type="button" class="att-modal-close" onclick="closeEditModal()">
        <i class="fa fa-times"></i>
      </button>
    </div>

    <!-- Error message inside modal -->
    <div id="editAttError" style="display:none;margin:12px 16px 0;padding:10px 14px;
         background:#fef2f2;border:1px solid #fecaca;border-radius:8px;
         color:#991b1b;font-size:13px;"></div>

    <!-- No method/action — JS handles submission entirely -->
    <form id="editAttForm" onsubmit="submitEditAttendance(event)">
      <div class="att-modal-body">

        <input type="hidden" name="attendance_id" id="edit_id">

        <!-- Employee (readonly) -->
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

        <!-- Status -->
        <div class="att-field">
          <label>Status <span class="req">*</span></label>
          <select name="status" id="edit_status" required>
            <option value="PRESENT">Present</option>
            <option value="LATE">Late</option>
            <option value="ABSENT">Absent</option>
            <option value="LEAVE">On Leave</option>
            <option value="HALF_DAY">Half Day</option>
            <option value="INCOMPLETE">Incomplete</option>
            <option value="HOLIDAY">Holiday</option>
          </select>
        </div>

        <!-- Notes -->
        <div class="att-field">
          <label>Notes (Optional)</label>
          <textarea name="remarks" id="edit_remarks" rows="3"
                    placeholder="Add any additional notes or comments..."></textarea>
        </div>

      </div>

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
function submitEditAttendance(e) {
    e.preventDefault();   // always stop the browser from navigating away

    const form   = document.getElementById('editAttForm');
    const btn    = document.getElementById('editAttSubmitBtn');
    const errBox = document.getElementById('editAttError');

    // Reset error state
    errBox.style.display = 'none';
    errBox.textContent   = '';
    btn.disabled         = true;
    btn.innerHTML        = '<i class="fa fa-spinner fa-spin"></i> Saving…';

    const data = new FormData(form);

    fetch('<?= BASE_URL ?>actions/update-attendance.php', {
        method: 'POST',
        body:   data
    })
    .then(function(res) {
        // Guard: if the response isn't JSON, show a helpful error
        const ct = res.headers.get('content-type') || '';
        if (!ct.includes('application/json')) {
            return res.text().then(function(txt) {
                throw new Error('Server returned unexpected response. Check PHP logs.\n\n' + txt.substring(0, 200));
            });
        }
        return res.json();
    })
    .then(function(data) {
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
    .catch(function(err) {
        errBox.textContent   = err.message || 'A network error occurred. Please try again.';
        errBox.style.display = 'block';
        btn.disabled         = false;
        btn.innerHTML        = '<i class="fa fa-floppy-disk"></i> Update Attendance';
    });
}
</script>