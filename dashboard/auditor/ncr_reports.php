<?php
require_once __DIR__ . '/../../config/app.php';
requireLogin();
requireRole([ROLE_AUDITOR_TECHNICAL, ROLE_AUDITOR_SHARIAH]);

$page_title = 'NCR Reports';
$page_heading = 'Non-Conformance Reports';
$is_dashboard = true;
$breadcrumbs = [['label' => 'Dashboard', 'url' => BASE_URL . 'dashboard/auditor/'], ['label' => 'NCR Reports']];
$user_id = $_SESSION['user_id'];
$error = ''; $success = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $inspection_id = intval($_POST['inspection_id']);
    $app_id = intval($_POST['application_id']);
    $finding_details = sanitize($_POST['finding_details']);
    $category = sanitize($_POST['category']);
    $corrective_action = sanitize($_POST['corrective_action_required'] ?? '');
    $deadline = sanitize($_POST['deadline'] ?? '');
    $ncr_number = generateReference('NCR');
    
    $stmt = $conn->prepare("INSERT INTO ncr_reports (inspection_id, application_id, ncr_number, finding_details, category, corrective_action_required, deadline, status) VALUES (?, ?, ?, ?, ?, ?, ?, 'open')");
    $stmt->bind_param("iisssss", $inspection_id, $app_id, $ncr_number, $finding_details, $category, $corrective_action, $deadline);
    if ($stmt->execute()) {
        $owner = $conn->query("SELECT business_owner_id FROM hdp_applications WHERE id = $app_id")->fetch_assoc();
        if ($owner) {
            createNotification($conn, $owner['business_owner_id'], 'NCR Issued: ' . $ncr_number, 
                'A Non-Conformance Report has been issued for your application. Corrective action is required.',
                'warning', BASE_URL . 'dashboard/business_owner/applications.php');
        }
        $success = 'NCR Report ' . $ncr_number . ' created successfully.';
    }
}

// Get completed inspections for NCR creation
$completed = $conn->query("SELECT i.*, loi.company_name, ha.id as app_id FROM inspections i JOIN hdp_applications ha ON i.application_id = ha.id JOIN letter_of_intent loi ON ha.loi_id = loi.id WHERE i.status = 'completed' AND i.conformity_status IN ('non_conforming','partial') ORDER BY i.created_at DESC")->fetch_all(MYSQLI_ASSOC);

$ncrs = $conn->query("SELECT n.*, loi.company_name FROM ncr_reports n JOIN hdp_applications ha ON n.application_id = ha.id JOIN letter_of_intent loi ON ha.loi_id = loi.id ORDER BY n.created_at DESC")->fetch_all(MYSQLI_ASSOC);

require_once __DIR__ . '/../../includes/header.php';
?>

<?php if ($success): ?>
    <div class="alert alert-success"><i class="fas fa-check-circle"></i><span><?= $success ?></span><button class="close-alert"><i class="fas fa-times"></i></button></div>
<?php endif; ?>

<?php if (!empty($completed)): ?>
<div class="card" style="margin-bottom: 24px;">
    <div class="card-header"><h3><i class="fas fa-plus" style="color: var(--danger); margin-right: 8px;"></i> Generate NCR</h3></div>
    <div class="card-body">
        <form method="POST">
            <div style="display: grid; grid-template-columns: 1fr 1fr; gap: 20px;">
                <div class="form-group">
                    <label>Inspection <span class="required">*</span></label>
                    <select name="inspection_id" class="form-control" required id="ncr_inspection">
                        <option value="">Select inspection...</option>
                        <?php foreach ($completed as $c): ?>
                        <option value="<?= $c['id'] ?>" data-app="<?= $c['app_id'] ?>"><?= htmlspecialchars($c['company_name']) ?> (<?= formatDate($c['created_at']) ?>)</option>
                        <?php endforeach; ?>
                    </select>
                    <input type="hidden" name="application_id" id="ncr_app_id">
                </div>
                <div class="form-group">
                    <label>Category <span class="required">*</span></label>
                    <select name="category" class="form-control" required>
                        <option value="major">Major Non-Conformance</option>
                        <option value="minor">Minor Non-Conformance</option>
                        <option value="observation">Observation</option>
                    </select>
                </div>
            </div>
            <div class="form-group">
                <label>Finding Details <span class="required">*</span></label>
                <textarea name="finding_details" class="form-control" rows="4" required placeholder="Describe the non-conformance finding in detail..."></textarea>
            </div>
            <div class="form-group">
                <label>Corrective Action Required</label>
                <textarea name="corrective_action_required" class="form-control" rows="3" placeholder="Describe what corrective actions are needed..."></textarea>
            </div>
            <div class="form-group">
                <label>Deadline for Corrective Action</label>
                <input type="date" name="deadline" class="form-control" min="<?= date('Y-m-d') ?>">
            </div>
            <button type="submit" class="btn btn-danger"><i class="fas fa-exclamation-triangle"></i> Generate NCR</button>
        </form>
    </div>
</div>
<?php endif; ?>

<div class="card">
    <div class="card-header"><h3>All NCR Reports</h3></div>
    <div class="card-body" style="padding: 0;">
        <?php if (empty($ncrs)): ?>
            <div class="empty-state"><div class="empty-icon"><i class="fas fa-check-circle" style="color:var(--success)"></i></div><h3>No NCRs</h3><p>No non-conformance reports have been generated.</p></div>
        <?php else: ?>
            <div class="table-responsive">
                <table class="table">
                    <thead><tr><th>NCR #</th><th>Company</th><th>Category</th><th>Deadline</th><th>Status</th></tr></thead>
                    <tbody>
                        <?php foreach ($ncrs as $n): ?>
                        <tr>
                            <td><strong><?= htmlspecialchars($n['ncr_number']) ?></strong></td>
                            <td><?= htmlspecialchars($n['company_name']) ?></td>
                            <td>
                                <?php if ($n['category'] === 'major'): ?>
                                    <span class="badge badge-danger">Major</span>
                                <?php elseif ($n['category'] === 'minor'): ?>
                                    <span class="badge badge-warning">Minor</span>
                                <?php else: ?>
                                    <span class="badge badge-info">Observation</span>
                                <?php endif; ?>
                            </td>
                            <td><?= $n['deadline'] ? formatDate($n['deadline']) : '-' ?></td>
                            <td><?= getStatusBadge($n['status']) ?></td>
                        </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        <?php endif; ?>
    </div>
</div>

<script>
document.getElementById('ncr_inspection')?.addEventListener('change', function() {
    const selected = this.options[this.selectedIndex];
    document.getElementById('ncr_app_id').value = selected.dataset.app || '';
});
</script>

<?php require_once __DIR__ . '/../../includes/footer.php'; ?>
