<?php
require_once __DIR__ . '/../core/db.php';
require_once __DIR__ . '/../core/functions.php';
require_once __DIR__ . '/../core/security.php';
require_once __DIR__ . '/../core/auth.php';
require_once __DIR__ . '/../core/csrf.php';
require_once __DIR__ . '/../core/rbac.php';
require_once __DIR__ . '/../core/inventory.php';
require_once __DIR__ . '/../core/stock_transfer_unified.php';
if (file_exists(__DIR__ . '/../core/ops14.php')) require_once __DIR__ . '/../core/ops14.php';

start_secure_session();
require_admin();
ensure_rbac_schema();
$u = require_menu_access('inventori', 'view');
ensure_inventory_module_schema();
adena_stu_ensure_schema();
csrf_token();

$err = ''; $msg = '';
$schemaReason = '';
$schemaReady = adena_stu_schema_ready($schemaReason);
if (!$schemaReady) $err = 'Database Transfer Stok belum lengkap: '.$schemaReason.' Jalankan db/update_stock_transfer_unified_final.sql.';

$locations = $schemaReady ? adena_stu_locations() : [];
$products = $schemaReady ? adena_stu_products() : [];
$locById = [];
foreach ($locations as $loc) $locById[(int)$loc['id']] = $loc;

if ($schemaReady && $_SERVER['REQUEST_METHOD'] === 'POST') {
  try {
    csrf_check();
    $action = (string)($_POST['action'] ?? 'send');
    $db = db();
    if ($action === 'send') {
      require_action_access('inventori', 'create');
      $fromId = (int)($_POST['from_location_id'] ?? 0);
      $toId = (int)($_POST['to_location_id'] ?? 0);
      $notes = trim((string)($_POST['notes'] ?? ''));
      if ($fromId <= 0 || $toId <= 0 || $fromId === $toId) throw new Exception('Lokasi asal dan tujuan transfer harus berbeda.');
      if (!isset($locById[$fromId]) || !isset($locById[$toId])) throw new Exception('Lokasi transfer tidak ditemukan.');
      $items = adena_stu_collect_items($_POST);
      $db->beginTransaction();
      $no = trim((string)($_POST['transfer_no'] ?? ''));
      if ($no === '') $no = 'TRF-'.date('YmdHis');
      $stmt = $db->prepare("INSERT INTO stock_transfers (transfer_no,from_location_id,to_location_id,status,transfer_type,sent_at,created_by,sent_by,notes) VALUES (?,?,?,'sent','stock_transfer',NOW(),?,?,?)");
      $stmt->execute([$no, $fromId, $toId, (int)($u['id'] ?? 0), (int)($u['id'] ?? 0), $notes]);
      $transferId = (int)$db->lastInsertId();
      $itemStmt = $db->prepare("INSERT INTO stock_transfer_items (transfer_id,product_id,qty,note) VALUES (?,?,?,?)");
      $fromBranch = adena_stu_location_branch_id($locById[$fromId]);
      foreach ($items as $it) {
        $itemStmt->execute([$transferId, $it['product_id'], $it['qty'], $it['note']]);
        adena_stu_insert_ledger([
          'branch_id'=>$fromBranch, 'location_id'=>$fromId, 'product_id'=>$it['product_id'],
          'trans_type'=>'transfer_out', 'ref_table'=>'stock_transfers', 'ref_id'=>$transferId,
          'qty_in'=>0, 'qty_out'=>$it['qty'], 'unit_cost'=>null,
          'note'=>'Transfer stok keluar '.$no, 'created_by'=>(int)($u['id'] ?? 0)
        ]);
      }
      $db->commit();
      $msg = 'Transfer stok berhasil dikirim. Stok tujuan bertambah setelah diterima/approve.';
    }
    if ($action === 'cancel') {
      require_action_access('inventori', 'delete');
      $id = (int)($_POST['id'] ?? 0);
      if ($id <= 0) throw new Exception('ID transfer tidak valid.');
      $db->beginTransaction();
      $stmt = $db->prepare("SELECT st.*, fl.branch_id from_branch_id FROM stock_transfers st LEFT JOIN stock_locations fl ON fl.id=st.from_location_id WHERE st.id=? AND st.status='sent' LIMIT 1 FOR UPDATE");
      $stmt->execute([$id]);
      $h = $stmt->fetch(PDO::FETCH_ASSOC);
      if (!$h) throw new Exception('Transfer tidak ditemukan atau sudah diproses.');
      $items = $db->prepare("SELECT * FROM stock_transfer_items WHERE transfer_id=? ORDER BY id ASC");
      $items->execute([$id]);
      $branchId = (int)($h['from_branch_id'] ?? 0); if ($branchId <= 0) $branchId = (int)(function_exists('active_branch_id') ? active_branch_id() : 1);
      foreach ($items->fetchAll(PDO::FETCH_ASSOC) as $it) {
        adena_stu_insert_ledger([
          'branch_id'=>$branchId, 'location_id'=>(int)$h['from_location_id'], 'product_id'=>(int)$it['product_id'],
          'trans_type'=>'transfer_cancel_return', 'ref_table'=>'stock_transfers', 'ref_id'=>$id,
          'qty_in'=>(float)$it['qty'], 'qty_out'=>0, 'unit_cost'=>null,
          'note'=>'Pembatalan transfer '.$h['transfer_no'], 'created_by'=>(int)($u['id'] ?? 0)
        ]);
      }
      $db->prepare("UPDATE stock_transfers SET status='cancelled',cancelled_at=NOW(),received_by=?,receiver_notes=? WHERE id=?")->execute([(int)($u['id'] ?? 0), 'Dibatalkan dari halaman transfer', $id]);
      $db->commit();
      $msg = 'Transfer dibatalkan dan stok asal dikembalikan.';
    }
  } catch (Throwable $e) {
    try { if (isset($db) && $db->inTransaction()) $db->rollBack(); } catch (Throwable $ignore) {}
    $err = $e->getMessage();
  }
}

