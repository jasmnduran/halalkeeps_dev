<?php
require_once __DIR__ . '/../../config/app.php';
requireLogin();
requireRole([ROLE_BUSINESS_OWNER]);

$page_title = 'My Payments';
$page_heading = 'Payments';
$is_dashboard = true;
$breadcrumbs = [['label' => 'Dashboard', 'url' => BASE_URL . 'dashboard/business_owner/'], ['label' => 'Payments']];
$user_id = $_SESSION['user_id'];
$error = ''; $success = '';

// ── Handle PayMongo for laboratory ───────────────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['pay_laboratory'])) {
    $request_id = intval($_POST['request_id']);

    $check = $conn->prepare("
        SELECT lr.id, lr.payment_testing_fee, lr.request_number, loi.company_name
        FROM laboratory_requests lr
        JOIN hdp_applications ha ON lr.application_id = ha.id
        JOIN letter_of_intent loi ON ha.loi_id = loi.id
        WHERE lr.id = ? AND lr.business_owner_id = ? AND lr.status = 'pending_payment'
        LIMIT 1
    ");
    $check->bind_param("ii", $request_id, $user_id);
    $check->execute();
    $lab_req = $check->get_result()->fetch_assoc();

    if (!$lab_req) {
        $error = 'Laboratory request not found or not eligible for payment.';
    } else {
        $existing = $conn->prepare("SELECT id, status FROM payments WHERE user_id = ? AND reference_type = 'laboratory' AND reference_id = ? ORDER BY created_at DESC LIMIT 1");
        $existing->bind_param("ii", $user_id, $request_id);
        $existing->execute();
        $existing_payment = $existing->get_result()->fetch_assoc();

        if (($existing_payment['status'] ?? '') === 'verified') {
            $error = 'This laboratory payment has already been verified.';
        }
    }

    if (!$error) {
        $amount = floatval($lab_req['payment_testing_fee']);
        $ref = $lab_req['request_number'] ?: ('LAB-' . $lab_req['id']);
        $success_url = 'http://' . $_SERVER['HTTP_HOST'] . BASE_URL . 'dashboard/business_owner/payments.php?lab_success=1&request_id=' . $request_id;
        $cancel_url  = 'http://' . $_SERVER['HTTP_HOST'] . BASE_URL . 'dashboard/business_owner/payments.php?lab_cancelled=1&request_id=' . $request_id;

        $checkout = createPayMongoCheckoutSession(
            $amount,
            'Laboratory Testing Fee - ' . $lab_req['company_name'],
            $success_url,
            $cancel_url,
            ['company_name' => $lab_req['company_name'], 'request_id' => (string)$request_id, 'type' => 'laboratory_testing', 'user_id' => (string)$user_id]
        );

        if (!$checkout['success']) {
            $error = $checkout['message'];
        } else {
            $checkout_data = $checkout['data']['data'] ?? [];
            $checkout_id  = $checkout_data['id'] ?? '';
            $checkout_url = $checkout_data['attributes']['checkout_url'] ?? '';

            if (empty($checkout_id) || empty($checkout_url)) {
                $error = 'PayMongo did not return a checkout URL.';
            } else {
                $raw_response = json_encode($checkout['data']);
                if (!empty($existing_payment['id'])) {
                    $upd = $conn->prepare("UPDATE payments SET amount=?, payment_method='paymongo', transaction_reference=?, paymongo_checkout_url=?, paymongo_raw_response=?, status='pending', paid_at=NULL WHERE id=?");
                    $upd->bind_param("dsssi", $amount, $checkout_id, $checkout_url, $raw_response, $existing_payment['id']);
                    $saved = $upd->execute();
                } else {
                    $ins = $conn->prepare("INSERT INTO payments (user_id, reference_type, reference_id, amount, payment_method, transaction_reference, paymongo_checkout_url, paymongo_raw_response, status, paid_at) VALUES (?, 'laboratory', ?, ?, 'paymongo', ?, ?, ?, 'pending', NULL)");
                    $ins->bind_param("iidsss", $user_id, $request_id, $amount, $checkout_id, $checkout_url, $raw_response);
                    $saved = $ins->execute();
                }
                if ($saved) { header('Location: ' . $checkout_url); exit(); }
                $error = 'Failed to save PayMongo checkout session.';
            }
        }
    }
}

