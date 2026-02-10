<?php
require_once __DIR__ . '/../core/db.php';
require_once __DIR__ . '/../core/functions.php';
require_once __DIR__ . '/../core/security.php';
require_once __DIR__ . '/../core/auth.php';

start_secure_session();
require_login();
ensure_kitchen_kpi_tables();
ensure_company_announcements_table();

$me = current_user();
$role = (string)($me['role'] ?? '');
if ($role !== 'pegawai_dapur') {
  redirect(base_url('pos/index.php'));
}

$today = app_today_jakarta();
$stmt = db()->prepare("SELECT a.activity_name, t.target_qty, COALESCE(r.qty,0) AS realized_qty
  FROM kitchen_kpi_targets t
  JOIN kitchen_kpi_activities a ON a.id=t.activity_id
  LEFT JOIN kitchen_kpi_realizations r ON r.user_id=t.user_id AND r.activity_id=t.activity_id AND r.realization_date=t.target_date
  WHERE t.user_id=? AND t.target_date=?
  ORDER BY a.activity_name ASC");
$stmt->execute([(int)$me['id'], $today]);
$rows = $stmt->fetchAll();

$announcement = latest_active_announcement('dapur');
?>
<!doctype html>
<html>
<head>
  <meta charset="utf-8">
  <meta name="viewport" content="width=device-width,initial-scale=1">
  <title>Pekerjaan Dapur Hari Ini</title>
  <link rel="stylesheet" href="<?php echo e(asset_url('assets/app.css')); ?>">
</head>
<body>
<div class="container" style="max-width:880px;margin:20px auto">
  <div class="card">
    <h3>Pekerjaan Dapur Hari Ini</h3>
    <?php if ($announcement): ?>
      <div class="card" style="margin-bottom:12px;background:rgba(96,165,250,.10);border-color:rgba(96,165,250,.35)">
        <strong><?php echo e((string)$announcement['title']); ?></strong>
        <p style="white-space:pre-line;margin:6px 0"><?php echo e((string)$announcement['message']); ?></p>
        <small>Dari: <?php echo e((string)($announcement['posted_by_name'] ?? 'Admin')); ?></small>
      </div>
    <?php endif; ?>
    <table class="table">
      <thead><tr><th>Kegiatan</th><th>Target</th><th>Realisasi</th></tr></thead>
      <tbody>
      <?php if (!$rows): ?>
        <tr><td colspan="3">Belum ada target KPI dapur hari ini.</td></tr>
      <?php else: foreach ($rows as $r): ?>
        <tr>
          <td><?php echo e($r['activity_name']); ?></td>
          <td><?php echo e((string)$r['target_qty']); ?></td>
          <td><?php echo e((string)$r['realized_qty']); ?></td>
        </tr>
      <?php endforeach; endif; ?>
      </tbody>
    </table>
    <div style="margin-top:10px"><a class="btn" href="<?php echo e(base_url('admin/logout.php')); ?>">Logout</a></div>
  </div>
</div>
</body>
</html>
