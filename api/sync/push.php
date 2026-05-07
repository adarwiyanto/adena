<?php
/**
 * POST /api/sync/push.php
 * Upload transaksi, shift, dan cash movements dari POS Desktop ke server.
 */
require_once __DIR__ . '/../helpers.php';

if (($_SERVER['REQUEST_METHOD'] ?? 'GET') !== 'POST') api_err('Method tidak diizinkan.', 405);

$user = api_verify_token();
$body = json_decode(file_get_contents('php://input'), true);
if (!is_array($body)) api_err('Body JSON tidak valid.');

$pdo = db();
ensure_branch_price_schema();
$branchId = branch_for_api_token($user);
$safeExec = static function (string $sql) use ($pdo): void {
    try { $pdo->exec($sql); } catch (Throwable $_) {}
};
$safeExec("ALTER TABLE sales ADD COLUMN branch_id INT NULL AFTER product_id");
$safeExec("ALTER TABLE pos_shifts ADD COLUMN branch_id INT NULL AFTER shift_code");
$safeExec("ALTER TABLE sales ADD COLUMN local_device_id VARCHAR(120) NULL");
$safeExec("ALTER TABLE sales ADD COLUMN local_transaction_id VARCHAR(120) NULL");
$safeExec("ALTER TABLE sales ADD COLUMN payment_channel_id BIGINT NULL");
$safeExec("ALTER TABLE sales ADD COLUMN payment_channel_name VARCHAR(120) NULL");
$safeExec("ALTER TABLE sales ADD COLUMN guide_id BIGINT NULL");
$safeExec("ALTER TABLE sales ADD KEY idx_sales_device_local (local_device_id, local_transaction_id)");

$debugMode = (($_GET['debug'] ?? '0') === '1') || (($_SERVER['HTTP_X_DEBUG_SYNC'] ?? '0') === '1');
$incomingShifts = (array)($body['shifts'] ?? []);
$incomingMovements = (array)($body['cash_movements'] ?? []);
$incomingTransactions = (array)($body['transactions'] ?? []);

if (count($incomingShifts) === 0 && count($incomingMovements) === 0 && count($incomingTransactions) === 0) {
    api_json([
        'ok' => false,
        'message' => 'Payload sync kosong. Tidak ada shifts/cash_movements/transactions.',
        'received_transactions' => 0,
        'inserted_transactions' => 0,
        'duplicate_transactions' => 0,
        'failed_transactions' => 0,
        'errors' => ['payload_empty'],
    ], 422);
}

$results = ['shifts' => [], 'cash_movements' => [], 'transactions' => []];
$summary = ['received' => count($incomingTransactions), 'inserted' => 0, 'failed' => 0, 'exists' => 0];
$debug = [
    'received' => [
        'shifts' => count($incomingShifts),
        'cash_movements' => count($incomingMovements),
        'transactions' => count($incomingTransactions),
    ],
    'offline_uuids' => array_values(array_filter(array_map(static fn($tx) => trim((string)($tx['offline_uuid'] ?? '')), $incomingTransactions))),
    'table' => 'sales',
    'branch_id' => $branchId,
    'insert_results' => [],
    'validation_errors' => [],
];

$pdo->beginTransaction();

