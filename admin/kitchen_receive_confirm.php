<?php
require_once __DIR__ . '/../core/db.php';
require_once __DIR__ . '/../core/functions.php';
require_once __DIR__ . '/../core/security.php';
require_once __DIR__ . '/../core/csrf.php';
require_once __DIR__ . '/../core/auth.php';
require_once __DIR__ . '/../core/rbac.php';
require_once __DIR__ . '/../core/inventory.php';

start_secure_session();
$u = require_menu_access('inventori');
if (!has_menu_access($u, 'inventori', 'approve') && !has_menu_access($u, 'inventori', 'edit') && !current_user_is_owner()) {
  redirect_to_best_allowed_page($u, 'menu:inventori:approve');
}
ensure_inventory_module_schema();
function kr_table_exists(string $table): bool { $st=db()->prepare('SHOW TABLES LIKE ?'); $st->execute([$table]); return (bool)$st->fetchColumn(); }
function kr_safe_exec(string $sql): void { try { db()->exec($sql); } catch (Throwable $e) {} }
function kr_ensure_tables(): void {
  $sql=file_get_contents(__DIR__.'/../db/toko_api_dapur_patch.sql');
  foreach(array_filter(array_map('trim', explode(';',$sql))) as $q){ if($q!=='') db()->exec($q); }
  kr_safe_exec("ALTER TABLE kitchen_api_receive_logs ADD COLUMN branch_id INT NULL AFTER token_id");
  kr_safe_exec("ALTER TABLE kitchen_api_receive_logs ADD COLUMN supplier_id INT NULL AFTER branch_id");
  kr_safe_exec("ALTER TABLE kitchen_api_receive_logs ADD COLUMN purchase_id INT NULL AFTER status");
  kr_safe_exec("ALTER TABLE kitchen_api_receive_logs ADD COLUMN purchase_no VARCHAR(80) NULL AFTER purchase_id");
  kr_safe_exec("ALTER TABLE kitchen_api_receive_logs ADD COLUMN confirmed_by INT NULL AFTER remote_ip");
  kr_safe_exec("ALTER TABLE kitchen_api_receive_logs ADD COLUMN confirmed_at DATETIME NULL AFTER confirmed_by");
  kr_safe_exec("ALTER TABLE kitchen_api_received_items ADD COLUMN qty_base DECIMAL(18,4) NOT NULL DEFAULT 0 AFTER qty");
  kr_safe_exec("ALTER TABLE kitchen_api_received_items ADD COLUMN unit_cost DECIMAL(18,2) DEFAULT 0 AFTER transfer_price");
  kr_safe_exec("ALTER TABLE kitchen_api_received_items ADD COLUMN line_total DECIMAL(18,2) DEFAULT 0 AFTER unit_cost");
}
function kr_supplier_id(): int {
  $st=db()->prepare('SELECT id FROM suppliers WHERE supplier_code=? LIMIT 1'); $st->execute(['DAPUR_ADENA']); $id=(int)$st->fetchColumn(); if($id>0) return $id;
  db()->prepare('INSERT INTO suppliers(supplier_code,supplier_name,is_active) VALUES(?,?,1)')->execute(['DAPUR_ADENA','Dapur Adena']); return (int)db()->lastInsertId();
}
function kr_purchase_no(string $transferNo, int $logId): string {
  $base=preg_replace('/[^A-Za-z0-9\-_.]/','-',$transferNo); $no='KD-'.substr($base,0,42);
  if(strlen($no)>50 || $no==='KD-') $no='KD-'.date('Ymd').'-'.$logId;
  $st=db()->prepare('SELECT id FROM purchase_headers WHERE purchase_no=? LIMIT 1'); $st->execute([$no]);
  return $st->fetchColumn() ? 'KD-'.date('Ymd').'-'.$logId : $no;
}
function kr_flash(string $message, string $type='ok'): void { $_SESSION['kitchen_receive_flash']=[$message,$type]; }
function kr_get_flash(): ?array { $f=$_SESSION['kitchen_receive_flash']??null; unset($_SESSION['kitchen_receive_flash']); return $f; }

