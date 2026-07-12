<?php
require_once __DIR__ . '/../../core/api_pairing.php';
require_once __DIR__ . '/../../core/store_kpi_finance.php';
pairing_auth('kpi.read');
if(!adena_module_table_exists('store_kpi_assessments') || !adena_module_table_exists('store_kpi_assessment_items')) pairing_err('Modul KPI toko belum diinstal. Import SQL update KPI terlebih dahulu.',503);
$month=trim((string)($_GET['month']??date('Y-m'))); if(!preg_match('/^\d{4}-\d{2}$/',$month)) $month=date('Y-m');
$period=$month.'-01'; $store=adena_store_identity(); $employees=adena_store_employee_rows(true); $rows=[];$sum=0;$highest=null;$lowest=null;$draft=0;$final=0;$locked=0;
try{
  $st=db()->prepare('SELECT a.*,u.name assessed_by_name FROM store_kpi_assessments a LEFT JOIN users u ON u.id=a.assessed_by WHERE a.period_month=? AND a.deleted_at IS NULL ORDER BY a.final_score DESC,a.employee_name_snapshot');
  $st->execute([$period]);
  foreach($st->fetchAll(PDO::FETCH_ASSOC) as $r){
    $score=(float)$r['final_score'];$status=(string)$r['status'];if($status==='draft')$draft++;elseif($status==='final')$final++;elseif($status==='locked')$locked++;
    if(in_array($status,['final','locked'],true)){$sum+=$score;$highest=$highest===null?$score:max($highest,$score);$lowest=$lowest===null?$score:min($lowest,$score);}
    $rows[]=['source'=>'store','store_name'=>$store['name'],'employee_id'=>(string)$r['employee_id'],'assessment_id'=>(string)$r['id'],'name'=>(string)$r['employee_name_snapshot'],'role_key'=>(string)$r['employee_role_snapshot'],'month'=>$month,'status'=>$status,'total_weight'=>(float)$r['total_weight'],'final_score'=>$score,'assessed_by'=>(string)($r['assessed_by_name']??''),'finalized_at'=>$r['finalized_at'],'updated_at'=>$r['updated_at']??$r['created_at']];
  }
}catch(Throwable $e){ pairing_err('Gagal membaca KPI toko: '.$e->getMessage(),500); }
$assessed=count(array_filter($rows,static fn($r)=>in_array($r['status'],['final','locked'],true)));$employeeCount=count($employees);$average=$assessed>0?$sum/$assessed:0;
pairing_ok(['data'=>['month'=>$month,'store'=>$store,'summary'=>['employee_count'=>$employeeCount,'assessment_count'=>count($rows),'assessed_count'=>$assessed,'unassessed_count'=>max(0,$employeeCount-$assessed),'draft_count'=>$draft,'final_count'=>$final,'locked_count'=>$locked,'average_score'=>$average,'highest_score'=>$highest??0,'lowest_score'=>$lowest??0],'employees'=>$rows],'count'=>count($rows)]);
