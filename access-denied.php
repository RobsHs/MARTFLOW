<?php
// /access-denied.php
// MartFlow POS - Access Denied Screen

require_once __DIR__ . '/includes/functions.php';
require_login();
$user = get_logged_user();
?>
<!DOCTYPE html>
<html lang="en" class="h-full bg-slate-50">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Akses Ditolak | MARTFLOW POS</title>
    <script src="https://cdn.tailwindcss.com"></script>
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700&display=swap" rel="stylesheet">
    <style>body { font-family: 'Inter', sans-serif; }</style>
</head>
<body class="h-full flex items-center justify-center p-6 bg-slate-900">
    <div class="max-w-md w-full bg-slate-800 border border-slate-700 rounded-2xl shadow-2xl p-8 text-center">
        <div class="w-16 h-16 bg-red-500/10 text-red-500 rounded-full flex items-center justify-center mx-auto mb-6">
            <svg class="w-8 h-8" fill="none" stroke="currentColor" viewBox="0 0 24 24" xmlns="http://www.w3.org/2000/svg">
                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 9v2m0 4h.01m-6.938 4h13.856c1.54 0 2.502-1.667 1.732-3L13.732 4c-.77-1.333-2.694-1.333-3.464 0L3.34 16c-.77 1.333.192 3 1.732 3z"></path>
            </svg>
        </div>
        
        <h1 class="text-2xl font-bold text-white mb-2">Akses Ditolak</h1>
        <p class="text-slate-400 text-sm mb-6 leading-relaxed">
            Maaf, akun Anda (<strong><?= esc($user['username']) ?></strong>) dengan hak akses <span class="text-red-400 font-semibold uppercase"><?= esc($user['role']) ?></span> tidak memiliki izin untuk melihat halaman ini.
        </p>
        
        <div class="flex flex-col gap-3">
            <?php if ($user['role'] === 'CASHIER'): ?>
                <a href="/cashier/pos.php" class="inline-flex justify-center items-center px-5 py-3 border border-transparent text-sm font-semibold rounded-xl text-white bg-emerald-600 hover:bg-emerald-700 shadow-lg shadow-emerald-600/25 transition-all">
                    Kembali ke POS Kasir
                </a>
            <?php else: ?>
                <a href="/admin/dashboard.php" class="inline-flex justify-center items-center px-5 py-3 border border-transparent text-sm font-semibold rounded-xl text-white bg-emerald-600 hover:bg-emerald-700 shadow-lg shadow-emerald-600/25 transition-all">
                    Ke Dashboard Admin
                </a>
            <?php endif; ?>
            <a href="/logout.php" class="text-xs text-slate-400 hover:text-white underline py-2">
                Log Out & Gunakan Akun Lain
            </a>
        </div>
    </div>
</body>
</html>
