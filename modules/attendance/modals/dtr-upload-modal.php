<?php /** modal: dtr-upload-modal.php — DTR Backup File Upload */ ?>

<div id="dtrUploadModal" class="att-modal" style="display:none;">
  <div class="att-modal-box" style="max-width:520px;">

    <div class="att-modal-header" style="background:#1d4ed8;">
      <div>
        <h3><i class="fa fa-file-arrow-up"></i> Upload DTR / Attendance Backup</h3>
        <p style="font-size:12px;opacity:0.8;margin-top:2px;">
          For audit history only — does not modify existing attendance records
        </p>
      </div>
      <button type="button" class="att-modal-close" onclick="closeDtrModal()">
        <i class="fa fa-times"></i>
      </button>
    </div>

    <div id="dtrUploadError" style="display:none;margin:12px 16px 0;padding:10px 14px;
         background:#fef2f2;border:1px solid #fecaca;border-radius:8px;color:#991b1b;font-size:13px;"></div>

    <div class="att-modal-body" style="gap:14px;">

      <!-- Notice -->
      <div class="att-notice-box" style="background:#eff6ff;border-color:#bfdbfe;color:#1e40af;">
        <strong><i class="fa fa-circle-info"></i> Backup Documentation Only</strong>
        <p>Use this when attendance was recorded on paper, Excel, or PDF during a system outage or manual process. This file is stored for audit and reference — it will not update any attendance records automatically.</p>
      </div>

      <!-- Date Range -->
      <div class="att-field-row">
        <div class="att-field">
          <label>Date From <span style="color:#dc2626;">*</span></label>
          <input type="date" id="dtrDateFrom" max="<?= date('Y-m-d') ?>">
        </div>
        <div class="att-field">
          <label>Date To</label>
          <input type="date" id="dtrDateTo" max="<?= date('Y-m-d') ?>">
          <small style="color:#94a3b8;font-size:11px;">Leave blank for single-day upload</small>
        </div>
      </div>

      <!-- Department -->
      <div class="att-field">
        <label>Department <span style="color:#94a3b8;font-size:11px;">(optional)</span></label>
        <select id="dtrDept">
          <option value="">— All Departments —</option>
          <?php foreach ($depts as $d): ?>
          <option value="<?= $d['department_id'] ?>"><?= htmlspecialchars($d['department_name']) ?></option>
          <?php endforeach; ?>
        </select>
      </div>

      <!-- File Upload -->
      <div class="att-field">
        <label>File <span style="color:#dc2626;">*</span></label>
        <div id="dtrDropZone" class="dtr-drop-zone" onclick="document.getElementById('dtrFile').click()"
             ondragover="event.preventDefault();this.classList.add('drag-over')"
             ondragleave="this.classList.remove('drag-over')"
             ondrop="dtrHandleDrop(event)">
          <i class="fa fa-cloud-arrow-up" style="font-size:28px;color:#3b82f6;margin-bottom:8px;"></i>
          <div style="font-weight:600;color:#374151;">Click or drag file here</div>
          <div id="dtrFileName" style="font-size:12px;color:#6b7280;margin-top:4px;">No file selected</div>
          <div style="font-size:11px;color:#9ca3af;margin-top:6px;">Excel (.xls .xlsx) · PDF · Image (.jpg .png) · Max 10 MB</div>
        </div>
        <input type="file" id="dtrFile" style="display:none;"
               accept=".xls,.xlsx,.pdf,.jpg,.jpeg,.png"
               onchange="dtrFileSelected(this)">
      </div>

      <!-- Notes -->
      <div class="att-field">
        <label>Notes / Explanation <span style="color:#94a3b8;font-size:11px;">(optional)</span></label>
        <textarea id="dtrNotes" rows="3"
                  placeholder="e.g. System offline during morning attendance — manual logbook scanned"
                  style="padding:9px 12px;border-radius:8px;border:1px solid #d1d5db;font-size:13px;width:100%;resize:vertical;box-sizing:border-box;"></textarea>
      </div>

    </div><!-- /modal-body -->

    <div class="att-modal-footer">
      <button type="button" class="att-btn outline" onclick="closeDtrModal()">Cancel</button>
      <button type="button" class="att-btn primary" id="dtrSubmitBtn" onclick="submitDtrUpload()">
        <i class="fa fa-file-arrow-up"></i> Upload
      </button>
    </div>

  </div>
</div>

