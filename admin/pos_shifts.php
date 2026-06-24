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

$from = trim((string)($_GET['from'] ?? date('Y-m-01')));
$to = trim((string)($_GET['to'] ?? date('Y-m-d')));
$status = trim((string)($_GET['status'] ?? ''));
$openedBy = (int)($_GET['opened_by'] ?? 0);
$closedBy = (int)($_GET['closed_by'] ?? 0);
$shiftCode = trim((string)($_GET['shift_code'] ?? ''));

$sql = "SELECT s.*, ob.name AS opened_by_name, cb.name AS closed_by_name
  FROM pos_shifts s
  LEFT JOIN users ob ON ob.id=s.opened_by
  LEFT JOIN users cb ON cb.id=s.closed_by
  WHERE DATE(s.opened_at) BETWEEN ? AND ?";
$params = [$from, $to];
if ($status !== '') { $sql .= " AND s.status=?"; $params[] = $status; }
if ($openedBy > 0) { $sql .= " AND s.opened_by=?"; $params[] = $openedBy; }
if ($closedBy > 0) { $sql .= " AND s.closed_by=?"; $params[] = $closedBy; }
if ($shiftCode !== '') { $sql .= " AND s.shift_code LIKE ?"; $params[] = '%' . $shiftCode . '%'; }
$sql .= " ORDER BY s.opened_at DESC LIMIT 300";
$stmt = db()->prepare($sql);
$stmt->execute($params);
$rows = $stmt->fetchAll();
$users = db()->query("SELECT id, name FROM users ORDER BY name ASC")->fetchAll();
$customCss = setting('custom_css', '');
?>
<!doctype html>
<html>
<head>
  <meta charset="utf-8">
  <meta name="viewport" content="width=device-width,initial-scale=1">
  <title>Laporan Shift POS</title>
  <link rel="icon" href="<?php echo e(favicon_url()); ?>">
  <link rel="stylesheet" href="<?php echo e(asset_url('assets/app.css')); ?>">
  <style><?php echo $customCss; ?></style>
</head>
<body class="desktop-compact">
<div class="container">
  <?php include __DIR__ . '/partials_sidebar.php'; ?>
  <div class="main">
    <div class="topbar"><button class="btn" data-toggle-sidebar type="button">Menu</button></div>
    <div class="content">
      <div class="card">
        <h3 style="margin-top:0">Laporan Shift POS</h3>
        <form method="get" class="grid cols-4">
          <div class="row"><label>Dari</label><input type="date" name="from" value="<?php echo e($from); ?>"></div>
          <div class="row"><label>Sampai</label><input type="date" name="to" value="<?php echo e($to); ?>"></div>
          <div class="row"><label>Status</label><select name="status"><option value="">Semua</option><option value="open" <?php echo $status==='open'?'selected':''; ?>>Open</option><option value="closed" <?php echo $status==='closed'?'selected':''; ?>>Closed</option></select></div>
          <div class="row"><label>Shift</label><input name="shift_code" value="<?php echo e($shiftCode); ?>" placeholder="kode shift"></div>
          <div class="row"><label>User pembuka</label><select name="opened_by"><option value="0">Semua</option><?php foreach($users as $u): ?><option value="<?php echo e((string)$u['id']); ?>" <?php echo $openedBy===(int)$u['id']?'selected':''; ?>><?php echo e($u['name']); ?></option><?php endforeach; ?></select></div>
          <div class="row"><label>User penutup</label><select name="closed_by"><option value="0">Semua</option><?php foreach($users as $u): ?><option value="<?php echo e((string)$u['id']); ?>" <?php echo $closedBy===(int)$u['id']?'selected':''; ?>><?php echo e($u['name']); ?></option><?php endforeach; ?></select></div>
          <div class="row" style="display:flex;align-items:flex-end"><button class="btn" type="submit">Filter</button></div>
        </form>
      </div>

      <div class="card" style="margin-top:16px">
        <table>
          <thead><tr><th>Shift</th><th>Waktu</th><th>Pembuka</th><th>Penutup</th><th>Kas Awal</th><th>Expected</th><th>Counted</th><th>Selisih</th><th>Sync</th><th>Aksi</th></tr></thead>
          <tbody>
            <?php if (!$rows): ?>
              <tr><td colspan="10">Belum ada data shift.</td></tr>
            <?php else: foreach($rows as $r): ?>
              <tr>
                <td><?php echo e($r['shift_code']); ?></td>
                <td><?php echo e($r['opened_at']); ?><br><small><?php echo e($r['closed_at'] ?? '-'); ?></small></td>
                <td><?php echo e($r['opened_by_name'] ?? '-'); ?></td>
                <td><?php echo e($r['closed_by_name'] ?? '-'); ?></td>
                <td>Rp <?php echo e(format_number_id((float)$r['opening_cash_actual'])); ?></td>
                <td>Rp <?php echo e(format_number_id((float)$r['expected_cash_total'])); ?></td>
                <td>Rp <?php echo e(format_number_id((float)$r['counted_cash_total'])); ?></td>
                <td>Rp <?php echo e(format_number_id((float)$r['cash_difference'])); ?></td>
                <td><?php echo e($r['sync_status']); ?></td>
                <td><a class="btn" href="<?php echo e(base_url('admin/pos_shift_detail.php?id=' . (int)$r['id'])); ?>">Detail</a></td>
              </tr>
            <?php endforeach; endif; ?>
          </tbody>
        </table>
      </div>
    </div>
  </div>
</div>
<script defer src="<?php echo e(asset_url('assets/app.js')); ?>"></script>
</body>
</html>
