<?php
/**
 * Shared helper untuk semua API endpoint Adena POS Desktop.
 * Include file ini di setiap API endpoint.
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

function api_ok(array $data = []): void {
    api_json(array_merge(['ok' => true], $data));
}

function api_err(string $message, int $status = 400): void {
    api_json(['ok' => false, 'message' => $message], $status);
}

function ensure_api_tokens_table(): void {
    $pdo = db();
    $pdo->exec("CREATE TABLE IF NOT EXISTS api_tokens (
        id INT AUTO_INCREMENT PRIMARY KEY,
        name VARCHAR(100) NOT NULL,
        token_hash VARCHAR(255) NOT NULL,
        is_active TINYINT(1) NOT NULL DEFAULT 1,
        last_used_at DATETIME NULL,
        created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
        revoked_at DATETIME NULL,
        INDEX idx_api_tokens_active (is_active)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
}

function api_get_bearer_token(): ?string {
    $h = $_SERVER['HTTP_AUTHORIZATION'] ?? '';
    if (preg_match('/^Bearer\s+(.+)$/i', trim($h), $m)) {
        return trim($m[1]);
    }
    return null;
}

function require_api_token(): array {
    ensure_api_tokens_table();

    $input = api_get_bearer_token();
    if (!$input || strlen($input) < 20) {
        api_err('API token tidak valid', 401);
    }

    $pdo = db();
    $rows = $pdo->query('SELECT id, name, token_hash FROM api_tokens WHERE is_active = 1 ORDER BY id DESC')
                ->fetchAll(PDO::FETCH_ASSOC);

    foreach ($rows as $row) {
        if (password_verify($input, (string)$row['token_hash'])) {
            $pdo->prepare('UPDATE api_tokens SET last_used_at = NOW() WHERE id = ?')->execute([(int)$row['id']]);
            return [
                'id' => (int)$row['id'],
                'name' => (string)$row['name'],
            ];
        }
    }

    api_err('API token tidak valid', 401);
}

function api_verify_token(): array {
    return require_api_token();
}

header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Headers: Authorization, Content-Type');
header('Access-Control-Allow-Methods: GET, POST, OPTIONS');
if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'OPTIONS') {
    http_response_code(204);
    exit;
}
