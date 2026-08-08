<?php
/**
 * Adena POS authentication.
 *
 * GET  /api/auth.php?ping=1 : public connectivity probe (no credential data).
 * GET  /api/auth.php        : verify an existing device API token.
 * POST /api/auth.php        : authenticate Adena user. Android may bootstrap without
 *                             a preconfigured token; a restricted device token is
 *                             provisioned automatically after valid user credentials.
 * Existing POS Desktop clients that already send Bearer tokens remain compatible.
 */
require_once __DIR__ . '/helpers.php';
require_once __DIR__ . '/../core/rbac.php';
require_once __DIR__ . '/../core/single_branch.php';
adena_enforce_single_branch_schema();

$method = $_SERVER['REQUEST_METHOD'] ?? 'GET';

if ($method === 'GET' && isset($_GET['ping'])) {
    api_ok(['service' => 'adena-pos-api']);
}

if ($method === 'GET') {
    $token = require_api_token();
    api_ok(['token' => $token]);
}

if ($method !== 'POST') {
    api_err('Method tidak diizinkan.', 405);
}

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

$plainDeviceToken = null;
$bearer = api_get_bearer_token();
if ($bearer) {
    // Backward compatible path for POS Desktop / devices that already have a token.
    $token = require_api_token();
} else {
    // Android bootstrap: user credential is the gate. No token is ever typed by user.
    ensure_api_tokens_table();
    $requestedDeviceCode = strtoupper(trim((string)($body['device_code'] ?? '')));
    if ($requestedDeviceCode !== '' && !preg_match('/^[A-Z0-9_-]{3,40}$/', $requestedDeviceCode)) {
        $requestedDeviceCode = '';
    }

    $existing = null;
    if ($requestedDeviceCode !== '') {
        $q = db()->prepare("SELECT id, name, device_code, branch_id, token_plain, api_type, client_type, api_mode, unit_code, remote_base_url
                            FROM api_tokens
                            WHERE is_active=1 AND device_code=? AND client_type='android' AND token_plain IS NOT NULL AND token_plain<>''
                            ORDER BY id DESC LIMIT 1");
        $q->execute([$requestedDeviceCode]);
        $existing = $q->fetch(PDO::FETCH_ASSOC) ?: null;
    }

    if ($existing) {
        $plainDeviceToken = (string)$existing['token_plain'];
        $token = [
            'id' => (int)$existing['id'],
            'name' => (string)$existing['name'],
            'device_code' => (string)$existing['device_code'],
            'branch_id' => isset($existing['branch_id']) ? (int)$existing['branch_id'] : null,
            'api_type' => (string)($existing['api_type'] ?? 'pos'),
            'client_type' => (string)($existing['client_type'] ?? 'android'),
            'api_mode' => (string)($existing['api_mode'] ?? 'sender'),
            'unit_code' => (string)($existing['unit_code'] ?? ''),
            'remote_base_url' => (string)($existing['remote_base_url'] ?? ''),
        ];
        db()->prepare('UPDATE api_tokens SET last_used_at=NOW() WHERE id=?')->execute([(int)$existing['id']]);
    } else {
        $deviceCode = $requestedDeviceCode !== ''
            ? $requestedDeviceCode
            : 'AND-' . strtoupper(substr(bin2hex(random_bytes(6)), 0, 10));
        $plainDeviceToken = 'adena_android_' . bin2hex(random_bytes(32));
        $tokenHash = password_hash($plainDeviceToken, PASSWORD_DEFAULT);
        $name = 'Android POS - ' . substr((string)$u['username'], 0, 60);
        $branchId = adena_single_branch_id();
        $permissions = json_encode(['pos.sync.pull','pos.sync.push','pos.shift','pos.revision','pos.return']);
        db()->prepare("INSERT INTO api_tokens
            (name, token_hash, device_code, branch_id, token_plain, api_type, client_type, api_mode, permissions, is_active, last_used_at, created_at)
            VALUES (?,?,?,?,?,'pos','android','sender',?,1,NOW(),NOW())")
            ->execute([$name, $tokenHash, $deviceCode, $branchId, $plainDeviceToken, $permissions]);
        $token = [
            'id' => (int)db()->lastInsertId(),
            'name' => $name,
            'device_code' => $deviceCode,
            'branch_id' => $branchId,
            'api_type' => 'pos',
            'client_type' => 'android',
            'api_mode' => 'sender',
            'unit_code' => '',
            'remote_base_url' => '',
        ];
    }
}

$token['branch_id'] = adena_single_branch_id();
$token['branch_code'] = adena_single_branch_code();
$token['branch_name'] = adena_single_branch_name();

$response = [
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
];
if ($plainDeviceToken !== null) $response['api_token'] = $plainDeviceToken;
api_ok($response);
