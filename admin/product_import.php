<?php
require_once __DIR__ . '/../core/db.php';
require_once __DIR__ . '/../core/functions.php';
require_once __DIR__ . '/../core/security.php';
require_once __DIR__ . '/../core/auth.php';
require_once __DIR__ . '/../core/csrf.php';
require_once __DIR__ . '/../core/rbac.php';
require_once __DIR__ . '/../core/inventory.php';

start_secure_session(); require_admin(); ensure_rbac_schema(); $u=require_menu_access('produk','create'); ensure_inventory_module_schema(); ensure_products_category_column(); ensure_products_best_seller_column();
$err=''; $ok=''; $branches=inventory_branches(); $sourceBranchId=(int)($_GET['source_branch_id']??0);
if($sourceBranchId>0){
  $stmt=db()->prepare("SELECT DISTINCT p.* FROM products p LEFT JOIN stock_ledger sl ON sl.product_id=p.id WHERE sl.branch_id=? OR NOT EXISTS (SELECT 1 FROM stock_ledger sl2 WHERE sl2.product_id=p.id) ORDER BY p.name ASC"); $stmt->execute([$sourceBranchId]); $sourceProducts=$stmt->fetchAll();
}else{ $sourceProducts=db()->query("SELECT * FROM products ORDER BY name ASC")->fetchAll(); }
if($_SERVER['REQUEST_METHOD']==='POST'){
  csrf_check();
  try{
    $mode=(string)($_POST['mode']??'selected'); $ids=$_POST['product_ids']??[]; if(!is_array($ids))$ids=[]; $ids=array_values(array_unique(array_map('intval',$ids)));
    if($mode==='all'){ $ids=array_map(fn($p)=>(int)$p['id'],$sourceProducts); }
    if(!$ids) throw new Exception('Pilih minimal 1 produk, atau gunakan impor semua.');
    $in=implode(',',array_fill(0,count($ids),'?')); $stmt=db()->prepare("SELECT * FROM products WHERE id IN ($in) ORDER BY name ASC"); $stmt->execute($ids); $rows=$stmt->fetchAll();
    $check=db()->prepare("SELECT id FROM products WHERE name=? LIMIT 1");
    $ins=db()->prepare("INSERT INTO products (name, category, is_best_seller, price, image_path, product_type, track_stock, allow_direct_purchase, allow_bom, show_on_pos, show_on_landing, base_unit, purchase_unit, purchase_to_base_factor, sale_unit, sale_to_base_factor) VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?)");
    $count=0; foreach($rows as $p){ $name=(string)$p['name']; $check->execute([$name]); if($check->fetch()){ $name=$name.' - copy '.date('His'); }
      $ins->execute([$name,$p['category']??'',(int)($p['is_best_seller']??0),(float)($p['price']??0),$p['image_path']??null,$p['product_type']??'finished_good',(int)($p['track_stock']??1),(int)($p['allow_direct_purchase']??0),(int)($p['allow_bom']??0),(int)($p['show_on_pos']??1),(int)($p['show_on_landing']??1),$p['base_unit']??'pcs',$p['purchase_unit']??($p['base_unit']??'pcs'),(float)($p['purchase_to_base_factor']??1),$p['sale_unit']??($p['base_unit']??'pcs'),(float)($p['sale_to_base_factor']??1)]); $count++; }
    $ok='Berhasil impor '.$count.' produk beserta referensi fotonya.';
  }catch(Throwable $e){ $err=$e->getMessage(); }
}
$customCss=setting('custom_css','');
?>
<!doctype html><html><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"><title>Impor Produk</title><link rel="icon" href="<?php echo e(favicon_url()); ?>"><link rel="stylesheet" href="<?php echo e(asset_url('assets/app.css')); ?>"><style><?php echo $customCss; ?></style></head><body><div class="container"><?php include __DIR__.'/partials_sidebar.php'; ?><div class="main"><div class="topbar"><button class="btn" data-toggle-sidebar type="button">Menu</button><a class="btn" href="<?php echo e(base_url('admin/products.php')); ?>">Kembali</a></div><div class="content"><div class="card"><h3>Impor Produk dari Cabang/Dapur</h3><p><small>Pilih satu, beberapa, atau semua produk. Foto ikut dipakai dari referensi produk sumber sehingga tidak perlu upload ulang.</small></p><?php if($err): ?><div class="card" style="border-color:rgba(251,113,133,.35);background:rgba(251,113,133,.10)"><?php echo e($err); ?></div><?php endif; ?><?php if($ok): ?><div class="card" style="border-color:rgba(34,197,94,.35);background:rgba(34,197,94,.10)"><?php echo e($ok); ?></div><?php endif; ?><form method="get"><div class="row"><label>Sumber cabang/dapur</label><select name="source_branch_id" onchange="this.form.submit()"><option value="0">Semua produk master</option><?php foreach($branches as $b): ?><option value="<?php echo e((string)$b['id']); ?>" <?php echo $sourceBranchId===(int)$b['id']?'selected':''; ?>><?php echo e($b['branch_name']); ?></option><?php endforeach; ?></select></div></form><form method="post"><input type="hidden" name="_csrf" value="<?php echo e(csrf_token()); ?>"><div style="display:flex;gap:8px;flex-wrap:wrap;margin:10px 0"><button class="btn" type="submit" name="mode" value="selected">Impor Produk Terpilih</button><button class="btn" type="submit" name="mode" value="all" data-confirm="Impor semua produk dari sumber ini?">Impor Semua</button></div><table class="table"><thead><tr><th style="width:40px"><input type="checkbox" onclick="document.querySelectorAll('.product-check').forEach(c=>c.checked=this.checked)"></th><th>Foto</th><th>Nama</th><th>Tipe</th><th>Kategori</th><th>Harga</th></tr></thead><tbody><?php foreach($sourceProducts as $p): ?><tr><td><input class="product-check" type="checkbox" name="product_ids[]" value="<?php echo e((string)$p['id']); ?>"></td><td><?php if(!empty($p['image_path'])): ?><img class="thumb" src="<?php echo e(upload_url($p['image_path'],'image')); ?>"><?php else: ?><div class="thumb" style="display:flex;align-items:center;justify-content:center;color:var(--muted)">No</div><?php endif; ?></td><td><?php echo e($p['name']); ?></td><td><?php echo e($p['product_type']??'finished_good'); ?></td><td><?php echo e($p['category']??''); ?></td><td>Rp <?php echo e(format_money((float)($p['price']??0))); ?></td></tr><?php endforeach; ?></tbody></table></form></div></div></div></div><script defer src="<?php echo e(asset_url('assets/app.js')); ?>"></script></body></html>
