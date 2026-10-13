<?php
require_once __DIR__ . '/../../config/app.php';
requireLogin();
requireRole([ROLE_CUSTOMER]);

$page_title = 'Halal Restaurants';
$page_heading = 'Halal Restaurants';
$is_dashboard = true;
$breadcrumbs = [['label' => 'Dashboard', 'url' => BASE_URL . 'dashboard/customer/'], ['label' => 'Restaurants']];
$user_id = $_SESSION['user_id'];
$action = $_GET['action'] ?? 'list';
$success = '';

// Handle order placement
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['place_order'])) {
    $restaurant_id    = intval($_POST['restaurant_id']);
    $items            = sanitize($_POST['items_json']        ?? '[]');
    $total            = floatval($_POST['total_amount']      ?? 0);
    $delivery_address = sanitize($_POST['delivery_address']  ?? '');
    $payment_method   = sanitize($_POST['payment_method']    ?? 'cash');
    $notes            = sanitize($_POST['notes']             ?? '');
    $service_type_raw = sanitize($_POST['service_type']      ?? 'delivery');
    $order_number     = generateReference('ORD');
    $allowed_svc      = ['dine_in', 'takeout', 'delivery'];
    if (!in_array($service_type_raw, $allowed_svc, true)) $service_type_raw = 'delivery';

    $stmt = $conn->prepare("INSERT INTO orders (order_number, customer_id, restaurant_id, items_json, subtotal, total_amount, delivery_address, service_type, payment_method, notes) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?)");
    $stmt->bind_param("siisddssss", $order_number, $user_id, $restaurant_id, $items, $total, $total, $delivery_address, $service_type_raw, $payment_method, $notes);
    
    if ($stmt->execute()) {
        // Notify restaurant owner
        $owner = $conn->query("SELECT business_owner_id FROM halal_restaurants WHERE id = $restaurant_id")->fetch_assoc();
        if ($owner) {
            createNotification($conn, $owner['business_owner_id'], 'New Order: ' . $order_number,
                'You have a new order from ' . $_SESSION['full_name'] . '!', 'action_required',
                BASE_URL . 'dashboard/business_owner/manage_orders.php');
        }
        $success = 'Order ' . $order_number . ' placed successfully!';
    }
}

// Handle review submission
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['submit_review'])) {
    $restaurant_id = intval($_POST['restaurant_id']);
    $rating = intval($_POST['rating']);
    $food_rating = intval($_POST['food_rating'] ?? $rating);
    $service_rating = intval($_POST['service_rating'] ?? $rating);
    $comment = sanitize($_POST['comment'] ?? '');
    
    $stmt = $conn->prepare("INSERT INTO reviews (customer_id, restaurant_id, rating, food_rating, service_rating, comment) VALUES (?, ?, ?, ?, ?, ?)");
    $stmt->bind_param("iiiiis", $user_id, $restaurant_id, $rating, $food_rating, $service_rating, $comment);
    if ($stmt->execute()) {
        // Update restaurant avg rating
        $conn->query("UPDATE halal_restaurants SET avg_rating = (SELECT AVG(rating) FROM reviews WHERE restaurant_id = $restaurant_id), total_reviews = (SELECT COUNT(*) FROM reviews WHERE restaurant_id = $restaurant_id) WHERE id = $restaurant_id");
        $success = 'Review submitted! Thank you for your feedback.';
    }
}

$search = sanitize($_GET['search'] ?? '');
$cuisine = sanitize($_GET['cuisine'] ?? '');

$where = "WHERE hr.is_active = 1";
if ($search) $where .= " AND (hr.restaurant_name LIKE '%$search%' OR hr.city LIKE '%$search%')";
if ($cuisine) $where .= " AND FIND_IN_SET('$cuisine', REPLACE(hr.cuisine_type, ', ', ','))";

// Validate sort parameter
$sort = in_array($_GET['sort'] ?? '', ['credibility','most_visited','engagement','rating'])
    ? $_GET['sort'] : 'rating';

$order_sql = match($sort) {
    'credibility'  => 'hr.credibility_score DESC, hr.avg_rating DESC',
    'most_visited' => '(SELECT COUNT(*) FROM orders WHERE restaurant_id=hr.id AND service_type IN (\'dine_in\',\'takeout\') AND created_at >= DATE_SUB(NOW(),INTERVAL 30 DAY)) DESC, hr.avg_rating DESC',
    'engagement'   => 'hr.total_reviews DESC, hr.avg_rating DESC',
    default        => 'hr.avg_rating DESC',
};

// Ensure schema columns exist
db_add_column($conn, 'halal_restaurants', 'hacera_hrp',              'DECIMAL(5,1) DEFAULT NULL');
db_add_column($conn, 'halal_restaurants', 'hacera_band',             'VARCHAR(30)  DEFAULT NULL');
db_add_column($conn, 'halal_restaurants', 'hacera_published_at',     'DATETIME     DEFAULT NULL');
ensureCredibilitySchema($conn);

