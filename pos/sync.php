<?php
require_once __DIR__ . '/../core/db.php';
require_once __DIR__ . '/../core/functions.php';
require_once __DIR__ . '/../core/security.php';
require_once __DIR__ . '/../core/auth.php';
require_once __DIR__ . '/../core/csrf.php';
require_once __DIR__ . '/../core/rbac.php';
require_once __DIR__ . '/../core/pos_shift.php';
require_once __DIR__ . '/../core/ops14.php';

header('Content-Type: application/json');

if (!function_exists('pos_safe_branch_id')) {
  function pos_safe_branch_id(): int {
    if (function_exists('active_branch_id')) {
      $id = (int)active_branch_id();
      if ($id > 0) return $id;
    }
    return 1;
  }
}

start_secure_session();
require_login();
ensure_rbac_schema();
ensure_pos_shift_schema();
ensure_adena14_schema();
ensure_payment_methods_table();
ensure_qris_banks_table();
ensure_sales_payment_bank_column();
$me = current_user();
$me = require_menu_access('pos', 'create');

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
  http_response_code(405);
  echo json_encode(['ok' => false, 'error' => 'Method not allowed']);
  exit;
}

try {
  csrf_check();
  $raw = file_get_contents('php://input');
  $body = json_decode($raw, true);
  if (!is_array($body)) throw new Exception('Payload JSON tidak valid.');

  $items = $body['items'] ?? [];
  if (!is_array($items) || empty($items)) throw new Exception('Queue item kosong.');

  $results = [];
  $branchId = pos_safe_branch_id();

  foreach ($items as $item) {
    $entity = (string)($item['entity_type'] ?? '');
    $offlineUuid = trim((string)($item['offline_uuid'] ?? ''));
    $payload = (array)($item['payload'] ?? []);

    if ($entity === '' || $offlineUuid === '') {
      $results[] = ['offline_uuid' => $offlineUuid, 'status' => 'failed', 'message' => 'entity_type/offline_uuid wajib'];
      continue;
    }

    if (pos_sync_is_duplicate($offlineUuid)) {
      pos_sync_log($entity, $offlineUuid, $payload, 'duplicate', 'Duplicate-safe (already processed)', (int)$me['id']);
      $results[] = ['offline_uuid' => $offlineUuid, 'status' => 'duplicate', 'message' => 'Duplicate-safe'];
      continue;
    }

    try {
      if ($entity === 'shift_open') {
        $opening = (float)($payload['opening_cash_actual'] ?? 0);
        $shift = pos_shift_open((int)$me['id'], $opening, $offlineUuid, $branchId);
        pos_sync_log($entity, $offlineUuid, $payload, 'success', 'Shift opened', (int)$me['id']);
        $results[] = ['offline_uuid' => $offlineUuid, 'status' => 'success', 'shift_id' => (int)$shift['id']];
        continue;
      }

      if ($entity === 'cash_movement') {
        $active = pos_shift_get_active($branchId);
        if (!$active) throw new Exception('Tidak ada shift aktif untuk kas masuk/keluar');
        $row = pos_cash_movement_record((int)$active['id'], (int)$me['id'], (string)($payload['movement_type'] ?? ''), (float)($payload['amount'] ?? 0), (string)($payload['reason'] ?? ''), (string)($payload['notes'] ?? ''), $offlineUuid);
        pos_sync_log($entity, $offlineUuid, $payload, 'success', 'Cash movement synced', (int)$me['id']);
        $results[] = ['offline_uuid' => $offlineUuid, 'status' => 'success', 'movement_id' => (int)$row['id']];
        continue;
      }

      if ($entity === 'shift_close') {
        $active = pos_shift_get_active($branchId);
        if (!$active) throw new Exception('Tidak ada shift aktif untuk ditutup');
        $pendingCount = (int)($payload['pending_queue_count'] ?? 0);
        $syncStatus = $pendingCount > 0 ? 'partial' : 'synced';
        $summary = pos_shift_close((int)$active['id'], (int)$me['id'], (float)($payload['counted_cash_total'] ?? 0), (string)($payload['notes'] ?? ''), $offlineUuid, $syncStatus);
        pos_sync_log($entity, $offlineUuid, $payload, 'success', 'Shift closed synced', (int)$me['id']);
        $results[] = ['offline_uuid' => $offlineUuid, 'status' => 'success', 'shift_id' => (int)$summary['shift']['id']];
        continue;
      }

      if ($entity === 'sale') {
        $active = pos_shift_get_active($branchId);
        if (!$active) throw new Exception('Tidak ada shift aktif untuk transaksi');
        $transactionCode = (string)($payload['transaction_code'] ?? '');
        $groupUuid = (string)($payload['transaction_group_uuid'] ?? '');
        if ($transactionCode === '') {
          $transactionCode = 'TRX-' . date('YmdHis') . '-' . strtoupper(bin2hex(random_bytes(2)));
        }
        if ($groupUuid === '') $groupUuid = $offlineUuid;

        $itemsPayload = $payload['items'] ?? [];
        if (!is_array($itemsPayload) || empty($itemsPayload)) throw new Exception('Item transaksi kosong');
        $paymentMethod = (string)($payload['payment_method'] ?? 'cash');
        $validSyncCodes = array_column(get_active_payment_methods(), 'code');
        if (!in_array($paymentMethod, $validSyncCodes, true)) $paymentMethod = 'cash';
        $paymentBank = null;
        if (payment_method_requires_bank($paymentMethod)) {
          $paymentBank = trim((string)($payload['payment_bank'] ?? ''));
          $validQrisBanks = array_column(get_active_qris_banks(), 'name');
          if ($paymentBank === '' || !in_array($paymentBank, $validQrisBanks, true)) {
            throw new Exception('Pilih bank non-tunai yang aktif.');
          }
        }

        $db = db();
        $db->beginTransaction();
        $stmt = $db->prepare("INSERT INTO sales (transaction_code, transaction_group_uuid, offline_uuid, sync_status, base_sale_code, revision_suffix, revision_no, is_active_revision, revision_status, original_sale_id, product_id, qty, price_each, total, payment_method, payment_proof_path, payment_bank, created_by, branch_id, shift_id)
          VALUES (?,?,?,'synced',?,NULL,0,1,'active',NULL,?,?,?,?,?,?,?,?,?,?)");
        $firstId = 0;
        foreach ($itemsPayload as $i => $s) {
          $pid = (int)($s['product_id'] ?? 0);
          $qty = (int)($s['qty'] ?? 0);
          $price = (float)($s['price_each'] ?? 0);
          if ($pid <= 0 || $qty <= 0) continue;
          $total = $price * $qty;
          $rowOffline = $i === 0 ? $offlineUuid : null;
          $stmt->execute([$transactionCode, $groupUuid, $rowOffline, $transactionCode, $pid, $qty, $price, $total, $paymentMethod, null, $paymentBank, (int)$me['id'], $branchId, (int)$active['id']]);
          $saleId = (int)$db->lastInsertId();
          if ($firstId <= 0) $firstId = $saleId;
          try {
            add_stock_ledger([
              'branch_id' => $branchId,
              'product_id' => $pid,
              'trans_type' => 'pos_sale',
              'ref_table' => 'sales',
              'ref_id' => $saleId,
              'qty_in' => 0,
              'qty_out' => $qty,
              'unit_cost' => null,
              'note' => 'Penjualan POS offline sync',
              'created_by' => (int)$me['id'],
            ]);
          } catch (Throwable $e) {
          }
        }
        if ($firstId > 0) {
          $db->prepare("UPDATE sales SET original_sale_id=? WHERE transaction_code=?")->execute([$firstId, $transactionCode]);
        }
        $db->commit();
        pos_shift_log((int)$active['id'], (int)$me['id'], 'checkout');
        pos_sync_log($entity, $offlineUuid, $payload, 'success', 'Sale synced', (int)$me['id']);
        $results[] = ['offline_uuid' => $offlineUuid, 'status' => 'success', 'transaction_code' => $transactionCode];
        continue;
      }

      pos_sync_log($entity, $offlineUuid, $payload, 'failed', 'Entity type unsupported', (int)$me['id']);
      $results[] = ['offline_uuid' => $offlineUuid, 'status' => 'failed', 'message' => 'Entity type unsupported'];
    } catch (Throwable $e) {
      pos_sync_log($entity, $offlineUuid, $payload, 'conflict', $e->getMessage(), (int)$me['id']);
      $results[] = ['offline_uuid' => $offlineUuid, 'status' => 'conflict', 'message' => $e->getMessage()];
    }
  }

  echo json_encode(['ok' => true, 'results' => $results]);
} catch (Throwable $e) {
  http_response_code(400);
  echo json_encode(['ok' => false, 'error' => $e->getMessage()]);
}
