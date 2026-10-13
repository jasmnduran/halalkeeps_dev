<?php
require_once __DIR__ . '/../../config/app.php';
requireLogin();
requireRole([ROLE_ADMIN]);

$page_title   = 'Admin Dashboard';
$page_heading = 'Security & Administration';
$is_dashboard = true;
$breadcrumbs  = [['label' => 'Admin Dashboard']];

// -- Stats ---------------------------------------------------------------------
$pending_roles = $conn->query("SELECT COUNT(*) as c FROM role_applications WHERE status = 'pending'")->fetch_assoc()['c'];

// business_applications table may not exist yet on a fresh install — guard with @
$biz_res     = @$conn->query("SELECT COUNT(*) as c FROM business_applications WHERE status = 'pending'");
$pending_biz = $biz_res ? (int)$biz_res->fetch_assoc()['c'] : 0;

$failed_logins_result = $conn->query("SELECT COUNT(*) as c FROM login_attempts WHERE success = 0 AND attempt_time > DATE_SUB(NOW(), INTERVAL 24 HOUR)");
$failed_logins = $failed_logins_result ? $failed_logins_result->fetch_assoc()['c'] : 0;
$active_users  = $conn->query("SELECT COUNT(*) as c FROM users WHERE role_status = 'approved'")->fetch_assoc()['c'];
$lockouts_res  = $conn->query("SELECT COUNT(*) as c FROM users WHERE account_locked = 1");
$lockouts      = $lockouts_res ? $lockouts_res->fetch_assoc()['c'] : 0;

require_once __DIR__ . '/../../includes/header.php';
?>

<!-- Stat Cards -->
<div class="stats-grid" style="margin-bottom: 28px;">

    <!-- Pending Business Applications -->
    <div class="card card-stat <?= $pending_biz > 0 ? 'accent' : '' ?>">
        <div class="stat-icon <?= $pending_biz > 0 ? 'gold' : 'blue' ?>">
            <i class="fas fa-store"></i>
        </div>
        <div class="stat-value"><?= $pending_biz ?></div>
        <div class="stat-label">Pending Business Applications</div>
        <?php if ($pending_biz > 0): ?>
            <a href="<?= BASE_URL ?>dashboard/admin/business_applications.php"
               style="display:inline-block;margin-top:10px;font-size:0.78rem;color:var(--accent-600);font-weight:600;">
                Review now →
            </a>
        <?php endif; ?>
    </div>

    <!-- Pending Role Approvals -->
    <div class="card card-stat <?= $pending_roles > 0 ? 'accent' : '' ?>">
        <div class="stat-icon <?= $pending_roles > 0 ? 'gold' : 'blue' ?>">
            <i class="fas fa-user-clock"></i>
        </div>
        <div class="stat-value"><?= $pending_roles ?></div>
        <div class="stat-label">Pending Role Approvals</div>
        <?php if ($pending_roles > 0): ?>
            <a href="<?= BASE_URL ?>dashboard/admin/approve_roles.php"
               style="display:inline-block;margin-top:10px;font-size:0.78rem;color:var(--accent-600);font-weight:600;">
                Review now →
            </a>
        <?php endif; ?>
    </div>

    <!-- Failed Logins -->
    <div class="card card-stat <?= $failed_logins > 5 ? 'danger' : '' ?>">
        <div class="stat-icon <?= $failed_logins > 5 ? 'red' : 'green' ?>">
            <i class="fas fa-shield-alt"></i>
        </div>
        <div class="stat-value"><?= $failed_logins ?></div>
        <div class="stat-label">Failed Logins (24h)</div>
        <?php if ($failed_logins > 5): ?>
            <a href="<?= BASE_URL ?>dashboard/admin/logging_monitoring.php"
               style="display:inline-block;margin-top:10px;font-size:0.78rem;color:#dc2626;font-weight:600;">
                View logs →
            </a>
        <?php endif; ?>
    </div>

    <!-- Active Users -->
    <div class="card card-stat info">
        <div class="stat-icon blue">
            <i class="fas fa-users"></i>
        </div>
        <div class="stat-value"><?= $active_users ?></div>
        <div class="stat-label">Active Users</div>
    </div>

    <!-- Locked Accounts -->
    <div class="card card-stat <?= $lockouts > 0 ? 'danger' : '' ?>">
        <div class="stat-icon <?= $lockouts > 0 ? 'red' : 'green' ?>">
            <i class="fas fa-lock"></i>
        </div>
        <div class="stat-value"><?= $lockouts ?></div>
        <div class="stat-label">Locked Accounts</div>
        <?php if ($lockouts > 0): ?>
            <a href="<?= BASE_URL ?>dashboard/admin/logging_monitoring.php"
               style="display:inline-block;margin-top:10px;font-size:0.78rem;color:#dc2626;font-weight:600;">
                Manage →
            </a>
        <?php endif; ?>
    </div>

