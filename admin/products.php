<?php
// /admin/products.php
// MartFlow POS - Products Catalog Management

require_once __DIR__ . '/../includes/functions.php';
require_role(['ADMIN', 'MANAGER']);

$page_title = 'Kelola Produk';
$db = Database::getConnection();

// --- POST HANDLERS (CRUD ACTION ENGINE) ---
$error = '';
$success = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = $_POST['action'] ?? '';
    $token = $_POST['csrf_token'] ?? '';

    // CSRF Check
    if (!verify_csrf_token($token)) {
        $error = 'Validasi keamanan CSRF gagal. Silakan coba lagi.';
    } else {
        // --- ADD OR EDIT PRODUCT ---
        if ($action === 'save') {
            $id = isset($_POST['id']) && !empty($_POST['id']) ? intval($_POST['id']) : null;
            $sku = strtoupper(trim($_POST['sku'] ?? ''));
            $barcode = trim($_POST['barcode'] ?? '');
            $name = trim($_POST['name'] ?? '');
            $category_id = !empty($_POST['category_id']) ? intval($_POST['category_id']) : null;
            $supplier_id = !empty($_POST['supplier_id']) ? intval($_POST['supplier_id']) : null;
            $buy_price = floatval($_POST['buy_price'] ?? 0);
            $sell_price = floatval($_POST['sell_price'] ?? 0);
            $stock = intval($_POST['stock'] ?? 0);
            $min_stock = intval($_POST['min_stock'] ?? 5);
            $unit = trim($_POST['unit'] ?? 'Pcs');
            $status = trim($_POST['status'] ?? 'ACTIVE');

            // Validations
            if (empty($sku) || empty($barcode) || empty($name)) {
                $error = 'SKU, Barcode, dan Nama Produk wajib diisi!';
            } else if ($buy_price < 0 || $sell_price < 0 || $stock < 0 || $min_stock < 0) {
                $error = 'Angka harga atau stok tidak boleh bernilai negatif!';
            } else {
                try {
                    // Check SKU uniqueness
                    $sku_check = $db->prepare("SELECT id FROM products WHERE sku = :sku AND id != :id");
                    $sku_check->execute(['sku' => $sku, 'id' => $id ?? 0]);
                    
                    // Check Barcode uniqueness
                    $bar_check = $db->prepare("SELECT id FROM products WHERE barcode = :barcode AND id != :id");
                    $bar_check->execute(['barcode' => $barcode, 'id' => $id ?? 0]);

                    if ($sku_check->fetch()) {
                        $error = "Gagal menyimpan: SKU '$sku' sudah digunakan oleh produk lain.";
                    } else if ($bar_check->fetch()) {
                        $error = "Gagal menyimpan: Barcode '$barcode' sudah digunakan oleh produk lain.";
                    } else {
                        if ($id) {
                            // EDIT PRODUCT
                            // Fetch stock before update to log stock change if modified
                            $s_stmt = $db->prepare("SELECT stock FROM products WHERE id = ?");
                            $s_stmt->execute([$id]);
                            $old_stock = intval($s_stmt->fetchColumn());

                            $stmt = $db->prepare("
                                UPDATE products SET 
                                    sku = :sku, barcode = :barcode, name = :name, 
                                    category_id = :category_id, supplier_id = :supplier_id, 
                                    buy_price = :buy_price, sell_price = :sell_price, 
                                    stock = :stock, min_stock = :min_stock, unit = :unit, status = :status
                                WHERE id = :id
                            ");
                            $stmt->execute([
                                'sku' => $sku, 'barcode' => $barcode, 'name' => $name,
                                'category_id' => $category_id, 'supplier_id' => $supplier_id,
                                'buy_price' => $buy_price, 'sell_price' => $sell_price,
                                'stock' => $stock, 'min_stock' => $min_stock, 'unit' => $unit, 'status' => $status,
                                'id' => $id
                            ]);

                            // Log manual stock adjustments if stock changed directly in catalog
                            if ($old_stock !== $stock) {
                                $diff = $stock - $old_stock;
                                $type = $diff > 0 ? 'IN' : 'OUT';
                                $db->prepare("
                                    INSERT INTO inventory_movements (product_id, type, qty, stock_before, stock_after, reason, user_id) 
                                    VALUES (?, ?, ?, ?, ?, 'Penyesuaian manual katalog', ?)
                                ")->execute([$id, $type, abs($diff), $old_stock, $stock, $_SESSION['user_id']]);
                            }

                            $success = "Produk '" . esc($name) . "' berhasil diperbarui.";
                            write_log("Updated product catalog entry (ID: $id) - SKU: $sku");
                        } else {
                            // NEW PRODUCT
                            $stmt = $db->prepare("
                                INSERT INTO products (sku, barcode, name, category_id, supplier_id, buy_price, sell_price, stock, min_stock, unit, status) 
                                VALUES (:sku, :barcode, :name, :category_id, :supplier_id, :buy_price, :sell_price, :stock, :min_stock, :unit, :status)
                            ");
                            $stmt->execute([
                                'sku' => $sku, 'barcode' => $barcode, 'name' => $name,
                                'category_id' => $category_id, 'supplier_id' => $supplier_id,
                                'buy_price' => $buy_price, 'sell_price' => $sell_price,
                                'stock' => $stock, 'min_stock' => $min_stock, 'unit' => $unit, 'status' => $status
                            ]);
                            $new_id = $db->lastInsertId();

                            // Log stock initial movement if stock > 0
                            if ($stock > 0) {
                                $db->prepare("
                                    INSERT INTO inventory_movements (product_id, type, qty, stock_before, stock_after, reason, user_id) 
                                    VALUES (?, 'IN', ?, 0, ?, 'Stok awal produk baru', ?)
                                ")->execute([$new_id, $stock, $stock, $_SESSION['user_id']]);
                            }

                            $success = "Produk '" . esc($name) . "' berhasil ditambahkan.";
                            write_log("Added new product SKU: $sku, Name: $name");
                        }
                    }
                } catch (Exception $e) {
                    $error = 'Gagal menyimpan ke database: ' . $e->getMessage();
                }
            }
        }

        // --- DELETE PRODUCT ---
        if ($action === 'delete') {
            $id = intval($_POST['id'] ?? 0);
            try {
                // Fetch name for logging first
                $n_stmt = $db->prepare("SELECT name, sku FROM products WHERE id = ?");
                $n_stmt->execute([$id]);
                $prod = $n_stmt->fetch();
                
                if ($prod) {
                    // Check if product exists in transaction items (relational constraint)
                    $tx_check = $db->prepare("SELECT COUNT(*) FROM transaction_items WHERE product_id = ?");
                    $tx_check->execute([$id]);
                    if (intval($tx_check->fetchColumn()) > 0) {
                        // Product has sales record, soft delete instead by setting status inactive
                        $db->prepare("UPDATE products SET status = 'INACTIVE' WHERE id = ?")->execute([$id]);
                        $success = "Produk '" . esc($prod['name']) . "' sudah memiliki transaksi, status dialihkan menjadi NON-AKTIF.";
                    } else {
                        // Hard delete
                        $db->prepare("DELETE FROM products WHERE id = ?")->execute([$id]);
                        $success = "Produk '" . esc($prod['name']) . "' berhasil dihapus permanen.";
                    }
                    write_log("Deleted product ID: $id (SKU: " . $prod['sku'] . ")");
                }
            } catch (Exception $e) {
                $error = 'Gagal menghapus produk: ' . $e->getMessage();
            }
        }
    }
}

// --- GET DATATABLE ARGUMENTS (PAGINATION, FILTERS, SEARCH, SORT) ---
$search = trim($_GET['search'] ?? '');
$filter_category = trim($_GET['category'] ?? '');
$filter_stock = trim($_GET['stock'] ?? '');
$sort_by = trim($_GET['sort_by'] ?? 'name');
$sort_order = trim($_GET['sort_order'] ?? 'asc');

$limit = 10;
$page = isset($_GET['page']) ? max(1, intval($_GET['page'])) : 1;
$offset = ($page - 1) * $limit;

// Build SQL dynamic query
$sql_where = " WHERE 1=1";
$params = [];

if (!empty($search)) {
    $sql_where .= " AND (p.name LIKE :search OR p.sku LIKE :search OR p.barcode LIKE :search)";
    $params['search'] = "%$search%";
}

if (!empty($filter_category)) {
    $sql_where .= " AND p.category_id = :category";
    $params['category'] = $filter_category;
}

if ($filter_stock === 'low') {
    $sql_where .= " AND p.stock <= p.min_stock AND p.stock > 0";
} else if ($filter_stock === 'out') {
    $sql_where .= " AND p.stock = 0";
}

// Define valid sorting columns
$allowed_sort = ['name' => 'p.name', 'sku' => 'p.sku', 'price' => 'p.sell_price', 'stock' => 'p.stock'];
$sort_col = $allowed_sort[$sort_by] ?? 'p.name';
$sort_dir = strtolower($sort_order) === 'desc' ? 'DESC' : 'ASC';

// Count total products matching filters
$count_sql = "SELECT COUNT(*) FROM products p $sql_where";
$count_stmt = $db->prepare($count_sql);
$count_stmt->execute($params);
$total_records = intval($count_stmt->fetchColumn());
$total_pages = ceil($total_records / $limit);

// Fetch products matching filters
$fetch_sql = "
    SELECT p.*, c.name as category_name, s.name as supplier_name 
    FROM products p
    LEFT JOIN categories c ON p.category_id = c.id
    LEFT JOIN suppliers s ON p.supplier_id = s.id
    $sql_where
    ORDER BY $sort_col $sort_dir
    LIMIT $limit OFFSET $offset
";
$fetch_stmt = $db->prepare($fetch_sql);
$fetch_stmt->execute($params);
$products = $fetch_stmt->fetchAll();

// Fetch auxiliary dropdown listings
$categories = $db->query("SELECT * FROM categories ORDER BY name ASC")->fetchAll();
$suppliers = $db->query("SELECT * FROM suppliers ORDER BY name ASC")->fetchAll();

// Include Header & Sidebar layouts
include __DIR__ . '/../includes/header.php';
include __DIR__ . '/../includes/sidebar.php';
?>

<!-- Main Content -->
<main class="flex-1 overflow-y-auto bg-slate-50 p-6 md:p-8">
    
    <!-- Title Section -->
    <div class="flex flex-col md:flex-row md:items-center md:justify-between mb-8 space-y-4 md:space-y-0">
        <div>
            <h2 class="text-2xl font-bold text-slate-900 tracking-tight">Katalog Produk</h2>
            <p class="text-sm text-slate-500 mt-1">Kelola data inventori barang dagang, harga beli, harga jual, dan stok minimarket.</p>
        </div>
        <button onclick="openFormModal()" class="cursor-pointer inline-flex items-center px-4.5 py-2.5 bg-emerald-600 hover:bg-emerald-700 text-sm font-semibold text-white rounded-xl shadow-lg shadow-emerald-600/20 transition-all">
            <i data-lucide="plus-circle" class="w-4.5 h-4.5 mr-2"></i>
            Tambah Produk Baru
        </button>
    </div>

    <!-- Feedback Banners -->
    <?php if (!empty($error)): ?>
        <div class="mb-6 bg-red-50 border border-red-200 rounded-xl p-4 flex items-start space-x-3 text-sm text-red-700 animate-shake">
            <i data-lucide="alert-circle" class="w-5 h-5 text-red-500 shrink-0 mt-0.5"></i>
            <span><?= esc($error) ?></span>
        </div>
    <?php endif; ?>
    <?php if (!empty($success)): ?>
        <div class="mb-6 bg-emerald-50 border border-emerald-200 rounded-xl p-4 flex items-start space-x-3 text-sm text-emerald-800">
            <i data-lucide="check-circle-2" class="w-5 h-5 text-emerald-600 shrink-0 mt-0.5"></i>
            <span><?= esc($success) ?></span>
        </div>
    <?php endif; ?>

    <!-- FILTER & SEARCH PANEL -->
    <div class="bg-white border border-slate-200 rounded-2xl p-5 shadow-sm mb-6">
        <form method="GET" class="grid grid-cols-1 sm:grid-cols-12 gap-4">
            <!-- Search Keyword -->
            <div class="sm:col-span-4 relative">
                <span class="absolute inset-y-0 left-0 pl-3 flex items-center pointer-events-none text-slate-400">
                    <i data-lucide="search" class="w-4 h-4"></i>
                </span>
                <input type="text" name="search" value="<?= esc($search) ?>" 
                       class="block w-full pl-9 pr-3 py-2 border border-slate-200 rounded-xl text-xs focus:outline-none focus:ring-2 focus:ring-emerald-500/25 focus:border-emerald-500 bg-slate-50 focus:bg-white transition-all"
                       placeholder="Cari SKU, Barcode, atau Nama...">
            </div>

            <!-- Filter Category -->
            <div class="sm:col-span-3">
                <select name="category" onchange="this.form.submit()" class="block w-full px-3 py-2 border border-slate-200 rounded-xl text-xs focus:outline-none focus:ring-2 focus:ring-emerald-500/25 focus:border-emerald-500 bg-slate-50">
                    <option value="">Semua Kategori</option>
                    <?php foreach ($categories as $cat): ?>
                        <option value="<?= $cat['id'] ?>" <?= $filter_category == $cat['id'] ? 'selected' : '' ?>><?= esc($cat['name']) ?></option>
                    <?php endforeach; ?>
                </select>
            </div>

            <!-- Filter Stock status -->
            <div class="sm:col-span-3">
                <select name="stock" onchange="this.form.submit()" class="block w-full px-3 py-2 border border-slate-200 rounded-xl text-xs focus:outline-none focus:ring-2 focus:ring-emerald-500/25 focus:border-emerald-500 bg-slate-50">
                    <option value="">Semua Status Stok</option>
                    <option value="low" <?= $filter_stock === 'low' ? 'selected' : '' ?>>Stok Menipis (Low)</option>
                    <option value="out" <?= $filter_stock === 'out' ? 'selected' : '' ?>>Stok Habis (Empty)</option>
                </select>
            </div>

            <!-- Filter Sort Order / Sorting Trigger Column -->
            <div class="sm:col-span-2 flex space-x-2">
                <button type="submit" class="cursor-pointer flex-1 bg-slate-900 hover:bg-slate-800 text-white rounded-xl text-xs font-semibold py-2 transition-all">
                    Filter
                </button>
                <a href="/admin/products.php" class="p-2 border border-slate-200 hover:bg-slate-100 rounded-xl transition-all" title="Reset Filters">
                    <i data-lucide="rotate-ccw" class="w-4 h-4 text-slate-500"></i>
                </a>
            </div>
        </form>
    </div>

    <!-- PRODUCTS DATA TABLE -->
    <div class="bg-white border border-slate-200 rounded-2xl shadow-sm overflow-hidden">
        <div class="overflow-x-auto">
            <table class="w-full text-left text-sm border-collapse">
                <thead>
                    <tr class="bg-slate-50 text-slate-600 font-mono text-xs uppercase border-b border-slate-200">
                        <th class="px-6 py-4 font-semibold">SKU / Barcode</th>
                        <th class="px-6 py-4 font-semibold">Nama Produk</th>
                        <th class="px-6 py-4 font-semibold">Kategori</th>
                        <th class="px-6 py-4 font-semibold text-right">Harga Beli</th>
                        <th class="px-6 py-4 font-semibold text-right">Harga Jual</th>
                        <th class="px-6 py-4 font-semibold text-center">Stok</th>
                        <th class="px-6 py-4 text-center">Status</th>
                        <th class="px-6 py-4 text-right">Aksi</th>
                    </tr>
                </thead>
                <tbody class="divide-y divide-slate-100">
                    <?php if (empty($products)): ?>
                        <tr>
                            <td colspan="8" class="text-center py-12 text-slate-400">
                                <div class="flex flex-col items-center justify-center">
                                    <i data-lucide="package-x" class="w-12 h-12 text-slate-300 mb-2"></i>
                                    <p class="text-sm font-semibold">Belum ada data produk terekam</p>
                                </div>
                            </td>
                        </tr>
                    <?php else: ?>
                        <?php foreach ($products as $p): ?>
                            <?php 
                            $stk = intval($p['stock']);
                            $min = intval($p['min_stock']);
                            $is_low = $stk <= $min && $stk > 0;
                            $is_out = $stk === 0;
                            ?>
                            <tr class="hover:bg-slate-50/50 transition-colors">
                                <td class="px-6 py-4">
                                    <p class="font-mono font-bold text-xs text-slate-900"><?= esc($p['sku']) ?></p>
                                    <p class="font-mono text-[10px] text-slate-400 mt-0.5"><?= esc($p['barcode']) ?></p>
                                </td>
                                <td class="px-6 py-4 font-semibold text-slate-900">
                                    <?= esc($p['name']) ?>
                                </td>
                                <td class="px-6 py-4 text-slate-600 text-xs">
                                    <?= esc($p['category_name'] ?? 'Lainnya') ?>
                                </td>
                                <td class="px-6 py-4 text-right font-mono text-xs text-slate-500">
                                    <?= format_rupiah($p['buy_price']) ?>
                                </td>
                                <td class="px-6 py-4 text-right font-mono text-xs font-bold text-slate-900">
                                    <?= format_rupiah($p['sell_price']) ?>
                                </td>
                                <td class="px-6 py-4 text-center">
                                    <?php if ($is_out): ?>
                                        <span class="inline-block px-2.5 py-1 text-[10px] font-bold rounded-lg bg-red-100 border border-red-200 text-red-800">
                                            0 <?= esc($p['unit']) ?>
                                        </span>
                                    <?php elseif ($is_low): ?>
                                        <span class="inline-block px-2.5 py-1 text-[10px] font-bold rounded-lg bg-amber-100 border border-amber-200 text-amber-800">
                                            <?= $stk ?> <?= esc($p['unit']) ?> <i class="inline inline-block w-1.5 h-1.5 bg-amber-500 rounded-full ml-1"></i>
                                        </span>
                                    <?php else: ?>
                                        <span class="inline-block px-2.5 py-1 text-[10px] font-semibold rounded-lg bg-emerald-100 border border-emerald-200 text-emerald-800">
                                            <?= $stk ?> <?= esc($p['unit']) ?>
                                        </span>
                                    <?php endif; ?>
                                </td>
                                <td class="px-6 py-4 text-center">
                                    <span class="inline-flex items-center px-2.5 py-0.5 rounded-full text-xs font-semibold <?= $p['status'] === 'ACTIVE' ? 'bg-green-100 text-green-800' : 'bg-slate-100 text-slate-800' ?>">
                                        <?= $p['status'] === 'ACTIVE' ? 'Aktif' : 'Non-Aktif' ?>
                                    </span>
                                </td>
                                <td class="px-6 py-4 text-right">
                                    <div class="inline-flex items-center space-x-2">
                                        <button onclick="openFormModal(<?= htmlspecialchars(json_encode($p)) ?>)" class="cursor-pointer p-1.5 bg-slate-100 hover:bg-slate-200 text-slate-600 hover:text-slate-900 rounded-lg transition-all" title="Edit">
                                            <i data-lucide="edit-3" class="w-4 h-4"></i>
                                        </button>
                                        <button onclick="confirmDelete(<?= $p['id'] ?>, '<?= esc($p['name']) ?>')" class="cursor-pointer p-1.5 bg-red-50 hover:bg-red-100 text-red-500 hover:text-red-700 rounded-lg transition-all" title="Delete">
                                            <i data-lucide="trash-2" class="w-4 h-4"></i>
                                        </button>
                                    </div>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                    <?php endif; ?>
                </tbody>
            </table>
        </div>

        <!-- Pagination Block -->
        <?php if ($total_pages > 1): ?>
            <div class="px-6 py-4 border-t border-slate-100 flex items-center justify-between text-xs bg-slate-50/50">
                <span class="text-slate-500">Menampilkan <?= count($products) ?> dari <?= $total_records ?> produk</span>
                <div class="flex items-center space-x-1">
                    <!-- Prev page link -->
                    <a href="?page=<?= max(1, $page - 1) ?>&search=<?= urlencode($search) ?>&category=<?= $filter_category ?>&stock=<?= $filter_stock ?>"
                       class="px-3 py-1.5 border border-slate-200 rounded-lg text-slate-600 hover:bg-slate-100 <?= $page <= 1 ? 'pointer-events-none opacity-40' : '' ?>">Sebelumnya</a>
                    
                    <?php for ($i = 1; $i <= $total_pages; $i++): ?>
                        <a href="?page=<?= $i ?>&search=<?= urlencode($search) ?>&category=<?= $filter_category ?>&stock=<?= $filter_stock ?>"
                           class="px-3 py-1.5 rounded-lg border <?= $i === $page ? 'bg-emerald-600 border-emerald-600 text-white shadow-md shadow-emerald-500/15' : 'border-slate-200 hover:bg-slate-100 text-slate-600' ?>"><?= $i ?></a>
                    <?php endfor; ?>

                    <!-- Next page link -->
                    <a href="?page=<?= min($total_pages, $page + 1) ?>&search=<?= urlencode($search) ?>&category=<?= $filter_category ?>&stock=<?= $filter_stock ?>"
                       class="px-3 py-1.5 border border-slate-200 rounded-lg text-slate-600 hover:bg-slate-100 <?= $page >= $total_pages ? 'pointer-events-none opacity-40' : '' ?>">Selanjutnya</a>
                </div>
            </div>
        <?php endif; ?>
    </div>
</main>

<!-- ================================================== -->
<!-- MODAL FORM: ADD / EDIT PRODUCT -->
<!-- ================================================== -->
<div id="product-form-modal" class="hidden fixed inset-0 bg-slate-950/70 backdrop-blur-sm z-50 flex items-center justify-center p-4">
    <div class="bg-white rounded-3xl shadow-2xl border border-slate-200 max-w-2xl w-full overflow-hidden flex flex-col transform transition-all animate-scaleIn">
        <!-- Header -->
        <div class="px-6 py-5 border-b border-slate-100 flex items-center justify-between bg-slate-50">
            <h3 class="font-extrabold text-slate-800" id="modal-title">Tambah Produk Baru</h3>
            <button onclick="closeFormModal()" class="text-slate-400 hover:text-slate-600 cursor-pointer">
                <i data-lucide="x" class="w-6 h-6"></i>
            </button>
        </div>

        <form action="/admin/products.php" method="POST" class="p-6 space-y-4">
            <!-- CSRF & Save Action markers -->
            <input type="hidden" name="csrf_token" value="<?= csrf_token() ?>">
            <input type="hidden" name="action" value="save">
            <input type="hidden" name="id" id="prod-id" value="">

            <div class="grid grid-cols-1 sm:grid-cols-2 gap-4">
                <!-- SKU -->
                <div>
                    <label for="prod-sku" class="block text-xs font-semibold text-slate-500 uppercase tracking-wider mb-1.5">SKU Produk *</label>
                    <input type="text" name="sku" id="prod-sku" required class="block w-full px-3 py-2.5 border border-slate-200 rounded-xl text-xs uppercase font-mono" placeholder="PRD-XXX-XXX">
                </div>

                <!-- Barcode -->
                <div>
                    <label for="prod-barcode" class="block text-xs font-semibold text-slate-500 uppercase tracking-wider mb-1.5">Barcode / EAN *</label>
                    <div class="relative">
                        <input type="text" name="barcode" id="prod-barcode" required class="block w-full px-3 py-2.5 border border-slate-200 rounded-xl text-xs font-mono" placeholder="8998866xxxx">
                        <button type="button" onclick="generateRandomBarcode()" class="absolute inset-y-0 right-0 pr-3 flex items-center text-emerald-500 hover:text-emerald-700 text-xs font-semibold" title="Generate Barcode">Auto</button>
                    </div>
                </div>
            </div>

            <!-- Product Name -->
            <div>
                <label for="prod-name" class="block text-xs font-semibold text-slate-500 uppercase tracking-wider mb-1.5">Nama Produk *</label>
                <input type="text" name="name" id="prod-name" required class="block w-full px-3 py-2.5 border border-slate-200 rounded-xl text-xs" placeholder="Contoh: Indomie Goreng Spesial">
            </div>

            <div class="grid grid-cols-1 sm:grid-cols-2 gap-4">
                <!-- Category select -->
                <div>
                    <label for="prod-cat" class="block text-xs font-semibold text-slate-500 uppercase tracking-wider mb-1.5">Kategori</label>
                    <select name="category_id" id="prod-cat" class="block w-full px-3 py-2.5 border border-slate-200 rounded-xl text-xs bg-slate-50">
                        <option value="">-- Tanpa Kategori --</option>
                        <?php foreach ($categories as $cat): ?>
                            <option value="<?= $cat['id'] ?>"><?= esc($cat['name']) ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>

                <!-- Supplier select -->
                <div>
                    <label for="prod-sup" class="block text-xs font-semibold text-slate-500 uppercase tracking-wider mb-1.5">Supplier Pemasok</label>
                    <select name="supplier_id" id="prod-sup" class="block w-full px-3 py-2.5 border border-slate-200 rounded-xl text-xs bg-slate-50">
                        <option value="">-- Tanpa Supplier --</option>
                        <?php foreach ($suppliers as $sup): ?>
                            <option value="<?= $sup['id'] ?>"><?= esc($sup['name']) ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
            </div>

            <div class="grid grid-cols-1 sm:grid-cols-3 gap-4">
                <!-- Buy Price -->
                <div>
                    <label for="prod-buy" class="block text-xs font-semibold text-slate-500 uppercase tracking-wider mb-1.5">Harga Modal *</label>
                    <div class="relative">
                        <span class="absolute inset-y-0 left-0 pl-3 flex items-center text-xs text-slate-400">Rp</span>
                        <input type="number" name="buy_price" id="prod-buy" required class="block w-full pl-8 pr-3 py-2.5 border border-slate-200 rounded-xl text-xs font-mono" placeholder="0">
                    </div>
                </div>

                <!-- Sell Price -->
                <div>
                    <label for="prod-sell" class="block text-xs font-semibold text-slate-500 uppercase tracking-wider mb-1.5">Harga Jual *</label>
                    <div class="relative">
                        <span class="absolute inset-y-0 left-0 pl-3 flex items-center text-xs text-slate-400">Rp</span>
                        <input type="number" name="sell_price" id="prod-sell" required class="block w-full pl-8 pr-3 py-2.5 border border-slate-200 rounded-xl text-xs font-mono" placeholder="0">
                    </div>
                </div>

                <!-- Unit selection -->
                <div>
                    <label for="prod-unit" class="block text-xs font-semibold text-slate-500 uppercase tracking-wider mb-1.5">Satuan Ukuran *</label>
                    <input type="text" name="unit" id="prod-unit" required class="block w-full px-3 py-2.5 border border-slate-200 rounded-xl text-xs" placeholder="Pcs, Box, Sachet, dll." value="Pcs">
                </div>
            </div>

            <div class="grid grid-cols-1 sm:grid-cols-3 gap-4">
                <!-- Initial Stock -->
                <div>
                    <label for="prod-stock" class="block text-xs font-semibold text-slate-500 uppercase tracking-wider mb-1.5">Jumlah Stok *</label>
                    <input type="number" name="stock" id="prod-stock" required class="block w-full px-3 py-2.5 border border-slate-200 rounded-xl text-xs font-mono" placeholder="0">
                </div>

                <!-- Minimum Alert Stock -->
                <div>
                    <label for="prod-min" class="block text-xs font-semibold text-slate-500 uppercase tracking-wider mb-1.5">Stok Minimum Alert *</label>
                    <input type="number" name="min_stock" id="prod-min" required class="block w-full px-3 py-2.5 border border-slate-200 rounded-xl text-xs font-mono" placeholder="5" value="5">
                </div>

                <!-- Active status -->
                <div>
                    <label for="prod-status" class="block text-xs font-semibold text-slate-500 uppercase tracking-wider mb-1.5">Status Produk</label>
                    <select name="status" id="prod-status" class="block w-full px-3 py-2.5 border border-slate-200 rounded-xl text-xs bg-slate-50">
                        <option value="ACTIVE">Aktif (Dijual)</option>
                        <option value="INACTIVE">Non-Aktif (Ditangguhkan)</option>
                    </select>
                </div>
            </div>

            <!-- Footer Buttons -->
            <div class="grid grid-cols-2 gap-4 pt-4 border-t border-slate-100">
                <button type="button" onclick="closeFormModal()" class="cursor-pointer py-2.5 border border-slate-200 text-slate-700 font-semibold rounded-xl text-xs text-center hover:bg-slate-50 transition-all">
                    Batal
                </button>
                <button type="submit" class="cursor-pointer py-2.5 bg-emerald-600 hover:bg-emerald-700 text-white font-semibold rounded-xl text-xs text-center shadow-md shadow-emerald-500/10 transition-all">
                    Simpan Produk
                </button>
            </div>
        </form>
    </div>
</div>

<!-- ================================================== -->
<!-- MODAL FORM: CONFIRM DELETE -->
<!-- ================================================== -->
<div id="delete-modal" class="hidden fixed inset-0 bg-slate-950/70 backdrop-blur-sm z-50 flex items-center justify-center p-4">
    <div class="bg-white rounded-2xl shadow-2xl border border-slate-200 max-w-sm w-full p-6 text-center animate-scaleIn">
        <div class="w-12 h-12 bg-red-100 text-red-600 rounded-full flex items-center justify-center mx-auto mb-4">
            <i data-lucide="alert-triangle" class="w-6 h-6"></i>
        </div>
        <h3 class="text-sm font-bold text-slate-900 mb-1">Hapus Produk Catalog?</h3>
        <p class="text-slate-500 text-xs mb-5" id="delete-alert-text">Apakah Anda yakin ingin menghapus produk ini dari database?</p>
        
        <form action="/admin/products.php" method="POST" class="grid grid-cols-2 gap-3">
            <input type="hidden" name="csrf_token" value="<?= csrf_token() ?>">
            <input type="hidden" name="action" value="delete">
            <input type="hidden" name="id" id="delete-id" value="">
            
            <button type="button" onclick="closeDeleteModal()" class="cursor-pointer py-2 border border-slate-200 text-slate-700 font-semibold rounded-xl text-xs hover:bg-slate-50 transition-all">Batal</button>
            <button type="submit" class="cursor-pointer py-2 bg-red-600 hover:bg-red-700 text-white font-semibold rounded-xl text-xs shadow-md transition-all">Hapus</button>
        </form>
    </div>
</div>

<script>
    // Modal controls
    function openFormModal(product = null) {
        const modal = document.getElementById('product-form-modal');
        const title = document.getElementById('modal-title');
        
        // Reset fields
        document.getElementById('prod-id').value = '';
        document.getElementById('prod-sku').value = '';
        document.getElementById('prod-barcode').value = '';
        document.getElementById('prod-name').value = '';
        document.getElementById('prod-cat').value = '';
        document.getElementById('prod-sup').value = '';
        document.getElementById('prod-buy').value = '';
        document.getElementById('prod-sell').value = '';
        document.getElementById('prod-stock').value = '';
        document.getElementById('prod-min').value = '5';
        document.getElementById('prod-unit').value = 'Pcs';
        document.getElementById('prod-status').value = 'ACTIVE';

        if (product) {
            title.textContent = "Edit Detail Produk";
            document.getElementById('prod-id').value = product.id;
            document.getElementById('prod-sku').value = product.sku;
            document.getElementById('prod-barcode').value = product.barcode;
            document.getElementById('prod-name').value = product.name;
            document.getElementById('prod-cat').value = product.category_id ?? '';
            document.getElementById('prod-sup').value = product.supplier_id ?? '';
            document.getElementById('prod-buy').value = Math.round(product.buy_price);
            document.getElementById('prod-sell').value = Math.round(product.sell_price);
            document.getElementById('prod-stock').value = product.stock;
            document.getElementById('prod-min').value = product.min_stock;
            document.getElementById('prod-unit').value = product.unit;
            document.getElementById('prod-status').value = product.status;
        } else {
            title.textContent = "Tambah Produk Baru";
            // Pre-fill a random SKU for ease of use
            document.getElementById('prod-sku').value = "PRD-" + Math.floor(100000 + Math.random() * 900000);
        }

        modal.classList.remove('hidden');
    }

    function closeFormModal() {
        document.getElementById('product-form-modal').classList.add('hidden');
    }

    function confirmDelete(id, name) {
        document.getElementById('delete-id').value = id;
        document.getElementById('delete-alert-text').innerHTML = `Apakah Anda yakin ingin menghapus produk <strong>"${name}"</strong> dari katalog?<br><span class="text-[10px] text-red-500">Catatan: Jika produk sudah memiliki transaksi, produk hanya akan dinonaktifkan dari sistem.</span>`;
        document.getElementById('delete-modal').classList.remove('hidden');
    }

    function closeDeleteModal() {
        document.getElementById('delete-modal').classList.add('hidden');
    }

    // Generate random barcode helper
    function generateRandomBarcode() {
        // Simple random EAN-13 simulation
        let code = "899" + Math.floor(1000000000 + Math.random() * 9000000000);
        document.getElementById('prod-barcode').value = code;
    }
</script>

<?php include __DIR__ . '/../includes/footer.php'; ?>
