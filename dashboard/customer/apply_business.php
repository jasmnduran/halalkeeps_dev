<?php
require_once __DIR__ . '/../../config/app.php';
requireLogin();
requireRole([ROLE_CUSTOMER]);

$page_title   = 'Register as a Business';
$page_heading = 'Register as a Business';
$is_dashboard = true;
$breadcrumbs  = [
    ['label' => 'Dashboard', 'url' => BASE_URL . 'dashboard/customer/'],
    ['label' => 'Register as a Business'],
];
$user_id = $_SESSION['user_id'];
$error   = '';
$success = '';

// -- Tables --------------------------------------------------------------------
$conn->query("
CREATE TABLE IF NOT EXISTS `business_applications` (
    `id`                   INT(11)          NOT NULL AUTO_INCREMENT,
    `user_id`              INT(11)          NOT NULL,
    `certification_path`   ENUM('certified','not_certified') NOT NULL,
    `business_name`        VARCHAR(255)     DEFAULT NULL,
    `business_description` TEXT             NOT NULL,
    `owner_name`           VARCHAR(255)     NOT NULL DEFAULT '',
    `owner_age`            TINYINT UNSIGNED DEFAULT NULL,
    `owner_religion`       VARCHAR(100)     DEFAULT NULL,
    `status`               ENUM('pending','under_review','approved','rejected','returned') NOT NULL DEFAULT 'pending',
    `admin_notes`          TEXT             DEFAULT NULL,
    `reviewed_by`          INT(11)          DEFAULT NULL,
    `reviewed_at`          DATETIME         DEFAULT NULL,
    `created_at`           TIMESTAMP        NOT NULL DEFAULT CURRENT_TIMESTAMP,
    `updated_at`           TIMESTAMP        NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (`id`),
    KEY `idx_ba_user` (`user_id`),
    KEY `idx_ba_status` (`status`),
    CONSTRAINT `ba_user_fk` FOREIGN KEY (`user_id`) REFERENCES `users` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");

$conn->query("
CREATE TABLE IF NOT EXISTS `business_branches` (
    `id`                     INT(11)  NOT NULL AUTO_INCREMENT,
    `application_id`         INT(11)  NOT NULL,
    `branch_index`           TINYINT  NOT NULL DEFAULT 0,
    `is_main`                TINYINT(1) NOT NULL DEFAULT 0,
    `branch_type`            ENUM('restaurant','food_stall','karenderia','cafe','fast_food','food_truck','bakery','catering') NOT NULL DEFAULT 'restaurant',
    `cert_status`            ENUM('certified','not_certified') NOT NULL,
    `address_region`         VARCHAR(100) DEFAULT NULL,
    `address_province`       VARCHAR(100) DEFAULT NULL,
    `address_city`           VARCHAR(100) DEFAULT NULL,
    `address_barangay`       VARCHAR(150) DEFAULT NULL,
    `address_street`         VARCHAR(255) DEFAULT NULL,
    `address_zip`            VARCHAR(20)  DEFAULT NULL,
    `inherits_raw_materials` TINYINT(1) NOT NULL DEFAULT 0,
    `created_at`             TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (`id`),
    KEY `idx_bb_app` (`application_id`),
    CONSTRAINT `bb_app_fk` FOREIGN KEY (`application_id`) REFERENCES `business_applications` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");

$conn->query("
CREATE TABLE IF NOT EXISTS `branch_documents` (
    `id`             INT(11) NOT NULL AUTO_INCREMENT,
    `branch_id`      INT(11) NOT NULL,
    `application_id` INT(11) NOT NULL,
    `doc_type`       ENUM('bir_notice','halal_certificate','business_permit','mayors_barangay_permit','sanitary_permit','lto_permit','raw_material_list_file') NOT NULL,
    `original_name`  VARCHAR(255) NOT NULL,
    `file_path`      VARCHAR(500) NOT NULL,
    `file_size`      INT UNSIGNED DEFAULT NULL,
    `mime_type`      VARCHAR(100) DEFAULT NULL,
    `uploaded_at`    TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (`id`),
    KEY `idx_bd_branch` (`branch_id`),
    KEY `idx_bd_app` (`application_id`),
    CONSTRAINT `bd_branch_fk` FOREIGN KEY (`branch_id`) REFERENCES `business_branches` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");

// Add columns for bypass workflow - store uploaded halal certificate from registration
db_add_column($conn, 'business_applications', 'halal_certificate_path',
    "VARCHAR(500) DEFAULT NULL COMMENT 'Path to uploaded halal certificate for certified businesses'");
db_add_column($conn, 'business_applications', 'halal_certificate_expiry',
    "DATE DEFAULT NULL COMMENT 'Expiry date of the uploaded halal certificate'");

$conn->query("
CREATE TABLE IF NOT EXISTS `raw_material_items` (
    `id`                 INT(11) NOT NULL AUTO_INCREMENT,
    `application_id`     INT(11) NOT NULL,
    `branch_id`          INT(11) DEFAULT NULL,
    `item_name`          VARCHAR(255) NOT NULL,
    `manufacturer`       VARCHAR(255) DEFAULT NULL,
    `halal_status`       ENUM('certified','non_certified','pending') NOT NULL DEFAULT 'pending',
    `certification_body` VARCHAR(255) DEFAULT NULL,
    `image_front_path`   VARCHAR(500) DEFAULT NULL,
    `image_back_path`    VARCHAR(500) DEFAULT NULL,
    `has_back_logo`      TINYINT(1) NOT NULL DEFAULT 0,
    `sort_order`         SMALLINT NOT NULL DEFAULT 0,
    `created_at`         TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (`id`),
    KEY `idx_rmi_app` (`application_id`),
    KEY `idx_rmi_branch` (`branch_id`),
    CONSTRAINT `rmi_app_fk` FOREIGN KEY (`application_id`) REFERENCES `business_applications` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");

// -- Idempotent column additions for existing installs -------------------------
db_add_column($conn, 'business_applications', 'address_region',       'VARCHAR(100) DEFAULT NULL');
db_add_column($conn, 'business_applications', 'address_barangay',     'VARCHAR(150) DEFAULT NULL');
db_add_column($conn, 'business_applications', 'halal_classification',
    "ENUM('Halal','Muslim Friendly','Non-Pork','N/A') NOT NULL DEFAULT 'N/A'");
db_add_column($conn, 'raw_material_items',    'branch_id',            'INT(11) DEFAULT NULL', 'application_id');

// Drop the bad composite unique key if it exists on this server.
// Checking information_schema first avoids a fatal exception on hosts
// where the key was never created (e.g. first-time deployments).
$_drop_chk = $conn->prepare(
    "SELECT COUNT(*) FROM information_schema.STATISTICS
     WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'business_applications'
     AND INDEX_NAME = 'uq_ba_active_user'"
);
$_drop_chk->execute();
if ((int)$_drop_chk->get_result()->fetch_row()[0] > 0) {
    $conn->query("ALTER TABLE `business_applications` DROP INDEX `uq_ba_active_user`");
}
unset($_drop_chk);

// -- Load existing application -------------------------------------------------
$app_stmt = $conn->prepare("SELECT * FROM business_applications WHERE user_id = ? ORDER BY created_at DESC LIMIT 1");
$app_stmt->bind_param("i", $user_id);
$app_stmt->execute();
$existing_app = $app_stmt->get_result()->fetch_assoc();
$locked_statuses = ['pending', 'under_review', 'approved'];
$is_locked = $existing_app && in_array($existing_app['status'], $locked_statuses, true);

// For approved certified businesses, fetch the provisioned halal certificate
$provisioned_cert = null;
if ($existing_app && $existing_app['status'] === 'approved') {
    $cert_stmt = $conn->prepare("
        SELECT hc.*
        FROM   halal_certificates hc
        JOIN   halal_restaurants hr ON hr.certificate_id = hc.id
        WHERE  hr.business_owner_id = ?
          AND  hc.status NOT IN ('revoked','expired','suspended')
        ORDER  BY hc.created_at DESC
        LIMIT  1
    ");
    $cert_stmt->bind_param("i", $user_id);
    $cert_stmt->execute();
    $provisioned_cert = $cert_stmt->get_result()->fetch_assoc();
    $cert_stmt->close();
}

// -- Upload helpers ------------------------------------------------------------

/**
 * Maps PHP upload error codes to human-readable messages for error logging.
 */
function _uploadErrorMsg(int $code): string {
    $msgs = [
        UPLOAD_ERR_INI_SIZE   => 'File exceeds upload_max_filesize in php.ini',
        UPLOAD_ERR_FORM_SIZE  => 'File exceeds MAX_FILE_SIZE in form',
        UPLOAD_ERR_PARTIAL    => 'File was only partially uploaded',
        UPLOAD_ERR_NO_FILE    => 'No file was uploaded',
        UPLOAD_ERR_NO_TMP_DIR => 'Missing temporary folder',
        UPLOAD_ERR_CANT_WRITE => 'Failed to write file to disk',
        UPLOAD_ERR_EXTENSION  => 'Upload stopped by PHP extension',
    ];
    return $msgs[$code] ?? "Unknown upload error (code $code)";
}

function uploadBranchDoc(array $file, string $doc_type, int $bi): array {
    $ok_ext   = ['pdf','doc','docx','jpg','jpeg','png'];
    $ok_mimes = ['application/pdf','application/msword',
        'application/vnd.openxmlformats-officedocument.wordprocessingml.document',
        'image/jpeg','image/png'];
    $label = "Branch " . ($bi + 1) . " -- " . ucwords(str_replace('_', ' ', $doc_type));

    // Guard: no file submitted
    if ($file['error'] === UPLOAD_ERR_NO_FILE) {
        return ['success' => false, 'message' => "No file uploaded for: $label"];
    }

    // Guard: any other PHP upload error
    if ($file['error'] !== UPLOAD_ERR_OK) {
        $err_msg = _uploadErrorMsg($file['error']);
        error_log("[apply_business] Upload error for $label: $err_msg (code {$file['error']})");
        return ['success' => false, 'message' => "Upload failed for $label: $err_msg"];
    }

    // Guard: size
    if ($file['size'] > MAX_FILE_SIZE) {
        return ['success' => false, 'message' => "File too large (max 20 MB) for: $label"];
    }

    // Guard: extension whitelist
    $ext = strtolower(pathinfo($file['name'], PATHINFO_EXTENSION));
    if (!in_array($ext, $ok_ext, true)) {
        return ['success' => false, 'message' => "File type .$ext not allowed for: $label (use PDF, DOC, DOCX, JPG, PNG)"];
    }

    // Guard: MIME type double-check
    $fi   = finfo_open(FILEINFO_MIME_TYPE);
    $mime = finfo_file($fi, $file['tmp_name']);
    finfo_close($fi);
    if (!in_array($mime, $ok_mimes, true)) {
        error_log("[apply_business] MIME mismatch for $label: detected $mime, ext .$ext");
        return ['success' => false, 'message' => "File content does not match its extension for: $label"];
    }

    // Ensure destination directory exists (suppress race-condition warning —
    // if two requests mkdir simultaneously the second call returns false but
    // the directory already exists, which is fine).
    $dir = UPLOAD_DIR . "business_docs/branch_{$bi}/";
    @mkdir($dir, 0755, true);
    if (!is_dir($dir)) {
        error_log("[apply_business] Cannot create directory: $dir");
        return ['success' => false, 'message' => "Server storage error for: $label — could not create directory"];
    }

    // Sanitised unique filename
    $fn = uniqid($doc_type . '_', true) . '_' . time() . '.' . $ext;

    if (!move_uploaded_file($file['tmp_name'], $dir . $fn)) {
        error_log("[apply_business] move_uploaded_file failed: {$file['tmp_name']} -> {$dir}{$fn}");
        return ['success' => false, 'message' => "Could not save file for: $label — check server write permissions"];
    }

    return [
        'success'       => true,
        'path'          => "business_docs/branch_{$bi}/$fn",
        'original_name' => basename($file['name']),
        'file_size'     => $file['size'],
        'mime_type'     => $mime,
    ];
}

function uploadProductImage(array $file, string $prefix): array {
    $ok_ext   = ['jpg','jpeg','png','webp','gif'];
    $ok_mimes = ['image/jpeg','image/png','image/webp','image/gif'];

    if ($file['error'] !== UPLOAD_ERR_OK) {
        error_log("[apply_business] Product image upload error for $prefix: " . _uploadErrorMsg($file['error']));
        return ['success' => false, 'message' => "Image upload failed for $prefix: " . _uploadErrorMsg($file['error'])];
    }
    if ($file['size'] > MAX_FILE_SIZE) {
        return ['success' => false, 'message' => "Image too large (max 20 MB) for: $prefix"];
    }
    $ext = strtolower(pathinfo($file['name'], PATHINFO_EXTENSION));
    if (!in_array($ext, $ok_ext, true)) {
        return ['success' => false, 'message' => "Invalid image type .$ext for: $prefix"];
    }
    $fi   = finfo_open(FILEINFO_MIME_TYPE);
    $mime = finfo_file($fi, $file['tmp_name']);
    finfo_close($fi);
    if (!in_array($mime, $ok_mimes, true)) {
        error_log("[apply_business] Product image MIME mismatch for $prefix: $mime");
        return ['success' => false, 'message' => "Image content does not match extension for: $prefix"];
    }

    $dir = UPLOAD_DIR . 'products/';
    @mkdir($dir, 0755, true);
    if (!is_dir($dir)) {
        error_log("[apply_business] Cannot create products directory: $dir");
        return ['success' => false, 'message' => "Server storage error for product image: $prefix"];
    }

    $fn = uniqid($prefix . '_', true) . '_' . time() . '.' . $ext;
    if (!move_uploaded_file($file['tmp_name'], $dir . $fn)) {
        error_log("[apply_business] move_uploaded_file failed for product image: $prefix");
        return ['success' => false, 'message' => "Could not save product image for: $prefix"];
    }

    return ['success'=>true,'path'=>"products/$fn",'mime'=>$mime,'size'=>$file['size']];
}

function processBranchRawMaterials(int $bi): array {
    $items = []; $post = $_POST['branches'][$bi]['materials'] ?? [];
    if (empty($post)) return ['items'=>[],'error'=>''];
    $valid = ['certified','non_certified','pending'];
    foreach ($post as $idx => $row) {
        $name  = trim(strip_tags($row['name'] ?? '')); if (!$name) continue;
        $mfr   = trim(strip_tags($row['manufacturer'] ?? ''));
        $stat  = in_array($row['halal_status']??'pending',$valid,true) ? $row['halal_status'] : 'pending';
        $cb    = trim(strip_tags($row['cert_body'] ?? ''));
        $hback = !empty($row['has_back_logo']) ? 1 : 0;
        $fp = ''; $bp = '';
        $ft = $_FILES['branches']['tmp_name'][$bi]['materials'][$idx]['image_front'] ?? null;
        if ($ft && is_uploaded_file($ft)
            && ($fe = $_FILES['branches']['error'][$bi]['materials'][$idx]['image_front'] ?? UPLOAD_ERR_NO_FILE) === UPLOAD_ERR_OK) {
            $r = uploadProductImage(['name'=>$_FILES['branches']['name'][$bi]['materials'][$idx]['image_front'],
                'type'=>$_FILES['branches']['type'][$bi]['materials'][$idx]['image_front'],
                'tmp_name'=>$ft,'error'=>$fe,'size'=>$_FILES['branches']['size'][$bi]['materials'][$idx]['image_front']],
                "b{$bi}_mf{$idx}");
            if (!$r['success']) return ['items'=>[],'error'=>"Branch ".($bi+1)." item ".($idx+1).": ".$r['message']];
            $fp = $r['path'];
        }
        if ($hback) {
            $bt = $_FILES['branches']['tmp_name'][$bi]['materials'][$idx]['image_back'] ?? null;
            if ($bt && is_uploaded_file($bt)
                && ($be = $_FILES['branches']['error'][$bi]['materials'][$idx]['image_back'] ?? UPLOAD_ERR_NO_FILE) === UPLOAD_ERR_OK) {
                $r = uploadProductImage(['name'=>$_FILES['branches']['name'][$bi]['materials'][$idx]['image_back'],
                    'type'=>$_FILES['branches']['type'][$bi]['materials'][$idx]['image_back'],
                    'tmp_name'=>$bt,'error'=>$be,'size'=>$_FILES['branches']['size'][$bi]['materials'][$idx]['image_back']],
                    "b{$bi}_mb{$idx}");
                if (!$r['success']) return ['items'=>[],'error'=>"Branch ".($bi+1)." item ".($idx+1)." back: ".$r['message']];
                $bp = $r['path'];
            }
        }
        $items[] = ['item_name'=>$name,'manufacturer'=>$mfr,'halal_status'=>$stat,
            'certification_body'=>$cb,'image_front_path'=>$fp,'image_back_path'=>$bp,
            'has_back_logo'=>$hback,'sort_order'=>(int)$idx];
    }
    return ['items'=>$items,'error'=>''];
}

// -- POST handler --------------------------------------------------------------
if ($_SERVER['REQUEST_METHOD'] === 'POST' && !$is_locked) {
    // Guard: post_max_size exceeded causes $_POST and $_FILES to be empty.
    // PHP sets Content-Length header but empties the superglobals silently.
    if (empty($_POST) && isset($_SERVER['CONTENT_LENGTH']) && (int)$_SERVER['CONTENT_LENGTH'] > 0) {
        $max = ini_get('post_max_size');
        $error = "Upload failed: the total form size exceeds the server limit (post_max_size = $max). "
               . "Try uploading fewer or smaller files.";
    }

    // Guard: max_input_vars truncation — if num_branches posted but branches
    // array is missing, the POST was silently clipped.
    if (!$error && isset($_POST['num_branches']) && (int)$_POST['num_branches'] > 0
        && empty($_POST['branches'])) {
        $max_vars = ini_get('max_input_vars');
        $error = "Upload failed: the form exceeded the server's input variable limit "
               . "(max_input_vars = $max_vars). Try reducing the number of raw-material rows "
               . "or contact your administrator to raise this limit.";
    }

    // Guard: max_file_uploads — count submitted file slots vs PHP limit.
    if (!$error && !empty($_FILES)) {
        $file_limit   = (int)ini_get('max_file_uploads');
        $files_count  = 0;
        // array_walk_recursive requires a real variable, not a temporary expression.
        $branch_names = $_FILES['branches']['name'] ?? [];
        array_walk_recursive($branch_names, function($v) use (&$files_count) {
            if ($v !== '') $files_count++;
        });
        if ($files_count > $file_limit) {
            $error = "Upload failed: you submitted $files_count files but the server allows "
                   . "a maximum of $file_limit per request (max_file_uploads). "
                   . "Please reduce the number of branches or file attachments.";
        }
    }

    // Development debug: log $_FILES structure on every POST to help trace upload failures.
    // Remove or gate behind a debug constant before going to production.
    error_log('[apply_business] POST received. $_FILES keys: ' . implode(', ', array_keys($_FILES)));
    if (!empty($_FILES['branches'])) {
        error_log('[apply_business] $_FILES[branches][name]: ' . json_encode($_FILES['branches']['name']));
        error_log('[apply_business] $_FILES[branches][error]: ' . json_encode($_FILES['branches']['error']));
    }
    if (isset($_POST['num_branches'])) {
        error_log('[apply_business] num_branches POST value: ' . $_POST['num_branches']);
    }
    $cert_path    = trim(strip_tags($_POST['certification_path'] ?? ''));
    $biz_name     = trim(strip_tags($_POST['business_name'] ?? ''));
    $biz_desc     = trim(strip_tags($_POST['business_description'] ?? ''));
    $owner_name   = trim(strip_tags($_POST['owner_name'] ?? ''));
    $owner_age    = (int)($_POST['owner_age'] ?? 0);
    $owner_rel    = trim(strip_tags($_POST['owner_religion'] ?? ''));
    $num_branches = max(1, min(10, (int)($_POST['num_branches'] ?? 1)));
    $branches_post = $_POST['branches'] ?? [];
    $valid_types  = ['restaurant','food_stall','karenderia','cafe','fast_food','food_truck','bakery','catering'];

    // Capture halal certificate expiry for certified businesses (from main branch)
    $halal_cert_expiry = null;
    if ($cert_path === 'certified') {
        $halal_cert_expiry = trim(strip_tags($_POST['halal_cert_expiry'] ?? ''));
        // Validate it's a valid future date
        if (empty($halal_cert_expiry) || !preg_match('/^\d{4}-\d{2}-\d{2}$/', $halal_cert_expiry)) {
            $error = 'Please enter a valid Halal Certificate expiry date.';
        } elseif (strtotime($halal_cert_expiry) < strtotime(date('Y-m-d'))) {
            $error = 'Halal Certificate expiry date must be in the future.';
        }
    }

    if (!$error && !in_array($cert_path, ['certified','not_certified'], true))
        $error = 'Please answer the certification question.';
    elseif (empty($biz_desc))
        $error = 'Business description is required.';
    elseif (empty($owner_name) || $owner_age < 18 || empty($owner_rel))
        $error = 'Please complete all Owner Profile fields (minimum age 18).';
    elseif (empty($branches_post) || !is_array($branches_post))
        $error = 'Branch information is missing.';
    else {
        $branch_data  = [];
        $upload_error = '';

        for ($bi = 0; $bi < $num_branches && !$upload_error; $bi++) {
            $b      = $branches_post[$bi] ?? [];
            $btype  = trim(strip_tags($b['type'] ?? ''));
            $bcert  = trim(strip_tags($b['cert_status'] ?? ''));
            $breg   = trim(strip_tags($b['address_region'] ?? ''));
            $bprov  = trim(strip_tags($b['address_province'] ?? ''));
            $bcity  = trim(strip_tags($b['address_city'] ?? ''));
            $bbrgy  = trim(strip_tags($b['address_barangay'] ?? ''));
            $bstr   = trim(strip_tags($b['address_street'] ?? ''));
            $bzip   = trim(strip_tags($b['address_zip'] ?? ''));
            $lbl    = 'Branch ' . ($bi+1) . ($bi===0?' (Main)':'');

            if (!in_array($btype, $valid_types, true)) { $upload_error="$lbl: Select a branch type."; break; }
            if (!in_array($bcert, ['certified','not_certified'], true)) { $upload_error="$lbl: Select Halal status."; break; }
            if (empty($bstr)||empty($bcity)||empty($bprov)||empty($bbrgy)) { $upload_error="$lbl: Complete the full address."; break; }

            // Required docs
            $req = $bcert==='certified'
                ? ['bir_notice','halal_certificate']
                : ['bir_notice','business_permit','mayors_barangay_permit','sanitary_permit','lto_permit'];

            $buploads = [];
            foreach ($req as $slot) {
                $fname = $_FILES['branches']['name'][$bi][$slot] ?? '';
                if (empty($fname)) { $upload_error="$lbl: Upload required - ".ucwords(str_replace('_',' ',$slot))."."; break 2; }
                $f = ['name'=>$fname,'type'=>$_FILES['branches']['type'][$bi][$slot],
                      'tmp_name'=>$_FILES['branches']['tmp_name'][$bi][$slot],
                      'error'=>$_FILES['branches']['error'][$bi][$slot],
                      'size'=>$_FILES['branches']['size'][$bi][$slot]];
                $r = uploadBranchDoc($f, $slot, $bi);
                if (!$r['success']) { $upload_error=$r['message']; break 2; }
                $buploads[$slot] = $r;
            }

            // Raw materials (not_certified only)
            $rm_mode    = trim(strip_tags($b['raw_material_mode'] ?? ''));
            $rm_inherit = ($bi > 0) && !empty($b['inherit_raw_materials']);
            $rm_items   = [];

            if ($bcert === 'not_certified' && !$rm_inherit) {
                if ($rm_mode === 'upload') {
                    $rfn = $_FILES['branches']['name'][$bi]['raw_material_list_file'] ?? '';
                    if (empty($rfn)) { $upload_error="$lbl: Upload Raw Material List file."; break; }
                    $f = ['name'=>$rfn,'type'=>$_FILES['branches']['type'][$bi]['raw_material_list_file'],
                          'tmp_name'=>$_FILES['branches']['tmp_name'][$bi]['raw_material_list_file'],
                          'error'=>$_FILES['branches']['error'][$bi]['raw_material_list_file'],
                          'size'=>$_FILES['branches']['size'][$bi]['raw_material_list_file']];
                    $r = uploadBranchDoc($f, 'raw_material_list_file', $bi);
                    if (!$r['success']) { $upload_error=$r['message']; break; }
                    $buploads['raw_material_list_file'] = $r;
                } elseif ($rm_mode === 'builder') {
                    $rm = processBranchRawMaterials($bi);
                    if ($rm['error']) { $upload_error=$rm['error']; break; }
                    if (empty($rm['items'])) { $upload_error="$lbl: Add at least one raw material item."; break; }
                    $rm_items = $rm['items'];
                } else {
                    $upload_error="$lbl: Choose how to provide the Raw Material List."; break;
                }
            }

            $branch_data[$bi] = ['btype'=>$btype,'bcert'=>$bcert,'breg'=>$breg,'bprov'=>$bprov,
                'bcity'=>$bcity,'bbrgy'=>$bbrgy,'bstr'=>$bstr,'bzip'=>$bzip,
                'is_main'=>($bi===0)?1:0,'inherit'=>$rm_inherit?1:0,
                'uploads'=>$buploads,'rm_items'=>$rm_items];
        }

        if ($upload_error) {
            $error = $upload_error;
        } else {
            // Capture halal certificate path from main branch (first branch) for certified businesses
            $halal_cert_path = null;
            if ($cert_path === 'certified' && isset($branch_data[0]['uploads']['halal_certificate'])) {
                $halal_cert_path = $branch_data[0]['uploads']['halal_certificate']['path'];
            }

            $conn->begin_transaction();
            try {
                $ins = $conn->prepare("INSERT INTO business_applications
                    (user_id,certification_path,business_name,business_description,owner_name,owner_age,owner_religion,
                     halal_certificate_path,halal_certificate_expiry,status)
                    VALUES (?,?,?,?,?,?,?,?,?,'pending')");
                $ins->bind_param("issssisss", $user_id,$cert_path,$biz_name,$biz_desc,$owner_name,$owner_age,$owner_rel,
                    $halal_cert_path,$halal_cert_expiry);
                $ins->execute();
                $app_id = $conn->insert_id;

                $bb  = $conn->prepare("INSERT INTO business_branches
                    (application_id,branch_index,is_main,branch_type,cert_status,
                     address_region,address_province,address_city,address_barangay,address_street,address_zip,inherits_raw_materials)
                    VALUES (?,?,?,?,?,?,?,?,?,?,?,?)");
                $doc = $conn->prepare("INSERT INTO branch_documents
                    (branch_id,application_id,doc_type,original_name,file_path,file_size,mime_type)
                    VALUES (?,?,?,?,?,?,?)");
                $rmi = $conn->prepare("INSERT INTO raw_material_items
                    (application_id,branch_id,item_name,manufacturer,halal_status,certification_body,
                     image_front_path,image_back_path,has_back_logo,sort_order)
                    VALUES (?,?,?,?,?,?,?,?,?,?)");

                $main_bid = null;
                foreach ($branch_data as $bi => $bd) {
                    $bb->bind_param("iiissssssssi",
                        $app_id,$bi,$bd['is_main'],$bd['btype'],$bd['bcert'],
                        $bd['breg'],$bd['bprov'],$bd['bcity'],$bd['bbrgy'],$bd['bstr'],$bd['bzip'],$bd['inherit']);
                    $bb->execute();
                    $bid = $conn->insert_id;
                    if ($bi===0) $main_bid = $bid;

                    foreach ($bd['uploads'] as $dt => $u) {
                        $doc->bind_param("iisssis", $bid,$app_id,$dt,$u['original_name'],$u['path'],$u['file_size'],$u['mime_type']);
                        $doc->execute();
                    }

                    $eff_bid = $bd['inherit'] ? $main_bid : $bid;
                    foreach ($bd['rm_items'] as $it) {
                        $rmi->bind_param("iissssssii",
                            $app_id,$eff_bid,$it['item_name'],$it['manufacturer'],$it['halal_status'],
                            $it['certification_body'],$it['image_front_path'],$it['image_back_path'],
                            $it['has_back_logo'],$it['sort_order']);
                        $rmi->execute();
                    }
                }

                $adm = $conn->query("SELECT id FROM users WHERE is_admin=1 AND is_active=1");
                while ($a = $adm->fetch_assoc())
                    createNotification($conn,$a['id'],'New Business Application',
                        $_SESSION['full_name']." submitted a business application ($num_branches branch(es)).",
                        'action_required', BASE_URL.'dashboard/admin/business_applications.php');

                logActivity($conn,$user_id,'Business Application Submitted',
                    "Submitted ($num_branches branch(es), path: $cert_path)",'business_registration');

                $conn->commit();

                // PRG: store success in session, redirect so browser refresh
                // cannot re-POST the form and create a duplicate application.
                $_SESSION['flash_message'] = 'Application submitted successfully! You will be notified once reviewed.';
                $_SESSION['flash_type']    = 'success';
                header('Location: ' . BASE_URL . 'dashboard/customer/apply_business.php');
                exit();

            } catch (Throwable $e) {
                $conn->rollback();
                // Log the real error server-side; show a generic message to users.
                error_log('[apply_business] Submission failed for user_id=' . $user_id . ': ' . $e->getMessage());
                $error = 'Something went wrong while saving your application. Please try again.';
            }
        }
    }
}

require_once __DIR__ . '/../../includes/header.php';
?>

<?php if ($error): ?>
<div class="alert alert-danger" style="margin-bottom:20px;">
    <i class="fas fa-exclamation-circle"></i>
    <span><?= htmlspecialchars($error) ?></span>
    <button class="close-alert" onclick="this.parentElement.remove()"><i class="fas fa-times"></i></button>
</div>
<?php endif; ?>
<?php if ($success): ?>
<div class="alert alert-success" style="margin-bottom:20px;">
    <i class="fas fa-check-circle"></i>
    <span><?= htmlspecialchars($success) ?></span>
</div>
<?php endif; ?>

<?php if ($is_locked && $existing_app):
    $smap = [
        'pending'      =>['label'=>'Pending Review',            'icon'=>'fas fa-hourglass-half','color'=>'#d97706','bg'=>'#fef9c3','border'=>'#fde68a'],
        'under_review' =>['label'=>'Under Review',              'icon'=>'fas fa-search',        'color'=>'#2563eb','bg'=>'#eff6ff','border'=>'#bfdbfe'],
        'approved'     =>['label'=>'Approved',                  'icon'=>'fas fa-check-circle',  'color'=>'#059669','bg'=>'#ecfdf5','border'=>'#bbf7d0'],
        'rejected'     =>['label'=>'Not Approved',              'icon'=>'fas fa-times-circle',  'color'=>'#dc2626','bg'=>'#fef2f2','border'=>'#fecaca'],
        'returned'     =>['label'=>'Returned - Revise & Resubmit','icon'=>'fas fa-undo',        'color'=>'#d97706','bg'=>'#fff7ed','border'=>'#fed7aa'],
    ];
    $s = $smap[$existing_app['status']] ?? $smap['pending'];
?>
<div class="card" style="max-width:680px;margin:0 auto;">
    <div class="card-body" style="text-align:center;padding:48px 40px;">
        <div style="width:72px;height:72px;border-radius:50%;background:<?=$s['bg']?>;border:2px solid <?=$s['border']?>;
                    display:flex;align-items:center;justify-content:center;margin:0 auto 20px;">
            <i class="<?=$s['icon']?>" style="font-size:1.8rem;color:<?=$s['color']?>;"></i>
        </div>
        <h3 style="font-size:1.25rem;font-weight:700;color:var(--neutral-800);margin-bottom:8px;">
            Application <?= htmlspecialchars($s['label']) ?>
        </h3>
        <p style="color:var(--neutral-500);font-size:0.92rem;margin-bottom:24px;">
            Submitted <?= formatDate($existing_app['created_at']) ?>.
            <?php if (in_array($existing_app['status'],['returned','rejected'],true)): ?>Check your notifications, then resubmit.
            <?php elseif ($existing_app['status']==='approved'): ?>Your business account is now active.
            <?php else: ?>You'll receive a notification once reviewed.<?php endif; ?>
        </p>

        <?php if ($existing_app['status'] === 'approved' && $provisioned_cert): ?>
        <!-- ── Halal Certificate Card ─────────────────────────────────── -->
        <div style="background:linear-gradient(135deg,#f0fdf4,#dcfce7);
                    border:2px solid #bbf7d0;border-radius:14px;
                    padding:24px;margin-bottom:24px;text-align:left;">

            <!-- Header row -->
            <div style="display:flex;align-items:center;gap:12px;margin-bottom:18px;">
                <div style="width:46px;height:46px;background:#059669;border-radius:11px;flex-shrink:0;
                            display:flex;align-items:center;justify-content:center;">
                    <i class="fas fa-certificate" style="color:#fff;font-size:1.2rem;"></i>
                </div>
                <div>
                    <div style="font-weight:700;color:#065f46;font-size:1rem;">Halal Certificate Active</div>
                    <div style="font-size:.78rem;color:#059669;">
                        Verified by Halal Development &amp; Inspection Program
                    </div>
                </div>
                <span style="margin-left:auto;background:#059669;color:#fff;font-size:.72rem;
                             font-weight:700;padding:3px 10px;border-radius:20px;">ACTIVE</span>
            </div>

            <!-- Details grid -->
            <div style="display:grid;grid-template-columns:1fr 1fr;gap:10px;margin-bottom:18px;">
                <div style="background:rgba(255,255,255,.7);border-radius:8px;padding:10px 12px;">
                    <div style="font-size:.68rem;font-weight:600;color:#6b7280;
                                text-transform:uppercase;letter-spacing:.05em;margin-bottom:3px;">
                        Certificate No.
                    </div>
                    <div style="font-weight:700;color:#1e293b;font-size:.85rem;font-family:monospace;">
                        <?= htmlspecialchars($provisioned_cert['certificate_number']) ?>
                    </div>
                </div>
                <div style="background:rgba(255,255,255,.7);border-radius:8px;padding:10px 12px;">
                    <div style="font-size:.68rem;font-weight:600;color:#6b7280;
                                text-transform:uppercase;letter-spacing:.05em;margin-bottom:3px;">
                        Certifying Body
                    </div>
                    <div style="font-weight:600;color:#1e293b;font-size:.82rem;">
                        <?= htmlspecialchars($provisioned_cert['certifying_body'] ?? 'HDIP') ?>
                    </div>
                </div>
                <div style="background:rgba(255,255,255,.7);border-radius:8px;padding:10px 12px;">
                    <div style="font-size:.68rem;font-weight:600;color:#6b7280;
                                text-transform:uppercase;letter-spacing:.05em;margin-bottom:3px;">
                        Issue Date
                    </div>
                    <div style="font-weight:600;color:#1e293b;font-size:.85rem;">
                        <?= $provisioned_cert['issue_date']
                            ? date('F j, Y', strtotime($provisioned_cert['issue_date'])) : '—' ?>
                    </div>
                </div>
                <div style="background:rgba(255,255,255,.7);border-radius:8px;padding:10px 12px;">
                    <div style="font-size:.68rem;font-weight:600;color:#6b7280;
                                text-transform:uppercase;letter-spacing:.05em;margin-bottom:3px;">
                        Valid Until
                    </div>
                    <?php
                        $days_left = $provisioned_cert['expiry_date']
                            ? (int)((strtotime($provisioned_cert['expiry_date']) - time()) / 86400)
                            : 9999;
                    ?>
                    <div style="font-weight:700;font-size:.85rem;
                                color:<?= $days_left < 30 ? '#b45309' : '#065f46' ?>;">
                        <?= $provisioned_cert['expiry_date']
                            ? date('F j, Y', strtotime($provisioned_cert['expiry_date'])) : '—' ?>
                        <?php if ($days_left < 30 && $days_left >= 0): ?>
                        <span style="font-size:.7rem;background:#fef3c7;color:#b45309;
                                     padding:2px 7px;border-radius:20px;margin-left:4px;">
                            Expiring soon
                        </span>
                        <?php endif; ?>
                    </div>
                </div>
            </div>

            <!-- Certificate document -->
            <?php
                $cert_file = $provisioned_cert['certificate_file_path'] ?? null;
                $cert_url  = $cert_file ? BASE_URL . 'uploads/' . htmlspecialchars($cert_file) : null;
                $cert_ext  = $cert_file ? strtolower(pathinfo($cert_file, PATHINFO_EXTENSION)) : '';
                $is_image  = in_array($cert_ext, ['jpg','jpeg','png','gif','webp']);
                $is_pdf    = ($cert_ext === 'pdf');
            ?>
            <?php if ($cert_url): ?>
            <div style="border:1px solid #a7f3d0;border-radius:10px;overflow:hidden;margin-bottom:6px;">
                <!-- toolbar -->
                <div style="background:rgba(255,255,255,.8);padding:9px 14px;
                            border-bottom:1px solid #a7f3d0;
                            display:flex;align-items:center;justify-content:space-between;">
                    <span style="font-size:.8rem;font-weight:600;color:#065f46;">
                        <i class="fas fa-file-<?= $is_image ? 'image' : ($is_pdf ? 'pdf' : 'alt') ?>"
                           style="margin-right:5px;color:<?= $is_pdf ? '#dc2626' : '#0891b2' ?>;"></i>
                        Certificate Document
                    </span>
                    <a href="<?= $cert_url ?>" target="_blank" download
                       style="font-size:.78rem;color:#065f46;font-weight:700;text-decoration:none;
                              background:rgba(5,150,105,.12);padding:4px 10px;border-radius:6px;">
                        <i class="fas fa-download"></i> Download
                    </a>
                </div>
                <!-- viewer -->
                <?php if ($is_image): ?>
                <div style="text-align:center;padding:14px;background:#fff;">
                    <img src="<?= $cert_url ?>" alt="Halal Certificate"
                         style="max-width:100%;max-height:420px;object-fit:contain;
                                border-radius:6px;cursor:pointer;"
                         onclick="document.getElementById('certImgModal').style.display='flex'">
                    <p style="font-size:.72rem;color:#94a3b8;margin-top:6px;">
                        Click image to enlarge
                    </p>
                </div>
                <?php elseif ($is_pdf): ?>
                <div style="height:440px;">
                    <iframe src="<?= $cert_url ?>" width="100%" height="100%"
                            style="border:none;" title="Halal Certificate PDF">
                        <p style="padding:20px;text-align:center;color:#64748b;">
                            Cannot display PDF inline.
                            <a href="<?= $cert_url ?>" target="_blank" style="color:#059669;">
                                Open in new tab
                            </a>
                        </p>
                    </iframe>
                </div>
                <?php else: ?>
                <div style="padding:20px;text-align:center;">
                    <a href="<?= $cert_url ?>" target="_blank"
                       style="background:#059669;color:#fff;padding:10px 20px;
                              border-radius:8px;text-decoration:none;font-weight:600;font-size:.88rem;">
                        <i class="fas fa-external-link-alt"></i> Open Certificate
                    </a>
                </div>
                <?php endif; ?>
            </div>
            <?php endif; ?>
        </div>

        <!-- Fullscreen image lightbox (image certs only) -->
        <?php if ($cert_url && $is_image): ?>
        <div id="certImgModal"
             onclick="this.style.display='none'"
             style="display:none;position:fixed;inset:0;background:rgba(0,0,0,.85);
                    z-index:9999;align-items:center;justify-content:center;cursor:zoom-out;">
            <img src="<?= $cert_url ?>" alt="Halal Certificate"
                 style="max-width:92vw;max-height:92vh;object-fit:contain;border-radius:8px;">
        </div>
        <?php endif; ?>

        <?php endif; // provisioned_cert ?>

        <?php if (in_array($existing_app['status'],['returned','rejected'],true)): ?>
            <a href="?resubmit=1" class="btn btn-primary"><i class="fas fa-redo"></i> Resubmit Application</a>
        <?php elseif ($existing_app['status']==='approved'): ?>
            <a href="<?= BASE_URL ?>dashboard/customer/open_business_dashboard.php" class="btn btn-success">
                <i class="fas fa-tachometer-alt"></i> Go to Business Dashboard
            </a>
        <?php endif; ?>
        <a href="<?= BASE_URL ?>dashboard/customer/" class="btn btn-outline" style="margin-left:8px;">
            <i class="fas fa-arrow-left"></i> Back to Dashboard
        </a>
    </div>
</div>
<?php if (isset($_GET['resubmit']) && in_array($existing_app['status'],['returned','rejected'],true)): $is_locked=false; endif; ?>
<?php endif; ?>

<?php if (!$is_locked): ?>
<!-- Page hero -->
<div style="background:linear-gradient(135deg,#064e3b 0%,#065f46 55%,#047857 100%);
            border-radius:var(--radius-xl);padding:28px 32px;margin-bottom:28px;
            position:relative;overflow:hidden;">
    <div style="position:absolute;right:-40px;top:-40px;width:180px;height:180px;border-radius:50%;background:rgba(255,255,255,.05);pointer-events:none;"></div>
    <div style="position:relative;display:flex;align-items:center;gap:16px;flex-wrap:wrap;">
        <div style="width:52px;height:52px;border-radius:14px;background:rgba(255,255,255,.15);
                    display:flex;align-items:center;justify-content:center;flex-shrink:0;">
            <i class="fas fa-store" style="color:#fff;font-size:1.3rem;"></i>
        </div>
        <div>
            <h1 style="font-family:var(--font-display);font-size:1.4rem;font-weight:800;color:#fff;margin:0 0 5px;">
                Register as a Business
            </h1>
            <p style="color:rgba(255,255,255,.7);font-size:.88rem;margin:0;">
                Complete the steps below to apply for halal certification and list your restaurant.
            </p>
        </div>
    </div>
</div>
<!-- Progress bar -->
<div style="display:flex;align-items:center;margin-bottom:28px;max-width:760px;margin-left:auto;margin-right:auto;">
<?php foreach(['Certification','Owner Profile','Branches','Branch Details','Review'] as $i=>$lbl): $n=$i+1; ?>
<div style="display:flex;align-items:center;flex:1;">
    <?php if($n>1): ?><div style="flex:1;height:2px;background:var(--neutral-200);" class="step-line" id="step-line-<?=$n?>"></div><?php endif; ?>
    <div style="display:flex;flex-direction:column;align-items:center;">
        <div id="step-circle-<?=$n?>" style="width:30px;height:30px;border-radius:50%;border:2px solid <?=$n===1?'var(--primary-600)':'var(--neutral-300)'?>;background:<?=$n===1?'var(--primary-600)':'#fff'?>;color:<?=$n===1?'#fff':'var(--neutral-400)'?>;display:flex;align-items:center;justify-content:center;font-weight:700;font-size:.8rem;transition:all .25s;"><?=$n?></div>
        <div id="step-label-<?=$n?>" style="font-size:.64rem;color:<?=$n===1?'var(--primary-600)':'var(--neutral-400)'?>;margin-top:3px;font-weight:600;white-space:nowrap;"><?=$lbl?></div>
    </div>
</div>
<?php endforeach; ?>
</div>

<form id="bizForm" method="POST" action="<?= BASE_URL ?>dashboard/customer/apply_business.php" enctype="multipart/form-data" novalidate>
<input type="hidden" name="num_branches" id="hidden_num_branches" value="1">

<!-- Step 1: Certification -->
<div class="biz-step-panel" id="panel-1">
<div class="card" style="max-width:700px;margin:0 auto;">
    <div class="card-header"><h3><i class="fas fa-question-circle" style="color:var(--primary-600);margin-right:8px;"></i>Certification Status</h3></div>
    <div class="card-body" style="padding:32px;">
        <p style="font-size:1rem;color:var(--neutral-600);margin-bottom:28px;text-align:center;">Is your business <strong>already Halal-certified</strong>?</p>
        <div style="display:grid;grid-template-columns:1fr 1fr;gap:16px;max-width:460px;margin:0 auto 24px;">
            <label class="cert-choice" id="cert-yes" style="border:2px solid var(--neutral-200);border-radius:14px;padding:24px 16px;cursor:pointer;text-align:center;transition:all .2s;">
                <input type="radio" name="certification_path" value="certified" style="display:none;" onchange="selectCertPath(this)">
                <i class="fas fa-certificate" style="font-size:2rem;color:var(--primary-500);margin-bottom:10px;display:block;"></i>
                <strong style="font-size:.95rem;color:var(--neutral-800);">Yes - Halal-certified</strong>
                <p style="font-size:.78rem;color:var(--neutral-400);margin-top:6px;margin-bottom:0;">I have a valid Halal certificate</p>
            </label>
            <label class="cert-choice" id="cert-no" style="border:2px solid var(--neutral-200);border-radius:14px;padding:24px 16px;cursor:pointer;text-align:center;transition:all .2s;">
                <input type="radio" name="certification_path" value="not_certified" style="display:none;" onchange="selectCertPath(this)">
                <i class="fas fa-file-alt" style="font-size:2rem;color:var(--accent-500);margin-bottom:10px;display:block;"></i>
                <strong style="font-size:.95rem;color:var(--neutral-800);">No - Not yet certified</strong>
                <p style="font-size:.78rem;color:var(--neutral-400);margin-top:6px;margin-bottom:0;">I want to apply for certification</p>
            </label>
        </div>
        <p style="font-size:.78rem;color:var(--neutral-400);text-align:center;"><i class="fas fa-info-circle"></i> Click a card to continue automatically.</p>
    </div>
</div>
</div>

<!-- Step 2: Owner Profile -->
<div class="biz-step-panel" id="panel-2" style="display:none;">
<div class="card" style="max-width:700px;margin:0 auto;">
    <div class="card-header"><h3><i class="fas fa-user-tie" style="color:var(--primary-600);margin-right:8px;"></i>Business & Owner Profile</h3></div>
    <div class="card-body" style="padding:32px;">
        <div class="form-group" style="margin-bottom:18px;">
            <label class="form-label">Business / Trade Name <span style="color:var(--neutral-400);font-weight:400;">(optional)</span></label>
            <input type="text" name="business_name" class="form-control" placeholder="e.g. Al-Barakah Food House" maxlength="255">
        </div>
        <div class="form-group" style="margin-bottom:18px;">
            <label class="form-label">Business Description <span style="color:red;">*</span></label>
            <textarea name="business_description" class="form-control" rows="4" placeholder="Describe your business, products, and services-" maxlength="2000"></textarea>
        </div>
        <hr style="margin:18px 0;border-color:var(--neutral-100);">
        <div class="form-group" style="margin-bottom:14px;">
            <label class="form-label">Owner Full Name <span style="color:red;">*</span></label>
            <input type="text" name="owner_name" class="form-control" maxlength="255" value="<?= htmlspecialchars($_SESSION['full_name'] ?? '') ?>">
        </div>
        <div style="display:grid;grid-template-columns:1fr 1fr;gap:14px;margin-bottom:22px;">
            <div class="form-group">
                <label class="form-label">Age <span style="color:red;">*</span></label>
                <input type="number" name="owner_age" class="form-control" min="18" max="120" placeholder="e.g. 35">
            </div>
            <div class="form-group">
                <label class="form-label">Religion <span style="color:red;">*</span></label>
                <select name="owner_religion" class="form-control">
                    <option value="">- Select -</option>
                    <option value="Muslim">Muslim</option>
                    <option value="Non-Muslim">Non-Muslim</option>
                </select>
            </div>
        </div>
        <div style="display:flex;justify-content:space-between;">
            <button type="button" class="btn btn-outline" onclick="goToStep(1)"><i class="fas fa-arrow-left"></i> Back</button>
            <button type="button" class="btn btn-primary" onclick="validateStep2()">Next <i class="fas fa-arrow-right"></i></button>
        </div>
    </div>
</div>
</div>

<!-- Step 3: Branch Count -->
<div class="biz-step-panel" id="panel-3" style="display:none;">
<div class="card" style="max-width:700px;margin:0 auto;">
    <div class="card-header"><h3><i class="fas fa-code-branch" style="color:var(--primary-600);margin-right:8px;"></i>Business Locations / Branches</h3></div>
    <div class="card-body" style="padding:32px;">
        <p style="color:var(--neutral-600);font-size:.95rem;margin-bottom:20px;">How many business locations or branches are you registering?</p>
        <div class="form-group" style="margin-bottom:20px;max-width:240px;">
            <label class="form-label">Number of Branches <span style="color:red;">*</span></label>
            <select id="num_branches_select" class="form-control" style="font-size:1rem;">
                <?php for($i=1;$i<=10;$i++): ?>
                <option value="<?=$i?>"><?=$i?><?=$i===1?' (Single location)':''?></option>
                <?php endfor; ?>
            </select>
        </div>
        <div style="background:var(--neutral-50);border:1px solid var(--neutral-200);border-radius:10px;padding:12px 16px;font-size:.83rem;color:var(--neutral-600);margin-bottom:24px;">
            <i class="fas fa-info-circle" style="color:var(--primary-500);margin-right:6px;"></i>
            Each branch has its own location and document checklist. Branches 2+ may inherit the Main Branch's Raw Material List.
        </div>
        <div style="display:flex;justify-content:space-between;">
            <button type="button" class="btn btn-outline" onclick="goToStep(2)"><i class="fas fa-arrow-left"></i> Back</button>
            <button type="button" class="btn btn-primary" onclick="confirmBranchCount()">Continue <i class="fas fa-arrow-right"></i></button>
        </div>
    </div>
</div>
</div>

<!-- Step 4: Branch Tabs (JS-generated) -->
<div class="biz-step-panel" id="panel-4" style="display:none;">
<div style="max-width:760px;margin:0 auto;">
    <div id="branch-tab-bar" style="display:flex;gap:4px;flex-wrap:wrap;border-bottom:2px solid var(--neutral-200);margin-bottom:0;"></div>
    <div id="branch-panels-container"></div>
    <div style="display:flex;justify-content:space-between;margin-top:20px;">
        <button type="button" class="btn btn-outline" onclick="goToStep(3)"><i class="fas fa-arrow-left"></i> Back</button>
        <button type="button" class="btn btn-primary" onclick="validateAllBranches()">Review &amp; Submit <i class="fas fa-arrow-right"></i></button>
    </div>
</div>
</div>

<!-- Step 5: Review & Submit -->
<div class="biz-step-panel" id="panel-5" style="display:none;">
<div class="card" style="max-width:760px;margin:0 auto;">
    <div class="card-header"><h3><i class="fas fa-check-double" style="color:var(--primary-600);margin-right:8px;"></i>Review & Submit</h3></div>
    <div class="card-body" style="padding:32px;">
        <div id="review-summary" style="background:var(--neutral-50);border:1px solid var(--neutral-200);
             border-radius:12px;padding:20px 24px;margin-bottom:22px;font-size:.88rem;color:var(--neutral-700);line-height:1.8;"></div>
        <div style="background:#fef9c3;border:1px solid #fde68a;border-radius:10px;padding:12px 16px;font-size:.83rem;color:#92400e;margin-bottom:22px;">
            <i class="fas fa-exclamation-triangle" style="margin-right:6px;"></i>
            <strong>Reminder:</strong> Ensure all documents are clear and legible before submitting.
        </div>
        <div style="display:flex;justify-content:space-between;">
            <button type="button" class="btn btn-outline" onclick="goToStep(4)"><i class="fas fa-arrow-left"></i> Back</button>
            <button type="submit" class="btn btn-success" id="submitBtn"><i class="fas fa-paper-plane"></i> Submit Application</button>
        </div>
    </div>
</div>
</div>
</form>
<?php endif; ?>

<?php $extra_js = <<<'ENDJS'
<style>
.biz-step-panel{animation:bizSlideIn .28s cubic-bezier(.4,0,.2,1) both}
@keyframes bizSlideIn{from{opacity:0;transform:translateX(28px)}to{opacity:1;transform:translateX(0)}}
.biz-step-panel.slide-left{animation:bizSlideInLeft .28s cubic-bezier(.4,0,.2,1) both}
@keyframes bizSlideInLeft{from{opacity:0;transform:translateX(-28px)}to{opacity:1;transform:translateX(0)}}
@keyframes bizFadeIn{from{opacity:0;transform:translateX(-50%) translateY(10px)}to{opacity:1;transform:translateX(-50%) translateY(0)}}
.cert-choice{transition:border-color .18s,box-shadow .18s,background .18s,transform .12s}
.cert-choice:hover{transform:translateY(-2px)}
.cert-choice.selected{border-color:var(--primary-600)!important;background:#eff6ff!important;box-shadow:0 0 0 3px rgba(37,99,235,.13)!important}
select.form-control:disabled{opacity:.5;cursor:not-allowed}
.file-upload-zone{border:2px dashed var(--neutral-300);border-radius:10px;padding:18px 14px;text-align:center;cursor:pointer;transition:border-color .2s,background .2s;background:var(--neutral-50);user-select:none}
.file-upload-zone:hover,.file-upload-zone.dragover{border-color:var(--primary-500);background:#eff6ff}
.file-upload-zone.has-file{border-color:#059669;background:#f0fdf4}
.file-upload-zone.has-file .zone-text{display:none}
.branch-tab{padding:8px 18px;border:2px solid var(--neutral-200);border-bottom:none;border-radius:8px 8px 0 0;cursor:pointer;font-size:.82rem;font-weight:600;color:var(--neutral-500);background:#fff;transition:all .18s;margin-bottom:-2px}
.branch-tab.active{border-color:var(--primary-600);color:var(--primary-700);background:#eff6ff}
.branch-panel{display:none;border:2px solid var(--primary-200);border-radius:0 8px 8px 8px;padding:24px;background:#fff;margin-bottom:0}
.branch-panel.active{display:block}
.rm-mode-card{transition:border-color .18s,box-shadow .18s,background .18s,transform .12s;border-radius:12px;padding:16px 12px;cursor:pointer;text-align:center}
.rm-mode-card:hover{transform:translateY(-2px)}
.rm-mode-card.rm-selected{border-color:var(--primary-600)!important;background:#eff6ff!important;box-shadow:0 0 0 3px rgba(37,99,235,.13)!important}
.rm-row{border:1px solid var(--neutral-200);border-radius:10px;padding:16px 18px;margin-bottom:12px;background:var(--neutral-50)}
.rm-row-header{display:flex;align-items:center;justify-content:space-between;margin-bottom:12px}
.rm-row-number{font-weight:700;font-size:.82rem;color:var(--primary-700);background:var(--primary-100);border-radius:6px;padding:2px 10px}
.rm-g2{display:grid;grid-template-columns:1fr 1fr;gap:10px;margin-bottom:10px}
.rm-img-zone{border:2px dashed var(--neutral-300);border-radius:8px;padding:12px 8px;text-align:center;cursor:pointer;font-size:.76rem;color:var(--neutral-500);background:#fff;transition:border-color .2s,background .2s}
.rm-img-zone:hover{border-color:var(--primary-400);background:#eff6ff}
.rm-img-zone.has-img{border-color:#059669;background:#f0fdf4;color:#065f46}
@media(max-width:600px){.rm-g2{grid-template-columns:1fr}}
</style>
ENDJS;

// DATA_BASE is emitted here directly so BASE_URL (a PHP constant) is baked in.
// It cannot live inside the single-quoted heredoc above because single-quoted
// heredocs do not interpolate PHP expressions.
$extra_js .= '<script>' . "\n";
$extra_js .= 'var DATA_BASE=' . json_encode(BASE_URL . 'assets/data/') . ';' . "\n";
$extra_js .= <<<'ENDJS_CORE'
// -- Core state ----------------------------------------------------------------
var currentStep  = 1;
var selectedPath = '';
var numBranches  = 1;

var _cache     = _cache || {};
var _regions   = _regions   || null;
var _provinces = _provinces || null;
var _cities    = _cities    || null;
var _barangays = _barangays || null;
var _rmRowIdx  = _rmRowIdx  || {};

async function loadJson(f){
    if(_cache[f]) return _cache[f];
    try{const r=await fetch(DATA_BASE+f);if(!r.ok)throw 0;const d=await r.json();_cache[f]=d;return d;}
    catch(e){console.error('Location data load failed:',DATA_BASE+f,e);return null;}
}
async function preloadLoc(){
    if(_regions && _provinces && _cities && _barangays) return;
    [_regions,_provinces,_cities,_barangays]=await Promise.all([
        loadJson('regions.json'),loadJson('provinces.json'),
        loadJson('cities.json'),loadJson('barangays.json')]);
    if(!_regions) console.error('preloadLoc: regions.json failed. DATA_BASE='+DATA_BASE);
}

// -- Step navigation -----------------------------------------------------------
function goToStep(n){
    const fwd = n > currentStep;
    document.querySelectorAll('.biz-step-panel').forEach(p=>p.style.display='none');
    const panel=document.getElementById('panel-'+n);
    panel.style.display='';
    panel.classList.remove('slide-left');
    void panel.offsetWidth;
    if(!fwd) panel.classList.add('slide-left');
    currentStep=n;
    updateProgress(n);
    window.scrollTo({top:0,behavior:'smooth'});
    if(n===5) buildReviewSummary();
}
function updateProgress(active){
    for(let i=1;i<=5;i++){
        const c=document.getElementById('step-circle-'+i);
        const l=document.getElementById('step-label-'+i);
        const ln=document.getElementById('step-line-'+i);
        if(!c) continue;
        const base='width:30px;height:30px;border-radius:50%;border:2px solid;display:flex;align-items:center;justify-content:center;font-weight:700;font-size:.8rem;transition:all .25s;';
        if(i<active){
            c.style.cssText=base+'background:var(--primary-600);border-color:var(--primary-600);color:#fff;';
            c.innerHTML='<i class="fas fa-check" style="font-size:.65rem;"></i>';
            if(l) l.style.color='var(--primary-600)';
            if(ln) ln.style.background='var(--primary-500)';
        }else if(i===active){
            c.style.cssText=base+'background:var(--primary-600);border-color:var(--primary-600);color:#fff;';
            c.textContent=i;
            if(l) l.style.color='var(--primary-600)';
            if(ln) ln.style.background='var(--primary-500)';
        }else{
            c.style.cssText=base+'background:#fff;border-color:var(--neutral-300);color:var(--neutral-400);';
            c.textContent=i;
            if(l) l.style.color='var(--neutral-400)';
            if(ln) ln.style.background='var(--neutral-200)';
        }
    }
}

// -- Step 1: cert path auto-advance --------------------------------------------
function selectCertPath(radio){
    selectedPath=radio.value;
    document.querySelectorAll('.cert-choice').forEach(e=>e.classList.remove('selected'));
    radio.closest('.cert-choice').classList.add('selected');
    setTimeout(()=>goToStep(2),320);
}

// -- Step 2 validation ---------------------------------------------------------
function validateStep2(){
    const req=['business_description','owner_name','owner_age','owner_religion'];
    for(const n of req){
        const el=document.querySelector('[name="'+n+'"]');
        if(!el||!el.value.trim()){flashError(el);showToast('Please complete all required fields.');return;}
    }
    if((parseInt(document.querySelector('[name="owner_age"]').value)||0)<18){showToast('Owner age must be 18 or older.');return;}
    goToStep(3);
}

// -- Step 3: confirm branch count (full async impl is in $extra_js_d) ----------

// -- Branch tab builder --------------------------------------------------------
function buildBranchTabs(n){
    const tabBar=document.getElementById('branch-tab-bar');
    const container=document.getElementById('branch-panels-container');
    tabBar.innerHTML=''; container.innerHTML='';
    for(let i=0;i<n;i++){
        const tab=document.createElement('button');
        tab.type='button'; tab.className='branch-tab'+(i===0?' active':'');
        tab.id='btab-'+i; tab.dataset.bi=i;
        tab.textContent=(i===0?'Branch 1 (Main)':'Branch '+(i+1));
        tab.onclick=()=>switchBranchTab(i);
        tabBar.appendChild(tab);
        const panel=document.createElement('div');
        panel.className='branch-panel'+(i===0?' active':'');
        panel.id='bpanel-'+i;
        panel.innerHTML=buildBranchHTML(i);
        container.appendChild(panel);
        _rmRowIdx[i]=0;
        initBranchLocation(i);
        renderBranchDocs(i);
    }
}
function switchBranchTab(bi){
    document.querySelectorAll('.branch-tab').forEach(t=>t.classList.remove('active'));
    document.querySelectorAll('.branch-panel').forEach(p=>p.classList.remove('active'));
    document.getElementById('btab-'+bi).classList.add('active');
    document.getElementById('bpanel-'+bi).classList.add('active');
}
</script>
ENDJS_CORE;

echo $extra_js;

// ── Branch HTML builder + docs/RM renderer + location cascades ────────────────
$extra_js_b = <<<'ENDJSB'
<script>
function buildBranchHTML(bi){
    const isMain=bi===0;
    const warn=isMain?`<div style="background:linear-gradient(135deg,#fff7ed,#fef9c3);border:2px solid #fbbf24;border-radius:12px;padding:14px 18px;margin-bottom:18px;display:flex;gap:12px;align-items:flex-start;"><div style="flex-shrink:0;width:34px;height:34px;border-radius:8px;background:#f59e0b;display:flex;align-items:center;justify-content:center;"><i class="fas fa-exclamation-triangle" style="color:#fff;font-size:.9rem;"></i></div><div><p style="font-weight:700;color:#92400e;margin:0 0 2px;font-size:.88rem;">Operational Readiness Requirement</p><p style="color:#b45309;margin:0;font-size:.8rem;line-height:1.5;">Business must be <strong>actively operational for at least 3 months</strong> before submitting.</p></div></div>`:'';
    const certVal=selectedPath==='certified'?'certified':'not_certified';
    const inheritRow=isMain?'':(`<div style="background:#f0fdf4;border:1px solid #bbf7d0;border-radius:10px;padding:11px 14px;margin-bottom:14px;"><label style="display:flex;align-items:center;gap:9px;cursor:pointer;font-size:.85rem;font-weight:600;color:var(--neutral-700);"><input type="checkbox" name="branches[${bi}][inherit_raw_materials]" value="1" onchange="toggleInheritRM(this,${bi})" style="width:15px;height:15px;accent-color:var(--primary-600);"><span>This branch uses the <strong>same Raw Material List</strong> as Branch 1 (Main Branch)</span></label></div>`);
    return `${warn}
    <div class="rm-g2" style="margin-bottom:14px;">
        <div class="form-group"><label class="form-label" style="font-size:.82rem;">Branch Type <span style="color:red;">*</span></label>
        <select name="branches[${bi}][type]" class="form-control"><option value="">-- Select --</option><option value="restaurant">Restaurant</option><option value="food_stall">Food Stall</option><option value="karenderia">Karenderia</option><option value="cafe">Cafe</option><option value="fast_food">Fast Food</option><option value="food_truck">Food Truck</option><option value="bakery">Bakery</option><option value="catering">Catering</option></select></div>
        <div class="form-group"><label class="form-label" style="font-size:.82rem;">Halal-Certified? <span style="color:red;">*</span></label>
        <select name="branches[${bi}][cert_status]" class="form-control" onchange="renderBranchDocs(${bi})"><option value="${certVal}" selected>${certVal==='certified'?'Yes -- Halal-certified':'No -- Not certified'}</option>${certVal==='certified'?'<option value="not_certified">No -- Not certified</option>':'<option value="certified">Yes -- Halal-certified</option>'}</select></div>
    </div>
    <hr style="margin:12px 0;border-color:var(--neutral-100);">
    <h5 style="font-size:.83rem;font-weight:700;color:var(--neutral-700);margin-bottom:10px;"><i class="fas fa-map-marker-alt" style="color:var(--primary-500);margin-right:5px;"></i>Branch Address</h5>
    <div class="form-group" style="margin-bottom:10px;"><label class="form-label" style="font-size:.78rem;">Region <span style="color:red;">*</span></label>
    <select id="b${bi}_region" name="branches[${bi}][address_region]" class="form-control" onchange="onBranchRegion(${bi})"><option value="">-- Select Region --</option></select></div>
    <div class="rm-g2" style="margin-bottom:10px;">
        <div class="form-group"><label class="form-label" style="font-size:.78rem;">Province <span style="color:red;">*</span></label><select id="b${bi}_province" name="branches[${bi}][address_province]" class="form-control" disabled onchange="onBranchProvince(${bi})"><option value="">-- Select Province --</option></select></div>
        <div class="form-group"><label class="form-label" style="font-size:.78rem;">City / Municipality <span style="color:red;">*</span></label><select id="b${bi}_city" name="branches[${bi}][address_city]" class="form-control" disabled onchange="onBranchCity(${bi})"><option value="">-- Select City --</option></select></div>
    </div>
    <div class="rm-g2" style="margin-bottom:10px;">
        <div class="form-group"><label class="form-label" style="font-size:.78rem;">Barangay <span style="color:red;">*</span></label><select id="b${bi}_barangay" name="branches[${bi}][address_barangay]" class="form-control" disabled><option value="">-- Select Barangay --</option></select></div>
        <div class="form-group"><label class="form-label" style="font-size:.78rem;">ZIP Code</label><input type="text" name="branches[${bi}][address_zip]" class="form-control" placeholder="e.g. 7000" maxlength="20"></div>
    </div>
    <div class="form-group" style="margin-bottom:16px;"><label class="form-label" style="font-size:.78rem;">Street / Unit / Bldg <span style="color:red;">*</span></label><input type="text" name="branches[${bi}][address_street]" class="form-control" placeholder="e.g. 123 Rizal St, Unit 4" maxlength="255"></div>
    <hr style="margin:12px 0;border-color:var(--neutral-100);">
    <h5 style="font-size:.83rem;font-weight:700;color:var(--neutral-700);margin-bottom:10px;"><i class="fas fa-folder-open" style="color:var(--primary-500);margin-right:5px;"></i>Documents <span style="font-weight:400;font-size:.73rem;color:var(--neutral-400);">PDF, DOC, JPG, PNG -- max 20 MB</span></h5>
    <div id="b${bi}_docs"></div>${inheritRow}<div id="b${bi}_rm_section"></div>`;
}
function buildBranchTabs_REMOVED_DUPLICATE(n){}
function renderBranchDocs(bi){
    const cs=document.querySelector('[name="branches['+bi+'][cert_status]"]');
    const cert=cs?cs.value:(selectedPath==='certified'?'certified':'not_certified');
    const docs=cert==='certified'
        ?[['bir_notice','BIR Notice / Invoice'],['halal_certificate','Halal Certificate']]
        :[['bir_notice','BIR Notice / Invoice'],['business_permit','Business Permit'],
          ['mayors_barangay_permit',"Mayor's / Barangay Permit"],
          ['sanitary_permit','Sanitary Permit'],['lto_permit','LTO - License to Operate']];
    // Add expiry date field for certified branches
    let expiryHTML='';
    if(cert==='certified' && bi===0){
        expiryHTML=`<div class="form-group" style="margin-bottom:12px;">
            <label class="form-label" style="font-size:.8rem;font-weight:600;">Halal Certificate Expiry Date <span style="color:red;">*</span></label>
            <input type="date" name="halal_cert_expiry" class="form-control" style="max-width:220px;"
                   min="<?= date('Y-m-d') ?>" required>
            <p style="font-size:.72rem;color:var(--neutral-400);margin-top:4px;">Enter the expiration date shown on your Halal certificate</p>
        </div>`;
    }
    const c=document.getElementById('b'+bi+'_docs');if(!c)return;
    c.innerHTML=docs.map(([slot,lbl])=>`<div class="form-group" style="margin-bottom:12px;">
        <label class="form-label" style="font-size:.8rem;font-weight:600;">${lbl} <span style="color:red;">*</span></label>
        <div class="file-upload-zone" id="zone_b${bi}_${slot}" onclick="document.getElementById('file_b${bi}_${slot}').click()">
            <i class="fas fa-cloud-upload-alt" style="font-size:1.1rem;color:var(--neutral-300);margin-bottom:3px;display:block;"></i>
            <span class="zone-text" style="font-size:.76rem;">Click to upload or drag &amp; drop</span>
            <div id="prev_b${bi}_${slot}" style="display:none;margin-top:5px;font-size:.76rem;color:#059669;font-weight:600;"></div>
        </div>
        <input type="file" id="file_b${bi}_${slot}" name="branches[${bi}][${slot}]"
               accept=".pdf,.doc,.docx,.jpg,.jpeg,.png" style="display:none;"
               onchange="branchDocPreview(this,${bi},'${slot}')">
    </div>`).join('')+expiryHTML;
    renderBranchRM(bi);
}
function renderBranchRM(bi){
    const cs=document.querySelector('[name="branches['+bi+'][cert_status]"]');
    const cert=cs?cs.value:(selectedPath==='certified'?'certified':'not_certified');
    const sec=document.getElementById('b'+bi+'_rm_section');if(!sec)return;
    const inh=document.querySelector('[name="branches['+bi+'][inherit_raw_materials]"]');
    if(cert!=='not_certified'||(inh&&inh.checked)){sec.innerHTML='';return;}
    sec.innerHTML=`<hr style="margin:12px 0;border-color:var(--neutral-100);">
    <h5 style="font-size:.83rem;font-weight:700;color:var(--neutral-700);margin-bottom:10px;"><i class="fas fa-list-alt" style="color:var(--primary-500);margin-right:5px;"></i>Raw Material List &amp; Halal Verification <span style="color:red;">*</span></h5>
    <div style="display:grid;grid-template-columns:1fr 1fr;gap:10px;margin-bottom:14px;">
        <label id="rm-opt-upload-card-${bi}" class="rm-mode-card" style="border:2px solid var(--neutral-200);border-radius:12px;padding:14px 10px;cursor:pointer;text-align:center;">
            <input type="radio" name="branches[${bi}][raw_material_mode]" value="upload" style="display:none;" onchange="selectBranchRMMode(${bi},'upload')">
            <i class="fas fa-file-upload" style="font-size:1.3rem;color:var(--primary-500);margin-bottom:5px;display:block;"></i>
            <strong style="font-size:.83rem;">Option A -- Upload File</strong>
            <p style="font-size:.7rem;color:var(--neutral-400);margin:3px 0 0;">PDF, Excel, Word</p></label>
        <label id="rm-opt-builder-card-${bi}" class="rm-mode-card" style="border:2px solid var(--neutral-200);border-radius:12px;padding:14px 10px;cursor:pointer;text-align:center;">
            <input type="radio" name="branches[${bi}][raw_material_mode]" value="builder" style="display:none;" onchange="selectBranchRMMode(${bi},'builder')">
            <i class="fas fa-th-list" style="font-size:1.3rem;color:var(--accent-500);margin-bottom:5px;display:block;"></i>
            <strong style="font-size:.83rem;">Option B -- Build List</strong>
            <p style="font-size:.7rem;color:var(--neutral-400);margin:3px 0 0;">Enter items one by one</p></label>
    </div>
    <div id="rm-upload-section-${bi}" style="display:none;">
        <p style="font-size:.76rem;color:var(--neutral-500);margin-bottom:8px;"><i class="fas fa-info-circle" style="color:var(--primary-500);"></i> List must include item names, manufacturers, and Halal certification details.</p>
        <div class="file-upload-zone" id="zone_b${bi}_rm_file" onclick="document.getElementById('file_b${bi}_rm').click()">
            <i class="fas fa-cloud-upload-alt" style="font-size:1.1rem;color:var(--neutral-300);margin-bottom:3px;display:block;"></i>
            <span class="zone-text" style="font-size:.76rem;">PDF, XLSX, DOCX, JPG, PNG -- max 20 MB</span>
            <div id="prev_b${bi}_rm_file" style="display:none;margin-top:5px;font-size:.76rem;color:#059669;font-weight:600;"></div>
        </div>
        <input type="file" id="file_b${bi}_rm" name="branches[${bi}][raw_material_list_file]"
               accept=".pdf,.xlsx,.xls,.docx,.doc,.jpg,.jpeg,.png" style="display:none;"
               onchange="branchDocPreview(this,${bi},'rm_file')">
    </div>
    <div id="rm-builder-section-${bi}" style="display:none;">
        <div id="rm-rows-${bi}"></div>
        <button type="button" class="btn btn-outline" onclick="addRMRow(${bi})" style="margin-top:8px;font-size:.8rem;"><i class="fas fa-plus"></i> Add Another Item</button>
    </div>`;
}
function toggleInheritRM(chk,bi){renderBranchRM(bi);}
function selectBranchRMMode(bi,mode){
    ['upload','builder'].forEach(m=>{
        const c=document.getElementById('rm-opt-'+m+'-card-'+bi);
        const s=document.getElementById('rm-'+m+'-section-'+bi);
        if(c)c.classList.toggle('rm-selected',m===mode);
        if(s)s.style.display=m===mode?'':'none';
    });
    if(mode==='builder'&&document.querySelectorAll('#rm-rows-'+bi+' .rm-row').length===0)addRMRow(bi);
}
function initBranchLocation(bi){
    const s=document.getElementById('b'+bi+'_region');if(!s)return;
    s.innerHTML='<option value="">-- Select Region --</option>';
    _regions.forEach(r=>{const o=document.createElement('option');o.value=r.name;o.textContent=r.name;s.appendChild(o);});
    s.disabled=false;
}
function onBranchRegion(bi){
    const rName=document.getElementById('b'+bi+'_region').value;
    const rCode=_regions.find(r=>r.name===rName)?.code||'';
    const ps=document.getElementById('b'+bi+'_province');
    const cs=document.getElementById('b'+bi+'_city');
    const bs=document.getElementById('b'+bi+'_barangay');
    [ps,cs,bs].forEach(s=>{s.innerHTML='<option value="">--</option>';s.disabled=true;});
    if(!rCode||!_provinces)return;
    const f=_provinces.filter(p=>p.regionCode===rCode).sort((a,b)=>a.name.localeCompare(b.name));
    ps.innerHTML='<option value="">-- Select Province --</option>';
    f.forEach(p=>{const o=document.createElement('option');o.value=p.name;o.textContent=p.name;ps.appendChild(o);});
    ps.disabled=false;
    cs.innerHTML='<option value="">-- Select City --</option>';
    bs.innerHTML='<option value="">-- Select Barangay --</option>';
}
function onBranchProvince(bi){
    const pName=document.getElementById('b'+bi+'_province').value;
    const pCode=_provinces.find(p=>p.name===pName)?.code||'';
    const cs=document.getElementById('b'+bi+'_city');
    const bs=document.getElementById('b'+bi+'_barangay');
    cs.innerHTML='<option value="">--</option>';cs.disabled=true;
    bs.innerHTML='<option value="">--</option>';bs.disabled=true;
    if(!pCode||!_cities)return;
    const f=_cities.filter(c=>c.provinceCode===pCode).sort((a,b)=>a.name.localeCompare(b.name));
    cs.innerHTML='<option value="">-- Select City --</option>';
    // Store both name (value) and code (data-code) so onBranchCity can look up barangays
    f.forEach(c=>{const o=document.createElement('option');o.value=c.name;o.dataset.code=c.code;o.textContent=c.name;cs.appendChild(o);});
    cs.disabled=false;
}
function onBranchCity(bi){
    const citySelect=document.getElementById('b'+bi+'_city');
    const selectedOpt=citySelect.options[citySelect.selectedIndex];
    // Use data-code if present (set by onBranchProvince), fallback to value for legacy
    const cc=selectedOpt?.dataset?.code||selectedOpt?.value||'';
    const bs=document.getElementById('b'+bi+'_barangay');
    bs.innerHTML='<option value="">--</option>';bs.disabled=true;
    if(!cc)return;
    const names=(_barangays&&_barangays[cc])||[];
    bs.innerHTML=names.length
        ?'<option value="">-- Select Barangay --</option>'
        :'<option value="">(Not listed -- type in street field)</option>';
    names.forEach(n=>{const o=document.createElement('option');o.value=n;o.textContent=n;bs.appendChild(o);});
    bs.disabled=false;
}
</script>
ENDJSB;
echo $extra_js_b;


// ── Raw material row builder + image helpers ──────────────────────────────────
$extra_js_c = <<<'ENDJSC'
<script>
// _rmRowIdx is declared in the core script block above; do NOT re-declare here.
function addRMRow(bi) {
    const idx = (_rmRowIdx[bi] = (_rmRowIdx[bi] || 0));
    _rmRowIdx[bi]++;
    const cont = document.getElementById('rm-rows-' + bi);
    if (!cont) return;
    const div = document.createElement('div');
    div.className = 'rm-row';
    div.id = 'rm-row-' + bi + '-' + idx;
    div.innerHTML = `
    <div class="rm-row-header">
        <span class="rm-row-number">Item #${idx + 1}</span>
        <button type="button" onclick="removeRMRow(${bi},${idx})"
            style="background:none;border:none;cursor:pointer;color:#ef4444;font-size:.8rem;padding:2px 6px;">
            <i class="fas fa-trash-alt"></i> Remove</button>
    </div>
    <div class="rm-g2">
        <div class="form-group">
            <label class="form-label" style="font-size:.78rem;">Item Name <span style="color:red;">*</span></label>
            <input type="text" name="branches[${bi}][materials][${idx}][name]"
                   class="form-control" placeholder="e.g. Chicken Breast" maxlength="255">
        </div>
        <div class="form-group">
            <label class="form-label" style="font-size:.78rem;">Manufacturer</label>
            <input type="text" name="branches[${bi}][materials][${idx}][manufacturer]"
                   class="form-control" placeholder="e.g. San Miguel Foods" maxlength="255">
        </div>
    </div>
    <div class="rm-g2">
        <div class="form-group">
            <label class="form-label" style="font-size:.78rem;">Halal Status <span style="color:red;">*</span></label>
            <select name="branches[${bi}][materials][${idx}][halal_status]" class="form-control">
                <option value="certified">Certified</option>
                <option value="non_certified">Non-Certified</option>
                <option value="pending" selected>Pending Verification</option>
            </select>
        </div>
        <div class="form-group">
            <label class="form-label" style="font-size:.78rem;">Halal Certification Body</label>
            <input type="text" name="branches[${bi}][materials][${idx}][cert_body]"
                   class="form-control" placeholder="e.g. IDCP, IFANCA" maxlength="255">
        </div>
    </div>
    <div style="margin-bottom:10px;">
        <label style="display:flex;align-items:center;gap:8px;cursor:pointer;font-size:.8rem;color:var(--neutral-700);">
            <input type="checkbox" name="branches[${bi}][materials][${idx}][has_back_logo]" value="1"
                   onchange="toggleBackLogo(this,${bi},${idx})"
                   style="width:15px;height:15px;accent-color:var(--primary-600);">
            Halal logo is on the <strong>back</strong> of the packaging
        </label>
    </div>
    <div class="rm-g2">
        <div>
            <label class="form-label" style="font-size:.78rem;">Front Image <span style="color:red;">*</span></label>
            <div class="rm-img-zone" id="rz-front-${bi}-${idx}"
                 onclick="document.getElementById('ri-front-${bi}-${idx}').click()">
                <i class="fas fa-camera" style="font-size:1rem;margin-bottom:3px;display:block;"></i>
                <span id="rl-front-${bi}-${idx}">Upload / Take Photo</span>
            </div>
            <input type="file" id="ri-front-${bi}-${idx}"
                   name="branches[${bi}][materials][${idx}][image_front]"
                   accept="image/*" capture="environment" style="display:none;"
                   onchange="rmImgPreview(this,'front',${bi},${idx})">
        </div>
        <div id="rm-back-wrap-${bi}-${idx}" style="display:none;">
            <label class="form-label" style="font-size:.78rem;">Back Image <span style="color:red;">*</span></label>
            <div class="rm-img-zone" id="rz-back-${bi}-${idx}"
                 onclick="document.getElementById('ri-back-${bi}-${idx}').click()">
                <i class="fas fa-camera" style="font-size:1rem;margin-bottom:3px;display:block;"></i>
                <span id="rl-back-${bi}-${idx}">Upload / Take Photo</span>
            </div>
            <input type="file" id="ri-back-${bi}-${idx}"
                   name="branches[${bi}][materials][${idx}][image_back]"
                   accept="image/*" capture="environment" style="display:none;"
                   onchange="rmImgPreview(this,'back',${bi},${idx})">
        </div>
    </div>`;
    cont.appendChild(div);
    renumberRMRows(bi);
}
function removeRMRow(bi, idx) {
    const r = document.getElementById('rm-row-' + bi + '-' + idx);
    if (r) r.remove();
    renumberRMRows(bi);
}
function renumberRMRows(bi) {
    document.querySelectorAll('#rm-rows-' + bi + ' .rm-row').forEach((r, i) => {
        const b = r.querySelector('.rm-row-number');
        if (b) b.textContent = 'Item #' + (i + 1);
    });
}
function toggleBackLogo(chk, bi, idx) {
    const w = document.getElementById('rm-back-wrap-' + bi + '-' + idx);
    if (w) w.style.display = chk.checked ? '' : 'none';
}
function rmImgPreview(inp, side, bi, idx) {
    if (!inp.files || !inp.files[0]) return;
    const f = inp.files[0];
    const z = document.getElementById('rz-' + side + '-' + bi + '-' + idx);
    const l = document.getElementById('rl-' + side + '-' + bi + '-' + idx);
    if (z) z.classList.add('has-img');
    if (l) l.textContent = '✓ ' + f.name + ' (' + (f.size / 1024).toFixed(0) + ' KB)';
}
function branchDocPreview(inp, bi, slot) {
    const z = document.getElementById('zone_b' + bi + '_' + slot);
    const p = document.getElementById('prev_b' + bi + '_' + slot);
    if (!inp.files || !inp.files[0] || !z || !p) return;
    const f = inp.files[0];
    p.textContent = '✓ ' + f.name + ' (' + (f.size / 1024).toFixed(1) + ' KB)';
    p.style.display = '';
    z.classList.add('has-file');
}
</script>
ENDJSC;
echo $extra_js_c;


// ── Validation, review summary, step 3 confirm, and DOMContentLoaded init ────
$extra_js_d = <<<'ENDJSD'
<script>
async function confirmBranchCount() {
    // Await location data before building branch tabs so Region dropdowns
    // are populated on first render for every branch.
    await preloadLoc();
    numBranches = parseInt(document.getElementById('num_branches_select').value) || 1;
    document.getElementById('hidden_num_branches').value = numBranches;
    buildBranchTabs(numBranches);
    goToStep(4);
}
function validateAllBranches() {
    const validTypes = ['restaurant','food_stall','karenderia','cafe','fast_food','food_truck','bakery','catering'];
    for (let bi = 0; bi < numBranches; bi++) {
        const lbl = 'Branch ' + (bi + 1) + (bi === 0 ? ' (Main)' : '');
        const typeEl = document.querySelector('[name="branches[' + bi + '][type]"]');
        if (!typeEl || !validTypes.includes(typeEl.value)) {
            showToast(lbl + ': Select a branch type.'); switchBranchTab(bi); return;
        }
        const certEl = document.querySelector('[name="branches[' + bi + '][cert_status]"]');
        if (!certEl || !['certified','not_certified'].includes(certEl.value)) {
            showToast(lbl + ': Select Halal certification status.'); switchBranchTab(bi); return;
        }
        const city = document.getElementById('b' + bi + '_city')?.value;
        const prov = document.getElementById('b' + bi + '_province')?.value;
        const brgy = document.getElementById('b' + bi + '_barangay')?.value;
        const str  = document.querySelector('[name="branches[' + bi + '][address_street]"]')?.value?.trim();
        if (!city || !prov || !brgy || !str) {
            showToast(lbl + ': Complete the full address (province, city, barangay, street).');
            switchBranchTab(bi); return;
        }
        const cert = certEl.value;
        const req = cert === 'certified'
            ? ['bir_notice','halal_certificate']
            : ['bir_notice','business_permit','mayors_barangay_permit','sanitary_permit','lto_permit'];
        for (const slot of req) {
            const fi = document.getElementById('file_b' + bi + '_' + slot);
            if (!fi?.files?.length) {
                showToast(lbl + ': Upload ' + slot.replace(/_/g,' ').replace(/\b\w/g, c => c.toUpperCase()) + '.');
                switchBranchTab(bi); return;
            }
        }
        if (cert === 'not_certified') {
            const inh = document.querySelector('[name="branches[' + bi + '][inherit_raw_materials]"]');
            if (bi > 0 && inh && inh.checked) continue;
            const mode = document.querySelector('[name="branches[' + bi + '][raw_material_mode]"]:checked')?.value;
            if (!mode) {
                showToast(lbl + ': Choose Raw Material List option (A or B).'); switchBranchTab(bi); return;
            }
            if (mode === 'upload') {
                const fi = document.getElementById('file_b' + bi + '_rm');
                if (!fi?.files?.length) {
                    showToast(lbl + ': Upload Raw Material List file.'); switchBranchTab(bi); return;
                }
            } else {
                const rows = document.querySelectorAll('#rm-rows-' + bi + ' .rm-row');
                let ok = false;
                rows.forEach(r => { if (r.querySelector('[name$="[name]"]')?.value?.trim()) ok = true; });
                if (!ok) { showToast(lbl + ': Add at least one raw material item.'); switchBranchTab(bi); return; }
            }
        }
    }
    goToStep(5);
}
function buildReviewSummary() {
    const get = n => document.querySelector('[name="' + n + '"]')?.value?.trim() || '--';
    const gst = id => { const e = document.getElementById(id); return e?.options[e.selectedIndex]?.text?.trim() || '--'; };
    let h = '<p><strong>Certification:</strong> ' + (selectedPath === 'certified' ? 'Yes -- Halal-Certified' : 'No -- Not Certified') + '</p>';
    h += '<p><strong>Business Name:</strong> ' + escHtml(get('business_name') || '(not provided)') + '</p>';
    h += '<p><strong>Description:</strong> ' + escHtml(get('business_description').substring(0, 100)) + (get('business_description').length > 100 ? '...' : '') + '</p>';
    h += '<p><strong>Owner:</strong> ' + escHtml(get('owner_name')) + ' -- Age ' + escHtml(get('owner_age')) + ', ' + escHtml(get('owner_religion')) + '</p>';
    h += '<hr style="margin:10px 0;border-color:var(--neutral-200);">';
    for (let bi = 0; bi < numBranches; bi++) {
        const lbl = bi === 0 ? 'Branch 1 (Main Branch)' : 'Branch ' + (bi + 1);
        const btype = document.querySelector('[name="branches[' + bi + '][type]"]')?.value || '--';
        const cert  = document.querySelector('[name="branches[' + bi + '][cert_status]"]')?.value || '--';
        const city  = gst('b' + bi + '_city');
        const prov  = gst('b' + bi + '_province');
        const brgy  = gst('b' + bi + '_barangay');
        const str   = get('branches[' + bi + '][address_street]');
        const inh   = document.querySelector('[name="branches[' + bi + '][inherit_raw_materials]"]');
        h += '<p style="font-weight:700;margin:10px 0 3px;">' + escHtml(lbl) + '</p>';
        h += '<p style="margin:0 0 2px;padding-left:12px;"><strong>Type:</strong> ' + escHtml(btype) + ' | <strong>Cert:</strong> ' + escHtml(cert) + '</p>';
        h += '<p style="margin:0 0 2px;padding-left:12px;"><strong>Address:</strong> ' + escHtml([brgy, city, prov, str].filter(v => v && v !== '--').join(', ')) + '</p>';
        if (bi > 0 && inh && inh.checked)
            h += '<p style="margin:0;padding-left:12px;color:#059669;font-size:.83rem;"><i class="fas fa-check-circle"></i> Inherits Raw Materials from Main Branch</p>';
    }
    document.getElementById('review-summary').innerHTML = h;
}
function escHtml(s) {
    return String(s).replace(/&/g,'&amp;').replace(/</g,'&lt;').replace(/>/g,'&gt;').replace(/"/g,'&quot;');
}
function showToast(msg) {
    document.querySelector('.biz-toast')?.remove();
    const d = document.createElement('div');
    d.className = 'biz-toast';
    d.style.cssText = 'position:fixed;bottom:24px;left:50%;transform:translateX(-50%);background:#1e293b;color:#fff;padding:11px 22px;border-radius:10px;font-size:.85rem;z-index:9999;box-shadow:0 4px 18px rgba(0,0,0,.25);white-space:nowrap;';
    d.textContent = msg;
    document.body.appendChild(d);
    setTimeout(() => d.remove(), 3200);
}
function flashError(el) {
    if (!el) return;
    const o = el.style.borderColor;
    el.style.borderColor = '#ef4444';
    setTimeout(() => el.style.borderColor = o, 2200);
}
document.addEventListener('DOMContentLoaded', async () => {
    await preloadLoc();
    document.getElementById('bizForm')?.addEventListener('submit', () => {
        const btn = document.getElementById('submitBtn');
        if (btn) { btn.disabled = true; btn.innerHTML = '<i class="fas fa-spinner fa-spin"></i> Submitting...'; }
    });
});
</script>
ENDJSD;
echo $extra_js_d;

require_once __DIR__ . '/../../includes/footer.php';
