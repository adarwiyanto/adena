<?php
require_once __DIR__ . '/../core/db.php';
require_once __DIR__ . '/../core/functions.php';
require_once __DIR__ . '/../core/security.php';
require_once __DIR__ . '/../core/auth.php';
require_once __DIR__ . '/../core/csrf.php';
require_once __DIR__ . '/../core/rbac.php';
require_once __DIR__ . '/../core/inventory.php';

start_secure_session();
require_admin();
ensure_rbac_schema();
$u = require_menu_access('purchase', 'view');
ensure_inventory_module_schema();

$err='';
$branches = inventory_branches();
$suppliers = db()->query("SELECT id,supplier_name FROM suppliers WHERE is_active=1 ORDER BY supplier_name ASC")->fetchAll();
$products = db()->query("SELECT id,name,base_unit,purchase_unit,purchase_to_base_factor FROM products WHERE product_type<>'raw_material' ORDER BY name ASC")->fetchAll();

function general_purchase_items(array $src): array {
  $productIds = $src['item_product_id'] ?? [];
  $names = $src['item_name'] ?? [];
  $qtys = $src['item_qty'] ?? [];
  $costs = $src['item_unit_cost'] ?? [];
  $notes = $src['item_notes'] ?? [];
  foreach (['productIds','names','qtys','costs','notes'] as $v) if (!is_array($$v)) $$v=[];
  $items=[]; $max=max(count($productIds),count($names),count($qtys),count($costs),count($notes));
  for($i=0;$i<$max;$i++){
    $pid=(int)($productIds[$i]??0);
    $name=trim((string)($names[$i]??''));
    $qty=parse_number_input($qtys[$i]??0);
    $cost=parse_number_input($costs[$i]??0);
    $note=trim((string)($notes[$i]??''));
    if($pid<=0 && $name==='' && $qty<=0 && $cost<=0 && $note==='') continue;
    if($pid<=0 && $name==='') throw new Exception('Isi nama barang manual bila produk dikosongkan.');
    if($qty<=0 || $cost<0) throw new Exception('Qty/harga tidak valid.');
    $items[]=['product_id'=>$pid>0?$pid:null,'item_name'=>$name,'qty'=>$qty,'unit_cost'=>$cost,'line_total'=>$qty*$cost,'notes'=>$note];
  }
  if(!$items) throw new Exception('Minimal 1 item pembelian wajib diisi.');
  return $items;
}

