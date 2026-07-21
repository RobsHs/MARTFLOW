<?php
// /admin/categories.php
// MartFlow POS - Category Management

require_once __DIR__ . '/../includes/functions.php';
require_role(['ADMIN', 'MANAGER']);

$page_title = 'Kelola Kategori';
$db = Database::getConnection();

$error = '';
$success = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = $_POST['action'] ?? '';
    $token = $_POST['csrf_token'] ?? '';

    if (!verify_csrf_token($token)) {
        $error = 'Validasi keamanan CSRF gagal. Silakan coba lagi.';
    } else {
        // --- ADD OR EDIT CATEGORY ---
        if ($action === 'save') {
            $id = isset($_POST['id']) && !empty($_POST['id']) ? intval($_POST['id']) : null;
            $name = trim($_POST['name'] ?? '');
            $description = trim($_POST['description'] ?? '');

            if (empty($name)) {
                $error = 'Nama kategori wajib diisi!';
            } else {
                try {
                    // Check duplicate name
                    $name_check = $db->prepare("SELECT id FROM categories WHERE name = :name AND id != :id");
                    $name_check->execute(['name' => $name, 'id' => $id ?? 0]);

                    if ($name_check->fetch()) {
                        $error = "Gagal menyimpan: Kategori '$name' sudah terdaftar.";
                    } else {
                        if ($id) {
                            $stmt = $db->prepare("UPDATE categories SET name = :name, description = :description WHERE id = :id");
                            $stmt->execute(['name' => $name, 'description' => $description, 'id' => $id]);
                            $success = "Kategori '$name' berhasil diperbarui.";
                            write_log("Updated product category (ID: $id) - Name: $name");
                        } else {
                            $stmt = $db->prepare("INSERT INTO categories (name, description) VALUES (:name, :description)");
                            $stmt->execute(['name' => $name, 'description' => $description]);
                            $success = "Kategori '$name' berhasil ditambahkan.";
                            write_log("Created new product category: $name");
                        }
                    }
                } catch (Exception $e) {
                    $error = 'Database error: ' . $e->getMessage();
                }
            }
        }

        // --- DELETE CATEGORY ---
        if ($action === 'delete') {
            $id = intval($_POST['id'] ?? 0);
            try {
                // Check if any product is using this category
                $prod_check = $db->prepare("SELECT COUNT(*) FROM products WHERE category_id = ?");
                $prod_check->execute([$id]);
                $count = intval($prod_check->fetchColumn());

                if ($count > 0) {
                    $error = "Gagal menghapus: Kategori sedang digunakan oleh $count produk. Silakan ubah kategori produk terlebih dahulu.";
                } else {
                    $n_stmt = $db->prepare("SELECT name FROM categories WHERE id = ?");
                    $n_stmt->execute([$id]);
                    $cat_name = $n_stmt->fetchColumn();

                    $db->prepare("DELETE FROM categories WHERE id = ?")->execute([$id]);
                    $success = "Kategori '$cat_name' berhasil dihapus.";
                    write_log("Deleted category ID: $id (Name: $cat_name)");
                }
            } catch (Exception $e) {
                $error = 'Gagal menghapus kategori: ' . $e->getMessage();
            }
        }
    }
}

