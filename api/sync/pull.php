<?php
/**
 * GET /api/sync/pull.php
 * Download data master untuk POS Desktop.
 */
require_once __DIR__ . '/../helpers.php';
require_once __DIR__ . '/../../core/inventory.php';

if (($_SERVER['REQUEST_METHOD'] ?? 'GET') !== 'GET') {
    api_err('Method tidak diizinkan.', 405);
}

$debugSync = (string)($_SERVER['HTTP_X_DEBUG_SYNC'] ?? '') === '1';
$debugNotes = [];

/**
 * @param mixed $value
 */
function safe_string($value): string {
    if ($value === null) {
        return '';
    }
    return trim((string)$value);
}

/**
 * Parse since dari ISO/date string lalu normalisasi ke UTC MySQL datetime.
 */
function parse_since_param(string $sinceRaw, array &$debugNotes): ?string {
    if ($sinceRaw === '') {
        return null;
    }

    try {
        $dt = new DateTimeImmutable($sinceRaw);
        return $dt->setTimezone(new DateTimeZone('UTC'))->format('Y-m-d H:i:s');
    } catch (Throwable $e) {
        $debugNotes[] = [
            'type' => 'invalid_since',
            'since' => $sinceRaw,
            'message' => $e->getMessage(),
        ];
        return null;
    }
}

/**
 * Eksekusi query aman: jika tabel/kolom tidak ada, kembalikan array kosong.
 */
function safe_rows(PDO $pdo, string $label, string $sql, array $params, array &$debugNotes): array {
    try {
        $stmt = $pdo->prepare($sql);
        $stmt->execute($params);
        return $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
    } catch (Throwable $e) {
        $debugNotes[] = [
            'type' => 'query_failed',
            'label' => $label,
            'error' => $e->getMessage(),
            'sql' => $sql,
            'params' => $params,
        ];
        return [];
    }
}

/**
 * Ambil setting tanpa membuat endpoint gagal.
 */
