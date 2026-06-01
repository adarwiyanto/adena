<?php
require_once __DIR__ . '/../core/db.php';
require_once __DIR__ . '/../core/functions.php';
require_once __DIR__ . '/../core/security.php';
require_once __DIR__ . '/../core/auth.php';
require_once __DIR__ . '/../core/rbac.php';
require_once __DIR__ . '/../core/inventory.php';

start_secure_session();
$u = require_menu_access('stok_opname', 'view');
ensure_inventory_module_schema();

$id = (int)($_GET['id'] ?? 0);
$header = $id > 0 ? get_stock_opname_header($id) : null;
if (!$header) {
  http_response_code(404);
  echo 'Dokumen opname tidak ditemukan.';
  exit;
}

$items = get_stock_opname_items($id);
$varianceItems = [];
$totalPlus = 0.0;
$totalMinus = 0.0;
$totalAbs = 0.0;
$byReason = [];

foreach ($items as $it) {
  $variance = (float)($it['variance_qty'] ?? 0);
  if (abs($variance) < 0.00001) continue;
  $varianceItems[] = $it;
  if ($variance > 0) $totalPlus += $variance;
  if ($variance < 0) $totalMinus += abs($variance);
  $totalAbs += abs($variance);
  $reason = trim((string)($it['reason_note'] ?? ''));
  if ($reason === '') $reason = 'Tanpa alasan';
  if (!isset($byReason[$reason])) {
    $byReason[$reason] = ['count' => 0, 'plus' => 0.0, 'minus' => 0.0, 'abs' => 0.0];
  }
  $byReason[$reason]['count']++;
  if ($variance > 0) $byReason[$reason]['plus'] += $variance;
  if ($variance < 0) $byReason[$reason]['minus'] += abs($variance);
  $byReason[$reason]['abs'] += abs($variance);
}

