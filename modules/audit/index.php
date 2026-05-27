<?php
/**
 * modules/audit/index.php
 * Admin-only audit log viewer — filters, pagination, detail toggle, CSV export.
 */
require_once __DIR__ . '/../../includes/auth.php';
requireAdminPage();

// ── Filters ────────────────────────────────────────────────────────────────
$dateFrom  = trim($_GET['date_from']   ?? '');
$dateTo    = trim($_GET['date_to']     ?? '');
$filterAct = trim($_GET['action_type'] ?? '');
$filterTbl = trim($_GET['table_name']  ?? '');
$search    = trim($_GET['search']      ?? '');
$page      = max(1, (int)($_GET['page'] ?? 1));
$perPage   = 25;

// ── WHERE builder ──────────────────────────────────────────────────────────
$joinSQL = "FROM audit_logs al
    LEFT JOIN users u ON u.user_id = al.user_id
    LEFT JOIN employees e ON e.employee_id = u.employee_id";

$where  = [];
$params = [];

if ($dateFrom !== '') { $where[] = 'al.created_at >= ?'; $params[] = $dateFrom . ' 00:00:00'; }
if ($dateTo   !== '') { $where[] = 'al.created_at <= ?'; $params[] = $dateTo   . ' 23:59:59'; }
if ($filterAct !== '') { $where[] = 'al.action = ?';     $params[] = $filterAct; }
if ($filterTbl !== '') { $where[] = 'al.table_name = ?'; $params[] = $filterTbl; }
if ($search !== '') {
    $like = "%{$search}%";
    $where[] = "(CONCAT(e.first_name,' ',e.last_name) LIKE ? OR u.username LIKE ? OR al.description LIKE ?)";
    array_push($params, $like, $like, $like);
}

$whereSQL = $where ? ('WHERE ' . implode(' AND ', $where)) : '';

