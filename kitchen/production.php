<?php
require_once __DIR__ . '/../core/portal_area_guard.php';
require_once __DIR__ . '/../core/csrf.php';
require_once __DIR__ . '/../core/portal_inventory.php';
require_once __DIR__ . '/_layout.php';
$u = portal_light_area_guard('kitchen');
$customCss = setting('custom_css','');
$err=''; $msg=''; $boms=[]; $rows=[]; $locationId=1;
try { $locationId=portal_inventory_kitchen_location_id(); } catch(Throwable $e){ $err=$e->getMessage(); }
if ($_SERVER['REQUEST_METHOD']==='POST') {
  csrf_check();
  try {
    if (!portal_table_exists('production_headers') || !portal_table_exists('production_items')) throw new Exception('Tabel produksi belum tersedia.');
    $bomId=(int)($_POST['bom_id'] ?? 0);
    $qty=parse_number_input($_POST['qty_to_produce'] ?? 0);
    if($bomId<=0 || $qty<=0) throw new Exception('BOM dan qty produksi wajib diisi.');
    $db=db(); $db->beginTransaction();
    $stmt=$db->prepare("SELECT bh.*, p.name finished_name FROM bom_headers bh JOIN products p ON p.id=bh.finished_product_id WHERE bh.id=? AND bh.is_active=1 LIMIT 1");
    $stmt->execute([$bomId]); $bom=$stmt->fetch(PDO::FETCH_ASSOC); if(!$bom) throw new Exception('BOM tidak ditemukan/aktif.');
    $itemsStmt=$db->prepare("SELECT bi.*, p.name material_name FROM bom_items bi JOIN products p ON p.id=bi.material_product_id WHERE bi.bom_id=?");
    $itemsStmt->execute([$bomId]); $items=$itemsStmt->fetchAll(PDO::FETCH_ASSOC); if(empty($items)) throw new Exception('BOM belum memiliki bahan.');
    $yield=(float)$bom['yield_qty']; if($yield<=0) $yield=1;
    foreach($items as $it){ $need=((float)$it['qty_per_yield']/$yield)*$qty; if(portal_inventory_stock_qty($locationId,(int)$it['material_product_id'])+0.00001 < $need) throw new Exception('Stok bahan tidak cukup: '.(string)$it['material_name']); }
    $prodNo=portal_inventory_generate_no('PRD','production_headers','production_no');
    $branchId=1; // kompatibilitas tabel production_headers lama.
    $ins=$db->prepare("INSERT INTO production_headers (production_no,branch_id,bom_id,finished_product_id,production_date,qty_to_produce,status,mode_source,notes,created_by,posted_by,posted_at) VALUES (?,?,?,?,CURDATE(),?,'posted','manual_menu',?,?,?,NOW())");
    $ins->execute([$prodNo,$branchId,$bomId,(int)$bom['finished_product_id'],$qty,trim((string)($_POST['notes'] ?? '')) ?: null,(int)$u['id'],(int)$u['id']]);
    $prodId=(int)$db->lastInsertId();
    $itemIns=$db->prepare("INSERT INTO production_items (production_id,material_product_id,required_qty,actual_qty,unit_cost) VALUES (?,?,?,?,NULL)");
    foreach($items as $it){ $need=((float)$it['qty_per_yield']/$yield)*$qty; $itemIns->execute([$prodId,(int)$it['material_product_id'],$need,$need]); portal_inventory_add_ledger(['location_id'=>$locationId,'product_id'=>(int)$it['material_product_id'],'trans_type'=>'production_material_out','ref_table'=>'production_headers','ref_id'=>$prodId,'qty_in'=>0,'qty_out'=>$need,'note'=>'Produksi '.$prodNo,'created_by'=>(int)$u['id']]); }
    portal_inventory_add_ledger(['location_id'=>$locationId,'product_id'=>(int)$bom['finished_product_id'],'trans_type'=>'production_finished_in','ref_table'=>'production_headers','ref_id'=>$prodId,'qty_in'=>$qty,'qty_out'=>0,'note'=>'Produksi '.$prodNo,'created_by'=>(int)$u['id']]);
    $db->commit(); $msg='Produksi berhasil diposting.';
  } catch(Throwable $e){ if(isset($db) && $db instanceof PDO && $db->inTransaction()) $db->rollBack(); $err=$e->getMessage(); }
}
try {
  if (portal_table_exists('bom_headers')) $boms=db()->query("SELECT bh.id,bh.bom_code,bh.bom_name,p.name finished_name FROM bom_headers bh JOIN products p ON p.id=bh.finished_product_id WHERE bh.is_active=1 ORDER BY bh.bom_name ASC")->fetchAll(PDO::FETCH_ASSOC) ?: [];
  if (portal_table_exists('production_headers')) $rows=db()->query("SELECT ph.id,ph.production_no,ph.production_date,ph.qty_to_produce,ph.status,p.name finished_name FROM production_headers ph JOIN products p ON p.id=ph.finished_product_id ORDER BY ph.id DESC LIMIT 50")->fetchAll(PDO::FETCH_ASSOC) ?: [];
} catch(Throwable $e){ if(!$err) $err=$e->getMessage(); }
kitchen_header('Produksi Dapur', $customCss);
?>
<div class="card"><h3>Produksi Finished Good</h3><p class="portal-note">Produksi mengurangi bahan dan menambah produk jadi pada location_id dapur.</p><?php if($err): ?><div class="card" style="border-color:#fecdd3;background:#fff1f2;color:#9f1239"><?php echo e($err); ?></div><?php endif; ?><?php if($msg): ?><div class="card" style="border-color:#bbf7d0;background:#f0fdf4;color:#166534"><?php echo e($msg); ?></div><?php endif; ?><form method="post" class="grid cols-3"><input type="hidden" name="_csrf" value="<?php echo e(csrf_token()); ?>"><div class="row"><label>BOM</label><select name="bom_id" required><option value="0">-- pilih --</option><?php foreach($boms as $b): ?><option value="<?php echo e((string)$b['id']); ?>"><?php echo e((string)$b['bom_code'].' - '.(string)$b['bom_name'].' / '.(string)$b['finished_name']); ?></option><?php endforeach; ?></select></div><div class="row"><label>Qty Produksi</label><input name="qty_to_produce" inputmode="decimal" required></div><div class="row"><label>Catatan</label><input name="notes"></div><div class="row" style="align-self:end"><button class="btn" type="submit">Posting Produksi</button></div></form></div>
<div class="card"><h3>Riwayat Produksi</h3><table class="table"><thead><tr><th>No</th><th>Produk Jadi</th><th>Qty</th><th>Status</th><th>Tanggal</th></tr></thead><tbody><?php if(!$rows): ?><tr><td colspan="5" style="text-align:center;color:#94a3b8">Belum ada produksi.</td></tr><?php endif; foreach($rows as $r): ?><tr><td><?php echo e((string)$r['production_no']); ?></td><td><?php echo e((string)$r['finished_name']); ?></td><td><?php echo e((string)$r['qty_to_produce']); ?></td><td><?php echo e((string)$r['status']); ?></td><td><?php echo e((string)$r['production_date']); ?></td></tr><?php endforeach; ?></tbody></table></div>
<?php kitchen_footer(); ?>
