<?php
/**
 * Halal Directory Metrics API
 * GET  ?city=Davao+City          — full metrics + business list for one city
 * GET  ?region=XI                — aggregate metrics for all cities in a region
 * GET  ?action=region_xi_summary — compact summary for map tooltips
 *
 * Returns JSON.  No auth required (public data).
 */
declare(strict_types=1);
require_once __DIR__ . '/../config/app.php';

header('Content-Type: application/json; charset=utf-8');
header('Access-Control-Allow-Origin: *');

// ── Idempotent table guard ───────────────────────────────────────────────────
// Ensure tables exist even if the seed SQL hasn't been run yet.
$conn->query("
    CREATE TABLE IF NOT EXISTS `halal_directory_businesses` (
        `id`             INT UNSIGNED  NOT NULL AUTO_INCREMENT,
        `business_name`  VARCHAR(255)  NOT NULL,
        `category`       ENUM('halal_certified','muslim_friendly','non_pork') NOT NULL,
        `address`        VARCHAR(500)  DEFAULT NULL,
        `city`           VARCHAR(100)  NOT NULL DEFAULT 'Davao City',
        `province`       VARCHAR(100)  NOT NULL DEFAULT 'Davao del Sur',
        `region_code`    VARCHAR(10)   NOT NULL DEFAULT 'XI',
        `is_halal_keeps` TINYINT(1)    NOT NULL DEFAULT 0,
        `halal_keeps_id` INT(11)       DEFAULT NULL,
        `is_active`      TINYINT(1)    NOT NULL DEFAULT 1,
        `created_at`     TIMESTAMP     NOT NULL DEFAULT CURRENT_TIMESTAMP,
        PRIMARY KEY (`id`),
        KEY `idx_hdb_city`     (`city`),
        KEY `idx_hdb_region`   (`region_code`),
        KEY `idx_hdb_category` (`category`)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
");
$conn->query("
    CREATE TABLE IF NOT EXISTS `halal_directory_city_metrics` (
        `id`              INT UNSIGNED NOT NULL AUTO_INCREMENT,
        `city`            VARCHAR(100) NOT NULL,
        `province`        VARCHAR(100) NOT NULL,
        `region_code`     VARCHAR(10)  NOT NULL,
        `total_food_biz`  INT UNSIGNED NOT NULL DEFAULT 0,
        `halal_certified` INT UNSIGNED NOT NULL DEFAULT 0,
        `muslim_friendly` INT UNSIGNED NOT NULL DEFAULT 0,
        `non_pork`        INT UNSIGNED NOT NULL DEFAULT 0,
        `is_live`         TINYINT(1)   NOT NULL DEFAULT 0,
        `updated_at`      TIMESTAMP    NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
        PRIMARY KEY (`id`),
        UNIQUE KEY `uq_city_province` (`city`, `province`)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
");

// ── Helper: safe JSON output ─────────────────────────────────────────────────
function json_out(array $payload): never {
    echo json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT);
    exit();
}

// ── Helper: calculate derived metrics ───────────────────────────────────────
function compute_metrics(int $halal, int $friendly, int $non_pork, int $total_food): array {
    $compliant = $halal + $friendly + $non_pork;

    // Compliance Index = Total Halal-Compliant Businesses / Total Registered Food Businesses × 100
    $compliance_index = $total_food > 0
        ? round(($compliant / $total_food) * 100, 2)
        : 0.0;

    // Halal-Certified Score = Halal-Certified Restaurants / Compliant Restaurants × 100
    $halal_certified_score = $compliant > 0
        ? round(($halal / $compliant) * 100, 2)
        : 0.0;

    // Muslim-Friendly Score = Muslim-Friendly Restaurants / Compliant Businesses × 100
    $muslim_friendly_score = $compliant > 0
        ? round(($friendly / $compliant) * 100, 2)
        : 0.0;

    // Non-Pork Score = Non-Pork Restaurants / Compliant Businesses × 100
    $non_pork_score = $compliant > 0
        ? round(($non_pork / $compliant) * 100, 2)
        : 0.0;

    return [
        'total_compliant'       => $compliant,
        'compliance_index'      => $compliance_index,       // Compliant ÷ Total Food × 100
        'halal_certified_score' => $halal_certified_score,  // Halal ÷ Compliant × 100
        'muslim_friendly_score' => $muslim_friendly_score,  // Muslim-Friendly ÷ Compliant × 100
        'non_pork_score'        => $non_pork_score,         // Non-Pork ÷ Compliant × 100
    ];
}

$action = $_GET['action'] ?? '';
$city   = trim($_GET['city']   ?? '');
$region = trim($_GET['region'] ?? '');

// ============================================================================
// ACTION: city detail
// GET ?city=Davao+City
// ============================================================================
if ($city !== '') {
    $city_esc = $conn->real_escape_string($city);

    // Metrics row
    $mrow = $conn->query(
        "SELECT * FROM halal_directory_city_metrics WHERE city = '$city_esc' LIMIT 1"
    )?->fetch_assoc();

    if (!$mrow) {
        json_out(['success' => false, 'message' => "No metrics found for city: $city"]);
    }

    $metrics = compute_metrics(
        (int)$mrow['halal_certified'],
        (int)$mrow['muslim_friendly'],
        (int)$mrow['non_pork'],
        (int)$mrow['total_food_biz']
    );

    // Business listings
    $brows = $conn->query(
        "SELECT id, business_name, category, address, is_halal_keeps
         FROM halal_directory_businesses
         WHERE city = '$city_esc' AND is_active = 1
         ORDER BY is_halal_keeps DESC, category ASC, business_name ASC"
    )?->fetch_all(MYSQLI_ASSOC) ?? [];

    // Group by category
    $businesses = ['halal_certified' => [], 'muslim_friendly' => [], 'non_pork' => []];
    foreach ($brows as $b) {
        $businesses[$b['category']][] = [
            'id'            => (int)$b['id'],
            'name'          => $b['business_name'],
            'address'       => $b['address'],
            'is_halal_keeps'=> (bool)$b['is_halal_keeps'],
        ];
    }

    json_out([
        'success'    => true,
        'city'       => $mrow['city'],
        'province'   => $mrow['province'],
        'region'     => $mrow['region_code'],
        'is_live'    => (bool)$mrow['is_live'],
        'raw_counts' => [
            'halal_certified' => (int)$mrow['halal_certified'],
            'muslim_friendly' => (int)$mrow['muslim_friendly'],
            'non_pork'        => (int)$mrow['non_pork'],
            'total_food_biz'  => (int)$mrow['total_food_biz'],
        ],
        'metrics'    => $metrics,
        'businesses' => $businesses,
    ]);
}

// ============================================================================
// ACTION: region summary (all cities)
// GET ?region=XI  or  ?action=region_xi_summary
// ============================================================================
if ($region !== '' || $action === 'region_xi_summary') {
    $rc = $region !== '' ? $conn->real_escape_string($region) : 'XI';

    $rows = $conn->query(
        "SELECT * FROM halal_directory_city_metrics
         WHERE region_code = '$rc'
         ORDER BY is_live DESC, halal_certified DESC"
    )?->fetch_all(MYSQLI_ASSOC) ?? [];

    $cities = [];
    $totals = ['halal_certified' => 0, 'muslim_friendly' => 0, 'non_pork' => 0, 'total_food_biz' => 0];

    foreach ($rows as $r) {
        $m = compute_metrics(
            (int)$r['halal_certified'],
            (int)$r['muslim_friendly'],
            (int)$r['non_pork'],
            (int)$r['total_food_biz']
        );
        $cities[] = [
            'city'            => $r['city'],
            'province'        => $r['province'],
            'is_live'         => (bool)$r['is_live'],
            'halal_certified' => (int)$r['halal_certified'],
            'muslim_friendly' => (int)$r['muslim_friendly'],
            'non_pork'        => (int)$r['non_pork'],
            'total_food_biz'  => (int)$r['total_food_biz'],
            'compliance_index'=> $m['compliance_index'],
            'market_penetration' => $m['market_penetration'],
        ];
        foreach ($totals as $k => $_) {
            $totals[$k] += (int)$r[$k];
        }
    }

    $region_metrics = compute_metrics(
        $totals['halal_certified'],
        $totals['muslim_friendly'],
        $totals['non_pork'],
        $totals['total_food_biz']
    );

    json_out([
        'success'        => true,
        'region'         => $rc,
        'cities'         => $cities,
        'region_totals'  => $totals,
        'region_metrics' => $region_metrics,
    ]);
}

// ============================================================================
// Default: return Davao City metrics (homepage default)
// ============================================================================
$_GET['city'] = 'Davao City';
$city = 'Davao City';

$city_esc = 'Davao City';
$mrow = $conn->query(
    "SELECT * FROM halal_directory_city_metrics WHERE city = 'Davao City' LIMIT 1"
)?->fetch_assoc();

if (!$mrow) {
    json_out([
        'success' => false,
        'message' => 'Davao City metrics not yet seeded. Run database_migrations/halal_directory_seed.sql first.',
    ]);
}

$metrics = compute_metrics(
    (int)$mrow['halal_certified'],
    (int)$mrow['muslim_friendly'],
    (int)$mrow['non_pork'],
    (int)$mrow['total_food_biz']
);

$brows = $conn->query(
    "SELECT id, business_name, category, address, is_halal_keeps
     FROM halal_directory_businesses
     WHERE city = 'Davao City' AND is_active = 1
     ORDER BY is_halal_keeps DESC, category ASC, business_name ASC"
)?->fetch_all(MYSQLI_ASSOC) ?? [];

$businesses = ['halal_certified' => [], 'muslim_friendly' => [], 'non_pork' => []];
foreach ($brows as $b) {
    $businesses[$b['category']][] = [
        'id'             => (int)$b['id'],
        'name'           => $b['business_name'],
        'address'        => $b['address'],
        'is_halal_keeps' => (bool)$b['is_halal_keeps'],
    ];
}

json_out([
    'success'    => true,
    'city'       => 'Davao City',
    'province'   => 'Davao del Sur',
    'region'     => 'XI',
    'is_live'    => true,
    'raw_counts' => [
        'halal_certified' => (int)$mrow['halal_certified'],
        'muslim_friendly' => (int)$mrow['muslim_friendly'],
        'non_pork'        => (int)$mrow['non_pork'],
        'total_food_biz'  => (int)$mrow['total_food_biz'],
    ],
    'metrics'    => $metrics,
    'businesses' => $businesses,
]);
