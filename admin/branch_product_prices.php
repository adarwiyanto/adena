<?php
require_once __DIR__ . '/../core/db.php';
require_once __DIR__ . '/../core/functions.php';
require_once __DIR__ . '/../core/security.php';
require_once __DIR__ . '/../core/auth.php';
require_once __DIR__ . '/../core/csrf.php';
require_once __DIR__ . '/../core/rbac.php';
require_once __DIR__ . '/../core/inventory.php';

start_secure_session();
$u = require_menu_access('branch_prices', 'view');
ensure_inventory_module_schema();
ensure_branch_product_prices_table();

$branches = inventory_branches();
$branchId = (int)($_GET['branch_id'] ?? $_POST['branch_id'] ?? active_branch_id());
if ($branchId <= 0 && !empty($branches)) {
  $branchId = (int)$branches[0]['id'];
}

$branchExists = false;
foreach ($branches as $b) {
  if ((int)$b['id'] === $branchId) {
    $branchExists = true;
    break;
  }
}
if (!$branchExists && !empty($branches)) {
  $branchId = (int)$branches[0]['id'];
}

$err = '';
$ok = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'save_prices') {
  require_action_access('branch_prices', 'edit');
  csrf_check();
  $branchId = (int)($_POST['branch_id'] ?? 0);
  $prices = $_POST['prices'] ?? [];
  $active = $_POST['active'] ?? [];

  if ($branchId <= 0) {
    $err = 'Cabang wajib dipilih.';
  } elseif (!is_array($prices)) {
    $err = 'Data harga tidak valid.';
  } else {
    $db = db();
    $db->beginTransaction();
    try {
      $upsert = $db->prepare("INSERT INTO branch_product_prices (branch_id, product_id, price, is_active)
        VALUES (?, ?, ?, ?)
        ON DUPLICATE KEY UPDATE price=VALUES(price), is_active=VALUES(is_active), updated_at=NOW()");
      foreach ($prices as $productId => $rawPrice) {
        $productId = (int)$productId;
        if ($productId <= 0) continue;
        $raw = trim(str_replace(['.', ','], ['', '.'], (string)$rawPrice));
        if ($raw === '') {
          $raw = '0';
        }
        $price = max(0, (float)$raw);
        $isActive = isset($active[$productId]) ? 1 : 0;
        $upsert->execute([$branchId, $productId, $price, $isActive]);
      }
      $db->commit();
      $ok = 'Harga produk cabang berhasil disimpan.';
    } catch (Throwable $e) {
      if ($db->inTransaction()) $db->rollBack();
      $err = 'Gagal menyimpan harga cabang: ' . $e->getMessage();
    }
  }
}

$stmt = db()->prepare("SELECT p.id, p.name, p.category, p.price AS default_price, p.product_type, p.show_on_pos,
       bpp.price AS branch_price, bpp.is_active AS branch_price_active
  FROM products p
  LEFT JOIN branch_product_prices bpp ON bpp.product_id = p.id AND bpp.branch_id = ?
 WHERE COALESCE(p.product_type, 'finished_good') = 'finished_good'
 ORDER BY COALESCE(p.category,''), p.name ASC");
$stmt->execute([$branchId]);
$products = $stmt->fetchAll(PDO::FETCH_ASSOC);

$canEdit = has_menu_access($u, 'branch_prices', 'edit');
$customCss = setting('custom_css', '');
?>
<!doctype html>
<html>
<head>
  <meta charset="utf-8">
  <meta name="viewport" content="width=device-width,initial-scale=1">
  <title>Harga Produk Cabang</title>
  <link rel="icon" href="<?php echo e(favicon_url()); ?>">
  <link rel="stylesheet" href="<?php echo e(asset_url('assets/app.css')); ?>">
  <style><?php echo $customCss; ?></style>
</head>
<body class="desktop-compact">
<div class="container">
  <?php include __DIR__ . '/partials_sidebar.php'; ?>
  <div class="main">
    <div class="topbar"><button class="btn" data-toggle-sidebar type="button">Menu</button></div>
    <div class="content">
      <div class="card">
        <h3 style="margin-top:0">Harga Produk Cabang</h3>
        <p><small>Master produk tetap dari Admin. Halaman ini hanya menentukan harga jual finished good untuk cabang/dapur yang dipilih. Jika harga cabang nonaktif, POS memakai harga default produk.</small></p>
        <?php if ($err): ?><div class="card" style="border-color:#fca5a5;background:#fef2f2"><?php echo e($err); ?></div><?php endif; ?>
        <?php if ($ok): ?><div class="card" style="border-color:#86efac;background:#ecfdf5"><?php echo e($ok); ?></div><?php endif; ?>
        <form method="get" class="grid cols-3" style="align-items:end">
          <div class="row"><label>Cabang / Dapur</label><select name="branch_id">
            <?php foreach ($branches as $b): ?><option value="<?php echo e((string)$b['id']); ?>" <?php echo (int)$b['id']===$branchId?'selected':''; ?>><?php echo e($b['branch_name']); ?></option><?php endforeach; ?>
          </select></div>
          <div class="row"><button class="btn" type="submit">Tampilkan</button></div>
        </form>
      </div>

      <div class="card">
        <form method="post">
          <input type="hidden" name="_csrf" value="<?php echo e(csrf_token()); ?>">
          <input type="hidden" name="action" value="save_prices">
          <input type="hidden" name="branch_id" value="<?php echo e((string)$branchId); ?>">
          <div class="table-wrap"><table class="table">
            <thead><tr><th>Produk</th><th>Kategori</th><th>Harga Default Admin</th><th>Harga Cabang</th><th>Aktif</th><th>Harga Dipakai</th></tr></thead>
            <tbody>
              <?php foreach ($products as $p): ?>
                <?php
                  $hasBranchPrice = $p['branch_price'] !== null;
                  $active = $hasBranchPrice ? ((int)$p['branch_price_active'] === 1) : false;
                  $usedPrice = $active ? (float)$p['branch_price'] : (float)$p['default_price'];
                ?>
                <tr>
                  <td><?php echo e($p['name']); ?></td>
                  <td><?php echo e($p['category'] ?: '-'); ?></td>
                  <td>Rp <?php echo e(format_money((float)$p['default_price'])); ?></td>
                  <td><input type="number" min="0" step="1" name="prices[<?php echo e((string)$p['id']); ?>]" value="<?php echo e((string)(int)round((float)($p['branch_price'] ?? $p['default_price']))); ?>" <?php echo $canEdit ? '' : 'readonly'; ?>></td>
                  <td><label class="checkbox-row"><input type="checkbox" name="active[<?php echo e((string)$p['id']); ?>]" value="1" <?php echo $active?'checked':''; ?> <?php echo $canEdit ? '' : 'disabled'; ?>> Pakai harga cabang</label></td>
                  <td><strong>Rp <?php echo e(format_money($usedPrice)); ?></strong></td>
                </tr>
              <?php endforeach; ?>
            </tbody>
          </table></div>
          <?php if ($canEdit): ?><div style="margin-top:12px"><button class="btn" type="submit">Simpan Harga Cabang</button></div><?php endif; ?>
        </form>
      </div>
    </div>
  </div>
</div>
<script defer src="<?php echo e(asset_url('assets/app.js')); ?>"></script>
</body>
</html>
