<?php
/**
 * modal-request-loan.php — included by modules/employee/loans/index.php
 * Employee self-service loan request form.
 * Variables provided by parent: $loanTypes (from loan_types WHERE is_active=1)
 */
if (!isset($loanTypes)) $loanTypes = [];
?>
<!-- REQUEST LOAN MODAL -->
<div class="emp-modal-overlay" id="requestLoanOverlay"
     style="display:none;position:fixed;inset:0;background:rgba(0,0,0,.45);z-index:1000;
            display:none;align-items:center;justify-content:center;padding:16px;">
  <div style="background:#fff;border-radius:14px;width:100%;max-width:540px;max-height:90vh;
              overflow-y:auto;box-shadow:0 20px 60px rgba(0,0,0,.25);">

    <!-- Header -->
    <div style="display:flex;align-items:center;justify-content:space-between;
                padding:18px 22px;border-bottom:1px solid var(--border);">
      <h3 style="margin:0;font-size:16px;font-weight:700;color:#0f172a;display:flex;align-items:center;gap:8px;">
        <i class="fa fa-file-invoice-dollar" style="color:#0d9488;"></i>
        Request a Loan
      </h3>
      <button onclick="closeRequestLoan()"
              style="border:none;background:none;font-size:18px;color:#94a3b8;cursor:pointer;padding:4px;">
        <i class="fa fa-times"></i>
      </button>
    </div>

    <!-- Body -->
    <div style="padding:20px 22px;">

      <!-- Notice -->
      <div style="display:flex;align-items:flex-start;gap:10px;padding:10px 14px;
                  background:#eff6ff;border:1px solid #bfdbfe;border-radius:10px;margin-bottom:16px;">
        <i class="fa fa-circle-info" style="color:#2563eb;font-size:15px;margin-top:1px;flex-shrink:0;"></i>
        <div style="font-size:12px;color:#1e40af;line-height:1.5;">
          Your request will be submitted to the <strong>HR Admin</strong> and
          <strong>Principal</strong> for review. Deductions will not begin until
          your loan is approved.
        </div>
      </div>

      <!-- Loan Type -->
      <div style="margin-bottom:14px;">
        <label style="display:block;font-size:12px;font-weight:600;color:#374151;margin-bottom:5px;">
          Loan Type <span style="color:#ef4444;">*</span>
        </label>
        <select id="rl-type"
                style="width:100%;padding:9px 12px;border:1px solid var(--border);border-radius:8px;
                       font-size:13px;color:#0f172a;background:#fff;">
          <option value="">— Select loan type —</option>
          <?php foreach ($loanTypes as $lt): ?>
          <option value="<?= $lt['loan_type_id'] ?>"><?= htmlspecialchars($lt['loan_name']) ?></option>
          <?php endforeach; ?>
        </select>
      </div>

      <!-- Provider -->
      <div style="margin-bottom:14px;">
        <label style="display:block;font-size:12px;font-weight:600;color:#374151;margin-bottom:5px;">
          Provider / Lending Institution <span style="color:#ef4444;">*</span>
        </label>
        <input type="text" id="rl-provider"
               placeholder="e.g. SSS, Pag-IBIG, PERAA, Rural Bank of La Paz"
               style="width:100%;padding:9px 12px;border:1px solid var(--border);border-radius:8px;
                      font-size:13px;color:#0f172a;box-sizing:border-box;">
      </div>

      <!-- Account Reference + Interest Rate -->
      <div style="display:grid;grid-template-columns:1fr 1fr;gap:12px;margin-bottom:14px;">
        <div>
          <label style="display:block;font-size:12px;font-weight:600;color:#374151;margin-bottom:5px;">
            Account / Reference Number
          </label>
          <input type="text" id="rl-ref"
                 placeholder="e.g. SSS-2024-001234"
                 style="width:100%;padding:9px 12px;border:1px solid var(--border);border-radius:8px;
                        font-size:13px;color:#0f172a;box-sizing:border-box;">
          <div style="font-size:11px;color:#64748b;margin-top:3px;">From your loan approval letter (optional)</div>
        </div>
        <div>
          <label style="display:block;font-size:12px;font-weight:600;color:#374151;margin-bottom:5px;">
            Interest Rate
            <span style="font-size:11px;color:#94a3b8;font-weight:400;">(% per annum)</span>
          </label>
          <div style="display:flex;align-items:center;border:1px solid var(--border);border-radius:8px;overflow:hidden;">
            <input type="number" id="rl-interest" min="0" step="0.01" placeholder="0.00"
                   oninput="computeRequestLoan()"
                   style="flex:1;padding:9px 10px;border:none;outline:none;font-size:13px;color:#0f172a;">
            <span style="padding:9px 10px;background:#f8fafc;color:#64748b;font-size:13px;border-left:1px solid var(--border);">%</span>
          </div>
          <div style="font-size:11px;color:#64748b;margin-top:3px;">Leave 0 if interest-free</div>
        </div>
      </div>

      <!-- Loan Amount + Monthly Amortization -->
      <div style="display:grid;grid-template-columns:1fr 1fr;gap:12px;margin-bottom:14px;">
        <div>
          <label style="display:block;font-size:12px;font-weight:600;color:#374151;margin-bottom:5px;">
            Loan Amount <span style="color:#ef4444;">*</span>
          </label>
          <div style="display:flex;align-items:center;border:1px solid var(--border);border-radius:8px;overflow:hidden;">
            <span style="padding:9px 10px;background:#f8fafc;color:#64748b;font-size:13px;border-right:1px solid var(--border);">₱</span>
            <input type="number" id="rl-amount" min="0" step="0.01" placeholder="0.00"
                   oninput="computeRequestLoan()"
                   style="flex:1;padding:9px 10px;border:none;outline:none;font-size:13px;color:#0f172a;">
          </div>
        </div>
        <div>
          <label style="display:block;font-size:12px;font-weight:600;color:#374151;margin-bottom:5px;">
            Monthly Amortization <span style="color:#ef4444;">*</span>
          </label>
          <div style="display:flex;align-items:center;border:1px solid var(--border);border-radius:8px;overflow:hidden;">
            <span style="padding:9px 10px;background:#f8fafc;color:#64748b;font-size:13px;border-right:1px solid var(--border);">₱</span>
            <input type="number" id="rl-monthly" min="0" step="0.01" placeholder="0.00"
                   oninput="computeRequestLoan()"
                   style="flex:1;padding:9px 10px;border:none;outline:none;font-size:13px;color:#0f172a;">
          </div>
          <div style="font-size:11px;color:#64748b;margin-top:3px;">
            Per-payroll deduction: <strong id="rl-per-payroll">₱0.00</strong>
          </div>
        </div>
      </div>

      <!-- Total Payable + Current Outstanding Balance -->
      <div style="display:grid;grid-template-columns:1fr 1fr;gap:12px;margin-bottom:14px;">
        <div>
          <label style="display:block;font-size:12px;font-weight:600;color:#374151;margin-bottom:5px;">
            Total Payable
          </label>
          <div style="display:flex;align-items:center;border:1px solid var(--border);border-radius:8px;overflow:hidden;">
            <span style="padding:9px 10px;background:#f8fafc;color:#64748b;font-size:13px;border-right:1px solid var(--border);">₱</span>
            <input type="number" id="rl-total-payable" min="0" step="0.01" placeholder="0.00"
                   oninput="this.dataset.manuallySet='1'; computeRequestLoan();"
                   style="flex:1;padding:9px 10px;border:none;outline:none;font-size:13px;color:#0f172a;">
          </div>
          <div style="font-size:11px;color:#64748b;margin-top:3px;">Auto-computed; update if your letter shows a different figure</div>
        </div>
        <div>
          <label style="display:block;font-size:12px;font-weight:600;color:#374151;margin-bottom:5px;">
            Current Outstanding Balance
          </label>
          <div style="display:flex;align-items:center;border:1px solid var(--border);border-radius:8px;overflow:hidden;">
            <span style="padding:9px 10px;background:#f8fafc;color:#64748b;font-size:13px;border-right:1px solid var(--border);">₱</span>
            <input type="number" id="rl-current-balance" min="0" step="0.01" placeholder="0.00"
                   oninput="this.dataset.manuallySet='1'; _rlUpdateBalanceHint();"
                   style="flex:1;padding:9px 10px;border:none;outline:none;font-size:13px;color:#0f172a;">
          </div>
          <div id="rl-balance-hint" style="display:none;font-size:11px;color:#0369a1;margin-top:3px;line-height:1.4;"></div>
          <div style="font-size:11px;color:#64748b;margin-top:3px;">Leave as loan amount if no prior payments</div>
        </div>
      </div>

      <!-- Start Date -->
      <div style="margin-bottom:14px;">
        <label style="display:block;font-size:12px;font-weight:600;color:#374151;margin-bottom:5px;">
          Preferred Start Date <span style="color:#ef4444;">*</span>
        </label>
        <input type="date" id="rl-start" value="<?= date('Y-m-') ?>15"
               style="width:100%;padding:9px 12px;border:1px solid var(--border);border-radius:8px;
                      font-size:13px;color:#0f172a;box-sizing:border-box;">
        <div style="font-size:11px;color:#64748b;margin-top:3px;">
          HR Admin may adjust the start date during review.
        </div>
      </div>

      <!-- Remarks -->
      <div style="margin-bottom:14px;">
        <label style="display:block;font-size:12px;font-weight:600;color:#374151;margin-bottom:5px;">
          Purpose / Remarks
        </label>
        <textarea id="rl-remarks" rows="2"
                  placeholder="Brief description of loan purpose (optional)…"
                  style="width:100%;padding:9px 12px;border:1px solid var(--border);border-radius:8px;
                         font-size:13px;color:#0f172a;resize:vertical;box-sizing:border-box;"></textarea>
      </div>

      <!-- Document Upload (optional) -->
      <div style="margin-bottom:14px;">
        <label style="display:block;font-size:12px;font-weight:600;color:#374151;margin-bottom:5px;">
          Supporting Documents
          <span style="font-size:11px;color:#94a3b8;font-weight:400;">(optional — PDF, image, max 5 MB each)</span>
        </label>
        <input type="file" id="rl-files" multiple accept=".pdf,.jpg,.jpeg,.png"
               style="width:100%;padding:8px 10px;border:1px dashed var(--border);border-radius:8px;
                      font-size:12px;color:#64748b;box-sizing:border-box;cursor:pointer;">
        <div id="rl-file-list" style="margin-top:6px;font-size:11px;color:#64748b;"></div>
      </div>

      <!-- Computed summary -->
      <div id="rl-summary" style="display:none;padding:10px 14px;background:#f0fdf4;
           border:1px solid #bbf7d0;border-radius:8px;font-size:12px;color:#166534;margin-bottom:6px;">
        <div style="display:flex;gap:20px;flex-wrap:wrap;">
          <span>Estimated term: <strong id="rl-est-term">—</strong></span>
          <span>Estimated end: <strong id="rl-est-end">—</strong></span>
        </div>
      </div>

      <div id="rl-flash" style="display:none;padding:10px 14px;border-radius:8px;
           font-size:12px;margin-bottom:6px;"></div>

    </div>

    <!-- Footer -->
    <div style="display:flex;gap:10px;justify-content:flex-end;padding:14px 22px;
                border-top:1px solid var(--border);">
      <button onclick="closeRequestLoan()"
              style="padding:9px 18px;border:1px solid var(--border);border-radius:8px;
                     background:#fff;color:#374151;font-size:13px;font-weight:600;cursor:pointer;">
        Cancel
      </button>
      <button id="rl-submit-btn" onclick="submitRequestLoan()"
              style="padding:9px 20px;background:#0d9488;color:#fff;border:none;border-radius:8px;
                     font-size:13px;font-weight:700;cursor:pointer;display:flex;align-items:center;gap:6px;">
        <i class="fa fa-paper-plane"></i> Submit Request
      </button>
    </div>

  </div>
