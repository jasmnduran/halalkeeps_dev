<?php
/**
 * PayMongo Webhook Receiver
 * 
 * Handles incoming webhook events from PayMongo:
 *   - checkout_session.payment.paid
 *   - payment.paid
 *   - payment.failed
 * 
 * Automatically updates order status and payment_status in the database.
 * 
 * Security: Verify webhook signature using PayMongo webhook secret.
 */
require_once __DIR__ . '/../config/app.php';
require_once __DIR__ . '/../includes/IngredientInventoryService.php';

header('Content-Type: application/json; charset=utf-8');

// ── Log all incoming webhooks for debugging ────────────────────────────────────
$raw_input = file_get_contents('php://input');
$webhook_event = json_decode($raw_input, true);

if (!$webhook_event || !isset($webhook_event['data'])) {
    http_response_code(400);
    echo json_encode(['error' => 'Invalid webhook payload']);
    exit;
}

$event_type = $webhook_event['data']['attributes']['type'] ?? 'unknown';
$event_id   = $webhook_event['data']['id'] ?? null;

// ── Verify webhook signature (IMPORTANT: Implement in production) ──────────────
$paymongo_webhook_secret = getenv('PAYMONGO_WEBHOOK_SECRET') ?: PAYMONGO_WEBHOOK_SECRET ?? '';

// Signature verification (optional but recommended)
// $signature = $_SERVER['HTTP_PAYMONGO_SIGNATURE'] ?? '';
// if (!empty($paymongo_webhook_secret)) {
//     $computed_signature = hash_hmac('sha256', $raw_input, $paymongo_webhook_secret);
//     if (!hash_equals($computed_signature, $signature)) {
//         http_response_code(401);
//         echo json_encode(['error' => 'Invalid signature']);
//         exit;
//     }
// }

// ── Extract payment data ───────────────────────────────────────────────────────
$attributes = $webhook_event['data']['attributes'] ?? [];
$event_data = $attributes['data'] ?? [];

// Try to find payment intent ID from various possible locations
$payment_intent_id = null;
$order_number = null;
$amount_paid = 0;
$payment_status_raw = '';

// Handle checkout_session.payment.paid event
if ($event_type === 'checkout_session.payment.paid') {
    $checkout_data = $event_data;
    $payment_intent_id = $checkout_data['attributes']['payment_intent']['id'] ?? null;
    $payment_status_raw = $checkout_data['attributes']['payment_intent']['attributes']['status'] ?? '';
    $amount_paid = ($checkout_data['attributes']['payment_intent']['attributes']['amount'] ?? 0) / 100;
    
    // Extract order_number from metadata
    $metadata = $checkout_data['attributes']['payment_intent']['attributes']['metadata'] ?? [];
    $order_number = $metadata['order_number'] ?? null;
}
// Handle direct payment.paid event
elseif ($event_type === 'payment.paid' || $event_type === 'payment_intent.succeeded') {
    $payment_data = $event_data;
    $payment_intent_id = $payment_data['id'] ?? null;
    $payment_status_raw = $payment_data['attributes']['status'] ?? '';
    $amount_paid = ($payment_data['attributes']['amount'] ?? 0) / 100;
    
    $metadata = $payment_data['attributes']['metadata'] ?? [];
    $order_number = $metadata['order_number'] ?? null;
}
// Handle payment failure
elseif ($event_type === 'payment.failed' || $event_type === 'payment_intent.payment_failed') {
    $payment_data = $event_data;
    $payment_intent_id = $payment_data['id'] ?? null;
    $payment_status_raw = 'failed';
    
    $metadata = $payment_data['attributes']['metadata'] ?? [];
    $order_number = $metadata['order_number'] ?? null;
}

