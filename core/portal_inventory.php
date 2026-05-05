<?php
require_once __DIR__ . '/db.php';
require_once __DIR__ . '/functions.php';
require_once __DIR__ . '/inventory.php';
require_once __DIR__ . '/ops14.php';

function portal_inventory_schema(): void {
  ensure_inventory_module_schema();
  ensure_adena14_schema();
  $safe = static function (string $sql): void { try { db()->exec($sql); } catch (Throwable $e) {} };
  $safe("ALTER TABLE stock_ledger ADD COLUMN location_id INT NULL AFTER branch_id");
  $safe("ALTER TABLE stock_ledger ADD KEY idx_stock_ledger_location (location_id,product_id,created_at)");
  $safe("ALTER TABLE stock_transfers ADD COLUMN cancelled_at TIMESTAMP NULL DEFAULT NULL AFTER rejected_at");
  $safe("ALTER TABLE stock_transfer_items ADD COLUMN note VARCHAR(255) NULL AFTER unit_cost");
}

function portal_table_has_column(string $table, string $column): bool {
  static $cache = [];
  $key = $table . '.' . $column;
  if (array_key_exists($key, $cache)) return $cache[$key];
  try {
    $stmt = db()->prepare("SHOW COLUMNS FROM `{$table}` LIKE ?");
    $stmt->execute([$column]);
    $cache[$key] = (bool)$stmt->fetch();
  } catch (Throwable $e) { $cache[$key] = false; }
  return $cache[$key];
}

function portal_inventory_branch_location_id(int $branchId): int {
  portal_inventory_schema();
  if ($branchId <= 0) $branchId = 1;
  $pdo = db();
  $stmt = $pdo->prepare("SELECT id FROM stock_locations WHERE branch_id=? AND location_type IN ('store','branch') AND is_active=1 ORDER BY id LIMIT 1");
  $stmt->execute([$branchId]);
  $id = (int)($stmt->fetchColumn() ?: 0);
  if ($id > 0) return $id;
  $stmt = $pdo->prepare("SELECT branch_code, branch_name FROM branches WHERE id=? LIMIT 1");
  $stmt->execute([$branchId]);
  $b = $stmt->fetch(PDO::FETCH_ASSOC) ?: ['branch_code'=>'BR'.$branchId, 'branch_name'=>'Cabang '.$branchId];
  $code = 'BRANCH-' . $branchId;
  $name = (string)($b['branch_name'] ?? ('Cabang '.$branchId));
  try {
    $ins = $pdo->prepare("INSERT INTO stock_locations (location_code,location_name,location_type,branch_id,is_active) VALUES (?,?,?,?,1)");
    $ins->execute([$code, $name, 'branch', $branchId]);
    return (int)$pdo->lastInsertId();
  } catch (Throwable $e) {
    $stmt = $pdo->prepare("SELECT id FROM stock_locations WHERE location_code=? LIMIT 1");
    $stmt->execute([$code]);
    return (int)($stmt->fetchColumn() ?: 0);
  }
}

function portal_inventory_kitchen_location_id(): int {
  portal_inventory_schema();
  $pdo = db();
  $id = (int)($pdo->query("SELECT id FROM stock_locations WHERE location_type='kitchen' AND is_active=1 ORDER BY id LIMIT 1")->fetchColumn() ?: 0);
  if ($id > 0) return $id;
  try {
    $pdo->exec("INSERT INTO stock_locations (location_code,location_name,location_type,branch_id,is_active) VALUES ('KITCHEN','Dapur Produksi','kitchen',NULL,1)");
    return (int)$pdo->lastInsertId();
  } catch (Throwable $e) {
    return (int)($pdo->query("SELECT id FROM stock_locations WHERE location_code='KITCHEN' LIMIT 1")->fetchColumn() ?: 0);
  }
}

