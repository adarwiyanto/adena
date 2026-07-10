<?php
/**
 * API Dapur - ekspor/impor seluruh produk.
 *
 * GET  /api/dapur/products.php
 *      Export semua produk + kategori dalam format JSON.
 *
 * POST /api/dapur/products.php
 *      Import/upsert produk dari payload JSON:
 *      { "products": [ ... ] }
 *      atau langsung array produk.
 *
 * Catatan keamanan:
 * - Endpoint memakai token API existing via Authorization: Bearer <token>.
 * - Tidak mengubah stok/transaksi/POS/print.
 */

require_once __DIR__ . '/../helpers.php';
require_once __DIR__ . '/../../core/inventory.php';

$token = require_api_token();

function dapur_column_names(string $table): array {
    static $cache = [];
    if (isset($cache[$table])) return $cache[$table];
    try {
        $stmt = db()->query('SHOW COLUMNS FROM `' . str_replace('`', '', $table) . '`');
        $cols = [];
        foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
            if (!empty($row['Field'])) $cols[] = (string)$row['Field'];
        }
        $cache[$table] = $cols;
        return $cols;
    } catch (Throwable $e) {
        return [];
    }
}

function dapur_has_table(string $table): bool {
    try {
        $stmt = db()->prepare('SHOW TABLES LIKE ?');
        $stmt->execute([$table]);
        return (bool)$stmt->fetchColumn();
    } catch (Throwable $e) {
        return false;
    }
}

function dapur_clean_scalar($value) {
    if (is_bool($value)) return $value ? 1 : 0;
    if (is_int($value) || is_float($value) || is_string($value) || $value === null) return $value;
    return null;
}

function dapur_normalize_product(array $row, array $productColumns): array {
    $allowed = array_values(array_diff($productColumns, ['id', 'created_at', 'updated_at']));
    $data = [];
    foreach ($allowed as $col) {
        if (array_key_exists($col, $row)) {
            $data[$col] = dapur_clean_scalar($row[$col]);
        }
    }

    $name = trim((string)($data['name'] ?? $row['name'] ?? ''));
    if ($name === '') {
        throw new RuntimeException('Nama produk kosong.');
    }
    $data['name'] = substr($name, 0, 180);

    if (in_array('price', $productColumns, true)) {
        $data['price'] = is_numeric($data['price'] ?? null) ? (float)$data['price'] : 0;
    }
    if (in_array('category', $productColumns, true)) {
        $data['category'] = trim((string)($data['category'] ?? ''));
    }
    if (in_array('product_type', $productColumns, true)) {
        $type = (string)($data['product_type'] ?? 'finished_good');
        if (!in_array($type, ['finished_good', 'raw_material', 'service'], true)) $type = 'finished_good';
        $data['product_type'] = $type;
    }

    $intDefaults = [
        'is_favorite' => 0,
        'is_best_seller' => 0,
        'track_stock' => 1,
        'allow_direct_purchase' => 0,
        'allow_bom' => 0,
        'show_on_pos' => 1,
        'show_on_landing' => 1,
    ];
    foreach ($intDefaults as $col => $default) {
        if (in_array($col, $productColumns, true)) {
            $data[$col] = (int)($data[$col] ?? $default) ? 1 : 0;
        }
    }

    foreach (['base_unit', 'purchase_unit', 'sale_unit'] as $col) {
        if (in_array($col, $productColumns, true)) {
            $val = trim((string)($data[$col] ?? ''));
            $data[$col] = $val !== '' ? substr($val, 0, 50) : 'pcs';
        }
    }
    foreach (['purchase_to_base_factor', 'sale_to_base_factor'] as $col) {
        if (in_array($col, $productColumns, true)) {
            $val = is_numeric($data[$col] ?? null) ? (float)$data[$col] : 1.0;
            $data[$col] = $val > 0 ? $val : 1.0;
        }
    }
    if (in_array('reorder_level', $productColumns, true)) {
        $data['reorder_level'] = is_numeric($data['reorder_level'] ?? null) ? (float)$data['reorder_level'] : 0;
    }

    if (($data['product_type'] ?? '') === 'raw_material') {
        if (in_array('allow_direct_purchase', $productColumns, true)) $data['allow_direct_purchase'] = 1;
        if (in_array('allow_bom', $productColumns, true)) $data['allow_bom'] = 0;
    } elseif (($data['product_type'] ?? '') === 'service') {
        foreach (['track_stock', 'allow_direct_purchase', 'allow_bom'] as $col) {
            if (in_array($col, $productColumns, true)) $data[$col] = 0;
        }
    }

    return $data;
}

function dapur_ensure_category(?string $name): void {
    $name = trim((string)$name);
    if ($name === '') return;
    if (!dapur_has_table('product_categories')) return;
    try {
        $stmt = db()->prepare('INSERT IGNORE INTO product_categories (name) VALUES (?)');
        $stmt->execute([substr($name, 0, 120)]);
    } catch (Throwable $e) {
        // kategori opsional; jangan gagalkan import produk hanya karena kategori gagal dibuat
    }
}

