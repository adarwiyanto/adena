<?php
require_once __DIR__ . '/../core/functions.php';
require_once __DIR__ . '/../core/auth.php';
require_once __DIR__ . '/../core/rbac.php';
require_once __DIR__ . '/../core/csrf.php';
require_once __DIR__ . '/../core/portal_switcher.php';

$appName = app_config()['app']['name'];
$u = current_user();
ensure_rbac_schema();
$resolvedRole = resolve_user_role(is_array($u) ? $u : []);
$displayRole = (string)($resolvedRole['role_name'] ?? '');
if ($displayRole === '') {
  $displayRole = (string)($resolvedRole['role_key'] ?? 'unknown');
}
$avatarUrl = '';
if (!empty($u['avatar_path'])) {
  $avatarUrl = upload_url($u['avatar_path'], 'image');
}
$portalOptions = is_array($u) ? adena_portal_options($u) : [];
$currentPortal = adena_portal_current_value();
$portalFlash = adena_portal_flash();

$path = str_replace('\\', '/', (string)($_SERVER['PHP_SELF'] ?? ''));
$sessionPortalType = (string)($_SESSION['adena_portal_type'] ?? '');
$portalType = 'admin';
if (strpos($path, '/cabang/') !== false || strpos($path, '/branch/') !== false) {
  $portalType = 'branch';
} elseif (strpos($path, '/kitchen/') !== false) {
  $portalType = 'kitchen';
} elseif (in_array($sessionPortalType, ['branch', 'kitchen'], true) && strpos($path, '/admin/') === false) {
  $portalType = $sessionPortalType;
}
if ($currentPortal === 'kitchen') $portalType = 'kitchen';
if (strpos($currentPortal, 'branch:') === 0) $portalType = 'branch';
if ($currentPortal === 'admin' && strpos($path, '/admin/') !== false) $portalType = 'admin';

$activeBranchName = 'Cabang';
$activeBranchCode = 'CABANG';
if ($portalType === 'branch') {
  try {
    $bid = (int)($_SESSION['active_branch_id'] ?? 0);
    if ($bid <= 0 && preg_match('/branch:(\d+)/', $currentPortal, $m)) $bid = (int)$m[1];
    if ($bid > 0) {
      $br = branch_portal_branch($bid);
      if (is_array($br)) {
        $activeBranchName = (string)($br['branch_name'] ?? $activeBranchName);
        $activeBranchCode = (string)($br['branch_code'] ?? $activeBranchCode);
      }
    }
  } catch (Throwable $e) {}
}
$portalLabel = 'ADMIN PUSAT';
if ($portalType === 'branch') $portalLabel = 'CABANG - ' . $activeBranchName;
if ($portalType === 'kitchen') $portalLabel = 'DAPUR PRODUKSI';

