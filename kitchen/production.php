<?php
require_once __DIR__ . '/../core/ops14.php';
require_once __DIR__ . '/../core/csrf.php';
require_once __DIR__ . '/../core/portal_inventory.php';
require_once __DIR__ . '/_layout.php';
$u = adena14_area_guard('kitchen');
ensure_production_tables(); ensure_bom_tables();
$locationId = portal_inventory_kitchen_location_id();
$err=''; $msg='';
if ($_SERVER['REQUEST_METHOD']==='POST') {
  csrf_check();
  $db=db();
  try {
    $bomId=(int)($_POST['bom_id'] ?? 0); $qty=parse_number_input($_POST['qty_to_produce'] ?? 0); if($bomId<=0 || $qty<=0) throw new Exception('BOM dan qty produksi wajib diisi.');
    $stmt=$db->prepare("SELECT bh.*, p.name finished_name FROM bom_headers bh JOIN products p ON p.id=bh.finished_product_id WHERE bh.id=? AND bh.is_active=1 LIMIT 1"); $stmt->execute([$bomId]); $bom=$stmt->fetch(PDO::FETCH_ASSOC); if(!$bom) throw new Exception('BOM tidak ditemukan/aktif.');
    $itemsStmt=$db->prepare("SELECT bi.*, p.name material_name FROM bom_items bi JOIN products p ON p.id=bi.material_product_id WHERE bi.bom_id=?"); $itemsStmt->execute([$bomId]); $items=$itemsStmt->fetchAll(PDO::FETCH_ASSOC); if(empty($items)) throw new Exception('BOM belum memiliki bahan.');
    $factor=$qty / max(0.000001,(float)$bom['yield_qty']);
    foreach($items as $it){ $need=round((float)$it['qty_per_yield']*$factor,4); if(portal_inventory_stock_qty($locationId,(int)$it['material_product_id']) + 0.00001 < $need) throw new Exception('Stok bahan tidak cukup: '.$it['material_name']); }
    $db->beginTransaction();
    $no=portal_inventory_generate_no('PRD','production_headers','production_no');
    $branchId=1; // kompatibilitas tabel produksi lama; stok aktual tetap memakai location_id dapur di ledger.
    $ins=$db->prepare("INSERT INTO production_headers (production_no,branch_id,bom_id,finished_product_id,production_date,qty_to_produce,status,mode_source,notes,created_by,posted_by,posted_at) VALUES (?,?,?,?,CURDATE(),?,'posted','manual_menu',?,?,?,NOW())");
    $ins->execute([$no,$branchId,$bomId,(int)$bom['finished_product_id'],$qty,trim((string)($_POST['notes'] ?? '')) ?: null,(int)$u['id'],(int)$u['id']]);
    $prodId=(int)$db->lastInsertId();
    $itemIns=$db->prepare("INSERT INTO production_items (production_id,material_product_id,required_qty,actual_qty,unit_cost) VALUES (?,?,?,?,NULL)");
    foreach($items as $it){ $need=round((float)$it['qty_per_yield']*$factor,4); $itemIns->execute([$prodId,(int)$it['material_product_id'],$need,$need]); portal_inventory_add_ledger(['location_id'=>$locationId,'product_id'=>(int)$it['material_product_id'],'trans_type'=>'production_consume','ref_table'=>'production_headers','ref_id'=>$prodId,'qty_in'=>0,'qty_out'=>$need,'note'=>'Produksi '.$no,'created_by'=>(int)$u['id']]); }
    portal_inventory_add_ledger(['location_id'=>$locationId,'product_id'=>(int)$bom['finished_product_id'],'trans_type'=>'production_output','ref_table'=>'production_headers','ref_id'=>$prodId,'qty_in'=>$qty,'qty_out'=>0,'note'=>'Output produksi '.$no,'created_by'=>(int)$u['id']]);
    $db->commit(); $msg='Produksi berhasil diposting ke stok dapur.';
  } catch(Throwable $e) { if(isset($db) && $db->inTransaction()) $db->rollBack(); $err=$e->getMessage(); }
}
$boms=db()->query("SELECT bh.*, p.name finished_name FROM bom_headers bh JOIN products p ON p.id=bh.finished_product_id WHERE bh.is_active=1 ORDER BY bh.bom_name ASC")->fetchAll(PDO::FETCH_ASSOC) ?: [];
$rows=db()->query("SELECT ph.*, p.name finished_name FROM production_headers ph JOIN products p ON p.id=ph.finished_product_id ORDER BY ph.id DESC LIMIT 50")->fetchAll(PDO::FETCH_ASSOC) ?: [];
$customCss=setting('custom_css','');
kitchen_header('Produksi Dapur', $customCss);
?>
<div class="card"><h3>Input Produksi</h3><p class="portal-note">Produksi mengurangi stok bahan di Dapur dan menambah stok produk jadi di Dapur.</p><?php if($err): ?><div class="card" style="border-color:#fecdd3;background:#fff1f2;color:#9f1239"><?php echo e($err); ?></div><?php endif; ?><?php if($msg): ?><div class="card" style="border-color:#bbf7d0;background:#f0fdf4;color:#166534"><?php echo e($msg); ?></div><?php endif; ?><form method="post" class="grid cols-3"><input type="hidden" name="_csrf" value="<?php echo e(csrf_token()); ?>"><div class="row"><label>BOM</label><select name="bom_id" required><?php foreach($boms as $b): ?><option value="<?php echo e((string)$b['id']); ?>"><?php echo e($b['bom_name'].' → '.$b['finished_name']); ?></option><?php endforeach; ?></select></div><div class="row"><label>Qty Produksi</label><input name="qty_to_produce" inputmode="decimal" required placeholder="0.00"></div><div class="row"><label>Catatan</label><input name="notes" placeholder="opsional"></div><div class="row" style="align-self:end"><button class="btn" type="submit">Posting Produksi</button></div></form></div>
<div class="card"><h3>Riwayat Produksi</h3><table class="table"><thead><tr><th>No</th><th>Tanggal</th><th>Produk Jadi</th><th>Qty</th><th>Status</th></tr></thead><tbody><?php foreach($rows as $r): ?><tr><td><?php echo e((string)$r['production_no']); ?></td><td><?php echo e((string)$r['production_date']); ?></td><td><?php echo e((string)$r['finished_name']); ?></td><td><?php echo e((string)$r['qty_to_produce']); ?></td><td><?php echo e((string)$r['status']); ?></td></tr><?php endforeach; ?></tbody></table></div>
<?php kitchen_footer(); ?>
