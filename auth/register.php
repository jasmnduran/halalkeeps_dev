<?php
require_once __DIR__ . '/../config/app.php';

// Already logged in — go straight to their dashboard
if (isLoggedIn()) {
    header('Location: ' . getDashboardUrl($_SESSION['role_id'] ?? ROLE_CUSTOMER));
    exit();
}

// ── Idempotent schema migration — add demographic columns if missing ───────────
db_add_column($conn, 'users', 'religion',           "ENUM('Muslim','Non-Muslim') DEFAULT NULL");
db_add_column($conn, 'users', 'food_preference',    "ENUM('Halal','Non-Pork','Muslim-Friendly','Any') DEFAULT NULL");
db_add_column($conn, 'users', 'cuisine_preferences',"VARCHAR(255) DEFAULT NULL");
db_add_column($conn, 'users', 'address_line',       "TEXT DEFAULT NULL");
db_add_column($conn, 'users', 'address_region',     "VARCHAR(100) DEFAULT NULL");
db_add_column($conn, 'users', 'address_barangay',   "VARCHAR(150) DEFAULT NULL");
db_add_column($conn, 'users', 'city',               "VARCHAR(100) DEFAULT NULL");
db_add_column($conn, 'users', 'province',           "VARCHAR(100) DEFAULT NULL");
db_add_column($conn, 'users', 'postal_code',        "VARCHAR(20) DEFAULT NULL");
// ── Allowed values (server-side whitelist) ────────────────────────────────────
$allowed_religions   = ['Muslim', 'Non-Muslim'];
$allowed_food_prefs  = ['Halal', 'Non-Pork', 'Muslim-Friendly', 'Any'];
$allowed_cuisines    = ['Filipino','Middle Eastern','Indian','Malaysian','Indonesian',
                        'Turkish','Mediterranean','Asian Fusion','Seafood','Fast Food','Other'];

$error   = '';
$success = '';

// ── Re-populate form on error ─────────────────────────────────────────────────
$old = [];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    // Basic fields
    $first_name = sanitize($_POST['firstName'] ?? '');
    $last_name  = sanitize($_POST['lastName']  ?? '');
    $email      = sanitize($_POST['email']     ?? '');
    $phone      = sanitize($_POST['phone']     ?? '');
    $password         = $_POST['password']         ?? '';
    $confirm_password = $_POST['confirmPassword']  ?? '';

    // Demographic fields
    $religion        = sanitize($_POST['religion']        ?? '');
    $food_preference = sanitize($_POST['food_preference'] ?? '');
    $address_line    = sanitize($_POST['address_line']    ?? '');
    $address_region  = sanitize($_POST['region']          ?? '');
    $address_barangay= sanitize($_POST['barangay']        ?? '');
    $city            = sanitize($_POST['city']            ?? '');
    $province        = sanitize($_POST['province']        ?? '');
    $postal_code     = sanitize($_POST['postal_code']     ?? '');

    // Cuisine multi-select — validate each value against whitelist
    $raw_cuisines = (array)($_POST['cuisine_preferences'] ?? []);
    $cuisine_arr  = array_values(array_filter(
        array_map('trim', $raw_cuisines),
        fn($c) => in_array($c, $allowed_cuisines, true)
    ));
    $cuisine_preferences = implode(', ', $cuisine_arr);

    // Preserve form values for repopulation
    $old = compact('first_name','last_name','email','phone',
                   'religion','food_preference','cuisine_arr',
                   'address_region','address_barangay',
                   'address_line','city','province','postal_code');
    // ── Validation ────────────────────────────────────────────────────────────
    if (empty($first_name) || empty($last_name) || empty($email) || empty($password)) {
        $error = 'Please fill in all required fields.';
    } elseif (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
        $error = 'Please enter a valid email address.';
    } elseif (strlen($password) < 8) {
        $error = 'Password must be at least 8 characters long.';
    } elseif (!preg_match('/[A-Z]/', $password)) {
        $error = 'Password must contain at least one uppercase letter.';
    } elseif (!preg_match('/[a-z]/', $password)) {
        $error = 'Password must contain at least one lowercase letter.';
    } elseif (!preg_match('/[0-9]/', $password)) {
        $error = 'Password must contain at least one number.';
    } elseif (!preg_match('/[\W_]/', $password)) {
        $error = 'Password must contain at least one special character (e.g. !@#$%^&*).';
    } elseif ($password !== $confirm_password) {
        $error = 'Passwords do not match.';
    } elseif (!in_array($religion, $allowed_religions, true)) {
        $error = 'Please select a valid religion.';
    } elseif (!in_array($food_preference, $allowed_food_prefs, true)) {
        $error = 'Please select a valid food preference.';
    } elseif (empty($address_line)) {
        $error = 'Please enter your street address.';
    } elseif (empty($city)) {
        $error = 'Please select your city.';
    } elseif (empty($province)) {
        $error = 'Please select your province.';
    } else {
        // Check duplicate email
        $check = $conn->prepare("SELECT id FROM users WHERE email = ?");
        $check->bind_param("s", $email);
        $check->execute();
        if ($check->get_result()->num_rows > 0) {
            $error = 'An account with this email already exists. Try signing in instead.';
        } else {
            $full_name       = $first_name . ' ' . $last_name;
            $hashed_password = password_hash($password, PASSWORD_DEFAULT);
            $customer_role_id = ROLE_CUSTOMER;

            $stmt = $conn->prepare("
                INSERT INTO users
                    (email, full_name, first_name, last_name, phone, password,
                     email_verified, role_id, role_status,
                     religion, food_preference, cuisine_preferences,
                     address_line, address_region, address_barangay,
                     city, province, postal_code)
                VALUES (?, ?, ?, ?, ?, ?, 1, ?, 'approved', ?, ?, ?, ?, ?, ?, ?, ?, ?)
            ");
            $stmt->bind_param(
                "ssssssisssssssss",
                $email, $full_name, $first_name, $last_name, $phone, $hashed_password,
                $customer_role_id,
                $religion, $food_preference, $cuisine_preferences,
                $address_line, $address_region, $address_barangay,
                $city, $province, $postal_code
            );

            if ($stmt->execute()) {
                logActivity($conn, $stmt->insert_id, 'Registration', 'User registered via email/password', 'auth');
                header('Location: ' . BASE_URL . 'auth/login.php?registered=1');
                exit();
            } else {
                $error = 'Registration failed. Please try again.';
            }
        }
    }
}

