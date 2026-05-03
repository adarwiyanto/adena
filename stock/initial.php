<?php
require_once __DIR__ . '/../core/db.php';
require_once __DIR__ . '/../core/functions.php';
require_once __DIR__ . '/../core/security.php';
require_once __DIR__ . '/../core/auth.php';
require_once __DIR__ . '/../core/csrf.php';
require_once __DIR__ . '/../core/rbac.php';
require_once __DIR__ . '/../core/inventory.php';
require_once __DIR__ . '/../core/ops14.php';
$u=adena14_area_guard('initial_stock'); ensure_inventory_module_schema(); csrf_token(); $err=''; $msg='';
$locations=adena14_locations(); $products=db()->query("SELECT id,name,base_unit FROM products WHERE track_stock=1 ORDER BY name")->fetchAll(PDO::FETCH_ASSOC);
if($_SERVER['REQUEST_METHOD']==='POST'){
  try{ csrf_check(); $loc=(int)($_POST['location_id']??0); $pid=(int)($_POST['product_id']??0); $qty=parse_number_input($_POST['qty']??0); $cost=parse_number_input($_POST['unit_cost']??0); $note=trim((string)($_POST['note']??''));
    if($loc<=0||$pid<=0||$qty<0) throw new Exception('Lokasi, produk, dan qty wajib valid.');
    $exists=db()->prepare("SELECT id,status FROM initial_stock_entries WHERE location_id=? AND product_id=? LIMIT 1"); $exists->execute([$loc,$pid]); $row=$exists->fetch(PDO::FETCH_ASSOC);
    if($row && !current_user_is_owner()) throw new Exception('Stok awal untuk produk/lokasi ini sudah pernah diinput. Perubahan berikutnya melalui stok opname atau approval owner.');
    db()->beginTransaction();
    if($row && current_user_is_owner()){
      db()->prepare("UPDATE initial_stock_entries SET qty=?,unit_cost=?,status='owner_override_approved',note=?,approved_by=?,approved_at=NOW() WHERE id=?")->execute([$qty,$cost,$note,(int)$u['id'],(int)$row['id']]); $entryId=(int)$row['id'];
    } else {
      db()->prepare("INSERT INTO initial_stock_entries (location_id,product_id,qty,unit_cost,status,note,created_by) VALUES (?,?,?,?, 'posted', ?, ?)")->execute([$loc,$pid,$qty,$cost,$note,(int)$u['id']]); $entryId=(int)db()->lastInsertId();
    }
    db()->prepare("INSERT INTO stock_ledger (branch_id,product_id,trans_type,ref_table,ref_id,qty_in,qty_out,unit_cost,note,created_by) VALUES (1,?,'initial_stock','initial_stock_entries',?,?,0,?,?,?)")->execute([$pid,$entryId,$qty,$cost,'Stok awal lokasi '.$loc,(int)$u['id']]);
    db()->commit(); $msg='Stok awal tersimpan.';
  }catch(Throwable $e){ if(db()->inTransaction()) db()->rollBack(); $err=$e->getMessage(); }
}
$rows=db()->query("SELECT ise.*, sl.location_name, p.name product_name FROM initial_stock_entries ise LEFT JOIN stock_locations sl ON sl.id=ise.location_id LEFT JOIN products p ON p.id=ise.product_id ORDER BY ise.id DESC LIMIT 50")->fetchAll(PDO::FETCH_ASSOC);
$customCss=setting('custom_css','');
?>
<!doctype html><html><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"><title>Stok Awal</title><link rel="stylesheet" href="<?php echo e(asset_url('assets/app.css')); ?>"><style><?php echo $customCss; ?></style></head><body><div class="container"><?php include __DIR__.'/../admin/partials_sidebar.php'; ?><div class="main"><div class="topbar"><button class="btn" data-toggle-sidebar type="button">Menu</button><strong>Stok Awal</strong></div><div class="content"><?php if($err): ?><div class="alert danger"><?php echo e($err); ?></div><?php endif; ?><?php if($msg): ?><div class="alert success"><?php echo e($msg); ?></div><?php endif; ?><div class="card"><h3>Input Stok Awal</h3><p style="color:#64748b">Hanya satu kali per produk per lokasi. Setelah itu gunakan stok opname. Owner dapat override bila diperlukan.</p><form method="post" class="grid cols-4"><?php echo csrf_field(); ?><div class="row"><label>Lokasi</label><select name="location_id" required><option value="">Pilih lokasi</option><?php foreach($locations as $l): ?><option value="<?php echo e((string)$l['id']); ?>"><?php echo e($l['location_name'].' - '.$l['location_type']); ?></option><?php endforeach; ?></select></div><div class="row"><label>Produk</label><select name="product_id" required><option value="">Pilih produk</option><?php foreach($products as $p): ?><option value="<?php echo e((string)$p['id']); ?>"><?php echo e($p['name']); ?></option><?php endforeach; ?></select></div><div class="row"><label>Qty</label><input name="qty" type="number" step="0.0001" min="0" required></div><div class="row"><label>Unit Cost</label><input name="unit_cost" type="number" step="0.01" min="0"></div><div class="row"><label>Catatan</label><input name="note"></div><div class="row" style="align-self:end"><button class="btn">Simpan Stok Awal</button></div></form></div><div class="card"><h3>Riwayat Stok Awal</h3><table class="table"><thead><tr><th>Lokasi</th><th>Produk</th><th>Qty</th><th>Status</th><th>Waktu</th></tr></thead><tbody><?php foreach($rows as $r): ?><tr><td><?php echo e($r['location_name']??'-'); ?></td><td><?php echo e($r['product_name']??'-'); ?></td><td><?php echo e(format_qty($r['qty'])); ?></td><td><?php echo e($r['status']); ?></td><td><?php echo e($r['created_at']); ?></td></tr><?php endforeach; ?></tbody></table></div></div></div></div><script src="<?php echo e(asset_url('assets/app.js')); ?>"></script></body></html>
