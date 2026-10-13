<?php
/**
 * One-time migration: normalize empty/null laboratory_requests.status -> 'submitted'
 * Usage (recommended CLI):
 *   php database_migrations/normalize_lab_status.php confirm
 * Or via browser (less secure) after backing up DB:
 *   http://localhost/halal_final/database_migrations/normalize_lab_status.php?confirm=1
 */

require_once __DIR__ . '/../config/app.php';

$confirmed = false;
if (php_sapi_name() === 'cli') {
    global $argv;
    $confirmed = in_array('confirm', $argv, true);
} else {
    $confirmed = isset($_GET['confirm']) && $_GET['confirm'] === '1';
}

if (!$confirmed) {
    echo "WARNING: This migration will set empty or NULL laboratory_requests.status to 'submitted'.\n";
    echo "Backup your database before proceeding.\n\n";
    echo "Run this script with confirmation:\n";
    echo "  php database_migrations/normalize_lab_status.php confirm\n\n";
    echo "Or open in your browser with ?confirm=1 after taking a DB backup.\n";
    exit(0);
}

// Count records to update
$count_q = $conn->query("SELECT COUNT(*) AS c FROM laboratory_requests WHERE status IS NULL OR TRIM(status) = ''");
$c_row = $count_q ? $count_q->fetch_assoc() : null;
$to_update = intval($c_row['c'] ?? 0);

if ($to_update === 0) {
    echo "No laboratory_requests rows with empty/null status found. Nothing to do.\n";
    exit(0);
}

// Perform update inside transaction
$conn->begin_transaction();
try {
    $res = $conn->query("UPDATE laboratory_requests SET status = 'submitted' WHERE status IS NULL OR TRIM(status) = ''");
    $affected = $conn->affected_rows;
    $conn->commit();

    $log = '[' . date('Y-m-d H:i:s') . "] Normalized lab request statuses. Affected rows: " . intval($affected) . "\n";
    file_put_contents(__DIR__ . '/normalize_lab_status.log', $log, FILE_APPEND | LOCK_EX);

    echo "Migration completed. Affected rows: " . intval($affected) . "\n";
    echo "Log written to database_migrations/normalize_lab_status.log\n";
} catch (Exception $e) {
    $conn->rollback();
    $err = $e->getMessage();
    file_put_contents(__DIR__ . '/normalize_lab_status.log', '[' . date('Y-m-d H:i:s') . "] ERROR: " . $err . "\n", FILE_APPEND | LOCK_EX);
    echo "Migration failed: " . $err . "\n";
    exit(1);
}

?>
