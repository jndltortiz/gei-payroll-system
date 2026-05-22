<?php /** modal: timeout-modal.php — single / bulk time-out confirmation */ ?>

<div id="timeoutModal" class="att-modal" style="display:none;">
  <div class="att-modal-box" style="max-width:420px;">

    <div class="att-modal-header" style="background:#b45309;">
      <div>
        <h3><i class="fa fa-clock"></i> Log Time Out</h3>
        <p id="timeoutModalSubtitle" style="font-size:13px;opacity:0.8;margin-top:2px;"></p>
      </div>
      <button type="button" class="att-modal-close" onclick="closeTimeoutModal()">
        <i class="fa fa-times"></i>
      </button>
    </div>

    <div class="att-modal-body">

      <!-- Time display -->
      <div class="att-notice-box" style="background:#fffbeb;border-color:#fde068;color:#92400e;">
        <strong><i class="fa fa-clock"></i>
          Time Out: <span id="timeoutDisplayTime" style="font-size:15px;font-weight:800;">—</span>
        </strong>
        <p>Current system time is used automatically as the logout time.</p>
      </div>

      <!-- Employee list -->
      <div id="timeoutEmpList" style="margin-top:10px;max-height:200px;overflow-y:auto;"></div>

      <div id="timeoutError" style="display:none;margin-top:10px;padding:10px 14px;
           background:#fef2f2;border:1px solid #fecaca;border-radius:8px;color:#991b1b;font-size:13px;"></div>

    </div>

    <div class="att-modal-footer">
      <button type="button" class="att-btn outline" onclick="closeTimeoutModal()">Cancel</button>
      <button type="button" class="att-btn warning" id="confirmTimeoutBtn" onclick="submitTimeout()">
        <i class="fa fa-clock"></i> Confirm Time Out
      </button>
    </div>
  </div>
</div>

<script>
let _pendingTimeoutIds = [];

window.openTimeoutModal = function(ids, names) {
    _pendingTimeoutIds = ids.map(String);

    const now = new Date();
    document.getElementById('timeoutDisplayTime').textContent =
        now.toLocaleTimeString('en-US', {hour:'2-digit', minute:'2-digit', hour12:true});

    document.getElementById('timeoutModalSubtitle').textContent =
        `Logging out ${ids.length} employee${ids.length !== 1 ? 's' : ''}`;

    const list = document.getElementById('timeoutEmpList');
    if (names && names.length > 0) {
        list.innerHTML = names.map(n =>
            `<div class="att-timeout-emp-row">
               <i class="fa fa-user" style="color:#b45309;width:16px;"></i>
               ${escHtml(n)}
             </div>`
        ).join('');
    } else {
        list.innerHTML = '';
    }

    document.getElementById('timeoutError').style.display = 'none';
    const btn = document.getElementById('confirmTimeoutBtn');
    btn.disabled  = false;
    btn.innerHTML = '<i class="fa fa-clock"></i> Confirm Time Out';

    document.getElementById('timeoutModal').style.display = 'flex';
};

window.closeTimeoutModal = function() {
    document.getElementById('timeoutModal').style.display = 'none';
    _pendingTimeoutIds = [];
};

window.submitTimeout = function() {
    if (_pendingTimeoutIds.length === 0) return;

    const btn    = document.getElementById('confirmTimeoutBtn');
    const errBox = document.getElementById('timeoutError');
    errBox.style.display = 'none';
    btn.disabled  = true;
    btn.innerHTML = '<i class="fa fa-spinner fa-spin"></i> Processing…';

    const fd = new FormData();
    _pendingTimeoutIds.forEach(id => fd.append('attendance_ids[]', id));

    fetch('<?= BASE_URL ?>actions/timeout-attendance.php', {method:'POST', body:fd})
        .then(r => r.json())
        .then(data => {
            if (data.success) {
                closeTimeoutModal();
                location.reload();
            } else {
                errBox.textContent   = data.message || 'Could not process time out.';
                errBox.style.display = 'block';
                btn.disabled  = false;
                btn.innerHTML = '<i class="fa fa-clock"></i> Confirm Time Out';
            }
        })
        .catch(err => {
            errBox.textContent   = err.message || 'Network error. Please try again.';
            errBox.style.display = 'block';
            btn.disabled  = false;
            btn.innerHTML = '<i class="fa fa-clock"></i> Confirm Time Out';
        });
};
</script>
