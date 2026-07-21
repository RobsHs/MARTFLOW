<?php
// /admin/inventory.php
// MartFlow POS - Stock Management & Inventory movements

require_once __DIR__ . '/../includes/functions.php';
require_role(['ADMIN', 'MANAGER']);

$page_title = 'Kelola Inventori';
$db = Database::getConnection();

$error = '';
$success = '';

// --- SAVE STOCK ADJUSTMENT ACTION ---
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $token = $_POST['csrf_token'] ?? '';
    
    if (!verify_csrf_token($token)) {
        $error = 'Validasi keamanan CSRF gagal. Silakan coba lagi.';
    } else {
        $product_id = intval($_POST['product_id'] ?? 0);
        $type = trim($_POST['type'] ?? ''); // 'IN' or 'OUT'
        $qty = intval($_POST['qty'] ?? 0);
        $reason = trim($_POST['reason'] ?? '');

        if ($product_id <= 0 || !in_array($type, ['IN', 'OUT']) || $qty <= 0 || empty($reason)) {
            $error = 'Semua field penyesuaian stok wajib diisi dengan benar!';
        } else {
            try {
                $db->beginTransaction();

                // Fetch current stock and details of product
                $p_stmt = $db->prepare("SELECT stock, name, unit FROM products WHERE id = ? FOR UPDATE");
                $p_stmt->execute([$product_id]);
                $product = $p_stmt->fetch();

                if (!$product) {
                    throw new Exception("Produk tidak ditemukan di database.");
                }

                $stock_before = intval($product['stock']);
                $stock_after = 0;

                if ($type === 'IN') {
                    $stock_after = $stock_before + $qty;
                } else if ($type === 'OUT') {
                    if ($qty > $stock_before) {
                        throw new Exception("Stok saat ini (" . $stock_before . ") tidak mencukupi untuk pengurangan sebanyak " . $qty);
                    }
                    $stock_after = $stock_before - $qty;
                }

                // 1. Update product stock
                $up_stmt = $db->prepare("UPDATE products SET stock = ? WHERE id = ?");
                $up_stmt->execute([$stock_after, $product_id]);

                // 2. Log in inventory_movements
                $log_stmt = $db->prepare("
                    INSERT INTO inventory_movements (product_id, type, qty, stock_before, stock_after, reason, user_id) 
                    VALUES (?, ?, ?, ?, ?, ?, ?)
                ");
                $log_stmt->execute([$product_id, $type, $qty, $stock_before, $stock_after, $reason, $_SESSION['user_id']]);

                $db->commit();
                $success = "Penyesuaian stok '" . esc($product['name']) . "' berhasil: " . ($type === 'IN' ? '+' : '-') . "$qty " . $product['unit'] . " (Stok akhir: $stock_after)";
                write_log("Manual Stock adjustment ($type) for '" . $product['name'] . "' - Qty: $qty. Reason: $reason");
            } catch (Exception $e) {
                $db->rollBack();
                $error = 'Gagal menyesuaikan stok: ' . $e->getMessage();
            }
        }
    }
}

// --- GET ADJUSTMENTS LIST ARGUMENTS ---
$search = trim($_GET['search'] ?? '');
$type_filter = trim($_GET['type'] ?? '');

$limit = 15;
$page = isset($_GET['page']) ? max(1, intval($_GET['page'])) : 1;
$offset = ($page - 1) * $limit;

// Build SQL dynamic query
$sql_where = " WHERE 1=1";
$params = [];

if (!empty($search)) {
    $sql_where .= " AND (p.name LIKE :search OR p.sku LIKE :search OR im.reason LIKE :search)";
    $params['search'] = "%$search%";
}

if (!empty($type_filter)) {
    $sql_where .= " AND im.type = :type";
    $params['type'] = $type_filter;
}

