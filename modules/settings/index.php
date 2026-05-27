<?php
/**
 * modules/settings/index.php
 * System Settings hub — navigation cards for all configuration sub-modules.
 */
require_once __DIR__ . '/../../includes/auth.php';
requireAdminPage();

$pageTitle = 'System Settings';
$extraCSS  = [];
require_once __DIR__ . '/../../includes/head.php';
?>
<style>
/* ── Settings hub (set- prefix) ─────────────────────────────────────────────── */
.set-page {
    max-width: 960px;
    margin: 0 auto;
}

.set-page-hdr {
    margin-bottom: 24px;
}
.set-page-hdr h1 {
    font-size: 20px;
    font-weight: 700;
    color: var(--text-primary);
    margin: 0 0 4px;
    display: flex;
    align-items: center;
    gap: 10px;
}
.set-page-hdr h1 i { color: var(--accent); font-size: 18px; }
.set-page-hdr p {
    font-size: 13px;
    color: var(--text-secondary);
    margin: 0;
}

/* Section label */
.set-section-label {
    font-size: 11px;
    font-weight: 700;
    color: var(--text-muted);
    letter-spacing: .6px;
    text-transform: uppercase;
    margin: 28px 0 12px;
}
.set-section-label:first-of-type { margin-top: 0; }

/* Cards grid */
.set-grid {
    display: grid;
    grid-template-columns: repeat(3, 1fr);
    gap: 14px;
}

/* Individual card */
.set-card {
    display: flex;
    flex-direction: column;
    background: var(--bg-card);
    border: 1px solid var(--border);
    border-radius: var(--radius);
    padding: 20px;
    text-decoration: none;
    box-shadow: var(--shadow);
    transition: border-color .18s, box-shadow .18s, transform .12s;
    cursor: pointer;
    position: relative;
    overflow: hidden;
}
.set-card:hover {
    border-color: var(--accent);
    box-shadow: 0 4px 20px rgba(0,0,0,.10);
    transform: translateY(-1px);
}
.set-card::before {
    content: '';
    position: absolute;
    left: 0; top: 0; bottom: 0;
    width: 3px;
    background: var(--accent);
    opacity: 0;
    transition: opacity .18s;
    border-radius: var(--radius) 0 0 var(--radius);
}
.set-card:hover::before { opacity: 1; }

.set-card-head {
    display: flex;
    align-items: center;
    gap: 12px;
    margin-bottom: 10px;
}
.set-card-icon {
    width: 40px;
    height: 40px;
    border-radius: 10px;
    background: var(--accent-light);
    display: flex;
    align-items: center;
    justify-content: center;
    color: var(--accent);
    font-size: 17px;
    flex-shrink: 0;
    transition: background .18s;
}
.set-card:hover .set-card-icon {
    background: var(--accent-mid);
}
.set-card-title {
    font-size: 14px;
    font-weight: 700;
    color: var(--text-primary);
    line-height: 1.2;
}

.set-card-desc {
    font-size: 12px;
    color: var(--text-secondary);
    line-height: 1.55;
    flex: 1;
    margin-bottom: 14px;
}

.set-card-cta {
    display: inline-flex;
    align-items: center;
    gap: 5px;
    font-size: 12px;
    font-weight: 600;
    color: var(--accent);
}
.set-card-cta i { font-size: 10px; transition: transform .15s; }
.set-card:hover .set-card-cta i { transform: translateX(3px); }

/* Responsive */
@media (max-width: 820px) {
    .set-grid { grid-template-columns: repeat(2, 1fr); }
}
@media (max-width: 520px) {
    .set-grid { grid-template-columns: 1fr; }
}
</style>
<body>
<?php include __DIR__ . '/../../includes/sidebar.php'; ?>
<div class="main">
  <?php include __DIR__ . '/../../includes/header.php'; ?>
  <div class="main-content">
  <div class="set-page">

    <!-- Page header -->
    <div class="set-page-hdr">
      <h1><i class="fa fa-gear"></i> System Settings</h1>
      <p>Configure payroll rules, work schedules, leave policies, and academic calendar data.</p>
    </div>

    <!-- ── Payroll configuration ───────────────────────────────────────────── -->
    <div class="set-section-label">Payroll Configuration</div>
    <div class="set-grid" style="grid-template-columns: repeat(3,1fr);">

      <a class="set-card" href="<?= BASE_URL ?>modules/payroll-settings/index.php">
        <div class="set-card-head">
          <div class="set-card-icon"><i class="fa fa-sliders"></i></div>
          <div class="set-card-title">Payroll Settings</div>
        </div>
        <div class="set-card-desc">
          Configure allowance types, deduction types, pay rates, government calculation mode, and payroll frequency rules.
        </div>
        <div class="set-card-cta">Open <i class="fa fa-arrow-right"></i></div>
      </a>

      <a class="set-card" href="<?= BASE_URL ?>modules/shifts/index.php">
        <div class="set-card-head">
          <div class="set-card-icon"><i class="fa fa-business-time"></i></div>
          <div class="set-card-title">Shift Management</div>
        </div>
        <div class="set-card-desc">
          Define work schedules and time thresholds that drive attendance status — PRESENT, LATE, and HALF_DAY logic.
        </div>
        <div class="set-card-cta">Open <i class="fa fa-arrow-right"></i></div>
      </a>

      <a class="set-card" href="<?= BASE_URL ?>modules/holidays/index.php">
        <div class="set-card-head">
          <div class="set-card-icon"><i class="fa fa-calendar-check"></i></div>
          <div class="set-card-title">Holiday Calendar</div>
        </div>
        <div class="set-card-desc">
          Manage Philippine national holidays, special non-working days, and institution-specific school holidays.
        </div>
        <div class="set-card-cta">Open <i class="fa fa-arrow-right"></i></div>
      </a>

    </div>

    <!-- ── Leave & academic configuration ─────────────────────────────────── -->
    <div class="set-section-label">Leave &amp; Academic Configuration</div>
    <div class="set-grid" style="grid-template-columns: repeat(3,1fr);">

      <a class="set-card" href="<?= BASE_URL ?>modules/leave-credits/index.php">
        <div class="set-card-head">
          <div class="set-card-icon"><i class="fa fa-id-card-clip"></i></div>
          <div class="set-card-title">Leave Credits</div>
        </div>
        <div class="set-card-desc">
          Allocate and track leave entitlements per employee, per leave type, and per school year.
        </div>
        <div class="set-card-cta">Open <i class="fa fa-arrow-right"></i></div>
      </a>

      <a class="set-card" href="<?= BASE_URL ?>modules/school-years/index.php">
        <div class="set-card-head">
          <div class="set-card-icon"><i class="fa fa-graduation-cap"></i></div>
          <div class="set-card-title">School Years</div>
        </div>
        <div class="set-card-desc">
          Define academic year cycles, set the currently active school year, and link leave credits to the correct period.
        </div>
        <div class="set-card-cta">Open <i class="fa fa-arrow-right"></i></div>
      </a>

    </div>

  </div><!-- .set-page -->
  </div><!-- .main-content -->
</div><!-- .main -->
<?php include __DIR__ . '/../../includes/footer.php'; ?>
