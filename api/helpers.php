<?php
/**
 * Shared helper untuk semua API endpoint Adena POS Desktop.
 * Include file ini di setiap API endpoint.
 */

require_once __DIR__ . '/../core/db.php';
require_once __DIR__ . '/../core/functions.php';
require_once __DIR__ . '/../core/single_branch.php';
require_once __DIR__ . '/../core/api_permissions.php';

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

function ensure_api_tokens_table(): void {
    ensure_api_v1_schema();
}

function api_get_bearer_token(): ?string {
    $h = $_SERVER['HTTP_AUTHORIZATION'] ?? '';
    if (preg_match('/^Bearer\s+(.+)$/i', trim($h), $m)) {
        return trim($m[1]);
    }
    return null;
}

function require_api_token(?string $permission = null): array {
    ensure_api_v1_schema();

    $input = api_get_bearer_token();
    if (!$input || strlen($input) < 20) {
        api_log_request(null, (string)($_SERVER['REQUEST_URI'] ?? ''), (string)($_SERVER['REQUEST_METHOD'] ?? 'GET'), $permission, 401, 'missing_or_short_token');
        api_err('API token tidak valid', 401);
    }

    $pdo = db();
    $rows = $pdo->query('SELECT id, name, token_hash, device_code, branch_id, unit_code, client_type, permissions, allowed_ips, is_active FROM api_tokens WHERE is_active = 1 ORDER BY id DESC')
                ->fetchAll(PDO::FETCH_ASSOC);

    foreach ($rows as $row) {
        if (password_verify($input, (string)$row['token_hash'])) {
            $unitCode = strtoupper(trim((string)($row['unit_code'] ?? $row['device_code'] ?? '')));
            $branchId = isset($row['branch_id']) ? (int)$row['branch_id'] : 0;
            if ($branchId <= 0 && $unitCode !== '' && function_exists('adena_branch_id_by_code')) {
                $branchId = adena_branch_id_by_code($unitCode);
            }
            $token = [
                'id' => (int)$row['id'],
                'name' => (string)$row['name'],
                'device_code' => strtoupper(trim((string)($row['device_code'] ?? ''))),
                'unit_code' => $unitCode,
                'branch_id' => $branchId,
                'client_type' => (string)($row['client_type'] ?? 'pos_desktop'),
                'permissions' => (string)($row['permissions'] ?? ''),
                'allowed_ips' => (string)($row['allowed_ips'] ?? ''),
            ];
            if (!api_ip_allowed($token)) {
                api_log_request($token, (string)($_SERVER['REQUEST_URI'] ?? ''), (string)($_SERVER['REQUEST_METHOD'] ?? 'GET'), $permission, 403, 'ip_not_allowed');
                api_err('IP tidak diizinkan untuk token ini', 403);
            }
            if ($permission !== null && $permission !== '' && !api_token_has_permission($token, $permission)) {
                api_log_request($token, (string)($_SERVER['REQUEST_URI'] ?? ''), (string)($_SERVER['REQUEST_METHOD'] ?? 'GET'), $permission, 403, 'permission_denied');
                api_err('Permission API tidak mencukupi: ' . $permission, 403);
            }
            $pdo->prepare('UPDATE api_tokens SET last_used_at = NOW() WHERE id = ?')->execute([(int)$row['id']]);
            api_log_request($token, (string)($_SERVER['REQUEST_URI'] ?? ''), (string)($_SERVER['REQUEST_METHOD'] ?? 'GET'), $permission, 200, 'ok');
            return $token;
        }
    }

    api_log_request(null, (string)($_SERVER['REQUEST_URI'] ?? ''), (string)($_SERVER['REQUEST_METHOD'] ?? 'GET'), $permission, 401, 'token_not_found');
    api_err('API token tidak valid', 401);
}

function api_verify_token(?string $permission = null): array {
    return require_api_token($permission);
}

header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Headers: Authorization, Content-Type, X-Debug-Sync');
header('Access-Control-Allow-Methods: GET, POST, OPTIONS');
if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'OPTIONS') {
    http_response_code(204);
    exit;
}
