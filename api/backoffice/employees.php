<?php
require_once __DIR__ . '/../../core/api_pairing.php';
require_once __DIR__ . '/../../core/rbac.php';
pairing_auth('users.view');
ensure_api_pairing_schema();
ensure_rbac_schema();

function adena_employee_role_label(string $roleKey): string {
  $roleKey=strtolower(trim($roleKey));
  return match($roleKey){
    'owner' => 'Owner',
    'admin' => 'Admin Toko',
    'manager_cabang','manager' => 'Manajer Toko',
    default => 'Pegawai Toko',
  };
}

try{
  $hasIsActive=pairing_column_exists('users','is_active');
  $activeSql=$hasIsActive ? 'COALESCE(u.is_active,1)' : '1';
  $sql="SELECT u.id, COALESCE(NULLIF(u.name,''),u.username) name, u.username, u.email, u.role, {$activeSql} is_active, r.role_key
        FROM users u
        LEFT JOIN roles r ON r.id=u.role_id
        ORDER BY name";
  $rows=[];
  foreach(db()->query($sql)->fetchAll(PDO::FETCH_ASSOC) as $r){
    $roleKey=strtolower(trim((string)($r['role_key'] ?: $r['role'] ?: 'pegawai_toko')));
    if($roleKey==='superadmin') $roleKey='owner';
    if(in_array($roleKey,['pegawai','user','kasir','gudang','pegawai_cabang'],true)) $roleKey='pegawai_toko';
    if($roleKey==='manager') $roleKey='manager_cabang';
    $rows[]=[
      'source'=>'adena',
      'employee_id'=>(string)$r['id'],
      'name'=>(string)$r['name'],
      'username'=>(string)$r['username'],
      'email'=>(string)($r['email']??''),
      'role_key'=>$roleKey,
      'role'=>adena_employee_role_label($roleKey),
      'location'=>'Toko',
      'is_active'=>(int)($r['is_active']??1),
    ];
  }
  pairing_ok(['data'=>$rows,'count'=>count($rows),'message'=>'Data pegawai berhasil dibaca.']);
}catch(Throwable $e){
  error_log('[Adena Backoffice Employees API] '.$e->getMessage());
  pairing_err('Data pegawai gagal dibaca dari database.',500,['data'=>[],'count'=>0]);
}
