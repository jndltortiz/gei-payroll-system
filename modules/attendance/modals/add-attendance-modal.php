<div id="addAttendanceModal" class="att-modal">
  <div class="att-modal-box">

    <div class="att-modal-header">
      <div>
        <h3>Add Attendance Manually</h3>
      </div>
      <button type="button" class="att-modal-close" onclick="closeAttModal()">
        <i class="fa fa-times"></i>
      </button>
    </div>

    <form method="POST" action="../../actions/add-attendance.php" id="addAttForm">
      <div class="att-modal-body">

        <div class="att-notice-box">
          <strong>Manual Attendance Entry</strong>
          <p>Fill in the employee details below to record attendance manually</p>
        </div>

        <!-- EMPLOYEE SEARCH (replaces plain dropdown) -->
        <div class="att-field">
          <label>Employee Name <span class="req">*</span></label>
          <div class="att-emp-search-wrap">
            <i class="fa fa-magnifying-glass"></i>
            <input type="text" id="empSearchInput" placeholder="Type to search employee..."
                   autocomplete="off" oninput="searchEmployees(this.value)">
            <input type="hidden" name="employee_id" id="selectedEmployeeId" required>
          </div>
          <div id="empDropdown" class="att-emp-dropdown hidden"></div>
          <div id="selectedEmpBadge" class="att-selected-emp hidden">
            <span id="selectedEmpName"></span>
            <button type="button" onclick="clearEmployeeSelection()"><i class="fa fa-times"></i></button>
          </div>
        </div>

        <!-- DATE -->
        <div class="att-field">
          <label>Date <span class="req">*</span></label>
          <input type="date" name="date" value="<?= date('Y-m-d') ?>" required>
        </div>

        <!-- TIME IN / OUT -->
        <div class="att-field-row">
          <div class="att-field">
            <label>Time In</label>
            <input type="time" name="time_in">
          </div>
          <div class="att-field">
            <label>Time Out</label>
            <input type="time" name="time_out">
          </div>
        </div>

        <!-- STATUS -->
        <div class="att-field">
          <label>Status <span class="req">*</span></label>
          <select name="status" required>
            <option value="PRESENT">Present</option>
            <option value="LATE">Late</option>
            <option value="ABSENT">Absent</option>
            <option value="INC">On Leave</option>
          </select>
        </div>

        <!-- METHOD (readonly, always Manual) -->
        <div class="att-field">
          <label>Method</label>
          <input type="text" value="Manual" disabled style="background:#f9fafb;color:#6b7280;">
        </div>

        <!-- NOTES -->
        <div class="att-field">
          <label>Notes (Optional)</label>
          <textarea name="remarks" placeholder="Add any additional notes or comments..." rows="3"></textarea>
        </div>

      </div>

      <div class="att-modal-footer">
        <button type="button" class="att-btn outline" onclick="closeAttModal()">Cancel</button>
        <button type="submit" class="att-btn primary">
          <i class="fa fa-floppy-disk"></i> Save Attendance
        </button>
      </div>
    </form>
  </div>
</div>