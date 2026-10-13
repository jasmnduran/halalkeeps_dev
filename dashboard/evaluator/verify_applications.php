<?php
require_once __DIR__ . '/../../config/app.php';
requireLogin();
requireRole([ROLE_EVALUATOR]);

$page_title = 'Verify Applications';
$page_heading = 'Verify Applications';
$is_dashboard = true;
$breadcrumbs = [['label' => 'Dashboard', 'url' => BASE_URL . 'dashboard/evaluator/'], ['label' => 'Verify Applications']];
$user_id = $_SESSION['user_id'];
$action = $_GET['action'] ?? 'list';
$error = ''; $success = '';

// Handle verification POST
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $app_id = intval($_POST['application_id']);
    $status  = sanitize($_POST['verification_status']);
    $feedback = sanitize($_POST['feedback'] ?? '');

    $stmt = $conn->prepare("INSERT INTO application_verification (application_id, evaluator_id, verification_status, feedback, verified_at) VALUES (?, ?, ?, ?, NOW())");
    $stmt->bind_param("iiss", $app_id, $user_id, $status, $feedback);

    if ($stmt->execute()) {
        $new_status = $status === 'verified' ? 'verified' : ($status === 'returned' ? 'incomplete' : $status);
        $upd = $conn->prepare("UPDATE hdp_applications SET status = ? WHERE id = ?");
        $upd->bind_param("si", $new_status, $app_id);
        $upd->execute();

        $owner_q = $conn->prepare("SELECT business_owner_id FROM hdp_applications WHERE id = ?");
        $owner_q->bind_param("i", $app_id);
        $owner_q->execute();
        $owner = $owner_q->get_result()->fetch_assoc();
        if ($owner) {
            createNotification($conn, $owner['business_owner_id'], 'Application ' . ucfirst($status),
                'Your HDP application has been ' . $status . '.',
                $status === 'verified' ? 'success' : 'warning',
                BASE_URL . 'dashboard/business_owner/applications.php');
        }
        logActivity($conn, $user_id, 'Application Verified', 'App #' . $app_id . ' ' . $status, 'evaluation');
        $success = 'Application verification saved.';
        $action = 'list';
    }
}

$filter = $_GET['filter'] ?? 'submitted';

require_once __DIR__ . '/../../includes/header.php';
?>

<?php if ($success): ?>
    <div class="alert alert-success"><i class="fas fa-check-circle"></i><span><?= $success ?></span><button class="close-alert"><i class="fas fa-times"></i></button></div>
<?php endif; ?>

<?php if ($action === 'list'): ?>
<?php
$where = $filter === 'all' ? '' : "WHERE ha.status = '$filter'";
$apps = $conn->query("SELECT ha.*, loi.company_name, u.full_name as owner_name FROM hdp_applications ha JOIN letter_of_intent loi ON ha.loi_id = loi.id JOIN users u ON ha.business_owner_id = u.id $where ORDER BY ha.created_at DESC")->fetch_all(MYSQLI_ASSOC);
?>
<div class="card" style="margin-bottom: 16px;">
    <div class="card-body" style="display: flex; gap: 8px;">
        <a href="?filter=submitted" class="btn btn-sm <?= $filter === 'submitted' ? 'btn-primary' : 'btn-secondary' ?>">Pending</a>
        <a href="?filter=verified" class="btn btn-sm <?= $filter === 'verified' ? 'btn-primary' : 'btn-secondary' ?>">Verified</a>
        <a href="?filter=all" class="btn btn-sm <?= $filter === 'all' ? 'btn-primary' : 'btn-secondary' ?>">All</a>
    </div>
</div>

<div class="card">
    <div class="card-header"><h3>HDP Applications</h3></div>
    <div class="card-body" style="padding: 0;">
        <?php if (empty($apps)): ?>
            <div class="empty-state"><div class="empty-icon"><i class="fas fa-clipboard-check"></i></div><h3>No Applications</h3></div>
        <?php else: ?>
            <div class="table-responsive">
                <table class="table">
                    <thead><tr><th>Company</th><th>Owner</th><th>Status</th><th>Submitted</th><th>Actions</th></tr></thead>
                    <tbody>
                        <?php foreach ($apps as $app): ?>
                        <tr>
                            <td><strong><?= htmlspecialchars($app['company_name']) ?></strong></td>
                            <td><?= htmlspecialchars($app['owner_name']) ?></td>
                            <td><?= getStatusBadge($app['status']) ?></td>
                            <td style="font-size:0.85rem;color:var(--neutral-500)"><?= $app['submitted_at'] ? formatDate($app['submitted_at']) : '-' ?></td>
                            <td>
                                <a href="?action=review&id=<?= $app['id'] ?>" class="btn btn-sm btn-primary">
                                    <i class="fas fa-eye"></i> Review
                                </a>
                            </td>
                        </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        <?php endif; ?>
    </div>
</div>

