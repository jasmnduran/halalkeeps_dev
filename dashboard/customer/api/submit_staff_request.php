<?php
/**
 * Staff Application Submit Handler
 * POST — validates, uploads files, inserts into staff_applications.
 * Redirects back to restaurant_view.php with a success/error param.
 */
require_once __DIR__ . '/../../../config/app.php';
requireLogin();
requireRole([ROLE_CUSTOMER]);

$user_id = (int)$_SESSION['user_id'];

// ── Helpers ───────────────────────────────────────────────────────────────────

function sa_redirect(int $rid, string $param, string $msg): void {
    $url = BASE_URL . 'dashboard/customer/restaurant_view.php?id=' . $rid
         . '&' . $param . '=' . urlencode($msg);
    header('Location: ' . $url);
    exit();
}

/**
 * Upload a single staff-application document.
 * Returns ['success'=>true,'path'=>'...'] or ['success'=>false,'message'=>'...']
 */
function sa_upload(array $file, string $slot): array {
    $ok_ext   = ['pdf','doc','docx','jpg','jpeg','png'];
    $ok_mimes = [
        'application/pdf',
        'application/msword',
        'application/vnd.openxmlformats-officedocument.wordprocessingml.document',
        'image/jpeg',
        'image/png',
    ];

    if ($file['error'] === UPLOAD_ERR_NO_FILE) {
        return ['success' => false, 'message' => "No file uploaded for: $slot"];
    }
    if ($file['error'] !== UPLOAD_ERR_OK) {
        return ['success' => false, 'message' => "Upload error for $slot (code {$file['error']})"];
    }
    if ($file['size'] > MAX_FILE_SIZE) {
        return ['success' => false, 'message' => "File too large for $slot (max 20 MB)"];
    }

    $ext = strtolower(pathinfo($file['name'], PATHINFO_EXTENSION));
    if (!in_array($ext, $ok_ext, true)) {
        return ['success' => false, 'message' => "File type .$ext not allowed for $slot"];
    }

    $fi   = finfo_open(FILEINFO_MIME_TYPE);
    $mime = finfo_file($fi, $file['tmp_name']);
    finfo_close($fi);
    if (!in_array($mime, $ok_mimes, true)) {
        return ['success' => false, 'message' => "File content does not match its extension for $slot"];
    }

    $dir = UPLOAD_DIR . 'staff_applications/';
    @mkdir($dir, 0755, true);
    if (!is_dir($dir)) {
        return ['success' => false, 'message' => "Server storage error for $slot"];
    }

    $fn = uniqid($slot . '_', true) . '_' . time() . '.' . $ext;
    if (!move_uploaded_file($file['tmp_name'], $dir . $fn)) {
        return ['success' => false, 'message' => "Could not save file for $slot"];
    }

    return ['success' => true, 'path' => 'staff_applications/' . $fn];
}

// ── Input validation ──────────────────────────────────────────────────────────

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    header('Location: ' . BASE_URL . 'dashboard/customer/restaurants.php');
    exit();
}

