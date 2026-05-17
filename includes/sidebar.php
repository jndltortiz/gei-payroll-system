<aside class="sidebar" id="sidebar">
  <div class="sidebar-logo">
    <div class="logo-box">GEI</div>
    <div class="logo-text">
      <strong>GEI HR System</strong>
      <span>Great Eastern Institute</span>
    </div>
  </div>
  
  <nav class="sidebar-nav">
    <div class="nav-section-label">Overview</div>
    <a class="nav-item active" href="<?= BASE_URL ?>modules/dashboard/index.php">
      <i class="fa fa-home"></i>
      <span>Dashboard</span>
    </a>

    <div class="nav-section-label">Workforce</div>
    <a class="nav-item" href="<?= BASE_URL ?>modules/attendance/index.php">
      <i class="fa fa-clock"></i>
      <span>Attendance</span>
    </a>
    <a class="nav-item" href="<?= BASE_URL ?>modules/employees/index.php">
      <i class="fa fa-users"></i>
      <span>Employees</span>
    </a>

    <div class="nav-section-label">Payroll</div>
    <a class="nav-item" href="<?= BASE_URL ?>modules/payroll/index.php">
      <i class="fa fa-money-bill"></i>
      <span>Payroll</span>
    </a>
    <a class="nav-item" href="<?= BASE_URL ?>modules/payroll-settings/index.php">
      <i class="fa fa-sliders-h"></i>
      <span>Payroll Settings</span>
    </a>

    <div class="nav-section-label">Leave</div>
    <a class="nav-item" href="<?= BASE_URL ?>modules/leave/index.php">
      <i class="fa fa-calendar-check"></i>
      <span>Leave Records</span>
    </a>

    <div class="nav-section-label">Reports & Analytics</div>
    <a class="nav-item" href="<?= BASE_URL ?>modules/reports/index.php">
      <i class="fa fa-chart-line"></i>
      <span>Analytics</span>
    </a>
    <a class="nav-item" href="<?= BASE_URL ?>modules/reports/index.php">
      <i class="fa fa-file-alt"></i>
      <span>Reports Center</span>
    </a>

    <div class="nav-section-label">System</div>
    <a class="nav-item" href="<?= BASE_URL ?>modules/audit/index.php">
      <i class="fa fa-clipboard-list"></i>
      <span>Audit Logs</span>
    </a>
    <a class="nav-item" href="<?= BASE_URL ?>modules/settings/index.php">
    <i class="fa fa-cog"></i>
    <span>Settings</span>
  </a>
  </nav>
<div class="sidebar-footer">

        <div class="user-chip">
            <div class="avatar">
                <?= strtoupper(substr($_SESSION['user']['first_name'],0,1)) ?>
                <?= strtoupper(substr($_SESSION['user']['last_name'],0,1)) ?>
            </div>

            <div class="user-chip-text">
                <strong>
                    <?= $_SESSION['user']['first_name'] . ' ' . $_SESSION['user']['last_name']; ?>
                </strong>
                <span><?= $_SESSION['user']['role_name']; ?></span>
            </div>
        </div>

        <a class="nav-item logout" href="http://localhost/gei-payroll-system/logout.php">
            <i class="fa fa-sign-out-alt"></i>
            <span>Logout</span>
        </a>

    </div>
</aside>