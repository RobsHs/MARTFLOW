<?php
// /config/database.php
// MartFlow POS - Database Connection and Initializer

class Database {
    private static $conn = null;

    public static function getConnection() {
        if (self::$conn === null) {
            $config = include(__DIR__ . '/app.php');
            $db_type = isset($config['db_type']) ? $config['db_type'] : 'sqlite';

            if ($db_type === 'mysql') {
                try {
                    $host = $config['db_host'];
                    $dbname = $config['db_name'];
                    $username = $config['db_user'];
                    $password = $config['db_pass'];

                    self::$conn = new PDO("mysql:host=$host;dbname=$dbname;charset=utf8", $username, $password);
                    self::$conn->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
                    self::$conn->setAttribute(PDO::ATTR_DEFAULT_FETCH_MODE, PDO::FETCH_ASSOC);
                } catch (PDOException $e) {
                    // If MySQL fails, fallback to SQLite to prevent app crash in preview mode
                    error_log("MySQL connection failed, falling back to SQLite: " . $e->getMessage());
                    self::$conn = self::initSQLite();
                }
            } else {
                self::$conn = self::initSQLite();
            }
        }
        return self::$conn;
    }

    private static function initSQLite() {
        $db_dir = __DIR__ . '/../database';
        if (!is_dir($db_dir)) {
            mkdir($db_dir, 0777, true);
        }
        $db_file = $db_dir . '/database.sqlite';
        $is_new = !file_exists($db_file);

        try {
            $conn = new PDO("sqlite:" . $db_file);
            $conn->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
            $conn->setAttribute(PDO::ATTR_DEFAULT_FETCH_MODE, PDO::FETCH_ASSOC);
            $conn->exec("PRAGMA foreign_keys = ON;");

            if ($is_new) {
                self::buildSQLiteSchema($conn);
                self::seedSQLiteData($conn);
            }

            return $conn;
        } catch (PDOException $e) {
            die("SQLite Initialization failed: " . $e->getMessage());
        }
    }

    private static function buildSQLiteSchema($conn) {
        $schema_path = __DIR__ . '/../database/schema.sql';
        if (!file_exists($schema_path)) {
            return;
        }

        $sql = file_get_contents($schema_path);

        // Convert MySQL syntax to SQLite compatible syntax
        $sql = preg_replace('/INT AUTO_INCREMENT PRIMARY KEY/i', 'INTEGER PRIMARY KEY AUTOINCREMENT', $sql);
        $sql = preg_replace('/INT\s/i', 'INTEGER ', $sql);
        $sql = preg_replace('/DECIMAL\(\d+,\s*\d+\)/i', 'REAL', $sql);
        $sql = preg_replace('/TINYINT\(1\)/i', 'INTEGER', $sql);
        $sql = preg_replace('/ON UPDATE CURRENT_TIMESTAMP/i', '', $sql);
        $sql = preg_replace('/FOREIGN KEY\s*\(([^)]+)\)\s*REFERENCES\s*([^(]+)\([^)]+\)\s*ON DELETE CASCADE/i', 'FOREIGN KEY ($1) REFERENCES $2(id) ON DELETE CASCADE', $sql);
        $sql = preg_replace('/FOREIGN KEY\s*\(([^)]+)\)\s*REFERENCES\s*([^(]+)\([^)]+\)\s*ON DELETE SET NULL/i', 'FOREIGN KEY ($1) REFERENCES $2(id) ON DELETE SET NULL', $sql);
        $sql = preg_replace('/FOREIGN KEY\s*\(([^)]+)\)\s*REFERENCES\s*([^(]+)\([^)]+\)/i', 'FOREIGN KEY ($1) REFERENCES $2(id)', $sql);

        // SQLite cannot execute multi-queries at once in standard PDO exec if they are complex.
        // Split queries by semicolon (making sure not to split within quotes, but a simple split works if structured nicely)
        $queries = explode(';', $sql);
        foreach ($queries as $query) {
            $query = trim($query);
            if (!empty($query)) {
                try {
                    $conn->exec($query);
                } catch (PDOException $e) {
                    error_log("Error executing query: " . $query . "\nError: " . $e->getMessage());
                }
            }
        }
    }

