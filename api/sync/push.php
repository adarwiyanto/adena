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
$safeExec = static function (string $sql) use ($pdo): void {
    try { $pdo->exec($sql); } catch (Throwable $_) {}
};
$safeExec("ALTER TABLE sales ADD COLUMN local_device_id VARCHAR(120) NULL");
$safeExec("ALTER TABLE sales ADD COLUMN local_transaction_id VARCHAR(120) NULL");
$safeExec("ALTER TABLE sales ADD COLUMN payment_channel_id BIGINT NULL");
$safeExec("ALTER TABLE sales ADD COLUMN payment_channel_name VARCHAR(120) NULL");
$safeExec("ALTER TABLE sales ADD COLUMN guide_id BIGINT NULL");
$safeExec("ALTER TABLE sales ADD COLUMN customer_id BIGINT NULL");
$safeExec("ALTER TABLE sales ADD COLUMN customer_name VARCHAR(150) NULL");
$safeExec("ALTER TABLE sales ADD COLUMN customer_phone VARCHAR(50) NULL");
$safeExec("ALTER TABLE sales ADD COLUMN payment_summary TEXT NULL");
$safeExec("ALTER TABLE sales ADD KEY idx_sales_device_local (local_device_id, local_transaction_id)");
$safeExec("CREATE TABLE IF NOT EXISTS sale_payments (
  id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  sale_id BIGINT NULL,
  transaction_group_uuid VARCHAR(120) NULL,
  local_transaction_id VARCHAR(120) NULL,
  payment_method VARCHAR(50) NOT NULL,
  payment_bank VARCHAR(120) NULL,
  payment_bank_id BIGINT NULL,
  amount DECIMAL(15,2) NOT NULL DEFAULT 0,
  fee_percent DECIMAL(8,4) NOT NULL DEFAULT 0,
  fee_amount DECIMAL(15,2) NOT NULL DEFAULT 0,
  charged_amount DECIMAL(15,2) NOT NULL DEFAULT 0,
  cash_received DECIMAL(15,2) NULL,
  cash_change DECIMAL(15,2) NULL,
  created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  KEY idx_sale_payments_local_tx (local_transaction_id),
  KEY idx_sale_payments_group (transaction_group_uuid)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");

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
        $paymentSummary = (string)($tx['payment_summary'] ?? '');
        $payments = (array)($tx['payments'] ?? []);
        $customer = is_array($tx['customer'] ?? null) ? $tx['customer'] : [];
        $customerName = trim((string)($customer['name'] ?? $tx['customer_name'] ?? ''));
        $customerPhone = trim((string)($customer['phone'] ?? $tx['customer_phone'] ?? ''));
        if (!$customerId && ($customerName !== '' || $customerPhone !== '')) {
            try {
                if ($customerPhone !== '') {
                    $c = $pdo->prepare("SELECT id FROM customers WHERE phone = ? LIMIT 1");
                    $c->execute([$customerPhone]);
                    $rowC = $c->fetch(PDO::FETCH_ASSOC);
                    if ($rowC) $customerId = (int)$rowC['id'];
                }
                if (!$customerId) {
                    $pdo->prepare("INSERT INTO customers (name, phone, created_at) VALUES (?, ?, NOW())")
                        ->execute([$customerName !== '' ? $customerName : $customerPhone, $customerPhone !== '' ? $customerPhone : null]);
                    $customerId = (int)$pdo->lastInsertId();
                } elseif ($customerName !== '') {
                    $pdo->prepare("UPDATE customers SET name = CASE WHEN name = '' OR name IS NULL THEN ? ELSE name END WHERE id = ?")
                        ->execute([$customerName, $customerId]);
                }
            } catch (Throwable $_) {}
        }

        $firstId = null;
        try {
            foreach ($items as $idx => $item) {
                $itemUuid = $idx === 0 ? $txUuid : null;
                $priceEach = isset($item['price_each']) ? (float)$item['price_each'] : (float)($item['price'] ?? 0);
                $itemTotal = isset($item['total']) ? (float)$item['total'] : (float)($item['subtotal'] ?? 0);

                $pdo->prepare("
                    INSERT INTO sales
                        (transaction_code, transaction_group_uuid, offline_uuid,
                         product_id, qty, price_each, total,
                         discount_amount, discount_type,
                         tx_discount_amount, tx_discount_type,
                         payment_method, payment_bank, payment_channel_id, payment_channel_name, guide_id, guide_name,
                         customer_id, customer_name, customer_phone, payment_summary,
                         local_device_id, local_transaction_id,
                         created_by, shift_id, sold_at,
                         sync_status, original_sale_id,
                         is_active_revision, revision_no, revision_status,
                         base_sale_code)
                    VALUES
                        (?, ?, ?,
                         ?, ?, ?, ?,
                         ?, ?,
                         ?, ?,
                         ?, ?, ?, ?, ?, ?,
                         ?, ?, ?, ?,
                         ?, ?, ?, ?, ?,
                         'synced', ?,
                         1, 0, 'active',
                         ?)
                ")->execute([
                    $txCode, $txGroupUuid, $itemUuid,
                    (int)($item['product_id'] ?? 0),
                    (int)($item['qty'] ?? 1),
                    $priceEach,
                    $itemTotal,
                    (float)($item['discount_amount'] ?? 0),
                    (string)($item['discount_type'] ?? 'fixed'),
                    $txDiscAmt, $txDiscType,
                    $payMethod, $payBank, $payChannelId, $payChannelName, $guideId, $guideName,
                    $customerId ?: null, $customerName ?: null, $customerPhone ?: null, $paymentSummary ?: null,
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

            if (!empty($payments)) {
                try {
                    $pdo->prepare("DELETE FROM sale_payments WHERE local_transaction_id = ?")->execute([$localTxId ?: $txUuid]);
                    $payStmt = $pdo->prepare("INSERT INTO sale_payments
                        (sale_id, transaction_group_uuid, local_transaction_id, payment_method, payment_bank, payment_bank_id, amount, fee_percent, fee_amount, charged_amount, cash_received, cash_change)
                        VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)");
                    foreach ($payments as $pay) {
                        if (!is_array($pay)) continue;
                        $payStmt->execute([
                            $firstId,
                            $txGroupUuid,
                            $localTxId ?: $txUuid,
                            (string)($pay['method'] ?? $pay['payment_method'] ?? ''),
                            (string)($pay['bank_name'] ?? $pay['payment_bank'] ?? ''),
                            !empty($pay['bank_id']) ? (int)$pay['bank_id'] : null,
                            (float)($pay['amount'] ?? 0),
                            (float)($pay['fee_percent'] ?? 0),
                            (float)($pay['fee_amount'] ?? 0),
                            (float)($pay['charged_amount'] ?? $pay['amount'] ?? 0),
                            array_key_exists('cash_received', $pay) ? (float)$pay['cash_received'] : null,
                            array_key_exists('cash_change', $pay) ? (float)$pay['cash_change'] : null,
                        ]);
                    }
                } catch (Throwable $ePay) {
                    if ($debugMode) $debug['payment_insert_error'][$txUuid] = $ePay->getMessage();
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