try {
    // ── 1. Shifts ─────────────────────────────────────────────────────────────
    foreach ($incomingShifts as $sh) {
        $uuid = trim((string)($sh['offline_uuid'] ?? ''));
        if ($uuid === '') continue;

        $existing = $pdo->prepare("SELECT id FROM pos_shifts WHERE offline_open_uuid = ? LIMIT 1");
        $existing->execute([$uuid]);
        $existRow = $existing->fetch(PDO::FETCH_ASSOC);

        if ($existRow) {
            if (($sh['status'] ?? '') === 'closed' && !empty($sh['offline_close_uuid'])) {
                $pdo->prepare("
                    UPDATE pos_shifts
                    SET status = 'closed',
                        branch_id = COALESCE(branch_id, ?),
                        closed_at = ?,
                        closed_by = ?,
                        counted_cash_total = ?,
                        notes = ?,
                        offline_close_uuid = ?,
                        sync_status = 'synced'
                    WHERE id = ?
                ")->execute([
                    $branchId,
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

        $shiftCode = 'SHIFT-' . date('Ymd-His') . '-' . strtoupper(substr($uuid, 0, 6));
        $pdo->prepare("
            INSERT INTO pos_shifts
                (shift_code, branch_id, status, opened_at, opened_by,
                 opening_cash_actual, offline_open_uuid, sync_status)
            VALUES (?, ?, ?, ?, ?, ?, 'synced')
        ")->execute([
            $shiftCode,
            $branchId,
            $sh['status'] ?? 'open',
            $sh['opened_at'] ?? date('Y-m-d H:i:s'),
            $user['id'],
            $sh['opening_cash_actual'] ?? 0,
            $uuid,
        ]);
        $results['shifts'][$uuid] = (int)$pdo->lastInsertId();
    }

    // ── 2. Cash movements ─────────────────────────────────────────────────────
    foreach ($incomingMovements as $cm) {
        $uuid = trim((string)($cm['offline_uuid'] ?? ''));
        if ($uuid === '') continue;

        $existing = $pdo->prepare("SELECT id FROM pos_cash_movements WHERE offline_uuid = ? LIMIT 1");
        $existing->execute([$uuid]);
        if ($existing->fetch()) {
            $results['cash_movements'][$uuid] = 'exists';
            continue;
        }

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

    // ── 3. Transactions ───────────────────────────────────────────────────────
    foreach ($incomingTransactions as $tx) {
        $txUuid = trim((string)($tx['offline_uuid'] ?? ''));
        $deviceId = trim((string)($tx['local_device_id'] ?? ''));
        $localTxId = trim((string)($tx['local_transaction_id'] ?? $txUuid));
        $validationErrors = [];

        if ($txUuid === '') {
            $validationErrors[] = 'offline_uuid wajib ada';
        }

        $items = (array)($tx['items'] ?? []);
        if (empty($items)) {
            $validationErrors[] = 'items kosong';
        }

        $cashierId  = !empty($tx['user_id']) ? (int)$tx['user_id'] : (int)$user['id'];
        if ($cashierId <= 0) $validationErrors[] = 'user_id/kasir_id wajib ada';

        $payMethod = trim((string)($tx['payment_method'] ?? ''));
        if ($payMethod === '') $validationErrors[] = 'payment_method wajib ada';

        foreach ($items as $idx => $item) {
            $missing = [];
            if (empty($item['product_id'])) $missing[] = 'product_id';
            if (!isset($item['qty'])) $missing[] = 'qty';
            if (!isset($item['price_each']) && !isset($item['price'])) $missing[] = 'price_each/price';
            if (!isset($item['total']) && !isset($item['subtotal'])) $missing[] = 'total/subtotal';
            if ($missing) {
                $validationErrors[] = 'item #' . ($idx + 1) . ' missing: ' . implode(', ', $missing);
            }
        }

        if (!empty($validationErrors)) {
            $key = $txUuid !== '' ? $txUuid : ('missing_uuid_' . uniqid('', true));
            $message = implode('; ', $validationErrors);
            $results['transactions'][$key] = ['status' => 'failed', 'message' => $message];
            $summary['failed']++;
            $debug['validation_errors'][$key] = $validationErrors;
            continue;
        }

        $existing = $pdo->prepare("SELECT id FROM sales WHERE offline_uuid = ? LIMIT 1");
        $existing->execute([$txUuid]);
        if ($existing->fetch(PDO::FETCH_ASSOC)) {
            $results['transactions'][$txUuid] = ['status' => 'exists', 'message' => 'offline_uuid sudah ada'];
            $summary['exists']++;
            continue;
        }

        if ($deviceId !== '' && $localTxId !== '') {
            $dup = $pdo->prepare("SELECT id FROM sales WHERE local_device_id = ? AND local_transaction_id = ? LIMIT 1");
            $dup->execute([$deviceId, $localTxId]);
            if ($dup->fetch(PDO::FETCH_ASSOC)) {
                $results['transactions'][$txUuid] = ['status' => 'exists', 'message' => 'local_transaction_id sudah ada'];
                $summary['exists']++;
                continue;
            }
        }

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

$txCode = trim((string)($tx['transaction_code'] ?? ''));
        if ($txCode === '') {
            $txCode = 'TRX-' . date('YmdHis') . '-' . strtoupper(substr($txUuid, 0, 6));
        }
        $txGroupUuid = (string)($tx['transaction_group_uuid'] ?? $txUuid);
        $soldAt = (string)($tx['sold_at'] ?? date('Y-m-d H:i:s'));
        $payBank = (string)($tx['payment_bank'] ?? '');
        $payChannelId = !empty($tx['payment_channel_id']) ? (int)$tx['payment_channel_id'] : null;
        $payChannelName = (string)($tx['payment_channel_name'] ?? $payBank);
        $guideName = (string)($tx['guide_name'] ?? '');
        $guideId = !empty($tx['guide_id']) ? (int)$tx['guide_id'] : null;
        $customerId = !empty($tx['customer_id']) ? (int)$tx['customer_id'] : null;
        $txDiscAmt = (float)($tx['tx_discount_amount'] ?? 0);
        $txDiscType = (string)($tx['tx_discount_type'] ?? 'fixed');

        $firstId = null;
        try {
            foreach ($items as $idx => $item) {
                $itemUuid = $idx === 0 ? $txUuid : null;
                $priceEach = isset($item['price_each']) ? (float)$item['price_each'] : (float)($item['price'] ?? 0);
                $itemTotal = isset($item['total']) ? (float)$item['total'] : (float)($item['subtotal'] ?? 0);

                $pdo->prepare("
                    INSERT INTO sales
                        (transaction_code, transaction_group_uuid, offline_uuid,
                         product_id, branch_id, qty, price_each, total,
                         discount_amount, discount_type,
                         tx_discount_amount, tx_discount_type,
                         payment_method, payment_bank, payment_channel_id, payment_channel_name, guide_id, guide_name,
                         local_device_id, local_transaction_id,
                         created_by, shift_id, sold_at,
                         sync_status, original_sale_id,
                         is_active_revision, revision_no, revision_status,
                         base_sale_code)
                    VALUES
                        (?, ?, ?,
                         ?, ?, ?, ?, ?,
                         ?, ?,
                         ?, ?,
                         ?, ?, ?, ?, ?, ?,
                         ?, ?, ?, ?, ?,
                         'synced', ?,
                         1, 0, 'active',
                         ?)
                ")->execute([
                    $txCode, $txGroupUuid, $itemUuid,
                    (int)($item['product_id'] ?? 0),
                    $branchId,
                    (int)($item['qty'] ?? 1),
                    $priceEach,
                    $itemTotal,
                    (float)($item['discount_amount'] ?? 0),
                    (string)($item['discount_type'] ?? 'fixed'),
                    $txDiscAmt, $txDiscType,
                    $payMethod, $payBank, $payChannelId, $payChannelName, $guideId, $guideName,
                    $deviceId ?: null, $localTxId ?: $txUuid,
                    $cashierId, $shiftServerId, $soldAt,
                    $firstId,
                    $txCode,
                ]);

                $newId = (int)$pdo->lastInsertId();
                if ($firstId === null) {
                    $firstId = $newId;
                    $pdo->prepare("UPDATE sales SET original_sale_id = ? WHERE id = ?")->execute([$newId, $newId]);
                }
            }

            if ($customerId && $txDiscAmt >= 0) {
                $loyaltyVal = (float)setting('loyalty_point_value', '0');
                if ($loyaltyVal > 0) {
                    $total = (float)($tx['total'] ?? 0);
                    $pts = (int)floor($total / $loyaltyVal);
                    if ($pts > 0) {
                        $pdo->prepare("UPDATE customers SET loyalty_points = loyalty_points + ? WHERE id = ?")
                            ->execute([$pts, $customerId]);
                    }
                }
            }

            $results['transactions'][$txUuid] = ['status' => 'inserted', 'transaction_code' => $txCode];
            $summary['inserted']++;
            $debug['insert_results'][$txUuid] = [
                'status' => 'inserted',
                'transaction_code' => $txCode,
                'items_count' => count($items),
            ];
        } catch (Throwable $eTx) {
            $results['transactions'][$txUuid] = ['status' => 'failed', 'message' => $eTx->getMessage()];
            $summary['failed']++;
            $debug['insert_results'][$txUuid] = [
                'status' => 'failed',
                'error' => $eTx->getMessage(),
                'step' => 'insert_sales',
            ];
            if ($debugMode) {
                $debug['sql_exception'] = $eTx->getMessage();
            }
        }
    }

    $pdo->commit();
} catch (Throwable $e) {
    $pdo->rollBack();
    $payload = [
        'ok' => false,
        'message' => 'Server error: ' . $e->getMessage(),
    ];
    if ($debugMode) {
        $payload['debug'] = [
            'step' => 'push_main',
            'transaction_count' => count($incomingTransactions),
            'table' => 'sales',
    'branch_id' => $branchId,
            'sql_exception' => $e->getMessage(),
            'offline_uuids' => $debug['offline_uuids'],
        ];
    }
    api_json($payload, 500);
}

$response = [
    'ok' => true,
    'results' => $results,
    'summary' => $summary,
    'received_transactions' => (int)$summary['received'],
    'inserted_transactions' => (int)$summary['inserted'],
    'duplicate_transactions' => (int)$summary['exists'],
    'failed_transactions' => (int)$summary['failed'],
    'errors' => array_values(array_filter(array_map(
        static fn($row) => is_array($row) ? ($row['message'] ?? null) : null,
        $results['transactions']
    ))),
];
if ($debugMode) {
    $response['debug'] = $debug;
}
api_json($response);
