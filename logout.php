<?php
// /logout.php
// MartFlow POS - Secure Logout

require_once __DIR__ . '/includes/functions.php';

if (is_logged_in()) {
    write_log("User logged out");
}

// Clear all session variables
$_SESSION = [];

// Destroy session cookies
if (ini_get("session.use_cookies")) {
    $params = session_get_cookie_params();
    setcookie(session_name(), '', time() - 42000,
        $params["path"], $params["domain"],
        $params["secure"], $params["httponly"]
    );
}

// Destroy the session
session_destroy();

header("Location: /login.php");
exit();
