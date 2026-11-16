<?php
/**
 * HaCeRa AJAX API
 * Handles: save_response, upload_document, delete_document, get_scores, generate_pdf
 *
 * All responses are JSON.  Auth: ROLE_BUSINESS_OWNER only (no staff sub-roles —
 * HaCeRa is an owner-level tool tied to the restaurant, not a staff function).
 */
declare(strict_types=1);

require_once __DIR__ . '/../../../config/app.php';
require_once __DIR__ . '/../../../includes/HaCeRaCalculator.php';
require_once __DIR__ . '/../../../includes/RevenuePaymentService.php';
require_once __DIR__ . '/../../../vendor/autoload.php';

header('Content-Type: application/json; charset=utf-8');

// ── Auth guard ────────────────────────────────────────────────────────────────
if (!isLoggedIn() || (int)($_SESSION['role_id'] ?? 0) !== ROLE_BUSINESS_OWNER) {
    http_response_code(403);
    echo json_encode(['success' => false, 'message' => 'Unauthorized.']);
    exit();
}


$user_id = (int)$_SESSION['user_id'];

// ── Resolve restaurant ────────────────────────────────────────────────────────
$rest_stmt = $conn->prepare(
    "SELECT id FROM halal_restaurants WHERE business_owner_id = ? AND is_active = 1 LIMIT 1"
);
$rest_stmt->bind_param('i', $user_id);
$rest_stmt->execute();
$rest_row = $rest_stmt->get_result()->fetch_assoc();
$rest_stmt->close();

if (!$rest_row) {
    echo json_encode(['success' => false, 'message' => 'No active restaurant found for this account.']);
    exit();
}
$rest_id = (int)$rest_row['id'];

