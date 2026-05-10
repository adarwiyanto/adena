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
$me = require_menu_access('purchase', 'view');
ensure_inventory_module_schema();
csrf_token();

function adena_general_purchase_schema(): void {
  try { db()->exec("ALTER TABLE purchase_headers ADD COLUMN purchase_type ENUM('raw_material','general') NOT NULL DEFAULT 'raw_material' AFTER purchase_date"); } catch (Throwable $e) {}
  try { db()->exec("ALTER TABLE purchase_items MODIFY product_id INT NULL"); } catch (Throwable $e) {}
  try { db()->exec("ALTER TABLE purchase_items ADD COLUMN item_name VARCHAR(190) NULL AFTER product_id"); } catch (Throwable $e) {}
}
adena_general_purchase_schema();

$u = current_user() ?: [];
$err = '';
$msg = '';
$branches = inventory_branches();
$suppliers = db()->query("SELECT id,supplier_name FROM suppliers WHERE is_active=1 ORDER BY supplier_name ASC")->fetchAll(PDO::FETCH_ASSOC) ?: [];
$products = db()->query("SELECT id,name FROM products WHERE is_active=1 ORDER BY name ASC")->fetchAll(PDO::FETCH_ASSOC) ?: [];

function gp_collect_items(array $src): array {
  $productIds = $src['item_product_id'] ?? [];
  $names = $src['item_name'] ?? [];
  $qtys = $src['item_qty'] ?? [];
  $costs = $src['item_unit_cost'] ?? [];
  $notes = $src['item_notes'] ?? [];
  foreach (['productIds','names','qtys','costs','notes'] as $v) if (!is_array($$v)) $$v = [];
  $items = [];
  $max = max(count($productIds), count($names), count($qtys), count($costs), count($notes));
  for ($i=0; $i<$max; $i++) {
    $pid = (int)($productIds[$i] ?? 0);
    $name = trim((string)($names[$i] ?? ''));
    $qty = parse_number_input($qtys[$i] ?? 0);
    $cost = parse_number_input($costs[$i] ?? 0);
    $note = trim((string)($notes[$i] ?? ''));
    if ($pid <= 0 && $name === '' && $qty <= 0 && $cost <= 0 && $note === '') continue;
    if ($pid <= 0 && $name === '') throw new Exception('Nama barang wajib diisi bila produk dikosongkan.');
    if ($qty <= 0 || $cost < 0) throw new Exception('Qty/harga beli item pembelian umum tidak valid.');
    $items[] = ['product_id'=>$pid > 0 ? $pid : null, 'item_name'=>$name, 'qty'=>$qty, 'unit_cost'=>$cost, 'line_total'=>$qty*$cost, 'notes'=>$note];
  }
  if (!$items) throw new Exception('Minimal 1 item pembelian umum wajib diisi.');
  return $items;
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
  try {
    csrf_check();
    $purchaseNo = trim((string)($_POST['purchase_no'] ?? ''));
    $branchId = (int)($_POST['branch_id'] ?? active_branch_id());
    $supplierId = (int)($_POST['supplier_id'] ?? 0);
    $date = (string)($_POST['purchase_date'] ?? date('Y-m-d'));
    $notes = trim((string)($_POST['notes'] ?? ''));
    if ($purchaseNo === '') throw new Exception('Nomor purchase wajib diisi.');
    if ($supplierId <= 0) throw new Exception('Supplier wajib dipilih.');
    $items = gp_collect_items($_POST);
    $total = array_sum(array_map(static fn($it)=>(float)$it['line_total'], $items));
    $db = db();
    $db->beginTransaction();
    $stmt = $db->prepare("INSERT INTO purchase_headers (branch_id,supplier_id,purchase_no,purchase_date,purchase_type,status,subtotal,grand_total,notes,created_by) VALUES (?,?,?,?, 'general','posted',?,?,?,?)");
    $stmt->execute([$branchId,$supplierId,$purchaseNo,$date,$total,$total,$notes,(int)($u['id'] ?? 0)]);
    $pid = (int)$db->lastInsertId();
    $stmt = $db->prepare("INSERT INTO purchase_items (purchase_id,product_id,item_name,qty,unit_cost,line_total,notes) VALUES (?,?,?,?,?,?,?)");
    foreach ($items as $it) $stmt->execute([$pid,$it['product_id'],$it['item_name'],$it['qty'],$it['unit_cost'],$it['line_total'],$it['notes']]);
    $db->commit();
    $msg = 'Pembelian umum berhasil disimpan.';
  } catch (Throwable $e) { if (db()->inTransaction()) db()->rollBack(); $err = $e->getMessage(); }
}

