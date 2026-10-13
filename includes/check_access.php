<?php
/**
 * check_access.php — Lightweight RBAC Middleware
 *
 * Include at the top of any page that needs role-based access control.
 * Requires config/app.php to already be loaded (provides $conn, session, constants).
 *
 * Usage patterns:
 *
 *   // 1. Require a specific system role
 *   check_role_access([ROLE_BUSINESS_OWNER]);
 *
 *   // 2. Require a specific restaurant staff role (ops_manager or cashier)
 *   check_staff_role($conn, STAFF_ROLE_OPS_MANAGER);
 *
 *   // 3. Owner-only page — block any staff member
 *   check_owner_only();
 *
 *   // 4. Verify manager override PIN for void / modify order actions
 *   $result = check_manager_pin($conn, $restaurant_id, $pin_input);
 *   if (!$result['ok']) { /* return error ... }
 */

// ── Guard: check session role against an allowed list ────────────────────────
/**
 * Redirects to apply_role.php if the current user's system role is not
 * in $allowed_roles.
 *
 * @param int[]  $allowed_roles  Array of ROLE_* constants
 * @param string $redirect_url   Optional override redirect (default: apply_role.php)
 */
function check_role_access(array $allowed_roles, string $redirect_url = ''): void {
    if (!function_exists('isLoggedIn')) {
        die('check_access.php requires config/app.php to be loaded first.');
    }

    requireLogin();

    $current_role = (int)($_SESSION['role_id'] ?? 0);
    if (!in_array($current_role, $allowed_roles, true)) {
        $dest = $redirect_url ?: BASE_URL . 'auth/apply_role.php';
        header('Location: ' . $dest);
        exit();
    }
}

// ── Guard: restaurant staff role check ───────────────────────────────────────
/**
 * Ensures the current user holds $required_key as their restaurant staff role.
 * Actual restaurant owners (no staff_role_key in session) are always permitted.
 * All other staff are redirected to their own page.
 *
 * @param mysqli $conn
 * @param string $required_key  One of STAFF_ROLE_* constants
 */
function check_staff_role(mysqli $conn, string $required_key): void {
    requireStaffRole($conn, $required_key);
}

// ── Guard: owner-only pages ───────────────────────────────────────────────────
/**
 * Blocks any session with staff_role_key set (i.e. ops_manager / cashier).
 * Use on owner_dashboard.php, staff_applications.php, etc.
 *
 * @param string $label  Human-readable page name for redirect logic (optional)
 */
function check_owner_only(string $label = 'this page'): void {
    requireStaffAccess($label);
}

// ── Guard: manager PIN verification ──────────────────────────────────────────
/**
 * Wrapper around authorizeManagerAction() for use in action endpoints.
 *
 * Returns ['ok' => bool, 'manager_name' => string|null, 'error' => string|null]
 *
 * @param mysqli $conn
 * @param int    $restaurant_id
 * @param string $pin_input     Raw PIN from POST
 */
function check_manager_pin(mysqli $conn, int $restaurant_id, string $pin_input): array {
    return authorizeManagerAction($conn, $restaurant_id, $pin_input);
}

// ── Helper: assert a single required POST/GET field is non-empty ─────────────
/**
 * Returns trimmed value or emits a JSON error and exits if missing.
 * Useful in API endpoints that return JSON.
 *
 * @param string $field   Key in $_POST or $_GET
 * @param string $source  'post' or 'get'
 */
function require_field(string $field, string $source = 'post'): string {
    $bag = strtolower($source) === 'get' ? $_GET : $_POST;
    $val = trim($bag[$field] ?? '');
    if ($val === '') {
        header('Content-Type: application/json; charset=utf-8');
        echo json_encode(['success' => false, 'message' => "Missing required field: {$field}"]);
        exit();
    }
    return $val;
}

// ── Role matrix constants ─────────────────────────────────────────────────────
/**
 * ACCESS_MATRIX defines which pages each staff_role_key may access.
 * Keys are file basenames; values are arrays of allowed role keys
 * (null = any authenticated ROLE_BUSINESS_OWNER including actual owner).
 *
 * This is a reference table — the actual enforcement is done by the
 * guard functions above. Use it for audit or dynamic UI decisions.
 */
const ACCESS_MATRIX = [
    'owner_dashboard.php'        => [null],                          // owner only
    'staff_applications.php'     => [null],                          // owner only
    'restaurant.php'             => [null],                          // owner only
    'manage_orders.php'          => [null, 'cashier'],               // owner + cashier
    'operations_manager.php'     => [null, 'ops_manager'],           // owner + ops_manager
    'pos_order_processing.php'   => [null, 'ops_manager', 'cashier'],// all three
    'letter_of_intent.php'       => [null, 'ops_manager'],
    'applications.php'           => [null, 'ops_manager'],
    'terms_of_reference.php'     => [null, 'ops_manager'],
    'inspection_schedules.php'   => [null, 'ops_manager'],
    'audit_findings.php'         => [null, 'ops_manager'],
    'laboratory.php'             => [null, 'ops_manager'],
    'payments.php'               => [null, 'ops_manager'],
    'certificates.php'           => [null, 'ops_manager'],
    'chef_dashboard.php'         => [null, 'chef', 'ops_manager'],
];

/**
 * can_access_page — check if the current staff_role_key may view a given page.
 *
 * @param string $page_basename  e.g. 'operations_manager.php'
 * @return bool
 */
function can_access_page(string $page_basename): bool {
    $staff_key = $_SESSION['staff_role_key'] ?? null;
    if (!array_key_exists($page_basename, ACCESS_MATRIX)) return true; // not in matrix = no restriction
    return in_array($staff_key, ACCESS_MATRIX[$page_basename], true);
}
