<?php
/**
 * Staff Action API
 * POST — assign / activate / deactivate / remove / change_role
 * Restricted to logged-in Business Owners who own the target restaurant.
 */
require_once __DIR__ . '/../../../config/app.php';
requireLogin();
requireRole([ROLE_BUSINESS_OWNER]);

header('Content-Type: application/json; charset=utf-8');

$owner_id = (int)$_SESSION['user_id'];
$action   = trim(strip_tags($_POST['action'] ?? ''));

function jsonOut(bool $ok, string $msg, array $extra = []): void {
    echo json_encode(array_merge(['success' => $ok, 'message' => $msg], $extra));
    exit;
}

// ── Helpers ───────────────────────────────────────────────────────────────────

/** Resolve a role_key to its id in restaurant_staff_roles */
function resolveRoleId(mysqli $conn, string $role_key): int {
    $s = $conn->prepare("SELECT id FROM restaurant_staff_roles WHERE role_key = ? LIMIT 1");
    $s->bind_param("s", $role_key);
    $s->execute();
    $row = $s->get_result()->fetch_assoc();
    return $row ? (int)$row['id'] : 0;
}

/** Verify the given staff record belongs to a restaurant owned by $owner_id */
function ownsStaffRecord(mysqli $conn, int $staff_id, int $owner_id): ?array {
    $s = $conn->prepare("
        SELECT rs.*, hr.business_owner_id
        FROM restaurant_staff rs
        JOIN halal_restaurants hr ON rs.restaurant_id = hr.id
        WHERE rs.id = ? AND hr.business_owner_id = ?
        LIMIT 1
    ");
    $s->bind_param("ii", $staff_id, $owner_id);
    $s->execute();
    return $s->get_result()->fetch_assoc();
}

/** Verify $owner_id owns the given restaurant */
function ownsRestaurant(mysqli $conn, int $restaurant_id, int $owner_id): bool {
    $s = $conn->prepare(
        "SELECT id FROM halal_restaurants WHERE id = ? AND business_owner_id = ? LIMIT 1"
    );
    $s->bind_param("ii", $restaurant_id, $owner_id);
    $s->execute();
    return $s->get_result()->num_rows > 0;
}

// ── Route ─────────────────────────────────────────────────────────────────────
switch ($action) {

    // ── assign ────────────────────────────────────────────────────────────────
    case 'assign': {
        $restaurant_id = (int)($_POST['restaurant_id'] ?? 0);
        $target_uid    = (int)($_POST['user_id']        ?? 0);
        $role_key      = trim(strip_tags($_POST['staff_role_key'] ?? ''));

        if (!$restaurant_id || !$target_uid || !$role_key)
            jsonOut(false, 'Missing required fields.');

        if (!ownsRestaurant($conn, $restaurant_id, $owner_id))
            jsonOut(false, 'You do not own this restaurant.');

        if ($target_uid === $owner_id)
            jsonOut(false, 'You cannot assign yourself as a staff member.');

        $role_id = resolveRoleId($conn, $role_key);
        if (!$role_id)
            jsonOut(false, 'Invalid staff role.');

        // Check not already assigned
        $dup = $conn->prepare(
            "SELECT id FROM restaurant_staff WHERE user_id = ? AND restaurant_id = ? LIMIT 1"
        );
        $dup->bind_param("ii", $target_uid, $restaurant_id);
        $dup->execute();
        if ($dup->get_result()->num_rows > 0)
            jsonOut(false, 'This user is already assigned to your restaurant.');

        // Check the target user exists and is active
        $u = $conn->prepare("SELECT id, full_name FROM users WHERE id = ? AND is_active = 1 LIMIT 1");
        $u->bind_param("i", $target_uid);
        $u->execute();
        $target_user = $u->get_result()->fetch_assoc();
        if (!$target_user)
            jsonOut(false, 'User not found or inactive.');

        $ins = $conn->prepare("INSERT INTO restaurant_staff
            (restaurant_id, user_id, staff_role_id, is_active, assigned_by)
            VALUES (?, ?, ?, 1, ?)");
        $ins->bind_param("iiii", $restaurant_id, $target_uid, $role_id, $owner_id);

        if (!$ins->execute())
            jsonOut(false, 'Database error: ' . $ins->error);

        // Notify the assigned user
        $role_label_row = $conn->query(
            "SELECT role_label FROM restaurant_staff_roles WHERE id = $role_id"
        )->fetch_assoc();
        $role_label = $role_label_row['role_label'] ?? $role_key;
        createNotification(
            $conn, $target_uid,
            'You have been assigned a restaurant role',
            "You have been assigned as {$role_label} at a restaurant. Check your dashboard.",
            'info',
            BASE_URL . 'dashboard/business_owner/owner_dashboard.php'
        );
        logActivity($conn, $owner_id, 'Staff Assigned',
            "User #{$target_uid} assigned as {$role_label} to restaurant #{$restaurant_id}",
            'staff_management');

        jsonOut(true, htmlspecialchars($target_user['full_name']) . " assigned as {$role_label}.");
    }

    // ── activate ──────────────────────────────────────────────────────────────
    case 'activate': {
        $staff_id = (int)($_POST['staff_id'] ?? 0);
        if (!$staff_id) jsonOut(false, 'Missing staff ID.');
        if (!ownsStaffRecord($conn, $staff_id, $owner_id))
            jsonOut(false, 'Permission denied.');

        $conn->prepare("UPDATE restaurant_staff SET is_active = 1 WHERE id = ?")
             ->bind_param("i", $staff_id) || jsonOut(false, 'Prepare failed.');
        $u = $conn->prepare("UPDATE restaurant_staff SET is_active = 1 WHERE id = ?");
        $u->bind_param("i", $staff_id);
        $u->execute();
        logActivity($conn, $owner_id, 'Staff Activated', "Staff record #{$staff_id}", 'staff_management');
        jsonOut(true, 'Staff member activated.');
    }

    // ── deactivate ────────────────────────────────────────────────────────────
    case 'deactivate': {
        $staff_id = (int)($_POST['staff_id'] ?? 0);
        if (!$staff_id) jsonOut(false, 'Missing staff ID.');
        if (!ownsStaffRecord($conn, $staff_id, $owner_id))
            jsonOut(false, 'Permission denied.');

        $u = $conn->prepare("UPDATE restaurant_staff SET is_active = 0 WHERE id = ?");
        $u->bind_param("i", $staff_id);
        $u->execute();
        logActivity($conn, $owner_id, 'Staff Deactivated', "Staff record #{$staff_id}", 'staff_management');
        jsonOut(true, 'Staff member deactivated.');
    }

    // ── remove ────────────────────────────────────────────────────────────────
    case 'remove': {
        $staff_id = (int)($_POST['staff_id'] ?? 0);
        if (!$staff_id) jsonOut(false, 'Missing staff ID.');
        $record = ownsStaffRecord($conn, $staff_id, $owner_id);
        if (!$record) jsonOut(false, 'Permission denied.');

        $d = $conn->prepare("DELETE FROM restaurant_staff WHERE id = ?");
        $d->bind_param("i", $staff_id);
        $d->execute();
        logActivity($conn, $owner_id, 'Staff Removed', "Staff record #{$staff_id}", 'staff_management');
        jsonOut(true, 'Staff member removed.');
    }

    // ── change_role ───────────────────────────────────────────────────────────
    case 'change_role': {
        $staff_id = (int)($_POST['staff_id']       ?? 0);
        $role_key = trim(strip_tags($_POST['staff_role_key'] ?? ''));
        if (!$staff_id || !$role_key) jsonOut(false, 'Missing fields.');
        if (!ownsStaffRecord($conn, $staff_id, $owner_id))
            jsonOut(false, 'Permission denied.');

        $role_id = resolveRoleId($conn, $role_key);
        if (!$role_id) jsonOut(false, 'Invalid role.');

        $u = $conn->prepare("UPDATE restaurant_staff SET staff_role_id = ? WHERE id = ?");
        $u->bind_param("ii", $role_id, $staff_id);
        $u->execute();
        logActivity($conn, $owner_id, 'Staff Role Changed',
            "Staff #{$staff_id} → {$role_key}", 'staff_management');
        jsonOut(true, 'Role updated.');
    }

    default:
        jsonOut(false, 'Unknown action.');
}
