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

require_once __DIR__ . '/../config/env.php';

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
$dbHost        = appEnv('DB_HOST');
$dbPort        = appEnv('DB_PORT');
$dbUser        = appEnv('DB_USERNAME');
$dbPass        = appEnv('DB_PASSWORD', '');
$dbName        = appEnv('DB_DATABASE');
$dbCharset     = appEnv('DB_CHARSET', 'utf8mb4');

echo "═══════════════════════════════════════════════════\n";
echo "  GEI Payroll System — Save Demo Snapshot\n";
echo "  " . date('Y-m-d H:i:s') . "\n";
echo "═══════════════════════════════════════════════════\n\n";

if (!file_exists($mysqldumpBin)) {
    echo "  ✗ mysqldump not found: {$mysqldumpBin}\n";
    echo "    Is XAMPP installed at C:\\xampp?\n";
    exit(1);
}
if (!$dbHost || !$dbUser || !$dbName) {
    echo "  Database environment variables are missing. Check your .env file.\n";
    exit(1);
}

echo "  Snapshot : {$snapshotFile}\n";
echo "  Database : {$dbName} @ {$dbHost}\n";
echo "\n  Saving... (this takes ~3-5 seconds)\n\n";

$passArg = $dbPass !== '' ? '-p' . escapeshellarg($dbPass) : '';
$portArg = $dbPort !== null && $dbPort !== '' ? '-P ' . escapeshellarg($dbPort) : '';
$cmd = sprintf(
    '"%s" -h %s %s -u %s %s --routines --triggers --single-transaction %s > "%s" 2>&1',
    $mysqldumpBin,
    escapeshellarg($dbHost),
    $portArg,
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
    $dsn = "mysql:host={$dbHost};dbname={$dbName};charset={$dbCharset}";
    if ($dbPort !== null && $dbPort !== '') {
        $dsn = "mysql:host={$dbHost};port={$dbPort};dbname={$dbName};charset={$dbCharset}";
    }
    $vPdo = new PDO($dsn, $dbUser, $dbPass);
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
