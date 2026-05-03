<?php
require_once __DIR__ . '/../core/db.php';
require_once __DIR__ . '/../core/functions.php';
require_once __DIR__ . '/../core/security.php';
require_once __DIR__ . '/../core/auth.php';
require_once __DIR__ . '/../core/csrf.php';
require_once __DIR__ . '/../core/rbac.php';
require_once __DIR__ . '/../core/inventory.php';
require_once __DIR__ . '/../core/ops14.php';
$u=adena14_area_guard('branch'); ensure_inventory_module_schema(); csrf_token(); $err=''; $msg='';
if($_SERVER['REQUEST_METHOD']==='POST'){
  try{ csrf_check(); $id=(int)($_POST['id']??0); $action=(string)($_POST['action']??''); $note=trim((string)($_POST['receiver_notes']??'')); if($id<=0||!in_array($action,['accept','reject'],true)) throw new Exception('Aksi tidak valid.');
    $tr=db()->prepare("SELECT * FROM stock_transfers WHERE id=? AND status='sent'"); $tr->execute([$id]); $transfer=$tr->fetch(PDO::FETCH_ASSOC); if(!$transfer) throw new Exception('Transfer tidak ditemukan atau sudah diproses.');
    db()->beginTransaction();
    if($action==='accept'){
      db()->prepare("UPDATE stock_transfers SET status='accepted',accepted_at=NOW(),received_by=?,receiver_notes=? WHERE id=?")->execute([(int)$u['id'],$note,$id]);
      $items=db()->prepare("SELECT * FROM stock_transfer_items WHERE transfer_id=?"); $items->execute([$id]);
      foreach($items->fetchAll(PDO::FETCH_ASSOC) as $it){ db()->prepare("INSERT INTO stock_ledger (branch_id,product_id,trans_type,ref_table,ref_id,qty_in,qty_out,note,created_by) VALUES (1,?,'transfer_in','stock_transfers',?,?,0,?,?)")->execute([(int)$it['product_id'],$id,(float)$it['qty'],'Transfer diterima',(int)$u['id']]); }
      $msg='Transfer diterima dan stok masuk.';
    } else { db()->prepare("UPDATE stock_transfers SET status='rejected',rejected_at=NOW(),received_by=?,receiver_notes=? WHERE id=?")->execute([(int)$u['id'],$note,$id]); $msg='Transfer ditolak. Owner/admin bisa evaluasi.'; }
    db()->commit();
  }catch(Throwable $e){ if(db()->inTransaction()) db()->rollBack(); $err=$e->getMessage(); }
}
$rows=db()->query("SELECT st.*, fl.location_name from_name, tl.location_name to_name FROM stock_transfers st LEFT JOIN stock_locations fl ON fl.id=st.from_location_id LEFT JOIN stock_locations tl ON tl.id=st.to_location_id WHERE st.status='sent' ORDER BY st.id DESC")->fetchAll(PDO::FETCH_ASSOC);
$customCss=setting('custom_css','');
?>
<!doctype html><html><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"><title>Approval Transfer</title><link rel="stylesheet" href="<?php echo e(asset_url('assets/app.css')); ?>"><style><?php echo $customCss; ?></style></head><body><div class="container"><?php include __DIR__.'/../admin/partials_sidebar.php'; ?><div class="main"><div class="topbar"><button class="btn" data-toggle-sidebar type="button">Menu</button><strong>Approval Transfer Masuk</strong></div><div class="content"><?php if($err): ?><div class="alert danger"><?php echo e($err); ?></div><?php endif; ?><?php if($msg): ?><div class="alert success"><?php echo e($msg); ?></div><?php endif; ?><div class="card"><h3>Transfer Menunggu Approval</h3><table class="table"><thead><tr><th>No</th><th>Dari</th><th>Tujuan</th><th>Catatan</th><th>Aksi</th></tr></thead><tbody><?php if(!$rows): ?><tr><td colspan="5" style="text-align:center;color:#94a3b8">Tidak ada transfer menunggu approval.</td></tr><?php endif; foreach($rows as $r): ?><tr><td><?php echo e($r['transfer_no']); ?></td><td><?php echo e($r['from_name']??'-'); ?></td><td><?php echo e($r['to_name']??'-'); ?></td><td><?php echo e($r['notes']??''); ?></td><td><form method="post" style="display:flex;gap:6px;flex-wrap:wrap"><?php echo csrf_field(); ?><input type="hidden" name="id" value="<?php echo e((string)$r['id']); ?>"><input name="receiver_notes" placeholder="Catatan cek barang"><button class="btn" name="action" value="accept">Approve</button><button class="btn btn-light" name="action" value="reject">Tolak</button></form></td></tr><?php endforeach; ?></tbody></table></div></div></div></div><script src="<?php echo e(asset_url('assets/app.js')); ?>"></script></body></html>
