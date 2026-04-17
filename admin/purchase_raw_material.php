<?php
require_once __DIR__ . '/../core/db.php';
require_once __DIR__ . '/../core/functions.php';
require_once __DIR__ . '/../core/security.php';
require_once __DIR__ . '/../core/auth.php';
require_once __DIR__ . '/../core/csrf.php';
require_once __DIR__ . '/../core/inventory.php';

start_secure_session();
require_admin();
ensure_inventory_module_schema();

$err = '';
$u = current_user();
$isOwner = (($u['role'] ?? '') === 'owner');
$branches = inventory_branches();
$suppliers = db()->query("SELECT id,supplier_name FROM suppliers WHERE is_active=1 ORDER BY supplier_name ASC")->fetchAll();
$materials = db()->query("SELECT id,name,base_unit,purchase_unit,purchase_to_base_factor,sale_unit,sale_to_base_factor FROM products WHERE product_type='raw_material' ORDER BY name ASC")->fetchAll();

function purchase_collect_items(array $src, bool $forEdit = false): array {
  $productIds = $src['item_product_id'] ?? [];
  $qtys = $src['item_qty'] ?? [];
  $costs = $src['item_unit_cost'] ?? [];
  $notes = $src['item_notes'] ?? [];
  $itemIds = $src['item_id'] ?? [];

  if (!is_array($productIds)) $productIds = [];
  if (!is_array($qtys)) $qtys = [];
  if (!is_array($costs)) $costs = [];
  if (!is_array($notes)) $notes = [];
  if (!is_array($itemIds)) $itemIds = [];

  $items = [];
  $max = max(count($productIds), count($qtys), count($costs), count($notes));
  for ($i = 0; $i < $max; $i++) {
    $productId = (int)($productIds[$i] ?? 0);
    $qty = parse_number_input($qtys[$i] ?? 0);
    $unitCost = parse_number_input($costs[$i] ?? 0);
    $note = trim((string)($notes[$i] ?? ''));
    $itemId = (int)($itemIds[$i] ?? 0);
    if ($productId <= 0 && $qty <= 0 && $unitCost <= 0 && $note === '') continue;
    if ($productId <= 0 || $qty <= 0 || $unitCost < 0) {
      throw new Exception('Data item pembelian tidak valid.');
    }
    $items[] = [
      'id' => $forEdit ? $itemId : 0,
      'product_id' => $productId,
      'qty' => $qty,
      'qty_base' => 0,
      'unit_cost' => $unitCost,
      'line_total' => $qty * $unitCost,
      'notes' => $note,
    ];
  }

  if (empty($items)) {
    throw new Exception('Minimal 1 item pembelian wajib diisi.');
  }

  if (!empty($items)) {
    $ids = array_map(static function ($it) { return (int)$it['product_id']; }, $items);
    $ids = array_values(array_unique(array_filter($ids)));
    $products = [];
    if (!empty($ids)) {
      $in = implode(',', array_fill(0, count($ids), '?'));
      $stmt = db()->prepare("SELECT * FROM products WHERE id IN ($in)");
      $stmt->execute($ids);
      foreach ($stmt->fetchAll() as $p) {
        $products[(int)$p['id']] = $p;
      }
    }
    foreach ($items as $idx => $it) {
      $meta = product_unit_fallback($products[(int)$it['product_id']] ?? []);
      $items[$idx]['purchase_unit'] = $meta['purchase_unit'];
      $items[$idx]['base_unit'] = $meta['base_unit'];
      $items[$idx]['purchase_to_base_factor'] = (float)$meta['purchase_to_base_factor'];
      $items[$idx]['qty_base'] = (float)$it['qty'] * (float)$meta['purchase_to_base_factor'];
    }
  }

  return $items;
}

