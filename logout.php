<?php
require_once __DIR__ . '/includes/auth.php';
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    header('Allow: POST');
    exit('طريقة الطلب غير مسموحة.');
}
verify_csrf();
$user = current_user();
if ($user !== null) {
    audit_log(db(), 'logout', 'user', $user['id'], 'تسجيل خروج', ['username' => $user['username']]);
}
$_SESSION = [];
if (ini_get('session.use_cookies')) {
    $params = session_get_cookie_params();
    setcookie(session_name(), '', time() - 42000, $params['path'], $params['domain'], $params['secure'], $params['httponly']);
}
session_destroy();
redirect('/login.php');
