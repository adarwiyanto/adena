<?php
require_once __DIR__ . '/_common.php';
$method = $_SERVER['REQUEST_METHOD'] ?? 'GET';
if ($method === 'GET') {
  $token = api_verify_token('sales.view');
  [$branchSql,$params] = api_v1_filter_branch_sql($token);
  $sql = 'SELECT * FROM sales WHERE 1=1' . $branchSql . ' ORDER BY sold_at DESC, id DESC LIMIT 2000';
  api_ok(['sales'=>api_v1_rows($sql,$params)]);
}
if ($method === 'POST') {
  $token = api_verify_token('sales.push');
  $in = api_v1_input(); $items = $in['items'] ?? [$in]; $count=0;
  foreach ($items as $r) {
    if (!is_array($r)) continue;
    $branchId = (int)($r['branch_id'] ?? ($token['branch_id'] ?? 0));
    $cols = ['transaction_code','transaction_group_uuid','offline_uuid','product_id','branch_id','qty','price_each','total','payment_method','payment_bank','guide_id','guide_name','created_by','sold_at','sale_source','unit_type','local_device_id','local_transaction_id','customer_name','customer_phone'];
    $use=[];$vals=[];
    foreach ($cols as $c) if (api_v1_col_exists('sales',$c) && array_key_exists($c,$r)) { $use[]=$c; $vals[]=$r[$c]; }
    if (api_v1_col_exists('sales','branch_id') && !in_array('branch_id',$use,true)) { $use[]='branch_id'; $vals[]=$branchId ?: null; }
    if (!$use || !in_array('product_id',$use,true)) continue;
    $sql='INSERT INTO sales (`'.implode('`,`',$use).'`) VALUES ('.implode(',',array_fill(0,count($use),'?')).')';
    db()->prepare($sql)->execute($vals); $count++;
  }
  api_ok(['imported'=>$count]);
}
api_err('Method tidak diizinkan.',405);
