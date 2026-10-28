<?php
require_once __DIR__.'/../../core/store_pairing.php';
if(($_SERVER['REQUEST_METHOD']??'')!=='POST') pairing_err('Method not allowed',405);
try {
 sp_schema(); $in=pairing_input();
 $url=sp_url((string)($in['requester_base_url']??'')); $code=trim((string)($in['request_code']??'')); $secretHash=(string)($in['request_secret_hash']??''); $pairCode=strtoupper(trim((string)($in['pairing_code']??'')));
 if(!preg_match('/^STORE-[A-Z0-9-]{12,90}$/',$code)||!preg_match('/^\$2[ayb]\$/',$secretHash)||strlen($secretHash)>255) throw new RuntimeException('Request invalid.');
 $stmt=db()->prepare('SELECT id FROM store_pairing_codes WHERE code_hash=? AND used_at IS NULL AND expires_at>NOW() LIMIT 1'); $stmt->execute([hash('sha256',$pairCode)]); $id=$stmt->fetchColumn();
 if(!$id) pairing_err('Kode pairing salah, kedaluwarsa, atau sudah digunakan.',403);
 $pdo=db(); $pdo->beginTransaction();
 $lock=$pdo->prepare('UPDATE store_pairing_codes SET used_at=NOW() WHERE id=? AND used_at IS NULL AND expires_at>NOW()'); $lock->execute([$id]); if(!$lock->rowCount()) throw new RuntimeException('Kode sudah digunakan.');
 $st=$pdo->prepare("INSERT INTO api_pairing_requests(direction,request_code,request_secret_hash,requester_name,requester_type,requester_base_url,target_type,requested_scope,status,expires_at) VALUES('incoming',?,?,?,'adena_store',?,'adena_store','store_product_readonly','pending',DATE_ADD(NOW(),INTERVAL 48 HOUR))");
 $st->execute([$code,$secretHash,substr(trim((string)($in['requester_name']??'Toko')),0,180),$url]); $pdo->commit();
 pairing_ok(['request_code'=>$code,'status'=>'pending']);
} catch(Throwable $e) {if(isset($pdo)&&$pdo->inTransaction())$pdo->rollBack(); pairing_err($e->getMessage(),422);}
