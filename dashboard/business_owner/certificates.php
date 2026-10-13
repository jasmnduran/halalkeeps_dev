<?php
require_once __DIR__ . '/../../config/app.php';
requireLogin();
requireRole([ROLE_BUSINESS_OWNER]);

$page_title = 'Certificates';
$page_heading = 'My Certificates';
$is_dashboard = true;
$breadcrumbs = [['label' => 'Dashboard', 'url' => BASE_URL . 'dashboard/business_owner/'], ['label' => 'Certificates']];
$user_id = $_SESSION['user_id'];

$stmt = $conn->prepare("
    SELECT hc.*, ha.id as app_id, loi.company_name,
           p.id AS payment_id, p.status AS latest_payment_status, p.paymongo_checkout_url
    FROM halal_certificates hc 
    JOIN hdp_applications ha ON hc.application_id = ha.id 
    JOIN letter_of_intent loi ON ha.loi_id = loi.id
    LEFT JOIN payments p ON p.id = (
        SELECT p2.id FROM payments p2
        WHERE p2.reference_type = 'certification'
          AND p2.reference_id = hc.id
          AND p2.user_id = ?
        ORDER BY p2.created_at DESC LIMIT 1
    )
    WHERE ha.business_owner_id = ? 
    ORDER BY hc.created_at DESC
");
$stmt->bind_param("ii", $user_id, $user_id);
$stmt->execute();
$certificates = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);

require_once __DIR__ . '/../../includes/header.php';
?>

<!-- ══ PAGE HERO ═══════════════════════════════════════════════════════════ -->
<div style="background:linear-gradient(135deg,#064e3b 0%,#065f46 55%,#047857 100%);
            border-radius:var(--radius-xl);padding:24px 32px;margin-bottom:24px;
            position:relative;overflow:hidden;">
    <div style="position:absolute;right:-40px;top:-40px;width:180px;height:180px;border-radius:50%;background:rgba(255,255,255,.05);pointer-events:none;"></div>
    <div style="position:relative;">
        <div style="font-size:.72rem;font-weight:700;letter-spacing:.08em;text-transform:uppercase;color:#6ee7b7;margin-bottom:6px;">
            <i class="fas fa-certificate" style="margin-right:5px;"></i>Halal Certification
        </div>
        <h1 style="font-family:var(--font-display);font-size:1.4rem;font-weight:800;color:#fff;margin:0 0 4px;">
            My Halal Certificates
        </h1>
        <p style="color:rgba(255,255,255,.7);font-size:.85rem;margin:0;">
            View, download, and manage your halal certification documents.
        </p>
    </div>
</div>

