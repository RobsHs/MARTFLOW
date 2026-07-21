<?php
// /admin/logs.php
// MartFlow POS - System Audit Logs (ADMIN ONLY)

require_once __DIR__ . '/../includes/functions.php';
require_role(['ADMIN']); // STRICT ADMIN ONLY

$page_title = 'Audit Logs';
$db = Database::getConnection();

// --- PAGINATION & SEARCH FILTERS ---
$search = trim($_GET['search'] ?? '');
$limit = 20;
$page = isset($_GET['page']) ? max(1, intval($_GET['page'])) : 1;
$offset = ($page - 1) * $limit;

$sql_where = " WHERE 1=1";
$params = [];

if (!empty($search)) {
    $sql_where .= " AND (username LIKE :search OR activity LIKE :search OR ip_address LIKE :search)";
    $params['search'] = "%$search%";
}

// Count total matching logs
$count_stmt = $db->prepare("SELECT COUNT(*) FROM activity_logs $sql_where");
$count_stmt->execute($params);
$total_records = intval($count_stmt->fetchColumn());
$total_pages = ceil($total_records / $limit);

// Fetch logs
$fetch_stmt = $db->prepare("
    SELECT * FROM activity_logs 
    $sql_where 
    ORDER BY created_at DESC 
    LIMIT $limit OFFSET $offset
");
$fetch_stmt->execute($params);
$logs = $fetch_stmt->fetchAll();

include __DIR__ . '/../includes/header.php';
include __DIR__ . '/../includes/sidebar.php';
?>

<!-- Main Content Area -->
<main class="flex-1 overflow-y-auto bg-slate-50 p-6 md:p-8">
    <!-- Page Header -->
    <div class="mb-8">
        <h2 class="text-2xl font-bold text-slate-900 tracking-tight">Audit Log Aktivitas Sistem</h2>
        <p class="text-sm text-slate-500 mt-1">Daftar rekam jejak operasi penting, aktivitas login karyawan, penyuntingan harga, dan manipulasi inventori.</p>
    </div>

    <!-- FILTER & SEARCH PANEL -->
    <div class="bg-white border border-slate-200 rounded-2xl p-5 shadow-sm mb-6 max-w-4xl">
        <form method="GET" class="flex space-x-3">
            <div class="relative flex-1">
                <span class="absolute inset-y-0 left-0 pl-3 flex items-center pointer-events-none text-slate-400">
                    <i data-lucide="search" class="w-4 h-4"></i>
                </span>
                <input type="text" name="search" value="<?= esc($search) ?>" 
                       class="block w-full pl-9 pr-3 py-2 border border-slate-200 rounded-xl text-xs focus:outline-none focus:ring-2 focus:ring-emerald-500/25 focus:border-emerald-500 bg-slate-50 focus:bg-white transition-all"
                       placeholder="Cari berdasarkan petugas, keterangan aktivitas, atau alamat IP...">
            </div>
            <button type="submit" class="cursor-pointer bg-slate-900 hover:bg-slate-800 text-white rounded-xl text-xs font-semibold px-5 py-2 transition-all">
                Cari Log
            </button>
            <a href="/admin/logs.php" class="p-2 border border-slate-200 hover:bg-slate-100 rounded-xl transition-all" title="Reset Filters">
                <i data-lucide="rotate-ccw" class="w-4 h-4 text-slate-500"></i>
            </a>
        </form>
    </div>

    <!-- AUDIT LOGS TABLE -->
    <div class="bg-white border border-slate-200 rounded-2xl shadow-sm overflow-hidden max-w-4xl">
        <div class="overflow-x-auto">
            <table class="w-full text-left text-sm border-collapse">
                <thead>
                    <tr class="bg-slate-50 text-slate-600 font-mono text-xs uppercase border-b border-slate-200">
                        <th class="px-6 py-4 font-semibold w-48">Tanggal & Waktu</th>
                        <th class="px-6 py-4 font-semibold w-40">Pengguna Karyawan</th>
                        <th class="px-6 py-4 font-semibold">Tindakan / Aktivitas</th>
                        <th class="px-6 py-4 font-semibold w-36">Alamat IP</th>
                    </tr>
                </thead>
                <tbody class="divide-y divide-slate-100">
                    <?php if (empty($logs)): ?>
                        <tr>
                            <td colspan="4" class="text-center py-12 text-slate-400">
                                <div class="flex flex-col items-center justify-center">
                                    <i data-lucide="scroll" class="w-10 h-10 text-slate-300 mb-2"></i>
                                    <p class="text-sm font-semibold">Tidak ada data log terekam</p>
                                </div>
                            </td>
                        </tr>
                    <?php else: ?>
                        <?php foreach ($logs as $log): ?>
                            <tr class="hover:bg-slate-50/50 transition-colors text-xs">
                                <td class="px-6 py-4 font-mono text-slate-500">
                                    <?= date('d-m-Y H:i:s', strtotime($log['created_at'])) ?>
                                </td>
                                <td class="px-6 py-4 font-semibold text-slate-900">
                                    @<?= esc($log['username'] ?: 'system') ?>
                                </td>
                                <td class="px-6 py-4 text-slate-700 leading-relaxed font-mono">
                                    <?= esc($log['activity']) ?>
                                </td>
                                <td class="px-6 py-4 font-mono text-slate-500 text-xs">
                                    <?= esc($log['ip_address'] ?: '127.0.0.1') ?>
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
                <span class="text-slate-500">Menampilkan <?= count($logs) ?> dari <?= $total_records ?> log aktivitas</span>
                <div class="flex items-center space-x-1">
                    <a href="?page=<?= max(1, $page - 1) ?>&search=<?= urlencode($search) ?>"
                       class="px-3 py-1.5 border border-slate-200 rounded-lg text-slate-600 hover:bg-slate-100 <?= $page <= 1 ? 'pointer-events-none opacity-40' : '' ?>">Sebelumnya</a>
                    
                    <?php for ($i = 1; $i <= $total_pages; $i++): ?>
                        <a href="?page=<?= $i ?>&search=<?= urlencode($search) ?>"
                           class="px-3 py-1.5 rounded-lg border <?= $i === $page ? 'bg-emerald-600 border-emerald-600 text-white shadow-md' : 'border-slate-200 hover:bg-slate-100 text-slate-600' ?>"><?= $i ?></a>
                    <?php endfor; ?>

                    <a href="?page=<?= min($total_pages, $page + 1) ?>&search=<?= urlencode($search) ?>"
                       class="px-3 py-1.5 border border-slate-200 rounded-lg text-slate-600 hover:bg-slate-100 <?= $page >= $total_pages ? 'pointer-events-none opacity-40' : '' ?>">Selanjutnya</a>
                </div>
            </div>
        <?php endif; ?>
    </div>
</main>

<?php include __DIR__ . '/../includes/footer.php'; ?>
