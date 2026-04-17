<?php
require_once __DIR__ . '/../core/db.php'; require_once __DIR__ . '/../core/functions.php'; require_once __DIR__ . '/../core/security.php'; require_once __DIR__ . '/../core/auth.php'; require_once __DIR__ . '/../core/csrf.php'; require_once __DIR__ . '/../core/inventory.php';
start_secure_session(); require_admin(); ensure_inventory_module_schema();
$err=''; $u=current_user(); $branches=inventory_branches();
$boms=db()->query("SELECT bh.id,bh.bom_code,p.name finished_name,p.base_unit,p.purchase_unit,p.purchase_to_base_factor,p.sale_unit,p.sale_to_base_factor,bh.branch_id FROM bom_headers bh JOIN products p ON p.id=bh.finished_product_id WHERE bh.is_active=1 ORDER BY bh.id DESC")->fetchAll();
if($_SERVER['REQUEST_METHOD']==='POST'){ csrf_check(); $action=(string)($_POST['action']??'create'); try {
 if($action==='create'){
  $bomId=(int)($_POST['bom_id']??0); $branchId=(int)($_POST['branch_id']??active_branch_id()); $qty=parse_number_input($_POST['qty_to_produce']??0); $mode=(string)($_POST['mode_source']??'manual_menu');
  if($bomId<=0||$qty<=0) throw new Exception('Data produksi tidak valid.');
  $stmt=db()->prepare("SELECT * FROM bom_headers WHERE id=? AND is_active=1 LIMIT 1"); $stmt->execute([$bomId]); $bom=$stmt->fetch(); if(!$bom) throw new Exception('BOM tidak aktif.');
  if($bom['branch_id']!==null && (int)$bom['branch_id']!==$branchId) throw new Exception('BOM hanya untuk cabang tertentu.');
  $items=explode_bom_requirements($bomId,$qty);
  $db=db(); $db->beginTransaction();
  $id=create_production_with_items($db,[
    'production_no'=>'PRD-'.date('YmdHis').'-'.strtoupper(bin2hex(random_bytes(2))),
    'branch_id'=>$branchId,'bom_id'=>$bomId,'finished_product_id'=>(int)$bom['finished_product_id'],'production_date'=>date('Y-m-d'),'qty_to_produce'=>$qty,'status'=>'draft','mode_source'=>$mode,'created_by'=>(int)($u['id']??0)
  ],$items);
  if(setting('production_mode','auto')==='auto'){
    post_production($id,(int)($u['id']??0));
  }
  $db->commit(); redirect(base_url('admin/production.php'));
 }
 if($action==='post'){
  $id=(int)($_POST['id']??0); $db=db(); $db->beginTransaction(); post_production($id,(int)($u['id']??0)); $db->commit(); redirect(base_url('admin/production.php'));
 }
 if($action==='cancel'){
  $id=(int)($_POST['id']??0); $db=db(); $db->beginTransaction();
  $stmt=$db->prepare("SELECT * FROM production_headers WHERE id=? LIMIT 1 FOR UPDATE"); $stmt->execute([$id]); $h=$stmt->fetch(); if(!$h) throw new Exception('Dokumen tidak ditemukan.'); if($h['status']==='cancelled') throw new Exception('Sudah cancelled.');
  if($h['status']==='posted'){
    $stmt=$db->prepare("SELECT * FROM production_items WHERE production_id=?"); $stmt->execute([$id]); foreach($stmt->fetchAll() as $it){ add_stock_ledger(['branch_id'=>(int)$h['branch_id'],'product_id'=>(int)$it['material_product_id'],'trans_type'=>'production_cancel_consume','ref_table'=>'production_headers','ref_id'=>$id,'qty_in'=>(float)$it['actual_qty'],'qty_out'=>0,'note'=>'Reversal cancel produksi','created_by'=>(int)($u['id']??0)]); }
    add_stock_ledger(['branch_id'=>(int)$h['branch_id'],'product_id'=>(int)$h['finished_product_id'],'trans_type'=>'production_cancel_output','ref_table'=>'production_headers','ref_id'=>$id,'qty_in'=>0,'qty_out'=>(float)$h['qty_to_produce'],'note'=>'Reversal output produksi','created_by'=>(int)($u['id']??0)]);
  }
  $stmt=$db->prepare("UPDATE production_headers SET status='cancelled' WHERE id=?"); $stmt->execute([$id]); $db->commit(); redirect(base_url('admin/production.php'));
 }
} catch(Throwable $e){ if(isset($db)&&$db->inTransaction()) $db->rollBack(); $err=$e->getMessage(); }}
$rows=db()->query("SELECT ph.*, b.branch_name, p.name finished_name, p.base_unit, p.purchase_unit, p.purchase_to_base_factor, p.sale_unit, p.sale_to_base_factor, bh.bom_code FROM production_headers ph JOIN branches b ON b.id=ph.branch_id JOIN products p ON p.id=ph.finished_product_id JOIN bom_headers bh ON bh.id=ph.bom_id ORDER BY ph.id DESC LIMIT 100")->fetchAll(); $customCss=setting('custom_css','');
?>
<!doctype html><html><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"><title>Produksi</title><link rel="icon" href="<?php echo e(favicon_url()); ?>"><link rel="stylesheet" href="<?php echo e(asset_url('assets/app.css')); ?>"><style><?php echo $customCss; ?></style></head><body><div class="container"><?php include __DIR__ . '/partials_sidebar.php'; ?><div class="main"><div class="topbar"><button class="btn" data-toggle-sidebar type="button">Menu</button></div><div class="content"><div class="card"><h3>Produksi Finished Good</h3><?php if($err): ?><div class="card" style="border-color:rgba(251,113,133,.35);background:rgba(251,113,133,.10)"><?php echo e($err); ?></div><?php endif; ?><form method="post"><input type="hidden" name="_csrf" value="<?php echo e(csrf_token()); ?>"><input type="hidden" name="action" value="create"><div class="grid cols-3"><div class="row"><label>Cabang</label><select name="branch_id"><?php foreach($branches as $b): ?><option value="<?php echo e((string)$b['id']); ?>" <?php echo (int)$b['id']===active_branch_id()?'selected':''; ?>><?php echo e($b['branch_name']); ?></option><?php endforeach; ?></select></div><div class="row"><label>BOM Aktif</label><select name="bom_id" required><?php foreach($boms as $b): ?><option value="<?php echo e((string)$b['id']); ?>"><?php echo e($b['bom_code'].' - '.$b['finished_name']); ?></option><?php endforeach; ?></select></div><div class="row"><label>Qty Produce</label><input type="number" min="0.0001" step="0.0001" name="qty_to_produce" required></div></div><div class="row"><label>Mode Source</label><select name="mode_source"><option value="manual_menu">manual_menu</option><option value="pos_auto">pos_auto</option></select></div><button class="btn" type="submit">Simpan Produksi</button></form></div>
<div class="card"><table class="table"><thead><tr><th>No</th><th>Tanggal</th><th>Cabang</th><th>Produk</th><th>Qty</th><th>Mode</th><th>Status</th><th>Aksi</th></tr></thead><tbody><?php foreach($rows as $r): ?><tr><td><?php echo e($r['production_no']); ?></td><td><?php echo e($r['production_date']); ?></td><td><?php echo e($r['branch_name']); ?></td><td><?php echo e($r['finished_name']); ?></td><td><?php $um = product_unit_fallback($r); echo e(format_qty((float)$r['qty_to_produce'], $um['base_unit'])); ?></td><td><?php echo e($r['mode_source']); ?></td><td><?php echo e($r['status']); ?></td><td style="display:flex;gap:6px"><?php if($r['status']==='draft'): ?><form method="post"><input type="hidden" name="_csrf" value="<?php echo e(csrf_token()); ?>"><input type="hidden" name="action" value="post"><input type="hidden" name="id" value="<?php echo e((string)$r['id']); ?>"><button class="btn" type="submit">Post</button></form><?php endif; ?><?php if($r['status']!=='cancelled'): ?><form method="post"><input type="hidden" name="_csrf" value="<?php echo e(csrf_token()); ?>"><input type="hidden" name="action" value="cancel"><input type="hidden" name="id" value="<?php echo e((string)$r['id']); ?>"><button class="btn danger" type="submit">Cancel</button></form><?php endif; ?></td></tr><?php endforeach; ?></tbody></table></div>
</div></div></div></div><script defer src="<?php echo e(asset_url('assets/app.js')); ?>"></script></body></html>
