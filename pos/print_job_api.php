<?php
require_once __DIR__ . '/../core/db.php';
require_once __DIR__ . '/../core/functions.php';
require_once __DIR__ . '/../core/security.php';

start_secure_session();
ensure_pos_print_jobs_table();
expire_old_pos_print_jobs();

$origin = (string)($_SERVER['HTTP_ORIGIN'] ?? '');
$allowedOrigin = parse_url(base_url(), PHP_URL_SCHEME) . '://' . parse_url(base_url(), PHP_URL_HOST);
if ($origin !== '' && strcasecmp($origin, $allowedOrigin) === 0) {
  header('Access-Control-Allow-Origin: ' . $allowedOrigin);
  header('Vary: Origin');
}
header('Content-Type: application/json; charset=utf-8');
header('X-Content-Type-Options: nosniff');

$action = strtolower(trim((string)($_REQUEST['action'] ?? '')));
$token = trim((string)($_REQUEST['token'] ?? ''));

if ($action === '' || $token === '') {
  http_response_code(400);
  echo json_encode(['ok' => false, 'message' => 'Parameter action/token wajib diisi.']);
  exit;
}

if (!preg_match('/^[A-Za-z0-9\-_]{20,100}$/', $token)) {
  http_response_code(400);
  echo json_encode(['ok' => false, 'message' => 'Token tidak valid.']);
  exit;
}

try {
  if ($action === 'get') {
    $job = get_pos_print_job_by_token($token, ['require_pending' => true]);
    if (!$job) {
      http_response_code(404);
      echo json_encode(['ok' => false, 'message' => 'Print job tidak ditemukan / sudah tidak aktif.']);
      exit;
    }

    http_response_code(200);
    echo json_encode([
      'ok' => true,
      'data' => [
        'token' => $job['job_token'],
        'status' => $job['status'],
        'expires_at' => $job['expires_at'],
        'payload' => $job['payload'],
      ],
    ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    exit;
  }

  if ($action === 'mark_printed') {
    $ok = mark_pos_print_job_printed($token);
    if (!$ok) {
      $job = get_pos_print_job_by_token($token, ['allow_printed' => true]);
      if ($job && (string)$job['status'] === 'printed') {
        http_response_code(200);
        echo json_encode(['ok' => true, 'message' => 'Print job sudah ditandai printed.']);
        exit;
      }
      http_response_code(409);
      echo json_encode(['ok' => false, 'message' => 'Gagal menandai printed (expired/invalid).']);
      exit;
    }

    http_response_code(200);
    echo json_encode(['ok' => true, 'message' => 'Print job berhasil ditandai printed.']);
    exit;
  }

  http_response_code(400);
  echo json_encode(['ok' => false, 'message' => 'Action tidak dikenali.']);
} catch (Throwable $e) {
  http_response_code(500);
  echo json_encode(['ok' => false, 'message' => 'Terjadi kesalahan server.']);
}
