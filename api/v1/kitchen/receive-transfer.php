<?php
require_once __DIR__.'/../../../core/db.php';
require_once __DIR__.'/../../../core/functions.php';
require_once __DIR__.'/../../../core/inventory.php';
header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');

function out($d,$c=200){http_response_code($c);echo json_encode($d,JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES);exit;}
function table_exists_local(string $table): bool { $st=db()->prepare("SHOW TABLES LIKE ?"); $st->execute([$table]); return (bool)$st->fetchColumn(); }
function safe_exec_local(string $sql): void { try{ db()->exec($sql); }catch(Throwable $e){} }
function ensure_tables(){
 $sql=file_get_contents(__DIR__.'/../../../db/toko_api_dapur_patch.sql');
 foreach(array_filter(array_map('trim', explode(';',$sql))) as $q){ if($q!=='') db()->exec($q); }
 safe_exec_local("ALTER TABLE kitchen_api_receive_logs ADD COLUMN branch_id INT NULL AFTER token_id");
 safe_exec_local("ALTER TABLE kitchen_api_receive_logs ADD COLUMN supplier_id INT NULL AFTER branch_id");
 safe_exec_local("ALTER TABLE kitchen_api_receive_logs ADD COLUMN purchase_id INT NULL AFTER status");
 safe_exec_local("ALTER TABLE kitchen_api_receive_logs ADD COLUMN purchase_no VARCHAR(80) NULL AFTER purchase_id");
 safe_exec_local("ALTER TABLE kitchen_api_receive_logs ADD COLUMN confirmed_by INT NULL AFTER remote_ip");
 safe_exec_local("ALTER TABLE kitchen_api_receive_logs ADD COLUMN confirmed_at DATETIME NULL AFTER confirmed_by");
 safe_exec_local("ALTER TABLE kitchen_api_received_items ADD COLUMN qty_base DECIMAL(18,4) NOT NULL DEFAULT 0 AFTER qty");
 safe_exec_local("ALTER TABLE kitchen_api_received_items ADD COLUMN unit_cost DECIMAL(18,2) DEFAULT 0 AFTER transfer_price");
 safe_exec_local("ALTER TABLE kitchen_api_received_items ADD COLUMN line_total DECIMAL(18,2) DEFAULT 0 AFTER unit_cost");
}
function bearer(){ $h=$_SERVER['HTTP_AUTHORIZATION'] ?? $_SERVER['REDIRECT_HTTP_AUTHORIZATION'] ?? ''; if(preg_match('/Bearer\s+(.+)/i',$h,$m)) return trim($m[1]); return $_GET['token'] ?? ''; }
function kitchen_active_branch_id(): int { return function_exists('active_branch_id') ? max(1,(int)active_branch_id()) : 1; }
function kitchen_supplier_id(): int {
  $code = 'DAPUR_ADENA';
  $st = db()->prepare('SELECT id FROM suppliers WHERE supplier_code=? LIMIT 1');
  $st->execute([$code]);
  $id = (int)$st->fetchColumn();
  if ($id > 0) return $id;
  $ins = db()->prepare('INSERT INTO suppliers(supplier_code,supplier_name,is_active) VALUES(?,?,1)');
  $ins->execute([$code, 'Dapur Adena']);
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
function validate_kitchen_transfer_items(array $items): array {
 $valid=[]; $total=0.0; $idx=0;
 foreach($items as $it){
   $idx++;
   if(!is_array($it)) throw new Exception('Item #'.$idx.' bukan object JSON valid.');
   $pid=(int)($it['store_product_id']??$it['product_id']??0);
   $qty=(float)($it['qty']??0);
   $name=(string)($it['name']??'');
   if($pid<=0) throw new Exception('Item #'.$idx.($name!==''?' ('.$name.')':'').': store_product_id/product_id wajib diisi.');
   if($qty<=0) throw new Exception('Item #'.$idx.': qty harus lebih dari 0.');
   $product=db()->prepare('SELECT * FROM products WHERE id=? LIMIT 1');
   $product->execute([$pid]);
   $p=$product->fetch(PDO::FETCH_ASSOC);
   if(!$p) throw new Exception('Item #'.$idx.($name!==''?' ('.$name.')':'').': produk toko tidak ditemukan, product_id='.$pid.'. Jalankan import/mapping produk dari toko yang benar.');
   $unit=(string)($it['unit']??($p['base_unit']??''));
   $unitCost=(float)($it['transfer_price']??0);
   if($unitCost<0) throw new Exception('Item #'.$idx.': harga transfer tidak boleh negatif untuk produk_id='.$pid.'.');
   $qtyBase=kitchen_product_base_qty($p,$qty,$unit);
   $line=$qty*$unitCost; $total += $line;
   $valid[]=['product_id'=>$pid,'sku'=>(string)($it['sku']??''),'product_name'=>(string)($it['name']??$p['name']),'qty'=>$qty,'qty_base'=>$qtyBase,'unit'=>$unit,'transfer_price'=>$unitCost,'unit_cost'=>$unitCost,'line_total'=>$line];
 }
 if(count($valid)<1) throw new Exception('Tidak ada item valid untuk diterima.');
 return [$valid,$total];
}

ensure_tables();
if (function_exists('ensure_inventory_module_schema')) ensure_inventory_module_schema();
$token=bearer(); if($token==='') out(['ok'=>false,'message'=>'Token kosong','error'=>'Token kosong'],401);
$st=db()->prepare('SELECT * FROM kitchen_api_tokens WHERE token_hash=? AND is_active=1 LIMIT 1'); $st->execute([hash('sha256',$token)]); $tok=$st->fetch(PDO::FETCH_ASSOC); if(!$tok) out(['ok'=>false,'message'=>'Token tidak valid','error'=>'Token tidak valid'],401);
$in=json_decode(file_get_contents('php://input')?:'{}',true); if(!is_array($in)) out(['ok'=>false,'message'=>'Payload JSON tidak valid','error'=>'Payload JSON tidak valid'],400);
$dryRun=!empty($in['dry_run']);
$transferNo=(string)($in['transfer_no']??''); $items=$in['items']??[]; if($transferNo===''||!is_array($items)||count($items)===0) out(['ok'=>false,'message'=>'transfer_no/items wajib diisi','error'=>'transfer_no/items wajib diisi'],422);
try{
 [$validItems,$grandTotal]=validate_kitchen_transfer_items($items);
 if($dryRun){
  db()->prepare('UPDATE kitchen_api_tokens SET last_used_at=NOW() WHERE id=?')->execute([(int)$tok['id']]);
  out(['ok'=>true,'status'=>'dry_run_ok','message'=>'Transfer test valid. Dry-run tidak mengubah stok/pembelian toko.','transfer_no'=>$transferNo,'total_items'=>count($validItems),'grand_total'=>$grandTotal]);
 }
}catch(Throwable $e){
 if($dryRun) out(['ok'=>false,'status'=>'dry_run_failed','message'=>$e->getMessage(),'error'=>$e->getMessage()],422);
 out(['ok'=>false,'message'=>$e->getMessage(),'error'=>$e->getMessage()],422);
}

$exists=db()->prepare('SELECT id,status,purchase_id,purchase_no FROM kitchen_api_receive_logs WHERE transfer_no=? LIMIT 1');
$exists->execute([$transferNo]);
$ex=$exists->fetch(PDO::FETCH_ASSOC);
if($ex) out(['ok'=>true,'duplicate'=>true,'status'=>$ex['status'],'message'=>'Transfer sudah pernah diterima di toko','log_id'=>(int)$ex['id'],'purchase_id'=>(int)($ex['purchase_id']??0),'purchase_no'=>$ex['purchase_no']??null]);
try{
 db()->beginTransaction();
 $branchId=(int)($in['branch_id'] ?? kitchen_active_branch_id()); if($branchId<=0) $branchId=1;
 $supplierId=kitchen_supplier_id();
 $transferDate=(string)($in['transfer_date'] ?? date('Y-m-d')); if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $transferDate)) $transferDate=date('Y-m-d');
 $log=db()->prepare('INSERT INTO kitchen_api_receive_logs(token_id,branch_id,supplier_id,transfer_no,endpoint,status,message,payload_json,remote_ip) VALUES(?,?,?,?,?,?,?,?,?)');
 $log->execute([(int)$tok['id'],$branchId,$supplierId,$transferNo,'receive-transfer','pending_confirmation','Menunggu konfirmasi penerimaan stok oleh manager toko',json_encode($in,JSON_UNESCAPED_UNICODE),$_SERVER['REMOTE_ADDR']??'']);
 $logId=(int)db()->lastInsertId();
 $ins=db()->prepare('INSERT INTO kitchen_api_received_items(log_id,product_id,sku,product_name,qty,qty_base,unit,transfer_price,unit_cost,line_total) VALUES(?,?,?,?,?,?,?,?,?,?)');
 foreach($validItems as $it){
   $ins->execute([$logId,$it['product_id'],$it['sku'],$it['product_name'],$it['qty'],$it['qty_base'],$it['unit'],$it['transfer_price'],$it['unit_cost'],$it['line_total']]);
 }
 db()->prepare('UPDATE kitchen_api_tokens SET last_used_at=NOW() WHERE id=?')->execute([(int)$tok['id']]);
 db()->commit();
 out(['ok'=>true,'message'=>'Transfer stok diterima sebagai pending. Manager toko perlu konfirmasi sebelum stok masuk.','status'=>'pending_confirmation','transfer_no'=>$transferNo,'log_id'=>$logId,'total_items'=>count($validItems),'grand_total'=>$grandTotal]);
}catch(Throwable $e){
 if(db()->inTransaction()) db()->rollBack();
 try{db()->prepare('INSERT INTO kitchen_api_receive_logs(token_id,transfer_no,endpoint,status,message,payload_json,remote_ip) VALUES(?,?,?,?,?,?,?)')->execute([(int)$tok['id'],$transferNo,'receive-transfer','failed',$e->getMessage(),json_encode($in,JSON_UNESCAPED_UNICODE),$_SERVER['REMOTE_ADDR']??'']);}catch(Throwable $x){}
 out(['ok'=>false,'message'=>$e->getMessage(),'error'=>$e->getMessage()],500);
}
