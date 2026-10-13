<?php
// Load .env values into getenv() before reading any config
require_once __DIR__ . '/env.php';

// Database credentials — all read from .env (or OS/server environment).
// Fallbacks are XAMPP defaults so the app works out of the box locally
// even if .env hasn't been created yet.
$db_host     = getenv('DB_HOST') ?: '127.0.0.1';
$db_port     = (int)(getenv('DB_PORT') ?: 3306);
$db_username = getenv('DB_USER') ?: 'root';
$db_password = getenv('DB_PASS') ?: '';
$db_name     = getenv('DB_NAME') ?: 'halal_system';

// Create connection — include port when non-standard
$db_host_str = ($db_port !== 3306) ? "$db_host:$db_port" : $db_host;
$conn = new mysqli($db_host_str, $db_username, $db_password, $db_name);

if ($conn->connect_error) {
    // Show a friendly message instead of exposing credentials
    $env = getenv('APP_ENV') ?: 'production';
    if ($env === 'local') {
        die('<b>Database connection failed:</b> ' . htmlspecialchars($conn->connect_error)
            . '<br><small>Check DB_HOST / DB_USER / DB_PASS / DB_NAME in your .env file.</small>');
    } else {
        die('Service temporarily unavailable. Please try again later.');
    }
}

$conn->set_charset('utf8mb4');

/**
 * db_add_column — MySQL 5.6/5.7-safe equivalent of ADD COLUMN IF NOT EXISTS.
 *
 * Checks information_schema before issuing ALTER TABLE, so it is a no-op when
 * the column already exists. The $after parameter maps to AFTER `col`; pass
 * null (default) to append at the end of the table.
 *
 * @param mysqli $conn
 * @param string $table      Table name (no backticks)
 * @param string $column     Column name (no backticks)
 * @param string $definition Column type + constraints, e.g. "VARCHAR(255) DEFAULT NULL"
 * @param string|null $after  Column to insert after, or null to append
 */
function db_add_column(mysqli $conn, string $table, string $column, string $definition, ?string $after = null): void
{
    $db   = $conn->query("SELECT DATABASE()")->fetch_row()[0];
    $stmt = $conn->prepare(
        "SELECT COUNT(*) FROM information_schema.COLUMNS
         WHERE TABLE_SCHEMA = ? AND TABLE_NAME = ? AND COLUMN_NAME = ?"
    );
    $stmt->bind_param('sss', $db, $table, $column);
    $stmt->execute();
    if ((int)$stmt->get_result()->fetch_row()[0] > 0) return; // already exists

    $after_clause = $after ? " AFTER `$after`" : '';
    $conn->query("ALTER TABLE `$table` ADD COLUMN `$column` $definition$after_clause");
}

/**
 * db_add_unique_key — MySQL 5.6/5.7-safe equivalent of ADD UNIQUE KEY IF NOT EXISTS.
 *
 * Checks information_schema.STATISTICS before issuing ALTER TABLE.
 *
 * @param mysqli   $conn
 * @param string   $table    Table name (no backticks)
 * @param string   $keyName  Index name (no backticks)
 * @param string[] $columns  Ordered list of column names that form the key
 */
function db_add_unique_key(mysqli $conn, string $table, string $keyName, array $columns): void
{
    $db   = $conn->query("SELECT DATABASE()")->fetch_row()[0];
    $stmt = $conn->prepare(
        "SELECT COUNT(*) FROM information_schema.STATISTICS
         WHERE TABLE_SCHEMA = ? AND TABLE_NAME = ? AND INDEX_NAME = ?"
    );
    $stmt->bind_param('sss', $db, $table, $keyName);
    $stmt->execute();
    if ((int)$stmt->get_result()->fetch_row()[0] > 0) return; // already exists

    $cols = implode(', ', array_map(function($c) { return "`$c`"; }, $columns));
    $conn->query("ALTER TABLE `$table` ADD UNIQUE KEY `$keyName` ($cols)");
}

/**
 * db_drop_fk — safely drops a foreign key only if it exists.
 * Avoids a thrown exception on hosts with mysqli strict error reporting.
 */
function db_drop_fk(mysqli $conn, string $table, string $fkName): void
{
    $db   = $conn->query("SELECT DATABASE()")->fetch_row()[0];
    $stmt = $conn->prepare(
        "SELECT COUNT(*) FROM information_schema.TABLE_CONSTRAINTS
         WHERE TABLE_SCHEMA = ? AND TABLE_NAME = ? AND CONSTRAINT_NAME = ?
         AND CONSTRAINT_TYPE = 'FOREIGN KEY'"
    );
    $stmt->bind_param('sss', $db, $table, $fkName);
    $stmt->execute();
    if ((int)$stmt->get_result()->fetch_row()[0] === 0) return;
    $conn->query("ALTER TABLE `$table` DROP FOREIGN KEY `$fkName`");
}

/**
 * db_add_fk — adds a foreign key only if it does not already exist.
 */
function db_add_fk(mysqli $conn, string $table, string $fkName,
                   string $column, string $refTable, string $refColumn,
                   string $onDelete = 'RESTRICT'): void
{
    $db   = $conn->query("SELECT DATABASE()")->fetch_row()[0];
    $stmt = $conn->prepare(
        "SELECT COUNT(*) FROM information_schema.TABLE_CONSTRAINTS
         WHERE TABLE_SCHEMA = ? AND TABLE_NAME = ? AND CONSTRAINT_NAME = ?
         AND CONSTRAINT_TYPE = 'FOREIGN KEY'"
    );
    $stmt->bind_param('sss', $db, $table, $fkName);
    $stmt->execute();
    if ((int)$stmt->get_result()->fetch_row()[0] > 0) return;
    $conn->query("ALTER TABLE `$table`
        ADD CONSTRAINT `$fkName` FOREIGN KEY (`$column`)
        REFERENCES `$refTable` (`$refColumn`) ON DELETE $onDelete");
}
