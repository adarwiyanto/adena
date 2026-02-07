<?php
require_once __DIR__ . '/../core/functions.php';
require_once __DIR__ . '/../core/auth.php';

$appName = app_config()['app']['name'];
$u = current_user();
$role = (string)($u['role'] ?? '');
$isManagerToko = $role === 'manager_toko';
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
    <?php if ($isManagerToko): ?>
      <div class="item">
        <a class="<?php echo (basename($_SERVER['PHP_SELF'])==='schedule.php')?'active':''; ?>" href="<?php echo e(base_url('admin/schedule.php')); ?>">
          <div class="mi">📅</div><div class="label">Jadwal Pegawai</div>
        </a>
      </div>
      <div class="item">
        <a class="<?php echo (basename($_SERVER['PHP_SELF'])==='attendance.php')?'active':''; ?>" href="<?php echo e(base_url('admin/attendance.php')); ?>">
          <div class="mi">🕒</div><div class="label">Rekap Absensi</div>
        </a>
      </div>
      <div class="item">
        <a href="<?php echo e(base_url('pos/index.php')); ?>" target="_blank" rel="noopener">
          <div class="mi">🧾</div><div class="label">POS Kasir</div>
        </a>
      </div>
    <?php else: ?>
      <div class="item">
        <a href="<?php echo e(base_url('index.php')); ?>" target="_blank" rel="noopener">
          <div class="mi">🌐</div><div class="label">Landing Page</div>
        </a>
      </div>

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
        </div>
      </div>

      <div class="item">
        <a href="<?php echo e(base_url('pos/index.php')); ?>" target="_blank" rel="noopener">
          <div class="mi">🧾</div><div class="label">POS Kasir</div>
        </a>
      </div>

      <?php if (in_array($u['role'] ?? '', ['admin', 'owner'], true)): ?>
        <div class="item">
          <button type="button" data-toggle-submenu="#m-admin">
            <div class="mi">⚙️</div><div class="label">Admin</div>
            <div class="chev">▾</div>
          </button>
          <div class="submenu" id="m-admin">
            <a href="<?php echo e(base_url('admin/users.php')); ?>">User</a>
            <a href="<?php echo e(base_url('admin/store.php')); ?>">Profil Toko</a>
            <a href="<?php echo e(base_url('admin/theme.php')); ?>">Tema / CSS</a>
            <a href="<?php echo e(base_url('admin/loyalty.php')); ?>">Loyalti Point</a>
            <a href="<?php echo e(base_url('admin/schedule.php')); ?>">Jadwal Pegawai</a>
            <a href="<?php echo e(base_url('admin/attendance.php')); ?>">Rekap Absensi</a>
            <?php if (($u['role'] ?? '') === 'owner'): ?>
              <a href="<?php echo e(base_url('admin/backup.php')); ?>">Backup Database</a>
            <?php endif; ?>
          </div>
        </div>
      <?php endif; ?>
    <?php endif; ?>

    <div class="item">
      <a href="<?php echo e(base_url('admin/logout.php')); ?>">
        <div class="mi">⎋</div><div class="label">Logout</div>
      </a>
    </div>
  </div>
</div>
