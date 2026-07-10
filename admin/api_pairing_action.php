<?php
require_once __DIR__ . '/../core/db.php';
require_once __DIR__ . '/../core/functions.php';
require_once __DIR__ . '/../core/security.php';
require_once __DIR__ . '/../core/auth.php';
require_once __DIR__ . '/../core/rbac.php';
require_once __DIR__ . '/../core/api_pairing.php';
start_secure_session(); require_login(); ensure_api_pairing_schema();
if(function_exists('csrf_check')) csrf_check();
$me=current_user(); $uid=(int)($me['id']??0); $act=$_POST['act']??'';
function go_pair($m=''){ header('Location: '.base_url('admin/api_pairing.php').($m?'?msg='.urlencode($m):'')); exit; }
if(!current_user_is_owner() && !has_menu_access($me,'settings','edit')) go_pair('Akses ditolak. Hanya owner/admin berizin yang boleh mengelola pairing API.');
try{
 if($act==='create_request'){
   $name=trim((string)($_POST['connection_name']??'Koneksi Baru')); $url=pairing_normalize_url((string)($_POST['base_url']??'')); $target=trim((string)($_POST['target_type']??'adena_store'));
   if($url==='') throw new RuntimeException('URL tujuan wajib diisi.');
   $secret=pairing_secret(); $code=pairing_request_code('ADENA'); $scope=pairing_scope_for('adena_store',$target);
   $payload=['request_code'=>$code,'request_secret_hash'=>password_hash($secret,PASSWORD_DEFAULT),'requester_name'=>setting('store_name','Adena Store'),'requester_type'=>'adena_store','requester_base_url'=>app_config()['app']['base_url']??'','target_type'=>$target,'callback_url'=>''];
   $res=pairing_remote_json($url,'api/pairing/request.php',$payload,'POST');
   db()->prepare("INSERT INTO api_pairing_requests(direction,request_code,request_secret_hash,requester_name,requester_type,requester_base_url,target_name,target_type,target_base_url,requested_scope,status,last_message,created_at) VALUES('outgoing',?,?,?,?,?,?,?,?,?,?,?,NOW())")
     ->execute([$code,password_hash($secret,PASSWORD_DEFAULT),$payload['requester_name'],'adena_store',(string)$payload['requester_base_url'],$name,$target,$url,$scope,!empty($res['ok'])?'pending':'failed',(string)($res['message']??$res['_error']??'')]);
   db()->prepare('UPDATE api_pairing_requests SET local_secret_encrypted=? WHERE request_code=?')->execute([pairing_encrypt_secret($secret),$code]);
   go_pair(!empty($res['ok'])?'Request pairing terkirim.':'Request gagal: '.($res['message']??'error'));
 }
 if($act==='approve'){
   $id=(int)($_POST['id']??0); $r=db()->prepare("SELECT * FROM api_pairing_requests WHERE id=? AND direction='incoming' AND status='pending'"); $r->execute([$id]); $req=$r->fetch(PDO::FETCH_ASSOC); if(!$req) throw new RuntimeException('Request tidak ditemukan.');
   $token=bin2hex(random_bytes(32)); $hash=hash('sha256',$token);
   $safeScope=pairing_scope_for((string)$req['requester_type'],(string)$req['target_type']);
   db()->prepare("INSERT INTO api_connections(connection_name,connection_type,remote_base_url,remote_system_type,access_scope,token_hash,status,paired_from_request_code,paired_by,paired_at) VALUES(?,?,?,?,?,?,'active',?,?,NOW())")
     ->execute([$req['requester_name'],$req['requester_type'],$req['requester_base_url'],$req['requester_type'],$safeScope,$hash,$req['request_code'],$uid]);
   db()->prepare("UPDATE api_pairing_requests SET requested_scope=?,status='approved',access_token_encrypted=?,token_hash=?,approved_by=?,approved_at=NOW(),last_message='Approved' WHERE id=?")->execute([$safeScope,pairing_encrypt_secret($token),$hash,$uid,$id]);
   go_pair('Pairing disetujui. Token otomatis siap dipakai oleh peminta.');
 }
 if($act==='reject'){
   $id=(int)($_POST['id']??0); db()->prepare("UPDATE api_pairing_requests SET status='rejected',reject_reason=?,rejected_by=?,rejected_at=NOW(),last_message=? WHERE id=? AND direction='incoming'")->execute([trim((string)($_POST['reason']??'')),$uid,'Rejected',$id]); go_pair('Pairing ditolak.');
 }
 if($act==='check_status'){
   $id=(int)($_POST['id']??0); $st=db()->prepare("SELECT * FROM api_pairing_requests WHERE id=? AND direction='outgoing'"); $st->execute([$id]); $r=$st->fetch(PDO::FETCH_ASSOC); if(!$r) throw new RuntimeException('Request outgoing tidak ditemukan.');
   $secret=pairing_decrypt_secret($r['local_secret_encrypted'] ?? '');
   $res=pairing_remote_json($r['target_base_url'],'api/pairing/status.php',['request_code'=>$r['request_code'],'request_secret'=>$secret],'GET');
   $status=(string)($res['status']??($res['ok']?'pending':'failed'));
   db()->prepare('UPDATE api_pairing_requests SET status=?,last_checked_at=NOW(),last_message=? WHERE id=?')->execute([$status,(string)($res['message']??''),$id]);
   if($status==='approved' && !empty($res['access_token'])){
     $plain=(string)$res['access_token'];
     $safeScope=pairing_scope_for('adena_store',(string)$r['target_type']);
     db()->prepare("INSERT INTO api_connections(connection_name,connection_type,remote_base_url,remote_system_type,access_scope,token_hash,token_encrypted,status,paired_from_request_code,paired_by,paired_at) VALUES(?,?,?,?,?,?,?,'active',?,?,NOW())")
       ->execute([$r['target_name']?:$r['target_base_url'],$r['target_type'],$r['target_base_url'],$r['target_type'],$safeScope,hash('sha256',$plain),pairing_encrypt_secret($plain),$r['request_code'],$uid]);
   }
   go_pair('Status pairing: '.$status);
 }
 if($act==='test_connection'){
   $id=(int)($_POST['id']??0); $st=db()->prepare('SELECT * FROM api_connections WHERE id=?'); $st->execute([$id]); $c=$st->fetch(PDO::FETCH_ASSOC); if(!$c) throw new RuntimeException('Koneksi tidak ditemukan.');
   $token=pairing_decrypt_secret($c['token_encrypted']??''); if($token==='') throw new RuntimeException('Koneksi ini adalah koneksi masuk; tidak punya token keluar untuk test remote.');
   $res=pairing_remote_json($c['remote_base_url'],'api/pairing/test.php',[],'GET',$token); $ok=!empty($res['ok']);
   db()->prepare('UPDATE api_connections SET last_test_at=NOW(),last_test_status=?,last_test_message=? WHERE id=?')->execute([$ok?'ok':'failed',(string)($res['message']??$res['_error']??''),$id]);
   go_pair($ok?'Test koneksi berhasil.':'Test koneksi gagal: '.($res['message']??'error'));
 }
}catch(Throwable $e){ go_pair('Error: '.$e->getMessage()); }
go_pair();
