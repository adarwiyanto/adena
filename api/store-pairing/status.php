<?php
require_once __DIR__.'/../../core/store_pairing.php';
if(($_SERVER['REQUEST_METHOD']??'')!=='POST') pairing_err('Method not allowed',405);
sp_schema(); $in=pairing_input(); $code=(string)($in['request_code']??''); $secret=(string)($in['request_secret']??'');
$st=db()->prepare("SELECT * FROM api_pairing_requests WHERE request_code=? AND direction='incoming' AND requester_type='adena_store' LIMIT 1"); $st->execute([$code]); $r=$st->fetch(PDO::FETCH_ASSOC);
if(!$r || strlen($secret)<40 || !password_verify($secret,(string)$r['request_secret_hash'])) pairing_err('Request authentication failed',401);
if($r['status']==='pending' && strtotime($r['expires_at']??'')<time()) { db()->prepare("UPDATE api_pairing_requests SET status='expired' WHERE id=?")->execute([$r['id']]);$r['status']='expired'; }
$data=['status'=>$r['status'],'request_code'=>$code];
if($r['status']==='approved') { $data['access_token']=pairing_decrypt_secret($r['access_token_encrypted']); $data['remote_name']=sp_label(); $data['remote_base_url']=sp_local_url(); }
pairing_ok($data);
