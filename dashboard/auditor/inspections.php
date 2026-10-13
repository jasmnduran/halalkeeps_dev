<?php
require_once __DIR__ . '/../../config/app.php';
requireLogin();
requireRole([ROLE_AUDITOR_TECHNICAL, ROLE_AUDITOR_SHARIAH]);

$page_title = 'Inspections';
$page_heading = 'Conduct Inspections';
$is_dashboard = true;
$breadcrumbs = [['label' => 'Dashboard', 'url' => BASE_URL . 'dashboard/auditor/'], ['label' => 'Inspections']];
$user_id = $_SESSION['user_id'];
$role_id = $_SESSION['role_id'];
$error = ''; $success = '';

// Handle inspection submission
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action_type = $_POST['action_type'] ?? '';
    
    if ($action_type === 'start_inspection') {
        $schedule_id = intval($_POST['schedule_id']);
        $app_id = intval($_POST['application_id']);
        
        // Determine which auditor field to set
        $auditor_field = ($role_id == ROLE_AUDITOR_TECHNICAL) ? 'auditor_technical_id' : 'auditor_shariah_id';
        
        // Check if inspection record exists
        $check = $conn->prepare("SELECT id FROM inspections WHERE schedule_id = ?");
        $check->bind_param("i", $schedule_id);
        $check->execute();
        $existing = $check->get_result()->fetch_assoc();
        
        if ($existing) {
            $stmt = $conn->prepare("UPDATE inspections SET $auditor_field = ?, status = 'in_progress' WHERE schedule_id = ?");
            $stmt->bind_param("ii", $user_id, $schedule_id);
        } else {
            $stmt = $conn->prepare("INSERT INTO inspections (schedule_id, application_id, $auditor_field, inspection_date, status) VALUES (?, ?, ?, CURDATE(), 'in_progress')");
            $stmt->bind_param("iii", $schedule_id, $app_id, $user_id);
        }
        
        if ($stmt->execute()) {
            $conn->query("UPDATE inspection_schedules SET status = 'in_progress' WHERE id = $schedule_id");
            $success = 'Inspection started!';
        }
    }
    
    if ($action_type === 'submit_findings') {
        $inspection_id = intval($_POST['inspection_id']);
        $audit_findings = sanitize($_POST['audit_findings']);
        $conformity_status = sanitize($_POST['conformity_status']);
        $remarks = sanitize($_POST['remarks'] ?? '');
        
        $stmt = $conn->prepare("UPDATE inspections SET audit_findings = ?, conformity_status = ?, remarks = ?, status = 'completed' WHERE id = ?");
        $stmt->bind_param("sssi", $audit_findings, $conformity_status, $remarks, $inspection_id);
        
        if ($stmt->execute()) {
            // Update schedule status
            $conn->query("UPDATE inspection_schedules s JOIN inspections i ON s.id = i.schedule_id SET s.status = 'completed' WHERE i.id = $inspection_id");
            
            // If non-conforming, prompt NCR creation
            if ($conformity_status === 'non_conforming' || $conformity_status === 'partial') {
                $success = 'Findings submitted. Please create an NCR report for non-conforming items.';
            } else {
                // Notify impartial committee
                $comm = $conn->query("SELECT id FROM users WHERE role_id = " . ROLE_IMPARTIAL_COMMITTEE . " AND role_status = 'approved'");
                while ($c = $comm->fetch_assoc()) {
                    createNotification($conn, $c['id'], 'Inspection Completed', 
                        'An inspection has been completed and is ready for review.', 'action_required',
                        BASE_URL . 'dashboard/impartial_committee/review_evidence.php');
                }
                $success = 'Inspection findings submitted successfully!';
            }
            logActivity($conn, $user_id, 'Inspection Completed', 'Inspection #' . $inspection_id, 'audit');
        }
    }
}

// Get scheduled inspections
$schedules = $conn->query("SELECT s.*, loi.company_name, loi.company_address, ha.id as app_id, i.id as inspection_id, i.status as insp_status FROM inspection_schedules s JOIN hdp_applications ha ON s.application_id = ha.id JOIN letter_of_intent loi ON ha.loi_id = loi.id LEFT JOIN inspections i ON s.id = i.schedule_id ORDER BY s.schedule_date DESC")->fetch_all(MYSQLI_ASSOC);

// Get my inspections in progress
$my_inspections = [];
$auditor_field = ($role_id == ROLE_AUDITOR_TECHNICAL) ? 'auditor_technical_id' : 'auditor_shariah_id';
$mi = $conn->prepare("SELECT i.*, s.schedule_date, loi.company_name FROM inspections i JOIN inspection_schedules s ON i.schedule_id = s.id JOIN hdp_applications ha ON i.application_id = ha.id JOIN letter_of_intent loi ON ha.loi_id = loi.id WHERE i.$auditor_field = ? AND i.status = 'in_progress'");
$mi->bind_param("i", $user_id);
$mi->execute();
$my_inspections = $mi->get_result()->fetch_all(MYSQLI_ASSOC);

