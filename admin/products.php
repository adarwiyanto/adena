<?php
require_once __DIR__ . '/../core/db.php';
require_once __DIR__ . '/../core/functions.php';
require_once __DIR__ . '/../core/security.php';
require_once __DIR__ . '/../core/auth.php';
require_once __DIR__ . '/../core/csrf.php';
require_once __DIR__ . '/../core/rbac.php';
require_once __DIR__ . '/../core/inventory.php';
require_once __DIR__ . '/../lib/upload_secure.php';

start_secure_session();
require_admin();
ensure_rbac_schema();
$u = require_menu_access('produk');
ensure_products_category_column();
ensure_products_best_seller_column();
ensure_inventory_module_schema();

if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'delete') {
  require_action_access('produk', 'delete');
  csrf_check();
  $id = (int)($_POST['id'] ?? 0);

  // Produk yang sudah pernah masuk transaksi/stok tidak dihapus permanen agar riwayat
  // sales, purchase, ledger, opname, dan laporan lama tetap aman. Tombol Hapus
  // berfungsi sebagai sembunyikan dari POS dan landing.
  $stmt = db()->prepare("UPDATE products
    SET show_on_pos=0,
        show_on_landing=0,
        is_best_seller=0,
        updated_at=NOW()
    WHERE id=?");
  $stmt->execute([$id]);

  redirect(base_url('admin/products.php?hidden=1'));
}

$products = db()->query("SELECT * FROM products ORDER BY id DESC")->fetchAll();
$canCreateProduct = has_menu_access($u, 'produk', 'create');
$canEditProduct = has_menu_access($u, 'produk', 'edit');
$canDeleteProduct = has_menu_access($u, 'produk', 'delete');
$customCss = setting('custom_css', '');
?>
<!doctype html>
<html>
<head>
  <meta charset="utf-8">
  <meta name="viewport" content="width=device-width,initial-scale=1">
  <title>Produk</title>
  <link rel="icon" href="<?php echo e(favicon_url()); ?>">
  <link rel="stylesheet" href="<?php echo e(asset_url('assets/app.css')); ?>">
  <style><?php echo $customCss; ?></style>
</head>
<body>
<div class="container">
  <?php include __DIR__ . '/partials_sidebar.php'; ?>
  <div class="main">
    <div class="topbar">
      <button class="btn" data-toggle-sidebar type="button">Menu</button>
      <?php if ($canCreateProduct): ?><a class="btn" href="<?php echo e(base_url('admin/product_form.php')); ?>">Tambah Produk</a><a class="btn" href="<?php echo e(base_url('admin/product_import.php')); ?>">Impor Produk</a><?php endif; ?>
    </div>

    <div class="content">
      <div class="card">
        <h3 style="margin-top:0">Daftar Produk</h3>
        <?php if (isset($_GET['hidden'])): ?><div class="card" style="border-color:#86efac;background:#ecfdf5;margin-bottom:12px">Produk berhasil disembunyikan dari POS dan landing. Riwayat transaksi/stok tetap aman.</div><?php endif; ?>
        <table class="table">
          <thead>
            <tr>
              <th>Foto</th><th>Nama</th><th>Tipe</th><th>Kategori</th><th>Harga</th><th>BOM</th><th>POS</th><th>Landing</th><th>Best Seller</th><th>Aksi</th>
            </tr>
          </thead>
          <tbody>
            <?php foreach ($products as $p): ?>
              <tr>
                <td>
                  <?php if ($p['image_path']): ?>
                    <img class="thumb" src="<?php echo e(upload_url($p['image_path'], 'image')); ?>">
                  <?php else: ?>
                    <div class="thumb" style="display:flex;align-items:center;justify-content:center;color:var(--muted)">No</div>
                  <?php endif; ?>
                </td>
                <td><?php echo e($p['name']); ?></td>
                <td><?php echo e((string)($p['product_type'] ?? 'finished_good')); ?></td>
                <td><?php echo e($p['category'] ?: 'Tanpa kategori'); ?></td>
                <?php $unitMeta = product_unit_fallback($p); ?>
                <td>Rp <?php echo e(format_money((float)$p['price'])); ?><br><small><?php echo e($unitMeta['base_unit']); ?> | beli <?php echo e($unitMeta['purchase_unit']); ?> (x<?php echo e(format_number_custom($unitMeta['purchase_to_base_factor'], 2)); ?>)</small></td>
                <td><?php echo !empty($p['allow_bom']) ? 'Aktif' : '-'; ?></td>
                <td><?php echo !empty($p['show_on_pos'] ?? 1) ? 'Tampil' : '-'; ?></td>
                <td><?php echo !empty($p['show_on_landing'] ?? 1) ? 'Tampil' : '-'; ?></td>
                <td><?php echo !empty($p['is_best_seller']) ? '⭐' : '-'; ?></td>
                <td style="display:flex;gap:8px;align-items:center">
                  <?php if ($canEditProduct): ?><a class="btn" href="<?php echo e(base_url('admin/product_form.php?id=' . (int)$p['id'])); ?>">Edit</a><?php endif; ?>
                  <?php if ($canDeleteProduct): ?><form method="post" data-confirm="Sembunyikan produk ini dari POS dan landing?">
                    <input type="hidden" name="_csrf" value="<?php echo e(csrf_token()); ?>">
                    <input type="hidden" name="action" value="delete">
                    <input type="hidden" name="id" value="<?php echo e((string)$p['id']); ?>">
                    <button class="btn danger" type="submit">Hapus/Sembunyikan</button>
                  </form><?php endif; ?>
                </td>
              </tr>
            <?php endforeach; ?>
          </tbody>
        </table>
      </div>
    </div>
  </div>
</div>
<script defer src="<?php echo e(asset_url('assets/app.js')); ?>"></script>
</body>
</html>
