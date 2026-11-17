<?php
/**
 * Helper functions for the Halal Certification System
 */

// Check if user is logged in
function isLoggedIn() {
    return isset($_SESSION['user_id']) && !empty($_SESSION['user_id']);
}

// Require login - redirect if not logged in
function requireLogin() {
    if (!isLoggedIn()) {
        header('Location: ' . BASE_URL . 'auth/login.php');
        exit();
    }
}

// Require specific role
function requireRole($roles) {
    requireLogin();
    if (!is_array($roles)) $roles = [$roles];
    if (!in_array($_SESSION['role_id'] ?? 0, $roles)) {
        header('Location: ' . BASE_URL . 'auth/apply_role.php');
        exit();
    }
}

// Check if user has completed role application
function hasApprovedRole() {
    return isset($_SESSION['role_id']) && $_SESSION['role_status'] === 'approved';
}

// Get user role name
function getRoleName($roleId, $conn) {
    $stmt = $conn->prepare("SELECT role_name FROM roles WHERE id = ?");
    $stmt->bind_param("i", $roleId);
    $stmt->execute();
    $result = $stmt->get_result();
    if ($row = $result->fetch_assoc()) {
        return $row['role_name'];
    }
    return 'Unassigned';
}

// Sanitize input
function sanitize($data) {
    $data = trim($data);
    $data = stripslashes($data);
    $data = htmlspecialchars($data, ENT_QUOTES, 'UTF-8');
    return $data;
}

// Generate unique reference number
function generateReference($prefix = 'HDP') {
    return $prefix . '-' . date('Y') . '-' . str_pad(mt_rand(1, 99999), 5, '0', STR_PAD_LEFT);
}

// Upload file
function uploadFile($file, $directory, $allowedTypes = null) {
    if ($allowedTypes === null) {
        $allowedTypes = ALLOWED_FILE_TYPES;
    }
    
    if ($file['error'] !== UPLOAD_ERR_OK) {
        return ['success' => false, 'message' => 'Upload error occurred'];
    }
    
    if ($file['size'] > MAX_FILE_SIZE) {
        return ['success' => false, 'message' => 'File too large (max 10MB)'];
    }
    
    $ext = strtolower(pathinfo($file['name'], PATHINFO_EXTENSION));
    if (!in_array($ext, $allowedTypes)) {
        return ['success' => false, 'message' => 'File type not allowed'];
    }
    
    $filename = uniqid() . '_' . time() . '.' . $ext;
    $targetPath = UPLOAD_DIR . $directory . '/' . $filename;
    
    if (!is_dir(UPLOAD_DIR . $directory)) {
        mkdir(UPLOAD_DIR . $directory, 0755, true);
    }
    
    if (move_uploaded_file($file['tmp_name'], $targetPath)) {
        return ['success' => true, 'filename' => $filename, 'path' => $directory . '/' . $filename];
    }
    
    return ['success' => false, 'message' => 'Failed to move uploaded file'];
}

// Create notification
function createNotification($conn, $userId, $title, $message, $type = 'info', $link = '') {
    $stmt = $conn->prepare("INSERT INTO notifications (user_id, title, message, type, link) VALUES (?, ?, ?, ?, ?)");
    $stmt->bind_param("issss", $userId, $title, $message, $type, $link);
    return $stmt->execute();
}

// Log activity
function logActivity($conn, $userId, $action, $details = '', $module = '') {
    $ip = $_SERVER['REMOTE_ADDR'] ?? '127.0.0.1';
    $stmt = $conn->prepare("INSERT INTO activity_log (user_id, action, details, ip_address, module) VALUES (?, ?, ?, ?, ?)");
    $stmt->bind_param("issss", $userId, $action, $details, $ip, $module);
    return $stmt->execute();
}

// Get unread notification count
function getUnreadNotificationCount($conn, $userId) {
    $stmt = $conn->prepare("SELECT COUNT(*) as count FROM notifications WHERE user_id = ? AND is_read = 0");
    $stmt->bind_param("i", $userId);
    $stmt->execute();
    $result = $stmt->get_result();
    $row = $result->fetch_assoc();
    return $row['count'];
}

// Get user's notifications
function getNotifications($conn, $userId, $limit = 10) {
    $stmt = $conn->prepare("SELECT * FROM notifications WHERE user_id = ? ORDER BY created_at DESC LIMIT ?");
    $stmt->bind_param("ii", $userId, $limit);
    $stmt->execute();
    return $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
}

// Format date
function formatDate($date, $format = 'M d, Y') {
    return date($format, strtotime($date));
}

// Format date time
function formatDateTime($datetime, $format = 'M d, Y h:i A') {
    return date($format, strtotime($datetime));
}

// Format currency (Philippine Peso)
function formatCurrency($amount) {
    return '&#8369;' . number_format($amount, 2);
}

function getEnterpriseStandards() {
    return [
        'micro' => [
            'label' => 'Micro Enterprise',
            'technical' => 1,
            'shariah' => 1,
            'amount_each' => 500.00,
        ],
        'small' => [
            'label' => 'Small Enterprise',
            'technical' => 1,
            'shariah' => 1,
            'amount_each' => 700.00,
        ],
        'medium' => [
            'label' => 'Medium Enterprise',
            'technical' => 1,
            'shariah' => 2,
            'amount_each' => 900.00,
        ],
    ];
}

function normalizeEnterpriseType($type) {
    $type = strtolower(trim((string)$type));
    return array_key_exists($type, getEnterpriseStandards()) ? $type : '';
}

function getEnterpriseStandard($type) {
    $type = normalizeEnterpriseType($type);
    $standards = getEnterpriseStandards();
    return $type ? $standards[$type] : null;
}

function buildDefaultAuditorAssignments($enterpriseType) {
    $standard = getEnterpriseStandard($enterpriseType);
    if (!$standard) return [];

    $assignments = [];
    for ($i = 0; $i < $standard['shariah']; $i++) {
        $assignments[] = ['role' => 'shariah', 'auditor_id' => 0, 'amount' => $standard['amount_each']];
    }
    for ($i = 0; $i < $standard['technical']; $i++) {
        $assignments[] = ['role' => 'technical', 'auditor_id' => 0, 'amount' => $standard['amount_each']];
    }
    return $assignments;
}

function getAuditorAssignmentTotal($assignments) {
    $total = 0;
    foreach ($assignments as $assignment) {
        $total += floatval($assignment['amount'] ?? 0);
    }
    return $total;
}

function getEnterpriseStandardSummary($enterpriseType) {
    $standard = getEnterpriseStandard($enterpriseType);
    if (!$standard) return 'Not classified';

    $count = intval($standard['technical']) + intval($standard['shariah']);
    return $standard['label'] . ': ' . $count . ' auditor' . ($count === 1 ? '' : 's') .
        ' (' . intval($standard['shariah']) . ' Shariah, ' . intval($standard['technical']) . ' Technical) at ' .
        formatCurrency($standard['amount_each']) . ' each';
}

function getCertificateFeeByEnterpriseType($enterpriseType) {
    switch (normalizeEnterpriseType($enterpriseType)) {
        case 'micro': return 2000.00;
        case 'small': return 2500.00;
        case 'medium': return 4000.00;
        default: return 2500.00;
    }
}

function getPayMongoSecretKey() {
    return defined('PAYMONGO_SECRET_KEY') ? PAYMONGO_SECRET_KEY : '';
}

function getPayMongoPublicKey() {
    return defined('PAYMONGO_PUBLIC_KEY') ? PAYMONGO_PUBLIC_KEY : '';
}

function payMongoRequest($method, $endpoint, $payload = null) {
    $secretKey = getPayMongoSecretKey();
    if (empty($secretKey)) {
        return ['success' => false, 'message' => 'PayMongo secret key is not configured.'];
    }

    if (!function_exists('curl_init')) {
        return ['success' => false, 'message' => 'PHP cURL extension is required for PayMongo payments.'];
    }

    $ch = curl_init('https://api.paymongo.com' . $endpoint);
    $headers = [
        'Accept: application/json',
        'Authorization: Basic ' . base64_encode($secretKey . ':'),
    ];

    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_CUSTOMREQUEST, strtoupper($method));
    curl_setopt($ch, CURLOPT_HTTPHEADER, $headers);
    curl_setopt($ch, CURLOPT_TIMEOUT, 30);

    if ($payload !== null) {
        $headers[] = 'Content-Type: application/json';
        curl_setopt($ch, CURLOPT_HTTPHEADER, $headers);
        curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($payload));
    }

    $raw = curl_exec($ch);
    $curlError = curl_error($ch);
    $statusCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);

    if ($raw === false) {
        return ['success' => false, 'message' => 'PayMongo request failed: ' . $curlError];
    }

    $data = json_decode($raw, true);
    if ($statusCode < 200 || $statusCode >= 300) {
        $message = $data['errors'][0]['detail'] ?? $data['errors'][0]['title'] ?? 'PayMongo returned an error.';
        return ['success' => false, 'message' => $message, 'response' => $data, 'status_code' => $statusCode];
    }

    return ['success' => true, 'data' => $data, 'status_code' => $statusCode];
}

