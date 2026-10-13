<?php
require_once __DIR__ . '/../config/app.php';
requireLogin();

$page_title   = 'My Profile';
$page_heading = 'My Profile';
$is_dashboard = true;
$breadcrumbs  = [['label' => 'Profile']];

$user_id = $_SESSION['user_id'];
$success = '';
$error   = '';

// Allowed values
$allowed_religions  = ['Muslim', 'Non-Muslim'];
$allowed_food_prefs = ['Halal', 'Non-Pork', 'Muslim-Friendly', 'Any'];
$allowed_cuisines   = ['Filipino','Middle Eastern','Indian','Malaysian','Indonesian',
                       'Turkish','Mediterranean','Asian Fusion','Seafood','Fast Food','Other'];

// Idempotent schema migration — ensure demographic columns exist
db_add_column($conn, 'users', 'religion',            "ENUM('Muslim','Non-Muslim') DEFAULT NULL");
db_add_column($conn, 'users', 'food_preference',     "ENUM('Halal','Non-Pork','Muslim-Friendly','Any') DEFAULT NULL");
db_add_column($conn, 'users', 'cuisine_preferences', "VARCHAR(255) DEFAULT NULL");
db_add_column($conn, 'users', 'address_line',        "TEXT DEFAULT NULL");
db_add_column($conn, 'users', 'address_region',      "VARCHAR(100) DEFAULT NULL");
db_add_column($conn, 'users', 'address_barangay',    "VARCHAR(150) DEFAULT NULL");
db_add_column($conn, 'users', 'city',                "VARCHAR(100) DEFAULT NULL");
db_add_column($conn, 'users', 'province',            "VARCHAR(100) DEFAULT NULL");
db_add_column($conn, 'users', 'postal_code',         "VARCHAR(20) DEFAULT NULL");

// Fetch user
$stmt = $conn->prepare("SELECT u.*, r.role_name FROM users u LEFT JOIN roles r ON u.role_id = r.id WHERE u.id = ?");
$stmt->bind_param("i", $user_id);
$stmt->execute();
$user = $stmt->get_result()->fetch_assoc();

// Handle profile update
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $first_name  = sanitize($_POST['first_name']  ?? '');
    $last_name   = sanitize($_POST['last_name']   ?? '');
    $phone       = sanitize($_POST['phone']       ?? '');
    $address     = sanitize($_POST['address']     ?? '');
    $full_name   = trim($first_name . ' ' . $last_name);

    // Demographics
    $religion        = sanitize($_POST['religion']        ?? '');
    $food_preference = sanitize($_POST['food_preference'] ?? '');
    $address_line    = sanitize($_POST['address_line']    ?? '');
    $address_region  = sanitize($_POST['region']          ?? '');
    $address_barangay= sanitize($_POST['barangay']        ?? '');
    $city            = sanitize($_POST['city']            ?? '');
    $province        = sanitize($_POST['province']        ?? '');
    $postal_code     = sanitize($_POST['postal_code']     ?? '');

    // Cuisine multi-select
    $raw_cuisines = (array)($_POST['cuisine_preferences'] ?? []);
    $cuisine_arr  = array_values(array_filter(
        array_map('trim', $raw_cuisines),
        fn($c) => in_array($c, $allowed_cuisines, true)
    ));
    $cuisine_preferences = implode(', ', $cuisine_arr);

    // Validate
    if (!in_array($religion, $allowed_religions, true) && !empty($religion)) {
        $error = 'Invalid religion value.';
    } elseif (!in_array($food_preference, $allowed_food_prefs, true) && !empty($food_preference)) {
        $error = 'Invalid food preference value.';
    } else {
        $upd = $conn->prepare("
            UPDATE users
            SET first_name = ?, last_name = ?, full_name = ?, phone = ?, address = ?,
                religion = ?, food_preference = ?, cuisine_preferences = ?,
                address_line = ?, address_region = ?, address_barangay = ?,
                city = ?, province = ?, postal_code = ?
            WHERE id = ?
        ");
        $upd->bind_param(
            "ssssssssssssssi",
            $first_name, $last_name, $full_name, $phone, $address,
            $religion, $food_preference, $cuisine_preferences,
            $address_line, $address_region, $address_barangay,
            $city, $province, $postal_code,
            $user_id
        );

        if ($upd->execute()) {
            $_SESSION['full_name']  = $full_name;
            $_SESSION['first_name'] = $first_name;
            $_SESSION['last_name']  = $last_name;
            $success = 'Profile updated successfully.';
            // Refresh user data
            $stmt->execute();
            $user = $stmt->get_result()->fetch_assoc();
        } else {
            $error = 'Failed to update profile.';
        }
    }
}

