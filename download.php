<?php
require_once __DIR__ . '/core/auth.php';
require_once __DIR__ . '/core/customer_auth.php';
require_once __DIR__ . '/core/attendance.php';
require_once __DIR__ . '/config/upload.php';

start_session();
customer_bootstrap_from_cookie();

$type = $_GET['type'] ?? 'image';
if (!in_array($type, ['image', 'doc', 'attendance'], true)) {
  $type = 'image';
}

if ($type === 'doc' && empty($_SESSION['user']) && empty($_SESSION['customer'])) {
  http_response_code(403);
  exit('Forbidden');
}
if ($type === 'attendance' && empty($_SESSION['user'])) {
  http_response_code(403);
  exit('Forbidden');
}

$file = basename((string)($_GET['f'] ?? ''));
if ($file === '' || $file === '.' || $file === '..') {
  http_response_code(400);
  exit('Invalid file');
}

if ($type === 'attendance') {
  $pathRel = str_replace('..', '', (string)($_GET['f'] ?? ''));
  if (!preg_match('#^attendance/\d{4}/\d{2}/user_\d+_\d{8}_(in|out)_[a-f0-9]+\.jpg$#i', $pathRel)) {
    http_response_code(404);
    exit('Not found');
  }
  $uid = 0;
  if (preg_match('/user_(\d+)_/', $pathRel, $m)) {
    $uid = (int)$m[1];
  }
  $me = current_user();
  if ((int)($me['id'] ?? 0) !== $uid && !in_array($me['role'] ?? '', ['admin', 'owner'], true)) {
    http_response_code(403);
    exit('Forbidden');
  }
  $path = UPLOAD_BASE . str_replace('/', DIRECTORY_SEPARATOR, $pathRel);
} else {
  $pattern = $type === 'doc'
    ? '/^[a-f0-9]{32}\.(pdf)$/i'
    : '/^[a-f0-9]{32}\.(jpe?g|png)$/i';
  if (!preg_match($pattern, $file)) {
    http_response_code(404);
    exit('Not found');
  }
  $baseDir = $type === 'doc' ? UPLOAD_DOC : UPLOAD_IMG;
  $path = $baseDir . $file;
}

if (!is_file($path)) {
  http_response_code(404);
  exit('Not found');
}

$mime = mime_content_type($path) ?: 'application/octet-stream';
header('Content-Type: ' . $mime);
header('Content-Length: ' . filesize($path));
header('X-Content-Type-Options: nosniff');
header('Cache-Control: private, max-age=3600');
if ($type === 'doc') {
  header('Content-Disposition: inline; filename="' . $file . '"');
}

readfile($path);
exit;
