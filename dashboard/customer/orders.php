<?php
require_once __DIR__ . '/../../config/app.php';
requireLogin();
requireRole([ROLE_CUSTOMER]);

$page_title   = 'My Orders';
$page_heading = 'My Orders';
$is_dashboard = true;
$breadcrumbs  = [
    ['label' => 'Dashboard', 'url' => BASE_URL . 'dashboard/customer/'],
    ['label' => 'Orders'],
];
$user_id = $_SESSION['user_id'];

$pay_status   = $_GET['payment'] ?? '';
$pay_order_no = htmlspecialchars($_GET['order'] ?? '');

// ── Fetch orders ───────────────────────────────────────────────────────────────
$stmt = $conn->prepare(
    "SELECT o.*, hr.restaurant_name
     FROM orders o
     JOIN halal_restaurants hr ON o.restaurant_id = hr.id
     WHERE o.customer_id = ?
     ORDER BY o.created_at DESC"
);
$stmt->bind_param("i", $user_id);
$stmt->execute();
$orders = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);

// ── Collect reviewed order IDs for this customer ───────────────────────────────
$reviewed_order_ids = [];
if (!empty($orders)) {
    $order_ids = array_column($orders, 'id');
    $placeholders = implode(',', array_fill(0, count($order_ids), '?'));
    $types = str_repeat('i', count($order_ids));
    $rev_stmt = $conn->prepare(
        "SELECT order_id FROM reviews
         WHERE customer_id = ? AND order_id IN ($placeholders)"
    );
    $params = array_merge([$user_id], $order_ids);
    $rev_stmt->bind_param($types . 'i' /* prepend user_id type */, ...$params);
    // bind_param: first param is user_id (i), then order_ids (all i)
    // Re-do correctly:
    $rev_stmt->close();
    $rev_stmt2 = $conn->prepare(
        "SELECT order_id FROM reviews WHERE customer_id = ? AND order_id IN ($placeholders)"
    );
    $all_params = array_merge([$user_id], $order_ids);
    $all_types  = 'i' . str_repeat('i', count($order_ids));
    $refs = [];
    $refs[] = &$all_types;
    foreach ($all_params as &$val) $refs[] = &$val;
    call_user_func_array([$rev_stmt2, 'bind_param'], $refs);
    $rev_stmt2->execute();
    $rev_result = $rev_stmt2->get_result()->fetch_all(MYSQLI_ASSOC);
    $reviewed_order_ids = array_column($rev_result, 'order_id');
}

require_once __DIR__ . '/../../includes/header.php';
?>

<?php if ($pay_status === 'success'): ?>
<div class="alert alert-success">
    <i class="fas fa-check-circle"></i>
    <span>Payment confirmed<?= $pay_order_no ? ' for order <strong>' . $pay_order_no . '</strong>' : '' ?>. Your order is being processed!</span>
    <button class="close-alert"><i class="fas fa-times"></i></button>
</div>
<?php elseif ($pay_status === 'pending'): ?>
<div class="alert alert-warning">
    <i class="fas fa-clock"></i>
    <span>Payment for order <strong><?= $pay_order_no ?></strong> is pending verification.</span>
    <button class="close-alert"><i class="fas fa-times"></i></button>
</div>
<?php endif; ?>

