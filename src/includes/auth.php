<?php
// ============================================================
// includes/auth.php  —  Session & authentication helpers
// ============================================================

require_once __DIR__ . '/../config/config.php';
require_once __DIR__ . '/../config/db.php';

/**
 * Check whether a user is currently logged in via session.
 *
 * @return bool True if a valid user_id exists in the session.
 */
function isLoggedIn(): bool {
    return isset($_SESSION['user_id']) && !empty($_SESSION['user_id']);
}

/**
 * Enforce authentication. Redirects to login page if the user is not logged in.
 *
 * @param string $redirect Path relative to APP_URL to redirect to. Defaults to login page.
 * @return void
 */
function requireLogin(string $redirect = '/pages/login.php'): void {
    if (!isLoggedIn()) {
        header('Location: ' . APP_URL . $redirect);
        exit;
    }
}

/**
 * Enforce role-based access control. Redirects to the 403 unauthorized page
 * if the current user's role is not in the allowed list.
 *
 * @param string|array $roles A single role string or array of permitted roles.
 * @return void
 */
function requireRole(string|array $roles): void {
    requireLogin();
    $roles = (array)$roles;
    if (!in_array($_SESSION['user_role'], $roles, true)) {
        header('Location: ' . APP_URL . '/pages/unauthorized.php');
        exit;
    }
}

/**
 * Return the current user's data from the session.
 *
 * @return array Associative array with keys: user_id, full_name, email, role.
 */
function currentUser(): array {
    return [
        'user_id'   => $_SESSION['user_id']   ?? null,
        'full_name' => $_SESSION['full_name']  ?? '',
        'email'     => $_SESSION['email']      ?? '',
        'role'      => $_SESSION['user_role']  ?? '',
    ];
}

/**
 * Validate credentials and start an authenticated session.
 * Regenerates the session ID on success to prevent session fixation.
 *
 * @param string $email    The user's email address.
 * @param string $password The plaintext password to verify against the stored hash.
 * @return array ['success' => bool, 'message' => string] on failure,
 *               ['success' => true, 'role' => string] on success.
 */
function loginUser(string $email, string $password): array {
    $db   = getDB();
    $stmt = $db->prepare('SELECT * FROM users WHERE email = ? AND is_active = 1 LIMIT 1');
    $stmt->execute([trim($email)]);
    $user = $stmt->fetch();

    if (!$user || !password_verify($password, $user['password_hash'])) {
        return ['success' => false, 'message' => 'Invalid email or password.'];
    }

    // Regenerate session ID on login (session fixation prevention)
    session_regenerate_id(true);

    $_SESSION['user_id']   = $user['user_id'];
    $_SESSION['full_name'] = $user['full_name'];
    $_SESSION['email']     = $user['email'];
    $_SESSION['user_role'] = $user['role'];

    return ['success' => true, 'role' => $user['role']];
}

/**
 * Register a new user account with a bcrypt-hashed password.
 * Invalid roles are silently coerced to 'elder'.
 *
 * @param string $fullName The user's display name.
 * @param string $email    The user's email address (must be unique).
 * @param string $password The plaintext password to hash and store.
 * @param string $role     Account role: 'elder', 'caregiver', or 'admin'. Defaults to 'elder'.
 * @return array ['success' => bool, 'message' => string] on failure,
 *               ['success' => true, 'user_id' => int] on success.
 */
function registerUser(string $fullName, string $email, string $password, string $role = 'elder'): array {
    $db = getDB();

    // Check duplicate email
    $stmt = $db->prepare('SELECT user_id FROM users WHERE email = ? LIMIT 1');
    $stmt->execute([trim($email)]);
    if ($stmt->fetch()) {
        return ['success' => false, 'message' => 'That email is already registered.'];
    }

    $allowedRoles = ['elder', 'caregiver', 'admin'];
    if (!in_array($role, $allowedRoles, true)) {
        $role = 'elder';
    }

    $hash = password_hash($password, PASSWORD_BCRYPT, ['cost' => 12]);
    $stmt = $db->prepare('INSERT INTO users (full_name, email, password_hash, role) VALUES (?, ?, ?, ?)');
    $stmt->execute([trim($fullName), trim($email), $hash, $role]);

    return ['success' => true, 'user_id' => $db->lastInsertId()];
}

/**
 * Destroy the current session and clear the session cookie.
 *
 * @return void
 */
function logoutUser(): void {
    $_SESSION = [];
    if (ini_get('session.use_cookies')) {
        $params = session_get_cookie_params();
        setcookie(session_name(), '', time() - 42000,
            $params['path'], $params['domain'],
            $params['secure'], $params['httponly']
        );
    }
    session_destroy();
}

/**
 * Generate or retrieve the CSRF token for the current session.
 *
 * @return string A 64-character hex CSRF token.
 */
function csrfToken(): string {
    if (empty($_SESSION['csrf_token'])) {
        $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
    }
    return $_SESSION['csrf_token'];
}

/**
 * Validate a submitted CSRF token against the one stored in the session.
 * Uses timing-safe comparison to prevent timing attacks.
 *
 * @param string $token The token submitted with the form.
 * @return bool True if the token matches the session token.
 */
function verifyCsrf(string $token): bool {
    return isset($_SESSION['csrf_token']) && hash_equals($_SESSION['csrf_token'], $token);
}

/**
 * Render a hidden HTML input field containing the current CSRF token.
 * Include inside every state-changing form.
 *
 * @return string HTML input element string.
 */
function csrfField(): string {
    return '<input type="hidden" name="csrf_token" value="' . htmlspecialchars(csrfToken()) . '">';
}

/**
 * Store a one-time flash message in the session for display on the next page load.
 *
 * @param string $type    Message category: 'success', 'danger', 'warning', or 'info'.
 * @param string $message The message text to display.
 * @return void
 */
function setFlash(string $type, string $message): void {
    $_SESSION['flash'] = ['type' => $type, 'message' => $message];
}

/**
 * Retrieve and clear the current flash message from the session.
 *
 * @return array|null Associative array with 'type' and 'message', or null if none set.
 */
function getFlash(): ?array {
    if (isset($_SESSION['flash'])) {
        $flash = $_SESSION['flash'];
        unset($_SESSION['flash']);
        return $flash;
    }
    return null;
}
