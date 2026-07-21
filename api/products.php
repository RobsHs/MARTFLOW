<?php
// /api/products.php
// MartFlow POS - Products API Endpoints

require_once __DIR__ . '/../includes/functions.php';
require_login();

$action = $_GET['action'] ?? '';
$db = Database::getConnection();

if ($_SERVER['REQUEST_METHOD'] === 'GET') {
    
    // --- SEARCH PRODUCTS BY BARCODE ---
    if ($action === 'barcode') {
        $barcode = trim($_GET['barcode'] ?? '');
        if (empty($barcode)) {
            json_response(false, "Barcode tidak boleh kosong!");
        }

        try {
            // Find active product with matching barcode
            $stmt = $db->prepare("SELECT * FROM products WHERE barcode = :barcode AND status = 'ACTIVE' LIMIT 1");
            $stmt->execute(['barcode' => $barcode]);
            $product = $stmt->fetch();

            if ($product) {
                // Check if product is out of stock
                if ($product['stock'] <= 0) {
                    json_response(false, "Stok produk '" . $product['name'] . "' habis!");
                }
                
                // Get active promo
                $promo = get_product_promo($product['id'], $product['category_id'], floatval($product['sell_price']));
                $product['promo'] = $promo;
                
                json_response(true, "Produk ditemukan", $product);
            } else {
                json_response(false, "Produk dengan barcode '$barcode' tidak ditemukan atau dinonaktifkan.");
            }
        } catch (Exception $e) {
            json_response(false, "Database error: " . $e->getMessage());
        }
    }
    
    // --- GENERAL PRODUCT SEARCH (BY NAME, SKU, OR CATEGORY) ---
    if ($action === 'search') {
        $q = trim($_GET['q'] ?? '');
        $cat_id = trim($_GET['category_id'] ?? '');

        try {
            $sql = "SELECT p.*, c.name as category_name 
                    FROM products p
                    LEFT JOIN categories c ON p.category_id = c.id
                    WHERE p.status = 'ACTIVE'";
            $params = [];

            if (!empty($q)) {
                $sql .= " AND (p.name LIKE :q OR p.sku LIKE :q OR p.barcode LIKE :q)";
                $params['q'] = "%$q%";
            }

            if (!empty($cat_id)) {
                $sql .= " AND p.category_id = :cat_id";
                $params['cat_id'] = $cat_id;
            }

            $sql .= " ORDER BY p.name ASC LIMIT 30";
            
            $stmt = $db->prepare($sql);
            $stmt->execute($params);
            $products = $stmt->fetchAll();

            // Append active promotions to each product
            foreach ($products as &$p) {
                $p['promo'] = get_product_promo($p['id'], $p['category_id'], floatval($p['sell_price']));
            }
            unset($p);

            json_response(true, "Pencarian produk berhasil", $products);
        } catch (Exception $e) {
            json_response(false, "Database error: " . $e->getMessage());
        }
    }
}

json_response(false, "Aksi API tidak didukung!");
