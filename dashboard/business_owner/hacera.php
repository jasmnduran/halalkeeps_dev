<?php
declare(strict_types=1);
/**
 * HaCeRa — Halal Readiness Assessment System (wizard/step-by-step flow)
 * Access: ROLE_BUSINESS_OWNER only (actual owner, not staff sub-roles)
 */

require_once __DIR__ . '/../../config/app.php';
require_once __DIR__ . '/../../includes/HaCeRaCalculator.php';
require_once __DIR__ . '/../../includes/HaCeRaAIService.php';
require_once __DIR__ . '/../../includes/RevenuePaymentService.php';

requireLogin();
requireRole([ROLE_BUSINESS_OWNER]);


$page_title   = 'HaCeRa — Halal Readiness Assessment';
$page_heading = 'Halal Readiness Assessment (HaCeRa)';
$is_dashboard = true;
$user_id      = (int)$_SESSION['user_id'];
$revenue_payment_csrf = RevenuePaymentService::ensureCsrfToken();
$breadcrumbs  = [
    ['label' => 'Owner Dashboard', 'url' => BASE_URL . 'dashboard/business_owner/owner_dashboard.php'],
    ['label' => 'HaCeRa Assessment'],
];

db_add_column($conn, 'halal_restaurants', 'hacera_hrp',          'DECIMAL(5,1) DEFAULT NULL');
db_add_column($conn, 'halal_restaurants', 'hacera_band',         'VARCHAR(30)  DEFAULT NULL');
db_add_column($conn, 'halal_restaurants', 'hacera_published_at', 'DATETIME     DEFAULT NULL');
db_add_column($conn, 'halal_restaurants', 'hacera_completed_at', 'DATETIME     DEFAULT NULL');

$rest_stmt = $conn->prepare(
    "SELECT id, restaurant_name, address, hacera_hrp, hacera_band, hacera_published_at
     FROM halal_restaurants WHERE business_owner_id = ? AND is_active = 1 LIMIT 1"
);
$rest_stmt->bind_param('i', $user_id);
$rest_stmt->execute();
$restaurant = $rest_stmt->get_result()->fetch_assoc();
$rest_stmt->close();

if (!$restaurant) {
    header('Location: ' . BASE_URL . 'dashboard/business_owner/restaurant.php');
    exit();
}
$completionStmt = $conn->prepare('SELECT hacera_completed_at FROM halal_restaurants WHERE id = ? LIMIT 1');
$completionRestaurantId = (int)$restaurant['id'];
$completionStmt->bind_param('i', $completionRestaurantId);
$completionStmt->execute();
$completionRow = $completionStmt->get_result()->fetch_assoc();
$completionStmt->close();
$restaurant['hacera_completed_at'] = $completionRow['hacera_completed_at'] ?? null;
$rest_id = (int)$restaurant['id'];
$paymentReturn = (string)($_GET['payment'] ?? '');
if (!in_array($paymentReturn, ['return', 'cancelled', 'verified'], true)) $paymentReturn = '';

// AI Insights is deliberately disabled unless explicitly enabled in .env.
// Its token is scoped to the owner session and the separate advisory API.
$hacera_ai_enabled = HaCeRaAIService::isFeatureEnabled();
if ($hacera_ai_enabled && empty($_SESSION['hacera_ai_csrf_token'])) {
    $_SESSION['hacera_ai_csrf_token'] = bin2hex(random_bytes(32));
}
$hacera_ai_csrf_token = (string)($_SESSION['hacera_ai_csrf_token'] ?? '');