</div>

<script>
function openRequestLoan() {
    document.getElementById('requestLoanOverlay').style.display = 'flex';
    // Reset all fields including new financial detail fields
    ['rl-type','rl-provider','rl-ref','rl-interest','rl-amount','rl-monthly',
     'rl-total-payable','rl-current-balance','rl-remarks'].forEach(function(id) {
        var el = document.getElementById(id);
        if (el) { el.value = ''; delete el.dataset.manuallySet; }
    });
    document.getElementById('rl-start').value = new Date().toISOString().substr(0,7) + '-15';
    document.getElementById('rl-per-payroll').textContent = '₱0.00';
    document.getElementById('rl-summary').style.display = 'none';
    var hint = document.getElementById('rl-balance-hint'); if (hint) hint.style.display = 'none';
    var f = document.getElementById('rl-flash'); f.style.display = 'none';
    var fl = document.getElementById('rl-file-list'); if (fl) fl.textContent = '';
    document.getElementById('rl-submit-btn').disabled = false;
}

function closeRequestLoan() {
    document.getElementById('requestLoanOverlay').style.display = 'none';
}

function computeRequestLoan() {
    var amount   = parseFloat(document.getElementById('rl-amount').value)  || 0;
    var monthly  = parseFloat(document.getElementById('rl-monthly').value) || 0;
    var intEl    = document.getElementById('rl-interest');
    var interest = intEl ? (parseFloat(intEl.value) || 0) : 0;

    // Per-payroll display
    var perPay = monthly > 0 ? (monthly / 2).toFixed(2) : '0.00';
    document.getElementById('rl-per-payroll').textContent =
        '₱' + parseFloat(perPay).toLocaleString('en-PH',{minimumFractionDigits:2});

    var tpField  = document.getElementById('rl-total-payable');
    var balField = document.getElementById('rl-current-balance');

    if (amount > 0 && monthly > 0) {
        // Suggested total payable: principal + annual simple interest, rounded to whole payments
        var basePayable     = interest > 0 ? amount * (1 + interest / 100) : amount;
        var computedTerms   = Math.ceil(basePayable / monthly);
        var computedPayable = monthly * computedTerms;

        // Auto-fill total payable only if not manually edited by user
        if (tpField && !tpField.dataset.manuallySet) {
            tpField.value = computedPayable.toFixed(2);
        }
        // Auto-fill balance from loan amount only if not manually edited
        if (balField && !balField.dataset.manuallySet) {
            balField.value = amount.toFixed(2);
        }

        // Use the actual total payable (auto or manual) for the summary term display
        var actualPayable = tpField ? (parseFloat(tpField.value) || computedPayable) : computedPayable;
        var displayTerms  = Math.ceil(actualPayable / monthly);
        var startVal = document.getElementById('rl-start').value;
        var estEnd = '—';
        if (startVal) {
            var d = new Date(startVal);
            d.setMonth(d.getMonth() + displayTerms);
            estEnd = d.toLocaleDateString('en-PH', {year:'numeric',month:'short',day:'numeric'});
        }
        document.getElementById('rl-est-term').textContent = displayTerms + ' month' + (displayTerms !== 1 ? 's' : '');
        document.getElementById('rl-est-end').textContent  = estEnd;
        document.getElementById('rl-summary').style.display = 'block';
    } else {
        if (tpField  && !tpField.dataset.manuallySet)  tpField.value  = '';
        if (balField && !balField.dataset.manuallySet) balField.value = '';
        document.getElementById('rl-summary').style.display = 'none';
    }
    _rlUpdateBalanceHint();
}