$rows = [];
try {
  $rows = db()->query("SELECT ph.*, b.branch_name, s.supplier_name FROM purchase_headers ph LEFT JOIN branches b ON b.id=ph.branch_id LEFT JOIN suppliers s ON s.id=ph.supplier_id WHERE ph.purchase_type='general' ORDER BY ph.id DESC LIMIT 100")->fetchAll(PDO::FETCH_ASSOC) ?: [];
} catch (Throwable $e) { $err = $err ?: $e->getMessage(); }
$customCss = setting('custom_css','');
$autoNo = 'PG-' . date('YmdHis');
?>
<!doctype html><html><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"><title>Pembelian Umum</title><link rel="icon" href="<?php echo e(favicon_url()); ?>"><link rel="stylesheet" href="<?php echo e(asset_url('assets/app.css')); ?>"><style><?php echo $customCss; ?></style></head><body><div class="container"><?php include __DIR__ . '/partials_sidebar.php'; ?><div class="main"><div class="topbar"><button class="btn" data-toggle-sidebar type="button">Menu</button><strong>Pembelian Umum</strong></div><div class="content"><?php if($err): ?><div class="alert danger"><?php echo e($err); ?></div><?php endif; ?><?php if($msg): ?><div class="alert success"><?php echo e($msg); ?></div><?php endif; ?><div class="card"><h3>Pembelian Barang Umum / Pihak Ketiga</h3><p style="color:#64748b">Untuk ATK, kebutuhan harian, atau barang pihak ketiga. Kolom produk boleh dikosongkan, isi nama barang manual.</p><form method="post"><?php echo csrf_field(); ?><div class="grid2"><label>No Purchase<input name="purchase_no" value="<?php echo e($autoNo); ?>" required></label><label>Tanggal<input type="date" name="purchase_date" value="<?php echo e(date('Y-m-d')); ?>" required></label><label>Cabang<select name="branch_id"><?php foreach($branches as $b): ?><option value="<?php echo e((string)$b['id']); ?>"><?php echo e($b['branch_name'] ?? $b['name'] ?? ('Cabang '.$b['id'])); ?></option><?php endforeach; ?></select></label><label>Supplier<select name="supplier_id" required><option value="">- pilih supplier -</option><?php foreach($suppliers as $s): ?><option value="<?php echo e((string)$s['id']); ?>"><?php echo e($s['supplier_name']); ?></option><?php endforeach; ?></select></label></div><label>Catatan<textarea name="notes" placeholder="Catatan pembelian umum"></textarea></label><h4>Item</h4><table class="table" id="items"><thead><tr><th>Produk Opsional</th><th>Nama Barang Manual</th><th>Qty</th><th>Harga</th><th>Catatan</th><th>Aksi</th></tr></thead><tbody><tr><td><select name="item_product_id[]"><option value="">- kosongkan/manual -</option><?php foreach($products as $p): ?><option value="<?php echo e((string)$p['id']); ?>"><?php echo e($p['name']); ?></option><?php endforeach; ?></select></td><td><input name="item_name[]" placeholder="ATK / plastik / jasa / lainnya"></td><td><input name="item_qty[]" type="number" step="0.0001" value="1"></td><td><input name="item_unit_cost[]" type="number" step="0.01" value="0"></td><td><input name="item_notes[]"></td><td><button class="btn btn-danger" type="button" onclick="this.closest('tr').remove()">Hapus</button></td></tr></tbody></table><button class="btn btn-light" type="button" onclick="addItem()">Tambah Item</button> <button class="btn" type="submit">Simpan</button></form></div><div class="card"><h3>Riwayat Pembelian Umum</h3><table class="table"><thead><tr><th>No</th><th>Tanggal</th><th>Cabang</th><th>Supplier</th><th>Total</th><th>Status</th></tr></thead><tbody><?php foreach($rows as $r): ?><tr><td><?php echo e($r['purchase_no']); ?></td><td><?php echo e($r['purchase_date']); ?></td><td><?php echo e($r['branch_name'] ?? '-'); ?></td><td><?php echo e($r['supplier_name'] ?? '-'); ?></td><td><?php echo e(number_format((float)$r['grand_total'],0,',','.')); ?></td><td><?php echo e($r['status']); ?></td></tr><?php endforeach; if(!$rows): ?><tr><td colspan="6" style="text-align:center;color:#94a3b8">Belum ada pembelian umum.</td></tr><?php endif; ?></tbody></table></div></div></div></div><script src="<?php echo e(asset_url('assets/app.js')); ?>"></script><script>function addItem(){const tb=document.querySelector('#items tbody'); const tr=tb.rows[0].cloneNode(true); tr.querySelectorAll('input').forEach(i=>{i.value=i.name.includes('qty')?'1':(i.name.includes('unit_cost')?'0':'')}); tr.querySelectorAll('select').forEach(s=>s.selectedIndex=0); tb.appendChild(tr);}</script></body></html>
