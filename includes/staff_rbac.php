<?php
/**
 * Staff RBAC helpers
 *
 * Works with the existing tables:
 *   restaurant_staff_roles  (id, role_key, role_label, description)
 *   restaurant_staff        (id, restaurant_id, user_id, staff_role_id, is_active, assigned_by, assigned_at)
 *   staff_applications      (id, user_id, restaurant_id, applied_role, application_type,
 *                            cover_letter_text, file_*, status, owner_notes, reviewed_by, reviewed_at)
 *
 * No new tables are required — all functions fall back gracefully when optional
 * columns (manager_pin, etc.) are absent.
 */

// ─────────────────────────────────────────────────────────────────────────────
// PERMISSION HELPERS
// ─────────────────────────────────────────────────────────────────────────────

/**
 * Return true if $reviewer_user_id is the business owner of $restaurant_id.
 * This is the only hard permission gate needed for the owner dashboard.
 */
function isRestaurantOwner(mysqli $conn, int $reviewer_user_id, int $restaurant_id): bool {
    $stmt = $conn->prepare(
        "SELECT 1 FROM halal_restaurants WHERE id = ? AND business_owner_id = ? LIMIT 1"
    );
    $stmt->bind_param("ii", $restaurant_id, $reviewer_user_id);
    $stmt->execute();
    return $stmt->get_result()->num_rows > 0;
}

/**
 * Return all pending staff_applications rows visible to $reviewer_user_id
 * for the given restaurant.  Only the business owner can see them.
 */
