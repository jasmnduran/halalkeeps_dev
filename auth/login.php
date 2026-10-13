<?php
require_once __DIR__ . '/../config/app.php';

// Redirect if already logged in — always go straight to their dashboard
if (isLoggedIn()) {
    header('Location: ' . getDashboardUrl($_SESSION['role_id'] ?? ROLE_CUSTOMER));
    exit();
}

$error = '';

// Consume any flash message set by google_callback.php
if (!empty($_SESSION['flash_message'])) {
    $error = $_SESSION['flash_message'];
    unset($_SESSION['flash_message'], $_SESSION['flash_type']);
}

// Show success message when redirected here after registration
$success = (!empty($_GET['registered'])) ? 'Account created successfully! Please sign in.' : '';

// Handle traditional login form
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $email = sanitize($_POST['email'] ?? '');
    $password = $_POST['password'] ?? '';
    
    if (empty($email) || empty($password)) {
        $error = 'Please fill in all fields.';
    } else {
        $stmt = $conn->prepare("SELECT u.*, r.role_name FROM users u LEFT JOIN roles r ON u.role_id = r.id WHERE u.email = ?");
        $stmt->bind_param("s", $email);
        $stmt->execute();
        $result = $stmt->get_result();
        
        if ($user = $result->fetch_assoc()) {
            // Check if account is locked
            if (!empty($user['account_locked'])) {
                $error = 'Your account has been locked. Please contact the administrator.';
            } elseif (!empty($user['password']) && password_verify($password, $user['password'])) {
                // Successful login — reset failed attempts
                $conn->query("UPDATE users SET failed_login_attempts=0, account_locked=0 WHERE id=" . intval($user['id']));

                // Update last login
                $update = $conn->prepare("UPDATE users SET last_login = NOW() WHERE id = ?");
                $update->bind_param("i", $user['id']);
                $update->execute();

                logActivity($conn, $user['id'], 'Login', 'User logged in via email/password', 'auth');

                // Guarantee a Customer role for users who have none, then
                // build the session and go straight to their dashboard.
                autoAssignCustomerRole($conn, $user);
                createUserSession($user);
                header('Location: ' . getDashboardUrl($user['role_id']));
                exit();
            } else {
                // Failed login — increment counter
                $new_attempts = intval($user['failed_login_attempts'] ?? 0) + 1;
                $lock = $new_attempts >= MAX_LOGIN_ATTEMPTS ? 1 : 0;
                $conn->query("UPDATE users SET failed_login_attempts=$new_attempts, account_locked=$lock WHERE id=" . intval($user['id']));
                
                if ($lock) {
                    $error = 'Too many failed attempts. Your account has been locked. Contact the administrator.';
                } else {
                    $remaining = MAX_LOGIN_ATTEMPTS - $new_attempts;
                    $error = 'Invalid email or password. ' . $remaining . ' attempt(s) remaining before lockout.';
                }
            }
        } else {
            $error = 'No account found with this email. Please sign up first.';
        }
    }
}

