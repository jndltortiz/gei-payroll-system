<?php
/**
 * includes/sidebar.php
 * Unified sidebar navigation.
 * Active state auto-detected from REQUEST_URI.
 */
function sidebarActive(string $path): string {
    $uri = $_SERVER['REQUEST_URI'] ?? '';
    return str_contains($uri, $path) ? 'active' : '';
}
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

  <!-- Toggle button -->
  <button class="sidebar-toggle-btn" id="sidebarToggle" title="Collapse sidebar">
    <i class="fa fa-bars"></i>
  </button>

  <div class="sidebar-nav">

    <div class="nav-section-label">OVERVIEW</div>
    <a class="nav-item <?= sidebarActive('modules/dashboard') ?>"
       href="<?= BASE_URL ?>modules/dashboard/index.php">
      <i class="fa fa-house"></i>
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
      <i class="fa fa-wallet"></i>
      <span>Payroll</span>
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
    <a class="nav-item <?= sidebarActive('modules/leave') ?>"
       href="<?= BASE_URL ?>modules/leave/index.php">
      <i class="fa fa-calendar-days"></i>
      <span>Leave Records</span>
    </a>

    <div class="nav-section-label">REPORTS &amp; ANALYTICS</div>
    <a class="nav-item <?= sidebarActive('modules/analytics') ?>"
       href="<?= BASE_URL ?>modules/reports/index.php">
      <i class="fa fa-chart-line"></i>
      <span>Analytics</span>
    </a>
    <a class="nav-item <?= sidebarActive('modules/reports') ?>"
       href="<?= BASE_URL ?>modules/reports/index.php">
      <i class="fa fa-file-lines"></i>
      <span>Reports Center</span>
    </a>

    <div class="nav-section-label">SYSTEM</div>
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

  <!-- User / Logout -->
  <div class="sidebar-footer">
    <div class="sidebar-user">
      <div class="sidebar-user-avatar">
        <?= strtoupper(substr($_SESSION['user']['first_name'] ?? 'A', 0, 1)) ?>
      </div>
      <div class="sidebar-user-info">
        <strong><?= htmlspecialchars($_SESSION['user']['first_name'] ?? 'Admin') ?></strong>
        <span><?= htmlspecialchars($_SESSION['user']['role_name'] ?? '') ?></span>
      </div>
    </div>
    <a class="nav-item nav-item--logout" href="<?= BASE_URL ?>actions/logout.php">
      <i class="fa fa-right-from-bracket"></i>
      <span>Logout</span>
    </a>
  </div>

</nav>

<script>
// Sidebar toggle — runs immediately (no DOMContentLoaded needed, script is at bottom)
(function() {
    function initSidebar() {
        const sidebar  = document.getElementById('sidebar');
        const btn      = document.getElementById('sidebarToggle');
        if (!sidebar || !btn) return;

        // Restore saved state
        if (localStorage.getItem('sidebarCollapsed') === '1') {
            sidebar.classList.add('collapsed');
        }

        btn.addEventListener('click', function () {
            sidebar.classList.toggle('collapsed');
            localStorage.setItem('sidebarCollapsed',
                sidebar.classList.contains('collapsed') ? '1' : '0');
        });
    }

    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', initSidebar);
    } else {
        initSidebar();
    }
})();
</script>