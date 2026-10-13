<?php
require_once __DIR__ . '/../../config/app.php';
requireLogin();
requireRole([ROLE_CUSTOMER]);

$page_title   = 'Consumer Demand Survey';
$page_heading = 'Consumer Demand Survey';
$is_dashboard = true;
$breadcrumbs  = [
    ['label' => 'Dashboard', 'url' => BASE_URL . 'dashboard/customer/'],
    ['label' => 'Consumer Demand Survey'],
];
$user_id = (int)$_SESSION['user_id'];

// ── Idempotent table creation ─────────────────────────────────────────────────
$conn->query("CREATE TABLE IF NOT EXISTS `tpb_survey_responses` (
    `id`               INT UNSIGNED  NOT NULL AUTO_INCREMENT,
    `user_id`          INT(11)       NOT NULL,
    `religion`         ENUM('Muslim','Non-Muslim') NOT NULL,
    `food_preference`  ENUM('Halal','Non-Pork','Muslim-Friendly','Any') DEFAULT NULL,
    `questionnaire_set` TINYINT(1)  NOT NULL DEFAULT 1,
    `mean_att`         DECIMAL(4,3) DEFAULT NULL,
    `mean_sn`          DECIMAL(4,3) DEFAULT NULL,
    `mean_pbc`         DECIMAL(4,3) DEFAULT NULL,
    `raw_pi`           DECIMAL(5,3) DEFAULT NULL,
    `normalized_pi`    DECIMAL(4,3) DEFAULT NULL,
    `mean_pi_actual`   DECIMAL(4,3) DEFAULT NULL,
    `predicted_pi`     DECIMAL(5,4) DEFAULT NULL,
    `submitted_at`     TIMESTAMP    NOT NULL DEFAULT CURRENT_TIMESTAMP,
    `updated_at`       TIMESTAMP    NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (`id`),
    UNIQUE KEY `uq_survey_user` (`user_id`),
    KEY `idx_tpb_religion`  (`religion`),
    KEY `idx_tpb_submitted` (`submitted_at`),
    CONSTRAINT `tpb_user_fk`
        FOREIGN KEY (`user_id`) REFERENCES `users` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");

$conn->query("CREATE TABLE IF NOT EXISTS `tpb_survey_answers` (
    `id`          INT UNSIGNED NOT NULL AUTO_INCREMENT,
    `response_id` INT UNSIGNED NOT NULL,
    `item_code`   VARCHAR(10)  NOT NULL,
    `score`       TINYINT(1)   NOT NULL,
    PRIMARY KEY (`id`),
    UNIQUE KEY `uq_answer_item` (`response_id`, `item_code`),
    KEY `idx_tpb_item` (`item_code`),
    CONSTRAINT `tpb_ans_response_fk`
        FOREIGN KEY (`response_id`) REFERENCES `tpb_survey_responses` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");

// Add new columns idempotently
foreach ([
    ['questionnaire_set', "TINYINT(1) NOT NULL DEFAULT 1"],
    ['mean_pbc',          "DECIMAL(4,3) DEFAULT NULL"],
    ['raw_pi',            "DECIMAL(5,3) DEFAULT NULL"],
    ['normalized_pi',     "DECIMAL(4,3) DEFAULT NULL"],
] as [$col, $def]) {
    if (function_exists('db_add_column')) db_add_column($conn, 'tpb_survey_responses', $col, $def);
}

// ── User profile ──────────────────────────────────────────────────────────────
$profile_stmt = $conn->prepare("SELECT religion, food_preference, full_name FROM users WHERE id = ? LIMIT 1");
$profile_stmt->bind_param("i", $user_id);
$profile_stmt->execute();
$user_profile    = $profile_stmt->get_result()->fetch_assoc();
$profile_stmt->close();

$user_religion  = $user_profile['religion']       ?? '';
$user_food_pref = $user_profile['food_preference'] ?? '';
$profile_incomplete = empty($user_religion);

// Questionnaire set: 1 = Muslim, 2 = Non-Muslim
$q_set = ($user_religion === 'Muslim') ? 1 : 2;

// ── Existing response ─────────────────────────────────────────────────────────
$ex_stmt = $conn->prepare(
    "SELECT id, mean_att, mean_sn, mean_pbc, raw_pi, normalized_pi,
            questionnaire_set, submitted_at
     FROM tpb_survey_responses WHERE user_id = ? LIMIT 1"
);
$ex_stmt->bind_param("i", $user_id);
$ex_stmt->execute();
$existing = $ex_stmt->get_result()->fetch_assoc();
$ex_stmt->close();

$prior_answers = [];
if ($existing) {
    $ans_stmt = $conn->prepare("SELECT item_code, score FROM tpb_survey_answers WHERE response_id = ?");
    $ans_stmt->bind_param("i", $existing['id']);
    $ans_stmt->execute();
    foreach ($ans_stmt->get_result()->fetch_all(MYSQLI_ASSOC) as $r) {
        $prior_answers[$r['item_code']] = (int)$r['score'];
    }
    $ans_stmt->close();
}

// ── Questionnaire definitions ─────────────────────────────────────────────────
// Set 1 — Muslim respondents
$q1_att = [
    'ATT1' => 'I feel more confident dining at establishments with reliable Halal certification or verification.',
    'ATT2' => 'I have a positive perception of food establishments that clearly follow Halal requirements.',
    'ATT3' => 'I believe supporting Halal-compliant food businesses benefits the Muslim community.',
    'ATT4' => 'I consider dining at Halal-certified or reliably verified establishments a positive experience.',
    'ATT5' => 'Choosing food that I can confidently identify as Halal is important to my lifestyle and dining decisions.',
];
$q1_sn = [
    'SN1' => 'My family encourages me to choose food establishments with reliable Halal assurance.',
    'SN2' => 'My close friends influence my preference for dining at Halal-certified or reliably verified establishments.',
    'SN3' => 'Recommendations from people or groups I trust in the Muslim community influence my choice of food establishments.',
    'SN4' => 'The opinions of people who are important to me influence whether I choose establishments with clear Halal assurance.',
    'SN5' => 'Recommendations and experiences shared by other Muslim consumers, including those online, influence my choice of food establishments.',
];
$q1_pbc = [
    'PBC1' => 'Recommendations and experiences shared by other Muslim consumers, including those online, influence my choice of food establishments.',
    'PBC2' => 'The cost of available Halal food options generally allows me to choose them when dining out.',
    'PBC3' => 'I can easily determine whether a food establishment has reliable Halal certification or verification.',
    'PBC4' => 'I am generally able to choose a Halal food establishment even when dining with people who may have different food preferences.',
    'PBC5' => 'I can generally access suitable Halal food options when dining in unfamiliar areas.',
];

// Set 2 — Non-Muslim respondents
$q2_att = [
    'ATT1' => 'I have a positive perception of food establishments that clearly follow Halal requirements.',
    'ATT2' => 'I believe Halal certification or reliable Halal verification increases my confidence in a food establishment.',
    'ATT3' => 'I am open to trying food from Halal-certified or reliably verified Halal establishments.',
    'ATT4' => 'I consider dining at Halal-certified establishments a positive way to experience different food choices and cultures.',
    'ATT5' => 'I would consider choosing a Halal-certified or reliably verified Halal establishment when deciding where to eat.',
];
$q2_sn = [
    'SN1' => 'I would be willing to dine at a Halal-certified establishment when invited by Muslim friends, classmates, or colleagues.',
    'SN2' => 'People close to me would generally support my decision to dine at a Halal-certified establishment.',
    'SN3' => 'Positive recommendations from people I know would encourage me to try a Halal-certified establishment.',
    'SN4' => 'Positive reviews and recommendations on social media can influence my decision to visit a Halal-certified establishment.',
    'SN5' => 'The opinions of people whose recommendations I value can influence my willingness to try Halal food establishments.',
];
$q2_pbc = [
    'PBC1' => 'I can easily find Halal-certified or reliably verified Halal food establishments in areas accessible to me.',
    'PBC2' => 'The cost of available Halal food options generally allows me to choose them when dining out.',
    'PBC3' => 'Clear certification information, labels, or signage makes it easy for me to identify Halal food establishments.',
    'PBC4' => 'I can find sufficient food choices at Halal establishments that match my personal preferences.',
    'PBC5' => 'I am generally able to choose a Halal food establishment when I want to dine there.',
];

// Select active questionnaire set
$att_items = ($q_set === 1) ? $q1_att : $q2_att;
$sn_items  = ($q_set === 1) ? $q1_sn  : $q2_sn;
$pbc_items = ($q_set === 1) ? $q1_pbc : $q2_pbc;

$likert_labels = [1=>'Strongly Disagree',2=>'Disagree',3=>'Neutral',4=>'Agree',5=>'Strongly Agree'];
$total_items   = 15;

require_once __DIR__ . '/../../includes/header.php';
?>
<style>
.survey-wrapper{max-width:860px;margin:0 auto;padding:0 16px 60px}
.survey-progress-bar{height:6px;background:var(--neutral-200);border-radius:var(--radius-full);overflow:hidden;margin-bottom:32px}
.survey-progress-fill{height:100%;background:var(--gradient-primary);border-radius:var(--radius-full);transition:width .4s cubic-bezier(.4,0,.2,1)}
.survey-section{background:#fff;border-radius:var(--radius-lg);border:1px solid var(--neutral-200);box-shadow:var(--shadow-sm);margin-bottom:28px;overflow:hidden}
.survey-section-header{padding:20px 28px;display:flex;align-items:center;gap:14px}
.survey-section-icon{width:42px;height:42px;background:rgba(255,255,255,.18);border-radius:var(--radius-md);display:flex;align-items:center;justify-content:center;font-size:1.25rem;color:#fff;flex-shrink:0}
.survey-section-title{color:#fff;font-size:1.05rem;font-weight:700;font-family:var(--font-display)}
.survey-section-subtitle{color:rgba(255,255,255,.78);font-size:.78rem;margin-top:2px}
.survey-section-body{padding:24px 28px}
.survey-item{padding:18px 0;border-bottom:1px solid var(--neutral-100)}
.survey-item:last-child{border-bottom:none;padding-bottom:0}
.item-number{display:inline-flex;align-items:center;justify-content:center;width:26px;height:26px;border-radius:var(--radius-full);font-size:.72rem;font-weight:700;flex-shrink:0;margin-right:10px}
.item-text{font-size:.9rem;color:var(--neutral-800);line-height:1.55;font-weight:500}
.item-header{display:flex;align-items:flex-start;margin-bottom:12px}
.likert-scale{display:grid;grid-template-columns:repeat(5,1fr);gap:8px}
@media(max-width:580px){.likert-option label{font-size:.6rem;padding:7px 3px}}
.likert-option{text-align:center}
.likert-option input[type="radio"]{display:none}
.likert-option label{display:flex;flex-direction:column;align-items:center;gap:5px;padding:10px 6px;background:var(--neutral-50);border:2px solid var(--neutral-200);border-radius:var(--radius-md);cursor:pointer;font-size:.7rem;color:var(--neutral-600);font-weight:500;line-height:1.3;transition:all .18s;min-height:68px;justify-content:center}
.likert-option label .score-num{font-size:1.1rem;font-weight:800;color:var(--neutral-400);transition:color .18s}
.likert-option input:checked+label{background:var(--primary-50);border-color:var(--primary-600);color:var(--primary-700);box-shadow:0 0 0 3px rgba(4,120,87,.12)}
.likert-option input:checked+label .score-num{color:var(--primary-600)}
.likert-option label:hover{border-color:var(--primary-400);background:var(--primary-50)}
.likert-option[data-val="1"] input:checked+label{background:#fef2f2;border-color:#ef4444;color:#dc2626}
.likert-option[data-val="1"] input:checked+label .score-num{color:#ef4444}
.likert-option[data-val="2"] input:checked+label{background:#fff7ed;border-color:#f97316;color:#c2410c}
.likert-option[data-val="2"] input:checked+label .score-num{color:#f97316}
.likert-option[data-val="3"] input:checked+label{background:#fefce8;border-color:#eab308;color:#854d0e}
.likert-option[data-val="3"] input:checked+label .score-num{color:#eab308}
.likert-option[data-val="4"] input:checked+label{background:#f0fdf4;border-color:#22c55e;color:#15803d}
.likert-option[data-val="4"] input:checked+label .score-num{color:#22c55e}
.likert-option[data-val="5"] input:checked+label{background:var(--primary-50);border-color:var(--primary-600);color:var(--primary-700)}
.likert-option[data-val="5"] input:checked+label .score-num{color:var(--primary-600)}
.survey-item.has-error .likert-scale label{border-color:#fca5a5!important}
.survey-item.has-error .item-text::after{content:' *';color:#ef4444}
.profile-pill{display:inline-flex;align-items:center;gap:8px;padding:6px 14px;border-radius:var(--radius-full);font-size:.8rem;font-weight:600;border:1.5px solid}
.pill-religion{background:#eff6ff;border-color:#93c5fd;color:#1d4ed8}
.pill-food{background:#f0fdf4;border-color:#86efac;color:#15803d}
.btn-survey-submit{background:var(--gradient-primary);color:#fff;border:none;padding:16px 40px;border-radius:var(--radius-lg);font-size:1rem;font-weight:700;cursor:pointer;display:inline-flex;align-items:center;gap:10px;transition:opacity .2s,transform .2s;box-shadow:var(--shadow-md)}
.btn-survey-submit:hover{opacity:.92;transform:translateY(-2px)}
.btn-survey-submit:disabled{opacity:.5;cursor:not-allowed;transform:none}
.sticky-tracker{position:sticky;top:72px;z-index:30;background:#fff;border-bottom:1px solid var(--neutral-200);padding:10px 0;margin-bottom:24px}
.incomplete-alert{background:#fef2f2;border:1.5px solid #fca5a5;border-radius:var(--radius-md);padding:14px 20px;color:#991b1b;font-size:.875rem;display:flex;align-items:center;gap:10px;margin-bottom:20px}
/* Results constructs grid */
.construct-grid{display:grid;grid-template-columns:repeat(3,1fr);gap:16px;margin-bottom:20px}
@media(max-width:600px){.construct-grid{grid-template-columns:1fr}}
.construct-card{border-radius:12px;padding:18px 20px;text-align:center}
</style>

<div class="survey-wrapper">

<?php
// Flash messages
$flash_success = null; $flash_error = null;
if (!empty($_SESSION['survey_flash'])) { $flash_success = $_SESSION['survey_flash']; unset($_SESSION['survey_flash']); }
if (!empty($_SESSION['survey_error'])) { $flash_error   = $_SESSION['survey_error']; unset($_SESSION['survey_error']); }
?>

<?php if ($flash_success): ?>
<!-- ── Thank-you banner ──────────────────────────────────────────────────── -->
<div style="background:linear-gradient(135deg,#064e3b 0%,#065f46 55%,#047857 100%);
            border-radius:var(--radius-lg);padding:32px;margin-bottom:28px;
            position:relative;overflow:hidden;text-align:center;">
    <div style="position:absolute;right:-40px;top:-40px;width:150px;height:150px;border-radius:50%;background:rgba(255,255,255,.05);pointer-events:none"></div>
    <div style="width:64px;height:64px;background:rgba(255,255,255,.15);border-radius:50%;display:flex;align-items:center;justify-content:center;margin:0 auto 16px;box-shadow:0 4px 18px rgba(0,0,0,.15)">
        <i class="fas fa-check" style="color:#a4f3cb;font-size:1.8rem"></i>
    </div>
    <h2 style="font-family:var(--font-display);font-size:1.5rem;font-weight:800;color:#fff;margin:0 0 8px">Thank you for completing the survey!</h2>
    <p style="color:rgba(255,255,255,.78);font-size:.9rem;margin:0 0 24px;line-height:1.6">
        Your responses are recorded and will help map Halal food consumer demand in Davao City.
    </p>
    <!-- 3-construct score pills -->
    <div style="display:flex;flex-wrap:wrap;gap:10px;justify-content:center;margin-bottom:16px">
        <span style="background:rgba(255,255,255,.18);color:#fff;padding:6px 16px;border-radius:999px;font-size:.82rem;font-weight:700">
            <i class="fas fa-heart" style="margin-right:5px;color:#a4f3cb"></i>
            ATT: <?= number_format((float)($flash_success['mean_att']??0),2) ?>
        </span>
        <span style="background:rgba(255,255,255,.18);color:#fff;padding:6px 16px;border-radius:999px;font-size:.82rem;font-weight:700">
            <i class="fas fa-users" style="margin-right:5px;color:#a4f3cb"></i>
            SN: <?= number_format((float)($flash_success['mean_sn']??0),2) ?>
        </span>
        <span style="background:rgba(255,255,255,.18);color:#fff;padding:6px 16px;border-radius:999px;font-size:.82rem;font-weight:700">
            <i class="fas fa-hand-pointer" style="margin-right:5px;color:#a4f3cb"></i>
            PBC: <?= number_format((float)($flash_success['mean_pbc']??0),2) ?>
        </span>
        <span style="background:rgba(255,255,255,.25);color:#fff;padding:6px 16px;border-radius:999px;font-size:.85rem;font-weight:800;border:1.5px solid rgba(255,255,255,.4)">
            <i class="fas fa-chart-line" style="margin-right:5px;color:#fcd34d"></i>
            PI: <?= number_format((float)($flash_success['normalized_pi']??0),2) ?>/5.00
            &nbsp;·&nbsp; <?= $flash_success['pi_percentage'] ?>%
            &nbsp;·&nbsp; <?= htmlspecialchars($flash_success['intent_label']) ?>
        </span>
    </div>
    <div style="font-size:.8rem;color:rgba(255,255,255,.6);margin-bottom:22px">
        Dominant construct: <strong style="color:#a4f3cb"><?= htmlspecialchars($flash_success['dominant_driver']??'N/A') ?></strong>
        &nbsp;·&nbsp; Questionnaire Set <?= (int)($flash_success['questionnaire_set']??1) ?>
        (<?= ($flash_success['questionnaire_set']??1)==1 ? 'Muslim' : 'Non-Muslim' ?>)
    </div>
    <div style="display:flex;gap:10px;justify-content:center;flex-wrap:wrap">
        <a href="<?= BASE_URL ?>dashboard/customer/" style="display:inline-flex;align-items:center;gap:7px;background:#fff;color:var(--primary-800);padding:10px 22px;border-radius:var(--radius-md);font-weight:700;font-size:.88rem;text-decoration:none" onmouseover="this.style.opacity='.88'" onmouseout="this.style.opacity='1'">
            <i class="fas fa-home"></i> Back to Dashboard
        </a>
        <a href="?retake=1" style="display:inline-flex;align-items:center;gap:7px;background:rgba(255,255,255,.15);border:1.5px solid rgba(255,255,255,.3);color:#fff;padding:10px 22px;border-radius:var(--radius-md);font-weight:700;font-size:.88rem;text-decoration:none">
            <i class="fas fa-eye"></i> View Full Results
        </a>
    </div>
</div>
<?php endif; ?>

<?php if ($flash_error): ?>
<div class="incomplete-alert" style="margin-bottom:20px"><i class="fas fa-exclamation-triangle"></i><div><?= htmlspecialchars($flash_error) ?></div></div>
<?php endif; ?>

<?php if ($profile_incomplete): ?>
<div class="incomplete-alert">
    <i class="fas fa-exclamation-circle" style="font-size:1.2rem;flex-shrink:0"></i>
    <div><strong>Profile incomplete.</strong> Your <em>Religion</em> field is not set. The survey questionnaire is selected based on your religion.
    Please <a href="<?= BASE_URL ?>dashboard/customer/profile.php" style="color:#dc2626;text-decoration:underline;font-weight:700">update your profile</a> first.</div>
</div>
<?php endif; ?>

<!-- ── Page header ────────────────────────────────────────────────────────── -->
<div style="margin-bottom:28px">
    <div style="display:flex;align-items:center;gap:14px;margin-bottom:14px">
        <div style="width:52px;height:52px;background:var(--gradient-primary);border-radius:var(--radius-lg);display:flex;align-items:center;justify-content:center;flex-shrink:0">
            <i class="fas fa-chart-line" style="color:#fff;font-size:1.4rem"></i>
        </div>
        <div>
            <h1 style="font-family:var(--font-display);font-size:1.5rem;font-weight:800;color:var(--neutral-900);margin:0">Consumer Demand Survey</h1>
            <p style="color:var(--neutral-500);font-size:.875rem;margin:2px 0 0">Halal Food Purchase Intention Study — Davao City</p>
        </div>
    </div>
    <!-- Profile pills -->
    <div style="display:flex;flex-wrap:wrap;gap:8px;margin-bottom:16px">
        <span class="profile-pill pill-religion">
            <i class="fas fa-user-circle"></i>
            <?= $user_religion ?: '<em style="opacity:.6">Religion not set</em>' ?>
        </span>
        <span class="profile-pill pill-food">
            <i class="fas fa-utensils"></i>
            <?= $user_food_pref ?: '<em style="opacity:.6">No preference set</em>' ?>
        </span>
        <span class="profile-pill" style="background:<?= $q_set===1?'#eff6ff':'#f5f3ff' ?>;border-color:<?= $q_set===1?'#93c5fd':'#c4b5fd' ?>;color:<?= $q_set===1?'#1d4ed8':'#5b21b6' ?>">
            <i class="fas fa-list-check"></i>
            Questionnaire Set <?= $q_set ?> — <?= $q_set===1?'Muslim Demand':'Non-Muslim Demand' ?>
        </span>
    </div>
    <!-- Theory note -->
    <div style="background:var(--neutral-50);border:1px solid var(--neutral-200);border-radius:var(--radius-md);padding:14px 18px;font-size:.82rem;color:var(--neutral-600);line-height:1.65">
        <strong style="color:var(--neutral-700)">Theory of Planned Behavior (TPB)</strong> — This survey measures three constructs:<br>
        <div style="display:flex;flex-wrap:wrap;gap:8px;margin-top:8px">
            <span style="background:#dcfce7;color:#15803d;border:1px solid #bbf7d0;padding:3px 10px;border-radius:6px;font-size:.78rem;font-weight:600"><i class="fas fa-heart" style="margin-right:4px"></i>Attitude (ATT)</span>
            <span style="background:#dbeafe;color:#1d4ed8;border:1px solid #bfdbfe;padding:3px 10px;border-radius:6px;font-size:.78rem;font-weight:600"><i class="fas fa-users" style="margin-right:4px"></i>Subjective Norms (SN)</span>
            <span style="background:#fef3c7;color:#92400e;border:1px solid #fde68a;padding:3px 10px;border-radius:6px;font-size:.78rem;font-weight:600"><i class="fas fa-hand-pointer" style="margin-right:4px"></i>Perceived Behavioral Control (PBC)</span>
        </div>
        <div style="margin-top:10px;font-family:monospace;background:#fff;border:1px solid var(--neutral-200);border-radius:6px;padding:6px 12px;font-size:.82rem;color:var(--neutral-700)">
            PI = Mean(ATT) + Mean(SN) + Mean(PBC) &nbsp;·&nbsp; Normalized (÷3) → 1.0–5.0 scale
        </div>
    </div>
</div>

<?php if ($existing && !isset($_GET['retake'])): ?>
<!-- ══════════════════════════════════════════════════════════════════════════
     RESULTS VIEW
══════════════════════════════════════════════════════════════════════════ -->
<?php
    // Use new columns if available, fall back to old ones
    $norm_pi   = (float)($existing['normalized_pi'] ?? $existing['predicted_pi'] ?? 0);
    $raw_pi    = (float)($existing['raw_pi']        ?? $norm_pi * 3);
    $att_mean  = (float)($existing['mean_att'] ?? 0);
    $sn_mean   = (float)($existing['mean_sn']  ?? 0);
    $pbc_mean  = (float)($existing['mean_pbc'] ?? 0);
    $pi_pct    = round((($norm_pi - 1) / 4) * 100, 1);
    $ex_q_set  = (int)($existing['questionnaire_set'] ?? 1);
    $pi_lbl    = match(true) {
        $norm_pi >= 4.5 => ['Very High', '#16a34a'],
        $norm_pi >= 3.5 => ['High',      '#059669'],
        $norm_pi >= 2.5 => ['Moderate',  '#d97706'],
        $norm_pi >= 1.5 => ['Low',       '#ea580c'],
        default          => ['Very Low',  '#dc2626'],
    };
    $dominant = match(true) {
        $att_mean >= $sn_mean && $att_mean >= $pbc_mean => 'Attitude (ATT)',
        $sn_mean  >= $att_mean && $sn_mean  >= $pbc_mean => 'Subjective Norms (SN)',
        default => 'Perceived Behavioral Control (PBC)',
    };
?>

<!-- Score banner -->
<div style="background:linear-gradient(135deg,#064e3b 0%,#065f46 55%,#047857 100%);
            border-radius:var(--radius-lg);padding:28px 32px;color:#fff;margin-bottom:24px">
    <div style="display:flex;flex-wrap:wrap;gap:8px;align-items:center;margin-bottom:16px">
        <span style="font-size:.72rem;font-weight:700;letter-spacing:.08em;text-transform:uppercase;color:#6ee7b7">
            Your Results · Set <?= $ex_q_set ?> · Submitted <?= date('M j, Y', strtotime($existing['submitted_at'])) ?>
        </span>
    </div>
    <!-- 3 construct scores -->
    <div class="construct-grid" style="margin-bottom:20px">
        <?php foreach ([
            ['Attitude','ATT','fa-heart',$att_mean,'#dcfce7','#15803d'],
            ['Subjective Norms','SN','fa-users',$sn_mean,'#dbeafe','#1d4ed8'],
            ['Perceived Behavioral Control','PBC','fa-hand-pointer',$pbc_mean,'#fef3c7','#92400e'],
        ] as [$name,$code,$icon,$val,$bg,$col]): ?>
        <div style="background:rgba(255,255,255,.12);border-radius:12px;padding:16px;text-align:center;border:1px solid rgba(255,255,255,.2)">
            <i class="fas <?= $icon ?>" style="font-size:1.2rem;color:rgba(255,255,255,.7);margin-bottom:8px;display:block"></i>
            <div style="font-size:.68rem;text-transform:uppercase;letter-spacing:.07em;color:rgba(255,255,255,.6);margin-bottom:4px"><?= $name ?></div>
            <div style="font-size:2rem;font-weight:900;font-family:var(--font-display);line-height:1"><?= number_format($val,2) ?></div>
            <div style="font-size:.7rem;color:rgba(255,255,255,.5);margin-top:2px">mean / 5.00</div>
        </div>
        <?php endforeach; ?>
    </div>
    <!-- Overall PI -->
    <div style="background:rgba(255,255,255,.15);border-radius:12px;padding:16px 20px;display:flex;align-items:center;flex-wrap:wrap;gap:16px">
        <div style="flex:1;min-width:160px">
            <div style="font-size:.7rem;text-transform:uppercase;letter-spacing:.07em;color:rgba(255,255,255,.6);margin-bottom:4px">Purchase Intention (Normalized)</div>
            <div style="font-size:2.8rem;font-weight:900;font-family:var(--font-display);line-height:1"><?= number_format($norm_pi,2) ?></div>
            <div style="font-size:.75rem;color:rgba(255,255,255,.6);margin-top:4px">
                Raw PI: <?= number_format($raw_pi,2) ?>/15 &nbsp;·&nbsp; Formula: ATT + SN + PBC ÷ 3
            </div>
            <div style="height:6px;background:rgba(255,255,255,.2);border-radius:999px;overflow:hidden;margin-top:10px">
                <div style="height:100%;background:#fff;border-radius:999px;width:<?= min(round(($norm_pi/5)*100),100) ?>%"></div>
            </div>
        </div>
        <div style="display:flex;flex-direction:column;gap:6px">
            <span style="background:<?= $pi_lbl[1] ?>;color:#fff;padding:5px 14px;border-radius:999px;font-size:.8rem;font-weight:700"><?= $pi_lbl[0] ?> Intent</span>
            <span style="background:rgba(255,255,255,.15);color:#fff;padding:5px 14px;border-radius:999px;font-size:.78rem;font-weight:600">PI Index: <?= $pi_pct ?>%</span>
            <span style="background:rgba(255,255,255,.15);color:#a4f3cb;padding:5px 14px;border-radius:999px;font-size:.78rem;font-weight:700"><i class="fas fa-trophy" style="margin-right:4px"></i><?= htmlspecialchars($dominant) ?></span>
        </div>
    </div>
</div>

<!-- Item breakdown table -->
<div class="survey-section">
    <div class="survey-section-header" style="background:var(--gradient-primary)">
        <div class="survey-section-icon"><i class="fas fa-table"></i></div>
        <div>
            <div class="survey-section-title">Your Item-Level Responses</div>
            <div class="survey-section-subtitle">Set <?= $ex_q_set ?> (<?= $ex_q_set===1?'Muslim':'Non-Muslim' ?>) · 15 items across ATT, SN, PBC</div>
        </div>
    </div>
    <div style="padding:0;overflow-x:auto">
        <table style="width:100%;border-collapse:collapse;font-size:.85rem">
            <thead>
                <tr style="background:var(--neutral-50)">
                    <th style="padding:10px 18px;text-align:left;color:var(--neutral-500);font-weight:600;border-bottom:1px solid var(--neutral-200)">Item</th>
                    <th style="padding:10px 18px;text-align:left;color:var(--neutral-500);font-weight:600;border-bottom:1px solid var(--neutral-200)">Construct</th>
                    <th style="padding:10px 18px;text-align:left;color:var(--neutral-500);font-weight:600;border-bottom:1px solid var(--neutral-200);max-width:360px">Statement</th>
                    <th style="padding:10px 18px;text-align:center;color:var(--neutral-500);font-weight:600;border-bottom:1px solid var(--neutral-200)">Score</th>
                </tr>
            </thead>
            <tbody>
            <?php
            // Show items matching the set actually used
            $display_att = ($ex_q_set===1) ? $q1_att : $q2_att;
            $display_sn  = ($ex_q_set===1) ? $q1_sn  : $q2_sn;
            $display_pbc = ($ex_q_set===1) ? $q1_pbc : $q2_pbc;
            $row_items = array_merge(
                array_map(fn($k,$v)=>['code'=>$k,'construct'=>'Attitude (ATT)','text'=>$v,'bg'=>'#dcfce7','col'=>'#15803d'], array_keys($display_att), $display_att),
                array_map(fn($k,$v)=>['code'=>$k,'construct'=>'Subjective Norms (SN)','text'=>$v,'bg'=>'#dbeafe','col'=>'#1d4ed8'], array_keys($display_sn), $display_sn),
                array_map(fn($k,$v)=>['code'=>$k,'construct'=>'Perceived Behavioral Control (PBC)','text'=>$v,'bg'=>'#fef3c7','col'=>'#92400e'], array_keys($display_pbc), $display_pbc)
            );
            $badge_colors = [1=>'#fef2f2;color:#dc2626',2=>'#fff7ed;color:#c2410c',3=>'#fefce8;color:#854d0e',4=>'#f0fdf4;color:#15803d',5=>'#ecfdf5;color:#047857'];
            foreach ($row_items as $item):
                $sc  = $prior_answers[$item['code']] ?? null;
                $lbl = $sc ? $likert_labels[$sc] : '—';
                $bc  = $sc ? ($badge_colors[$sc]??'') : '';
            ?>
            <tr style="border-bottom:1px solid var(--neutral-100)">
                <td style="padding:10px 18px;font-weight:700;color:<?= $item['col'] ?>"><?= $item['code'] ?></td>
                <td style="padding:10px 18px;white-space:nowrap">
                    <span style="background:<?= $item['bg'] ?>;color:<?= $item['col'] ?>;padding:2px 8px;border-radius:5px;font-size:.75rem;font-weight:600"><?= htmlspecialchars($item['construct']) ?></span>
                </td>
                <td style="padding:10px 18px;color:var(--neutral-600);font-size:.82rem;line-height:1.4;max-width:340px"><?= htmlspecialchars($item['text']) ?></td>
                <td style="padding:10px 18px;text-align:center">
                    <?php if ($bc): ?><span style="background:<?= $bc ?>;padding:3px 12px;border-radius:999px;font-weight:700;font-size:.85rem"><?= $sc ?></span><?php else: ?><span style="color:var(--neutral-300)">—</span><?php endif; ?>
                </td>
            </tr>
            <?php endforeach; ?>
            </tbody>
        </table>
    </div>
</div>

<div style="display:flex;gap:12px;flex-wrap:wrap">
    <a href="?retake=1" class="btn-survey-submit" style="background:linear-gradient(135deg,var(--accent-600),var(--accent-500))">
        <i class="fas fa-redo"></i> Retake Survey
    </a>
    <a href="<?= BASE_URL ?>dashboard/customer/" class="btn-survey-submit" style="background:var(--neutral-700)">
        <i class="fas fa-home"></i> Back to Dashboard
    </a>
</div>

<?php else: ?>
<!-- ══════════════════════════════════════════════════════════════════════════
     SURVEY FORM
══════════════════════════════════════════════════════════════════════════ -->
<?php if ($existing && isset($_GET['retake'])): ?>
<div class="incomplete-alert" style="background:#eff6ff;border-color:#93c5fd;color:#1e40af">
    <i class="fas fa-info-circle"></i>
    <div>You are retaking the survey. Your previous results will be updated on submission.</div>
</div>
<?php endif; ?>

<!-- ── Data Privacy Notice ───────────────────────────────────────────────── -->
<div id="privacyNoticePanel"
     style="background:#fff;border:2px solid #d1fae5;border-radius:var(--radius-lg);
            padding:28px 32px;margin-bottom:24px;box-shadow:var(--shadow-sm);">

    <!-- Header -->
    <div style="display:flex;align-items:center;gap:14px;margin-bottom:20px;">
        <div style="width:46px;height:46px;border-radius:var(--radius-md);background:#ecfdf5;
                    display:flex;align-items:center;justify-content:center;flex-shrink:0;">
            <i class="fas fa-shield-halved" style="color:#059669;font-size:1.2rem;"></i>
        </div>
        <div>
            <h3 style="margin:0;font-size:1.05rem;font-weight:800;color:var(--neutral-800);
                       font-family:var(--font-display);">
                Data Privacy Notice
            </h3>
            <p style="margin:3px 0 0;font-size:.78rem;color:var(--neutral-500);">
                Republic Act No. 10173 — Data Privacy Act of 2012
            </p>
        </div>
    </div>

    <!-- Notice body -->
    <div style="font-size:.88rem;color:var(--neutral-700);line-height:1.75;
                background:var(--neutral-50);border-radius:var(--radius-md);
                padding:20px 24px;border:1px solid var(--neutral-200);margin-bottom:20px;">
        <p style="margin:0 0 14px;">
            This questionnaire is being conducted for <strong>academic and research purposes</strong>
            in connection with the <strong>Halal Keeps</strong> project. In accordance with the
            <strong>Data Privacy Act of 2012 (Republic Act No. 10173)</strong>, any personal
            information collected through this questionnaire will be handled with appropriate
            confidentiality and used only for the purposes of this study.
        </p>
        <p style="margin:0 0 14px;">
            Participation in this survey is <strong>voluntary</strong>. You may choose not to answer
            any question or discontinue your participation at any time without penalty. Your responses
            will be analyzed primarily in <strong>summarized or aggregated form</strong> and will not
            be disclosed in a manner that directly identifies you.
        </p>
        <p style="margin:0 0 14px;">
            Any information collected will be accessible only to the researchers and, when necessary,
            their authorized academic adviser or institution for legitimate academic purposes. The
            researchers will take reasonable measures to protect the information collected from
            unauthorized access, disclosure, alteration, or misuse.
        </p>
        <p style="margin:0;">
            Research data will be retained only for the period necessary to complete the study and
            applicable academic requirements and will thereafter be securely disposed of.
        </p>
    </div>

    <!-- Consent checkbox -->
    <label id="privacyConsentLabel"
           style="display:flex;align-items:flex-start;gap:12px;cursor:pointer;
                  padding:14px 18px;border-radius:var(--radius-md);
                  border:2px solid var(--neutral-200);background:#fff;
                  transition:border-color .15s,background .15s;"
           onmouseenter="this.style.borderColor='var(--primary-300)';this.style.background='#f0fdf4';"
           onmouseleave="this.style.borderColor=document.getElementById('privacyConsent').checked?'var(--primary-500)':'var(--neutral-200)';
                         this.style.background=document.getElementById('privacyConsent').checked?'#f0fdf4':'#fff';">
        <input type="checkbox"
               id="privacyConsent"
               name="privacy_consent"
               style="width:20px;height:20px;accent-color:var(--primary-600);
                      cursor:pointer;flex-shrink:0;margin-top:2px;"
               onchange="handlePrivacyConsent(this)">
        <span style="font-size:.875rem;color:var(--neutral-700);line-height:1.55;">
            By proceeding with this questionnaire, I acknowledge that I have read and understood
            this notice and <strong>voluntarily consent</strong> to the collection and processing
            of my responses for the purposes described above.
        </span>
    </label>

    <!-- Locked notice shown until consent is given -->
    <div id="privacyLockMsg"
         style="margin-top:14px;display:flex;align-items:center;gap:8px;
                font-size:.8rem;color:#b45309;font-weight:600;">
        <i class="fas fa-lock"></i>
        Please read and accept the data privacy notice above to proceed with the survey.
    </div>
</div>

<!-- Sticky progress -->
<div class="sticky-tracker">
    <div style="max-width:860px;margin:0 auto;padding:0 16px;display:flex;align-items:center;gap:14px">
        <div style="flex:1">
            <div class="survey-progress-bar" style="margin-bottom:4px">
                <div class="survey-progress-fill" id="surveyProgressFill" style="width:0%"></div>
            </div>
            <div style="font-size:.78rem;color:var(--neutral-500)">
                <span id="answeredCount">0</span> of <?= $total_items ?> questions answered
            </div>
        </div>
        <div style="font-size:.82rem;color:var(--neutral-600);font-weight:600;white-space:nowrap">
            <i class="fas fa-clock" style="color:var(--primary-600)"></i>&nbsp;~4 min
        </div>
    </div>
</div>

<form id="tpbSurveyForm" method="POST" action="<?= BASE_URL ?>api/submit_survey.php" novalidate>
    <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($_SESSION['csrf_token'] ?? '') ?>">
    <?php
    // Preserve return_url so submit_survey.php redirects the customer back after completion
    $return_url_param = trim($_GET['return_url'] ?? '');
    if ($return_url_param !== ''): ?>
    <input type="hidden" name="return_url" value="<?= htmlspecialchars($return_url_param) ?>">
    <?php endif; ?>

<?php
// Shared Likert renderer
function renderLikertItem(string $code, string $text, int $num, string $numBg, string $numCol, array $prior, array $labels): void {
    $checked = fn(int $v) => (($prior[$code] ?? 0) == $v) ? 'checked' : '';
    echo "<div class=\"survey-item\" id=\"item-{$code}\">";
    echo "<div class=\"item-header\">";
    echo "<span class=\"item-number\" style=\"background:{$numBg};color:{$numCol}\">{$num}</span>";
    echo "<span class=\"item-text\">" . htmlspecialchars($text) . "</span>";
    echo "</div>";
    echo "<div class=\"likert-scale\" role=\"radiogroup\" aria-label=\"{$code}\">";
    foreach ($labels as $v => $l) {
        $c = $checked($v);
        echo "<div class=\"likert-option\" data-val=\"{$v}\">";
        echo "<input type=\"radio\" name=\"{$code}\" id=\"{$code}_{$v}\" value=\"{$v}\" {$c}>";
        echo "<label for=\"{$code}_{$v}\"><span class=\"score-num\">{$v}</span><span style=\"font-size:.62rem;line-height:1.2;text-align:center\">{$l}</span></label>";
        echo "</div>";
    }
    echo "</div></div>";
}
?>

    <!-- Section A: ATT -->
    <div class="survey-section">
        <div class="survey-section-header" style="background:linear-gradient(135deg,#065f46,#047857)">
            <div class="survey-section-icon"><i class="fas fa-heart"></i></div>
            <div>
                <div class="survey-section-title">Section A: Attitude toward Halal Food (ATT)</div>
                <div class="survey-section-subtitle">
                    <?= $q_set===1 ? 'How you personally evaluate Halal dining — 5 items' : 'Your personal perception of Halal-certified food — 5 items' ?>
                </div>
            </div>
        </div>
        <div class="survey-section-body">
            <?php $n=1; foreach ($att_items as $code=>$text): renderLikertItem($code,$text,$n,'#dcfce7','#15803d',$prior_answers,$likert_labels); $n++; endforeach; ?>
        </div>
    </div>

    <!-- Section B: SN -->
    <div class="survey-section">
        <div class="survey-section-header" style="background:linear-gradient(135deg,#1d4ed8,#1e40af)">
            <div class="survey-section-icon"><i class="fas fa-users"></i></div>
            <div>
                <div class="survey-section-title">Section B: Subjective Norms towards Halal Food (SN)</div>
                <div class="survey-section-subtitle">
                    <?= $q_set===1 ? 'Social influence from family, peers, and community — 5 items' : 'Social comfort and peer influence toward Halal dining — 5 items' ?>
                </div>
            </div>
        </div>
        <div class="survey-section-body">
            <?php $n=1; foreach ($sn_items as $code=>$text): renderLikertItem($code,$text,$n,'#dbeafe','#1d4ed8',$prior_answers,$likert_labels); $n++; endforeach; ?>
        </div>
    </div>

    <!-- Section C: PBC -->
    <div class="survey-section">
        <div class="survey-section-header" style="background:linear-gradient(135deg,#92400e,#b45309)">
            <div class="survey-section-icon"><i class="fas fa-hand-pointer"></i></div>
            <div>
                <div class="survey-section-title">Section C: Perceived Behavioral Control (PBC)</div>
                <div class="survey-section-subtitle">
                    <?= $q_set===1 ? 'Ease, access, and autonomy in choosing Halal options — 5 items' : 'Accessibility, affordability, and menu suitability of Halal options — 5 items' ?>
                </div>
            </div>
        </div>
        <div class="survey-section-body">
            <?php $n=1; foreach ($pbc_items as $code=>$text): renderLikertItem($code,$text,$n,'#fef3c7','#92400e',$prior_answers,$likert_labels); $n++; endforeach; ?>
        </div>
    </div>

    <!-- Submit row -->
    <div style="display:flex;align-items:center;justify-content:space-between;flex-wrap:wrap;gap:14px;padding:20px 0">
        <div style="font-size:.82rem;color:var(--neutral-500)">
            <i class="fas fa-shield-alt" style="color:var(--primary-600)"></i>
            Responses are anonymized in aggregated analytics. Your individual answers are private.
        </div>
        <button type="submit" class="btn-survey-submit" id="submitBtn" disabled>
            <i class="fas fa-paper-plane"></i>
            <?= $existing ? 'Update My Survey' : 'Submit Survey' ?>
        </button>
    </div>

    <div id="surveyError" class="incomplete-alert" style="display:none">
        <i class="fas fa-exclamation-triangle"></i>
        <div id="surveyErrorMsg">Please answer all <?= $total_items ?> questions before submitting.</div>
    </div>
</form>
<?php endif; ?>
</div><!-- /survey-wrapper -->

<script>
(function(){
    'use strict';
    const TOTAL  = <?= $total_items ?>;
    const CODES  = ['ATT1','ATT2','ATT3','ATT4','ATT5','SN1','SN2','SN3','SN4','SN5','PBC1','PBC2','PBC3','PBC4','PBC5'];
    const form   = document.getElementById('tpbSurveyForm');
    const fill   = document.getElementById('surveyProgressFill');
    const count  = document.getElementById('answeredCount');
    const errBnr = document.getElementById('surveyError');
    const errMsg = document.getElementById('surveyErrorMsg');
    const btn    = document.getElementById('submitBtn');
    const PROFILE_INCOMPLETE = <?= $profile_incomplete ? 'true' : 'false' ?>;

    // ── Privacy consent handler ──────────────────────────────────────────────
    window.handlePrivacyConsent = function(checkbox) {
        const lockMsg    = document.getElementById('privacyLockMsg');
        const consentLbl = document.getElementById('privacyConsentLabel');

        if (checkbox.checked) {
            // Update consent label style
            if (consentLbl) {
                consentLbl.style.borderColor = 'var(--primary-500)';
                consentLbl.style.background  = '#f0fdf4';
            }
            // Hide lock message
            if (lockMsg) lockMsg.style.display = 'none';
            // Enable submit unless profile is incomplete
            if (btn && !PROFILE_INCOMPLETE) btn.disabled = false;
        } else {
            if (consentLbl) {
                consentLbl.style.borderColor = 'var(--neutral-200)';
                consentLbl.style.background  = '#fff';
            }
            if (lockMsg) lockMsg.style.display = 'flex';
            if (btn) btn.disabled = true;
        }
    };

    function updateProgress() {
        if (!form) return 0;
        const answered = CODES.filter(c => form.querySelector(`input[name="${c}"]:checked`)).length;
        const pct = Math.round((answered / TOTAL) * 100);
        if (fill)  fill.style.width  = pct + '%';
        if (count) count.textContent = answered;
        return answered;
    }

    if (form) {
        form.addEventListener('change', () => {
            updateProgress();
            document.querySelectorAll('.survey-item.has-error').forEach(el => {
                const code = el.id.replace('item-','');
                if (form.querySelector(`input[name="${code}"]:checked`)) el.classList.remove('has-error');
            });
            if (errBnr) errBnr.style.display = 'none';
        });

        form.addEventListener('submit', e => {
            const answered = updateProgress();
            if (answered < TOTAL) {
                e.preventDefault();
                const missing = CODES.filter(c => !form.querySelector(`input[name="${c}"]:checked`));
                missing.forEach(c => { const el = document.getElementById('item-'+c); if(el) el.classList.add('has-error'); });
                if (errMsg) errMsg.textContent = `Please answer all ${TOTAL} questions. Unanswered: ${missing.join(', ')}.`;
                if (errBnr) { errBnr.style.display = 'flex'; errBnr.scrollIntoView({behavior:'smooth',block:'center'}); }
                return;
            }
            if (btn) { btn.disabled = true; btn.innerHTML = '<i class="fas fa-spinner fa-spin"></i> Submitting…'; }
        });
    }
    updateProgress();
})();
</script>
<?php require_once __DIR__ . '/../../includes/footer.php'; ?>
