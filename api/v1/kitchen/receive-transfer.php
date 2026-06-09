<?php
require_once __DIR__.'/../../../core/db.php';
header('Content-Type: application/json; charset=utf-8');
function out($d,$c=200){http_response_code($c);echo json_encode($d,JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES);exit;}
function ensure_tables(){ $sql=file_get_contents(__DIR__.'/../../../db/toko_api_dapur_patch.sql'); foreach(array_filter(array_map('trim', explode(';',$sql))) as $q){ if($q!=='') db()->exec($q); } }
function bearer(){ $h=$_SERVER['HTTP_AUTHORIZATION'] ?? $_SERVER['REDIRECT_HTTP_AUTHORIZATION'] ?? ''; if(preg_match('/Bearer\s+(.+)/i',$h,$m)) return trim($m[1]); return $_GET['token'] ?? ''; }
ensure_tables();
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
 foreach($items as $it){
   $pid=(int)($it['store_product_id']??$it['product_id']??0); $qty=(float)($it['qty']??0); if($pid<=0||$qty<=0) continue;
   $product=db()->prepare('SELECT id,name,base_unit FROM products WHERE id=? LIMIT 1'); $product->execute([$pid]); $p=$product->fetch(PDO::FETCH_ASSOC); if(!$p) throw new Exception('Produk toko tidak ditemukan: '.$pid);
   $ins=db()->prepare('INSERT INTO kitchen_api_received_items(log_id,product_id,sku,product_name,qty,unit,transfer_price) VALUES(?,?,?,?,?,?,?)');
   $ins->execute([$logId,$pid,(string)($it['sku']??''),(string)($it['name']??$p['name']),$qty,(string)($it['unit']??($p['base_unit']??'')),(float)($it['transfer_price']??0)]);
   if (db()->query("SHOW TABLES LIKE 'stock_ledger'")->fetchColumn()) {
     $branchId=1; $stmt=db()->prepare('INSERT INTO stock_ledger(branch_id,product_id,trans_type,ref_table,ref_id,qty_in,qty_out,unit_cost,note,created_at) VALUES(?,?,?,?,?,?,?,?,?,NOW())');
     $stmt->execute([$branchId,$pid,'receive_from_kitchen','kitchen_api_receive_logs',$logId,$qty,0,(float)($it['transfer_price']??0),'Penerimaan dari Dapur Adena '.$transferNo]);
   }
 }
 db()->prepare('UPDATE kitchen_api_tokens SET last_used_at=NOW() WHERE id=?')->execute([(int)$tok['id']]);
 db()->commit(); out(['ok'=>true,'message'=>'Stok dari dapur diterima','transfer_no'=>$transferNo,'log_id'=>$logId]);
}catch(Throwable $e){ if(db()->inTransaction()) db()->rollBack(); try{db()->prepare('INSERT INTO kitchen_api_receive_logs(token_id,transfer_no,endpoint,status,message,payload_json,remote_ip) VALUES(?,?,?,?,?,?,?)')->execute([(int)$tok['id'],$transferNo,'receive-transfer','failed',$e->getMessage(),json_encode($in,JSON_UNESCAPED_UNICODE),$_SERVER['REMOTE_ADDR']??'']);}catch(Throwable $x){} out(['ok'=>false,'error'=>$e->getMessage()],500); }
