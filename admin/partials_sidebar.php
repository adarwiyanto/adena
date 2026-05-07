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
$initial = strtoupper(substr((string)($u['name'] ?? 'U'), 0, 1));
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
        <div class="p-right">
          <span class="chev">▾</span>
        </div>
      </button>
    </div>
    <div class="submenu profile-submenu" id="profile-menu">
      <a href="<?php echo e(base_url('profile.php')); ?>">Edit Profil</a>
      <a href="<?php echo e(base_url('password.php')); ?>">Ubah Password</a>
    </div>

    <?php
      $portalOptions = adena_portal_options(is_array($u) ? $u : []);
      $portalCurrent = adena_portal_current_value();
      $portalFlash = adena_portal_flash();
    ?>
    <?php if ($portalFlash): ?>
      <div class="card" style="margin:10px 12px;padding:10px;border-color:rgba(251,113,133,.35);background:rgba(251,113,133,.10);font-size:12px">
        <?php echo e((string)$portalFlash['message']); ?>
      </div>
    <?php endif; ?>
    <form method="post" action="<?php echo e(base_url('portal_switch.php')); ?>" style="padding:10px 12px;border-top:1px solid rgba(148,163,184,.18);border-bottom:1px solid rgba(148,163,184,.18);">
      <input type="hidden" name="_csrf" value="<?php echo e(csrf_token()); ?>">
      <input type="hidden" name="back" value="<?php echo e((string)($_SERVER['REQUEST_URI'] ?? base_url('admin/dashboard.php'))); ?>">
      <label style="display:block;font-size:11px;color:#64748b;font-weight:800;margin-bottom:6px;text-transform:uppercase;letter-spacing:.06em">Area Kerja</label>
      <select name="portal_target" style="width:100%;border-radius:10px;border:1px solid #cbd5e1;padding:8px 10px;background:#fff;color:#0f172a;font-weight:700">
        <?php foreach ($portalOptions as $opt): ?>
          <?php if (!empty($opt['allowed'])): ?>
            <option value="<?php echo e((string)$opt['value']); ?>" <?php echo ((string)$opt['value'] === $portalCurrent) ? 'selected' : ''; ?>><?php echo e((string)$opt['label']); ?></option>
          <?php endif; ?>
        <?php endforeach; ?>
      </select>
      <button class="btn" style="margin-top:8px;width:100%;justify-content:center" type="submit">Go / Buka</button>
    </form>
  </div>

  <div class="nav">
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
      <a class="<?php echo (basename($_SERVER['PHP_SELF'])==='dashboard.php')?'active':''; ?>"
         href="<?php echo e(base_url('admin/dashboard.php')); ?>">
        <div class="mi">🏠</div><div class="label">Dasbor</div>
      </a>
    </div>
    <?php endif; ?>

    <div class="item">
      <button type="button" data-toggle-submenu="#m-produk">
        <div class="mi">📦</div><div class="label">Produk & Inventori</div>
        <div class="chev">▾</div>
      </button>
      <div class="submenu" id="m-produk">
        <?php if (has_menu_access($u, 'produk')): ?><a href="<?php echo e(base_url('admin/products.php')); ?>">Produk</a><?php endif; ?>
        <?php if (has_menu_access($u, 'produk')): ?><a href="<?php echo e(base_url('admin/product_categories.php')); ?>">Kategori Produk</a><?php endif; ?>
        <?php if (has_menu_access($u, 'produk')): ?><a href="<?php echo e(base_url('admin/branch_product_prices.php')); ?>">Harga per Cabang</a><?php endif; ?>
        <?php if (has_menu_access($u, 'produk')): ?><a href="<?php echo e(base_url('admin/bom.php')); ?>">BOM Produk</a><?php endif; ?>
        <?php if (has_menu_access($u, 'inventori')): ?><a href="<?php echo e(base_url('admin/production.php')); ?>">Produksi</a><?php endif; ?>
        <?php if (has_menu_access($u, 'inventori', 'export')): ?><a href="<?php echo e(base_url('admin/inventory_reports.php')); ?>">Laporan Inventory</a><?php endif; ?>
      </div>
    </div>

    <div class="item">
      <button type="button" data-toggle-submenu="#m-transaksi">
        <div class="mi">💳</div><div class="label">Pembayaran & Admin</div>
        <div class="chev">▾</div>
      </button>
      <div class="submenu" id="m-transaksi">
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

    <?php if (has_menu_access($u, 'users') || has_menu_access($u, 'settings') || has_menu_access($u, 'roles')): ?>
      <div class="item">
        <button type="button" data-toggle-submenu="#m-admin">
          <div class="mi">⚙️</div><div class="label">Admin</div>
          <div class="chev">▾</div>
        </button>
        <div class="submenu" id="m-admin">
          <?php if (has_menu_access($u, 'users')): ?><a href="<?php echo e(base_url('admin/users.php')); ?>">User</a><?php endif; ?>
          <?php if (current_user_is_owner() || has_menu_access($u, 'roles')): ?>
            <a href="<?php echo e(base_url('admin/roles.php')); ?>">Role & Permission</a>
          <?php endif; ?>
          <?php if (has_menu_access($u, 'settings')): ?><a href="<?php echo e(base_url('admin/store.php')); ?>">Profil Toko</a><?php endif; ?>
          <?php if (has_menu_access($u, 'settings')): ?><a href="<?php echo e(base_url('admin/theme.php')); ?>">Tema / CSS</a><?php endif; ?>
          <?php if (has_menu_access($u, 'settings')): ?><a href="<?php echo e(base_url('admin/loyalty.php')); ?>">Loyalti Point</a><?php endif; ?>
          <?php if (has_menu_access($u, 'settings')): ?><a href="<?php echo e(base_url('admin/payment_methods.php')); ?>">Metode Pembayaran</a><?php endif; ?>
          <?php if (has_menu_access($u, 'settings')): ?><a href="<?php echo e(base_url('admin/guides.php')); ?>">Daftar Guide</a><?php endif; ?>
          <?php if (has_menu_access($u, 'settings')): ?><a href="<?php echo e(base_url('admin/api_desktop.php')); ?>">Kasir Desktop</a><?php endif; ?>
          <?php if (has_menu_access($u, 'settings')): ?><a href="<?php echo e(base_url('admin/inventory_settings.php')); ?>">Setting Produksi/Inventory</a><?php endif; ?>
          <?php if (current_user_is_owner()): ?>
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