// ── Handle PayMongo success callback for laboratory ──────────────────────────
if (isset($_GET['lab_success'], $_GET['request_id'])) {
    $request_id = intval($_GET['request_id']);
    $pq = $conn->prepare("SELECT p.* FROM payments p WHERE p.user_id=? AND p.reference_type='laboratory' AND p.reference_id=? ORDER BY p.created_at DESC LIMIT 1");
    $pq->bind_param("ii", $user_id, $request_id);
    $pq->execute();
    $payment = $pq->get_result()->fetch_assoc();

    if ($payment && !empty($payment['transaction_reference'])) {
        $checkout = retrievePayMongoCheckoutSession($payment['transaction_reference']);
        if ($checkout['success'] && isPayMongoCheckoutPaid($checkout['data'])) {
            $upd = $conn->prepare("UPDATE payments SET status='verified', paid_at=NOW() WHERE id=?");
            $upd->bind_param("i", $payment['id']);
            $upd->execute();
            $upd2 = $conn->prepare("UPDATE laboratory_requests SET status='received', payment_status='paid' WHERE id=?");
            $upd2->bind_param("i", $request_id);
            $upd2->execute();
            // Notify receiving officer / lab analyst
            $ro_q = $conn->query("SELECT id FROM users WHERE role_id IN (" . ROLE_RECEIVING_OFFICER . "," . ROLE_LABORATORY_ANALYST . ") AND role_status='approved'");
            if ($ro_q) {
                while ($ro = $ro_q->fetch_assoc()) {
                    createNotification($conn, $ro['id'], 'Laboratory Payment Received', 'Payment for lab request #' . $request_id . ' has been verified. Samples are ready for analysis.', 'success', BASE_URL . 'dashboard/receiving_officer/receive_samples.php');
                }
            }
            $success = 'Payment completed and verified. The laboratory team has been notified.';
        } else {
            $success = 'Checkout completed. Payment verification is still pending.';
        }
    } else {
        $error = 'Unable to find the payment record.';
    }
}

if (isset($_GET['lab_cancelled'])) {
    $error = 'PayMongo checkout was cancelled. You can try again anytime.';
}

