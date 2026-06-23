<?php
require_once __DIR__.'/../../../core/db.php';
require_once __DIR__.'/_auth.php';
header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');

function out($d,$c=200){http_response_code($c);echo json_encode($d,JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES);exit;}
function ensure_tables(){ $sql=file_get_contents(__DIR__.'/../../../db/toko_api_dapur_patch.sql'); foreach(array_filter(array_map('trim', explode(';',$sql))) as $q){ if($q!=='') db()->exec($q); } }

ensure_tables();
$tok = kitchen_api_find_token(['products.view','master.view','stock_transfer']);
$rows=[];
if (db()->query("SHOW TABLES LIKE 'products'")->fetchColumn()) {
  $rows=db()->query('SELECT * FROM products ORDER BY name')->fetchAll(PDO::FETCH_ASSOC);
}
kitchen_api_touch_token($tok);
out(['ok'=>true,'products'=>$rows,'total'=>count($rows),'token_source'=>$tok['source']]);
