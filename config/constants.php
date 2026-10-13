<?php
// Load .env values before anything else (env.php is idempotent — safe to call
// multiple times; it checks require_once internally via the function guard).
require_once __DIR__ . '/env.php';

// ============================================================================
// Site Identity
// ============================================================================
define('SITE_NAME',    'Halal Keeps');
define('SITE_TAGLINE', 'Verify Halal, Anytime, Anywhere');

// ============================================================================
// BASE_URL
// ============================================================================
// Resolution order:
//   1. If APP_SUBFOLDER is set in .env  → use it      (recommended for local)
//   2. Auto-detect from HTTP_HOST       → localhost/127.x/192.168.x subfolder
//   3. Production fallback              → domain root
//
// On localhost with XAMPP the project lives at:
//   http://localhost/<APP_SUBFOLDER>/
// On production it lives at the domain root:
//   https://yourdomain.com/
// ============================================================================
if (!defined('BASE_URL')) {
    $isHttps = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off')
               || (isset($_SERVER['SERVER_PORT']) && (int)$_SERVER['SERVER_PORT'] === 443)
               || (!empty($_SERVER['HTTP_X_FORWARDED_PROTO']) && $_SERVER['HTTP_X_FORWARDED_PROTO'] === 'https');
    $scheme = $isHttps ? 'https' : 'http';
    $host   = $_SERVER['HTTP_HOST'] ?? 'localhost';

    $envSubfolder = trim((string)(getenv('APP_SUBFOLDER') ?: ''), '/');

    $isLocal = ($host === 'localhost'
        || str_starts_with($host, '127.')
        || str_starts_with($host, '192.168.')
        || str_starts_with($host, '10.')
        || str_ends_with($host, '.local')
        || str_ends_with($host, '.test'));

    if ($isLocal && $envSubfolder !== '') {
        // Explicit subfolder from .env  →  http://localhost/halal_final/
        define('BASE_URL', $scheme . '://' . $host . '/' . $envSubfolder . '/');
    } elseif ($isLocal && $envSubfolder === '') {
        // Local but no .env subfolder — derive from the script path so it
        // works even without a .env file (zero-config fallback).
        // e.g. /halal_final/config/constants.php  →  /halal_final/
        $scriptDir  = str_replace('\\', '/', dirname(dirname(__FILE__)));
        $docRoot    = str_replace('\\', '/', ($_SERVER['DOCUMENT_ROOT'] ?? ''));
        $subpath    = ltrim(str_replace($docRoot, '', $scriptDir), '/');
        $subpath    = $subpath !== '' ? '/' . $subpath . '/' : '/';
        define('BASE_URL', $scheme . '://' . $host . $subpath);
    } else {
        // Production — app is at the domain root
        define('BASE_URL', $scheme . '://' . $host . '/');
    }
}

// ============================================================================
// File System Paths
// ============================================================================
// __DIR__ is always the real absolute path on disk, so this works on any
// machine regardless of where XAMPP is installed (C:\xampp, D:\xampp, etc.)
define('UPLOAD_DIR', dirname(__DIR__) . '/uploads/');

define('MAX_FILE_SIZE', 20 * 1024 * 1024); // 20 MB

// ============================================================================
// Google OAuth  — credentials from .env
// ============================================================================
define('GOOGLE_CLIENT_ID',     getenv('GOOGLE_CLIENT_ID')     ?: '');
define('GOOGLE_CLIENT_SECRET', getenv('GOOGLE_CLIENT_SECRET') ?: '');
// Redirect URI always derives from BASE_URL so it is correct on every env
define('GOOGLE_REDIRECT_URI',  BASE_URL . 'auth/google_callback.php');

// ============================================================================
// PayMongo  — credentials from .env
// ============================================================================
if (!defined('PAYMONGO_SECRET_KEY')) {
    define('PAYMONGO_SECRET_KEY', getenv('PAYMONGO_SECRET_KEY') ?: '');
}
if (!defined('PAYMONGO_PUBLIC_KEY')) {
    define('PAYMONGO_PUBLIC_KEY', getenv('PAYMONGO_PUBLIC_KEY') ?: '');
}

