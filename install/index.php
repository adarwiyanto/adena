<?php
$lock = __DIR__ . '/install.lock'; $lockAlt = __DIR__ . '/LOCK';
if (file_exists($lock) || file_exists($lockAlt)) { header('Location: ../adm.php'); exit; }
function h($s){ return htmlspecialchars((string)$s, ENT_QUOTES, 'UTF-8'); }
$err=''; $ok='';
function adena_sql_statements(string $sql): array {
  $sql=preg_replace('/^\xEF\xBB\xBF/','',$sql); $out=[]; $buf=''; $q=null; $esc=false; $lc=false; $bc=false; $n=strlen($sql);
  for($i=0;$i<$n;$i++){ $ch=$sql[$i]; $nx=$i+1<$n?$sql[$i+1]:'';
    if($lc){ if($ch==="\n")$lc=false; continue; } if($bc){ if($ch==='*'&&$nx==='/'){$bc=false;$i++;} continue; }
    if($q!==null){ $buf.=$ch; if($esc)$esc=false; elseif($ch==='\\')$esc=true; elseif($ch===$q)$q=null; continue; }
    if($ch==='-'&&$nx==='-'){$lc=true;$i++;continue;} if($ch==='/'&&$nx==='*'){$bc=true;$i++;continue;} if($ch==='#'){$lc=true;continue;}
    if($ch==="'"||$ch==='"'||$ch==='`'){$q=$ch;$buf.=$ch;continue;} if($ch===';'){ $st=trim($buf); $buf=''; if($st!==''&&stripos($st,'DELIMITER')!==0)$out[]=$st; continue; } $buf.=$ch;
  } $st=trim($buf); if($st!=='')$out[]=$st; return $out;
}
function run_sql_file(PDO $pdo, string $file): void {
  if (!is_file($file)) return;
  foreach(adena_sql_statements(file_get_contents($file)) as $stmt){
    try { $pdo->exec($stmt); } catch(Throwable $e) { /* installer tetap lanjut untuk ALTER IF NOT EXISTS pada versi lama */ }
  }
}
if($_SERVER['REQUEST_METHOD']==='POST'){
  $app_name=trim($_POST['app_name']??'Adena POS');
  $unit_mode=strtolower(trim($_POST['unit_mode']??'branch'));
  if(!in_array($unit_mode,['backoffice','branch','kitchen'],true)) $unit_mode='branch';
  $unit_name=trim($_POST['unit_name']??($unit_mode==='kitchen'?'Dapur Utama':($unit_mode==='backoffice'?'Backoffice Pusat':'Cabang')));
  $unit_code=strtoupper(trim($_POST['unit_code']??($unit_mode==='kitchen'?'DAPUR':($unit_mode==='backoffice'?'BO':'CBG'))));
  $central_url=rtrim(trim($_POST['central_url']??''),'/');
  $base_url=rtrim(trim($_POST['base_url']??''),'/'); $db_host=trim($_POST['db_host']??'127.0.0.1'); $db_port=trim($_POST['db_port']??'3306'); $db_name=trim($_POST['db_name']??''); $db_user=trim($_POST['db_user']??'root'); $db_pass=(string)($_POST['db_pass']??'');
  $admin_username=trim($_POST['admin_username']??'admin'); $admin_name=trim($_POST['admin_name']??'Administrator'); $admin_pass1=(string)($_POST['admin_pass1']??''); $admin_pass2=(string)($_POST['admin_pass2']??'');
  try{
    if($app_name===''||$unit_name===''||$unit_code===''||$base_url===''||$db_name==='') throw new Exception('Nama aplikasi, unit, kode unit, Base URL, dan database wajib diisi.');
    if(!preg_match('/^[A-Z0-9_-]+$/',$unit_code)) throw new Exception('Kode unit hanya boleh huruf, angka, strip, dan underscore.');
    if($admin_pass1===''||$admin_pass1!==$admin_pass2) throw new Exception('Password admin tidak cocok.');
    $dump=__DIR__.'/../db/adena_latest_single_belitung.sql'; if(!is_file($dump)) throw new Exception('File database terbaru tidak ditemukan: /db/adena_latest_single_belitung.sql');
    $pdo=new PDO("mysql:host={$db_host};port={$db_port};charset=utf8mb4",$db_user,$db_pass,[PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION]);
    $safeDb=str_replace('`','``',$db_name); $pdo->exec("CREATE DATABASE IF NOT EXISTS `{$safeDb}` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci"); $pdo->exec("USE `{$safeDb}`");
    run_sql_file($pdo,$dump);
    run_sql_file($pdo,__DIR__.'/../db/updates_api_settings_permissions_v1.sql');
    $branchType = $unit_mode === 'kitchen' ? 'kitchen' : 'branch';
    $isKitchen = $unit_mode === 'kitchen' ? 1 : 0;
    $activeBranchId = null;
    if ($unit_mode !== 'backoffice') {
      $st = $pdo->prepare("SELECT id FROM branches WHERE UPPER(branch_code)=UPPER(?) LIMIT 1");
      $st->execute([$unit_code]);
      $activeBranchId = (int)($st->fetchColumn() ?: 0);
      if ($activeBranchId > 0) {
        $pdo->prepare("UPDATE branches SET branch_name=?, unit_type=?, is_kitchen=?, is_active=1, updated_at=NOW() WHERE id=?")
          ->execute([$unit_name,$branchType,$isKitchen,$activeBranchId]);
      } else {
        $pdo->prepare("INSERT INTO branches (branch_code,branch_name,unit_type,is_kitchen,is_active,sort_order) VALUES (?,?,?,?,1,0)")
          ->execute([$unit_code,$unit_name,$branchType,$isKitchen]);
        $activeBranchId = (int)$pdo->lastInsertId();
      }
      try{$pdo->prepare("UPDATE branches SET is_active=0 WHERE UPPER(branch_code)<>UPPER(?)")->execute([$unit_code]);}catch(Throwable $e){}
      try{$pdo->prepare("UPDATE api_tokens SET unit_code=COALESCE(NULLIF(unit_code,''), device_code) WHERE unit_code IS NULL OR unit_code=''")->execute();}catch(Throwable $e){}
      try{$pdo->prepare("UPDATE api_tokens SET branch_id=? WHERE (branch_id IS NULL OR branch_id=0) AND (UPPER(device_code)=UPPER(?) OR UPPER(unit_code)=UPPER(?))")->execute([$activeBranchId,$unit_code,$unit_code]);}catch(Throwable $e){}
    } else {
      try{$pdo->exec("UPDATE branches SET is_active=1");}catch(Throwable $e){}
    }
    $roleId = null; try { $st=$pdo->prepare("SELECT id FROM roles WHERE role_key='owner' LIMIT 1"); $st->execute(); $roleId=(int)($st->fetchColumn() ?: 0); } catch(Throwable $e) {}
    $pdo->prepare("INSERT INTO users (username,name,role,role_id,password_hash) VALUES (?,?, 'owner', ?, ?) ON DUPLICATE KEY UPDATE name=VALUES(name), role='owner', role_id=VALUES(role_id), password_hash=VALUES(password_hash)")->execute([$admin_username,$admin_name,$roleId ?: null,password_hash($admin_pass1,PASSWORD_DEFAULT)]);
    $set=$pdo->prepare("INSERT INTO settings (`key`,`value`) VALUES (?,?) ON DUPLICATE KEY UPDATE `value`=VALUES(`value`)");
    foreach([
      'active_branch_id'=>(string)($activeBranchId ?: ''),'active_unit_code'=>$unit_code,'active_unit_type'=>$unit_mode,'branch_mode'=>($unit_mode==='backoffice'?'multi':'single'),'system_unit_type'=>$unit_mode,'unit_name'=>$unit_name,'unit_code'=>$unit_code,
      'branch_name'=>$unit_name,'branch_code'=>$unit_code,'store_name'=>$app_name,'store_subtitle'=>'Makanan Khas Belitung','central_api_url'=>$central_url,
      'api_topology'=>'branch_to_branch,branch_to_kitchen,branch_to_backoffice,kitchen_to_backoffice,backoffice_to_branch'
    ] as $k=>$v){$set->execute([$k,$v]);}
    $config=['app'=>['name'=>$app_name,'base_url'=>$base_url,'unit_type'=>$unit_mode,'unit_name'=>$unit_name,'unit_code'=>$unit_code,'branch_name'=>$unit_name,'branch_code'=>$unit_code,'branch_mode'=>'single','central_url'=>$central_url],'db'=>['host'=>$db_host,'port'=>$db_port,'name'=>$db_name,'user'=>$db_user,'pass'=>$db_pass,'charset'=>'utf8mb4'],'security'=>['session_name'=>'ADENAPOSSESS']];
    file_put_contents(__DIR__.'/../config.php',"<?php\nreturn ".var_export($config,true).";\n"); @mkdir(__DIR__.'/../private_uploads',0755,true); @mkdir(__DIR__.'/../uploads',0755,true); file_put_contents($lock,'installed '.date('c'));
    $ok="Instalasi selesai. Mode {$unit_mode}: {$unit_name} ({$unit_code}).";
  }catch(Throwable $e){$err=$e->getMessage();}
}
?><!doctype html><html lang="id"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"><title>Installer Adena POS</title><style>body{font-family:system-ui,-apple-system,Segoe UI,sans-serif;background:#f1f5f9;margin:0;color:#0f172a}.wrap{max-width:820px;margin:40px auto;background:#fff;border-radius:18px;padding:24px;box-shadow:0 10px 30px #0f172a22}.grid{display:grid;grid-template-columns:1fr 1fr;gap:14px}label{font-weight:700;font-size:13px}input,select{width:100%;padding:11px;border:1px solid #cbd5e1;border-radius:12px;box-sizing:border-box}.full{grid-column:1/-1}.btn{background:#0f172a;color:#fff;border:0;border-radius:12px;padding:12px 18px;font-weight:800;cursor:pointer}.note{background:#e0f2fe;border:1px solid #7dd3fc;color:#075985;padding:12px;border-radius:12px;margin-bottom:18px}.err{background:#fee2e2;color:#991b1b;padding:12px;border-radius:12px}.ok{background:#dcfce7;color:#166534;padding:12px;border-radius:12px}@media(max-width:720px){.grid{grid-template-columns:1fr}.wrap{margin:12px}}</style></head><body><div class="wrap"><h1>Installer Adena POS</h1><div class="note"><b>Clean install:</b> pilih mode unit untuk deploy folder yang sama ke GitHub lalu dipakai cabang/dapur/backoffice lain. Setelah install, menu <b>Admin → Pengaturan API</b> hanya bisa diakses owner.</div><?php if($err): ?><p class="err"><?php echo h($err); ?></p><?php endif; ?><?php if($ok): ?><p class="ok"><?php echo h($ok); ?> <a href="../adm.php">Masuk admin</a></p><?php endif; ?><form method="post"><div class="grid"><div><label>Nama Aplikasi</label><input name="app_name" value="Adena POS"></div><div><label>Base URL</label><input name="base_url" placeholder="https://adena.co.id"></div><div><label>Mode Unit</label><select name="unit_mode"><option value="backoffice">Backoffice Pusat</option><option value="branch" selected>Cabang / Toko</option><option value="kitchen">Dapur</option></select></div><div><label>Kode Unit</label><input name="unit_code" value="BLT"></div><div><label>Nama Unit</label><input name="unit_name" value="Belitung"></div><div><label>URL Backoffice/Pusat (opsional)</label><input name="central_url" placeholder="https://pusat.domain.com"></div><div><label>DB Host</label><input name="db_host" value="127.0.0.1"></div><div><label>DB Port</label><input name="db_port" value="3306"></div><div><label>DB Name</label><input name="db_name"></div><div><label>DB User</label><input name="db_user" value="root"></div><div class="full"><label>DB Password</label><input name="db_pass" type="password"></div><div><label>Owner Username</label><input name="admin_username" value="admin"></div><div><label>Owner Name</label><input name="admin_name" value="Administrator"></div><div><label>Password Owner</label><input name="admin_pass1" type="password"></div><div><label>Ulangi Password</label><input name="admin_pass2" type="password"></div><div class="full"><button class="btn" type="submit">Install Database Terbaru</button></div></div></form></div></body></html>