<style>
.dtr-drop-zone {
    border: 2px dashed #93c5fd;
    border-radius: 10px;
    padding: 24px 16px;
    text-align: center;
    cursor: pointer;
    background: #f8faff;
    transition: background 0.15s, border-color 0.15s;
}
.dtr-drop-zone:hover,
.dtr-drop-zone.drag-over {
    background: #eff6ff;
    border-color: #3b82f6;
}
.dtr-drop-zone.file-ready {
    background: #f0fdf4;
    border-color: #4ade80;
}
</style>

<script>
let _dtrFile = null;

window.openDtrModal = function() {
    document.getElementById('dtrUploadModal').style.display = 'flex';
    document.getElementById('dtrUploadError').style.display = 'none';
    // Default date_from to today
    const today = new Date().toISOString().slice(0, 10);
    document.getElementById('dtrDateFrom').value = today;
    document.getElementById('dtrDateTo').value   = '';
    document.getElementById('dtrDept').value     = '';
    document.getElementById('dtrFile').value     = '';
    document.getElementById('dtrNotes').value    = '';
    document.getElementById('dtrFileName').textContent = 'No file selected';
    document.getElementById('dtrDropZone').classList.remove('file-ready');
    _dtrFile = null;
};

window.closeDtrModal = function() {
    document.getElementById('dtrUploadModal').style.display = 'none';
    _dtrFile = null;
};

window.dtrFileSelected = function(input) {
    if (input.files && input.files[0]) {
        _dtrFile = input.files[0];
        document.getElementById('dtrFileName').textContent = _dtrFile.name + ' (' + (_dtrFile.size / 1024).toFixed(1) + ' KB)';
        document.getElementById('dtrDropZone').classList.add('file-ready');
    }
};

window.dtrHandleDrop = function(e) {
    e.preventDefault();
    document.getElementById('dtrDropZone').classList.remove('drag-over');
    const f = e.dataTransfer.files[0];
    if (!f) return;
    const allowed = ['xls','xlsx','pdf','jpg','jpeg','png'];
    const ext = f.name.split('.').pop().toLowerCase();
    if (!allowed.includes(ext)) {
        alert('File type not allowed. Please upload Excel, PDF, or Image files only.');
        return;
    }
    _dtrFile = f;
    document.getElementById('dtrFileName').textContent = f.name + ' (' + (f.size / 1024).toFixed(1) + ' KB)';
    document.getElementById('dtrDropZone').classList.add('file-ready');
};

window.submitDtrUpload = function() {
    const errBox = document.getElementById('dtrUploadError');
    const btn    = document.getElementById('dtrSubmitBtn');
    errBox.style.display = 'none';

    const dateFrom = document.getElementById('dtrDateFrom').value;
    const dateTo   = document.getElementById('dtrDateTo').value;

    if (!dateFrom) {
        errBox.textContent = 'Please select a start date.';
        errBox.style.display = 'block';
        return;
    }
    if (!_dtrFile) {
        errBox.textContent = 'Please select a file to upload.';
        errBox.style.display = 'block';
        return;
    }
    if (_dtrFile.size > 10 * 1024 * 1024) {
        errBox.textContent = 'File exceeds 10 MB limit.';
        errBox.style.display = 'block';
        return;
    }

    btn.disabled  = true;
    btn.innerHTML = '<i class="fa fa-spinner fa-spin"></i> Uploading…';

    const fd = new FormData();
    fd.append('date_from',     dateFrom);
    fd.append('date_to',       dateTo || dateFrom);
    fd.append('department_id', document.getElementById('dtrDept').value);
    fd.append('notes',         document.getElementById('dtrNotes').value.trim());
    fd.append('dtr_file',      _dtrFile);

    fetch('<?= BASE_URL ?>actions/upload-dtr.php', { method: 'POST', body: fd })
        .then(r => r.json())
        .then(data => {
            if (data.success) {
                closeDtrModal();
                // Show success toast if available, else alert
                if (typeof showToast === 'function') {
                    showToast('DTR file uploaded successfully.', 'ok');
                } else {
                    alert('DTR file uploaded successfully.');
                }
                // Reload to show the new attachment in the history tab
                setTimeout(() => location.reload(), 800);
            } else {
                errBox.textContent   = data.message || 'Upload failed.';
                errBox.style.display = 'block';
                btn.disabled  = false;
                btn.innerHTML = '<i class="fa fa-file-arrow-up"></i> Upload';
            }
        })
        .catch(() => {
            errBox.textContent   = 'Network error. Please try again.';
            errBox.style.display = 'block';
            btn.disabled  = false;
            btn.innerHTML = '<i class="fa fa-file-arrow-up"></i> Upload';
        });
};
</script>