// Helpers
$esc  = fn($k) => htmlspecialchars($user[$k] ?? '');
$sel  = fn($k, $v) => (($user[$k] ?? '') === $v) ? 'selected' : '';
$chkC = fn($v) => in_array($v, array_map('trim', explode(',', $user['cuisine_preferences'] ?? '')), true) ? 'checked' : '';

require_once __DIR__ . '/../includes/header.php';
?>

<style>
/* Chip checkboxes — same as register.php */
.pref-chip input { display:none; }
.pref-chip span {
    display:inline-block; padding:5px 14px; border-radius:999px; font-size:.82rem;
    border:1.5px solid var(--neutral-300); background:#fff; color:var(--neutral-600);
    cursor:pointer; transition:all .15s; user-select:none;
}
.pref-chip input:checked + span {
    border-color:var(--primary-600); background:var(--primary-50);
    color:var(--primary-700); font-weight:600;
}
.pref-chip span:hover { border-color:var(--primary-400); }
</style>

<div style="max-width:860px;">

    <?php if ($success): ?>
    <div class="alert alert-success">
        <i class="fas fa-check-circle"></i><span><?= $success ?></span>
        <button class="close-alert"><i class="fas fa-times"></i></button>
    </div>
    <?php endif; ?>
    <?php if ($error): ?>
    <div class="alert alert-danger">
        <i class="fas fa-exclamation-circle"></i><span><?= htmlspecialchars($error) ?></span>
        <button class="close-alert"><i class="fas fa-times"></i></button>
    </div>
    <?php endif; ?>

    <!-- ── Profile header card ───────────────────────────────────────────── -->
    <div class="card" style="margin-bottom:24px;">
        <div class="card-body" style="display:flex;align-items:center;gap:24px;padding:32px;flex-wrap:wrap;">
            <?php if (!empty($user['avatar'])): ?>
                <img src="<?= htmlspecialchars($user['avatar']) ?>" alt="Avatar"
                     style="width:80px;height:80px;border-radius:16px;object-fit:cover;flex-shrink:0;">
            <?php else: ?>
                <div style="width:80px;height:80px;border-radius:16px;background:var(--primary-100);
                            color:var(--primary-700);display:flex;align-items:center;justify-content:center;
                            font-size:2rem;font-weight:800;font-family:var(--font-display);flex-shrink:0;">
                    <?= strtoupper(substr($user['full_name'] ?? 'U', 0, 1)) ?>
                </div>
            <?php endif; ?>
            <div style="flex:1;min-width:0;">
                <h2 style="font-family:var(--font-display);font-weight:800;font-size:1.5rem;margin-bottom:4px;">
                    <?= htmlspecialchars($user['full_name'] ?? '') ?>
                </h2>
                <p style="color:var(--neutral-500);margin-bottom:10px;"><?= htmlspecialchars($user['email'] ?? '') ?></p>
                <div style="display:flex;gap:8px;flex-wrap:wrap;align-items:center;">
                    <?= getStatusBadge($user['role_status'] ?? '') ?>
                    <span class="badge badge-primary"><?= htmlspecialchars($user['role_name'] ?? 'No Role') ?></span>
                    <?php if (!empty($user['religion'])): ?>
                    <span style="background:var(--primary-50);color:var(--primary-700);border:1px solid var(--primary-200);
                                 padding:3px 10px;border-radius:999px;font-size:.75rem;font-weight:600;">
                        <i class="fas fa-moon" style="margin-right:4px;"></i><?= htmlspecialchars($user['religion']) ?>
                    </span>
                    <?php endif; ?>
                    <?php if (!empty($user['food_preference'])): ?>
                    <span style="background:#fef9c3;color:#854d0e;border:1px solid #fde68a;
                                 padding:3px 10px;border-radius:999px;font-size:.75rem;font-weight:600;">
                        <i class="fas fa-utensils" style="margin-right:4px;"></i><?= htmlspecialchars($user['food_preference']) ?>
                    </span>
                    <?php endif; ?>
                    <?php if (!empty($user['city'])): ?>
                    <span style="background:var(--neutral-100);color:var(--neutral-600);border:1px solid var(--neutral-200);
                                 padding:3px 10px;border-radius:999px;font-size:.75rem;font-weight:600;">
                        <i class="fas fa-location-dot" style="margin-right:4px;"></i><?= htmlspecialchars($user['city']) ?>
                    </span>
                    <?php endif; ?>
                </div>
                <?php if (!empty($user['cuisine_preferences'])): ?>
                <div style="margin-top:10px;display:flex;flex-wrap:wrap;gap:5px;">
                    <?php foreach (array_map('trim', explode(',', $user['cuisine_preferences'])) as $_c):
                          if (!$_c) continue; ?>
                    <span style="background:var(--primary-50);color:var(--primary-700);border:1px solid var(--primary-200);
                                 padding:2px 9px;border-radius:999px;font-size:.72rem;font-weight:600;">
                        <?= htmlspecialchars($_c) ?>
                    </span>
                    <?php endforeach; ?>
                </div>
                <?php endif; ?>
            </div>
        </div>
    </div>

    <!-- ── Edit form ─────────────────────────────────────────────────────── -->
    <form method="POST" action="">

        <!-- ① Account Info ──────────────────────────────────────────────── -->
        <div class="card" style="margin-bottom:20px;">
            <div class="card-header">
                <h3><i class="fas fa-user" style="color:var(--primary-600);margin-right:8px;"></i> Account Information</h3>
            </div>
            <div class="card-body">
                <div style="display:grid;grid-template-columns:1fr 1fr;gap:20px;">
                    <div class="form-group">
                        <label for="first_name">First Name</label>
                        <input type="text" id="first_name" name="first_name" class="form-control"
                               value="<?= $esc('first_name') ?>">
                    </div>
                    <div class="form-group">
                        <label for="last_name">Last Name</label>
                        <input type="text" id="last_name" name="last_name" class="form-control"
                               value="<?= $esc('last_name') ?>">
                    </div>
                </div>
                <div class="form-group">
                    <label>Email</label>
                    <input type="email" class="form-control" value="<?= $esc('email') ?>" disabled>
                    <p class="form-text">Email cannot be changed.</p>
                </div>
                <div style="display:grid;grid-template-columns:1fr 1fr;gap:20px;">
                    <div class="form-group">
                        <label for="phone">Phone Number</label>
                        <input type="text" id="phone" name="phone" class="form-control"
                               value="<?= $esc('phone') ?>" placeholder="09123456789">
                    </div>
                    <div class="form-group">
                        <label>Member Since</label>
                        <input type="text" class="form-control" value="<?= formatDate($user['created_at']) ?>" disabled>
                    </div>
                </div>
            </div>
        </div>

        <!-- ② Demographic & Food Profile ───────────────────────────────── -->
        <div class="card" style="margin-bottom:20px;">
            <div class="card-header">
                <h3><i class="fas fa-heart" style="color:var(--primary-600);margin-right:8px;"></i> Demographic &amp; Food Profile</h3>
            </div>
            <div class="card-body">
                <div style="display:grid;grid-template-columns:1fr 1fr;gap:20px;margin-bottom:20px;">
                    <!-- Religion -->
                    <div class="form-group" style="margin:0;">
                        <label for="religion">Religion</label>
                        <select id="religion" name="religion" class="form-control">
                            <option value="">— Select Religion —</option>
                            <option value="Muslim"     <?= $sel('religion','Muslim') ?>>Muslim</option>
                            <option value="Non-Muslim" <?= $sel('religion','Non-Muslim') ?>>Non-Muslim</option>
                        </select>
                    </div>
                    <!-- Food Preference -->
                    <div class="form-group" style="margin:0;">
                        <label for="food_preference">Food Preference</label>
                        <select id="food_preference" name="food_preference" class="form-control">
                            <option value="">— Select Preference —</option>
                            <option value="Halal"          <?= $sel('food_preference','Halal') ?>>Halal</option>
                            <option value="Non-Pork"       <?= $sel('food_preference','Non-Pork') ?>>Non-Pork</option>
                            <option value="Muslim-Friendly"<?= $sel('food_preference','Muslim-Friendly') ?>>Muslim-Friendly</option>
                            <option value="Any"            <?= $sel('food_preference','Any') ?>>Any</option>
                        </select>
                    </div>
                </div>

                <!-- Cuisine Preferences -->
                <div class="form-group" style="margin:0;">
                    <label>Preferred Cuisines
                        <span style="font-weight:400;color:var(--neutral-400);font-size:.8rem;margin-left:4px;">(Select all that apply)</span>
                    </label>
                    <div style="display:flex;flex-wrap:wrap;gap:8px;padding:10px 12px;
                                border:1px solid var(--neutral-300);border-radius:var(--radius-md);
                                background:var(--neutral-50);">
                        <?php foreach ($allowed_cuisines as $c): ?>
                        <label class="pref-chip">
                            <input type="checkbox" name="cuisine_preferences[]"
                                   value="<?= htmlspecialchars($c) ?>" <?= $chkC($c) ?>>
                            <span><?= htmlspecialchars($c) ?></span>
                        </label>
                        <?php endforeach; ?>
                    </div>
                </div>
            </div>
        </div>

        <!-- ③ Location ──────────────────────────────────────────────────── -->
        <div class="card" style="margin-bottom:20px;">
            <div class="card-header">
                <h3><i class="fas fa-location-dot" style="color:var(--primary-600);margin-right:8px;"></i> Location Details</h3>
            </div>
            <div class="card-body">
                <div style="display:grid;grid-template-columns:1fr 1fr;gap:20px;margin-bottom:16px;">
                    <!-- Region -->
                    <div class="form-group" style="margin:0;">
                        <label for="prof_region">Region</label>
                        <select id="prof_region" name="region" class="form-control"
                                onchange="onProfRegion()">
                            <option value="">— Select Region —</option>
                        </select>
                    </div>
                    <!-- Province -->
                    <div class="form-group" style="margin:0;">
                        <label for="prof_province">Province</label>
                        <select id="prof_province" name="province" class="form-control" disabled
                                onchange="onProfProvince()">
                            <option value="">— Select Province —</option>
                        </select>
                    </div>
                    <!-- City -->
                    <div class="form-group" style="margin:0;">
                        <label for="prof_city">City / Municipality</label>
                        <select id="prof_city" name="city" class="form-control" disabled
                                onchange="onProfCity()">
                            <option value="">— Select City —</option>
                        </select>
                    </div>
                    <!-- Barangay -->
                    <div class="form-group" style="margin:0;">
                        <label for="prof_barangay">Barangay</label>
                        <select id="prof_barangay" name="barangay" class="form-control" disabled>
                            <option value="">— Select Barangay —</option>
                        </select>
                    </div>
                </div>
                <!-- Street address -->
                <div class="form-group" style="margin-bottom:16px;">
                    <label for="address_line">Street Address / Unit / Building</label>
                    <input type="text" id="address_line" name="address_line" class="form-control"
                           placeholder="House/Building No., Street Name"
                           value="<?= $esc('address_line') ?>">
                </div>
                <!-- Postal code -->
                <div style="display:grid;grid-template-columns:1fr 1fr;gap:20px;">
                    <div class="form-group" style="margin:0;">
                        <label for="postal_code">Postal Code</label>
                        <input type="text" id="postal_code" name="postal_code" class="form-control"
                               placeholder="e.g. 8000"
                               value="<?= $esc('postal_code') ?>">
                    </div>
                </div>
            </div>
        </div>

        <!-- Submit -->
        <div style="display:flex;justify-content:flex-end;gap:12px;margin-bottom:32px;">
            <button type="submit" class="btn btn-primary">
                <i class="fas fa-save"></i> Save Changes
            </button>
        </div>
    </form>