// ============================================================================
// Role IDs  (must match the `roles` table)
// ============================================================================
define('ROLE_CUSTOMER',            1);
define('ROLE_BUSINESS_OWNER',      2);
define('ROLE_EVALUATOR',           3);
define('ROLE_AUDITOR_TECHNICAL',   4);
define('ROLE_AUDITOR_SHARIAH',     5);
define('ROLE_IMPARTIAL_COMMITTEE', 6);
define('ROLE_DECISION_COMMITTEE',  7);
define('ROLE_PRESIDENT',           8);
define('ROLE_RECEIVING_OFFICER',   9);
define('ROLE_LABORATORY_ANALYST',  10);
define('ROLE_LAB_ANALYST',         10); // alias
define('ROLE_ADMIN',               11);

// ============================================================================
// Restaurant Staff Role Keys  (stored in restaurant_staff_roles.role_key)
// These are per-restaurant roles, NOT system-wide role_ids.
// Use these constants when reading/writing restaurant_staff.staff_role_id.
// ============================================================================
define('STAFF_ROLE_OWNER',       'owner');
define('STAFF_ROLE_OPS_MANAGER', 'ops_manager');
define('STAFF_ROLE_CASHIER',     'cashier');
define('STAFF_ROLE_CHEF',        'chef');

// Pages each restaurant staff role is allowed to access (used by requireStaffRole())
define('STAFF_ROLE_PAGES', [
    STAFF_ROLE_OWNER       => ['owner_dashboard.php'],
    STAFF_ROLE_OPS_MANAGER => ['operations_manager.php'],
    STAFF_ROLE_CASHIER     => ['pos_order_processing.php'],
    STAFF_ROLE_CHEF        => ['chef_dashboard.php'],
]);

// ============================================================================
// Application Status Constants
// ============================================================================
define('STATUS_DRAFT',        'draft');
define('STATUS_SUBMITTED',    'submitted');
define('STATUS_UNDER_REVIEW', 'under_review');
define('STATUS_VERIFIED',     'verified');
define('STATUS_RETURNED',     'returned');
define('STATUS_REJECTED',     'rejected');
define('STATUS_APPROVED',     'approved');

// ============================================================================
// File Uploads
// ============================================================================
define('ALLOWED_FILE_TYPES',  ['pdf', 'doc', 'docx', 'jpg', 'jpeg', 'png', 'gif']);
define('ALLOWED_IMAGE_TYPES', ['jpg', 'jpeg', 'png', 'gif', 'webp']);

// ============================================================================
// Security
// ============================================================================
define('MAX_LOGIN_ATTEMPTS', 5);
define('LOCKOUT_DURATION',   900);   // 15 minutes in seconds
define('SESSION_TIMEOUT',    1800);  // 30 minutes in seconds

// Encryption key for sensitive fields — always read from .env, never hardcoded
define('FIELD_ENCRYPT_KEY', getenv('FIELD_ENCRYPT_KEY') ?: 'change-me-in-dot-env');

// ============================================================================
// Data Classification Levels
// ============================================================================
define('DATA_PUBLIC',       'public');
define('DATA_INTERNAL',     'internal');
define('DATA_CONFIDENTIAL', 'confidential');
define('DATA_RESTRICTED',   'restricted');

// ============================================================================
// Feature Flags
// ============================================================================
define('FEATURE_MENU_UI', true);

// ============================================================================
// Menu Item Categories  (used by add/update menu item APIs and the JS form builder)
// ============================================================================
define('MENU_ITEM_CATEGORIES', [
    'Appetizer',
    'Dessert',
    'House Specialty',
    'Main Course',
    'Noodles (Chicken, Seafood)',
    'Rice',
    'Rice Toppings',
    'Sandwiches',
    'Soups',
    'Steamed Dumplings',
    'Vegetables',
]);

// ============================================================================
// Menu Serving Types  (shared by add/update API and the JS form builder)
// ============================================================================
define('ALLOWED_SERVING_TYPES', ['Per serving', 'For sharing']);

