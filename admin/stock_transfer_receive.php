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

$err=''; $msg='';
$schemaReason='';
$schemaReady = adena_stu_schema_ready($schemaReason);
if (!$schemaReady) $err = 'Database Penerimaan Stok belum lengkap: '.$schemaReason.' Jalankan db/update_stock_transfer_unified_final.sql.';

if ($schemaReady && $_SERVER['REQUEST_METHOD'] === 'POST') {
  try {
    csrf_check();
    require_action_access('inventori', 'edit');
    $id = (int)($_POST['id'] ?? 0);
    $action = (string)($_POST['action'] ?? '');
    $note = trim((string)($_POST['receiver_notes'] ?? ''));
    if ($id <= 0 || !in_array($action, ['accept','reject'], true)) throw new Exception('Aksi penerimaan tidak valid.');
    $db = db();
    $db->beginTransaction();
    $stmt = $db->prepare("SELECT st.*, fl.branch_id from_branch_id, tl.branch_id to_branch_id FROM stock_transfers st LEFT JOIN stock_locations fl ON fl.id=st.from_location_id LEFT JOIN stock_locations tl ON tl.id=st.to_location_id WHERE st.id=? AND st.status='sent' LIMIT 1 FOR UPDATE");
    $stmt->execute([$id]);
    $transfer = $stmt->fetch(PDO::FETCH_ASSOC);
    if (!$transfer) throw new Exception('Transfer tidak ditemukan atau sudah diproses.');
    $itemsStmt = $db->prepare("SELECT * FROM stock_transfer_items WHERE transfer_id=? ORDER BY id ASC");
    $itemsStmt->execute([$id]);
    $items = $itemsStmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
    if ($action === 'reject') {
      $fromBranch = (int)($transfer['from_branch_id'] ?? 0); if ($fromBranch <= 0) $fromBranch = (int)(function_exists('active_branch_id') ? active_branch_id() : 1);
      foreach ($items as $it) {
        adena_stu_insert_ledger([
          'branch_id'=>$fromBranch, 'location_id'=>(int)$transfer['from_location_id'], 'product_id'=>(int)$it['product_id'],
          'trans_type'=>'transfer_reject_return', 'ref_table'=>'stock_transfers', 'ref_id'=>$id,
          'qty_in'=>(float)$it['qty'], 'qty_out'=>0, 'unit_cost'=>null,
          'note'=>'Transfer ditolak '.$transfer['transfer_no'], 'created_by'=>(int)($u['id'] ?? 0)
        ]);
      }
      $db->prepare("UPDATE stock_transfers SET status='rejected',rejected_at=NOW(),received_by=?,receiver_notes=? WHERE id=?")->execute([(int)($u['id'] ?? 0), $note, $id]);
      $msg = 'Transfer ditolak. Stok asal dikembalikan.';
    } else {
      $toBranch = (int)($transfer['to_branch_id'] ?? 0); if ($toBranch <= 0) $toBranch = (int)(function_exists('active_branch_id') ? active_branch_id() : 1);
      foreach ($items as $it) {
        adena_stu_insert_ledger([
          'branch_id'=>$toBranch, 'location_id'=>(int)$transfer['to_location_id'], 'product_id'=>(int)$it['product_id'],
          'trans_type'=>'transfer_in', 'ref_table'=>'stock_transfers', 'ref_id'=>$id,
          'qty_in'=>(float)$it['qty'], 'qty_out'=>0, 'unit_cost'=>$it['unit_cost'] ?? null,
          'note'=>'Transfer stok diterima '.$transfer['transfer_no'], 'created_by'=>(int)($u['id'] ?? 0)
        ]);
      }
      $db->prepare("UPDATE stock_transfers SET status='accepted',accepted_at=NOW(),received_by=?,receiver_notes=? WHERE id=?")->execute([(int)($u['id'] ?? 0), $note, $id]);
      $msg = 'Transfer diterima. Stok tujuan sudah bertambah.';
    }
    $db->commit();
  } catch (Throwable $e) {
    try { if (isset($db) && $db->inTransaction()) $db->rollBack(); } catch (Throwable $ignore) {}
    $err = $e->getMessage();
  }
}

$rows=[];
if ($schemaReady) {
  try {
    $rows = db()->query("SELECT st.*, fl.location_name from_name, tl.location_name to_name
      FROM stock_transfers st
      LEFT JOIN stock_locations fl ON fl.id=st.from_location_id
      LEFT JOIN stock_locations tl ON tl.id=st.to_location_id
      WHERE st.status='sent'
      ORDER BY st.id DESC LIMIT 100")->fetchAll(PDO::FETCH_ASSOC) ?: [];
  } catch (Throwable $e) { $err = $err ?: $e->getMessage(); }
}
$customCss=setting('custom_css','');
?>
<!doctype html><html><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"><title>Penerimaan Stok</title><link rel="icon" href="<?php echo e(favicon_url()); ?>"><link rel="stylesheet" href="<?php echo e(asset_url('assets/app.css')); ?>"><style><?php echo $customCss; ?></style></head><body><div class="container"><?php include __DIR__ . '/partials_sidebar.php'; ?><div class="main"><div class="topbar"><button class="btn" data-toggle-sidebar type="button">Menu</button><strong>Penerimaan Stok dari Dapur</strong></div><div class="content"><?php if($err): ?><div class="alert danger"><?php echo e($err); ?></div><?php endif; ?><?php if($msg): ?><div class="alert success"><?php echo e($msg); ?></div><?php endif; ?><div class="card"><h3>Transfer Menunggu Penerimaan</h3><p style="color:#64748b">Approve bila barang sudah diterima toko/cabang. Stok tujuan bertambah setelah approve. Jika ditolak, stok dikembalikan ke lokasi asal.</p><table class="table"><thead><tr><th>No</th><th>Dari</th><th>Tujuan</th><th>Catatan Kirim</th><th>Aksi</th></tr></thead><tbody><?php foreach($rows as $r): ?><tr><td><?php echo e($r['transfer_no']); ?></td><td><?php echo e($r['from_name'] ?? '-'); ?></td><td><?php echo e($r['to_name'] ?? '-'); ?></td><td><?php echo e($r['notes'] ?? ''); ?></td><td><form method="post" style="display:flex;gap:6px;flex-wrap:wrap;align-items:center"><input type="hidden" name="_csrf" value="<?php echo e(csrf_token()); ?>"><input type="hidden" name="id" value="<?php echo e((string)$r['id']); ?>"><input name="receiver_notes" placeholder="Catatan penerimaan"><button class="btn" name="action" value="accept">Terima</button><button class="btn danger" name="action" value="reject" onclick="return confirm('Tolak transfer ini dan kembalikan stok asal?')">Tolak</button></form></td></tr><?php endforeach; if(!$rows): ?><tr><td colspan="5" style="text-align:center;color:#94a3b8">Tidak ada transfer menunggu penerimaan.</td></tr><?php endif; ?></tbody></table></div></div></div></div><script src="<?php echo e(asset_url('assets/app.js')); ?>"></script></body></html>
