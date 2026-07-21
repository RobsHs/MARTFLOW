<?php
// /cashier/pos.php
// MartFlow POS - Kasir / Point of Sale Screen

require_once __DIR__ . '/../includes/functions.php';
require_role(['ADMIN', 'MANAGER', 'CASHIER']);

$page_title = 'POS Kasir';
$db = Database::getConnection();

// Fetch categories for product filtering chips
$categories = $db->query("SELECT * FROM categories ORDER BY name ASC")->fetchAll();

// Fetch active member customers list for checkout dropdown/modal search
$customers = $db->query("SELECT * FROM customers ORDER BY name ASC")->fetchAll();

// Include Header (Sidebar will be omitted for POS to give kasir maximum screen space)
include __DIR__ . '/../includes/header.php';
?>

<!-- Minimalist POS Layout: Sidebar is hidden, we place a custom top-nav for cashiers -->
<div class="flex-1 flex flex-col h-screen overflow-hidden bg-slate-100">

    <!-- Cashier Header Navigation Bar -->
    <header class="bg-slate-900 text-white px-6 py-3.5 flex items-center justify-between shadow-md shrink-0 border-b border-slate-800">
        <div class="flex items-center space-x-3">
            <a href="/index.php" class="w-9 h-9 rounded-lg bg-emerald-500 flex items-center justify-center font-bold text-white text-lg hover:bg-emerald-600 transition-all">M</a>
            <div>
                <h1 class="font-bold text-sm tracking-wider uppercase">MARTFLOW POS</h1>
                <p class="text-[10px] text-slate-400 font-mono">Terminal POS Kasir #1</p>
            </div>
        </div>

        <!-- Center Help Shortcuts -->
        <div class="hidden lg:flex items-center space-x-6 text-[11px] text-slate-400 font-mono">
            <span class="flex items-center"><kbd class="bg-slate-800 px-1.5 py-0.5 rounded text-white border border-slate-700 mr-1.5">F2</kbd> Cari SKU/Nama</span>
            <span class="flex items-center"><kbd class="bg-slate-800 px-1.5 py-0.5 rounded text-white border border-slate-700 mr-1.5">F4</kbd> Pembayaran</span>
            <span class="flex items-center"><kbd class="bg-slate-800 px-1.5 py-0.5 rounded text-white border border-slate-700 mr-1.5">ESC</kbd> Tutup Modal</span>
            <span class="flex items-center"><kbd class="bg-slate-800 px-1.5 py-0.5 rounded text-white border border-slate-700 mr-1.5">Ctrl+Enter</kbd> Proses Bayar</span>
        </div>

        <!-- Cashier Actions / Profile -->
        <div class="flex items-center space-x-4">
            <div class="text-right">
                <p class="text-xs font-semibold text-white"><?= esc($_SESSION['name']) ?></p>
                <p class="text-[9px] font-bold text-emerald-400 font-mono uppercase tracking-wider"><?= esc($_SESSION['role']) ?></p>
            </div>

            <div class="h-8 w-px bg-slate-800"></div>

            <?php if ($_SESSION['role'] === 'ADMIN' || $_SESSION['role'] === 'MANAGER'): ?>
                <a href="/admin/dashboard.php" class="text-xs font-semibold bg-slate-800 hover:bg-slate-700 text-slate-300 hover:text-white px-3.5 py-1.5 rounded-lg border border-slate-700 transition-all flex items-center">
                    <i data-lucide="layout-dashboard" class="w-3.5 h-3.5 mr-1.5 text-slate-400"></i>
                    Dashboard
                </a>
            <?php endif; ?>

            <a href="/logout.php" class="text-xs font-semibold text-red-400 hover:text-red-300 transition-all flex items-center" title="Keluar">
                <i data-lucide="log-out" class="w-4 h-4"></i>
            </a>
        </div>
    </header>

    <!-- Main Workspace (Two Columns) -->
    <div class="flex-1 flex overflow-hidden flex-col md:flex-row">

        <!-- LEFT COLUMN: Product Catalog (60%) -->
        <div class="w-full md:w-[60%] lg:w-[65%] flex flex-col h-full bg-slate-50 border-r border-slate-200">
            <!-- Search & Filters Container -->
            <div class="p-4 bg-white border-b border-slate-200 space-y-3 shrink-0 shadow-sm">

                <!-- Dual inputs: Barcode Scanning (Left) and Keyword Search (Right) -->
                <div class="grid grid-cols-1 sm:grid-cols-12 gap-3">
                    <!-- Barcode Input Box (Focussed by default or scanner) -->
                    <div class="sm:col-span-5 relative">
                        <div class="absolute inset-y-0 left-0 pl-3.5 flex items-center pointer-events-none text-slate-400">
                            <i data-lucide="scan-barcode" class="w-4.5 h-4.5"></i>
                        </div>
                        <input type="text" id="barcode-input" autofocus
                            class="block w-full pl-10 pr-3 py-2.5 border border-slate-200 rounded-xl text-sm font-mono placeholder-slate-400 focus:outline-none focus:ring-2 focus:ring-emerald-500/25 focus:border-emerald-500 bg-slate-50 focus:bg-white transition-all"
                            placeholder="Scan Barcode / Tekan Enter">
                    </div>

                    <!-- Keyword search input -->
                    <div class="sm:col-span-7 relative">
                        <div class="absolute inset-y-0 left-0 pl-3.5 flex items-center pointer-events-none text-slate-400">
                            <i data-lucide="search" class="w-4.5 h-4.5"></i>
                        </div>
                        <input type="text" id="search-input"
                            class="block w-full pl-10 pr-8 py-2.5 border border-slate-200 rounded-xl text-sm placeholder-slate-400 focus:outline-none focus:ring-2 focus:ring-emerald-500/25 focus:border-emerald-500 bg-slate-50 focus:bg-white transition-all"
                            placeholder="Cari SKU atau Nama Produk... (F2)">
                        <button id="clear-search" class="absolute inset-y-0 right-0 pr-3 flex items-center text-slate-400 hover:text-slate-600 hidden">
                            <i data-lucide="x" class="w-4 h-4"></i>
                        </button>
                    </div>
                </div>

                <!-- Horizontal Category Filters -->
                <div class="flex items-center space-x-2 overflow-x-auto pb-1 select-none">
                    <button onclick="filterCategory('')" id="cat-chip-all"
                        class="cat-chip px-4 py-1.5 rounded-lg text-xs font-semibold cursor-pointer shrink-0 transition-all bg-emerald-600 text-white shadow-sm shadow-emerald-500/10 border border-emerald-600">
                        Semua Kategori
                    </button>
                    <?php foreach ($categories as $cat): ?>
                        <button onclick="filterCategory(<?= $cat['id'] ?>)" id="cat-chip-<?= $cat['id'] ?>"
                            class="cat-chip px-4 py-1.5 rounded-lg text-xs font-semibold cursor-pointer shrink-0 transition-all bg-slate-100 text-slate-600 border border-slate-200 hover:bg-slate-200 hover:text-slate-800">
                            <?= esc($cat['name']) ?>
                        </button>
                    <?php endforeach; ?>
                </div>
            </div>

            <!-- Scrollable Catalog List -->
            <div class="flex-1 overflow-y-auto p-4">
                <!-- Skeleton Loader / Loading State -->
                <div id="catalog-loading" class="hidden grid grid-cols-2 sm:grid-cols-3 lg:grid-cols-4 gap-4">
                    <?php for ($i = 0; $i < 8; $i++): ?>
                        <div class="bg-white border border-slate-200 rounded-2xl p-4 space-y-3.5 animate-pulse">
                            <div class="h-3 bg-slate-200 rounded w-1/3"></div>
                            <div class="h-5 bg-slate-200 rounded w-4/5"></div>
                            <div class="h-4 bg-slate-200 rounded w-1/2"></div>
                            <div class="pt-2 flex justify-between">
                                <div class="h-6 bg-slate-200 rounded w-1/3"></div>
                                <div class="h-6 bg-slate-200 rounded w-1/4"></div>
                            </div>
                        </div>
                    <?php endfor; ?>
                </div>

                <!-- Real Catalog Grid -->
                <div id="catalog-grid" class="grid grid-cols-2 sm:grid-cols-3 lg:grid-cols-4 gap-4">
                    <!-- Loaded dynamically via Javascript Fetch -->
                </div>

                <!-- Empty State -->
                <div id="catalog-empty" class="hidden flex flex-col items-center justify-center py-20 text-slate-400">
                    <i data-lucide="package-search" class="w-16 h-16 text-slate-300 mb-4 animate-bounce"></i>
                    <p class="font-bold text-slate-600">Produk tidak ditemukan</p>
                    <p class="text-xs text-slate-400 mt-1">Coba sesuaikan kata kunci pencarian atau filter kategori Anda.</p>
                </div>
            </div>
        </div>

        <!-- RIGHT COLUMN: Shopping Cart & Checkout (40%) -->
        <div class="w-full md:w-[40%] lg:w-[35%] flex flex-col h-full bg-white border-l border-slate-200 shadow-xl">
            <!-- Header Cart Info -->
            <div class="px-5 py-4 border-b border-slate-100 flex items-center justify-between shrink-0 bg-slate-50/50">
                <div class="flex items-center space-x-2 text-slate-800">
                    <i data-lucide="shopping-cart" class="w-5 h-5 text-emerald-600"></i>
                    <h3 class="font-bold text-sm uppercase tracking-wider">Keranjang Belanja</h3>
                </div>
                <!-- Clear Cart link -->
                <button onclick="clearCart()" class="text-xs font-semibold text-red-500 hover:text-red-700 cursor-pointer flex items-center">
                    <i data-lucide="trash-2" class="w-3.5 h-3.5 mr-1.5"></i>
                    Kosongkan
                </button>
            </div>

            <!-- Member / Customer Selection Section -->
            <div class="px-5 py-3 border-b border-slate-100 bg-slate-50/30 shrink-0 space-y-2">
                <div class="flex justify-between items-center">
                    <label class="text-[10px] font-bold text-slate-500 uppercase tracking-widest">Pelanggan Member</label>
                    <button onclick="openMemberSearchModal()" class="text-[10px] text-emerald-600 hover:text-emerald-700 font-bold uppercase transition-all flex items-center">
                        <i data-lucide="search" class="w-3 h-3 mr-1"></i> Cari ID
                    </button>
                </div>
                <!-- Selected Member Card / Dropdown -->
                <div id="selected-member-card" class="bg-white border border-slate-200 rounded-xl p-2.5 flex items-center justify-between shadow-sm">
                    <div class="flex items-center space-x-2.5 overflow-hidden">
                        <div class="w-7 h-7 bg-slate-100 text-slate-500 rounded-full flex items-center justify-center font-bold text-xs uppercase" id="member-avatar">G</div>
                        <div class="overflow-hidden">
                            <p class="text-xs font-bold text-slate-800 truncate" id="member-name">Guest (Bukan Member)</p>
                            <p class="text-[9px] text-slate-400 font-mono tracking-wider mt-0.5" id="member-id">NON-MEMBER</p>
                        </div>
                    </div>
                    <!-- Clear Member button -->
                    <button id="clear-member-btn" onclick="deselectMember()" class="text-slate-400 hover:text-red-500 hidden cursor-pointer">
                        <i data-lucide="x" class="w-4 h-4"></i>
                    </button>
                </div>
            </div>

            <!-- Scrollable Shopping Cart Items List -->
            <div class="flex-1 overflow-y-auto divide-y divide-slate-100" id="cart-list">
                <!-- Populated dynamically via javascript -->
            </div>

            <!-- Bottom Pricing Summary & Pay Actions -->
            <div class="p-5 border-t border-slate-200 bg-slate-50 shrink-0 space-y-4 shadow-inner shadow-slate-100">
                <!-- Pricing totals breakdown -->
                <div class="space-y-2 text-xs text-slate-600 font-medium">
                    <div class="flex justify-between">
                        <span>Subtotal (Kotor)</span>
                        <span id="summary-subtotal" class="font-mono text-slate-900">Rp 0</span>
                    </div>
                    <div class="flex justify-between text-red-600">
                        <span>Potongan Diskon</span>
                        <span id="summary-discount" class="font-mono">-Rp 0</span>
                    </div>
                    <div class="flex justify-between">
                        <span>PPN (11%)</span>
                        <span id="summary-tax" class="font-mono text-slate-900">Rp 0</span>
                    </div>
                </div>

                <!-- Grand Total -->
                <div class="flex items-center justify-between bg-slate-900 text-white rounded-2xl p-4.5 shadow-lg shadow-slate-900/10 shrink-0 border border-slate-800">
                    <div>
                        <p class="text-[9px] font-bold text-slate-400 uppercase tracking-widest font-mono">Total Tagihan (Nett)</p>
                        <p id="summary-grand-total" class="text-2xl font-extrabold text-emerald-400 font-sans mt-0.5">Rp 0</p>
                    </div>
                    <button onclick="openPaymentModal()" id="btn-pay-trigger"
                        class="px-5 py-3 cursor-pointer bg-emerald-500 hover:bg-emerald-600 text-sm font-bold text-white rounded-xl shadow-lg shadow-emerald-500/20 hover:scale-105 active:scale-95 transition-all">
                        BAYAR (F4)
                    </button>
                </div>
            </div>
        </div>
    </div>
