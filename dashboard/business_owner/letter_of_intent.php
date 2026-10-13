<?php
require_once __DIR__ . '/../../config/app.php';
requireLogin();
requireRole([ROLE_BUSINESS_OWNER]);

$page_title = 'Letter of Intent';
$page_heading = 'Letter of Intent';
$is_dashboard = true;
$breadcrumbs = [['label' => 'Dashboard', 'url' => BASE_URL . 'dashboard/business_owner/'], ['label' => 'Letter of Intent']];
$user_id = $_SESSION['user_id'];
$action = $_GET['action'] ?? 'list';
$error = '';
$success = '';

// Handle create/edit
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $company_name = sanitize($_POST['company_name'] ?? '');
    $company_address = sanitize($_POST['company_address'] ?? '');
    $date_of_intent = sanitize($_POST['date_of_intent'] ?? '');
    // Build menu_list from dynamic items array
    $menu_items = $_POST['menu_items'] ?? [];
    $menu_items = array_filter(array_map('trim', $menu_items));
    $menu_list = implode("\n", $menu_items);
    $contact_person = sanitize($_POST['contact_person'] ?? '');
    $contact_email = sanitize($_POST['contact_email'] ?? '');
    $contact_phone = sanitize($_POST['contact_phone'] ?? '');
    $submit_action = $_POST['submit_action'] ?? 'draft';
    
    if (empty($company_name)) {
        $error = 'Company name is required.';
    } else {
        $status = ($submit_action === 'submit') ? 'submitted' : 'draft';
        $submitted_at = ($submit_action === 'submit') ? date('Y-m-d H:i:s') : null;
        
        if (isset($_POST['loi_id']) && $_POST['loi_id'] > 0) {
            // Update existing
            $stmt = $conn->prepare("UPDATE letter_of_intent SET company_name=?, company_address=?, menu_list=?, contact_person=?, contact_email=?, contact_phone=?, status=?, submitted_at=COALESCE(?, submitted_at), date_of_intent=? WHERE id=? AND business_owner_id=?");
            if (!$stmt) { $error = 'DB error: ' . $conn->error; } else {
            $stmt->bind_param("sssssssssii", $company_name, $company_address, $menu_list, $contact_person, $contact_email, $contact_phone, $status, $submitted_at, $date_of_intent, $_POST['loi_id'], $user_id);
            
            if ($stmt->execute()) {
                $success = $submit_action === 'submit' ? 'Letter of Intent submitted successfully!' : 'Draft saved.';
                if ($submit_action === 'submit') {
                    // Notify evaluators
                    $eval_query = $conn->query("SELECT id FROM users WHERE role_id = " . ROLE_EVALUATOR . " AND role_status = 'approved'");
                    while ($eval = $eval_query->fetch_assoc()) {
                        createNotification($conn, $eval['id'], 'New Letter of Intent', 
                            $_SESSION['full_name'] . ' has submitted a Letter of Intent for ' . $company_name,
                            'action_required', BASE_URL . 'dashboard/evaluator/verify_loi.php');
                    }
                }
                $action = 'list';
            } else {
                $error = 'Failed to save. Please try again.';
            }
            } // end if $stmt
        } else {
            // Insert new
            $stmt = $conn->prepare("INSERT INTO letter_of_intent (business_owner_id, company_name, company_address, menu_list, contact_person, contact_email, contact_phone, status, submitted_at, date_of_intent) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?)");
            $stmt->bind_param("isssssssss", $user_id, $company_name, $company_address, $menu_list, $contact_person, $contact_email, $contact_phone, $status, $submitted_at, $date_of_intent);
            
            if ($stmt->execute()) {
                $success = $submit_action === 'submit' ? 'Letter of Intent submitted successfully!' : 'Draft saved.';
                logActivity($conn, $user_id, 'LOI Created', 'Created LOI for ' . $company_name, 'loi');
                if ($submit_action === 'submit') {
                    $eval_query = $conn->query("SELECT id FROM users WHERE role_id = " . ROLE_EVALUATOR . " AND role_status = 'approved'");
                    while ($eval = $eval_query->fetch_assoc()) {
                        createNotification($conn, $eval['id'], 'New Letter of Intent', 
                            $_SESSION['full_name'] . ' has submitted a Letter of Intent for ' . $company_name,
                            'action_required', BASE_URL . 'dashboard/evaluator/verify_loi.php');
                    }
                }
                $action = 'list';
            } else {
                $error = 'Failed to create. Please try again.';
            }
        }
    }
}

