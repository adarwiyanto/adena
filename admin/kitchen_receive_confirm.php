<?php
require_once __DIR__ . '/../core/db.php';
require_once __DIR__ . '/../core/functions.php';
require_once __DIR__ . '/../core/security.php';
require_once __DIR__ . '/../core/csrf.php';
require_once __DIR__ . '/../core/auth.php';
require_once __DIR__ . '/../core/rbac.php';
require_once __DIR__ . '/../core/inventory.php';

start_secure_session();
require_admin();
$u = current_user() ?? [];
ensure_rbac_schema();
$role = resolve_user_role($u);
$roleKey = (string)($role['role_key'] ?? '');
$canKitchenReceive = in_array($roleKey, ['owner','admin','manager_cabang'], true)
  || has_menu_access($u, 'inventori', 'approve')
  || has_menu_access($u, 'inventori', 'edit');
if (!$canKitchenReceive) {
  redirect_to_best_allowed_page($u, 'menu:kitchen_receive_confirm');
}
ensure_inventory_module_schema();

function kr_safe_exec(string $sql): void { try { db()->exec($sql); } catch (Throwable $e) {} }
function kr_column_exists(string $table, string $column): bool {
  try { $st=db()->prepare("SHOW COLUMNS FROM `".str_replace('`','',$table)."` LIKE ?"); $st->execute([$column]); return (bool)$st->fetch(); } catch (Throwable $e) { return false; }
}
function kr_ensure_tables(): void {
  kr_safe_exec("CREATE TABLE IF NOT EXISTS kitchen_api_receive_logs (
    id BIGINT AUTO_INCREMENT PRIMARY KEY,
    token_id INT NULL,
    branch_id INT NULL,
    supplier_id INT NULL,
    transfer_no VARCHAR(80) NULL,
    endpoint VARCHAR(160) NULL,
    status VARCHAR(40) NOT NULL,
    purchase_id INT NULL,
    purchase_no VARCHAR(80) NULL,
    message TEXT NULL,
    payload_json LONGTEXT NULL,
    remote_ip VARCHAR(80) NULL,
    confirmed_by INT NULL,
    confirmed_at DATETIME NULL,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    UNIQUE KEY uq_transfer_no(transfer_no)
  ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
  kr_safe_exec("CREATE TABLE IF NOT EXISTS kitchen_api_received_items (
    id BIGINT AUTO_INCREMENT PRIMARY KEY,
    log_id BIGINT NOT NULL,
    product_id INT NOT NULL,
    sku VARCHAR(100) NULL,
    product_name VARCHAR(180) NULL,
    qty DECIMAL(18,4) NOT NULL DEFAULT 0,
    qty_base DECIMAL(18,4) NOT NULL DEFAULT 0,
    unit VARCHAR(50) NULL,
    transfer_price DECIMAL(18,2) DEFAULT 0,
    unit_cost DECIMAL(18,2) DEFAULT 0,
    line_total DECIMAL(18,2) DEFAULT 0,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    KEY idx_log_id(log_id)
  ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
  $cols = [
    ['kitchen_api_receive_logs','branch_id','ALTER TABLE kitchen_api_receive_logs ADD COLUMN branch_id INT NULL AFTER token_id'],
    ['kitchen_api_receive_logs','supplier_id','ALTER TABLE kitchen_api_receive_logs ADD COLUMN supplier_id INT NULL AFTER branch_id'],
    ['kitchen_api_receive_logs','purchase_id','ALTER TABLE kitchen_api_receive_logs ADD COLUMN purchase_id INT NULL AFTER status'],
    ['kitchen_api_receive_logs','purchase_no','ALTER TABLE kitchen_api_receive_logs ADD COLUMN purchase_no VARCHAR(80) NULL AFTER purchase_id'],
    ['kitchen_api_receive_logs','confirmed_by','ALTER TABLE kitchen_api_receive_logs ADD COLUMN confirmed_by INT NULL AFTER remote_ip'],
    ['kitchen_api_receive_logs','confirmed_at','ALTER TABLE kitchen_api_receive_logs ADD COLUMN confirmed_at DATETIME NULL AFTER confirmed_by'],
    ['kitchen_api_receive_logs','returned_by','ALTER TABLE kitchen_api_receive_logs ADD COLUMN returned_by INT NULL AFTER confirmed_at'],
    ['kitchen_api_receive_logs','returned_at','ALTER TABLE kitchen_api_receive_logs ADD COLUMN returned_at DATETIME NULL AFTER returned_by'],
    ['kitchen_api_receive_logs','return_note','ALTER TABLE kitchen_api_receive_logs ADD COLUMN return_note TEXT NULL AFTER returned_at'],
    ['kitchen_api_received_items','qty_base','ALTER TABLE kitchen_api_received_items ADD COLUMN qty_base DECIMAL(18,4) NOT NULL DEFAULT 0 AFTER qty'],
    ['kitchen_api_received_items','unit_cost','ALTER TABLE kitchen_api_received_items ADD COLUMN unit_cost DECIMAL(18,2) DEFAULT 0 AFTER transfer_price'],
    ['kitchen_api_received_items','line_total','ALTER TABLE kitchen_api_received_items ADD COLUMN line_total DECIMAL(18,2) DEFAULT 0 AFTER unit_cost'],
  ];
  foreach ($cols as $c) { if (!kr_column_exists($c[0], $c[1])) kr_safe_exec($c[2]); }
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
function kr_receive_log_status_label(string $status): string {
  $map = ['pending_confirmation'=>'Pending cek cabang','confirmed'=>'Diterima','returned_to_kitchen'=>'Dikembalikan ke Dapur','failed'=>'Failed'];
  return $map[$status] ?? $status;
}

kr_ensure_tables();
if ($_SERVER['REQUEST_METHOD']==='POST') {
  csrf_check();
  $action=(string)($_POST['action']??''); $id=(int)($_POST['id']??0);
  if ($id > 0 && ($action==='confirm' || $action==='return')) {
    try{
      db()->beginTransaction();
      $st=db()->prepare('SELECT * FROM kitchen_api_receive_logs WHERE id=? FOR UPDATE'); $st->execute([$id]); $log=$st->fetch(PDO::FETCH_ASSOC);
      if(!$log) throw new Exception('Transfer tidak ditemukan.');
      if((string)$log['status']!=='pending_confirmation') throw new Exception('Status transfer tidak bisa diproses: '.$log['status']);
      if ($action==='return') {
        $note=trim((string)($_POST['return_note']??''));
        if($note==='') throw new Exception('Catatan koreksi wajib diisi saat mengembalikan kiriman ke dapur.');
        db()->prepare('UPDATE kitchen_api_receive_logs SET status=?, message=?, returned_by=?, returned_at=NOW(), return_note=? WHERE id=?')
          ->execute(['returned_to_kitchen','Dikembalikan ke dapur untuk dikoreksi: '.$note,(int)($u['id']??0),$note,$id]);
        db()->commit(); kr_flash('Kiriman dikembalikan ke dapur untuk koreksi. Stok cabang belum bertambah.','ok');
        redirect(base_url('admin/kitchen_receive_confirm.php?status=returned_to_kitchen'));
      }
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
      db()->prepare('UPDATE kitchen_api_receive_logs SET status=?, purchase_id=?, purchase_no=?, message=?, confirmed_by=?, confirmed_at=NOW() WHERE id=?')->execute(['confirmed',$purchaseId,$purchaseNo,'Stok dikonfirmasi manager cabang dan masuk pembelian '.$purchaseNo,(int)($u['id']??0),$id]);
      db()->commit(); kr_flash('Penerimaan stok dikonfirmasi. Stok sudah masuk: '.$purchaseNo,'ok');
    }catch(Throwable $e){ if(db()->inTransaction()) db()->rollBack(); kr_flash('Gagal proses kiriman: '.$e->getMessage(),'err'); }
    redirect(base_url('admin/kitchen_receive_confirm.php'));
  }
}

$status=trim((string)($_GET['status']??'pending_confirmation'));
$where=''; $params=[];
if($status!=='all'){ $where='WHERE l.status=?'; $params[]=$status; }
$stmt=db()->prepare("SELECT l.*, b.branch_name, u.name confirmed_name, ru.name returned_name FROM kitchen_api_receive_logs l LEFT JOIN branches b ON b.id=l.branch_id LEFT JOIN users u ON u.id=l.confirmed_by LEFT JOIN users ru ON ru.id=l.returned_by $where ORDER BY l.id DESC LIMIT 100");
$stmt->execute($params); $logs=$stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
$itemsByLog=[]; if($logs){ $ids=array_map(fn($r)=>(int)$r['id'],$logs); $in=implode(',',array_fill(0,count($ids),'?')); $it=db()->prepare("SELECT * FROM kitchen_api_received_items WHERE log_id IN ($in) ORDER BY log_id,id"); $it->execute($ids); foreach($it->fetchAll(PDO::FETCH_ASSOC) ?: [] as $r){ $itemsByLog[(int)$r['log_id']][]=$r; } }
$f=kr_get_flash(); $customCss=setting('custom_css','');
?>
<!doctype html><html><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"><title>Konfirmasi Stok Dapur</title><link rel="icon" href="<?php echo e(favicon_url()); ?>"><link rel="stylesheet" href="<?php echo e(asset_url('assets/app.css')); ?>"><style><?php echo $customCss; ?>.receive-page{max-width:1500px;margin:0 auto;padding:14px 18px 28px}.receive-card{margin-bottom:12px}.receive-log-head{display:flex;justify-content:space-between;gap:12px;align-items:flex-start}.receive-meta{color:#64748b;font-size:13px}.receive-table-wrap{overflow:auto;border:1px solid #e5e7eb;border-radius:12px}.receive-table-wrap table{min-width:820px;margin:0}.badge-pending{background:#fff7ed;color:#9a3412}.badge-confirmed{background:#ecfdf5;color:#166534}.badge-failed{background:#fff1f2;color:#9f1239}.badge-returned{background:#eff6ff;color:#1d4ed8}.return-box{margin-top:8px;display:flex;gap:8px;align-items:center}.return-box input{min-width:260px}.receive-actions{min-width:270px;text-align:right}@media(max-width:760px){.receive-page{padding:12px}.receive-log-head{display:block}.receive-actions{text-align:left;margin-top:10px}.return-box{display:block}.return-box input,.return-box button{width:100%;margin-top:6px}}</style></head><body><div class="container"><?php include __DIR__ . '/partials_sidebar.php'; ?><div class="main"><div class="topbar"><button class="btn" data-toggle-sidebar type="button">Menu</button><strong>Konfirmasi Stok Dapur</strong></div><div class="content receive-page">
<?php if($f): ?><div class="card" style="border-color:<?php echo $f[1]==='err'?'#fecdd3':'#bbf7d0'; ?>;background:<?php echo $f[1]==='err'?'#fff1f2':'#f0fdf4'; ?>"><?php echo e($f[0]); ?></div><?php endif; ?>
<div class="card receive-card"><h3>Verifikasi Penerimaan Stok dari Dapur Adena</h3><p class="receive-meta">Manager cabang mengecek barang kiriman. Stok cabang baru bertambah setelah kiriman diterima. Bila salah, kembalikan ke dapur dengan catatan koreksi.</p><form method="get" class="actions"><label>Status <select name="status"><option value="pending_confirmation" <?php echo $status==='pending_confirmation'?'selected':''; ?>>Pending</option><option value="confirmed" <?php echo $status==='confirmed'?'selected':''; ?>>Diterima</option><option value="returned_to_kitchen" <?php echo $status==='returned_to_kitchen'?'selected':''; ?>>Dikembalikan</option><option value="failed" <?php echo $status==='failed'?'selected':''; ?>>Failed</option><option value="all" <?php echo $status==='all'?'selected':''; ?>>Semua</option></select></label><button class="btn light" type="submit">Filter</button></form></div>
<?php if(!$logs): ?><div class="card">Tidak ada data penerimaan stok untuk filter ini.</div><?php endif; ?>
<?php foreach($logs as $log): $items=$itemsByLog[(int)$log['id']]??[]; $grand=0; foreach($items as $it){$grand+=(float)($it['line_total'] ?: ((float)$it['qty']*(float)$it['transfer_price']));} $cls='badge-pending'; if($log['status']==='confirmed')$cls='badge-confirmed'; elseif($log['status']==='failed')$cls='badge-failed'; elseif($log['status']==='returned_to_kitchen')$cls='badge-returned'; ?>
<div class="card receive-card"><div class="receive-log-head"><div><h3 style="margin:0"><?php echo e((string)$log['transfer_no']); ?> <span class="badge <?php echo e($cls); ?>"><?php echo e(kr_receive_log_status_label((string)$log['status'])); ?></span></h3><div class="receive-meta">Cabang: <?php echo e($log['branch_name'] ?? '-'); ?> • Masuk: <?php echo e((string)$log['created_at']); ?><?php if($log['confirmed_at']): ?> • Diterima: <?php echo e((string)$log['confirmed_at']); ?> oleh <?php echo e($log['confirmed_name'] ?? '-'); ?><?php endif; ?><?php if($log['returned_at']): ?> • Dikembalikan: <?php echo e((string)$log['returned_at']); ?> oleh <?php echo e($log['returned_name'] ?? '-'); ?><?php endif; ?></div><div class="receive-meta"><?php echo e((string)($log['message'] ?? '')); ?></div><?php if(!empty($log['return_note'])): ?><div class="receive-meta"><strong>Catatan koreksi:</strong> <?php echo e((string)$log['return_note']); ?></div><?php endif; ?></div><div class="receive-actions"><strong><?php echo rupiah($grand); ?></strong><?php if($log['status']==='pending_confirmation'): ?><form method="post" style="margin-top:8px" onsubmit="return confirm('Konfirmasi stok diterima dan masukkan ke stok cabang?')"><input type="hidden" name="_csrf" value="<?php echo e(csrf_token()); ?>"><input type="hidden" name="action" value="confirm"><input type="hidden" name="id" value="<?php echo (int)$log['id']; ?>"><button class="btn" type="submit">Terima / Barang Benar</button></form><form method="post" class="return-box" onsubmit="return confirm('Kembalikan kiriman ini ke dapur untuk koreksi?')"><input type="hidden" name="_csrf" value="<?php echo e(csrf_token()); ?>"><input type="hidden" name="action" value="return"><input type="hidden" name="id" value="<?php echo (int)$log['id']; ?>"><input name="return_note" placeholder="Catatan koreksi wajib" required><button class="btn danger" type="submit">Kembalikan</button></form><?php endif; ?></div></div><div class="receive-table-wrap" style="margin-top:12px"><table class="table"><thead><tr><th>Produk</th><th>SKU</th><th>Qty</th><th>Unit</th><th>Harga</th><th>Subtotal</th></tr></thead><tbody><?php foreach($items as $it): $sub=(float)($it['line_total'] ?: ((float)$it['qty']*(float)$it['transfer_price'])); ?><tr><td><?php echo e((string)$it['product_name']); ?></td><td><?php echo e((string)$it['sku']); ?></td><td><?php echo number_format((float)$it['qty'],4,',','.'); ?></td><td><?php echo e((string)$it['unit']); ?></td><td><?php echo rupiah($it['transfer_price']); ?></td><td><?php echo rupiah($sub); ?></td></tr><?php endforeach; ?></tbody></table></div></div>
<?php endforeach; ?>
</div></div></div><script defer src="<?php echo e(asset_url('assets/app.js')); ?>"></script></body></html>
