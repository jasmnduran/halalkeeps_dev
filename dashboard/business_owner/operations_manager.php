<?php
require_once __DIR__ . '/../../config/app.php';
require_once __DIR__ . '/../../includes/MenuWorkflowService.php';
requireLogin();
requireRole([ROLE_BUSINESS_OWNER]);
requireStaffRole($conn, STAFF_ROLE_OPS_MANAGER);

$page_title   = 'Operations Manager';
$page_heading = 'Operations Manager Interface';
$is_dashboard = true;
$breadcrumbs  = [['label' => 'Operations Manager Interface']];
$user_id = $_SESSION['user_id'];
$error   = '';
$success = '';
$menu_workflow_csrf = MenuWorkflowService::ensureCsrfToken();

// Active tab from query string — sidebar links deep-link via ?tab=
// Valid tabs: profile, menu, reviews, requisitions, expenses, other_inflows, log_order
$active_tab = in_array($_GET['tab'] ?? '', ['profile','menu','reviews','requisitions','expenses','other_inflows','log_order']) ? $_GET['tab'] : 'profile';

// â”€â”€ Runtime session refresh: re-resolve staff_role_key without requiring re-login
if ((int)($_SESSION['role_id'] ?? 0) === ROLE_BUSINESS_OWNER) {
    $uid_check = (int)$_SESSION['user_id'];
    $sr_check  = $conn->prepare("
        SELECT rsr.role_key FROM restaurant_staff rs
        JOIN restaurant_staff_roles rsr ON rs.staff_role_id = rsr.id
        WHERE rs.user_id = ? AND rs.is_active = 1 LIMIT 1
    ");
    if ($sr_check) {
        $sr_check->bind_param("i", $uid_check);
        $sr_check->execute();
        $sr_row = $sr_check->get_result()->fetch_assoc();
        $_SESSION['staff_role_key'] = $sr_row ? $sr_row['role_key'] : null;
        $sr_check->close();
    }
}

// Load restaurant via shared helper (works for both owner and ops_manager staff)
$rest_id    = getSessionRestaurantId($conn);
$restaurant = null;
if ($rest_id) {
    $rest_stmt = $conn->prepare(
        "SELECT hr.*, hc.certificate_number, hc.issue_date, hc.expiry_date, hc.status AS cert_status
         FROM halal_restaurants hr
         LEFT JOIN halal_certificates hc ON hr.certificate_id = hc.id
         WHERE hr.id = ? LIMIT 1"
    );
    $rest_stmt->bind_param("i", $rest_id);
    $rest_stmt->execute();
    $restaurant = $rest_stmt->get_result()->fetch_assoc();
}

// â”€â”€ Load reviews for this restaurant â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€
$reviews        = [];
$review_summary = ['avg' => 0, 'total' => 0, 'food_avg' => 0, 'service_avg' => 0, 'dist' => [5=>0,4=>0,3=>0,2=>0,1=>0]];
if ($rest_id) {
    $rev_result = $conn->query("
        SELECT r.id, r.rating, r.food_rating, r.service_rating,
               r.comment, r.response, r.response_at, r.created_at,
               u.full_name AS customer_name
        FROM reviews r
        JOIN users u ON r.customer_id = u.id
        WHERE r.restaurant_id = {$rest_id}
        ORDER BY r.created_at DESC
        LIMIT 100
    ");
    if ($rev_result) {
        $reviews = $rev_result->fetch_all(MYSQLI_ASSOC);
        $rev_result->free();
        if ($reviews) {
            $review_summary['total']       = count($reviews);
            $review_summary['avg']         = round(array_sum(array_column($reviews, 'rating'))       / $review_summary['total'], 1);
            $review_summary['food_avg']    = round(array_sum(array_column($reviews, 'food_rating'))   / $review_summary['total'], 1);
            $review_summary['service_avg'] = round(array_sum(array_column($reviews, 'service_rating'))/ $review_summary['total'], 1);
            foreach ($reviews as $rv) {
                $star = max(1, min(5, (int)$rv['rating']));
                $review_summary['dist'][$star]++;
            }
        }
    }
}

// â”€â”€ POST: Reply to a review â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['reply_review_id']) && $rest_id) {
    $review_id  = (int)$_POST['reply_review_id'];
    $reply_text = trim(strip_tags($_POST['reply_text'] ?? ''));
    if ($review_id && $reply_text !== '') {
        $rpl = $conn->prepare("
            UPDATE reviews
            SET response = ?, response_by = ?, response_at = NOW()
            WHERE id = ? AND restaurant_id = ?
        ");
        if ($rpl) {
            $rpl->bind_param("siii", $reply_text, $user_id, $review_id, $rest_id);
            $rpl->execute();
            $rpl->close();
            $success    = 'Your response has been posted.';
            $active_tab = 'reviews'; // stay on reviews tab after reply
        }
    }
}

// Load grocery lists for Ops Manager review — pending/acknowledged/approved
// Approved lists stay visible so the Ops Manager can encode the expenses
$pending_requisitions = [];
if ($rest_id) {
    $rq = $conn->query("SELECT gl.*, u.full_name AS requester_name FROM grocery_lists gl JOIN users u ON gl.created_by=u.id WHERE gl.restaurant_id={$rest_id} AND gl.status IN('pending','acknowledged','approved','fulfilled') ORDER BY gl.created_at DESC");
    if ($rq) {
        while ($rrow = $rq->fetch_assoc()) {
            $rid2 = (int)$rrow['id'];
            $ri2 = $conn->query("SELECT * FROM grocery_list_items WHERE grocery_list_id={$rid2}");
            $rrow['items'] = $ri2 ? $ri2->fetch_all(MYSQLI_ASSOC) : [];
            $pending_requisitions[] = $rrow;
        }
    }
}

// â”€â”€ POST: Update business profile â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€
// Sourced from constants - single source of truth across all pages
$allowed_services = SERVICE_TYPES;
$allowed_cuisines = CUISINE_TYPES;

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['save_profile']) && $rest_id) {
    // Cuisine — posted as an array of checkboxes
    $p_cuisine_raw = (array)($_POST['cuisine_type'] ?? []);
    $p_cuisine_arr = array_values(array_filter(
        array_map('strip_tags', $p_cuisine_raw),
        fn($c) => in_array($c, $allowed_cuisines, true)
    ));
    $p_cuisine = !empty($p_cuisine_arr) ? implode(', ', $p_cuisine_arr) : 'Other';    $p_desc     = trim(strip_tags($_POST['description']    ?? ''));
    $p_phone    = trim(strip_tags($_POST['phone']          ?? ''));
    $p_email    = trim(strip_tags($_POST['contact_email']  ?? ''));
    $p_from     = trim(strip_tags($_POST['open_from']      ?? ''));
    $p_to       = trim(strip_tags($_POST['open_to']        ?? ''));

    // Validate cuisine against whitelist — now handled during array parse above

    $p_services = array_values(array_filter(
        array_map('strip_tags', (array)($_POST['services_offered'] ?? [])),
        fn($s) => in_array($s, $allowed_services, true)
    ));
    $p_services_json = json_encode($p_services);

    $p_hours = ($p_from && $p_to)
        ? date('h:i A', strtotime($p_from)) . ' - ' . date('h:i A', strtotime($p_to))
        : ($restaurant['operating_hours'] ?? '');

    // Handle logo upload
    $p_logo = $restaurant['image_path'] ?? '';
    if (!empty($_FILES['logo_image']['name']) && $_FILES['logo_image']['error'] === UPLOAD_ERR_OK) {
        $ext = strtolower(pathinfo($_FILES['logo_image']['name'], PATHINFO_EXTENSION));
        if (in_array($ext, ['jpg','jpeg','png','webp'], true) && $_FILES['logo_image']['size'] <= MAX_FILE_SIZE) {
            $logo_dir = UPLOAD_DIR . 'restaurant_images/';
            @mkdir($logo_dir, 0755, true);
            $logo_fn  = 'logo_' . $rest_id . '_' . time() . '.' . $ext;
            if (move_uploaded_file($_FILES['logo_image']['tmp_name'], $logo_dir . $logo_fn)) {
                $p_logo = 'restaurant_images/' . $logo_fn;
            }
        }
    }

    $upd = $conn->prepare("
        UPDATE halal_restaurants
        SET cuisine_type=?, operating_hours=?, description=?,
            phone=?, email=?, services_offered=?, image_path=?,
            updated_at=NOW()
        WHERE id=?
    ");
    $upd->bind_param("sssssssi",
        $p_cuisine, $p_hours, $p_desc,
        $p_phone, $p_email, $p_services_json, $p_logo,
        $rest_id
    );
    if ($upd->execute()) {
        $success    = 'Business profile updated successfully.';
        $active_tab = 'profile';
        // Re-load fresh data &mdash; re-prepare because get_result() already exhausted the stmt
        $fresh = $conn->prepare(
            "SELECT hr.*, hc.certificate_number, hc.issue_date, hc.expiry_date, hc.status AS cert_status
             FROM halal_restaurants hr
             LEFT JOIN halal_certificates hc ON hr.certificate_id = hc.id
             WHERE hr.id = ? LIMIT 1"
        );
        $fresh->bind_param("i", $rest_id);
        $fresh->execute();
        $restaurant = $fresh->get_result()->fetch_assoc();
    } else {
        $error = 'Update failed: ' . $upd->error;
    }
}

// â”€â”€ Parse operating hours back to HH:MM for <input type="time"> â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€
$open_from_val = '';
$open_to_val   = '';
if (!empty($restaurant['operating_hours'])) {
    if (preg_match('/(\d{1,2}:\d{2}\s*[AP]M)\s*[-]\s*(\d{1,2}:\d{2}\s*[AP]M)/i',
                   $restaurant['operating_hours'], $hm)) {
        $open_from_val = date('H:i', strtotime($hm[1]));
        $open_to_val   = date('H:i', strtotime($hm[2]));
    }
}

// â”€â”€ Services offered (JSON array â†’ PHP array) â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€
$restaurant_services = [];
if (!empty($restaurant['services_offered'])) {
    $decoded = json_decode($restaurant['services_offered'], true);
    if (is_array($decoded)) $restaurant_services = $decoded;
}

// â”€â”€ POST: Delete menu item â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€
// ── Post: Delete menu item ───────────────────────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['delete_menu_item']) && $rest_id) {
    $did = (int)$_POST['delete_menu_item'];
    $conn->query("DELETE FROM menu_items WHERE id=$did AND restaurant_id=$rest_id");
    $success = 'Item removed.';
}

// ── Idempotent approval columns ───────────────────────────────────────────────
db_add_column($conn, 'menu_items', 'approval_status',
    "ENUM('pending','approved','rejected') NOT NULL DEFAULT 'pending'");
db_add_column($conn, 'menu_items', 'submitted_by',     'INT UNSIGNED NULL');
db_add_column($conn, 'menu_items', 'reviewed_by',      'INT UNSIGNED NULL');
db_add_column($conn, 'menu_items', 'reviewed_at',      'DATETIME NULL');
db_add_column($conn, 'menu_items', 'rejection_reason', 'VARCHAR(255) NULL');

