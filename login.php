<?php
// /login.php
// MartFlow POS - Secure Login Page

require_once __DIR__ . '/includes/functions.php';

// If already logged in, redirect to index
if (is_logged_in()) {
    header("Location: /index.php");
    exit();
}

$error = '';
$username = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $username = trim($_POST['username'] ?? '');
    $password = $_POST['password'] ?? '';
    $token = $_POST['csrf_token'] ?? '';

    // Verify CSRF
    if (!verify_csrf_token($token)) {
        $error = 'Validasi keamanan CSRF gagal. Silakan coba lagi.';
    } else if (empty($username) || empty($password)) {
        $error = 'Username dan password wajib diisi!';
    } else {
        try {
            $db = Database::getConnection();
            $stmt = $db->prepare("SELECT * FROM users WHERE username = :username LIMIT 1");
            $stmt->execute(['username' => $username]);
            $user = $stmt->fetch();

            if ($user && $user['status'] === 'ACTIVE' && password_verify($password, $user['password'])) {
                // Secure Session Bindings
                $_SESSION['user_id'] = $user['id'];
                $_SESSION['username'] = $user['username'];
                $_SESSION['name'] = $user['name'];
                $_SESSION['role'] = $user['role'];

                // Regenerate session ID for security
                session_regenerate_id(true);

                // Write activity log
                write_log("User logged in");

                header("Location: /index.php");
                exit();
            } else {
                $error = 'Username atau password salah, atau akun dinonaktifkan.';
            }
        } catch (Exception $e) {
            $error = 'Terjadi kesalahan sistem: ' . $e->getMessage();
        }
    }
}
?>
<!DOCTYPE html>
<html lang="id" class="h-full bg-slate-50">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Login | MARTFLOW POS</title>
    <!-- Tailwind CSS -->
    <script src="https://cdn.tailwindcss.com"></script>
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700&display=swap" rel="stylesheet">
    <!-- Lucide Icons -->
    <script src="https://unpkg.com/lucide@latest"></script>
    <style>
        body { font-family: 'Inter', sans-serif; }
    </style>
