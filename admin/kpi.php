<?php
require_once __DIR__ . '/../core/db.php';
require_once __DIR__ . '/../core/functions.php';
require_once __DIR__ . '/../core/security.php';
require_once __DIR__ . '/../core/auth.php';
require_once __DIR__ . '/../core/csrf.php';
require_once __DIR__ . '/../core/rbac.php';
require_once __DIR__ . '/../core/store_kpi_finance.php';

start_secure_session();
require_admin();
ensure_rbac_schema();
$me=adena_owner_admin_guard();
csrf_token();
$schemaReady=adena_module_table_exists('store_kpi_types') && adena_module_table_exists('store_kpi_assessments') && adena_module_table_exists('store_kpi_assessment_items');
$tab=($_GET['tab']??'input')==='settings'?'settings':'input';
$month=$_GET['month']??date('Y-m');
if(!preg_match('/^\d{4}-\d{2}$/',$month)) $month=date('Y-m');
$err=''; $ok='';

if($schemaReady && $_SERVER['REQUEST_METHOD']==='POST'){
  try{
    csrf_check();
    $action=(string)($_POST['action']??'');
    $uid=(int)($me['id']??0);
    if($action==='save_type'){
      $id=(int)($_POST['id']??0);
      $name=trim((string)($_POST['kpi_name']??''));
      $desc=trim((string)($_POST['description']??''));
      $weight=(float)($_POST['weight']??0);
      $max=(float)($_POST['max_score']??100);
      $sort=(int)($_POST['sort_order']??0);
      if($name==='') throw new Exception('Nama jenis KPI wajib diisi.');
      if($weight<0 || $weight>100) throw new Exception('Bobot harus antara 0 sampai 100.');
      if($max<=0) throw new Exception('Nilai maksimum harus lebih dari 0.');
      if($id>0){
        db()->prepare('UPDATE store_kpi_types SET kpi_name=?,description=?,weight=?,max_score=?,sort_order=?,updated_at=NOW() WHERE id=?')->execute([$name,$desc,$weight,$max,$sort,$id]);
      }else{
        db()->prepare('INSERT INTO store_kpi_types(record_uuid,kpi_name,description,weight,max_score,sort_order,is_active,created_by) VALUES(?,?,?,?,?,?,1,?)')->execute([adena_uuid_v4(),$name,$desc,$weight,$max,$sort,$uid]);
      }
      $ok='Setting KPI berhasil disimpan.'; $tab='settings';
    } elseif($action==='toggle_type'){
      $id=(int)($_POST['id']??0); $active=(int)($_POST['active']??0)===1?1:0;
      db()->prepare('UPDATE store_kpi_types SET is_active=?,updated_at=NOW() WHERE id=?')->execute([$active,$id]);
      $ok=$active?'Jenis KPI diaktifkan.':'Jenis KPI dinonaktifkan; histori lama tetap tersimpan.'; $tab='settings';
    } elseif($action==='save_assessment'){
      $period=(string)($_POST['period']??date('Y-m'));
      if(!preg_match('/^\d{4}-\d{2}$/',$period)) throw new Exception('Periode KPI tidak valid.');
      $employeeId=(int)($_POST['employee_id']??0);
      $status=(string)($_POST['save_status']??'draft');
      if(!in_array($status,['draft','final'],true)) $status='draft';
      $employees=adena_store_employee_rows(false); $employee=null;
      foreach($employees as $row){ if((int)$row['id']===$employeeId){$employee=$row;break;} }
      if(!$employee) throw new Exception('Pegawai tidak ditemukan atau tidak dapat dinilai.');
      $typeIds=$_POST['kpi_type_id']??[]; $scores=$_POST['score']??[]; $notes=$_POST['item_notes']??[];
      if(!is_array($typeIds)) $typeIds=[]; if(!is_array($scores)) $scores=[]; if(!is_array($notes)) $notes=[];
      $items=[]; $seen=[]; $totalWeight=0; $finalScore=0;
      $stType=db()->prepare('SELECT * FROM store_kpi_types WHERE id=? AND deleted_at IS NULL LIMIT 1');
      foreach($typeIds as $i=>$rawId){
        $typeId=(int)$rawId; if($typeId<=0) continue;
        if(isset($seen[$typeId])) throw new Exception('Jenis KPI tidak boleh dipilih lebih dari satu kali.');
        $seen[$typeId]=true; $stType->execute([$typeId]); $type=$stType->fetch(PDO::FETCH_ASSOC);
        if(!$type) throw new Exception('Salah satu jenis KPI tidak ditemukan.');
        $score=(float)($scores[$i]??0); $max=(float)$type['max_score']; $weight=(float)$type['weight'];
        if($score<0 || $score>$max) throw new Exception('Nilai '.$type['kpi_name'].' harus antara 0 dan '.$max.'.');
        $weighted=$max>0?($score/$max)*$weight:0;
        $items[]=['type'=>$type,'score'=>$score,'weighted'=>$weighted,'notes'=>trim((string)($notes[$i]??'')),'sort'=>$i+1];
        $totalWeight+=$weight; $finalScore+=$weighted;
      }
      if(!$items) throw new Exception('Minimal satu baris KPI harus diisi.');
      if($status==='final' && abs($totalWeight-100)>0.0001) throw new Exception('Penilaian final memerlukan total bobot tepat 100%. Saat ini '.$totalWeight.'%.');
      $periodDate=$period.'-01'; $db=db(); $db->beginTransaction();
      $st=$db->prepare('SELECT * FROM store_kpi_assessments WHERE period_month=? AND employee_id=? LIMIT 1 FOR UPDATE'); $st->execute([$periodDate,$employeeId]); $existing=$st->fetch(PDO::FETCH_ASSOC);
      if($existing && in_array((string)$existing['status'],['locked'],true)) throw new Exception('KPI sudah dikunci untuk payroll dan tidak dapat diubah.');
      $oldStatus=$existing['status']??null;
      if($existing){
        $assessmentId=(int)$existing['id'];
        $db->prepare('UPDATE store_kpi_assessments SET employee_name_snapshot=?,employee_role_snapshot=?,status=?,total_weight=?,final_score=?,general_notes=?,assessed_by=?,finalized_by=?,finalized_at=?,version_no=version_no+1,updated_at=NOW() WHERE id=?')->execute([
          $employee['name'],(string)$employee['role_key'],$status,$totalWeight,$finalScore,trim((string)($_POST['general_notes']??'')),$uid,$status==='final'?$uid:null,$status==='final'?date('Y-m-d H:i:s'):null,$assessmentId
        ]);
        $db->prepare('DELETE FROM store_kpi_assessment_items WHERE assessment_id=?')->execute([$assessmentId]);
      }else{
        $db->prepare('INSERT INTO store_kpi_assessments(record_uuid,period_month,employee_id,employee_name_snapshot,employee_role_snapshot,status,total_weight,final_score,general_notes,assessed_by,finalized_by,finalized_at) VALUES(?,?,?,?,?,?,?,?,?,?,?,?)')->execute([
          adena_uuid_v4(),$periodDate,$employeeId,$employee['name'],(string)$employee['role_key'],$status,$totalWeight,$finalScore,trim((string)($_POST['general_notes']??'')),$uid,$status==='final'?$uid:null,$status==='final'?date('Y-m-d H:i:s'):null
        ]);
        $assessmentId=(int)$db->lastInsertId();
      }
      $ins=$db->prepare('INSERT INTO store_kpi_assessment_items(record_uuid,assessment_id,kpi_type_id,kpi_name_snapshot,max_score_snapshot,weight_snapshot,score,weighted_score,notes,sort_order) VALUES(?,?,?,?,?,?,?,?,?,?)');
      foreach($items as $it){ $t=$it['type']; $ins->execute([adena_uuid_v4(),$assessmentId,(int)$t['id'],$t['kpi_name'],(float)$t['max_score'],(float)$t['weight'],$it['score'],$it['weighted'],$it['notes'],$it['sort']]); }
      adena_kpi_audit($assessmentId,'save_assessment',$oldStatus,$status,['period'=>$period,'employee_id'=>$employeeId,'total_weight'=>$totalWeight,'final_score'=>$finalScore],$uid);
      adena_finance_outbox('store_kpi_assessment',(string)$assessmentId,$existing?'updated':'created',['assessment_id'=>$assessmentId,'period'=>$period,'employee_id'=>$employeeId,'status'=>$status,'final_score'=>$finalScore],(int)($existing['version_no']??0)+1);
      $db->commit();
      $ok=$status==='final'?'KPI berhasil difinalisasi.':'Draft KPI berhasil disimpan.'; $month=$period; $tab='input';
    }
  }catch(Throwable $e){ if(db()->inTransaction()) db()->rollBack(); $err=$e->getMessage(); }
}