function createPayMongoCheckoutSession($amount, $description, $successUrl, $cancelUrl, $metadata = []) {
    $amountCentavos = max(0, intval(round(floatval($amount) * 100)));
    if ($amountCentavos <= 0) {
        return ['success' => false, 'message' => 'Payment amount must be greater than zero.'];
    }

    $payload = [
        'data' => [
            'attributes' => [
                'billing' => [
                    'name' => $_SESSION['full_name'] ?? '',
                    'email' => $_SESSION['email'] ?? '',
                ],
                'description' => $description,
                'line_items' => [[
                    'currency' => 'PHP',
                    'amount' => $amountCentavos,
                    'name' => $description,
                    'quantity' => 1,
                ]],
                'payment_method_types' => ['card', 'gcash', 'paymaya'],
                'send_email_receipt' => true,
                'show_description' => true,
                'show_line_items' => true,
                'success_url' => $successUrl,
                'cancel_url' => $cancelUrl,
                'metadata' => $metadata,
            ],
        ],
    ];

    return payMongoRequest('POST', '/v1/checkout_sessions', $payload);
}

function retrievePayMongoCheckoutSession($checkoutSessionId) {
    $checkoutSessionId = trim((string)$checkoutSessionId);
    if ($checkoutSessionId === '') {
        return ['success' => false, 'message' => 'Missing PayMongo checkout session id.'];
    }
    return payMongoRequest('GET', '/v1/checkout_sessions/' . rawurlencode($checkoutSessionId));
}

function isPayMongoCheckoutPaid($checkoutSession) {
    $attributes = $checkoutSession['data']['attributes'] ?? [];

    foreach (($attributes['payments'] ?? []) as $payment) {
        if (($payment['attributes']['status'] ?? '') === 'paid') {
            return true;
        }
    }

    return in_array($attributes['payment_intent']['attributes']['status'] ?? '', ['succeeded', 'paid'], true);
}

// Get status badge HTML
function getStatusBadge($status) {
    $badges = [
        'draft' => '<span class="badge badge-secondary">Draft</span>',
        'pending' => '<span class="badge badge-warning">Pending</span>',
        'submitted' => '<span class="badge badge-info">Submitted</span>',
        'under_review' => '<span class="badge badge-primary">Under Review</span>',
        'verified' => '<span class="badge badge-success">Verified</span>',
        'approved' => '<span class="badge badge-success">Approved</span>',
        'active' => '<span class="badge badge-success">Active</span>',
        'returned' => '<span class="badge badge-warning">Returned</span>',
        'rejected' => '<span class="badge badge-danger">Rejected</span>',
        'incomplete' => '<span class="badge badge-warning">Incomplete</span>',
        'in_progress' => '<span class="badge badge-primary">In Progress</span>',
        'completed' => '<span class="badge badge-success">Completed</span>',
        'cancelled' => '<span class="badge badge-danger">Cancelled</span>',
        'scheduled' => '<span class="badge badge-info">Scheduled</span>',
        'confirmed' => '<span class="badge badge-primary">Confirmed</span>',
        'conforming' => '<span class="badge badge-success">Conforming</span>',
        'non_conforming' => '<span class="badge badge-danger">Non-Conforming</span>',
        'partial' => '<span class="badge badge-warning">Partial</span>',
        'open' => '<span class="badge badge-danger">Open</span>',
        'resolved' => '<span class="badge badge-success">Resolved</span>',
        'closed' => '<span class="badge badge-secondary">Closed</span>',
        'paid' => '<span class="badge badge-success">Paid</span>',
        'unpaid' => '<span class="badge badge-danger">Unpaid</span>',
        'expired' => '<span class="badge badge-danger">Expired</span>',
        'revoked' => '<span class="badge badge-danger">Revoked</span>',
        'suspended' => '<span class="badge badge-warning">Suspended</span>',
        'pending_president_approval' => '<span class="badge badge-warning">Pending President Approval</span>',
        'approved_by_president' => '<span class="badge badge-info">Approved by President</span>',
        'awarded' => '<span class="badge badge-success">Awarded</span>',
        'decorated' => '<span class="badge badge-info">Decorated</span>',
        'prepared' => '<span class="badge badge-secondary">Prepared</span>',
        'recommend_approve' => '<span class="badge badge-success">Recommend Approval</span>',
        'recommend_reject' => '<span class="badge badge-danger">Recommend Rejection</span>',
        'need_more_info' => '<span class="badge badge-warning">Need More Info</span>',
        'deferred' => '<span class="badge badge-secondary">Deferred</span>',
        'none' => '<span class="badge badge-secondary">No Role</span>',
    ];
    return $badges[strtolower($status)] ?? '<span class="badge badge-secondary">' . ucfirst(str_replace('_', ' ', $status)) . '</span>';
}

// Get dashboard URL based on role
function getDashboardUrl($roleId) {
    // For Business Owner role, check if this user is a staff member (not the
    // actual owner) and route them to their specific workspace page.
    if ((int)$roleId === ROLE_BUSINESS_OWNER && !empty($_SESSION['staff_role_key'])) {
        $staff_pages = [
            'ops_manager' => BASE_URL . 'dashboard/business_owner/operations_manager.php',
            'cashier'     => BASE_URL . 'dashboard/business_owner/pos_order_processing.php',
            'chef'        => BASE_URL . 'dashboard/business_owner/chef_dashboard.php',
        ];
        if (isset($staff_pages[$_SESSION['staff_role_key']])) {
            return $staff_pages[$_SESSION['staff_role_key']];
        }
    }

    $dashboards = [
        ROLE_CUSTOMER            => BASE_URL . 'dashboard/customer/',
        ROLE_BUSINESS_OWNER      => BASE_URL . 'dashboard/business_owner/owner_dashboard.php',
        ROLE_EVALUATOR           => BASE_URL . 'dashboard/evaluator/',
        ROLE_AUDITOR_TECHNICAL   => BASE_URL . 'dashboard/auditor/',
        ROLE_AUDITOR_SHARIAH     => BASE_URL . 'dashboard/auditor/',
        ROLE_IMPARTIAL_COMMITTEE => BASE_URL . 'dashboard/impartial_committee/',
        ROLE_DECISION_COMMITTEE  => BASE_URL . 'dashboard/decision_committee/',
        ROLE_PRESIDENT           => BASE_URL . 'dashboard/president/',
        ROLE_RECEIVING_OFFICER   => BASE_URL . 'dashboard/receiving_officer/',
        ROLE_LABORATORY_ANALYST  => BASE_URL . 'dashboard/lab_analyst/',
        ROLE_ADMIN               => BASE_URL . 'dashboard/admin/',
    ];
    return $dashboards[$roleId] ?? BASE_URL . 'auth/apply_role.php';
}

