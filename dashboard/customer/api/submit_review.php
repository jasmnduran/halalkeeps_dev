<?php
/**
 * AJAX endpoint: submit an order review with optional photo uploads.
 *
 * POST fields:
 *   order_id       int      (required)
 *   restaurant_id  int      (required)
 *   rating         int 1-5  (required)
 *   food_rating    int 1-5
 *   service_rating int 1-5
 *   comment        string
 *
 * FILES:
 *   photos[]       image files (jpg, jpeg, png, webp, gif) — max 5MB each
 *
 * Returns JSON: { success, message, review_id? }
 */
require_once __DIR__ . '/../../../config/app.php';

header('Content-Type: application/json; charset=utf-8');

// ── Auth guards ────────────────────────────────────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['success' => false, 'message' => 'Method not allowed.']);
    exit;
}
if (!isLoggedIn()) {
    http_response_code(401);
    echo json_encode(['success' => false, 'message' => 'Not authenticated.']);
    exit;
}
if (($_SESSION['role_id'] ?? 0) !== ROLE_CUSTOMER) {
    http_response_code(403);
    echo json_encode(['success' => false, 'message' => 'Access denied.']);
    exit;
}

$user_id = (int) $_SESSION['user_id'];

// ── Input validation ───────────────────────────────────────────────────────────
$order_id      = (int) ($_POST['order_id']      ?? 0);
$restaurant_id = (int) ($_POST['restaurant_id'] ?? 0);
$rating        = min(5, max(1, (int) ($_POST['rating']         ?? 5)));
$food_rating   = min(5, max(1, (int) ($_POST['food_rating']    ?? $rating)));
$svc_rating    = min(5, max(1, (int) ($_POST['service_rating'] ?? $rating)));
$comment       = trim(sanitize($_POST['comment'] ?? ''));

if ($order_id <= 0 || $restaurant_id <= 0) {
    echo json_encode(['success' => false, 'message' => 'Invalid order or restaurant.']);
    exit;
}

// ── Verify the order belongs to this customer and is delivered/completed ───────
$ord_q = $conn->prepare(
    "SELECT id FROM orders
     WHERE id = ? AND customer_id = ? AND restaurant_id = ?
       AND order_status IN ('delivered','completed')
     LIMIT 1"
);
$ord_q->bind_param("iii", $order_id, $user_id, $restaurant_id);
$ord_q->execute();
if ($ord_q->get_result()->num_rows === 0) {
    echo json_encode(['success' => false, 'message' => 'Order not eligible for review.']);
    exit;
}

// ── Duplicate check — one review per order ─────────────────────────────────────
$dup = $conn->prepare(
    "SELECT id FROM reviews WHERE customer_id = ? AND order_id = ? LIMIT 1"
);
$dup->bind_param("ii", $user_id, $order_id);
$dup->execute();
if ($dup->get_result()->num_rows > 0) {
    echo json_encode(['success' => false, 'message' => 'You have already reviewed this order.']);
    exit;
}

// ── Insert review ──────────────────────────────────────────────────────────────
$ins = $conn->prepare(
    "INSERT INTO reviews
        (customer_id, restaurant_id, order_id, rating, food_rating, service_rating, comment)
     VALUES (?, ?, ?, ?, ?, ?, ?)"
);
if (!$ins) {
    http_response_code(500);
    echo json_encode(['success' => false, 'message' => 'DB error: ' . $conn->error]);
    exit;
}
$ins->bind_param("iiiiiss",
    $user_id, $restaurant_id, $order_id,
    $rating, $food_rating, $svc_rating, $comment
);
if (!$ins->execute()) {
    http_response_code(500);
    echo json_encode(['success' => false, 'message' => 'Failed to save review: ' . $ins->error]);
    exit;
}
$review_id = $conn->insert_id;

// ── Update restaurant aggregate rating ────────────────────────────────────────
$conn->query(
    "UPDATE halal_restaurants
     SET avg_rating    = (SELECT AVG(rating) FROM reviews WHERE restaurant_id = $restaurant_id),
         total_reviews = (SELECT COUNT(*)    FROM reviews WHERE restaurant_id = $restaurant_id)
     WHERE id = $restaurant_id"
);

// ── Handle photo uploads ───────────────────────────────────────────────────────
$allowed_ext  = ['jpg', 'jpeg', 'png', 'webp', 'gif'];
$max_size     = 5 * 1024 * 1024; // 5 MB
$upload_dir   = UPLOAD_DIR . 'review_photos/';
$photos_saved = 0;

if (!is_dir($upload_dir)) {
    mkdir($upload_dir, 0755, true);
}

if (!empty($_FILES['photos']['name'][0])) {
    $file_count = count($_FILES['photos']['name']);
    $file_count = min($file_count, 10); // cap at 10 photos

    for ($i = 0; $i < $file_count; $i++) {
        if ($_FILES['photos']['error'][$i] !== UPLOAD_ERR_OK) continue;
        if ($_FILES['photos']['size'][$i]  >  $max_size)      continue;

        $ext = strtolower(pathinfo($_FILES['photos']['name'][$i], PATHINFO_EXTENSION));
        if (!in_array($ext, $allowed_ext, true)) continue;

        // Double-check MIME via finfo
        $finfo = finfo_open(FILEINFO_MIME_TYPE);
        $mime  = finfo_file($finfo, $_FILES['photos']['tmp_name'][$i]);
        finfo_close($finfo);
        $allowed_mime = ['image/jpeg','image/png','image/webp','image/gif'];
        if (!in_array($mime, $allowed_mime, true)) continue;

        $filename = 'rv_' . $review_id . '_' . uniqid() . '.' . $ext;
        $target   = $upload_dir . $filename;

        if (move_uploaded_file($_FILES['photos']['tmp_name'][$i], $target)) {
            $rel_path = 'review_photos/' . $filename;
            $photo_ins = $conn->prepare(
                "INSERT INTO review_photos (review_id, image_path) VALUES (?, ?)"
            );
            $photo_ins->bind_param("is", $review_id, $rel_path);
            $photo_ins->execute();
            $photos_saved++;
        }
    }
}

echo json_encode([
    'success'   => true,
    'message'   => 'Review submitted! Thank you for your feedback.',
    'review_id' => $review_id,
    'photos'    => $photos_saved,
]);
