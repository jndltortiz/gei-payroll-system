<?php /** modal: add-attendance-modal.php — included by attendance/index.php */ ?>

<div id="addAttendanceModal" class="att-modal" style="display:none;">
  <div class="att-modal-box">

    <div class="att-modal-header">
      <div>
        <h3>Add Attendance Manually</h3>
        <p id="addModalSubtitle" style="font-size:13px;opacity:0.8;margin-top:2px;">
          Fill in the employee details below to record attendance manually
        </p>
      </div>
      <button type="button" class="att-modal-close" onclick="closeAddModal()">
        <i class="fa fa-times"></i>
      </button>
    </div>

    <div id="addAttError" style="display:none;margin:12px 16px 0;padding:10px 14px;
         background:#fef2f2;border:1px solid #fecaca;border-radius:8px;color:#991b1b;font-size:13px;"></div>

    <form id="addAttForm" onsubmit="submitAddAttendance(event)">
      <div class="att-modal-body">

        <!-- STEP 1: Employee search -->
        <div class="att-field">
          <label>Employee Name <span class="req">*</span></label>
          <select name="employee_id" id="add_employee_id" required
                  onchange="onAddAttendanceEmployeeChange(this)">
            <option value="">— Select employee —</option>
            <?php foreach ($pdo->query("
                SELECT e.employee_id, CONCAT(e.first_name,' ',e.last_name) AS full_name,
                       p.position_name
                FROM employees e
                LEFT JOIN positions p ON e.position_id=p.position_id
                WHERE e.employee_status='ACTIVE'
                ORDER BY e.last_name, e.first_name
            ")->fetchAll() as $emp): ?>
            <option value="<?= $emp['employee_id'] ?>"
                    data-name="<?= htmlspecialchars($emp['full_name']) ?>">
              <?= htmlspecialchars($emp['full_name']) ?>
              <?php if ($emp['position_name']): ?>(<?= htmlspecialchars($emp['position_name']) ?>)<?php endif; ?>
            </option>
            <?php endforeach; ?>
          </select>
        </div>

        <!-- Shift info hint (shown after employee selected) -->
        <div id="add-shift-hint" style="display:none;padding:8px 12px;background:#f0fdf9;
             border:1px solid #a7f3d0;border-radius:8px;font-size:12px;color:#065f46;margin-bottom:12px;">
          <i class="fa fa-clock"></i>
          <span id="add-shift-hint-text"></span>
        </div>

        <!-- Date -->
        <div class="att-field">
          <label>Date <span class="req">*</span></label>
          <input type="date" name="date" id="add_date"
                 value="<?= date('Y-m-d') ?>" required>
        </div>

        <!-- Time In / Out -->
        <div class="att-field-row">
          <div class="att-field">
            <label>Time In</label>
            <input type="time" name="time_in" id="add_time_in"
                   oninput="onAddTimeInChange(this.value)">
          </div>
          <div class="att-field">
            <label>Time Out</label>
            <input type="time" name="time_out" id="add_time_out">
          </div>
        </div>

        <!-- Status — auto-suggested from shift -->
        <div class="att-field">
          <label>Status <span class="req">*</span></label>
          <div style="position:relative;">
            <select name="status" id="add_status" required>
              <option value="PRESENT">Present</option>
              <option value="LATE">Late</option>
              <option value="ABSENT">Absent</option>
              <option value="LEAVE">On Leave</option>
              <option value="HALF_DAY">Half Day</option>
              <option value="INCOMPLETE">Incomplete</option>
              <option value="HOLIDAY">Holiday</option>
            </select>
            <span id="add-status-auto-badge" style="display:none;position:absolute;right:36px;top:50%;
                  transform:translateY(-50%);font-size:10px;background:#d1fae5;color:#065f46;
                  padding:2px 8px;border-radius:999px;font-weight:600;pointer-events:none;">
              Auto
            </span>
          </div>
          <small id="add-status-hint" style="font-size:11px;color:#9ca3af;display:none;margin-top:3px;"></small>
        </div>

        <!-- Notes -->
        <div class="att-field">
          <label>Notes (Optional)</label>
          <textarea name="remarks" id="add_remarks" rows="2"
                    placeholder="Add any additional notes or comments..."></textarea>
        </div>

      </div>

      <div class="att-modal-footer">
        <button type="button" class="att-btn outline" onclick="closeAddModal()">Cancel</button>
        <button type="submit" class="att-btn primary" id="addAttSubmitBtn">
          <i class="fa fa-floppy-disk"></i> Save Attendance
        </button>
      </div>
    </form>
  </div>
</div>

<script>
// ── Employee shift data cache ─────────────────────────────────────────────────
let _currentShift = null;

async function onAddAttendanceEmployeeChange(sel) {
    const empId   = sel.value;
    const hint    = document.getElementById('add-shift-hint');
    const hintTxt = document.getElementById('add-shift-hint-text');
    _currentShift = null;

    if (!empId) { hint.style.display = 'none'; return; }

    try {
        const res  = await fetch(`<?= BASE_URL ?>actions/get-employee-shift.php?employee_id=${empId}`);
        const data = await res.json();

        if (!data.success || !data.start_time) {
            hint.style.display = 'none'; return;
        }
        _currentShift = data;

        const start      = formatTime12h(data.start_time);
        const end        = data.end_time ? formatTime12h(data.end_time) : '—';
        const empType    = data.employment_type === 'PART_TIME' ? ' · Part-Time' : ' · Full-Time';
        const graceNote  = (data.employment_type !== 'PART_TIME' && data.grace_period_minutes > 0)
            ? ` · ${data.grace_period_minutes}-min grace` : '';

        hintTxt.textContent = `Shift: ${data.shift_name || 'Regular'} · ${start} – ${end}${graceNote}${empType}`;
        hint.style.display  = 'block';

        // Re-evaluate status if time_in already filled
        const timeIn = document.getElementById('add_time_in').value;
        if (timeIn) onAddTimeInChange(timeIn);

    } catch (e) {
        hint.style.display = 'none';
    }
}

/**
 * Computes the suggested attendance status based on:
 *  - The employee's employment_type (FULL_TIME / PART_TIME)
 *  - The shift's start_time and half_day_time
 *
 * FULL_TIME rules:
 *   time_in <= start_time            → PRESENT
 *   time_in  > start_time AND < 9:00 → LATE
 *   time_in >= 9:00                  → HALF_DAY
 *
 * PART_TIME rules:
 *   time_in <= start_time            → PRESENT
 *   time_in  > start_time            → LATE   (no half-day rule)
 */
function onAddTimeInChange(timeInVal) {
    if (!timeInVal || !_currentShift?.start_time) return;

    const toMins = t => { const [h, m] = t.split(':').map(Number); return h * 60 + m; };

    const shiftStartMins = toMins(_currentShift.start_time);
    const timeInMins     = toMins(timeInVal);
    const isPartTime     = _currentShift.employment_type === 'PART_TIME';

    const statusSel   = document.getElementById('add_status');
    const autoBadge   = document.getElementById('add-status-auto-badge');
    const statusHint  = document.getElementById('add-status-hint');

    let suggestedStatus, hintMsg, hintColor;

    if (isPartTime) {
        // ── PART-TIME: only PRESENT / LATE, no half-day ──────────────────────
        if (timeInMins <= shiftStartMins) {
            suggestedStatus = 'PRESENT';
            hintMsg  = `On time (shift starts ${formatTime12h(_currentShift.start_time)})`;
            hintColor = '#059669';
        } else {
            const minsLate  = timeInMins - shiftStartMins;
            suggestedStatus = 'LATE';
            hintMsg  = `${minsLate} min${minsLate > 1 ? 's' : ''} late`;
            hintColor = '#d97706';
        }
    } else {
        // ── FULL-TIME: PRESENT / LATE / HALF_DAY ─────────────────────────────
        // Half-day threshold: use shift's half_day_time if set, otherwise 9:00 AM
        const halfDayMins = _currentShift.half_day_time
            ? toMins(_currentShift.half_day_time)
            : 9 * 60; // default 9:00 AM

        if (timeInMins <= shiftStartMins) {
            suggestedStatus = 'PRESENT';
            hintMsg   = `On time (shift starts ${formatTime12h(_currentShift.start_time)})`;
            hintColor = '#059669';
        } else if (timeInMins < halfDayMins) {
            // After shift start but before half-day threshold → LATE
            const minsLate  = timeInMins - shiftStartMins;
            suggestedStatus = 'LATE';
            hintMsg   = `${minsLate} min${minsLate > 1 ? 's' : ''} late (before ${formatTime12h(_currentShift.half_day_time || '09:00')})`;
            hintColor = '#d97706';
        } else {
            // At or after half-day threshold → HALF_DAY
            suggestedStatus = 'HALF_DAY';
            hintMsg   = `At or after ${formatTime12h(_currentShift.half_day_time || '09:00')} — Half Day`;
            hintColor = '#dc2626';
        }
    }

    statusSel.value             = suggestedStatus;
    autoBadge.style.display     = 'inline-block';
    statusHint.style.display    = 'block';
    statusHint.textContent      = '⚡ Auto-suggested: ' + hintMsg;
    statusHint.style.color      = hintColor;
}

function formatTime12h(time24) {
    if (!time24) return '';
    const [h, m] = time24.split(':').map(Number);
    const ampm = h >= 12 ? 'PM' : 'AM';
    const h12  = h > 12 ? h - 12 : (h === 0 ? 12 : h);
    return `${h12}:${String(m).padStart(2, '0')} ${ampm}`;
}

// ── Form submit ───────────────────────────────────────────────────────────────
async function submitAddAttendance(e) {
    e.preventDefault();
    const form   = document.getElementById('addAttForm');
    const btn    = document.getElementById('addAttSubmitBtn');
    const errBox = document.getElementById('addAttError');

    errBox.style.display = 'none';
    btn.disabled  = true;
    btn.innerHTML = '<i class="fa fa-spinner fa-spin"></i> Saving…';

    const fd = new FormData(form);

    try {
        const res = await fetch('<?= BASE_URL ?>actions/add-attendance.php',
                                { method: 'POST', body: fd });
        const ct  = res.headers.get('content-type') || '';
        if (!ct.includes('application/json')) {
            throw new Error('Server returned unexpected response. Check PHP logs.');
        }
        const data = await res.json();

        if (data.success) {
            closeAddModal();
            location.reload();
        } else {
            errBox.textContent   = data.message || 'Could not save attendance.';
            errBox.style.display = 'block';
            btn.disabled  = false;
            btn.innerHTML = '<i class="fa fa-floppy-disk"></i> Save Attendance';
        }
    } catch (err) {
        errBox.textContent   = err.message || 'Network error. Please try again.';
        errBox.style.display = 'block';
        btn.disabled  = false;
        btn.innerHTML = '<i class="fa fa-floppy-disk"></i> Save Attendance';
    }
}

function closeAddModal() {
    document.getElementById('addAttendanceModal').style.display = 'none';
    document.getElementById('addAttForm').reset();
    document.getElementById('add-shift-hint').style.display     = 'none';
    document.getElementById('add-status-auto-badge').style.display = 'none';
    document.getElementById('add-status-hint').style.display    = 'none';
    document.getElementById('addAttError').style.display         = 'none';
    _currentShift = null;
}
</script>