// ── Log webhook event ──────────────────────────────────────────────────────────
$log_stmt = $conn->prepare("
    INSERT INTO paymongo_webhook_logs
        (event_type, event_id, payment_intent_id, status, raw_payload, received_at)
    VALUES (?, ?, ?, ?, ?, NOW())
");

$log_stmt->bind_param("sssss",
    $event_type,
    $event_id,
    $payment_intent_id,
    $payment_status_raw,
    $raw_input
);

$log_stmt->execute();
$webhook_log_id = $conn->insert_id;
$log_stmt->close();

// ── Find and update order ──────────────────────────────────────────────────────
if ($payment_intent_id) {
    // Find order by payment_intent_id
    $order_stmt = $conn->prepare("
        SELECT id, order_number, customer_id, restaurant_id, total_amount, payment_status, payment_verified_at
        FROM orders
        WHERE paymongo_payment_intent_id = ?
        LIMIT 1
    ");
    $order_stmt->bind_param("s", $payment_intent_id);
    $order_stmt->execute();
    $order = $order_stmt->get_result()->fetch_assoc();
    $order_stmt->close();
    
    if ($order) {
        $order_id = (int)$order['id'];
        $restaurant_id = (int)$order['restaurant_id'];
        
        // ── Idempotency check: prevent duplicate processing ───────────────────
        if ($order['payment_verified_at'] !== null) {
            // Payment already processed - return success without re-processing
            http_response_code(200);
            echo json_encode([
                'success' => true,
                'message' => 'Payment already processed',
                'order_id' => $order_id,
                'idempotent' => true
            ]);
            exit;
        }
        
        // Update order based on payment status
        if ($payment_status_raw === 'succeeded' || $event_type === 'checkout_session.payment.paid' || $event_type === 'payment.paid') {
            // ── Payment successful - Atomic update with idempotency ──────────
            $update_stmt = $conn->prepare("
                UPDATE orders
                SET payment_status = 'paid',
                    paymongo_payment_status = ?,
                    order_status = 'confirmed',
                    payment_verified_at = NOW(),
                    webhook_event_id = ?,
                    updated_at = NOW()
                WHERE id = ? AND payment_verified_at IS NULL
            ");
            
            $status_value = 'succeeded';
            $update_stmt->bind_param("ssi", $status_value, $event_id, $order_id);
            $update_stmt->execute();
            $affected = $update_stmt->affected_rows;
            $update_stmt->close();
            
            if ($affected === 0) {
                // Another webhook already processed this - idempotent exit
                http_response_code(200);
                echo json_encode([
                    'success' => true,
                    'message' => 'Payment already processed by concurrent webhook',
                    'order_id' => $order_id,
                    'idempotent' => true
                ]);
                exit;
            }
            
            // Update webhook log with order_id
            $conn->query("UPDATE paymongo_webhook_logs SET order_id = {$order_id}, processed = 1 WHERE id = {$webhook_log_id}");
            
            // ── Create cashier notification ───────────────────────────────────
            db_add_column($conn, 'cashier_notifications', 'id', 'INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY');
            
            // Notify all active cashiers for this restaurant
            $cashier_stmt = $conn->query("
                SELECT rs.user_id
                FROM restaurant_staff rs
                JOIN restaurant_staff_roles rsr ON rs.staff_role_id = rsr.id
                WHERE rs.restaurant_id = {$restaurant_id}
                  AND rsr.role_key = 'cashier'
                  AND rs.is_active = 1
            ");
            
            if ($cashier_stmt) {
                while ($cashier = $cashier_stmt->fetch_assoc()) {
                    $cashier_user_id = (int)$cashier['user_id'];
                    
                    // Insert cashier notification
                    $notif_ins = $conn->prepare("
                        INSERT INTO cashier_notifications
                            (restaurant_id, order_id, cashier_user_id, notification_type, is_read, created_at)
                        VALUES (?, ?, ?, 'new_paid_order', 0, NOW())
                    ");
                    $notif_ins->bind_param("iii", $restaurant_id, $order_id, $cashier_user_id);
                    $notif_ins->execute();
                    $notif_ins->close();
                    
                    // Also send system notification
                    createNotification(
                        $conn,
                        $cashier_user_id,
                        'New Paid Order: ' . $order['order_number'],
                        'Customer order verified and paid. Please process in POS.',
                        'action_required',
                        BASE_URL . 'dashboard/business_owner/pos_order_processing.php'
                    );
                }
                $cashier_stmt->free();
            }
            
            // Notify business owner
            $owner_stmt = $conn->prepare("
                SELECT business_owner_id FROM halal_restaurants WHERE id = ? LIMIT 1
            ");
            $owner_stmt->bind_param("i", $restaurant_id);
            $owner_stmt->execute();
            $owner_row = $owner_stmt->get_result()->fetch_assoc();
            $owner_stmt->close();
            
            if ($owner_row) {
                createNotification(
                    $conn,
                    (int)$owner_row['business_owner_id'],
                    'New Customer Order: ' . $order['order_number'],
                    'Payment confirmed. Order ready for processing.',
                    'success',
                    BASE_URL . 'dashboard/business_owner/manage_orders.php'
                );
            }
            
            // Log activity
            if (function_exists('logActivity')) {
                logActivity($conn, $order['customer_id'], 'Payment Received',
                    "Order #{$order['order_number']} - PayMongo payment confirmed (₱{$amount_paid})", 'payment');
            }
            
            // Send notification to customer
            createNotification(
                $conn,
                $order['customer_id'],
                'Payment Confirmed!',
                "Your payment for Order #{$order['order_number']} has been received. The restaurant is preparing your order.",
                'success',
                BASE_URL . 'dashboard/customer/orders.php'
            );
            
            // Record status change in audit trail
            db_add_column($conn, 'order_status_history', 'id', 'INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY');
            $history_stmt = $conn->prepare("
                INSERT INTO order_status_history
                    (order_id, old_status, new_status, changed_by_role, notes, changed_at)
                VALUES (?, 'pending', 'confirmed', 'system', 'Payment verified via PayMongo webhook', NOW())
            ");
            $history_stmt->bind_param("i", $order_id);
            $history_stmt->execute();
            $history_stmt->close();
            
            http_response_code(200);
            echo json_encode(['success' => true, 'message' => 'Order updated successfully', 'order_id' => $order_id]);
            
        } elseif ($payment_status_raw === 'failed') {
            // Payment failed
            $update_stmt = $conn->prepare("
                UPDATE orders
                SET payment_status = 'failed',
                    paymongo_payment_status = 'failed',
                    order_status = 'cancelled',
                    updated_at = NOW()
                WHERE id = ?
            ");
            
            $update_stmt->bind_param("i", $order_id);
            $update_stmt->execute();
            $update_stmt->close();
            if (IngredientInventoryService::isFeatureEnabled()) {
                try { (new IngredientInventoryService($conn))->releaseOrderReservations((int)$order_id); }
                catch (Throwable $e) { error_log('Could not release ingredient reservation for failed payment: ' . $e->getMessage()); }
            }
            
            $conn->query("UPDATE paymongo_webhook_logs SET order_id = {$order_id}, processed = 1 WHERE id = {$webhook_log_id}");
            
            // Notify customer of failure
            if (function_exists('createNotification')) {
                createNotification(
                    $conn,
                    $order['customer_id'],
                    'Payment Failed',
                    "Payment for Order #{$order['order_number']} could not be processed. Please try again.",
                    'error',
                    BASE_URL . 'dashboard/customer/orders.php'
                );
            }
            
            http_response_code(200);
            echo json_encode(['success' => true, 'message' => 'Payment failure recorded', 'order_id' => $order_id]);
            
        } else {
            // Other status - just log
            $conn->query("UPDATE paymongo_webhook_logs SET order_id = {$order_id}, error_message = 'Unhandled status: {$payment_status_raw}' WHERE id = {$webhook_log_id}");
            
            http_response_code(200);
            echo json_encode(['success' => true, 'message' => 'Event logged', 'status' => $payment_status_raw]);
        }
        
    } else {
        // Order not found
        $conn->query("UPDATE paymongo_webhook_logs SET error_message = 'Order not found for payment_intent_id' WHERE id = {$webhook_log_id}");
        
        http_response_code(404);
        echo json_encode(['error' => 'Order not found', 'payment_intent_id' => $payment_intent_id]);
    }
    
} else {
    // No payment_intent_id found in webhook
    $conn->query("UPDATE paymongo_webhook_logs SET error_message = 'No payment_intent_id in webhook payload' WHERE id = {$webhook_log_id}");
    
    http_response_code(400);
    echo json_encode(['error' => 'No payment_intent_id in payload']);
}
