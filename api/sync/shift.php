<?php
/**
 * POST /api/sync/shift.php
 * Shift actions for POS Desktop (token based).
 */
require_once __DIR__ . '/../helpers.php';
require_once __DIR__ . '/../../core/pos_shift.php';

if (($_SERVER['REQUEST_METHOD'] ?? 'GET') !== 'POST') api_err('Method tidak diizinkan.', 405);

$token = api_verify_token();
$body = json_decode(file_get_contents('php://input'), true);
if (!is_array($body)) api_err('Body JSON tidak valid.');

$action = trim((string)($body['action'] ?? 'status'));
$branchId = (int)($body['branch_id'] ?? ($token['branch_id'] ?? 0));
if ($branchId <= 0) $branchId = 1;

try {
    if ($action === 'status') {
        $active = pos_shift_get_active($branchId);
        api_ok([
            'shift' => $active,
            'has_active_shift' => (bool)$active,
            'state' => $active ? 'active_shift_exists' : 'no_active_shift',
            'requires_open_shift' => !$active,
        ]);
    }

    $userId = (int)($body['user_id'] ?? 0);
    if ($userId <= 0) api_err('user_id wajib diisi.', 422);

    if ($action === 'open') {
        $openingCash = (float)($body['opening_cash_actual'] ?? 0);
        $offlineUuid = trim((string)($body['offline_uuid'] ?? '')) ?: null;
        if ($offlineUuid !== null) {
            $stmt = db()->prepare("SELECT * FROM pos_shifts WHERE offline_open_uuid = ? LIMIT 1");
            $stmt->execute([$offlineUuid]);
            $existing = $stmt->fetch(PDO::FETCH_ASSOC);
            if ($existing) api_ok(['shift' => $existing, 'idempotent' => true]);
        }
        $shift = pos_shift_open($userId, $openingCash, $offlineUuid, $branchId);
        api_ok(['shift' => $shift]);
    }

    if ($action === 'close') {
        $countedCash = (float)($body['counted_cash_total'] ?? 0);
        $notes = trim((string)($body['notes'] ?? ''));
        $offlineUuid = trim((string)($body['offline_uuid'] ?? '')) ?: null;
        if ($offlineUuid !== null) {
            $stmt = db()->prepare("SELECT * FROM pos_shifts WHERE offline_close_uuid = ? LIMIT 1");
            $stmt->execute([$offlineUuid]);
            $closed = $stmt->fetch(PDO::FETCH_ASSOC);
            if ($closed) {
                $summary = pos_shift_calculate_summary((int)$closed['id']);
                api_ok(['summary' => $summary, 'idempotent' => true]);
            }
        }
        $active = pos_shift_get_active($branchId);
        if (!$active) api_err('Belum ada shift aktif.', 409);
        $summary = pos_shift_close((int)$active['id'], $userId, $countedCash, $notes, $offlineUuid, 'synced');
        api_ok(['summary' => $summary]);
    }

    api_err('Action tidak dikenali.', 422);
} catch (Throwable $e) {
    api_err($e->getMessage(), 400);
}
