<?php
if (!defined('UPLOAD_BASE')) {
  $localConfig = __DIR__ . '/../config.local.php';
  if (file_exists($localConfig)) {
    require_once $localConfig;
  }

  $envBase = getenv('HOPE_UPLOAD_BASE');
  if (!$envBase && defined('HOPE_UPLOAD_BASE')) {
    $envBase = HOPE_UPLOAD_BASE;
  }
  $base = '';
  $root = realpath(__DIR__ . '/..');
  if ($envBase) {
    $base = $envBase;
  } else {
    $isWindows = strtoupper(substr(PHP_OS_FAMILY, 0, 3)) === 'WIN' || DIRECTORY_SEPARATOR === '\\';
    if ($isWindows) {
      $base = 'C:/xampp/private_uploads/hope/';
    } else {
      $rootPath = $root ? str_replace('\\', '/', $root) : str_replace('\\', '/', __DIR__);
      if (preg_match('~^/home/([^/]+)/public_html(?:/.*)?$~', $rootPath, $matches)) {
        $base = '/home/' . $matches[1] . '/private_uploads/hope/';
      } else {
        $base = $rootPath . '/../private_uploads/hope/';
      }
    }
  }

  define('UPLOAD_BASE', rtrim($base, '/\\') . DIRECTORY_SEPARATOR);
  define('UPLOAD_IMG', UPLOAD_BASE . 'images' . DIRECTORY_SEPARATOR);
  define('UPLOAD_DOC', UPLOAD_BASE . 'docs' . DIRECTORY_SEPARATOR);

  foreach ([UPLOAD_BASE, UPLOAD_IMG, UPLOAD_DOC] as $dir) {
    if (!is_dir($dir)) {
      @mkdir($dir, 0750, true);
    }
  }
}
