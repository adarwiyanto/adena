<?php
require_once __DIR__ . '/_common.php';
$method = $_SERVER['REQUEST_METHOD'] ?? 'GET';
if ($method === 'GET') {
  api_verify_token('users.view');
  api_ok(['users'=>api_v1_rows("SELECT id, username, name, role, role_id, created_at FROM users ORDER BY id")]);
}
if ($method === 'POST') {
  api_verify_token('users.sync');
  api_ok(['message'=>'Endpoint sinkron user aktif. Perubahan user tetap harus mengikuti permission web/owner.']);
}
api_err('Method tidak diizinkan.',405);