function purchase_snapshot(PDO $db, int $purchaseId): array {
  $stmt = $db->prepare("SELECT * FROM purchase_headers WHERE id=? LIMIT 1");
  $stmt->execute([$purchaseId]);
  $header = $stmt->fetch();
  if (!$header) throw new Exception('Dokumen tidak ditemukan.');

  $stmt = $db->prepare("SELECT pi.*, p.name product_name, p.base_unit, p.purchase_unit, p.purchase_to_base_factor, p.sale_unit, p.sale_to_base_factor FROM purchase_items pi JOIN products p ON p.id=pi.product_id WHERE pi.purchase_id=? ORDER BY pi.id ASC");
  $stmt->execute([$purchaseId]);
  $items = $stmt->fetchAll();

  return ['header' => $header, 'items' => $items];
}

function purchase_changes(array $before, array $after): array {
  $changes = [];
  $headerFields = ['purchase_date', 'branch_id', 'supplier_id', 'status', 'notes', 'subtotal', 'grand_total'];
  foreach ($headerFields as $f) {
    $old = $before['header'][$f] ?? null;
    $new = $after['header'][$f] ?? null;
    if ((string)$old !== (string)$new) {
      $changes['header'][$f] = ['old' => $old, 'new' => $new];
    }
  }

  $beforeItems = [];
  foreach (($before['items'] ?? []) as $it) {
    $beforeItems[(int)$it['id']] = $it;
  }
  $afterItems = [];
  foreach (($after['items'] ?? []) as $it) {
    $afterItems[(int)$it['id']] = $it;
  }

  foreach ($beforeItems as $id => $oldItem) {
    if (!isset($afterItems[$id])) {
      $changes['items'][] = ['type' => 'removed', 'item_id' => $id, 'old' => $oldItem];
      continue;
    }
    $newItem = $afterItems[$id];
    $itemDiff = [];
    foreach (['product_id', 'qty', 'unit_cost', 'line_total', 'notes'] as $f) {
      if ((string)($oldItem[$f] ?? '') !== (string)($newItem[$f] ?? '')) {
        $itemDiff[$f] = ['old' => $oldItem[$f] ?? null, 'new' => $newItem[$f] ?? null];
      }
    }
    if (!empty($itemDiff)) {
      $changes['items'][] = ['type' => 'updated', 'item_id' => $id, 'changes' => $itemDiff];
    }
  }

  foreach ($afterItems as $id => $newItem) {
    if (!isset($beforeItems[$id])) {
      $changes['items'][] = ['type' => 'added', 'item_id' => $id, 'new' => $newItem];
    }
  }

  return $changes;
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
  csrf_check();
  $action = (string)($_POST['action'] ?? 'create');
  try {
    if ($action === 'create') {
      $purchaseNo = trim((string)($_POST['purchase_no'] ?? ''));
      $branchId = (int)($_POST['branch_id'] ?? active_branch_id());
      $supplierId = (int)($_POST['supplier_id'] ?? 0);
      $date = (string)($_POST['purchase_date'] ?? date('Y-m-d'));
      $notes = trim((string)($_POST['notes'] ?? ''));
      $items = purchase_collect_items($_POST);
      if ($purchaseNo === '') throw new Exception('Nomor purchase wajib.');
      if ($supplierId <= 0) throw new Exception('Supplier wajib dipilih.');

      $subtotal = 0.0;
      foreach ($items as $it) $subtotal += (float)$it['line_total'];

      $db = db();
      $db->beginTransaction();
      $stmt = $db->prepare("INSERT INTO purchase_headers (branch_id,supplier_id,purchase_no,purchase_date,status,subtotal,grand_total,notes,created_by) VALUES (?,?,?,?, 'draft',?,?,?,?)");
      $stmt->execute([$branchId, $supplierId, $purchaseNo, $date, $subtotal, $subtotal, $notes, (int)($u['id'] ?? 0)]);
      $purchaseId = (int)$db->lastInsertId();

      $stmt = $db->prepare("INSERT INTO purchase_items (purchase_id,product_id,qty,unit_cost,line_total,notes) VALUES (?,?,?,?,?,?)");
      foreach ($items as $it) {
        $stmt->execute([$purchaseId, $it['product_id'], $it['qty'], $it['unit_cost'], $it['line_total'], $it['notes']]);
      }

      $db->commit();
      redirect(base_url('admin/purchase_raw_material.php'));
    }

    if ($action === 'update') {
      $id = (int)($_POST['id'] ?? 0);
      $branchId = (int)($_POST['branch_id'] ?? active_branch_id());
      $supplierId = (int)($_POST['supplier_id'] ?? 0);
      $date = (string)($_POST['purchase_date'] ?? date('Y-m-d'));
      $notes = trim((string)($_POST['notes'] ?? ''));
      $status = (string)($_POST['status'] ?? 'draft');
      if (!in_array($status, ['draft', 'posted'], true)) $status = 'draft';
      $editReason = trim((string)($_POST['edit_reason'] ?? ''));
      if ($supplierId <= 0) throw new Exception('Supplier wajib dipilih.');

      $newItems = purchase_collect_items($_POST, true);
      $newSubtotal = 0.0;
      foreach ($newItems as $it) $newSubtotal += (float)$it['line_total'];

      $db = db();
      $db->beginTransaction();

      $stmt = $db->prepare("SELECT * FROM purchase_headers WHERE id=? LIMIT 1 FOR UPDATE");
      $stmt->execute([$id]);
      $oldHeader = $stmt->fetch();
      if (!$oldHeader) throw new Exception('Dokumen tidak ditemukan.');
      if (($oldHeader['status'] ?? '') === 'cancelled') throw new Exception('Dokumen cancelled tidak bisa diedit.');

      $stmt = $db->prepare("SELECT pi.*, p.product_type, p.purchase_to_base_factor FROM purchase_items pi JOIN products p ON p.id=pi.product_id WHERE pi.purchase_id=? ORDER BY pi.id ASC");
      $stmt->execute([$id]);
      $oldItems = $stmt->fetchAll();
      if (empty($oldItems)) throw new Exception('Item dokumen lama kosong.');

      $oldItemsById = [];
      foreach ($oldItems as $it) {
        if (($it['product_type'] ?? '') !== 'raw_material') throw new Exception('Pembelian ini hanya untuk raw material.');
        $oldItemsById[(int)$it['id']] = $it;
      }

      $productCheck = $db->prepare("SELECT id, product_type FROM products WHERE id=? LIMIT 1");
      foreach ($newItems as $it) {
        $productCheck->execute([(int)$it['product_id']]);
        $p = $productCheck->fetch();
        if (!$p || ($p['product_type'] ?? '') !== 'raw_material') {
          throw new Exception('Semua item harus produk raw material.');
        }
      }

      if (($oldHeader['status'] ?? '') === 'posted') {
        if ($editReason === '') throw new Exception('Alasan edit wajib untuk dokumen posted.');
        if (!$isOwner) {
          foreach ($newItems as $ni) {
            $oldItemId = (int)($ni['id'] ?? 0);
            if ($oldItemId <= 0 || !isset($oldItemsById[$oldItemId])) {
              throw new Exception('Hanya owner yang boleh mengubah harga beli pada posted (termasuk tambah item baru).');
            }
            $oldCost = (float)$oldItemsById[$oldItemId]['unit_cost'];
            if (abs($oldCost - (float)$ni['unit_cost']) > 0.00001) {
              throw new Exception('Hanya owner yang boleh mengubah harga beli pada posted.');
            }
          }
        }

        foreach ($oldItems as $it) {
          add_stock_ledger([
            'branch_id' => (int)$oldHeader['branch_id'],
            'product_id' => (int)$it['product_id'],
            'trans_type' => 'purchase_edit_rollback',
            'ref_table' => 'purchase_headers',
            'ref_id' => $id,
            'qty_in' => 0,
            'qty_out' => (float)($it['qty_base'] ?? ((float)$it['qty'] * max(0.000001, (float)($it['purchase_to_base_factor'] ?? 1)))),
            'unit_cost' => (float)$it['unit_cost'],
            'note' => 'Rollback stok edit purchase posted',
            'created_by' => (int)($u['id'] ?? 0),
          ]);
        }
      }

      $postedBy = null;
      $postedAtSql = 'NULL';
      if ($status === 'posted') {
        $postedBy = (int)($u['id'] ?? 0);
        $postedAtSql = 'NOW()';
      }

      $sql = "UPDATE purchase_headers SET branch_id=?, supplier_id=?, purchase_date=?, status=?, subtotal=?, grand_total=?, notes=?, posted_by=?, posted_at={$postedAtSql} WHERE id=?";
      $stmt = $db->prepare($sql);
      $stmt->execute([$branchId, $supplierId, $date, $status, $newSubtotal, $newSubtotal, $notes, $postedBy, $id]);

      $db->prepare("DELETE FROM purchase_items WHERE purchase_id=?")->execute([$id]);
      $stmtIns = $db->prepare("INSERT INTO purchase_items (purchase_id,product_id,qty,unit_cost,line_total,notes) VALUES (?,?,?,?,?,?)");
      foreach ($newItems as $it) {
        $stmtIns->execute([$id, $it['product_id'], $it['qty'], $it['unit_cost'], $it['line_total'], $it['notes']]);
      }

      $stmt = $db->prepare("SELECT pi.*, p.purchase_to_base_factor, p.base_unit, p.purchase_unit, p.sale_unit, p.sale_to_base_factor FROM purchase_items pi JOIN products p ON p.id=pi.product_id WHERE pi.purchase_id=? ORDER BY pi.id ASC");
      $stmt->execute([$id]);
      $savedItems = $stmt->fetchAll();

      if ($status === 'posted') {
        foreach ($savedItems as $it) {
          add_stock_ledger([
            'branch_id' => $branchId,
            'product_id' => (int)$it['product_id'],
            'trans_type' => 'purchase_edit_apply',
            'ref_table' => 'purchase_headers',
            'ref_id' => $id,
            'qty_in' => (float)($it['qty_base'] ?? ((float)$it['qty'] * max(0.000001, (float)($it['purchase_to_base_factor'] ?? 1)))),
            'qty_out' => 0,
            'unit_cost' => (float)$it['unit_cost'],
            'note' => 'Apply stok hasil edit purchase',
            'created_by' => (int)($u['id'] ?? 0),
          ]);
        }
      }

      $beforeSnapshot = [
        'header' => $oldHeader,
        'items' => $oldItems,
      ];
      $afterSnapshot = [
        'header' => [
          'id' => $id,
          'purchase_no' => $oldHeader['purchase_no'],
          'purchase_date' => $date,
          'branch_id' => $branchId,
          'supplier_id' => $supplierId,
          'status' => $status,
          'subtotal' => $newSubtotal,
          'grand_total' => $newSubtotal,
          'notes' => $notes,
          'posted_by' => $postedBy,
        ],
        'items' => $savedItems,
      ];
      $changes = purchase_changes($beforeSnapshot, $afterSnapshot);

      $stmt = $db->prepare("INSERT INTO purchase_revision_audit (purchase_id,purchase_no,edited_by,edit_reason,snapshot_before,snapshot_after,change_summary) VALUES (?,?,?,?,?,?,?)");
      $stmt->execute([
        $id,
        (string)$oldHeader['purchase_no'],
        (int)($u['id'] ?? 0),
        $editReason !== '' ? $editReason : null,
        json_encode($beforeSnapshot, JSON_UNESCAPED_UNICODE),
        json_encode($afterSnapshot, JSON_UNESCAPED_UNICODE),
        json_encode($changes, JSON_UNESCAPED_UNICODE),
      ]);

      $db->commit();
      redirect(base_url('admin/purchase_raw_material.php'));
    }

    if ($action === 'post') {
      $id = (int)($_POST['id'] ?? 0);
      $db = db();
      $db->beginTransaction();
      $stmt = $db->prepare("SELECT * FROM purchase_headers WHERE id=? LIMIT 1 FOR UPDATE");
      $stmt->execute([$id]);
      $h = $stmt->fetch();
      if (!$h) throw new Exception('Dokumen tidak ditemukan.');
      if ($h['status'] !== 'draft') throw new Exception('Hanya draft yang bisa diposting.');
      $stmt = $db->prepare("SELECT pi.*, p.product_type, p.purchase_to_base_factor FROM purchase_items pi JOIN products p ON p.id=pi.product_id WHERE pi.purchase_id=?");
      $stmt->execute([$id]);
      $items = $stmt->fetchAll();
      if (empty($items)) throw new Exception('Item kosong.');
      foreach ($items as $it) {
        if (($it['product_type'] ?? '') !== 'raw_material') throw new Exception('Pembelian ini hanya untuk raw material.');
        add_stock_ledger([
          'branch_id' => (int)$h['branch_id'],
          'product_id' => (int)$it['product_id'],
          'trans_type' => 'purchase_post',
          'ref_table' => 'purchase_headers',
          'ref_id' => $id,
          'qty_in' => (float)($it['qty_base'] ?? ((float)$it['qty'] * max(0.000001, (float)($it['purchase_to_base_factor'] ?? 1)))),
          'qty_out' => 0,
          'unit_cost' => (float)$it['unit_cost'],
          'note' => 'Posting pembelian',
          'created_by' => (int)($u['id'] ?? 0),
        ]);
      }
      $stmt = $db->prepare("UPDATE purchase_headers SET status='posted', posted_by=?, posted_at=NOW() WHERE id=?");
      $stmt->execute([(int)($u['id'] ?? 0), $id]);
      $db->commit();
      redirect(base_url('admin/purchase_raw_material.php'));
    }

    if ($action === 'cancel') {
      $id = (int)($_POST['id'] ?? 0);
      $db = db();
      $db->beginTransaction();
      $stmt = $db->prepare("SELECT * FROM purchase_headers WHERE id=? LIMIT 1 FOR UPDATE");
      $stmt->execute([$id]);
      $h = $stmt->fetch();
      if (!$h) throw new Exception('Dokumen tidak ditemukan.');
      if ($h['status'] === 'cancelled') throw new Exception('Sudah cancelled.');
      if ($h['status'] === 'posted') {
        $stmt = $db->prepare("SELECT pi.*, p.purchase_to_base_factor FROM purchase_items pi JOIN products p ON p.id=pi.product_id WHERE pi.purchase_id=?");
        $stmt->execute([$id]);
        foreach ($stmt->fetchAll() as $it) {
          add_stock_ledger([
            'branch_id' => (int)$h['branch_id'],
            'product_id' => (int)$it['product_id'],
            'trans_type' => 'purchase_cancel',
            'ref_table' => 'purchase_headers',
            'ref_id' => $id,
            'qty_in' => 0,
            'qty_out' => (float)($it['qty_base'] ?? ((float)$it['qty'] * max(0.000001, (float)($it['purchase_to_base_factor'] ?? 1)))),
            'unit_cost' => (float)$it['unit_cost'],
            'note' => 'Reversal cancel pembelian',
            'created_by' => (int)($u['id'] ?? 0),
          ]);
        }
      }
      $stmt = $db->prepare("UPDATE purchase_headers SET status='cancelled' WHERE id=?");
      $stmt->execute([$id]);
      $db->commit();
      redirect(base_url('admin/purchase_raw_material.php'));
    }
  } catch (Throwable $e) {
    if (isset($db) && $db->inTransaction()) $db->rollBack();
    $err = $e->getMessage();
  }
}

