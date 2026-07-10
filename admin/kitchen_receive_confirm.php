<?php
require_once __DIR__ . '/../core/db.php';
require_once __DIR__ . '/../core/functions.php';
require_once __DIR__ . '/../core/security.php';
require_once __DIR__ . '/../core/csrf.php';
require_once __DIR__ . '/../core/auth.php';
require_once __DIR__ . '/../core/rbac.php';
require_once __DIR__ . '/../core/inventory.php';

start_secure_session();
require_admin();
$u = current_user() ?? [];

/*
 * Halaman ini sengaja tidak menjalankan ensure_inventory_module_schema()
 * atau SQL patch besar saat GET. Dulu ini membuat halaman terasa menggantung
 * dan sidebar seperti tidak bisa diklik pada shared hosting.
 */
ensure_rbac_schema();
$role = resolve_user_role($u);
$roleKey = (string)($role['role_key'] ?? ($u['role'] ?? ''));
$canKitchenReceive = in_array($roleKey, ['owner','admin','manager_cabang'], true)
  || has_menu_access($u, 'inventori', 'approve')
  || has_menu_access($u, 'inventori', 'edit');
if (!$canKitchenReceive) {
  redirect_to_best_allowed_page($u, 'menu:kitchen_receive_confirm');
}

function kr_db(): PDO { return db(); }
function kr_money($v): string { return 'Rp ' . number_format((float)$v, 0, ',', '.'); }
function kr_qty($v): string {
  $n = (float)$v;
  $s = number_format($n, 4, ',', '.');
  $s = rtrim(rtrim($s, '0'), ',');
  return $s === '' ? '0' : $s;
}
function kr_num($v): string {
  $n = (float)$v;
  $s = number_format($n, 4, '.', '');
  return rtrim(rtrim($s, '0'), '.');
}
function kr_decimal($v): float {
  $s = trim((string)$v);
  if ($s === '') return 0.0;
  $s = str_replace(' ', '', $s);
  if (strpos($s, ',') !== false && strpos($s, '.') !== false) {
    $s = str_replace('.', '', $s);
    $s = str_replace(',', '.', $s);
  } elseif (strpos($s, ',') !== false) {
    $s = str_replace(',', '.', $s);
  }
  $s = preg_replace('/[^0-9.\-]/', '', $s);
  return (float)$s;
}
function kr_safe_exec(string $sql): void { try { kr_db()->exec($sql); } catch (Throwable $e) {} }
function kr_column_exists(string $table, string $column): bool {
  try {
    $st = kr_db()->prepare("SHOW COLUMNS FROM `" . str_replace('`','',$table) . "` LIKE ?");
    $st->execute([$column]);
    return (bool)$st->fetch(PDO::FETCH_ASSOC);
  } catch (Throwable $e) { return false; }
}
function kr_table_exists(string $table): bool {
  try {
    $st = kr_db()->prepare('SHOW TABLES LIKE ?');
    $st->execute([$table]);
    return (bool)$st->fetchColumn();
  } catch (Throwable $e) { return false; }
}
function kr_has_review_columns(): bool {
  return kr_column_exists('kitchen_api_received_items', 'reviewed_qty')
    && kr_column_exists('kitchen_api_received_items', 'review_status')
    && kr_column_exists('kitchen_api_receive_logs', 'reviewed_by');
}
function kr_bootstrap_minimal_schema(): void {
  /* Dipanggil hanya saat POST kalau struktur belum ada. Ringan dan terbatas. */
  kr_safe_exec("CREATE TABLE IF NOT EXISTS kitchen_api_receive_logs (
    id BIGINT AUTO_INCREMENT PRIMARY KEY,
    token_id INT NULL,
    branch_id INT NULL,
    supplier_id INT NULL,
    transfer_no VARCHAR(80) NULL,
    endpoint VARCHAR(160) NULL,
    status VARCHAR(40) NOT NULL DEFAULT 'pending_confirmation',
    purchase_id INT NULL,
    purchase_no VARCHAR(80) NULL,
    message TEXT NULL,
    payload_json LONGTEXT NULL,
    remote_ip VARCHAR(80) NULL,
    confirmed_by INT NULL,
    confirmed_at DATETIME NULL,
    returned_by INT NULL,
    returned_at DATETIME NULL,
    return_note TEXT NULL,
    reviewed_by INT NULL,
    reviewed_at DATETIME NULL,
    review_note TEXT NULL,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    UNIQUE KEY uq_transfer_no(transfer_no)
  ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
  kr_safe_exec("CREATE TABLE IF NOT EXISTS kitchen_api_received_items (
    id BIGINT AUTO_INCREMENT PRIMARY KEY,
    log_id BIGINT NOT NULL,
    product_id INT NOT NULL,
    sku VARCHAR(100) NULL,
    product_name VARCHAR(180) NULL,
    qty DECIMAL(18,4) NOT NULL DEFAULT 0,
    qty_base DECIMAL(18,4) NOT NULL DEFAULT 0,
    unit VARCHAR(50) NULL,
    transfer_price DECIMAL(18,2) DEFAULT 0,
    unit_cost DECIMAL(18,2) DEFAULT 0,
    line_total DECIMAL(18,2) DEFAULT 0,
    review_status VARCHAR(30) NOT NULL DEFAULT 'unchecked',
    reviewed_product_id INT NULL,
    reviewed_qty DECIMAL(18,4) NULL,
    reviewed_qty_base DECIMAL(18,4) NULL,
    reviewed_unit VARCHAR(50) NULL,
    reviewed_unit_cost DECIMAL(18,2) NULL,
    reviewed_line_total DECIMAL(18,2) NULL,
    review_note VARCHAR(255) NULL,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    KEY idx_log_id(log_id)
  ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
  $cols = [
    ['kitchen_api_receive_logs','branch_id','ALTER TABLE kitchen_api_receive_logs ADD COLUMN branch_id INT NULL AFTER token_id'],
    ['kitchen_api_receive_logs','supplier_id','ALTER TABLE kitchen_api_receive_logs ADD COLUMN supplier_id INT NULL AFTER branch_id'],
    ['kitchen_api_receive_logs','purchase_id','ALTER TABLE kitchen_api_receive_logs ADD COLUMN purchase_id INT NULL AFTER status'],
    ['kitchen_api_receive_logs','purchase_no','ALTER TABLE kitchen_api_receive_logs ADD COLUMN purchase_no VARCHAR(80) NULL AFTER purchase_id'],
    ['kitchen_api_receive_logs','confirmed_by','ALTER TABLE kitchen_api_receive_logs ADD COLUMN confirmed_by INT NULL AFTER remote_ip'],
    ['kitchen_api_receive_logs','confirmed_at','ALTER TABLE kitchen_api_receive_logs ADD COLUMN confirmed_at DATETIME NULL AFTER confirmed_by'],
    ['kitchen_api_receive_logs','returned_by','ALTER TABLE kitchen_api_receive_logs ADD COLUMN returned_by INT NULL AFTER confirmed_at'],
    ['kitchen_api_receive_logs','returned_at','ALTER TABLE kitchen_api_receive_logs ADD COLUMN returned_at DATETIME NULL AFTER returned_by'],
    ['kitchen_api_receive_logs','return_note','ALTER TABLE kitchen_api_receive_logs ADD COLUMN return_note TEXT NULL AFTER returned_at'],
    ['kitchen_api_receive_logs','reviewed_by','ALTER TABLE kitchen_api_receive_logs ADD COLUMN reviewed_by INT NULL AFTER return_note'],
    ['kitchen_api_receive_logs','reviewed_at','ALTER TABLE kitchen_api_receive_logs ADD COLUMN reviewed_at DATETIME NULL AFTER reviewed_by'],
    ['kitchen_api_receive_logs','review_note','ALTER TABLE kitchen_api_receive_logs ADD COLUMN review_note TEXT NULL AFTER reviewed_at'],
    ['kitchen_api_received_items','qty_base','ALTER TABLE kitchen_api_received_items ADD COLUMN qty_base DECIMAL(18,4) NOT NULL DEFAULT 0 AFTER qty'],
    ['kitchen_api_received_items','unit_cost','ALTER TABLE kitchen_api_received_items ADD COLUMN unit_cost DECIMAL(18,2) DEFAULT 0 AFTER transfer_price'],
    ['kitchen_api_received_items','line_total','ALTER TABLE kitchen_api_received_items ADD COLUMN line_total DECIMAL(18,2) DEFAULT 0 AFTER unit_cost'],
    ['kitchen_api_received_items','review_status','ALTER TABLE kitchen_api_received_items ADD COLUMN review_status VARCHAR(30) NOT NULL DEFAULT \'unchecked\' AFTER line_total'],
    ['kitchen_api_received_items','reviewed_product_id','ALTER TABLE kitchen_api_received_items ADD COLUMN reviewed_product_id INT NULL AFTER review_status'],
    ['kitchen_api_received_items','reviewed_qty','ALTER TABLE kitchen_api_received_items ADD COLUMN reviewed_qty DECIMAL(18,4) NULL AFTER reviewed_product_id'],
    ['kitchen_api_received_items','reviewed_qty_base','ALTER TABLE kitchen_api_received_items ADD COLUMN reviewed_qty_base DECIMAL(18,4) NULL AFTER reviewed_qty'],
    ['kitchen_api_received_items','reviewed_unit','ALTER TABLE kitchen_api_received_items ADD COLUMN reviewed_unit VARCHAR(50) NULL AFTER reviewed_qty_base'],
    ['kitchen_api_received_items','reviewed_unit_cost','ALTER TABLE kitchen_api_received_items ADD COLUMN reviewed_unit_cost DECIMAL(18,2) NULL AFTER reviewed_unit'],
    ['kitchen_api_received_items','reviewed_line_total','ALTER TABLE kitchen_api_received_items ADD COLUMN reviewed_line_total DECIMAL(18,2) NULL AFTER reviewed_unit_cost'],
    ['kitchen_api_received_items','review_note','ALTER TABLE kitchen_api_received_items ADD COLUMN review_note VARCHAR(255) NULL AFTER reviewed_line_total'],
  ];
  foreach ($cols as $c) if (!kr_column_exists($c[0], $c[1])) kr_safe_exec($c[2]);
}
function kr_supplier_id(): int {
  $st = kr_db()->prepare('SELECT id FROM suppliers WHERE supplier_code=? LIMIT 1');
  $st->execute(['DAPUR_ADENA']);
  $id = (int)$st->fetchColumn();
  if ($id > 0) return $id;
  kr_db()->prepare('INSERT INTO suppliers(supplier_code,supplier_name,is_active) VALUES(?,?,1)')->execute(['DAPUR_ADENA','Dapur Adena']);
  return (int)kr_db()->lastInsertId();
}
function kr_purchase_no(string $transferNo, int $logId): string {
  $base = preg_replace('/[^A-Za-z0-9\-_.]/', '-', $transferNo);
  $no = 'KD-' . substr($base ?: '', 0, 42);
  if (strlen($no) > 50 || $no === 'KD-') $no = 'KD-' . date('Ymd') . '-' . $logId;
  $st = kr_db()->prepare('SELECT id FROM purchase_headers WHERE purchase_no=? LIMIT 1');
  $st->execute([$no]);
  return $st->fetchColumn() ? 'KD-' . date('Ymd') . '-' . $logId : $no;
}
function kr_flash(string $message, string $type='ok'): void { $_SESSION['kitchen_receive_flash'] = [$message, $type]; }
function kr_get_flash(): ?array { $f = $_SESSION['kitchen_receive_flash'] ?? null; unset($_SESSION['kitchen_receive_flash']); return $f; }
function kr_receive_log_status_label(string $status): string {
  $map = [
    'pending_confirmation' => 'Pending Review Cabang',
    'reviewed' => 'Sudah Direview',
    'confirmed' => 'Diterima',
    'returned_to_kitchen' => 'Dikembalikan ke Dapur',
    'failed' => 'Failed',
  ];
  return $map[$status] ?? $status;
}
function kr_review_item_rows(int $logId, array $postedItems): array {
  $st = kr_db()->prepare('SELECT * FROM kitchen_api_received_items WHERE log_id=? ORDER BY id');
  $st->execute([$logId]);
  $rows = $st->fetchAll(PDO::FETCH_ASSOC) ?: [];
  $out = [];
  foreach ($rows as $r) {
    $iid = (int)$r['id'];
    $p = is_array($postedItems[$iid] ?? null) ? $postedItems[$iid] : [];
    $productId = (int)($p['product_id'] ?? ($r['reviewed_product_id'] ?: $r['product_id']));
    $qty = array_key_exists('qty', $p) ? kr_decimal($p['qty']) : (float)($r['reviewed_qty'] ?? $r['qty']);
    $qtyBase = $qty;
    $unit = trim((string)($p['unit'] ?? ($r['reviewed_unit'] ?: $r['unit'])));
    $cost = array_key_exists('unit_cost', $p) ? kr_decimal($p['unit_cost']) : (float)($r['reviewed_unit_cost'] ?? $r['unit_cost'] ?? $r['transfer_price']);
    $status = (string)($p['review_status'] ?? ($r['review_status'] ?: 'ok'));
    if (!in_array($status, ['ok','corrected','problem'], true)) $status = 'ok';
    $note = trim((string)($p['note'] ?? ($r['review_note'] ?? '')));
    if ($productId <= 0) throw new Exception('Produk cabang wajib dipilih pada item: ' . ($r['product_name'] ?? $iid));
    if ($qty < 0) throw new Exception('Qty review tidak boleh minus pada item: ' . ($r['product_name'] ?? $iid));
    if ($cost < 0) throw new Exception('Harga review tidak boleh minus pada item: ' . ($r['product_name'] ?? $iid));
    $r['_reviewed_product_id'] = $productId;
    $r['_reviewed_qty'] = $qty;
    $r['_reviewed_qty_base'] = $qtyBase;
    $r['_reviewed_unit'] = $unit;
    $r['_reviewed_unit_cost'] = $cost;
    $r['_reviewed_line_total'] = $qty * $cost;
    $r['_review_status'] = $status;
    $r['_review_note'] = $note;
    $out[] = $r;
  }
  return $out;
}
function kr_save_review_items(int $logId, array $reviewRows, int $userId, string $globalNote=''): void {
  $up = kr_db()->prepare('UPDATE kitchen_api_received_items
    SET reviewed_product_id=?, reviewed_qty=?, reviewed_qty_base=?, reviewed_unit=?, reviewed_unit_cost=?, reviewed_line_total=?, review_status=?, review_note=?
    WHERE id=? AND log_id=?');
  foreach ($reviewRows as $r) {
    $up->execute([
      (int)$r['_reviewed_product_id'],
      (float)$r['_reviewed_qty'],
      (float)$r['_reviewed_qty_base'],
      (string)$r['_reviewed_unit'],
      (float)$r['_reviewed_unit_cost'],
      (float)$r['_reviewed_line_total'],
      (string)$r['_review_status'],
      (string)$r['_review_note'],
      (int)$r['id'],
      $logId,
    ]);
  }
  kr_db()->prepare('UPDATE kitchen_api_receive_logs SET reviewed_by=?, reviewed_at=NOW(), review_note=? WHERE id=?')
    ->execute([$userId, $globalNote, $logId]);
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
  csrf_check();
  kr_bootstrap_minimal_schema();
  $action = (string)($_POST['action'] ?? '');
  $id = (int)($_POST['id'] ?? 0);
  if ($id > 0 && in_array($action, ['save_review','confirm','return'], true)) {
    try {
      kr_db()->beginTransaction();
      $st = kr_db()->prepare('SELECT * FROM kitchen_api_receive_logs WHERE id=? FOR UPDATE');
      $st->execute([$id]);
      $log = $st->fetch(PDO::FETCH_ASSOC);
      if (!$log) throw new Exception('Transfer tidak ditemukan.');
      if ((string)$log['status'] !== 'pending_confirmation') throw new Exception('Status transfer tidak bisa diproses: ' . $log['status']);

      if ($action === 'return') {
        $note = trim((string)($_POST['return_note'] ?? ''));
        if ($note === '') throw new Exception('Catatan koreksi wajib diisi saat mengembalikan kiriman ke dapur.');
        kr_db()->prepare('UPDATE kitchen_api_receive_logs SET status=?, message=?, returned_by=?, returned_at=NOW(), return_note=? WHERE id=?')
          ->execute(['returned_to_kitchen', 'Dikembalikan ke dapur untuk dikoreksi: ' . $note, (int)($u['id'] ?? 0), $note, $id]);
        kr_db()->commit();
        kr_flash('Kiriman dikembalikan ke dapur untuk koreksi. Stok cabang belum bertambah.', 'ok');
        redirect(base_url('admin/kitchen_receive_confirm.php?status=returned_to_kitchen'));
      }

      $reviewRows = kr_review_item_rows($id, is_array($_POST['items'] ?? null) ? $_POST['items'] : []);
      if (!$reviewRows) throw new Exception('Item transfer kosong.');
      $globalNote = trim((string)($_POST['review_note'] ?? ''));
      kr_save_review_items($id, $reviewRows, (int)($u['id'] ?? 0), $globalNote);

      if ($action === 'save_review') {
        kr_db()->commit();
        kr_flash('Review penerimaan stok tersimpan. Belum masuk stok cabang.', 'ok');
        redirect(base_url('admin/kitchen_receive_confirm.php?status=pending_confirmation'));
      }

      $payload = json_decode((string)($log['payload_json'] ?? '{}'), true);
      if (!is_array($payload)) $payload = [];
      $purchaseDate = (string)($payload['transfer_date'] ?? date('Y-m-d'));
      if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $purchaseDate)) $purchaseDate = date('Y-m-d');
      $branchId = (int)($log['branch_id'] ?? 0);
      if ($branchId <= 0) $branchId = function_exists('active_branch_id') ? max(1, (int)active_branch_id()) : 1;
      $supplierId = (int)($log['supplier_id'] ?? 0);
      if ($supplierId <= 0) $supplierId = kr_supplier_id();
      $purchaseNo = kr_purchase_no((string)$log['transfer_no'], $id);
      $total = 0.0;
      $hasPositiveQty = false;
      foreach ($reviewRows as $r) {
        $total += (float)$r['_reviewed_line_total'];
        if ((float)$r['_reviewed_qty_base'] > 0) $hasPositiveQty = true;
      }
      if (!$hasPositiveQty) throw new Exception('Minimal satu item harus memiliki qty diterima lebih dari 0 untuk approve.');

      $ph = kr_db()->prepare("INSERT INTO purchase_headers (branch_id,supplier_id,purchase_no,purchase_date,purchase_type,status,subtotal,grand_total,notes,created_by,posted_by,posted_at)
        VALUES (?,?,?,?, 'general','posted',?,?,?,?,?,NOW())");
      $ph->execute([$branchId, $supplierId, $purchaseNo, $purchaseDate, $total, $total, 'Konfirmasi penerimaan stok dari Dapur Adena ' . ($log['transfer_no'] ?? '') . ($globalNote ? ' | Review: ' . $globalNote : ''), (int)($u['id'] ?? 0), (int)($u['id'] ?? 0)]);
      $purchaseId = (int)kr_db()->lastInsertId();
      $pi = kr_db()->prepare('INSERT INTO purchase_items (purchase_id,product_id,item_name,qty,unit_cost,line_total,notes) VALUES (?,?,?,?,?,?,?)');
      $mark = kr_db()->prepare("UPDATE products SET track_stock=1, allow_direct_purchase=1 WHERE id=? AND product_type='finished_good'");
      foreach ($reviewRows as $r) {
        $productId = (int)$r['_reviewed_product_id'];
        $qty = (float)$r['_reviewed_qty'];
        $qtyBase = (float)$r['_reviewed_qty_base'];
        $unitCost = (float)$r['_reviewed_unit_cost'];
        $line = (float)$r['_reviewed_line_total'];
        if ($qtyBase <= 0) continue;
        $mark->execute([$productId]);
        $itemName = (string)($r['product_name'] ?? 'Produk Dapur');
        $note = 'Transfer dari Dapur Adena ' . ($log['transfer_no'] ?? '');
        if (($r['_review_status'] ?? '') !== 'ok') $note .= ' | ' . $r['_review_status'];
        if (!empty($r['_review_note'])) $note .= ' | ' . $r['_review_note'];
        $pi->execute([$purchaseId, $productId, $itemName, $qty, $unitCost, $line, $note]);
        add_stock_ledger([
          'branch_id' => $branchId,
          'product_id' => $productId,
          'trans_type' => 'receive_from_kitchen',
          'ref_table' => 'purchase_headers',
          'ref_id' => $purchaseId,
          'qty_in' => $qtyBase,
          'qty_out' => 0,
          'unit_cost' => $unitCost,
          'note' => 'Penerimaan Dapur Adena ' . $purchaseNo,
          'created_by' => (int)($u['id'] ?? 0),
        ]);
      }
      kr_db()->prepare('UPDATE kitchen_api_receive_logs SET status=?, purchase_id=?, purchase_no=?, message=?, confirmed_by=?, confirmed_at=NOW() WHERE id=?')
        ->execute(['confirmed', $purchaseId, $purchaseNo, 'Stok dikonfirmasi manager cabang dan masuk pembelian ' . $purchaseNo, (int)($u['id'] ?? 0), $id]);
      kr_db()->commit();
      kr_flash('Penerimaan stok dikonfirmasi. Stok sudah masuk: ' . $purchaseNo, 'ok');
    } catch (Throwable $e) {
      if (kr_db()->inTransaction()) kr_db()->rollBack();
      kr_flash('Gagal proses kiriman: ' . $e->getMessage(), 'err');
    }
    redirect(base_url('admin/kitchen_receive_confirm.php'));
  }
}

$schemaReady = kr_table_exists('kitchen_api_receive_logs') && kr_table_exists('kitchen_api_received_items');
$reviewReady = $schemaReady && kr_has_review_columns();
$status = trim((string)($_GET['status'] ?? 'pending_confirmation'));
$allowedStatus = ['pending_confirmation','confirmed','returned_to_kitchen','failed','all'];
if (!in_array($status, $allowedStatus, true)) $status = 'pending_confirmation';
$where = '';
$params = [];
$logs = [];
$itemsByLog = [];
$products = [];
if ($schemaReady) {
  if ($status !== 'all') { $where = 'WHERE l.status=?'; $params[] = $status; }
  if ($reviewReady) {
    $stmt = kr_db()->prepare("SELECT l.*, b.branch_name, u.name confirmed_name, ru.name returned_name, rv.name reviewed_name
      FROM kitchen_api_receive_logs l
      LEFT JOIN branches b ON b.id=l.branch_id
      LEFT JOIN users u ON u.id=l.confirmed_by
      LEFT JOIN users ru ON ru.id=l.returned_by
      LEFT JOIN users rv ON rv.id=l.reviewed_by
      $where
      ORDER BY CASE WHEN l.status='pending_confirmation' THEN 0 ELSE 1 END, l.id DESC
      LIMIT 80");
  } else {
    $stmt = kr_db()->prepare("SELECT l.*, b.branch_name, u.name confirmed_name, ru.name returned_name
      FROM kitchen_api_receive_logs l
      LEFT JOIN branches b ON b.id=l.branch_id
      LEFT JOIN users u ON u.id=l.confirmed_by
      LEFT JOIN users ru ON ru.id=l.returned_by
      $where
      ORDER BY CASE WHEN l.status='pending_confirmation' THEN 0 ELSE 1 END, l.id DESC
      LIMIT 80");
  }
  $stmt->execute($params);
  $logs = $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
  if ($logs) {
    $ids = array_map(fn($r)=>(int)$r['id'], $logs);
    $in = implode(',', array_fill(0, count($ids), '?'));
    $select = $reviewReady ? '*' : 'id,log_id,product_id,sku,product_name,qty,qty_base,unit,transfer_price,unit_cost,line_total,created_at';
    $it = kr_db()->prepare("SELECT $select FROM kitchen_api_received_items WHERE log_id IN ($in) ORDER BY log_id,id");
    $it->execute($ids);
    foreach ($it->fetchAll(PDO::FETCH_ASSOC) ?: [] as $r) $itemsByLog[(int)$r['log_id']][] = $r;
  }
  try {
    $ps = kr_db()->query("SELECT id, name, COALESCE(base_unit,'') base_unit FROM products WHERE product_type='finished_good' ORDER BY name ASC LIMIT 1200");
    $products = $ps ? ($ps->fetchAll(PDO::FETCH_ASSOC) ?: []) : [];
  } catch (Throwable $e) { $products = []; }
}
$f = kr_get_flash();
$customCss = setting('custom_css','');
?>
<!doctype html>
<html>
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width,initial-scale=1">
<title>Konfirmasi Stok Dapur</title>
<link rel="icon" href="<?php echo e(favicon_url()); ?>">
<link rel="stylesheet" href="<?php echo e(asset_url('assets/app.css')); ?>">
<style>
<?php echo $customCss; ?>
.receive-page{max-width:1540px;margin:0 auto;padding:12px 16px 28px}.receive-card{margin-bottom:12px}.receive-head{display:flex;justify-content:space-between;gap:12px;align-items:flex-start}.receive-meta{color:#64748b;font-size:12.5px;line-height:1.45}.receive-filter{display:flex;gap:8px;align-items:end;flex-wrap:wrap}.receive-filter label{font-size:13px;color:#334155}.receive-filter select{height:36px;min-width:170px}.receive-table-wrap{overflow:auto;border:1px solid #e5e7eb;border-radius:12px;background:#fff}.receive-table{min-width:1180px;margin:0}.receive-table th,.receive-table td{vertical-align:top;padding:8px 9px}.receive-table th{font-size:12px;white-space:nowrap}.receive-table td{font-size:13px}.badge-pending{background:#fff7ed;color:#9a3412}.badge-confirmed{background:#ecfdf5;color:#166534}.badge-failed{background:#fff1f2;color:#9f1239}.badge-returned{background:#eff6ff;color:#1d4ed8}.review-grid{display:grid;grid-template-columns:1fr;gap:10px}.review-input,.review-select,.review-note{width:100%;height:34px;border:1px solid #d1d5db;border-radius:8px;padding:6px 8px;background:#fff}.review-note{min-width:170px}.review-product{min-width:260px}.review-small{max-width:110px}.review-price{max-width:130px}.review-actions{display:flex;gap:8px;flex-wrap:wrap;justify-content:flex-end;margin-top:10px}.return-row{display:flex;gap:8px;align-items:center;justify-content:flex-end;margin-top:8px}.return-row input{width:min(420px,100%)}.schema-warn{border-color:#fed7aa!important;background:#fff7ed!important}.receive-summary{display:grid;grid-template-columns:repeat(4,minmax(120px,1fr));gap:8px;margin-top:10px}.receive-summary div{background:#f8fafc;border:1px solid #e5e7eb;border-radius:10px;padding:8px;font-size:13px}.receive-summary strong{display:block;font-size:15px;color:#0f172a}.sidebar-overlay{display:none!important;pointer-events:none!important}body.sidebar-mobile-open .sidebar-overlay{display:block!important;pointer-events:auto!important}@media(max-width:980px){.receive-page{padding:10px}.receive-head{display:block}.review-actions,.return-row{justify-content:flex-start}.return-row{display:block}.return-row input,.return-row button{width:100%;margin-top:6px}.receive-summary{grid-template-columns:1fr 1fr}}@media(max-width:640px){.receive-summary{grid-template-columns:1fr}}
</style>
</head>
<body data-kitchen-receive-page="1"><div class="container"><?php include __DIR__ . '/partials_sidebar.php'; ?><div class="main"><div class="topbar"><button class="btn" data-toggle-sidebar type="button">Menu</button><strong>Konfirmasi Stok Dapur</strong></div><div class="content receive-page">
<?php if($f): ?><div class="card" style="border-color:<?php echo $f[1]==='err'?'#fecdd3':'#bbf7d0'; ?>;background:<?php echo $f[1]==='err'?'#fff1f2':'#f0fdf4'; ?>"><?php echo e($f[0]); ?></div><?php endif; ?>
<?php if(!$schemaReady || !$reviewReady): ?><div class="card schema-warn"><strong>Struktur review penerimaan belum lengkap.</strong><br><span class="receive-meta">Jalankan SQL patch <code>db/update_kitchen_receive_review_20260625.sql</code>. Halaman tetap dibuka, tetapi fitur review/edit penuh baru aktif setelah SQL dijalankan.</span></div><?php endif; ?>
<div class="card receive-card"><div class="receive-head"><div><h3 style="margin:0 0 6px">Review Penerimaan Stok dari Dapur Adena</h3><p class="receive-meta" style="margin:0">Manager cabang mengecek barang fisik, mengedit qty/harga/unit bila perlu, lalu approve. Admin dan Owner hanya backup. Stok cabang belum bertambah sebelum tombol <strong>Approve / Terima Stok</strong>.</p></div><form method="get" class="receive-filter"><label>Status<br><select name="status"><option value="pending_confirmation" <?php echo $status==='pending_confirmation'?'selected':''; ?>>Pending Review</option><option value="confirmed" <?php echo $status==='confirmed'?'selected':''; ?>>Diterima</option><option value="returned_to_kitchen" <?php echo $status==='returned_to_kitchen'?'selected':''; ?>>Dikembalikan</option><option value="failed" <?php echo $status==='failed'?'selected':''; ?>>Failed</option><option value="all" <?php echo $status==='all'?'selected':''; ?>>Semua</option></select></label><button class="btn light" type="submit">Filter</button></form></div></div>
<?php if($schemaReady && !$logs): ?><div class="card">Tidak ada data penerimaan stok untuk filter ini.</div><?php endif; ?>
<?php foreach($logs as $log):
  $items = $itemsByLog[(int)$log['id']] ?? [];
  $grandSent = 0; $grandReview = 0; $totalQty = 0; $reviewedCount = 0;
  foreach($items as $it){
    $sent = (float)($it['line_total'] ?: ((float)$it['qty']*(float)($it['transfer_price'] ?: $it['unit_cost'])));
    $rvQty = isset($it['reviewed_qty']) && $it['reviewed_qty'] !== null ? (float)$it['reviewed_qty'] : (float)$it['qty'];
    $rvCost = isset($it['reviewed_unit_cost']) && $it['reviewed_unit_cost'] !== null ? (float)$it['reviewed_unit_cost'] : (float)($it['unit_cost'] ?: $it['transfer_price']);
    $rvLine = isset($it['reviewed_line_total']) && $it['reviewed_line_total'] !== null ? (float)$it['reviewed_line_total'] : $rvQty*$rvCost;
    $grandSent += $sent; $grandReview += $rvLine; $totalQty += $rvQty;
    if (!empty($it['reviewed_qty']) || !empty($it['review_status']) || !empty($it['review_note'])) $reviewedCount++;
  }
  $cls='badge-pending'; if($log['status']==='confirmed')$cls='badge-confirmed'; elseif($log['status']==='failed')$cls='badge-failed'; elseif($log['status']==='returned_to_kitchen')$cls='badge-returned';
?>
<div class="card receive-card"><div class="receive-head"><div><h3 style="margin:0"><?php echo e((string)$log['transfer_no']); ?> <span class="badge <?php echo e($cls); ?>"><?php echo e(kr_receive_log_status_label((string)$log['status'])); ?></span></h3><div class="receive-meta">Cabang: <?php echo e($log['branch_name'] ?? '-'); ?> • Masuk: <?php echo e((string)$log['created_at']); ?><?php if(!empty($log['reviewed_at'])): ?> • Review: <?php echo e((string)$log['reviewed_at']); ?> oleh <?php echo e($log['reviewed_name'] ?? '-'); ?><?php endif; ?><?php if(!empty($log['confirmed_at'])): ?> • Diterima: <?php echo e((string)$log['confirmed_at']); ?> oleh <?php echo e($log['confirmed_name'] ?? '-'); ?><?php endif; ?><?php if(!empty($log['returned_at'])): ?> • Dikembalikan: <?php echo e((string)$log['returned_at']); ?> oleh <?php echo e($log['returned_name'] ?? '-'); ?><?php endif; ?></div><div class="receive-meta"><?php echo e((string)($log['message'] ?? '')); ?></div><?php if(!empty($log['review_note'])): ?><div class="receive-meta"><strong>Catatan review:</strong> <?php echo e((string)$log['review_note']); ?></div><?php endif; ?><?php if(!empty($log['return_note'])): ?><div class="receive-meta"><strong>Catatan koreksi dapur:</strong> <?php echo e((string)$log['return_note']); ?></div><?php endif; ?></div></div><div class="receive-summary"><div>Nilai kirim<strong><?php echo kr_money($grandSent); ?></strong></div><div>Nilai review<strong><?php echo kr_money($grandReview); ?></strong></div><div>Total qty review<strong><?php echo e(kr_qty($totalQty)); ?></strong></div><div>Item review<strong><?php echo (int)$reviewedCount; ?> / <?php echo count($items); ?></strong></div></div>
<?php if($log['status']==='pending_confirmation'): ?><form method="post" data-no-auto-number-format class="review-grid" style="margin-top:12px"><?php endif; ?>
<input type="hidden" name="_csrf" value="<?php echo e(csrf_token()); ?>"><input type="hidden" name="id" value="<?php echo (int)$log['id']; ?>">
<div class="receive-table-wrap" style="margin-top:12px"><table class="table receive-table"><thead><tr><th>Produk kiriman dapur</th><th>Produk cabang</th><th>SKU</th><th>Qty kirim</th><th>Qty diterima</th><th>Unit</th><th>Harga</th><th>Status cek</th><th>Catatan item</th><th>Subtotal review</th></tr></thead><tbody>
<?php foreach($items as $it):
  $iid=(int)$it['id'];
  $rvProduct=(int)($it['reviewed_product_id'] ?? 0); if($rvProduct<=0) $rvProduct=(int)$it['product_id'];
  $rvQty=isset($it['reviewed_qty']) && $it['reviewed_qty']!==null ? (float)$it['reviewed_qty'] : (float)$it['qty'];
  $rvUnit=(string)($it['reviewed_unit'] ?? ''); if($rvUnit==='') $rvUnit=(string)($it['unit'] ?? '');
  $rvCost=isset($it['reviewed_unit_cost']) && $it['reviewed_unit_cost']!==null ? (float)$it['reviewed_unit_cost'] : (float)($it['unit_cost'] ?: $it['transfer_price']);
  $rvStatus=(string)($it['review_status'] ?? 'ok'); if($rvStatus==='unchecked') $rvStatus='ok';
  $rvNote=(string)($it['review_note'] ?? '');
  $rvLine=isset($it['reviewed_line_total']) && $it['reviewed_line_total']!==null ? (float)$it['reviewed_line_total'] : $rvQty*$rvCost;
?>
<tr><td><strong><?php echo e((string)$it['product_name']); ?></strong><br><span class="receive-meta">ID awal: <?php echo (int)$it['product_id']; ?></span></td><td><?php if($log['status']==='pending_confirmation'): ?><select class="review-select review-product" name="items[<?php echo $iid; ?>][product_id]"><?php foreach($products as $p): ?><option value="<?php echo (int)$p['id']; ?>" <?php echo ((int)$p['id']===$rvProduct)?'selected':''; ?>><?php echo e((string)$p['name']); ?> #<?php echo (int)$p['id']; ?></option><?php endforeach; ?></select><?php else: ?>#<?php echo $rvProduct; ?><?php endif; ?></td><td><?php echo e((string)$it['sku']); ?></td><td><?php echo e(kr_qty($it['qty'])); ?> <?php echo e((string)$it['unit']); ?></td><td><?php if($log['status']==='pending_confirmation'): ?><input class="review-input review-small" name="items[<?php echo $iid; ?>][qty]" value="<?php echo e(kr_num($rvQty)); ?>" inputmode="decimal"><?php else: ?><?php echo e(kr_qty($rvQty)); ?><?php endif; ?></td><td><?php if($log['status']==='pending_confirmation'): ?><input class="review-input review-small" name="items[<?php echo $iid; ?>][unit]" value="<?php echo e($rvUnit); ?>"><?php else: ?><?php echo e($rvUnit); ?><?php endif; ?></td><td><?php if($log['status']==='pending_confirmation'): ?><input class="review-input review-price" name="items[<?php echo $iid; ?>][unit_cost]" value="<?php echo e(kr_num($rvCost)); ?>" inputmode="decimal"><?php else: ?><?php echo kr_money($rvCost); ?><?php endif; ?></td><td><?php if($log['status']==='pending_confirmation'): ?><select class="review-select" name="items[<?php echo $iid; ?>][review_status]"><option value="ok" <?php echo $rvStatus==='ok'?'selected':''; ?>>Cocok</option><option value="corrected" <?php echo $rvStatus==='corrected'?'selected':''; ?>>Dikoreksi</option><option value="problem" <?php echo $rvStatus==='problem'?'selected':''; ?>>Bermasalah</option></select><?php else: ?><?php echo e($rvStatus); ?><?php endif; ?></td><td><?php if($log['status']==='pending_confirmation'): ?><input class="review-input review-note" name="items[<?php echo $iid; ?>][note]" value="<?php echo e($rvNote); ?>" placeholder="Opsional"><?php else: ?><?php echo e($rvNote); ?><?php endif; ?></td><td><?php echo kr_money($rvLine); ?></td></tr>
<?php endforeach; ?>
</tbody></table></div>
<?php if($log['status']==='pending_confirmation'): ?><div class="receive-head" style="margin-top:10px;align-items:end"><label style="flex:1;font-size:13px;color:#334155">Catatan review keseluruhan<br><input class="review-input" name="review_note" value="<?php echo e((string)($log['review_note'] ?? '')); ?>" placeholder="Misal: qty item A dikoreksi sesuai barang fisik"></label><div class="review-actions"><button class="btn light" type="submit" name="action" value="save_review">Simpan Review</button><button class="btn" type="submit" name="action" value="confirm" onclick="return confirm('Approve penerimaan dan masukkan stok cabang berdasarkan qty review?')">Approve / Terima Stok</button></div></div></form><form method="post" data-no-auto-number-format class="return-row" onsubmit="return confirm('Kembalikan kiriman ini ke dapur untuk koreksi? Stok cabang tidak bertambah.')"><input type="hidden" name="_csrf" value="<?php echo e(csrf_token()); ?>"><input type="hidden" name="action" value="return"><input type="hidden" name="id" value="<?php echo (int)$log['id']; ?>"><input class="review-input" name="return_note" placeholder="Catatan koreksi wajib bila dikembalikan ke dapur" required><button class="btn danger" type="submit">Kembalikan ke Dapur</button></form><?php endif; ?>
</div>
<?php endforeach; ?>
</div></div></div>
<script>
(function(){
  document.body.classList.add('is-admin');
  const sidebar=document.querySelector('.sidebar');
  const btn=document.querySelector('[data-toggle-sidebar]');
  let overlay=document.querySelector('.sidebar-overlay');
  if(!overlay && sidebar){overlay=document.createElement('div');overlay.className='sidebar-overlay';document.body.appendChild(overlay);}
  const mq=window.matchMedia('(max-width:980px)');
  function closeSide(){if(!sidebar)return;sidebar.classList.remove('mobile-open');document.body.classList.remove('sidebar-mobile-open');}
  function openSide(){if(!sidebar)return;sidebar.classList.remove('collapsed');sidebar.classList.add('mobile-open');document.body.classList.add('sidebar-mobile-open');}
  function sync(){if(!sidebar)return;if(mq.matches){sidebar.classList.remove('collapsed');closeSide();}else{sidebar.classList.remove('mobile-open');document.body.classList.remove('sidebar-mobile-open');if(localStorage.getItem('sidebar_collapsed')==='1')sidebar.classList.add('collapsed');}}
  if(btn&&sidebar){btn.addEventListener('click',function(){if(mq.matches){sidebar.classList.contains('mobile-open')?closeSide():openSide();return;}sidebar.classList.toggle('collapsed');localStorage.setItem('sidebar_collapsed',sidebar.classList.contains('collapsed')?'1':'0');});sync();if(mq.addEventListener)mq.addEventListener('change',sync);else mq.addListener(sync);}
  if(overlay)overlay.addEventListener('click',closeSide);
  document.addEventListener('keydown',function(e){if(e.key==='Escape')closeSide();});
  document.querySelectorAll('[data-toggle-submenu]').forEach(function(b){b.addEventListener('click',function(){const t=document.querySelector(b.getAttribute('data-toggle-submenu')||''); if(t)t.classList.toggle('open');});});
})();
</script>
</body></html>
