<?php
/**
 * POST /api/sync/push.php
 * Upload transaksi, shift, dan cash movements dari POS Desktop ke server.
 *
 * Body JSON:
 * {
 *   "shifts":         [...],
 *   "cash_movements": [...],
 *   "transactions":   [...]
 * }
 */
require_once __DIR__ . '/../helpers.php';

if ($_SERVER['REQUEST_METHOD'] !== 'POST') api_err('Method tidak diizinkan.', 405);

$user = api_verify_token();
$body = json_decode(file_get_contents('php://input'), true);
if (!is_array($body)) api_err('Body JSON tidak valid.');

$pdo = db();
$pdo->beginTransaction();

$results = ['shifts' => [], 'cash_movements' => [], 'transactions' => []];

try {

// ── 1. Shifts ─────────────────────────────────────────────────────────────────
foreach ((array)($body['shifts'] ?? []) as $sh) {
    $uuid = trim((string)($sh['offline_uuid'] ?? ''));
    if ($uuid === '') continue;

    // Cek apakah sudah ada
    $existing = $pdo->prepare("SELECT id FROM pos_shifts WHERE offline_open_uuid = ? LIMIT 1");
    $existing->execute([$uuid]);
    $existRow = $existing->fetch(PDO::FETCH_ASSOC);

    if ($existRow) {
        // Update status jika close
        if (($sh['status'] ?? '') === 'closed' && !empty($sh['offline_close_uuid'])) {
            $pdo->prepare("
                UPDATE pos_shifts
                SET status = 'closed',
                    closed_at = ?,
                    closed_by = ?,
                    counted_cash_total = ?,
                    notes = ?,
                    offline_close_uuid = ?,
                    sync_status = 'synced'
                WHERE id = ?
            ")->execute([
                $sh['closed_at'] ?? date('Y-m-d H:i:s'),
                $user['id'],
                $sh['counted_cash_total'] ?? 0,
                $sh['notes'] ?? '',
                $sh['offline_close_uuid'] ?? null,
                $existRow['id'],
            ]);
        }
        $results['shifts'][$uuid] = (int)$existRow['id'];
        continue;
    }

    // Insert shift baru
    $shiftCode = 'SHIFT-' . date('Ymd-His') . '-' . strtoupper(substr($uuid, 0, 6));
    $pdo->prepare("
        INSERT INTO pos_shifts
            (shift_code, status, opened_at, opened_by,
             opening_cash_actual, offline_open_uuid, sync_status)
        VALUES (?, ?, ?, ?, ?, ?, 'synced')
    ")->execute([
        $shiftCode,
        $sh['status'] ?? 'open',
        $sh['opened_at'] ?? date('Y-m-d H:i:s'),
        $user['id'],
        $sh['opening_cash_actual'] ?? 0,
        $uuid,
    ]);
    $shiftServerId = (int)$pdo->lastInsertId();
    $results['shifts'][$uuid] = $shiftServerId;
}

// ── 2. Cash movements ─────────────────────────────────────────────────────────
foreach ((array)($body['cash_movements'] ?? []) as $cm) {
    $uuid = trim((string)($cm['offline_uuid'] ?? ''));
    if ($uuid === '') continue;

    $existing = $pdo->prepare("SELECT id FROM pos_cash_movements WHERE offline_uuid = ? LIMIT 1");
    $existing->execute([$uuid]);
    if ($existing->fetch()) {
        $results['cash_movements'][$uuid] = 'exists';
        continue;
    }

    // Resolve shift server ID
    $shiftServerId = null;
    $shiftUuid = (string)($cm['shift_offline_uuid'] ?? '');
    if ($shiftUuid !== '') {
        $shiftServerId = $results['shifts'][$shiftUuid] ?? null;
        if (!$shiftServerId) {
            $s = $pdo->prepare("SELECT id FROM pos_shifts WHERE offline_open_uuid = ?");
            $s->execute([$shiftUuid]); $row = $s->fetch(PDO::FETCH_ASSOC);
            $shiftServerId = $row ? (int)$row['id'] : null;
        }
    }

    $pdo->prepare("
        INSERT INTO pos_cash_movements
            (shift_id, movement_type, amount, reason, notes,
             created_by, offline_uuid, sync_status, created_at)
        VALUES (?, ?, ?, ?, ?, ?, ?, 'synced', ?)
    ")->execute([
        $shiftServerId,
        $cm['movement_type'] ?? 'in',
        $cm['amount'] ?? 0,
        $cm['reason'] ?? '',
        $cm['notes'] ?? '',
        $user['id'],
        $uuid,
        $cm['created_at'] ?? date('Y-m-d H:i:s'),
    ]);
    $results['cash_movements'][$uuid] = (int)$pdo->lastInsertId();
}

