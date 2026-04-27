<?php
/**
 * GET /api/auth.php  — verifikasi API token desktop.
 * POST /api/auth.php — login user kasir (tetap butuh device API token valid).
 */
require_once __DIR__ . '/helpers.php';
require_once __DIR__ . '/../core/rbac.php';

if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'GET') {
    $token = require_api_token();
    api_ok(['token' => $token]);
}

if (($_SERVER['REQUEST_METHOD'] ?? 'GET') !== 'POST') {
    api_err('Method tidak diizinkan.', 405);
}

$token = require_api_token();
$body = json_decode(file_get_contents('php://input'), true);
if (!is_array($body)) api_err('Body JSON tidak valid.');

$username = trim((string)($body['username'] ?? ''));
$password = (string)($body['password'] ?? '');
if ($username === '' || $password === '') api_err('Username dan password wajib diisi.', 422);

ensure_rbac_schema();
$stmt = db()->prepare("
  SELECT u.id, u.username, u.name, u.avatar_path, u.password_hash, u.role, u.role_id,
         r.role_key, r.role_name
  FROM users u
  LEFT JOIN roles r ON r.id = u.role_id
  WHERE u.username = ?
  LIMIT 1
");
$stmt->execute([$username]);
$u = $stmt->fetch(PDO::FETCH_ASSOC);
if (!$u) api_err('Username atau password salah.', 401);

$hash = (string)($u['password_hash'] ?? '');
$verified = password_verify($password, $hash);
if (!$verified) {
    $legacyMatch = ($hash !== '') && (
        (strlen($hash) === 32 && hash_equals($hash, md5($password))) ||
        (strlen($hash) === 40 && hash_equals($hash, sha1($password))) ||
        hash_equals($hash, $password)
    );
    if (!$legacyMatch) api_err('Username atau password salah.', 401);
}

$roleKey = (string)($u['role_key'] ?? $u['role'] ?? '');
if ($roleKey === '') $roleKey = 'kasir';
api_ok([
    'user' => [
        'id' => (int)$u['id'],
        'username' => (string)$u['username'],
        'name' => (string)($u['name'] ?? $u['username']),
        'role' => $roleKey,
        'role_name' => (string)($u['role_name'] ?? $roleKey),
        'avatar_path' => (string)($u['avatar_path'] ?? ''),
        'avatar_url' => !empty($u['avatar_path']) ? upload_url($u['avatar_path'], 'image') : '',
    ],
    'token' => $token,
]);
