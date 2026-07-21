<?php
// /cashier/receipt.php
// MartFlow POS - Thermal Receipt Slip Page

require_once __DIR__ . '/../includes/functions.php';
require_login();

$invoice_no = trim($_GET['invoice'] ?? '');
if (empty($invoice_no)) {
    die("Error: Parameter invoice tidak valid!");
}

$db = Database::getConnection();

// Fetch transaction main record
$stmt = $db->prepare("
    SELECT t.*, u.name as cashier_name, c.name as customer_name, c.member_id as customer_member_id, c.points as customer_points
    FROM transactions t
    JOIN users u ON t.user_id = u.id
    LEFT JOIN customers c ON t.customer_id = c.id
    WHERE t.invoice_no = :invoice_no LIMIT 1
");
$stmt->execute(['invoice_no' => $invoice_no]);
$tx = $stmt->fetch();

if (!$tx) {
    die("Error: Transaksi dengan nomor invoice '$invoice_no' tidak ditemukan di database.");
}

// Fetch transaction items
$stmt_items = $db->prepare("
    SELECT ti.*, p.name as product_name, p.sku as product_sku
    FROM transaction_items ti
    JOIN products p ON ti.product_id = p.id
    WHERE ti.transaction_id = :tx_id
");
$stmt_items->execute(['tx_id' => $tx['id']]);
$items = $stmt_items->fetchAll();

$app_config = include(__DIR__ . '/../config/app.php');
?>
<!DOCTYPE html>
<html lang="id">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Struk Transaksi <?= esc($invoice_no) ?></title>
    <script src="https://cdn.tailwindcss.com"></script>
    <link href="https://fonts.googleapis.com/css2?family=Inconsolata:wght@400;500;700&family=Inter:wght@400;500;600;700&display=swap" rel="stylesheet">
    <style>
        body {
            font-family: 'Inter', sans-serif;
        }
        /* Style specifically formatted for receipt view */
        .receipt-font {
            font-family: 'Inconsolata', monospace;
        }
        
        /* Printer print stylesheets */
        @media print {
            body {
                background: #ffffff;
                color: #000000;
                padding: 0;
                margin: 0;
            }
            .no-print {
                display: none !important;
            }
            .print-area {
                box-shadow: none !important;
                border: none !important;
                margin: 0 !important;
                padding: 0 !important;
                width: 80mm !important; /* Standard 80mm thermal paper width */
                max-width: 80mm !important;
            }
        }
    </style>
</head>
<body class="bg-slate-900 text-slate-800 min-h-screen py-10 px-4 flex flex-col items-center justify-center">

    <!-- Action Buttons bar (Hidden during print) -->
    <div class="no-print max-w-[340px] w-full flex justify-between mb-5">
        <a href="/cashier/pos.php" class="inline-flex items-center px-4 py-2.5 bg-slate-800 hover:bg-slate-700 text-slate-200 text-xs font-semibold rounded-xl border border-slate-700 transition-all">
            <svg class="w-4 h-4 mr-1.5" fill="none" stroke="currentColor" viewBox="0 0 24 24" xmlns="http://www.w3.org/2000/svg">
                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M10 19l-7-7m0 0l7-7m-7 7h18"></path>
            </svg>
            Kasir Baru
        </a>
        
        <button onclick="window.print()" class="cursor-pointer inline-flex items-center px-4 py-2.5 bg-emerald-600 hover:bg-emerald-700 text-white text-xs font-semibold rounded-xl shadow-lg shadow-emerald-500/20 transition-all">
            <svg class="w-4 h-4 mr-1.5" fill="none" stroke="currentColor" viewBox="0 0 24 24" xmlns="http://www.w3.org/2000/svg">
                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M17 17h2a2 2 0 002-2v-4a2 2 0 00-2-2H5a2 2 0 00-2 2v4a2 2 0 002 2h2m2 4h10a2 2 0 002-2v-4a2 2 0 00-2-2H9a2 2 0 00-2 2v4a2 2 0 002 2zm8-12V5a2 2 0 00-2-2H9a2 2 0 00-2 2v4h10z"></path>
            </svg>
            Cetak Struk
        </button>
    </div>

    <!-- Thermal Receipt Paper Container -->
    <div class="print-area max-w-[340px] w-full bg-white border border-slate-200 rounded-2xl shadow-2xl p-6 receipt-font text-xs text-black">
        
        <!-- Store Header Info -->
        <div class="text-center space-y-1 mb-5">
            <h1 class="text-sm font-extrabold tracking-wide uppercase font-sans"><?= esc($app_config['store_name']) ?></h1>
            <p class="text-[10px] text-slate-500 leading-relaxed"><?= esc($app_config['store_address']) ?></p>
            <p class="text-[10px] text-slate-500">Telp: <?= esc($app_config['store_phone']) ?></p>
        </div>

        <div class="border-t border-dashed border-slate-300 py-3 space-y-1 text-[10px] text-slate-600">
            <div class="flex justify-between">
                <span>Invoice: <span class="font-bold text-black"><?= esc($tx['invoice_no']) ?></span></span>
                <span>Kasir: <?= esc($tx['cashier_name']) ?></span>
            </div>
            <div class="flex justify-between">
                <span>Tanggal: <?= date('d-m-Y H:i', strtotime($tx['created_at'])) ?></span>
                <span>Cara: <span class="font-bold text-black"><?= esc($tx['payment_method']) ?></span></span>
            </div>
            <?php if ($tx['customer_name']): ?>
                <div class="border-t border-dotted border-slate-200 pt-1 flex justify-between">
                    <span>Member: <?= esc($tx['customer_name']) ?></span>
                    <span>ID: <?= esc($tx['customer_member_id']) ?></span>
                </div>
            <?php endif; ?>
        </div>

        <!-- Purchase List -->
        <div class="border-t border-dashed border-slate-300 py-3">
            <div class="space-y-3">
                <?php foreach ($items as $item): ?>
                    <div>
                        <!-- Product name -->
                        <p class="font-semibold text-black leading-tight"><?= esc($item['product_name']) ?></p>
                        
                        <!-- Pricing Details (Qty x Price - Discount) -->
                        <div class="flex justify-between text-[11px] text-slate-600 mt-1">
                            <span>
                                <?= $item['qty'] ?> x <?= number_format($item['sell_price'], 0, ',', '.') ?>
                                <?php if (floatval($item['discount']) > 0): ?>
                                    <span class="text-red-600">(-<?= number_format($item['discount'], 0, ',', '.') ?>)</span>
                                <?php endif; ?>
                            </span>
                            <span class="font-bold text-black"><?= number_format($item['subtotal'], 0, ',', '.') ?></span>
                        </div>
                    </div>
                <?php endforeach; ?>
            </div>
        </div>

        <!-- Financial Totals section -->
        <div class="border-t border-dashed border-slate-300 py-3 space-y-2 text-[11px]">
            <div class="flex justify-between">
                <span class="text-slate-600">Subtotal Kotor:</span>
                <span><?= number_format($tx['subtotal'], 0, ',', '.') ?></span>
            </div>
            <?php if (floatval($tx['discount']) > 0): ?>
                <div class="flex justify-between text-red-600">
                    <span>Potongan Diskon:</span>
                    <span>-<?= number_format($tx['discount'], 0, ',', '.') ?></span>
                </div>
            <?php endif; ?>
            <div class="flex justify-between">
                <span class="text-slate-600">PPN (11%):</span>
                <span><?= number_format($tx['tax'], 0, ',', '.') ?></span>
            </div>
            
            <div class="border-t border-dotted border-slate-200 pt-2 flex justify-between text-sm font-extrabold text-black">
                <span>TOTAL AKHIR:</span>
                <span>Rp <?= number_format($tx['total'], 0, ',', '.') ?></span>
            </div>
        </div>

        <!-- Cash transactions Paid and Change -->
        <div class="border-t border-dashed border-slate-300 py-3 space-y-1 text-[11px]">
            <div class="flex justify-between text-slate-700">
                <span>Dibayar:</span>
                <span><?= number_format($tx['amount_paid'], 0, ',', '.') ?></span>
            </div>
            <?php if ($tx['payment_method'] === 'CASH'): ?>
                <div class="flex justify-between font-bold text-black">
                    <span>Uang Kembali:</span>
                    <span>Rp <?= number_format($tx['change_amount'], 0, ',', '.') ?></span>
                </div>
            <?php endif; ?>
        </div>

        <!-- Member Points summary -->
        <?php if ($tx['customer_name']): ?>
            <div class="border-t border-dashed border-slate-300 py-2.5 text-center text-[10px] text-slate-600 space-y-0.5">
                <p>Poin Terkumpul Baru: <span class="font-bold text-black">+<?= floor($tx['total'] * $app_config['points_multiplier']) ?> Pts</span></p>
                <p>Total Akumulasi Poin: <span class="font-bold text-black"><?= number_format($tx['customer_points'], 0, ',', '.') ?> Pts</span></p>
            </div>
        <?php endif; ?>

        <!-- Footer greeting card -->
        <div class="border-t border-dashed border-slate-300 pt-5 text-center text-[9px] text-slate-500 whitespace-pre-line leading-relaxed">
            <?= esc($app_config['store_receipt_footer']) ?>
        </div>

    </div>

    <!-- Interactive navigation buttons (Hidden during print) -->
    <div class="no-print mt-6 max-w-[340px] w-full flex flex-col space-y-2.5">
        <a href="/cashier/pos.php" class="cursor-pointer text-center w-full py-3.5 bg-emerald-600 hover:bg-emerald-700 text-white font-semibold rounded-2xl shadow-lg shadow-emerald-500/10 transition-all text-sm">
            Mulai Transaksi Baru (Kasir POS)
        </a>
        <a href="/cashier/transactions.php" class="cursor-pointer text-center w-full py-3 border border-slate-700 text-slate-300 font-semibold rounded-2xl hover:bg-slate-800 transition-all text-xs">
            Buka Riwayat Transaksi
        </a>
    </div>

    <script>
        // Automatic trigger print on slip open (Optional, commented to prevent annoying prompts during dev, but easily uncommentable)
        // window.onload = function() { window.print(); }
    </script>
</body>
</html>
