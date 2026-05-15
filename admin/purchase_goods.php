<?php
require_once __DIR__ . '/../core/db.php';
require_once __DIR__ . '/../core/functions.php';
require_once __DIR__ . '/../core/security.php';
require_once __DIR__ . '/../core/auth.php';
require_once __DIR__ . '/../core/csrf.php';
require_once __DIR__ . '/../core/rbac.php';
require_once __DIR__ . '/../core/inventory.php';

start_secure_session();
require_admin();
ensure_rbac_schema();
$u = require_menu_access('purchase', 'view');
ensure_inventory_module_schema();
csrf_token();

$err = '';
$msg = '';
$branchId = (int)($_GET['branch_id'] ?? active_branch_id());
$search = trim((string)($_GET['search'] ?? ''));
$category = trim((string)($_GET['category'] ?? ''));
$branches = inventory_branches();
$categories = stock_categories();
$suppliers = db()->query("SELECT id,supplier_name FROM suppliers WHERE is_active=1 ORDER BY supplier_name ASC")->fetchAll(PDO::FETCH_ASSOC) ?: [];
$products = store_goods_for_purchase($branchId, $search, $category);

function purchase_goods_no(PDO $db): string {
  $prefix = 'PB-' . date('Ymd-His');
  for ($i=0; $i<10; $i++) {
    $no = $prefix . '-' . strtoupper(bin2hex(random_bytes(2)));
    $stmt = $db->prepare("SELECT id FROM purchase_headers WHERE purchase_no=? LIMIT 1");
    $stmt->execute([$no]);
    if (!$stmt->fetch()) return $no;
  }
  return $prefix . '-' . strtoupper(bin2hex(random_bytes(4)));
}

