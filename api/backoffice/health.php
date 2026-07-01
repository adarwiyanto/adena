<?php
require_once __DIR__ . '/../../core/api_pairing.php';
$conn=pairing_auth('readonly');
pairing_ok(['message'=>'Adena Back Office API aktif','system'=>'adena','scope'=>$conn['access_scope'],'time'=>date('Y-m-d H:i:s')]);
