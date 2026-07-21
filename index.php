<?php
// /index.php
// MartFlow POS - Router Index

require_once __DIR__ . '/includes/functions.php';

if (!is_logged_in()) {
    header("Location: /login.php");
    exit();
}

$user = get_logged_user();
if ($user['role'] === 'CASHIER') {
    header("Location: /cashier/pos.php");
    exit();
} else if ($user['role'] === 'ADMIN' || $user['role'] === 'MANAGER') {
    header("Location: /admin/dashboard.php");
    exit();
} else {
    // Unknown role? Clear session and login again
    header("Location: /logout.php");
    exit();
}
