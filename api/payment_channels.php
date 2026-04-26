<?php
require_once __DIR__ . '/helpers.php';

if (($_SERVER['REQUEST_METHOD'] ?? 'GET') !== 'GET') {
    api_err('Method tidak diizinkan.', 405);
}

require_api_token();

$pdo = db();
$rows = [];

try {
    $rows = $pdo->query("SELECT id,
                               LOWER(COALESCE(payment_method,'')) AS method,
                               COALESCE(channel_name, bank_name, '') AS name,
                               is_active
                        FROM payment_channels
                        WHERE is_active = 1
                        ORDER BY sort_order, id")->fetchAll(PDO::FETCH_ASSOC);
} catch (Throwable $e) {
    try {
        $rows = $pdo->query("SELECT id,
                                   'qris' AS method,
                                   name,
                                   is_active
                            FROM qris_banks
                            WHERE is_active = 1
                            ORDER BY sort_order, id")->fetchAll(PDO::FETCH_ASSOC);
    } catch (Throwable $e2) {
        $rows = [];
    }
}

$rows = array_values(array_filter($rows, static function ($r) {
    return trim((string)($r['name'] ?? '')) !== '';
}));

api_json($rows);