</head>
<body class="h-full flex flex-col md:flex-row min-h-screen bg-slate-50">
    
    <!-- Left Panel: Branding and App Presentation (Desktop Only) -->
    <div class="hidden md:flex md:w-1/2 bg-slate-900 text-white p-12 flex-col justify-between relative overflow-hidden">
        <!-- Background accents -->
        <div class="absolute -right-10 -top-10 w-40 h-40 bg-emerald-500/10 rounded-full blur-3xl"></div>
        <div class="absolute left-20 bottom-10 w-60 h-60 bg-emerald-500/5 rounded-full blur-2xl"></div>
        
        <!-- Logo -->
        <div class="flex items-center space-x-3 z-10">
            <div class="w-12 h-12 rounded-xl bg-emerald-500 flex items-center justify-center font-bold text-white text-2xl shadow-lg shadow-emerald-500/30">M</div>
            <div>
                <h1 class="font-bold text-xl tracking-wider text-white">MARTFLOW POS</h1>
                <p class="text-[10px] text-slate-400 font-mono tracking-widest uppercase">Modern Retail Management System</p>
            </div>
        </div>

        <!-- Tagline / Graphic presentation -->
        <div class="my-auto max-w-lg z-10 space-y-4">
            <h2 class="text-4xl font-extrabold text-white leading-tight">
                Kelola Minimarket & POS dengan Kecepatan Alur Kasir Maksimal.
            </h2>
            <p class="text-slate-300 text-base leading-relaxed">
                MartFlow POS dirancang khusus untuk mengoptimalkan penjualan, manajemen inventori, pemantauan member/pelanggan, dan laporan keuangan komprehensif dalam satu platform yang andal.
            </p>
            <div class="flex items-center space-x-6 pt-4 text-xs font-mono text-emerald-400">
                <div class="flex items-center space-x-2">
                    <i data-lucide="shield-check" class="w-4 h-4 text-emerald-400"></i>
                    <span>Secure Encrypted PDO</span>
                </div>
                <div class="flex items-center space-x-2">
                    <i data-lucide="zap" class="w-4 h-4 text-emerald-400"></i>
                    <span>Real-Time Audit Log</span>
                </div>
            </div>
        </div>

        <!-- Footer -->
        <div class="text-xs text-slate-500 z-10">
            &copy; 2026 MartFlow POS. Hak Cipta Dilindungi Undang-Undang.
        </div>
    </div>

    <!-- Right Panel: Login Form -->
    <div class="flex-1 flex flex-col justify-center items-center p-6 sm:p-12 md:w-1/2">
        <div class="w-full max-w-md space-y-8">
            <!-- Mobile Logo Header (Only visible on small screens) -->
            <div class="flex md:hidden flex-col items-center text-center space-y-2">
                <div class="w-14 h-14 rounded-xl bg-emerald-500 flex items-center justify-center font-bold text-white text-3xl shadow-lg shadow-emerald-500/30">M</div>
                <h1 class="text-2xl font-bold tracking-tight text-slate-900">MARTFLOW POS</h1>
                <p class="text-xs text-slate-500">Modern Retail & POS System</p>
            </div>

            <!-- Page Title -->
            <div class="text-center md:text-left">
                <h2 class="text-2xl font-bold tracking-tight text-slate-950">Masuk ke Sistem</h2>
                <p class="text-sm text-slate-500 mt-1">Gunakan akun MartFlow terdaftar Anda.</p>
            </div>

            <!-- Error Banner -->
            <?php if (!empty($error)): ?>
                <div class="bg-red-50 border border-red-200 rounded-xl p-4 flex items-start space-x-3 text-sm text-red-700 animate-shake">
                    <i data-lucide="alert-circle" class="w-5 h-5 text-red-500 shrink-0 mt-0.5"></i>
                    <span><?= esc($error) ?></span>
                </div>
            <?php endif; ?>

            <!-- Login Form -->
            <form action="/login.php" method="POST" class="space-y-6">
                <!-- CSRF Token -->
                <input type="hidden" name="csrf_token" value="<?= csrf_token() ?>">

                <div>
                    <label for="username" class="block text-sm font-medium text-slate-700 mb-1.5">Username</label>
                    <div class="relative">
                        <div class="absolute inset-y-0 left-0 pl-3.5 flex items-center pointer-events-none text-slate-400">
                            <i data-lucide="user" class="w-5 h-5"></i>
                        </div>
                        <input type="text" name="username" id="username" required value="<?= esc($username) ?>"
                               class="block w-full pl-11 pr-4 py-3 border border-slate-200 rounded-xl text-slate-900 placeholder-slate-400 focus:outline-none focus:ring-2 focus:ring-emerald-500/25 focus:border-emerald-500 transition-all text-sm"
                               placeholder="Masukkan username Anda">
                    </div>
                </div>

                <div>
                    <label for="password" class="block text-sm font-medium text-slate-700 mb-1.5">Password</label>
                    <div class="relative">
                        <div class="absolute inset-y-0 left-0 pl-3.5 flex items-center pointer-events-none text-slate-400">
                            <i data-lucide="lock" class="w-5 h-5"></i>
                        </div>
                        <input type="password" name="password" id="password" required
                               class="block w-full pl-11 pr-11 py-3 border border-slate-200 rounded-xl text-slate-900 placeholder-slate-400 focus:outline-none focus:ring-2 focus:ring-emerald-500/25 focus:border-emerald-500 transition-all text-sm"
                               placeholder="Masukkan password Anda">
                        <button type="button" onclick="togglePasswordVisibility()" class="absolute inset-y-0 right-0 pr-3 flex items-center text-slate-400 hover:text-slate-600 focus:outline-none">
                            <i data-lucide="eye" id="password-eye-icon" class="w-5 h-5"></i>
                        </button>
                    </div>
                </div>

                <button type="submit"
                        class="w-full flex justify-center items-center py-3.5 px-4 border border-transparent rounded-xl shadow-lg shadow-emerald-500/10 text-sm font-semibold text-white bg-emerald-600 hover:bg-emerald-700 focus:outline-none focus:ring-2 focus:ring-offset-2 focus:ring-emerald-500 transition-all cursor-pointer">
                    <i data-lucide="log-in" class="w-4 h-4 mr-2"></i>
                    Masuk Sekarang
                </button>
            </form>

            <!-- Quick Access / Testing Helper Card -->
            <div class="bg-slate-100 border border-slate-200 rounded-2xl p-5 mt-6 space-y-3.5">
                <div class="flex items-center space-x-2 text-slate-700">
                    <i data-lucide="key" class="w-4.5 h-4.5 text-slate-500 shrink-0"></i>
                    <h3 class="text-xs font-bold uppercase tracking-wider text-slate-600 font-mono">Quick Access Credentials</h3>
                </div>
                <div class="grid grid-cols-3 gap-2">
                    <button onclick="fillLogin('admin', 'Admin123!')" type="button" class="flex flex-col items-center justify-center p-2 bg-white hover:bg-slate-50 rounded-xl border border-slate-200 transition-all text-left">
                        <span class="text-[10px] font-bold text-slate-700">Admin</span>
                        <span class="text-[9px] text-slate-400 font-mono mt-0.5">Full Control</span>
                    </button>
                    <button onclick="fillLogin('manager', 'Manager123!')" type="button" class="flex flex-col items-center justify-center p-2 bg-white hover:bg-slate-50 rounded-xl border border-slate-200 transition-all text-left">
                        <span class="text-[10px] font-bold text-slate-700">Manager</span>
                        <span class="text-[9px] text-slate-400 font-mono mt-0.5">Stock & Report</span>
                    </button>
                    <button onclick="fillLogin('cashier', 'Cashier123!')" type="button" class="flex flex-col items-center justify-center p-2 bg-white hover:bg-slate-50 rounded-xl border border-slate-200 transition-all text-left">
                        <span class="text-[10px] font-bold text-slate-700">Kasir</span>
                        <span class="text-[9px] text-slate-400 font-mono mt-0.5">POS Cashier</span>
                    </button>
                </div>
            </div>
        </div>
    </div>

    <!-- Script assets -->
    <script>
        // Toggle password view
        function togglePasswordVisibility() {
            const pwdInput = document.getElementById('password');
            const eyeIcon = document.getElementById('password-eye-icon');
            if (pwdInput.type === 'password') {
                pwdInput.type = 'text';
                eyeIcon.setAttribute('data-lucide', 'eye-off');
            } else {
                pwdInput.type = 'password';
                eyeIcon.setAttribute('data-lucide', 'eye');
            }
            lucide.createIcons();
        }

        // Easy Login Credential Filler
        function fillLogin(username, password) {
            document.getElementById('username').value = username;
            document.getElementById('password').value = password;
        }

        // Initialize Lucide Icons
        lucide.createIcons();
    </script>
</body>
</html>