kr_ensure_tables();
if ($_SERVER['REQUEST_METHOD']==='POST') {
  csrf_check();
  $action=(string)($_POST['action']??''); $id=(int)($_POST['id']??0);
  if ($action==='confirm' && $id>0) {
    try{
      db()->beginTransaction();
      $st=db()->prepare('SELECT * FROM kitchen_api_receive_logs WHERE id=? FOR UPDATE'); $st->execute([$id]); $log=$st->fetch(PDO::FETCH_ASSOC);
      if(!$log) throw new Exception('Transfer tidak ditemukan.');
      if((string)$log['status']==='confirmed') throw new Exception('Transfer ini sudah dikonfirmasi.');
      if((string)$log['status']!=='pending_confirmation') throw new Exception('Status transfer tidak bisa dikonfirmasi: '.$log['status']);
      $items=db()->prepare('SELECT * FROM kitchen_api_received_items WHERE log_id=? ORDER BY id'); $items->execute([$id]); $rows=$items->fetchAll(PDO::FETCH_ASSOC) ?: [];
      if(!$rows) throw new Exception('Item transfer kosong.');
      $payload=json_decode((string)($log['payload_json']??'{}'),true); if(!is_array($payload)) $payload=[];
      $purchaseDate=(string)($payload['transfer_date'] ?? date('Y-m-d')); if(!preg_match('/^\d{4}-\d{2}-\d{2}$/',$purchaseDate)) $purchaseDate=date('Y-m-d');
      $branchId=(int)($log['branch_id'] ?? 0); if($branchId<=0) $branchId=function_exists('active_branch_id')?max(1,(int)active_branch_id()):1;
      $supplierId=(int)($log['supplier_id'] ?? 0); if($supplierId<=0) $supplierId=kr_supplier_id();
      $purchaseNo=kr_purchase_no((string)$log['transfer_no'],$id); $total=0.0;
      foreach($rows as $r){ $total += (float)($r['line_total'] ?: ((float)$r['qty']*(float)$r['transfer_price'])); }
      $ph=db()->prepare("INSERT INTO purchase_headers (branch_id,supplier_id,purchase_no,purchase_date,purchase_type,status,subtotal,grand_total,notes,created_by,posted_by,posted_at) VALUES (?,?,?,?, 'general','posted',?,?,?,?,?,NOW())");
      $ph->execute([$branchId,$supplierId,$purchaseNo,$purchaseDate,$total,$total,'Konfirmasi penerimaan stok dari Dapur Adena '.($log['transfer_no']??''),(int)($u['id']??0),(int)($u['id']??0)]);
      $purchaseId=(int)db()->lastInsertId();
      $pi=db()->prepare('INSERT INTO purchase_items (purchase_id,product_id,item_name,qty,unit_cost,line_total,notes) VALUES (?,?,?,?,?,?,?)');
      $mark=db()->prepare("UPDATE products SET track_stock=1, allow_direct_purchase=1 WHERE id=? AND product_type='finished_good'");
      foreach($rows as $r){
        $productId=(int)$r['product_id']; $qty=(float)$r['qty']; $qtyBase=(float)($r['qty_base'] ?: $qty); $unitCost=(float)($r['unit_cost'] ?: $r['transfer_price']); $line=(float)($r['line_total'] ?: $qty*$unitCost);
        $mark->execute([$productId]);
        $pi->execute([$purchaseId,$productId,(string)$r['product_name'],$qty,$unitCost,$line,'Transfer dari Dapur Adena '.($log['transfer_no']??'')]);
        add_stock_ledger(['branch_id'=>$branchId,'product_id'=>$productId,'trans_type'=>'receive_from_kitchen','ref_table'=>'purchase_headers','ref_id'=>$purchaseId,'qty_in'=>$qtyBase,'qty_out'=>0,'unit_cost'=>$unitCost,'note'=>'Penerimaan Dapur Adena '.$purchaseNo,'created_by'=>(int)($u['id']??0)]);
      }
      db()->prepare('UPDATE kitchen_api_receive_logs SET status=?, purchase_id=?, purchase_no=?, message=?, confirmed_by=?, confirmed_at=NOW() WHERE id=?')->execute(['confirmed',$purchaseId,$purchaseNo,'Stok dikonfirmasi manager toko dan masuk pembelian '.$purchaseNo,(int)($u['id']??0),$id]);
      db()->commit(); kr_flash('Penerimaan stok dikonfirmasi. Stok sudah masuk: '.$purchaseNo,'ok');
    }catch(Throwable $e){ if(db()->inTransaction()) db()->rollBack(); kr_flash('Gagal konfirmasi: '.$e->getMessage(),'err'); }
    redirect(base_url('admin/kitchen_receive_confirm.php'));
  }
}

