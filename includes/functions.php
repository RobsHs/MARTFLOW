<?php
// /includes/functions.php
// MartFlow POS - Shared Helper Functions & Security

if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

require_once __DIR__ . '/../config/database.php';

// Set Timezone
$app_config = include(__DIR__ . '/../config/app.php');
date_default_timezone_set($app_config['timezone']);

/**
 * Escape output to prevent XSS
 */
function esc($str) {
    return htmlspecialchars($str ?? '', ENT_QUOTES, 'UTF-8');
}

/**
 * Format number to Indonesian Rupiah (Rp)
 */
function format_rupiah($number) {
    return 'Rp ' . number_format($number, 0, ',', '.');
}

/**
 * Generate CSRF Token for Form Security
 */
function csrf_token() {
    if (empty($_SESSION['csrf_token'])) {
        $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
    }
    return $_SESSION['csrf_token'];
}

/**
 * Verify CSRF Token
 */
function verify_csrf_token($token) {
    if (!isset($_SESSION['csrf_token']) || empty($token)) {
        return false;
    }
    return hash_equals($_SESSION['csrf_token'], $token);
}

/**
 * Authentication & Access Control Helpers
 */
function is_logged_in() {
    return isset($_SESSION['user_id']) && !empty($_SESSION['user_id']);
}

function get_logged_user() {
    if (!is_logged_in()) return null;
    return [
        'id' => $_SESSION['user_id'],
        'username' => $_SESSION['username'],
        'name' => $_SESSION['name'],
        'role' => $_SESSION['role']
    ];
}

function require_login() {
    if (!is_logged_in()) {
        header("Location: /login.php");
        exit();
    }
}

function require_role($allowed_roles) {
    require_login();
    
    if (is_string($allowed_roles)) {
        $allowed_roles = [$allowed_roles];
    }
    
    $user_role = $_SESSION['role'];
    if (!in_array($user_role, $allowed_roles)) {
        // Redirect to access denied page
        header("Location: /access-denied.php");
        exit();
    }
}

/**
 * Write to Activity Logs Table
 */
function write_log($activity) {
    try {
        $db = Database::getConnection();
        $user_id = isset($_SESSION['user_id']) ? $_SESSION['user_id'] : null;
        $username = isset($_SESSION['username']) ? $_SESSION['username'] : 'System/Guest';
        $ip = $_SERVER['REMOTE_ADDR'] ?? '127.0.0.1';

        $stmt = $db->prepare("INSERT INTO activity_logs (user_id, username, activity, ip_address) VALUES (:user_id, :username, :activity, :ip_address)");
        $stmt->execute([
            'user_id' => $user_id,
            'username' => $username,
            'activity' => $activity,
            'ip_address' => $ip
        ]);
    } catch (Exception $e) {
        // Fail silently to avoid breaking the application for a log failure
        error_log("Failed to write log: " . $e->getMessage());
    }
}

/**
 * JSON Response helper
 */
function json_response($success, $message, $data = [], $status_code = 200) {
    http_response_code($status_code);
    header('Content-Type: application/json');
    echo json_encode([
        'success' => $success,
        'message' => $message,
        'data' => $data
    ]);
    exit();
}

/**
 * Calculate Promo / Discount for a product
 */
function get_product_promo($product_id, $category_id, $sell_price) {
    try {
        $db = Database::getConnection();
        $today = date('Y-m-d');
        
        // Find active promotions matching either the product_id or category_id
        $stmt = $db->prepare("
            SELECT * FROM promotions 
            WHERE active = 1 
              AND (product_id = :prod_id OR category_id = :cat_id)
              AND start_date <= :today 
              AND end_date >= :today
            ORDER BY type = 'PERCENTAGE' DESC, value DESC LIMIT 1
        ");
        $stmt->execute([
            'prod_id' => $product_id,
            'cat_id' => $category_id,
            'today' => $today
        ]);
        
        $promo = $stmt->fetch();
        if ($promo) {
            $discount_amount = 0;
            if ($promo['type'] === 'PERCENTAGE') {
                $discount_amount = $sell_price * ($promo['value'] / 100);
            } else if ($promo['type'] === 'NOMINAL') {
                $discount_amount = $promo['value'];
            } else if ($promo['type'] === 'BUY_1_GET_1' || $promo['type'] === 'BUY_2_GET_1') {
                // Return promotional tag
                return [
                    'id' => $promo['id'],
                    'name' => $promo['name'],
                    'type' => $promo['type'],
                    'discount' => 0,
                    'sell_price' => $sell_price,
                    'promo_price' => $sell_price,
                    'is_bundle' => true
                ];
            }
            
            return [
                'id' => $promo['id'],
                'name' => $promo['name'],
                'type' => $promo['type'],
                'discount' => $discount_amount,
                'sell_price' => $sell_price,
                'promo_price' => max(0, $sell_price - $discount_amount),
                'is_bundle' => false
            ];
        }
    } catch (Exception $e) {
        error_log("Error in get_product_promo: " . $e->getMessage());
    }
    
    return [
        'id' => null,
        'name' => '',
        'type' => '',
        'discount' => 0,
        'sell_price' => $sell_price,
        'promo_price' => $sell_price,
        'is_bundle' => false
    ];
}
