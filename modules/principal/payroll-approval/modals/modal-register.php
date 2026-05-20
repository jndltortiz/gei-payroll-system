<!-- ── Detailed Payroll Register Modal ───────────────────────────────────── -->
<div class="pr-modal-overlay" id="registerOverlay">
  <div class="pr-modal-box pr-modal-box--xl">
    <div class="pr-modal-header">
      <h3 id="registerTitle">Detailed Payroll Register</h3>
      <div style="display:flex;gap:10px;align-items:center;">
        <button class="pr-btn-export" id="registerExportBtn" onclick="exportRegisterPDF()">
          <i class="fa fa-download"></i> Export PDF
        </button>
        <button onclick="closeRegister()" title="Close"><i class="fa fa-times"></i></button>
      </div>
    </div>
    <div class="pr-modal-body" id="registerBody">
      <div class="pr-loading"><i class="fa fa-spinner fa-spin"></i> Loading…</div>
    </div>
  </div>
</div>