function purchase_goods_collect_items(array $src): array {
  $productIds = $src['item_product_id'] ?? [];
  $qtys = $src['item_qty'] ?? [];
  $costs = $src['item_unit_cost'] ?? [];
  $notes = $src['item_notes'] ?? [];
  if (!is_array($productIds)) $productIds = [];
  if (!is_array($qtys)) $qtys = [];
  if (!is_array($costs)) $costs = [];
  if (!is_array($notes)) $notes = [];
  $items = [];
  $max = max(count($productIds), count($qtys), count($costs), count($notes));
  for ($i=0; $i<$max; $i++) {
    $productId = (int)($productIds[$i] ?? 0);
    $qty = parse_number_input($qtys[$i] ?? 0);
    $unitCost = parse_number_input($costs[$i] ?? 0);
    $note = trim((string)($notes[$i] ?? ''));
    if ($productId <= 0 && $qty <= 0 && $unitCost <= 0 && $note === '') continue;
    if ($productId <= 0) throw new Exception('Produk wajib dipilih pada setiap baris pembelian barang.');
    if ($qty <= 0) throw new Exception('Qty pembelian harus lebih dari 0.');
    if ($unitCost < 0) throw new Exception('Harga beli tidak boleh negatif.');
    $items[] = [
      'product_id' => $productId,
      'qty' => $qty,
      'unit_cost' => $unitCost,
      'line_total' => $qty * $unitCost,
      'notes' => $note,
    ];
  }
  if (!$items) throw new Exception('Minimal 1 item pembelian wajib diisi.');

  $ids = array_values(array_unique(array_map(static function($it){ return (int)$it['product_id']; }, $items)));
  $in = implode(',', array_fill(0, count($ids), '?'));
  $stmt = db()->prepare("SELECT * FROM products WHERE id IN ($in)");
  $stmt->execute($ids);
  $map = [];
  foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $p) $map[(int)$p['id']] = $p;
  foreach ($items as $idx => $it) {
    $p = $map[(int)$it['product_id']] ?? null;
    if (!$p) throw new Exception('Produk pembelian tidak ditemukan.');
    if (($p['product_type'] ?? '') !== 'finished_good') {
      throw new Exception('Pembelian barang toko hanya boleh memakai produk jadi.');
    }
    $meta = product_unit_fallback($p);
    $items[$idx]['item_name'] = (string)($p['name'] ?? '');
    $items[$idx]['base_unit'] = $meta['base_unit'];
    $items[$idx]['purchase_unit'] = $meta['purchase_unit'];
    $items[$idx]['factor'] = max(0.000001, (float)$meta['purchase_to_base_factor']);
    $items[$idx]['qty_base'] = round((float)$it['qty'] * (float)$items[$idx]['factor'], 4);
  }
  return $items;
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
  csrf_check();
  require_action_access('purchase', 'create');
  try {
    $postNow = (string)($_POST['post_now'] ?? '1') === '1';
    $branchPost = (int)($_POST['branch_id'] ?? active_branch_id());
    $supplierId = (int)($_POST['supplier_id'] ?? 0);
    $purchaseNo = trim((string)($_POST['purchase_no'] ?? ''));
    $date = (string)($_POST['purchase_date'] ?? date('Y-m-d'));
    $notes = trim((string)($_POST['notes'] ?? ''));
    if ($branchPost <= 0) throw new Exception('Cabang wajib dipilih.');
    if ($supplierId <= 0) throw new Exception('Supplier wajib dipilih.');
    if ($purchaseNo === '') $purchaseNo = purchase_goods_no(db());
    $items = purchase_goods_collect_items($_POST);
    $total = 0;
    foreach ($items as $it) $total += (float)$it['line_total'];

    $db = db();
    $db->beginTransaction();

    // Barang yang dibeli dari menu toko otomatis dijadikan stockable,
    // supaya pembelian berikutnya, kartu stok, dan stok opname memakai logika yang sama.
    $markStockStmt = $db->prepare("UPDATE products SET track_stock=1, allow_direct_purchase=1 WHERE id=? AND product_type='finished_good'");
    foreach ($items as $it) {
      $markStockStmt->execute([(int)$it['product_id']]);
    }

    $status = $postNow ? 'posted' : 'draft';
    $stmt = $db->prepare("INSERT INTO purchase_headers (branch_id,supplier_id,purchase_no,purchase_date,purchase_type,status,subtotal,grand_total,notes,created_by,posted_by,posted_at) VALUES (?,?,?,?, 'general',?,?,?,?,?,?,?)");
    $stmt->execute([
      $branchPost,
      $supplierId,
      $purchaseNo,
      $date,
      $status,
      $total,
      $total,
      $notes !== '' ? $notes : null,
      (int)($u['id'] ?? 0),
      $postNow ? (int)($u['id'] ?? 0) : null,
      $postNow ? date('Y-m-d H:i:s') : null,
    ]);
    $purchaseId = (int)$db->lastInsertId();

    $itemStmt = $db->prepare("INSERT INTO purchase_items (purchase_id,product_id,item_name,qty,unit_cost,line_total,notes) VALUES (?,?,?,?,?,?,?)");
    foreach ($items as $it) {
      $itemStmt->execute([$purchaseId, $it['product_id'], $it['item_name'], $it['qty'], $it['unit_cost'], $it['line_total'], $it['notes'] !== '' ? $it['notes'] : null]);
      if ($postNow) {
        add_stock_ledger([
          'branch_id' => $branchPost,
          'product_id' => (int)$it['product_id'],
          'trans_type' => 'store_purchase',
          'ref_table' => 'purchase_headers',
          'ref_id' => $purchaseId,
          'qty_in' => (float)$it['qty_base'],
          'qty_out' => 0,
          'unit_cost' => (float)$it['unit_cost'],
          'note' => 'Pembelian barang toko ' . $purchaseNo,
          'created_by' => (int)($u['id'] ?? 0),
        ]);
      }
    }
    $db->commit();
    $msg = $postNow ? 'Pembelian barang berhasil disimpan dan stok bertambah.' : 'Draft pembelian barang berhasil disimpan.';
    $branchId = $branchPost;
    $products = store_goods_for_purchase($branchId, $search, $category);
  } catch (Throwable $e) {
    if (isset($db) && $db->inTransaction()) $db->rollBack();
    $err = $e->getMessage();
  }
}

