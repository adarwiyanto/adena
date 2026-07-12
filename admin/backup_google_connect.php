<?php
$backupRoot = dirname(__DIR__);
require_once $backupRoot.'/core/backup_safe.php';
backup_safe_register($backupRoot, 'ADENA Google OAuth connect', 'html');
require_once $backupRoot.'/core/auth.php';
start_secure_session();
require_admin();
if (!current_user_is_owner()) {
    http_response_code(403);
    exit('Akses hanya untuk owner.');
}

function adena_backup_absolute_url(string $path): string {
    if (preg_match('~^https?://~i', $path)) return $path;
    $scheme = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') ? 'https' : 'http';
    return $scheme.'://'.($_SERVER['HTTP_HOST'] ?? 'localhost').'/'.ltrim($path, '/');
}

try {
    $token = (string)($_GET['token'] ?? '');
    $expected = (string)($_SESSION['backup_connect_token'] ?? '');
    if ($token === '' || $expected === '' || !hash_equals($expected, $token)) {
        throw new RuntimeException('Token koneksi Google Drive tidak valid atau sudah kedaluwarsa. Buka ulang halaman Setting Backup.');
    }
    unset($_SESSION['backup_connect_token']);

    require_once $backupRoot.'/core/backup_adapter.php';
    $svc = adena_backup_service();
    if (!$svc->hasOAuthClient()) {
        throw new RuntimeException('Google OAuth Client ID dan Client Secret belum tersimpan dengan benar.');
    }

    $state = bin2hex(random_bytes(32));
    $_SESSION['backup_oauth_state'] = $state;
    $_SESSION['backup_oauth_started_at'] = time();

    $callback = adena_backup_absolute_url(base_url('admin/backup_google_callback.php'));
    $authorizationUrl = $svc->authorizationUrl($callback, $state);
    backup_safe_write_log($backupRoot, 'ADENA OAuth connect', 'OAuth redirect generated for callback '.$callback, null);

    if (!headers_sent()) {
        header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');
        header('Pragma: no-cache');
        header('Location: '.$authorizationUrl, true, 302);
        backup_safe_finish();
        exit;
    }

    backup_safe_finish();
    ?><!doctype html><html><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"><title>Hubungkan Google Drive</title></head><body>
    <p>Mengarahkan ke halaman otorisasi Google…</p>
    <p><a href="<?=htmlspecialchars($authorizationUrl, ENT_QUOTES, 'UTF-8')?>">Klik di sini bila tidak berpindah otomatis</a></p>
    <script>window.location.replace(<?=json_encode($authorizationUrl, JSON_UNESCAPED_SLASHES)?>);</script>
    </body></html><?php
} catch (Throwable $e) {
    $message = backup_safe_capture($backupRoot, 'ADENA OAuth connect', $e);
    $_SESSION['backup_flash_error'] = $message;
    backup_safe_finish();
    header('Location: '.base_url('admin/backup.php'));
    exit;
}
