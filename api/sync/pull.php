<?php
/**
 * GET /api/sync/pull.php
 * Download semua data yang dibutuhkan POS Desktop dari server.
 * Param: ?since=2026-01-01T00:00:00 (ISO, opsional — full sync jika kosong)
 */
require_once __DIR__ . '/../helpers.php';

if ($_SERVER['REQUEST_METHOD'] !== 'GET') api_err('Method tidak diizinkan.', 405);

$user  = api_verify_token();
$pdo   = db();
$since = trim($_GET['since'] ?? '');
$hasFilter = $since !== '' && strtotime($since) !== false;
$sinceParam = $hasFilter ? date('Y-m-d H:i:s', strtotime($since)) : null;

// ── Helper ────────────────────────────────────────────────────────────────────
function rows(PDO $pdo, string $sql, array $params = []): array {
    $s = $pdo->prepare($sql); $s->execute($params); return $s->fetchAll(PDO::FETCH_ASSOC);
}

// ── Products (show_on_pos only) ───────────────────────────────────────────────
$productSql = "SELECT id, name, price, category, image_path,
                      is_favorite, is_best_seller, show_on_pos,
                      track_stock, base_unit, updated_at
               FROM products
               WHERE show_on_pos = 1" .
              ($hasFilter ? " AND updated_at >= ?" : "") .
              " ORDER BY is_favorite DESC, name ASC";
$products = rows($pdo, $productSql, $hasFilter ? [$sinceParam] : []);

// ── Categories ────────────────────────────────────────────────────────────────
$categories = rows($pdo, "SELECT id, name FROM product_categories ORDER BY name");

// ── Customers ─────────────────────────────────────────────────────────────────
$customerSql = "SELECT id, name, phone, email, loyalty_points, loyalty_remainder, updated_at
                FROM customers" .
               ($hasFilter ? " WHERE updated_at >= ?" : "") .
               " ORDER BY name";
$customers = rows($pdo, $customerSql, $hasFilter ? [$sinceParam] : []);

// ── Loyalty rewards ───────────────────────────────────────────────────────────
$loyaltyRewards = rows($pdo,
    "SELECT lr.id, lr.product_id, lr.points_required, p.name AS product_name
     FROM loyalty_rewards lr JOIN products p ON p.id = lr.product_id"
);

// ── Payment methods ───────────────────────────────────────────────────────────
$paymentMethods = [];
try {
    $paymentMethods = rows($pdo,
        "SELECT code, name, is_active, sort_order, requires_bank FROM payment_methods
         WHERE is_active = 1 ORDER BY sort_order, id"
    );
} catch (Throwable $_) {
    // fallback untuk schema server lama tanpa kolom requires_bank
    try {
        $paymentMethods = rows($pdo,
            "SELECT code, name, is_active, sort_order FROM payment_methods
             WHERE is_active = 1 ORDER BY sort_order, id"
        );
    } catch (Throwable $_2) {}
}

// ── QRIS banks ────────────────────────────────────────────────────────────────
$banks = [];
try {
    $banks = rows($pdo,
        "SELECT id, name, sort_order, is_active
         FROM qris_banks
         WHERE is_active = 1
         ORDER BY sort_order, name"
    );
} catch (Throwable $_) {}

// ── Payment channels (opsional, schema baru) ────────────────────────────────
$paymentChannels = [];
try {
    $paymentChannels = rows($pdo,
        "SELECT id, payment_method, channel_name, bank_name, is_active, sort_order
         FROM payment_channels
         WHERE is_active = 1
         ORDER BY sort_order, id"
    );
} catch (Throwable $_) {}

// ── Guides ────────────────────────────────────────────────────────────────────
$guides = [];
try {
    $guides = rows($pdo,
        "SELECT id, name, is_active
         FROM guides
         WHERE is_active = 1
         ORDER BY name"
    );
} catch (Throwable $_) {}

// ── Cashiers/users aktif ─────────────────────────────────────────────────────
$cashiers = [];
try {
    $cashiers = rows($pdo,
        "SELECT id, username, name,
                COALESCE(NULLIF(role,''), 'kasir') AS role,
                1 AS is_active
         FROM users
         ORDER BY name ASC"
    );
} catch (Throwable $_) {}

// ── Store settings ────────────────────────────────────────────────────────────
$settingKeys = [
    'store_name', 'store_subtitle', 'store_address', 'store_phone',
    'store_logo', 'receipt_footer',
    'loyalty_point_value', 'loyalty_remainder_mode',
    'pos_default_opening_cash',
];
$settingsData = [];
foreach ($settingKeys as $k) {
    $settingsData[$k] = setting($k, '');
}

// ── Active shift ──────────────────────────────────────────────────────────────
$activeShift = null;
try {
    $s = $pdo->prepare("SELECT * FROM pos_shifts WHERE status = 'open' LIMIT 1");
    $s->execute(); $activeShift = $s->fetch(PDO::FETCH_ASSOC) ?: null;
} catch (Throwable $_) {}

// ── Cash movements of active shift ───────────────────────────────────────────
$cashMovements = [];
if ($activeShift) {
    try {
        $cashMovements = rows($pdo,
            "SELECT id, shift_id, movement_type, amount, reason, notes,
                    offline_uuid, created_at
             FROM pos_cash_movements WHERE shift_id = ? ORDER BY created_at",
            [$activeShift['id']]
        );
    } catch (Throwable $_) {}
}

// ── Pending landing orders (belanja landing page) ───────────────────────────
$pendingOrders = [];
$pendingOrderItems = [];
try {
    $pendingOrders = rows($pdo, "
      SELECT o.id, o.order_code, o.customer_id, o.status, o.created_at, o.updated_at,
             c.name AS customer_name, COALESCE(c.phone, c.email) AS contact
      FROM orders o
      LEFT JOIN customers c ON c.id = o.customer_id
      WHERE o.status = 'pending'
      ORDER BY o.created_at DESC
      LIMIT 50
    ");
    if (!empty($pendingOrders)) {
        $orderIds = array_map(static fn($row) => (int)$row['id'], $pendingOrders);
        $placeholders = implode(',', array_fill(0, count($orderIds), '?'));
        $pendingOrderItems = rows($pdo, "
          SELECT oi.order_id, oi.product_id, oi.qty, p.name AS product_name
          FROM order_items oi
          LEFT JOIN products p ON p.id = oi.product_id
          WHERE oi.order_id IN ($placeholders)
          ORDER BY oi.order_id ASC, oi.id ASC
        ", $orderIds);
    }
} catch (Throwable $_) {}

api_ok([
    'data' => [
        'synced_at'       => date('c'),
        'user'            => $user,
        'products'        => array_values($products),
        'categories'      => array_values($categories),
        'customers'       => array_values($customers),
        'loyalty_rewards' => array_values($loyaltyRewards),
        'payment_methods' => array_values($paymentMethods),
        'qris_banks'      => array_values($banks),
        'payment_channels' => array_values($paymentChannels),
        'guides'          => array_values($guides),
        'cashiers'        => array_values($cashiers),
        'settings'        => $settingsData,
        'active_shift'    => $activeShift,
        'cash_movements'  => array_values($cashMovements),
        'pending_orders' => array_values($pendingOrders),
        'pending_order_items' => array_values($pendingOrderItems),
    ],
]);
