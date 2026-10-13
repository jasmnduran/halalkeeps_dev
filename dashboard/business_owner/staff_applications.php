<?php
require_once __DIR__ . '/../../config/app.php';
requireLogin();
requireRole([ROLE_BUSINESS_OWNER]);
requireStaffAccess('Staff Applications');

$page_title   = 'Staff Applications';
$page_heading = 'Staff Applications';
$is_dashboard = true;
$breadcrumbs  = [
    ['label' => 'Owner Dashboard', 'url' => BASE_URL . 'dashboard/business_owner/owner_dashboard.php'],
    ['label' => 'Staff Applications'],
];

$user_id = (int)$_SESSION['user_id'];
$rest_id = getSessionRestaurantId($conn);

if (!$rest_id || !isRestaurantOwner($conn, $user_id, $rest_id)) {
    header('Location: ' . BASE_URL . 'dashboard/business_owner/owner_dashboard.php');
    exit();
}

// Flash messages
$flash_success = $_SESSION['staff_success'] ?? null;
$flash_error   = $_SESSION['staff_error']   ?? null;
unset($_SESSION['staff_success'], $_SESSION['staff_error']);

$active_tab = in_array($_GET['tab'] ?? '', ['pending', 'all', 'staff'], true)
    ? $_GET['tab'] : 'pending';

// ── Data ──────────────────────────────────────────────────────────────────────
$pending_apps = getVisiblePendingApplications($conn, $user_id, $rest_id);
$active_staff = getActiveStaffAssignments($conn, $rest_id);