// ── Idempotent schema migrations ──────────────────────────────────────────────
$conn->query("CREATE TABLE IF NOT EXISTS `hacera_modules` (
    `id`          TINYINT UNSIGNED NOT NULL,
    `title`       VARCHAR(100)     NOT NULL,
    `description` TEXT             DEFAULT NULL,
    `sort_order`  TINYINT UNSIGNED NOT NULL DEFAULT 0,
    PRIMARY KEY (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");

$conn->query("CREATE TABLE IF NOT EXISTS `hacera_checklists` (
    `id`          SMALLINT UNSIGNED NOT NULL,
    `module_id`   TINYINT UNSIGNED  NOT NULL,
    `label`       VARCHAR(255)      NOT NULL,
    `item_type`   ENUM('upload','yesno') NOT NULL DEFAULT 'upload',
    `weight`      TINYINT UNSIGNED  NOT NULL DEFAULT 2 COMMENT '3=High,2=Standard,1=Info',
    `is_optional` TINYINT(1)        NOT NULL DEFAULT 0,
    `sort_order`  SMALLINT UNSIGNED NOT NULL DEFAULT 0,
    PRIMARY KEY (`id`),
    KEY `idx_hc_module` (`module_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");

$conn->query("CREATE TABLE IF NOT EXISTS `business_hacera_responses` (
    `id`               INT UNSIGNED      NOT NULL AUTO_INCREMENT,
    `restaurant_id`    INT(11)           NOT NULL,
    `checklist_item_id` SMALLINT UNSIGNED NOT NULL,
    `response_value`   ENUM('yes','no','na') NOT NULL DEFAULT 'no',
    `notes`            TEXT              DEFAULT NULL,
    `responded_by`     INT(11)           NOT NULL,
    `created_at`       TIMESTAMP         NOT NULL DEFAULT CURRENT_TIMESTAMP,
    `updated_at`       TIMESTAMP         NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (`id`),
    UNIQUE KEY `uq_bhr_rest_item` (`restaurant_id`, `checklist_item_id`),
    KEY `idx_bhr_rest` (`restaurant_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");

$conn->query("CREATE TABLE IF NOT EXISTS `hacera_documents` (
    `id`               INT UNSIGNED      NOT NULL AUTO_INCREMENT,
    `restaurant_id`    INT(11)           NOT NULL,
    `checklist_item_id` SMALLINT UNSIGNED NOT NULL,
    `file_path`        VARCHAR(500)      NOT NULL,
    `original_name`    VARCHAR(255)      NOT NULL DEFAULT '',
    `uploaded_by`      INT(11)           NOT NULL,
    `uploaded_at`      TIMESTAMP         NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (`id`),
    KEY `idx_hd_rest`      (`restaurant_id`),
    KEY `idx_hd_item`      (`checklist_item_id`),
    KEY `idx_hd_rest_item` (`restaurant_id`, `checklist_item_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");

// ── Resolve action ────────────────────────────────────────────────────────────
$ct     = $_SERVER['CONTENT_TYPE'] ?? '';
$json_b = [];
if (stripos($ct, 'application/json') !== false) {
    $json_b = json_decode(file_get_contents('php://input'), true) ?? [];
}
$action = trim($_POST['action'] ?? $_GET['action'] ?? ($json_b['action'] ?? ''));

// ACTION: complete_assessment
// Stores the owner-confirmed completion state without publishing the score.
if ($action === 'complete_assessment') {
    db_add_column($conn, 'halal_restaurants', 'hacera_completed_at', 'DATETIME DEFAULT NULL');
    $completedAt = date('Y-m-d H:i:s');
    $stmt = $conn->prepare(
        'UPDATE halal_restaurants SET hacera_completed_at = ? WHERE id = ? AND business_owner_id = ?'
    );
    $stmt->bind_param('sii', $completedAt, $rest_id, $user_id);
    $saved = $stmt->execute();
    $stmt->close();

    if (!$saved) {
        echo json_encode(['success' => false, 'message' => 'Could not save assessment completion.']);
        exit();
    }

    echo json_encode(['success' => true, 'completed_at' => $completedAt]);
    exit();
}

// ── Helper: return current scores for this restaurant ─────────────────────────
function buildScorePayload(mysqli $conn, int $rest_id): array
{
    // Load yes/no responses
    $res = $conn->query(
        "SELECT checklist_item_id, response_value FROM business_hacera_responses
         WHERE restaurant_id = $rest_id"
    );
    $responses = $res ? $res->fetch_all(MYSQLI_ASSOC) : [];

    // Load uploaded document item IDs
    $doc_res = $conn->query(
        "SELECT DISTINCT checklist_item_id FROM hacera_documents WHERE restaurant_id = $rest_id"
    );
    $doc_ids = [];
    if ($doc_res) {
        while ($d = $doc_res->fetch_assoc()) $doc_ids[] = (int)$d['checklist_item_id'];
    }

    $completed_ids = HaCeRaCalculator::buildCompletedIds($responses, $doc_ids);

    $module_scores = [];
    foreach (array_keys(HaCeRaCalculator::getModules()) as $mid) {
        $module_scores[$mid] = HaCeRaCalculator::scoreModule($mid, $completed_ids);
    }

    $hrp  = HaCeRaCalculator::computeHRP($module_scores);
    $band = HaCeRaCalculator::getBand($hrp);

    return [
        'hrp'           => $hrp,
        'band'          => $band,
        'module_scores' => $module_scores,
    ];
}
function clientScorePayload(mysqli $conn, int $userId, int $restaurantId): array {
    // The checklist Gap Analysis is a free assessment result. Premium endpoints
    // separately enforce payment for AI insights and the saved full-report PDF.
    return buildScorePayload($conn, $restaurantId);
}

// ══════════════════════════════════════════════════════════════════════════════
// ACTION: save_response  (yesno toggle)
// POST: checklist_item_id, response_value (yes|no|na), notes
// ══════════════════════════════════════════════════════════════════════════════
if ($action === 'save_response') {
    $item_id = (int)($_POST['checklist_item_id'] ?? 0);
    $val     = trim($_POST['response_value'] ?? 'no');
    $notes   = trim(strip_tags($_POST['notes'] ?? ''));

    if (!$item_id) {
        echo json_encode(['success' => false, 'message' => 'Missing checklist item ID.']);
        exit();
    }
    if (!in_array($val, ['yes', 'no', 'na'], true)) {
        echo json_encode(['success' => false, 'message' => 'Invalid response value.']);
        exit();
    }

    // Verify item exists in module definitions
    $valid_item = false;
    foreach (HaCeRaCalculator::getModules() as $mod) {
        foreach ($mod['items'] as $it) {
            if ($it['id'] === $item_id) { $valid_item = true; break 2; }
        }
    }
    if (!$valid_item) {
        echo json_encode(['success' => false, 'message' => 'Unknown checklist item.']);
        exit();
    }

    $stmt = $conn->prepare("
        INSERT INTO business_hacera_responses
            (restaurant_id, checklist_item_id, response_value, notes, responded_by)
        VALUES (?, ?, ?, ?, ?)
        ON DUPLICATE KEY UPDATE
            response_value = VALUES(response_value),
            notes          = VALUES(notes),
            responded_by   = VALUES(responded_by),
            updated_at     = CURRENT_TIMESTAMP
    ");
    $stmt->bind_param('iissi', $rest_id, $item_id, $val, $notes, $user_id);
    $ok = $stmt->execute();
    $stmt->close();

    if (!$ok) {
        echo json_encode(['success' => false, 'message' => 'DB error: ' . $conn->error]);
        exit();
    }

    $scores = clientScorePayload($conn, $user_id, $rest_id);
    echo json_encode(['success' => true, 'message' => 'Response saved.'] + $scores);
    exit();
}

// ══════════════════════════════════════════════════════════════════════════════
// ACTION: upload_document
// Multipart POST: checklist_item_id, doc_file
// ══════════════════════════════════════════════════════════════════════════════
if ($action === 'upload_document') {
    $item_id = (int)($_POST['checklist_item_id'] ?? 0);
    if (!$item_id) {
        echo json_encode(['success' => false, 'message' => 'Missing checklist item ID.']);
        exit();
    }

    if (!isset($_FILES['doc_file']) || $_FILES['doc_file']['error'] !== UPLOAD_ERR_OK) {
        $err_map = [
            UPLOAD_ERR_INI_SIZE   => 'File exceeds server upload limit.',
            UPLOAD_ERR_FORM_SIZE  => 'File exceeds form upload limit.',
            UPLOAD_ERR_PARTIAL    => 'File was only partially uploaded.',
            UPLOAD_ERR_NO_FILE    => 'No file was sent.',
            UPLOAD_ERR_NO_TMP_DIR => 'Missing temporary upload folder.',
            UPLOAD_ERR_CANT_WRITE => 'Failed to write file to disk.',
        ];
        $err_code = $_FILES['doc_file']['error'] ?? UPLOAD_ERR_NO_FILE;
        $msg = $err_map[$err_code] ?? 'Upload error (code ' . $err_code . ').';
        echo json_encode(['success' => false, 'message' => $msg]);
        exit();
    }

    $orig_name = basename($_FILES['doc_file']['name'] ?? 'document');
    $upload    = uploadFile($_FILES['doc_file'], 'hacera_docs', ['pdf', 'png', 'jpg', 'jpeg', 'docx', 'doc']);
    if (!$upload['success']) {
        echo json_encode(['success' => false, 'message' => $upload['message']]);
        exit();
    }

    // Insert; allow multiple docs per item (portfolio evidence)
    $ins = $conn->prepare("
        INSERT INTO hacera_documents
            (restaurant_id, checklist_item_id, file_path, original_name, uploaded_by)
        VALUES (?, ?, ?, ?, ?)
    ");
    $ins->bind_param('iissi', $rest_id, $item_id, $upload['path'], $orig_name, $user_id);
    $ok = $ins->execute();
    $doc_id = (int)$conn->insert_id;
    $ins->close();

    if (!$ok) {
        echo json_encode(['success' => false, 'message' => 'DB insert failed.']);
        exit();
    }

    logActivity($conn, $user_id, 'hacera_upload',
        "Uploaded document for item #{$item_id} (restaurant #{$rest_id})", 'hacera');

    $scores = clientScorePayload($conn, $user_id, $rest_id);
    echo json_encode([
        'success'     => true,
        'message'     => 'Document uploaded successfully.',
        'doc_id'      => $doc_id,
        'file_path'   => $upload['path'],
        'orig_name'   => $orig_name,
        'uploaded_at' => date('M d, Y'),
    ] + $scores);
    exit();
}

// ══════════════════════════════════════════════════════════════════════════════
// ACTION: delete_document
// POST: doc_id
// ══════════════════════════════════════════════════════════════════════════════
if ($action === 'delete_document') {
    $doc_id = (int)($_POST['doc_id'] ?? 0);
    if (!$doc_id) {
        echo json_encode(['success' => false, 'message' => 'Missing document ID.']);
        exit();
    }

    // Fetch to verify ownership and get path for disk delete
    $chk = $conn->prepare(
        "SELECT id, file_path FROM hacera_documents WHERE id = ? AND restaurant_id = ? LIMIT 1"
    );
    $chk->bind_param('ii', $doc_id, $rest_id);
    $chk->execute();
    $doc = $chk->get_result()->fetch_assoc();
    $chk->close();

    if (!$doc) {
        echo json_encode(['success' => false, 'message' => 'Document not found.']);
        exit();
    }

    // Delete from DB
    $del = $conn->prepare("DELETE FROM hacera_documents WHERE id = ?");
    $del->bind_param('i', $doc_id);
    $del->execute();
    $del->close();

    // Remove from disk (non-fatal if already gone)
    $disk_path = UPLOAD_DIR . $doc['file_path'];
    if (file_exists($disk_path)) @unlink($disk_path);

    $scores = clientScorePayload($conn, $user_id, $rest_id);
    echo json_encode(['success' => true, 'message' => 'Document deleted.'] + $scores);
    exit();
}

// ══════════════════════════════════════════════════════════════════════════════
// ACTION: get_scores  (polling / initial page load via AJAX)
// ══════════════════════════════════════════════════════════════════════════════
if ($action === 'get_scores') {
    echo json_encode(['success' => true] + clientScorePayload($conn, $user_id, $rest_id));
    exit();
}

// ══════════════════════════════════════════════════════════════════════════════
// ACTION: generate_pdf  — Inspection Readiness Portfolio
// ══════════════════════════════════════════════════════════════════════════════
if ($action === 'generate_pdf') {
    // A PDF can only be generated from an entitlement activated by a verified webhook.
    $purchaseId = (int)($_GET['purchase_id'] ?? $_POST['purchase_id'] ?? 0);
    $purchase = (new RevenuePaymentService($conn))->authorizeReportDownload($user_id, $rest_id, 'hacera', $purchaseId);
    if (!$purchase) {
        http_response_code(402);
        echo json_encode(['success'=>false,'message'=>'Purchase and verified PayMongo payment are required before downloading this report.']);
        exit();
    }
    $snapshot = json_decode((string)$purchase['snapshot_json'], true);
    if (!is_array($snapshot) || ($snapshot['kind'] ?? '') !== 'hacera') {
        http_response_code(500);
        echo json_encode(['success'=>false,'message'=>'The saved HaCeRa report snapshot is unavailable.']);
        exit();
    }

    $scores_data = $snapshot['scores'] ?? [];
    $modules = HaCeRaCalculator::getModules();
    $rinfo = $snapshot['restaurant'] ?? [];
    $resp_map = [];
    foreach (($snapshot['responses'] ?? []) as $row) {
        $resp_map[(int)$row['checklist_item_id']] = $row;
    }
    $doc_map = [];
    foreach (($snapshot['documents'] ?? []) as $row) {
        $doc_map[(int)$row['checklist_item_id']][] = $row;
    }
    // Preserved for the older PDF builder below; the rows come from the saved snapshot.
    $resp_res = true;
// ── Build completed IDs for gap indicators ────────────────────────────
    $all_resp  = $resp_res ? array_values(array_map(
        fn($k, $v) => ['checklist_item_id' => $k, 'response_value' => $v['response_value']],
        array_keys($resp_map), $resp_map
    )) : [];
    $completed_ids = array_map('intval', $snapshot['completed_ids'] ?? []);

    $hrp  = $scores_data['hrp'];
    $band = $scores_data['band'];

    // ── mPDF ─────────────────────────────────────────────────────────────────
    $tmp_dir = dirname(__DIR__, 3) . '/tmp/mpdf';
    if (!is_dir($tmp_dir)) mkdir($tmp_dir, 0755, true);

    try {
        $mpdf = new \Mpdf\Mpdf([
            'mode'       => 'utf-8',
            'format'     => 'A4',
            'tempDir'    => $tmp_dir,
            'margin_top' => 18, 'margin_bottom' => 18,
            'margin_left' => 15, 'margin_right' => 15,
        ]);
        $mpdf->SetTitle('HaCeRa Inspection Readiness Portfolio');
        $mpdf->SetAuthor('Halal Keeps');
        $mpdf->SetCreator('HaCeRa v1.0');

        $rest_name    = htmlspecialchars($rinfo['restaurant_name'] ?? 'N/A');
        $rest_addr    = htmlspecialchars($rinfo['address']         ?? '');
        $generated_at = date('F j, Y \a\t g:i A');

        // ── Bar graph HTML helper ─────────────────────────────────────────
        $bar = function(float $pct, string $color) {
            $w = min(100, max(0, $pct));
            return "<div style='background:#e5e7eb;border-radius:4px;height:10px;width:100%;'>
                        <div style='background:{$color};border-radius:4px;height:10px;width:{$w}%;'></div>
                    </div>";
        };

        // ── Assemble HTML ─────────────────────────────────────────────────
        $html  = <<<HTML
        <style>
            body { font-family: 'Arial', sans-serif; color: #1e293b; font-size: 10pt; }
            h1   { font-size: 18pt; color: #065f46; margin: 0 0 4px; }
            h2   { font-size: 13pt; color: #1e293b; margin: 18px 0 6px; border-bottom: 2px solid #e5e7eb; padding-bottom: 4px; }
            h3   { font-size: 10pt; font-weight: bold; margin: 12px 0 4px; }
            .meta { font-size: 9pt; color: #64748b; }
            .hrp  { font-size: 28pt; font-weight: bold; }
            .band-badge { display: inline-block; padding: 4px 12px; border-radius: 4px; font-size: 9pt; font-weight: bold; }
            table { width: 100%; border-collapse: collapse; margin-bottom: 8px; }
            th    { background: #f1f5f9; font-size: 8.5pt; padding: 5px 7px; text-align: left; border: 1px solid #e2e8f0; }
            td    { font-size: 8.5pt; padding: 5px 7px; border: 1px solid #e2e8f0; vertical-align: top; }
            .tag-done   { color: #16a34a; font-weight: bold; }
            .tag-gap    { color: #dc2626; font-weight: bold; }
            .tag-hi     { background: #fef3c7; color: #92400e; padding: 1px 5px; border-radius: 3px; font-size: 7.5pt; }
            .tag-std    { background: #eff6ff; color: #1e40af; padding: 1px 5px; border-radius: 3px; font-size: 7.5pt; }
            .tag-info   { background: #f1f5f9; color: #475569; padding: 1px 5px; border-radius: 3px; font-size: 7.5pt; }
            .page-break { page-break-after: always; }
        </style>

        <!-- ── Cover ─────────────────────────────────────────── -->
        <h1>HaCeRa Inspection Readiness Portfolio</h1>
        <p class="meta"><strong>{$rest_name}</strong> &nbsp;|&nbsp; {$rest_addr}<br>Generated: {$generated_at}</p>
        <br>

        <!-- Overall HRP gauge (text-based for PDF) -->
        <table style="width:100%;border:none;">
          <tr>
            <td style="border:none;width:50%;vertical-align:middle;">
              <p style="margin:0;font-size:9pt;color:#64748b;font-weight:bold;">OVERALL HALAL READINESS PROGRESS</p>
              <p class="hrp" style="color:{$band['color']};margin:4px 0;">{$hrp}%</p>
              <span class="band-badge" style="background:{$band['bg']};color:{$band['color']};">
                  {$band['label']}
              </span>
            </td>
            <td style="border:none;width:50%;vertical-align:middle;padding-left:20px;">
        HTML;

        // Module summary table on cover
        $html .= '<table><tr><th>Module</th><th>Score</th><th>Completed</th></tr>';
        foreach ($modules as $mid => $mod) {
            $ms    = $scores_data['module_scores'][$mid];
            $s_pct = $ms['score'];
            $s_col = $s_pct >= 70 ? '#16a34a' : ($s_pct >= 40 ? '#d97706' : '#dc2626');
            $html .= "<tr>
                <td>{$mod['title']}</td>
                <td style='color:{$s_col};font-weight:bold;'>{$s_pct}%</td>
                <td>{$ms['completed']}/{$ms['total']}</td>
              </tr>";
        }
        $html .= '</table>';
        $html .= '</td></tr></table>';
        $html .= '<div class="page-break"></div>';

        // ── Module detail pages ────────────────────────────────────────
        foreach ($modules as $mid => $mod) {
            $ms     = $scores_data['module_scores'][$mid];
            $m_band = HaCeRaCalculator::getBand($ms['score']);
            $html  .= "<h2>Module {$mid}: {$mod['title']}</h2>";
            $html  .= "<p style='font-size:9pt;color:#64748b;'>{$mod['description']}</p>";
            $html  .= "<p><strong>Section Readiness:</strong> <span style='color:{$m_band['color']};font-weight:bold;'>{$ms['score']}%</span>
                       &nbsp; ({$ms['earned_weight']}/{$ms['total_weight']} weight points) &nbsp;
                       <span class='band-badge' style='background:{$m_band['bg']};color:{$m_band['color']};font-size:8pt;'>{$m_band['label']}</span></p>";
            $html  .= $bar($ms['score'], $m_band['color']);
            $html  .= '<br>';

            $html .= '<table>
                <tr>
                    <th style="width:44%;">Checklist Item</th>
                    <th style="width:8%;">Weight</th>
                    <th style="width:10%;">Status</th>
                    <th style="width:38%;">Evidence / Response</th>
                </tr>';

            foreach ($mod['items'] as $item) {
                $done      = in_array($item['id'], $completed_ids, true);
                $w_tag     = $item['weight'] === 3 ? 'tag-hi' : ($item['weight'] === 2 ? 'tag-std' : 'tag-info');
                $w_lbl     = $item['weight'] === 3 ? 'High' : ($item['weight'] === 2 ? 'Standard' : 'Info');
                $status_td = $done
                    ? '<span class="tag-done">&#10003; Complete</span>'
                    : '<span class="tag-gap">&#10007; Gap</span>';

                // Evidence
                $evidence = '';
                if ($item['type'] === 'yesno') {
                    $rv = $resp_map[$item['id']]['response_value'] ?? 'no';
                    $evidence = 'Response: <strong>' . strtoupper($rv) . '</strong>';
                    if (!empty($resp_map[$item['id']]['notes'])) {
                        $evidence .= '<br><em>' . htmlspecialchars($resp_map[$item['id']]['notes']) . '</em>';
                    }
                } else {
                    if (!empty($doc_map[$item['id']])) {
                        foreach ($doc_map[$item['id']] as $d) {
                            $evidence .= '&#128196; ' . htmlspecialchars($d['original_name']) . ' <span style="color:#94a3b8;">(' . date('M d, Y', strtotime($d['uploaded_at'])) . ')</span><br>';
                        }
                    } else {
                        $evidence = '<span style="color:#94a3b8;">No document uploaded</span>';
                    }
                }

                $opt = !empty($item['optional']) ? ' <em style="color:#94a3b8;">(optional)</em>' : '';
                $html .= "<tr>
                    <td>" . htmlspecialchars($item['label']) . $opt . "</td>
                    <td><span class='{$w_tag}'>{$w_lbl}</span></td>
                    <td>{$status_td}</td>
                    <td>{$evidence}</td>
                  </tr>";
            }
            $html .= '</table>';

            // Gap summary for this module
            $gaps = HaCeRaCalculator::sortGapsByPriority($ms['gaps']);
            if (!empty($gaps)) {
                $html .= "<h3 style='color:#dc2626;'>&#9888; Outstanding Gaps (" . count($gaps) . ")</h3><ul>";
                foreach ($gaps as $g) {
                    $w_lbl = $g['weight'] === 3 ? '[HIGH]' : ($g['weight'] === 2 ? '[STD]' : '[INFO]');
                    $html .= '<li style="font-size:8.5pt;">' . $w_lbl . ' ' . htmlspecialchars($g['label']) . '</li>';
                }
                $html .= '</ul>';
            }

            if ($mid < 5) $html .= '<div class="page-break"></div>';
        }

        $mpdf->WriteHTML($html);

        $filename = 'HaCeRa_Portfolio_' . preg_replace('/[^a-zA-Z0-9]/', '_', $rest_name) . '_' . date('Ymd') . '.pdf';

        // Send as download
        header('Content-Type: application/pdf');
        header('Content-Disposition: attachment; filename="' . $filename . '"');
        header('Cache-Control: private, max-age=0, must-revalidate');
        // Cancel the earlier JSON content-type
        header_remove('Content-Type');
        header('Content-Type: application/pdf');

        $mpdf->Output($filename, 'D');
        exit();

    } catch (\Throwable $e) {
        // mPDF not available or failed — return JSON error so AJAX can surface it
        header('Content-Type: application/json; charset=utf-8');
        echo json_encode([
            'success' => false,
            'message' => 'PDF generation failed: ' . $e->getMessage(),
        ]);
        exit();
    }
}

// ══════════════════════════════════════════════════════════════════════════════
// ACTION: publish_score
// Computes the live HRP score and persists it to halal_restaurants so the
// customer-facing pages can display it without re-running the full scoring
// query on every page load.
// Idempotently adds hacera_hrp + hacera_band columns if they don't exist yet.
// ══════════════════════════════════════════════════════════════════════════════
if ($action === 'publish_score') {
db_add_column($conn, 'halal_restaurants', 'hacera_hrp',
    "DECIMAL(5,1) DEFAULT NULL COMMENT 'Published HaCeRa Halal Readiness Progress (0-100)'");
db_add_column($conn, 'halal_restaurants', 'hacera_band',
    "VARCHAR(30) DEFAULT NULL COMMENT 'Readiness band label: Inspection Ready / Near Ready / etc.'");
db_add_column($conn, 'halal_restaurants', 'hacera_published_at',
    "DATETIME DEFAULT NULL COMMENT 'When the owner last published their readiness score'");

    // Compute current live score
    $scores = buildScorePayload($conn, $rest_id);
    $hrp    = (float)$scores['hrp'];
    $band   = $scores['band']['label'] ?? 'Not Started';
    $now    = date('Y-m-d H:i:s');

    $upd = $conn->prepare(
        "UPDATE halal_restaurants
         SET hacera_hrp = ?, hacera_band = ?, hacera_published_at = ?
         WHERE id = ? AND business_owner_id = ?"
    );
    $upd->bind_param('dssii', $hrp, $band, $now, $rest_id, $user_id);
    $ok = $upd->execute();
    $upd->close();

    if (!$ok) {
        echo json_encode(['success' => false, 'message' => 'Failed to publish score: ' . $conn->error]);
        exit();
    }

    logActivity($conn, $user_id, 'hacera_publish',
        "Published HaCeRa score {$hrp}% ({$band}) for restaurant #{$rest_id}", 'hacera');

    echo json_encode([
        'success'      => true,
        'message'      => 'Readiness score published to your public profile.',
        'hrp'          => $hrp,
        'band'         => $scores['band'],
        'published_at' => date('M j, Y g:i A'),
    ] + clientScorePayload($conn, $user_id, $rest_id));
    exit();
}

// ── Fallback ──────────────────────────────────────────────────────────────────
echo json_encode(['success' => false, 'message' => 'Invalid action.']);
