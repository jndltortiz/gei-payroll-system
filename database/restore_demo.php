<?php
/**
 * database/restore_demo.php
 * Restores the database from demo_snapshot.sql via PHP CLI.
 *
 * CLI usage:  php database/restore_demo.php
 * Web usage:  http://localhost/gei-payroll-system/database/restore_demo.php
 *             (protected by token below — do NOT leave exposed without the token)
 *
 * Web token:  http://localhost/gei-payroll-system/database/restore_demo.php?token=gei2026demo
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

$snapshotFile = __DIR__ . '/demo_snapshot.sql';
$mysqlBin     = 'C:\\xampp\\mysql\\bin\\mysql.exe';
$dbHost       = 'localhost';
$dbUser       = 'root';
$dbPass       = '';
$dbName       = 'gei_payroll_system';

echo "═══════════════════════════════════════════════════\n";
echo "  GEI Payroll System — Demo Database Restore\n";
echo "  " . date('Y-m-d H:i:s') . "\n";
echo "═══════════════════════════════════════════════════\n\n";

// Pre-flight checks
if (!file_exists($snapshotFile)) {
    echo "  ✗ Snapshot not found: {$snapshotFile}\n";
    echo "    Run seed_demo.php + seed_supplement_may2026.php first,\n";
    echo "    then save a new snapshot.\n";
    exit(1);
}
if (!file_exists($mysqlBin)) {
    echo "  ✗ MySQL client not found: {$mysqlBin}\n";
    echo "    Is XAMPP installed at C:\\xampp?\n";
    exit(1);
}

echo "  Snapshot : {$snapshotFile}\n";
echo "  Database : {$dbName} @ {$dbHost}\n";
echo "  Size     : " . round(filesize($snapshotFile) / 1024, 1) . " KB\n";
echo "\n  Restoring... (this takes ~3-5 seconds)\n\n";

$passArg = $dbPass !== '' ? '-p' . escapeshellarg($dbPass) : '';
$cmd = sprintf(
    '"%s" -h %s -u %s %s %s < "%s" 2>&1',
    $mysqlBin,
    escapeshellarg($dbHost),
    escapeshellarg($dbUser),
    $passArg,
    escapeshellarg($dbName),
    $snapshotFile
);

$output   = [];
$exitCode = 0;
exec($cmd, $output, $exitCode);

if ($exitCode === 0) {
    echo "  ✓ Database restored successfully!\n\n";

    // Quick count verification (direct PDO — avoids session_start clash with config.php)
    $vPdo = new PDO("mysql:host=localhost;dbname=gei_payroll_system;charset=utf8mb4", 'root', '');
    $counts = $vPdo->query("
        SELECT
          (SELECT COUNT(*) FROM employees)         AS employees,
          (SELECT COUNT(*) FROM payroll_periods)   AS payroll_periods,
          (SELECT COUNT(*) FROM payroll_records WHERE payroll_status='RELEASED') AS released_payrolls,
          (SELECT COUNT(*) FROM attendance_records) AS attendance_records,
          (SELECT COUNT(*) FROM leave_requests)    AS leave_requests,
          (SELECT year_name FROM school_years WHERE is_active=1 LIMIT 1) AS active_sy
    ")->fetch(PDO::FETCH_ASSOC);

    echo "  --- Verification ---\n";
    foreach ($counts as $k => $v) {
        echo sprintf("  %-30s %s\n", str_replace('_',' ',ucfirst($k)).':', $v);
    }
    echo "\n  ✓ System is ready for demo.\n";
    echo "    URL: http://localhost/gei-payroll-system/\n\n";
    echo "  ACCOUNTS\n";
    echo "  ─────────────────────────────────────────\n";
    echo "  admin / r.miguel / treasurer → Admin@GEI2025\n";
    echo "  all employees                → Employee@GEI2025\n";
} else {
    echo "  ✗ Restore failed (exit code {$exitCode})\n";
    foreach ($output as $line) {
        echo "    {$line}\n";
    }
    echo "\n  Make sure XAMPP MySQL is running and try again.\n";
}

echo "\n═══════════════════════════════════════════════════\n";

if (!$isCli) {
    echo '</pre>';
}
