<?php
require_once __DIR__ . '/../../config/app.php';
requireLogin();
requireRole([ROLE_BUSINESS_OWNER]);

$page_title = 'Applications';
$page_heading = 'HDP Application';
$is_dashboard = true;
$breadcrumbs = [['label' => 'Dashboard', 'url' => BASE_URL . 'dashboard/business_owner/'], ['label' => 'Applications']];
$user_id = $_SESSION['user_id'];
$action = $_GET['action'] ?? 'list';
$error = '';
$success = '';

// The fixed requirement labels from the official form
$requirement_labels = [
    '1. Letter of Intent',
    '2.1 Company Profile',
    '2.2 Name of products/menu and corresponding ingredients',
    '2.3 List of raw materials/ingredients with source',
    '2.4 Halal Certificate of meat products',
    '2.5 Kitchen layout',
    '2.6 Alcohol and liquor prohibition compliance',
    '2.7 Halal Certification for ingredients',
    '2.8 Type of packaging materials',
    '2.9 GMP, HACCP, SSOP, GHP, TQM, ISO',
    '2.10 Pest Control, SEC Registration, License to Operate',
    '2.11 Flow chart of processing / storage / equipment',
    '2.12 Transportation used for Halal Products',
    '2.13 Storage of raw materials and finished products',
    '2.14 Waste disposal management',
    '2.15 Halal Prayer room',
    '2.16 Appointment of Muslim cook and crew',
    '2.17 Halal Assurance System Manual',
];

// Get the business owner's verified LOI
$loi_stmt = $conn->prepare("SELECT * FROM letter_of_intent WHERE business_owner_id = ? AND status = 'verified' LIMIT 1");
$loi_stmt->bind_param("i", $user_id);
$loi_stmt->execute();
$verified_loi = $loi_stmt->get_result()->fetch_assoc();

// Get or auto-create the application tied to the verified LOI
$application = null;
if ($verified_loi) {
    $app_stmt = $conn->prepare("SELECT * FROM hdp_applications WHERE business_owner_id = ? AND loi_id = ? LIMIT 1");
    $app_stmt->bind_param("ii", $user_id, $verified_loi['id']);
    $app_stmt->execute();
    $application = $app_stmt->get_result()->fetch_assoc();

    if (!$application) {
        // Auto-create draft application from LOI
        $ins = $conn->prepare("INSERT INTO hdp_applications (loi_id, business_owner_id, status) VALUES (?, ?, 'draft')");
        $ins->bind_param("ii", $verified_loi['id'], $user_id);
        if ($ins->execute()) {
            $app_id = $conn->insert_id;
            $app_stmt2 = $conn->prepare("SELECT * FROM hdp_applications WHERE id = ?");
            $app_stmt2->bind_param("i", $app_id);
            $app_stmt2->execute();
            $application = $app_stmt2->get_result()->fetch_assoc();
        }
    }
}

// Handle TOR response from business owner
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['tor_response']) && $application) {
    $tor_id   = intval($_POST['tor_id']);
    $response = sanitize($_POST['response_action']); // 'accepted' or 'rejected'
    $remarks  = sanitize($_POST['business_remarks'] ?? '');

    $upd = $conn->prepare("UPDATE terms_of_reference SET business_response=?, business_remarks=?, responded_at=NOW() WHERE id=? AND application_id=?");
    $upd->bind_param("ssii", $response, $remarks, $tor_id, $application['id']);
    if ($upd->execute()) {
        // Notify evaluator
        $eval_q = $conn->prepare("SELECT evaluator_id FROM terms_of_reference WHERE id=?");
        $eval_q->bind_param("i", $tor_id);
        $eval_q->execute();
        $eval_row = $eval_q->get_result()->fetch_assoc();
        if ($eval_row) {
            $msg = $response === 'accepted'
                ? $_SESSION['full_name'] . ' has accepted the Terms of Reference.'
                : $_SESSION['full_name'] . ' has rejected the Terms of Reference and left remarks.';
            createNotification($conn, $eval_row['evaluator_id'],
                'TOR Response: ' . ucfirst($response),
                $msg,
                $response === 'accepted' ? 'success' : 'warning',
                BASE_URL . 'dashboard/evaluator/terms_of_reference.php?action=view&id=' . $tor_id);
        }
        $success = 'Your response has been submitted.';
    }
}

