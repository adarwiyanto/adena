<?php
require_once __DIR__ . '/../core/ops14.php';
require_once __DIR__ . '/../core/csrf.php';
require_once __DIR__ . '/../core/portal_inventory.php';
require_once __DIR__ . '/_layout.php';
$u = adena14_area_guard('kitchen');
$customCss = setting('custom_css','');
$err=''; $msg=''; $finishedProducts=[]; $materials=[]; $boms=[];
try { $finishedProducts=portal_inventory_products('', 'finished'); $materials=portal_inventory_products('', 'raw'); } catch(Throwable $e){ $err=$e->getMessage(); }
if ($_SERVER['REQUEST_METHOD']==='POST') {
  csrf_check();
  try {
    if (!portal_table_exists('bom_headers') || !portal_table_exists('bom_items')) throw new Exception('Tabel BOM belum tersedia.');
    $finished=(int)($_POST['finished_product_id'] ?? 0);
    $name=trim((string)($_POST['bom_name'] ?? ''));
    $yield=parse_number_input($_POST['yield_qty'] ?? 1);
    if ($finished<=0 || $name==='') throw new Exception('Produk jadi dan nama BOM wajib diisi.');
    if ($yield<=0) throw new Exception('Yield wajib lebih dari 0.');
    $code=portal_inventory_generate_no('BOM','bom_headers','bom_code');
    $stmt=db()->prepare("INSERT INTO bom_headers (branch_id,finished_product_id,bom_code,bom_name,yield_qty,is_active,notes,created_by) VALUES (NULL,?,?,?,?,1,?,?)");
    $stmt->execute([$finished,$code,$name,$yield,trim((string)($_POST['notes'] ?? '')) ?: null,(int)$u['id']]);
    $bomId=(int)db()->lastInsertId();
    $itemStmt=db()->prepare("INSERT INTO bom_items (bom_id,material_product_id,qty_per_yield,unit_note,wastage_pct,sort_order) VALUES (?,?,?,?,0,?)");
    $order=0;
    foreach(($_POST['material_product_id'] ?? []) as $i=>$pidRaw){
      $pid=(int)$pidRaw; $qtyRaw=($_POST['qty_per_yield'] ?? [])[$i] ?? ''; $qty=trim((string)$qtyRaw)!=='' ? parse_number_input($qtyRaw) : 0;
      if($pid>0 && $qty>0){ $itemStmt->execute([$bomId,$pid,$qty,trim((string)(($_POST['unit_note'] ?? [])[$i] ?? '')) ?: null,$order++]); }
    }
    if ($order===0) throw new Exception('Minimal satu bahan BOM wajib diisi.');
    $msg='BOM berhasil dibuat.';
  } catch(Throwable $e) { $err=$e->getMessage(); }
}
try {
  if (portal_table_exists('bom_headers') && portal_table_exists('bom_items')) {
    $boms=db()->query("SELECT bh.id,bh.bom_code,bh.bom_name,bh.yield_qty,bh.is_active,p.name finished_name,
        (SELECT COUNT(*) FROM bom_items bi WHERE bi.bom_id=bh.id) item_count
      FROM bom_headers bh JOIN products p ON p.id=bh.finished_product_id
      ORDER BY bh.id DESC LIMIT 80")->fetchAll(PDO::FETCH_ASSOC) ?: [];
  }
} catch(Throwable $e){ if(!$err) $err=$e->getMessage(); }
kitchen_header('BOM Produk Dapur', $customCss);
?>
<div class="card"><h3>BOM Produk</h3><p class="portal-note">BOM dibuat dari portal dapur dan tersimpan di tabel BOM yang sama dengan sistem lama.</p><?php if($err): ?><div class="card" style="border-color:#fecdd3;background:#fff1f2;color:#9f1239"><?php echo e($err); ?></div><?php endif; ?><?php if($msg): ?><div class="card" style="border-color:#bbf7d0;background:#f0fdf4;color:#166534"><?php echo e($msg); ?></div><?php endif; ?><form method="post"><input type="hidden" name="_csrf" value="<?php echo e(csrf_token()); ?>"><div class="grid cols-3"><div class="row"><label>Produk Jadi</label><select name="finished_product_id" required><option value="0">-- pilih --</option><?php foreach($finishedProducts as $p): ?><option value="<?php echo e((string)$p['id']); ?>"><?php echo e((string)$p['name']); ?></option><?php endforeach; ?></select></div><div class="row"><label>Nama BOM</label><input name="bom_name" required placeholder="contoh: Ketam Isi 250gr"></div><div class="row"><label>Yield Qty</label><input name="yield_qty" value="1" inputmode="decimal" required></div></div><table class="table" style="margin-top:12px"><thead><tr><th>Bahan</th><th>Qty per Yield</th><th>Unit Note</th></tr></thead><tbody><?php for($i=0;$i<8;$i++): ?><tr><td><select name="material_product_id[]"><option value="0">-- pilih --</option><?php foreach($materials as $m): ?><option value="<?php echo e((string)$m['id']); ?>"><?php echo e((string)$m['name']); ?></option><?php endforeach; ?></select></td><td><input name="qty_per_yield[]" inputmode="decimal" placeholder="0.00"></td><td><input name="unit_note[]" placeholder="gram / pcs / kg"></td></tr><?php endfor; ?></tbody></table><div class="row"><label>Catatan</label><input name="notes" placeholder="opsional"></div><button class="btn" type="submit">Simpan BOM</button></form></div>
<div class="card"><h3>Daftar BOM</h3><table class="table"><thead><tr><th>Kode</th><th>Nama</th><th>Produk Jadi</th><th>Yield</th><th>Item</th><th>Status</th></tr></thead><tbody><?php if(!$boms): ?><tr><td colspan="6" style="text-align:center;color:#94a3b8">Belum ada BOM.</td></tr><?php endif; foreach($boms as $b): ?><tr><td><?php echo e((string)$b['bom_code']); ?></td><td><?php echo e((string)$b['bom_name']); ?></td><td><?php echo e((string)$b['finished_name']); ?></td><td><?php echo e((string)$b['yield_qty']); ?></td><td><?php echo e((string)$b['item_count']); ?></td><td><?php echo ((int)$b['is_active']===1)?'Aktif':'Nonaktif'; ?></td></tr><?php endforeach; ?></tbody></table></div>
<?php kitchen_footer(); ?>