    private static function seedSQLiteData($conn) {
        // 1. Seed Users (ADMIN, MANAGER, CASHIER)
        $users = [
            [
                'username' => 'admin',
                'password' => password_hash('Admin123!', PASSWORD_BCRYPT),
                'name' => 'Administrator MartFlow',
                'role' => 'ADMIN',
                'status' => 'ACTIVE'
            ],
            [
                'username' => 'manager',
                'password' => password_hash('Manager123!', PASSWORD_BCRYPT),
                'name' => 'Budi Santoso (Manager)',
                'role' => 'MANAGER',
                'status' => 'ACTIVE'
            ],
            [
                'username' => 'cashier',
                'password' => password_hash('Cashier123!', PASSWORD_BCRYPT),
                'name' => 'Siti Aminah (Kasir)',
                'role' => 'CASHIER',
                'status' => 'ACTIVE'
            ]
        ];

        $stmt = $conn->prepare("INSERT INTO users (username, password, name, role, status) VALUES (:username, :password, :name, :role, :status)");
        foreach ($users as $user) {
            $stmt->execute($user);
        }

        // 2. Seed Categories
        $categories = [
            ['name' => 'Makanan', 'description' => 'Makanan instan, snack, bahan makanan pokok'],
            ['name' => 'Minuman', 'description' => 'Air mineral, jus, soda, susu, teh, kopi'],
            ['name' => 'Snack', 'description' => 'Kue, keripik, cokelat, permen'],
            ['name' => 'Kebutuhan Rumah', 'description' => 'Sabun cuci, pewangi, baterai, kebersihan'],
            ['name' => 'Personal Care', 'description' => 'Sabun mandi, shampo, pasta gigi, parfum'],
            ['name' => 'ATK', 'description' => 'Alat tulis kantor, buku, pulpen, kertas'],
            ['name' => 'Elektronik', 'description' => 'Aksesoris handphone, lampu, stopkontak'],
            ['name' => 'Lainnya', 'description' => 'Barang-barang umum lainnya']
        ];

        $stmtCat = $conn->prepare("INSERT INTO categories (name, description) VALUES (:name, :description)");
        foreach ($categories as $cat) {
            $stmtCat->execute($cat);
        }

        // 3. Seed Suppliers
        $suppliers = [
            [
                'name' => 'PT Indofood CBP',
                'contact_name' => 'Hendra Wijaya',
                'phone' => '081234567890',
                'email' => 'sales@indofood.com',
                'address' => 'Kawasan Industri Sudirman, Jakarta',
                'notes' => 'Supplier mie instan, bumbu, minuman'
            ],
            [
                'name' => 'PT Unilever Indonesia',
                'contact_name' => 'Dewi Lestari',
                'phone' => '081198765432',
                'email' => 'distributor@unilever.co.id',
                'address' => 'BSD City, Tangerang',
                'notes' => 'Supplier sabun, shampoo, produk personal care'
            ],
            [
                'name' => 'CV Sumber Makmur',
                'contact_name' => 'Rudi Hermawan',
                'phone' => '085712345678',
                'email' => 'sumbermakmur@gmail.com',
                'address' => 'Pasar Induk Kramat Jati, Jakarta',
                'notes' => 'Supplier beras, minyak goreng, kebutuhan pokok'
            ]
        ];

        $stmtSup = $conn->prepare("INSERT INTO suppliers (name, contact_name, phone, email, address, notes) VALUES (:name, :contact_name, :phone, :email, :address, :notes)");
        foreach ($suppliers as $sup) {
            $stmtSup->execute($sup);
        }

        // 4. Seed Customers
        $customers = [
            [
                'member_id' => 'MBR-0001',
                'name' => 'Ahmad Fauzi',
                'phone' => '08991234567',
                'email' => 'ahmad@example.com',
                'address' => 'Jl. Mawar No. 12, Jakarta',
                'points' => 250,
                'level' => 'BRONZE',
                'total_spend' => 250000.00
            ],
            [
                'member_id' => 'MBR-0002',
                'name' => 'Linda Permata',
                'phone' => '08779876543',
                'email' => 'linda.p@example.com',
                'address' => 'Apartemen Green Pramuka, Jakarta',
                'points' => 1250,
                'level' => 'SILVER',
                'total_spend' => 1250000.00
            ],
            [
                'member_id' => 'MBR-0003',
                'name' => 'Rendra Pratama',
                'phone' => '08128888777',
                'email' => 'rendra@example.com',
                'address' => 'Perumahan Pondok Indah, Jakarta',
                'points' => 5400,
                'level' => 'GOLD',
                'total_spend' => 5400000.00
            ]
        ];

        $stmtCust = $conn->prepare("INSERT INTO customers (member_id, name, phone, email, address, points, level, total_spend) VALUES (:member_id, :name, :phone, :email, :address, :points, :level, :total_spend)");
        foreach ($customers as $cust) {
            $stmtCust->execute($cust);
        }

        // 5. Seed Products
        $products = [
            // Makanan (Cat 1)
            [
                'sku' => 'PRD-IND-001',
                'barcode' => '8998866200257',
                'name' => 'Indomie Goreng Spesial',
                'category_id' => 1,
                'supplier_id' => 1,
                'buy_price' => 2600.00,
                'sell_price' => 3100.00,
                'stock' => 120,
                'min_stock' => 15,
                'unit' => 'Pcs',
                'status' => 'ACTIVE'
            ],
            [
                'sku' => 'PRD-IND-002',
                'barcode' => '8998866200219',
                'name' => 'Indomie Rasa Soto Lamongan',
                'category_id' => 1,
                'supplier_id' => 1,
                'buy_price' => 2550.00,
                'sell_price' => 3000.00,
                'stock' => 80,
                'min_stock' => 15,
                'unit' => 'Pcs',
                'status' => 'ACTIVE'
            ],
            // Minuman (Cat 2)
            [
                'sku' => 'PRD-MIN-001',
                'barcode' => '8996001301053',
                'name' => 'Aqua Air Mineral 600ml',
                'category_id' => 2,
                'supplier_id' => 1,
                'buy_price' => 2500.00,
                'sell_price' => 3500.00,
                'stock' => 200,
                'min_stock' => 20,
                'unit' => 'Pcs',
                'status' => 'ACTIVE'
            ],
            [
                'sku' => 'PRD-MIN-002',
                'barcode' => '8992761111032',
                'name' => 'Teh Botol Sosro 450ml',
                'category_id' => 2,
                'supplier_id' => 1,
                'buy_price' => 4500.00,
                'sell_price' => 6000.00,
                'stock' => 4, // Low Stock trigger test
                'min_stock' => 10,
                'unit' => 'Pcs',
                'status' => 'ACTIVE'
            ],
            // Snack (Cat 3)
            [
                'sku' => 'PRD-SNC-001',
                'barcode' => '8992681121012',
                'name' => 'Chitato Sapi Panggang 68g',
                'category_id' => 3,
                'supplier_id' => 1,
                'buy_price' => 8500.00,
                'sell_price' => 11500.00,
                'stock' => 45,
                'min_stock' => 10,
                'unit' => 'Pcs',
                'status' => 'ACTIVE'
            ],
            // Kebutuhan Rumah (Cat 4)
            [
                'sku' => 'PRD-KRT-001',
                'barcode' => '8999999042523',
                'name' => 'Rinso Liquid Deterjen 750ml',
                'category_id' => 4,
                'supplier_id' => 2,
                'buy_price' => 17500.00,
                'sell_price' => 21000.00,
                'stock' => 0, // Out of Stock trigger test
                'min_stock' => 5,
                'unit' => 'Pcs',
                'status' => 'ACTIVE'
            ],
            // Personal Care (Cat 5)
            [
                'sku' => 'PRD-PCA-001',
                'barcode' => '8999999002442',
                'name' => 'Pepsodent Pencegah Gigi Berlubang 190g',
                'category_id' => 5,
                'supplier_id' => 2,
                'buy_price' => 10500.00,
                'sell_price' => 13500.00,
                'stock' => 60,
                'min_stock' => 8,
                'unit' => 'Pcs',
                'status' => 'ACTIVE'
            ]
        ];

        $stmtProd = $conn->prepare("INSERT INTO products (sku, barcode, name, category_id, supplier_id, buy_price, sell_price, stock, min_stock, unit, status) VALUES (:sku, :barcode, :name, :category_id, :supplier_id, :buy_price, :sell_price, :stock, :min_stock, :unit, :status)");
        foreach ($products as $prod) {
            $stmtProd->execute($prod);
        }

        // 6. Seed Promotions (Buy 1 Get 1 and percentage diskon)
        $promotions = [
            [
                'name' => 'Diskon Merdeka 10%',
                'type' => 'PERCENTAGE',
                'value' => 10.00,
                'start_date' => '2026-01-01',
                'end_date' => '2027-12-31',
                'active' => 1,
                'category_id' => null,
                'product_id' => 1 // Indomie Goreng
            ],
            [
                'name' => 'Minuman Murah PPN',
                'type' => 'NOMINAL',
                'value' => 500.00,
                'start_date' => '2026-01-01',
                'end_date' => '2027-12-31',
                'active' => 1,
                'category_id' => 2, // Minuman category discount
                'product_id' => null
            ]
        ];

        $stmtProm = $conn->prepare("INSERT INTO promotions (name, type, value, start_date, end_date, active, category_id, product_id) VALUES (:name, :type, :value, :start_date, :end_date, :active, :category_id, :product_id)");
        foreach ($promotions as $prom) {
            $stmtProm->execute($prom);
        }
        
        // 7. Seed Initial Inventory movements
        $stmtMov = $conn->prepare("INSERT INTO inventory_movements (product_id, type, qty, stock_before, stock_after, reason, user_id) VALUES (:product_id, :type, :qty, :stock_before, :stock_after, :reason, :user_id)");
        for ($i = 1; $i <= 7; $i++) {
            $p_stmt = $conn->prepare("SELECT stock FROM products WHERE id = ?");
            $p_stmt->execute([$i]);
            $prod_stock = $p_stmt->fetchColumn();
            
            $stmtMov->execute([
                'product_id' => $i,
                'type' => 'IN',
                'qty' => $prod_stock,
                'stock_before' => 0,
                'stock_after' => $prod_stock,
                'reason' => 'Stok awal sistem',
                'user_id' => 1
            ]);
        }
    }
}
