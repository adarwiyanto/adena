<?php
require_once __DIR__.'/../../../core/db.php';
require_once __DIR__.'/../../../core/functions.php';
require_once __DIR__.'/../../../core/inventory.php';
header('Content-Type: application/json; charset=utf-8');

function out($d,$c=200){http_response_code($c);echo json_encode($d,JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES);exit;}
function ensure_tables(){ $sql=file_get_contents(__DIR__.'/../../../db/toko_api_dapur_patch.sql'); foreach(array_filter(array_map('trim', explode(';',$sql))) as $q){ if($q!=='') db()->exec($q); } }
function bearer(){ $h=$_SERVER['HTTP_AUTHORIZATION'] ?? $_SERVER['REDIRECT_HTTP_AUTHORIZATION'] ?? ''; if(preg_match('/Bearer\s+(.+)/i',$h,$m)) return trim($m[1]); return $_GET['token'] ?? ''; }
function table_exists_local(string $table): bool { $st=db()->prepare("SHOW TABLES LIKE ?"); $st->execute([$table]); return (bool)$st->fetchColumn(); }
function kitchen_active_branch_id(): int { return function_exists('active_branch_id') ? max(1,(int)active_branch_id()) : 1; }
function kitchen_purchase_no(string $transferNo, int $logId): string {
  $base = preg_replace('/[^A-Za-z0-9\-_.]/', '-', $transferNo);
  $no = 'KD-' . substr($base, 0, 42);
  if (strlen($no) > 50 || $no === 'KD-') $no = 'KD-' . date('Ymd') . '-' . $logId;
  $st = db()->prepare('SELECT id FROM purchase_headers WHERE purchase_no=? LIMIT 1');
  $st->execute([$no]);
  if (!$st->fetchColumn()) return $no;
  return 'KD-' . date('Ymd') . '-' . $logId;
}
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
    if ($purchaseUnit !== '' && strcasecmp($unit, $purchaseUnit) === 0) {
      return round($qty * max(0.000001, (float)($meta['purchase_to_base_factor'] ?? 1)), 4);
    }
    $saleUnit = (string)($meta['sale_unit'] ?? '');
    if ($saleUnit !== '' && strcasecmp($unit, $saleUnit) === 0) {
      return round($qty * max(0.000001, (float)($meta['sale_to_base_factor'] ?? 1)), 4);
    }
  }
  return $qty;
}

ensure_tables();
if (function_exists('ensure_inventory_module_schema')) ensure_inventory_module_schema();
$token=bearer(); if($token==='') out(['ok'=>false,'error'=>'Token kosong'],401);
$st=db()->prepare('SELECT * FROM kitchen_api_tokens WHERE token_hash=? AND is_active=1 LIMIT 1'); $st->execute([hash('sha256',$token)]); $tok=$st->fetch(PDO::FETCH_ASSOC); if(!$tok) out(['ok'=>false,'error'=>'Token tidak valid'],401);
$in=json_decode(file_get_contents('php://input')?:'{}',true); if(!is_array($in)) out(['ok'=>false,'error'=>'Payload JSON tidak valid'],400);
$transferNo=(string)($in['transfer_no']??''); $items=$in['items']??[]; if($transferNo===''||!is_array($items)||count($items)===0) out(['ok'=>false,'error'=>'transfer_no/items wajib diisi'],422);
$exists=db()->prepare('SELECT id FROM kitchen_api_receive_logs WHERE transfer_no=? LIMIT 1'); $exists->execute([$transferNo]); if($exists->fetchColumn()) out(['ok'=>true,'duplicate'=>true,'message'=>'Transfer sudah pernah diterima']);