function _rlUpdateBalanceHint() {
    var amount  = parseFloat((document.getElementById('rl-amount')          || {}).value) || 0;
    var balance = parseFloat((document.getElementById('rl-current-balance') || {}).value) || 0;
    var monthly = parseFloat((document.getElementById('rl-monthly')         || {}).value) || 0;
    var hint    = document.getElementById('rl-balance-hint');
    if (!hint) return;
    if (amount > 0 && balance > 0 && balance < amount - 0.009 && monthly > 0) {
        var paid     = amount - balance;
        var paysMade = Math.floor(paid / monthly);
        hint.style.display = 'block';
        hint.innerHTML = '<i class="fa fa-circle-info"></i> '
            + (paysMade > 0
                ? '<strong>' + paysMade + ' prior payment' + (paysMade > 1 ? 's' : '') + '</strong>'
                  + ' (₱' + paid.toLocaleString('en-PH',{minimumFractionDigits:2,maximumFractionDigits:2}) + ') will be recorded in the payment log.'
                : 'Balance entered is less than the loan amount.');
    } else {
        hint.style.display = 'none';
    }
}

// Show selected file names
document.addEventListener('DOMContentLoaded', function() {
    var fi = document.getElementById('rl-files');
    if (!fi) return;
    fi.addEventListener('change', function() {
        var list = document.getElementById('rl-file-list');
        if (!fi.files.length) { list.textContent = ''; return; }
        var names = [];
        for (var i = 0; i < fi.files.length; i++) {
            var sz = (fi.files[i].size / 1024).toFixed(0) + ' KB';
            names.push('<span style="display:inline-flex;align-items:center;gap:4px;margin-right:8px;">'
                + '<i class="fa fa-paperclip"></i> ' + fi.files[i].name + ' <em>(' + sz + ')</em></span>');
        }
        list.innerHTML = names.join('');
    });
});

