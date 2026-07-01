<?php
require_once __DIR__ . '/../../core/api_pairing.php';
pairing_auth('readonly');
$today=date('Y-m-d'); $data=['sales_today'=>0,'revenue_today'=>0,'products'=>0,'pending_pairing'=>pairing_pending_count()];
try{ $data['products']=(int)db()->query('SELECT COUNT(*) FROM products')->fetchColumn(); }catch(Throwable $e){}
try{ $st=db()->prepare("SELECT COUNT(DISTINCT COALESCE(NULLIF(transaction_code,''), CONCAT('LEGACY-',id))) c, COALESCE(SUM(total),0) s FROM sales WHERE sold_at>=? AND sold_at<DATE_ADD(?, INTERVAL 1 DAY)"); $st->execute([$today,$today]); $r=$st->fetch(PDO::FETCH_ASSOC); $data['sales_today']=(int)($r['c']??0); $data['revenue_today']=(float)($r['s']??0); }catch(Throwable $e){}
pairing_ok(['data'=>$data]);