<div class="card">
    <div class="card-header">
        <h3><i class="fas fa-certificate" style="color: var(--accent-500); margin-right: 8px;"></i> My Halal Certificates</h3>
    </div>
    <div class="card-body" style="padding: 0;">
        <?php if (empty($certificates)): ?>
            <div class="empty-state">
                <div class="empty-icon"><i class="fas fa-certificate"></i></div>
                <h3>No Certificates Yet</h3>
                <p>Your halal certificates will appear here once your application is approved by the decision committee and president.</p>
            </div>
        <?php else: ?>
            <div style="display: grid; gap: 16px; padding: 20px;">
                <?php foreach ($certificates as $cert): 
                    $is_paid         = ($cert['payment_status'] === 'paid');
                    $can_download    = (in_array($cert['status'], ['awarded','active','pending_payment'])) && $is_paid;
                    $needs_payment   = $cert['status'] === 'pending_payment' && !$is_paid;
                    $has_pending_pay = $needs_payment && !empty($cert['payment_id']) && $cert['latest_payment_status'] === 'pending';
                    $is_rejected     = $cert['status'] === 'rejected';
                    $is_prepared     = $cert['status'] === 'prepared'; // waiting for president
                ?>
                <div class="card" style="border: 1px solid #e2e8f0; border-radius: 10px; overflow: hidden;">
                    <!-- Header -->
                    <div style="display: flex; align-items: center; justify-content: space-between; padding: 16px; background: #f8fafc; border-bottom: 1px solid #e2e8f0; flex-wrap: wrap; gap: 12px;">
                        <div>
                            <div style="font-weight: 700; font-size: 1rem; color: var(--neutral-900);">
                                <?= htmlspecialchars($cert['company_name']) ?>
                            </div>
                            <div style="font-size: 0.85rem; color: var(--neutral-500); margin-top: 2px; font-family: monospace;">
                                <?= htmlspecialchars($cert['certificate_number']) ?>
                            </div>
                        </div>
                        <div style="display: flex; gap: 8px; align-items: center;">
                            <?= getStatusBadge($cert['status']) ?>
                            <?php if ($needs_payment): ?>
                                <span style="background:#fef9c3;color:#92400e;padding:3px 12px;border-radius:99px;font-size:0.75rem;font-weight:700;border:1px solid #fde68a;">
                                    <i class="fas fa-exclamation-triangle" style="margin-right:4px;"></i>Payment Required
                                </span>
                            <?php elseif ($is_paid): ?>
                                <?= getStatusBadge('paid') ?>
                            <?php elseif (!$is_prepared && !$is_rejected): ?>
                                <?= getStatusBadge($cert['payment_status']) ?>
                            <?php endif; ?>
                        </div>
                    </div>

                    <!-- Body -->
                    <div style="display: grid; grid-template-columns: 1fr 1fr 1fr 1fr; gap: 16px; padding: 16px;">
                        <div>
                            <div style="font-size: 0.75rem; font-weight: 700; color: var(--neutral-500); text-transform: uppercase; margin-bottom: 4px;">Business Type</div>
                            <div style="color: var(--neutral-700); font-size: 0.9rem;"><?= htmlspecialchars($cert['business_type'] ?? '—') ?></div>
                        </div>
                        <div>
                            <div style="font-size: 0.75rem; font-weight: 700; color: var(--neutral-500); text-transform: uppercase; margin-bottom: 4px;">Issue Date</div>
                            <div style="color: var(--neutral-700); font-size: 0.9rem;"><?= formatDate($cert['issue_date']) ?></div>
                        </div>
                        <div>
                            <div style="font-size: 0.75rem; font-weight: 700; color: var(--neutral-500); text-transform: uppercase; margin-bottom: 4px;">Expiry Date</div>
                            <div style="color: var(--neutral-700); font-size: 0.9rem;"><?= formatDate($cert['expiry_date']) ?></div>
                        </div>
                        <div>
                            <div style="font-size: 0.75rem; font-weight: 700; color: var(--neutral-500); text-transform: uppercase; margin-bottom: 4px;">Fee</div>
                            <div style="color: var(--accent-700); font-weight: 700; font-size: 0.9rem;"><?= formatCurrency($cert['payment_amount']) ?></div>
                        </div>
                    </div>

                    <!-- Actions -->
                    <div style="padding: 12px 16px; background: #f8fafc; border-top: 1px solid #e2e8f0; display: flex; gap: 8px; flex-wrap: wrap; align-items: center;">
                        <?php if ($is_prepared): ?>
                            <div style="flex:1;color:var(--neutral-600);font-size:0.85rem;padding:8px 0;">
                                <i class="fas fa-hourglass-half" style="margin-right:4px;color:#f59e0b;"></i>
                                Awaiting President approval...
                            </div>

                        <?php elseif ($needs_payment): ?>
                            <!-- ── PAYMENT REQUIRED ── -->
                            <div style="flex:1;display:flex;align-items:center;justify-content:space-between;flex-wrap:wrap;gap:10px;">
                                <div style="font-size:0.85rem;color:#92400e;">
                                    <i class="fas fa-info-circle" style="margin-right:5px;color:#f59e0b;"></i>
                                    Pay the certification fee to unlock and download your certificate.
                                </div>
                                <div style="display:flex;gap:8px;">
                                    <?php if ($has_pending_pay && !empty($cert['paymongo_checkout_url'])): ?>
                                        <a href="<?= htmlspecialchars($cert['paymongo_checkout_url']) ?>"
                                           class="btn btn-sm btn-outline">
                                            <i class="fas fa-redo"></i> Resume Checkout
                                        </a>
                                    <?php endif; ?>
                                    <a href="<?= BASE_URL ?>dashboard/business_owner/pay_certificate.php?cert_id=<?= $cert['id'] ?>"
                                       class="btn btn-sm btn-primary"
                                       style="background:linear-gradient(135deg,#059669,#047857);">
                                        <i class="fas fa-credit-card"></i>
                                        Pay <?= formatCurrency($cert['payment_amount']) ?>
                                    </a>
                                </div>
                            </div>

                        <?php elseif ($is_rejected): ?>
                            <div style="flex:1;color:#991b1b;font-size:0.85rem;padding:8px 0;">
                                <i class="fas fa-times-circle" style="margin-right:4px;"></i>
                                Certificate rejected.
                                <?php if (!empty($cert['rejection_reason'])): ?>
                                    <span style="color:var(--neutral-600);">Reason: <?= htmlspecialchars($cert['rejection_reason']) ?></span>
                                <?php endif; ?>
                            </div>

                        <?php elseif ($can_download): ?>
                            <a href="<?= BASE_URL ?>dashboard/decision_committee/cert_pdf.php?id=<?= $cert['id'] ?>"
                               target="_blank" class="btn btn-success btn-sm">
                                <i class="fas fa-download"></i> Download Certificate
                            </a>
                            <a href="<?= BASE_URL ?>dashboard/decision_committee/cert_pdf.php?id=<?= $cert['id'] ?>&preview=1"
                               target="_blank" class="btn btn-outline btn-sm">
                                <i class="fas fa-eye"></i> Preview
                            </a>

                        <?php else: ?>
                            <div style="flex:1;text-align:center;color:var(--neutral-500);font-size:0.85rem;padding:8px 0;">
                                <i class="fas fa-lock" style="margin-right:4px;"></i>
                                Certificate access is locked
                            </div>
                        <?php endif; ?>
                    </div>
                </div>
                <?php endforeach; ?>
            </div>
        <?php endif; ?>
    </div>
</div>

<?php require_once __DIR__ . '/../../includes/footer.php'; ?>
