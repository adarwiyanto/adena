<?php
require_once __DIR__.'/db.php'; require_once __DIR__.'/functions.php'; require_once __DIR__.'/backup_google.php';
function adena_backup_service(): GoogleDriveBackupService {
 static $s=null; if($s) return $s; $cfg=app_config();
 return $s=new GoogleDriveBackupService(['pdo'=>db(),'db'=>$cfg['db'],'app_key'=>'ADENA','app_name'=>$cfg['app']['name']??'Adena','root_path'=>dirname(__DIR__),'private_path'=>dirname(__DIR__).'/storage/private_backup','jobs_table'=>'backup_jobs','timezone'=>$cfg['app']['timezone']??'Asia/Jakarta','get_setting'=>fn($k,$d=null)=>setting($k,$d),'set_setting'=>fn($k,$v)=>set_setting($k,$v)]);
}
