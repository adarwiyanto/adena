<?php
declare(strict_types=1);

final class GoogleDriveBackupService {
  private PDO $pdo;
  private array $dbConfig;
  private string $appKey;
  private string $appName;
  private string $rootPath;
  private string $privatePath;
  private array $externalPaths=[];
  private string $jobsTable;
  private string $timezone;
  private $getSetting;
  private $setSetting;
  private string $bootstrapError='';

  public function __construct(array $cfg) {
    $this->pdo=$cfg['pdo'];
    $this->dbConfig=$cfg['db'];
    $this->appKey=(string)$cfg['app_key'];
    $this->appName=(string)$cfg['app_name'];
    $this->rootPath=rtrim((string)$cfg['root_path'],'/\\');
    $this->privatePath=rtrim((string)$cfg['private_path'],'/\\');
    $this->externalPaths=is_array($cfg['external_paths']??null)?$cfg['external_paths']:[];
    $this->jobsTable=(string)$cfg['jobs_table'];
    $this->timezone=(string)($cfg['timezone']??'Asia/Jakarta');
    if($this->timezone==='') $this->timezone='Asia/Jakarta';
    date_default_timezone_set($this->timezone);
    $this->getSetting=$cfg['get_setting'];
    $this->setSetting=$cfg['set_setting'];
    // Membuka halaman setting tidak boleh menulis folder, tabel, atau setting.
    // Inisialisasi hanya dilakukan melalui aksi eksplisit owner atau saat backup benar-benar berjalan.
  }

  public function get(string $key, $default=null) {
    try { return ($this->getSetting)('backup_'.$key,$default); }
    catch(Throwable $e) { if($this->bootstrapError==='') $this->bootstrapError=$e->getMessage(); return $default; }
  }
  public function set(string $key,string $value): void { ($this->setSetting)('backup_'.$key,$value); }
  public function bootstrapError(): string { return $this->bootstrapError; }
  public function isReady(): bool { return $this->bootstrapError===''; }
  private function assertReady(): void { if($this->bootstrapError!=='') throw new RuntimeException('Infrastruktur backup belum siap: '.$this->bootstrapError); }
  public function repairInfrastructure(): void {
    $this->bootstrapError='';
    try { $this->ensurePrivatePath(); $this->ensureSchema(); $this->ensureDefaults(); }
    catch(Throwable $e) { $this->bootstrapError=$e->getMessage(); throw $e; }
  }
  public function diagnostics(): array {
    $out=[];
    $out[]=['label'=>'Versi PHP','ok'=>version_compare(PHP_VERSION,'8.0.0','>='),'detail'=>PHP_VERSION.' (minimal 8.0)'];
    $out[]=['label'=>'PDO MySQL','ok'=>extension_loaded('pdo_mysql'),'detail'=>extension_loaded('pdo_mysql')?'aktif':'tidak aktif'];
    $out[]=['label'=>'cURL','ok'=>function_exists('curl_init'),'detail'=>function_exists('curl_init')?'aktif':'tidak aktif'];
    $out[]=['label'=>'OpenSSL','ok'=>function_exists('openssl_encrypt') && in_array('aes-256-gcm',openssl_get_cipher_methods(),true),'detail'=>(function_exists('openssl_encrypt')?'aktif':'tidak aktif')];
    $out[]=['label'=>'Kompresi GZIP','ok'=>function_exists('gzopen'),'detail'=>function_exists('gzopen')?'aktif':'tidak aktif'];
    $out[]=['label'=>'Arsip snapshot','ok'=>class_exists('ZipArchive') || class_exists('PharData'),'detail'=>class_exists('ZipArchive')?'ZipArchive aktif':(class_exists('PharData')?'PharData aktif':'ZipArchive/PharData tidak aktif')];
    $execAvailable=function_exists('exec') && is_callable('exec');
    $escapeAvailable=function_exists('escapeshellarg') && is_callable('escapeshellarg');
    $shellReady=$execAvailable && $escapeAvailable;
    $out[]=['label'=>'Metode dump database','ok'=>true,'detail'=>$shellReady?'mysqldump tersedia; fallback native PHP tetap aktif':'Fungsi shell dibatasi; otomatis menggunakan native PHP'];
    $out[]=['label'=>'exec (opsional)','ok'=>true,'detail'=>$execAvailable?'aktif':'tidak aktif/dinonaktifkan hosting'];
    $out[]=['label'=>'escapeshellarg (opsional)','ok'=>true,'detail'=>$escapeAvailable?'aktif':'tidak aktif/dinonaktifkan hosting'];
    $disabled=trim((string)ini_get('disable_functions'));
    $out[]=['label'=>'disable_functions','ok'=>true,'detail'=>$disabled!==''?$disabled:'tidak ada'];
    $cliPath=trim((string)$this->get('php_cli_path','/opt/cpanel/ea-php84/root/usr/bin/php'));
    $cliDetected=$cliPath!=='' && @is_file($cliPath) && @is_executable($cliPath);
    $out[]=['label'=>'PHP CLI cron','ok'=>true,'detail'=>$cliPath.($cliDetected?' (terdeteksi)':' (belum dapat diverifikasi dari PHP web; dapat diubah di setting atau gunakan URL cron)')];
    $parent=is_dir($this->privatePath)?$this->privatePath:dirname($this->privatePath);
    $out[]=['label'=>'Folder backup','ok'=>is_dir($this->privatePath)?is_writable($this->privatePath):is_writable($parent),'detail'=>$this->privatePath];
    foreach($this->externalPaths as $label=>$path){ $p=rtrim((string)$path,'/\\'); $out[]=['label'=>'Private upload: '.(string)$label,'ok'=>is_dir($p) && is_readable($p),'detail'=>$p!==''?$p:'belum dikonfigurasi']; }
    try { $t=$this->safeIdentifier($this->jobsTable); $this->pdo->query("SELECT 1 FROM `$t` LIMIT 1"); $ok=true; $detail=$t.' tersedia'; }
    catch(Throwable $e) { $ok=false; $detail=$e->getMessage(); }
    $out[]=['label'=>'Tabel log backup','ok'=>$ok,'detail'=>$detail];
    return $out;
  }
  public function appKey(): string { return $this->appKey; }
  public function appName(): string { return $this->appName; }
  public function privatePath(): string { return $this->privatePath; }
  public function jobsTable(): string { return $this->jobsTable; }

  private function ensurePrivatePath(): void {
    if(!is_dir($this->privatePath) && !@mkdir($this->privatePath,0700,true) && !is_dir($this->privatePath)) throw new RuntimeException('Folder private backup tidak dapat dibuat.');
    @chmod($this->privatePath,0700);
    $deny=$this->privatePath.'/.htaccess';
    if(!is_file($deny)) @file_put_contents($deny,"Require all denied\nDeny from all\n");
    $index=$this->privatePath.'/index.html'; if(!is_file($index)) @file_put_contents($index,'');
  }

