<?php
/**
 * TPB Survey Submission API — v2 (ATT + SN + PBC)
 *
 * Accepts 15 Likert items (ATT1-5, SN1-5, PBC1-5).
 * Questionnaire set is chosen by religion:
 *   Set 1 — Muslim respondents
 *   Set 2 — Non-Muslim respondents
 *
 * Formula:
 *   Mean(ATT) = sum(ATT1-5) / 5
 *   Mean(SN)  = sum(SN1-5)  / 5
 *   Mean(PBC) = sum(PBC1-5) / 5
 *   Raw PI    = Mean(ATT) + Mean(SN) + Mean(PBC)   [range 3.0 – 15.0]
 *   Norm PI   = Raw PI / 3                          [range 1.0 –  5.0]
 */
require_once __DIR__ . '/../config/app.php';
requireLogin();
requireRole([ROLE_CUSTOMER]);

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    header('Location: ' . BASE_URL . 'dashboard/customer/tpb_survey.php');
    exit();
}

$user_id = (int)$_SESSION['user_id'];

// ── Capture return_url (passed from ordering page) ────────────────────────────
$return_url = '';
if (!empty($_POST['return_url'])) {
    $candidate = filter_var($_POST['return_url'], FILTER_SANITIZE_URL);
    // Only allow relative paths or same-host URLs to prevent open-redirect
    $parsed = parse_url($candidate);
    $host   = $_SERVER['HTTP_HOST'] ?? '';
    if (empty($parsed['host']) || $parsed['host'] === $host) {
        $return_url = $candidate;
    }
}
$survey_base_url = BASE_URL . 'dashboard/customer/tpb_survey.php'
    . ($return_url ? '?return_url=' . urlencode($return_url) : '');

// ── User profile ──────────────────────────────────────────────────────────────
$prof = $conn->prepare("SELECT religion, food_preference FROM users WHERE id = ? LIMIT 1");
$prof->bind_param("i", $user_id);
$prof->execute();
$profile = $prof->get_result()->fetch_assoc();
$prof->close();

if (empty($profile['religion'])) {
    $_SESSION['survey_error'] = 'Your Religion field is not set. Please update your profile before submitting.';
    header('Location: ' . $survey_base_url);
    exit();
}

$religion        = $profile['religion'];
$food_preference = $profile['food_preference'] ?? null;
$q_set           = ($religion === 'Muslim') ? 1 : 2;

// ── Item codes — 15 total ────────────────────────────────────────────────────
$att_codes = ['ATT1','ATT2','ATT3','ATT4','ATT5'];
$sn_codes  = ['SN1', 'SN2', 'SN3', 'SN4', 'SN5'];
$pbc_codes = ['PBC1','PBC2','PBC3','PBC4','PBC5'];
$all_codes = array_merge($att_codes, $sn_codes, $pbc_codes);

// ── Validate & collect ────────────────────────────────────────────────────────
$scores  = [];
$missing = [];

foreach ($all_codes as $code) {
    $raw = $_POST[$code] ?? null;
    if ($raw === null || $raw === '') {
        $missing[] = $code;
        continue;
    }
    $val = (int)$raw;
    if ($val < 1 || $val > 5) {
        $_SESSION['survey_error'] = "Invalid score for {$code}. Must be 1–5.";
        header('Location: ' . $survey_base_url);
        exit();
    }
    $scores[$code] = $val;
}

if (!empty($missing)) {
    $_SESSION['survey_error'] = 'Please answer all 15 questions. Missing: ' . implode(', ', $missing);
    header('Location: ' . $survey_base_url);
    exit();
}

// ── Compute construct means ───────────────────────────────────────────────────
$mean_att = round(array_sum(array_map(fn($c) => $scores[$c], $att_codes)) / 5, 3);
$mean_sn  = round(array_sum(array_map(fn($c) => $scores[$c], $sn_codes))  / 5, 3);
$mean_pbc = round(array_sum(array_map(fn($c) => $scores[$c], $pbc_codes)) / 5, 3);

// ── PI formula ────────────────────────────────────────────────────────────────
$raw_pi        = round($mean_att + $mean_sn + $mean_pbc, 3);   // 3.0 – 15.0
$normalized_pi = round($raw_pi / 3, 3);                         // 1.0 –  5.0
$pi_percentage = round((($normalized_pi - 1) / 4) * 100, 1);   // 20% – 100%

$intent_label = match(true) {
    $normalized_pi >= 4.5 => 'Very High',
    $normalized_pi >= 3.5 => 'High',
    $normalized_pi >= 2.5 => 'Moderate',
    $normalized_pi >= 1.5 => 'Low',
    default               => 'Very Low',
};

$dominant = match(true) {
    $mean_att >= $mean_sn && $mean_att >= $mean_pbc => 'Attitude (ATT)',
    $mean_sn  >= $mean_att && $mean_sn >= $mean_pbc  => 'Subjective Norms (SN)',
    default                                           => 'Perceived Behavioral Control (PBC)',
};

