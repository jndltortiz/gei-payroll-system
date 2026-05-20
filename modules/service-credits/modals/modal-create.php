<?php
/**
 * modal-create.php — Create / Edit service credit
 * Variables: $employees (from parent index.php)
 */
if (!isset($employees)) $employees = [];
?>
<div class="sc-modal-overlay" id="createModal" style="display:none;">
  <div class="sc-modal-box">
    <div class="sc-modal-header">
      <h3 id="createModalTitle"><i class="fa fa-plus-circle"></i> Create Service Credit</h3>
      <button onclick="closeModal('createModal')"><i class="fa fa-times"></i></button>
    </div>

    <form id="scCreateForm" method="POST" action="<?= BASE_URL ?>actions/service-credits-action.php">
      <input type="hidden" name="action"            id="scFormAction" value="submit">
      <input type="hidden" name="service_credit_id" id="scFormId"     value="">

      <div class="sc-modal-body">

        <!-- Employee -->
        <div class="sc-form-group">
          <label>Employee <span class="req">*</span></label>
          <select name="employee_id" id="scEmployee" required
                  onchange="onScEmployeeChange(this.value)">
            <option value="">— Select employee —</option>
            <?php foreach ($employees as $e): ?>
            <option value="<?= $e['employee_id'] ?>"
                    data-rate="<?= number_format((float)($e['daily_rate']??0), 2, '.', '') ?>">
              <?= htmlspecialchars($e['full_name'].' — '.$e['position_name']) ?>
            </option>
            <?php endforeach; ?>
          </select>
          <div id="scRateHint" style="display:none;" class="sc-rate-hint">
            <i class="fa fa-circle-info"></i>
            Daily rate: <strong id="scRateDisplay">—</strong>
            &nbsp;·&nbsp; Equivalent pay = Days × Daily Rate
          </div>
        </div>

        <!-- Date + Days (2-col) -->
        <div class="sc-form-row">
          <div class="sc-form-group">
            <label>Date of Extra Work <span class="req">*</span></label>
            <input type="date" name="work_date" id="scWorkDate"
                   value="<?= date('Y-m-d') ?>" max="<?= date('Y-m-d') ?>" required>
          </div>
          <div class="sc-form-group">
            <label>Number of Days <span class="req">*</span></label>
            <input type="number" name="days" id="scDays"
                   value="1.0" step="0.5" min="0.5" max="30" required
                   oninput="computeScPay()">
          </div>
        </div>

        <!-- Equivalent Pay -->
        <div class="sc-form-group">
          <label>
            Equivalent Pay (₱) <span class="req">*</span>
            <span id="scAutoLabel" style="display:none;" class="sc-auto-badge">
              <i class="fa fa-bolt"></i> Auto-computed
            </span>
          </label>
          <div class="sc-peso-input">
            <span>₱</span>
            <input type="number" name="equivalent_pay" id="scEquivPay"
                   value="0.00" step="0.01" min="0" required>
          </div>
          <small>Computed from Days × Daily Rate. Adjust if a different rate applies (e.g. holiday, overtime).</small>
        </div>

        <!-- Description -->
        <div class="sc-form-group">
          <label>Description of Extra Work</label>
          <textarea name="remarks" id="scRemarks" rows="3"
                    placeholder="e.g. Saturday tutorial, enrollment duty, school event…"
                    maxlength="255"></textarea>
          <span class="sc-char-count" id="scCharCount">0 / 255</span>
        </div>

      </div><!-- .sc-modal-body -->

      <div class="sc-modal-footer">
        <button type="button" class="sc-btn-ghost" onclick="closeModal('createModal')">Cancel</button>
        <button type="button" class="sc-btn-outline" id="btnSaveDraft"
                onclick="setScAction('save_draft')">
          <i class="fa fa-floppy-disk"></i> Save as Draft
        </button>
        <button type="submit" class="sc-btn-primary" id="btnSubmitApproval"
                onclick="setScAction('submit')">
          <i class="fa fa-paper-plane"></i> Submit for Approval
        </button>
      </div>
    </form>
  </div>
</div>