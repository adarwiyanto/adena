<?php
/**
 * Minimal guard for the Google Drive backup module.
 * This file intentionally has no database or application bootstrap dependency.
 */
if (!function_exists('backup_safe_log_path')) {
    function backup_safe_log_path($rootPath) {
        return rtrim((string)$rootPath, '/\\').'/storage/private_backup/backup-error.log';
    }

    function backup_safe_write_log($rootPath, $context, $message, $throwable = null) {
        $rootPath = rtrim((string)$rootPath, '/\\');
        $dir = $rootPath.'/storage/private_backup';
        $line = '['.date('Y-m-d H:i:s').'] ['.(string)$context.'] '.(string)$message;
        if ($throwable instanceof Throwable) {
            $line .= ' | '.get_class($throwable).' in '.$throwable->getFile().':'.$throwable->getLine();
            $trace = $throwable->getTraceAsString();
            if ($trace !== '') $line .= "\n".$trace;
        }
        $line .= "\n";
        $written = false;
        if ((is_dir($dir) || @mkdir($dir, 0700, true)) && is_writable($dir)) {
            @chmod($dir, 0700);
            if (!is_file($dir.'/.htaccess')) @file_put_contents($dir.'/.htaccess', "Require all denied\nDeny from all\n");
            if (!is_file($dir.'/index.html')) @file_put_contents($dir.'/index.html', '');
            $written = @file_put_contents($dir.'/backup-error.log', $line, FILE_APPEND | LOCK_EX) !== false;
            if ($written) @chmod($dir.'/backup-error.log', 0600);
        }
        if (!$written) @error_log('[Google Drive Backup] '.$line);
    }

    function backup_safe_error_text($throwable) {
        if ($throwable instanceof Throwable) {
            $file = basename((string)$throwable->getFile());
            return get_class($throwable).': '.$throwable->getMessage().' ('.$file.':'.$throwable->getLine().')';
        }
        return 'Kesalahan modul backup yang tidak diketahui.';
    }

    function backup_safe_register($rootPath, $context, $mode) {
        $GLOBALS['BACKUP_SAFE_ROOT'] = rtrim((string)$rootPath, '/\\');
        $GLOBALS['BACKUP_SAFE_CONTEXT'] = (string)$context;
        $GLOBALS['BACKUP_SAFE_MODE'] = (string)$mode;
        $GLOBALS['BACKUP_SAFE_FINISHED'] = false;
        if (!empty($GLOBALS['BACKUP_SAFE_REGISTERED'])) return;
        $GLOBALS['BACKUP_SAFE_REGISTERED'] = true;
        register_shutdown_function(function () {
            $last = error_get_last();
            $fatalTypes = array(E_ERROR, E_PARSE, E_CORE_ERROR, E_COMPILE_ERROR, E_USER_ERROR, E_RECOVERABLE_ERROR);
            if (!$last || !in_array((int)$last['type'], $fatalTypes, true)) return;
            $root = isset($GLOBALS['BACKUP_SAFE_ROOT']) ? $GLOBALS['BACKUP_SAFE_ROOT'] : dirname(__DIR__);
            $context = isset($GLOBALS['BACKUP_SAFE_CONTEXT']) ? $GLOBALS['BACKUP_SAFE_CONTEXT'] : 'backup';
            $message = (string)$last['message'].' in '.basename((string)$last['file']).':'.(int)$last['line'];
            backup_safe_write_log($root, $context, $message, null);
            if (!empty($GLOBALS['BACKUP_SAFE_FINISHED'])) return;
            $mode = isset($GLOBALS['BACKUP_SAFE_MODE']) ? $GLOBALS['BACKUP_SAFE_MODE'] : 'html';
            if ($mode === 'json') {
                if (!headers_sent()) {
                    http_response_code(500);
                    header('Content-Type: application/json; charset=utf-8');
                }
                echo json_encode(array('ok'=>false,'error'=>'Modul backup mengalami fatal error. Periksa backup-error.log.'), JSON_UNESCAPED_UNICODE);
                return;
            }
            if (!headers_sent()) http_response_code(200);
            echo '<div style="margin:16px;padding:14px;border:1px solid #fca5a5;border-radius:12px;background:#fef2f2;color:#991b1b">';
            echo '<b>Modul backup mengalami error.</b><br>';
            echo htmlspecialchars($message, ENT_QUOTES, 'UTF-8');
            echo '<br><small>Detail dicatat pada storage/private_backup/backup-error.log atau PHP error_log.</small></div>';
        });
    }

    function backup_safe_finish() {
        $GLOBALS['BACKUP_SAFE_FINISHED'] = true;
    }

    function backup_safe_capture($rootPath, $context, $throwable) {
        $text = backup_safe_error_text($throwable);
        backup_safe_write_log($rootPath, $context, $text, $throwable);
        return $text;
    }

    function backup_safe_render_error($message, $rootPath) {
        $items = array(
            array('PHP', PHP_VERSION),
            array('SAPI', PHP_SAPI),
            array('cURL', function_exists('curl_init') ? 'aktif' : 'tidak aktif'),
            array('OpenSSL', function_exists('openssl_encrypt') ? 'aktif' : 'tidak aktif'),
            array('PDO MySQL', extension_loaded('pdo_mysql') ? 'aktif' : 'tidak aktif'),
            array('open_basedir', (string)ini_get('open_basedir') !== '' ? (string)ini_get('open_basedir') : 'tidak dibatasi'),
            array('Root writable', is_writable((string)$rootPath) ? 'ya' : 'tidak'),
            array('Log', backup_safe_log_path($rootPath))
        );
        echo '<div style="padding:14px;border:1px solid #fca5a5;border-radius:12px;background:#fef2f2;color:#7f1d1d">';
        echo '<h3 style="margin-top:0">Modul backup mengalami error</h3>';
        echo '<p>'.htmlspecialchars((string)$message, ENT_QUOTES, 'UTF-8').'</p>';
        echo '<table style="width:100%;border-collapse:collapse;background:#fff">';
        foreach ($items as $row) {
            echo '<tr><th style="padding:7px;border:1px solid #e5e7eb;text-align:left">'.htmlspecialchars($row[0], ENT_QUOTES, 'UTF-8').'</th>';
            echo '<td style="padding:7px;border:1px solid #e5e7eb;word-break:break-all">'.htmlspecialchars($row[1], ENT_QUOTES, 'UTF-8').'</td></tr>';
        }
        echo '</table><p style="margin-bottom:0"><small>Tidak ada tabel atau data transaksi yang diubah ketika halaman ini dibuka.</small></p></div>';
    }
}
