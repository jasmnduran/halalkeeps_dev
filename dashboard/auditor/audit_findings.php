<?php
require_once __DIR__ . '/../../config/app.php';
requireLogin();
requireRole([ROLE_AUDITOR_TECHNICAL, ROLE_AUDITOR_SHARIAH]);

$page_title = 'Audit Findings';
$page_heading = 'Audit Findings';
$is_dashboard = true;
$breadcrumbs = [['label' => 'Dashboard', 'url' => BASE_URL . 'dashboard/auditor/'], ['label' => 'Audit Findings']];

$inspections = $conn->query("SELECT i.*, loi.company_name, s.schedule_date, ut.full_name as tech_name, us.full_name as shariah_name FROM inspections i JOIN hdp_applications ha ON i.application_id = ha.id JOIN letter_of_intent loi ON ha.loi_id = loi.id JOIN inspection_schedules s ON i.schedule_id = s.id LEFT JOIN users ut ON i.auditor_technical_id = ut.id LEFT JOIN users us ON i.auditor_shariah_id = us.id WHERE i.status = 'completed' ORDER BY i.created_at DESC")->fetch_all(MYSQLI_ASSOC);

require_once __DIR__ . '/../../includes/header.php';
?>

<div class="card">
    <div class="card-header"><h3><i class="fas fa-clipboard" style="color: var(--primary-600); margin-right: 8px;"></i> Completed Audit Findings</h3></div>
    <div class="card-body" style="padding: 0;">
        <?php if (empty($inspections)): ?>
            <div class="empty-state"><div class="empty-icon"><i class="fas fa-clipboard"></i></div><h3>No Completed Inspections</h3></div>
        <?php else: ?>
            <div class="table-responsive">
                <table class="table">
                    <thead><tr><th>Company</th><th>Date</th><th>Technical Auditor</th><th>Shariah Auditor</th><th>Conformity</th><th>Details</th></tr></thead>
                    <tbody>
                        <?php foreach ($inspections as $i): ?>
                        <tr>
                            <td><strong><?= htmlspecialchars($i['company_name']) ?></strong></td>
                            <td><?= formatDate($i['inspection_date'] ?? $i['created_at']) ?></td>
                            <td><?= htmlspecialchars($i['tech_name'] ?? 'N/A') ?></td>
                            <td><?= htmlspecialchars($i['shariah_name'] ?? 'N/A') ?></td>
                            <td><?= getStatusBadge($i['conformity_status']) ?></td>
                            <td>
                                <button class="btn btn-sm btn-outline" data-modal="findingModal<?= $i['id'] ?>"><i class="fas fa-eye"></i></button>
                            </td>
                        </tr>
                        <div class="modal-overlay" id="findingModal<?= $i['id'] ?>">
                            <div class="modal" style="max-width: 700px;">
                                <div class="modal-header"><h3>Findings: <?= htmlspecialchars($i['company_name']) ?></h3><button class="modal-close"><i class="fas fa-times"></i></button></div>
                                <div class="modal-body">
                                    <div style="margin-bottom: 16px;"><strong>Audit Findings:</strong><div style="background:var(--neutral-50);padding:12px;border-radius:8px;margin-top:6px"><?= nl2br(htmlspecialchars($i['audit_findings'] ?? 'No findings recorded.')) ?></div></div>
                                    <div style="margin-bottom: 16px;"><strong>Conformity:</strong> <?= getStatusBadge($i['conformity_status']) ?></div>
                                    <div><strong>Remarks:</strong><div style="background:var(--neutral-50);padding:12px;border-radius:8px;margin-top:6px"><?= nl2br(htmlspecialchars($i['remarks'] ?? 'No remarks.')) ?></div></div>
                                </div>
                            </div>
                        </div>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        <?php endif; ?>
    </div>
</div>

<?php require_once __DIR__ . '/../../includes/footer.php'; ?>
