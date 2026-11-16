<?php
// Main application bootstrap

// PHP version guard — match expressions and arrow functions require PHP 8.0+
if (PHP_VERSION_ID < 80000) {
    http_response_code(500);
    $v = PHP_VERSION;
    die(
        '<!DOCTYPE html><html><head><meta charset="utf-8">' .
        '<title>Server Configuration Error</title>' .
        '<style>body{font-family:sans-serif;max-width:600px;margin:60px auto;padding:0 20px;color:#333}' .
        'h1{color:#dc2626}code{background:#f3f4f6;padding:2px 6px;border-radius:4px;font-size:.9em}</style></head><body>' .
        '<h1>Server Configuration Error</h1>' .
        '<p>This application requires <strong>PHP 8.0 or higher</strong>.</p>' .
        '<p>Your server is running PHP <code>' . htmlspecialchars($v) . '</code>.</p>' .
        '<p>Please upgrade PHP in your hosting control panel (cPanel &rarr; MultiPHP Manager) ' .
        'and set the domain to PHP 8.1 or 8.2.</p>' .
        '</body></html>'
    );
}

// Keep sessions in a protected application-owned directory. This avoids losing
// authenticated sessions when the shared XAMPP temp directory is not writable
// by Apache, which otherwise makes valid owner API requests appear unauthorized.
$appSessionPath = dirname(__DIR__) . '/tmp/sessions';
if (!is_dir($appSessionPath)) {
    @mkdir($appSessionPath, 0700, true);
}
if (is_dir($appSessionPath) && is_writable($appSessionPath)) {
    ini_set('session.save_path', $appSessionPath);
} else {
    error_log('Halal Keeps session directory is not writable: ' . $appSessionPath);
}
ini_set('session.use_strict_mode', '1');
session_start();

// Force UTF-8 throughout: ini settings, mbstring, and HTTP headers.
// This prevents multi-byte characters (₱, –, ·, etc.) from appearing
// as Latin-1 mojibake (e.g. â€") in the browser.
ini_set('default_charset', 'UTF-8');
mb_internal_encoding('UTF-8');
mb_http_output('UTF-8');

if (!headers_sent()) {
    header('Content-Type: text/html; charset=utf-8');
}

// Load configuration
require_once __DIR__ . '/database.php';
require_once __DIR__ . '/constants.php';
require_once __DIR__ . '/../includes/functions.php';
require_once __DIR__ . '/../includes/staff_rbac.php';

// DLP Feature: Enforce Global Session Timeout
if (isset($_SESSION['user_id']) && isset($_SESSION['last_activity'])) {
    $timeout_query   = $conn->query("SELECT setting_value FROM system_settings WHERE setting_key = 'session_timeout_minutes'");
    $timeout_minutes = 30;
    if ($timeout_query) {
        if ($timeout_query->num_rows > 0) {
            $timeout_minutes = intval($timeout_query->fetch_assoc()['setting_value']);
        }
        $timeout_query->free();
    }

    if (time() - $_SESSION['last_activity'] > ($timeout_minutes * 60)) {
        session_unset();
        session_destroy();
        header("Location: " . BASE_URL . "auth/login.php?error=" . urlencode("Your session has expired due to inactivity."));
        exit();
    }
}
// Update last activity time
if (isset($_SESSION['user_id'])) {
    $_SESSION['last_activity'] = time();
}

// ── Global staff role key refresh ────────────────────────────────────────────
// Re-resolve on every request for ROLE_BUSINESS_OWNER so staff members always
// have the correct role key regardless of session age.
if (isset($_SESSION['user_id']) && (int)($_SESSION['role_id'] ?? 0) === ROLE_BUSINESS_OWNER) {
    $uid_refresh = (int)$_SESSION['user_id'];
    $sr_refresh  = $conn->prepare("
        SELECT rsr.role_key
        FROM restaurant_staff rs
        JOIN restaurant_staff_roles rsr ON rs.staff_role_id = rsr.id
        WHERE rs.user_id = ? AND rs.is_active = 1
        LIMIT 1
    ");
    if ($sr_refresh) {
        $sr_refresh->bind_param("i", $uid_refresh);
        $sr_refresh->execute();
        $sr_row = $sr_refresh->get_result()->fetch_assoc();

        $prev_key = $_SESSION['staff_role_key'] ?? null;
        $new_key  = $sr_row ? $sr_row['role_key'] : null;
        $_SESSION['staff_role_key'] = $new_key;
        $sr_refresh->close();

        // Staff member was just deactivated (had a role key, now has none).
        // Redirect them to the Halal Marketplace (customer restaurants page)
        // so they don't stay stuck on their now-inaccessible staff interface.
        if ($prev_key !== null && $new_key === null) {
            $_SESSION['flash_message'] = 'Your staff access has been deactivated. You have been returned to the Halal Marketplace.';
            $_SESSION['flash_type']    = 'info';
            header('Location: ' . BASE_URL . 'dashboard/customer/restaurants.php');
            exit();
        }
    }
}

// Create upload directories if they don't exist
$upload_dirs = [
    UPLOAD_DIR,
    UPLOAD_DIR . 'avatars/',
    UPLOAD_DIR . 'documents/',
    UPLOAD_DIR . 'certificates/',
    UPLOAD_DIR . 'laboratory/',
    UPLOAD_DIR . 'role_applications/',
    UPLOAD_DIR . 'restaurant_images/',
    UPLOAD_DIR . 'menu_images/',
    UPLOAD_DIR . 'receipts/',
    UPLOAD_DIR . 'staff_applications/',
    // Business application uploads — branch sub-dirs are created per-upload
    // but the parent dirs must exist first so mkdir(recursive) can write them.
    UPLOAD_DIR . 'business_docs/',
    UPLOAD_DIR . 'products/',
];

foreach ($upload_dirs as $dir) {
    if (!is_dir($dir)) {
        mkdir($dir, 0755, true);
    }
}
?>
