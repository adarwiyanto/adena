<?php
require_once __DIR__.'/../../../core/db.php';
require_once __DIR__.'/../../../core/functions.php';
require_once __DIR__.'/../../../core/inventory.php';
require_once __DIR__.'/_auth.php';
header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');

function out($d,$c=200){http_response_code($c);echo json_encode($d,JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES);exit;}
function safe_exec_local(string $sql): void { try{ db()->exec($sql); }catch(Throwable $e){} }
function api_col_exists(string $table,string $col): bool { try{$st=db()->prepare("SHOW COLUMNS FROM `".str_replace('`','',$table)."` LIKE ?");$st->execute([$col]);return (bool)$st->fetch();}catch(Throwable $e){return false;} }
function table_columns_local(string $table): array { try{return array_map(fn($r)=>(string)$r['Field'], db()->query('SHOW COLUMNS FROM `'.str_replace('`','',$table).'`')->fetchAll(PDO::FETCH_ASSOC));}catch(Throwable $e){return [];} }
function ensure_tables(){
 safe_exec_local("CREATE TABLE IF NOT EXISTS kitchen_api_receive_logs (
  id BIGINT AUTO_INCREMENT PRIMARY KEY, token_id INT NULL, branch_id INT NULL, supplier_id INT NULL,
  transfer_no VARCHAR(80) NULL, endpoint VARCHAR(160) NULL, status VARCHAR(40) NOT NULL,
  purchase_id INT NULL, purchase_no VARCHAR(80) NULL, message TEXT NULL, payload_json LONGTEXT NULL,
  remote_ip VARCHAR(80) NULL, confirmed_by INT NULL, confirmed_at DATETIME NULL,
  returned_by INT NULL, returned_at DATETIME NULL, return_note TEXT NULL,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP, UNIQUE KEY uq_transfer_no(transfer_no)
 ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
 safe_exec_local("CREATE TABLE IF NOT EXISTS kitchen_api_received_items (
  id BIGINT AUTO_INCREMENT PRIMARY KEY, log_id BIGINT NOT NULL, product_id INT NOT NULL, sku VARCHAR(100) NULL,
  product_name VARCHAR(180) NULL, qty DECIMAL(18,4) NOT NULL DEFAULT 0, qty_base DECIMAL(18,4) NOT NULL DEFAULT 0,
  unit VARCHAR(50) NULL, transfer_price DECIMAL(18,2) DEFAULT 0, unit_cost DECIMAL(18,2) DEFAULT 0,
  line_total DECIMAL(18,2) DEFAULT 0, created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP, KEY idx_log_id(log_id)
 ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
 $cols=[
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
 foreach($cols as $c){ if(!api_col_exists($c[0],$c[1])) safe_exec_local($c[2]); }
}
function kitchen_active_branch_id(): int { return function_exists('active_branch_id') ? max(1,(int)active_branch_id()) : 1; }
function kitchen_supplier_id(): int {
  $code = 'DAPUR_ADENA';
  $st = db()->prepare('SELECT id FROM suppliers WHERE supplier_code=? LIMIT 1'); $st->execute([$code]);
  $id = (int)$st->fetchColumn(); if ($id > 0) return $id;
  $ins = db()->prepare('INSERT INTO suppliers(supplier_code,supplier_name,is_active) VALUES(?,?,1)'); $ins->execute([$code, 'Dapur Adena']);
  return (int)db()->lastInsertId();
}
function kitchen_product_base_qty(array $p, float $qty, string $unit): float {
  $base = (string)($p['base_unit'] ?? '');
  if ($base === '' || $unit === '' || strcasecmp($unit, $base) === 0) return $qty;
  if (function_exists('product_unit_fallback')) {
    $meta = product_unit_fallback($p);
    $purchaseUnit = (string)($meta['purchase_unit'] ?? '');
    if ($purchaseUnit !== '' && strcasecmp($unit, $purchaseUnit) === 0) return round($qty * max(0.000001, (float)($meta['purchase_to_base_factor'] ?? 1)), 4);
    $saleUnit = (string)($meta['sale_unit'] ?? '');
    if ($saleUnit !== '' && strcasecmp($unit, $saleUnit) === 0) return round($qty * max(0.000001, (float)($meta['sale_to_base_factor'] ?? 1)), 4);
  }
  return $qty;
}
function kitchen_find_or_create_product(array $it, bool $create): array {
  $cols = table_columns_local('products');
  $hasSku = in_array('sku', $cols, true);
  $pid=(int)($it['store_product_id']??$it['product_id']??0);
  if($pid>0){ $st=db()->prepare('SELECT * FROM products WHERE id=? LIMIT 1'); $st->execute([$pid]); $p=$st->fetch(PDO::FETCH_ASSOC); if($p) return [$p,false,'id']; }
  $sku=trim((string)($it['sku']??''));
  if($hasSku && $sku!==''){ $st=db()->prepare('SELECT * FROM products WHERE sku=? LIMIT 1'); $st->execute([$sku]); $p=$st->fetch(PDO::FETCH_ASSOC); if($p) return [$p,false,'sku']; }
  $name=trim((string)($it['name']??$it['product_name']??''));
  if($name!==''){ $st=db()->prepare('SELECT * FROM products WHERE LOWER(name)=LOWER(?) LIMIT 1'); $st->execute([$name]); $p=$st->fetch(PDO::FETCH_ASSOC); if($p) return [$p,false,'name']; }
  if(!$create) {
    return [['id'=>0,'name'=>$name ?: ($sku ?: 'Produk Dapur'), 'base_unit'=>(string)($it['unit']??''), 'product_type'=>'finished_good'], true, 'would_create'];
  }
  if($name==='') throw new Exception('Produk belum ada di cabang dan nama produk kosong, tidak bisa dibuat otomatis.');
  $unit=(string)($it['unit']??'pcs'); if($unit==='') $unit='pcs';
  $price=(float)($it['transfer_price']??0);
  $data=[
    'name'=>$name, 'category'=>'Dapur Adena', 'price'=>$price, 'kitchen_price'=>$price,
    'product_type'=>'finished_good', 'track_stock'=>1, 'allow_direct_purchase'=>1, 'allow_bom'=>0,
    'show_on_pos'=>1, 'show_on_landing'=>0, 'base_unit'=>$unit, 'purchase_unit'=>$unit, 'purchase_to_base_factor'=>1,
    'sale_unit'=>$unit, 'sale_to_base_factor'=>1, 'include_in_sales_report'=>1
  ];
  if($hasSku && $sku!=='') $data['sku']=$sku;
  $insertCols=[]; $marks=[]; $vals=[];
  foreach($data as $c=>$v){ if(in_array($c,$cols,true)){ $insertCols[]='`'.str_replace('`','',$c).'`'; $marks[]='?'; $vals[]=$v; } }
  if(empty($insertCols)) throw new Exception('Struktur tabel produk tidak valid untuk auto-create produk dapur.');
  $stmt=db()->prepare('INSERT INTO products ('.implode(',',$insertCols).') VALUES ('.implode(',',$marks).')'); $stmt->execute($vals);
  $newId=(int)db()->lastInsertId();
  $st=db()->prepare('SELECT * FROM products WHERE id=? LIMIT 1'); $st->execute([$newId]); $p=$st->fetch(PDO::FETCH_ASSOC);
  if(!$p) throw new Exception('Produk cabang berhasil dibuat tetapi gagal dibaca ulang.');
  return [$p,true,'created'];
}
function validate_kitchen_transfer_items(array $items, bool $createProducts): array {
 $valid=[]; $total=0.0; $idx=0; $created=0; $wouldCreate=0;
 foreach($items as $it){
   $idx++;
   if(!is_array($it)) throw new Exception('Item #'.$idx.' bukan object JSON valid.');
   $qty=(float)($it['qty']??0);
   $name=(string)($it['name']??'');
   if($qty<=0) throw new Exception('Item #'.$idx.': qty harus lebih dari 0.');
   [$p,$made,$match]=kitchen_find_or_create_product($it,$createProducts);
   if($made && $match==='created') $created++;
   if($made && $match==='would_create') $wouldCreate++;
   $pid=(int)($p['id']??0);
   if($pid<=0 && $createProducts) throw new Exception('Item #'.$idx.($name!==''?' ('.$name.')':'').': produk toko tidak ditemukan dan gagal dibuat otomatis.');
   $unit=(string)($it['unit']??($p['base_unit']??''));
   $unitCost=(float)($it['transfer_price']??0);
   if($unitCost<0) throw new Exception('Item #'.$idx.': harga transfer tidak boleh negatif.');
   $qtyBase=kitchen_product_base_qty($p,$qty,$unit);
   $line=$qty*$unitCost; $total += $line;
   $valid[]=['product_id'=>$pid,'sku'=>(string)($it['sku']??''),'product_name'=>(string)($it['name']??$p['name']),'qty'=>$qty,'qty_base'=>$qtyBase,'unit'=>$unit,'transfer_price'=>$unitCost,'unit_cost'=>$unitCost,'line_total'=>$line,'product_match'=>$match];
 }
 if(count($valid)<1) throw new Exception('Tidak ada item valid untuk diterima.');
 return [$valid,$total,$created,$wouldCreate];
}

ensure_tables();
if (function_exists('ensure_inventory_module_schema')) ensure_inventory_module_schema();
$tok = kitchen_api_find_token(['stock_transfer','transfers.receive','transfers.create']);
$in=json_decode(file_get_contents('php://input')?:'{}',true); if(!is_array($in)) out(['ok'=>false,'message'=>'Payload JSON tidak valid','error'=>'Payload JSON tidak valid'],400);
$dryRun=!empty($in['dry_run']);
$transferNo=(string)($in['transfer_no']??''); $items=$in['items']??[]; if($transferNo===''||!is_array($items)||count($items)===0) out(['ok'=>false,'message'=>'transfer_no/items wajib diisi','error'=>'transfer_no/items wajib diisi'],422);
$exists=db()->prepare('SELECT id,status,purchase_id,purchase_no FROM kitchen_api_receive_logs WHERE transfer_no=? LIMIT 1');
$exists->execute([$transferNo]);
$ex=$exists->fetch(PDO::FETCH_ASSOC);
if($ex) out(['ok'=>true,'duplicate'=>true,'status'=>$ex['status'],'message'=>'Transfer sudah pernah diterima di toko','log_id'=>(int)$ex['id'],'purchase_id'=>(int)($ex['purchase_id']??0),'purchase_no'=>$ex['purchase_no']??null]);

try{
 [$validItems,$grandTotal,$createdProducts,$wouldCreateProducts]=validate_kitchen_transfer_items($items,!$dryRun);
 if($dryRun){
  kitchen_api_touch_token($tok);
  out(['ok'=>true,'status'=>'dry_run_ok','message'=>'Transfer test valid. Produk yang belum ada akan dibuat otomatis saat transfer asli. Dry-run tidak mengubah stok/pembelian toko.','transfer_no'=>$transferNo,'total_items'=>count($validItems),'grand_total'=>$grandTotal,'would_create_products'=>$wouldCreateProducts,'token_source'=>$tok['source']]);
 }
}catch(Throwable $e){
 if($dryRun) out(['ok'=>false,'status'=>'dry_run_failed','message'=>$e->getMessage(),'error'=>$e->getMessage()],422);
 out(['ok'=>false,'message'=>$e->getMessage(),'error'=>$e->getMessage()],422);
}
try{
 db()->beginTransaction();
 $branchId=(int)($in['branch_id'] ?? kitchen_active_branch_id()); if($branchId<=0) $branchId=1;
 $supplierId=kitchen_supplier_id();
 $transferDate=(string)($in['transfer_date'] ?? date('Y-m-d')); if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $transferDate)) $transferDate=date('Y-m-d');
 $log=db()->prepare('INSERT INTO kitchen_api_receive_logs(token_id,branch_id,supplier_id,transfer_no,endpoint,status,message,payload_json,remote_ip) VALUES(?,?,?,?,?,?,?,?,?)');
 $log->execute([(int)$tok['id'],$branchId,$supplierId,$transferNo,'receive-transfer','pending_confirmation','Menunggu pengecekan dan konfirmasi penerimaan stok oleh manager cabang',json_encode($in,JSON_UNESCAPED_UNICODE),$_SERVER['REMOTE_ADDR']??'']);
 $logId=(int)db()->lastInsertId();
 $ins=db()->prepare('INSERT INTO kitchen_api_received_items(log_id,product_id,sku,product_name,qty,qty_base,unit,transfer_price,unit_cost,line_total) VALUES(?,?,?,?,?,?,?,?,?,?)');
 foreach($validItems as $it){
   $ins->execute([$logId,$it['product_id'],$it['sku'],$it['product_name'],$it['qty'],$it['qty_base'],$it['unit'],$it['transfer_price'],$it['unit_cost'],$it['line_total']]);
 }
 kitchen_api_touch_token($tok);
 db()->commit();
 out(['ok'=>true,'message'=>'Transfer stok diterima sebagai pending. Manager cabang perlu cek barang lalu terima/kembalikan sebelum stok masuk.','status'=>'pending_confirmation','transfer_no'=>$transferNo,'log_id'=>$logId,'total_items'=>count($validItems),'grand_total'=>$grandTotal,'created_products'=>$createdProducts,'token_source'=>$tok['source']]);
}catch(Throwable $e){
 if(db()->inTransaction()) db()->rollBack();
 try{db()->prepare('INSERT INTO kitchen_api_receive_logs(token_id,transfer_no,endpoint,status,message,payload_json,remote_ip) VALUES(?,?,?,?,?,?,?)')->execute([(int)$tok['id'],$transferNo,'receive-transfer','failed',$e->getMessage(),json_encode($in,JSON_UNESCAPED_UNICODE),$_SERVER['REMOTE_ADDR']??'']);}catch(Throwable $x){}
 out(['ok'=>false,'message'=>$e->getMessage(),'error'=>$e->getMessage()],500);
}
