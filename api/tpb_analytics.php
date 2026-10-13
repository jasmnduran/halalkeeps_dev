<?php
/**
 * TPB Analytics API — v2 (ATT + SN + PBC)
 *
 * GET ?scope=public  — landing page barometer (no auth)
 * GET ?scope=owner   — full breakdown incl. construct means (ROLE_BUSINESS_OWNER)
 *
 * PI formula: Raw PI = Mean(ATT)+Mean(SN)+Mean(PBC)  [3-15]
 *             Norm PI = Raw PI / 3                    [1-5]
 *             PI%     = (Norm PI - 1) / 4 * 100
 */
require_once __DIR__ . '/../config/app.php';
header('Content-Type: application/json; charset=utf-8');

$scope = strtolower($_GET['scope'] ?? 'public');
if ($scope === 'owner') {
    requireLogin();
    requireRole([ROLE_BUSINESS_OWNER, ROLE_ADMIN]);
}

// ── Graceful empty if table missing ──────────────────────────────────────────
$tbl = $conn->query("SHOW TABLES LIKE 'tpb_survey_responses'");
if (!$tbl || $tbl->num_rows === 0) {
    echo json_encode(['success'=>true,'total_respondents'=>0,'muslim'=>null,'non_muslim'=>null,'overall'=>null,
        'note'=>'Survey tables not yet created. Run tpb_pbc_migration.sql.']);
    exit();
}

// ── Check normalized_pi column exists (pre-migration tables) ─────────────────
$col_check = $conn->query("SHOW COLUMNS FROM tpb_survey_responses LIKE 'normalized_pi'");
$has_norm  = ($col_check && $col_check->num_rows > 0);
$pi_col    = $has_norm ? 'normalized_pi' : 'predicted_pi';
$pbc_col   = $has_norm ? 'mean_pbc' : 'NULL';

// ── Helper ────────────────────────────────────────────────────────────────────
function fetchGroup(mysqli $conn, ?string $religion, string $pi_col, string $pbc_col): array {
    $where = $religion !== null
        ? "WHERE religion = '" . $conn->real_escape_string($religion) . "' AND {$pi_col} IS NOT NULL"
        : "WHERE {$pi_col} IS NOT NULL";

    $row = $conn->query("
        SELECT COUNT(*) AS n,
               COALESCE(ROUND(AVG(mean_att),3),0)      AS avg_att,
               COALESCE(ROUND(AVG(mean_sn),3),0)       AS avg_sn,
               COALESCE(ROUND(AVG({$pbc_col}),3),0)    AS avg_pbc,
               COALESCE(ROUND(AVG({$pi_col}),3),0)     AS avg_pi
        FROM tpb_survey_responses {$where}
    ")?->fetch_assoc();

    if (!$row || (int)$row['n'] === 0) {
        return ['n'=>0,'avg_att'=>0,'avg_sn'=>0,'avg_pbc'=>0,'avg_pi'=>0,
                'pi_percentage'=>0,'dominant'=>'N/A'];
    }

    $pi  = (float)$row['avg_pi'];
    $att = (float)$row['avg_att'];
    $sn  = (float)$row['avg_sn'];
    $pbc = (float)$row['avg_pbc'];

    $pi_pct = round((($pi - 1) / 4) * 100, 1);

    $dominant = match(true) {
        $att >= $sn && $att >= $pbc => 'Attitude (ATT)',
        $sn  >= $att && $sn >= $pbc  => 'Subjective Norms (SN)',
        default                       => 'Perceived Behavioral Control (PBC)',
    };

    return [
        'n'            => (int)$row['n'],
        'avg_att'      => $att,
        'avg_sn'       => $sn,
        'avg_pbc'      => $pbc,
        'avg_pi'       => $pi,
        'pi_percentage'=> $pi_pct,
        'dominant'     => $dominant,
    ];
}

$muslim     = fetchGroup($conn, 'Muslim',     $pi_col, $pbc_col);
$non_muslim = fetchGroup($conn, 'Non-Muslim', $pi_col, $pbc_col);
$overall    = fetchGroup($conn, null,         $pi_col, $pbc_col);

// ── Owner-only extras ─────────────────────────────────────────────────────────
$item_averages = [];
if ($scope === 'owner') {
    $res = $conn->query("
        SELECT r.religion, a.item_code, ROUND(AVG(a.score),3) AS avg_score, COUNT(*) AS n
        FROM tpb_survey_answers a
        JOIN tpb_survey_responses r ON a.response_id = r.id
        GROUP BY r.religion, a.item_code
        ORDER BY r.religion, a.item_code
    ");
    if ($res) {
        while ($ir = $res->fetch_assoc()) {
            $item_averages[$ir['religion']][$ir['item_code']] = [
                'avg' => (float)$ir['avg_score'],
                'n'   => (int)$ir['n'],
            ];
        }
    }
}

echo json_encode([
    'success'           => true,
    'total_respondents' => $overall['n'],
    'formula'           => 'PI = Mean(ATT) + Mean(SN) + Mean(PBC); Norm PI = Raw/3',
    'muslim'            => $muslim,
    'non_muslim'        => $non_muslim,
    'overall'           => $overall,
    'item_averages'     => $item_averages,
    'generated_at'      => date('Y-m-d H:i:s'),
], JSON_UNESCAPED_UNICODE);
exit();
