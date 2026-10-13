<?php
/**
 * Admin API — Business Application Detail
 * GET ?id=N
 * Returns full application JSON: app metadata, branches, documents, raw materials.
 * Restricted to logged-in admins only.
 */
require_once __DIR__ . '/../../../config/app.php';
require_once __DIR__ . '/../../../includes/DocumentOCRService.php';
require_once __DIR__ . '/../../../includes/DocumentScreeningRepository.php';
requireLogin();
requireRole([ROLE_ADMIN]);

header('Content-Type: application/json; charset=utf-8');

$aid = (int)($_GET['id'] ?? 0);
if (!$aid) {
    http_response_code(400);
    echo json_encode(['error' => 'Missing application ID.']);
    exit;
}

// -- Main application row -----------------------------------------------------
$app_stmt = $conn->prepare("
    SELECT ba.*,
           u.full_name  AS applicant_name,
           u.email      AS applicant_email,
           rev.full_name AS reviewer_name
    FROM business_applications ba
    JOIN  users u   ON ba.user_id     = u.id
    LEFT JOIN users rev ON ba.reviewed_by = rev.id
    WHERE ba.id = ?
");
$app_stmt->bind_param("i", $aid);
$app_stmt->execute();
$app = $app_stmt->get_result()->fetch_assoc();

if (!$app) {
    http_response_code(404);
    echo json_encode(['error' => 'Application not found.']);
    exit;
}

// -- Branches ------------------------------------------------------------------
$br_stmt = $conn->prepare("
    SELECT * FROM business_branches
    WHERE application_id = ?
    ORDER BY branch_index ASC
");
$br_stmt->bind_param("i", $aid);
$br_stmt->execute();
$branches = $br_stmt->get_result()->fetch_all(MYSQLI_ASSOC);

// -- Documents + raw materials per branch ------------------------------------
$doc_stmt = $conn->prepare("
    SELECT * FROM branch_documents
    WHERE branch_id = ?
    ORDER BY doc_type ASC
");
$rmi_stmt = $conn->prepare("
    SELECT * FROM raw_material_items
    WHERE branch_id = ?
    ORDER BY sort_order ASC
");

$detail_branches = [];
foreach ($branches as $br) {
    $bid = (int)$br['id'];

    $doc_stmt->bind_param("i", $bid);
    $doc_stmt->execute();
    $docs = $doc_stmt->get_result()->fetch_all(MYSQLI_ASSOC);

    $rmi_stmt->bind_param("i", $bid);
    $rmi_stmt->execute();
    $rmi = $rmi_stmt->get_result()->fetch_all(MYSQLI_ASSOC);

    $detail_branches[] = array_merge($br, [
        'documents'    => $docs,
        'raw_materials'=> $rmi,
    ]);
}

$screening = [
    'installed' => false,
    'enabled' => DocumentOCRService::isFeatureEnabled(),
    'message' => 'Intelligent Document Screening is not installed. Apply the additive migration first.',
];
try {
    $screeningRepository = new DocumentScreeningRepository($conn);
    if ($screeningRepository->hasSchema()) {
        $screening = $screeningRepository->applicationResults($aid);
        $screening['enabled'] = DocumentOCRService::isFeatureEnabled();
    }
} catch (Throwable $e) {
    error_log('Unable to load document screening summary: ' . $e->getMessage());
    $screening['message'] = 'Document screening results are temporarily unavailable.';
}

echo json_encode([
    'app'      => $app,
    'branches' => $detail_branches,
    'screening' => $screening,
], JSON_UNESCAPED_UNICODE);
exit;
