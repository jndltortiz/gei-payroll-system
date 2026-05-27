<?php
/**
 * modal-add-loan.php — included by modules/loans/index.php
 * Variables provided by parent: $empList, $loanTypes
 */
if (!isset($empList))   $empList   = [];
if (!isset($loanTypes)) $loanTypes = [];
?>
<!-- ADD LOAN RECORD MODAL -->
<div class="loan-modal-overlay" id="addLoanOverlay">
  <div class="loan-modal-box">
    <div class="loan-modal-header">
      <h3><i class="fa fa-file-invoice-dollar" style="color:#0d9488"></i> Add Loan Record</h3>
      <button onclick="closeAddLoan()"><i class="fa fa-times"></i></button>
    </div>
    <div class="loan-modal-body">

      <div class="lf-row">
        <div class="lf-field lf-field--full">
          <label>Employee <span class="req">*</span></label>
          <select id="al-employee" onchange="onAddLoanEmployeeChange(this)">
            <option value="">— Select employee —</option>
            <?php foreach ($empList as $emp): ?>
            <option value="<?= $emp['employee_id'] ?>"
              data-salary="<?= $emp['monthly_salary'] ?? 0 ?>"
              data-position="<?= htmlspecialchars($emp['position_name'] ?? '') ?>"
              data-dept="<?= htmlspecialchars($emp['department_name'] ?? '') ?>"
              data-hire="<?= $emp['hire_date'] ?>"
              data-type="<?= htmlspecialchars($emp['employment_type'] ?? '') ?>">
              <?= htmlspecialchars($emp['full_name']) ?>
            </option>
            <?php endforeach; ?>
          </select>
        </div>
      </div>

      <!-- Employee card (shown after selection) -->
      <div id="al-emp-card" style="display:none" class="review-emp-card">
        <div class="emp-avatar" id="al-initials">—</div>
        <div>
          <strong id="al-emp-name">—</strong>
          <span id="al-emp-sub">—</span>
          <div style="display:flex;gap:16px;margin-top:4px;font-size:12px;color:#64748b;">
            <span>Since: <strong id="al-hire-date">—</strong></span>
            <span>Salary: <strong id="al-salary">—</strong></span>
          </div>
        </div>
      </div>

      <div class="lf-row">
        <div class="lf-field">
          <label>Loan Type <span class="req">*</span></label>
          <select id="al-type" onchange="onLoanTypeChange()">
            <option value="">— Select type —</option>
            <?php foreach ($loanTypes as $lt): ?>
            <option value="<?= $lt['loan_type_id'] ?>" data-name="<?= htmlspecialchars($lt['loan_name']) ?>">
              <?= htmlspecialchars($lt['loan_name']) ?>
            </option>
            <?php endforeach; ?>
          </select>
        </div>
        <div class="lf-field">
          <label>Provider / Lending Institution <span class="req">*</span></label>
          <input type="text" id="al-provider" placeholder="e.g. SSS, Pag-IBIG, PERAA, Rural Bank of La Paz">
          <small style="font-size:11px;color:#9ca3af;margin-top:3px;display:block;">
            The government agency or bank that approved the loan.
          </small>
        </div>
      </div>

      <div class="lf-row">
        <div class="lf-field lf-field--full">
          <label>External Reference Number
            <span style="font-weight:400;color:#9ca3af;">(Control / Loan Account #)</span>
          </label>
          <input type="text" id="al-ref" placeholder="e.g. 07-1234567-8 or HDM-2026-00099">
          <small style="font-size:11px;color:#9ca3af;margin-top:3px;display:block;">
            Reference number assigned by the lending institution. Leave blank if not yet available.
          </small>
        </div>
      </div>

      <!-- Submission notice (all loans go to Principal review first) -->
      <div style="display:flex;align-items:flex-start;gap:12px;padding:12px 14px;
                  background:#eff6ff;border:1px solid #bfdbfe;border-radius:10px;margin-bottom:14px;">
        <i class="fa fa-circle-info" style="color:#2563eb;font-size:16px;margin-top:2px;flex-shrink:0;"></i>
        <div>
          <strong style="font-size:13px;color:#1e40af;display:block;margin-bottom:2px;">
            Pending Principal Review
          </strong>
          <span style="font-size:12px;color:#3b82f6;line-height:1.5;">
            This loan record will be submitted to the Principal for review and approval
            before any payroll deductions begin. This applies to all loan types
            (SSS, Pag-IBIG, PERAA, Rural Bank).
          </span>
        </div>
      </div>

      <div class="lf-row">
        <div class="lf-field">
          <label>Total Loan Amount <span class="req">*</span></label>
          <div class="peso-input">
            <span>₱</span>
            <input type="number" id="al-amount" min="0" step="0.01"
                   placeholder="0.00" oninput="computeAddLoan()">
          </div>
        </div>
        <div class="lf-field">
          <label>Interest Rate (%)</label>
          <input type="number" id="al-interest" min="0" step="0.01"
                 placeholder="0.00" oninput="computeAddLoan()">
        </div>
      </div>

      <div class="lf-row">
        <div class="lf-field">
          <label>Term (months) <span class="req">*</span></label>
          <input type="number" id="al-term" min="1" step="1"
                 placeholder="e.g. 24" oninput="computeAddLoan()">
        </div>
        <div class="lf-field">
          <label>Monthly Amortization <span class="req">*</span></label>
          <div class="peso-input">
            <span>₱</span>
            <input type="number" id="al-monthly" min="0" step="0.01"
                   placeholder="Auto-calculated" oninput="computeAddLoanFromMonthly()">
          </div>
          <small style="font-size:11px;color:#9ca3af;margin-top:3px;display:block;">
            Full monthly payment. The per-payroll deduction is this ÷ 2 for semi-monthly payroll.
          </small>
        </div>
      </div>

      <!-- Auto-deduction per payroll (read-only, computed) -->
      <div class="lf-row">
        <div class="lf-field">
          <label>Start Date <span class="req">*</span></label>
          <input type="date" id="al-start" value="<?= date('Y-m-') ?>15">
        </div>
        <div class="lf-field">
          <label style="display:flex;align-items:center;gap:6px;">
            Auto-Deduction per Payroll
            <span style="font-size:10px;background:#e0f2fe;color:#0369a1;padding:2px 6px;border-radius:4px;font-weight:600">READ-ONLY</span>
          </label>
          <div class="peso-input peso-input--readonly" style="background:#f0f9ff">
            <span>₱</span>
            <input type="text" id="al-auto-deduction" readonly placeholder="0.00"
                   title="Monthly Amortization ÷ 2 (semi-monthly payroll)">
          </div>
          <small style="font-size:11px;color:#0369a1;margin-top:3px;display:block;">
            This amount will be deducted each payroll run (15th &amp; 30th).
          </small>
        </div>
      </div>

      <div class="lf-row">
        <div class="lf-field">
          <label>Total Payable</label>
          <div class="peso-input peso-input--readonly">
            <span>₱</span>
            <input type="text" id="al-total-payable" readonly placeholder="0.00">
          </div>
        </div>
      </div>

      <!-- Current balance — for loans already partially paid before entry -->
      <div class="lf-row">
        <div class="lf-field lf-field--full">
          <label>
            Current Outstanding Balance
            <span class="req">*</span>
          </label>
          <div class="peso-input">
            <span>₱</span>
            <input type="number" id="al-current-balance" min="0" step="0.01"
                   placeholder="Auto-filled from Total Amount" oninput="updateBalanceHint()">
          </div>
          <div id="al-balance-hint" style="display:none;margin-top:5px;padding:8px 12px;
               background:#eff6ff;border:1px solid #bfdbfe;border-radius:8px;font-size:12px;color:#1e40af;">
          </div>
          <small style="font-size:11px;color:#9ca3af;margin-top:3px;display:block;">
            Leave blank if no payments have been made yet. If the employee already made payments
            before this record was entered, enter the actual remaining balance — prior payments
            will automatically appear as paid in the payment log.
          </small>
        </div>
      </div>

      <div class="lf-field" style="margin-top:2px;">
        <label>Notes / Purpose</label>
        <textarea id="al-reason" rows="2"
                  placeholder="Brief description of loan purpose or additional notes…"></textarea>
      </div>

      <!-- Document Upload (optional) -->
      <div class="lf-field" style="margin-top:10px;">
        <label style="display:flex;align-items:center;gap:6px;">
          Supporting Documents
          <span style="font-size:10px;background:#f0fdf4;color:#166534;padding:2px 6px;border-radius:4px;font-weight:600;">OPTIONAL</span>
        </label>
        <input type="file" id="al-files" multiple accept=".pdf,.jpg,.jpeg,.png"
               style="width:100%;padding:8px 10px;border:1px dashed #cbd5e1;border-radius:8px;
                      font-size:12px;color:#64748b;box-sizing:border-box;cursor:pointer;margin-top:4px;">
        <div id="al-file-list" style="font-size:11px;color:#64748b;margin-top:4px;"></div>
        <small style="font-size:11px;color:#9ca3af;margin-top:3px;display:block;">
          Attach loan approval documents, vouchers, or relevant files (PDF, JPG, PNG — max 5 MB each).
          Files are uploaded after the loan record is created.
        </small>
      </div>

      <div id="al-salary-warning" style="display:none"
           class="loan-notice loan-notice--warn"></div>

      <div id="al-flash" style="display:none" class="loan-flash"></div>

    </div>
    <div class="loan-modal-footer">
      <button class="btn-outline" onclick="closeAddLoan()">Cancel</button>
      <button class="btn-primary" id="al-submit-btn" onclick="submitAddLoan()">
        <i class="fa fa-paper-plane"></i> Submit for Principal Review
      </button>
    </div>
  </div>
</div>
