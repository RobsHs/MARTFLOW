<?php
// /includes/sidebar.php
// MartFlow POS - Dynamic Sidebar based on User Role

require_once __DIR__ . '/functions.php';
$user = get_logged_user();
$app_config = include(__DIR__ . '/../config/app.php');
$current_page = basename($_SERVER['PHP_SELF']);

// Helper to determine if menu is active
function is_active($page_name, $current_page) {
    return $page_name === $current_page ? 'bg-emerald-500/10 text-emerald-400 border-l-4 border-emerald-500 rounded-l-none font-semibold shadow-sm' : 'text-slate-400 hover:bg-slate-800/40 hover:text-white transition-all';
}
?>

<!-- Mobile Top Header -->
<div class="md:hidden flex items-center justify-between bg-[#0F172A] text-white px-4 py-3 shadow-md border-b border-slate-800 shrink-0">
    <div class="flex items-center space-x-2">
        <div class="w-8 h-8 rounded bg-emerald-500 flex items-center justify-center font-bold text-white text-lg">M</div>
        <span class="font-bold text-base tracking-wider"><?= esc($app_config['app_name']) ?></span>
    </div>
    <div class="flex items-center space-x-3">
        <span class="text-xs bg-slate-800 text-emerald-400 px-2.5 py-1 rounded-full font-medium border border-emerald-500/20"><?= esc($user['role']) ?></span>
        <button id="mobile-menu-toggle" class="text-slate-300 hover:text-white focus:outline-none">
            <i data-lucide="menu" class="w-6 h-6"></i>
        </button>
    </div>
</div>

