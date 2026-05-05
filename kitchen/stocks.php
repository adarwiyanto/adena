<?php
require_once __DIR__ . '/../core/ops14.php';
require_once __DIR__ . '/../core/portal_inventory.php';
require_once __DIR__ . '/_layout.php';
$u = adena14_area_guard('kitchen');
$customCss = setting('custom_css','');
$search = trim((string)($_GET['search'] ?? ''));
$type = (string)($_GET['type'] ?? 'all');
if (!in_array($type, ['all','raw','finished'], true)) $type = 'all';
$err = ''; $rows = [];
try { $rows = portal_inventory_stock_rows(portal_inventory_kitchen_location_id(), $search, $type); }
catch (Throwable $e) { $err = $e->getMessage(); }
kitchen_header('Stok Dapur', $customCss);
?>
<div class="card"><h3>Daftar Stok Dapur</h3><p class="portal-note">Stok dibaca dari <strong>stock_locations</strong> tipe kitchen. Tidak lompat ke halaman Admin.</p><?php if($err): ?><div class="card" style="border-color:#fecdd3;background:#fff1f2;color:#9f1239"><?php echo e($err); ?></div><?php endif; ?><form method="get" class="grid cols-3"><div class="row"><label>Search</label><input name="search" value="<?php echo e($search); ?>" placeholder="Nama/kategori/kode"></div><div class="row"><label>Jenis Produk</label><select name="type"><option value="all" <?php echo $type==='all'?'selected':''; ?>>Semua</option><option value="raw" <?php echo $type==='raw'?'selected':''; ?>>Bahan baku</option><option value="finished" <?php echo $type==='finished'?'selected':''; ?>>Finished goods</option></select></div><div class="row" style="align-self:end"><button class="btn" type="submit">Filter</button></div></form></div>
<div class="card"><table class="table"><thead><tr><th>Produk</th><th>Kategori</th><th>Jenis</th><th style="text-align:right">Stok Dapur</th></tr></thead><tbody><?php if(empty($rows)): ?><tr><td colspan="4" style="text-align:center;color:#94a3b8">Data tidak ditemukan.</td></tr><?php else: foreach($rows as $r): $unit=product_unit_fallback($r); ?><tr><td><?php echo e((string)$r['name']); ?><br><small>ID: <?php echo e((string)$r['id']); ?></small></td><td><?php echo e((string)($r['category'] ?? '-')); ?></td><td><?php echo e((string)$r['product_type']); ?></td><td style="text-align:right;font-weight:800"><?php echo e(format_qty((float)$r['stock_qty'], $unit['base_unit'])); ?></td></tr><?php endforeach; endif; ?></tbody></table></div>
<?php kitchen_footer(); ?>
