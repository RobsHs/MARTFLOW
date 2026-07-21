<?php
// /cashier/transactions.php
// MartFlow POS - Cashier Transactions History Lookups

require_once __DIR__ . '/../includes/functions.php';
require_role(['ADMIN', 'MANAGER', 'CASHIER']);

$page_title = 'Riwayat Transaksi';
$db = Database::getConnection();

// --- SEARCH & DATE FILTERS ---
$search = trim($_GET['search'] ?? '');
$filter_date = trim($_GET['date'] ?? '');

$limit = 15;
$page = isset($_GET['page']) ? max(1, intval($_GET['page'])) : 1;
$offset = ($page - 1) * $limit;

$sql_where = " WHERE 1=1";
$params = [];

if (!empty($search)) {
    $sql_where .= " AND (t.invoice_no LIKE :search OR c.name LIKE :search)";
    $params['search'] = "%$search%";
}

if (!empty($filter_date)) {
    $sql_where .= " AND DATE(t.created_at) = :filter_date";
    $params['filter_date'] = $filter_date;
}

// In case role is only CASHIER, optionally we can restrict them to see only their own, 
// but in retail, usually cashiers can look up any invoices to process refunds/reprints, so we show all.

// Count total transactions matching
$count_stmt = $db->prepare("
    SELECT COUNT(*) 
    FROM transactions t
    LEFT JOIN customers c ON t.customer_id = c.id
    $sql_where
");
$count_stmt->execute($params);
$total_records = intval($count_stmt->fetchColumn());
$total_pages = ceil($total_records / $limit);

// Fetch transaction lists
$fetch_stmt = $db->prepare("
    SELECT t.*, u.name as cashier_name, c.name as customer_name, c.member_id as customer_member_id
    FROM transactions t
    JOIN users u ON t.user_id = u.id
    LEFT JOIN customers c ON t.customer_id = c.id
    $sql_where
    ORDER BY t.created_at DESC
    LIMIT $limit OFFSET $offset
");
$fetch_stmt->execute($params);
$transactions = $fetch_stmt->fetchAll();

include __DIR__ . '/../includes/header.php';
include __DIR__ . '/../includes/sidebar.php';
?>

<!-- Main Content Area -->
<main class="flex-1 overflow-y-auto bg-slate-50 p-6 md:p-8">
    <!-- Page Header -->
    <div class="flex flex-col md:flex-row md:items-center md:justify-between mb-8 space-y-4 md:space-y-0">
        <div>
            <h2 class="text-2xl font-bold text-slate-900 tracking-tight">Riwayat Invoice Penjualan</h2>
            <p class="text-sm text-slate-500 mt-1">Cari struk transaksi sebelumnya, lakukan cetak ulang struk thermal, dan lihat riwayat pembayaran.</p>
        </div>
        <a href="/cashier/pos.php" class="cursor-pointer inline-flex items-center px-4.5 py-2.5 bg-emerald-600 hover:bg-emerald-700 text-sm font-semibold text-white rounded-xl shadow-lg shadow-emerald-600/20 transition-all">
            <i data-lucide="shopping-cart" class="w-4.5 h-4.5 mr-2"></i>
            Buka Layar Kasir POS
        </a>
    </div>

    <!-- FILTER & SEARCH PANEL -->
    <div class="bg-white border border-slate-200 rounded-2xl p-5 shadow-sm mb-6">
        <form method="GET" class="grid grid-cols-1 sm:grid-cols-12 gap-4">
            <!-- Search field -->
            <div class="sm:col-span-6 relative">
                <span class="absolute inset-y-0 left-0 pl-3 flex items-center pointer-events-none text-slate-400">
                    <i data-lucide="search" class="w-4 h-4"></i>
                </span>
                <input type="text" name="search" value="<?= esc($search) ?>" 
                       class="block w-full pl-9 pr-3 py-2 border border-slate-200 rounded-xl text-xs focus:outline-none focus:ring-2 focus:ring-emerald-500/25 focus:border-emerald-500 bg-slate-50 focus:bg-white transition-all"
                       placeholder="Cari nomor invoice atau nama pelanggan...">
            </div>

            <!-- Date Filter -->
            <div class="sm:col-span-4">
                <input type="date" name="date" value="<?= esc($filter_date) ?>"
                       class="block w-full px-3 py-2 border border-slate-200 rounded-xl text-xs font-mono bg-slate-50">
            </div>

            <div class="sm:col-span-2 flex space-x-2">
                <button type="submit" class="cursor-pointer flex-1 bg-slate-900 hover:bg-slate-800 text-white rounded-xl text-xs font-semibold py-2 transition-all">
                    Filter
                </button>
                <a href="/cashier/transactions.php" class="p-2 border border-slate-200 hover:bg-slate-100 rounded-xl transition-all" title="Reset Filters">
                    <i data-lucide="rotate-ccw" class="w-4 h-4 text-slate-500"></i>
                </a>
            </div>
        </form>
    </div>

    <!-- TRANSACTIONS HISTORY TABLE -->
    <div class="bg-white border border-slate-200 rounded-2xl shadow-sm overflow-hidden">
        <div class="overflow-x-auto">
            <table class="w-full text-left text-sm border-collapse">
                <thead>
                    <tr class="bg-slate-50 text-slate-600 font-mono text-xs uppercase border-b border-slate-200">
                        <th class="px-6 py-4 font-semibold">Nomor Invoice</th>
                        <th class="px-6 py-4 font-semibold">Tanggal & Waktu Transaksi</th>
                        <th class="px-6 py-4 font-semibold">Petugas Kasir</th>
                        <th class="px-6 py-4 font-semibold">Pelanggan Member</th>
                        <th class="px-6 py-4 font-semibold text-center">Metode Bayar</th>
                        <th class="px-6 py-4 font-semibold text-right">Potongan</th>
                        <th class="px-6 py-4 font-semibold text-right">Total Akhir</th>
                        <th class="px-6 py-4 font-semibold text-right">Uang Dibayar</th>
                        <th class="px-6 py-4 text-center">Tindakan</th>
                    </tr>
                </thead>
                <tbody class="divide-y divide-slate-100">
                    <?php if (empty($transactions)): ?>
                        <tr>
                            <td colspan="9" class="text-center py-12 text-slate-400">
                                <div class="flex flex-col items-center justify-center">
                                    <i data-lucide="receipt-text" class="w-10 h-10 text-slate-300 mb-2"></i>
                                    <p class="text-sm font-semibold">Belum ada transaksi terekam untuk filter ini</p>
                                </div>
                            </td>
                        </tr>
                    <?php else: ?>
                        <?php foreach ($transactions as $t): ?>
                            <tr class="hover:bg-slate-50/50 transition-colors text-xs">
                                <td class="px-6 py-4">
                                    <span class="font-mono font-bold text-slate-900 text-sm"><?= esc($t['invoice_no']) ?></span>
                                </td>
                                <td class="px-6 py-4 font-mono text-slate-500">
                                    <?= date('d-m-Y H:i', strtotime($t['created_at'])) ?>
                                </td>
                                <td class="px-6 py-4 text-slate-700 font-medium">
                                    <?= esc($t['cashier_name']) ?>
                                </td>
                                <td class="px-6 py-4 text-slate-600">
                                    <?php if ($t['customer_name']): ?>
                                        <p class="font-semibold text-slate-800"><?= esc($t['customer_name']) ?></p>
                                        <p class="font-mono text-[9px] text-slate-400 mt-0.5"><?= esc($t['customer_member_id']) ?></p>
                                    <?php else: ?>
                                        <span class="text-slate-400">Guest (Bukan Member)</span>
                                    <?php endif; ?>
                                </td>
                                <td class="px-6 py-4 text-center">
                                    <span class="inline-block px-2 py-0.5 rounded text-[10px] font-bold bg-slate-100 border border-slate-200 text-slate-700 font-mono">
                                        <?= esc($t['payment_method']) ?>
                                    </span>
                                </td>
                                <td class="px-6 py-4 text-right font-mono text-red-600">
                                    -<?= number_format($t['discount'], 0, ',', '.') ?>
                                </td>
                                <td class="px-6 py-4 text-right font-bold font-mono text-slate-900 text-sm">
                                    <?= format_rupiah($t['total']) ?>
                                </td>
                                <td class="px-6 py-4 text-right font-mono text-slate-500">
                                    <?= number_format($t['amount_paid'], 0, ',', '.') ?>
                                </td>
                                <td class="px-6 py-4 text-center">
                                    <a href="/cashier/receipt.php?invoice=<?= urlencode($t['invoice_no']) ?>" target="_blank"
                                       class="cursor-pointer inline-flex items-center px-2.5 py-1.5 bg-slate-900 hover:bg-slate-800 text-white font-semibold rounded-lg text-[10px] transition-all">
                                        <i data-lucide="printer" class="w-3.5 h-3.5 mr-1"></i> Cetak Struk
                                    </a>
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
                <span class="text-slate-500">Menampilkan <?= count($transactions) ?> dari <?= $total_records ?> invoice</span>
                <div class="flex items-center space-x-1">
                    <a href="?page=<?= max(1, $page - 1) ?>&search=<?= urlencode($search) ?>&date=<?= $filter_date ?>"
                       class="px-3 py-1.5 border border-slate-200 rounded-lg text-slate-600 hover:bg-slate-100 <?= $page <= 1 ? 'pointer-events-none opacity-40' : '' ?>">Sebelumnya</a>
                    
                    <?php for ($i = 1; $i <= $total_pages; $i++): ?>
                        <a href="?page=<?= $i ?>&search=<?= urlencode($search) ?>&date=<?= $filter_date ?>"
                           class="px-3 py-1.5 rounded-lg border <?= $i === $page ? 'bg-emerald-600 border-emerald-600 text-white shadow-md' : 'border-slate-200 hover:bg-slate-100 text-slate-600' ?>"><?= $i ?></a>
                    <?php endfor; ?>

                    <a href="?page=<?= min($total_pages, $page + 1) ?>&search=<?= urlencode($search) ?>&date=<?= $filter_date ?>"
                       class="px-3 py-1.5 border border-slate-200 rounded-lg text-slate-600 hover:bg-slate-100 <?= $page >= $total_pages ? 'pointer-events-none opacity-40' : '' ?>">Selanjutnya</a>
                </div>
            </div>
        <?php endif; ?>
    </div>
</main>

<?php include __DIR__ . '/../includes/footer.php'; ?>
