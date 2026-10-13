<?php
require_once __DIR__ . '/../../config/app.php';
requireLogin();
requireRole([ROLE_BUSINESS_OWNER]);

$page_title = 'Laboratory';
$page_heading = 'Laboratory Requests';
$is_dashboard = true;
$breadcrumbs = [['label' => 'Dashboard', 'url' => BASE_URL . 'dashboard/business_owner/'], ['label' => 'Laboratory']];
$user_id = $_SESSION['user_id'];

$stmt = $conn->prepare("SELECT lr.*, loi.company_name FROM laboratory_requests lr JOIN hdp_applications ha ON lr.application_id = ha.id JOIN letter_of_intent loi ON ha.loi_id = loi.id WHERE lr.business_owner_id = ? ORDER BY lr.created_at DESC");
$stmt->bind_param("i", $user_id);
$stmt->execute();
$requests = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);

require_once __DIR__ . '/../../includes/header.php';
?>

<div class="card">
    <div class="card-header">
        <h3><i class="fas fa-flask" style="color: var(--primary-600); margin-right: 8px;"></i> Laboratory Requests</h3>
    </div>
    <div class="card-body" style="padding: 0;">
        <?php if (empty($requests)): ?>
            <div class="empty-state">
                <div class="empty-icon"><i class="fas fa-flask"></i></div>
                <h3>No Laboratory Requests</h3>
                <p>Laboratory requests will appear here once they are created during the certification process.</p>
            </div>
        <?php else: ?>
            <div class="table-responsive">
                <table class="table">
                    <thead>
                        <tr><th>Company</th><th>Description</th><th>Fee</th><th>Payment</th><th>Status</th></tr>
                    </thead>
                    <tbody>
                        <?php foreach ($requests as $req): ?>
                        <tr>
                            <td><strong><?= htmlspecialchars($req['company_name']) ?></strong></td>
                            <td><?= htmlspecialchars(substr($req['sample_description'], 0, 50)) ?>...</td>
                            <td><?= formatCurrency($req['payment_testing_fee']) ?></td>
                            <td><?= getStatusBadge($req['payment_status']) ?></td>
                            <td><?= getStatusBadge($req['status']) ?></td>
                        </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        <?php endif; ?>
    </div>
</div>

<?php require_once __DIR__ . '/../../includes/footer.php'; ?>
