<?php
require_once __DIR__ . '/../../config/app.php';
requireLogin();
requireRole([ROLE_BUSINESS_OWNER]);
requireStaffAccess('Staff Roles');

$page_title   = 'Staff Roles';
$page_heading = 'Staff Roles';
$is_dashboard = true;
$breadcrumbs  = [
    ['label' => 'Owner Dashboard', 'url' => BASE_URL . 'dashboard/business_owner/owner_dashboard.php'],
    ['label' => 'Staff Roles'],
];

$user_id = (int)$_SESSION['user_id'];
$rest_id = getSessionRestaurantId($conn);

if (!$rest_id || !isRestaurantOwner($conn, $user_id, $rest_id)) {
    header('Location: ' . BASE_URL . 'dashboard/business_owner/owner_dashboard.php');
    exit();
}

// Flash messages
$flash_success = $_SESSION['sr_success'] ?? null;
$flash_error   = $_SESSION['sr_error']   ?? null;
unset($_SESSION['sr_success'], $_SESSION['sr_error']);

// Load platform roles + this restaurant's custom roles
$roles = getActiveBusinessRoles($conn, $rest_id);

// Count active staff per role for this restaurant
$count_stmt = $conn->prepare("
    SELECT rsr.id, COUNT(rs.id) AS active_count
    FROM   restaurant_staff_roles rsr
    LEFT JOIN restaurant_staff rs
           ON rs.staff_role_id = rsr.id
          AND rs.restaurant_id = ?
          AND rs.is_active = 1
    GROUP  BY rsr.id
");
$count_stmt->bind_param("i", $rest_id);
$count_stmt->execute();
$counts_raw    = $count_stmt->get_result()->fetch_all(MYSQLI_ASSOC);
$active_counts = array_column($counts_raw, 'active_count', 'id');

// Count existing custom roles for the limit badge
$custom_count = count(array_filter($roles, fn($r) => (int)($r['is_custom'] ?? 0) === 1));

require_once __DIR__ . '/../../includes/header.php';
?>

<style>
/* ── Grid & cards ─────────────────────────────────────── */
.sr-grid {
    display: grid;
    grid-template-columns: repeat(auto-fill, minmax(300px, 1fr));
    gap: 20px;
    margin-top: 8px;
}
.role-card {
    background: #fff;
    border: 1px solid #e2e8f0;
    border-radius: 14px;
    padding: 24px;
    box-shadow: 0 1px 4px rgba(0,0,0,.05);
    transition: box-shadow .2s;
    display: flex;
    flex-direction: column;
}
.role-card:hover { box-shadow: 0 4px 14px rgba(0,0,0,.09); }
.role-card.custom-card { border-color: #c7d2fe; }

.role-icon {
    width: 48px; height: 48px;
    border-radius: 12px;
    display: flex; align-items: center; justify-content: center;
    font-size: 1.3rem;
    margin-bottom: 14px;
    flex-shrink: 0;
}
.role-card h3  { margin: 0 0 6px; font-size: 1.05rem; font-weight: 700; color: #1e293b; }
.role-card p   { margin: 0 0 14px; font-size: .85rem; color: #64748b; line-height: 1.5; flex: 1; }
.role-meta     { font-size: .8rem; color: #94a3b8; margin-bottom: 16px; }
.count-badge   { display: inline-block; background: #eef2ff; color: #4338ca; padding: 3px 10px; border-radius: 20px; font-weight: 700; font-size: .8rem; }
.custom-badge  { display: inline-block; background: #f5f3ff; color: #7c3aed; padding: 2px 8px; border-radius: 20px; font-weight: 700; font-size: .72rem; margin-left: 6px; }

.card-actions  { display: flex; gap: 8px; flex-wrap: wrap; margin-top: auto; }
.btn-edit {
    background: #f1f5f9; color: #334155;
    border: 1px solid #e2e8f0;
    padding: 8px 16px; border-radius: 8px;
    font-weight: 600; font-size: .85rem;
    cursor: pointer; transition: all .15s;
}
.btn-edit:hover { background: #e0e7ff; color: #4338ca; border-color: #c7d2fe; }
.btn-delete {
    background: #fff1f2; color: #be123c;
    border: 1px solid #fecdd3;
    padding: 8px 14px; border-radius: 8px;
    font-weight: 600; font-size: .85rem;
    cursor: pointer; transition: all .15s;
}
.btn-delete:hover { background: #ffe4e6; border-color: #fda4af; }

/* Add role card */
.add-role-card {
    background: #f8fafc;
    border: 2px dashed #c7d2fe;
    border-radius: 14px;
    padding: 24px;
    display: flex;
    flex-direction: column;
    align-items: center;
    justify-content: center;
    gap: 12px;
    cursor: pointer;
    transition: all .2s;
    min-height: 180px;
    color: #6366f1;
    text-align: center;
}
.add-role-card:hover { background: #eef2ff; border-color: #6366f1; }
.add-role-card .add-icon {
    width: 52px; height: 52px;
    border-radius: 14px;
    background: #eef2ff;
    display: flex; align-items: center; justify-content: center;
    font-size: 1.4rem;
    color: #6366f1;
}
.add-role-card span { font-weight: 700; font-size: .95rem; }
.add-role-card small { font-size: .8rem; color: #94a3b8; }

/* ── Modals ───────────────────────────────────────────── */
.modal-bg {
    display: none; position: fixed; inset: 0;
    background: rgba(0,0,0,.45); z-index: 9998;
    align-items: center; justify-content: center;
}
.modal-bg.open { display: flex; }
.modal-box {
    background: #fff; border-radius: 14px;
    padding: 32px; max-width: 500px; width: 90%;
    box-shadow: 0 10px 40px rgba(0,0,0,.2);
    animation: modalIn .18s ease;
}
@keyframes modalIn { from { transform: translateY(-12px); opacity: 0; } to { transform: none; opacity: 1; } }
.modal-box h2  { margin: 0 0 6px; font-size: 1.25rem; font-weight: 700; }
.modal-box .role-key-badge {
    display: inline-block; background: #f1f5f9; color: #475569;
    padding: 3px 10px; border-radius: 6px;
    font-size: .78rem; font-weight: 700; font-family: monospace;
    margin-bottom: 18px;
}
.form-grp { margin-bottom: 16px; }
.form-grp label { display: block; margin-bottom: 5px; font-weight: 600; font-size: .88rem; color: #334155; }
.form-grp input,
.form-grp textarea {
    width: 100%; padding: 9px 11px;
    border: 1px solid #e2e8f0; border-radius: 8px;
    font-size: .9rem; font-family: inherit;
    box-sizing: border-box;
    transition: border-color .15s;
}
.form-grp input:focus,
.form-grp textarea:focus { outline: none; border-color: #6366f1; box-shadow: 0 0 0 3px rgba(99,102,241,.12); }
.form-grp textarea { resize: vertical; min-height: 80px; }
.hint { font-size: .78rem; color: #94a3b8; margin-top: 4px; }
.btn-row { display: flex; gap: 10px; margin-top: 22px; justify-content: flex-end; }
.btn-save {
    background: #6366f1; color: #fff; border: none;
    padding: 10px 24px; border-radius: 8px;
    font-weight: 700; cursor: pointer; font-size: .9rem;
    transition: background .15s;
}
.btn-save:hover   { background: #4f46e5; }
.btn-save:disabled { opacity: .65; cursor: not-allowed; }
.btn-cancel {
    background: #f1f5f9; color: #475569;
    border: 1px solid #e2e8f0;
    padding: 10px 20px; border-radius: 8px;
    font-weight: 600; cursor: pointer;
}

/* Inline toast */
#srToast {
    display: none;
    padding: 12px 18px; border-radius: 8px;
    font-size: .88rem; font-weight: 600;
    margin-bottom: 20px;
}
</style>

<!-- ══ PAGE HERO ═══════════════════════════════════════════════════════════ -->
<div style="background:linear-gradient(135deg,#4f46e5 0%,#6366f1 55%,#7c3aed 100%);
            border-radius:var(--radius-xl);padding:24px 32px;margin-bottom:24px;
            position:relative;overflow:hidden;">
    <div style="position:absolute;right:-40px;top:-40px;width:180px;height:180px;border-radius:50%;background:rgba(255,255,255,.05);pointer-events:none;"></div>
    <div style="position:relative;display:flex;align-items:center;justify-content:space-between;flex-wrap:wrap;gap:12px;">
        <div>
            <div style="font-size:.72rem;font-weight:700;letter-spacing:.08em;text-transform:uppercase;color:#c4b5fd;margin-bottom:6px;">
                <i class="fas fa-id-badge" style="margin-right:5px;"></i>Access Control
            </div>
            <h1 style="font-family:var(--font-display);font-size:1.4rem;font-weight:800;color:#fff;margin:0 0 4px;">
                Staff Roles
            </h1>
            <p style="color:rgba(255,255,255,.7);font-size:.85rem;margin:0;">
                Manage platform roles and create custom roles for your restaurant.
                <?php if ($custom_count > 0): ?><?= $custom_count ?>/10 custom roles used.<?php endif; ?>
            </p>
        </div>
        <button onclick="openCreate()"
                style="display:inline-flex;align-items:center;gap:8px;background:#fff;color:#4f46e5;
                       padding:9px 18px;border-radius:var(--radius-md);font-weight:700;font-size:.85rem;
                       border:none;cursor:pointer;transition:opacity .15s;white-space:nowrap;"
                onmouseover="this.style.opacity='.88'" onmouseout="this.style.opacity='1'">
            <i class="fas fa-plus"></i> Add Custom Role
        </button>
    </div>
</div>

<?php if ($flash_success): ?>
<div style="background:#dcfce7;color:#166534;border:1px solid #bbf7d0;padding:12px 16px;border-radius:8px;margin-bottom:20px">
    <i class="fas fa-check-circle"></i> <?= htmlspecialchars($flash_success) ?>
</div>
<?php endif; ?>
<?php if ($flash_error): ?>
<div style="background:#fee2e2;color:#991b1b;border:1px solid #fca5a5;padding:12px 16px;border-radius:8px;margin-bottom:20px">
    <i class="fas fa-exclamation-circle"></i> <?= htmlspecialchars($flash_error) ?>
</div>
<?php endif; ?>

<div id="srToast"></div>

<!-- Page header row -->
<div style="display:flex;align-items:flex-start;justify-content:space-between;gap:16px;flex-wrap:wrap;margin-bottom:24px;">
    <div>
        <p style="color:#64748b;margin:4px 0 0;font-size:.92rem;max-width:640px;">
            The four platform roles below are built in and cannot be removed, but you can
            customise their display names and descriptions. You can also create up to
            <strong>10 additional custom roles</strong> specific to your restaurant.
            To assign a person, approve their application from
            <a href="<?= BASE_URL ?>dashboard/business_owner/staff_applications.php"
               style="color:#6366f1;font-weight:600">Staff Applications</a>.
        </p>
    </div>
    <button class="btn-save" style="white-space:nowrap;flex-shrink:0;"
            onclick="openCreate()">
        <i class="fas fa-plus"></i> Add Custom Role
        <?php if ($custom_count > 0): ?>
        <span style="background:rgba(255,255,255,.25);border-radius:20px;
                     padding:1px 8px;font-size:.78rem;margin-left:6px;">
            <?= $custom_count ?>/10
        </span>
        <?php endif; ?>
    </button>
</div>

<?php
$role_styles = [
    'owner'       => ['icon' => 'fas fa-chart-line',   'bg' => '#ecfdf5', 'color' => '#059669'],
    'ops_manager' => ['icon' => 'fas fa-user-tie',      'bg' => '#eff6ff', 'color' => '#2563eb'],
    'cashier'     => ['icon' => 'fas fa-cash-register', 'bg' => '#fffbeb', 'color' => '#d97706'],
    'chef'        => ['icon' => 'fas fa-utensils',      'bg' => '#f5f3ff', 'color' => '#7c3aed'],
];
$custom_colors = ['#6366f1','#0891b2','#059669','#d97706','#be123c','#7c3aed','#0284c7','#15803d','#b45309','#64748b'];
?>

<div class="sr-grid">

<?php foreach ($roles as $role):
    $is_custom  = (int)($role['is_custom'] ?? 0) === 1;
    $count      = (int)($active_counts[$role['id']] ?? 0);

    if ($is_custom) {
        // Pick a consistent colour from the palette based on the role id
        $color = $custom_colors[$role['id'] % count($custom_colors)];
        $style = ['icon' => 'fas fa-user-cog', 'bg' => '#f5f3ff', 'color' => $color];
    } else {
        $style = $role_styles[$role['role_key']] ?? ['icon' => 'fas fa-user', 'bg' => '#f8fafc', 'color' => '#64748b'];
    }

    // JSON payload for JS – safe for inline onclick
    $js_payload = htmlspecialchars(json_encode([
        'id'          => $role['id'],
        'role_key'    => $role['role_key'],
        'role_label'  => $role['role_label'],
        'description' => $role['description'] ?? '',
        'is_custom'   => $is_custom ? 1 : 0,
    ]), ENT_QUOTES);
?>
    <div class="role-card<?= $is_custom ? ' custom-card' : '' ?>">
        <div class="role-icon" style="background:<?= $style['bg'] ?>;color:<?= $style['color'] ?>">
            <i class="<?= $style['icon'] ?>"></i>
        </div>

        <h3>
            <?= htmlspecialchars($role['role_label']) ?>
            <?php if ($is_custom): ?>
            <span class="custom-badge"><i class="fas fa-star" style="font-size:.65rem;margin-right:2px;"></i>Custom</span>
            <?php endif; ?>
        </h3>

        <p><?= htmlspecialchars($role['description'] ?? 'No description set.') ?></p>

        <div class="role-meta">
            Role key:
            <code style="background:#f1f5f9;padding:1px 6px;border-radius:4px;font-size:.78rem;">
                <?= htmlspecialchars($role['role_key']) ?>
            </code>
            &nbsp;&middot;&nbsp;
            <span class="count-badge"><?= $count ?> active</span>
        </div>

        <div class="card-actions">
            <?php if ($role['role_key'] !== 'owner'): ?>
            <button class="btn-edit" onclick='openEdit(<?= $js_payload ?>)'>
                <i class="fas fa-pen"></i> Edit Details
            </button>
            <button class="btn-delete" onclick='confirmDelete(<?= $js_payload ?>)'>
                <i class="fas fa-trash-alt"></i> Delete
            </button>
            <?php else: ?>
            <span style="font-size:.8rem;color:#94a3b8;font-style:italic;align-self:center;">
                Owner role &mdash; not assignable to staff
            </span>
            <?php endif; ?>
        </div>
    </div>
<?php endforeach; ?>

    <!-- Add custom role shortcut card -->
    <?php if ($custom_count < 10): ?>
    <div class="add-role-card" onclick="openCreate()" role="button" tabindex="0"
         onkeydown="if(event.key==='Enter'||event.key===' ')openCreate()">
        <div class="add-icon"><i class="fas fa-plus"></i></div>
        <span>Add Custom Role</span>
        <small><?= 10 - $custom_count ?> slot<?= (10 - $custom_count) !== 1 ? 's' : '' ?> remaining</small>
    </div>
    <?php endif; ?>

</div>

<!-- ══ EDIT MODAL ════════════════════════════════════════════════════════ -->
<div id="editModal" class="modal-bg sr-modal" role="dialog" aria-modal="true" aria-labelledby="editModalTitle">
    <div class="modal-box">
        <h2 id="editModalTitle">Edit Staff Role</h2>
        <span class="role-key-badge" id="editRoleKey"></span>
        <form id="editForm" onsubmit="submitEdit(event)">
            <input type="hidden" id="editRoleId" name="role_id">
            <div class="form-grp">
                <label for="editRoleLabel">Display Name <span style="color:#dc2626">*</span></label>
                <input type="text" id="editRoleLabel" name="role_label" maxlength="60" required
                       placeholder="e.g. Head Cashier">
                <div class="hint">Shown in dashboards, notifications, and reports (max 60 chars).</div>
            </div>
            <div class="form-grp">
                <label for="editDescription">Description</label>
                <textarea id="editDescription" name="description" maxlength="255"
                          placeholder="Brief summary of this role's responsibilities&hellip;"></textarea>
            </div>
            <div class="btn-row">
                <button type="button" class="btn-cancel" onclick="sr_closeModal('editModal')">Cancel</button>
                <button type="submit" class="btn-save" id="editSaveBtn">
                    <i class="fas fa-save"></i> Save Changes
                </button>
            </div>
        </form>
    </div>
</div>

<!-- ══ CREATE MODAL ══════════════════════════════════════════════════════ -->
<div id="createModal" class="modal-bg sr-modal" role="dialog" aria-modal="true" aria-labelledby="createModalTitle">
    <div class="modal-box">
        <h2 id="createModalTitle"><i class="fas fa-plus-circle" style="color:#6366f1;margin-right:8px;"></i>Add Custom Role</h2>
        <p style="font-size:.85rem;color:#64748b;margin:0 0 20px;">
            Custom roles are specific to your restaurant and can be assigned to staff
            members in addition to the four platform roles.
        </p>
        <form id="createForm" onsubmit="submitCreate(event)">
            <div class="form-grp">
                <label for="createRoleLabel">Display Name <span style="color:#dc2626">*</span></label>
                <input type="text" id="createRoleLabel" name="role_label" maxlength="60" required
                       placeholder="e.g. Prep Cook, Delivery Rider, Barista&hellip;">
                <div class="hint">Max 60 characters. A machine key will be generated automatically.</div>
            </div>
            <div class="form-grp">
                <label for="createDescription">Description</label>
                <textarea id="createDescription" name="description" maxlength="255"
                          placeholder="Brief summary of this role's responsibilities&hellip;"></textarea>
            </div>
            <div class="btn-row">
                <button type="button" class="btn-cancel" onclick="sr_closeModal('createModal')">Cancel</button>
                <button type="submit" class="btn-save" id="createSaveBtn">
                    <i class="fas fa-plus"></i> Create Role
                </button>
            </div>
        </form>
    </div>
</div>

<!-- ══ DELETE CONFIRM MODAL ══════════════════════════════════════════════ -->
<div id="deleteModal" class="modal-bg sr-modal" role="dialog" aria-modal="true" aria-labelledby="deleteModalTitle">
    <div class="modal-box" style="max-width:420px;">
        <h2 id="deleteModalTitle" style="color:#be123c;">
            <i class="fas fa-trash-alt" style="margin-right:8px;"></i>Delete Role
        </h2>
        <p id="deleteModalBody" style="font-size:.9rem;color:#475569;margin:12px 0 24px;line-height:1.6;"></p>
        <input type="hidden" id="deleteRoleId">
        <div class="btn-row">
            <button type="button" class="btn-cancel" onclick="sr_closeModal('deleteModal')">Cancel</button>
            <button type="button" id="deleteConfirmBtn"
                    style="background:#be123c;color:#fff;border:none;padding:10px 22px;
                           border-radius:8px;font-weight:700;cursor:pointer;font-size:.9rem;"
                    onclick="submitDelete()">
                <i class="fas fa-trash-alt"></i> Yes, Delete
            </button>
        </div>
    </div>
</div>

<script>
const HANDLER_URL = '<?= BASE_URL ?>dashboard/business_owner/api/staff_role_handler.php';

/* ── Toast ─────────────────────────────────────────────────────────── */
function showToast(msg, isError) {
    const t = document.getElementById('srToast');
    t.textContent = msg;
    t.style.display    = 'block';
    t.style.background = isError ? '#fee2e2' : '#dcfce7';
    t.style.color      = isError ? '#991b1b' : '#166534';
    t.style.border     = isError ? '1px solid #fca5a5' : '1px solid #bbf7d0';
    clearTimeout(t._t);
    t._t = setTimeout(() => { t.style.display = 'none'; }, 5000);
    t.scrollIntoView({ behavior: 'smooth', block: 'nearest' });
}

/* ── Modal helpers (prefixed sr_ to avoid collision with main.js) ─── */
function sr_openModal(id) {
    const el = document.getElementById(id);
    if (el) el.classList.add('open');
}
function sr_closeModal(id) {
    const el = document.getElementById(id);
    if (el) el.classList.remove('open');
}

// Close on backdrop click
document.querySelectorAll('.sr-modal').forEach(m => {
    m.addEventListener('click', e => { if (e.target === m) sr_closeModal(m.id); });
});
// Close on Escape key
document.addEventListener('keydown', e => {
    if (e.key === 'Escape') {
        document.querySelectorAll('.sr-modal.open').forEach(m => sr_closeModal(m.id));
    }
});

/* ── Edit modal ─────────────────────────────────────────────────────── */
function openEdit(role) {
    document.getElementById('editRoleId').value        = role.id;
    document.getElementById('editRoleKey').textContent = role.role_key;
    document.getElementById('editRoleLabel').value     = role.role_label;
    document.getElementById('editDescription').value   = role.description || '';
    resetBtn('editSaveBtn', '<i class="fas fa-save"></i> Save Changes');
    sr_openModal('editModal');
    document.getElementById('editRoleLabel').focus();
}

function submitEdit(e) {
    e.preventDefault();
    const btn = document.getElementById('editSaveBtn');
    setLoading(btn, 'Saving\u2026');

    const fd = new FormData(document.getElementById('editForm'));
    fd.append('action', 'update_role');

    apiFetch(fd)
        .then(d => {
            if (d.success) {
                sr_closeModal('editModal');
                showToast(d.message, false);
                setTimeout(() => location.reload(), 900);
            } else {
                showError(btn, '<i class="fas fa-save"></i> Save Changes', d.message);
            }
        })
        .catch(() => showError(btn, '<i class="fas fa-save"></i> Save Changes', 'Request failed. Please try again.'));
}

/* ── Create modal ───────────────────────────────────────────────────── */
function openCreate() {
    document.getElementById('createForm').reset();
    resetBtn('createSaveBtn', '<i class="fas fa-plus"></i> Create Role');
    sr_openModal('createModal');
    document.getElementById('createRoleLabel').focus();
}

function submitCreate(e) {
    e.preventDefault();
    const btn = document.getElementById('createSaveBtn');
    setLoading(btn, 'Creating\u2026');

    const fd = new FormData(document.getElementById('createForm'));
    fd.append('action', 'create_role');

    apiFetch(fd)
        .then(d => {
            if (d.success) {
                sr_closeModal('createModal');
                showToast(d.message, false);
                setTimeout(() => location.reload(), 900);
            } else {
                showError(btn, '<i class="fas fa-plus"></i> Create Role', d.message);
            }
        })
        .catch(() => showError(btn, '<i class="fas fa-plus"></i> Create Role', 'Request failed. Please try again.'));
}

/* ── Delete modal ───────────────────────────────────────────────────── */
function confirmDelete(role) {
    document.getElementById('deleteRoleId').value = role.id;

    const isPlatform = !role.is_custom;
    const platformWarning = isPlatform
        ? '<br><span style="color:#b45309;font-size:.85rem;">' +
          '<i class="fas fa-exclamation-triangle"></i> ' +
          'This is a built-in platform role. Deleting it will prevent new staff from being assigned to it. ' +
          'Existing staff assignments will also be removed.' +
          '</span>'
        : '';

    document.getElementById('deleteModalBody').innerHTML =
        'You are about to permanently delete the role <strong>' +
        escHtml(role.role_label) +
        '</strong>. This cannot be undone.' +
        platformWarning +
        '<br><br><span style="color:#dc2626;font-size:.85rem;">' +
        '<i class="fas fa-exclamation-triangle"></i> ' +
        'All active staff assigned to this role must be deactivated or reassigned first.' +
        '</span>';

    resetBtn('deleteConfirmBtn', '<i class="fas fa-trash-alt"></i> Yes, Delete');
    sr_openModal('deleteModal');
}

function submitDelete() {
    const btn    = document.getElementById('deleteConfirmBtn');
    const roleId = document.getElementById('deleteRoleId').value;
    setLoading(btn, 'Deleting\u2026');

    const fd = new FormData();
    fd.append('action',  'delete_role');
    fd.append('role_id', roleId);

    apiFetch(fd)
        .then(d => {
            if (d.success) {
                sr_closeModal('deleteModal');
                showToast(d.message, false);
                setTimeout(() => location.reload(), 900);
            } else {
                showError(btn, '<i class="fas fa-trash-alt"></i> Yes, Delete', d.message);
            }
        })
        .catch(() => showError(btn, '<i class="fas fa-trash-alt"></i> Yes, Delete', 'Request failed. Please try again.'));
}

/* ── Shared fetch wrapper ───────────────────────────────────────────── */
function apiFetch(formData) {
    return fetch(HANDLER_URL, { method: 'POST', body: formData })
        .then(r => {
            if (!r.ok) throw new Error('HTTP ' + r.status);
            return r.json();
        });
}

/* ── Button helpers ─────────────────────────────────────────────────── */
function setLoading(btn, label) {
    btn.disabled  = true;
    btn.innerHTML = '<i class="fas fa-spinner fa-spin"></i> ' + label;
}
function resetBtn(id, label) {
    const btn     = document.getElementById(id);
    btn.disabled  = false;
    btn.innerHTML = label;
}
function showError(btn, label, msg) {
    btn.disabled  = false;
    btn.innerHTML = label;
    showToast('Error: ' + msg, true);
}

/* ── XSS-safe text escape ───────────────────────────────────────────── */
function escHtml(str) {
    const d = document.createElement('div');
    d.textContent = str;
    return d.innerHTML;
}
</script>

<?php require_once __DIR__ . '/../../includes/footer.php'; ?>