function portal_inventory_location(int $locationId): ?array {
  portal_inventory_schema();
  $stmt = db()->prepare("SELECT * FROM stock_locations WHERE id=? LIMIT 1");
  $stmt->execute([$locationId]);
  $row = $stmt->fetch(PDO::FETCH_ASSOC);
  return $row ?: null;
}

function portal_inventory_destination_locations(?int $excludeId = null): array {
  portal_inventory_schema();
  $sql = "SELECT * FROM stock_locations WHERE is_active=1";
  $params = [];
  if ($excludeId) { $sql .= " AND id<>?"; $params[] = $excludeId; }
  $sql .= " ORDER BY FIELD(location_type,'branch','store','kitchen'), location_name";
  $stmt = db()->prepare($sql);
  $stmt->execute($params);
  return $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
}

function portal_inventory_products(string $search = '', string $type = 'all'): array {
  portal_inventory_schema();
  $params = [];
  $where = "WHERE p.track_stock=1";
  if ($type === 'raw') $where .= " AND p.product_type='raw_material'";
  elseif ($type === 'finished') $where .= " AND p.product_type='finished_good'";
  else $where .= " AND p.product_type IN ('raw_material','finished_good')";
  if ($search !== '') {
    $where .= " AND (p.name LIKE ? OR COALESCE(p.category,'') LIKE ? OR CAST(p.id AS CHAR) LIKE ?)";
    $term = '%' . $search . '%';
    array_push($params, $term, $term, $term);
  }
  $stmt = db()->prepare("SELECT p.id,p.name,p.category,p.product_type,p.track_stock,p.base_unit,p.purchase_unit,p.purchase_to_base_factor,p.sale_unit,p.sale_to_base_factor,p.price,p.kitchen_price FROM products p {$where} ORDER BY p.name ASC LIMIT 500");
  $stmt->execute($params);
  return $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
}

function portal_inventory_stock_qty(int $locationId, int $productId): float {
  portal_inventory_schema();
  if ($locationId <= 0 || $productId <= 0) return 0.0;
  $loc = portal_inventory_location($locationId);
  $params = [$productId, $locationId];
  $where = "product_id=? AND location_id=?";
  if ($loc && in_array((string)($loc['location_type'] ?? ''), ['branch','store'], true)) {
    $branchId = (int)($loc['branch_id'] ?? 0);
    if ($branchId > 0) { $where = "product_id=? AND (location_id=? OR (location_id IS NULL AND branch_id=?))"; $params[] = $branchId; }
  }
  $stmt = db()->prepare("SELECT COALESCE(SUM(qty_in-qty_out),0) FROM stock_ledger WHERE {$where}");
  $stmt->execute($params);
  return (float)($stmt->fetchColumn() ?: 0);
}

function portal_inventory_stock_rows(int $locationId, string $search = '', string $type = 'all'): array {
  $products = portal_inventory_products($search, $type);
  foreach ($products as &$p) {
    $p['stock_qty'] = portal_inventory_stock_qty($locationId, (int)$p['id']);
  }
  unset($p);
  return $products;
}