// ── Fetch all payments for this user ─────────────────────────────────────────
$payments = $conn->prepare("
    SELECT p.*,
        CASE
            WHEN p.reference_type = 'inspection' THEN CONCAT('Inspection Fee — ', loi_i.company_name)
            WHEN p.reference_type = 'laboratory' THEN CONCAT('Laboratory Testing Fee — ', COALESCE(lr.request_number, CONCAT('LAB-', lr.id)))
            WHEN p.reference_type = 'certification' THEN CONCAT('Certification Fee — ', loi_c.company_name)
            ELSE CONCAT(p.reference_type, ' #', p.reference_id)
        END AS description,
        COALESCE(lr.request_number, '') AS lab_ref,
        lr.id AS lab_request_id,
        lr.status AS lab_status,
        lr.payment_testing_fee AS lab_fee,
        lr.sample_description AS lab_sample_desc,
        lr.analysis_requirements AS lab_analysis_req,
        lr.received_at AS lab_received_at,
        lr.receiving_notes AS lab_receiving_notes
    FROM payments p
    LEFT JOIN inspection_schedules ins ON p.reference_type = 'inspection' AND p.reference_id = ins.id
    LEFT JOIN hdp_applications ha_i ON ins.application_id = ha_i.id
    LEFT JOIN letter_of_intent loi_i ON ha_i.loi_id = loi_i.id
    LEFT JOIN laboratory_requests lr ON p.reference_type = 'laboratory' AND p.reference_id = lr.id
    LEFT JOIN hdp_applications ha_c ON p.reference_type = 'certification' AND p.reference_id = ha_c.id
    LEFT JOIN letter_of_intent loi_c ON ha_c.loi_id = loi_c.id
    WHERE p.user_id = ?
    ORDER BY p.created_at DESC
");
$payments->bind_param("i", $user_id);
$payments->execute();
$all_payments = $payments->get_result()->fetch_all(MYSQLI_ASSOC);

// ── Fetch pending_payment lab requests (not yet in payments table) ────────────
$pending_labs = $conn->prepare("
    SELECT lr.*, loi.company_name,
        p.id AS payment_id, p.status AS payment_status, p.paid_at
    FROM laboratory_requests lr
    JOIN hdp_applications ha ON lr.application_id = ha.id
    JOIN letter_of_intent loi ON ha.loi_id = loi.id
    LEFT JOIN payments p ON p.reference_type = 'laboratory' AND p.reference_id = lr.id AND p.user_id = ?
    WHERE lr.business_owner_id = ? AND lr.status = 'pending_payment'
    ORDER BY lr.created_at DESC
");
$pending_labs->bind_param("ii", $user_id, $user_id);
$pending_labs->execute();
$pending_lab_requests = $pending_labs->get_result()->fetch_all(MYSQLI_ASSOC);

require_once __DIR__ . '/../../includes/header.php';
?>

<!-- ══ PAGE HERO ═══════════════════════════════════════════════════════════ -->
<div style="background:linear-gradient(135deg,#92400e 0%,#b45309 50%,#d97706 100%);
            border-radius:var(--radius-xl);padding:24px 32px;margin-bottom:24px;
            position:relative;overflow:hidden;">
    <div style="position:absolute;right:-40px;top:-40px;width:180px;height:180px;border-radius:50%;background:rgba(255,255,255,.06);pointer-events:none;"></div>
    <div style="position:relative;">
        <div style="font-size:.72rem;font-weight:700;letter-spacing:.08em;text-transform:uppercase;color:#fcd34d;margin-bottom:6px;">
            <i class="fas fa-credit-card" style="margin-right:5px;"></i>Billing & Payments
        </div>
        <h1 style="font-family:var(--font-display);font-size:1.4rem;font-weight:800;color:#fff;margin:0 0 4px;">
            My Payments
        </h1>
        <p style="color:rgba(255,255,255,.75);font-size:.85rem;margin:0;">
            Pay certification and laboratory testing fees, and view your full payment history.
        </p>
    </div>
</div>

<?php if ($success): ?>
    <div class="alert alert-success"><i class="fas fa-check-circle"></i><span><?= $success ?></span><button class="close-alert"><i class="fas fa-times"></i></button></div>
<?php endif; ?>
<?php if ($error): ?>
    <div class="alert alert-danger"><i class="fas fa-exclamation-circle"></i><span><?= $error ?></span><button class="close-alert"><i class="fas fa-times"></i></button></div>
<?php endif; ?>

<?php foreach ($pending_lab_requests as $lr):
    $analysis_data = json_decode($lr['analysis_requirements'], true);
    $tests = $analysis_data['tests'] ?? [];
    $delivery_method = $analysis_data['delivery_method'] ?? 'pickup';
    $request_interpretation = $analysis_data['request_interpretation'] ?? 'no';
    $special_instructions = $analysis_data['special_instructions'] ?? '';
    $ref = $lr['request_number'] ?: ('LAB-' . $lr['id']);
            // Determine payment status for this lab request
        $payment_status = $lr['payment_status'] ?? '';
        $is_pending = ($payment_status === 'pending');
        $payment_done = in_array($payment_status, ['paid', 'verified']);

    // Parse sample lines from receiving officer
    $sample_lines = [];
    if (!empty($lr['sample_description']) && $lr['sample_description'] !== 'Pending - To be filled by Receiving Officer') {
        foreach (explode("\n", $lr['sample_description']) as $line) {
            $line = trim($line);
            if ($line !== '') {
                $parts = explode(':', $line, 2);
                $sample_lines[] = ['code' => trim($parts[0] ?? ''), 'desc' => trim($parts[1] ?? '')];
            }
        }
    }
?>
<!-- ── Pending Payment Lab Request Card ── -->
<div class="card" style="margin-bottom:24px;border-top:4px solid #f59e0b;">
    <!-- Header -->
    <div class="card-header" style="background:#fffbeb;border-bottom:1px solid #fde68a;display:flex;align-items:center;justify-content:space-between;flex-wrap:wrap;gap:12px;">
        <div style="display:flex;align-items:center;gap:12px;">
            <div style="width:42px;height:42px;border-radius:10px;background:white;display:flex;align-items:center;justify-content:center;color:#d97706;font-size:1.1rem;box-shadow:0 1px 4px rgba(0,0,0,0.08);">
                <i class="fas fa-flask"></i>
            </div>
            <div>
                <div style="font-family:var(--font-display);font-weight:800;font-size:1.05rem;color:var(--neutral-900);"><?= htmlspecialchars($ref) ?></div>
                <div style="font-size:0.8rem;color:var(--neutral-500);"><?= htmlspecialchars($lr['company_name']) ?> &bull; Laboratory Testing Fee</div>
            </div>
        </div>
        <div style="display:flex;align-items:center;gap:8px;">
            <span style="background:#fef3c7;color:#92400e;border:1px solid #fcd34d;padding:4px 14px;border-radius:20px;font-size:0.78rem;font-weight:700;text-transform:uppercase;">
                <?= $payment_done ? 'PAID' : ($is_pending ? 'PAYMENT PENDING VERIFICATION' : 'PAYMENT REQUIRED') ?>
            </span>
        </div>
    </div>

    <div class="card-body">
        <!-- Top metadata strip (like the image) -->
        <div style="display:grid;grid-template-columns:repeat(auto-fit,minmax(160px,1fr));gap:0;border-bottom:1px solid var(--neutral-100);padding-bottom:20px;margin-bottom:20px;">
            <div style="padding-right:20px;border-right:1px solid var(--neutral-100);">
                <div style="font-size:0.72rem;font-weight:700;color:var(--neutral-400);text-transform:uppercase;letter-spacing:0.5px;margin-bottom:4px;">Request Date</div>
                <div style="font-weight:700;font-size:1rem;color:var(--neutral-900);"><?= formatDateTime($lr['created_at']) ?></div>
            </div>
            <div style="padding:0 20px;border-right:1px solid var(--neutral-100);">
                <div style="font-size:0.72rem;font-weight:700;color:var(--neutral-400);text-transform:uppercase;letter-spacing:0.5px;margin-bottom:4px;">Testing Fee</div>
                <div style="font-weight:700;font-size:1rem;color:var(--primary-700);"><?= formatCurrency($lr['payment_testing_fee']) ?></div>
            </div>
            <div style="padding:0 20px;border-right:1px solid var(--neutral-100);">
                <div style="font-size:0.72rem;font-weight:700;color:var(--neutral-400);text-transform:uppercase;letter-spacing:0.5px;margin-bottom:4px;">Request Status</div>
                <?= getStatusBadge($lr['status']) ?>
            </div>
            <div style="padding:0 20px;border-right:1px solid var(--neutral-100);">
                <div style="font-size:0.72rem;font-weight:700;color:var(--neutral-400);text-transform:uppercase;letter-spacing:0.5px;margin-bottom:4px;">Delivery Method</div>
                <div style="font-weight:600;color:var(--neutral-700);"><?= ucfirst(htmlspecialchars($delivery_method)) ?></div>
            </div>
            <div style="padding-left:20px;">
                <div style="font-size:0.72rem;font-weight:700;color:var(--neutral-400);text-transform:uppercase;letter-spacing:0.5px;margin-bottom:4px;">Interpretation</div>
                <div style="font-weight:600;color:var(--neutral-700);"><?= $request_interpretation === 'yes' ? '<span style="color:#059669;">Requested</span>' : 'Not requested' ?></div>
            </div>
        </div>

        <!-- Two-column body -->
        <div style="display:grid;grid-template-columns:1fr 1fr;gap:28px;align-items:start;">

            <!-- LEFT: Receiving Officer Details -->
            <div>
                <div style="font-size:0.72rem;font-weight:700;color:var(--neutral-400);text-transform:uppercase;letter-spacing:0.5px;margin-bottom:10px;">
                    <i class="fas fa-user-check" style="margin-right:4px;color:var(--primary-500);"></i> Receiving Officer Details
                </div>

                <div style="margin-bottom:14px;">
                    <div style="font-size:0.75rem;color:var(--neutral-500);margin-bottom:3px;">Received At</div>
                    <div style="background:var(--neutral-50);border-radius:8px;padding:10px 14px;color:var(--neutral-700);font-weight:600;">
                        <?= !empty($lr['received_at']) ? formatDateTime($lr['received_at']) : '<span style="color:var(--neutral-400);">—</span>' ?>
                    </div>
                </div>

                <div style="margin-bottom:14px;">
                    <div style="font-size:0.75rem;color:var(--neutral-500);margin-bottom:3px;">Receiving Notes</div>
                    <div style="background:var(--neutral-50);border-radius:8px;padding:10px 14px;color:var(--neutral-700);min-height:44px;">
                        <?= nl2br(htmlspecialchars($lr['receiving_notes'] ?: 'No additional notes.')) ?>
                    </div>
                </div>

                <?php if (!empty($sample_lines)): ?>
                <div>
                    <div style="font-size:0.75rem;color:var(--neutral-500);margin-bottom:6px;"><i class="fas fa-barcode" style="margin-right:4px;"></i> Assigned Sample Codes</div>
                    <div style="display:flex;flex-direction:column;gap:5px;">
                        <?php foreach ($sample_lines as $sl): ?>
                        <div style="border:1px solid var(--neutral-200);border-radius:8px;padding:8px 12px;display:flex;justify-content:space-between;align-items:center;gap:12px;background:white;">
                            <span style="font-family:monospace;font-weight:700;color:var(--primary-600);font-size:0.88rem;"><?= htmlspecialchars($sl['code']) ?></span>
                            <span style="font-size:0.82rem;color:var(--neutral-600);"><?= htmlspecialchars($sl['desc']) ?></span>
                        </div>
                        <?php endforeach; ?>
                    </div>
                </div>
                <?php endif; ?>
            </div>

            <!-- RIGHT: Tests + Pay Button -->
            <div>
                <div style="font-size:0.72rem;font-weight:700;color:var(--neutral-400);text-transform:uppercase;letter-spacing:0.5px;margin-bottom:10px;">
                    <i class="fas fa-list-ul" style="margin-right:4px;color:var(--primary-500);"></i> Tests Requested (<?= count($tests) ?>)
                </div>

                <?php if (!empty($tests)): ?>
                <div style="border:1px solid var(--neutral-200);border-radius:10px;overflow:hidden;margin-bottom:16px;">
                    <?php foreach ($tests as $i => $t): ?>
                    <div style="display:flex;align-items:center;gap:12px;padding:10px 14px;<?= $i > 0 ? 'border-top:1px solid var(--neutral-100);' : '' ?>background:<?= $i % 2 === 1 ? '#fafafa' : 'white' ?>;">
                        <?php if (!empty($t['sample_photo'])): ?>
                            <a href="<?= BASE_URL . 'uploads/' . htmlspecialchars($t['sample_photo']) ?>" target="_blank">
                                <img src="<?= BASE_URL . 'uploads/' . htmlspecialchars($t['sample_photo']) ?>" style="width:44px;height:44px;object-fit:cover;border-radius:6px;border:1px solid var(--neutral-200);flex-shrink:0;" alt="Sample">
                            </a>
                        <?php else: ?>
                            <div style="width:44px;height:44px;border-radius:6px;background:var(--neutral-100);display:flex;align-items:center;justify-content:center;color:var(--neutral-400);flex-shrink:0;"><i class="fas fa-image"></i></div>
                        <?php endif; ?>
                        <div style="flex:1;min-width:0;">
                            <div style="font-weight:600;color:var(--neutral-800);font-size:0.88rem;white-space:nowrap;overflow:hidden;text-overflow:ellipsis;"><?= htmlspecialchars($t['test_name'] ?? '') ?></div>
                            <div style="font-size:0.78rem;color:var(--neutral-500);">Qty <?= htmlspecialchars($t['quantity'] ?? '1') ?> &times; ₱<?= number_format(floatval($t['unit_cost'] ?? 0), 2) ?></div>
                        </div>
                        <div style="font-weight:700;color:var(--neutral-700);white-space:nowrap;font-size:0.9rem;">₱<?= number_format(floatval($t['total'] ?? 0), 2) ?></div>
                    </div>
                    <?php endforeach; ?>
                    <div style="background:#f0fdf4;padding:10px 14px;display:flex;justify-content:space-between;border-top:2px solid #bbf7d0;">
                        <span style="font-weight:700;color:var(--neutral-600);">TOTAL</span>
                        <span style="font-weight:800;color:var(--primary-700);"><?= formatCurrency($lr['payment_testing_fee']) ?></span>
                    </div>
                </div>
                <?php endif; ?>

                                <?php if ($is_pending): ?>
    <div style="background:#eff6ff;border:1px solid #bfdbfe;border-radius:10px;padding:14px;display:flex;align-items:center;gap:10px;">
        <i class="fas fa-clock" style="color:#2563eb;font-size:1.1rem;"></i>
        <div style="font-size:0.88rem;color:#1e40af;">Payment submitted via PayMongo. Awaiting verification by the laboratory team.</div>
    </div>
<?php elseif (!$payment_done): ?>
    <form method="POST">
        <input type="hidden" name="pay_laboratory" value="1">
        <input type="hidden" name="request_id" value="<?= $lr['id'] ?>">
        <button type="submit" class="btn btn-primary" style="width:100%;font-size:1rem;padding:14px;border-radius:10px;">
            <i class="fas fa-credit-card"></i> Pay Testing Fee — <?= formatCurrency($lr['payment_testing_fee']) ?>
        </button>
    </form>
    <div style="text-align:center;margin-top:8px;">
        <img src="https://assets.paymongo.com/paymongo-logo.svg" alt="PayMongo" style="height:18px;opacity:0.5;" onerror="this.style.display='none'">
        <span style="font-size:0.75rem;color:var(--neutral-400);margin-left:6px;">Secure payment via PayMongo</span>
    </div>
<?php endif; ?>
            </div>
        </div>
    </div>
</div>
<?php endforeach; ?>

<!-- ── All Payments History ── -->
<div class="card">
    <div class="card-header">
        <h3><i class="fas fa-receipt" style="color:var(--primary-600);margin-right:8px;"></i> Payment History</h3>
    </div>
    <div class="card-body" style="padding:0;">
        <?php if (empty($all_payments)): ?>
        <div class="empty-state" style="padding:40px;">
            <div class="empty-icon"><i class="fas fa-receipt"></i></div>
            <h3>No Payments Yet</h3>
            <p>Your payment history will appear here once you make a payment.</p>
        </div>
        <?php else: ?>
        <div class="table-responsive">
            <table class="table" style="margin:0;">
                <thead>
                    <tr>
                        <th>Description</th>
                        <th>Type</th>
                        <th style="text-align:right;">Amount</th>
                        <th>Method</th>
                        <th>Status</th>
                        <th>Date</th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($all_payments as $p):
                        $type_icons = ['inspection' => 'fas fa-calendar-check', 'laboratory' => 'fas fa-flask', 'certification' => 'fas fa-certificate', 'order' => 'fas fa-shopping-bag'];
                        $type_colors = ['inspection' => '#2563eb', 'laboratory' => '#7c3aed', 'certification' => '#059669', 'order' => '#d97706'];
                        $icon = $type_icons[$p['reference_type']] ?? 'fas fa-money-bill';
                        $color = $type_colors[$p['reference_type']] ?? 'var(--neutral-500)';
                    ?>
                    <tr>
                        <td>
                            <div style="display:flex;align-items:center;gap:10px;">
                                <div style="width:34px;height:34px;border-radius:8px;background:<?= $color ?>18;display:flex;align-items:center;justify-content:center;color:<?= $color ?>;font-size:0.9rem;flex-shrink:0;">
                                    <i class="<?= $icon ?>"></i>
                                </div>
                                <div>
                                    <div style="font-weight:600;font-size:0.88rem;color:var(--neutral-800);"><?= htmlspecialchars($p['description']) ?></div>
                                    <?php if (!empty($p['transaction_reference'])): ?>
                                    <div style="font-size:0.75rem;color:var(--neutral-400);font-family:monospace;"><?= htmlspecialchars(substr($p['transaction_reference'], 0, 30)) ?></div>
                                    <?php endif; ?>
                                </div>
                            </div>
                        </td>
                        <td>
                            <span style="font-size:0.78rem;font-weight:600;color:<?= $color ?>;background:<?= $color ?>18;padding:3px 10px;border-radius:20px;text-transform:capitalize;">
                                <?= htmlspecialchars($p['reference_type']) ?>
                            </span>
                        </td>
                        <td style="text-align:right;font-weight:700;color:var(--neutral-800);"><?= formatCurrency($p['amount']) ?></td>
                        <td style="font-size:0.85rem;color:var(--neutral-600);text-transform:capitalize;"><?= htmlspecialchars($p['payment_method'] ?? '—') ?></td>
                        <td><?= getStatusBadge($p['status']) ?></td>
                        <td style="font-size:0.82rem;color:var(--neutral-500);">
                            <?= !empty($p['paid_at']) ? formatDateTime($p['paid_at']) : formatDateTime($p['created_at']) ?>
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
