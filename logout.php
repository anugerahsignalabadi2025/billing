<?php
session_start();
if (!empty($_SESSION['authenticated'])) {
    require_once __DIR__ . '/admin_helpers.php';
    billing_log_activity(
        $_SESSION['username'] ?? 'admin',
        'Logout',
        'logout.php',
        null,
        isset($_SESSION['admin_user_id']) ? (int) $_SESSION['admin_user_id'] : null
    );
}
$_SESSION = [];
if (ini_get('session.use_cookies')) {
    $params = session_get_cookie_params();
    setcookie(session_name(), '', time() - 42000, $params['path'], $params['domain'], $params['secure'], $params['httponly']);
}
session_destroy();

header("Location: login.php");
exit;
?>