<?php
require_once __DIR__ . '/../../core/api_pairing.php';
require_once __DIR__ . '/../../core/store_kpi_finance.php';
pairing_auth('kpi.read');
if(!adena_module_table_exists('store_kpi_assessments')) pairing_err('Modul KPI toko belum diinstal.',503);
$assessmentId=(int)($_GET['assessment_id']??0);$employeeId=(int)($_GET['employee_id']??0);$month=trim((string)($_GET['month']??date('Y-m')));if(!preg_match('/^\d{4}-\d{2}$/',$month))$month=date('Y-m');
try{
  if($assessmentId>0){$st=db()->prepare('SELECT a.*,u.name assessed_by_name FROM store_kpi_assessments a LEFT JOIN users u ON u.id=a.assessed_by WHERE a.id=? AND a.deleted_at IS NULL LIMIT 1');$st->execute([$assessmentId]);}
  else{$st=db()->prepare('SELECT a.*,u.name assessed_by_name FROM store_kpi_assessments a LEFT JOIN users u ON u.id=a.assessed_by WHERE a.employee_id=? AND a.period_month=? AND a.deleted_at IS NULL LIMIT 1');$st->execute([$employeeId,$month.'-01']);}
  $a=$st->fetch(PDO::FETCH_ASSOC);if(!$a)pairing_err('Detail KPI tidak ditemukan.',404);
  $st=db()->prepare('SELECT id,kpi_type_id,kpi_name_snapshot,max_score_snapshot,weight_snapshot,score,weighted_score,notes,sort_order FROM store_kpi_assessment_items WHERE assessment_id=? ORDER BY sort_order,id');$st->execute([(int)$a['id']]);$items=$st->fetchAll(PDO::FETCH_ASSOC)?:[];
  pairing_ok(['data'=>['store'=>adena_store_identity(),'employee'=>['employee_id'=>(string)$a['employee_id'],'name'=>$a['employee_name_snapshot'],'role_key'=>$a['employee_role_snapshot']],'assessment'=>['assessment_id'=>(string)$a['id'],'month'=>substr((string)$a['period_month'],0,7),'status'=>$a['status'],'total_weight'=>(float)$a['total_weight'],'final_score'=>(float)$a['final_score'],'general_notes'=>$a['general_notes'],'assessed_by'=>$a['assessed_by_name']??'','finalized_at'=>$a['finalized_at'],'updated_at'=>$a['updated_at']??$a['created_at'],'items'=>$items]]]);
}catch(Throwable $e){pairing_err('Gagal membaca detail KPI: '.$e->getMessage(),500);}
