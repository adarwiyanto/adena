<?php
require_once __DIR__ . '/../../core/api_pairing.php';
pairing_auth('backup.read');

function bo_export_table_exists(string $table): bool {
  try {
    $st=db()->prepare('SELECT COUNT(*) FROM information_schema.TABLES WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME=?');
    $st->execute([$table]);
    return (int)$st->fetchColumn()>0;
  } catch(Throwable $e) { return false; }
}

function bo_export_column_exists(string $table,string $column): bool {
  try {
    $st=db()->prepare('SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME=? AND COLUMN_NAME=?');
    $st->execute([$table,$column]);
    return (int)$st->fetchColumn()>0;
  } catch(Throwable $e) { return false; }
}

$dataset=strtolower(trim((string)($_GET['dataset'] ?? '')));
$limit=(int)($_GET['limit'] ?? 500);
if($limit<1 || $limit>2000) $limit=500;
$allowed=[
  'products'=>'products',
  'sales'=>'sales',
  'sale_payments'=>'sale_payments',
  'stock_ledger'=>'stock_ledger',
  'stock_transfers'=>'stock_transfers',
  'stock_transfer_items'=>'stock_transfer_items',
];
if(!isset($allowed[$dataset])) pairing_err('Dataset backup tidak dikenal.',422);
$table=$allowed[$dataset];
if(!bo_export_table_exists($table)) pairing_ok(['data'=>[],'count'=>0,'message'=>'Tabel '.$table.' belum tersedia.']);

$orderCol=bo_export_column_exists($table,'updated_at')?'updated_at':(bo_export_column_exists($table,'created_at')?'created_at':'id');
$since=trim((string)($_GET['since'] ?? ''));
$where=''; $params=[];
if($since!=='' && in_array($orderCol,['updated_at','created_at'],true)){
  $where="WHERE {$orderCol} >= ?";
  $params[]=$since;
}
$sql="SELECT * FROM {$table} {$where} ORDER BY {$orderCol} DESC LIMIT {$limit}";
$st=db()->prepare($sql); $st->execute($params);
$rows=$st->fetchAll(PDO::FETCH_ASSOC) ?: [];
pairing_ok(['data'=>$rows,'count'=>count($rows),'dataset'=>$dataset,'dry_run'=>isset($_GET['dry_run'])]);
