<?php
require_once __DIR__ . '/../../config/app.php';
requireLogin();
requireRole([ROLE_CUSTOMER]);

$page_title = 'My Reviews';
$page_heading = 'My Reviews';
$is_dashboard = true;
$breadcrumbs = [['label' => 'Dashboard', 'url' => BASE_URL . 'dashboard/customer/'], ['label' => 'Reviews']];
$user_id = $_SESSION['user_id'];

$stmt = $conn->prepare("SELECT rv.*, hr.restaurant_name FROM reviews rv JOIN halal_restaurants hr ON rv.restaurant_id = hr.id WHERE rv.customer_id = ? ORDER BY rv.created_at DESC");
$stmt->bind_param("i", $user_id);
$stmt->execute();
$reviews = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);

// Batch-fetch photos for all reviews
$review_photos_map = [];
if (!empty($reviews)) {
    $rv_ids = implode(',', array_map(fn($r) => (int)$r['id'], $reviews));
    $photos_result = $conn->query(
        "SELECT review_id, image_path FROM review_photos WHERE review_id IN ($rv_ids) ORDER BY id ASC"
    );
    if ($photos_result) {
        while ($ph = $photos_result->fetch_assoc()) {
            $review_photos_map[(int)$ph['review_id']][] = $ph['image_path'];
        }
    }
}

require_once __DIR__ . '/../../includes/header.php';
?>

<div class="card">
    <div class="card-header" style="border-bottom:1px solid var(--neutral-100);">
        <div style="display:flex;align-items:center;gap:10px;">
            <div style="width:40px;height:40px;border-radius:10px;background:var(--accent-50);
                        display:flex;align-items:center;justify-content:center;">
                <i class="fas fa-star" style="color:var(--accent-500);"></i>
            </div>
            <div>
                <h3 style="margin:0;font-family:var(--font-display);font-size:1rem;font-weight:700;color:var(--neutral-800);">My Reviews</h3>
                <div style="font-size:.78rem;color:var(--neutral-400);margin-top:1px;"><?= count($reviews) ?> review<?= count($reviews) !== 1 ? 's' : '' ?></div>
            </div>
        </div>
    </div>
    <div class="card-body" style="padding:0;">
        <?php if (empty($reviews)): ?>
            <div class="empty-state" style="padding:60px 32px;">
                <div class="empty-icon"><i class="fas fa-star"></i></div>
                <h3>No Reviews Yet</h3>
                <p>Order from a restaurant and share your experience!</p>
                <a href="<?= BASE_URL ?>dashboard/customer/restaurants.php" class="btn btn-primary">
                    <i class="fas fa-utensils"></i> Browse Restaurants
                </a>
            </div>
        <?php else: ?>
            <?php foreach ($reviews as $rv):
                $rv_photos = $review_photos_map[(int)$rv['id']] ?? [];
            ?>
            <div style="padding:20px 24px;border-bottom:1px solid var(--neutral-100);transition:background .15s;"
                 onmouseover="this.style.background='var(--neutral-50)'" onmouseout="this.style.background=''">
                <div style="display:flex;align-items:flex-start;gap:14px;">
                    <!-- Avatar -->
                    <div style="width:44px;height:44px;border-radius:12px;flex-shrink:0;
                                background:var(--primary-100);color:var(--primary-700);
                                display:flex;align-items:center;justify-content:center;
                                font-weight:800;font-size:1rem;border:2px solid var(--primary-200);">
                        <?= strtoupper(substr($_SESSION['full_name'] ?? 'U', 0, 1)) ?>
                    </div>
                    <div style="flex:1;min-width:0;">
                        <!-- Restaurant name + date -->
                        <div style="display:flex;align-items:flex-start;justify-content:space-between;gap:12px;flex-wrap:wrap;margin-bottom:6px;">
                            <div>
                                <strong style="font-size:1rem;color:var(--neutral-800);"><?= htmlspecialchars($rv['restaurant_name']) ?></strong>
                                <div style="font-size:.75rem;color:var(--neutral-400);margin-top:1px;"><?= formatDateTime($rv['created_at']) ?></div>
                            </div>
                            <div style="text-align:right;flex-shrink:0;">
                                <!-- Star row -->
                                <div style="display:flex;gap:2px;margin-bottom:4px;justify-content:flex-end;">
                                    <?php for ($s = 1; $s <= 5; $s++): ?>
                                    <i class="fas fa-star" style="font-size:.85rem;color:<?= $s <= $rv['rating'] ? 'var(--accent-400)' : 'var(--neutral-200)' ?>;"></i>
                                    <?php endfor; ?>
                                </div>
                                <div style="font-size:.72rem;color:var(--neutral-400);">
                                    Food <?= $rv['food_rating'] ?>/5 &bull; Service <?= $rv['service_rating'] ?>/5
                                </div>
                            </div>
                        </div>
                        <?php if ($rv['comment']): ?>
                        <p style="color:var(--neutral-600);line-height:1.6;margin:0 0 <?= !empty($rv_photos) ? '12px' : '0' ?>;">
                            <?= nl2br(htmlspecialchars($rv['comment'])) ?>
                        </p>
                        <?php endif; ?>
                        <?php if (!empty($rv_photos)): ?>
                        <div style="display:flex;flex-wrap:wrap;gap:8px;margin-bottom:<?= $rv['response'] ? '12px' : '0' ?>;">
                            <?php foreach ($rv_photos as $photo_path): ?>
                            <a href="<?= BASE_URL ?>uploads/<?= htmlspecialchars($photo_path) ?>" target="_blank" rel="noopener">
                                <img src="<?= BASE_URL ?>uploads/<?= htmlspecialchars($photo_path) ?>"
                                     alt="Review photo"
                                     style="width:80px;height:80px;object-fit:cover;border-radius:10px;
                                            border:2px solid var(--neutral-100);transition:opacity .15s;"
                                     onmouseover="this.style.opacity='.8'" onmouseout="this.style.opacity='1'">
                            </a>
                            <?php endforeach; ?>
                        </div>
                        <?php endif; ?>
                        <?php if ($rv['response']): ?>
                        <div style="background:var(--primary-50);border:1px solid var(--primary-100);padding:12px 16px;border-radius:10px;margin-top:10px;font-size:.88rem;">
                            <strong style="color:var(--primary-700);display:flex;align-items:center;gap:6px;margin-bottom:4px;">
                                <i class="fas fa-store" style="font-size:.75rem;"></i> Owner Reply
                            </strong>
                            <p style="margin:0;color:var(--neutral-700);line-height:1.6;"><?= nl2br(htmlspecialchars($rv['response'])) ?></p>
                        </div>
                        <?php endif; ?>
                    </div>
                </div>
            </div>
            <?php endforeach; ?>
        <?php endif; ?>
    </div>
</div>

<?php require_once __DIR__ . '/../../includes/footer.php'; ?>
