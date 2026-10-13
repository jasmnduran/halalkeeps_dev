<?php
/**
 * PayMongo Checkout Return Handler — Customer Orders
 *
 * User lands here after completing (or cancelling) PayMongo checkout.
 * Querystring: ?order_id=123&status=success|cancel
 *
 * On ?status=success we immediately mark the order as paid/confirmed so the
 * payment status is updated right away without waiting for a webhook.
 */
require_once __DIR__ . '/../../../config/app.php';
requireLogin();
requireRole([ROLE_CUSTOMER]);

$order_id = (int) ($_GET['order_id'] ?? 0);
$status   = sanitize($_GET['status'] ?? '');

if ($order_id <= 0) {
    header('Location: ' . BASE_URL . 'dashboard/customer/orders.php');
    exit;
}

// Verify order belongs to current user
$user_id = (int) $_SESSION['user_id'];
$order_stmt = $conn->prepare(
    "SELECT id, order_number, order_status, payment_status, payment_method, restaurant_id, customer_id
     FROM orders
     WHERE id = ? AND customer_id = ?
     LIMIT 1"
);
$order_stmt->bind_param("ii", $order_id, $user_id);
$order_stmt->execute();
$order = $order_stmt->get_result()->fetch_assoc();

// ── If customer returned from PayMongo with success, mark as paid immediately ──
if ($order && $status === 'success' && $order['payment_status'] === 'pending') {
    $upd = $conn->prepare("
        UPDATE orders
        SET payment_status      = 'paid',
            order_status        = CASE WHEN order_status = 'pending' THEN 'confirmed' ELSE order_status END,
            payment_verified_at = NOW(),
            updated_at          = NOW()
        WHERE id = ?
          AND customer_id = ?
          AND payment_status = 'pending'
          AND payment_verified_at IS NULL
    ");
    $upd->bind_param("ii", $order_id, $user_id);
    $upd->execute();

    if ($upd->affected_rows > 0) {
        // Refresh the order row so the page shows the updated status
        $order['payment_status'] = 'paid';
        $order['order_status']   = ($order['order_status'] === 'pending') ? 'confirmed' : $order['order_status'];

        // Notify business owner
        $owner_stmt = $conn->prepare("
            SELECT business_owner_id FROM halal_restaurants WHERE id = ? LIMIT 1
        ");
        $owner_stmt->bind_param("i", (int)$order['restaurant_id']);
        $owner_stmt->execute();
        $owner_row = $owner_stmt->get_result()->fetch_assoc();
        $owner_stmt->close();

        if ($owner_row && function_exists('createNotification')) {
            createNotification(
                $conn,
                (int)$owner_row['business_owner_id'],
                'New Customer Order: ' . $order['order_number'],
                'Payment confirmed. Order ready for processing.',
                'success',
                BASE_URL . 'dashboard/business_owner/manage_orders.php'
            );
        }

        // Notify cashiers
        $cashier_stmt = $conn->query("
            SELECT rs.user_id
            FROM restaurant_staff rs
            JOIN restaurant_staff_roles rsr ON rs.staff_role_id = rsr.id
            WHERE rs.restaurant_id = " . (int)$order['restaurant_id'] . "
              AND rsr.role_key = 'cashier'
              AND rs.is_active = 1
        ");
        if ($cashier_stmt) {
            while ($cashier = $cashier_stmt->fetch_assoc()) {
                if (function_exists('createNotification')) {
                    createNotification(
                        $conn,
                        (int)$cashier['user_id'],
                        'New Paid Order: ' . $order['order_number'],
                        'Customer payment verified. Please process in POS.',
                        'action_required',
                        BASE_URL . 'dashboard/business_owner/pos_order_processing.php'
                    );
                }
            }
        }

        // Log activity
        if (function_exists('logActivity')) {
            logActivity($conn, $user_id, 'Payment Received',
                "Order #{$order['order_number']} — payment confirmed on return from PayMongo checkout.",
                'payment');
        }
    }
    $upd->close();
}

if (!$order) {
    $_SESSION['flash_message'] = 'Order not found or access denied.';
    $_SESSION['flash_type']    = 'error';
    header('Location: ' . BASE_URL . 'dashboard/customer/orders.php');
    exit;
}

$page_title = 'Order Payment ' . ucfirst($status);
$page_heading = 'Order Payment Status';
$breadcrumbs = [
    ['label' => 'Dashboard', 'url' => BASE_URL . 'dashboard/customer/'],
    ['label' => 'My Orders',  'url' => BASE_URL . 'dashboard/customer/orders.php'],
    ['label' => 'Payment Status'],
];

require_once __DIR__ . '/../../../includes/header.php';
?>

<div class="container" style="max-width:700px;margin:60px auto;padding:20px;">
    <div class="card" style="background:#fff;border-radius:16px;box-shadow:0 4px 20px rgba(0,0,0,0.08);overflow:hidden;">
        
        <?php if ($status === 'success'): ?>
            <!-- Success state -->
            <div style="background:linear-gradient(135deg,#10b981,#059669);padding:40px 20px;text-align:center;">
                <div style="width:80px;height:80px;background:rgba(255,255,255,0.2);border-radius:50%;margin:0 auto 16px;display:flex;align-items:center;justify-content:center;">
                    <i class="fas fa-check-circle" style="font-size:3rem;color:#fff;"></i>
                </div>
                <h1 style="margin:0;font-size:1.8rem;font-weight:800;color:#fff;">Payment Received!</h1>
                <p style="margin:10px 0 0;font-size:1rem;color:rgba(255,255,255,0.9);">Your order is being processed</p>
            </div>
            
            <div style="padding:32px 28px;">
                <div style="text-align:center;margin-bottom:24px;">
                    <div style="font-size:.875rem;color:#6b7280;margin-bottom:6px;">Order Number</div>
                    <div style="font-size:1.5rem;font-weight:800;color:#111827;font-family:monospace;letter-spacing:1px;"><?= htmlspecialchars($order['order_number']) ?></div>
                </div>
                
                <div style="background:#f0fdf4;border:1.5px solid #86efac;border-radius:12px;padding:18px;margin-bottom:20px;">
                    <div style="display:flex;align-items:flex-start;gap:12px;">
                        <i class="fas fa-info-circle" style="color:#16a34a;font-size:1.2rem;margin-top:2px;"></i>
                        <div>
                            <div style="font-weight:700;color:#166534;margin-bottom:4px;">What happens next?</div>
                            <ul style="margin:0;padding-left:18px;color:#166534;font-size:.88rem;line-height:1.6;">
                                <li>We've notified the restaurant about your order</li>
                                <li>They will confirm and begin preparation shortly</li>
                                <li>You'll receive notifications as your order progresses</li>
                                <li>Check order status anytime in "My Orders"</li>
                            </ul>
                        </div>
                    </div>
                </div>
                

                
                <div style="display:flex;gap:12px;margin-top:24px;">
                    <a href="<?= BASE_URL ?>dashboard/customer/orders.php" class="btn btn-primary" style="flex:1;padding:14px;font-size:1rem;font-weight:700;text-align:center;display:flex;align-items:center;justify-content:center;gap:8px;text-decoration:none;">
                        <i class="fas fa-list"></i>
                        View My Orders
                    </a>
                    <a href="<?= BASE_URL ?>dashboard/customer/" class="btn btn-secondary" style="flex:1;padding:14px;font-size:1rem;font-weight:700;text-align:center;display:flex;align-items:center;justify-content:center;gap:8px;text-decoration:none;">
                        <i class="fas fa-home"></i>
                        Back to Dashboard
                    </a>
                </div>
            </div>
            
        <?php elseif ($status === 'cancel'): ?>
            <!-- Cancelled state -->
            <div style="background:linear-gradient(135deg,#ef4444,#dc2626);padding:40px 20px;text-align:center;">
                <div style="width:80px;height:80px;background:rgba(255,255,255,0.2);border-radius:50%;margin:0 auto 16px;display:flex;align-items:center;justify-content:center;">
                    <i class="fas fa-times-circle" style="font-size:3rem;color:#fff;"></i>
                </div>
                <h1 style="margin:0;font-size:1.8rem;font-weight:800;color:#fff;">Payment Cancelled</h1>
                <p style="margin:10px 0 0;font-size:1rem;color:rgba(255,255,255,0.9);">You cancelled the payment process</p>
            </div>
            
            <div style="padding:32px 28px;">
                <div style="text-align:center;margin-bottom:24px;">
                    <div style="font-size:.875rem;color:#6b7280;margin-bottom:6px;">Order Number</div>
                    <div style="font-size:1.5rem;font-weight:800;color:#111827;font-family:monospace;letter-spacing:1px;"><?= htmlspecialchars($order['order_number']) ?></div>
                </div>
                
                <div style="background:#fef2f2;border:1.5px solid#fecaca;border-radius:12px;padding:18px;margin-bottom:20px;">
                    <div style="display:flex;align-items:flex-start;gap:12px;">
                        <i class="fas fa-info-circle" style="color:#dc2626;font-size:1.2rem;margin-top:2px;"></i>
                        <div style="color:#991b1b;font-size:.88rem;line-height:1.6;">
                            <strong style="display:block;margin-bottom:6px;">Your order is still pending payment.</strong>
                            The restaurant will hold your order for a limited time. You can:
                            <ul style="margin:8px 0 0;padding-left:18px;">
                                <li>Try payment again from "My Orders"</li>
                                <li>Contact the restaurant to arrange an alternative</li>
                                <li>Cancel the order if you no longer need it</li>
                            </ul>
                        </div>
                    </div>
                </div>
                
                <div style="display:flex;gap:12px;margin-top:24px;">
                    <a href="<?= BASE_URL ?>dashboard/customer/orders.php" class="btn btn-primary" style="flex:1;padding:14px;font-size:1rem;font-weight:700;text-align:center;display:flex;align-items:center;justify-content:center;gap:8px;text-decoration:none;">
                        <i class="fas fa-redo"></i>
                        Retry Payment
                    </a>
                    <a href="<?= BASE_URL ?>dashboard/customer/restaurants.php" class="btn btn-secondary" style="flex:1;padding:14px;font-size:1rem;font-weight:700;text-align:center;display:flex;align-items:center;justify-content:center;gap:8px;text-decoration:none;">
                        <i class="fas fa-utensils"></i>
                        Browse Restaurants
                    </a>
                </div>
            </div>
            
        <?php else: ?>
            <!-- Unknown status fallback -->
            <div style="padding:40px 20px;text-align:center;">
                <i class="fas fa-question-circle" style="font-size:3rem;color:#9ca3af;margin-bottom:16px;"></i>
                <h1 style="margin:0 0 10px;font-size:1.5rem;font-weight:700;color:#374151;">Unknown Payment Status</h1>
                <p style="color:#6b7280;margin-bottom:24px;">Unable to determine the outcome of your payment.</p>
                <a href="<?= BASE_URL ?>dashboard/customer/orders.php" class="btn btn-primary">
                    <i class="fas fa-list"></i> View My Orders
                </a>
            </div>
        <?php endif; ?>
        
    </div>
</div>

<?php require_once __DIR__ . '/../../../includes/footer.php'; ?>