<?php elseif ($action === 'review' && isset($_GET['id'])): ?>
<?php
$app_id = intval($_GET['id']);
$stmt = $conn->prepare("SELECT ha.*, loi.company_name, loi.company_address, loi.contact_person, loi.contact_phone, u.full_name as owner_name, u.email as owner_email FROM hdp_applications ha JOIN letter_of_intent loi ON ha.loi_id = loi.id JOIN users u ON ha.business_owner_id = u.id WHERE ha.id = ?");
$stmt->bind_param("i", $app_id);
$stmt->execute();
$app = $stmt->get_result()->fetch_assoc();

// Load uploaded files
$uploads = [];
if ($app) {
    $uf = $conn->prepare("SELECT * FROM application_requirement_uploads WHERE application_id = ?");
    $uf->bind_param("i", $app_id);
    $uf->execute();
    foreach ($uf->get_result()->fetch_all(MYSQLI_ASSOC) as $row) {
        $uploads[$row['requirement_label']] = $row;
    }
}

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
?>
<?php if ($app): ?>
<a href="?action=list&filter=<?= $filter ?>" class="btn btn-outline btn-sm" style="margin-bottom:20px;"><i class="fas fa-arrow-left"></i> Back</a>

<div style="display: grid; grid-template-columns: 2fr 1fr; gap: 24px; align-items: start;">

    <!-- Submitted Files -->
    <div class="card">
        <div class="card-header">
            <h3><i class="fas fa-folder-open" style="color:var(--primary-600);margin-right:8px;"></i>
                <?= htmlspecialchars($app['company_name']) ?> — Submitted Requirements
            </h3>
            <?= getStatusBadge($app['status']) ?>
        </div>
        <div class="card-body" style="padding:0;">
            <table class="table" style="margin:0;">
                <thead>
                    <tr><th>#</th><th>Requirement</th><th>File</th></tr>
                </thead>
                <tbody>
                    <?php foreach ($requirement_labels as $i => $label): ?>
                    <?php $file = $uploads[$label] ?? null; ?>
                    <tr>
                        <td style="color:var(--neutral-400);font-size:0.85rem;"><?= $i + 1 ?></td>
                        <td style="font-size:0.9rem;color:var(--neutral-700);"><?= htmlspecialchars($label) ?></td>
                        <td>
                            <?php if ($file): ?>
                                <a href="<?= BASE_URL ?>uploads/<?= $file['file_path'] ?>" target="_blank"
                                   class="btn btn-sm btn-outline" style="font-size:0.8rem;">
                                    <i class="fas fa-file"></i> View
                                </a>
                            <?php else: ?>
                                <span style="font-size:0.8rem;color:var(--neutral-300);">—</span>
                            <?php endif; ?>
                        </td>
                    </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    </div>

    <!-- Verification Panel -->
    <div class="card" style="position:sticky;top:20px;">
        <div class="card-header">
            <h3><i class="fas fa-clipboard-check" style="color:var(--primary-600);margin-right:8px;"></i> Verification</h3>
        </div>
        <div class="card-body">
            <div style="margin-bottom:16px;">
                <div style="font-size:0.8rem;color:var(--neutral-500);text-transform:uppercase;font-weight:600;">Company</div>
                <div style="font-weight:600;"><?= htmlspecialchars($app['company_name']) ?></div>
            </div>
            <div style="margin-bottom:16px;">
                <div style="font-size:0.8rem;color:var(--neutral-500);text-transform:uppercase;font-weight:600;">Submitted By</div>
                <div><?= htmlspecialchars($app['owner_name']) ?></div>
                <div style="font-size:0.85rem;color:var(--neutral-400);"><?= htmlspecialchars($app['owner_email']) ?></div>
            </div>
            <div style="margin-bottom:20px;">
                <div style="font-size:0.8rem;color:var(--neutral-500);text-transform:uppercase;font-weight:600;">Files Uploaded</div>
                <div style="font-size:1.1rem;font-weight:700;color:var(--primary-600);"><?= count($uploads) ?> / <?= count($requirement_labels) ?></div>
            </div>

            <?php if ($app['status'] === 'submitted'): ?>
            <form method="POST">
                <input type="hidden" name="application_id" value="<?= $app['id'] ?>">
                <div class="form-group">
                    <label>Feedback / Remarks</label>
                    <textarea name="feedback" class="form-control" rows="4" placeholder="Optional notes..."></textarea>
                </div>
                <div style="display:flex;flex-direction:column;gap:10px;margin-top:8px;">
                    <button type="submit" name="verification_status" value="verified" class="btn btn-success">
                        <i class="fas fa-check-circle"></i> Approve
                    </button>
                    <button type="submit" name="verification_status" value="returned" class="btn btn-danger">
                        <i class="fas fa-times-circle"></i> Return / Incomplete
                    </button>
                </div>
            </form>
            <?php else: ?>
            <div style="text-align:center;"><?= getStatusBadge($app['status']) ?></div>
            <?php endif; ?>
        </div>
    </div>

</div>
<?php endif; ?>
<?php endif; ?>

<?php require_once __DIR__ . '/../../includes/footer.php'; ?>