$google_auth_url = getGoogleAuthUrl();

// Helpers for re-population
$v  = fn(string $k, string $def = '') => htmlspecialchars($old[$k] ?? $def);
$sel = fn(string $k, string $val) => (($old[$k] ?? '') === $val) ? 'selected' : '';
$chk = fn(string $val) => in_array($val, $old['cuisine_arr'] ?? [], true) ? 'checked' : '';
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="utf-8">
<meta content="width=device-width, initial-scale=1.0" name="viewport">
<title>Halal Keeps - Create Your Account</title>
<script src="https://cdn.tailwindcss.com?plugins=forms,container-queries"></script>
<link href="https://fonts.googleapis.com/css2?family=Plus+Jakarta+Sans:wght@400;600;700&family=Manrope:wght@400;600&display=swap" rel="stylesheet">
<link href="https://fonts.googleapis.com/css2?family=Material+Symbols+Outlined:wght,FILL@100..700,0..1&display=swap" rel="stylesheet">
<script id="tailwind-config">
tailwind.config = {
    darkMode: "class",
    theme: {
        extend: {
            colors: {
                "on-tertiary-fixed":"#241a00","surface-container-highest":"#e1e3e4",
                "on-tertiary":"#ffffff","tertiary-fixed":"#ffe088","on-tertiary-container":"#4e3d00",
                "surface-container-high":"#e6e8e9","error":"#ba1a1a","on-error":"#ffffff",
                "inverse-surface":"#2e3132","on-secondary-fixed-variant":"#00504c",
                "error-container":"#ffdad6","on-primary-fixed-variant":"#005137",
                "secondary-fixed":"#7cf6ec","on-surface-variant":"#3f4943",
                "surface-variant":"#e1e3e4","primary-fixed-dim":"#89d6b0",
                "on-surface":"#191c1d","on-secondary-fixed":"#00201e",
                "on-secondary-container":"#00716b","inverse-on-surface":"#eff1f2",
                "primary":"#00452e","secondary":"#006a65","surface-dim":"#d8dadb",
                "surface-container-lowest":"#ffffff","on-primary-fixed":"#002114",
                "outline-variant":"#bec9c1","surface-bright":"#f8fafb",
                "on-tertiary-fixed-variant":"#574500","on-secondary":"#ffffff",
                "on-primary-container":"#89d6b0","secondary-container":"#7cf6ec",
                "tertiary-container":"#cba72f","background":"#f8fafb",
                "primary-container":"#005f41","surface-container":"#eceeef",
                "on-error-container":"#93000a","on-primary":"#ffffff",
                "surface-tint":"#186b4c","primary-fixed":"#a4f3cb","surface":"#f8fafb",
                "surface-container-low":"#f2f4f5","tertiary":"#735c00",
                "inverse-primary":"#89d6b0","secondary-fixed-dim":"#5dd9d0",
                "tertiary-fixed-dim":"#e9c349","outline":"#6f7a73","on-background":"#191c1d"
            },
            borderRadius: { DEFAULT:"0.25rem", lg:"0.5rem", xl:"0.75rem", full:"9999px" },
            spacing: { base:"8px","card-padding":"20px","container-margin":"24px",
                       gutter:"16px","section-gap":"40px" },
            fontFamily: {
                "headline-md":["Plus Jakarta Sans"],"display-lg":["Plus Jakarta Sans"],
                "headline-lg":["Plus Jakarta Sans"],"label-md":["Manrope"],
                "body-md":["Manrope"],"body-lg":["Manrope"],
                "stats-number":["Plus Jakarta Sans"],"headline-lg-mobile":["Plus Jakarta Sans"]
            },
            fontSize: {
                "headline-md":["24px",{lineHeight:"32px",fontWeight:"600"}],
                "display-lg":["48px",{lineHeight:"56px",letterSpacing:"-0.02em",fontWeight:"700"}],
                "headline-lg":["32px",{lineHeight:"40px",fontWeight:"700"}],
                "label-md":["14px",{lineHeight:"20px",fontWeight:"600"}],
                "body-md":["16px",{lineHeight:"24px",fontWeight:"400"}],
                "body-lg":["18px",{lineHeight:"28px",fontWeight:"400"}],
                "stats-number":["28px",{lineHeight:"34px",fontWeight:"700"}],
                "headline-lg-mobile":["26px",{lineHeight:"32px",fontWeight:"700"}]
            }
        }
    }
}
</script>
<style>
.pattern-bg { background-image: radial-gradient(rgba(255,255,255,.1) 1px,transparent 1px); background-size: 20px 20px; }
.valid-req { color: #006a65 !important; font-weight: 600; }
.valid-req .indicator { background-color: #006a65 !important; }
input:-webkit-autofill,input:-webkit-autofill:hover,input:-webkit-autofill:focus {
    -webkit-box-shadow:0 0 0px 1000px #f8fafb inset !important;
    -webkit-text-fill-color:#191c1d !important;
    transition:background-color 5000s ease-in-out 0s;
}
/* Chip-style cuisine checkbox */
.cuisine-chip input { display:none; }
.cuisine-chip span {
    display:inline-block; padding:5px 14px; border-radius:999px; font-size:.82rem;
    border:1.5px solid #bec9c1; background:#fff; color:#3f4943; cursor:pointer;
    transition:all .15s; user-select:none;
}
.cuisine-chip input:checked + span {
    border-color:#00452e; background:#ecfdf5; color:#00442e; font-weight:600;
}
.cuisine-chip span:hover { border-color:#00452e; }
</style>
</head>
<body class="bg-background min-h-screen flex text-on-surface font-body-md antialiased">

<main class="w-full min-h-screen flex flex-col md:flex-row">

<!-- ── Left panel ──────────────────────────────────────────────────────────── -->
<section class="hidden md:flex md:w-5/12 lg:w-1/3 bg-primary-container text-on-primary-container flex-col justify-center items-center p-8 lg:p-12 relative pattern-bg sticky top-0 h-screen">
    <div class="max-w-xs w-full flex flex-col items-center text-center space-y-8 z-10">
        <div class="w-24 h-24 rounded-2xl overflow-hidden bg-white/10 border border-white/20 shadow-lg">
            <img alt="Halal Keeps Logo" class="w-full h-full object-cover" src="<?= BASE_URL ?>assets/images/logo.jpg">
        </div>
        <h1 class="font-headline-lg text-headline-lg text-white">Join Our Platform</h1>
        <p class="font-body-md text-body-md text-primary-fixed opacity-90 max-w-sm">
            Create your account and explore Halal, Muslim-Friendly, and Non-Pork options nearby.
        </p>
        <ul class="space-y-4 text-left w-full mt-4">
            <?php foreach (['Sign up as a Customer','Log in your credentials','Explore the Marketplace','Register your Business to Halal Keeps'] as $step): ?>
            <li class="flex items-center space-x-3 text-white/90">
                <span class="material-symbols-outlined text-primary-fixed bg-white/10 rounded-full p-1 text-sm flex-shrink-0" style="font-variation-settings:'FILL' 1;">check</span>
                <span class="font-body-md text-body-md"><?= $step ?></span>
            </li>
            <?php endforeach; ?>
        </ul>
    </div>
    <div class="absolute inset-0 bg-gradient-to-t from-primary/50 to-transparent pointer-events-none"></div>
</section>

<!-- ── Right panel (scrollable form) ──────────────────────────────────────── -->
<section class="w-full md:w-7/12 lg:w-2/3 bg-surface min-h-screen overflow-y-auto flex flex-col px-6 sm:px-10 lg:px-16 py-8">
<div class="max-w-2xl w-full mx-auto space-y-6">

    <!-- Back link -->
    <a class="inline-flex items-center space-x-2 text-primary hover:underline font-label-md text-label-md py-1" href="../index.php">
        <span class="material-symbols-outlined text-[18px]">arrow_back</span>
        <span>Back to Home</span>
    </a>

    <!-- Heading -->
    <div class="space-y-1">
        <h2 class="font-headline-lg text-headline-lg text-on-surface">Create Your Account</h2>
        <p class="font-body-md text-body-md text-on-surface-variant">Sign up to get started with Halal Keeps</p>
    </div>

    <!-- Alerts -->
    <?php if (!empty($error)): ?>
    <div class="p-3 rounded-lg bg-error-container text-on-error-container border border-error/20 text-sm flex items-center space-x-2">
        <span class="material-symbols-outlined text-error text-[20px] flex-shrink-0">error</span>
        <span><?= htmlspecialchars($error) ?></span>
    </div>
    <?php endif; ?>

    <!-- Google signup -->
    <button type="button" onclick="window.location.href='<?= htmlspecialchars($google_auth_url) ?>'"
            class="w-full flex items-center justify-center space-x-3 py-2.5 px-4 border border-outline-variant rounded-lg bg-surface hover:bg-surface-container-low transition-colors shadow-sm text-on-surface font-label-md text-label-md">
        <svg class="w-5 h-5" viewBox="0 0 24 24">
            <path d="M22.56 12.25c0-.78-.07-1.53-.2-2.25H12v4.26h5.92c-.26 1.31-1.03 2.41-2.15 3.16v2.62h3.48c2.04-1.88 3.31-4.66 3.31-7.79z" fill="#4285F4"/>
            <path d="M12 23c2.97 0 5.46-.98 7.28-2.66l-3.48-2.62c-.98.66-2.23 1.05-3.8 1.05-2.92 0-5.39-1.97-6.27-4.63H2.15v2.71C3.97 20.45 7.66 23 12 23z" fill="#34A853"/>
            <path d="M5.73 14.14c-.22-.66-.35-1.36-.35-2.14s.13-1.48.35-2.14V7.15H2.15C1.43 8.59 1 10.23 1 12s.43 3.41 1.15 4.85l3.58-2.71z" fill="#FBBC05"/>
            <path d="M12 5.38c1.62 0 3.06.56 4.21 1.64l3.15-3.15C17.45 2.09 14.97 1 12 1 7.66 1 3.97 3.55 2.15 7.15l3.58 2.71c.88-2.66 3.35-4.48 6.27-4.48z" fill="#EA4335"/>
        </svg>
        <span>Sign up with Google</span>
    </button>

    <!-- Divider -->
    <div class="relative flex items-center py-1">
        <div class="flex-grow border-t border-outline-variant"></div>
        <span class="flex-shrink-0 mx-3 text-on-surface-variant text-[12px] font-medium bg-surface px-1">or create account with email</span>
        <div class="flex-grow border-t border-outline-variant"></div>
    </div>

    <!-- ── FORM ──────────────────────────────────────────────────────────── -->
    <form method="POST" action="<?= htmlspecialchars($_SERVER['PHP_SELF']) ?>" class="space-y-6">

        <!-- ① Account Info ──────────────────────────────────────────────── -->
        <div>
            <h3 class="font-label-md text-label-md text-on-surface-variant uppercase tracking-wider mb-3 flex items-center gap-2">
                <span class="w-5 h-5 rounded-full bg-primary text-on-primary text-[11px] font-bold flex items-center justify-center flex-shrink-0">1</span>
                Account Information
            </h3>
            <div class="grid grid-cols-1 sm:grid-cols-2 gap-4">
                <div class="space-y-1">
                    <label class="font-label-md text-label-md text-on-surface" for="firstName">First Name <span class="text-error">*</span></label>
                    <input id="firstName" name="firstName" type="text" required placeholder="Juan"
                           value="<?= $v('first_name') ?>"
                           class="w-full border border-outline-variant rounded-lg px-4 py-2.5 bg-surface text-on-surface focus:ring-2 focus:ring-primary focus:border-primary outline-none transition-shadow text-sm placeholder:text-outline">
                </div>
                <div class="space-y-1">
                    <label class="font-label-md text-label-md text-on-surface" for="lastName">Last Name <span class="text-error">*</span></label>
                    <input id="lastName" name="lastName" type="text" required placeholder="Dela Cruz"
                           value="<?= $v('last_name') ?>"
                           class="w-full border border-outline-variant rounded-lg px-4 py-2.5 bg-surface text-on-surface focus:ring-2 focus:ring-primary focus:border-primary outline-none transition-shadow text-sm placeholder:text-outline">
                </div>
                <div class="space-y-1">
                    <label class="font-label-md text-label-md text-on-surface" for="email">Email Address <span class="text-error">*</span></label>
                    <input id="email" name="email" type="email" required placeholder="juan@example.com"
                           value="<?= $v('email') ?>"
                           class="w-full border border-outline-variant rounded-lg px-4 py-2.5 bg-surface text-on-surface focus:ring-2 focus:ring-primary focus:border-primary outline-none transition-shadow text-sm placeholder:text-outline">
                </div>
                <div class="space-y-1">
                    <label class="font-label-md text-label-md text-on-surface" for="phone">Phone Number</label>
                    <input id="phone" name="phone" type="tel" placeholder="09123456789"
                           value="<?= $v('phone') ?>"
                           class="w-full border border-outline-variant rounded-lg px-4 py-2.5 bg-surface text-on-surface focus:ring-2 focus:ring-primary focus:border-primary outline-none transition-shadow text-sm placeholder:text-outline">
                </div>
                <div class="space-y-1">
                    <label class="font-label-md text-label-md text-on-surface" for="password">Password <span class="text-error">*</span></label>
                    <div class="relative">
                        <input id="password" name="password" type="password" required
                               placeholder="Min. 8 chars with uppercase, number &amp; symbol"
                               class="w-full border border-outline-variant rounded-lg pl-4 pr-10 py-2.5 bg-surface text-on-surface focus:ring-2 focus:ring-primary focus:border-primary outline-none transition-shadow text-sm placeholder:text-outline">
                        <button type="button" class="toggle-password absolute inset-y-0 right-0 pr-3 flex items-center text-outline" data-target="password">
                            <span class="material-symbols-outlined text-[20px]">visibility_off</span>
                        </button>
                    </div>
                    <div class="grid grid-cols-2 gap-x-2 gap-y-1 mt-1 text-[11px] text-outline">
                        <div class="flex items-center space-x-1" id="req-len"><div class="indicator w-1.5 h-1.5 rounded-full bg-outline-variant"></div><span>8+ characters</span></div>
                        <div class="flex items-center space-x-1" id="req-upper"><div class="indicator w-1.5 h-1.5 rounded-full bg-outline-variant"></div><span>Uppercase letter</span></div>
                        <div class="flex items-center space-x-1" id="req-lower"><div class="indicator w-1.5 h-1.5 rounded-full bg-outline-variant"></div><span>Lowercase letter</span></div>
                        <div class="flex items-center space-x-1" id="req-num"><div class="indicator w-1.5 h-1.5 rounded-full bg-outline-variant"></div><span>One number</span></div>
                        <div class="flex items-center space-x-1 col-span-2" id="req-spec"><div class="indicator w-1.5 h-1.5 rounded-full bg-outline-variant"></div><span>Special character</span></div>
                    </div>
                </div>
                <div class="space-y-1">
                    <label class="font-label-md text-label-md text-on-surface" for="confirmPassword">Confirm Password <span class="text-error">*</span></label>
                    <div class="relative">
                        <input id="confirmPassword" name="confirmPassword" type="password" required
                               placeholder="Repeat your password"
                               class="w-full border border-outline-variant rounded-lg pl-4 pr-10 py-2.5 bg-surface text-on-surface focus:ring-2 focus:ring-primary focus:border-primary outline-none transition-shadow text-sm placeholder:text-outline">
                        <button type="button" class="toggle-password absolute inset-y-0 right-0 pr-3 flex items-center text-outline" data-target="confirmPassword">
                            <span class="material-symbols-outlined text-[20px]">visibility_off</span>
                        </button>
                    </div>
                </div>
            </div>
        </div>

        <!-- ② Demographic & Food Profile ───────────────────────────────── -->
        <div class="border-t border-outline-variant/50 pt-6">
            <h3 class="font-label-md text-label-md text-on-surface-variant uppercase tracking-wider mb-3 flex items-center gap-2">
                <span class="w-5 h-5 rounded-full bg-primary text-on-primary text-[11px] font-bold flex items-center justify-center flex-shrink-0">2</span>
                Demographic &amp; Food Profile
            </h3>

            <div class="grid grid-cols-1 sm:grid-cols-2 gap-4 mb-4">
                <!-- Religion -->
                <div class="space-y-1">
                    <label class="font-label-md text-label-md text-on-surface" for="religion">Religion <span class="text-error">*</span></label>
                    <select id="religion" name="religion" required
                            class="w-full border border-outline-variant rounded-lg px-4 py-2.5 bg-surface text-on-surface focus:ring-2 focus:ring-primary focus:border-primary outline-none text-sm">
                        <option value="" disabled <?= empty($old) ? 'selected' : '' ?>>Select Religion</option>
                        <option value="Muslim"     <?= $sel('religion','Muslim') ?>>Muslim</option>
                        <option value="Non-Muslim" <?= $sel('religion','Non-Muslim') ?>>Non-Muslim</option>
                    </select>
                </div>
                <!-- Food Preference -->
                <div class="space-y-1">
                    <label class="font-label-md text-label-md text-on-surface" for="food_preference">Food Preference <span class="text-error">*</span></label>
                    <select id="food_preference" name="food_preference" required
                            class="w-full border border-outline-variant rounded-lg px-4 py-2.5 bg-surface text-on-surface focus:ring-2 focus:ring-primary focus:border-primary outline-none text-sm">
                        <option value="" disabled <?= empty($old) ? 'selected' : '' ?>>Select Dietary Preference</option>
                        <option value="Halal"          <?= $sel('food_preference','Halal') ?>>Halal</option>
                        <option value="Non-Pork"       <?= $sel('food_preference','Non-Pork') ?>>Non-Pork</option>
                        <option value="Muslim-Friendly"<?= $sel('food_preference','Muslim-Friendly') ?>>Muslim-Friendly</option>
                        <option value="Any"            <?= $sel('food_preference','Any') ?>>Any</option>
                    </select>
                </div>
            </div>

            <!-- Cuisine Preferences -->
            <div class="space-y-2">
                <label class="font-label-md text-label-md text-on-surface block">
                    Preferred Cuisines
                    <span class="text-on-surface-variant font-normal text-xs ml-1">(Select all that apply)</span>
                </label>
                <div class="flex flex-wrap gap-2">
                    <?php foreach ($allowed_cuisines as $c): ?>
                    <label class="cuisine-chip">
                        <input type="checkbox" name="cuisine_preferences[]" value="<?= $c ?>" <?= $chk($c) ?>>
                        <span><?= $c ?></span>
                    </label>
                    <?php endforeach; ?>
                </div>
            </div>
        </div>

        <!-- ③ Location ──────────────────────────────────────────────────── -->
        <div class="border-t border-outline-variant/50 pt-6">
            <h3 class="font-label-md text-label-md text-on-surface-variant uppercase tracking-wider mb-3 flex items-center gap-2">
                <span class="w-5 h-5 rounded-full bg-primary text-on-primary text-[11px] font-bold flex items-center justify-center flex-shrink-0">3</span>
                Location Details
            </h3>
            <div class="grid grid-cols-1 sm:grid-cols-2 gap-4">
                <!-- Region -->
                <div class="space-y-1">
                    <label class="font-label-md text-label-md text-on-surface" for="reg_region">Region <span class="text-error">*</span></label>
                    <select id="reg_region" name="region" required
                            onchange="onRegRegion()"
                            class="w-full border border-outline-variant rounded-lg px-4 py-2.5 bg-surface text-on-surface focus:ring-2 focus:ring-primary focus:border-primary outline-none text-sm disabled:opacity-50">
                        <option value="">— Select Region —</option>
                    </select>
                </div>
                <!-- Province -->
                <div class="space-y-1">
                    <label class="font-label-md text-label-md text-on-surface" for="reg_province">Province <span class="text-error">*</span></label>
                    <select id="reg_province" name="province" required disabled
                            onchange="onRegProvince()"
                            class="w-full border border-outline-variant rounded-lg px-4 py-2.5 bg-surface text-on-surface focus:ring-2 focus:ring-primary focus:border-primary outline-none text-sm disabled:opacity-50">
                        <option value="">— Select Province —</option>
                    </select>
                </div>
                <!-- City -->
                <div class="space-y-1">
                    <label class="font-label-md text-label-md text-on-surface" for="reg_city">City / Municipality <span class="text-error">*</span></label>
                    <select id="reg_city" name="city" required disabled
                            onchange="onRegCity()"
                            class="w-full border border-outline-variant rounded-lg px-4 py-2.5 bg-surface text-on-surface focus:ring-2 focus:ring-primary focus:border-primary outline-none text-sm disabled:opacity-50">
                        <option value="">— Select City —</option>
                    </select>
                </div>
                <!-- Barangay -->
                <div class="space-y-1">
                    <label class="font-label-md text-label-md text-on-surface" for="reg_barangay">Barangay <span class="text-error">*</span></label>
                    <select id="reg_barangay" name="barangay" required disabled
                            class="w-full border border-outline-variant rounded-lg px-4 py-2.5 bg-surface text-on-surface focus:ring-2 focus:ring-primary focus:border-primary outline-none text-sm disabled:opacity-50">
                        <option value="">— Select Barangay —</option>
                    </select>
                </div>
                <!-- Street address -->
                <div class="sm:col-span-2 space-y-1">
                    <label class="font-label-md text-label-md text-on-surface" for="address_line">Street Address / Unit / Building <span class="text-error">*</span></label>
                    <input id="address_line" name="address_line" type="text" required
                           placeholder="House/Building No., Street Name"
                           value="<?= $v('address_line') ?>"
                           class="w-full border border-outline-variant rounded-lg px-4 py-2.5 bg-surface text-on-surface focus:ring-2 focus:ring-primary focus:border-primary outline-none text-sm placeholder:text-outline">
                </div>
                <!-- Postal code -->
                <div class="space-y-1">
                    <label class="font-label-md text-label-md text-on-surface" for="postal_code">Postal Code</label>
                    <input id="postal_code" name="postal_code" type="text" placeholder="e.g. 8000"
                           value="<?= $v('postal_code') ?>"
                           class="w-full border border-outline-variant rounded-lg px-4 py-2.5 bg-surface text-on-surface focus:ring-2 focus:ring-primary focus:border-primary outline-none text-sm placeholder:text-outline">
                </div>
            </div>
        </div>

        <!-- Submit -->
        <button type="submit"
                class="w-full bg-primary hover:bg-primary-container text-on-primary font-label-md text-label-md py-3 rounded-lg shadow-sm transition-colors flex items-center justify-center space-x-2 mt-2">
            <span class="material-symbols-outlined text-[20px]" style="font-variation-settings:'FILL' 1;">person_add</span>
            <span>Create Account</span>
        </button>
    </form>

    <p class="text-center text-sm text-on-surface-variant pb-6">
        Already have an account? <a class="text-primary hover:underline font-semibold" href="login.php">Sign in here</a>
    </p>

</div>
</section>
</main>

<script>
// ── Password visibility toggle ────────────────────────────────────────────────
document.querySelectorAll('.toggle-password').forEach(btn => {
    btn.addEventListener('click', function() {
        const inp  = document.getElementById(this.dataset.target);
        const icon = this.querySelector('.material-symbols-outlined');
        const show = inp.type === 'password';
        inp.type         = show ? 'text' : 'password';
        icon.textContent = show ? 'visibility' : 'visibility_off';
    });
});

// ── Live password rule indicators ─────────────────────────────────────────────
const pwInput = document.getElementById('password');
const reqs = {
    len:   document.getElementById('req-len'),
    upper: document.getElementById('req-upper'),
    lower: document.getElementById('req-lower'),
    num:   document.getElementById('req-num'),
    spec:  document.getElementById('req-spec'),
};
pwInput.addEventListener('input', function() {
    const v = this.value;
    reqs.len.classList.toggle('valid-req',   v.length >= 8);
    reqs.upper.classList.toggle('valid-req', /[A-Z]/.test(v));
    reqs.lower.classList.toggle('valid-req', /[a-z]/.test(v));
    reqs.num.classList.toggle('valid-req',   /[0-9]/.test(v));
    reqs.spec.classList.toggle('valid-req',  /[^A-Za-z0-9]/.test(v));
});

// ── Location cascade (same JSON data as apply_business.php) ──────────────────
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
    } catch(e) {
        console.error('Location load failed:', LOC_BASE + f, e);
        return null;
    }
}

async function initLocation() {
    [_regions, _provinces, _cities, _barangays] = await Promise.all([
        loadJson('regions.json'), loadJson('provinces.json'),
        loadJson('cities.json'),  loadJson('barangays.json')
    ]);
    const sel = document.getElementById('reg_region');
    if (!_regions || !sel) return;
    sel.innerHTML = '<option value="">— Select Region —</option>';
    _regions.forEach(r => {
        const o = document.createElement('option');
        o.value = r.name; o.textContent = r.name;
        sel.appendChild(o);
    });
    sel.disabled = false;
}

function resetSelect(id, placeholder, disable = true) {
    const s = document.getElementById(id);
    s.innerHTML = `<option value="">${placeholder}</option>`;
    s.disabled = disable;
    s.value = '';
}

function onRegRegion() {
    const rName = document.getElementById('reg_region').value;
    const rCode = (_regions || []).find(r => r.name === rName)?.code || '';
    resetSelect('reg_province', '— Select Province —');
    resetSelect('reg_city',     '— Select City —');
    resetSelect('reg_barangay', '— Select Barangay —');
    if (!rCode || !_provinces) return;
    const filtered = _provinces.filter(p => p.regionCode === rCode)
                                .sort((a,b) => a.name.localeCompare(b.name));
    const ps = document.getElementById('reg_province');
    ps.innerHTML = '<option value="">— Select Province —</option>';
    filtered.forEach(p => {
        const o = document.createElement('option');
        o.value = p.name; o.textContent = p.name;
        ps.appendChild(o);
    });
    ps.disabled = false;
}

function onRegProvince() {
    const pName = document.getElementById('reg_province').value;
    const pCode = (_provinces || []).find(p => p.name === pName)?.code || '';
    resetSelect('reg_city',     '— Select City —');
    resetSelect('reg_barangay', '— Select Barangay —');
    if (!pCode || !_cities) return;
    const filtered = _cities.filter(c => c.provinceCode === pCode)
                             .sort((a,b) => a.name.localeCompare(b.name));
    const cs = document.getElementById('reg_city');
    cs.innerHTML = '<option value="">— Select City —</option>';
    filtered.forEach(c => {
        const o = document.createElement('option');
        o.value = c.name; o.textContent = c.name;
        o.dataset.code = c.code;
        cs.appendChild(o);
    });
    cs.disabled = false;
}

function onRegCity() {
    const cs   = document.getElementById('reg_city');
    const opt  = cs.options[cs.selectedIndex];
    const code = opt?.dataset?.code || opt?.value || '';
    resetSelect('reg_barangay', '— Select Barangay —');
    const names = (_barangays && _barangays[code]) || [];
    if (!names.length) return;
    const bs = document.getElementById('reg_barangay');
    bs.innerHTML = '<option value="">— Select Barangay —</option>';
    [...names].sort().forEach(b => {
        const o = document.createElement('option');
        o.value = b; o.textContent = b;
        bs.appendChild(o);
    });
    bs.disabled = false;
}

// Attach change handlers
document.getElementById('reg_region')  ?.addEventListener('change', onRegRegion);
document.getElementById('reg_province')?.addEventListener('change', onRegProvince);
document.getElementById('reg_city')    ?.addEventListener('change', onRegCity);

// Boot
initLocation();
</script>
</body>
</html>
