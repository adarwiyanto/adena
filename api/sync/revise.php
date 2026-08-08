<?php
require_once __DIR__ . '/../helpers.php';
require_once __DIR__ . '/../../core/sales_revision.php';
if (($_SERVER['REQUEST_METHOD'] ?? 'GET') !== 'POST') api_err('Method tidak diizinkan.',405);
$token=api_verify_token();
$body=json_decode(file_get_contents('php://input'),true); if(!is_array($body)) api_err('Body JSON tidak valid.',422);
$userId=(int)($body['user_id'] ?? 0); if($userId<=0) api_err('user_id wajib ada.',422);
ensure_sales_revision_schema();
$stmt=db()->prepare("SELECT u.id,u.username,u.name,COALESCE(r.role_key,u.role,'kasir') role FROM users u LEFT JOIN roles r ON r.id=u.role_id WHERE u.id=? LIMIT 1");
$stmt->execute([$userId]);$actor=$stmt->fetch(PDO::FETCH_ASSOC);if(!$actor)api_err('User tidak ditemukan.',404);
if(!in_array(strtolower((string)$actor['role']),['owner','admin'],true))api_err('Anda tidak diizinkan mengedit transaksi.',403);
$saleCode=trim((string)($body['sale_code']??''));if($saleCode==='')api_err('sale_code wajib ada.',422);
if(isset($body['expected_revision_no'])){$q=db()->prepare("SELECT MAX(revision_no) FROM sales WHERE transaction_code=? AND is_active_revision=1");$q->execute([$saleCode]);$current=(int)$q->fetchColumn();if($current!==(int)$body['expected_revision_no'])api_err('Konflik revisi: transaksi telah berubah di perangkat lain.',409);}
try{$newCode=revise_sale_transaction($body,$actor);api_ok(['transaction_code'=>$newCode]);}catch(Throwable $e){api_err($e->getMessage(),422);}