// Generate Google OAuth URL
$google_auth_url = getGoogleAuthUrl();
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="utf-8">
<meta content="width=device-width, initial-scale=1.0" name="viewport">
<title>Halal Keeps - Login</title>
<script src="https://cdn.tailwindcss.com?plugins=forms,container-queries"></script>
<link href="https://fonts.googleapis.com/css2?family=Manrope:wght@400;600&amp;family=Plus+Jakarta+Sans:wght@600;700&amp;display=swap" rel="stylesheet">
<link href="https://fonts.googleapis.com/css2?family=Material+Symbols+Outlined:wght,FILL@100..700,0..1&amp;display=swap" rel="stylesheet">
<script id="tailwind-config">
        tailwind.config = {
            darkMode: "class",
            theme: {
                extend: {
                    "colors": {
                        "on-tertiary-fixed": "#241a00",
                        "surface-container-highest": "#e1e3e4",
                        "on-tertiary": "#ffffff",
                        "tertiary-fixed": "#ffe088",
                        "on-tertiary-container": "#4e3d00",
                        "surface-container-high": "#e6e8e9",
                        "error": "#ba1a1a",
                        "on-error": "#ffffff",
                        "inverse-surface": "#2e3132",
                        "on-secondary-fixed-variant": "#00504c",
                        "error-container": "#ffdad6",
                        "on-primary-fixed-variant": "#005137",
                        "secondary-fixed": "#7cf6ec",
                        "on-surface-variant": "#3f4943",
                        "surface-variant": "#e1e3e4",
                        "primary-fixed-dim": "#89d6b0",
                        "on-surface": "#191c1d",
                        "on-secondary-fixed": "#00201e",
                        "on-secondary-container": "#00716b",
                        "inverse-on-surface": "#eff1f2",
                        "primary": "#00452e",
                        "secondary": "#006a65",
                        "surface-dim": "#d8dadb",
                        "surface-container-lowest": "#ffffff",
                        "on-primary-fixed": "#002114",
                        "outline-variant": "#bec9c1",
                        "surface-bright": "#f8fafb",
                        "on-tertiary-fixed-variant": "#574500",
                        "on-secondary": "#ffffff",
                        "on-primary-container": "#89d6b0",
                        "secondary-container": "#7cf6ec",
                        "tertiary-container": "#cba72f",
                        "background": "#f8fafb",
                        "primary-container": "#005f41",
                        "surface-container": "#eceeef",
                        "on-error-container": "#93000a",
                        "on-primary": "#ffffff",
                        "surface-tint": "#186b4c",
                        "primary-fixed": "#a4f3cb",
                        "surface": "#f8fafb",
                        "surface-container-low": "#f2f4f5",
                        "tertiary": "#735c00",
                        "inverse-primary": "#89d6b0",
                        "secondary-fixed-dim": "#5dd9d0",
                        "tertiary-fixed-dim": "#e9c349",
                        "outline": "#6f7a73",
                        "on-background": "#191c1d"
                    },
                    "borderRadius": {
                        "DEFAULT": "0.25rem",
                        "lg": "0.5rem",
                        "xl": "0.75rem",
                        "full": "9999px"
                    },
                    "spacing": {
                        "base": "8px",
                        "card-padding": "20px",
                        "container-margin": "24px",
                        "gutter": "16px",
                        "section-gap": "40px"
                    },
                    "fontFamily": {
                        "headline-md": ["Plus Jakarta Sans"],
                        "display-lg": ["Plus Jakarta Sans"],
                        "headline-lg": ["Plus Jakarta Sans"],
                        "label-md": ["Manrope"],
                        "body-md": ["Manrope"],
                        "body-lg": ["Manrope"],
                        "stats-number": ["Plus Jakarta Sans"],
                        "headline-lg-mobile": ["Plus Jakarta Sans"]
                    },
                    "fontSize": {
                        "headline-md": ["24px", { "lineHeight": "32px", "fontWeight": "600" }],
                        "display-lg": ["48px", { "lineHeight": "56px", "letterSpacing": "-0.02em", "fontWeight": "700" }],
                        "headline-lg": ["32px", { "lineHeight": "40px", "fontWeight": "700" }],
                        "label-md": ["14px", { "lineHeight": "20px", "fontWeight": "600" }],
                        "body-md": ["16px", { "lineHeight": "24px", "fontWeight": "400" }],
                        "body-lg": ["18px", { "lineHeight": "28px", "fontWeight": "400" }],
                        "stats-number": ["28px", { "lineHeight": "34px", "fontWeight": "700" }],
                        "headline-lg-mobile": ["26px", { "lineHeight": "32px", "fontWeight": "700" }]
                    }
                }
            }
        }
    </script>