// ============================================================================
// Expense Categories  (shared by the expense form and server-side validation)
// Each entry: [ icon_class, text_color, bg_color ]
// ============================================================================
define('EXPENSE_CATEGORIES', [
    'Ingredients' => ['fas fa-carrot',    '#16a34a', '#dcfce7'],
    'Utilities'   => ['fas fa-bolt',      '#d97706', '#fef9c3'],
    'Supplies'    => ['fas fa-boxes',     '#0891b2', '#e0f2fe'],
    'Wages'       => ['fas fa-users',     '#7c3aed', '#ede9fe'],
    'Maintenance' => ['fas fa-wrench',    '#dc2626', '#fee2e2'],
    'Marketing'   => ['fas fa-bullhorn',  '#db2777', '#fce7f3'],
    'Transport'   => ['fas fa-truck',     '#2563eb', '#eff6ff'],
    'Rent'        => ['fas fa-building',  '#b45309', '#fef3c7'],
    'Other'       => ['fas fa-ellipsis-h','#64748b', '#f1f5f9'],
]);

// ============================================================================
// Expense Period Tags  (key => display label used for filter bar and <select>)
// ============================================================================
define('EXPENSE_PERIODS', [
    'daily'   => 'Today',
    'weekly'  => 'This Week',
    'monthly' => 'This Month',
]);

// ============================================================================
// Cuisine Types  (used by restaurant profile form and server-side validation)
// ============================================================================
define('CUISINE_TYPES', [
    'Filipino', 'Middle Eastern', 'Indian', 'Malaysian', 'Indonesian',
    'Turkish', 'Mediterranean', 'Asian Fusion', 'Seafood', 'Fast Food', 'Other',
]);

// ============================================================================
// Restaurant Service Types  (used by restaurant profile form and validation)
// ============================================================================
define('SERVICE_TYPES', ['Dine-in', 'Takeout', 'Delivery']);

// ============================================================================
// Other Inflow Types  (used by operations_manager other_inflows tab)
// Each entry: [ icon_class, text_color, bg_color, display_label ]
// 'catering' is special: ops manager self-approves on creation.
// All other types require explicit approval before counting in revenue.
// ============================================================================
define('INFLOW_TYPES', [
    'catering'    => ['fas fa-concierge-bell',     '#7c3aed', '#ede9fe', 'Catering Order'],
    'event'       => ['fas fa-calendar-star',       '#0891b2', '#e0f2fe', 'Event / Reservation'],
    'grant'       => ['fas fa-hand-holding-heart',  '#15803d', '#dcfce7', 'Grant / Aid'],
    'sponsorship' => ['fas fa-handshake',           '#d97706', '#fef9c3', 'Sponsorship'],
    'donation'    => ['fas fa-gift',                '#db2777', '#fce7f3', 'Donation'],
    'rental'      => ['fas fa-building',            '#b45309', '#fef3c7', 'Space Rental'],
    'rebate'      => ['fas fa-tags',                '#2563eb', '#eff6ff', 'Supplier Rebate'],
    'other'       => ['fas fa-ellipsis-h',          '#64748b', '#f1f5f9', 'Other'],
]);


// ============================================================================
// PayMongo Payment Gateway Configuration
// ============================================================================
// Get PayMongo keys from .env or set defaults for testing
// PRODUCTION: Always use environment variables, never hardcode keys!
//
// Test Keys (for development only):
//   Secret: sk_test_...
//   Public: pk_test_...
// 
// Live Keys (production):
//   Secret: sk_live_...
//   Public: pk_live_...

if (!defined('PAYMONGO_SECRET_KEY')) {
    define('PAYMONGO_SECRET_KEY', getenv('PAYMONGO_SECRET_KEY') ?: '');
}
if (!defined('PAYMONGO_PUBLIC_KEY')) {
    define('PAYMONGO_PUBLIC_KEY', getenv('PAYMONGO_PUBLIC_KEY') ?: '');
}
define('PAYMONGO_WEBHOOK_SECRET', getenv('PAYMONGO_WEBHOOK_SECRET') ?: '');

// PayMongo API Base URL
define('PAYMONGO_API_URL', 'https://api.paymongo.com/v1');

// Payment method configuration
define('PAYMONGO_ENABLED_METHODS', ['gcash', 'paymaya']); // paymaya = bank transfer
define('DELIVERY_REQUIRES_ONLINE_PAYMENT', true); // Force GCash/Bank for delivery orders
