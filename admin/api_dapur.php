<?php
require_once __DIR__.'/../core/auth.php';
require_once __DIR__.'/../core/functions.php';
require_once __DIR__.'/../core/csrf.php';
require_admin();
if (function_exists('require_menu_access')) { try { require_menu_access('settings'); } catch (Throwable $e) {} }
function kd_table_exists($t){$st=db()->prepare('SHOW TABLES LIKE ?');$st->execute([$t]);return (bool)$st->fetchColumn();}
function kd_init(){
  $sql=file_get_contents(__DIR__.'/../db/toko_api_dapur_patch.sql');
  foreach(array_filter(array_map('trim', explode(';',$sql))) as $q){ if($q!=='') db()->exec($q); }
}
kd_init();
$msg=''; $plain='';
if(($_SERVER['REQUEST_METHOD']??'GET')==='POST'){
  if(function_exists('csrf_verify')) { csrf_verify(); }
  $act=$_POST['act']??'';
  if($act==='generate'){
    $plain='tkd_'.bin2hex(random_bytes(24));
    $st=db()->prepare('INSERT INTO kitchen_api_tokens(token_name,token_hash,store_code,permissions_json,is_active) VALUES(?,?,?,?,1)');
    $st->execute([trim($_POST['token_name']??'Dapur Adena'),hash('sha256',$plain),trim($_POST['store_code']??''),json_encode(['kitchen.receive','products.view'])]);
    $msg='Token dibuat. Simpan sekarang: '.$plain;
  }
  if($act==='revoke'){
    $st=db()->prepare('UPDATE kitchen_api_tokens SET is_active=0, revoked_at=NOW() WHERE id=?');$st->execute([(int)$_POST['id']]);$msg='Token dinonaktifkan.';
  }
}
$tokens=db()->query('SELECT * FROM kitchen_api_tokens ORDER BY id DESC')->fetchAll(PDO::FETCH_ASSOC);
$logs=db()->query('SELECT * FROM kitchen_api_receive_logs ORDER BY id DESC LIMIT 50')->fetchAll(PDO::FETCH_ASSOC);
?><!doctype html><html><head><meta charset="utf-8"><title>Setting API Dapur</title><link rel="stylesheet" href="../assets/app.css"></head><body><div class="layout"><?php if(is_file(__DIR__.'/partials_sidebar.php')) include __DIR__.'/partials_sidebar.php'; ?><main class="content"><div class="page-head"><h1>Setting API Dapur</h1><p>Toko hanya menerima kiriman stok dari Dapur Adena. Tidak mengubah alur POS toko.</p></div><?php if($msg): ?><div class="alert alert-info" style="padding:12px;border-radius:10px;background:#fff3cd;margin-bottom:12px"><?=htmlspecialchars($msg)?></div><?php endif; ?><section class="card"><h2>Generate Token per Toko</h2><form method="post" class="form-grid"><?php if(function_exists('csrf_field')) echo csrf_field(); ?><input type="hidden" name="act" value="generate"><label>Nama Token<input name="token_name" value="Dapur Adena"></label><label>Kode Toko<input name="store_code" placeholder="TOKO_001"></label><button class="btn">Generate Token</button></form><p><b>Endpoint penerimaan:</b> <code><?=htmlspecialchars(rtrim(app_config()['app']['base_url'] ?? '', '/'))?>/api/v1/kitchen/receive-transfer.php</code></p></section><section class="card"><h2>Token Aktif</h2><table><tr><th>Nama</th><th>Kode Toko</th><th>Status</th><th>Last Used</th><th>Aksi</th></tr><?php foreach($tokens as $t): ?><tr><td><?=htmlspecialchars($t['token_name'])?></td><td><?=htmlspecialchars($t['store_code']??'')?></td><td><?=((int)$t['is_active']===1?'Aktif':'Nonaktif')?></td><td><?=htmlspecialchars($t['last_used_at']??'')?></td><td><?php if((int)$t['is_active']===1): ?><form method="post" onsubmit="return confirm('Nonaktifkan token ini?')"><?php if(function_exists('csrf_field')) echo csrf_field(); ?><input type="hidden" name="act" value="revoke"><input type="hidden" name="id" value="<?=(int)$t['id']?>"><button class="btn danger">Revoke</button></form><?php endif; ?></td></tr><?php endforeach; ?></table></section><section class="card"><h2>Log Penerimaan API</h2><table><tr><th>Waktu</th><th>Transfer No</th><th>Status</th><th>Message</th><th>IP</th></tr><?php foreach($logs as $l): ?><tr><td><?=htmlspecialchars($l['created_at'])?></td><td><?=htmlspecialchars($l['transfer_no']??'')?></td><td><?=htmlspecialchars($l['status'])?></td><td><?=htmlspecialchars($l['message']??'')?></td><td><?=htmlspecialchars($l['remote_ip']??'')?></td></tr><?php endforeach; ?></table></section></main></div></body></html>
