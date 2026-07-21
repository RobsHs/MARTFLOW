<?php
// /admin/reports.php
// MartFlow POS - Sales & Financial Analytics Report

require_once __DIR__ . '/../includes/functions.php';
require_role(['ADMIN', 'MANAGER']);

$db = Database::getConnection();

// --- GET FILTER PARAMETERS (Defaults to current month) ---
$start_date = trim($_GET['start_date'] ?? date('Y-m-01'));
$end_date = trim($_GET['end_date'] ?? date('Y-m-d'));
$cashier_id = trim($_GET['cashier_id'] ?? '');
$payment_method = trim($_GET['payment_method'] ?? '');

// Common where clauses
$where_sql = " WHERE DATE(t.created_at) >= :start_date AND DATE(t.created_at) <= :end_date";
$params = [
    'start_date' => $start_date,
    'end_date' => $end_date
];

if (!empty($cashier_id)) {
    $where_sql .= " AND t.user_id = :cashier_id";
    $params['cashier_id'] = $cashier_id;
}

if (!empty($payment_method)) {
    $where_sql .= " AND t.payment_method = :payment_method";
    $params['payment_method'] = $payment_method;
}

// --- EXPORT TO CSV HANDLER ---
if (isset($_GET['export']) && $_GET['export'] === 'csv') {
    // Clear buffer and write headers
    ob_end_clean();
    header('Content-Type: text/csv; charset=utf-8');
    header('Content-Disposition: attachment; filename=MartFlow_Sales_Report_' . $start_date . '_to_' . $end_date . '.csv');
    
    $output = fopen('php://output', 'w');
    
    // Write CSV Column Headers
    fputcsv($output, ['No', 'Nomor Invoice', 'Tanggal & Waktu', 'Kasir', 'Pelanggan Member', 'Metode Bayar', 'Subtotal', 'Diskon', 'PPN (11%)', 'Total Tagihan', 'Uang Dibayar']);
    
    // Fetch all filtered records
    $stmt_export = $db->prepare("
        SELECT t.*, u.name as cashier_name, c.name as customer_name 
        FROM transactions t
        JOIN users u ON t.user_id = u.id
        LEFT JOIN customers c ON t.customer_id = c.id
        $where_sql
        ORDER BY t.created_at DESC
    ");
    $stmt_export->execute($params);
    $records = $stmt_export->fetchAll();
    
    $no = 1;
    foreach ($records as $r) {
        fputcsv($output, [
            $no++,
            $r['invoice_no'],
            $r['created_at'],
            $r['cashier_name'],
            $r['customer_name'] ?: 'Guest',
            $r['payment_method'],
            round($r['subtotal']),
            round($r['discount']),
            round($r['tax']),
            round($r['total']),
            round($r['amount_paid'])
        ]);
    }
    
    fclose($output);
    exit();
}

// --- KEY SALES STATS KPI METRICS ---
$stats_stmt = $db->prepare("
    SELECT 
        COUNT(t.id) as total_tx,
        SUM(t.subtotal) as gross_sales,
        SUM(t.discount) as total_discount,
        SUM(t.tax) as total_tax,
        SUM(t.total) as net_sales
    FROM transactions t
    $where_sql
");
$stats_stmt->execute($params);
$kpi = $stats_stmt->fetch();

$total_transactions = intval($kpi['total_tx']);
$gross_sales = floatval($kpi['gross_sales'] ?? 0);
$total_discount = floatval($kpi['total_discount'] ?? 0);
$total_tax = floatval($kpi['total_tax'] ?? 0);
$net_sales = floatval($kpi['net_sales'] ?? 0);

$avg_invoice = $total_transactions > 0 ? ($net_sales / $total_transactions) : 0;

// --- CHART DATA: DAILY SALES TREND ---
$chart_stmt = $db->prepare("
    SELECT DATE(t.created_at) as sale_date, SUM(t.total) as daily_total
    FROM transactions t
    $where_sql
    GROUP BY DATE(t.created_at)
    ORDER BY DATE(t.created_at) ASC
");
$chart_stmt->execute($params);
$trend_raw = $chart_stmt->fetchAll();

$chart_labels = [];
$chart_values = [];
foreach ($trend_raw as $t) {
    $chart_labels[] = date('d M', strtotime($t['sale_date']));
    $chart_values[] = floatval($t['daily_total']);
}

// --- FETCH TRANSACTION HISTORY GRID ---
$hist_stmt = $db->prepare("
    SELECT t.*, u.name as cashier_name, c.name as customer_name 
    FROM transactions t
    JOIN users u ON t.user_id = u.id
    LEFT JOIN customers c ON t.customer_id = c.id
    $where_sql
    ORDER BY t.created_at DESC
");
$hist_stmt->execute($params);
$transactions = $hist_stmt->fetchAll();

// Auxiliary listings for filter dropdowns
$cashiers = $db->query("SELECT id, name FROM users WHERE status = 'ACTIVE' ORDER BY name ASC")->fetchAll();

$page_title = 'Laporan Keuangan';
include __DIR__ . '/../includes/header.php';
include __DIR__ . '/../includes/sidebar.php';
?>

<!-- Main Content Area -->
<main class="flex-1 overflow-y-auto bg-slate-50 p-6 md:p-8">
    <!-- Page Header -->
    <div class="flex flex-col md:flex-row md:items-center md:justify-between mb-8 space-y-4 md:space-y-0">
        <div>
            <h2 class="text-2xl font-bold text-slate-900 tracking-tight">Laporan Keuangan & Penjualan</h2>
            <p class="text-sm text-slate-500 mt-1">Saring data, analisa grafik tren, cetak rangkuman performa kasir dan export ke berkas CSV excel.</p>
        </div>
        
        <!-- Action Buttons -->
        <div class="flex items-center space-x-3.5">
            <button onclick="window.print()" class="no-print cursor-pointer inline-flex items-center px-4 py-2.5 border border-slate-200 bg-white text-slate-700 font-semibold text-xs rounded-xl hover:bg-slate-50 transition-all">
                <i data-lucide="printer" class="w-4 h-4 mr-2"></i> Cetak Laporan
            </button>
            
            <a href="?export=csv&start_date=<?= $start_date ?>&end_date=<?= $end_date ?>&cashier_id=<?= $cashier_id ?>&payment_method=<?= $payment_method ?>"
               class="no-print cursor-pointer inline-flex items-center px-4.5 py-2.5 bg-emerald-600 hover:bg-emerald-700 text-sm font-semibold text-white rounded-xl shadow-lg shadow-emerald-500/10 transition-all">
                <i data-lucide="download" class="w-4.5 h-4.5 mr-2"></i> Export Ke Excel/CSV
            </a>
        </div>
    </div>

    <!-- REPORT FILTERS BAR (Hidden during printing) -->
    <div class="no-print bg-white border border-slate-200 rounded-2xl p-5 shadow-sm mb-8">
        <form method="GET" class="grid grid-cols-1 md:grid-cols-12 gap-4">
            <!-- Start Date -->
            <div class="md:col-span-3">
                <label for="rep-start" class="block text-[10px] font-bold text-slate-400 uppercase tracking-widest mb-1.5">Tanggal Mulai</label>
                <input type="date" name="start_date" id="rep-start" value="<?= esc($start_date) ?>" required class="block w-full px-3 py-2 border border-slate-200 rounded-xl text-xs font-mono bg-slate-50">
            </div>

            <!-- End Date -->
            <div class="md:col-span-3">
                <label for="rep-end" class="block text-[10px] font-bold text-slate-400 uppercase tracking-widest mb-1.5">Tanggal Selesai</label>
                <input type="date" name="end_date" id="rep-end" value="<?= esc($end_date) ?>" required class="block w-full px-3 py-2 border border-slate-200 rounded-xl text-xs font-mono bg-slate-50">
            </div>

            <!-- Cashier Filter -->
            <div class="md:col-span-3">
                <label for="rep-cashier" class="block text-[10px] font-bold text-slate-400 uppercase tracking-widest mb-1.5">Petugas Kasir</label>
                <select name="cashier_id" id="rep-cashier" class="block w-full px-3 py-2 border border-slate-200 rounded-xl text-xs bg-slate-50">
                    <option value="">Semua Kasir</option>
                    <?php foreach ($cashiers as $u): ?>
                        <option value="<?= $u['id'] ?>" <?= $cashier_id == $u['id'] ? 'selected' : '' ?>><?= esc($u['name']) ?></option>
                    <?php endforeach; ?>
                </select>
            </div>

            <!-- Payment Method -->
            <div class="md:col-span-2">
                <label for="rep-pay" class="block text-[10px] font-bold text-slate-400 uppercase tracking-widest mb-1.5">Metode Bayar</label>
                <select name="payment_method" id="rep-pay" class="block w-full px-3 py-2 border border-slate-200 rounded-xl text-xs bg-slate-50">
                    <option value="">Semua</option>
                    <option value="CASH" <?= $payment_method === 'CASH' ? 'selected' : '' ?>>CASH</option>
                    <option value="QRIS" <?= $payment_method === 'QRIS' ? 'selected' : '' ?>>QRIS</option>
                    <option value="DEBIT" <?= $payment_method === 'DEBIT' ? 'selected' : '' ?>>DEBIT</option>
                    <option value="CREDIT" <?= $payment_method === 'CREDIT' ? 'selected' : '' ?>>KREDIT</option>
                    <option value="E-WALLET" <?= $payment_method === 'E-WALLET' ? 'selected' : '' ?>>E-WALLET</option>
                </select>
            </div>

            <!-- Submit button -->
            <div class="md:col-span-1 flex items-end">
                <button type="submit" class="cursor-pointer w-full bg-slate-900 hover:bg-slate-800 text-white rounded-xl text-xs font-semibold py-2.5 text-center transition-all">
                    Saring
                </button>
            </div>
        </form>
    </div>

    <!-- MAIN STATISTICS GRID (KPIs) -->
    <div class="grid grid-cols-2 lg:grid-cols-5 gap-4 mb-8">
        <!-- gross revenue -->
        <div class="bg-white border border-slate-200 p-5 rounded-2xl shadow-sm">
            <p class="text-[10px] font-bold text-slate-400 uppercase tracking-widest font-mono">Penjualan Kotor</p>
            <h3 class="text-lg font-extrabold text-slate-800 mt-1.5 font-mono"><?= format_rupiah($gross_sales) ?></h3>
            <span class="text-[9px] text-slate-400 font-medium">Sebelum diskon & PPN</span>
        </div>

        <!-- Diskon Terpotong -->
        <div class="bg-white border border-slate-200 p-5 rounded-2xl shadow-sm">
            <p class="text-[10px] font-bold text-slate-400 uppercase tracking-widest font-mono">Diskon Terpakai</p>
            <h3 class="text-lg font-extrabold text-red-600 mt-1.5 font-mono">-<?= format_rupiah($total_discount) ?></h3>
            <span class="text-[9px] text-red-400 font-medium">Potongan kampanye</span>
        </div>

        <!-- PPN 11% Collected -->
        <div class="bg-white border border-slate-200 p-5 rounded-2xl shadow-sm">
            <p class="text-[10px] font-bold text-slate-400 uppercase tracking-widest font-mono">PPN Terkumpul</p>
            <h3 class="text-lg font-extrabold text-slate-800 mt-1.5 font-mono"><?= format_rupiah($total_tax) ?></h3>
            <span class="text-[9px] text-slate-400 font-medium">Pajak negara (11%)</span>
        </div>

        <!-- NET REVENUE -->
        <div class="bg-emerald-950 text-white p-5 rounded-2xl shadow-lg shadow-emerald-950/10 border border-emerald-900">
            <p class="text-[10px] font-bold text-emerald-400 uppercase tracking-widest font-mono">Omset Bersih (Nett)</p>
            <h3 class="text-lg font-extrabold text-emerald-300 mt-1.5 font-mono"><?= format_rupiah($net_sales) ?></h3>
            <span class="text-[9px] text-emerald-400 font-medium">Kas masuk riil (Nett)</span>
        </div>

        <!-- Count and Avg invoice -->
        <div class="bg-white border border-slate-200 p-5 rounded-2xl shadow-sm col-span-2 lg:col-span-1">
            <p class="text-[10px] font-bold text-slate-400 uppercase tracking-widest font-mono">Total Struk</p>
            <h3 class="text-lg font-extrabold text-slate-900 mt-1.5 font-mono"><?= $total_transactions ?> Struk</h3>
            <span class="text-[9px] text-slate-400 font-medium">Rata-rata: <?= format_rupiah($avg_invoice) ?> / Struk</span>
        </div>
    </div>

    <div class="grid grid-cols-1 lg:grid-cols-3 gap-6 mb-8">
        <!-- TREND LINE CHART (Left 2/3) -->
        <div class="lg:col-span-2 bg-white border border-slate-200 p-5 rounded-2xl shadow-sm flex flex-col no-print">
            <h3 class="font-bold text-slate-900 text-sm mb-4">Grafik Perkembangan Omset Harian</h3>
            <div class="flex-1 min-h-[220px] relative">
                <canvas id="salesTrendChart"></canvas>
            </div>
        </div>

        <!-- METHOD DISTRIBUTION (Right 1/3) -->
        <div class="bg-white border border-slate-200 p-5 rounded-2xl shadow-sm flex flex-col">
            <h3 class="font-bold text-slate-900 text-sm mb-4">Rincian Pembayaran Kasir</h3>
            <div class="flex-1 space-y-3.5 flex flex-col justify-center">
                <?php
                // Query distribution
                $dist_stmt = $db->prepare("
                    SELECT payment_method, COUNT(id) as cnt, SUM(total) as val 
                    FROM transactions t
                    $where_sql 
                    GROUP BY payment_method
                ");
                $dist_stmt->execute($params);
                $distribution = $dist_stmt->fetchAll();
                
                $total_sum_val = max(1.0, $net_sales);

                if (empty($distribution)): ?>
                    <p class="text-center text-xs text-slate-400 py-10">Belum ada rincian data transaksi.</p>
                <?php else: ?>
                    <?php foreach ($distribution as $d): 
                        $pct = ($d['val'] / $total_sum_val) * 100;
                        ?>
                        <div class="space-y-1.5">
                            <div class="flex justify-between text-xs font-semibold">
                                <span class="text-slate-700"><?= $d['payment_method'] ?> (<?= $d['cnt'] ?>x)</span>
                                <span class="font-mono text-slate-900"><?= format_rupiah($d['val']) ?></span>
                            </div>
                            <div class="w-full bg-slate-100 h-2 rounded-full overflow-hidden">
                                <div class="bg-emerald-500 h-full rounded-full" style="width: <?= $pct ?>%"></div>
                            </div>
                        </div>
                    <?php endforeach; ?>
                <?php endif; ?>
            </div>
        </div>
    </div>

    <!-- TRANSACTIONS LIST TABLE -->
    <div class="bg-white border border-slate-200 rounded-2xl shadow-sm overflow-hidden">
        <div class="px-5 py-4 border-b border-slate-100 flex items-center justify-between bg-slate-50/50">
            <h3 class="font-bold text-slate-900 text-sm">Riwayat Buku Transaksi Kasir</h3>
            <span class="text-xs text-slate-500">Menampilkan <?= count($transactions) ?> log invoice</span>
        </div>
        <div class="overflow-x-auto">
            <table class="w-full text-left text-sm border-collapse">
                <thead>
                    <tr class="bg-slate-50 text-slate-600 font-mono text-xs uppercase border-b border-slate-200">
                        <th class="px-6 py-4 font-semibold">No Invoice</th>
                        <th class="px-6 py-4 font-semibold">Tanggal & Waktu</th>
                        <th class="px-6 py-4 font-semibold">Kasir</th>
                        <th class="px-6 py-4 font-semibold">Pelanggan Member</th>
                        <th class="px-6 py-4 font-semibold">Metode Bayar</th>
                        <th class="px-6 py-4 font-semibold text-right">Subtotal</th>
                        <th class="px-6 py-4 font-semibold text-right">Potongan</th>
                        <th class="px-6 py-4 font-semibold text-right">PPN (11%)</th>
                        <th class="px-6 py-4 font-semibold text-right">Total Tagihan</th>
                        <th class="px-6 py-4 text-center">Struk</th>
                    </tr>
                </thead>
                <tbody class="divide-y divide-slate-100">
                    <?php if (empty($transactions)): ?>
                        <tr>
                            <td colspan="10" class="text-center py-12 text-slate-400">
                                <div class="flex flex-col items-center justify-center">
                                    <i data-lucide="receipt-text" class="w-10 h-10 text-slate-300 mb-2"></i>
                                    <p class="text-sm font-semibold">Tidak ada transaksi ditemukan untuk filter ini</p>
                                </div>
                            </td>
                        </tr>
                    <?php else: ?>
                        <?php foreach ($transactions as $t): ?>
                            <tr class="hover:bg-slate-50/50 transition-colors text-xs">
                                <td class="px-6 py-4">
                                    <span class="font-mono font-bold text-slate-900 text-xs"><?= esc($t['invoice_no']) ?></span>
                                </td>
                                <td class="px-6 py-4 font-mono text-slate-500">
                                    <?= date('d-m-Y H:i', strtotime($t['created_at'])) ?>
                                </td>
                                <td class="px-6 py-4 text-slate-700 font-medium">
                                    <?= esc($t['cashier_name']) ?>
                                </td>
                                <td class="px-6 py-4 text-slate-600">
                                    <?= esc($t['customer_name'] ?: 'Guest (Bukan Member)') ?>
                                </td>
                                <td class="px-6 py-4 font-bold text-slate-700 text-center">
                                    <?= esc($t['payment_method']) ?>
                                </td>
                                <td class="px-6 py-4 text-right font-mono text-slate-500">
                                    <?= number_format($t['subtotal'], 0, ',', '.') ?>
                                </td>
                                <td class="px-6 py-4 text-right font-mono text-red-600">
                                    -<?= number_format($t['discount'], 0, ',', '.') ?>
                                </td>
                                <td class="px-6 py-4 text-right font-mono text-slate-500">
                                    <?= number_format($t['tax'], 0, ',', '.') ?>
                                </td>
                                <td class="px-6 py-4 text-right font-bold font-mono text-slate-900 text-sm">
                                    <?= format_rupiah($t['total']) ?>
                                </td>
                                <td class="px-6 py-4 text-center">
                                    <a href="/cashier/receipt.php?invoice=<?= urlencode($t['invoice_no']) ?>" target="_blank"
                                       class="inline-flex p-1 bg-slate-100 hover:bg-slate-200 text-slate-600 hover:text-slate-900 rounded transition-all" title="Cetak Ulang">
                                        <i data-lucide="external-link" class="w-3.5 h-3.5"></i>
                                    </a>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                    <?php endif; ?>
                </tbody>
            </table>
        </div>
    </div>
</main>

<!-- Chart JS load -->
<script src="https://cdn.jsdelivr.net/npm/chart.js"></script>
<script>
    document.addEventListener('DOMContentLoaded', () => {
        const labels = <?= json_encode($chart_labels) ?>;
        const values = <?= json_encode($chart_values) ?>;

        const ctx = document.getElementById('salesTrendChart').getContext('2d');
        new Chart(ctx, {
            type: 'line',
            data: {
                labels: labels,
                datasets: [{
                    label: 'Omset Bersih Harian',
                    data: values,
                    borderColor: '#10b981',
                    backgroundColor: 'rgba(16, 185, 129, 0.05)',
                    borderWidth: 3,
                    fill: true,
                    tension: 0.35,
                    pointBackgroundColor: '#10b981',
                    pointHoverRadius: 6
                }]
            },
            options: {
                responsive: true,
                maintainAspectRatio: false,
                plugins: {
                    legend: { display: false }
                },
                scales: {
                    x: {
                        grid: { display: false },
                        ticks: { font: { family: 'Inconsolata', size: 10 } }
                    },
                    y: {
                        grid: { color: '#f1f5f9' },
                        ticks: {
                            font: { family: 'Inconsolata', size: 10 },
                            callback: function(value) {
                                return 'Rp ' + value.toLocaleString('id-ID');
                            }
                        }
                    }
                }
            }
        });
    });
</script>

<?php include __DIR__ . '/../includes/footer.php'; ?>
