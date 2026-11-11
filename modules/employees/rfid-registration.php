<?php
require_once __DIR__ . '/../../includes/auth.php';
require_once __DIR__ . '/../../includes/rfid-utils.php';
requireAdminPage();
header('Cache-Control: no-store');

$flash = getFlash();
$esc = static fn($value): string => htmlspecialchars((string)$value, ENT_QUOTES, 'UTF-8');
$preselect = (int)($_GET['employee_id'] ?? 0);

$employees = $pdo->query("
    SELECT e.employee_id, e.employee_no, e.first_name, e.last_name, e.employee_status,
           e.rfid_uid, e.rfid_registered_at, d.department_name
    FROM employees e
    LEFT JOIN departments d ON d.department_id = e.department_id
    ORDER BY e.last_name, e.first_name
")->fetchAll(PDO::FETCH_ASSOC);

$activeEmployees = array_values(array_filter($employees, static fn($e) => $e['employee_status'] === 'ACTIVE'));
$withCard = count(array_filter($employees, static fn($e) => !empty($e['rfid_uid'])));
$activeWithoutCard = count(array_filter($activeEmployees, static fn($e) => empty($e['rfid_uid'])));

$pageTitle = 'RFID Card Registration';
$extraCSS = [BASE_URL . 'assets/css/rfid-registration.css'];
$loadBootstrap = false;
require_once __DIR__ . '/../../includes/head.php';
?>
<body>
<?php include __DIR__ . '/../../includes/sidebar.php'; ?>
<div class="main">
    <?php include __DIR__ . '/../../includes/header.php'; ?>
    <div class="content">
    <div class="rfidreg-page">
        <a class="rfidreg-back" href="<?= BASE_URL ?>modules/employees/index.php"><i class="fa fa-arrow-left"></i> Back to Employees</a>
        <div class="rfidreg-header">
            <div>
                <h1><i class="fa fa-id-card"></i> RFID Card Registration</h1>
                <p>Link an RFID card to an employee so they can time in and out at the RFID kiosk.</p>
            </div>
            <a class="rfidreg-btn rfidreg-btn--ghost" href="<?= BASE_URL ?>modules/attendance/rfid-kiosk.php" target="_blank" rel="noopener"><i class="fa fa-arrow-up-right-from-square"></i> Open RFID Kiosk</a>
        </div>

        <?php if ($flash): ?>
        <div class="rfidreg-alert rfidreg-alert--<?= $esc($flash['type']) ?>" role="alert">
            <i class="fa <?= $flash['type'] === 'success' ? 'fa-circle-check' : 'fa-circle-exclamation' ?>"></i>
            <span><?= $esc($flash['message']) ?></span>
        </div>
        <?php endif; ?>

        <div class="rfidreg-stats">
            <div class="rfidreg-stat"><strong><?= $withCard ?></strong><span>Cards registered</span></div>
            <div class="rfidreg-stat"><strong><?= count($activeEmployees) ?></strong><span>Active employees</span></div>
            <div class="rfidreg-stat <?= $activeWithoutCard ? 'is-warn' : '' ?>"><strong><?= $activeWithoutCard ?></strong><span>Active without a card</span></div>
        </div>

        <div class="rfidreg-grid">
            <section class="rfidreg-card rfidreg-form-card" aria-labelledby="register-title">
                <h2 id="register-title">Register a card</h2>
                <form id="rfidRegisterForm" action="<?= BASE_URL ?>actions/rfid-registration-save.php" method="post" autocomplete="off">
                    <input type="hidden" name="csrf_token" value="<?= $esc(csrfToken()) ?>">
                    <input type="hidden" name="action" value="assign">

                    <div class="rfidreg-step">
                        <span class="rfidreg-step-num">1</span>
                        <div class="rfidreg-step-body">
                            <label for="employeeSelect">Choose the employee</label>
                            <input type="search" id="employeeFilter" class="rfidreg-input rfidreg-input--sm" placeholder="Filter by name or employee no.">
                            <select id="employeeSelect" name="employee_id" class="rfidreg-input" size="7" required>
                                <?php foreach ($activeEmployees as $e): ?>
                                <option value="<?= (int)$e['employee_id'] ?>"
                                        data-search="<?= $esc(strtolower($e['employee_no'] . ' ' . $e['first_name'] . ' ' . $e['last_name'])) ?>"
                                        data-name="<?= $esc(trim($e['first_name'] . ' ' . $e['last_name'])) ?>"
                                        data-card="<?= $esc(maskRfidUid($e['rfid_uid'])) ?>"
                                        <?= $preselect === (int)$e['employee_id'] ? 'selected' : '' ?>>
                                    <?= $esc($e['last_name'] . ', ' . $e['first_name'] . ' — ' . $e['employee_no'] . (!empty($e['rfid_uid']) ? '  (has card)' : '')) ?>
                                </option>
                                <?php endforeach; ?>
                            </select>
                            <p class="rfidreg-hint" id="employeeHint">Only active employees are listed.</p>
                        </div>
                    </div>

                    <div class="rfidreg-step">
                        <span class="rfidreg-step-num">2</span>
                        <div class="rfidreg-step-body">
                            <label for="rfidUid">Tap the card on the reader</label>
                            <div class="rfidreg-scan">
                                <i class="fa fa-wifi"></i>
                                <input type="text" id="rfidUid" name="rfid_uid" class="rfidreg-input" maxlength="100" required
                                       placeholder="Waiting for card…" inputmode="text" spellcheck="false">
                            </div>
                            <p class="rfidreg-hint">Click the box first if tapping does nothing. You can also type the card number printed on the card.</p>
                        </div>
                    </div>

                    <button type="submit" class="rfidreg-btn rfidreg-btn--primary rfidreg-submit" id="registerSubmit"><i class="fa fa-link"></i> <span>Register card</span></button>
                </form>
            </section>

            <section class="rfidreg-card" aria-labelledby="list-title">
                <div class="rfidreg-list-head">
                    <h2 id="list-title">Employee cards</h2>
                    <div class="rfidreg-list-tools">
                        <input type="search" id="tableFilter" class="rfidreg-input rfidreg-input--sm" placeholder="Search name or ID">
                        <select id="cardFilter" class="rfidreg-input rfidreg-input--sm" aria-label="Filter by card status">
                            <option value="all">All employees</option>
                            <option value="with">With card</option>
                            <option value="without">Without card</option>
                        </select>
                    </div>
                </div>
                <div class="rfidreg-table-wrap">
                    <table class="rfidreg-table" id="cardTable">
                        <thead><tr><th>Employee</th><th>Department</th><th>Card</th><th>Registered</th><th class="ta-right">Actions</th></tr></thead>
                        <tbody>
                        <?php foreach ($employees as $e):
                            $hasCard = !empty($e['rfid_uid']);
                            $isActive = $e['employee_status'] === 'ACTIVE';
                            $name = trim($e['first_name'] . ' ' . $e['last_name']); ?>
                            <tr data-search="<?= $esc(strtolower($e['employee_no'] . ' ' . $name)) ?>" data-card="<?= $hasCard ? 'with' : 'without' ?>">
                                <td>
                                    <strong><?= $esc($e['last_name'] . ', ' . $e['first_name']) ?></strong>
                                    <small><?= $esc($e['employee_no']) ?><?= $isActive ? '' : ' · ' . $esc(ucfirst(strtolower($e['employee_status']))) ?></small>
                                </td>
                                <td><?= $esc($e['department_name'] ?? '—') ?></td>
                                <td><?= $hasCard ? '<span class="rfidreg-badge is-linked"><i class="fa fa-id-card"></i> ' . $esc(maskRfidUid($e['rfid_uid'])) . '</span>' : '<span class="rfidreg-badge">No card</span>' ?></td>
                                <td><?= $e['rfid_registered_at'] ? $esc(date('M j, Y', strtotime($e['rfid_registered_at']))) : '—' ?></td>
                                <td class="ta-right">
                                    <div class="rfidreg-actions">
                                    <?php if ($isActive): ?>
                                        <button type="button" class="rfidreg-btn rfidreg-btn--sm rfidreg-btn--ghost js-pick" data-employee-id="<?= (int)$e['employee_id'] ?>"><?= $hasCard ? 'Replace' : 'Assign' ?></button>
                                    <?php endif; ?>
                                    <?php if ($hasCard): ?>
                                        <form action="<?= BASE_URL ?>actions/rfid-registration-save.php" method="post" class="js-unlink" data-name="<?= $esc($name) ?>">
                                            <input type="hidden" name="csrf_token" value="<?= $esc(csrfToken()) ?>">
                                            <input type="hidden" name="action" value="unassign">
                                            <input type="hidden" name="employee_id" value="<?= (int)$e['employee_id'] ?>">
                                            <button type="submit" class="rfidreg-btn rfidreg-btn--sm rfidreg-btn--danger">Unlink</button>
                                        </form>
                                    <?php endif; ?>
                                    </div>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                        </tbody>
                    </table>
                    <p class="rfidreg-empty" id="tableEmpty" hidden>No employees match.</p>
                </div>
            </section>
        </div>
    </div>
    </div>
</div>
<script src="<?= BASE_URL ?>assets/js/rfid-registration.js"></script>
</body>
</html>
