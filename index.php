<?php
require_once __DIR__ . '/config/app.php';

if (isLoggedIn()) {
    header('Location: ' . (hasApprovedRole() ? getDashboardUrl($_SESSION['role_id']) : BASE_URL . 'auth/apply_role.php'));
    exit();
}

$base = rtrim(BASE_URL, '/');

// ── Davao City directory metrics ─────────────────────────────────────────────
$dc_metrics    = null;
$dc_businesses = ['halal_certified' => [], 'muslim_friendly' => [], 'non_pork' => []];

// ── Pull businesses from halal_restaurants (the live Halal Keeps registry) ───
// city comes from business_branches.address_city (halal_restaurants.city is unpopulated)
$hk_rows = $conn->query("
    SELECT
        hr.restaurant_name  AS business_name,
        hr.address,
        hr.image_path,
        COALESCE(bb.address_city, hr.city, 'Davao City') AS city,
        COALESCE(
            (SELECT ba.halal_classification
             FROM   business_applications ba
             WHERE  ba.user_id = hr.business_owner_id
               AND  ba.status  = 'approved'
             ORDER  BY ba.created_at DESC LIMIT 1),
            'Halal Certified'
        ) AS halal_classification
    FROM halal_restaurants hr
    LEFT JOIN business_applications ba2
           ON ba2.user_id   = hr.business_owner_id
          AND ba2.status    = 'approved'
    LEFT JOIN business_branches bb
           ON bb.application_id = ba2.id
          AND bb.is_main = 1
    WHERE hr.is_active = 1
    ORDER BY hr.restaurant_name ASC
")?->fetch_all(MYSQLI_ASSOC) ?? [];

// Map halal_classification values → directory category keys
$class_map = [
    'Halal Certified'  => 'halal_certified',
    'Halal'            => 'halal_certified',
    'Muslim Friendly'  => 'muslim_friendly',
    'Muslim-Friendly'  => 'muslim_friendly',
    'Non-Pork'         => 'non_pork',
    'Non Pork'         => 'non_pork',
];

foreach ($hk_rows as $r) {
    $cat = $class_map[$r['halal_classification']] ?? 'halal_certified';
    $dc_businesses[$cat][] = [
        'business_name' => $r['business_name'],
        'address'       => $r['address'],
        'image_path'    => $r['image_path'],
        'is_halal_keeps'=> 1, // all entries here are Halal Keeps registered
    ];
}

// Live counts from the actual registered businesses
$hk_hc   = count($dc_businesses['halal_certified']);
$hk_mf   = count($dc_businesses['muslim_friendly']);
$hk_np   = count($dc_businesses['non_pork']);
$hk_comp = $hk_hc + $hk_mf + $hk_np;

// Davao City-specific counts for the detail card
// Match both "Davao City" and "City of Davao" (both appear in the data)
$davao_hc = 0; $davao_mf = 0; $davao_np = 0;
foreach ($hk_rows as $r) {
    $c = strtolower(trim($r['city'] ?? ''));
    if (str_contains($c, 'davao')) {
        $cat = $class_map[$r['halal_classification']] ?? 'halal_certified';
        if ($cat === 'halal_certified') $davao_hc++;
        elseif ($cat === 'muslim_friendly') $davao_mf++;
        elseif ($cat === 'non_pork') $davao_np++;
    }
}
$davao_comp = $davao_hc + $davao_mf + $davao_np;

// All numbers are sourced exclusively from halal_restaurants (Halal Keeps registry).
$tf = max($hk_comp, 1);

// Formulas per spec:
//   Compliance Index         = Total Compliant ÷ Total Food Businesses
//   Halal-Compliant Score    = (HC + MF + NP) ÷ Total Compliant
//   Halal-Certified Score    = Halal-Certified ÷ Total Compliant
//   Muslim-Friendly Score    = Muslim-Friendly ÷ Total Compliant
//   Non-Pork Score           = Non-Pork ÷ Total Compliant
$dc_metrics = [
    // Platform-wide totals (all registered cities) — for compliance metric cards
    'halal_certified'       => $hk_hc,
    'muslim_friendly'       => $hk_mf,
    'non_pork'              => $hk_np,
    'total_compliant'       => $hk_comp,
    'total_food_biz'        => $tf,
    'compliance_index'      => $tf      > 0 ? round(($hk_comp / $tf)    * 100, 1) : 0,
    'halal_compliant_score' => $hk_comp > 0 ? 100.0 : 0,
    'halal_certified_score' => $hk_comp > 0 ? round(($hk_hc / $hk_comp) * 100, 1) : 0,
    'muslim_friendly_score' => $hk_comp > 0 ? round(($hk_mf / $hk_comp) * 100, 1) : 0,
    'non_pork_score'        => $hk_comp > 0 ? round(($hk_np / $hk_comp) * 100, 1) : 0,
    // Davao City-specific counts — for the map detail card
    'davao_halal_certified' => $davao_hc,
    'davao_muslim_friendly' => $davao_mf,
    'davao_non_pork'        => $davao_np,
    'davao_total'           => $davao_comp,
];

// Region XI map panel — live counts from halal_restaurants only
// city comes from business_branches.address_city since halal_restaurants.city is unpopulated
$xi_live_rows = $conn->query("
    SELECT
        COALESCE(bb.address_city, hr.city, 'Unknown')                   AS city,
        COUNT(*)                                                          AS total_hk,
        SUM(CASE WHEN COALESCE(
            (SELECT ba2.halal_classification FROM business_applications ba2
             WHERE ba2.user_id = hr.business_owner_id AND ba2.status = 'approved'
             ORDER BY ba2.created_at DESC LIMIT 1), 'Halal Certified')
            IN ('Halal Certified','Halal') THEN 1 ELSE 0 END)            AS halal_certified,
        SUM(CASE WHEN COALESCE(
            (SELECT ba2.halal_classification FROM business_applications ba2
             WHERE ba2.user_id = hr.business_owner_id AND ba2.status = 'approved'
             ORDER BY ba2.created_at DESC LIMIT 1), '')
            IN ('Muslim Friendly','Muslim-Friendly') THEN 1 ELSE 0 END)  AS muslim_friendly,
        SUM(CASE WHEN COALESCE(
            (SELECT ba2.halal_classification FROM business_applications ba2
             WHERE ba2.user_id = hr.business_owner_id AND ba2.status = 'approved'
             ORDER BY ba2.created_at DESC LIMIT 1), '')
            IN ('Non-Pork','Non Pork') THEN 1 ELSE 0 END)                AS non_pork
    FROM halal_restaurants hr
    LEFT JOIN business_applications ba
           ON ba.user_id = hr.business_owner_id
          AND ba.status  = 'approved'
    LEFT JOIN business_branches bb
           ON bb.application_id = ba.id
          AND bb.is_main = 1
    WHERE hr.is_active = 1
    GROUP BY COALESCE(bb.address_city, hr.city, 'Unknown')
    ORDER BY total_hk DESC, city ASC
")?->fetch_all(MYSQLI_ASSOC) ?? [];

// Build xi_rows in the same shape the map panel template expects,
// but using only real Halal Keeps data. Province/region are not on
// halal_restaurants so we derive what we can and mark all as is_live=1.
$xi_rows = array_map(fn($row) => [
    'city'            => $row['city'],
    'province'        => '',               // not stored on halal_restaurants
    'halal_certified' => (int)$row['halal_certified'],
    'muslim_friendly' => (int)$row['muslim_friendly'],
    'non_pork'        => (int)$row['non_pork'],
    'total_food_biz'  => (int)$row['total_hk'],
    'is_live'         => 1,
], $xi_live_rows);

// ── TPB aggregate scores (v2: ATT + SN + PBC) ───────────────────────────────
$tpb = ['total'=>0,'muslim_pi'=>0,'muslim_pct'=>0,'muslim_att'=>0,'muslim_sn'=>0,'muslim_pbc'=>0,
                   'nm_pi'=>0,    'nm_pct'=>0,    'nm_att'=>0,    'nm_sn'=>0,    'nm_pbc'=>0,
                   'overall_pi'=>0,'overall_pct'=>0,'overall_att'=>0,'overall_sn'=>0,'overall_pbc'=>0];
if ($conn->query("SHOW TABLES LIKE 'tpb_survey_responses'")?->num_rows > 0) {
    $col_chk  = $conn->query("SHOW COLUMNS FROM tpb_survey_responses LIKE 'normalized_pi'");
    $has_norm = ($col_chk && $col_chk->num_rows > 0);
    $pi_col   = $has_norm ? 'normalized_pi' : 'predicted_pi';
    $pbc_col  = $has_norm ? 'mean_pbc' : '0';

    $tpb['total'] = (int)($conn->query("SELECT COUNT(*) AS c FROM tpb_survey_responses WHERE {$pi_col} IS NOT NULL")->fetch_assoc()['c'] ?? 0);
    foreach ($conn->query("SELECT religion,
        ROUND(AVG({$pi_col}),3) AS p, ROUND(((AVG({$pi_col})-1)/4)*100,1) AS pct,
        ROUND(AVG(mean_att),3) AS att, ROUND(AVG(mean_sn),3) AS sn, ROUND(AVG({$pbc_col}),3) AS pbc
        FROM tpb_survey_responses WHERE {$pi_col} IS NOT NULL GROUP BY religion")?->fetch_all(MYSQLI_ASSOC) ?? [] as $tr) {
        if ($tr['religion']==='Muslim') {
            $tpb['muslim_pi']=$tr['p']; $tpb['muslim_pct']=$tr['pct'];
            $tpb['muslim_att']=$tr['att']; $tpb['muslim_sn']=$tr['sn']; $tpb['muslim_pbc']=$tr['pbc'];
        } else {
            $tpb['nm_pi']=$tr['p']; $tpb['nm_pct']=$tr['pct'];
            $tpb['nm_att']=$tr['att']; $tpb['nm_sn']=$tr['sn']; $tpb['nm_pbc']=$tr['pbc'];
        }
    }
    $ov = $conn->query("SELECT ROUND(AVG({$pi_col}),3) AS p, ROUND(((AVG({$pi_col})-1)/4)*100,1) AS pct,
        ROUND(AVG(mean_att),3) AS att, ROUND(AVG(mean_sn),3) AS sn, ROUND(AVG({$pbc_col}),3) AS pbc
        FROM tpb_survey_responses WHERE {$pi_col} IS NOT NULL")?->fetch_assoc();
    $tpb['overall_pi']=$ov['p']??0; $tpb['overall_pct']=$ov['pct']??0;
    $tpb['overall_att']=$ov['att']??0; $tpb['overall_sn']=$ov['sn']??0; $tpb['overall_pbc']=$ov['pbc']??0;
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width,initial-scale=1.0">
<title>Halal Keeps — Halal Food Business Directory</title>
<meta name="description" content="Discover verified Halal, Muslim-Friendly and Non-Pork food establishments in Davao City.">
<script src="https://cdn.tailwindcss.com?plugins=forms,container-queries"></script>
<link href="https://fonts.googleapis.com/css2?family=Plus+Jakarta+Sans:wght@400;600;700;800;900&family=Manrope:wght@400;500;600;700&display=swap" rel="stylesheet">
<link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.0/css/all.min.css">
<script>
tailwind.config={darkMode:'class',theme:{extend:{colors:{primary:'#00452e','primary-container':'#005f41','primary-fixed':'#a4f3cb','primary-fixed-dim':'#89d6b0','on-primary':'#ffffff',secondary:'#006a65',background:'#f8fafb',surface:'#f8fafb','surface-container':'#eceeef','surface-container-low':'#f2f4f5','on-surface':'#191c1d','on-surface-variant':'#3f4943','outline-variant':'#bec9c1'},fontFamily:{'display-lg':['Plus Jakarta Sans'],'headline-md':['Plus Jakarta Sans'],'body-md':['Manrope']},fontSize:{'display-lg':['52px',{lineHeight:'60px',fontWeight:'800',letterSpacing:'-0.02em'}],'headline-md':['24px',{lineHeight:'32px',fontWeight:'600'}],'body-md':['16px',{lineHeight:'24px'}]}}}}
</script>
<style>
.reveal{opacity:0;transform:translateY(28px);transition:opacity .7s ease,transform .7s ease}
.reveal.active{opacity:1;transform:none}
.hover-lift{transition:transform .22s cubic-bezier(.4,0,.2,1),box-shadow .22s}
.hover-lift:hover{transform:translateY(-4px);box-shadow:0 12px 32px rgba(0,0,0,.12)}
.hk-verified{box-shadow:0 0 0 2px #a4f3cb,0 4px 20px rgba(0,95,65,.18)}
.hk-badge{background:linear-gradient(135deg,#005f41,#047857);color:#fff;font-size:.68rem;font-weight:800;letter-spacing:.04em;padding:3px 10px;border-radius:999px;display:inline-flex;align-items:center;gap:5px}
.metric-card{background:#fff;border-radius:16px;padding:20px 24px;border:1px solid #e2e8f0;box-shadow:0 2px 8px rgba(0,0,0,.05);transition:transform .2s,box-shadow .2s}
.metric-card:hover{transform:translateY(-3px);box-shadow:0 8px 24px rgba(0,0,0,.1)}
.biz-card{background:#fff;border-radius:14px;padding:18px 20px;border:1px solid #e2e8f0;transition:all .2s}
.biz-card:hover{border-color:#a7f3d0;box-shadow:0 6px 20px rgba(0,95,65,.1)}
#map-tooltip{pointer-events:none;z-index:50;max-width:260px}
.dir-pill{padding:7px 18px;border-radius:999px;font-size:.82rem;font-weight:700;cursor:pointer;transition:all .18s;border:1.5px solid #e2e8f0;background:#fff;color:#64748b}
.dir-pill.active{background:#005f41;border-color:#005f41;color:#fff}
.cat-halal{background:#dcfce7;color:#15803d;border-color:#bbf7d0}
.cat-friendly{background:#dbeafe;color:#1d4ed8;border-color:#bfdbfe}
.cat-nonpork{background:#fef3c7;color:#b45309;border-color:#fde68a}
/* ── Dual-view switcher ── */
.view-switcher{display:flex;gap:5px;background:rgba(255,255,255,.15);backdrop-filter:blur(10px);border-radius:999px;padding:5px;border:1.5px solid rgba(255,255,255,.25)}
.view-btn{display:inline-flex;align-items:center;gap:7px;padding:9px 22px;border-radius:999px;font-size:.85rem;font-weight:700;cursor:pointer;transition:all .22s cubic-bezier(.4,0,.2,1);border:none;background:transparent;color:rgba(255,255,255,.75);white-space:nowrap}
.view-btn.active{background:#fff;color:#00452e;box-shadow:0 3px 12px rgba(0,0,0,.18)}
.view-btn.owner-btn.active{background:linear-gradient(135deg,#fbbf24,#d97706);color:#fff;box-shadow:0 3px 14px rgba(217,119,6,.4)}
.view-panel{display:none}
.view-panel.active{display:block;animation:tabIn .32s ease}
@keyframes tabIn{from{opacity:0;transform:translateY(10px)}to{opacity:1;transform:none}}
/* ── Barometer ── */
.baro-card{background:#fff;border-radius:18px;padding:22px 24px;border:1.5px solid #e2e8f0;transition:transform .2s,box-shadow .2s}
.baro-card:hover{transform:translateY(-3px);box-shadow:0 10px 28px rgba(0,0,0,.09)}
/* ── Owner view ── */
.owner-hero{background:linear-gradient(135deg,#064e3b 0%,#065f46 55%,#047857 100%);border-radius:20px;overflow:hidden;position:relative}
.owner-hero::before{content:'';position:absolute;right:-60px;top:-60px;width:240px;height:240px;border-radius:50%;background:rgba(255,255,255,.05);pointer-events:none}
.teaser-card{border-radius:16px;padding:22px;transition:box-shadow .2s,transform .2s}
.teaser-card:hover{box-shadow:0 8px 24px rgba(0,0,0,.10);transform:translateY(-2px)}
.hacera-card{background:#fff;border:1px solid #e2e8f0;border-radius:12px;padding:18px;text-align:center;transition:border-color .18s,box-shadow .18s}
.hacera-card:hover{border-color:#a7f3d0;box-shadow:0 4px 16px rgba(0,95,65,.09)}
</style>
</head>
<body class="bg-background text-on-surface antialiased overflow-x-hidden" style="font-family:'Manrope',sans-serif">

<!-- NAV -->
<header class="fixed top-0 w-full z-50 bg-white/90 backdrop-blur-md border-b border-gray-200/50 shadow-sm">
  <div class="flex justify-between items-center px-6 py-4 max-w-[1440px] mx-auto">
    <div class="flex items-center gap-8">
      <a href="<?= $base ?>/" class="flex items-center gap-3">
        <img src="<?= $base ?>/assets/images/logo.jpg" alt="Halal Keeps" class="h-10 w-10 rounded-full object-cover">
        <span class="text-xl font-bold text-primary" style="font-family:'Plus Jakarta Sans'">Halal Keeps</span>
      </a>
      <nav class="hidden md:flex gap-6">
        <a href="#directory" class="text-sm font-semibold text-gray-700 hover:text-primary transition-colors">Directory</a>
        <a href="#map"       class="text-sm font-semibold text-gray-700 hover:text-primary transition-colors">Map</a>
        <a href="#demand"    class="text-sm font-semibold text-gray-700 hover:text-primary transition-colors">Demand</a>
        <a href="#about"     class="text-sm font-semibold text-gray-700 hover:text-primary transition-colors">About</a>
      </nav>
    </div>
    <div class="flex items-center gap-3">
      <a href="<?= $base ?>/auth/login.php" class="text-sm font-bold text-primary border border-primary/30 px-5 py-2 rounded-full hover:bg-primary/5 transition-colors">Login</a>
      <a href="<?= $base ?>/auth/register.php" class="hidden sm:inline-flex text-sm font-bold bg-primary text-white px-5 py-2 rounded-full hover:bg-primary-container transition-colors">Sign Up</a>
    </div>
  </div>
</header>

<!-- HERO -->
<section class="relative min-h-[680px] flex items-center pt-20 overflow-hidden">
  <div class="absolute inset-0 -z-10">
    <div class="absolute inset-0 bg-cover bg-center" style="background-image:url('https://images.unsplash.com/photo-1555939594-58d7cb561ad1?w=1800&auto=format&fit=crop&q=85');background-position:center 45%"></div>
    <div class="absolute inset-0" style="background:linear-gradient(135deg,rgba(0,33,20,.84) 0%,rgba(0,69,46,.74) 50%,rgba(4,120,87,.57) 100%)"></div>
    <div class="absolute inset-0" style="background-image:radial-gradient(rgba(255,255,255,.06) 1px,transparent 1px);background-size:28px 28px"></div>
  </div>
  <div class="max-w-[1440px] mx-auto px-6 py-16 w-full">
    <!-- Dual-view switcher -->
    <div class="flex justify-center mb-12">
      <div class="view-switcher" role="tablist">
        <button class="view-btn active" id="btn-customer" onclick="switchView('customer')" role="tab" aria-selected="true" aria-controls="view-customer">
          <i class="fas fa-user"></i> Customer View
        </button>
        <button class="view-btn owner-btn" id="btn-owner" onclick="switchView('owner')" role="tab" aria-selected="false" aria-controls="view-owner">
          <i class="fas fa-store"></i> Business Owner View
        </button>
      </div>
    </div>
    <!-- Customer hero -->
    <div id="hero-customer" class="max-w-2xl">
      <div class="inline-flex items-center gap-2 bg-white/15 backdrop-blur-sm border border-white/25 text-white text-xs font-bold px-4 py-2 rounded-full mb-6 uppercase tracking-wide">
        <span class="w-2 h-2 rounded-full bg-emerald-400 animate-pulse"></span> Live Data — Davao City, Region XI
      </div>
      <h1 class="text-white mb-5 leading-tight" style="font-family:'Plus Jakarta Sans';font-size:clamp(36px,5vw,52px);font-weight:800;letter-spacing:-.02em">
        Find Verified<br><span style="color:#a4f3cb">Halal Eateries</span><br>Near You
      </h1>
      <p class="text-white/85 text-lg leading-relaxed mb-8 max-w-xl">The Philippines' first live Halal food business directory. Discover certified restaurants, Muslim-friendly cafes, and pork-free dining across Davao City.</p>
      <div class="flex flex-wrap gap-4 mb-10">
        <a href="#directory" class="inline-flex items-center gap-2 bg-white text-primary font-bold px-7 py-3.5 rounded-full shadow-lg hover:bg-green-50 transition-all hover-lift"><i class="fas fa-search"></i> Browse Directory</a>
        <a href="<?= $base ?>/auth/register.php" class="inline-flex items-center gap-2 bg-transparent text-white font-bold px-7 py-3.5 rounded-full border-2 border-white/60 hover:bg-white/10 transition-all"><i class="fas fa-user-plus"></i> Create Account</a>
      </div>
      <?php if ($dc_metrics): ?>
      <div class="flex flex-wrap gap-3">
        <?php foreach ([
          [$dc_metrics['davao_halal_certified'], 'Halal Certified'],
          [$dc_metrics['davao_muslim_friendly'], 'Muslim-Friendly'],
          [$dc_metrics['davao_non_pork'],        'Non-Pork'],
          [$dc_metrics['davao_total'],           'On Halal Keeps'],
        ] as [$n,$l]): ?>
        <div class="bg-white/15 backdrop-blur-sm border border-white/20 rounded-xl px-5 py-3 text-white">
          <div class="text-2xl font-black"><?= $n ?></div>
          <div class="text-xs font-semibold text-white/70 mt-0.5"><?= $l ?></div>
        </div>
        <?php endforeach; ?>
      </div>
      <?php endif; ?>
    </div>
    <!-- Owner hero -->
    <div id="hero-owner" class="max-w-2xl" style="display:none">
      <div class="inline-flex items-center gap-2 bg-white/15 backdrop-blur-sm border border-white/25 text-white text-xs font-bold px-4 py-2 rounded-full mb-6 uppercase tracking-wide">
        <span class="w-2 h-2 rounded-full bg-amber-400 animate-pulse"></span> Halal Keeps — Business Platform
      </div>
      <h1 class="text-white mb-5 leading-tight" style="font-family:'Plus Jakarta Sans';font-size:clamp(36px,5vw,52px);font-weight:800;letter-spacing:-.02em">
        Grow Your<br><span style="color:#fcd34d">Halal Business</span><br>with Data
      </h1>
      <p class="text-white/85 text-lg leading-relaxed mb-8 max-w-xl">List your restaurant, unlock consumer demand analytics, and earn your <strong style="color:#a4f3cb">Halal Keeps Verified</strong> badge — the trust signal that converts browsers into loyal diners.</p>
      <div class="flex flex-wrap gap-4 mb-10">
        <a href="<?= $base ?>/auth/register.php" class="inline-flex items-center gap-2 bg-white text-primary font-bold px-7 py-3.5 rounded-full shadow-lg hover:bg-green-50 transition-all hover-lift"><i class="fas fa-store"></i> Register My Business</a>
        <a href="#owner-demand" class="inline-flex items-center gap-2 bg-transparent text-white font-bold px-7 py-3.5 rounded-full border-2 border-white/60 hover:bg-white/10 transition-all"><i class="fas fa-chart-bar"></i> See Analytics Preview</a>
      </div>
      <div class="flex flex-wrap gap-3">
        <?php foreach (['Demand Analytics','HaCeRa Assessment','Halal Keeps Badge','Free to Register'] as $f): ?>
        <div class="bg-white/15 backdrop-blur-sm border border-white/20 rounded-xl px-4 py-2.5 text-white text-sm font-semibold flex items-center gap-2"><span style="color:#a4f3cb;font-weight:900">✓</span><?= $f ?></div>
        <?php endforeach; ?>
      </div>
    </div>
  </div>
</section>

<!-- ════════════════════════════════════════════════════
     TAB 1 — CUSTOMER VIEW
════════════════════════════════════════════════════ -->
<div id="view-customer" class="view-panel active" role="tabpanel" aria-labelledby="btn-customer">

<!-- Compliance Metrics -->
<?php if ($dc_metrics): ?>
<section id="metrics" class="py-14 px-6 bg-white border-b border-gray-100 reveal active">
  <div class="max-w-[1440px] mx-auto">
    <div class="text-center mb-10">
      <div class="inline-flex items-center gap-2 text-xs font-bold text-primary uppercase tracking-widest bg-green-50 px-4 py-1.5 rounded-full mb-3">
        <i class="fas fa-chart-pie text-xs"></i> Davao City — Compliance Insights
      </div>
      <h2 class="text-2xl font-bold text-gray-900" style="font-family:'Plus Jakarta Sans'">Halal Compliance Index</h2>
      <p class="text-gray-500 text-sm mt-1">
        Based on <?= number_format($dc_metrics['total_compliant']) ?> Halal Keeps-registered establishments
        &nbsp;·&nbsp; <?= number_format($dc_metrics['total_food_biz']) ?> total food businesses in Davao City
      </p>
    </div>

    <div class="grid grid-cols-2 md:grid-cols-5 gap-5">
      <?php foreach ([
        [$dc_metrics['compliance_index'],      '#00452e', 'Compliance Index',        'Total Compliant ÷ Total Food Businesses'],
        [$dc_metrics['halal_compliant_score'],  '#0891b2', 'Halal-Compliant Score',   '(Halal-Certified + Muslim-Friendly + Non-Pork) ÷ Total Compliant'],
        [$dc_metrics['halal_certified_score'],  '#047857', 'Halal-Certified Score',   'Halal-Certified ÷ Total Compliant'],
        [$dc_metrics['muslim_friendly_score'],  '#1d4ed8', 'Muslim-Friendly Score',   'Muslim-Friendly ÷ Total Compliant'],
        [$dc_metrics['non_pork_score'],         '#b45309', 'Non-Pork Score',          'Non-Pork ÷ Total Compliant'],
      ] as [$val, $color, $label, $note]): ?>
      <div class="metric-card text-center">
        <div class="text-4xl font-black mb-1" style="color:<?= $color ?>;font-family:'Plus Jakarta Sans'"><?= $val ?>%</div>
        <div class="text-sm font-bold text-gray-800 mb-1"><?= $label ?></div>
        <div class="text-xs text-gray-400 leading-snug"><?= $note ?></div>
        <div class="mt-3 h-1.5 bg-gray-100 rounded-full overflow-hidden">
          <div class="h-full rounded-full" style="width:<?= min($val,100) ?>%;background:<?= $color ?>"></div>
        </div>
      </div>
      <?php endforeach; ?>
    </div>

    <!-- Formula footnote -->
    <?php $tc = $dc_metrics['total_compliant']; $tf2 = $dc_metrics['total_food_biz']; ?>
    <div class="text-center text-xs text-gray-400 mt-6 space-y-1 leading-relaxed">
      <p>
        <strong>Compliance Index</strong> = <?= $tc ?> compliant ÷ <?= number_format($tf2) ?> total food biz × 100 = <strong><?= $dc_metrics['compliance_index'] ?>%</strong>
        &nbsp;|&nbsp;
        <strong>Halal-Certified</strong> = <?= $dc_metrics['halal_certified'] ?> ÷ <?= $tc ?> × 100 = <strong><?= $dc_metrics['halal_certified_score'] ?>%</strong>
      </p>
      <p>
        <strong>Muslim-Friendly</strong> = <?= $dc_metrics['muslim_friendly'] ?> ÷ <?= $tc ?> × 100 = <strong><?= $dc_metrics['muslim_friendly_score'] ?>%</strong>
        &nbsp;|&nbsp;
        <strong>Non-Pork</strong> = <?= $dc_metrics['non_pork'] ?> ÷ <?= $tc ?> × 100 = <strong><?= $dc_metrics['non_pork_score'] ?>%</strong>
      </p>
    </div>
  </div>
</section>
<?php endif; ?>

<!-- Interactive Map -->
<section id="map" class="py-16 px-6 bg-gray-50 reveal">
  <div class="max-w-[1440px] mx-auto">
    <div class="text-center mb-10">
      <div class="inline-flex items-center gap-2 text-xs font-bold text-primary uppercase tracking-widest bg-green-50 px-4 py-1.5 rounded-full mb-3"><i class="fas fa-map text-xs"></i> Coverage Map</div>
      <h2 class="text-2xl font-bold text-gray-900 mb-2" style="font-family:'Plus Jakarta Sans'">Halal Food Coverage — Philippines</h2>
      <p class="text-gray-500 text-sm">Live data active for <strong>Davao City (Region XI)</strong>. Hover regions for status.</p>
    </div>
    <div class="grid grid-cols-1 lg:grid-cols-2 gap-10 items-start">
      <div class="relative">
        <div id="map-tooltip" class="absolute hidden bg-white border border-gray-200 rounded-xl shadow-xl p-4 text-sm" style="min-width:210px;z-index:50;pointer-events:none"></div>
        <div id="ph-map-container" class="w-full" style="max-width:480px;margin:0 auto"><svg id="ph-map" class="w-full h-auto" style="display:block"></svg></div>
        <div class="flex flex-wrap gap-4 mt-4 text-xs font-semibold justify-center">
          <span class="flex items-center gap-1.5"><span class="w-3 h-3 rounded-sm inline-block" style="background:#059669"></span>Active (live)</span>
          <span class="flex items-center gap-1.5"><span class="w-3 h-3 rounded-sm inline-block" style="background:#d1fae5;border:1px solid #6ee7b7"></span>Hover for info</span>
          <span class="flex items-center gap-1.5"><span class="w-3 h-3 rounded-sm inline-block" style="background:#e5e7eb"></span>Coming soon</span>
        </div>
      </div>
      <div class="space-y-5">
        <div class="bg-white rounded-2xl border border-gray-200 shadow-sm overflow-hidden">
          <div class="bg-gradient-to-r from-primary to-primary-container px-6 py-4">
            <div class="flex items-center justify-between">
              <div><div class="text-xs font-bold text-emerald-200 uppercase tracking-widest mb-1">Region XI — Davao Region</div><h3 class="text-white font-bold text-lg"><?= count($xi_rows) ?> Registered <?= count($xi_rows) === 1 ? 'City' : 'Cities' ?></h3></div>
              <div class="bg-white/20 rounded-xl px-4 py-2 text-center"><div class="text-white font-black text-2xl"><?= array_sum(array_column($xi_rows,'halal_certified')) + array_sum(array_column($xi_rows,'muslim_friendly')) + array_sum(array_column($xi_rows,'non_pork')) ?></div><div class="text-emerald-200 text-xs font-semibold">On Halal Keeps</div></div>
            </div>
          </div>
          <div class="divide-y divide-gray-100">
            <?php foreach ($xi_rows as $xr):
              $xcomp = $xr['halal_certified'] + $xr['muslim_friendly'] + $xr['non_pork'];
              $xci   = $xcomp > 0 ? round(($xr['halal_certified'] / $xcomp) * 100) : 0;
            ?>
            <div class="flex items-center justify-between px-6 py-3.5 hover:bg-gray-50 transition-colors cursor-pointer" onclick="showDavaoDetail()">
              <div class="flex items-center gap-3">
                <span class="w-2 h-2 rounded-full flex-shrink-0 bg-emerald-500"></span>
                <div>
                  <div class="font-semibold text-sm"><?= htmlspecialchars($xr['city']) ?></div>
                  <div class="text-xs text-gray-400">Halal Keeps Registered</div>
                </div>
              </div>
              <div class="text-right">
                <div class="font-bold text-sm text-primary"><?= $xcomp ?> Listed</div>
                <div class="text-xs text-gray-400"><?= $xr['halal_certified'] ?> Halal · <?= $xci ?>% HC</div>
              </div>
            </div>
            <?php endforeach; ?>
          </div>
        </div>
        <div id="davao-detail-card" class="bg-white rounded-2xl border-2 border-emerald-200 shadow-sm overflow-hidden">
          <div class="bg-emerald-50 px-5 py-3 flex items-center gap-2 border-b border-emerald-100">
            <span class="w-2.5 h-2.5 rounded-full bg-emerald-500"></span>
            <span class="font-bold text-sm text-emerald-800">Davao City — Live Data Active</span>
            <span class="ml-auto hk-badge"><img src="<?= $base ?>/assets/images/logo.jpg" class="w-4 h-4 rounded-full object-cover"> Halal Keeps</span>
          </div>
          <?php if ($dc_metrics): ?>
          <div class="p-5 grid grid-cols-2 gap-4">
            <?php foreach ([
              [$dc_metrics['davao_halal_certified'], 'Halal Certified',   '#ecfdf5','#15803d'],
              [$dc_metrics['davao_muslim_friendly'], 'Muslim-Friendly',   '#eff6ff','#1d4ed8'],
              [$dc_metrics['davao_non_pork'],        'Non-Pork',          '#fef3c7','#b45309'],
              [$dc_metrics['davao_total'],           'On Halal Keeps',    '#f9fafb','#374151'],
            ] as [$n,$l,$bg,$tc]): ?>
            <div class="text-center rounded-xl p-3" style="background:<?= $bg ?>">
              <div class="text-2xl font-black" style="color:<?= $tc ?>;font-family:'Plus Jakarta Sans'"><?= $n ?></div>
              <div class="text-xs font-semibold mt-0.5" style="color:<?= $tc ?>"><?= $l ?></div>
            </div>
            <?php endforeach; ?>
          </div>
          <div class="px-5 pb-4"><a href="#directory" class="block text-center text-xs font-bold text-white bg-primary rounded-lg py-2 hover:bg-primary-container transition-colors">View Directory →</a></div>
          <?php else: ?><div class="p-5 text-sm text-gray-400">Run the seed SQL to populate live data.</div><?php endif; ?>
        </div>
      </div>
    </div>
  </div>
</section>

<!-- Live Demand Barometer -->
<section id="demand" class="py-14 px-6 bg-white reveal">
  <div class="max-w-[1440px] mx-auto">
    <div class="text-center mb-10">
      <div class="inline-flex items-center gap-2 text-xs font-bold text-primary uppercase tracking-widest bg-green-50 px-4 py-1.5 rounded-full mb-3">
        <i class="fas fa-chart-line text-xs"></i> Consumer Demand Study
      </div>
      <h2 class="text-2xl font-bold text-gray-900 mb-2" style="font-family:'Plus Jakarta Sans'">Live Purchase Intention Barometer</h2>
      <p class="text-gray-500 text-sm">
        Theory of Planned Behavior &mdash;
        <strong>PI&nbsp;=&nbsp;Mean(ATT)&nbsp;+&nbsp;Mean(SN)&nbsp;+&nbsp;Mean(PBC)</strong>
        &nbsp;·&nbsp; <?= number_format($tpb['total']) ?> respondents
      </p>
    </div>

    <?php if ($tpb['total']>0): ?>

    <!-- ── 3 PI score cards ──────────────────────────────────────────────── -->
    <div class="grid grid-cols-1 md:grid-cols-3 gap-6 mb-8">
      <?php foreach ([
        ['Muslim Purchase Intention','PI<sub>Muslim</sub>',$tpb['muslim_pi'],$tpb['muslim_pct'],'#1d4ed8','#eff6ff','#bfdbfe','fa-moon',$tpb['muslim_att'],$tpb['muslim_sn'],$tpb['muslim_pbc']],
        ['Non-Muslim Purchase Intention','PI<sub>Non-Muslim</sub>',$tpb['nm_pi'],$tpb['nm_pct'],'#7c3aed','#f5f3ff','#ddd6fe','fa-users',$tpb['nm_att'],$tpb['nm_sn'],$tpb['nm_pbc']],
        ['Overall Market Demand','PI<sub>Overall</sub>',$tpb['overall_pi'],$tpb['overall_pct'],'#047857','#f0fdf4','#bbf7d0','fa-globe-asia',$tpb['overall_att'],$tpb['overall_sn'],$tpb['overall_pbc']],
      ] as [$title,$sub,$pi,$pct,$color,$bg,$border,$icon,$att,$sn,$pbc]):
        $bw     = min(round(($pi/5)*100),100);
        $intent = match(true){$pi>=4.5=>'Very High',$pi>=3.5=>'High',$pi>=2.5=>'Moderate',$pi>=1.5=>'Low',default=>'—'};
      ?>
      <div class="baro-card" style="background:<?= $bg ?>;border-color:<?= $border ?>">
        <!-- Card header -->
        <div class="flex items-center gap-3 mb-4">
          <div style="width:36px;height:36px;border-radius:10px;background:<?= $color ?>;display:flex;align-items:center;justify-content:center;flex-shrink:0">
            <i class="fas <?= $icon ?>" style="color:#fff;font-size:.88rem"></i>
          </div>
          <div>
            <div class="font-bold text-sm leading-snug"><?= $title ?></div>
            <div class="text-xs text-gray-400"><?= $sub ?></div>
          </div>
        </div>
        <?php if ($pi>0): ?>
        <!-- PI score + bar -->
        <div class="flex items-baseline gap-2 mb-3">
          <span class="text-4xl font-black" style="color:<?= $color ?>;font-family:'Plus Jakarta Sans'"><?= number_format($pi,2) ?></span>
          <span class="text-gray-400 text-sm">/ 5.00</span>
          <span class="ml-auto font-bold px-2.5 py-0.5 rounded-full text-white text-xs" style="background:<?= $color ?>"><?= $intent ?></span>
        </div>
        <div class="h-2 rounded-full overflow-hidden mb-4" style="background:rgba(0,0,0,.08)">
          <div class="h-full rounded-full" style="background:<?= $color ?>;width:<?= $bw ?>%;transition:width 1.2s ease"></div>
        </div>
        <!-- 3-construct mini breakdown -->
        <div style="border-top:1px solid <?= $border ?>;padding-top:10px;">
          <div class="text-xs font-bold mb-2" style="color:<?= $color ?>;text-transform:uppercase;letter-spacing:.05em">Construct Breakdown</div>
          <?php foreach ([
            ['ATT','fa-heart',$att],
            ['SN', 'fa-users',$sn],
            ['PBC','fa-hand-pointer',$pbc],
          ] as [$cname,$cico,$cval]):
            $cbw = $cval > 0 ? min(round(($cval/5)*100),100) : 0;
          ?>
          <div style="margin-bottom:5px">
            <div class="flex justify-between text-xs mb-1">
              <span class="flex items-center gap-1 font-semibold text-gray-600">
                <i class="fas <?= $cico ?>" style="font-size:.65rem;color:<?= $color ?>"></i><?= $cname ?>
              </span>
              <span class="font-bold" style="color:<?= $color ?>"><?= $cval>0?number_format($cval,2):'—' ?></span>
            </div>
            <div class="h-1.5 rounded-full overflow-hidden" style="background:rgba(0,0,0,.07)">
              <div class="h-full rounded-full" style="background:<?= $color ?>;width:<?= $cbw ?>%"></div>
            </div>
          </div>
          <?php endforeach; ?>
        </div>
        <div class="text-xs mt-2" style="color:<?= $color ?>;font-weight:700">PI Index: <?= $pct ?>%</div>
        <?php else: ?>
        <div class="text-center py-6 text-gray-300 text-sm">No responses yet</div>
        <?php endif; ?>
      </div>
      <?php endforeach; ?>
    </div>

    <p class="text-center text-xs text-gray-400">
      Scores update in real time as registered customers complete the
      <a href="<?= $base ?>/auth/login.php" class="text-primary font-semibold hover:underline">Consumer Demand Survey</a>.
      Log in to participate.
    </p>

    <?php else: ?>
    <div class="max-w-lg mx-auto text-center bg-white border border-gray-200 rounded-2xl p-10 shadow-sm">
      <i class="fas fa-chart-bar text-5xl text-gray-200 block mb-4"></i>
      <h3 class="font-bold text-lg mb-2">Demand Data Coming Soon</h3>
      <p class="text-sm text-gray-400 mb-5">Consumer demand scores appear here as registered customers complete the TPB survey.</p>
      <a href="<?= $base ?>/auth/register.php" class="inline-flex items-center gap-2 bg-primary text-white font-bold px-6 py-3 rounded-full hover:bg-primary-container transition-colors">
        <i class="fas fa-user-plus"></i> Join &amp; Participate
      </a>
    </div>
    <?php endif; ?>
  </div>
</section>

<!-- Business Directory -->
<section id="directory" class="py-16 px-6 bg-gray-50 reveal">
  <div class="max-w-[1440px] mx-auto">
    <div class="flex flex-wrap items-end justify-between gap-6 mb-10">
      <div>
        <div class="inline-flex items-center gap-2 text-xs font-bold text-primary uppercase tracking-widest bg-green-50 px-4 py-1.5 rounded-full mb-3"><i class="fas fa-utensils text-xs"></i> Davao City Directory</div>
        <h2 class="text-2xl font-bold text-gray-900" style="font-family:'Plus Jakarta Sans'">Verified Halal Businesses</h2>
        <p class="text-gray-500 text-sm mt-1"><?= array_sum(array_map('count',$dc_businesses)) ?> establishments registered on Halal Keeps · Live data</p>
      </div>
      <a href="<?= $base ?>/auth/register.php" class="inline-flex items-center gap-2 bg-primary text-white font-bold text-sm px-6 py-3 rounded-full hover:bg-primary-container transition-colors"><i class="fas fa-plus-circle"></i> List Your Business</a>
    </div>
    <div class="flex flex-wrap gap-2 mb-8">
      <button class="dir-pill active" onclick="filterDir('all',this)">All (<?= array_sum(array_map('count',$dc_businesses)) ?>)</button>
      <button class="dir-pill" onclick="filterDir('halal_certified',this)">✦ Halal Certified (<?= count($dc_businesses['halal_certified']) ?>)</button>
      <button class="dir-pill" onclick="filterDir('muslim_friendly',this)">☪ Muslim-Friendly (<?= count($dc_businesses['muslim_friendly']) ?>)</button>
      <button class="dir-pill" onclick="filterDir('non_pork',this)">⊘ Non-Pork (<?= count($dc_businesses['non_pork']) ?>)</button>
    </div>
    <div class="grid grid-cols-1 md:grid-cols-2 xl:grid-cols-3 gap-5" id="biz-grid">
      <?php foreach (['halal_certified'=>['Halal Certified','cat-halal','fa-check-circle','border-emerald-200'],'muslim_friendly'=>['Muslim-Friendly','cat-friendly','fa-star','border-blue-200'],'non_pork'=>['Non-Pork','cat-nonpork','fa-ban','border-amber-200']] as $cat=>[$lbl,$cls,$ico,$brd]):
        foreach ($dc_businesses[$cat] as $biz): $hk=(bool)$biz['is_halal_keeps']; ?>
      <div class="biz-card hover-lift <?= $hk?'hk-verified border-2 '.$brd:'' ?>" data-category="<?= $cat ?>">
        <div class="flex items-start justify-between gap-3 mb-3">
          <div class="flex-1 min-w-0">
            <h3 class="font-bold text-base leading-snug mb-1 truncate" title="<?= htmlspecialchars($biz['business_name']) ?>"><?= htmlspecialchars($biz['business_name']) ?></h3>
            <span class="inline-flex items-center gap-1.5 text-xs font-bold px-2.5 py-1 rounded-full border <?= $cls ?>"><i class="fas <?= $ico ?>" style="font-size:11px"></i><?= $lbl ?></span>
          </div>
          <?php if ($hk): ?><div class="flex-shrink-0 flex flex-col items-center gap-1"><img src="<?= $base ?>/assets/images/logo.jpg" class="w-10 h-10 rounded-full object-cover ring-2 ring-emerald-300"><span class="text-[10px] font-black text-emerald-700 leading-none">Halal Keeps</span></div><?php endif; ?>
        </div>
        <?php if ($biz['address']): ?>
        <div class="flex items-start gap-1.5 text-xs text-gray-400 mt-2"><i class="fas fa-map-marker-alt text-primary flex-shrink-0 mt-0.5" style="font-size:12px"></i><span class="line-clamp-2"><?= htmlspecialchars($biz['address']) ?></span></div>
        <?php endif; ?>
        <?php if ($hk): ?><div class="mt-3 flex items-center gap-2 bg-emerald-50 border border-emerald-100 rounded-lg px-3 py-2"><i class="fas fa-shield-alt text-emerald-600" style="font-size:12px"></i><span class="text-xs font-bold text-emerald-700">Registered &amp; Verified on Halal Keeps</span></div><?php endif; ?>
      </div>
      <?php endforeach; endforeach; ?>
      <div id="dir-empty" class="hidden col-span-full text-center py-16 text-gray-400"><i class="fas fa-search-minus text-5xl text-gray-200 block mb-3"></i>No businesses in this category yet.</div>
    </div>
    <div class="text-center mt-10">
      <p class="text-sm text-gray-400 mb-4">More listings available inside the marketplace</p>
      <a href="<?= $base ?>/auth/register.php" class="inline-flex items-center gap-2 bg-primary text-white font-bold px-8 py-3.5 rounded-full hover:bg-primary-container transition-all hover-lift"><i class="fas fa-compass"></i> Explore Full Marketplace</a>
    </div>
  </div>
</section>

<!-- Trust / About -->
<section id="about" class="py-16 px-6 bg-white reveal">
  <div class="max-w-[1440px] mx-auto grid grid-cols-1 md:grid-cols-3 gap-8">
    <?php foreach ([['fa-certificate','text-primary','bg-green-50','Halal Certified',"Verified by accredited Halal certification bodies against HDIP Shari'ah standards."],['fa-handshake','text-blue-600','bg-blue-50','Muslim-Friendly','Establishments that respect Muslim dietary requirements — no pork, no alcohol in cooking.'],['fa-utensils','text-amber-600','bg-amber-50','Non-Pork','Certified pork-free kitchens serving halal-compliant meals across Davao City.']] as [$ico,$ic,$bg,$title,$desc]): ?>
    <div class="text-center p-6">
      <div class="w-16 h-16 <?= $bg ?> rounded-2xl flex items-center justify-center mx-auto mb-4"><i class="fas <?= $ico ?> <?= $ic ?> text-2xl"></i></div>
      <h3 class="font-bold text-lg mb-2" style="font-family:'Plus Jakarta Sans'"><?= $title ?></h3>
      <p class="text-sm text-gray-500"><?= $desc ?></p>
    </div>
    <?php endforeach; ?>
  </div>
</section>

</div><!-- /view-customer -->

<!-- ════════════════════════════════════════════════════
     TAB 2 — BUSINESS OWNER VIEW
════════════════════════════════════════════════════ -->
<div id="view-owner" class="view-panel" role="tabpanel" aria-labelledby="btn-owner">

<!-- Value Proposition Hero -->
<section class="py-16 px-6 bg-white">
  <div class="max-w-[1440px] mx-auto">
    <div class="owner-hero px-8 py-12 md:px-14 md:py-16 text-white">
      <div class="grid grid-cols-1 lg:grid-cols-2 gap-12 items-center">
        <div>
          <div class="inline-flex items-center gap-2 bg-white/15 border border-white/25 text-white text-xs font-bold px-4 py-2 rounded-full mb-6 uppercase tracking-wide">
            <span class="w-2 h-2 rounded-full bg-amber-400 animate-pulse"></span> For Food Business Owners
          </div>
          <h2 class="text-3xl md:text-4xl font-black mb-5 leading-tight" style="font-family:'Plus Jakarta Sans'">
            Why List on<br><span style="color:#fcd34d">Halal Keeps?</span>
          </h2>
          <p class="text-white/80 text-base leading-relaxed mb-8">
            Reach Davao City's growing Muslim and Halal-conscious market.
            Access real consumer demand intelligence, earn a trusted verification badge,
            and manage your entire operation — from orders to compliance — in one platform.
          </p>
          <div class="grid grid-cols-1 sm:grid-cols-2 gap-4 mb-8">
            <?php foreach ([
              ['fa-chart-bar','Demand Analytics','Real-time PI scores from verified consumer surveys'],
              ['fa-shield-alt','Verified Badge','Halal Keeps trust badge shown to thousands of diners'],
              ['fa-clipboard-check','HaCeRa Tool','5-module Halal readiness self-assessment workflow'],
              ['fa-users','Staff Management','Assign roles: Chef, Cashier, Ops Manager'],
              ['fa-receipt','POS & Orders','Built-in point-of-sale and order management system'],
              ['fa-certificate','Cert Tracking','Full Halal certification application lifecycle'],
            ] as [$icon,$title,$desc]): ?>
            <div class="flex items-start gap-3 bg-white/10 rounded-xl p-4 border border-white/15">
              <div class="w-9 h-9 bg-white/20 rounded-lg flex items-center justify-center flex-shrink-0">
                <i class="fas <?= $icon ?>" style="font-size:.9rem"></i>
              </div>
              <div>
                <div class="font-bold text-sm"><?= $title ?></div>
                <div class="text-white/65 text-xs mt-0.5 leading-relaxed"><?= $desc ?></div>
              </div>
            </div>
            <?php endforeach; ?>
          </div>
          <a href="<?= $base ?>/auth/register.php"
             class="inline-flex items-center gap-2 bg-white text-primary font-bold px-8 py-4 rounded-full shadow-lg hover:bg-green-50 transition-all hover-lift text-base">
            <i class="fas fa-store"></i> Register My Business — Free
          </a>
        </div>
        <!-- Stats column -->
        <div class="grid grid-cols-2 gap-5">
          <?php foreach ([
            [$dc_metrics['halal_certified']??0,'Halal Certified','Verified businesses in Davao City','#a4f3cb'],
            [$dc_metrics['total_food_biz']??0,'Food Businesses','Total registered establishments','#fcd34d'],
            [number_format($tpb['total']),'Survey Respondents','Customers who shared demand data','#93c5fd'],
            [number_format($tpb['overall_pi'],2),'Overall PI Score','Market-wide purchase intention','#f9a8d4'],
          ] as [$n,$title,$sub,$accent]): ?>
          <div class="bg-white/12 border border-white/20 rounded-2xl p-6 text-center hover:bg-white/18 transition-colors">
            <div class="text-3xl font-black mb-1" style="color:<?= $accent ?>;font-family:'Plus Jakarta Sans'"><?= $n ?></div>
            <div class="font-bold text-sm text-white mb-1"><?= $title ?></div>
            <div class="text-white/55 text-xs"><?= $sub ?></div>
          </div>
          <?php endforeach; ?>
        </div>
      </div>
    </div>
  </div>
</section>

<!-- Live Demand Analytics Teaser -->
<section id="owner-demand" class="py-16 px-6 bg-gray-50">
  <div class="max-w-[1440px] mx-auto">
    <div class="text-center mb-12">
      <div class="inline-flex items-center gap-2 text-xs font-bold text-primary uppercase tracking-widest bg-green-50 px-4 py-1.5 rounded-full mb-3">
        <i class="fas fa-chart-bar text-xs"></i> Demand Analytics Preview
      </div>
      <h2 class="text-2xl font-bold text-gray-900 mb-2" style="font-family:'Plus Jakarta Sans'">
        Know Your Market Before You Open
      </h2>
      <p class="text-gray-500 text-sm max-w-xl mx-auto">
        Business owners on Halal Keeps see live <strong>Purchase Intention (PI)</strong> scores
        segmented by Muslim vs. Non-Muslim demand — powered by the Theory of Planned Behavior regression model.
      </p>
    </div>

    <!-- Teaser scorecard grid -->
    <div class="grid grid-cols-1 lg:grid-cols-3 gap-6 mb-8">

      <!-- Muslim PI card -->
      <div class="teaser-card bg-white border-2 border-blue-100" style="position:relative;overflow:hidden">
        <div style="position:absolute;top:-20px;right:-20px;width:100px;height:100px;border-radius:50%;background:#eff6ff;pointer-events:none"></div>
        <div class="flex items-center gap-3 mb-5">
          <div style="width:42px;height:42px;background:#1d4ed8;border-radius:12px;display:flex;align-items:center;justify-content:center;flex-shrink:0">
            <i class="fas fa-moon" style="color:#fff"></i>
          </div>
          <div>
            <div class="font-bold text-sm text-blue-800">Muslim Demand (PI<sub>Muslim</sub>)</div>
            <div class="text-xs text-gray-400">Predicted Purchase Intention</div>
          </div>
        </div>
        <?php if ($tpb['muslim_pi']>0): ?>
        <div class="flex items-baseline gap-2 mb-3">
          <span class="text-4xl font-black text-blue-700" style="font-family:'Plus Jakarta Sans'"><?= number_format($tpb['muslim_pi'],2) ?></span>
          <span class="text-gray-400">/&nbsp;5.00</span>
        </div>
        <div class="h-3 bg-blue-100 rounded-full overflow-hidden mb-2">
          <div class="h-full bg-blue-600 rounded-full" style="width:<?= min(round(($tpb['muslim_pi']/5)*100),100) ?>%;transition:width 1.2s ease"></div>
        </div>
        <div class="flex justify-between text-xs mb-4">
          <span class="font-bold text-blue-700">PI Index: <?= $tpb['muslim_pct'] ?>%</span>
          <span class="bg-blue-600 text-white px-2.5 py-0.5 rounded-full font-bold text-[.7rem]">
            <?= match(true){$tpb['muslim_pi']>=4.5=>'Very High',$tpb['muslim_pi']>=3.5=>'High',$tpb['muslim_pi']>=2.5=>'Moderate',default=>'Low'} ?>
          </span>
        </div>
        <?php else: ?>
        <div class="text-center py-6 text-gray-300">
          <i class="fas fa-chart-bar text-3xl block mb-2"></i>
          <span class="text-sm">Populates with survey data</span>
        </div>
        <?php endif; ?>
        <div class="pt-4 border-t border-gray-100 text-xs text-gray-400">
          <i class="fas fa-info-circle text-blue-400 mr-1"></i>
          Formula: PI = <strong>0.554</strong> × Mean(ATT) + <strong>0.379</strong> × Mean(SN)
        </div>
      </div>

      <!-- Non-Muslim PI card -->
      <div class="teaser-card bg-white border-2 border-purple-100" style="position:relative;overflow:hidden">
        <div style="position:absolute;top:-20px;right:-20px;width:100px;height:100px;border-radius:50%;background:#f5f3ff;pointer-events:none"></div>
        <div class="flex items-center gap-3 mb-5">
          <div style="width:42px;height:42px;background:#7c3aed;border-radius:12px;display:flex;align-items:center;justify-content:center;flex-shrink:0">
            <i class="fas fa-users" style="color:#fff"></i>
          </div>
          <div>
            <div class="font-bold text-sm text-purple-800">Non-Muslim Demand (PI<sub>Non-Muslim</sub>)</div>
            <div class="text-xs text-gray-400">Predicted Purchase Intention</div>
          </div>
        </div>
        <?php if ($tpb['nm_pi']>0): ?>
        <div class="flex items-baseline gap-2 mb-3">
          <span class="text-4xl font-black text-purple-700" style="font-family:'Plus Jakarta Sans'"><?= number_format($tpb['nm_pi'],2) ?></span>
          <span class="text-gray-400">/&nbsp;5.00</span>
        </div>
        <div class="h-3 bg-purple-100 rounded-full overflow-hidden mb-2">
          <div class="h-full bg-purple-600 rounded-full" style="width:<?= min(round(($tpb['nm_pi']/5)*100),100) ?>%;transition:width 1.2s ease"></div>
        </div>
        <div class="flex justify-between text-xs mb-4">
          <span class="font-bold text-purple-700">PI Index: <?= $tpb['nm_pct'] ?>%</span>
          <span class="bg-purple-600 text-white px-2.5 py-0.5 rounded-full font-bold text-[.7rem]">
            <?= match(true){$tpb['nm_pi']>=4.5=>'Very High',$tpb['nm_pi']>=3.5=>'High',$tpb['nm_pi']>=2.5=>'Moderate',default=>'Low'} ?>
          </span>
        </div>
        <?php else: ?>
        <div class="text-center py-6 text-gray-300">
          <i class="fas fa-chart-bar text-3xl block mb-2"></i>
          <span class="text-sm">Populates with survey data</span>
        </div>
        <?php endif; ?>
        <div class="pt-4 border-t border-gray-100 text-xs text-gray-400">
          <i class="fas fa-info-circle text-purple-400 mr-1"></i>
          Actual Mean PI tracked alongside predicted for validation
        </div>
      </div>

      <!-- Overall + ATT vs SN breakdown -->
      <div class="teaser-card bg-white border-2 border-emerald-100" style="position:relative;overflow:hidden">
        <div style="position:absolute;top:-20px;right:-20px;width:100px;height:100px;border-radius:50%;background:#f0fdf4;pointer-events:none"></div>
        <div class="flex items-center gap-3 mb-5">
          <div style="width:42px;height:42px;background:linear-gradient(135deg,#047857,#065f46);border-radius:12px;display:flex;align-items:center;justify-content:center;flex-shrink:0">
            <i class="fas fa-globe-asia" style="color:#fff"></i>
          </div>
          <div>
            <div class="font-bold text-sm text-emerald-800">Overall Market Demand</div>
            <div class="text-xs text-gray-400"><?= number_format($tpb['total']) ?> total respondents</div>
          </div>
        </div>
        <?php if ($tpb['overall_pi']>0): ?>
        <div class="flex items-baseline gap-2 mb-3">
          <span class="text-4xl font-black text-emerald-700" style="font-family:'Plus Jakarta Sans'"><?= number_format($tpb['overall_pi'],2) ?></span>
          <span class="text-gray-400">/&nbsp;5.00</span>
        </div>
        <div class="h-3 bg-emerald-100 rounded-full overflow-hidden mb-2">
          <div class="h-full bg-emerald-600 rounded-full" style="width:<?= min(round(($tpb['overall_pi']/5)*100),100) ?>%;transition:width 1.2s ease"></div>
        </div>
        <div class="flex justify-between text-xs mb-4">
          <span class="font-bold text-emerald-700">PI Index: <?= $tpb['overall_pct'] ?>%</span>
          <span class="bg-emerald-600 text-white px-2.5 py-0.5 rounded-full font-bold text-[.7rem]">
            <?= match(true){$tpb['overall_pi']>=4.5=>'Very High',$tpb['overall_pi']>=3.5=>'High',$tpb['overall_pi']>=2.5=>'Moderate',default=>'Low'} ?>
          </span>
        </div>
        <?php else: ?>
        <div class="text-center py-6 text-gray-300">
          <i class="fas fa-chart-bar text-3xl block mb-2"></i>
          <span class="text-sm">Populates with survey data</span>
        </div>
        <?php endif; ?>
        <div class="pt-4 border-t border-gray-100 text-xs text-gray-400">
          <i class="fas fa-lock text-emerald-400 mr-1"></i>
          Full ATT vs. SN driver breakdown available to registered owners
        </div>
      </div>

    </div><!-- /grid -->

    <!-- Regression formula callout -->
    <div class="bg-white border border-gray-200 rounded-2xl p-6 md:p-8 flex flex-wrap items-center gap-6">
      <div class="flex-1 min-w-0">
        <div class="text-xs font-bold text-primary uppercase tracking-widest mb-2">TPB Regression Model</div>
        <div class="font-mono text-lg font-bold text-gray-800 mb-2" style="background:#f8fafc;border:1px solid #e2e8f0;border-radius:10px;padding:10px 16px;display:inline-block">
          Predicted PI = 0.554 &times; Mean(ATT) + 0.379 &times; Mean(SN)
        </div>
        <p class="text-sm text-gray-500 mt-2">
          <strong>ATT</strong> (Attitude, 55.4% weight) measures personal evaluation of Halal food quality &amp; safety.<br>
          <strong>SN</strong> (Subjective Norms, 37.9% weight) measures social pressure from family, peers &amp; community.<br>
          PI Index (%) = (Score − 1) ÷ 4 × 100 &nbsp;·&nbsp; Range: 20% (score 1.0) to 100% (score 5.0)
        </p>
      </div>
      <a href="<?= $base ?>/auth/register.php"
         class="flex-shrink-0 inline-flex items-center gap-2 bg-primary text-white font-bold px-7 py-3.5 rounded-full hover:bg-primary-container transition-all hover-lift">
        <i class="fas fa-unlock"></i> Unlock Full Analytics
      </a>
    </div>
  </div>
</section>

<!-- HaCeRa Overview -->
<section class="py-16 px-6 bg-white">
  <div class="max-w-[1440px] mx-auto">
    <div class="text-center mb-12">
      <div class="inline-flex items-center gap-2 text-xs font-bold text-primary uppercase tracking-widest bg-green-50 px-4 py-1.5 rounded-full mb-3">
        <i class="fas fa-clipboard-check text-xs"></i> Halal Readiness Assessment
      </div>
      <h2 class="text-2xl font-bold text-gray-900 mb-2" style="font-family:'Plus Jakarta Sans'">
        HaCeRa — 5-Module Compliance Workflow
      </h2>
      <p class="text-gray-500 text-sm max-w-xl mx-auto">
        The Halal Certification Readiness Assessment guides your business through 5 core compliance
        modules before applying for official certification.
      </p>
    </div>

    <div class="grid grid-cols-1 sm:grid-cols-2 lg:grid-cols-5 gap-5 mb-10">
      <?php foreach ([
        ['1','fa-building','Premises &amp; Facilities','Physical space, sanitation zones, and Halal-dedicated equipment','#ecfdf5','#047857'],
        ['2','fa-boxes','Raw Materials','Supplier auditing, ingredient sourcing, and Halal documentation','#eff6ff','#1d4ed8'],
        ['3','fa-industry','Processing &amp; Handling','Slaughter methods, cross-contamination prevention, workflow SOP','#fef3c7','#b45309'],
        ['4','fa-file-alt','Documentation','Halal Assurance Manual, record-keeping, and traceability system','#f5f3ff','#7c3aed'],
        ['5','fa-user-check','Personnel &amp; Training','Staff awareness, Muslim-friendly policies, audit readiness','#fff1f2','#be123c'],
      ] as [$step,$icon,$title,$desc,$bg,$color]): ?>
      <div class="hacera-card" style="border-top:4px solid <?= $color ?>">
        <div style="width:44px;height:44px;background:<?= $bg ?>;border-radius:12px;display:flex;align-items:center;justify-content:center;margin:0 auto 12px">
          <i class="fas <?= $icon ?>" style="color:<?= $color ?>;font-size:1.1rem"></i>
        </div>
        <div style="background:<?= $color ?>;color:#fff;width:24px;height:24px;border-radius:50%;display:flex;align-items:center;justify-content:center;margin:0 auto 10px;font-size:.75rem;font-weight:900"><?= $step ?></div>
        <div class="font-bold text-sm text-gray-800 mb-2"><?= $title ?></div>
        <div class="text-xs text-gray-400 leading-relaxed"><?= $desc ?></div>
      </div>
      <?php endforeach; ?>
    </div>

    <!-- Progress illustration -->
    <div class="bg-gray-50 border border-gray-200 rounded-2xl p-6 md:p-8">
      <div class="flex flex-wrap items-center gap-6 mb-6">
        <div class="flex-1 min-w-[200px]">
          <div class="text-sm font-bold text-gray-700 mb-1">Sample HaCeRa Progress</div>
          <div class="text-xs text-gray-400">Illustrative score — actual scores are computed from your real assessment</div>
        </div>
        <div class="flex gap-3 flex-wrap text-xs font-semibold">
          <span class="bg-blue-100 text-blue-700 px-3 py-1 rounded-full">≥ 90% Inspection Ready</span>
          <span class="bg-green-100 text-green-700 px-3 py-1 rounded-full">≥ 70% Near Ready</span>
          <span class="bg-amber-100 text-amber-700 px-3 py-1 rounded-full">≥ 40% In Progress</span>
          <span class="bg-red-100 text-red-700 px-3 py-1 rounded-full">&lt; 40% Just Started</span>
        </div>
      </div>
      <div class="grid grid-cols-1 sm:grid-cols-5 gap-3">
        <?php foreach ([['Premises','78','#047857'],['Raw Materials','65','#1d4ed8'],['Processing','82','#b45309'],['Documentation','55','#7c3aed'],['Personnel','90','#be123c']] as [$mod,$pct,$col]): ?>
        <div>
          <div class="flex justify-between text-xs font-semibold mb-1">
            <span class="text-gray-600"><?= $mod ?></span>
            <span style="color:<?= $col ?>"><?= $pct ?>%</span>
          </div>
          <div class="h-2.5 bg-gray-200 rounded-full overflow-hidden">
            <div class="h-full rounded-full" style="width:<?= $pct ?>%;background:<?= $col ?>"></div>
          </div>
        </div>
        <?php endforeach; ?>
      </div>
      <div class="mt-5 flex flex-wrap items-center justify-between gap-4">
        <div>
          <span class="text-sm text-gray-500">Overall Readiness: </span>
          <span class="text-xl font-black text-primary" style="font-family:'Plus Jakarta Sans'">74.0%</span>
          <span class="ml-2 bg-green-100 text-green-700 text-xs font-bold px-3 py-1 rounded-full">Near Ready</span>
        </div>
        <a href="<?= $base ?>/auth/register.php"
           class="inline-flex items-center gap-2 bg-primary text-white font-bold px-6 py-3 rounded-full hover:bg-primary-container transition-colors">
          <i class="fas fa-play-circle"></i> Start Your Assessment
        </a>
      </div>
    </div>
  </div>
</section>

<!-- Owner CTA strip -->
<section class="py-14 px-6 bg-primary text-white text-center">
  <div class="max-w-2xl mx-auto">
    <i class="fas fa-store text-4xl mb-4 block" style="color:#a4f3cb"></i>
    <h2 class="text-2xl font-bold mb-3" style="font-family:'Plus Jakarta Sans'">Ready to Join Halal Keeps?</h2>
    <p class="text-white/80 text-base leading-relaxed mb-8">
      Registration is free. List your business, access consumer demand data,
      and start your Halal certification journey today.
    </p>
    <div class="flex flex-wrap gap-4 justify-center">
      <a href="<?= $base ?>/auth/register.php" class="inline-flex items-center gap-2 bg-white text-primary font-bold px-8 py-4 rounded-full shadow-lg hover:bg-green-50 transition-all hover-lift">
        <i class="fas fa-user-plus"></i> Create Business Account
      </a>
      <a href="<?= $base ?>/auth/login.php" class="inline-flex items-center gap-2 bg-white/15 text-white font-bold px-8 py-4 rounded-full border border-white/30 hover:bg-white/25 transition-all">
        <i class="fas fa-sign-in-alt"></i> Already Have an Account
      </a>
    </div>
  </div>
</section>

</div><!-- /view-owner -->

<!-- SDG Banner (shared) -->
<section class="py-14 px-6 text-white text-center" style="background:linear-gradient(135deg,#064e3b,#047857)">
  <div class="max-w-3xl mx-auto">
    <i class="fas fa-leaf text-4xl mb-4 block" style="color:#a4f3cb"></i>
    <h2 class="text-2xl font-bold mb-3" style="font-family:'Plus Jakarta Sans'">Committed to SDG 12 — Responsible Consumption</h2>
    <p class="text-white/80 text-base leading-relaxed">
      Halal Keeps promotes transparent supply chains, reduces food waste, and empowers consumers
      to make ethical choices — aligning halal dining standards with global sustainability goals.
    </p>
  </div>
</section>

<!-- Footer -->
<footer style="background:#191c1d" class="py-12 px-6">
  <div class="max-w-[1440px] mx-auto grid grid-cols-1 md:grid-cols-4 gap-8">
    <div class="md:col-span-1">
      <div class="flex items-center gap-3 mb-4">
        <img src="<?= $base ?>/assets/images/logo.jpg" alt="Halal Keeps" class="h-9 w-9 rounded-full object-cover">
        <span class="font-bold text-white text-lg" style="font-family:'Plus Jakarta Sans'">Halal Keeps</span>
      </div>
      <p class="text-gray-400 text-sm leading-relaxed">
        &copy; <?= date('Y') ?> Halal Keeps.<br>
        Promoting SDG 12 through ethical, transparent Halal food standards.
      </p>
    </div>
    <div class="md:col-span-3 grid grid-cols-2 md:grid-cols-3 gap-8">
      <div>
        <div class="text-white font-bold text-sm mb-4">Directory</div>
        <div class="flex flex-col gap-2.5 text-sm text-gray-400">
          <a href="#directory"                         class="hover:text-white transition-colors">Browse Businesses</a>
          <a href="<?= $base ?>/auth/register.php"    class="hover:text-white transition-colors">Register Your Business</a>
          <a href="#metrics"                           class="hover:text-white transition-colors">Compliance Metrics</a>
        </div>
      </div>
      <div>
        <div class="text-white font-bold text-sm mb-4">Platform</div>
        <div class="flex flex-col gap-2.5 text-sm text-gray-400">
          <a href="<?= $base ?>/auth/login.php"        class="hover:text-white transition-colors">Business Portal</a>
          <a href="<?= $base ?>/auth/register.php"    class="hover:text-white transition-colors">Customer Sign Up</a>
          <a href="#demand"                            class="hover:text-white transition-colors">Demand Survey</a>
        </div>
      </div>
      <div>
        <div class="text-white font-bold text-sm mb-4">Coverage</div>
        <div class="flex flex-col gap-2.5 text-sm text-gray-400">
          <span class="text-emerald-400 font-semibold">&#9679; Davao City (Live)</span>
          <span class="text-gray-500">Davao del Sur (Soon)</span>
          <span class="text-gray-500">Other Regions (Soon)</span>
        </div>
      </div>
    </div>
  </div>
</footer>

<!-- ═══════════════════════════════════════════════════════════
     SCRIPTS
═══════════════════════════════════════════════════════════ -->
<script>
// ── View switcher ────────────────────────────────────────────────────────────
function switchView(view) {
  const panels = ['customer','owner'];
  panels.forEach(v => {
    document.getElementById('view-' + v).classList.toggle('active', v === view);
    const btn = document.getElementById('btn-' + v);
    if (btn) {
      btn.classList.toggle('active', v === view);
      btn.setAttribute('aria-selected', String(v === view));
    }
  });
  // Swap hero content
  const hc = document.getElementById('hero-customer');
  const ho = document.getElementById('hero-owner');
  if (hc) hc.style.display = view === 'customer' ? '' : 'none';
  if (ho) ho.style.display = view === 'owner'    ? '' : 'none';

  // Persist in URL without reload
  const url = new URL(window.location.href);
  url.searchParams.set('view', view);
  history.replaceState({}, '', url.toString());

  // Re-trigger reveal animations for newly visible panel
  document.querySelectorAll('#view-' + view + ' .reveal').forEach(el => {
    if (!el.classList.contains('active')) el.classList.add('active');
  });
}

// Restore view from URL param on load
(function () {
  const params = new URLSearchParams(window.location.search);
  const v = params.get('view');
  if (v === 'owner') switchView('owner');
})();

// ── Scroll reveal ────────────────────────────────────────────────────────────
const revealObs = new IntersectionObserver(entries => {
  entries.forEach(e => { if (e.isIntersecting) e.target.classList.add('active'); });
}, { threshold: 0.08 });
document.querySelectorAll('.reveal').forEach(el => revealObs.observe(el));

// ── Directory filter ─────────────────────────────────────────────────────────
function filterDir(cat, btn) {
  document.querySelectorAll('.dir-pill').forEach(b => b.classList.remove('active'));
  btn.classList.add('active');
  let visible = 0;
  document.querySelectorAll('#biz-grid .biz-card').forEach(c => {
    const show = cat === 'all' || c.dataset.category === cat;
    c.style.display = show ? '' : 'none';
    if (show) visible++;
  });
  const empty = document.getElementById('dir-empty');
  if (empty) empty.classList.toggle('hidden', visible > 0);
}

// ── Map helper ───────────────────────────────────────────────────────────────
function showDavaoDetail() {
  const card = document.getElementById('davao-detail-card');
  if (card) {
    card.scrollIntoView({ behavior: 'smooth', block: 'center' });
    card.style.boxShadow = '0 0 0 3px #a7f3d0';
    setTimeout(() => { card.style.boxShadow = ''; }, 1800);
  }
  setTimeout(() => {
    const dir = document.getElementById('directory');
    if (dir) dir.scrollIntoView({ behavior: 'smooth' });
  }, 600);
}

// ── D3 Philippines Map ───────────────────────────────────────────────────────
(async function buildMap() {
  const REGION_META = {
    100000000:  { name:'Ilocos Region (I)',         active:false },
    200000000:  { name:'Cagayan Valley (II)',        active:false },
    300000000:  { name:'Central Luzon (III)',        active:false },
    400000000:  { name:'CALABARZON (IV-A)',          active:false },
    500000000:  { name:'Bicol Region (V)',           active:false },
    600000000:  { name:'Western Visayas (VI)',       active:false },
    700000000:  { name:'Central Visayas (VII)',      active:false },
    800000000:  { name:'Eastern Visayas (VIII)',     active:false },
    900000000:  { name:'Zamboanga Peninsula (IX)',   active:false },
    1000000000: { name:'Northern Mindanao (X)',      active:false },
    1100000000: { name:'Davao Region (XI)',          active:true  },
    1200000000: { name:'SOCCSKSARGEN (XII)',         active:false },
    1300000000: { name:'Metro Manila (NCR)',         active:false },
    1400000000: { name:'CAR',                        active:false },
    1600000000: { name:'Caraga (XIII)',              active:false },
    1700000000: { name:'MIMAROPA Region',            active:false },
    1900000000: { name:'BARMM',                      active:false },
  };

  const contEl = document.getElementById('ph-map-container');
  const svgEl  = document.getElementById('ph-map');
  if (!contEl || !svgEl) return;

  const W = contEl.clientWidth || 480;
  const H = Math.round(W * 1.52);
  svgEl.setAttribute('viewBox', `0 0 ${W} ${H}`);
  svgEl.setAttribute('width', W);
  svgEl.setAttribute('height', H);
  svgEl.style.background = '#f0f9ff';

  function loadScript(src) {
    return new Promise((res, rej) => {
      const s = document.createElement('script');
      s.src = src; s.onload = res; s.onerror = rej;
      document.head.appendChild(s);
    });
  }

  try {
    await loadScript('https://cdn.jsdelivr.net/npm/d3@7/dist/d3.min.js');
    await loadScript('https://cdn.jsdelivr.net/npm/topojson-client@3/dist/topojson-client.min.js');
  } catch(e) {
    svgEl.setAttribute('viewBox','0 0 300 60');
    svgEl.innerHTML = '<text x="150" y="35" text-anchor="middle" font-size="12" fill="#9ca3af" font-family="sans-serif">Map unavailable</text>';
    return;
  }

  const TOPO_URL = 'https://raw.githubusercontent.com/faeldon/philippines-json-maps/master/2023/topojson/country/lowres/country.topo.0.001.json';
  let topo;
  try { topo = await fetch(TOPO_URL).then(r => r.json()); }
  catch(e) {
    svgEl.setAttribute('viewBox','0 0 300 60');
    svgEl.innerHTML = '<text x="150" y="35" text-anchor="middle" font-size="12" fill="#9ca3af" font-family="sans-serif">Map unavailable — check connection</text>';
    return;
  }

  const objKey  = Object.keys(topo.objects)[0];
  const geojson = topojson.feature(topo, topo.objects[objKey]);
  const proj    = d3.geoMercator().fitSize([W, H], geojson);
  const pathGen = d3.geoPath().projection(proj);
  const svg     = d3.select('#ph-map');
  const tooltip = document.getElementById('map-tooltip');

  svg.selectAll('path.region')
    .data(geojson.features)
    .join('path')
    .attr('class','region')
    .attr('d', pathGen)
    .each(function(d) {
      const meta  = REGION_META[d.id] || { name: d.properties.adm1_en || 'Region', active: false };
      const isXI  = meta.active;
      d3.select(this)
        .attr('fill',         isXI ? '#bbf7d0' : '#e5e7eb')
        .attr('stroke',       '#ffffff')
        .attr('stroke-width', 0.8)
        .attr('cursor',       'pointer')
        .style('transition',  'fill .2s')
        .on('mouseenter', function(event) {
          d3.select(this).attr('fill', isXI ? '#6ee7b7' : '#d1d5db')
            .attr('filter', isXI ? 'drop-shadow(0 2px 6px rgba(0,95,65,.35))' : null);
          if (!tooltip) return;
          const rect = svgEl.getBoundingClientRect();
          tooltip.innerHTML = isXI
            ? `<div style="display:flex;align-items:center;gap:8px;margin-bottom:6px"><span style="width:8px;height:8px;border-radius:50%;background:#10b981;flex-shrink:0"></span><strong style="color:#064e3b;font-size:.85rem">${meta.name}</strong><span style="margin-left:auto;font-size:.65rem;font-weight:700;background:#d1fae5;color:#065f46;padding:2px 8px;border-radius:999px">LIVE</span></div><p style="font-size:.75rem;color:#6b7280;margin:0;line-height:1.5">Click to explore Davao City live data,<br>compliance metrics &amp; business directory.</p>`
            : `<div style="display:flex;align-items:center;gap:8px;margin-bottom:6px"><span style="width:8px;height:8px;border-radius:50%;background:#d1d5db;flex-shrink:0"></span><strong style="font-size:.85rem;color:#111827">${meta.name}</strong></div><p style="font-size:.75rem;color:#6b7280;margin:0;line-height:1.5">Coming soon. Currently focusing on<br><strong style="color:#059669">Davao City (Region XI)</strong>.</p>`;
          const tx = Math.min(event.clientX - rect.left + 14, (contEl.clientWidth||W) - 240);
          const ty = Math.max(0, event.clientY - rect.top - 14);
          tooltip.classList.remove('hidden');
          tooltip.style.left = tx + 'px';
          tooltip.style.top  = ty + 'px';
        })
        .on('mousemove', function(event) {
          if (!tooltip) return;
          const rect = svgEl.getBoundingClientRect();
          tooltip.style.left = Math.min(event.clientX - rect.left + 14, (contEl.clientWidth||W) - 240) + 'px';
          tooltip.style.top  = Math.max(0, event.clientY - rect.top - 14) + 'px';
        })
        .on('mouseleave', function() {
          d3.select(this).attr('fill', isXI ? '#bbf7d0' : '#e5e7eb').attr('filter', null);
          if (tooltip) tooltip.classList.add('hidden');
        })
        .on('click', () => { if (isXI) showDavaoDetail(); });
    });

  // Davao City centroid marker
  const xiFeature = geojson.features.find(d => d.id === 1100000000);
  if (xiFeature) {
    const centroid = d3.geoCentroid(xiFeature);
    const pt       = proj(centroid);
    if (pt && !isNaN(pt[0]) && !isNaN(pt[1])) {
      const r = Math.max(9, W * 0.022);
      svg.append('circle').attr('cx',pt[0]).attr('cy',pt[1]).attr('r',r)
        .attr('fill','#047857').attr('stroke','#a7f3d0').attr('stroke-width',2.5)
        .attr('cursor','pointer').on('click', showDavaoDetail);
      svg.append('text').attr('x',pt[0]).attr('y',pt[1]+r*0.38)
        .attr('text-anchor','middle').attr('font-size',Math.max(7,r*0.72))
        .attr('font-weight','800').attr('fill','#fff').attr('pointer-events','none')
        .text('DC');
    }
  }
})().catch(() => {});
</script>
</body>
</html>
