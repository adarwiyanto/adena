<?php
require_once __DIR__.'/../core/auth.php'; require_once __DIR__.'/../core/csrf.php'; require_once __DIR__.'/../core/backup_adapter.php'; require_once __DIR__.'/backup_ui.php';
start_secure_session(); require_admin(); if(!current_user_is_owner()){ http_response_code(403); exit('Akses hanya untuk owner.'); }
$svc=adena_backup_service(); $msg=(string)($_SESSION['backup_flash']??''); $err=(string)($_SESSION['backup_flash_error']??''); unset($_SESSION['backup_flash'],$_SESSION['backup_flash_error']);
$relativeCallback=base_url('admin/backup_google_callback.php'); $callback=preg_match('~^https?://~i',$relativeCallback)?$relativeCallback:(((!empty($_SERVER['HTTPS'])&&$_SERVER['HTTPS']!=='off')?'https':'http').'://'.($_SERVER['HTTP_HOST']??'localhost').'/'.ltrim($relativeCallback,'/'));
if($_SERVER['REQUEST_METHOD']==='POST'){
 try{ if(!csrf_verify((string)($_POST['_csrf']??''))) throw new RuntimeException('CSRF token tidak valid.'); $a=(string)($_POST['backup_action']??'');
  if($a==='repair'){ $svc->repairInfrastructure(); $msg='Struktur backup berhasil diperiksa dan diperbaiki.'; }
  elseif($a==='save_config'){ $svc->saveConfiguration($_POST); $msg='Konfigurasi backup berhasil disimpan.'; }
  elseif($a==='connect'){ $state=bin2hex(random_bytes(24)); $_SESSION['backup_oauth_state']=$state; header('Location: '.$svc->authorizationUrl($callback,$state)); exit; }
  elseif($a==='test'){ $r=$svc->testConnection(); $msg='Koneksi berhasil ke '.($r['email']??'Google Drive').'.'; }
  elseif($a==='disconnect'){ $svc->disconnect(); $msg='Koneksi Google Drive diputus.'; }
  elseif($a==='download_key'){ $site=preg_replace('/[^A-Za-z0-9_-]+/','-',(string)$svc->get('site_code',$svc->appKey())); while(ob_get_level()>0)@ob_end_clean(); header('Content-Type: text/plain; charset=utf-8'); header('Content-Disposition: attachment; filename="backup-recovery-key-'.$site.'.txt"'); echo $svc->recoveryKeyText(); exit; }
  elseif($a==='run'){ $r=$svc->runBackup((string)($_POST['backup_type']??'daily'),'owner'); $msg='Backup berhasil: '.$r['filename']; }
 }catch(Throwable $e){ $err=$e->getMessage(); }
}
$cronSecret=(string)$svc->get('cron_secret',''); $cronFile=dirname(__DIR__).'/cron_backup.php';
$cronCommand='/usr/local/bin/php -q '.escapeshellarg($cronFile).' >/dev/null 2>&1'; $relativeCron=base_url('cron_backup.php?key='.rawurlencode($cronSecret)); $cronUrl=preg_match('~^https?://~i',$relativeCron)?$relativeCron:(((!empty($_SERVER['HTTPS'])&&$_SERVER['HTTPS']!=='off')?'https':'http').'://'.($_SERVER['HTTP_HOST']??'localhost').'/'.ltrim($relativeCron,'/'));
$customCss=setting('custom_css','');
?><!doctype html><html><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"><title>Setting Backup Google Drive</title><link rel="icon" href="<?=e(favicon_url())?>"><link rel="stylesheet" href="<?=e(asset_url('assets/app.css'))?>"><style><?=$customCss?></style></head><body><div class="container"><?php include __DIR__.'/partials_sidebar.php'; ?><div class="main"><div class="topbar"><button class="btn" data-toggle-sidebar type="button">Menu</button><div class="badge">Setting Backup Google Drive</div></div><div class="content"><?php if($msg): ?><div class="card" style="border-color:#86efac;background:#f0fdf4"><?=e($msg)?></div><?php endif; ?><?php if($err): ?><div class="card" style="border-color:#fca5a5;background:#fef2f2"><?=e($err)?></div><?php endif; ?><?php backup_render_settings($svc,$callback,$cronCommand,$cronUrl,'<input type="hidden" name="_csrf" value="'.e(csrf_token()).'">'); ?></div></div></div><script defer src="<?=e(asset_url('assets/app.js'))?>"></script></body></html>
