<?php
require_once __DIR__ . '/../../config/app.php';
requireLogin();
requireRole([ROLE_CUSTOMER]);

$page_title   = 'My Staff Applications';
$page_heading = 'My Staff Applications';
$is_dashboard = true;
$breadcrumbs  = [
    ['label' => 'Dashboard', 'url' => BASE_URL . 'dashboard/customer/'],
    ['label' => 'My Staff Applications'],
];
$user_id = (int)$_SESSION['user_id'];

// ── Idempotent migration ──────────────────────────────────────────────────────
$conn->query("
CREATE TABLE IF NOT EXISTS `staff_applications` (
    `id`                INT UNSIGNED  NOT NULL AUTO_INCREMENT,
    `user_id`           INT(11)       NOT NULL,
    `restaurant_id`     INT(11)       NOT NULL,
    `application_type`  ENUM('applicant','existing') NOT NULL,
    `applied_role`      ENUM('ops_manager','cashier','chef') NOT NULL DEFAULT 'ops_manager',
    `cover_letter_text` TEXT          DEFAULT NULL,
    `file_cover_letter` VARCHAR(500)  DEFAULT NULL,
    `file_resume`       VARCHAR(500)  DEFAULT NULL,
    `file_nbi`          VARCHAR(500)  DEFAULT NULL,
    `file_medical`      VARCHAR(500)  DEFAULT NULL,
    `file_gov_id`       VARCHAR(500)  DEFAULT NULL,
    `file_proof_employ` VARCHAR(500)  DEFAULT NULL,
    `status`            ENUM('pending','approved','rejected') NOT NULL DEFAULT 'pending',
    `owner_notes`       TEXT          DEFAULT NULL,
    `reviewed_by`       INT(11)       DEFAULT NULL,
    `reviewed_at`       DATETIME      DEFAULT NULL,
    `created_at`        TIMESTAMP     NOT NULL DEFAULT CURRENT_TIMESTAMP,
    `updated_at`        TIMESTAMP     NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (`id`),
    KEY `idx_sa_user`       (`user_id`),
    KEY `idx_sa_restaurant` (`restaurant_id`),
    KEY `idx_sa_status`     (`status`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
");

// ── Load all applications for this user ───────────────────────────────────────
$apps_result = $conn->query("
    SELECT sa.id, sa.applied_role, sa.application_type, sa.status,
           sa.owner_notes, sa.created_at, sa.reviewed_at,
           hr.restaurant_name, hr.id AS restaurant_id,
           hr.cuisine_type, hr.address
    FROM staff_applications sa
    JOIN halal_restaurants hr ON sa.restaurant_id = hr.id
    WHERE sa.user_id = {$user_id}
    ORDER BY sa.created_at DESC
");
$applications = $apps_result ? $apps_result->fetch_all(MYSQLI_ASSOC) : [];
if ($apps_result) $apps_result->free();

// ── Helpers ───────────────────────────────────────────────────────────────────
$role_labels = [
    'ops_manager' => 'Operations Manager',
    'cashier'     => 'Cashier',
    'chef'        => 'Chef',
];
$type_labels = [
    'applicant' => 'New Applicant',
    'existing'  => 'Existing Employee',
];
$status_map = [
    'pending'  => ['bg' => '#fef9c3', 'color' => '#854d0e', 'border' => '#fde68a',
                   'icon' => 'fas fa-hourglass-half', 'label' => 'Pending Review'],
    'approved' => ['bg' => '#dcfce7', 'color' => '#166534', 'border' => '#bbf7d0',
                   'icon' => 'fas fa-check-circle',   'label' => 'Approved'],
    'rejected' => ['bg' => '#fee2e2', 'color' => '#991b1b', 'border' => '#fecaca',
                   'icon' => 'fas fa-times-circle',   'label' => 'Not Approved'],
];

require_once __DIR__ . '/../../includes/header.php';
?>

<!-- ── Page hero ──────────────────────────────────────────────────────────── -->
<div style="background:linear-gradient(135deg,#1d4ed8 0%,#2563eb 60%,#7c3aed 100%);
            border-radius:var(--radius-xl);padding:28px 32px;margin-bottom:24px;
            position:relative;overflow:hidden;">
    <div style="position:absolute;right:-40px;top:-40px;width:180px;height:180px;border-radius:50%;background:rgba(255,255,255,.05);pointer-events:none;"></div>
    <div style="position:absolute;right:60px;bottom:-50px;width:120px;height:120px;border-radius:50%;background:rgba(255,255,255,.04);pointer-events:none;"></div>
    <div style="position:relative;display:flex;align-items:center;justify-content:space-between;flex-wrap:wrap;gap:16px;">
        <div>
            <div style="font-size:.72rem;font-weight:700;letter-spacing:.08em;text-transform:uppercase;color:#93c5fd;margin-bottom:8px;">
                <i class="fas fa-briefcase" style="margin-right:5px;"></i>Career at a Halal Restaurant
            </div>
            <h1 style="font-family:var(--font-display);font-size:1.5rem;font-weight:800;color:#fff;margin:0 0 6px;line-height:1.2;">
                My Staff Applications
            </h1>
            <p style="color:rgba(255,255,255,.7);font-size:.88rem;margin:0;">
                Track the status of your position applications at halal restaurants.
            </p>
        </div>
        <a href="<?= BASE_URL ?>dashboard/customer/restaurants.php"
           style="display:inline-flex;align-items:center;gap:8px;background:#fff;
                  color:#1d4ed8;padding:11px 22px;border-radius:var(--radius-md);
                  font-weight:700;font-size:.88rem;text-decoration:none;
                  transition:opacity .15s;white-space:nowrap;flex-shrink:0;"
           onmouseover="this.style.opacity='.88'" onmouseout="this.style.opacity='1'">
            <i class="fas fa-plus"></i> Apply at a Restaurant
        </a>
    </div>
</div>

<?php if (empty($applications)): ?>
<!-- Empty state -->
<div class="card" style="text-align:center;padding:56px 32px;">
    <div style="width:72px;height:72px;border-radius:50%;background:var(--primary-50);
                display:flex;align-items:center;justify-content:center;
                margin:0 auto 20px;font-size:1.8rem;color:var(--primary-400);">
        <i class="fas fa-user-tie"></i>
    </div>
    <h3 style="color:var(--neutral-700);margin-bottom:8px;">No Applications Yet</h3>
    <p style="color:var(--neutral-400);font-size:.9rem;margin-bottom:24px;max-width:400px;margin-left:auto;margin-right:auto;">
        Browse halal restaurants and click <strong>View &amp; Apply</strong> on any restaurant card
        to apply for an Operations Manager, Cashier, or Chef position.
    </p>
    <a href="<?= BASE_URL ?>dashboard/customer/restaurants.php" class="btn btn-primary">
        <i class="fas fa-utensils"></i> Browse Restaurants
    </a>
</div>

<?php else: ?>

<div style="display:flex;flex-direction:column;gap:14px;">
<?php foreach ($applications as $app):
    $st  = $status_map[$app['status']] ?? $status_map['pending'];
    $rl  = $role_labels[$app['applied_role']] ?? $app['applied_role'];
    $tl  = $type_labels[$app['application_type']] ?? $app['application_type'];
    $role_colors = [
        'ops_manager' => ['bg' => '#eff6ff', 'color' => '#1d4ed8', 'icon' => 'fas fa-user-tie'],
        'cashier'     => ['bg' => '#fff7ed', 'color' => '#9a3412', 'icon' => 'fas fa-cash-register'],
        'chef'        => ['bg' => '#fdf4ff', 'color' => '#7e22ce', 'icon' => 'fas fa-hat-chef'],
    ];
    $rc = $role_colors[$app['applied_role']] ?? ['bg' => '#f1f5f9', 'color' => '#334155', 'icon' => 'fas fa-briefcase'];
?>
<div class="card" style="border-left:4px solid <?= $st['border'] ?>;transition:box-shadow .2s;"
     onmouseover="this.style.boxShadow='0 6px 20px rgba(0,0,0,.08)'" onmouseout="this.style.boxShadow=''">
    <div class="card-body" style="padding:20px 24px;">
        <div style="display:flex;align-items:flex-start;gap:16px;flex-wrap:wrap;">

            <!-- Role icon avatar -->
            <div style="width:50px;height:50px;border-radius:14px;flex-shrink:0;
                        background:<?= $rc['bg'] ?>;color:<?= $rc['color'] ?>;
                        display:flex;align-items:center;justify-content:center;font-size:1.2rem;">
                <i class="<?= $rc['icon'] ?>"></i>
            </div>

            <!-- Main info -->
            <div style="flex:1;min-width:200px;">
                <div style="font-weight:700;font-size:1rem;color:var(--neutral-800);margin-bottom:3px;">
                    <a href="<?= BASE_URL ?>dashboard/customer/restaurant_view.php?id=<?= $app['restaurant_id'] ?>"
                       style="color:var(--primary-700);text-decoration:none;transition:color .15s;"
                       onmouseover="this.style.color='var(--primary-500)'" onmouseout="this.style.color='var(--primary-700)'">
                        <?= htmlspecialchars($app['restaurant_name']) ?>
                    </a>
                </div>
                <?php if (!empty($app['address'])): ?>
                <div style="font-size:.78rem;color:var(--neutral-400);margin-bottom:8px;display:flex;align-items:center;gap:4px;">
                    <i class="fas fa-map-marker-alt" style="color:var(--danger);font-size:.68rem;"></i>
                    <?= htmlspecialchars($app['address']) ?>
                </div>
                <?php endif; ?>
                <div style="display:flex;gap:8px;flex-wrap:wrap;align-items:center;">
                    <span style="background:<?= $rc['bg'] ?>;color:<?= $rc['color'] ?>;
                                 padding:4px 12px;border-radius:999px;font-size:.76rem;font-weight:700;
                                 display:inline-flex;align-items:center;gap:5px;">
                        <i class="<?= $rc['icon'] ?>" style="font-size:.68rem;"></i>
                        <?= htmlspecialchars($rl) ?>
                    </span>
                    <span style="background:var(--neutral-100);color:var(--neutral-600);
                                 padding:4px 12px;border-radius:999px;font-size:.73rem;font-weight:600;">
                        <?= htmlspecialchars($tl) ?>
                    </span>
                </div>
            </div>

            <!-- Status + dates -->
            <div style="display:flex;flex-direction:column;align-items:flex-end;gap:6px;flex-shrink:0;">
                <span style="display:inline-flex;align-items:center;gap:6px;
                             background:<?= $st['bg'] ?>;color:<?= $st['color'] ?>;
                             border:1px solid <?= $st['border'] ?>;
                             padding:6px 14px;border-radius:999px;font-size:.78rem;font-weight:700;white-space:nowrap;">
                    <i class="<?= $st['icon'] ?>" style="font-size:.7rem;"></i>
                    <?= $st['label'] ?>
                </span>
                <div style="font-size:.74rem;color:var(--neutral-400);text-align:right;">
                    Applied <?= formatDate($app['created_at']) ?>
                </div>
                <?php if (!empty($app['reviewed_at'])): ?>
                <div style="font-size:.74rem;color:var(--neutral-400);">
                    Reviewed <?= formatDate($app['reviewed_at']) ?>
                </div>
                <?php endif; ?>
            </div>
        </div>

        <?php if (!empty($app['owner_notes'])): ?>
        <div style="margin-top:14px;padding:12px 16px;background:var(--neutral-50);
                    border:1px solid var(--neutral-200);border-radius:var(--radius-md);font-size:.84rem;color:var(--neutral-700);">
            <div style="font-size:.7rem;font-weight:700;text-transform:uppercase;letter-spacing:.05em;color:var(--neutral-400);margin-bottom:4px;display:flex;align-items:center;gap:5px;">
                <i class="fas fa-comment-dots"></i> Notes from Restaurant
            </div>
            <?= nl2br(htmlspecialchars($app['owner_notes'])) ?>
        </div>
        <?php endif; ?>

        <div style="margin-top:14px;padding-top:12px;border-top:1px solid var(--neutral-100);
                    display:flex;gap:10px;flex-wrap:wrap;align-items:center;">
            <a href="<?= BASE_URL ?>dashboard/customer/restaurant_view.php?id=<?= $app['restaurant_id'] ?>"
               class="btn btn-sm btn-outline">
                <i class="fas fa-store"></i> View Restaurant
            </a>
            <?php if ($app['status'] === 'approved'): ?>
            <span style="font-size:.82rem;color:var(--primary-600);font-weight:600;display:flex;align-items:center;gap:5px;">
                <i class="fas fa-circle-check"></i>
                You're on the team! Check your dashboard for your staff workspace.
            </span>
            <?php elseif ($app['status'] === 'rejected'): ?>
            <a href="<?= BASE_URL ?>dashboard/customer/restaurant_view.php?id=<?= $app['restaurant_id'] ?>"
               class="btn btn-sm btn-primary">
                <i class="fas fa-redo"></i> Apply Again
            </a>
            <?php endif; ?>
        </div>
    </div>
</div>
<?php endforeach; ?>
</div>

<?php endif; ?>

<?php require_once __DIR__ . '/../../includes/footer.php'; ?>