// ── 3. Transactions ───────────────────────────────────────────────────────────
foreach ((array)($body['transactions'] ?? []) as $tx) {
    $txUuid = trim((string)($tx['offline_uuid'] ?? ''));
    if ($txUuid === '') continue;

    // Cek apakah sudah ada (lewat offline_uuid di sales)
    $existing = $pdo->prepare("SELECT id FROM sales WHERE offline_uuid = ? LIMIT 1");
    $existing->execute([$txUuid]);
    $existRow = $existing->fetch(PDO::FETCH_ASSOC);
    if ($existRow) {
        $results['transactions'][$txUuid] = 'exists';
        continue;
    }

    // Resolve shift server ID
    $shiftServerId = null;
    $shiftUuid = (string)($tx['shift_offline_uuid'] ?? '');
    if ($shiftUuid !== '') {
        $shiftServerId = $results['shifts'][$shiftUuid] ?? null;
        if (!$shiftServerId) {
            $s = $pdo->prepare("SELECT id FROM pos_shifts WHERE offline_open_uuid = ?");
            $s->execute([$shiftUuid]); $row = $s->fetch(PDO::FETCH_ASSOC);
            $shiftServerId = $row ? (int)$row['id'] : null;
        }
    }

    $items = (array)($tx['items'] ?? []);
    if (empty($items)) continue;

    $txCode     = 'TRX-' . date('YmdHis') . '-' . strtoupper(substr($txUuid, 0, 6));
    $txGroupUuid = (string)($tx['transaction_group_uuid'] ?? $txUuid);
    $soldAt     = (string)($tx['sold_at'] ?? date('Y-m-d H:i:s'));
    $payMethod  = (string)($tx['payment_method'] ?? 'cash');
    $payBank    = (string)($tx['payment_bank'] ?? '');
    $guideName  = (string)($tx['guide_name'] ?? '');
    $customerId = !empty($tx['customer_id']) ? (int)$tx['customer_id'] : null;
    $txDiscAmt  = (float)($tx['tx_discount_amount'] ?? 0);
    $txDiscType = (string)($tx['tx_discount_type'] ?? 'fixed');

    $firstId = null;
    foreach ($items as $idx => $item) {
        $itemUuid = $idx === 0 ? $txUuid : null; // solo offline_uuid on first item only
        $pdo->prepare("
            INSERT INTO sales
                (transaction_code, transaction_group_uuid, offline_uuid,
                 product_id, qty, price_each, total,
                 discount_amount, discount_type,
                 tx_discount_amount, tx_discount_type,
                 payment_method, payment_bank, guide_name,
                 created_by, shift_id, sold_at,
                 sync_status, original_sale_id,
                 is_active_revision, revision_no, revision_status,
                 base_sale_code)
            VALUES
                (?, ?, ?,
                 ?, ?, ?, ?,
                 ?, ?,
                 ?, ?,
                 ?, ?, ?,
                 ?, ?, ?,
                 'synced', ?,
                 1, 0, 'active',
                 ?)
        ")->execute([
            $txCode, $txGroupUuid, $itemUuid,
            (int)($item['product_id'] ?? 0),
            (int)($item['qty'] ?? 1),
            (float)($item['price_each'] ?? 0),
            (float)($item['total'] ?? 0),
            (float)($item['discount_amount'] ?? 0),
            (string)($item['discount_type'] ?? 'fixed'),
            $txDiscAmt, $txDiscType,
            $payMethod, $payBank, $guideName,
            $user['id'], $shiftServerId, $soldAt,
            $firstId,
            $txCode,
        ]);

        $newId = (int)$pdo->lastInsertId();
        if ($firstId === null) {
            $firstId = $newId;
            // Set original_sale_id = self untuk baris pertama
            $pdo->prepare("UPDATE sales SET original_sale_id = ? WHERE id = ?")->execute([$newId, $newId]);
        }
    }

    // Update loyalty points jika ada customer
    if ($customerId && $txDiscAmt >= 0) {
        $loyaltyVal = (float)setting('loyalty_point_value', '0');
        if ($loyaltyVal > 0) {
            $total = (float)($tx['total'] ?? 0);
            $pts   = (int)floor($total / $loyaltyVal);
            if ($pts > 0) {
                $pdo->prepare("UPDATE customers SET loyalty_points = loyalty_points + ? WHERE id = ?")
                    ->execute([$pts, $customerId]);
            }
        }
    }

    $results['transactions'][$txUuid] = $txCode;
}

$pdo->commit();

} catch (Throwable $e) {
    $pdo->rollBack();
    api_err('Server error: ' . $e->getMessage(), 500);
}

api_ok(['results' => $results]);
