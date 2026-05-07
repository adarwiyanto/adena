<?php
require_once __DIR__ . '/../core/db.php';
require_once __DIR__ . '/../core/functions.php';
require_once __DIR__ . '/../core/security.php';
require_once __DIR__ . '/../core/auth.php';
require_once __DIR__ . '/../core/csrf.php';
require_once __DIR__ . '/../core/rbac.php';
require_once __DIR__ . '/../core/branch_portal.php';

start_secure_session();
$me = require_menu_access('produk', 'view');
$roleKey = (string)(resolve_user_role($me)['role_key'] ?? '');
if (!in_array($roleKey, ['owner','admin'], true)) redirect(base_url('admin/dashboard.php'));
ensure_branch_price_schema();
$ok = '';
$err = '';
$branchId = (int)($_GET['branch_id'] ?? $_POST['branch_id'] ?? setting('active_branch_id','1'));
$branches = table_exists('branches') ? db()->query("SELECT id, branch_code, branch_name FROM branches WHERE is_active=1 ORDER BY branch_name")->fetchAll(PDO::FETCH_ASSOC) : [];
if ($branchId <= 0 && $branches) $branchId = (int)$branches[0]['id'];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
  csrf_check();
  $prices = (array)($_POST['prices'] ?? []);
  $active = (array)($_POST['active'] ?? []);
  $db = db();
  try {
    $db->beginTransaction();
    foreach ($prices as $productId => $rawPrice) {
      $pid = (int)$productId;
      if ($pid <= 0) continue;
      $price = parse_number_input($rawPrice);
      $isActive = isset($active[$pid]) ? 1 : 0;
      $stmt = $db->prepare("INSERT INTO branch_product_prices (branch_id, product_id, price, is_active)
        VALUES (?, ?, ?, ?)
        ON DUPLICATE KEY UPDATE price=VALUES(price), is_active=VALUES(is_active), updated_at=CURRENT_TIMESTAMP");
      $stmt->execute([$branchId, $pid, $price, $isActive]);
    }
    $db->commit();
    $ok = 'Harga cabang berhasil disimpan.';
  } catch (Throwable $e) {
    if ($db->inTransaction()) $db->rollBack();
    $err = 'Gagal menyimpan harga cabang: ' . $e->getMessage();
  }
}

$stmt = db()->prepare("SELECT p.id, p.name, p.price AS default_price, p.category,
                              bpp.price AS branch_price, COALESCE(bpp.is_active,0) AS branch_price_active
                       FROM products p
                       LEFT JOIN branch_product_prices bpp ON bpp.product_id=p.id AND bpp.branch_id=?
                       WHERE COALESCE(p.show_on_pos,1)=1
                       ORDER BY p.category, p.name");
$stmt->execute([$branchId]);
$products = $stmt->fetchAll(PDO::FETCH_ASSOC);
$customCss = setting('custom_css','');
?>
<!doctype html><html><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1">
<title>Harga Produk per Cabang</title><link rel="icon" href="<?php echo e(favicon_url()); ?>"><link rel="stylesheet" href="<?php echo e(asset_url('assets/app.css')); ?>"><style><?php echo $customCss; ?></style></head>
<body><div class="container"><?php include __DIR__ . '/partials_sidebar.php'; ?><div class="main"><div class="topbar"><button class="btn" data-toggle-sidebar type="button">Menu</button></div><div class="content">
<div class="card"><h3 style="margin-top:0">Harga Produk per Cabang</h3>
<p><small>Jika harga cabang aktif, POS Desktop akan menerima harga ini melalui sync. Jika tidak aktif/kosong, sistem fallback ke harga default produk.</small></p>
<?php if ($ok): ?><div class="card" style="border-color:#86efac;background:#ecfdf5"><?php echo e($ok); ?></div><?php endif; ?>
<?php if ($err): ?><div class="card" style="border-color:#fca5a5;background:#fef2f2"><?php echo e($err); ?></div><?php endif; ?>
<form method="get" class="card" style="display:flex;gap:12px;align-items:end;flex-wrap:wrap">
  <div class="row" style="margin:0"><label>Cabang</label><select name="branch_id" onchange="this.form.submit()">
    <?php foreach ($branches as $b): ?><option value="<?php echo e((string)$b['id']); ?>" <?php echo ((int)$b['id']===$branchId)?'selected':''; ?>><?php echo e(($b['branch_code'] ?? '') . ' - ' . ($b['branch_name'] ?? '')); ?></option><?php endforeach; ?>
  </select></div>
  <noscript><button class="btn" type="submit">Pilih</button></noscript>
</form>
<form method="post"><input type="hidden" name="_csrf" value="<?php echo e(csrf_token()); ?>"><input type="hidden" name="branch_id" value="<?php echo e((string)$branchId); ?>">
<div class="table-wrap"><table><thead><tr><th>Produk</th><th>Kategori</th><th>Harga Default</th><th>Harga Cabang</th><th>Aktif</th></tr></thead><tbody>
<?php foreach ($products as $p): $pid=(int)$p['id']; ?>
<tr><td><?php echo e($p['name']); ?></td><td><?php echo e((string)($p['category'] ?? '-')); ?></td><td>Rp <?php echo e(format_money($p['default_price'])); ?></td>
<td><input type="text" name="prices[<?php echo $pid; ?>]" value="<?php echo e(format_money($p['branch_price'] ?? $p['default_price'])); ?>" style="max-width:150px"></td>
<td><label><input type="checkbox" name="active[<?php echo $pid; ?>]" value="1" <?php echo ((int)$p['branch_price_active']===1)?'checked':''; ?>> Pakai harga cabang</label></td></tr>
<?php endforeach; ?>
</tbody></table></div><button class="btn" type="submit" style="margin-top:12px">Simpan Harga Cabang</button></form>
</div></div></div></div><script src="<?php echo e(asset_url('assets/app.js')); ?>"></script></body></html>
