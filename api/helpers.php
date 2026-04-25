<?php
/**
 * Shared helper untuk semua API endpoint Adena POS Desktop.
 * Include file ini di setiap API endpoint.
 */

require_once __DIR__ . '/../core/db.php';
require_once __DIR__ . '/../core/functions.php';

// ── Response helpers ──────────────────────────────────────────────────────────

function api_json($data, int $status = 200): void {
    http_response_code($status);
    header('Content-Type: application/json; charset=utf-8');
    header('X-Content-Type-Options: nosniff');
    echo json_encode($data, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    exit;
}

function api_ok(array $data = []): void {
    api_json(array_merge(['ok' => true], $data));
}

function api_err(string $message, int $status = 400): void {
    api_json(['ok' => false, 'message' => $message], $status);
}

// ── Device token table ────────────────────────────────────────────────────────

function ensure_device_tokens_table(): void {
    $pdo = db();
    $pdo->exec("
        CREATE TABLE IF NOT EXISTS device_tokens (
            id          INT AUTO_INCREMENT PRIMARY KEY,
            user_id     INT NOT NULL,
            token_hash  CHAR(64) NOT NULL,
            device_name VARCHAR(120),
            created_at  TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            last_used_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            expires_at  TIMESTAMP NOT NULL,
            INDEX (token_hash),
            FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
    ");
}

// ── Auth ──────────────────────────────────────────────────────────────────────

function api_get_bearer_token(): ?string {
    $h = $_SERVER['HTTP_AUTHORIZATION'] ?? $_SERVER['HTTP_X_DEVICE_TOKEN'] ?? '';
    if (preg_match('/^Bearer\s+(.+)$/i', trim($h), $m)) return trim($m[1]);
    // also accept X-Device-Token header directly
    $direct = trim($_SERVER['HTTP_X_DEVICE_TOKEN'] ?? '');
    if ($direct !== '') return $direct;
    return null;
}

function api_verify_token(): array {
    ensure_device_tokens_table();
    $raw = api_get_bearer_token();
    if (!$raw || strlen($raw) < 32) api_err('Token tidak valid.', 401);

    $hash = hash('sha256', $raw);
    $pdo  = db();
    $stmt = $pdo->prepare("
        SELECT dt.id AS token_id, dt.user_id, dt.expires_at,
               u.id, u.username, u.name, u.role AS legacy_role, r.role_key
        FROM   device_tokens dt
        JOIN   users u ON u.id = dt.user_id
        LEFT JOIN roles r ON r.id = u.role_id
        WHERE  dt.token_hash = ?
          AND  dt.expires_at > NOW()
        LIMIT 1
    ");
    $stmt->execute([$hash]);
    $row = $stmt->fetch(PDO::FETCH_ASSOC);
    if (!$row) api_err('Token tidak valid.', 401);

    // Refresh last_used
    $pdo->prepare("UPDATE device_tokens SET last_used_at = NOW() WHERE id = ?")->execute([$row['token_id']]);

    $effectiveRole = trim((string)($row['role_key'] ?: $row['legacy_role']));

    return [
        'id'       => (int)$row['id'],
        'username' => $row['username'],
        'name'     => $row['name'],
        'role'     => $effectiveRole,
    ];
}

// ── CORS (opsional, untuk akses dari app) ─────────────────────────────────────

header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Headers: Authorization, Content-Type, X-Device-Token');
header('Access-Control-Allow-Methods: GET, POST, OPTIONS');
if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') { http_response_code(204); exit; }