// Get sidebar menu items based on role
function getSidebarMenu($roleId) {
    $menus = [
        ROLE_CUSTOMER => [
            ['icon' => 'fas fa-tachometer-alt',  'label' => 'Dashboard',             'url' => BASE_URL . 'dashboard/customer/'],
            ['icon' => 'fas fa-utensils',         'label' => 'Halal Restaurants',     'url' => BASE_URL . 'dashboard/customer/restaurants.php'],
            ['icon' => 'fas fa-shopping-bag',     'label' => 'My Orders',             'url' => BASE_URL . 'dashboard/customer/orders.php'],
            ['icon' => 'fas fa-star',             'label' => 'My Reviews',            'url' => BASE_URL . 'dashboard/customer/reviews.php'],
            ['icon' => 'fas fa-chart-line',       'label' => 'Demand Survey',         'url' => BASE_URL . 'dashboard/customer/tpb_survey.php'],
            ['icon' => 'fas fa-store',            'label' => 'Register as a Business','url' => BASE_URL . 'dashboard/customer/apply_business.php'],
            ['icon' => 'fas fa-user-tie',          'label' => 'Staff Applications',    'url' => BASE_URL . 'dashboard/customer/my_staff_applications.php'],
        ],
        ROLE_BUSINESS_OWNER => (function() {
            $staffKey = $_SESSION['staff_role_key'] ?? null;

            // Ops manager sidebar
            if ($staffKey === 'ops_manager') {
                return [
                    ['icon' => 'fas fa-store',              'label' => 'My Restaurant',     'url' => BASE_URL . 'dashboard/business_owner/operations_manager.php?tab=profile'],
                    ['icon' => 'fas fa-utensils',           'label' => 'Menu Management',   'url' => BASE_URL . 'dashboard/business_owner/operations_manager.php?tab=menu'],
                    ['icon' => 'fas fa-star',               'label' => 'Reviews & Ratings', 'url' => BASE_URL . 'dashboard/business_owner/operations_manager.php?tab=reviews'],
                    ['icon' => 'fas fa-shopping-basket',    'label' => 'Grocery Lists',     'url' => BASE_URL . 'dashboard/business_owner/operations_manager.php?tab=requisitions'],
                    ['icon' => 'fas fa-receipt',            'label' => 'Expenses',          'url' => BASE_URL . 'dashboard/business_owner/operations_manager.php?tab=expenses'],
                    ['icon' => 'fas fa-file-invoice-dollar','label' => 'Reports & Billing', 'url' => BASE_URL . 'dashboard/business_owner/billing.php'],
                    ['icon' => 'fas fa-certificate',        'label' => 'Halal Certification','url' => BASE_URL . 'dashboard/business_owner/halal_certification.php'],
                ];
            }
            // Cashier: orders view + POS
            if ($staffKey === 'cashier') {
                return [
                    ['icon' => 'fas fa-shopping-bag',  'label' => 'Orders',        'url' => BASE_URL . 'dashboard/business_owner/manage_orders.php'],
                    ['icon' => 'fas fa-cash-register',  'label' => 'POS / Cashier', 'url' => BASE_URL . 'dashboard/business_owner/pos_order_processing.php'],
                ];
            }
            // Chef: Kitchen, Suppliers, Grocery List, Categories, Recipe Builder, POS Guardrails
            if ($staffKey === 'chef') {
                return [
                    ['icon' => 'fas fa-hat-cowboy-side', 'label' => 'Chef Workstation',  'url' => BASE_URL . 'dashboard/business_owner/chef_dashboard.php'],
                    ['icon' => 'fas fa-boxes',           'label' => 'Kitchen Inventory', 'url' => BASE_URL . 'dashboard/business_owner/chef_dashboard.php?tab=inventory'],
                    ['icon' => 'fas fa-drumstick-bite',  'label' => 'Meat Suppliers',    'url' => BASE_URL . 'dashboard/business_owner/chef_dashboard.php?tab=suppliers'],
                    ['icon' => 'fas fa-shopping-basket', 'label' => 'Grocery List',      'url' => BASE_URL . 'dashboard/business_owner/chef_dashboard.php?tab=grocery_lists'],
                    ['icon' => 'fas fa-tags',            'label' => 'Categories',        'url' => BASE_URL . 'dashboard/business_owner/chef_dashboard.php?tab=categories'],
                    ['icon' => 'fas fa-book-open',       'label' => 'Recipe Builder',    'url' => BASE_URL . 'dashboard/business_owner/chef_dashboard.php?tab=recipes'],
                    ['icon' => 'fas fa-toggle-on',       'label' => 'POS Guardrails',    'url' => BASE_URL . 'dashboard/business_owner/chef_dashboard.php?tab=menu_status'],
                ];
            }

            // Full owner sidebar
            return [
                ['icon' => 'fas fa-chart-line',  'label' => 'Owner Dashboard',    'url' => BASE_URL . 'dashboard/business_owner/owner_dashboard.php'],
                ['icon' => 'fas fa-file-invoice-dollar','label' => 'Reports & Billing', 'url' => BASE_URL . 'dashboard/business_owner/billing.php'],
                ['icon' => 'fas fa-store',       'label' => 'My Restaurant',      'url' => BASE_URL . 'dashboard/business_owner/restaurant.php'],
                ['icon' => 'fas fa-users-cog',   'label' => 'Staff Roles',        'url' => BASE_URL . 'dashboard/business_owner/staff_roles.php'],
                ['icon' => 'fas fa-inbox',       'label' => 'Staff Applications', 'url' => BASE_URL . 'dashboard/business_owner/staff_applications.php'],
                ['icon' => 'fas fa-chart-pie',   'label' => 'HaCeRa Assessment',  'url' => BASE_URL . 'dashboard/business_owner/hacera.php'],
                ['icon' => 'fas fa-certificate', 'label' => 'Halal Certification','url' => BASE_URL . 'dashboard/business_owner/halal_certification.php'],
            ];
        })(),
        ROLE_EVALUATOR => [
            ['icon' => 'fas fa-tachometer-alt', 'label' => 'Dashboard', 'url' => BASE_URL . 'dashboard/evaluator/'],
            ['icon' => 'fas fa-file-alt', 'label' => 'Verify LOI', 'url' => BASE_URL . 'dashboard/evaluator/verify_loi.php'],
            ['icon' => 'fas fa-clipboard-check', 'label' => 'Verify Applications', 'url' => BASE_URL . 'dashboard/evaluator/verify_applications.php'],
            ['icon' => 'fas fa-file-contract', 'label' => 'Terms of Reference', 'url' => BASE_URL . 'dashboard/evaluator/terms_of_reference.php'],
            ['icon' => 'fas fa-calendar-alt', 'label' => 'Inspection Schedules', 'url' => BASE_URL . 'dashboard/evaluator/inspection_schedules.php'],
        ],
        ROLE_AUDITOR_TECHNICAL => [
            ['icon' => 'fas fa-tachometer-alt', 'label' => 'Dashboard', 'url' => BASE_URL . 'dashboard/auditor/'],
            ['icon' => 'fas fa-search', 'label' => 'Inspections', 'url' => BASE_URL . 'dashboard/auditor/inspections.php'],
            ['icon' => 'fas fa-exclamation-triangle', 'label' => 'NCR Reports', 'url' => BASE_URL . 'dashboard/auditor/ncr_reports.php'],
            ['icon' => 'fas fa-clipboard', 'label' => 'Audit Findings', 'url' => BASE_URL . 'dashboard/auditor/audit_findings.php'],
        ],
        ROLE_AUDITOR_SHARIAH => [
            ['icon' => 'fas fa-tachometer-alt', 'label' => 'Dashboard', 'url' => BASE_URL . 'dashboard/auditor/'],
            ['icon' => 'fas fa-search', 'label' => 'Inspections', 'url' => BASE_URL . 'dashboard/auditor/inspections.php'],
            ['icon' => 'fas fa-exclamation-triangle', 'label' => 'NCR Reports', 'url' => BASE_URL . 'dashboard/auditor/ncr_reports.php'],
            ['icon' => 'fas fa-clipboard', 'label' => 'Audit Findings', 'url' => BASE_URL . 'dashboard/auditor/audit_findings.php'],
        ],
        ROLE_IMPARTIAL_COMMITTEE => [
            ['icon' => 'fas fa-tachometer-alt', 'label' => 'Dashboard', 'url' => BASE_URL . 'dashboard/impartial_committee/'],
            ['icon' => 'fas fa-gavel', 'label' => 'Review Evidence', 'url' => BASE_URL . 'dashboard/impartial_committee/review_evidence.php'],
            ['icon' => 'fas fa-tasks', 'label' => 'Potential Decisions', 'url' => BASE_URL . 'dashboard/impartial_committee/potential_decisions.php'],
        ],
        ROLE_DECISION_COMMITTEE => [
            ['icon' => 'fas fa-tachometer-alt', 'label' => 'Dashboard', 'url' => BASE_URL . 'dashboard/decision_committee/'],
            ['icon' => 'fas fa-check-double', 'label' => 'Final Decisions', 'url' => BASE_URL . 'dashboard/decision_committee/final_decisions.php'],
            ['icon' => 'fas fa-history', 'label' => 'Decision History', 'url' => BASE_URL . 'dashboard/decision_committee/history.php'],
        ],
        ROLE_PRESIDENT => [
            ['icon' => 'fas fa-tachometer-alt', 'label' => 'Dashboard', 'url' => BASE_URL . 'dashboard/president/'],
            ['icon' => 'fas fa-award', 'label' => 'Award Certificates', 'url' => BASE_URL . 'dashboard/president/award_certificates.php'],
            ['icon' => 'fas fa-certificate', 'label' => 'All Certificates', 'url' => BASE_URL . 'dashboard/president/all_certificates.php'],
            ['icon' => 'fas fa-users', 'label' => 'Manage Users', 'url' => BASE_URL . 'dashboard/president/manage_users.php'],
            ['icon' => 'fas fa-chart-bar', 'label' => 'Reports', 'url' => BASE_URL . 'dashboard/president/reports.php'],
            ['icon' => 'fas fa-shield-alt', 'label' => 'Admin Panel', 'url' => BASE_URL . 'admin/'],
        ],
        ROLE_RECEIVING_OFFICER => [
            ['icon' => 'fas fa-tachometer-alt', 'label' => 'Dashboard', 'url' => BASE_URL . 'dashboard/receiving_officer/'],
            ['icon' => 'fas fa-inbox', 'label' => 'Request Form', 'url' => BASE_URL . 'dashboard/receiving_officer/receive_samples.php'],
            ['icon' => 'fas fa-search', 'label' => 'Sample Tracking', 'url' => BASE_URL . 'dashboard/receiving_officer/sample_tracking.php'],
        ],
        ROLE_LABORATORY_ANALYST => [
            ['icon' => 'fas fa-tachometer-alt', 'label' => 'Dashboard', 'url' => BASE_URL . 'dashboard/lab_analyst/'],
            ['icon' => 'fas fa-microscope', 'label' => 'Analyze Samples', 'url' => BASE_URL . 'dashboard/lab_analyst/analyze_samples.php'],
            ['icon' => 'fas fa-file-medical-alt', 'label' => 'Lab Reports', 'url' => BASE_URL . 'dashboard/lab_analyst/lab_reports.php'],
        ],
        ROLE_ADMIN => [
            ['icon' => 'fas fa-shield-alt',    'label' => 'Admin Dashboard',        'url' => BASE_URL . 'dashboard/admin/'],
            ['icon' => 'fas fa-store',          'label' => 'Business Applications',  'url' => BASE_URL . 'dashboard/admin/business_applications.php'],
            ['icon' => 'fas fa-user-shield',    'label' => 'Role Approvals',         'url' => BASE_URL . 'dashboard/admin/approve_roles.php'],
            ['icon' => 'fas fa-key',            'label' => 'Authorization (RBAC)',   'url' => BASE_URL . 'dashboard/admin/authorization.php'],
            ['icon' => 'fas fa-lock',           'label' => 'Secure Data',            'url' => BASE_URL . 'dashboard/admin/secure_data.php'],
            ['icon' => 'fas fa-list-alt',       'label' => 'Logs & Monitor',         'url' => BASE_URL . 'dashboard/admin/logging_monitoring.php'],
            ['icon' => 'fas fa-user-secret',    'label' => 'DLP Features',           'url' => BASE_URL . 'dashboard/admin/dlp_features.php'],
        ],
    ];
    return $menus[$roleId] ?? [];
}