$rows = [];
if ($schemaReady) {
  try {
    $rows = db()->query("SELECT st.*, fl.location_name from_name, tl.location_name to_name
      FROM stock_transfers st
      LEFT JOIN stock_locations fl ON fl.id=st.from_location_id
      LEFT JOIN stock_locations tl ON tl.id=st.to_location_id
      ORDER BY st.id DESC LIMIT 100")->fetchAll(PDO::FETCH_ASSOC) ?: [];
  } catch (Throwable $e) { $err = $err ?: $e->getMessage(); }
}
$customCss = setting('custom_css','');
?>
<!doctype html><html><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"><title>Transfer Stok</title><link rel="icon" href="<?php echo e(favicon_url()); ?>"><link rel="stylesheet" href="<?php echo e(asset_url('assets/app.css')); ?>"><style><?php echo $customCss; ?></style></head><body><div class="container"><?php include __DIR__ . '/partials_sidebar.php'; ?><div class="main"><div class="topbar"><button class="btn" data-toggle-sidebar type="button">Menu</button><strong>Transfer Stok</strong></div><div class="content"><?php if($err): ?><div class="alert danger"><?php echo e($err); ?></div><?php endif; ?><?php if($msg): ?><div class="alert success"><?php echo e($msg); ?></div><?php endif; ?><div class="card"><h3>Kirim Transfer Stok</h3><p style="color:#64748b">Gunakan untuk kirim stok dari dapur ke toko/cabang. Toko menerima melalui menu Penerimaan Stok.</p><form method="post"><input type="hidden" name="_csrf" value="<?php echo e(csrf_token()); ?>"><input type="hidden" name="action" value="send"><div class="grid2"><label>No Transfer<input name="transfer_no" value="<?php echo e('TRF-'.date('YmdHis')); ?>"></label><label>Dari Lokasi<select name="from_location_id" required><option value="">- pilih asal -</option><?php foreach($locations as $l): ?><option value="<?php echo e((string)$l['id']); ?>"><?php echo e($l['location_name'].' ('.$l['location_type'].')'); ?></option><?php endforeach; ?></select></label><label>Ke Lokasi<select name="to_location_id" required><option value="">- pilih tujuan -</option><?php foreach($locations as $l): ?><option value="<?php echo e((string)$l['id']); ?>"><?php echo e($l['location_name'].' ('.$l['location_type'].')'); ?></option><?php endforeach; ?></select></label></div><label>Catatan<textarea name="notes" placeholder="Catatan transfer"></textarea></label><table class="table" id="items"><thead><tr><th>Produk</th><th style="width:140px">Qty</th><th>Catatan</th><th>Aksi</th></tr></thead><tbody><tr><td><select name="item_product_id[]" required><option value="">- pilih produk -</option><?php foreach($products as $p): ?><option value="<?php echo e((string)$p['id']); ?>"><?php echo e($p['name']); ?></option><?php endforeach; ?></select></td><td><input name="item_qty[]" type="number" step="0.0001" min="0.0001" value="1" required></td><td><input name="item_notes[]"></td><td><button class="btn danger" type="button" onclick="if(document.querySelectorAll('#items tbody tr').length>1)this.closest('tr').remove()">Hapus</button></td></tr></tbody></table><button class="btn" type="button" onclick="addItem()">Tambah Item</button> <button class="btn" type="submit">Kirim Transfer</button></form></div><div class="card"><h3>Riwayat Transfer</h3><table class="table"><thead><tr><th>No</th><th>Dari</th><th>Ke</th><th>Status</th><th>Dikirim</th><th>Diterima</th><th>Aksi</th></tr></thead><tbody><?php foreach($rows as $r): ?><tr><td><?php echo e($r['transfer_no']); ?></td><td><?php echo e($r['from_name'] ?? '-'); ?></td><td><?php echo e($r['to_name'] ?? '-'); ?></td><td><?php echo e($r['status']); ?></td><td><?php echo e($r['sent_at'] ?? '-'); ?></td><td><?php echo e($r['accepted_at'] ?? '-'); ?></td><td><?php if(($r['status'] ?? '')==='sent'): ?><form method="post" onsubmit="return confirm('Batalkan transfer ini dan kembalikan stok asal?')"><input type="hidden" name="_csrf" value="<?php echo e(csrf_token()); ?>"><input type="hidden" name="action" value="cancel"><input type="hidden" name="id" value="<?php echo e((string)$r['id']); ?>"><button class="btn danger">Cancel</button></form><?php endif; ?></td></tr><?php endforeach; if(!$rows): ?><tr><td colspan="7" style="text-align:center;color:#94a3b8">Belum ada transfer stok.</td></tr><?php endif; ?></tbody></table></div></div></div></div><script src="<?php echo e(asset_url('assets/app.js')); ?>"></script><script>function addItem(){const tb=document.querySelector('#items tbody');const tr=tb.rows[0].cloneNode(true);tr.querySelectorAll('input').forEach(i=>{i.value=i.name.includes('qty')?'1':''});tr.querySelectorAll('select').forEach(s=>s.selectedIndex=0);tb.appendChild(tr);}</script></body></html>