$customCss = setting('custom_css', '');
?>
<!doctype html>
<html>
<head>
<meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1">
<title>Rekap Selisih Opname <?php echo e((string)$header['opname_no']); ?></title>
<link rel="icon" href="<?php echo e(favicon_url()); ?>">
<link rel="stylesheet" href="<?php echo e(asset_url('assets/app.css')); ?>">
<style>
<?php echo $customCss; ?>
.report-wrap{max-width:1100px;margin:24px auto;padding:0 16px}.report-head{display:flex;justify-content:space-between;gap:16px;align-items:flex-start}.report-title h2{margin:0 0 6px}.muted{color:#64748b}.summary-grid{display:grid;grid-template-columns:repeat(4,minmax(0,1fr));gap:12px}.summary-box{border:1px solid #e2e8f0;border-radius:14px;padding:12px;background:#fff}.summary-box .num{font-size:22px;font-weight:700;margin-top:4px}.print-actions{display:flex;gap:8px;flex-wrap:wrap}.table td,.table th{vertical-align:top}.text-right{text-align:right}.nowrap{white-space:nowrap}@media print{.no-print,.sidebar,.topbar{display:none!important}.container{display:block}.main{margin:0}.report-wrap{max-width:none;margin:0;padding:0}.card{box-shadow:none;border-color:#cbd5e1}.table{font-size:12px}body{background:#fff}.summary-grid{grid-template-columns:repeat(4,1fr)}}
@media(max-width:800px){.report-head{display:block}.summary-grid{grid-template-columns:repeat(2,minmax(0,1fr))}}
</style>
</head>
<body>
<div class="report-wrap">
  <div class="card report-head">
    <div class="report-title">
      <h2>Rekap Laporan Selisih Stok Opname</h2>
      <div class="muted">Satu dokumen rekap untuk sekali stok opname.</div>
    </div>
    <div class="print-actions no-print">
      <button class="btn" type="button" onclick="window.print()">Print / Simpan PDF</button>
      <a class="btn btn-light" href="<?php echo e(base_url('admin/stock_opname_form.php?id=' . (int)$id)); ?>">Kembali ke Detail</a>
    </div>
  </div>

  <div class="card">
    <div class="grid cols-4">
      <div class="row"><label>No Opname</label><div><?php echo e((string)$header['opname_no']); ?></div></div>
      <div class="row"><label>Tanggal</label><div><?php echo e((string)$header['opname_date']); ?></div></div>
      <div class="row"><label>Cabang</label><div><?php echo e((string)$header['branch_name']); ?></div></div>
      <div class="row"><label>Status</label><div><span class="badge"><?php echo e((string)$header['status']); ?></span></div></div>
      <div class="row"><label>Petugas</label><div><?php echo e((string)($header['creator_name'] ?? '-')); ?></div></div>
      <div class="row"><label>Approver</label><div><?php echo e((string)($header['approver_name'] ?? '-')); ?></div></div>
      <div class="row"><label>Waktu Approval</label><div><?php echo e((string)($header['approved_at'] ?? '-')); ?></div></div>
      <div class="row"><label>Catatan</label><div><?php echo e((string)($header['notes'] ?? '-')); ?></div></div>
    </div>
  </div>

  <div class="card summary-grid">
    <div class="summary-box"><div class="muted">Total Item Opname</div><div class="num"><?php echo e((string)count($items)); ?></div></div>
    <div class="summary-box"><div class="muted">Item Berselisih</div><div class="num"><?php echo e((string)count($varianceItems)); ?></div></div>
    <div class="summary-box"><div class="muted">Total Selisih Plus</div><div class="num"><?php echo e(format_qty($totalPlus, null)); ?></div></div>
    <div class="summary-box"><div class="muted">Total Selisih Minus</div><div class="num"><?php echo e(format_qty($totalMinus, null)); ?></div></div>
  </div>

  <div class="card">
    <h3>Ringkasan Selisih Berdasarkan Alasan</h3>
    <table class="table">
      <thead><tr><th>Alasan</th><th class="text-right">Jumlah Item</th><th class="text-right">Selisih Plus</th><th class="text-right">Selisih Minus</th><th class="text-right">Total Absolut</th></tr></thead>
      <tbody>
      <?php if(empty($byReason)): ?>
        <tr><td colspan="5" style="text-align:center;color:#94a3b8">Tidak ada selisih pada opname ini.</td></tr>
      <?php else: foreach($byReason as $reason => $sum): ?>
        <tr>
          <td><?php echo e($reason); ?></td>
          <td class="text-right"><?php echo e((string)$sum['count']); ?></td>
          <td class="text-right"><?php echo e(format_qty((float)$sum['plus'], null)); ?></td>
          <td class="text-right"><?php echo e(format_qty((float)$sum['minus'], null)); ?></td>
          <td class="text-right"><?php echo e(format_qty((float)$sum['abs'], null)); ?></td>
        </tr>
      <?php endforeach; endif; ?>
      </tbody>
    </table>
  </div>

  <div class="card">
    <h3>Detail Item Berselisih dalam Opname Ini</h3>
    <table class="table">
      <thead><tr><th>Barang</th><th class="text-right nowrap">Qty Sistem</th><th class="text-right nowrap">Qty Fisik</th><th class="text-right nowrap">Selisih</th><th>Alasan</th><th>Catatan</th></tr></thead>
      <tbody>
      <?php if(empty($varianceItems)): ?>
        <tr><td colspan="6" style="text-align:center;color:#94a3b8">Tidak ada item berselisih.</td></tr>
      <?php else: foreach($varianceItems as $it): $unitMeta = product_unit_fallback($it); $variance = (float)$it['variance_qty']; ?>
        <tr>
          <td><?php echo e((string)$it['product_name']); ?></td>
          <td class="text-right nowrap"><?php echo e(format_qty((float)$it['system_qty'], $unitMeta['base_unit'])); ?></td>
          <td class="text-right nowrap"><?php echo e(format_qty((float)$it['physical_qty'], $unitMeta['base_unit'])); ?></td>
          <td class="text-right nowrap"><?php echo e(($variance > 0 ? '+' : '') . format_qty($variance, $unitMeta['base_unit'])); ?></td>
          <td><?php echo e((string)($it['reason_note'] ?? '-')); ?></td>
          <td><?php echo e((string)($it['line_note'] ?? '-')); ?></td>
        </tr>
      <?php endforeach; endif; ?>
      </tbody>
    </table>
  </div>
</div>
</body>
</html>
