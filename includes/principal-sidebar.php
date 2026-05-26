<?php
/**
 * includes/principal-sidebar.php
 * Sidebar navigation for the Principal Portal.
 * Active state auto-detected from REQUEST_URI.
 */
function principalActive(string $path): string {
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
      <strong>Principal Portal</strong>
      <span>Great Eastern Institute</span>
    </div>
  </div>

  <!-- Toggle button -->
  <button class="sidebar-toggle-btn" id="sidebarToggle" title="Collapse sidebar">
    <i class="fa fa-bars"></i>
  </button>

  <div class="sidebar-nav">

    <div class="nav-section-label">OVERVIEW</div>
    <a class="nav-item <?= principalActive('principal/dashboard') ?>"
       href="<?= BASE_URL ?>modules/principal/dashboard/index.php">
      <i class="fa fa-house"></i>
      <span>Dashboard</span>
    </a>

        <div class="nav-section-label">WORKFORCE</div>
    <a class="nav-item <?= principalActive('principal/employees') ?>"
       href="<?= BASE_URL ?>modules/principal/employees/index.php">
      <i class="fa fa-users"></i>
      <span>Employees</span>
    </a>
    <a class="nav-item <?= principalActive('principal/attendance') ?>"
       href="<?= BASE_URL ?>modules/principal/attendance/index.php">
      <i class="fa fa-user-check"></i>
      <span>Attendance</span>
    </a>

    <div class="nav-section-label">PAYROLL</div>
    <a class="nav-item <?= principalActive('principal/payroll-approval') ?>"
       href="<?= BASE_URL ?>modules/principal/payroll-approval/index.php">
      <i class="fa fa-wallet"></i>
      <span>Payroll Approvals</span>
    </a>
    <a class="nav-item <?= principalActive('payroll/archive') ?>"
       href="<?= BASE_URL ?>modules/payroll/archive.php">
      <i class="fa fa-box-archive"></i>
      <span>Payroll Archive</span>
    </a>
    <a class="nav-item <?= principalActive('principal/loan-approval') ?>"
       href="<?= BASE_URL ?>modules/principal/loan-approval/index.php">
      <i class="fa fa-hand-holding-dollar"></i>
      <span>Loan Approval</span>
    </a>
    <a class="nav-item <?= principalActive('principal/service-credit-approval') ?>"
       href="<?= BASE_URL ?>modules/principal/service-credit-approval/index.php">
      <i class="fa fa-hands-helping"></i>
      <span>Service Credit Approval</span>
    </a>

    <div class="nav-section-label">LEAVE</div>
    <a class="nav-item <?= principalActive('principal/leave-approval') ?>"
       href="<?= BASE_URL ?>modules/principal/leave-approval/index.php">
      <i class="fa fa-calendar-check"></i>
      <span>Leave Requests</span>
    </a>


    <div class="nav-section-label">REPORTS &amp; ANALYTICS</div>
    <a class="nav-item <?= principalActive('principal/analytics') ?>"
       href="<?= BASE_URL ?>modules/principal/analytics/index.php">
      <i class="fa fa-chart-bar"></i>
      <span>Analytics</span>
    </a>
    <a class="nav-item <?= principalActive('principal/reports') ?>"
       href="<?= BASE_URL ?>modules/principal/reports/index.php">
      <i class="fa fa-file-lines"></i>
      <span>Reports Center</span>
    </a>

    <div class="nav-section-label">SYSTEM</div>
    <a class="nav-item <?= principalActive('modules/settings') ?>"
       href="<?= BASE_URL ?>modules/settings/index.php">
      <i class="fa fa-gear"></i>
      <span>Settings</span>
    </a>

  </div><!-- .sidebar-nav -->

  <!-- User / Logout -->
  <div class="sidebar-footer">
    <div class="sidebar-user">
      <div class="sidebar-user-avatar">
        <?= strtoupper(substr($_SESSION['user']['first_name'] ?? 'P', 0, 1)) ?>
      </div>
      <div class="sidebar-user-info">
        <strong><?= htmlspecialchars(($_SESSION['user']['first_name'] ?? '') . ' ' . ($_SESSION['user']['last_name'] ?? '')) ?></strong>
        <span><?= htmlspecialchars($_SESSION['user']['role_name'] ?? 'Principal') ?></span>
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
        if (localStorage.getItem('principalSidebarCollapsed') === '1') {
            sidebar.classList.add('collapsed');
        }
        btn.addEventListener('click', function () {
            sidebar.classList.toggle('collapsed');
            localStorage.setItem('principalSidebarCollapsed',
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