// Handle file uploads per requirement
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['upload_requirement']) && $application) {
    $app_id   = intval($application['id']);
    $label    = sanitize($_POST['requirement_label'] ?? '');
    $allowed  = ['pdf', 'png', 'jpg', 'jpeg'];

    if (isset($_FILES['req_file']) && $_FILES['req_file']['error'] === UPLOAD_ERR_OK) {
        $upload = uploadFile($_FILES['req_file'], 'documents', $allowed);
        if ($upload['success']) {
            // Replace existing upload for same label
            $del = $conn->prepare("DELETE FROM application_requirement_uploads WHERE application_id = ? AND requirement_label = ?");
            $del->bind_param("is", $app_id, $label);
            $del->execute();

            $ins = $conn->prepare("INSERT INTO application_requirement_uploads (application_id, requirement_label, file_path) VALUES (?, ?, ?)");
            $ins->bind_param("iss", $app_id, $label, $upload['path']);
            $ins->execute();
            $success = 'File uploaded for: ' . $label;
        } else {
            $error = $upload['message'];
        }
    } else {
        $error = 'Please select a valid file (PDF, PNG, JPG, JPEG).';
    }
}

// Handle final submission
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['submit_application']) && $application) {
    $app_id = intval($application['id']);
    $upd = $conn->prepare("UPDATE hdp_applications SET status = 'submitted', submitted_at = NOW() WHERE id = ? AND business_owner_id = ?");
    $upd->bind_param("ii", $app_id, $user_id);
    if ($upd->execute()) {
        // Notify evaluators
        $eval_query = $conn->query("SELECT id FROM users WHERE role_id = " . ROLE_EVALUATOR . " AND role_status = 'approved'");
        while ($eval = $eval_query->fetch_assoc()) {
            createNotification($conn, $eval['id'], 'New HDP Application',
                $_SESSION['full_name'] . ' has submitted an HDP application.',
                'action_required', BASE_URL . 'dashboard/evaluator/verify_applications.php');
        }
        $success = 'Application submitted successfully!';
        // Refresh
        $app_stmt3 = $conn->prepare("SELECT * FROM hdp_applications WHERE id = ?");
        $app_stmt3->bind_param("i", $app_id);
        $app_stmt3->execute();
        $application = $app_stmt3->get_result()->fetch_assoc();
    }
}

// Load uploaded files for the application
$uploaded_files = [];
if ($application) {
    $uf = $conn->prepare("SELECT * FROM application_requirement_uploads WHERE application_id = ?");
    $uf->bind_param("i", $application['id']);
    $uf->execute();
    foreach ($uf->get_result()->fetch_all(MYSQLI_ASSOC) as $row) {
        $uploaded_files[$row['requirement_label']] = $row;
    }
}

// Load TOR for this application if any
$tor = null;
if ($application) {
    $tor_q = $conn->prepare("SELECT * FROM terms_of_reference WHERE application_id = ? ORDER BY created_at DESC LIMIT 1");
    $tor_q->bind_param("i", $application['id']);
    $tor_q->execute();
    $tor = $tor_q->get_result()->fetch_assoc();
}

require_once __DIR__ . '/../../includes/header.php';
?>

<?php if ($success): ?>
    <div class="alert alert-success"><i class="fas fa-check-circle"></i><span><?= $success ?></span><button class="close-alert"><i class="fas fa-times"></i></button></div>
<?php endif; ?>
<?php if ($error): ?>
    <div class="alert alert-danger"><i class="fas fa-exclamation-circle"></i><span><?= $error ?></span><button class="close-alert"><i class="fas fa-times"></i></button></div>
<?php endif; ?>

<?php if (!$verified_loi): ?>
<div class="empty-state">
    <div class="empty-icon"><i class="fas fa-file-alt"></i></div>
    <h3>No Verified LOI</h3>
    <p>Your Letter of Intent must be approved by an evaluator before you can submit an application.</p>
    <a href="<?= BASE_URL ?>dashboard/business_owner/letter_of_intent.php" class="btn btn-primary">
        <i class="fas fa-arrow-right"></i> Go to Letter of Intent
    </a>
</div>

<?php elseif ($application): ?>