$isActive = static function (string $file): string {
  return (basename((string)($_SERVER['PHP_SELF'] ?? '')) === $file) ? 'active' : '';
};
?>
<div class="sidebar">
  <div class="sb-top">
    <div class="profile-card">
      <button class="profile-trigger" type="button" data-toggle-submenu="#profile-menu">
        <div class="avatar">
          <?php if ($avatarUrl): ?>
            <img src="<?php echo e($avatarUrl); ?>" alt="<?php echo e($u['name'] ?? 'User'); ?>" onerror="this.style.display='none';this.nextElementSibling.style.display='flex';">
            <span class="avatar-no-photo" style="display:none">No<br>Photo</span>
          <?php else: ?>
            <span class="avatar-no-photo">No<br>Photo</span>
          <?php endif; ?>
        </div>
        <div class="p-text">
          <div class="p-title"><?php echo e($u['name'] ?? 'User'); ?></div>
          <div class="p-sub"><?php echo e($displayRole); ?></div>
        </div>
        <div class="p-right"><span class="chev">▾</span></div>
      </button>
    </div>
    <div class="submenu profile-submenu" id="profile-menu">
      <a href="<?php echo e(base_url('profile.php')); ?>">Edit Profil</a>
      <a href="<?php echo e(base_url('password.php')); ?>">Ubah Password</a>
    </div>

    <form class="portal-switcher" method="post" action="<?php echo e(base_url('portal_switch.php')); ?>">
      <input type="hidden" name="_csrf" value="<?php echo e(csrf_token()); ?>">
      <input type="hidden" name="back" value="<?php echo e((string)($_SERVER['REQUEST_URI'] ?? '')); ?>">
      <label for="portal_target">Pindah Halaman</label>
      <div class="portal-switch-row">
        <select id="portal_target" name="portal_target">
          <?php foreach ($portalOptions as $opt): ?>
            <?php $isAllowed = !empty($opt['allowed']); $value = (string)$opt['value']; ?>
            <option value="<?php echo e($value); ?>" class="<?php echo $isAllowed ? '' : 'is-locked'; ?>" <?php echo $value === $currentPortal ? 'selected' : ''; ?>><?php echo $isAllowed ? '' : '🔒 '; ?><?php echo e((string)$opt['label']); ?></option>
          <?php endforeach; ?>
        </select>
        <button type="submit">Go</button>
      </div>
      <?php if ($portalFlash): ?>
        <div class="portal-flash <?php echo e((string)($portalFlash['type'] ?? 'error')); ?>"><?php echo e((string)($portalFlash['message'] ?? '')); ?></div>
      <?php endif; ?>
    </form>
    <div class="portal-context-badge <?php echo e($portalType); ?>"><?php echo e($portalLabel); ?></div>
  </div>

  <div class="nav">
    <?php if ($portalType === 'branch'): ?>
      <div class="group-label">Portal Cabang</div>
      <div class="item"><a class="<?php echo $isActive('dashboard.php'); ?>" href="<?php echo e(base_url('cabang/dashboard.php')); ?>"><div class="mi">🏠</div><div class="label">Dashboard Cabang</div></a></div>
      <div class="item"><a class="<?php echo $isActive('pilih.php'); ?>" href="<?php echo e(base_url('cabang/pilih.php')); ?>"><div class="mi">🏬</div><div class="label">Pilih Cabang</div></a></div>
      <div class="item"><a class="<?php echo $isActive('stocks.php'); ?>" href="<?php echo e(base_url('cabang/stocks.php')); ?>"><div class="mi">📦</div><div class="label">Stok Cabang</div></a></div>
      <div class="item"><a href="<?php echo e(base_url('cabang/stock_initial.php')); ?>"><div class="mi">🧮</div><div class="label">Stok Awal</div></a></div>
      <div class="item"><a class="<?php echo $isActive('stok_masuk.php'); ?>" href="<?php echo e(base_url('cabang/stok_masuk.php')); ?>"><div class="mi">📥</div><div class="label">Input Stok</div></a></div>
      <div class="item"><a class="<?php echo $isActive('stock_opname.php'); ?>" href="<?php echo e(base_url('cabang/stock_opname.php')); ?>"><div class="mi">📋</div><div class="label">Stok Opname</div></a></div>
      <div class="item"><a href="<?php echo e(base_url('cabang/receive_transfer.php')); ?>"><div class="mi">✅</div><div class="label">Terima Transfer</div></a></div>
      <div class="item"><a class="<?php echo $isActive('riwayat.php'); ?>" href="<?php echo e(base_url('cabang/riwayat.php')); ?>"><div class="mi">🕘</div><div class="label">Riwayat Cabang</div></a></div>
      <?php if (has_menu_access($u, 'pos')): ?>
        <div class="item"><a href="<?php echo e(base_url('pos/index.php')); ?>" target="_blank" rel="noopener"><div class="mi">🧾</div><div class="label">POS Kasir</div></a></div>
      <?php endif; ?>
      <div class="item"><a href="<?php echo e(base_url('admin/logout.php')); ?>"><div class="mi">⎋</div><div class="label">Logout</div></a></div>

    <?php elseif ($portalType === 'kitchen'): ?>
      <div class="group-label">Portal Dapur</div>
      <div class="item"><a class="<?php echo $isActive('index.php'); ?>" href="<?php echo e(base_url('kitchen/index.php')); ?>"><div class="mi">🏠</div><div class="label">Dashboard Dapur</div></a></div>
      <div class="item"><a href="<?php echo e(base_url('kitchen/initial_stock.php')); ?>"><div class="mi">🧮</div><div class="label">Stok Awal Dapur</div></a></div>
      <div class="item"><a href="<?php echo e(base_url('kitchen/stocks.php')); ?>"><div class="mi">📦</div><div class="label">Stok Dapur</div></a></div>
      <div class="item"><a href="<?php echo e(base_url('kitchen/bom.php')); ?>"><div class="mi">🧩</div><div class="label">BOM Produk</div></a></div>
      <div class="item"><a href="<?php echo e(base_url('kitchen/production.php')); ?>"><div class="mi">🍳</div><div class="label">Produksi</div></a></div>
      <div class="item"><a class="<?php echo $isActive('transfers.php'); ?>" href="<?php echo e(base_url('kitchen/transfers.php')); ?>"><div class="mi">🚚</div><div class="label">Transfer ke Cabang</div></a></div>
      <div class="item"><a href="<?php echo e(base_url('kitchen/opname.php')); ?>"><div class="mi">📋</div><div class="label">Stok Opname</div></a></div>
      <div class="item"><a href="<?php echo e(base_url('admin/logout.php')); ?>"><div class="mi">⎋</div><div class="label">Logout</div></a></div>

    <?php else: ?>
      <?php if (has_menu_access($u, 'produk')): ?>
      <div class="item">
        <a href="<?php echo e(base_url('index.php')); ?>" target="_blank" rel="noopener">
          <div class="mi">🌐</div><div class="label">Landing Page</div>
        </a>
      </div>
      <?php endif; ?>

      <?php if (has_menu_access($u, 'dashboard') || has_menu_access($u, 'pos') || has_menu_access($u, 'produk') || has_menu_access($u, 'sales')): ?>
      <?php if (has_menu_access($u, 'dashboard')): ?>
      <div class="item">
        <a class="<?php echo (basename($_SERVER['PHP_SELF'])==='dashboard.php')?'active':''; ?>" href="<?php echo e(base_url('admin/dashboard.php')); ?>">
          <div class="mi">🏠</div><div class="label">Dasbor</div>
        </a>
      </div>
      <?php endif; ?>

      <div class="item">
        <button type="button" data-toggle-submenu="#m-produk">
          <div class="mi">📦</div><div class="label">Produk & Inventori</div><div class="chev">▾</div>
        </button>
        <div class="submenu" id="m-produk">
          <?php if (has_menu_access($u, 'produk')): ?><a href="<?php echo e(base_url('admin/products.php')); ?>">Produk</a><?php endif; ?>
          <?php if (has_menu_access($u, 'produk')): ?><a href="<?php echo e(base_url('admin/product_categories.php')); ?>">Kategori Produk</a><?php endif; ?>
          <?php if (has_menu_access($u, 'produk')): ?><a href="<?php echo e(base_url('admin/bom.php')); ?>">BOM Produk</a><?php endif; ?>
          <?php if (has_menu_access($u, 'inventori')): ?><a href="<?php echo e(base_url('admin/production.php')); ?>">Produksi</a><?php endif; ?>
          <?php if (has_menu_access($u, 'inventori', 'export')): ?><a href="<?php echo e(base_url('admin/inventory_reports.php')); ?>">Laporan Inventory</a><?php endif; ?>
        </div>
      </div>

      <div class="item">
        <button type="button" data-toggle-submenu="#m-transaksi">
          <div class="mi">💳</div><div class="label">Transaksi & Pembayaran</div><div class="chev">▾</div>
        </button>
        <div class="submenu" id="m-transaksi">
          <?php if (has_menu_access($u, 'sales')): ?><a href="<?php echo e(base_url('admin/sales.php')); ?>">Penjualan</a><?php endif; ?>
          <?php if (has_menu_access($u, 'sales')): ?><a href="<?php echo e(base_url('admin/pos_shifts.php')); ?>">Laporan Shift POS</a><?php endif; ?>
          <?php if (has_menu_access($u, 'rekap_omset')): ?><a href="<?php echo e(base_url('admin/rekap_omset.php')); ?>">Rekap Omset</a><?php endif; ?>
          <?php if (has_menu_access($u, 'customers')): ?><a href="<?php echo e(base_url('admin/customers.php')); ?>">Pelanggan</a><?php endif; ?>
          <?php if (has_menu_access($u, 'purchase')): ?><a href="<?php echo e(base_url('admin/purchase_raw_material.php')); ?>">Pembelian Bahan Baku</a><?php endif; ?>
          <?php if (has_menu_access($u, 'suppliers')): ?><a href="<?php echo e(base_url('admin/suppliers.php')); ?>">Master Supplier</a><?php endif; ?>
        </div>
      </div>
      <?php endif; ?>

      <?php if (has_menu_access($u, 'inventori') || has_menu_access($u, 'stok_opname')): ?>
        <div class="item">
          <button type="button" data-toggle-submenu="#m-stok">
            <div class="mi">📊</div><div class="label">Stok</div><div class="chev">▾</div>
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

      <?php if (has_menu_access($u, 'users') || has_menu_access($u, 'settings') || has_menu_access($u, 'roles')): ?>
        <div class="item">
          <button type="button" data-toggle-submenu="#m-admin">
            <div class="mi">⚙️</div><div class="label">Admin</div><div class="chev">▾</div>
          </button>
          <div class="submenu" id="m-admin">
            <?php if (has_menu_access($u, 'users')): ?><a href="<?php echo e(base_url('admin/users.php')); ?>">User</a><?php endif; ?>
            <?php if (current_user_is_owner() || has_menu_access($u, 'roles')): ?><a href="<?php echo e(base_url('admin/roles.php')); ?>">Role & Permission</a><?php endif; ?>
            <?php if (has_menu_access($u, 'settings')): ?><a href="<?php echo e(base_url('admin/store.php')); ?>">Profil Toko</a><?php endif; ?>
            <?php if (has_menu_access($u, 'settings')): ?><a href="<?php echo e(base_url('admin/theme.php')); ?>">Tema / CSS</a><?php endif; ?>
            <?php if (has_menu_access($u, 'settings')): ?><a href="<?php echo e(base_url('admin/loyalty.php')); ?>">Loyalti Point</a><?php endif; ?>
            <?php if (has_menu_access($u, 'settings')): ?><a href="<?php echo e(base_url('admin/payment_methods.php')); ?>">Metode Pembayaran</a><?php endif; ?>
            <?php if (has_menu_access($u, 'settings')): ?><a href="<?php echo e(base_url('admin/guides.php')); ?>">Daftar Guide</a><?php endif; ?>
            <?php if (has_menu_access($u, 'settings')): ?><a href="<?php echo e(base_url('admin/api_desktop.php')); ?>">Kasir Desktop</a><?php endif; ?>
            <?php if (has_menu_access($u, 'settings')): ?><a href="<?php echo e(base_url('admin/inventory_settings.php')); ?>">Setting Produksi/Inventory</a><?php endif; ?>
            <?php if (current_user_is_owner()): ?><a href="<?php echo e(base_url('admin/backup.php')); ?>">Backup Database</a><?php endif; ?>
          </div>
        </div>
      <?php endif; ?>

      <div class="item"><a href="<?php echo e(base_url('admin/logout.php')); ?>"><div class="mi">⎋</div><div class="label">Logout</div></a></div>
    <?php endif; ?>
  </div>
</div>