function getVisiblePendingApplications(mysqli $conn, int $reviewer_user_id, int $restaurant_id): array {
    if (!isRestaurantOwner($conn, $reviewer_user_id, $restaurant_id)) {
        return [];
    }

    $stmt = $conn->prepare("
        SELECT sa.*,
               u.full_name, u.email, u.phone,
               rsr.role_label
        FROM   staff_applications sa
        JOIN   users u   ON sa.user_id = u.id
        LEFT JOIN restaurant_staff_roles rsr ON rsr.role_key = sa.applied_role
        WHERE  sa.restaurant_id = ?
          AND  sa.status = 'pending'
        ORDER  BY sa.created_at DESC
    ");
    $stmt->bind_param("i", $restaurant_id);
    $stmt->execute();
    return $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
}

/**
 * Return all active restaurant_staff rows (with user + role details) for a restaurant.
 */
function getActiveStaffAssignments(mysqli $conn, int $restaurant_id): array {
    $stmt = $conn->prepare("
        SELECT rs.*,
               u.full_name, u.email, u.phone,
               rsr.role_key, rsr.role_label, rsr.description AS role_description
        FROM   restaurant_staff rs
        JOIN   users u   ON rs.user_id = u.id
        JOIN   restaurant_staff_roles rsr ON rs.staff_role_id = rsr.id
        WHERE  rs.restaurant_id = ?
          AND  rs.is_active = 1
        ORDER  BY rsr.role_label, u.full_name
    ");
    $stmt->bind_param("i", $restaurant_id);
    $stmt->execute();
    return $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
}

/**
 * Return all roles visible to a restaurant:
 *   - All platform-wide roles (restaurant_id IS NULL, is_custom = 0)
 *   - Custom roles created by this specific restaurant (restaurant_id = $restaurant_id, is_custom = 1)
 *
 * Platform roles come first (ordered by their natural insertion order so
 * owner → ops_manager → cashier → chef stays stable), followed by custom
 * roles sorted alphabetically.
 *
 * Runs the schema migration inline on first call so the columns always
 * exist before the SELECT is attempted.
 */
function getActiveBusinessRoles(mysqli $conn, int $restaurant_id = 0): array {
    // ── Idempotent schema migration ───────────────────────────────────────
    db_add_column($conn, 'restaurant_staff_roles', 'restaurant_id',
        "INT(11) DEFAULT NULL COMMENT 'NULL = platform role; set to restaurant id for custom roles'");
    db_add_column($conn, 'restaurant_staff_roles', 'is_custom',
        "TINYINT(1) NOT NULL DEFAULT 0 COMMENT '0 = built-in platform role, 1 = owner-created custom role'");

    // Virtual sentinel column — COALESCE(restaurant_id, 0).
    // Lets the unique key treat all platform rows as (role_key, 0),
    // blocking duplicates even though restaurant_id IS NULL (NULL!=NULL in MySQL).
    db_add_column($conn, 'restaurant_staff_roles', 'restaurant_id_key',
        'INT(11) AS (COALESCE(`restaurant_id`, 0)) VIRTUAL');

    // Drop the old NULL-permissive composite key if it still exists.
    $chk_drop = $conn->prepare(
        "SELECT COUNT(*) FROM information_schema.STATISTICS
         WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'restaurant_staff_roles'
         AND INDEX_NAME = 'uq_role_key_rest'"
    );
    $chk_drop->execute();
    if ((int)$chk_drop->get_result()->fetch_row()[0] > 0) {
        $conn->query("ALTER TABLE `restaurant_staff_roles` DROP INDEX `uq_role_key_rest`");
    }
    unset($chk_drop);

    // Add the correct unique key on the virtual sentinel column.
    db_add_unique_key($conn, 'restaurant_staff_roles', 'uq_role_key_rest_v',
        ['role_key', 'restaurant_id_key']);

    // ── Deduplicate: remove any stale duplicate platform rows ─────────────
    // Keeps the row with the lowest id per role_key among platform rows.
    // This is a no-op once the unique key above is in place.
    @$conn->query("
        DELETE FROM `restaurant_staff_roles`
        WHERE  `is_custom` = 0
          AND  `id` NOT IN (
            SELECT `keep_id` FROM (
                SELECT MIN(`id`) AS `keep_id`
                FROM   `restaurant_staff_roles`
                WHERE  `is_custom` = 0
                GROUP  BY `role_key`
            ) AS `_dedup`
          )
    ");

    // ── Fetch: platform roles first (fixed order), then custom roles ──────
    $stmt = $conn->prepare("
        SELECT id, role_key, role_label, description,
               is_custom, restaurant_id
        FROM   restaurant_staff_roles
        WHERE  (is_custom = 0 AND restaurant_id IS NULL)
           OR  (is_custom = 1 AND restaurant_id = ?)
        ORDER  BY is_custom ASC,
                  FIELD(role_key, 'owner', 'ops_manager', 'cashier', 'chef') ASC,
                  role_label ASC
    ");

    if (!$stmt) {
        $result = $conn->query(
            "SELECT id, role_key, role_label, description,
                    0 AS is_custom, NULL AS restaurant_id
             FROM   restaurant_staff_roles
             ORDER  BY role_label"
        );
        return $result ? $result->fetch_all(MYSQLI_ASSOC) : [];
    }

    $stmt->bind_param("i", $restaurant_id);
    $stmt->execute();
    $rows = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
    $stmt->close();
    return $rows;
}

/**
 * Return the active restaurant_staff row (with role info) for a specific user
 * at a specific restaurant, or null if none.
 */
function getUserStaffAssignment(mysqli $conn, int $user_id, int $restaurant_id): ?array {
    $stmt = $conn->prepare("
        SELECT rs.*,
               rsr.role_key, rsr.role_label, rsr.description AS role_description
        FROM   restaurant_staff rs
        JOIN   restaurant_staff_roles rsr ON rs.staff_role_id = rsr.id
        WHERE  rs.user_id = ?
          AND  rs.restaurant_id = ?
          AND  rs.is_active = 1
        LIMIT  1
    ");
    $stmt->bind_param("ii", $user_id, $restaurant_id);
    $stmt->execute();
    $row = $stmt->get_result()->fetch_assoc();
    return $row ?: null;
}

/**
 * Return all staff_applications rows for a given user (applicant view).
 */
function getUserStaffApplications(mysqli $conn, int $user_id): array {
    $stmt = $conn->prepare("
        SELECT sa.*,
               hr.restaurant_name,
               rsr.role_label
        FROM   staff_applications sa
        JOIN   halal_restaurants hr ON sa.restaurant_id = hr.id
        LEFT JOIN restaurant_staff_roles rsr ON rsr.role_key = sa.applied_role
        WHERE  sa.user_id = ?
        ORDER  BY sa.created_at DESC
    ");
    $stmt->bind_param("i", $user_id);
    $stmt->execute();
    return $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
}

// ─────────────────────────────────────────────────────────────────────────────
// AUDIT / ACTIVITY LOGGING (wraps the existing logActivity helper)
// ─────────────────────────────────────────────────────────────────────────────

/**
 * Log a staff management action to activity_log (uses existing logActivity()).
 *
 * @param array $details  Associative array that will be JSON-encoded.
 */
function logStaffAction(
    mysqli $conn,
    int    $restaurant_id,
    string $action_type,
    string $target_type,
    ?int   $target_id,
    int    $performed_by,
    array  $details = []
): bool {
    $detail_str = json_encode(array_merge(
        ['restaurant_id' => $restaurant_id, 'target_type' => $target_type, 'target_id' => $target_id],
        $details
    ));
    return logActivity($conn, $performed_by, $action_type, $detail_str, 'staff_management');
}

// ─────────────────────────────────────────────────────────────────────────────
// STAFF DEACTIVATION
// ─────────────────────────────────────────────────────────────────────────────

/**
 * Deactivate a restaurant_staff row by its primary key.
 * Returns ['success' => bool, 'message' => string].
 */
function deactivateStaffAssignment(
    mysqli $conn,
    int    $assignment_id,
    int    $restaurant_id,
    int    $performed_by
): array {
    // Verify the row belongs to this restaurant
    $chk = $conn->prepare(
        "SELECT user_id FROM restaurant_staff WHERE id = ? AND restaurant_id = ? LIMIT 1"
    );
    $chk->bind_param("ii", $assignment_id, $restaurant_id);
    $chk->execute();
    $row = $chk->get_result()->fetch_assoc();
    if (!$row) {
        return ['success' => false, 'message' => 'Staff record not found.'];
    }

    $stmt = $conn->prepare(
        "UPDATE restaurant_staff SET is_active = 0 WHERE id = ? AND restaurant_id = ?"
    );
    $stmt->bind_param("ii", $assignment_id, $restaurant_id);
    if (!$stmt->execute()) {
        return ['success' => false, 'message' => 'Database error: ' . $stmt->error];
    }

    logStaffAction($conn, $restaurant_id, 'deactivate_staff', 'assignment',
        $assignment_id, $performed_by, ['staff_user_id' => $row['user_id']]);

    // Notify the staff member
    createNotification($conn, (int)$row['user_id'],
        'Staff Role Deactivated',
        'Your staff role at this restaurant has been deactivated.',
        'info'
    );

    return ['success' => true, 'message' => 'Staff member deactivated.'];
}

/**
 * Re-activate a restaurant_staff row.
 */
function reactivateStaffAssignment(
    mysqli $conn,
    int    $assignment_id,
    int    $restaurant_id,
    int    $performed_by
): array {
    $chk = $conn->prepare(
        "SELECT user_id FROM restaurant_staff WHERE id = ? AND restaurant_id = ? LIMIT 1"
    );
    $chk->bind_param("ii", $assignment_id, $restaurant_id);
    $chk->execute();
    $row = $chk->get_result()->fetch_assoc();
    if (!$row) {
        return ['success' => false, 'message' => 'Staff record not found.'];
    }

    $stmt = $conn->prepare(
        "UPDATE restaurant_staff SET is_active = 1 WHERE id = ? AND restaurant_id = ?"
    );
    $stmt->bind_param("ii", $assignment_id, $restaurant_id);
    if (!$stmt->execute()) {
        return ['success' => false, 'message' => 'Database error: ' . $stmt->error];
    }

    logStaffAction($conn, $restaurant_id, 'reactivate_staff', 'assignment',
        $assignment_id, $performed_by, ['staff_user_id' => $row['user_id']]);

    createNotification($conn, (int)$row['user_id'],
        'Staff Role Reactivated',
        'Your staff role has been reactivated. You can access your workspace again.',
        'success'
    );

    return ['success' => true, 'message' => 'Staff member reactivated.'];
}
