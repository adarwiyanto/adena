<?php
require_once __DIR__ . '/core/functions.php';
require_once __DIR__ . '/core/auth.php';
require_once __DIR__ . '/core/csrf.php';
require_once __DIR__ . '/core/portal_switcher.php';

start_secure_session();
require_admin();
$u = current_user() ?? [];

if (($_SERVER['REQUEST_METHOD'] ?? 'GET') !== 'POST') {
  redirect(base_url('admin/dashboard.php'));
}

$back = (string)($_POST['back'] ?? base_url('admin/dashboard.php'));
if ($back === '') $back = base_url('admin/dashboard.php');
try {
  if (!csrf_verify((string)($_POST['_csrf'] ?? ''))) {
    throw new Exception('Sesi keamanan tidak valid. Silakan coba lagi.');
  }
  $target = (string)($_POST['portal_target'] ?? '');
  $url = adena_portal_switch($u, $target);
  redirect($url);
} catch (Throwable $e) {
  adena_portal_flash($e->getMessage(), 'error');
  redirect($back);
}
