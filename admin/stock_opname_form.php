<?php
require_once __DIR__ . '/../core/db.php';
require_once __DIR__ . '/../core/functions.php';
require_once __DIR__ . '/../core/security.php';
require_once __DIR__ . '/../core/auth.php';
require_once __DIR__ . '/../core/csrf.php';
require_once __DIR__ . '/../core/inventory.php';

start_secure_session();
$u = inventory_require_stock_role();
ensure_inventory_module_schema();

$err = '';
$id = (int)($_GET['id'] ?? 0);

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
  csrf_check();
  $action = (string)($_POST['action'] ?? '');
  try {
    $db = db();
    $db->beginTransaction();
    if ($action === 'create_draft') {
      $branchId = (int)($_POST['branch_id'] ?? active_branch_id());
      $opnameDate = (string)($_POST['opname_date'] ?? date('Y-m-d'));
      $notes = trim((string)($_POST['notes'] ?? ''));
      $search = trim((string)($_POST['search'] ?? ''));
      $category = trim((string)($_POST['category'] ?? ''));
      $productType = trim((string)($_POST['product_type'] ?? ''));
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

function variance_badge(float $variance): string {
  if ($variance > 0) return '+'.format_qty($variance, null);
  if ($variance < 0) return format_qty($variance, null);
  return '0';
}
?>
<!doctype html><html><head>
<meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1">
<title>Form Stok Opname</title><link rel="icon" href="<?php echo e(favicon_url()); ?>"><link rel="stylesheet" href="<?php echo e(asset_url('assets/app.css')); ?>"><style><?php echo $customCss; ?></style>
</head><body><div class="container"><?php include __DIR__ . '/partials_sidebar.php'; ?>
<div class="main"><div class="topbar"><button class="btn" data-toggle-sidebar type="button">Menu</button></div><div class="content">
<?php if($err): ?><div class="card" style="border-color:rgba(251,113,133,.35);background:rgba(251,113,133,.10)"><?php echo e($err); ?></div><?php endif; ?>

<?php if(!$header): ?>
<div class="card"><h3>Buat Draft Stok Opname</h3>
<form method="post" class="grid cols-3">
<input type="hidden" name="_csrf" value="<?php echo e(csrf_token()); ?>"><input type="hidden" name="action" value="create_draft">
<div class="row"><label>Cabang</label><select name="branch_id"><?php foreach($branches as $b): ?><option value="<?php echo e((string)$b['id']); ?>" <?php echo (int)$b['id']===(int)($_GET['branch_id'] ?? active_branch_id())?'selected':''; ?>><?php echo e($b['branch_name']); ?></option><?php endforeach; ?></select></div>
<div class="row"><label>Tanggal Opname</label><input type="date" name="opname_date" value="<?php echo e(date('Y-m-d')); ?>" required></div>
<div class="row"><label>Jenis Produk</label><select name="product_type"><option value="">Raw+Finished</option><option value="raw_material">Raw Material</option><option value="finished_good">Finished Good</option></select></div>
<div class="row"><label>Search</label><input type="text" name="search" placeholder="Nama/kategori/kode"></div>
<div class="row"><label>Kategori</label><select name="category"><option value="">Semua</option><?php foreach($categories as $c): ?><option value="<?php echo e((string)$c['category']); ?>"><?php echo e((string)$c['category']); ?></option><?php endforeach; ?></select></div>
<div class="row"><label>Catatan</label><input type="text" name="notes" placeholder="Catatan umum"></div>
<div class="row" style="align-self:end"><button class="btn" type="submit">Generate Draft</button></div>
</form>
</div>
<?php else: ?>
<div class="card"><h3>Detail Opname <?php echo e((string)$header['opname_no']); ?></h3>
<div class="grid cols-4">
<div class="row"><label>Status</label><div><span class="badge"><?php echo e((string)$header['status']); ?></span></div></div>
<div class="row"><label>Cabang</label><div><?php echo e((string)$header['branch_name']); ?></div></div>
<div class="row"><label>Tanggal</label><div><?php echo e((string)$header['opname_date']); ?></div></div>
<div class="row"><label>Petugas</label><div><?php echo e((string)($header['creator_name'] ?? '-')); ?></div></div>
</div>
<?php if(!empty($header['approval_note'])): ?><p style="margin-top:8px"><strong>Catatan Approval:</strong> <?php echo e((string)$header['approval_note']); ?></p><?php endif; ?>
</div>

<div class="card">
<form method="post">
<input type="hidden" name="_csrf" value="<?php echo e(csrf_token()); ?>"><input type="hidden" name="action" value="save_items"><input type="hidden" name="id" value="<?php echo e((string)$id); ?>">
<table class="table"><thead><tr><th>Barang</th><th>Sistem Qty</th><th>Physical Qty</th><th>Variance</th><th>Warning</th><th>Alasan Selisih</th><th>Catatan</th></tr></thead><tbody>
<?php foreach($items as $idx => $it):
  $variance = (float)$it['variance_qty'];
  $needsWarning = stock_variance_needs_warning($variance);
  $unitMeta = product_unit_fallback($it);
?>
<tr>
  <td>
    <?php echo e((string)$it['product_name']); ?>
    <input type="hidden" name="item_id[]" value="<?php echo e((string)$it['id']); ?>">
    <input type="hidden" name="system_qty[]" value="<?php echo e((string)$it['system_qty']); ?>">
  </td>
  <td><?php echo e(format_qty((float)$it['system_qty'], $unitMeta['base_unit'])); ?></td>
  <td><input type="number" step="0.0001" min="0" name="physical_qty[]" value="<?php echo e((string)$it['physical_qty']); ?>" <?php echo !$isDraft?'readonly':''; ?> required><small><?php echo e('Input dalam ' . $unitMeta['base_unit']); ?></small></td>
  <td><span class="badge"><?php echo e(format_qty((float)$variance, $unitMeta['base_unit'])); ?></span></td>
  <td><?php if($needsWarning): ?><span class="badge" style="background:#fff7ed;border-color:#fdba74;color:#9a3412">Selisih > <?php echo e(format_qty(stock_opname_warning_threshold(), $unitMeta['base_unit'])); ?></span><?php else: ?>-<?php endif; ?></td>
  <td><input type="text" name="reason_note[]" value="<?php echo e((string)($it['reason_note'] ?? '')); ?>" <?php echo !$isDraft?'readonly':''; ?> placeholder="Wajib jika variance != 0"></td>
  <td><input type="text" name="line_note[]" value="<?php echo e((string)($it['line_note'] ?? '')); ?>" <?php echo !$isDraft?'readonly':''; ?>></td>
</tr>
<?php endforeach; ?>
</tbody></table>
<?php if($isDraft): ?><button class="btn" type="submit">Simpan Draft</button><?php endif; ?>
<a class="btn btn-light" href="<?php echo e(base_url('admin/stock_opname.php')); ?>">Kembali</a>
</form>
<?php if($isDraft): ?>
<form method="post" style="margin-top:10px"><input type="hidden" name="_csrf" value="<?php echo e(csrf_token()); ?>"><input type="hidden" name="action" value="submit"><input type="hidden" name="id" value="<?php echo e((string)$id); ?>"><button class="btn" type="submit">Submit Menunggu Approval</button></form>
<?php endif; ?>
</div>
<?php endif; ?>

</div></div></div>
<script defer src="<?php echo e(asset_url('assets/app.js')); ?>"></script>
</body></html>
