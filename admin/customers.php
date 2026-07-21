<?php
// /admin/customers.php
// MartFlow POS - Customer / Member loyalty management

require_once __DIR__ . '/../includes/functions.php';
require_role(['ADMIN', 'MANAGER']);

$page_title = 'Kelola Member';
$db = Database::getConnection();

$error = '';
$success = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = $_POST['action'] ?? '';
    $token = $_POST['csrf_token'] ?? '';

    if (!verify_csrf_token($token)) {
        $error = 'Validasi keamanan CSRF gagal. Silakan coba lagi.';
    } else {
        // --- ADD OR EDIT CUSTOMER MEMBER ---
        if ($action === 'save') {
            $id = isset($_POST['id']) && !empty($_POST['id']) ? intval($_POST['id']) : null;
            $name = trim($_POST['name'] ?? '');
            $member_id = strtoupper(trim($_POST['member_id'] ?? ''));
            $phone = trim($_POST['phone'] ?? '');
            $email = trim($_POST['email'] ?? '');
            $address = trim($_POST['address'] ?? '');
            $level = trim($_POST['level'] ?? 'BRONZE');
            $points = intval($_POST['points'] ?? 0);
            $status = trim($_POST['status'] ?? 'ACTIVE');

            if (empty($name) || empty($member_id)) {
                $error = 'Nama pelanggan dan ID Member wajib diisi!';
            } else if ($points < 0) {
                $error = 'Poin koin tidak boleh bernilai negatif!';
            } else {
                try {
                    // Check Member ID uniqueness
                    $mbr_check = $db->prepare("SELECT id FROM customers WHERE member_id = :member_id AND id != :id");
                    $mbr_check->execute(['member_id' => $member_id, 'id' => $id ?? 0]);

                    if ($mbr_check->fetch()) {
                        $error = "Gagal menyimpan: ID Member '$member_id' sudah terdaftar untuk pelanggan lain.";
                    } else {
                        if ($id) {
                            $stmt = $db->prepare("
                                UPDATE customers SET 
                                    name = :name, member_id = :member_id, phone = :phone, 
                                    email = :email, address = :address, level = :level, 
                                    points = :points, status = :status
                                WHERE id = :id
                            ");
                            $stmt->execute([
                                'name' => $name, 'member_id' => $member_id, 'phone' => $phone,
                                'email' => $email, 'address' => $address, 'level' => $level,
                                'points' => $points, 'status' => $status, 'id' => $id
                            ]);
                            $success = "Member '" . esc($name) . "' berhasil diperbarui.";
                            write_log("Updated customer member details (ID: $id) - MBR ID: $member_id");
                        } else {
                            $stmt = $db->prepare("
                                INSERT INTO customers (name, member_id, phone, email, address, level, points, total_spend, status) 
                                VALUES (:name, :member_id, :phone, :email, :address, :level, :points, 0, :status)
                            ");
                            $stmt->execute([
                                'name' => $name, 'member_id' => $member_id, 'phone' => $phone,
                                'email' => $email, 'address' => $address, 'level' => $level,
                                'points' => $points, 'status' => $status
                            ]);
                            $success = "Member '" . esc($name) . "' berhasil didaftarkan.";
                            write_log("Registered new customer member: $name (ID: $member_id)");
                        }
                    }
                } catch (Exception $e) {
                    $error = 'Database error: ' . $e->getMessage();
                }
            }
        }

        // --- DELETE CUSTOMER ---
        if ($action === 'delete') {
            $id = intval($_POST['id'] ?? 0);
            try {
                // Check if customer has transaction logs
                $tx_check = $db->prepare("SELECT COUNT(*) FROM transactions WHERE customer_id = ?");
                $tx_check->execute([$id]);
                $count = intval($tx_check->fetchColumn());

                if ($count > 0) {
                    // Customer has transactions, soft-delete instead
                    $db->prepare("UPDATE customers SET status = 'INACTIVE' WHERE id = ?")->execute([$id]);
                    $success = "Member memiliki $count riwayat transaksi. Status diubah menjadi NON-AKTIF untuk menjaga integrasi laporan.";
                } else {
                    $db->prepare("DELETE FROM customers WHERE id = ?")->execute([$id]);
                    $success = "Member berhasil dihapus dari database.";
                }
                write_log("Deleted or disabled customer ID: $id");
            } catch (Exception $e) {
                $error = 'Gagal menghapus member: ' . $e->getMessage();
            }
        }
    }
}

// --- PAGINATION, SEARCH FILTERS ---
$search = trim($_GET['search'] ?? '');
$level_filter = trim($_GET['level'] ?? '');

$limit = 10;
$page = isset($_GET['page']) ? max(1, intval($_GET['page'])) : 1;
$offset = ($page - 1) * $limit;

$sql_where = " WHERE 1=1";
$params = [];

if (!empty($search)) {
    $sql_where .= " AND (name LIKE :search OR member_id LIKE :search OR phone LIKE :search)";
    $params['search'] = "%$search%";
}

if (!empty($level_filter)) {
    $sql_where .= " AND level = :level";
    $params['level'] = $level_filter;
}

// Count total matching records
$count_stmt = $db->prepare("SELECT COUNT(*) FROM customers $sql_where");
$count_stmt->execute($params);
$total_records = intval($count_stmt->fetchColumn());
$total_pages = ceil($total_records / $limit);

// Fetch customers
$fetch_stmt = $db->prepare("
    SELECT * FROM customers 
    $sql_where 
    ORDER BY name ASC 
    LIMIT $limit OFFSET $offset
");
$fetch_stmt->execute($params);
$customers = $fetch_stmt->fetchAll();

include __DIR__ . '/../includes/header.php';
include __DIR__ . '/../includes/sidebar.php';
?>

<!-- Main Content Area -->
<main class="flex-1 overflow-y-auto bg-slate-50 p-6 md:p-8">
    <!-- Page Header -->
    <div class="flex flex-col md:flex-row md:items-center md:justify-between mb-8 space-y-4 md:space-y-0">
        <div>
            <h2 class="text-2xl font-bold text-slate-900 tracking-tight">Pelanggan & Member Terdaftar</h2>
            <p class="text-sm text-slate-500 mt-1">Kelola data keanggotaan loyalty member, akumulasi koin poin, dan tier pelanggan.</p>
        </div>
        <button onclick="openMbrModal()" class="cursor-pointer inline-flex items-center px-4.5 py-2.5 bg-emerald-600 hover:bg-emerald-700 text-sm font-semibold text-white rounded-xl shadow-lg shadow-emerald-600/20 transition-all">
            <i data-lucide="user-plus" class="w-4.5 h-4.5 mr-2"></i>
            Daftarkan Member Baru
        </button>
    </div>

    <!-- Feedback Banners -->
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
                       placeholder="Cari ID Member, nama, atau nomor telepon...">
            </div>

            <!-- Filter Level -->
            <div class="sm:col-span-4">
                <select name="level" onchange="this.form.submit()" class="block w-full px-3 py-2 border border-slate-200 rounded-xl text-xs focus:outline-none focus:ring-2 focus:ring-emerald-500/25 focus:border-emerald-500 bg-slate-50">
                    <option value="">Semua Tier Loyalty</option>
                    <option value="BRONZE" <?= $level_filter === 'BRONZE' ? 'selected' : '' ?>>BRONZE</option>
                    <option value="SILVER" <?= $level_filter === 'SILVER' ? 'selected' : '' ?>>SILVER</option>
                    <option value="GOLD" <?= $level_filter === 'GOLD' ? 'selected' : '' ?>>GOLD</option>
                    <option value="PLATINUM" <?= $level_filter === 'PLATINUM' ? 'selected' : '' ?>>PLATINUM</option>
                </select>
            </div>

            <div class="sm:col-span-2">
                <button type="submit" class="cursor-pointer w-full bg-slate-900 hover:bg-slate-800 text-white rounded-xl text-xs font-semibold py-2 transition-all">
                    Cari
                </button>
            </div>
        </form>
    </div>

    <!-- MEMBER DATA TABLE -->
    <div class="bg-white border border-slate-200 rounded-2xl shadow-sm overflow-hidden">
        <div class="overflow-x-auto">
            <table class="w-full text-left text-sm border-collapse">
                <thead>
                    <tr class="bg-slate-50 text-slate-600 font-mono text-xs uppercase border-b border-slate-200">
                        <th class="px-6 py-4 font-semibold">ID Member</th>
                        <th class="px-6 py-4 font-semibold">Nama Pelanggan</th>
                        <th class="px-6 py-4 font-semibold">No Telepon</th>
                        <th class="px-6 py-4 font-semibold">Email</th>
                        <th class="px-6 py-4 font-semibold text-center">Tier</th>
                        <th class="px-6 py-4 font-semibold text-right">Koin Poin</th>
                        <th class="px-6 py-4 font-semibold text-right">Total Belanja</th>
                        <th class="px-6 py-4 text-center">Status</th>
                        <th class="px-6 py-4 text-right">Aksi</th>
                    </tr>
                </thead>
                <tbody class="divide-y divide-slate-100">
                    <?php if (empty($customers)): ?>
                        <tr>
                            <td colspan="9" class="text-center py-12 text-slate-400">
                                <div class="flex flex-col items-center justify-center">
                                    <i data-lucide="users" class="w-10 h-10 text-slate-300 mb-2"></i>
                                    <p class="text-sm font-semibold">Belum ada data member terdaftar</p>
                                </div>
                            </td>
                        </tr>
                    <?php else: ?>
                        <?php foreach ($customers as $c): ?>
                            <?php 
                            // Level Badge Style helper
                            $lvl_class = 'bg-slate-100 text-slate-700';
                            if ($c['level'] === 'SILVER') $lvl_class = 'bg-gray-200 text-gray-800 border-gray-300';
                            else if ($c['level'] === 'GOLD') $lvl_class = 'bg-amber-100 text-amber-800 border-amber-200';
                            else if ($c['level'] === 'PLATINUM') $lvl_class = 'bg-emerald-100 text-emerald-800 border-emerald-200';
                            ?>
                            <tr class="hover:bg-slate-50/50 transition-colors text-xs">
                                <td class="px-6 py-4 font-mono font-bold text-slate-900 text-sm">
                                    <?= esc($c['member_id']) ?>
                                </td>
                                <td class="px-6 py-4 font-semibold text-slate-900">
                                    <?= esc($c['name']) ?>
                                </td>
                                <td class="px-6 py-4 font-mono text-slate-600">
                                    <?= esc($c['phone'] ?: '-') ?>
                                </td>
                                <td class="px-6 py-4 text-slate-500">
                                    <?= esc($c['email'] ?: '-') ?>
                                </td>
                                <td class="px-6 py-4 text-center">
                                    <span class="inline-block px-2 py-0.5 rounded text-[10px] font-bold border <?= $lvl_class ?>">
                                        <?= $c['level'] ?>
                                    </span>
                                </td>
                                <td class="px-6 py-4 text-right font-bold font-mono text-slate-800">
                                    <?= number_format($c['points'], 0, ',', '.') ?> Pts
                                </td>
                                <td class="px-6 py-4 text-right font-bold font-mono text-emerald-600">
                                    <?= format_rupiah($c['total_spend']) ?>
                                </td>
                                <td class="px-6 py-4 text-center">
                                    <span class="inline-flex items-center px-2.5 py-0.5 rounded-full text-[10px] font-semibold <?= $c['status'] === 'ACTIVE' ? 'bg-green-100 text-green-800' : 'bg-slate-100 text-slate-800' ?>">
                                        <?= $c['status'] === 'ACTIVE' ? 'Aktif' : 'Non-Aktif' ?>
                                    </span>
                                </td>
                                <td class="px-6 py-4 text-right">
                                    <div class="inline-flex items-center space-x-2">
                                        <button onclick="openMbrModal(<?= htmlspecialchars(json_encode($c)) ?>)" class="cursor-pointer p-1.5 bg-slate-100 hover:bg-slate-200 text-slate-600 hover:text-slate-900 rounded-lg transition-all" title="Edit">
                                            <i data-lucide="edit-3" class="w-4 h-4"></i>
                                        </button>
                                        <button onclick="confirmDelete(<?= $c['id'] ?>, '<?= esc($c['name']) ?>')" class="cursor-pointer p-1.5 bg-red-50 hover:bg-red-100 text-red-500 hover:text-red-700 rounded-lg transition-all" title="Delete">
                                            <i data-lucide="trash-2" class="w-4 h-4"></i>
                                        </button>
                                    </div>
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
                <span class="text-slate-500">Menampilkan <?= count($customers) ?> dari <?= $total_records ?> member</span>
                <div class="flex items-center space-x-1">
                    <a href="?page=<?= max(1, $page - 1) ?>&search=<?= urlencode($search) ?>&level=<?= $level_filter ?>"
                       class="px-3 py-1.5 border border-slate-200 rounded-lg text-slate-600 hover:bg-slate-100 <?= $page <= 1 ? 'pointer-events-none opacity-40' : '' ?>">Sebelumnya</a>
                    
                    <?php for ($i = 1; $i <= $total_pages; $i++): ?>
                        <a href="?page=<?= $i ?>&search=<?= urlencode($search) ?>&level=<?= $level_filter ?>"
                           class="px-3 py-1.5 rounded-lg border <?= $i === $page ? 'bg-emerald-600 border-emerald-600 text-white shadow-md' : 'border-slate-200 hover:bg-slate-100 text-slate-600' ?>"><?= $i ?></a>
                    <?php endfor; ?>

                    <a href="?page=<?= min($total_pages, $page + 1) ?>&search=<?= urlencode($search) ?>&level=<?= $level_filter ?>"
                       class="px-3 py-1.5 border border-slate-200 rounded-lg text-slate-600 hover:bg-slate-100 <?= $page >= $total_pages ? 'pointer-events-none opacity-40' : '' ?>">Selanjutnya</a>
                </div>
            </div>
        <?php endif; ?>
    </div>
</main>

<!-- ================================================== -->
<!-- MODAL: REGISTER / EDIT CUSTOMER MEMBER -->
<!-- ================================================== -->
<div id="mbr-modal" class="hidden fixed inset-0 bg-slate-950/70 backdrop-blur-sm z-50 flex items-center justify-center p-4">
    <div class="bg-white rounded-3xl shadow-2xl border border-slate-200 max-w-md w-full overflow-hidden flex flex-col transform transition-all animate-scaleIn">
        <div class="px-6 py-5 border-b border-slate-100 flex items-center justify-between bg-slate-50">
            <h3 class="font-extrabold text-slate-800" id="modal-title">Daftar Member Baru</h3>
            <button onclick="closeMbrModal()" class="text-slate-400 hover:text-slate-600 cursor-pointer">
                <i data-lucide="x" class="w-6 h-6"></i>
            </button>
        </div>

        <form action="/admin/customers.php" method="POST" class="p-6 space-y-4">
            <input type="hidden" name="csrf_token" value="<?= csrf_token() ?>">
            <input type="hidden" name="action" value="save">
            <input type="hidden" name="id" id="mbr-id" value="">

            <div class="grid grid-cols-2 gap-4">
                <!-- Member ID -->
                <div>
                    <label for="mbr-code" class="block text-xs font-semibold text-slate-500 uppercase tracking-wider mb-1.5">ID Kartu Member *</label>
                    <div class="relative">
                        <input type="text" name="member_id" id="mbr-code" required class="block w-full px-3 py-2.5 border border-slate-200 rounded-xl text-xs uppercase font-mono" placeholder="MBR-XXXX">
                        <button type="button" onclick="generateRandomMemberId()" class="absolute inset-y-0 right-0 pr-3 flex items-center text-emerald-500 hover:text-emerald-700 text-xs font-semibold" title="Generate ID">Auto</button>
                    </div>
                </div>

                <!-- Membership Tier -->
                <div>
                    <label for="mbr-lvl" class="block text-xs font-semibold text-slate-500 uppercase tracking-wider mb-1.5">Tier Loyalty</label>
                    <select name="level" id="mbr-lvl" class="block w-full px-3 py-2.5 border border-slate-200 rounded-xl text-xs bg-slate-50">
                        <option value="BRONZE">BRONZE (Basic)</option>
                        <option value="SILVER">SILVER</option>
                        <option value="GOLD">GOLD</option>
                        <option value="PLATINUM">PLATINUM (Premium)</option>
                    </select>
                </div>
            </div>

            <!-- Customer Name -->
            <div>
                <label for="mbr-name" class="block text-xs font-semibold text-slate-500 uppercase tracking-wider mb-1.5">Nama Lengkap Pelanggan *</label>
                <input type="text" name="name" id="mbr-name" required class="block w-full px-3 py-2.5 border border-slate-200 rounded-xl text-xs" placeholder="Contoh: Linda Permata">
            </div>

            <div class="grid grid-cols-2 gap-4">
                <!-- Phone -->
                <div>
                    <label for="mbr-phone" class="block text-xs font-semibold text-slate-500 uppercase tracking-wider mb-1.5">Nomor Telepon / WA</label>
                    <input type="text" name="phone" id="mbr-phone" class="block w-full px-3 py-2.5 border border-slate-200 rounded-xl text-xs font-mono" placeholder="0812...">
                </div>

                <!-- Email -->
                <div>
                    <label for="mbr-email" class="block text-xs font-semibold text-slate-500 uppercase tracking-wider mb-1.5">Alamat Email</label>
                    <input type="email" name="email" id="mbr-email" class="block w-full px-3 py-2.5 border border-slate-200 rounded-xl text-xs font-mono" placeholder="linda@gmail.com">
                </div>
            </div>

            <div class="grid grid-cols-2 gap-4">
                <!-- Loyalty Points -->
                <div>
                    <label for="mbr-points" class="block text-xs font-semibold text-slate-500 uppercase tracking-wider mb-1.5">Saldo Koin Poin</label>
                    <input type="number" name="points" id="mbr-points" class="block w-full px-3 py-2.5 border border-slate-200 rounded-xl text-xs font-mono" placeholder="0" value="0">
                </div>

                <!-- Status -->
                <div>
                    <label for="mbr-status" class="block text-xs font-semibold text-slate-500 uppercase tracking-wider mb-1.5">Status Keaktifan</label>
                    <select name="status" id="mbr-status" class="block w-full px-3 py-2.5 border border-slate-200 rounded-xl text-xs bg-slate-50">
                        <option value="ACTIVE">Aktif (Bisa Transaksi)</option>
                        <option value="INACTIVE">Non-Aktif (Ditangguhkan)</option>
                    </select>
                </div>
            </div>

            <!-- Address -->
            <div>
                <label for="mbr-address" class="block text-xs font-semibold text-slate-500 uppercase tracking-wider mb-1.5">Alamat Lengkap</label>
                <textarea name="address" id="mbr-address" rows="2" class="block w-full px-3 py-2.5 border border-slate-200 rounded-xl text-xs" placeholder="Alamat rumah member..."></textarea>
            </div>

            <div class="grid grid-cols-2 gap-4 pt-3 border-t border-slate-100">
                <button type="button" onclick="closeMbrModal()" class="cursor-pointer py-2.5 border border-slate-200 text-slate-700 font-semibold rounded-xl text-xs text-center hover:bg-slate-50 transition-all">Batal</button>
                <button type="submit" class="cursor-pointer py-2.5 bg-emerald-600 hover:bg-emerald-700 text-white font-semibold rounded-xl text-xs text-center shadow-md transition-all">Daftarkan</button>
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
        <h3 class="text-sm font-bold text-slate-900 mb-1">Hapus / Nonaktifkan Member?</h3>
        <p class="text-slate-500 text-xs mb-5" id="delete-alert-text">Apakah Anda yakin ingin menghapus data member ini?</p>
        
        <form action="/admin/customers.php" method="POST" class="grid grid-cols-2 gap-3">
            <input type="hidden" name="csrf_token" value="<?= csrf_token() ?>">
            <input type="hidden" name="action" value="delete">
            <input type="hidden" name="id" id="delete-id" value="">
            
            <button type="button" onclick="closeDeleteModal()" class="cursor-pointer py-2 border border-slate-200 text-slate-700 font-semibold rounded-xl text-xs hover:bg-slate-50 transition-all">Batal</button>
            <button type="submit" class="cursor-pointer py-2 bg-red-600 hover:bg-red-700 text-white font-semibold rounded-xl text-xs shadow-md transition-all">Hapus</button>
        </form>
    </div>
</div>

<script>
    function openMbrModal(mbr = null) {
        document.getElementById('mbr-id').value = '';
        document.getElementById('mbr-code').value = '';
        document.getElementById('mbr-lvl').value = 'BRONZE';
        document.getElementById('mbr-name').value = '';
        document.getElementById('mbr-phone').value = '';
        document.getElementById('mbr-email').value = '';
        document.getElementById('mbr-points').value = '0';
        document.getElementById('mbr-status').value = 'ACTIVE';
        document.getElementById('mbr-address').value = '';
        const title = document.getElementById('modal-title');

        if (mbr) {
            title.textContent = "Edit Detail Member";
            document.getElementById('mbr-id').value = mbr.id;
            document.getElementById('mbr-code').value = mbr.member_id;
            document.getElementById('mbr-lvl').value = mbr.level;
            document.getElementById('mbr-name').value = mbr.name;
            document.getElementById('mbr-phone').value = mbr.phone;
            document.getElementById('mbr-email').value = mbr.email;
            document.getElementById('mbr-points').value = mbr.points;
            document.getElementById('mbr-status').value = mbr.status;
            document.getElementById('mbr-address').value = mbr.address;
        } else {
            title.textContent = "Daftarkan Member Baru";
            generateRandomMemberId();
        }

        document.getElementById('mbr-modal').classList.remove('hidden');
    }

    function closeMbrModal() {
        document.getElementById('mbr-modal').classList.add('hidden');
    }

    function confirmDelete(id, name) {
        document.getElementById('delete-id').value = id;
        document.getElementById('delete-alert-text').innerHTML = `Apakah Anda yakin ingin menghapus member <strong>"${name}"</strong> dari database?<br><span class="text-[10px] text-red-500">Catatan: Jika member sudah memiliki riwayat belanja, status member hanya akan dinonaktifkan demi keutuhan data keuangan.</span>`;
        document.getElementById('delete-modal').classList.remove('hidden');
    }

    function closeDeleteModal() {
        document.getElementById('delete-modal').classList.add('hidden');
    }

    function generateRandomMemberId() {
        let code = "MBR-" + Math.floor(1000 + Math.random() * 9000);
        document.getElementById('mbr-code').value = code;
    }
</script>

<?php include __DIR__ . '/../includes/footer.php'; ?>
