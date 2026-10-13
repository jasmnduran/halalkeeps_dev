<?php
/**
 * Credibility Scores API
 *
 * GET  ?action=single&id=5          — score for one restaurant (recomputes if stale)
 * GET  ?action=leaderboard&limit=10 — top N restaurants by score (public, no auth)
 * POST ?action=recompute&id=5       — force-recompute one restaurant (owner/admin only)
 * POST ?action=recompute_all        — batch-recompute all active restaurants (admin only)
 */
declare(strict_types=1);
require_once __DIR__ . '/../config/app.php';

header('Content-Type: application/json; charset=utf-8');

$action = $_GET['action'] ?? $_POST['action'] ?? 'single';

// ── Auth guards ───────────────────────────────────────────────────────────────
$protected_actions = ['recompute', 'recompute_all'];
if (in_array($action, $protected_actions, true)) {
    requireLogin();
    if ($action === 'recompute_all') {
        requireRole([ROLE_ADMIN]);
    }
}

// ── Helper ────────────────────────────────────────────────────────────────────
function json_resp(array $payload): never {
    echo json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    exit();
}

// ══════════════════════════════════════════════════════════════════════════════
// GET ?action=single&id=5
// Returns credibility score for one restaurant (uses cache, recomputes if stale)
// ══════════════════════════════════════════════════════════════════════════════
if ($action === 'single') {
    $rid = (int)($_GET['id'] ?? 0);
    if (!$rid) json_resp(['success' => false, 'message' => 'Missing restaurant id.']);

    $score = getCredibilityScore($conn, $rid);

    json_resp([
        'success'       => true,
        'restaurant_id' => $rid,
        'score'         => $score['total_score'],
        'tier'          => $score['tier'],
        'label'         => $score['label'],
        'color'         => $score['color'],
        'bg'            => $score['bg'],
        'border'        => $score['border'],
        'icon'          => $score['icon'],
        'stars'         => $score['stars'],
        'components'    => $score['components'],
        'raw'           => $score['raw'],
    ]);
}

// ══════════════════════════════════════════════════════════════════════════════
// GET ?action=leaderboard&limit=10
// Returns top N restaurants ordered by credibility_score DESC (public endpoint)
// ══════════════════════════════════════════════════════════════════════════════
if ($action === 'leaderboard') {
    $limit = min(50, max(1, (int)($_GET['limit'] ?? 10)));

    ensureCredibilitySchema($conn);

    $rows = $conn->query("
        SELECT
            hr.id,
            hr.restaurant_name,
            hr.address,
            hr.city,
            hr.image_path,
            hr.avg_rating,
            hr.total_reviews,
            hr.credibility_score,
            hr.credibility_tier,
            hr.credibility_updated_at,
            (SELECT ba.halal_classification
             FROM business_applications ba
             WHERE ba.user_id = hr.business_owner_id AND ba.status = 'approved'
             ORDER BY ba.created_at DESC LIMIT 1) AS halal_classification
        FROM halal_restaurants hr
        WHERE hr.is_active = 1
          AND hr.credibility_score IS NOT NULL
        ORDER BY hr.credibility_score DESC
        LIMIT {$limit}
    ")?->fetch_all(MYSQLI_ASSOC) ?? [];

    $result = array_map(function(array $r): array {
        $tier = getCredibilityTier((float)($r['credibility_score'] ?? 0));
        return [
            'id'                  => (int)$r['id'],
            'restaurant_name'     => $r['restaurant_name'],
            'address'             => $r['address'],
            'city'                => $r['city'],
            'image_path'          => $r['image_path'],
            'avg_rating'          => (float)$r['avg_rating'],
            'total_reviews'       => (int)$r['total_reviews'],
            'credibility_score'   => (float)$r['credibility_score'],
            'credibility_tier'    => (int)$r['credibility_tier'],
            'tier_label'          => $tier['label'],
            'tier_color'          => $tier['color'],
            'tier_bg'             => $tier['bg'],
            'tier_icon'           => $tier['icon'],
            'halal_classification'=> $r['halal_classification'],
            'updated_at'          => $r['credibility_updated_at'],
        ];
    }, $rows);

    json_resp([
        'success' => true,
        'count'   => count($result),
        'data'    => $result,
    ]);
}

// ══════════════════════════════════════════════════════════════════════════════
// POST ?action=recompute&id=5
// Force-recompute one restaurant (requires login; owner can only recompute own)
// ══════════════════════════════════════════════════════════════════════════════
if ($action === 'recompute') {
    $rid = (int)($_POST['id'] ?? $_GET['id'] ?? 0);
    if (!$rid) json_resp(['success' => false, 'message' => 'Missing restaurant id.']);

    // If owner role, verify they own this restaurant
    if (isset($_SESSION['role_id']) && (int)$_SESSION['role_id'] === ROLE_BUSINESS_OWNER) {
        $own = $conn->prepare(
            "SELECT id FROM halal_restaurants WHERE id = ? AND business_owner_id = ? LIMIT 1"
        );
        $uid = (int)$_SESSION['user_id'];
        $own->bind_param("ii", $rid, $uid);
        $own->execute();
        if (!$own->get_result()->fetch_assoc()) {
            json_resp(['success' => false, 'message' => 'Access denied.']);
        }
        $own->close();
    }

    $score = computeCredibilityScore($conn, $rid, persist: true);

    json_resp([
        'success'    => true,
        'message'    => 'Score recomputed.',
        'score'      => $score['total_score'],
        'tier'       => $score['tier'],
        'label'      => $score['label'],
        'components' => $score['components'],
        'raw'        => $score['raw'],
    ]);
}

// ══════════════════════════════════════════════════════════════════════════════
// POST ?action=recompute_all   (admin only)
// Batch-recompute all active restaurants; streams progress as JSON lines
// ══════════════════════════════════════════════════════════════════════════════
if ($action === 'recompute_all') {
    $ids = $conn->query(
        "SELECT id FROM halal_restaurants WHERE is_active = 1 ORDER BY id ASC"
    )?->fetch_all(MYSQLI_ASSOC) ?? [];

    $results  = [];
    $updated  = 0;
    $errors   = 0;

    foreach ($ids as $row) {
        $rid = (int)$row['id'];
        try {
            $score = computeCredibilityScore($conn, $rid, persist: true);
            $results[] = [
                'id'    => $rid,
                'score' => $score['total_score'],
                'tier'  => $score['tier'],
            ];
            $updated++;
        } catch (Throwable $e) {
            $results[] = ['id' => $rid, 'error' => $e->getMessage()];
            $errors++;
        }
    }

    json_resp([
        'success'  => true,
        'total'    => count($ids),
        'updated'  => $updated,
        'errors'   => $errors,
        'results'  => $results,
    ]);
}

json_resp(['success' => false, 'message' => "Unknown action: {$action}"]);
