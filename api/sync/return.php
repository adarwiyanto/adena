<?php
require_once __DIR__ . '/../helpers.php';
require_once __DIR__ . '/../../core/inventory.php';
require_once __DIR__ . '/../../core/sales_revision.php';
if (($_SERVER['REQUEST_METHOD'] ?? 'GET') !== 'POST') api_err('Method tidak diizinkan.',405);
$token=api_verify_token();$body=json_decode(file_get_contents('php://input'),true);if(!is_array($body))api_err('Body JSON tidak valid.',422);
$userId=(int)($body['user_id']??0);$saleCode=trim((string)($body['sale_code']??''));$reason=trim((string)($body['reason']??''));if($userId<=0||$saleCode===''||$reason==='')api_err('user_id, sale_code dan reason wajib ada.',422);
$stmt=db()->prepare("SELECT u.id,COALESCE(r.role_key,u.role,'kasir') role FROM users u LEFT JOIN roles r ON r.id=u.role_id WHERE u.id=? LIMIT 1");$stmt->execute([$userId]);$actor=$stmt->fetch(PDO::FETCH_ASSOC);if(!$actor)api_err('User tidak ditemukan.',404);if(!in_array(strtolower((string)$actor['role']),['owner','admin'],true))api_err('Anda tidak diizinkan meretur transaksi.',403);
ensure_sales_revision_schema();$chk=db()->prepare("SELECT COUNT(*) FROM sales WHERE transaction_code=? AND is_active_revision=1 AND returned_at IS NOT NULL");$chk->execute([$saleCode]);if((int)$chk->fetchColumn()>0){api_ok(['status'=>'exists','transaction_code'=>$saleCode]);}
try{db()->beginTransaction();rollback_sale_stock_in_by_transaction_code($saleCode,$userId,'Rollback retur transaksi POS Android');$u=db()->prepare("UPDATE sales SET return_reason=?,returned_at=NOW() WHERE transaction_code=? AND is_active_revision=1");$u->execute([$reason,$saleCode]);db()->commit();api_ok(['status'=>'returned','transaction_code'=>$saleCode]);}catch(Throwable $e){if(db()->inTransaction())db()->rollBack();api_err($e->getMessage(),422);}
