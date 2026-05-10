<?php
require_once __DIR__ . '/../../core/db.php';
require_once __DIR__ . '/../../core/functions.php';
require_once __DIR__ . '/../../core/security.php';
require_once __DIR__ . '/../../core/auth.php';
require_once __DIR__ . '/../../core/csrf.php';
require_once __DIR__ . '/../../core/rbac.php';

start_secure_session();
header('Content-Type: application/json; charset=utf-8');

try {
  $me = require_menu_access('settings', 'view');
  $roleKey = (string)(resolve_user_role($me)['role_key'] ?? '');
  if (!in_array($roleKey, ['owner', 'admin'], true)) {
    http_response_code(403);
    echo json_encode(['ok' => false, 'message' => 'Akses ditolak.']);
    exit;
  }
  csrf_check();

  $domain = trim((string)($_POST['remote_base_url'] ?? ''));
  $token = trim((string)($_POST['remote_token'] ?? ''));
  if ($domain === '' || $token === '') {
    throw new RuntimeException('Domain pembuat dan API token wajib diisi.');
  }

  if (!preg_match('~^https?://~i', $domain)) {
    $domain = 'https://' . $domain;
  }
  $domain = rtrim($domain, '/');
  $url = $domain . '/api/auth.php';

  $status = 0;
  $body = '';
  $headers = "Authorization: Bearer " . $token . "\r\nAccept: application/json\r\n";
  $ctx = stream_context_create([
    'http' => [
      'method' => 'GET',
      'header' => $headers,
      'timeout' => 12,
      'ignore_errors' => true,
    ],
    'ssl' => [
      'verify_peer' => true,
      'verify_peer_name' => true,
    ],
  ]);
  $body = @file_get_contents($url, false, $ctx);
  $responseHeaders = $http_response_header ?? [];
  foreach ($responseHeaders as $h) {
    if (preg_match('~^HTTP/\S+\s+(\d+)~', $h, $m)) {
      $status = (int)$m[1];
    }
  }

  if ($body === false || $body === '') {
    throw new RuntimeException('Tidak bisa menghubungi domain pembuat. Periksa domain/SSL/server.');
  }

  $json = json_decode($body, true);
  if (!is_array($json)) {
    throw new RuntimeException('Respons bukan JSON valid. Pastikan domain mengarah ke website Adena yang benar.');
  }
  if ($status < 200 || $status >= 300 || empty($json['ok'])) {
    $msg = (string)($json['message'] ?? 'Token ditolak oleh website pembuat.');
    throw new RuntimeException('Koneksi gagal: ' . $msg);
  }

  echo json_encode([
    'ok' => true,
    'message' => 'Berhasil terhubung ke website pembuat.',
    'domain' => $domain,
    'status' => $status,
    'token_name' => (string)($json['token']['name'] ?? '-'),
    'device_code' => (string)($json['token']['device_code'] ?? '-'),
  ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
} catch (Throwable $e) {
  http_response_code(400);
  echo json_encode(['ok' => false, 'message' => $e->getMessage()], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
}