</div>

<script>
// ── Pre-fill values from PHP ──────────────────────────────────────────────────
const SAVED = {
    region:   <?= json_encode($user['address_region']   ?? '') ?>,
    province: <?= json_encode($user['province']         ?? '') ?>,
    city:     <?= json_encode($user['city']             ?? '') ?>,
    barangay: <?= json_encode($user['address_barangay'] ?? '') ?>,
};
const LOC_BASE = '<?= rtrim(BASE_URL, '/') ?>/assets/data/';
let _regions = null, _provinces = null, _cities = null, _barangays = null;
const _cache = {};

async function loadJson(f) {
    if (_cache[f]) return _cache[f];
    try {
        const r = await fetch(LOC_BASE + f);
        if (!r.ok) throw new Error('HTTP ' + r.status);
        _cache[f] = await r.json();
        return _cache[f];
    } catch(e) { console.error('Loc load failed:', f, e); return null; }
}

function populate(selId, items, labelKey, valKey, savedVal, disabled = false) {
    const s = document.getElementById(selId);
    const placeholder = s.options[0].textContent;
    s.innerHTML = `<option value="">${placeholder}</option>`;
    items.forEach(item => {
        const o = document.createElement('option');
        o.value       = item[valKey];
        o.textContent = item[labelKey];
        if (item.code) o.dataset.code = item.code;
        if (item[valKey] === savedVal) o.selected = true;
        s.appendChild(o);
    });
    s.disabled = disabled;
}

