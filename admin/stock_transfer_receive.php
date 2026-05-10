<?php
require_once __DIR__ . '/../core/db.php';
require_once __DIR__ . '/../core/functions.php';
require_once __DIR__ . '/../core/security.php';
require_once __DIR__ . '/../core/auth.php';
require_once __DIR__ . '/../core/csrf.php';
require_once __DIR__ . '/../core/rbac.php';
require_once __DIR__ . '/../core/inventory.php';
require_once __DIR__ . '/../core/ops14.php';

start_secure_session();
require_admin();
ensure_rbac_schema();
$me = require_menu_access('inventori', 'view');
ensure_inventory_module_schema();
ensure_adena14_schema();
csrf_token();

$u=current_user() ?: [];
$err=''; $msg='';

function adena_transfer_schema_ready(?string &$reason = null): bool {
  try {
    $db = db();
    $must = [
      'stock_locations' => ['id','location_code','location_name','location_type','branch_id','is_active'],
      'stock_transfers' => ['id','transfer_no','from_location_id','to_location_id','status','sent_at','accepted_at','rejected_at','created_by','sent_by','received_by','notes','receiver_notes'],
      'stock_transfer_items' => ['id','transfer_id','product_id','qty','note'],
      'stock_ledger' => ['branch_id','location_id','product_id','trans_type','ref_table','ref_id','qty_in','qty_out']
    ];
    foreach ($must as $table => $cols) {
      $q = $db->prepare("SELECT COLUMN_NAME FROM INFORMATION_SCHEMA.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ?");
      $q->execute([$table]);
      $have = array_flip($q->fetchAll(PDO::FETCH_COLUMN) ?: []);
      if (!$have) { $reason = "Tabel {$table} belum ada."; return false; }
      foreach ($cols as $c) if (!isset($have[$c])) { $reason = "Kolom {$table}.{$c} belum ada."; return false; }
    }
    return true;
  } catch (Throwable $e) { $reason = $e->getMessage(); return false; }
}

function adena_receive_location_branch_id(array $loc): int {
  $bid=(int)($loc['branch_id'] ?? 0);
  return $bid>0 ? $bid : (int)(function_exists('active_branch_id') ? active_branch_id() : 1);
}

$schemaReason = '';
$schemaReady = adena_transfer_schema_ready($schemaReason);
if (!$schemaReady) { $err = 'Database Penerimaan Stok belum lengkap: '.$schemaReason.' Jalankan db/update_store_receive_v2_REPAIR.sql.'; }

if($schemaReady && $_SERVER['REQUEST_METHOD']==='POST'){
  try{
    csrf_check();
    $id=(int)($_POST['id']??0);
    $action=(string)($_POST['action']??'');
    $note=trim((string)($_POST['receiver_notes']??''));
    if($id<=0 || !in_array($action,['accept','reject'],true)) throw new Exception('Aksi penerimaan tidak valid.');
    $db=db();
    $tr=$db->prepare("SELECT st.*, tl.branch_id to_branch_id, tl.location_name to_name FROM stock_transfers st LEFT JOIN stock_locations tl ON tl.id=st.to_location_id WHERE st.id=? AND st.status='sent' LIMIT 1 FOR UPDATE");
    $db->beginTransaction();
    $tr->execute([$id]);
    $transfer=$tr->fetch(PDO::FETCH_ASSOC);
    if(!$transfer) throw new Exception('Transfer tidak ditemukan atau sudah diproses.');
    if($action==='reject'){
      $db->prepare("UPDATE stock_transfers SET status='rejected',rejected_at=NOW(),received_by=?,receiver_notes=? WHERE id=?")->execute([(int)($u['id']??0),$note,$id]);
      $msg='Transfer ditolak.';
    } else {
      $db->prepare("UPDATE stock_transfers SET status='accepted',accepted_at=NOW(),received_by=?,receiver_notes=? WHERE id=?")->execute([(int)($u['id']??0),$note,$id]);
      $items=$db->prepare("SELECT * FROM stock_transfer_items WHERE transfer_id=? ORDER BY id ASC");
      $items->execute([$id]);
      $ledger=$db->prepare("INSERT INTO stock_ledger (branch_id,location_id,product_id,trans_type,ref_table,ref_id,qty_in,qty_out,note,created_by) VALUES (?,?,?,?,?,?,?,0,?,?)");
      $branchId=(int)($transfer['to_branch_id'] ?? 0); if($branchId<=0) $branchId=(int)(function_exists('active_branch_id') ? active_branch_id() : 1);
      foreach($items->fetchAll(PDO::FETCH_ASSOC) as $it){
        $ledger->execute([$branchId,(int)$transfer['to_location_id'],(int)$it['product_id'],'transfer_in','stock_transfers',$id,(float)$it['qty'],'Transfer stok diterima '.$transfer['transfer_no'],(int)($u['id']??0)]);
      }
      $msg='Transfer diterima. Stok tujuan sudah bertambah.';
    }
    $db->commit();
  }catch(Throwable $e){ try { if(db()->inTransaction()) db()->rollBack(); } catch(Throwable $ignore) {} $err=$e->getMessage(); }
}

