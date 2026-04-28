<?php
// Main application bootstrap
session_start();

// Load configuration
require_once __DIR__ . '/database.php';
require_once __DIR__ . '/constants.php';
require_once __DIR__ . '/../includes/functions.php';

// Create upload directories if they don't exist
$upload_dirs = [
    UPLOAD_DIR,
    UPLOAD_DIR . 'avatars/',
    UPLOAD_DIR . 'documents/',
    UPLOAD_DIR . 'certificates/',
    UPLOAD_DIR . 'laboratory/',
    UPLOAD_DIR . 'role_applications/',
    UPLOAD_DIR . 'restaurant_images/',
    UPLOAD_DIR . 'menu_images/',
    UPLOAD_DIR . 'receipts/',
];

foreach ($upload_dirs as $dir) {
    if (!is_dir($dir)) {
        mkdir($dir, 0755, true);
    }
}
?>