$conn->query("CREATE TABLE IF NOT EXISTS `business_hacera_responses` (
    `id`                INT UNSIGNED      NOT NULL AUTO_INCREMENT,
    `restaurant_id`     INT(11)           NOT NULL,
    `checklist_item_id` SMALLINT UNSIGNED NOT NULL,
    `response_value`    ENUM('yes','no','na') NOT NULL DEFAULT 'no',
    `notes`             TEXT              DEFAULT NULL,
    `responded_by`      INT(11)           NOT NULL,
    `created_at`        TIMESTAMP         NOT NULL DEFAULT CURRENT_TIMESTAMP,
    `updated_at`        TIMESTAMP         NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (`id`),
    UNIQUE KEY `uq_bhr_rest_item` (`restaurant_id`, `checklist_item_id`),
    KEY `idx_bhr_rest` (`restaurant_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");

$conn->query("CREATE TABLE IF NOT EXISTS `hacera_documents` (
    `id`                INT UNSIGNED      NOT NULL AUTO_INCREMENT,
    `restaurant_id`     INT(11)           NOT NULL,
    `checklist_item_id` SMALLINT UNSIGNED NOT NULL,
    `file_path`         VARCHAR(500)      NOT NULL,
    `original_name`     VARCHAR(255)      NOT NULL DEFAULT '',
    `uploaded_by`       INT(11)           NOT NULL,
    `uploaded_at`       TIMESTAMP         NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (`id`),
    KEY `idx_hd_rest`      (`restaurant_id`),
    KEY `idx_hd_item`      (`checklist_item_id`),
    KEY `idx_hd_rest_item` (`restaurant_id`, `checklist_item_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");

$resp_res  = $conn->query("SELECT checklist_item_id, response_value, notes FROM business_hacera_responses WHERE restaurant_id = $rest_id");
$responses = $resp_res ? $resp_res->fetch_all(MYSQLI_ASSOC) : [];
$resp_map  = [];
foreach ($responses as $r) {
    $resp_map[(int)$r['checklist_item_id']] = $r;
}

$doc_res = $conn->query("SELECT id, checklist_item_id, file_path, original_name, uploaded_at FROM hacera_documents WHERE restaurant_id = $rest_id ORDER BY uploaded_at DESC");
$doc_map = [];
$doc_ids = [];
if ($doc_res) {
    while ($d = $doc_res->fetch_assoc()) {
        $cid = (int)$d['checklist_item_id'];
        $doc_map[$cid][] = $d;
        $doc_ids[] = $cid;
    }
}
$doc_ids = array_unique($doc_ids);
$premiumBilling = new RevenuePaymentService($conn);
$paymentReconciliation = $paymentReturn === 'return' ? $premiumBilling->reconcileHaceraPremiumPayment($user_id, $rest_id) : null;
$premiumAccess = $premiumBilling->getHaceraPremiumAccess($user_id, $rest_id);
$premiumUnlocked = $premiumAccess !== null;
$premiumPurchaseId = $premiumUnlocked ? (int)$premiumAccess['id'] : 0;
$premiumPrice = $premiumBilling->billingSettings()['hacera_fee'];

$completed_ids = HaCeRaCalculator::buildCompletedIds($responses, $doc_ids);
$modules       = HaCeRaCalculator::getModules();
$module_scores = [];
foreach (array_keys($modules) as $mid) {
    $module_scores[$mid] = HaCeRaCalculator::scoreModule($mid, $completed_ids);
}
$hrp      = HaCeRaCalculator::computeHRP($module_scores);
$hrp_band = HaCeRaCalculator::getBand($hrp);
$all_gaps = [];
foreach ($module_scores as $mid => $ms) {
    foreach ($ms['gaps'] as $g) {
        $all_gaps[] = $g + ['module_id' => $mid, 'module_title' => $modules[$mid]['title']];
    }
}
usort($all_gaps, fn($a, $b) => $b['weight'] <=> $a['weight']);

$HACERA_API   = 'api/hacera_api.php';
$total_steps  = count($modules) + 1; // 5 modules + 1 results step
$assessment_complete = !empty($restaurant['hacera_completed_at']) || !empty($restaurant['hacera_published_at']);
$initial_step = $assessment_complete ? $total_steps : 1;

require_once __DIR__ . '/../../includes/header.php';
?>

<style>
/* ── Wizard stepper ──────────────────────────────────────────────────────── */
.hc-stepper { display:flex; align-items:flex-start; justify-content:center; gap:0; margin-bottom:28px; }
.hc-step-item { display:flex; flex-direction:column; align-items:center; flex:1; max-width:120px; }
.hc-step-line { flex:1; height:2px; background:var(--neutral-200); margin-top:15px; transition:background .3s; }
.hc-step-line.done { background:var(--primary-600); }
.hc-step-circle {
    width:32px; height:32px; border-radius:50%; border:2px solid var(--neutral-300);
    background:#fff; color:var(--neutral-400); display:flex; align-items:center;
    justify-content:center; font-weight:800; font-size:.8rem; transition:all .25s; flex-shrink:0;
}
.hc-step-circle.active { border-color:var(--primary-600); background:var(--primary-600); color:#fff; }
.hc-step-circle.done   { border-color:var(--primary-600); background:var(--primary-600); color:#fff; }
.hc-step-label {
    font-size:.62rem; color:var(--neutral-400); margin-top:5px; font-weight:600;
    text-align:center; white-space:nowrap; transition:color .25s;
}
.hc-step-label.active { color:var(--primary-600); }
.hc-step-label.done   { color:var(--primary-600); }

/* ── Panels ──────────────────────────────────────────────────────────────── */
.hc-panel { display:none; animation:hcSlideIn .28s cubic-bezier(.4,0,.2,1) both; }
.hc-panel.active { display:block; }
.hc-panel.slide-left { animation:hcSlideInLeft .28s cubic-bezier(.4,0,.2,1) both; }
@keyframes hcSlideIn     { from{opacity:0;transform:translateX(24px)} to{opacity:1;transform:none} }
@keyframes hcSlideInLeft { from{opacity:0;transform:translateX(-24px)} to{opacity:1;transform:none} }

/* ── Module header card ──────────────────────────────────────────────────── */
.hc-mod-header {
    border-radius:14px 14px 0 0; padding:20px 24px 16px;
    display:flex; align-items:center; gap:14px;
}
.hc-mod-icon { width:44px; height:44px; border-radius:12px; display:flex; align-items:center; justify-content:center; font-size:1.2rem; flex-shrink:0; }

/* ── Checklist item rows ─────────────────────────────────────────────────── */
/* Full-width card layout: question + meta on top, scale spanning full width below */
.hc-item-row { display:flex; flex-direction:column; padding:20px 0; border-bottom:1px solid var(--neutral-100); gap:0; }
.hc-item-row:last-child { border-bottom:none; }
/* Top row: status dot + question text */
.hc-item-top { display:flex; align-items:flex-start; gap:10px; margin-bottom:16px; }
.hc-item-check { width:22px; height:22px; border-radius:6px; flex-shrink:0; display:flex; align-items:center; justify-content:center; font-size:.75rem; margin-top:1px; }
.hc-item-check.done { background:#dcfce7; color:#16a34a; }
.hc-item-check.gap  { background:#fee2e2; color:#dc2626; }
.hc-item-check.na   { background:#f1f5f9; color:#94a3b8; }
.hc-item-content { flex:1; min-width:0; }
.hc-item-label { font-size:.92rem; font-weight:600; color:var(--neutral-800); line-height:1.4; }
.hc-item-meta  { font-size:.74rem; color:var(--neutral-500); margin-top:4px; display:flex; flex-wrap:wrap; gap:6px; align-items:center; }

/* ── Weight badges ───────────────────────────────────────────────────────── */
.w-badge { display:inline-flex; align-items:center; gap:3px; padding:1px 7px; border-radius:999px; font-size:.68rem; font-weight:700; }
.w-high  { background:#fef3c7; color:#92400e; }
.w-std   { background:#eff6ff; color:#1e40af; }
.w-info  { background:#f1f5f9; color:#64748b; }

/* ── 16Personalities-style Likert scale — full width ────────────────────── */
.yn-scale-wrap {
    display: flex;
    flex-direction: column;
    width: 100%;          /* fills the full item row width */
    padding: 0 4px;
    box-sizing: border-box;
}
.yn-toggle {
    display: flex;
    align-items: center;
    width: 100%;
    gap: 0;
}

/* Connecting lines grow to fill space between circles */
.yn-connector {
    flex: 1;
    height: 3px;
    background: #cbd5e1;
    transition: background .25s;
}

/* Individual circle node */
.yn-circle {
    width: 52px;
    height: 52px;
    border-radius: 50%;
    border: 3px solid #94a3b8;
    background: #f8fafc;
    cursor: pointer;
    transition: transform .18s cubic-bezier(.4,0,.2,1),
                border-color .18s, background .18s,
                box-shadow .18s;
    display: flex;
    align-items: center;
    justify-content: center;
    flex-shrink: 0;
    position: relative;
    box-shadow: 0 1px 3px rgba(0,0,0,.08);
}
.yn-circle:hover {
    transform: scale(1.12);
    border-color: #64748b;
    background: #f1f5f9;
    box-shadow: 0 0 0 6px rgba(100,116,139,.12), 0 2px 6px rgba(0,0,0,.1);
}
.yn-circle.yn-yes.active {
    background: #16a34a; border-color: #15803d;
    box-shadow: 0 0 0 6px rgba(22,163,74,.2), 0 3px 10px rgba(22,163,74,.3);
    transform: scale(1.12);
}
.yn-circle.yn-na.active {
    background: #475569; border-color: #334155;
    box-shadow: 0 0 0 6px rgba(71,85,105,.2), 0 3px 10px rgba(71,85,105,.25);
    transform: scale(1.08);
}
.yn-circle.yn-no.active {
    background: #7c3aed; border-color: #6d28d9;
    box-shadow: 0 0 0 6px rgba(124,58,237,.2), 0 3px 10px rgba(124,58,237,.25);
    transform: scale(1.12);
}
.yn-circle.active::after {
    content: ''; width: 16px; height: 16px;
    border-radius: 50%; background: #fff; display: block;
}
/* Labels: Yes flush-left, N/A centered, No flush-right */
.yn-labels {
    display: flex;
    justify-content: space-between;
    padding: 8px 0 0;
}
.yn-label-yes { font-size: .82rem; font-weight: 800; color: #15803d; letter-spacing: .01em; }
.yn-label-na  { font-size: .82rem; font-weight: 700; color: #475569; letter-spacing: .01em; }
.yn-label-no  { font-size: .82rem; font-weight: 800; color: #6d28d9; letter-spacing: .01em; }

/* ── Upload zone ─────────────────────────────────────────────────────────── */
.hc-upload-zone { border:2px dashed var(--neutral-300); border-radius:8px; padding:10px 14px; margin-top:8px; display:flex; align-items:center; gap:10px; cursor:pointer; background:#fafafa; transition:border-color .15s,background .15s; }
.hc-upload-zone:hover { border-color:var(--primary-400); background:#f0f9ff; }
.hc-upload-zone input { display:none; }
.hc-doc-list  { margin-top:6px; display:flex; flex-direction:column; gap:4px; }
.hc-doc-item  { display:flex; align-items:center; gap:8px; font-size:.76rem; background:#f8fafc; border-radius:6px; padding:5px 10px; border:1px solid var(--neutral-100); }
.hc-doc-item a { color:var(--primary-700); font-weight:600; flex:1; overflow:hidden; text-overflow:ellipsis; white-space:nowrap; }

/* ── Nav buttons ─────────────────────────────────────────────────────────── */
.hc-nav { display:flex; align-items:center; justify-content:space-between; margin-top:24px; padding-top:20px; border-top:1px solid var(--neutral-100); gap:12px; }

/* ── Progress bar ────────────────────────────────────────────────────────── */
.hc-bar-track { height:8px; border-radius:999px; background:var(--neutral-100); overflow:hidden; }
.hc-bar-fill  { height:100%; border-radius:999px; transition:width .7s cubic-bezier(.4,0,.2,1); }

/* ── Gauge ───────────────────────────────────────────────────────────────── */
.hrp-gauge-wrap { display:flex; align-items:center; justify-content:center; flex-direction:column; }

/* ── Gap analysis ────────────────────────────────────────────────────────── */
.gap-row { display:flex; align-items:flex-start; gap:10px; padding:9px 12px; border-radius:8px; margin-bottom:6px; background:#fff; border:1px solid #fca5a5; }
.gap-row.w3 { border-color:#fca5a5; background:#fff5f5; }
.gap-row.w2 { border-color:#fed7aa; background:#fffbf0; }
.gap-row.w1 { border-color:#e2e8f0; background:#fafafa; }

/* ── Toast ───────────────────────────────────────────────────────────────── */
#hcToast { position:fixed; bottom:28px; right:28px; z-index:9999; background:#1e293b; color:#fff; padding:12px 20px; border-radius:10px; font-size:.85rem; font-weight:600; box-shadow:0 8px 24px rgba(0,0,0,.2); display:none; align-items:center; gap:10px; max-width:340px; animation:slideUp .25s ease; }
#hcToast.error { background:#991b1b; }
@keyframes slideUp { from{transform:translateY(16px);opacity:0} to{transform:translateY(0);opacity:1} }

/* ── Spinner ─────────────────────────────────────────────────────────────── */
.hc-spinner { width:16px; height:16px; border:2px solid rgba(255,255,255,.3); border-top-color:#fff; border-radius:50%; animation:spin .7s linear infinite; display:inline-block; }
@keyframes spin { to{transform:rotate(360deg)} }

/* ── Results grid ────────────────────────────────────────────────────────── */
.results-grid { display:grid; grid-template-columns:260px 1fr; gap:24px; align-items:start; }
@media(max-width:720px) { .results-grid { grid-template-columns:1fr; } .hc-stepper { gap:0; } }

/* AI advisory cards deliberately reuse the existing neutral/primary palette. */
.ai-insights-grid { display:grid; grid-template-columns:repeat(2,minmax(0,1fr)); gap:14px; }
.ai-insight-card { border:1px solid var(--neutral-200); border-radius:12px; padding:16px; background:#fff; }
.ai-finding { border-left:4px solid var(--primary-500); border-radius:8px; background:var(--neutral-50); padding:12px; margin-top:9px; }
.ai-priority { display:inline-flex; border-radius:999px; padding:2px 8px; font-size:.67rem; font-weight:800; text-transform:uppercase; }
.ai-priority.critical,.ai-priority.high { background:#fee2e2; color:#b91c1c; }
.ai-priority.medium { background:#fef3c7; color:#92400e; }
.ai-priority.low { background:#e0f2fe; color:#075985; }
.ai-history-row { display:flex; gap:10px; justify-content:space-between; align-items:flex-start; padding:10px 0; border-bottom:1px solid var(--neutral-100); }
@media(max-width:720px) { .ai-insights-grid { grid-template-columns:1fr; } }
</style>

<!-- ══ Header banner ═══════════════════════════════════════════════════════ -->
<div style="background:linear-gradient(135deg,#064e3b 0%,#065f46 60%,#047857 100%);border-radius:16px;padding:18px 24px;margin-bottom:24px;display:flex;align-items:center;justify-content:space-between;flex-wrap:wrap;gap:12px;">
    <div style="display:flex;align-items:center;gap:12px;">
        <div style="width:42px;height:42px;background:rgba(255,255,255,.15);border-radius:10px;display:flex;align-items:center;justify-content:center;flex-shrink:0;">
            <i class="fas fa-clipboard-check" style="color:#fff;font-size:1.15rem;"></i>
        </div>
        <div>
            <h1 style="margin:0 0 1px;color:#fff;font-size:1.15rem;font-weight:800;">HaCeRa</h1>
            <p style="margin:0;color:#a7f3d0;font-size:.78rem;">Halal Readiness Assessment System &mdash; <?= htmlspecialchars($restaurant['restaurant_name']) ?></p>
        </div>
    </div>
    <div id="publishedStrip" style="<?= $restaurant['hacera_published_at'] ? '' : 'display:none;' ?>display:flex;align-items:center;gap:8px;background:rgba(255,255,255,.12);border:1px solid rgba(255,255,255,.25);padding:6px 14px;border-radius:8px;">
        <i class="fas fa-circle-check" style="color:#6ee7b7;font-size:.85rem;"></i>
        <span id="publishedAt" style="font-size:.75rem;color:#d1fae5;font-weight:600;">
            <?php if ($restaurant['hacera_published_at']): ?>
            Published <?= date('M j, Y', strtotime($restaurant['hacera_published_at'])) ?> &middot;
            <strong><?= htmlspecialchars($restaurant['hacera_band'] ?? '') ?> (<?= number_format((float)($restaurant['hacera_hrp'] ?? 0), 1) ?>%)</strong>
            <?php endif; ?>
        </span>
    </div>
</div>

<!-- ══ Step indicator ══════════════════════════════════════════════════════ -->
<div class="hc-stepper" id="hcStepper">
<?php
$step_labels = [];
foreach ($modules as $mid => $mod) {
    $step_labels[$mid] = 'M' . $mid . ': ' . $mod['title'];
}
$step_labels[6] = 'Results';
$s = 0;
foreach ($step_labels as $sid => $lbl):
    $s++;
?>
<?php if ($s > 1): ?>
<div class="hc-step-line" id="step-line-<?= $s ?>"></div>
<?php endif; ?>
<div class="hc-step-item">
    <div class="hc-step-circle <?= $s === 1 ? 'active' : '' ?>" id="step-circle-<?= $s ?>">
        <?php if ($s === 6): ?><i class="fas fa-chart-bar" style="font-size:.7rem;"></i><?php else: ?><?= $s ?><?php endif; ?>
    </div>
    <div class="hc-step-label <?= $s === 1 ? 'active' : '' ?>" id="step-label-<?= $s ?>"><?= htmlspecialchars($lbl) ?></div>
</div>
<?php endforeach; ?>
</div>

<!-- ══ Module panels (steps 1–5) ════════════════════════════════════════════ -->
<?php $step_num = 0; foreach ($modules as $mid => $mod):
    $step_num++;
    $ms      = $module_scores[$mid];
    $m_band  = HaCeRaCalculator::getBand($ms['score']);
    $m_score = $ms['score'];
    $m_gaps  = count($ms['gaps']);
?>
<div class="hc-panel card <?= $step_num === 1 ? 'active' : '' ?>" id="panel-<?= $step_num ?>" style="border-radius:14px;border:1px solid var(--neutral-200);overflow:hidden;">

    <!-- Module header -->
    <div class="hc-mod-header" style="background:linear-gradient(135deg,<?= $mod['color'] ?>18,<?= $mod['color'] ?>06);">
        <div class="hc-mod-icon" style="background:<?= $mod['color'] ?>20;color:<?= $mod['color'] ?>;"><i class="<?= $mod['icon'] ?>"></i></div>
        <div style="flex:1;">
            <div style="display:flex;align-items:center;gap:8px;flex-wrap:wrap;">
                <span style="font-weight:800;font-size:1rem;color:var(--neutral-800);">Module <?= $mid ?>: <?= htmlspecialchars($mod['title']) ?></span>
                <span id="m-band-<?= $mid ?>" style="font-size:.7rem;font-weight:700;padding:2px 8px;border-radius:999px;background:<?= $m_band['bg'] ?>;color:<?= $m_band['color'] ?>;"><?= $m_band['label'] ?></span>
                <?php if ($m_gaps > 0): ?>
                <span id="m-gap-badge-<?= $mid ?>" style="font-size:.68rem;background:#fee2e2;color:#dc2626;padding:2px 7px;border-radius:999px;font-weight:700;"><?= $m_gaps ?> gap<?= $m_gaps !== 1 ? 's' : '' ?></span>
                <?php else: ?>
                <span id="m-gap-badge-<?= $mid ?>" style="font-size:.68rem;background:#fee2e2;color:#dc2626;padding:2px 7px;border-radius:999px;font-weight:700;display:none;"></span>
                <?php endif; ?>
            </div>
            <div style="font-size:.78rem;color:var(--neutral-500);margin-top:2px;"><?= htmlspecialchars($mod['description']) ?></div>
            <div style="margin-top:8px;display:flex;align-items:center;gap:10px;">
                <div class="hc-bar-track" style="flex:1;">
                    <div class="hc-bar-fill" id="mod-bar-<?= $mid ?>" style="width:<?= $m_score ?>%;background:<?= $m_band['color'] ?>;"></div>
                </div>
                <span id="mod-pct-<?= $mid ?>" style="font-size:.82rem;font-weight:800;color:<?= $m_band['color'] ?>;min-width:38px;text-align:right;"><?= $m_score ?>%</span>
            </div>
        </div>
    </div>

    <!-- Checklist items -->
    <div style="padding:0 24px 4px;">
        <div style="font-size:.73rem;color:var(--neutral-400);padding:10px 0 6px;display:flex;align-items:center;gap:6px;">
            <span id="mod-count-<?= $mid ?>"><?= $ms['completed'] ?>/<?= $ms['total'] ?> completed</span>
            &nbsp;&middot;&nbsp;
            <i class="fas fa-circle" style="font-size:.4rem;color:#fbbf24;"></i> High &nbsp;
            <i class="fas fa-circle" style="font-size:.4rem;color:#60a5fa;"></i> Standard &nbsp;
            <i class="fas fa-circle" style="font-size:.4rem;color:#94a3b8;"></i> Info
        </div>
        <?php foreach ($mod['items'] as $item):
            $iid   = $item['id'];
            $resp  = $resp_map[$iid] ?? null;
            $rv    = $resp['response_value'] ?? 'no';
            $docs  = $doc_map[$iid] ?? [];
            $is_done  = in_array($iid, $completed_ids, true);
            $is_na    = ($rv === 'na');
            $chk_cls  = $is_done ? 'done' : ($is_na ? 'na' : 'gap');
            $chk_icon = $is_done ? 'fa-check' : ($is_na ? 'fa-minus' : 'fa-times');
            $w_class  = $item['weight'] === 3 ? 'w-high' : ($item['weight'] === 2 ? 'w-std' : 'w-info');
            $w_label  = $item['weight'] === 3 ? '⬆ High Priority' : ($item['weight'] === 2 ? 'Standard' : 'Info');
            $has_upload = ($item['type'] === 'upload');
        ?>
        <div class="hc-item-row" id="item-row-<?= $iid ?>">
            <!-- Top: status icon + question text + meta -->
            <div class="hc-item-top">
                <div class="hc-item-check <?= $chk_cls ?>" id="item-check-<?= $iid ?>"><i class="fas <?= $chk_icon ?>"></i></div>
                <div class="hc-item-content">
                    <div class="hc-item-label">
                        <?= htmlspecialchars($item['question'] ?? $item['label']) ?>
                        <?php if (!empty($item['optional'])): ?><span style="font-size:.68rem;color:var(--neutral-400);font-weight:400;"> (optional)</span><?php endif; ?>
                    </div>
                    <div class="hc-item-meta">
                        <span class="w-badge <?= $w_class ?>"><?= $w_label ?></span>
                        <?php if ($has_upload): ?><span style="color:var(--neutral-400);"><i class="fas fa-paperclip" style="font-size:.65rem;"></i> Upload available</span><?php endif; ?>
                    </div>
                </div>
            </div>
            <!-- Full-width Likert scale -->
            <div class="yn-scale-wrap">
                <div class="yn-toggle" id="yn-toggle-<?= $iid ?>">
                    <button class="yn-circle yn-yes <?= $rv === 'yes' ? 'active' : '' ?>"
                            title="Yes"
                            onclick="saveResponse(<?= $iid ?>, 'yes', this, <?= $has_upload ? 'true' : 'false' ?>)"
                            aria-label="Yes"></button>
                    <div class="yn-connector"></div>
                    <button class="yn-circle yn-na <?= $rv === 'na' ? 'active' : '' ?>"
                            title="N/A"
                            onclick="saveResponse(<?= $iid ?>, 'na',  this, <?= $has_upload ? 'true' : 'false' ?>)"
                            aria-label="Not applicable"></button>
                    <div class="yn-connector"></div>
                    <button class="yn-circle yn-no <?= $rv === 'no' ? 'active' : '' ?>"
                            title="No"
                            onclick="saveResponse(<?= $iid ?>, 'no',  this, <?= $has_upload ? 'true' : 'false' ?>)"
                            aria-label="No"></button>
                </div>
                <div class="yn-labels">
                    <span class="yn-label-yes">Yes</span>
                    <span class="yn-label-na">N/A</span>
                    <span class="yn-label-no">No</span>
                </div>
            </div>
            <!-- Optional upload section (shown only when Yes is selected) -->
            <?php if ($has_upload): ?>
            <div id="upload-section-<?= $iid ?>" style="<?= $rv === 'yes' ? '' : 'display:none;' ?>margin-top:12px;padding-top:12px;border-top:1px dashed var(--neutral-200);">
                <div style="font-size:.73rem;color:var(--neutral-600);margin-bottom:6px;font-weight:600;"><i class="fas fa-paperclip" style="color:var(--primary-400);"></i> Supporting Document (Optional)</div>
                <div class="hc-upload-zone" onclick="document.getElementById('file-<?= $iid ?>').click()" id="zone-<?= $iid ?>">
                    <i class="fas fa-cloud-arrow-up" style="color:var(--primary-400);font-size:1rem;flex-shrink:0;"></i>
                    <span style="font-size:.76rem;color:var(--neutral-500);">Click to upload PDF, JPG, PNG, DOCX<span style="font-size:.7rem;display:block;color:var(--neutral-400);">Max 20 MB</span></span>
                    <input type="file" id="file-<?= $iid ?>" accept=".pdf,.png,.jpg,.jpeg,.docx,.doc" onchange="uploadDocument(<?= $iid ?>, this)">
                    <span id="upload-spinner-<?= $iid ?>" style="display:none;"><span class="hc-spinner" style="border-top-color:var(--primary-500);border-color:var(--neutral-200);"></span></span>
                </div>
                <div class="hc-doc-list" id="doc-list-<?= $iid ?>">
                        <?php foreach ($docs as $doc): ?>
                        <div class="hc-doc-item" id="doc-entry-<?= $doc['id'] ?>">
                            <i class="fas fa-file-lines" style="color:var(--primary-500);font-size:.85rem;flex-shrink:0;"></i>
                            <a href="<?= BASE_URL ?>uploads/<?= htmlspecialchars($doc['file_path']) ?>" target="_blank" rel="noopener"><?= htmlspecialchars($doc['original_name'] ?: basename($doc['file_path'])) ?></a>
                            <span style="color:var(--neutral-400);font-size:.7rem;flex-shrink:0;"><?= date('M d, Y', strtotime($doc['uploaded_at'])) ?></span>
                            <button onclick="deleteDocument(<?= $doc['id'] ?>, <?= $iid ?>)" title="Remove" style="background:none;border:none;cursor:pointer;color:#dc2626;padding:0 2px;font-size:.8rem;flex-shrink:0;"><i class="fas fa-trash-can"></i></button>
                        </div>
                        <?php endforeach; ?>
                </div>
            </div>
            <?php endif; ?>
        </div>
        <?php endforeach; ?>
    </div>

    <!-- Nav -->
    <div class="hc-nav" style="padding:16px 24px;">
        <?php if ($step_num > 1): ?>
        <button type="button" class="btn btn-outline" onclick="goToStep(<?= $step_num - 1 ?>, false)"><i class="fas fa-arrow-left"></i> Back</button>
        <?php else: ?>
        <div></div>
        <?php endif; ?>
        <div style="display:flex;align-items:center;gap:8px;">
            <span style="font-size:.78rem;color:var(--neutral-400);">Step <?= $step_num ?> of <?= count($modules) ?></span>
            <?php if ($step_num < count($modules)): ?>
            <button type="button" class="btn btn-primary" onclick="goToStep(<?= $step_num + 1 ?>, true)">Next <i class="fas fa-arrow-right"></i></button>
            <?php else: ?>
            <button type="button" class="btn btn-primary" style="background:#065f46;border-color:#065f46;" onclick="openResultsConfirm()"><i class="fas fa-chart-bar"></i> View Results</button>
            <?php endif; ?>
        </div>
    </div>
</div>
<?php endforeach; ?>

<!-- ══ Results confirmation modal ══════════════════════════════════════════ -->
<div id="resultsConfirmOverlay" style="display:none;position:fixed;inset:0;background:rgba(15,23,42,.55);z-index:9998;align-items:center;justify-content:center;">
    <div style="background:#fff;border-radius:20px;width:min(460px,94vw);box-shadow:0 24px 60px rgba(0,0,0,.2);overflow:hidden;animation:hcSlideIn .25s cubic-bezier(.4,0,.2,1) both;">

        <!-- Modal header -->
        <div style="background:linear-gradient(135deg,#064e3b,#047857);padding:24px 28px 20px;">
            <div style="display:flex;align-items:center;gap:14px;">
                <div style="width:48px;height:48px;background:rgba(255,255,255,.15);border-radius:12px;display:flex;align-items:center;justify-content:center;flex-shrink:0;">
                    <i class="fas fa-clipboard-check" style="color:#fff;font-size:1.3rem;"></i>
                </div>
                <div>
                    <h3 style="margin:0 0 3px;color:#fff;font-size:1.1rem;font-weight:800;">Ready to see your results?</h3>
                    <p style="margin:0;color:#a7f3d0;font-size:.8rem;">HaCeRa Assessment Summary</p>
                </div>
            </div>
        </div>

        <!-- Modal body -->
        <div style="padding:24px 28px;">
            <p style="margin:0 0 16px;color:var(--neutral-600);font-size:.9rem;line-height:1.6;">
                You are about to view your <strong>Halal Readiness Progress (HRP)</strong> score and gap analysis based on your answers across all 5 modules.
            </p>
            <div style="background:#f0fdf4;border:1px solid #bbf7d0;border-radius:10px;padding:14px 16px;margin-bottom:20px;">
                <div style="font-size:.8rem;color:#166534;font-weight:700;margin-bottom:6px;display:flex;align-items:center;gap:6px;">
                    <i class="fas fa-circle-info"></i> Before you proceed
                </div>
                <ul style="margin:0;padding-left:18px;font-size:.82rem;color:#166534;line-height:1.7;">
                    <li>Your responses have been auto-saved as you answered.</li>
                    <li>You can still go back and edit any answer after viewing results.</li>
                    <li>You can publish your score to your public restaurant profile.</li>
                </ul>
            </div>
            <div style="display:flex;gap:10px;">
                <button onclick="closeResultsConfirm()" class="btn btn-outline" style="flex:1;">
                    <i class="fas fa-arrow-left"></i> Not Yet
                </button>
                <button onclick="confirmViewResults()" class="btn btn-primary" style="flex:2;background:#065f46;border-color:#065f46;font-weight:700;">
                    <i class="fas fa-chart-bar"></i> Yes, Show My Results
                </button>
            </div>
        </div>
    </div>
</div>

<!-- ══ Results panel (step 6) ═══════════════════════════════════════════════ -->
<div class="hc-panel card" id="panel-6" style="border-radius:14px;border:1px solid var(--neutral-200);overflow:hidden;">

    <!-- Results header -->
    <div style="background:linear-gradient(135deg,#064e3b,#047857);padding:20px 24px;">
        <div style="display:flex;align-items:center;gap:12px;flex-wrap:wrap;justify-content:space-between;">
            <div>
                <div style="font-size:.72rem;font-weight:700;color:#a7f3d0;letter-spacing:.05em;text-transform:uppercase;margin-bottom:4px;">Assessment Complete</div>
                <h2 style="margin:0;color:#fff;font-size:1.2rem;font-weight:800;"><?= htmlspecialchars($restaurant['restaurant_name']) ?></h2>
            </div>
            <div style="display:flex;gap:8px;flex-wrap:wrap;">
                <button onclick="publishScore()" id="publishBtn" style="background:#fff;color:#065f46;padding:9px 16px;border-radius:10px;font-weight:700;font-size:.85rem;cursor:pointer;display:flex;align-items:center;gap:8px;border:none;transition:opacity .15s;white-space:nowrap;" onmouseover="this.style.opacity='.88'" onmouseout="this.style.opacity='1'">
                    <i class="fas fa-globe"></i> Save &amp; Publish
                </button>
                <?php if ($premiumUnlocked): ?>
                <button onclick="generatePDF(<?= $premiumPurchaseId ?>)" id="pdfBtn" style="background:rgba(255,255,255,.15);border:1.5px solid rgba(255,255,255,.3);color:#fff;padding:9px 16px;border-radius:10px;font-weight:700;font-size:.85rem;cursor:pointer;display:flex;align-items:center;gap:8px;transition:background .15s;white-space:nowrap;">
                    <i class="fas fa-file-pdf"></i> Download Full Report
                </button>
                <?php else: ?>
                <button id="pdfBtn" disabled title="Verified premium payment required" style="background:rgba(255,255,255,.08);border:1.5px solid rgba(255,255,255,.2);color:#d1fae5;padding:9px 16px;border-radius:10px;font-weight:700;font-size:.85rem;cursor:not-allowed;opacity:.75;display:flex;align-items:center;gap:8px;white-space:nowrap;">
                    <i class="fas fa-lock"></i> Full Report Locked
                </button>
                <?php endif; ?>
                <a href="<?= BASE_URL ?>dashboard/business_owner/applications.php" style="background:rgba(255,255,255,.12);border:1.5px solid rgba(255,255,255,.25);color:#fff;padding:9px 16px;border-radius:10px;font-weight:700;font-size:.85rem;text-decoration:none;display:flex;align-items:center;gap:8px;white-space:nowrap;">
                    <i class="fas fa-paper-plane"></i> Submit Application
                </a>
            </div>
        </div>
    </div>

    <div class="card-body" style="padding:24px;">
        <?php if ($premiumUnlocked): ?>
        <div style="margin-bottom:16px;padding:11px 13px;border:1px solid #86efac;background:#f0fdf4;border-radius:9px;font-size:.8rem;color:#166534;"><i class="fas fa-circle-check"></i> <strong>Premium Access Active</strong> &mdash; HaCeRa Premium Assessment Results, PHP <?= number_format((float)$premiumAccess['amount'], 2) ?>. Verified <?= htmlspecialchars((string)$premiumAccess['paid_at']) ?> &middot; Receipt <?= htmlspecialchars((string)$premiumAccess['internal_reference']) ?>.</div>
        <?php elseif ($paymentReturn === 'return'): ?>
        <div style="margin-bottom:16px;padding:11px 13px;border:1px solid #bfdbfe;background:#eff6ff;border-radius:9px;font-size:.8rem;color:#1d4ed8;"><i class="fas fa-clock"></i> Verifying payment. Premium access will unlock only after the signed PayMongo webhook confirms it.</div>
        <?php elseif ($paymentReturn === 'cancelled'): ?>
        <div style="margin-bottom:16px;padding:11px 13px;border:1px solid #fed7aa;background:#fff7ed;border-radius:9px;font-size:.8rem;color:#9a3412;"><i class="fas fa-circle-info"></i> Payment was cancelled. Premium Results remain locked.</div>
        <?php endif; ?>
        <div class="results-grid">

            <!-- Left: Overall gauge -->
            <div>
                <div class="card" style="border-radius:12px;border:1px solid var(--neutral-200);overflow:hidden;margin-bottom:16px;">
                    <div style="background:linear-gradient(135deg,#064e3b,#047857);padding:12px 16px;">
                        <div style="font-size:.68rem;font-weight:700;color:#a7f3d0;text-transform:uppercase;letter-spacing:.05em;">Overall Readiness</div>
                    </div>
                    <div style="padding:16px;text-align:center;">
                        <div class="hrp-gauge-wrap" style="margin-bottom:8px;">
                            <svg width="140" height="80" viewBox="0 0 160 90" style="overflow:visible;">
                                <path d="M 16 88 A 64 64 0 0 1 144 88" fill="none" stroke="#e5e7eb" stroke-width="12" stroke-linecap="round"/>
                                <path id="gauge-fill" d="M 16 88 A 64 64 0 0 1 144 88" fill="none" stroke="<?= $hrp_band['color'] ?>" stroke-width="12" stroke-linecap="round" stroke-dasharray="201" stroke-dashoffset="<?= round(201 - (201 * $hrp / 100)) ?>"/>
                                <text x="80" y="78" text-anchor="middle" font-size="20" font-weight="800" fill="<?= $hrp_band['color'] ?>" id="gauge-text"><?= $hrp ?>%</text>
                            </svg>
                        </div>
                        <div id="hrp-band-badge" style="display:inline-flex;align-items:center;gap:4px;background:<?= $hrp_band['bg'] ?>;color:<?= $hrp_band['color'] ?>;padding:4px 12px;border-radius:999px;font-weight:700;font-size:.78rem;margin-bottom:14px;">
                            <i class="<?= $hrp_band['icon'] ?>"></i>
                            <span id="hrp-band-label"><?= $hrp_band['label'] ?></span>
                        </div>
                        <!-- Per-module mini bars -->
                        <div style="display:flex;flex-direction:column;gap:7px;text-align:left;">
                            <?php foreach ($modules as $mid => $mod):
                                $ms     = $module_scores[$mid];
                                $m_band = HaCeRaCalculator::getBand($ms['score']);
                            ?>
                            <div>
                                <div style="display:flex;justify-content:space-between;align-items:center;margin-bottom:2px;">
                                    <span style="font-size:.68rem;font-weight:600;color:var(--neutral-700);overflow:hidden;text-overflow:ellipsis;white-space:nowrap;max-width:130px;" title="<?= htmlspecialchars($mod['title']) ?>">
                                        <i class="<?= $mod['icon'] ?>" style="color:<?= $mod['color'] ?>;width:12px;"></i> <?= htmlspecialchars($mod['title']) ?>
                                    </span>
                                    <span id="res-score-<?= $mid ?>" style="font-size:.68rem;font-weight:800;color:<?= $m_band['color'] ?>;flex-shrink:0;margin-left:4px;"><?= $ms['score'] ?>%</span>
                                </div>
                                <div class="hc-bar-track">
                                    <div class="hc-bar-fill" id="res-bar-<?= $mid ?>" style="width:<?= $ms['score'] ?>%;background:<?= $m_band['color'] ?>;height:6px;"></div>
                                </div>
                            </div>
                            <?php endforeach; ?>
                        </div>
                        <!-- Totals -->
                        <?php
                        $total_items     = array_sum(array_column($module_scores, 'total'));
                        $completed_items = array_sum(array_column($module_scores, 'completed'));
                        $total_weight    = array_sum(array_column($module_scores, 'total_weight'));
                        $earned_weight   = array_sum(array_column($module_scores, 'earned_weight'));
                        ?>
                        <div style="margin-top:12px;padding:8px;background:#f8fafc;border-radius:8px;display:flex;gap:0;text-align:center;">
                            <div style="flex:1;border-right:1px solid var(--neutral-100);">
                                <div style="font-size:1rem;font-weight:800;color:var(--neutral-800);" id="res-items-done"><?= $completed_items ?>/<?= $total_items ?></div>
                                <div style="font-size:.63rem;color:var(--neutral-500);">Items</div>
                            </div>
                            <div style="flex:1;">
                                <div style="font-size:1rem;font-weight:800;color:var(--neutral-800);" id="res-weight-done"><?= $earned_weight ?>/<?= $total_weight ?></div>
                                <div style="font-size:.63rem;color:var(--neutral-500);">Weight</div>
                            </div>
                        </div>
                    </div>
                </div>

                <!-- Back to assessment -->
                <button type="button" onclick="goToStep(1, false)" class="btn btn-outline" style="width:100%;"><i class="fas fa-arrow-left"></i> Retake Assessment</button>
            </div>

            <!-- Right: Gap analysis -->
            <div>
                <div style="display:flex;align-items:center;gap:8px;margin-bottom:14px;">
                    <h3 style="margin:0;font-size:.95rem;font-weight:800;color:var(--neutral-800);display:flex;align-items:center;gap:8px;">
                        <i class="fas fa-triangle-exclamation" style="color:#dc2626;"></i> Gap Analysis
                        <span id="gap-count-badge" style="background:#fee2e2;color:#dc2626;font-size:.68rem;font-weight:700;padding:1px 7px;border-radius:999px;"><?= count($all_gaps) ?></span>
                    </h3>
                    <span style="font-size:.73rem;color:var(--neutral-500);margin-left:auto;">Sorted by priority</span>
                </div>
                <div id="gapList" style="max-height:480px;overflow-y:auto;">
                    <?php if (empty($all_gaps)): ?>
                    <div style="text-align:center;padding:32px;color:var(--neutral-400);">
                        <i class="fas fa-circle-check" style="font-size:2.2rem;color:#16a34a;display:block;margin-bottom:10px;"></i>
                        <strong style="color:#16a34a;">All items complete!</strong><br>
                        <span style="font-size:.8rem;">You are ready for Halal inspection.</span>
                    </div>
                    <?php else: ?>
                    <?php foreach ($all_gaps as $g):
                        $w_class = $g['weight'] === 3 ? 'w3' : ($g['weight'] === 2 ? 'w2' : 'w1');
                        $w_icon  = $g['weight'] === 3 ? 'fas fa-circle-exclamation' : ($g['weight'] === 2 ? 'fas fa-circle-dot' : 'fas fa-circle');
                        $w_clr   = $g['weight'] === 3 ? '#dc2626' : ($g['weight'] === 2 ? '#d97706' : '#94a3b8');
                        $mod_col = $modules[$g['module_id']]['color'];
                    ?>
                    <div class="gap-row <?= $w_class ?>" id="gap-row-<?= $g['id'] ?>">
                        <i class="<?= $w_icon ?>" style="color:<?= $w_clr ?>;font-size:.8rem;margin-top:2px;flex-shrink:0;"></i>
                        <div style="flex:1;min-width:0;">
                            <div style="font-size:.78rem;font-weight:600;color:var(--neutral-800);line-height:1.3;"><?= htmlspecialchars($g['label']) ?></div>
                            <div style="font-size:.7rem;color:var(--neutral-500);margin-top:2px;display:flex;gap:6px;align-items:center;">
                                <span style="color:<?= $mod_col ?>;font-weight:600;">M<?= $g['module_id'] ?> — <?= htmlspecialchars($g['module_title']) ?></span>
                                &middot;
                                <a href="#" onclick="goToModuleItem(<?= $g['module_id'] ?>, <?= $g['id'] ?>); return false;" style="color:var(--primary-600);font-weight:600;">Fix &rarr;</a>
                            </div>
                        </div>
                    </div>
                    <?php endforeach; ?>
                    <?php endif; ?>
                </div>
            </div>
        </div>

        <!-- AI Readiness Insights: advisory only; no score or certification controls. -->
        <section id="aiInsightsSection" style="margin-top:28px;border-top:1px solid var(--neutral-200);padding-top:24px;">
            <?php if (!$premiumUnlocked): ?>
            <div style="display:grid;grid-template-columns:repeat(auto-fit,minmax(250px,1fr));gap:14px;">
                <article style="border:1px solid var(--neutral-200);border-radius:12px;padding:18px;background:linear-gradient(135deg,#f8fafc,#fff);"><h3 style="margin:0 0 6px;font-size:1rem;"><i class="fas fa-lock" style="color:var(--primary-600);"></i> AI Readiness Insights</h3><p style="font-size:.8rem;color:var(--neutral-600);">Prioritized compliance guidance, corrective actions, and evidence-aware recommendations. One verified payment also unlocks the full report.</p><div aria-hidden="true" style="height:34px;border-radius:7px;background:linear-gradient(90deg,#e2e8f0,#f8fafc,#e2e8f0);filter:blur(2px);margin:12px 0;"></div><button type="button" class="btn btn-primary" onclick="unlockPremiumResults()">Unlock AI Insights &amp; Full Report &mdash; &#8369;<?= number_format($premiumPrice, 2) ?></button></article>
                <article style="border:1px solid var(--neutral-200);border-radius:12px;padding:18px;background:linear-gradient(135deg,#f8fafc,#fff);"><h3 style="margin:0 0 6px;font-size:1rem;"><i class="fas fa-lock" style="color:var(--primary-600);"></i> Full HaCeRa Assessment Report</h3><p style="font-size:.8rem;color:var(--neutral-600);">Complete assessment breakdown and a downloadable PDF for this assessment version. The same verified payment unlocks AI Readiness Insights.</p><div aria-hidden="true" style="height:34px;border-radius:7px;background:linear-gradient(90deg,#e2e8f0,#f8fafc,#e2e8f0);filter:blur(2px);margin:12px 0;"></div><button type="button" class="btn btn-primary" onclick="unlockPremiumResults()">Unlock AI Insights &amp; Full Report &mdash; &#8369;<?= number_format($premiumPrice, 2) ?></button></article>
            </div>
            <?php else: ?>
            <div style="display:flex;align-items:flex-start;justify-content:space-between;gap:16px;flex-wrap:wrap;margin-bottom:14px;">
                <div>
                    <h3 style="margin:0;font-size:1rem;font-weight:800;color:var(--neutral-800);"><i class="fas fa-wand-magic-sparkles" style="color:var(--primary-600);"></i> AI Readiness Insights</h3>
                    <p style="margin:5px 0 0;font-size:.78rem;color:var(--neutral-500);max-width:760px;line-height:1.55;">Advisory guidance based on your saved HaCeRa answers and evidence references. It does not change HRP, publish a score, verify documents, or make any certification decision.</p>
                </div>
                <?php if ($hacera_ai_enabled): ?>
                <button type="button" class="btn btn-primary" id="aiGenerateBtn" onclick="generateAiInsights()"><i class="fas fa-wand-magic-sparkles"></i> Generate AI Insights</button>
                <?php endif; ?>
            </div>

            <?php if (!$hacera_ai_enabled): ?>
            <div style="border:1px dashed var(--neutral-300);background:var(--neutral-50);border-radius:10px;padding:14px;font-size:.8rem;color:var(--neutral-600);">
                <i class="fas fa-lock" style="color:var(--neutral-400);"></i> AI Readiness Insights is disabled in this environment. Existing HaCeRa assessment and publishing features continue to work normally.
            </div>
            <?php else: ?>
            <div id="aiStatus" role="status" aria-live="polite" style="display:none;margin:0 0 14px;padding:10px 12px;border-radius:8px;font-size:.8rem;"></div>
            <div class="ai-insights-grid">
                <div class="ai-insight-card" style="background:linear-gradient(135deg,#ecfdf5,#f8fafc);">
                    <div style="font-size:.68rem;font-weight:800;color:var(--primary-700);text-transform:uppercase;letter-spacing:.05em;">Current HaCeRa baseline</div>
                    <div style="font-size:1.3rem;font-weight:800;color:<?= $hrp_band['color'] ?>;margin-top:5px;"><?= number_format($hrp, 1) ?>% <span style="font-size:.8rem;font-weight:700;"><?= htmlspecialchars($hrp_band['label']) ?></span></div>
                    <div id="aiLastAnalysis" style="font-size:.74rem;color:var(--neutral-500);margin-top:8px;">No AI analysis loaded.</div>
                </div>
                <div class="ai-insight-card">
                    <div style="font-size:.75rem;font-weight:800;color:var(--neutral-800);margin-bottom:6px;">AI readiness summary</div>
                    <div id="aiSummary" style="font-size:.8rem;color:var(--neutral-600);line-height:1.6;">Generate an advisory report to receive an evidence-aware explanation of this assessment.</div>
                </div>
            </div>
            <div class="ai-insights-grid" style="margin-top:14px;">
                <div class="ai-insight-card">
                    <h4 style="margin:0 0 6px;font-size:.84rem;color:var(--neutral-800);"><i class="fas fa-list-check" style="color:#dc2626;"></i> Compliance gap analysis</h4>
                    <div id="aiFindings" style="font-size:.78rem;color:var(--neutral-500);">No validated AI findings yet.</div>
                </div>
                <div class="ai-insight-card">
                    <h4 style="margin:0 0 6px;font-size:.84rem;color:var(--neutral-800);"><i class="fas fa-screwdriver-wrench" style="color:#d97706;"></i> Corrective action plan</h4>
                    <div id="aiActions" style="font-size:.78rem;color:var(--neutral-500);">No corrective actions yet.</div>
                </div>
                <div class="ai-insight-card">
                    <h4 style="margin:0 0 6px;font-size:.84rem;color:var(--neutral-800);"><i class="fas fa-paperclip" style="color:#2563eb;"></i> Evidence recommendations</h4>
                    <div id="aiEvidence" style="font-size:.78rem;color:var(--neutral-500);">No evidence recommendations yet.</div>
                </div>
                <div class="ai-insight-card">
                    <h4 style="margin:0 0 6px;font-size:.84rem;color:var(--neutral-800);"><i class="fas fa-clock-rotate-left" style="color:#7c3aed;"></i> AI analysis history</h4>
                    <div id="aiHistory" style="font-size:.78rem;color:var(--neutral-500);">Loading history…</div>
                </div>
            </div>
            <?php endif; ?>
            <?php endif; ?>
        </section>
    </div>

    <!-- Nav (back only on results) -->
    <div class="hc-nav" style="padding:16px 24px;">
        <button type="button" class="btn btn-outline" onclick="goToStep(<?= count($modules) ?>, false)"><i class="fas fa-arrow-left"></i> Back to Module <?= count($modules) ?></button>
        <span style="font-size:.78rem;color:var(--neutral-400);">Step <?= count($modules) + 1 ?> of <?= count($modules) + 1 ?></span>
    </div>
</div>

<!-- Toast -->
<div id="hcToast"></div>

<script>
const HACERA_API   = '<?= BASE_URL ?>dashboard/business_owner/api/hacera_api.php';
const REVENUE_PAYMENT_API = '<?= BASE_URL ?>dashboard/business_owner/api/revenue_payment_api.php';
const REVENUE_PAYMENT_CSRF = <?= json_encode($revenue_payment_csrf) ?>;
const HACERA_AI_API = '<?= BASE_URL ?>dashboard/business_owner/api/hacera_ai_api.php';
const BASE_URL_JS  = '<?= BASE_URL ?>';
const HACERA_AI_ENABLED = <?= $hacera_ai_enabled ? 'true' : 'false' ?>;
const HACERA_PREMIUM_UNLOCKED = <?= $premiumUnlocked ? 'true' : 'false' ?>;
const HACERA_PREMIUM_PURCHASE_ID = <?= $premiumPurchaseId ?>;
const HACERA_AI_CSRF = <?= json_encode($hacera_ai_csrf_token) ?>;
const TOTAL_STEPS  = <?= $total_steps ?>;
const MODULE_COUNT = <?= count($modules) ?>;
const INITIAL_STEP = <?= $initial_step ?>;
const MODULES_META = <?= json_encode(array_map(fn($m) => ['color'=>$m['color'],'title'=>$m['title'],'icon'=>$m['icon']], $modules), JSON_UNESCAPED_UNICODE) ?>;

let currentStep = INITIAL_STEP;
let isForward   = true;

// ── Toast ─────────────────────────────────────────────────────────────────
function showToast(msg, isError = false) {
    const t = document.getElementById('hcToast');
    t.innerHTML = `<i class="fas ${isError ? 'fa-circle-xmark' : 'fa-circle-check'}"></i> ${msg}`;
    t.className = isError ? 'error' : '';
    t.style.display = 'flex';
    clearTimeout(t._timer);
    t._timer = setTimeout(() => t.style.display = 'none', 3400);
}

// ── Step navigation ───────────────────────────────────────────────────────
function goToStep(n, forward) {
    isForward = forward !== false;
    document.querySelectorAll('.hc-panel').forEach(p => {
        p.classList.remove('active', 'slide-left');
        p.style.display = 'none';
    });
    const panel = document.getElementById('panel-' + n);
    if (!panel) return;
    panel.classList.remove('slide-left');
    panel.style.display = 'block';
    // Trigger reflow for animation
    void panel.offsetWidth;
    if (!isForward) panel.classList.add('slide-left');
    panel.classList.add('active');

    currentStep = n;
    updateStepperUI(n);
    window.scrollTo({ top: 0, behavior: 'smooth' });
}

function updateStepperUI(active) {
    for (let i = 1; i <= TOTAL_STEPS; i++) {
        const circle = document.getElementById('step-circle-' + i);
        const label  = document.getElementById('step-label-' + i);
        const line   = document.getElementById('step-line-' + i);
        if (!circle) continue;
        circle.classList.remove('active', 'done');
        label.classList.remove('active', 'done');
        if (line) line.classList.remove('done');
        if (i < active)  { circle.classList.add('done'); label.classList.add('done'); circle.innerHTML = '<i class="fas fa-check" style="font-size:.65rem;"></i>'; if (line) line.classList.add('done'); }
        else if (i === active) { circle.classList.add('active'); label.classList.add('active'); circle.innerHTML = i === TOTAL_STEPS ? '<i class="fas fa-chart-bar" style="font-size:.65rem;"></i>' : String(i); }
        else { circle.innerHTML = i === TOTAL_STEPS ? '<i class="fas fa-chart-bar" style="font-size:.65rem;"></i>' : String(i); }
    }
}

// Jump to a specific module + scroll to item (from gap "Fix →" link)
function goToModuleItem(mid, itemId) {
    goToStep(mid, false);
    setTimeout(() => {
        const el = document.getElementById('item-row-' + itemId);
        if (el) {
            el.scrollIntoView({ behavior: 'smooth', block: 'center' });
            el.style.background = '#fef9c3';
            setTimeout(() => { el.style.background = ''; el.style.transition = 'background .6s'; }, 1800);
        }
    }, 200);
}

// ── Results confirmation modal ────────────────────────────────────────────
function openResultsConfirm() {
    const overlay = document.getElementById('resultsConfirmOverlay');
    overlay.style.display = 'flex';
}

function closeResultsConfirm() {
    document.getElementById('resultsConfirmOverlay').style.display = 'none';
}

async function confirmViewResults() {
    closeResultsConfirm();
    goToStep(MODULE_COUNT + 1, true);
    const form = new FormData();
    form.append('action', 'complete_assessment');
    try {
        const response = await fetch(HACERA_API, { method: 'POST', body: form });
        const data = await response.json();
        if (!data.success) showToast(data.message || 'Results opened, but completion could not be saved.', true);
    } catch {
        showToast('Results opened, but completion could not be saved. Please try again later.', true);
    }
}
</script>

<script>
// ── Score update helper ───────────────────────────────────────────────────
function applyScores(data) {
    if (!data.module_scores) return;
    const hrp  = parseFloat(data.hrp || 0);
    const band = data.band || {};

    const gFill = document.getElementById('gauge-fill');
    const gText = document.getElementById('gauge-text');
    const badge = document.getElementById('hrp-band-badge');
    const lbl   = document.getElementById('hrp-band-label');

    if (gFill) { gFill.setAttribute('stroke-dashoffset', Math.round(201 - (201 * hrp / 100))); gFill.setAttribute('stroke', band.color || '#16a34a'); }
    if (gText) { gText.textContent = hrp + '%'; gText.setAttribute('fill', band.color || '#16a34a'); }
    if (badge && band.color) { badge.style.background = band.bg; badge.style.color = band.color; }
    if (lbl && band.label) lbl.textContent = band.label;

    let totalDone = 0, totalItems = 0, totalWeight = 0, earnedWeight = 0;
    const allGaps = [];

    for (const [midStr, ms] of Object.entries(data.module_scores)) {
        const mid   = parseInt(midStr);
        const score = parseFloat(ms.score);
        const color = scoreColor(score);

        // Module header bars
        const bar = document.getElementById('mod-bar-' + mid);
        const pct = document.getElementById('mod-pct-' + mid);
        const mBand = document.getElementById('m-band-' + mid);
        const gBadge = document.getElementById('m-gap-badge-' + mid);
        const cnt  = document.getElementById('mod-count-' + mid);
        if (bar) { bar.style.width = score + '%'; bar.style.background = color; }
        if (pct) { pct.textContent = score + '%'; pct.style.color = color; }
        if (cnt) cnt.textContent = (ms.completed || 0) + '/' + (ms.total || 0) + ' completed';
        if (gBadge) { const gc = Number(ms.gap_count ?? (ms.gaps || []).length); gBadge.textContent = gc ? gc + ' gap' + (gc !== 1 ? 's' : '') : ''; gBadge.style.display = gc ? '' : 'none'; }

        // Results page mini bars
        const rBar = document.getElementById('res-bar-' + mid);
        const rPct = document.getElementById('res-score-' + mid);
        if (rBar) { rBar.style.width = score + '%'; rBar.style.background = color; }
        if (rPct) { rPct.textContent = score + '%'; rPct.style.color = color; }

        totalDone   += (ms.completed || 0);
        totalItems  += (ms.total || 0);
        earnedWeight += (ms.earned_weight || 0);
        totalWeight  += (ms.total_weight || 0);

        (ms.gaps || []).forEach(g => allGaps.push({ ...g, module_id: mid }));
    }

    const rItemsDone   = document.getElementById('res-items-done');
    const rWeightDone  = document.getElementById('res-weight-done');
    if (rItemsDone)  rItemsDone.textContent  = totalDone + '/' + totalItems;
    if (rWeightDone) rWeightDone.textContent = earnedWeight + '/' + totalWeight;

    rebuildGaps(allGaps);
}

function scoreColor(pct) {
    if (pct >= 90) return '#16a34a';
    if (pct >= 70) return '#2563eb';
    if (pct >= 50) return '#d97706';
    if (pct >= 25) return '#ea580c';
    return '#dc2626';
}

function rebuildGaps(gaps) {
    const list  = document.getElementById('gapList');
    const badge = document.getElementById('gap-count-badge');
    if (!list) return;
    if (badge) badge.textContent = gaps.length;
    gaps.sort((a, b) => (b.weight || 0) - (a.weight || 0));
    if (gaps.length === 0) {
        list.innerHTML = `<div style="text-align:center;padding:32px;color:var(--neutral-400);">
            <i class="fas fa-circle-check" style="font-size:2.2rem;color:#16a34a;display:block;margin-bottom:10px;"></i>
            <strong style="color:#16a34a;">All items complete!</strong><br>
            <span style="font-size:.8rem;">You are ready for Halal inspection.</span></div>`;
        return;
    }
    list.innerHTML = gaps.map(g => {
        const wClass = g.weight === 3 ? 'w3' : (g.weight === 2 ? 'w2' : 'w1');
        const wIcon  = g.weight === 3 ? 'fas fa-circle-exclamation' : (g.weight === 2 ? 'fas fa-circle-dot' : 'fas fa-circle');
        const wClr   = g.weight === 3 ? '#dc2626' : (g.weight === 2 ? '#d97706' : '#94a3b8');
        const mColor = (MODULES_META[g.module_id] || {}).color || '#64748b';
        const mTitle = (MODULES_META[g.module_id] || {}).title || '';
        return `<div class="gap-row ${wClass}" id="gap-row-${g.id}">
            <i class="${wIcon}" style="color:${wClr};font-size:.8rem;margin-top:2px;flex-shrink:0;"></i>
            <div style="flex:1;min-width:0;">
                <div style="font-size:.78rem;font-weight:600;color:var(--neutral-800);line-height:1.3;">${escapeHtml(g.label)}</div>
                <div style="font-size:.7rem;color:var(--neutral-500);margin-top:2px;display:flex;gap:6px;align-items:center;">
                    <span style="color:${mColor};font-weight:600;">M${g.module_id} — ${escapeHtml(mTitle)}</span> &middot;
                    <a href="#" onclick="goToModuleItem(${g.module_id},${g.id});return false;" style="color:var(--primary-600);font-weight:600;">Fix &rarr;</a>
                </div>
            </div></div>`;
    }).join('');
}

function escapeHtml(s) {
    return String(s).replace(/&/g,'&amp;').replace(/</g,'&lt;').replace(/>/g,'&gt;').replace(/"/g,'&quot;');
}

// ── Item visual state ─────────────────────────────────────────────────────
function setItemState(itemId, isDone, isNa) {
    const chk = document.getElementById('item-check-' + itemId);
    if (!chk) return;
    chk.className = 'hc-item-check ' + (isDone ? 'done' : (isNa ? 'na' : 'gap'));
    const icon = chk.querySelector('i');
    if (icon) icon.className = 'fas ' + (isDone ? 'fa-check' : (isNa ? 'fa-minus' : 'fa-times'));
}
</script>

<script>
// ── Save Yes/No/NA ────────────────────────────────────────────────────────
// btn is a .yn-circle element; we deactivate all siblings then activate the clicked one
async function saveResponse(itemId, value, btn, hasUploadOption) {
    // Deactivate all three circles in this item's toggle group
    const group = btn.closest('.yn-toggle');
    group.querySelectorAll('.yn-circle').forEach(b => b.classList.remove('active'));
    // Activate the selected circle
    btn.classList.add('active');

    // Update the status icon (check / minus / times) on the item row
    setItemState(itemId, value === 'yes', value === 'na');

    // Show/hide the upload section when Yes is selected
    if (hasUploadOption) {
        const sec = document.getElementById('upload-section-' + itemId);
        if (sec) sec.style.display = value === 'yes' ? 'block' : 'none';
    }

    const fd = new FormData();
    fd.append('action', 'save_response');
    fd.append('checklist_item_id', itemId);
    fd.append('response_value', value);

    try {
        const res  = await fetch(HACERA_API, { method: 'POST', body: fd });
        const data = await res.json();
        if (data.success) {
            applyScores(data);
        } else {
            showToast(data.message || 'Save failed.', true);
        }
    } catch {
        showToast('Network error — please try again.', true);
    }
}

// ── Upload document ───────────────────────────────────────────────────────
async function uploadDocument(itemId, input) {
    if (!input.files || !input.files[0]) return;
    const spinner = document.getElementById('upload-spinner-' + itemId);
    const zone    = document.getElementById('zone-' + itemId);
    if (spinner) spinner.style.display = 'inline-block';
    if (zone)    zone.style.borderColor = 'var(--primary-400)';

    const fd = new FormData();
    fd.append('action', 'upload_document');
    fd.append('checklist_item_id', itemId);
    fd.append('doc_file', input.files[0]);

    try {
        const res  = await fetch(HACERA_API, { method: 'POST', body: fd });
        const data = await res.json();
        if (data.success) {
            const list = document.getElementById('doc-list-' + itemId);
            if (list) {
                const entry = document.createElement('div');
                entry.className = 'hc-doc-item';
                entry.id = 'doc-entry-' + data.doc_id;
                entry.innerHTML = `<i class="fas fa-file-lines" style="color:var(--primary-500);font-size:.85rem;flex-shrink:0;"></i>
                    <a href="${BASE_URL_JS}uploads/${escapeHtml(data.file_path)}" target="_blank" rel="noopener">${escapeHtml(data.orig_name)}</a>
                    <span style="color:var(--neutral-400);font-size:.7rem;flex-shrink:0;">${data.uploaded_at}</span>
                    <button onclick="deleteDocument(${data.doc_id},${itemId})" title="Remove" style="background:none;border:none;cursor:pointer;color:#dc2626;padding:0 2px;font-size:.8rem;"><i class="fas fa-trash-can"></i></button>`;
                list.appendChild(entry);
            }
            setItemState(itemId, true, false);
            applyScores(data);
            showToast('Document uploaded: ' + escapeHtml(data.orig_name));
        } else {
            showToast(data.message || 'Upload failed.', true);
        }
    } catch {
        showToast('Network error — please try again.', true);
    } finally {
        if (spinner) spinner.style.display = 'none';
        if (zone)    zone.style.borderColor = '';
        input.value = '';
    }
}

// ── Delete document ───────────────────────────────────────────────────────
async function deleteDocument(docId, itemId) {
    if (!confirm('Remove this document?')) return;
    const fd = new FormData();
    fd.append('action', 'delete_document');
    fd.append('doc_id', docId);
    try {
        const res  = await fetch(HACERA_API, { method: 'POST', body: fd });
        const data = await res.json();
        if (data.success) {
            const entry = document.getElementById('doc-entry-' + docId);
            if (entry) entry.remove();
            const list = document.getElementById('doc-list-' + itemId);
            if (list && list.querySelectorAll('.hc-doc-item').length === 0) setItemState(itemId, false, false);
            applyScores(data);
            showToast('Document removed.');
        } else {
            showToast(data.message || 'Delete failed.', true);
        }
    } catch {
        showToast('Network error — please try again.', true);
    }
}

// ── Generate PDF ──────────────────────────────────────────────────────────
async function generatePDF(purchaseId = 0) {
    if (purchaseId > 0) {
        window.location.assign(HACERA_API + '?action=generate_pdf&purchase_id=' + encodeURIComponent(purchaseId));
        return;
    }
    const btn = document.getElementById('pdfBtn');
    const original = btn.innerHTML;
    btn.innerHTML = '<span class="hc-spinner"></span> Preparing secure checkout…';
    btn.disabled = true;
    try {
        const form = new FormData();
        form.append('action', 'start_hacera_report');
        form.append('csrf_token', REVENUE_PAYMENT_CSRF);
        const response = await fetch(REVENUE_PAYMENT_API, { method: 'POST', body: form });
        const data = await response.json();
        if (!data.success) { showToast(data.message || 'Could not start report payment.', true); return; }
        if (data.ready && data.purchase_id) {
            window.location.assign(BASE_URL_JS + 'dashboard/business_owner/hacera.php?payment=verified');
            return;
        }
        if (data.checkout_url) { window.location.assign(data.checkout_url); return; }
        showToast('Payment request created. Wait for verification before downloading.');
    } catch (error) {
        showToast('Network error — please try again.', true);
    } finally {
        btn.innerHTML = original;
        btn.disabled = false;
    }
}
function unlockPremiumResults() {
    generatePDF();
}
// ── Publish score ─────────────────────────────────────────────────────────
async function publishScore() {
    const btn  = document.getElementById('publishBtn');
    const orig = btn.innerHTML;
    btn.innerHTML = '<span class="hc-spinner" style="border-top-color:#065f46;border-color:#bbf7d0;"></span> Publishing…';
    btn.disabled  = true;
    const fd = new FormData();
    fd.append('action', 'publish_score');
    try {
        const res  = await fetch(HACERA_API, { method: 'POST', body: fd });
        const data = await res.json();
        if (data.success) {
            const strip = document.getElementById('publishedStrip');
            const atEl  = document.getElementById('publishedAt');
            if (strip) strip.style.display = 'flex';
            if (atEl)  atEl.innerHTML = `Published ${data.published_at} &middot; <strong>${data.band.label} (${data.hrp}%)</strong>`;
            applyScores(data);
            showToast('✓ Readiness score published to your public profile.');
        } else {
            showToast(data.message || 'Publish failed.', true);
        }
    } catch {
        showToast('Network error — please try again.', true);
    } finally {
        btn.innerHTML = orig; btn.disabled = false;
    }
}

// ── Init ──────────────────────────────────────────────────────────────────
// AI Readiness Insights uses its own endpoint and never modifies HaCeRa scores.
let activeAiRunId = null;

function setAiStatus(message, error = false) {
    const el = document.getElementById('aiStatus');
    if (!el) return;
    el.style.display = 'block';
    el.style.background = error ? '#fef2f2' : '#ecfdf5';
    el.style.border = '1px solid ' + (error ? '#fecaca' : '#bbf7d0');
    el.style.color = error ? '#991b1b' : '#166534';
    el.textContent = message;
}

async function aiRequest(action, fields = {}, readOnly = false) {
    let url = HACERA_AI_API;
    const options = { method: readOnly ? 'GET' : 'POST' };
    if (readOnly) {
        url += '?' + new URLSearchParams({ action, ...fields }).toString();
    } else {
        const form = new FormData();
        form.append('action', action);
        form.append('csrf_token', HACERA_AI_CSRF);
        Object.entries(fields).forEach(([key, value]) => form.append(key, value));
        options.body = form;
    }
    const response = await fetch(url, options);
    const data = await response.json().catch(() => ({ success: false, message: 'The AI service returned an invalid response.' }));
    if (!response.ok || !data.success) throw new Error(data.message || 'The AI request failed.');
    return data;
}

function aiPriority(value) {
    return ['critical', 'high', 'medium', 'low'].includes(value) ? value : 'low';
}

function renderAiRun(run) {
    if (!run) return;
    activeAiRunId = Number(run.id || 0) || null;
    const rows = Array.isArray(run.findings) ? run.findings : [];
    const actions = Array.isArray(run.actions) ? run.actions : [];
    const summary = document.getElementById('aiSummary');
    const last = document.getElementById('aiLastAnalysis');
    if (summary) summary.textContent = run.summary || (run.status === 'queued' ? 'Your advisory analysis is queued for processing.' : 'No advisory summary is available.');
    if (last) last.textContent = run.completed_at ? 'Last analysis: ' + run.completed_at + (run.is_outdated ? ' — assessment changed; regenerate recommended.' : '') : 'Analysis status: ' + (run.status || 'queued');

    const findings = document.getElementById('aiFindings');
    if (findings) findings.innerHTML = rows.length ? rows.map(f => {
        const module = MODULES_META[Number(f.module_id)] || {};
        const review = Number(f.requires_expert_review) ? '<span style="font-size:.67rem;color:#7c2d12;font-weight:700;"> Human review required</span>' : '';
        return `<div class="ai-finding"><div style="display:flex;gap:6px;align-items:center;flex-wrap:wrap;"><span class="ai-priority ${aiPriority(f.severity)}">${escapeHtml(f.severity || 'low')}</span><strong style="font-size:.78rem;color:var(--neutral-800);">${escapeHtml(f.title || '')}</strong>${review}</div><div style="font-size:.7rem;color:var(--neutral-500);margin-top:4px;">M${Number(f.module_id)} — ${escapeHtml(module.title || 'Assessment module')} · ${escapeHtml(f.criteria_reference || '')} · ${escapeHtml(f.evidence_status || 'missing_information')}</div><p style="font-size:.76rem;color:var(--neutral-600);line-height:1.5;margin:7px 0 0;">${escapeHtml(f.explanation || '')}</p><p style="font-size:.75rem;color:var(--primary-700);line-height:1.5;margin:7px 0 0;"><strong>Recommended:</strong> ${escapeHtml(f.recommended_corrective_action || '')}</p></div>`;
    }).join('') : '<span>No validated findings were returned. This is not an official compliance decision.</span>';

    const actionEl = document.getElementById('aiActions');
    if (actionEl) actionEl.innerHTML = actions.length ? actions.map(a => {
        const options = ['not_started', 'in_progress', 'resolved_by_owner', 'needs_expert_review'].map(s => `<option value="${s}" ${s === a.action_status ? 'selected' : ''}>${s.replace(/_/g, ' ')}</option>`).join('');
        return `<div class="ai-finding" style="border-left-color:#f59e0b;"><div style="display:flex;gap:6px;justify-content:space-between;align-items:center;flex-wrap:wrap;"><strong style="font-size:.78rem;color:var(--neutral-800);">${escapeHtml(a.title || '')}</strong><span class="ai-priority ${aiPriority(a.priority)}">${escapeHtml(a.priority || 'low')}</span></div><p style="font-size:.75rem;color:var(--neutral-600);line-height:1.5;margin:6px 0;">${escapeHtml(a.description || '')}</p><label style="font-size:.7rem;color:var(--neutral-500);">Owner status <select onchange="updateAiAction(${Number(a.id)}, this.value)" style="margin-left:4px;padding:4px;border:1px solid var(--neutral-300);border-radius:5px;">${options}</select></label>${Number(a.requires_expert_review) ? '<div style="font-size:.68rem;color:#b45309;margin-top:6px;">Needs qualified human review before closure.</div>' : ''}</div>`;
    }).join('') : '<span>No corrective actions are available.</span>';

    const evidence = document.getElementById('aiEvidence');
    if (evidence) {
        const needsEvidence = rows.filter(f => f.evidence_status === 'missing_information' || f.finding_type === 'evidence_recommendation' || f.finding_type === 'missing_information');
        evidence.innerHTML = needsEvidence.length ? needsEvidence.map(f => `<div class="ai-finding" style="border-left-color:#2563eb;"><strong style="font-size:.77rem;">${escapeHtml(f.title || '')}</strong><div style="font-size:.74rem;color:var(--neutral-600);margin-top:5px;">${escapeHtml(f.recommended_corrective_action || '')}</div><div style="font-size:.68rem;color:var(--neutral-500);margin-top:5px;">Use the relevant HaCeRa upload location. Uploaded evidence remains unverified pending human review.</div></div>`).join('') : '<span>No additional evidence recommendations were returned.</span>';
    }
}

function renderAiHistory(history) {
    const target = document.getElementById('aiHistory');
    if (!target) return;
    target.innerHTML = history.length ? history.map(row => `<div class="ai-history-row"><div><strong style="font-size:.76rem;color:var(--neutral-700);">${escapeHtml(row.completed_at || row.requested_at || 'Pending')}</strong><div style="font-size:.7rem;color:var(--neutral-500);margin-top:2px;">${escapeHtml(row.status || '')} · ${Number(row.validated_finding_count || 0)} validated findings${row.is_outdated ? ' · outdated' : ''}</div></div><button type="button" class="btn btn-outline" style="padding:4px 8px;font-size:.7rem;" onclick="openAiRun(${Number(row.id)})">View</button></div>`).join('') : '<span>No previous AI analyses.</span>';
}

async function openAiRun(runId, notify = true) {
    if (!runId) return;
    try {
        const data = await aiRequest('results', { run_id: runId }, true);
        renderAiRun(data.run);
        if (notify) setAiStatus(data.run.is_outdated ? 'This report is outdated because the assessment has changed. Regenerate insights for current responses.' : 'AI advisory report loaded.');
    } catch (error) {
        if (notify) setAiStatus(error.message, true);
    }
}

async function loadAiHistory() {
    if (!HACERA_AI_ENABLED) return;
    try {
        const data = await aiRequest('history', {}, true);
        renderAiHistory(data.history || []);
        const completed = (data.history || []).find(row => row.status === 'completed');
        if (completed) await openAiRun(Number(completed.id), false);
    } catch (error) {
        setAiStatus(error.message, true);
    }
}

async function pollAiRun(runId) {
    for (let attempt = 0; attempt < 15; attempt++) {
        await new Promise(resolve => setTimeout(resolve, 2000));
        const data = await aiRequest('status', { run_id: runId }, true);
        renderAiRun(data.run);
        if (data.run.status === 'completed') return true;
        if (['failed', 'expired'].includes(data.run.status)) throw new Error(data.run.error_message || 'AI analysis did not complete.');
    }
    setAiStatus('The analysis is still queued. You may return to this page later to view it.');
    return false;
}

async function generateAiInsights() {
    const button = document.getElementById('aiGenerateBtn');
    if (!button) return;
    const original = button.innerHTML;
    button.disabled = true;
    button.innerHTML = '<span class="hc-spinner"></span> Processing…';
    setAiStatus('Preparing a read-only assessment context. No score or certification record will be changed.');
    try {
        const data = await aiRequest('generate');
        if (data.run) renderAiRun(data.run);
        let completed = data.run?.status === 'completed';
        if (data.queued || data.run?.status === 'queued' || data.run?.status === 'processing') {
            setAiStatus('AI analysis is queued. This page will check for the completed advisory report.');
            completed = await pollAiRun(Number(data.run?.id));
        }
        if (completed) setAiStatus(data.deduplicated ? 'A current advisory report already exists and was reused.' : 'AI advisory report generated and validated.');
        await loadAiHistory();
    } catch (error) {
        setAiStatus(error.message, true);
    } finally {
        button.disabled = false;
        button.innerHTML = activeAiRunId ? '<i class="fas fa-rotate"></i> Regenerate Insights' : original;
    }
}

async function updateAiAction(actionId, actionStatus) {
    try {
        await aiRequest('update_action', { action_id: actionId, action_status: actionStatus });
        setAiStatus('Corrective action status updated.');
        if (activeAiRunId) await openAiRun(activeAiRunId, false);
    } catch (error) {
        setAiStatus(error.message, true);
    }
}

document.addEventListener('DOMContentLoaded', () => {
    goToStep(INITIAL_STEP, true);

    // Close results confirm modal on backdrop click
    document.getElementById('resultsConfirmOverlay').addEventListener('click', function(e) {
        if (e.target === this) closeResultsConfirm();
    });
    if (HACERA_PREMIUM_UNLOCKED) loadAiHistory();
});
</script>

<?php require_once __DIR__ . '/../../includes/footer.php'; ?>