// Fetch all categories
$categories = $db->query("
    SELECT c.*, COUNT(p.id) as product_count 
    FROM categories c
    LEFT JOIN products p ON c.id = p.category_id
    GROUP BY c.id
    ORDER BY c.name ASC
")->fetchAll();

include __DIR__ . '/../includes/header.php';
include __DIR__ . '/../includes/sidebar.php';
?>

<!-- Main Content Area -->
<main class="flex-1 overflow-y-auto bg-slate-50 p-6 md:p-8">
    <!-- Page Header -->
    <div class="flex flex-col md:flex-row md:items-center md:justify-between mb-8 space-y-4 md:space-y-0">
        <div>
            <h2 class="text-2xl font-bold text-slate-900 tracking-tight">Kategori Produk</h2>
            <p class="text-sm text-slate-500 mt-1">Kelompokkan produk minimarket ke dalam kategori yang terorganisir.</p>
        </div>
        <button onclick="openCatModal()" class="cursor-pointer inline-flex items-center px-4.5 py-2.5 bg-emerald-600 hover:bg-emerald-700 text-sm font-semibold text-white rounded-xl shadow-lg shadow-emerald-600/20 transition-all">
            <i data-lucide="plus-circle" class="w-4.5 h-4.5 mr-2"></i>
            Tambah Kategori Baru
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
    <div class="bg-white border border-slate-200 rounded-2xl shadow-sm overflow-hidden max-w-4xl">
        <div class="overflow-x-auto">
            <table class="w-full text-left text-sm border-collapse">
                <thead>
                    <tr class="bg-slate-50 text-slate-600 font-mono text-xs uppercase border-b border-slate-200">
                        <th class="px-6 py-4 font-semibold w-12">No</th>
                        <th class="px-6 py-4 font-semibold">Nama Kategori</th>
                        <th class="px-6 py-4 font-semibold">Deskripsi</th>
                        <th class="px-6 py-4 font-semibold text-center">Jumlah SKU</th>
                        <th class="px-6 py-4 text-right">Aksi</th>
                    </tr>
                </thead>
                <tbody class="divide-y divide-slate-100">
                    <?php if (empty($categories)): ?>
                        <tr>
                            <td colspan="5" class="text-center py-12 text-slate-400">
                                <div class="flex flex-col items-center justify-center">
                                    <i data-lucide="tag" class="w-10 h-10 text-slate-300 mb-2"></i>
                                    <p class="text-sm font-semibold">Belum ada kategori terdaftar</p>
                                </div>
                            </td>
                        </tr>
                    <?php else: $no = 1; ?>
                        <?php foreach ($categories as $cat): ?>
                            <tr class="hover:bg-slate-50/50 transition-colors">
                                <td class="px-6 py-4 font-mono text-slate-400 text-xs">
                                    <?= $no++ ?>
                                </td>
                                <td class="px-6 py-4 font-bold text-slate-900">
                                    <?= esc($cat['name']) ?>
                                </td>
                                <td class="px-6 py-4 text-slate-600 text-xs">
                                    <?= esc($cat['description'] ?: '-') ?>
                                </td>
                                <td class="px-6 py-4 text-center">
                                    <span class="inline-block px-2.5 py-1 text-[10px] font-bold rounded bg-slate-100 text-slate-700 border border-slate-200">
                                        <?= $cat['product_count'] ?> SKU
                                    </span>
                                </td>
                                <td class="px-6 py-4 text-right">
                                    <div class="inline-flex items-center space-x-2">
                                        <button onclick="openCatModal(<?= htmlspecialchars(json_encode($cat)) ?>)" class="cursor-pointer p-1.5 bg-slate-100 hover:bg-slate-200 text-slate-600 hover:text-slate-900 rounded-lg transition-all" title="Edit">
                                            <i data-lucide="edit-3" class="w-4 h-4"></i>
                                        </button>
                                        <button onclick="confirmDelete(<?= $cat['id'] ?>, '<?= esc($cat['name']) ?>')" class="cursor-pointer p-1.5 bg-red-50 hover:bg-red-100 text-red-500 hover:text-red-700 rounded-lg transition-all" title="Delete">
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
<!-- MODAL: ADD / EDIT CATEGORY -->
<!-- ================================================== -->
<div id="cat-modal" class="hidden fixed inset-0 bg-slate-950/70 backdrop-blur-sm z-50 flex items-center justify-center p-4">
    <div class="bg-white rounded-3xl shadow-2xl border border-slate-200 max-w-md w-full overflow-hidden flex flex-col transform transition-all animate-scaleIn">
        <div class="px-6 py-5 border-b border-slate-100 flex items-center justify-between bg-slate-50">
            <h3 class="font-extrabold text-slate-800" id="modal-title">Tambah Kategori</h3>
            <button onclick="closeCatModal()" class="text-slate-400 hover:text-slate-600 cursor-pointer">
                <i data-lucide="x" class="w-6 h-6"></i>
            </button>
        </div>

        <form action="/admin/categories.php" method="POST" class="p-6 space-y-4">
            <input type="hidden" name="csrf_token" value="<?= csrf_token() ?>">
            <input type="hidden" name="action" value="save">
            <input type="hidden" name="id" id="cat-id" value="">

            <div>
                <label for="cat-name" class="block text-xs font-semibold text-slate-500 uppercase tracking-wider mb-1.5">Nama Kategori *</label>
                <input type="text" name="name" id="cat-name" required class="block w-full px-3 py-2.5 border border-slate-200 rounded-xl text-xs" placeholder="Contoh: Makanan Instan, Deterjen, dll.">
            </div>

            <div>
                <label for="cat-desc" class="block text-xs font-semibold text-slate-500 uppercase tracking-wider mb-1.5">Deskripsi Singkat</label>
                <textarea name="description" id="cat-desc" rows="3" class="block w-full px-3 py-2.5 border border-slate-200 rounded-xl text-xs" placeholder="Keterangan singkat mengenai kategori ini..."></textarea>
            </div>

            <div class="grid grid-cols-2 gap-4 pt-3 border-t border-slate-100">
                <button type="button" onclick="closeCatModal()" class="cursor-pointer py-2.5 border border-slate-200 text-slate-700 font-semibold rounded-xl text-xs text-center hover:bg-slate-50 transition-all">Batal</button>
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
        <h3 class="text-sm font-bold text-slate-900 mb-1">Hapus Kategori?</h3>
        <p class="text-slate-500 text-xs mb-5" id="delete-alert-text">Apakah Anda yakin ingin menghapus kategori ini?</p>
        
        <form action="/admin/categories.php" method="POST" class="grid grid-cols-2 gap-3">
            <input type="hidden" name="csrf_token" value="<?= csrf_token() ?>">
            <input type="hidden" name="action" value="delete">
            <input type="hidden" name="id" id="delete-id" value="">
            
            <button type="button" onclick="closeDeleteModal()" class="cursor-pointer py-2 border border-slate-200 text-slate-700 font-semibold rounded-xl text-xs hover:bg-slate-50 transition-all">Batal</button>
            <button type="submit" class="cursor-pointer py-2 bg-red-600 hover:bg-red-700 text-white font-semibold rounded-xl text-xs shadow-md transition-all">Hapus</button>
        </form>
    </div>
</div>

<script>
    function openCatModal(cat = null) {
        document.getElementById('cat-id').value = '';
        document.getElementById('cat-name').value = '';
        document.getElementById('cat-desc').value = '';
        const title = document.getElementById('modal-title');

        if (cat) {
            title.textContent = "Edit Kategori";
            document.getElementById('cat-id').value = cat.id;
            document.getElementById('cat-name').value = cat.name;
            document.getElementById('cat-desc').value = cat.description;
        } else {
            title.textContent = "Tambah Kategori Baru";
        }

        document.getElementById('cat-modal').classList.remove('hidden');
    }

    function closeCatModal() {
        document.getElementById('cat-modal').classList.add('hidden');
    }

    function confirmDelete(id, name) {
        document.getElementById('delete-id').value = id;
        document.getElementById('delete-alert-text').innerHTML = `Apakah Anda yakin ingin menghapus kategori <strong>"${name}"</strong> dari database?<br><span class="text-[10px] text-red-500">Tindakan ini tidak dapat dibatalkan.</span>`;
        document.getElementById('delete-modal').classList.remove('hidden');
    }

    function closeDeleteModal() {
        document.getElementById('delete-modal').classList.add('hidden');
    }
</script>

<?php include __DIR__ . '/../includes/footer.php'; ?>
