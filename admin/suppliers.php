<?php
// /admin/suppliers.php
// MartFlow POS - Supplier Management

require_once __DIR__ . '/../includes/functions.php';
require_role(['ADMIN', 'MANAGER']);

$page_title = 'Kelola Supplier';
$db = Database::getConnection();

$error = '';
$success = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = $_POST['action'] ?? '';
    $token = $_POST['csrf_token'] ?? '';

    if (!verify_csrf_token($token)) {
        $error = 'Validasi keamanan CSRF gagal. Silakan coba lagi.';
    } else {
        // --- ADD OR EDIT SUPPLIER ---
        if ($action === 'save') {
            $id = isset($_POST['id']) && !empty($_POST['id']) ? intval($_POST['id']) : null;
            $name = trim($_POST['name'] ?? '');
            $contact_name = trim($_POST['contact_name'] ?? '');
            $phone = trim($_POST['phone'] ?? '');
            $email = trim($_POST['email'] ?? '');
            $address = trim($_POST['address'] ?? '');

            if (empty($name)) {
                $error = 'Nama supplier wajib diisi!';
            } else {
                try {
                    if ($id) {
                        $stmt = $db->prepare("
                            UPDATE suppliers SET 
                                name = :name, contact_name = :contact_name, 
                                phone = :phone, email = :email, address = :address 
                            WHERE id = :id
                        ");
                        $stmt->execute([
                            'name' => $name, 'contact_name' => $contact_name,
                            'phone' => $phone, 'email' => $email, 'address' => $address,
                            'id' => $id
                        ]);
                        $success = "Supplier '$name' berhasil diperbarui.";
                        write_log("Updated supplier entry (ID: $id) - Name: $name");
                    } else {
                        $stmt = $db->prepare("
                            INSERT INTO suppliers (name, contact_name, phone, email, address) 
                            VALUES (:name, :contact_name, :phone, :email, :address)
                        ");
                        $stmt->execute([
                            'name' => $name, 'contact_name' => $contact_name,
                            'phone' => $phone, 'email' => $email, 'address' => $address
                        ]);
                        $success = "Supplier '$name' berhasil ditambahkan.";
                        write_log("Created new supplier entry: $name");
                    }
                } catch (Exception $e) {
                    $error = 'Database error: ' . $e->getMessage();
                }
            }
        }

        // --- DELETE SUPPLIER ---
        if ($action === 'delete') {
            $id = intval($_POST['id'] ?? 0);
            try {
                // Check if supplier is referenced in active products
                $prod_check = $db->prepare("SELECT COUNT(*) FROM products WHERE supplier_id = ?");
                $prod_check->execute([$id]);
                $count = intval($prod_check->fetchColumn());

                if ($count > 0) {
                    $error = "Gagal menghapus: Supplier sedang terhubung dengan $count produk. Harap ubah pemasok produk tersebut terlebih dahulu.";
                } else {
                    $n_stmt = $db->prepare("SELECT name FROM suppliers WHERE id = ?");
                    $n_stmt->execute([$id]);
                    $sup_name = $n_stmt->fetchColumn();

                    $db->prepare("DELETE FROM suppliers WHERE id = ?")->execute([$id]);
                    $success = "Supplier '$sup_name' berhasil dihapus.";
                    write_log("Deleted supplier ID: $id (Name: $sup_name)");
                }
            } catch (Exception $e) {
                $error = 'Gagal menghapus supplier: ' . $e->getMessage();
            }
        }
    }
}

// Fetch all suppliers
$suppliers = $db->query("
    SELECT s.*, COUNT(p.id) as product_count 
    FROM suppliers s
    LEFT JOIN products p ON s.id = p.supplier_id
    GROUP BY s.id
    ORDER BY s.name ASC
")->fetchAll();

include __DIR__ . '/../includes/header.php';
include __DIR__ . '/../includes/sidebar.php';
?>

<!-- Main Content Area -->
<main class="flex-1 overflow-y-auto bg-slate-50 p-6 md:p-8">
    <!-- Page Header -->
    <div class="flex flex-col md:flex-row md:items-center md:justify-between mb-8 space-y-4 md:space-y-0">
        <div>
            <h2 class="text-2xl font-bold text-slate-900 tracking-tight">Daftar Supplier Pemasok</h2>
            <p class="text-sm text-slate-500 mt-1">Kelola data mitra distributor dan produsen penyuplai stok minimarket.</p>
        </div>
        <button onclick="openSupModal()" class="cursor-pointer inline-flex items-center px-4.5 py-2.5 bg-emerald-600 hover:bg-emerald-700 text-sm font-semibold text-white rounded-xl shadow-lg shadow-emerald-600/20 transition-all">
            <i data-lucide="plus-circle" class="w-4.5 h-4.5 mr-2"></i>
            Tambah Supplier Baru
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
    <div class="bg-white border border-slate-200 rounded-2xl shadow-sm overflow-hidden">
        <div class="overflow-x-auto">
            <table class="w-full text-left text-sm border-collapse">
                <thead>
                    <tr class="bg-slate-50 text-slate-600 font-mono text-xs uppercase border-b border-slate-200">
                        <th class="px-6 py-4 font-semibold">Nama Supplier</th>
                        <th class="px-6 py-4 font-semibold">PIC / Narahubung</th>
                        <th class="px-6 py-4 font-semibold">No Telepon</th>
                        <th class="px-6 py-4 font-semibold">Email</th>
                        <th class="px-6 py-4 font-semibold">Alamat Kantor</th>
                        <th class="px-6 py-4 font-semibold text-center">Produk Disuplai</th>
                        <th class="px-6 py-4 text-right">Aksi</th>
                    </tr>
                </thead>
                <tbody class="divide-y divide-slate-100">
                    <?php if (empty($suppliers)): ?>
                        <tr>
                            <td colspan="7" class="text-center py-12 text-slate-400">
                                <div class="flex flex-col items-center justify-center">
                                    <i data-lucide="truck" class="w-10 h-10 text-slate-300 mb-2"></i>
                                    <p class="text-sm font-semibold">Belum ada supplier terdaftar</p>
                                </div>
                            </td>
                        </tr>
                    <?php else: ?>
                        <?php foreach ($suppliers as $sup): ?>
                            <tr class="hover:bg-slate-50/50 transition-colors text-xs">
                                <td class="px-6 py-4 font-bold text-slate-900 text-sm">
                                    <?= esc($sup['name']) ?>
                                </td>
                                <td class="px-6 py-4 text-slate-700">
                                    <?= esc($sup['contact_name'] ?: '-') ?>
                                </td>
                                <td class="px-6 py-4 font-mono text-slate-600">
                                    <?= esc($sup['phone'] ?: '-') ?>
                                </td>
                                <td class="px-6 py-4 text-slate-600">
                                    <?= esc($sup['email'] ?: '-') ?>
                                </td>
                                <td class="px-6 py-4 text-slate-500 truncate max-w-xs">
                                    <?= esc($sup['address'] ?: '-') ?>
                                </td>
                                <td class="px-6 py-4 text-center">
                                    <span class="inline-block px-2 py-0.5 rounded text-[10px] font-bold bg-slate-100 border border-slate-200 text-slate-700">
                                        <?= $sup['product_count'] ?> Barang
                                    </span>
                                </td>
                                <td class="px-6 py-4 text-right">
                                    <div class="inline-flex items-center space-x-2">
                                        <button onclick="openSupModal(<?= htmlspecialchars(json_encode($sup)) ?>)" class="cursor-pointer p-1.5 bg-slate-100 hover:bg-slate-200 text-slate-600 hover:text-slate-900 rounded-lg transition-all" title="Edit">
                                            <i data-lucide="edit-3" class="w-4 h-4"></i>
                                        </button>
                                        <button onclick="confirmDelete(<?= $sup['id'] ?>, '<?= esc($sup['name']) ?>')" class="cursor-pointer p-1.5 bg-red-50 hover:bg-red-100 text-red-500 hover:text-red-700 rounded-lg transition-all" title="Delete">
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
    </div>
</main>

<!-- ================================================== -->
<!-- MODAL: ADD / EDIT SUPPLIER -->
<!-- ================================================== -->
<div id="sup-modal" class="hidden fixed inset-0 bg-slate-950/70 backdrop-blur-sm z-50 flex items-center justify-center p-4">
    <div class="bg-white rounded-3xl shadow-2xl border border-slate-200 max-w-md w-full overflow-hidden flex flex-col transform transition-all animate-scaleIn">
        <div class="px-6 py-5 border-b border-slate-100 flex items-center justify-between bg-slate-50">
            <h3 class="font-extrabold text-slate-800" id="modal-title">Tambah Supplier</h3>
            <button onclick="closeSupModal()" class="text-slate-400 hover:text-slate-600 cursor-pointer">
                <i data-lucide="x" class="w-6 h-6"></i>
            </button>
        </div>

        <form action="/admin/suppliers.php" method="POST" class="p-6 space-y-4">
            <input type="hidden" name="csrf_token" value="<?= csrf_token() ?>">
            <input type="hidden" name="action" value="save">
            <input type="hidden" name="id" id="sup-id" value="">

            <div>
                <label for="sup-name" class="block text-xs font-semibold text-slate-500 uppercase tracking-wider mb-1.5">Nama Perusahaan / Supplier *</label>
                <input type="text" name="name" id="sup-name" required class="block w-full px-3 py-2.5 border border-slate-200 rounded-xl text-xs" placeholder="Contoh: PT. Sumber Alfaria Tbk.">
            </div>

            <div>
                <label for="sup-pic" class="block text-xs font-semibold text-slate-500 uppercase tracking-wider mb-1.5">Nama PIC / Narahubung</label>
                <input type="text" name="contact_name" id="sup-pic" class="block w-full px-3 py-2.5 border border-slate-200 rounded-xl text-xs" placeholder="Contoh: Budi Santoso">
            </div>

            <div class="grid grid-cols-2 gap-4">
                <div>
                    <label for="sup-phone" class="block text-xs font-semibold text-slate-500 uppercase tracking-wider mb-1.5">No Telepon / WA</label>
                    <input type="text" name="phone" id="sup-phone" class="block w-full px-3 py-2.5 border border-slate-200 rounded-xl text-xs font-mono" placeholder="0812345678">
                </div>
                <div>
                    <label for="sup-email" class="block text-xs font-semibold text-slate-500 uppercase tracking-wider mb-1.5">Email Perusahaan</label>
                    <input type="email" name="email" id="sup-email" class="block w-full px-3 py-2.5 border border-slate-200 rounded-xl text-xs font-mono" placeholder="sales@perusahaan.com">
                </div>
            </div>

            <div>
                <label for="sup-address" class="block text-xs font-semibold text-slate-500 uppercase tracking-wider mb-1.5">Alamat Kantor</label>
                <textarea name="address" id="sup-address" rows="3" class="block w-full px-3 py-2.5 border border-slate-200 rounded-xl text-xs" placeholder="Alamat lengkap distributor..."></textarea>
            </div>

            <div class="grid grid-cols-2 gap-4 pt-3 border-t border-slate-100">
                <button type="button" onclick="closeSupModal()" class="cursor-pointer py-2.5 border border-slate-200 text-slate-700 font-semibold rounded-xl text-xs text-center hover:bg-slate-50 transition-all">Batal</button>
                <button type="submit" class="cursor-pointer py-2.5 bg-emerald-600 hover:bg-emerald-700 text-white font-semibold rounded-xl text-xs text-center shadow-md transition-all">Simpan</button>
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
        <h3 class="text-sm font-bold text-slate-900 mb-1">Hapus Supplier?</h3>
        <p class="text-slate-500 text-xs mb-5" id="delete-alert-text">Apakah Anda yakin ingin menghapus supplier ini?</p>
        
        <form action="/admin/suppliers.php" method="POST" class="grid grid-cols-2 gap-3">
            <input type="hidden" name="csrf_token" value="<?= csrf_token() ?>">
            <input type="hidden" name="action" value="delete">
            <input type="hidden" name="id" id="delete-id" value="">
            
            <button type="button" onclick="closeDeleteModal()" class="cursor-pointer py-2 border border-slate-200 text-slate-700 font-semibold rounded-xl text-xs hover:bg-slate-50 transition-all">Batal</button>
            <button type="submit" class="cursor-pointer py-2 bg-red-600 hover:bg-red-700 text-white font-semibold rounded-xl text-xs shadow-md transition-all">Hapus</button>
        </form>
    </div>
</div>

<script>
    function openSupModal(sup = null) {
        document.getElementById('sup-id').value = '';
        document.getElementById('sup-name').value = '';
        document.getElementById('sup-pic').value = '';
        document.getElementById('sup-phone').value = '';
        document.getElementById('sup-email').value = '';
        document.getElementById('sup-address').value = '';
        const title = document.getElementById('modal-title');

        if (sup) {
            title.textContent = "Edit Supplier";
            document.getElementById('sup-id').value = sup.id;
            document.getElementById('sup-name').value = sup.name;
            document.getElementById('sup-pic').value = sup.contact_name;
            document.getElementById('sup-phone').value = sup.phone;
            document.getElementById('sup-email').value = sup.email;
            document.getElementById('sup-address').value = sup.address;
        } else {
            title.textContent = "Tambah Supplier Baru";
        }

        document.getElementById('sup-modal').classList.remove('hidden');
    }

    function closeSupModal() {
        document.getElementById('sup-modal').classList.add('hidden');
    }

    function confirmDelete(id, name) {
        document.getElementById('delete-id').value = id;
        document.getElementById('delete-alert-text').innerHTML = `Apakah Anda yakin ingin menghapus supplier <strong>"${name}"</strong> dari database?<br><span class="text-[10px] text-red-500">Tindakan ini tidak dapat dibatalkan.</span>`;
        document.getElementById('delete-modal').classList.remove('hidden');
    }

    function closeDeleteModal() {
        document.getElementById('delete-modal').classList.add('hidden');
    }
</script>

<?php include __DIR__ . '/../includes/footer.php'; ?>
