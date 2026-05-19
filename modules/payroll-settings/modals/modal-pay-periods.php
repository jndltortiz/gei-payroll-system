<?php
/**
 * modals/modal-pay-periods.php
 * Create Pay Periods modal for Payroll Settings.
 * $settings and $pdo available from parent include.
 */
$c1s = $settings['cutoff1_start_day'] ?? 1;
$c1e = $settings['cutoff1_end_day']   ?? 15;
$c2s = $settings['cutoff2_start_day'] ?? 16;
$c2e = $settings['cutoff2_end_day']   ?? 31;

$ppMonths = ['January','February','March','April','May','June',
             'July','August','September','October','November','December'];
$curMonth  = (int)date('n');
$curYear   = (int)date('Y');
?>

<!-- ========== PAY PERIOD CREATE MODAL ========== -->
<div class="modal fade" id="modalPayPeriods" tabindex="-1" aria-labelledby="modalPayPeriodsLabel" aria-hidden="true">
  <div class="modal-dialog modal-dialog-centered">
    <div class="modal-content" style="border-radius:16px;border:none;">

      <div class="modal-header" style="border-bottom:1px solid #f1f5f9;padding:18px 20px 14px;">
        <h5 class="modal-title" id="modalPayPeriodsLabel" style="font-size:16px;font-weight:700;">
          <i class="bi bi-calendar-plus" style="color:#0d9488;margin-right:6px;"></i>
          Create Pay Periods
        </h5>
        <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
      </div>

      <div class="modal-body" style="padding:18px 20px;">

        <p style="font-size:13px;color:#64748b;margin:0 0 14px;">
          Periods are created using your current cutoff settings:
          <strong>Days <?= $c1s ?>–<?= $c1e ?></strong> and
          <strong>Days <?= $c2s ?>–<?= $c2e ?></strong> of each month.
        </p>

        <!-- Create For -->
        <div style="margin-bottom:14px;">
          <label style="font-size:12px;font-weight:600;display:block;margin-bottom:8px;color:#374151;">
            Create For
          </label>
          <div style="display:flex;gap:8px;">
            <label class="pp-scope-card" style="flex:1;cursor:pointer;">
              <input type="radio" name="ppCreateFor" value="month" checked>
              <span class="pp-scope-inner">
                <i class="bi bi-calendar-event"></i>
                <strong>Single Month</strong>
                <small>Creates 2 periods</small>
              </span>
            </label>
            <label class="pp-scope-card" style="flex:1;cursor:pointer;">
              <input type="radio" name="ppCreateFor" value="year">
              <span class="pp-scope-inner">
                <i class="bi bi-calendar-range"></i>
                <strong>Full Year</strong>
                <small>Creates 24 periods</small>
              </span>
            </label>
          </div>
        </div>

        <!-- Month + Year (shown when month selected) -->
        <div id="ppMonthRow" style="display:flex;gap:10px;margin-bottom:14px;">
          <div style="flex:1;">
            <label style="font-size:12px;font-weight:600;display:block;margin-bottom:4px;color:#374151;">
              Month <span style="color:#ef4444;">*</span>
            </label>
            <select id="ppMonth" class="ps-form-select">
              <?php foreach ($ppMonths as $i => $mn): ?>
              <option value="<?= $i+1 ?>" <?= ($i+1) === $curMonth ? 'selected' : '' ?>>
                <?= $mn ?>
              </option>
              <?php endforeach; ?>
            </select>
          </div>
          <div style="flex:1;">
            <label style="font-size:12px;font-weight:600;display:block;margin-bottom:4px;color:#374151;">
              Year <span style="color:#ef4444;">*</span>
            </label>
            <select id="ppYear" class="ps-form-select">
              <?php for ($y = $curYear; $y <= $curYear + 3; $y++): ?>
              <option value="<?= $y ?>" <?= $y === $curYear ? 'selected' : '' ?>><?= $y ?></option>
              <?php endfor; ?>
            </select>
          </div>
        </div>

        <!-- Year-only (shown when full year selected) -->
        <div id="ppYearOnlyRow" style="display:none;margin-bottom:14px;">
          <label style="font-size:12px;font-weight:600;display:block;margin-bottom:4px;color:#374151;">
            Year <span style="color:#ef4444;">*</span>
          </label>
          <select id="ppYearOnly" class="ps-form-select" style="width:100%;">
            <?php for ($y = $curYear; $y <= $curYear + 3; $y++): ?>
            <option value="<?= $y ?>" <?= $y === $curYear ? 'selected' : '' ?>><?= $y ?></option>
            <?php endfor; ?>
          </select>
        </div>

        <div id="ppModalFlash" style="display:none;"></div>

      </div>

      <div class="modal-footer" style="border-top:1px solid #f1f5f9;padding:14px 20px;">
        <button type="button" class="ps-btn-secondary" data-bs-dismiss="modal">Cancel</button>
        <button type="button" class="ps-btn-primary" id="ppCreateBtn" onclick="submitCreatePeriods()">
          <i class="bi bi-plus-lg"></i> Create
        </button>
      </div>

    </div>
  </div>
</div>

<style>
.pp-scope-card input { display:none; }
.pp-scope-inner {
    display:flex; flex-direction:column; align-items:center; gap:3px;
    border:1.5px solid #e5e7eb; border-radius:10px; padding:12px 8px;
    text-align:center; font-size:12px; transition:border-color .15s, background .15s;
    cursor:pointer;
}
.pp-scope-inner i    { font-size:20px; color:#6b7280; margin-bottom:2px; }
.pp-scope-inner strong { font-size:13px; color:#0f172a; }
.pp-scope-inner small  { color:#9ca3af; font-size:11px; }
.pp-scope-card input:checked + .pp-scope-inner {
    border-color:#0d9488; background:#f0fdf9;
}
.pp-scope-card input:checked + .pp-scope-inner i { color:#0d9488; }
</style>