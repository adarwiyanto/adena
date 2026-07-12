<?php
require_once __DIR__ . '/db.php';
require_once __DIR__ . '/functions.php';

function adena_module_table_exists(string $table): bool {
  try {
    $st=db()->prepare('SELECT COUNT(*) FROM information_schema.TABLES WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME=?');
    $st->execute([$table]);
    return (int)$st->fetchColumn()>0;
  } catch(Throwable $e){ return false; }
}

function adena_module_column_exists(string $table,string $column): bool {
  try {
    $st=db()->prepare('SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME=? AND COLUMN_NAME=?');
    $st->execute([$table,$column]);
    return (int)$st->fetchColumn()>0;
  } catch(Throwable $e){ return false; }
}

function adena_uuid_v4(): string {
  $data=random_bytes(16);
  $data[6]=chr((ord($data[6]) & 0x0f) | 0x40);
  $data[8]=chr((ord($data[8]) & 0x3f) | 0x80);
  return vsprintf('%s%s-%s-%s-%s-%s%s%s',str_split(bin2hex($data),4));
}

function adena_store_identity(): array {
  $name='Adena Store'; $logo='';
  try { $name=(string)setting('store_name',$name); $logo=(string)setting('store_logo',''); } catch(Throwable $e){}
  $logoUrl='';
  try { $logoUrl=$logo!==''&&function_exists('upload_url')?(string)upload_url($logo,'image'):(function_exists('base_url')?(string)base_url('assets/favicon.svg'):''); } catch(Throwable $e){}
  return ['name'=>$name,'type'=>'store','base_url'=>function_exists('base_url')?rtrim((string)base_url(''),'/'):'','logo_url'=>$logoUrl];
}

function adena_owner_admin_guard(): array {
  $u=current_user() ?: [];
  $resolved=function_exists('resolve_user_role') ? resolve_user_role($u) : ['role_key'=>$u['role']??''];
  $role=strtolower((string)($resolved['role_key']??$u['role']??''));
  if(!in_array($role,['owner','admin'],true)){
    http_response_code(403);
    die('Akses hanya untuk Owner dan Admin.');
  }
  return $u;
}

function adena_store_employee_rows(bool $activeOnly=true): array {
  if(!adena_module_table_exists('users')) return [];
  $join=''; $roleExpr="COALESCE(NULLIF(TRIM(u.role),''),'pegawai')";
  if(adena_module_column_exists('users','role_id') && adena_module_table_exists('roles')){
    $join=' LEFT JOIN roles r ON r.id=u.role_id ';
    if(adena_module_column_exists('roles','role_key')) $roleExpr="COALESCE(NULLIF(TRIM(r.role_key),''),NULLIF(TRIM(u.role),''),'pegawai')";
  }
  $where=["LOWER($roleExpr) NOT IN ('owner','superadmin')"];
  if($activeOnly && adena_module_column_exists('users','is_active')) $where[]='COALESCE(u.is_active,1)=1';
  $sql="SELECT u.id,u.name,u.username,$roleExpr role_key".(adena_module_column_exists('users','is_active')?',COALESCE(u.is_active,1) is_active':',1 is_active')." FROM users u $join WHERE ".implode(' AND ',$where).' ORDER BY u.name';
  try { return db()->query($sql)->fetchAll(PDO::FETCH_ASSOC) ?: []; } catch(Throwable $e){ return []; }
}

function adena_finance_outbox(string $entityType,string $entityId,string $operation,array $payload,int $version=1): void {
  if(!adena_module_table_exists('sync_outbox')) return;
  try {
    db()->prepare('INSERT INTO sync_outbox(event_uuid,entity_type,entity_id,operation,entity_version,payload_json) VALUES(?,?,?,?,?,?)')
      ->execute([adena_uuid_v4(),$entityType,$entityId,$operation,$version,json_encode($payload,JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES)]);
  } catch(Throwable $e){}
}

function adena_finance_audit(string $entityType,int $entityId,string $action,array $payload,int $userId): void {
  if(!adena_module_table_exists('finance_audit_logs')) return;
  try { db()->prepare('INSERT INTO finance_audit_logs(entity_type,entity_id,action_key,payload_json,acted_by) VALUES(?,?,?,?,?)')->execute([$entityType,$entityId,$action,json_encode($payload,JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES),$userId]); } catch(Throwable $e){}
}

function adena_kpi_audit(?int $assessmentId,string $action,?string $oldStatus,?string $newStatus,array $payload,int $userId): void {
  if(!adena_module_table_exists('store_kpi_audit_logs')) return;
  try { db()->prepare('INSERT INTO store_kpi_audit_logs(assessment_id,action_key,old_status,new_status,payload_json,acted_by) VALUES(?,?,?,?,?,?)')->execute([$assessmentId,$action,$oldStatus,$newStatus,json_encode($payload,JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES),$userId]); } catch(Throwable $e){}
}

function adena_period_bounds(string $month): array {
  if(!preg_match('/^\d{4}-\d{2}$/',$month)) $month=date('Y-m');
  $start=$month.'-01';
  return [$month,$start,date('Y-m-d',strtotime($start.' +1 month'))];
}
