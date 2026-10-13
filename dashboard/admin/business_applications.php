<?php
require_once __DIR__ . '/../../config/app.php';
require_once __DIR__ . '/../../includes/DocumentOCRService.php';
requireLogin();
requireRole([ROLE_ADMIN]);

if (empty($_SESSION['document_screening_csrf_token'])) {
    $_SESSION['document_screening_csrf_token'] = bin2hex(random_bytes(32));
}
$document_screening_csrf_token = (string)$_SESSION['document_screening_csrf_token'];
$document_screening_enabled = DocumentOCRService::isFeatureEnabled();

$page_title   = 'Business Applications';
$page_heading = 'Business Applications';
$is_dashboard = true;
$breadcrumbs  = [
    ['label' => 'Admin Dashboard', 'url' => BASE_URL . 'dashboard/admin/'],
    ['label' => 'Business Applications'],
];
$admin_id = $_SESSION['user_id'];
$error    = '';
$success  = '';

// -- Idempotent schema upgrades ------------------------------------------------
db_add_column($conn, 'business_applications', 'halal_classification',
    "ENUM('Halal','Muslim Friendly','Non-Pork','N/A') NOT NULL DEFAULT 'N/A'", 'admin_notes');
db_add_column($conn, 'business_applications', 'halal_certificate_path',
    "VARCHAR(500) DEFAULT NULL COMMENT 'Path to uploaded halal certificate for certified businesses'");
db_add_column($conn, 'business_applications', 'halal_certificate_expiry',
    "DATE DEFAULT NULL COMMENT 'Expiry date of the uploaded halal certificate'");

