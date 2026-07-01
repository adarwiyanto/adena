<?php
require_once __DIR__ . '/../../core/api_pairing.php';
pairing_auth('readonly');
ensure_api_pairing_schema();
$rows=[];
try{
  $sql="SELECT u.id, COALESCE(NULLIF(u.name,''),u.username) name, u.username, u.email, u.role, COALESCE(u.is_active,1) is_active FROM users u ORDER BY name";
  foreach(db()->query($sql)->fetchAll(PDO::FETCH_ASSOC) as $r){ $rows[]=['source'=>'adena','employee_id'=>(string)$r['id'],'name'=>(string)$r['name'],'username'=>(string)$r['username'],'email'=>(string)($r['email']??''),'role'=>(string)($r['role']??''),'location'=>'Toko','is_active'=>(int)($r['is_active']??1)]; }
}catch(Throwable $e){}
pairing_ok(['data'=>$rows,'count'=>count($rows)]);
