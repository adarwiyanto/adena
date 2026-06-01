<?php
// config.local.php — konfigurasi lokal hasil installer.
// Mengatur upload privat tanpa membawa data dari dump database lama.

$base = 'C:/xampp/htdocs/private_uploads/ketam_isi_adena/';

if (!is_dir($base)) {
    @mkdir($base, 0755, true);
}

foreach (['images', 'docs'] as $sub) {
    $dir = rtrim($base, '/') . '/' . $sub . '/';
    if (!is_dir($dir)) {
        @mkdir($dir, 0755, true);
    }
}

putenv('HOPE_UPLOAD_BASE=' . $base);
$_ENV['HOPE_UPLOAD_BASE'] = $base;
$_SERVER['HOPE_UPLOAD_BASE'] = $base;

if (!defined('HOPE_UPLOAD_BASE')) {
    define('HOPE_UPLOAD_BASE', $base);
}