// ── Idempotent schema migration ───────────────────────────────────────────────
$conn->query("CREATE TABLE IF NOT EXISTS `tpb_survey_responses` (
    `id`               INT UNSIGNED   NOT NULL AUTO_INCREMENT,
    `user_id`          INT(11)        NOT NULL,
    `religion`         ENUM('Muslim','Non-Muslim') NOT NULL,
    `food_preference`  ENUM('Halal','Non-Pork','Muslim-Friendly','Any') DEFAULT NULL,
    `questionnaire_set` TINYINT(1)   NOT NULL DEFAULT 1,
    `mean_att`         DECIMAL(4,3)  DEFAULT NULL,
    `mean_sn`          DECIMAL(4,3)  DEFAULT NULL,
    `mean_pbc`         DECIMAL(4,3)  DEFAULT NULL,
    `raw_pi`           DECIMAL(5,3)  DEFAULT NULL,
    `normalized_pi`    DECIMAL(4,3)  DEFAULT NULL,
    `mean_pi_actual`   DECIMAL(4,3)  DEFAULT NULL,
    `predicted_pi`     DECIMAL(5,4)  DEFAULT NULL,
    `submitted_at`     TIMESTAMP     NOT NULL DEFAULT CURRENT_TIMESTAMP,
    `updated_at`       TIMESTAMP     NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (`id`),
    UNIQUE KEY `uq_survey_user` (`user_id`),
    KEY `idx_tpb_religion`  (`religion`),
    KEY `idx_tpb_submitted` (`submitted_at`),
    CONSTRAINT `tpb_user_fk`
        FOREIGN KEY (`user_id`) REFERENCES `users` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");

// Add new columns idempotently for existing tables
foreach ([
    ['questionnaire_set', "TINYINT(1) NOT NULL DEFAULT 1"],
    ['mean_pbc',          "DECIMAL(4,3) DEFAULT NULL"],
    ['raw_pi',            "DECIMAL(5,3) DEFAULT NULL"],
    ['normalized_pi',     "DECIMAL(4,3) DEFAULT NULL"],
] as [$col, $def]) {
    if (function_exists('db_add_column')) {
        db_add_column($conn, 'tpb_survey_responses', $col, $def);
    }
}

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

// ── Upsert response row ───────────────────────────────────────────────────────
$upsert = $conn->prepare("
    INSERT INTO tpb_survey_responses
        (user_id, religion, food_preference, questionnaire_set,
         mean_att, mean_sn, mean_pbc, raw_pi, normalized_pi, predicted_pi)
    VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?)
    ON DUPLICATE KEY UPDATE
        religion          = VALUES(religion),
        food_preference   = VALUES(food_preference),
        questionnaire_set = VALUES(questionnaire_set),
        mean_att          = VALUES(mean_att),
        mean_sn           = VALUES(mean_sn),
        mean_pbc          = VALUES(mean_pbc),
        raw_pi            = VALUES(raw_pi),
        normalized_pi     = VALUES(normalized_pi),
        predicted_pi      = VALUES(predicted_pi),
        updated_at        = CURRENT_TIMESTAMP
");
$upsert->bind_param(
    "issidddddd",
    $user_id, $religion, $food_preference, $q_set,
    $mean_att, $mean_sn, $mean_pbc, $raw_pi, $normalized_pi, $normalized_pi
);

if (!$upsert->execute()) {
    $_SESSION['survey_error'] = 'Database error saving response. Please try again.';
    header('Location: ' . $survey_base_url);
    exit();
}

$id_row      = $conn->query("SELECT id FROM tpb_survey_responses WHERE user_id = $user_id LIMIT 1")->fetch_assoc();
$response_id = (int)$id_row['id'];

// ── Replace answers ───────────────────────────────────────────────────────────
$conn->query("DELETE FROM tpb_survey_answers WHERE response_id = $response_id");
$ins = $conn->prepare("INSERT INTO tpb_survey_answers (response_id, item_code, score) VALUES (?, ?, ?)");
foreach ($all_codes as $code) {
    $sc = $scores[$code];
    $ins->bind_param("isi", $response_id, $code, $sc);
    $ins->execute();
}
$ins->close();

// ── Log ───────────────────────────────────────────────────────────────────────
if (function_exists('logActivity')) {
    logActivity($conn, $user_id, 'TPB Survey Submitted',
        "Set={$q_set}, NormPI={$normalized_pi}, ATT={$mean_att}, SN={$mean_sn}, PBC={$mean_pbc}, Religion={$religion}", 'survey');
}

// ── Flash & redirect ─────────────────────────────────────────────────────────
$_SESSION['survey_flash'] = [
    'normalized_pi'   => $normalized_pi,
    'raw_pi'          => $raw_pi,
    'pi_percentage'   => $pi_percentage,
    'intent_label'    => $intent_label,
    'dominant_driver' => $dominant,
    'mean_att'        => $mean_att,
    'mean_sn'         => $mean_sn,
    'mean_pbc'        => $mean_pbc,
    'questionnaire_set' => $q_set,
];

// If the customer came from an ordering page, send them straight back there.
// The survey results banner is shown on tpb_survey.php, so we skip it when
// a return_url is present — the customer can view results later from their profile.
if ($return_url) {
    $_SESSION['survey_complete_flash'] = 'Survey completed! You can now place your order.';
    header('Location: ' . $return_url);
} else {
    header('Location: ' . BASE_URL . 'dashboard/customer/tpb_survey.php');
}
exit();