<!-- Sidebar Navigation -->
<aside id="sidebar" class="hidden md:flex flex-col w-full md:w-64 bg-[#0F172A] border-r border-slate-800 shrink-0 h-full text-slate-100 z-30 transition-all duration-300">
    <!-- Brand / Logo -->
    <div class="p-5 flex items-center space-x-3 border-b border-slate-800/80 shrink-0">
        <div class="w-10 h-10 rounded-lg bg-emerald-500 flex items-center justify-center font-bold text-white text-xl shadow-lg shadow-emerald-500/30">M</div>
        <div>
            <h1 class="font-bold text-lg tracking-wider text-white"><?= esc($app_config['app_name']) ?></h1>
            <p class="text-[10px] text-slate-400 font-mono tracking-widest uppercase">System Retail v1.0</p>
        </div>
    </div>

    <!-- Active User Profile Summary -->
    <div class="p-4 bg-[#0B0F19]/60 border-b border-slate-800/80 shrink-0">
        <div class="flex items-center space-x-3">
            <div class="w-10 h-10 rounded-full bg-slate-800 flex items-center justify-center text-emerald-400 font-bold border border-slate-700 uppercase">
                <?= substr(esc($user['name']), 0, 2) ?>
            </div>
            <div class="overflow-hidden">
                <p class="text-sm font-semibold text-white truncate"><?= esc($user['name']) ?></p>
                <div class="flex items-center space-x-1.5 mt-0.5">
                    <span class="inline-block w-1.5 h-1.5 rounded-full bg-emerald-500 animate-pulse"></span>
                    <span class="text-[10px] font-semibold uppercase tracking-wider text-emerald-400 font-mono"><?= esc($user['role']) ?></span>
                </div>
            </div>
        </div>
    </div>

    <!-- Scrollable Menu Section -->
    <nav class="flex-1 overflow-y-auto px-3 py-4 space-y-1">
        
        <!-- CASHIER / ALL ROLES: POS -->
        <p class="text-[10px] font-bold tracking-widest text-slate-500 uppercase px-3 py-2">Transaksi</p>
        <a href="/cashier/pos.php" class="flex items-center space-x-3 px-3 py-2.5 rounded-lg text-sm font-medium <?= is_active('pos.php', $current_page) ?>">
            <i data-lucide="shopping-cart" class="w-4 h-4 shrink-0"></i>
            <span>Kasir / POS</span>
        </a>
        <a href="/cashier/transactions.php" class="flex items-center space-x-3 px-3 py-2.5 rounded-lg text-sm font-medium <?= is_active('transactions.php', $current_page) ?>">
            <i data-lucide="receipt" class="w-4 h-4 shrink-0"></i>
            <span>Riwayat Transaksi</span>
        </a>

        <!-- ADMIN & MANAGER ONLY: DASHBOARD & MANAGEMENT -->
        <?php if ($user['role'] === 'ADMIN' || $user['role'] === 'MANAGER'): ?>
            <p class="text-[10px] font-bold tracking-widest text-slate-500 uppercase px-3 py-2 mt-4">Analisis & Stok</p>
            <a href="/admin/dashboard.php" class="flex items-center space-x-3 px-3 py-2.5 rounded-lg text-sm font-medium <?= is_active('dashboard.php', $current_page) ?>">
                <i data-lucide="layout-dashboard" class="w-4 h-4 shrink-0"></i>
                <span>Dashboard</span>
            </a>
            <a href="/admin/products.php" class="flex items-center space-x-3 px-3 py-2.5 rounded-lg text-sm font-medium <?= is_active('products.php', $current_page) ?>">
                <i data-lucide="package" class="w-4 h-4 shrink-0"></i>
                <span>Daftar Produk</span>
            </a>
            <a href="/admin/categories.php" class="flex items-center space-x-3 px-3 py-2.5 rounded-lg text-sm font-medium <?= is_active('categories.php', $current_page) ?>">
                <i data-lucide="tag" class="w-4 h-4 shrink-0"></i>
                <span>Kategori</span>
            </a>
            <a href="/admin/inventory.php" class="flex items-center space-x-3 px-3 py-2.5 rounded-lg text-sm font-medium <?= is_active('inventory.php', $current_page) ?>">
                <i data-lucide="boxes" class="w-4 h-4 shrink-0"></i>
                <span>Stok & Inventory</span>
            </a>
            <a href="/admin/suppliers.php" class="flex items-center space-x-3 px-3 py-2.5 rounded-lg text-sm font-medium <?= is_active('suppliers.php', $current_page) ?>">
                <i data-lucide="truck" class="w-4 h-4 shrink-0"></i>
                <span>Pemasok / Supplier</span>
            </a>
            <a href="/admin/customers.php" class="flex items-center space-x-3 px-3 py-2.5 rounded-lg text-sm font-medium <?= is_active('customers.php', $current_page) ?>">
                <i data-lucide="users" class="w-4 h-4 shrink-0"></i>
                <span>Pelanggan / Member</span>
            </a>
            <a href="/admin/promotions.php" class="flex items-center space-x-3 px-3 py-2.5 rounded-lg text-sm font-medium <?= is_active('promotions.php', $current_page) ?>">
                <i data-lucide="ticket-percent" class="w-4 h-4 shrink-0"></i>
                <span>Diskon & Promo</span>
            </a>
            <a href="/admin/reports.php" class="flex items-center space-x-3 px-3 py-2.5 rounded-lg text-sm font-medium <?= is_active('reports.php', $current_page) ?>">
                <i data-lucide="line-chart" class="w-4 h-4 shrink-0"></i>
                <span>Laporan Penjualan</span>
            </a>
        <?php endif; ?>

        <!-- ADMIN ONLY: SYSTEM SETTINGS & AUDIT -->
        <?php if ($user['role'] === 'ADMIN'): ?>
            <p class="text-[10px] font-bold tracking-widest text-slate-500 uppercase px-3 py-2 mt-4">Sistem</p>
            <a href="/admin/users.php" class="flex items-center space-x-3 px-3 py-2.5 rounded-lg text-sm font-medium <?= is_active('users.php', $current_page) ?>">
                <i data-lucide="user-cog" class="w-4 h-4 shrink-0"></i>
                <span>Kelola Karyawan</span>
            </a>
            <a href="/admin/logs.php" class="flex items-center space-x-3 px-3 py-2.5 rounded-lg text-sm font-medium <?= is_active('logs.php', $current_page) ?>">
                <i data-lucide="history" class="w-4 h-4 shrink-0"></i>
                <span>Audit Log</span>
            </a>
        <?php endif; ?>
    </nav>

    <!-- Footer Logout Button -->
    <div class="p-3 border-t border-slate-800/80 shrink-0 bg-[#0B0F19]/40">
        <a href="/logout.php" class="flex items-center space-x-3 px-3 py-2.5 rounded-lg text-sm font-medium text-red-400 hover:bg-red-500/10 hover:text-red-300 transition-all">
            <i data-lucide="log-out" class="w-4 h-4 shrink-0"></i>
            <span>Log Out</span>
        </a>
    </div>
</aside>

<script>
    // Responsive Mobile Menu Drawer Toggle
    document.addEventListener('DOMContentLoaded', () => {
        const toggle = document.getElementById('mobile-menu-toggle');
        const sidebar = document.getElementById('sidebar');
        
        if (toggle && sidebar) {
            toggle.addEventListener('click', () => {
                sidebar.classList.toggle('hidden');
                sidebar.classList.toggle('flex');
                sidebar.classList.toggle('absolute');
                sidebar.classList.toggle('inset-y-0');
                sidebar.classList.toggle('left-0');
                sidebar.classList.toggle('w-72');
                sidebar.classList.toggle('shadow-2xl');
            });
        }
    });
</script>
