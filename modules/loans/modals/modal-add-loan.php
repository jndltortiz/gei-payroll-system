<?php
/**
 * modal-add-loan.php — included by modules/loans/index.php
 * Variables provided by parent: $empList, $loanTypes
 */
if (!isset($empList))   $empList   = [];
if (!isset($loanTypes)) $loanTypes = [];
?>
<!-- ADD LOAN MODAL -->
<div class="loan-modal-overlay" id="addLoanOverlay">
  <div class="loan-modal-box">
    <div class="loan-modal-header">
      <h3><i class="fa fa-plus-circle" style="color:#0d9488"></i> Add Loan</h3>
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
              data-hire="<?= $emp['hire_date'] ?>">
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
          <select id="al-type">
            <option value="">— Select type —</option>
            <?php foreach ($loanTypes as $lt): ?>
            <option value="<?= $lt['loan_type_id'] ?>" data-name="<?= htmlspecialchars($lt['loan_name']) ?>">
              <?= htmlspecialchars($lt['loan_name']) ?>
            </option>
            <?php endforeach; ?>
          </select>
        </div>
        <div class="lf-field">
          <label>External Reference # <span style="font-weight:400;color:#9ca3af;">(from SSS / HDMF / Bank)</span></label>
          <input type="text" id="al-ref" placeholder="e.g. SSS-2026-00123">
          <small style="font-size:11px;color:#9ca3af;margin-top:3px;display:block;">
            The reference number assigned by the lending institution. Leave blank if not yet available.
          </small>
        </div>
      </div>

      <!-- Status toggle: most loans are externally pre-approved -->
      <div class="lf-field" style="margin-bottom:14px;">
        <label>Loan Status <span class="req">*</span></label>
        <div class="loan-status-toggle" id="al-status-group">
          <label class="status-toggle-option">
            <input type="radio" name="al_status" value="ACTIVE" checked
                   onchange="updateStatusHint()">
            <span>
              <strong>Active immediately</strong>
              <small>Loan was approved externally (SSS, HDMF, PERAA). Start deducting now.</small>
            </span>
          </label>
          <label class="status-toggle-option">
            <input type="radio" name="al_status" value="PENDING"
                   onchange="updateStatusHint()">
            <span>
              <strong>Pending approval</strong>
              <small>Needs Principal approval first (e.g. Rural Bank of La Paz loan).</small>
            </span>
          </label>
        </div>
      </div>

      <div class="lf-row">
        <div class="lf-field">
          <label>Total Amount <span class="req">*</span></label>
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
          <label>Monthly Deduction</label>
          <div class="peso-input">
            <span>₱</span>
            <input type="number" id="al-monthly" min="0" step="0.01"
                   placeholder="Auto-calculated" oninput="computeAddLoanFromMonthly()">
          </div>
        </div>
      </div>

      <div class="lf-row">
        <div class="lf-field">
          <label>Start Date <span class="req">*</span></label>
          <input type="date" id="al-start" value="<?= date('Y-m-') ?>15">
        </div>
        <div class="lf-field">
          <label>Total Payable</label>
          <div class="peso-input peso-input--readonly">
            <span>₱</span>
            <input type="text" id="al-total-payable" readonly placeholder="0.00">
          </div>
        </div>
      </div>

      <!-- Current balance — key for loans that started before being entered into the system -->
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
            Leave blank if no payments have been made yet. If payments were already collected
            before this loan was entered into the system, enter the actual remaining balance
            from the employee's loan statement — past payments will automatically show as Paid.
          </small>
        </div>
      </div>

      <div class="lf-field" style="margin-top:2px;">
        <label>Purpose / Reason</label>
        <textarea id="al-reason" rows="2"
                  placeholder="Brief description of the loan purpose…"></textarea>
      </div>

      <div id="al-salary-warning" style="display:none"
           class="loan-notice loan-notice--warn"></div>

      <div id="al-flash" style="display:none" class="loan-flash"></div>

    </div>
    <div class="loan-modal-footer">
      <button class="btn-outline" onclick="closeAddLoan()">Cancel</button>
      <button class="btn-primary" id="al-submit-btn" onclick="submitAddLoan()">
        <i class="fa fa-plus"></i> Add Loan
      </button>
    </div>
  </div>
</div>