$restaurants = $conn->query("
    SELECT hr.*,
           -- Live review aggregates (overrides the denormalized hr.avg_rating / hr.total_reviews)
           COALESCE((SELECT ROUND(AVG(rv.rating),2) FROM reviews rv WHERE rv.restaurant_id = hr.id), 0) AS avg_rating,
           COALESCE((SELECT COUNT(*)               FROM reviews rv WHERE rv.restaurant_id = hr.id), 0) AS total_reviews,
           COALESCE(hc1.id, hc2.id)                                   AS cert_id,
           COALESCE(hc1.certificate_number, hc2.certificate_number)   AS certificate_number,
           COALESCE(hc1.issue_date,  hc2.issue_date)                  AS issue_date,
           COALESCE(hc1.expiry_date, hc2.expiry_date)                 AS expiry_date,
           COALESCE(hc1.status,      hc2.status)                      AS cert_status,
           COALESCE(hc1.payment_status, hc2.payment_status)           AS cert_payment_status,
           COALESCE(hc1.certificate_file_path, hc2.certificate_file_path) AS certificate_file_path,
           (SELECT ba.halal_classification
            FROM   business_applications ba
            WHERE  ba.user_id = hr.business_owner_id
              AND  ba.status  = 'approved'
            ORDER  BY ba.created_at DESC LIMIT 1) AS halal_classification,
           CASE
               WHEN hc1.id IS NULL AND hc2.id IS NULL
                    AND EXISTS (
                        SELECT 1 FROM business_applications ba
                        WHERE  ba.user_id = hr.business_owner_id
                          AND  ba.certification_path = 'certified'
                          AND  ba.status = 'approved'
                    )
               THEN 1 ELSE 0
           END AS is_bypass_certified,
           -- Dine-in count last 30 days for foot-traffic meter
           (SELECT COUNT(*) FROM orders o
            WHERE  o.restaurant_id = hr.id
              AND  o.service_type IN ('dine_in','takeout')
              AND  o.order_status != 'cancelled'
              AND  o.created_at   >= DATE_SUB(NOW(), INTERVAL 30 DAY)
           ) AS dine_in_30d,
           -- Profile views last 30 days
           (SELECT COUNT(*) FROM business_views bv
            WHERE  bv.restaurant_id = hr.id
              AND  bv.visited_at    >= DATE_SUB(NOW(), INTERVAL 30 DAY)
           ) AS profile_views_30d
    FROM halal_restaurants hr
    LEFT JOIN halal_certificates hc1
        ON  hc1.id = hr.certificate_id
        AND hc1.status NOT IN ('rejected','revoked','expired','suspended')
    LEFT JOIN halal_certificates hc2
        ON  hr.certificate_id IS NULL
        AND hc2.application_id = (
                SELECT ha.id FROM hdp_applications ha
                WHERE ha.business_owner_id = hr.business_owner_id
                ORDER BY ha.created_at DESC LIMIT 1
            )
        AND hc2.status NOT IN ('rejected','revoked','expired','suspended')
    WHERE hr.is_active = 1
    " . ($search ? " AND (hr.restaurant_name LIKE '%$search%' OR hr.city LIKE '%$search%')" : "") . "
    " . ($cuisine ? " AND FIND_IN_SET('$cuisine', REPLACE(hr.cuisine_type, ', ', ','))" : "") . "
    ORDER BY {$order_sql}
")->fetch_all(MYSQLI_ASSOC);

require_once __DIR__ . '/../../includes/header.php';
?>


<?php if ($action === 'list'): ?>

<!-- ══ PAGE HERO ═══════════════════════════════════════════════════════════ -->
<div style="background:linear-gradient(135deg,var(--primary-900) 0%,var(--primary-700) 60%,#047857 100%);
            border-radius:var(--radius-xl);padding:32px 36px;margin-bottom:24px;
            position:relative;overflow:hidden;">
    <div style="position:absolute;right:-40px;top:-40px;width:200px;height:200px;border-radius:50%;background:rgba(255,255,255,.05);pointer-events:none;"></div>
    <div style="position:absolute;right:60px;bottom:-60px;width:140px;height:140px;border-radius:50%;background:rgba(255,255,255,.04);pointer-events:none;"></div>
    <div style="position:relative;display:flex;align-items:center;justify-content:space-between;flex-wrap:wrap;gap:20px;">
        <div>
            <div style="font-size:.72rem;font-weight:700;letter-spacing:.08em;text-transform:uppercase;color:#6ee7b7;margin-bottom:8px;">
                <i class="fas fa-location-dot" style="margin-right:5px;"></i>Halal Verified
            </div>
            <h1 style="font-family:var(--font-display);font-size:1.7rem;font-weight:800;color:#fff;margin:0 0 6px;line-height:1.2;">
                Halal Restaurants
            </h1>
            <p style="color:rgba(255,255,255,.7);font-size:.9rem;margin:0;">
                <?= count($restaurants) ?> restaurant<?= count($restaurants) !== 1 ? 's' : '' ?> found
                <?= $search ? ' for &ldquo;' . htmlspecialchars($search) . '&rdquo;' : '' ?>
                <?= $cuisine ? ' in <strong style="color:#a7f3d0;">' . htmlspecialchars($cuisine) . '</strong>' : '' ?>
            </p>
        </div>
        <!-- Inline search -->
        <form method="GET" style="display:flex;gap:8px;align-items:center;flex-shrink:0;">
            <div style="position:relative;">
                <i class="fas fa-search" style="position:absolute;left:14px;top:50%;transform:translateY(-50%);color:rgba(255,255,255,.5);font-size:.82rem;"></i>
                <input type="text" name="search" value="<?= htmlspecialchars($search) ?>"
                       placeholder="Search restaurants…"
                       style="padding:10px 14px 10px 38px;border-radius:var(--radius-md);border:1.5px solid rgba(255,255,255,.2);
                              background:rgba(255,255,255,.1);color:#fff;font-size:.88rem;width:240px;
                              outline:none;backdrop-filter:blur(4px);"
                       onfocus="this.style.background='rgba(255,255,255,.18)';this.style.borderColor='rgba(255,255,255,.4)'"
                       onblur="this.style.background='rgba(255,255,255,.1)';this.style.borderColor='rgba(255,255,255,.2)'">
            </div>
            <button type="submit" style="padding:10px 18px;background:#fff;color:var(--primary-800);
                                         border:none;border-radius:var(--radius-md);font-weight:700;
                                         font-size:.88rem;cursor:pointer;white-space:nowrap;transition:opacity .15s;"
                    onmouseover="this.style.opacity='.88'" onmouseout="this.style.opacity='1'">
                Search
            </button>
        </form>
    </div>
</div>

<!-- ══ CUISINE FILTER CHIPS ════════════════════════════════════════════════ -->
<div style="display:flex;gap:8px;flex-wrap:wrap;margin-bottom:14px;align-items:center;">
    <?php
    $filter_chips = [
        ['value'=>'',                'label'=>'All Cuisines',    'icon'=>'fas fa-border-all'],
        ['value'=>'Filipino',        'label'=>'Filipino',        'icon'=>'fas fa-bowl-rice'],
        ['value'=>'Middle Eastern',  'label'=>'Middle Eastern',  'icon'=>'fas fa-moon'],
        ['value'=>'Indian',          'label'=>'Indian',          'icon'=>'fas fa-pepper-hot'],
        ['value'=>'Asian Fusion',    'label'=>'Asian Fusion',    'icon'=>'fas fa-circle-nodes'],
        ['value'=>'Malaysian',       'label'=>'Malaysian',       'icon'=>'fas fa-leaf'],
        ['value'=>'Indonesian',      'label'=>'Indonesian',      'icon'=>'fas fa-fire'],
        ['value'=>'Turkish',         'label'=>'Turkish',         'icon'=>'fas fa-star-and-crescent'],
        ['value'=>'Mediterranean',   'label'=>'Mediterranean',   'icon'=>'fas fa-sun'],
    ];
    foreach ($filter_chips as $chip):
        $is_active = ($cuisine === $chip['value']);
    ?>
    <a href="?cuisine=<?= urlencode($chip['value']) ?><?= $search ? '&search='.urlencode($search) : '' ?>&sort=<?= urlencode($sort) ?>"
       style="display:inline-flex;align-items:center;gap:6px;padding:7px 14px;border-radius:999px;
              font-size:.8rem;font-weight:600;text-decoration:none;white-space:nowrap;transition:all .15s;
              <?= $is_active
                  ? 'background:var(--primary-700);color:#fff;border:1.5px solid var(--primary-700);box-shadow:0 2px 8px rgba(4,120,87,.3);'
                  : 'background:#fff;color:var(--neutral-600);border:1.5px solid var(--neutral-200);' ?>">
        <i class="<?= $chip['icon'] ?>" style="font-size:.72rem;"></i>
        <?= htmlspecialchars($chip['label']) ?>
    </a>
    <?php endforeach; ?>
</div>

<!-- ══ SORT CONTROLS ════════════════════════════════════════════════════════ -->
<div style="display:flex;align-items:center;gap:10px;flex-wrap:wrap;margin-bottom:20px;">
    <span style="font-size:.78rem;font-weight:600;color:var(--neutral-500);flex-shrink:0;">Sort by:</span>
    <?php foreach ([
        ['rating',       'fas fa-star',        'Highest Rated'],
        ['credibility',  'fas fa-award',        'Credibility Score'],
        ['most_visited', 'fas fa-fire',         'Most Visited'],
        ['engagement',   'fas fa-comments',     'Most Reviewed'],
    ] as [$sv, $si, $sl]):
        $sa = ($sort === $sv);
    ?>
    <a href="?sort=<?= $sv ?><?= $search ? '&search='.urlencode($search) : '' ?><?= $cuisine ? '&cuisine='.urlencode($cuisine) : '' ?>"
       style="display:inline-flex;align-items:center;gap:6px;padding:6px 14px;border-radius:999px;
              font-size:.8rem;font-weight:700;text-decoration:none;white-space:nowrap;transition:all .15s;
              <?= $sa
                  ? 'background:var(--primary-700);color:#fff;border:1.5px solid var(--primary-700);'
                  : 'background:#fff;color:var(--neutral-600);border:1.5px solid var(--neutral-200);' ?>">
        <i class="<?= $si ?>" style="font-size:.7rem;"></i><?= $sl ?>
    </a>
    <?php endforeach; ?></div>

<?php if ($success): ?>
<div class="alert alert-success" style="margin-bottom:20px;">
    <i class="fas fa-check-circle"></i><span><?= htmlspecialchars($success) ?></span>
    <button class="close-alert"><i class="fas fa-times"></i></button>
</div>
<?php endif; ?>

<!-- ══ RESTAURANT GRID ══════════════════════════════════════════════════════ -->
<?php if (empty($restaurants)): ?>
<div class="card" style="text-align:center;padding:60px 32px;">
    <div style="width:72px;height:72px;border-radius:50%;background:var(--primary-50);
                display:flex;align-items:center;justify-content:center;
                margin:0 auto 16px;font-size:1.8rem;color:var(--primary-300);">
        <i class="fas fa-search"></i>
    </div>
    <h3 style="color:var(--neutral-600);margin-bottom:8px;">No Restaurants Found</h3>
    <p style="color:var(--neutral-400);font-size:.88rem;margin-bottom:20px;">
        Try a different search term or cuisine filter.
    </p>
    <a href="?" class="btn btn-primary"><i class="fas fa-border-all"></i> Show All Restaurants</a>
</div>
<?php else: ?>
<div style="display:grid;grid-template-columns:repeat(auto-fill,minmax(300px,1fr));gap:20px;">
    <?php foreach ($restaurants as $r):
        $service_badges = [];
        if (!empty($r['services_offered'])) {
            $service_badges = json_decode($r['services_offered'], true) ?: [];
        }
        $hrp_v = (float)($r['hacera_hrp'] ?? 0);
        [$hc, $hbg] = match(true) {
            $hrp_v >= 90 => ['#16a34a','#dcfce7'],
            $hrp_v >= 70 => ['#2563eb','#dbeafe'],
            $hrp_v >= 50 => ['#d97706','#fef3c7'],
            $hrp_v >= 25 => ['#ea580c','#ffedd5'],
            default      => ['#dc2626','#fee2e2'],
        };

        // ── Credibility score ─────────────────────────────────────────────────
        $cred_score = (float)($r['credibility_score'] ?? 0);
        $cred_tier  = getCredibilityTier($cred_score);

        // ── Foot traffic signals ──────────────────────────────────────────────
        $dine_in_30d      = (int)($r['dine_in_30d']      ?? 0);
        $profile_views_30d= (int)($r['profile_views_30d'] ?? 0);

        // Traffic meter: 0–5 bars based on dine-in volume
        $traffic_bars = match(true) {
            $dine_in_30d >= 100 => 5,
            $dine_in_30d >= 50  => 4,
            $dine_in_30d >= 20  => 3,
            $dine_in_30d >= 5   => 2,
            $dine_in_30d >= 1   => 1,
            default              => 0,
        };
        $traffic_label = match($traffic_bars) {
            5 => 'Very Busy', 4 => 'Busy', 3 => 'Moderate',
            2 => 'Light',     1 => 'Quiet', default => 'New',
        };
        $traffic_color = match($traffic_bars) {
            5,4 => '#dc2626', 3 => '#d97706', 2,1 => '#16a34a', default => '#94a3b8',
        };
    ?>
    <div class="card restaurant-card"
         style="display:flex;flex-direction:column;transition:transform .2s,box-shadow .2s;position:relative;overflow:hidden;"
         onmouseover="this.style.transform='translateY(-3px)';this.style.boxShadow='0 12px 28px rgba(0,0,0,.1)'"
         onmouseout="this.style.transform='';this.style.boxShadow=''">

        <!-- Image / thumbnail -->
        <div style="height:180px;background:linear-gradient(135deg,var(--primary-100),var(--accent-50));
                    position:relative;overflow:hidden;flex-shrink:0;border-radius:var(--radius-lg) var(--radius-lg) 0 0;">
            <?php if (!empty($r['image_path'])): ?>
                <img src="<?= BASE_URL ?>uploads/<?= htmlspecialchars($r['image_path']) ?>"
                     alt="<?= htmlspecialchars($r['restaurant_name']) ?>"
                     style="width:100%;height:100%;object-fit:cover;position:absolute;inset:0;transition:transform .4s;"
                     onmouseover="this.style.transform='scale(1.04)'" onmouseout="this.style.transform=''">
            <?php else: ?>
                <div style="width:100%;height:100%;display:flex;align-items:center;justify-content:center;">
                    <i class="fas fa-store" style="font-size:3.5rem;color:var(--primary-200);"></i>
                </div>
            <?php endif; ?>
            <div style="position:absolute;inset:0;background:linear-gradient(to top,rgba(0,0,0,.4) 0%,transparent 55%);pointer-events:none;"></div>

            <!-- Halal badge top-left -->
            <?php
            $is_halal_cert = $r['cert_id'] || $r['certificate_number'] || $r['is_bypass_certified'];
            $halal_class   = $r['halal_classification'] ?? 'N/A';
            $badge_map = [
                'Halal'          => ['fas fa-certificate',      'Halal Certified', '#064e3b','#fff','#059669'],
                'Muslim Friendly'=> ['fas fa-star-and-crescent','Muslim Friendly', '#1e3a5f','#fff','#2563eb'],
                'Non-Pork'       => ['fas fa-ban',              'Pork-Free',       '#7c1d1d','#fff','#dc2626'],
            ];
            if ($is_halal_cert) [$bi,$bl,$bbg,$btc,$bbc] = $badge_map['Halal'];
            elseif (isset($badge_map[$halal_class])) [$bi,$bl,$bbg,$btc,$bbc] = $badge_map[$halal_class];
            else $bl = null;
            ?>
            <?php if ($bl ?? false): ?>
            <div style="position:absolute;top:10px;left:10px;background:<?= $bbg ?>;color:<?= $btc ?>;
                        border:1.5px solid <?= $bbc ?>;padding:4px 10px;border-radius:999px;
                        font-size:.68rem;font-weight:700;display:flex;align-items:center;gap:5px;
                        box-shadow:0 2px 6px rgba(0,0,0,.25);">
                <i class="<?= $bi ?>"></i><?= $bl ?>
            </div>
            <?php endif; ?>

            <!-- Credibility badge top-right -->
            <?php if ($cred_score > 0 && $cred_tier['tier'] > 0): ?>
            <div style="position:absolute;top:10px;right:10px;
                        background:<?= $cred_tier['bg'] ?>;color:<?= $cred_tier['color'] ?>;
                        border:1.5px solid <?= $cred_tier['border'] ?>;
                        padding:4px 10px;border-radius:999px;font-size:.68rem;font-weight:800;
                        display:flex;align-items:center;gap:5px;box-shadow:0 2px 6px rgba(0,0,0,.18);">
                <i class="<?= $cred_tier['icon'] ?>"></i>
                <?= htmlspecialchars($cred_tier['label']) ?>
            </div>
            <?php endif; ?>

            <!-- HaCeRa pill bottom-left -->
            <?php if ($hrp_v > 0): ?>
            <div style="position:absolute;bottom:10px;left:10px;background:<?= $hbg ?>;color:<?= $hc ?>;
                        padding:4px 10px;border-radius:999px;font-size:.66rem;font-weight:700;
                        display:flex;align-items:center;gap:4px;box-shadow:0 1px 4px rgba(0,0,0,.18);">
                <i class="fas fa-clipboard-check" style="font-size:.58rem;"></i><?= $hrp_v ?>% Halal Ready
            </div>
            <?php endif; ?>

            <!-- Rating pill bottom-right -->
            <?php if ((float)$r['avg_rating'] > 0): ?>
            <div style="position:absolute;bottom:10px;right:10px;background:rgba(0,0,0,.55);color:#fff;
                        padding:4px 10px;border-radius:999px;font-size:.72rem;font-weight:700;
                        display:flex;align-items:center;gap:4px;backdrop-filter:blur(4px);">
                <i class="fas fa-star" style="color:#fbbf24;font-size:.62rem;"></i>
                <?= number_format($r['avg_rating'],1) ?>
                <span style="opacity:.7;font-size:.62rem;">(<?= (int)($r['total_reviews']??0) ?>)</span>
            </div>
            <?php endif; ?>
        </div>

        <!-- Card body -->
        <div class="restaurant-info" style="display:flex;flex-direction:column;flex:1;padding:14px 16px;">

            <div class="restaurant-name" style="margin-bottom:3px;"><?= htmlspecialchars($r['restaurant_name']) ?></div>

            <!-- Meta: location + cuisine -->
            <div class="restaurant-meta" style="margin-bottom:8px;">
                <span><i class="fas fa-map-marker-alt" style="color:var(--danger);"></i>
                    <?= htmlspecialchars($r['city'] ?? ($r['address'] ?? 'N/A')) ?></span>
                <?php foreach (array_map('trim', explode(',', $r['cuisine_type'] ?? '')) as $_ct):
                    if (!$_ct) continue; ?>
                <span><i class="fas fa-utensils" style="color:var(--primary-400);"></i><?= htmlspecialchars($_ct) ?></span>
                <?php endforeach; ?>
            </div>

            <!-- ── Credibility Score Bar ─────────────────────────────────── -->
            <?php if ($cred_score > 0): ?>
            <div style="margin-bottom:8px;padding:8px 10px;border-radius:9px;
                        background:<?= $cred_tier['bg'] ?>;border:1px solid <?= $cred_tier['border'] ?>;">
                <div style="display:flex;justify-content:space-between;align-items:center;margin-bottom:5px;">
                    <span style="font-size:.68rem;font-weight:700;color:<?= $cred_tier['color'] ?>;display:flex;align-items:center;gap:5px;">
                        <i class="<?= $cred_tier['icon'] ?>"></i> Credibility Score
                    </span>
                    <span style="font-size:.72rem;font-weight:900;color:<?= $cred_tier['color'] ?>;">
                        <?= number_format($cred_score, 1) ?>/100
                    </span>
                </div>
                <div style="height:5px;border-radius:999px;background:rgba(0,0,0,.08);overflow:hidden;">
                    <div style="height:100%;width:<?= min($cred_score,100) ?>%;background:<?= $cred_tier['color'] ?>;border-radius:999px;transition:width .8s ease;"></div>
                </div>
                <!-- Tier stars -->
                <div style="margin-top:4px;display:flex;align-items:center;gap:3px;">
                    <?php for ($s=1;$s<=3;$s++): ?>
                    <i class="fas fa-star" style="font-size:.6rem;color:<?= $s<=$cred_tier['stars']?$cred_tier['color']:'#e2e8f0' ?>;"></i>
                    <?php endfor; ?>
                    <span style="font-size:.62rem;color:<?= $cred_tier['color'] ?>;font-weight:700;margin-left:3px;">
                        <?= htmlspecialchars($cred_tier['label']) ?>
                    </span>
                </div>
            </div>
            <?php endif; ?>

            <!-- ── Traffic Separation: Profile Views vs Dine-ins ─────────── -->
            <div style="display:grid;grid-template-columns:1fr 1fr;gap:6px;margin-bottom:8px;">
                <!-- Profile Views (digital) -->
                <div style="background:#f8fafc;border:1px solid #e2e8f0;border-radius:8px;padding:7px 9px;">
                    <div style="font-size:.62rem;font-weight:700;color:#64748b;text-transform:uppercase;letter-spacing:.05em;margin-bottom:2px;">
                        <i class="fas fa-eye" style="color:#0891b2;margin-right:3px;"></i>Profile Views
                    </div>
                    <div style="font-size:.95rem;font-weight:900;color:#0891b2;line-height:1;">
                        <?= number_format($profile_views_30d) ?>
                    </div>
                    <div style="font-size:.6rem;color:#94a3b8;margin-top:1px;">last 30 days</div>
                </div>
                <!-- Verified Dine-ins (physical) -->
                <div style="background:#fff7ed;border:1px solid #fed7aa;border-radius:8px;padding:7px 9px;">
                    <div style="font-size:.62rem;font-weight:700;color:#c2410c;text-transform:uppercase;letter-spacing:.05em;margin-bottom:2px;">
                        <i class="fas fa-utensils" style="color:#ea580c;margin-right:3px;"></i>Dine-ins
                    </div>
                    <div style="font-size:.95rem;font-weight:900;color:#ea580c;line-height:1;">
                        <?= number_format($dine_in_30d) ?>
                    </div>
                    <div style="font-size:.6rem;color:#94a3b8;margin-top:1px;">last 30 days</div>
                </div>
            </div>

            <!-- ── Foot Traffic Meter ─────────────────────────────────────── -->
            <div style="display:flex;align-items:center;gap:7px;margin-bottom:8px;">
                <span style="font-size:.68rem;font-weight:600;color:var(--neutral-500);flex-shrink:0;">Foot Traffic:</span>
                <div style="display:flex;align-items:flex-end;gap:2px;">
                    <?php for ($b=1;$b<=5;$b++): ?>
                    <div style="width:6px;height:<?= 6+($b*3) ?>px;border-radius:2px;
                                background:<?= $b<=$traffic_bars ? $traffic_color : '#e2e8f0' ?>;
                                transition:background .3s;"></div>
                    <?php endfor; ?>
                </div>
                <span style="font-size:.68rem;font-weight:700;color:<?= $traffic_color ?>;"><?= $traffic_label ?></span>
            </div>

            <!-- Service badges -->
            <?php if (!empty($service_badges)): ?>
            <div style="display:flex;flex-wrap:wrap;gap:4px;margin-bottom:8px;">
                <?php foreach ($service_badges as $svc): ?>
                <span style="background:#eff6ff;color:#1d4ed8;padding:3px 8px;border-radius:999px;font-size:.68rem;font-weight:600;">
                    <?= htmlspecialchars($svc) ?>
                </span>
                <?php endforeach; ?>
            </div>
            <?php endif; ?>

            <!-- HaCeRa readiness bar -->
            <?php if ($hrp_v > 0): ?>
            <div style="margin-bottom:8px;padding:6px 9px;border-radius:8px;
                        background:<?= $hbg ?>;border:1px solid <?= $hc ?>22;">
                <div style="display:flex;justify-content:space-between;align-items:center;margin-bottom:3px;">
                    <span style="font-size:.66rem;font-weight:700;color:<?= $hc ?>;display:flex;align-items:center;gap:4px;">
                        <i class="fas fa-clipboard-check" style="font-size:.58rem;"></i> Halal Readiness
                    </span>
                    <span style="font-size:.68rem;font-weight:800;color:<?= $hc ?>;"><?= $hrp_v ?>%</span>
                </div>
                <div style="height:4px;border-radius:999px;background:rgba(0,0,0,.07);overflow:hidden;">
                    <div style="height:100%;width:<?= $hrp_v ?>%;background:<?= $hc ?>;border-radius:999px;"></div>
                </div>
                <div style="font-size:.6rem;color:<?= $hc ?>;margin-top:2px;font-weight:600;"><?= htmlspecialchars($r['hacera_band']??'') ?></div>
            </div>
            <?php endif; ?>

            <!-- Action buttons -->
            <div style="margin-top:auto;display:grid;grid-template-columns:1fr 1fr;gap:8px;padding-top:10px;border-top:1px solid var(--neutral-100);">
                <a href="<?= BASE_URL ?>dashboard/customer/restaurants.php?action=view&id=<?= $r['id'] ?>"
                   class="btn btn-sm btn-primary" style="justify-content:center;gap:6px;font-weight:700;">
                    <i class="fas fa-utensils"></i> Order Food
                </a>
                <a href="<?= BASE_URL ?>dashboard/customer/restaurant_view.php?id=<?= $r['id'] ?>"
                   class="btn btn-sm btn-outline" style="justify-content:center;gap:6px;">
                    <i class="fas fa-info-circle"></i> Details
                </a>
            </div>

        </div><!-- /restaurant-info -->
    </div><!-- /card -->
    <?php endforeach; ?>
</div><!-- /grid -->
<?php endif; // empty restaurants ?>

<?php elseif ($action === 'view' && isset($_GET['id'])): ?>
<?php
    $rid = intval($_GET['id']);

    // Track online menu/profile views (customer dashboard uses this route)
    logBusinessView($conn, $rid);

    // Flash message from completed survey redirect
    $survey_done_flash = '';
    if (!empty($_SESSION['survey_complete_flash'])) {
        $survey_done_flash = $_SESSION['survey_complete_flash'];
        unset($_SESSION['survey_complete_flash']);
    }


    $r = $conn->query("
        SELECT hr.*, u.full_name AS owner_name,
               hc.id AS cert_id, hc.certificate_number, hc.issue_date, hc.expiry_date,
               hc.status AS cert_status, hc.certifying_body
        FROM halal_restaurants hr
        JOIN users u ON hr.business_owner_id = u.id
        LEFT JOIN halal_certificates hc
            ON  hc.application_id = (
                    SELECT ha.id FROM hdp_applications ha
                    WHERE ha.business_owner_id = hr.business_owner_id
                    ORDER BY ha.created_at DESC LIMIT 1
                )
            AND hc.payment_status = 'paid'
            AND hc.status NOT IN ('rejected','revoked','expired','suspended')
        WHERE hr.id = $rid
    ")->fetch_assoc();
    // Fetch all items: available ones first, unavailable shown as Sold Out
    $menu = $conn->query("SELECT * FROM menu_items WHERE restaurant_id = $rid AND approval_status = 'approved' ORDER BY is_available DESC, category, name")->fetch_all(MYSQLI_ASSOC);

    // Ingredient transparency: load recipe ingredients for each menu item
    $menu_ingredients_ord = [];
$structured_menu_ids_ord = [];
$recipe_table_ord = $conn->query("SHOW TABLES LIKE 'menu_recipes'");
$recipe_tables_ready_ord = $recipe_table_ord && $recipe_table_ord->num_rows > 0;
if ($recipe_tables_ready_ord && !empty($menu)) {
    $ord_menu_ids = implode(',', array_map(fn($m) => (int)$m['id'], $menu));
    $active_recipe_res = $conn->query("SELECT menu_item_id FROM menu_recipes WHERE restaurant_id = $rid AND recipe_status = 'active' AND menu_item_id IN ($ord_menu_ids)");
    if ($active_recipe_res) {
        while ($active_recipe = $active_recipe_res->fetch_assoc()) {
            $structured_menu_ids_ord[(int)$active_recipe['menu_item_id']] = true;
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
          AND mr.menu_item_id IN ($ord_menu_ids)
          AND mr.recipe_status = 'active'
          AND mr.customer_visibility_status = 'published'
          AND ri.mapping_status = 'confirmed'
          AND ri.inventory_id IS NOT NULL
        ORDER BY mr.menu_item_id, ri.id");
    if ($published_res) {
        while ($oi = $published_res->fetch_assoc()) {
            $menu_ingredients_ord[(int)$oi['menu_item_id']][] = $oi;
        }
    }
}
$legacy_ord_ids = array_values(array_filter(array_map(fn($m) => (int)$m['id'], $menu), fn($id) => empty($structured_menu_ids_ord[$id])));
if (!empty($legacy_ord_ids)) {
    $legacy_ord_sql = implode(',', $legacy_ord_ids);
    $ord_ing_res = $conn->query("SELECT mri.menu_item_id,
            ki.ingredient_name,
            ki.halal_status,
            COALESCE(ic_child.name, '') AS category_name,
            COALESCE(ic_parent.name, '') AS parent_category_name
        FROM menu_recipe_ingredients mri
        JOIN kitchen_inventory ki ON mri.inventory_id = ki.id AND ki.restaurant_id = $rid
        LEFT JOIN ingredients_category ic_child ON ki.category_id = ic_child.id
        LEFT JOIN ingredients_category ic_parent ON ic_child.parent_id = ic_parent.id
        WHERE mri.menu_item_id IN ($legacy_ord_sql)
        ORDER BY mri.menu_item_id, ki.ingredient_name");
    if ($ord_ing_res) {
        while ($oi = $ord_ing_res->fetch_assoc()) {
            $menu_ingredients_ord[(int)$oi['menu_item_id']][] = $oi;
        }
    }
}
if (!function_exists('halalLabelOrd')) {
        function halalLabelOrd(string $s): string {
            return match($s) {
                'halal'          => 'Halal (Chef-verified)',
                'self_certified' => 'Supplier declaration recorded',
                'fresh_produce'  => 'Fresh ingredient record',
                'non_halal'      => 'Ingredient flagged for review',
                default          => 'Evidence review pending',
            };
        }
        function halalColorOrd(string $s): string {
            return match($s) {
                'halal'          => '#16a34a',
                'self_certified' => '#d97706',
                'fresh_produce'  => '#65a30d',
                'non_halal'      => '#dc2626',
                default          => '#d97706',
            };
        }
    }

    $reviews = $conn->query("SELECT rv.*, u.full_name, u.avatar FROM reviews rv JOIN users u ON rv.customer_id = u.id WHERE rv.restaurant_id = $rid ORDER BY rv.created_at DESC LIMIT 10")->fetch_all(MYSQLI_ASSOC);

    // Compute live aggregates (never trust the denormalized column for display)
    $live_review_count = count($reviews);
    $live_avg_rating   = $live_review_count > 0
        ? round(array_sum(array_column($reviews, 'rating')) / $live_review_count, 1)
        : 0.0;

    // Fetch photos for all reviews in one query
    $review_photos_map = [];
    if (!empty($reviews)) {
        $rv_ids = implode(',', array_map(fn($r) => (int)$r['id'], $reviews));
        $photos_result = $conn->query(
            "SELECT review_id, image_path FROM review_photos WHERE review_id IN ($rv_ids) ORDER BY id ASC"
        );
        if ($photos_result) {
            while ($ph = $photos_result->fetch_assoc()) {
                $review_photos_map[(int)$ph['review_id']][] = $ph['image_path'];
            }
        }
    }
    
    // Check if user already reviewed
    $already_reviewed = $conn->prepare("SELECT id FROM reviews WHERE customer_id = ? AND restaurant_id = ?");
    $already_reviewed->bind_param("ii", $user_id, $rid);
    $already_reviewed->execute();
    $has_review = $already_reviewed->get_result()->num_rows > 0;
?>
<?php if ($r): ?>
<?php $service_badges = []; if (!empty($r['services_offered'])) { $service_badges = json_decode($r['services_offered'], true) ?: []; } ?>
<a href="?action=list" class="btn btn-outline btn-sm" style="margin-bottom:20px"><i class="fas fa-arrow-left"></i> Back</a>

<div style="display: grid; grid-template-columns: 2fr 1fr; gap: 24px;">
    <div>
        <!-- Restaurant Info -->
        <div class="card" style="margin-bottom: 24px;">
            <div style="height:200px;background:linear-gradient(135deg,var(--primary-100),var(--accent-50));display:flex;align-items:center;justify-content:center;position:relative;overflow:hidden;">
                <?php if (!empty($r['image_path'])): ?>
                    <img src="<?= BASE_URL ?>uploads/<?= htmlspecialchars($r['image_path']) ?>"
                         alt="<?= htmlspecialchars($r['restaurant_name']) ?>"
                         style="width:100%;height:100%;object-fit:cover;position:absolute;inset:0;">
                <?php else: ?>
                    <i class="fas fa-store" style="font-size:4rem;color:var(--primary-300)"></i>
                <?php endif; ?>
                <?php if ($r['certificate_number']): ?>
                <button type="button" onclick="openModal('certModal')"
                    style="position:absolute;top:16px;right:16px;background:white;padding:8px 16px;border-radius:20px;display:flex;align-items:center;gap:8px;font-weight:600;color:#166534;box-shadow:var(--shadow-md);border:none;cursor:pointer;transition:all .15s;"
                    onmouseover="this.style.background='#dcfce7'" onmouseout="this.style.background='white'">
                    <i class="fas fa-certificate" style="color:#16a34a;font-size:1rem;"></i>
                    Halal Certified
                    <i class="fas fa-external-link-alt" style="font-size:0.7rem;color:#94a3b8;"></i>
                </button>
                <?php endif; ?>
            </div>
            <div class="card-body">
                <h2 style="font-family:var(--font-display);font-weight:800;font-size:1.5rem;margin-bottom:8px"><?= htmlspecialchars($r['restaurant_name']) ?></h2>
                <div style="display:flex;gap:16px;flex-wrap:wrap;margin-bottom:16px;font-size:0.9rem;color:var(--neutral-500)">
                    <span><i class="fas fa-map-marker-alt" style="color:var(--primary-500)"></i> <?= htmlspecialchars($r['address']) ?></span>
                    <?php if ($r['phone']): ?><span><i class="fas fa-phone" style="color:var(--primary-500)"></i> <?= htmlspecialchars($r['phone']) ?></span><?php endif; ?>
                    <?php if ($r['cuisine_type']): ?><span><i class="fas fa-utensils" style="color:var(--primary-500)"></i> <?= htmlspecialchars($r['cuisine_type']) ?></span><?php endif; ?>
                    <?php if ($r['operating_hours']): ?><span><i class="fas fa-clock" style="color:var(--primary-500)"></i> <?= htmlspecialchars($r['operating_hours']) ?></span><?php endif; ?>
                </div>
                <?php if ($r['description']): ?><p style="color:var(--neutral-600);line-height:1.7"><?= nl2br(htmlspecialchars($r['description'])) ?></p><?php endif; ?>
                <?php if (!empty($service_badges)): ?>
                <div style="display:flex;flex-wrap:wrap;gap:8px;margin-top:12px;">
                    <?php foreach ($service_badges as $service): ?>
                    <span style="background:#eff6ff;color:#1d4ed8;padding:6px 10px;border-radius:999px;font-size:0.8rem;"><?= htmlspecialchars($service) ?></span>
                    <?php endforeach; ?>
                </div>
                <?php endif; ?>

                <?php if (!empty($r['hacera_hrp'])): ?>
                <?php
                    $hrp_v  = (float)$r['hacera_hrp'];
                    $hb     = htmlspecialchars($r['hacera_band'] ?? '');
                    [$hc, $hbg] = match(true) {
                        $hrp_v >= 90 => ['#16a34a','#dcfce7'],
                        $hrp_v >= 70 => ['#2563eb','#dbeafe'],
                        $hrp_v >= 50 => ['#d97706','#fef3c7'],
                        $hrp_v >= 25 => ['#ea580c','#ffedd5'],
                        default      => ['#dc2626','#fee2e2'],
                    };
                ?>
                <div style="margin-top:16px;padding:14px 16px;border-radius:10px;
                            background:<?= $hbg ?>;border:1px solid <?= $hc ?>33;">
                    <div style="display:flex;align-items:center;justify-content:space-between;margin-bottom:8px;">
                        <span style="font-size:.82rem;font-weight:700;color:<?= $hc ?>;
                                     display:flex;align-items:center;gap:7px;">
                            <i class="fas fa-clipboard-check"></i>
                            Halal Readiness Assessment (HaCeRa)
                        </span>
                        <span style="font-size:1.1rem;font-weight:800;color:<?= $hc ?>;"><?= $hrp_v ?>%</span>
                    </div>
                    <div style="height:8px;border-radius:999px;background:rgba(0,0,0,.1);overflow:hidden;margin-bottom:6px;">
                        <div style="height:100%;width:<?= $hrp_v ?>%;background:<?= $hc ?>;border-radius:999px;
                                    transition:width .6s ease;"></div>
                    </div>
                    <div style="display:flex;justify-content:space-between;align-items:center;">
                        <span style="font-size:.75rem;font-weight:700;color:<?= $hc ?>;"><?= $hb ?></span>
                        <span style="font-size:.7rem;color:<?= $hc ?>;opacity:.75;">
                            <i class="fas fa-clock" style="font-size:.6rem;"></i>
                            Updated <?= $r['hacera_published_at'] ? date('M j, Y', strtotime($r['hacera_published_at'])) : '—' ?>
                        </span>
                    </div>
                </div>
                <?php endif; ?>
            </div>
        </div>
        
        <!-- Menu -->
        <div class="card" style="margin-bottom: 24px;">
            <div class="card-header"><h3><i class="fas fa-book-open" style="color:var(--accent-500);margin-right:8px"></i> Menu</h3></div>
            <div class="card-body">
                <?php if (empty($menu)): ?>
                    <p style="color:var(--neutral-500);text-align:center;padding:20px">Menu items coming soon!</p>
                <?php else: ?>
                    <?php
                    $categories = [];
                    foreach ($menu as $item) $categories[$item['category'] ?? 'General'][] = $item;
                    foreach ($categories as $cat => $items):
                    ?>
                    <h4 style="font-family:var(--font-display);font-weight:700;margin:16px 0 12px;color:var(--neutral-700)"><?= htmlspecialchars($cat) ?></h4>
                    <?php foreach ($items as $item):
                        $item_avail    = (int)($item['is_available'] ?? 1) === 1;
                        $item_ings_ord = $menu_ingredients_ord[(int)$item['id']] ?? [];
                    ?>
                    <div class="menu-dish-card" style="display:flex;gap:14px;padding:14px;margin-bottom:12px;
                                background:<?= $item_avail ? '#f8fafc' : '#fafafa' ?>;
                                border:1px solid <?= $item_avail ? '#e2e8f0' : '#fca5a5' ?>;
                                border-radius:12px;align-items:flex-start;
                                opacity:<?= $item_avail ? '1' : '0.7' ?>;">
                        <!-- Dish image (with SOLD OUT badge) -->
                        <div style="position:relative;flex-shrink:0;width:72px;height:72px;">
                            <?php if (!empty($item['image_path'])): ?>
                            <img src="<?= BASE_URL ?>uploads/<?= htmlspecialchars($item['image_path']) ?>"
                                 style="width:72px;height:72px;object-fit:cover;border-radius:10px;" alt="<?= htmlspecialchars($item['name']) ?>">
                            <?php else: ?>
                            <div style="width:72px;height:72px;background:#e2e8f0;border-radius:10px;display:flex;align-items:center;justify-content:center;">
                                <i class="fas fa-utensils" style="color:#94a3b8;font-size:1.4rem;"></i>
                            </div>
                            <?php endif; ?>
                            <?php if (!$item_avail): ?>
                            <span style="position:absolute;bottom:-6px;left:50%;transform:translateX(-50%);
                                         background:#dc2626;color:#fff;font-size:.55rem;font-weight:800;
                                         padding:2px 6px;border-radius:20px;white-space:nowrap;letter-spacing:.04em;">
                                SOLD OUT
                            </span>
                            <?php endif; ?>
                        </div>

                        <!-- Info -->
                        <div style="flex:1;min-width:0;">
                            <div style="font-weight:700;font-size:0.95rem;margin-bottom:2px;">
                                <?= htmlspecialchars($item['name']) ?>
                            </div>
                            <?php if ($item['description']): ?>
                            <div style="font-size:0.8rem;color:var(--neutral-500);margin-bottom:4px;">
                                <?= htmlspecialchars($item['description']) ?>
                            </div>
                            <?php endif; ?>
                            <?php if (!empty($item['serving_type'])): ?>
                            <div style="font-size:0.75rem;color:var(--neutral-400);">
                                Serving: <?= htmlspecialchars($item['serving_type']) ?>
                            </div>
                            <?php endif; ?>
                            <div style="font-weight:700;color:var(--primary-700);margin-top:4px;">
                                <?= formatCurrency($item['price']) ?>
                            </div>

                            <?php if (!empty($item_ings_ord)): ?>
                            <!-- ── Ingredient & Halal Status transparency ── -->
                            <details style="margin-top:10px;">
                                <summary style="font-size:.73rem;font-weight:700;color:#6366f1;cursor:pointer;
                                                list-style:none;display:flex;align-items:center;gap:5px;
                                                user-select:none;">
                                    <i class="fas fa-leaf" style="font-size:.68rem;"></i>
                                    Ingredients &amp; Halal Status
                                    <i class="fas fa-chevron-down" style="font-size:.58rem;margin-left:2px;"></i>
                                </summary>
                                <div style="margin-top:8px;display:flex;flex-direction:column;gap:4px;">
                                <?php foreach ($item_ings_ord as $oi):
                                    $cat_lbl = '';
                                    if ($oi['parent_category_name'] && $oi['category_name']) {
                                        $cat_lbl = $oi['parent_category_name'] . ' (' . $oi['category_name'] . ')';
                                    } elseif ($oi['category_name']) {
                                        $cat_lbl = $oi['category_name'];
                                    }
                                    $hs_c = halalColorOrd($oi['halal_status']);
                                    $hs_l = halalLabelOrd($oi['halal_status']);
                                ?>
                                <div style="font-size:.73rem;display:flex;align-items:center;flex-wrap:wrap;gap:4px;
                                            background:#f1f5f9;border-radius:6px;padding:4px 8px;
                                            border-left:3px solid <?= $hs_c ?>;">
                                    <span style="font-weight:600;color:#1e293b;">
                                        <?= htmlspecialchars($oi['ingredient_name']) ?>
                                    </span>
                                    <?php if ($cat_lbl): ?>
                                    <span style="color:#94a3b8;">·</span>
                                    <span style="color:#64748b;"><?= htmlspecialchars($cat_lbl) ?></span>
                                    <?php endif; ?>
                                    <span style="color:#94a3b8;">·</span>
                                    <span style="color:<?= $hs_c ?>;font-weight:700;"><?= $hs_l ?></span>
                                </div>
                                <?php endforeach; ?>
                                </div>
                            </details>
                            <?php endif; ?>
                        </div>

                        <!-- Qty stepper + Add button (disabled when sold out) -->
                        <div style="display:flex;flex-direction:column;align-items:center;gap:8px;flex-shrink:0;">
                            <?php if ($item_avail): ?>
                            <div style="display:flex;align-items:center;gap:0;border:1px solid #e2e8f0;border-radius:8px;overflow:hidden;background:#fff;">
                                <button type="button" class="qty-btn qty-dec"
                                        style="width:30px;height:30px;border:none;background:transparent;cursor:pointer;font-size:1rem;color:var(--neutral-600);"
                                        data-id="<?= $item['id'] ?>">−</button>
                                <input type="number" class="qty-input"
                                       value="1" min="1" max="99"
                                       data-id="<?= $item['id'] ?>"
                                       style="width:36px;height:30px;text-align:center;border:none;border-left:1px solid #e2e8f0;border-right:1px solid #e2e8f0;font-size:0.88rem;font-weight:600;-moz-appearance:textfield;">
                                <button type="button" class="qty-btn qty-inc"
                                        style="width:30px;height:30px;border:none;background:transparent;cursor:pointer;font-size:1rem;color:var(--neutral-600);"
                                        data-id="<?= $item['id'] ?>">+</button>
                            </div>
                            <button type="button" class="btn btn-sm btn-primary add-to-cart-btn"
                                    style="font-size:0.78rem;padding:5px 12px;white-space:nowrap;"
                                    data-id="<?= $item['id'] ?>"
                                    data-name="<?= htmlspecialchars($item['name'], ENT_QUOTES) ?>"
                                    data-price="<?= $item['price'] ?>">
                                <i class="fas fa-plus"></i> Add
                            </button>
                            <?php else: ?>
                            <span style="font-size:.72rem;color:#dc2626;font-weight:700;
                                         background:#fee2e2;padding:5px 10px;border-radius:8px;
                                         text-align:center;">
                                Not available
                            </span>
                            <?php endif; ?>
                        </div>
                    </div>
                    <?php endforeach; ?>
                    <?php endforeach; ?>
                <?php endif; ?>
            </div>
        </div>
        
        <!-- Reviews -->
        <div class="card">
            <div class="card-header"><h3><i class="fas fa-star" style="color:var(--accent-500);margin-right:8px"></i> Reviews (<?= count($reviews) ?>)</h3></div>
            <div class="card-body">
                <?php if (empty($reviews)): ?>
                    <p style="color:var(--neutral-500);text-align:center;padding:20px 0 10px;">No reviews yet. Be the first to review!</p>
                <?php else: ?>
                    <?php foreach ($reviews as $rv):
                        $rv_photos = $review_photos_map[(int)$rv['id']] ?? [];
                    ?>
                    <div style="padding:16px 0;border-bottom:1px solid var(--neutral-100)">
                        <div style="display:flex;align-items:center;gap:12px;margin-bottom:8px">
                            <div style="width:36px;height:36px;border-radius:50%;background:var(--primary-100);color:var(--primary-700);display:flex;align-items:center;justify-content:center;font-weight:700;font-size:0.8rem"><?= strtoupper(substr($rv['full_name'],0,1)) ?></div>
                            <div>
                                <div style="font-weight:600;font-size:0.9rem"><?= htmlspecialchars($rv['full_name']) ?></div>
                                <div style="font-size:0.75rem;color:var(--neutral-400)"><?= timeAgo($rv['created_at']) ?></div>
                            </div>
                            <div style="margin-left:auto;display:flex;align-items:center;gap:8px;">
                                <span style="font-size:0.75rem;color:var(--neutral-400);">
                                    Food <?= $rv['food_rating'] ?>/5 &bull; Service <?= $rv['service_rating'] ?>/5
                                </span>
                                <div>
                                <?php for ($s = 1; $s <= 5; $s++): ?>
                                    <i class="fas fa-star" style="color:<?= $s <= $rv['rating'] ? 'var(--accent-400)' : 'var(--neutral-200)' ?>;font-size:0.8rem"></i>
                                <?php endfor; ?>
                                </div>
                            </div>
                        </div>
                        <?php if ($rv['comment']): ?>
                        <p style="font-size:0.9rem;color:var(--neutral-600);line-height:1.6;margin-bottom:<?= !empty($rv_photos) ? '12px' : '0' ?>">
                            <?= nl2br(htmlspecialchars($rv['comment'])) ?>
                        </p>
                        <?php endif; ?>
                        <?php if (!empty($rv_photos)): ?>
                        <div style="display:flex;flex-wrap:wrap;gap:8px;margin-top:8px;">
                            <?php foreach ($rv_photos as $photo_path): ?>
                            <a href="<?= BASE_URL ?>uploads/<?= htmlspecialchars($photo_path) ?>"
                               target="_blank" rel="noopener"
                               style="display:block;flex-shrink:0;">
                                <img src="<?= BASE_URL ?>uploads/<?= htmlspecialchars($photo_path) ?>"
                                     alt="Review photo"
                                     style="width:80px;height:80px;object-fit:cover;border-radius:8px;
                                            border:1px solid #e2e8f0;transition:opacity .15s;"
                                     onmouseover="this.style.opacity='.8'"
                                     onmouseout="this.style.opacity='1'">
                            </a>
                            <?php endforeach; ?>
                        </div>
                        <?php endif; ?>
                    </div>
                    <?php endforeach; ?>
                <?php endif; ?>

                <?php if (!$has_review): ?>
                <!-- ── Inline Write Review form ──────────────────────────── -->
                <div style="margin-top:24px;padding-top:20px;border-top:2px solid var(--neutral-100);">
                    <div style="font-weight:700;font-size:0.95rem;margin-bottom:16px;display:flex;align-items:center;gap:8px;">
                        <i class="fas fa-pen" style="color:var(--accent-500);"></i> Write a Review
                    </div>
                    <form method="POST">
                        <input type="hidden" name="submit_review" value="1">
                        <input type="hidden" name="restaurant_id" value="<?= $r['id'] ?>">

                        <!-- Interactive star picker -->
                        <div class="form-group">
                            <label style="font-size:0.82rem;font-weight:700;color:var(--neutral-700);">Overall Rating <span style="color:var(--danger);">*</span></label>
                            <div class="star-picker" style="display:flex;gap:6px;margin-bottom:6px;" id="starPicker">
                                <?php for ($s = 1; $s <= 5; $s++): ?>
                                <i class="fas fa-star star-pick" data-val="<?= $s ?>"
                                   style="font-size:1.6rem;cursor:pointer;color:var(--neutral-200);transition:color .1s;"
                                   title="<?= $s ?> star<?= $s > 1 ? 's' : '' ?>"></i>
                                <?php endfor; ?>
                            </div>
                            <input type="hidden" name="rating" id="ratingInput" value="5">
                            <div id="ratingLabel" style="font-size:0.78rem;color:var(--neutral-400);">5 stars — Excellent</div>
                        </div>

                        <!-- Sub-ratings -->
                        <div style="display:grid;grid-template-columns:1fr 1fr;gap:12px;margin-bottom:4px;">
                            <div class="form-group" style="margin-bottom:0;">
                                <label style="font-size:0.78rem;font-weight:600;color:var(--neutral-600);">Food Quality</label>
                                <select name="food_rating" class="form-control" style="font-size:0.85rem;">
                                    <option value="5">5 — Excellent</option>
                                    <option value="4">4 — Very Good</option>
                                    <option value="3">3 — Good</option>
                                    <option value="2">2 — Fair</option>
                                    <option value="1">1 — Poor</option>
                                </select>
                            </div>
                            <div class="form-group" style="margin-bottom:0;">
                                <label style="font-size:0.78rem;font-weight:600;color:var(--neutral-600);">Service</label>
                                <select name="service_rating" class="form-control" style="font-size:0.85rem;">
                                    <option value="5">5 — Excellent</option>
                                    <option value="4">4 — Very Good</option>
                                    <option value="3">3 — Good</option>
                                    <option value="2">2 — Fair</option>
                                    <option value="1">1 — Poor</option>
                                </select>
                            </div>
                        </div>

                        <div class="form-group" style="margin-top:14px;">
                            <label style="font-size:0.82rem;font-weight:700;color:var(--neutral-700);">Your Comment</label>
                            <textarea name="comment" class="form-control" rows="3"
                                      placeholder="Share your experience — food quality, service, ambiance…"
                                      style="font-size:0.85rem;resize:vertical;"></textarea>
                        </div>

                        <button type="submit" class="btn btn-accent btn-block" style="margin-top:4px;">
                            <i class="fas fa-paper-plane"></i> Submit Review
                        </button>
                    </form>
                </div>
                <?php else: ?>
                <div style="margin-top:20px;padding:12px 16px;background:#f0fdf4;border:1px solid #bbf7d0;border-radius:8px;font-size:0.85rem;color:#166534;display:flex;align-items:center;gap:8px;">
                    <i class="fas fa-check-circle"></i> You have already submitted a review for this restaurant.
                </div>
                <?php endif; ?>
            </div>
        </div>
    </div>
    
    <!-- Sidebar: Order & Review -->
    <div>
        <!-- Quick Order -->
        <div class="card" style="margin-bottom:24px;position:sticky;top:20px;">
            <?php if ($survey_done_flash): ?>
            <div style="background:#f0fdf4;border-bottom:2px solid #86efac;padding:12px 16px;display:flex;align-items:center;gap:10px;border-radius:12px 12px 0 0;">
                <i class="fas fa-check-circle" style="color:#16a34a;font-size:1.1rem;flex-shrink:0;"></i>
                <span style="font-size:0.85rem;font-weight:600;color:#166534;"><?= htmlspecialchars($survey_done_flash) ?></span>
            </div>
            <?php endif; ?>
            <div class="card-header" style="justify-content:space-between;<?= $survey_done_flash ? 'border-radius:0;' : '' ?>">
                <h3><i class="fas fa-shopping-cart" style="color:var(--primary-600);margin-right:8px"></i> Your Order</h3>
                <span id="cartBadge" style="display:none;background:var(--primary-600);color:#fff;padding:2px 10px;border-radius:99px;font-size:0.78rem;font-weight:700;">0 items</span>
            </div>
            <div class="card-body">

                <!-- Payment banner alert (cancelled / pending) -->
                <?php if (!empty($_GET['payment'])): ?>
                <div class="alert alert-<?= $_GET['payment'] === 'cancelled' ? 'danger' : 'warning' ?>" style="margin-bottom:14px;">
                    <i class="fas fa-<?= $_GET['payment'] === 'cancelled' ? 'times-circle' : 'clock' ?>"></i>
                    <span><?= $_GET['payment'] === 'cancelled' ? 'Payment was cancelled. You can try again.' : 'Payment is pending verification.' ?></span>
                </div>
                <?php endif; ?>

                <!-- Cart empty state -->
                <div id="cartEmpty" style="text-align:center;padding:20px 0;color:var(--neutral-400);">
                    <i class="fas fa-shopping-basket" style="font-size:2rem;display:block;margin-bottom:8px;"></i>
                    <div style="font-size:0.85rem;">Add dishes from the menu to start your order.</div>
                </div>

                <!-- Cart item list -->
                <div id="cartItems" style="display:none;margin-bottom:14px;">
                    <div id="cartList" style="display:flex;flex-direction:column;gap:8px;max-height:240px;overflow-y:auto;padding-right:4px;"></div>

                    <!-- Order breakdown: subtotal + fee + grand total -->
                    <div style="border-top:2px solid var(--neutral-100);margin-top:12px;padding-top:10px;">
                        <div style="display:flex;justify-content:space-between;align-items:center;margin-bottom:4px;">
                            <span style="font-size:0.82rem;color:var(--neutral-500);">Subtotal</span>
                            <span id="cartSubtotal" style="font-size:0.82rem;color:var(--neutral-600);font-weight:600;">₱0.00</span>
                        </div>
                        <div id="deliveryFeeRow" style="display:none;justify-content:space-between;align-items:center;margin-bottom:4px;">
                            <span style="font-size:0.82rem;color:var(--neutral-500);">🛵 Delivery Fee</span>
                            <span style="font-size:0.82rem;color:var(--neutral-600);font-weight:600;">₱50.00</span>
                        </div>
                        <div style="display:flex;justify-content:space-between;align-items:center;padding-top:6px;border-top:1px dashed var(--neutral-200);">
                            <span style="font-weight:700;color:var(--neutral-700);">Total</span>
                            <span id="cartTotal" style="font-size:1.1rem;font-weight:800;color:var(--primary-700);">₱0.00</span>
                        </div>
                    </div>
                </div>

                <!-- Delivery section wrapper — shown when cart has items -->
                <div id="deliverySection" style="display:none;">

                    <!-- ── Service Type Selector ─────────────────────────── -->
                    <div style="margin-bottom:16px;">
                        <label style="font-size:0.82rem;font-weight:700;color:var(--neutral-700);display:block;margin-bottom:8px;">
                            Service Type <span style="color:var(--danger);">*</span>
                        </label>
                        <div style="display:grid;grid-template-columns:repeat(3,1fr);gap:6px;" id="serviceTypeGroup">

                            <label id="stLabel_dine_in"
                                   style="display:flex;flex-direction:column;align-items:center;gap:5px;padding:10px 6px;border:2px solid #e2e8f0;border-radius:10px;cursor:pointer;transition:all .15s;text-align:center;">
                                <input type="radio" name="service_type_ui" value="dine_in"
                                       style="position:absolute;opacity:0;pointer-events:none;">
                                <span style="font-size:1.3rem;">🍽️</span>
                                <span style="font-size:0.76rem;font-weight:700;color:var(--neutral-600);">Dine-in</span>
                            </label>

                            <label id="stLabel_takeout"
                                   style="display:flex;flex-direction:column;align-items:center;gap:5px;padding:10px 6px;border:2px solid #e2e8f0;border-radius:10px;cursor:pointer;transition:all .15s;text-align:center;">
                                <input type="radio" name="service_type_ui" value="takeout"
                                       style="position:absolute;opacity:0;pointer-events:none;">
                                <span style="font-size:1.3rem;">🛍️</span>
                                <span style="font-size:0.76rem;font-weight:700;color:var(--neutral-600);">Takeout</span>
                            </label>

                            <label id="stLabel_delivery"
                                   style="display:flex;flex-direction:column;align-items:center;gap:5px;padding:10px 6px;border:2px solid #e2e8f0;border-radius:10px;cursor:pointer;transition:all .15s;text-align:center;">
                                <input type="radio" name="service_type_ui" value="delivery"
                                       style="position:absolute;opacity:0;pointer-events:none;">
                                <span style="font-size:1.3rem;">🛵</span>
                                <span style="font-size:0.76rem;font-weight:700;color:var(--neutral-600);">Delivery</span>
                            </label>

                        </div>
                        <!-- Inline validation hint -->
                        <div id="serviceTypeError"
                             style="display:none;margin-top:6px;font-size:0.78rem;color:#dc2626;font-weight:600;">
                            <i class="fas fa-exclamation-circle"></i> Please select a service type.
                        </div>
                    </div>
                    <!-- ── /Service Type Selector ─────────────────────────── -->

                    <!-- Delivery address — shown only for Delivery -->
                    <div id="addressBlock" style="display:none;overflow:hidden;transition:max-height .3s ease,opacity .3s ease;max-height:0;opacity:0;">
                        <div style="font-size:0.82rem;font-weight:700;color:var(--neutral-700);margin-bottom:10px;">
                            <i class="fas fa-map-marker-alt" style="color:var(--primary-500);margin-right:4px;"></i>
                            Delivery Address <span style="color:var(--danger);">*</span>
                        </div>

                        <!-- Row 1: Street (full width) -->
                        <div class="form-group" style="margin-bottom:10px;">
                            <label style="font-size:0.75rem;font-weight:600;color:var(--neutral-500);margin-bottom:3px;">House / Unit No. &amp; Street Name <span style="color:var(--danger);">*</span></label>
                            <input type="text" id="addrStreet" class="form-control" placeholder="e.g. 12 Rizal St., Purok 3"
                                   style="font-size:0.85rem;">
                        </div>

                        <!-- Row 2: Barangay + Town (2-col) -->
                        <div style="display:grid;grid-template-columns:1fr 1fr;gap:10px;margin-bottom:10px;">
                            <div class="form-group" style="margin-bottom:0;">
                                <label style="font-size:0.75rem;font-weight:600;color:var(--neutral-500);margin-bottom:3px;">Barangay <span style="color:var(--danger);">*</span></label>
                                <input type="text" id="addrBarangay" class="form-control" placeholder="e.g. Brgy. San Roque"
                                       style="font-size:0.85rem;">
                            </div>
                            <div class="form-group" style="margin-bottom:0;">
                                <label style="font-size:0.75rem;font-weight:600;color:var(--neutral-500);margin-bottom:3px;">Town / Municipality</label>
                                <input type="text" id="addrTown" class="form-control" placeholder="e.g. Cotabato City"
                                       style="font-size:0.85rem;">
                            </div>
                        </div>

                        <!-- Row 3: City + Province (2-col) -->
                        <div style="display:grid;grid-template-columns:1fr 1fr;gap:10px;margin-bottom:14px;">
                            <div class="form-group" style="margin-bottom:0;">
                                <label style="font-size:0.75rem;font-weight:600;color:var(--neutral-500);margin-bottom:3px;">City <span style="color:var(--danger);">*</span></label>
                                <input type="text" id="addrCity" class="form-control" placeholder="e.g. General Santos"
                                       style="font-size:0.85rem;">
                            </div>
                            <div class="form-group" style="margin-bottom:0;">
                                <label style="font-size:0.75rem;font-weight:600;color:var(--neutral-500);margin-bottom:3px;">Province <span style="color:var(--danger);">*</span></label>
                                <input type="text" id="addrProvince" class="form-control" placeholder="e.g. South Cotabato"
                                       style="font-size:0.85rem;">
                            </div>
                        </div>
                    </div>
                    <!-- /addressBlock -->

                    <!-- Special notes -->
                    <div class="form-group">
                        <label style="font-size:0.82rem;font-weight:700;color:var(--neutral-700);">Special Requests <span style="font-weight:400;color:var(--neutral-400);">(optional)</span></label>
                        <textarea id="orderNotes" class="form-control" rows="2"
                                  placeholder="Allergies, no onions, extra sauce…"
                                  style="font-size:0.83rem;resize:none;"></textarea>
                    </div>

                    <!-- Payment method -->
                    <div class="form-group">
                        <label style="font-size:0.82rem;font-weight:700;color:var(--neutral-700);">Payment Method <span style="color:var(--danger);">*</span></label>
                        <div style="display:flex;flex-direction:column;gap:8px;" id="paymentMethods">
                            <label style="display:flex;align-items:center;gap:10px;padding:10px 12px;border:2px solid var(--primary-500);border-radius:10px;cursor:pointer;transition:border-color .15s;" id="pmLabel_cash">
                                <input type="radio" name="payment_method_ui" value="cash" checked style="accent-color:var(--primary-600);">
                                <i class="fas fa-money-bill-wave" style="color:#16a34a;font-size:1rem;"></i>
                                <span style="font-size:0.88rem;font-weight:600;">Cash</span>
                                <span style="margin-left:auto;font-size:0.72rem;color:#6b7280;background:#dcfce7;padding:2px 8px;border-radius:99px;">Pay at Cashier</span>
                            </label>
                            <label style="display:flex;align-items:center;gap:10px;padding:10px 12px;border:2px solid #e2e8f0;border-radius:10px;cursor:pointer;transition:border-color .15s;" id="pmLabel_gcash">
                                <input type="radio" name="payment_method_ui" value="gcash" style="accent-color:var(--primary-600);">
                                <i class="fas fa-mobile-alt" style="color:#1d4ed8;font-size:1rem;"></i>
                                <span style="font-size:0.88rem;font-weight:600;">GCash</span>
                                <span style="margin-left:auto;font-size:0.72rem;color:#6b7280;background:#eff6ff;padding:2px 8px;border-radius:99px;">via PayMongo</span>
                            </label>
                            <label style="display:flex;align-items:center;gap:10px;padding:10px 12px;border:2px solid #e2e8f0;border-radius:10px;cursor:pointer;transition:border-color .15s;" id="pmLabel_bank_transfer">
                                <input type="radio" name="payment_method_ui" value="bank_transfer" style="accent-color:var(--primary-600);">
                                <i class="fas fa-university" style="color:#7c3aed;font-size:1rem;"></i>
                                <span style="font-size:0.88rem;font-weight:600;">Bank Transfer</span>
                                <span style="margin-left:auto;font-size:0.72rem;color:#6b7280;background:#eff6ff;padding:2px 8px;border-radius:99px;">via PayMongo</span>
                            </label>
                        </div>
                        <!-- Payment method notice for delivery orders -->
                        <div id="deliveryPayNotice" style="display:none;margin-top:8px;padding:8px 10px;background:#fef3c7;border-left:3px solid #f59e0b;border-radius:6px;font-size:0.75rem;color:#92400e;">
                            <i class="fas fa-info-circle"></i> <strong>Delivery orders</strong> require online payment (GCash or Bank Transfer). Cash is only available for Dine-in and Takeout.
                        </div>
                    </div>

                    <!-- Order toast -->
                    <div id="orderToast" style="display:none;padding:10px 14px;border-radius:8px;font-size:0.85rem;font-weight:600;margin-bottom:12px;"></div>

                    <button id="placeOrderBtn" type="button" class="btn btn-primary btn-block"
                            style="font-size:0.95rem;padding:13px;">
                        <i class="fas fa-check-circle"></i> Place Order
                    </button>
                </div>
            </div>
        </div>
    </div>
</div>
<?php endif; ?>
<?php endif; ?>

<?php if ($action === 'view' && isset($r) && $r): ?>
<script>
(function () {
'use strict';

/* ── Config ────────────────────────────────────────────────────────────────── */
const RESTAURANT_ID  = <?= (int)$r['id'] ?>;
const AJAX_URL       = '<?= BASE_URL ?>dashboard/customer/api/place_order.php';
const DELIVERY_FEE   = 50; // flat ₱50 delivery fee

/* ── Cart state ────────────────────────────────────────────────────────────── */
const cart = new Map();

/* ── DOM refs ─────────────────────────────────────────────────────────────── */
const cartEmptyEl    = document.getElementById('cartEmpty');
const cartItemsEl    = document.getElementById('cartItems');
const cartListEl     = document.getElementById('cartList');
const cartSubtotalEl = document.getElementById('cartSubtotal');
const cartTotalEl    = document.getElementById('cartTotal');
const cartBadgeEl    = document.getElementById('cartBadge');
const deliverySect   = document.getElementById('deliverySection');
const deliveryFeeRow = document.getElementById('deliveryFeeRow');
const orderNotesEl   = document.getElementById('orderNotes');
const placeOrderBtn  = document.getElementById('placeOrderBtn');
const orderToastEl   = document.getElementById('orderToast');
const addressBlock   = document.getElementById('addressBlock');
const cashLabelEl    = document.getElementById('cashLabel');
const serviceTypeErr = document.getElementById('serviceTypeError');

/* ── Address field refs ────────────────────────────────────────────────────── */
const addrStreet   = document.getElementById('addrStreet');
const addrBarangay = document.getElementById('addrBarangay');
const addrTown     = document.getElementById('addrTown');
const addrCity     = document.getElementById('addrCity');
const addrProvince = document.getElementById('addrProvince');

/* ── Service type helper ─────────────────────────────────────────────────── */
function getServiceType() {
    const checked = document.querySelector('input[name="service_type_ui"]:checked');
    return checked ? checked.value : null;
}

/* ── Service-type UI update ─────────────────────────────────────────────── */
function applyServiceType(type) {
    // Reset all selector labels
    ['dine_in','takeout','delivery'].forEach(t => {
        const lbl = document.getElementById('stLabel_' + t);
        if (!lbl) return;
        lbl.style.borderColor  = '#e2e8f0';
        lbl.style.background   = '';
        const span = lbl.querySelector('span:last-child');
        if (span) span.style.color = 'var(--neutral-600)';
    });

    if (!type) return;

    // Highlight selected
    const activeLbl = document.getElementById('stLabel_' + type);
    if (activeLbl) {
        activeLbl.style.borderColor = 'var(--primary-500)';
        activeLbl.style.background  = 'var(--primary-50, #f0fdf4)';
        const span = activeLbl.querySelector('span:last-child');
        if (span) span.style.color = 'var(--primary-700)';
    }

    // Show / hide address block with smooth transition
    if (type === 'delivery') {
        addressBlock.style.display   = 'block';
        // Force reflow for transition
        addressBlock.offsetHeight;
        addressBlock.style.maxHeight = '600px';
        addressBlock.style.opacity   = '1';
    } else {
        addressBlock.style.maxHeight = '0';
        addressBlock.style.opacity   = '0';
        setTimeout(() => {
            if (getServiceType() !== 'delivery') {
                addressBlock.style.display = 'none';
            }
        }, 300);
    }

    // Show / hide delivery fee row and recompute total
    renderCart();

    // Payment method gating:
    // delivery → hide Cash, force GCash or Bank Transfer
    // dine_in / takeout → show Cash, re-select it
    const cashLabel    = document.getElementById('pmLabel_cash');
    const gcashRadio   = document.querySelector('input[name="payment_method_ui"][value="gcash"]');
    const cashRadio    = document.querySelector('input[name="payment_method_ui"][value="cash"]');
    const pmNotice     = document.getElementById('deliveryPayNotice');

    if (type === 'delivery') {
        // Hide cash option for delivery
        if (cashLabel)  cashLabel.style.display  = 'none';
        if (pmNotice)   pmNotice.style.display   = 'block';
        // If cash is currently selected, switch to gcash
        if (cashRadio && cashRadio.checked) {
            if (gcashRadio) {
                gcashRadio.checked = true;
                gcashRadio.dispatchEvent(new Event('change'));
            }
        }
    } else {
        // Show cash option for dine-in and takeout
        if (cashLabel)  cashLabel.style.display  = 'flex';
        if (pmNotice)   pmNotice.style.display   = 'none';
        if (cashRadio) {
            cashRadio.checked = true;
            cashRadio.dispatchEvent(new Event('change'));
        }
    }

    // Hide validation error if type is now selected
    if (serviceTypeErr) serviceTypeErr.style.display = 'none';
}

/* ── Wire service type radios ────────────────────────────────────────────── */
document.querySelectorAll('input[name="service_type_ui"]').forEach(radio => {
    radio.addEventListener('change', () => applyServiceType(radio.value));
});

/* ── Clicking the label should check its hidden radio ────────────────────── */
['dine_in','takeout','delivery'].forEach(t => {
    const lbl = document.getElementById('stLabel_' + t);
    if (!lbl) return;
    lbl.addEventListener('click', () => {
        const radio = lbl.querySelector('input[name="service_type_ui"]');
        if (radio) { radio.checked = true; applyServiceType(t); }
    });
});

/* ── Star picker ───────────────────────────────────────────────────────────── */
const starLabels = ['','1 star — Poor','2 stars — Fair','3 stars — Good','4 stars — Very Good','5 stars — Excellent'];
const starPicker  = document.getElementById('starPicker');
const ratingInput = document.getElementById('ratingInput');
const ratingLabel = document.getElementById('ratingLabel');

if (starPicker) {
    const stars = starPicker.querySelectorAll('.star-pick');
    function paintStars(upTo) {
        stars.forEach(s => {
            s.style.color = parseInt(s.dataset.val) <= upTo ? 'var(--accent-400)' : 'var(--neutral-200)';
        });
    }
    paintStars(5);
    stars.forEach(star => {
        star.addEventListener('mouseenter', () => paintStars(parseInt(star.dataset.val)));
        star.addEventListener('mouseleave', () => paintStars(parseInt(ratingInput.value)));
        star.addEventListener('click', () => {
            const v = parseInt(star.dataset.val);
            ratingInput.value = v;
            ratingLabel.textContent = starLabels[v] || '';
            paintStars(v);
        });
    });
}
document.querySelectorAll('input[name="payment_method_ui"]').forEach(radio => {
    radio.addEventListener('change', () => {
        ['cash','gcash','bank_transfer'].forEach(m => {
            const lbl = document.getElementById('pmLabel_' + m);
            if (lbl) lbl.style.borderColor = '#e2e8f0';
        });
        const lbl = document.getElementById('pmLabel_' + radio.value);
        if (lbl) lbl.style.borderColor = 'var(--primary-500)';
    });
    // Init highlight for checked
    if (radio.checked) {
        const lbl = document.getElementById('pmLabel_' + radio.value);
        if (lbl) lbl.style.borderColor = 'var(--primary-500)';
    }
});

/* ── Qty stepper wiring ────────────────────────────────────────────────────── */
document.querySelectorAll('.qty-btn').forEach(btn => {
    btn.addEventListener('click', () => {
        const id    = btn.dataset.id;
        const input = document.querySelector('.qty-input[data-id="' + id + '"]');
        if (!input) return;
        let v = parseInt(input.value, 10) || 1;
        if (btn.classList.contains('qty-inc')) v = Math.min(99, v + 1);
        else                                    v = Math.max(1, v - 1);
        input.value = v;
        // If item is in cart, update qty live
        if (cart.has(id)) {
            cart.get(id).qty = v;
            renderCart();
        }
    });
});

/* ── Add-to-cart button wiring ─────────────────────────────────────────────── */
document.querySelectorAll('.add-to-cart-btn').forEach(btn => {
    btn.addEventListener('click', () => {
        const id    = btn.dataset.id;
        const name  = btn.dataset.name;
        const price = parseFloat(btn.dataset.price);
        const qtyEl = document.querySelector('.qty-input[data-id="' + id + '"]');
        const qty   = qtyEl ? (parseInt(qtyEl.value, 10) || 1) : 1;

        if (cart.has(id)) {
            cart.get(id).qty += qty;
        } else {
            cart.set(id, { id, name, price, qty });
        }
        renderCart();

        // Brief visual feedback on button
        btn.innerHTML = '<i class="fas fa-check"></i> Added';
        btn.style.background = '#16a34a';
        setTimeout(() => {
            btn.innerHTML = '<i class="fas fa-plus"></i> Add';
            btn.style.background = '';
        }, 1000);
    });
});

/* ── Cart renderer ─────────────────────────────────────────────────────────── */
function renderCart() {
    cartListEl.innerHTML = '';
    let subtotal = 0, itemCount = 0;

    cart.forEach((item, id) => {
        if (item.qty <= 0) { cart.delete(id); return; }
        subtotal  += item.price * item.qty;
        itemCount += item.qty;

        const row = document.createElement('div');
        row.style.cssText = 'display:flex;align-items:center;gap:8px;padding:6px 0;border-bottom:1px solid var(--neutral-100);';
        row.innerHTML = `
            <div style="flex:1;min-width:0;">
                <div style="font-weight:600;font-size:0.85rem;white-space:nowrap;overflow:hidden;text-overflow:ellipsis;">${escHtml(item.name)}</div>
                <div style="font-size:0.78rem;color:var(--neutral-400);">₱${item.price.toFixed(2)} × ${item.qty} = <strong>₱${(item.price * item.qty).toFixed(2)}</strong></div>
            </div>
            <div style="display:flex;align-items:center;gap:4px;flex-shrink:0;">
                <button type="button" class="cart-dec" style="width:24px;height:24px;border:1px solid #e2e8f0;border-radius:6px;background:#fff;cursor:pointer;font-weight:700;font-size:0.9rem;line-height:1;" data-id="${escHtml(id)}">−</button>
                <span style="font-weight:700;min-width:20px;text-align:center;font-size:0.88rem;">${item.qty}</span>
                <button type="button" class="cart-inc" style="width:24px;height:24px;border:1px solid #e2e8f0;border-radius:6px;background:#fff;cursor:pointer;font-weight:700;font-size:0.9rem;line-height:1;" data-id="${escHtml(id)}">+</button>
                <button type="button" class="cart-del" style="width:24px;height:24px;border:none;background:transparent;cursor:pointer;color:#dc2626;font-size:0.8rem;" data-id="${escHtml(id)}"><i class="fas fa-times"></i></button>
            </div>`;
        cartListEl.appendChild(row);
    });

    cartListEl.querySelectorAll('.cart-dec').forEach(b => b.addEventListener('click', () => {
        const item = cart.get(b.dataset.id); if (!item) return;
        item.qty = Math.max(1, item.qty - 1); renderCart();
    }));
    cartListEl.querySelectorAll('.cart-inc').forEach(b => b.addEventListener('click', () => {
        const item = cart.get(b.dataset.id); if (!item) return;
        item.qty = Math.min(99, item.qty + 1); renderCart();
    }));
    cartListEl.querySelectorAll('.cart-del').forEach(b => b.addEventListener('click', () => {
        cart.delete(b.dataset.id); renderCart();
    }));

    // Calculate total including delivery fee when applicable
    const isDelivery  = getServiceType() === 'delivery';
    const fee         = isDelivery ? DELIVERY_FEE : 0;
    const grandTotal  = subtotal + fee;

    if (cartSubtotalEl) cartSubtotalEl.textContent = '₱' + subtotal.toFixed(2);
    if (deliveryFeeRow) deliveryFeeRow.style.display = isDelivery ? 'flex' : 'none';
    cartTotalEl.textContent = '₱' + grandTotal.toFixed(2);

    const hasItems = cart.size > 0;
    cartEmptyEl.style.display  = hasItems ? 'none'  : 'block';
    cartItemsEl.style.display  = hasItems ? 'block' : 'none';
    deliverySect.style.display = hasItems ? 'block' : 'none';
    cartBadgeEl.style.display  = hasItems ? '' : 'none';
    cartBadgeEl.textContent    = itemCount + (itemCount === 1 ? ' item' : ' items');
}

/* ── Address builder ─────────────────────────────────────────────────────── */
function buildDeliveryAddress() {
    return [
        addrStreet   ? addrStreet.value.trim()   : '',
        addrBarangay ? addrBarangay.value.trim()  : '',
        addrTown     ? addrTown.value.trim()      : '',
        addrCity     ? addrCity.value.trim()      : '',
        addrProvince ? addrProvince.value.trim()  : '',
    ].filter(Boolean).join(', ');
}

/* ── Place-order handler ─────────────────────────────────────────────────────── */
if (placeOrderBtn) {
    placeOrderBtn.addEventListener('click', async () => {
        if (cart.size === 0) { showToast('Your cart is empty.', true); return; }

        // ── Validate service type first ───────────────────────────────────────
        const serviceType = getServiceType();
        if (!serviceType) {
            if (serviceTypeErr) serviceTypeErr.style.display = 'block';
            document.getElementById('serviceTypeGroup')?.scrollIntoView({ behavior: 'smooth', block: 'nearest' });
            return;
        }
        if (serviceTypeErr) serviceTypeErr.style.display = 'none';

        // ── Validate address only for delivery ────────────────────────────────
        if (serviceType === 'delivery') {
            const street   = addrStreet   ? addrStreet.value.trim()   : '';
            const barangay = addrBarangay ? addrBarangay.value.trim()  : '';
            const city     = addrCity     ? addrCity.value.trim()      : '';
            const province = addrProvince ? addrProvince.value.trim()  : '';

            if (!street)   { showToast('Please enter your street / house number.', true); addrStreet.focus();   return; }
            if (!barangay) { showToast('Please enter your barangay.', true);               addrBarangay.focus(); return; }
            if (!city)     { showToast('Please enter your city.', true);                   addrCity.focus();     return; }
            if (!province) { showToast('Please enter your province.', true);               addrProvince.focus(); return; }
        }

        const paymentMethod = document.querySelector('input[name="payment_method_ui"]:checked')?.value ?? 'cash';
        const subtotal      = [...cart.values()].reduce((s, i) => s + i.price * i.qty, 0);
        const fee           = serviceType === 'delivery' ? DELIVERY_FEE : 0;
        const grandTotal    = subtotal + fee;
        const itemsArray    = [...cart.values()].map(i => ({ id: i.id, name: i.name, price: i.price, qty: i.qty }));

        placeOrderBtn.disabled = true;
        placeOrderBtn.innerHTML = '<i class="fas fa-spinner fa-spin"></i> Processing…';

        const fd = new FormData();
        fd.append('restaurant_id',    RESTAURANT_ID);
        fd.append('items_json',       JSON.stringify(itemsArray));
        fd.append('subtotal',         subtotal.toFixed(2));
        fd.append('delivery_fee',     fee.toFixed(2));
        fd.append('total_amount',     grandTotal.toFixed(2));
        fd.append('service_type',     serviceType);
        fd.append('payment_method',   paymentMethod);
        fd.append('notes',            orderNotesEl ? orderNotesEl.value.trim() : '');

        // Address fields — only populated for delivery
        if (serviceType === 'delivery') {
            fd.append('delivery_address', buildDeliveryAddress());
            fd.append('addr_street',      addrStreet     ? addrStreet.value.trim()     : '');
            fd.append('addr_barangay',    addrBarangay   ? addrBarangay.value.trim()   : '');
            fd.append('addr_town',        addrTown       ? addrTown.value.trim()       : '');
            fd.append('addr_city',        addrCity       ? addrCity.value.trim()       : '');
            fd.append('addr_province',    addrProvince   ? addrProvince.value.trim()   : '');
        } else {
            fd.append('delivery_address', '');
        }

        try {
            const res  = await fetch(AJAX_URL, { method: 'POST', body: fd });
            const data = await res.json();
            if (data.success) {
                if (data.redirect_url) {
                    window.location.href = data.redirect_url;
                } else {
                    showToast(data.message || 'Order placed!', false);
                    cart.clear(); renderCart();
                    document.querySelectorAll('input[name="service_type_ui"]').forEach(r => r.checked = false);
                    applyServiceType(null);
                    [addrStreet, addrBarangay, addrTown, addrCity, addrProvince].forEach(f => { if (f) f.value = ''; });
                    if (orderNotesEl) orderNotesEl.value = '';
                    placeOrderBtn.disabled = false;
                    placeOrderBtn.innerHTML = '<i class="fas fa-check-circle"></i> Place Order';
                }
            } else if (data.redirect_to_survey) {
                // Survey not yet completed — show modal, keep cart intact
                placeOrderBtn.disabled = false;
                placeOrderBtn.innerHTML = '<i class="fas fa-check-circle"></i> Place Order';
                showSurveyRequiredModal(data.survey_url);
            } else {
                showToast(data.message || 'Failed to place order.', true);
                placeOrderBtn.disabled = false;
                placeOrderBtn.innerHTML = '<i class="fas fa-check-circle"></i> Place Order';
            }
        } catch (err) {
            showToast('Network error. Please try again.', true);
            placeOrderBtn.disabled = false;
            placeOrderBtn.innerHTML = '<i class="fas fa-check-circle"></i> Place Order';
        }
    });
}

/* ── Survey required modal ──────────────────────────────────────────────────── */
function showSurveyRequiredModal(surveyUrl) {
    const existing = document.getElementById('_surveyModal');
    if (existing) existing.remove();

    // Build the return URL so survey redirects back here after completion
    const returnUrl = encodeURIComponent(window.location.href);
    const fullSurveyUrl = surveyUrl
        ? surveyUrl.replace(/return_url=[^&]*/, 'return_url=' + returnUrl)
        : '<?= BASE_URL ?>dashboard/customer/tpb_survey.php?return_url=' + returnUrl;

    const overlay = document.createElement('div');
    overlay.id = '_surveyModal';
    overlay.style.cssText = 'position:fixed;inset:0;background:rgba(0,0,0,.65);z-index:9999;display:flex;align-items:center;justify-content:center;padding:20px;';
    overlay.innerHTML = `
        <div style="background:#fff;border-radius:16px;max-width:440px;width:100%;box-shadow:0 24px 64px rgba(0,0,0,.25);overflow:hidden;">
            <div style="background:linear-gradient(135deg,#064e3b,#047857);padding:24px 28px 20px;">
                <div style="display:flex;align-items:center;gap:14px;">
                    <div style="width:48px;height:48px;background:rgba(255,255,255,.18);border-radius:12px;display:flex;align-items:center;justify-content:center;flex-shrink:0;">
                        <i class="fas fa-chart-line" style="color:#fff;font-size:1.3rem;"></i>
                    </div>
                    <div>
                        <h3 style="color:#fff;margin:0;font-size:1.05rem;font-weight:700;">Survey Required</h3>
                        <p style="color:rgba(255,255,255,.75);font-size:.8rem;margin:3px 0 0;">One quick step before ordering</p>
                    </div>
                </div>
            </div>
            <div style="padding:24px 28px;">
                <p style="color:#374151;font-size:.92rem;line-height:1.6;margin:0 0 16px;">
                    To help us understand halal food demand in Davao, we need you to complete a short <strong>Consumer Demand Survey</strong> (takes about 2 minutes) before placing your first order.
                </p>
                <div style="background:#f0fdf4;border-left:4px solid #16a34a;padding:10px 14px;border-radius:8px;font-size:.82rem;color:#166534;margin-bottom:22px;">
                    <i class="fas fa-info-circle" style="margin-right:6px;"></i>
                    <strong>Your cart is saved.</strong> You'll be brought back here automatically after completing the survey.
                </div>
                <div style="display:grid;grid-template-columns:1fr 1fr;gap:10px;">
                    <button onclick="document.getElementById('_surveyModal').remove()"
                            style="padding:11px;border:2px solid #e5e7eb;background:#fff;border-radius:10px;font-size:.88rem;font-weight:600;color:#6b7280;cursor:pointer;">
                        Maybe Later
                    </button>
                    <a href="${fullSurveyUrl}"
                       style="padding:11px;background:linear-gradient(135deg,#064e3b,#047857);color:#fff;border-radius:10px;font-size:.88rem;font-weight:700;text-align:center;text-decoration:none;display:block;">
                        <i class="fas fa-arrow-right" style="margin-right:6px;"></i>Take Survey
                    </a>
                </div>
            </div>
        </div>`;

    overlay.addEventListener('click', e => { if (e.target === overlay) overlay.remove(); });
    document.body.appendChild(overlay);
}

/* ── Toast helper ───────────────────────────────────────────────────────────── */
function showToast(msg, isError) {
    orderToastEl.textContent    = msg;
    orderToastEl.style.display  = 'block';
    orderToastEl.style.background = isError ? '#fef2f2' : '#f0fdf4';
    orderToastEl.style.border   = isError ? '1px solid #fecaca' : '1px solid #bbf7d0';
    orderToastEl.style.color    = isError ? '#991b1b' : '#166534';
    clearTimeout(orderToastEl._t);
    orderToastEl._t = setTimeout(() => { orderToastEl.style.display = 'none'; }, 6000);
}

/* ── XSS-safe escape ────────────────────────────────────────────────────────── */
function escHtml(str) {
    const d = document.createElement('div');
    d.textContent = String(str ?? '');
    return d.innerHTML;
}

/* ── Remove spinner on qty inputs (Chrome) ─────────────────────────────────── */
document.head.insertAdjacentHTML('beforeend',
    '<style>.qty-input::-webkit-inner-spin-button,.qty-input::-webkit-outer-spin-button{-webkit-appearance:none;margin:0}</style>');

})();
</script>
<?php endif; ?>

<?php require_once __DIR__ . '/../../includes/footer.php'; ?>
<script>
/* ── Restaurant card button delegation ──────────────────────────────────────
   Handles clicks on [data-action="order"] and [data-action="apply"] buttons
   via a single delegated listener on the card grid.                         */
document.addEventListener('click', function (e) {
    var btn = e.target.closest('[data-action]');
    if (!btn) return;

    var id     = btn.dataset.id;
    var action = btn.dataset.action;
    if (!id) return;

    // Let the native href handle navigation — this listener is here so
    // additional JS logic (analytics, cart reset, etc.) can be plugged in.
    if (action === 'order') {
        // href already points to ?action=view&id= — allow default navigation
        return;
    }
    if (action === 'apply') {
        // href already points to restaurant_view.php?id=&apply=1 — allow default
        return;
    }
});

/* ── Hover animation for card action buttons ──────────────────────────────── */
document.querySelectorAll('.card.restaurant-card').forEach(function (card) {
    card.addEventListener('mouseenter', function () {
        card.style.transform  = 'translateY(-4px)';
        card.style.boxShadow  = 'var(--shadow-lg)';
        card.style.transition = 'transform .2s ease, box-shadow .2s ease';
    });
    card.addEventListener('mouseleave', function () {
        card.style.transform = '';
        card.style.boxShadow = '';
    });
});
</script>
<?php
// ── Certificate Modal (only rendered in detail view when cert exists) ─────────
if ($action === 'view' && !empty($r['certificate_number'])):
?>
<div class="modal-overlay" id="certModal">
    <div class="modal" style="max-width:520px;">
        <div class="modal-header" style="background:linear-gradient(135deg,#f0fdf4,#dcfce7);border-bottom:2px solid #bbf7d0;">
            <div style="display:flex;align-items:center;gap:12px;">
                <div style="width:44px;height:44px;background:linear-gradient(135deg,#16a34a,#15803d);border-radius:10px;display:flex;align-items:center;justify-content:center;">
                    <i class="fas fa-certificate" style="color:#fff;font-size:1.2rem;"></i>
                </div>
                <div>
                    <h3 style="color:#166534;font-size:1rem;">Halal Certificate</h3>
                    <div style="font-size:0.78rem;color:#4ade80;">Verified &amp; Paid</div>
                </div>
            </div>
            <button class="modal-close"><i class="fas fa-times"></i></button>
        </div>
        <div class="modal-body">

            <!-- Certificate document preview -->
            <div style="border:3px solid #166534;border-radius:8px;padding:24px 28px;background:#fff;text-align:center;margin-bottom:16px;position:relative;">
                <div style="position:absolute;top:8px;left:8px;right:8px;bottom:8px;border:1px solid #d4af37;border-radius:4px;pointer-events:none;"></div>
                <div style="font-size:1.1rem;font-style:italic;color:#1a3a1a;margin-bottom:8px;">Certificate of Registration</div>
                <div style="display:inline-block;width:44px;height:44px;background:linear-gradient(135deg,#1a5c2a,#2d8a47);border-radius:6px;margin-bottom:6px;display:inline-flex;align-items:center;justify-content:center;">
                    <i class="fas fa-certificate" style="color:#fff;font-size:1.1rem;"></i>
                </div>
                <div style="font-weight:700;letter-spacing:.2em;font-size:0.75rem;color:#1a5c2a;margin-bottom:10px;">
                    <?= htmlspecialchars($r['certifying_body'] ?? 'HDIP') ?>
                </div>
                <div style="font-size:0.78rem;color:#555;margin-bottom:6px;font-style:italic;">This certificate of registration is awarded to</div>
                <div style="font-size:1.1rem;font-weight:700;color:#1a1a1a;margin-bottom:4px;"><?= htmlspecialchars($r['restaurant_name']) ?></div>
                <div style="font-size:0.8rem;color:#555;margin-bottom:12px;"><?= htmlspecialchars($r['address']) ?></div>
                <div style="font-size:0.78rem;color:#333;font-style:italic;margin-bottom:4px;">
                    certifies compliance with <strong>Philippine National Standard 2067:2008</strong>
                </div>
                <div style="font-size:0.78rem;color:#333;font-style:italic;margin-bottom:14px;">(Halal Food — General Guideline)</div>

                <div style="display:flex;justify-content:space-between;border-top:1px solid #e2e8f0;padding-top:12px;font-size:0.75rem;color:#333;text-align:left;">
                    <div>
                        <div><strong>Certificate No.:</strong><br>
                            <span style="font-family:monospace;color:#1a5c2a;font-weight:700;"><?= htmlspecialchars($r['certificate_number']) ?></span>
                        </div>
                    </div>
                    <div style="text-align:right;">
                        <div><strong>Issue Date:</strong> <?= $r['issue_date']  ? date('d M Y', strtotime($r['issue_date']))  : '—' ?></div>
                        <div><strong>Valid Until:</strong>
                            <span style="color:<?= $r['expiry_date'] && strtotime($r['expiry_date']) > time() ? '#16a34a' : '#dc2626' ?>;font-weight:700;">
                                <?= $r['expiry_date'] ? date('d M Y', strtotime($r['expiry_date'])) : '—' ?>
                            </span>
                        </div>
                    </div>
                </div>
            </div>

            <!-- Validity indicator -->
            <?php
            $is_valid = !empty($r['expiry_date']) && strtotime($r['expiry_date']) > time();
            ?>
            <div style="background:<?= $is_valid ? '#f0fdf4' : '#fef2f2' ?>;border:1px solid <?= $is_valid ? '#bbf7d0' : '#fecaca' ?>;border-radius:8px;padding:10px 14px;display:flex;align-items:center;gap:10px;font-size:0.85rem;color:<?= $is_valid ? '#166534' : '#991b1b' ?>;">
                <i class="fas fa-<?= $is_valid ? 'shield-alt' : 'exclamation-triangle' ?>"></i>
                <?= $is_valid
                    ? 'This Halal Certificate is <strong>valid and active</strong>.'
                    : 'This certificate has <strong>expired</strong>.' ?>
            </div>

            <div style="display:flex;justify-content:flex-end;margin-top:16px;">
                <a href="<?= BASE_URL ?>dashboard/decision_committee/cert_pdf.php?id=<?= $r['cert_id'] ?>&preview=1"
                   target="_blank" class="btn btn-success btn-sm">
                    <i class="fas fa-file-pdf"></i> View Full Certificate PDF
                </a>
                <button type="button" class="btn btn-outline btn-sm modal-close" style="margin-left:8px;">Close</button>
            </div>
        </div>
    </div>
</div>
<?php endif; ?>
