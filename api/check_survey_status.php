<?php
/**
 * Check Survey Completion Status API
 * 
 * Returns whether the logged-in customer has completed the mandatory
 * TPB demand survey required before placing orders.
 * 
 * Response JSON:
 * {
 *   "success": true,
 *   "completed": true|false,
 *   "survey_data": {...}  // If completed
 * }
 */
require_once __DIR__ . '/../config/app.php';

header('Content-Type: application/json; charset=utf-8');

if (!isLoggedIn()) {
    http_response_code(401);
    echo json_encode(['success' => false, 'message' => 'Not authenticated.']);
    exit;
}

if ((int)($_SESSION['role_id'] ?? 0) !== ROLE_CUSTOMER) {
    http_response_code(403);
    echo json_encode(['success' => false, 'message' => 'Access denied.']);
    exit;
}

$user_id = (int)$_SESSION['user_id'];

// ── Check if survey_responses table exists ────────────────────────────────────
$table_check = $conn->query("SHOW TABLES LIKE 'survey_responses'");
if ($table_check->num_rows === 0) {
    // Table doesn't exist yet - treat as not completed
    echo json_encode([
        'success'   => true,
        'completed' => false,
        'message'   => 'Survey not available yet.'
    ]);
    exit;
}

// ── Query most recent survey response for this user ───────────────────────────
$survey_stmt = $conn->prepare("
    SELECT id, questionnaire_set, religion, mean_att, mean_sn, mean_pbc,
           raw_pi, norm_pi, created_at
    FROM survey_responses
    WHERE user_id = ?
    ORDER BY created_at DESC
    LIMIT 1
");
$survey_stmt->bind_param("i", $user_id);
$survey_stmt->execute();
$survey = $survey_stmt->get_result()->fetch_assoc();
$survey_stmt->close();

if ($survey) {
    // Survey completed
    echo json_encode([
        'success'     => true,
        'completed'   => true,
        'survey_data' => [
            'id'                 => (int)$survey['id'],
            'questionnaire_set'  => (int)$survey['questionnaire_set'],
            'religion'           => $survey['religion'],
            'mean_att'           => (float)$survey['mean_att'],
            'mean_sn'            => (float)$survey['mean_sn'],
            'mean_pbc'           => (float)$survey['mean_pbc'],
            'raw_pi'             => (float)$survey['raw_pi'],
            'norm_pi'            => (float)$survey['norm_pi'],
            'completed_at'       => $survey['created_at']
        ]
    ]);
} else {
    // No survey found - needs to complete
    // Get user's religion to determine which questionnaire set they need
    $user_stmt = $conn->prepare("SELECT religion FROM users WHERE id = ? LIMIT 1");
    $user_stmt->bind_param("i", $user_id);
    $user_stmt->execute();
    $user_data = $user_stmt->get_result()->fetch_assoc();
    $user_stmt->close();
    
    $questionnaire_set = ($user_data['religion'] ?? '') === 'Muslim' ? 1 : 2;
    
    echo json_encode([
        'success'           => true,
        'completed'         => false,
        'questionnaire_set' => $questionnaire_set,
        'religion'          => $user_data['religion'] ?? null,
        'message'           => 'Please complete the demand survey to proceed with your order.'
    ]);
}