require_once __DIR__ . '/../../includes/header.php';
?>

<?php if ($success): ?>
    <div class="alert alert-success"><i class="fas fa-check-circle"></i><span><?= $success ?></span><button class="close-alert"><i class="fas fa-times"></i></button></div>
<?php endif; ?>

<?php if (!empty($my_inspections)): ?>
<div class="card" style="margin-bottom: 24px; border: 2px solid var(--primary-200);">
    <div class="card-header" style="background: var(--primary-50);">
        <h3><i class="fas fa-spinner fa-spin" style="color: var(--primary-600); margin-right: 8px;"></i> My Active Inspections</h3>
    </div>
    <div class="card-body">
        <?php foreach ($my_inspections as $insp): ?>
        <div style="background: var(--neutral-50); border-radius: 12px; padding: 20px; margin-bottom: 16px;">
            <h4 style="font-family: var(--font-display); font-weight: 700; margin-bottom: 12px;">
                <?= htmlspecialchars($insp['company_name']) ?>
                <span class="badge badge-primary" style="margin-left: 8px;">In Progress</span>
            </h4>
            <form method="POST">
                <input type="hidden" name="action_type" value="submit_findings">
                <input type="hidden" name="inspection_id" value="<?= $insp['id'] ?>">
                
                <div class="form-group">
                    <label>Audit Findings <span class="required">*</span></label>
                    <textarea name="audit_findings" class="form-control" rows="5" required placeholder="Document all audit findings during the inspection. Include observations on halal compliance, food handling, storage, sourcing, etc."><?= htmlspecialchars($insp['audit_findings'] ?? '') ?></textarea>
                </div>
                
                <div style="display: grid; grid-template-columns: 1fr 1fr; gap: 20px;">
                    <div class="form-group">
                        <label>Conformity Status <span class="required">*</span></label>
                        <select name="conformity_status" class="form-control" required>
                            <option value="">Select...</option>
                            <option value="conforming">✅ Conforming - Fully Compliant</option>
                            <option value="partial">⚠️ Partial - Minor Issues</option>
                            <option value="non_conforming">❌ Non-Conforming - Major Issues</option>
                        </select>
                    </div>
                    <div class="form-group">
                        <label>Remarks</label>
                        <textarea name="remarks" class="form-control" rows="3" placeholder="Additional remarks..."><?= htmlspecialchars($insp['remarks'] ?? '') ?></textarea>
                    </div>
                </div>
                
                <div style="display: flex; gap: 12px; justify-content: flex-end;">
                    <button type="submit" class="btn btn-primary"><i class="fas fa-check-circle"></i> Submit Findings</button>
                </div>
            </form>
        </div>
        <?php endforeach; ?>
    </div>
</div>
<?php endif; ?>

<div class="card">
    <div class="card-header">
        <h3><i class="fas fa-calendar-alt" style="color: var(--accent-500); margin-right: 8px;"></i> Scheduled Inspections</h3>
    </div>
    <div class="card-body" style="padding: 0;">
        <?php if (empty($schedules)): ?>
            <div class="empty-state"><div class="empty-icon"><i class="fas fa-calendar"></i></div><h3>No Inspections Scheduled</h3></div>
        <?php else: ?>
            <div class="table-responsive">
                <table class="table">
                    <thead><tr><th>Company</th><th>Location</th><th>Date</th><th>Schedule Status</th><th>Inspection</th><th>Action</th></tr></thead>
                    <tbody>
                        <?php foreach ($schedules as $s): ?>
                        <tr>
                            <td><strong><?= htmlspecialchars($s['company_name']) ?></strong></td>
                            <td style="font-size:0.85rem"><?= htmlspecialchars($s['location'] ?? $s['company_address'] ?? '-') ?></td>
                            <td><?= formatDate($s['schedule_date']) ?></td>
                            <td><?= getStatusBadge($s['status']) ?></td>
                            <td><?= $s['insp_status'] ? getStatusBadge($s['insp_status']) : '<span class="badge badge-secondary">Not Started</span>' ?></td>
                            <td>
                                <?php if ($s['status'] === 'scheduled' || $s['status'] === 'confirmed'): ?>
                                    <?php if (!$s['inspection_id']): ?>
                                    <form method="POST" style="display:inline">
                                        <input type="hidden" name="action_type" value="start_inspection">
                                        <input type="hidden" name="schedule_id" value="<?= $s['id'] ?>">
                                        <input type="hidden" name="application_id" value="<?= $s['app_id'] ?>">
                                        <button type="submit" class="btn btn-sm btn-success"><i class="fas fa-play"></i> Start</button>
                                    </form>
                                    <?php endif; ?>
                                <?php endif; ?>
                            </td>
                        </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        <?php endif; ?>
    </div>
</div>

<?php require_once __DIR__ . '/../../includes/footer.php'; ?>
