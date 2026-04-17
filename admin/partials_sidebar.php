<?php
require_once __DIR__ . '/../core/functions.php';
require_once __DIR__ . '/../core/auth.php';
require_once __DIR__ . '/../core/rbac.php';

$appName = app_config()['app']['name'];
$u = current_user();
ensure_rbac_schema();
$avatarUrl = '';
if (!empty($u['avatar_path'])) {
  $avatarUrl = upload_url($u['avatar_path'], 'image');
}
$initial = strtoupper(substr((string)($u['name'] ?? 'U'), 0, 1));
?>
<div class="sidebar">
  <div class="sb-top">
    <div class="profile-card">
      <button class="profile-trigger" type="button" data-toggle-submenu="#profile-menu">
        <div class="avatar">
          <?php if ($avatarUrl): ?>
            <img src="<?php echo e($avatarUrl); ?>" alt="<?php echo e($u['name'] ?? 'User'); ?>">
          <?php else: ?>
            <?php echo e($initial); ?>
          <?php endif; ?>
        </div>
        <div class="p-text">
          <div class="p-title"><?php echo e($u['name'] ?? 'User'); ?></div>
          <div class="p-sub"><?php echo e(ucfirst($u['role'] ?? 'admin')); ?></div>
        </div>
        <div class="p-right">
          <span class="chev">▾</span>
        </div>
      </button>
    </div>
    <div class="submenu profile-submenu" id="profile-menu">
      <a href="<?php echo e(base_url('profile.php')); ?>">Edit Profil</a>
      <a href="<?php echo e(base_url('password.php')); ?>">Ubah Password</a>
    </div>
  </div>

  <div class="nav">
    <?php if (has_menu_access($u, 'produk')): ?>
    <div class="item">
      <a href="<?php echo e(base_url('index.php')); ?>" target="_blank" rel="noopener">
        <div class="mi">🌐</div><div class="label">Landing Page</div>
      </a>
    </div>
    <?php endif; ?>

    <?php if (has_menu_access($u, 'admin') || has_menu_access($u, 'pos') || has_menu_access($u, 'produk')): ?>
    <div class="item">
      <a class="<?php echo (basename($_SERVER['PHP_SELF'])==='dashboard.php')?'active':''; ?>"
         href="<?php echo e(base_url('admin/dashboard.php')); ?>">
        <div class="mi">🏠</div><div class="label">Dasbor</div>
      </a>
    </div>

    <div class="item">
      <button type="button" data-toggle-submenu="#m-produk">
        <div class="mi">📦</div><div class="label">Produk & Inventori</div>
        <div class="chev">▾</div>
      </button>
      <div class="submenu" id="m-produk">
        <a href="<?php echo e(base_url('admin/products.php')); ?>">Produk</a>
        <a href="<?php echo e(base_url('admin/product_categories.php')); ?>">Kategori Produk</a>
        <a href="<?php echo e(base_url('admin/bom.php')); ?>">BOM Produk</a>
        <a href="<?php echo e(base_url('admin/production.php')); ?>">Produksi</a>
        <a href="<?php echo e(base_url('admin/inventory_reports.php')); ?>">Laporan Inventory</a>
      </div>
    </div>

    <div class="item">
      <button type="button" data-toggle-submenu="#m-transaksi">
        <div class="mi">💳</div><div class="label">Transaksi & Pembayaran</div>
        <div class="chev">▾</div>
      </button>
      <div class="submenu" id="m-transaksi">
        <a href="<?php echo e(base_url('admin/sales.php')); ?>">Penjualan</a>
        <a href="<?php echo e(base_url('admin/customers.php')); ?>">Pelanggan</a>
        <a href="<?php echo e(base_url('admin/purchase_raw_material.php')); ?>">Pembelian Bahan Baku</a>
        <a href="<?php echo e(base_url('admin/suppliers.php')); ?>">Master Supplier</a>
      </div>
    </div>
    <?php endif; ?>

    <?php if (has_menu_access($u, 'inventori') || has_menu_access($u, 'stok_opname')): ?>
      <div class="item">
        <button type="button" data-toggle-submenu="#m-stok">
          <div class="mi">📊</div><div class="label">Stok</div>
          <div class="chev">▾</div>
        </button>
        <div class="submenu" id="m-stok">
          <?php if (has_menu_access($u, 'inventori')): ?><a href="<?php echo e(base_url('admin/stocks.php')); ?>">Daftar Stok</a><?php endif; ?>
          <?php if (has_menu_access($u, 'stok_opname')): ?><a href="<?php echo e(base_url('admin/stock_opname.php')); ?>">Stok Opname</a><?php endif; ?>
          <?php if (has_menu_access($u, 'stok_opname', 'approve')): ?><a href="<?php echo e(base_url('admin/stock_opname_approval.php')); ?>">Approval Opname</a><?php endif; ?>
          <?php if (has_menu_access($u, 'inventori')): ?><a href="<?php echo e(base_url('admin/stock_card.php')); ?>">Kartu Stok</a><?php endif; ?>
        </div>
      </div>
    <?php endif; ?>

    <?php if (has_menu_access($u, 'pos')): ?>
    <div class="item">
      <a href="<?php echo e(base_url('pos/index.php')); ?>" target="_blank" rel="noopener">
        <div class="mi">🧾</div><div class="label">POS Kasir</div>
      </a>
    </div>
    <?php endif; ?>

    <?php if (has_menu_access($u, 'admin')): ?>
      <div class="item">
        <button type="button" data-toggle-submenu="#m-admin">
          <div class="mi">⚙️</div><div class="label">Admin</div>
          <div class="chev">▾</div>
        </button>
        <div class="submenu" id="m-admin">
          <a href="<?php echo e(base_url('admin/users.php')); ?>">User</a>
          <?php if (current_user_is_owner()): ?>
            <a href="<?php echo e(base_url('admin/roles.php')); ?>">Role & Permission</a>
          <?php endif; ?>
          <a href="<?php echo e(base_url('admin/store.php')); ?>">Profil Toko</a>
          <a href="<?php echo e(base_url('admin/theme.php')); ?>">Tema / CSS</a>
          <a href="<?php echo e(base_url('admin/loyalty.php')); ?>">Loyalti Point</a>
          <a href="<?php echo e(base_url('admin/inventory_settings.php')); ?>">Setting Produksi/Inventory</a>
          <?php if (($u['role'] ?? '') === 'owner'): ?>
            <a href="<?php echo e(base_url('admin/backup.php')); ?>">Backup Database</a>
          <?php endif; ?>
        </div>
      </div>
    <?php endif; ?>

    <div class="item">
      <a href="<?php echo e(base_url('admin/logout.php')); ?>">
        <div class="mi">⎋</div><div class="label">Logout</div>
      </a>
    </div>
  </div>
</div>