  public function ensureSchema(): void {
    $t=$this->safeIdentifier($this->jobsTable);
    $this->pdo->exec("CREATE TABLE IF NOT EXISTS `$t` (
      id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
      backup_type VARCHAR(30) NOT NULL,
      period_key VARCHAR(80) NOT NULL,
      status VARCHAR(30) NOT NULL DEFAULT 'queued',
      filename VARCHAR(255) NULL,
      bytes_size BIGINT UNSIGNED NOT NULL DEFAULT 0,
      sha256 CHAR(64) NULL,
      drive_file_id VARCHAR(160) NULL,
      drive_folder_id VARCHAR(160) NULL,
      dump_method VARCHAR(30) NULL,
      initiated_by VARCHAR(80) NULL,
      attempt_count INT NOT NULL DEFAULT 0,
      message TEXT NULL,
      started_at DATETIME NULL,
      finished_at DATETIME NULL,
      created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
      updated_at DATETIME NULL DEFAULT NULL ON UPDATE CURRENT_TIMESTAMP,
      PRIMARY KEY(id),
      UNIQUE KEY uq_backup_period (backup_type,period_key),
      KEY idx_backup_status (status,created_at),
      KEY idx_backup_created (created_at)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
  }

  private function ensureDefaults(): void {
    $defaultSite=strtoupper(trim((string)preg_replace('/[^A-Za-z0-9_-]+/','-',$this->appKey.'-'.(string)($this->dbConfig['name']??'MAIN')),'-'));
    $defaults=[
      'enabled'=>'1','google_email'=>'adarwiyanto@gmail.com','site_code'=>$defaultSite!==''?$defaultSite:$this->appKey,
      'drive_root'=>'ADENA_AUTOMATED_BACKUP','php_cli_path'=>'/opt/cpanel/ea-php84/root/usr/bin/php','schedule_6hourly'=>'1','schedule_daily'=>'1','schedule_weekly'=>'1','schedule_monthly'=>'1',
      'retention_6hourly_days'=>'7','retention_daily_days'=>'30','retention_weekly_days'=>'84','retention_monthly_days'=>'365',
      'cron_secret'=>bin2hex(random_bytes(24))
    ];
    foreach($defaults as $k=>$v){ if((string)$this->get($k,'')==='') $this->set($k,$v); }
  }

  public function saveConfiguration(array $input): void {
    $this->assertReady();
    $this->ensurePrivatePath();
    $oldEmail=strtolower((string)$this->get('google_email',''));
    $oldClientId=(string)$this->get('oauth_client_id','');
    $wasConnected=$this->isConnected();
    $email=strtolower(trim((string)($input['google_email']??'')));
    if($email==='' || !filter_var($email,FILTER_VALIDATE_EMAIL)) throw new InvalidArgumentException('Alamat email Google wajib diisi dan harus valid.');
    $site=strtoupper(trim((string)($input['site_code']??'')));
    $site=preg_replace('/[^A-Z0-9_-]+/','-',$site) ?: strtoupper($this->appKey);
    $root=trim((string)($input['drive_root']??'ADENA_AUTOMATED_BACKUP')) ?: 'ADENA_AUTOMATED_BACKUP';
    $phpCli=trim((string)($input['php_cli_path']??$this->get('php_cli_path','/opt/cpanel/ea-php84/root/usr/bin/php')));
    if($phpCli==='') $phpCli='/opt/cpanel/ea-php84/root/usr/bin/php';
    if(preg_match('/[\x00-\x1F\x7F]/',$phpCli)) throw new InvalidArgumentException('Path PHP CLI mengandung karakter yang tidak valid.');
    $clientId=trim((string)($input['oauth_client_id']??''));
    $clientSecret=(string)($input['oauth_client_secret']??'');
    $this->set('enabled',isset($input['enabled'])?'1':'0');
    $this->set('google_email',$email);
    $this->set('site_code',$site);
    $this->set('drive_root',$root);
    $this->set('php_cli_path',$phpCli);
    $this->set('oauth_client_id',$clientId);
    if($clientSecret!=='') $this->set('oauth_client_secret',$this->encryptSecret($clientSecret));
    if($wasConnected && (($oldEmail!=='' && $email!==$oldEmail) || ($oldClientId!=='' && $clientId!==$oldClientId) || $clientSecret!=='')) $this->disconnect();
    foreach(['6hourly','daily','weekly','monthly'] as $type){
      $this->set('schedule_'.$type,isset($input['schedule_'.$type])?'1':'0');
      $days=max(1,min(3650,(int)($input['retention_'.$type.'_days']??$this->get('retention_'.$type.'_days','30'))));
      $this->set('retention_'.$type.'_days',(string)$days);
    }
    $this->clearFolderCache();
  }

  public function hasOAuthClient(): bool { try{return trim((string)$this->get('oauth_client_id',''))!=='' && $this->oauthClientSecret()!=='';}catch(Throwable $e){return false;} }
  public function isConnected(): bool { try{return $this->refreshToken()!=='';}catch(Throwable $e){return false;} }
  public function connectedEmail(): string { return (string)$this->get('connected_email',''); }
  public function recoveryKeyText(): string {
    $this->assertReady();
    $this->ensurePrivatePath();
    $key=base64_encode($this->keyBytes());
    return "ADENA AUTOMATED BACKUP RECOVERY KEY\n".
      "App: {$this->appName}\n".
      "Site code: ".(string)$this->get('site_code',$this->appKey)."\n".
      "Generated/exported: ".date('c')."\n".
      "Fingerprint SHA-256: ".hash('sha256',$this->keyBytes())."\n".
      "key_base64=".$key."\n";
  }

  public function authorizationUrl(string $redirectUri,string $state): string {
    $this->assertReady();
    $this->ensurePrivatePath();
    if(!$this->hasOAuthClient()) throw new RuntimeException('Client ID dan Client Secret Google belum disimpan.');
    $q=[
      'client_id'=>(string)$this->get('oauth_client_id',''), 'redirect_uri'=>$redirectUri, 'response_type'=>'code',
      'scope'=>'openid email https://www.googleapis.com/auth/userinfo.email https://www.googleapis.com/auth/drive.file',
      'access_type'=>'offline','include_granted_scopes'=>'true','prompt'=>'consent','state'=>$state
    ];
    return 'https://accounts.google.com/o/oauth2/v2/auth?'.http_build_query($q,'','&',PHP_QUERY_RFC3986);
  }

  public function completeOAuth(string $code,string $redirectUri): array {
    $this->assertReady();
    $this->ensurePrivatePath();
    $data=$this->httpForm('https://oauth2.googleapis.com/token',[
      'code'=>$code,'client_id'=>(string)$this->get('oauth_client_id',''),'client_secret'=>$this->oauthClientSecret(),
      'redirect_uri'=>$redirectUri,'grant_type'=>'authorization_code'
    ]);
    $access=(string)($data['access_token']??'');
    if($access==='') throw new RuntimeException('Google tidak memberikan access token.');
    $refresh=(string)($data['refresh_token']??'');
    if($refresh!=='') $this->set('oauth_refresh_token',$this->encryptSecret($refresh));
    if($refresh==='' && $this->refreshToken()==='') throw new RuntimeException('Refresh token tidak diterima. Cabut akses aplikasi di akun Google lalu hubungkan kembali.');
    $profile=$this->httpJson('https://www.googleapis.com/oauth2/v2/userinfo','GET',null,['Authorization: Bearer '.$access]);
    $email=strtolower((string)($profile['email']??''));
    $expected=strtolower(trim((string)$this->get('google_email','')));
    if($expected!=='' && $email!==$expected){
      $this->disconnect();
      throw new RuntimeException('Akun Google yang terhubung '.$email.' tidak sesuai target '.$expected.'.');
    }
    $this->set('connected_email',$email);
    $this->set('connected_at',date('Y-m-d H:i:s'));
    $this->clearFolderCache();
    $folders=$this->ensureFolderTree($access);
    return ['email'=>$email,'root_id'=>$folders['root']];
  }

  public function disconnect(): void {
    foreach(['oauth_refresh_token','connected_email','connected_at','drive_root_id','drive_app_id','drive_site_id','drive_6hourly_id','drive_daily_id','drive_weekly_id','drive_monthly_id'] as $k) $this->set($k,'');
  }

  public function testConnection(): array {
    $this->assertReady();
    $this->ensurePrivatePath();
    $token=$this->accessToken();
    $about=$this->driveJson('GET','https://www.googleapis.com/drive/v3/about?fields=user,storageQuota',$token);
    $folders=$this->ensureFolderTree($token);
    return ['email'=>(string)($about['user']['emailAddress']??$this->connectedEmail()),'quota'=>$about['storageQuota']??[],'folders'=>$folders];
  }

  private function oauthClientSecret(): string { $v=(string)$this->get('oauth_client_secret',''); return $v===''?'':$this->decryptSecret($v); }
  private function refreshToken(): string { $v=(string)$this->get('oauth_refresh_token',''); return $v===''?'':$this->decryptSecret($v); }

  public function accessToken(): string {
    $this->assertReady();
    $this->ensurePrivatePath();
    $refresh=$this->refreshToken(); if($refresh==='') throw new RuntimeException('Google Drive belum terhubung.');
    $data=$this->httpForm('https://oauth2.googleapis.com/token',[
      'client_id'=>(string)$this->get('oauth_client_id',''),'client_secret'=>$this->oauthClientSecret(),
      'refresh_token'=>$refresh,'grant_type'=>'refresh_token'
    ]);
    $token=(string)($data['access_token']??''); if($token==='') throw new RuntimeException('Gagal memperbarui access token Google.');
    return $token;
  }

  public function runDue(): array {
    $this->assertReady();
    if((string)$this->get('enabled','1')!=='1') return ['status'=>'disabled'];
    $now=new DateTimeImmutable('now',new DateTimeZone($this->timezone));
    // Cron dipasang setiap 15 menit. Slot deterministik mencegah beberapa instalasi
    // memulai proses berat pada menit yang sama di satu akun hosting.
    $slot=(int)(sprintf('%u',crc32($this->appKey.'|'.(string)$this->get('site_code',$this->appKey)))%4);
    if((int)floor(((int)$now->format('i'))/15)!==$slot) return ['status'=>'waiting_slot','slot'=>$slot];
    $candidates=[];
    if((string)$this->get('schedule_monthly','1')==='1' && (int)$now->format('j')===1 && (int)$now->format('G')>=4) $candidates[]=['monthly',$now->format('Y-m')];
    if((string)$this->get('schedule_weekly','1')==='1' && (int)$now->format('w')===0 && (int)$now->format('G')>=3) $candidates[]=['weekly',$now->format('o-\\WW')];
    if((string)$this->get('schedule_daily','1')==='1' && (int)$now->format('G')>=2) $candidates[]=['daily',$now->format('Y-m-d')];
    if((string)$this->get('schedule_6hourly','1')==='1') $candidates[]=['6hourly',$now->format('Y-m-d').'-'.str_pad((string)(intdiv((int)$now->format('G'),6)*6),2,'0',STR_PAD_LEFT)];
    foreach($candidates as [$type,$period]){
      if(!$this->periodSucceeded($type,$period)) return $this->runBackup($type,'cron',$period);
    }
    return ['status'=>'not_due'];
  }

  private function periodSucceeded(string $type,string $period): bool {
    $t=$this->safeIdentifier($this->jobsTable);
    $st=$this->pdo->prepare("SELECT status FROM `$t` WHERE backup_type=? AND period_key=? LIMIT 1"); $st->execute([$type,$period]);
    return (string)$st->fetchColumn()==='success';
  }

  public function runBackup(string $type,string $initiatedBy='owner',?string $periodKey=null): array {
    $this->assertReady();
    $this->ensurePrivatePath();
    if(!in_array($type,['6hourly','daily','weekly','monthly'],true)) throw new InvalidArgumentException('Tipe backup tidak valid.');
    if(!$this->isConnected()) throw new RuntimeException('Google Drive belum terhubung.');
    @set_time_limit(0); @ignore_user_abort(true);
    $periodKey=$periodKey ?: 'manual-'.date('Ymd-His').'-'.bin2hex(random_bytes(3));
    $jobId=$this->startJob($type,$periodKey,$initiatedBy);
    $work=$this->privatePath.'/work-'.$jobId.'-'.bin2hex(random_bytes(4));
    if(!@mkdir($work,0700,true) && !is_dir($work)) throw new RuntimeException('Folder kerja backup tidak dapat dibuat.');
    $lock=fopen($this->privatePath.'/backup.lock','c+');
    if(!$lock || !flock($lock,LOCK_EX|LOCK_NB)){ $this->finishJob($jobId,'failed','Proses backup lain masih berjalan.'); throw new RuntimeException('Proses backup lain masih berjalan.'); }
    try {
      $dump=$work.'/database.sql';
      $method=$this->createDatabaseDump($dump);
      if(!is_file($dump) || filesize($dump)<100) throw new RuntimeException('Hasil dump database kosong atau tidak valid.');
      $site=$this->cleanName((string)$this->get('site_code',$this->appKey));
      $stamp=date('Ymd_His');
      if(in_array($type,['weekly','monthly'],true)){
        $plain=$this->createFullArchive($work.'/'.$site.'_'.$type.'_'.$stamp,$dump);
      } else {
        $plain=$work.'/'.$site.'_'.$type.'_'.$stamp.'.sql.gz';
        $inDump=fopen($dump,'rb'); $gz=gzopen($plain,'wb9');
        if(!$inDump||!$gz) throw new RuntimeException('Kompresi backup tidak dapat dimulai.');
        while(!feof($inDump)){ $chunk=fread($inDump,1024*1024); if($chunk===false) throw new RuntimeException('Dump database gagal dibaca saat kompresi.'); if($chunk==='') break; if(gzwrite($gz,$chunk)===false) throw new RuntimeException('Kompresi backup gagal.'); }
        fclose($inDump); gzclose($gz);
      }
      $encrypted=$plain.'.enc';
      $this->encryptFile($plain,$encrypted);
      $size=(int)filesize($encrypted); $sha=hash_file('sha256',$encrypted) ?: '';
      $token=$this->accessToken(); $folders=$this->ensureFolderTree($token); $folder=$folders[$type];
      $meta=$this->uploadResumable($encrypted,basename($encrypted),$folder,$token,$type,$sha);
      $driveId=(string)($meta['id']??''); if($driveId==='') throw new RuntimeException('Google Drive tidak mengembalikan ID file.');
      $remoteSize=(int)($meta['size']??0); if($remoteSize>0 && $remoteSize!==$size) throw new RuntimeException('Ukuran file Google Drive tidak sama dengan file lokal.');
      $this->updateJobSuccess($jobId,basename($encrypted),$size,$sha,$driveId,$folder,$method);
      try{ $this->pruneRetention($type,$folder,$token); }catch(Throwable $ignored){}
      return ['status'=>'success','job_id'=>$jobId,'filename'=>basename($encrypted),'bytes'=>$size,'sha256'=>$sha,'drive_file_id'=>$driveId,'method'=>$method];
    } catch(Throwable $e){
      $this->finishJob($jobId,'failed',$e->getMessage());
      throw $e;
    } finally {
      $this->deleteTree($work); if($lock){ flock($lock,LOCK_UN); fclose($lock); }
    }
  }

  private function startJob(string $type,string $period,string $by): int {
    $t=$this->safeIdentifier($this->jobsTable);
    $st=$this->pdo->prepare("SELECT id FROM `$t` WHERE backup_type=? AND period_key=? LIMIT 1"); $st->execute([$type,$period]); $id=(int)$st->fetchColumn();
    if($id>0){ $this->pdo->prepare("UPDATE `$t` SET status='running',initiated_by=?,attempt_count=attempt_count+1,message=NULL,started_at=NOW(),finished_at=NULL WHERE id=?")->execute([$by,$id]); return $id; }
    $this->pdo->prepare("INSERT INTO `$t`(backup_type,period_key,status,initiated_by,attempt_count,started_at) VALUES(?,?,'running',?,1,NOW())")->execute([$type,$period,$by]);
    return (int)$this->pdo->lastInsertId();
  }
  private function finishJob(int $id,string $status,string $message): void { $t=$this->safeIdentifier($this->jobsTable); $this->pdo->prepare("UPDATE `$t` SET status=?,message=?,finished_at=NOW() WHERE id=?")->execute([$status,substr($message,0,6000),$id]); }
  private function updateJobSuccess(int $id,string $name,int $bytes,string $sha,string $fileId,string $folderId,string $method): void { $t=$this->safeIdentifier($this->jobsTable); $this->pdo->prepare("UPDATE `$t` SET status='success',filename=?,bytes_size=?,sha256=?,drive_file_id=?,drive_folder_id=?,dump_method=?,message='Upload dan verifikasi berhasil',finished_at=NOW() WHERE id=?")->execute([$name,$bytes,$sha,$fileId,$folderId,$method,$id]); }

  public function recentJobs(int $limit=30): array { try{$t=$this->safeIdentifier($this->jobsTable);$limit=max(1,min(200,$limit));return $this->pdo->query("SELECT * FROM `$t` ORDER BY id DESC LIMIT $limit")->fetchAll(PDO::FETCH_ASSOC);}catch(Throwable $e){if($this->bootstrapError==='')$this->bootstrapError=$e->getMessage();return [];} }

  private function createDatabaseDump(string $target): string {
    if($this->tryMysqldump($target)) return 'mysqldump';
    $this->nativeDump($target); return 'native-php';
  }

  private function tryMysqldump(string $target): bool {
    if(!function_exists('exec') || !is_callable('exec') || !function_exists('escapeshellarg') || !is_callable('escapeshellarg')) return false;
    $errorFile=$target.'.err';
    try {
      $c=$this->dbConfig;
      $cmd=['mysqldump','--single-transaction','--quick','--routines','--triggers','--events','--hex-blob','--default-character-set=utf8mb4','--set-gtid-purged=OFF',
        '-h '.escapeshellarg((string)$c['host']),'-P '.escapeshellarg((string)$c['port']),'-u '.escapeshellarg((string)$c['user'])];
      if((string)($c['pass']??'')!=='') $cmd[]='--password='.escapeshellarg((string)$c['pass']);
      $cmd[]=escapeshellarg((string)$c['name']); $cmd[]='> '.escapeshellarg($target); $cmd[]='2> '.escapeshellarg($errorFile);
      $o=[]; $rc=1; @exec(implode(' ',$cmd),$o,$rc);
      return $rc===0 && is_file($target) && filesize($target)>100;
    } catch(Throwable $e) {
      return false;
    } finally {
      @unlink($errorFile);
    }
  }

  private function nativeDump(string $target): void {
    $fh=fopen($target,'wb'); if(!$fh) throw new RuntimeException('File dump tidak dapat dibuat.');
    fwrite($fh,"-- Automated backup: {$this->appName}\n-- Created: ".date('c')."\nSET NAMES utf8mb4;\nSET FOREIGN_KEY_CHECKS=0;\n\n");
    $tables=[];$views=[];
    foreach($this->pdo->query('SHOW FULL TABLES')->fetchAll(PDO::FETCH_NUM) as $r){ if(strtoupper((string)($r[1]??''))==='VIEW') $views[]=(string)$r[0]; else $tables[]=(string)$r[0]; }
    foreach($tables as $table){
      $q=$this->quoteIdentifier($table); $create=$this->pdo->query("SHOW CREATE TABLE $q")->fetch(PDO::FETCH_NUM);
      fwrite($fh,"DROP TABLE IF EXISTS $q;\n".(string)($create[1]??'').";\n");
      $st=$this->pdo->query("SELECT * FROM $q",PDO::FETCH_ASSOC); $cols=null; $batch=[];
      while($row=$st->fetch(PDO::FETCH_ASSOC)){
        if($cols===null) $cols=array_keys($row);
        $vals=[]; foreach($row as $v) $vals[]=$v===null?'NULL':$this->pdo->quote((string)$v);
        $batch[]='('.implode(',',$vals).')';
        if(count($batch)>=200){ fwrite($fh,$this->insertSql($table,$cols,$batch)); $batch=[]; }
      }
      if($batch && $cols) fwrite($fh,$this->insertSql($table,$cols,$batch)); fwrite($fh,"\n");
    }
    foreach($views as $view){ $q=$this->quoteIdentifier($view); $create=$this->pdo->query("SHOW CREATE VIEW $q")->fetch(PDO::FETCH_ASSOC); $sql=(string)($create['Create View']??array_values($create)[1]??''); fwrite($fh,"DROP VIEW IF EXISTS $q;\n$sql;\n\n"); }
    try { foreach($this->pdo->query('SHOW TRIGGERS')->fetchAll(PDO::FETCH_ASSOC) as $r){ $n=(string)$r['Trigger']; $cr=$this->pdo->query('SHOW CREATE TRIGGER '.$this->quoteIdentifier($n))->fetch(PDO::FETCH_ASSOC); $sql=(string)($cr['SQL Original Statement']??$cr['Create Trigger']??''); if($sql!=='') fwrite($fh,"DROP TRIGGER IF EXISTS ".$this->quoteIdentifier($n).";\nDELIMITER $$\n$sql$$\nDELIMITER ;\n\n"); } } catch(Throwable $e){}
    foreach(['PROCEDURE'=>'SHOW PROCEDURE STATUS WHERE Db=DATABASE()','FUNCTION'=>'SHOW FUNCTION STATUS WHERE Db=DATABASE()'] as $kind=>$query){ try{ foreach($this->pdo->query($query)->fetchAll(PDO::FETCH_ASSOC) as $r){ $n=(string)$r['Name']; $cr=$this->pdo->query("SHOW CREATE $kind ".$this->quoteIdentifier($n))->fetch(PDO::FETCH_ASSOC); $sql=(string)($cr['Create '.ucfirst(strtolower($kind))]??array_values($cr)[2]??''); if($sql!=='') fwrite($fh,"DROP $kind IF EXISTS ".$this->quoteIdentifier($n).";\nDELIMITER $$\n$sql$$\nDELIMITER ;\n\n"); } }catch(Throwable $e){} }
    try{ foreach($this->pdo->query('SHOW EVENTS')->fetchAll(PDO::FETCH_ASSOC) as $r){ $n=(string)($r['Name']??''); if($n==='') continue; $cr=$this->pdo->query('SHOW CREATE EVENT '.$this->quoteIdentifier($n))->fetch(PDO::FETCH_ASSOC); $sql=(string)($cr['Create Event']??''); if($sql!=='') fwrite($fh,"DROP EVENT IF EXISTS ".$this->quoteIdentifier($n).";\nDELIMITER $$\n$sql$$\nDELIMITER ;\n\n"); } }catch(Throwable $e){}
    fwrite($fh,"SET FOREIGN_KEY_CHECKS=1;\n"); fclose($fh);
  }
  private function insertSql(string $table,array $cols,array $batch): string { return 'INSERT INTO '.$this->quoteIdentifier($table).' ('.implode(',',array_map([$this,'quoteIdentifier'],$cols)).") VALUES\n".implode(",\n",$batch).";\n"; }

  private function createFullArchive(string $baseTarget,string $dump): string {
    $manifest=['format_version'=>2,'app'=>$this->appName,'app_key'=>$this->appKey,'site_code'=>$this->get('site_code',$this->appKey),'created_at'=>date('c'),'database'=>(string)$this->dbConfig['name'],'root_path'=>$this->rootPath,'external_paths'=>[],'components'=>['database','application']];
    foreach($this->externalPaths as $label=>$path){ $p=rtrim((string)$path,'/\\'); if($p!=='' && is_dir($p)){ $manifest['external_paths'][(string)$label]=$p; $manifest['components'][]='private_uploads/'.(string)$label; } }
    $checks=['_database/database.sql'=>hash_file('sha256',$dump)?:''];
    $manifestJson=json_encode($manifest,JSON_PRETTY_PRINT|JSON_UNESCAPED_SLASHES|JSON_UNESCAPED_UNICODE);
    $rootLen=strlen($this->rootPath)+1;
    if(class_exists('ZipArchive')){
      $target=$baseTarget.'.zip'; $zip=new ZipArchive();
      if($zip->open($target,ZipArchive::CREATE|ZipArchive::OVERWRITE)!==true) throw new RuntimeException('Arsip ZIP tidak dapat dibuat.');
      $zip->addFile($dump,'_database/database.sql');
      $it=new RecursiveIteratorIterator(new RecursiveDirectoryIterator($this->rootPath,FilesystemIterator::SKIP_DOTS),RecursiveIteratorIterator::LEAVES_ONLY);
      foreach($it as $file){ if(!$file->isFile()) continue; $path=$file->getPathname(); $rel=str_replace('\\','/',substr($path,$rootLen)); if($this->excludeFromArchive($rel,$path)) continue; $arc='application/'.$rel; $zip->addFile($path,$arc); $checks[$arc]=hash_file('sha256',$path)?:''; }
      foreach($manifest['external_paths'] as $label=>$base){ $baseLen=strlen($base)+1; $it=new RecursiveIteratorIterator(new RecursiveDirectoryIterator($base,FilesystemIterator::SKIP_DOTS),RecursiveIteratorIterator::LEAVES_ONLY); foreach($it as $file){ if(!$file->isFile()) continue; $path=$file->getPathname(); $rel=str_replace('\\','/',substr($path,$baseLen)); $arc='private_uploads/'.$this->cleanName((string)$label).'/'.$rel; $zip->addFile($path,$arc); $checks[$arc]=hash_file('sha256',$path)?:''; } }
      $zip->addFromString('_manifest.json',(string)$manifestJson); $zip->addFromString('_checksums.json',(string)json_encode($checks,JSON_PRETTY_PRINT|JSON_UNESCAPED_SLASHES));
      if(!$zip->close()) throw new RuntimeException('Arsip ZIP gagal ditutup.'); return $target;
    }
    if(class_exists('PharData')){
      $tarPath=$baseTarget.'.tar'; $gzPath=$tarPath.'.gz'; @unlink($tarPath); @unlink($gzPath);
      try{ $tar=new PharData($tarPath); $tar->addFile($dump,'_database/database.sql');
        $it=new RecursiveIteratorIterator(new RecursiveDirectoryIterator($this->rootPath,FilesystemIterator::SKIP_DOTS),RecursiveIteratorIterator::LEAVES_ONLY);
        foreach($it as $file){ if(!$file->isFile()) continue; $path=$file->getPathname(); $rel=str_replace('\\','/',substr($path,$rootLen)); if($this->excludeFromArchive($rel,$path)) continue; $arc='application/'.$rel; $tar->addFile($path,$arc); $checks[$arc]=hash_file('sha256',$path)?:''; }
        foreach($manifest['external_paths'] as $label=>$base){ $baseLen=strlen($base)+1; $it=new RecursiveIteratorIterator(new RecursiveDirectoryIterator($base,FilesystemIterator::SKIP_DOTS),RecursiveIteratorIterator::LEAVES_ONLY); foreach($it as $file){ if(!$file->isFile()) continue; $path=$file->getPathname(); $rel=str_replace('\\','/',substr($path,$baseLen)); $arc='private_uploads/'.$this->cleanName((string)$label).'/'.$rel; $tar->addFile($path,$arc); $checks[$arc]=hash_file('sha256',$path)?:''; } }
        $tar->addFromString('_manifest.json',(string)$manifestJson); $tar->addFromString('_checksums.json',(string)json_encode($checks,JSON_PRETTY_PRINT|JSON_UNESCAPED_SLASHES)); $tar->compress(Phar::GZ); unset($tar); @unlink($tarPath); if(!is_file($gzPath)) throw new RuntimeException('Arsip TAR.GZ tidak terbentuk.'); return $gzPath;
      }catch(Throwable $e){ @unlink($tarPath); @unlink($gzPath); throw new RuntimeException('Snapshot file gagal dibuat: '.$e->getMessage()); }
    }
    throw new RuntimeException('Snapshot file membutuhkan ekstensi ZipArchive atau PharData.');
  }
  private function excludeFromArchive(string $rel,string $absolute): bool {
    $r='/'.strtolower($rel);
    $parts=['/.git/','/node_modules/','/cache/','/logs/','/tmp/','/storage/private_backup/','/private_backup/','/android/.gradle/','/android/build/','/android/app/build/'];
    foreach($parts as $p) if(str_contains($r,$p)) return true;
    if(preg_match('/\.(sql\.gz|enc)$/i',$r)) return true;
    return str_starts_with(realpath($absolute)?:$absolute,realpath($this->privatePath)?:$this->privatePath);
  }

  private function keyBytes(): string {
    $path=$this->privatePath.'/encryption.key';
    if(!is_file($path)){ $key=random_bytes(32); if(file_put_contents($path,base64_encode($key),LOCK_EX)===false) throw new RuntimeException('Kunci enkripsi tidak dapat dibuat.'); @chmod($path,0600); return $key; }
    $raw=base64_decode(trim((string)file_get_contents($path)),true); if($raw===false || strlen($raw)!==32) throw new RuntimeException('Kunci enkripsi backup rusak.'); return $raw;
  }
  private function encryptSecret(string $plain): string { if(!function_exists('openssl_encrypt')) throw new RuntimeException('Ekstensi PHP OpenSSL belum aktif.'); $iv=random_bytes(12); $tag=''; $cipher=openssl_encrypt($plain,'aes-256-gcm',$this->keyBytes(),OPENSSL_RAW_DATA,$iv,$tag,'ABK-SECRET'); if($cipher===false) throw new RuntimeException('Enkripsi kredensial gagal.'); return 'v1:'.base64_encode($iv.$tag.$cipher); }
  private function decryptSecret(string $encoded): string { if(!function_exists('openssl_decrypt')) throw new RuntimeException('Ekstensi PHP OpenSSL belum aktif.'); if(!str_starts_with($encoded,'v1:')) return $encoded; $raw=base64_decode(substr($encoded,3),true); if($raw===false||strlen($raw)<29) throw new RuntimeException('Kredensial terenkripsi rusak.'); $iv=substr($raw,0,12);$tag=substr($raw,12,16);$cipher=substr($raw,28);$plain=openssl_decrypt($cipher,'aes-256-gcm',$this->keyBytes(),OPENSSL_RAW_DATA,$iv,$tag,'ABK-SECRET'); if($plain===false) throw new RuntimeException('Kredensial tidak dapat didekripsi.'); return $plain; }
  private function encryptFile(string $source,string $target): void {
    if(!function_exists('openssl_encrypt')) throw new RuntimeException('Ekstensi PHP OpenSSL belum aktif.');
    $in=fopen($source,'rb');$out=fopen($target,'wb');if(!$in||!$out) throw new RuntimeException('File backup tidak dapat dienkripsi.');
    $iv=random_bytes(12); fwrite($out,"ABK2".$iv); $ctx=hash_init('sha256',HASH_HMAC,$this->keyBytes()); $counter=0;
    while(!feof($in)){ $chunk=fread($in,1024*1024); if($chunk===false) throw new RuntimeException('Gagal membaca file backup.'); if($chunk==='') break; $nonce=substr($iv,0,8).pack('N',$counter++); $tag=''; $enc=openssl_encrypt($chunk,'aes-256-gcm',$this->keyBytes(),OPENSSL_RAW_DATA,$nonce,$tag,'ABK2'); if($enc===false) throw new RuntimeException('Enkripsi file gagal.'); $record=pack('N',strlen($enc)).$nonce.$tag.$enc; hash_update($ctx,$record); fwrite($out,$record); }
    fwrite($out,"END!".hash_final($ctx,true)); fclose($in);fclose($out);@chmod($target,0600);
  }

  private function ensureFolderTree(string $token): array {
    $root=$this->ensureFolder((string)$this->get('drive_root','ADENA_AUTOMATED_BACKUP'),null,$token,'root');
    $app=$this->ensureFolder(strtoupper($this->appKey),$root,$token,'app');
    $site=$this->ensureFolder((string)$this->get('site_code',$this->appKey),$app,$token,'site');
    $map=['root'=>$root,'app'=>$app,'site'=>$site];
    foreach(['6hourly'=>'6-HOURLY','daily'=>'DAILY','weekly'=>'WEEKLY','monthly'=>'MONTHLY'] as $k=>$name) $map[$k]=$this->ensureFolder($name,$site,$token,$k);
    return $map;
  }
  private function ensureFolder(string $name,?string $parent,string $token,string $cacheKey): string {
    $stored=(string)$this->get('drive_'.$cacheKey.'_id',''); if($stored!=='') return $stored;
    $q="mimeType='application/vnd.google-apps.folder' and trashed=false and name='".$this->driveEscape($name)."'"; if($parent) $q.=" and '".$this->driveEscape($parent)."' in parents";
    $url='https://www.googleapis.com/drive/v3/files?'.http_build_query(['q'=>$q,'spaces'=>'drive','fields'=>'files(id,name)','pageSize'=>10]);
    $res=$this->driveJson('GET',$url,$token); $id=(string)($res['files'][0]['id']??'');
    if($id===''){ $meta=['name'=>$name,'mimeType'=>'application/vnd.google-apps.folder']; if($parent) $meta['parents']=[$parent]; $created=$this->driveJson('POST','https://www.googleapis.com/drive/v3/files?fields=id,name',$token,$meta); $id=(string)($created['id']??''); }
    if($id==='') throw new RuntimeException('Folder Google Drive gagal dibuat: '.$name); $this->set('drive_'.$cacheKey.'_id',$id); return $id;
  }
  private function clearFolderCache(): void { foreach(['root','app','site','6hourly','daily','weekly','monthly'] as $k) $this->set('drive_'.$k.'_id',''); }

  private function uploadResumable(string $file,string $name,string $folder,string $token,string $type,string $sha): array {
    if(!function_exists('curl_init')) throw new RuntimeException('Ekstensi PHP cURL belum aktif. Aktifkan cURL di hosting.');
    $size=(int)filesize($file); $meta=['name'=>$name,'parents'=>[$folder],'appProperties'=>['backup_type'=>$type,'site_code'=>(string)$this->get('site_code',$this->appKey),'sha256'=>$sha]];
    $ch=curl_init('https://www.googleapis.com/upload/drive/v3/files?uploadType=resumable&fields=id,name,size,createdTime,md5Checksum,parents');
    curl_setopt_array($ch,[CURLOPT_POST=>true,CURLOPT_RETURNTRANSFER=>true,CURLOPT_HEADER=>true,CURLOPT_HTTPHEADER=>['Authorization: Bearer '.$token,'Content-Type: application/json; charset=UTF-8','X-Upload-Content-Type: application/octet-stream','X-Upload-Content-Length: '.$size],CURLOPT_POSTFIELDS=>json_encode($meta),CURLOPT_TIMEOUT=>60]);
    $raw=curl_exec($ch);$code=(int)curl_getinfo($ch,CURLINFO_RESPONSE_CODE);$headerSize=(int)curl_getinfo($ch,CURLINFO_HEADER_SIZE);$err=curl_error($ch);curl_close($ch);
    if($raw===false||$code<200||$code>=300) throw new RuntimeException('Gagal memulai upload Google Drive: '.($err?:substr((string)$raw,0,500)));
    $headers=substr((string)$raw,0,$headerSize); if(!preg_match('/^Location:\s*(.+)$/mi',$headers,$m)) throw new RuntimeException('Google Drive tidak memberikan URL resumable upload.'); $location=trim($m[1]);
    $fh=fopen($file,'rb'); if(!$fh) throw new RuntimeException('File terenkripsi tidak dapat dibaca.');
    $ch=curl_init($location); curl_setopt_array($ch,[CURLOPT_UPLOAD=>true,CURLOPT_INFILE=>$fh,CURLOPT_INFILESIZE=>$size,CURLOPT_RETURNTRANSFER=>true,CURLOPT_HTTPHEADER=>['Authorization: Bearer '.$token,'Content-Type: application/octet-stream'],CURLOPT_TIMEOUT=>0]);
    $body=curl_exec($ch);$code=(int)curl_getinfo($ch,CURLINFO_RESPONSE_CODE);$err=curl_error($ch);curl_close($ch);fclose($fh);
    if($body===false||$code<200||$code>=300) throw new RuntimeException('Upload Google Drive gagal: '.($err?:substr((string)$body,0,500)));
    $json=json_decode((string)$body,true); if(!is_array($json)) throw new RuntimeException('Respons upload Google Drive tidak valid.'); return $json;
  }

  private function pruneRetention(string $type,string $folder,string $token): void {
    $days=max(1,(int)$this->get('retention_'.$type.'_days','30')); $cut=(new DateTimeImmutable('now'))->modify('-'.$days.' days');
    $q="'".$this->driveEscape($folder)."' in parents and trashed=false"; $url='https://www.googleapis.com/drive/v3/files?'.http_build_query(['q'=>$q,'fields'=>'files(id,name,createdTime)','pageSize'=>1000,'orderBy'=>'createdTime desc']);
    $res=$this->driveJson('GET',$url,$token); foreach(($res['files']??[]) as $f){ try{$created=new DateTimeImmutable((string)$f['createdTime']); if($created<$cut) $this->driveJson('DELETE','https://www.googleapis.com/drive/v3/files/'.rawurlencode((string)$f['id']),$token);}catch(Throwable $e){} }
  }


  public function successfulBackups(string $type,int $limit=100): array {
    if(!in_array($type,['6hourly','daily','weekly','monthly'],true)) return [];
    $t=$this->safeIdentifier($this->jobsTable); $limit=max(1,min(200,$limit));
    $st=$this->pdo->prepare("SELECT * FROM `$t` WHERE backup_type=? AND status='success' AND drive_file_id IS NOT NULL ORDER BY id DESC LIMIT $limit"); $st->execute([$type]); return $st->fetchAll(PDO::FETCH_ASSOC);
  }
  public function backupById(int $id): array { $t=$this->safeIdentifier($this->jobsTable); $st=$this->pdo->prepare("SELECT * FROM `$t` WHERE id=? AND status='success' LIMIT 1"); $st->execute([$id]); $r=$st->fetch(PDO::FETCH_ASSOC); if(!$r) throw new RuntimeException('Backup tidak ditemukan.'); return $r; }
  public function testRestore(int $jobId): array {
    $this->assertReady(); @set_time_limit(0); $job=$this->backupById($jobId); $work=$this->privatePath.'/restore-test-'.$jobId.'-'.bin2hex(random_bytes(3)); if(!@mkdir($work,0700,true)&&!is_dir($work)) throw new RuntimeException('Folder tes restore tidak dapat dibuat.');
    try{ $enc=$work.'/'.basename((string)$job['filename']); $this->downloadDriveFile((string)$job['drive_file_id'],$enc,$this->accessToken()); if(($job['sha256']??'')!=='' && !hash_equals((string)$job['sha256'],hash_file('sha256',$enc)?:'')) throw new RuntimeException('Checksum file Google Drive tidak sesuai.');
      $plain=$work.'/plain'; $this->decryptFile($enc,$plain); $type=(string)$job['backup_type']; $result=['job_id'=>$jobId,'type'=>$type,'filename'=>$job['filename'],'checked_at'=>date('c'),'database_ok'=>false,'archive_ok'=>false,'private_uploads'=>0,'application_files'=>0];
      if(in_array($type,['6hourly','daily'],true)){ $sql=$work.'/database.sql'; $this->gunzipFile($plain,$sql); $this->validateSqlDump($sql); $result['database_ok']=true; $result['archive_ok']=true; }
      else { $extract=$work.'/extract'; @mkdir($extract,0700,true); $this->extractArchive($plain,$extract,(string)$job['filename']); $sql=$extract.'/_database/database.sql'; $this->validateSqlDump($sql); $this->validateExtractedChecksums($extract); $result['database_ok']=true; $result['archive_ok']=true; $result['private_uploads']=$this->countFiles($extract.'/private_uploads'); $result['application_files']=$this->countFiles($extract.'/application'); }
      $this->set('restore_test_'.$jobId,json_encode($result,JSON_UNESCAPED_SLASHES)); return $result;
    } finally { $this->deleteTree($work); }
  }
  public function restoreBackup(int $jobId,string $confirm): array {
    $this->assertReady(); $job=$this->backupById($jobId); $site=(string)$this->get('site_code',$this->appKey); if($confirm!=='RESTORE '.$site) throw new RuntimeException('Konfirmasi restore tidak sesuai. Ketik: RESTORE '.$site);
    $tested=json_decode((string)$this->get('restore_test_'.$jobId,''),true); if(!is_array($tested)||empty($tested['database_ok'])||empty($tested['archive_ok'])) throw new RuntimeException('Backup harus lulus Tes Restore terlebih dahulu.');
    @set_time_limit(0); @ignore_user_abort(true); $lock=fopen($this->privatePath.'/restore.lock','c+'); if(!$lock||!flock($lock,LOCK_EX|LOCK_NB)) throw new RuntimeException('Proses restore lain masih berjalan.');
    $work=$this->privatePath.'/restore-'.$jobId.'-'.bin2hex(random_bytes(3)); @mkdir($work,0700,true); $maintenance=$this->rootPath.'/storage/maintenance.backup_restore';
    try{ @file_put_contents($maintenance,'Restore berjalan '.date('c'),LOCK_EX); $pre=$this->runBackup('daily','pre-restore');
      $enc=$work.'/'.basename((string)$job['filename']); $this->downloadDriveFile((string)$job['drive_file_id'],$enc,$this->accessToken()); if(($job['sha256']??'')!==''&&!hash_equals((string)$job['sha256'],hash_file('sha256',$enc)?:'')) throw new RuntimeException('Checksum berubah sebelum restore.'); $plain=$work.'/plain'; $this->decryptFile($enc,$plain); $type=(string)$job['backup_type'];
      if(in_array($type,['6hourly','daily'],true)){ $sql=$work.'/database.sql'; $this->gunzipFile($plain,$sql); $this->importSql($sql); }
      else { $extract=$work.'/extract'; @mkdir($extract,0700,true); $this->extractArchive($plain,$extract,(string)$job['filename']); $this->validateExtractedChecksums($extract); $this->importSql($extract.'/_database/database.sql'); $this->restoreTree($extract.'/application',$this->rootPath,true); foreach($this->externalPaths as $label=>$target){ $src=$extract.'/private_uploads/'.$this->cleanName((string)$label); if(is_dir($src)) $this->restoreTree($src,rtrim((string)$target,'/\\'),false); } }
      return ['status'=>'success','pre_restore'=>$pre['filename']??'','restored'=>$job['filename']];
    } finally { @unlink($maintenance); $this->deleteTree($work); if($lock){flock($lock,LOCK_UN);fclose($lock);} }
  }
  private function downloadDriveFile(string $id,string $target,string $token): void { if(!function_exists('curl_init')) throw new RuntimeException('cURL tidak aktif.'); $fh=fopen($target,'wb'); if(!$fh) throw new RuntimeException('File download tidak dapat dibuat.'); $ch=curl_init('https://www.googleapis.com/drive/v3/files/'.rawurlencode($id).'?alt=media'); curl_setopt_array($ch,[CURLOPT_RETURNTRANSFER=>false,CURLOPT_FILE=>$fh,CURLOPT_FOLLOWLOCATION=>true,CURLOPT_HTTPHEADER=>['Authorization: Bearer '.$token],CURLOPT_TIMEOUT=>0]); $ok=curl_exec($ch);$code=(int)curl_getinfo($ch,CURLINFO_RESPONSE_CODE);$err=curl_error($ch);curl_close($ch);fclose($fh); if(!$ok||$code<200||$code>=300){@unlink($target);throw new RuntimeException('Download backup dari Drive gagal ('.$code.'): '.$err);} }
  private function decryptFile(string $source,string $target): void { $in=fopen($source,'rb');$out=fopen($target,'wb');if(!$in||!$out) throw new RuntimeException('File restore tidak dapat dibuka.');$head=fread($in,16);if(strlen($head)!==16||substr($head,0,4)!=='ABK2') throw new RuntimeException('Format enkripsi backup tidak dikenal.');$baseIv=substr($head,4,12);$ctx=hash_init('sha256',HASH_HMAC,$this->keyBytes());while(true){$lenRaw=fread($in,4);if($lenRaw==='') throw new RuntimeException('Penutup file enkripsi tidak ditemukan.');if($lenRaw==='END!'){ $expected=fread($in,32);$actual=hash_final($ctx,true);if(!hash_equals($expected,$actual)) throw new RuntimeException('Integritas file enkripsi gagal.');break;}if(strlen($lenRaw)!==4) throw new RuntimeException('Record enkripsi rusak.');$len=unpack('N',$lenRaw)[1];$nonce=fread($in,12);$tag=fread($in,16);$cipher=fread($in,$len);if(strlen($nonce)!==12||strlen($tag)!==16||strlen($cipher)!==$len) throw new RuntimeException('Record enkripsi terpotong.');$record=$lenRaw.$nonce.$tag.$cipher;hash_update($ctx,$record);$plain=openssl_decrypt($cipher,'aes-256-gcm',$this->keyBytes(),OPENSSL_RAW_DATA,$nonce,$tag,'ABK2');if($plain===false) throw new RuntimeException('Dekripsi backup gagal.');fwrite($out,$plain);}fclose($in);fclose($out); }
  private function gunzipFile(string $source,string $target): void { $in=gzopen($source,'rb');$out=fopen($target,'wb');if(!$in||!$out) throw new RuntimeException('File GZIP backup tidak valid.');while(!gzeof($in)){ $b=gzread($in,1048576);if($b===false) throw new RuntimeException('GZIP rusak.');fwrite($out,$b);}gzclose($in);fclose($out); }
  private function extractArchive(string $plain,string $target,string $filename): void { if(str_contains(strtolower($filename),'.zip.enc')){ if(!class_exists('ZipArchive')) throw new RuntimeException('ZipArchive diperlukan untuk restore.');$z=new ZipArchive();if($z->open($plain)!==true) throw new RuntimeException('ZIP backup rusak.');for($i=0;$i<$z->numFiles;$i++){ $n=$z->getNameIndex($i);if($n===false||str_contains($n,'../')||str_starts_with($n,'/')) throw new RuntimeException('Path arsip tidak aman.');}$z->extractTo($target);$z->close();return;} if(class_exists('PharData')){ $tmp=$plain.'.tar.gz';rename($plain,$tmp);$p=new PharData($tmp);$p->extractTo($target,null,true);return;}throw new RuntimeException('Format arsip tidak didukung server.'); }
  private function validateSqlDump(string $sql): void { if(!is_file($sql)||filesize($sql)<100) throw new RuntimeException('SQL backup kosong.');$head=(string)file_get_contents($sql,false,null,0,8192);if(!str_contains($head,'SET FOREIGN_KEY_CHECKS')&&!str_contains($head,'Automated backup')) throw new RuntimeException('SQL backup tidak dikenali.');if(!preg_match('/CREATE TABLE|CREATE VIEW/i',(string)file_get_contents($sql))) throw new RuntimeException('SQL tidak berisi struktur database.'); }
  private function validateExtractedChecksums(string $root): void { $f=$root.'/_checksums.json';if(!is_file($f)) throw new RuntimeException('Manifest checksum tidak ditemukan.');$map=json_decode((string)file_get_contents($f),true);if(!is_array($map)) throw new RuntimeException('Manifest checksum rusak.');foreach($map as $rel=>$sha){$p=$root.'/'.ltrim((string)$rel,'/');if(!is_file($p)||!hash_equals((string)$sha,hash_file('sha256',$p)?:'')) throw new RuntimeException('Checksum komponen gagal: '.$rel);} }
  private function countFiles(string $dir): int { if(!is_dir($dir)) return 0;$n=0;$it=new RecursiveIteratorIterator(new RecursiveDirectoryIterator($dir,FilesystemIterator::SKIP_DOTS));foreach($it as $f)if($f->isFile())$n++;return $n; }
  private function importSql(string $file): void { $this->validateSqlDump($file);$sql=(string)file_get_contents($file);$sql=preg_replace('/^DELIMITER\s+\S+\s*$/mi','',$sql);$stmts=preg_split('/;\s*(?:\r?\n|$)/',$sql);$this->pdo->exec('SET FOREIGN_KEY_CHECKS=0');try{foreach($stmts as $stmt){$stmt=trim($stmt);if($stmt===''||str_starts_with($stmt,'--'))continue;if(str_contains($stmt,'$$'))continue;$this->pdo->exec($stmt);}}finally{$this->pdo->exec('SET FOREIGN_KEY_CHECKS=1');} }
  private function restoreTree(string $source,string $target,bool $application): void { if(!is_dir($source)) return;if(!is_dir($target)&&!@mkdir($target,0750,true)&&!is_dir($target)) throw new RuntimeException('Folder tujuan restore tidak dapat dibuat.');$protected=['config.php','config.local.php','.env','storage/private_backup/'];$baseLen=strlen($source)+1;$it=new RecursiveIteratorIterator(new RecursiveDirectoryIterator($source,FilesystemIterator::SKIP_DOTS),RecursiveIteratorIterator::SELF_FIRST);foreach($it as $f){$rel=str_replace('\\','/',substr($f->getPathname(),$baseLen));if($application){foreach($protected as $p)if($rel===$p||str_starts_with($rel,$p))continue;}$dest=$target.DIRECTORY_SEPARATOR.str_replace('/',DIRECTORY_SEPARATOR,$rel);if($f->isDir()){if(!is_dir($dest))@mkdir($dest,0750,true);}else{if(!is_dir(dirname($dest)))@mkdir(dirname($dest),0750,true);if(!@copy($f->getPathname(),$dest))throw new RuntimeException('Gagal memulihkan file: '.$rel);}} }
  private function driveJson(string $method,string $url,string $token,?array $body=null): array { return $this->httpJson($url,$method,$body,['Authorization: Bearer '.$token]); }
  private function httpForm(string $url,array $fields): array { if(!function_exists('curl_init')) throw new RuntimeException('Ekstensi PHP cURL belum aktif. Aktifkan cURL di hosting.'); $ch=curl_init($url);curl_setopt_array($ch,[CURLOPT_POST=>true,CURLOPT_RETURNTRANSFER=>true,CURLOPT_HTTPHEADER=>['Content-Type: application/x-www-form-urlencoded'],CURLOPT_POSTFIELDS=>http_build_query($fields),CURLOPT_TIMEOUT=>60]);return $this->finishCurlJson($ch); }
  private function httpJson(string $url,string $method='GET',?array $body=null,array $headers=[]): array { if(!function_exists('curl_init')) throw new RuntimeException('Ekstensi PHP cURL belum aktif. Aktifkan cURL di hosting.'); $ch=curl_init($url);$headers[]='Accept: application/json';if($body!==null){$headers[]='Content-Type: application/json';curl_setopt($ch,CURLOPT_POSTFIELDS,json_encode($body));}curl_setopt_array($ch,[CURLOPT_CUSTOMREQUEST=>$method,CURLOPT_RETURNTRANSFER=>true,CURLOPT_HTTPHEADER=>$headers,CURLOPT_TIMEOUT=>60]);return $this->finishCurlJson($ch,$method==='DELETE'); }
  private function finishCurlJson($ch,bool $allowEmpty=false): array { $body=curl_exec($ch);$code=(int)curl_getinfo($ch,CURLINFO_RESPONSE_CODE);$err=curl_error($ch);curl_close($ch);if($body===false||$code<200||$code>=300) throw new RuntimeException('HTTP Google gagal ('.$code.'): '.($err?:substr((string)$body,0,700)));if($allowEmpty && trim((string)$body)==='') return []; $json=json_decode((string)$body,true);if(!is_array($json)) throw new RuntimeException('Respons Google bukan JSON valid.');return $json; }

  private function safeIdentifier(string $v): string { if(!preg_match('/^[A-Za-z0-9_]+$/',$v)) throw new InvalidArgumentException('Identifier database tidak valid.'); return $v; }
  public function quoteIdentifier(string $v): string { return '`'.str_replace('`','``',$v).'`'; }
  private function cleanName(string $v): string { return trim(preg_replace('/[^A-Za-z0-9_-]+/','-',$v),'-') ?: $this->appKey; }
  private function driveEscape(string $v): string { return str_replace(["\\","'"],["\\\\","\\'"],$v); }
  private function deleteTree(string $dir): void { if(!is_dir($dir)) return; $it=new RecursiveIteratorIterator(new RecursiveDirectoryIterator($dir,FilesystemIterator::SKIP_DOTS),RecursiveIteratorIterator::CHILD_FIRST);foreach($it as $f){$f->isDir()?@rmdir($f->getPathname()):@unlink($f->getPathname());}@rmdir($dir); }
}
