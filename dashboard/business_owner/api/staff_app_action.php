<?php
/**
 * Staff Application Action API
 * POST — approve or reject a staff_applications record.
 * On approval: inserts a row into restaurant_staff so the user gets access.
 * Returns JSON { success: bool, message: string }
 */
ob_start();
require_once __DIR__ . '/../../../config/app.php';
requireLogin();
requireRole([ROLE_BUSINESS_OWNER]);

header('Content-Type: application/json; charset=utf-8');

function json_out(bool $ok, string $msg): void {
    if (ob_get_length()) ob_clean();
    echo json_encode(['success' => $ok, 'message' => $msg]);
    exit();
}

try {
    $owner_id = (int)$_SESSION['user_id'];
    $user_rest_id = getSessionRestaurantId($conn);

    // ── Input ─────────────────────────────────────────────────────────────────────
    $app_id = (int)($_POST['app_id']     ?? 0);
    $action = trim(strip_tags($_POST['action']      ?? ''));
    $notes  = trim(strip_tags($_POST['owner_notes'] ?? ''));

    if (!$app_id)                                       json_out(false, 'Missing application ID.');
    if (!in_array($action, ['approved','rejected'],true)) json_out(false, 'Invalid action.');

    // ── Load the application + verify ownership ───────────────────────────────────
    $app_stmt = $conn->prepare("
        SELECT sa.*, hr.id AS restaurant_id, hr.business_owner_id
        FROM staff_applications sa
        JOIN halal_restaurants hr ON sa.restaurant_id = hr.id
        WHERE sa.id = ?
        LIMIT 1
    ");
    $app_stmt->bind_param("i", $app_id);
    $app_stmt->execute();
    $app = $app_stmt->get_result()->fetch_assoc();
    $app_stmt->close();

    if (!$app) json_out(false, 'Application not found.');

    // Verify permission: user must be the business owner or associated with the restaurant
    $is_owner_match = ((int)$app['business_owner_id'] === $owner_id);
    $is_rest_match  = ($user_rest_id && (int)$app['restaurant_id'] === (int)$user_rest_id);

    if (!$is_owner_match && !$is_rest_match) {
        json_out(false, 'Permission denied. You are not authorized to manage staff for this restaurant.');
    }

    if ($app['status'] !== 'pending') {
        json_out(false, 'This application has already been reviewed.');
    }

    // Ensure restaurant_staff_roles table and rows exist
    $conn->query("CREATE TABLE IF NOT EXISTS `restaurant_staff_roles` (
        `id`          SMALLINT UNSIGNED NOT NULL AUTO_INCREMENT,
        `role_key`    VARCHAR(30)  NOT NULL,
        `role_label`  VARCHAR(60)  NOT NULL,
        `description` VARCHAR(255) DEFAULT NULL,
        PRIMARY KEY (`id`),
        UNIQUE KEY `uq_role_key` (`role_key`)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");

    // Widen id column if it was previously TINYINT (max 255) — prevents AUTO_INCREMENT overflow
    $conn->query("SET FOREIGN_KEY_CHECKS=0");
    try { $conn->query("ALTER TABLE `restaurant_staff` DROP FOREIGN KEY `rs_role_fk`"); } catch (Throwable $e) {}
    try { $conn->query("ALTER TABLE `restaurant_staff` DROP FOREIGN KEY `rs_staff_role_fk`"); } catch (Throwable $e) {}
    $conn->query("ALTER TABLE `restaurant_staff_roles` MODIFY COLUMN `id` SMALLINT UNSIGNED NOT NULL AUTO_INCREMENT");
    $conn->query("ALTER TABLE `restaurant_staff`        MODIFY COLUMN `staff_role_id` SMALLINT UNSIGNED NOT NULL");
    try { $conn->query("ALTER TABLE `restaurant_staff` ADD CONSTRAINT `rs_role_fk` FOREIGN KEY (`staff_role_id`) REFERENCES `restaurant_staff_roles`(`id`)"); } catch (Throwable $e) {}
    $conn->query("SET FOREIGN_KEY_CHECKS=1");

    // Seed default roles — use ON DUPLICATE KEY UPDATE so AUTO_INCREMENT is not bumped for existing rows
    $conn->query("INSERT INTO `restaurant_staff_roles` (`role_key`,`role_label`,`description`) VALUES
    ('owner',       'Business Owner',       'Full access: analytics, financials, staff management.'),
    ('ops_manager', 'Operations Manager',   'Store profile, menu management, and Halal compliance.'),
    ('cashier',     'Cashier',              'POS order intake and payment processing only.'),
    ('chef',        'Chef / Head Cook',     'Manages kitchen inventory, requisitions, and menu recipe mapping.')
    ON DUPLICATE KEY UPDATE role_label = VALUES(role_label), description = VALUES(description)");

    // ── Resolve the target staff_role_id from restaurant_staff_roles ──────────────
    $target_role_key = $app['applied_role'] ?: 'ops_manager';
    $role_stmt = $conn->prepare(
        "SELECT id, role_label FROM restaurant_staff_roles WHERE role_key = ? LIMIT 1"
    );
    $role_stmt->bind_param("s", $target_role_key);
    $role_stmt->execute();
    $role_row = $role_stmt->get_result()->fetch_assoc();
    $role_stmt->close();

    // Dynamic fallback: if role_key row is missing, insert it on-the-fly
    if (!$role_row) {
        $fallback_label = ucfirst(str_replace('_', ' ', $target_role_key));
        $ins_role = $conn->prepare("INSERT INTO `restaurant_staff_roles` (`role_key`, `role_label`, `description`) VALUES (?, ?, ?)");
        $desc = "Staff role for {$fallback_label}";
        $ins_role->bind_param("sss", $target_role_key, $fallback_label, $desc);
        $ins_role->execute();
        $new_id = $ins_role->insert_id;
        $ins_role->close();

        $role_row = ['id' => $new_id, 'role_label' => $fallback_label];
    }

    $staff_role_id = (int)$role_row['id'];
    $role_label    = $role_row['role_label'];
    $applicant_id  = (int)$app['user_id'];
    $restaurant_id = (int)$app['restaurant_id'];

    // ── Transaction ───────────────────────────────────────────────────────────────
    $conn->begin_transaction();

    // 1. Update staff_applications status
    $upd = $conn->prepare("
        UPDATE staff_applications
        SET status = ?, owner_notes = ?, reviewed_by = ?, reviewed_at = NOW()
        WHERE id = ?
    ");
    $upd->bind_param("ssii", $action, $notes, $owner_id, $app_id);
    if (!$upd->execute()) {
        throw new RuntimeException('Failed to update application: ' . $upd->error);
    }
    $upd->close();

    // 2. On approval: upsert into restaurant_staff
    if ($action === 'approved') {

        // Check for an existing (possibly inactive) record for this user+restaurant
        $exist = $conn->prepare(
            "SELECT id FROM restaurant_staff WHERE user_id = ? AND restaurant_id = ? LIMIT 1"
        );
        $exist->bind_param("ii", $applicant_id, $restaurant_id);
        $exist->execute();
        $existing_staff = $exist->get_result()->fetch_assoc();
        $exist->close();

        if ($existing_staff) {
            // Re-activate and update role
            $reup = $conn->prepare("
                UPDATE restaurant_staff
                SET staff_role_id = ?, is_active = 1, assigned_by = ?, assigned_at = NOW()
                WHERE id = ?
            ");
            $reup->bind_param("iii", $staff_role_id, $owner_id, $existing_staff['id']);
            if (!$reup->execute()) {
                throw new RuntimeException('Failed to update staff record: ' . $reup->error);
            }
            $reup->close();
        } else {
            // Fresh insert
            $ins = $conn->prepare("
                INSERT INTO restaurant_staff
                    (restaurant_id, user_id, staff_role_id, is_active, assigned_by)
                VALUES (?, ?, ?, 1, ?)
            ");
            $ins->bind_param("iiii", $restaurant_id, $applicant_id, $staff_role_id, $owner_id);
            if (!$ins->execute()) {
                throw new RuntimeException('Failed to create staff record: ' . $ins->error);
            }
            $ins->close();
        }

        // Map the restaurant staff role key to the correct dashboard page
        $role_dashboard_map = [
            'ops_manager' => BASE_URL . 'dashboard/business_owner/operations_manager.php',
            'cashier'     => BASE_URL . 'dashboard/business_owner/pos_order_processing.php',
            'chef'        => BASE_URL . 'dashboard/business_owner/chef_dashboard.php',
        ];
        $staff_dashboard = $role_dashboard_map[$app['applied_role']]
                           ?? BASE_URL . 'dashboard/business_owner/owner_dashboard.php';

        // Promote the user's system role to ROLE_BUSINESS_OWNER (2) so they
        // can access the business_owner dashboard pages on next login.
        $promote = $conn->prepare(
            "UPDATE users SET role_id = ?, role_status = 'approved' WHERE id = ?"
        );
        $bo_role = ROLE_BUSINESS_OWNER;
        $promote->bind_param("ii", $bo_role, $applicant_id);
        if (!$promote->execute()) {
            throw new RuntimeException('Failed to promote user role: ' . $promote->error);
        }
        $promote->close();

        // Notify the applicant with the exact page they should land on
        createNotification(
            $conn,
            $applicant_id,
            'Staff Application Approved!',
            "Congratulations! You have been approved as {$role_label}. Click here to open your dashboard.",
            'success',
            $staff_dashboard
        );
    } else {
        // Rejection notification
        $rejection_reason = $notes ? " Reason: {$notes}" : '';
        createNotification(
            $conn,
            $applicant_id,
            'Staff Application Update',
            "Your application for {$role_label} was not approved at this time.{$rejection_reason}",
            'info',
            BASE_URL . 'dashboard/customer/restaurants.php'
        );
    }

    // 3. Log activity
    logActivity(
        $conn, $owner_id,
        'Staff Application ' . ucfirst($action),
        "Application #{$app_id} — applicant #{$applicant_id} — role: {$app['applied_role']} — restaurant: {$restaurant_id}",
        'staff_applications'
    );

    $conn->commit();

    // Fetch applicant name for cleaner message
    $aname_stmt = $conn->prepare("SELECT full_name FROM users WHERE id = ? LIMIT 1");
    $aname_stmt->bind_param("i", $applicant_id);
    $aname_stmt->execute();
    $aname_row = $aname_stmt->get_result()->fetch_assoc();
    $aname_stmt->close();
    $aname = $aname_row['full_name'] ?? 'Applicant';

    $msg = $action === 'approved'
        ? htmlspecialchars($aname) . " approved and assigned as {$role_label}."
        : htmlspecialchars($aname) . "'s application has been rejected.";

    json_out(true, $msg);

} catch (Throwable $e) {
    @$conn->rollback();
    json_out(false, 'Approval failed: ' . $e->getMessage());
}