// Get LOI for editing
$edit_loi = null;
if ($action === 'edit' && isset($_GET['id'])) {
    $stmt = $conn->prepare("SELECT * FROM letter_of_intent WHERE id = ? AND business_owner_id = ?");
    $stmt->bind_param("ii", $_GET['id'], $user_id);
    $stmt->execute();
    $edit_loi = $stmt->get_result()->fetch_assoc();
    if (!$edit_loi) {
        $action = 'list';
        $error = 'Letter of Intent not found.';
    }
}

// Get all LOIs for listing
$all_lois = [];
if ($action === 'list') {
    $stmt = $conn->prepare("SELECT loi.*, lv.verification_status, lv.feedback FROM letter_of_intent loi LEFT JOIN loi_verification lv ON loi.id = lv.loi_id WHERE loi.business_owner_id = ? ORDER BY loi.created_at DESC");
    $stmt->bind_param("i", $user_id);
    $stmt->execute();
    $all_lois = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
}

require_once __DIR__ . '/../../includes/header.php';
?>

<?php if ($success): ?>
    <div class="alert alert-success"><i class="fas fa-check-circle"></i><span><?= $success ?></span><button class="close-alert"><i class="fas fa-times"></i></button></div>
<?php endif; ?>
<?php if ($error): ?>
    <div class="alert alert-danger"><i class="fas fa-exclamation-circle"></i><span><?= $error ?></span><button class="close-alert"><i class="fas fa-times"></i></button></div>
<?php endif; ?>

<?php if ($action === 'list'): ?>
<!-- LOI List -->
<div class="card">
    <div class="card-header">
        <h3>My Letter of Intent</h3>
    </div>
    <div class="card-body" style="padding: 0;">
        <?php if (empty($all_lois)): ?>
            <div class="empty-state">
                <div class="empty-icon"><i class="fas fa-file-alt"></i></div>
                <h3>No Letter of Intent</h3>
                <p>Create your Letter of Intent to start the halal certification process.</p>
                <a href="?action=create" class="btn btn-primary"><i class="fas fa-plus"></i> Create LOI</a>
            </div>
        <?php else: $loi = $all_lois[0]; ?>
            <div style="padding: 24px; display: flex; align-items: center; justify-content: space-between; flex-wrap: wrap; gap: 16px;">
                <div>
                    <div style="font-size: 1.1rem; font-weight: 700; color: var(--neutral-900);"><?= htmlspecialchars($loi['company_name']) ?></div>
                    <div style="font-size: 0.85rem; color: var(--neutral-500); margin-top: 4px;">
                        Submitted: <?= $loi['submitted_at'] ? formatDate($loi['submitted_at']) : 'Not yet submitted' ?>
                    </div>
                </div>
                <div style="display: flex; align-items: center; gap: 12px;">
                    <?= getStatusBadge($loi['status']) ?>
                    <a href="?action=view&id=<?= $loi['id'] ?>" class="btn btn-sm btn-outline"><i class="fas fa-eye"></i> View</a>
                    <?php if (in_array($loi['status'], ['draft', 'returned'])): ?>
                        <a href="?action=edit&id=<?= $loi['id'] ?>" class="btn btn-sm btn-primary"><i class="fas fa-edit"></i> Edit</a>
                    <?php endif; ?>
                    <?php if ($loi['status'] === 'verified'): ?>
                        <a href="<?= BASE_URL ?>dashboard/business_owner/applications.php?action=create&loi_id=<?= $loi['id'] ?>" class="btn btn-sm btn-success"><i class="fas fa-arrow-right"></i> Proceed</a>
                    <?php endif; ?>
                </div>
            </div>
        <?php endif; ?>
    </div>