function rlFlash(msg, ok) {
    var f = document.getElementById('rl-flash');
    f.textContent = msg;
    f.style.display = 'block';
    f.style.background = ok ? '#f0fdf4' : '#fef2f2';
    f.style.color = ok ? '#166534' : '#991b1b';
    f.style.border = '1px solid ' + (ok ? '#bbf7d0' : '#fecaca');
}

function submitRequestLoan() {
    var typeId   = document.getElementById('rl-type').value;
    var provider = document.getElementById('rl-provider').value.trim();
    var amount   = parseFloat(document.getElementById('rl-amount').value) || 0;
    var monthly  = parseFloat(document.getElementById('rl-monthly').value) || 0;
    var startDt  = document.getElementById('rl-start').value;
    var remarks  = document.getElementById('rl-remarks').value.trim();

    if (!typeId || !provider || amount <= 0 || monthly <= 0 || !startDt) {
        rlFlash('Please fill in all required fields.', false);
        return;
    }

    var btn = document.getElementById('rl-submit-btn');
    btn.disabled = true;
    btn.innerHTML = '<i class="fa fa-spinner fa-spin"></i> Submitting…';

    var fd = new FormData();
    fd.append('action',            'request');
    fd.append('loan_type_id',      typeId);
    fd.append('provider_name',     provider);
    fd.append('account_reference', (document.getElementById('rl-ref') ? document.getElementById('rl-ref').value.trim() : ''));
    fd.append('interest_rate',     document.getElementById('rl-interest') ? (document.getElementById('rl-interest').value || '0') : '0');
    fd.append('total_amount',      amount);
    fd.append('monthly_deduction', monthly);
    fd.append('total_payable',     document.getElementById('rl-total-payable') ? (document.getElementById('rl-total-payable').value || '') : '');
    fd.append('current_balance',   document.getElementById('rl-current-balance') ? (document.getElementById('rl-current-balance').value || '') : '');
    fd.append('start_date',        startDt);
    fd.append('reason',            remarks);

    fetch('<?= BASE_URL ?>actions/loans-action.php', {method:'POST', body:fd})
    .then(function(r){ return r.json(); })
    .then(function(res) {
        if (!res.success) {
            rlFlash(res.message || 'Submission failed.', false);
            btn.disabled = false;
            btn.innerHTML = '<i class="fa fa-paper-plane"></i> Submit Request';
            return;
        }

        // Upload documents if any were selected
        var loanId  = res.loan_id;
        var fileInput = document.getElementById('rl-files');
        if (fileInput && fileInput.files.length > 0) {
            var docFd = new FormData();
            docFd.append('action',  'upload');
            docFd.append('loan_id', loanId);
            for (var i = 0; i < fileInput.files.length; i++) {
                docFd.append('loan_docs[]', fileInput.files[i]);
            }
            fetch('<?= BASE_URL ?>actions/loan-document-action.php', {method:'POST', body:docFd})
            .then(function(r){ return r.json(); })
            .then(function(docRes){
                if (!docRes.success) {
                    rlFlash('Loan submitted, but document upload failed: ' + (docRes.message || 'Unknown error. Please upload it from the loan details page.'), false);
                    btn.disabled = false;
                    btn.innerHTML = '<i class="fa fa-paper-plane"></i> Submit Request';
                } else {
                    finishRequestLoan(res.message);
                }
            })
            .catch(function(){ finishRequestLoan(res.message); });
        } else {
            finishRequestLoan(res.message);
        }
    })
    .catch(function() {
        rlFlash('Network error. Please try again.', false);
        btn.disabled = false;
        btn.innerHTML = '<i class="fa fa-paper-plane"></i> Submit Request';
    });
}

function finishRequestLoan(msg) {
    closeRequestLoan();
    location.reload(); // reload to show the new pending loan
}
</script>
