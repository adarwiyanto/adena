<?php
require_once __DIR__ . '/../../core/api_pairing.php';
$conn=pairing_auth('readonly');
pairing_ok(['message'=>'Koneksi pairing aktif.','connection_id'=>(int)$conn['id'],'scope'=>$conn['access_scope'],'system'=>'adena']);
