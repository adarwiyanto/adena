<?php
require_once __DIR__ . '/../core/db.php';
require_once __DIR__ . '/../core/functions.php';
require_once __DIR__ . '/../core/security.php';
require_once __DIR__ . '/../core/auth.php';
require_once __DIR__ . '/../core/csrf.php';
require_once __DIR__ . '/../core/rbac.php';
require_once __DIR__ . '/../core/inventory.php';
require_once __DIR__ . '/../core/ops14.php';
$u = adena14_area_guard('kitchen');
ensure_inventory_module_schema(); csrf_token();
$err=''; $msg=''; $locs=adena14_locations();
$kitchenId=0; foreach($locs as $l){ if(($l['location_type']??'')==='kitchen'){ $kitchenId=(int)$l['id']; break; }}
$products = db()->query("SELECT id,name,base_unit FROM products WHERE track_stock=1 AND product_type IN ('finished_good','raw_material','service') ORDER BY name")->fetchAll(PDO::FETCH_ASSOC);
if ($_SERVER['REQUEST_METHOD']==='POST') {
  try { csrf_check();
    $to=(int)($_POST['to_location_id']??0); $pid=(int)($_POST['product_id']??0); $qty=parse_number_input($_POST['qty']??0); $note=trim((string)($_POST['notes']??''));
    if($kitchenId<=0||$to<=0||$pid<=0||$qty<=0) throw new Exception('Tujuan, produk, dan qty wajib diisi.');
    $no='TRF-'.date('Ymd-His').'-'.strtoupper(substr(bin2hex(random_bytes(3)),0,6));
    db()->beginTransaction();
    $s=db()->prepare("INSERT INTO stock_transfers (transfer_no,from_location_id,to_location_id,status,sent_at,created_by,sent_by,notes) VALUES (?,?,?,'sent',NOW(),?,?,?)");
    $s->execute([$no,$kitchenId,$to,(int)$u['id'],(int)$u['id'],$note]); $tid=(int)db()->lastInsertId();
    db()->prepare("INSERT INTO stock_transfer_items (transfer_id,product_id,qty,note) VALUES (?,?,?,?)")->execute([$tid,$pid,$qty,$note]);
    db()->prepare("INSERT INTO stock_ledger (branch_id,product_id,trans_type,ref_table,ref_id,qty_in,qty_out,note,created_by) VALUES (1,?,'transfer_out','stock_transfers',?,0,?,?,?)")->execute([$pid,$tid,$qty,'Transfer keluar - menunggu approval penerima',(int)$u['id']]);
    db()->commit(); $msg='Transfer dikirim dan menunggu approval penerima.';
  } catch(Throwable $e){ if(db()->inTransaction()) db()->rollBack(); $err=$e->getMessage(); }
}
$rows=db()->query("SELECT st.*, fl.location_name from_name, tl.location_name to_name FROM stock_transfers st LEFT JOIN stock_locations fl ON fl.id=st.from_location_id LEFT JOIN stock_locations tl ON tl.id=st.to_location_id ORDER BY st.id DESC LIMIT 30")->fetchAll(PDO::FETCH_ASSOC);
$customCss=setting('custom_css','');
?>
<!doctype html><html><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"><title>Transfer Dapur</title><link rel="stylesheet" href="<?php echo e(asset_url('assets/app.css')); ?>"><style><?php echo $customCss; ?></style></head><body><div class="container"><?php include __DIR__.'/../admin/partials_sidebar.php'; ?><div class="main"><div class="topbar"><button class="btn" data-toggle-sidebar type="button">Menu</button><strong>Transfer Dapur</strong></div><div class="content">
<?php if($err): ?><div class="alert danger"><?php echo e($err); ?></div><?php endif; ?><?php if($msg): ?><div class="alert success"><?php echo e($msg); ?></div><?php endif; ?>
<div class="card"><h3>Buat Transfer Keluar</h3><form method="post" class="grid cols-4"><?php echo csrf_field(); ?><div class="row"><label>Tujuan</label><select name="to_location_id" required><option value="">Pilih tujuan</option><?php foreach($locs as $l): if((int)$l['id']===$kitchenId) continue; ?><option value="<?php echo e((string)$l['id']); ?>"><?php echo e($l['location_name'].' - '.$l['location_type']); ?></option><?php endforeach; ?></select></div><div class="row"><label>Produk</label><select name="product_id" required><option value="">Pilih produk</option><?php foreach($products as $p): ?><option value="<?php echo e((string)$p['id']); ?>"><?php echo e($p['name']); ?></option><?php endforeach; ?></select></div><div class="row"><label>Qty</label><input name="qty" type="number" step="0.0001" min="0" required></div><div class="row"><label>Catatan</label><input name="notes"></div><div class="row" style="align-self:end"><button class="btn">Kirim Transfer</button></div></form></div>
<div class="card"><h3>Riwayat Transfer</h3><table class="table"><thead><tr><th>No</th><th>Dari</th><th>Tujuan</th><th>Status</th><th>Tanggal</th></tr></thead><tbody><?php foreach($rows as $r): ?><tr><td><?php echo e($r['transfer_no']); ?></td><td><?php echo e($r['from_name']??'-'); ?></td><td><?php echo e($r['to_name']??'-'); ?></td><td><?php echo e($r['status']); ?></td><td><?php echo e($r['created_at']); ?></td></tr><?php endforeach; ?></tbody></table></div>
</div></div></div><script src="<?php echo e(asset_url('assets/app.js')); ?>"></script></body></html>