$status=trim((string)($_GET['status']??'pending_confirmation'));
$where=''; $params=[];
if($status!=='all'){ $where='WHERE l.status=?'; $params[]=$status; }
$stmt=db()->prepare("SELECT l.*, b.branch_name, u.name confirmed_name FROM kitchen_api_receive_logs l LEFT JOIN branches b ON b.id=l.branch_id LEFT JOIN users u ON u.id=l.confirmed_by $where ORDER BY l.id DESC LIMIT 100");
$stmt->execute($params); $logs=$stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
$itemsByLog=[]; if($logs){ $ids=array_map(fn($r)=>(int)$r['id'],$logs); $in=implode(',',array_fill(0,count($ids),'?')); $it=db()->prepare("SELECT * FROM kitchen_api_received_items WHERE log_id IN ($in) ORDER BY log_id,id"); $it->execute($ids); foreach($it->fetchAll(PDO::FETCH_ASSOC) ?: [] as $r){ $itemsByLog[(int)$r['log_id']][]=$r; } }
$f=kr_get_flash(); $customCss=setting('custom_css','');
?>
<!doctype html><html><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"><title>Konfirmasi Stok Dapur</title><link rel="icon" href="<?php echo e(favicon_url()); ?>"><link rel="stylesheet" href="<?php echo e(asset_url('assets/app.css')); ?>"><style><?php echo $customCss; ?>.receive-page{max-width:1500px;margin:0 auto;padding:18px 22px 34px}.receive-card{margin-bottom:14px}.receive-log-head{display:flex;justify-content:space-between;gap:12px;align-items:flex-start}.receive-meta{color:#64748b;font-size:13px}.receive-table-wrap{overflow:auto;border:1px solid #e5e7eb;border-radius:14px}.receive-table-wrap table{min-width:880px;margin:0}.badge-pending{background:#fff7ed;color:#9a3412}.badge-confirmed{background:#ecfdf5;color:#166534}.badge-failed{background:#fff1f2;color:#9f1239}@media(max-width:700px){.receive-page{padding:12px}.receive-log-head{display:block}}</style></head><body><div class="container"><?php include __DIR__ . '/partials_sidebar.php'; ?><div class="main"><div class="topbar"><button class="btn" data-toggle-sidebar type="button">Menu</button><strong>Konfirmasi Stok Dapur</strong></div><div class="content receive-page">
<?php if($f): ?><div class="card" style="border-color:<?php echo $f[1]==='err'?'#fecdd3':'#bbf7d0'; ?>;background:<?php echo $f[1]==='err'?'#fff1f2':'#f0fdf4'; ?>"><?php echo e($f[0]); ?></div><?php endif; ?>
<div class="card receive-card"><h3>Konfirmasi Penerimaan Stok dari Dapur Adena</h3><p class="receive-meta">Transfer yang masuk dari API Dapur tidak langsung menambah stok. Manager toko/admin perlu konfirmasi di sini.</p><form method="get" class="actions"><label>Status <select name="status"><option value="pending_confirmation" <?php echo $status==='pending_confirmation'?'selected':''; ?>>Pending</option><option value="confirmed" <?php echo $status==='confirmed'?'selected':''; ?>>Confirmed</option><option value="failed" <?php echo $status==='failed'?'selected':''; ?>>Failed</option><option value="all" <?php echo $status==='all'?'selected':''; ?>>Semua</option></select></label><button class="btn light" type="submit">Filter</button></form></div>
<?php if(!$logs): ?><div class="card">Tidak ada data penerimaan stok untuk filter ini.</div><?php endif; ?>
<?php foreach($logs as $log): $items=$itemsByLog[(int)$log['id']]??[]; $grand=0; foreach($items as $it) $grand+=(float)($it['line_total'] ?: ((float)$it['qty']*(float)$it['transfer_price'])); $cls='badge-pending'; if($log['status']==='confirmed') $cls='badge-confirmed'; elseif($log['status']==='failed') $cls='badge-failed'; ?>
<div class="card receive-card"><div class="receive-log-head"><div><h3 style="margin:0"><?php echo e((string)$log['transfer_no']); ?> <span class="badge <?php echo e($cls); ?>"><?php echo e((string)$log['status']); ?></span></h3><div class="receive-meta">Cabang: <?php echo e($log['branch_name'] ?? '-'); ?> • Masuk: <?php echo e((string)$log['created_at']); ?><?php if($log['confirmed_at']): ?> • Konfirmasi: <?php echo e((string)$log['confirmed_at']); ?> oleh <?php echo e($log['confirmed_name'] ?? '-'); ?><?php endif; ?></div><div class="receive-meta"><?php echo e((string)($log['message'] ?? '')); ?></div></div><div><strong><?php echo rupiah($grand); ?></strong><?php if($log['status']==='pending_confirmation'): ?><form method="post" style="margin-top:8px" onsubmit="return confirm('Konfirmasi stok diterima dan masukkan ke stok toko?')"><input type="hidden" name="_csrf" value="<?php echo e(csrf_token()); ?>"><input type="hidden" name="action" value="confirm"><input type="hidden" name="id" value="<?php echo (int)$log['id']; ?>"><button class="btn" type="submit">Konfirmasi Terima</button></form><?php endif; ?></div></div><div class="receive-table-wrap" style="margin-top:12px"><table class="table"><thead><tr><th>Produk</th><th>SKU</th><th>Qty</th><th>Unit</th><th>Harga</th><th>Subtotal</th></tr></thead><tbody><?php foreach($items as $it): $sub=(float)($it['line_total'] ?: ((float)$it['qty']*(float)$it['transfer_price'])); ?><tr><td><?php echo e((string)$it['product_name']); ?></td><td><?php echo e((string)$it['sku']); ?></td><td><?php echo number_format((float)$it['qty'],4,',','.'); ?></td><td><?php echo e((string)$it['unit']); ?></td><td><?php echo rupiah($it['transfer_price']); ?></td><td><?php echo rupiah($sub); ?></td></tr><?php endforeach; ?></tbody></table></div></div>
<?php endforeach; ?>
</div></div></div><script defer src="<?php echo e(asset_url('assets/app.js')); ?>"></script></body></html>
