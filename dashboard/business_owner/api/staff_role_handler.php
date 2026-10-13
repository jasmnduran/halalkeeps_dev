<?php
/**
 * Staff Role Handler
 * Manages restaurant_staff_roles for a specific restaurant.
 *
 * Actions (POST):
 *   update_role  — edit role_label / description (platform + custom roles)
 *   create_role  — create a new custom role scoped to this restaurant
 *   delete_role  — delete a custom role (only if no active staff assigned)
 */
ob_start();
require_once __DIR__ . '/../../../config/app.php';
requireLogin();
requireRole([ROLE_BUSINESS_OWNER]);
requireStaffAccess('Staff Role Handler');

header('Content-Type: application/json; charset=utf-8');

function json_out(bool $ok, string $msg, array $extra = []): void {
    if (ob_get_length()) ob_clean();
    echo json_encode(array_merge(['success' => $ok, 'message' => $msg], $extra));
    exit();
}

$user_id = (int)$_SESSION['user_id'];
$rest_id = getSessionRestaurantId($conn);

if (!$rest_id || !isRestaurantOwner($conn, $user_id, $rest_id)) {
    json_out(false, 'Not authorized.');
}

// Ensure the schema migration has run (getActiveBusinessRoles handles this,
// but the handler may be called independently via AJAX before the page loads).
getActiveBusinessRoles($conn, $rest_id);

$action = trim($_POST['action'] ?? '');

