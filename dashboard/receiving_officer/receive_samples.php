<?php
require_once __DIR__ . '/../../config/app.php';
requireLogin();
requireRole([ROLE_RECEIVING_OFFICER]);

$page_title = 'Receive Samples';
$page_heading = 'Receive Samples';
$is_dashboard = true;
$breadcrumbs = [['label' => 'Dashboard', 'url' => BASE_URL . 'dashboard/receiving_officer/'], ['label' => 'Receive Samples']];
$user_id = $_SESSION['user_id'];
$success = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $request_id = intval($_POST['request_id']);
    $action = sanitize($_POST['action']);
    $notes = sanitize($_POST['notes'] ?? '');
    
    if ($action === 'receive') {
        $stmt = $conn->prepare("UPDATE laboratory_requests SET status = 'received', received_by = ?, received_at = NOW(), receiving_notes = ? WHERE id = ?");
        $stmt->bind_param("isi", $user_id, $notes, $request_id);
        if ($stmt->execute()) {
            // Notify lab analyst
            $analysts = $conn->query("SELECT id FROM users WHERE role_id = " . ROLE_LAB_ANALYST . " AND role_status = 'approved'");
            while ($a = $analysts->fetch_assoc()) {
                createNotification($conn, $a['id'], 'New Sample Received',
                    'A new sample has been received and is ready for analysis.', 'action_required',
                    BASE_URL . 'dashboard/lab_analyst/analyze_samples.php');
            }
            $success = 'Sample received and assigned for testing.';
        }
    } elseif ($action === 'reject') {
        $stmt = $conn->prepare("UPDATE laboratory_requests SET status = 'rejected', receiving_notes = ? WHERE id = ?");
        $stmt->bind_param("si", $notes, $request_id);
        if ($stmt->execute()) {
            $success = 'Sample rejected.';
        }
    }
}

$filter = $_GET['filter'] ?? 'submitted';
$where = $filter === 'all' ? '' : "WHERE lr.status = '$filter'";
$requests = $conn->query("SELECT lr.*, loi.company_name, u.full_name as owner_name FROM laboratory_requests lr JOIN hdp_applications ha ON lr.application_id = ha.id JOIN letter_of_intent loi ON ha.loi_id = loi.id JOIN users u ON lr.business_owner_id = u.id $where ORDER BY lr.created_at DESC")->fetch_all(MYSQLI_ASSOC);

require_once __DIR__ . '/../../includes/header.php';
?>

<?php if ($success): ?>
    <div class="alert alert-success"><i class="fas fa-check-circle"></i><span><?= $success ?></span><button class="close-alert"><i class="fas fa-times"></i></button></div>
<?php endif; ?>

<div class="card" style="margin-bottom: 16px;">
    <div class="card-body" style="display: flex; gap: 8px;">
        <a href="?filter=submitted" class="btn btn-sm <?= $filter === 'submitted' ? 'btn-primary' : 'btn-secondary' ?>">Pending</a>
        <a href="?filter=received" class="btn btn-sm <?= $filter === 'received' ? 'btn-primary' : 'btn-secondary' ?>">Received</a>
        <a href="?filter=all" class="btn btn-sm <?= $filter === 'all' ? 'btn-primary' : 'btn-secondary' ?>">All</a>
    </div>
</div>

<div class="card">
    <div class="card-header"><h3>Laboratory Requests</h3></div>
    <div class="card-body" style="padding: 0;">
        <?php if (empty($requests)): ?>
            <div class="empty-state"><div class="empty-icon"><i class="fas fa-inbox"></i></div><h3>No Requests</h3></div>
        <?php else: ?>
            <div class="table-responsive">
                <table class="table">
                    <thead><tr><th>Company</th><th>Owner</th><th>Sample</th><th>Fee</th><th>Payment</th><th>Status</th><th>Actions</th></tr></thead>
                    <tbody>
                        <?php foreach ($requests as $lr): ?>
                        <tr>
                            <td><strong><?= htmlspecialchars($lr['company_name']) ?></strong></td>
                            <td style="font-size:0.85rem"><?= htmlspecialchars($lr['owner_name']) ?></td>
                            <td style="font-size:0.85rem"><?= htmlspecialchars(substr($lr['sample_description'], 0, 40)) ?></td>
                            <td><?= formatCurrency($lr['payment_testing_fee']) ?></td>
                            <td><?= getStatusBadge($lr['payment_status']) ?></td>
                            <td><?= getStatusBadge($lr['status']) ?></td>
                            <td>
                                <?php if ($lr['status'] === 'submitted'): ?>
                                <div style="display:flex;gap:4px">
                                    <form method="POST" style="display:inline"><input type="hidden" name="request_id" value="<?= $lr['id'] ?>"><input type="hidden" name="action" value="receive"><input type="hidden" name="notes" value=""><button type="submit" class="btn btn-sm btn-success" title="Receive"><i class="fas fa-check"></i></button></form>
                                    <button class="btn btn-sm btn-danger" data-modal="rejectModal<?= $lr['id'] ?>" title="Reject"><i class="fas fa-times"></i></button>
                                </div>
                                
                                <div class="modal-overlay" id="rejectModal<?= $lr['id'] ?>">
                                    <div class="modal" style="max-width:500px">
                                        <div class="modal-header"><h3>Reject Sample</h3><button class="modal-close"><i class="fas fa-times"></i></button></div>
                                        <div class="modal-body">
                                            <form method="POST">
                                                <input type="hidden" name="request_id" value="<?= $lr['id'] ?>">
                                                <input type="hidden" name="action" value="reject">
                                                <div class="form-group"><label>Reason</label><textarea name="notes" class="form-control" rows="3"></textarea></div>
                                                <button type="submit" class="btn btn-danger btn-block"><i class="fas fa-times"></i> Reject</button>
                                            </form>
                                        </div>
                                    </div>
                                </div>
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
