<?php
require_once __DIR__ . '/../core/db.php';
require_once __DIR__ . '/../core/functions.php';
require_once __DIR__ . '/../core/security.php';
require_once __DIR__ . '/../core/auth.php';
require_once __DIR__ . '/../core/rbac.php';
require_once __DIR__ . '/../core/pos_shift.php';

start_secure_session();
require_admin();
ensure_rbac_schema();
$me = require_menu_access('sales', 'view');
ensure_pos_shift_schema();

$id = (int)($_GET['id'] ?? 0);
if ($id <= 0) {
  redirect(base_url('admin/pos_shifts.php'));
}

$stmt = db()->prepare("SELECT s.*, ob.name AS opened_by_name, cb.name AS closed_by_name FROM pos_shifts s LEFT JOIN users ob ON ob.id=s.opened_by LEFT JOIN users cb ON cb.id=s.closed_by WHERE s.id=? LIMIT 1");
$stmt->execute([$id]);
$shift = $stmt->fetch();
if (!$shift) {
  redirect(base_url('admin/pos_shifts.php'));
}
$summary = pos_shift_calculate_summary($id);

$shiftStart = (string)($shift['opened_at'] ?? '1970-01-01 00:00:00');
$shiftEndBase = (string)($shift['closed_at'] ?? date('Y-m-d H:i:s'));
$shiftEnd = date('Y-m-d H:i:s', strtotime($shiftEndBase . ' +1 second'));
$shiftSales = store_acc_sales_metrics($shiftStart, $shiftEnd, ['shift_id' => $id]);
$txRows = $shiftSales['details'];
$userNames = [];
$userIds = array_values(array_unique(array_filter(array_map(static fn($r) => (int)($r['created_by'] ?? 0), $txRows))));
if ($userIds) {
  try {
    $ph = implode(',', array_fill(0, count($userIds), '?'));
    $userStmt = db()->prepare("SELECT id,name FROM users WHERE id IN ($ph)");
    $userStmt->execute($userIds);
    foreach ($userStmt->fetchAll(PDO::FETCH_ASSOC) as $row) $userNames[(int)$row['id']] = (string)$row['name'];
  } catch (Throwable $e) {}
}

$cashStmt = db()->prepare("SELECT cm.*, u.name AS created_by_name FROM pos_cash_movements cm LEFT JOIN users u ON u.id=cm.created_by WHERE cm.shift_id=? ORDER BY cm.created_at ASC");
$cashStmt->execute([$id]);
$cashRows = $cashStmt->fetchAll();

$auditRows = pos_shift_audit_feed($id);
$syncStmt = db()->prepare("SELECT * FROM pos_sync_queue_log WHERE payload_json LIKE ? ORDER BY created_at DESC LIMIT 100");
$syncStmt->execute(['%"shift_id":' . $id . '%']);
$syncRows = $syncStmt->fetchAll();

$customCss = setting('custom_css', '');
?>
<!doctype html>
<html>
<head>
  <meta charset="utf-8">
  <meta name="viewport" content="width=device-width,initial-scale=1">
  <title>Detail Shift POS</title>
  <link rel="icon" href="<?php echo e(favicon_url()); ?>">
  <link rel="stylesheet" href="<?php echo e(asset_url('assets/app.css')); ?>">
  <style><?php echo $customCss; ?>@media print{.no-print{display:none}}</style>