// All reviewed applications (approved + rejected)
$reviewed_stmt = $conn->prepare("
    SELECT sa.*,
           u.full_name, u.email,
           rsr.role_label,
           rev.full_name AS reviewer_name
    FROM   staff_applications sa
    JOIN   users u ON sa.user_id = u.id
    LEFT JOIN restaurant_staff_roles rsr ON rsr.role_key = sa.applied_role
    LEFT JOIN users rev ON sa.reviewed_by = rev.id
    WHERE  sa.restaurant_id = ?
      AND  sa.status IN ('approved','rejected')
    ORDER  BY sa.reviewed_at DESC
    LIMIT  100
");
$reviewed_stmt->bind_param("i", $rest_id);
$reviewed_stmt->execute();
$reviewed_apps = $reviewed_stmt->get_result()->fetch_all(MYSQLI_ASSOC);

// Counts used in the hero stats section
$pending_count  = count($pending_apps);
$active_count   = count($active_staff);
$reviewed_count = count($reviewed_apps);

$role_labels = [
    'ops_manager' => 'Operations Manager',
    'cashier'     => 'Cashier',
    'chef'        => 'Chef / Head Cook',
];

require_once __DIR__ . '/../../includes/header.php';
?>

<!-- ══ PAGE HERO ═══════════════════════════════════════════════════════════ -->
<div style="background:linear-gradient(135deg,#064e3b 0%,#065f46 55%,#047857 100%);
            border-radius:var(--radius-xl);padding:24px 32px;margin-bottom:24px;
            position:relative;overflow:hidden;">
    <div style="position:absolute;right:-40px;top:-40px;width:180px;height:180px;border-radius:50%;background:rgba(255,255,255,.05);pointer-events:none;"></div>
    <div style="position:relative;display:flex;align-items:center;justify-content:space-between;flex-wrap:wrap;gap:12px;">
        <div>
            <div style="font-size:.72rem;font-weight:700;letter-spacing:.08em;text-transform:uppercase;color:#6ee7b7;margin-bottom:6px;">
                <i class="fas fa-inbox" style="margin-right:5px;"></i>Staff Management
            </div>
            <h1 style="font-family:var(--font-display);font-size:1.4rem;font-weight:800;color:#fff;margin:0 0 4px;">
                Staff Applications
            </h1>
            <p style="color:rgba(255,255,255,.7);font-size:.85rem;margin:0;">
                Review applicants, assign roles, and manage your active team.
            </p>
        </div>
        <div style="display:flex;gap:20px;flex-wrap:wrap;">
            <div style="text-align:center;">
                <div style="font-size:1.6rem;font-weight:800;color:#fff;"><?= $pending_count ?></div>
                <div style="font-size:.72rem;color:#a7f3d0;font-weight:600;">Pending</div>
            </div>
            <div style="text-align:center;">
                <div style="font-size:1.6rem;font-weight:800;color:#fff;"><?= $active_count ?></div>
                <div style="font-size:.72rem;color:#a7f3d0;font-weight:600;">Active Staff</div>
            </div>
            <div style="text-align:center;">
                <div style="font-size:1.6rem;font-weight:800;color:#fff;"><?= $reviewed_count ?></div>
                <div style="font-size:.72rem;color:#a7f3d0;font-weight:600;">Reviewed</div>
            </div>
        </div>
    </div>
</div>

<style>
.sa-stats{display:grid;grid-template-columns:repeat(auto-fit,minmax(160px,1fr));gap:16px;margin-bottom:28px}
.sa-stat{background:#fff;border:1px solid #e2e8f0;border-radius:12px;padding:20px;text-align:center;box-shadow:0 1px 3px rgba(0,0,0,.05)}
.sa-stat .num{font-size:2rem;font-weight:800;color:#4338ca}
.sa-stat .lbl{font-size:.85rem;color:#64748b;font-weight:600;margin-top:4px}
.app-card{background:#fff;border:1px solid #e2e8f0;border-radius:12px;padding:20px;margin-bottom:14px;display:grid;grid-template-columns:1fr auto;gap:20px;align-items:start;box-shadow:0 1px 3px rgba(0,0,0,.04);transition:box-shadow .2s}
.app-card:hover{box-shadow:0 3px 10px rgba(0,0,0,.08)}
.app-card h3{margin:0 0 4px;font-size:1rem;font-weight:700;color:#1e293b}
.app-meta{font-size:.83rem;color:#64748b;margin:3px 0}
.app-cover{font-size:.88rem;color:#475569;margin-top:10px;padding:10px 12px;background:#f8fafc;border-left:3px solid #6366f1;border-radius:0 6px 6px 0}
.btn-approve{background:#10b981;color:#fff;border:none;padding:9px 16px;border-radius:8px;font-weight:700;cursor:pointer;display:inline-flex;align-items:center;gap:6px;font-size:.85rem;transition:background .2s}
.btn-approve:hover{background:#059669}
.btn-reject{background:#ef4444;color:#fff;border:none;padding:9px 16px;border-radius:8px;font-weight:700;cursor:pointer;display:inline-flex;align-items:center;gap:6px;font-size:.85rem;transition:background .2s}
.btn-reject:hover{background:#dc2626}
.btn-deact{background:#f1f5f9;color:#475569;border:1px solid #e2e8f0;padding:7px 12px;border-radius:8px;font-weight:600;cursor:pointer;font-size:.82rem;transition:all .2s}
.btn-deact:hover{background:#fee2e2;color:#dc2626;border-color:#fca5a5}
.badge-pending{background:#fef9c3;color:#854d0e;padding:4px 10px;border-radius:20px;font-size:.75rem;font-weight:700}
.badge-approved{background:#dcfce7;color:#166534;padding:4px 10px;border-radius:20px;font-size:.75rem;font-weight:700}
.badge-rejected{background:#fee2e2;color:#991b1b;padding:4px 10px;border-radius:20px;font-size:.75rem;font-weight:700}
.empty-box{text-align:center;padding:52px 20px;background:#f8fafc;border:1px dashed #cbd5e1;border-radius:12px}
.tab-bar{display:flex;gap:4px;border-bottom:2px solid #e2e8f0;margin-bottom:24px}
.tab-link{padding:10px 18px;font-weight:600;font-size:.88rem;color:#64748b;border-bottom:3px solid transparent;margin-bottom:-2px;text-decoration:none;border-radius:6px 6px 0 0;transition:all .15s}
.tab-link:hover{color:#4338ca;background:#eef2ff}
.tab-link.active{color:#4338ca;border-bottom-color:#6366f1;background:#eef2ff}
/* modal */
.modal-bg{display:none;position:fixed;inset:0;background:rgba(0,0,0,.45);z-index:9998;align-items:center;justify-content:center}
.modal-bg.open{display:flex}
.modal-box{background:#fff;border-radius:14px;padding:32px;max-width:480px;width:90%;box-shadow:0 10px 40px rgba(0,0,0,.2)}
.modal-box h2{margin:0 0 16px;font-size:1.3rem;font-weight:700}
.form-grp{margin-bottom:14px}
.form-grp label{display:block;margin-bottom:5px;font-weight:600;font-size:.88rem}
.form-grp textarea{width:100%;padding:9px;border:1px solid #e2e8f0;border-radius:6px;resize:vertical;min-height:80px;font-family:inherit;font-size:.88rem}
.btn-row{display:flex;gap:8px;margin-top:20px}
.btn-cancel{background:#f1f5f9;color:#475569;border:1px solid #e2e8f0;padding:10px 20px;border-radius:8px;font-weight:600;cursor:pointer}
</style>

<?php if ($flash_success): ?>
<div style="background:#dcfce7;color:#166534;border:1px solid #bbf7d0;padding:12px 16px;border-radius:8px;margin-bottom:20px;">
    <i class="fas fa-check-circle"></i> <?= htmlspecialchars($flash_success) ?>
</div>
<?php endif; ?>
<?php if ($flash_error): ?>
<div style="background:#fee2e2;color:#991b1b;border:1px solid #fca5a5;padding:12px 16px;border-radius:8px;margin-bottom:20px;">
    <i class="fas fa-exclamation-circle"></i> <?= htmlspecialchars($flash_error) ?>
</div>
<?php endif; ?>

<!-- Stats -->
<div class="sa-stats">
    <div class="sa-stat"><div class="num"><?= count($pending_apps) ?></div><div class="lbl">Pending</div></div>
    <div class="sa-stat"><div class="num"><?= count($active_staff) ?></div><div class="lbl">Active Staff</div></div>
    <div class="sa-stat"><div class="num"><?= count($reviewed_apps) ?></div><div class="lbl">Reviewed</div></div>
</div>

<!-- Tab bar -->
<div class="tab-bar">
    <a href="?tab=pending"  class="tab-link <?= $active_tab === 'pending'  ? 'active' : '' ?>"><i class="fas fa-hourglass-half"></i> Pending (<?= count($pending_apps) ?>)</a>
    <a href="?tab=staff"    class="tab-link <?= $active_tab === 'staff'    ? 'active' : '' ?>"><i class="fas fa-users"></i> Active Staff (<?= count($active_staff) ?>)</a>
    <a href="?tab=all"      class="tab-link <?= $active_tab === 'all'      ? 'active' : '' ?>"><i class="fas fa-history"></i> Reviewed</a>
</div>

<!-- ── TAB: PENDING ───────────────────────────────────────────────────────── -->
<?php if ($active_tab === 'pending'): ?>
    <?php if (empty($pending_apps)): ?>
        <div class="empty-box">
            <i class="fas fa-inbox" style="font-size:2.5rem;color:#cbd5e1;margin-bottom:12px"></i>
            <h3 style="color:#64748b;margin:0">No pending applications</h3>
            <p style="color:#94a3b8;margin:6px 0 0">New applications will appear here for review.</p>
        </div>
    <?php else: ?>
        <?php foreach ($pending_apps as $app): ?>
        <div class="app-card">
            <div>
                <h3><?= htmlspecialchars($app['full_name']) ?></h3>
                <div class="app-meta"><i class="fas fa-envelope" style="width:14px"></i> <?= htmlspecialchars($app['email']) ?></div>
                <?php if (!empty($app['phone'])): ?>
                <div class="app-meta"><i class="fas fa-phone" style="width:14px"></i> <?= htmlspecialchars($app['phone']) ?></div>
                <?php endif; ?>
                <div class="app-meta">
                    <i class="fas fa-briefcase" style="width:14px"></i>
                    <strong><?= htmlspecialchars($app['role_label'] ?? $role_labels[$app['applied_role']] ?? $app['applied_role']) ?></strong>
                    &nbsp;·&nbsp;
                    <span><?= $app['application_type'] === 'existing' ? 'Existing Employee' : 'New Applicant' ?></span>
                    &nbsp;·&nbsp;
                    <span><?= date('M d, Y', strtotime($app['created_at'])) ?></span>
                </div>
                <?php if (!empty($app['cover_letter_text'])): ?>
                <div class="app-cover">
                    <strong>Cover Letter:</strong><br>
                    <?= nl2br(htmlspecialchars(mb_substr($app['cover_letter_text'], 0, 200))) ?><?= mb_strlen($app['cover_letter_text']) > 200 ? '…' : '' ?>
                </div>
                <?php endif; ?>
                <?php
                $docs = array_filter([
                    'Cover Letter' => $app['file_cover_letter'] ?? null,
                    'Resume'       => $app['file_resume']       ?? null,
                    'NBI'          => $app['file_nbi']          ?? null,
                    'Medical Cert' => $app['file_medical']      ?? null,
                    'Gov ID'       => $app['file_gov_id']       ?? null,
                    'Proof of Employment' => $app['file_proof_employ'] ?? null,
                ]);
                if ($docs): ?>
                <div style="margin-top:10px;display:flex;flex-wrap:wrap;gap:6px">
                    <?php foreach ($docs as $label => $path): ?>
                    <a href="<?= BASE_URL . 'uploads/' . htmlspecialchars($path) ?>" target="_blank"
                       style="background:#eef2ff;color:#4338ca;padding:4px 10px;border-radius:6px;font-size:.78rem;font-weight:600;text-decoration:none">
                        <i class="fas fa-file"></i> <?= htmlspecialchars($label) ?>
                    </a>
                    <?php endforeach; ?>
                </div>
                <?php endif; ?>
            </div>
            <div style="display:flex;flex-direction:column;gap:8px;min-width:110px">
                <button class="btn-approve" onclick="openApprove(<?= $app['id'] ?>, '<?= htmlspecialchars(addslashes($app['full_name'])) ?>')">
                    <i class="fas fa-check"></i> Approve
                </button>
                <button class="btn-reject" onclick="openReject(<?= $app['id'] ?>, '<?= htmlspecialchars(addslashes($app['full_name'])) ?>')">
                    <i class="fas fa-times"></i> Reject
                </button>
            </div>
        </div>
        <?php endforeach; ?>
    <?php endif; ?>

<!-- ── TAB: ACTIVE STAFF ─────────────────────────────────────────────────── -->
<?php elseif ($active_tab === 'staff'): ?>
    <?php if (empty($active_staff)): ?>
        <div class="empty-box">
            <i class="fas fa-users-slash" style="font-size:2.5rem;color:#cbd5e1;margin-bottom:12px"></i>
            <h3 style="color:#64748b;margin:0">No active staff members</h3>
            <p style="color:#94a3b8;margin:6px 0 0">Approve a pending application to assign a staff role.</p>
        </div>
    <?php else: ?>
    <div style="overflow-x:auto">
        <table style="width:100%;border-collapse:collapse;background:#fff;border-radius:12px;overflow:hidden;box-shadow:0 1px 3px rgba(0,0,0,.05)">
            <thead>
                <tr style="background:#f8fafc;font-size:.8rem;text-transform:uppercase;color:#64748b">
                    <th style="padding:12px 16px;text-align:left;font-weight:700;border-bottom:2px solid #e2e8f0">Name</th>
                    <th style="padding:12px 16px;text-align:left;font-weight:700;border-bottom:2px solid #e2e8f0">Role</th>
                    <th style="padding:12px 16px;text-align:left;font-weight:700;border-bottom:2px solid #e2e8f0">Contact</th>
                    <th style="padding:12px 16px;text-align:left;font-weight:700;border-bottom:2px solid #e2e8f0">Assigned</th>
                    <th style="padding:12px 16px;text-align:center;font-weight:700;border-bottom:2px solid #e2e8f0">Actions</th>
                </tr>
            </thead>
            <tbody>
                <?php foreach ($active_staff as $s): ?>
                <tr style="border-bottom:1px solid #f1f5f9">
                    <td style="padding:12px 16px;font-weight:600;color:#1e293b"><?= htmlspecialchars($s['full_name']) ?></td>
                    <td style="padding:12px 16px">
                        <span style="background:#eef2ff;color:#4338ca;padding:4px 10px;border-radius:20px;font-size:.8rem;font-weight:700">
                            <?= htmlspecialchars($s['role_label']) ?>
                        </span>
                    </td>
                    <td style="padding:12px 16px;font-size:.83rem;color:#475569">
                        <div><?= htmlspecialchars($s['email']) ?></div>
                        <?php if (!empty($s['phone'])): ?>
                        <div><?= htmlspecialchars($s['phone']) ?></div>
                        <?php endif; ?>
                    </td>
                    <td style="padding:12px 16px;font-size:.83rem;color:#64748b">
                        <?= date('M d, Y', strtotime($s['assigned_at'])) ?>
                    </td>
                    <td style="padding:12px 16px;text-align:center">
                        <button class="btn-deact" onclick="deactivateStaff(<?= $s['id'] ?>, '<?= htmlspecialchars(addslashes($s['full_name'])) ?>')">
                            <i class="fas fa-ban"></i> Deactivate
                        </button>
                    </td>
                </tr>
                <?php endforeach; ?>
            </tbody>
        </table>
    </div>
    <?php endif; ?>

<!-- ── TAB: REVIEWED ─────────────────────────────────────────────────────── -->
<?php elseif ($active_tab === 'all'): ?>
    <?php if (empty($reviewed_apps)): ?>
        <div class="empty-box">
            <i class="fas fa-clipboard-check" style="font-size:2.5rem;color:#cbd5e1;margin-bottom:12px"></i>
            <h3 style="color:#64748b;margin:0">No reviewed applications yet</h3>
        </div>
    <?php else: ?>
    <div style="overflow-x:auto">
        <table style="width:100%;border-collapse:collapse;background:#fff;border-radius:12px;overflow:hidden;box-shadow:0 1px 3px rgba(0,0,0,.05)">
            <thead>
                <tr style="background:#f8fafc;font-size:.8rem;text-transform:uppercase;color:#64748b">
                    <th style="padding:12px 16px;text-align:left;font-weight:700;border-bottom:2px solid #e2e8f0">Applicant</th>
                    <th style="padding:12px 16px;text-align:left;font-weight:700;border-bottom:2px solid #e2e8f0">Role</th>
                    <th style="padding:12px 16px;text-align:left;font-weight:700;border-bottom:2px solid #e2e8f0">Status</th>
                    <th style="padding:12px 16px;text-align:left;font-weight:700;border-bottom:2px solid #e2e8f0">Reviewed By</th>
                    <th style="padding:12px 16px;text-align:left;font-weight:700;border-bottom:2px solid #e2e8f0">Date</th>
                    <th style="padding:12px 16px;text-align:left;font-weight:700;border-bottom:2px solid #e2e8f0">Notes</th>
                </tr>
            </thead>
            <tbody>
                <?php foreach ($reviewed_apps as $r): ?>
                <tr style="border-bottom:1px solid #f1f5f9">
                    <td style="padding:12px 16px">
                        <div style="font-weight:600;color:#1e293b"><?= htmlspecialchars($r['full_name']) ?></div>
                        <div style="font-size:.8rem;color:#64748b"><?= htmlspecialchars($r['email']) ?></div>
                    </td>
                    <td style="padding:12px 16px;font-size:.85rem"><?= htmlspecialchars($r['role_label'] ?? $role_labels[$r['applied_role']] ?? $r['applied_role']) ?></td>
                    <td style="padding:12px 16px">
                        <span class="badge-<?= $r['status'] ?>">
                            <?= ucfirst($r['status']) ?>
                        </span>
                    </td>
                    <td style="padding:12px 16px;font-size:.83rem;color:#64748b"><?= htmlspecialchars($r['reviewer_name'] ?? '—') ?></td>
                    <td style="padding:12px 16px;font-size:.83rem;color:#64748b"><?= $r['reviewed_at'] ? date('M d, Y', strtotime($r['reviewed_at'])) : '—' ?></td>
                    <td style="padding:12px 16px;font-size:.82rem;color:#475569;max-width:200px"><?= htmlspecialchars($r['owner_notes'] ?? '') ?></td>
                </tr>
                <?php endforeach; ?>
            </tbody>
        </table>
    </div>
    <?php endif; ?>
<?php endif; ?>

<!-- ── APPROVE MODAL ─────────────────────────────────────────────────────── -->
<div id="approveModal" class="modal-bg">
    <div class="modal-box">
        <h2><i class="fas fa-check-circle" style="color:#10b981"></i> Approve Application</h2>
        <p style="color:#475569;margin:0 0 16px">Approve <strong id="approveName"></strong> for this staff position?</p>
        <div class="form-grp">
            <label>Notes (optional)</label>
            <textarea id="approveNotes" placeholder="Any notes for the applicant..."></textarea>
        </div>
        <div class="btn-row">
            <button class="btn-cancel" onclick="closeModal('approveModal')">Cancel</button>
            <button class="btn-approve" onclick="submitAction('approved')"><i class="fas fa-check"></i> Confirm Approval</button>
        </div>
    </div>
</div>

<!-- ── REJECT MODAL ──────────────────────────────────────────────────────── -->
<div id="rejectModal" class="modal-bg">
    <div class="modal-box">
        <h2><i class="fas fa-times-circle" style="color:#ef4444"></i> Reject Application</h2>
        <p style="color:#475569;margin:0 0 16px">Reject <strong id="rejectName"></strong>'s application?</p>
        <div class="form-grp">
            <label>Reason for rejection (optional)</label>
            <textarea id="rejectNotes" placeholder="Brief explanation for the applicant..."></textarea>
        </div>
        <div class="btn-row">
            <button class="btn-cancel" onclick="closeModal('rejectModal')">Cancel</button>
            <button class="btn-reject" onclick="submitAction('rejected')"><i class="fas fa-times"></i> Confirm Rejection</button>
        </div>
    </div>
</div>

<input type="hidden" id="currentAppId" value="">

<script>
function openApprove(appId, name) {
    document.getElementById('currentAppId').value = appId;
    document.getElementById('approveName').textContent = name;
    document.getElementById('approveNotes').value = '';
    document.getElementById('approveModal').classList.add('open');
}
function openReject(appId, name) {
    document.getElementById('currentAppId').value = appId;
    document.getElementById('rejectName').textContent = name;
    document.getElementById('rejectNotes').value = '';
    document.getElementById('rejectModal').classList.add('open');
}
function closeModal(id) { document.getElementById(id).classList.remove('open'); }

function submitAction(action) {
    const appId = document.getElementById('currentAppId').value;
    const notes = action === 'approved'
        ? document.getElementById('approveNotes').value
        : document.getElementById('rejectNotes').value;

    const fd = new FormData();
    fd.append('app_id',      appId);
    fd.append('action',      action);
    fd.append('owner_notes', notes);

    fetch('<?= BASE_URL ?>dashboard/business_owner/api/staff_app_action.php', {
        method: 'POST', body: fd
    })
    .then(r => r.json())
    .then(d => {
        if (d.success) { location.reload(); }
        else { alert('Error: ' + d.message); }
    })
    .catch(() => alert('Request failed. Please try again.'));
}

function deactivateStaff(assignmentId, name) {
    if (!confirm('Deactivate ' + name + '? They will lose access to their staff role.')) return;
    const fd = new FormData();
    fd.append('action',        'deactivate');
    fd.append('assignment_id', assignmentId);
    fetch('<?= BASE_URL ?>dashboard/business_owner/api/staff_management_handler.php', {
        method: 'POST', body: fd
    })
    .then(r => r.json())
    .then(d => {
        if (d.success) location.reload();
        else alert('Error: ' + d.message);
    })
    .catch(() => alert('Request failed.'));
}

// Close modals on backdrop click
document.querySelectorAll('.modal-bg').forEach(m => {
    m.addEventListener('click', e => { if (e.target === m) m.classList.remove('open'); });
});
</script>

<?php require_once __DIR__ . '/../../includes/footer.php'; ?>
