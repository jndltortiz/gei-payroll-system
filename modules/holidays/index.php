<?php
require_once __DIR__ . '/../../config/config.php';
require_once __DIR__ . '/../../includes/auth.php';
requireLogin();

// ── Flash messages ────────────────────────────────────────────────────────
$flashOk  = $_SESSION['hol_success'] ?? '';
$flashErr = $_SESSION['hol_error']   ?? '';
unset($_SESSION['hol_success'], $_SESSION['hol_error']);

// ── Filters ───────────────────────────────────────────────────────────────
$filterTab    = in_array($_GET['tab'] ?? '', ['all','ph','school']) ? ($_GET['tab'] ?? 'all') : 'all';
$filterSY     = (int)($_GET['school_year_id'] ?? 0);
$filterType   = $_GET['type'] ?? '';
$filterYear   = (int)($_GET['year'] ?? date('Y'));
$filterSearch = trim($_GET['q'] ?? '');

// ── School years ──────────────────────────────────────────────────────────
$schoolYears = $pdo->query("
    SELECT school_year_id, year_name, is_active
    FROM school_years
    ORDER BY start_date DESC
")->fetchAll(PDO::FETCH_ASSOC);

$activeSchoolYear = null;
foreach ($schoolYears as $sy) {
    if ($sy['is_active']) { $activeSchoolYear = $sy; break; }
}

// ── Available calendar years ──────────────────────────────────────────────
$availYears = $pdo->query("
    SELECT DISTINCT YEAR(holiday_date) AS yr FROM holidays ORDER BY yr DESC
")->fetchAll(PDO::FETCH_COLUMN);
if (!in_array((int)date('Y'), $availYears)) {
    array_unshift($availYears, (int)date('Y'));
}

// ── Build query ───────────────────────────────────────────────────────────
$where  = "WHERE 1=1";
$params = [];

// Tab-based type filter
if ($filterTab === 'ph') {
    if ($filterType === 'REGULAR' || $filterType === 'SPECIAL') {
        $where .= " AND h.holiday_type = :ht";
        $params[':ht'] = $filterType;
    } else {
        $where .= " AND h.holiday_type IN ('REGULAR','SPECIAL')";
    }
} elseif ($filterTab === 'school') {
    $where .= " AND h.holiday_type = 'SCHOOL'";
} else {
    // All tab: respect explicit type filter
    if ($filterType !== '') {
        $where .= " AND h.holiday_type = :ht";
        $params[':ht'] = $filterType;
    }
}

// School year / calendar year filter
if ($filterSY > 0) {
    $where .= " AND h.school_year_id = :sy";
    $params[':sy'] = $filterSY;
} elseif ($filterYear > 0) {
    $where .= " AND YEAR(h.holiday_date) = :yr";
    $params[':yr'] = $filterYear;
}

// Search filter
if ($filterSearch !== '') {
    $where .= " AND h.holiday_name LIKE :q";
    $params[':q'] = '%' . $filterSearch . '%';
}

$stmt = $pdo->prepare("
    SELECT h.*, sy.year_name
    FROM holidays h
    LEFT JOIN school_years sy ON h.school_year_id = sy.school_year_id
    {$where}
    ORDER BY h.holiday_date ASC
");
$stmt->execute($params);
$holidays = $stmt->fetchAll(PDO::FETCH_ASSOC);

// ── Group by month ────────────────────────────────────────────────────────
$grouped = [];
foreach ($holidays as $h) {
    $grouped[date('Y-m', strtotime($h['holiday_date']))][] = $h;
}

// ── Summary counts (global — unfiltered) ──────────────────────────────────
$summary = $pdo->query("
    SELECT
        COUNT(*)                                AS total,
        SUM(holiday_type='REGULAR')             AS regular,
        SUM(holiday_type='SPECIAL')             AS special,
        SUM(holiday_type='SCHOOL')              AS school,
        SUM(YEAR(holiday_date)=YEAR(CURDATE())) AS this_year
    FROM holidays
")->fetch(PDO::FETCH_ASSOC);

// ── URL helpers ───────────────────────────────────────────────────────────
function holBaseParams(): array {
    global $filterSY, $filterYear, $filterSearch;
    return array_filter([
        'school_year_id' => $filterSY  ?: '',
        'year'           => $filterYear ?: '',
        'q'              => $filterSearch,
    ], function($v) { return $v !== '' && $v !== 0; });
}

function holTabUrl(string $tab): string {
    $p = holBaseParams();
    $p['tab'] = $tab;
    return '?' . http_build_query($p);
}

$currentUrl = '?' . http_build_query(array_filter([
    'tab'            => $filterTab,
    'school_year_id' => $filterSY  ?: '',
    'year'           => $filterYear ?: '',
    'type'           => $filterType,
    'q'              => $filterSearch,
], function($v) { return $v !== '' && $v !== 0; }));

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
    <div class="hol-header-btns">
      <button class="hol-btn-ghost" onclick="openImportModal()">
        <i class="fa fa-cloud-arrow-down"></i> Import PH Holidays
      </button>
      <button class="hol-btn-primary" onclick="openCreateModal()">
        <i class="fa fa-plus"></i> Add Holiday
      </button>
    </div>
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
      <div class="hol-sum-icon teal"><i class="fa fa-school"></i></div>
      <div>
        <div class="hol-sum-val"><?= (int)$summary['school'] ?></div>
        <div class="hol-sum-label">School Calendar</div>
      </div>
    </div>
  </div>

  <!-- Tabs -->
  <div class="hol-tabs">
    <a href="<?= holTabUrl('all') ?>"
       class="hol-tab <?= $filterTab === 'all'    ? 'hol-tab--active' : '' ?>">
      <i class="fa fa-list"></i> All Holidays
    </a>
    <a href="<?= holTabUrl('ph') ?>"
       class="hol-tab <?= $filterTab === 'ph'     ? 'hol-tab--active' : '' ?>">
      <i class="fa fa-flag"></i> Philippine Holidays
    </a>
    <a href="<?= holTabUrl('school') ?>"
       class="hol-tab <?= $filterTab === 'school' ? 'hol-tab--active' : '' ?>">
      <i class="fa fa-school"></i> School Calendar
    </a>
  </div>

  <!-- Filter bar -->
  <form method="GET" class="hol-filters-row" id="holFilterForm">
    <input type="hidden" name="tab" value="<?= htmlspecialchars($filterTab) ?>">
    <input type="hidden" name="q"   value="<?= htmlspecialchars($filterSearch) ?>">

    <div class="hol-filter-group">
      <label>School Year</label>
      <select name="school_year_id" onchange="this.form.submit()">
        <option value="">— All Years —</option>
        <?php foreach ($schoolYears as $sy): ?>
        <option value="<?= $sy['school_year_id'] ?>"
                <?= $filterSY == $sy['school_year_id'] ? 'selected' : '' ?>>
          <?= htmlspecialchars($sy['year_name']) ?>
          <?= $sy['is_active'] ? ' ★' : '' ?>
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

    <?php if ($filterTab === 'all' || $filterTab === 'ph'): ?>
    <div class="hol-filter-group">
      <label>Type</label>
      <select name="type" onchange="this.form.submit()">
        <?php if ($filterTab === 'ph'): ?>
        <option value="">All PH Types</option>
        <option value="REGULAR" <?= $filterType === 'REGULAR' ? 'selected' : '' ?>>Regular Only</option>
        <option value="SPECIAL" <?= $filterType === 'SPECIAL' ? 'selected' : '' ?>>Special Only</option>
        <?php else: ?>
        <option value="">All Types</option>
        <option value="REGULAR" <?= $filterType === 'REGULAR' ? 'selected' : '' ?>>Regular</option>
        <option value="SPECIAL" <?= $filterType === 'SPECIAL' ? 'selected' : '' ?>>Special</option>
        <option value="SCHOOL"  <?= $filterType === 'SCHOOL'  ? 'selected' : '' ?>>School</option>
        <?php endif; ?>
      </select>
    </div>
    <?php endif; ?>

    <?php if ($filterSY || $filterType || ($filterYear && $filterYear != (int)date('Y'))): ?>
    <a href="<?= holTabUrl($filterTab) ?>" class="hol-btn-ghost hol-btn-clear">
      <i class="fa fa-times"></i> Clear
    </a>
    <?php endif; ?>
  </form>

  <!-- Search bar -->
  <form method="GET" class="hol-search-bar">
    <input type="hidden" name="tab"            value="<?= htmlspecialchars($filterTab) ?>">
    <input type="hidden" name="school_year_id" value="<?= $filterSY ?>">
    <input type="hidden" name="year"           value="<?= $filterYear ?>">
    <input type="hidden" name="type"           value="<?= htmlspecialchars($filterType) ?>">
    <input type="hidden" name="page"           value="1">
    <div class="hol-search-wrap">
      <i class="fa fa-magnifying-glass hol-search-icon"></i>
      <input type="text" name="q" value="<?= htmlspecialchars($filterSearch) ?>"
             placeholder="Search by holiday name…"
             class="hol-search-input" autocomplete="off">
      <?php if ($filterSearch): ?>
      <a href="<?= htmlspecialchars($currentUrl) ?>&q=" class="hol-search-clear" title="Clear">
        <i class="fa fa-xmark"></i>
      </a>
      <?php endif; ?>
    </div>
    <button type="submit" class="hol-btn-ghost" style="height:38px;">Search</button>
  </form>

  <!-- Content -->
  <?php if (empty($holidays)): ?>
  <div class="hol-empty">
    <i class="fa fa-calendar-xmark"></i>
    <h3><?= $filterSearch ? 'No holidays match your search' : 'No holidays found' ?></h3>
    <p>
      <?php if ($filterSearch): ?>
        Try a different name or clear the search.
      <?php elseif ($filterTab === 'school'): ?>
        Add School Calendar entries for breaks, events, and non-working school days.
      <?php else: ?>
        Add holidays to enable automatic HOLIDAY attendance status on those dates.
      <?php endif; ?>
    </p>
    <?php if ($filterTab !== 'school'): ?>
    <div style="display:flex;gap:10px;justify-content:center;flex-wrap:wrap;">
      <button class="hol-btn-primary" onclick="openCreateModal()">
        <i class="fa fa-plus"></i> Add Holiday
      </button>
      <button class="hol-btn-ghost" onclick="openImportModal()">
        <i class="fa fa-cloud-arrow-down"></i> Import PH Holidays
      </button>
    </div>
    <?php else: ?>
    <button class="hol-btn-primary" onclick="openCreateModal()">
      <i class="fa fa-plus"></i> Add School Entry
    </button>
    <?php endif; ?>
  </div>
  <?php else: ?>

  <!-- Result info -->
  <div class="hol-result-info">
    <span>
      Showing <strong><?= count($holidays) ?></strong>
      holiday<?= count($holidays) !== 1 ? 's' : '' ?>
      <?= $filterSearch ? ' matching <em>' . htmlspecialchars($filterSearch) . '</em>' : '' ?>
    </span>
  </div>

  <!-- Bulk action bar -->
  <div class="hol-bulk-bar" id="holBulkBar" style="display:none;">
    <span class="hol-bulk-count" id="holBulkCount">0 selected</span>
    <div class="hol-bulk-controls">
      <select id="holBulkAction" class="hol-bulk-select" onchange="updateBulkControls()">
        <option value="">— Choose action —</option>
        <option value="bulk_link">Link to School Year</option>
        <option value="bulk_type">Change Type</option>
        <option value="bulk_delete">Delete Selected</option>
      </select>
      <!-- Extra: school year select for bulk_link -->
      <select id="holBulkSY" class="hol-bulk-select" style="display:none;">
        <option value="">— Unlink —</option>
        <?php foreach ($schoolYears as $sy): ?>
        <option value="<?= $sy['school_year_id'] ?>">
          <?= htmlspecialchars($sy['year_name']) ?>
          <?= $sy['is_active'] ? ' ★' : '' ?>
        </option>
        <?php endforeach; ?>
      </select>
      <!-- Extra: type select for bulk_type -->
      <select id="holBulkType" class="hol-bulk-select" style="display:none;">
        <option value="REGULAR">Regular Holiday</option>
        <option value="SPECIAL">Special Holiday</option>
        <option value="SCHOOL">School Calendar</option>
      </select>
      <button class="hol-btn-primary hol-bulk-apply" onclick="executeBulkAction()">
        Apply
      </button>
      <button class="hol-btn-ghost" onclick="clearBulkSelection()">Cancel</button>
    </div>
  </div>

  <!-- Bulk form (hidden; populated by JS) -->
  <form method="POST" action="<?= BASE_URL ?>actions/holiday-action.php" id="holBulkForm">
    <input type="hidden" name="action"         id="holBulkActionInput" value="">
    <input type="hidden" name="school_year_id" id="holBulkSYInput"     value="">
    <input type="hidden" name="holiday_type"   id="holBulkTypeInput"   value="">
    <input type="hidden" name="redirect"       value="<?= htmlspecialchars($currentUrl) ?>">
    <div id="holBulkIdsContainer"></div>
  </form>

  <!-- Holiday table (wrapped in a div so the bulk form is separate) -->
  <div class="hol-table-wrap">
    <table class="hol-table" id="holTable">
      <thead>
        <tr>
          <th class="hol-col-check">
            <input type="checkbox" id="selectAllChk" onchange="toggleSelectAll(this)"
                   title="Select all">
          </th>
          <th>Holiday Name</th>
          <th>Date</th>
          <th>Day</th>
          <th>Type</th>
          <th>School Year</th>
          <th class="hol-col-notes">Notes</th>
          <th>Actions</th>
        </tr>
      </thead>
      <tbody>
      <?php
      $typeLabels  = ['REGULAR' => 'Regular',  'SPECIAL' => 'Special',  'SCHOOL' => 'School'];
      $typeClasses = ['REGULAR' => 'hol-badge--regular', 'SPECIAL' => 'hol-badge--special', 'SCHOOL' => 'hol-badge--school'];

      foreach ($grouped as $monthKey => $monthHols):
          $monthLabel = date('F Y', strtotime($monthKey . '-01'));
      ?>
        <tr class="hol-month-row">
          <td colspan="8" class="hol-month-cell">
            <span class="hol-month-label"><i class="fa fa-calendar-days"></i> <?= $monthLabel ?></span>
          </td>
        </tr>
        <?php foreach ($monthHols as $h):
          $isPast  = $h['holiday_date'] < date('Y-m-d');
          $isToday = $h['holiday_date'] === date('Y-m-d');
          $tLabel  = $typeLabels[$h['holiday_type']] ?? ucfirst(strtolower($h['holiday_type']));
          $tClass  = $typeClasses[$h['holiday_type']] ?? 'hol-badge--special';
          $payload = htmlspecialchars(json_encode($h), ENT_QUOTES);
        ?>
        <tr class="<?= $isToday ? 'hol-row--today' : '' ?>" data-id="<?= $h['holiday_id'] ?>">
          <td class="hol-col-check">
            <input type="checkbox" class="hol-row-chk" value="<?= $h['holiday_id'] ?>"
                   onchange="updateBulkBar()">
          </td>
          <td>
            <strong><?= htmlspecialchars($h['holiday_name']) ?></strong>
            <?php if ($isToday): ?>
            <span class="hol-badge hol-badge--today">Today</span>
            <?php endif; ?>
          </td>
          <td>
            <span class="<?= $isPast ? 'hol-date-past' : 'hol-date-future' ?>">
              <?= date('M j, Y', strtotime($h['holiday_date'])) ?>
            </span>
          </td>
          <td class="hol-day"><?= date('l', strtotime($h['holiday_date'])) ?></td>
          <td><span class="hol-badge <?= $tClass ?>"><?= $tLabel ?></span></td>
          <td>
            <?php if ($h['year_name']): ?>
            <span class="hol-sy-tag"><?= htmlspecialchars($h['year_name']) ?></span>
            <?php else: ?>
            <span class="hol-badge hol-badge--unlinked">Not linked</span>
            <?php endif; ?>
          </td>
          <td class="hol-col-notes hol-notes">
            <?= $h['notes'] ? htmlspecialchars($h['notes']) : '<span class="hol-none">—</span>' ?>
          </td>
          <td>
            <div class="hol-action-btns">
              <button class="hol-btn-sm hol-btn-sm--outline"
                      onclick="openEditModal(<?= $payload ?>)"
                      title="Edit">
                <i class="fa fa-pen"></i>
              </button>
              <?php if (!$isPast): ?>
              <button class="hol-btn-sm hol-btn-sm--danger"
                      onclick="deleteHoliday(<?= $h['holiday_id'] ?>, <?= htmlspecialchars(json_encode($h['holiday_name']), ENT_QUOTES) ?>)"
                      title="Delete">
                <i class="fa fa-trash"></i>
              </button>
              <?php endif; ?>
            </div>
          </td>
        </tr>
        <?php endforeach; ?>
      <?php endforeach; ?>
      </tbody>
    </table>
  </div>

  <p class="hol-table-note">
    <i class="fa fa-circle-info"></i>
    Past holidays cannot be deleted once attendance records reference them.
    Regular = national rest day &nbsp;·&nbsp; Special = optional non-working day &nbsp;·&nbsp; School = academic calendar entry.
  </p>

  <?php endif; // empty($holidays) ?>

</div><!-- .hol-page -->
</div><!-- .main-content -->
</div><!-- .main -->
</div><!-- .layout -->

<!-- ── Add / Edit Holiday Modal ──────────────────────────────────────────── -->
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
      <input type="hidden" name="redirect"   value="<?= htmlspecialchars($currentUrl) ?>">

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
            <select name="holiday_type" id="holType" onchange="onHolTypeChange()">
              <option value="REGULAR">Regular Holiday</option>
              <option value="SPECIAL">Special Holiday</option>
              <option value="SCHOOL">School Calendar</option>
            </select>
            <small id="holTypeHint">Regular = national rest day. Special = optional non-working.</small>
          </div>
          <div class="hol-form-group" id="holSYGroup">
            <label id="holSYLabel">School Year</label>
            <select name="school_year_id" id="holSY">
              <option value="">— Not linked —</option>
              <?php foreach ($schoolYears as $sy): ?>
              <option value="<?= $sy['school_year_id'] ?>">
                <?= htmlspecialchars($sy['year_name']) ?>
                <?= $sy['is_active'] ? ' ★' : '' ?>
              </option>
              <?php endforeach; ?>
            </select>
          </div>
        </div>

        <div class="hol-form-row">
          <div class="hol-form-group">
            <label>Notes <span class="hol-opt">(optional)</span></label>
            <input type="text" name="notes" id="holNotes" maxlength="255"
                   placeholder="e.g. Proc. No. 1087 — optional rest day">
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

<!-- ── Import PH Holidays Modal ──────────────────────────────────────────── -->
<div class="hol-modal-overlay" id="importModal" style="display:none;">
  <div class="hol-modal-box">
    <div class="hol-modal-header">
      <h3><i class="fa fa-cloud-arrow-down"></i> Import Philippine Holidays</h3>
      <button onclick="document.getElementById('importModal').style.display='none'">
        <i class="fa fa-times"></i>
      </button>
    </div>
    <form method="POST" action="<?= BASE_URL ?>actions/holiday-action.php">
      <input type="hidden" name="action"   value="import_ph">
      <input type="hidden" name="redirect" value="<?= htmlspecialchars($currentUrl) ?>">

      <div class="hol-modal-body">

        <div class="hol-import-info">
          <i class="fa fa-circle-info"></i>
          <div>
            <strong>Auto-generates official Philippine holidays</strong> including regular and
            special non-working days (New Year's Day, Holy Week, Independence Day, etc.).
            Existing dates are <em>skipped</em> — no duplicates will be created.
            You can edit or delete individual entries after import.
          </div>
        </div>

        <div class="hol-form-row hol-form-row--2" style="margin-top:16px;">
          <div class="hol-form-group">
            <label>Calendar Year <span class="req">*</span></label>
            <select name="import_year" id="importYear" required>
              <?php
              $curY = (int)date('Y');
              for ($y = $curY - 2; $y <= $curY + 6; $y++):
              ?>
              <option value="<?= $y ?>" <?= $y === $curY ? 'selected' : '' ?>><?= $y ?></option>
              <?php endfor; ?>
            </select>
          </div>
          <div class="hol-form-group">
            <label>Link to School Year <span class="hol-opt">(optional)</span></label>
            <select name="school_year_id">
              <option value="">— Not linked —</option>
              <?php foreach ($schoolYears as $sy): ?>
              <option value="<?= $sy['school_year_id'] ?>"
                      <?= $activeSchoolYear && $activeSchoolYear['school_year_id'] == $sy['school_year_id'] ? 'selected' : '' ?>>
                <?= htmlspecialchars($sy['year_name']) ?>
                <?= $sy['is_active'] ? ' ★' : '' ?>
              </option>
              <?php endforeach; ?>
            </select>
          </div>
        </div>

        <div class="hol-import-preview">
          <strong>What will be imported for <span id="importYearPreview"><?= $curY ?></span>:</strong>
          <div class="hol-import-list">
            <div class="hol-import-list-col">
              <span class="hol-badge hol-badge--regular" style="margin-bottom:6px;">Regular Holidays</span>
              <ul>
                <li>New Year's Day (Jan 1)</li>
                <li>Araw ng Kagitingan (Apr 9)</li>
                <li>Maundy Thursday</li>
                <li>Good Friday</li>
                <li>Labor Day (May 1)</li>
                <li>Independence Day (Jun 12)</li>
                <li>National Heroes Day (last Mon, Aug)</li>
                <li>Bonifacio Day (Nov 30)</li>
                <li>Christmas Day (Dec 25)</li>
                <li>Rizal Day (Dec 30)</li>
              </ul>
            </div>
            <div class="hol-import-list-col">
              <span class="hol-badge hol-badge--special" style="margin-bottom:6px;">Special Holidays</span>
              <ul>
                <li>EDSA Revolution Anniversary (Feb 25)</li>
                <li>Black Saturday</li>
                <li>Ninoy Aquino Day (Aug 21)</li>
                <li>All Saints' Day (Nov 1)</li>
                <li>Immaculate Conception (Dec 8)</li>
                <li>Last Day of the Year (Dec 31)</li>
              </ul>
            </div>
          </div>
        </div>

      </div>

      <div class="hol-modal-footer">
        <button type="button" class="hol-btn-ghost"
                onclick="document.getElementById('importModal').style.display='none'">Cancel</button>
        <button type="submit" class="hol-btn-primary">
          <i class="fa fa-cloud-arrow-down"></i> Import Holidays
        </button>
      </div>
    </form>
  </div>
</div>

<!-- Hidden delete form -->
<form method="POST" action="<?= BASE_URL ?>actions/holiday-action.php" id="holDeleteForm" style="display:none;">
  <input type="hidden" name="action"     value="delete">
  <input type="hidden" name="holiday_id" id="holDeleteId" value="">
  <input type="hidden" name="redirect"   value="<?= htmlspecialchars($currentUrl) ?>">
</form>

<script>
// ── Modal helpers ──────────────────────────────────────────────────────────
function openCreateModal() {
    document.getElementById('holModalTitle').innerHTML = '<i class="fa fa-calendar-plus"></i> Add Holiday';
    document.getElementById('holFormAction').value = 'create';
    document.getElementById('holFormId').value     = '';
    document.getElementById('holSubmitLabel').textContent = 'Save Holiday';
    document.getElementById('holForm').reset();
    <?php if ($activeSchoolYear): ?>
    document.getElementById('holSY').value = '<?= $activeSchoolYear['school_year_id'] ?>';
    <?php endif; ?>
    <?php if ($filterTab === 'school'): ?>
    document.getElementById('holType').value = 'SCHOOL';
    <?php elseif ($filterTab === 'ph'): ?>
    document.getElementById('holType').value = 'REGULAR';
    <?php endif; ?>
    onHolTypeChange();
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
    onHolTypeChange();
    document.getElementById('holModal').style.display = 'flex';
}

function openImportModal() {
    document.getElementById('importModal').style.display = 'flex';
}

// ── Dynamic modal: school year required when type = SCHOOL ─────────────────
function onHolTypeChange() {
    var type    = document.getElementById('holType').value;
    var label   = document.getElementById('holSYLabel');
    var syGroup = document.getElementById('holSYGroup');
    var syEl    = document.getElementById('holSY');
    var hint    = document.getElementById('holTypeHint');

    if (type === 'SCHOOL') {
        label.innerHTML = 'School Year <span class="req">*</span>';
        syEl.required   = true;
        syGroup.classList.add('hol-sy-required');
        hint.textContent = 'School Calendar entries must be linked to a school year.';
    } else {
        label.innerHTML = 'School Year <span class="hol-opt">(optional)</span>';
        syEl.required   = false;
        syGroup.classList.remove('hol-sy-required');
        hint.textContent = type === 'REGULAR'
            ? 'Regular = national rest day. Employees are off work.'
            : 'Special = optional non-working day (e.g. EDSA anniversary).';
    }
}

// ── Delete (single) ────────────────────────────────────────────────────────
function deleteHoliday(id, name) {
    GEI.confirm({
        title:       'Delete Holiday',
        message:     'Delete "' + name + '"? This will permanently remove the holiday.',
        note:        'Cannot be undone if attendance records reference this date.',
        type:        'danger',
        confirmText: 'Delete Holiday',
    }).then(function() {
        document.getElementById('holDeleteId').value = id;
        document.getElementById('holDeleteForm').submit();
    }).catch(function() {});
}

// ── Bulk selection ─────────────────────────────────────────────────────────
function updateBulkBar() {
    var chks   = document.querySelectorAll('.hol-row-chk:checked');
    var bar    = document.getElementById('holBulkBar');
    var count  = document.getElementById('holBulkCount');
    var allChk = document.getElementById('selectAllChk');
    var total  = document.querySelectorAll('.hol-row-chk').length;

    count.textContent = chks.length + ' selected';
    bar.style.display = chks.length > 0 ? 'flex' : 'none';
    allChk.indeterminate = chks.length > 0 && chks.length < total;
    allChk.checked = chks.length === total && total > 0;
}

function toggleSelectAll(masterChk) {
    document.querySelectorAll('.hol-row-chk').forEach(function(c) {
        c.checked = masterChk.checked;
    });
    updateBulkBar();
}

function clearBulkSelection() {
    document.querySelectorAll('.hol-row-chk, #selectAllChk').forEach(function(c) {
        c.checked = false;
        c.indeterminate = false;
    });
    document.getElementById('holBulkBar').style.display = 'none';
    document.getElementById('holBulkAction').value = '';
    updateBulkControls();
}

function updateBulkControls() {
    var action  = document.getElementById('holBulkAction').value;
    document.getElementById('holBulkSY').style.display   = action === 'bulk_link'   ? 'inline-block' : 'none';
    document.getElementById('holBulkType').style.display = action === 'bulk_type'   ? 'inline-block' : 'none';
}

function executeBulkAction() {
    var action = document.getElementById('holBulkAction').value;
    if (!action) { alert('Please choose an action first.'); return; }

    var ids = Array.from(document.querySelectorAll('.hol-row-chk:checked')).map(function(c) { return c.value; });
    if (ids.length === 0) { alert('No holidays selected.'); return; }

    var actionLabels = { bulk_link: 'link', bulk_type: 'retype', bulk_delete: 'delete' };

    GEI.confirm({
        title:       action === 'bulk_delete' ? 'Delete Selected Holidays' : 'Apply Bulk Action',
        message:     (action === 'bulk_delete'
                       ? 'Delete ' + ids.length + ' selected holiday(s)?'
                       : 'Apply "' + (document.getElementById('holBulkAction').options[document.getElementById('holBulkAction').selectedIndex].text) + '" to ' + ids.length + ' holiday(s)?'),
        note:        action === 'bulk_delete' ? 'Holidays with attendance records will be skipped.' : 'This will update the selected holidays.',
        type:        action === 'bulk_delete' ? 'danger' : 'warning',
        confirmText: action === 'bulk_delete' ? 'Delete Selected' : 'Apply',
    }).then(function() {
        // Populate bulk form
        document.getElementById('holBulkActionInput').value = action;
        document.getElementById('holBulkSYInput').value     = action === 'bulk_link' ? document.getElementById('holBulkSY').value : '';
        document.getElementById('holBulkTypeInput').value   = action === 'bulk_type' ? document.getElementById('holBulkType').value : '';

        var container = document.getElementById('holBulkIdsContainer');
        container.innerHTML = '';
        ids.forEach(function(id) {
            var inp = document.createElement('input');
            inp.type  = 'hidden';
            inp.name  = 'holiday_ids[]';
            inp.value = id;
            container.appendChild(inp);
        });

        document.getElementById('holBulkForm').submit();
    }).catch(function() {});
}

// ── Import year preview label ──────────────────────────────────────────────
var importYear = document.getElementById('importYear');
var importYearPreview = document.getElementById('importYearPreview');
if (importYear && importYearPreview) {
    importYear.addEventListener('change', function() {
        importYearPreview.textContent = this.value;
    });
}

// ── Close modals on overlay click ─────────────────────────────────────────
document.querySelectorAll('.hol-modal-overlay').forEach(function(el) {
    el.addEventListener('click', function(e) {
        if (e.target === el) el.style.display = 'none';
    });
});
</script>

<?php include __DIR__ . '/../../includes/footer.php'; ?>
