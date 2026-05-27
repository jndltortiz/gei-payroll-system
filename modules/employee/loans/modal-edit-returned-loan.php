<?php
/**
 * modal-edit-returned-loan.php — included by modules/employee/loans/index.php
 * Employee edit & resubmit form for RETURNED loans.
 * Variables provided by parent: $loanTypes
 */
if (!isset($loanTypes)) $loanTypes = [];
?>
<!-- EDIT & RESUBMIT RETURNED LOAN MODAL -->
<div id="editReturnedOverlay"
     style="display:none;position:fixed;inset:0;background:rgba(0,0,0,.45);z-index:1000;
            align-items:center;justify-content:center;padding:16px;">
  <div style="background:#fff;border-radius:14px;width:100%;max-width:540px;max-height:90vh;
              overflow-y:auto;box-shadow:0 20px 60px rgba(0,0,0,.25);">

    <!-- Header -->
    <div style="display:flex;align-items:center;justify-content:space-between;
                padding:18px 22px;border-bottom:1px solid var(--border);">
      <h3 style="margin:0;font-size:16px;font-weight:700;color:#0f172a;display:flex;align-items:center;gap:8px;">
        <i class="fa fa-pen-to-square" style="color:#d97706;"></i>
        Edit &amp; Resubmit Loan
      </h3>
      <button onclick="closeEditReturned()"
              style="border:none;background:none;font-size:18px;color:#94a3b8;cursor:pointer;padding:4px;">
        <i class="fa fa-times"></i>
      </button>
    </div>

    <!-- Body -->
    <div style="padding:20px 22px;">

      <input type="hidden" id="er-loan-id" value="">

      <!-- Return Reason Banner -->
      <div id="er-return-reason-banner"
           style="display:none;padding:10px 14px;background:#fef3c7;border:1px solid #fcd34d;
                  border-radius:10px;margin-bottom:16px;">
        <div style="font-size:11px;font-weight:700;color:#92400e;margin-bottom:4px;
                    display:flex;align-items:center;gap:5px;">
          <i class="fa fa-rotate-left"></i> Returned for Correction
        </div>
        <div style="font-size:12px;color:#78350f;line-height:1.5;">
          <strong>Reason:</strong> <span id="er-return-reason-text"></span>
        </div>
      </div>

      <!-- Loan Type -->
      <div style="margin-bottom:14px;">
        <label style="display:block;font-size:12px;font-weight:600;color:#374151;margin-bottom:5px;">
          Loan Type <span style="color:#ef4444;">*</span>
        </label>
        <select id="er-type"
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
        <input type="text" id="er-provider"
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
          <input type="text" id="er-ref"
                 placeholder="e.g. SSS-2024-001234"
                 style="width:100%;padding:9px 12px;border:1px solid var(--border);border-radius:8px;
                        font-size:13px;color:#0f172a;box-sizing:border-box;">
        </div>
        <div>
          <label style="display:block;font-size:12px;font-weight:600;color:#374151;margin-bottom:5px;">
            Interest Rate
            <span style="font-size:11px;color:#94a3b8;font-weight:400;">(% per annum)</span>
          </label>
          <div style="display:flex;align-items:center;border:1px solid var(--border);border-radius:8px;overflow:hidden;">
            <input type="number" id="er-interest" min="0" step="0.01" placeholder="0.00"
                   style="flex:1;padding:9px 10px;border:none;outline:none;font-size:13px;color:#0f172a;">
            <span style="padding:9px 10px;background:#f8fafc;color:#64748b;font-size:13px;border-left:1px solid var(--border);">%</span>
          </div>
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
            <input type="number" id="er-amount" min="0" step="0.01" placeholder="0.00"
                   style="flex:1;padding:9px 10px;border:none;outline:none;font-size:13px;color:#0f172a;">
          </div>
        </div>
        <div>
          <label style="display:block;font-size:12px;font-weight:600;color:#374151;margin-bottom:5px;">
            Monthly Amortization <span style="color:#ef4444;">*</span>
          </label>
          <div style="display:flex;align-items:center;border:1px solid var(--border);border-radius:8px;overflow:hidden;">
            <span style="padding:9px 10px;background:#f8fafc;color:#64748b;font-size:13px;border-right:1px solid var(--border);">₱</span>
            <input type="number" id="er-monthly" min="0" step="0.01" placeholder="0.00"
                   style="flex:1;padding:9px 10px;border:none;outline:none;font-size:13px;color:#0f172a;">
          </div>
        </div>
      </div>

      <!-- Total Payable + Start Date -->
      <div style="display:grid;grid-template-columns:1fr 1fr;gap:12px;margin-bottom:14px;">
        <div>
          <label style="display:block;font-size:12px;font-weight:600;color:#374151;margin-bottom:5px;">
            Total Payable
          </label>
          <div style="display:flex;align-items:center;border:1px solid var(--border);border-radius:8px;overflow:hidden;">
            <span style="padding:9px 10px;background:#f8fafc;color:#64748b;font-size:13px;border-right:1px solid var(--border);">₱</span>
            <input type="number" id="er-payable" min="0" step="0.01" placeholder="0.00"
                   style="flex:1;padding:9px 10px;border:none;outline:none;font-size:13px;color:#0f172a;">
          </div>
          <div style="font-size:11px;color:#64748b;margin-top:3px;">Update if your letter shows a different figure</div>
        </div>
        <div>
          <label style="display:block;font-size:12px;font-weight:600;color:#374151;margin-bottom:5px;">
            Preferred Start Date <span style="color:#ef4444;">*</span>
          </label>
          <input type="date" id="er-start"
                 style="width:100%;padding:9px 12px;border:1px solid var(--border);border-radius:8px;
                        font-size:13px;color:#0f172a;box-sizing:border-box;">
        </div>
      </div>

      <!-- Remarks -->
      <div style="margin-bottom:14px;">
        <label style="display:block;font-size:12px;font-weight:600;color:#374151;margin-bottom:5px;">
          Purpose / Remarks
        </label>
        <textarea id="er-remarks" rows="2"
                  placeholder="Brief description of loan purpose (optional)…"
                  style="width:100%;padding:9px 12px;border:1px solid var(--border);border-radius:8px;
                         font-size:13px;color:#0f172a;resize:vertical;box-sizing:border-box;"></textarea>
      </div>

      <!-- Flash message -->
      <div id="er-flash" style="display:none;padding:10px 14px;border-radius:8px;
           font-size:13px;font-weight:600;margin-bottom:8px;"></div>

    </div>

    <!-- Footer -->
    <div style="display:flex;justify-content:flex-end;gap:10px;
                padding:14px 22px;border-top:1px solid var(--border);">
      <button onclick="closeEditReturned()"
              style="padding:9px 20px;border:1px solid var(--border);border-radius:8px;
                     background:#fff;color:#374151;font-size:13px;font-weight:600;cursor:pointer;">
        Cancel
      </button>
      <button id="er-submit-btn" onclick="submitEditReturned()"
              style="padding:9px 20px;background:#d97706;color:#fff;border:none;
                     border-radius:8px;font-size:13px;font-weight:700;cursor:pointer;
                     display:inline-flex;align-items:center;gap:6px;">
        <i class="fa fa-paper-plane"></i> Save &amp; Resubmit
      </button>
    </div>

  </div>
</div>