// ── Load menu items split by approval status ──────────────────────────────────
$menu_items_pending  = [];
$menu_items_approved = [];
$menu_items_rejected = [];
if ($rest_id) {
    $mi = $conn->prepare("
        SELECT mi.id, mi.name, mi.description, mi.price, mi.category,
               mi.serving_type, mi.image_path, mi.is_available,
               mi.approval_status, mi.workflow_status, mi.workflow_updated_at, mi.rejection_reason,
               mi.ingredient_evidence_status, mi.operational_status, mi.submitted_by, mi.reviewed_at,
               u.full_name AS submitted_by_name
        FROM   menu_items mi
        LEFT JOIN users u ON mi.submitted_by = u.id
        WHERE  mi.restaurant_id = ?
        ORDER  BY mi.approval_status ASC, mi.category ASC, mi.name ASC
    ");
    $mi->bind_param("i", $rest_id);
    $mi->execute();
    $all_menu = $mi->get_result()->fetch_all(MYSQLI_ASSOC);
    foreach ($all_menu as $m) {
        if (empty(trim($m['name'] ?? ''))) continue;
        $workflowStatus = $m['workflow_status'] ?? '';
        if ($workflowStatus === MenuWorkflowService::PENDING_REVIEW) {
            $menu_items_pending[] = $m;
        } elseif ($workflowStatus === MenuWorkflowService::REJECTED) {
            $menu_items_rejected[] = $m;
        } elseif ($workflowStatus === MenuWorkflowService::APPROVED || ($workflowStatus === MenuWorkflowService::LEGACY && ($m['approval_status'] ?? '') === 'approved')) {
            $menu_items_approved[] = $m;
        }
    }
}
// Legacy compat: $valid_menu_items still used by the JS bridge (approved only)
$valid_menu_items = $menu_items_approved;

// Review data is loaded only for completed submissions currently in the manager queue.
$menu_review_details = [];
if ($rest_id && !empty($menu_items_pending)) {
    $pendingIds = implode(',', array_map(static fn(array $row): int => (int)$row['id'], $menu_items_pending));
    $detailResult = $conn->query("SELECT mr.menu_item_id,mr.id AS recipe_id,mr.recipe_name,mr.serving_size,mr.version_no,
            ri.id AS recipe_ingredient_id,ri.ingredient_name,ri.quantity_required,ri.unit,ri.mapping_status,
            ki.ingredient_name AS inventory_name,ki.quantity AS inventory_quantity,ki.unit AS inventory_unit,
            COALESCE((SELECT SUM(GREATEST(batch.quantity_available-batch.quantity_reserved,0)) FROM ingredient_inventory_batches batch WHERE batch.restaurant_id=mr.restaurant_id AND batch.inventory_id=ri.inventory_id AND batch.batch_status='active' AND (batch.expiration_date IS NULL OR batch.expiration_date>=CURDATE())),0) AS batch_available,
            aim.confidence_indicator,aim.explanation,aim.potential_substitution_warning,aim.review_status AS ai_review_status
        FROM menu_recipes mr
        LEFT JOIN recipe_ingredients ri ON ri.recipe_id=mr.id AND ri.restaurant_id=mr.restaurant_id
        LEFT JOIN kitchen_inventory ki ON ki.id=ri.inventory_id AND ki.restaurant_id=mr.restaurant_id
        LEFT JOIN ai_ingredient_mappings aim ON aim.id=(SELECT MAX(latest.id) FROM ai_ingredient_mappings latest WHERE latest.recipe_ingredient_id=ri.id AND latest.restaurant_id=mr.restaurant_id)
        WHERE mr.restaurant_id={$rest_id} AND mr.menu_item_id IN ({$pendingIds}) AND mr.recipe_status='active'
        ORDER BY mr.menu_item_id,ri.id");
    if ($detailResult) {
        while ($detail = $detailResult->fetch_assoc()) {
            $menuId = (int)$detail['menu_item_id'];
            if (!isset($menu_review_details[$menuId])) {
                $menu_review_details[$menuId] = ['recipe_name' => $detail['recipe_name'], 'serving_size' => $detail['serving_size'], 'version_no' => $detail['version_no'], 'ingredients' => []];
            }
            if ($detail['recipe_ingredient_id'] !== null) $menu_review_details[$menuId]['ingredients'][] = $detail;
        }
        $detailResult->free();
    }
}

// â”€â”€ Ensure restaurant_expenses table exists + has added_by_role column â”€â”€â”€â”€â”€â”€â”€â”€
$conn->query("CREATE TABLE IF NOT EXISTS `restaurant_expenses` (
    `id`            INT UNSIGNED  NOT NULL AUTO_INCREMENT,
    `restaurant_id` INT(11)       NOT NULL,
    `logged_by`     INT(11)       NOT NULL,
    `added_by_role` VARCHAR(30)   NOT NULL DEFAULT 'unknown',
    `category`      VARCHAR(60)   NOT NULL DEFAULT 'Other',
    `description`   VARCHAR(255)  NOT NULL,
    `amount`        DECIMAL(10,2) NOT NULL,
    `receipt_path`  VARCHAR(500)  DEFAULT NULL,
    `expense_date`  DATE          NOT NULL,
    `period_tag`    ENUM('daily','weekly','monthly') NOT NULL DEFAULT 'daily',
    `created_at`    TIMESTAMP     NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (`id`),
    KEY `idx_re_restaurant` (`restaurant_id`),
    KEY `idx_re_date` (`expense_date`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");

// â”€â”€ Ensure restaurant_expense_items table exists â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€
$conn->query("CREATE TABLE IF NOT EXISTS `restaurant_expense_items` (
    `id`            INT UNSIGNED  NOT NULL AUTO_INCREMENT,
    `expense_id`    INT UNSIGNED  NOT NULL,
    `description`   VARCHAR(255)  NOT NULL,
    `unit_price`    DECIMAL(10,2) NOT NULL,
    `quantity`      INT UNSIGNED  NOT NULL DEFAULT 1,
    `line_total`    DECIMAL(10,2) NOT NULL,
    PRIMARY KEY (`id`),
    KEY `idx_rei_expense` (`expense_id`),
    CONSTRAINT `rei_expense_fk` FOREIGN KEY (`expense_id`) REFERENCES `restaurant_expenses` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");

// Idempotent migration: add added_by_role if the table predates this column
db_add_column($conn, 'restaurant_expenses', 'added_by_role',
    "VARCHAR(30) NOT NULL DEFAULT 'unknown'", 'logged_by');

// ── Ensure restaurant_other_inflows table exists ─────────────────────────────
$conn->query("CREATE TABLE IF NOT EXISTS `restaurant_other_inflows` (
    `id`               INT UNSIGNED  NOT NULL AUTO_INCREMENT,
    `restaurant_id`    INT(11)       NOT NULL,
    `created_by`       INT(11)       NOT NULL,
    `created_by_role`  VARCHAR(30)   NOT NULL DEFAULT 'ops_manager',
    `inflow_type`      VARCHAR(30)   NOT NULL DEFAULT 'other',
    `description`      VARCHAR(500)  NOT NULL,
    `amount`           DECIMAL(12,2) NOT NULL,
    `client_name`      VARCHAR(255)  DEFAULT NULL,
    `event_date`       DATE          DEFAULT NULL,
    `reference_number` VARCHAR(100)  DEFAULT NULL,
    `receipt_path`     VARCHAR(500)  DEFAULT NULL,
    `status`           ENUM('pending','approved','rejected') NOT NULL DEFAULT 'pending',
    `approved_by`      INT(11)       DEFAULT NULL,
    `approved_at`      DATETIME      DEFAULT NULL,
    `rejection_reason` VARCHAR(255)  DEFAULT NULL,
    `inflow_date`      DATE          NOT NULL,
    `created_at`       TIMESTAMP     NOT NULL DEFAULT CURRENT_TIMESTAMP,
    `updated_at`       TIMESTAMP     NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (`id`),
    KEY `idx_roi_restaurant` (`restaurant_id`),
    KEY `idx_roi_date`       (`inflow_date`),
    KEY `idx_roi_status`     (`status`),
    KEY `idx_roi_type`       (`inflow_type`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");

// â”€â”€ Business Visits & Foot Traffic Analytics Aggregation â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€
$traffic_data = getBusinessTrafficStats($conn, $rest_id);
$traffic_stats = $traffic_data;
extract($traffic_data, EXTR_SKIP);

// Determine the role of the current session user for expense attribution
$_logged_role = (($_SESSION['staff_role_key'] ?? null) === STAFF_ROLE_OPS_MANAGER)
    ? 'ops_manager'
    : 'business_owner';

// â”€â”€ POST: Add expense â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['save_expense']) && $rest_id) {
    $exp_cat   = trim(strip_tags($_POST['exp_category']    ?? 'Other'));
    // exp_desc will be auto-generated from item descriptions below
    $exp_date  = $_POST['exp_date']  ?? date('Y-m-d');
    $exp_per   = in_array($_POST['exp_period'] ?? '', array_keys(EXPENSE_PERIODS))
                    ? $_POST['exp_period'] : 'daily';

    $allowed_cats = array_keys(EXPENSE_CATEGORIES);
    if (!in_array($exp_cat, $allowed_cats, true)) $exp_cat = 'Other';

    if (!strtotime($exp_date)) $exp_date = date('Y-m-d');

    // Parse and validate itemized lines
    $items = [];
    $total_calculated_amount = 0;
    if (isset($_POST['item_desc']) && is_array($_POST['item_desc'])) {
        foreach ($_POST['item_desc'] as $idx => $desc) {
            $desc = trim(strip_tags($desc));
            $price = (float)($_POST['item_price'][$idx] ?? 0);
            $qty = (int)($_POST['item_qty'][$idx] ?? 1);
            if ($qty < 1) $qty = 1;
            if ($price < 0) $price = 0;
            if ($desc !== '') {
                $line_total = $price * $qty;
                $total_calculated_amount += $line_total;
                $items[] = [
                    'description' => $desc,
                    'unit_price' => $price,
                    'quantity' => $qty,
                    'line_total' => $line_total
                ];
            }
        }
    }

    // Auto-generate parent description from item names
    $exp_desc = !empty($items)
        ? implode(', ', array_map(fn($i) => $i['description'], $items))
        : '';
    if (mb_strlen($exp_desc) > 255) {
        $exp_desc = mb_substr($exp_desc, 0, 252) . 'â€¦';
    }

    // Receipt upload
    $receipt_path = null;
    if (!empty($_FILES['exp_receipt']['name']) && $_FILES['exp_receipt']['error'] === UPLOAD_ERR_OK) {
        $rext = strtolower(pathinfo($_FILES['exp_receipt']['name'], PATHINFO_EXTENSION));
        if (in_array($rext, ['jpg','jpeg','png','webp','pdf'], true)
            && $_FILES['exp_receipt']['size'] <= MAX_FILE_SIZE) {
            $rdir = UPLOAD_DIR . 'expense_receipts/';
            @mkdir($rdir, 0755, true);
            $rfn  = 'receipt_' . $rest_id . '_' . time() . '.' . $rext;
            if (move_uploaded_file($_FILES['exp_receipt']['tmp_name'], $rdir . $rfn)) {
                $receipt_path = 'expense_receipts/' . $rfn;
            }
        }
    }

    if (!empty($items) && $total_calculated_amount > 0) {
        // Start database transaction
        $conn->begin_transaction();
        try {
            // 1. Insert parent expense record
            $ins = $conn->prepare("
                INSERT INTO restaurant_expenses
                    (restaurant_id, logged_by, added_by_role, category, description,
                     amount, receipt_path, expense_date, period_tag)
                VALUES (?,?,?,?,?,?,?,?,?)
            ");
            $ins->bind_param("iissdssss",
                $rest_id, $user_id, $_logged_role, $exp_cat, $exp_desc,
                $total_calculated_amount, $receipt_path, $exp_date, $exp_per
            );
            $ins->execute();
            $expense_id = (int)$conn->insert_id;
            $ins->close();

            // 2. Insert child items
            $ins_item = $conn->prepare("
                INSERT INTO restaurant_expense_items
                    (expense_id, description, unit_price, quantity, line_total)
                VALUES (?,?,?,?,?)
            ");
            foreach ($items as $item) {
                $ins_item->bind_param("isdid",
                    $expense_id, $item['description'], $item['unit_price'], $item['quantity'], $item['line_total']
                );
                $ins_item->execute();
            }
            $ins_item->close();

            // 3. Commit transaction
            $conn->commit();
            $success    = 'Expense logged successfully with ' . count($items) . ' itemised line(s).';
            $active_tab = 'expenses';
        } catch (Exception $e) {
            // Rollback transaction on failure
            $conn->rollback();
            $error      = 'Failed to save expense items: ' . $e->getMessage();
            $active_tab = 'expenses';
        }
    } else {
        $error      = 'Please specify at least one valid itemized row with description and price.';
        $active_tab = 'expenses';
    }
}

// â”€â”€ POST: Delete expense â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['delete_expense']) && $rest_id) {
    $del_id = (int)$_POST['delete_expense'];
    $conn->query("DELETE FROM restaurant_expenses WHERE id=$del_id AND restaurant_id=$rest_id");
    $success    = 'Expense removed.';
    $active_tab = 'expenses';
}

// ── POST: Save Other Inflow ──────────────────────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['save_inflow']) && $rest_id) {
    $inf_type   = trim(strip_tags($_POST['inflow_type']  ?? 'other'));
    $inf_desc   = trim(strip_tags($_POST['inflow_desc']  ?? ''));
    $inf_amt    = (float)($_POST['inflow_amount'] ?? 0);
    $inf_client = trim(strip_tags($_POST['inflow_client'] ?? ''));
    $inf_evt    = trim(strip_tags($_POST['inflow_event_date'] ?? ''));
    $inf_ref    = trim(strip_tags($_POST['inflow_reference'] ?? ''));
    $inf_date   = trim(strip_tags($_POST['inflow_date'] ?? date('Y-m-d')));

    $valid_types = array_keys(INFLOW_TYPES);
    if (!in_array($inf_type, $valid_types, true)) $inf_type = 'other';
    if (!strtotime($inf_date))  $inf_date = date('Y-m-d');
    if (!strtotime($inf_evt))   $inf_evt  = null;
    if ($inf_evt === '') $inf_evt = null;

    // For catering orders, ops manager self-approves on creation
    $auto_approve = ($inf_type === 'catering');
    $inf_status   = $auto_approve ? 'approved' : 'pending';
    $approved_by  = $auto_approve ? $user_id : null;
    $approved_at  = $auto_approve ? date('Y-m-d H:i:s') : null;

    // Receipt upload
    $inf_receipt = null;
    if (!empty($_FILES['inflow_receipt']['name']) && $_FILES['inflow_receipt']['error'] === UPLOAD_ERR_OK) {
        $rext = strtolower(pathinfo($_FILES['inflow_receipt']['name'], PATHINFO_EXTENSION));
        if (in_array($rext, ['jpg','jpeg','png','webp','pdf'], true)
            && $_FILES['inflow_receipt']['size'] <= MAX_FILE_SIZE) {
            $rdir = UPLOAD_DIR . 'inflow_receipts/';
            @mkdir($rdir, 0755, true);
            $rfn  = 'inflow_' . $rest_id . '_' . time() . '.' . $rext;
            if (move_uploaded_file($_FILES['inflow_receipt']['tmp_name'], $rdir . $rfn)) {
                $inf_receipt = 'inflow_receipts/' . $rfn;
            }
        }
    }

    if ($inf_desc !== '' && $inf_amt > 0) {
        $ins = $conn->prepare("
            INSERT INTO restaurant_other_inflows
                (restaurant_id, created_by, created_by_role, inflow_type, description,
                 amount, client_name, event_date, reference_number, receipt_path,
                 status, approved_by, approved_at, inflow_date)
            VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?,?)
        ");
        $ins->bind_param("iiiissdsssssis",
            $rest_id, $user_id, $_logged_role, $inf_type, $inf_desc,
            $inf_amt, $inf_client, $inf_evt, $inf_ref, $inf_receipt,
            $inf_status, $approved_by, $approved_at, $inf_date
        );
        if ($ins->execute()) {
            $success    = $auto_approve
                ? 'Catering order recorded and approved. It will appear in the revenue report.'
                : ucfirst($inf_type) . ' inflow of ₱' . number_format($inf_amt, 2) . ' saved (pending approval).';
        } else {
            $error = 'Failed to save inflow: ' . $ins->error;
        }
        $ins->close();
        $active_tab = 'other_inflows';
    } else {
        $error      = 'Description and a valid amount are required.';
        $active_tab = 'other_inflows';
    }
}

// ── POST: Approve / Reject inflow (ops manager self-service) ─────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['inflow_action']) && $rest_id) {
    $inf_id     = (int)($_POST['inflow_id'] ?? 0);
    $inf_action = $_POST['inflow_action'];

    if ($inf_id > 0 && in_array($inf_action, ['approve','reject'], true)) {
        if ($inf_action === 'approve') {
            $upd = $conn->prepare("
                UPDATE restaurant_other_inflows
                SET status='approved', approved_by=?, approved_at=NOW()
                WHERE id=? AND restaurant_id=? AND status='pending'
            ");
            $upd->bind_param("iii", $user_id, $inf_id, $rest_id);
            $upd->execute();
            $success = 'Inflow approved — it now counts toward restaurant revenue.';
        } else {
            $reason = trim(strip_tags($_POST['reject_reason'] ?? ''));
            $upd = $conn->prepare("
                UPDATE restaurant_other_inflows
                SET status='rejected', rejection_reason=?
                WHERE id=? AND restaurant_id=? AND status='pending'
            ");
            $upd->bind_param("sii", $reason, $inf_id, $rest_id);
            $upd->execute();
            $success = 'Inflow marked as rejected.';
        }
        $active_tab = 'other_inflows';
    }
}

// ── POST: Delete inflow ────────────────────────────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['delete_inflow']) && $rest_id) {
    $del_inf = (int)$_POST['delete_inflow'];
    $conn->query("DELETE FROM restaurant_other_inflows WHERE id=$del_inf AND restaurant_id=$rest_id");
    $success    = 'Inflow record deleted.';
    $active_tab = 'other_inflows';
}

// â”€â”€ Fetch expenses via shared helper (identical query to owner dashboard) â”€â”€â”€â”€â”€
$exp_filter  = in_array($_GET['exp_period'] ?? '', ['daily','weekly','monthly','all'])
                ? $_GET['exp_period'] : 'all';
$exp_records = $rest_id ? getSharedExpenses($conn, $rest_id, $exp_filter) : [];
$exp_total   = array_sum(array_column($exp_records, 'amount'));

// ── Fetch other inflows (all time, newest first) ─────────────────────────────
$inflow_filter = in_array($_GET['inf_status'] ?? '', ['all','pending','approved','rejected'])
                    ? $_GET['inf_status'] : 'all';
$inflow_records = [];
$inflow_totals  = ['pending' => 0.0, 'approved' => 0.0, 'rejected' => 0.0, 'all' => 0.0];
if ($rest_id) {
    $inf_where = ($inflow_filter !== 'all') ? "AND roi.status = '$inflow_filter'" : '';
    $inf_res = $conn->query("
        SELECT roi.*, u.full_name AS created_by_name
        FROM restaurant_other_inflows roi
        LEFT JOIN users u ON roi.created_by = u.id
        WHERE roi.restaurant_id = $rest_id $inf_where
        ORDER BY roi.inflow_date DESC, roi.created_at DESC
    ");
    if ($inf_res) $inflow_records = $inf_res->fetch_all(MYSQLI_ASSOC);

    // Totals by status (always all rows for summary cards)
    $tot_res = $conn->query("
        SELECT status, COALESCE(SUM(amount),0) AS total
        FROM restaurant_other_inflows
        WHERE restaurant_id = $rest_id
        GROUP BY status
    ");
    while ($tot_row = $tot_res ? $tot_res->fetch_assoc() : null) {
        if (!$tot_row) break;
        $inflow_totals[$tot_row['status']] = (float)$tot_row['total'];
        $inflow_totals['all'] += (float)$tot_row['total'];
    }
}
// ── Load approved menu items for the Log Order tab ───────────────────────────
$log_order_menu = [];
if ($rest_id) {
    $lom = $conn->query("
        SELECT mi.id, mi.name, mi.price, mi.category, mi.serving_type
        FROM menu_items mi
        WHERE mi.restaurant_id = $rest_id
          AND " . MenuWorkflowService::customerEligibilitySql('mi') . "
          AND mi.is_available = 1
        ORDER BY mi.category ASC, mi.name ASC
    ");
    if ($lom) $log_order_menu = $lom->fetch_all(MYSQLI_ASSOC);
}

require_once __DIR__ . '/../../includes/header.php';
?>

<!-- â”€â”€ Workspace branding banner â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€ -->
<div style="display:flex;align-items:center;justify-content:space-between;
            flex-wrap:wrap;gap:10px;margin-bottom:20px;">
    <div style="display:flex;align-items:center;gap:12px;">
        <div style="width:40px;height:40px;border-radius:10px;background:#eff6ff;
                    display:flex;align-items:center;justify-content:center;flex-shrink:0;">
            <i class="fas fa-user-tie" style="color:#2563eb;font-size:1rem;"></i>
        </div>
        <div>
            <div style="font-weight:700;font-size:1rem;color:var(--neutral-800);">
                Operations Manager Workspace
            </div>
            <div style="font-size:.78rem;color:var(--neutral-500);">
                <?= htmlspecialchars($restaurant['restaurant_name'] ?? 'Your Restaurant') ?>
            </div>
        </div>
        <span style="background:#eff6ff;color:#1d4ed8;border:1px solid #bfdbfe;
                     border-radius:999px;padding:3px 12px;font-size:.72rem;font-weight:700;
                     text-transform:uppercase;letter-spacing:.06em;">
            Operations Interface
        </span>
    </div>
</div>

<?php if ($error): ?>
<div class="alert alert-danger"><i class="fas fa-exclamation-circle"></i>
    <span><?= htmlspecialchars($error) ?></span>
    <button class="close-alert" onclick="this.parentElement.remove()"><i class="fas fa-times"></i></button>
</div>
<?php endif; ?>
<?php if ($success): ?>
<div class="alert alert-success"><i class="fas fa-check-circle"></i>
    <span><?= htmlspecialchars($success) ?></span>
    <button class="close-alert" onclick="this.parentElement.remove()"><i class="fas fa-times"></i></button>
</div>
<?php endif; ?>

<!-- â”€â”€ Tab navigation bar â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€ -->
<style>
.omgr-tabs {
    display: flex;
    gap: 0;
    border-bottom: 2px solid var(--neutral-200);
    margin-bottom: 24px;
    overflow-x: auto;
    overflow-y: visible;
    scrollbar-width: thin;
    scrollbar-color: var(--neutral-300) transparent;
    -webkit-overflow-scrolling: touch;
}
.omgr-tabs::-webkit-scrollbar { height: 4px; }
.omgr-tabs::-webkit-scrollbar-thumb { background: var(--neutral-300); border-radius: 2px; }
.omgr-tab-btn {
    display: flex;
    align-items: center;
    gap: 7px;
    padding: 10px 18px;
    border: none;
    border-bottom: 3px solid transparent;
    background: transparent;
    cursor: pointer;
    font-size: .875rem;
    font-weight: 600;
    color: var(--neutral-500);
    margin-bottom: -2px;
    transition: color .15s, border-color .15s, background .15s;
    white-space: nowrap;
    flex-shrink: 0;
    border-radius: 6px 6px 0 0;
}
.omgr-tab-btn:hover {
    color: var(--primary-600, #4f46e5);
    background: rgba(99,102,241,.06);
}
.omgr-tab-btn.active {
    color: var(--primary-600, #4f46e5);
    border-bottom-color: var(--primary-600, #4f46e5);
    background: #eef2ff;
    font-weight: 700;
}
</style>
<div class="omgr-tabs">
    <?php
    $tabs = [
        ['key' => 'profile',       'icon' => 'fas fa-store',           'label' => 'My Restaurant'],
        ['key' => 'menu',          'icon' => 'fas fa-utensils',         'label' => 'Menu Management'],
        ['key' => 'reviews',       'icon' => 'fas fa-star',             'label' => 'Reviews & Ratings'],
        ['key' => 'requisitions',  'icon' => 'fas fa-shopping-basket',  'label' => 'Grocery Lists'],
        ['key' => 'expenses',      'icon' => 'fas fa-receipt',          'label' => 'Expenses'],
        ['key' => 'other_inflows', 'icon' => 'fas fa-hand-holding-usd', 'label' => 'Other Inflows'],
        ['key' => 'log_order',     'icon' => 'fas fa-calendar-plus',    'label' => 'Log Order'],
    ];
    foreach ($tabs as $t):
        $on = $active_tab === $t['key'];
    ?>
    <button type="button"
            class="omgr-tab-btn<?= $on ? ' active' : '' ?>"
            data-tab="<?= $t['key'] ?>"
            onclick="switchOmgrTab('<?= $t['key'] ?>')">
        <i class="<?= $t['icon'] ?>"></i>
        <?= $t['label'] ?>
    </button>
    <?php endforeach; ?>
</div>

<!-- â•â•â• TAB: Business Profile â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â• -->
<div id="tab-profile" class="omgr-tab-panel"
     style="<?= $active_tab !== 'profile' ? 'display:none;' : '' ?>">

<!-- Business Visits & Traffic Analytics Card (Operations Manager View) -->
<div class="card" style="margin-bottom:20px;border:1px solid var(--neutral-200);border-radius:14px;box-shadow:0 4px 12px rgba(0,0,0,0.03);background:#ffffff;overflow:hidden;">
    <div class="card-header" style="background:linear-gradient(135deg, #0f172a 0%, #1e293b 100%);color:#ffffff;padding:16px 20px;display:flex;align-items:center;justify-content:space-between;flex-wrap:wrap;gap:12px;">
        <div style="display:flex;align-items:center;gap:12px;">
            <div style="width:38px;height:38px;border-radius:10px;background:rgba(255,255,255,0.1);display:flex;align-items:center;justify-content:center;font-size:1.2rem;color:#38bdf8;">
                <i class="fas fa-chart-line"></i>
            </div>
            <div>
                <h3 style="margin:0;font-size:1.05rem;font-weight:700;color:#ffffff;display:flex;align-items:center;gap:8px;">
                    Business Visits & Foot Traffic
                    <span style="font-size:0.7rem;font-weight:600;padding:2px 8px;border-radius:999px;background:rgba(56,189,248,0.2);color:#38bdf8;border:1px solid rgba(56,189,248,0.3);">
                        <i class="fas fa-bolt" style="font-size:0.65rem;"></i> Live Analytics
                    </span>
                </h3>
                <div style="font-size:0.75rem;color:#94a3b8;margin-top:2px;">
                    Digital store menu/profile views & physical dine-in customer visits
                </div>
            </div>
        </div>
        
        <div style="display:flex;align-items:center;gap:8px;background:rgba(255,255,255,0.08);padding:5px 12px;border-radius:999px;border:1px solid rgba(255,255,255,0.12);">
            <i class="fas fa-arrow-trend-up" style="color:<?= $growth_pct >= 0 ? '#4ade80' : '#f87171' ?>;"></i>
            <span style="font-size:0.8rem;font-weight:700;color:<?= $growth_pct >= 0 ? '#4ade80' : '#f87171' ?>;">
                <?= ($growth_pct >= 0 ? '+' : '') . number_format($growth_pct, 1) ?>%
            </span>
            <span style="font-size:0.7rem;color:#cbd5e1;">vs last month</span>
        </div>
    </div>

    <div class="card-body" style="padding:20px;">
        <div style="display:grid;grid-template-columns:repeat(auto-fit, minmax(200px, 1fr));gap:16px;margin-bottom:16px;">
            
            <!-- Combined Total Business Visits -->
            <div style="background:linear-gradient(135deg, #f0f9ff 0%, #e0f2fe 100%);border:1px solid #bae6fd;border-radius:12px;padding:16px;position:relative;overflow:hidden;">
                <div style="font-size:0.75rem;font-weight:700;text-transform:uppercase;letter-spacing:.04em;color:#0369a1;margin-bottom:4px;display:flex;align-items:center;justify-content:space-between;">
                    <span>Total Combined Visits</span>
                    <span style="background:#0284c7;color:#fff;font-size:0.65rem;padding:2px 6px;border-radius:4px;font-weight:600;">This Month</span>
                </div>
                <div style="font-size:1.6rem;font-weight:800;color:#0c4a6e;line-height:1.2;">
                    <?= number_format($total_visits_month) ?>
                </div>
                <div style="font-size:0.75rem;color:#0284c7;margin-top:4px;display:flex;align-items:center;gap:6px;">
                    <i class="fas fa-calendar-day"></i> <strong><?= number_format($total_visits_today) ?></strong> today &middot; <strong><?= number_format($total_visits_total) ?></strong> lifetime
                </div>
            </div>

            <!-- Online Profile Views -->
            <div style="background:linear-gradient(135deg, #f0fdf4 0%, #dcfce7 100%);border:1px solid #bbf7d0;border-radius:12px;padding:16px;position:relative;overflow:hidden;">
                <div style="font-size:0.75rem;font-weight:700;text-transform:uppercase;letter-spacing:.04em;color:#15803d;margin-bottom:4px;display:flex;align-items:center;justify-content:space-between;">
                    <span>Online Profile Views</span>
                    <i class="fas fa-eye" style="color:#16a34a;"></i>
                </div>
                <div style="font-size:1.6rem;font-weight:800;color:#14532d;line-height:1.2;">
                    <?= number_format($traffic_stats['online_views_month']) ?>
                </div>
                <div style="font-size:0.75rem;color:#16a34a;margin-top:4px;display:flex;align-items:center;gap:6px;">
                    <i class="fas fa-clock"></i> <strong><?= number_format($traffic_stats['online_views_today']) ?></strong> today &middot; <strong><?= number_format($traffic_stats['online_views_total']) ?></strong> total
                </div>
            </div>

            <!-- Physical Dine-In Visits -->
            <div style="background:linear-gradient(135deg, #fff7ed 0%, #ffedd5 100%);border:1px solid #fed7aa;border-radius:12px;padding:16px;position:relative;overflow:hidden;">
                <div style="font-size:0.75rem;font-weight:700;text-transform:uppercase;letter-spacing:.04em;color:#c2410c;margin-bottom:4px;display:flex;align-items:center;justify-content:space-between;">
                    <span>Physical Dine-In Visits</span>
                    <i class="fas fa-store" style="color:#ea580c;"></i>
                </div>
                <div style="font-size:1.6rem;font-weight:800;color:#7c2d12;line-height:1.2;">
                    <?= number_format($traffic_stats['dine_in_month']) ?>
                </div>
                <div style="font-size:0.75rem;color:#ea580c;margin-top:4px;display:flex;align-items:center;gap:6px;">
                    <i class="fas fa-chair"></i> <strong><?= number_format($traffic_stats['dine_in_today']) ?></strong> today &middot; <strong><?= number_format($traffic_stats['dine_in_total']) ?></strong> total
                </div>
            </div>

        </div>

        <!-- Traffic Distribution Bar -->
        <div style="background:var(--neutral-50);border:1px solid var(--neutral-200);border-radius:10px;padding:12px 16px;">
            <div style="display:flex;justify-content:space-between;font-size:0.78rem;font-weight:600;color:var(--neutral-700);margin-bottom:6px;">
                <span><i class="fas fa-globe" style="color:#0284c7;margin-right:4px;"></i> Digital Views: <strong><?= $online_pct ?>%</strong></span>
                <span><i class="fas fa-utensils" style="color:#ea580c;margin-right:4px;"></i> In-Store Dine-In: <strong><?= $dine_in_pct ?>%</strong></span>
            </div>
            <div style="height:8px;background:#fed7aa;border-radius:999px;overflow:hidden;display:flex;">
                <div style="width:<?= $online_pct ?>%;background:#0284c7;transition:width 0.5s ease;"></div>
                <div style="width:<?= $dine_in_pct ?>%;background:#ea580c;transition:width 0.5s ease;"></div>
            </div>
        </div>

    </div>
</div>

<form method="POST" enctype="multipart/form-data" data-profile-form>
<input type="hidden" name="save_profile" value="1">

<?php if (!$rest_id): ?>
<div class="alert alert-danger">
    <i class="fas fa-exclamation-circle"></i>
    No restaurant is linked to your account. Ask the business owner to assign you first.
</div>
<?php else: ?>

<div style="display:grid;grid-template-columns:1fr 360px;gap:20px;align-items:start;">

<!-- LEFT column -->
<div style="display:flex;flex-direction:column;gap:20px;">

<!-- Store Identity card -->
<div class="card">
    <div class="card-header">
        <h3><i class="fas fa-store" style="color:var(--primary-600);margin-right:8px;"></i>
            Store Identity</h3>
        <?php if ($restaurant): ?>
        <span style="background:#dcfce7;color:#166534;padding:3px 12px;border-radius:99px;
                     font-size:.75rem;font-weight:700;">
            <i class="fas fa-check-circle" style="margin-right:3px;"></i>Registered
        </span>
        <?php endif; ?>
    </div>
    <div class="card-body" style="display:flex;flex-direction:column;gap:14px;">
        <!-- Read-only name + address sourced from halal application -->
        <div style="background:#f0fdf4;border:1px solid #bbf7d0;border-radius:10px;
                    padding:12px 16px;">
            <div style="font-size:.72rem;font-weight:700;color:#166534;text-transform:uppercase;
                        letter-spacing:.05em;margin-bottom:10px;">
                <i class="fas fa-lock" style="margin-right:4px;"></i>
                Sourced from Halal Application (read-only)
            </div>
            <div style="display:grid;grid-template-columns:1fr 1fr;gap:12px;">
                <div>
                    <div style="font-size:.75rem;color:var(--neutral-500);margin-bottom:3px;">
                        Restaurant / Business Name
                    </div>
                    <div style="font-weight:600;color:var(--neutral-700);">
                        <?= $restaurant['restaurant_name'] ? htmlspecialchars($restaurant['restaurant_name']) : '&mdash;' ?>
                    </div>
                </div>
                <div>
                    <div style="font-size:.75rem;color:var(--neutral-500);margin-bottom:3px;">
                        Business Address
                    </div>
                    <div style="font-weight:600;color:var(--neutral-700);">
                        <?= $restaurant['address'] ? htmlspecialchars($restaurant['address']) : '&mdash;' ?>
                    </div>
                </div>
            </div>
        </div>

        <!-- Cuisine type -->
        <div class="form-group" style="margin:0;">
            <label class="form-label">Cuisine Type <span style="color:red;">*</span></label>
            <?php
            $saved_cuisines = array_map('trim', explode(',', $restaurant['cuisine_type'] ?? ''));
            ?>
            <div style="display:flex;flex-wrap:wrap;gap:8px;padding:10px 12px;
                        border:1px solid var(--neutral-300);border-radius:var(--radius-md);
                        background:var(--neutral-50);">
                <?php foreach ($allowed_cuisines as $c): ?>
                <label style="display:inline-flex;align-items:center;gap:6px;
                              padding:5px 12px;border-radius:999px;cursor:pointer;font-size:.85rem;
                              border:1.5px solid <?= in_array($c, $saved_cuisines) ? 'var(--primary-600)' : 'var(--neutral-300)' ?>;
                              background:<?= in_array($c, $saved_cuisines) ? 'var(--primary-50)' : '#fff' ?>;
                              color:<?= in_array($c, $saved_cuisines) ? 'var(--primary-700)' : 'var(--neutral-600)' ?>;
                              font-weight:<?= in_array($c, $saved_cuisines) ? '600' : '400' ?>;
                              transition:all .15s;"
                      onmouseover="this.style.borderColor='var(--primary-400)'"
                      onmouseout="syncCuisineChip(this)">
                    <input type="checkbox" name="cuisine_type[]" value="<?= htmlspecialchars($c) ?>"
                           <?= in_array($c, $saved_cuisines) ? 'checked' : '' ?>
                           style="width:14px;height:14px;accent-color:var(--primary-600);cursor:pointer;"
                           onchange="syncCuisineChip(this.closest('label'))">
                    <?= htmlspecialchars($c) ?>
                </label>
                <?php endforeach; ?>
            </div>
            <div style="font-size:.75rem;color:var(--neutral-400);margin-top:5px;">
                Select all that apply. At least one is required.
            </div>
        </div>
        <script>
        function syncCuisineChip(label) {
            const cb = label.querySelector('input[type=checkbox]');
            if (cb && cb.checked) {
                label.style.borderColor = 'var(--primary-600)';
                label.style.background  = 'var(--primary-50)';
                label.style.color       = 'var(--primary-700)';
                label.style.fontWeight  = '600';
            } else {
                label.style.borderColor = 'var(--neutral-300)';
                label.style.background  = '#fff';
                label.style.color       = 'var(--neutral-600)';
                label.style.fontWeight  = '400';
            }
        }
        // Validate at least one cuisine is checked before submit
        document.querySelector('form[data-profile-form]') &&
        document.querySelector('form[data-profile-form]').addEventListener('submit', function(e) {
            const checked = this.querySelectorAll('input[name="cuisine_type[]"]:checked');
            if (!checked.length) {
                e.preventDefault();
                alert('Please select at least one cuisine type.');
            }
        });
        </script>

        <!-- Description -->
        <div class="form-group" style="margin:0;">
            <label class="form-label">Description</label>
            <textarea name="description" class="form-control" rows="3"
                      placeholder="Specialties, ambiance, halal commitmentâ€¦"><?= htmlspecialchars($restaurant['description'] ?? '') ?></textarea>
        </div>

        <!-- Logo upload -->
        <div class="form-group" style="margin:0;">
            <label class="form-label">Restaurant Logo / Photo</label>
            <div style="display:flex;align-items:center;gap:14px;">
                <?php if (!empty($restaurant['image_path'])): ?>
                <img src="<?= BASE_URL ?>uploads/<?= htmlspecialchars($restaurant['image_path']) ?>"
                     style="width:64px;height:64px;object-fit:cover;border-radius:10px;
                            border:1px solid var(--neutral-200);">
                <?php else: ?>
                <div style="width:64px;height:64px;border-radius:10px;background:var(--neutral-100);
                            display:flex;align-items:center;justify-content:center;
                            color:var(--neutral-300);font-size:1.5rem;border:1px solid var(--neutral-200);">
                    <i class="fas fa-image"></i>
                </div>
                <?php endif; ?>
                <div style="flex:1;">
                    <input type="file" name="logo_image" class="form-control"
                           accept="image/jpeg,image/png,image/webp"
                           style="font-size:.875rem;">
                    <div style="font-size:.75rem;color:var(--neutral-400);margin-top:4px;">
                        JPG, PNG, WebP &middot; max 20 MB
                    </div>
                </div>
            </div>
        </div>
    </div>
</div>

<!-- Contact & Hours card -->
<div class="card">
    <div class="card-header">
        <h3><i class="fas fa-clock" style="color:var(--primary-600);margin-right:8px;"></i>
            Contact &amp; Hours</h3>
    </div>
    <div class="card-body" style="display:flex;flex-direction:column;gap:14px;">
        <div style="display:grid;grid-template-columns:1fr 1fr;gap:14px;">
            <div class="form-group" style="margin:0;">
                <label class="form-label">Phone</label>
                <input type="text" name="phone" class="form-control"
                       value="<?= htmlspecialchars($restaurant['phone'] ?? '') ?>"
                       placeholder="e.g. 0917 123 4567" maxlength="50">
            </div>
            <div class="form-group" style="margin:0;">
                <label class="form-label">Contact Email</label>
                <input type="email" name="contact_email" class="form-control"
                       value="<?= htmlspecialchars($restaurant['email'] ?? '') ?>"
                       placeholder="restaurant@example.com" maxlength="255">
            </div>
        </div>
        <div class="form-group" style="margin:0;">
            <label class="form-label">Operating Hours <span style="color:red;">*</span></label>
            <div style="display:flex;align-items:center;gap:10px;">
                <input type="time" name="open_from" class="form-control"
                       value="<?= htmlspecialchars($open_from_val) ?>" style="flex:1;">
                <span style="color:var(--neutral-400);font-weight:600;white-space:nowrap;">to</span>
                <input type="time" name="open_to" class="form-control"
                       value="<?= htmlspecialchars($open_to_val) ?>" style="flex:1;">
            </div>
        </div>
        <div class="form-group" style="margin:0;">
            <label class="form-label">Services Offered</label>
            <div style="display:flex;gap:10px;flex-wrap:wrap;">
                <?php foreach ($allowed_services as $svc): ?>
                <label style="display:flex;align-items:center;gap:8px;padding:8px 14px;
                              border:1px solid var(--neutral-200);border-radius:8px;
                              cursor:pointer;background:#fff;font-size:.875rem;">
                    <input type="checkbox" name="services_offered[]"
                           value="<?= $svc ?>"
                           <?= in_array($svc, $restaurant_services, true) ? 'checked' : '' ?>
                           style="accent-color:var(--primary-600);">
                    <?= $svc ?>
                </label>
                <?php endforeach; ?>
            </div>
        </div>
    </div>
</div>

<!-- Save button -->
<div style="display:flex;justify-content:flex-end;gap:10px;">
    <button type="submit" class="btn btn-primary">
        <i class="fas fa-save"></i> Save Business Profile
    </button>
</div>

</div><!-- /LEFT column -->

<!-- RIGHT: live summary -->
<div style="position:sticky;top:20px;">
<div class="card">
    <div class="card-header">
        <h3 style="font-size:.95rem;">
            <i class="fas fa-info-circle" style="color:var(--primary-600);margin-right:6px;"></i>
            Profile Summary
        </h3>
    </div>
    <div class="card-body" style="font-size:.875rem;">
        <?php if ($restaurant): ?>
        <div style="display:flex;flex-direction:column;gap:12px;">
            <?php if (!empty($restaurant['image_path'])): ?>
            <img src="<?= BASE_URL ?>uploads/<?= htmlspecialchars($restaurant['image_path']) ?>"
                 style="width:100%;height:120px;object-fit:cover;border-radius:10px;">
            <?php endif; ?>
            <div>
                <div style="font-size:.7rem;font-weight:700;text-transform:uppercase;
                            color:var(--neutral-400);margin-bottom:2px;">Name</div>
                <div style="font-weight:600;"><?= htmlspecialchars($restaurant['restaurant_name']) ?></div>
            </div>
            <div>
                <div style="font-size:.7rem;font-weight:700;text-transform:uppercase;
                            color:var(--neutral-400);margin-bottom:2px;">Address</div>
                <div><?= $restaurant['address'] ? htmlspecialchars($restaurant['address']) : '&mdash;' ?></div>
            </div>
            <?php if (!empty($restaurant['cuisine_type'])): ?>
            <div>
                <div style="font-size:.7rem;font-weight:700;text-transform:uppercase;
                            color:var(--neutral-400);margin-bottom:4px;">Cuisine</div>
                <div style="display:flex;flex-wrap:wrap;gap:5px;">
                    <?php foreach (array_map('trim', explode(',', $restaurant['cuisine_type'])) as $_ct):
                          if ($_ct === '') continue; ?>
                    <span style="background:var(--primary-50);color:var(--primary-700);
                                 border:1px solid var(--primary-200);
                                 padding:2px 10px;border-radius:999px;font-size:.75rem;font-weight:600;">
                        <?= htmlspecialchars($_ct) ?>
                    </span>
                    <?php endforeach; ?>
                </div>
            </div>
            <?php endif; ?>
            <?php if (!empty($restaurant['operating_hours'])): ?>
            <div>
                <div style="font-size:.7rem;font-weight:700;text-transform:uppercase;
                            color:var(--neutral-400);margin-bottom:2px;">Hours</div>
                <div><?= htmlspecialchars($restaurant['operating_hours']) ?></div>
            </div>
            <?php endif; ?>
            <?php if (!empty($restaurant['phone']) || !empty($restaurant['email'])): ?>
            <div>
                <div style="font-size:.7rem;font-weight:700;text-transform:uppercase;
                            color:var(--neutral-400);margin-bottom:2px;">Contact</div>
                <?php if (!empty($restaurant['phone'])): ?>
                <div><?= htmlspecialchars($restaurant['phone']) ?></div>
                <?php endif; ?>
                <?php if (!empty($restaurant['email'])): ?>
                <div style="color:var(--primary-600);"><?= htmlspecialchars($restaurant['email']) ?></div>
                <?php endif; ?>
            </div>
            <?php endif; ?>
            <?php if (!empty($restaurant_services)): ?>
            <div>
                <div style="font-size:.7rem;font-weight:700;text-transform:uppercase;
                            color:var(--neutral-400);margin-bottom:4px;">Services</div>
                <div style="display:flex;gap:5px;flex-wrap:wrap;">
                    <?php foreach ($restaurant_services as $svc): ?>
                    <span style="background:#eff6ff;color:#1d4ed8;padding:2px 10px;
                                 border-radius:999px;font-size:.75rem;font-weight:600;">
                        <?= htmlspecialchars($svc) ?>
                    </span>
                    <?php endforeach; ?>
                </div>
            </div>
            <?php endif; ?>
            <?php if (!empty($restaurant['cert_status'])): ?>
            <div style="padding:10px;background:#f0fdf4;border:1px solid #bbf7d0;
                        border-radius:8px;text-align:center;">
                <i class="fas fa-certificate" style="color:var(--accent-500);font-size:1.2rem;
                   display:block;margin-bottom:4px;"></i>
                <div style="font-weight:700;font-size:.82rem;color:#166534;">Halal Certified</div>
            </div>
            <?php endif; ?>
        </div>
        <?php else: ?>
        <div style="text-align:center;padding:20px;color:var(--neutral-400);">
            <i class="fas fa-store" style="font-size:2rem;display:block;margin-bottom:8px;"></i>
            <div style="font-size:.85rem;">Profile will appear here once saved.</div>
        </div>
        <?php endif; ?>
    </div>
</div>
</div><!-- /RIGHT summary -->

</div><!-- /grid -->
<?php endif; // rest_id ?>
</form>
</div><!-- /tab-profile -->

<!-- ══ TAB: Menu Approvals ══════════════════════════════════════════════════ -->
<div id="tab-menu" class="omgr-tab-panel"
     style="<?= $active_tab !== 'menu' ? 'display:none;' : '' ?>">

<?php if (!$rest_id): ?>
<div class="alert alert-danger">
    <i class="fas fa-exclamation-circle"></i> No restaurant found. Please contact your business owner.
</div>
<?php else: ?>

<!-- ── Pending Approval Queue ─────────────────────────────────────────────── -->
<div class="card" style="margin-bottom:20px;border:2px solid #fde68a;overflow:hidden;">
    <div class="card-header" style="background:linear-gradient(135deg,#92400e,#b45309);
                                     display:flex;align-items:center;justify-content:space-between;flex-wrap:wrap;gap:10px;">
        <h3 style="color:#fff;margin:0;display:flex;align-items:center;gap:8px;">
            <i class="fas fa-clock"></i> Pending Approval
            <?php if (count($menu_items_pending)): ?>
            <span style="background:rgba(255,255,255,.25);color:#fff;font-size:.72rem;padding:2px 9px;border-radius:999px;">
                <?= count($menu_items_pending) ?>
            </span>
            <?php endif; ?>
        </h3>
        <p style="color:rgba(255,255,255,.75);font-size:.78rem;margin:0;">
            Review and approve or reject items submitted by the kitchen team.
        </p>
    </div>
    <div class="card-body" style="padding:0;">
        <?php if (empty($menu_items_pending)): ?>
        <div style="text-align:center;padding:36px 24px;color:var(--neutral-400);">
            <i class="fas fa-check-double" style="font-size:2rem;margin-bottom:10px;display:block;color:#a7f3d0;"></i>
            <p style="margin:0;font-size:.9rem;">No items awaiting approval. All caught up!</p>
        </div>
        <?php else: ?>
        <div style="display:flex;flex-direction:column;">
        <?php foreach ($menu_items_pending as $item):
            $img_url = !empty($item['image_path']) ? BASE_URL . 'uploads/' . htmlspecialchars($item['image_path']) : '';
            $review_detail = $menu_review_details[(int)$item['id']] ?? null;
        ?>
        <div id="approval-row-<?= $item['id'] ?>"
             style="display:flex;align-items:flex-start;gap:14px;padding:16px 20px;
                    border-bottom:1px solid var(--neutral-100);background:#fffbeb;">
            <?php if ($img_url): ?>
            <img src="<?= $img_url ?>" style="width:56px;height:56px;object-fit:cover;border-radius:8px;flex-shrink:0;" alt="<?= htmlspecialchars($item['name']) ?>">
            <?php else: ?>
            <div style="width:56px;height:56px;background:var(--neutral-100);border-radius:8px;display:flex;align-items:center;justify-content:center;flex-shrink:0;">
                <i class="fas fa-utensils" style="color:var(--neutral-300);font-size:1.2rem;"></i>
            </div>
            <?php endif; ?>
            <div style="flex:1;min-width:0;">
                <div style="font-weight:700;font-size:.95rem;color:var(--neutral-800);"><?= htmlspecialchars($item['name']) ?></div>
                <?php if ($item['description']): ?>
                <div style="font-size:.8rem;color:var(--neutral-500);margin-top:2px;"><?= htmlspecialchars(mb_substr($item['description'],0,100)) ?><?= mb_strlen($item['description'])>100?'…':'' ?></div>
                <?php endif; ?>
                <div style="font-size:.78rem;color:var(--neutral-400);margin-top:4px;display:flex;flex-wrap:wrap;gap:8px;">
                    <span><i class="fas fa-tag" style="color:var(--primary-400);"></i> <?= htmlspecialchars($item['category']??'') ?></span>
                    <span><i class="fas fa-peso-sign" style="color:var(--primary-400);"></i> <?= formatCurrency($item['price']) ?></span>
                    <?php if ($item['serving_type']): ?><span><i class="fas fa-bowl-food" style="color:var(--primary-400);"></i> <?= htmlspecialchars($item['serving_type']) ?></span><?php endif; ?>
                    <?php if ($item['submitted_by_name']): ?><span style="color:#8b5cf6;"><i class="fas fa-user"></i> <?= htmlspecialchars($item['submitted_by_name']) ?></span><?php endif; ?>
                    <?php if ($item['workflow_updated_at']): ?><span><i class="fas fa-clock"></i> Submitted <?= htmlspecialchars($item['workflow_updated_at']) ?></span><?php endif; ?>
                </div>
                <div style="margin-top:10px;padding:10px 12px;background:#fff;border:1px solid #fde68a;border-radius:8px;font-size:.76rem;color:#475569;">
                    <div style="font-weight:800;color:#78350f;">Recipe: <?= htmlspecialchars($review_detail['recipe_name'] ?? 'Missing recipe') ?><?= !empty($review_detail['serving_size']) ? ' &middot; '.htmlspecialchars($review_detail['serving_size']) : '' ?><?= isset($review_detail['version_no']) ? ' &middot; v'.(int)$review_detail['version_no'] : '' ?></div>
                    <div style="margin-top:3px;">Evidence: <?= htmlspecialchars(str_replace('_', ' ', $item['ingredient_evidence_status'] ?? 'pending_review')) ?> &middot; Availability: <?= htmlspecialchars(str_replace('_', ' ', $item['operational_status'] ?? 'inventory_not_confirmed')) ?></div>
                    <?php if (!empty($review_detail['ingredients'])): ?><ul style="margin:7px 0 0 17px;padding:0;display:grid;gap:4px;"><?php foreach ($review_detail['ingredients'] as $ingredient): $availableStock = (float)$ingredient['batch_available'] > 0 ? $ingredient['batch_available'] : $ingredient['inventory_quantity']; ?><li><strong><?= htmlspecialchars($ingredient['ingredient_name']) ?></strong> &mdash; <?= htmlspecialchars($ingredient['quantity_required']) ?> <?= htmlspecialchars($ingredient['unit']) ?> required; <?= htmlspecialchars($ingredient['inventory_name'] ?: 'No confirmed inventory') ?> (<?= htmlspecialchars((string)$availableStock) ?> <?= htmlspecialchars($ingredient['inventory_unit'] ?: '') ?> available); mapping: <?= htmlspecialchars($ingredient['mapping_status']) ?><?php if ($ingredient['confidence_indicator']): ?>; AI <?= htmlspecialchars($ingredient['confidence_indicator']) ?> confidence<?php endif; ?><?php if ($ingredient['potential_substitution_warning']): ?><div style="color:#9a3412;">Warning: <?= htmlspecialchars($ingredient['potential_substitution_warning']) ?></div><?php endif; ?></li><?php endforeach; ?></ul><?php else: ?><div style="color:#b91c1c;margin-top:5px;">No active structured recipe ingredients were found.</div><?php endif; ?>
                </div>
            </div>
            <div style="display:flex;gap:8px;flex-shrink:0;flex-wrap:wrap;align-items:center;">
                <button type="button" onclick="approveItem(<?= $item['id'] ?>, '<?= htmlspecialchars(addslashes($item['name'])) ?>')"
                        class="btn btn-sm" style="background:#16a34a;color:#fff;border-color:#16a34a;gap:6px;">
                    <i class="fas fa-check"></i> Approve
                </button>
                <button type="button" onclick="openRevisionModal(<?= $item['id'] ?>, '<?= htmlspecialchars(addslashes($item['name'])) ?>')"
                        class="btn btn-sm btn-outline" style="color:#b45309;border-color:#fbbf24;gap:6px;">
                    <i class="fas fa-rotate-left"></i> Return for revision
                </button>
                <button type="button" onclick="openRejectModal(<?= $item['id'] ?>, '<?= htmlspecialchars(addslashes($item['name'])) ?>')"
                        class="btn btn-sm btn-danger" style="gap:6px;">
                    <i class="fas fa-times"></i> Reject
                </button>
            </div>
        </div>
        <?php endforeach; ?>
        </div>
        <?php endif; ?>
    </div>
</div>

<!-- ── Reject Modal ───────────────────────────────────────────────────────── -->
<div id="rejectModalOverlay"
     style="display:none;position:fixed;inset:0;background:rgba(0,0,0,.5);z-index:9999;
            align-items:center;justify-content:center;">
    <div style="background:#fff;border-radius:16px;padding:28px;max-width:480px;width:90%;box-shadow:0 20px 60px rgba(0,0,0,.25);">
        <div style="display:flex;align-items:center;gap:10px;margin-bottom:16px;">
            <div style="width:40px;height:40px;background:#fee2e2;border-radius:10px;display:flex;align-items:center;justify-content:center;flex-shrink:0;">
                <i class="fas fa-times" style="color:#dc2626;font-size:1rem;"></i>
            </div>
            <div>
                <div id="reviewModalTitle" style="font-weight:800;font-size:1rem;color:var(--neutral-900);">Reject Menu Item</div>
                <div id="rejectModalItemName" style="font-size:.82rem;color:var(--neutral-500);"></div>
            </div>
        </div>
        <p id="reviewModalDescription" style="font-size:.875rem;color:var(--neutral-600);margin:0 0 14px;">
            Provide a clear reason so the chef can revise and resubmit.
        </p>
        <textarea id="rejectReasonInput" maxlength="255" placeholder="e.g. Price is too high, please revise."
                  oninput="document.getElementById('rejectReasonCount').textContent=this.value.length+'/255'"
                  style="width:100%;border:1.5px solid var(--neutral-200);border-radius:8px;padding:10px 14px;
                         font-size:.875rem;resize:vertical;min-height:90px;font-family:inherit;
                         outline:none;box-sizing:border-box;"></textarea>
        <div id="rejectReasonCount" style="font-size:.72rem;color:var(--neutral-400);text-align:right;margin-top:3px;">0/255</div>
        <div id="rejectModalError" style="color:#dc2626;font-size:.82rem;margin-top:6px;display:none;"></div>
        <div style="display:flex;gap:10px;margin-top:18px;justify-content:flex-end;">
            <button type="button" onclick="closeRejectModal()" class="btn btn-outline">Cancel</button>
            <button type="button" onclick="submitRejection()" class="btn btn-danger" id="rejectSubmitBtn">
                <i class="fas fa-times"></i> Reject Item
            </button>
        </div>
    </div>
</div>

<!-- ── Live (Approved) Menu ───────────────────────────────────────────────── -->
<div class="card" style="margin-bottom:20px;border:1px solid var(--neutral-200);">
    <div class="card-header" style="display:flex;align-items:center;justify-content:space-between;flex-wrap:wrap;gap:10px;">
        <h3 style="display:flex;align-items:center;gap:8px;margin:0;">
            <i class="fas fa-circle-check" style="color:#16a34a;"></i>
            Live Menu
            <span style="background:#dcfce7;color:#15803d;font-size:.72rem;padding:2px 9px;border-radius:999px;font-weight:700;">
                <?= count($menu_items_approved) ?> item<?= count($menu_items_approved)!==1?'s':'' ?>
            </span>
        </h3>
        <p style="font-size:.78rem;color:var(--neutral-400);margin:0;">
            Currently visible to customers and on the POS. Modifications require chef submission + re-approval.
        </p>
    </div>
    <div class="card-body" style="padding:0;">
        <?php if (empty($menu_items_approved)): ?>
        <div style="text-align:center;padding:36px 24px;color:var(--neutral-400);">
            <i class="fas fa-utensils" style="font-size:2rem;margin-bottom:10px;display:block;opacity:.3;"></i>
            <p style="margin:0;font-size:.9rem;">No approved menu items yet.</p>
        </div>
        <?php else: ?>
        <div style="display:flex;flex-direction:column;">
        <?php foreach ($menu_items_approved as $item):
            $img_url = !empty($item['image_path']) ? BASE_URL . 'uploads/' . htmlspecialchars($item['image_path']) : '';
        ?>
        <div style="display:flex;align-items:center;gap:14px;padding:12px 20px;
                    border-bottom:1px solid var(--neutral-100);
                    <?= !(int)($item['is_available']??1)?'opacity:.55;':'' ?>">
            <?php if ($img_url): ?>
            <img src="<?= $img_url ?>" style="width:48px;height:48px;object-fit:cover;border-radius:8px;flex-shrink:0;" alt="">
            <?php else: ?>
            <div style="width:48px;height:48px;background:var(--neutral-100);border-radius:8px;display:flex;align-items:center;justify-content:center;flex-shrink:0;">
                <i class="fas fa-utensils" style="color:var(--neutral-300);font-size:1rem;"></i>
            </div>
            <?php endif; ?>
            <div style="flex:1;min-width:0;">
                <div style="font-weight:600;font-size:.9rem;color:var(--neutral-800);">
                    <?= htmlspecialchars($item['name']) ?>
                    <?php if (!(int)($item['is_available']??1)): ?><span style="background:#fef2f2;color:#dc2626;font-size:.68rem;padding:2px 7px;border-radius:10px;margin-left:6px;font-weight:700;">Unavailable</span><?php endif; ?>
                </div>
                <div style="font-size:.75rem;color:var(--neutral-400);"><?= htmlspecialchars($item['category']??'') ?><?= $item['serving_type']?' · '.htmlspecialchars($item['serving_type']):'' ?></div>
            </div>
            <div style="font-weight:700;color:var(--primary-700);white-space:nowrap;font-size:.95rem;"><?= formatCurrency($item['price']) ?></div>
            <span style="background:#dcfce7;color:#15803d;font-size:.7rem;padding:3px 10px;border-radius:999px;font-weight:700;white-space:nowrap;">
                <i class="fas fa-circle-check"></i> Live
            </span>
        </div>
        <?php endforeach; ?>
        </div>
        <?php endif; ?>
    </div>
</div>

<!-- ── Rejected Items Log ─────────────────────────────────────────────────── -->
<?php if (!empty($menu_items_rejected)): ?>
<div class="card" style="border:1px solid #fecaca;">
    <div class="card-header" style="background:#fef2f2;">
        <h3 style="color:#991b1b;margin:0;display:flex;align-items:center;gap:8px;">
            <i class="fas fa-circle-xmark"></i>
            Rejected Items
            <span style="background:#fecaca;color:#991b1b;font-size:.72rem;padding:2px 9px;border-radius:999px;font-weight:700;"><?= count($menu_items_rejected) ?></span>
        </h3>
    </div>
    <div class="card-body" style="padding:0;">
    <?php foreach ($menu_items_rejected as $item): ?>
    <div style="padding:14px 20px;border-bottom:1px solid #fee2e2;background:#fff5f5;">
        <div style="display:flex;align-items:center;gap:12px;flex-wrap:wrap;">
            <div style="flex:1;min-width:180px;">
                <div style="font-weight:600;font-size:.9rem;"><?= htmlspecialchars($item['name']) ?></div>
                <div style="font-size:.75rem;color:var(--neutral-400);margin-top:2px;">
                    <?= htmlspecialchars($item['category']??'') ?> · <?= formatCurrency($item['price']) ?>
                    <?php if ($item['submitted_by_name']): ?> · Submitted by <?= htmlspecialchars($item['submitted_by_name']) ?><?php endif; ?>
                </div>
            </div>
            <form method="POST" style="margin:0;" onsubmit="return confirm('Delete this rejected item permanently?')">
                <input type="hidden" name="delete_menu_item" value="<?= $item['id'] ?>">
                <button type="submit" class="btn btn-sm btn-outline" style="color:#dc2626;border-color:#fecaca;">
                    <i class="fas fa-trash"></i> Remove
                </button>
            </form>
        </div>
        <?php if ($item['rejection_reason']): ?>
        <div style="margin-top:8px;padding:8px 12px;background:#fee2e2;border-radius:7px;font-size:.8rem;color:#991b1b;border-left:3px solid #dc2626;">
            <i class="fas fa-comment-exclamation" style="margin-right:5px;"></i>
            <strong>Reason:</strong> <?= htmlspecialchars($item['rejection_reason']) ?>
        </div>
        <?php endif; ?>
    </div>
    <?php endforeach; ?>
    </div>
</div>
<?php endif; ?>

<?php endif; // rest_id ?>
</div><!-- /tab-menu -->

<script>
(function(){
    const APPROVAL_URL = <?= json_encode(BASE_URL . 'dashboard/business_owner/api/menu_item_approval.php') ?>;
    const MENU_WORKFLOW_CSRF = <?= json_encode($menu_workflow_csrf) ?>;
    let _rejectItemId = null;
    let _reviewAction = 'reject';

    window.approveItem = function(itemId, itemName) {
        if (!confirm('Approve "' + itemName + '" and make it live?')) return;
        const fd = new FormData();
        fd.append('action', 'approve');
        fd.append('item_id', itemId);
        fd.append('csrf_token', MENU_WORKFLOW_CSRF);
        fetch(APPROVAL_URL, {method:'POST',body:fd}).then(r=>r.json()).then(data => {
            if (data.success) {
                const row = document.getElementById('approval-row-' + itemId);
                if (row) { row.style.transition='opacity .4s'; row.style.opacity='0'; setTimeout(()=>{row.remove();checkEmptyQueue();},420); }
                showMenuToast(data.message, false);
            } else { showMenuToast(data.message||'Approval failed.', true); }
        }).catch(()=>showMenuToast('Network error.',true));
    };

    window.openRejectModal = function(itemId, itemName) {
        _rejectItemId = itemId;
        _reviewAction = 'reject';
        document.getElementById('reviewModalTitle').textContent = 'Reject Menu Item';
        document.getElementById('reviewModalDescription').textContent = 'Provide a clear reason for the final rejection.';
        document.getElementById('rejectSubmitBtn').innerHTML = '<i class="fas fa-times"></i> Reject Item';
        document.getElementById('rejectModalItemName').textContent = '"' + itemName + '"';
        document.getElementById('rejectReasonInput').value = '';
        document.getElementById('rejectReasonCount').textContent = '0/255';
        document.getElementById('rejectModalError').style.display = 'none';
        document.getElementById('rejectModalOverlay').style.display = 'flex';
        setTimeout(()=>document.getElementById('rejectReasonInput').focus(), 80);
    };

    window.openRevisionModal = function(itemId, itemName) {
        window.openRejectModal(itemId, itemName);
        _reviewAction = 'needs_revision';
        document.getElementById('reviewModalTitle').textContent = 'Return Menu for Revision';
        document.getElementById('reviewModalDescription').textContent = 'Provide actionable feedback. The Chef must revise and resubmit the menu.';
        document.getElementById('rejectSubmitBtn').innerHTML = '<i class="fas fa-rotate-left"></i> Return for Revision';
    };

    window.closeRejectModal = function() {
        document.getElementById('rejectModalOverlay').style.display = 'none';
        _rejectItemId = null;
    };

    window.submitRejection = function() {
        const reason = document.getElementById('rejectReasonInput').value.trim();
        const errEl  = document.getElementById('rejectModalError');
        const btn    = document.getElementById('rejectSubmitBtn');
        if (!reason) { errEl.textContent='Please enter a rejection reason.'; errEl.style.display='block'; return; }
        errEl.style.display = 'none';
        btn.disabled = true;
        btn.innerHTML = '<i class="fas fa-spinner fa-spin"></i> Saving\u2026';
        const fd = new FormData();
        fd.append('action', _reviewAction); fd.append('item_id', _rejectItemId); fd.append('review_remarks', reason); fd.append('csrf_token', MENU_WORKFLOW_CSRF);
        fetch(APPROVAL_URL, {method:'POST',body:fd}).then(r=>r.json()).then(data => {
            btn.disabled=false; btn.innerHTML=_reviewAction === 'needs_revision' ? '<i class="fas fa-rotate-left"></i> Return for Revision' : '<i class="fas fa-times"></i> Reject Item';
            if (data.success) {
                const reviewedId = _rejectItemId;
                closeRejectModal();
                const row = document.getElementById('approval-row-' + reviewedId);
                if (row) { row.style.transition='opacity .4s'; row.style.opacity='0'; setTimeout(()=>{row.remove();checkEmptyQueue();},420); }
                showMenuToast(data.message, false);
            } else { errEl.textContent=data.message||'Rejection failed.'; errEl.style.display='block'; }
        }).catch(()=>{ btn.disabled=false; btn.innerHTML=_reviewAction === 'needs_revision' ? '<i class="fas fa-rotate-left"></i> Return for Revision' : '<i class="fas fa-times"></i> Reject Item'; errEl.textContent='Network error.'; errEl.style.display='block'; });
    };

    document.getElementById('rejectModalOverlay').addEventListener('click', function(e){ if(e.target===this) closeRejectModal(); });

    function checkEmptyQueue() {
        const rows = document.querySelectorAll('[id^="approval-row-"]');
        if (!rows.length) {
            const cb = document.querySelector('#tab-menu .card:first-of-type .card-body');
            if (cb) cb.innerHTML='<div style="text-align:center;padding:36px 24px;color:var(--neutral-400);"><i class="fas fa-check-double" style="font-size:2rem;margin-bottom:10px;display:block;color:#a7f3d0;"></i><p style="margin:0;font-size:.9rem;">All caught up!</p></div>';
        }
    }

    function showMenuToast(msg, isErr) {
        let t = document.getElementById('menuApprovalToast');
        if (!t) {
            t = document.createElement('div'); t.id='menuApprovalToast';
            t.style.cssText='position:fixed;bottom:24px;right:24px;z-index:10000;padding:12px 20px;border-radius:10px;font-size:.875rem;font-weight:600;box-shadow:0 8px 24px rgba(0,0,0,.18);max-width:340px;';
            document.body.appendChild(t);
        }
        t.textContent=msg;
        t.style.background=isErr?'#fef2f2':'#f0fdf4';
        t.style.border=isErr?'1.5px solid #fca5a5':'1.5px solid #bbf7d0';
        t.style.color=isErr?'#991b1b':'#166534';
        t.style.display='block';
        clearTimeout(t._timer); t._timer=setTimeout(()=>t.style.display='none',5000);
    }
})();
</script>
</div><!-- /tab-menu -->

<!-- â•â•â• TAB: Reviews & Ratings â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â• -->
<div id="tab-reviews" class="omgr-tab-panel"
     style="<?= $active_tab !== 'reviews' ? 'display:none;' : '' ?>">

<?php if (empty($reviews)): ?>
<div class="card"><div class="card-body">
    <div class="empty-state" style="padding:40px;">
        <div class="empty-icon"><i class="fas fa-star"></i></div>
        <h3>No Reviews Yet</h3>
        <p>Customer reviews will appear here once diners rate the restaurant.</p>
    </div>
</div></div>
<?php else: ?>

<!-- â”€â”€ Summary row â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€ -->
<div style="display:grid;grid-template-columns:auto 1fr 1fr 1fr;gap:16px;margin-bottom:24px;align-items:center;flex-wrap:wrap;">

    <!-- Big average score -->
    <div class="card" style="padding:24px 28px;text-align:center;min-width:140px;">
        <div style="font-family:var(--font-display);font-size:3.2rem;font-weight:800;
                    color:var(--neutral-900);line-height:1;">
            <?= number_format($review_summary['avg'], 1) ?>
        </div>
        <div style="margin:8px 0 4px;">
            <?php for ($s = 1; $s <= 5; $s++): ?>
            <i class="fas fa-star" style="font-size:.9rem;color:<?= $s <= round($review_summary['avg']) ? 'var(--accent-400)' : 'var(--neutral-200)' ?>"></i>
            <?php endfor; ?>
        </div>
        <div style="font-size:.78rem;color:var(--neutral-500);">
            <?= $review_summary['total'] ?> review<?= $review_summary['total'] !== 1 ? 's' : '' ?>
        </div>
    </div>

    <!-- Star distribution bar -->
    <div class="card" style="padding:20px 24px;">
        <div style="font-size:.78rem;font-weight:700;text-transform:uppercase;
                    letter-spacing:.05em;color:var(--neutral-400);margin-bottom:12px;">
            Rating Breakdown
        </div>
        <?php for ($s = 5; $s >= 1; $s--): 
            $cnt  = $review_summary['dist'][$s] ?? 0;
            $pct  = $review_summary['total'] > 0 ? round($cnt / $review_summary['total'] * 100) : 0;
        ?>
        <div style="display:flex;align-items:center;gap:8px;margin-bottom:6px;">
            <span style="font-size:.75rem;color:var(--neutral-600);min-width:12px;"><?= $s ?></span>
            <i class="fas fa-star" style="font-size:.72rem;color:var(--accent-400);"></i>
            <div style="flex:1;background:var(--neutral-100);border-radius:999px;height:8px;overflow:hidden;">
                <div style="width:<?= $pct ?>%;height:100%;background:var(--accent-400);border-radius:999px;transition:width .4s;"></div>
            </div>
            <span style="font-size:.75rem;color:var(--neutral-400);min-width:24px;text-align:right;"><?= $cnt ?></span>
        </div>
        <?php endfor; ?>
    </div>

    <!-- Food rating -->
    <div class="card" style="padding:20px 24px;text-align:center;">
        <div style="font-size:.75rem;font-weight:700;text-transform:uppercase;
                    letter-spacing:.05em;color:var(--neutral-400);margin-bottom:8px;">
            Food Quality
        </div>
        <div style="font-family:var(--font-display);font-size:2.2rem;font-weight:800;
                    color:var(--neutral-900);line-height:1;">
            <?= number_format($review_summary['food_avg'], 1) ?>
        </div>
        <div style="margin-top:6px;">
            <?php for ($s = 1; $s <= 5; $s++): ?>
            <i class="fas fa-star" style="font-size:.8rem;color:<?= $s <= round($review_summary['food_avg']) ? 'var(--accent-400)' : 'var(--neutral-200)' ?>"></i>
            <?php endfor; ?>
        </div>
    </div>

    <!-- Service rating -->
    <div class="card" style="padding:20px 24px;text-align:center;">
        <div style="font-size:.75rem;font-weight:700;text-transform:uppercase;
                    letter-spacing:.05em;color:var(--neutral-400);margin-bottom:8px;">
            Service
        </div>
        <div style="font-family:var(--font-display);font-size:2.2rem;font-weight:800;
                    color:var(--neutral-900);line-height:1;">
            <?= number_format($review_summary['service_avg'], 1) ?>
        </div>
        <div style="margin-top:6px;">
            <?php for ($s = 1; $s <= 5; $s++): ?>
            <i class="fas fa-star" style="font-size:.8rem;color:<?= $s <= round($review_summary['service_avg']) ? 'var(--accent-400)' : 'var(--neutral-200)' ?>"></i>
            <?php endfor; ?>
        </div>
    </div>
</div>

<!-- â”€â”€ Review list â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€ -->
<div class="card">
    <div class="card-header">
        <h3><i class="fas fa-comments" style="color:var(--accent-500);margin-right:8px;"></i>
            Customer Reviews
        </h3>
    </div>
    <div class="card-body" style="padding:0;">
    <?php foreach ($reviews as $rv): ?>
    <div style="padding:20px 24px;border-bottom:1px solid var(--neutral-100);">

        <!-- Reviewer row -->
        <div style="display:flex;align-items:flex-start;gap:12px;">
            <div style="width:38px;height:38px;border-radius:50%;background:var(--primary-100);
                        display:flex;align-items:center;justify-content:center;
                        font-weight:700;color:var(--primary-700);flex-shrink:0;font-size:.9rem;">
                <?= strtoupper(substr($rv['customer_name'], 0, 1)) ?>
            </div>
            <div style="flex:1;min-width:0;">
                <div style="display:flex;align-items:center;gap:10px;flex-wrap:wrap;">
                    <span style="font-weight:700;font-size:.9rem;color:var(--neutral-800);">
                        <?= htmlspecialchars($rv['customer_name']) ?>
                    </span>
                    <!-- Stars -->
                    <span>
                        <?php for ($s = 1; $s <= 5; $s++): ?>
                        <i class="fas fa-star" style="font-size:.78rem;color:<?= $s <= (int)$rv['rating'] ? 'var(--accent-400)' : 'var(--neutral-200)' ?>"></i>
                        <?php endfor; ?>
                    </span>
                    <!-- Sub-ratings pills -->
                    <span style="font-size:.72rem;color:var(--neutral-500);">
                        Food: <strong><?= $rv['food_rating'] ?>/5</strong>
                        &nbsp;&middot;&nbsp;
                        Service: <strong><?= $rv['service_rating'] ?>/5</strong>
                    </span>
                    <span style="margin-left:auto;font-size:.75rem;color:var(--neutral-400);white-space:nowrap;">
                        <?= formatDate($rv['created_at']) ?>
                    </span>
                </div>

                <!-- Comment -->
                <?php if (!empty($rv['comment'])): ?>
                <p style="margin:8px 0 0;font-size:.875rem;color:var(--neutral-700);line-height:1.6;">
                    <?= nl2br(htmlspecialchars($rv['comment'])) ?>
                </p>
                <?php endif; ?>

                <!-- Existing response -->
                <?php if (!empty($rv['response'])): ?>
                <div style="margin-top:12px;padding:12px 16px;background:var(--primary-50);
                            border-left:3px solid var(--primary-400);border-radius:0 8px 8px 0;">
                    <div style="font-size:.72rem;font-weight:700;color:var(--primary-700);
                                text-transform:uppercase;letter-spacing:.05em;margin-bottom:4px;">
                        <i class="fas fa-reply" style="margin-right:4px;"></i> Management Response
                    </div>
                    <p style="font-size:.85rem;color:var(--neutral-700);margin:0;line-height:1.5;">
                        <?= nl2br(htmlspecialchars($rv['response'])) ?>
                    </p>
                </div>
                <?php else: ?>
                <!-- Reply form -->
                <form method="POST" style="margin-top:10px;display:flex;gap:8px;align-items:flex-start;">
                    <input type="hidden" name="reply_review_id" value="<?= $rv['id'] ?>">
                    <textarea name="reply_text" class="form-control" rows="2"
                              placeholder="Write a response to this reviewâ€¦"
                              style="font-size:.82rem;resize:none;flex:1;"></textarea>
                    <button type="submit" class="btn btn-sm btn-outline"
                            style="white-space:nowrap;flex-shrink:0;">
                        <i class="fas fa-reply"></i> Reply
                    </button>
                </form>
                <?php endif; ?>

            </div>
        </div>
    </div>
    <?php endforeach; ?>
    </div>
</div>

<?php endif; ?>
</div><!-- /tab-reviews -->

<!-- â•â•â• TAB: Halal Certification â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â• -->
<div id="tab-halal" class="omgr-tab-panel"
     style="<?= $active_tab !== 'halal' ? 'display:none;' : '' ?>">

<!-- Cert status + progress tracker -->
<div style="display:grid;grid-template-columns:1fr 1fr;gap:20px;margin-bottom:20px;">

<!-- Certificate status card -->
<div class="card">
    <div class="card-header">
        <h3><i class="fas fa-certificate" style="color:var(--accent-500);margin-right:8px;"></i>
            Certificate Status</h3>
    </div>
    <div class="card-body">
        <?php if (!empty($restaurant['certificate_number'])): ?>
        <div style="display:flex;flex-direction:column;gap:10px;font-size:.875rem;">
            <div style="display:flex;gap:8px;align-items:center;">
                <span style="color:var(--neutral-500);min-width:100px;">Number</span>
                <strong><?= htmlspecialchars($restaurant['certificate_number']) ?></strong>
            </div>
            <div style="display:flex;gap:8px;align-items:center;">
                <span style="color:var(--neutral-500);min-width:100px;">Status</span>
                <?= getStatusBadge($restaurant['cert_status'] ?? 'pending') ?>
            </div>
            <?php if (!empty($restaurant['issue_date'])): ?>
            <div style="display:flex;gap:8px;align-items:center;">
                <span style="color:var(--neutral-500);min-width:100px;">Issued</span>
                <span><?= formatDate($restaurant['issue_date']) ?></span>
            </div>
            <?php endif; ?>
            <?php if (!empty($restaurant['expiry_date'])):
                $exp_ts = strtotime($restaurant['expiry_date']);
                $expiring_soon = $exp_ts < strtotime('+60 days');
            ?>
            <div style="display:flex;gap:8px;align-items:center;">
                <span style="color:var(--neutral-500);min-width:100px;">Expires</span>
                <span style="<?= $expiring_soon ? 'color:#dc2626;font-weight:600;' : '' ?>">
                    <?= formatDate($restaurant['expiry_date']) ?>
                    <?= $expiring_soon ? ' <span style="font-size:.72rem;">âš  Expiring soon</span>' : '' ?>
                </span>
            </div>
            <?php endif; ?>
        </div>
        <?php else: ?>
        <div style="text-align:center;padding:20px;color:var(--neutral-400);">
            <i class="fas fa-certificate" style="font-size:2rem;opacity:.3;display:block;margin-bottom:8px;"></i>
            <p style="font-size:.875rem;">No certificate on record yet.</p>
        </div>
        <?php endif; ?>
        <a href="<?= BASE_URL ?>dashboard/business_owner/certificates.php"
           class="btn btn-outline btn-sm" style="width:100%;text-align:center;margin-top:14px;">
            <i class="fas fa-external-link-alt"></i> View Certificates Page
        </a>
    </div>
</div>

<!-- Certification step tracker -->
<div class="card">
    <div class="card-header">
        <h3><i class="fas fa-tasks" style="color:var(--primary-600);margin-right:8px;"></i>
            Application Steps</h3>
    </div>
    <div class="card-body" style="padding:0;">
        <?php
        $cert_steps = [
            ['Letter of Intent',    'letter_of_intent.php',    'fas fa-file-alt'],
            ['Application Form',    'applications.php',        'fas fa-clipboard-list'],
            ['Terms of Reference',  'terms_of_reference.php',  'fas fa-file-contract'],
            ['Inspection Schedule', 'inspection_schedules.php','fas fa-calendar-alt'],
            ['Audit Findings',      'audit_findings.php',      'fas fa-clipboard-check'],
            ['Laboratory',          'laboratory.php',          'fas fa-flask'],
            ['Payments',            'payments.php',            'fas fa-receipt'],
            ['Certificate',         'certificates.php',        'fas fa-certificate'],
        ];
        foreach ($cert_steps as $n => [$lbl, $pg, $ico]):
        ?>
        <div style="display:flex;align-items:center;gap:10px;padding:10px 16px;
                    border-bottom:1px solid var(--neutral-100);">
            <div style="width:24px;height:24px;border-radius:50%;background:var(--neutral-100);
                        display:flex;align-items:center;justify-content:center;
                        font-size:.7rem;font-weight:700;color:var(--neutral-500);flex-shrink:0;">
                <?= $n + 1 ?>
            </div>
            <i class="<?= $ico ?>" style="color:var(--primary-500);width:16px;text-align:center;flex-shrink:0;"></i>
            <span style="flex:1;font-size:.875rem;color:var(--neutral-700);"><?= $lbl ?></span>
            <a href="<?= BASE_URL ?>dashboard/business_owner/<?= $pg ?>"
               class="btn btn-sm btn-outline" style="font-size:.75rem;padding:3px 10px;">
                Open â†’
            </a>
        </div>
        <?php endforeach; ?>
    </div>
</div>

</div><!-- /cert grid -->

<!-- Compliance notes -->
<div class="card">
    <div class="card-header">
        <h3><i class="fas fa-lightbulb" style="color:var(--accent-500);margin-right:8px;"></i>
            Compliance Responsibilities</h3>
    </div>
    <div class="card-body">
        <div style="background:var(--primary-50);border:1px solid var(--primary-200);
                    border-radius:10px;padding:14px 18px;font-size:.875rem;
                    color:var(--primary-800);line-height:1.7;">
            <ul style="margin:0 0 0 16px;padding:0;">
                <li>Keep the restaurant profile and operating hours current at all times.</li>
                <li>Ensure all menu items display accurate, Halal-compliant ingredient details.</li>
                <li>Monitor certificate expiry and begin renewal at least <strong>60 days</strong> in advance.</li>
                <li>Upload updated permits and compliance documents via the Applications step above.</li>
            </ul>
        </div>
    </div>
</div>

</div><!-- /tab-halal -->


<!-- â•â•â• TAB: Expenses & Cost Tracking â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â• -->

<!-- â•â•â• TAB: Requisitions â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â• -->
<div id="tab-requisitions" class="omgr-tab-panel"
     style="<?= $active_tab !== 'requisitions' ? 'display:none;' : '' ?>">

<?php if (empty($pending_requisitions)): ?>
<div class="card"><div class="card-body">
    <div class="empty-state" style="padding:40px;">
        <div class="empty-icon"><i class="fas fa-clipboard-list"></i></div>
        <h3>No Grocery Lists</h3>
        <p>When the Chef submits a grocery list it will appear here.</p>
    </div>
</div></div>
<?php else: ?>

<?php foreach ($pending_requisitions as $req):
    $statusColors = [
        'pending'      => ['#fef9c3','#854d0e'],
        'acknowledged' => ['#dbeafe','#1e40af'],
        'approved'     => ['#dcfce7','#166534'],
        'fulfilled'    => ['#e0f2fe','#0369a1'],
    ];
    [$sbg,$sfg] = $statusColors[$req['status']] ?? ['#f1f5f9','#334155'];
    $is_approved = in_array($req['status'], ['approved', 'fulfilled']);
?>
<div class="card" style="margin-bottom:16px;">
    <div class="card-header" style="display:flex;align-items:center;justify-content:space-between;gap:12px;flex-wrap:wrap;">
        <div>
            <span style="font-weight:700;font-size:.95rem;color:var(--neutral-800);">
                GL-<?= $req['id'] ?> &mdash; <?= htmlspecialchars($req['requester_name']) ?>
            </span>
            <span style="display:inline-flex;align-items:center;gap:5px;padding:3px 10px;
                         border-radius:999px;font-size:.75rem;font-weight:700;
                         background:<?= $sbg ?>;color:<?= $sfg ?>;margin-left:10px;"
                  class="req-status-badge" data-status="<?= htmlspecialchars($req['status']) ?>">
                <?= ucfirst($req['status']) ?>
            </span>
            <div style="font-size:.78rem;color:var(--neutral-400);margin-top:2px;">
                <?= formatDate($req['created_at']) ?>
            </div>
        </div>
        <div style="display:flex;gap:8px;flex-wrap:wrap;">
            <a href="<?= BASE_URL ?>dashboard/business_owner/print_requisition.php?req_id=<?= $req['id'] ?>"
               target="_blank" class="btn btn-sm btn-outline">
                <i class="fas fa-print"></i> Print Slip
            </a>
            <?php if ($req['status'] === 'pending'): ?>
            <button data-ack-id="<?= $req['id'] ?>"
                    onclick="acknowledgeReq(<?= $req['id'] ?>)"
                    class="btn btn-sm btn-primary">
                <i class="fas fa-check"></i> Acknowledge
            </button>
            <?php endif; ?>
        </div>
    </div>
    <?php if (!empty($req['notes'])): ?>
    <div style="padding:10px 24px;background:var(--neutral-50);font-size:.85rem;
                color:var(--neutral-600);border-bottom:1px solid var(--neutral-100);">
        <i class="fas fa-sticky-note" style="margin-right:6px;color:var(--accent-500);"></i>
        <?= htmlspecialchars($req['notes']) ?>
    </div>
    <?php endif; ?>

    <!-- Approval form: Ops Manager enters amount spent per ingredient then approves or rejects -->
    <div class="card-body" style="padding:0;">
    <form onsubmit="approveReq(event, <?= $req['id'] ?>)">
        <div class="table-responsive">
        <table class="table" style="margin:0;">
            <thead style="background:var(--neutral-50);font-size:.75rem;text-transform:uppercase;color:var(--neutral-500);">
                <tr>
                    <th>Ingredient</th>
                    <th>Qty Needed</th>
                    <th>Price / Unit</th>
                    <th>Total Amount</th>
                    <th style="min-width:130px;">Amount Spent (&#8369;)</th>
                    <th>Item Notes</th>
                </tr>
            </thead>
            <tbody>
            <?php foreach ($req['items'] as $idx => $item):
                $om_qty        = max((float)($item['quantity'] ?? 1), 0.001);
                $om_unit_price = ($item['unit_price'] !== null && $item['unit_price'] !== '')
                    ? (float)$item['unit_price']
                    : (($item['estimated_cost'] !== null) ? round((float)$item['estimated_cost'] / $om_qty, 2) : null);
                $om_total = ($om_unit_price !== null)
                    ? $om_unit_price * $om_qty
                    : (float)($item['estimated_cost'] ?? 0);
                $om_total_disp = ($item['estimated_cost'] !== null || $om_unit_price !== null)
                    ? '&#8369;' . number_format($om_total, 2) : '&mdash;';
            ?>
            <input type="hidden" name="items[<?= $idx ?>][item_id]" value="<?= $item['id'] ?>">
            <tr>
                <td style="font-weight:600;font-size:.875rem;">
                    <?= htmlspecialchars($item['ingredient_name']) ?>
                </td>
                <td style="font-size:.85rem;color:var(--neutral-600);font-weight:700;">
                    <?= number_format($om_qty, 2) ?> <?= htmlspecialchars($item['unit']) ?>
                </td>
                <td style="font-size:.85rem;color:var(--neutral-500);">
                    <?php if ($om_unit_price !== null): ?>
                        &#8369;<?= number_format($om_unit_price, 2) ?>
                        <span style="font-size:.72rem;color:var(--neutral-400);">/ <?= htmlspecialchars($item['unit']) ?></span>
                    <?php else: ?>
                        &mdash;
                    <?php endif; ?>
                </td>
                <td style="font-size:.85rem;font-weight:700;color:#1d4ed8;">
                    <?= $om_total_disp ?>
                </td>
                <td>
                    <input type="number" step="0.01" min="0"
                           name="items[<?= $idx ?>][amount_spent]"
                           class="form-control" style="width:120px;font-size:.85rem;height:34px;"
                           placeholder="0.00"
                           value="<?= number_format($om_total, 2) ?>"
                           <?= $is_approved ? 'readonly style="width:120px;font-size:.85rem;height:34px;background:#f8fafc;"' : '' ?>>
                </td>
                <td style="font-size:.82rem;color:var(--neutral-500);">
                    <?= $item['notes'] ? htmlspecialchars($item['notes']) : '&mdash;' ?>
                </td>
            </tr>
            <?php endforeach; ?>
            </tbody>
        </table>
        </div>
        <!-- List totals summary -->
        <?php
        $ops_total_est   = 0;
        $ops_total_spent = 0;
        foreach ($req['items'] as $oi) {
            $oi_qty  = max((float)($oi['quantity'] ?? 1), 0.001);
            $oi_up   = ($oi['unit_price'] !== null && $oi['unit_price'] !== '')
                ? (float)$oi['unit_price']
                : (($oi['estimated_cost'] !== null) ? round((float)$oi['estimated_cost'] / $oi_qty, 2) : null);
            $ops_total_est   += ($oi_up !== null) ? $oi_up * $oi_qty : (float)($oi['estimated_cost'] ?? 0);
            $ops_total_spent += (float)($oi['amount_spent'] ?? 0);
        }
        ?>
        <?php if ($ops_total_est > 0): ?>
        <div style="padding:10px 24px;background:#f0f9ff;border-top:1px solid #bae6fd;
                    display:flex;align-items:center;justify-content:flex-end;gap:24px;font-size:.85rem;">
            <div style="display:flex;align-items:center;gap:6px;">
                <span style="color:var(--neutral-500);font-weight:600;">Total Estimated:</span>
                <strong style="color:#1d4ed8;font-size:.92rem;">&#8369;<?= number_format($ops_total_est, 2) ?></strong>
            </div>
            <?php if ($ops_total_spent > 0): ?>
            <div style="display:flex;align-items:center;gap:6px;">
                <span style="color:var(--neutral-500);font-weight:600;">Total Approved:</span>
                <strong style="color:#065f46;font-size:.92rem;">&#8369;<?= number_format($ops_total_spent, 2) ?></strong>
            </div>
            <?php endif; ?>
        </div>
        <?php endif; ?>
        <!-- Ops notes -->
        <div style="padding:12px 24px;background:var(--neutral-50);border-top:1px solid var(--neutral-100);">
            <label style="font-size:.8rem;font-weight:700;color:var(--neutral-600);display:block;margin-bottom:6px;">
                <i class="fas fa-comment-alt" style="margin-right:4px;color:var(--accent-500);"></i>
                Ops Manager Notes (optional)
            </label>
            <textarea name="ops_notes" class="form-control" rows="2"
                      style="font-size:.82rem;resize:vertical;"
                      placeholder="Budget notes, purchase instructions, delivery schedule&hellip;"
                      <?= $is_approved ? 'readonly style="font-size:.82rem;resize:vertical;background:#f8fafc;"' : '' ?>><?= $is_approved ? htmlspecialchars($req['ops_notes'] ?? '') : '' ?></textarea>
        </div>
        <div style="padding:14px 24px;background:var(--neutral-50);
                    border-top:1px solid var(--neutral-100);
                    display:flex;gap:10px;justify-content:flex-end;align-items:center;">
            <?php if ($is_approved): ?>
            <!-- Approved/Fulfilled — prompt Ops Manager to log the actual spend as an expense -->
            <?php if ($req['status'] === 'fulfilled'): ?>
            <div style="flex:1;font-size:.82rem;color:#0369a1;display:flex;align-items:center;gap:6px;">
                <i class="fas fa-box-open"></i>
                Fulfilled — Chef received items on <?= !empty($req['updated_at']) ? date('M j, Y', strtotime($req['updated_at'])) : 'N/A' ?>
            </div>
            <?php else: ?>
            <div style="flex:1;font-size:.82rem;color:#166534;display:flex;align-items:center;gap:6px;">
                <i class="fas fa-check-circle"></i>
                Approved on <?= !empty($req['approved_at']) ? date('M j, Y', strtotime($req['approved_at'])) : 'N/A' ?>
            </div>
            <?php endif; ?>
            <a href="?tab=expenses"
               class="btn btn-success" style="padding:8px 20px;">
                <i class="fas fa-receipt"></i> Log Expenses for This List
            </a>
            <?php else: ?>
            <button type="button"
                    onclick="rejectReq(<?= $req['id'] ?>)"
                    class="btn btn-sm btn-danger" style="padding:8px 18px;">
                <i class="fas fa-times-circle"></i> Reject Requisition
            </button>
            <button type="submit" class="btn btn-success" style="padding:8px 20px;"
                <?php if ($req['status'] === 'pending'): ?>
                    disabled title="Acknowledge this list first before approving"
                    style="padding:8px 20px;opacity:.5;cursor:not-allowed;"
                <?php endif; ?>>
                <i class="fas fa-check-double"></i> Approve &amp; Notify Chef
            </button>
            <?php endif; ?>
        </div>
    </form>
    </div>
</div>
<?php endforeach; ?>
<?php endif; ?>

</div><!-- /tab-requisitions -->

<div id="tab-expenses" class="omgr-tab-panel"
     style="<?= $active_tab !== 'expenses' ? 'display:none;' : '' ?>">

<?php if (!$rest_id): ?>
<div class="alert alert-danger">
    <i class="fas fa-exclamation-circle"></i>
    No restaurant is linked to your account. Ask the business owner to assign you first.
</div>
<?php else: ?>

<!-- â”€â”€ Period filter bar â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€ -->
<div style="display:flex;align-items:center;justify-content:space-between;
            flex-wrap:wrap;gap:12px;margin-bottom:20px;">
    <div style="display:flex;gap:6px;flex-wrap:wrap;">
        <?php
        $filter_labels = ['all' => 'All Time'] + array_map(fn($v) => $v, EXPENSE_PERIODS);
        foreach ($filter_labels as $fk => $fl):
            $is_active_f = ($exp_filter === $fk);
        ?>
        <a href="?tab=expenses&exp_period=<?= $fk ?>"
           style="display:inline-flex;align-items:center;gap:6px;padding:7px 16px;
                  border-radius:999px;font-size:.82rem;font-weight:600;text-decoration:none;
                  border:1.5px solid <?= $is_active_f ? 'var(--primary-600)' : 'var(--neutral-200)' ?>;
                  background:<?= $is_active_f ? 'var(--primary-600)' : '#fff' ?>;
                  color:<?= $is_active_f ? '#fff' : 'var(--neutral-600)' ?>;
                  transition:all .15s;">
            <?php if ($fk==='daily'):   ?><i class="fas fa-sun"></i><?php
                elseif($fk==='weekly'): ?><i class="fas fa-calendar-week"></i><?php
                elseif($fk==='monthly'):?><i class="fas fa-calendar-alt"></i><?php
                else:                   ?><i class="fas fa-list"></i><?php endif; ?>
            <?= $fl ?>
        </a>
        <?php endforeach; ?>
    </div>
    <!-- Add expense trigger -->
    <button type="button" id="expFormToggle"
            onclick="document.getElementById('expFormWrap').classList.toggle('exp-form-hidden')"
            style="display:inline-flex;align-items:center;gap:8px;padding:9px 20px;
                   background:var(--primary-600);color:#fff;border:none;border-radius:10px;
                   font-size:.875rem;font-weight:700;cursor:pointer;
                   box-shadow:0 2px 8px rgba(37,99,235,.25);transition:background .15s;">
        <i class="fas fa-plus-circle"></i> Log Expense
    </button>
</div>

<!-- â”€â”€ Add-expense form (collapsible) â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€ -->
<div id="expFormWrap" class="exp-form-hidden"
     style="margin-bottom:24px;transition:all .2s;">
<div class="card" style="border:2px solid var(--primary-200);border-radius:14px;">
    <div class="card-header" style="background:var(--primary-50);border-radius:12px 12px 0 0;">
        <h3 style="margin:0;font-size:1rem;color:var(--primary-800);">
            <i class="fas fa-file-invoice-dollar" style="margin-right:8px;"></i>
            Log a New Expense
        </h3>
        <span style="font-size:.78rem;color:var(--primary-600);font-weight:500;">
            Attach a receipt to substantiate this cost entry
        </span>
    </div>
    <div class="card-body">
    <form method="POST" enctype="multipart/form-data" id="expenseForm">
    <input type="hidden" name="save_expense" value="1">

    <!-- Row 1: Category + Period + Date -->
    <div style="display:grid;grid-template-columns:1fr 1fr 1fr;gap:14px;margin-bottom:14px;">
        <div class="form-group" style="margin:0;">
            <label class="form-label">
                <i class="fas fa-tag" style="color:var(--primary-500);margin-right:4px;"></i>
                Category <span style="color:red;">*</span>
            </label>
            <select name="exp_category" class="form-control" required id="expCategorySelect"
                    onchange="document.getElementById('expCategoryOther').style.display=this.value==='Other'?'block':'none'">
                <?php foreach (array_keys(EXPENSE_CATEGORIES) as $ec): ?>
                <option value="<?= $ec ?>"><?= $ec ?></option>
                <?php endforeach; ?>
            </select>
            <input type="text" name="exp_category_custom" id="expCategoryOther"
                   placeholder="Specify other categoryâ€¦"
                   class="form-control" style="display:none;margin-top:6px;font-size:.85rem;">
        </div>
        <div class="form-group" style="margin:0;">
            <label class="form-label">
                <i class="fas fa-clock" style="color:var(--primary-500);margin-right:4px;"></i>
                Reporting Period <span style="color:red;">*</span>
            </label>
            <select name="exp_period" class="form-control" required>
                <?php foreach (EXPENSE_PERIODS as $_ep_key => $_ep_label): ?>
                <option value="<?= $_ep_key ?>"><?= ucfirst($_ep_key) ?> (<?= $_ep_label ?>'s expense)</option>
                <?php endforeach; ?>
            </select>
        </div>
        <div class="form-group" style="margin:0;">
            <label class="form-label">
                <i class="fas fa-calendar-day" style="color:var(--primary-500);margin-right:4px;"></i>
                Expense Date <span style="color:red;">*</span>
            </label>
            <input type="date" name="exp_date" class="form-control" required
                   value="<?= date('Y-m-d') ?>" max="<?= date('Y-m-d') ?>">
        </div>
    </div>

    <!-- Dynamic Itemized Expense List -->
    <div style="margin-bottom:14px;">
        <div style="display:flex;align-items:center;justify-content:space-between;margin-bottom:8px;">
            <label class="form-label" style="margin:0;font-weight:700;">
                <i class="fas fa-list-ol" style="color:var(--primary-500);margin-right:4px;"></i>
                Itemized Line Items <span style="color:red;">*</span>
            </label>
            <button type="button" class="btn btn-sm btn-outline" id="addItemRowBtn" style="font-size:.78rem;padding:4px 10px;">
                <i class="fas fa-plus"></i> Add Item Row
            </button>
        </div>
        <div style="overflow-x:auto;border:1px solid var(--neutral-200);border-radius:10px;background:#fff;margin-bottom:8px;">
            <table style="width:100%;border-collapse:collapse;font-size:.85rem;min-width:500px;" id="itemizedTable">
                <thead>
                    <tr style="border-bottom:1px solid var(--neutral-200);background:#f8fafc;">
                        <th style="padding:10px 12px;text-align:left;font-weight:700;color:var(--neutral-500);width:50%;">Item Description *</th>
                        <th style="padding:10px 12px;text-align:right;font-weight:700;color:var(--neutral-500);width:20%;">Price (&#8369;) *</th>
                        <th style="padding:10px 12px;text-align:center;font-weight:700;color:var(--neutral-500);width:12%;">Qty *</th>
                        <th style="padding:10px 12px;text-align:right;font-weight:700;color:var(--neutral-500);width:15%;">Line Total</th>
                        <th style="padding:10px 12px;text-align:center;font-weight:700;color:var(--neutral-500);width:3%;"></th>
                    </tr>
                </thead>
                <tbody id="itemizedBody">
                    <!-- Javascript will dynamically add rows here -->
                </tbody>
            </table>
        </div>
        <div style="font-size:.75rem;color:var(--neutral-400);">
            <i class="fas fa-info-circle"></i> Add at least one item. The main Amount field below is read-only and automatically calculated.
        </div>
    </div>

    <!-- Row 3: Amount + Receipt upload -->
    <div style="display:grid;grid-template-columns:1fr 2fr;gap:14px;margin-bottom:14px;">
        <div class="form-group" style="margin:0;">
            <label class="form-label">
                <i class="fas fa-peso-sign" style="color:var(--primary-500);margin-right:4px;"></i>
                Amount (&#8369;) <span style="color:red;">*</span>
            </label>
            <input type="number" name="exp_amount" id="expAmountInput" class="form-control"
                   min="0.01" step="0.01" placeholder="0.00" required readonly
                   style="font-size:1.1rem;font-weight:700;background:#f1f5f9;cursor:not-allowed;">
        </div>
        <div class="form-group" style="margin:0;">
            <label class="form-label">
                <i class="fas fa-paperclip" style="color:var(--primary-500);margin-right:4px;"></i>
                Receipt / Invoice
                <span style="color:var(--neutral-400);font-weight:400;">(photo or PDF, max 20 MB)</span>
            </label>
            <!-- Drag-and-drop receipt zone -->
            <label id="receiptDropZone"
                   style="display:flex;align-items:center;gap:14px;padding:14px 18px;
                          border:2px dashed var(--neutral-300);border-radius:10px;
                          background:#f8fafc;cursor:pointer;transition:border-color .15s;">
                <i class="fas fa-file-upload" style="font-size:1.4rem;color:var(--neutral-400);"
                   id="receiptIcon"></i>
                <div style="flex:1;min-width:0;">
                    <div style="font-size:.85rem;font-weight:600;color:var(--neutral-600);"
                         id="receiptLabel">Click to upload or drag &amp; drop a receipt</div>
                    <div style="font-size:.75rem;color:var(--neutral-400);">
                        JPG, PNG, WebP, PDF accepted
                    </div>
                </div>
                <input type="file" name="exp_receipt" id="expReceiptInput"
                       accept="image/jpeg,image/png,image/webp,application/pdf"
                       style="display:none;"
                       onchange="previewExpReceipt(this)">
            </label>
            <div id="receiptPreviewWrap" style="display:none;margin-top:8px;"></div>
        </div>
    </div>

    <!-- Submit row -->
    <div style="display:flex;align-items:center;justify-content:flex-end;gap:10px;
                padding-top:12px;border-top:1px solid var(--neutral-100);">
        <button type="button"
                onclick="document.getElementById('expFormWrap').classList.add('exp-form-hidden')"
                class="btn btn-outline">
            Cancel
        </button>
        <button type="submit" class="btn btn-primary"
                style="font-weight:700;display:inline-flex;align-items:center;gap:8px;">
            <i class="fas fa-save"></i> Save Expense
        </button>
    </div>
    </form>
    </div>
</div>
</div>

<!-- â”€â”€ Summary stat cards â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€ -->
<?php
// Compute per-category totals for this filter
$cat_totals = [];
foreach ($exp_records as $ex) {
    $cat_totals[$ex['category']] = ($cat_totals[$ex['category']] ?? 0) + (float)$ex['amount'];
}
arsort($cat_totals);
$top_cat      = array_key_first($cat_totals) ?? 'None';
$top_cat_amt  = $cat_totals[$top_cat] ?? 0;
$exp_count    = count($exp_records);

// Fetch itemized lines map
$expense_ids = array_column($exp_records, 'id');
$expense_items_map = [];
if (!empty($expense_ids)) {
    $ids_str = implode(',', array_map('intval', $expense_ids));
    $items_res = $conn->query("SELECT * FROM restaurant_expense_items WHERE expense_id IN ($ids_str)");
    if ($items_res) {
        while ($row = $items_res->fetch_assoc()) {
            $expense_items_map[$row['expense_id']][] = $row;
        }
    }
}

// Category icon map - sourced from EXPENSE_CATEGORIES constant
$cat_icon_map = EXPENSE_CATEGORIES;
?>
<div style="display:grid;grid-template-columns:repeat(3,1fr);gap:16px;margin-bottom:24px;">

    <!-- Total Spent -->
    <div class="card" style="border-left:4px solid var(--primary-500);padding:0;">
        <div class="card-body" style="padding:18px 20px;">
            <div style="font-size:.75rem;font-weight:700;color:var(--neutral-400);
                        text-transform:uppercase;letter-spacing:.08em;margin-bottom:8px;">
                <i class="fas fa-receipt" style="margin-right:4px;"></i>Total Expenses
            </div>
            <div style="font-size:1.9rem;font-weight:800;color:var(--primary-700);line-height:1;">
                &#8369;<?= number_format($exp_total, 2) ?>
            </div>
            <div style="font-size:.78rem;color:var(--neutral-400);margin-top:6px;">
                <?= ucfirst($exp_filter === 'all' ? 'all-time' : $exp_filter) ?> &middot; <?= $exp_count ?> entr<?= $exp_count===1?'y':'ies' ?>
            </div>
        </div>
    </div>

    <!-- Top Category -->
    <div class="card" style="border-left:4px solid #16a34a;padding:0;">
        <div class="card-body" style="padding:18px 20px;">
            <div style="font-size:.75rem;font-weight:700;color:var(--neutral-400);
                        text-transform:uppercase;letter-spacing:.08em;margin-bottom:8px;">
                <i class="fas fa-chart-bar" style="margin-right:4px;"></i>Highest Category
            </div>
            <div style="font-size:1.1rem;font-weight:800;color:#15803d;line-height:1.2;">
                <?php
                $ci = $cat_icon_map[$top_cat] ?? $cat_icon_map['Other'];
                echo '<i class="'.$ci[0].'" style="color:'.$ci[1].';margin-right:6px;"></i>';
                echo htmlspecialchars($top_cat);
                ?>
            </div>
            <div style="font-size:.78rem;color:var(--neutral-400);margin-top:6px;">
                &#8369;<?= number_format($top_cat_amt, 2) ?>
                <?= $exp_total > 0 ? '(' . round(($top_cat_amt / $exp_total) * 100) . '%)' : '' ?>
            </div>
        </div>
    </div>

    <!-- Category breakdown mini -->
    <div class="card" style="border-left:4px solid #7c3aed;padding:0;">
        <div class="card-body" style="padding:18px 20px;">
            <div style="font-size:.75rem;font-weight:700;color:var(--neutral-400);
                        text-transform:uppercase;letter-spacing:.08em;margin-bottom:8px;">
                <i class="fas fa-layer-group" style="margin-right:4px;"></i>Categories Used
            </div>
            <div style="font-size:1.9rem;font-weight:800;color:#6d28d9;line-height:1;">
                <?= count($cat_totals) ?>
            </div>
            <div style="font-size:.78rem;color:var(--neutral-400);margin-top:6px;">
                distinct expense categories
            </div>
        </div>
    </div>
</div>

<!-- â”€â”€ Category breakdown bar â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€ -->
<?php if (!empty($cat_totals)): ?>
<div class="card" style="margin-bottom:24px;">
    <div class="card-header">
        <h3><i class="fas fa-chart-pie" style="color:var(--primary-600);margin-right:8px;"></i>
            Breakdown by Category</h3>
        <span style="font-size:.78rem;color:var(--neutral-400);">
            <?= ucfirst($exp_filter==='all'?'All time':$exp_filter) ?> total: &#8369;<?= number_format($exp_total,2) ?>
        </span>
    </div>
    <div class="card-body" style="display:flex;flex-direction:column;gap:10px;">
        <?php foreach ($cat_totals as $cname => $camt):
            $pct  = $exp_total > 0 ? ($camt / $exp_total) * 100 : 0;
            $info = $cat_icon_map[$cname] ?? $cat_icon_map['Other'];
        ?>
        <div style="display:flex;align-items:center;gap:12px;">
            <div style="width:30px;height:30px;border-radius:8px;
                        background:<?= $info[2] ?>;display:flex;align-items:center;
                        justify-content:center;flex-shrink:0;">
                <i class="<?= $info[0] ?>" style="color:<?= $info[1] ?>;font-size:.8rem;"></i>
            </div>
            <div style="flex:1;min-width:0;">
                <div style="display:flex;justify-content:space-between;
                            font-size:.82rem;font-weight:600;color:var(--neutral-700);margin-bottom:4px;">
                    <span><?= htmlspecialchars($cname) ?></span>
                    <span style="color:<?= $info[1] ?>;">&#8369;<?= number_format($camt, 2) ?> (<?= round($pct) ?>%)</span>
                </div>
                <div style="height:7px;background:var(--neutral-100);border-radius:99px;overflow:hidden;">
                    <div style="height:100%;width:<?= round($pct) ?>%;
                                background:<?= $info[1] ?>;border-radius:99px;
                                transition:width .6s ease;"></div>
                </div>
            </div>
        </div>
        <?php endforeach; ?>
    </div>
</div>
<?php endif; ?>

<!-- â”€â”€ Expense records table â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€ -->
<div class="card">
    <div class="card-header">
        <h3>
            <i class="fas fa-list-alt" style="color:var(--primary-600);margin-right:8px;"></i>
            Expense Records
        </h3>
        <div style="display:flex;align-items:center;gap:10px;">
            <?php if ($exp_count): ?>
            <span style="background:#eff6ff;color:#1d4ed8;border:1px solid #bfdbfe;
                         border-radius:999px;padding:3px 12px;font-size:.72rem;font-weight:700;">
                <?= $exp_count ?> record<?= $exp_count===1?'':'s' ?>
            </span>
            <?php endif; ?>
        </div>
    </div>

    <?php if (empty($exp_records)): ?>
    <div class="card-body">
        <div class="empty-state" style="padding:48px 20px;text-align:center;">
            <div style="width:64px;height:64px;background:var(--neutral-100);border-radius:50%;
                        display:flex;align-items:center;justify-content:center;
                        margin:0 auto 16px;font-size:1.6rem;color:var(--neutral-300);">
                <i class="fas fa-receipt"></i>
            </div>
            <h3 style="font-size:1.1rem;color:var(--neutral-600);margin-bottom:8px;">
                No expenses logged
                <?= $exp_filter !== 'all' ? 'for this period' : 'yet' ?>
            </h3>
            <p style="font-size:.875rem;color:var(--neutral-400);">
                Use the <strong>Log Expense</strong> button above to record daily costs,
                ingredient purchases, utility bills, and more.
            </p>
        </div>
    </div>

    <?php else: ?>
    <div style="overflow-x:auto;">
    <table style="width:100%;border-collapse:collapse;font-size:.875rem;">
        <thead>
            <tr style="border-bottom:2px solid var(--neutral-100);">
                <th style="padding:10px 16px;text-align:left;font-size:.75rem;font-weight:700;
                           color:var(--neutral-400);text-transform:uppercase;letter-spacing:.06em;
                           white-space:nowrap;">Date</th>
                <th style="padding:10px 12px;text-align:left;font-size:.75rem;font-weight:700;
                           color:var(--neutral-400);text-transform:uppercase;letter-spacing:.06em;">Logged By</th>
                <th style="padding:10px 12px;text-align:left;font-size:.75rem;font-weight:700;
                           color:var(--neutral-400);text-transform:uppercase;letter-spacing:.06em;">Category</th>
                <th style="padding:10px 12px;text-align:left;font-size:.75rem;font-weight:700;
                           color:var(--neutral-400);text-transform:uppercase;letter-spacing:.06em;">Purpose / Description</th>
                <th style="padding:10px 12px;text-align:left;font-size:.75rem;font-weight:700;
                           color:var(--neutral-400);text-transform:uppercase;letter-spacing:.06em;
                           white-space:nowrap;">Period</th>
                <th style="padding:10px 12px;text-align:right;font-size:.75rem;font-weight:700;
                           color:var(--neutral-400);text-transform:uppercase;letter-spacing:.06em;">Amount</th>
                <th style="padding:10px 12px;text-align:center;font-size:.75rem;font-weight:700;
                           color:var(--neutral-400);text-transform:uppercase;letter-spacing:.06em;">Receipt</th>
                <th style="padding:10px 16px;text-align:center;font-size:.75rem;font-weight:700;
                           color:var(--neutral-400);text-transform:uppercase;letter-spacing:.06em;">Actions</th>
            </tr>
        </thead>
        <tbody>
        <?php foreach ($exp_records as $ex):
            $ex_info = $cat_icon_map[$ex['category']] ?? $cat_icon_map['Other'];
            $period_colors = ['daily'=>['#1d4ed8','#eff6ff'],'weekly'=>['#6d28d9','#ede9fe'],'monthly'=>['#15803d','#dcfce7']];
            [$pc,$pb] = $period_colors[$ex['period_tag']] ?? ['#64748b','#f1f5f9'];
            $has_receipt = !empty($ex['receipt_path']);
        ?>
        <tr style="border-bottom:1px solid var(--neutral-50);transition:background .12s;"
            onmouseover="this.style.background='#f8fafc'" onmouseout="this.style.background=''">
            <!-- Date -->
            <td style="padding:12px 16px;white-space:nowrap;vertical-align:top;">
                <div style="font-weight:700;color:var(--neutral-800);">
                    <?= date('M j, Y', strtotime($ex['expense_date'])) ?>
                </div>
                <div style="font-size:.72rem;color:var(--neutral-400);">
                    <?= date('g:i A', strtotime($ex['created_at'])) ?>
                </div>
            </td>
            <!-- Logged By Attribution -->
            <td style="padding:12px 12px;vertical-align:top;white-space:nowrap;">
                <?= renderExpenseAttributionBadge($ex['added_by_role'], $ex['logged_by_name'] ?? '', $ex['logged_by_role_label'] ?? '') ?>
            </td>
            <!-- Category -->
            <td style="padding:12px 12px;vertical-align:top;">
                <div style="display:inline-flex;align-items:center;gap:6px;
                            background:<?= $ex_info[2] ?>;color:<?= $ex_info[1] ?>;
                            border-radius:8px;padding:4px 10px;font-size:.78rem;font-weight:700;">
                    <i class="<?= $ex_info[0] ?>"></i>
                    <?= htmlspecialchars($ex['category']) ?>
                </div>
            </td>
            <!-- Description -->
            <td style="padding:12px 12px;vertical-align:top;max-width:320px;">
                <div style="color:var(--neutral-700);line-height:1.5;font-size:.875rem;font-weight:600;">
                    <?= nl2br(htmlspecialchars($ex['description'])) ?>
                </div>
                <?php if (!empty($expense_items_map[$ex['id']])): ?>
                <div style="margin-top:8px;border-top:1px dashed var(--neutral-200);padding-top:6px;">
                    <div style="font-size:.72rem;font-weight:700;color:var(--neutral-400);text-transform:uppercase;letter-spacing:.04em;margin-bottom:4px;">
                        Line Items Breakdown
                    </div>
                    <ul style="margin:0;padding-left:14px;font-size:.78rem;color:var(--neutral-600);list-style-type:circle;">
                        <?php foreach ($expense_items_map[$ex['id']] as $item): ?>
                        <li style="margin-bottom:2px;">
                            <strong><?= htmlspecialchars($item['description']) ?></strong>
                            <span style="color:var(--neutral-400);"> &middot; </span>
                            <?= $item['quantity'] ?> &times; &#8369;<?= number_format($item['unit_price'], 2) ?>
                            <span style="color:var(--neutral-400);"> = </span>
                            <span style="font-weight:600;color:var(--neutral-700);">&#8369;<?= number_format($item['line_total'], 2) ?></span>
                        </li>
                        <?php endforeach; ?>
                    </ul>
                </div>
                <?php endif; ?>
            </td>
            <!-- Period tag -->
            <td style="padding:12px 12px;vertical-align:top;white-space:nowrap;">
                <span style="display:inline-block;background:<?= $pb ?>;color:<?= $pc ?>;
                             border-radius:999px;padding:3px 10px;font-size:.72rem;font-weight:700;
                             text-transform:capitalize;">
                    <?= htmlspecialchars($ex['period_tag']) ?>
                </span>
            </td>
            <!-- Amount -->
            <td style="padding:12px 12px;text-align:right;vertical-align:top;white-space:nowrap;">
                <span style="font-size:1rem;font-weight:800;color:var(--primary-700);">
                    &#8369;<?= number_format((float)$ex['amount'], 2) ?>
                </span>
            </td>
            <!-- Receipt -->
            <td style="padding:12px 12px;text-align:center;vertical-align:top;">
                <?php if ($has_receipt): ?>
                <?php
                    $rp   = $ex['receipt_path'];
                    $rurl = BASE_URL . 'uploads/' . htmlspecialchars($rp);
                    $is_img = preg_match('/\.(jpg|jpeg|png|webp)$/i', $rp);
                ?>
                <a href="<?= $rurl ?>" target="_blank" title="View receipt"
                   style="display:inline-flex;align-items:center;gap:5px;
                          color:var(--primary-600);font-size:.78rem;font-weight:600;
                          text-decoration:none;padding:4px 10px;border:1px solid var(--primary-200);
                          border-radius:8px;background:var(--primary-50);">
                    <i class="fas <?= $is_img ? 'fa-image' : 'fa-file-pdf' ?>"></i>
                    View
                </a>
                <?php else: ?>
                <span style="font-size:.72rem;color:var(--neutral-300);font-style:italic;">No receipt</span>
                <?php endif; ?>
            </td>
            <!-- Delete -->
            <td style="padding:12px 16px;text-align:center;vertical-align:top;">
                <form method="POST" style="display:inline;margin:0;"
                      onsubmit="return confirm('Remove this expense entry permanently?')">
                    <input type="hidden" name="delete_expense" value="<?= $ex['id'] ?>">
                    <button type="submit" class="btn btn-sm btn-danger"
                            title="Delete expense" style="font-size:.78rem;">
                        <i class="fas fa-trash"></i>
                    </button>
                </form>
            </td>
        </tr>
        <?php endforeach; ?>
        </tbody>
        <tfoot>
            <tr style="border-top:2px solid var(--neutral-200);background:#f8fafc;">
                <td colspan="5" style="padding:12px 16px;font-weight:700;color:var(--neutral-600);
                                       font-size:.82rem;text-transform:uppercase;letter-spacing:.05em;">
                    <?= ucfirst($exp_filter==='all'?'All-time':$exp_filter) ?> Total
                </td>
                <td style="padding:12px 12px;text-align:right;font-size:1.1rem;
                           font-weight:800;color:var(--primary-700);white-space:nowrap;">
                    &#8369;<?= number_format($exp_total, 2) ?>
                </td>
                <td colspan="2"></td>
            </tr>
        </tfoot>
    </table>
    </div>
    <?php endif; ?>
</div>

<?php endif; ?>
</div><!-- /tab-expenses -->


<!-- ══ TAB: Other Inflows ══════════════════════════════════════════════════ -->
<div id="tab-other_inflows" class="omgr-tab-panel"
     style="<?= $active_tab !== 'other_inflows' ? 'display:none;' : '' ?>">

<?php if (!$rest_id): ?>
<div class="alert alert-danger">
    <i class="fas fa-exclamation-circle"></i>
    No restaurant linked to your account.
</div>
<?php else: ?>

<!-- ── Summary cards ────────────────────────────────────────────────────── -->
<div style="display:grid;grid-template-columns:repeat(auto-fit,minmax(200px,1fr));gap:16px;margin-bottom:24px;">

    <div style="background:linear-gradient(135deg,#4f46e5,#7c3aed);color:#fff;
                border-radius:14px;padding:20px;">
        <div style="font-size:.75rem;font-weight:700;text-transform:uppercase;
                    letter-spacing:.05em;opacity:.85;margin-bottom:6px;">
            <i class="fas fa-hand-holding-usd"></i>&nbsp; Total Approved Inflows
        </div>
        <div style="font-size:1.6rem;font-weight:800;">
            &#8369;<?= number_format($inflow_totals['approved'], 2) ?>
        </div>
        <div style="font-size:.72rem;opacity:.7;margin-top:4px;">Counts in owner revenue report</div>
    </div>

    <div style="background:#fef9c3;border:1px solid #fde047;border-radius:14px;padding:20px;">
        <div style="font-size:.75rem;font-weight:700;color:#854d0e;text-transform:uppercase;
                    letter-spacing:.05em;margin-bottom:6px;">
            <i class="fas fa-hourglass-half"></i>&nbsp; Pending Approval
        </div>
        <div style="font-size:1.6rem;font-weight:800;color:#78350f;">
            &#8369;<?= number_format($inflow_totals['pending'], 2) ?>
        </div>
    </div>

    <div style="background:#f0fdf4;border:1px solid #bbf7d0;border-radius:14px;padding:20px;">
        <div style="font-size:.75rem;font-weight:700;color:#15803d;text-transform:uppercase;
                    letter-spacing:.05em;margin-bottom:6px;">
            <i class="fas fa-layer-group"></i>&nbsp; All-Time Total
        </div>
        <div style="font-size:1.6rem;font-weight:800;color:#14532d;">
            &#8369;<?= number_format($inflow_totals['all'], 2) ?>
        </div>
    </div>
</div>

<!-- ── Add New Inflow form ────────────────────────────────────────────────── -->
<div class="card" style="margin-bottom:24px;">
    <div class="card-header" style="background:linear-gradient(135deg,#4f46e5,#7c3aed);
                                    color:#fff;padding:14px 18px;border-radius:12px 12px 0 0;
                                    display:flex;align-items:center;gap:10px;">
        <i class="fas fa-plus-circle"></i>
        <h3 style="margin:0;font-size:1rem;font-weight:700;">Record a New Inflow</h3>
    </div>
    <div class="card-body" style="padding:22px;">
        <form method="POST" enctype="multipart/form-data" id="inflow-form">
            <input type="hidden" name="save_inflow" value="1">

            <div style="display:grid;grid-template-columns:1fr 1fr;gap:16px;margin-bottom:16px;">

                <!-- Inflow type -->
                <div>
                    <label style="display:block;font-size:.82rem;font-weight:700;
                                  color:var(--neutral-600);margin-bottom:5px;">
                        Inflow Type <span style="color:#ef4444;">*</span>
                    </label>
                    <select name="inflow_type" id="inflow-type-sel" required
                            onchange="toggleCateringFields()"
                            style="width:100%;padding:9px 12px;border:1.5px solid var(--neutral-300);
                                   border-radius:8px;font-size:.875rem;">
                        <?php foreach (INFLOW_TYPES as $tkey => $tinfo): ?>
                        <option value="<?= $tkey ?>">
                            <?= htmlspecialchars($tinfo[3]) ?>
                            <?php if ($tkey === 'catering'): ?> ✓ auto-approved<?php endif; ?>
                        </option>
                        <?php endforeach; ?>
                    </select>
                    <div style="font-size:.72rem;color:var(--neutral-400);margin-top:4px;">
                        <i class="fas fa-info-circle"></i>
                        Catering orders are self-approved. All others require explicit approval.
                    </div>
                </div>

                <!-- Amount -->
                <div>
                    <label style="display:block;font-size:.82rem;font-weight:700;
                                  color:var(--neutral-600);margin-bottom:5px;">
                        Amount (₱) <span style="color:#ef4444;">*</span>
                    </label>
                    <input type="number" name="inflow_amount" min="0.01" step="0.01" required
                           placeholder="0.00"
                           style="width:100%;padding:9px 12px;border:1.5px solid var(--neutral-300);
                                  border-radius:8px;font-size:.875rem;">
                </div>

                <!-- Description -->
                <div style="grid-column:1/-1;">
                    <label style="display:block;font-size:.82rem;font-weight:700;
                                  color:var(--neutral-600);margin-bottom:5px;">
                        Description <span style="color:#ef4444;">*</span>
                    </label>
                    <input type="text" name="inflow_desc" required maxlength="500"
                           placeholder="e.g. Wedding catering on Dec 15 — 80 pax"
                           style="width:100%;padding:9px 12px;border:1.5px solid var(--neutral-300);
                                  border-radius:8px;font-size:.875rem;">
                </div>

                <!-- Catering-only fields (shown/hidden by JS) -->
                <div id="catering-fields" style="display:none;grid-column:1/-1;">
                    <div style="background:#ede9fe;border:1px solid #c4b5fd;border-radius:10px;
                                padding:16px;display:grid;grid-template-columns:1fr 1fr 1fr;gap:12px;">
                        <div style="grid-column:1/-1;font-size:.8rem;font-weight:700;color:#5b21b6;
                                    margin-bottom:4px;">
                            <i class="fas fa-concierge-bell"></i>&nbsp; Catering / Event Details
                        </div>
                        <div>
                            <label style="display:block;font-size:.78rem;font-weight:600;
                                          color:#5b21b6;margin-bottom:4px;">Client / Event Host</label>
                            <input type="text" name="inflow_client" maxlength="255"
                                   placeholder="Full name or organization"
                                   style="width:100%;padding:8px 10px;border:1.5px solid #c4b5fd;
                                          border-radius:7px;font-size:.85rem;">
                        </div>
                        <div>
                            <label style="display:block;font-size:.78rem;font-weight:600;
                                          color:#5b21b6;margin-bottom:4px;">Event Date</label>
                            <input type="date" name="inflow_event_date"
                                   style="width:100%;padding:8px 10px;border:1.5px solid #c4b5fd;
                                          border-radius:7px;font-size:.85rem;">
                        </div>
                        <div>
                            <label style="display:block;font-size:.78rem;font-weight:600;
                                          color:#5b21b6;margin-bottom:4px;">Reference / Order #</label>
                            <input type="text" name="inflow_reference" maxlength="100"
                                   placeholder="e.g. CAT-2026-001"
                                   style="width:100%;padding:8px 10px;border:1.5px solid #c4b5fd;
                                          border-radius:7px;font-size:.85rem;">
                        </div>
                    </div>
                </div>

                <!-- Inflow date -->
                <div>
                    <label style="display:block;font-size:.82rem;font-weight:700;
                                  color:var(--neutral-600);margin-bottom:5px;">Inflow Date</label>
                    <input type="date" name="inflow_date" value="<?= date('Y-m-d') ?>"
                           style="width:100%;padding:9px 12px;border:1.5px solid var(--neutral-300);
                                  border-radius:8px;font-size:.875rem;">
                </div>

                <!-- Receipt -->
                <div>
                    <label style="display:block;font-size:.82rem;font-weight:700;
                                  color:var(--neutral-600);margin-bottom:5px;">
                        Supporting Document (optional)
                    </label>
                    <input type="file" name="inflow_receipt"
                           accept=".jpg,.jpeg,.png,.webp,.pdf"
                           style="width:100%;padding:8px 12px;border:1.5px solid var(--neutral-300);
                                  border-radius:8px;font-size:.82rem;background:#f8fafc;">
                </div>

            </div>

            <button type="submit" class="btn btn-primary" style="gap:8px;">
                <i class="fas fa-save"></i> Save Inflow
            </button>
        </form>
    </div>
</div>

<!-- ── Status filter bar ─────────────────────────────────────────────────── -->
<div style="display:flex;gap:8px;flex-wrap:wrap;margin-bottom:16px;align-items:center;">
    <span style="font-size:.8rem;font-weight:700;color:var(--neutral-500);">Filter:</span>
    <?php
    $inf_statuses_filter = ['all' => 'All', 'pending' => 'Pending', 'approved' => 'Approved', 'rejected' => 'Rejected'];
    foreach ($inf_statuses_filter as $sfk => $sfl):
        $is_active = ($inflow_filter === $sfk);
    ?>
    <a href="?tab=other_inflows&inf_status=<?= $sfk ?>"
       style="padding:5px 14px;border-radius:999px;font-size:.78rem;font-weight:700;
              text-decoration:none;border:1.5px solid <?= $is_active ? '#4f46e5' : 'var(--neutral-300)' ?>;
              background:<?= $is_active ? '#4f46e5' : '#fff' ?>;
              color:<?= $is_active ? '#fff' : 'var(--neutral-600)' ?>;">
        <?= $sfl ?>
    </a>
    <?php endforeach; ?>
</div>

<!-- ── Inflows table ─────────────────────────────────────────────────────── -->
<?php if (empty($inflow_records)): ?>
<div style="text-align:center;padding:48px 24px;color:var(--neutral-400);">
    <i class="fas fa-hand-holding-usd" style="font-size:2.5rem;margin-bottom:12px;display:block;"></i>
    No inflow records found.
    <?= $inflow_filter !== 'all' ? '<a href="?tab=other_inflows">View all</a>' : '' ?>
</div>
<?php else: ?>
<div style="overflow-x:auto;">
<table class="table" style="font-size:.86rem;width:100%;border-collapse:collapse;">
    <thead>
        <tr style="background:#f8fafc;border-bottom:2px solid var(--neutral-200);">
            <th style="padding:11px 14px;">Date</th>
            <th style="padding:11px 14px;">Type</th>
            <th style="padding:11px 14px;">Description</th>
            <th style="padding:11px 14px;">Client / Ref</th>
            <th style="padding:11px 14px;text-align:right;">Amount</th>
            <th style="padding:11px 14px;text-align:center;">Status</th>
            <th style="padding:11px 14px;text-align:center;">Receipt</th>
            <th style="padding:11px 14px;text-align:center;">Actions</th>
        </tr>
    </thead>
    <tbody>
    <?php foreach ($inflow_records as $inf):
        $itype  = $inf['inflow_type'] ?? 'other';
        $iinfo  = INFLOW_TYPES[$itype] ?? INFLOW_TYPES['other'];
        $istatus = $inf['status'];
        [$sbg, $stc] = match($istatus) {
            'approved' => ['#dcfce7', '#15803d'],
            'rejected' => ['#fee2e2', '#b91c1c'],
            default    => ['#fef9c3', '#854d0e'],
        };
        $has_receipt = !empty($inf['receipt_path']);
    ?>
    <tr style="border-bottom:1px solid var(--neutral-100);">
        <!-- Date -->
        <td style="padding:10px 14px;white-space:nowrap;vertical-align:top;">
            <div style="font-weight:700;font-size:.82rem;">
                <?= date('M j, Y', strtotime($inf['inflow_date'])) ?>
            </div>
            <div style="font-size:.7rem;color:var(--neutral-400);">
                <?= date('g:i A', strtotime($inf['created_at'])) ?>
            </div>
        </td>
        <!-- Type badge -->
        <td style="padding:10px 14px;vertical-align:top;white-space:nowrap;">
            <span style="display:inline-flex;align-items:center;gap:5px;
                         background:<?= $iinfo[2] ?>;color:<?= $iinfo[1] ?>;
                         border-radius:8px;padding:4px 10px;font-size:.78rem;font-weight:700;">
                <i class="<?= $iinfo[0] ?>"></i>
                <?= htmlspecialchars($iinfo[3]) ?>
            </span>
        </td>
        <!-- Description -->
        <td style="padding:10px 14px;vertical-align:top;max-width:280px;">
            <div style="font-weight:600;color:var(--neutral-800);line-height:1.4;">
                <?= htmlspecialchars($inf['description']) ?>
            </div>
            <?php if ($inf['event_date']): ?>
            <div style="font-size:.72rem;color:#7c3aed;margin-top:3px;">
                <i class="fas fa-calendar"></i>
                Event: <?= date('M j, Y', strtotime($inf['event_date'])) ?>
            </div>
            <?php endif; ?>
        </td>
        <!-- Client / Ref -->
        <td style="padding:10px 14px;vertical-align:top;">
            <?php if ($inf['client_name']): ?>
            <div style="font-size:.82rem;font-weight:600;color:var(--neutral-700);">
                <i class="fas fa-user" style="color:#7c3aed;font-size:.7rem;"></i>
                <?= htmlspecialchars($inf['client_name']) ?>
            </div>
            <?php endif; ?>
            <?php if ($inf['reference_number']): ?>
            <div style="font-size:.75rem;color:var(--neutral-400);margin-top:2px;">
                Ref: <?= htmlspecialchars($inf['reference_number']) ?>
            </div>
            <?php endif; ?>
        </td>
        <!-- Amount -->
        <td style="padding:10px 14px;text-align:right;vertical-align:top;white-space:nowrap;">
            <span style="font-size:1rem;font-weight:800;color:var(--primary-700);">
                &#8369;<?= number_format((float)$inf['amount'], 2) ?>
            </span>
        </td>
        <!-- Status + approve/reject buttons -->
        <td style="padding:10px 14px;text-align:center;vertical-align:top;">
            <span style="display:inline-block;background:<?= $sbg ?>;color:<?= $stc ?>;
                         border-radius:999px;padding:3px 11px;font-size:.75rem;font-weight:700;">
                <?= ucfirst($istatus) ?>
            </span>
            <?php if ($istatus === 'pending'): ?>
            <div style="margin-top:6px;display:flex;gap:4px;justify-content:center;">
                <form method="POST" style="margin:0;">
                    <input type="hidden" name="inflow_id" value="<?= $inf['id'] ?>">
                    <input type="hidden" name="inflow_action" value="approve">
                    <button type="submit" class="btn btn-sm"
                            style="background:#16a34a;color:#fff;font-size:.72rem;padding:3px 9px;border:none;border-radius:6px;cursor:pointer;"
                            title="Approve — will count toward revenue">
                        <i class="fas fa-check"></i> Approve
                    </button>
                </form>
                <button onclick="rejectInflow(<?= $inf['id'] ?>)"
                        class="btn btn-sm"
                        style="background:#ef4444;color:#fff;font-size:.72rem;padding:3px 9px;border:none;border-radius:6px;cursor:pointer;"
                        title="Reject">
                    <i class="fas fa-times"></i> Reject
                </button>
            </div>
            <?php endif; ?>
            <?php if ($istatus === 'rejected' && $inf['rejection_reason']): ?>
            <div style="font-size:.7rem;color:#b91c1c;margin-top:3px;max-width:140px;">
                <?= htmlspecialchars($inf['rejection_reason']) ?>
            </div>
            <?php endif; ?>
        </td>
        <!-- Receipt -->
        <td style="padding:10px 14px;text-align:center;vertical-align:top;">
            <?php if ($has_receipt): ?>
            <?php $rurl = BASE_URL . 'uploads/' . htmlspecialchars($inf['receipt_path']); ?>
            <a href="<?= $rurl ?>" target="_blank"
               style="display:inline-flex;align-items:center;gap:4px;color:var(--primary-600);
                      font-size:.78rem;font-weight:600;text-decoration:none;
                      padding:3px 9px;border:1px solid var(--primary-200);
                      border-radius:7px;background:var(--primary-50);">
                <i class="fas fa-file"></i> View
            </a>
            <?php else: ?>
            <span style="font-size:.72rem;color:var(--neutral-300);font-style:italic;">—</span>
            <?php endif; ?>
        </td>
        <!-- Delete -->
        <td style="padding:10px 14px;text-align:center;vertical-align:top;">
            <form method="POST" style="margin:0;"
                  onsubmit="return confirm('Delete this inflow record permanently?')">
                <input type="hidden" name="delete_inflow" value="<?= $inf['id'] ?>">
                <button type="submit" class="btn btn-sm btn-danger"
                        style="font-size:.75rem;" title="Delete">
                    <i class="fas fa-trash"></i>
                </button>
            </form>
        </td>
    </tr>
    <?php endforeach; ?>
    </tbody>
    <tfoot>
        <tr style="border-top:2px solid var(--neutral-200);background:#f8fafc;">
            <td colspan="4" style="padding:11px 14px;font-weight:700;color:var(--neutral-600);
                                    font-size:.82rem;text-transform:uppercase;">
                <?= $inflow_filter === 'all' ? 'All' : ucfirst($inflow_filter) ?> Total
            </td>
            <td style="padding:11px 14px;text-align:right;font-size:1.05rem;
                       font-weight:800;color:var(--primary-700);white-space:nowrap;">
                &#8369;<?= number_format(array_sum(array_column($inflow_records,'amount')), 2) ?>
            </td>
            <td colspan="3"></td>
        </tr>
    </tfoot>
</table>
</div>
<?php endif; ?>

<!-- Hidden reject form (submitted by JS) -->
<form method="POST" id="reject-inflow-form" style="display:none;">
    <input type="hidden" name="inflow_id"     id="reject-inflow-id">
    <input type="hidden" name="inflow_action" value="reject">
    <input type="hidden" name="reject_reason" id="reject-inflow-reason">
</form>

<?php endif; ?>
</div><!-- /tab-other_inflows -->


<!-- ══ TAB: Log Order ═════════════════════════════════════════════════════ -->
<div id="tab-log_order" class="omgr-tab-panel"
     style="<?= $active_tab !== 'log_order' ? 'display:none;' : '' ?>">

<?php if (!$rest_id): ?>
<div class="alert alert-danger">
    <i class="fas fa-exclamation-circle"></i>
    No restaurant linked to your account.
</div>
<?php elseif (empty($log_order_menu)): ?>
<div style="text-align:center;padding:56px 24px;color:var(--neutral-400);">
    <i class="fas fa-utensils" style="font-size:2.5rem;display:block;margin-bottom:14px;opacity:.4;"></i>
    <strong style="font-size:1rem;color:var(--neutral-600);">No approved menu items yet.</strong>
    <p style="margin-top:8px;font-size:.88rem;">
        The Chef must add items and you must approve them from the
        <a href="?tab=menu" onclick="switchOmgrTab('menu');return false;">Menu Management</a> tab first.
    </p>
</div>
<?php else: ?>

<!-- Header -->
<div style="background:linear-gradient(135deg,#0f172a,#1e40af);border-radius:14px;
            padding:22px 26px;margin-bottom:24px;display:flex;align-items:center;
            justify-content:space-between;flex-wrap:wrap;gap:14px;">
    <div style="display:flex;align-items:center;gap:14px;">
        <div style="width:46px;height:46px;border-radius:12px;background:rgba(255,255,255,.12);
                    display:flex;align-items:center;justify-content:center;font-size:1.4rem;color:#93c5fd;">
            <i class="fas fa-calendar-plus"></i>
        </div>
        <div>
            <h2 style="margin:0;font-size:1.15rem;font-weight:800;color:#fff;">Log a Past Order</h2>
            <p style="margin:4px 0 0;font-size:.8rem;color:#93c5fd;">
                Record orders that were made on a specific date — they will appear in the correct financial report period.
            </p>
        </div>
    </div>
    <span style="background:rgba(255,255,255,.1);color:#bfdbfe;border:1px solid rgba(255,255,255,.2);
                 border-radius:999px;padding:4px 14px;font-size:.72rem;font-weight:700;
                 text-transform:uppercase;letter-spacing:.06em;">
        <i class="fas fa-lock" style="font-size:.6rem;margin-right:4px;"></i> Approved Menu Only
    </span>
</div>

<!-- Success / Error toast (JS-driven) -->
<div id="log-order-toast" style="display:none;padding:12px 18px;border-radius:10px;
     margin-bottom:18px;font-size:.88rem;font-weight:600;"></div>

<div style="display:grid;grid-template-columns:1fr 1.6fr;gap:22px;align-items:start;">

<!-- ── LEFT: Order details form ───────────────────────────────────────────── -->
<div class="card" style="border:2px solid #bfdbfe;border-radius:14px;">
    <div class="card-header" style="background:#eff6ff;border-radius:12px 12px 0 0;padding:14px 18px;">
        <h3 style="margin:0;font-size:.95rem;color:#1d4ed8;font-weight:700;">
            <i class="fas fa-pen-to-square" style="margin-right:7px;"></i>Order Details
        </h3>
    </div>
    <div class="card-body" style="padding:18px;">

        <div class="form-group">
            <label class="form-label">
                <i class="fas fa-calendar-day" style="color:var(--primary-500);margin-right:4px;"></i>
                Order Date <span style="color:#ef4444;">*</span>
            </label>
            <input type="date" id="lo-date" class="form-control"
                   value="<?= date('Y-m-d') ?>"
                   max="<?= date('Y-m-d') ?>" required>
        </div>

        <div class="form-group">
            <label class="form-label">
                <i class="fas fa-clock" style="color:var(--primary-500);margin-right:4px;"></i>
                Order Time
            </label>
            <input type="time" id="lo-time" class="form-control" value="12:00">
            <div style="font-size:.72rem;color:var(--neutral-400);margin-top:3px;">
                Used for timestamp accuracy in reports. Defaults to noon.
            </div>
        </div>

        <div class="form-group">
            <label class="form-label">
                <i class="fas fa-concierge-bell" style="color:var(--primary-500);margin-right:4px;"></i>
                Service Type <span style="color:#ef4444;">*</span>
            </label>
            <select id="lo-service" class="form-control">
                <option value="dine_in">Dine-in</option>
                <option value="takeout">Takeout</option>
                <option value="delivery">Delivery</option>
            </select>
        </div>

        <div class="form-group">
            <label class="form-label">
                <i class="fas fa-wallet" style="color:var(--primary-500);margin-right:4px;"></i>
                Payment Method <span style="color:#ef4444;">*</span>
            </label>
            <select id="lo-payment" class="form-control">
                <option value="cash">Cash</option>
                <option value="gcash">GCash</option>
                <option value="bank_transfer">Bank Transfer</option>
            </select>
        </div>

        <div class="form-group">
            <label class="form-label">
                <i class="fas fa-user" style="color:var(--primary-500);margin-right:4px;"></i>
                Customer Name <span style="color:var(--neutral-400);font-weight:400;">(optional)</span>
            </label>
            <input type="text" id="lo-customer" class="form-control"
                   placeholder="Walk-in / reference name" maxlength="255">
        </div>

        <div class="form-group" style="margin-bottom:0;">
            <label class="form-label">
                <i class="fas fa-sticky-note" style="color:var(--primary-500);margin-right:4px;"></i>
                Notes <span style="color:var(--neutral-400);font-weight:400;">(optional)</span>
            </label>
            <textarea id="lo-notes" class="form-control" rows="2"
                      placeholder="Any additional details…" maxlength="500"
                      style="resize:vertical;"></textarea>
        </div>
    </div>
</div>

<!-- ── RIGHT: Menu item picker + order summary ────────────────────────────── -->
<div style="display:flex;flex-direction:column;gap:16px;">

    <!-- Item picker -->
    <div class="card" style="border:2px solid #d1fae5;border-radius:14px;">
        <div class="card-header" style="background:#f0fdf4;border-radius:12px 12px 0 0;padding:14px 18px;
                                        display:flex;align-items:center;justify-content:space-between;">
            <h3 style="margin:0;font-size:.95rem;color:#15803d;font-weight:700;">
                <i class="fas fa-utensils" style="margin-right:7px;"></i>Menu Items
                <span style="font-size:.72rem;font-weight:500;color:#16a34a;margin-left:6px;">
                    (approved &amp; available only)
                </span>
            </h3>
            <input type="text" id="lo-menu-search" placeholder="Search items…"
                   style="padding:5px 10px;border:1px solid #d1fae5;border-radius:7px;font-size:.8rem;
                          width:160px;outline:none;">
        </div>
        <div style="max-height:340px;overflow-y:auto;padding:10px 14px;" id="lo-menu-list">
            <?php
            $last_cat = '';
            foreach ($log_order_menu as $mi):
                if ($mi['category'] !== $last_cat):
                    $last_cat = $mi['category'];
            ?>
            <div style="font-size:.7rem;font-weight:800;text-transform:uppercase;letter-spacing:.06em;
                        color:var(--neutral-400);margin:10px 0 5px;padding-top:4px;
                        border-top:1px solid var(--neutral-100);">
                <?= htmlspecialchars($mi['category']) ?>
            </div>
            <?php endif; ?>
            <div class="lo-menu-row"
                 data-id="<?= $mi['id'] ?>"
                 data-name="<?= htmlspecialchars($mi['name'], ENT_QUOTES) ?>"
                 data-price="<?= (float)$mi['price'] ?>"
                 data-cat="<?= htmlspecialchars(strtolower($mi['category']), ENT_QUOTES) ?>"
                 data-search="<?= htmlspecialchars(strtolower($mi['name'] . ' ' . $mi['category']), ENT_QUOTES) ?>"
                 style="display:flex;align-items:center;justify-content:space-between;
                        padding:8px 10px;border-radius:8px;cursor:pointer;margin-bottom:4px;
                        border:1.5px solid transparent;transition:all .12s;"
                 onmouseenter="this.style.background='#f0fdf4';this.style.borderColor='#86efac';"
                 onmouseleave="this.style.background='';this.style.borderColor='transparent';"
                 onclick="loAddItem(this)">
                <div>
                    <div style="font-size:.88rem;font-weight:700;color:var(--neutral-800);">
                        <?= htmlspecialchars($mi['name']) ?>
                    </div>
                    <?php if (!empty($mi['serving_type'])): ?>
                    <div style="font-size:.7rem;color:var(--neutral-400);">
                        <?= htmlspecialchars($mi['serving_type']) ?>
                    </div>
                    <?php endif; ?>
                </div>
                <div style="font-size:.92rem;font-weight:800;color:#16a34a;white-space:nowrap;margin-left:10px;">
                    &#8369;<?= number_format((float)$mi['price'], 2) ?>
                    <i class="fas fa-plus-circle" style="color:#22c55e;font-size:.8rem;margin-left:5px;"></i>
                </div>
            </div>
            <?php endforeach; ?>
        </div>
    </div>

    <!-- Order basket -->
    <div class="card" style="border:2px solid #e0e7ff;border-radius:14px;">
        <div class="card-header" style="background:#eef2ff;border-radius:12px 12px 0 0;padding:14px 18px;
                                        display:flex;align-items:center;justify-content:space-between;">
            <h3 style="margin:0;font-size:.95rem;color:#4338ca;font-weight:700;">
                <i class="fas fa-shopping-basket" style="margin-right:7px;"></i>Order Basket
            </h3>
            <span id="lo-item-count" style="background:#c7d2fe;color:#3730a3;border-radius:999px;
                                            padding:2px 10px;font-size:.75rem;font-weight:700;">0 items</span>
        </div>
        <div id="lo-basket" style="padding:12px 14px;min-height:60px;">
            <div id="lo-basket-empty" style="text-align:center;padding:18px;color:var(--neutral-300);font-size:.85rem;">
                <i class="fas fa-shopping-basket" style="font-size:1.5rem;display:block;margin-bottom:6px;opacity:.4;"></i>
                Click items from the menu to add them
            </div>
        </div>
        <div style="border-top:2px solid #e0e7ff;padding:12px 18px;
                    display:flex;align-items:center;justify-content:space-between;flex-wrap:wrap;gap:10px;">
            <div>
                <span style="font-size:.78rem;color:var(--neutral-500);">Order Total</span>
                <div id="lo-total" style="font-size:1.4rem;font-weight:900;color:#4338ca;">&#8369;0.00</div>
            </div>
            <button id="lo-submit-btn"
                    onclick="loSubmit()"
                    style="display:inline-flex;align-items:center;gap:8px;padding:10px 24px;
                           background:linear-gradient(135deg,#2563eb,#4f46e5);color:#fff;border:none;
                           border-radius:10px;font-size:.9rem;font-weight:700;cursor:pointer;
                           box-shadow:0 2px 10px rgba(79,70,229,.3);transition:opacity .15s;"
                    onmouseover="this.style.opacity='.88'" onmouseout="this.style.opacity='1'">
                <i class="fas fa-save"></i> Save Order
            </button>
        </div>
    </div>

</div><!-- /right col -->
</div><!-- /grid -->

<?php endif; ?>
</div><!-- /tab-log_order -->


<?php
$extra_js  = '<script>' . "\n";
$extra_js .= '/* â”€â”€ Tab switcher â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€ */' . "\n";
$extra_js .= 'function switchOmgrTab(tab) {' . "\n";
$extra_js .= '    document.querySelectorAll(\'.omgr-tab-panel\').forEach(p => p.style.display = \'none\');' . "\n";
$extra_js .= '    document.querySelectorAll(\'.omgr-tab-btn\').forEach(b => {' . "\n";
$extra_js .= '        b.classList.toggle(\'active\', b.dataset.tab === tab);' . "\n";
$extra_js .= '    });' . "\n";
$extra_js .= '    const panel = document.getElementById(\'tab-\' + tab);' . "\n";
$extra_js .= '    if (panel) panel.style.display = \'\';' . "\n";
$extra_js .= '    const url = new URL(window.location.href);' . "\n";
$extra_js .= '    url.searchParams.set(\'tab\', tab);' . "\n";
$extra_js .= '    window.history.replaceState(null, \'\', url.toString());' . "\n";
$extra_js .= '}' . "\n";
$extra_js .= 'document.addEventListener(\'DOMContentLoaded\', () => switchOmgrTab(' . json_encode($active_tab) . '));' . "\n";
$extra_js .= "\n";

$extra_js .= <<<'EOJS'
/* â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•
   MENU MANAGEMENT &mdash; AJAX add + edit system
 â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â• */
(function () {
    'use strict';

EOJS;

// URLs that require PHP interpolation
$extra_js .= '    const ADD_URL    = ' . json_encode(BASE_URL . 'dashboard/business_owner/api/add_menu_item.php')  . ';' . "\n";
$extra_js .= '    const UPDATE_URL = ' . json_encode(BASE_URL . 'dashboard/business_owner/api/update_menu_item.php') . ';' . "\n";
$extra_js .= '    const MENU_ITEM_CATEGORIES_JS  = ' . json_encode(MENU_ITEM_CATEGORIES)  . ';' . "\n";
$extra_js .= '    const ALLOWED_SERVING_TYPES_JS = ' . json_encode(ALLOWED_SERVING_TYPES) . ';' . "\n";

$extra_js .= <<<'EOJS'
    const CURRENCY      = '&#8369;';
    let   dishCount     = 0;

    const dishRowsEl    = document.getElementById('dishRows');
    const menuSubmitRow = document.getElementById('menuSubmitRow');
    const saveMenuBtn   = document.getElementById('saveMenuBtn');
    const addDishBtn    = document.getElementById('addDishBtn');
    const menuToast     = document.getElementById('menuToast');
    const menuItemsList = document.getElementById('menuItemsList');
    const emptyState    = document.getElementById('menuEmptyState');

    if (!addDishBtn) return;

    /* â”€â”€ Toast â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€ */
    function showToast(msg, isErr) {
        menuToast.textContent       = msg;
        menuToast.style.display     = 'block';
        menuToast.style.background  = isErr ? '#fef2f2' : '#f0fdf4';
        menuToast.style.border      = isErr ? '1px solid #fecaca' : '1px solid #bbf7d0';
        menuToast.style.color       = isErr ? '#991b1b'  : '#166534';
        clearTimeout(menuToast._t);
        menuToast._t = setTimeout(() => { menuToast.style.display = 'none'; }, 5000);
    }

    /* â”€â”€ Build a dish input row (shared by add + edit) â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€ */
    function buildRow(item = null) {
        const idx      = dishCount++;
        const isEdit   = item !== null;
        const itemId   = isEdit ? item.id : null;

        const wrap = document.createElement('div');
        wrap.className = 'dish-row';
        if (isEdit) wrap.dataset.editId = itemId;
        wrap.style.cssText = 'background:#f8fafc;border:1px solid ' + (isEdit ? '#bfdbfe' : '#e2e8f0') + ';border-radius:12px;padding:14px 16px;display:flex;flex-direction:column;gap:10px;';

        const cats    = MENU_ITEM_CATEGORIES_JS;
        const serves  = ALLOWED_SERVING_TYPES_JS;
        const catOpts  = cats.map(c   => `<option${isEdit && item.category     === c   ? ' selected' : ''}>${c}</option>`).join('');
        const servOpts = serves.map(s => `<option${isEdit && item.serving_type === s ? ' selected' : ''}>${s}</option>`).join('');

        // Pre-fill existing image if editing
        const imgStyle = isEdit && item.image_url
            ? `background-image:url('${esc(item.image_url)}');background-size:cover;background-position:center;`
            : '';
        const iconDisplay = isEdit && item.image_url ? 'display:none;' : '';

        wrap.innerHTML = `
            ${isEdit ? `<div style="font-size:.75rem;font-weight:700;color:#1d4ed8;text-transform:uppercase;letter-spacing:.05em;"><i class="fas fa-pencil-alt" style="margin-right:4px;"></i>Editing: ${esc(item.name)}</div>` : ''}
            <div style="display:flex;align-items:flex-end;gap:10px;flex-wrap:nowrap;">
                <div style="flex-shrink:0;">
                    <div class="dish-field-label">Photo</div>
                    <label id="imgLabel${idx}"
                        style="display:flex;flex-direction:column;align-items:center;justify-content:center;
                               border:2px dashed #cbd5e1;border-radius:8px;width:72px;height:72px;
                               cursor:pointer;background:#fff;overflow:hidden;position:relative;${imgStyle}">
                        <i class="fas fa-camera" style="color:#94a3b8;font-size:1.2rem;${iconDisplay}" id="imgIcon${idx}"></i>
                        <input type="file" accept="image/*" style="display:none;" data-idx="${idx}" class="dish-img-input">
                    </label>
                </div>
                <div style="flex:1 1 140px;min-width:0;">
                    <div class="dish-field-label">Dish Name <span style="color:var(--danger);">*</span></div>
                    <input type="text" class="form-control dish-name-input"
                           value="${isEdit ? esc(item.name) : ''}"
                           placeholder="e.g. Grilled Chicken" style="font-size:.88rem;">
                </div>
                <div style="flex:0 0 100px;">
                    <div class="dish-field-label">Price (&#8369;) <span style="color:var(--danger);">*</span></div>
                    <input type="number" class="form-control dish-price-input"
                           value="${isEdit ? parseFloat(item.price).toFixed(2) : ''}"
                           min="0" step="0.01" placeholder="0.00" style="font-size:.88rem;">
                </div>
                <div style="flex:0 0 140px;">
                    <div class="dish-field-label">Category</div>
                    <select class="form-control dish-cat-input" style="font-size:.85rem;">${catOpts}</select>
                </div>
                <div style="flex:0 0 130px;">
                    <div class="dish-field-label">Serving Type</div>
                    <select class="form-control dish-serve-input" style="font-size:.85rem;">${servOpts}</select>
                </div>
                <div style="flex-shrink:0;padding-bottom:2px;">
                    <button type="button" class="dish-remove-btn" title="${isEdit ? 'Cancel edit' : 'Remove'}"
                        style="width:36px;height:38px;border:1px solid #fecaca;background:#fef2f2;
                               border-radius:8px;cursor:pointer;color:#dc2626;font-size:.9rem;
                               display:flex;align-items:center;justify-content:center;">
                        <i class="fas fa-times"></i>
                    </button>
                </div>
            </div>
            <div>
                <div class="dish-field-label">Brief Description <span style="color:var(--neutral-400);font-weight:400;">(optional)</span></div>
                <textarea class="form-control dish-desc-input" rows="2"
                          placeholder="Ingredients, flavour profile, allergensâ€¦"
                          style="font-size:.83rem;resize:vertical;">${isEdit ? esc(item.description || '') : ''}</textarea>
            </div>`;

        /* image preview */
        wrap.querySelector('.dish-img-input').addEventListener('change', function () {
            const label = document.getElementById('imgLabel' + this.dataset.idx);
            const icon  = document.getElementById('imgIcon'  + this.dataset.idx);
            if (this.files && this.files[0]) {
                const r = new FileReader();
                r.onload = e => {
                    if (icon) icon.style.display = 'none';
                    label.style.backgroundImage    = `url('${e.target.result}')`;
                    label.style.backgroundSize     = 'cover';
                    label.style.backgroundPosition = 'center';
                };
                r.readAsDataURL(this.files[0]);
            }
        });

        /* cancel / remove */
        wrap.querySelector('.dish-remove-btn').addEventListener('click', () => {
            // If editing, restore the original card visibility
            if (isEdit && itemId) {
                const origCard = menuItemsList.querySelector(`[data-item-id="${itemId}"]`);
                if (origCard) origCard.style.display = '';
            }
            wrap.remove();
            if (!dishRowsEl.querySelectorAll('.dish-row').length) {
                if (emptyState && !menuItemsList?.children.length) emptyState.style.display = '';
                menuSubmitRow.style.display = 'none';
            }
        });

        return wrap;
    }

    /* â”€â”€ Add new dish row â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€ */
    function addDishRow() {
        if (emptyState) emptyState.style.display = 'none';
        menuSubmitRow.style.display = 'flex';
        dishRowsEl.appendChild(buildRow(null));
    }

    /* â”€â”€ Open edit row for existing item â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€ */
    window.openEditRow = function (btn, item) {
        // Don't allow two edit rows for the same item
        if (dishRowsEl.querySelector(`[data-edit-id="${item.id}"]`)) return;

        // Hide the existing card while editing
        const card = btn.closest('[data-item-id]');
        if (card) card.style.display = 'none';

        if (emptyState) emptyState.style.display = 'none';
        menuSubmitRow.style.display = 'flex';
        dishRowsEl.appendChild(buildRow(item));
        dishRowsEl.lastElementChild.scrollIntoView({ behavior: 'smooth', block: 'nearest' });
    };

    /* â”€â”€ Save all rows (add new + save edits) â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€ */
    async function saveAllDishes() {
        const rows = dishRowsEl.querySelectorAll('.dish-row');
        if (!rows.length) return;

        saveMenuBtn.disabled  = true;
        saveMenuBtn.innerHTML = '<i class="fas fa-spinner fa-spin"></i> Savingâ€¦';

        let saved = 0, failed = 0;

        for (const row of rows) {
            const name = row.querySelector('.dish-name-input')?.value.trim() ?? '';
            if (!name) { failed++; continue; }

            const isEdit = !!row.dataset.editId;
            const url    = isEdit ? UPDATE_URL : ADD_URL;

            const fd = new FormData();
            if (isEdit) fd.append('item_id', row.dataset.editId);
            fd.append('dish_name',         name);
            fd.append('dish_price',        row.querySelector('.dish-price-input')?.value  ?? '0');
            fd.append('dish_cat',          row.querySelector('.dish-cat-input')?.value    ?? 'General');
            fd.append('dish_serving_type', row.querySelector('.dish-serve-input')?.value  ?? 'Per serving');
            fd.append('dish_desc',         row.querySelector('.dish-desc-input')?.value   ?? '');

            const imgInput = row.querySelector('.dish-img-input');
            if (imgInput?.files?.[0]) fd.append('dish_image', imgInput.files[0]);

            try {
                const res  = await fetch(url, { method: 'POST', body: fd });
                const data = await res.json();
                if (data.success) {
                    saved++;
                    // Remove the old card if editing
                    if (isEdit) {
                        const old = menuItemsList.querySelector(`[data-item-id="${row.dataset.editId}"]`);
                        if (old) old.remove();
                    }
                    row.remove();
                    appendCard(data.item);
                } else {
                    failed++;
                }
            } catch { failed++; }
        }

        if (!dishRowsEl.querySelectorAll('.dish-row').length) {
            if (emptyState && !menuItemsList?.children.length) emptyState.style.display = '';
            menuSubmitRow.style.display = 'none';
        }

        saveMenuBtn.disabled  = false;
        saveMenuBtn.innerHTML = '<i class="fas fa-check"></i> Save All Dishes';

        if (saved && !failed)     showToast(`${saved} item${saved!==1?'s':''} saved!`, false);
        else if (saved && failed) showToast(`${saved} saved, ${failed} failed. Check required fields.`, true);
        else                      showToast('Could not save &mdash; make sure dish names are filled in.', true);
    }

    /* â”€â”€ Append / refresh a card in the live list â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€ */
    function appendCard(item) {
        if (!menuItemsList) return;
        if (emptyState) emptyState.style.display = 'none';

        const price   = CURRENCY + ' ' + parseFloat(item.price).toFixed(2);
        const imgHtml = item.image_url
            ? `<img src="${esc(item.image_url)}" style="width:52px;height:52px;object-fit:cover;border-radius:8px;flex-shrink:0;" alt="${esc(item.name)}">`
            : `<div style="width:52px;height:52px;background:var(--neutral-100);border-radius:8px;display:flex;align-items:center;justify-content:center;flex-shrink:0;color:var(--neutral-300);font-size:1.2rem;"><i class="fas fa-utensils"></i></div>`;

        const row = document.createElement('div');
        row.dataset.itemId = item.id;
        row.style.cssText  = 'display:flex;align-items:center;gap:14px;padding:12px 4px;border-bottom:1px solid var(--neutral-100);';
        row.innerHTML = `
            ${imgHtml}
            <div style="flex:1;min-width:0;">
                <div style="font-weight:600;color:var(--neutral-800);">${esc(item.name)}</div>
                ${item.description ? `<div style="font-size:.82rem;color:var(--neutral-500);">${esc(item.description.substring(0,80))}${item.description.length>80?'â€¦':''}</div>` : ''}
                <div style="font-size:.78rem;color:var(--neutral-400);">${esc(item.category)}${item.serving_type?' &middot; '+esc(item.serving_type):''}</div>
            </div>
            <div style="font-weight:700;color:var(--primary-700);white-space:nowrap;font-size:1rem;">${price}</div>
            <button type="button" class="btn btn-sm btn-outline" title="Edit"
                    onclick="openEditRow(this, ${JSON.stringify(item).replace(/</g,'\\u003c')})">
                <i class="fas fa-pencil-alt"></i>
            </button>
            <form method="POST" style="display:inline;margin:0;" onsubmit="return confirm('Remove this item permanently?')">
                <input type="hidden" name="delete_menu_item" value="${item.id}">
                <button type="submit" class="btn btn-sm btn-danger" title="Delete"><i class="fas fa-trash"></i></button>
            </form>`;

        menuItemsList.appendChild(row);
    }

    function esc(str) {
        const d = document.createElement('div');
        d.textContent = String(str ?? '');
        return d.innerHTML;
    }

    addDishBtn.addEventListener('click', addDishRow);
    saveMenuBtn.addEventListener('click', saveAllDishes);
})();


EOJS;

// -- Expense tab: styles appended to $extra_js (before closing </script>) --
$extra_js .= '</script>' . "\n";
$extra_js .= '<style>' . "\n";
$extra_js .= '.dish-field-label{font-size:.75rem;font-weight:600;color:var(--neutral-500);margin-bottom:4px;white-space:nowrap;}' . "\n";
$extra_js .= '.dish-row .form-control{height:38px;padding-top:6px;padding-bottom:6px;}' . "\n";
$extra_js .= '.dish-row textarea.form-control{height:auto;}' . "\n";
$extra_js .= '.exp-form-hidden{display:none !important;}' . "\n";
$extra_js .= '#receiptDropZone:hover{border-color:var(--primary-400);background:#eff6ff;}' . "\n";
$extra_js .= '#receiptDropZone.drag-over{border-color:var(--primary-600);background:#dbeafe;}' . "\n";
$extra_js .= '@media(max-width:720px){' . "\n";
$extra_js .= '    #expFormWrap .card-body form [style*="grid-template-columns:1fr 1fr 1fr"]{grid-template-columns:1fr !important;}' . "\n";
$extra_js .= '    #expFormWrap .card-body form [style*="grid-template-columns:1fr 2fr"]{grid-template-columns:1fr !important;}' . "\n";
$extra_js .= '}' . "\n";
$extra_js .= '</style>' . "\n";
$extra_js .= '<script>' . "\n";
// -- Expense tab JS + Requisitions JS appended to $extra_js ----------------
$extra_js .= <<<'EXPJS'
function previewExpReceipt(input) {
    const label=document.getElementById('receiptLabel'),icon=document.getElementById('receiptIcon'),preview=document.getElementById('receiptPreviewWrap');
    if(!input.files||!input.files[0])return;
    const file=input.files[0];label.textContent=file.name;
    icon.className='fas '+(file.type==='application/pdf'?'fa-file-pdf':'fa-check-circle');
    icon.style.color='var(--primary-500)';
    if(file.type.startsWith('image/')){const r=new FileReader();r.onload=e=>{preview.style.display='';preview.innerHTML=`<img src="${e.target.result}" style="max-width:100%;border-radius:8px;">`};r.readAsDataURL(file);}
    else{preview.style.display='';preview.innerHTML=`<span style="font-size:.82rem;color:var(--primary-700);">PDF: ${file.name}</span>`;}
}
(function(){
    const zone=document.getElementById('receiptDropZone'),inp=document.getElementById('expReceiptInput');
    if(!zone||!inp)return;
    ['dragenter','dragover'].forEach(ev=>zone.addEventListener(ev,e=>{e.preventDefault();zone.classList.add('drag-over');}));
    ['dragleave','drop'].forEach(ev=>zone.addEventListener(ev,e=>{e.preventDefault();zone.classList.remove('drag-over');}));
    zone.addEventListener('drop',e=>{const f=e.dataTransfer?.files;if(f&&f[0]){const dt=new DataTransfer();dt.items.add(f[0]);inp.files=dt.files;previewExpReceipt(inp);}});
})();
(function(){
    const url=new URL(window.location.href),period=url.searchParams.get('exp_period')||'all',sel=document.querySelector('[name="exp_period"]');
    if(!sel)return;
    if(period==='daily')sel.value='daily';else if(period==='weekly')sel.value='weekly';else if(period==='monthly')sel.value='monthly';
})();
(function(){
    const tb=document.getElementById('itemizedBody'),ab=document.getElementById('addItemRowBtn'),ai=document.getElementById('expAmountInput');
    if(!tb||!ab||!ai)return;
    function recalc(){let g=0;tb.querySelectorAll('tr').forEach(row=>{const p=parseFloat(row.querySelector('.item-price')?.value||0),q=parseInt(row.querySelector('.item-qty')?.value||1),t=p*q;g+=t;const lbl=row.querySelector('.item-line-total-label');if(lbl)lbl.textContent='\u20B1'+t.toLocaleString('en-US',{minimumFractionDigits:2,maximumFractionDigits:2});});ai.value=g.toFixed(2);}
    function addRow(){const tr=document.createElement('tr');tr.style.borderBottom='1px solid var(--neutral-100)';tr.innerHTML=`<td><input type="text" name="item_desc[]" class="form-control" style="font-size:.82rem;" placeholder="e.g. Cooking Oil" required></td><td style="width:90px"><input type="number" name="item_price[]" class="form-control item-price" style="font-size:.82rem;" min="0" step="0.01" placeholder="0.00" required></td><td style="width:70px"><input type="number" name="item_qty[]" class="form-control item-qty" style="font-size:.82rem;" min="1" value="1" required></td><td style="width:90px;text-align:right;font-weight:700;font-size:.88rem;" class="item-line-total-label">\u20B10.00</td><td style="width:36px;text-align:center"><button type="button" onclick="removeExpRow(this)" style="background:none;border:none;color:#dc2626;cursor:pointer;"><i class="fas fa-times"></i></button></td>`;tb.appendChild(tr);recalc();}
    window.removeExpRow=function(btn){btn.closest('tr').remove();if(!tb.children.length)addRow();else recalc();};
    tb.addEventListener('input',e=>{if(e.target.classList.contains('item-price')||e.target.classList.contains('item-qty'))recalc();});
    ab.addEventListener('click',addRow);addRow();
})();
const CHEF_API_OPS = BASE_URL + 'dashboard/business_owner/api/chef_action.php';
async function acknowledgeReq(reqId){
    const btn = document.querySelector('[data-ack-id="'+reqId+'"]');
    if (btn) { btn.disabled = true; btn.innerHTML = '<i class="fas fa-spinner fa-spin"></i> Acknowledging...'; }
    try {
        const fd = new FormData();
        fd.append('action','acknowledge_grocery_list');
        fd.append('gl_id', reqId);
        const r = await fetch(CHEF_API_OPS, {method:'POST', body:fd});
        if (!r.ok) throw new Error('Server error: ' + r.status);
        const d = await r.json();
        if (d.success) { location.reload(); }
        else {
            alert(d.message);
            if (btn) { btn.disabled = false; btn.innerHTML = '<i class="fas fa-check"></i> Acknowledge'; }
        }
    } catch(err) {
        alert('Request failed: ' + err.message);
        if (btn) { btn.disabled = false; btn.innerHTML = '<i class="fas fa-check"></i> Acknowledge'; }
    }
}
async function approveReq(e, reqId){
    e.preventDefault();
    const form = e.target;

    // Guard: must be acknowledged before approving
    const card = form.closest('.card');
    const statusBadge = card ? card.querySelector('.req-status-badge') : null;
    const currentStatus = statusBadge ? statusBadge.dataset.status : '';
    if (currentStatus === 'pending') {
        alert('Please acknowledge this grocery list before approving it.');
        return;
    }

    const fd = new FormData(form);
    fd.set('action','approve_grocery_list');
    fd.set('gl_id', reqId);
    const btn = form.querySelector('button[type=submit]');
    btn.disabled = true;
    btn.innerHTML = '<i class="fas fa-spinner fa-spin"></i> Processing...';
    try {
        const r = await fetch(CHEF_API_OPS, {method:'POST', body:fd});
        if (!r.ok) throw new Error('Server error: ' + r.status);
        const d = await r.json();
        if (d.success) {
            location.href = '?tab=requisitions&success=' + encodeURIComponent(d.message);
        } else {
            btn.disabled = false;
            btn.innerHTML = '<i class="fas fa-check-double"></i> Approve &amp; Notify Chef';
            alert(d.message);
        }
    } catch(err) {
        btn.disabled = false;
        btn.innerHTML = '<i class="fas fa-check-double"></i> Approve &amp; Notify Chef';
        alert('Request failed: ' + err.message);
    }
}
async function rejectReq(reqId){
    const reason = prompt('Enter a rejection reason (required):');
    if (reason === null) return;
    if (!reason.trim()) { alert('A rejection reason is required.'); return; }
    try {
        const fd = new FormData();
        fd.append('action','reject_grocery_list');
        fd.append('gl_id', reqId);
        fd.append('ops_notes', reason.trim());
        const r = await fetch(CHEF_API_OPS, {method:'POST', body:fd});
        if (!r.ok) throw new Error('Server error: ' + r.status);
        const d = await r.json();
        if (d.success) {
            location.href = '?tab=requisitions&success=' + encodeURIComponent(d.message);
        } else {
            alert(d.message);
        }
    } catch(err) {
        alert('Request failed: ' + err.message);
    }
}
EXPJS;
$extra_js .= <<<INFLOWJS

// -- Other Inflows JS --
function toggleCateringFields() {
    const sel = document.getElementById('inflow-type-sel');
    const cf  = document.getElementById('catering-fields');
    if (cf) cf.style.display = (sel && (sel.value === 'catering' || sel.value === 'event')) ? '' : 'none';
}
document.addEventListener('DOMContentLoaded', () => { toggleCateringFields(); });

function rejectInflow(id) {
    const reason = prompt('Enter rejection reason (required):');
    if (reason === null) return;
    if (!reason.trim()) { alert('A reason is required.'); return; }
    document.getElementById('reject-inflow-id').value = id;
    document.getElementById('reject-inflow-reason').value = reason.trim();
    document.getElementById('reject-inflow-form').submit();
}
</script>
INFLOWJS;

// ── Log Order tab JS ──────────────────────────────────────────────────────────
$extra_js .= '<script>' . "\n";
$extra_js .= 'const LO_API = ' . json_encode(BASE_URL . 'dashboard/business_owner/api/log_backdated_order.php') . ';' . "\n";
$extra_js .= <<<'LOJS'

// basket state: { id: { id, name, price, qty } }
const _loBasket = {};

function _loFmt(n) {
    return '₱' + parseFloat(n).toLocaleString('en-PH', { minimumFractionDigits:2, maximumFractionDigits:2 });
}

function loAddItem(el) {
    const id    = parseInt(el.dataset.id, 10);
    const name  = el.dataset.name;
    const price = parseFloat(el.dataset.price);
    if (_loBasket[id]) {
        _loBasket[id].qty += 1;
    } else {
        _loBasket[id] = { id, name, price, qty: 1 };
    }
    loRenderBasket();
}

function loRenderBasket() {
    const basket  = document.getElementById('lo-basket');
    const empty   = document.getElementById('lo-basket-empty');
    const counter = document.getElementById('lo-item-count');
    const totalEl = document.getElementById('lo-total');
    if (!basket) return;

    const keys = Object.keys(_loBasket);
    if (!keys.length) {
        empty && (empty.style.display = '');
        counter && (counter.textContent = '0 items');
        totalEl && (totalEl.textContent = '₱0.00');
        // remove item rows
        basket.querySelectorAll('.lo-basket-row').forEach(r => r.remove());
        return;
    }
    empty && (empty.style.display = 'none');

    // rebuild rows
    basket.querySelectorAll('.lo-basket-row').forEach(r => r.remove());

    let total = 0;
    keys.forEach(id => {
        const it  = _loBasket[id];
        const lt  = it.price * it.qty;
        total    += lt;
        const row = document.createElement('div');
        row.className = 'lo-basket-row';
        row.style.cssText = 'display:flex;align-items:center;gap:8px;padding:6px 0;border-bottom:1px solid #e0e7ff;font-size:.85rem;';
        row.innerHTML =
            `<div style="flex:1;font-weight:600;color:var(--neutral-800);">${it.name}</div>` +
            `<div style="display:flex;align-items:center;gap:5px;">` +
            `<button type="button" onclick="loQty(${id},-1)"` +
            ` style="width:24px;height:24px;border-radius:50%;border:1.5px solid #c7d2fe;background:#fff;` +
            ` cursor:pointer;font-size:.85rem;font-weight:700;color:#4338ca;line-height:1;">−</button>` +
            `<span style="min-width:22px;text-align:center;font-weight:700;">${it.qty}</span>` +
            `<button type="button" onclick="loQty(${id},1)"` +
            ` style="width:24px;height:24px;border-radius:50%;border:1.5px solid #c7d2fe;background:#fff;` +
            ` cursor:pointer;font-size:.85rem;font-weight:700;color:#4338ca;line-height:1;">+</button>` +
            `</div>` +
            `<div style="font-weight:700;color:#4338ca;white-space:nowrap;min-width:72px;text-align:right;">${_loFmt(lt)}</div>` +
            `<button type="button" onclick="loRemove(${id})"` +
            ` style="background:none;border:none;color:#ef4444;cursor:pointer;font-size:.85rem;padding:0 4px;"` +
            `><i class="fas fa-times"></i></button>`;
        basket.appendChild(row);
    });

    const count = keys.reduce((s, k) => s + _loBasket[k].qty, 0);
    counter && (counter.textContent = count + ' item' + (count !== 1 ? 's' : ''));
    totalEl && (totalEl.textContent = _loFmt(total));
}

function loQty(id, delta) {
    if (!_loBasket[id]) return;
    _loBasket[id].qty += delta;
    if (_loBasket[id].qty <= 0) delete _loBasket[id];
    loRenderBasket();
}

function loRemove(id) {
    delete _loBasket[id];
    loRenderBasket();
}

// Menu search filter
(function() {
    const inp = document.getElementById('lo-menu-search');
    if (!inp) return;
    inp.addEventListener('input', function() {
        const q = this.value.trim().toLowerCase();
        document.querySelectorAll('.lo-menu-row').forEach(row => {
            row.style.display = (!q || row.dataset.search.includes(q)) ? '' : 'none';
        });
    });
})();

async function loSubmit() {
    const btn    = document.getElementById('lo-submit-btn');
    const toast  = document.getElementById('log-order-toast');
    const keys   = Object.keys(_loBasket);

    if (!keys.length) { loToast('Add at least one item.', true); return; }
    const date = document.getElementById('lo-date')?.value;
    if (!date) { loToast('Please select an order date.', true); return; }

    btn.disabled = true;
    btn.innerHTML = '<i class="fas fa-spinner fa-spin"></i> Saving…';

    const items = keys.map(k => ({ id: _loBasket[k].id, qty: _loBasket[k].qty }));
    const fd    = new FormData();
    fd.append('order_date',    date);
    fd.append('order_time',    document.getElementById('lo-time')?.value  || '12:00');
    fd.append('service_type',  document.getElementById('lo-service')?.value  || 'dine_in');
    fd.append('payment_method',document.getElementById('lo-payment')?.value  || 'cash');
    fd.append('customer_name', document.getElementById('lo-customer')?.value || '');
    fd.append('notes',         document.getElementById('lo-notes')?.value    || '');
    fd.append('items',         JSON.stringify(items));

    try {
        const r = await fetch(LO_API, { method: 'POST', body: fd });
        const d = await r.json();
        if (d.success) {
            loToast('✓ ' + d.message, false);
            // clear basket
            Object.keys(_loBasket).forEach(k => delete _loBasket[k]);
            loRenderBasket();
            document.getElementById('lo-customer').value = '';
            document.getElementById('lo-notes').value    = '';
        } else {
            loToast('Error: ' + d.message, true);
        }
    } catch (e) {
        loToast('Request failed: ' + e.message, true);
    } finally {
        btn.disabled = false;
        btn.innerHTML = '<i class="fas fa-save"></i> Save Order';
    }
}

function loToast(msg, isErr) {
    const t = document.getElementById('log-order-toast');
    if (!t) return;
    t.textContent    = msg;
    t.style.display  = '';
    t.style.background = isErr ? '#fee2e2' : '#dcfce7';
    t.style.color      = isErr ? '#991b1b' : '#166534';
    t.style.border     = '1px solid ' + (isErr ? '#fca5a5' : '#86efac');
    clearTimeout(t._tid);
    t._tid = setTimeout(() => { t.style.display = 'none'; }, 6000);
}
</script>
LOJS;
require_once __DIR__ . '/../../includes/footer.php';?>


