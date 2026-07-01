<?php
require_once __DIR__ . '/../../core/api_pairing.php';
ensure_api_pairing_schema();
$code=trim((string)($_GET['request_code']??'')); $secret=trim((string)($_GET['request_secret']??''));
if($code==='') pairing_err('request_code wajib diisi.',422);
$st=db()->prepare('SELECT * FROM api_pairing_requests WHERE request_code=? LIMIT 1'); $st->execute([$code]); $r=$st->fetch(PDO::FETCH_ASSOC);
if(!$r) pairing_err('Request pairing tidak ditemukan.',404);
$res=['request_code'=>$code,'status'=>$r['status'],'message'=>$r['last_message'] ?: null,'requested_scope'=>$r['requested_scope']];
if($r['status']==='approved'){
  if(!empty($r['request_secret_hash']) && $secret!=='' && password_verify($secret,(string)$r['request_secret_hash'])){
    $res['access_token']=$r['access_token_plain'];
    $res['remote_system_type']=$r['target_type'];
    $res['access_scope']=$r['requested_scope'];
    $res['connection_name']=$r['requester_name'];
  } else { $res['message']='Approved. Secret belum valid sehingga token tidak dikirim.'; }
}
pairing_ok($res);
