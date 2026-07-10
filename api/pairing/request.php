<?php
require_once __DIR__ . '/../../core/api_pairing.php';
if(($_SERVER['REQUEST_METHOD']??'GET')!=='POST') pairing_err('Method tidak diizinkan',405);
ensure_api_pairing_schema(); $in=pairing_input();
$requesterName=trim((string)($in['requester_name']??'Aplikasi Peminta'));
$requesterType=trim((string)($in['requester_type']??'external'));
$requesterUrl=pairing_normalize_url((string)($in['requester_base_url']??''));
$targetType=trim((string)($in['target_type']??'adena_store'));
$code=trim((string)($in['request_code']??pairing_request_code('PAIR')));
$secretHash=trim((string)($in['request_secret_hash']??''));
$accessToken=trim((string)($in['access_token']??''));
$accessTokenHash=trim((string)($in['access_token_hash']??''));
$callback=pairing_normalize_url((string)($in['callback_url']??''));
if($requesterUrl==='') pairing_err('Requester base URL wajib diisi.',422);
if($requesterType==='backoffice' && strlen($accessToken)<40) pairing_err('Token pairing dari Back Office tidak valid.',422);
if($requesterType==='backoffice'){
  $calculatedHash=hash('sha256',$accessToken);
  if($accessTokenHash!=='' && !hash_equals($accessTokenHash,$calculatedHash)) pairing_err('Hash token pairing tidak cocok.',422);
  $accessTokenHash=$calculatedHash;
}
$scope=pairing_scope_for($requesterType,$targetType);
$exists=db()->prepare('SELECT id,status FROM api_pairing_requests WHERE request_code=? LIMIT 1'); $exists->execute([$code]); $old=$exists->fetch(PDO::FETCH_ASSOC);
if($old) pairing_ok(['message'=>'Request pairing sudah tercatat.','request_code'=>$code,'status'=>$old['status']]);
$st=db()->prepare("INSERT INTO api_pairing_requests(direction,request_code,request_secret_hash,requester_name,requester_type,requester_base_url,target_type,requested_scope,status,callback_url,token_hash,expires_at,created_at) VALUES('incoming',?,?,?,?,?,?,?,'pending',?,?,DATE_ADD(NOW(), INTERVAL 48 HOUR),NOW())");
$st->execute([$code,$secretHash,$requesterName,$requesterType,$requesterUrl,$targetType,$scope,$callback,$accessTokenHash!==''?$accessTokenHash:null]);
pairing_ok(['message'=>'Permintaan pairing diterima. Menunggu approval owner/admin.','request_code'=>$code,'status'=>'pending','requested_scope'=>$scope]);