$rows = [];
try {
  $stmt = db()->prepare("SELECT ph.*, b.branch_name, s.supplier_name, COUNT(pi.id) total_items
    FROM purchase_headers ph
    LEFT JOIN branches b ON b.id=ph.branch_id
    LEFT JOIN suppliers s ON s.id=ph.supplier_id
    LEFT JOIN purchase_items pi ON pi.purchase_id=ph.id
    WHERE ph.purchase_type='general' AND ph.branch_id=?
    GROUP BY ph.id
    ORDER BY ph.id DESC LIMIT 100");
  $stmt->execute([$branchId]);
  $rows = $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
} catch (Throwable $e) { $err = $err ?: $e->getMessage(); }

$customCss = setting('custom_css','');
$autoNo = purchase_goods_no(db());
?>
<!doctype html>
<html>
<head>
<meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1">
<title>Pembelian Barang Toko</title>
<link rel="icon" href="<?php echo e(favicon_url()); ?>">
<link rel="stylesheet" href="<?php echo e(asset_url('assets/app.css')); ?>">
<style>
<?php echo $customCss; ?>
.purchase-page{max-width:1380px;margin:0 auto}.purchase-toolbar{display:flex;gap:12px;justify-content:space-between;align-items:flex-end;flex-wrap:wrap}.purchase-grid{display:grid;grid-template-columns:repeat(4,minmax(180px,1fr));gap:12px}.table-shell{overflow:auto;border:1px solid #e2e8f0;border-radius:16px}.goods-table{min-width:1120px;width:100%;border-collapse:separate;border-spacing:0}.goods-table th{position:sticky;top:0;background:#f8fafc;z-index:2;font-size:12px;text-transform:uppercase;letter-spacing:.04em;color:#475569}.goods-table th,.goods-table td{padding:10px 12px;border-bottom:1px solid #e2e8f0}.goods-table select{min-width:320px}.goods-table input{min-height:38px}.num{text-align:right}.muted{color:#64748b;font-size:12px}.total-box{display:flex;justify-content:flex-end;gap:12px;align-items:center;margin-top:12px;font-weight:800}.total-box span{font-size:22px}.action-bar{display:flex;gap:10px;justify-content:flex-end;flex-wrap:wrap;margin-top:12px}.badge-soft{display:inline-flex;border:1px solid #bfdbfe;background:#eff6ff;color:#1d4ed8;border-radius:999px;padding:4px 10px;font-size:12px;font-weight:700}@media(max-width:900px){.purchase-grid{grid-template-columns:1fr}.purchase-toolbar{display:block}.action-bar .btn{width:100%}}
</style>
</head>
<body>
<div class="container"><?php include __DIR__ . '/partials_sidebar.php'; ?>
<div class="main"><div class="topbar"><button class="btn" data-toggle-sidebar type="button">Menu</button><strong>Pembelian Barang Toko</strong></div>
<div class="content purchase-page">
<?php if($err): ?><div class="card" style="border-color:rgba(251,113,133,.35);background:rgba(251,113,133,.10)"><?php echo e($err); ?></div><?php endif; ?>
<?php if($msg): ?><div class="card" style="border-color:#86efac;background:#ecfdf5;color:#166534"><?php echo e($msg); ?></div><?php endif; ?>

<div class="card">
  <div class="purchase-toolbar">
    <div>
      <h3>Pembelian Barang</h3>
      <p class="muted">Untuk toko: barang masuk langsung menambah stok dan tercatat di kartu stok. Raw material dan produksi tidak digunakan di halaman ini.</p>
    </div>
    <form method="get" class="purchase-toolbar">
      <div class="row"><label>Cabang</label><select name="branch_id"><?php foreach($branches as $b): ?><option value="<?php echo e((string)$b['id']); ?>" <?php echo (int)$b['id']===$branchId?'selected':''; ?>><?php echo e($b['branch_name']); ?></option><?php endforeach; ?></select></div>
      <div class="row"><label>Kategori produk</label><select name="category"><option value="">Semua</option><?php foreach($categories as $c): ?><option value="<?php echo e((string)$c['category']); ?>" <?php echo (string)$c['category']===$category?'selected':''; ?>><?php echo e((string)$c['category']); ?></option><?php endforeach; ?></select></div>
      <div class="row"><label>Cari produk</label><input name="search" value="<?php echo e($search); ?>" placeholder="Nama/kategori/kode"></div>
      <div class="row" style="align-self:end"><button class="btn btn-light" type="submit">Filter Produk</button></div>
    </form>
  </div>
</div>

<div class="card">
  <form method="post" id="purchaseForm">
    <input type="hidden" name="_csrf" value="<?php echo e(csrf_token()); ?>">
    <input type="hidden" name="post_now" value="1">
    <div class="purchase-grid">
      <div class="row"><label>No Pembelian</label><input name="purchase_no" value="<?php echo e($autoNo); ?>" required></div>
      <div class="row"><label>Tanggal</label><input type="date" name="purchase_date" value="<?php echo e(date('Y-m-d')); ?>" required></div>
      <div class="row"><label>Cabang</label><select name="branch_id"><?php foreach($branches as $b): ?><option value="<?php echo e((string)$b['id']); ?>" <?php echo (int)$b['id']===$branchId?'selected':''; ?>><?php echo e($b['branch_name']); ?></option><?php endforeach; ?></select></div>
      <div class="row"><label>Supplier</label><select name="supplier_id" required><option value="">- pilih supplier -</option><?php foreach($suppliers as $s): ?><option value="<?php echo e((string)$s['id']); ?>"><?php echo e($s['supplier_name']); ?></option><?php endforeach; ?></select></div>
      <div class="row" style="grid-column:1/-1"><label>Catatan</label><textarea name="notes" placeholder="Catatan pembelian barang"></textarea></div>
    </div>

    <h4>Item Barang</h4>
    <div class="table-shell">
      <table class="goods-table" id="goodsItems">
        <thead><tr><th>Produk</th><th>Stok Saat Ini</th><th>Qty Beli</th><th>Harga Beli</th><th>Subtotal</th><th>Catatan</th><th>Aksi</th></tr></thead>
        <tbody>
          <tr>
            <td><select name="item_product_id[]" class="product-select" required><option value="">- pilih produk -</option><?php foreach($products as $p): $m=product_unit_fallback($p); ?><option value="<?php echo e((string)$p['id']); ?>" data-stock="<?php echo e((string)$p['current_stock']); ?>" data-unit="<?php echo e($m['base_unit']); ?>"><?php echo e($p['name']); ?><?php echo !empty($p['category']) ? ' · '.e((string)$p['category']) : ''; ?></option><?php endforeach; ?></select></td>
            <td class="stock-cell muted">-</td>
            <td><input class="qty num" name="item_qty[]" type="number" step="0.0001" min="0.0001" value="1" required></td>
            <td><input class="cost num" name="item_unit_cost[]" type="number" step="0.01" min="0" value="0" required></td>
            <td class="subtotal num">0</td>
            <td><input name="item_notes[]" placeholder="Opsional"></td>
            <td><button class="btn danger" type="button" onclick="removeItemRow(this)">Hapus</button></td>
          </tr>
        </tbody>
      </table>
    </div>
    <div class="total-box">Grand Total <span id="grandTotal">Rp 0</span></div>
    <div class="action-bar"><button class="btn btn-light" type="button" onclick="addItemRow()">Tambah Item</button><button class="btn" type="submit">Simpan & Tambah Stok</button></div>
  </form>
</div>

<div class="card">
  <h3>Riwayat Pembelian Barang</h3>
  <div class="table-shell">
    <table class="table">
      <thead><tr><th>No</th><th>Tanggal</th><th>Cabang</th><th>Supplier</th><th>Item</th><th>Total</th><th>Status</th></tr></thead>
      <tbody>
      <?php foreach($rows as $r): ?><tr><td><?php echo e($r['purchase_no']); ?></td><td><?php echo e($r['purchase_date']); ?></td><td><?php echo e($r['branch_name'] ?? '-'); ?></td><td><?php echo e($r['supplier_name'] ?? '-'); ?></td><td><?php echo e((string)($r['total_items'] ?? 0)); ?></td><td><?php echo e(format_money((float)$r['grand_total'])); ?></td><td><span class="badge-soft"><?php echo e($r['status']); ?></span></td></tr><?php endforeach; ?>
      <?php if(!$rows): ?><tr><td colspan="7" style="text-align:center;color:#94a3b8">Belum ada pembelian barang.</td></tr><?php endif; ?>
      </tbody>
    </table>
  </div>
</div>
</div></div></div>
<script defer src="<?php echo e(asset_url('assets/app.js')); ?>"></script>
<script>
const nf = new Intl.NumberFormat('id-ID');
function money(v){ return 'Rp ' + nf.format(Math.round(Number(v||0))); }
function rowSubtotal(tr){ return Number(tr.querySelector('.qty')?.value || 0) * Number(tr.querySelector('.cost')?.value || 0); }
function updateRow(tr){
  const opt = tr.querySelector('.product-select')?.selectedOptions[0];
  const stock = opt?.dataset.stock || '';
  const unit = opt?.dataset.unit || '';
  tr.querySelector('.stock-cell').textContent = stock === '' ? '-' : nf.format(Number(stock)) + (unit ? ' ' + unit : '');
  tr.querySelector('.subtotal').textContent = money(rowSubtotal(tr));
}
function updateTotal(){
  let total = 0;
  document.querySelectorAll('#goodsItems tbody tr').forEach(tr => { updateRow(tr); total += rowSubtotal(tr); });
  document.getElementById('grandTotal').textContent = money(total);
}
function addItemRow(){
  const tb = document.querySelector('#goodsItems tbody');
  const tr = tb.rows[0].cloneNode(true);
  tr.querySelectorAll('input').forEach(i => { i.value = i.classList.contains('qty') ? '1' : (i.classList.contains('cost') ? '0' : ''); });
  tr.querySelectorAll('select').forEach(s => s.selectedIndex = 0);
  tb.appendChild(tr);
  bindRow(tr); updateTotal();
}
function removeItemRow(btn){
  const tb = document.querySelector('#goodsItems tbody');
  if (tb.rows.length <= 1) { tb.rows[0].querySelectorAll('input').forEach(i => { i.value = i.classList.contains('qty') ? '1' : (i.classList.contains('cost') ? '0' : ''); }); tb.rows[0].querySelector('select').selectedIndex = 0; }
  else btn.closest('tr').remove();
  updateTotal();
}
function bindRow(tr){ tr.querySelectorAll('input,select').forEach(el => el.addEventListener('input', updateTotal)); tr.querySelectorAll('select').forEach(el => el.addEventListener('change', updateTotal)); }
document.querySelectorAll('#goodsItems tbody tr').forEach(bindRow); updateTotal();
</script>
</body></html>