/**
 * Require that the current user holds a specific restaurant-level staff role
 * for the given restaurant.
 *
 * Usage at top of each staff page:
 *   requireStaffRole($conn, STAFF_ROLE_OPS_MANAGER);
 *
 * The function checks the restaurant_staff table, not the system roles table.
 * Business owners are always permitted regardless of staff assignment.
 *
 * @param mysqli $conn
 * @param string $required_role  One of STAFF_ROLE_* constants
 */
function requireStaffRole(mysqli $conn, string $required_role): void {
    requireLogin();
    requireRole([ROLE_BUSINESS_OWNER]);

    $user_id  = (int)($_SESSION['user_id'] ?? 0);
    $staff_key = $_SESSION['staff_role_key'] ?? null;

    // Actual restaurant owners (no staff_role_key set) are allowed everywhere
    if ($staff_key === null) {
        $chk = $conn->prepare(
            "SELECT id FROM halal_restaurants WHERE business_owner_id = ? LIMIT 1"
        );
        $chk->bind_param("i", $user_id);
        $chk->execute();
        if ($chk->get_result()->num_rows > 0) return;
        header('Location: ' . BASE_URL . 'dashboard/business_owner/restaurant.php');
        exit();
    }

    // Staff member: must match the required role key exactly
    if ($staff_key !== $required_role) {
        // Send them to their own correct page
        $fallback_map = [
            STAFF_ROLE_OPS_MANAGER => BASE_URL . 'dashboard/business_owner/operations_manager.php',
            STAFF_ROLE_CASHIER     => BASE_URL . 'dashboard/business_owner/pos_order_processing.php',
            STAFF_ROLE_CHEF        => BASE_URL . 'dashboard/business_owner/chef_dashboard.php',
        ];
        header('Location: ' . ($fallback_map[$staff_key] ?? BASE_URL . 'dashboard/business_owner/owner_dashboard.php'));
        exit();
    }
}

/**
 * getStaffWorkspaceLabel — returns the display workspace name and accent colour
 * for the current session, used in header.php for sidebar branding and the
 * user-role footer label.
 *
 * Returns an array:
 *   ['label' => string, 'sublabel' => string, 'color' => string, 'icon' => string]
 */
function getStaffWorkspaceLabel(): array {
    $staff_key = $_SESSION['staff_role_key'] ?? null;

    switch ($staff_key) {
        case STAFF_ROLE_OPS_MANAGER:
            return [
                'label'    => 'Operations Manager',
                'sublabel' => 'Operations Interface',
                'color'    => '#2563eb',   // blue
                'bg'       => '#eff6ff',
                'icon'     => 'fas fa-user-tie',
            ];
        case STAFF_ROLE_CASHIER:
            return [
                'label'    => 'Cashier / POS Station',
                'sublabel' => 'POS Interface',
                'color'    => '#d97706',   // amber
                'bg'       => '#fffbeb',
                'icon'     => 'fas fa-cash-register',
            ];
        case STAFF_ROLE_CHEF:
            return [
                'label'    => 'Chef / Head Cook',
                'sublabel' => 'Kitchen Workstation',
                'color'    => '#a855f7',   // bright purple
                'bg'       => '#f5f3ff',
                'icon'     => 'fas fa-utensils',
            ];
        default:
            return [
                'label'    => 'Business Owner',
                'sublabel' => 'Executive Dashboard',
                'color'    => '#059669',   // green
                'bg'       => '#ecfdf5',
                'icon'     => 'fas fa-chart-line',
            ];
    }
}

/**
 * Blocks any user whose $_SESSION['staff_role_key'] is set (i.e. they are
 * a staff member, not the actual restaurant owner).  Use this at the top of
 * owner_dashboard.php, staff_applications.php, etc.
 */
function requireStaffAccess(string $owner_only_page_label = 'this page'): void {
    requireLogin();
    requireRole([ROLE_BUSINESS_OWNER]);

    $staff_key = $_SESSION['staff_role_key'] ?? null;
    if ($staff_key !== null) {
        // Staff member tried to reach an owner-only page — send them home
        $fallback_map = [
            STAFF_ROLE_OPS_MANAGER => BASE_URL . 'dashboard/business_owner/operations_manager.php',
            STAFF_ROLE_CASHIER     => BASE_URL . 'dashboard/business_owner/pos_order_processing.php',
            STAFF_ROLE_CHEF        => BASE_URL . 'dashboard/business_owner/chef_dashboard.php',
        ];
        header('Location: ' . ($fallback_map[$staff_key] ?? BASE_URL . 'dashboard/business_owner/owner_dashboard.php'));
        exit();
    }
}

/**
 * getSessionRestaurantId — resolve the restaurant_id for the current user.
 *
 * - Actual owners:   look up halal_restaurants.business_owner_id
 * - Staff members:   look up restaurant_staff.restaurant_id
 *
 * Returns 0 when no restaurant is found.
 */