<div class="card">
    <div class="card-header" style="border-bottom:1px solid var(--neutral-100);">
        <div style="display:flex;align-items:center;gap:10px;">
            <div style="width:40px;height:40px;border-radius:10px;background:var(--primary-50);
                        display:flex;align-items:center;justify-content:center;">
                <i class="fas fa-shopping-bag" style="color:var(--primary-600);"></i>
            </div>
            <div>
                <h3 style="margin:0;font-family:var(--font-display);font-size:1rem;font-weight:700;color:var(--neutral-800);">Order History</h3>
                <div style="font-size:.78rem;color:var(--neutral-400);margin-top:1px;"><?= count($orders) ?> order<?= count($orders) !== 1 ? 's' : '' ?></div>
            </div>
        </div>
        <a href="<?= BASE_URL ?>dashboard/customer/restaurants.php" class="btn btn-sm btn-primary">
            <i class="fas fa-plus"></i> New Order
        </a>
    </div>
    <div class="card-body" style="padding:0;">
        <?php if (empty($orders)): ?>
            <div class="empty-state" style="padding:60px 32px;">
                <div class="empty-icon"><i class="fas fa-shopping-bag"></i></div>
                <h3>No Orders Yet</h3>
                <p>Browse restaurants to place your first order!</p>
                <a href="<?= BASE_URL ?>dashboard/customer/restaurants.php" class="btn btn-primary">
                    <i class="fas fa-utensils"></i> Browse Restaurants
                </a>
            </div>
        <?php else: ?>
            <div class="table-responsive">
                <table class="table">
                    <thead style="background:var(--neutral-50);">
                        <tr>
                            <th>Order #</th>
                            <th>Restaurant</th>
                            <th>Items</th>
                            <th>Total</th>
                            <th>Payment</th>
                            <th>Status</th>
                            <th>Date</th>
                            <th style="text-align:center;min-width:130px;">Action</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($orders as $o):
                            $oid      = (int) $o['id'];
                            $status   = $o['order_status'];
                            $is_ofd   = $status === 'out_for_delivery';
                            $is_done  = in_array($status, ['delivered', 'completed'], true);
                            $reviewed = in_array($oid, array_map('intval', $reviewed_order_ids), true);

                            $items_decoded = json_decode($o['items_json'] ?? '[]', true) ?: [];
                            $item_summary  = '';
                            if (!empty($items_decoded)) {
                                $parts = array_map(
                                    fn($it) => htmlspecialchars($it['name'] ?? '') . ' ×' . (int)($it['qty'] ?? 1),
                                    array_slice($items_decoded, 0, 2)
                                );
                                $item_summary = implode(', ', $parts);
                                if (count($items_decoded) > 2) $item_summary .= ' +' . (count($items_decoded) - 2) . ' more';
                            }
                        ?>
                        <tr id="order-row-<?= $oid ?>">
                            <td><strong><?= htmlspecialchars($o['order_number']) ?></strong></td>
                            <td><?= htmlspecialchars($o['restaurant_name']) ?></td>
                            <td style="font-size:0.82rem;color:var(--neutral-500);max-width:180px;"><?= $item_summary ?: '—' ?></td>
                            <td><?= formatCurrency($o['total_amount']) ?></td>
                            <td id="pay-cell-<?= $oid ?>"
                                data-payment-method="<?= htmlspecialchars($o['payment_method'], ENT_QUOTES) ?>"
                                data-payment-status="<?= htmlspecialchars($o['payment_status'], ENT_QUOTES) ?>"
                                data-order-number="<?= htmlspecialchars($o['order_number'], ENT_QUOTES) ?>"><?= getStatusBadge($o['payment_status']) ?></td>
                            <td id="status-cell-<?= $oid ?>"><?= getStatusBadge($status) ?></td>
                            <td style="font-size:0.85rem;color:var(--neutral-500);"><?= formatDateTime($o['created_at']) ?></td>
                            <td style="text-align:center;" id="action-cell-<?= $oid ?>">
                                <?php if ($is_ofd): ?>
                                    <button type="button" class="btn btn-sm confirm-received-btn"
                                            style="background:#16a34a;color:#fff;border:none;padding:6px 12px;border-radius:8px;font-weight:600;font-size:0.8rem;cursor:pointer;white-space:nowrap;"
                                            data-order-id="<?= $oid ?>"
                                            data-restaurant-id="<?= (int)$o['restaurant_id'] ?>"
                                            data-restaurant-name="<?= htmlspecialchars($o['restaurant_name'], ENT_QUOTES) ?>"
                                            data-order-number="<?= htmlspecialchars($o['order_number'], ENT_QUOTES) ?>">
                                        <i class="fas fa-box-open"></i> Order Received
                                    </button>
                                <?php elseif ($is_done && !$reviewed): ?>
                                    <button type="button" class="btn btn-sm leave-review-btn"
                                            style="background:#f59e0b;color:#fff;border:none;padding:6px 12px;border-radius:8px;font-weight:600;font-size:0.8rem;cursor:pointer;white-space:nowrap;"
                                            data-order-id="<?= $oid ?>"
                                            data-restaurant-id="<?= (int)$o['restaurant_id'] ?>"
                                            data-restaurant-name="<?= htmlspecialchars($o['restaurant_name'], ENT_QUOTES) ?>"
                                            data-order-number="<?= htmlspecialchars($o['order_number'], ENT_QUOTES) ?>">
                                        <i class="fas fa-star"></i> Leave a Review
                                    </button>
                                <?php elseif ($is_done && $reviewed): ?>
                                    <span style="font-size:0.78rem;color:#16a34a;font-weight:600;">
                                        <i class="fas fa-check-circle"></i> Reviewed
                                    </span>
                                <?php else: ?>
                                    <span style="font-size:0.78rem;color:var(--neutral-400);">—</span>
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

<!-- ── Camera Modal ──────────────────────────────────────────────────────────── -->
<div id="cameraModalOverlay"
     style="display:none;position:fixed;inset:0;background:rgba(0,0,0,.75);z-index:1100;
            align-items:center;justify-content:center;padding:16px;">
    <div style="background:#1a1a2e;border-radius:20px;width:100%;max-width:480px;
                overflow:hidden;box-shadow:0 24px 80px rgba(0,0,0,.5);">

        <!-- Camera header -->
        <div style="display:flex;align-items:center;justify-content:space-between;
                    padding:14px 20px;border-bottom:1px solid rgba(255,255,255,.1);">
            <span style="color:#fff;font-weight:700;font-size:0.95rem;">
                <i class="fas fa-camera" style="color:#34d399;margin-right:8px;"></i>Snap Proof via Camera
            </span>
            <button id="closeCameraBtn"
                    style="width:32px;height:32px;border:none;background:rgba(255,255,255,.12);
                           border-radius:8px;cursor:pointer;color:#fff;font-size:1rem;">
                <i class="fas fa-times"></i>
            </button>
        </div>

        <!-- Live viewfinder -->
        <div id="cameraViewfinder" style="position:relative;background:#000;line-height:0;">
            <video id="cameraVideo" autoplay playsinline muted
                   style="width:100%;max-height:340px;object-fit:cover;display:block;"></video>
            <!-- Snapshot preview (hidden until captured) -->
            <canvas id="cameraCanvas" style="display:none;width:100%;max-height:340px;
                                              object-fit:cover;"></canvas>
        </div>

        <!-- Controls -->
        <div style="padding:16px 20px;display:flex;gap:10px;justify-content:center;flex-wrap:wrap;">
            <!-- Live controls -->
            <div id="liveControls" style="display:flex;gap:10px;align-items:center;">
                <select id="cameraSelect"
                        style="font-size:0.8rem;padding:6px 10px;border-radius:8px;border:1px solid rgba(255,255,255,.2);
                               background:rgba(255,255,255,.1);color:#fff;cursor:pointer;">
                    <option value="">— select camera —</option>
                </select>
                <button id="captureBtn"
                        style="width:64px;height:64px;border-radius:50%;border:4px solid #fff;
                               background:#34d399;color:#fff;font-size:1.4rem;cursor:pointer;
                               display:flex;align-items:center;justify-content:center;flex-shrink:0;">
                    <i class="fas fa-circle"></i>
                </button>
            </div>

            <!-- Post-capture controls (hidden until snapshot) -->
            <div id="snapControls" style="display:none;gap:10px;align-items:center;justify-content:center;">
                <button id="retakeBtn"
                        style="padding:10px 20px;border-radius:10px;border:1.5px solid rgba(255,255,255,.3);
                               background:transparent;color:#fff;font-size:0.85rem;font-weight:600;cursor:pointer;">
                    <i class="fas fa-redo"></i> Retake
                </button>
                <button id="usePhotoBtn"
                        style="padding:10px 20px;border-radius:10px;border:none;
                               background:#34d399;color:#1a1a2e;font-size:0.85rem;font-weight:700;cursor:pointer;">
                    <i class="fas fa-check"></i> Use Photo
                </button>
            </div>
        </div>

        <!-- Camera error -->
        <div id="cameraError"
             style="display:none;padding:14px 20px;color:#fca5a5;font-size:0.82rem;
                    border-top:1px solid rgba(255,255,255,.08);text-align:center;"></div>
    </div>
