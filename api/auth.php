<?php
/**
 * POST /api/auth.php  — login, dapatkan device token
 * GET  /api/auth.php  — verifikasi token yang ada
 */
require_once __DIR__ . '/helpers.php';

ensure_device_tokens_table();

$method = $_SERVER['REQUEST_METHOD'];

// ── GET: Verify token ─────────────────────────────────────────────────────────
if ($method === 'GET') {
    $user = api_verify_token();
    api_ok(['user' => $user]);
}

// ── POST: Login ───────────────────────────────────────────────────────────────
if ($method !== 'POST') api_err('Method tidak diizinkan.', 405);

$body = json_decode(file_get_contents('php://input'), true) ?? [];
$username    = trim((string)($body['username']    ?? ''));
$password    = (string)($body['password']    ?? '');
$deviceName  = trim((string)($body['device_name'] ?? 'Adena POS Desktop'));

if ($username === '' || $password === '') {
    api_err('Username dan password wajib diisi.');
}

$pdo  = db();
$stmt = $pdo->prepare("SELECT id, username, name, role, password_hash FROM users WHERE username = ? LIMIT 1");
$stmt->execute([$username]);
$user = $stmt->fetch(PDO::FETCH_ASSOC);

if (!$user || !password_verify($password, (string)$user['password_hash'])) {
    api_err('Username atau password salah.', 401);
}

// Hanya role kasir, admin, manager, owner yang boleh login ke app
$allowedRoles = ['kasir', 'admin', 'manager', 'owner'];
if (!in_array($user['role'], $allowedRoles, true)) {
    api_err('Role Anda tidak memiliki akses ke POS Desktop.', 403);
}

// Buat token baru (hapus token lama dari device yang sama jika deviceName cocok)
$rawToken = bin2hex(random_bytes(32)); // 64-char hex
$hash     = hash('sha256', $rawToken);
$expires  = date('Y-m-d H:i:s', strtotime('+1 year'));

// Hapus token lama untuk user+device ini
$pdo->prepare("DELETE FROM device_tokens WHERE user_id = ? AND device_name = ?")
    ->execute([$user['id'], $deviceName]);

// Insert token baru
$pdo->prepare("
    INSERT INTO device_tokens (user_id, token_hash, device_name, expires_at)
    VALUES (?, ?, ?, ?)
")->execute([$user['id'], $hash, $deviceName, $expires]);

api_ok([
    'token' => $rawToken,
    'user'  => [
        'id'       => (int)$user['id'],
        'username' => $user['username'],
        'name'     => $user['name'],
        'role'     => $user['role'],
    ],
]);
