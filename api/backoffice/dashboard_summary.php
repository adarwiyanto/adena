<?php
require_once __DIR__ . '/../../core/api_pairing.php';
require_once __DIR__ . '/../../core/store_kpi_finance.php';
pairing_auth('readonly');

function adena_bo_sales_filter_sql(): string {
  $where=[];
  if(adena_module_column_exists('sales','return_reason')) $where[]="(return_reason IS NULL OR return_reason='')";
  if(adena_module_column_exists('sales','is_active_revision')) $where[]='COALESCE(is_active_revision,1)=1';
  if(adena_module_column_exists('sales','include_in_sales_report')) $where[]='COALESCE(include_in_sales_report,1)=1';
  return $where?' AND '.implode(' AND ',$where):'';
}
function adena_bo_sales_summary(string $start,string $end): array {
  $out=['transactions'=>0,'revenue'=>0.0];
  if(!adena_module_table_exists('sales')) return $out;
  try{
    $txExpr=adena_module_column_exists('sales','transaction_group_uuid')?"COALESCE(NULLIF(transaction_group_uuid,''),NULLIF(transaction_code,''),CONCAT('LEGACY-',id))":"COALESCE(NULLIF(transaction_code,''),CONCAT('LEGACY-',id))";
    $st=db()->prepare("SELECT COUNT(DISTINCT $txExpr) transactions,COALESCE(SUM(total),0) revenue FROM sales WHERE sold_at>=? AND sold_at<?".adena_bo_sales_filter_sql());$st->execute([$start,$end]);$r=$st->fetch(PDO::FETCH_ASSOC)?:[];$out=['transactions'=>(int)($r['transactions']??0),'revenue'=>(float)($r['revenue']??0)];
  }catch(Throwable $e){$out['error']=$e->getMessage();}
  return $out;
}
function adena_bo_distribution_summary(): array {
  $out=['pending'=>0,'received'=>0,'returned'=>0,'failed'=>0,'total'=>0];
  if(!adena_module_table_exists('kitchen_api_receive_logs')) return $out;
  try{
    $rows=db()->query('SELECT LOWER(COALESCE(status,\'\')) status,COUNT(*) c FROM kitchen_api_receive_logs GROUP BY LOWER(COALESCE(status,\'\'))')->fetchAll(PDO::FETCH_ASSOC)?:[];
    foreach($rows as $r){$s=(string)$r['status'];$c=(int)$r['c'];$out['total']+=$c;if(in_array($s,['pending','pending_confirmation','sent','waiting_confirmation'],true))$out['pending']+=$c;elseif(in_array($s,['confirmed','received','received_by_store','completed','done'],true))$out['received']+=$c;elseif(in_array($s,['returned','rejected','cancelled'],true))$out['returned']+=$c;elseif(in_array($s,['failed','failed_sync','error'],true))$out['failed']+=$c;}
  }catch(Throwable $e){}
  return $out;
}
function adena_bo_production_summary(string $today,string $monthStart,string $monthEnd): array {
  $out=['batches_today'=>0,'qty_today'=>0.0,'batches_month'=>0,'qty_month'=>0.0];
  if(!adena_module_table_exists('production_headers')) return $out;
  try{$st=db()->prepare("SELECT COUNT(*) c,COALESCE(SUM(qty_to_produce),0) q FROM production_headers WHERE production_date=? AND status<>'cancelled'");$st->execute([$today]);$r=$st->fetch(PDO::FETCH_ASSOC)?:[];$out['batches_today']=(int)($r['c']??0);$out['qty_today']=(float)($r['q']??0);}catch(Throwable $e){}
  try{$st=db()->prepare("SELECT COUNT(*) c,COALESCE(SUM(qty_to_produce),0) q FROM production_headers WHERE production_date>=? AND production_date<? AND status<>'cancelled'");$st->execute([$monthStart,$monthEnd]);$r=$st->fetch(PDO::FETCH_ASSOC)?:[];$out['batches_month']=(int)($r['c']??0);$out['qty_month']=(float)($r['q']??0);}catch(Throwable $e){}
  return $out;
}
function adena_bo_kpi_summary(string $period): array {
  $out=['assessed_count'=>0,'draft_count'=>0,'average_score'=>0.0,'highest_score'=>0.0,'lowest_score'=>0.0];
  if(!adena_module_table_exists('store_kpi_assessments')) return $out;
  try{
    $st=db()->prepare("SELECT COUNT(*) total,SUM(status='draft') drafts,SUM(status IN ('final','locked')) assessed,COALESCE(AVG(CASE WHEN status IN ('final','locked') THEN final_score END),0) avg_score,COALESCE(MAX(CASE WHEN status IN ('final','locked') THEN final_score END),0) max_score,COALESCE(MIN(CASE WHEN status IN ('final','locked') THEN final_score END),0) min_score FROM store_kpi_assessments WHERE period_month=? AND deleted_at IS NULL");$st->execute([$period]);$r=$st->fetch(PDO::FETCH_ASSOC)?:[];$out=['assessed_count'=>(int)($r['assessed']??0),'draft_count'=>(int)($r['drafts']??0),'average_score'=>(float)($r['avg_score']??0),'highest_score'=>(float)($r['max_score']??0),'lowest_score'=>(float)($r['min_score']??0)];
  }catch(Throwable $e){}
  return $out;
}
function adena_bo_finance_summary(string $start,string $end,float $sales): array {
  $out=['purchase_total'=>0.0,'purchase_external'=>0.0,'purchase_internal'=>0.0,'expense_total'=>0.0,'payment_pending'=>0.0,'estimated_cash_profit'=>0.0];
  try{if(adena_module_table_exists('purchase_headers')){$st=db()->prepare("SELECT grand_total,notes FROM purchase_headers WHERE purchase_date>=? AND purchase_date<? AND status='posted'");$st->execute([$start,$end]);foreach($st->fetchAll(PDO::FETCH_ASSOC) as $r){$v=(float)$r['grand_total'];$out['purchase_total']+=$v;$notes=strtolower((string)($r['notes']??''));if(str_contains($notes,'dapur')||str_contains($notes,'internal transfer'))$out['purchase_internal']+=$v;else$out['purchase_external']+=$v;}}}catch(Throwable $e){}
  try{if(adena_module_table_exists('expenses')){$st=db()->prepare("SELECT COALESCE(SUM(amount),0) FROM expenses WHERE expense_date>=? AND expense_date<? AND status IN ('approved','paid') AND deleted_at IS NULL");$st->execute([$start,$end]);$out['expense_total']=(float)$st->fetchColumn();}}catch(Throwable $e){}
  try{if(adena_module_table_exists('payment_requests')){$st=db()->prepare("SELECT COALESCE(SUM(amount),0) FROM payment_requests WHERE request_date>=? AND request_date<? AND status IN ('draft','submitted','approved') AND deleted_at IS NULL");$st->execute([$start,$end]);$out['payment_pending']=(float)$st->fetchColumn();}}catch(Throwable $e){}
  $out['estimated_cash_profit']=$sales-$out['purchase_external']-$out['expense_total'];return $out;
}

