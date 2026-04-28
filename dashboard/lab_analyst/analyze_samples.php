<?php
require_once __DIR__ . '/../../config/app.php';
requireLogin();
requireRole([ROLE_LAB_ANALYST]);

$page_title = 'Analyze Samples';
$page_heading = 'Analyze Samples';
$is_dashboard = true;
$breadcrumbs = [['label' => 'Dashboard', 'url' => BASE_URL . 'dashboard/lab_analyst/'], ['label' => 'Analyze']];
$user_id = $_SESSION['user_id'];
$success = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action_type = $_POST['action_type'] ?? '';
    
    if ($action_type === 'start_testing') {
        $request_id = intval($_POST['request_id']);
        $conn->query("UPDATE laboratory_requests SET status = 'testing', analyst_id = $user_id, testing_started_at = NOW() WHERE id = $request_id");
        $success = 'Testing started!';
    }
    
    if ($action_type === 'submit_results') {
        $request_id = intval($_POST['request_id']);
        $app_id = intval($_POST['application_id']);
        $result = sanitize($_POST['test_result']);
        $analysis = sanitize($_POST['analysis_details']);
        $halal_status = sanitize($_POST['halal_status']);
        $report_content = sanitize($_POST['report_content']);
        $report_number = generateReference('LAB');
        
        // Update lab request
        $stmt = $conn->prepare("UPDATE laboratory_requests SET status = 'completed', test_result = ?, analysis_details = ?, halal_status = ?, completed_at = NOW() WHERE id = ?");
        $stmt->bind_param("sssi", $result, $analysis, $halal_status, $request_id);
        $stmt->execute();
        
        // Create lab report
        $stmt2 = $conn->prepare("INSERT INTO laboratory_reports (laboratory_request_id, application_id, analyst_id, report_number, report_content, halal_status) VALUES (?, ?, ?, ?, ?, ?)");
        $stmt2->bind_param("iiisss", $request_id, $app_id, $user_id, $report_number, $report_content, $halal_status);
        $stmt2->execute();
        
        // Notify evaluator/auditors
        $evaluators = $conn->query("SELECT id FROM users WHERE role_id IN (" . ROLE_EVALUATOR . "," . ROLE_AUDITOR_TECHNICAL . ") AND role_status = 'approved'");
        while ($e = $evaluators->fetch_assoc()) {
            createNotification($conn, $e['id'], 'Lab Report Ready: ' . $report_number,
                'Laboratory analysis is complete. Report is available for review.', 'info',
                BASE_URL . 'dashboard/evaluator/');
        }
        
        $owner = $conn->query("SELECT business_owner_id FROM laboratory_requests WHERE id = $request_id")->fetch_assoc();
        if ($owner) {
            createNotification($conn, $owner['business_owner_id'], 'Laboratory Results Ready',
                'Your laboratory testing is complete. Status: ' . ucfirst($halal_status), 
                $halal_status === 'halal' ? 'success' : 'warning',
                BASE_URL . 'dashboard/business_owner/laboratory.php');
        }
        
        logActivity($conn, $user_id, 'Lab Report Created', 'Report ' . $report_number, 'laboratory');
        $success = 'Lab report ' . $report_number . ' submitted successfully!';
    }
}

$filter = $_GET['filter'] ?? 'received';
$where_clause = $filter === 'all' ? "WHERE lr.status IN ('received','testing','completed')" : "WHERE lr.status = '$filter'";
$samples = $conn->query("SELECT lr.*, loi.company_name, ha.id as app_id FROM laboratory_requests lr JOIN hdp_applications ha ON lr.application_id = ha.id JOIN letter_of_intent loi ON ha.loi_id = loi.id $where_clause ORDER BY lr.created_at DESC")->fetch_all(MYSQLI_ASSOC);

require_once __DIR__ . '/../../includes/header.php';
?>

<?php if ($success): ?>
    <div class="alert alert-success"><i class="fas fa-check-circle"></i><span><?= $success ?></span><button class="close-alert"><i class="fas fa-times"></i></button></div>
<?php endif; ?>

<div class="card" style="margin-bottom: 16px;">
    <div class="card-body" style="display: flex; gap: 8px;">
        <a href="?filter=received" class="btn btn-sm <?= $filter === 'received' ? 'btn-primary' : 'btn-secondary' ?>">Awaiting</a>
        <a href="?filter=testing" class="btn btn-sm <?= $filter === 'testing' ? 'btn-primary' : 'btn-secondary' ?>">In Testing</a>
        <a href="?filter=completed" class="btn btn-sm <?= $filter === 'completed' ? 'btn-primary' : 'btn-secondary' ?>">Completed</a>
        <a href="?filter=all" class="btn btn-sm <?= $filter === 'all' ? 'btn-primary' : 'btn-secondary' ?>">All</a>
    </div>