<style>
        .pattern-bg {
            background-color: #005f41; /* primary-container */
            background-image: radial-gradient(rgba(255, 255, 255, 0.1) 1px, transparent 1px);
            background-size: 20px 20px;
        }
        /* Browser autofill background override */
        input:-webkit-autofill,
        input:-webkit-autofill:hover, 
        input:-webkit-autofill:focus {
            -webkit-box-shadow: 0 0 0px 1000px #f2f4f5 inset !important;
            -webkit-text-fill-color: #191c1d !important;
            transition: background-color 5000s ease-in-out 0s;
        }
    </style>
</head>
<body class="bg-background text-on-background min-h-screen flex font-body-md antialiased">

<main class="w-full min-h-screen flex flex-col lg:flex-row">
<!-- Left Side: Branding & Trust Metrics -->
<section class="hidden lg:flex lg:w-1/2 pattern-bg text-on-primary flex-col justify-center items-center p-section-gap relative">
<div class="absolute inset-0 bg-gradient-to-b from-primary/30 to-primary/80 z-0"></div>
<div class="z-10 flex flex-col items-center text-center max-w-lg space-y-6">
<!-- Logo -->
<div class="w-24 h-24 rounded-2xl flex items-center justify-center shadow-lg border border-primary-fixed/30 overflow-hidden">
    <img alt="Halal Keeps Logo" class="w-full h-full object-cover" src="<?= BASE_URL ?>assets/images/logo.jpg">
</div>
<h1 class="font-display-lg text-display-lg text-on-primary">Halal Keeps</h1>
<p class="font-body-lg text-body-lg text-primary-fixed-dim">Your trusted partner in keeping your business halal. Sign in to access the marketplace, inventory-mapping, and market analysis for business owners.</p>
</div>
</section>

<!-- Right Side: Login Form -->
<section class="w-full lg:w-1/2 bg-surface flex flex-col p-container-margin overflow-y-auto min-h-screen">
<!-- Back link -->
<div class="w-full max-w-md mx-auto pt-gutter pb-6">
<a class="inline-flex items-center text-secondary font-label-md text-label-md hover:text-primary transition-colors" href="../index.php">
<span class="material-symbols-outlined mr-2 text-[20px]">arrow_back</span>
                Back to Home
            </a>
</div>

<div class="flex-1 flex flex-col justify-center max-w-md mx-auto w-full py-4">
<div class="mb-6">
<h2 class="font-headline-lg text-headline-lg text-on-surface mb-1">Welcome Back</h2>
<p class="font-body-md text-body-md text-on-surface-variant">Sign in to your account to continue</p>
</div>

<!-- Dynamic Error Alert -->
<?php if (!empty($success)): ?>
<div class="mb-6 p-3.5 rounded-lg bg-secondary-container text-on-secondary-container border border-secondary/20 font-body-md text-sm flex items-center space-x-2">
    <span class="material-symbols-outlined text-secondary text-[20px]">check_circle</span>
    <span><?php echo htmlspecialchars($success); ?></span>
</div>
<?php endif; ?>
<?php if (!empty($error)): ?>
<div class="mb-6 p-3.5 rounded-lg bg-error-container text-on-error-container border border-error/20 font-body-md text-sm flex items-center space-x-2">
    <span class="material-symbols-outlined text-error text-[20px]">error</span>
    <span><?php echo htmlspecialchars($error); ?></span>
</div>
<?php endif; ?>

