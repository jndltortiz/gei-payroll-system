<?php
/**
 * database/save_demo.php
 * Saves the current database state to demo_snapshot.sql via mysqldump.
 * After saving, restore_demo will restore to THIS state (preserving current data).
 *
 * CLI usage:  php database/save_demo.php
 * Web usage:  http://localhost/gei-payroll-system/database/save_demo.php?token=gei2026demo
 */

$isCli = (PHP_SAPI === 'cli');

// ── Web guard ──────────────────────────────────────────────────────────────
if (!$isCli) {
    if (($_GET['token'] ?? '') !== 'gei2026demo') {
        http_response_code(403);
        die('<h2>403 Forbidden</h2><p>Add <code>?token=gei2026demo</code> to the URL.</p>');
    }
    echo '<pre style="font-family:monospace;font-size:14px;padding:20px;">';
}

$snapshotFile  = __DIR__ . '/demo_snapshot.sql';
$mysqldumpBin  = 'C:\\xampp\\mysql\\bin\\mysqldump.exe';
$dbHost        = 'localhost';
$dbUser        = 'root';
$dbPass        = '';
$dbName        = 'gei_payroll_system';

echo "═══════════════════════════════════════════════════\n";
echo "  GEI Payroll System — Save Demo Snapshot\n";
echo "  " . date('Y-m-d H:i:s') . "\n";
echo "═══════════════════════════════════════════════════\n\n";

if (!file_exists($mysqldumpBin)) {
    echo "  ✗ mysqldump not found: {$mysqldumpBin}\n";
    echo "    Is XAMPP installed at C:\\xampp?\n";
    exit(1);
}

echo "  Snapshot : {$snapshotFile}\n";
echo "  Database : {$dbName} @ {$dbHost}\n";
echo "\n  Saving... (this takes ~3-5 seconds)\n\n";

$passArg = $dbPass !== '' ? '-p' . escapeshellarg($dbPass) : '';
$cmd = sprintf(
    '"%s" -h %s -u %s %s --routines --triggers --single-transaction %s > "%s" 2>&1',
    $mysqldumpBin,
    escapeshellarg($dbHost),
    escapeshellarg($dbUser),
    $passArg,
    escapeshellarg($dbName),
    $snapshotFile
);

$output   = [];
$exitCode = 0;
exec($cmd, $output, $exitCode);

if ($exitCode === 0 && file_exists($snapshotFile) && filesize($snapshotFile) > 1024) {
    echo "  ✓ Snapshot saved successfully!\n";
    echo "  Size     : " . round(filesize($snapshotFile) / 1024, 1) . " KB\n\n";

    // Quick count of what was saved
    $vPdo = new PDO("mysql:host=localhost;dbname=gei_payroll_system;charset=utf8mb4", 'root', '');
    $counts = $vPdo->query("
        SELECT
          (SELECT COUNT(*) FROM employees)          AS employees,
          (SELECT COUNT(*) FROM attendance_records) AS attendance_records,
          (SELECT COUNT(*) FROM payroll_records)    AS payroll_records,
          (SELECT COUNT(*) FROM payroll_periods)    AS payroll_periods,
          (SELECT COUNT(*) FROM leave_requests)     AS leave_requests
    ")->fetch(PDO::FETCH_ASSOC);

    echo "  --- Snapshot Contents ---\n";
    foreach ($counts as $k => $v) {
        echo sprintf("  %-30s %s rows\n", str_replace('_', ' ', ucfirst($k)) . ':', $v);
    }
    echo "\n  ✓ Running restore_demo will now restore to THIS state.\n";
} else {
    echo "  ✗ Save failed (exit code {$exitCode})\n";
    foreach ($output as $line) {
        echo "    {$line}\n";
    }
    echo "\n  Make sure XAMPP MySQL is running and try again.\n";
}

echo "\n═══════════════════════════════════════════════════\n";

if (!$isCli) {
    echo '</pre>';
}
