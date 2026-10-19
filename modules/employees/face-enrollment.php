<?php
require_once __DIR__ . '/../../includes/auth.php';
requireAdminPage();
if (!in_array($_SERVER['REMOTE_ADDR'] ?? '', ['127.0.0.1', '::1'], true)) { http_response_code(403); exit('Enrollment is restricted to localhost.'); }
if (currentRole() !== 'admin') { http_response_code(403); exit('Admin access required.'); }
if ($pdo->query('SELECT DATABASE()')->fetchColumn() !== 'gei_test') { http_response_code(403); exit('Enrollment is enabled only on gei_test.'); }
$actor = $pdo->prepare("SELECT u.user_id FROM users u JOIN roles r ON r.role_id=u.role_id WHERE u.user_id=? AND u.is_active=1 AND u.must_change_password=0 AND LOWER(r.role_name)='admin'");
$actor->execute([user()['user_id']]);
if (!$actor->fetchColumn()) { http_response_code(403); exit('Active Admin account with an updated password required.'); }
header('Cache-Control: no-store');
header('X-Frame-Options: DENY');
header('Permissions-Policy: camera=(self), microphone=()');
$employees = $pdo->query('SELECT e.employee_id,e.employee_no,e.first_name,e.last_name,COUNT(f.template_id) AS samples,MAX(f.consented_at) AS consented_at FROM employees e LEFT JOIN employee_face_templates f ON f.employee_id=e.employee_id GROUP BY e.employee_id ORDER BY e.last_name,e.first_name')->fetchAll();
$token = csrfToken();
session_write_close();
?>
<!doctype html><html lang="en"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"><title>Face enrollment</title>
<style>
*{box-sizing:border-box}body{font:16px system-ui;background:#f3f5f8;color:#172234;margin:0}main{max-width:900px;margin:24px auto;padding:24px;background:white;border-radius:14px}p{line-height:1.5}label{display:block;margin:16px 0 8px}select,button{font:inherit;padding:10px;border-radius:6px}select{max-width:100%}button{background:#174bb8;color:white;border:0;margin:6px 6px 6px 0;cursor:pointer}button:disabled{opacity:.45;cursor:default}.feed{position:relative;background:#142035;min-height:120px}video{width:100%;display:block}canvas{position:absolute;inset:0;width:100%;height:100%}#status{padding:12px;background:#edf2ff}.error{color:#991b1b}small{color:#526079}li{margin:6px 0}
</style><script defer src="../../assets/vendor/face-api/face-api.min.js"></script><script defer src="../../assets/js/face-enrollment.js"></script></head>
<body><main><h1>Employee face enrollment</h1><p>Test database only. Capture 3–5 samples with small changes in pose. No attendance is recorded. Only descriptors are saved; photos and video are not uploaded.</p>
<p><a href="index.php">Back to Employees</a> | <a href="../attendance/face-kiosk.php">Face Attendance</a></p>
<p>Use only with employee consent and guard supervision. This is not liveness detection; photos or phone replays may spoof recognition. Sign out of the admin session before shared kiosk use.</p>
<form id="enrollment" action="../../actions/face-enrollment-save.php" method="post">
<input type="hidden" name="csrf_token" value="<?= htmlspecialchars($token, ENT_QUOTES, 'UTF-8') ?>">
<label for="employee">Employee</label><select id="employee" name="employee_id" required><option value="">Select an employee</option>
<?php foreach ($employees as $e): ?>
<option value="<?= (int)$e['employee_id'] ?>" data-count="<?= (int)$e['samples'] ?>" data-consent="<?= htmlspecialchars($e['consented_at'] ?? '', ENT_QUOTES, 'UTF-8') ?>"><?= htmlspecialchars($e['employee_no'].' — '.$e['last_name'].', '.$e['first_name'].' — '.($e['samples'] ? 'Enrolled ('.$e['samples'].' samples)' : 'Not enrolled'), ENT_QUOTES, 'UTF-8') ?></option>
<?php endforeach; ?></select><p id="existing">Select an employee to view enrollment status.</p>
<label for="mode">Save mode</label><select id="mode" name="mode"><option value="add">Register / add samples</option><option value="replace">Replace all existing samples for this employee</option></select>
<button id="remove" type="button" disabled>Remove enrollment</button>
<p id="status" role="status" aria-live="polite">Loading local models…</p>
<button id="start" type="button" disabled>Start camera</button><button id="capture" type="button" disabled>Capture sample</button><button id="stop" type="button" disabled>Stop camera</button><button id="clear" type="button">Clear new samples</button>
<div class="feed"><video id="video" autoplay muted playsinline></video><canvas id="overlay" aria-hidden="true"></canvas></div>
<p id="prompt">Look straight at the camera for sample 1.</p><ol id="samples"></ol>
<label><input id="consent" name="consent" type="checkbox" value="1" required> Employee has consented to biometric enrollment</label>
<small>The server records the confirming admin and consent timestamp (Asia/Manila) with each saved sample. Verify the selected employee is the person in front of the camera.</small><p><button id="save" disabled>Save enrollment</button></p></form><noscript>JavaScript is required for webcam enrollment.</noscript></main></body></html>