async function initProfLocation() {
    [_regions, _provinces, _cities, _barangays] = await Promise.all([
        loadJson('regions.json'), loadJson('provinces.json'),
        loadJson('cities.json'),  loadJson('barangays.json')
    ]);
    if (!_regions) return;

    // Populate regions
    populate('prof_region', _regions, 'name', 'name', SAVED.region, false);

    if (!SAVED.region) return;

    // Pre-fill province list
    const rCode = _regions.find(r => r.name === SAVED.region)?.code || '';
    const filtP = (_provinces||[]).filter(p => p.regionCode === rCode)
                                   .sort((a,b) => a.name.localeCompare(b.name));
    populate('prof_province', filtP, 'name', 'name', SAVED.province, false);
    if (!SAVED.province) return;

    // Pre-fill city list
    const pCode = (_provinces||[]).find(p => p.name === SAVED.province)?.code || '';
    const filtC = (_cities||[]).filter(c => c.provinceCode === pCode)
                                .sort((a,b) => a.name.localeCompare(b.name));
    // Keep code as dataset
    const cItems = filtC.map(c => ({name: c.name, code: c.code}));
    populate('prof_city', cItems, 'name', 'name', SAVED.city, false);
    if (!SAVED.city) return;

    // Pre-fill barangay list
    const cityOpt = document.getElementById('prof_city').querySelector(`option[value="${CSS.escape(SAVED.city)}"]`);
    const cCode   = cityOpt?.dataset?.code || '';
    const bList   = ((_barangays && _barangays[cCode]) || []).sort();
    const bItems  = bList.map(b => ({name: b, code: ''}));
    populate('prof_barangay', bItems, 'name', 'name', SAVED.barangay, false);
}