<div style="display: grid; grid-template-columns: 1fr 320px; gap: 24px; align-items: start;">

    <!-- Requirements Upload -->
    <div class="card">
        <div class="card-header">
            <h3><i class="fas fa-clipboard-list" style="color:var(--primary-600);margin-right:8px;"></i>
                <?= htmlspecialchars($verified_loi['company_name']) ?> — Application Requirements
            </h3>
            <?= getStatusBadge($application['status']) ?>
        </div>
        <div class="card-body" style="padding: 0;">
            <table class="table" style="margin: 0;">
                <thead>
                    <tr>
                        <th style="width: 40%;">Requirement</th>
                        <th>Uploaded File</th>
                        <th style="width: 220px;">Upload</th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($requirement_labels as $label): ?>
                    <?php $uploaded = $uploaded_files[$label] ?? null; ?>
                    <tr>
                        <td style="font-size: 0.9rem; color: var(--neutral-700); vertical-align: middle;">
                            <?= htmlspecialchars($label) ?>
                        </td>
                        <td style="vertical-align: middle;">
                            <?php if ($uploaded): ?>
                                <a href="<?= BASE_URL ?>uploads/<?= $uploaded['file_path'] ?>" target="_blank"
                                   style="font-size:0.85rem;color:var(--primary-600);display:inline-flex;align-items:center;gap:6px;">
                                    <i class="fas fa-file"></i> View file
                                </a>
                                <br><small style="color:var(--neutral-400);"><?= formatDate($uploaded['uploaded_at']) ?></small>
                            <?php else: ?>
                                <span style="font-size:0.8rem;color:var(--neutral-400);">Not uploaded</span>
                            <?php endif; ?>
                        </td>
                        <td style="vertical-align: middle;">
                            <?php if (in_array($application['status'], ['draft', 'incomplete'])): ?>
                            <form method="POST" enctype="multipart/form-data" class="req-upload-form">
                                <input type="hidden" name="upload_requirement" value="1">
                                <input type="hidden" name="requirement_label" value="<?= htmlspecialchars($label) ?>">
                                <div class="req-dropzone" onclick="this.querySelector('input[type=file]').click()"
                                     ondragover="event.preventDefault();this.classList.add('drag-over')"
                                     ondragleave="this.classList.remove('drag-over')"
                                     ondrop="handleDrop(event,this)">
                                    <i class="fas fa-cloud-upload-alt" style="font-size:1.6rem;color:var(--primary-500);margin-bottom:6px;"></i>
                                    <div style="font-weight:600;font-size:0.8rem;color:var(--neutral-700);">Click to upload or drag and drop</div>
                                    <div style="font-size:0.75rem;color:var(--neutral-400);margin-top:2px;">PDF, JPG, PNG (max 5MB)</div>
                                    <input type="file" name="req_file" accept=".pdf,.png,.jpg,.jpeg"
                                           style="display:none;" onchange="this.closest('form').submit()">
                                </div>
                            </form>
                            <?php else: ?>
                                <span style="font-size:0.8rem;color:var(--neutral-400);">—</span>
                            <?php endif; ?>
                        </td>
                    </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    </div>

    <!-- Summary Panel -->
    <div class="card" style="position:sticky;top:20px;">
        <div class="card-header">
            <h3><i class="fas fa-info-circle" style="color:var(--primary-600);margin-right:8px;"></i> Summary</h3>
        </div>
        <div class="card-body">
            <div style="margin-bottom:16px;">
                <div style="font-size:0.8rem;color:var(--neutral-500);text-transform:uppercase;font-weight:600;">Company</div>
                <div style="font-weight:600;"><?= htmlspecialchars($verified_loi['company_name']) ?></div>
            </div>
            <div style="margin-bottom:16px;">
                <div style="font-size:0.8rem;color:var(--neutral-500);text-transform:uppercase;font-weight:600;">Status</div>
                <?= getStatusBadge($application['status']) ?>
            </div>
            <div style="margin-bottom:20px;">
                <div style="font-size:0.8rem;color:var(--neutral-500);text-transform:uppercase;font-weight:600;margin-bottom:6px;">Upload Progress</div>
                <?php $uploaded_count = count($uploaded_files); $total = count($requirement_labels); ?>
                <div style="background:var(--neutral-100);border-radius:99px;height:8px;overflow:hidden;">
                    <div style="background:var(--primary-500);height:100%;width:<?= round($uploaded_count/$total*100) ?>%;transition:width .3s;"></div>
                </div>
                <div style="font-size:0.8rem;color:var(--neutral-500);margin-top:4px;"><?= $uploaded_count ?> / <?= $total ?> uploaded</div>
            </div>

            <?php if (in_array($application['status'], ['draft', 'incomplete'])): ?>
            <form method="POST">
                <input type="hidden" name="submit_application" value="1">
                <button type="submit" class="btn btn-primary" style="width:100%;"
                    onclick="return confirm('Submit your application? Make sure all required files are uploaded.')">
                    <i class="fas fa-paper-plane"></i> Submit Application
                </button>
            </form>
            <?php elseif ($application['status'] === 'submitted'): ?>
            <div class="alert alert-info" style="margin:0;font-size:0.85rem;">
                <i class="fas fa-clock"></i> Application submitted on <?= formatDate($application['submitted_at']) ?>. Awaiting review.
            </div>
            <?php else: ?>
            <div style="text-align:center;"><?= getStatusBadge($application['status']) ?></div>
            <?php endif; ?>
        </div>
    </div>

