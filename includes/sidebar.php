<?php
/**
 * includes/sidebar.php
 * Unified sidebar navigation — matches GEI HR System design.
 * Active state auto-detected from REQUEST_URI.
 */
function sidebarActive(string $path): string {
    $uri = $_SERVER['REQUEST_URI'] ?? '';
    return str_contains($uri, $path) ? 'active' : '';
}

$_sidebarFirst = htmlspecialchars($_SESSION['user']['first_name'] ?? 'Admin');
$_sidebarRole  = htmlspecialchars($_SESSION['user']['role_name']  ?? '');
$_sidebarInit  = strtoupper(substr($_SESSION['user']['first_name'] ?? 'A', 0, 1));
?>
<nav class="sidebar" id="sidebar">

  <!-- Brand -->
  <div class="sidebar-brand">
    <div class="sidebar-logo">
      <span class="sidebar-logo-text">GEI</span>
    </div>
    <div class="sidebar-brand-name">
      <strong>GEI HR System</strong>
      <span>Great Eastern Institute</span>
    </div>
  </div>

  <!-- Toggle button (chevron on right edge) -->
  <button class="sidebar-toggle-btn" id="sidebarToggle" title="Collapse sidebar">
    <i class="fa fa-chevron-left" id="sidebarChevron"></i>
  </button>

  <!-- User role card (visible when expanded) -->
  <div class="sidebar-role-card" id="sidebarRoleCard">
    <div class="sidebar-role-label"><?= strtoupper($_sidebarRole) ?></div>
    <div class="sidebar-role-name"><?= $_sidebarFirst ?></div>
  </div>

  <div class="sidebar-nav">

    <div class="nav-section-label">OVERVIEW</div>
    <a class="nav-item <?= sidebarActive('modules/dashboard') ?>"
       href="<?= BASE_URL ?>modules/dashboard/index.php">
      <i class="fa fa-table-cells-large"></i>
      <span>Dashboard</span>
    </a>

    <div class="nav-section-label">WORKFORCE</div>
    <a class="nav-item <?= sidebarActive('modules/attendance') ?>"
       href="<?= BASE_URL ?>modules/attendance/index.php">
      <i class="fa fa-clock"></i>
      <span>Attendance</span>
    </a>
    <a class="nav-item <?= sidebarActive('modules/employees') ?>"
       href="<?= BASE_URL ?>modules/employees/index.php">
      <i class="fa fa-users"></i>
      <span>Employees</span>
    </a>

    <div class="nav-section-label">PAYROLL</div>
    <a class="nav-item <?= sidebarActive('modules/payroll/index') ?>"
       href="<?= BASE_URL ?>modules/payroll/index.php">
      <i class="fa fa-file-invoice-dollar"></i>
      <span>Payroll</span>
    </a>
    <a class="nav-item <?= sidebarActive('modules/payroll/archive') ?>"
       href="<?= BASE_URL ?>modules/payroll/archive.php">
      <i class="fa fa-box-archive"></i>
      <span>Payroll Archive</span>
    </a>
    <a class="nav-item <?= sidebarActive('modules/loans') ?>"
       href="<?= BASE_URL ?>modules/loans/index.php">
      <i class="fa fa-hand-holding-dollar"></i>
      <span>Loans</span>
    </a>
    <a class="nav-item <?= sidebarActive('modules/service-credits') ?>"
       href="<?= BASE_URL ?>modules/service-credits/index.php">
      <i class="fa fa-medal"></i>
      <span>Service Credits</span>
    </a>
    <a class="nav-item <?= sidebarActive('modules/payroll-settings') ?>"
       href="<?= BASE_URL ?>modules/payroll-settings/index.php">
      <i class="fa fa-sliders"></i>
      <span>Payroll Settings</span>
    </a>

    <div class="nav-section-label">LEAVE</div>
    <a class="nav-item <?= sidebarActive('modules/leave/') ?>"
       href="<?= BASE_URL ?>modules/leave/index.php">
      <i class="fa fa-calendar-days"></i>
      <span>Leave Records</span>
    </a>
    <a class="nav-item <?= sidebarActive('modules/leave-credits') ?>"
       href="<?= BASE_URL ?>modules/leave-credits/index.php">
      <i class="fa fa-id-card-clip"></i>
      <span>Leave Credits</span>
    </a>
    <a class="nav-item <?= sidebarActive('modules/school-years') ?>"
       href="<?= BASE_URL ?>modules/school-years/index.php">
      <i class="fa fa-graduation-cap"></i>
      <span>School Years</span>
    </a>

    <div class="nav-section-label">REPORTS &amp; ANALYTICS</div>
    <a class="nav-item <?= sidebarActive('modules/analytics') ?>"
       href="<?= BASE_URL ?>modules/analytics/index.php">
      <i class="fa fa-chart-bar"></i>
      <span>Analytics</span>
    </a>
    <a class="nav-item <?= sidebarActive('modules/reports') ?>"
       href="<?= BASE_URL ?>modules/reports/index.php">
      <i class="fa fa-file-lines"></i>
      <span>Reports Center</span>
    </a>

    <div class="nav-section-label">SYSTEM</div>
    <a class="nav-item <?= sidebarActive('modules/shifts') ?>"
       href="<?= BASE_URL ?>modules/shifts/index.php">
      <i class="fa fa-business-time"></i>
      <span>Shifts</span>
    </a>
    <a class="nav-item <?= sidebarActive('modules/holidays') ?>"
       href="<?= BASE_URL ?>modules/holidays/index.php">
      <i class="fa fa-calendar-check"></i>
      <span>Holidays</span>
    </a>
    <a class="nav-item <?= sidebarActive('modules/audit') ?>"
       href="<?= BASE_URL ?>modules/audit/index.php">
      <i class="fa fa-shield-halved"></i>
      <span>Audit Logs</span>
    </a>
    <a class="nav-item <?= sidebarActive('modules/settings') ?>"
       href="<?= BASE_URL ?>modules/settings/index.php">
      <i class="fa fa-gear"></i>
      <span>Settings</span>
    </a>

  </div><!-- .sidebar-nav -->

  <!-- User / Logout footer -->
  <div class="sidebar-footer">
    <div class="sidebar-user">
      <div class="sidebar-user-avatar"><?= $_sidebarInit ?></div>
      <div class="sidebar-user-info">
        <strong><?= $_sidebarFirst ?></strong>
        <span><?= $_sidebarRole ?></span>
      </div>
    </div>
    <a class="nav-item nav-item--logout" href="<?= BASE_URL ?>actions/logout.php">
      <i class="fa fa-right-from-bracket"></i>
      <span>Logout</span>
    </a>
  </div>

</nav>

<script>
(function () {
    function initSidebar() {
        const sidebar  = document.getElementById('sidebar');
        const btn      = document.getElementById('sidebarToggle');
        const chevron  = document.getElementById('sidebarChevron');
        if (!sidebar || !btn) return;

        function applyState(collapsed) {
            sidebar.classList.toggle('collapsed', collapsed);
            if (chevron) {
                chevron.className = collapsed ? 'fa fa-chevron-right' : 'fa fa-chevron-left';
            }
        }

        applyState(localStorage.getItem('sidebarCollapsed') === '1');

        btn.addEventListener('click', function () {
            const next = !sidebar.classList.contains('collapsed');
            applyState(next);
            localStorage.setItem('sidebarCollapsed', next ? '1' : '0');
        });
    }

    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', initSidebar);
    } else {
        initSidebar();
    }
})();
</script>