if($_SERVER['REQUEST_METHOD']==='POST'){
  csrf_check();
  $action=(string)($_POST['action']??'create');
  try{
    $db=db();
    if($action==='create'){
      $purchaseNo=trim((string)($_POST['purchase_no']??''));
      $branchId=(int)($_POST['branch_id']??active_branch_id());
      $supplierId=(int)($_POST['supplier_id']??0);
      $date=(string)($_POST['purchase_date']??date('Y-m-d'));
      $notes=trim((string)($_POST['notes']??''));
      $items=general_purchase_items($_POST);
      if($purchaseNo==='') throw new Exception('Nomor pembelian wajib.');
      if($supplierId<=0) throw new Exception('Supplier wajib dipilih.');
      $subtotal=array_sum(array_map(fn($it)=>(float)$it['line_total'],$items));
      $db->beginTransaction();
      $stmt=$db->prepare("INSERT INTO purchase_headers (branch_id,supplier_id,purchase_no,purchase_date,purchase_type,status,subtotal,grand_total,notes,created_by) VALUES (?,?,?,?, 'general','draft',?,?,?,?)");
      $stmt->execute([$branchId,$supplierId,$purchaseNo,$date,$subtotal,$subtotal,$notes,(int)($u['id']??0)]);
      $pid=(int)$db->lastInsertId();
      $ins=$db->prepare("INSERT INTO purchase_items (purchase_id,product_id,item_name,qty,unit_cost,line_total,notes) VALUES (?,?,?,?,?,?,?)");
      foreach($items as $it) $ins->execute([$pid,$it['product_id'],$it['item_name'],$it['qty'],$it['unit_cost'],$it['line_total'],$it['notes']]);
      $db->commit(); redirect(base_url('admin/purchase_general.php'));
    }
    if($action==='post'){
      $id=(int)($_POST['id']??0); $db->beginTransaction();
      $stmt=$db->prepare("SELECT * FROM purchase_headers WHERE id=? AND purchase_type='general' LIMIT 1 FOR UPDATE"); $stmt->execute([$id]); $h=$stmt->fetch();
      if(!$h) throw new Exception('Dokumen tidak ditemukan.');
      if($h['status']!=='draft') throw new Exception('Hanya draft yang bisa diposting.');
      $stmt=$db->prepare("SELECT pi.*, p.purchase_to_base_factor FROM purchase_items pi LEFT JOIN products p ON p.id=pi.product_id WHERE pi.purchase_id=?"); $stmt->execute([$id]);
      foreach($stmt->fetchAll() as $it){
        if((int)($it['product_id']??0)>0){
          add_stock_ledger(['branch_id'=>(int)$h['branch_id'],'product_id'=>(int)$it['product_id'],'trans_type'=>'general_purchase_post','ref_table'=>'purchase_headers','ref_id'=>$id,'qty_in'=>(float)$it['qty']*max(0.000001,(float)($it['purchase_to_base_factor']??1)),'qty_out'=>0,'unit_cost'=>(float)$it['unit_cost'],'note'=>'Posting pembelian umum','created_by'=>(int)($u['id']??0)]);
        }
      }
      $db->prepare("UPDATE purchase_headers SET status='posted', posted_by=?, posted_at=NOW() WHERE id=?")->execute([(int)($u['id']??0),$id]);
      $db->commit(); redirect(base_url('admin/purchase_general.php'));
    }
    if($action==='cancel'){
      $id=(int)($_POST['id']??0); $db->beginTransaction();
      $stmt=$db->prepare("SELECT * FROM purchase_headers WHERE id=? AND purchase_type='general' LIMIT 1 FOR UPDATE"); $stmt->execute([$id]); $h=$stmt->fetch();
      if(!$h) throw new Exception('Dokumen tidak ditemukan.');
      if($h['status']==='cancelled') throw new Exception('Sudah cancelled.');
      if($h['status']==='posted'){
        $stmt=$db->prepare("SELECT pi.*, p.purchase_to_base_factor FROM purchase_items pi LEFT JOIN products p ON p.id=pi.product_id WHERE pi.purchase_id=?"); $stmt->execute([$id]);
        foreach($stmt->fetchAll() as $it){ if((int)($it['product_id']??0)>0) add_stock_ledger(['branch_id'=>(int)$h['branch_id'],'product_id'=>(int)$it['product_id'],'trans_type'=>'general_purchase_cancel','ref_table'=>'purchase_headers','ref_id'=>$id,'qty_in'=>0,'qty_out'=>(float)$it['qty']*max(0.000001,(float)($it['purchase_to_base_factor']??1)),'unit_cost'=>(float)$it['unit_cost'],'note'=>'Cancel pembelian umum','created_by'=>(int)($u['id']??0)]); }
      }
      $db->prepare("UPDATE purchase_headers SET status='cancelled' WHERE id=?")->execute([$id]); $db->commit(); redirect(base_url('admin/purchase_general.php'));
    }
  }catch(Throwable $e){ if(isset($db)&&$db->inTransaction())$db->rollBack(); $err=$e->getMessage(); }
}
$docs=db()->query("SELECT ph.*, b.branch_name, s.supplier_name FROM purchase_headers ph JOIN branches b ON b.id=ph.branch_id JOIN suppliers s ON s.id=ph.supplier_id WHERE ph.purchase_type='general' ORDER BY ph.id DESC LIMIT 100")->fetchAll();
$customCss=setting('custom_css','');
?>
<!doctype html><html><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"><title>Pembelian Umum</title><link rel="icon" href="<?php echo e(favicon_url()); ?>"><link rel="stylesheet" href="<?php echo e(asset_url('assets/app.css')); ?>"><style><?php echo $customCss; ?></style></head><body><div class="container"><?php include __DIR__.'/partials_sidebar.php'; ?><div class="main"><div class="topbar"><button class="btn" data-toggle-sidebar type="button">Menu</button></div><div class="content">
<div class="card"><h3>Pembelian Umum / Barang Harian</h3><p><small>Untuk toko/cabang: ATK, barang pihak ketiga, operasional harian, atau item bebas. Produk boleh dikosongkan dengan mengisi nama barang manual.</small></p><?php if($err): ?><div class="card" style="border-color:rgba(251,113,133,.35);background:rgba(251,113,133,.10)"><?php echo e($err); ?></div><?php endif; ?>
<form method="post" id="purchase-form"><input type="hidden" name="_csrf" value="<?php echo e(csrf_token()); ?>"><input type="hidden" name="action" value="create"><div class="grid cols-2"><div class="row"><label>No Pembelian</label><input name="purchase_no" required value="<?php echo e('PU-'.date('YmdHis')); ?>"></div><div class="row"><label>Tanggal</label><input type="date" name="purchase_date" required value="<?php echo e(date('Y-m-d')); ?>"></div></div><div class="grid cols-2"><div class="row"><label>Cabang/Toko</label><select name="branch_id"><?php foreach($branches as $b): ?><option value="<?php echo e((string)$b['id']); ?>" <?php echo (int)$b['id']===active_branch_id()?'selected':''; ?>><?php echo e($b['branch_name']); ?></option><?php endforeach; ?></select></div><div class="row"><label>Supplier/Pihak Ketiga</label><select name="supplier_id" required><?php foreach($suppliers as $s): ?><option value="<?php echo e((string)$s['id']); ?>"><?php echo e($s['supplier_name']); ?></option><?php endforeach; ?></select></div></div><div class="row"><label>Catatan</label><textarea name="notes" rows="2"></textarea></div>
<table class="table" id="items-table"><thead><tr><th>Produk Opsional</th><th>Nama Barang Manual</th><th style="width:110px">Qty</th><th style="width:140px">Harga</th><th style="width:140px">Subtotal</th><th>Catatan</th><th>Aksi</th></tr></thead><tbody><tr class="item-row"><td><select name="item_product_id[]"><option value="">- kosongkan bila item bebas -</option><?php foreach($products as $p): ?><option value="<?php echo e((string)$p['id']); ?>"><?php echo e($p['name']); ?></option><?php endforeach; ?></select></td><td><input name="item_name[]" placeholder="contoh: ATK / plastik / barang pihak ketiga"></td><td><input type="number" step="0.0001" min="0.0001" name="item_qty[]" value="1" required></td><td><input type="number" step="0.01" min="0" name="item_unit_cost[]" value="0" required></td><td><input class="line-total" readonly value="0.00"></td><td><input name="item_notes[]"></td><td><button class="btn danger btn-remove-item" type="button">Hapus</button></td></tr></tbody></table><button class="btn" id="btn-add-item" type="button">Tambah Item</button> <button class="btn" type="submit">Simpan Draft</button></form></div>
<div class="card"><h3>Riwayat Pembelian Umum</h3><table class="table"><thead><tr><th>No</th><th>Tanggal</th><th>Cabang</th><th>Supplier</th><th>Total</th><th>Status</th><th>Aksi</th></tr></thead><tbody><?php foreach($docs as $d): ?><tr><td><?php echo e($d['purchase_no']); ?></td><td><?php echo e($d['purchase_date']); ?></td><td><?php echo e($d['branch_name']); ?></td><td><?php echo e($d['supplier_name']); ?></td><td><?php echo e(format_money((float)$d['grand_total'])); ?></td><td><?php echo e($d['status']); ?></td><td style="display:flex;gap:6px;flex-wrap:wrap"><?php if($d['status']==='draft'): ?><form method="post"><input type="hidden" name="_csrf" value="<?php echo e(csrf_token()); ?>"><input type="hidden" name="action" value="post"><input type="hidden" name="id" value="<?php echo e((string)$d['id']); ?>"><button class="btn">Post</button></form><?php endif; ?><?php if($d['status']!=='cancelled'): ?><form method="post"><input type="hidden" name="_csrf" value="<?php echo e(csrf_token()); ?>"><input type="hidden" name="action" value="cancel"><input type="hidden" name="id" value="<?php echo e((string)$d['id']); ?>"><button class="btn danger">Cancel</button></form><?php endif; ?></td></tr><?php endforeach; ?></tbody></table></div>
</div></div></div><script>(function(){const tb=document.querySelector('#items-table tbody'),add=document.querySelector('#btn-add-item');function recalc(){tb.querySelectorAll('.item-row').forEach(r=>{const q=parseFloat(r.querySelector('[name="item_qty[]"]').value||0),c=parseFloat(r.querySelector('[name="item_unit_cost[]"]').value||0);r.querySelector('.line-total').value=(q*c).toFixed(2);});}function bind(r){r.querySelectorAll('input,select').forEach(el=>el.addEventListener('input',recalc));r.querySelector('.btn-remove-item').addEventListener('click',()=>{if(tb.querySelectorAll('.item-row').length<=1){alert('Minimal 1 item.');return;}r.remove();recalc();});}tb.querySelectorAll('.item-row').forEach(bind);add.addEventListener('click',()=>{const c=tb.querySelector('.item-row').cloneNode(true);c.querySelectorAll('input').forEach(i=>{i.value=i.name==='item_qty[]'?'1':(i.name==='item_unit_cost[]'?'0':'');});c.querySelector('select').value='';tb.appendChild(c);bind(c);recalc();});recalc();})();</script><script defer src="<?php echo e(asset_url('assets/app.js')); ?>"></script></body></html>
