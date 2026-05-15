<?php
require_once __DIR__ . '/../core/db.php';
require_once __DIR__ . '/../core/functions.php';
require_once __DIR__ . '/../core/security.php';
require_once __DIR__ . '/../core/auth.php';
require_once __DIR__ . '/../core/csrf.php';
require_once __DIR__ . '/../core/rbac.php';
require_once __DIR__ . '/../core/inventory.php';

start_secure_session();
$u = require_menu_access('stok_opname', 'view');
ensure_inventory_module_schema();

$err = '';
$id = (int)($_GET['id'] ?? 0);

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
  csrf_check();
  $action = (string)($_POST['action'] ?? '');
  $actionPermMap = ['create_draft' => 'create', 'save_items' => 'edit', 'submit' => 'edit'];
  if (isset($actionPermMap[$action])) {
    require_action_access('stok_opname', $actionPermMap[$action]);
  }

  try {
    $db = db();
    $db->beginTransaction();

    if ($action === 'create_draft') {
      $branchId = (int)($_POST['branch_id'] ?? active_branch_id());
      $opnameDate = (string)($_POST['opname_date'] ?? date('Y-m-d'));
      $notes = trim((string)($_POST['notes'] ?? ''));
      $search = trim((string)($_POST['search'] ?? ''));
      $category = trim((string)($_POST['category'] ?? ''));
      $productType = trim((string)($_POST['product_type'] ?? 'finished_good'));
      if ($productType === '') $productType = 'finished_good';
      $products = stock_products_for_opname($branchId, $search, $category, $productType);
      $id = create_stock_opname_draft($db, [
        'branch_id' => $branchId,
        'opname_date' => $opnameDate,
        'notes' => $notes,
        'created_by' => (int)($u['id'] ?? 0),
      ], $products);
      $db->commit();
      redirect(base_url('admin/stock_opname_form.php?id=' . $id));
    }

    if ($action === 'save_items') {
      $id = (int)($_POST['id'] ?? 0);
      $itemIds = $_POST['item_id'] ?? [];
      $systemQtys = $_POST['system_qty'] ?? [];
      $physicalQtys = $_POST['physical_qty'] ?? [];
      $reasonNotes = $_POST['reason_note'] ?? [];
      $lineNotes = $_POST['line_note'] ?? [];
      $rows = [];
      if (!is_array($itemIds)) $itemIds = [];
      foreach ($itemIds as $idx => $itemId) {
        $rows[] = [
          'id' => (int)$itemId,
          'system_qty' => parse_number_input($systemQtys[$idx] ?? 0),
          'physical_qty' => parse_number_input($physicalQtys[$idx] ?? 0),
          'reason_note' => trim((string)($reasonNotes[$idx] ?? '')),
          'line_note' => trim((string)($lineNotes[$idx] ?? '')),
        ];
      }
      save_stock_opname_items($db, $id, $rows);
      $db->commit();
      redirect(base_url('admin/stock_opname_form.php?id=' . $id));
    }

    if ($action === 'submit') {
      $id = (int)($_POST['id'] ?? 0);
      submit_stock_opname($db, $id);
      $db->commit();
      redirect(base_url('admin/stock_opname.php'));
    }
  } catch (Throwable $e) {
    if (isset($db) && $db->inTransaction()) $db->rollBack();
    $err = $e->getMessage();
  }
}

$branches = inventory_branches();
$categories = stock_categories();
$customCss = setting('custom_css', '');
$header = $id > 0 ? get_stock_opname_header($id) : null;
$items = $id > 0 ? get_stock_opname_items($id) : [];
$isDraft = (($header['status'] ?? '') === 'draft');
$totalItems = count($items);
$totalVariance = 0;
$totalPlus = 0;
$totalMinus = 0;
foreach ($items as $it) {
  $v = (float)($it['variance_qty'] ?? 0);
  if (abs($v) > 0.00001) $totalVariance++;
  if ($v > 0) $totalPlus += $v;
  if ($v < 0) $totalMinus += abs($v);
}