$today=date('Y-m-d');$tomorrow=date('Y-m-d',strtotime($today.' +1 day'));$month=trim((string)($_GET['month']??date('Y-m')));if(!preg_match('/^\d{4}-\d{2}$/',$month))$month=date('Y-m');$monthStart=$month.'-01';$monthEnd=date('Y-m-d',strtotime($monthStart.' +1 month'));
$todaySales=adena_bo_sales_summary($today,$tomorrow);$monthSales=adena_bo_sales_summary($monthStart,$monthEnd);$employees=count(adena_store_employee_rows(true));$store=adena_store_identity();$distribution=adena_bo_distribution_summary();$production=adena_bo_production_summary($today,$monthStart,$monthEnd);$kpi=adena_bo_kpi_summary($monthStart);$finance=adena_bo_finance_summary($monthStart,$monthEnd,(float)$monthSales['revenue']);$products=0;try{$products=(int)db()->query('SELECT COUNT(*) FROM products')->fetchColumn();}catch(Throwable $e){}
$data=[
 'system'=>['type'=>'adena','name'=>$store['name']], 'store_name'=>$store['name'],'connection_label'=>$store['name'],
 'transactions_today'=>(int)$todaySales['transactions'],'sales_today'=>(int)$todaySales['transactions'],'revenue_today'=>(float)$todaySales['revenue'],'omset_today'=>(float)$todaySales['revenue'],
 'transactions_month'=>(int)$monthSales['transactions'],'revenue_month'=>(float)$monthSales['revenue'],'omset_month'=>(float)$monthSales['revenue'],'omset_bulan_ini'=>(float)$monthSales['revenue'],'monthly_revenue'=>(float)$monthSales['revenue'],
 'employees_count'=>$employees,'employee_count'=>$employees,'active_employees'=>$employees,'products'=>$products,'active_products'=>$products,
 'production'=>['batches_today'=>$production['batches_today'],'qty_today'=>$production['qty_today'],'batches_month'=>$production['batches_month'],'qty_month'=>$production['qty_month']],
 'productions_today'=>$production['batches_today'],'production_qty_today'=>$production['qty_today'],
 'distribution'=>['pending'=>$distribution['pending'],'received'=>$distribution['received'],'returned'=>$distribution['returned'],'failed'=>$distribution['failed'],'total'=>$distribution['total']],
 'pending_distributions'=>$distribution['pending'],'distribution_pending'=>$distribution['pending'],'distribution_received'=>$distribution['received'],'distribution_returned'=>$distribution['returned'],'distribution_failed'=>$distribution['failed'],
 'kpi'=>array_merge($kpi,['employee_count'=>$employees,'unassessed_count'=>max(0,$employees-$kpi['assessed_count'])]),
 'finance'=>$finance,'purchase_total_month'=>$finance['purchase_total'],'expense_total_month'=>$finance['expense_total'],'estimated_profit_month'=>$finance['estimated_cash_profit'],
 'today'=>['transactions'=>(int)$todaySales['transactions'],'revenue'=>(float)$todaySales['revenue'],'omset'=>(float)$todaySales['revenue']],
 'month'=>['period'=>$month,'transactions'=>(int)$monthSales['transactions'],'revenue'=>(float)$monthSales['revenue'],'omset'=>(float)$monthSales['revenue']],
 'pending_pairing'=>pairing_pending_count(),'period'=>['today'=>$today,'month'=>$month,'month_start'=>$monthStart,'month_end_exclusive'=>$monthEnd]
];
$errors=[];if(!empty($todaySales['error']))$errors['today_sales']=$todaySales['error'];if(!empty($monthSales['error']))$errors['month_sales']=$monthSales['error'];if($errors)$data['errors']=$errors;
pairing_ok(['data'=>$data]);