// ── Idempotent migration ───────────────────────────────────────────────────────
$conn->query("
CREATE TABLE IF NOT EXISTS `staff_applications` (
    `id`                INT UNSIGNED  NOT NULL AUTO_INCREMENT,
    `user_id`           INT(11)       NOT NULL,
    `restaurant_id`     INT(11)       NOT NULL,
    `application_type`  ENUM('applicant','existing') NOT NULL,
    `applied_role`      VARCHAR(60)   NOT NULL,
    `cover_letter_text` TEXT          DEFAULT NULL,
    `file_cover_letter` VARCHAR(500)  DEFAULT NULL,
    `file_resume`       VARCHAR(500)  DEFAULT NULL,
    `file_nbi`          VARCHAR(500)  DEFAULT NULL,
    `file_medical`      VARCHAR(500)  DEFAULT NULL,
    `file_gov_id`       VARCHAR(500)  DEFAULT NULL,
    `file_proof_employ` VARCHAR(500)  DEFAULT NULL,
    `status`            ENUM('pending','approved','rejected') NOT NULL DEFAULT 'pending',
    `owner_notes`       TEXT          DEFAULT NULL,
    `reviewed_by`       INT(11)       DEFAULT NULL,
    `reviewed_at`       DATETIME      DEFAULT NULL,
    `created_at`        TIMESTAMP     NOT NULL DEFAULT CURRENT_TIMESTAMP,
    `updated_at`        TIMESTAMP     NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (`id`),
    KEY `idx_sa_user`       (`user_id`),
    KEY `idx_sa_restaurant` (`restaurant_id`),
    KEY `idx_sa_status`     (`status`),
    CONSTRAINT `sa_user_fk`       FOREIGN KEY (`user_id`)       REFERENCES `users`(`id`)             ON DELETE CASCADE,
    CONSTRAINT `sa_restaurant_fk` FOREIGN KEY (`restaurant_id`) REFERENCES `halal_restaurants`(`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
");
// Widen applied_role from ENUM to VARCHAR if the table already existed with the old definition
$conn->query("ALTER TABLE `staff_applications` MODIFY COLUMN `applied_role` VARCHAR(60) NOT NULL");

$rid  = (int)($_POST['restaurant_id'] ?? 0);
$type = trim(strip_tags($_POST['application_type'] ?? ''));
$role = trim(strip_tags($_POST['applied_role']     ?? ''));

if (!$rid) sa_redirect(0, 'error', 'Invalid restaurant.');

// Verify restaurant exists
$chk = $conn->prepare("SELECT id FROM halal_restaurants WHERE id = ? AND is_active = 1 LIMIT 1");
$chk->bind_param("i", $rid);
$chk->execute();
if ($chk->get_result()->num_rows === 0) sa_redirect($rid, 'error', 'Restaurant not found.');

if (!in_array($type, ['applicant', 'existing'], true))
    sa_redirect($rid, 'error', 'Invalid application type.');

// Validate the submitted role against this restaurant's actual roles (platform + custom).
// This automatically accepts any custom role the business owner has created.
// Ensure platform roles exist first (seeded lazily, same as owner_dashboard does).
$conn->query("CREATE TABLE IF NOT EXISTS `restaurant_staff_roles` (
    `id`          SMALLINT UNSIGNED NOT NULL AUTO_INCREMENT,
    `role_key`    VARCHAR(30)  NOT NULL,
    `role_label`  VARCHAR(60)  NOT NULL,
    `description` VARCHAR(255) DEFAULT NULL,
    PRIMARY KEY (`id`),
    UNIQUE KEY `uq_role_key` (`role_key`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
$conn->query("INSERT IGNORE INTO `restaurant_staff_roles` (`role_key`,`role_label`,`description`) VALUES
    ('owner',       'Business Owner',     'Full access: analytics, financials, staff management.'),
    ('ops_manager', 'Operations Manager', 'Store profile, menu management, and Halal compliance.'),
    ('cashier',     'Cashier',            'POS order intake and payment processing only.'),
    ('chef',        'Chef / Head Cook',   'Manages kitchen inventory, requisitions, and menu recipe mapping.')");
// Ensure is_custom / restaurant_id columns exist (added by getActiveBusinessRoles migration)
db_add_column($conn, 'restaurant_staff_roles', 'restaurant_id', 'INT(11) DEFAULT NULL');
db_add_column($conn, 'restaurant_staff_roles', 'is_custom',     'TINYINT(1) NOT NULL DEFAULT 0');

$role_chk = $conn->prepare("
    SELECT role_label FROM restaurant_staff_roles
    WHERE  role_key <> 'owner'
      AND  role_key = ?
      AND  (is_custom = 0 OR restaurant_id = ?)
    LIMIT  1
");
$role_chk->bind_param("si", $role, $rid);
$role_chk->execute();
$role_row = $role_chk->get_result()->fetch_assoc();
$role_chk->close();
if (!$role_row)
    sa_redirect($rid, 'error', 'Invalid role selected.');
$role_label = $role_row['role_label'];

// Block duplicate pending applications
$dup = $conn->prepare(
    "SELECT id FROM staff_applications WHERE user_id = ? AND restaurant_id = ? AND status = 'pending' LIMIT 1"
);
$dup->bind_param("ii", $user_id, $rid);
$dup->execute();
if ($dup->get_result()->num_rows > 0)
    sa_redirect($rid, 'error', 'You already have a pending application for this restaurant.');

// ── Process uploads by path ───────────────────────────────────────────────────

$cover_letter_text = '';
$file_cover_letter = null;
$file_resume       = null;
$file_nbi          = null;
$file_medical      = null;
$file_gov_id       = null;
$file_proof_employ = null;

if ($type === 'applicant') {
    $cover_letter_text = trim(strip_tags($_POST['cover_letter_text'] ?? ''));
    $has_cover_text    = $cover_letter_text !== '';
    $has_cover_file    = isset($_FILES['file_cover_letter'])
                         && $_FILES['file_cover_letter']['error'] !== UPLOAD_ERR_NO_FILE;

    if (!$has_cover_text && !$has_cover_file)
        sa_redirect($rid, 'error', 'Please provide a cover letter (text or PDF file).');

    // Optional cover letter file
    if ($has_cover_file) {
        $r = sa_upload($_FILES['file_cover_letter'], 'Cover Letter');
        if (!$r['success']) sa_redirect($rid, 'error', $r['message']);
        $file_cover_letter = $r['path'];
    }

    // Required: Resume
    if (!isset($_FILES['file_resume']) || $_FILES['file_resume']['error'] === UPLOAD_ERR_NO_FILE)
        sa_redirect($rid, 'error', 'Resume / CV is required.');
    $r = sa_upload($_FILES['file_resume'], 'Resume');
    if (!$r['success']) sa_redirect($rid, 'error', $r['message']);
    $file_resume = $r['path'];

    // Required: NBI Clearance
    if (!isset($_FILES['file_nbi']) || $_FILES['file_nbi']['error'] === UPLOAD_ERR_NO_FILE)
        sa_redirect($rid, 'error', 'NBI Clearance is required.');
    $r = sa_upload($_FILES['file_nbi'], 'NBI Clearance');
    if (!$r['success']) sa_redirect($rid, 'error', $r['message']);
    $file_nbi = $r['path'];

    // Required: Medical Certificate
    if (!isset($_FILES['file_medical']) || $_FILES['file_medical']['error'] === UPLOAD_ERR_NO_FILE)
        sa_redirect($rid, 'error', 'Medical Certificate is required.');
    $r = sa_upload($_FILES['file_medical'], 'Medical Certificate');
    if (!$r['success']) sa_redirect($rid, 'error', $r['message']);
    $file_medical = $r['path'];

    // Required: Government ID
    if (!isset($_FILES['file_gov_id']) || $_FILES['file_gov_id']['error'] === UPLOAD_ERR_NO_FILE)
        sa_redirect($rid, 'error', 'Government ID is required.');
    $r = sa_upload($_FILES['file_gov_id'], 'Government ID');
    if (!$r['success']) sa_redirect($rid, 'error', $r['message']);
    $file_gov_id = $r['path'];

} else { // existing
    if (!isset($_FILES['file_proof_employ']) || $_FILES['file_proof_employ']['error'] === UPLOAD_ERR_NO_FILE)
        sa_redirect($rid, 'error', 'Proof of employment is required.');
    $r = sa_upload($_FILES['file_proof_employ'], 'Proof of Employment');
    if (!$r['success']) sa_redirect($rid, 'error', $r['message']);
    $file_proof_employ = $r['path'];
}

// ── Insert record ─────────────────────────────────────────────────────────────

$ins = $conn->prepare("
    INSERT INTO staff_applications
        (user_id, restaurant_id, application_type, applied_role,
         cover_letter_text, file_cover_letter, file_resume,
         file_nbi, file_medical, file_gov_id, file_proof_employ, status)
    VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, 'pending')
");
if (!$ins) sa_redirect($rid, 'error', 'Database error: ' . $conn->error);

$ins->bind_param(
    "iisssssssss",
    $user_id, $rid, $type, $role,
    $cover_letter_text, $file_cover_letter, $file_resume,
    $file_nbi, $file_medical, $file_gov_id, $file_proof_employ
);

if (!$ins->execute()) sa_redirect($rid, 'error', 'Failed to save application: ' . $ins->error);

$app_id = $conn->insert_id;

// ── Notify the business owner ─────────────────────────────────────────────────
$owner_row = $conn->query(
    "SELECT business_owner_id FROM halal_restaurants WHERE id = $rid LIMIT 1"
)->fetch_assoc();

if ($owner_row) {
    $type_label = $type === 'applicant' ? 'New Applicant' : 'Existing Employee';
    createNotification(
        $conn,
        $owner_row['business_owner_id'],
        'New Staff Application Received',
        $_SESSION['full_name'] . " applied as {$role_label} ({$type_label}).",
        'action_required',
        BASE_URL . 'dashboard/business_owner/staff_applications.php'
    );
}

// ── Activity log ──────────────────────────────────────────────────────────────
logActivity(
    $conn, $user_id,
    'Staff Application Submitted',
    "Application #{$app_id} — role: {$role}, type: {$type}, restaurant: {$rid}",
    'staff_applications'
);

// ── Done ──────────────────────────────────────────────────────────────────────
$msg = $type === 'applicant'
    ? 'Your application has been submitted! The owner will review it shortly.'
    : 'Your employment verification request has been submitted. The owner will confirm your role shortly.';

sa_redirect($rid, 'success', $msg);
