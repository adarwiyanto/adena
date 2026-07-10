<?php
require_once __DIR__.'/../../../core/db.php';
header('Content-Type: application/json; charset=utf-8');
echo json_encode(['ok'=>true,'message'=>'API Dapur receiver aktif','time'=>date('c')], JSON_UNESCAPED_UNICODE);