try{
 db()->beginTransaction();
 $log=db()->prepare('INSERT INTO kitchen_api_receive_logs(token_id,transfer_no,endpoint,status,message,payload_json,remote_ip) VALUES(?,?,?,?,?,?,?)');
 $log->execute([(int)$tok['id'],$transferNo,'receive-transfer','received','Payload diterima',json_encode($in,JSON_UNESCAPED_UNICODE),$_SERVER['REMOTE_ADDR']??'']);
 $logId=(int)db()->lastInsertId();
 $branchId=(int)($in['branch_id'] ?? kitchen_active_branch_id()); if($branchId<=0) $branchId=1;
 $supplierId=kitchen_supplier_id();
 $purchaseNo=kitchen_purchase_no($transferNo,$logId);
 $purchaseDate=(string)($in['transfer_date'] ?? date('Y-m-d'));
 if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $purchaseDate)) $purchaseDate=date('Y-m-d');
 $valid=[]; $total=0.0;
 foreach($items as $it){
   if(!is_array($it)) continue;
   $pid=(int)($it['store_product_id']??$it['product_id']??0); $qty=(float)($it['qty']??0); if($pid<=0||$qty<=0) continue;
   $product=db()->prepare('SELECT * FROM products WHERE id=? LIMIT 1'); $product->execute([$pid]); $p=$product->fetch(PDO::FETCH_ASSOC); if(!$p) throw new Exception('Produk toko tidak ditemukan: '.$pid);
   $unit=(string)($it['unit']??($p['base_unit']??''));
   $unitCost=(float)($it['transfer_price']??0);
   if($unitCost<0) throw new Exception('Harga transfer tidak boleh negatif untuk produk: '.$pid);
   $qtyBase=kitchen_product_base_qty($p,$qty,$unit);
   $line=$qty*$unitCost;
   $valid[]=['product'=>$p,'product_id'=>$pid,'sku'=>(string)($it['sku']??''),'name'=>(string)($it['name']??$p['name']),'qty'=>$qty,'qty_base'=>$qtyBase,'unit'=>$unit,'unit_cost'=>$unitCost,'line_total'=>$line];
   $total += $line;
 }
 if(!$valid) throw new Exception('Tidak ada item valid untuk diterima.');

 if (!table_exists_local('purchase_headers') || !table_exists_local('purchase_items') || !table_exists_local('suppliers')) {
   throw new Exception('Tabel pembelian belum tersedia di database Adéna.');
 }
 $ph=db()->prepare("INSERT INTO purchase_headers (branch_id,supplier_id,purchase_no,purchase_date,purchase_type,status,subtotal,grand_total,notes,created_by,posted_by,posted_at) VALUES (?,?,?,?, 'general','posted',?,?,?,?,?,NOW())");
 $ph->execute([$branchId,$supplierId,$purchaseNo,$purchaseDate,$total,$total,'Auto pembelian dari transfer Dapur Adena '.$transferNo,null,null]);
 $purchaseId=(int)db()->lastInsertId();
 $pi=db()->prepare('INSERT INTO purchase_items (purchase_id,product_id,item_name,qty,unit_cost,line_total,notes) VALUES (?,?,?,?,?,?,?)');
 $mark=db()->prepare("UPDATE products SET track_stock=1, allow_direct_purchase=1 WHERE id=? AND product_type='finished_good'");
 foreach($valid as $row){
   $mark->execute([(int)$row['product_id']]);
   $pi->execute([$purchaseId,(int)$row['product_id'],(string)$row['name'],(float)$row['qty'],(float)$row['unit_cost'],(float)$row['line_total'],'Transfer dari Dapur Adena '.$transferNo]);
   $ins=db()->prepare('INSERT INTO kitchen_api_received_items(log_id,product_id,sku,product_name,qty,unit,transfer_price) VALUES(?,?,?,?,?,?,?)');
   $ins->execute([$logId,(int)$row['product_id'],(string)$row['sku'],(string)$row['name'],(float)$row['qty'],(string)$row['unit'],(float)$row['unit_cost']]);
   if (table_exists_local('stock_ledger')) {
     if(function_exists('add_stock_ledger')){
       add_stock_ledger(['branch_id'=>$branchId,'product_id'=>(int)$row['product_id'],'trans_type'=>'receive_from_kitchen','ref_table'=>'purchase_headers','ref_id'=>$purchaseId,'qty_in'=>(float)$row['qty_base'],'qty_out'=>0,'unit_cost'=>(float)$row['unit_cost'],'note'=>'Pembelian dari Dapur Adena '.$purchaseNo,'created_by'=>null]);
     } else {
       $stmt=db()->prepare('INSERT INTO stock_ledger(branch_id,product_id,trans_type,ref_table,ref_id,qty_in,qty_out,unit_cost,note,created_at) VALUES(?,?,?,?,?,?,?,?,?,NOW())');
       $stmt->execute([$branchId,(int)$row['product_id'],'receive_from_kitchen','purchase_headers',$purchaseId,(float)$row['qty_base'],0,(float)$row['unit_cost'],'Pembelian dari Dapur Adena '.$purchaseNo]);
     }
   }
 }
 db()->prepare('UPDATE kitchen_api_receive_logs SET message=? WHERE id=?')->execute(['Payload diterima dan dibuat pembelian '.$purchaseNo,$logId]);
 db()->prepare('UPDATE kitchen_api_tokens SET last_used_at=NOW() WHERE id=?')->execute([(int)$tok['id']]);
 db()->commit(); out(['ok'=>true,'message'=>'Stok dari dapur diterima sebagai pembelian barang','transfer_no'=>$transferNo,'log_id'=>$logId,'purchase_id'=>$purchaseId,'purchase_no'=>$purchaseNo,'grand_total'=>$total]);
}catch(Throwable $e){ if(db()->inTransaction()) db()->rollBack(); try{db()->prepare('INSERT INTO kitchen_api_receive_logs(token_id,transfer_no,endpoint,status,message,payload_json,remote_ip) VALUES(?,?,?,?,?,?,?)')->execute([(int)$tok['id'],$transferNo,'receive-transfer','failed',$e->getMessage(),json_encode($in,JSON_UNESCAPED_UNICODE),$_SERVER['REMOTE_ADDR']??'']);}catch(Throwable $x){} out(['ok'=>false,'error'=>$e->getMessage()],500); }