</div>

<!-- ================================================== -->
<!-- MODAL: PEMBAYARAN (PAYMENT) -->
<!-- ================================================== -->
<div id="payment-modal" class="hidden fixed inset-0 bg-slate-950/70 backdrop-blur-sm z-50 flex items-center justify-center p-4">
    <div class="bg-white rounded-3xl shadow-2xl border border-slate-200 max-w-lg w-full overflow-hidden flex flex-col transform transition-all animate-scaleIn">
        <!-- Header -->
        <div class="px-6 py-5 border-b border-slate-100 flex items-center justify-between bg-slate-50">
            <div class="flex items-center space-x-2.5">
                <i data-lucide="wallet-card" class="w-5 h-5 text-emerald-600"></i>
                <h3 class="font-extrabold text-slate-800">Proses Pembayaran</h3>
            </div>
            <button onclick="closePaymentModal()" class="text-slate-400 hover:text-slate-600 cursor-pointer">
                <i data-lucide="x" class="w-6 h-6"></i>
            </button>
        </div>

        <form onsubmit="processPayment(event)" class="flex-1 p-6 space-y-5">
            <!-- Total tagihan display -->
            <div class="bg-slate-900 text-white rounded-2xl p-4 text-center border border-slate-800">
                <p class="text-[10px] font-bold text-slate-400 font-mono uppercase tracking-widest">Total Belanjaan</p>
                <p id="modal-grand-total" class="text-3xl font-extrabold text-emerald-400 font-sans mt-1">Rp 0</p>
            </div>

            <!-- Payment Methods Grid -->
            <div>
                <label class="block text-xs font-bold text-slate-500 uppercase tracking-wider mb-2.5">Metode Pembayaran</label>
                <div class="grid grid-cols-3 gap-2.5" id="payment-methods-grid">
                    <button type="button" onclick="selectPaymentMethod('CASH')" id="pay-CASH"
                        class="pay-method-btn py-3 border border-emerald-600 rounded-xl font-semibold text-sm flex flex-col items-center justify-center transition-all bg-emerald-50 text-emerald-700 shadow-sm shadow-emerald-500/5">
                        <i data-lucide="banknote" class="w-5 h-5 mb-1.5"></i> CASH
                    </button>
                    <button type="button" onclick="selectPaymentMethod('QRIS')" id="pay-QRIS"
                        class="pay-method-btn py-3 border border-slate-200 text-slate-600 hover:bg-slate-50 rounded-xl font-semibold text-sm flex flex-col items-center justify-center transition-all">
                        <i data-lucide="qr-code" class="w-5 h-5 mb-1.5"></i> QRIS
                    </button>
                    <button type="button" onclick="selectPaymentMethod('DEBIT')" id="pay-DEBIT"
                        class="pay-method-btn py-3 border border-slate-200 text-slate-600 hover:bg-slate-50 rounded-xl font-semibold text-sm flex flex-col items-center justify-center transition-all">
                        <i data-lucide="credit-card" class="w-5 h-5 mb-1.5"></i> DEBIT
                    </button>
                    <button type="button" onclick="selectPaymentMethod('CREDIT')" id="pay-CREDIT"
                        class="pay-method-btn py-3 border border-slate-200 text-slate-600 hover:bg-slate-50 rounded-xl font-semibold text-sm flex flex-col items-center justify-center transition-all">
                        <i data-lucide="credit-card" class="w-5 h-5 mb-1.5"></i> KREDIT
                    </button>
                    <button type="button" onclick="selectPaymentMethod('E-WALLET')" id="pay-E-WALLET"
                        class="pay-method-btn py-3 border border-slate-200 text-slate-600 hover:bg-slate-50 rounded-xl font-semibold text-sm flex flex-col items-center justify-center transition-all">
                        <i data-lucide="smartphone" class="w-5 h-5 mb-1.5"></i> E-WALLET
                    </button>
                    <button type="button" onclick="selectPaymentMethod('TRANSFER')" id="pay-TRANSFER"
                        class="pay-method-btn py-3 border border-slate-200 text-slate-600 hover:bg-slate-50 rounded-xl font-semibold text-sm flex flex-col items-center justify-center transition-all">
                        <i data-lucide="arrow-right-left" class="w-5 h-5 mb-1.5"></i> TRANSFER
                    </button>
                </div>
            </div>

            <!-- Cash Payment input section -->
            <div id="cash-payment-section" class="space-y-4">
                <div class="grid grid-cols-2 gap-4">
                    <!-- Cash Paid Amount -->
                    <div>
                        <label for="cash-paid" class="block text-xs font-bold text-slate-500 uppercase tracking-wider mb-1.5">Uang Pelanggan</label>
                        <div class="relative">
                            <span class="absolute inset-y-0 left-0 pl-3 flex items-center text-sm font-semibold text-slate-400 select-none">Rp</span>
                            <input type="number" id="cash-paid" required oninput="calculateChange()"
                                class="block w-full pl-9 pr-3 py-3 border border-slate-200 rounded-xl text-lg font-bold font-mono focus:outline-none focus:ring-2 focus:ring-emerald-500/25 focus:border-emerald-500 transition-all placeholder-slate-300 bg-slate-50 focus:bg-white"
                                placeholder="0">
                        </div>
                    </div>

                    <!-- Change Amount (Kembalian) -->
                    <div>
                        <label class="block text-xs font-bold text-slate-500 uppercase tracking-wider mb-1.5">Uang Kembalian</label>
                        <div class="relative">
                            <span class="absolute inset-y-0 left-0 pl-3 flex items-center text-sm font-semibold text-slate-400 select-none">Rp</span>
                            <input type="text" id="cash-change" readonly value="0"
                                class="block w-full pl-9 pr-3 py-3 border border-slate-200 rounded-xl text-lg font-bold font-mono bg-slate-100 text-slate-500 focus:outline-none">
                        </div>
                    </div>
                </div>

                <!-- Quick Cash Helpers -->
                <div>
                    <label class="block text-[10px] font-bold text-slate-400 uppercase tracking-widest mb-1.5">Nominal Uang Pas / Cepat</label>
                    <div class="grid grid-cols-4 gap-2 text-xs font-bold">
                        <button type="button" id="quick-cash-exact" onclick="quickCash('exact')" class="cursor-pointer py-2 border border-slate-200 hover:bg-slate-50 text-slate-700 rounded-lg transition-all font-mono">Uang Pas</button>
                        <button type="button" onclick="quickCash(10000)" class="cursor-pointer py-2 border border-slate-200 hover:bg-slate-50 text-slate-700 rounded-lg transition-all font-mono">10.000</button>
                        <button type="button" onclick="quickCash(20000)" class="cursor-pointer py-2 border border-slate-200 hover:bg-slate-50 text-slate-700 rounded-lg transition-all font-mono">20.000</button>
                        <button type="button" onclick="quickCash(50000)" class="cursor-pointer py-2 border border-slate-200 hover:bg-slate-50 text-slate-700 rounded-lg transition-all font-mono">50.000</button>
                        <button type="button" onclick="quickCash(100000)" class="cursor-pointer py-2 border border-slate-200 hover:bg-slate-50 text-slate-700 rounded-lg transition-all font-mono col-span-2">100.000</button>
                        <button type="button" onclick="quickCash(200000)" class="cursor-pointer py-2 border border-slate-200 hover:bg-slate-50 text-slate-700 rounded-lg transition-all font-mono col-span-2">200.000</button>
                    </div>
                </div>
            </div>

            <!-- Error banner inside modal -->
            <div id="payment-error-banner" class="hidden bg-red-50 border border-red-200 text-xs text-red-700 p-3 rounded-xl flex items-start space-x-2">
                <i data-lucide="alert-circle" class="w-4 h-4 shrink-0 text-red-500 mt-0.5"></i>
                <span id="payment-error-text">Uang pelanggan tidak mencukupi untuk melakukan checkout!</span>
            </div>

            <!-- Action buttons -->
            <div class="grid grid-cols-2 gap-4 pt-3 border-t border-slate-100">
                <button type="button" onclick="closePaymentModal()"
                    class="cursor-pointer py-3 border border-slate-200 text-slate-700 font-semibold rounded-xl text-sm text-center hover:bg-slate-50 transition-all">
                    Batal (Esc)
                </button>
                <button type="submit" id="submit-checkout-btn"
                    class="cursor-pointer py-3 bg-emerald-600 hover:bg-emerald-700 text-white font-semibold rounded-xl text-sm text-center shadow-lg shadow-emerald-500/10 transition-all">
                    Proses Selesai (Ctrl+Enter)
                </button>
            </div>
        </form>
    </div>
