<?php
require_once __DIR__ . '/../../config/app.php';
require_once __DIR__ . '/../../includes/MenuWorkflowService.php';
require_once __DIR__ . '/../../includes/RevenuePaymentService.php';
requireLogin();
requireRole([ROLE_CUSTOMER]);

$page_title   = 'Restaurant Details';
$page_heading = 'Restaurant Details';
$is_dashboard = true;
$user_id = $_SESSION['user_id'];
$rid = intval($_GET['id'] ?? 0);
$checkout_billing = (new RevenuePaymentService($conn))->billingSettings();
$checkout_platform_fee = [
    'enabled' => (bool)$checkout_billing['platform_fee_enabled'],
    'rate' => (float)$checkout_billing['platform_fee_rate'],
    'label' => (string)$checkout_billing['platform_fee_label'],
];

if (!$rid) {
    header('Location: ' . BASE_URL . 'dashboard/customer/restaurants.php');
    exit();
}

// ── Idempotent migration: staff_applications table ────────────────────────────
$conn->query("
CREATE TABLE IF NOT EXISTS `staff_applications` (
    `id`                INT UNSIGNED  NOT NULL AUTO_INCREMENT,
    `user_id`           INT(11)       NOT NULL,
    `restaurant_id`     INT(11)       NOT NULL,
    `application_type`  ENUM('applicant','existing') NOT NULL,
    `applied_role`      VARCHAR(60)   NOT NULL,
    `cover_letter_text` TEXT          DEFAULT NULL,
    `file_cover_letter` VARCHAR(500)  DEFAULT NULL,
    `file_resume`       VARCHAR(500)  DEFAULT NULL,
    `file_nbi`          VARCHAR(500)  DEFAULT NULL,
    `file_medical`      VARCHAR(500)  DEFAULT NULL,
    `file_gov_id`       VARCHAR(500)  DEFAULT NULL,
    `file_proof_employ` VARCHAR(500)  DEFAULT NULL,
    `status`            ENUM('pending','approved','rejected') NOT NULL DEFAULT 'pending',
    `owner_notes`       TEXT          DEFAULT NULL,
    `reviewed_by`       INT(11)       DEFAULT NULL,
    `reviewed_at`       DATETIME      DEFAULT NULL,
    `created_at`        TIMESTAMP     NOT NULL DEFAULT CURRENT_TIMESTAMP,
    `updated_at`        TIMESTAMP     NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (`id`),
    KEY `idx_sa_user`       (`user_id`),
    KEY `idx_sa_restaurant` (`restaurant_id`),
    KEY `idx_sa_status`     (`status`),
    CONSTRAINT `sa_user_fk`       FOREIGN KEY (`user_id`)       REFERENCES `users`(`id`)              ON DELETE CASCADE,
    CONSTRAINT `sa_restaurant_fk` FOREIGN KEY (`restaurant_id`) REFERENCES `halal_restaurants`(`id`)  ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
");
// Widen applied_role from ENUM to VARCHAR if the table already existed with the old definition
$conn->query("ALTER TABLE `staff_applications` MODIFY COLUMN `applied_role` VARCHAR(60) NOT NULL");

// ── Log page view for Business Visits analytics ─────────────────────────────
logBusinessView($conn, $rid);

// ── Ensure HaCeRa publish columns exist before SELECT hr.* ──────────────────
db_add_column($conn, 'halal_restaurants', 'hacera_hrp',          'DECIMAL(5,1) DEFAULT NULL');
db_add_column($conn, 'halal_restaurants', 'hacera_band',         'VARCHAR(30)  DEFAULT NULL');
db_add_column($conn, 'halal_restaurants', 'hacera_published_at', 'DATETIME     DEFAULT NULL');

// ── Load restaurant ───────────────────────────────────────────────────────────
$r_stmt = $conn->prepare("
    SELECT hr.*, u.full_name AS owner_name,
           hc.certificate_number, hc.issue_date, hc.expiry_date,
           hc.status AS cert_status, hc.certifying_body, hc.certificate_file_path
    FROM halal_restaurants hr
    JOIN users u ON hr.business_owner_id = u.id
    LEFT JOIN halal_certificates hc
        ON  hc.id = hr.certificate_id
        OR  (hc.application_id = (
                SELECT ha.id FROM hdp_applications ha
                WHERE ha.business_owner_id = hr.business_owner_id
                ORDER BY ha.created_at DESC LIMIT 1
            )
            AND hc.payment_status = 'paid'
            AND hc.status NOT IN ('rejected','revoked','expired','suspended'))
    WHERE hr.id = ? AND hr.is_active = 1
    LIMIT 1
");
$r_stmt->bind_param("i", $rid);
$r_stmt->execute();
$r = $r_stmt->get_result()->fetch_assoc();

if (!$r) {
    header('Location: ' . BASE_URL . 'dashboard/customer/restaurants.php');
    exit();
}

$page_title   = htmlspecialchars($r['restaurant_name']) . ' — Restaurant Details';
$page_heading = htmlspecialchars($r['restaurant_name']);
$breadcrumbs  = [
    ['label' => 'Dashboard',    'url' => BASE_URL . 'dashboard/customer/'],
    ['label' => 'Restaurants',  'url' => BASE_URL . 'dashboard/customer/restaurants.php'],
    ['label' => $r['restaurant_name']],
];

// ── Menu items (all items — unavailable shown with Sold Out badge) ────────────
$menu = $conn->query("SELECT mi.* FROM menu_items mi WHERE mi.restaurant_id = $rid AND " . MenuWorkflowService::customerEligibilitySql('mi') . " ORDER BY mi.is_available DESC, mi.category, mi.name")->fetch_all(MYSQLI_ASSOC);

// ── Ingredient transparency: load recipe ingredients for each menu item ───────
$menu_ingredients = [];
$structured_menu_ids = [];
$recipe_table = $conn->query("SHOW TABLES LIKE 'menu_recipes'");
$recipe_tables_ready = $recipe_table && $recipe_table->num_rows > 0;
if ($recipe_tables_ready && !empty($menu)) {
    $menu_ids = implode(',', array_map(fn($m) => (int)$m['id'], $menu));
    $active_recipe_res = $conn->query("SELECT menu_item_id FROM menu_recipes WHERE restaurant_id = $rid AND recipe_status = 'active' AND menu_item_id IN ($menu_ids)");
    if ($active_recipe_res) {
        while ($active_recipe = $active_recipe_res->fetch_assoc()) {
            $structured_menu_ids[(int)$active_recipe['menu_item_id']] = true;
        }
    }
    $published_res = $conn->query("SELECT mr.menu_item_id,
            ri.ingredient_name,
            ki.halal_status,
            COALESCE(ic_child.name, '') AS category_name,
            COALESCE(ic_parent.name, '') AS parent_category_name
        FROM menu_recipes mr
        JOIN recipe_ingredients ri ON ri.recipe_id = mr.id AND ri.restaurant_id = mr.restaurant_id
        JOIN kitchen_inventory ki ON ki.id = ri.inventory_id AND ki.restaurant_id = mr.restaurant_id
        LEFT JOIN ingredients_category ic_child ON ki.category_id = ic_child.id
        LEFT JOIN ingredients_category ic_parent ON ic_child.parent_id = ic_parent.id
        WHERE mr.restaurant_id = $rid
          AND mr.menu_item_id IN ($menu_ids)
          AND mr.recipe_status = 'active'
          AND mr.customer_visibility_status = 'published'
          AND ri.mapping_status = 'confirmed'
          AND ri.inventory_id IS NOT NULL
        ORDER BY mr.menu_item_id, ri.id");
    if ($published_res) {
        while ($ing = $published_res->fetch_assoc()) {
            $menu_ingredients[(int)$ing['menu_item_id']][] = $ing;
        }
    }
}
$legacy_menu_ids = array_values(array_filter(array_map(fn($m) => (int)$m['id'], $menu), fn($id) => empty($structured_menu_ids[$id])));
if (!empty($legacy_menu_ids)) {
    $legacy_ids_sql = implode(',', $legacy_menu_ids);
    $ing_res = $conn->query("SELECT mri.menu_item_id,
            ki.ingredient_name,
            ki.halal_status,
            COALESCE(ic_child.name, '') AS category_name,
            COALESCE(ic_parent.name, '') AS parent_category_name
        FROM menu_recipe_ingredients mri
        JOIN kitchen_inventory ki ON mri.inventory_id = ki.id AND ki.restaurant_id = $rid
        LEFT JOIN ingredients_category ic_child ON ki.category_id = ic_child.id
        LEFT JOIN ingredients_category ic_parent ON ic_child.parent_id = ic_parent.id
        WHERE mri.menu_item_id IN ($legacy_ids_sql)
        ORDER BY mri.menu_item_id, ki.ingredient_name");
    if ($ing_res) {
        while ($ing = $ing_res->fetch_assoc()) {
            $menu_ingredients[(int)$ing['menu_item_id']][] = $ing;
        }
    }
}
// Map halal_status to a human-readable label (guards against empty/null)
function halalLabel(string $status): string {
    return match(trim($status)) {
        'halal'          => 'Halal (Chef-verified)',
        'self_certified' => 'Supplier declaration recorded',
        'fresh_produce'  => 'Fresh ingredient record',
        'non_halal'      => 'Ingredient flagged for review',
        default          => 'Evidence review pending',
    };
}
function halalColor(string $status): string {
    return match(trim($status)) {
        'halal'          => '#16a34a',
        'self_certified' => '#d97706',
        'fresh_produce'  => '#65a30d',
        'non_halal'      => '#dc2626',
        default          => '#d97706',
    };
}

// ── Reviews ───────────────────────────────────────────────────────────────────
$reviews = $conn->query("
    SELECT rv.*, u.full_name, u.avatar
    FROM reviews rv JOIN users u ON rv.customer_id = u.id
    WHERE rv.restaurant_id = $rid
    ORDER BY rv.created_at DESC LIMIT 10
")->fetch_all(MYSQLI_ASSOC);

// Compute live aggregates from the fetched rows (never trust the denormalized column)
$live_review_count = count($reviews);
$live_avg_rating   = $live_review_count > 0
    ? round(array_sum(array_column($reviews, 'rating')) / $live_review_count, 1)
    : 0.0;

// ── Existing staff application by this user for this restaurant ───────────────
$my_app_stmt = $conn->prepare(
    "SELECT id, applied_role, application_type, status, created_at
     FROM staff_applications WHERE user_id = ? AND restaurant_id = ? ORDER BY created_at DESC LIMIT 1"
);
$my_app_stmt->bind_param("ii", $user_id, $rid);
$my_app_stmt->execute();
$my_app = $my_app_stmt->get_result()->fetch_assoc();

// ── Available staff roles for this restaurant (platform + custom) ─────────────
// Excludes 'owner' since customers cannot apply for the owner role.
// Seed platform roles lazily so the query never returns empty on a fresh install.
$conn->query("CREATE TABLE IF NOT EXISTS `restaurant_staff_roles` (
    `id`          SMALLINT UNSIGNED NOT NULL AUTO_INCREMENT,
    `role_key`    VARCHAR(30)  NOT NULL,
    `role_label`  VARCHAR(60)  NOT NULL,
    `description` VARCHAR(255) DEFAULT NULL,
    PRIMARY KEY (`id`),
    UNIQUE KEY `uq_role_key` (`role_key`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
$conn->query("INSERT IGNORE INTO `restaurant_staff_roles` (`role_key`,`role_label`,`description`) VALUES
    ('owner',       'Business Owner',     'Full access: analytics, financials, staff management.'),
    ('ops_manager', 'Operations Manager', 'Store profile, menu management, and Halal compliance.'),
    ('cashier',     'Cashier',            'POS order intake and payment processing only.'),
    ('chef',        'Chef / Head Cook',   'Manages kitchen inventory, requisitions, and menu recipe mapping.')");
db_add_column($conn, 'restaurant_staff_roles', 'restaurant_id', 'INT(11) DEFAULT NULL');
db_add_column($conn, 'restaurant_staff_roles', 'is_custom',     'TINYINT(1) NOT NULL DEFAULT 0');

$avail_roles_stmt = $conn->prepare("
    SELECT role_key, role_label
    FROM   restaurant_staff_roles
    WHERE  role_key <> 'owner'
      AND  (is_custom = 0 OR restaurant_id = ?)
    ORDER  BY is_custom ASC, role_label ASC
");
// If restaurant_staff_roles doesn't exist yet, getActiveBusinessRoles will create it;
// fall back to the three hardcoded platform roles in that edge case.
$avail_roles = [];
if ($avail_roles_stmt) {
    $avail_roles_stmt->bind_param("i", $rid);
    $avail_roles_stmt->execute();
    $avail_roles = $avail_roles_stmt->get_result()->fetch_all(MYSQLI_ASSOC);
    $avail_roles_stmt->close();
}
if (empty($avail_roles)) {
    $avail_roles = [
        ['role_key' => 'ops_manager', 'role_label' => 'Operations Manager'],
        ['role_key' => 'cashier',     'role_label' => 'Cashier'],
        ['role_key' => 'chef',        'role_label' => 'Chef / Head Cook'],
    ];
}
// Build a quick lookup map: role_key → role_label
$avail_roles_map = array_column($avail_roles, 'role_label', 'role_key');

$service_badges = [];
if (!empty($r['services_offered'])) {
    $service_badges = json_decode($r['services_offered'], true) ?: [];
}

require_once __DIR__ . '/../../includes/header.php';
?>

<style>
/* ══════════════════════════════════════════════════════════════════════════
   Restaurant Detail View — Standardized Layout
   ══════════════════════════════════════════════════════════════════════════ */

/* Main 2-column layout wrapper */
.restaurant-detail-layout {
    display: grid;
    grid-template-columns: 2fr 1fr;
    gap: 24px;
    margin-bottom: 32px;
}

/* Responsive breakpoint: stack columns on tablets and mobile */
@media (max-width: 968px) {
    .restaurant-detail-layout {
        grid-template-columns: 1fr;
        gap: 20px;
    }
}

/* Hero banner enhancements */
.restaurant-hero-banner {
    height: 220px;
    background: linear-gradient(135deg, var(--primary-900), var(--primary-700));
    position: relative;
    overflow: hidden;
}

.restaurant-hero-overlay {
    position: absolute;
    inset: 0;
    background: linear-gradient(to top, rgba(0, 0, 0, 0.5) 0%, transparent 60%);
    pointer-events: none;
}

/* Order CTA button hover states */
.order-cta-primary {
    background: linear-gradient(135deg, #16a34a, #15803d);
    color: #fff;
    padding: 9px 20px;
    border: none;
    border-radius: 999px;
    font-size: 0.88rem;
    font-weight: 700;
    cursor: pointer;
    display: inline-flex;
    align-items: center;
    gap: 8px;
    box-shadow: 0 4px 12px rgba(22, 163, 74, 0.25);
    transition: all 0.2s;
}

.order-cta-primary:hover {
    transform: translateY(-1px);
    box-shadow: 0 6px 16px rgba(22, 163, 74, 0.35);
}

.order-cta-primary:active {
    transform: translateY(0);
}

/* Smooth scroll offset for anchored sections */
#menu-section {
    scroll-margin-top: 80px;
}

/* Service badges enhancement */
.service-badges-container {
    display: flex;
    flex-wrap: wrap;
    gap: 8px;
    align-items: center;
    margin-top: 4px;
}

@media (max-width: 768px) {
    .service-badges-container {
        flex-direction: column;
        align-items: flex-start;
    }
    
    .order-cta-primary {
        margin-left: 0 !important;
        width: 100%;
        justify-content: center;
    }
}
</style>

<a href="<?= BASE_URL ?>dashboard/customer/restaurants.php"
   style="display:inline-flex;align-items:center;gap:6px;font-size:.85rem;font-weight:600;
          color:var(--primary-600);text-decoration:none;margin-bottom:20px;
          padding:7px 14px;background:#fff;border:1.5px solid var(--neutral-200);
          border-radius:var(--radius-md);transition:all .15s;"
   onmouseover="this.style.background='var(--primary-50)';this.style.borderColor='var(--primary-300)'"
   onmouseout="this.style.background='#fff';this.style.borderColor='var(--neutral-200)'">
    <i class="fas fa-arrow-left" style="font-size:.78rem;"></i> Back to Restaurants
</a>

<!-- ── Alert from redirect ─────────────────────────────────────────────────── -->
<?php if (isset($_GET['success'])): ?>
<div class="alert alert-success">
    <i class="fas fa-check-circle"></i>
    <span><?= htmlspecialchars($_GET['success']) ?></span>
    <button class="close-alert"><i class="fas fa-times"></i></button>
</div>
<?php elseif (isset($_GET['error'])): ?>
<div class="alert alert-danger">
    <i class="fas fa-exclamation-circle"></i>
    <span><?= htmlspecialchars($_GET['error']) ?></span>
    <button class="close-alert"><i class="fas fa-times"></i></button>
</div>
<?php endif; ?>

<div class="restaurant-detail-layout">
<div>
<!-- ── Restaurant hero card ─────────────────────────────────────────────────── -->
<div class="card" style="margin-bottom:24px;overflow:hidden;">
    <div style="height:220px;background:linear-gradient(135deg,var(--primary-900),var(--primary-700));
                position:relative;overflow:hidden;">
        <?php if (!empty($r['image_path'])): ?>
        <img src="<?= BASE_URL ?>uploads/<?= htmlspecialchars($r['image_path']) ?>"
             alt="<?= htmlspecialchars($r['restaurant_name']) ?>"
             style="width:100%;height:100%;object-fit:cover;position:absolute;inset:0;">
        <?php else: ?>
        <div style="position:absolute;right:-30px;top:-30px;width:180px;height:180px;border-radius:50%;background:rgba(255,255,255,.05);pointer-events:none;"></div>
        <div style="position:absolute;left:40px;bottom:-40px;width:120px;height:120px;border-radius:50%;background:rgba(255,255,255,.04);pointer-events:none;"></div>
        <div style="position:absolute;inset:0;display:flex;align-items:center;justify-content:center;">
            <i class="fas fa-store" style="font-size:4rem;color:rgba(255,255,255,.25)"></i>
        </div>
        <?php endif; ?>
        <!-- Gradient overlay -->
        <div style="position:absolute;inset:0;background:linear-gradient(to top,rgba(0,0,0,.5) 0%,transparent 60%);pointer-events:none;"></div>
        <!-- Halal badge -->
        <?php if ($r['certificate_number']): ?>
        <div onclick="openCertModal()" style="position:absolute;top:16px;right:16px;background:rgba(255,255,255,.95);padding:7px 14px;
                    border-radius:999px;display:flex;align-items:center;gap:7px;font-weight:700;font-size:.8rem;
                    color:#166534;box-shadow:var(--shadow-md);cursor:pointer;transition:transform .15s;"
                    onmouseover="this.style.transform='scale(1.04)'" onmouseout="this.style.transform='scale(1)'">
            <i class="fas fa-circle-check" style="color:#16a34a"></i> Halal Certified
        </div>
        <?php endif; ?>
        <!-- Restaurant name overlay on image -->
        <div style="position:absolute;bottom:16px;left:20px;">
            <h2 style="font-family:var(--font-display);font-weight:800;font-size:1.6rem;color:#fff;margin:0;text-shadow:0 1px 6px rgba(0,0,0,.4);">
                <?= htmlspecialchars($r['restaurant_name']) ?>
            </h2>
            <div style="display:flex;gap:12px;flex-wrap:wrap;font-size:.82rem;color:rgba(255,255,255,.85);margin-top:4px;">
                <?php if ($r['address'] ?? ''): ?>
                <span><i class="fas fa-map-marker-alt" style="margin-right:4px;"></i><?= htmlspecialchars($r['city'] ?? $r['address']) ?></span>
                <?php endif; ?>
                <?php if ($r['cuisine_type']): ?>
                <span><i class="fas fa-utensils" style="margin-right:4px;"></i><?= htmlspecialchars($r['cuisine_type']) ?></span>
                <?php endif; ?>
                <?php if ($r['operating_hours']): ?>
                <span><i class="fas fa-clock" style="margin-right:4px;"></i><?= htmlspecialchars($r['operating_hours']) ?></span>
                <?php endif; ?>
            </div>
        </div>
    </div>
    <div class="card-body">
        <?php if ($r['description']): ?>
        <p style="color:var(--neutral-600);line-height:1.7;margin-bottom:14px;">
            <?= nl2br(htmlspecialchars($r['description'])) ?></p>
        <?php endif; ?>
        <?php if (!empty($service_badges)): ?>
        <div class="service-badges-container">
            <?php foreach ($service_badges as $sb): ?>
            <span style="background:#eff6ff;color:#1d4ed8;padding:5px 12px;
                         border-radius:999px;font-size:.78rem;font-weight:600;">
                <?= htmlspecialchars($sb) ?>
            </span>
            <?php endforeach; ?>
            
            <!-- Order Now CTA Button -->
            <button type="button" 
                    onclick="scrollToMenu()"
                    class="order-cta-primary"
                    style="margin-left:auto;">
                <i class="fas fa-shopping-bag"></i>
                <span>Place an Order</span>
            </button>
        </div>
        <?php else: ?>
        <!-- Show Order CTA even when no service badges -->
        <div style="margin-top:14px;">
            <button type="button" 
                    onclick="scrollToMenu()"
                    class="order-cta-primary">
                <i class="fas fa-shopping-bag"></i>
                <span>Place an Order</span>
            </button>
        </div>
        <?php endif; ?>

        <?php if (!empty($r['hacera_hrp'])): ?>
        <?php
            $hrp_v = (float)$r['hacera_hrp'];
            $hb    = htmlspecialchars($r['hacera_band'] ?? '');
            [$hc, $hbg, $hborder] = match(true) {
                $hrp_v >= 90 => ['#16a34a', '#dcfce7', '#bbf7d0'],
                $hrp_v >= 70 => ['#2563eb', '#dbeafe', '#bfdbfe'],
                $hrp_v >= 50 => ['#d97706', '#fef3c7', '#fde68a'],
                $hrp_v >= 25 => ['#ea580c', '#ffedd5', '#fed7aa'],
                default      => ['#dc2626', '#fee2e2', '#fca5a5'],
            };
        ?>
        <div style="margin-top:18px;padding:16px 18px;border-radius:12px;
                    background:<?= $hbg ?>;border:1.5px solid <?= $hborder ?>;">
            <!-- Header row -->
            <div style="display:flex;align-items:center;justify-content:space-between;margin-bottom:10px;">
                <span style="font-size:.85rem;font-weight:800;color:<?= $hc ?>;
                             display:flex;align-items:center;gap:8px;">
                    <i class="fas fa-clipboard-check"></i>
                    Halal Readiness Assessment (HaCeRa)
                </span>
                <span style="font-size:1.25rem;font-weight:900;color:<?= $hc ?>;"><?= $hrp_v ?>%</span>
            </div>
            <!-- Progress bar -->
            <div style="height:10px;border-radius:999px;background:rgba(0,0,0,.1);overflow:hidden;margin-bottom:8px;">
                <div style="height:100%;width:<?= $hrp_v ?>%;background:<?= $hc ?>;border-radius:999px;"></div>
            </div>
            <!-- Band label + date -->
            <div style="display:flex;justify-content:space-between;align-items:center;">
                <span style="font-size:.78rem;font-weight:700;color:<?= $hc ?>;
                             display:flex;align-items:center;gap:6px;">
                    <?php
                        $icon = match(true) {
                            $hrp_v >= 90 => 'fa-circle-check',
                            $hrp_v >= 70 => 'fa-circle-half-stroke',
                            $hrp_v >= 50 => 'fa-circle-dot',
                            $hrp_v >= 25 => 'fa-circle-exclamation',
                            default      => 'fa-circle-xmark',
                        };
                    ?>
                    <i class="fas <?= $icon ?>" style="font-size:.72rem;"></i>
                    <?= $hb ?>
                </span>
                <?php if ($r['hacera_published_at']): ?>
                <span style="font-size:.7rem;color:<?= $hc ?>;opacity:.7;">
                    <i class="fas fa-rotate" style="font-size:.6rem;"></i>
                    Updated <?= date('M j, Y', strtotime($r['hacera_published_at'])) ?>
                </span>
                <?php endif; ?>
            </div>
        </div>
        <?php endif; ?>
    </div>
</div>

<?php
// ── Credibility Score + Traffic Panel ────────────────────────────────────────
ensureCredibilitySchema($conn);
$cred = getCredibilityScore($conn, $rid);
$traffic = getBusinessTrafficStats($conn, $rid);
?>
<div class="card" style="margin-bottom:24px;overflow:hidden;border:1.5px solid <?= $cred['border'] ?>;">
    <!-- Panel header -->
    <div style="background:linear-gradient(135deg,#0f172a,#1e293b);padding:16px 20px;
                display:flex;align-items:center;justify-content:space-between;flex-wrap:wrap;gap:10px;">
        <div style="display:flex;align-items:center;gap:12px;">
            <div style="width:40px;height:40px;background:<?= $cred['bg'] ?>;border-radius:10px;
                        display:flex;align-items:center;justify-content:center;flex-shrink:0;">
                <i class="<?= $cred['icon'] ?>" style="color:<?= $cred['color'] ?>;font-size:1rem;"></i>
            </div>
            <div>
                <div style="font-size:.7rem;font-weight:700;letter-spacing:.07em;text-transform:uppercase;
                            color:#94a3b8;margin-bottom:2px;">Credibility & Traffic</div>
                <div style="font-size:.95rem;font-weight:800;color:#fff;">
                    Restaurant Credibility Score
                </div>
            </div>
        </div>
        <!-- Score badge -->
        <?php if ($cred['total_score'] > 0): ?>
        <div style="display:flex;align-items:center;gap:8px;">
            <span style="background:<?= $cred['bg'] ?>;color:<?= $cred['color'] ?>;
                         padding:5px 14px;border-radius:999px;font-size:.82rem;font-weight:800;
                         border:1.5px solid <?= $cred['border'] ?>;">
                <i class="<?= $cred['icon'] ?>" style="margin-right:4px;"></i>
                <?= htmlspecialchars($cred['label']) ?>
            </span>
            <span style="font-size:1.6rem;font-weight:900;color:#fff;font-family:var(--font-display);">
                <?= number_format($cred['total_score'], 1) ?>
                <span style="font-size:.8rem;color:#94a3b8;font-weight:500;">/100</span>
            </span>
        </div>
        <?php endif; ?>
    </div>

    <div class="card-body" style="padding:20px;">
        <?php if ($cred['total_score'] > 0): ?>

        <!-- Score bar -->
        <div style="margin-bottom:18px;">
            <div style="height:10px;background:#e2e8f0;border-radius:999px;overflow:hidden;">
                <div style="height:100%;width:<?= min($cred['total_score'],100) ?>%;
                            background:linear-gradient(90deg,<?= $cred['color'] ?>,<?= $cred['border'] ?>);
                            border-radius:999px;transition:width 1s ease;"></div>
            </div>
            <!-- Tier stars + label -->
            <div style="display:flex;align-items:center;gap:6px;margin-top:7px;">
                <?php for ($s=1;$s<=3;$s++): ?>
                <i class="fas fa-star" style="font-size:.75rem;color:<?= $s<=$cred['stars']?$cred['color']:'#e2e8f0' ?>;"></i>
                <?php endfor; ?>
                <span style="font-size:.78rem;font-weight:700;color:<?= $cred['color'] ?>;">
                    <?= htmlspecialchars($cred['label']) ?>
                </span>
            </div>
        </div>

        <!-- Component breakdown (if available) -->
        <?php if (!empty($cred['components'])): ?>
        <div style="display:grid;grid-template-columns:repeat(2,1fr);gap:10px;margin-bottom:18px;">
            <?php foreach ([
                ['foot_traffic','Foot Traffic & Dine-ins','fas fa-utensils','#ea580c','#fff7ed','#fed7aa','35%'],
                ['engagement',  'Customer Engagement',    'fas fa-star',    '#d97706','#fef3c7','#fde68a','25%'],
                ['compliance',  'Halal Compliance',       'fas fa-certificate','#16a34a','#f0fdf4','#bbf7d0','25%'],
                ['popularity',  'Platform Popularity',    'fas fa-fire',    '#7c3aed','#f5f3ff','#ddd6fe','15%'],
            ] as [$key,$label,$icon,$col,$bg,$brd,$weight]):
                $val = (float)($cred['components'][$key] ?? 0);
            ?>
            <div style="background:<?= $bg ?>;border:1px solid <?= $brd ?>;border-radius:10px;padding:12px 14px;">
                <div style="display:flex;align-items:center;justify-content:space-between;margin-bottom:6px;">
                    <span style="font-size:.7rem;font-weight:700;color:<?= $col ?>;display:flex;align-items:center;gap:5px;">
                        <i class="<?= $icon ?>" style="font-size:.65rem;"></i><?= $label ?>
                    </span>
                    <span style="font-size:.62rem;color:<?= $col ?>;opacity:.7;"><?= $weight ?> weight</span>
                </div>
                <div style="font-size:1.1rem;font-weight:900;color:<?= $col ?>;line-height:1;margin-bottom:5px;">
                    <?= number_format($val,1) ?><span style="font-size:.68rem;font-weight:500;color:<?= $col ?>;opacity:.7;">/100</span>
                </div>
                <div style="height:4px;background:rgba(0,0,0,.07);border-radius:999px;overflow:hidden;">
                    <div style="height:100%;width:<?= min($val,100) ?>%;background:<?= $col ?>;border-radius:999px;"></div>
                </div>
            </div>
            <?php endforeach; ?>
        </div>
        <?php endif; ?>
        <?php else: ?>
        <p style="color:var(--neutral-400);font-size:.875rem;text-align:center;padding:12px 0;">
            Credibility score will appear once this restaurant has sufficient activity data.
        </p>
        <?php endif; ?>

        <!-- ── Separated traffic metrics ────────────────────────────────── -->
        <div style="border-top:1px solid var(--neutral-100);padding-top:16px;">
            <div style="font-size:.72rem;font-weight:700;text-transform:uppercase;letter-spacing:.07em;
                        color:var(--neutral-500);margin-bottom:12px;">
                <i class="fas fa-chart-bar" style="margin-right:5px;color:var(--primary-600);"></i>
                Traffic Breakdown — Digital vs. Physical
            </div>
            <div style="display:grid;grid-template-columns:1fr 1fr;gap:12px;">
                <!-- Profile Views (digital engagement) -->
                <div style="background:#f0f9ff;border:1.5px solid #bae6fd;border-radius:12px;padding:14px 16px;">
                    <div style="display:flex;align-items:center;gap:8px;margin-bottom:8px;">
                        <div style="width:32px;height:32px;background:#0891b2;border-radius:8px;
                                    display:flex;align-items:center;justify-content:center;flex-shrink:0;">
                            <i class="fas fa-eye" style="color:#fff;font-size:.8rem;"></i>
                        </div>
                        <div>
                            <div style="font-size:.7rem;font-weight:700;text-transform:uppercase;
                                        letter-spacing:.06em;color:#0891b2;">Profile Views</div>
                            <div style="font-size:.62rem;color:#94a3b8;">Digital engagement</div>
                        </div>
                    </div>
                    <div style="display:flex;justify-content:space-between;align-items:flex-end;">
                        <div>
                            <div style="font-size:1.5rem;font-weight:900;color:#0c4a6e;line-height:1;">
                                <?= number_format($traffic['online_views_month']) ?>
                            </div>
                            <div style="font-size:.62rem;color:#94a3b8;">this month</div>
                        </div>
                        <div style="text-align:right;">
                            <div style="font-size:.88rem;font-weight:700;color:#0891b2;">
                                <?= number_format($traffic['online_views_today']) ?>
                            </div>
                            <div style="font-size:.6rem;color:#94a3b8;">today</div>
                        </div>
                    </div>
                    <div style="margin-top:8px;font-size:.68rem;color:#0891b2;background:rgba(8,145,178,.08);
                                border-radius:6px;padding:4px 8px;">
                        <i class="fas fa-info-circle" style="margin-right:3px;"></i>
                        Measures marketing reach &amp; online interest
                    </div>
                </div>

                <!-- Verified Dine-ins (physical foot traffic) -->
                <div style="background:#fff7ed;border:1.5px solid #fed7aa;border-radius:12px;padding:14px 16px;">
                    <div style="display:flex;align-items:center;gap:8px;margin-bottom:8px;">
                        <div style="width:32px;height:32px;background:#ea580c;border-radius:8px;
                                    display:flex;align-items:center;justify-content:center;flex-shrink:0;">
                            <i class="fas fa-utensils" style="color:#fff;font-size:.8rem;"></i>
                        </div>
                        <div>
                            <div style="font-size:.7rem;font-weight:700;text-transform:uppercase;
                                        letter-spacing:.06em;color:#ea580c;">Verified Dine-ins</div>
                            <div style="font-size:.62rem;color:#94a3b8;">Physical foot traffic</div>
                        </div>
                    </div>
                    <div style="display:flex;justify-content:space-between;align-items:flex-end;">
                        <div>
                            <div style="font-size:1.5rem;font-weight:900;color:#7c2d12;line-height:1;">
                                <?= number_format($traffic['dine_in_month']) ?>
                            </div>
                            <div style="font-size:.62rem;color:#94a3b8;">this month</div>
                        </div>
                        <div style="text-align:right;">
                            <div style="font-size:.88rem;font-weight:700;color:#ea580c;">
                                <?= number_format($traffic['dine_in_today']) ?>
                            </div>
                            <div style="font-size:.6rem;color:#94a3b8;">today</div>
                        </div>
                    </div>
                    <div style="margin-top:8px;font-size:.68rem;color:#ea580c;background:rgba(234,88,12,.08);
                                border-radius:6px;padding:4px 8px;">
                        <i class="fas fa-trophy" style="margin-right:3px;"></i>
                        Primary driver of Credibility Score
                    </div>
                </div>
            </div>

            <!-- Traffic source bar -->
            <?php if ($traffic['total_visits_month'] > 0): ?>
            <div style="margin-top:12px;">
                <div style="display:flex;justify-content:space-between;font-size:.72rem;
                            color:var(--neutral-500);margin-bottom:4px;">
                    <span>Digital <?= $traffic['online_pct'] ?>%</span>
                    <span>Physical <?= $traffic['dine_in_pct'] ?>%</span>
                </div>
                <div style="height:8px;border-radius:999px;overflow:hidden;display:flex;">
                    <div style="width:<?= $traffic['online_pct'] ?>%;background:#0891b2;border-radius:999px 0 0 999px;transition:width .8s ease;"></div>
                    <div style="flex:1;background:#ea580c;border-radius:0 999px 999px 0;"></div>
                </div>
            </div>
            <?php endif; ?>
        </div>
    </div>
</div>

<!-- ── Menu ──────────────────────────────────────────────────────────────────── -->
<div id="menu-section" class="card" style="margin-bottom:24px;scroll-margin-top:80px;">
    <div class="card-header" style="display:flex;align-items:center;justify-content:space-between;flex-wrap:wrap;gap:10px;">
        <h3 style="margin:0;"><i class="fas fa-book-open" style="color:var(--accent-500);margin-right:8px"></i> Menu</h3>
        <!-- Secondary Order CTA in menu header -->
        <button type="button" 
                onclick="openOrderModal()"
                class="btn btn-primary btn-sm"
                style="display:flex;align-items:center;gap:6px;">
            <i class="fas fa-cart-plus"></i>
            <span>Start Order</span>
        </button>
    </div>
    <div class="card-body">
        <?php if (empty($menu)): ?>
        <p style="color:var(--neutral-500);text-align:center;padding:20px">Menu coming soon!</p>
        <?php else: ?>
            <?php $cats = []; foreach ($menu as $mi) $cats[$mi['category'] ?? 'General'][] = $mi; ?>
            <?php foreach ($cats as $cat => $items): ?>
            <h4 style="font-family:var(--font-display);font-weight:700;margin:16px 0 12px;color:var(--neutral-700)">
                <?= htmlspecialchars($cat) ?>
            </h4>
            <?php foreach ($items as $mi): ?>
            <div style="display:flex;gap:14px;padding:14px;margin-bottom:12px;background:#f8fafc;
                        border:1px solid #e2e8f0;border-radius:12px;align-items:flex-start;
                        <?= (int)($mi['is_available'] ?? 1) === 0 ? 'opacity:.65;' : '' ?>">
                <!-- Image / icon -->
                <div style="flex-shrink:0;position:relative;">
                    <?php if (!empty($mi['image_path'])): ?>
                    <img src="<?= BASE_URL ?>uploads/<?= htmlspecialchars($mi['image_path']) ?>"
                         style="width:72px;height:72px;object-fit:cover;border-radius:10px;
                                <?= (int)($mi['is_available'] ?? 1) === 0 ? 'filter:grayscale(50%)' : '' ?>"
                         alt="<?= htmlspecialchars($mi['name']) ?>">
                    <?php else: ?>
                    <div style="width:72px;height:72px;background:#e2e8f0;border-radius:10px;
                                display:flex;align-items:center;justify-content:center;flex-shrink:0">
                        <i class="fas fa-utensils" style="color:#94a3b8;font-size:1.4rem"></i>
                    </div>
                    <?php endif; ?>
                    <?php if ((int)($mi['is_available'] ?? 1) === 0): ?>
                    <span style="position:absolute;bottom:-6px;left:50%;transform:translateX(-50%);
                                 background:#dc2626;color:#fff;font-size:.58rem;font-weight:800;
                                 padding:2px 6px;border-radius:20px;white-space:nowrap;letter-spacing:.03em;">
                        SOLD OUT
                    </span>
                    <?php endif; ?>
                </div>

                <!-- Info -->
                <div style="flex:1;min-width:0;">
                    <div style="font-weight:700;font-size:.95rem;margin-bottom:2px">
                        <?= htmlspecialchars($mi['name']) ?>
                    </div>
                    <?php if ($mi['description']): ?>
                    <div style="font-size:.8rem;color:var(--neutral-500);margin-bottom:4px">
                        <?= htmlspecialchars($mi['description']) ?>
                    </div>
                    <?php endif; ?>
                    <div style="display:flex;align-items:center;gap:10px;margin-top:4px;">
                        <div style="font-weight:700;color:var(--primary-700);font-size:1.05rem;">
                            <?= formatCurrency($mi['price']) ?>
                        </div>
                        <?php if ((int)($mi['is_available'] ?? 1) === 1): ?>
                        <!-- Add to Cart Button -->
                        <button type="button"
                                onclick='addToCart(<?= json_encode([
                                    "id"    => (int)$mi["id"],
                                    "name"  => $mi["name"],
                                    "price" => (float)$mi["price"],
                                    "image" => !empty($mi["image_path"]) ? BASE_URL . "uploads/" . $mi["image_path"] : ""
                                ], JSON_HEX_APOS | JSON_HEX_QUOT) ?>)'
                                class="btn btn-sm btn-primary"
                                style="display:flex;align-items:center;gap:6px;padding:6px 14px;">
                            <i class="fas fa-plus"></i>
                            <span>Add to Cart</span>
                        </button>
                        <?php endif; ?>
                    </div>

                    <?php
                    $item_ings = $menu_ingredients[(int)$mi['id']] ?? [];
                    if (!empty($item_ings)):
                    ?>
                    <!-- Ingredient transparency section -->
                    <details style="margin-top:10px;">
                        <summary style="font-size:.75rem;font-weight:700;color:#6366f1;cursor:pointer;
                                        list-style:none;display:flex;align-items:center;gap:5px;
                                        user-select:none;">
                            <i class="fas fa-leaf" style="font-size:.7rem;"></i>
                            Ingredients &amp; Halal Status
                            <i class="fas fa-chevron-down" style="font-size:.6rem;margin-left:2px;"></i>
                        </summary>
                        <div style="margin-top:8px;display:flex;flex-direction:column;gap:5px;">
                        <?php foreach ($item_ings as $ing):
                            $cat_label = '';
                            if ($ing['parent_category_name'] && $ing['category_name']) {
                                $cat_label = $ing['parent_category_name'] . ' (' . $ing['category_name'] . ')';
                            } elseif ($ing['category_name']) {
                                $cat_label = $ing['category_name'];
                            }
                            $hs_color = halalColor($ing['halal_status']);
                            $hs_label = halalLabel($ing['halal_status']);
                        ?>
                        <div style="font-size:.75rem;display:flex;align-items:center;flex-wrap:wrap;gap:4px;
                                    background:#f8fafc;border-radius:6px;padding:5px 8px;
                                    border-left:3px solid <?= $hs_color ?>;">
                            <span style="font-weight:600;color:var(--neutral-800);">
                                <?= htmlspecialchars($ing['ingredient_name']) ?>
                            </span>
                            <?php if ($cat_label): ?>
                            <span style="color:var(--neutral-400);">·</span>
                            <span style="color:var(--neutral-500);"><?= htmlspecialchars($cat_label) ?></span>
                            <?php endif; ?>
                            <span style="color:var(--neutral-400);">·</span>
                            <span style="color:<?= $hs_color ?>;font-weight:700;"><?= $hs_label ?></span>
                        </div>
                        <?php endforeach; ?>
                        </div>
                    </details>
                    <?php endif; ?>
                </div>
            </div>
            <?php endforeach; ?>
            <?php endforeach; ?>
        <?php endif; ?>
    </div>
</div>

<!-- ── Reviews ───────────────────────────────────────────────────────────────── -->
<div class="card">
    <div class="card-header">
        <h3><i class="fas fa-star" style="color:var(--accent-400);margin-right:8px"></i> Reviews</h3>
    </div>
    <div class="card-body">
        <?php if (empty($reviews)): ?>
        <p style="color:var(--neutral-500);text-align:center;padding:20px">No reviews yet.</p>
        <?php else: ?>
        <?php foreach ($reviews as $rv): ?>
        <div style="border-bottom:1px solid var(--neutral-100);padding:16px 0">
            <div style="display:flex;align-items:center;gap:10px;margin-bottom:6px">
                <div style="width:36px;height:36px;border-radius:50%;background:var(--primary-100);
                            display:flex;align-items:center;justify-content:center;
                            font-weight:700;color:var(--primary-700)">
                    <?= strtoupper(substr($rv['full_name'],0,1)) ?>
                </div>
                <div>
                    <div style="font-weight:600;font-size:.9rem"><?= htmlspecialchars($rv['full_name']) ?></div>
                    <div style="font-size:.75rem;color:var(--neutral-400)"><?= formatDate($rv['created_at']) ?></div>
                </div>
                <div style="margin-left:auto">
                    <?php for ($s=1;$s<=5;$s++): ?>
                    <i class="fas fa-star" style="font-size:.8rem;color:<?= $s<=$rv['rating']?'var(--accent-400)':'var(--neutral-200)' ?>"></i>
                    <?php endfor; ?>
                </div>
            </div>
            <?php if ($rv['comment']): ?>
            <p style="font-size:.9rem;color:var(--neutral-600);margin-left:46px">
                <?= nl2br(htmlspecialchars($rv['comment'])) ?></p>
            <?php endif; ?>
        </div>
        <?php endforeach; ?>
        <?php endif; ?>
    </div>
</div>
</div><!-- end left col -->

<!-- ── Right sidebar ──────────────────────────────────────────────────────────── -->
<div>

<!-- Rating summary -->
<div class="card" style="margin-bottom:24px;">
    <div class="card-body" style="text-align:center;padding:24px">
        <?php if ($live_review_count > 0): ?>
        <div style="font-family:var(--font-display);font-size:3rem;font-weight:800;color:var(--neutral-900)">
            <?= number_format($live_avg_rating, 1) ?>
        </div>
        <div style="margin:8px 0">
            <?php for ($s=1;$s<=5;$s++): ?>
            <i class="fas fa-star" style="color:<?= $s<=round($live_avg_rating)?'var(--accent-400)':'var(--neutral-200)' ?>"></i>
            <?php endfor; ?>
        </div>
        <div style="font-size:.85rem;color:var(--neutral-500)"><?= $live_review_count ?> review<?= $live_review_count !== 1 ? 's' : '' ?></div>
        <?php else: ?>
        <div style="font-size:2.5rem;color:var(--neutral-300);margin-bottom:8px;">
            <i class="fas fa-star-half-alt"></i>
        </div>
        <div style="font-size:.88rem;color:var(--neutral-400);font-weight:600;">No ratings yet</div>
        <div style="font-size:.78rem;color:var(--neutral-300);margin-top:4px;">Be the first to review!</div>
        <?php endif; ?>
    </div>
</div>

<!-- ══ HALAL CERTIFICATE CARD ════════════════════════════════════════════════ -->
<?php if ($r['certificate_number']): ?>
<div class="card" style="margin-bottom:24px;border:2px solid #bbf7d0;">
    <div class="card-header" style="background:linear-gradient(135deg,#064e3b,#065f46);">
        <h3 style="color:#fff;display:flex;align-items:center;gap:8px;margin:0;">
            <i class="fas fa-certificate"></i> Halal Certificate
        </h3>
    </div>
    <div class="card-body" style="padding:18px;">
        <div style="display:flex;flex-direction:column;gap:8px;margin-bottom:14px;">
            <div style="display:flex;justify-content:space-between;font-size:.82rem;">
                <span style="color:var(--neutral-500);font-weight:600;">Cert No.</span>
                <span style="color:var(--neutral-800);font-weight:700;font-family:monospace;">
                    <?= htmlspecialchars($r['certificate_number']) ?>
                </span>
            </div>
            <?php if ($r['issue_date']): ?>
            <div style="display:flex;justify-content:space-between;font-size:.82rem;">
                <span style="color:var(--neutral-500);font-weight:600;">Issued</span>
                <span style="color:var(--neutral-700);"><?= date('M j, Y', strtotime($r['issue_date'])) ?></span>
            </div>
            <?php endif; ?>
            <?php if ($r['expiry_date']): ?>
            <?php $days_left = (int)((strtotime($r['expiry_date']) - time()) / 86400); ?>
            <div style="display:flex;justify-content:space-between;font-size:.82rem;">
                <span style="color:var(--neutral-500);font-weight:600;">Valid Until</span>
                <span style="color:<?= $days_left < 30 ? '#b45309' : '#059669' ?>;font-weight:700;">
                    <?= date('M j, Y', strtotime($r['expiry_date'])) ?>
                </span>
            </div>
            <?php endif; ?>
        </div>
        <button onclick="openCertModal()"
                style="width:100%;background:linear-gradient(135deg,#065f46,#047857);color:#fff;
                       border:none;padding:10px 16px;border-radius:8px;font-weight:700;
                       cursor:pointer;font-size:.85rem;display:flex;align-items:center;
                       justify-content:center;gap:8px;transition:opacity .15s;"
                onmouseover="this.style.opacity='.88'" onmouseout="this.style.opacity='1'">
            <i class="fas fa-eye"></i> View Certificate
        </button>
    </div>
</div>
<?php endif; ?>

<!-- ══ STAFF APPLICATION CARD ═══════════════════════════════════════════════ -->
<div class="card" style="margin-bottom:24px;">
    <div class="card-header" style="background:linear-gradient(135deg,var(--primary-800),var(--primary-700))">
        <h3 style="color:#fff;display:flex;align-items:center;gap:8px">
            <i class="fas fa-user-tie"></i> Work Here
        </h3>
    </div>
    <div class="card-body">
        <?php if ($my_app && $my_app['status'] === 'pending'): ?>
        <!-- Already applied / pending -->
        <div style="text-align:center;padding:12px 0">
            <div style="width:56px;height:56px;border-radius:50%;background:var(--accent-50);
                        display:flex;align-items:center;justify-content:center;margin:0 auto 12px">
                <i class="fas fa-hourglass-half" style="font-size:1.5rem;color:var(--accent-600)"></i>
            </div>
            <div style="font-weight:700;color:var(--neutral-800);margin-bottom:4px">Application Submitted</div>
            <div style="font-size:.85rem;color:var(--neutral-500);margin-bottom:12px">
                Role: <strong><?= htmlspecialchars($avail_roles_map[$my_app['applied_role']] ?? ucfirst(str_replace('_', ' ', $my_app['applied_role']))) ?></strong><br>
                Type: <strong><?= $my_app['application_type'] === 'applicant' ? 'New Applicant' : 'Existing Employee' ?></strong>
            </div>
            <?= getStatusBadge('pending') ?>
            <p style="font-size:.8rem;color:var(--neutral-400);margin-top:10px">
                Waiting for the business owner to review your application.
            </p>
        </div>
        <?php elseif ($my_app && $my_app['status'] === 'approved'): ?>
        <div style="text-align:center;padding:12px 0">
            <div style="width:56px;height:56px;border-radius:50%;background:var(--primary-50);
                        display:flex;align-items:center;justify-content:center;margin:0 auto 12px">
                <i class="fas fa-check-circle" style="font-size:1.5rem;color:var(--primary-600)"></i>
            </div>
            <div style="font-weight:700;color:var(--neutral-800);margin-bottom:8px">You're on the team!</div>
            <?= getStatusBadge('approved') ?>
            <p style="font-size:.8rem;color:var(--neutral-400);margin-top:10px">
                Your role has been provisioned. Check your dashboard for staff tools.
            </p>
        </div>
        <?php elseif ($my_app && $my_app['status'] === 'rejected'): ?>
        <div style="text-align:center;padding:12px 0;margin-bottom:16px">
            <?= getStatusBadge('rejected') ?>
            <p style="font-size:.85rem;color:var(--neutral-500);margin-top:8px">
                Your previous application was not approved. You may apply again.
            </p>
        </div>
        <button type="button" class="btn btn-primary btn-block" onclick="openStaffModal()">
            <i class="fas fa-paper-plane"></i> Apply Again
        </button>
        <?php else: ?>
        <p style="font-size:.88rem;color:var(--neutral-500);margin-bottom:16px;line-height:1.6">
            Interested in joining this restaurant as staff? Submit your application or verify your existing employment.
        </p>
        <button type="button" class="btn btn-primary btn-block" onclick="openStaffModal()">
            <i class="fas fa-paper-plane"></i> Apply for a Staff Position
        </button>
        <?php endif; ?>
    </div>
</div>

</div><!-- end right col -->
</div><!-- end grid -->

<!-- ══════════════════════════════════════════════════════════════════════════
     STAFF APPLICATION MODAL
     ════════════════════════════════════════════════════════════════════════ -->
<div id="staffModal" style="display:none;position:fixed;inset:0;background:rgba(0,0,0,.55);
     align-items:center;justify-content:center;z-index:1000;overflow-y:auto;padding:16px 0;">
    <div style="background:#fff;border-radius:var(--radius-xl);width:100%;max-width:580px;
                max-height:90vh;overflow-y:auto;box-shadow:var(--shadow-2xl);margin:16px">

        <!-- Modal header -->
        <div style="padding:24px 28px 20px;border-bottom:1px solid var(--neutral-100);
                    display:flex;align-items:center;justify-content:space-between;
                    background:linear-gradient(135deg,var(--primary-800),var(--primary-700));
                    border-radius:var(--radius-xl) var(--radius-xl) 0 0">
            <div>
                <h3 style="color:#fff;font-family:var(--font-display);font-weight:700;font-size:1.2rem;margin-bottom:2px">
                    <i class="fas fa-user-tie" style="margin-right:8px"></i> Staff Position Application
                </h3>
                <p style="color:rgba(255,255,255,.75);font-size:.85rem;margin:0">
                    <?= htmlspecialchars($r['restaurant_name']) ?>
                </p>
            </div>
            <button type="button" onclick="closeStaffModal()"
                    style="background:rgba(255,255,255,.15);border:none;color:#fff;width:36px;height:36px;
                           border-radius:50%;cursor:pointer;font-size:1.1rem;display:flex;
                           align-items:center;justify-content:center">
                <i class="fas fa-times"></i>
            </button>
        </div>

        <!-- Path toggle -->
        <div style="padding:24px 28px 0">
            <p style="font-size:.875rem;font-weight:600;color:var(--neutral-700);margin-bottom:12px">
                I am a…
            </p>
            <div style="display:flex;gap:10px;margin-bottom:8px">
                <label id="toggleA"
                       onclick="switchPath('applicant')"
                       style="flex:1;border:2px solid var(--primary-600);border-radius:var(--radius-md);
                              padding:14px;cursor:pointer;text-align:center;transition:all .2s;
                              background:var(--primary-50)">
                    <i class="fas fa-user-plus" style="font-size:1.4rem;color:var(--primary-600);display:block;margin-bottom:6px"></i>
                    <div style="font-weight:700;color:var(--primary-800);font-size:.9rem">New Applicant</div>
                    <div style="font-size:.75rem;color:var(--neutral-500)">Apply for a staff position</div>
                </label>
                <label id="toggleB"
                       onclick="switchPath('existing')"
                       style="flex:1;border:2px solid var(--neutral-200);border-radius:var(--radius-md);
                              padding:14px;cursor:pointer;text-align:center;transition:all .2s;
                              background:#fff">
                    <i class="fas fa-id-badge" style="font-size:1.4rem;color:var(--neutral-400);display:block;margin-bottom:6px"></i>
                    <div style="font-weight:700;color:var(--neutral-600);font-size:.9rem">Already Work Here</div>
                    <div style="font-size:.75rem;color:var(--neutral-500)">Verify my employment</div>
                </label>
            </div>
        </div>

        <!-- The form -->
        <form id="staffAppForm" method="POST"
              action="<?= BASE_URL ?>dashboard/customer/api/submit_staff_request.php"
              enctype="multipart/form-data">
            <input type="hidden" name="restaurant_id" value="<?= $rid ?>">
            <input type="hidden" name="application_type" id="appTypeInput" value="applicant">

            <div style="padding:20px 28px 28px">

                <!-- Role selector (both paths) — populated from restaurant_staff_roles -->
                <div class="form-group">
                    <label>Target Role <span class="required">*</span></label>
                    <select name="applied_role" class="form-control" required>
                        <option value="">— Select a role —</option>
                        <?php foreach ($avail_roles as $ar): ?>
                        <option value="<?= htmlspecialchars($ar['role_key']) ?>">
                            <?= htmlspecialchars($ar['role_label']) ?>
                        </option>
                        <?php endforeach; ?>
                    </select>
                </div>

                <!-- ── PATH A: New Applicant ───────────────────────────────── -->
                <div id="pathA">
                    <div class="form-group">
                        <label>Cover Letter <span class="required">*</span></label>
                        <textarea name="cover_letter_text" class="form-control" rows="4"
                                  placeholder="Briefly tell the owner why you're a great fit for this role…"></textarea>
                        <div class="form-text">Optional: also attach a PDF cover letter below.</div>
                    </div>
                    <div style="display:grid;grid-template-columns:1fr 1fr;gap:16px">
                        <div class="form-group">
                            <label>Cover Letter File</label>
                            <div class="file-upload" id="drop_cover_letter">
                                <input type="file" name="file_cover_letter" accept=".pdf"
                                       onchange="showFileName(this,'cover_letter')">
                                <i class="fas fa-file-pdf"></i>
                                <p id="cover_letter_name">PDF only · max 20 MB</p>
                            </div>
                        </div>
                        <div class="form-group">
                            <label>Resume / CV <span class="required">*</span></label>
                            <div class="file-upload" id="drop_resume">
                                <input type="file" name="file_resume" accept=".pdf,.docx"
                                       onchange="showFileName(this,'resume')">
                                <i class="fas fa-file-alt"></i>
                                <p id="resume_name">PDF / DOCX · max 20 MB</p>
                            </div>
                        </div>
                    </div>
                    <div style="display:grid;grid-template-columns:1fr 1fr 1fr;gap:16px">
                        <div class="form-group">
                            <label>NBI Clearance <span class="required">*</span></label>
                            <div class="file-upload">
                                <input type="file" name="file_nbi" accept=".pdf,.jpg,.jpeg,.png"
                                       onchange="showFileName(this,'nbi')">
                                <i class="fas fa-shield-alt"></i>
                                <p id="nbi_name">PDF/IMG</p>
                            </div>
                        </div>
                        <div class="form-group">
                            <label>Medical Certificate <span class="required">*</span></label>
                            <div class="file-upload">
                                <input type="file" name="file_medical" accept=".pdf,.jpg,.jpeg,.png"
                                       onchange="showFileName(this,'medical')">
                                <i class="fas fa-file-medical"></i>
                                <p id="medical_name">PDF/IMG</p>
                            </div>
                        </div>
                        <div class="form-group">
                            <label>Government ID <span class="required">*</span></label>
                            <div class="file-upload">
                                <input type="file" name="file_gov_id" accept=".pdf,.jpg,.jpeg,.png"
                                       onchange="showFileName(this,'gov_id')">
                                <i class="fas fa-id-card"></i>
                                <p id="gov_id_name">PDF/IMG</p>
                            </div>
                        </div>
                    </div>
                </div><!-- end pathA -->

                <!-- ── PATH B: Existing Employee ──────────────────────────── -->
                <div id="pathB" style="display:none">
                    <div class="form-group">
                        <label>Proof of Employment <span class="required">*</span></label>
                        <div class="form-text" style="margin-bottom:10px;color:var(--neutral-600)">
                            Upload your <strong>Store ID badge</strong> or
                            <strong>Certificate of Employment (COE)</strong>.
                        </div>
                        <div class="file-upload">
                            <input type="file" name="file_proof_employ"
                                   accept=".pdf,.jpg,.jpeg,.png"
                                   onchange="showFileName(this,'proof_employ')">
                            <i class="fas fa-id-badge" style="font-size:1.8rem;color:var(--primary-400)"></i>
                            <p id="proof_employ_name">PDF / JPG / PNG · max 20 MB</p>
                        </div>
                    </div>
                </div><!-- end pathB -->

                <!-- Error / submit -->
                <div id="modalError" class="alert alert-danger" style="display:none;margin-bottom:16px">
                    <i class="fas fa-exclamation-circle"></i>
                    <span id="modalErrorMsg"></span>
                </div>

                <div style="display:flex;gap:12px;margin-top:4px">
                    <button type="button" onclick="closeStaffModal()"
                            class="btn btn-outline" style="flex:1">Cancel</button>
                    <button type="submit" id="submitStaffBtn"
                            class="btn btn-primary" style="flex:2">
                        <i class="fas fa-paper-plane"></i> Submit Application
                    </button>
                </div>

            </div><!-- end padding -->
        </form>
    </div><!-- end modal box -->
</div><!-- end modal overlay -->

<!-- ══════════════════════════════════════════════════════════════════════════
     HALAL CERTIFICATE VIEWER MODAL
     ════════════════════════════════════════════════════════════════════════ -->
<?php if ($r['certificate_number']): ?>
<div id="certModal" style="display:none;position:fixed;inset:0;background:rgba(0,0,0,.65);
     align-items:center;justify-content:center;z-index:2000;padding:16px;">
    <div style="background:#fff;border-radius:16px;width:100%;max-width:680px;
                max-height:92vh;overflow-y:auto;box-shadow:0 20px 60px rgba(0,0,0,.3);">

        <!-- Header -->
        <div style="padding:20px 24px 18px;border-bottom:1px solid #e2e8f0;
                    background:linear-gradient(135deg,#064e3b,#065f46);
                    border-radius:16px 16px 0 0;
                    display:flex;align-items:center;justify-content:space-between;">
            <div style="display:flex;align-items:center;gap:12px;">
                <div style="width:42px;height:42px;background:rgba(255,255,255,.15);
                            border-radius:10px;display:flex;align-items:center;justify-content:center;">
                    <i class="fas fa-certificate" style="color:#fff;font-size:1.2rem;"></i>
                </div>
                <div>
                    <div style="color:#fff;font-weight:700;font-size:1.05rem;">Halal Certificate</div>
                    <div style="color:rgba(255,255,255,.75);font-size:.8rem;">
                        <?= htmlspecialchars($r['restaurant_name']) ?>
                    </div>
                </div>
            </div>
            <button onclick="closeCertModal()"
                    style="background:rgba(255,255,255,.15);border:none;color:#fff;
                           width:34px;height:34px;border-radius:50%;cursor:pointer;
                           font-size:1rem;display:flex;align-items:center;justify-content:center;">
                <i class="fas fa-times"></i>
            </button>
        </div>

        <!-- Certificate details -->
        <div style="padding:24px 28px;">

            <!-- Status banner -->
            <div style="background:#dcfce7;border:1px solid #bbf7d0;border-radius:10px;
                        padding:12px 16px;margin-bottom:20px;
                        display:flex;align-items:center;gap:10px;">
                <i class="fas fa-check-circle" style="color:#16a34a;font-size:1.1rem;"></i>
                <div>
                    <div style="font-weight:700;color:#166534;font-size:.9rem;">Verified Halal Certificate</div>
                    <div style="font-size:.78rem;color:#15803d;">
                        This business has been verified and certified as Halal
                    </div>
                </div>
            </div>

            <!-- Details grid -->
            <div style="display:grid;grid-template-columns:1fr 1fr;gap:14px;margin-bottom:20px;">
                <div style="background:#f8fafc;border-radius:8px;padding:12px 14px;">
                    <div style="font-size:.72rem;font-weight:600;color:#94a3b8;
                                text-transform:uppercase;letter-spacing:.05em;margin-bottom:4px;">
                        Certificate No.
                    </div>
                    <div style="font-weight:700;color:#1e293b;font-size:.88rem;font-family:monospace;">
                        <?= htmlspecialchars($r['certificate_number']) ?>
                    </div>
                </div>
                <div style="background:#f8fafc;border-radius:8px;padding:12px 14px;">
                    <div style="font-size:.72rem;font-weight:600;color:#94a3b8;
                                text-transform:uppercase;letter-spacing:.05em;margin-bottom:4px;">
                        Certifying Body
                    </div>
                    <div style="font-weight:600;color:#1e293b;font-size:.85rem;">
                        <?= htmlspecialchars($r['certifying_body'] ?? 'Halal Development Program - HDIP') ?>
                    </div>
                </div>
                <div style="background:#f8fafc;border-radius:8px;padding:12px 14px;">
                    <div style="font-size:.72rem;font-weight:600;color:#94a3b8;
                                text-transform:uppercase;letter-spacing:.05em;margin-bottom:4px;">
                        Issue Date
                    </div>
                    <div style="font-weight:600;color:#1e293b;font-size:.85rem;">
                        <?= $r['issue_date'] ? date('F j, Y', strtotime($r['issue_date'])) : '—' ?>
                    </div>
                </div>
                <div style="background:#f8fafc;border-radius:8px;padding:12px 14px;">
                    <div style="font-size:.72rem;font-weight:600;color:#94a3b8;
                                text-transform:uppercase;letter-spacing:.05em;margin-bottom:4px;">
                        Valid Until
                    </div>
                    <div style="font-weight:700;font-size:.85rem;
                                color:<?= ($r['expiry_date'] && strtotime($r['expiry_date']) < strtotime('+30 days'))
                                    ? '#b45309' : '#059669' ?>;">
                        <?= $r['expiry_date'] ? date('F j, Y', strtotime($r['expiry_date'])) : '—' ?>
                        <?php if ($r['expiry_date'] && strtotime($r['expiry_date']) < strtotime('+30 days')): ?>
                        <span style="font-size:.72rem;background:#fef3c7;color:#b45309;
                                     padding:2px 8px;border-radius:20px;margin-left:4px;">
                            Expiring soon
                        </span>
                        <?php endif; ?>
                    </div>
                </div>
            </div>

            <!-- Certificate document viewer -->
            <?php if (!empty($r['certificate_file_path'])): ?>
            <?php
                $cert_file = $r['certificate_file_path'];
                $cert_url  = BASE_URL . 'uploads/' . htmlspecialchars($cert_file);
                $cert_ext  = strtolower(pathinfo($cert_file, PATHINFO_EXTENSION));
                $is_image  = in_array($cert_ext, ['jpg','jpeg','png','gif','webp']);
                $is_pdf    = ($cert_ext === 'pdf');
            ?>
            <div style="border:1px solid #e2e8f0;border-radius:10px;overflow:hidden;margin-bottom:20px;">
                <div style="background:#f8fafc;padding:10px 14px;border-bottom:1px solid #e2e8f0;
                            display:flex;align-items:center;justify-content:space-between;">
                    <span style="font-size:.82rem;font-weight:600;color:#475569;">
                        <i class="fas fa-file-<?= $is_image ? 'image' : 'pdf' ?>"
                           style="color:<?= $is_image ? '#0891b2' : '#dc2626' ?>;margin-right:6px;"></i>
                        Certificate Document
                    </span>
                    <a href="<?= $cert_url ?>" target="_blank" download
                       style="font-size:.78rem;color:#4f46e5;font-weight:600;text-decoration:none;">
                        <i class="fas fa-download"></i> Download
                    </a>
                </div>
                <?php if ($is_image): ?>
                <div style="text-align:center;padding:16px;background:#fff;">
                    <img src="<?= $cert_url ?>"
                         alt="Halal Certificate"
                         style="max-width:100%;max-height:500px;object-fit:contain;border-radius:6px;">
                </div>
                <?php elseif ($is_pdf): ?>
                <div style="height:500px;">
                    <iframe src="<?= $cert_url ?>" width="100%" height="100%"
                            style="border:none;" title="Halal Certificate PDF">
                        <p style="padding:20px;text-align:center;color:#64748b;">
                            Your browser cannot display PDF files.
                            <a href="<?= $cert_url ?>" target="_blank" style="color:#4f46e5;">
                                Open certificate in new tab
                            </a>
                        </p>
                    </iframe>
                </div>
                <?php else: ?>
                <div style="padding:24px;text-align:center;">
                    <i class="fas fa-file-alt" style="font-size:2.5rem;color:#94a3b8;margin-bottom:10px;display:block;"></i>
                    <p style="color:#64748b;font-size:.88rem;margin-bottom:12px;">
                        Certificate document is available for download.
                    </p>
                    <a href="<?= $cert_url ?>" target="_blank"
                       style="background:#4f46e5;color:#fff;padding:10px 20px;border-radius:8px;
                              text-decoration:none;font-weight:600;font-size:.88rem;">
                        <i class="fas fa-external-link-alt"></i> Open Document
                    </a>
                </div>
                <?php endif; ?>
            </div>
            <?php else: ?>
            <!-- No file uploaded yet (full HDP workflow certificates may not have a file) -->
            <div style="background:#f8fafc;border:1px dashed #cbd5e1;border-radius:10px;
                        padding:24px;text-align:center;margin-bottom:20px;">
                <i class="fas fa-file-certificate" style="font-size:2rem;color:#94a3b8;
                           display:block;margin-bottom:8px;"></i>
                <p style="color:#64748b;font-size:.85rem;margin:0;">
                    Certificate document not available for public viewing.
                </p>
            </div>
            <?php endif; ?>

            <!-- Footer note -->
            <p style="font-size:.75rem;color:#94a3b8;text-align:center;margin:0;">
                <i class="fas fa-shield-alt"></i>
                This certificate is verified by the Halal Development &amp; Inspection Program (HDIP).
            </p>
        </div>
    </div>
</div>
<?php endif; ?>

<?php require_once __DIR__ . '/../../includes/footer.php'; ?>

<script src="<?= BASE_URL ?>assets/js/survey_modal.js"></script>
<script>
/* ── Halal certificate modal helpers ─────────────────────────────────────── */
function openCertModal() {
    var m = document.getElementById('certModal');
    if (m) { m.style.display = 'flex'; document.body.style.overflow = 'hidden'; }
}
function closeCertModal() {
    var m = document.getElementById('certModal');
    if (m) { m.style.display = 'none'; document.body.style.overflow = ''; }
}
document.addEventListener('DOMContentLoaded', function () {
    var cm = document.getElementById('certModal');
    if (cm) cm.addEventListener('click', function (e) {
        if (e.target === this) closeCertModal();
    });
});

/* ── Staff modal helpers ─────────────────────────────────────────────────── */
function openStaffModal() {
    document.getElementById('staffModal').style.display = 'flex';
    document.body.style.overflow = 'hidden';
}
function closeStaffModal() {
    document.getElementById('staffModal').style.display = 'none';
    document.body.style.overflow = '';
}
document.addEventListener('DOMContentLoaded', function() {
    var overlay = document.getElementById('staffModal');
    if (overlay) {
        overlay.addEventListener('click', function(e) {
            if (e.target === this) closeStaffModal();
        });
    }

    function switchPath(path) {
        var isA = (path === 'applicant');
        document.getElementById('appTypeInput').value = path;
        document.getElementById('pathA').style.display = isA ? '' : 'none';
        document.getElementById('pathB').style.display = isA ? 'none' : '';

        var tA = document.getElementById('toggleA');
        var tB = document.getElementById('toggleB');
        tA.style.border      = isA ? '2px solid var(--primary-600)' : '2px solid var(--neutral-200)';
        tA.style.background  = isA ? 'var(--primary-50)' : '#fff';
        tA.querySelector('i').style.color       = isA ? 'var(--primary-600)' : 'var(--neutral-400)';
        tA.querySelector('div').style.color     = isA ? 'var(--primary-800)' : 'var(--neutral-600)';
        tB.style.border      = isA ? '2px solid var(--neutral-200)' : '2px solid var(--primary-600)';
        tB.style.background  = isA ? '#fff' : 'var(--primary-50)';
        tB.querySelector('i').style.color       = isA ? 'var(--neutral-400)' : 'var(--primary-600)';
        tB.querySelector('div').style.color     = isA ? 'var(--neutral-600)' : 'var(--primary-800)';
    }
    // Expose to inline onclick on the path-toggle labels
    window.switchPath = switchPath;

    function showFileName(input, key) {
        var el = document.getElementById(key + '_name');
        if (el && input.files && input.files[0]) {
            el.textContent   = input.files[0].name;
            el.style.color      = 'var(--primary-700)';
            el.style.fontWeight = '600';
        }
    }
    window.showFileName = showFileName;

    /* ── Client-side validation before submit ──────────────────────────── */
    var form = document.getElementById('staffAppForm');
    if (form) {
        form.addEventListener('submit', function(e) {
            var type   = document.getElementById('appTypeInput').value;
            var role   = this.querySelector('[name="applied_role"]').value;
            var errEl  = document.getElementById('modalError');
            var errMsg = document.getElementById('modalErrorMsg');

            function showErr(msg) {
                errMsg.textContent    = msg;
                errEl.style.display  = 'flex';
                e.preventDefault();
            }

            errEl.style.display = 'none';
            if (!role) { showErr('Please select a target role.'); return; }

            if (type === 'applicant') {
                var cover  = this.querySelector('[name="cover_letter_text"]').value.trim();
                var resume = this.querySelector('[name="file_resume"]').files.length;
                var nbi    = this.querySelector('[name="file_nbi"]').files.length;
                var med    = this.querySelector('[name="file_medical"]').files.length;
                var gov    = this.querySelector('[name="file_gov_id"]').files.length;
                if (!cover && !this.querySelector('[name="file_cover_letter"]').files.length)
                    { showErr('Please provide a cover letter (text or PDF).'); return; }
                if (!resume) { showErr('Resume / CV is required.'); return; }
                if (!nbi)    { showErr('NBI Clearance is required.'); return; }
                if (!med)    { showErr('Medical Certificate is required.'); return; }
                if (!gov)    { showErr('Government ID is required.'); return; }
            } else {
                var proof = this.querySelector('[name="file_proof_employ"]').files.length;
                if (!proof) { showErr('Proof of employment is required.'); return; }
            }

            var btn = document.getElementById('submitStaffBtn');
            btn.disabled   = true;
            btn.innerHTML  = '<i class="fas fa-spinner fa-spin"></i> Submitting…';
        });
    }
});

// ── Order CTA: Smooth scroll to menu ──────────────────────────────────────────
function scrollToMenu() {
    const menuSection = document.getElementById('menu-section');
    if (menuSection) {
        menuSection.scrollIntoView({ 
            behavior: 'smooth', 
            block: 'start'
        });
        // Highlight the menu section briefly
        menuSection.style.transition = 'box-shadow 0.3s';
        menuSection.style.boxShadow = '0 0 0 3px rgba(22, 163, 74, 0.3)';
        setTimeout(() => {
            menuSection.style.boxShadow = '';
        }, 1200);
    }
}

// ── Order modal trigger (integrate with existing order flow) ──────────────────
function openOrderModal() {
    // Check if menu section exists and has items
    const menuSection = document.getElementById('menu-section');
    if (!menuSection) {
        alert('Menu section not found. Please refresh the page.');
        return;
    }
    
    // Check if there are any menu items visible (more robust detection)
    const hasMenuItems = menuSection.textContent.toLowerCase().includes('main course') ||
                         menuSection.textContent.toLowerCase().includes('appetizer') ||
                         menuSection.querySelector('.card-body img') !== null ||
                         menuSection.querySelector('.card-body').children.length > 1;
    
    if (!hasMenuItems || menuSection.textContent.includes('coming soon')) {
        alert('This restaurant hasn\'t added menu items yet. Please check back soon!');
        return;
    }
    
    // Scroll to menu and show helpful toast
    scrollToMenu();
    
    // Show a helpful toast
    const toast = document.createElement('div');
    toast.style.cssText = 'position:fixed;bottom:24px;right:24px;z-index:9999;' +
        'background:#16a34a;color:#fff;padding:14px 20px;border-radius:12px;' +
        'font-size:.875rem;font-weight:600;box-shadow:0 8px 24px rgba(0,0,0,.2);' +
        'display:flex;align-items:center;gap:10px;animation:slideInUp 0.3s ease;';
    toast.innerHTML = '<i class="fas fa-info-circle"></i> Select items from the menu below to add to your order';
    document.body.appendChild(toast);
    setTimeout(() => {
        toast.style.opacity = '0';
        toast.style.transform = 'translateY(10px)';
        toast.style.transition = 'all 0.3s';
        setTimeout(() => toast.remove(), 300);
    }, 4000);
}

// Add animation keyframes
(function() {
    if (!document.querySelector('#orderCTAAnimations')) {
        const style = document.createElement('style');
        style.id = 'orderCTAAnimations';
        style.textContent = `
            @keyframes slideInUp {
                from { opacity: 0; transform: translateY(20px); }
                to { opacity: 1; transform: translateY(0); }
            }
        `;
        document.head.appendChild(style);
    }
})();

// ── Shopping Cart System ──────────────────────────────────────────────────────
let cart = JSON.parse(localStorage.getItem('cart_<?= $rid ?>') || '[]');

function addToCart(item) {
    // Check if item already exists in cart
    const existingIndex = cart.findIndex(i => i.id === item.id);
    
    if (existingIndex !== -1) {
        // Increment quantity
        cart[existingIndex].qty = (cart[existingIndex].qty || 1) + 1;
    } else {
        // Add new item with qty 1
        cart.push({ ...item, qty: 1 });
    }
    
    // Save to localStorage
    localStorage.setItem('cart_<?= $rid ?>', JSON.stringify(cart));
    
    // Show success toast
    showCartToast(item.name + ' added to cart!', false);
    
    // Update cart badge if exists
    updateCartBadge();
}

function updateCartBadge() {
    const totalItems = cart.reduce((sum, item) => sum + (item.qty || 1), 0);
    let badge = document.getElementById('cart-badge');
    
    if (!badge) {
        // Create floating cart button if it doesn't exist
        const cartBtn = document.createElement('div');
        cartBtn.id = 'floating-cart-btn';
        cartBtn.style.cssText = 'position:fixed;bottom:24px;right:24px;z-index:9998;' +
            'background:linear-gradient(135deg,#6366f1,#4f46e5);color:#fff;' +
            'width:60px;height:60px;border-radius:50%;display:flex;align-items:center;' +
            'justify-content:center;font-size:1.4rem;cursor:pointer;' +
            'box-shadow:0 8px 24px rgba(99,102,241,0.4);transition:all 0.2s;';
        cartBtn.innerHTML = '<i class="fas fa-shopping-cart"></i>' +
            '<span id="cart-badge" style="position:absolute;top:-4px;right:-4px;' +
            'background:#dc2626;color:#fff;font-size:.7rem;font-weight:800;' +
            'width:24px;height:24px;border-radius:50%;display:flex;align-items:center;' +
            'justify-content:center;border:2px solid #fff;">' + totalItems + '</span>';
        cartBtn.onclick = openCheckout;
        cartBtn.onmouseover = function() { this.style.transform = 'scale(1.1)'; };
        cartBtn.onmouseout = function() { this.style.transform = 'scale(1)'; };
        document.body.appendChild(cartBtn);
        badge = document.getElementById('cart-badge');
    } else {
        badge.textContent = totalItems;
    }
    
    // Show/hide cart button
    const cartBtn = document.getElementById('floating-cart-btn');
    if (cartBtn) {
        cartBtn.style.display = totalItems > 0 ? 'flex' : 'none';
    }
}

async function openCheckout() {
    if (cart.length === 0) {
        alert('Your cart is empty. Add some items first!');
        return;
    }
    
    // ── Survey Gate: Check if user has completed TPB demand survey ───────────
    try {
        const surveyCheck = await fetch('<?= BASE_URL ?>api/check_survey_status.php', {
            method: 'POST',
            headers: { 'Content-Type': 'application/x-www-form-urlencoded' }
        });
        const surveyData = await surveyCheck.json();
        
        if (!surveyData.success) {
            alert('Unable to verify survey status. Please try again.');
            return;
        }
        
        if (!surveyData.completed) {
            // Show survey modal with questionnaire set
            showSurveyModal(surveyData.questionnaire_set);
            return;
        }
        
        // Survey completed — proceed to checkout
        showCheckoutModal();
        
    } catch (error) {
        console.error('Survey check error:', error);
        alert('Network error while checking survey status. Please try again.');
    }
}

function showCheckoutModal() {
    // Remove existing modal if any
    const existing = document.getElementById('checkout-modal-overlay');
    if (existing) existing.remove();
    
    // Calculate totals
    const subtotal = cart.reduce((sum, item) => sum + (item.price * item.qty), 0);
    const deliveryFee = 50; // Default delivery fee
    
    // Build cart items HTML
    let cartItemsHTML = '';
    cart.forEach(item => {
        const itemImage = item.image ? 
            '<img src="' + item.image + '" style="width:50px;height:50px;object-fit:cover;border-radius:8px;">' :
            '<div style="width:50px;height:50px;background:#e2e8f0;border-radius:8px;display:flex;align-items:center;justify-content:center;"><i class="fas fa-utensils" style="color:#94a3b8;"></i></div>';
        
        cartItemsHTML += '<div style="display:flex;gap:12px;padding:12px;background:#f8fafc;border-radius:10px;margin-bottom:10px;align-items:center;">' +
            itemImage +
            '<div style="flex:1;">' +
                '<div style="font-weight:600;font-size:0.9rem;">' + escapeHtml(item.name) + '</div>' +
                '<div style="font-size:0.8rem;color:var(--neutral-500);">₱' + item.price.toFixed(2) + ' × ' + item.qty + '</div>' +
            '</div>' +
            '<div style="font-weight:700;color:var(--primary-700);">₱' + (item.price * item.qty).toFixed(2) + '</div>' +
            '<button onclick="removeFromCart(' + item.id + ')" style="width:28px;height:28px;border:1px solid #fecaca;background:#fef2f2;border-radius:6px;cursor:pointer;color:#dc2626;">' +
                '<i class="fas fa-trash" style="font-size:0.7rem;"></i>' +
            '</button>' +
        '</div>';
    });
    
    // Build modal
    const modal = document.createElement('div');
    modal.id = 'checkout-modal-overlay';
    modal.style.cssText = 'position:fixed;inset:0;background:rgba(0,0,0,0.6);z-index:10000;' +
        'display:flex;align-items:center;justify-content:center;padding:20px;animation:fadeIn 0.2s;';
    
    const modalContent = document.createElement('div');
    modalContent.style.cssText = 'background:#fff;border-radius:16px;max-width:600px;width:100%;' +
        'max-height:90vh;overflow-y:auto;box-shadow:0 20px 60px rgba(0,0,0,0.3);animation:slideInUp 0.3s;';
    
    modalContent.innerHTML = `
        <div style="padding:24px;border-bottom:2px solid var(--neutral-100);">
            <div style="display:flex;align-items:center;justify-content:space-between;">
                <h3 style="margin:0;display:flex;align-items:center;gap:10px;">
                    <i class="fas fa-shopping-cart" style="color:var(--primary-600);"></i>
                    Your Order
                </h3>
                <button onclick="closeCheckoutModal()" style="width:32px;height:32px;border:none;background:transparent;cursor:pointer;color:var(--neutral-400);font-size:1.2rem;">
                    <i class="fas fa-times"></i>
                </button>
            </div>
        </div>
        
        <div style="padding:20px 24px;">
            <!-- Cart Items -->
            <div style="margin-bottom:20px;">
                ` + cartItemsHTML + `
            </div>
            
            <!-- Service Type -->
            <div style="margin-bottom:16px;">
                <label style="display:block;font-weight:700;margin-bottom:8px;font-size:0.9rem;">Service Type</label>
                <div style="display:flex;gap:10px;">
                    <label style="flex:1;border:2px solid var(--neutral-200);border-radius:10px;padding:12px;cursor:pointer;display:flex;align-items:center;gap:8px;" onclick="updateServiceType('dine-in')">
                        <input type="radio" name="service_type_checkout" value="dine-in" checked>
                        <span style="font-weight:600;font-size:0.85rem;">🍽️ Dine-In</span>
                    </label>
                    <label style="flex:1;border:2px solid var(--neutral-200);border-radius:10px;padding:12px;cursor:pointer;display:flex;align-items:center;gap:8px;" onclick="updateServiceType('takeout')">
                        <input type="radio" name="service_type_checkout" value="takeout">
                        <span style="font-weight:600;font-size:0.85rem;">🛍️ Takeout</span>
                    </label>
                    <label style="flex:1;border:2px solid var(--neutral-200);border-radius:10px;padding:12px;cursor:pointer;display:flex;align-items:center;gap:8px;" onclick="updateServiceType('delivery')">
                        <input type="radio" name="service_type_checkout" value="delivery">
                        <span style="font-weight:600;font-size:0.85rem;">🚗 Delivery</span>
                    </label>
                </div>
            </div>
            
            <!-- Table Number (for dine-in, hidden by default) -->
            <div id="table-number-section" style="display:block;margin-bottom:16px;">
                <label style="display:block;font-weight:700;margin-bottom:6px;font-size:0.9rem;">
                    <i class="fas fa-chair" style="color:var(--primary-600);margin-right:4px;"></i>
                    Table Number <span style="font-weight:400;color:var(--neutral-400);font-size:0.8rem;">(optional)</span>
                </label>
                <input type="text" id="table-number-input" placeholder="e.g., Table 5, A1, etc." 
                       style="width:100%;border:1.5px solid var(--neutral-200);border-radius:8px;padding:10px;font-size:0.85rem;">
            </div>
            
            <!-- Delivery Address (hidden by default) -->
            <div id="delivery-address-section" style="display:none;margin-bottom:16px;">
                <label style="display:block;font-weight:700;margin-bottom:6px;font-size:0.9rem;">Delivery Address</label>
                <textarea id="delivery-address-input" rows="2" placeholder="Enter your full delivery address..." 
                          style="width:100%;border:1.5px solid var(--neutral-200);border-radius:8px;padding:10px;font-size:0.85rem;resize:vertical;"></textarea>
            </div>
            
            <!-- Order Summary -->
            <div style="background:#f0f9ff;border:1.5px solid #bae6fd;border-radius:10px;padding:14px;margin-bottom:16px;">
                <div style="display:flex;justify-content:space-between;margin-bottom:6px;">
                    <span style="font-size:0.85rem;color:var(--neutral-600);">Subtotal</span>
                    <span style="font-weight:600;">₱` + subtotal.toFixed(2) + `</span>
                </div>
                <div id="delivery-fee-row" style="display:none;justify-content:space-between;margin-bottom:6px;">
                    <span style="font-size:0.85rem;color:var(--neutral-600);">Delivery Fee</span>
                    <span style="font-weight:600;">₱` + deliveryFee.toFixed(2) + `</span>
                </div>
                <div style="display:flex;justify-content:space-between;padding-top:8px;border-top:1px dashed #bae6fd;">
                    <span style="font-weight:700;color:var(--neutral-800);">Total</span>
                    <span id="checkout-total" style="font-size:1.2rem;font-weight:800;color:var(--primary-700);">₱` + subtotal.toFixed(2) + `</span>
                </div>
            </div>
            
            <!-- Payment Method -->
            <div style="margin-bottom:16px;">
                <label style="display:block;font-weight:700;margin-bottom:8px;font-size:0.9rem;">Payment Method</label>
                <div style="display:flex;gap:10px;flex-wrap:wrap;">
                    <label id="payment-gcash-option" style="flex:1;min-width:45%;border:2px solid var(--neutral-200);border-radius:10px;padding:10px;cursor:pointer;display:flex;align-items:center;gap:8px;">
                        <input type="radio" name="payment_method_checkout" value="gcash" checked onchange="updatePaymentInstructions()">
                        <div style="display:flex;flex-direction:column;">
                            <span style="font-weight:700;font-size:0.9rem;">📱 GCash</span>
                            <span style="font-size:0.75rem;color:var(--neutral-600);margin-top:2px;">Pay Online</span>
                        </div>
                    </label>
                </div>
                <!-- Payment Instructions -->
                <div id="payment-instructions" style="margin-top:12px;padding:10px;border-radius:8px;background:var(--warning-50);border-left:4px solid var(--warning-600);">
                    <div style="display:flex;align-items:start;gap:8px;">
                        <i class="fas fa-info-circle" style="color:var(--warning-700);margin-top:2px;"></i>
                        <div style="font-size:0.82rem;color:var(--warning-900);">
                            <strong>Secure PayMongo payment:</strong> You will be redirected to PayMongo to pay <strong>₱` + subtotal.toFixed(2) + `</strong>. Your order is created only after PayMongo verifies payment.
                        </div>
                    </div>
                </div>
            </div>
        </div>
        
        <div style="padding:16px 24px;border-top:2px solid var(--neutral-100);background:#fafafa;">
            <button onclick="submitOrder()" class="btn btn-primary" style="width:100%;padding:14px;font-size:1rem;font-weight:700;display:flex;align-items:center;justify-content:center;gap:8px;">
                <i class="fas fa-check-circle"></i>
                <span>Continue to PayMongo</span>
            </button>
        </div>
    `;
    
    modal.appendChild(modalContent);
    document.body.appendChild(modal);
    
    // Close on backdrop click
    modal.addEventListener('click', (e) => {
        if (e.target === modal) closeCheckoutModal();
    });
}

function closeCheckoutModal() {
    const modal = document.getElementById('checkout-modal-overlay');
    if (modal) {
        modal.style.opacity = '0';
        setTimeout(() => modal.remove(), 200);
    }
}

function updatePaymentInstructions() {
    const instructionsDiv = document.getElementById('payment-instructions');
    const gcashOption = document.getElementById('payment-gcash-option');
    if (gcashOption) {
        gcashOption.style.borderColor = 'var(--primary-600)';
        gcashOption.style.background = 'var(--primary-50)';
    }
    const subtotal = cart.reduce((sum, item) => sum + (item.price * item.qty), 0);
    const serviceType = document.querySelector('input[name="service_type_checkout"]:checked')?.value || 'dine-in';
    const deliveryFee = serviceType === 'delivery' ? 50 : 0;
    const totalAmount = subtotal + deliveryFee;
    if (instructionsDiv) {
        instructionsDiv.style.display = 'block';
        instructionsDiv.style.background = 'var(--success-50)';
        instructionsDiv.style.borderLeftColor = 'var(--success-600)';
        instructionsDiv.innerHTML = `
            <div style="display:flex;align-items:start;gap:8px;">
                <i class="fas fa-shield-alt" style="color:var(--success-700);margin-top:2px;"></i>
                <div style="font-size:0.82rem;color:var(--success-900);">
                    <strong>Secure PayMongo payment:</strong> you will be redirected to PayMongo to pay <strong>₱${totalAmount.toFixed(2)}</strong>. The order is created only after PayMongo verifies payment.
                </div>
            </div>`;
    }
}

function updateServiceType(type) {
    const deliverySection = document.getElementById('delivery-address-section');
    const tableSection = document.getElementById('table-number-section');
    const deliveryFeeRow = document.getElementById('delivery-fee-row');
    const totalEl = document.getElementById('checkout-total');
    
    const subtotal = cart.reduce((sum, item) => sum + (item.price * item.qty), 0);
    const deliveryFee = 50;
    
    if (type === 'delivery') {
        deliverySection.style.display = 'block';
        tableSection.style.display = 'none';
        deliveryFeeRow.style.display = 'flex';
        totalEl.textContent = '₱' + (subtotal + deliveryFee).toFixed(2);
    } else if (type === 'dine-in') {
        deliverySection.style.display = 'none';
        tableSection.style.display = 'block';
        deliveryFeeRow.style.display = 'none';
        totalEl.textContent = '₱' + subtotal.toFixed(2);
    } else {
        // takeout
        deliverySection.style.display = 'none';
        tableSection.style.display = 'none';
        deliveryFeeRow.style.display = 'none';
        totalEl.textContent = '₱' + subtotal.toFixed(2);
    }
}

function removeFromCart(itemId) {
    cart = cart.filter(item => item.id !== itemId);
    localStorage.setItem('cart_<?= $rid ?>', JSON.stringify(cart));
    
    if (cart.length === 0) {
        closeCheckoutModal();
        updateCartBadge();
    } else {
        closeCheckoutModal();
        showCheckoutModal();
        updateCartBadge();
    }
}

async function submitOrder() {
    const serviceType = document.querySelector('input[name="service_type_checkout"]:checked')?.value;
    const paymentMethod = document.querySelector('input[name="payment_method_checkout"]:checked')?.value;
    const deliveryAddress = document.getElementById('delivery-address-input')?.value?.trim() || '';
    const tableNumber = document.getElementById('table-number-input')?.value?.trim() || '';
    
    if (!serviceType) {
        alert('Please select a service type.');
        return;
    }
    
    if (serviceType === 'delivery' && !deliveryAddress) {
        alert('Please enter your delivery address.');
        return;
    }
    
    const subtotal = cart.reduce((sum, item) => sum + (item.price * item.qty), 0);
    const deliveryFee = serviceType === 'delivery' ? 50 : 0;
    const totalAmount = subtotal + deliveryFee;
    
    const orderData = {
        restaurant_id: <?= $rid ?>,
        items_json: JSON.stringify(cart),
        subtotal: subtotal,
        delivery_fee: deliveryFee,
        total_amount: totalAmount,
        service_type: serviceType.replace('-', '_'),
        payment_method: paymentMethod,
        delivery_address: deliveryAddress,
        table_number: tableNumber,
        notes: ''
    };
    
    // Show loading
    const submitBtn = document.querySelector('#checkout-modal-overlay .btn-primary');
    if (!submitBtn) return;
    
    submitBtn.disabled = true;
    submitBtn.innerHTML = '<i class="fas fa-spinner fa-spin"></i> Opening PayMongo...';
    
    try {
        const response = await fetch('<?= BASE_URL ?>dashboard/customer/api/place_order.php', {
            method: 'POST',
            headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
            body: new URLSearchParams(orderData)
        });
        
        const data = await response.json();
        
        if (data.success) {
            // Check if PayMongo redirect is needed
            if (data.redirect_url) {
                // PayMongo checkout: redirect to hosted payment page
                window.location.href = data.redirect_url;
                return;
            }
            
            // Cash/Counter payment: immediate confirmation
            cart = [];
            localStorage.removeItem('cart_<?= $rid ?>');
            updateCartBadge();
            closeCheckoutModal();
            
            alert('Order placed successfully! Order #' + (data.order_number || 'confirmed'));
        } else {
            // Check if survey is required
            if (data.redirect_to_survey && data.survey_url) {
                if (confirm(data.message + '\n\nRedirect to survey now?')) {
                    window.location.href = data.survey_url;
                }
                submitBtn.disabled = false;
                submitBtn.innerHTML = '<i class="fas fa-check-circle"></i> Place Order';
                return;
            }
            
            alert(data.message || 'Failed to place order. Please try again.');
            submitBtn.disabled = false;
            submitBtn.innerHTML = '<i class="fas fa-check-circle"></i> Place Order';
        }
    } catch (error) {
        console.error('Order submission error:', error);
        alert('Network error. Please check your connection and try again.');
        submitBtn.disabled = false;
        submitBtn.innerHTML = '<i class="fas fa-check-circle"></i> Place Order';
    }
}

function escapeHtml(text) {
    const div = document.createElement('div');
    div.textContent = text;
    return div.innerHTML;
}

function showCartToast(msg, isErr) {
    const toast = document.createElement('div');
    toast.style.cssText = 'position:fixed;bottom:100px;right:24px;z-index:9997;' +
        'background:' + (isErr ? '#fef2f2' : '#f0fdf4') + ';' +
        'border:1.5px solid ' + (isErr ? '#fca5a5' : '#bbf7d0') + ';' +
        'color:' + (isErr ? '#991b1b' : '#166534') + ';' +
        'padding:12px 18px;border-radius:10px;font-size:.875rem;font-weight:600;' +
        'box-shadow:0 8px 24px rgba(0,0,0,.15);animation:slideInUp 0.3s ease;' +
        'display:flex;align-items:center;gap:8px;';
    toast.innerHTML = '<i class="fas fa-' + (isErr ? 'exclamation-circle' : 'check-circle') + '"></i>' + msg;
    document.body.appendChild(toast);
    setTimeout(() => {
        toast.style.opacity = '0';
        toast.style.transform = 'translateY(10px)';
        toast.style.transition = 'all 0.3s';
        setTimeout(() => toast.remove(), 300);
    }, 3000);
}

// Initialize cart badge on page load
updateCartBadge();
</script>

<?php
if (!empty($_GET['apply'])): ?>
<script>
    const menuSection = document.getElementById('menu-section');
    if (menuSection) {
        menuSection.scrollIntoView({ 
            behavior: 'smooth', 
            block: 'start'
        });
        // Highlight the menu section briefly
        menuSection.style.transition = 'box-shadow 0.3s';
        menuSection.style.boxShadow = '0 0 0 3px rgba(22, 163, 74, 0.3)';
        setTimeout(() => {
            menuSection.style.boxShadow = '';
        }, 1200);
    }
}

// ── Order modal trigger (integrate with existing order flow) ──────────────────
function openOrderModal() {
    // Check if menu section exists and has items
    const menuSection = document.getElementById('menu-section');
    if (!menuSection) {
        alert('Menu section not found. Please refresh the page.');
        return;
    }
    
    // Check if there are any menu items visible (more robust detection)
    const hasMenuItems = menuSection.textContent.toLowerCase().includes('main course') ||
                         menuSection.textContent.toLowerCase().includes('appetizer') ||
                         menuSection.querySelector('.card-body img') !== null ||
                         menuSection.querySelector('.card-body').children.length > 1;
    
    if (!hasMenuItems || menuSection.textContent.includes('coming soon')) {
        alert('This restaurant hasn\'t added menu items yet. Please check back soon!');
        return;
    }
    
    // Scroll to menu and show helpful toast
    scrollToMenu();
    
    // Show a helpful toast
    const toast = document.createElement('div');
    toast.style.cssText = 'position:fixed;bottom:24px;right:24px;z-index:9999;' +
        'background:#16a34a;color:#fff;padding:14px 20px;border-radius:12px;' +
        'font-size:.875rem;font-weight:600;box-shadow:0 8px 24px rgba(0,0,0,.2);' +
        'display:flex;align-items:center;gap:10px;animation:slideInUp 0.3s ease;';
    toast.innerHTML = '<i class="fas fa-info-circle"></i> Select items from the menu below to add to your order';
    document.body.appendChild(toast);
    setTimeout(() => {
        toast.style.opacity = '0';
        toast.style.transform = 'translateY(10px)';
        toast.style.transition = 'all 0.3s';
        setTimeout(() => toast.remove(), 300);
    }, 4000);
}

// Add animation keyframes
(function() {
    if (!document.querySelector('#orderCTAAnimations')) {
        const style = document.createElement('style');
        style.id = 'orderCTAAnimations';
        style.textContent = `
            @keyframes slideInUp {
                from { opacity: 0; transform: translateY(20px); }
                to { opacity: 1; transform: translateY(0); }
            }
        `;
        document.head.appendChild(style);
    }
})();

// ── Shopping Cart System ──────────────────────────────────────────────────────
let cart = JSON.parse(localStorage.getItem('cart_<?= $rid ?>') || '[]');

function addToCart(item) {
    // Check if item already exists in cart
    const existingIndex = cart.findIndex(i => i.id === item.id);
    
    if (existingIndex !== -1) {
        // Increment quantity
        cart[existingIndex].qty = (cart[existingIndex].qty || 1) + 1;
    } else {
        // Add new item with qty 1
        cart.push({ ...item, qty: 1 });
    }
    
    // Save to localStorage
    localStorage.setItem('cart_<?= $rid ?>', JSON.stringify(cart));
    
    // Show success toast
    showCartToast(`${item.name} added to cart!`, false);
    
    // Update cart badge if exists
    updateCartBadge();
}

function updateCartBadge() {
    const totalItems = cart.reduce((sum, item) => sum + (item.qty || 1), 0);
    let badge = document.getElementById('cart-badge');
    
    if (!badge) {
        // Create floating cart button if it doesn't exist
        const cartBtn = document.createElement('div');
        cartBtn.id = 'floating-cart-btn';
        cartBtn.style.cssText = 'position:fixed;bottom:24px;right:24px;z-index:9998;' +
            'background:linear-gradient(135deg,#6366f1,#4f46e5);color:#fff;' +
            'width:60px;height:60px;border-radius:50%;display:flex;align-items:center;' +
            'justify-content:center;font-size:1.4rem;cursor:pointer;' +
            'box-shadow:0 8px 24px rgba(99,102,241,0.4);transition:all 0.2s;';
        cartBtn.innerHTML = '<i class="fas fa-shopping-cart"></i>' +
            '<span id="cart-badge" style="position:absolute;top:-4px;right:-4px;' +
            'background:#dc2626;color:#fff;font-size:.7rem;font-weight:800;' +
            'width:24px;height:24px;border-radius:50%;display:flex;align-items:center;' +
            'justify-content:center;border:2px solid #fff;">' + totalItems + '</span>';
        cartBtn.onclick = openCheckout;
        cartBtn.onmouseover = function() { this.style.transform = 'scale(1.1)'; };
        cartBtn.onmouseout = function() { this.style.transform = 'scale(1)'; };
        document.body.appendChild(cartBtn);
        badge = document.getElementById('cart-badge');
    } else {
        badge.textContent = totalItems;
    }
    
    // Show/hide cart button
    const cartBtn = document.getElementById('floating-cart-btn');
    if (cartBtn) {
        cartBtn.style.display = totalItems > 0 ? 'flex' : 'none';
    }
}

async function openCheckout() {
    if (cart.length === 0) {
        alert('Your cart is empty. Add some items first!');
        return;
    }
    
    // ── Survey Gate: Check if user has completed TPB demand survey ───────────
    try {
        const surveyCheck = await fetch('<?= BASE_URL ?>api/check_survey_status.php', {
            method: 'POST',
            headers: { 'Content-Type': 'application/x-www-form-urlencoded' }
        });
        const surveyData = await surveyCheck.json();
        
        if (!surveyData.success) {
            alert('Unable to verify survey status. Please try again.');
            return;
        }
        
        if (!surveyData.completed) {
            // Show survey modal with questionnaire set
            showSurveyModal(surveyData.questionnaire_set);
            return;
        }
        
        // Survey completed — proceed to checkout
        showCheckoutModal();
        
    } catch (error) {
        console.error('Survey check error:', error);
        alert('Network error while checking survey status. Please try again.');
    }
}

function showCheckoutModal() {
    // Remove existing modal if any
    const existing = document.getElementById('checkout-modal-overlay');
    if (existing) existing.remove();
    
    // Calculate totals
    const subtotal = cart.reduce((sum, item) => sum + (item.price * item.qty), 0);
    const deliveryFee = 50; // Default delivery fee
    
    // Build modal
    const modal = document.createElement('div');
    modal.id = 'checkout-modal-overlay';
    modal.style.cssText = 'position:fixed;inset:0;background:rgba(0,0,0,0.6);z-index:10000;' +
        'display:flex;align-items:center;justify-content:center;padding:20px;animation:fadeIn 0.2s;';
    
    const modalContent = document.createElement('div');
    modalContent.style.cssText = 'background:#fff;border-radius:16px;max-width:600px;width:100%;' +
        'max-height:90vh;overflow-y:auto;box-shadow:0 20px 60px rgba(0,0,0,0.3);animation:slideInUp 0.3s;';
    
    modalContent.innerHTML = `
        <div style="padding:24px;border-bottom:2px solid var(--neutral-100);">
            <div style="display:flex;align-items:center;justify-content:space-between;">
                <h3 style="margin:0;display:flex;align-items:center;gap:10px;">
                    <i class="fas fa-shopping-cart" style="color:var(--primary-600);"></i>
                    Your Order
                </h3>
                <button onclick="closeCheckoutModal()" style="width:32px;height:32px;border:none;background:transparent;cursor:pointer;color:var(--neutral-400);font-size:1.2rem;">
                    <i class="fas fa-times"></i>
                </button>
            </div>
        </div>
        
        <div style="padding:20px 24px;">
            <!-- Cart Items -->
            <div style="margin-bottom:20px;">
                ${cart.map(item => `
                    <div style="display:flex;gap:12px;padding:12px;background:#f8fafc;border-radius:10px;margin-bottom:10px;align-items:center;">
                        ${item.image ? `<img src="${item.image}" style="width:50px;height:50px;object-fit:cover;border-radius:8px;">` : 
                          '<div style="width:50px;height:50px;background:#e2e8f0;border-radius:8px;display:flex;align-items:center;justify-content:center;"><i class="fas fa-utensils" style="color:#94a3b8;"></i></div>'}
                        <div style="flex:1;">
                            <div style="font-weight:600;font-size:0.9rem;">${escapeHtml(item.name)}</div>
                            <div style="font-size:0.8rem;color:var(--neutral-500);">₱${item.price.toFixed(2)} × ${item.qty}</div>
                        </div>
                        <div style="font-weight:700;color:var(--primary-700);">₱${(item.price * item.qty).toFixed(2)}</div>
                        <button onclick="removeFromCart(${item.id})" style="width:28px;height:28px;border:1px solid #fecaca;background:#fef2f2;border-radius:6px;cursor:pointer;color:#dc2626;">
                            <i class="fas fa-trash" style="font-size:0.7rem;"></i>
                        </button>
                    </div>
                `).join('')}
            </div>
            
            <!-- Service Type -->
            <div style="margin-bottom:16px;">
                <label style="display:block;font-weight:700;margin-bottom:8px;font-size:0.9rem;">Service Type</label>
                <div style="display:flex;gap:10px;">
                    <label style="flex:1;border:2px solid var(--neutral-200);border-radius:10px;padding:12px;cursor:pointer;display:flex;align-items:center;gap:8px;" onclick="updateServiceType('dine-in')">
                        <input type="radio" name="service_type_checkout" value="dine-in" checked>
                        <span style="font-weight:600;font-size:0.85rem;">🍽️ Dine-In</span>
                    </label>
                    <label style="flex:1;border:2px solid var(--neutral-200);border-radius:10px;padding:12px;cursor:pointer;display:flex;align-items:center;gap:8px;" onclick="updateServiceType('takeout')">
                        <input type="radio" name="service_type_checkout" value="takeout">
                        <span style="font-weight:600;font-size:0.85rem;">🛍️ Takeout</span>
                    </label>
                    <label style="flex:1;border:2px solid var(--neutral-200);border-radius:10px;padding:12px;cursor:pointer;display:flex;align-items:center;gap:8px;" onclick="updateServiceType('delivery')">
                        <input type="radio" name="service_type_checkout" value="delivery">
                        <span style="font-weight:600;font-size:0.85rem;">🚗 Delivery</span>
                    </label>
                </div>
            </div>
            
            <!-- Table Number (for dine-in, hidden by default) -->
            <div id="table-number-section" style="display:block;margin-bottom:16px;">
                <label style="display:block;font-weight:700;margin-bottom:6px;font-size:0.9rem;">
                    <i class="fas fa-chair" style="color:var(--primary-600);margin-right:4px;"></i>
                    Table Number <span style="font-weight:400;color:var(--neutral-400);font-size:0.8rem;">(optional)</span>
                </label>
                <input type="text" id="table-number-input" placeholder="e.g., Table 5, A1, etc." 
                       style="width:100%;border:1.5px solid var(--neutral-200);border-radius:8px;padding:10px;font-size:0.85rem;">
            </div>
            
            <!-- Delivery Address (hidden by default) -->
            <div id="delivery-address-section" style="display:none;margin-bottom:16px;">
                <label style="display:block;font-weight:700;margin-bottom:6px;font-size:0.9rem;">Delivery Address</label>
                <textarea id="delivery-address-input" rows="2" placeholder="Enter your full delivery address..." 
                          style="width:100%;border:1.5px solid var(--neutral-200);border-radius:8px;padding:10px;font-size:0.85rem;resize:vertical;"></textarea>
            </div>
            
            <!-- Order Summary -->
            <div style="background:#f0f9ff;border:1.5px solid #bae6fd;border-radius:10px;padding:14px;margin-bottom:16px;">
                <div style="display:flex;justify-content:space-between;margin-bottom:6px;">
                    <span style="font-size:0.85rem;color:var(--neutral-600);">Subtotal</span>
                    <span style="font-weight:600;">₱${subtotal.toFixed(2)}</span>
                </div>
                <div id="delivery-fee-row" style="display:none;justify-content:space-between;margin-bottom:6px;">
                    <span style="font-size:0.85rem;color:var(--neutral-600);">Delivery Fee</span>
                    <span style="font-weight:600;">₱${deliveryFee.toFixed(2)}</span>
                </div>
                <div style="display:flex;justify-content:space-between;padding-top:8px;border-top:1px dashed #bae6fd;">
                    <span style="font-weight:700;color:var(--neutral-800);">Total</span>
                    <span id="checkout-total" style="font-size:1.2rem;font-weight:800;color:var(--primary-700);">₱${subtotal.toFixed(2)}</span>
                </div>
            </div>
            
            <!-- Payment Method -->
            <div style="margin-bottom:16px;">
                <label style="display:block;font-weight:700;margin-bottom:8px;font-size:0.9rem;">Payment Method</label>
                <div style="display:flex;gap:10px;flex-wrap:wrap;">
                    <label id="payment-gcash-option" style="flex:1;min-width:45%;border:2px solid var(--neutral-200);border-radius:10px;padding:10px;cursor:pointer;display:flex;align-items:center;gap:8px;">
                        <input type="radio" name="payment_method_checkout" value="gcash" checked onchange="updatePaymentInstructions()">
                        <div style="display:flex;flex-direction:column;">
                            <span style="font-weight:700;font-size:0.9rem;">📱 GCash</span>
                            <span style="font-size:0.75rem;color:var(--neutral-600);margin-top:2px;">Pay Online</span>
                        </div>
                    </label>
                </div>
                <!-- Payment Instructions -->
                <div id="payment-instructions" style="margin-top:12px;padding:10px;border-radius:8px;background:var(--warning-50);border-left:4px solid var(--warning-600);">
                    <div style="display:flex;align-items:start;gap:8px;">
                        <i class="fas fa-info-circle" style="color:var(--warning-700);margin-top:2px;"></i>
                        <div style="font-size:0.82rem;color:var(--warning-900);">
                            <strong>Secure PayMongo payment:</strong> You will be redirected to PayMongo to pay <strong>₱` + subtotal.toFixed(2) + `</strong>. Your order is created only after PayMongo verifies payment.
                        </div>
                    </div>
                </div>
            </div>
        </div>
        
        <div style="padding:16px 24px;border-top:2px solid var(--neutral-100);background:#fafafa;">
            <button onclick="submitOrder()" class="btn btn-primary" style="width:100%;padding:14px;font-size:1rem;font-weight:700;display:flex;align-items:center;justify-content:center;gap:8px;">
                <i class="fas fa-check-circle"></i>
                <span>Continue to PayMongo</span>
            </button>
        </div>
    `;
    
    modal.appendChild(modalContent);
    document.body.appendChild(modal);
    
    // Close on backdrop click
    modal.addEventListener('click', (e) => {
        if (e.target === modal) closeCheckoutModal();
    });
}

function closeCheckoutModal() {
    const modal = document.getElementById('checkout-modal-overlay');
    if (modal) {
        modal.style.opacity = '0';
        setTimeout(() => modal.remove(), 200);
    }
}

function updatePaymentInstructions() {
    const instructionsDiv = document.getElementById('payment-instructions');
    const gcashOption = document.getElementById('payment-gcash-option');
    if (gcashOption) {
        gcashOption.style.borderColor = 'var(--primary-600)';
        gcashOption.style.background = 'var(--primary-50)';
    }
    const subtotal = cart.reduce((sum, item) => sum + (item.price * item.qty), 0);
    const serviceType = document.querySelector('input[name="service_type_checkout"]:checked')?.value || 'dine-in';
    const deliveryFee = serviceType === 'delivery' ? 50 : 0;
    const totalAmount = subtotal + deliveryFee;
    if (instructionsDiv) {
        instructionsDiv.style.display = 'block';
        instructionsDiv.style.background = 'var(--success-50)';
        instructionsDiv.style.borderLeftColor = 'var(--success-600)';
        instructionsDiv.innerHTML = `
            <div style="display:flex;align-items:start;gap:8px;">
                <i class="fas fa-shield-alt" style="color:var(--success-700);margin-top:2px;"></i>
                <div style="font-size:0.82rem;color:var(--success-900);">
                    <strong>Secure PayMongo payment:</strong> you will be redirected to PayMongo to pay <strong>₱${totalAmount.toFixed(2)}</strong>. The order is created only after PayMongo verifies payment.
                </div>
            </div>`;
    }
}

function updateServiceType(type) {
    const deliverySection = document.getElementById('delivery-address-section');
    const tableSection = document.getElementById('table-number-section');
    const deliveryFeeRow = document.getElementById('delivery-fee-row');
    const totalEl = document.getElementById('checkout-total');
    
    const subtotal = cart.reduce((sum, item) => sum + (item.price * item.qty), 0);
    const deliveryFee = 50;
    
    if (type === 'delivery') {
        deliverySection.style.display = 'block';
        tableSection.style.display = 'none';
        deliveryFeeRow.style.display = 'flex';
        totalEl.textContent = '₱' + (subtotal + deliveryFee).toFixed(2);
    } else if (type === 'dine-in') {
        deliverySection.style.display = 'none';
        tableSection.style.display = 'block';
        deliveryFeeRow.style.display = 'none';
        totalEl.textContent = '₱' + subtotal.toFixed(2);
    } else {
        // takeout
        deliverySection.style.display = 'none';
        tableSection.style.display = 'none';
        deliveryFeeRow.style.display = 'none';
        totalEl.textContent = '₱' + subtotal.toFixed(2);
    }
}

function removeFromCart(itemId) {
    cart = cart.filter(item => item.id !== itemId);
    localStorage.setItem('cart_<?= $rid ?>', JSON.stringify(cart));
    
    if (cart.length === 0) {
        closeCheckoutModal();
        updateCartBadge();
    } else {
        closeCheckoutModal();
        showCheckoutModal();
        updateCartBadge();
    }
}

async function submitOrder() {
    const serviceType = document.querySelector('input[name="service_type_checkout"]:checked')?.value;
    const paymentMethod = document.querySelector('input[name="payment_method_checkout"]:checked')?.value;
    const deliveryAddress = document.getElementById('delivery-address-input')?.value?.trim() || '';
    const tableNumber = document.getElementById('table-number-input')?.value?.trim() || '';
    
    if (!serviceType) {
        alert('Please select a service type.');
        return;
    }
    
    if (serviceType === 'delivery' && !deliveryAddress) {
        alert('Please enter your delivery address.');
        return;
    }
    
    const subtotal = cart.reduce((sum, item) => sum + (item.price * item.qty), 0);
    const deliveryFee = serviceType === 'delivery' ? 50 : 0;
    const totalAmount = subtotal + deliveryFee;
    
    const orderData = {
        restaurant_id: <?= $rid ?>,
        items_json: JSON.stringify(cart),
        subtotal: subtotal,
        delivery_fee: deliveryFee,
        total_amount: totalAmount,
        service_type: serviceType.replace('-', '_'),
        payment_method: paymentMethod,
        delivery_address: deliveryAddress,
        table_number: tableNumber,
        notes: ''
    };
    
    // Show loading
    const submitBtn = document.querySelector('#checkout-modal-overlay .btn-primary');
    if (!submitBtn) return;
    
    submitBtn.disabled = true;
    submitBtn.innerHTML = '<i class="fas fa-spinner fa-spin"></i> Opening PayMongo...';
    
    try {
        const response = await fetch('<?= BASE_URL ?>dashboard/customer/api/place_order.php', {
            method: 'POST',
            headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
            body: new URLSearchParams(orderData)
        });
        
        const data = await response.json();
        
        if (data.success) {
            // Check if PayMongo redirect is needed
            if (data.redirect_url) {
                // PayMongo checkout: redirect to hosted payment page
                window.location.href = data.redirect_url;
                return;
            }
            
            // Cash/Counter payment: immediate confirmation
            cart = [];
            localStorage.removeItem('cart_<?= $rid ?>');
            updateCartBadge();
            closeCheckoutModal();
            
            alert('Order placed successfully! Order #' + (data.order_number || 'confirmed'));
        } else {
            // Check if survey is required
            if (data.redirect_to_survey && data.survey_url) {
                if (confirm(data.message + '\n\nRedirect to survey now?')) {
                    window.location.href = data.survey_url;
                }
                submitBtn.disabled = false;
                submitBtn.innerHTML = '<i class="fas fa-check-circle"></i> Place Order';
                return;
            }
            
            alert(data.message || 'Failed to place order. Please try again.');
            submitBtn.disabled = false;
            submitBtn.innerHTML = '<i class="fas fa-check-circle"></i> Place Order';
        }
    } catch (error) {
        console.error('Order submission error:', error);
        alert('Network error. Please check your connection and try again.');
        submitBtn.disabled = false;
        submitBtn.innerHTML = '<i class="fas fa-check-circle"></i> Place Order';
    }
}

function escapeHtml(text) {
    const div = document.createElement('div');
    div.textContent = text;
    return div.innerHTML;
}

function showCartToast(msg, isErr) {
    const toast = document.createElement('div');
    toast.style.cssText = 'position:fixed;bottom:100px;right:24px;z-index:9997;' +
        'background:' + (isErr ? '#fef2f2' : '#f0fdf4') + ';' +
        'border:1.5px solid ' + (isErr ? '#fca5a5' : '#bbf7d0') + ';' +
        'color:' + (isErr ? '#991b1b' : '#166534') + ';' +
        'padding:12px 18px;border-radius:10px;font-size:.875rem;font-weight:600;' +
        'box-shadow:0 8px 24px rgba(0,0,0,.15);animation:slideInUp 0.3s ease;' +
        'display:flex;align-items:center;gap:8px;';
    toast.innerHTML = '<i class="fas fa-' + (isErr ? 'exclamation-circle' : 'check-circle') + '"></i>' + msg;
    document.body.appendChild(toast);
    setTimeout(() => {
        toast.style.opacity = '0';
        toast.style.transform = 'translateY(10px)';
        toast.style.transition = 'all 0.3s';
        setTimeout(() => toast.remove(), 300);
    }, 3000);
}

document.addEventListener('DOMContentLoaded', function () {
    if (typeof openStaffModal === 'function') openStaffModal();
});

// Display only: the order API recomputes this same fee server-side before checkout.
const CHECKOUT_PLATFORM_FEE = <?= json_encode($checkout_platform_fee, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) ?>;
const checkoutPeso = String.fromCharCode(0x20B1);
function refreshCheckoutPlatformFee() {
    const modal = document.getElementById('checkout-modal-overlay');
    if (!modal) return;
    const totalEl = document.getElementById('checkout-total');
    if (!totalEl) return;
    const serviceType = document.querySelector('input[name="service_type_checkout"]:checked')?.value || 'dine-in';
    const paymentMethod = document.querySelector('input[name="payment_method_checkout"]:checked')?.value || 'gcash';
    const subtotal = cart.reduce((sum, item) => sum + (item.price * item.qty), 0);
    const deliveryFee = serviceType === 'delivery' ? 50 : 0;
    const eligible = ['gcash', 'bank_transfer', 'paymongo', 'card'].includes(paymentMethod);
    const fee = CHECKOUT_PLATFORM_FEE.enabled && eligible ? Number(((subtotal + deliveryFee) * Number(CHECKOUT_PLATFORM_FEE.rate)).toFixed(2)) : 0;
    let feeRow = document.getElementById('platform-fee-row');
    if (!feeRow) {
        feeRow = document.createElement('div');
        feeRow.id = 'platform-fee-row';
        feeRow.style.cssText = 'display:none;justify-content:space-between;margin-bottom:6px;';
        totalEl.parentElement.parentElement.insertBefore(feeRow, totalEl.parentElement);
    }
    feeRow.style.display = fee > 0 ? 'flex' : 'none';
    if (fee > 0) feeRow.innerHTML = '<span style="font-size:.85rem;color:var(--neutral-600);">' + escapeHtml(CHECKOUT_PLATFORM_FEE.label) + ' (' + (Number(CHECKOUT_PLATFORM_FEE.rate) * 100).toFixed(2) + '%)</span><span style="font-weight:600;">' + checkoutPeso + fee.toFixed(2) + '</span>';
    totalEl.textContent = checkoutPeso + (subtotal + deliveryFee + fee).toFixed(2);
    const instructions = document.getElementById('payment-instructions');
    if (instructions && paymentMethod !== 'cash' && fee > 0) {
        instructions.innerHTML = '<div style="display:flex;align-items:start;gap:8px;"><i class="fas fa-mobile-alt" style="color:var(--success-700);margin-top:2px;"></i><div style="font-size:0.82rem;color:var(--success-900);"><strong>Online Payment:</strong> You will be redirected to PayMongo to complete your payment of <strong>' + checkoutPeso + (subtotal + deliveryFee + fee).toFixed(2) + '</strong>. This includes the ' + escapeHtml(CHECKOUT_PLATFORM_FEE.label) + ' of ' + checkoutPeso + fee.toFixed(2) + '.</div></div>';
    }
}
const baseShowCheckoutModal = showCheckoutModal;
showCheckoutModal = function () { baseShowCheckoutModal(); refreshCheckoutPlatformFee(); };
const baseUpdatePaymentInstructions = updatePaymentInstructions;
updatePaymentInstructions = function () { baseUpdatePaymentInstructions(); refreshCheckoutPlatformFee(); };
const baseUpdateServiceType = updateServiceType;
updateServiceType = function (type) { baseUpdateServiceType(type); baseUpdatePaymentInstructions(); refreshCheckoutPlatformFee(); };
</script>
<?php endif; ?>