<!-- Social Login -->
<button class="w-full flex items-center justify-center py-3 px-4 border border-outline-variant rounded-lg bg-surface hover:bg-surface-container-low transition-colors mb-6 text-on-surface font-label-md text-label-md shadow-sm" type="button" onclick="window.location.href='<?php echo htmlspecialchars($google_auth_url); ?>'">
<svg class="w-5 h-5 mr-3" viewBox="0 0 24 24" xmlns="http://www.w3.org/2000/svg">
<path d="M22.56 12.25c0-.78-.07-1.53-.2-2.25H12v4.26h5.92c-.26 1.37-1.04 2.53-2.21 3.31v2.77h3.57c2.08-1.92 3.28-4.74 3.28-8.09z" fill="#4285F4"></path>
<path d="M12 23c2.97 0 5.46-.98 7.28-2.66l-3.57-2.77c-.98.66-2.23 1.06-3.71 1.06-2.86 0-5.29-1.93-6.16-4.53H2.18v2.84C3.99 20.53 7.7 23 12 23z" fill="#34A853"></path>
<path d="M5.84 14.09c-.22-.66-.35-1.36-.35-2.09s.13-1.43.35-2.09V7.07H2.18C1.43 8.55 1 10.22 1 12s.43 3.45 1.18 4.93l2.85-2.22.81-.62z" fill="#FBBC05"></path>
<path d="M12 5.38c1.62 0 3.06.56 4.21 1.64l3.15-3.15C17.45 2.09 14.97 1 12 1 7.7 1 3.99 3.47 2.18 7.07l3.66 2.84c.87-2.6 3.3-4.53 6.16-4.53z" fill="#EA4335"></path>
</svg>
                Sign in with Google
            </button>

<!-- Divider -->
<div class="relative flex items-center mb-6">
<div class="flex-grow border-t border-outline-variant"></div>
<span class="flex-shrink-0 mx-4 font-body-md text-sm text-outline">or sign in with email</span>
<div class="flex-grow border-t border-outline-variant"></div>
</div>

<!-- Login Form -->
<form action="<?php echo htmlspecialchars($_SERVER['PHP_SELF']); ?>" class="space-y-5" method="POST">
<div class="space-y-1.5">
<label class="block font-label-md text-label-md text-on-surface" for="email">
                        Email Address <span class="text-error">*</span>
</label>
<input class="w-full px-4 py-3 bg-surface-container-low border border-outline-variant rounded-lg focus:ring-2 focus:ring-primary focus:border-primary transition-shadow text-on-surface font-body-md text-body-md outline-none placeholder:text-outline" id="email" name="email" placeholder="name@example.com" required type="email">
</div>

<div class="space-y-1.5">
<label class="block font-label-md text-label-md text-on-surface" for="password">
                        Password <span class="text-error">*</span>
</label>
<div class="relative">
<input class="w-full px-4 py-3 pr-10 bg-surface-container-low border border-outline-variant rounded-lg focus:ring-2 focus:ring-primary focus:border-primary transition-shadow text-on-surface font-body-md text-body-md outline-none placeholder:text-outline" id="password" name="password" placeholder="Enter your password" required type="password">
<button aria-label="Toggle password visibility" class="absolute inset-y-0 right-0 pr-3 flex items-center text-outline hover:text-on-surface transition-colors" id="togglePassword" type="button">
<span class="material-symbols-outlined text-[20px]">visibility_off</span>
</button>
</div>
</div>

<button class="w-full py-3.5 px-6 bg-primary text-on-primary rounded-lg font-label-md text-label-md flex items-center justify-center hover:bg-primary-container transition-colors shadow-sm mt-2" type="submit">
<span class="material-symbols-outlined mr-2 text-[20px]">login</span>
                    Sign In
                </button>
</form>

<div class="mt-8 text-center">
<p class="font-body-md text-body-md text-on-surface-variant">
                    Don't have an account? 
                    <a class="font-label-md text-label-md text-secondary hover:text-primary transition-colors hover:underline" href="register.php">Create Account</a>
</p>
</div>
</div>
</section>
</main>

<script>
    // Toggle Password Visibility
    const togglePassword = document.getElementById('togglePassword');
    const passwordInput = document.getElementById('password');

    if (togglePassword && passwordInput) {
        togglePassword.addEventListener('click', function () {
            const isPassword = passwordInput.type === 'password';
            passwordInput.type = isPassword ? 'text' : 'password';
            this.querySelector('.material-symbols-outlined').textContent = isPassword ? 'visibility' : 'visibility_off';
        });
    }
</script>
</body>
</html>