// -- POST: approve or reject ---------------------------------------------------
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $app_id = (int)($_POST['app_id'] ?? 0);
    $action = trim(strip_tags($_POST['action'] ?? ''));
    $notes  = trim(strip_tags($_POST['admin_notes'] ?? ''));
    $hclass = trim(strip_tags($_POST['halal_classification'] ?? 'N/A'));

    $valid_actions = ['approved', 'rejected', 'under_review', 'returned'];
    $valid_hclass  = ['Halal', 'Muslim Friendly', 'Non-Pork', 'N/A'];

    if (!$app_id || !in_array($action, $valid_actions, true)) {
        $error = 'Invalid request.';
    } else {
        if (!in_array($hclass, $valid_hclass, true)) $hclass = 'N/A';
        if ($action !== 'approved') $hclass = 'N/A';

        $conn->begin_transaction();
        try {
            // 1. Update the application record
            $upd = $conn->prepare("
                UPDATE business_applications
                SET status = ?, admin_notes = ?, halal_classification = ?,
                    reviewed_by = ?, reviewed_at = NOW(), updated_at = NOW()
                WHERE id = ?");
            $upd->bind_param("sssii", $action, $notes, $hclass, $admin_id, $app_id);
            $upd->execute();

            // 2. Fetch the applicant user_id
            $row = $conn->query("SELECT user_id, business_name FROM business_applications WHERE id = $app_id")->fetch_assoc();
            $applicant_id   = (int)$row['user_id'];
            $business_label = $row['business_name'] ?: 'your business';

            // 3. On approval — promote user to Business Owner role
            //    and auto-create the halal_restaurants row from their application data
            if ($action === 'approved') {
                $bo_role = ROLE_BUSINESS_OWNER; // 2
                $conn->query("UPDATE users SET role_id = $bo_role, role_status = 'approved' WHERE id = $applicant_id");

                // Pull the main branch address to build the restaurant address string
                // Also pull halal certificate path/expiry for bypass workflow
                $ba_detail = $conn->query("
                    SELECT ba.business_name, ba.halal_certificate_path, ba.halal_certificate_expiry,
                           bb.address_street, bb.address_barangay,
                           bb.address_city, bb.address_province,
                           bb.address_region, bb.address_zip
                    FROM business_applications ba
                    LEFT JOIN business_branches bb
                           ON bb.application_id = ba.id AND bb.is_main = 1
                    WHERE ba.id = $app_id
                    LIMIT 1
                ")->fetch_assoc();

                if ($ba_detail) {
                    $rest_name = $ba_detail['business_name'] ?: $business_label;
                    $addr_parts = array_filter([
                        $ba_detail['address_street']   ?? '',
                        $ba_detail['address_barangay'] ?? '',
                        $ba_detail['address_city']     ?? '',
                        $ba_detail['address_province'] ?? '',
                        $ba_detail['address_region']   ?? '',
                        $ba_detail['address_zip']      ?? '',
                    ]);
                    $rest_address = implode(', ', $addr_parts);

                    // Only create if one doesn't already exist for this owner
                    $existing_rest = $conn->query(
                        "SELECT id FROM halal_restaurants WHERE business_owner_id = $applicant_id LIMIT 1"
                    )->fetch_assoc();

                    if (!$existing_rest) {
                        $ri = $conn->prepare(
                            "INSERT INTO halal_restaurants
                                (business_owner_id, restaurant_name, address, is_active)
                             VALUES (?, ?, ?, 1)"
                        );
                        $ri->bind_param("iss", $applicant_id, $rest_name, $rest_address);
                        $ri->execute();
                        $restaurant_id = $conn->insert_id;
                    } else {
                        $restaurant_id = $existing_rest['id'];
                    }

                    // ═══════════════════════════════════════════════════════════════
                    // BYPASS WORKFLOW: Auto-provision halal_certificates record
                    // If the business already uploaded a valid halal certificate during
                    // registration, skip the full HDP internal workflow and create
                    // the certificate record directly.
                    // ═══════════════════════════════════════════════════════════════
                    $halal_cert_path   = $ba_detail['halal_certificate_path'] ?? null;
                    $halal_cert_expiry = $ba_detail['halal_certificate_expiry'] ?? null;

                    if (!empty($halal_cert_path) && !empty($halal_cert_expiry)) {
                        // Generate certificate number: BPL-YYYY-XXXXXXXX (Bypass Prefix)
                        $cert_number = 'HVL-' . date('Y') . '-' . strtoupper(substr(uniqid(), -8));

                        // Check if a certificate already exists for this business owner
                        $existing_cert = $conn->query("
                            SELECT id FROM halal_certificates
                            WHERE application_id IS NULL
                              AND business_name = '" . $conn->real_escape_string($rest_name) . "'
                            LIMIT 1
                        ")->fetch_assoc();

                        if (!$existing_cert) {
                            // Ensure application_id and final_decision_id are nullable
                            // for the bypass path (no HDP application exists)
                            $conn->query("ALTER TABLE `halal_certificates`
                                MODIFY COLUMN `application_id`    INT(11) DEFAULT NULL,
                                MODIFY COLUMN `final_decision_id` INT(11) DEFAULT NULL");

                            // Insert the halal_certificates record directly
                            $issue_date = date('Y-m-d');
                            $ins_cert = $conn->prepare("
                                INSERT INTO halal_certificates
                                    (certificate_number, business_name, business_address,
                                     certifying_body, issue_date, expiry_date,
                                     certificate_file_path, status, payment_status,
                                     decorated_by, awarded_by)
                                VALUES (?, ?, ?,
                                        'Halal Development Program - HDIP', ?, ?,
                                        ?, 'active', 'paid',
                                        ?, ?)
                            ");
                            $ins_cert->bind_param("ssssssii",
                                $cert_number,
                                $rest_name,
                                $rest_address,
                                $issue_date,
                                $halal_cert_expiry,
                                $halal_cert_path,
                                $admin_id,
                                $admin_id
                            );
                            $ins_cert->execute();
                            $cert_id = $conn->insert_id;

                            // Link the certificate to the restaurant
                            $conn->query("UPDATE halal_restaurants SET certificate_id = $cert_id WHERE id = $restaurant_id");

                            // Notify the business owner about the auto-provisioned certificate
                            createNotification(
                                $conn, $applicant_id,
                                'Halal Certificate Verified & Active',
                                "Your Halal certificate has been verified and is now active! Certificate No: $cert_number. Valid until: " . date('M d, Y', strtotime($halal_cert_expiry)),
                                'success',
                                BASE_URL . 'dashboard/business_owner/certificates.php'
                            );

                            // Log the bypass
                            logActivity($conn, $admin_id,
                                'Halal Certificate Auto-Provisioned (Bypass)',
                                "Cert #$cert_id — $cert_number for $rest_name (expiry: $halal_cert_expiry)",
                                'certificates'
                            );
                        }
                    }
                }
            }

            // 4. Notify applicant
            $notif_map = [
                'approved'     => ['Your Business Application was Approved!',
                    "Congratulations! Your application for \"$business_label\" has been approved" .
                    ($action === 'approved' && $hclass !== 'N/A' ? " with Halal classification: $hclass." : "."),
                    'success', BASE_URL . 'dashboard/business_owner/'],
                'rejected'     => ['Business Application Not Approved',
                    "Your application for \"$business_label\" was not approved." .
                    ($notes ? " Reason: $notes" : ''),
                    'error', BASE_URL . 'dashboard/customer/apply_business.php'],
                'under_review' => ['Application Under Review',
                    "Your application for \"$business_label\" is now under review. We will notify you of the outcome.",
                    'info', BASE_URL . 'dashboard/customer/apply_business.php'],
                'returned'     => ['Application Returned — Revision Required',
                    "Your application for \"$business_label\" has been returned for revision." .
                    ($notes ? " Notes: $notes" : ''),
                    'warning', BASE_URL . 'dashboard/customer/apply_business.php'],
            ];
            [$ntitle, $nmsg, $ntype, $nlink] = $notif_map[$action];
            createNotification($conn, $applicant_id, $ntitle, $nmsg, $ntype, $nlink);

            // 5. Log activity
            logActivity($conn, $admin_id,
                'Business Application ' . ucfirst($action),
                "App #$app_id — $business_label" . ($hclass !== 'N/A' ? " [$hclass]" : ''),
                'business_applications');

            $conn->commit();
            $success = 'Application has been ' . ucfirst($action) . '.';
        } catch (Throwable $e) {
            $conn->rollback();
            $error = 'Server error: ' . $e->getMessage();
        }
    }
}

// -- List query ---------------------------------------------------------------
$filter = $_GET['filter'] ?? 'pending';
$valid_filters = ['pending', 'under_review', 'approved', 'rejected', 'returned', 'all'];
if (!in_array($filter, $valid_filters, true)) $filter = 'pending';

$where_clause = ($filter === 'all') ? '' : "WHERE ba.status = '$filter'";

$apps = $conn->query("
    SELECT ba.*,
           u.full_name  AS applicant_name,
           u.email      AS applicant_email,
           u.avatar     AS applicant_avatar,
           rev.full_name AS reviewer_name
    FROM business_applications ba
    JOIN  users u   ON ba.user_id     = u.id
    LEFT JOIN users rev ON ba.reviewed_by = rev.id
    $where_clause
    ORDER BY ba.created_at DESC
")->fetch_all(MYSQLI_ASSOC);

// Counts for filter tabs
$counts = [];
foreach (['pending','under_review','approved','rejected','returned'] as $s) {
    $counts[$s] = (int)$conn->query(
        "SELECT COUNT(*) AS c FROM business_applications WHERE status = '$s'"
    )->fetch_assoc()['c'];
}
$counts['all'] = array_sum($counts);

require_once __DIR__ . '/../../includes/header.php';
?>

<?php if ($error): ?>
<div class="alert alert-danger"><i class="fas fa-exclamation-circle"></i>
    <span><?= htmlspecialchars($error) ?></span>
    <button class="close-alert" onclick="this.parentElement.remove()"><i class="fas fa-times"></i></button>
</div>
<?php endif; ?>
<?php if ($success): ?>
<div class="alert alert-success"><i class="fas fa-check-circle"></i>
    <span><?= htmlspecialchars($success) ?></span>
    <button class="close-alert" onclick="this.parentElement.remove()"><i class="fas fa-times"></i></button>
</div>
<?php endif; ?>

<!-- Filter tabs -->
<div class="card" style="margin-bottom:16px;">
    <div class="card-body" style="display:flex;gap:8px;flex-wrap:wrap;padding:14px 18px;">
        <?php
        $tab_labels = ['pending'=>'Pending','under_review'=>'Under Review','approved'=>'Approved',
                       'rejected'=>'Rejected','returned'=>'Returned','all'=>'All'];
        foreach ($tab_labels as $key => $lbl):
            $active = $filter === $key;
            $cnt    = $counts[$key] ?? 0;
        ?>
        <a href="?filter=<?= $key ?>"
           class="btn btn-sm <?= $active ? 'btn-primary' : 'btn-outline' ?>">
            <?= $lbl ?>
            <?php if ($cnt > 0): ?>
                <span class="badge <?= $active ? 'badge-light' : 'badge-secondary' ?>"
                      style="margin-left:5px;"><?= $cnt ?></span>
            <?php endif; ?>
        </a>
        <?php endforeach; ?>
    </div>
</div>

<!-- Applications table -->
<div class="card">
    <div class="card-header">
        <h3><i class="fas fa-store" style="color:var(--primary-600);margin-right:8px;"></i>
            Business Applications
            <?php if ($counts['pending'] > 0 && $filter !== 'pending'): ?>
                <span class="badge badge-warning" style="margin-left:8px;font-size:.72rem;">
                    <?= $counts['pending'] ?> pending
                </span>
            <?php endif; ?>
        </h3>
    </div>
    <div class="card-body" style="padding:0;">
        <?php if (empty($apps)): ?>
        <div class="empty-state" style="padding:48px 24px;text-align:center;">
            <div class="empty-icon" style="font-size:2.5rem;color:var(--neutral-300);margin-bottom:12px;">
                <i class="fas fa-store-slash"></i>
            </div>
            <h3 style="color:var(--neutral-500);">No Applications</h3>
            <p style="color:var(--neutral-400);font-size:.88rem;">
                No business applications match this filter.
            </p>
        </div>
        <?php else: ?>
        <div style="overflow-x:auto;">
        <table style="width:100%;border-collapse:collapse;font-size:.875rem;">
            <thead>
            <tr style="background:var(--neutral-50);border-bottom:2px solid var(--neutral-200);">
                <th style="padding:12px 18px;text-align:left;font-weight:600;color:var(--neutral-600);">Applicant</th>
                <th style="padding:12px 16px;text-align:left;font-weight:600;color:var(--neutral-600);">Business</th>
                <th style="padding:12px 16px;text-align:center;font-weight:600;color:var(--neutral-600);">Certified?</th>
                <th style="padding:12px 16px;text-align:center;font-weight:600;color:var(--neutral-600);">Submitted</th>
                <th style="padding:12px 16px;text-align:center;font-weight:600;color:var(--neutral-600);">Status</th>
                <th style="padding:12px 16px;text-align:center;font-weight:600;color:var(--neutral-600);">Halal Class</th>
                <th style="padding:12px 16px;text-align:center;font-weight:600;color:var(--neutral-600);">Action</th>
            </tr>
            </thead>
            <tbody>
            <?php foreach ($apps as $app): ?>
            <tr style="border-bottom:1px solid var(--neutral-100);transition:background .15s;"
                onmouseover="this.style.background='var(--neutral-50)'"
                onmouseout="this.style.background=''">
                <!-- Applicant -->
                <td style="padding:14px 18px;">
                    <div style="display:flex;align-items:center;gap:10px;">
                        <?php if ($app['applicant_avatar']): ?>
                            <img src="<?= htmlspecialchars($app['applicant_avatar']) ?>"
                                 style="width:36px;height:36px;border-radius:8px;object-fit:cover;">
                        <?php else: ?>
                            <div style="width:36px;height:36px;border-radius:8px;background:var(--primary-100);
                                        color:var(--primary-700);display:flex;align-items:center;
                                        justify-content:center;font-weight:700;">
                                <?= strtoupper(substr($app['applicant_name'], 0, 1)) ?>
                            </div>
                        <?php endif; ?>
                        <div>
                            <div style="font-weight:600;color:var(--neutral-800);">
                                <?= htmlspecialchars($app['applicant_name']) ?>
                            </div>
                            <div style="font-size:.78rem;color:var(--neutral-400);">
                                <?= htmlspecialchars($app['applicant_email']) ?>
                            </div>
                        </div>
                    </div>
                </td>
                <!-- Business -->
                <td style="padding:14px 16px;max-width:200px;">
                    <div style="font-weight:500;color:var(--neutral-700);white-space:nowrap;
                                overflow:hidden;text-overflow:ellipsis;">
                        <?= htmlspecialchars($app['business_name'] ?: '(unnamed)') ?>
                    </div>
                    <div style="font-size:.75rem;color:var(--neutral-400);margin-top:2px;">
                        <?= htmlspecialchars(substr($app['business_description'] ?? '', 0, 60)) ?>
                        <?= strlen($app['business_description'] ?? '') > 60 ? '…' : '' ?>
                    </div>
                </td>
                <!-- Certified -->
                <td style="padding:14px 16px;text-align:center;">
                    <?php if ($app['certification_path'] === 'certified'): ?>
                        <span style="background:#ecfdf5;color:#059669;font-size:.75rem;font-weight:600;
                                     padding:3px 10px;border-radius:20px;border:1px solid #bbf7d0;">
                            <i class="fas fa-certificate"></i> Yes
                        </span>
                    <?php else: ?>
                        <span style="background:#f1f5f9;color:#64748b;font-size:.75rem;font-weight:600;
                                     padding:3px 10px;border-radius:20px;border:1px solid #e2e8f0;">
                            <i class="fas fa-file-alt"></i> No
                        </span>
                    <?php endif; ?>
                </td>
                <!-- Date -->
                <td style="padding:14px 16px;text-align:center;color:var(--neutral-500);font-size:.82rem;">
                    <?= formatDate($app['created_at']) ?><br>
                    <span style="font-size:.72rem;color:var(--neutral-400);">
                        <?= date('h:i A', strtotime($app['created_at'])) ?>
                    </span>
                </td>
                <!-- Status -->
                <td style="padding:14px 16px;text-align:center;">
                    <?= getStatusBadge($app['status']) ?>
                </td>
                <!-- Halal classification -->
                <td style="padding:14px 16px;text-align:center;font-size:.8rem;font-weight:600;color:var(--neutral-600);">
                    <?php if ($app['halal_classification'] !== 'N/A'): ?>
                        <span style="background:#fef9c3;color:#b45309;padding:3px 10px;
                                     border-radius:20px;border:1px solid #fde68a;">
                            <?= htmlspecialchars($app['halal_classification']) ?>
                        </span>
                    <?php else: ?>
                        <span style="color:var(--neutral-300);">—</span>
                    <?php endif; ?>
                </td>
                <!-- Action -->
                <td style="padding:14px 16px;text-align:center;">
                    <button class="btn btn-sm btn-primary"
                            onclick="openReview(<?= $app['id'] ?>)"
                            title="Review application">
                        <i class="fas fa-eye"></i> Review
                    </button>
                </td>
            </tr>
            <?php endforeach; ?>
            </tbody>
        </table>
        </div>
        <?php endif; ?>
    </div>
</div>

<!-- ══════════════════════════════════════════════════════════════════════
     REVIEW SLIDE-OVER PANEL
     Loads application detail via fetch() into a right-side drawer.
     ══════════════════════════════════════════════════════════════════════ -->
<div id="reviewOverlay"
     style="display:none;position:fixed;inset:0;background:rgba(0,0,0,.45);z-index:1000;
            animation:fadeIn .2s ease;"
     onclick="if(event.target===this)closeReview()">
</div>

<div id="reviewDrawer"
     style="display:none;position:fixed;top:0;right:0;bottom:0;width:min(760px,100vw);
            background:#fff;z-index:1001;overflow-y:auto;
            box-shadow:-6px 0 32px rgba(0,0,0,.18);
            animation:slideInRight .28s cubic-bezier(.4,0,.2,1);">

    <!-- Drawer header -->
    <div style="position:sticky;top:0;background:#fff;z-index:10;
                padding:18px 24px;border-bottom:1px solid var(--neutral-200);
                display:flex;align-items:center;gap:12px;">
        <button onclick="closeReview()"
                style="background:none;border:none;cursor:pointer;padding:4px 8px;
                       border-radius:6px;color:var(--neutral-500);font-size:1rem;"
                title="Close">
            <i class="fas fa-times"></i>
        </button>
        <h3 style="margin:0;font-size:1rem;font-weight:700;color:var(--neutral-800);flex:1;">
            Application Review
        </h3>
        <span id="drawerStatusBadge"></span>
    </div>

    <!-- Drawer body — filled by JS -->
    <div id="reviewDrawerBody" style="padding:24px;">
        <div style="text-align:center;padding:40px;color:var(--neutral-400);">
            <i class="fas fa-spinner fa-spin" style="font-size:1.6rem;"></i>
            <p style="margin-top:10px;">Loading…</p>
        </div>
    </div>
</div>

<style>
@keyframes fadeIn{from{opacity:0}to{opacity:1}}
@keyframes slideInRight{from{transform:translateX(100%)}to{transform:translateX(0)}}
.review-section{margin-bottom:22px;}
.review-section h4{font-size:.82rem;font-weight:700;text-transform:uppercase;
    letter-spacing:.06em;color:var(--neutral-400);margin:0 0 10px;
    padding-bottom:6px;border-bottom:1px solid var(--neutral-100);}
.review-row{display:flex;gap:8px;margin-bottom:6px;font-size:.875rem;}
.review-row .lbl{min-width:140px;color:var(--neutral-500);font-weight:500;flex-shrink:0;}
.review-row .val{color:var(--neutral-800);word-break:break-word;}
.doc-chip{display:inline-flex;align-items:center;gap:6px;background:var(--neutral-50);
    border:1px solid var(--neutral-200);border-radius:8px;padding:6px 12px;
    font-size:.8rem;color:var(--primary-700);text-decoration:none;margin:3px 4px 3px 0;
    transition:background .15s,border-color .15s;}
.doc-chip:hover{background:#eff6ff;border-color:var(--primary-300);}
.rm-item-card{border:1px solid var(--neutral-200);border-radius:10px;padding:12px 14px;
    margin-bottom:10px;background:var(--neutral-50);}
.rm-item-card h5{margin:0 0 8px;font-size:.83rem;font-weight:700;color:var(--neutral-700);}
.rm-grid{display:grid;grid-template-columns:1fr 1fr;gap:6px 14px;font-size:.8rem;}
.rm-grid .lbl{color:var(--neutral-500);}
.rm-grid .val{color:var(--neutral-800);font-weight:500;}
.rm-img{width:80px;height:80px;object-fit:cover;border-radius:8px;border:1px solid var(--neutral-200);cursor:pointer;}
.decision-panel{background:var(--neutral-50);border:1px solid var(--neutral-200);
    border-radius:12px;padding:20px 22px;margin-top:24px;}
.decision-panel h4{font-size:.88rem;font-weight:700;color:var(--neutral-700);margin:0 0 16px;}
</style>


<?php
// PHP helper — emit JS constant for upload base URL (used by drawer)
$upload_base_url = BASE_URL . 'uploads/';
?>
<script>
const UPLOAD_BASE = <?= json_encode($upload_base_url) ?>;
const REVIEW_URL  = <?= json_encode(BASE_URL . 'dashboard/admin/api/business_app_detail.php?id=') ?>;
const ACTION_URL  = <?= json_encode(BASE_URL . 'dashboard/admin/business_applications.php') ?>;
const SCREENING_URL = <?= json_encode(BASE_URL . 'dashboard/admin/api/document_screening_api.php') ?>;
const SCREENING_PREVIEW_URL = <?= json_encode(BASE_URL . 'dashboard/admin/api/document_screening_preview.php') ?>;
const DOCUMENT_SCREENING_CSRF = <?= json_encode($document_screening_csrf_token) ?>;
const DOCUMENT_SCREENING_ENABLED = <?= $document_screening_enabled ? 'true' : 'false' ?>;
let activeReviewId = null;

// ── Human-readable labels ────────────────────────────────────────────────────
const DOC_LABELS = {
    bir_notice: 'BIR Notice / Invoice',
    halal_certificate: 'Halal Certificate',
    business_permit: 'Business Permit',
    mayors_barangay_permit: "Mayor's / Barangay Permit",
    sanitary_permit: 'Sanitary Permit',
    lto_permit: 'LTO — License to Operate',
    raw_material_list_file: 'Raw Material List (file)',
};
const BRANCH_TYPES = {
    restaurant:'Restaurant', food_stall:'Food Stall', karenderia:'Karenderia',
    cafe:'Cafe', fast_food:'Fast Food', food_truck:'Food Truck',
    bakery:'Bakery', catering:'Catering',
};
const STATUS_COLORS = {
    pending:      {bg:'#fef9c3',color:'#92400e',border:'#fde68a'},
    under_review: {bg:'#eff6ff',color:'#1d4ed8',border:'#bfdbfe'},
    approved:     {bg:'#ecfdf5',color:'#065f46',border:'#bbf7d0'},
    rejected:     {bg:'#fef2f2',color:'#991b1b',border:'#fecaca'},
    returned:     {bg:'#fff7ed',color:'#9a3412',border:'#fed7aa'},
};

function statusBadgeHtml(status) {
    const s = STATUS_COLORS[status] || STATUS_COLORS.pending;
    const lbl = {pending:'Pending',under_review:'Under Review',approved:'Approved',
                 rejected:'Rejected',returned:'Returned'}[status] || status;
    return `<span style="background:${s.bg};color:${s.color};border:1px solid ${s.border};
        border-radius:20px;padding:3px 12px;font-size:.75rem;font-weight:700;">${lbl}</span>`;
}
function esc(str) {
    return String(str ?? '').replace(/&/g,'&amp;').replace(/</g,'&lt;')
                            .replace(/>/g,'&gt;').replace(/"/g,'&quot;');
}
function fileExt(path) { return (path||'').split('.').pop().toLowerCase(); }
function isImage(path) { return ['jpg','jpeg','png','webp','gif'].includes(fileExt(path)); }
function fmtSize(bytes) {
    if (!bytes) return '';
    return bytes >= 1048576 ? (bytes/1048576).toFixed(1)+' MB' : Math.round(bytes/1024)+' KB';
}

// ── Open / close drawer ──────────────────────────────────────────────────────
function openReview(id) {
    activeReviewId = id;
    document.getElementById('reviewOverlay').style.display = '';
    document.getElementById('reviewDrawer').style.display  = '';
    document.getElementById('reviewDrawerBody').innerHTML  =
        '<div style="text-align:center;padding:40px;color:var(--neutral-400);">' +
        '<i class="fas fa-spinner fa-spin" style="font-size:1.6rem;"></i>' +
        '<p style="margin-top:10px;">Loading…</p></div>';
    document.getElementById('drawerStatusBadge').innerHTML = '';

    fetch(REVIEW_URL + id)
        .then(r => r.json())
        .then(data => {
            if (data.error) {
                document.getElementById('reviewDrawerBody').innerHTML =
                    `<div class="alert alert-danger"><i class="fas fa-exclamation-circle"></i> ${esc(data.error)}</div>`;
                return;
            }
            renderDrawer(data);
        })
        .catch(() => {
            document.getElementById('reviewDrawerBody').innerHTML =
                '<div class="alert alert-danger">Failed to load application. Please try again.</div>';
        });
}
function closeReview() {
    document.getElementById('reviewOverlay').style.display = 'none';
    document.getElementById('reviewDrawer').style.display  = 'none';
}
document.addEventListener('keydown', e => { if (e.key === 'Escape') closeReview(); });
</script>

<script>
// ── Render drawer content ────────────────────────────────────────────────────
function renderDrawer(data) {
    const app = data.app;
    document.getElementById('drawerStatusBadge').innerHTML = statusBadgeHtml(app.status);

    let html = '';

    // ── Business & Owner Profile ──────────────────────────────────────────────
    html += `<div class="review-section">
        <h4><i class="fas fa-user-tie" style="margin-right:5px;color:var(--primary-500);"></i>Business &amp; Owner Profile</h4>
        <div class="review-row"><span class="lbl">Business Name</span>
            <span class="val">${esc(app.business_name || '(not provided)')}</span></div>
        <div class="review-row"><span class="lbl">Description</span>
            <span class="val" style="white-space:pre-wrap;">${esc(app.business_description)}</span></div>
        <div class="review-row"><span class="lbl">Certification Path</span>
            <span class="val">${app.certification_path === 'certified'
                ? '<span style="color:#059669;font-weight:600;"><i class="fas fa-certificate"></i> Already Halal-Certified</span>'
                : '<span style="color:#64748b;"><i class="fas fa-file-alt"></i> Not Yet Certified</span>'}</span></div>
        <hr style="margin:10px 0;border-color:var(--neutral-100);">
        <div class="review-row"><span class="lbl">Owner Name</span>
            <span class="val">${esc(app.owner_name)}</span></div>
        <div class="review-row"><span class="lbl">Age</span>
            <span class="val">${esc(app.owner_age)}</span></div>
        <div class="review-row"><span class="lbl">Religion</span>
            <span class="val">${esc(app.owner_religion)}</span></div>
        <div class="review-row"><span class="lbl">Applicant Email</span>
            <span class="val">${esc(app.applicant_email)}</span></div>
        <div class="review-row"><span class="lbl">Submitted</span>
            <span class="val">${esc(app.created_at)}</span></div>
    </div>`;

    // ── Branches ──────────────────────────────────────────────────────────────
    data.branches.forEach((br, bi) => {
        const brLabel = bi === 0 ? 'Branch 1 — Main Branch' : `Branch ${bi + 1}`;
        const addr    = [br.address_barangay, br.address_city, br.address_province,
                         br.address_region, br.address_street, br.address_zip]
                         .filter(Boolean).join(', ');

        html += `<div class="review-section">
            <h4><i class="fas fa-code-branch" style="margin-right:5px;color:var(--primary-500);"></i>${esc(brLabel)}</h4>
            <div class="review-row"><span class="lbl">Branch Type</span>
                <span class="val">${esc(BRANCH_TYPES[br.branch_type] || br.branch_type)}</span></div>
            <div class="review-row"><span class="lbl">Halal Status</span>
                <span class="val">${br.cert_status === 'certified'
                    ? '<span style="color:#059669;font-weight:600;">Certified</span>'
                    : '<span style="color:#d97706;font-weight:600;">Not Certified</span>'}</span></div>
            <div class="review-row"><span class="lbl">Address</span>
                <span class="val">${esc(addr || '—')}</span></div>`;

        // Documents
        if (br.documents && br.documents.length) {
            html += `<div style="margin-top:10px;"><span style="font-size:.78rem;font-weight:600;
                color:var(--neutral-500);display:block;margin-bottom:6px;">Documents</span>`;
            br.documents.forEach(doc => {
                const lbl  = DOC_LABELS[doc.doc_type] || doc.doc_type;
                const url  = `${SCREENING_PREVIEW_URL}?application_id=${encodeURIComponent(app.id)}&source_type=branch_document&source_document_id=${encodeURIComponent(doc.id)}`;
                const size = fmtSize(parseInt(doc.file_size));
                const icon = isImage(doc.file_path) ? 'fa-file-image' :
                             doc.file_path.endsWith('.pdf') ? 'fa-file-pdf' : 'fa-file-alt';
                html += `<a href="${url}" target="_blank" rel="noopener" class="doc-chip">
                    <i class="fas ${icon}"></i>
                    <span>${esc(lbl)}</span>
                    ${size ? `<span style="font-size:.7rem;color:var(--neutral-400);">${size}</span>` : ''}
                </a>`;
            });
            html += `</div>`;
        }

        // Raw materials
        if (br.raw_materials && br.raw_materials.length) {
            const inhLabel = br.inherits_raw_materials
                ? '<em style="color:var(--neutral-400);font-size:.8rem;">(Inherits from Main Branch)</em>' : '';
            html += `<div style="margin-top:12px;">
                <span style="font-size:.78rem;font-weight:600;color:var(--neutral-500);
                    display:block;margin-bottom:6px;">Raw Material Items ${inhLabel}</span>`;
            br.raw_materials.forEach((item, ii) => {
                const hsBadge = {certified:'#ecfdf5|#059669',non_certified:'#fef2f2|#dc2626',pending:'#fef9c3|#d97706'}
                                [item.halal_status] || '#f1f5f9|#475569';
                const [hsBg, hsCol] = hsBadge.split('|');
                html += `<div class="rm-item-card">
                    <h5>Item ${ii + 1}: ${esc(item.item_name)}</h5>
                    <div class="rm-grid">
                        <span class="lbl">Manufacturer</span>
                        <span class="val">${esc(item.manufacturer || '—')}</span>
                        <span class="lbl">Halal Status</span>
                        <span class="val"><span style="background:${hsBg};color:${hsCol};
                            padding:2px 8px;border-radius:12px;font-size:.75rem;font-weight:600;">
                            ${esc(item.halal_status.replace('_',' '))}</span></span>
                        <span class="lbl">Cert. Body</span>
                        <span class="val">${esc(item.certification_body || '—')}</span>
                    </div>`;
                if (item.image_front_path || item.image_back_path) {
                    html += `<div style="display:flex;gap:10px;margin-top:10px;">`;
                    if (item.image_front_path) {
                        html += `<div style="text-align:center;">
                            <img src="${UPLOAD_BASE}${esc(item.image_front_path)}"
                                 class="rm-img" onclick="lightbox(this.src)" alt="Front">
                            <div style="font-size:.68rem;color:var(--neutral-400);margin-top:3px;">Front</div>
                        </div>`;
                    }
                    if (item.has_back_logo && item.image_back_path) {
                        html += `<div style="text-align:center;">
                            <img src="${UPLOAD_BASE}${esc(item.image_back_path)}"
                                 class="rm-img" onclick="lightbox(this.src)" alt="Back">
                            <div style="font-size:.68rem;color:var(--neutral-400);margin-top:3px;">Back</div>
                        </div>`;
                    }
                    html += `</div>`;
                }
                html += `</div>`;
            });
            html += `</div>`;
        }
        html += `</div>`;
    });

    html += renderDocumentScreening(data.screening, app);

    // ── Admin notes (if already reviewed) ────────────────────────────────────
    if (app.admin_notes) {
        html += `<div class="review-section">
            <h4><i class="fas fa-sticky-note" style="margin-right:5px;color:var(--primary-500);"></i>Admin Notes</h4>
            <p style="font-size:.875rem;color:var(--neutral-700);margin:0;white-space:pre-wrap;">${esc(app.admin_notes)}</p>
        </div>`;
    }

    // ── Decision panel ────────────────────────────────────────────────────────
    const isLocked = ['approved','rejected'].includes(app.status);
    html += `<div class="decision-panel">
        <h4><i class="fas fa-gavel" style="margin-right:6px;color:var(--primary-500);"></i>Decision</h4>`;

    if (isLocked) {
        const sc = STATUS_COLORS[app.status] || {};
        html += `<p style="font-size:.875rem;color:var(--neutral-600);margin:0;">
            This application has already been
            <strong style="color:${sc.color||'inherit'};">${app.status}</strong>.
            ${app.halal_classification && app.halal_classification !== 'N/A'
                ? `Halal classification assigned: <strong>${esc(app.halal_classification)}</strong>.` : ''}
            ${app.reviewer_name ? `Reviewed by ${esc(app.reviewer_name)}.` : ''}
        </p>`;
    } else {
        html += `<form method="POST" action="${ACTION_URL}" id="decisionForm">
            <input type="hidden" name="app_id" value="${app.id}">
            <div style="margin-bottom:14px;">
                <label style="font-size:.82rem;font-weight:600;color:var(--neutral-700);display:block;margin-bottom:6px;">
                    Action <span style="color:red;">*</span>
                </label>
                <div style="display:flex;gap:8px;flex-wrap:wrap;" id="actionBtns">
                    ${['under_review','returned','rejected','approved'].map(a => {
                        const colors = {under_review:'btn-outline',returned:'btn-outline',
                                        rejected:'btn-danger',approved:'btn-success'};
                        const icons  = {under_review:'fa-search',returned:'fa-undo',
                                        rejected:'fa-times-circle',approved:'fa-check-circle'};
                        const labels = {under_review:'Mark Under Review',returned:'Return for Revision',
                                        rejected:'Reject',approved:'Approve'};
                        return `<button type="button" class="btn btn-sm ${colors[a]}"
                                    data-action="${a}" onclick="selectAction(this)">
                                    <i class="fas ${icons[a]}"></i> ${labels[a]}
                                </button>`;
                    }).join('')}
                </div>
                <input type="hidden" name="action" id="actionInput" value="">
            </div>

            <div id="hclassRow" style="display:none;margin-bottom:14px;">
                <label style="font-size:.82rem;font-weight:600;color:var(--neutral-700);display:block;margin-bottom:6px;">
                    Halal Classification <span style="color:red;">*</span>
                </label>
                <select name="halal_classification" class="form-control" style="max-width:260px;">
                    <option value="">-- Select --</option>
                    <option value="Halal">Halal</option>
                    <option value="Muslim Friendly">Muslim Friendly</option>
                    <option value="Non-Pork">Non-Pork</option>
                </select>
            </div>

            <div style="margin-bottom:16px;">
                <label style="font-size:.82rem;font-weight:600;color:var(--neutral-700);display:block;margin-bottom:6px;">
                    Notes / Feedback <span id="notesRequiredHint" style="display:none;color:red;">*</span>
                </label>
                <textarea name="admin_notes" class="form-control" rows="3"
                    placeholder="Optional — provide context, reasons, or instructions for the applicant."
                    style="font-size:.875rem;"></textarea>
            </div>

            <button type="submit" id="decisionSubmitBtn" class="btn btn-primary" disabled
                    style="min-width:160px;">
                <i class="fas fa-paper-plane"></i> Submit Decision
            </button>
        </form>`;
    }
    html += `</div>`;

    document.getElementById('reviewDrawerBody').innerHTML = html;
}

function screeningStatus(job) {
    if (!job) return '<span style="color:#64748b;">Not processed</span>';
    const labels = {
        queued:['#eff6ff','#1d4ed8','Queued'], processing:['#eff6ff','#1d4ed8','Processing'],
        completed:['#ecfdf5','#065f46','Processed'], requires_review:['#fff7ed','#9a3412','Review needed'],
        failed:['#fef2f2','#991b1b','Processing failed']
    };
    const status = labels[job.status] || ['#f1f5f9','#475569',esc(job.status || 'Unknown')];
    return `<span style="background:${status[0]};color:${status[1]};padding:2px 8px;border-radius:12px;font-size:.72rem;font-weight:700;">${status[2]}</span>`;
}
function readableFieldName(value) {
    return String(value || '').replace(/_/g, ' ').replace(/\b\w/g, c => c.toUpperCase());
}
function screeningFindingTitle(finding) {
    if (finding.rule_id === 'ai_contextual_explanation') return 'AI review context';
    if (finding.outcome === 'missing_information' && finding.field_key) {
        return `Missing ${readableFieldName(finding.field_key)}`;
    }
    return readableFieldName(finding.outcome);
}
function setScreeningFeedback(message, type = 'info') {
    const feedback = document.getElementById('screeningActionFeedback');
    if (!feedback) return;
    const palette = type === 'error' ? ['#fef2f2', '#991b1b'] : ['#ecfdf5', '#065f46'];
    feedback.style.display = 'block';
    feedback.style.background = palette[0];
    feedback.style.color = palette[1];
    feedback.textContent = message;
}
function renderDocumentScreening(screening, app) {
    if (!screening || !screening.installed) {
        return `<div class="review-section"><h4><i class="fas fa-search" style="margin-right:5px;color:var(--primary-500);"></i>Intelligent Document Screening</h4><p style="margin:0;font-size:.85rem;color:var(--neutral-600);">${esc((screening && screening.message) || 'Screening is not installed.')} Existing document review remains available.</p></div>`;
    }
    const summary = screening.summary || {};
    const enabled = !!screening.enabled && DOCUMENT_SCREENING_ENABLED;
    const metrics = [
        ['Submitted', summary.submitted_documents || 0], ['Processed', summary.processed_documents || 0],
        ['Review needed', summary.documents_requiring_review || 0], ['Missing required', summary.missing_required_documents || 0],
        ['Expired', summary.expired_documents || 0]
    ];
    let html = `<div class="review-section"><h4><i class="fas fa-search" style="margin-right:5px;color:var(--primary-500);"></i>Intelligent Document Screening</h4>
        <p style="font-size:.78rem;color:var(--neutral-500);margin:0 0 10px;">Screening identifies administrative review priorities only; it does not prove authenticity or make an application decision.</p>
        <div id="screeningActionFeedback" role="status" style="display:none;margin:0 0 10px;padding:7px 9px;border-radius:5px;font-size:.78rem;"></div>
        <div style="display:grid;grid-template-columns:repeat(2,minmax(0,1fr));gap:7px;margin-bottom:12px;">${metrics.map(metric => `<div style="background:var(--neutral-50);border-radius:6px;padding:7px;"><span style="font-size:.68rem;color:var(--neutral-500);display:block;">${metric[0]}</span><strong style="font-size:.95rem;">${metric[1]}</strong></div>`).join('')}</div>`;
    if (enabled) {
        html += `<button type="button" class="btn btn-sm btn-outline" onclick="startDocumentScreening(${app.id})" style="margin-bottom:10px;"><i class="fas fa-play"></i> Screen submitted documents</button>`;
    } else {
        html += `<p style="font-size:.78rem;color:#92400e;margin:0 0 10px;">Screening is disabled by server configuration. Manual review is unaffected.</p>`;
    }
    (screening.missing_required_documents || []).forEach(item => {
        html += `<div style="font-size:.78rem;color:#9a3412;margin:4px 0;"><i class="fas fa-exclamation-triangle"></i> Missing ${esc(readableFieldName(item.document_type))} for branch #${item.branch_id}</div>`;
    });
    (screening.documents || []).forEach(doc => {
        const previewUrl = `${SCREENING_PREVIEW_URL}?application_id=${encodeURIComponent(app.id)}&source_type=${encodeURIComponent(doc.source_type)}&source_document_id=${encodeURIComponent(doc.source_document_id)}`;
        html += `<div style="border-top:1px solid var(--neutral-100);padding-top:9px;margin-top:9px;"><div style="display:flex;justify-content:space-between;gap:8px;align-items:flex-start;"><div><a href="${previewUrl}" target="_blank" rel="noopener" style="font-size:.82rem;font-weight:700;color:var(--primary-700);">${esc(doc.original_name || readableFieldName(doc.doc_type))}</a><div style="font-size:.72rem;color:var(--neutral-500);">${esc(readableFieldName(doc.doc_type))}</div></div>${screeningStatus(doc.screening_job)}</div>`;
        if (enabled) html += `<button type="button" class="btn btn-sm btn-outline" style="margin-top:6px;font-size:.72rem;" onclick="startDocumentScreening(${app.id}, '${doc.source_type}', ${doc.source_document_id})"><i class="fas fa-redo"></i> Screen this document</button>`;
        if ((doc.fields || []).length) {
            html += `<div style="margin-top:7px;font-size:.76rem;">`;
            doc.fields.filter(field => field.raw_value).slice(0, 12).forEach(field => {
                html += `<div style="display:grid;grid-template-columns:105px 1fr;gap:5px;margin:3px 0;"><span style="color:var(--neutral-500);">${esc(readableFieldName(field.field_key))}</span><span>${esc(field.raw_value)}${field.is_uncertain == 1 ? ' <em style="color:#b45309;">(uncertain)</em>' : ''}</span></div>`;
            });
            html += `</div>`;
        }
        (doc.findings || []).filter(finding => (!['matched','not_applicable'].includes(finding.outcome) || finding.rule_id === 'ai_contextual_explanation') && finding.rule_id !== 'screening_priority').forEach(finding => {
            const color = ['high','critical'].includes(finding.severity) ? '#991b1b' : '#9a3412';
            const compared = (finding.submitted_value || finding.extracted_value) ? `<div style="margin-top:4px;color:var(--neutral-600);">Submitted: ${esc(finding.submitted_value || '—')}<br>Extracted: ${esc(finding.extracted_value || '—')}</div>` : '';
            const hasExtractedValue = String(finding.extracted_value || '').trim() !== '';
            const controls = finding.review_status === 'open'
                ? `<div style="margin-top:6px;display:flex;gap:5px;flex-wrap:wrap;"><button type="button" class="btn btn-sm btn-outline" onclick="submitScreeningReview(${app.id},${finding.id},'reviewed')">Mark reviewed</button>${hasExtractedValue ? `<button type="button" class="btn btn-sm btn-outline" onclick="submitScreeningReview(${app.id},${finding.id},'confirm_extracted')">Confirm extracted</button>` : ''}<button type="button" class="btn btn-sm btn-outline" onclick="showScreeningReviewForm(${app.id},${finding.id},'correct_ocr')">Correct OCR</button><button type="button" class="btn btn-sm btn-outline" onclick="showScreeningReviewForm(${app.id},${finding.id},'request_clarification')">Request clarification</button></div><div id="screeningReviewForm${finding.id}"></div>`
                : `<div style="margin-top:4px;color:var(--neutral-500);">${esc(finding.review_status)}${finding.corrected_value ? ': ' + esc(finding.corrected_value) : ''}</div>`;
            const heading = screeningFindingTitle(finding);
            html += `<div style="margin-top:7px;padding:7px;background:#fffaf0;border-left:3px solid ${color};font-size:.76rem;"><strong style="color:${color};">${esc(heading)}</strong><br>${esc(finding.explanation)}${compared}${controls}</div>`;
        });
        html += `</div>`;
    });
    return html + `</div>`;
}
function startDocumentScreening(applicationId, sourceType = '', sourceDocumentId = 0) {
    const form = new FormData();
    form.append('action', 'start_screening');
    form.append('application_id', applicationId);
    form.append('csrf_token', DOCUMENT_SCREENING_CSRF);
    if (sourceType) { form.append('source_type', sourceType); form.append('source_document_id', sourceDocumentId); }
    fetch(SCREENING_URL, {method:'POST', body:form}).then(response => response.json()).then(data => {
        if (!data.success) throw new Error(data.message || 'Screening could not be started.');
        openReview(applicationId);
    }).catch(error => setScreeningFeedback(error.message || 'Screening could not be started.', 'error'));
}
function showScreeningReviewForm(applicationId, findingId, action) {
    const container = document.getElementById(`screeningReviewForm${findingId}`);
    if (!container) return;
    const isCorrection = action === 'correct_ocr';
    const label = isCorrection ? 'Corrected transcription' : 'Message to applicant';
    const placeholder = isCorrection
        ? 'Enter the value shown on the original document'
        : 'Explain the information or updated evidence needed';
    const hint = isCorrection
        ? 'This correction is saved only to the screening review record; it does not overwrite the original upload or application data.'
        : 'Use an applicant-facing message. Do not include internal review notes.';
    const reviewerNote = isCorrection
        ? `<label style="display:block;font-weight:600;margin:6px 0 4px;">Reviewer note (optional)</label><input id="screeningReviewComment${findingId}" class="form-control" type="text" maxlength="4000" placeholder="Internal review note">`
        : '';
    container.innerHTML = `<div style="margin-top:7px;padding:8px;background:#f8fafc;border:1px solid var(--neutral-200);border-radius:5px;"><label style="display:block;font-weight:600;margin-bottom:4px;">${label}</label><textarea id="screeningReviewValue${findingId}" class="form-control" rows="${isCorrection ? 2 : 3}" placeholder="${placeholder}"></textarea><div style="margin:4px 0;color:var(--neutral-500);font-size:.72rem;">${hint}</div>${reviewerNote}<div style="display:flex;gap:5px;margin-top:7px;"><button type="button" class="btn btn-sm btn-primary" onclick="submitScreeningReview(${applicationId},${findingId},'${action}')">Save</button><button type="button" class="btn btn-sm btn-outline" onclick="this.closest('[id^=screeningReviewForm]').innerHTML=''">Cancel</button></div></div>`;
}
function submitScreeningReview(applicationId, findingId, action) {
    const corrected = document.getElementById(`screeningReviewValue${findingId}`)?.value.trim() || '';
    const comment = document.getElementById(`screeningReviewComment${findingId}`)?.value.trim() || '';
    if (action === 'correct_ocr' && !corrected) {
        setScreeningFeedback('Enter the corrected transcription before saving.', 'error');
        return;
    }
    if (action === 'request_clarification' && !corrected) {
        setScreeningFeedback('Enter the message to send to the applicant.', 'error');
        return;
    }
    const form = new FormData();
    form.append('action', action === 'request_clarification' ? 'request_clarification' : 'review_finding');
    form.append('application_id', applicationId);
    form.append('finding_id', findingId);
    form.append('csrf_token', DOCUMENT_SCREENING_CSRF);
    if (action !== 'request_clarification') form.append('review_action', action);
    if (corrected) form.append('corrected_value', corrected);
    if (action === 'request_clarification') form.append('reviewer_comment', corrected);
    else if (comment) form.append('reviewer_comment', comment);
    fetch(SCREENING_URL, {method:'POST', body:form}).then(response => response.json()).then(data => {
        if (!data.success) throw new Error(data.message || 'Review could not be saved.');
        openReview(applicationId);
    }).catch(error => setScreeningFeedback(error.message || 'Review could not be saved.', 'error'));
}

// ── Action selector ───────────────────────────────────────────────────────────
function selectAction(btn) {
    document.querySelectorAll('#actionBtns button').forEach(b => {
        b.style.outline = '';
        b.style.boxShadow = '';
    });
    btn.style.outline    = '2px solid var(--primary-600)';
    btn.style.boxShadow  = '0 0 0 3px rgba(37,99,235,.18)';

    const action  = btn.dataset.action;
    document.getElementById('actionInput').value = action;
    document.getElementById('hclassRow').style.display = action === 'approved' ? '' : 'none';
    document.getElementById('notesRequiredHint').style.display =
        ['rejected','returned'].includes(action) ? '' : 'none';
    document.getElementById('decisionSubmitBtn').disabled = false;
    document.getElementById('decisionSubmitBtn').textContent = '';
    document.getElementById('decisionSubmitBtn').innerHTML =
        `<i class="fas fa-paper-plane"></i> Submit — ${btn.textContent.trim()}`;
}

// ── Simple image lightbox ─────────────────────────────────────────────────────
function lightbox(src) {
    const el = document.createElement('div');
    el.style.cssText = 'position:fixed;inset:0;background:rgba(0,0,0,.85);z-index:2000;' +
        'display:flex;align-items:center;justify-content:center;cursor:zoom-out;';
    el.innerHTML = `<img src="${src}" style="max-width:90vw;max-height:90vh;border-radius:10px;
        box-shadow:0 8px 40px rgba(0,0,0,.5);">`;
    el.onclick = () => el.remove();
    document.body.appendChild(el);
}
</script>

<?php require_once __DIR__ . '/../../includes/footer.php'; ?>
