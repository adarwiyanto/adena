<?php
require_once __DIR__ . '/../core/db.php';
require_once __DIR__ . '/../core/functions.php';
require_once __DIR__ . '/../core/security.php';
require_once __DIR__ . '/../core/auth.php';
require_once __DIR__ . '/../core/csrf.php';
require_once __DIR__ . '/../core/rbac.php';
require_once __DIR__ . '/../core/inventory.php';
if (file_exists(__DIR__ . '/../core/ops14.php')) { require_once __DIR__ . '/../core/ops14.php'; }



// HARDFIX fallback: halaman tetap hidup walau core/ops14.php belum ter-upload.
if (!function_exists('ensure_adena14_schema')) {
  function ensure_adena14_schema(): void {
    static $done=false; if($done) return; $done=true;
    try { $db=db(); } catch (Throwable $e) { return; }
    $safe = static function(string $sql) use ($db): void { try { $db->exec($sql); } catch (Throwable $e) {} };
    $safe("CREATE TABLE IF NOT EXISTS stock_locations (
      id INT AUTO_INCREMENT PRIMARY KEY,
      location_code VARCHAR(40) NOT NULL,
      location_name VARCHAR(160) NOT NULL,
      location_type ENUM('kitchen','store','branch') NOT NULL DEFAULT 'branch',
      branch_id INT NULL,
      is_active TINYINT(1) NOT NULL DEFAULT 1,
      created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
      updated_at TIMESTAMP NULL DEFAULT NULL ON UPDATE CURRENT_TIMESTAMP,
      UNIQUE KEY uniq_stock_locations_code (location_code)
    ) ENGINE=InnoDB");
    $safe("ALTER TABLE stock_locations ADD COLUMN location_code VARCHAR(40) NOT NULL AFTER id");
    $safe("ALTER TABLE stock_locations ADD COLUMN location_name VARCHAR(160) NOT NULL AFTER location_code");
    $safe("ALTER TABLE stock_locations ADD COLUMN location_type ENUM('kitchen','store','branch') NOT NULL DEFAULT 'branch' AFTER location_name");
    $safe("ALTER TABLE stock_locations ADD COLUMN branch_id INT NULL AFTER location_type");
    $safe("ALTER TABLE stock_locations ADD COLUMN is_active TINYINT(1) NOT NULL DEFAULT 1 AFTER branch_id");

    $safe("CREATE TABLE IF NOT EXISTS stock_transfers (
      id BIGINT AUTO_INCREMENT PRIMARY KEY,
      transfer_no VARCHAR(60) NOT NULL,
      from_location_id INT NOT NULL,
      to_location_id INT NOT NULL,
      status ENUM('draft','sent','accepted','rejected','cancelled') NOT NULL DEFAULT 'draft',
      sent_at TIMESTAMP NULL DEFAULT NULL,
      accepted_at TIMESTAMP NULL DEFAULT NULL,
      rejected_at TIMESTAMP NULL DEFAULT NULL,
      created_by INT NULL,
      sent_by INT NULL,
      received_by INT NULL,
      notes TEXT NULL,
      receiver_notes TEXT NULL,
      created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
      updated_at TIMESTAMP NULL DEFAULT NULL ON UPDATE CURRENT_TIMESTAMP,
      UNIQUE KEY uniq_stock_transfer_no (transfer_no)
    ) ENGINE=InnoDB");
    foreach(['transfer_no VARCHAR(60) NOT NULL','from_location_id INT NOT NULL','to_location_id INT NOT NULL',"status ENUM('draft','sent','accepted','rejected','cancelled') NOT NULL DEFAULT 'draft'",'sent_at TIMESTAMP NULL DEFAULT NULL','accepted_at TIMESTAMP NULL DEFAULT NULL','rejected_at TIMESTAMP NULL DEFAULT NULL','created_by INT NULL','sent_by INT NULL','received_by INT NULL','notes TEXT NULL','receiver_notes TEXT NULL'] as $def){ $safe('ALTER TABLE stock_transfers ADD COLUMN '.$def); }

    $safe("CREATE TABLE IF NOT EXISTS stock_transfer_items (
      id BIGINT AUTO_INCREMENT PRIMARY KEY,
      transfer_id BIGINT NOT NULL,
      product_id INT NOT NULL,
      qty DECIMAL(18,4) NOT NULL DEFAULT 0,
      unit_cost DECIMAL(18,2) NULL,
      note VARCHAR(255) NULL,
      created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
    ) ENGINE=InnoDB");
    foreach(['transfer_id BIGINT NOT NULL','product_id INT NOT NULL','qty DECIMAL(18,4) NOT NULL DEFAULT 0','unit_cost DECIMAL(18,2) NULL','note VARCHAR(255) NULL'] as $def){ $safe('ALTER TABLE stock_transfer_items ADD COLUMN '.$def); }

    $safe("ALTER TABLE stock_ledger ADD COLUMN location_id INT NULL AFTER branch_id");
    try {
      $db->exec("INSERT INTO stock_locations (location_code,location_name,location_type,branch_id,is_active)
        SELECT 'KITCHEN','Dapur Produksi','kitchen',1,1 FROM DUAL
        WHERE NOT EXISTS (SELECT 1 FROM stock_locations WHERE location_code='KITCHEN')");
      $db->exec("INSERT INTO stock_locations (location_code,location_name,location_type,branch_id,is_active)
        SELECT CONCAT('TOKO-',branch_code), branch_name, 'branch', id, 1 FROM branches b
        WHERE NOT EXISTS (SELECT 1 FROM stock_locations sl WHERE sl.branch_id=b.id)");
    } catch (Throwable $e) {}
  }
}
if (!function_exists('adena14_locations')) {
  function adena14_locations(): array {
    ensure_adena14_schema();
    try { return db()->query("SELECT * FROM stock_locations WHERE is_active=1 ORDER BY FIELD(location_type,'kitchen','store','branch'), location_name")->fetchAll(PDO::FETCH_ASSOC) ?: []; }
    catch (Throwable $e) { return []; }
  }
}

start_secure_session();
require_admin();
ensure_rbac_schema();
$me = require_menu_access('inventori', 'view');
ensure_inventory_module_schema();
ensure_adena14_schema();
csrf_token();

$u = current_user() ?: [];
$err = '';
$msg = '';

function adena_transfer_schema_ready(?string &$reason = null): bool {
  try {
    $db = db();
    $must = [
      'stock_locations' => ['id','location_code','location_name','location_type','branch_id','is_active'],
      'stock_transfers' => ['id','transfer_no','from_location_id','to_location_id','status','sent_at','accepted_at','rejected_at','created_by','sent_by','received_by','notes','receiver_notes'],
      'stock_transfer_items' => ['id','transfer_id','product_id','qty','note'],
      'stock_ledger' => ['branch_id','location_id','product_id','trans_type','ref_table','ref_id','qty_in','qty_out']
    ];
    foreach ($must as $table => $cols) {
      $q = $db->prepare("SELECT COLUMN_NAME FROM INFORMATION_SCHEMA.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ?");
      $q->execute([$table]);
      $have = array_flip($q->fetchAll(PDO::FETCH_COLUMN) ?: []);
      if (!$have) { $reason = "Tabel {$table} belum ada."; return false; }
      foreach ($cols as $c) if (!isset($have[$c])) { $reason = "Kolom {$table}.{$c} belum ada."; return false; }
    }
    return true;
  } catch (Throwable $e) { $reason = $e->getMessage(); return false; }
}

function adena_loc_branch_id(array $loc): int {
  $bid = (int)($loc['branch_id'] ?? 0);
  return $bid > 0 ? $bid : (int)(function_exists('active_branch_id') ? active_branch_id() : 1);
}
function adena_transfer_items(array $src): array {
  $pids = $src['item_product_id'] ?? [];
  $qtys = $src['item_qty'] ?? [];
  $notes = $src['item_notes'] ?? [];
  foreach (['pids','qtys','notes'] as $v) if (!is_array($$v)) $$v = [];
  $items=[]; $max=max(count($pids), count($qtys), count($notes));
  for($i=0;$i<$max;$i++){
    $pid=(int)($pids[$i]??0); $qty=parse_number_input($qtys[$i]??0); $note=trim((string)($notes[$i]??''));
    if($pid<=0 && $qty<=0 && $note==='') continue;
    if($pid<=0 || $qty<=0) throw new Exception('Produk dan qty transfer wajib valid.');
    $items[]=['product_id'=>$pid,'qty'=>$qty,'note'=>$note];
  }
  if(!$items) throw new Exception('Minimal 1 item transfer wajib diisi.');
  return $items;
}

$schemaReason = '';
$schemaReady = adena_transfer_schema_ready($schemaReason);
$locations = $schemaReady ? adena14_locations() : [];
$products = $schemaReady ? (db()->query("SELECT id,name FROM products WHERE is_active=1 ORDER BY name ASC")->fetchAll(PDO::FETCH_ASSOC) ?: []) : [];
$locById=[]; foreach($locations as $l) $locById[(int)$l['id']]=$l;
if (!$schemaReady) { $err = 'Database Transfer Stok belum lengkap: '.$schemaReason.' Jalankan db/update_store_receive_v2_REPAIR.sql.'; }

if ($schemaReady && $_SERVER['REQUEST_METHOD'] === 'POST') {
  try {
    csrf_check();
    $fromId=(int)($_POST['from_location_id']??0);
    $toId=(int)($_POST['to_location_id']??0);
    $notes=trim((string)($_POST['notes']??''));
    if($fromId<=0 || $toId<=0 || $fromId===$toId) throw new Exception('Lokasi asal/tujuan transfer tidak valid.');
    if(!isset($locById[$fromId]) || !isset($locById[$toId])) throw new Exception('Lokasi transfer tidak ditemukan.');
    $items=adena_transfer_items($_POST);
    $db=db();
    $db->beginTransaction();
    $no='TRF-'.date('YmdHis');
    $stmt=$db->prepare("INSERT INTO stock_transfers (transfer_no,from_location_id,to_location_id,status,sent_at,created_by,sent_by,notes) VALUES (?,?,?,'sent',NOW(),?,?,?)");
    $stmt->execute([$no,$fromId,$toId,(int)($u['id']??0),(int)($u['id']??0),$notes]);
    $transferId=(int)$db->lastInsertId();
    $itemStmt=$db->prepare("INSERT INTO stock_transfer_items (transfer_id,product_id,qty,note) VALUES (?,?,?,?)");
    $ledger=$db->prepare("INSERT INTO stock_ledger (branch_id,location_id,product_id,trans_type,ref_table,ref_id,qty_in,qty_out,note,created_by) VALUES (?,?,?,?,?,?,0,?,?,?)");
    $fromBranch=adena_loc_branch_id($locById[$fromId]);
    foreach($items as $it){
      $itemStmt->execute([$transferId,$it['product_id'],$it['qty'],$it['note']]);
      $ledger->execute([$fromBranch,$fromId,$it['product_id'],'transfer_out','stock_transfers',$transferId,$it['qty'],'Transfer stok keluar '.$no,(int)($u['id']??0)]);
    }
    $db->commit();
    $msg='Transfer stok berhasil dikirim. Stok tujuan baru bertambah setelah diterima/approve.';
  } catch(Throwable $e) { try { if(db()->inTransaction()) db()->rollBack(); } catch(Throwable $ignore) {} $err=$e->getMessage(); }
}

$rows=[];
if ($schemaReady) try { $rows=db()->query("SELECT st.*, fl.location_name from_name, tl.location_name to_name FROM stock_transfers st LEFT JOIN stock_locations fl ON fl.id=st.from_location_id LEFT JOIN stock_locations tl ON tl.id=st.to_location_id ORDER BY st.id DESC LIMIT 100")->fetchAll(PDO::FETCH_ASSOC) ?: []; } catch(Throwable $e) { $err=$err ?: $e->getMessage(); }
$customCss=setting('custom_css','');
?>
<!doctype html><html><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"><title>Transfer Stok</title><link rel="icon" href="<?php echo e(favicon_url()); ?>"><link rel="stylesheet" href="<?php echo e(asset_url('assets/app.css')); ?>"><style><?php echo $customCss; ?></style></head><body><div class="container"><?php include __DIR__ . '/partials_sidebar.php'; ?><div class="main"><div class="topbar"><button class="btn" data-toggle-sidebar type="button">Menu</button><strong>Transfer Stok</strong></div><div class="content"><?php if($err): ?><div class="alert danger"><?php echo e($err); ?></div><?php endif; ?><?php if($msg): ?><div class="alert success"><?php echo e($msg); ?></div><?php endif; ?><div class="card"><h3>Kirim Transfer Stok</h3><p style="color:#64748b">Gunakan untuk kirim stok dari dapur ke toko/cabang. Toko menerima melalui menu Penerimaan Stok.</p><form method="post"><?php echo csrf_field(); ?><div class="grid2"><label>Dari Lokasi<select name="from_location_id" required><option value="">- pilih asal -</option><?php foreach($locations as $l): ?><option value="<?php echo e((string)$l['id']); ?>"><?php echo e($l['location_name'].' ('.$l['location_type'].')'); ?></option><?php endforeach; ?></select></label><label>Ke Lokasi<select name="to_location_id" required><option value="">- pilih tujuan -</option><?php foreach($locations as $l): ?><option value="<?php echo e((string)$l['id']); ?>"><?php echo e($l['location_name'].' ('.$l['location_type'].')'); ?></option><?php endforeach; ?></select></label></div><label>Catatan<textarea name="notes" placeholder="Catatan transfer"></textarea></label><table class="table" id="items"><thead><tr><th>Produk</th><th>Qty</th><th>Catatan</th><th>Aksi</th></tr></thead><tbody><tr><td><select name="item_product_id[]" required><option value="">- pilih produk -</option><?php foreach($products as $p): ?><option value="<?php echo e((string)$p['id']); ?>"><?php echo e($p['name']); ?></option><?php endforeach; ?></select></td><td><input name="item_qty[]" type="number" step="0.0001" value="1" required></td><td><input name="item_notes[]"></td><td><button class="btn btn-danger" type="button" onclick="this.closest('tr').remove()">Hapus</button></td></tr></tbody></table><button class="btn btn-light" type="button" onclick="addItem()">Tambah Item</button> <button class="btn" type="submit">Kirim Transfer</button></form></div><div class="card"><h3>Riwayat Transfer</h3><table class="table"><thead><tr><th>No</th><th>Dari</th><th>Ke</th><th>Status</th><th>Dikirim</th><th>Diterima</th></tr></thead><tbody><?php foreach($rows as $r): ?><tr><td><?php echo e($r['transfer_no']); ?></td><td><?php echo e($r['from_name'] ?? '-'); ?></td><td><?php echo e($r['to_name'] ?? '-'); ?></td><td><?php echo e($r['status']); ?></td><td><?php echo e($r['sent_at'] ?? '-'); ?></td><td><?php echo e($r['accepted_at'] ?? '-'); ?></td></tr><?php endforeach; if(!$rows): ?><tr><td colspan="6" style="text-align:center;color:#94a3b8">Belum ada transfer stok.</td></tr><?php endif; ?></tbody></table></div></div></div></div><script src="<?php echo e(asset_url('assets/app.js')); ?>"></script><script>function addItem(){const tb=document.querySelector('#items tbody'); const tr=tb.rows[0].cloneNode(true); tr.querySelectorAll('input').forEach(i=>{i.value=i.name.includes('qty')?'1':''}); tr.querySelectorAll('select').forEach(s=>s.selectedIndex=0); tb.appendChild(tr);}</script></body></html>
