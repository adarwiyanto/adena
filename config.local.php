<?php
// config.local.php — upload privat di luar public_html

$homeDir = dirname(__DIR__);
$base = rtrim($homeDir, '/') . '/private_uploads/ketam_isi_adena/';

// Pastikan folder ada
if (!is_dir($base)) {
    @mkdir($base, 0755, true);
}

// Set ENV
putenv('HOPE_UPLOAD_BASE=' . $base);
$_ENV['HOPE_UPLOAD_BASE'] = $base;
$_SERVER['HOPE_UPLOAD_BASE'] = $base;

// Fallback stabil
if (!defined('HOPE_UPLOAD_BASE')) {
    define('HOPE_UPLOAD_BASE', $base);
}