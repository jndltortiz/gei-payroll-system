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

    <form method="POST" action="../../actions/update-attendance.php" id="editAttForm">
      <div class="att-modal-body">

        <input type="hidden" name="attendance_id" id="edit_id">

        <!-- Employee (readonly) -->
        <div class="att-field">
          <label>Employee</label>
          <input type="text" id="edit_employee" disabled style="background:#f9fafb;color:#374151;font-weight:600;">
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
            <option value="INC">On Leave</option>
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
        <button type="submit" class="att-btn primary">
          <i class="fa fa-floppy-disk"></i> Update Attendance
        </button>
      </div>
    </form>
  </div>
</div>