</div>

<?php foreach ($samples as $s): ?>
<div class="card" style="margin-bottom: 16px;">
    <div class="card-header">
        <h3><i class="fas fa-vial" style="color:var(--primary-600);margin-right:8px"></i> <?= htmlspecialchars($s['company_name']) ?></h3>
        <?= getStatusBadge($s['status']) ?>
    </div>
    <div class="card-body">
        <div style="display: grid; grid-template-columns: 1fr 1fr 1fr; gap: 16px; margin-bottom: 16px;">
            <div><label style="font-weight:600;color:var(--neutral-500);font-size:0.8rem;text-transform:uppercase">Sample</label><p style="font-size:0.9rem"><?= htmlspecialchars($s['sample_description']) ?></p></div>
            <div><label style="font-weight:600;color:var(--neutral-500);font-size:0.8rem;text-transform:uppercase">Payment</label><p><?= getStatusBadge($s['payment_status']) ?> - <?= formatCurrency($s['payment_testing_fee']) ?></p></div>
            <div><label style="font-weight:600;color:var(--neutral-500);font-size:0.8rem;text-transform:uppercase">Received</label><p style="font-size:0.9rem"><?= $s['received_at'] ? formatDateTime($s['received_at']) : 'N/A' ?></p></div>
        </div>
        
        <?php if ($s['status'] === 'received'): ?>
        <form method="POST">
            <input type="hidden" name="action_type" value="start_testing">
            <input type="hidden" name="request_id" value="<?= $s['id'] ?>">
            <button type="submit" class="btn btn-primary"><i class="fas fa-play"></i> Start Testing</button>
        </form>
        <?php elseif ($s['status'] === 'testing'): ?>
        <div style="border-top:2px solid var(--neutral-100);padding-top:20px;margin-top:16px">
            <h4 style="font-family:var(--font-display);font-weight:700;margin-bottom:16px"><i class="fas fa-microscope" style="color:var(--primary-600)"></i> Submit Analysis Results</h4>
            <form method="POST">
                <input type="hidden" name="action_type" value="submit_results">
                <input type="hidden" name="request_id" value="<?= $s['id'] ?>">
                <input type="hidden" name="application_id" value="<?= $s['app_id'] ?>">
                
                <div style="display:grid;grid-template-columns:1fr 1fr;gap:20px">
                    <div class="form-group">
                        <label>Test Result <span class="required">*</span></label>
                        <select name="test_result" class="form-control" required>
                            <option value="">Select...</option>
                            <option value="pass">✅ Pass</option>
                            <option value="fail">❌ Fail</option>
                            <option value="inconclusive">⚠️ Inconclusive</option>
                        </select>
                    </div>
                    <div class="form-group">
                        <label>Halal Status <span class="required">*</span></label>
                        <select name="halal_status" class="form-control" required>
                            <option value="">Select...</option>
                            <option value="halal">✅ Halal - Compliant</option>
                            <option value="haram">❌ Haram - Non-Compliant</option>
                            <option value="mushbooh">⚠️ Mushbooh - Doubtful</option>
                        </select>
                    </div>
                </div>
                
                <div class="form-group">
                    <label>Analysis Details <span class="required">*</span></label>
                    <textarea name="analysis_details" class="form-control" rows="4" required placeholder="Detailed analysis of the sample including testing methods and results..."></textarea>
                </div>
                
                <div class="form-group">
                    <label>Full Report Content</label>
                    <textarea name="report_content" class="form-control" rows="5" placeholder="Complete laboratory report content..."></textarea>
                </div>
                
                <div style="display:flex;justify-content:flex-end">
                    <button type="submit" class="btn btn-primary"><i class="fas fa-file-medical-alt"></i> Submit Report</button>
                </div>
            </form>
        </div>
        <?php elseif ($s['status'] === 'completed'): ?>
        <div style="background:var(--primary-50);padding:16px;border-radius:10px">
            <p><strong>Result:</strong> <?= getStatusBadge($s['test_result'] ?? 'N/A') ?></p>
            <p><strong>Halal Status:</strong> <?= getStatusBadge($s['halal_status'] ?? 'N/A') ?></p>
            <?php if ($s['analysis_details']): ?><p style="margin-top:8px;font-size:0.9rem"><?= nl2br(htmlspecialchars(substr($s['analysis_details'], 0, 200))) ?></p><?php endif; ?>
        </div>
        <?php endif; ?>
    </div>
</div>
<?php endforeach; ?>

<?php if (empty($samples)): ?>
<div class="card"><div class="card-body"><div class="empty-state"><div class="empty-icon"><i class="fas fa-flask"></i></div><h3>No Samples</h3><p>No samples matching this filter.</p></div></div></div>
<?php endif; ?>

<?php require_once __DIR__ . '/../../includes/footer.php'; ?>