</div>

<?php elseif ($action === 'view' && isset($_GET['id'])): ?>
<?php
    $stmt = $conn->prepare("SELECT loi.*, lv.verification_status, lv.feedback, lv.verified_at, u.full_name as evaluator_name FROM letter_of_intent loi LEFT JOIN loi_verification lv ON loi.id = lv.loi_id LEFT JOIN users u ON lv.evaluator_id = u.id WHERE loi.id = ? AND loi.business_owner_id = ?");
    $stmt->bind_param("ii", $_GET['id'], $user_id);
    $stmt->execute();
    $view_loi = $stmt->get_result()->fetch_assoc();

    $req_row = null;
    if ($view_loi) {
        $rq = $conn->prepare("SELECT * FROM loi_requirements WHERE loi_id = ? ORDER BY sent_at DESC LIMIT 1");
        $rq->bind_param("i", $view_loi['id']);
        $rq->execute();
        $req_row = $rq->get_result()->fetch_assoc();
    }
?>
<?php if ($view_loi): ?>
<a href="?action=list" class="btn btn-outline btn-sm" style="margin-bottom: 20px;"><i class="fas fa-arrow-left"></i> Back to List</a>

<div style="display: grid; grid-template-columns: <?= $req_row ? '1fr 1fr' : '1fr' ?>; gap: 24px; align-items: start;">

    <!-- Formal Letter -->
    <div class="card">
        <div class="card-header">
            <h3><i class="fas fa-file-alt" style="color:var(--primary-600);margin-right:8px;"></i> Letter of Intent</h3>
            <?= getStatusBadge($view_loi['status']) ?>
        </div>
        <div class="card-body" style="padding: 40px 48px; font-family: 'Times New Roman', Times, serif; font-size: 1rem; line-height: 1.8; color: #111;">
            <p style="margin-bottom: 24px;">
                <?= $view_loi['date_of_intent'] ? date('d F Y', strtotime($view_loi['date_of_intent'])) : '[Day/Month/Year]' ?>
            </p>
            <p style="margin-bottom: 24px;">
                <strong>HADJI ABDULATIF S. SANGCUPAN</strong><br>
                President/CEO<br>
                Halal Development Institute of the Philippines (HDIP)<br>
                4th Flr. Unit 401, Central Bldg. 37 Arayat Cor. Malabito St.<br>
                Cubao, Quezon City
            </p>
            <p style="margin-bottom: 24px;">
                Subject: Letter of Intent for Halal Certification as <strong><?= htmlspecialchars($view_loi['company_name']) ?></strong>
            </p>
            <p>Dear Sir/Madam,</p>
            <p>I am writing to formally express the intent of <strong><?= htmlspecialchars($view_loi['company_name']) ?></strong> to apply for Halal Certification from the Halal Development Institute of the Philippines (HDIP) for our products/services.</p>
            <p>Our company, located at <strong><?= htmlspecialchars($view_loi['company_address']) ?></strong>, produces/serves
                <?php
                    $items = array_filter(explode("\n", $view_loi['menu_list'] ?? ''));
                    echo $items ? '<strong>' . htmlspecialchars(implode(', ', array_map('trim', $items))) . '</strong>' : '<strong>[Specific Product/Menu Item List]</strong>';
                ?>.
                We are committed to complying with all the requirements and standards set forth by the HDIP, including adherence to Shariah law, GMP (Good Manufacturing Practices), and hygiene standards.
            </p>
            <p>We have attached our company profile, product list, and other relevant documents for your preliminary review. We request an assessment of our facility for Halal compliance.</p>
            <p>Thank you for your assistance.</p>
            <p>Sincerely,</p>
            <p style="margin-top: 32px;">
                <strong><?= htmlspecialchars($view_loi['contact_person'] ?? $_SESSION['full_name']) ?></strong><br>
                <?= htmlspecialchars($_SESSION['full_name']) ?><br>
                <?= htmlspecialchars($view_loi['contact_phone'] ?? '[Phone Number]') ?><br>
                <?= htmlspecialchars($view_loi['contact_email'] ?? $_SESSION['email']) ?>
            </p>
        </div>
    </div>

    <?php if ($req_row): ?>
    <!-- Requirements from Evaluator -->
    <div class="card" style="position: sticky; top: 20px;">
        <div class="card-header">
            <h3><i class="fas fa-list-check" style="color:var(--primary-600);margin-right:8px;"></i> Requirements to Submit</h3>
            <small style="color:var(--neutral-400);">Sent <?= formatDate($req_row['sent_at']) ?></small>
        </div>
        <div class="card-body">
            <p style="color:var(--neutral-600);font-size:0.9rem;margin-bottom:16px;">
                Please prepare and submit the following documents for your halal certification application:
            </p>
            <ol style="padding-left: 20px; line-height: 2.2; margin: 0;">
                <?php foreach (array_filter(explode("\n", $req_row['requirements'])) as $req): ?>
                    <li style="font-size: 0.9rem; color: var(--neutral-700);"><?= htmlspecialchars(trim($req)) ?></li>
                <?php endforeach; ?>
            </ol>
            <div style="margin-top: 24px;">
                <a href="<?= BASE_URL ?>dashboard/business_owner/applications.php?action=create&loi_id=<?= $view_loi['id'] ?>" class="btn btn-primary" style="width:100%;">
                    <i class="fas fa-arrow-right"></i> Proceed to Submit Application
                </a>
            </div>
        </div>
    </div>
    <?php endif; ?>