function resetSelect(id, placeholder, disable = true) {
    const s = document.getElementById(id);
    s.innerHTML = `<option value="">${placeholder}</option>`;
    s.disabled  = disable;
    s.value     = '';
}

function onProfRegion() {
    const rName = document.getElementById('prof_region').value;
    const rCode = (_regions||[]).find(r => r.name === rName)?.code || '';
    resetSelect('prof_province', '— Select Province —');
    resetSelect('prof_city',     '— Select City —');
    resetSelect('prof_barangay', '— Select Barangay —');
    if (!rCode || !_provinces) return;
    const filtered = _provinces.filter(p => p.regionCode === rCode)
                                .sort((a,b) => a.name.localeCompare(b.name));
    populate('prof_province', filtered, 'name', 'name', '', false);
}

function onProfProvince() {
    const pName = document.getElementById('prof_province').value;
    const pCode = (_provinces||[]).find(p => p.name === pName)?.code || '';
    resetSelect('prof_city',     '— Select City —');
    resetSelect('prof_barangay', '— Select Barangay —');
    if (!pCode || !_cities) return;
    const filtered = _cities.filter(c => c.provinceCode === pCode)
                             .sort((a,b) => a.name.localeCompare(b.name));
    const items = filtered.map(c => ({name: c.name, code: c.code}));
    populate('prof_city', items, 'name', 'name', '', false);
}

function onProfCity() {
    const cs  = document.getElementById('prof_city');
    const opt = cs.options[cs.selectedIndex];
    const cc  = opt?.dataset?.code || '';
    resetSelect('prof_barangay', '— Select Barangay —');
    const names = (_barangays && _barangays[cc]) || [];
    if (!names.length) return;
    const items = [...names].sort().map(b => ({name: b, code: ''}));
    populate('prof_barangay', items, 'name', 'name', '', false);
}

document.getElementById('prof_region')  ?.addEventListener('change', onProfRegion);
document.getElementById('prof_province')?.addEventListener('change', onProfProvince);
document.getElementById('prof_city')    ?.addEventListener('change', onProfCity);

initProfLocation();
</script>

<?php require_once __DIR__ . '/../includes/footer.php'; ?>
