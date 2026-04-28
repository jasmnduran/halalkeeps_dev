<?php
// System Constants
define('SITE_NAME', 'Halal Institute of Development Philippines');
define('SITE_TAGLINE', 'Halal Certification Body');
define('BASE_URL', '/halal_final/');
define('UPLOAD_DIR', __DIR__ . '/../uploads/');
define('MAX_FILE_SIZE', 10 * 1024 * 1024); // 10MB

// Google OAuth Configuration
define('GOOGLE_CLIENT_ID', getenv('GOOGLE_CLIENT_ID') ?: '');
define('GOOGLE_CLIENT_SECRET', getenv('GOOGLE_CLIENT_SECRET') ?: '');
define('GOOGLE_REDIRECT_URI', BASE_URL . 'auth/google_callback.php');

// Role IDs (matching database)
define('ROLE_CUSTOMER', 1);
define('ROLE_BUSINESS_OWNER', 2);
define('ROLE_EVALUATOR', 3);
define('ROLE_AUDITOR_TECHNICAL', 4);
define('ROLE_AUDITOR_SHARIAH', 5);
define('ROLE_IMPARTIAL_COMMITTEE', 6);
define('ROLE_DECISION_COMMITTEE', 7);
define('ROLE_PRESIDENT', 8);
define('ROLE_RECEIVING_OFFICER', 9);
define('ROLE_LABORATORY_ANALYST', 10);

// Application Status Constants
define('STATUS_DRAFT', 'draft');
define('STATUS_SUBMITTED', 'submitted');
define('STATUS_UNDER_REVIEW', 'under_review');
define('STATUS_VERIFIED', 'verified');
define('STATUS_RETURNED', 'returned');
define('STATUS_REJECTED', 'rejected');
define('STATUS_APPROVED', 'approved');

// Allowed file types
define('ALLOWED_FILE_TYPES', ['pdf', 'doc', 'docx', 'jpg', 'jpeg', 'png', 'gif']);
define('ALLOWED_IMAGE_TYPES', ['jpg', 'jpeg', 'png', 'gif', 'webp']);
?>