$editId = (int)($_GET['edit_id'] ?? 0);
$editDoc = null;
$editItems = [];
$editLockedCost = false;
if ($editId > 0) {
  try {
    $snap = purchase_snapshot(db(), $editId);
    if (($snap['header']['status'] ?? '') === 'cancelled') {
      $err = 'Dokumen cancelled tidak bisa diedit.';
    } else {
      $editDoc = $snap['header'];
      $editItems = $snap['items'];
      $editLockedCost = (($editDoc['status'] ?? '') === 'posted' && !$isOwner);
    }
  } catch (Throwable $e) {
    $err = $e->getMessage();
  }
}

$docs = db()->query("SELECT ph.*, b.branch_name, s.supplier_name FROM purchase_headers ph JOIN branches b ON b.id=ph.branch_id JOIN suppliers s ON s.id=ph.supplier_id ORDER BY ph.id DESC LIMIT 100")->fetchAll();
$customCss = setting('custom_css', '');
?>
<!doctype html>
<html>
<head>
<meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"><title>Pembelian Bahan Baku</title>
<link rel="icon" href="<?php echo e(favicon_url()); ?>"><link rel="stylesheet" href="<?php echo e(asset_url('assets/app.css')); ?>"><style><?php echo $customCss; ?></style>
</head>
<body>
<div class="container"><?php include __DIR__ . '/partials_sidebar.php'; ?><div class="main"><div class="topbar"><button class="btn" data-toggle-sidebar type="button">Menu</button></div><div class="content">
<div class="card"><h3><?php echo $editDoc ? 'Edit Purchase Bahan Baku' : 'Pembelian Bahan Baku'; ?></h3><?php if ($err): ?><div class="card" style="border-color:rgba(251,113,133,.35);background:rgba(251,113,133,.10)"><?php echo e($err); ?></div><?php endif; ?>
<form method="post" id="purchase-form">
<input type="hidden" name="_csrf" value="<?php echo e(csrf_token()); ?>">
<input type="hidden" name="action" value="<?php echo $editDoc ? 'update' : 'create'; ?>">
<?php if ($editDoc): ?><input type="hidden" name="id" value="<?php echo e((string)$editDoc['id']); ?>"><?php endif; ?>
<div class="grid cols-2"><div class="row"><label>No Purchase</label><input name="purchase_no" value="<?php echo e((string)($editDoc['purchase_no'] ?? ('PO-' . date('YmdHis')))); ?>" <?php echo $editDoc ? 'readonly' : 'required'; ?>></div><div class="row"><label>Tanggal</label><input type="date" name="purchase_date" value="<?php echo e((string)($editDoc['purchase_date'] ?? date('Y-m-d'))); ?>" required></div></div>
<div class="grid cols-2"><div class="row"><label>Cabang</label><select name="branch_id"><?php foreach($branches as $b): ?><option value="<?php echo e((string)$b['id']); ?>" <?php echo ((int)$b['id'] === (int)($editDoc['branch_id'] ?? active_branch_id())) ? 'selected' : ''; ?>><?php echo e($b['branch_name']); ?></option><?php endforeach; ?></select></div><div class="row"><label>Supplier</label><select name="supplier_id" required><?php foreach($suppliers as $s): ?><option value="<?php echo e((string)$s['id']); ?>" <?php echo ((int)$s['id'] === (int)($editDoc['supplier_id'] ?? 0)) ? 'selected' : ''; ?>><?php echo e($s['supplier_name']); ?></option><?php endforeach; ?></select></div></div>
<div class="row"><label>Catatan</label><textarea name="notes" rows="2" placeholder="Catatan purchase (opsional)"><?php echo e((string)($editDoc['notes'] ?? '')); ?></textarea></div>
<?php if ($editDoc): ?><div class="grid cols-2"><div class="row"><label>Status</label><select name="status"><option value="draft" <?php echo (($editDoc['status'] ?? '') === 'draft') ? 'selected' : ''; ?>>Draft</option><option value="posted" <?php echo (($editDoc['status'] ?? '') === 'posted') ? 'selected' : ''; ?>>Posted</option></select></div><div class="row"><label>Total</label><input type="text" id="purchase-total" readonly value="0.00"></div></div><?php endif; ?>
<?php if ($editDoc && ($editDoc['status'] ?? '') === 'posted'): ?><div class="row"><label>Alasan Edit (wajib untuk posted)</label><textarea name="edit_reason" rows="2" placeholder="Contoh: koreksi qty karena salah input" <?php echo (($editDoc['status'] ?? '') === 'posted') ? 'required' : ''; ?>></textarea></div><?php endif; ?>
<?php if ($editLockedCost): ?><p><small>Dokumen posted: harga beli dikunci karena hanya owner yang boleh mengubah harga.</small></p><?php endif; ?>