$rows=[];
if ($schemaReady) try{
  $rows=db()->query("SELECT st.*, fl.location_name from_name, tl.location_name to_name FROM stock_transfers st LEFT JOIN stock_locations fl ON fl.id=st.from_location_id LEFT JOIN stock_locations tl ON tl.id=st.to_location_id WHERE st.status='sent' ORDER BY st.id DESC LIMIT 100")->fetchAll(PDO::FETCH_ASSOC) ?: [];
}catch(Throwable $e){ $err=$err ?: $e->getMessage(); }
$customCss=setting('custom_css','');
?>
<!doctype html><html><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"><title>Penerimaan Stok</title><link rel="icon" href="<?php echo e(favicon_url()); ?>"><link rel="stylesheet" href="<?php echo e(asset_url('assets/app.css')); ?>"><style><?php echo $customCss; ?></style></head><body><div class="container"><?php include __DIR__ . '/partials_sidebar.php'; ?><div class="main"><div class="topbar"><button class="btn" data-toggle-sidebar type="button">Menu</button><strong>Penerimaan Stok dari Dapur</strong></div><div class="content"><?php if($err): ?><div class="alert danger"><?php echo e($err); ?></div><?php endif; ?><?php if($msg): ?><div class="alert success"><?php echo e($msg); ?></div><?php endif; ?><div class="card"><h3>Transfer Menunggu Penerimaan</h3><p style="color:#64748b">Approve bila barang sudah diterima toko/cabang. Stok tujuan baru bertambah setelah approve.</p><table class="table"><thead><tr><th>No</th><th>Dari</th><th>Tujuan</th><th>Catatan Kirim</th><th>Aksi</th></tr></thead><tbody><?php foreach($rows as $r): ?><tr><td><?php echo e($r['transfer_no']); ?></td><td><?php echo e($r['from_name'] ?? '-'); ?></td><td><?php echo e($r['to_name'] ?? '-'); ?></td><td><?php echo e($r['notes'] ?? ''); ?></td><td><form method="post" style="display:flex;gap:6px;flex-wrap:wrap;align-items:center"><?php echo csrf_field(); ?><input type="hidden" name="id" value="<?php echo e((string)$r['id']); ?>"><input name="receiver_notes" placeholder="Catatan penerimaan"><button class="btn" name="action" value="accept">Terima</button><button class="btn btn-danger" name="action" value="reject">Tolak</button></form></td></tr><?php endforeach; if(!$rows): ?><tr><td colspan="5" style="text-align:center;color:#94a3b8">Tidak ada transfer menunggu penerimaan.</td></tr><?php endif; ?></tbody></table></div></div></div></div><script src="<?php echo e(asset_url('assets/app.js')); ?>"></script></body></html>
