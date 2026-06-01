<?php
/**
 * Shared helper untuk semua API endpoint Adena POS Desktop / cabang.
 * Hotfix v2.1: migration defensif untuk server cPanel/MariaDB lama.
 */

require_once __DIR__ . '/../core/db.php';
require_once __DIR__ . '/../core/functions.php';

function api_json($data, int $status = 200): void {
    http_response_code($status);
    header('Content-Type: application/json; charset=utf-8');
    header('X-Content-Type-Options: nosniff');
    echo json_encode($data, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    exit;
}
function api_ok(array $data = []): void { api_json(array_merge(['ok' => true], $data)); }
function api_err(string $message, int $status = 400): void { api_json(['ok' => false, 'message' => $message], $status); }
function api_try_exec(string $sql): void { try { db()->exec($sql); } catch (Throwable $e) {} }
function api_table_exists_schema(string $table): bool {
    try {
        $stmt = db()->prepare('SELECT COUNT(*) FROM information_schema.TABLES WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME=?');
        $stmt->execute([$table]);
        return (int)$stmt->fetchColumn() > 0;
    } catch (Throwable $e) { return false; }
}
function api_column_exists_schema(string $table, string $column): bool {
    try {
        $stmt = db()->prepare('SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME=? AND COLUMN_NAME=?');
        $stmt->execute([$table, $column]);
        return (int)$stmt->fetchColumn() > 0;
    } catch (Throwable $e) { return false; }
}
function api_add_column_if_missing(string $table, string $column, string $definition): void {
    if (!api_column_exists_schema($table, $column)) api_try_exec("ALTER TABLE `$table` ADD COLUMN $definition");
}
function api_add_index_if_missing(string $table, string $indexName, string $definition): void {
    try {
        $stmt = db()->prepare('SELECT COUNT(*) FROM information_schema.STATISTICS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME=? AND INDEX_NAME=?');
        $stmt->execute([$table, $indexName]);
        if ((int)$stmt->fetchColumn() === 0) api_try_exec("ALTER TABLE `$table` ADD $definition");
    } catch (Throwable $e) {}
}

function ensure_api_tokens_table(): void {
    $pdo = db();
    $pdo->exec("CREATE TABLE IF NOT EXISTS api_tokens (
        id INT AUTO_INCREMENT PRIMARY KEY,
        name VARCHAR(100) NOT NULL,
        token_hash VARCHAR(255) NOT NULL,
        device_code VARCHAR(40) NULL,
        is_active TINYINT(1) NOT NULL DEFAULT 1,
        last_used_at DATETIME NULL,
        created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
        revoked_at DATETIME NULL,
        INDEX idx_api_tokens_active (is_active)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");

    if (api_column_exists_schema('api_tokens', 'device_code')) api_try_exec("ALTER TABLE api_tokens MODIFY device_code VARCHAR(40) NULL");
    else api_add_column_if_missing('api_tokens', 'device_code', 'device_code VARCHAR(40) NULL AFTER token_hash');
    api_add_column_if_missing('api_tokens', 'branch_id', 'branch_id INT NULL AFTER device_code');
    api_add_column_if_missing('api_tokens', 'token_plain', 'token_plain TEXT NULL AFTER branch_id');
    api_add_column_if_missing('api_tokens', 'api_type', 'api_type VARCHAR(50) NULL AFTER token_plain');
    api_add_column_if_missing('api_tokens', 'client_type', 'client_type VARCHAR(30) NULL AFTER api_type');
    api_add_column_if_missing('api_tokens', 'unit_code', 'unit_code VARCHAR(40) NULL AFTER client_type');
    api_add_column_if_missing('api_tokens', 'remote_base_url', 'remote_base_url VARCHAR(255) NULL AFTER unit_code');
    api_add_column_if_missing('api_tokens', 'remote_token', 'remote_token TEXT NULL AFTER remote_base_url');
    api_add_column_if_missing('api_tokens', 'permissions', 'permissions TEXT NULL AFTER remote_token');
    api_add_column_if_missing('api_tokens', 'allowed_ips', 'allowed_ips TEXT NULL AFTER permissions');
    api_add_column_if_missing('api_tokens', 'notes', 'notes TEXT NULL AFTER allowed_ips');
    api_add_index_if_missing('api_tokens', 'idx_api_tokens_device', 'INDEX idx_api_tokens_device (device_code)');
    api_add_index_if_missing('api_tokens', 'idx_api_tokens_unit', 'INDEX idx_api_tokens_unit (unit_code)');
    api_add_index_if_missing('api_tokens', 'idx_api_tokens_branch_id', 'INDEX idx_api_tokens_branch_id (branch_id)');

    $pdo->exec("CREATE TABLE IF NOT EXISTS api_token_permissions (
        id INT AUTO_INCREMENT PRIMARY KEY,
        token_id INT NOT NULL,
        permission_key VARCHAR(80) NOT NULL,
        is_allowed TINYINT(1) NOT NULL DEFAULT 1,
        created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
        UNIQUE KEY uniq_api_token_permission (token_id, permission_key),
        KEY idx_api_token_permissions_token (token_id)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");

    $pdo->exec("CREATE TABLE IF NOT EXISTS api_logs (
        id BIGINT AUTO_INCREMENT PRIMARY KEY,
        token_id INT NULL,
        token_name VARCHAR(120) NULL,
        endpoint VARCHAR(255) NOT NULL,
        method VARCHAR(12) NOT NULL,
        permission_key VARCHAR(80) NULL,
        status_code INT NULL,
        ip_address VARCHAR(64) NULL,
        message VARCHAR(255) NULL,
        created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
        KEY idx_api_logs_created (created_at),
        KEY idx_api_logs_token (token_id),
        KEY idx_api_logs_endpoint (endpoint)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
}

function api_get_bearer_token(): ?string {
    $h = $_SERVER['HTTP_AUTHORIZATION'] ?? '';
    if ($h === '' && function_exists('getallheaders')) {
        $headers = getallheaders();
        $h = $headers['Authorization'] ?? $headers['authorization'] ?? '';
    }
    if (preg_match('/^Bearer\s+(.+)$/i', trim((string)$h), $m)) return trim($m[1]);
    return null;
}
function api_client_ip(): string {
    foreach (['HTTP_CF_CONNECTING_IP','HTTP_X_FORWARDED_FOR','REMOTE_ADDR'] as $k) {
        $v = trim((string)($_SERVER[$k] ?? ''));
        if ($v !== '') return trim(explode(',', $v)[0]);
    }
    return '';
}
function api_log(?array $token, ?string $permissionKey, int $statusCode, string $message = ''): void {
    try {
        ensure_api_tokens_table();
        db()->prepare('INSERT INTO api_logs (token_id, token_name, endpoint, method, permission_key, status_code, ip_address, message, created_at) VALUES (?,?,?,?,?,?,?,?,NOW())')
            ->execute([$token['id'] ?? null, $token['name'] ?? null, substr((string)($_SERVER['REQUEST_URI'] ?? ''),0,255), substr((string)($_SERVER['REQUEST_METHOD'] ?? 'GET'),0,12), $permissionKey, $statusCode, api_client_ip(), substr($message,0,255)]);
    } catch (Throwable $e) {}
}
function api_allowed_ip(?string $allowedIps): bool {
    $allowedIps = trim((string)$allowedIps);
    if ($allowedIps === '') return true;
    $ip = api_client_ip();
    $items = preg_split('/[\r\n,]+/', $allowedIps) ?: [];
    foreach ($items as $item) if (trim($item) !== '' && trim($item) === $ip) return true;
    return false;
}
function api_token_has_permission(int $tokenId, string $permissionKey): bool {
    if ($permissionKey === '') return true;
    try {
        $stmt = db()->prepare('SELECT is_allowed FROM api_token_permissions WHERE token_id=? AND permission_key=? LIMIT 1');
        $stmt->execute([$tokenId, $permissionKey]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        return !$row || (int)$row['is_allowed'] === 1;
    } catch (Throwable $e) { return true; }
}
function require_api_token(?string $permissionKey = null): array {
    ensure_api_tokens_table();
    $input = api_get_bearer_token();
    if (!$input || strlen($input) < 20) { api_log(null, $permissionKey, 401, 'Token kosong/tidak valid'); api_err('API token tidak valid', 401); }
    $rows = db()->query('SELECT id, name, token_hash, device_code, branch_id, api_type, unit_code, allowed_ips FROM api_tokens WHERE is_active = 1 ORDER BY id DESC')->fetchAll(PDO::FETCH_ASSOC);
    foreach ($rows as $row) {
        if (password_verify($input, (string)$row['token_hash'])) {
            $token = ['id'=>(int)$row['id'], 'name'=>(string)$row['name'], 'device_code'=>strtoupper(trim((string)($row['device_code'] ?? ''))), 'branch_id'=>isset($row['branch_id'])?(int)$row['branch_id']:null, 'api_type'=>(string)($row['api_type'] ?? ''), 'unit_code'=>strtoupper(trim((string)($row['unit_code'] ?? '')))];
            if (!api_allowed_ip($row['allowed_ips'] ?? null)) { api_log($token, $permissionKey, 403, 'IP tidak diizinkan'); api_err('IP tidak diizinkan', 403); }
            if ($permissionKey && !api_token_has_permission((int)$row['id'], $permissionKey)) { api_log($token, $permissionKey, 403, 'Permission ditolak'); api_err('Permission API ditolak', 403); }
            db()->prepare('UPDATE api_tokens SET last_used_at = NOW() WHERE id = ?')->execute([(int)$row['id']]);
            api_log($token, $permissionKey, 200, 'OK');
            return $token;
        }
    }
    api_log(null, $permissionKey, 401, 'Token tidak cocok');
    api_err('API token tidak valid', 401);
}
function api_verify_token(?string $permissionKey = null): array { return require_api_token($permissionKey); }

header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Headers: Authorization, Content-Type, X-Debug-Sync');
header('Access-Control-Allow-Methods: GET, POST, OPTIONS');
if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'OPTIONS') { http_response_code(204); exit; }