function dapur_find_product_id_by_name(string $name): int {
    $stmt = db()->prepare('SELECT id FROM products WHERE LOWER(name) = LOWER(?) ORDER BY id ASC LIMIT 1');
    $stmt->execute([$name]);
    return (int)($stmt->fetchColumn() ?: 0);
}

try {
    ensure_products_category_column();
    ensure_product_categories_table();
    ensure_products_favorite_column();
    ensure_products_best_seller_column();
    ensure_products_inventory_columns();
    ensure_products_reorder_level_column();
} catch (Throwable $e) {
    // schema additive best-effort; endpoint tetap akan memakai kolom yang tersedia
}

$method = strtoupper((string)($_SERVER['REQUEST_METHOD'] ?? 'GET'));

if ($method === 'GET') {
    $productColumns = dapur_column_names('products');
    if (empty($productColumns)) api_err('Tabel products tidak ditemukan.', 500);

    $select = implode(', ', array_map(static fn($c) => '`' . str_replace('`', '', $c) . '`', $productColumns));
    $products = db()->query('SELECT ' . $select . ' FROM products ORDER BY id ASC')->fetchAll(PDO::FETCH_ASSOC);
    $categories = [];
    if (dapur_has_table('product_categories')) {
        $categories = db()->query('SELECT id, name FROM product_categories ORDER BY name ASC')->fetchAll(PDO::FETCH_ASSOC);
    }

    api_ok([
        'source' => 'api_dapur_products',
        'mode' => 'export_all',
        'generated_at' => date('c'),
        'token_name' => $token['name'] ?? '',
        'total' => count($products),
        'categories' => $categories,
        'products' => $products,
    ]);
}

if ($method === 'POST') {
    $raw = file_get_contents('php://input');
    $payload = json_decode($raw ?: '', true);
    if (!is_array($payload)) api_err('Payload JSON tidak valid.', 400);

    $items = $payload['products'] ?? $payload;
    if (!is_array($items)) api_err('Payload wajib berisi array products.', 400);

    $productColumns = dapur_column_names('products');
    if (empty($productColumns)) api_err('Tabel products tidak ditemukan.', 500);

    $pdo = db();
    $pdo->beginTransaction();
    $created = 0;
    $updated = 0;
    $skipped = 0;
    $errors = [];

    foreach ($items as $idx => $item) {
        if (!is_array($item)) {
            $skipped++;
            $errors[] = ['index' => $idx, 'message' => 'Item bukan object.'];
            continue;
        }
        try {
            $data = dapur_normalize_product($item, $productColumns);
            dapur_ensure_category($data['category'] ?? '');

            $id = dapur_find_product_id_by_name((string)$data['name']);
            if ($id > 0) {
                if (in_array('updated_at', $productColumns, true)) {
                    $data['updated_at'] = date('Y-m-d H:i:s');
                }
                $sets = [];
                $values = [];
                foreach ($data as $col => $value) {
                    if ($col === 'id' || !in_array($col, $productColumns, true)) continue;
                    $sets[] = '`' . str_replace('`', '', $col) . '` = ?';
                    $values[] = $value;
                }
                if (!empty($sets)) {
                    $values[] = $id;
                    $stmt = $pdo->prepare('UPDATE products SET ' . implode(', ', $sets) . ' WHERE id = ?');
                    $stmt->execute($values);
                }
                $updated++;
            } else {
                if (in_array('created_at', $productColumns, true) && !array_key_exists('created_at', $data)) {
                    $data['created_at'] = date('Y-m-d H:i:s');
                }
                if (in_array('updated_at', $productColumns, true) && !array_key_exists('updated_at', $data)) {
                    $data['updated_at'] = date('Y-m-d H:i:s');
                }
                $cols = [];
                $marks = [];
                $values = [];
                foreach ($data as $col => $value) {
                    if ($col === 'id' || !in_array($col, $productColumns, true)) continue;
                    $cols[] = '`' . str_replace('`', '', $col) . '`';
                    $marks[] = '?';
                    $values[] = $value;
                }
                $stmt = $pdo->prepare('INSERT INTO products (' . implode(', ', $cols) . ') VALUES (' . implode(', ', $marks) . ')');
                $stmt->execute($values);
                $created++;
            }
        } catch (Throwable $e) {
            $skipped++;
            $errors[] = ['index' => $idx, 'name' => (string)($item['name'] ?? ''), 'message' => $e->getMessage()];
        }
    }

    $pdo->commit();
    api_ok([
        'source' => 'api_dapur_products',
        'mode' => 'import_all',
        'created' => $created,
        'updated' => $updated,
        'skipped' => $skipped,
        'errors' => $errors,
    ]);
}

api_err('Method tidak didukung.', 405);