function portal_inventory_add_ledger(array $row): void {
  portal_inventory_schema();
  $locationId = (int)($row['location_id'] ?? 0);
  $branchId = (int)($row['branch_id'] ?? 0);
  if ($locationId > 0 && $branchId <= 0) {
    $loc = portal_inventory_location($locationId);
    $branchId = (int)($loc['branch_id'] ?? 0);
  }
  if ($branchId <= 0) $branchId = 1; // kompatibilitas tabel lama: branch_id masih NOT NULL.
  $stmt = db()->prepare("INSERT INTO stock_ledger (branch_id,location_id,product_id,trans_type,ref_table,ref_id,qty_in,qty_out,unit_cost,note,created_by)
    VALUES (?,?,?,?,?,?,?,?,?,?,?)");
  $stmt->execute([
    $branchId,
    $locationId > 0 ? $locationId : null,
    (int)$row['product_id'],
    (string)$row['trans_type'],
    (string)$row['ref_table'],
    (int)$row['ref_id'],
    (float)($row['qty_in'] ?? 0),
    (float)($row['qty_out'] ?? 0),
    array_key_exists('unit_cost', $row) && $row['unit_cost'] !== null ? (float)$row['unit_cost'] : null,
    (string)($row['note'] ?? ''),
    (int)($row['created_by'] ?? 0),
  ]);
}

function portal_inventory_generate_no(string $prefix, string $table, string $column): string {
  $base = $prefix . '-' . date('Ymd-His');
  for ($i=0; $i<10; $i++) {
    $no = $base . '-' . strtoupper(bin2hex(random_bytes(2)));
    $stmt = db()->prepare("SELECT id FROM {$table} WHERE {$column}=? LIMIT 1");
    $stmt->execute([$no]);
    if (!$stmt->fetch()) return $no;
  }
  return $base . '-' . strtoupper(bin2hex(random_bytes(4)));
}

function portal_inventory_create_initial_stock(int $locationId, int $productId, float $qty, ?float $unitCost, string $note, int $userId): int {
  if ($locationId <= 0 || $productId <= 0) throw new Exception('Lokasi dan produk wajib dipilih.');
  if ($qty < 0) throw new Exception('Qty tidak boleh negatif.');
  $db = db();
  $db->beginTransaction();
  try {
    $stmt = $db->prepare("INSERT INTO initial_stock_entries (location_id,product_id,qty,unit_cost,status,note,created_by) VALUES (?,?,?,?, 'posted', ?, ?)");
    $stmt->execute([$locationId,$productId,$qty,$unitCost,$note !== '' ? $note : null,$userId]);
    $id = (int)$db->lastInsertId();
    portal_inventory_add_ledger(['location_id'=>$locationId,'product_id'=>$productId,'trans_type'=>'initial_stock','ref_table'=>'initial_stock_entries','ref_id'=>$id,'qty_in'=>$qty,'qty_out'=>0,'unit_cost'=>$unitCost,'note'=>'Stok awal lokasi','created_by'=>$userId]);
    $db->commit();
    return $id;
  } catch (Throwable $e) {
    if ($db->inTransaction()) $db->rollBack();
    if (stripos($e->getMessage(), 'Duplicate') !== false) throw new Exception('Stok awal produk ini sudah pernah diinput. Gunakan stok masuk/opname untuk koreksi berikutnya.');
    throw $e;
  }
}

function portal_inventory_create_transfer(int $fromLocationId, int $toLocationId, array $items, string $notes, int $userId): int {
  if ($fromLocationId <= 0 || $toLocationId <= 0 || $fromLocationId === $toLocationId) throw new Exception('Lokasi asal/tujuan tidak valid.');
  $clean = [];
  foreach ($items as $it) {
    $pid = (int)($it['product_id'] ?? 0);
    $qty = (float)($it['qty'] ?? 0);
    if ($pid > 0 && $qty > 0) $clean[] = ['product_id'=>$pid,'qty'=>$qty,'unit_cost'=>$it['unit_cost'] ?? null,'note'=>$it['note'] ?? ''];
  }
  if (empty($clean)) throw new Exception('Minimal satu item transfer wajib diisi.');
  foreach ($clean as $it) {
    if (portal_inventory_stock_qty($fromLocationId, $it['product_id']) + 0.00001 < $it['qty']) {
      throw new Exception('Stok salah satu produk tidak cukup untuk transfer.');
    }
  }
  $db = db();
  $db->beginTransaction();
  try {
    $no = portal_inventory_generate_no('TRF', 'stock_transfers', 'transfer_no');
    $stmt = $db->prepare("INSERT INTO stock_transfers (transfer_no,from_location_id,to_location_id,status,sent_at,created_by,sent_by,notes) VALUES (?,?,?,'sent',NOW(),?,?,?)");
    $stmt->execute([$no,$fromLocationId,$toLocationId,$userId,$userId,$notes !== '' ? $notes : null]);
    $tid = (int)$db->lastInsertId();
    $itemStmt = $db->prepare("INSERT INTO stock_transfer_items (transfer_id,product_id,qty,unit_cost,note) VALUES (?,?,?,?,?)");
    foreach ($clean as $it) {
      $itemStmt->execute([$tid,$it['product_id'],$it['qty'],$it['unit_cost'] !== null && $it['unit_cost'] !== '' ? (float)$it['unit_cost'] : null, trim((string)$it['note']) ?: null]);
      portal_inventory_add_ledger(['location_id'=>$fromLocationId,'product_id'=>$it['product_id'],'trans_type'=>'transfer_out','ref_table'=>'stock_transfers','ref_id'=>$tid,'qty_in'=>0,'qty_out'=>$it['qty'],'unit_cost'=>$it['unit_cost'] !== null && $it['unit_cost'] !== '' ? (float)$it['unit_cost'] : null,'note'=>'Transfer keluar '.$no.' - menunggu diterima','created_by'=>$userId]);
    }
    $db->commit();
    return $tid;
  } catch (Throwable $e) { if ($db->inTransaction()) $db->rollBack(); throw $e; }
}

function portal_inventory_accept_transfer(int $transferId, int $userId, string $note = ''): void {
  $db = db();
  $db->beginTransaction();
  try {
    $stmt = $db->prepare("SELECT * FROM stock_transfers WHERE id=? LIMIT 1 FOR UPDATE");
    $stmt->execute([$transferId]);
    $tr = $stmt->fetch(PDO::FETCH_ASSOC);
    if (!$tr) throw new Exception('Transfer tidak ditemukan.');
    if (($tr['status'] ?? '') !== 'sent') throw new Exception('Hanya transfer berstatus sent yang bisa diterima.');
    $items = $db->prepare("SELECT * FROM stock_transfer_items WHERE transfer_id=?");
    $items->execute([$transferId]);
    foreach ($items->fetchAll(PDO::FETCH_ASSOC) as $it) {
      portal_inventory_add_ledger(['location_id'=>(int)$tr['to_location_id'],'product_id'=>(int)$it['product_id'],'trans_type'=>'transfer_in','ref_table'=>'stock_transfers','ref_id'=>$transferId,'qty_in'=>(float)$it['qty'],'qty_out'=>0,'unit_cost'=>$it['unit_cost'] !== null ? (float)$it['unit_cost'] : null,'note'=>'Transfer masuk '.(string)$tr['transfer_no'],'created_by'=>$userId]);
    }
    $upd = $db->prepare("UPDATE stock_transfers SET status='accepted', accepted_at=NOW(), received_by=?, receiver_notes=?, updated_at=NOW() WHERE id=?");
    $upd->execute([$userId, $note !== '' ? $note : null, $transferId]);
    $db->commit();
  } catch (Throwable $e) { if ($db->inTransaction()) $db->rollBack(); throw $e; }
}

function portal_inventory_adjust_stock(int $locationId, int $productId, float $physicalQty, string $note, int $userId): void {
  if ($physicalQty < 0) throw new Exception('Stok fisik tidak boleh negatif.');
  $system = portal_inventory_stock_qty($locationId, $productId);
  $diff = round($physicalQty - $system, 4);
  if (abs($diff) < 0.00001) return;
  portal_inventory_add_ledger(['location_id'=>$locationId,'product_id'=>$productId,'trans_type'=>'portal_opname_adjustment','ref_table'=>'stock_ledger','ref_id'=>0,'qty_in'=>$diff > 0 ? $diff : 0,'qty_out'=>$diff < 0 ? abs($diff) : 0,'unit_cost'=>null,'note'=>$note !== '' ? $note : 'Penyesuaian opname portal','created_by'=>$userId]);
}