// ── CSV export (must run before any HTML output) ───────────────────────────
if (($_GET['export'] ?? '') === 'csv') {
    header('Content-Type: text/csv; charset=utf-8');
    header('Content-Disposition: attachment; filename="audit_log_' . date('Y-m-d_His') . '.csv"');
    $out  = fopen('php://output', 'w');
    fputcsv($out, ['ID', 'Timestamp', 'User', 'Action', 'Module / Table', 'Record ID', 'Description']);
    $stmt = $pdo->prepare("
        SELECT al.log_id, al.created_at,
               TRIM(CONCAT(COALESCE(e.first_name,''),' ',COALESCE(e.last_name,''))) AS actor,
               u.username, al.user_id,
               al.action, al.table_name, al.record_id, al.description
        $joinSQL $whereSQL ORDER BY al.created_at DESC
    ");
    $stmt->execute($params);
    while ($r = $stmt->fetch()) {
        $actor = trim($r['actor']) ?: ($r['username'] ?? "User #{$r['user_id']}");
        fputcsv($out, [
            $r['log_id'], $r['created_at'], $actor,
            $r['action'], $r['table_name'], $r['record_id'], $r['description'],
        ]);
    }
    fclose($out);
    exit;
}

// ── Count & paginate ───────────────────────────────────────────────────────
$cntStmt = $pdo->prepare("SELECT COUNT(*) $joinSQL $whereSQL");
$cntStmt->execute($params);
$total  = (int)$cntStmt->fetchColumn();
$pages  = max(1, (int)ceil($total / $perPage));
$page   = min($page, $pages);
$offset = ($page - 1) * $perPage;

$dataStmt = $pdo->prepare("
    SELECT al.log_id, al.user_id,
           al.action, al.table_name, al.record_id, al.description, al.created_at,
           TRIM(CONCAT(COALESCE(e.first_name,''),' ',COALESCE(e.last_name,''))) AS actor_name,
           u.username
    $joinSQL $whereSQL
    ORDER BY al.created_at DESC
    LIMIT {$perPage} OFFSET {$offset}
");
$dataStmt->execute($params);
$logs = $dataStmt->fetchAll();

$hasFilters = ($dateFrom !== '' || $dateTo !== '' || $filterAct !== '' || $filterTbl !== '' || $search !== '');

// ── Dropdown options ───────────────────────────────────────────────────────
$actionOpts = $pdo->query(
    "SELECT DISTINCT action FROM audit_logs WHERE action IS NOT NULL ORDER BY action"
)->fetchAll(PDO::FETCH_COLUMN);

$tableOpts = $pdo->query(
    "SELECT DISTINCT table_name FROM audit_logs WHERE table_name IS NOT NULL ORDER BY table_name"
)->fetchAll(PDO::FETCH_COLUMN);

// ── Helpers ────────────────────────────────────────────────────────────────
function auditBadgeClass(string $action): string
{
    $a = strtoupper($action);
    // Destructive first so BULK_DELETE doesn't match BULK (green)
    if (str_contains($a,'DELETE') || str_contains($a,'REMOV') ||
        str_contains($a,'CANCEL') || str_contains($a,'REJECT') || str_contains($a,'REVOK')) {
        return 'aud-badge--red';
    }
    if (str_contains($a,'LOGOUT')) return 'aud-badge--muted';
    if (str_contains($a,'LOGIN'))  return 'aud-badge--blue';
    if (str_contains($a,'CREATE') || str_contains($a,'INSERT') || str_contains($a,'IMPORT') ||
        str_contains($a,'GENERAT') || str_contains($a,'BULK')  || str_contains($a,'ADD')) {
        return 'aud-badge--green';
    }
    // UPDATE, EDIT, APPROVE, VERIFY, SAVE, TOGGLE, SET_ACTIVE, etc.
    return 'aud-badge--yellow';
}

function audQs(array $overrides = []): string
{
    $keep = ['date_from','date_to','action_type','table_name','search'];
    $base = [];
    foreach ($keep as $k) {
        if (isset($_GET[$k]) && $_GET[$k] !== '') $base[$k] = $_GET[$k];
    }
    return htmlspecialchars(http_build_query(array_merge($base, $overrides)));
}

// ── Page setup ─────────────────────────────────────────────────────────────
$pageTitle = 'Audit Log';
$extraCSS  = [BASE_URL . 'assets/css/audit.css'];
require_once __DIR__ . '/../../includes/head.php';
?>
<body>
<?php include __DIR__ . '/../../includes/sidebar.php'; ?>
<div class="main">
  <?php include __DIR__ . '/../../includes/header.php'; ?>
  <div class="main-content">
    <div class="aud-page">

      <!-- ── Page header ─────────────────────────────────────────────── -->
      <div class="aud-hdr">
        <h1><i class="fa-solid fa-shield-halved"></i> Audit Log</h1>
        <p><?= number_format($total) ?> record<?= $total !== 1 ? 's' : '' ?> found<?= ($dateFrom || $dateTo || $filterAct || $filterTbl || $search) ? ' (filtered)' : '' ?></p>
      </div>

      <!-- ── Filters ────────────────────────────────────────────────── -->
      <form class="aud-filters" method="get" action="">
        <div class="aud-filter-row">

          <label class="aud-label">From
            <input class="aud-input" type="date" name="date_from"
                   value="<?= htmlspecialchars($dateFrom) ?>">
          </label>

          <label class="aud-label">To
            <input class="aud-input" type="date" name="date_to"
                   value="<?= htmlspecialchars($dateTo) ?>">
          </label>

          <label class="aud-label">Action
            <select class="aud-input" name="action_type">
              <option value="">All actions</option>
              <?php foreach ($actionOpts as $a): ?>
                <option value="<?= htmlspecialchars($a) ?>"
                  <?= $filterAct === $a ? 'selected' : '' ?>>
                  <?= htmlspecialchars($a) ?>
                </option>
              <?php endforeach; ?>
            </select>
          </label>

          <label class="aud-label">Module
            <select class="aud-input" name="table_name">
              <option value="">All modules</option>
              <?php foreach ($tableOpts as $t): ?>
                <option value="<?= htmlspecialchars($t) ?>"
                  <?= $filterTbl === $t ? 'selected' : '' ?>>
                  <?= htmlspecialchars($t) ?>
                </option>
              <?php endforeach; ?>
            </select>
          </label>

          <label class="aud-label aud-label--grow">Search
            <input class="aud-input" type="text" name="search"
                   placeholder="User or description…"
                   value="<?= htmlspecialchars($search) ?>">
          </label>

          <div class="aud-filter-btns">
            <button class="aud-btn aud-btn--primary" type="submit">
              <i class="fa-solid fa-magnifying-glass"></i> Filter
            </button>
            <?php if ($hasFilters): ?>
            <a class="aud-btn aud-btn--ghost" href="?">
              <i class="fa-solid fa-xmark"></i> Reset
            </a>
            <?php endif; ?>
            <a class="aud-btn aud-btn--export"
               href="?<?= audQs(['export' => 'csv']) ?>">
              <i class="fa-solid fa-download"></i> CSV
            </a>
          </div>

        </div>
      </form>

      <!-- ── Table ──────────────────────────────────────────────────── -->
      <div class="aud-card">
        <?php if (empty($logs)): ?>
          <div class="aud-empty">
            <i class="fa-solid fa-magnifying-glass"></i>
            <p>No audit records match the selected filters.</p>
          </div>
        <?php else: ?>
          <table class="aud-table">
            <thead>
              <tr>
                <th style="width:56px">#</th>
                <th style="width:150px">Timestamp</th>
                <th style="width:160px">User</th>
                <th style="width:130px">Action</th>
                <th>Module / Table</th>
                <th style="width:72px">Rec&nbsp;ID</th>
                <th>Description</th>
              </tr>
            </thead>
            <tbody>
              <?php foreach ($logs as $log):
                $actor      = trim($log['actor_name']) ?: ($log['username'] ?? "User #{$log['user_id']}");
                $badgeExtra = auditBadgeClass($log['action']);
              ?>
              <tr class="aud-row"
                  data-id="<?= (int)$log['log_id'] ?>"
                  title="Click to expand details"
                  tabindex="0">
                <td class="aud-cell-muted"><?= (int)$log['log_id'] ?></td>
                <td class="aud-cell-mono">
                  <?= date('M j, Y', strtotime($log['created_at'])) ?><br>
                  <span style="color:var(--text-muted)"><?= date('H:i:s', strtotime($log['created_at'])) ?></span>
                </td>
                <td><?= htmlspecialchars($actor) ?></td>
                <td>
                  <span class="aud-badge <?= $badgeExtra ?>">
                    <?= htmlspecialchars($log['action']) ?>
                  </span>
                </td>
                <td class="aud-cell-mono"><?= htmlspecialchars($log['table_name'] ?? '—') ?></td>
                <td class="aud-cell-muted"><?= $log['record_id'] !== null ? (int)$log['record_id'] : '—' ?></td>
                <td class="aud-desc" title="<?= htmlspecialchars($log['description'] ?? '') ?>">
                  <?= htmlspecialchars($log['description'] ?? '') ?>
                </td>
              </tr>
              <tr class="aud-detail" id="aud-detail-<?= (int)$log['log_id'] ?>">
                <td colspan="7">
                  <div class="aud-detail-inner">
                    <dl class="aud-dl">
                      <dt>Log ID</dt>
                      <dd><?= (int)$log['log_id'] ?></dd>

                      <dt>Timestamp</dt>
                      <dd><?= htmlspecialchars($log['created_at']) ?></dd>

                      <dt>User</dt>
                      <dd><?= htmlspecialchars($actor) ?></dd>

                      <dt>Action</dt>
                      <dd><span class="aud-badge <?= $badgeExtra ?>"><?= htmlspecialchars($log['action']) ?></span></dd>

                      <dt>Module</dt>
                      <dd><?= htmlspecialchars($log['table_name'] ?? '—') ?></dd>

                      <dt>Record ID</dt>
                      <dd><?= $log['record_id'] !== null ? (int)$log['record_id'] : '—' ?></dd>

                      <dt>Description</dt>
                      <dd><?= htmlspecialchars($log['description'] ?? '') ?></dd>
                    </dl>
                  </div>
                </td>
              </tr>
              <?php endforeach; ?>
            </tbody>
          </table>
        <?php endif; ?>
      </div>

      <!-- ── Pagination ─────────────────────────────────────────────── -->
      <?php if ($pages > 1): ?>
      <div class="aud-pager">

        <?php if ($page > 1): ?>
          <a class="aud-page-btn" href="?<?= audQs(['page' => $page - 1]) ?>">
            <i class="fa-solid fa-chevron-left"></i>
          </a>
        <?php endif; ?>

        <?php
          $rangeStart = max(1, $page - 2);
          $rangeEnd   = min($pages, $page + 2);
        ?>

        <?php if ($rangeStart > 1): ?>
          <a class="aud-page-btn" href="?<?= audQs(['page' => 1]) ?>">1</a>
          <?php if ($rangeStart > 2): ?><span class="aud-page-ellipsis">…</span><?php endif; ?>
        <?php endif; ?>

        <?php for ($i = $rangeStart; $i <= $rangeEnd; $i++): ?>
          <a class="aud-page-btn <?= $i === $page ? 'active' : '' ?>"
             href="?<?= audQs(['page' => $i]) ?>"><?= $i ?></a>
        <?php endfor; ?>

        <?php if ($rangeEnd < $pages): ?>
          <?php if ($rangeEnd < $pages - 1): ?><span class="aud-page-ellipsis">…</span><?php endif; ?>
          <a class="aud-page-btn" href="?<?= audQs(['page' => $pages]) ?>"><?= $pages ?></a>
        <?php endif; ?>

        <?php if ($page < $pages): ?>
          <a class="aud-page-btn" href="?<?= audQs(['page' => $page + 1]) ?>">
            <i class="fa-solid fa-chevron-right"></i>
          </a>
        <?php endif; ?>

        <span class="aud-page-info">Page <?= $page ?> of <?= $pages ?> &middot; <?= number_format($total) ?> total</span>
      </div>
      <?php endif; ?>

    </div><!-- .aud-page -->
  </div><!-- .main-content -->
</div><!-- .main -->

<script>
(function () {
    document.querySelectorAll('.aud-row').forEach(function (row) {
        row.addEventListener('click', function () {
            var detail = document.getElementById('aud-detail-' + this.dataset.id);
            if (detail) detail.classList.toggle('aud-detail--open');
            this.classList.toggle('aud-row--active');
        });
        row.addEventListener('keydown', function (e) {
            if (e.key === 'Enter' || e.key === ' ') { e.preventDefault(); this.click(); }
        });
    });
}());
</script>

<?php include __DIR__ . '/../../includes/footer.php'; ?>
