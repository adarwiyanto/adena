<?php
require_once __DIR__ . '/../core/functions.php';
require_once __DIR__ . '/../core/auth.php';
require_once __DIR__ . '/../core/rbac.php';

$appName = app_config()['app']['name'];
$u = current_user();
ensure_rbac_schema();
$resolvedRole = resolve_user_role(is_array($u) ? $u : []);
$displayRole = (string)($resolvedRole['role_name'] ?? '');
$financeKpiAllowed = in_array(strtolower((string)($resolvedRole['role_key'] ?? '')), ['owner','admin'], true);
if ($displayRole === '') {
  $displayRole = (string)($resolvedRole['role_key'] ?? 'unknown');
}
$avatarUrl = '';
if (!empty($u['avatar_path'])) {
  $avatarUrl = upload_url($u['avatar_path'], 'image');
}
$initial = strtoupper(substr((string)($u['name'] ?? 'U'), 0, 1));
$apiPairingNotifyCount = 0;
$apiPairingNotifyItems = [];
$apiPairingCanSee = in_array((string)($resolvedRole['role_key'] ?? ''), ['owner','admin'], true) || has_menu_access($u, 'settings');
if ($apiPairingCanSee) {
  $pairingFile = __DIR__ . '/../core/api_pairing.php';
  if (is_file($pairingFile)) {
    require_once $pairingFile;
    try {
      $apiPairingNotifyCount = pairing_pending_count();
      $apiPairingNotifyItems = pairing_latest_notifications(5);
    } catch (Throwable $e) {
      $apiPairingNotifyCount = 0;
      $apiPairingNotifyItems = [];
    }
  }
}
?>
<?php if ($apiPairingCanSee): ?>
<div class="api-global-notif" aria-label="Notifikasi request API">
  <button class="api-global-notif-btn" type="button">🔔<?php if ($apiPairingNotifyCount > 0): ?><span><?php echo (int)$apiPairingNotifyCount; ?></span><?php endif; ?></button>
  <div class="api-global-notif-menu">
    <div class="api-global-notif-head">Request API<?php if ($apiPairingNotifyCount > 0): ?> <b><?php echo (int)$apiPairingNotifyCount; ?> pending</b><?php endif; ?></div>
    <?php foreach ($apiPairingNotifyItems as $n): ?>
      <a class="api-global-notif-item" href="<?php echo e(base_url('admin/api_pairing.php')); ?>">
        <strong><?php echo e((string)$n['requester_name']); ?></strong>
        <small><?php echo e((string)$n['requester_type'] . ' · ' . (string)$n['status'] . ' · ' . (string)$n['created_at']); ?></small>
      </a>
    <?php endforeach; ?>
    <?php if (!$apiPairingNotifyItems): ?><div class="api-global-notif-empty">Belum ada request API.</div><?php endif; ?>
    <a class="api-global-notif-open" href="<?php echo e(base_url('admin/api_pairing.php')); ?>">Buka Request Pairing</a>
  </div>