</div>

<?php if ($view_loi['feedback']): ?>
<div class="alert alert-info" style="margin-top: 20px;">
    <i class="fas fa-comment"></i>
    <div>
        <strong>Evaluator Feedback</strong>
        <p style="margin-top: 4px;"><?= nl2br(htmlspecialchars($view_loi['feedback'])) ?></p>
        <?php if ($view_loi['evaluator_name']): ?>
            <small>By: <?= htmlspecialchars($view_loi['evaluator_name']) ?> | <?= $view_loi['verified_at'] ? formatDateTime($view_loi['verified_at']) : '' ?></small>
        <?php endif; ?>
    </div>
</div>
<?php endif; ?>

<?php endif; ?>

<?php elseif ($action === 'create' || $action === 'edit'): ?>
<!-- Create/Edit LOI Form -->
<a href="?action=list" class="btn btn-outline btn-sm" style="margin-bottom: 20px;"><i class="fas fa-arrow-left"></i> Back to List</a>

<div class="card">
    <div class="card-header">
        <h3><i class="fas fa-pen-fancy" style="color: var(--primary-600); margin-right: 8px;"></i> <?= $edit_loi ? 'Edit' : 'Create' ?> Letter of Intent</h3>
    </div>
    <div class="card-body">
        <form method="POST" action="?action=<?= $action ?>" enctype="multipart/form-data">
            <?php if ($edit_loi): ?>
                <input type="hidden" name="loi_id" value="<?= $edit_loi['id'] ?>">
            <?php endif; ?>
            
            <h4 style="font-family: var(--font-display); font-weight: 700; margin-bottom: 16px; color: var(--neutral-700);">
                <i class="fas fa-building" style="color: var(--primary-500);"></i> Company Information
            </h4>
            
            <div style="display: grid; grid-template-columns: 1fr 1fr 1fr; gap: 20px;">
                <div class="form-group">
                    <label>Company Name <span class="required">*</span></label>
                    <input type="text" name="company_name" class="form-control" value="<?= htmlspecialchars($edit_loi['company_name'] ?? '') ?>" required placeholder="Enter company name">
                </div>
                <div class="form-group">
                    <label>Contact Person</label>
                    <input type="text" name="contact_person" class="form-control" value="<?= htmlspecialchars($edit_loi['contact_person'] ?? $_SESSION['full_name']) ?>" placeholder="Contact person name">
                </div>
                <div class="form-group">
                    <label>Date of Intent <span class="required">*</span></label>
                    <input type="date" name="date_of_intent" class="form-control" value="<?= htmlspecialchars($edit_loi['date_of_intent'] ?? date('Y-m-d')) ?>" required>
                </div>
            </div>
            
            <div class="form-group">
                <label>Company Address <span class="required">*</span></label>
                <textarea name="company_address" class="form-control" rows="2" required placeholder="Complete company address"><?= htmlspecialchars($edit_loi['company_address'] ?? '') ?></textarea>
            </div>
            
            <div style="display: grid; grid-template-columns: 1fr 1fr; gap: 20px;">
                <div class="form-group">
                    <label>Contact Email</label>
                    <input type="email" name="contact_email" class="form-control" value="<?= htmlspecialchars($edit_loi['contact_email'] ?? $_SESSION['email']) ?>" placeholder="Email address">
                </div>
                <div class="form-group">
                    <label>Contact Phone</label>
                    <input type="text" name="contact_phone" class="form-control" value="<?= htmlspecialchars($edit_loi['contact_phone'] ?? '') ?>" placeholder="Phone number">
                </div>
            </div>
            
            
            <div class="form-group">
                <label>Menu List</label>
                <?php
                    $existing_items = [];
                    if (!empty($edit_loi['menu_list'])) {
                        $existing_items = array_filter(explode("\n", $edit_loi['menu_list']));
                    }
                    if (empty($existing_items)) $existing_items = [''];
                ?>
                <div id="menuItemsContainer">
                    <?php foreach ($existing_items as $item): ?>
                    <div class="menu-item-row" style="display: flex; gap: 8px; margin-bottom: 8px;">
                        <input type="text" name="menu_items[]" class="form-control" value="<?= htmlspecialchars(trim($item)) ?>" placeholder="e.g. Chicken Adobo">
                        <button type="button" class="btn btn-sm btn-outline" onclick="removeMenuItem(this)" title="Remove" style="flex-shrink:0;"><i class="fas fa-times"></i></button>
                    </div>
                    <?php endforeach; ?>
                </div>
                <button type="button" class="btn btn-sm btn-outline" onclick="addMenuItem()" style="margin-top: 6px;">
                    <i class="fas fa-plus"></i> Add Menu Item
                </button>
                <p class="form-text">List all menu items or products you want to certify as halal.</p>
            </div>
            
            <div style="display: flex; gap: 12px; justify-content: flex-end; margin-top: 24px;">
                <a href="?action=list" class="btn btn-secondary"><i class="fas fa-times"></i> Cancel</a>
                <button type="submit" name="submit_action" value="draft" class="btn btn-outline"><i class="fas fa-save"></i> Save as Draft</button>
                <button type="submit" name="submit_action" value="submit" class="btn btn-primary"><i class="fas fa-paper-plane"></i> Submit LOI</button>
            </div>
        </form>
    </div>
</div>
<?php endif; ?>

<?php require_once __DIR__ . '/../../includes/footer.php'; ?>
<script>
function addMenuItem() {
    const container = document.getElementById('menuItemsContainer');
    const row = document.createElement('div');
    row.className = 'menu-item-row';
    row.style.cssText = 'display:flex;gap:8px;margin-bottom:8px;';
    row.innerHTML = '<input type="text" name="menu_items[]" class="form-control" placeholder="e.g. Chicken Adobo">'
        + '<button type="button" class="btn btn-sm btn-outline" onclick="removeMenuItem(this)" title="Remove" style="flex-shrink:0;"><i class="fas fa-times"></i></button>';
    container.appendChild(row);
    row.querySelector('input').focus();
}

function removeMenuItem(btn) {
    const container = document.getElementById('menuItemsContainer');
    if (container.querySelectorAll('.menu-item-row').length > 1) {
        btn.closest('.menu-item-row').remove();
    } else {
        btn.closest('.menu-item-row').querySelector('input').value = '';
    }
}
</script>