</div>

<!-- ================================================== -->
<!-- MODAL: SEARCH MEMBER -->
<!-- ================================================== -->
<div id="member-search-modal" class="hidden fixed inset-0 bg-slate-950/70 backdrop-blur-sm z-50 flex items-center justify-center p-4">
    <div class="bg-white rounded-3xl shadow-2xl border border-slate-200 max-w-md w-full overflow-hidden flex flex-col transform transition-all animate-scaleIn">
        <!-- Header -->
        <div class="px-5 py-4 border-b border-slate-100 flex items-center justify-between bg-slate-50">
            <h3 class="font-extrabold text-slate-800">Cari Pelanggan Member</h3>
            <button onclick="closeMemberSearchModal()" class="text-slate-400 hover:text-slate-600 cursor-pointer">
                <i data-lucide="x" class="w-5 h-5"></i>
            </button>
        </div>

        <div class="p-5 space-y-4">
            <div>
                <label for="member-search-input" class="block text-xs font-semibold text-slate-500 uppercase tracking-wider mb-1.5">ID Member / No Telepon</label>
                <div class="flex space-x-2">
                    <input type="text" id="member-search-input" class="block flex-1 px-4 py-2.5 border border-slate-200 rounded-xl text-sm placeholder-slate-400 font-mono uppercase" placeholder="MBR-XXXX atau 0812...">
                    <button type="button" onclick="searchMember()" class="cursor-pointer px-4 bg-slate-900 hover:bg-slate-800 text-white rounded-xl text-sm font-semibold flex items-center">
                        Cari
                    </button>
                </div>
            </div>

            <!-- Error / Warning Banner -->
            <div id="member-search-error" class="hidden bg-red-50 border border-red-200 text-xs text-red-600 p-3 rounded-xl">
                Member tidak ditemukan. Silakan periksa kembali kueri Anda.
            </div>

            <!-- Member Result Box -->
            <div id="member-search-result" class="hidden bg-slate-50 border border-slate-200 rounded-2xl p-4 space-y-3">
                <div class="flex items-center justify-between">
                    <span class="text-xs font-bold text-slate-400 font-mono tracking-wider uppercase" id="res-member-lvl">Level: GOLD</span>
                    <span class="inline-block px-2 py-0.5 rounded-full text-[10px] font-bold bg-emerald-100 text-emerald-800" id="res-member-id">MBR-0001</span>
                </div>
                <div>
                    <h4 class="font-bold text-slate-900" id="res-member-name">Linda Permata</h4>
                    <p class="text-xs text-slate-500 font-mono mt-0.5" id="res-member-phone">08123456789</p>
                </div>
                <div class="grid grid-cols-2 gap-4 border-t border-slate-200 pt-3 text-xs">
                    <div>
                        <p class="text-slate-400 font-mono text-[9px] uppercase tracking-wider">Koin Poin</p>
                        <p class="font-bold text-slate-800 font-mono" id="res-member-points">2.450 Pts</p>
                    </div>
                    <div>
                        <p class="text-slate-400 font-mono text-[9px] uppercase tracking-wider">Total Belanja</p>
                        <p class="font-bold text-slate-800 font-mono" id="res-member-spend">Rp 1.250.000</p>
                    </div>
                </div>
                <button type="button" onclick="selectMember()" class="cursor-pointer w-full py-2.5 bg-emerald-600 hover:bg-emerald-700 text-white rounded-xl text-xs font-semibold text-center mt-2">
                    Terapkan Member ke Belanjaan
                </button>
            </div>
        </div>
    </div>
