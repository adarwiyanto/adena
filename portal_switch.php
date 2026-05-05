<?php
require_once __DIR__ . '/core/functions.php';
require_once __DIR__ . '/core/auth.php';
require_once __DIR__ . '/core/csrf.php';
require_once __DIR__ . '/core/portal_switcher.php';

start_secure_session();
$u = current_user();
if (!$u) {
  redirect(base_url('login.php'));
}

$back = (string)($_POST['back'] ?? $_SERVER['HTTP_REFERER'] ?? base_url('admin/dashboard.php'));
if ($back === '') $back = base_url('admin/dashboard.php');

try {
  if (strtoupper((string)($_SERVER['REQUEST_METHOD'] ?? 'GET')) !== 'POST') {
    throw new Exception('Metode tidak valid.');
  }

  $token = (string)($_POST['_csrf'] ?? '');
  if (!csrf_verify($token)) {
    throw new Exception('Sesi keamanan kedaluwarsa. Silakan coba lagi.');
  }

  $target = (string)($_POST['portal_target'] ?? '');
  $url = adena_portal_switch($u, $target);
  redirect($url);
} catch (Throwable $e) {
  adena_portal_flash($e->getMessage(), 'error');
  redirect($back);
}
