<?php
// /admin/promotions.php
// MartFlow POS - Promotions & Discounts Manager

require_once __DIR__ . '/../includes/functions.php';
require_role(['ADMIN', 'MANAGER']);

$page_title = 'Kelola Promo';
$db = Database::getConnection();

$error = '';
$success = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = $_POST['action'] ?? '';
    $token = $_POST['csrf_token'] ?? '';

    if (!verify_csrf_token($token)) {
        $error = 'Validasi keamanan CSRF gagal. Silakan coba lagi.';
    } else {
        // --- ADD OR EDIT PROMOTION ---
        if ($action === 'save') {
            $id = isset($_POST['id']) && !empty($_POST['id']) ? intval($_POST['id']) : null;
            $name = trim($_POST['name'] ?? '');
            $type = trim($_POST['type'] ?? 'PERCENTAGE');
            $value = floatval($_POST['value'] ?? 0);
            $start_date = trim($_POST['start_date'] ?? '');
            $end_date = trim($_POST['end_date'] ?? '');
            $active = isset($_POST['active']) ? intval($_POST['active']) : 1;
            
            // Apply targets
            $target_type = trim($_POST['target_type'] ?? 'ALL');
            $category_id = null;
            $product_id = null;

            if ($target_type === 'CATEGORY') {
                $category_id = !empty($_POST['category_id']) ? intval($_POST['category_id']) : null;
            } else if ($target_type === 'PRODUCT') {
                $product_id = !empty($_POST['product_id']) ? intval($_POST['product_id']) : null;
            }

            // Validations
            if (empty($name) || empty($start_date) || empty($end_date)) {
                $error = 'Nama promo, tanggal mulai, dan tanggal selesai wajib diisi!';
            } else if (strtotime($end_date) < strtotime($start_date)) {
                $error = 'Tanggal selesai tidak boleh mendahului tanggal mulai!';
            } else if (($type === 'PERCENTAGE' && ($value <= 0 || $value > 100))) {
                $error = 'Nilai diskon persentase harus berada di antara 1% - 100%!';
            } else if (($type === 'NOMINAL' && $value <= 0)) {
                $error = 'Nilai diskon nominal harus lebih dari Rp 0!';
            } else {
                try {
                    if ($id) {
                        $stmt = $db->prepare("
                            UPDATE promotions SET 
                                name = :name, type = :type, value = :value, 
                                start_date = :start_date, end_date = :end_date, 
                                active = :active, category_id = :category_id, product_id = :product_id
                            WHERE id = :id
                        ");
                        $stmt->execute([
                            'name' => $name, 'type' => $type, 'value' => $value,
                            'start_date' => $start_date, 'end_date' => $end_date,
                            'active' => $active, 'category_id' => $category_id, 'product_id' => $product_id,
                            'id' => $id
                        ]);
                        $success = "Promosi '" . esc($name) . "' berhasil diperbarui.";
                        write_log("Updated promotion rule (ID: $id) - Name: $name");
                    } else {
                        $stmt = $db->prepare("
                            INSERT INTO promotions (name, type, value, start_date, end_date, active, category_id, product_id) 
                            VALUES (:name, :type, :value, :start_date, :end_date, :active, :category_id, :product_id)
                        ");
                        $stmt->execute([
                            'name' => $name, 'type' => $type, 'value' => $value,
                            'start_date' => $start_date, 'end_date' => $end_date,
                            'active' => $active, 'category_id' => $category_id, 'product_id' => $product_id
                        ]);
                        $success = "Promosi '" . esc($name) . "' berhasil didaftarkan.";
                        write_log("Created promotion rule: $name (Type: $type)");
                    }
                } catch (Exception $e) {
                    $error = 'Database error: ' . $e->getMessage();
                }
            }
        }

        // --- TOGGLE PROMO STATUS DIRECTLY ---
        if ($action === 'toggle') {
            $id = intval($_POST['id'] ?? 0);
            $active = intval($_POST['active'] ?? 0);
            try {
                $db->prepare("UPDATE promotions SET active = ? WHERE id = ?")->execute([$active, $id]);
                $success = "Status promo berhasil diubah.";
                write_log("Toggled active state of promo ID: $id to $active");
            } catch (Exception $e) {
                $error = 'Database error: ' . $e->getMessage();
            }
        }

        // --- DELETE PROMOTION RULE ---
        if ($action === 'delete') {
            $id = intval($_POST['id'] ?? 0);
            try {
                $db->prepare("DELETE FROM promotions WHERE id = ?")->execute([$id]);
                $success = "Aturan promosi berhasil dihapus.";
                write_log("Deleted promotion rule ID: $id");
            } catch (Exception $e) {
                $error = 'Gagal menghapus promosi: ' . $e->getMessage();
            }
        }
    }
}

// Fetch promotions list
$promotions = $db->query("
    SELECT pr.*, p.name as product_name, p.sku as product_sku, c.name as category_name
    FROM promotions pr
    LEFT JOIN products p ON pr.product_id = p.id
    LEFT JOIN categories c ON pr.category_id = c.id
    ORDER BY pr.active DESC, pr.end_date DESC
")->fetchAll();

// Auxiliary dropdown datasets
$categories = $db->query("SELECT id, name FROM categories ORDER BY name ASC")->fetchAll();
$products = $db->query("SELECT id, name, sku FROM products WHERE status = 'ACTIVE' ORDER BY name ASC")->fetchAll();

include __DIR__ . '/../includes/header.php';
include __DIR__ . '/../includes/sidebar.php';
?>

<!-- Main Content Area -->
<main class="flex-1 overflow-y-auto bg-slate-50 p-6 md:p-8">
    <!-- Page Header -->
    <div class="flex flex-col md:flex-row md:items-center md:justify-between mb-8 space-y-4 md:space-y-0">
        <div>
            <h2 class="text-2xl font-bold text-slate-900 tracking-tight">Aturan Promo & Diskon</h2>
            <p class="text-sm text-slate-500 mt-1">Kelola diskon berkala, kustomisasi diskon per kategori, dan promo Beli 1 Gratis 1.</p>
        </div>
        <button onclick="openPromoModal()" class="cursor-pointer inline-flex items-center px-4.5 py-2.5 bg-emerald-600 hover:bg-emerald-700 text-sm font-semibold text-white rounded-xl shadow-lg shadow-emerald-600/20 transition-all">
            <i data-lucide="sparkles" class="w-4.5 h-4.5 mr-2"></i>
            Buat Aturan Promo Baru
        </button>
    </div>

    <!-- Feedback Banners -->
    <?php if (!empty($error)): ?>
        <div class="mb-6 bg-red-50 border border-red-200 rounded-xl p-4 flex items-start space-x-3 text-sm text-red-700">
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
                        <th class="px-6 py-4 font-semibold">Nama Kampanye Promo</th>
                        <th class="px-6 py-4 font-semibold">Tipe Promo</th>
                        <th class="px-6 py-4 font-semibold text-right">Nilai</th>
                        <th class="px-6 py-4 font-semibold">Terapkan Ke</th>
                        <th class="px-6 py-4 font-semibold">Masa Aktif</th>
                        <th class="px-6 py-4 text-center">Status</th>
                        <th class="px-6 py-4 text-right">Aksi</th>
                    </tr>
                </thead>
                <tbody class="divide-y divide-slate-100">
                    <?php if (empty($promotions)): ?>
                        <tr>
                            <td colspan="7" class="text-center py-12 text-slate-400">
                                <div class="flex flex-col items-center justify-center">
                                    <i data-lucide="ticket" class="w-10 h-10 text-slate-300 mb-2"></i>
                                    <p class="text-sm font-semibold">Belum ada promo terdaftar</p>
                                </div>
                            </td>
                        </tr>
                    <?php else: ?>
                        <?php foreach ($promotions as $pr): ?>
                            <?php 
                            $today = date('Y-m-d');
                            $is_expired = $today > $pr['end_date'];
                            $is_upcoming = $today < $pr['start_date'];
                            
                            $status_text = 'Aktif';
                            $status_class = 'bg-green-100 text-green-800 border-green-200';
                            
                            if (!$pr['active']) {
                                $status_text = 'Non-Aktif';
                                $status_class = 'bg-slate-100 text-slate-500 border-slate-200';
                            } else if ($is_expired) {
                                $status_text = 'Kedaluwarsa';
                                $status_class = 'bg-red-50 text-red-600 border-red-100';
                            } else if ($is_upcoming) {
                                $status_text = 'Terjadwal';
                                $status_class = 'bg-blue-50 text-blue-600 border-blue-100';
                            }
                            ?>
                            <tr class="hover:bg-slate-50/50 transition-colors text-xs">
                                <td class="px-6 py-4">
                                    <p class="font-bold text-slate-900 text-sm"><?= esc($pr['name']) ?></p>
                                </td>
                                <td class="px-6 py-4 font-semibold text-slate-700">
                                    <?php 
                                    if ($pr['type'] === 'PERCENTAGE') echo 'Potongan Persen (%)';
                                    else if ($pr['type'] === 'NOMINAL') echo 'Potongan Harga (Rp)';
                                    else if ($pr['type'] === 'BUY_1_GET_1') echo 'Beli 1 Gratis 1 (B1G1)';
                                    else if ($pr['type'] === 'BUY_2_GET_1') echo 'Beli 2 Gratis 1 (B2G1)';
                                    ?>
                                </td>
                                <td class="px-6 py-4 text-right font-bold font-mono">
                                    <?php 
                                    if ($pr['type'] === 'PERCENTAGE') echo $pr['value'] . '%';
                                    else if ($pr['type'] === 'NOMINAL') echo format_rupiah($pr['value']);
                                    else echo '-';
                                    ?>
                                </td>
                                <td class="px-6 py-4 text-slate-600 font-medium">
                                    <?php 
                                    if ($pr['product_id']) {
                                        echo '<span class="inline-flex items-center text-[10px] bg-slate-100 border border-slate-200 px-2 py-0.5 rounded font-mono font-bold text-slate-800">SPESIFIK</span> <span class="ml-1">' . esc($pr['product_name']) . '</span>';
                                    } else if ($pr['category_id']) {
                                        echo '<span class="inline-flex items-center text-[10px] bg-amber-50 border border-amber-200 px-2 py-0.5 rounded font-bold text-amber-800">KATEGORI</span> <span class="ml-1">' . esc($pr['category_name']) . '</span>';
                                    } else {
                                        echo '<span class="inline-flex items-center text-[10px] bg-emerald-50 border border-emerald-200 px-2 py-0.5 rounded font-bold text-emerald-800">SEMUA PRODUK</span>';
                                    }
                                    ?>
                                </td>
                                <td class="px-6 py-4 font-mono text-slate-500 whitespace-nowrap">
                                    <?= date('d-m-Y', strtotime($pr['start_date'])) ?> s/d <?= date('d-m-Y', strtotime($pr['end_date'])) ?>
                                </td>
                                <td class="px-6 py-4 text-center">
                                    <span class="inline-flex items-center px-2.5 py-0.5 rounded-full text-[10px] font-bold border <?= $status_class ?>">
                                        <?= $status_text ?>
                                    </span>
                                </td>
                                <td class="px-6 py-4 text-right">
                                    <div class="inline-flex items-center space-x-2">
                                        <!-- Inline status toggle -->
                                        <form action="/admin/promotions.php" method="POST" class="inline">
                                            <input type="hidden" name="csrf_token" value="<?= csrf_token() ?>">
                                            <input type="hidden" name="action" value="toggle">
                                            <input type="hidden" name="id" value="<?= $pr['id'] ?>">
                                            <input type="hidden" name="active" value="<?= $pr['active'] ? 0 : 1 ?>">
                                            <button type="submit" class="cursor-pointer px-2 py-1 text-[10px] font-bold rounded-lg border transition-all <?= $pr['active'] ? 'bg-slate-100 text-slate-700 hover:bg-slate-200' : 'bg-emerald-50 text-emerald-700 border-emerald-200 hover:bg-emerald-100' ?>">
                                                <?= $pr['active'] ? 'Matikan' : 'Aktifkan' ?>
                                            </button>
                                        </form>

                                        <button onclick="openPromoModal(<?= htmlspecialchars(json_encode($pr)) ?>)" class="cursor-pointer p-1.5 bg-slate-100 hover:bg-slate-200 text-slate-600 hover:text-slate-900 rounded-lg transition-all" title="Edit">
                                            <i data-lucide="edit-3" class="w-4 h-4"></i>
                                        </button>
                                        <button onclick="confirmDelete(<?= $pr['id'] ?>, '<?= esc($pr['name']) ?>')" class="cursor-pointer p-1.5 bg-red-50 hover:bg-red-100 text-red-500 hover:text-red-700 rounded-lg transition-all" title="Delete">
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
<!-- MODAL: ADD / EDIT PROMOTION Kampanye -->
<!-- ================================================== -->
<div id="promo-modal" class="hidden fixed inset-0 bg-slate-950/70 backdrop-blur-sm z-50 flex items-center justify-center p-4">
    <div class="bg-white rounded-3xl shadow-2xl border border-slate-200 max-w-md w-full overflow-hidden flex flex-col transform transition-all animate-scaleIn">
        <div class="px-6 py-5 border-b border-slate-100 flex items-center justify-between bg-slate-50">
            <h3 class="font-extrabold text-slate-800" id="modal-title">Buat Aturan Promo</h3>
            <button onclick="closePromoModal()" class="text-slate-400 hover:text-slate-600 cursor-pointer">
                <i data-lucide="x" class="w-6 h-6"></i>
            </button>
        </div>

        <form action="/admin/promotions.php" method="POST" class="p-6 space-y-4">
            <input type="hidden" name="csrf_token" value="<?= csrf_token() ?>">
            <input type="hidden" name="action" value="save">
            <input type="hidden" name="id" id="promo-id" value="">

            <!-- Promo Name -->
            <div>
                <label for="prm-name" class="block text-xs font-semibold text-slate-500 uppercase tracking-wider mb-1.5">Nama Kampanye Promo *</label>
                <input type="text" name="name" id="prm-name" required class="block w-full px-3 py-2.5 border border-slate-200 rounded-xl text-xs" placeholder="Contoh: Diskon Harbolnas Indomie">
            </div>

            <!-- Promo Type & value -->
            <div class="grid grid-cols-2 gap-4">
                <div>
                    <label for="prm-type" class="block text-xs font-semibold text-slate-500 uppercase tracking-wider mb-1.5">Tipe Kampanye *</label>
                    <select name="type" id="prm-type" required onchange="togglePromoValueInput()" class="block w-full px-3 py-2.5 border border-slate-200 rounded-xl text-xs bg-slate-50">
                        <option value="PERCENTAGE">Potongan Persentase (%)</option>
                        <option value="NOMINAL">Potongan Nominal (IDR)</option>
                        <option value="BUY_1_GET_1">Beli 1 Gratis 1 (B1G1)</option>
                        <option value="BUY_2_GET_1">Beli 2 Gratis 1 (B2G1)</option>
                    </select>
                </div>
                <div id="promo-value-wrapper">
                    <label for="prm-val" class="block text-xs font-semibold text-slate-500 uppercase tracking-wider mb-1.5">Nilai Diskon *</label>
                    <input type="number" name="value" id="prm-val" class="block w-full px-3 py-2.5 border border-slate-200 rounded-xl text-xs font-mono" placeholder="10">
                </div>
            </div>

            <!-- Targets selector logic -->
            <div>
                <label class="block text-xs font-semibold text-slate-500 uppercase tracking-wider mb-1.5">Target Produk Promo</label>
                <div class="grid grid-cols-3 gap-2 text-center mb-3">
                    <label class="border border-slate-200 rounded-lg p-2 cursor-pointer hover:bg-slate-50 block">
                        <input type="radio" name="target_type" value="ALL" checked onchange="toggleTargetFields()" class="sr-only">
                        <span class="text-[10px] font-bold block" id="lbl-target-ALL">Semua Produk</span>
                    </label>
                    <label class="border border-slate-200 rounded-lg p-2 cursor-pointer hover:bg-slate-50 block">
                        <input type="radio" name="target_type" value="CATEGORY" onchange="toggleTargetFields()" class="sr-only">
                        <span class="text-[10px] font-bold block" id="lbl-target-CATEGORY">Kategori</span>
                    </label>
                    <label class="border border-slate-200 rounded-lg p-2 cursor-pointer hover:bg-slate-50 block">
                        <input type="radio" name="target_type" value="PRODUCT" onchange="toggleTargetFields()" class="sr-only">
                        <span class="text-[10px] font-bold block" id="lbl-target-PRODUCT">Produk</span>
                    </label>
                </div>

                <!-- Targeted Category dropdown -->
                <div id="target-category-field" class="hidden">
                    <label for="prm-cat" class="block text-[10px] font-semibold text-slate-400 uppercase tracking-widest mb-1.5">Pilih Kategori Sasaran</label>
                    <select name="category_id" id="prm-cat" class="block w-full px-3 py-2.5 border border-slate-200 rounded-xl text-xs bg-slate-50">
                        <option value="">-- Pilih Kategori --</option>
                        <?php foreach ($categories as $c): ?>
                            <option value="<?= $c['id'] ?>"><?= esc($c['name']) ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>

                <!-- Targeted Product dropdown -->
                <div id="target-product-field" class="hidden">
                    <label for="prm-prod" class="block text-[10px] font-semibold text-slate-400 uppercase tracking-widest mb-1.5">Pilih Produk Sasaran</label>
                    <select name="product_id" id="prm-prod" class="block w-full px-3 py-2.5 border border-slate-200 rounded-xl text-xs bg-slate-50">
                        <option value="">-- Pilih Produk --</option>
                        <?php foreach ($products as $p): ?>
                            <option value="<?= $p['id'] ?>"><?= esc($p['sku']) ?> - <?= esc($p['name']) ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
            </div>

            <!-- Date pickup boundaries -->
            <div class="grid grid-cols-2 gap-4">
                <div>
                    <label for="prm-start" class="block text-xs font-semibold text-slate-500 uppercase tracking-wider mb-1.5">Tanggal Mulai *</label>
                    <input type="date" name="start_date" id="prm-start" required class="block w-full px-3 py-2 border border-slate-200 rounded-xl text-xs font-mono">
                </div>
                <div>
                    <label for="prm-end" class="block text-xs font-semibold text-slate-500 uppercase tracking-wider mb-1.5">Tanggal Selesai *</label>
                    <input type="date" name="end_date" id="prm-end" required class="block w-full px-3 py-2 border border-slate-200 rounded-xl text-xs font-mono">
                </div>
            </div>

            <!-- Status active flag -->
            <div>
                <label for="prm-active" class="block text-xs font-semibold text-slate-500 uppercase tracking-wider mb-1.5">Status Kampanye</label>
                <select name="active" id="prm-active" class="block w-full px-3 py-2.5 border border-slate-200 rounded-xl text-xs bg-slate-50">
                    <option value="1">Aktif / Berjalan</option>
                    <option value="0">Non-Aktif / Tangguhkan</option>
                </select>
            </div>

            <div class="grid grid-cols-2 gap-4 pt-3 border-t border-slate-100">
                <button type="button" onclick="closePromoModal()" class="cursor-pointer py-2.5 border border-slate-200 text-slate-700 font-semibold rounded-xl text-xs text-center hover:bg-slate-50 transition-all">Batal</button>
                <button type="submit" class="cursor-pointer py-2.5 bg-emerald-600 hover:bg-emerald-700 text-white font-semibold rounded-xl text-xs text-center shadow-md transition-all">Simpan Promo</button>
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
        <h3 class="text-sm font-bold text-slate-900 mb-1">Hapus Aturan Promosi?</h3>
        <p class="text-slate-500 text-xs mb-5" id="delete-alert-text">Apakah Anda yakin ingin menghapus aturan promo ini?</p>
        
        <form action="/admin/promotions.php" method="POST" class="grid grid-cols-2 gap-3">
            <input type="hidden" name="csrf_token" value="<?= csrf_token() ?>">
            <input type="hidden" name="action" value="delete">
            <input type="hidden" name="id" id="delete-id" value="">
            
            <button type="button" onclick="closeDeleteModal()" class="cursor-pointer py-2 border border-slate-200 text-slate-700 font-semibold rounded-xl text-xs hover:bg-slate-50 transition-all">Batal</button>
            <button type="submit" class="cursor-pointer py-2 bg-red-600 hover:bg-red-700 text-white font-semibold rounded-xl text-xs shadow-md transition-all">Hapus</button>
        </form>
    </div>
</div>

<script>
    function openPromoModal(promo = null) {
        document.getElementById('promo-id').value = '';
        document.getElementById('prm-name').value = '';
        document.getElementById('prm-type').value = 'PERCENTAGE';
        document.getElementById('prm-val').value = '';
        document.getElementById('prm-start').value = '';
        document.getElementById('prm-end').value = '';
        document.getElementById('prm-active').value = '1';
        document.getElementById('prm-cat').value = '';
        document.getElementById('prm-prod').value = '';

        // Reset target radio checked
        setTargetRadioValue('ALL');

        const title = document.getElementById('modal-title');

        if (promo) {
            title.textContent = "Edit Aturan Promo";
            document.getElementById('promo-id').value = promo.id;
            document.getElementById('prm-name').value = promo.name;
            document.getElementById('prm-type').value = promo.type;
            document.getElementById('prm-val').value = Math.round(promo.value);
            document.getElementById('prm-start').value = promo.start_date;
            document.getElementById('prm-end').value = promo.end_date;
            document.getElementById('prm-active').value = promo.active;

            if (promo.product_id) {
                setTargetRadioValue('PRODUCT');
                document.getElementById('prm-prod').value = promo.product_id;
            } else if (promo.category_id) {
                setTargetRadioValue('CATEGORY');
                document.getElementById('prm-cat').value = promo.category_id;
            } else {
                setTargetRadioValue('ALL');
            }
        } else {
            title.textContent = "Buat Promo Kampanye Baru";
        }

        togglePromoValueInput();
        toggleTargetFields();
        document.getElementById('promo-modal').classList.remove('hidden');
    }

    function closePromoModal() {
        document.getElementById('promo-modal').classList.add('hidden');
    }

    function confirmDelete(id, name) {
        document.getElementById('delete-id').value = id;
        document.getElementById('delete-alert-text').innerHTML = `Apakah Anda yakin ingin menghapus aturan kampanye <strong>"${name}"</strong> dari database?<br><span class="text-[10px] text-red-500">Tindakan ini permanen.</span>`;
        document.getElementById('delete-modal').classList.remove('hidden');
    }

    function closeDeleteModal() {
        document.getElementById('delete-modal').classList.add('hidden');
    }

    // Toggle showing value input based on promo type (B1G1 does not need values)
    function togglePromoValueInput() {
        const type = document.getElementById('prm-type').value;
        const valWrapper = document.getElementById('promo-value-wrapper');
        const valInp = document.getElementById('prm-val');

        if (type === 'BUY_1_GET_1' || type === 'BUY_2_GET_1') {
            valWrapper.classList.add('opacity-40', 'pointer-events-none');
            valInp.removeAttribute('required');
            valInp.value = '0';
        } else {
            valWrapper.classList.remove('opacity-40', 'pointer-events-none');
            valInp.setAttribute('required', 'required');
            if (valInp.value === '0') valInp.value = '';
        }
    }

    // Helper to change style of targeted chips on radio checked
    function setTargetRadioValue(val) {
        const radios = document.getElementsByName('target_type');
        for (let r of radios) {
            if (r.value === val) {
                r.checked = true;
            }
        }
    }

    function toggleTargetFields() {
        const selected = document.querySelector('input[name="target_type"]:checked').value;
        
        const catField = document.getElementById('target-category-field');
        const prodField = document.getElementById('target-product-field');

        catField.classList.add('hidden');
        prodField.classList.add('hidden');
        
        // Remove active styles on all chips
        document.getElementById('lbl-target-ALL').className = "text-[10px] font-bold block text-slate-500";
        document.getElementById('lbl-target-CATEGORY').className = "text-[10px] font-bold block text-slate-500";
        document.getElementById('lbl-target-PRODUCT').className = "text-[10px] font-bold block text-slate-500";
        
        // Remove bg style
        document.getElementById('lbl-target-ALL').parentElement.className = "border border-slate-200 rounded-lg p-2 cursor-pointer hover:bg-slate-50 block";
        document.getElementById('lbl-target-CATEGORY').parentElement.className = "border border-slate-200 rounded-lg p-2 cursor-pointer hover:bg-slate-50 block";
        document.getElementById('lbl-target-PRODUCT').parentElement.className = "border border-slate-200 rounded-lg p-2 cursor-pointer hover:bg-slate-50 block";

        // Set active chip styling
        document.getElementById(`lbl-target-${selected}`).className = "text-[10px] font-bold block text-emerald-800";
        document.getElementById(`lbl-target-${selected}`).parentElement.className = "border border-emerald-500 bg-emerald-50 rounded-lg p-2 cursor-pointer block";

        if (selected === 'CATEGORY') {
            catField.classList.remove('hidden');
        } else if (selected === 'PRODUCT') {
            prodField.classList.remove('hidden');
        }
    }
</script>

<?php include __DIR__ . '/../includes/footer.php'; ?>