</div>

<!-- Custom POS Logic script -->
<script>
    // --- STATE ENGINE ---
    let cart = [];
    let selectedCategory = '';
    let selectedMember = null;
    let paymentMethod = 'CASH';
    const TAX_RATE = 0.11; // 11%

    // Loaded Member temporary search holder
    let searchedMemberHolder = null;

    // Load Catalog on Screen Init
    document.addEventListener('DOMContentLoaded', () => {
        loadCatalog();

        // Listen to search inputs
        const searchInput = document.getElementById('search-input');
        const clearBtn = document.getElementById('clear-search');

        searchInput.addEventListener('input', (e) => {
            const val = e.target.value.trim();
            if (val.length > 0) {
                clearBtn.classList.remove('hidden');
            } else {
                clearBtn.classList.add('hidden');
            }
            // Debounce/Immediate call for instant catalog filtering
            loadCatalog(val, selectedCategory);
        });

        clearBtn.addEventListener('click', () => {
            searchInput.value = '';
            clearBtn.classList.add('hidden');
            loadCatalog('', selectedCategory);
            searchInput.focus();
        });

        // Listen to barcode scanner enter key
        const barcodeInput = document.getElementById('barcode-input');
        barcodeInput.addEventListener('keydown', (e) => {
            if (e.key === 'Enter') {
                const code = e.target.value.trim();
                if (code.length > 0) {
                    scanBarcodeProduct(code);
                }
                e.preventDefault();
            }
        });

        // KEYBOARD SHORTCUTS LISTENER
        document.addEventListener('keydown', (e) => {
            // F2: Focus onto search keyword
            if (e.key === 'F2') {
                e.preventDefault();
                document.getElementById('search-input').focus();
            }
            // F4: Open Payment Modal
            if (e.key === 'F4') {
                e.preventDefault();
                openPaymentModal();
            }
            // ESC: Close Modal
            if (e.key === 'Escape') {
                e.preventDefault();
                closePaymentModal();
                closeMemberSearchModal();
            }
            // CTRL + ENTER: Submit checkout if payment modal is open
            if (e.key === 'Enter' && e.ctrlKey) {
                const modal = document.getElementById('payment-modal');
                if (!modal.classList.contains('hidden')) {
                    e.preventDefault();
                    document.getElementById('submit-checkout-btn').click();
                }
            }
        });
    });

    // --- FETCH CATALOG PRODUCTS ---
    function loadCatalog(searchQuery = '', categoryId = '') {
        const grid = document.getElementById('catalog-grid');
        const loading = document.getElementById('catalog-loading');
        const empty = document.getElementById('catalog-empty');

        grid.innerHTML = '';
        loading.classList.remove('hidden');
        empty.classList.add('hidden');

        let url = `/api/products.php?action=search&q=${encodeURIComponent(searchQuery)}`;
        if (categoryId) {
            url += `&category_id=${categoryId}`;
        }

        fetch(url)
            .then(res => res.json())
            .then(data => {
                loading.classList.add('hidden');
                if (data.success && data.data.length > 0) {
                    data.data.forEach(p => {
                        const card = createProductCard(p);
                        grid.appendChild(card);
                    });
                    lucide.createIcons();
                } else {
                    empty.classList.remove('hidden');
                }
            })
            .catch(err => {
                console.error(err);
                loading.classList.add('hidden');
                empty.classList.remove('hidden');
            });
    }

    // --- GENERATE CARD ELEMENT FOR CATALOG ---
    function createProductCard(product) {
        const card = document.createElement('div');
        card.id = `prd-card-${product.id}`;
        card.className = "bg-white border border-slate-200 rounded-2xl p-4 shadow-sm hover:shadow-md hover:border-emerald-500/40 hover:-translate-y-0.5 transition-all cursor-pointer flex flex-col justify-between select-none relative";

        // Low stock warning tag
        let stockTag = '';
        const stock = parseInt(product.stock);
        const minStock = parseInt(product.min_stock);

        if (stock <= 0) {
            stockTag = `<span class="absolute top-2.5 right-2.5 px-2 py-0.5 text-[9px] font-bold rounded bg-red-100 text-red-800 border border-red-200 uppercase">Habis</span>`;
        } else if (stock <= minStock) {
            stockTag = `<span class="absolute top-2.5 right-2.5 px-2 py-0.5 text-[9px] font-bold rounded bg-amber-100 text-amber-800 border border-amber-200 uppercase">Menipis</span>`;
        }

        // Price formatting
        const sellPrice = parseFloat(product.sell_price);
        const promoPrice = parseFloat(product.promo.promo_price);
        const discountAmount = parseFloat(product.promo.discount);

        let priceSection = `<p class="font-extrabold text-sm text-slate-900 font-mono">${formatRupiah(sellPrice)}</p>`;
        if (discountAmount > 0) {
            priceSection = `
                <div>
                    <p class="font-extrabold text-sm text-emerald-600 font-mono">${formatRupiah(promoPrice)}</p>
                    <p class="text-[10px] text-slate-400 line-through font-mono mt-0.5">${formatRupiah(sellPrice)}</p>
                </div>
            `;
        }

        card.innerHTML = `
            ${stockTag}
            <div onclick="addProductToCart(${product.id}, '${escapeQuote(product.name)}', ${sellPrice}, ${discountAmount}, ${stock})">
                <p class="text-[9px] font-bold text-slate-400 font-mono tracking-wider uppercase mb-1">${product.sku}</p>
                <h4 class="font-bold text-xs text-slate-800 leading-tight tracking-tight hover:text-emerald-600 line-clamp-2 h-8.5">${escapeHtml(product.name)}</h4>
                <p class="text-[10px] text-slate-400 font-mono mt-1">Stok: <span class="font-semibold text-slate-700">${product.stock} ${product.unit}</span></p>
            </div>
            <div class="mt-4 pt-3 border-t border-slate-100 flex items-center justify-between">
                ${priceSection}
                <button onclick="addProductToCart(${product.id}, '${escapeQuote(product.name)}', ${sellPrice}, ${discountAmount}, ${stock})" 
                        class="p-2 cursor-pointer bg-emerald-500 hover:bg-emerald-600 text-white rounded-xl shadow-md shadow-emerald-500/10 hover:scale-105 active:scale-95 transition-all">
                    <i data-lucide="plus" class="w-4 h-4"></i>
                </button>
            </div>
        `;
        return card;
    }

    // --- CATEGORY FILTER CHIPS ---
    function filterCategory(catId) {
        selectedCategory = catId;

        // Style all chips as inactive
        document.querySelectorAll('.cat-chip').forEach(c => {
            c.className = "cat-chip px-4 py-1.5 rounded-lg text-xs font-semibold cursor-pointer shrink-0 transition-all bg-slate-100 text-slate-600 border border-slate-200 hover:bg-slate-200 hover:text-slate-800";
        });

        // Set clicked chip as active
        const activeChip = catId ? document.getElementById(`cat-chip-${catId}`) : document.getElementById('cat-chip-all');
        if (activeChip) {
            activeChip.className = "cat-chip px-4 py-1.5 rounded-lg text-xs font-semibold cursor-pointer shrink-0 transition-all bg-emerald-600 text-white shadow-sm shadow-emerald-500/10 border border-emerald-600";
        }

        // Reload products
        const searchQuery = document.getElementById('search-input').value.trim();
        loadCatalog(searchQuery, catId);
    }

    // --- SCAN BARCODE HANDLER (ENTER PRESSED) ---
    function scanBarcodeProduct(barcode) {
        const barInput = document.getElementById('barcode-input');
        barInput.value = ''; // Clear immediately
        barInput.blur();

        fetch(`/api/products.php?action=barcode&barcode=${encodeURIComponent(barcode)}`)
            .then(res => res.json())
            .then(data => {
                if (data.success) {
                    const p = data.data;
                    const discount = parseFloat(p.promo.discount ?? 0);
                    addProductToCart(p.id, p.name, parseFloat(p.sell_price), discount, parseInt(p.stock));
                    barInput.focus();
                } else {
                    alert(data.message);
                    barInput.focus();
                }
            })
            .catch(err => {
                console.error(err);
                alert("Terjadi kesalahan scanning.");
                barInput.focus();
            });
    }

    // --- ADD PRODUCT TO CART ---
    function addProductToCart(productId, name, sellPrice, discount, stockLimit) {
        // Prevent adding product with zero stock
        if (stockLimit <= 0) {
            alert(`Stok produk "${name}" habis! Tidak dapat ditambahkan.`);
            return;
        }

        const existing = cart.find(item => item.product_id === productId);
        if (existing) {
            if (existing.qty + 1 > stockLimit) {
                alert(`Tidak dapat menambahkan lebih banyak! Batas stok adalah ${stockLimit} Pcs.`);
                return;
            }
            existing.qty += 1;
        } else {
            cart.push({
                product_id: productId,
                name: name,
                sell_price: sellPrice,
                discount: discount, // per unit discount
                qty: 1,
                stock_limit: stockLimit
            });
        }

        renderCart();
    }

    // --- REMOVE / DECREASE QUANTITY ---
    function updateCartItemQty(productId, delta) {
        const item = cart.find(i => i.product_id === productId);
        if (!item) return;

        item.qty += delta;
        if (item.qty <= 0) {
            cart = cart.filter(i => i.product_id !== productId);
        } else if (item.qty > item.stock_limit) {
            alert(`Batas stok terlampaui! Stok tersedia: ${item.stock_limit} Pcs.`);
            item.qty = item.stock_limit;
        }

        renderCart();
    }

    // --- DELETE ITEM FROM CART ---
    function deleteCartItem(productId) {
        cart = cart.filter(i => i.product_id !== productId);
        renderCart();
    }

    // --- CLEAR CART ---
    function clearCart() {
        if (cart.length === 0) return;
        if (confirm('Apakah Anda yakin ingin mengosongkan keranjang belanja kasir?')) {
            cart = [];
            renderCart();
        }
    }

    // --- APPLY DIRECT ITEM-LEVEL DISCOUNT ---
    function changeItemDiscount(productId, discountValue) {
        const item = cart.find(i => i.product_id === productId);
        if (!item) return;

        discountValue = parseFloat(discountValue);
        if (isNaN(discountValue) || discountValue < 0) {
            discountValue = 0;
        }
        if (discountValue > item.sell_price) {
            alert("Diskon tidak boleh melebihi harga jual!");
            discountValue = 0;
        }

        item.discount = discountValue;
        renderCart();
    }

    // --- COMPUTE AND RENDER CART LIST ---
    function renderCart() {
        const listContainer = document.getElementById('cart-list');
        listContainer.innerHTML = '';

        if (cart.length === 0) {
            listContainer.innerHTML = `
                <div class="flex flex-col items-center justify-center py-20 text-slate-300">
                    <i data-lucide="shopping-bag" class="w-12 h-12 mb-3"></i>
                    <p class="text-xs font-semibold text-slate-400 font-mono">Keranjang belanja kosong</p>
                </div>
            `;
            updatePrices(0, 0, 0, 0);
            lucide.createIcons();
            return;
        }

        let subtotalAccumulator = 0;
        let discountAccumulator = 0;

        cart.forEach(item => {
            const itemSubtotal = item.sell_price * item.qty;
            const itemDiscount = item.discount * item.qty;

            subtotalAccumulator += itemSubtotal;
            discountAccumulator += itemDiscount;

            const row = document.createElement('div');
            row.className = "p-4 flex flex-col justify-between hover:bg-slate-50/50 transition-all border-b border-slate-50";

            row.innerHTML = `
                <div class="flex items-start justify-between space-x-3">
                    <div class="overflow-hidden">
                        <h4 class="font-bold text-xs text-slate-800 leading-snug truncate" title="${escapeHtml(item.name)}">${escapeHtml(item.name)}</h4>
                        <p class="text-[10px] text-slate-400 font-mono mt-0.5">${formatRupiah(item.sell_price)} / Pcs</p>
                    </div>
                    <!-- Delete button -->
                    <button onclick="deleteCartItem(${item.product_id})" class="text-slate-300 hover:text-red-500 cursor-pointer">
                        <i data-lucide="trash" class="w-3.5 h-3.5"></i>
                    </button>
                </div>

                <div class="flex items-center justify-between mt-3.5 pt-2 border-t border-dashed border-slate-100">
                    <!-- Qty adjustment controls -->
                    <div class="flex items-center border border-slate-200 rounded-lg overflow-hidden bg-white shrink-0">
                        <button onclick="updateCartItemQty(${item.product_id}, -1)" class="cursor-pointer px-2 py-1 text-slate-500 hover:bg-slate-50 border-r border-slate-200">
                            <i data-lucide="minus" class="w-3 h-3"></i>
                        </button>
                        <span class="px-3 py-1 font-mono text-xs font-bold text-slate-800 bg-slate-50/50 min-w-[32px] text-center select-none">${item.qty}</span>
                        <button onclick="updateCartItemQty(${item.product_id}, 1)" class="cursor-pointer px-2 py-1 text-slate-500 hover:bg-slate-50 border-l border-slate-200">
                            <i data-lucide="plus" class="w-3 h-3"></i>
                        </button>
                    </div>

                    <!-- Direct discount edit input -->
                    <div class="flex items-center space-x-1 shrink-0">
                        <span class="text-[9px] font-bold text-slate-400 uppercase tracking-widest font-mono">Disc:</span>
                        <input type="number" value="${item.discount}" onchange="changeItemDiscount(${item.product_id}, this.value)"
                               class="w-14 py-1 px-1.5 border border-slate-200 rounded-md text-xs font-mono font-bold text-red-500 bg-red-50/30 text-center focus:outline-none focus:border-red-500" placeholder="0">
                    </div>

                    <!-- Row Final Net Subtotal -->
                    <div class="text-right font-mono font-bold text-xs text-slate-900 shrink-0">
                        ${formatRupiah(itemSubtotal - itemDiscount)}
                    </div>
                </div>
            `;
            listContainer.appendChild(row);
        });

        // Compute Tax & Grand Total
        const netSubtotal = subtotalAccumulator - discountAccumulator;
        const taxAmount = Math.round(netSubtotal * TAX_RATE);
        const grandTotal = netSubtotal + taxAmount;

        updatePrices(subtotalAccumulator, discountAccumulator, taxAmount, grandTotal);
        lucide.createIcons();
    }

    // --- UPDATE DOM PRICES ---
    function updatePrices(sub, disc, tax, grand) {
        document.getElementById('summary-subtotal').textContent = formatRupiah(sub);
        document.getElementById('summary-discount').textContent = '-' + formatRupiah(disc);
        document.getElementById('summary-tax').textContent = formatRupiah(tax);

        const g_str = formatRupiah(grand);
        document.getElementById('summary-grand-total').textContent = g_str;
        document.getElementById('modal-grand-total').textContent = g_str;
    }

    // --- OPEN MEMBER SEARCH MODAL ---
    function openMemberSearchModal() {
        document.getElementById('member-search-modal').classList.remove('hidden');
        document.getElementById('member-search-input').focus();
    }

    function closeMemberSearchModal() {
        document.getElementById('member-search-modal').classList.add('hidden');
        document.getElementById('member-search-input').value = '';
        document.getElementById('member-search-error').classList.add('hidden');
        document.getElementById('member-search-result').classList.add('hidden');
        searchedMemberHolder = null;
    }

    // --- SEARCH MEMBER HANDLER (API FETCH) ---
    function searchMember() {
        const query = document.getElementById('member-search-input').value.trim();
        const err = document.getElementById('member-search-error');
        const res = document.getElementById('member-search-result');

        err.classList.add('hidden');
        res.classList.add('hidden');
        searchedMemberHolder = null;

        if (query.length === 0) return;

        fetch(`/api/transactions.php?action=get_member&q=${encodeURIComponent(query)}`)
            .then(response => response.json())
            .then(data => {
                if (data.success && data.data) {
                    searchedMemberHolder = data.data;

                    document.getElementById('res-member-lvl').textContent = `Level: ${searchedMemberHolder.level}`;
                    document.getElementById('res-member-id').textContent = searchedMemberHolder.member_id;
                    document.getElementById('res-member-name').textContent = searchedMemberHolder.name;
                    document.getElementById('res-member-phone').textContent = searchedMemberHolder.phone;
                    document.getElementById('res-member-points').textContent = parseInt(searchedMemberHolder.points).toLocaleString() + ' Pts';
                    document.getElementById('res-member-spend').textContent = formatRupiah(parseFloat(searchedMemberHolder.total_spend));

                    res.classList.remove('hidden');
                } else {
                    err.classList.remove('hidden');
                }
            })
            .catch(err => {
                console.error(err);
                alert("Gagal melakukan pencarian database.");
            });
    }

    // --- APPLY MEMBER TO CURRENT ORDER ---
    function selectMember() {
        if (!searchedMemberHolder) return;
        selectedMember = searchedMemberHolder;

        // Update selected member visual badge
        document.getElementById('member-name').textContent = selectedMember.name;
        document.getElementById('member-id').textContent = selectedMember.member_id;
        document.getElementById('member-avatar').textContent = selectedMember.name.substring(0, 2).toUpperCase();
        document.getElementById('member-avatar').className = "w-7 h-7 bg-emerald-500 text-white rounded-full flex items-center justify-center font-bold text-xs uppercase shadow-sm shadow-emerald-500/10";
        document.getElementById('clear-member-btn').classList.remove('hidden');

        closeMemberSearchModal();
    }

    // --- REMOVE MEMBER FROM CURRENT ORDER ---
    function deselectMember() {
        selectedMember = null;
        document.getElementById('member-name').textContent = "Guest (Bukan Member)";
        document.getElementById('member-id').textContent = "NON-MEMBER";
        document.getElementById('member-avatar').textContent = "G";
        document.getElementById('member-avatar').className = "w-7 h-7 bg-slate-100 text-slate-500 rounded-full flex items-center justify-center font-bold text-xs uppercase";
        document.getElementById('clear-member-btn').classList.add('hidden');
    }

    // --- OPEN PAYMENT PROCESS MODAL ---
    function openPaymentModal() {
        if (cart.length === 0) {
            alert("Keranjang belanja kosong! Silakan tambahkan produk terlebih dahulu.");
            return;
        }

        document.getElementById('payment-modal').classList.remove('hidden');
        document.getElementById('payment-error-banner').classList.add('hidden');

        // Reset Paid Amount input field
        const payInp = document.getElementById('cash-paid');
        payInp.value = '';

        // Select cash method by default
        selectPaymentMethod('CASH');
        calculateChange();
    }

    function closePaymentModal() {
        document.getElementById('payment-modal').classList.add('hidden');
    }

    // --- SELECT PAYMENT METHOD TRIGGER ---
    function selectPaymentMethod(method) {
        paymentMethod = method;

        // Remove style on all methods
        document.querySelectorAll('.pay-method-btn').forEach(btn => {
            btn.className = "pay-method-btn py-3 border border-slate-200 text-slate-600 hover:bg-slate-50 rounded-xl font-semibold text-sm flex flex-col items-center justify-center transition-all";
        });

        // Set style on selected method
        const activeBtn = document.getElementById(`pay-${method}`);
        if (activeBtn) {
            activeBtn.className = "pay-method-btn py-3 border border-emerald-600 rounded-xl font-semibold text-sm flex flex-col items-center justify-center transition-all bg-emerald-50 text-emerald-700 shadow-sm shadow-emerald-500/5";
        }

        // Hide / Show cash inputs
        const cashSection = document.getElementById('cash-payment-section');
        const paidInput = document.getElementById('cash-paid');

        if (method === 'CASH') {
            cashSection.classList.remove('hidden');
            paidInput.setAttribute('required', 'required');
            paidInput.focus();
        } else {
            cashSection.classList.add('hidden');
            paidInput.removeAttribute('required');
        }
        calculateChange();
    }

    // --- CALCULATE CASH CHANGE (KEMBALIAN) ---
    function calculateChange() {
        const grandTotal = getCartGrandTotal();
        const cashInput = document.getElementById('cash-paid');
        const changeInput = document.getElementById('cash-change');
        const errBanner = document.getElementById('payment-error-banner');

        errBanner.classList.add('hidden');

        if (paymentMethod !== 'CASH') {
            changeInput.value = '0';
            return;
        }

        const paidVal = parseFloat(cashInput.value);
        if (isNaN(paidVal) || paidVal <= 0) {
            changeInput.value = '0';
            return;
        }

        const change = paidVal - grandTotal;
        changeInput.value = change.toLocaleString('id-ID');

        if (change < 0) {
            changeInput.value = '-' + Math.abs(change).toLocaleString('id-ID');
        }
    }

    // --- QUICK CASH NOMINAL BUTTONS ---
    function quickCash(amount) {
        const grand = getCartGrandTotal();
        const paidInput = document.getElementById('cash-paid');

        if (amount === 'exact') {
            paidInput.value = grand;
        } else {
            paidInput.value = amount;
        }

        calculateChange();
    }

    // --- GET NET GRAND TOTAL OF CART ---
    function getCartGrandTotal() {
        let sub = 0;
        let disc = 0;
        cart.forEach(item => {
            sub += item.sell_price * item.qty;
            disc += item.discount * item.qty;
        });
        const net = sub - disc;
        return net + Math.round(net * TAX_RATE);
    }

    // --- SUBMIT REAL CHECKOUT TRANSACTION TO PHP API ---
    function processPayment(event) {
        event.preventDefault();

        const grandTotal = getCartGrandTotal();
        const paidInput = document.getElementById('cash-paid');
        const submitBtn = document.getElementById('submit-checkout-btn');
        const errBanner = document.getElementById('payment-error-banner');
        const errText = document.getElementById('payment-error-text');

        errBanner.classList.add('hidden');

        // Verify cash validation
        let amountPaid = grandTotal;
        if (paymentMethod === 'CASH') {
            amountPaid = parseFloat(paidInput.value);
            if (isNaN(amountPaid) || amountPaid < grandTotal) {
                errText.textContent = `Uang pelanggan tidak mencukupi! Minimal pembayaran adalah ${formatRupiah(grandTotal)}.`;
                errBanner.classList.remove('hidden');
                paidInput.focus();
                return;
            }
        }

        // Prepare POST payload
        const payload = {
            items: cart.map(i => ({
                product_id: i.product_id,
                qty: i.qty,
                discount: i.discount
            })),
            customer_id: selectedMember ? selectedMember.id : null,
            discount_tx: 0, // Direct entire transaction discount, omitted or 0
            tax_rate: TAX_RATE,
            payment_method: paymentMethod,
            amount_paid: amountPaid
        };

        // Disable button to prevent double submits
        submitBtn.disabled = true;
        submitBtn.textContent = 'Memproses...';

        fetch('/api/transactions.php?action=checkout', {
                method: 'POST',
                headers: {
                    'Content-Type': 'application/json'
                },
                body: JSON.stringify(payload)
            })
            .then(res => res.json())
            .then(data => {
                submitBtn.disabled = false;
                submitBtn.textContent = 'Proses Selesai (Ctrl+Enter)';

                if (data.success && data.data) {
                    // Redirect directly to printer thermal receipt page
                    location.href = `/cashier/receipt.php?invoice=${encodeURIComponent(data.data.invoice_no)}`;
                } else {
                    errText.textContent = data.message || "Gagal memproses transaksi.";
                    errBanner.classList.remove('hidden');
                }
            })
            .catch(err => {
                console.error(err);
                submitBtn.disabled = false;
                submitBtn.textContent = 'Proses Selesai (Ctrl+Enter)';
                errText.textContent = "Terjadi kesalahan koneksi internet atau server.";
                errBanner.classList.remove('hidden');
            });
    }

    // --- FORMAT CURRENCY IN JS ---
    function formatRupiah(value) {
        return 'Rp ' + Math.round(value).toLocaleString('id-ID');
    }

    function escapeHtml(text) {
        return text
            .replace(/&/g, "&amp;")
            .replace(/</g, "&lt;")
            .replace(/>/g, "&gt;")
            .replace(/"/g, "&quot;")
            .replace(/'/g, "&#039;");
    }

    function escapeQuote(text) {
        return text.replace(/'/g, "\\'");
    }
</script>

<?php include __DIR__ . '/../includes/footer.php'; ?>