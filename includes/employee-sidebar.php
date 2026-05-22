<?php
/**
 * includes/employee-sidebar.php
 * Sidebar navigation for the Employee Self-Service Portal.
 * Active state auto-detected from REQUEST_URI.
 */
function employeeActive(string $path): string {
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
      <strong>Employee Portal</strong>
      <span>Great Eastern Institute</span>
    </div>
  </div>

  <!-- Toggle button -->
  <button class="sidebar-toggle-btn" id="sidebarToggle" title="Collapse sidebar">
    <i class="fa fa-bars"></i>
  </button>

  <div class="sidebar-nav">

    <div class="nav-section-label">OVERVIEW</div>
    <a class="nav-item <?= employeeActive('employee/dashboard') ?>"
       href="<?= BASE_URL ?>modules/employee/dashboard/index.php">
      <i class="fa fa-house"></i>
      <span>Dashboard</span>
    </a>

    <div class="nav-section-label">MY PAYROLL</div>
    <a class="nav-item <?= employeeActive('employee/payslips') ?>"
       href="<?= BASE_URL ?>modules/employee/payslips/index.php">
      <i class="fa fa-file-invoice-dollar"></i>
      <span>My Payslips</span>
    </a>
    <a class="nav-item <?= employeeActive('employee/loans') ?>"
       href="<?= BASE_URL ?>modules/employee/loans/index.php">
      <i class="fa fa-hand-holding-dollar"></i>
      <span>My Loans</span>
    </a>
    <a class="nav-item <?= employeeActive('employee/service-credits') ?>"
       href="<?= BASE_URL ?>modules/employee/service-credits/index.php">
      <i class="fa fa-medal"></i>
      <span>My Service Credits</span>
    </a>

    <div class="nav-section-label">MY LEAVE</div>
    <a class="nav-item <?= employeeActive('employee/leave') ?>"
       href="<?= BASE_URL ?>modules/employee/leave/index.php">
      <i class="fa fa-calendar-days"></i>
      <span>Leave Balance &amp; History</span>
    </a>

    <div class="nav-section-label">MY ATTENDANCE</div>
    <a class="nav-item <?= employeeActive('employee/attendance') ?>"
       href="<?= BASE_URL ?>modules/employee/attendance/index.php">
      <i class="fa fa-user-clock"></i>
      <span>Attendance Records</span>
    </a>

  </div><!-- .sidebar-nav -->

  <!-- User / Logout -->
  <div class="sidebar-footer">
    <div class="sidebar-user">
      <div class="sidebar-user-avatar">
        <?= strtoupper(substr($_SESSION['user']['first_name'] ?? 'E', 0, 1)) ?>
      </div>
      <div class="sidebar-user-info">
        <strong><?= htmlspecialchars(($_SESSION['user']['first_name'] ?? '') . ' ' . ($_SESSION['user']['last_name'] ?? '')) ?></strong>
        <span>Employee</span>
      </div>
    </div>
    <a class="nav-item nav-item--logout" href="<?= BASE_URL ?>actions/logout.php">
      <i class="fa fa-right-from-bracket"></i>
      <span>Logout</span>
    </a>
  </div>

</nav>

<script>
(function() {
    function initSidebar() {
        const sidebar = document.getElementById('sidebar');
        const btn     = document.getElementById('sidebarToggle');
        if (!sidebar || !btn) return;
        if (localStorage.getItem('employeeSidebarCollapsed') === '1') {
            sidebar.classList.add('collapsed');
        }
        btn.addEventListener('click', function () {
            sidebar.classList.toggle('collapsed');
            localStorage.setItem('employeeSidebarCollapsed',
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
