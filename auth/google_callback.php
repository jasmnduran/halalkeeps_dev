<?php
require_once __DIR__ . '/../config/app.php';

/**
 * Google OAuth 2.0 Callback
 * Handles both sign-in and sign-up via Google.
 */

// ── Helper: abort with a user-visible flash and redirect to login ─────────────
function oauth_fail(string $msg): never {
    $_SESSION['flash_message'] = $msg;
    $_SESSION['flash_type']    = 'danger';
    header('Location: ' . BASE_URL . 'auth/login.php');
    exit();
}

// ── 1. Check for an error response from Google ────────────────────────────────
if (isset($_GET['error'])) {
    $google_error = htmlspecialchars($_GET['error']);
    oauth_fail("Google sign-in was cancelled or denied ({$google_error}). Please try again.");
}

// ── 2. Require the authorisation code ────────────────────────────────────────
if (empty($_GET['code'])) {
    oauth_fail('No authorisation code received from Google. Please try again.');
}

// ── 3. CSRF state verification ────────────────────────────────────────────────
$received_state = $_GET['state'] ?? '';
$expected_state = $_SESSION['oauth_state'] ?? '';

// Always clear the stored state — it is single-use
unset($_SESSION['oauth_state']);

if (empty($expected_state) || !hash_equals($expected_state, $received_state)) {
    oauth_fail('Security check failed (state mismatch). Please try signing in again.');
}

// ── 4. Exchange code for access token ─────────────────────────────────────────
try {
    $token_data = getGoogleAccessToken($_GET['code']);

    if (empty($token_data) || isset($token_data['error'])) {
        $detail = $token_data['error_description'] ?? $token_data['error'] ?? 'unknown error';
        throw new RuntimeException("Could not obtain access token from Google: {$detail}");
    }

    $access_token = $token_data['access_token'] ?? '';
    if (empty($access_token)) {
        throw new RuntimeException('Google returned an empty access token.');
    }

    // ── 5. Fetch user profile from Google ─────────────────────────────────────
    $user_info = getGoogleUserInfo($access_token);

    if (empty($user_info['email'])) {
        throw new RuntimeException('Could not retrieve your email address from Google. Please ensure your Google account has a verified email.');
    }

    $google_id  = $user_info['sub']          ?? '';
    $email      = $user_info['email']        ?? '';
    $full_name  = $user_info['name']         ?? '';
    $first_name = $user_info['given_name']   ?? '';
    $last_name  = $user_info['family_name']  ?? '';
    $avatar     = $user_info['picture']      ?? '';

    // ── 6. Look up or create the user account ─────────────────────────────────
    $stmt = $conn->prepare(
        "SELECT u.*, r.role_name
         FROM users u
         LEFT JOIN roles r ON u.role_id = r.id
         WHERE u.google_id = ? OR u.email = ?
         LIMIT 1"
    );
    $stmt->bind_param("ss", $google_id, $email);
    $stmt->execute();
    $user = $stmt->get_result()->fetch_assoc();
    $stmt->close();

    if ($user) {
        // ── Existing user: refresh Google data ────────────────────────────────
        // Only update avatar if Google provides one
        $new_avatar = !empty($avatar) ? $avatar : $user['avatar'];

        $upd = $conn->prepare(
            "UPDATE users
             SET google_id = ?, avatar = ?, last_login = NOW(), email_verified = 1
             WHERE id = ?"
        );
        $upd->bind_param("ssi", $google_id, $new_avatar, $user['id']);
        $upd->execute();
        $upd->close();

        // Re-fetch so the session gets the freshest data
        $stmt2 = $conn->prepare(
            "SELECT u.*, r.role_name
             FROM users u
             LEFT JOIN roles r ON u.role_id = r.id
             WHERE u.id = ?"
        );
        $stmt2->bind_param("i", $user['id']);
        $stmt2->execute();
        $user = $stmt2->get_result()->fetch_assoc();
        $stmt2->close();

        logActivity($conn, $user['id'], 'Google Login', 'User logged in via Google OAuth', 'auth');

    } else {
        // ── New user: register with Customer role ──────────────────────────────
        $customer_role_id = ROLE_CUSTOMER;

        $ins = $conn->prepare(
            "INSERT INTO users
                 (google_id, email, full_name, first_name, last_name,
                  avatar, email_verified, role_id, role_status, last_login)
             VALUES (?, ?, ?, ?, ?, ?, 1, ?, 'approved', NOW())"
        );
        $ins->bind_param(
            "ssssssi",
            $google_id, $email, $full_name,
            $first_name, $last_name, $avatar,
            $customer_role_id
        );

        if (!$ins->execute()) {
            throw new RuntimeException('Failed to create your account. Please try again.');
        }

        $new_id = (int)$conn->insert_id;
        $ins->close();

        // Fetch the newly created user
        $stmt2 = $conn->prepare(
            "SELECT u.*, r.role_name
             FROM users u
             LEFT JOIN roles r ON u.role_id = r.id
             WHERE u.id = ?"
        );
        $stmt2->bind_param("i", $new_id);
        $stmt2->execute();
        $user = $stmt2->get_result()->fetch_assoc();
        $stmt2->close();

        logActivity($conn, $new_id, 'Google Registration', 'New user registered via Google OAuth', 'auth');
    }

    // ── 7. Guarantee a Customer role, build session, redirect ─────────────────
    autoAssignCustomerRole($conn, $user);
    createUserSession($user);
    header('Location: ' . getDashboardUrl($user['role_id']));
    exit();

} catch (RuntimeException $e) {
    oauth_fail('Google sign-in failed: ' . $e->getMessage());
}
