<?php
/**
 * modules/notifications/index.php
 * Full notification list — all roles.
 * Filters: all / unread / read
 * Newest first, paginated (20/page).
 * Click → marks read + navigates to link_url.
 */
require_once __DIR__ . '/../../config/config.php';
require_once __DIR__ . '/../../includes/auth.php';
requireLogin();
blockIfMustChangePassword();

$userId = (int)($_SESSION['user']['user_id']     ?? 0);
$empId  = (int)($_SESSION['user']['employee_id'] ?? 0);

// ── Table guard ───────────────────────────────────────────────────────────────
$hasTable = (bool)$pdo->query("
    SELECT COUNT(*) FROM information_schema.TABLES
    WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'notifications'
")->fetchColumn();

$filter = in_array($_GET['filter'] ?? '', ['all','unread','read']) ? $_GET['filter'] : 'all';
$page   = max(1, (int)($_GET['page'] ?? 1));
$limit  = 20;
$offset = ($page - 1) * $limit;

$notifications = [];
$total         = 0;
$pages         = 1;
$unreadCount   = 0;

if ($hasTable) {
    $where = 'WHERE user_id = ?';
    if ($filter === 'unread') $where .= ' AND is_read = 0';
    if ($filter === 'read')   $where .= ' AND is_read = 1';

    $cntStmt = $pdo->prepare("SELECT COUNT(*) FROM notifications WHERE user_id = ? AND is_read = 0");
    $cntStmt->execute([$userId]);
    $unreadCount = (int)$cntStmt->fetchColumn();

    $totalStmt = $pdo->prepare("SELECT COUNT(*) FROM notifications {$where}");
    $totalStmt->execute([$userId]);
    $total = (int)$totalStmt->fetchColumn();
    $pages = (int)ceil($total / $limit) ?: 1;

    $listStmt = $pdo->prepare("
        SELECT notification_id, title, message, type, link_url, is_read, created_at
        FROM notifications {$where}
        ORDER BY created_at DESC
        LIMIT {$limit} OFFSET {$offset}
    ");
    $listStmt->execute([$userId]);
    $notifications = $listStmt->fetchAll();
}

// ── Page setup ────────────────────────────────────────────────────────────────
$pageTitle = 'Notifications';
$extraCSS  = [];
if (isEmployee()) {
    $extraCSS[] = BASE_URL . 'assets/css/employee-portal.css';
}
require_once __DIR__ . '/../../includes/head.php';

// ── Type metadata ─────────────────────────────────────────────────────────────
$typeIcons = [
    'calendar'       => 'fa-calendar-days',
    'payroll'        => 'fa-file-invoice-dollar',
    'leave'          => 'fa-calendar-minus',
    'loan'           => 'fa-hand-holding-dollar',
    'service_credit' => 'fa-medal',
    'system'         => 'fa-circle-info',
];
$typeColors = [
    'calendar'       => '#d97706',
    'payroll'        => '#4f46e5',
    'leave'          => '#0d9488',
    'loan'           => '#059669',
    'service_credit' => '#7c3aed',
    'system'         => '#3b82f6',
];
$typeLabels = [
    'calendar'       => 'Calendar',
    'payroll'        => 'Payroll',
    'leave'          => 'Leave',
    'loan'           => 'Loan',
    'service_credit' => 'Service Credit',
    'system'         => 'System',
];

function notifTimeAgo(string $dt): string {
    $diff = time() - strtotime($dt);
    if ($diff < 60)    return 'Just now';
    if ($diff < 3600)  return floor($diff / 60) . 'm ago';
    if ($diff < 86400) return floor($diff / 3600) . 'h ago';
    if ($diff < 604800) return floor($diff / 86400) . 'd ago';
    return date('M j, Y', strtotime($dt));
}

function notifBuildUrl(string $filter, int $page): string {
    return '?filter=' . urlencode($filter) . '&page=' . $page;
}
?>
<style>
/* ── notifications/index.php styles (nf- prefix) ─────────────────────────── */
.nf-page { max-width: 820px; margin: 0 auto; }

.nf-header {
    display: flex; align-items: center; justify-content: space-between;
    flex-wrap: wrap; gap: 12px; margin-bottom: 20px;
}
.nf-title { font-size: 20px; font-weight: 700; color: #0f172a; }
.nf-sub   { font-size: 13px; color: #64748b; margin-top: 2px; }

.nf-btn-markall {
    display: inline-flex; align-items: center; gap: 7px;
    padding: 8px 15px; border-radius: 8px;
    background: var(--accent); color: #fff;
    border: none; font-size: 12px; font-weight: 600;
    cursor: pointer; font-family: inherit; transition: background .15s;
    text-decoration: none;
}
.nf-btn-markall:hover { background: #17a085; }
.nf-btn-markall:disabled { opacity: .6; cursor: not-allowed; }

/* Filter tabs */
.nf-tabs { display: flex; gap: 2px; margin-bottom: 18px; }
.nf-tab {
    padding: 8px 18px; border-radius: 8px; font-size: 13px; font-weight: 600;
    color: #64748b; text-decoration: none; transition: all .15s;
    border: 1px solid transparent;
}
.nf-tab:hover { background: #f1f5f9; color: #0f172a; }
.nf-tab.active {
    background: var(--accent-light); color: var(--accent);
    border-color: var(--accent-mid);
}

/* Card / list */
.nf-card {
    background: #fff; border: 1px solid #e2e8f0;
    border-radius: 14px; overflow: hidden;
    box-shadow: 0 1px 4px rgba(0,0,0,.06);
}

.nf-item {
    display: flex; align-items: flex-start; gap: 14px;
    padding: 15px 18px;
    border-bottom: 1px solid #f1f5f9;
    text-decoration: none; color: inherit;
    transition: background .12s; cursor: pointer;
    position: relative;
}
.nf-item:last-child { border-bottom: none; }
.nf-item:hover { background: #f8fafc; }
.nf-item.nf-unread { background: #f0fdf9; }
.nf-item.nf-unread:hover { background: #e6f7f4; }

.nf-item-icon {
    width: 42px; height: 42px; border-radius: 11px;
    display: flex; align-items: center; justify-content: center;
    font-size: 17px; flex-shrink: 0; margin-top: 2px;
}
.nf-item-body { flex: 1; min-width: 0; }
.nf-item-title {
    font-size: 14px; font-weight: 600; color: #0f172a;
    line-height: 1.35; margin-bottom: 3px;
}
.nf-item-msg {
    font-size: 12px; color: #64748b; line-height: 1.5;
    margin-bottom: 6px;
}
.nf-item-meta {
    display: flex; align-items: center; gap: 10px; flex-wrap: wrap;
}
.nf-item-type {
    display: inline-flex; align-items: center; gap: 4px;
    font-size: 10px; font-weight: 700; letter-spacing: .3px;
    padding: 2px 8px; border-radius: 99px; text-transform: uppercase;
}
.nf-item-time { font-size: 11px; color: #94a3b8; }
.nf-unread-dot {
    width: 8px; height: 8px; border-radius: 50%;
    background: var(--accent); flex-shrink: 0; margin-top: 18px;
}

/* Empty state */
.nf-empty {
    text-align: center; padding: 60px 24px;
    color: #94a3b8;
}
.nf-empty i { font-size: 36px; margin-bottom: 14px; display: block; }
.nf-empty p { font-size: 14px; font-weight: 600; color: #64748b; }
.nf-empty small { font-size: 12px; color: #94a3b8; display: block; margin-top: 4px; }

/* Pagination */
.nf-pagination {
    display: flex; align-items: center; justify-content: center;
    gap: 6px; margin-top: 20px; flex-wrap: wrap;
}
.nf-page-btn {
    padding: 6px 13px; border-radius: 7px; font-size: 13px; font-weight: 600;
    text-decoration: none; color: #374151;
    border: 1px solid #e2e8f0; background: #fff; transition: all .15s;
}
.nf-page-btn:hover { background: #f1f5f9; }
.nf-page-btn.active {
    background: var(--accent); color: #fff; border-color: var(--accent);
}
.nf-page-btn.disabled { opacity: .4; pointer-events: none; }
</style>
<body>
<div class="layout">

<?php
if (isAdmin()):
    include __DIR__ . '/../../includes/sidebar.php';
elseif (isPrincipalRole()):
    include __DIR__ . '/../../includes/principal-sidebar.php';
else:
    include __DIR__ . '/../../includes/employee-sidebar.php';
endif;
?>

<div class="main">

<?php
if (isEmployee()) {
    $empPortalIcon = 'fa-bell';
    include __DIR__ . '/../../includes/employee-header.php';
} else {
    include __DIR__ . '/../../includes/header.php';
}
?>

  <div class="main-content">
  <div class="<?= isEmployee() ? 'emp-page nf-page' : 'nf-page' ?>">

    <!-- ── Page header ──────────────────────────────────────────────────────── -->
    <div class="nf-header">
      <div>
        <div class="nf-title"><i class="fa fa-bell" style="color:var(--accent);margin-right:8px;"></i>Notifications</div>
        <div class="nf-sub">
          <?= $unreadCount > 0
              ? "{$unreadCount} unread notification" . ($unreadCount > 1 ? 's' : '')
              : 'All caught up' ?>
        </div>
      </div>
      <?php if ($hasTable && $unreadCount > 0): ?>
        <button class="nf-btn-markall" id="nfMarkAllBtn">
          <i class="fa fa-check-double"></i> Mark all read
        </button>
      <?php endif; ?>
    </div>

    <?php if (!$hasTable): ?>
      <!-- Migration not run -->
      <div class="nf-card">
        <div class="nf-empty">
          <i class="fa fa-circle-info" style="color:#3b82f6;"></i>
          <p>Notifications are not yet enabled</p>
          <small>Ask your system administrator to run migration <strong>027_notifications.sql</strong>.</small>
        </div>
      </div>
    <?php else: ?>

    <!-- ── Filter tabs ───────────────────────────────────────────────────────── -->
    <div class="nf-tabs">
      <a class="nf-tab <?= $filter === 'all'    ? 'active' : '' ?>"
         href="<?= notifBuildUrl('all', 1) ?>">All <span style="opacity:.6;">(<?= $total ?>)</span></a>
      <a class="nf-tab <?= $filter === 'unread' ? 'active' : '' ?>"
         href="<?= notifBuildUrl('unread', 1) ?>">Unread <?= $unreadCount > 0 ? "<span style='opacity:.6;'>({$unreadCount})</span>" : '' ?></a>
      <a class="nf-tab <?= $filter === 'read'   ? 'active' : '' ?>"
         href="<?= notifBuildUrl('read', 1) ?>">Read</a>
    </div>

    <!-- ── Notification list ─────────────────────────────────────────────────── -->
    <div class="nf-card">
      <?php if (empty($notifications)): ?>
        <div class="nf-empty">
          <i class="fa fa-bell-slash"></i>
          <p>No notifications here</p>
          <small><?= $filter === 'unread' ? 'You have no unread notifications.' : 'Nothing to show for this filter.' ?></small>
        </div>
      <?php else: ?>
        <?php foreach ($notifications as $n): ?>
          <?php
            $type    = $n['type'];
            $icon    = $typeIcons[$type]   ?? 'fa-bell';
            $color   = $typeColors[$type]  ?? '#64748b';
            $label   = $typeLabels[$type]  ?? ucfirst($type);
            $bgRgba  = $color . '22';
            $unread  = !(int)$n['is_read'];
            $href    = $n['link_url'] ?: '#';
          ?>
          <a class="nf-item <?= $unread ? 'nf-unread' : '' ?>"
             href="<?= htmlspecialchars($href) ?>"
             onclick="nfMarkRead(event, <?= (int)$n['notification_id'] ?>, '<?= htmlspecialchars(addslashes($href)) ?>')">

            <div class="nf-item-icon" style="background:<?= $bgRgba ?>;color:<?= $color ?>">
              <i class="fa <?= $icon ?>"></i>
            </div>

            <div class="nf-item-body">
              <div class="nf-item-title"><?= htmlspecialchars($n['title']) ?></div>
              <?php if ($n['message']): ?>
                <div class="nf-item-msg"><?= htmlspecialchars($n['message']) ?></div>
              <?php endif; ?>
              <div class="nf-item-meta">
                <span class="nf-item-type"
                      style="background:<?= $bgRgba ?>;color:<?= $color ?>">
                  <i class="fa <?= $icon ?>"></i> <?= htmlspecialchars($label) ?>
                </span>
                <span class="nf-item-time"><?= notifTimeAgo($n['created_at']) ?></span>
              </div>
            </div>

            <?php if ($unread): ?>
              <div class="nf-unread-dot"></div>
            <?php endif; ?>
          </a>
        <?php endforeach; ?>
      <?php endif; ?>
    </div>

    <!-- ── Pagination ────────────────────────────────────────────────────────── -->
    <?php if ($pages > 1): ?>
      <div class="nf-pagination">
        <a class="nf-page-btn <?= $page <= 1 ? 'disabled' : '' ?>"
           href="<?= notifBuildUrl($filter, $page - 1) ?>">← Prev</a>
        <?php for ($p = 1; $p <= $pages; $p++): ?>
          <a class="nf-page-btn <?= $p === $page ? 'active' : '' ?>"
             href="<?= notifBuildUrl($filter, $p) ?>"><?= $p ?></a>
        <?php endfor; ?>
        <a class="nf-page-btn <?= $page >= $pages ? 'disabled' : '' ?>"
           href="<?= notifBuildUrl($filter, $page + 1) ?>">Next →</a>
      </div>
    <?php endif; ?>

    <?php endif; // hasTable ?>

  </div><!-- .nf-page -->
  </div><!-- .main-content -->
</div><!-- .main -->
</div><!-- .layout -->

<script>
function nfMarkRead(e, notifId, href) {
    e.preventDefault();
    fetch(window.BASE_URL + 'actions/notification-action.php', {
        method: 'POST',
        headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
        body: 'action=mark_read&notification_id=' + notifId
    }).finally(function () {
        if (href && href !== '#') {
            window.location.href = href;
        } else {
            window.location.reload();
        }
    });
}

<?php if ($hasTable && $unreadCount > 0): ?>
var nfMarkAllBtn = document.getElementById('nfMarkAllBtn');
if (nfMarkAllBtn) {
    nfMarkAllBtn.addEventListener('click', function () {
        nfMarkAllBtn.disabled = true;
        nfMarkAllBtn.innerHTML = '<i class="fa fa-circle-notch fa-spin"></i> Marking…';
        fetch(window.BASE_URL + 'actions/notification-action.php', {
            method: 'POST',
            headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
            body: 'action=mark_all_read'
        }).then(function () { window.location.reload(); });
    });
}
<?php endif; ?>
</script>

<?php require_once __DIR__ . '/../../includes/footer.php'; ?>