function opname_status_label(string $status): string {
  $map = [
    'draft' => 'Draft',
    'waiting_approval' => 'Menunggu Approval',
    'approved' => 'Approved',
    'rejected' => 'Rejected',
    'cancelled' => 'Cancelled',
  ];
  return $map[$status] ?? $status;
}
?>
<!doctype html>
<html>
<head>
<meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1">
<title><?php echo $id > 0 ? 'Detail Stok Opname' : 'Buat Stok Opname'; ?></title>
<link rel="icon" href="<?php echo e(favicon_url()); ?>">
<link rel="stylesheet" href="<?php echo e(asset_url('assets/app.css')); ?>">
<style>
<?php echo $customCss; ?>
.opname-page{max-width:1380px;margin:0 auto}.desktop-toolbar{display:flex;gap:12px;align-items:flex-end;justify-content:space-between;flex-wrap:wrap}.desktop-toolbar .left{display:flex;gap:12px;align-items:flex-end;flex-wrap:wrap}.summary-grid{display:grid;grid-template-columns:repeat(4,minmax(160px,1fr));gap:12px;margin-top:12px}.summary-card{border:1px solid #e2e8f0;background:#f8fafc;border-radius:16px;padding:14px}.summary-card .num{font-size:24px;font-weight:800;color:#0f172a}.summary-card .lbl{font-size:12px;color:#64748b;text-transform:uppercase;letter-spacing:.04em}.table-shell{overflow:auto;border:1px solid #e2e8f0;border-radius:16px}.opname-table{min-width:1180px;width:100%;border-collapse:separate;border-spacing:0}.opname-table th{position:sticky;top:0;background:#f8fafc;z-index:2;font-size:12px;text-transform:uppercase;letter-spacing:.04em;color:#475569}.opname-table th,.opname-table td{padding:10px 12px;border-bottom:1px solid #e2e8f0;vertical-align:middle}.opname-table tbody tr:hover{background:#f8fafc}.product-cell{min-width:300px}.product-name{font-weight:700;color:#0f172a}.muted{color:#64748b;font-size:12px}.qty-input{width:140px;min-height:38px;font-size:15px;text-align:right}.reason-input{min-width:220px}.note-input{min-width:180px}.variance-pill{display:inline-flex;align-items:center;justify-content:center;min-width:88px;border-radius:999px;border:1px solid #cbd5e1;padding:6px 10px;font-weight:700}.variance-plus{background:#ecfdf5;border-color:#86efac;color:#166534}.variance-minus{background:#fff1f2;border-color:#fda4af;color:#9f1239}.variance-zero{background:#f8fafc;color:#475569}.action-bar{position:sticky;bottom:0;display:flex;gap:10px;justify-content:flex-end;align-items:center;flex-wrap:wrap;background:rgba(255,255,255,.92);backdrop-filter:blur(8px);border:1px solid #e2e8f0;border-radius:16px;padding:12px;margin-top:12px}.filter-chip{display:flex;gap:8px;align-items:center;flex-wrap:wrap}.filter-chip input,.filter-chip select{min-height:40px}@media(max-width:900px){.summary-grid{grid-template-columns:1fr 1fr}.desktop-toolbar{align-items:stretch}.desktop-toolbar .left{display:grid;grid-template-columns:1fr;width:100%}.opname-page{max-width:100%}.table-shell{border-radius:12px}.action-bar{position:static;justify-content:stretch}.action-bar .btn{width:100%}}
</style>
</head>
<body>
<div class="container"><?php include __DIR__ . '/partials_sidebar.php'; ?>
<div class="main"><div class="topbar"><button class="btn" data-toggle-sidebar type="button">Menu</button><strong>Stok Opname Toko</strong></div>
<div class="content opname-page">
<?php if($err): ?><div class="card" style="border-color:rgba(251,113,133,.35);background:rgba(251,113,133,.10)"><?php echo e($err); ?></div><?php endif; ?>

<?php if(!$header): ?>
<div class="card">
  <h3>Buat Draft Stok Opname</h3>
  <p class="muted">Mode toko hanya menampilkan produk jadi/barang jual yang ditrack stok. Raw material, BOM, dan produksi tidak masuk alur ini.</p>
  <form method="post" class="grid cols-4">
    <input type="hidden" name="_csrf" value="<?php echo e(csrf_token()); ?>">
    <input type="hidden" name="action" value="create_draft">
    <div class="row"><label>Cabang</label><select name="branch_id"><?php foreach($branches as $b): ?><option value="<?php echo e((string)$b['id']); ?>" <?php echo (int)$b['id']===(int)($_GET['branch_id'] ?? active_branch_id())?'selected':''; ?>><?php echo e($b['branch_name']); ?></option><?php endforeach; ?></select></div>
    <div class="row"><label>Tanggal Opname</label><input type="date" name="opname_date" value="<?php echo e(date('Y-m-d')); ?>" required></div>
    <div class="row"><label>Jenis Produk</label><select name="product_type"><option value="finished_good" selected>Barang toko / produk jadi</option></select></div>
    <div class="row"><label>Kategori</label><select name="category"><option value="">Semua kategori</option><?php foreach($categories as $c): ?><option value="<?php echo e((string)$c['category']); ?>"><?php echo e((string)$c['category']); ?></option><?php endforeach; ?></select></div>
    <div class="row"><label>Cari Produk</label><input type="text" name="search" placeholder="Nama/kategori/kode"></div>
    <div class="row" style="grid-column:span 2"><label>Catatan</label><input type="text" name="notes" placeholder="Catatan umum opname"></div>
    <div class="row" style="align-self:end"><button class="btn" type="submit">Generate Draft</button></div>
  </form>
</div>
<?php else: ?>
<div class="card">
  <div class="desktop-toolbar">
    <div>
      <h3 style="margin-bottom:4px">Detail Opname <?php echo e((string)$header['opname_no']); ?></h3>
      <div class="muted"><?php echo e((string)$header['branch_name']); ?> · <?php echo e((string)$header['opname_date']); ?> · Petugas: <?php echo e((string)($header['creator_name'] ?? '-')); ?></div>
    </div>
    <div><span class="badge"><?php echo e(opname_status_label((string)$header['status'])); ?></span></div>
  </div>
  <div class="summary-grid">
    <div class="summary-card"><div class="lbl">Total Item</div><div class="num"><?php echo e((string)$totalItems); ?></div></div>
    <div class="summary-card"><div class="lbl">Item Selisih</div><div class="num" id="sumVarianceItems"><?php echo e((string)$totalVariance); ?></div></div>
    <div class="summary-card"><div class="lbl">Total Selisih Plus</div><div class="num" id="sumPlus"><?php echo e(format_qty($totalPlus)); ?></div></div>
    <div class="summary-card"><div class="lbl">Total Selisih Minus</div><div class="num" id="sumMinus"><?php echo e(format_qty($totalMinus)); ?></div></div>
  </div>
  <?php if(!empty($header['approval_note'])): ?><p style="margin-top:12px"><strong>Catatan Approval:</strong> <?php echo e((string)$header['approval_note']); ?></p><?php endif; ?>
</div>

<div class="card">
  <div class="desktop-toolbar" style="margin-bottom:12px">
    <div class="left filter-chip">
      <div class="row"><label>Cari di draft</label><input id="opnameSearch" type="search" placeholder="Ketik nama/kategori/ID"></div>
      <div class="row"><label>Status Selisih</label><select id="varianceFilter"><option value="all">Semua</option><option value="diff">Ada selisih</option><option value="plus">Plus</option><option value="minus">Minus</option><option value="zero">Nol</option></select></div>
    </div>
    <?php if($isDraft): ?><div class="muted">Input stok fisik, selisih dihitung otomatis. Alasan wajib bila selisih tidak nol.</div><?php endif; ?>
  </div>

  <form method="post" id="opnameForm">
    <input type="hidden" name="_csrf" value="<?php echo e(csrf_token()); ?>">
    <input type="hidden" name="action" value="save_items">
    <input type="hidden" name="id" value="<?php echo e((string)$id); ?>">
    <div class="table-shell">
      <table class="opname-table" id="opnameTable">
        <thead><tr><th>Barang</th><th>Kategori</th><th>Stok Sistem</th><th>Stok Fisik</th><th>Selisih</th><th>Alasan Selisih</th><th>Catatan</th></tr></thead>
        <tbody>
        <?php foreach($items as $it):
          $variance = (float)$it['variance_qty'];
          $unitMeta = product_unit_fallback($it);
          $vClass = abs($variance) < 0.00001 ? 'variance-zero' : ($variance > 0 ? 'variance-plus' : 'variance-minus');
          $vType = abs($variance) < 0.00001 ? 'zero' : ($variance > 0 ? 'plus' : 'minus');
          $searchText = strtolower((string)$it['product_name'] . ' ' . (string)($it['category'] ?? '') . ' ' . (string)$it['product_id']);
        ?>
        <tr data-search="<?php echo e($searchText); ?>" data-variance-type="<?php echo e($vType); ?>">
          <td class="product-cell">
            <div class="product-name"><?php echo e((string)$it['product_name']); ?></div>
            <div class="muted">ID: <?php echo e((string)$it['product_id']); ?> · Unit: <?php echo e($unitMeta['base_unit']); ?></div>
            <input type="hidden" name="item_id[]" value="<?php echo e((string)$it['id']); ?>">
            <input class="system-qty" type="hidden" name="system_qty[]" value="<?php echo e((string)$it['system_qty']); ?>">
          </td>
          <td><?php echo e((string)($it['category'] ?? '-')); ?></td>
          <td style="text-align:right" data-system="<?php echo e((string)$it['system_qty']); ?>"><?php echo e(format_qty((float)$it['system_qty'], $unitMeta['base_unit'])); ?></td>
          <td><input class="qty-input physical-qty" type="number" step="0.0001" min="0" name="physical_qty[]" value="<?php echo e((string)$it['physical_qty']); ?>" <?php echo !$isDraft?'readonly':''; ?> required data-unit="<?php echo e($unitMeta['base_unit']); ?>"></td>
          <td><span class="variance-pill <?php echo e($vClass); ?>"><?php echo e(format_qty($variance, $unitMeta['base_unit'])); ?></span></td>
          <td><input class="reason-input" type="text" name="reason_note[]" value="<?php echo e((string)($it['reason_note'] ?? '')); ?>" <?php echo !$isDraft?'readonly':''; ?> placeholder="Wajib bila selisih"></td>
          <td><input class="note-input" type="text" name="line_note[]" value="<?php echo e((string)($it['line_note'] ?? '')); ?>" <?php echo !$isDraft?'readonly':''; ?>></td>
        </tr>
        <?php endforeach; ?>
        <?php if(empty($items)): ?><tr><td colspan="7" style="text-align:center;color:#94a3b8">Item opname kosong.</td></tr><?php endif; ?>
        </tbody>
      </table>
    </div>

    <div class="action-bar">
      <a class="btn btn-light" href="<?php echo e(base_url('admin/stock_opname.php')); ?>">Kembali</a>
      <?php if($isDraft && has_menu_access($u, 'stok_opname', 'edit')): ?><button class="btn" type="submit">Simpan Draft</button><?php endif; ?>
    </div>
  </form>

  <?php if($isDraft && has_menu_access($u, 'stok_opname', 'edit')): ?>
  <form method="post" class="action-bar" onsubmit="return confirm('Submit opname untuk approval owner? Setelah submit draft tidak bisa diedit.');">
    <input type="hidden" name="_csrf" value="<?php echo e(csrf_token()); ?>">
    <input type="hidden" name="action" value="submit">
    <input type="hidden" name="id" value="<?php echo e((string)$id); ?>">
    <button class="btn" type="submit">Submit Menunggu Approval</button>
  </form>
  <?php endif; ?>
</div>
<?php endif; ?>

</div></div></div>
<script defer src="<?php echo e(asset_url('assets/app.js')); ?>"></script>
<script>
(function(){
  const table = document.getElementById('opnameTable');
  if (!table) return;
  const search = document.getElementById('opnameSearch');
  const varianceFilter = document.getElementById('varianceFilter');
  const nf = new Intl.NumberFormat('id-ID', {minimumFractionDigits:0, maximumFractionDigits:4});
  function parseNum(v){ const n = parseFloat(String(v || '0').replace(',', '.')); return Number.isFinite(n) ? n : 0; }
  function fmt(v, unit){ return nf.format(v) + (unit ? ' ' + unit : ''); }
  function updateRow(tr){
    const sys = parseNum(tr.querySelector('.system-qty')?.value);
    const input = tr.querySelector('.physical-qty');
    const physical = parseNum(input?.value);
    const variance = Math.round((physical - sys) * 10000) / 10000;
    const pill = tr.querySelector('.variance-pill');
    const unit = input?.dataset.unit || '';
    let type = 'zero';
    if (variance > 0.00001) type = 'plus';
    if (variance < -0.00001) type = 'minus';
    tr.dataset.varianceType = type;
    if (pill) {
      pill.className = 'variance-pill ' + (type === 'plus' ? 'variance-plus' : (type === 'minus' ? 'variance-minus' : 'variance-zero'));
      pill.textContent = fmt(variance, unit);
    }
  }
  function updateSummary(){
    let diff = 0, plus = 0, minus = 0;
    table.querySelectorAll('tbody tr[data-variance-type]').forEach(tr => {
      const sys = parseNum(tr.querySelector('.system-qty')?.value);
      const physical = parseNum(tr.querySelector('.physical-qty')?.value);
      const v = Math.round((physical - sys) * 10000) / 10000;
      if (Math.abs(v) > 0.00001) diff++;
      if (v > 0) plus += v;
      if (v < 0) minus += Math.abs(v);
    });
    const sumVarianceItems = document.getElementById('sumVarianceItems');
    const sumPlus = document.getElementById('sumPlus');
    const sumMinus = document.getElementById('sumMinus');
    if (sumVarianceItems) sumVarianceItems.textContent = String(diff);
    if (sumPlus) sumPlus.textContent = nf.format(plus);
    if (sumMinus) sumMinus.textContent = nf.format(minus);
  }
  function applyFilter(){
    const q = (search?.value || '').toLowerCase().trim();
    const vf = varianceFilter?.value || 'all';
    table.querySelectorAll('tbody tr[data-search]').forEach(tr => {
      const hitSearch = !q || (tr.dataset.search || '').includes(q);
      const type = tr.dataset.varianceType || 'zero';
      const hitVar = vf === 'all' || (vf === 'diff' && type !== 'zero') || vf === type;
      tr.style.display = hitSearch && hitVar ? '' : 'none';
    });
  }
  table.querySelectorAll('.physical-qty').forEach(input => input.addEventListener('input', e => { updateRow(e.target.closest('tr')); updateSummary(); applyFilter(); }));
  if (search) search.addEventListener('input', applyFilter);
  if (varianceFilter) varianceFilter.addEventListener('change', applyFilter);
})();
</script>
</body></html>
