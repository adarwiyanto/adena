<?php
require_once __DIR__ . '/../../core/db.php';
require_once __DIR__ . '/../../core/functions.php';
require_once __DIR__ . '/../../core/security.php';
require_once __DIR__ . '/../../core/auth.php';
require_once __DIR__ . '/../../core/csrf.php';
require_once __DIR__ . '/../../core/rbac.php';
require_once __DIR__ . '/../../api/helpers.php';

header('Content-Type: application/json; charset=utf-8');

start_secure_session();
require_login();
$me = require_menu_access('settings', 'view');

$roleKey = (string)(resolve_user_role($me)['role_key'] ?? '');
$legacyRole = (string)($me['role'] ?? '');
if (!in_array($roleKey, ['owner', 'admin'], true) && $legacyRole !== 'superadmin') {
  http_response_code(403);
  echo json_encode(['ok' => false, 'message' => 'Akses ditolak.']);
  exit;
}

if (($_SERVER['REQUEST_METHOD'] ?? 'GET') !== 'POST') {
  http_response_code(405);
  echo json_encode(['ok' => false, 'message' => 'Method tidak diizinkan.']);
  exit;
}

try {
  csrf_check();
  ensure_api_tokens_table();

  $id = filter_input(INPUT_POST, 'id', FILTER_VALIDATE_INT);
  if (!$id || $id <= 0) {
    echo json_encode(['ok' => false, 'message' => 'ID token tidak valid.']);
    exit;
  }

  $stmt = db()->prepare('SELECT id FROM api_tokens WHERE id = ? LIMIT 1');
  $stmt->execute([$id]);
  $row = $stmt->fetch(PDO::FETCH_ASSOC);

  if (!$row) {
    echo json_encode(['ok' => false, 'message' => 'Token tidak ditemukan.']);
    exit;
  }

  db()->prepare('DELETE FROM api_tokens WHERE id = ?')->execute([$id]);
  error_log('POS token deleted id=' . (int)$id . ' by user=' . (int)($_SESSION['user_id'] ?? 0));

  echo json_encode(['ok' => true, 'message' => 'Token berhasil dihapus.']);
} catch (Throwable $e) {
  http_response_code(400);
  echo json_encode(['ok' => false, 'message' => 'Gagal menghapus token.']);
}