try {
    switch ($action) {

        // ── Edit label / description ─────────────────────────────────────
        case 'update_role': {
            $role_id     = (int)($_POST['role_id']     ?? 0);
            $role_label  = trim($_POST['role_label']   ?? '');
            $description = trim($_POST['description']  ?? '');

            if ($role_id <= 0)                   json_out(false, 'Invalid role ID.');
            if ($role_label === '')               json_out(false, 'Display name is required.');
            if (mb_strlen($role_label) > 60)     json_out(false, 'Display name must be 60 characters or less.');

            // Verify the role exists AND (is a platform role OR belongs to this restaurant)
            $chk = $conn->prepare("
                SELECT id, is_custom, restaurant_id
                FROM   restaurant_staff_roles
                WHERE  id = ?
                LIMIT  1
            ");
            $chk->bind_param("i", $role_id);
            $chk->execute();
            $role_row = $chk->get_result()->fetch_assoc();
            $chk->close();

            if (!$role_row) {
                json_out(false, 'Role not found.');
            }
            // Custom roles can only be edited by their owning restaurant
            if ((int)$role_row['is_custom'] === 1 && (int)$role_row['restaurant_id'] !== $rest_id) {
                json_out(false, 'You do not have permission to edit this role.');
            }

            $stmt = $conn->prepare("
                UPDATE restaurant_staff_roles
                SET    role_label = ?, description = ?
                WHERE  id = ?
            ");
            $stmt->bind_param("ssi", $role_label, $description, $role_id);
            if (!$stmt->execute()) {
                json_out(false, 'Database error: ' . $stmt->error);
            }
            $stmt->close();

            // affected_rows is 0 when values didn't change — that's still a success
            logStaffAction($conn, $rest_id, 'update_role', 'role', $role_id, $user_id, [
                'role_label'  => $role_label,
                'description' => $description,
            ]);

            json_out(true, 'Role updated successfully.');
        }

        // ── Create a new custom role ──────────────────────────────────────
        case 'create_role': {
            $role_label  = trim($_POST['role_label']  ?? '');
            $description = trim($_POST['description'] ?? '');

            if ($role_label === '')           json_out(false, 'Display name is required.');
            if (mb_strlen($role_label) > 60) json_out(false, 'Display name must be 60 characters or less.');

            // Limit: max 10 custom roles per restaurant to avoid abuse
            $cnt = $conn->prepare("
                SELECT COUNT(*) AS n FROM restaurant_staff_roles
                WHERE  is_custom = 1 AND restaurant_id = ?
            ");
            $cnt->bind_param("i", $rest_id);
            $cnt->execute();
            $n = (int)$cnt->get_result()->fetch_assoc()['n'];
            $cnt->close();
            if ($n >= 10) {
                json_out(false, 'Maximum of 10 custom roles per restaurant reached.');
            }

            // Generate a unique machine key from the label
            // e.g. "Prep Cook" → "prep_cook_7" (suffix = rest_id keeps it unique)
            $base_key = preg_replace('/[^a-z0-9]+/', '_',
                            strtolower(trim(preg_replace('/\s+/', '_', $role_label))));
            $base_key = trim($base_key, '_');
            $base_key = substr($base_key, 0, 20);          // keep the key short
            $role_key = $base_key . '_' . $rest_id;

            // Ensure uniqueness within this restaurant
            $dup = $conn->prepare("
                SELECT id FROM restaurant_staff_roles
                WHERE  role_key = ? AND restaurant_id = ? LIMIT 1
            ");
            $dup->bind_param("si", $role_key, $rest_id);
            $dup->execute();
            if ($dup->get_result()->fetch_assoc()) {
                // Append a counter suffix to resolve collision
                $role_key .= '_' . time();
            }
            $dup->close();

            $ins = $conn->prepare("
                INSERT INTO restaurant_staff_roles
                    (role_key, role_label, description, restaurant_id, is_custom)
                VALUES (?, ?, ?, ?, 1)
                ON DUPLICATE KEY UPDATE
                    role_label  = VALUES(role_label),
                    description = VALUES(description)
            ");
            $ins->bind_param("sssi", $role_key, $role_label, $description, $rest_id);
            if (!$ins->execute()) {
                json_out(false, 'Database error: ' . $ins->error);
            }
            $new_id = (int)$conn->insert_id;
            $ins->close();

            logStaffAction($conn, $rest_id, 'create_role', 'role', $new_id, $user_id, [
                'role_key'    => $role_key,
                'role_label'  => $role_label,
                'description' => $description,
            ]);

            json_out(true, 'Custom role created.', [
                'role' => [
                    'id'          => $new_id,
                    'role_key'    => $role_key,
                    'role_label'  => $role_label,
                    'description' => $description,
                    'is_custom'   => 1,
                ],
            ]);
        }

        // ── Delete a custom role ──────────────────────────────────────────
        case 'delete_role': {
            $role_id = (int)($_POST['role_id'] ?? 0);
            if ($role_id <= 0) json_out(false, 'Invalid role ID.');

            // Fetch the role — works for both platform and custom
            $chk = $conn->prepare("
                SELECT id, role_key, is_custom, restaurant_id
                FROM   restaurant_staff_roles
                WHERE  id = ? LIMIT 1
            ");
            $chk->bind_param("i", $role_id);
            $chk->execute();
            $role_row = $chk->get_result()->fetch_assoc();
            $chk->close();

            if (!$role_row) {
                json_out(false, 'Role not found.');
            }

            // The owner role can never be deleted
            if ($role_row['role_key'] === 'owner') {
                json_out(false, 'The Owner role cannot be deleted.');
            }

            // Custom roles must belong to this restaurant
            if ((int)$role_row['is_custom'] === 1 && (int)$role_row['restaurant_id'] !== $rest_id) {
                json_out(false, 'You do not have permission to delete this role.');
            }

            // Block deletion if any active staff are assigned
            $staff_chk = $conn->prepare("
                SELECT COUNT(*) AS n FROM restaurant_staff
                WHERE  staff_role_id = ? AND restaurant_id = ? AND is_active = 1
            ");
            $staff_chk->bind_param("ii", $role_id, $rest_id);
            $staff_chk->execute();
            $assigned = (int)$staff_chk->get_result()->fetch_assoc()['n'];
            $staff_chk->close();

            if ($assigned > 0) {
                json_out(false, "Cannot delete: {$assigned} active staff member(s) are assigned to this role. Deactivate or reassign them first.");
            }

            $del = $conn->prepare("DELETE FROM restaurant_staff_roles WHERE id = ?");
            $del->bind_param("i", $role_id);
            if (!$del->execute() || $del->affected_rows === 0) {
                json_out(false, 'Delete failed.');
            }
            $del->close();

            logStaffAction($conn, $rest_id, 'delete_role', 'role', $role_id, $user_id, [
                'role_key'  => $role_row['role_key'],
                'is_custom' => $role_row['is_custom'],
            ]);

            json_out(true, 'Role deleted successfully.');
        }

        default:
            json_out(false, 'Unknown action: ' . htmlspecialchars($action));
    }
} catch (Throwable $e) {
    json_out(false, 'Error: ' . $e->getMessage());
}
