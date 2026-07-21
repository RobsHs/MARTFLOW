<?php
// /api/transactions.php
// MartFlow POS - Transaction API Endpoints

require_once __DIR__ . '/../includes/functions.php';
require_login();

$action = $_GET['action'] ?? '';
$db = Database::getConnection();

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    
    // --- SEED DEMO HISTORICAL TRANSACTIONS ---
    if ($action === 'seed_demo') {
        require_role(['ADMIN', 'MANAGER']);
        try {
            $db->beginTransaction();
            
            // Delete any existing transactions to reseed cleanly if requested
            $db->exec("DELETE FROM transaction_items");
            $db->exec("DELETE FROM transactions");
            $db->exec("DELETE FROM inventory_movements WHERE reason LIKE '%demo%'");
            
            // Reset stock of products first to make sure there is stock
            $db->exec("UPDATE products SET stock = 150 WHERE sku = 'PRD-IND-001'");
            $db->exec("UPDATE products SET stock = 120 WHERE sku = 'PRD-IND-002'");
            $db->exec("UPDATE products SET stock = 200 WHERE sku = 'PRD-MIN-001'");
            $db->exec("UPDATE products SET stock = 50 WHERE sku = 'PRD-MIN-002'");
            $db->exec("UPDATE products SET stock = 80 WHERE sku = 'PRD-SNC-001'");
            $db->exec("UPDATE products SET stock = 40 WHERE sku = 'PRD-KRT-001'");
            $db->exec("UPDATE products SET stock = 90 WHERE sku = 'PRD-PCA-001'");

            // Payment methods
            $methods = ['CASH', 'QRIS', 'DEBIT', 'CREDIT', 'E-WALLET'];
            
            // Seed a few members
            // Customer IDs are 1, 2, 3
            // Products:
            // 1: Indomie Goreng (3100)
            // 2: Indomie Soto (3000)
            // 3: Aqua 600ml (3500)
            // 4: Teh Botol Sosro (6000)
            // 5: Chitato (11500)
            // 6: Rinso Liquid (21000)
            // 7: Pepsodent (13500)
            
            // Create sales for the last 7 days
            for ($i = 6; $i >= 0; $i--) {
                $days_ago = date('Y-m-d H:i:s', strtotime("-$i days " . rand(8, 20) . ":" . rand(10, 59) . ":" . rand(10, 59)));
                
                // 1 to 3 transactions per day
                $tx_per_day = rand(1, 3);
                for ($j = 0; $j < $tx_per_day; $j++) {
                    $invoice_no = 'INV-' . date('Ymd', strtotime($days_ago)) . '-' . str_pad(rand(100, 999), 3, '0', STR_PAD_LEFT);
                    $payment = $methods[array_rand($methods)];
                    $cust_id = rand(0, 3); // 0 means Guest, 1-3 are seeded members
                    $cust_id = $cust_id === 0 ? null : $cust_id;
                    $cashier_id = rand(1, 3); // Seeded users are Admin (1), Manager (2), Cashier (3)
                    
                    // Select 1 to 4 random products
                    $num_items = rand(1, 4);
                    $selected_items = [];
                    $subtotal = 0;
                    $discount_total = 0;
                    
                    for ($k = 0; $k < $num_items; $k++) {
                        $p_id = rand(1, 7);
                        if (in_array($p_id, $selected_items)) continue; // skip duplicates in same transaction
                        
                        $selected_items[] = $p_id;
                        $qty = rand(1, 5);
                        
                        // Get product prices
                        $p_stmt = $db->prepare("SELECT buy_price, sell_price, name FROM products WHERE id = ?");
                        $p_stmt->execute([$p_id]);
                        $prod = $p_stmt->fetch();
                        
                        $buy = floatval($prod['buy_price']);
                        $sell = floatval($prod['sell_price']);
                        
                        // Check random discount
                        $disc = (rand(1, 10) > 8) ? (rand(1, 5) * 500) : 0; // occasional discount of 500, 1000, 1500, 2000, 2500
                        
                        $item_subtotal = ($sell - $disc) * $qty;
                        $subtotal += $sell * $qty;
                        $discount_total += $disc * $qty;
                    }
                    
                    if (empty($selected_items)) continue;
                    
                    $tax = round($subtotal * 0.11);
                    $total = $subtotal - $discount_total + $tax;
                    
                    $paid = ceil($total / 5000) * 5000; // round up for paying amount
                    if ($payment !== 'CASH') $paid = $total;
                    $change = $paid - $total;
                    
                    // Insert transaction
                    $stmt_tx = $db->prepare("
                        INSERT INTO transactions (invoice_no, customer_id, user_id, subtotal, discount, tax, total, payment_method, amount_paid, change_amount, created_at) 
                        VALUES (:invoice_no, :customer_id, :user_id, :subtotal, :discount, :tax, :total, :payment_method, :amount_paid, :change_amount, :created_at)
                    ");
                    $stmt_tx->execute([
                        'invoice_no' => $invoice_no,
                        'customer_id' => $cust_id,
                        'user_id' => $cashier_id,
                        'subtotal' => $subtotal,
                        'discount' => $discount_total,
                        'tax' => $tax,
                        'total' => $total,
                        'payment_method' => $payment,
                        'amount_paid' => $paid,
                        'change_amount' => $change,
                        'created_at' => $days_ago
                    ]);
                    $tx_id = $db->lastInsertId();
                    
                    // Insert items and decrease stock
                    foreach ($selected_items as $p_id) {
                        $qty = rand(1, 5);
                        
                        $p_stmt = $db->prepare("SELECT buy_price, sell_price, stock FROM products WHERE id = ?");
                        $p_stmt->execute([$p_id]);
                        $prod = $p_stmt->fetch();
                        
                        $buy = floatval($prod['buy_price']);
                        $sell = floatval($prod['sell_price']);
                        $stock_before = intval($prod['stock']);
                        $stock_after = $stock_before - $qty;
                        
                        $disc = (rand(1, 10) > 8) ? (rand(1, 5) * 500) : 0;
                        $item_sub = ($sell - $disc) * $qty;
                        
                        // Insert item
                        $stmt_item = $db->prepare("
                            INSERT INTO transaction_items (transaction_id, product_id, qty, buy_price, sell_price, discount, subtotal, created_at) 
                            VALUES (:tx_id, :product_id, :qty, :buy_price, :sell_price, :discount, :subtotal, :created_at)
                        ");
                        $stmt_item->execute([
                            'tx_id' => $tx_id,
                            'product_id' => $p_id,
                            'qty' => $qty,
                            'buy_price' => $buy,
                            'sell_price' => $sell,
                            'discount' => $disc,
                            'subtotal' => $item_sub,
                            'created_at' => $days_ago
                        ]);
                        
                        // Update stock
                        $db->prepare("UPDATE products SET stock = ? WHERE id = ?")->execute([$stock_after, $p_id]);
                        
                        // Insert movement
                        $db->prepare("
                            INSERT INTO inventory_movements (product_id, type, qty, stock_before, stock_after, reason, user_id, created_at) 
                            VALUES (?, 'OUT', ?, ?, ?, 'Transaksi POS demo', ?, ?)
                        ")->execute([$p_id, $qty, $stock_before, $stock_after, $cashier_id, $days_ago]);
                    }
                    
                    // If customer, update customer stats
                    if ($cust_id) {
                        $points_gained = round($total * 0.01);
                        $db->prepare("
                            UPDATE customers 
                            SET total_spend = total_spend + :total, 
                                points = points + :points 
                            WHERE id = :cust_id
                        ")->execute([
                            'total' => $total,
                            'points' => $points_gained,
                            'cust_id' => $cust_id
                        ]);
                    }
                }
            }
            
            $db->commit();
            write_log("Imported transaction demo data successfully");
            json_response(true, "Data demo transaksi berhasil di-import!");
        } catch (Exception $e) {
            $db->rollBack();
            json_response(false, "Gagal mengimpor data demo: " . $e->getMessage());
        }
    }

    // --- POS CHECKOUT TRANSACTION ---
    if ($action === 'checkout') {
        $data = json_decode(file_get_contents('php://input'), true);
        if (!$data) {
            json_response(false, "Data transaksi tidak valid!");
        }

        $items = $data['items'] ?? [];
        $customer_id = $data['customer_id'] ?? null;
        $discount_tx = floatval($data['discount_tx'] ?? 0);
        $tax_rate = floatval($data['tax_rate'] ?? 0.11);
        $payment_method = strtoupper($data['payment_method'] ?? 'CASH');
        $amount_paid = floatval($data['amount_paid'] ?? 0);
        
        if (empty($items)) {
            json_response(false, "Keranjang belanja kosong!");
        }

        try {
            $db->beginTransaction();

            $subtotal = 0;
            $discount_items_total = 0;
            
            // Validate items and calculate totals
            foreach ($items as &$item) {
                $p_id = intval($item['product_id']);
                $qty = intval($item['qty']);
                
                // Get product information
                $p_stmt = $db->prepare("SELECT * FROM products WHERE id = :id LIMIT 1");
                $p_stmt->execute(['id' => $p_id]);
                $product = $p_stmt->fetch();
                
                if (!$product) {
                    throw new Exception("Produk ID $p_id tidak ditemukan!");
                }
                if ($product['status'] !== 'ACTIVE') {
                    throw new Exception("Produk " . $product['name'] . " sedang tidak aktif!");
                }
                if ($product['stock'] < $qty) {
                    throw new Exception("Stok untuk " . $product['name'] . " tidak mencukupi! Sisa stok: " . $product['stock']);
                }

                $item['buy_price'] = floatval($product['buy_price']);
                $item['sell_price'] = floatval($product['sell_price']);
                $item['discount'] = floatval($item['discount'] ?? 0); // Per item discount
                
                $item_subtotal = ($item['sell_price'] - $item['discount']) * $qty;
                $item['subtotal'] = $item_subtotal;

                $subtotal += $product['sell_price'] * $qty;
                $discount_items_total += $item['discount'] * $qty;
                
                $item['stock_before'] = intval($product['stock']);
            }
            unset($item);

            // Calculate final transaction details
            $total_discount = $discount_items_total + $discount_tx;
            $tax_amount = round(($subtotal - $total_discount) * $tax_rate);
            $total_payable = max(0, $subtotal - $total_discount + $tax_amount);

            // Validate cash payment
            if ($payment_method === 'CASH' && $amount_paid < $total_payable) {
                throw new Exception("Uang yang dibayarkan kurang! Total tagihan: " . format_rupiah($total_payable) . ", Dibayar: " . format_rupiah($amount_paid));
            }

            $change_amount = 0;
            if ($payment_method === 'CASH') {
                $change_amount = $amount_paid - $total_payable;
            } else {
                $amount_paid = $total_payable; // Non-cash payments are always paid exact
            }

            // Create automatic Invoice Number
            // Format: INV-YYYYMMDD-XXXX
            $today_str = date('Ymd');
            $inv_stmt = $db->query("SELECT invoice_no FROM transactions WHERE invoice_no LIKE 'INV-$today_str-%' ORDER BY id DESC LIMIT 1");
            $last_invoice = $inv_stmt->fetchColumn();
            
            if ($last_invoice) {
                $parts = explode('-', $last_invoice);
                $num = intval($parts[2]) + 1;
                $invoice_no = 'INV-' . $today_str . '-' . str_pad($num, 4, '0', STR_PAD_LEFT);
            } else {
                $invoice_no = 'INV-' . $today_str . '-0001';
            }

            // Insert Transaction record
            $user_id = $_SESSION['user_id'];
            $stmt_tx = $db->prepare("
                INSERT INTO transactions (invoice_no, customer_id, user_id, subtotal, discount, tax, total, payment_method, amount_paid, change_amount) 
                VALUES (:invoice_no, :customer_id, :user_id, :subtotal, :discount, :tax, :total, :payment_method, :amount_paid, :change_amount)
            ");
            $stmt_tx->execute([
                'invoice_no' => $invoice_no,
                'customer_id' => $customer_id,
                'user_id' => $user_id,
                'subtotal' => $subtotal,
                'discount' => $total_discount,
                'tax' => $tax_amount,
                'total' => $total_payable,
                'payment_method' => $payment_method,
                'amount_paid' => $amount_paid,
                'change_amount' => $change_amount
            ]);
            $transaction_id = $db->lastInsertId();

            // Insert Transaction Items & Update Stock
            $stmt_item = $db->prepare("
                INSERT INTO transaction_items (transaction_id, product_id, qty, buy_price, sell_price, discount, subtotal) 
                VALUES (:tx_id, :product_id, :qty, :buy_price, :sell_price, :discount, :subtotal)
            ");
            
            $stmt_stock_update = $db->prepare("UPDATE products SET stock = :new_stock WHERE id = :id");
            
            $stmt_movement = $db->prepare("
                INSERT INTO inventory_movements (product_id, type, qty, stock_before, stock_after, reason, user_id) 
                VALUES (:product_id, 'OUT', :qty, :before, :after, :reason, :user_id)
            ");

            foreach ($items as $item) {
                $p_id = $item['product_id'];
                $qty = $item['qty'];
                $new_stock = $item['stock_before'] - $qty;

                // 1. Write item record
                $stmt_item->execute([
                    'tx_id' => $transaction_id,
                    'product_id' => $p_id,
                    'qty' => $qty,
                    'buy_price' => $item['buy_price'],
                    'sell_price' => $item['sell_price'],
                    'discount' => $item['discount'],
                    'subtotal' => $item['subtotal']
                ]);

                // 2. Reduce stock
                $stmt_stock_update->execute([
                    'new_stock' => $new_stock,
                    'id' => $p_id
                ]);

                // 3. Log stock movement
                $stmt_movement->execute([
                    'product_id' => $p_id,
                    'qty' => $qty,
                    'before' => $item['stock_before'],
                    'after' => $new_stock,
                    'reason' => "Transaksi POS: $invoice_no",
                    'user_id' => $user_id
                ]);
            }

            // If member, update spend and points
            if ($customer_id) {
                $gained_points = floor($total_payable * $app_config['points_multiplier']);
                
                // Update customer total spend & points
                $stmt_cust_up = $db->prepare("
                    UPDATE customers 
                    SET total_spend = total_spend + :spend, 
                        points = points + :points 
                    WHERE id = :id
                ");
                $stmt_cust_up->execute([
                    'spend' => $total_payable,
                    'points' => $gained_points,
                    'id' => $customer_id
                ]);

                // Fetch new total spend to adjust member level
                $spend_stmt = $db->prepare("SELECT total_spend FROM customers WHERE id = ?");
                $spend_stmt->execute([$customer_id]);
                $new_spend = floatval($spend_stmt->fetchColumn());

                // Auto level members based on spending thresholds
                $new_level = 'BRONZE';
                if ($new_spend >= 5000000) {
                    $new_level = 'GOLD';
                } else if ($new_spend >= 1000000) {
                    $new_level = 'SILVER';
                }

                $db->prepare("UPDATE customers SET level = ? WHERE id = ?")->execute([$new_level, $customer_id]);
            }

            // Log activity
            write_log("Processed POS transaction $invoice_no, total: " . format_rupiah($total_payable));

            $db->commit();
            
            json_response(true, "Transaksi berhasil diproses!", [
                'invoice_no' => $invoice_no,
                'transaction_id' => $transaction_id,
                'total' => $total_payable,
                'change_amount' => $change_amount
            ]);

        } catch (Exception $e) {
            $db->rollBack();
            json_response(false, "Terjadi kesalahan checkout: " . $e->getMessage());
        }
    }
}

// --- GET MEMBER DATA BY ID OR PHONE ---
if ($_SERVER['REQUEST_METHOD'] === 'GET') {
    if ($action === 'get_member') {
        $query = trim($_GET['q'] ?? '');
        if (empty($query)) {
            json_response(false, "Kueri pencarian kosong!");
        }

        try {
            $stmt = $db->prepare("
                SELECT * FROM customers 
                WHERE member_id = :query OR phone = :query LIMIT 1
            ");
            $stmt->execute(['query' => $query]);
            $member = $stmt->fetch();

            if ($member) {
                json_response(true, "Member ditemukan", $member);
            } else {
                json_response(false, "Member tidak ditemukan");
            }
        } catch (Exception $e) {
            json_response(false, "Database error: " . $e->getMessage());
        }
    }
}

json_response(false, "Metode / aksi tidak valid!");
