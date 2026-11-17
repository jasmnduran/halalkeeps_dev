<?php
require_once __DIR__ . '/../../config/app.php';
require_once __DIR__ . '/../../includes/MenuWorkflowService.php';
requireLogin();
requireRole([ROLE_BUSINESS_OWNER]);
requireStaffAccess('Owner Dashboard'); // blocks ops_manager and cashier staff
$page_heading = 'Owner Dashboard';
$is_dashboard = true;
$breadcrumbs  = [['label' => 'Owner Dashboard']];
$user_id = $_SESSION['user_id'];

// -- Idempotent migration: ensure staff tables exist --------------------------
$conn->query("CREATE TABLE IF NOT EXISTS `restaurant_staff_roles` (
    `id`          SMALLINT UNSIGNED NOT NULL AUTO_INCREMENT,
    `role_key`    VARCHAR(30)  NOT NULL,
    `role_label`  VARCHAR(60)  NOT NULL,
    `description` VARCHAR(255) DEFAULT NULL,
    PRIMARY KEY (`id`),
    UNIQUE KEY `uq_role_key` (`role_key`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");

// Widen id column if it was previously TINYINT (max 255) — prevents AUTO_INCREMENT overflow
// FK checks must be off and foreign keys temporarily dropped to permit altering PK column types in MariaDB
$conn->query("SET FOREIGN_KEY_CHECKS=0");
try { $conn->query("ALTER TABLE `restaurant_staff` DROP FOREIGN KEY `rs_role_fk`"); } catch (Throwable $e) {}
try { $conn->query("ALTER TABLE `restaurant_staff` DROP FOREIGN KEY `rs_staff_role_fk`"); } catch (Throwable $e) {}
$conn->query("ALTER TABLE `restaurant_staff_roles` MODIFY COLUMN `id` SMALLINT UNSIGNED NOT NULL AUTO_INCREMENT");
$conn->query("ALTER TABLE `restaurant_staff`        MODIFY COLUMN `staff_role_id` SMALLINT UNSIGNED NOT NULL");
try { $conn->query("ALTER TABLE `restaurant_staff` ADD CONSTRAINT `rs_role_fk` FOREIGN KEY (`staff_role_id`) REFERENCES `restaurant_staff_roles`(`id`)"); } catch (Throwable $e) {}
$conn->query("SET FOREIGN_KEY_CHECKS=1");

// Seed default roles — use ON DUPLICATE KEY UPDATE (no-op) so the AUTO_INCREMENT
// counter is NOT bumped for already-existing rows (INSERT IGNORE does bump it in InnoDB)
$conn->query("INSERT INTO `restaurant_staff_roles` (`role_key`,`role_label`,`description`) VALUES
('owner',       'Business Owner',       'Full access: analytics, financials, staff management.'),
('ops_manager', 'Operations Manager',   'Store profile, menu management, and Halal compliance.'),
('cashier',     'Cashier',              'POS order intake and payment processing only.'),
('chef',        'Chef / Head Cook',     'Manages kitchen inventory, requisitions, and menu recipe mapping.')
ON DUPLICATE KEY UPDATE role_label = VALUES(role_label), description = VALUES(description)");

$conn->query("CREATE TABLE IF NOT EXISTS `restaurant_staff` (
    `id`            INT UNSIGNED     NOT NULL AUTO_INCREMENT,
    `restaurant_id` INT(11)          NOT NULL,
    `user_id`       INT(11)          NOT NULL,
    `staff_role_id` SMALLINT UNSIGNED NOT NULL,
    `is_active`     TINYINT(1)       NOT NULL DEFAULT 1,
    `assigned_by`   INT(11)          NOT NULL,
    `assigned_at`   TIMESTAMP        NOT NULL DEFAULT CURRENT_TIMESTAMP,
    `updated_at`    TIMESTAMP        NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (`id`),
    UNIQUE KEY `uq_user_restaurant` (`user_id`,`restaurant_id`),
    KEY `idx_rs_restaurant` (`restaurant_id`),
    KEY `idx_rs_user` (`user_id`),
    CONSTRAINT `rs_restaurant_fk` FOREIGN KEY (`restaurant_id`) REFERENCES `halal_restaurants`(`id`) ON DELETE CASCADE,
    CONSTRAINT `rs_user_fk`       FOREIGN KEY (`user_id`)       REFERENCES `users`(`id`) ON DELETE CASCADE,
    CONSTRAINT `rs_role_fk`       FOREIGN KEY (`staff_role_id`) REFERENCES `restaurant_staff_roles`(`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");

// -- Idempotent migration: ensure all orders columns exist --------------------
// These are added by cash_payment_migration.sql, order_survey_integration_migration.sql,
// and add_paymongo_revenue_models.sql — guard with db_add_column so the page
// never 500s on a fresh database that hasn't had every migration applied yet.
db_add_column($conn, 'orders', 'payment_reference',           "VARCHAR(255) DEFAULT NULL");
db_add_column($conn, 'orders', 'payment_verified_at',         "DATETIME DEFAULT NULL");
db_add_column($conn, 'orders', 'platform_fee',                "DECIMAL(12,2) NOT NULL DEFAULT 0.00");
db_add_column($conn, 'orders', 'platform_fee_rate',           "DECIMAL(7,6) NOT NULL DEFAULT 0.000000");
db_add_column($conn, 'orders', 'discount_amount',             "DECIMAL(12,2) NOT NULL DEFAULT 0.00");
db_add_column($conn, 'orders', 'order_source',                "VARCHAR(30) NOT NULL DEFAULT 'customer'");
db_add_column($conn, 'orders', 'paymongo_checkout_session_id',"VARCHAR(100) DEFAULT NULL");
db_add_column($conn, 'orders', 'receipt_number',              "VARCHAR(80) DEFAULT NULL");
db_add_column($conn, 'orders', 'cash_received',               "DECIMAL(10,2) DEFAULT NULL");
db_add_column($conn, 'orders', 'change_amount',               "DECIMAL(10,2) DEFAULT NULL");
db_add_column($conn, 'orders', 'payment_confirmed_by',        "INT DEFAULT NULL");
db_add_column($conn, 'orders', 'service_type',                "VARCHAR(30) NOT NULL DEFAULT 'delivery'");
db_add_column($conn, 'orders', 'backdated_note',              "TEXT DEFAULT NULL");

// -- Idempotent migration: ensure menu_items columns exist --------------------
db_add_column($conn, 'menu_items', 'approval_status',           "ENUM('pending','approved','rejected') NOT NULL DEFAULT 'approved'");
db_add_column($conn, 'menu_items', 'operational_status',        "ENUM('available','unavailable','out_of_stock') NOT NULL DEFAULT 'available'");
db_add_column($conn, 'menu_items', 'ingredient_evidence_status',"VARCHAR(30) NOT NULL DEFAULT 'unchecked'");
db_add_column($conn, 'menu_items', 'serving_type',              "VARCHAR(30) NOT NULL DEFAULT 'Per serving'");

// -- Load restaurant with certificate info -----------------------------------
$rest_stmt = $conn->prepare("
    SELECT hr.*, hc.certificate_number, hc.issue_date, hc.expiry_date, hc.status AS cert_status
    FROM halal_restaurants hr
    LEFT JOIN halal_certificates hc ON hr.certificate_id = hc.id
    WHERE hr.business_owner_id = ? LIMIT 1
");
$rest_stmt->bind_param("i", $user_id);
$rest_stmt->execute();
$restaurant = $rest_stmt->get_result()->fetch_assoc();
$restaurant_id = $restaurant ? (int)$restaurant['id'] : 0;

// -- Ensure HaCeRa columns exist on halal_restaurants table ───────────────────
if ($restaurant_id) {
    db_add_column($conn, 'halal_restaurants', 'hacera_hrp',          'DECIMAL(5,1) DEFAULT NULL');
    db_add_column($conn, 'halal_restaurants', 'hacera_band',         'VARCHAR(30)  DEFAULT NULL');
    db_add_column($conn, 'halal_restaurants', 'hacera_published_at', 'DATETIME     DEFAULT NULL');
}

// -- Safety-net: auto-create halal_restaurants if approved application exists
if (!$restaurant_id) {
    $ba_auto = $conn->query("
        SELECT ba.business_name,
               bb.address_street, bb.address_barangay,
               bb.address_city, bb.address_province,
               bb.address_region, bb.address_zip
        FROM business_applications ba
        LEFT JOIN business_branches bb
               ON bb.application_id = ba.id AND bb.is_main = 1
        WHERE ba.user_id = $user_id AND ba.status = 'approved'
        ORDER BY ba.created_at DESC LIMIT 1
    ")->fetch_assoc();

    if ($ba_auto) {
        $auto_name = $ba_auto['business_name'] ?: ($_SESSION['full_name'] . "'s Restaurant");
        $auto_addr_parts = array_filter([
            $ba_auto['address_street']   ?? '',
            $ba_auto['address_barangay'] ?? '',
            $ba_auto['address_city']     ?? '',
            $ba_auto['address_province'] ?? '',
            $ba_auto['address_region']   ?? '',
            $ba_auto['address_zip']      ?? '',
        ]);
        $auto_addr = implode(', ', $auto_addr_parts);

        $ri = $conn->prepare(
            "INSERT INTO halal_restaurants (business_owner_id, restaurant_name, address, is_active)
             VALUES (?, ?, ?, 1)"
        );
        $ri->bind_param("iss", $user_id, $auto_name, $auto_addr);
        if ($ri->execute()) {
            $restaurant_id = (int)$conn->insert_id;
            $rest_stmt->bind_param("i", $user_id);
            $rest_stmt->execute();
            $restaurant = $rest_stmt->get_result()->fetch_assoc();
        }
    }
}

// Active tab determination (URL parameter state persistence)
$active_tab = in_array($_GET['tab'] ?? '', ['overview', 'financial_report', 'business_visits', 'demand_insights']) ? $_GET['tab'] : 'overview';

// ── Financial report period filter ────────────────────────────────────────────
// fin_period drives all revenue / expense / inflow queries on the financial tab
$fin_period = in_array($_GET['fin_period'] ?? '', ['today','week','month','year'])
    ? $_GET['fin_period'] : 'today';

// ── Month picker: fin_month = YYYY-MM, defaults to current month ──────────────
// Only active when fin_period === 'month'. Allows any past or current month.
$_raw_month = trim($_GET['fin_month'] ?? '');
if (preg_match('/^(\d{4})-(\d{2})$/', $_raw_month, $_mm)
    && (int)$_mm[1] >= 2020
    && mktime(0,0,0,(int)$_mm[2],1,(int)$_mm[1]) <= mktime(0,0,0,date('n'),1,date('Y'))
) {
    $fin_month      = $_raw_month;          // e.g. "2026-03"
    $fin_month_year = (int)$_mm[1];
    $fin_month_num  = (int)$_mm[2];
} else {
    $fin_month      = date('Y-m');
    $fin_month_year = (int)date('Y');
    $fin_month_num  = (int)date('n');
}
$fin_month_days = (int)date('t', mktime(0,0,0,$fin_month_num,1,$fin_month_year));
$fin_month_label = date('F Y', mktime(0,0,0,$fin_month_num,1,$fin_month_year));

// Map each period to a human label, SQL date-from string, and date-to string
$_fin_period_map = [
    'today' => [
        'label'         => 'Today',
        'orders_where'  => "DATE(payment_verified_at) = CURDATE()",
        'exp_where'     => "DATE(expense_date) = CURDATE()",
        'inflow_where'  => "DATE(inflow_date) = CURDATE()",
        'chart_mode'    => 'hourly',
    ],
    'week'  => [
        'label'         => 'This Week',
        'orders_where'  => "DATE(payment_verified_at) BETWEEN DATE_SUB(CURDATE(), INTERVAL WEEKDAY(CURDATE()) DAY) AND CURDATE()",
        'exp_where'     => "expense_date BETWEEN DATE_SUB(CURDATE(), INTERVAL WEEKDAY(CURDATE()) DAY) AND CURDATE()",
        'inflow_where'  => "inflow_date BETWEEN DATE_SUB(CURDATE(), INTERVAL WEEKDAY(CURDATE()) DAY) AND CURDATE()",
        'chart_mode'    => 'daily',
    ],
    'month' => [
        'label'         => $fin_month_label,
        'orders_where'  => "MONTH(payment_verified_at)={$fin_month_num} AND YEAR(payment_verified_at)={$fin_month_year}",
        'exp_where'     => "MONTH(expense_date)={$fin_month_num} AND YEAR(expense_date)={$fin_month_year}",
        'inflow_where'  => "MONTH(inflow_date)={$fin_month_num} AND YEAR(inflow_date)={$fin_month_year}",
        'chart_mode'    => 'daily',
    ],
    'year'  => [
        'label'         => 'This Year',
        'orders_where'  => "YEAR(payment_verified_at)=YEAR(NOW())",
        'exp_where'     => "YEAR(expense_date)=YEAR(NOW())",
        'inflow_where'  => "YEAR(inflow_date)=YEAR(NOW())",
        'chart_mode'    => 'monthly',
    ],
];
$_fp           = $_fin_period_map[$fin_period];
$fin_label     = $_fp['label'];
$_ord_where    = $_fp['orders_where'];
$_exp_where    = $_fp['exp_where'];
$_inf_where    = $_fp['inflow_where'];
$_chart_mode   = $_fp['chart_mode'];

// Helper: build URL preserving current fin_month when switching periods
function finUrl(string $period, string $month = ''): string {
    $q = ['tab' => 'financial_report', 'fin_period' => $period];
    if ($period === 'month' && $month !== '') $q['fin_month'] = $month;
    return '?' . http_build_query($q);
}

// Pending staff applications count
$pending_staff_count = 0;
$tbl_sa = $conn->query("SHOW TABLES LIKE 'staff_applications'");
if ($tbl_sa && $tbl_sa->num_rows > 0 && $restaurant_id) {
    $psc = $conn->query(
        "SELECT COUNT(*) AS c FROM staff_applications
         WHERE restaurant_id = $restaurant_id AND status = 'pending'"
    )->fetch_assoc();
    $pending_staff_count = (int)($psc['c'] ?? 0);
}

// -- Stats (Operational & Monthly Financial) -----------------------------------
$stats = ['total_orders' => 0, 'revenue_today' => 0, 'revenue_week' => 0,
          'revenue_month' => 0, 'avg_rating' => 0, 'total_reviews' => 0,
          'pending_orders' => 0, 'total_staff' => 0];

if ($restaurant_id) {
    $s = $conn->query("SELECT
        COUNT(*) AS total_orders,
        SUM(CASE WHEN DATE(payment_verified_at) = CURDATE() AND payment_status='paid' THEN (total_amount - delivery_fee - COALESCE(platform_fee, 0)) ELSE 0 END) AS revenue_today,
        SUM(CASE WHEN payment_verified_at >= DATE_SUB(NOW(),INTERVAL 7 DAY) AND payment_status='paid' THEN (total_amount - delivery_fee - COALESCE(platform_fee, 0)) ELSE 0 END) AS revenue_week,
        SUM(CASE WHEN MONTH(payment_verified_at)=MONTH(NOW()) AND YEAR(payment_verified_at)=YEAR(NOW()) AND payment_status='paid' THEN (total_amount - delivery_fee - COALESCE(platform_fee, 0)) ELSE 0 END) AS revenue_month,
        SUM(CASE WHEN order_status='pending' THEN 1 ELSE 0 END) AS pending_orders
        FROM orders WHERE restaurant_id = $restaurant_id")->fetch_assoc();
    if ($s) $stats = array_merge($stats, $s);
    // Live review aggregates — query directly to avoid stale denormalized column
    $live_rv = $conn->query(
        "SELECT ROUND(AVG(rating),1) AS avg_r, COUNT(*) AS cnt FROM reviews WHERE restaurant_id=$restaurant_id"
    )->fetch_assoc();
    $stats['avg_rating']    = (float)($live_rv['avg_r'] ?? 0);
    $stats['total_reviews'] = (int)  ($live_rv['cnt']   ?? 0);
    $stats['total_staff']   = (int)$conn->query(
        "SELECT COUNT(*) AS c FROM restaurant_staff WHERE restaurant_id=$restaurant_id AND is_active=1"
    )->fetch_assoc()['c'];

    // ── Ensure restaurant_expenses table exists + has added_by_role column ────────
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

    db_add_column($conn, 'restaurant_expenses', 'added_by_role',
        "VARCHAR(30) NOT NULL DEFAULT 'unknown'", 'logged_by');

    // ── Ensure restaurant_expense_items child table exists ────────────────────
    $conn->query("CREATE TABLE IF NOT EXISTS `restaurant_expense_items` (
        `id`            INT UNSIGNED  NOT NULL AUTO_INCREMENT,
        `expense_id`    INT UNSIGNED  NOT NULL,
        `description`   VARCHAR(255)  NOT NULL,
        `unit_price`    DECIMAL(10,2) NOT NULL,
        `quantity`      INT UNSIGNED  NOT NULL DEFAULT 1,
        `line_total`    DECIMAL(10,2) NOT NULL,
        PRIMARY KEY (`id`),
        KEY `idx_rei_expense` (`expense_id`),
        CONSTRAINT `rei_expense_fk_owner` FOREIGN KEY (`expense_id`)
            REFERENCES `restaurant_expenses` (`id`) ON DELETE CASCADE
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");

    // ── Business Visits & Foot Traffic Analytics Aggregation ────────────────
    $traffic_data = getBusinessTrafficStats($conn, $restaurant_id);
    $traffic_stats = $traffic_data;
    extract($traffic_data, EXTR_SKIP);

    // ── Handle Expense POST Actions by Owner ─────────────────────────────────
    $exp_error   = '';
    $exp_success = '';

    if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['save_owner_expense']) && $restaurant_id) {
        $exp_cat   = trim(strip_tags($_POST['exp_category']    ?? 'Other'));
        $exp_desc  = trim(strip_tags($_POST['exp_description'] ?? ''));
        $exp_amt   = (float)($_POST['exp_amount'] ?? 0);
        $exp_date  = $_POST['exp_date']  ?? date('Y-m-d');
        $exp_per   = in_array($_POST['exp_period'] ?? '', ['daily','weekly','monthly'])
                        ? $_POST['exp_period'] : 'daily';

        $allowed_cats = ['Ingredients','Utilities','Supplies','Wages','Maintenance',
                         'Marketing','Transport','Rent','Other'];
        if (!in_array($exp_cat, $allowed_cats, true)) $exp_cat = 'Other';
        if (!strtotime($exp_date)) $exp_date = date('Y-m-d');

        // Receipt upload
        $receipt_path = null;
        if (!empty($_FILES['exp_receipt']['name']) && $_FILES['exp_receipt']['error'] === UPLOAD_ERR_OK) {
            $rext = strtolower(pathinfo($_FILES['exp_receipt']['name'], PATHINFO_EXTENSION));
            if (in_array($rext, ['jpg','jpeg','png','webp','pdf'], true)
                && $_FILES['exp_receipt']['size'] <= MAX_FILE_SIZE) {
                $rdir = UPLOAD_DIR . 'expense_receipts/';
                @mkdir($rdir, 0755, true);
                $rfn  = 'receipt_' . $restaurant_id . '_' . time() . '.' . $rext;
                if (move_uploaded_file($_FILES['exp_receipt']['tmp_name'], $rdir . $rfn)) {
                    $receipt_path = 'expense_receipts/' . $rfn;
                }
            }
        }

        if ($exp_desc && $exp_amt > 0) {
            $ins = $conn->prepare("
                INSERT INTO restaurant_expenses
                    (restaurant_id, logged_by, added_by_role, category, description,
                     amount, receipt_path, expense_date, period_tag)
                VALUES (?,?,'business_owner',?,?,?,?,?,?)
            ");
            $ins->bind_param("iisdssss",
                $restaurant_id, $user_id, $exp_cat, $exp_desc,
                $exp_amt, $receipt_path, $exp_date, $exp_per
            );
            $ins->execute();
            $exp_success = 'Expense logged successfully by Business Owner.';
            $active_tab  = 'financial_report';
        } else {
            $exp_error  = 'Please fill in a description and a valid amount.';
            $active_tab = 'financial_report';
        }
    }

    if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['delete_expense']) && $restaurant_id) {
        $del_id = (int)$_POST['delete_expense'];
        $conn->query("DELETE FROM restaurant_expenses WHERE id=$del_id AND restaurant_id=$restaurant_id");
        $exp_success = 'Expense entry removed.';
        $active_tab  = 'financial_report';
    }

    // ── Period-aware revenue query ────────────────────────────────────────────
    $revRes = $conn->query("
        SELECT
            COALESCE(SUM(total_amount - delivery_fee - COALESCE(platform_fee, 0)), 0) AS period_revenue,
            COALESCE(SUM(delivery_fee), 0)               AS period_delivery_fees,
            COUNT(*)                                      AS period_order_count,
            SUM(CASE WHEN order_source='customer' THEN 1 ELSE 0 END) AS customer_orders,
            SUM(CASE WHEN order_source='pos' THEN 1 ELSE 0 END)      AS pos_orders
        FROM orders
        WHERE payment_status = 'paid'
          AND $_ord_where
          AND restaurant_id = $restaurant_id
    ");
    $revRow                  = $revRes->fetch_assoc();
    $todayRevenue            = (float)$revRow['period_revenue'];
    $todayOrderCount         = (int)  $revRow['period_order_count'];
    $todayCustomerOrders     = (int)  $revRow['customer_orders'];
    $todayPosOrders          = (int)  $revRow['pos_orders'];
    $deliveryFeeExpenseToday = (float)$revRow['period_delivery_fees'];

    $opExpRes        = $conn->query("SELECT COALESCE(SUM(amount),0) AS op_expenses FROM restaurant_expenses WHERE restaurant_id=$restaurant_id AND $_exp_where");
    $opExpensesToday = (float)$opExpRes->fetch_assoc()['op_expenses'];

    // ── Other Inflows (approved) — today's total added to revenue ─────────────
    // Ensure the table exists before querying (idempotent)
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

    $inflowTodayRes  = $conn->query("SELECT COALESCE(SUM(amount),0) AS inflow_today FROM restaurant_other_inflows WHERE restaurant_id=$restaurant_id AND status='approved' AND $_inf_where");
    $otherInflowToday = (float)($inflowTodayRes->fetch_assoc()['inflow_today'] ?? 0);

    // Monthly inflow for stats card (always month regardless of period)
    $inflowMonthRes  = $conn->query("SELECT COALESCE(SUM(amount),0) AS inflow_month FROM restaurant_other_inflows WHERE restaurant_id=$restaurant_id AND status='approved' AND MONTH(inflow_date)=MONTH(NOW()) AND YEAR(inflow_date)=YEAR(NOW())");
    $otherInflowMonth = (float)($inflowMonthRes->fetch_assoc()['inflow_month'] ?? 0);

    // Inflows for the panel table — scoped to the selected period
    $inflowAllRes  = $conn->query("
        SELECT roi.*, u.full_name AS created_by_name
        FROM restaurant_other_inflows roi
        LEFT JOIN users u ON roi.created_by = u.id
        WHERE roi.restaurant_id = $restaurant_id
          AND $_inf_where
        ORDER BY roi.inflow_date DESC, roi.created_at DESC
        LIMIT 200
    ");
    $ownerInflowRecords = $inflowAllRes ? $inflowAllRes->fetch_all(MYSQLI_ASSOC) : [];

    // Period totals by status (scoped to the selected period for the stat strip)
    $ownerInflowTotals = ['pending' => 0.0, 'approved' => 0.0, 'rejected' => 0.0];
    $inflowTotRes = $conn->query("
        SELECT status, COALESCE(SUM(amount),0) AS total
        FROM restaurant_other_inflows
        WHERE restaurant_id = $restaurant_id AND $_inf_where
        GROUP BY status
    ");
    while ($tr = $inflowTotRes ? $inflowTotRes->fetch_assoc() : null) {
        if (!$tr) break;
        $ownerInflowTotals[$tr['status']] = (float)$tr['total'];
    }

    // Revenue = orders + approved other inflows (catering, grants, etc.)
    $todayRevenue  += $otherInflowToday;
    $todayExpenses  = $deliveryFeeExpenseToday + $opExpensesToday;
    $netCashFlow    = $todayRevenue - $todayExpenses;

    // ── Payment Method Breakdown (period-aware) ───────────────────────────────
    $paymentBreakdownRes = $conn->query("
        SELECT
            payment_method,
            COUNT(*) AS order_count,
            COALESCE(SUM(total_amount - delivery_fee - COALESCE(platform_fee, 0)), 0) AS revenue
        FROM orders
        WHERE payment_status = 'paid'
          AND $_ord_where
          AND restaurant_id = $restaurant_id
        GROUP BY payment_method
        ORDER BY revenue DESC
    ");
    $paymentBreakdown = [];
    $cashRevenue = 0;
    $onlineRevenue = 0;
    $cashOrders = 0;
    $onlineOrders = 0;
    
    while ($pmRow = $paymentBreakdownRes->fetch_assoc()) {
        $method = $pmRow['payment_method'];
        $revenue = (float)$pmRow['revenue'];
        $count = (int)$pmRow['order_count'];
        
        $paymentBreakdown[] = [
            'method' => $method,
            'count' => $count,
            'revenue' => $revenue
        ];
        
        // Categorize as cash or online
        if ($method === 'cash') {
            $cashRevenue += $revenue;
            $cashOrders += $count;
        } else {
            // gcash, bank_transfer, paymongo, etc. are online
            $onlineRevenue += $revenue;
            $onlineOrders += $count;
        }
    }

    // Synchronized Expenses Fetch — scoped to the active fin_period / fin_month.
    // Pass $_exp_where directly so the table always matches the KPI cards and chart.
    $exp_records = getSharedExpenses($conn, $restaurant_id, 'all', 0, "AND {$_exp_where}");
    $exp_total   = array_sum(array_column($exp_records, 'amount'));
    // Keep $exp_filter for backwards compat (used in filter label HTML below)
    $exp_filter  = $fin_period;

    // Fetch itemized lines map for owner dashboard
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

    // Itemised transaction logs: Orders, Delivery Fees, Operational Expenses, Other Inflows
    // For today: timestamp-level detail. For wider periods: same rows, more volume.
    $logSql = "
        SELECT id, type, amount, ts, description FROM (
            SELECT id,
                   CAST('order' AS CHAR CHARACTER SET utf8mb4) AS type,
                   (total_amount - delivery_fee - COALESCE(platform_fee, 0)) AS amount,
                   payment_verified_at AS ts,
                   CONVERT(CONCAT('Order #', id, IF(delivery_fee>0,' (food subtotal)','')) USING utf8mb4) AS description
            FROM orders
            WHERE payment_status='paid' AND $_ord_where AND restaurant_id=$restaurant_id
            UNION ALL
            SELECT id,
                   CAST('delivery' AS CHAR CHARACTER SET utf8mb4) AS type,
                   delivery_fee AS amount,
                   payment_verified_at AS ts,
                   CONVERT(CONCAT('Delivery Fee — Order #', id) USING utf8mb4) AS description
            FROM orders
            WHERE payment_status='paid' AND $_ord_where AND restaurant_id=$restaurant_id AND delivery_fee > 0
            UNION ALL
            SELECT id,
                   CAST('expense' AS CHAR CHARACTER SET utf8mb4) AS type,
                   amount,
                   created_at AS ts,
                   CONVERT(CONCAT('[', category, '] ', description) USING utf8mb4) AS description
            FROM restaurant_expenses
            WHERE restaurant_id=$restaurant_id AND $_exp_where
            UNION ALL
            SELECT id,
                   CAST('inflow' AS CHAR CHARACTER SET utf8mb4) AS type,
                   amount,
                   created_at AS ts,
                   CONVERT(CONCAT('[', inflow_type, '] ', description) USING utf8mb4) AS description
            FROM restaurant_other_inflows
            WHERE restaurant_id=$restaurant_id AND status='approved' AND $_inf_where
        ) AS u ORDER BY ts DESC LIMIT 200
    ";
    $logRes    = $conn->query($logSql);
    $todayLogs = $logRes->fetch_all(MYSQLI_ASSOC);

    // ── Period-aware trend chart data ─────────────────────────────────────────
    // today  → group by HOUR  (0-23)
    // week   → group by DATE  (Mon-Sun, last 7 days)
    // month  → group by DAY   (1-31)
    // year   → group by MONTH (1-12)
    if ($_chart_mode === 'hourly') {
        $hourSql = "
            SELECT hour AS slot, COALESCE(SUM(revenue),0) AS revenue, COALESCE(SUM(expense),0) AS expense
            FROM (
                SELECT HOUR(payment_verified_at) AS hour, SUM(total_amount - delivery_fee - COALESCE(platform_fee, 0)) AS revenue, 0 AS expense
                FROM orders WHERE payment_status='paid' AND $_ord_where AND restaurant_id=$restaurant_id
                GROUP BY hour
                UNION ALL
                SELECT HOUR(payment_verified_at) AS hour, 0 AS revenue, SUM(delivery_fee) AS expense
                FROM orders WHERE payment_status='paid' AND $_ord_where AND restaurant_id=$restaurant_id AND delivery_fee > 0
                GROUP BY hour
                UNION ALL
                SELECT HOUR(created_at) AS hour, 0 AS revenue, SUM(amount) AS expense
                FROM restaurant_expenses WHERE restaurant_id=$restaurant_id AND $_exp_where
                GROUP BY hour
                UNION ALL
                SELECT HOUR(created_at) AS hour, SUM(amount) AS revenue, 0 AS expense
                FROM restaurant_other_inflows WHERE restaurant_id=$restaurant_id AND status='approved' AND $_inf_where
                GROUP BY hour
            ) AS t GROUP BY hour ORDER BY hour ASC
        ";
    } elseif ($_chart_mode === 'daily' && $fin_period === 'week') {
        $hourSql = "
            SELECT DATE(payment_verified_at) AS slot,
                   COALESCE(SUM(total_amount - delivery_fee - COALESCE(platform_fee, 0)),0) AS revenue,
                   0 AS expense
            FROM orders WHERE payment_status='paid' AND $_ord_where AND restaurant_id=$restaurant_id
            GROUP BY DATE(payment_verified_at)
            UNION ALL
            SELECT expense_date AS slot, 0 AS revenue, COALESCE(SUM(amount),0) AS expense
            FROM restaurant_expenses WHERE $_exp_where AND restaurant_id=$restaurant_id
            GROUP BY expense_date
            UNION ALL
            SELECT inflow_date AS slot, COALESCE(SUM(amount),0) AS revenue, 0 AS expense
            FROM restaurant_other_inflows WHERE status='approved' AND $_inf_where AND restaurant_id=$restaurant_id
            GROUP BY inflow_date
            ORDER BY slot ASC
        ";
    } elseif ($_chart_mode === 'daily' && $fin_period === 'month') {
        $hourSql = "
            SELECT DAY(payment_verified_at) AS slot,
                   COALESCE(SUM(total_amount - delivery_fee - COALESCE(platform_fee, 0)),0) AS revenue,
                   0 AS expense
            FROM orders WHERE payment_status='paid' AND $_ord_where AND restaurant_id=$restaurant_id
            GROUP BY DAY(payment_verified_at)
            UNION ALL
            SELECT DAY(expense_date) AS slot, 0 AS revenue, COALESCE(SUM(amount),0) AS expense
            FROM restaurant_expenses WHERE $_exp_where AND restaurant_id=$restaurant_id
            GROUP BY DAY(expense_date)
            UNION ALL
            SELECT DAY(inflow_date) AS slot, COALESCE(SUM(amount),0) AS revenue, 0 AS expense
            FROM restaurant_other_inflows WHERE status='approved' AND $_inf_where AND restaurant_id=$restaurant_id
            GROUP BY DAY(inflow_date)
            ORDER BY slot ASC
        ";
    } else {
        // year → monthly slots 1-12
        $hourSql = "
            SELECT MONTH(payment_verified_at) AS slot,
                   COALESCE(SUM(total_amount - delivery_fee - COALESCE(platform_fee, 0)),0) AS revenue,
                   0 AS expense
            FROM orders WHERE payment_status='paid' AND $_ord_where AND restaurant_id=$restaurant_id
            GROUP BY MONTH(payment_verified_at)
            UNION ALL
            SELECT MONTH(expense_date) AS slot, 0 AS revenue, COALESCE(SUM(amount),0) AS expense
            FROM restaurant_expenses WHERE $_exp_where AND restaurant_id=$restaurant_id
            GROUP BY MONTH(expense_date)
            UNION ALL
            SELECT MONTH(inflow_date) AS slot, COALESCE(SUM(amount),0) AS revenue, 0 AS expense
            FROM restaurant_other_inflows WHERE status='approved' AND $_inf_where AND restaurant_id=$restaurant_id
            GROUP BY MONTH(inflow_date)
            ORDER BY slot ASC
        ";
    }

    // Aggregate duplicate slots (UNION may produce multiple rows for same slot)
    $hourRes    = $conn->query($hourSql);
    $rawSlots   = $hourRes ? $hourRes->fetch_all(MYSQLI_ASSOC) : [];
    $slotAgg    = [];
    foreach ($rawSlots as $sr) {
        $k = $sr['slot'];
        if (!isset($slotAgg[$k])) $slotAgg[$k] = ['slot' => $k, 'revenue' => 0.0, 'expense' => 0.0];
        $slotAgg[$k]['revenue'] += (float)$sr['revenue'];
        $slotAgg[$k]['expense'] += (float)$sr['expense'];
    }
    ksort($slotAgg);
    $hourlyData = array_values($slotAgg);
} else {
    $todayRevenue            = 0;
    $todayExpenses           = 0;
    $netCashFlow             = 0;
    $deliveryFeeExpenseToday = 0;
    $opExpensesToday         = 0;
    $otherInflowToday        = 0;
    $otherInflowMonth        = 0;
    $ownerInflowRecords      = [];
    $ownerInflowTotals       = ['pending' => 0.0, 'approved' => 0.0, 'rejected' => 0.0];
    $todayLogs               = [];
    $hourlyData              = [];
    $exp_records             = [];
    $exp_total               = 0;
    $exp_filter              = 'all';
    $paymentBreakdown        = [];
    $cashRevenue = $onlineRevenue = $cashOrders = $onlineOrders = 0;
    $traffic_data            = getBusinessTrafficStats($conn, 0);
    $traffic_stats           = $traffic_data;
    extract($traffic_data, EXTR_SKIP);
}
?>
<script>
window.dashboardData = {
    revenue:      <?= json_encode((float)$todayRevenue) ?>,
    expenses:     <?= json_encode((float)$todayExpenses) ?>,
    net:          <?= json_encode((float)$netCashFlow) ?>,
    inflowToday:  <?= json_encode((float)$otherInflowToday) ?>,
    logs:         <?= json_encode($todayLogs,  JSON_UNESCAPED_SLASHES) ?>,
    hourly:       <?= json_encode($hourlyData, JSON_UNESCAPED_SLASHES) ?>,
    period:       <?= json_encode($fin_period) ?>,
    chartMode:    <?= json_encode($_chart_mode) ?>,
    monthDays:    <?= json_encode($fin_month_days) ?>,
    monthLabel:   <?= json_encode($fin_month_label) ?>,
    monthYear:    <?= json_encode($fin_month_year) ?>,
    monthNum:     <?= json_encode($fin_month_num) ?>
};
</script>
<?php

// -- Recent orders (last 10) --------------------------------------------------
$recent_orders = [];
if ($restaurant_id) {

// -- Approved menu items for Log Order panel ----------------------------------
$owner_log_order_menu = [];
if ($restaurant_id) {
    $olom = $conn->query("
        SELECT mi.id, mi.name, mi.price, mi.category, mi.serving_type
        FROM menu_items mi
        WHERE mi.restaurant_id = $restaurant_id
          AND " . MenuWorkflowService::customerEligibilitySql('mi') . "
          AND mi.is_available = 1
        ORDER BY mi.category ASC, mi.name ASC
    ");
    if ($olom) $owner_log_order_menu = $olom->fetch_all(MYSQLI_ASSOC);
}
    $ro = $conn->prepare("SELECT o.order_number, o.total_amount, o.order_status,
        o.payment_status, o.created_at, u.full_name AS customer_name
        FROM orders o JOIN users u ON o.customer_id = u.id
        WHERE o.restaurant_id = ? ORDER BY o.created_at DESC LIMIT 10");
    $ro->bind_param("i", $restaurant_id);
    $ro->execute();
    $recent_orders = $ro->get_result()->fetch_all(MYSQLI_ASSOC);
}

// -- Staff list ---------------------------------------------------------------
$staff_list = [];
if ($restaurant_id) {
    $sf = $conn->prepare("SELECT rs.id, rs.is_active, rs.assigned_at,
        u.full_name, u.email, u.avatar,
        rsr.role_key, rsr.role_label
        FROM restaurant_staff rs
        JOIN users u ON rs.user_id = u.id
        JOIN restaurant_staff_roles rsr ON rs.staff_role_id = rsr.id
        WHERE rs.restaurant_id = ? ORDER BY rsr.id ASC, u.full_name ASC");
    $sf->bind_param("i", $restaurant_id);
    $sf->execute();
    $staff_list = $sf->get_result()->fetch_all(MYSQLI_ASSOC);
}

// -- Users eligible for manual assignment
$eligible_users = [];
if ($restaurant_id) {
    $tbl_check = $conn->query("SHOW TABLES LIKE 'staff_applications'");
    if ($tbl_check && $tbl_check->num_rows > 0) {
        $eu = $conn->prepare("
            SELECT u.id, u.full_name, u.email,
                   sa.applied_role
            FROM staff_applications sa
            JOIN users u ON sa.user_id = u.id
            WHERE sa.restaurant_id = ?
              AND sa.status        = 'approved'
              AND u.is_admin       = 0
              AND u.id != ?
              AND u.id NOT IN (
                  SELECT user_id FROM restaurant_staff
                  WHERE restaurant_id = ? AND is_active = 1
              )
            ORDER BY u.full_name ASC
        ");
        $eu->bind_param("iii", $restaurant_id, $user_id, $restaurant_id);
        $eu->execute();
        $eligible_users = $eu->get_result()->fetch_all(MYSQLI_ASSOC);
    }
}

$staff_roles_list = $conn->query("SELECT * FROM restaurant_staff_roles ORDER BY id ASC")->fetch_all(MYSQLI_ASSOC);

require_once __DIR__ . '/../../includes/header.php';
?>

<!-- ══ PAGE HERO ════════════════════════════════════════════════════════════ -->
<div style="background:linear-gradient(135deg,var(--primary-900) 0%,var(--primary-700) 55%,#047857 100%);
            border-radius:var(--radius-xl);padding:28px 32px;margin-bottom:24px;
            position:relative;overflow:hidden;">
    <div style="position:absolute;right:-50px;top:-50px;width:200px;height:200px;border-radius:50%;background:rgba(255,255,255,.05);pointer-events:none;"></div>
    <div style="position:absolute;right:80px;bottom:-60px;width:140px;height:140px;border-radius:50%;background:rgba(255,255,255,.04);pointer-events:none;"></div>
    <div style="position:relative;display:flex;align-items:center;justify-content:space-between;flex-wrap:wrap;gap:16px;">
        <div style="display:flex;align-items:center;gap:16px;">
            <?php if (!empty($restaurant['image_path'])): ?>
            <img src="<?= BASE_URL ?>uploads/<?= htmlspecialchars($restaurant['image_path']) ?>"
                 style="width:60px;height:60px;border-radius:14px;object-fit:cover;border:2px solid rgba(255,255,255,.25);flex-shrink:0;">
            <?php else: ?>
            <div style="width:60px;height:60px;border-radius:14px;background:rgba(255,255,255,.15);
                        display:flex;align-items:center;justify-content:center;flex-shrink:0;">
                <i class="fas fa-store" style="color:#fff;font-size:1.5rem;"></i>
            </div>
            <?php endif; ?>
            <div>
                <div style="font-size:.72rem;font-weight:700;letter-spacing:.08em;text-transform:uppercase;color:#6ee7b7;margin-bottom:5px;">
                    <i class="fas fa-chart-line" style="margin-right:5px;"></i>Owner Dashboard
                </div>
                <h1 style="font-family:var(--font-display);font-size:1.45rem;font-weight:800;color:#fff;margin:0 0 4px;line-height:1.2;">
                    <?= htmlspecialchars($restaurant['restaurant_name'] ?? 'Your Restaurant') ?>
                </h1>
                <div style="font-size:.82rem;color:rgba(255,255,255,.7);display:flex;align-items:center;gap:10px;flex-wrap:wrap;">
                    <span><i class="fas fa-map-marker-alt" style="color:#f87171;margin-right:4px;"></i><?= htmlspecialchars($restaurant['address'] ?? '') ?></span>
                    <?php if (!empty($restaurant['operating_hours'])): ?>
                    <span><i class="fas fa-clock" style="color:#fbbf24;margin-right:4px;"></i><?= htmlspecialchars($restaurant['operating_hours']) ?></span>
                    <?php endif; ?>
                </div>
            </div>
        </div>
        <div style="display:flex;gap:8px;flex-wrap:wrap;">
            <a href="<?= BASE_URL ?>dashboard/business_owner/manage_orders.php"
               style="display:inline-flex;align-items:center;gap:8px;background:#fff;color:var(--primary-800);
                      padding:9px 18px;border-radius:var(--radius-md);font-weight:700;font-size:.85rem;
                      text-decoration:none;transition:opacity .15s;white-space:nowrap;"
               onmouseover="this.style.opacity='.88'" onmouseout="this.style.opacity='1'">
                <i class="fas fa-receipt"></i> Manage Orders
            </a>
            <a href="<?= BASE_URL ?>dashboard/business_owner/restaurant.php"
               style="display:inline-flex;align-items:center;gap:8px;
                      background:rgba(255,255,255,.12);border:1.5px solid rgba(255,255,255,.25);
                      color:#fff;padding:9px 18px;border-radius:var(--radius-md);font-weight:700;
                      font-size:.85rem;text-decoration:none;transition:background .15s;white-space:nowrap;"
               onmouseover="this.style.background='rgba(255,255,255,.22)'"
               onmouseout="this.style.background='rgba(255,255,255,.12)'">
                <i class="fas fa-store"></i> Edit Profile
            </a>
        </div>
    </div>
</div>

<!-- ── Tab Navigation Bar ──────────────────────────────────────────────────── -->
<div style="display:flex;gap:0;border-bottom:2px solid var(--neutral-200);margin-bottom:24px;background:#fff;padding:0 8px;border-radius:12px 12px 0 0;">
    <?php
    $owner_tabs = [
        ['key' => 'overview',          'icon' => 'fas fa-chart-pie',            'label' => 'Main Overview'],
        ['key' => 'financial_report',  'icon' => 'fas fa-file-invoice-dollar',  'label' => 'Financial Report & Analytics'],
        ['key' => 'business_visits',   'icon' => 'fas fa-chart-line',           'label' => 'Business Visits & Foot Traffic'],
        ['key' => 'demand_insights',   'icon' => 'fas fa-users',                'label' => 'Customer Purchase Intention'],
    ];
    foreach ($owner_tabs as $ot):
        $on = ($active_tab === $ot['key']);
    ?>
    <button type="button"
            class="owner-tab-btn<?= $on ? ' active' : '' ?>"
            data-tab="<?= $ot['key'] ?>"
            onclick="switchOwnerTab('<?= $ot['key'] ?>')"
            style="display:flex;align-items:center;gap:8px;padding:14px 24px;
                   border:none;border-bottom:3px solid <?= $on ? 'var(--primary-600)' : 'transparent' ?>;
                   background:transparent;cursor:pointer;font-size:.92rem;font-weight:700;
                   color:<?= $on ? 'var(--primary-600)' : 'var(--neutral-500)' ?>;
                   margin-bottom:-2px;transition:all .15s;white-space:nowrap;">
        <i class="<?= $ot['icon'] ?>"></i>
        <?= $ot['label'] ?>
    </button>
    <?php endforeach; ?>
</div>

<?php if (!$restaurant): ?>
<div class="card" style="max-width:600px;margin:0 auto;text-align:center;padding:48px 32px;">
    <div style="font-size:2.5rem;color:var(--neutral-300);margin-bottom:16px;"><i class="fas fa-store-slash"></i></div>
    <h3 style="color:var(--neutral-600);margin-bottom:8px;">Setting Up Your Restaurant</h3>
    <p style="color:var(--neutral-400);margin-bottom:24px;">
        Your restaurant profile is being set up. Please complete it before viewing analytics.
    </p>
    <a href="<?= BASE_URL ?>dashboard/business_owner/restaurant.php" class="btn btn-primary">
        <i class="fas fa-store"></i> Complete Restaurant Profile
    </a>
</div>
<?php else: ?>

<!-- ══ TAB 1: MAIN OVERVIEW (DEFAULT) ═══════════════════════════════════════ -->
<div id="tab-overview" class="owner-tab-panel" style="<?= $active_tab !== 'overview' ? 'display:none;' : '' ?>">


    <!-- HaCeRa Readiness Card - Dedicated Section -->
    <?php 
    $hrp_score = (float)($restaurant['hacera_hrp'] ?? 0);
    if ($hrp_score > 0):
        $hrp_band_data = [
            ['min' => 90, 'label' => 'Inspection Ready',  'bg' => '#dbeafe', 'color' => '#0369a1', 'border' => '#7dd3fc', 'icon' => 'fa-check-double'],
            ['min' => 70, 'label' => 'Near Ready',        'bg' => '#dcfce7', 'color' => '#15803d', 'border' => '#bbf7d0', 'icon' => 'fa-check'],
            ['min' => 40, 'label' => 'In Progress',       'bg' => '#fef3c7', 'color' => '#92400e', 'border' => '#fde68a', 'icon' => 'fa-hourglass-half'],
            ['min' => 0,  'label' => 'Just Started',      'bg' => '#fee2e2', 'color' => '#991b1b', 'border' => '#fecaca', 'icon' => 'fa-circle-exclamation'],
        ];
        $selected_band = $hrp_band_data[count($hrp_band_data) - 1];
        foreach ($hrp_band_data as $band) {
            if ($hrp_score >= $band['min']) {
                $selected_band = $band;
                break;
            }
        }
    ?>
    <div class="card" style="margin-bottom:24px;background:linear-gradient(135deg,<?= $selected_band['bg'] ?> 0%,<?= $selected_band['bg'] ?> 100%);border:2px solid <?= $selected_band['border'] ?>;overflow:hidden;">
        <div class="card-body" style="display:flex;align-items:center;justify-content:space-between;flex-wrap:wrap;gap:20px;padding:24px;color:<?= $selected_band['color'] ?>;">
            <div style="display:flex;align-items:center;gap:16px;flex:1;">
                <div style="width:60px;height:60px;border-radius:14px;background:rgba(255,255,255,.3);display:flex;align-items:center;justify-content:center;flex-shrink:0;">
                    <i class="fas <?= $selected_band['icon'] ?>" style="font-size:1.8rem;"></i>
                </div>
                <div style="flex:1;">
                    <div style="font-size:.75rem;font-weight:700;text-transform:uppercase;letter-spacing:.06em;opacity:.8;">HaCeRa Readiness Progress</div>
                    <div style="font-size:2rem;font-weight:900;margin:4px 0;line-height:1;"><?= number_format($hrp_score, 1) ?>%</div>
                    <div style="font-size:.95rem;font-weight:700;display:flex;align-items:center;gap:8px;">
                        <i class="fas fa-circle" style="font-size:.5rem;"></i>
                        <?= htmlspecialchars($selected_band['label']) ?>
                    </div>
                </div>
            </div>
            <div style="display:flex;flex-direction:column;align-items:flex-end;gap:8px;">
                <div style="font-size:.8rem;opacity:.85;">Last updated</div>
                <div style="font-size:.85rem;font-weight:600;"><?= $restaurant['hacera_published_at'] ? date('M j, Y', strtotime($restaurant['hacera_published_at'])) : 'Not published' ?></div>
                <a href="<?= BASE_URL ?>dashboard/business_owner/hacera.php"
                   style="margin-top:8px;background:<?= $selected_band['color'] ?>;color:#fff;padding:8px 16px;border-radius:8px;text-decoration:none;font-weight:700;font-size:.85rem;display:inline-flex;align-items:center;gap:6px;transition:opacity .15s;"
                   onmouseover="this.style.opacity='0.85'" onmouseout="this.style.opacity='1'">
                    <i class="fas fa-edit"></i> View Assessment
                </a>
            </div>
        </div>
        <!-- Progress Bar -->
        <div style="height:8px;background:rgba(255,255,255,.4);overflow:hidden;">
            <div style="height:100%;background:<?= $selected_band['color'] ?>;width:<?= $hrp_score ?>%;transition:width .5s ease;"></div>
        </div>
    </div>
    <?php endif; ?>

    <!-- Core Operational KPI Cards -->
    <div class="stats-grid" style="margin-bottom:24px;">
        <div class="card card-stat">
            <div class="stat-icon blue"><i class="fas fa-shopping-bag"></i></div>
            <div class="stat-value"><?= number_format($stats['total_orders']) ?></div>
            <div class="stat-label">Total Orders (Lifetime)</div>
        </div>
        <div class="card card-stat">
            <div class="stat-icon green"><i class="fas fa-peso-sign"></i></div>
            <div class="stat-value"><?= formatCurrency($stats['revenue_month']??0) ?></div>
            <div class="stat-label">Monthly Revenue (<?= date('F Y') ?>)</div>
        </div>
        <div class="card card-stat">
            <div class="stat-icon gold"><i class="fas fa-star"></i></div>
            <div class="stat-value"><?= number_format((float)($stats['avg_rating']??0),1) ?> / 5.0</div>
            <div class="stat-label">Average Rating (<?= number_format($stats['total_reviews']) ?> reviews)</div>
        </div>
        <div class="card card-stat <?= ($stats['pending_orders']>0)?'accent':'' ?>">
            <div class="stat-icon <?= ($stats['pending_orders']>0)?'gold':'green' ?>"><i class="fas fa-clock"></i></div>
            <div class="stat-value"><?= number_format($stats['pending_orders']) ?></div>
            <div class="stat-label">Pending Orders</div>
        </div>
        <div class="card card-stat">
            <div class="stat-icon blue"><i class="fas fa-users"></i></div>
            <div class="stat-value"><?= number_format($stats['total_staff']) ?></div>
            <div class="stat-label">Active Staff</div>
        </div>
    </div>

    <!-- HaCeRa Readiness Assessment Quick Access -->
    <?php if ($restaurant_id && empty($restaurant['certificate_number'])): ?>
    <div class="card" style="margin-bottom:20px;background:linear-gradient(135deg,#064e3b 0%,#065f46 60%,#047857 100%);border:none;overflow:hidden;">
        <div class="card-body" style="display:flex;align-items:center;justify-content:space-between;flex-wrap:wrap;gap:16px;padding:20px 24px;color:#fff;">
            <div style="flex:1;min-width:0;">
                <h3 style="margin:0 0 6px;font-size:1.1rem;font-weight:800;color:#fff;display:flex;align-items:center;gap:10px;">
                    <i class="fas fa-clipboard-check" style="font-size:1.3rem;"></i>
                    HaCeRa — Halal Readiness Assessment
                </h3>
                <p style="margin:0;font-size:.85rem;color:#a7f3d0;max-width:480px;">
                    Complete the Halal Readiness Assessment to track your compliance across 5 core modules.
                    <?php if ((float)($restaurant['hacera_hrp'] ?? 0) > 0): ?>
                    Your current readiness: <strong><?= number_format((float)$restaurant['hacera_hrp'], 1) ?>%</strong>
                    <?php endif; ?>
                </p>
            </div>
            <a href="<?= BASE_URL ?>dashboard/business_owner/hacera.php"
               style="display:flex;align-items:center;gap:8px;padding:10px 20px;background:#fff;color:#065f46;border-radius:10px;font-weight:700;font-size:.85rem;text-decoration:none;transition:opacity .15s;white-space:nowrap;"
               onmouseover="this.style.opacity='0.88'" onmouseout="this.style.opacity='1'">
                <i class="fas fa-arrow-right"></i> Start Assessment
            </a>
        </div>
    </div>
    <?php endif; ?>

    <!-- Pending Staff Alert -->
    <?php if ($pending_staff_count > 0): ?>
    <div class="alert" style="background:var(--accent-50);border:1px solid var(--accent-200);
         color:var(--accent-800);margin-bottom:20px;display:flex;align-items:center;gap:10px;">
        <i class="fas fa-user-clock" style="font-size:1.2rem;color:var(--accent-600);"></i>
        <div>
            <strong><?= $pending_staff_count ?> staff application<?= $pending_staff_count > 1 ? 's' : '' ?> waiting for your review.</strong>
            <span style="font-size:.875rem;margin-left:6px;">Review and assign roles to get your team started.</span>
        </div>
        <a href="<?= BASE_URL ?>dashboard/business_owner/staff_applications.php"
           class="btn btn-sm btn-accent" style="margin-left:auto;white-space:nowrap;">
            <i class="fas fa-inbox"></i> Review Now
        </a>
    </div>
    <?php endif; ?>

    <!-- Assign Approved Applicant Form -->
    <div class="card" style="margin-bottom:24px;">
        <div class="card-header">
            <h3><i class="fas fa-user-plus" style="color:var(--primary-600);margin-right:8px;"></i>Assign Approved Applicant</h3>
            <a href="<?= BASE_URL ?>dashboard/business_owner/staff_applications.php" class="btn btn-sm btn-outline">
                <i class="fas fa-inbox"></i> View All Applications
            </a>
        </div>
        <div class="card-body">
            <?php if (empty($eligible_users)): ?>
            <div style="text-align:center;padding:16px 0;color:var(--neutral-500);">
                <p style="font-size:.88rem;margin:0;">No approved applicants waiting to be assigned.</p>
            </div>
            <?php else: ?>
            <form id="assignStaffForm" style="display:flex;gap:12px;flex-wrap:wrap;align-items:flex-end;">
                <input type="hidden" name="action" value="assign">
                <input type="hidden" name="restaurant_id" value="<?= $restaurant_id ?>">
                <div class="form-group" style="flex:1;min-width:200px;margin:0;">
                    <label class="form-label">Approved Applicant</label>
                    <select name="user_id" class="form-control" required>
                        <option value="">— Select applicant —</option>
                        <?php foreach ($eligible_users as $eu):
                            $role_hint = $eu['applied_role'] === 'ops_manager' ? 'Ops Manager' : 'Cashier';
                        ?>
                        <option value="<?= $eu['id'] ?>">
                            <?= htmlspecialchars($eu['full_name']) ?> (<?= htmlspecialchars($eu['email']) ?> · applied as <?= $role_hint ?>)
                        </option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div class="form-group" style="min-width:180px;margin:0;">
                    <label class="form-label">Staff Role</label>
                    <select name="staff_role_key" class="form-control" required>
                        <option value="">— Select role —</option>
                        <?php foreach ($staff_roles_list as $sr): ?>
                        <option value="<?= htmlspecialchars($sr['role_key']) ?>">
                            <?= htmlspecialchars($sr['role_label']) ?>
                        </option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <button type="submit" class="btn btn-primary" style="white-space:nowrap;">
                    <i class="fas fa-plus"></i> Assign
                </button>
            </form>
            <?php endif; ?>
            <div id="assignResult" style="margin-top:10px;display:none;"></div>
        </div>
    </div>

    <!-- Current Staff Directory -->
    <div class="card" style="margin-bottom:24px;">
        <div class="card-header">
            <h3><i class="fas fa-users" style="color:var(--primary-600);margin-right:8px;"></i>
                Current Staff Directory
                <span class="badge badge-primary" style="margin-left:8px;"><?= count($staff_list) ?></span>
            </h3>
        </div>
        <div class="card-body" style="padding:0;">
            <?php if (empty($staff_list)): ?>
            <div class="empty-state" style="padding:36px;">
                <div class="empty-icon"><i class="fas fa-users-slash"></i></div>
                <h3>No Staff Assigned</h3>
                <p>Use the form above to assign staff members to this restaurant.</p>
            </div>
            <?php else: ?>
            <div class="table-responsive">
            <table class="table">
                <thead><tr>
                    <th>Name</th><th>Email</th><th>Role</th>
                    <th style="text-align:center;">Status</th>
                    <th>Assigned</th>
                    <th style="text-align:center;">Actions</th>
                </tr></thead>
                <tbody id="staffTableBody">
                <?php foreach ($staff_list as $sm): ?>
                <tr id="staff-row-<?= $sm['id'] ?>">
                    <td>
                        <div style="display:flex;align-items:center;gap:10px;">
                            <?php if (!empty($sm['avatar'])): ?>
                                <img src="<?= htmlspecialchars($sm['avatar']) ?>" style="width:34px;height:34px;border-radius:8px;object-fit:cover;">
                            <?php else: ?>
                                <div style="width:34px;height:34px;border-radius:8px;background:var(--primary-100);color:var(--primary-700);display:flex;align-items:center;justify-content:center;font-weight:700;">
                                    <?= strtoupper(substr($sm['full_name'],0,1)) ?>
                                </div>
                            <?php endif; ?>
                            <strong><?= htmlspecialchars($sm['full_name']) ?></strong>
                        </div>
                    </td>
                    <td style="color:var(--neutral-500);font-size:.85rem;"><?= htmlspecialchars($sm['email']) ?></td>
                    <td>
                        <?php
                        $roleColors = [
                            'owner'       => ['#ecfdf5','#065f46'],
                            'ops_manager' => ['#eff6ff','#1d4ed8'],
                            'cashier'     => ['#fff7ed','#9a3412'],
                        ];
                        [$bg,$col] = $roleColors[$sm['role_key']] ?? ['#f1f5f9','#475569'];
                        ?>
                        <span style="background:<?=$bg?>;color:<?=$col?>;padding:3px 10px;border-radius:20px;font-size:.75rem;font-weight:600;">
                            <?= htmlspecialchars($sm['role_label']) ?>
                        </span>
                    </td>
                    <td style="text-align:center;">
                        <?= $sm['is_active'] ? '<span class="badge badge-success">Active</span>' : '<span class="badge badge-secondary">Inactive</span>' ?>
                    </td>
                    <td style="font-size:.82rem;color:var(--neutral-400);"><?= formatDate($sm['assigned_at']) ?></td>
                    <td style="text-align:center;">
                        <div style="display:flex;gap:6px;justify-content:center;">
                            <button class="btn btn-sm <?= $sm['is_active']?'btn-outline':'btn-success' ?>" onclick="staffAction(<?=$sm['id']?>, '<?=$sm['is_active']?'deactivate':'activate'?>')" title="<?= $sm['is_active']?'Deactivate':'Activate' ?>">
                                <i class="fas fa-<?= $sm['is_active']?'pause':'play' ?>"></i>
                            </button>
                            <select class="form-control" style="width:auto;font-size:.8rem;padding:3px 6px;" onchange="staffAction(<?=$sm['id']?>, 'change_role', this.value)">
                                <?php foreach ($staff_roles_list as $sr): ?>
                                <option value="<?= htmlspecialchars($sr['role_key']) ?>" <?= $sr['role_key']===$sm['role_key']?'selected':'' ?>>
                                    <?= htmlspecialchars($sr['role_label']) ?>
                                </option>
                                <?php endforeach; ?>
                            </select>
                            <button class="btn btn-sm btn-danger" onclick="if(confirm('Remove this staff member?')) staffAction(<?=$sm['id']?>, 'remove')" title="Remove">
                                <i class="fas fa-trash"></i>
                            </button>
                        </div>
                    </td>
                </tr>
                <?php endforeach; ?>
                </tbody>
            </table>
            </div>
            <?php endif; ?>
        </div>
    </div>

    <!-- Recent Orders -->
    <div class="card">
        <div class="card-header" style="display:flex;align-items:center;justify-content:space-between;">
            <h3><i class="fas fa-receipt" style="color:var(--accent-500);margin-right:8px;"></i>Recent Orders</h3>
            <a href="<?= BASE_URL ?>dashboard/business_owner/manage_orders.php" style="font-size:.82rem;color:var(--primary-600);">View all →</a>
        </div>
        <div class="card-body" style="padding:0;">
            <?php if (empty($recent_orders)): ?>
            <div class="empty-state" style="padding:32px;">
                <div class="empty-icon"><i class="fas fa-receipt"></i></div>
                <p>No orders yet.</p>
            </div>
            <?php else: ?>
            <div class="table-responsive">
            <table class="table">
                <thead><tr>
                    <th>Order #</th><th>Customer</th><th>Total</th>
                    <th>Payment</th><th>Status</th><th>Date</th>
                </tr></thead>
                <tbody>
                <?php foreach ($recent_orders as $o): ?>
                <tr>
                    <td><strong><?= htmlspecialchars($o['order_number']) ?></strong></td>
                    <td><?= htmlspecialchars($o['customer_name']) ?></td>
                    <td><?= formatCurrency($o['total_amount']) ?></td>
                    <td><?= getStatusBadge($o['payment_status']) ?></td>
                    <td><?= getStatusBadge($o['order_status']) ?></td>
                    <td style="font-size:.82rem;color:var(--neutral-400);"><?= formatDateTime($o['created_at']) ?></td>
                </tr>
                <?php endforeach; ?>
                </tbody>
            </table>
            </div>
            <?php endif; ?>
        </div>
    </div>


</div><!-- /tab-overview -->


</div><!-- /tab-overview -->

<!-- ══ TAB 2: FINANCIAL REPORT & ANALYTICS ══════════════════════════════════ -->
<div id="tab-financial_report" class="owner-tab-panel" style="<?= $active_tab !== 'financial_report' ? 'display:none;' : '' ?>">

    <!-- ── Period Selector ─────────────────────────────────────────────────── -->
    <?php
    // Build a max-month value (current YYYY-MM) for the month input cap
    $fin_month_max = date('Y-m');
    ?>
    <div style="background:#fff;border:1px solid var(--neutral-200);border-radius:14px;
                padding:14px 18px;margin-bottom:24px;">

        <!-- Row 1: period pill buttons -->
        <div style="display:flex;align-items:center;flex-wrap:wrap;gap:10px;">
            <span style="font-size:.82rem;font-weight:700;color:var(--neutral-500);
                         display:flex;align-items:center;gap:6px;flex-shrink:0;">
                <i class="fas fa-filter" style="color:var(--primary-400);"></i>
                Reporting Period
            </span>
            <div style="display:flex;gap:6px;flex-wrap:wrap;flex:1;">
                <?php
                $pill_opts = [
                    'today' => ['Today',     'fas fa-sun'],
                    'week'  => ['This Week', 'fas fa-calendar-week'],
                    'month' => [$fin_period === 'month' ? $fin_month_label : 'By Month', 'fas fa-calendar-alt'],
                    'year'  => ['This Year', 'fas fa-calendar'],
                ];
                foreach ($pill_opts as $pkey => [$plabel, $picon]):
                    $is_ap = ($fin_period === $pkey);
                    $href  = finUrl($pkey, $fin_month);
                ?>
                <a href="<?= $href ?>"
                   style="display:inline-flex;align-items:center;gap:6px;padding:7px 16px;
                          border-radius:999px;font-size:.82rem;font-weight:700;text-decoration:none;
                          border:1.5px solid <?= $is_ap ? 'var(--primary-600)' : 'var(--neutral-200)' ?>;
                          background:<?= $is_ap ? 'var(--primary-600)' : '#fff' ?>;
                          color:<?= $is_ap ? '#fff' : 'var(--neutral-500)' ?>;
                          transition:all .15s;">
                    <i class="<?= $picon ?>"></i> <?= $plabel ?>
                </a>
                <?php endforeach; ?>
            </div>
            <div style="font-size:.78rem;color:var(--neutral-400);flex-shrink:0;">
                <i class="fas fa-chart-bar" style="color:var(--primary-300);margin-right:3px;"></i>
                Showing: <strong style="color:var(--primary-700);"><?= htmlspecialchars($fin_label) ?></strong>
            </div>
        </div>

        <!-- Row 2: Month picker — only shown when period = month -->
        <?php if ($fin_period === 'month'): ?>
        <div style="margin-top:12px;padding-top:12px;border-top:1px solid var(--neutral-100);
                    display:flex;align-items:center;flex-wrap:wrap;gap:10px;">
            <span style="font-size:.8rem;font-weight:700;color:var(--primary-700);">
                <i class="fas fa-calendar-days" style="margin-right:4px;"></i>Select Month:
            </span>
            <!-- Native month input: type="month" gives a YYYY-MM picker in all modern browsers -->
            <form method="GET" style="display:flex;align-items:center;gap:8px;flex-wrap:wrap;">
                <input type="hidden" name="tab"        value="financial_report">
                <input type="hidden" name="fin_period" value="month">
                <input type="month"
                       name="fin_month"
                       value="<?= htmlspecialchars($fin_month) ?>"
                       min="2020-01"
                       max="<?= $fin_month_max ?>"
                       style="padding:6px 10px;border:1.5px solid var(--primary-300);
                              border-radius:8px;font-size:.85rem;font-weight:600;
                              color:var(--primary-800);background:#eff6ff;outline:none;cursor:pointer;"
                       onchange="this.form.submit()">
                <button type="submit"
                        style="display:inline-flex;align-items:center;gap:5px;padding:6px 14px;
                               background:var(--primary-600);color:#fff;border:none;border-radius:8px;
                               font-size:.82rem;font-weight:700;cursor:pointer;">
                    <i class="fas fa-arrow-right"></i> View
                </button>
            </form>
            <!-- Quick-jump: previous / next month -->
            <?php
            $prev_ts    = mktime(0,0,0,$fin_month_num - 1, 1, $fin_month_year);
            $next_ts    = mktime(0,0,0,$fin_month_num + 1, 1, $fin_month_year);
            $prev_month = date('Y-m', $prev_ts);
            $next_month = date('Y-m', $next_ts);
            $can_next   = $next_ts <= mktime(0,0,0,date('n'),1,date('Y'));
            ?>
            <a href="<?= finUrl('month', $prev_month) ?>"
               style="display:inline-flex;align-items:center;gap:4px;padding:6px 12px;
                      border:1.5px solid var(--neutral-200);border-radius:8px;font-size:.8rem;
                      font-weight:700;color:var(--neutral-600);text-decoration:none;background:#fff;
                      transition:background .12s;"
               onmouseover="this.style.background='#f1f5f9'" onmouseout="this.style.background='#fff'">
                <i class="fas fa-chevron-left"></i> <?= date('M Y', $prev_ts) ?>
            </a>
            <?php if ($can_next): ?>
            <a href="<?= finUrl('month', $next_month) ?>"
               style="display:inline-flex;align-items:center;gap:4px;padding:6px 12px;
                      border:1.5px solid var(--neutral-200);border-radius:8px;font-size:.8rem;
                      font-weight:700;color:var(--neutral-600);text-decoration:none;background:#fff;
                      transition:background .12s;"
               onmouseover="this.style.background='#f1f5f9'" onmouseout="this.style.background='#fff'">
                <?= date('M Y', $next_ts) ?> <i class="fas fa-chevron-right"></i>
            </a>
            <?php else: ?>
            <span style="padding:6px 12px;border:1.5px solid var(--neutral-100);border-radius:8px;
                         font-size:.8rem;font-weight:700;color:var(--neutral-300);">
                <?= date('M Y', $next_ts) ?> <i class="fas fa-chevron-right"></i>
            </span>
            <?php endif; ?>
        </div>
        <?php endif; ?>
    </div>

    <style>
    .fin-kpi-grid{display:grid;grid-template-columns:repeat(auto-fit,minmax(240px,1fr));gap:18px;margin-bottom:24px;}
    .fin-kpi-card{background:linear-gradient(135deg,#fff 60%,#f8fafc);border:1px solid #e2e8f0;border-radius:16px;
        padding:22px 24px;position:relative;overflow:hidden;transition:box-shadow .2s,transform .2s;}
    .fin-kpi-card:hover{box-shadow:0 8px 28px rgba(0,0,0,.10);transform:translateY(-2px);}
    .fin-kpi-card::before{content:'';position:absolute;top:0;left:0;right:0;height:4px;border-radius:16px 16px 0 0;}
    .fin-kpi-card.revenue::before{background:linear-gradient(90deg,#22c55e,#16a34a);}
    .fin-kpi-card.expense::before{background:linear-gradient(90deg,#f97316,#ea580c);}
    .fin-kpi-card.net::before{background:linear-gradient(90deg,#6366f1,#4f46e5);}
    .fin-kpi-icon{width:46px;height:46px;border-radius:12px;display:flex;align-items:center;justify-content:center;
        font-size:1.2rem;margin-bottom:14px;}
    .fin-kpi-icon.rev{background:#dcfce7;color:#16a34a;}
    .fin-kpi-icon.exp{background:#ffedd5;color:#ea580c;}
    .fin-kpi-icon.net-pos{background:#e0e7ff;color:#4f46e5;}
    .fin-kpi-icon.net-neg{background:#fee2e2;color:#dc2626;}
    .fin-kpi-label{font-size:.75rem;font-weight:600;letter-spacing:.06em;text-transform:uppercase;
        color:#94a3b8;margin-bottom:4px;}
    .fin-kpi-value{font-size:1.85rem;font-weight:800;letter-spacing:-.02em;color:#0f172a;line-height:1;}
    .fin-kpi-sub{font-size:.78rem;color:#94a3b8;margin-top:6px;}
    .fin-kpi-btn{margin-top:14px;font-size:.75rem;font-weight:600;padding:5px 12px;border-radius:8px;
        border:1.5px solid currentColor;background:transparent;cursor:pointer;transition:background .15s;}
    .fin-kpi-card.revenue .fin-kpi-btn{color:#16a34a;}
    .fin-kpi-card.revenue .fin-kpi-btn:hover{background:#dcfce7;}
    .fin-kpi-card.expense .fin-kpi-btn{color:#ea580c;}
    .fin-kpi-card.expense .fin-kpi-btn:hover{background:#ffedd5;}
    .fin-kpi-card.net .fin-kpi-btn{color:#4f46e5;}
    .fin-kpi-card.net .fin-kpi-btn:hover{background:#e0e7ff;}
    /* Modal */
    .fin-modal-overlay{display:none;position:fixed;inset:0;background:rgba(15,23,42,.45);z-index:9999;
        align-items:center;justify-content:center;}
    .fin-modal-overlay.open{display:flex;}
    .fin-modal{background:#fff;border-radius:20px;width:min(640px,96vw);max-height:80vh;
        display:flex;flex-direction:column;box-shadow:0 24px 60px rgba(0,0,0,.18);overflow:hidden;}
    .fin-modal-hdr{padding:22px 24px 16px;border-bottom:1px solid #e2e8f0;display:flex;align-items:center;gap:12px;}
    .fin-modal-hdr h4{flex:1;margin:0;font-size:1.05rem;font-weight:700;color:#0f172a;}
    .fin-modal-close{background:none;border:none;font-size:1.3rem;cursor:pointer;color:#64748b;
        width:34px;height:34px;border-radius:8px;display:flex;align-items:center;justify-content:center;}
    .fin-modal-close:hover{background:#f1f5f9;}
    .fin-modal-body{padding:20px 24px;overflow-y:auto;flex:1;}
    .fin-formula{background:#f8fafc;border:1px solid #e2e8f0;border-radius:12px;padding:14px 18px;
        font-family:monospace;font-size:.85rem;color:#1e293b;margin-bottom:20px;line-height:1.7;}
    .fin-formula span.var{color:#4f46e5;font-weight:700;}
    .fin-formula span.eq{color:#94a3b8;}
    .fin-txn-list{list-style:none;padding:0;margin:0;}
    .fin-txn-list li{display:flex;align-items:center;gap:10px;padding:9px 0;
        border-bottom:1px solid #f1f5f9;font-size:.84rem;}
    .fin-txn-list li:last-child{border-bottom:none;}
    .fin-txn-type{font-size:.7rem;font-weight:700;padding:3px 8px;border-radius:6px;white-space:nowrap;}
    .fin-txn-type.order{background:#dcfce7;color:#15803d;}
    .fin-txn-type.payment{background:#ffedd5;color:#c2410c;}
    .fin-txn-type.delivery{background:#e0e7ff;color:#4338ca;}
    .fin-txn-type.expense{background:#fee2e2;color:#b91c1c;}
    .fin-txn-type.inflow{background:#ede9fe;color:#6d28d9;}
    .fin-txn-desc{flex:1;color:#334155;}
    .fin-txn-ts{font-size:.75rem;color:#94a3b8;white-space:nowrap;}
    .fin-txn-amt{font-weight:700;white-space:nowrap;}
    .fin-txn-amt.credit{color:#16a34a;}
    .fin-txn-amt.debit{color:#dc2626;}
    /* Chart area */
    .fin-chart-card{background:#fff;border:1px solid #e2e8f0;border-radius:16px;padding:22px 24px;
        margin-bottom:24px;}
    .fin-chart-card h4{margin:0 0 18px;font-size:.95rem;font-weight:700;color:#0f172a;display:flex;
        align-items:center;gap:8px;}
    .exp-form-hidden { display:none !important; }
    </style>

    <?php
    $todayRevenueFmt  = '&#8369;' . number_format($todayRevenue, 2);
    $todayExpensesFmt = '&#8369;' . number_format($todayExpenses, 2);
    $netCashFlowFmt   = '&#8369;' . number_format(abs($netCashFlow), 2);
    $netIsNeg         = $netCashFlow < 0;
    $inflowTodayFmt   = '&#8369;' . number_format($otherInflowToday, 2);
    $orderRevToday    = $todayRevenue - $otherInflowToday;
    ?>

    <?php if (!empty($exp_error)): ?>
    <div class="alert alert-danger"><i class="fas fa-exclamation-circle"></i> <?= htmlspecialchars($exp_error) ?></div>
    <?php endif; ?>
    <?php if (!empty($exp_success)): ?>
    <div class="alert alert-success"><i class="fas fa-check-circle"></i> <?= htmlspecialchars($exp_success) ?></div>
    <?php endif; ?>

    <!-- Financial KPI Grid -->
    <div class="fin-kpi-grid">
        <!-- Revenue card -->
        <div class="fin-kpi-card revenue">
            <div class="fin-kpi-icon rev"><i class="fas fa-arrow-trend-up"></i></div>
            <div class="fin-kpi-label"><?= $fin_label ?> Revenue</div>
            <div class="fin-kpi-value" id="finRevValue"><?= $todayRevenueFmt ?></div>
            <div class="fin-kpi-sub">
                Orders: &#8369;<?= number_format($orderRevToday, 2) ?>
                <?php if ($otherInflowToday > 0): ?>
                &nbsp;+&nbsp;Inflows: &#8369;<?= number_format($otherInflowToday, 2) ?>
                <?php endif; ?>
            </div>
            <button class="fin-kpi-btn" onclick="openFinModal('revenue')"><i class="fas fa-calculator"></i> View Breakdown</button>
        </div>
        <!-- Expenses card -->
        <div class="fin-kpi-card expense">
            <div class="fin-kpi-icon exp"><i class="fas fa-arrow-trend-down"></i></div>
            <div class="fin-kpi-label"><?= $fin_label ?> Expenses</div>
            <div class="fin-kpi-value" id="finExpValue"><?= $todayExpensesFmt ?></div>
            <div class="fin-kpi-sub">Delivery fees (&#8369;<?= number_format($deliveryFeeExpenseToday, 2) ?>) + Op Costs (&#8369;<?= number_format($opExpensesToday, 2) ?>)</div>
            <button class="fin-kpi-btn" onclick="openFinModal('expense')"><i class="fas fa-calculator"></i> View Breakdown</button>
        </div>
        <!-- Net Cash Flow card -->
        <div class="fin-kpi-card net">
            <div class="fin-kpi-icon <?= $netIsNeg ? 'net-neg' : 'net-pos' ?>">
                <i class="fas fa-<?= $netIsNeg ? 'minus-circle' : 'check-circle' ?>"></i>
            </div>
            <div class="fin-kpi-label"><?= $fin_label ?> Net Cash Flow</div>
            <div class="fin-kpi-value" style="color:<?= $netIsNeg ? '#dc2626' : '#4f46e5' ?>">
                <?= ($netIsNeg ? '&minus;' : '+') . $netCashFlowFmt ?>
            </div>
            <div class="fin-kpi-sub">Revenue &minus; Expenses</div>
            <button class="fin-kpi-btn" onclick="openFinModal('net')"><i class="fas fa-calculator"></i> View Breakdown</button>
        </div>
        <!-- Other Inflows card -->
        <div class="fin-kpi-card" style="border-top:none;">
            <div style="position:absolute;top:0;left:0;right:0;height:4px;border-radius:16px 16px 0 0;background:linear-gradient(90deg,#7c3aed,#4f46e5);"></div>
            <div class="fin-kpi-icon" style="background:#ede9fe;color:#7c3aed;"><i class="fas fa-hand-holding-usd"></i></div>
            <div class="fin-kpi-label" style="color:#7c3aed;"><?= $fin_label ?> Other Inflows</div>
            <div class="fin-kpi-value" style="color:#5b21b6;"><?= $inflowTodayFmt ?></div>
            <div class="fin-kpi-sub">
                Approved (all-time): &#8369;<?= number_format($ownerInflowTotals['approved'], 2) ?>
                &nbsp;&middot;&nbsp;Pending: &#8369;<?= number_format($ownerInflowTotals['pending'], 2) ?>
            </div>
            <button class="fin-kpi-btn" style="color:#7c3aed;" onclick="openFinModal('inflow')"><i class="fas fa-calculator"></i> View Breakdown</button>
        </div>
    </div>

    <!-- Payment Method Breakdown -->
    <?php if (!empty($paymentBreakdown)): ?>
    <div class="card" style="margin-bottom:24px;">
        <div class="card-header" style="background:linear-gradient(135deg,#6366f1,#4f46e5);color:#fff;padding:14px 18px;border-radius:12px 12px 0 0;">
            <h3 style="margin:0;font-size:1rem;font-weight:700;display:flex;align-items:center;gap:8px;">
                <i class="fas fa-wallet"></i>
                <span>Payment Method Breakdown — <?= $fin_label ?></span>
            </h3>
        </div>
        <div class="card-body" style="padding:20px;">
            <!-- Summary Stats -->
            <div style="display:grid;grid-template-columns:repeat(auto-fit,minmax(200px,1fr));gap:16px;margin-bottom:20px;">
                <div style="background:#dcfce7;border-left:4px solid #16a34a;padding:14px;border-radius:8px;">
                    <div style="font-size:0.8rem;color:#15803d;font-weight:600;margin-bottom:4px;">💵 Cash Payments</div>
                    <div style="font-size:1.4rem;font-weight:800;color:#166534;">₱<?= number_format($cashRevenue, 2) ?></div>
                    <div style="font-size:0.75rem;color:#166534;margin-top:2px;"><?= $cashOrders ?> order<?= $cashOrders !== 1 ? 's' : '' ?></div>
                </div>
                <div style="background:#dbeafe;border-left:4px solid #3b82f6;padding:14px;border-radius:8px;">
                    <div style="font-size:0.8rem;color:#1e40af;font-weight:600;margin-bottom:4px;">📱 Online Payments</div>
                    <div style="font-size:1.4rem;font-weight:800;color:#1e3a8a;">₱<?= number_format($onlineRevenue, 2) ?></div>
                    <div style="font-size:0.75rem;color:#1e3a8a;margin-top:2px;"><?= $onlineOrders ?> order<?= $onlineOrders !== 1 ? 's' : '' ?></div>
                </div>
            </div>
            
            <!-- Detailed Breakdown Table -->
            <table class="table" style="font-size:0.88rem;">
                <thead>
                    <tr style="background:#f8fafc;border-bottom:2px solid #e2e8f0;">
                        <th style="padding:10px;font-weight:700;">Payment Method</th>
                        <th style="padding:10px;font-weight:700;text-align:center;">Orders</th>
                        <th style="padding:10px;font-weight:700;text-align:right;">Revenue</th>
                        <th style="padding:10px;font-weight:700;text-align:right;">Percentage</th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($paymentBreakdown as $pm): 
                        $percentage = $todayRevenue > 0 ? ($pm['revenue'] / $todayRevenue * 100) : 0;
                        $methodIcon = match($pm['method']) {
                            'cash' => '💵',
                            'gcash' => '📱',
                            'paymongo' => '💳',
                            'bank_transfer' => '🏦',
                            default => '💰'
                        };
                        $methodLabel = ucfirst(str_replace('_', ' ', $pm['method']));
                    ?>
                    <tr style="border-bottom:1px solid #f1f5f9;">
                        <td style="padding:10px;">
                            <span style="font-size:1.2rem;margin-right:6px;"><?= $methodIcon ?></span>
                            <strong><?= $methodLabel ?></strong>
                        </td>
                        <td style="padding:10px;text-align:center;"><?= $pm['count'] ?></td>
                        <td style="padding:10px;text-align:right;font-weight:700;color:var(--primary-700);">₱<?= number_format($pm['revenue'], 2) ?></td>
                        <td style="padding:10px;text-align:right;">
                            <div style="display:flex;align-items:center;justify-content:flex-end;gap:8px;">
                                <div style="flex:0 0 100px;background:#e2e8f0;border-radius:8px;height:8px;overflow:hidden;">
                                    <div style="background:linear-gradient(90deg,#6366f1,#4f46e5);height:100%;width:<?= number_format($percentage, 1) ?>%;transition:width .3s;"></div>
                                </div>
                                <span style="font-weight:600;min-width:45px;text-align:right;"><?= number_format($percentage, 1) ?>%</span>
                            </div>
                        </td>
                    </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    </div>
    <?php endif; ?>

    <!-- ══ Log a Past Order ══════════════════════════════════════════════════ -->
    <div class="card" style="margin-bottom:24px;border:2px solid #bfdbfe;border-radius:16px;">
        <div class="card-header"
             style="background:linear-gradient(135deg,#1d4ed8,#4f46e5);color:#fff;
                    padding:14px 20px;border-radius:14px 14px 0 0;
                    display:flex;align-items:center;justify-content:space-between;flex-wrap:wrap;gap:10px;">
            <div>
                <h3 style="margin:0;font-size:1rem;font-weight:700;display:flex;align-items:center;gap:8px;">
                    <i class="fas fa-calendar-plus"></i> Log a Past Order
                </h3>
                <div style="font-size:.75rem;opacity:.8;margin-top:3px;">
                    Record orders made on a specific date — appears in the correct financial period automatically
                </div>
            </div>
            <button type="button"
                    onclick="document.getElementById('ownerLoForm').classList.toggle('exp-form-hidden')"
                    style="display:inline-flex;align-items:center;gap:6px;background:rgba(255,255,255,.18);
                           border:1.5px solid rgba(255,255,255,.35);color:#fff;padding:7px 14px;
                           border-radius:8px;font-size:.8rem;font-weight:700;cursor:pointer;transition:background .15s;"
                    onmouseover="this.style.background='rgba(255,255,255,.28)'"
                    onmouseout="this.style.background='rgba(255,255,255,.18)'">
                <i class="fas fa-plus-circle"></i> New Entry
            </button>
        </div>

        <div id="ownerLoForm" class="exp-form-hidden" style="padding:22px;">

            <div id="owner-lo-toast" style="display:none;padding:11px 16px;border-radius:9px;
                 margin-bottom:16px;font-size:.87rem;font-weight:600;"></div>

            <?php if (empty($owner_log_order_menu)): ?>
            <div style="text-align:center;padding:32px;color:var(--neutral-400);">
                <i class="fas fa-utensils" style="font-size:2rem;display:block;margin-bottom:8px;opacity:.4;"></i>
                No approved menu items yet. Items must be approved by the Operations Manager first.
            </div>
            <?php else: ?>

            <div style="display:grid;grid-template-columns:1fr 1.7fr;gap:20px;align-items:start;">

            <!-- Left: details -->
            <div style="display:flex;flex-direction:column;gap:12px;">
                <div class="form-group" style="margin:0;">
                    <label class="form-label" style="font-size:.78rem;">
                        <i class="fas fa-calendar-day" style="color:var(--primary-500);margin-right:4px;"></i>
                        Order Date <span style="color:#ef4444;">*</span>
                    </label>
                    <input type="date" id="olo-date" class="form-control"
                           value="<?= date('Y-m-d') ?>" max="<?= date('Y-m-d') ?>">
                </div>
                <div class="form-group" style="margin:0;">
                    <label class="form-label" style="font-size:.78rem;">
                        <i class="fas fa-clock" style="color:var(--primary-500);margin-right:4px;"></i>
                        Time
                    </label>
                    <input type="time" id="olo-time" class="form-control" value="12:00">
                </div>
                <div class="form-group" style="margin:0;">
                    <label class="form-label" style="font-size:.78rem;">
                        <i class="fas fa-concierge-bell" style="color:var(--primary-500);margin-right:4px;"></i>
                        Service Type <span style="color:#ef4444;">*</span>
                    </label>
                    <select id="olo-service" class="form-control" style="font-size:.85rem;">
                        <option value="dine_in">Dine-in</option>
                        <option value="takeout">Takeout</option>
                        <option value="delivery">Delivery</option>
                    </select>
                </div>
                <div class="form-group" style="margin:0;">
                    <label class="form-label" style="font-size:.78rem;">
                        <i class="fas fa-wallet" style="color:var(--primary-500);margin-right:4px;"></i>
                        Payment Method <span style="color:#ef4444;">*</span>
                    </label>
                    <select id="olo-payment" class="form-control" style="font-size:.85rem;">
                        <option value="cash">Cash</option>
                        <option value="gcash">GCash</option>
                        <option value="bank_transfer">Bank Transfer</option>
                    </select>
                </div>
                <div class="form-group" style="margin:0;">
                    <label class="form-label" style="font-size:.78rem;">
                        <i class="fas fa-user" style="color:var(--primary-500);margin-right:4px;"></i>
                        Customer Name <span style="color:var(--neutral-400);font-weight:400;">(optional)</span>
                    </label>
                    <input type="text" id="olo-customer" class="form-control" style="font-size:.85rem;"
                           placeholder="Walk-in / reference" maxlength="255">
                </div>
                <div class="form-group" style="margin:0;">
                    <label class="form-label" style="font-size:.78rem;">
                        <i class="fas fa-sticky-note" style="color:var(--primary-500);margin-right:4px;"></i>
                        Notes <span style="color:var(--neutral-400);font-weight:400;">(optional)</span>
                    </label>
                    <textarea id="olo-notes" class="form-control" rows="2"
                              style="font-size:.85rem;resize:vertical;" maxlength="500"></textarea>
                </div>
            </div>

            <!-- Right: menu picker + basket -->
            <div style="display:flex;flex-direction:column;gap:14px;">

                <!-- Menu picker -->
                <div style="border:1.5px solid #d1fae5;border-radius:12px;overflow:hidden;">
                    <div style="background:#f0fdf4;padding:11px 16px;display:flex;
                                align-items:center;justify-content:space-between;">
                        <span style="font-size:.85rem;font-weight:700;color:#15803d;">
                            <i class="fas fa-utensils" style="margin-right:6px;"></i>Choose Menu Items
                        </span>
                        <input type="text" id="olo-search" placeholder="Search…"
                               style="padding:4px 9px;border:1px solid #86efac;border-radius:6px;
                                      font-size:.78rem;outline:none;width:130px;">
                    </div>
                    <div style="max-height:300px;overflow-y:auto;padding:8px 12px;" id="olo-menu-list">
                        <?php
                        $olast_cat = '';
                        foreach ($owner_log_order_menu as $omi):
                            if ($omi['category'] !== $olast_cat):
                                $olast_cat = $omi['category'];
                        ?>
                        <div style="font-size:.68rem;font-weight:800;text-transform:uppercase;
                                    letter-spacing:.06em;color:var(--neutral-400);
                                    margin:8px 0 4px;border-top:1px solid var(--neutral-100);padding-top:4px;">
                            <?= htmlspecialchars($omi['category']) ?>
                        </div>
                        <?php endif; ?>
                        <div class="olo-menu-row"
                             data-id="<?= $omi['id'] ?>"
                             data-name="<?= htmlspecialchars($omi['name'], ENT_QUOTES) ?>"
                             data-price="<?= (float)$omi['price'] ?>"
                             data-search="<?= htmlspecialchars(strtolower($omi['name'] . ' ' . $omi['category']), ENT_QUOTES) ?>"
                             style="display:flex;align-items:center;justify-content:space-between;
                                    padding:7px 9px;border-radius:7px;cursor:pointer;margin-bottom:3px;
                                    border:1.5px solid transparent;transition:all .1s;"
                             onmouseenter="this.style.background='#f0fdf4';this.style.borderColor='#86efac';"
                             onmouseleave="this.style.background='';this.style.borderColor='transparent';"
                             onclick="oloAdd(this)">
                            <span style="font-size:.84rem;font-weight:600;color:var(--neutral-800);">
                                <?= htmlspecialchars($omi['name']) ?>
                            </span>
                            <span style="font-size:.84rem;font-weight:800;color:#16a34a;white-space:nowrap;margin-left:8px;">
                                &#8369;<?= number_format((float)$omi['price'], 2) ?>
                                <i class="fas fa-plus-circle" style="color:#22c55e;font-size:.72rem;margin-left:3px;"></i>
                            </span>
                        </div>
                        <?php endforeach; ?>
                    </div>
                </div>

                <!-- Basket -->
                <div style="border:1.5px solid #c7d2fe;border-radius:12px;overflow:hidden;">
                    <div style="background:#eef2ff;padding:11px 16px;display:flex;
                                align-items:center;justify-content:space-between;">
                        <span style="font-size:.85rem;font-weight:700;color:#4338ca;">
                            <i class="fas fa-shopping-basket" style="margin-right:6px;"></i>Basket
                        </span>
                        <span id="olo-count" style="background:#c7d2fe;color:#3730a3;border-radius:999px;
                                                    padding:2px 9px;font-size:.73rem;font-weight:700;">0 items</span>
                    </div>
                    <div id="olo-basket" style="padding:10px 14px;min-height:50px;">
                        <div id="olo-basket-empty" style="text-align:center;padding:14px;
                             color:var(--neutral-300);font-size:.82rem;">
                            Click items above to add them
                        </div>
                    </div>
                    <div style="border-top:1.5px solid #c7d2fe;padding:10px 16px;
                                display:flex;align-items:center;justify-content:space-between;gap:10px;">
                        <div>
                            <div style="font-size:.72rem;color:var(--neutral-500);">Total</div>
                            <div id="olo-total" style="font-size:1.25rem;font-weight:900;color:#4338ca;">&#8369;0.00</div>
                        </div>
                        <button id="olo-submit-btn" onclick="oloSubmit()"
                                style="display:inline-flex;align-items:center;gap:7px;padding:9px 20px;
                                       background:linear-gradient(135deg,#2563eb,#4f46e5);color:#fff;border:none;
                                       border-radius:9px;font-size:.875rem;font-weight:700;cursor:pointer;
                                       box-shadow:0 2px 8px rgba(79,70,229,.3);">
                            <i class="fas fa-save"></i> Save Order
                        </button>
                    </div>
                </div>

            </div>
            </div><!-- /grid -->
            <?php endif; ?>
        </div><!-- /ownerLoForm -->
    </div><!-- /Log a Past Order card -->

    <!-- Other Inflows Panel (Operations Manager logged inflows visible to owner) -->
    <div class="card" style="margin-bottom:24px;">
        <div class="card-header" style="background:linear-gradient(135deg,#4f46e5,#7c3aed);color:#fff;
                                        padding:14px 20px;border-radius:12px 12px 0 0;
                                        display:flex;align-items:center;justify-content:space-between;flex-wrap:wrap;gap:10px;">
            <div>
                <h3 style="margin:0;font-size:1rem;font-weight:700;display:flex;align-items:center;gap:8px;">
                    <i class="fas fa-hand-holding-usd"></i> Other Revenue Inflows
                </h3>
                <div style="font-size:.75rem;opacity:.8;margin-top:3px;">
                    Catering orders, grants, sponsorships &amp; other non-order income logged by Operations Manager
                </div>
            </div>
            <a href="<?= BASE_URL ?>dashboard/business_owner/operations_manager.php?tab=other_inflows"
               style="display:inline-flex;align-items:center;gap:6px;background:rgba(255,255,255,.18);
                      border:1.5px solid rgba(255,255,255,.35);color:#fff;padding:7px 14px;
                      border-radius:8px;font-size:.8rem;font-weight:700;text-decoration:none;
                      transition:background .15s;"
               onmouseover="this.style.background='rgba(255,255,255,.28)'"
               onmouseout="this.style.background='rgba(255,255,255,.18)'">
                <i class="fas fa-external-link-alt"></i> Manage Inflows
            </a>
        </div>
        <div class="card-body" style="padding:20px;">

            <!-- Summary stat strip -->
            <div style="display:grid;grid-template-columns:repeat(auto-fit,minmax(160px,1fr));gap:14px;margin-bottom:20px;">
                <div style="background:linear-gradient(135deg,#4f46e5,#7c3aed);color:#fff;border-radius:12px;padding:16px;">
                    <div style="font-size:.7rem;font-weight:700;text-transform:uppercase;letter-spacing:.05em;opacity:.8;margin-bottom:4px;">
                        <i class="fas fa-check-circle"></i>&nbsp; Approved — <?= htmlspecialchars($fin_label) ?>
                    </div>
                    <div style="font-size:1.4rem;font-weight:800;">&#8369;<?= number_format($ownerInflowTotals['approved'], 2) ?></div>
                    <div style="font-size:.7rem;opacity:.7;margin-top:3px;">Counts in <?= htmlspecialchars($fin_label) ?> revenue</div>
                </div>
                <div style="background:#fef9c3;border:1px solid #fde047;border-radius:12px;padding:16px;">
                    <div style="font-size:.7rem;font-weight:700;color:#854d0e;text-transform:uppercase;letter-spacing:.05em;margin-bottom:4px;">
                        <i class="fas fa-hourglass-half"></i>&nbsp; Pending — <?= htmlspecialchars($fin_label) ?>
                    </div>
                    <div style="font-size:1.4rem;font-weight:800;color:#78350f;">&#8369;<?= number_format($ownerInflowTotals['pending'], 2) ?></div>
                    <div style="font-size:.7rem;color:#92400e;margin-top:3px;">Not yet in revenue</div>
                </div>
                <div style="background:#fee2e2;border:1px solid #fca5a5;border-radius:12px;padding:16px;">
                    <div style="font-size:.7rem;font-weight:700;color:#991b1c;text-transform:uppercase;letter-spacing:.05em;margin-bottom:4px;">
                        <i class="fas fa-times-circle"></i>&nbsp; Rejected — <?= htmlspecialchars($fin_label) ?>
                    </div>
                    <div style="font-size:1.4rem;font-weight:800;color:#7f1d1d;">&#8369;<?= number_format($ownerInflowTotals['rejected'], 2) ?></div>
                </div>
                <div style="background:#f0fdf4;border:1px solid #bbf7d0;border-radius:12px;padding:16px;">
                    <div style="font-size:.7rem;font-weight:700;color:#15803d;text-transform:uppercase;letter-spacing:.05em;margin-bottom:4px;">
                        <i class="fas fa-layer-group"></i>&nbsp; Total — <?= htmlspecialchars($fin_label) ?>
                    </div>
                    <div style="font-size:1.4rem;font-weight:800;color:#14532d;">
                        &#8369;<?= number_format(array_sum($ownerInflowTotals), 2) ?>
                    </div>
                    <div style="font-size:.7rem;color:#166534;margin-top:3px;">Approved: &#8369;<?= number_format($ownerInflowTotals['approved'], 2) ?></div>
                </div>
            </div>

            <?php if (empty($ownerInflowRecords)): ?>
            <div style="text-align:center;padding:40px 24px;color:var(--neutral-400);">
                <i class="fas fa-hand-holding-usd" style="font-size:2.5rem;margin-bottom:12px;display:block;opacity:.4;"></i>
                No inflow records for <strong><?= htmlspecialchars($fin_label) ?></strong>.
                The Operations Manager can log catering orders, grants, and other income from their workspace.
            </div>
            <?php else: ?>
            <div style="overflow-x:auto;">
            <table class="table" style="font-size:.86rem;width:100%;border-collapse:collapse;">
                <thead>
                    <tr style="background:#f8fafc;border-bottom:2px solid var(--neutral-200);">
                        <th style="padding:10px 12px;">Date</th>
                        <th style="padding:10px 12px;">Type</th>
                        <th style="padding:10px 12px;">Description</th>
                        <th style="padding:10px 12px;">Logged By</th>
                        <th style="padding:10px 12px;text-align:right;">Amount</th>
                        <th style="padding:10px 12px;text-align:center;">Status</th>
                        <th style="padding:10px 12px;text-align:center;">Receipt</th>
                    </tr>
                </thead>
                <tbody>
                <?php foreach ($ownerInflowRecords as $inf):
                    $itype  = $inf['inflow_type'] ?? 'other';
                    $iinfo  = INFLOW_TYPES[$itype] ?? INFLOW_TYPES['other'];
                    $istatus = $inf['status'];
                    [$sbg, $stc] = match($istatus) {
                        'approved' => ['#dcfce7', '#15803d'],
                        'rejected' => ['#fee2e2', '#b91c1c'],
                        default    => ['#fef9c3', '#854d0e'],
                    };
                ?>
                <tr style="border-bottom:1px solid var(--neutral-100);">
                    <td style="padding:9px 12px;white-space:nowrap;vertical-align:top;">
                        <div style="font-weight:700;font-size:.82rem;">
                            <?= date('M j, Y', strtotime($inf['inflow_date'])) ?>
                        </div>
                        <?php if ($inf['event_date']): ?>
                        <div style="font-size:.7rem;color:#7c3aed;margin-top:2px;">
                            <i class="fas fa-calendar"></i> Event: <?= date('M j, Y', strtotime($inf['event_date'])) ?>
                        </div>
                        <?php endif; ?>
                    </td>
                    <td style="padding:9px 12px;vertical-align:top;white-space:nowrap;">
                        <span style="display:inline-flex;align-items:center;gap:5px;
                                     background:<?= $iinfo[2] ?>;color:<?= $iinfo[1] ?>;
                                     border-radius:8px;padding:4px 10px;font-size:.78rem;font-weight:700;">
                            <i class="<?= $iinfo[0] ?>"></i>
                            <?= htmlspecialchars($iinfo[3]) ?>
                        </span>
                    </td>
                    <td style="padding:9px 12px;vertical-align:top;max-width:260px;">
                        <div style="font-weight:600;color:var(--neutral-800);line-height:1.4;">
                            <?= htmlspecialchars($inf['description']) ?>
                        </div>
                        <?php if ($inf['client_name']): ?>
                        <div style="font-size:.72rem;color:var(--neutral-400);margin-top:2px;">
                            <i class="fas fa-user" style="color:#7c3aed;"></i>
                            <?= htmlspecialchars($inf['client_name']) ?>
                            <?php if ($inf['reference_number']): ?>
                            &nbsp;&middot;&nbsp; Ref: <?= htmlspecialchars($inf['reference_number']) ?>
                            <?php endif; ?>
                        </div>
                        <?php endif; ?>
                    </td>
                    <td style="padding:9px 12px;vertical-align:top;white-space:nowrap;">
                        <div style="font-size:.8rem;color:var(--neutral-600);">
                            <?= htmlspecialchars($inf['created_by_name'] ?? '—') ?>
                        </div>
                        <div style="font-size:.7rem;color:var(--neutral-400);margin-top:1px;text-transform:capitalize;">
                            <?= htmlspecialchars(str_replace('_', ' ', $inf['created_by_role'] ?? '')) ?>
                        </div>
                    </td>
                    <td style="padding:9px 12px;text-align:right;vertical-align:top;white-space:nowrap;">
                        <span style="font-size:1rem;font-weight:800;
                                     color:<?= $istatus === 'approved' ? 'var(--primary-700)' : 'var(--neutral-400)' ?>;">
                            <?= $istatus !== 'approved' ? '<s>' : '' ?>&#8369;<?= number_format((float)$inf['amount'], 2) ?><?= $istatus !== 'approved' ? '</s>' : '' ?>
                        </span>
                    </td>
                    <td style="padding:9px 12px;text-align:center;vertical-align:top;">
                        <span style="display:inline-block;background:<?= $sbg ?>;color:<?= $stc ?>;
                                     border-radius:999px;padding:3px 10px;font-size:.73rem;font-weight:700;">
                            <?= ucfirst($istatus) ?>
                        </span>
                    </td>
                    <td style="padding:9px 12px;text-align:center;vertical-align:top;">
                        <?php if (!empty($inf['receipt_path'])): ?>
                        <a href="<?= BASE_URL ?>uploads/<?= htmlspecialchars($inf['receipt_path']) ?>" target="_blank"
                           style="display:inline-flex;align-items:center;gap:4px;color:var(--primary-600);
                                  font-size:.78rem;font-weight:600;text-decoration:none;padding:3px 8px;
                                  border:1px solid var(--primary-200);border-radius:6px;background:var(--primary-50);">
                            <i class="fas fa-file"></i> View
                        </a>
                        <?php else: ?>
                        <span style="font-size:.72rem;color:var(--neutral-300);font-style:italic;">—</span>
                        <?php endif; ?>
                    </td>
                </tr>
                <?php endforeach; ?>
                </tbody>
                <tfoot>
                    <tr style="border-top:2px solid var(--neutral-200);background:#f8fafc;">
                        <td colspan="4" style="padding:10px 12px;font-weight:700;color:var(--neutral-600);
                                                font-size:.8rem;text-transform:uppercase;">
                            Approved Total — <?= htmlspecialchars($fin_label) ?>
                        </td>
                        <td style="padding:10px 12px;text-align:right;font-size:1rem;
                                   font-weight:800;color:var(--primary-700);white-space:nowrap;">
                            &#8369;<?= number_format($ownerInflowTotals['approved'], 2) ?>
                        </td>
                        <td colspan="2"></td>
                    </tr>
                </tfoot>
            </table>
            </div>
            <?php endif; ?>
        </div>
    </div>

    <!-- <?= $fin_label ?> Trend Chart -->
    <div class="fin-chart-card">
        <h4>
            <i class="fas fa-chart-<?= $fin_period === 'today' ? 'bar' : 'line' ?>" style="color:#6366f1"></i>
            <?= $fin_label ?> Cash Flow Trend
            <?php if ($fin_period === 'today'): ?>
            <span style="font-size:.72rem;font-weight:500;color:#94a3b8;margin-left:4px;">(by hour)</span>
            <?php elseif ($fin_period === 'year'): ?>
            <span style="font-size:.72rem;font-weight:500;color:#94a3b8;margin-left:4px;">(by month)</span>
            <?php else: ?>
            <span style="font-size:.72rem;font-weight:500;color:#94a3b8;margin-left:4px;">(by day)</span>
            <?php endif; ?>
        </h4>
        <canvas id="finHourlyChart" height="90"></canvas>
    </div>

    <!-- Synchronized Operational Expenses Panel -->
    <div class="card" style="margin-bottom:24px;">
        <div class="card-header" style="display:flex;align-items:center;justify-content:space-between;flex-wrap:wrap;gap:12px;">
            <div>
                <h3 style="margin:0;font-size:1.05rem;">
                    <i class="fas fa-receipt" style="color:var(--primary-600);margin-right:8px;"></i>
                    Synchronized Operational Expenses
                </h3>
                <div style="font-size:.78rem;color:var(--neutral-400);margin-top:2px;">
                    Real-time synchronized accounting control between Business Owner and Operations Manager
                </div>
            </div>
            <button type="button" class="btn btn-primary btn-sm"
                    onclick="document.getElementById('ownerExpFormWrap').classList.toggle('exp-form-hidden')"
                    style="display:inline-flex;align-items:center;gap:6px;">
                <i class="fas fa-plus-circle"></i> Log Expense as Owner
            </button>
        </div>

        <div class="card-body">

            <!-- Collapsible Add Expense Form for Owner -->
            <div id="ownerExpFormWrap" class="exp-form-hidden" style="margin-bottom:20px;padding:16px;background:#f8fafc;border:1px solid #e2e8f0;border-radius:12px;">
                <h4 style="margin:0 0 12px;font-size:.92rem;color:var(--primary-700);"><i class="fas fa-edit" style="margin-right:6px;"></i>Log New Operational Expense</h4>
                <form method="POST" enctype="multipart/form-data">
                    <input type="hidden" name="save_owner_expense" value="1">
                    <div style="display:grid;grid-template-columns:1fr 1fr 1fr;gap:12px;margin-bottom:12px;">
                        <div>
                            <label class="form-label" style="font-size:.75rem;">Category *</label>
                            <select name="exp_category" class="form-control" style="font-size:.85rem;" required>
                                <?php foreach (['Ingredients','Utilities','Supplies','Wages','Maintenance','Marketing','Transport','Rent','Other'] as $ec): ?>
                                <option value="<?= $ec ?>"><?= $ec ?></option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        <div>
                            <label class="form-label" style="font-size:.75rem;">Period Tag *</label>
                            <select name="exp_period" class="form-control" style="font-size:.85rem;" required>
                                <option value="daily">Daily (Today)</option>
                                <option value="weekly">Weekly (This week)</option>
                                <option value="monthly">Monthly (This month)</option>
                            </select>
                        </div>
                        <div>
                            <label class="form-label" style="font-size:.75rem;">Date *</label>
                            <input type="date" name="exp_date" class="form-control" style="font-size:.85rem;" value="<?= date('Y-m-d') ?>" max="<?= date('Y-m-d') ?>" required>
                        </div>
                    </div>
                    <div style="margin-bottom:12px;">
                        <label class="form-label" style="font-size:.75rem;">Purpose / Description *</label>
                        <input type="text" name="exp_description" class="form-control" style="font-size:.85rem;" placeholder="e.g. Paid weekly staff wages / Purchased fresh vegetables" required>
                    </div>
                    <div style="display:grid;grid-template-columns:1fr 2fr;gap:12px;margin-bottom:12px;">
                        <div>
                            <label class="form-label" style="font-size:.75rem;">Amount (₱) *</label>
                            <input type="number" name="exp_amount" class="form-control" style="font-size:.85rem;font-weight:700;" min="0.01" step="0.01" placeholder="0.00" required>
                        </div>
                        <div>
                            <label class="form-label" style="font-size:.75rem;">Receipt / Document (Optional)</label>
                            <input type="file" name="exp_receipt" class="form-control" style="font-size:.82rem;" accept="image/*,application/pdf">
                        </div>
                    </div>
                    <div style="display:flex;justify-content:flex-end;gap:8px;">
                        <button type="button" class="btn btn-sm btn-outline" onclick="document.getElementById('ownerExpFormWrap').classList.add('exp-form-hidden')">Cancel</button>
                        <button type="submit" class="btn btn-sm btn-primary"><i class="fas fa-check"></i> Save Expense</button>
                    </div>
                </form>
            </div>

            <!-- Period indicator — expenses now follow the main fin_period selector above -->
            <div style="display:flex;align-items:center;justify-content:space-between;flex-wrap:wrap;gap:12px;margin-bottom:16px;">
                <div style="display:flex;align-items:center;gap:8px;">
                    <span style="font-size:.8rem;font-weight:600;color:var(--neutral-500);">
                        <i class="fas fa-filter" style="color:var(--primary-400);margin-right:4px;"></i>
                        Showing expenses for:
                    </span>
                    <span style="display:inline-flex;align-items:center;gap:5px;padding:4px 14px;
                                 border-radius:999px;font-size:.8rem;font-weight:700;
                                 background:var(--primary-600);color:#fff;">
                        <?= htmlspecialchars($fin_label) ?>
                    </span>
                    <span style="font-size:.76rem;color:var(--neutral-400);">
                        (use the period selector above to change)
                    </span>
                </div>
                <div style="font-size:.82rem;font-weight:700;color:var(--primary-700);">
                    Total: &#8369;<?= number_format($exp_total, 2) ?>
                </div>
            </div>

            <!-- Synchronized Table -->
            <?php if (empty($exp_records)): ?>
            <div style="text-align:center;padding:32px;color:var(--neutral-400);">
                <i class="fas fa-receipt" style="font-size:2rem;display:block;margin-bottom:8px;opacity:.4;"></i>
                No operational expenses logged for this filter.
            </div>
            <?php else: ?>
            <div class="table-responsive">
            <table class="table" style="font-size:.85rem;">
                <thead>
                    <tr>
                        <th>Date</th>
                        <th>Logged By (Role & Name)</th>
                        <th>Category</th>
                        <th>Purpose / Description</th>
                        <th>Period</th>
                        <th style="text-align:right;">Amount</th>
                        <th style="text-align:center;">Receipt</th>
                        <th style="text-align:center;">Action</th>
                    </tr>
                </thead>
                <tbody>
                    <?php
                    $cat_icon_map = [
                        'Ingredients' => ['fas fa-carrot',           '#16a34a','#dcfce7'],
                        'Utilities'   => ['fas fa-bolt',             '#d97706','#fef9c3'],
                        'Supplies'    => ['fas fa-boxes',            '#0891b2','#e0f2fe'],
                        'Wages'       => ['fas fa-users',            '#7c3aed','#ede9fe'],
                        'Maintenance' => ['fas fa-wrench',           '#dc2626','#fee2e2'],
                        'Marketing'   => ['fas fa-bullhorn',         '#db2777','#fce7f3'],
                        'Transport'   => ['fas fa-truck',            '#2563eb','#eff6ff'],
                        'Rent'        => ['fas fa-building',         '#b45309','#fef3c7'],
                        'Other'       => ['fas fa-ellipsis-h',       '#64748b','#f1f5f9'],
                    ];
                    foreach ($exp_records as $ex):
                        $ex_info = $cat_icon_map[$ex['category']] ?? $cat_icon_map['Other'];
                    ?>
                    <tr>
                        <td>
                            <strong><?= date('M j, Y', strtotime($ex['expense_date'])) ?></strong>
                            <div style="font-size:.72rem;color:var(--neutral-400);"><?= date('g:i A', strtotime($ex['created_at'])) ?></div>
                        </td>
                        <td>
                            <?= renderExpenseAttributionBadge($ex['added_by_role'], $ex['logged_by_name'] ?? '', $ex['logged_by_role_label'] ?? '') ?>
                        </td>
                        <td>
                            <span style="display:inline-flex;align-items:center;gap:5px;background:<?= $ex_info[2] ?>;color:<?= $ex_info[1] ?>;padding:3px 8px;border-radius:6px;font-weight:700;font-size:.78rem;">
                                <i class="<?= $ex_info[0] ?>"></i> <?= htmlspecialchars($ex['category']) ?>
                            </span>
                        </td>
                        <td>
                            <div style="font-weight:600;color:var(--neutral-700);"><?= nl2br(htmlspecialchars($ex['description'])) ?></div>
                            <?php if (!empty($expense_items_map[$ex['id']])): ?>
                            <div style="margin-top:6px;border-top:1px dashed var(--neutral-200);padding-top:6px;">
                                <div style="font-size:.68rem;font-weight:700;color:var(--neutral-400);text-transform:uppercase;letter-spacing:.04em;margin-bottom:4px;">
                                    Line Items Breakdown
                                </div>
                                <ul style="margin:0;padding-left:14px;font-size:.78rem;color:var(--neutral-600);list-style-type:circle;">
                                    <?php foreach ($expense_items_map[$ex['id']] as $item): ?>
                                    <li style="margin-bottom:2px;">
                                        <strong><?= htmlspecialchars($item['description']) ?></strong>
                                        <span style="color:var(--neutral-400);"> · </span>
                                        <?= $item['quantity'] ?> × ₱<?= number_format($item['unit_price'], 2) ?>
                                        <span style="color:var(--neutral-400);"> = </span>
                                        <span style="font-weight:600;color:var(--neutral-700);">₱<?= number_format($item['line_total'], 2) ?></span>
                                    </li>
                                    <?php endforeach; ?>
                                </ul>
                            </div>
                            <?php endif; ?>
                        </td>
                        <td><span style="text-transform:capitalize;font-weight:600;font-size:.75rem;"><?= htmlspecialchars($ex['period_tag']) ?></span></td>
                        <td style="text-align:right;font-weight:700;color:var(--primary-700);">₱<?= number_format((float)$ex['amount'], 2) ?></td>
                        <td style="text-align:center;">
                            <?php if (!empty($ex['receipt_path'])): ?>
                            <a href="<?= BASE_URL ?>uploads/<?= htmlspecialchars($ex['receipt_path']) ?>" target="_blank" class="btn btn-sm btn-outline" style="padding:2px 8px;font-size:.75rem;">
                                <i class="fas fa-file"></i> View
                            </a>
                            <?php else: ?>
                            <span style="font-size:.72rem;color:var(--neutral-400);font-style:italic;">None</span>
                            <?php endif; ?>
                        </td>
                        <td style="text-align:center;">
                            <form method="POST" style="display:inline;margin:0;" onsubmit="return confirm('Delete this expense record?')">
                                <input type="hidden" name="delete_expense" value="<?= $ex['id'] ?>">
                                <button type="submit" class="btn btn-sm btn-danger" style="padding:2px 7px;font-size:.75rem;" title="Delete">
                                    <i class="fas fa-trash"></i>
                                </button>
                            </form>
                        </td>
                    </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
            </div>
            <?php endif; ?>

        </div>
    </div>

    <!-- Computation Modal -->
    <div class="fin-modal-overlay" id="finModal" onclick="if(event.target===this) closeFinModal()">
        <div class="fin-modal">
            <div class="fin-modal-hdr">
                <i id="finModalIcon" class="fas fa-calculator" style="color:#6366f1;font-size:1.1rem;"></i>
                <h4 id="finModalTitle">Computation Breakdown</h4>
                <button class="fin-modal-close" onclick="closeFinModal()"><i class="fas fa-times"></i></button>
            </div>
            <div class="fin-modal-body">
                <div class="fin-formula" id="finModalFormula"></div>
                <div style="font-size:.8rem;font-weight:700;text-transform:uppercase;letter-spacing:.06em;
                            color:#94a3b8;margin-bottom:10px;">Transaction Detail</div>
                <ul class="fin-txn-list" id="finModalList"></ul>
                <div id="finModalEmpty" style="display:none;text-align:center;padding:24px;color:#94a3b8;font-size:.88rem;">
                    <i class="fas fa-inbox" style="font-size:2rem;display:block;margin-bottom:8px;"></i>
                    No transactions recorded today.
                </div>
            </div>
        </div>
    </div>

</div><!-- /tab-financial_report -->

<!-- ══ TAB: BUSINESS VISITS & FOOT TRAFFIC ══════════════════════════════════ -->
<div id="tab-business_visits" class="owner-tab-panel" style="<?= $active_tab !== 'business_visits' ? 'display:none;' : '' ?>">

    <!-- Header Banner -->
    <div style="background:linear-gradient(135deg, #0f172a 0%, #1e293b 100%);border-radius:16px;padding:28px 32px;margin-bottom:28px;display:flex;align-items:center;justify-content:space-between;flex-wrap:wrap;gap:16px;">
        <div style="display:flex;align-items:center;gap:16px;">
            <div style="width:52px;height:52px;border-radius:14px;background:rgba(56,189,248,0.15);display:flex;align-items:center;justify-content:center;font-size:1.6rem;color:#38bdf8;border:1px solid rgba(56,189,248,0.3);">
                <i class="fas fa-chart-line"></i>
            </div>
            <div>
                <h2 style="margin:0;font-size:1.35rem;font-weight:800;color:#ffffff;">Business Visits &amp; Foot Traffic</h2>
                <p style="margin:4px 0 0;font-size:0.82rem;color:#94a3b8;">Digital profile/menu views &amp; physical dine-in customer visits</p>
            </div>
        </div>
        <!-- Growth Badge -->
        <div style="display:flex;align-items:center;gap:10px;background:rgba(255,255,255,0.08);padding:10px 18px;border-radius:999px;border:1px solid rgba(255,255,255,0.12);">
            <i class="fas fa-arrow-trend-up" style="color:<?= $growth_pct >= 0 ? '#4ade80' : '#f87171' ?>;font-size:1.1rem;"></i>
            <div>
                <div style="font-size:1rem;font-weight:800;color:<?= $growth_pct >= 0 ? '#4ade80' : '#f87171' ?>;">
                    <?= ($growth_pct >= 0 ? '+' : '') . number_format($growth_pct, 1) ?>%
                </div>
                <div style="font-size:0.7rem;color:#94a3b8;margin-top:1px;">vs last month</div>
            </div>
        </div>
    </div>

    <!-- KPI Cards Grid -->
    <div style="display:grid;grid-template-columns:repeat(auto-fit, minmax(240px, 1fr));gap:20px;margin-bottom:28px;">

        <!-- Total Combined Visits -->
        <div style="background:#ffffff;border:1px solid #bae6fd;border-radius:14px;padding:22px;position:relative;overflow:hidden;box-shadow:0 2px 10px rgba(14,165,233,0.08);">
            <div style="position:absolute;right:-12px;bottom:-12px;font-size:5rem;color:rgba(14,165,233,0.07);pointer-events:none;"><i class="fas fa-users"></i></div>
            <div style="display:flex;align-items:center;justify-content:space-between;margin-bottom:10px;">
                <div style="width:38px;height:38px;border-radius:10px;background:linear-gradient(135deg,#0ea5e9,#0284c7);display:flex;align-items:center;justify-content:center;color:#fff;font-size:1rem;">
                    <i class="fas fa-users"></i>
                </div>
                <span style="background:#dbeafe;color:#1d4ed8;font-size:0.68rem;font-weight:700;padding:3px 8px;border-radius:999px;text-transform:uppercase;letter-spacing:.04em;">This Month</span>
            </div>
            <div style="font-size:0.78rem;font-weight:600;color:#64748b;text-transform:uppercase;letter-spacing:.04em;margin-bottom:4px;">Total Combined Visits</div>
            <div style="font-size:2.2rem;font-weight:900;color:#0c4a6e;line-height:1.1;"><?= number_format($total_visits_month) ?></div>
            <div style="font-size:0.77rem;color:#0284c7;margin-top:8px;display:flex;align-items:center;gap:8px;">
                <span><i class="fas fa-calendar-day"></i> <strong><?= number_format($total_visits_today) ?></strong> today</span>
                <span style="color:#cbd5e1;">·</span>
                <span><i class="fas fa-infinity"></i> <strong><?= number_format($total_visits_total) ?></strong> lifetime</span>
            </div>
        </div>

        <!-- Online Profile / Menu Views -->
        <div style="background:#ffffff;border:1px solid #bbf7d0;border-radius:14px;padding:22px;position:relative;overflow:hidden;box-shadow:0 2px 10px rgba(34,197,94,0.08);">
            <div style="position:absolute;right:-12px;bottom:-12px;font-size:5rem;color:rgba(34,197,94,0.07);pointer-events:none;"><i class="fas fa-globe"></i></div>
            <div style="display:flex;align-items:center;justify-content:space-between;margin-bottom:10px;">
                <div style="width:38px;height:38px;border-radius:10px;background:linear-gradient(135deg,#22c55e,#16a34a);display:flex;align-items:center;justify-content:center;color:#fff;font-size:1rem;">
                    <i class="fas fa-globe"></i>
                </div>
                <span style="background:#dcfce7;color:#15803d;font-size:0.68rem;font-weight:700;padding:3px 8px;border-radius:999px;text-transform:uppercase;letter-spacing:.04em;"><i class="fas fa-eye" style="font-size:0.6rem;"></i> Digital</span>
            </div>
            <div style="font-size:0.78rem;font-weight:600;color:#64748b;text-transform:uppercase;letter-spacing:.04em;margin-bottom:4px;">Online Profile Views</div>
            <div style="font-size:2.2rem;font-weight:900;color:#14532d;line-height:1.1;"><?= number_format($traffic_stats['online_views_month']) ?></div>
            <div style="font-size:0.77rem;color:#16a34a;margin-top:8px;display:flex;align-items:center;gap:8px;">
                <span><i class="fas fa-clock"></i> <strong><?= number_format($traffic_stats['online_views_today']) ?></strong> today</span>
                <span style="color:#cbd5e1;">·</span>
                <span><i class="fas fa-infinity"></i> <strong><?= number_format($traffic_stats['online_views_total']) ?></strong> total</span>
            </div>
        </div>

        <!-- Dine-In / Physical Visits -->
        <div style="background:#ffffff;border:1px solid #fed7aa;border-radius:14px;padding:22px;position:relative;overflow:hidden;box-shadow:0 2px 10px rgba(249,115,22,0.08);">
            <div style="position:absolute;right:-12px;bottom:-12px;font-size:5rem;color:rgba(249,115,22,0.07);pointer-events:none;"><i class="fas fa-utensils"></i></div>
            <div style="display:flex;align-items:center;justify-content:space-between;margin-bottom:10px;">
                <div style="width:38px;height:38px;border-radius:10px;background:linear-gradient(135deg,#f97316,#ea580c);display:flex;align-items:center;justify-content:center;color:#fff;font-size:1rem;">
                    <i class="fas fa-store"></i>
                </div>
                <span style="background:#ffedd5;color:#c2410c;font-size:0.68rem;font-weight:700;padding:3px 8px;border-radius:999px;text-transform:uppercase;letter-spacing:.04em;"><i class="fas fa-chair" style="font-size:0.6rem;"></i> Physical</span>
            </div>
            <div style="font-size:0.78rem;font-weight:600;color:#64748b;text-transform:uppercase;letter-spacing:.04em;margin-bottom:4px;">Dine-In / Walk-In Visits</div>
            <div style="font-size:2.2rem;font-weight:900;color:#7c2d12;line-height:1.1;"><?= number_format($traffic_stats['dine_in_month']) ?></div>
            <div style="font-size:0.77rem;color:#ea580c;margin-top:8px;display:flex;align-items:center;gap:8px;">
                <span><i class="fas fa-chair"></i> <strong><?= number_format($traffic_stats['dine_in_today']) ?></strong> today</span>
                <span style="color:#cbd5e1;">·</span>
                <span><i class="fas fa-infinity"></i> <strong><?= number_format($traffic_stats['dine_in_total']) ?></strong> total</span>
            </div>
        </div>

    </div>

    <!-- Traffic Distribution Bar -->
    <div style="background:#ffffff;border:1px solid var(--neutral-200);border-radius:14px;padding:22px;box-shadow:0 2px 8px rgba(0,0,0,0.04);margin-bottom:28px;">
        <div style="display:flex;align-items:center;justify-content:space-between;margin-bottom:14px;flex-wrap:wrap;gap:8px;">
            <h4 style="margin:0;font-size:0.95rem;font-weight:700;color:var(--neutral-800);display:flex;align-items:center;gap:8px;">
                <i class="fas fa-chart-pie" style="color:#6366f1;"></i> Traffic Source Distribution
            </h4>
            <span style="font-size:0.75rem;color:var(--neutral-500);">This Month</span>
        </div>
        <div style="display:flex;justify-content:space-between;font-size:0.82rem;font-weight:600;color:var(--neutral-700);margin-bottom:10px;flex-wrap:wrap;gap:6px;">
            <span><i class="fas fa-globe" style="color:#0284c7;margin-right:4px;"></i> Digital Menu Views: <strong style="color:#0284c7;"><?= $online_pct ?>%</strong></span>
            <span><i class="fas fa-utensils" style="color:#ea580c;margin-right:4px;"></i> In-Store Dine-In: <strong style="color:#ea580c;"><?= $dine_in_pct ?>%</strong></span>
        </div>
        <div style="height:12px;background:#f1f5f9;border-radius:999px;overflow:hidden;display:flex;gap:2px;">
            <div style="width:<?= $online_pct ?>%;background:linear-gradient(90deg,#38bdf8,#0284c7);border-radius:999px 0 0 999px;transition:width 0.6s ease;"></div>
            <div style="width:<?= $dine_in_pct ?>%;background:linear-gradient(90deg,#fb923c,#ea580c);border-radius:0 999px 999px 0;transition:width 0.6s ease;"></div>
        </div>
        <div style="display:flex;justify-content:space-between;font-size:0.72rem;color:var(--neutral-400);margin-top:8px;">
            <span>0%</span><span>50%</span><span>100%</span>
        </div>
    </div>

    <!-- Insight Note -->
    <div style="background:linear-gradient(135deg,#f0f9ff,#e0f2fe);border:1px solid #bae6fd;border-radius:12px;padding:16px 20px;display:flex;align-items:flex-start;gap:12px;">
        <div style="width:32px;height:32px;min-width:32px;border-radius:8px;background:#0284c7;display:flex;align-items:center;justify-content:center;color:#fff;font-size:0.85rem;margin-top:1px;">
            <i class="fas fa-lightbulb"></i>
        </div>
        <div>
            <div style="font-size:0.83rem;font-weight:700;color:#0369a1;margin-bottom:3px;">Analytics Insight</div>
            <div style="font-size:0.8rem;color:#0284c7;line-height:1.5;">
                <?php
                $dominant = $traffic_stats['online_views_month'] >= $traffic_stats['dine_in_month'] ? 'online' : 'dine-in';
                if ($dominant === 'online'):
                ?>
                    Your business is primarily driven by <strong>digital discovery</strong> — customers find you online first. Consider promoting your dine-in experience to boost foot traffic.
                <?php else: ?>
                    Your business is primarily driven by <strong>walk-in foot traffic</strong>. Consider boosting your online presence to attract more digital customers.
                <?php endif; ?>
            </div>
        </div>
    </div>

</div><!-- /tab-business_visits -->

<!-- ══ TAB 4: CUSTOMER PURCHASE INTENTION ═══════════════════════════════════ -->
<div id="tab-demand_insights" class="owner-tab-panel" style="<?= $active_tab !== 'demand_insights' ? 'display:none;' : '' ?>">

<?php
// ── TPB aggregate data fetch (v2: ATT + SN + PBC) ────────────────────────────
$tpb_data = null;
$tbl_tpb  = $conn->query("SHOW TABLES LIKE 'tpb_survey_responses'");
if ($tbl_tpb && $tbl_tpb->num_rows > 0) {
    // Detect whether new normalized_pi column exists
    $col_chk = $conn->query("SHOW COLUMNS FROM tpb_survey_responses LIKE 'normalized_pi'");
    $has_norm = ($col_chk && $col_chk->num_rows > 0);
    $pi_expr  = $has_norm ? 'normalized_pi' : 'predicted_pi';
    $pbc_expr = $has_norm ? 'mean_pbc'      : '0';

    $tpb_rows = $conn->query("
        SELECT religion,
               COUNT(*)                                         AS n,
               ROUND(AVG(mean_att),   3)                        AS avg_att,
               ROUND(AVG(mean_sn),    3)                        AS avg_sn,
               ROUND(AVG({$pbc_expr}),3)                        AS avg_pbc,
               ROUND(AVG({$pi_expr}), 3)                        AS avg_pi,
               ROUND(((AVG({$pi_expr})-1)/4)*100, 1)            AS pi_pct
        FROM tpb_survey_responses
        WHERE {$pi_expr} IS NOT NULL
        GROUP BY religion
    ")?->fetch_all(MYSQLI_ASSOC) ?? [];

    $tpb_total = (int)($conn->query("SELECT COUNT(*) AS c FROM tpb_survey_responses WHERE {$pi_expr} IS NOT NULL")->fetch_assoc()['c'] ?? 0);

    $tpb_by_group = [];
    foreach ($tpb_rows as $tr) {
        $att = (float)$tr['avg_att'];
        $sn  = (float)$tr['avg_sn'];
        $pbc = (float)$tr['avg_pbc'];
        $dominant = match(true) {
            $att >= $sn && $att >= $pbc => 'Attitude (ATT)',
            $sn  >= $att && $sn >= $pbc  => 'Subjective Norms (SN)',
            default                       => 'Perceived Behavioral Control (PBC)',
        };
        $tpb_by_group[$tr['religion']] = array_merge($tr, ['dominant' => $dominant]);
    }

    $overall_row = $conn->query("
        SELECT ROUND(AVG({$pi_expr}),3) AS pi,
               ROUND(AVG(mean_att),3)   AS att,
               ROUND(AVG(mean_sn),3)    AS sn,
               ROUND(AVG({$pbc_expr}),3) AS pbc,
               ROUND(((AVG({$pi_expr})-1)/4)*100,1) AS pi_pct
        FROM tpb_survey_responses
        WHERE {$pi_expr} IS NOT NULL
    ")?->fetch_assoc();

    $tpb_data = [
        'total'       => $tpb_total,
        'muslim'      => $tpb_by_group['Muslim']     ?? null,
        'non_muslim'  => $tpb_by_group['Non-Muslim'] ?? null,
        'overall_pi'  => (float)($overall_row['pi']  ?? 0),
        'overall_att' => (float)($overall_row['att'] ?? 0),
        'overall_sn'  => (float)($overall_row['sn']  ?? 0),
        'overall_pbc' => (float)($overall_row['pbc'] ?? 0),
        'overall_pct' => (float)($overall_row['pi_pct'] ?? 0),
    ];
}

$pi_color = fn(float $pi): string => match(true) {
    $pi >= 4.0 => '#16a34a', $pi >= 3.0 => '#059669',
    $pi >= 2.0 => '#d97706', default     => '#dc2626',
};
$pi_label = fn(float $pi): string => match(true) {
    $pi >= 4.5 => 'Very High', $pi >= 3.5 => 'High',
    $pi >= 2.5 => 'Moderate',  $pi >= 1.5 => 'Low',
    default    => 'Very Low',
};
$pi_interp = fn(float $pi): string => match(true) {
    $pi >= 4.5 => 'Customers are extremely likely to visit and purchase from Halal-certified restaurants.',
    $pi >= 3.5 => 'Most customers intend to visit a Halal-certified restaurant soon.',
    $pi >= 2.5 => 'Customers show moderate interest — there is clear room to grow demand.',
    $pi >= 1.5 => 'Purchase intent is currently low. Consider marketing and awareness efforts.',
    default    => 'Very limited purchase intent recorded so far.',
};
?>

    <?php if (!$tpb_data || $tpb_data['total'] === 0): ?>
    <!-- ── Empty state ───────────────────────────────────────────────────── -->
    <div class="card" style="text-align:center;padding:56px 32px;">
        <div style="width:64px;height:64px;background:var(--primary-100);border-radius:50%;
                    display:flex;align-items:center;justify-content:center;margin:0 auto 16px;">
            <i class="fas fa-users" style="font-size:1.8rem;color:var(--primary-600);"></i>
        </div>
        <h3 style="color:var(--neutral-700);margin:0 0 10px;font-size:1.1rem;">No Survey Responses Yet</h3>
        <p style="color:var(--neutral-400);font-size:.875rem;max-width:420px;margin:0 auto 20px;line-height:1.6;">
            This tab fills in automatically as customers complete the Consumer Demand Survey.
            Share the platform with your customers to start collecting intent data.
        </p>
        <div style="display:inline-block;background:var(--primary-50);border:1px solid var(--primary-200);
                    border-radius:10px;padding:12px 20px;font-size:.82rem;color:var(--primary-700);">
            <i class="fas fa-info-circle" style="margin-right:6px;"></i>
            Formula: <strong>PI = Mean(ATT) + Mean(SN) + Mean(PBC) ÷ 3</strong>
        </div>
    </div>

    <?php else:
        $ov_pi  = $tpb_data['overall_pi'];
        $ov_col = $pi_color($ov_pi);
        $ov_lbl = $pi_label($ov_pi);
        $ov_deg = round(($ov_pi / 5) * 360);

        $m  = $tpb_data['muslim'];
        $nm = $tpb_data['non_muslim'];

        // Helper to safely extract construct means from a group row
        $g_att = fn($d) => $d ? (float)$d['avg_att'] : 0;
        $g_sn  = fn($d) => $d ? (float)$d['avg_sn']  : 0;
        $g_pbc = fn($d) => $d ? (float)$d['avg_pbc'] : 0;
        $g_pi  = fn($d) => $d ? (float)$d['avg_pi']  : 0;
        $g_pct = fn($d) => $d ? (float)$d['pi_pct']  : 0;
        $g_n   = fn($d) => $d ? (int)$d['n']          : 0;
    ?>

    <!-- ── Overall headline ──────────────────────────────────────────────── -->
    <div class="card" style="margin-bottom:20px;background:linear-gradient(135deg,#f0fdf4,#ecfdf5);
                              border:1px solid #bbf7d0;padding:20px 24px;
                              display:flex;align-items:center;gap:20px;flex-wrap:wrap;">
        <div style="width:60px;height:60px;border-radius:50%;flex-shrink:0;
                    background:conic-gradient(<?= $ov_col ?> <?= $ov_deg ?>deg,#e5e7eb <?= $ov_deg ?>deg);
                    display:flex;align-items:center;justify-content:center;">
            <div style="width:44px;height:44px;border-radius:50%;background:#fff;
                        display:flex;align-items:center;justify-content:center;">
                <span style="font-size:1rem;font-weight:900;color:<?= $ov_col ?>;
                             font-family:var(--font-display);"><?= $ov_lbl[0] ?></span>
            </div>
        </div>
        <div style="flex:1;min-width:180px;">
            <div style="font-size:.68rem;font-weight:700;letter-spacing:.07em;text-transform:uppercase;
                        color:var(--primary-700);margin-bottom:4px;">
                Overall Market Intent &nbsp;·&nbsp; <?= number_format($tpb_data['total']) ?> respondents
            </div>
            <div style="font-size:.95rem;font-weight:700;color:var(--neutral-800);line-height:1.4;">
                <?= htmlspecialchars($pi_interp($ov_pi)) ?>
            </div>
        </div>
        <div style="text-align:center;flex-shrink:0;">
            <div style="font-size:2.2rem;font-weight:900;color:<?= $ov_col ?>;
                        font-family:var(--font-display);line-height:1;"><?= number_format($ov_pi, 2) ?></div>
            <div style="font-size:.68rem;color:var(--neutral-400);margin-top:1px;">out of 5.00</div>
            <span style="display:inline-block;margin-top:5px;background:<?= $ov_col ?>;color:#fff;
                         padding:2px 10px;border-radius:999px;font-size:.7rem;font-weight:700;"><?= $ov_lbl ?></span>
        </div>
        <!-- 3 construct mini-badges overall -->
        <div style="display:flex;flex-direction:column;gap:5px;flex-shrink:0;">
            <?php foreach ([
                ['ATT','fa-heart','#dcfce7','#15803d',$tpb_data['overall_att']],
                ['SN', 'fa-users','#dbeafe','#1d4ed8',$tpb_data['overall_sn']],
                ['PBC','fa-hand-pointer','#fef3c7','#92400e',$tpb_data['overall_pbc']],
            ] as [$name,$ico,$bg,$col,$val]): ?>
            <div style="display:flex;align-items:center;gap:6px;background:<?= $bg ?>;
                        border-radius:7px;padding:4px 10px;font-size:.72rem;">
                <i class="fas <?= $ico ?>" style="color:<?= $col ?>"></i>
                <span style="font-weight:700;color:<?= $col ?>"><?= $name ?>:</span>
                <span style="font-weight:800;color:<?= $col ?>"><?= number_format($val,2) ?></span>
            </div>
            <?php endforeach; ?>
        </div>
    </div>

    <!-- ── Side-by-side group cards (score + bar + construct breakdown) ─── -->
    <div style="display:grid;grid-template-columns:1fr 1fr;gap:16px;margin-bottom:20px;">
        <?php
        $groups = [
            'Muslim'     => ['data'=>$m,  'label'=>'Muslim Customers',    'icon'=>'fa-moon',  'accent'=>'#1d4ed8','bg'=>'#eff6ff','border'=>'#bfdbfe','chart_id'=>'radarMuslim'],
            'Non-Muslim' => ['data'=>$nm, 'label'=>'Non-Muslim Customers','icon'=>'fa-users', 'accent'=>'#7c3aed','bg'=>'#f5f3ff','border'=>'#ddd6fe','chart_id'=>'radarNonMuslim'],
        ];
        foreach ($groups as $grp_key => $g):
            $d   = $g['data'];
            $pi  = $g_pi($d);
            $pct = $g_pct($d);
            $n   = $g_n($d);
            $col = $pi_color($pi);
            $lbl = $pi_label($pi);
            $bar = min(round(($pi / 5) * 100), 100);
            $att = $g_att($d); $sn = $g_sn($d); $pbc = $g_pbc($d);
            $sentence = match(true) {
                !$d || $pi === 0.0 => 'No responses from this group yet.',
                $pi >= 4.5 => 'Extremely likely to choose a Halal-certified restaurant.',
                $pi >= 3.5 => 'Very likely to visit and recommend Halal-certified places.',
                $pi >= 2.5 => 'Moderately interested — open to visiting Halal restaurants.',
                $pi >= 1.5 => 'Currently showing low intent. Awareness may help.',
                default    => 'Very limited intent recorded so far.',
            };
            $dominant = $d['dominant'] ?? 'N/A';
        ?>
        <div class="card" style="background:<?= $g['bg'] ?>;border:1.5px solid <?= $g['border'] ?>;padding:20px 22px;">
            <!-- Header -->
            <div style="display:flex;align-items:center;gap:10px;margin-bottom:14px;">
                <div style="width:34px;height:34px;background:<?= $g['accent'] ?>;border-radius:9px;
                            display:flex;align-items:center;justify-content:center;flex-shrink:0;">
                    <i class="fas <?= $g['icon'] ?>" style="color:#fff;font-size:.85rem;"></i>
                </div>
                <div>
                    <div style="font-weight:700;font-size:.85rem;color:<?= $g['accent'] ?>;"><?= $g['label'] ?></div>
                    <div style="font-size:.72rem;color:var(--neutral-400);"><?= $n ?> respondent<?= $n!==1?'s':'' ?></div>
                </div>
                <?php if ($pi > 0): ?>
                <span style="margin-left:auto;background:<?= $col ?>;color:#fff;padding:2px 10px;border-radius:999px;font-size:.72rem;font-weight:700;"><?= $lbl ?></span>
                <?php endif; ?>
            </div>

            <?php if ($pi > 0): ?>
            <!-- PI score + bar -->
            <div style="display:flex;align-items:baseline;gap:6px;margin-bottom:7px;">
                <span style="font-size:2rem;font-weight:900;color:<?= $col ?>;font-family:var(--font-display);line-height:1;"><?= number_format($pi,2) ?></span>
                <span style="font-size:.75rem;color:var(--neutral-400);">/ 5.00 &nbsp;·&nbsp; <?= $pct ?>%</span>
            </div>
            <div style="height:7px;background:rgba(0,0,0,.07);border-radius:999px;overflow:hidden;margin-bottom:12px;">
                <div style="height:100%;background:<?= $col ?>;width:<?= $bar ?>%;border-radius:999px;transition:width 1.1s ease;"></div>
            </div>

            <!-- Stacked construct bars -->
            <div style="margin-bottom:12px;">
                <div style="font-size:.68rem;font-weight:700;text-transform:uppercase;letter-spacing:.06em;
                            color:<?= $g['accent'] ?>;margin-bottom:6px;">Construct Breakdown</div>
                <?php foreach ([
                    ['Attitude (ATT)','fa-heart','#16a34a','#dcfce7',$att],
                    ['Subjective Norms (SN)','fa-users','#1d4ed8','#dbeafe',$sn],
                    ['Perceived Behavioral Control (PBC)','fa-hand-pointer','#92400e','#fef3c7',$pbc],
                ] as [$cname,$cico,$ccol,$cbg,$cval]): ?>
                <div style="margin-bottom:6px;">
                    <div style="display:flex;justify-content:space-between;align-items:center;font-size:.72rem;margin-bottom:2px;">
                        <span style="display:flex;align-items:center;gap:5px;font-weight:<?= $dominant===$cname?'800':'600' ?>;color:<?= $ccol ?>">
                            <?= $dominant===$cname ? '★ ' : '' ?>
                            <i class="fas <?= $cico ?>" style="font-size:.7rem"></i>
                            <?= htmlspecialchars(explode(' (',$cname)[0]) ?>
                        </span>
                        <span style="font-weight:700;color:var(--neutral-700)"><?= number_format($cval,2) ?></span>
                    </div>
                    <div style="height:5px;background:rgba(0,0,0,.06);border-radius:999px;overflow:hidden;">
                        <div style="height:100%;background:<?= $ccol ?>;width:<?= min(round(($cval/5)*100),100) ?>%;border-radius:999px;"></div>
                    </div>
                </div>
                <?php endforeach; ?>
            </div>
            <?php endif; ?>

            <p style="font-size:.8rem;color:var(--neutral-600);margin:0 0 10px;line-height:1.5;"><?= htmlspecialchars($sentence) ?></p>
            <?php if ($d && $pi > 0): ?>
            <div style="font-size:.72rem;font-weight:700;color:<?= $g['accent'] ?>;background:<?= $g['border'] ?>;
                        border-radius:7px;padding:5px 10px;display:inline-block;">
                <i class="fas fa-trophy" style="margin-right:4px;"></i>Dominant: <?= htmlspecialchars($dominant) ?>
            </div>
            <?php endif; ?>
        </div>
        <?php endforeach; ?>
    </div>

    <!-- ── Radar Chart: ATT / SN / PBC per group ─────────────────────────── -->
    <?php if (($g_pi($m) > 0 || $g_pi($nm) > 0)): ?>
    <div class="card" style="padding:20px 24px;margin-bottom:20px;">
        <div style="font-size:.72rem;font-weight:700;text-transform:uppercase;letter-spacing:.07em;
                    color:var(--primary-700);margin-bottom:16px;">
            <i class="fas fa-chart-radar" style="margin-right:5px;color:var(--primary-600);"></i>
            Construct Comparison — Muslim vs. Non-Muslim
        </div>
        <div style="display:grid;grid-template-columns:1fr 1fr;gap:20px;align-items:center;">
            <!-- Radar chart -->
            <div style="position:relative;max-width:320px;margin:0 auto;width:100%;">
                <canvas id="tpbRadarChart" style="width:100%;height:auto;"></canvas>
            </div>
            <!-- Legend + formula -->
            <div>
                <div style="font-size:.82rem;color:var(--neutral-700);font-weight:600;margin-bottom:12px;">
                    How to read this chart:
                </div>
                <?php foreach ([
                    ['Muslim Customers (Set 1)','#1d4ed8','rgba(29,78,216,.12)'],
                    ['Non-Muslim Customers (Set 2)','#7c3aed','rgba(124,58,237,.12)'],
                ] as [$lname,$lcolor,$lbg]): ?>
                <div style="display:flex;align-items:center;gap:8px;margin-bottom:7px;font-size:.8rem;">
                    <div style="width:14px;height:14px;border-radius:3px;background:<?= $lcolor ?>;flex-shrink:0"></div>
                    <span style="color:var(--neutral-700)"><?= $lname ?></span>
                </div>
                <?php endforeach; ?>
                <div style="margin-top:14px;background:var(--neutral-50);border:1px solid var(--neutral-200);
                            border-radius:8px;padding:10px 14px;font-size:.77rem;color:var(--neutral-600);line-height:1.55;">
                    <strong>Formula:</strong><br>
                    PI = Mean(ATT) + Mean(SN) + Mean(PBC)<br>
                    Normalized PI = Raw / 3 → 1.0–5.0<br>
                    <span style="color:var(--neutral-400);font-size:.72rem">Each axis shows mean score (1–5)</span>
                </div>
                <!-- Numeric table -->
                <table style="width:100%;border-collapse:collapse;font-size:.78rem;margin-top:12px;">
                    <thead>
                        <tr style="background:var(--neutral-50)">
                            <th style="padding:6px 10px;text-align:left;color:var(--neutral-500);font-weight:600;border-bottom:1px solid var(--neutral-200)">Construct</th>
                            <th style="padding:6px 10px;text-align:center;color:#1d4ed8;font-weight:700;border-bottom:1px solid var(--neutral-200)">Muslim</th>
                            <th style="padding:6px 10px;text-align:center;color:#7c3aed;font-weight:700;border-bottom:1px solid var(--neutral-200)">Non-Muslim</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ([
                            ['Attitude (ATT)',   $g_att($m), $g_att($nm)],
                            ['Subj. Norms (SN)', $g_sn($m),  $g_sn($nm)],
                            ['PBC',              $g_pbc($m), $g_pbc($nm)],
                            ['Norm. PI',         $g_pi($m),  $g_pi($nm)],
                        ] as [$rname,$rval_m,$rval_nm]):
                            $last = $rname === 'Norm. PI';
                        ?>
                        <tr style="border-bottom:1px solid var(--neutral-100);<?= $last?'font-weight:700;background:var(--neutral-50)':'' ?>">
                            <td style="padding:6px 10px;color:var(--neutral-700)"><?= $rname ?></td>
                            <td style="padding:6px 10px;text-align:center;color:#1d4ed8"><?= $rval_m>0?number_format($rval_m,2):'—' ?></td>
                            <td style="padding:6px 10px;text-align:center;color:#7c3aed"><?= $rval_nm>0?number_format($rval_nm,2):'—' ?></td>
                        </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        </div>
    </div>

    <script>
    (function(){
        const ctx = document.getElementById('tpbRadarChart');
        if (!ctx) return;
        // Load Chart.js on demand
        function loadChartJs(cb) {
            if (window.Chart) { cb(); return; }
            const s = document.createElement('script');
            s.src = 'https://cdn.jsdelivr.net/npm/chart.js@4.4.0/dist/chart.umd.min.js';
            s.onload = cb;
            document.head.appendChild(s);
        }
        loadChartJs(function(){
            new Chart(ctx, {
                type: 'radar',
                data: {
                    labels: ['Attitude\n(ATT)', 'Subjective\nNorms (SN)', 'Perceived\nBehavioral\nControl (PBC)'],
                    datasets: [
                        {
                            label: 'Muslim Customers',
                            data: [<?= number_format($g_att($m),3) ?>, <?= number_format($g_sn($m),3) ?>, <?= number_format($g_pbc($m),3) ?>],
                            borderColor: '#1d4ed8',
                            backgroundColor: 'rgba(29,78,216,.15)',
                            pointBackgroundColor: '#1d4ed8',
                            pointRadius: 5,
                            borderWidth: 2.5,
                        },
                        {
                            label: 'Non-Muslim Customers',
                            data: [<?= number_format($g_att($nm),3) ?>, <?= number_format($g_sn($nm),3) ?>, <?= number_format($g_pbc($nm),3) ?>],
                            borderColor: '#7c3aed',
                            backgroundColor: 'rgba(124,58,237,.15)',
                            pointBackgroundColor: '#7c3aed',
                            pointRadius: 5,
                            borderWidth: 2.5,
                        },
                    ]
                },
                options: {
                    responsive: true,
                    scales: {
                        r: {
                            min: 0, max: 5,
                            ticks: { stepSize: 1, font: { size: 11 }, color: '#94a3b8', backdropColor: 'transparent' },
                            pointLabels: { font: { size: 11, weight: '600' }, color: '#374151' },
                            grid: { color: '#e2e8f0' },
                            angleLines: { color: '#e2e8f0' },
                        }
                    },
                    plugins: {
                        legend: { display: false },
                        tooltip: {
                            callbacks: {
                                label: ctx => `${ctx.dataset.label}: ${ctx.parsed.r.toFixed(2)}`
                            }
                        }
                    }
                }
            });
        });
    })();
    </script>
    <?php endif; ?>

    <!-- ── Business insights ──────────────────────────────────────────────── -->
    <?php if ($ov_pi > 0):
        $muslim_pi = $g_pi($m);  $nm_pi = $g_pi($nm);
        $sn_src    = $m ?? $nm;  $avg_sn = $g_sn($sn_src);
        $avg_pbc   = $g_pbc($m ?? $nm);

        $insights = [];
        if ($muslim_pi > 0 && $nm_pi > 0) {
            $top_grp = $muslim_pi >= $nm_pi ? 'Muslim' : 'Non-Muslim';
            $top_pi  = max($muslim_pi, $nm_pi);
            $insights[] = ['fa-crosshairs','#1d4ed8','#eff6ff','Strongest Segment',
                "{$top_grp} customers show the highest purchase intent (" . number_format($top_pi,2) . "/5). Prioritise marketing to this group first."];
        } elseif ($muslim_pi > 0 || $nm_pi > 0) {
            $only_pi  = $muslim_pi > 0 ? $muslim_pi : $nm_pi;
            $only_grp = $muslim_pi > 0 ? 'Muslim' : 'Non-Muslim';
            $insights[] = ['fa-crosshairs','#1d4ed8','#eff6ff','Active Segment',
                "{$only_grp} responses are in. Score: " . number_format($only_pi,2) . "/5."];
        }
        if ($avg_sn > 0) {
            $insights[] = ['fa-share-alt','#7c3aed','#f5f3ff','Word-of-Mouth Potential',
                $avg_sn >= 3.5
                    ? 'Social influence is strong (avg SN ' . number_format($avg_sn,1) . '). Referrals and family recommendations are driving visits — encourage reviews.'
                    : 'Social influence is moderate (avg SN ' . number_format($avg_sn,1) . '). Strengthen community presence to lift this score.'];
        }
        if ($avg_pbc > 0) {
            $insights[] = ['fa-map-marker-alt','#92400e','#fef3c7','Accessibility Signal',
                $avg_pbc >= 3.5
                    ? 'Customers find it easy to locate and access Halal options (avg PBC ' . number_format($avg_pbc,1) . '). Visibility is working — maintain it.'
                    : 'Customers face some barriers finding or accessing your Halal business (avg PBC ' . number_format($avg_pbc,1) . '). Improve signage, maps, and menu clarity.'];
        }
        $insights[] = ['fa-store',$ov_pi>=3.5?'#16a34a':'#d97706',$ov_pi>=3.5?'#dcfce7':'#fef3c7','Market Readiness',
            $ov_pi>=4.0
                ? 'Strong demand. Investing in Halal certification and visibility is likely to bring a direct return.'
                : ($ov_pi>=3.0
                    ? 'Demand is healthy. Consistent quality and marketing can push intent higher.'
                    : 'Demand is building. Completing HaCeRa and earning your certificate builds customer trust.')];
    ?>
    <div class="card" style="padding:20px 22px;">
        <div style="font-size:.72rem;font-weight:700;letter-spacing:.07em;text-transform:uppercase;
                    color:var(--primary-700);margin-bottom:14px;">
            <i class="fas fa-lightbulb" style="margin-right:5px;color:var(--accent-500);"></i>
            What This Means for Your Business
        </div>
        <div style="display:grid;grid-template-columns:repeat(auto-fit,minmax(190px,1fr));gap:12px;">
            <?php foreach ($insights as [$ico,$col,$bg,$title,$body]): ?>
            <div style="background:<?= $bg ?>;border-radius:9px;padding:14px 16px;">
                <div style="display:flex;align-items:center;gap:7px;margin-bottom:7px;">
                    <i class="fas <?= $ico ?>" style="color:<?= $col ?>;font-size:.9rem;"></i>
                    <span style="font-size:.8rem;font-weight:700;color:<?= $col ?>;"><?= $title ?></span>
                </div>
                <p style="font-size:.8rem;color:var(--neutral-700);margin:0;line-height:1.55;"><?= htmlspecialchars($body) ?></p>
            </div>
            <?php endforeach; ?>
        </div>
    </div>
    <?php endif; ?>

    <?php endif; // end has data ?>

</div><!-- /tab-demand_insights -->

<?php endif; ?>

<?php
$extra_js = <<<'EOJS'
<script src="https://cdn.jsdelivr.net/npm/chart.js@4.4.0/dist/chart.umd.min.js"></script>
<script>
const STAFF_API = BASE_URL + 'dashboard/business_owner/api/staff_action.php';

// ── Tab Switcher Logic with State Persistence ──────────────────────────────
function switchOwnerTab(tab) {
    document.querySelectorAll('.owner-tab-panel').forEach(p => p.style.display = 'none');
    document.querySelectorAll('.owner-tab-btn').forEach(b => {
        const on = b.dataset.tab === tab;
        b.classList.toggle('active', on);
        b.style.color             = on ? 'var(--primary-600)' : 'var(--neutral-500)';
        b.style.borderBottomColor = on ? 'var(--primary-600)' : 'transparent';
    });
    const panel = document.getElementById('tab-' + tab);
    if (panel) panel.style.display = '';
    const url = new URL(window.location.href);
    url.searchParams.set('tab', tab);
    window.history.replaceState(null, '', url.toString());
}

// ── Assign staff form ─────────────────────────────────────────────────────────
document.getElementById('assignStaffForm')?.addEventListener('submit', async function(e) {
    e.preventDefault();
    const fd = new FormData(this);
    const res = document.getElementById('assignResult');
    res.style.display = '';
    res.innerHTML = '<span style="color:var(--neutral-500)"><i class="fas fa-spinner fa-spin"></i> Assigning…</span>';
    try {
        const r = await fetch(STAFF_API, { method:'POST', body: fd });
        const d = await r.json();
        if (d.success) {
            res.innerHTML = '<div class="alert alert-success" style="margin:0;padding:10px 14px;">'
                + '<i class="fas fa-check-circle"></i> ' + d.message + '</div>';
            setTimeout(() => location.reload(), 900);
        } else {
            res.innerHTML = '<div class="alert alert-danger" style="margin:0;padding:10px 14px;">'
                + '<i class="fas fa-exclamation-circle"></i> ' + d.message + '</div>';
        }
    } catch {
        res.innerHTML = '<div class="alert alert-danger" style="margin:0;padding:10px 14px;">Request failed.</div>';
    }
});

// ── Staff action (activate / deactivate / remove / change_role) ───────────────
async function staffAction(staffId, action, roleKey = '') {
    const fd = new FormData();
    fd.append('action', action);
    fd.append('staff_id', staffId);
    if (roleKey) fd.append('staff_role_key', roleKey);
    try {
        const r = await fetch(STAFF_API, { method:'POST', body: fd });
        const d = await r.json();
        if (d.success) { location.reload(); } else { alert('Error: ' + d.message); }
    } catch { alert('Request failed. Please try again.'); }
}

// ══ Financial Dashboard ════════════════════════════════════════════════════════
const _fin = window.dashboardData || { revenue:0, expenses:0, net:0, logs:[], hourly:[], period:'today', chartMode:'hourly' };

function _fmt(n) {
    return '₱' + Math.abs(parseFloat(n)).toLocaleString('en-PH',{minimumFractionDigits:2,maximumFractionDigits:2});
}

const _PERIOD_LABELS = { today:'Today', week:'This Week', month: _fin.monthLabel || 'This Month', year:'This Year' };
const _periodLabel = _PERIOD_LABELS[_fin.period] || 'This Period';

// Modal logic
function openFinModal(type) {
    const modal   = document.getElementById('finModal');
    const title   = document.getElementById('finModalTitle');
    const icon    = document.getElementById('finModalIcon');
    const formula = document.getElementById('finModalFormula');
    const list    = document.getElementById('finModalList');
    const empty   = document.getElementById('finModalEmpty');

    let logs = [];
    if (type === 'revenue') {
        title.textContent = 'Revenue Breakdown';
        icon.style.color  = '#16a34a';
        formula.innerHTML =
            '<span class="var">' + _periodLabel + ' Revenue</span> <span class="eq">=</span> ' +
            'Food order subtotals + Approved other inflows (catering, grants, etc.)<br>' +
            '<span class="eq">Formula:</span> SUM(order.total_amount &minus; delivery_fee &minus; platform_fee) + SUM(approved inflows)<br>' +
            '<span class="var">Result:</span> ' + _fmt(_fin.revenue);
        logs = _fin.logs.filter(l => l.type === 'order' || l.type === 'inflow');
    } else if (type === 'expense') {
        title.textContent = 'Expenses Breakdown';
        icon.style.color  = '#ea580c';
        formula.innerHTML =
            '<span class="var">' + _periodLabel + ' Expenses</span> <span class="eq">=</span> ' +
            'Delivery fees (courier pass-through) + Operational costs<br>' +
            '<span class="var">Result:</span> ' + _fmt(_fin.expenses);
        logs = _fin.logs.filter(l => l.type !== 'order' && l.type !== 'inflow');
    } else if (type === 'inflow') {
        title.textContent = 'Other Inflows Breakdown';
        icon.style.color  = '#7c3aed';
        formula.innerHTML =
            '<span class="var">' + _periodLabel + ' Other Inflows</span> <span class="eq">=</span> ' +
            'Approved catering orders, grants, sponsorships &amp; other non-order income<br>' +
            '<span class="var">Period total (approved):</span> <span style="color:#7c3aed">' +
            _fmt(_fin.inflowToday || 0) + '</span>';
        logs = _fin.logs.filter(l => l.type === 'inflow');
    } else {
        title.textContent = _periodLabel + ' Net Cash Flow';
        icon.style.color  = '#6366f1';
        const net = parseFloat(_fin.net);
        formula.innerHTML =
            '<span class="var">Net Cash Flow</span> <span class="eq">=</span> ' +
            'Revenue <span class="eq">&minus;</span> Expenses<br>' +
            _fmt(_fin.revenue) + ' <span class="eq">&minus;</span> ' + _fmt(_fin.expenses) +
            ' <span class="eq">=</span> <span class="var" style="color:' +
            (net < 0 ? '#dc2626' : '#4f46e5') + '">' +
            (net < 0 ? '&minus;' : '+') + _fmt(net) + '</span>';
        logs = _fin.logs;
    }

    list.innerHTML = '';
    if (!logs.length) {
        list.style.display = 'none';
        empty.style.display = '';
    } else {
        list.style.display = '';
        empty.style.display = 'none';
        logs.forEach(l => {
            const isOrder    = l.type === 'order';
            const isDelivery = l.type === 'delivery';
            const isInflow   = l.type === 'inflow';
            const isCredit   = isOrder || isInflow;
            const ts = l.ts ? l.ts.substring(11,16) : '--:--';
            const li = document.createElement('li');
            let badgeClass, badgeLabel;
            if (isOrder)         { badgeClass = 'order';    badgeLabel = 'Order'; }
            else if (isDelivery) { badgeClass = 'delivery'; badgeLabel = 'Delivery Fee'; }
            else if (isInflow)   { badgeClass = 'inflow';   badgeLabel = 'Other Inflow'; }
            else                 { badgeClass = 'expense';  badgeLabel = 'Expense'; }
            li.innerHTML =
                `<span class="fin-txn-type ${badgeClass}">${badgeLabel}</span>` +
                `<span class="fin-txn-desc">${l.description || ('ID #' + l.id)}</span>` +
                `<span class="fin-txn-ts">${ts}</span>` +
                `<span class="fin-txn-amt ${isCredit ? 'credit' : 'debit'}">${isCredit ? '+' : '-'}${_fmt(l.amount)}</span>`;
            list.appendChild(li);
        });
    }
    modal.classList.add('open');
    document.body.style.overflow = 'hidden';
}

function closeFinModal() {
    document.getElementById('finModal').classList.remove('open');
    document.body.style.overflow = '';
}
document.addEventListener('keydown', e => { if (e.key === 'Escape') closeFinModal(); });

// Period-aware trend chart
(function buildTrendChart() {
    const canvas = document.getElementById('finHourlyChart');
    if (!canvas) return;
    const slots    = _fin.hourly   || [];
    const mode     = _fin.chartMode || 'hourly';
    const period   = _fin.period   || 'today';

    // Build labels and data arrays depending on mode
    let labels, rev, exp;

    if (mode === 'hourly') {
        // 0-23 hours
        labels = Array.from({length:24}, (_, i) => i + ':00');
        rev    = new Array(24).fill(0);
        exp    = new Array(24).fill(0);
        slots.forEach(h => {
            const idx = parseInt(h.slot, 10);
            if (idx >= 0 && idx < 24) {
                rev[idx] = parseFloat(h.revenue);
                exp[idx] = parseFloat(h.expense);
            }
        });

    } else if (mode === 'daily' && period === 'week') {
        // Mon–Sun of current week as date strings
        const dayNames  = ['Mon','Tue','Wed','Thu','Fri','Sat','Sun'];
        const weekStart = new Date();
        weekStart.setDate(weekStart.getDate() - weekStart.getDay() + 1); // Monday
        labels = dayNames.map((_, i) => {
            const d = new Date(weekStart);
            d.setDate(d.getDate() + i);
            return dayNames[i] + ' ' + (d.getMonth()+1) + '/' + d.getDate();
        });
        rev = new Array(7).fill(0);
        exp = new Array(7).fill(0);
        slots.forEach(h => {
            const d    = new Date(h.slot);  // h.slot is 'YYYY-MM-DD'
            let dow    = d.getDay() - 1;    // 0=Mon … 6=Sun
            if (dow < 0) dow = 6;
            if (dow >= 0 && dow < 7) {
                rev[dow] += parseFloat(h.revenue);
                exp[dow] += parseFloat(h.expense);
            }
        });

    } else if (mode === 'daily' && period === 'month') {
        // Use the server-supplied day count for the selected month (not always JS's current month)
        const daysInMonth = _fin.monthDays || 31;
        const yr          = _fin.monthYear || new Date().getFullYear();
        const mo          = (_fin.monthNum  || (new Date().getMonth() + 1)) - 1; // 0-based for Date
        labels = Array.from({length: daysInMonth}, (_, i) => {
            const d = new Date(yr, mo, i + 1);
            return (d.getMonth()+1) + '/' + (i+1);   // "3/1", "3/2" … compact date labels
        });
        rev    = new Array(daysInMonth).fill(0);
        exp    = new Array(daysInMonth).fill(0);
        slots.forEach(h => {
            const idx = parseInt(h.slot, 10) - 1;  // DAY() is 1-based
            if (idx >= 0 && idx < daysInMonth) {
                rev[idx] += parseFloat(h.revenue);
                exp[idx] += parseFloat(h.expense);
            }
        });

    } else {
        // Monthly — Jan to Dec
        const monthNames = ['Jan','Feb','Mar','Apr','May','Jun','Jul','Aug','Sep','Oct','Nov','Dec'];
        labels = monthNames;
        rev    = new Array(12).fill(0);
        exp    = new Array(12).fill(0);
        slots.forEach(h => {
            const idx = parseInt(h.slot, 10) - 1;  // MONTH() is 1-based
            if (idx >= 0 && idx < 12) {
                rev[idx] += parseFloat(h.revenue);
                exp[idx] += parseFloat(h.expense);
            }
        });
    }

    new Chart(canvas, {
        type: 'bar',
        data: {
            labels,
            datasets: [
                { label: 'Revenue', data: rev, backgroundColor: 'rgba(34,197,94,.75)',
                  borderColor: '#16a34a', borderWidth: 1.5, borderRadius: 4, order: 2 },
                { label: 'Expenses', data: exp, backgroundColor: 'rgba(249,115,22,.65)',
                  borderColor: '#ea580c', borderWidth: 1.5, borderRadius: 4, order: 2 },
                { label: 'Net', data: rev.map((r,i) => r - exp[i]), type: 'line',
                  borderColor: '#6366f1', backgroundColor: 'rgba(99,102,241,.12)',
                  borderWidth: 2.5, pointRadius: 3, fill: true, tension: .35, order: 1 }
            ]
        },
        options: {
            responsive: true, maintainAspectRatio: true,
            plugins: {
                legend: { position: 'top', labels: { font: { size: 12 }, usePointStyle: true } },
                tooltip: {
                    callbacks: {
                        label: ctx => ctx.dataset.label + ': ' + _fmt(ctx.parsed.y)
                    }
                }
            },
            scales: {
                x: { grid: { display: false } },
                y: { ticks: { callback: v => '₱' + v.toLocaleString() }, beginAtZero: true }
            }
        }
    });
})();
</script>
EOJS;

// ── Owner — Log Order JS ──────────────────────────────────────────────────────
$extra_js .= '<script>' . "\n";
$extra_js .= 'const OLO_API = ' . json_encode(BASE_URL . 'dashboard/business_owner/api/log_backdated_order.php') . ';' . "\n";
$extra_js .= <<<'OLOJS'
const _oloBasket = {};

function _oloFmt(n) {
    return '₱' + parseFloat(n).toLocaleString('en-PH', {minimumFractionDigits:2,maximumFractionDigits:2});
}

function oloAdd(el) {
    const id    = parseInt(el.dataset.id, 10);
    const name  = el.dataset.name;
    const price = parseFloat(el.dataset.price);
    if (_oloBasket[id]) { _oloBasket[id].qty += 1; }
    else                { _oloBasket[id] = {id, name, price, qty:1}; }
    oloRender();
}

function oloRender() {
    const basket  = document.getElementById('olo-basket');
    const empty   = document.getElementById('olo-basket-empty');
    const counter = document.getElementById('olo-count');
    const totalEl = document.getElementById('olo-total');
    if (!basket) return;
    const keys = Object.keys(_oloBasket);
    basket.querySelectorAll('.olo-row').forEach(r => r.remove());
    if (!keys.length) {
        empty  && (empty.style.display  = '');
        counter && (counter.textContent = '0 items');
        totalEl && (totalEl.textContent = '₱0.00');
        return;
    }
    empty && (empty.style.display = 'none');
    let total = 0;
    keys.forEach(k => {
        const it = _oloBasket[k], lt = it.price * it.qty;
        total += lt;
        const row = document.createElement('div');
        row.className = 'olo-row';
        row.style.cssText = 'display:flex;align-items:center;gap:7px;padding:5px 0;border-bottom:1px solid #e0e7ff;font-size:.83rem;';
        row.innerHTML =
            `<div style="flex:1;font-weight:600;color:var(--neutral-800);">${it.name}</div>` +
            `<div style="display:flex;align-items:center;gap:4px;">` +
            `<button type="button" onclick="oloQty(${k},-1)" style="width:22px;height:22px;border-radius:50%;border:1.5px solid #c7d2fe;background:#fff;cursor:pointer;font-weight:700;color:#4338ca;">−</button>` +
            `<span style="min-width:20px;text-align:center;font-weight:700;">${it.qty}</span>` +
            `<button type="button" onclick="oloQty(${k},1)"  style="width:22px;height:22px;border-radius:50%;border:1.5px solid #c7d2fe;background:#fff;cursor:pointer;font-weight:700;color:#4338ca;">+</button>` +
            `</div>` +
            `<span style="font-weight:700;color:#4338ca;white-space:nowrap;min-width:68px;text-align:right;">${_oloFmt(lt)}</span>` +
            `<button type="button" onclick="oloRm(${k})" style="background:none;border:none;color:#ef4444;cursor:pointer;padding:0 3px;"><i class="fas fa-times"></i></button>`;
        basket.appendChild(row);
    });
    const count = keys.reduce((s,k) => s + _oloBasket[k].qty, 0);
    counter && (counter.textContent = count + ' item' + (count !== 1 ? 's' : ''));
    totalEl && (totalEl.textContent = _oloFmt(total));
}

function oloQty(id, d) {
    if (!_oloBasket[id]) return;
    _oloBasket[id].qty += d;
    if (_oloBasket[id].qty <= 0) delete _oloBasket[id];
    oloRender();
}
function oloRm(id) { delete _oloBasket[id]; oloRender(); }

// Search filter
(function() {
    const inp = document.getElementById('olo-search');
    if (!inp) return;
    inp.addEventListener('input', function() {
        const q = this.value.trim().toLowerCase();
        document.querySelectorAll('.olo-menu-row').forEach(row => {
            row.style.display = (!q || row.dataset.search.includes(q)) ? '' : 'none';
        });
    });
})();

async function oloSubmit() {
    const btn  = document.getElementById('olo-submit-btn');
    const keys = Object.keys(_oloBasket);
    if (!keys.length) { oloToast('Add at least one item.', true); return; }
    const date = document.getElementById('olo-date')?.value;
    if (!date) { oloToast('Please select an order date.', true); return; }
    btn.disabled = true;
    btn.innerHTML = '<i class="fas fa-spinner fa-spin"></i> Saving…';
    const items = keys.map(k => ({id:_oloBasket[k].id, qty:_oloBasket[k].qty}));
    const fd    = new FormData();
    fd.append('order_date',     date);
    fd.append('order_time',     document.getElementById('olo-time')?.value     || '12:00');
    fd.append('service_type',   document.getElementById('olo-service')?.value  || 'dine_in');
    fd.append('payment_method', document.getElementById('olo-payment')?.value  || 'cash');
    fd.append('customer_name',  document.getElementById('olo-customer')?.value || '');
    fd.append('notes',          document.getElementById('olo-notes')?.value    || '');
    fd.append('items',          JSON.stringify(items));
    try {
        const r = await fetch(OLO_API, {method:'POST', body:fd});
        const d = await r.json();
        if (d.success) {
            oloToast('✓ ' + d.message, false);
            Object.keys(_oloBasket).forEach(k => delete _oloBasket[k]);
            oloRender();
            ['olo-customer','olo-notes'].forEach(id => { const el=document.getElementById(id); if(el) el.value=''; });
        } else { oloToast('Error: ' + d.message, true); }
    } catch(e) { oloToast('Request failed: ' + e.message, true); }
    finally {
        btn.disabled = false;
        btn.innerHTML = '<i class="fas fa-save"></i> Save Order';
    }
}

function oloToast(msg, isErr) {
    const t = document.getElementById('owner-lo-toast');
    if (!t) return;
    t.textContent  = msg;
    t.style.display = '';
    t.style.background = isErr ? '#fee2e2' : '#dcfce7';
    t.style.color      = isErr ? '#991b1b' : '#166534';
    t.style.border     = '1px solid ' + (isErr ? '#fca5a5' : '#86efac');
    clearTimeout(t._tid);
    t._tid = setTimeout(() => { t.style.display = 'none'; }, 6000);
}
</script>
OLOJS;

require_once __DIR__ . '/../../includes/footer.php';
