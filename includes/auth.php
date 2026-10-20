<?php
declare(strict_types=1);

require_once __DIR__ . '/db.php';
require_once __DIR__ . '/functions.php';
require_once __DIR__ . '/permissions.php';

if (session_status() !== PHP_SESSION_ACTIVE) {
    $https = isset($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off';
    ini_set('session.use_strict_mode', '1');
    ini_set('session.use_only_cookies', '1');
    session_set_cookie_params([
        'httponly' => true,
        'secure' => $https,
        'samesite' => 'Lax',
        'path' => '/',
    ]);
    session_start();
}

ensure_notifications_table(db());

if (isset($_SESSION['user_id']) && isset($_SESSION['last_activity'])) {
    if (time() - (int) $_SESSION['last_activity'] > SESSION_IDLE_TIMEOUT) {
        $_SESSION = [];
        session_destroy();
        session_start();
        session_regenerate_id(true);
        flash('error', 'انتهت الجلسة بسبب عدم النشاط. يرجى تسجيل الدخول مجددًا.');
    }
}
if (isset($_SESSION['user_id'])) {
    $activeCheck = db()->prepare('SELECT name, username, role, is_active FROM users WHERE id = ?');
    $activeCheck->execute([(int) $_SESSION['user_id']]);
    $sessionUser = $activeCheck->fetch();
    if (!$sessionUser || (int) $sessionUser['is_active'] !== 1) {
        $_SESSION = [];
        session_regenerate_id(true);
        flash('error', 'تم تعطيل الحساب أو لم يعد موجودًا. تواصلي مع الأدمن.');
    } else {
        $_SESSION['user_name'] = $sessionUser['name'];
        $_SESSION['username'] = $sessionUser['username'];
        $_SESSION['role'] = $sessionUser['role'];
    }
    $_SESSION['last_activity'] = time();
}

function current_user(): ?array
{
    return isset($_SESSION['user_id']) ? [
        'id' => (int) $_SESSION['user_id'],
        'name' => (string) $_SESSION['user_name'],
        'username' => (string) $_SESSION['username'],
        'role' => (string) $_SESSION['role'],
    ] : null;
}

function require_role(array $allowedRoles): void
{
    $user = current_user();
    if ($user === null) {
        redirect('/login.php');
    }
    if (!in_array($user['role'], $allowedRoles, true)) {
        http_response_code(403);
        require BASE_PATH . '/includes/403.php';
        exit;
    }
}

function login_user(array $user): void
{
    session_regenerate_id(true);
    unset($_SESSION['csrf_token']);
    $_SESSION['user_id'] = (int) $user['id'];
    $_SESSION['user_name'] = (string) $user['name'];
    $_SESSION['username'] = (string) $user['username'];
    $_SESSION['role'] = (string) $user['role'];
    $_SESSION['last_activity'] = time();
}

function csrf_token(): string
{
    if (empty($_SESSION['csrf_token'])) {
        $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
    }
    return $_SESSION['csrf_token'];
}

function csrf_field(): string
{
    return '<input type="hidden" name="csrf_token" value="' . e(csrf_token()) . '">';
}

function verify_csrf(): void
{
    $token = $_POST['csrf_token'] ?? $_SERVER['HTTP_X_CSRF_TOKEN'] ?? '';
    if (!is_string($token) || !hash_equals(csrf_token(), $token)) {
        http_response_code(419);
        exit('انتهت صلاحية الطلب. أعد تحميل الصفحة وحاول مرة أخرى.');
    }
}
