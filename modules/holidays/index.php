<?php
require_once __DIR__ . '/../../config/config.php';
require_once __DIR__ . '/../../includes/auth.php';
requireLogin();

// ── Flash messages ────────────────────────────────────────────────────────────
$flashOk  = $_SESSION['hol_success'] ?? '';
$flashErr = $_SESSION['hol_error']   ?? '';
unset($_SESSION['hol_success'], $_SESSION['hol_error']);

// ── Filters ───────────────────────────────────────────────────────────────────
$filterSY   = (int)($_GET['school_year_id'] ?? 0);
$filterType = $_GET['type'] ?? '';
$filterYear = (int)($_GET['year'] ?? date('Y'));

// ── School years for filter dropdown ─────────────────────────────────────────
$schoolYears = $pdo->query("
    SELECT school_year_id, year_name, is_active
    FROM school_years
    ORDER BY start_date DESC
")->fetchAll(PDO::FETCH_ASSOC);

$activeSchoolYear = null;
foreach ($schoolYears as $sy) {
    if ($sy['is_active']) { $activeSchoolYear = $sy; break; }
}

// ── Build query ───────────────────────────────────────────────────────────────
$where  = "WHERE 1=1";
$params = [];

if ($filterSY > 0) {
    $where   .= " AND h.school_year_id = :sy";
    $params[':sy'] = $filterSY;
} elseif ($filterYear > 0) {
    $where   .= " AND YEAR(h.holiday_date) = :yr";
    $params[':yr'] = $filterYear;
}

if ($filterType !== '') {
    $where   .= " AND h.holiday_type = :ht";
    $params[':ht'] = $filterType;
}

$holidays = $pdo->prepare("
    SELECT h.*,
           sy.year_name
    FROM holidays h
    LEFT JOIN school_years sy ON h.school_year_id = sy.school_year_id
    $where
    ORDER BY h.holiday_date ASC
");
$holidays->execute($params);
$holidays = $holidays->fetchAll(PDO::FETCH_ASSOC);

// ── Available years for the year filter ───────────────────────────────────────
$availYears = $pdo->query("
    SELECT DISTINCT YEAR(holiday_date) AS yr FROM holidays ORDER BY yr DESC
")->fetchAll(PDO::FETCH_COLUMN);
if (!in_array(date('Y'), $availYears)) {
    array_unshift($availYears, (int)date('Y'));
}

// ── Summary counts (unfiltered, current year) ─────────────────────────────────
$summary = $pdo->prepare("
    SELECT
        COUNT(*)                                AS total,
        SUM(holiday_type='REGULAR')             AS regular,
        SUM(holiday_type='SPECIAL')             AS special,
        SUM(YEAR(holiday_date)=YEAR(CURDATE())) AS this_year
    FROM holidays
");
$summary->execute();
$summary = $summary->fetch(PDO::FETCH_ASSOC);

$pageTitle = 'Holiday Calendar';
$extraCSS  = [BASE_URL . 'assets/css/holidays.css'];
require_once __DIR__ . '/../../includes/head.php';
?>
<body>
<div class="layout">
<?php include __DIR__ . '/../../includes/sidebar.php'; ?>
<div class="main">
<?php include __DIR__ . '/../../includes/header.php'; ?>
<div class="main-content">

<div class="hol-page">

  <!-- Page Header -->
  <div class="hol-page-header">
    <div>
      <h1><i class="fa fa-calendar-check"></i> Holiday Calendar</h1>
      <p>Manage school holidays. Attendance records on holiday dates are automatically marked HOLIDAY.</p>
    </div>
    <button class="hol-btn-primary" onclick="openCreateModal()">
      <i class="fa fa-plus"></i> Add Holiday
    </button>
  </div>

  <!-- Flash alerts -->
  <?php if ($flashOk): ?>
  <div class="hol-alert hol-alert--ok">
    <i class="fa fa-circle-check"></i>
    <?= htmlspecialchars($flashOk) ?>
    <button onclick="this.parentElement.remove()">×</button>
  </div>
  <?php endif; ?>
  <?php if ($flashErr): ?>
  <div class="hol-alert hol-alert--err">
    <i class="fa fa-triangle-exclamation"></i>
    <?= htmlspecialchars($flashErr) ?>
    <button onclick="this.parentElement.remove()">×</button>
  </div>
  <?php endif; ?>

  <!-- Summary Cards -->
  <div class="hol-summary-cards">
    <div class="hol-sum-card">
      <div class="hol-sum-icon blue"><i class="fa fa-calendar-days"></i></div>
      <div>
        <div class="hol-sum-val"><?= (int)$summary['total'] ?></div>
        <div class="hol-sum-label">Total Holidays</div>
      </div>
    </div>
    <div class="hol-sum-card">
      <div class="hol-sum-icon red"><i class="fa fa-star"></i></div>
      <div>
        <div class="hol-sum-val"><?= (int)$summary['regular'] ?></div>
        <div class="hol-sum-label">Regular Holidays</div>
      </div>
    </div>
    <div class="hol-sum-card">
      <div class="hol-sum-icon orange"><i class="fa fa-circle-dot"></i></div>
      <div>
        <div class="hol-sum-val"><?= (int)$summary['special'] ?></div>
        <div class="hol-sum-label">Special Holidays</div>
      </div>
    </div>
    <div class="hol-sum-card">
      <div class="hol-sum-icon green"><i class="fa fa-calendar-check"></i></div>
      <div>
        <div class="hol-sum-val"><?= (int)$summary['this_year'] ?></div>
        <div class="hol-sum-label">This Year (<?= date('Y') ?>)</div>
      </div>
    </div>
  </div>

  <!-- Filters -->
  <form method="GET" class="hol-filters-row" id="holFilterForm">
    <div class="hol-filter-group">
      <label>School Year</label>
      <select name="school_year_id" onchange="this.form.submit()">
        <option value="">— All Years —</option>
        <?php foreach ($schoolYears as $sy): ?>
        <option value="<?= $sy['school_year_id'] ?>"
                <?= $filterSY == $sy['school_year_id'] ? 'selected' : '' ?>>
          <?= htmlspecialchars($sy['year_name']) ?>
          <?= $sy['is_active'] ? ' (Active)' : '' ?>
        </option>
        <?php endforeach; ?>
      </select>
    </div>
    <div class="hol-filter-group">
      <label>Calendar Year</label>
      <select name="year" onchange="this.form.submit()">
        <option value="">— All —</option>
        <?php foreach ($availYears as $yr): ?>
        <option value="<?= $yr ?>" <?= $filterYear == $yr ? 'selected' : '' ?>><?= $yr ?></option>
        <?php endforeach; ?>
      </select>
    </div>
    <div class="hol-filter-group">
      <label>Type</label>
      <select name="type" onchange="this.form.submit()">
        <option value="">All Types</option>
        <option value="REGULAR" <?= $filterType === 'REGULAR' ? 'selected' : '' ?>>Regular</option>
        <option value="SPECIAL" <?= $filterType === 'SPECIAL' ? 'selected' : '' ?>>Special</option>
        <option value="SCHOOL"  <?= $filterType === 'SCHOOL'  ? 'selected' : '' ?>>School</option>
      </select>
    </div>
    <?php if ($filterSY || $filterType || ($filterYear && $filterYear != date('Y'))): ?>
    <a href="<?= BASE_URL ?>modules/holidays/index.php" class="hol-btn-ghost hol-btn-clear">
      <i class="fa fa-times"></i> Clear
    </a>
    <?php endif; ?>
  </form>

  <!-- Holidays Table -->
  <?php if (empty($holidays)): ?>
  <div class="hol-empty">
    <i class="fa fa-calendar-xmark"></i>
    <h3>No holidays found</h3>
    <p>Add holidays to enable automatic HOLIDAY attendance status on those dates.</p>
    <button class="hol-btn-primary" onclick="openCreateModal()">
      <i class="fa fa-plus"></i> Add Holiday
    </button>
  </div>
  <?php else: ?>
  <div class="hol-table-wrap">
    <table class="hol-table">
      <thead>
        <tr>
          <th>#</th>
          <th class="sortable" data-col="1" data-sort-type="text">Holiday Name</th>
          <th class="sortable" data-col="2" data-sort-type="date">Date</th>
          <th>Day</th>
          <th class="sortable" data-col="4" data-sort-type="text">Type</th>
          <th>School Year</th>
          <th>Notes</th>
          <th>Actions</th>
        </tr>
      </thead>
      <tbody>
        <?php foreach ($holidays as $i => $h):
          $isPast   = $h['holiday_date'] < date('Y-m-d');
          $isToday  = $h['holiday_date'] === date('Y-m-d');
        ?>
        <tr class="<?= $isToday ? 'hol-row--today' : '' ?>">
          <td class="hol-num"><?= $i + 1 ?></td>
          <td>
            <strong><?= htmlspecialchars($h['holiday_name']) ?></strong>
            <?php if ($isToday): ?><span class="hol-badge hol-badge--today">Today</span><?php endif; ?>
          </td>
          <td>
            <span class="<?= $isPast ? 'hol-date-past' : 'hol-date-future' ?>">
              <?= date('M j, Y', strtotime($h['holiday_date'])) ?>
            </span>
          </td>
          <td class="hol-day"><?= date('l', strtotime($h['holiday_date'])) ?></td>
          <td>
            <?php
            $typeLabels = ['REGULAR' => 'Regular', 'SPECIAL' => 'Special', 'SCHOOL' => 'School'];
            $typeClasses = ['REGULAR' => 'hol-badge--regular', 'SPECIAL' => 'hol-badge--special', 'SCHOOL' => 'hol-badge--school'];
            $tLabel = $typeLabels[$h['holiday_type']] ?? ucfirst(strtolower($h['holiday_type']));
            $tClass = $typeClasses[$h['holiday_type']] ?? 'hol-badge--special';
          ?>
            <span class="hol-badge <?= $tClass ?>"><?= $tLabel ?></span>
          </td>
          <td>
            <?= $h['year_name']
                ? '<span class="hol-sy-tag">' . htmlspecialchars($h['year_name']) . '</span>'
                : '<span class="hol-none">—</span>' ?>
          </td>
          <td class="hol-notes">
            <?= $h['notes'] ? htmlspecialchars($h['notes']) : '<span class="hol-none">—</span>' ?>
          </td>
          <td>
            <div class="hol-action-btns">
              <button class="hol-btn-sm hol-btn-sm--outline"
                      onclick="openEditModal(<?= htmlspecialchars(json_encode($h)) ?>)">
                <i class="fa fa-pen"></i> Edit
              </button>
              <?php if (!$isPast): ?>
              <form method="POST" action="<?= BASE_URL ?>actions/holiday-action.php"
                    data-confirm-title="Delete Holiday"
                    data-confirm-message="Delete &quot;<?= htmlspecialchars($h['holiday_name']) ?>&quot;? This holiday will be permanently removed."
                    data-confirm-note="This cannot be undone."
                    data-confirm-type="danger"
                    data-confirm-btn="Delete Holiday">
                <input type="hidden" name="action" value="delete">
                <input type="hidden" name="holiday_id" value="<?= $h['holiday_id'] ?>">
                <button type="submit" class="hol-btn-sm hol-btn-sm--danger">
                  <i class="fa fa-trash"></i>
                </button>
              </form>
              <?php endif; ?>
            </div>
          </td>
        </tr>
        <?php endforeach; ?>
      </tbody>
    </table>
  </div>
  <p class="hol-table-note">
    <i class="fa fa-circle-info"></i>
    Past holidays cannot be deleted once attendance records reference them.
    Regular holidays are national rest days; Special holidays are optional rest days.
  </p>
  <?php endif; ?>

</div><!-- .hol-page -->
</div><!-- .main-content -->
</div><!-- .main -->
</div><!-- .layout -->

<!-- ── Create / Edit Modal ──────────────────────────────────────────────────── -->
<div class="hol-modal-overlay" id="holModal" style="display:none;">
  <div class="hol-modal-box">
    <div class="hol-modal-header">
      <h3 id="holModalTitle"><i class="fa fa-calendar-plus"></i> Add Holiday</h3>
      <button onclick="document.getElementById('holModal').style.display='none'">
        <i class="fa fa-times"></i>
      </button>
    </div>
    <form method="POST" action="<?= BASE_URL ?>actions/holiday-action.php" id="holForm">
      <input type="hidden" name="action"     id="holFormAction" value="create">
      <input type="hidden" name="holiday_id" id="holFormId"     value="">
      <div class="hol-modal-body">

        <div class="hol-form-row hol-form-row--2">
          <div class="hol-form-group">
            <label>Holiday Name <span class="req">*</span></label>
            <input type="text" name="holiday_name" id="holName" required
                   placeholder="e.g. Independence Day" maxlength="150">
          </div>
          <div class="hol-form-group">
            <label>Date <span class="req">*</span></label>
            <input type="date" name="holiday_date" id="holDate" required>
          </div>
        </div>

        <div class="hol-form-row hol-form-row--2">
          <div class="hol-form-group">
            <label>Type <span class="req">*</span></label>
            <select name="holiday_type" id="holType">
              <option value="REGULAR">Regular Holiday</option>
              <option value="SPECIAL">Special Holiday</option>
              <option value="SCHOOL">School Holiday</option>
            </select>
            <small>Regular = national rest day. Special = optional. School = academic calendar.</small>
          </div>
          <div class="hol-form-group">
            <label>School Year</label>
            <select name="school_year_id" id="holSY">
              <option value="">— Not linked —</option>
              <?php foreach ($schoolYears as $sy): ?>
              <option value="<?= $sy['school_year_id'] ?>">
                <?= htmlspecialchars($sy['year_name']) ?>
                <?= $sy['is_active'] ? ' (Active)' : '' ?>
              </option>
              <?php endforeach; ?>
            </select>
          </div>
        </div>

        <div class="hol-form-row">
          <div class="hol-form-group">
            <label>Notes</label>
            <input type="text" name="notes" id="holNotes" maxlength="255"
                   placeholder="Optional description or law reference…">
          </div>
        </div>

      </div>
      <div class="hol-modal-footer">
        <button type="button" class="hol-btn-ghost"
                onclick="document.getElementById('holModal').style.display='none'">Cancel</button>
        <button type="submit" class="hol-btn-primary">
          <i class="fa fa-floppy-disk"></i>
          <span id="holSubmitLabel">Save Holiday</span>
        </button>
      </div>
    </form>
  </div>
</div>

<script>
function openCreateModal() {
    document.getElementById('holModalTitle').innerHTML = '<i class="fa fa-calendar-plus"></i> Add Holiday';
    document.getElementById('holFormAction').value = 'create';
    document.getElementById('holFormId').value     = '';
    document.getElementById('holSubmitLabel').textContent = 'Save Holiday';
    document.getElementById('holForm').reset();
    // Default school year to active if present
    <?php if ($activeSchoolYear): ?>
    document.getElementById('holSY').value = '<?= $activeSchoolYear['school_year_id'] ?>';
    <?php endif; ?>
    document.getElementById('holModal').style.display = 'flex';
    document.getElementById('holName').focus();
}

function openEditModal(h) {
    document.getElementById('holModalTitle').innerHTML = '<i class="fa fa-pen"></i> Edit Holiday';
    document.getElementById('holFormAction').value = 'update';
    document.getElementById('holFormId').value     = h.holiday_id;
    document.getElementById('holName').value       = h.holiday_name;
    document.getElementById('holDate').value       = h.holiday_date;
    document.getElementById('holType').value       = h.holiday_type;
    document.getElementById('holSY').value         = h.school_year_id || '';
    document.getElementById('holNotes').value      = h.notes || '';
    document.getElementById('holSubmitLabel').textContent = 'Update Holiday';
    document.getElementById('holModal').style.display = 'flex';
}

document.getElementById('holModal').addEventListener('click', function(e) {
    if (e.target === this) this.style.display = 'none';
});
</script>

<?php include __DIR__ . '/../../includes/footer.php'; ?>
