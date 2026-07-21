<?php
// /admin/dashboard.php
// MartFlow POS - Admin & Manager Dashboard

require_once __DIR__ . '/../includes/functions.php';
require_role(['ADMIN', 'MANAGER']);

$page_title = 'Dashboard';
$db = Database::getConnection();

// --- PULL REAL METRICS FROM THE DATABASE ---

// 1. Sales, Transactions, Units Sold, and Profit Today
$today = date('Y-m-d');
$sales_today = 0;
$tx_count_today = 0;
$items_sold_today = 0;
$profit_today = 0;

// Query today's summary
$stmt = $db->prepare("SELECT COUNT(*) as tx_count, SUM(total) as total_sales FROM transactions WHERE DATE(created_at) = :today");
$stmt->execute(['today' => $today]);
$tx_today_res = $stmt->fetch();
if ($tx_today_res) {
    $tx_count_today = intval($tx_today_res['tx_count']);
    $sales_today = floatval($tx_today_res['total_sales'] ?? 0);
}

// Query today's items sold & profit estimation
$stmt = $db->prepare("
    SELECT SUM(qty) as items_sold, SUM((sell_price - buy_price - discount) * qty) as profit 
    FROM transaction_items 
    WHERE DATE(created_at) = :today
");
$stmt->execute(['today' => $today]);
$profit_res = $stmt->fetch();
if ($profit_res) {
    $items_sold_today = intval($profit_res['items_sold'] ?? 0);
    $profit_today = floatval($profit_res['profit'] ?? 0);
}

// 2. Weekly and Monthly Sales
$start_of_week = date('Y-m-d', strtotime('monday this week'));
$start_of_month = date('Y-m-01');

$stmt = $db->prepare("SELECT SUM(total) as total FROM transactions WHERE DATE(created_at) >= :start_date");
$stmt->execute(['start_date' => $start_of_week]);
$sales_week = floatval($stmt->fetchColumn() ?? 0);

$stmt->execute(['start_date' => $start_of_month]);
$sales_month = floatval($stmt->fetchColumn() ?? 0);

// 3. Inventory Stock Statuses
$low_stock_count = 0;
$out_of_stock_count = 0;

$stmt = $db->query("SELECT COUNT(*) FROM products WHERE stock <= min_stock AND stock > 0 AND status = 'ACTIVE'");
$low_stock_count = intval($stmt->fetchColumn());

$stmt = $db->query("SELECT COUNT(*) FROM products WHERE stock = 0 AND status = 'ACTIVE'");
$out_of_stock_count = intval($stmt->fetchColumn());

$stmt = $db->query("SELECT COUNT(*) FROM products WHERE status = 'ACTIVE'");
$total_products = intval($stmt->fetchColumn());

// 4. Total Customers
$stmt = $db->query("SELECT COUNT(*) FROM customers");
$total_customers = intval($stmt->fetchColumn());

// 5. Recent 5 Transactions
$stmt = $db->query("
    SELECT t.*, u.name as cashier_name, c.name as customer_name 
    FROM transactions t
    JOIN users u ON t.user_id = u.id
    LEFT JOIN customers c ON t.customer_id = c.id
    ORDER BY t.created_at DESC LIMIT 5
");
$recent_tx = $stmt->fetchAll();

// --- PREPARE DATA FOR INTERACTIVE CHARTS ---

// A. 7-Day Revenue Trend (Last 7 days including today)
$dates_7 = [];
$revenues_7 = [];
for ($i = 6; $i >= 0; $i--) {
    $d = date('Y-m-d', strtotime("-$i days"));
    $dates_7[] = date('d M', strtotime($d));
    
    $stmtTrend = $db->prepare("SELECT SUM(total) FROM transactions WHERE DATE(created_at) = ?");
    $stmtTrend->execute([$d]);
    $revenues_7[] = floatval($stmtTrend->fetchColumn() ?? 0);
}

// B. Best Selling Products (Top 5)
$top_products_names = [];
$top_products_qty = [];
$stmtTop = $db->query("
    SELECT p.name, SUM(ti.qty) as total_qty 
    FROM transaction_items ti
    JOIN products p ON ti.product_id = p.id
    GROUP BY ti.product_id 
    ORDER BY total_qty DESC LIMIT 5
");
$top_res = $stmtTop->fetchAll();
foreach ($top_res as $tr) {
    $top_products_names[] = $tr['name'];
    $top_products_qty[] = intval($tr['total_qty']);
}

// C. Sales by Category
$cat_names = [];
$cat_totals = [];
$stmtCatChart = $db->query("
    SELECT c.name as cat_name, SUM(ti.subtotal) as total_sales
    FROM transaction_items ti
    JOIN products p ON ti.product_id = p.id
    JOIN categories c ON p.category_id = c.id
    GROUP BY p.category_id
    ORDER BY total_sales DESC
");
$cat_res = $stmtCatChart->fetchAll();
foreach ($cat_res as $cr) {
    $cat_names[] = $cr['cat_name'];
    $cat_totals[] = floatval($cr['total_sales']);
}

// D. Sales by Payment Method
$payment_methods = [];
$payment_totals = [];
$stmtPayChart = $db->query("
    SELECT payment_method, SUM(total) as total_sales
    FROM transactions
    GROUP BY payment_method
    ORDER BY total_sales DESC
");
$pay_res = $stmtPayChart->fetchAll();
foreach ($pay_res as $pr) {
    $payment_methods[] = $pr['payment_method'];
    $payment_totals[] = floatval($pr['total_sales']);
}

// Include Header & Sidebar
include __DIR__ . '/../includes/header.php';
include __DIR__ . '/../includes/sidebar.php';
?>

<!-- Content Area -->
<main class="flex-1 overflow-y-auto bg-slate-50 p-6 md:p-8">
    <!-- Header Page Title -->
    <div class="flex flex-col md:flex-row md:items-center md:justify-between mb-8 space-y-4 md:space-y-0">
        <div>
            <h2 class="text-2xl font-bold text-slate-900 tracking-tight">Dashboard Ringkasan</h2>
            <p class="text-sm text-slate-500 mt-1">Status performa minimarket dan arus kas penjualan retail secara real-time.</p>
        </div>
        
        <!-- Live Clock Indicator -->
        <div class="flex items-center space-x-3 bg-white border border-slate-200 px-4 py-2.5 rounded-xl shadow-sm text-sm font-medium text-slate-600">
            <span class="inline-block w-2.5 h-2.5 rounded-full bg-emerald-500 animate-ping"></span>
            <span class="font-mono" id="live-clock">--:--:--</span>
        </div>
    </div>

    <!-- Quick Seeder Helper if Fresh Installation (Shows if total sales == 0 and we want to help the demo) -->
    <?php
    $total_tx_all_time = intval($db->query("SELECT COUNT(*) FROM transactions")->fetchColumn());
    if ($total_tx_all_time === 0):
    ?>
    <div class="mb-8 p-5 bg-amber-50 border border-amber-200 rounded-2xl flex flex-col md:flex-row items-start md:items-center justify-between gap-4">
        <div class="flex items-start space-x-3.5">
            <div class="w-10 h-10 bg-amber-500/10 text-amber-600 rounded-full flex items-center justify-center shrink-0">
                <i data-lucide="info" class="w-5 h-5"></i>
            </div>
            <div>
                <h4 class="font-bold text-sm text-amber-950">Database Masih Kosong!</h4>
                <p class="text-xs text-amber-800 leading-relaxed mt-0.5">Belum ada transaksi terekam. Anda dapat membuat transaksi di menu Kasir POS, atau klik tombol di samping untuk mengimpor transaksi demo historis untuk visualisasi grafik.</p>
            </div>
        </div>
        <button onclick="seedDemoTransactions()" class="cursor-pointer inline-flex items-center px-4 py-2 bg-amber-600 hover:bg-amber-700 text-xs font-semibold text-white rounded-lg transition-all shadow-md">
            <i data-lucide="database-backup" class="w-3.5 h-3.5 mr-1.5"></i>
            Import Transaksi Demo
        </button>
    </div>
    <?php endif; ?>

    <!-- STATS GRID CARD -->
    <div class="grid grid-cols-1 sm:grid-cols-2 lg:grid-cols-4 gap-6 mb-8">
        
        <!-- Total Penjualan Hari Ini -->
        <div class="bg-white border border-slate-200 rounded-2xl p-5 shadow-sm hover:shadow-md transition-all">
            <div class="flex items-center justify-between mb-4">
                <span class="text-xs font-semibold uppercase tracking-wider text-slate-500 font-mono">Penjualan Hari Ini</span>
                <div class="w-9 h-9 bg-emerald-500/10 text-emerald-600 rounded-xl flex items-center justify-center">
                    <i data-lucide="trending-up" class="w-5 h-5"></i>
                </div>
            </div>
            <h3 class="text-2xl font-bold text-slate-900 tracking-tight font-sans"><?= format_rupiah($sales_today) ?></h3>
            <p class="text-xs text-slate-400 mt-1 font-mono">Total omset kotor terekam</p>
        </div>

        <!-- Jumlah Transaksi Hari Ini -->
        <div class="bg-white border border-slate-200 rounded-2xl p-5 shadow-sm hover:shadow-md transition-all">
            <div class="flex items-center justify-between mb-4">
                <span class="text-xs font-semibold uppercase tracking-wider text-slate-500 font-mono">Transaksi Hari Ini</span>
                <div class="w-9 h-9 bg-blue-500/10 text-blue-600 rounded-xl flex items-center justify-center">
                    <i data-lucide="shopping-bag" class="w-5 h-5"></i>
                </div>
            </div>
            <h3 class="text-2xl font-bold text-slate-900 tracking-tight font-sans"><?= number_format($tx_count_today, 0) ?></h3>
            <p class="text-xs text-slate-400 mt-1 font-mono"><?= number_format($items_sold_today, 0) ?> produk terjual hari ini</p>
        </div>

        <!-- Estimasi Keuntungan -->
        <div class="bg-white border border-slate-200 rounded-2xl p-5 shadow-sm hover:shadow-md transition-all">
            <div class="flex items-center justify-between mb-4">
                <span class="text-xs font-semibold uppercase tracking-wider text-slate-500 font-mono">Estimasi Laba Bersih</span>
                <div class="w-9 h-9 bg-indigo-500/10 text-indigo-600 rounded-xl flex items-center justify-center">
                    <i data-lucide="coins" class="w-5 h-5"></i>
                </div>
            </div>
            <h3 class="text-2xl font-bold text-slate-900 tracking-tight font-sans"><?= format_rupiah($profit_today) ?></h3>
            <p class="text-xs text-slate-400 mt-1 font-mono">Omset minus modal pokok</p>
        </div>

        <!-- Stok Menipis / Out of Stock -->
        <div class="bg-white border border-slate-200 rounded-2xl p-5 shadow-sm hover:shadow-md transition-all">
            <div class="flex items-center justify-between mb-4">
                <span class="text-xs font-semibold uppercase tracking-wider text-slate-500 font-mono">Status Inventory</span>
                <div class="w-9 h-9 bg-red-500/10 text-red-600 rounded-xl flex items-center justify-center">
                    <i data-lucide="alert-triangle" class="w-5 h-5"></i>
                </div>
            </div>
            <div class="flex items-baseline space-x-2">
                <h3 class="text-2xl font-bold text-slate-900 tracking-tight font-sans"><?= $low_stock_count ?></h3>
                <span class="text-xs font-medium text-amber-600 bg-amber-50 px-2 py-0.5 rounded border border-amber-200">Menipis</span>
                <h3 class="text-2xl font-bold text-slate-900 tracking-tight font-sans pl-2 border-l border-slate-200"><?= $out_of_stock_count ?></h3>
                <span class="text-xs font-medium text-red-600 bg-red-50 px-2 py-0.5 rounded border border-red-200">Habis</span>
            </div>
            <p class="text-xs text-slate-400 mt-2 font-mono">Dari total <?= $total_products ?> sku aktif</p>
        </div>
    </div>

    <!-- REVENUE COMPARISONS -->
    <div class="grid grid-cols-1 md:grid-cols-3 gap-6 mb-8">
        <div class="bg-white border border-slate-200 rounded-2xl p-5 shadow-sm flex items-center space-x-4">
            <div class="p-3.5 bg-emerald-50 text-emerald-600 rounded-2xl">
                <i data-lucide="calendar-range" class="w-6 h-6"></i>
            </div>
            <div>
                <p class="text-xs font-semibold text-slate-400 font-mono uppercase">Pendapatan Minggu Ini</p>
                <h4 class="text-lg font-bold text-slate-900 mt-0.5"><?= format_rupiah($sales_week) ?></h4>
            </div>
        </div>
        <div class="bg-white border border-slate-200 rounded-2xl p-5 shadow-sm flex items-center space-x-4">
            <div class="p-3.5 bg-sky-50 text-sky-600 rounded-2xl">
                <i data-lucide="calendar-check-2" class="w-6 h-6"></i>
            </div>
            <div>
                <p class="text-xs font-semibold text-slate-400 font-mono uppercase">Pendapatan Bulan Ini</p>
                <h4 class="text-lg font-bold text-slate-900 mt-0.5"><?= format_rupiah($sales_month) ?></h4>
            </div>
        </div>
        <div class="bg-white border border-slate-200 rounded-2xl p-5 shadow-sm flex items-center space-x-4">
            <div class="p-3.5 bg-slate-50 text-slate-600 rounded-2xl">
                <i data-lucide="users" class="w-6 h-6"></i>
            </div>
            <div>
                <p class="text-xs font-semibold text-slate-400 font-mono uppercase">Total Pelanggan Member</p>
                <h4 class="text-lg font-bold text-slate-900 mt-0.5"><?= number_format($total_customers, 0) ?> Member</h4>
            </div>
        </div>
    </div>

    <!-- CHARTS PANEL SECTION -->
    <div class="grid grid-cols-1 lg:grid-cols-2 gap-8 mb-8">
        <!-- 7 Days Sales Trend Chart -->
        <div class="bg-white border border-slate-200 rounded-2xl p-5 shadow-sm">
            <h3 class="text-sm font-bold text-slate-800 uppercase tracking-wider mb-4 border-b border-slate-100 pb-2">Tren Penjualan 7 Hari Terakhir</h3>
            <div class="h-72 w-full flex items-center justify-center">
                <canvas id="trendChart"></canvas>
            </div>
        </div>

        <!-- Best Selling Products Chart -->
        <div class="bg-white border border-slate-200 rounded-2xl p-5 shadow-sm">
            <h3 class="text-sm font-bold text-slate-800 uppercase tracking-wider mb-4 border-b border-slate-100 pb-2">Top 5 Produk Terlaris</h3>
            <div class="h-72 w-full flex items-center justify-center">
                <canvas id="bestSellingChart"></canvas>
            </div>
        </div>

        <!-- Sales by Category -->
        <div class="bg-white border border-slate-200 rounded-2xl p-5 shadow-sm">
            <h3 class="text-sm font-bold text-slate-800 uppercase tracking-wider mb-4 border-b border-slate-100 pb-2">Penjualan Berdasarkan Kategori</h3>
            <div class="h-72 w-full flex items-center justify-center">
                <canvas id="categoryChart"></canvas>
            </div>
        </div>

        <!-- Sales by Payment Method -->
        <div class="bg-white border border-slate-200 rounded-2xl p-5 shadow-sm">
            <h3 class="text-sm font-bold text-slate-800 uppercase tracking-wider mb-4 border-b border-slate-100 pb-2">Metode Pembayaran Terfavorit</h3>
            <div class="h-72 w-full flex items-center justify-center">
                <canvas id="paymentChart"></canvas>
            </div>
        </div>
    </div>

    <!-- RECENT TRANSACTIONS TABLE -->
    <div class="bg-white border border-slate-200 rounded-2xl shadow-sm overflow-hidden">
        <div class="px-6 py-5 border-b border-slate-200 flex justify-between items-center bg-slate-50/50">
            <div>
                <h3 class="text-sm font-bold text-slate-800 uppercase tracking-wider">Transaksi Terbaru</h3>
                <p class="text-xs text-slate-400 mt-0.5">Daftar 5 transaksi retail terakhir yang diproses kasir.</p>
            </div>
            <a href="/cashier/transactions.php" class="text-xs font-semibold text-emerald-600 hover:text-emerald-700 hover:underline inline-flex items-center">
                Lihat Semua Riwayat
                <i data-lucide="arrow-right" class="w-3.5 h-3.5 ml-1"></i>
            </a>
        </div>
        
        <div class="overflow-x-auto">
            <table class="w-full text-left text-sm border-collapse">
                <thead>
                    <tr class="bg-slate-100 text-slate-600 font-mono text-xs uppercase border-b border-slate-200">
                        <th class="px-6 py-3 font-semibold">No Invoice</th>
                        <th class="px-6 py-3 font-semibold">Pelanggan</th>
                        <th class="px-6 py-3 font-semibold">Kasir</th>
                        <th class="px-6 py-3 font-semibold text-right">Total Transaksi</th>
                        <th class="px-6 py-3 font-semibold text-center">Metode</th>
                        <th class="px-6 py-3 font-semibold">Waktu</th>
                        <th class="px-6 py-3 font-semibold text-center">Status</th>
                    </tr>
                </thead>
                <tbody class="divide-y divide-slate-100">
                    <?php if (empty($recent_tx)): ?>
                        <tr>
                            <td colspan="7" class="text-center py-12 text-slate-400">
                                <div class="flex flex-col items-center justify-center">
                                    <i data-lucide="receipt" class="w-10 h-10 text-slate-300 mb-2"></i>
                                    <p class="text-sm font-semibold">Belum ada transaksi terekam</p>
                                </div>
                            </td>
                        </tr>
                    <?php else: ?>
                        <?php foreach ($recent_tx as $tx): ?>
                            <tr class="hover:bg-slate-50/50 transition-colors">
                                <td class="px-6 py-4 font-semibold text-slate-900 mono-text">
                                    <a href="/cashier/receipt.php?invoice=<?= urlencode($tx['invoice_no']) ?>" class="hover:text-emerald-600 hover:underline">
                                        <?= esc($tx['invoice_no']) ?>
                                    </a>
                                </td>
                                <td class="px-6 py-4 text-slate-600">
                                    <?= esc($tx['customer_name'] ?? 'Guest / Non-Member') ?>
                                </td>
                                <td class="px-6 py-4 text-slate-600">
                                    <?= esc($tx['cashier_name']) ?>
                                </td>
                                <td class="px-6 py-4 text-right font-bold text-slate-900 font-sans">
                                    <?= format_rupiah($tx['total']) ?>
                                </td>
                                <td class="px-6 py-4 text-center">
                                    <span class="inline-block px-2.5 py-1 text-[11px] font-bold font-mono tracking-wider rounded-md bg-slate-100 border border-slate-200 text-slate-700 uppercase">
                                        <?= esc($tx['payment_method']) ?>
                                    </span>
                                </td>
                                <td class="px-6 py-4 text-slate-500 text-xs">
                                    <?= date('d-m-Y H:i', strtotime($tx['created_at'])) ?>
                                </td>
                                <td class="px-6 py-4 text-center">
                                    <span class="inline-flex items-center px-2.5 py-0.5 rounded-full text-xs font-semibold bg-emerald-100 text-emerald-800">
                                        <span class="w-1.5 h-1.5 bg-emerald-500 rounded-full mr-1.5"></span>
                                        Selesai
                                    </span>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                    <?php endif; ?>
                </tbody>
            </table>
        </div>
    </div>
</main>

<!-- Chart.js CDN -->
<script src="https://cdn.jsdelivr.net/npm/chart.js"></script>

<script>
    // 1. Update Live Clock dynamically
    function updateClock() {
        const clockEl = document.getElementById('live-clock');
        if (clockEl) {
            const now = new Date();
            clockEl.textContent = now.toLocaleTimeString('id-ID');
        }
    }
    setInterval(updateClock, 1000);
    updateClock();

    // 2. Load Chart JS configurations
    document.addEventListener('DOMContentLoaded', () => {
        // A. Trend Chart (Line)
        const ctxTrend = document.getElementById('trendChart');
        if (ctxTrend) {
            new Chart(ctxTrend, {
                type: 'line',
                data: {
                    labels: <?= json_encode($dates_7) ?>,
                    datasets: [{
                        label: 'Penjualan (Rupiah)',
                        data: <?= json_encode($revenues_7) ?>,
                        borderColor: '#14b8a6',
                        backgroundColor: 'rgba(20, 184, 166, 0.05)',
                        borderWidth: 3,
                        fill: true,
                        tension: 0.35,
                        pointBackgroundColor: '#0f766e',
                        pointBorderColor: '#ffffff',
                        pointHoverRadius: 7,
                        pointRadius: 4
                    }]
                },
                options: {
                    responsive: true,
                    maintainAspectRatio: false,
                    plugins: { legend: { display: false } },
                    scales: {
                        y: { 
                            beginAtZero: true,
                            grid: { borderDash: [4, 4] },
                            ticks: { callback: value => 'Rp ' + value.toLocaleString('id-ID') }
                        },
                        x: { grid: { display: false } }
                    }
                }
            });
        }

        // B. Best Selling Products (Bar)
        const ctxBest = document.getElementById('bestSellingChart');
        if (ctxBest) {
            new Chart(ctxBest, {
                type: 'bar',
                data: {
                    labels: <?= json_encode($top_products_names) ?>,
                    datasets: [{
                        data: <?= json_encode($top_products_qty) ?>,
                        backgroundColor: '#14b8a6',
                        borderRadius: 6,
                        barThickness: 24
                    }]
                },
                options: {
                    indexAxis: 'y',
                    responsive: true,
                    maintainAspectRatio: false,
                    plugins: { legend: { display: false } },
                    scales: {
                        x: { beginAtZero: true, grid: { borderDash: [4, 4] } },
                        y: { grid: { display: false } }
                    }
                }
            });
        }

        // C. Sales by Category (Doughnut)
        const ctxCat = document.getElementById('categoryChart');
        if (ctxCat) {
            new Chart(ctxCat, {
                type: 'doughnut',
                data: {
                    labels: <?= json_encode($cat_names) ?>,
                    datasets: [{
                        data: <?= json_encode($cat_totals) ?>,
                        backgroundColor: ['#14b8a6', '#3b82f6', '#f59e0b', '#ef4444', '#8b5cf6', '#6366f1', '#ec4899', '#64748b']
                    }]
                },
                options: {
                    responsive: true,
                    maintainAspectRatio: false,
                    plugins: {
                        legend: { position: 'right', labels: { boxWidth: 12, font: { size: 10 } } }
                    },
                    cutout: '65%'
                }
            });
        }

        // D. Sales by Payment (Pie)
        const ctxPay = document.getElementById('paymentChart');
        if (ctxPay) {
            new Chart(ctxPay, {
                type: 'pie',
                data: {
                    labels: <?= json_encode($payment_methods) ?>,
                    datasets: [{
                        data: <?= json_encode($payment_totals) ?>,
                        backgroundColor: ['#6366f1', '#14b8a6', '#f59e0b', '#ec4899', '#3b82f6', '#8b5cf6']
                    }]
                },
                options: {
                    responsive: true,
                    maintainAspectRatio: false,
                    plugins: {
                        legend: { position: 'right', labels: { boxWidth: 12, font: { size: 10 } } }
                    }
                }
            });
        }
    });

    // Seed Demo Transactions Trigger
    function seedDemoTransactions() {
        if (confirm('Apakah Anda ingin mengimpor data transaksi demo historis selama 7 hari terakhir? Hal ini berguna untuk memvisualisasikan grafik secara profesional.')) {
            fetch('/api/transactions.php?action=seed_demo', { method: 'POST' })
            .then(res => res.json())
            .then(data => {
                if (data.success) {
                    alert('Impor data transaksi demo berhasil! Halaman akan dimuat ulang.');
                    location.reload();
                } else {
                    alert('Gagal impor data: ' + data.message);
                }
            })
            .catch(err => {
                console.error(err);
                alert('Terjadi kesalahan koneksi.');
            });
        }
    }
</script>

<?php include __DIR__ . '/../includes/footer.php'; ?>