</div>

<!-- ── Review Modal ──────────────────────────────────────────────────────────── -->
<div id="reviewModalOverlay"
     style="display:none;position:fixed;inset:0;background:rgba(0,0,0,.55);z-index:1050;
            align-items:center;justify-content:center;padding:16px;">
    <div style="background:#fff;border-radius:16px;width:100%;max-width:560px;
                box-shadow:0 20px 60px rgba(0,0,0,.25);display:flex;flex-direction:column;
                max-height:90vh;overflow:hidden;">

        <!-- Header -->
        <div style="padding:20px 24px 16px;border-bottom:1px solid #e2e8f0;
                    display:flex;align-items:center;justify-content:space-between;">
            <div>
                <div style="font-family:var(--font-display);font-weight:800;font-size:1.05rem;">
                    <i class="fas fa-star" style="color:#f59e0b;margin-right:6px;"></i> Leave a Review
                </div>
                <div id="reviewModalSubtitle" style="font-size:0.8rem;color:var(--neutral-400);margin-top:2px;"></div>
            </div>
            <button id="closeReviewModal"
                    style="width:32px;height:32px;border:none;background:#f1f5f9;border-radius:8px;
                           cursor:pointer;color:var(--neutral-600);font-size:1rem;">
                <i class="fas fa-times"></i>
            </button>
        </div>

        <!-- Scrollable body -->
        <div style="padding:20px 24px;overflow-y:auto;flex:1;">

            <input type="hidden" id="rv_order_id">
            <input type="hidden" id="rv_restaurant_id">

            <!-- Star picker -->
            <div style="margin-bottom:18px;">
                <div style="font-size:0.82rem;font-weight:700;color:var(--neutral-700);margin-bottom:8px;">
                    Overall Rating <span style="color:var(--danger);">*</span>
                </div>
                <div id="rvStarPicker" style="display:flex;gap:8px;margin-bottom:6px;">
                    <?php for ($s = 1; $s <= 5; $s++): ?>
                    <i class="fas fa-star rv-star" data-val="<?= $s ?>"
                       style="font-size:2rem;cursor:pointer;color:#e2e8f0;transition:color .1s;"></i>
                    <?php endfor; ?>
                </div>
                <input type="hidden" id="rv_rating" value="0">
                <div id="rvRatingLabel" style="font-size:0.78rem;color:var(--neutral-400);">Click a star to rate</div>
            </div>

            <!-- Sub-ratings -->
            <div style="display:grid;grid-template-columns:1fr 1fr;gap:14px;margin-bottom:18px;">
                <div>
                    <label style="font-size:0.78rem;font-weight:600;color:var(--neutral-600);display:block;margin-bottom:4px;">Food Quality</label>
                    <select id="rv_food_rating" class="form-control" style="font-size:0.85rem;">
                        <option value="5">5 — Excellent</option>
                        <option value="4">4 — Very Good</option>
                        <option value="3">3 — Good</option>
                        <option value="2">2 — Fair</option>
                        <option value="1">1 — Poor</option>
                    </select>
                </div>
                <div>
                    <label style="font-size:0.78rem;font-weight:600;color:var(--neutral-600);display:block;margin-bottom:4px;">Service</label>
                    <select id="rv_service_rating" class="form-control" style="font-size:0.85rem;">
                        <option value="5">5 — Excellent</option>
                        <option value="4">4 — Very Good</option>
                        <option value="3">3 — Good</option>
                        <option value="2">2 — Fair</option>
                        <option value="1">1 — Poor</option>
                    </select>
                </div>
            </div>

            <!-- Comment -->
            <div style="margin-bottom:18px;">
                <label style="font-size:0.82rem;font-weight:700;color:var(--neutral-700);display:block;margin-bottom:4px;">Your Review</label>
                <textarea id="rv_comment" class="form-control" rows="4"
                          placeholder="How was the food and service? Share your experience…"
                          style="font-size:0.85rem;resize:vertical;"></textarea>
            </div>

            <!-- Photo attachment — camera-first -->
            <div style="margin-bottom:18px;">
                <label style="font-size:0.82rem;font-weight:700;color:var(--neutral-700);display:block;margin-bottom:8px;">
                    Add Photos <span style="font-weight:400;color:var(--neutral-400);">(optional, up to 10)</span>
                </label>

                <!-- Primary: Take Photo -->
                <button type="button" id="openCameraBtn"
                        style="width:100%;display:flex;align-items:center;justify-content:center;
                               gap:10px;padding:13px 18px;border-radius:12px;border:none;
                               background:var(--primary-600,#059669);color:#fff;font-size:0.95rem;
                               font-weight:700;cursor:pointer;transition:opacity .15s;margin-bottom:8px;">
                    <i class="fas fa-camera" style="font-size:1.1rem;"></i> Take Photo
                </button>

                <!-- Secondary: file upload -->
                <label id="photoUploadLabel"
                       style="display:flex;align-items:center;justify-content:center;gap:8px;
                              padding:8px 14px;border:1.5px dashed #cbd5e1;border-radius:10px;
                              cursor:pointer;background:#f8fafc;transition:border-color .15s;
                              font-size:0.8rem;color:var(--neutral-500);">
                    <i class="fas fa-folder-open" style="font-size:0.9rem;"></i>
                    Or upload a file from device
                    <input type="file" id="rv_photos" accept="image/*" multiple style="display:none;">
                </label>

                <!-- Thumbnail strip -->
                <div id="photoPreviewStrip"
                     style="display:flex;flex-wrap:wrap;gap:8px;margin-top:10px;"></div>
            </div>

            <!-- Toast -->
            <div id="rvToast" style="display:none;padding:10px 14px;border-radius:8px;
                                     font-size:0.85rem;font-weight:600;margin-bottom:12px;"></div>
        </div>

        <!-- Footer -->
        <div style="padding:16px 24px;border-top:1px solid #e2e8f0;display:flex;justify-content:flex-end;gap:10px;">
            <button id="cancelReviewBtn" class="btn btn-outline btn-sm">Cancel</button>
            <button id="submitReviewBtn" class="btn btn-primary">
                <i class="fas fa-paper-plane"></i> Submit Review
            </button>
        </div>
    </div>
</div>

<?php require_once __DIR__ . '/../../includes/footer.php'; ?>

<script>
(function () {
'use strict';

/* ── Constants ─────────────────────────────────────────────────────────────── */
const CONFIRM_URL = '<?= BASE_URL ?>dashboard/customer/api/confirm_received.php';
const REVIEW_URL  = '<?= BASE_URL ?>dashboard/customer/api/submit_review.php';
const STAR_LABELS = ['','1 — Poor','2 — Fair','3 — Good','4 — Very Good','5 — Excellent'];

/* =========================================================================
   CAMERA CONTROLLER
   ========================================================================= */
const camOverlay    = document.getElementById('cameraModalOverlay');
const camVideo      = document.getElementById('cameraVideo');
const camCanvas     = document.getElementById('cameraCanvas');
const camSelect     = document.getElementById('cameraSelect');
const captureBtn    = document.getElementById('captureBtn');
const retakeBtn     = document.getElementById('retakeBtn');
const usePhotoBtn   = document.getElementById('usePhotoBtn');
const liveControls  = document.getElementById('liveControls');
const snapControls  = document.getElementById('snapControls');
const camError      = document.getElementById('cameraError');
const closeCamBtn   = document.getElementById('closeCameraBtn');
const openCamBtn    = document.getElementById('openCameraBtn');

let activeStream    = null;   // MediaStream currently running
let capturedBlobs   = [];     // Blobs already committed via "Use Photo"

/* -- Enumerate cameras and populate select --------------------------------- */
async function populateCameras() {
    camSelect.innerHTML = '<option value="">— select camera —</option>';
    try {
        const devices = await navigator.mediaDevices.enumerateDevices();
        const cams    = devices.filter(d => d.kind === 'videoinput');
        if (cams.length <= 1) {
            camSelect.style.display = 'none';
            return;
        }
        camSelect.style.display = '';
        cams.forEach((cam, i) => {
            const opt = document.createElement('option');
            opt.value       = cam.deviceId;
            opt.textContent = cam.label || 'Camera ' + (i + 1);
            // Default to back camera on mobile
            if (cam.label.toLowerCase().includes('back') ||
                cam.label.toLowerCase().includes('rear') ||
                cam.label.toLowerCase().includes('environment')) {
                opt.selected = true;
            }
            camSelect.appendChild(opt);
        });
    } catch (_) { camSelect.style.display = 'none'; }
}

/* -- Start or switch camera stream ---------------------------------------- */
async function startCamera(deviceId) {
    stopCamera();
    camError.style.display = 'none';

    const constraints = {
        video: deviceId
            ? { deviceId: { exact: deviceId }, width: { ideal: 1280 }, height: { ideal: 720 } }
            : { facingMode: { ideal: 'environment' }, width: { ideal: 1280 }, height: { ideal: 720 } },
        audio: false
    };

    try {
        activeStream     = await navigator.mediaDevices.getUserMedia(constraints);
        camVideo.srcObject = activeStream;
        camVideo.style.display  = 'block';
        camCanvas.style.display = 'none';
        liveControls.style.display = 'flex';
        snapControls.style.display = 'none';
        await populateCameras();
    } catch (err) {
        camError.style.display = 'block';
        camError.textContent   =
            err.name === 'NotAllowedError'
                ? 'Camera access denied. Please allow camera permissions and try again.'
                : err.name === 'NotFoundError'
                    ? 'No camera found on this device.'
                    : 'Could not start camera: ' + err.message;
    }
}

/* -- Stop camera stream ---------------------------------------------------- */
function stopCamera() {
    if (activeStream) {
        activeStream.getTracks().forEach(t => t.stop());
        activeStream = null;
    }
    camVideo.srcObject = null;
}

/* -- Capture frame from live video ---------------------------------------- */
function captureFrame() {
    const vw = camVideo.videoWidth  || camVideo.clientWidth;
    const vh = camVideo.videoHeight || camVideo.clientHeight;
    camCanvas.width  = vw;
    camCanvas.height = vh;
    camCanvas.getContext('2d').drawImage(camVideo, 0, 0, vw, vh);

    // Switch viewfinder to frozen snapshot
    camVideo.style.display  = 'none';
    camCanvas.style.display = 'block';
    liveControls.style.display = 'none';
    snapControls.style.display = 'flex';

    stopCamera(); // release camera while reviewing snapshot
}

/* -- Retake: restart stream ------------------------------------------------- */
retakeBtn.addEventListener('click', () => {
    startCamera(camSelect.value || undefined);
});

/* -- Camera select change --------------------------------------------------- */
camSelect.addEventListener('change', () => {
    if (camSelect.value) startCamera(camSelect.value);
});

/* -- Shutter button --------------------------------------------------------- */
captureBtn.addEventListener('click', captureFrame);

/* -- "Use Photo" — convert canvas to Blob, add to review payload ----------- */
usePhotoBtn.addEventListener('click', () => {
    camCanvas.toBlob(blob => {
        if (!blob) return;
        const timestamp = Date.now();
        // Give the blob a proper filename so PHP sees a valid extension
        const file = new File([blob], 'cam_' + timestamp + '.jpg', { type: 'image/jpeg' });
        capturedBlobs.push(file);
        addThumbnailFromBlob(file, capturedBlobs.length - 1, 'cam');
        closeCameraModal();
    }, 'image/jpeg', 0.92);
});

/* -- Open / close camera modal --------------------------------------------- */
openCamBtn.addEventListener('click', () => {
    if (!navigator.mediaDevices || !navigator.mediaDevices.getUserMedia) {
        alert('Camera not supported in this browser. Please use the file upload option.');
        return;
    }
    camOverlay.style.display = 'flex';
    startCamera();
});

function closeCameraModal() {
    stopCamera();
    camOverlay.style.display = 'none';
    camError.style.display   = 'none';
}

closeCamBtn.addEventListener('click', closeCameraModal);
camOverlay.addEventListener('click', e => { if (e.target === camOverlay) closeCameraModal(); });


/* =========================================================================
   REVIEW MODAL
   ========================================================================= */
const overlay        = document.getElementById('reviewModalOverlay');
const subtitle       = document.getElementById('reviewModalSubtitle');
const rvOrderId      = document.getElementById('rv_order_id');
const rvRestaurantId = document.getElementById('rv_restaurant_id');
const rvRating       = document.getElementById('rv_rating');
const rvRatingLabel  = document.getElementById('rvRatingLabel');
const rvFoodRating   = document.getElementById('rv_food_rating');
const rvSvcRating    = document.getElementById('rv_service_rating');
const rvComment      = document.getElementById('rv_comment');
const rvPhotos       = document.getElementById('rv_photos');
const previewStrip   = document.getElementById('photoPreviewStrip');
const rvToast        = document.getElementById('rvToast');
const submitBtn      = document.getElementById('submitReviewBtn');
const cancelBtn      = document.getElementById('cancelReviewBtn');
const closeBtn       = document.getElementById('closeReviewModal');

/* -- Star picker ------------------------------------------------------------ */
const starEls = document.querySelectorAll('.rv-star');
function paintStars(upTo) {
    starEls.forEach(s => {
        s.style.color = parseInt(s.dataset.val) <= upTo ? '#f59e0b' : '#e2e8f0';
    });
}
starEls.forEach(star => {
    star.addEventListener('mouseenter', () => paintStars(parseInt(star.dataset.val)));
    star.addEventListener('mouseleave', () => paintStars(parseInt(rvRating.value) || 0));
    star.addEventListener('click', () => {
        const v = parseInt(star.dataset.val);
        rvRating.value = v;
        rvRatingLabel.textContent = STAR_LABELS[v] || '';
        paintStars(v);
    });
});

/* -- Thumbnail helper (used for both file upload and camera blobs) ---------- */
function addThumbnailFromBlob(fileOrBlob, index, source) {
    const url  = URL.createObjectURL(fileOrBlob);
    const wrap = document.createElement('div');
    wrap.style.cssText = 'position:relative;width:72px;height:72px;flex-shrink:0;';
    wrap.dataset.source = source;
    wrap.dataset.index  = index;

    const img = document.createElement('img');
    img.src = url;
    img.style.cssText = 'width:72px;height:72px;object-fit:cover;border-radius:8px;border:2px solid #34d399;';

    // Camera badge
    if (source === 'cam') {
        const badge = document.createElement('span');
        badge.innerHTML = '<i class="fas fa-camera"></i>';
        badge.style.cssText =
            'position:absolute;bottom:3px;right:3px;background:rgba(0,0,0,.65);color:#fff;' +
            'border-radius:4px;padding:1px 4px;font-size:0.6rem;';
        wrap.appendChild(badge);
    }

    // Remove button
    const rm = document.createElement('button');
    rm.type = 'button';
    rm.innerHTML = '<i class="fas fa-times"></i>';
    rm.style.cssText =
        'position:absolute;top:-5px;right:-5px;width:18px;height:18px;border-radius:50%;' +
        'border:none;background:#ef4444;color:#fff;font-size:0.6rem;cursor:pointer;' +
        'display:flex;align-items:center;justify-content:center;padding:0;';
    rm.addEventListener('click', () => {
        if (source === 'cam') capturedBlobs.splice(index, 1);
        wrap.remove();
        URL.revokeObjectURL(url);
    });

    wrap.appendChild(img);
    wrap.appendChild(rm);
    previewStrip.appendChild(wrap);
}

/* -- File input change: preview standard uploads --------------------------- */
rvPhotos.addEventListener('change', function () {
    // Remove existing file-upload thumbnails (keep camera ones)
    previewStrip.querySelectorAll('[data-source="file"]').forEach(el => el.remove());

    const files = Array.from(this.files).slice(0, 10 - capturedBlobs.length);
    files.forEach((file, i) => addThumbnailFromBlob(file, i, 'file'));
    if (files.length > 0) {
        document.getElementById('photoUploadLabel').style.borderColor = '#f59e0b';
    }
});

/* -- Open review modal ------------------------------------------------------ */
function openReviewModal(orderId, restaurantId, restaurantName, orderNumber) {
    rvOrderId.value      = orderId;
    rvRestaurantId.value = restaurantId;
    subtitle.textContent = orderNumber
        ? orderNumber + ' — ' + restaurantName
        : restaurantName;

    // Reset
    rvRating.value = '0';
    paintStars(0);
    rvRatingLabel.textContent = 'Click a star to rate';
    rvFoodRating.value = '5';
    rvSvcRating.value  = '5';
    rvComment.value    = '';
    previewStrip.innerHTML = '';
    rvPhotos.value = '';
    capturedBlobs  = [];
    document.getElementById('photoUploadLabel').style.borderColor = '#cbd5e1';
    rvToast.style.display = 'none';
    submitBtn.disabled    = false;
    submitBtn.innerHTML   = '<i class="fas fa-paper-plane"></i> Submit Review';

    overlay.style.display  = 'flex';
    document.body.style.overflow = 'hidden';
}

function closeReviewModal() {
    overlay.style.display = 'none';
    document.body.style.overflow = '';
    capturedBlobs = [];
}

closeBtn.addEventListener('click', closeReviewModal);
cancelBtn.addEventListener('click', closeReviewModal);
overlay.addEventListener('click', e => { if (e.target === overlay) closeReviewModal(); });

/* -- Wire a single "Leave a Review" button ---------------------------------- */
function wireReviewBtn(btn) {
    if (!btn) return;
    btn.addEventListener('click', () => openReviewModal(
        btn.dataset.orderId,
        btn.dataset.restaurantId,
        btn.dataset.restaurantName || 'Restaurant',
        btn.dataset.orderNumber   || ''
    ));
}
document.querySelectorAll('.leave-review-btn').forEach(wireReviewBtn);

/* -- Submit review via Fetch ----------------------------------------------- */
submitBtn.addEventListener('click', async () => {
    const rating = parseInt(rvRating.value) || 0;
    if (rating < 1) { showRvToast('Please select a star rating.', true); return; }

    submitBtn.disabled = true;
    submitBtn.innerHTML = '<i class="fas fa-spinner fa-spin"></i> Submitting…';

    const fd = new FormData();
    fd.append('order_id',       rvOrderId.value);
    fd.append('restaurant_id',  rvRestaurantId.value);
    fd.append('rating',         rating);
    fd.append('food_rating',    rvFoodRating.value);
    fd.append('service_rating', rvSvcRating.value);
    fd.append('comment',        rvComment.value.trim());

    // 1. Camera blobs (already File objects with .jpg extension)
    capturedBlobs.forEach(f => fd.append('photos[]', f));

    // 2. Standard file-upload selections (if any)
    const fileCount = Math.min(rvPhotos.files.length, 10 - capturedBlobs.length);
    for (let i = 0; i < fileCount; i++) fd.append('photos[]', rvPhotos.files[i]);

    try {
        const res  = await fetch(REVIEW_URL, { method: 'POST', body: fd });
        const data = await res.json();
        if (data.success) {
            const actionCell = document.getElementById('action-cell-' + rvOrderId.value);
            if (actionCell) {
                actionCell.innerHTML =
                    '<span style="font-size:0.78rem;color:#16a34a;font-weight:600;">' +
                    '<i class="fas fa-check-circle"></i> Reviewed</span>';
            }
            closeReviewModal();
        } else {
            showRvToast(data.message || 'Submission failed.', true);
            submitBtn.disabled = false;
            submitBtn.innerHTML = '<i class="fas fa-paper-plane"></i> Submit Review';
        }
    } catch (_) {
        showRvToast('Network error. Please try again.', true);
        submitBtn.disabled = false;
        submitBtn.innerHTML = '<i class="fas fa-paper-plane"></i> Submit Review';
    }
});

/* ── "Order Received" handler ────────────────────────────────────────────────── */
document.querySelectorAll('.confirm-received-btn').forEach(function (btn) {
    btn.addEventListener('click', function () {
        const orderId    = btn.dataset.orderId;
        const actionCell = document.getElementById('action-cell-' + orderId);
        const statusCell = document.getElementById('status-cell-' + orderId);
        if (!confirm('Confirm that you have received your order?')) return;
        btn.disabled = true;
        btn.innerHTML = '<i class="fas fa-spinner fa-spin"></i> Confirming…';
        const fd = new FormData();
        fd.append('order_id', orderId);
        fetch(CONFIRM_URL, { method: 'POST', body: fd })
            .then(r => r.json())
            .then(d => {
                if (d.success) {
                    statusCell.innerHTML = '<span class="badge badge-success">Delivered</span>';
                    actionCell.innerHTML =
                        '<button type="button" class="btn btn-sm leave-review-btn" ' +
                        'style="background:#f59e0b;color:#fff;border:none;padding:6px 12px;' +
                        'border-radius:8px;font-weight:600;font-size:0.8rem;cursor:pointer;white-space:nowrap;" ' +
                        'data-order-id="'       + escHtml(btn.dataset.orderId)        + '" ' +
                        'data-restaurant-id="'  + escHtml(btn.dataset.restaurantId)   + '" ' +
                        'data-restaurant-name="'+ escHtml(btn.dataset.restaurantName) + '" ' +
                        'data-order-number="'   + escHtml(btn.dataset.orderNumber)    + '">' +
                        '<i class="fas fa-star"></i> Leave a Review</button>';
                    wireReviewBtn(actionCell.querySelector('.leave-review-btn'));
                } else {
                    btn.disabled = false;
                    btn.innerHTML = '<i class="fas fa-box-open"></i> Order Received';
                    alert(d.message || 'Could not confirm order. Please try again.');
                }
            })
            .catch(() => {
                btn.disabled = false;
                btn.innerHTML = '<i class="fas fa-box-open"></i> Order Received';
                alert('Network error. Please try again.');
            });
    });
});

/* ── Helpers ─────────────────────────────────────────────────────────────────── */
function showRvToast(msg, isError) {
    rvToast.textContent      = msg;
    rvToast.style.display    = 'block';
    rvToast.style.background = isError ? '#fef2f2' : '#f0fdf4';
    rvToast.style.border     = isError ? '1px solid #fecaca' : '1px solid #bbf7d0';
    rvToast.style.color      = isError ? '#991b1b' : '#166534';
}

function escHtml(str) {
    const d = document.createElement('div');
    d.textContent = String(str ?? '');
    return d.innerHTML;
}

/* ── Real-time Order Status Polling ──────────────────────────────────────────── */
// Poll for order status updates every 15 seconds for active orders
const activeOrderIds = [];
document.querySelectorAll('tr[id^="order-row-"]').forEach(row => {
    const orderId = row.id.replace('order-row-', '');
    const statusCell = document.getElementById('status-cell-' + orderId);
    if (statusCell) {
        const currentStatus = statusCell.textContent.toLowerCase().trim();
        // Only poll orders that are in progress (not completed/cancelled/delivered)
        if (!currentStatus.includes('completed') && !currentStatus.includes('cancelled') && 
            !currentStatus.includes('delivered') && !currentStatus.includes('failed')) {
            activeOrderIds.push(orderId);
        }
    }
});

async function pollOrderStatuses() {
    if (activeOrderIds.length === 0) return;
    
    try {
        const response = await fetch('<?= BASE_URL ?>dashboard/customer/api/get_order_statuses.php', {
            method: 'POST',
            headers: { 'Content-Type': 'application/json' },
            body: JSON.stringify({ order_ids: activeOrderIds })
        });
        
        const data = await response.json();
        
        if (data.success && data.orders) {
            data.orders.forEach(order => {
                // ── Order status badge update ──────────────────────────────────
                const statusCell = document.getElementById('status-cell-' + order.id);
                if (statusCell && order.order_status) {
                    statusCell.innerHTML = getStatusBadgeHtml(order.order_status);

                    // Remove from active polling when terminal status reached
                    if (order.order_status === 'completed' || order.order_status === 'delivered' ||
                        order.order_status === 'cancelled') {
                        const index = activeOrderIds.indexOf(order.id.toString());
                        if (index > -1) activeOrderIds.splice(index, 1);
                    }
                }

                // ── Payment status badge update (GCash / Bank Transfer) ────────
                const payCell = document.getElementById('pay-cell-' + order.id);
                if (payCell && order.payment_status) {
                    const prevStatus    = payCell.dataset.paymentStatus;
                    const paymentMethod = payCell.dataset.paymentMethod;
                    const orderNumber   = payCell.dataset.orderNumber;
                    const isOnline      = paymentMethod === 'gcash' || paymentMethod === 'bank_transfer';

                    // Only act when transitioning from pending → paid for online payments
                    if (isOnline && prevStatus !== 'paid' && order.payment_status === 'paid') {
                        // Update badge
                        payCell.innerHTML = getPaymentBadgeHtml('paid');
                        payCell.dataset.paymentStatus = 'paid';

                        // Show "Payment Received" popup
                        showPaymentReceivedPopup(orderNumber, paymentMethod);
                    } else if (prevStatus !== order.payment_status) {
                        // Keep badge in sync for any other status change
                        payCell.innerHTML = getPaymentBadgeHtml(order.payment_status);
                        payCell.dataset.paymentStatus = order.payment_status;
                    }
                }
            });
        }
    } catch (error) {
        console.error('Error polling order statuses:', error);
    }
}

/* ── Payment badge HTML ──────────────────────────────────────────────────────── */
function getPaymentBadgeHtml(status) {
    const map = {
        'paid':          '<span class="badge badge-success">Paid</span>',
        'pending':       '<span class="badge badge-warning">Pending</span>',
        'awaiting_cash': '<span class="badge badge-warning">Awaiting Cash</span>',
        'failed':        '<span class="badge badge-danger">Failed</span>',
        'refunded':      '<span class="badge badge-info">Refunded</span>',
    };
    return map[status] || '<span class="badge badge-secondary">' + escHtml(status) + '</span>';
}

/* ── "Payment Received" popup for GCash / Bank Transfer ─────────────────────── */
function showPaymentReceivedPopup(orderNumber, paymentMethod) {
    // Remove any existing popup
    const existing = document.getElementById('_payReceivedPopup');
    if (existing) existing.remove();

    const methodLabel = paymentMethod === 'gcash' ? 'GCash' : 'Bank Transfer';

    const popup = document.createElement('div');
    popup.id = '_payReceivedPopup';
    popup.style.cssText = [
        'position:fixed', 'inset:0', 'z-index:2000',
        'display:flex', 'align-items:center', 'justify-content:center',
        'padding:16px', 'background:rgba(0,0,0,.45)',
        'animation:fadeInBg .25s ease'
    ].join(';');

    popup.innerHTML = `
        <div style="background:#fff;border-radius:20px;width:100%;max-width:400px;
                    box-shadow:0 24px 64px rgba(0,0,0,.22);overflow:hidden;
                    animation:slideUpIn .3s cubic-bezier(.34,1.56,.64,1);">
            <!-- Green header -->
            <div style="background:linear-gradient(135deg,#10b981,#059669);
                        padding:32px 24px 24px;text-align:center;">
                <div style="width:72px;height:72px;background:rgba(255,255,255,.2);border-radius:50%;
                            margin:0 auto 14px;display:flex;align-items:center;justify-content:center;">
                    <i class="fas fa-check-circle" style="font-size:2.4rem;color:#fff;"></i>
                </div>
                <h2 style="margin:0;font-size:1.45rem;font-weight:800;color:#fff;">Payment Received!</h2>
                <p style="margin:6px 0 0;font-size:.9rem;color:rgba(255,255,255,.88);">
                    ${escHtml(methodLabel)} payment confirmed
                </p>
            </div>
            <!-- Body -->
            <div style="padding:24px;">
                <div style="text-align:center;margin-bottom:18px;">
                    <div style="font-size:.78rem;color:#6b7280;margin-bottom:4px;">Order Number</div>
                    <div style="font-size:1.35rem;font-weight:800;color:#111827;
                                font-family:monospace;letter-spacing:1px;">${escHtml(orderNumber)}</div>
                </div>
                <div style="background:#f0fdf4;border:1.5px solid #86efac;border-radius:12px;
                            padding:14px 16px;margin-bottom:20px;font-size:.85rem;
                            color:#166534;line-height:1.55;">
                    <i class="fas fa-info-circle" style="margin-right:6px;"></i>
                    Your ${escHtml(methodLabel)} payment has been verified. The restaurant is now
                    preparing your order.
                </div>
                <button id="_payReceivedOkBtn"
                        style="width:100%;padding:13px;border:none;border-radius:12px;
                               background:#059669;color:#fff;font-size:1rem;font-weight:700;
                               cursor:pointer;transition:background .15s;">
                    <i class="fas fa-thumbs-up"></i>&nbsp; Got it!
                </button>
            </div>
        </div>`;

    // Inject keyframe animations once
    if (!document.getElementById('_payPopupStyles')) {
        const style = document.createElement('style');
        style.id = '_payPopupStyles';
        style.textContent = `
            @keyframes fadeInBg   { from { opacity:0 } to { opacity:1 } }
            @keyframes slideUpIn  { from { transform:translateY(40px);opacity:0 }
                                     to   { transform:translateY(0);opacity:1 } }`;
        document.head.appendChild(style);
    }

    document.body.appendChild(popup);

    // Close handlers
    const close = () => popup.remove();
    document.getElementById('_payReceivedOkBtn').addEventListener('click', close);
    popup.addEventListener('click', e => { if (e.target === popup) close(); });
}

function getStatusBadgeHtml(status) {
    const badges = {
        'pending': '<span class="badge badge-warning">Pending</span>',
        'confirmed': '<span class="badge badge-info">Confirmed</span>',
        'preparing': '<span class="badge badge-primary">Preparing</span>',
        'ready': '<span class="badge badge-success">Ready</span>',
        'completed': '<span class="badge badge-success">Completed</span>',
        'delivered': '<span class="badge badge-success">Delivered</span>',
        'cancelled': '<span class="badge badge-danger">Cancelled</span>',
        'failed': '<span class="badge badge-danger">Failed</span>'
    };
    return badges[status] || '<span class="badge badge-secondary">' + status + '</span>';
}

// Start polling if there are active orders
if (activeOrderIds.length > 0) {
    // Poll immediately on load
    pollOrderStatuses();
    // Then poll every 15 seconds
    setInterval(pollOrderStatuses, 15000);
}

})();
</script>
