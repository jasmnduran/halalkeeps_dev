<?php
require_once __DIR__ . '/../../config/app.php';
requireLogin();
requireRole([ROLE_EVALUATOR]);

$page_title = 'Inspection Schedules';
$page_heading = 'Inspection Schedules';
$is_dashboard = true;
$breadcrumbs = [['label' => 'Dashboard', 'url' => BASE_URL . 'dashboard/evaluator/'], ['label' => 'Inspection Schedules']];
$user_id = $_SESSION['user_id'];
$error = ''; $success = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $app_id = intval($_POST['application_id']);
    $schedule_date = sanitize($_POST['schedule_date']);
    $schedule_time = sanitize($_POST['schedule_time'] ?? '');
    $location = sanitize($_POST['location'] ?? '');
    $inspectors = sanitize($_POST['inspectors'] ?? '');
    $fees = floatval($_POST['inspection_fees'] ?? 0);
    $notes = sanitize($_POST['notes'] ?? '');
    
    $stmt = $conn->prepare("INSERT INTO inspection_schedules (application_id, evaluator_id, schedule_date, schedule_time, location, inspectors, inspection_fees, notes, status) VALUES (?, ?, ?, ?, ?, ?, ?, ?, 'scheduled')");
    $stmt->bind_param("iissssds", $app_id, $user_id, $schedule_date, $schedule_time, $location, $inspectors, $fees, $notes);
    if ($stmt->execute()) {
        // Notify auditors
        $auditors = $conn->query("SELECT id FROM users WHERE role_id IN (" . ROLE_AUDITOR_TECHNICAL . "," . ROLE_AUDITOR_SHARIAH . ") AND role_status = 'approved'");
        while ($aud = $auditors->fetch_assoc()) {
            createNotification($conn, $aud['id'], 'New Inspection Scheduled', 
                'An inspection has been scheduled for ' . $schedule_date, 'action_required',
                BASE_URL . 'dashboard/auditor/inspections.php');
        }
        $success = 'Inspection scheduled successfully!';
    }
}

// Get applications with verified status or TOR created
$apps = $conn->query("SELECT ha.*, loi.company_name, loi.company_address FROM hdp_applications ha JOIN letter_of_intent loi ON ha.loi_id = loi.id WHERE ha.status IN ('verified', 'approved') ORDER BY ha.updated_at DESC")->fetch_all(MYSQLI_ASSOC);

$schedules = $conn->query("SELECT s.*, loi.company_name, u.full_name as evaluator_name FROM inspection_schedules s JOIN hdp_applications ha ON s.application_id = ha.id JOIN letter_of_intent loi ON ha.loi_id = loi.id JOIN users u ON s.evaluator_id = u.id ORDER BY s.schedule_date DESC")->fetch_all(MYSQLI_ASSOC);

require_once __DIR__ . '/../../includes/header.php';
?>

<?php if ($success): ?>
    <div class="alert alert-success"><i class="fas fa-check-circle"></i><span><?= $success ?></span><button class="close-alert"><i class="fas fa-times"></i></button></div>
<?php endif; ?>

<div class="card" style="margin-bottom: 24px;">
    <div class="card-header"><h3><i class="fas fa-calendar-plus" style="color:var(--primary-600);margin-right:8px"></i> Schedule Inspection</h3></div>
    <div class="card-body">
        <form method="POST">
            <div style="display: grid; grid-template-columns: 1fr 1fr; gap: 20px;">
                <div class="form-group">
                    <label>Application <span class="required">*</span></label>
                    <select name="application_id" class="form-control" required>
                        <option value="">Select application...</option>
                        <?php foreach ($apps as $a): ?>
                        <option value="<?= $a['id'] ?>"><?= htmlspecialchars($a['company_name']) ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div class="form-group">
                    <label>Inspection Date <span class="required">*</span></label>
                    <input type="date" name="schedule_date" class="form-control" required min="<?= date('Y-m-d') ?>">
                </div>
            </div>
            <div style="display: grid; grid-template-columns: 1fr 1fr 1fr; gap: 20px;">
                <div class="form-group">
                    <label>Time</label>
                    <input type="time" name="schedule_time" class="form-control">
                </div>
                <div class="form-group">
                    <label>Inspectors</label>
                    <input type="text" name="inspectors" class="form-control" placeholder="Assigned inspectors">
                </div>
                <div class="form-group">
                    <label>Fees (₱)</label>
                    <input type="number" name="inspection_fees" class="form-control" step="0.01" value="5000.00">
                </div>
            </div>
            <div class="form-group">
                <label>Location</label>
                <input type="text" name="location" class="form-control" placeholder="Inspection location">
            </div>
            <div class="form-group">
                <label>Notes</label>
                <textarea name="notes" class="form-control" rows="3" placeholder="Additional notes..."></textarea>
            </div>
            <button type="submit" class="btn btn-primary"><i class="fas fa-calendar-check"></i> Schedule Inspection</button>
        </form>
    </div>
</div>

<div class="card">
    <div class="card-header"><h3>All Schedules</h3></div>
    <div class="card-body" style="padding: 0;">
        <?php if (empty($schedules)): ?>
            <div class="empty-state"><div class="empty-icon"><i class="fas fa-calendar"></i></div><h3>No Schedules</h3></div>
        <?php else: ?>
            <div class="table-responsive">
                <table class="table">
                    <thead><tr><th>Company</th><th>Date</th><th>Time</th><th>Inspectors</th><th>Fees</th><th>Status</th></tr></thead>
                    <tbody>
                        <?php foreach ($schedules as $s): ?>
                        <tr>
                            <td><strong><?= htmlspecialchars($s['company_name']) ?></strong></td>
                            <td><?= formatDate($s['schedule_date']) ?></td>
                            <td><?= $s['schedule_time'] ?? '-' ?></td>
                            <td><?= htmlspecialchars($s['inspectors'] ?? '-') ?></td>
                            <td><?= formatCurrency($s['inspection_fees']) ?></td>
                            <td><?= getStatusBadge($s['status']) ?></td>
                        </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        <?php endif; ?>
    </div>
</div>

<?php require_once __DIR__ . '/../../includes/footer.php'; ?>
