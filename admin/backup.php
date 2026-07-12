<?php
$backupRoot = dirname(__DIR__);
require_once $backupRoot.'/core/backup_safe.php';
backup_safe_register($backupRoot, 'ADENA admin backup settings', 'html');
require_once $backupRoot.'/core/auth.php';
require_once $backupRoot.'/core/csrf.php';
start_secure_session();
require_admin();
if (!current_user_is_owner()) { http_response_code(403); exit('Akses hanya untuk owner.'); }

$svc = null; $loadError = ''; $msg = (string)($_SESSION['backup_flash'] ?? ''); $err = (string)($_SESSION['backup_flash_error'] ?? '');
unset($_SESSION['backup_flash'], $_SESSION['backup_flash_error']);
try {
    require_once $backupRoot.'/core/backup_adapter.php';
    require_once __DIR__.'/backup_ui.php';
    $svc = adena_backup_service();
} catch (Throwable $e) {
    $loadError = backup_safe_capture($backupRoot, 'ADENA service bootstrap', $e);
}

$relativeCallback = base_url('admin/backup_google_callback.php');
$callback = preg_match('~^https?://~i', $relativeCallback) ? $relativeCallback : (((!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') ? 'https' : 'http').'://'.($_SERVER['HTTP_HOST'] ?? 'localhost').'/'.ltrim($relativeCallback, '/'));
$connectToken = bin2hex(random_bytes(24));
$_SESSION['backup_connect_token'] = $connectToken;
$relativeConnect = base_url('admin/backup_google_connect.php?token='.rawurlencode($connectToken));
$connectUrl = preg_match('~^https?://~i', $relativeConnect) ? $relativeConnect : (((!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') ? 'https' : 'http').'://'.($_SERVER['HTTP_HOST'] ?? 'localhost').'/'.ltrim($relativeConnect, '/'));
if ($_SERVER['REQUEST_METHOD'] === 'POST' && $svc) {
    try {
        if (!csrf_verify((string)($_POST['_csrf'] ?? ''))) throw new RuntimeException('CSRF token tidak valid.');
        $action = (string)($_POST['backup_action'] ?? '');
        if ($action === 'repair') { $svc->repairInfrastructure(); $msg = 'Struktur backup berhasil diperiksa dan diperbaiki.'; }
        elseif ($action === 'save_config') { $svc->saveConfiguration($_POST); $msg = 'Konfigurasi backup berhasil disimpan.'; }
        elseif ($action === 'connect') { $state = bin2hex(random_bytes(24)); $_SESSION['backup_oauth_state'] = $state; header('Location: '.$svc->authorizationUrl($callback, $state)); backup_safe_finish(); exit; }
        elseif ($action === 'test') { $result = $svc->testConnection(); $msg = 'Koneksi berhasil ke '.($result['email'] ?? 'Google Drive').'.'; }
        elseif ($action === 'disconnect') { $svc->disconnect(); $msg = 'Koneksi Google Drive diputus.'; }
        elseif ($action === 'download_key') { $site = preg_replace('/[^A-Za-z0-9_-]+/', '-', (string)$svc->get('site_code', $svc->appKey())); while (ob_get_level() > 0) @ob_end_clean(); header('Content-Type: text/plain; charset=utf-8'); header('Content-Disposition: attachment; filename="backup-recovery-key-'.$site.'.txt"'); echo $svc->recoveryKeyText(); backup_safe_finish(); exit; }
        elseif ($action === 'run') { $result = $svc->runBackup((string)($_POST['backup_type'] ?? 'daily'), 'owner'); $msg = 'Backup berhasil: '.$result['filename']; }
        elseif ($action === 'test_restore') {
            $jobId = (int)($_POST['backup_job_id'] ?? 0);
            if ($jobId <= 0) {
                $type = (string)($_POST['backup_type'] ?? 'daily');
                $latest = $svc->successfulBackups($type, 1);
                if (!$latest) {
                    throw new RuntimeException('Belum ada backup berhasil untuk timeframe yang dipilih. Jalankan Backup Sekarang terlebih dahulu.');
                }
                $jobId = (int)($latest[0]['id'] ?? 0);
            }
            $result = $svc->testRestore($jobId);
            $msg = 'Tes Restore lulus: database valid, file aplikasi '.(int)($result['application_files']??0).', private uploads '.(int)($result['private_uploads']??0).'.';
        }
        elseif ($action === 'restore') { $result = $svc->restoreBackup((int)($_POST['backup_job_id'] ?? 0),(string)($_POST['restore_confirm'] ?? '')); $msg = 'Restore berhasil. Pre-restore backup: '.($result['pre_restore']??'-'); }
    } catch (Throwable $e) {
        $err = backup_safe_capture($backupRoot, 'ADENA backup action', $e);
    }
}
if ($_SERVER['REQUEST_METHOD'] === 'POST' && !$svc && $loadError === '') $loadError = 'Engine backup tidak tersedia.';

$customCss = setting('custom_css', '');
?><!doctype html><html><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"><title>Setting Backup Google Drive</title><link rel="icon" href="<?=e(favicon_url())?>"><link rel="stylesheet" href="<?=e(asset_url('assets/app.css'))?>"><style><?=$customCss?></style></head><body><div class="container"><?php include __DIR__.'/partials_sidebar.php'; ?><div class="main"><div class="topbar"><button class="btn" data-toggle-sidebar type="button">Menu</button><div class="badge">Setting Backup Google Drive</div></div><div class="content">
<?php if ($msg !== ''): ?><div class="card" style="border-color:#86efac;background:#f0fdf4"><?=e($msg)?></div><?php endif; ?>
<?php if ($err !== ''): ?><div class="card" style="border-color:#fca5a5;background:#fef2f2"><?=e($err)?></div><?php endif; ?>
<?php
if ($svc) {
    $cronSecret = (string)$svc->get('cron_secret', '');
    $cronFile = dirname(__DIR__).'/cron_backup.php';
    $cronCommand = backup_build_cron_command($svc, $cronFile);
    $relativeCron = base_url('cron_backup.php?key='.rawurlencode($cronSecret));
    $cronUrl = preg_match('~^https?://~i', $relativeCron) ? $relativeCron : (((!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') ? 'https' : 'http').'://'.($_SERVER['HTTP_HOST'] ?? 'localhost').'/'.ltrim($relativeCron, '/'));
    backup_render_settings($svc, $callback, $cronCommand, $cronUrl, '<input type="hidden" name="_csrf" value="'.e(csrf_token()).'">', '', $connectUrl);
} else { backup_safe_render_error($loadError, $backupRoot); }
backup_safe_finish();
?></div></div></div><script defer src="<?=e(asset_url('assets/app.js'))?>"></script></body></html>