// Count movements matching filters
$count_stmt = $db->prepare("
    SELECT COUNT(*) 
    FROM inventory_movements im
    JOIN products p ON im.product_id = p.id
    $sql_where
");
$count_stmt->execute($params);
$total_records = intval($count_stmt->fetchColumn());
$total_pages = ceil($total_records / $limit);

// Fetch movements
$fetch_stmt = $db->prepare("
    SELECT im.*, p.name as product_name, p.sku as product_sku, p.unit as product_unit, u.name as user_name 
    FROM inventory_movements im
    JOIN products p ON im.product_id = p.id
    LEFT JOIN users u ON im.user_id = u.id
    $sql_where
    ORDER BY im.created_at DESC
    LIMIT $limit OFFSET $offset
");
$fetch_stmt->execute($params);
$movements = $fetch_stmt->fetchAll();

// Fetch all products list for selection dropdown
$products = $db->query("SELECT id, name, sku, stock, unit FROM products WHERE status = 'ACTIVE' ORDER BY name ASC")->fetchAll();

include __DIR__ . '/../includes/header.php';
include __DIR__ . '/../includes/sidebar.php';
?>

<!-- Main Content Area -->
<main class="flex-1 overflow-y-auto bg-slate-50 p-6 md:p-8">
    <!-- Page Header -->
    <div class="flex flex-col md:flex-row md:items-center md:justify-between mb-8 space-y-4 md:space-y-0">
        <div>
            <h2 class="text-2xl font-bold text-slate-900 tracking-tight">Manajemen Stok & Inventori</h2>
            <p class="text-sm text-slate-500 mt-1">Lakukan penyesuaian stok manual (Restock, Opname) dan pantau riwayat mutasi barang.</p>
        </div>
        <button onclick="openAdjustModal()" class="cursor-pointer inline-flex items-center px-4.5 py-2.5 bg-slate-900 hover:bg-slate-800 text-sm font-semibold text-white rounded-xl shadow-lg transition-all">
            <i data-lucide="sliders-horizontal" class="w-4.5 h-4.5 mr-2"></i>
            Penyesuaian Stok Manual
        </button>
    </div>

    <!-- Feedback Banners -->
    <?php if (!empty($error)): ?>
        <div class="mb-6 bg-red-50 border border-red-200 rounded-xl p-4 flex items-start space-x-3 text-sm text-red-700">
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

    <!-- FILTER & SEARCH BAR -->
    <div class="bg-white border border-slate-200 rounded-2xl p-5 shadow-sm mb-6">
        <form method="GET" class="grid grid-cols-1 sm:grid-cols-12 gap-4">
            <!-- Search Keyword -->
            <div class="sm:col-span-6 relative">
                <span class="absolute inset-y-0 left-0 pl-3 flex items-center pointer-events-none text-slate-400">
                    <i data-lucide="search" class="w-4 h-4"></i>
                </span>
                <input type="text" name="search" value="<?= esc($search) ?>" 
                       class="block w-full pl-9 pr-3 py-2 border border-slate-200 rounded-xl text-xs focus:outline-none focus:ring-2 focus:ring-emerald-500/25 focus:border-emerald-500 bg-slate-50 focus:bg-white transition-all"
                       placeholder="Cari SKU, Nama Produk, atau alasan penyesuaian...">
            </div>

            <!-- Filter Type -->
            <div class="sm:col-span-4">
                <select name="type" onchange="this.form.submit()" class="block w-full px-3 py-2 border border-slate-200 rounded-xl text-xs focus:outline-none focus:ring-2 focus:ring-emerald-500/25 focus:border-emerald-500 bg-slate-50">
                    <option value="">Semua Tipe Mutasi</option>
                    <option value="IN" <?= $type_filter === 'IN' ? 'selected' : '' ?>>Barang Masuk (IN)</option>
                    <option value="OUT" <?= $type_filter === 'OUT' ? 'selected' : '' ?>>Barang Keluar (OUT)</option>
                </select>
            </div>

            <div class="sm:col-span-2">
                <button type="submit" class="cursor-pointer w-full bg-slate-900 hover:bg-slate-800 text-white rounded-xl text-xs font-semibold py-2 transition-all">
                    Filter
                </button>
            </div>
        </form>
    </div>

    <!-- INVENTORY TIMELINE / MUTATION TABLE -->
    <div class="bg-white border border-slate-200 rounded-2xl shadow-sm overflow-hidden">
        <div class="overflow-x-auto">
            <table class="w-full text-left text-sm border-collapse">
                <thead>
                    <tr class="bg-slate-50 text-slate-600 font-mono text-xs uppercase border-b border-slate-200">
                        <th class="px-6 py-4 font-semibold">Tanggal & Waktu</th>
                        <th class="px-6 py-4 font-semibold">Produk SKU / Nama</th>
                        <th class="px-6 py-4 font-semibold text-center">Tipe Mutasi</th>
                        <th class="px-6 py-4 font-semibold text-right">Jumlah</th>
                        <th class="px-6 py-4 font-semibold text-right">Stok Sebelum</th>
                        <th class="px-6 py-4 font-semibold text-right">Stok Sesudah</th>
                        <th class="px-6 py-4 font-semibold">Alasan Penyesuaian</th>
                        <th class="px-6 py-4">Petugas</th>
                    </tr>
                </thead>
                <tbody class="divide-y divide-slate-100">
                    <?php if (empty($movements)): ?>
                        <tr>
                            <td colspan="8" class="text-center py-12 text-slate-400">
                                <div class="flex flex-col items-center justify-center">
                                    <i data-lucide="arrow-right-left" class="w-10 h-10 text-slate-300 mb-2"></i>
                                    <p class="text-sm font-semibold">Belum ada riwayat mutasi terekam</p>
                                </div>
                            </td>
                        </tr>
                    <?php else: ?>
                        <?php foreach ($movements as $m): ?>
                            <tr class="hover:bg-slate-50/50 transition-colors text-xs">
                                <td class="px-6 py-4 font-mono text-slate-500 whitespace-nowrap">
                                    <?= date('d-m-Y H:i:s', strtotime($m['created_at'])) ?>
                                </td>
                                <td class="px-6 py-4">
                                    <p class="font-mono font-bold text-slate-900"><?= esc($m['product_sku']) ?></p>
                                    <p class="text-slate-500 font-semibold mt-0.5 truncate max-w-xs"><?= esc($m['product_name']) ?></p>
                                </td>
                                <td class="px-6 py-4 text-center">
                                    <?php if ($m['type'] === 'IN'): ?>
                                        <span class="inline-flex items-center px-2 py-0.5 rounded text-[10px] font-bold bg-green-100 text-green-800 border border-green-200">
                                            MASUK (IN)
                                        </span>
                                    <?php else: ?>
                                        <span class="inline-flex items-center px-2 py-0.5 rounded text-[10px] font-bold bg-red-100 text-red-800 border border-red-200">
                                            KELUAR (OUT)
                                        </span>
                                    <?php endif; ?>
                                </td>
                                <td class="px-6 py-4 text-right font-bold font-mono text-slate-900">
                                    <?= ($m['type'] === 'IN' ? '+' : '-') ?><?= $m['qty'] ?> <?= esc($m['product_unit']) ?>
                                </td>
                                <td class="px-6 py-4 text-right font-mono text-slate-500">
                                    <?= $m['stock_before'] ?>
                                </td>
                                <td class="px-6 py-4 text-right font-mono font-semibold text-slate-900">
                                    <?= $m['stock_after'] ?>
                                </td>
                                <td class="px-6 py-4 text-slate-600 max-w-xs truncate" title="<?= esc($m['reason']) ?>">
                                    <?= esc($m['reason']) ?>
                                </td>
                                <td class="px-6 py-4 text-slate-500 font-medium">
                                    <?= esc($m['user_name'] ?? 'Sistem') ?>
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
                <span class="text-slate-500">Menampilkan <?= count($movements) ?> dari <?= $total_records ?> mutasi</span>
                <div class="flex items-center space-x-1">
                    <a href="?page=<?= max(1, $page - 1) ?>&search=<?= urlencode($search) ?>&type=<?= $type_filter ?>"
                       class="px-3 py-1.5 border border-slate-200 rounded-lg text-slate-600 hover:bg-slate-100 <?= $page <= 1 ? 'pointer-events-none opacity-40' : '' ?>">Sebelumnya</a>
                    
                    <?php for ($i = 1; $i <= $total_pages; $i++): ?>
                        <a href="?page=<?= $i ?>&search=<?= urlencode($search) ?>&type=<?= $type_filter ?>"
                           class="px-3 py-1.5 rounded-lg border <?= $i === $page ? 'bg-emerald-600 border-emerald-600 text-white shadow-md' : 'border-slate-200 hover:bg-slate-100 text-slate-600' ?>"><?= $i ?></a>
                    <?php endfor; ?>

                    <a href="?page=<?= min($total_pages, $page + 1) ?>&search=<?= urlencode($search) ?>&type=<?= $type_filter ?>"
                       class="px-3 py-1.5 border border-slate-200 rounded-lg text-slate-600 hover:bg-slate-100 <?= $page >= $total_pages ? 'pointer-events-none opacity-40' : '' ?>">Selanjutnya</a>
                </div>
            </div>
        <?php endif; ?>
    </div>
</main>

<!-- ================================================== -->
<!-- MODAL: MANUAL STOCK ADJUSTMENT -->
<!-- ================================================== -->
<div id="adjust-modal" class="hidden fixed inset-0 bg-slate-950/70 backdrop-blur-sm z-50 flex items-center justify-center p-4">
    <div class="bg-white rounded-3xl shadow-2xl border border-slate-200 max-w-md w-full overflow-hidden flex flex-col transform transition-all animate-scaleIn">
        <div class="px-6 py-5 border-b border-slate-100 flex items-center justify-between bg-slate-50">
            <h3 class="font-extrabold text-slate-800">Penyesuaian Stok Manual</h3>
            <button onclick="closeAdjustModal()" class="text-slate-400 hover:text-slate-600 cursor-pointer">
                <i data-lucide="x" class="w-6 h-6"></i>
            </button>
        </div>

        <form action="/admin/inventory.php" method="POST" class="p-6 space-y-4">
            <input type="hidden" name="csrf_token" value="<?= csrf_token() ?>">

            <!-- Select Product -->
            <div>
                <label for="adj-product" class="block text-xs font-semibold text-slate-500 uppercase tracking-wider mb-1.5">Pilih Produk SKU/Nama *</label>
                <select name="product_id" id="adj-product" required class="block w-full px-3 py-2.5 border border-slate-200 rounded-xl text-xs bg-slate-50">
                    <option value="">-- Pilih Produk --</option>
                    <?php foreach ($products as $p): ?>
                        <option value="<?= $p['id'] ?>"><?= esc($p['sku']) ?> - <?= esc($p['name']) ?> (Stok saat ini: <?= $p['stock'] ?> <?= esc($p['unit']) ?>)</option>
                    <?php endforeach; ?>
                </select>
            </div>

            <!-- Adjustment Type -->
            <div>
                <label class="block text-xs font-semibold text-slate-500 uppercase tracking-wider mb-1.5">Tipe Penyesuaian *</label>
                <div class="grid grid-cols-2 gap-3">
                    <label class="border border-slate-200 rounded-xl p-3 flex items-center justify-center space-x-2 cursor-pointer hover:bg-slate-50 transition-all">
                        <input type="radio" name="type" value="IN" required checked class="text-emerald-600 focus:ring-emerald-500">
                        <span class="text-xs font-bold text-green-700">Barang Masuk (IN)</span>
                    </label>
                    <label class="border border-slate-200 rounded-xl p-3 flex items-center justify-center space-x-2 cursor-pointer hover:bg-slate-50 transition-all">
                        <input type="radio" name="type" value="OUT" required class="text-red-600 focus:ring-red-500">
                        <span class="text-xs font-bold text-red-700">Barang Keluar (OUT)</span>
                    </label>
                </div>
            </div>

            <!-- Quantity adjustment -->
            <div>
                <label for="adj-qty" class="block text-xs font-semibold text-slate-500 uppercase tracking-wider mb-1.5">Jumlah Penyesuaian (Qty) *</label>
                <input type="number" name="qty" id="adj-qty" required min="1" class="block w-full px-3 py-2.5 border border-slate-200 rounded-xl text-xs font-mono" placeholder="0">
            </div>

            <!-- Adjustment reason -->
            <div>
                <label for="adj-reason" class="block text-xs font-semibold text-slate-500 uppercase tracking-wider mb-1.5">Alasan Penyesuaian Stok *</label>
                <input type="text" name="reason" id="adj-reason" required class="block w-full px-3 py-2.5 border border-slate-200 rounded-xl text-xs" placeholder="Contoh: Restok Barang, Barang Rusak, Salah Hitung, dll.">
            </div>

            <div class="grid grid-cols-2 gap-4 pt-3 border-t border-slate-100">
                <button type="button" onclick="closeAdjustModal()" class="cursor-pointer py-2.5 border border-slate-200 text-slate-700 font-semibold rounded-xl text-xs text-center hover:bg-slate-50 transition-all">Batal</button>
                <button type="submit" class="cursor-pointer py-2.5 bg-emerald-600 hover:bg-emerald-700 text-white font-semibold rounded-xl text-xs text-center shadow-md transition-all">Terapkan Stok</button>
            </div>
        </form>
    </div>
</div>

<script>
    function openAdjustModal() {
        document.getElementById('adj-product').value = '';
        document.getElementById('adj-qty').value = '';
        document.getElementById('adj-reason').value = '';
        document.getElementById('adjust-modal').classList.remove('hidden');
    }

    function closeAdjustModal() {
        document.getElementById('adjust-modal').classList.add('hidden');
    }
</script>

<?php include __DIR__ . '/../includes/footer.php'; ?>
