<?php
require_once __DIR__ . '/../../config/app.php';
requireLogin();
requireRole([ROLE_CUSTOMER]);

$page_title = 'Customer Dashboard';
$page_heading = 'Dashboard';
$is_dashboard = true;
$user_id = $_SESSION['user_id'];

$order_count = $conn->prepare("SELECT COUNT(*) as c FROM orders WHERE customer_id = ?");
$order_count->bind_param("i", $user_id);
$order_count->execute();
$total_orders = $order_count->get_result()->fetch_assoc()['c'];

$review_count = $conn->prepare("SELECT COUNT(*) as c FROM reviews WHERE customer_id = ?");
$review_count->bind_param("i", $user_id);
$review_count->execute();
$total_reviews = $review_count->get_result()->fetch_assoc()['c'];

$restaurant_count = $conn->query("SELECT COUNT(*) as c FROM halal_restaurants WHERE is_active = 1")->fetch_assoc()['c'];

$pending_orders = $conn->prepare("SELECT COUNT(*) as c FROM orders WHERE customer_id = ? AND order_status NOT IN ('delivered','cancelled')");
$pending_orders->bind_param("i", $user_id);
$pending_orders->execute();
$active_orders = $pending_orders->get_result()->fetch_assoc()['c'];

// ── Auto-create business_applications tables if they don't exist yet ─────────
// This removes the need to manually run the SQL migration in phpMyAdmin.
$conn->query("
    CREATE TABLE IF NOT EXISTS `business_applications` (
        `id`                   INT(11)          NOT NULL AUTO_INCREMENT,
        `user_id`              INT(11)          NOT NULL,
        `certification_path`   ENUM('certified','not_certified') NOT NULL,
        `business_name`        VARCHAR(255)     DEFAULT NULL,
        `business_description` TEXT             NOT NULL,
        `address_street`       VARCHAR(255)     DEFAULT NULL,
        `address_city`         VARCHAR(100)     DEFAULT NULL,
        `address_province`     VARCHAR(100)     DEFAULT NULL,
        `address_zip`          VARCHAR(20)      DEFAULT NULL,
        `latitude`             DECIMAL(10,8)    DEFAULT NULL,
        `longitude`            DECIMAL(11,8)    DEFAULT NULL,
        `owner_name`           VARCHAR(255)     NOT NULL DEFAULT '',
        `owner_age`            TINYINT UNSIGNED DEFAULT NULL,
        `owner_religion`       VARCHAR(100)     DEFAULT NULL,
        `status`               ENUM('pending','under_review','approved','rejected','returned')
                               NOT NULL DEFAULT 'pending',
        `admin_notes`          TEXT             DEFAULT NULL,
        `reviewed_by`          INT(11)          DEFAULT NULL,
        `reviewed_at`          DATETIME         DEFAULT NULL,
        `created_at`           TIMESTAMP        NOT NULL DEFAULT CURRENT_TIMESTAMP,
        `updated_at`           TIMESTAMP        NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
        PRIMARY KEY (`id`),
        KEY `idx_ba_user`   (`user_id`),
        KEY `idx_ba_status` (`status`),
        CONSTRAINT `ba_user_fk`
            FOREIGN KEY (`user_id`) REFERENCES `users` (`id`) ON DELETE CASCADE
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
");

$conn->query("
    CREATE TABLE IF NOT EXISTS `business_application_documents` (
        `id`             INT(11)      NOT NULL AUTO_INCREMENT,
        `application_id` INT(11)      NOT NULL,
        `doc_type`       ENUM(
                             'halal_certificate','halal_assurance_manual',
                             'business_permit','sanitary_permit','lto_permit','dti_license',
                             'proof_of_halal','halal_cert_raw_materials',
                             'halal_assurance_manual_opt'
                         ) NOT NULL,
        `original_name`  VARCHAR(255) NOT NULL,
        `file_path`      VARCHAR(500) NOT NULL,
        `file_size`      INT UNSIGNED DEFAULT NULL,
        `mime_type`      VARCHAR(100) DEFAULT NULL,
        `uploaded_at`    TIMESTAMP    NOT NULL DEFAULT CURRENT_TIMESTAMP,
        PRIMARY KEY (`id`),
        KEY `idx_bad_app` (`application_id`),
        CONSTRAINT `bad_app_fk`
            FOREIGN KEY (`application_id`)
            REFERENCES `business_applications` (`id`) ON DELETE CASCADE
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
");

// Check for an existing business application (drives the CTA banner state)
$biz_app = null;
$biz_app_stmt = $conn->prepare(
    "SELECT id, status, certification_path, created_at FROM business_applications WHERE user_id = ? ORDER BY created_at DESC LIMIT 1"
);
$biz_app_stmt->bind_param("i", $user_id);
$biz_app_stmt->execute();
$biz_app = $biz_app_stmt->get_result()->fetch_assoc();

// My staff application (for hero badge)
$my_sa_stmt = $conn->prepare(
    "SELECT id, status, applied_role, restaurant_id FROM staff_applications
     WHERE user_id = ? ORDER BY created_at DESC LIMIT 1"
);
$my_sa_stmt->bind_param("i", $user_id);
$my_sa_stmt->execute();
$my_latest_sa = $my_sa_stmt->get_result()->fetch_assoc();
$my_sa_stmt->close();

// Recent orders
$recent_orders = $conn->prepare("SELECT o.*, hr.restaurant_name FROM orders o JOIN halal_restaurants hr ON o.restaurant_id = hr.id WHERE o.customer_id = ? ORDER BY o.created_at DESC LIMIT 5");
$recent_orders->bind_param("i", $user_id);
$recent_orders->execute();
$orders = $recent_orders->get_result()->fetch_all(MYSQLI_ASSOC);

// ── Personalised recommendations ─────────────────────────────────────────────
// Fetch the user's demographic preferences
$pref_stmt = $conn->prepare("SELECT food_preference, cuisine_preferences, city FROM users WHERE id = ?");
$pref_stmt->bind_param("i", $user_id);
$pref_stmt->execute();
$user_prefs = $pref_stmt->get_result()->fetch_assoc();
$pref_stmt->close();

$pref_food     = $user_prefs['food_preference']    ?? '';
$pref_cuisines = $user_prefs['cuisine_preferences'] ?? '';
$pref_city     = $user_prefs['city']               ?? '';

// Build the WHERE clause conditions for matching
// 1. Food preference → halal_classification match via correlated subquery
$food_map = [
    'Halal'           => ['Halal'],
    'Non-Pork'        => ['Non-Pork', 'Halal'],
    'Muslim-Friendly' => ['Muslim Friendly', 'Halal'],
    'Any'             => ['Halal', 'Muslim Friendly', 'Non-Pork', 'N/A'],
];
$allowed_classes  = $food_map[$pref_food] ?? [];
$class_conditions = [];
if (!empty($allowed_classes)) {
    $quoted = implode(',', array_map(fn($c) => "'" . $conn->real_escape_string($c) . "'", $allowed_classes));
    // Inline correlated subquery — safe to use in score expression
    $class_conditions[] = "(SELECT ba_sc.halal_classification FROM business_applications ba_sc
                             WHERE ba_sc.user_id = hr.business_owner_id AND ba_sc.status = 'approved'
                             ORDER BY ba_sc.created_at DESC LIMIT 1) IN ($quoted)";
}

// 2. Cuisine preferences → FIND_IN_SET match
$cuisine_list  = array_filter(array_map('trim', explode(',', $pref_cuisines)));
$cuisine_conds = [];
foreach ($cuisine_list as $c) {
    $c_esc           = $conn->real_escape_string($c);
    $cuisine_conds[] = "FIND_IN_SET('$c_esc', REPLACE(hr.cuisine_type, ', ', ','))";
}

// 3. City match
$city_cond = '';
if (!empty($pref_city)) {
    $city_esc  = $conn->real_escape_string($pref_city);
    $city_cond = "hr.city = '$city_esc'";
}

// Score: city match = 3 pts, food class match = 2 pts, cuisine match = 1 pt each
$score_expr  = '0';
if ($city_cond)                $score_expr .= " + IF($city_cond, 3, 0)";
if ($class_conditions)         $score_expr .= ' + IF((' . $class_conditions[0] . '), 2, 0)';
foreach ($cuisine_conds as $cc) $score_expr .= " + IF($cc, 1, 0)";

$rec_sql = "
    SELECT hr.*,
           COALESCE(hc1.id, hc2.id)              AS cert_id,
           COALESCE(hc1.certificate_number, hc2.certificate_number) AS certificate_number,
           COALESCE(hc1.status, hc2.status)       AS cert_status,
           CASE
               WHEN hc1.id IS NULL AND hc2.id IS NULL
                    AND EXISTS (
                        SELECT 1 FROM business_applications bx
                        WHERE bx.user_id = hr.business_owner_id
                          AND bx.certification_path = 'certified'
                          AND bx.status = 'approved'
                    ) THEN 1 ELSE 0
           END AS is_bypass_certified,
           (SELECT ba2.halal_classification
            FROM business_applications ba2
            WHERE ba2.user_id = hr.business_owner_id AND ba2.status = 'approved'
            ORDER BY ba2.created_at DESC LIMIT 1) AS halal_classification,
           ($score_expr) AS match_score
    FROM halal_restaurants hr
    LEFT JOIN halal_certificates hc1
        ON hc1.id = hr.certificate_id
        AND hc1.status NOT IN ('rejected','revoked','expired','suspended')
    LEFT JOIN halal_certificates hc2
        ON hr.certificate_id IS NULL
        AND hc2.application_id = (
            SELECT ha.id FROM hdp_applications ha
            WHERE ha.business_owner_id = hr.business_owner_id
            ORDER BY ha.created_at DESC LIMIT 1
        )
        AND hc2.status NOT IN ('rejected','revoked','expired','suspended')
    WHERE hr.is_active = 1
    HAVING match_score > 0
    ORDER BY match_score DESC, hr.avg_rating DESC
    LIMIT 6
";
$recommendations = $conn->query($rec_sql)?->fetch_all(MYSQLI_ASSOC) ?? [];

// Fallback: if no preferences set or no matches, show top-rated
$show_recs = !empty($pref_food) || !empty($pref_cuisines);
$has_recs  = !empty($recommendations);
if ($show_recs && !$has_recs) {
    $recommendations = $conn->query("
        SELECT hr.*, hc.id AS cert_id, hc.certificate_number, hc.status AS cert_status,
               0 AS is_bypass_certified, NULL AS halal_classification, 0 AS match_score
        FROM halal_restaurants hr
        LEFT JOIN halal_certificates hc ON hc.id = hr.certificate_id
            AND hc.status NOT IN ('rejected','revoked','expired','suspended')
        WHERE hr.is_active = 1
        ORDER BY hr.avg_rating DESC LIMIT 6
    ")?->fetch_all(MYSQLI_ASSOC) ?? [];
    $has_recs = !empty($recommendations);
}

// ── Nearby restaurants — all active restaurants in the user's city ────────────
$nearby_restaurants = [];
if (!empty($pref_city)) {
    $rec_ids        = array_column($recommendations, 'id');
    $exclude_clause = !empty($rec_ids)
        ? 'AND hr.id NOT IN (' . implode(',', array_map('intval', $rec_ids)) . ')'
        : '';
    $city_esc_nb    = $conn->real_escape_string($pref_city);
    $nearby_restaurants = $conn->query("
        SELECT hr.*,
               COALESCE(hc1.id, hc2.id)                                   AS cert_id,
               COALESCE(hc1.certificate_number, hc2.certificate_number)   AS certificate_number,
               COALESCE(hc1.status,      hc2.status)                      AS cert_status,
               CASE
                   WHEN hc1.id IS NULL AND hc2.id IS NULL
                        AND EXISTS (
                            SELECT 1 FROM business_applications bx
                            WHERE bx.user_id = hr.business_owner_id
                              AND bx.certification_path = 'certified'
                              AND bx.status = 'approved'
                        ) THEN 1 ELSE 0
               END AS is_bypass_certified,
               (SELECT ba2.halal_classification
                FROM business_applications ba2
                WHERE ba2.user_id = hr.business_owner_id AND ba2.status = 'approved'
                ORDER BY ba2.created_at DESC LIMIT 1) AS halal_classification
        FROM halal_restaurants hr
        LEFT JOIN halal_certificates hc1
            ON hc1.id = hr.certificate_id
            AND hc1.status NOT IN ('rejected','revoked','expired','suspended')
        LEFT JOIN halal_certificates hc2
            ON hr.certificate_id IS NULL
            AND hc2.application_id = (
                SELECT ha.id FROM hdp_applications ha
                WHERE ha.business_owner_id = hr.business_owner_id
                ORDER BY ha.created_at DESC LIMIT 1
            )
            AND hc2.status NOT IN ('rejected','revoked','expired','suspended')
        WHERE hr.is_active = 1
          AND hr.city = '$city_esc_nb'
          $exclude_clause
        ORDER BY hr.avg_rating DESC
        LIMIT 12
    ")?->fetch_all(MYSQLI_ASSOC) ?? [];
}

require_once __DIR__ . '/../../includes/header.php';
?>

<?php
$sa_status     = $my_latest_sa['status']       ?? null;
$sa_role_label = ['ops_manager' => 'Operations Manager', 'cashier' => 'Cashier', 'chef' => 'Chef'][$my_latest_sa['applied_role'] ?? ''] ?? '';
$sa_badge = [
    'pending'  => ['bg' => '#fef9c3', 'color' => '#854d0e', 'border' => '#fde68a', 'icon' => 'fas fa-hourglass-half', 'text' => 'Application Pending'],
    'approved' => ['bg' => '#dcfce7', 'color' => '#166534', 'border' => '#bbf7d0', 'icon' => 'fas fa-check-circle',   'text' => 'Application Approved'],
    'rejected' => ['bg' => '#fee2e2', 'color' => '#991b1b', 'border' => '#fecaca', 'icon' => 'fas fa-times-circle',   'text' => 'Application Rejected'],
][$sa_status ?? ''] ?? null;

$biz_status       = $biz_app['status'] ?? null;
$already_approved = ($biz_status === 'approved');
?>

<!-- ══ DISCOVERY HERO ═══════════════════════════════════════════════════════ -->
<div style="background:linear-gradient(135deg,var(--primary-900) 0%,var(--primary-700) 60%,#047857 100%);
            border-radius:var(--radius-xl);padding:40px 36px;margin-bottom:24px;
            position:relative;overflow:hidden;">
    <!-- decorative circles -->
    <div style="position:absolute;right:-60px;top:-60px;width:260px;height:260px;border-radius:50%;background:rgba(255,255,255,.05);pointer-events:none;"></div>
    <div style="position:absolute;right:80px;bottom:-80px;width:180px;height:180px;border-radius:50%;background:rgba(255,255,255,.04);pointer-events:none;"></div>

    <div style="display:flex;align-items:center;justify-content:space-between;flex-wrap:wrap;gap:24px;position:relative;">
        <div style="flex:1;min-width:260px;">
            <div style="font-size:.78rem;font-weight:700;letter-spacing:.08em;text-transform:uppercase;
                        color:#6ee7b7;margin-bottom:10px;">
                <i class="fas fa-certificate" style="margin-right:6px;"></i>Halal Verified Marketplace
            </div>
            <h1 style="font-family:var(--font-display);font-size:2rem;font-weight:800;color:#fff;
                       margin:0 0 10px;line-height:1.2;">
                Discover Halal Eateries
            </h1>
            <p style="color:rgba(255,255,255,.75);font-size:.95rem;line-height:1.6;margin:0 0 20px;">
                Find certified halal restaurants, browse menus, and order your favorite meals.
            </p>
            <!-- Quick stats strip -->
            <div style="display:flex;gap:20px;flex-wrap:wrap;">
                <div style="display:flex;align-items:center;gap:8px;">
                    <span style="width:32px;height:32px;border-radius:8px;background:rgba(255,255,255,.12);
                                 display:flex;align-items:center;justify-content:center;flex-shrink:0;">
                        <i class="fas fa-utensils" style="color:#34d399;font-size:.8rem;"></i>
                    </span>
                    <div>
                        <div style="font-size:1.1rem;font-weight:800;color:#fff;"><?= $restaurant_count ?></div>
                        <div style="font-size:.68rem;color:rgba(255,255,255,.55);">Restaurants</div>
                    </div>
                </div>
                <div style="display:flex;align-items:center;gap:8px;">
                    <span style="width:32px;height:32px;border-radius:8px;background:rgba(255,255,255,.12);
                                 display:flex;align-items:center;justify-content:center;flex-shrink:0;">
                        <i class="fas fa-shopping-bag" style="color:#fbbf24;font-size:.8rem;"></i>
                    </span>
                    <div>
                        <div style="font-size:1.1rem;font-weight:800;color:#fff;"><?= $total_orders ?></div>
                        <div style="font-size:.68rem;color:rgba(255,255,255,.55);">My Orders</div>
                    </div>
                </div>
                <div style="display:flex;align-items:center;gap:8px;">
                    <span style="width:32px;height:32px;border-radius:8px;background:rgba(255,255,255,.12);
                                 display:flex;align-items:center;justify-content:center;flex-shrink:0;">
                        <i class="fas fa-star" style="color:#fbbf24;font-size:.8rem;"></i>
                    </span>
                    <div>
                        <div style="font-size:1.1rem;font-weight:800;color:#fff;"><?= $total_reviews ?></div>
                        <div style="font-size:.68rem;color:rgba(255,255,255,.55);">Reviews</div>
                    </div>
                </div>
            </div>
        </div>

        <!-- Action buttons -->
        <div style="display:flex;flex-direction:column;gap:10px;flex-shrink:0;">
            <a href="<?= BASE_URL ?>dashboard/customer/restaurants.php"
               style="display:inline-flex;align-items:center;gap:10px;background:#fff;
                      color:var(--primary-800);padding:14px 26px;border-radius:var(--radius-md);
                      font-weight:700;font-size:.95rem;text-decoration:none;
                      transition:opacity .15s;white-space:nowrap;"
               onmouseover="this.style.opacity='.88'" onmouseout="this.style.opacity='1'">
                <i class="fas fa-search"></i> Browse Restaurants
                <i class="fas fa-arrow-right" style="font-size:.78rem;"></i>
            </a>
            <?php if ($sa_badge): ?>
            <a href="<?= BASE_URL ?>dashboard/customer/my_staff_applications.php"
               style="display:inline-flex;align-items:center;gap:10px;
                      background:<?= $sa_badge['bg'] ?>;color:<?= $sa_badge['color'] ?>;
                      border:1.5px solid <?= $sa_badge['border'] ?>;
                      padding:12px 22px;border-radius:var(--radius-md);
                      font-weight:700;font-size:.88rem;text-decoration:none;white-space:nowrap;">
                <i class="<?= $sa_badge['icon'] ?>"></i> <?= $sa_badge['text'] ?>
                <?php if ($sa_role_label): ?> — <?= htmlspecialchars($sa_role_label) ?><?php endif; ?>
            </a>
            <?php else: ?>
            <a href="<?= BASE_URL ?>dashboard/customer/restaurants.php"
               style="display:inline-flex;align-items:center;gap:10px;
                      background:rgba(255,255,255,.12);border:1.5px solid rgba(255,255,255,.25);
                      color:#fff;padding:12px 22px;border-radius:var(--radius-md);
                      font-weight:700;font-size:.88rem;text-decoration:none;
                      transition:background .15s;white-space:nowrap;"
               onmouseover="this.style.background='rgba(255,255,255,.2)'"
               onmouseout="this.style.background='rgba(255,255,255,.12)'">
                <i class="fas fa-paper-plane"></i> Apply for a Position
            </a>
            <?php endif; ?>
        </div>
    </div>
</div>

<!-- ══ CUISINE FILTER CHIPS ════════════════════════════════════════════════ -->
<div style="display:flex;gap:8px;flex-wrap:wrap;margin-bottom:24px;align-items:center;">
    <span style="font-size:.78rem;font-weight:600;color:var(--neutral-500);margin-right:4px;">Discover:</span>
    <?php
    $chips = [
        ['icon'=>'fas fa-check-double','label'=>'Halal Certified',  'url'=>'restaurants.php?search=',          'color'=>'var(--primary-700)','bg'=>'#dcfce7','border'=>'#bbf7d0'],
        ['icon'=>'fas fa-leaf',        'label'=>'All Cuisines',     'url'=>'restaurants.php',                  'color'=>'#1e40af','bg'=>'#dbeafe','border'=>'#bfdbfe'],
        ['icon'=>'fas fa-moon',        'label'=>'Middle Eastern',   'url'=>'restaurants.php?cuisine=Middle+Eastern','color'=>'#92400e','bg'=>'#fef3c7','border'=>'#fde68a'],
        ['icon'=>'fas fa-bowl-rice',   'label'=>'Asian',            'url'=>'restaurants.php?cuisine=Filipino', 'color'=>'#7e22ce','bg'=>'#f3e8ff','border'=>'#e9d5ff'],
        ['icon'=>'fas fa-burger',      'label'=>'Western',          'url'=>'restaurants.php?cuisine=Western',  'color'=>'#9a3412','bg'=>'#ffedd5','border'=>'#fed7aa'],
    ];
    foreach ($chips as $chip):
    ?>
    <a href="<?= BASE_URL ?>dashboard/customer/<?= $chip['url'] ?>"
       style="display:inline-flex;align-items:center;gap:6px;padding:7px 14px;
              border-radius:999px;font-size:.8rem;font-weight:600;text-decoration:none;
              background:<?= $chip['bg'] ?>;color:<?= $chip['color'] ?>;
              border:1px solid <?= $chip['border'] ?>;transition:opacity .15s;"
       onmouseover="this.style.opacity='.78'" onmouseout="this.style.opacity='1'">
        <i class="<?= $chip['icon'] ?>" style="font-size:.72rem;"></i>
        <?= $chip['label'] ?>
    </a>
    <?php endforeach; ?>
</div>

<!-- ══ PERSONALISED RECOMMENDATIONS ════════════════════════════════════════ -->
<?php if ($show_recs && $has_recs): ?>
<div style="margin-bottom:28px;">
    <!-- Section header -->
    <div style="display:flex;align-items:center;justify-content:space-between;margin-bottom:14px;flex-wrap:wrap;gap:8px;">
        <div>
            <h2 style="font-family:var(--font-display);font-size:1.1rem;font-weight:800;
                       color:var(--neutral-800);margin:0 0 3px;display:flex;align-items:center;gap:8px;">
                <i class="fas fa-wand-magic-sparkles" style="color:var(--accent-500);"></i>
                Recommended for You
            </h2>
            <p style="font-size:.78rem;color:var(--neutral-500);margin:0;">
                Based on your
                <?php
                $why = [];
                if ($pref_food)     $why[] = '<strong>' . htmlspecialchars($pref_food) . '</strong> preference';
                if ($pref_cuisines) $why[] = 'love of <strong>' . htmlspecialchars($pref_cuisines) . '</strong>';
                if ($pref_city)     $why[] = 'location in <strong>' . htmlspecialchars($pref_city) . '</strong>';
                echo implode(', ', $why) ?: 'your profile';
                ?>
            </p>
        </div>
        <a href="<?= BASE_URL ?>dashboard/customer/restaurants.php"
           style="font-size:.8rem;color:var(--primary-600);font-weight:600;text-decoration:none;
                  display:flex;align-items:center;gap:4px;white-space:nowrap;">
            See all restaurants <i class="fas fa-arrow-right" style="font-size:.7rem;"></i>
        </a>
    </div>

    <!-- Cards grid -->
    <div style="display:grid;grid-template-columns:repeat(auto-fill,minmax(260px,1fr));gap:16px;">
        <?php foreach ($recommendations as $rec):
            $is_cert = $rec['cert_id'] || $rec['certificate_number'] || $rec['is_bypass_certified'];
            $hclass  = $rec['halal_classification'] ?? '';
            // Badge config
            $badge = null;
            if ($is_cert) {
                $badge = ['label'=>'Halal Certified','bg'=>'#064e3b','color'=>'#ffffff','icon'=>'fas fa-certificate'];
            } elseif ($hclass === 'Muslim Friendly') {
                $badge = ['label'=>'Muslim Friendly','bg'=>'#1e3a5f','color'=>'#ffffff','icon'=>'fas fa-star-and-crescent'];
            } elseif ($hclass === 'Non-Pork') {
                $badge = ['label'=>'Pork-Free','bg'=>'#7c1d1d','color'=>'#ffffff','icon'=>'fas fa-ban'];
            }
            $rating  = (float)($rec['avg_rating'] ?? 0);
            $reviews = (int)($rec['total_reviews'] ?? 0);
            $cuisines = array_map('trim', explode(',', $rec['cuisine_type'] ?? ''));
        ?>
        <a href="<?= BASE_URL ?>dashboard/customer/restaurants.php?action=view&id=<?= $rec['id'] ?>"
           style="text-decoration:none;display:block;" class="rec-card">
            <div class="card" style="height:100%;transition:box-shadow .18s,transform .18s;overflow:hidden;">
                <!-- Image / placeholder -->
                <div style="position:relative;height:140px;overflow:hidden;background:linear-gradient(135deg,#064e3b,#065f46);">
                    <?php if (!empty($rec['image_path'])): ?>
                    <img src="<?= BASE_URL ?>uploads/<?= htmlspecialchars($rec['image_path']) ?>"
                         alt="<?= htmlspecialchars($rec['restaurant_name']) ?>"
                         style="width:100%;height:100%;object-fit:cover;">
                    <div style="position:absolute;inset:0;background:linear-gradient(to top,rgba(0,0,0,.4) 0%,transparent 55%);pointer-events:none;"></div>
                    <?php else: ?>
                    <div style="display:flex;align-items:center;justify-content:center;height:100%;">
                        <i class="fas fa-store" style="font-size:2.5rem;color:rgba(255,255,255,.25);"></i>
                    </div>
                    <?php endif; ?>
                    <!-- Halal badge -->
                    <?php if ($badge): ?>
                    <div style="position:absolute;top:10px;left:10px;
                                background:<?= $badge['bg'] ?>;color:<?= $badge['color'] ?>;
                                padding:4px 10px;border-radius:999px;font-size:.68rem;font-weight:700;
                                display:flex;align-items:center;gap:4px;box-shadow:0 2px 6px rgba(0,0,0,.25);">
                        <i class="<?= $badge['icon'] ?>"></i> <?= $badge['label'] ?>
                    </div>
                    <?php endif; ?>
                    <!-- Rating -->
                    <?php if ($rating > 0): ?>
                    <div style="position:absolute;bottom:8px;right:10px;
                                background:rgba(0,0,0,.55);backdrop-filter:blur(4px);
                                color:#fff;padding:3px 8px;border-radius:999px;
                                font-size:.72rem;font-weight:700;display:flex;align-items:center;gap:4px;">
                        <i class="fas fa-star" style="color:#fbbf24;font-size:.6rem;"></i>
                        <?= number_format($rating, 1) ?>
                        <span style="opacity:.7;">(<?= $reviews ?>)</span>
                    </div>
                    <?php endif; ?>
                </div>
                <!-- Info -->
                <div style="padding:14px 16px;">
                    <div style="font-weight:700;font-size:.92rem;color:var(--neutral-800);
                                margin-bottom:4px;white-space:nowrap;overflow:hidden;text-overflow:ellipsis;">
                        <?= htmlspecialchars($rec['restaurant_name']) ?>
                    </div>
                    <?php if (!empty($rec['city'])): ?>
                    <div style="font-size:.75rem;color:var(--neutral-500);margin-bottom:7px;
                                display:flex;align-items:center;gap:4px;">
                        <i class="fas fa-location-dot" style="color:var(--primary-400);font-size:.65rem;"></i>
                        <?= htmlspecialchars($rec['city']) ?>
                    </div>
                    <?php endif; ?>
                    <!-- Cuisine pills -->
                    <?php if ($cuisines[0] ?? ''): ?>
                    <div style="display:flex;flex-wrap:wrap;gap:4px;">
                        <?php foreach (array_slice($cuisines, 0, 3) as $_ct): if (!$_ct) continue; ?>
                        <span style="background:var(--primary-50);color:var(--primary-700);
                                     border:1px solid var(--primary-100);padding:2px 8px;
                                     border-radius:999px;font-size:.68rem;font-weight:600;">
                            <?= htmlspecialchars($_ct) ?>
                        </span>
                        <?php endforeach; ?>
                    </div>
                    <?php endif; ?>
                </div>
            </div>
        </a>
        <?php endforeach; ?>
    </div>
</div>
<style>
.rec-card:hover .card { box-shadow:0 8px 24px rgba(0,0,0,.10); transform:translateY(-2px); }
</style>
<?php elseif (!$show_recs): ?>
<!-- Prompt to complete profile when no preferences set -->
<div style="background:linear-gradient(135deg,#f0fdf4,#fef9c3);border:1.5px solid #bbf7d0;
            border-radius:var(--radius-lg);padding:18px 22px;margin-bottom:24px;
            display:flex;align-items:center;gap:14px;flex-wrap:wrap;">
    <div style="width:40px;height:40px;border-radius:10px;background:var(--primary-600);
                display:flex;align-items:center;justify-content:center;flex-shrink:0;">
        <i class="fas fa-user-circle" style="color:#fff;font-size:1.1rem;"></i>
    </div>
    <div style="flex:1;min-width:200px;">
        <div style="font-weight:700;font-size:.9rem;color:var(--neutral-800);margin-bottom:2px;">
            Get personalised restaurant suggestions
        </div>
        <div style="font-size:.8rem;color:var(--neutral-500);">
            Complete your food preferences and location in your profile to see restaurants tailored to you.
        </div>
    </div>
    <a href="<?= BASE_URL ?>dashboard/profile.php"
       style="display:inline-flex;align-items:center;gap:6px;padding:8px 16px;
              background:var(--primary-600);color:#fff;border-radius:8px;
              font-size:.82rem;font-weight:700;text-decoration:none;flex-shrink:0;">
        <i class="fas fa-pencil"></i> Complete Profile
    </a>
</div>
<?php endif; ?>

<!-- ══ NEARBY RESTAURANTS ═══════════════════════════════════════════════════ -->
<?php if (!empty($nearby_restaurants)): ?>
<div style="margin-bottom:28px;">
    <div style="display:flex;align-items:center;justify-content:space-between;margin-bottom:14px;flex-wrap:wrap;gap:8px;">
        <div>
            <h2 style="font-family:var(--font-display);font-size:1.1rem;font-weight:800;
                       color:var(--neutral-800);margin:0 0 3px;display:flex;align-items:center;gap:8px;">
                <i class="fas fa-location-dot" style="color:var(--primary-600);"></i>
                More in <?= htmlspecialchars($pref_city) ?>
            </h2>
            <p style="font-size:.78rem;color:var(--neutral-500);margin:0;">
                All active restaurants in your area
            </p>
        </div>
        <a href="<?= BASE_URL ?>dashboard/customer/restaurants.php"
           style="font-size:.8rem;color:var(--primary-600);font-weight:600;text-decoration:none;
                  display:flex;align-items:center;gap:4px;white-space:nowrap;">
            See all restaurants <i class="fas fa-arrow-right" style="font-size:.7rem;"></i>
        </a>
    </div>

    <div style="display:grid;grid-template-columns:repeat(auto-fill,minmax(260px,1fr));gap:16px;">
        <?php foreach ($nearby_restaurants as $nb):
            $nb_cert  = $nb['cert_id'] || $nb['certificate_number'] || $nb['is_bypass_certified'];
            $nb_class = $nb['halal_classification'] ?? '';
            if ($nb_cert) {
                $nb_badge = ['label'=>'Halal Certified','bg'=>'#064e3b','color'=>'#fff','icon'=>'fas fa-certificate'];
            } elseif ($nb_class === 'Muslim Friendly') {
                $nb_badge = ['label'=>'Muslim Friendly','bg'=>'#1e3a5f','color'=>'#fff','icon'=>'fas fa-star-and-crescent'];
            } elseif ($nb_class === 'Non-Pork') {
                $nb_badge = ['label'=>'Pork-Free','bg'=>'#7c1d1d','color'=>'#fff','icon'=>'fas fa-ban'];
            } else {
                $nb_badge = null;
            }
            $nb_rating  = (float)($nb['avg_rating'] ?? 0);
            $nb_reviews = (int)($nb['total_reviews'] ?? 0);
            $nb_cuisines = array_map('trim', explode(',', $nb['cuisine_type'] ?? ''));
        ?>
        <a href="<?= BASE_URL ?>dashboard/customer/restaurants.php?action=view&id=<?= $nb['id'] ?>"
           style="text-decoration:none;display:block;" class="rec-card">
            <div class="card" style="height:100%;transition:box-shadow .18s,transform .18s;overflow:hidden;">
                <!-- Image / placeholder -->
                <div style="position:relative;height:130px;overflow:hidden;background:linear-gradient(135deg,#064e3b,#065f46);">
                    <?php if (!empty($nb['image_path'])): ?>
                    <img src="<?= BASE_URL ?>uploads/<?= htmlspecialchars($nb['image_path']) ?>"
                         alt="<?= htmlspecialchars($nb['restaurant_name']) ?>"
                         style="width:100%;height:100%;object-fit:cover;">
                    <div style="position:absolute;inset:0;background:linear-gradient(to top,rgba(0,0,0,.4) 0%,transparent 55%);pointer-events:none;"></div>
                    <?php else: ?>
                    <div style="display:flex;align-items:center;justify-content:center;height:100%;">
                        <i class="fas fa-store" style="font-size:2.2rem;color:rgba(255,255,255,.2);"></i>
                    </div>
                    <?php endif; ?>
                    <?php if ($nb_badge): ?>
                    <div style="position:absolute;top:8px;left:8px;
                                background:<?= $nb_badge['bg'] ?>;color:<?= $nb_badge['color'] ?>;
                                padding:3px 9px;border-radius:999px;font-size:.65rem;font-weight:700;
                                display:flex;align-items:center;gap:4px;box-shadow:0 2px 6px rgba(0,0,0,.25);">
                        <i class="<?= $nb_badge['icon'] ?>"></i> <?= $nb_badge['label'] ?>
                    </div>
                    <?php endif; ?>
                    <?php if ($nb_rating > 0): ?>
                    <div style="position:absolute;bottom:7px;right:8px;
                                background:rgba(0,0,0,.55);backdrop-filter:blur(4px);
                                color:#fff;padding:2px 7px;border-radius:999px;
                                font-size:.68rem;font-weight:700;display:flex;align-items:center;gap:3px;">
                        <i class="fas fa-star" style="color:#fbbf24;font-size:.58rem;"></i>
                        <?= number_format($nb_rating,1) ?>
                        <span style="opacity:.65;">(<?= $nb_reviews ?>)</span>
                    </div>
                    <?php endif; ?>
                </div>
                <!-- Info -->
                <div style="padding:12px 14px;">
                    <div style="font-weight:700;font-size:.88rem;color:var(--neutral-800);
                                margin-bottom:4px;white-space:nowrap;overflow:hidden;text-overflow:ellipsis;">
                        <?= htmlspecialchars($nb['restaurant_name']) ?>
                    </div>
                    <?php if (!empty($nb['address'])): ?>
                    <div style="font-size:.72rem;color:var(--neutral-500);margin-bottom:6px;
                                white-space:nowrap;overflow:hidden;text-overflow:ellipsis;">
                        <i class="fas fa-location-dot" style="color:var(--primary-400);font-size:.62rem;margin-right:3px;"></i>
                        <?= htmlspecialchars($nb['address']) ?>
                    </div>
                    <?php endif; ?>
                    <?php if ($nb_cuisines[0] ?? ''): ?>
                    <div style="display:flex;flex-wrap:wrap;gap:4px;">
                        <?php foreach (array_slice($nb_cuisines, 0, 3) as $_nct): if (!$_nct) continue; ?>
                        <span style="background:var(--neutral-100);color:var(--neutral-600);
                                     border:1px solid var(--neutral-200);padding:2px 7px;
                                     border-radius:999px;font-size:.65rem;font-weight:600;">
                            <?= htmlspecialchars($_nct) ?>
                        </span>
                        <?php endforeach; ?>
                    </div>
                    <?php endif; ?>
                </div>
            </div>
        </a>
        <?php endforeach; ?>
    </div>
</div>
<?php endif; ?>

<!-- ══ BUSINESS UPGRADE BANNER ═════════════════════════════════════════════ -->
<?php if (!$already_approved && (int)($_SESSION['role_id'] ?? 0) === ROLE_CUSTOMER):
    $status_label_map = [
        'pending'      => ['label'=>'Under Review',             'color'=>'#d97706','bg'=>'#fef9c3','border'=>'#fde68a','icon'=>'fas fa-hourglass-half'],
        'under_review' => ['label'=>'Under Review',             'color'=>'#2563eb','bg'=>'#eff6ff','border'=>'#bfdbfe','icon'=>'fas fa-search'],
        'returned'     => ['label'=>'Returned — action needed', 'color'=>'#d97706','bg'=>'#fff7ed','border'=>'#fed7aa','icon'=>'fas fa-undo'],
        'rejected'     => ['label'=>'Not Approved',             'color'=>'#dc2626','bg'=>'#fef2f2','border'=>'#fecaca','icon'=>'fas fa-times-circle'],
    ];
    $s = $status_label_map[$biz_status] ?? null;
    $has_pending = $biz_app && !$already_approved;
?>
<div id="biz-upgrade-banner" style="background:linear-gradient(135deg,#ecfdf5 0%,#f0fdf4 50%,#fef9c3 100%);border:1.5px solid #bbf7d0;border-radius:var(--radius-lg);padding:20px 24px;margin-bottom:24px;display:flex;align-items:center;gap:16px;flex-wrap:wrap;position:relative;">
    <div style="width:48px;height:48px;border-radius:12px;flex-shrink:0;background:linear-gradient(135deg,#059669,#16a34a);display:flex;align-items:center;justify-content:center;box-shadow:0 4px 14px rgba(5,150,105,.2);">
        <i class="fas fa-store" style="font-size:1.2rem;color:#fff;"></i>
    </div>
    <div style="flex:1;min-width:200px;">
        <?php if ($has_pending && $s): ?>
            <div style="font-weight:700;font-size:.95rem;color:var(--neutral-800);margin-bottom:2px;">Business Application — <?= htmlspecialchars($s['label']) ?></div>
            <div style="font-size:.82rem;color:var(--neutral-500);">Submitted <?= formatDate($biz_app['created_at']) ?>. <?= in_array($biz_status,['returned','rejected']) ? 'Review feedback and resubmit.' : "We'll notify you once reviewed." ?></div>
        <?php else: ?>
            <div style="font-weight:700;font-size:.95rem;color:var(--neutral-800);margin-bottom:2px;">Own a business? Get Halal Certified.</div>
            <div style="font-size:.82rem;color:var(--neutral-500);">Register your business and reach thousands of customers.</div>
        <?php endif; ?>
    </div>
    <a href="<?= BASE_URL ?>dashboard/customer/apply_business.php"
       style="display:inline-flex;align-items:center;gap:8px;padding:9px 18px;border-radius:10px;font-size:.85rem;font-weight:700;text-decoration:none;flex-shrink:0;
              <?= $has_pending && $s ? "background:{$s['bg']};color:{$s['color']};border:1.5px solid {$s['border']};" : 'background:linear-gradient(135deg,#059669,#16a34a);color:#fff;box-shadow:0 4px 12px rgba(5,150,105,.25);' ?>">
        <i class="fas <?= $has_pending && $s ? $s['icon'] : 'fa-arrow-right' ?>"></i>
        <?= $has_pending && $s ? htmlspecialchars($s['label']) : 'Register as a Business' ?>
    </a>
    <?php if (!$has_pending): ?>
    <button onclick="document.getElementById('biz-upgrade-banner').style.display='none';localStorage.setItem('hide_biz_banner','1');"
            style="position:absolute;top:10px;right:12px;background:none;border:none;cursor:pointer;color:var(--neutral-400);font-size:.85rem;padding:4px;">
        <i class="fas fa-times"></i>
    </button>
    <script>if(localStorage.getItem('hide_biz_banner')==='1'){document.getElementById('biz-upgrade-banner').style.display='none';}</script>
    <?php endif; ?>
</div>
<?php endif; ?>

<!-- ══ TWO-COLUMN: ACTIVITY + REVIEWS ══════════════════════════════════════ -->
<div style="display:grid;grid-template-columns:1fr 1fr;gap:20px;align-items:start;">

    <!-- Recent Activity -->
    <div>
        <div style="display:flex;align-items:center;justify-content:space-between;margin-bottom:12px;">
            <h3 style="font-family:var(--font-display);font-size:1rem;font-weight:700;color:var(--neutral-800);margin:0;display:flex;align-items:center;gap:8px;">
                <i class="fas fa-clock" style="color:var(--primary-600);"></i> Recent Activity
            </h3>
            <a href="<?= BASE_URL ?>dashboard/customer/orders.php" style="font-size:.8rem;color:var(--primary-600);font-weight:600;">View all →</a>
        </div>

        <?php if (empty($orders)): ?>
        <div class="card" style="text-align:center;padding:36px 20px;">
            <i class="fas fa-shopping-bag" style="font-size:2rem;color:var(--neutral-200);margin-bottom:12px;display:block;"></i>
            <p style="color:var(--neutral-400);font-size:.88rem;margin:0;">No orders yet. Start exploring!</p>
        </div>
        <?php else: ?>
        <div style="display:flex;flex-direction:column;gap:10px;">
            <?php foreach ($orders as $o):
                $status_colors = [
                    'pending'         => ['bg'=>'#fef9c3','color'=>'#854d0e','icon'=>'fa-hourglass-half'],
                    'confirmed'       => ['bg'=>'#dbeafe','color'=>'#1e40af','icon'=>'fa-check'],
                    'preparing'       => ['bg'=>'#fef3c7','color'=>'#92400e','icon'=>'fa-fire-burner'],
                    'out_for_delivery'=> ['bg'=>'#eff6ff','color'=>'#1d4ed8','icon'=>'fa-truck'],
                    'delivered'       => ['bg'=>'#dcfce7','color'=>'#166534','icon'=>'fa-circle-check'],
                    'completed'       => ['bg'=>'#dcfce7','color'=>'#166534','icon'=>'fa-circle-check'],
                    'cancelled'       => ['bg'=>'#fee2e2','color'=>'#991b1b','icon'=>'fa-times-circle'],
                ];
                $sc = $status_colors[$o['order_status']] ?? ['bg'=>'#f1f5f9','color'=>'#475569','icon'=>'fa-circle'];
                $is_active = !in_array($o['order_status'], ['delivered','completed','cancelled']);
            ?>
            <div class="card" style="border-left:3px solid <?= $sc['color'] ?>;<?= $is_active ? 'box-shadow:0 4px 16px rgba(0,0,0,.07);' : '' ?>">
                <div style="padding:14px 18px;display:flex;align-items:center;gap:12px;">
                    <div style="width:40px;height:40px;border-radius:10px;background:<?= $sc['bg'] ?>;
                                display:flex;align-items:center;justify-content:center;flex-shrink:0;">
                        <i class="fas <?= $sc['icon'] ?>" style="color:<?= $sc['color'] ?>;font-size:.9rem;"></i>
                    </div>
                    <div style="flex:1;min-width:0;">
                        <div style="font-weight:700;font-size:.88rem;color:var(--neutral-800);
                                    white-space:nowrap;overflow:hidden;text-overflow:ellipsis;">
                            <?= htmlspecialchars($o['restaurant_name']) ?>
                        </div>
                        <div style="font-size:.75rem;color:var(--neutral-500);margin-top:1px;">
                            <?= htmlspecialchars($o['order_number']) ?> · <?= timeAgo($o['created_at']) ?>
                        </div>
                    </div>
                    <div style="text-align:right;flex-shrink:0;">
                        <div style="font-weight:700;font-size:.88rem;color:var(--neutral-800);"><?= formatCurrency($o['total_amount']) ?></div>
                        <span style="display:inline-block;margin-top:3px;font-size:.7rem;font-weight:700;
                                     background:<?= $sc['bg'] ?>;color:<?= $sc['color'] ?>;
                                     padding:2px 8px;border-radius:999px;">
                            <?= ucwords(str_replace('_',' ',$o['order_status'])) ?>
                        </span>
                    </div>
                </div>
                <?php if ($is_active): ?>
                <div style="padding:0 18px 12px;">
                    <a href="<?= BASE_URL ?>dashboard/customer/orders.php"
                       style="font-size:.75rem;color:var(--primary-600);font-weight:600;
                              display:flex;align-items:center;gap:4px;text-decoration:none;">
                        <i class="fas fa-eye" style="font-size:.65rem;"></i> Track Order
                    </a>
                </div>
                <?php endif; ?>
            </div>
            <?php endforeach; ?>
        </div>
        <?php endif; ?>
    </div>

    <!-- My Latest Reviews -->
    <div>
        <div style="display:flex;align-items:center;justify-content:space-between;margin-bottom:12px;">
            <h3 style="font-family:var(--font-display);font-size:1rem;font-weight:700;color:var(--neutral-800);margin:0;display:flex;align-items:center;gap:8px;">
                <i class="fas fa-star" style="color:var(--accent-500);"></i> My Latest Reviews
            </h3>
            <a href="<?= BASE_URL ?>dashboard/customer/reviews.php" style="font-size:.8rem;color:var(--primary-600);font-weight:600;">View all →</a>
        </div>

        <?php
        $rv_stmt = $conn->prepare("SELECT rv.*, hr.restaurant_name FROM reviews rv JOIN halal_restaurants hr ON rv.restaurant_id = hr.id WHERE rv.customer_id = ? ORDER BY rv.created_at DESC LIMIT 3");
        $rv_stmt->bind_param("i", $user_id);
        $rv_stmt->execute();
        $dashboard_reviews = $rv_stmt->get_result()->fetch_all(MYSQLI_ASSOC);
        $rv_stmt->close();
        ?>
        <?php if (empty($dashboard_reviews)): ?>
        <div class="card" style="text-align:center;padding:36px 20px;">
            <i class="fas fa-star" style="font-size:2rem;color:var(--neutral-200);margin-bottom:12px;display:block;"></i>
            <p style="color:var(--neutral-400);font-size:.88rem;margin:0 0 14px;">No reviews yet.</p>
            <a href="<?= BASE_URL ?>dashboard/customer/restaurants.php" class="btn btn-sm btn-primary">
                <i class="fas fa-utensils"></i> Order &amp; Review
            </a>
        </div>
        <?php else: ?>
        <div style="display:flex;flex-direction:column;gap:10px;">
            <?php foreach ($dashboard_reviews as $rv): ?>
            <div class="card" style="padding:16px 18px;">
                <div style="display:flex;align-items:flex-start;gap:12px;">
                    <!-- Avatar -->
                    <div style="width:38px;height:38px;border-radius:10px;flex-shrink:0;
                                background:var(--primary-100);color:var(--primary-700);
                                display:flex;align-items:center;justify-content:center;
                                font-weight:800;font-size:.9rem;">
                        <?= strtoupper(substr($_SESSION['full_name'] ?? 'U', 0, 1)) ?>
                    </div>
                    <div style="flex:1;min-width:0;">
                        <div style="font-weight:700;font-size:.88rem;color:var(--neutral-800);
                                    white-space:nowrap;overflow:hidden;text-overflow:ellipsis;">
                            <?= htmlspecialchars($rv['restaurant_name']) ?>
                        </div>
                        <!-- Stars -->
                        <div style="display:flex;gap:2px;margin:3px 0;">
                            <?php for ($s = 1; $s <= 5; $s++): ?>
                            <i class="fas fa-star" style="font-size:.72rem;color:<?= $s <= $rv['rating'] ? 'var(--accent-400)' : 'var(--neutral-200)' ?>;"></i>
                            <?php endfor; ?>
                            <span style="font-size:.72rem;color:var(--neutral-400);margin-left:4px;"><?= timeAgo($rv['created_at']) ?></span>
                        </div>
                        <?php if ($rv['comment']): ?>
                        <p style="font-size:.8rem;color:var(--neutral-600);line-height:1.5;margin:0;
                                  display:-webkit-box;-webkit-line-clamp:2;-webkit-box-orient:vertical;overflow:hidden;">
                            <?= htmlspecialchars($rv['comment']) ?>
                        </p>
                        <?php endif; ?>
                    </div>
                </div>
            </div>
            <?php endforeach; ?>
        </div>
        <?php endif; ?>
    </div>

</div><!-- /two-col -->

<?php require_once __DIR__ . '/../../includes/footer.php'; ?>