</div>
<style>
.api-global-notif{position:fixed;top:14px;right:18px;z-index:9999;font-family:inherit}.api-global-notif-btn{border:1px solid #dbeafe;background:#fff;color:#111827;border-radius:999px;min-width:42px;min-height:38px;padding:7px 10px;box-shadow:0 8px 22px rgba(15,23,42,.12);cursor:pointer}.api-global-notif-btn span{display:inline-block;margin-left:4px;background:#ef4444;color:#fff;border-radius:999px;font-size:11px;line-height:1;padding:3px 6px;font-weight:700}.api-global-notif-menu{display:none;position:absolute;right:0;top:44px;width:340px;max-width:88vw;background:#fff;border:1px solid #e5e7eb;border-radius:14px;box-shadow:0 20px 45px rgba(15,23,42,.18);overflow:hidden}.api-global-notif:hover .api-global-notif-menu,.api-global-notif:focus-within .api-global-notif-menu{display:block}.api-global-notif-head{padding:10px 12px;border-bottom:1px solid #eef2f7;font-weight:700}.api-global-notif-head b{color:#dc2626}.api-global-notif-item{display:block;padding:10px 12px;border-bottom:1px solid #f1f5f9;color:#111827;text-decoration:none}.api-global-notif-item:hover{background:#f8fafc}.api-global-notif-item small{display:block;color:#64748b;margin-top:2px}.api-global-notif-empty{padding:12px;color:#64748b}.api-global-notif-open{display:block;padding:10px 12px;background:#6f4e37;color:#fff;text-align:center;text-decoration:none;font-weight:700}@media(max-width:760px){.api-global-notif{top:10px;right:10px}.api-global-notif-menu{width:310px}}
</style>
<?php endif; ?>
<style>
/* Nested system settings menu: scoped to the admin sidebar. */
.sidebar .admin-nested-toggle{
  width:100%;display:flex;align-items:center;justify-content:space-between;gap:10px;
  margin:4px 0 2px;padding:9px 10px;border:1px solid #e2e8f0;border-radius:10px;
  background:#f8fafc;color:var(--text);font:inherit;font-size:14px;font-weight:700;
  text-align:left;cursor:pointer;box-sizing:border-box;
}
.sidebar .admin-nested-toggle:hover,.sidebar .admin-nested-toggle.active{background:#eef6ff;border-color:#bfdbfe}
.sidebar .admin-nested-chev{margin-left:auto;opacity:.6;transition:transform .18s ease}
.sidebar .admin-nested-toggle[aria-expanded="true"] .admin-nested-chev{transform:rotate(180deg)}
.sidebar .admin-nested-submenu{margin:4px 0 8px 12px;padding-left:8px;border-left:2px solid #dbeafe}
.sidebar .admin-nested-submenu a{padding:8px 10px;font-size:13px}
.sidebar.collapsed .admin-nested-toggle span,.sidebar.collapsed .admin-nested-submenu{display:none}
</style>
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
        <?php if (has_menu_access($u, 'produk')): ?><a href="<?php echo e(base_url('admin/bom.php')); ?>">BOM Produk</a><?php endif; ?>
        <?php if (has_menu_access($u, 'inventori')): ?><a href="<?php echo e(base_url('admin/production.php')); ?>">Produksi</a><?php endif; ?>
        <?php if (has_menu_access($u, 'inventori', 'export')): ?><a href="<?php echo e(base_url('admin/inventory_reports.php')); ?>">Laporan Inventory</a><?php endif; ?>
      </div>
    </div>

    <div class="item">
      <button type="button" data-toggle-submenu="#m-transaksi">
        <div class="mi">💳</div><div class="label">Transaksi & Pembayaran</div>
        <div class="chev">▾</div>
      </button>
      <div class="submenu" id="m-transaksi">
        <?php if (has_menu_access($u, 'sales')): ?><a href="<?php echo e(base_url('admin/sales.php')); ?>">Penjualan</a><?php endif; ?>
        <?php if (has_menu_access($u, 'sales')): ?><a href="<?php echo e(base_url('admin/pos_shifts.php')); ?>">Laporan Shift POS</a><?php endif; ?>
        <?php if (has_menu_access($u, 'rekap_omset')): ?><a href="<?php echo e(base_url('admin/rekap_omset.php')); ?>">Rekap Omset</a><?php endif; ?>
        <?php if (has_menu_access($u, 'customers')): ?><a href="<?php echo e(base_url('admin/customers.php')); ?>">Pelanggan</a><?php endif; ?>
        <?php if (has_menu_access($u, 'purchase')): ?><a href="<?php echo e(base_url('admin/purchase_raw_material.php')); ?>">Pembelian Bahan Baku</a><a href="<?php echo e(base_url('admin/purchase_general.php')); ?>">Pembelian Umum</a><?php endif; ?>
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
          <?php if (has_menu_access($u, 'inventori')): ?><a href="<?php echo e(base_url('admin/stock_card.php')); ?>">Kartu Stok</a><?php endif; ?>
          <?php $roleInfo = resolve_user_role($u); $roleKey = (string)($roleInfo['role_key'] ?? ''); ?>
          <?php if (in_array($roleKey, ['owner','admin','manager_cabang'], true) || has_menu_access($u, 'inventori')): ?><a href="<?php echo e(base_url('admin/kitchen_receive_confirm.php')); ?>">Konfirmasi Stok Dapur</a><?php endif; ?>
        </div>
      </div>
    <?php endif; ?>


    <?php if ($financeKpiAllowed): ?>
      <div class="item">
        <button type="button" data-toggle-submenu="#m-keuangan">
          <div class="mi">💰</div><div class="label">Keuangan</div>
          <div class="chev">▾</div>
        </button>
        <div class="submenu" id="m-keuangan">
          <a href="<?php echo e(base_url('admin/finance.php?tab=summary')); ?>">Ringkasan Keuangan</a>
          <a href="<?php echo e(base_url('admin/finance.php?tab=purchases')); ?>">Pembelian</a>
          <a href="<?php echo e(base_url('admin/finance.php?tab=expenses')); ?>">Pengeluaran</a>
          <a href="<?php echo e(base_url('admin/finance.php?tab=payments')); ?>">Permintaan Pembayaran</a>
          <a href="<?php echo e(base_url('admin/finance.php?tab=settings')); ?>">Setting Jenis Pengeluaran</a>
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

    <?php if (has_menu_access($u, 'users') || has_menu_access($u, 'settings') || has_menu_access($u, 'roles') || has_menu_access($u, 'customers')): ?>
      <?php
        $currentAdminPage = basename((string)($_SERVER['PHP_SELF'] ?? ''));
        $systemSettingsPagesForAdmin = ['api_desktop.php', 'api_pairing.php', 'inventory_settings.php', 'backup.php'];
        $adminSystemOpen = in_array($currentAdminPage, $systemSettingsPagesForAdmin, true);
      ?>
      <div class="item">
        <button type="button" data-toggle-submenu="#m-admin" aria-controls="m-admin" aria-expanded="<?php echo $adminSystemOpen ? 'true' : 'false'; ?>">
          <div class="mi">⚙️</div><div class="label">Admin</div>
          <div class="chev">▾</div>
        </button>
        <div class="submenu<?php echo $adminSystemOpen ? ' open' : ''; ?>" id="m-admin">
          <?php if (has_menu_access($u, 'customers')): ?><a href="<?php echo e(base_url('admin/customer_recap.php')); ?>">Rekapitulasi Pelanggan</a><?php endif; ?>
          <?php if ($financeKpiAllowed): ?>
            <div style="padding:8px 14px 4px;font-size:11px;font-weight:800;letter-spacing:.06em;color:#94a3b8;text-transform:uppercase">KPI</div>
            <a href="<?php echo e(base_url('admin/kpi.php?tab=input')); ?>">↳ Pengisian KPI</a>
            <a href="<?php echo e(base_url('admin/kpi.php?tab=settings')); ?>">↳ Setting KPI</a>
          <?php endif; ?>

          <?php if (has_menu_access($u, 'users')): ?><a href="<?php echo e(base_url('admin/users.php')); ?>">User</a><?php endif; ?>
          <?php if (current_user_is_owner() || has_menu_access($u, 'roles')): ?>
            <a href="<?php echo e(base_url('admin/roles.php')); ?>">Role & Permission</a>
          <?php endif; ?>
          <?php if (has_menu_access($u, 'settings')): ?><a href="<?php echo e(base_url('admin/store.php')); ?>">Profil Toko</a><?php endif; ?>
          <?php if (has_menu_access($u, 'settings')): ?><a href="<?php echo e(base_url('admin/theme.php')); ?>">Tema / CSS</a><?php endif; ?>
          <?php if (has_menu_access($u, 'settings')): ?><a href="<?php echo e(base_url('admin/loyalty.php')); ?>">Loyalti Point</a><?php endif; ?>
          <?php if (has_menu_access($u, 'settings')): ?><a href="<?php echo e(base_url('admin/payment_methods.php')); ?>">Metode Pembayaran</a><?php endif; ?>
          <?php if (has_menu_access($u, 'settings')): ?><a href="<?php echo e(base_url('admin/guides.php')); ?>">Daftar Guide</a><?php endif; ?>
          <?php
            $systemSettingsVisible = has_menu_access($u, 'settings') || $apiPairingCanSee || current_user_is_owner();
            $systemSettingsPages = ['api_desktop.php', 'api_pairing.php', 'inventory_settings.php', 'backup.php'];
            $systemSettingsOpen = in_array(basename((string)($_SERVER['PHP_SELF'] ?? '')), $systemSettingsPages, true);
          ?>
          <?php if ($systemSettingsVisible): ?>
            <button
              class="admin-nested-toggle<?php echo $systemSettingsOpen ? ' active' : ''; ?>"
              type="button"
              data-toggle-submenu="#m-system-settings"
              aria-controls="m-system-settings"
              aria-expanded="<?php echo $systemSettingsOpen ? 'true' : 'false'; ?>">
              <span>Setting System</span><span class="admin-nested-chev">▾</span>
            </button>
            <div class="submenu admin-nested-submenu<?php echo $systemSettingsOpen ? ' open' : ''; ?>" id="m-system-settings">
              <?php if (has_menu_access($u, 'settings')): ?><a href="<?php echo e(base_url('admin/api_desktop.php')); ?>">API &amp; Integrasi</a><?php endif; ?>
              <?php if ($apiPairingCanSee): ?><a href="<?php echo e(base_url('admin/api_pairing.php')); ?>">Pairing Back Office</a><?php endif; ?>
              <?php if (has_menu_access($u, 'settings')): ?><a href="<?php echo e(base_url('admin/inventory_settings.php')); ?>">Setting Produksi/Inventory</a><?php endif; ?>
              <?php if (current_user_is_owner()): ?><a href="<?php echo e(base_url('admin/backup.php')); ?>">Setting Backup Google Drive</a><?php endif; ?>
            </div>
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