</div>

<!-- Module Cards -->
<div style="display:grid;grid-template-columns:repeat(auto-fit,minmax(280px,1fr));gap:20px;">

    <!-- Business Applications (NEW) -->
    <div class="card" style="display:flex;flex-direction:column;border-top:3px solid #059669;">
        <div class="card-body" style="flex:1;display:flex;flex-direction:column;gap:12px;">
            <div style="display:flex;align-items:center;gap:12px;">
                <div style="width:44px;height:44px;border-radius:10px;background:#ecfdf5;color:#059669;
                            display:flex;align-items:center;justify-content:center;font-size:1.1rem;flex-shrink:0;">
                    <i class="fas fa-store"></i>
                </div>
                <div>
                    <h3 style="margin:0;font-size:1rem;font-weight:700;color:var(--neutral-800);">Business Applications</h3>
                    <?php if ($pending_biz > 0): ?>
                        <span class="badge badge-warning" style="font-size:0.72rem;"><?= $pending_biz ?> pending</span>
                    <?php else: ?>
                        <span class="badge badge-success" style="font-size:0.72rem;">All clear</span>
                    <?php endif; ?>
                </div>
            </div>
            <p style="font-size:0.875rem;color:var(--neutral-500);margin:0;flex:1;">
                Review submitted business registrations, inspect uploaded documents, assign Halal classification, and approve or reject applicants.
            </p>
            <a href="<?= BASE_URL ?>dashboard/admin/business_applications.php"
               class="btn btn-primary btn-sm" style="align-self:stretch;text-align:center;">
                <i class="fas fa-store"></i> Review Business Applications
                <?= $pending_biz > 0 ? '<span class="badge badge-light" style="margin-left:6px;">'.$pending_biz.'</span>' : '' ?>
            </a>
        </div>
    </div>

    <!-- Role Applications -->
    <div class="card" style="display:flex;flex-direction:column;border-top:3px solid #f59e0b;">
        <div class="card-body" style="flex:1;display:flex;flex-direction:column;gap:12px;">
            <div style="display:flex;align-items:center;gap:12px;">
                <div style="width:44px;height:44px;border-radius:10px;background:#fffbeb;color:#d97706;
                            display:flex;align-items:center;justify-content:center;font-size:1.1rem;flex-shrink:0;">
                    <i class="fas fa-user-check"></i>
                </div>
                <div>
                    <h3 style="margin:0;font-size:1rem;font-weight:700;color:var(--neutral-800);">Role Applications</h3>
                    <?php if ($pending_roles > 0): ?>
                        <span class="badge badge-warning" style="font-size:0.72rem;"><?= $pending_roles ?> pending</span>
                    <?php else: ?>
                        <span class="badge badge-success" style="font-size:0.72rem;">All clear</span>
                    <?php endif; ?>
                </div>
            </div>
            <p style="font-size:0.875rem;color:var(--neutral-500);margin:0;flex:1;">
                Review and approve or reject user applications for system roles such as Evaluator, Auditor, and Committee members.
            </p>
            <a href="<?= BASE_URL ?>dashboard/admin/role_applications.php"
               class="btn btn-primary btn-sm" style="align-self:stretch;text-align:center;">
                <i class="fas fa-user-check"></i> Review Role Applications
                <?= $pending_roles > 0 ? '<span class="badge badge-light" style="margin-left:6px;">'.$pending_roles.'</span>' : '' ?>
            </a>
        </div>
    </div>

    <!-- Role Management -->
    <div class="card" style="display:flex;flex-direction:column;border-top:3px solid var(--primary-500);">
        <div class="card-body" style="flex:1;display:flex;flex-direction:column;gap:12px;">
            <div style="display:flex;align-items:center;gap:12px;">
                <div style="width:44px;height:44px;border-radius:10px;background:var(--primary-50);color:var(--primary-600);
                            display:flex;align-items:center;justify-content:center;font-size:1.1rem;flex-shrink:0;">
                    <i class="fas fa-user-shield"></i>
                </div>
                <div>
                    <h3 style="margin:0;font-size:1rem;font-weight:700;color:var(--neutral-800);">Role Management</h3>
                    <?php if ($pending_roles > 0): ?>
                        <span class="badge badge-warning" style="font-size:0.72rem;"><?= $pending_roles ?> pending</span>
                    <?php else: ?>
                        <span class="badge badge-success" style="font-size:0.72rem;">All clear</span>
                    <?php endif; ?>
                </div>
            </div>
            <p style="font-size:0.875rem;color:var(--neutral-500);margin:0;flex:1;">
                Approve or reject users applying for system roles. Manage access control for all staff accounts.
            </p>
            <a href="<?= BASE_URL ?>dashboard/admin/approve_roles.php"
               class="btn btn-primary btn-sm" style="align-self:stretch;text-align:center;">
                <i class="fas fa-user-check"></i> View Pending Approvals
                <?= $pending_roles > 0 ? '<span class="badge badge-light" style="margin-left:6px;">'.$pending_roles.'</span>' : '' ?>
            </a>
        </div>
    </div>

    <!-- Authorization (RBAC) -->
    <div class="card" style="display:flex;flex-direction:column;border-top:3px solid #8b5cf6;">
        <div class="card-body" style="flex:1;display:flex;flex-direction:column;gap:12px;">
            <div style="display:flex;align-items:center;gap:12px;">
                <div style="width:44px;height:44px;border-radius:10px;background:#ede9fe;color:#7c3aed;
                            display:flex;align-items:center;justify-content:center;font-size:1.1rem;flex-shrink:0;">
                    <i class="fas fa-key"></i>
                </div>
                <div>
                    <h3 style="margin:0;font-size:1rem;font-weight:700;color:var(--neutral-800);">Authorization (RBAC)</h3>
                    <span style="font-size:0.75rem;color:var(--neutral-400);">Role-Based Access Control</span>
                </div>
            </div>
            <p style="font-size:0.875rem;color:var(--neutral-500);margin:0;flex:1;">
                View the RBAC permission matrix and verify which roles are authorized for each system endpoint.
            </p>
            <a href="<?= BASE_URL ?>dashboard/admin/authorization.php"
               class="btn btn-outline btn-sm"
               style="align-self:stretch;text-align:center;border-color:#8b5cf6;color:#7c3aed;">
                <i class="fas fa-table"></i> View RBAC Matrix
            </a>
        </div>
    </div>

    <!-- Secure Data Storage -->
    <div class="card" style="display:flex;flex-direction:column;border-top:3px solid #0ea5e9;">
        <div class="card-body" style="flex:1;display:flex;flex-direction:column;gap:12px;">
            <div style="display:flex;align-items:center;gap:12px;">
                <div style="width:44px;height:44px;border-radius:10px;background:#e0f2fe;color:#0284c7;
                            display:flex;align-items:center;justify-content:center;font-size:1.1rem;flex-shrink:0;">
                    <i class="fas fa-lock"></i>
                </div>
                <div>
                    <h3 style="margin:0;font-size:1rem;font-weight:700;color:var(--neutral-800);">Secure Data Storage</h3>
                    <span style="font-size:0.75rem;color:var(--neutral-400);">Encryption &amp; Hashing</span>
                </div>
            </div>
            <p style="font-size:0.875rem;color:var(--neutral-500);margin:0;flex:1;">
                Manage encrypted storage policies, password hashing standards, and key management settings.
            </p>
            <a href="<?= BASE_URL ?>dashboard/admin/secure_data.php"
               class="btn btn-outline btn-sm"
               style="align-self:stretch;text-align:center;border-color:#0ea5e9;color:#0284c7;">
                <i class="fas fa-database"></i> Manage Encryption
            </a>
        </div>
    </div>

    <!-- Logging & Monitoring -->
    <div class="card" style="display:flex;flex-direction:column;border-top:3px solid #f59e0b;">
        <div class="card-body" style="flex:1;display:flex;flex-direction:column;gap:12px;">
            <div style="display:flex;align-items:center;gap:12px;">
                <div style="width:44px;height:44px;border-radius:10px;background:#fffbeb;color:#d97706;
                            display:flex;align-items:center;justify-content:center;font-size:1.1rem;flex-shrink:0;">
                    <i class="fas fa-list-alt"></i>
                </div>
                <div>
                    <h3 style="margin:0;font-size:1rem;font-weight:700;color:var(--neutral-800);">Logging &amp; Monitoring</h3>
                    <?php if ($failed_logins > 0): ?>
                        <span class="badge badge-warning" style="font-size:0.72rem;">
                            <?= $failed_logins ?> failed login<?= $failed_logins > 1 ? 's' : '' ?> today
                        </span>
                    <?php else: ?>
                        <span style="font-size:0.75rem;color:var(--neutral-400);">Activity Audit</span>
                    <?php endif; ?>
                </div>
            </div>
            <p style="font-size:0.875rem;color:var(--neutral-500);margin:0;flex:1;">
                Audit system events, admin activity logs, and monitor failed login attempts in real time.
            </p>
            <a href="<?= BASE_URL ?>dashboard/admin/logging_monitoring.php"
               class="btn btn-outline btn-sm"
               style="align-self:stretch;text-align:center;border-color:#f59e0b;color:#d97706;">
                <i class="fas fa-search"></i> View System Logs
            </a>
        </div>
    </div>

    <!-- DLP Features -->
    <div class="card" style="display:flex;flex-direction:column;border-top:3px solid #10b981;">
        <div class="card-body" style="flex:1;display:flex;flex-direction:column;gap:12px;">
            <div style="display:flex;align-items:center;gap:12px;">
                <div style="width:44px;height:44px;border-radius:10px;background:#d1fae5;color:#059669;
                            display:flex;align-items:center;justify-content:center;font-size:1.1rem;flex-shrink:0;">
                    <i class="fas fa-user-secret"></i>
                </div>
                <div>
                    <h3 style="margin:0;font-size:1rem;font-weight:700;color:var(--neutral-800);">DLP Features</h3>
                    <span style="font-size:0.75rem;color:var(--neutral-400);">Data Loss Prevention</span>
                </div>
            </div>
            <p style="font-size:0.875rem;color:var(--neutral-500);margin:0;flex:1;">
                Configure session timeouts, data classification tags, and policies to prevent unauthorized data loss.
            </p>
            <a href="<?= BASE_URL ?>dashboard/admin/dlp_features.php"
               class="btn btn-outline btn-sm"
               style="align-self:stretch;text-align:center;border-color:#10b981;color:#059669;">
                <i class="fas fa-sliders-h"></i> Configure DLP
            </a>
        </div>
    </div>

</div>

<?php require_once __DIR__ . '/../../includes/footer.php'; ?>