</head>
<body>
<div class="container">
  <?php include __DIR__ . '/partials_sidebar.php'; ?>
  <div class="main">
    <div class="topbar"><button class="btn" data-toggle-sidebar type="button">Menu</button></div>
    <div class="content">
      <div class="card no-print">
        <a class="btn" href="<?php echo e(base_url('admin/pos_shifts.php')); ?>">← Kembali</a>
        <button class="btn" type="button" onclick="window.print()">Print</button>
      </div>

      <div class="card" style="margin-top:12px">
        <h3 style="margin-top:0">Shift <?php echo e($shift['shift_code']); ?></h3>
        <div class="grid cols-3">
          <div class="card"><small>Pembuka</small><div><?php echo e($shift['opened_by_name'] ?? '-'); ?></div><small><?php echo e($shift['opened_at']); ?></small></div>
          <div class="card"><small>Penutup</small><div><?php echo e($shift['closed_by_name'] ?? '-'); ?></div><small><?php echo e($shift['closed_at'] ?? '-'); ?></small></div>
          <div class="card"><small>Status</small><div><?php echo e($shift['status']); ?> / <?php echo e($shift['sync_status']); ?></div></div>
          <div class="card"><small>Kas Awal</small><div>Rp <?php echo e(format_number_id((float)$summary['opening_cash'])); ?></div></div>
          <div class="card"><small>Cash Sales Bersih</small><div>Rp <?php echo e(format_number_id((float)$summary['cash_sales'])); ?></div></div>
          <div class="card"><small>Non-cash Bersih</small><div>Rp <?php echo e(format_number_id((float)$summary['non_cash_sales'])); ?></div></div>
          <div class="card"><small>Kas Masuk/Keluar</small><div>Rp <?php echo e(format_number_id((float)$summary['cash_in'])); ?> / Rp <?php echo e(format_number_id((float)$summary['cash_out'])); ?></div></div>
          <div class="card"><small>Expected / Counted</small><div>Rp <?php echo e(format_number_id((float)$summary['expected_cash'])); ?> / Rp <?php echo e(format_number_id((float)($shift['counted_cash_total'] ?? 0))); ?></div></div>
          <div class="card"><small>Selisih</small><div>Rp <?php echo e(format_number_id((float)($shift['cash_difference'] ?? 0))); ?></div></div>
        </div>
      </div>

      <div class="card" style="margin-top:12px">
        <h4 style="margin-top:0">Transaksi Shift</h4>
        <table><thead><tr><th>Waktu</th><th>Jenis</th><th>Kode</th><th>Metode</th><th>Kotor</th><th>Diskon</th><th>Retur</th><th>Omzet Bersih</th><th>User</th></tr></thead><tbody><?php if(!$txRows): ?><tr><td colspan="9">Belum ada transaksi.</td></tr><?php else: foreach($txRows as $r): ?><tr><td><?php echo e($r['event_at']); ?></td><td><?php echo e($r['event_label']); ?></td><td><?php echo e($r['transaction_code']); ?></td><td><?php echo e($r['payment_method']); ?></td><td>Rp <?php echo e(format_number_id((float)$r['gross'])); ?></td><td>Rp <?php echo e(format_number_id((float)$r['discount_total'])); ?></td><td>Rp <?php echo e(format_number_id((float)$r['return_amount'])); ?></td><td><?php echo (float)$r['net'] < 0 ? '- ' : ''; ?>Rp <?php echo e(format_number_id(abs((float)$r['net']))); ?></td><td><?php echo e($userNames[(int)($r['created_by'] ?? 0)] ?? (string)($r['created_by'] ?? '-')); ?></td></tr><?php endforeach; endif; ?></tbody></table>
      </div>

      <div class="card" style="margin-top:12px">
        <h4 style="margin-top:0">Kas Masuk / Keluar</h4>
        <table><thead><tr><th>Waktu</th><th>Jenis</th><th>Nominal</th><th>Alasan</th><th>User</th></tr></thead><tbody><?php if(!$cashRows): ?><tr><td colspan="5">Belum ada data.</td></tr><?php else: foreach($cashRows as $r): ?><tr><td><?php echo e($r['created_at']); ?></td><td><?php echo e($r['movement_type']); ?></td><td>Rp <?php echo e(format_number_id((float)$r['amount'])); ?></td><td><?php echo e($r['reason']); ?></td><td><?php echo e($r['created_by_name'] ?? (string)$r['created_by']); ?></td></tr><?php endforeach; endif; ?></tbody></table>
      </div>

      <div class="card" style="margin-top:12px">
        <h4 style="margin-top:0">Audit Trail Shift</h4>
        <table><thead><tr><th>Waktu</th><th>User</th><th>Aktivitas</th></tr></thead><tbody><?php if(!$auditRows): ?><tr><td colspan="3">Belum ada log.</td></tr><?php else: foreach($auditRows as $r): ?><tr><td><?php echo e($r['created_at']); ?></td><td><?php echo e($r['user_name'] ?? (string)$r['user_id']); ?></td><td><?php echo e(strtoupper($r['activity_type'])); ?></td></tr><?php endforeach; endif; ?></tbody></table>
      </div>

      <div class="card" style="margin-top:12px">
        <h4 style="margin-top:0">Log Sinkronisasi</h4>
        <table><thead><tr><th>Waktu</th><th>Entity</th><th>UUID</th><th>Status</th><th>Pesan</th></tr></thead><tbody><?php if(!$syncRows): ?><tr><td colspan="5">Belum ada log sinkronisasi.</td></tr><?php else: foreach($syncRows as $r): ?><tr><td><?php echo e($r['created_at']); ?></td><td><?php echo e($r['entity_type']); ?></td><td><?php echo e($r['offline_uuid']); ?></td><td><?php echo e($r['status']); ?></td><td><?php echo e($r['message']); ?></td></tr><?php endforeach; endif; ?></tbody></table>
      </div>
    </div>
  </div>
</div>
<script defer src="<?php echo e(asset_url('assets/app.js')); ?>"></script>
</body>
</html>