$types=$schemaReady ? (db()->query('SELECT * FROM store_kpi_types WHERE deleted_at IS NULL ORDER BY is_active DESC,sort_order,kpi_name')->fetchAll(PDO::FETCH_ASSOC) ?: []) : [];
$activeTypes=array_values(array_filter($types,static fn($r)=>(int)$r['is_active']===1));
$employees=adena_store_employee_rows(true);
$assessments=[];
if($schemaReady){
  $st=db()->prepare('SELECT a.*,u.name assessed_by_name FROM store_kpi_assessments a LEFT JOIN users u ON u.id=a.assessed_by WHERE a.period_month=? AND a.deleted_at IS NULL ORDER BY a.final_score DESC,a.employee_name_snapshot');
  $st->execute([$month.'-01']); $assessments=$st->fetchAll(PDO::FETCH_ASSOC) ?: [];
}
$detail=null; $detailItems=[];
if($schemaReady && (int)($_GET['detail']??0)>0){
  $st=db()->prepare('SELECT a.*,u.name assessed_by_name FROM store_kpi_assessments a LEFT JOIN users u ON u.id=a.assessed_by WHERE a.id=? LIMIT 1'); $st->execute([(int)$_GET['detail']]); $detail=$st->fetch(PDO::FETCH_ASSOC) ?: null;
  if($detail){ $st=db()->prepare('SELECT * FROM store_kpi_assessment_items WHERE assessment_id=? ORDER BY sort_order,id'); $st->execute([(int)$detail['id']]); $detailItems=$st->fetchAll(PDO::FETCH_ASSOC) ?: []; }
}
$editAssessment=null; $editItems=[];
if($schemaReady && (int)($_GET['edit']??0)>0){
  $st=db()->prepare('SELECT * FROM store_kpi_assessments WHERE id=? LIMIT 1'); $st->execute([(int)$_GET['edit']]); $editAssessment=$st->fetch(PDO::FETCH_ASSOC) ?: null;
  if($editAssessment){ $st=db()->prepare('SELECT * FROM store_kpi_assessment_items WHERE assessment_id=? ORDER BY sort_order,id'); $st->execute([(int)$editAssessment['id']]); $editItems=$st->fetchAll(PDO::FETCH_ASSOC) ?: []; $month=substr((string)$editAssessment['period_month'],0,7); }
}
$customCss=setting('custom_css','');
?>
<!doctype html><html><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"><title>KPI Pegawai Toko</title><link rel="icon" href="<?php echo e(favicon_url()); ?>"><link rel="stylesheet" href="<?php echo e(asset_url('assets/app.css')); ?>"><style><?php echo $customCss; ?>
.kpi-tabs{display:flex;gap:8px;flex-wrap:wrap;margin-bottom:16px}.kpi-table input,.kpi-table select{min-width:120px}.kpi-total{font-size:1.25rem;font-weight:800}.report-box{background:#fff}.report-logo{display:block;max-width:88px;max-height:88px;object-fit:contain;margin:0 auto 8px}.report-meta{display:grid;grid-template-columns:repeat(auto-fit,minmax(180px,1fr));gap:10px;margin:14px 0}.report-meta div{border:1px solid #e5e7eb;padding:10px;border-radius:10px}.report-meta span{display:block;color:#64748b;font-size:.8rem}.status-draft{color:#92400e}.status-final{color:#166534}.status-locked{color:#1d4ed8}@media print{.sidebar,.topbar,.no-print,.api-global-notif{display:none!important}.main{margin:0!important}.content{padding:0!important}.report-box{box-shadow:none;border:0}}
</style></head><body><div class="container"><?php include __DIR__.'/partials_sidebar.php'; ?><div class="main"><div class="topbar"><button class="btn" data-toggle-sidebar type="button">Menu</button><strong>KPI Pegawai Toko</strong></div><div class="content">
<?php if(!$schemaReady): ?><div class="alert danger">Tabel KPI belum tersedia. Import <b>db/20260711_001_kpi_finance_sync.sql</b> melalui phpMyAdmin terlebih dahulu.</div><?php endif; ?>
<?php if($err): ?><div class="alert danger"><?php echo e($err); ?></div><?php endif; ?><?php if($ok): ?><div class="alert success"><?php echo e($ok); ?></div><?php endif; ?>
<div class="kpi-tabs no-print"><a class="btn <?php echo $tab==='input'?'':'btn-light'; ?>" href="?tab=input&month=<?php echo e($month); ?>">Pengisian KPI</a><a class="btn <?php echo $tab==='settings'?'':'btn-light'; ?>" href="?tab=settings">Setting KPI</a></div>
<?php if($schemaReady && $tab==='settings'): ?>
<div class="card"><h3>Setting Jenis KPI</h3><p style="color:#64748b">Jenis KPI yang sudah pernah dipakai tidak dihapus permanen. Nonaktifkan agar histori lama tetap utuh.</p>
<?php $editType=null; foreach($types as $t){if((int)($t['id'])===(int)($_GET['edit_type']??0))$editType=$t;} ?>
<form method="post"><input type="hidden" name="_csrf" value="<?php echo e(csrf_token()); ?>"><input type="hidden" name="action" value="save_type"><input type="hidden" name="id" value="<?php echo (int)($editType['id']??0); ?>"><div class="grid2"><label>Nama KPI<input name="kpi_name" value="<?php echo e($editType['kpi_name']??''); ?>" required></label><label>Bobot (%)<input type="number" step="0.0001" min="0" max="100" name="weight" value="<?php echo e($editType['weight']??'0'); ?>" required></label><label>Nilai Maksimum<input type="number" step="0.0001" min="0.0001" name="max_score" value="<?php echo e($editType['max_score']??'100'); ?>" required></label><label>Urutan<input type="number" name="sort_order" value="<?php echo e($editType['sort_order']??'0'); ?>"></label></div><label>Deskripsi<textarea name="description"><?php echo e($editType['description']??''); ?></textarea></label><button class="btn">Simpan Setting</button><?php if($editType): ?> <a class="btn btn-light" href="?tab=settings">Batal</a><?php endif; ?></form></div>
<div class="card"><h3>Daftar Jenis KPI</h3><table class="table"><thead><tr><th>Jenis</th><th>Bobot</th><th>Nilai Maks.</th><th>Status</th><th>Aksi</th></tr></thead><tbody><?php $totalActiveWeight=0; foreach($types as $t): if((int)$t['is_active']===1)$totalActiveWeight+=(float)$t['weight']; ?><tr><td><b><?php echo e($t['kpi_name']); ?></b><br><small><?php echo e($t['description']); ?></small></td><td><?php echo e(number_format((float)$t['weight'],2,',','.')); ?>%</td><td><?php echo e(number_format((float)$t['max_score'],2,',','.')); ?></td><td><?php echo (int)$t['is_active']===1?'Aktif':'Nonaktif'; ?></td><td><a class="btn btn-light" href="?tab=settings&edit_type=<?php echo (int)$t['id']; ?>">Edit</a> <form method="post" style="display:inline"><input type="hidden" name="_csrf" value="<?php echo e(csrf_token()); ?>"><input type="hidden" name="action" value="toggle_type"><input type="hidden" name="id" value="<?php echo (int)$t['id']; ?>"><input type="hidden" name="active" value="<?php echo (int)$t['is_active']===1?0:1; ?>"><button class="btn <?php echo (int)$t['is_active']===1?'btn-danger':'btn-light'; ?>"><?php echo (int)$t['is_active']===1?'Nonaktifkan':'Aktifkan'; ?></button></form></td></tr><?php endforeach; ?><tr><td><b>Total Bobot Aktif</b></td><td colspan="4"><b><?php echo e(number_format($totalActiveWeight,2,',','.')); ?>%</b><?php if(abs($totalActiveWeight-100)>0.0001): ?> <span style="color:#b45309">— idealnya tepat 100%</span><?php endif; ?></td></tr></tbody></table></div>
<?php elseif($schemaReady): ?>
<div class="card no-print"><h3><?php echo $editAssessment?'Edit Penilaian KPI':'Pengisian KPI Bulanan'; ?></h3><p style="color:#64748b">Identitas cabang otomatis mengikuti host toko ini. Form awal menampilkan empat baris; tombol tambah menambah satu baris.</p>
<form method="post" id="kpi-form"><input type="hidden" name="_csrf" value="<?php echo e(csrf_token()); ?>"><input type="hidden" name="action" value="save_assessment"><div class="grid2"><label>Periode<input type="month" name="period" value="<?php echo e($month); ?>" required></label><label>Pegawai<select name="employee_id" required><option value="">- pilih pegawai -</option><?php foreach($employees as $emp): ?><option value="<?php echo (int)$emp['id']; ?>" <?php echo $editAssessment && (int)$editAssessment['employee_id']===(int)$emp['id']?'selected':''; ?>><?php echo e($emp['name'].' · '.$emp['role_key']); ?></option><?php endforeach; ?></select></label></div>
<table class="table kpi-table" id="kpi-items"><thead><tr><th>Jenis KPI</th><th>Nilai</th><th>Bobot</th><th>Nilai Akhir</th><th>Catatan</th><th>Aksi</th></tr></thead><tbody>
<?php
$rowsForForm=$editItems;
if(!$rowsForForm){ for($i=0;$i<4;$i++) $rowsForForm[]=['kpi_type_id'=>$activeTypes[$i]['id']??0,'score'=>'','notes'=>'']; }
foreach($rowsForForm as $ri): ?>
<tr><td><select name="kpi_type_id[]" class="kpi-type"><option value="">- pilih -</option><?php foreach($activeTypes as $t): ?><option value="<?php echo (int)$t['id']; ?>" data-weight="<?php echo e($t['weight']); ?>" data-max="<?php echo e($t['max_score']); ?>" <?php echo (int)($ri['kpi_type_id']??0)===(int)$t['id']?'selected':''; ?>><?php echo e($t['kpi_name']); ?></option><?php endforeach; ?></select></td><td><input name="score[]" class="kpi-score" type="number" step="0.0001" min="0" value="<?php echo e($ri['score']??''); ?>"></td><td><span class="kpi-weight">0%</span></td><td><span class="kpi-result">0</span></td><td><input name="item_notes[]" value="<?php echo e($ri['notes']??''); ?>"></td><td><button class="btn btn-danger remove-row" type="button">Hapus</button></td></tr>
<?php endforeach; ?>
</tbody></table><button class="btn btn-light" type="button" id="add-kpi">Tambah KPI</button><label>Catatan Umum<textarea name="general_notes"><?php echo e($editAssessment['general_notes']??''); ?></textarea></label><div style="display:flex;justify-content:space-between;align-items:center;gap:12px;flex-wrap:wrap"><div><div>Total Bobot: <b id="total-weight">0%</b></div><div>Total Nilai KPI: <span class="kpi-total" id="total-score">0</span></div></div><div><button class="btn btn-light" name="save_status" value="draft">Simpan Draft</button> <button class="btn" name="save_status" value="final">Finalisasi</button></div></div></form></div>
<div class="card"><h3>Hasil KPI Periode <?php echo e($month); ?></h3><form method="get" class="no-print" style="display:flex;gap:8px;align-items:end"><input type="hidden" name="tab" value="input"><label>Bulan<input type="month" name="month" value="<?php echo e($month); ?>"></label><button class="btn btn-light">Filter</button></form><table class="table"><thead><tr><th>Pegawai</th><th>Role</th><th>Status</th><th>Bobot</th><th>Nilai Akhir</th><th>Pengisi</th><th>Aksi</th></tr></thead><tbody><?php foreach($assessments as $a): ?><tr><td><b><?php echo e($a['employee_name_snapshot']); ?></b></td><td><?php echo e($a['employee_role_snapshot']); ?></td><td class="status-<?php echo e($a['status']); ?>"><?php echo e(strtoupper($a['status'])); ?></td><td><?php echo e(number_format((float)$a['total_weight'],2,',','.')); ?>%</td><td><b><?php echo e(number_format((float)$a['final_score'],2,',','.')); ?></b></td><td><?php echo e($a['assessed_by_name']??'-'); ?></td><td><a class="btn btn-light" href="?tab=input&month=<?php echo e($month); ?>&detail=<?php echo (int)$a['id']; ?>">Detail</a><?php if($a['status']!=='locked'): ?> <a class="btn btn-light" href="?tab=input&month=<?php echo e($month); ?>&edit=<?php echo (int)$a['id']; ?>">Edit</a><?php endif; ?></td></tr><?php endforeach; if(!$assessments): ?><tr><td colspan="7">Belum ada penilaian KPI pada periode ini.</td></tr><?php endif; ?></tbody></table></div>
<?php endif; ?>
<?php if($detail): ?><div class="card report-box" id="kpi-detail"><div class="no-print" style="display:flex;justify-content:space-between"><h3>Detail KPI Pegawai</h3><div><button class="btn" onclick="window.print()">Print / PDF</button> <a class="btn btn-light" href="?tab=input&month=<?php echo e(substr((string)$detail['period_month'],0,7)); ?>">Tutup</a></div></div><?php $storeIdentity=adena_store_identity(); if(!empty($storeIdentity['logo_url'])): ?><img class="report-logo" src="<?php echo e($storeIdentity['logo_url']); ?>" alt="Logo"><?php endif; ?><h2 style="text-align:center"><?php echo e($storeIdentity['name']); ?></h2><h3 style="text-align:center">Laporan KPI Pegawai Toko</h3><div class="report-meta"><div><span>Nama Pegawai</span><b><?php echo e($detail['employee_name_snapshot']); ?></b></div><div><span>Jabatan</span><b><?php echo e($detail['employee_role_snapshot']); ?></b></div><div><span>Periode</span><b><?php echo e(date('F Y',strtotime($detail['period_month']))); ?></b></div><div><span>Status</span><b><?php echo e(strtoupper($detail['status'])); ?></b></div><div><span>Total Bobot</span><b><?php echo e(number_format((float)$detail['total_weight'],2,',','.')); ?>%</b></div><div><span>Nilai Akhir</span><b><?php echo e(number_format((float)$detail['final_score'],2,',','.')); ?></b></div></div><table class="table"><thead><tr><th>Jenis KPI</th><th>Nilai</th><th>Nilai Maks.</th><th>Bobot</th><th>Nilai Akhir</th><th>Catatan</th></tr></thead><tbody><?php foreach($detailItems as $it): ?><tr><td><?php echo e($it['kpi_name_snapshot']); ?></td><td><?php echo e(number_format((float)$it['score'],2,',','.')); ?></td><td><?php echo e(number_format((float)$it['max_score_snapshot'],2,',','.')); ?></td><td><?php echo e(number_format((float)$it['weight_snapshot'],2,',','.')); ?>%</td><td><?php echo e(number_format((float)$it['weighted_score'],2,',','.')); ?></td><td><?php echo e($it['notes']); ?></td></tr><?php endforeach; ?></tbody></table><p><b>Catatan umum:</b> <?php echo e($detail['general_notes']?:'-'); ?></p><p><small>Dinilai oleh <?php echo e($detail['assessed_by_name']??'-'); ?> · Diperbarui <?php echo e($detail['updated_at']??$detail['created_at']); ?></small></p></div><?php endif; ?>
</div></div></div>
<script defer src="<?php echo e(asset_url('assets/app.js')); ?>"></script><script>
(function(){
 const table=document.querySelector('#kpi-items tbody'); if(!table)return;
 function recalc(){let tw=0,ts=0; table.querySelectorAll('tr').forEach(tr=>{const sel=tr.querySelector('.kpi-type'),score=tr.querySelector('.kpi-score');const opt=sel.options[sel.selectedIndex];const w=parseFloat(opt?.dataset.weight||0),m=parseFloat(opt?.dataset.max||100),s=parseFloat(score.value||0);const result=m>0?(s/m)*w:0;tr.querySelector('.kpi-weight').textContent=w.toLocaleString('id-ID')+'%';tr.querySelector('.kpi-result').textContent=result.toLocaleString('id-ID',{maximumFractionDigits:2});score.max=m;tw+=w;ts+=result;});document.getElementById('total-weight').textContent=tw.toLocaleString('id-ID',{maximumFractionDigits:2})+'%';document.getElementById('total-score').textContent=ts.toLocaleString('id-ID',{maximumFractionDigits:2});}
 table.addEventListener('input',recalc);table.addEventListener('change',recalc);table.addEventListener('click',e=>{if(e.target.classList.contains('remove-row')){e.target.closest('tr').remove();recalc();}});
 document.getElementById('add-kpi').addEventListener('click',()=>{const tr=table.rows[0].cloneNode(true);tr.querySelectorAll('input').forEach(i=>i.value='');tr.querySelector('select').selectedIndex=0;table.appendChild(tr);recalc();});recalc();
})();
</script></body></html>
