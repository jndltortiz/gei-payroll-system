<?php
/**
 * includes/principal-sidebar.php
 * Sidebar navigation for the Principal Portal.
 * Active state auto-detected from REQUEST_URI.
 *
 * Ensures auth.php is loaded so hasEmployeeAccess() and role helpers
 * are always available, even on pages that only require config.php.
 */
require_once __DIR__ . '/auth.php';
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
    <i class="fa fa-chevron-left" id="sidebarChevron"></i>
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
      <i class="fa fa-handshake-angle"></i>
      <span>Service Credit Approval</span>
    </a>

    <div class="nav-section-label">LEAVE</div>
    <a class="nav-item <?= principalActive('principal/leave-approval') ?>"
       href="<?= BASE_URL ?>modules/principal/leave-approval/index.php">
      <i class="fa fa-calendar-check"></i>
      <span>Leave Requests</span>
    </a>


    <div class="nav-section-label">ANALYTICS</div>
    <a class="nav-item <?= principalActive('principal/analytics') ?>"
       href="<?= BASE_URL ?>modules/principal/analytics/index.php">
      <i class="fa fa-chart-bar"></i>
      <span>Analytics</span>
    </a>

    <div class="nav-section-label">SYSTEM</div>
    <a class="nav-item <?= principalActive('modules/notifications') ?>"
       href="<?= BASE_URL ?>modules/notifications/index.php">
      <i class="fa fa-bell"></i>
      <span>Notifications</span>
    </a>

    <?php if (hasEmployeeAccess()): ?>
    <div class="nav-section-label">MY ACCOUNT</div>
    <a class="nav-item <?= principalActive('employee/attendance') ?>"
       href="<?= BASE_URL ?>modules/employee/attendance/index.php">
      <i class="fa fa-user-clock"></i>
      <span>My Attendance</span>
    </a>
    <a class="nav-item <?= principalActive('employee/leave') ?>"
       href="<?= BASE_URL ?>modules/employee/leave/index.php">
      <i class="fa fa-calendar-days"></i>
      <span>My Leave</span>
    </a>
    <a class="nav-item <?= principalActive('employee/payslips') ?>"
       href="<?= BASE_URL ?>modules/employee/payslips/index.php">
      <i class="fa fa-file-invoice-dollar"></i>
      <span>My Payslips</span>
    </a>
    <a class="nav-item <?= principalActive('employee/loans') ?>"
       href="<?= BASE_URL ?>modules/employee/loans/index.php">
      <i class="fa fa-hand-holding-dollar"></i>
      <span>My Loans</span>
    </a>
    <a class="nav-item <?= principalActive('employee/service-credits') ?>"
       href="<?= BASE_URL ?>modules/employee/service-credits/index.php">
      <i class="fa fa-medal"></i>
      <span>My Service Credits</span>
    </a>
    <a class="nav-item <?= principalActive('employee/profile') ?>"
       href="<?= BASE_URL ?>modules/employee/profile/index.php">
      <i class="fa fa-id-badge"></i>
      <span>My Profile</span>
    </a>
    <a class="nav-item <?= principalActive('employee/calendar') ?>"
       href="<?= BASE_URL ?>modules/employee/calendar/index.php">
      <i class="fa fa-calendar-check"></i>
      <span>My Calendar</span>
    </a>
    <?php endif; ?>

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

        applyState(localStorage.getItem('principalSidebarCollapsed') === '1');

        btn.addEventListener('click', function () {
            const next = !sidebar.classList.contains('collapsed');
            applyState(next);
            localStorage.setItem('principalSidebarCollapsed', next ? '1' : '0');
        });
    }
    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', initSidebar);
    } else {
        initSidebar();
    }
})();
</script>