</div>
<?php endif; ?>

<?php if ($tor): ?>
<!-- Terms of Reference -->
<div class="card" style="margin-top: 24px;">
    <div class="card-header">
        <h3><i class="fas fa-file-contract" style="color:var(--primary-600);margin-right:8px;"></i> Terms of Reference</h3>
        <?php
            $resp = $tor['business_response'] ?? 'pending';
            $badge = ['pending' => 'badge-warning', 'accepted' => 'badge-success', 'rejected' => 'badge-danger'];
        ?>
        <span class="badge <?= $badge[$resp] ?>">Your response: <?= ucfirst($resp) ?></span>
    </div>
    <div style="display: grid; grid-template-columns: 2fr 1fr; gap: 0;">
        <!-- TOR Content -->
        <div style="padding: 32px 40px; border-right: 1px solid var(--neutral-100); white-space: pre-wrap; font-family: 'Times New Roman', serif; font-size: 0.95rem; line-height: 1.8; color: #111; max-height: 500px; overflow-y: auto;">
            <?= htmlspecialchars($tor['tor_content']) ?>
        </div>
        <!-- Response Panel -->
        <div style="padding: 24px;">
            <div style="margin-bottom:16px;">
                <div style="font-size:0.8rem;color:var(--neutral-500);text-transform:uppercase;font-weight:600;">Inspection Fee</div>
                <div style="font-weight:700;font-size:1.1rem;"><?= formatCurrency($tor['inspection_fees']) ?></div>
            </div>
            <div style="margin-bottom:20px;">
                <div style="font-size:0.8rem;color:var(--neutral-500);text-transform:uppercase;font-weight:600;">Sent On</div>
                <div><?= formatDate($tor['created_at']) ?></div>
            </div>

            <?php if ($resp === 'pending'): ?>
            <form method="POST">
                <input type="hidden" name="tor_response" value="1">
                <input type="hidden" name="tor_id" value="<?= $tor['id'] ?>">
                <div class="form-group">
                    <label>Remarks <small style="color:var(--neutral-400);">(required if rejecting)</small></label>
                    <textarea name="business_remarks" class="form-control" rows="4"
                        placeholder="If you disagree or want to negotiate any terms, explain here..."></textarea>
                </div>
                <div style="display:flex;flex-direction:column;gap:10px;margin-top:8px;">
                    <button type="submit" name="response_action" value="accepted" class="btn btn-success"
                        onclick="return confirm('Confirm you agree to the Terms of Reference?')">
                        <i class="fas fa-check-circle"></i> I Agree / Accept
                    </button>
                    <button type="submit" name="response_action" value="rejected" class="btn btn-danger">
                        <i class="fas fa-times-circle"></i> I Disagree / Send Remarks
                    </button>
                </div>
            </form>
            <?php elseif ($resp === 'accepted'): ?>
            <div class="alert alert-success" style="margin:0;">
                <i class="fas fa-check-circle"></i>
                <span>You have accepted the Terms of Reference on <?= formatDate($tor['responded_at']) ?>.</span>
            </div>
            <?php else: ?>
            <div class="alert alert-warning" style="margin:0 0 12px;">
                <i class="fas fa-exclamation-triangle"></i>
                <span>You submitted remarks on <?= formatDate($tor['responded_at']) ?>. Awaiting evaluator response.</span>
            </div>
            <?php if ($tor['business_remarks']): ?>
            <div style="background:var(--neutral-50);padding:12px;border-radius:8px;font-size:0.85rem;color:var(--neutral-700);">
                <strong>Your remarks:</strong><br><?= nl2br(htmlspecialchars($tor['business_remarks'])) ?>
            </div>
            <?php endif; ?>
            <?php endif; ?>
        </div>
    </div>
</div>
<?php endif; ?>

<?php require_once __DIR__ . '/../../includes/footer.php'; ?>
<style>
.req-dropzone {
    border: 2px dashed var(--neutral-300);
    border-radius: 10px;
    padding: 14px 10px;
    text-align: center;
    cursor: pointer;
    background: var(--neutral-50);
    transition: border-color .2s, background .2s;
    display: flex;
    flex-direction: column;
    align-items: center;
}
.req-dropzone:hover, .req-dropzone.drag-over {
    border-color: var(--primary-500);
    background: var(--primary-50);
}
</style>
<script>
function handleDrop(e, zone) {
    e.preventDefault();
    zone.classList.remove('drag-over');
    const file = e.dataTransfer.files[0];
    if (!file) return;
    const input = zone.querySelector('input[type=file]');
    const dt = new DataTransfer();
    dt.items.add(file);
    input.files = dt.files;
    input.closest('form').submit();
}
</script>