<div class="row"><label>Item Bahan Baku</label>
<table class="table" id="items-table"><thead><tr><th>Material</th><th style="width:120px">Qty</th><th style="width:140px">Harga Beli</th><th style="width:140px">Subtotal</th><th>Catatan</th><th style="width:80px">Aksi</th></tr></thead><tbody>
<?php $rows = !empty($editItems) ? $editItems : [['id'=>0,'product_id'=>0,'qty'=>1,'unit_cost'=>0,'notes'=>'']]; ?>
<?php foreach ($rows as $it): ?>
<tr class="item-row">
<td>
<input type="hidden" name="item_id[]" value="<?php echo e((string)($it['id'] ?? 0)); ?>">
<select name="item_product_id[]" required>
<option value="">- pilih material -</option>
<?php foreach($materials as $m): $metaM = product_unit_fallback($m); ?><option value="<?php echo e((string)$m['id']); ?>" data-purchase-unit="<?php echo e($metaM['purchase_unit']); ?>" data-base-unit="<?php echo e($metaM['base_unit']); ?>" data-factor="<?php echo e((string)$metaM['purchase_to_base_factor']); ?>" <?php echo ((int)$m['id'] === (int)($it['product_id'] ?? 0)) ? 'selected' : ''; ?>><?php echo e($m['name']); ?></option><?php endforeach; ?>
</select>
<small class="unit-hint"></small>
</td>
<td><input type="number" min="0.0001" step="0.0001" name="item_qty[]" value="<?php echo e((string)($it['qty'] ?? 1)); ?>" required></td>
<td><input type="number" min="0" step="0.01" name="item_unit_cost[]" value="<?php echo e((string)($it['unit_cost'] ?? 0)); ?>" required <?php echo $editLockedCost ? 'readonly' : ''; ?>></td>
<td><input type="text" class="line-total" readonly value="0.00"></td>
<td><input type="text" name="item_notes[]" value="<?php echo e((string)($it['notes'] ?? '')); ?>" placeholder="Catatan item"></td>
<td><button class="btn danger btn-remove-item" type="button">Hapus</button></td>
</tr>
<?php endforeach; ?>
</tbody></table>
</div>
<?php if (!$editLockedCost): ?><button class="btn" id="btn-add-item" type="button">Tambah Item</button><?php endif; ?>
<button class="btn" type="submit"><?php echo $editDoc ? 'Simpan Edit' : 'Simpan Draft'; ?></button>
<?php if ($editDoc): ?><a class="btn" href="<?php echo e(base_url('admin/purchase_raw_material.php')); ?>">Batal Edit</a><?php endif; ?>
</form></div>
<div class="card"><table class="table"><thead><tr><th>No</th><th>Tanggal</th><th>Cabang</th><th>Supplier</th><th>Total</th><th>Status</th><th>Aksi</th></tr></thead><tbody><?php foreach($docs as $d): ?><tr><td><?php echo e($d['purchase_no']); ?></td><td><?php echo e($d['purchase_date']); ?></td><td><?php echo e($d['branch_name']); ?></td><td><?php echo e($d['supplier_name']); ?></td><td><?php echo e(format_money((float)$d['grand_total'])); ?></td><td><?php echo e($d['status']); ?></td><td style="display:flex;gap:6px;flex-wrap:wrap"><a class="btn" href="<?php echo e(base_url('admin/purchase_raw_material.php?edit_id=' . (int)$d['id'])); ?>">Edit</a><?php if($d['status']==='draft'): ?><form method="post"><input type="hidden" name="_csrf" value="<?php echo e(csrf_token()); ?>"><input type="hidden" name="action" value="post"><input type="hidden" name="id" value="<?php echo e((string)$d['id']); ?>"><button class="btn" type="submit">Post</button></form><?php endif; ?><?php if($d['status']!=='cancelled'): ?><form method="post"><input type="hidden" name="_csrf" value="<?php echo e(csrf_token()); ?>"><input type="hidden" name="action" value="cancel"><input type="hidden" name="id" value="<?php echo e((string)$d['id']); ?>"><button class="btn danger" type="submit">Cancel</button></form><?php endif; ?></td></tr><?php endforeach; ?></tbody></table></div>
</div></div></div></div>
<script>
(function(){
  const tableBody = document.querySelector('#items-table tbody');
  const addBtn = document.querySelector('#btn-add-item');
  const totalInput = document.querySelector('#purchase-total');
  const lockedCost = <?php echo $editLockedCost ? 'true' : 'false'; ?>;

  function recalc(){
    let total = 0;
    tableBody.querySelectorAll('tr.item-row').forEach((row)=>{
      const qtyEl = row.querySelector('input[name="item_qty[]"]');
      const costEl = row.querySelector('input[name="item_unit_cost[]"]');
      const lineEl = row.querySelector('.line-total');
      const qty = parseFloat(qtyEl?.value || '0');
      const cost = parseFloat(costEl?.value || '0');
      const line = qty * cost;
      if (lineEl) lineEl.value = line.toFixed(2);
      total += line;
    });
    if (totalInput) totalInput.value = total.toFixed(2);
  }

  function bindRow(row){
    row.querySelectorAll('input[name="item_qty[]"],input[name="item_unit_cost[]"]').forEach((el)=>el.addEventListener('input', recalc));
    const productSel = row.querySelector('select[name="item_product_id[]"]');
    const hintEl = row.querySelector('.unit-hint');
    const updateHint = () => {
      if (!hintEl || !productSel) return;
      const opt = productSel.options[productSel.selectedIndex];
      const purchaseUnit = opt?.getAttribute('data-purchase-unit') || 'unit';
      const baseUnit = opt?.getAttribute('data-base-unit') || purchaseUnit;
      const factor = parseFloat(opt?.getAttribute('data-factor') || '1');
      hintEl.textContent = `Input dalam ${purchaseUnit}. 1 ${purchaseUnit} = ${factor} ${baseUnit}.`;
    };
    if (productSel) {
      productSel.addEventListener('change', updateHint);
      updateHint();
    }
    const delBtn = row.querySelector('.btn-remove-item');
    if (delBtn) {
      delBtn.addEventListener('click', ()=>{
        const rows = tableBody.querySelectorAll('tr.item-row');
        if (rows.length <= 1) {
          alert('Minimal 1 item harus ada.');
          return;
        }
        row.remove();
        recalc();
      });
    }
  }

  tableBody.querySelectorAll('tr.item-row').forEach(bindRow);

  if (addBtn) {
    addBtn.addEventListener('click', ()=>{
      if (lockedCost) return;
      const first = tableBody.querySelector('tr.item-row');
      if (!first) return;
      const clone = first.cloneNode(true);
      clone.querySelector('input[name="item_id[]"]').value = '0';
      clone.querySelector('select[name="item_product_id[]"]').value = '';
      clone.querySelector('input[name="item_qty[]"]').value = '1';
      clone.querySelector('input[name="item_unit_cost[]"]').value = '0';
      clone.querySelector('input[name="item_notes[]"]').value = '';
      const lineEl = clone.querySelector('.line-total');
      if (lineEl) lineEl.value = '0.00';
      tableBody.appendChild(clone);
      bindRow(clone);
      recalc();
    });
  }

  recalc();
})();
</script>
<script defer src="<?php echo e(asset_url('assets/app.js')); ?>"></script>
</body>
</html>