function safe_setting(PDO $pdo, string $key, array &$debugNotes): string {
    try {
        $stmt = $pdo->prepare("SELECT value FROM settings WHERE `key` = ? LIMIT 1");
        $stmt->execute([$key]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        return isset($row['value']) ? (string)$row['value'] : '';
    } catch (Throwable $e) {
        $debugNotes[] = [
            'type' => 'setting_failed',
            'key' => $key,
            'error' => $e->getMessage(),
        ];
        return '';
    }
}

try {
    $user = api_verify_token();
    $pdo = db();
    ensure_branch_product_prices_table();
    $tokenBranchId = (int)($user['branch_id'] ?? 0);

    $sinceRaw = safe_string($_GET['since'] ?? '');
    $sinceParam = parse_since_param($sinceRaw, $debugNotes);
    $hasFilter = $sinceParam !== null;

    $productParams = [];
    if ($tokenBranchId > 0) {
        $productParams[] = $tokenBranchId;
    }
    if ($hasFilter) {
        $productParams[] = $sinceParam;
    }
    $productPriceExpr = $tokenBranchId > 0
        ? "CASE WHEN bpp.is_active = 1 THEN bpp.price ELSE p.price END"
        : "p.price";
    $productJoin = $tokenBranchId > 0
        ? " LEFT JOIN branch_product_prices bpp ON bpp.product_id = p.id AND bpp.branch_id = ?"
        : "";
    $productSql = "SELECT p.id, p.name, {$productPriceExpr} AS price, p.price AS default_price,
                          p.category, p.category AS category_id, p.image_path,
                          p.is_favorite, p.is_best_seller, p.show_on_pos,
                          p.track_stock, p.base_unit, p.updated_at
                   FROM products p" . $productJoin .
                   " WHERE p.show_on_pos = 1" .
                   ($hasFilter ? " AND p.updated_at >= ?" : "") .
                   " ORDER BY p.is_favorite DESC, p.name ASC";
    $products = safe_rows($pdo, 'products', $productSql, $productParams, $debugNotes);

    $categories = safe_rows(
        $pdo,
        'categories',
        "SELECT id, name, image_path FROM product_categories ORDER BY name",
        [],
        $debugNotes
    );

    $guides = safe_rows(
        $pdo,
        'guides',
        "SELECT id, name, is_active FROM guides WHERE is_active = 1 ORDER BY name",
        [],
        $debugNotes
    );

    $paymentMethods = safe_rows(
        $pdo,
        'payment_methods',
        "SELECT code, name, is_active, sort_order, requires_bank
         FROM payment_methods
         WHERE is_active = 1
         ORDER BY sort_order, id",
        [],
        $debugNotes
    );
    if (empty($paymentMethods)) {
        $paymentMethods = safe_rows(
            $pdo,
            'payment_methods_fallback',
            "SELECT code, name, is_active, sort_order
             FROM payment_methods
             WHERE is_active = 1
             ORDER BY sort_order, id",
            [],
            $debugNotes
        );
    }

    $banks = safe_rows(
        $pdo,
        'banks',
        "SELECT id, name, sort_order, is_active
         FROM qris_banks
         WHERE is_active = 1
         ORDER BY sort_order, name",
        [],
        $debugNotes
    );

    $settingKeys = [
        'store_name', 'store_subtitle', 'store_address', 'store_phone', 'store_logo', 'receipt_footer',
        'loyalty_point_value', 'loyalty_remainder_mode', 'pos_default_opening_cash', 'store_intro',
        'theme_primary', 'theme_secondary', 'theme_accent', 'theme_surface', 'theme_sidebar',
        'theme_header', 'theme_text', 'theme_muted', 'custom_css',
    ];
    $settings = [];
    foreach ($settingKeys as $key) {
        $settings[$key] = safe_setting($pdo, $key, $debugNotes);
    }
    // URL siap pakai untuk POS Desktop. Nilai store_logo biasanya hanya nama file private_uploads.
    $settings['store_logo_url'] = !empty($settings['store_logo']) ? upload_url($settings['store_logo'], 'image') : '';

    $shiftsSql = "SELECT id, shift_code, branch_id, opened_at, opened_by, opening_cash_default,
                         opening_cash_actual, status, closed_at, closed_by, expected_cash_total,
                         counted_cash_total, cash_difference, notes, offline_open_uuid, offline_close_uuid,
                         sync_status, created_at, updated_at
                  FROM pos_shifts" .
                  ($hasFilter ? " WHERE updated_at >= ?" : "") .
                  " ORDER BY id DESC LIMIT 100";
    $shifts = safe_rows($pdo, 'shifts', $shiftsSql, $hasFilter ? [$sinceParam] : [], $debugNotes);

    $salesSql = "SELECT s.id AS web_sale_id, s.transaction_code, s.transaction_group_uuid, s.offline_uuid,
                        s.product_id, s.qty, s.price_each, s.total, s.payment_method, s.payment_bank,
                        s.guide_id, s.guide_name, s.created_by, s.sold_at,
                        u.name AS cashier_name
                 FROM sales s
                 LEFT JOIN users u ON u.id = s.created_by
                 WHERE (s.return_reason IS NULL OR s.return_reason = '')" .
                 ($hasFilter ? " AND s.sold_at >= ?" : "") .
                 " ORDER BY s.sold_at DESC, s.id DESC LIMIT 2000";
    $salesHistory = safe_rows($pdo, 'sales_history', $salesSql, $hasFilter ? [$sinceParam] : [], $debugNotes);

    $pendingOrders = safe_rows(
        $pdo,
        'pending_orders',
        "SELECT id, order_code, customer_id, status, created_at, completed_at, customer_name, customer_contact AS contact,
                customer_address, customer_note, total_amount
         FROM orders
         ORDER BY created_at DESC
         LIMIT 500",
        [],
        $debugNotes
    );

    $pendingOrderItems = safe_rows(
        $pdo,
        'pending_order_items',
        "SELECT id, order_id, product_id, qty, price_each, subtotal, product_name
         FROM order_items
         ORDER BY id DESC
         LIMIT 3000",
        [],
        $debugNotes
    );

    $activeShiftRows = safe_rows(
        $pdo,
        'active_shift',
        "SELECT id, shift_code, branch_id, opened_at, opened_by, opening_cash_default,
                opening_cash_actual, status, closed_at, closed_by, expected_cash_total,
                counted_cash_total, cash_difference, notes, offline_open_uuid, offline_close_uuid,
                sync_status, created_at, updated_at
         FROM pos_shifts
         WHERE status = 'open'
         ORDER BY id DESC
         LIMIT 1",
        [],
        $debugNotes
    );

    $response = [
        'ok' => true,
        'server_time' => gmdate('Y-m-d H:i:s'),
        'data' => [
            'products' => array_values($products),
            'categories' => array_values($categories),
            'guides' => array_values($guides),
            'banks' => array_values($banks),
            'payment_methods' => array_values($paymentMethods),
            'settings' => $settings,
            'shifts' => array_values($shifts),
            'sales_history' => array_values($salesHistory),
            'pending_orders' => array_values($pendingOrders),
            'pending_order_items' => array_values($pendingOrderItems),
            'active_shift' => $activeShiftRows[0] ?? null,
        ],
        'token' => [
            'id' => (int)($user['id'] ?? 0),
            'name' => (string)($user['name'] ?? ''),
            'device_code' => strtoupper(trim((string)($user['device_code'] ?? ''))),
            'branch_id' => $tokenBranchId,
        ],
    ];

    if ($debugSync) {
        $response['debug'] = [
            'since_raw' => $sinceRaw,
            'since_mysql' => $sinceParam,
            'notes' => $debugNotes,
        ];
    }

    api_json($response, 200);
} catch (Throwable $e) {
    $payload = [
        'ok' => false,
        'message' => 'Sync pull failed',
        'error' => $e->getMessage(),
    ];

    if ($debugSync) {
        $payload['debug'] = [
            'exception' => get_class($e),
            'trace' => $e->getTraceAsString(),
            'notes' => $debugNotes,
        ];
    } else {
        $payload['debug'] = 'Aktifkan header X-Debug-Sync: 1 untuk detail error.';
    }

    api_json($payload, 500);
}
