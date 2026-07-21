<?php
// /admin/users.php
// MartFlow POS - User & Employee Management (ADMIN ONLY)

require_once __DIR__ . '/../includes/functions.php';
require_role(['ADMIN']); // Strict admin-only access

$page_title = 'Kelola Pengguna';
$db = Database::getConnection();

$error = '';
$success = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = $_POST['action'] ?? '';
    $token = $_POST['csrf_token'] ?? '';

    if (!verify_csrf_token($token)) {
        $error = 'Validasi keamanan CSRF gagal. Silakan coba lagi.';
    } else {
        // --- ADD OR EDIT USER / EMPLOYEE ---
        if ($action === 'save') {
            $id = isset($_POST['id']) && !empty($_POST['id']) ? intval($_POST['id']) : null;
            $username = strtolower(trim($_POST['username'] ?? ''));
            $name = trim($_POST['name'] ?? '');
            $role = trim($_POST['role'] ?? 'CASHIER');
            $status = trim($_POST['status'] ?? 'ACTIVE');
            $password = trim($_POST['password'] ?? '');

            if (empty($username) || empty($name)) {
                $error = 'Username dan Nama Karyawan wajib diisi!';
            } else {
                try {
                    // Check username uniqueness
                    $user_check = $db->prepare("SELECT id FROM users WHERE username = :username AND id != :id");
                    $user_check->execute(['username' => $username, 'id' => $id ?? 0]);

                    if ($user_check->fetch()) {
                        $error = "Gagal menyimpan: Username '$username' sudah terdaftar untuk karyawan lain.";
                    } else {
                        if ($id) {
                            // UPDATE USER
                            if (!empty($password)) {
                                // Password updated
                                $hashed_password = password_hash($password, PASSWORD_DEFAULT);
                                $stmt = $db->prepare("
                                    UPDATE users SET 
                                        username = :username, password = :password, 
                                        name = :name, role = :role, status = :status 
                                    WHERE id = :id
                                ");
                                $stmt->execute([
                                    'username' => $username, 'password' => $hashed_password,
                                    'name' => $name, 'role' => $role, 'status' => $status,
                                    'id' => $id
                                ]);
                            } else {
                                // Password left blank (no change)
                                $stmt = $db->prepare("
                                    UPDATE users SET 
                                        username = :username, name = :name, 
                                        role = :role, status = :status 
                                    WHERE id = :id
                                ");
                                $stmt->execute([
                                    'username' => $username, 'name' => $name,
                                    'role' => $role, 'status' => $status,
                                    'id' => $id
                                ]);
                            }
                            
                            // Prevent lock out of self (if admin deactivates or changes role of self)
                            if ($id === $_SESSION['user_id']) {
                                $_SESSION['role'] = $role;
                                $_SESSION['name'] = $name;
                            }

                            $success = "Karyawan '" . esc($name) . "' berhasil diperbarui.";
                            write_log("Updated employee account entry (ID: $id) - Username: $username");
                        } else {
                            // CREATE NEW USER
                            if (empty($password)) {
                                $error = 'Password wajib diisi untuk pembuatan akun karyawan baru!';
                            } else {
                                $hashed_password = password_hash($password, PASSWORD_DEFAULT);
                                $stmt = $db->prepare("
                                    INSERT INTO users (username, password, name, role, status) 
                                    VALUES (:username, :password, :name, :role, :status)
                                ");
                                $stmt->execute([
                                    'username' => $username, 'password' => $hashed_password,
                                    'name' => $name, 'role' => $role, 'status' => $status
                                ]);
                                $success = "Akun Karyawan '" . esc($name) . "' berhasil dibuat.";
                                write_log("Registered new employee account: $username ($role)");
                            }
                        }
                    }
                } catch (Exception $e) {
                    $error = 'Database error: ' . $e->getMessage();
                }
            }
        }

        // --- DELETE USER ---
        if ($action === 'delete') {
            $id = intval($_POST['id'] ?? 0);
            
            if ($id === $_SESSION['user_id']) {
                $error = 'Gagal menghapus: Anda tidak boleh menghapus akun administratif Anda sendiri!';
            } else {
                try {
                    // Check if user has active transaction logs
                    $tx_check = $db->prepare("SELECT COUNT(*) FROM transactions WHERE user_id = ?");
                    $tx_check->execute([$id]);
                    $count = intval($tx_check->fetchColumn());

                    if ($count > 0) {
                        // User has processed transactions, soft-delete instead
                        $db->prepare("UPDATE users SET status = 'INACTIVE' WHERE id = ?")->execute([$id]);
                        $success = "Akun karyawan memiliki $count log transaksi penjualan. Akun dinonaktifkan demi keutuhan rekam kasir.";
                    } else {
                        $db->prepare("DELETE FROM users WHERE id = ?")->execute([$id]);
                        $success = "Akun karyawan berhasil dihapus permanen.";
                    }
                    write_log("Deleted or disabled user account ID: $id");
                } catch (Exception $e) {
                    $error = 'Gagal menghapus karyawan: ' . $e->getMessage();
                }
            }
        }
    }
}

// Fetch all users
$users = $db->query("
    SELECT u.*, COUNT(t.id) as tx_count 
    FROM users u
    LEFT JOIN transactions t ON u.id = t.user_id
    GROUP BY u.id
    ORDER BY u.name ASC
")->fetchAll();

include __DIR__ . '/../includes/header.php';
include __DIR__ . '/../includes/sidebar.php';
?>

<!-- Main Content Area -->
<main class="flex-1 overflow-y-auto bg-slate-50 p-6 md:p-8">
    <!-- Page Header -->
    <div class="flex flex-col md:flex-row md:items-center md:justify-between mb-8 space-y-4 md:space-y-0">
        <div>
            <h2 class="text-2xl font-bold text-slate-900 tracking-tight">Kelola Hak Akses Pengguna & Karyawan</h2>
            <p class="text-sm text-slate-500 mt-1">Konfigurasikan kewenangan sistem untuk Admin, Manager Keuangan, dan Petugas Kasir.</p>
        </div>
        <button onclick="openUserModal()" class="cursor-pointer inline-flex items-center px-4.5 py-2.5 bg-emerald-600 hover:bg-emerald-700 text-sm font-semibold text-white rounded-xl shadow-lg shadow-emerald-600/20 transition-all">
            <i data-lucide="user-plus" class="w-4.5 h-4.5 mr-2"></i>
            Tambah Karyawan Baru
        </button>
    </div>

    <!-- Alert Messages -->
    <?php if (!empty($error)): ?>
        <div class="mb-6 bg-red-50 border border-red-200 rounded-xl p-4 flex items-start space-x-3 text-sm text-red-700 animate-shake">
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

    <!-- DATA TABLE -->
    <div class="bg-white border border-slate-200 rounded-2xl shadow-sm overflow-hidden max-w-5xl">
        <div class="overflow-x-auto">
            <table class="w-full text-left text-sm border-collapse">
                <thead>
                    <tr class="bg-slate-50 text-slate-600 font-mono text-xs uppercase border-b border-slate-200">
                        <th class="px-6 py-4 font-semibold">Username Akun</th>
                        <th class="px-6 py-4 font-semibold">Nama Karyawan</th>
                        <th class="px-6 py-4 font-semibold">Hak Akses Role</th>
                        <th class="px-6 py-4 font-semibold text-center">Transaksi Diproses</th>
                        <th class="px-6 py-4 text-center">Status</th>
                        <th class="px-6 py-4 text-right">Aksi</th>
                    </tr>
                </thead>
                <tbody class="divide-y divide-slate-100">
                    <?php foreach ($users as $u): ?>
                        <tr class="hover:bg-slate-50/50 transition-colors text-xs">
                            <td class="px-6 py-4">
                                <span class="font-mono font-bold text-slate-900 text-sm">@<?= esc($u['username']) ?></span>
                            </td>
                            <td class="px-6 py-4 font-semibold text-slate-900">
                                <?= esc($u['name']) ?>
                                <?php if ($u['id'] === $_SESSION['user_id']): ?>
                                    <span class="ml-1.5 inline-block px-1.5 py-0.5 rounded text-[9px] font-bold bg-slate-900 text-white font-mono uppercase">Anda</span>
                                <?php endif; ?>
                            </td>
                            <td class="px-6 py-4">
                                <?php 
                                $r_text = 'Kasir POS';
                                $r_class = 'bg-blue-50 text-blue-800 border-blue-200';
                                
                                if ($u['role'] === 'ADMIN') {
                                    $r_text = 'Administrator';
                                    $r_class = 'bg-slate-900 text-white border-slate-950';
                                } else if ($u['role'] === 'MANAGER') {
                                    $r_text = 'Manager';
                                    $r_class = 'bg-emerald-100 text-emerald-800 border-emerald-200';
                                }
                                ?>
                                <span class="inline-block px-2 py-0.5 rounded text-[10px] font-bold border <?= $r_class ?>">
                                    <?= $r_text ?>
                                </span>
                            </td>
                            <td class="px-6 py-4 text-center font-bold font-mono text-slate-700">
                                <?= $u['tx_count'] ?> Invoice
                            </td>
                            <td class="px-6 py-4 text-center">
                                <span class="inline-flex items-center px-2.5 py-0.5 rounded-full text-[10px] font-bold border <?= $u['status'] === 'ACTIVE' ? 'bg-green-100 text-green-800 border-green-200' : 'bg-red-50 text-red-600 border-red-100' ?>">
                                    <?= $u['status'] === 'ACTIVE' ? 'Aktif' : 'Non-Aktif' ?>
                                </span>
                            </td>
                            <td class="px-6 py-4 text-right">
                                <div class="inline-flex items-center space-x-2">
                                    <button onclick="openUserModal(<?= htmlspecialchars(json_encode($u)) ?>)" class="cursor-pointer p-1.5 bg-slate-100 hover:bg-slate-200 text-slate-600 hover:text-slate-900 rounded-lg transition-all" title="Edit">
                                        <i data-lucide="edit-3" class="w-4 h-4"></i>
                                    </button>
                                    <?php if ($u['id'] !== $_SESSION['user_id']): ?>
                                        <button onclick="confirmDelete(<?= $u['id'] ?>, '<?= esc($u['name']) ?>')" class="cursor-pointer p-1.5 bg-red-50 hover:bg-red-100 text-red-500 hover:text-red-700 rounded-lg transition-all" title="Delete">
                                            <i data-lucide="trash-2" class="w-4 h-4"></i>
                                        </button>
                                    <?php endif; ?>
                                </div>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    </div>
</main>

<!-- ================================================== -->
<!-- MODAL: ADD / EDIT EMPLOYEE ACCOUNT -->
<!-- ================================================== -->
<div id="user-modal" class="hidden fixed inset-0 bg-slate-950/70 backdrop-blur-sm z-50 flex items-center justify-center p-4">
    <div class="bg-white rounded-3xl shadow-2xl border border-slate-200 max-w-sm w-full overflow-hidden flex flex-col transform transition-all animate-scaleIn">
        <div class="px-6 py-5 border-b border-slate-100 flex items-center justify-between bg-slate-50">
            <h3 class="font-extrabold text-slate-800" id="modal-title">Registrasi Akun Karyawan</h3>
            <button onclick="closeUserModal()" class="text-slate-400 hover:text-slate-600 cursor-pointer">
                <i data-lucide="x" class="w-6 h-6"></i>
            </button>
        </div>

        <form action="/admin/users.php" method="POST" class="p-6 space-y-4">
            <input type="hidden" name="csrf_token" value="<?= csrf_token() ?>">
            <input type="hidden" name="action" value="save">
            <input type="hidden" name="id" id="user-id" value="">

            <!-- Username -->
            <div>
                <label for="usr-username" class="block text-xs font-semibold text-slate-500 uppercase tracking-wider mb-1.5">Username *</label>
                <div class="relative">
                    <span class="absolute inset-y-0 left-0 pl-3 flex items-center text-xs text-slate-400 font-mono">@</span>
                    <input type="text" name="username" id="usr-username" required class="block w-full pl-7 pr-3 py-2.5 border border-slate-200 rounded-xl text-xs font-mono" placeholder="budi.s">
                </div>
            </div>

            <!-- Employee Full name -->
            <div>
                <label for="usr-name" class="block text-xs font-semibold text-slate-500 uppercase tracking-wider mb-1.5">Nama Lengkap Karyawan *</label>
                <input type="text" name="name" id="usr-name" required class="block w-full px-3 py-2.5 border border-slate-200 rounded-xl text-xs" placeholder="Budi Santoso">
            </div>

            <!-- Password Reset/Init input -->
            <div>
                <label for="usr-password" class="block text-xs font-semibold text-slate-500 uppercase tracking-wider mb-1.5">Kata Sandi (Password)</label>
                <input type="password" name="password" id="usr-password" class="block w-full px-3 py-2.5 border border-slate-200 rounded-xl text-xs" placeholder="Ketik password baru...">
                <p class="text-[10px] text-slate-400 mt-1" id="password-hint">Kosongkan jika tidak ingin mengubah password akun.</p>
            </div>

            <div class="grid grid-cols-2 gap-4">
                <!-- Role -->
                <div>
                    <label for="usr-role" class="block text-xs font-semibold text-slate-500 uppercase tracking-wider mb-1.5">Hak Akses Role</label>
                    <select name="role" id="usr-role" class="block w-full px-3 py-2.5 border border-slate-200 rounded-xl text-xs bg-slate-50">
                        <option value="CASHIER">CASHIER (Kasir)</option>
                        <option value="MANAGER">MANAGER (Keuangan)</option>
                        <option value="ADMIN">ADMINISTRATOR</option>
                    </select>
                </div>

                <!-- Status -->
                <div>
                    <label for="usr-status" class="block text-xs font-semibold text-slate-500 uppercase tracking-wider mb-1.5">Status Akun</label>
                    <select name="status" id="usr-status" class="block w-full px-3 py-2.5 border border-slate-200 rounded-xl text-xs bg-slate-50">
                        <option value="ACTIVE">Aktif (Aktif)</option>
                        <option value="INACTIVE">Non-Aktif (Terkunci)</option>
                    </select>
                </div>
            </div>

            <div class="grid grid-cols-2 gap-4 pt-3 border-t border-slate-100">
                <button type="button" onclick="closeUserModal()" class="cursor-pointer py-2.5 border border-slate-200 text-slate-700 font-semibold rounded-xl text-xs text-center hover:bg-slate-50 transition-all">Batal</button>
                <button type="submit" class="cursor-pointer py-2.5 bg-emerald-600 hover:bg-emerald-700 text-white font-semibold rounded-xl text-xs text-center shadow-md transition-all">Simpan Akun</button>
            </div>
        </form>
    </div>
</div>

<!-- ================================================== -->
<!-- MODAL: CONFIRM DELETE -->
<!-- ================================================== -->
<div id="delete-modal" class="hidden fixed inset-0 bg-slate-950/70 backdrop-blur-sm z-50 flex items-center justify-center p-4">
    <div class="bg-white rounded-2xl shadow-2xl border border-slate-200 max-w-sm w-full p-6 text-center animate-scaleIn">
        <div class="w-12 h-12 bg-red-100 text-red-600 rounded-full flex items-center justify-center mx-auto mb-4">
            <i data-lucide="alert-triangle" class="w-6 h-6"></i>
        </div>
        <h3 class="text-sm font-bold text-slate-900 mb-1">Hapus Akun Karyawan?</h3>
        <p class="text-slate-500 text-xs mb-5" id="delete-alert-text">Apakah Anda yakin ingin menghapus akun ini?</p>
        
        <form action="/admin/users.php" method="POST" class="grid grid-cols-2 gap-3">
            <input type="hidden" name="csrf_token" value="<?= csrf_token() ?>">
            <input type="hidden" name="action" value="delete">
            <input type="hidden" name="id" id="delete-id" value="">
            
            <button type="button" onclick="closeDeleteModal()" class="cursor-pointer py-2 border border-slate-200 text-slate-700 font-semibold rounded-xl text-xs hover:bg-slate-50 transition-all">Batal</button>
            <button type="submit" class="cursor-pointer py-2 bg-red-600 hover:bg-red-700 text-white font-semibold rounded-xl text-xs shadow-md transition-all">Hapus Karyawan</button>
        </form>
    </div>
</div>

<script>
    function openUserModal(user = null) {
        document.getElementById('user-id').value = '';
        document.getElementById('usr-username').value = '';
        document.getElementById('usr-name').value = '';
        document.getElementById('usr-password').value = '';
        document.getElementById('usr-role').value = 'CASHIER';
        document.getElementById('usr-status').value = 'ACTIVE';
        
        const title = document.getElementById('modal-title');
        const hint = document.getElementById('password-hint');

        if (user) {
            title.textContent = "Edit Detail Akun";
            hint.textContent = "Biarkan kosong jika tidak ingin merubah sandi akun.";
            document.getElementById('user-id').value = user.id;
            document.getElementById('usr-username').value = user.username;
            document.getElementById('usr-name').value = user.name;
            document.getElementById('usr-role').value = user.role;
            document.getElementById('usr-status').value = user.status;
        } else {
            title.textContent = "Registrasi Karyawan Baru";
            hint.textContent = "Sandi bersifat wajib diisi untuk registrasi baru.";
            document.getElementById('usr-password').setAttribute('required', 'required');
        }

        document.getElementById('user-modal').classList.remove('hidden');
    }

    function closeUserModal() {
        document.getElementById('user-modal').classList.add('hidden');
        document.getElementById('usr-password').removeAttribute('required');
    }

    function confirmDelete(id, name) {
        document.getElementById('delete-id').value = id;
        document.getElementById('delete-alert-text').innerHTML = `Apakah Anda yakin ingin menghapus akun karyawan <strong>"${name}"</strong> dari database?<br><span class="text-[10px] text-red-500">Catatan: Jika akun memiliki data penjualan kasir, akun hanya akan ditangguhkan/dinonaktifkan demi keutuhan pembukuan finansial.</span>`;
        document.getElementById('delete-modal').classList.remove('hidden');
    }

    function closeDeleteModal() {
        document.getElementById('delete-modal').classList.add('hidden');
    }
</script>

<?php include __DIR__ . '/../includes/footer.php'; ?>
