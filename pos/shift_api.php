<?php
require_once __DIR__ . '/../core/db.php';
require_once __DIR__ . '/../core/functions.php';
require_once __DIR__ . '/../core/security.php';
require_once __DIR__ . '/../core/auth.php';
require_once __DIR__ . '/../core/csrf.php';
require_once __DIR__ . '/../core/rbac.php';
require_once __DIR__ . '/../core/pos_shift.php';

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
$me = current_user();
$me = require_menu_access('pos', 'view');

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
  http_response_code(405);
  echo json_encode(['ok' => false, 'error' => 'Method not allowed']);
  exit;
}

try {
  csrf_check();
  $action = (string)($_POST['action'] ?? 'status');
  $branchId = pos_safe_branch_id();
  $role = (string)(resolve_user_role($me)['role_key'] ?? '');

  if ($action === 'status') {
    $active = pos_shift_get_active($branchId);
    if ($active) pos_shift_mark_user_activity($active, (int)$me['id'], 'join');
    echo json_encode([
      'ok' => true,
      'shift' => $active,
      'has_active_shift' => (bool)$active,
      'requires_open_shift' => !$active,
      'state' => $active ? 'active_shift_exists' : 'no_active_shift',
      'forced_close_required' => false,
    ]);
    exit;
  }

  if ($action === 'open') {
    if (!in_array($role, ['owner', 'admin'], true)) {
      throw new Exception('Hanya owner/admin yang boleh membuka shift.');
    }
    $openingCash = (float)parse_number_input($_POST['opening_cash_actual'] ?? '0');
    $offlineUuid = trim((string)($_POST['offline_uuid'] ?? ''));
    $shift = pos_shift_open((int)$me['id'], $openingCash, $offlineUuid ?: null, $branchId);
    pos_sync_log('shift_open', $offlineUuid ?: ('srv-open-' . $shift['id']), ['shift_id' => $shift['id']], 'success', 'Shift opened', (int)$me['id']);
    echo json_encode(['ok' => true, 'shift' => $shift]);
    exit;
  }

  $active = pos_shift_get_active($branchId);
  if (!$active) {
    http_response_code(409);
    echo json_encode([
      'ok' => false,
      'error' => 'Belum ada shift aktif.',
      'code' => 'no_active_shift',
      'has_active_shift' => false,
      'requires_open_shift' => true,
      'state' => 'no_active_shift',
    ]);
    exit;
  }

  if ($action === 'summary') {
    pos_shift_mark_user_activity($active, (int)$me['id'], 'use');
    $summary = pos_shift_calculate_summary((int)$active['id']);
    echo json_encode(['ok' => true, 'summary' => $summary]);
    exit;
  }

  if ($action === 'cash_movement') {
    $movementType = (string)($_POST['movement_type'] ?? '');
    $amount = (float)parse_number_input($_POST['amount'] ?? '0');
    $reason = trim((string)($_POST['reason'] ?? ''));
    $notes = trim((string)($_POST['notes'] ?? ''));
    $offlineUuid = trim((string)($_POST['offline_uuid'] ?? ''));
    $row = pos_cash_movement_record((int)$active['id'], (int)$me['id'], $movementType, $amount, $reason, $notes, $offlineUuid ?: null);
    pos_sync_log('cash_movement', $offlineUuid ?: ('srv-cash-' . $row['id']), $row, 'success', 'Cash movement stored', (int)$me['id']);
    echo json_encode(['ok' => true, 'movement' => $row]);
    exit;
  }

  if ($action === 'close') {
    $countedCash = (float)parse_number_input($_POST['counted_cash_total'] ?? '0');
    $notes = trim((string)($_POST['notes'] ?? ''));
    $offlineUuid = trim((string)($_POST['offline_uuid'] ?? ''));
    $syncStatus = (string)($_POST['sync_status'] ?? 'synced');
    if (!in_array($syncStatus, ['synced', 'pending', 'partial', 'conflict'], true)) $syncStatus = 'synced';
    $summary = pos_shift_close((int)$active['id'], (int)$me['id'], $countedCash, $notes, $offlineUuid ?: null, $syncStatus);
    pos_sync_log('shift_close', $offlineUuid ?: ('srv-close-' . $active['id']), ['shift_id' => $active['id'], 'counted_cash_total' => $countedCash], 'success', 'Shift closed', (int)$me['id']);
    echo json_encode(['ok' => true, 'summary' => $summary]);
    exit;
  }

  throw new Exception('Action tidak dikenali.');
} catch (Throwable $e) {
  http_response_code(400);
  echo json_encode(['ok' => false, 'error' => $e->getMessage()]);
}