function getSessionRestaurantId(mysqli $conn): int {
    $user_id   = (int)($_SESSION['user_id']      ?? 0);
    $staff_key = $_SESSION['staff_role_key'] ?? null;

    if ($staff_key === null) {
        // Owner path
        $s = $conn->prepare(
            "SELECT id FROM halal_restaurants WHERE business_owner_id = ? LIMIT 1"
        );
        $s->bind_param("i", $user_id);
    } else {
        // Staff path
        $s = $conn->prepare("
            SELECT rs.restaurant_id AS id
            FROM restaurant_staff rs
            JOIN restaurant_staff_roles rsr ON rs.staff_role_id = rsr.id
            WHERE rs.user_id = ? AND rsr.role_key = ? AND rs.is_active = 1
            LIMIT 1
        ");
        $s->bind_param("is", $user_id, $staff_key);
    }

    $s->execute();
    $row = $s->get_result()->fetch_assoc();
    $s->close();
    return $row ? (int)$row['id'] : 0;
}

/**
 * authorizeManagerAction — verify a manager override PIN for privileged POS actions.
 *
 * Checks that $pin_input matches the hashed manager_pin stored on a user who is:
 *   - an active ops_manager or owner for the given restaurant, AND
 *   - has a matching PIN hash in restaurant_staff (or halal_restaurants for owner)
 *
 * Returns ['ok' => true, 'manager_name' => '...'] on success or
 *         ['ok' => false, 'error' => '...'] on failure.
 *
 * @param mysqli $conn
 * @param int    $restaurant_id
 * @param string $pin_input      Raw PIN submitted by the cashier
 */
function authorizeManagerAction(mysqli $conn, int $restaurant_id, string $pin_input): array {
    if (!$restaurant_id || strlen(trim($pin_input)) < 4) {
        return ['ok' => false, 'error' => 'Invalid PIN.'];
    }

    // Fetch all active manager/owner PIN hashes for this restaurant
    $stmt = $conn->prepare("
        SELECT u.full_name, rs.manager_pin
        FROM restaurant_staff rs
        JOIN users u ON rs.user_id = u.id
        JOIN restaurant_staff_roles rsr ON rs.staff_role_id = rsr.id
        WHERE rs.restaurant_id = ?
          AND rsr.role_key IN ('ops_manager', 'owner')
          AND rs.is_active = 1
          AND rs.manager_pin IS NOT NULL
    ");
    $stmt->bind_param("i", $restaurant_id);
    $stmt->execute();
    $managers = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);

    // Also check the restaurant's actual owner (stored on halal_restaurants)
    $owner_stmt = $conn->prepare("
        SELECT u.full_name, hr.manager_pin
        FROM halal_restaurants hr
        JOIN users u ON hr.business_owner_id = u.id
        WHERE hr.id = ? AND hr.manager_pin IS NOT NULL
        LIMIT 1
    ");
    $owner_stmt->bind_param("i", $restaurant_id);
    $owner_stmt->execute();
    $owner_row = $owner_stmt->get_result()->fetch_assoc();
    if ($owner_row) $managers[] = $owner_row;

    foreach ($managers as $mgr) {
        if (!empty($mgr['manager_pin']) && password_verify($pin_input, $mgr['manager_pin'])) {
            return ['ok' => true, 'manager_name' => $mgr['full_name']];
        }
    }

    return ['ok' => false, 'error' => 'Incorrect PIN. Please ask your manager to try again.'];
}

/**
 * Ensure a user has the Customer role assigned.
 *
 * Called immediately after any successful login or registration so that
 * users with role_status = 'none' (no role yet) are silently promoted to
 * ROLE_CUSTOMER before the redirect decision is made.
 *
 * Safe to call on users who already have an approved role — it does nothing
 * in that case, so every auth path can call it unconditionally.
 *
 * @param  mysqli $conn   Active DB connection
 * @param  array  &$user  User row array (passed by reference so the caller's
 *                        copy stays up-to-date without an extra DB round-trip)
 * @return void
 */
function autoAssignCustomerRole(mysqli $conn, array &$user): void
{
    // Already has a properly assigned role — nothing to do.
    if (!empty($user['role_id']) && $user['role_status'] === 'approved') {
        return;
    }

    $customer_role_id = ROLE_CUSTOMER; // = 1

    // Fetch the customer role name once (keeps session data accurate)
    $rs = $conn->prepare("SELECT role_name FROM roles WHERE id = ?");
    $rs->bind_param("i", $customer_role_id);
    $rs->execute();
    $role_row  = $rs->get_result()->fetch_assoc();
    $role_name = $role_row['role_name'] ?? 'Customer';

    // Persist to DB
    $upd = $conn->prepare(
        "UPDATE users SET role_id = ?, role_status = 'approved' WHERE id = ?"
    );
    $upd->bind_param("ii", $customer_role_id, $user['id']);
    $upd->execute();

    // Keep the caller's array in sync so getDashboardUrl() works immediately
    $user['role_id']     = $customer_role_id;
    $user['role_status'] = 'approved';
    $user['role_name']   = $role_name;

    // Patch the live session if one exists for this user
    if (isset($_SESSION['user_id']) && (int)$_SESSION['user_id'] === (int)$user['id']) {
        $_SESSION['role_id']     = $customer_role_id;
        $_SESSION['role_status'] = 'approved';
        $_SESSION['role_name']   = $role_name;
    }
}

// Create user session from database
function createUserSession($user) {
    global $conn;

    $_SESSION['user_id']     = $user['id'];
    $_SESSION['email']       = $user['email'];
    $_SESSION['full_name']   = $user['full_name'];
    $_SESSION['first_name']  = $user['first_name'] ?? '';
    $_SESSION['last_name']   = $user['last_name']  ?? '';
    $_SESSION['avatar']      = $user['avatar']     ?? '';
    $_SESSION['role_id']     = $user['role_id'];
    $_SESSION['role_status'] = $user['role_status'];
    $_SESSION['role_name']   = $user['role_name']  ?? 'Unassigned';
    $_SESSION['is_admin']    = !empty($user['is_admin']) ? true : false;
    $_SESSION['last_activity'] = time();

    // For Business Owner users, also store their restaurant staff role key
    // so getDashboardUrl() can route them to the correct page (ops_manager
    // → operations_manager.php, cashier → pos_order_processing.php, or the
    // owner dashboard when they are the actual owner with no staff record).
    $_SESSION['staff_role_key'] = null;
    if ((int)($user['role_id'] ?? 0) === ROLE_BUSINESS_OWNER) {
        global $conn;
        if ($conn instanceof mysqli) {
            $uid = (int)$user['id'];
            $sr  = $conn->prepare("
                SELECT rsr.role_key
                FROM restaurant_staff rs
                JOIN restaurant_staff_roles rsr ON rs.staff_role_id = rsr.id
                WHERE rs.user_id = ? AND rs.is_active = 1
                LIMIT 1
            ");
            if ($sr) {
                $sr->bind_param("i", $uid);
                $sr->execute();
                $sr_row = $sr->get_result()->fetch_assoc();
                if ($sr_row) {
                    $_SESSION['staff_role_key'] = $sr_row['role_key'];
                }
                $sr->close();
            }
        }
    }
}

// Get the Google OAuth URL — generates a CSRF state token and stores it in session
function getGoogleAuthUrl() {
    // Generate a random state token and persist it for verification in the callback
    if (session_status() !== PHP_SESSION_ACTIVE) session_start();
    $state = bin2hex(random_bytes(16));
    $_SESSION['oauth_state'] = $state;

    $params = [
        'client_id'     => GOOGLE_CLIENT_ID,
        'redirect_uri'  => GOOGLE_REDIRECT_URI,
        'response_type' => 'code',
        'scope'         => 'openid email profile',
        'access_type'   => 'online',
        'prompt'        => 'consent',
        'state'         => $state,
    ];
    return 'https://accounts.google.com/o/oauth2/v2/auth?' . http_build_query($params);
}

// Exchange Google auth code for token
function getGoogleAccessToken($code) {
    $url = 'https://oauth2.googleapis.com/token';
    $data = [
        'code' => $code,
        'client_id' => GOOGLE_CLIENT_ID,
        'client_secret' => GOOGLE_CLIENT_SECRET,
        'redirect_uri' => GOOGLE_REDIRECT_URI,
        'grant_type' => 'authorization_code',
    ];
    
    $ch = curl_init();
    curl_setopt($ch, CURLOPT_URL, $url);
    curl_setopt($ch, CURLOPT_POST, true);
    curl_setopt($ch, CURLOPT_POSTFIELDS, http_build_query($data));
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);
    $response = curl_exec($ch);
    curl_close($ch);
    
    return json_decode($response, true);
}

// Get Google user info
function getGoogleUserInfo($accessToken) {
    $url = 'https://www.googleapis.com/oauth2/v3/userinfo';
    
    $ch = curl_init();
    curl_setopt($ch, CURLOPT_URL, $url);
    curl_setopt($ch, CURLOPT_HTTPHEADER, ['Authorization: Bearer ' . $accessToken]);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);
    $response = curl_exec($ch);
    curl_close($ch);
    
    return json_decode($response, true);
}

// Get application progress percentage
function getApplicationProgress($conn, $applicationId) {
    $steps = [
        'loi_submitted' => false,
        'loi_verified' => false,
        'application_submitted' => false,
        'application_verified' => false,
        'tor_created' => false,
        'inspection_scheduled' => false,
        'inspection_completed' => false,
        'lab_completed' => false,
        'impartial_reviewed' => false,
        'decision_made' => false,
        'certificate_awarded' => false,
    ];
    
    // Check each step
    $stmt = $conn->prepare("SELECT status FROM hdp_applications WHERE id = ?");
    $stmt->bind_param("i", $applicationId);
    $stmt->execute();
    $result = $stmt->get_result();
    if ($app = $result->fetch_assoc()) {
        $steps['application_submitted'] = in_array($app['status'], ['submitted', 'under_review', 'verified', 'approved']);
    }
    
    $completed = array_sum(array_map('intval', $steps));
    $total = count($steps);
    
    return round(($completed / $total) * 100);
}

// Time ago function
function timeAgo($datetime) {
    $now = new DateTime();
    $ago = new DateTime($datetime);
    $diff = $now->diff($ago);
    
    if ($diff->y > 0) return $diff->y . ' year' . ($diff->y > 1 ? 's' : '') . ' ago';
    if ($diff->m > 0) return $diff->m . ' month' . ($diff->m > 1 ? 's' : '') . ' ago';
    if ($diff->d > 0) return $diff->d . ' day' . ($diff->d > 1 ? 's' : '') . ' ago';
    if ($diff->h > 0) return $diff->h . ' hour' . ($diff->h > 1 ? 's' : '') . ' ago';
    if ($diff->i > 0) return $diff->i . ' minute' . ($diff->i > 1 ? 's' : '') . ' ago';
    return 'Just now';
}

// ── Business visit / foot-traffic helpers ─────────────────────────────────────

/**
 * Ensure the business_views analytics table exists (idempotent).
 */
function ensureBusinessViewsTable(mysqli $conn): void {
    $conn->query("CREATE TABLE IF NOT EXISTS `business_views` (
        `id`            INT UNSIGNED NOT NULL AUTO_INCREMENT,
        `restaurant_id` INT(11)      NOT NULL,
        `user_id`       INT(11)      DEFAULT NULL,
        `ip_address`    VARCHAR(45)  DEFAULT NULL,
        `user_agent`    VARCHAR(255) DEFAULT NULL,
        `visited_at`    DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
        PRIMARY KEY (`id`),
        KEY `idx_bv_restaurant` (`restaurant_id`),
        KEY `idx_bv_date` (`visited_at`),
        CONSTRAINT `bv_restaurant_fk` FOREIGN KEY (`restaurant_id`) REFERENCES `halal_restaurants`(`id`) ON DELETE CASCADE
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
}

/**
 * Record an online profile/menu page view for foot-traffic analytics.
 */
function logBusinessView(mysqli $conn, int $restaurant_id): void {
    if ($restaurant_id <= 0) {
        return;
    }

    ensureBusinessViewsTable($conn);

    $ip_addr = $_SERVER['REMOTE_ADDR'] ?? '';
    $u_agent = substr($_SERVER['HTTP_USER_AGENT'] ?? '', 0, 255);
    $uid_log = isset($_SESSION['user_id']) ? (int)$_SESSION['user_id'] : null;

    $bv_stmt = $conn->prepare(
        "INSERT INTO business_views (restaurant_id, user_id, ip_address, user_agent) VALUES (?, ?, ?, ?)"
    );
    if ($bv_stmt) {
        $bv_stmt->bind_param('iiss', $restaurant_id, $uid_log, $ip_addr, $u_agent);
        $bv_stmt->execute();
        $bv_stmt->close();
    }
}

/**
 * Aggregate online views + in-store orders for owner/ops-manager dashboards.
 *
 * @return array{
 *   online_views_today:int,online_views_month:int,online_views_total:int,online_views_last_mo:int,
 *   dine_in_today:int,dine_in_month:int,dine_in_total:int,dine_in_last_mo:int,
 *   total_visits_month:int,total_visits_today:int,total_visits_total:int,total_visits_last_mo:int,
 *   growth_pct:float,online_pct:float,dine_in_pct:float
 * }
 */
function getBusinessTrafficStats(mysqli $conn, int $restaurant_id): array {
    // Guard: ensure service_type column exists (added by order_survey_integration_migration.sql)
    db_add_column($conn, 'orders', 'service_type', "VARCHAR(30) NOT NULL DEFAULT 'delivery'");

    $stats = [
        'online_views_today'   => 0,
        'online_views_month'   => 0,
        'online_views_total'   => 0,
        'online_views_last_mo' => 0,
        'dine_in_today'        => 0,
        'dine_in_month'        => 0,
        'dine_in_total'        => 0,
        'dine_in_last_mo'      => 0,
    ];

    if ($restaurant_id <= 0) {
        return array_merge($stats, [
            'total_visits_month'   => 0,
            'total_visits_today'   => 0,
            'total_visits_total'   => 0,
            'total_visits_last_mo' => 0,
            'growth_pct'           => 0.0,
            'online_pct'           => 50.0,
            'dine_in_pct'          => 50.0,
        ]);
    }

    ensureBusinessViewsTable($conn);

    $bv_q = $conn->query("
        SELECT
            SUM(CASE WHEN DATE(visited_at) = CURDATE() THEN 1 ELSE 0 END) AS online_views_today,
            SUM(CASE WHEN MONTH(visited_at) = MONTH(CURRENT_DATE()) AND YEAR(visited_at) = YEAR(CURRENT_DATE()) THEN 1 ELSE 0 END) AS online_views_month,
            COUNT(*) AS online_views_total,
            SUM(CASE WHEN MONTH(visited_at) = MONTH(CURRENT_DATE() - INTERVAL 1 MONTH) AND YEAR(visited_at) = YEAR(CURRENT_DATE() - INTERVAL 1 MONTH) THEN 1 ELSE 0 END) AS online_views_last_mo
        FROM business_views
        WHERE restaurant_id = {$restaurant_id}
    ");
    if ($bv_q && ($bv_row = $bv_q->fetch_assoc())) {
        $stats['online_views_today']   = (int)($bv_row['online_views_today']   ?? 0);
        $stats['online_views_month']   = (int)($bv_row['online_views_month']   ?? 0);
        $stats['online_views_total']   = (int)($bv_row['online_views_total']   ?? 0);
        $stats['online_views_last_mo'] = (int)($bv_row['online_views_last_mo'] ?? 0);
    }

    // In-store visits: POS / customer dine-in and takeout orders
    $di_q = $conn->query("
        SELECT
            SUM(CASE WHEN DATE(created_at) = CURDATE() THEN 1 ELSE 0 END) AS dine_in_today,
            SUM(CASE WHEN MONTH(created_at) = MONTH(CURRENT_DATE()) AND YEAR(created_at) = YEAR(CURRENT_DATE()) THEN 1 ELSE 0 END) AS dine_in_month,
            COUNT(*) AS dine_in_total,
            SUM(CASE WHEN MONTH(created_at) = MONTH(CURRENT_DATE() - INTERVAL 1 MONTH) AND YEAR(created_at) = YEAR(CURRENT_DATE() - INTERVAL 1 MONTH) THEN 1 ELSE 0 END) AS dine_in_last_mo
        FROM orders
        WHERE restaurant_id = {$restaurant_id}
          AND service_type IN ('dine_in', 'takeout')
    ");
    if ($di_q && ($di_row = $di_q->fetch_assoc())) {
        $stats['dine_in_today']   = (int)($di_row['dine_in_today']   ?? 0);
        $stats['dine_in_month']   = (int)($di_row['dine_in_month']   ?? 0);
        $stats['dine_in_total']   = (int)($di_row['dine_in_total']   ?? 0);
        $stats['dine_in_last_mo'] = (int)($di_row['dine_in_last_mo'] ?? 0);
    }

    $total_visits_month   = $stats['online_views_month'] + $stats['dine_in_month'];
    $total_visits_today   = $stats['online_views_today'] + $stats['dine_in_today'];
    $total_visits_total   = $stats['online_views_total'] + $stats['dine_in_total'];
    $total_visits_last_mo = $stats['online_views_last_mo'] + $stats['dine_in_last_mo'];

    $growth_pct = 0.0;
    if ($total_visits_last_mo > 0) {
        $growth_pct = (($total_visits_month - $total_visits_last_mo) / $total_visits_last_mo) * 100;
    } elseif ($total_visits_month > 0) {
        $growth_pct = 100.0;
    }

    $online_pct  = $total_visits_month > 0 ? round(($stats['online_views_month'] / $total_visits_month) * 100, 1) : 50.0;
    $dine_in_pct = $total_visits_month > 0 ? round(($stats['dine_in_month']       / $total_visits_month) * 100, 1) : 50.0;

    return array_merge($stats, compact(
        'total_visits_month',
        'total_visits_today',
        'total_visits_total',
        'total_visits_last_mo',
        'growth_pct',
        'online_pct',
        'dine_in_pct'
    ));
}

// ── Credibility Score Engine ──────────────────────────────────────────────────

/**
 * Ensure credibility columns and log table exist (idempotent).
 */
function ensureCredibilitySchema(mysqli $conn): void
{
    db_add_column($conn, 'halal_restaurants', 'credibility_score',
        'DECIMAL(5,2) DEFAULT NULL');
    db_add_column($conn, 'halal_restaurants', 'credibility_tier',
        'TINYINT(1) DEFAULT NULL');
    db_add_column($conn, 'halal_restaurants', 'credibility_updated_at',
        'DATETIME DEFAULT NULL');

    $conn->query("CREATE TABLE IF NOT EXISTS `restaurant_credibility_log` (
        `id`                    INT UNSIGNED  NOT NULL AUTO_INCREMENT,
        `restaurant_id`         INT(11)       NOT NULL,
        `score_foot_traffic`    DECIMAL(5,2)  NOT NULL DEFAULT 0,
        `score_engagement`      DECIMAL(5,2)  NOT NULL DEFAULT 0,
        `score_compliance`      DECIMAL(5,2)  NOT NULL DEFAULT 0,
        `score_popularity`      DECIMAL(5,2)  NOT NULL DEFAULT 0,
        `total_score`           DECIMAL(5,2)  NOT NULL DEFAULT 0,
        `tier`                  TINYINT(1)    NOT NULL DEFAULT 0,
        `raw_dine_in_30d`       INT UNSIGNED  NOT NULL DEFAULT 0,
        `raw_profile_views_30d` INT UNSIGNED  NOT NULL DEFAULT 0,
        `raw_avg_rating`        DECIMAL(3,2)  NOT NULL DEFAULT 0,
        `raw_total_reviews`     INT UNSIGNED  NOT NULL DEFAULT 0,
        `raw_hacera_hrp`        DECIMAL(5,1)  NOT NULL DEFAULT 0,
        `raw_has_cert`          TINYINT(1)    NOT NULL DEFAULT 0,
        `computed_at`           DATETIME      NOT NULL DEFAULT CURRENT_TIMESTAMP,
        PRIMARY KEY (`id`),
        KEY `idx_rcl_restaurant` (`restaurant_id`),
        KEY `idx_rcl_computed`   (`computed_at`),
        CONSTRAINT `rcl_restaurant_fk`
            FOREIGN KEY (`restaurant_id`) REFERENCES `halal_restaurants` (`id`) ON DELETE CASCADE
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
}

/**
 * Map a raw credibility score (0–100) to a tier integer and label.
 *
 * Tier 3 — Top Tier          (≥ 80)
 * Tier 2 — Highly Recommended (≥ 55)
 * Tier 1 — Popular Choice     (≥ 30)
 * Tier 0 — Unranked           (< 30)
 *
 * @return array{ tier:int, label:string, color:string, bg:string,
 *                border:string, icon:string, stars:int }
 */
function getCredibilityTier(float $score): array
{
    return match(true) {
        $score >= 80 => [
            'tier'   => 3,
            'label'  => 'Top Tier',
            'color'  => '#92400e',
            'bg'     => '#fef3c7',
            'border' => '#fde68a',
            'icon'   => 'fas fa-award',
            'stars'  => 3,
        ],
        $score >= 55 => [
            'tier'   => 2,
            'label'  => 'Highly Recommended',
            'color'  => '#1d4ed8',
            'bg'     => '#eff6ff',
            'border' => '#bfdbfe',
            'icon'   => 'fas fa-thumbs-up',
            'stars'  => 2,
        ],
        $score >= 30 => [
            'tier'   => 1,
            'label'  => 'Popular Choice',
            'color'  => '#15803d',
            'bg'     => '#f0fdf4',
            'border' => '#bbf7d0',
            'icon'   => 'fas fa-fire',
            'stars'  => 1,
        ],
        default => [
            'tier'   => 0,
            'label'  => 'Unranked',
            'color'  => '#64748b',
            'bg'     => '#f8fafc',
            'border' => '#e2e8f0',
            'icon'   => 'fas fa-circle',
            'stars'  => 0,
        ],
    };
}

/**
 * Compute (and persist) the credibility score for one restaurant.
 *
 * ── Weighted formula ──────────────────────────────────────────────────────────
 *
 *   Component               Weight   Source
 *   ─────────────────────── ──────   ───────────────────────────────────────────
 *   Foot Traffic & Dine-ins   35 %   orders WHERE service_type IN (dine_in,takeout)
 *                                    last 30 days, log-scaled cap 200
 *   Customer Engagement       25 %   avg_rating (×60%) + review volume (×40%)
 *   Halal Compliance          25 %   cert validity (50 pts) + HaCeRa HRP (50 pts)
 *   Platform Popularity       15 %   profile views last 30 days, log-scaled cap 500
 *                                    + repeat-visit momentum (views_30d / views_prev_30d)
 *   ─────────────────────── ──────
 *   Total                    100 %   → composite 0–100
 *
 * ── Normalisation ─────────────────────────────────────────────────────────────
 *   Each component is first computed on a 0–100 internal scale, then multiplied
 *   by its weight fraction before summing.
 *
 * @param  mysqli $conn
 * @param  int    $restaurant_id
 * @param  bool   $persist       Write result back to halal_restaurants + log table
 * @return array{
 *   total_score:float, tier:int, label:string,
 *   components: array{foot_traffic:float, engagement:float,
 *                     compliance:float, popularity:float},
 *   raw: array{dine_in_30d:int, profile_views_30d:int,
 *              avg_rating:float, total_reviews:int,
 *              hacera_hrp:float, has_cert:int}
 * }
 */
function computeCredibilityScore(
    mysqli $conn,
    int    $restaurant_id,
    bool   $persist = true
): array {
    ensureCredibilitySchema($conn);

    if ($restaurant_id <= 0) {
        return _credibilityEmpty();
    }

    // ── 1. Foot Traffic — dine_in + takeout orders last 30 days ──────────────
    $ft_row = $conn->query("
        SELECT COUNT(*) AS cnt
        FROM   orders
        WHERE  restaurant_id  = {$restaurant_id}
          AND  service_type  IN ('dine_in','takeout')
          AND  order_status  != 'cancelled'
          AND  created_at    >= DATE_SUB(NOW(), INTERVAL 30 DAY)
    ")?->fetch_assoc();
    $dine_in_30d = (int)($ft_row['cnt'] ?? 0);

    // Log-scale: ln(x+1)/ln(201) × 100, capped at 200 orders = 100 pts
    $score_foot = $dine_in_30d > 0
        ? min(100, round(log($dine_in_30d + 1) / log(201) * 100, 2))
        : 0.0;

    // ── 2. Customer Engagement — rating quality + review volume ──────────────
    $eng_row = $conn->query("
        SELECT avg_rating, total_reviews FROM halal_restaurants WHERE id = {$restaurant_id}
    ")?->fetch_assoc();
    $avg_rating    = (float)($eng_row['avg_rating']    ?? 0);
    $total_reviews = (int)  ($eng_row['total_reviews'] ?? 0);

    // Rating sub-score: (avg_rating / 5) × 100
    $rating_sub = $avg_rating > 0 ? round(($avg_rating / 5.0) * 100, 2) : 0.0;

    // Review volume sub-score: log-scale cap 100 reviews = 100 pts
    $review_sub = $total_reviews > 0
        ? min(100, round(log($total_reviews + 1) / log(101) * 100, 2))
        : 0.0;

    // Weighted blend: rating 60%, volume 40%
    $score_engagement = round(($rating_sub * 0.60) + ($review_sub * 0.40), 2);

    // ── 3. Halal Compliance — certification validity + HaCeRa HRP ────────────
    // Certificate check: has an active, non-expired certificate
    $cert_row = $conn->query("
        SELECT COUNT(*) AS cnt
        FROM   halal_certificates hc
        WHERE  (hc.id = (SELECT certificate_id FROM halal_restaurants WHERE id = {$restaurant_id})
                OR hc.application_id = (
                    SELECT ha.id FROM hdp_applications ha
                    JOIN   halal_restaurants hr ON ha.business_owner_id = hr.business_owner_id
                    WHERE  hr.id = {$restaurant_id}
                    ORDER  BY ha.created_at DESC LIMIT 1
                ))
          AND  hc.status NOT IN ('rejected','revoked','expired','suspended')
        LIMIT 1
    ")?->fetch_assoc();
    $has_cert = (int)(($cert_row['cnt'] ?? 0) > 0);

    $hrp_row   = $conn->query("
        SELECT COALESCE(hacera_hrp, 0) AS hrp FROM halal_restaurants WHERE id = {$restaurant_id}
    ")?->fetch_assoc();
    $hacera_hrp = (float)($hrp_row['hrp'] ?? 0);

    // Cert worth 50 pts, HRP normalised to 50 pts
    $score_compliance = round(($has_cert * 50) + (($hacera_hrp / 100) * 50), 2);

    // ── 4. Platform Popularity — profile views + momentum ────────────────────
    ensureBusinessViewsTable($conn);
    $views_row = $conn->query("
        SELECT
            SUM(CASE WHEN visited_at >= DATE_SUB(NOW(), INTERVAL 30 DAY)  THEN 1 ELSE 0 END) AS views_30d,
            SUM(CASE WHEN visited_at >= DATE_SUB(NOW(), INTERVAL 60 DAY)
                      AND visited_at <  DATE_SUB(NOW(), INTERVAL 30 DAY)  THEN 1 ELSE 0 END) AS views_prev_30d
        FROM business_views
        WHERE restaurant_id = {$restaurant_id}
    ")?->fetch_assoc();
    $views_30d      = (int)($views_row['views_30d']      ?? 0);
    $views_prev_30d = (int)($views_row['views_prev_30d'] ?? 0);

    // Views sub-score: log-scale cap 500 views = 100 pts
    $views_sub = $views_30d > 0
        ? min(100, round(log($views_30d + 1) / log(501) * 100, 2))
        : 0.0;

    // Momentum bonus: growth ratio capped at +20 pts
    $momentum = 0.0;
    if ($views_prev_30d > 0 && $views_30d > $views_prev_30d) {
        $growth_ratio = ($views_30d - $views_prev_30d) / $views_prev_30d;
        $momentum     = min(20.0, round($growth_ratio * 20, 2));
    } elseif ($views_prev_30d === 0 && $views_30d > 0) {
        $momentum = 10.0; // first-time visibility bonus
    }

    $score_popularity = min(100, round($views_sub + $momentum, 2));

    // ── Composite weighted total ──────────────────────────────────────────────
    // Weights: foot_traffic=35%, engagement=25%, compliance=25%, popularity=15%
    $total_score = round(
        ($score_foot       * 0.35) +
        ($score_engagement * 0.25) +
        ($score_compliance * 0.25) +
        ($score_popularity * 0.15),
        2
    );

    $tier_data = getCredibilityTier($total_score);

    // ── Persist to halal_restaurants + log ───────────────────────────────────
    if ($persist) {
        $conn->query("
            UPDATE halal_restaurants
            SET    credibility_score      = {$total_score},
                   credibility_tier       = {$tier_data['tier']},
                   credibility_updated_at = NOW()
            WHERE  id = {$restaurant_id}
        ");

        $log = $conn->prepare("
            INSERT INTO restaurant_credibility_log
                (restaurant_id, score_foot_traffic, score_engagement,
                 score_compliance, score_popularity, total_score, tier,
                 raw_dine_in_30d, raw_profile_views_30d, raw_avg_rating,
                 raw_total_reviews, raw_hacera_hrp, raw_has_cert)
            VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?)
        ");
        if ($log) {
            $log->bind_param(
                "iddddddiididdi",
                $restaurant_id,
                $score_foot, $score_engagement, $score_compliance, $score_popularity,
                $total_score, $tier_data['tier'],
                $dine_in_30d, $views_30d, $avg_rating, $total_reviews,
                $hacera_hrp, $has_cert
            );
            $log->execute();
            $log->close();
        }
    }

    return [
        'total_score' => $total_score,
        'tier'        => $tier_data['tier'],
        'label'       => $tier_data['label'],
        'color'       => $tier_data['color'],
        'bg'          => $tier_data['bg'],
        'border'      => $tier_data['border'],
        'icon'        => $tier_data['icon'],
        'stars'       => $tier_data['stars'],
        'components'  => [
            'foot_traffic' => $score_foot,
            'engagement'   => $score_engagement,
            'compliance'   => $score_compliance,
            'popularity'   => $score_popularity,
        ],
        'raw' => [
            'dine_in_30d'      => $dine_in_30d,
            'profile_views_30d'=> $views_30d,
            'avg_rating'       => $avg_rating,
            'total_reviews'    => $total_reviews,
            'hacera_hrp'       => $hacera_hrp,
            'has_cert'         => $has_cert,
        ],
    ];
}

/**
 * Return a zeroed-out credibility result (used for invalid restaurant IDs).
 */
function _credibilityEmpty(): array
{
    $tier = getCredibilityTier(0);
    return [
        'total_score' => 0.0,
        'tier'        => 0,
        'label'       => $tier['label'],
        'color'       => $tier['color'],
        'bg'          => $tier['bg'],
        'border'      => $tier['border'],
        'icon'        => $tier['icon'],
        'stars'       => 0,
        'components'  => [
            'foot_traffic' => 0.0,
            'engagement'   => 0.0,
            'compliance'   => 0.0,
            'popularity'   => 0.0,
        ],
        'raw' => [
            'dine_in_30d'       => 0,
            'profile_views_30d' => 0,
            'avg_rating'        => 0.0,
            'total_reviews'     => 0,
            'hacera_hrp'        => 0.0,
            'has_cert'          => 0,
        ],
    ];
}

/**
 * Read the cached credibility score for one restaurant from halal_restaurants.
 * Falls back to computing live (without persisting) if the score is missing
 * or stale (> 24 hours old).
 *
 * @return array Same shape as computeCredibilityScore()
 */
function getCredibilityScore(mysqli $conn, int $restaurant_id): array
{
    ensureCredibilitySchema($conn);

    $row = $conn->query("
        SELECT credibility_score, credibility_tier, credibility_updated_at
        FROM   halal_restaurants
        WHERE  id = {$restaurant_id}
        LIMIT 1
    ")?->fetch_assoc();

    $stale = true;
    if ($row && $row['credibility_score'] !== null && $row['credibility_updated_at'] !== null) {
        $age_hours = (time() - strtotime($row['credibility_updated_at'])) / 3600;
        $stale     = $age_hours > 24;
    }

    if ($stale) {
        // Recompute and persist
        return computeCredibilityScore($conn, $restaurant_id, persist: true);
    }

    $score     = (float)$row['credibility_score'];
    $tier_data = getCredibilityTier($score);

    return array_merge($tier_data, [
        'total_score' => $score,
        'components'  => null, // cached — components not stored in main table
        'raw'         => null,
    ]);
}

// ── Shared Expense Helpers ────────────────────────────────────────────────────

/**
 * getSharedExpenses — Canonical query for restaurant_expenses used by BOTH
 * the Owner Dashboard and Operations Manager pages.
 *
 * Using a single function guarantees both roles always see identical data for
 * the same restaurant and period — true bi-directional synchronization.
 *
 * @param  mysqli      $conn
 * @param  int         $restaurant_id
 * @param  string      $period        'daily' | 'weekly' | 'monthly' | 'all'
 * @param  int         $limit         0 = no limit
 * @param  string|null $date_where    Optional raw SQL AND-clause that overrides
 *                                    the built-in period logic. Used by the owner
 *                                    dashboard to pass fin_period-aware clauses
 *                                    (e.g. a specific month/year combination).
 * @return array  Rows with keys: id, category, description, amount,
 *                receipt_path, expense_date, period_tag, added_by_role,
 *                created_at, logged_by_name, logged_by_role_label
 */
function getSharedExpenses(mysqli $conn, int $restaurant_id, string $period = 'all', int $limit = 0, ?string $date_where = null): array {
    if (!$restaurant_id) return [];

    // If a raw date clause is supplied, use it directly; otherwise derive from $period.
    if ($date_where !== null) {
        $date_clause = $date_where;
    } else {
        $date_clause = match($period) {
            'daily'   => "AND e.expense_date = CURDATE()",
            'weekly'  => "AND e.expense_date >= DATE_SUB(CURDATE(), INTERVAL 6 DAY)",
            'monthly' => "AND MONTH(e.expense_date)=MONTH(NOW()) AND YEAR(e.expense_date)=YEAR(NOW())",
            default   => ""
        };
    }
    $limit_clause = ($limit > 0) ? "LIMIT " . (int)$limit : "";

    // LEFT JOIN restaurant_staff to resolve the role label of the logger.
    // We match on both restaurant_id AND user_id to avoid cross-restaurant joins.
    $sql = "
        SELECT
            e.id,
            e.category,
            e.description,
            e.amount,
            e.receipt_path,
            e.expense_date,
            e.period_tag,
            COALESCE(e.added_by_role, 'unknown') AS added_by_role,
            e.created_at,
            u.full_name  AS logged_by_name,
            COALESCE(rsr.role_label, 'Business Owner') AS logged_by_role_label
        FROM restaurant_expenses e
        LEFT JOIN users u
               ON e.logged_by = u.id
        LEFT JOIN restaurant_staff rs
               ON rs.user_id = e.logged_by
              AND rs.restaurant_id = e.restaurant_id
              AND rs.is_active = 1
        LEFT JOIN restaurant_staff_roles rsr
               ON rs.staff_role_id = rsr.id
        WHERE e.restaurant_id = {$restaurant_id}
              {$date_clause}
        ORDER BY e.expense_date DESC, e.created_at DESC
        {$limit_clause}
    ";

    $res = $conn->query($sql);
    return $res ? $res->fetch_all(MYSQLI_ASSOC) : [];
}

/**
 * renderExpenseAttributionBadge — Returns an HTML string with a color-coded
 * "Logged by" badge for the expense table, showing role + name.
 *
 * @param  string $role       Value of added_by_role column
 * @param  string $name       Full name of the logger (logged_by_name)
 * @param  string $role_label Human-readable role label
 * @return string HTML
 */
function renderExpenseAttributionBadge(string $role, string $name, string $role_label): string {
    $configs = [
        'business_owner' => ['#166534','#dcfce7','fas fa-user-crown','Business Owner'],
        'ops_manager'    => ['#1d4ed8','#eff6ff','fas fa-user-tie',  'Ops Manager'],
        'unknown'        => ['#64748b','#f1f5f9','fas fa-user',       'Staff'],
    ];
    [$color, $bg, $icon, $default_label] = $configs[$role] ?? $configs['unknown'];
    $display_role = ($role_label && $role_label !== 'unknown') ? htmlspecialchars($role_label) : $default_label;
    $display_name = htmlspecialchars($name ?: 'Unknown');

    return "<span style=\"display:inline-flex;align-items:center;gap:5px;background:{$bg};color:{$color};"
         . "border-radius:8px;padding:3px 9px;font-size:.72rem;font-weight:700;white-space:nowrap;\">"
         . "<i class=\"{$icon}\" style=\"font-size:.65rem;\"></i>"
         . "<span>{$display_role}</span>"
         . "</span>"
         . "<div style=\"font-size:.7rem;color:#94a3b8;margin-top:2px;\">{$display_name}</div>";
}
?>
