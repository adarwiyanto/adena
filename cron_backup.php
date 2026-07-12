<?php
require_once __DIR__.'/core/backup_adapter.php';
$svc=adena_backup_service(); $cli=PHP_SAPI==='cli'; $key=(string)($_GET['key']??''); if(!$cli&&!hash_equals((string)$svc->get('cron_secret',''),$key)){http_response_code(403);exit('Forbidden');}
header('Content-Type: application/json; charset=utf-8'); try{$r=$svc->runDue();echo json_encode(['ok'=>true,'result'=>$r],JSON_UNESCAPED_SLASHES|JSON_UNESCAPED_UNICODE);}catch(Throwable $e){http_response_code(500);echo json_encode(['ok'=>false,'error'=>$e->getMessage()],JSON_UNESCAPED_UNICODE);}
