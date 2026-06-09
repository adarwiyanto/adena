<?php
require_once __DIR__.'/../../../core/db.php';
header('Content-Type: application/json; charset=utf-8');
function out($d,$c=200){http_response_code($c);echo json_encode($d,JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES);exit;}
function ensure_tables(){ $sql=file_get_contents(__DIR__.'/../../../db/toko_api_dapur_patch.sql'); foreach(array_filter(array_map('trim', explode(';',$sql))) as $q){ if($q!=='') db()->exec($q); } }
function bearer(){ $h=$_SERVER['HTTP_AUTHORIZATION'] ?? $_SERVER['REDIRECT_HTTP_AUTHORIZATION'] ?? ''; if(preg_match('/Bearer\s+(.+)/i',$h,$m)) return trim($m[1]); return $_GET['token'] ?? ''; }
ensure_tables();
$token=bearer(); if($token==='') out(['ok'=>false,'error'=>'Token kosong'],401);
$st=db()->prepare('SELECT * FROM kitchen_api_tokens WHERE token_hash=? AND is_active=1 LIMIT 1'); $st->execute([hash('sha256',$token)]); $tok=$st->fetch(PDO::FETCH_ASSOC); if(!$tok) out(['ok'=>false,'error'=>'Token tidak valid'],401);
$perms=json_decode((string)($tok['permissions_json']??'[]'),true) ?: [];
if(!in_array('products.view',$perms,true) && !in_array('*',$perms,true)) out(['ok'=>false,'error'=>'Permission products.view ditolak'],403);
$rows=[];
if (db()->query("SHOW TABLES LIKE 'products'")->fetchColumn()) {
  $rows=db()->query('SELECT * FROM products ORDER BY name')->fetchAll(PDO::FETCH_ASSOC);
}
db()->prepare('UPDATE kitchen_api_tokens SET last_used_at=NOW() WHERE id=?')->execute([(int)$tok['id']]);
out(['ok'=>true,'products'=>$rows]);
