<?php
require_once __DIR__ . '/../core/db.php';
require_once __DIR__ . '/../core/functions.php';
require_once __DIR__ . '/../core/security.php';
require_once __DIR__ . '/../core/auth.php';
require_once __DIR__ . '/../core/csrf.php';
require_once __DIR__ . '/../core/inventory.php';
require_once __DIR__ . '/../lib/upload_secure.php';

start_secure_session();
require_admin();

$id = (int)($_GET['id'] ?? 0);
ensure_products_category_column();
ensure_product_categories_table();
ensure_products_best_seller_column();
ensure_inventory_module_schema();
$product = ['name'=>'','category'=>'','price'=>'0','image_path'=>null,'is_best_seller'=>0,'product_type'=>'finished_good','track_stock'=>1,'allow_direct_purchase'=>0,'allow_bom'=>0,'show_on_pos'=>1,'show_on_landing'=>1,'base_unit'=>'pcs','purchase_unit'=>'pcs','purchase_to_base_factor'=>'1','sale_unit'=>'pcs','sale_to_base_factor'=>'1'];

if ($id) {
  $stmt = db()->prepare("SELECT * FROM products WHERE id=?");
  $stmt->execute([$id]);
  $product = $stmt->fetch() ?: $product;
}
$bomStatus = null;
if ($id > 0) {
  $stmt = db()->prepare("SELECT COUNT(*) AS total FROM bom_headers WHERE finished_product_id=? AND is_active=1");
  $stmt->execute([$id]);
  $bomStatus = (int)($stmt->fetch()['total'] ?? 0);
}

$err = '';
$categories = product_categories();

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
  csrf_check();

  $name = trim($_POST['name'] ?? '');
  $category = trim($_POST['category'] ?? '');
  $price = normalize_money($_POST['price'] ?? '0');
  $isBestSeller = isset($_POST['is_best_seller']) ? 1 : 0;
  $productType = (string)($_POST['product_type'] ?? 'finished_good');
  $trackStock = isset($_POST['track_stock']) ? 1 : 0;
  $allowDirectPurchase = isset($_POST['allow_direct_purchase']) ? 1 : 0;
  $allowBom = isset($_POST['allow_bom']) ? 1 : 0;
  $showOnPos = isset($_POST['show_on_pos']) ? 1 : 0;
  $showOnLanding = isset($_POST['show_on_landing']) ? 1 : 0;
  $baseUnit = trim((string)($_POST['base_unit'] ?? ''));
  $purchaseUnit = trim((string)($_POST['purchase_unit'] ?? ''));
  $purchaseToBaseFactor = parse_number_input($_POST['purchase_to_base_factor'] ?? '1');
  $saleUnit = trim((string)($_POST['sale_unit'] ?? ''));
  $saleToBaseFactor = parse_number_input($_POST['sale_to_base_factor'] ?? '1');

  try {
    if ($name === '') throw new Exception('Nama produk wajib diisi.');
    if (!in_array($productType, ['finished_good','raw_material','service'], true)) {
      throw new Exception('Tipe produk tidak valid.');
    }
    if ($productType === 'raw_material') {
      $allowBom = 0;
      $allowDirectPurchase = 1;
    }
    if ($productType === 'service') {
      $trackStock = 0;
      $allowBom = 0;
      $allowDirectPurchase = 0;
    }
    if ($id === 0) {
      if (!isset($_POST['show_on_pos'])) {
        $showOnPos = $productType === 'raw_material' ? 0 : 1;
      }
      if (!isset($_POST['show_on_landing'])) {
        $showOnLanding = $productType === 'raw_material' ? 0 : 1;
      }
    }
    if (!empty($categories)) {
      $categoryNames = array_map(static function ($cat) {
        return $cat['name'];
      }, $categories);
      $isLegacyCategory = $id && $product['category'] === $category;
      if ($category !== '' && !in_array($category, $categoryNames, true) && !$isLegacyCategory) {
        throw new Exception('Kategori tidak valid. Silakan pilih dari daftar.');
      }
    }
    if ($trackStock === 1 && $baseUnit === '') {
      throw new Exception('Base unit wajib diisi untuk produk yang track stock.');
    }
    if ($purchaseToBaseFactor <= 0 || $saleToBaseFactor <= 0) {
      throw new Exception('Faktor konversi harus lebih besar dari 0.');
    }
    if ($baseUnit === '') $baseUnit = 'pcs';
    if ($purchaseUnit === '') $purchaseUnit = $baseUnit;
    if ($saleUnit === '') $saleUnit = $baseUnit;

    // Upload foto (opsional)
    $imagePath = $product['image_path'];
    if (!empty($_FILES['image']['name'])) {
      $upload = upload_secure($_FILES['image'], 'image');
      if (empty($upload['ok'])) throw new Exception($upload['error'] ?? 'Upload gagal.');

      // Hapus foto lama
      if ($imagePath) {
        if (upload_is_legacy_path($imagePath)) {
          $old = __DIR__ . '/../' . $imagePath;
          if (file_exists($old)) @unlink($old);
        } else {
          upload_secure_delete($imagePath, 'image');
        }
      }
      $imagePath = $upload['name'];
    }

    if ($id) {
      $stmt = db()->prepare("UPDATE products SET name=?, category=?, is_best_seller=?, price=?, image_path=?, product_type=?, track_stock=?, allow_direct_purchase=?, allow_bom=?, show_on_pos=?, show_on_landing=?, base_unit=?, purchase_unit=?, purchase_to_base_factor=?, sale_unit=?, sale_to_base_factor=? WHERE id=?");
      $stmt->execute([$name, $category, $isBestSeller, $price, $imagePath, $productType, $trackStock, $allowDirectPurchase, $allowBom, $showOnPos, $showOnLanding, $baseUnit, $purchaseUnit, $purchaseToBaseFactor, $saleUnit, $saleToBaseFactor, $id]);
    } else {
      $stmt = db()->prepare("INSERT INTO products (name, category, is_best_seller, price, image_path, product_type, track_stock, allow_direct_purchase, allow_bom, show_on_pos, show_on_landing, base_unit, purchase_unit, purchase_to_base_factor, sale_unit, sale_to_base_factor) VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?)");
      $stmt->execute([$name, $category, $isBestSeller, $price, $imagePath, $productType, $trackStock, $allowDirectPurchase, $allowBom, $showOnPos, $showOnLanding, $baseUnit, $purchaseUnit, $purchaseToBaseFactor, $saleUnit, $saleToBaseFactor]);
    }

    redirect(base_url('admin/products.php'));
  } catch (Throwable $e) {
    $err = $e->getMessage();
  }
}

$customCss = setting('custom_css', '');
?>
<!doctype html>
<html>
<head>
  <meta charset="utf-8">
  <meta name="viewport" content="width=device-width,initial-scale=1">
  <title><?php echo $id ? 'Edit Produk' : 'Tambah Produk'; ?></title>
  <link rel="icon" href="<?php echo e(favicon_url()); ?>">
  <link rel="stylesheet" href="<?php echo e(asset_url('assets/app.css')); ?>">
  <style><?php echo $customCss; ?></style>
</head>
<body>
<div class="container">
  <?php include __DIR__ . '/partials_sidebar.php'; ?>
  <div class="main">
    <div class="topbar">
      <button class="btn" data-toggle-sidebar type="button">Menu</button>
      <a class="btn" href="<?php echo e(base_url('admin/products.php')); ?>">Kembali</a>
    </div>

    <div class="content">
      <div class="card product-form-card">
        <h3><?php echo $id ? 'Edit Produk' : 'Tambah Produk'; ?></h3>
        <?php if ($err): ?>
          <div class="card" style="border-color:rgba(251,113,133,.35);background:rgba(251,113,133,.10)"><?php echo e($err); ?></div>
        <?php endif; ?>

        <form method="post" enctype="multipart/form-data">
          <input type="hidden" name="_csrf" value="<?php echo e(csrf_token()); ?>">
          <div class="row">
            <label>Nama Produk</label>
            <input name="name" value="<?php echo e($_POST['name'] ?? $product['name']); ?>" required>
          </div>
          <div class="grid cols-2">
            <div class="row">
              <label>Tipe Produk</label>
              <?php $selectedType = $_POST['product_type'] ?? $product['product_type']; ?>
              <select name="product_type" id="product_type">
                <option value="finished_good" <?php echo $selectedType === 'finished_good' ? 'selected' : ''; ?>>Finished Good</option>
                <option value="raw_material" <?php echo $selectedType === 'raw_material' ? 'selected' : ''; ?>>Raw Material</option>
                <option value="service" <?php echo $selectedType === 'service' ? 'selected' : ''; ?>>Service</option>
              </select>
            </div>
            <div class="row">
              <label>Kategori</label>
              <?php
                $selectedCategory = $_POST['category'] ?? $product['category'];
                $categoryNames = array_map(static function ($cat) {
                  return $cat['name'];
                }, $categories);
                $hasLegacyCategory = $selectedCategory !== '' && !in_array($selectedCategory, $categoryNames, true);
              ?>
              <select name="category">
                <option value="">Tanpa kategori</option>
                <?php foreach ($categories as $category): ?>
                  <option value="<?php echo e($category['name']); ?>" <?php echo $selectedCategory === $category['name'] ? 'selected' : ''; ?>>
                    <?php echo e($category['name']); ?>
                  </option>
                <?php endforeach; ?>
                <?php if ($hasLegacyCategory): ?>
                  <option value="<?php echo e($selectedCategory); ?>" selected>
                    <?php echo e($selectedCategory); ?> (belum terdaftar)
                  </option>
                <?php endif; ?>
              </select>
              <?php if (empty($categories)): ?>
                <small>Belum ada kategori. Tambahkan di menu <a href="<?php echo e(base_url('admin/product_categories.php')); ?>">Kategori Produk</a>.</small>
              <?php else: ?>
                <small>Kelola kategori di menu <a href="<?php echo e(base_url('admin/product_categories.php')); ?>">Kategori Produk</a>.</small>
              <?php endif; ?>
            </div>
            <div class="row">
              <label>Harga</label>
              <input type="number" name="price" inputmode="numeric" min="0" step="1" value="<?php echo e($_POST['price'] ?? (string)$product['price']); ?>" required>
              <small>Gunakan angka, contoh: 12500</small>
            </div>
            <?php
              $unitOptions = ['pcs','pack','box','dus','sachet','botol','cup','kaleng','tray','gram','kg','ons','ml','liter','cm','meter'];
              $baseUnitVal = $_POST['base_unit'] ?? ($product['base_unit'] ?? 'pcs');
              $purchaseUnitVal = $_POST['purchase_unit'] ?? ($product['purchase_unit'] ?? $baseUnitVal);
              $saleUnitVal = $_POST['sale_unit'] ?? ($product['sale_unit'] ?? $baseUnitVal);
            ?>
            <div class="row">
              <label>Base Unit</label>
              <input list="unit-options" name="base_unit" value="<?php echo e((string)$baseUnitVal); ?>" placeholder="contoh: pcs / gram">
            </div>
            <div class="row">
              <label>Purchase Unit</label>
              <input list="unit-options" name="purchase_unit" value="<?php echo e((string)$purchaseUnitVal); ?>" placeholder="contoh: dus / kg">
            </div>
            <div class="row">
              <label>Konversi Purchase ke Base</label>
              <input type="number" min="0.000001" step="0.000001" name="purchase_to_base_factor" value="<?php echo e((string)($_POST['purchase_to_base_factor'] ?? ($product['purchase_to_base_factor'] ?? '1'))); ?>" required>
            </div>
            <div class="row">
              <label>Sale Unit</label>
              <input list="unit-options" name="sale_unit" value="<?php echo e((string)$saleUnitVal); ?>" placeholder="contoh: pack / liter">
            </div>
            <div class="row">
              <label>Konversi Sale ke Base</label>
              <input type="number" min="0.000001" step="0.000001" name="sale_to_base_factor" value="<?php echo e((string)($_POST['sale_to_base_factor'] ?? ($product['sale_to_base_factor'] ?? '1'))); ?>" required>
            </div>
          </div>
          <datalist id="unit-options">
            <?php foreach($unitOptions as $opt): ?><option value="<?php echo e($opt); ?>"><?php endforeach; ?>
          </datalist>
            <div class="row">
              <label>Foto Produk (opsional, max 2MB)</label>
            <input type="file" name="image" accept=".jpg,.jpeg,.png">
            <?php if (!empty($product['image_path'])): ?>
              <div class="helper">
                <small>Foto saat ini:</small>
                <img class="thumb" src="<?php echo e(upload_url($product['image_path'], 'image')); ?>">
              </div>
            <?php endif; ?>
          </div>
          <div class="row">
            <label class="checkbox-row">
              <input type="checkbox" name="is_best_seller" value="1" <?php echo !empty($_POST) ? (isset($_POST['is_best_seller']) ? 'checked' : '') : ((int)$product['is_best_seller'] === 1 ? 'checked' : ''); ?>>
              Tandai sebagai best seller (tampil di paling atas)
            </label>
          </div>
          <div class="grid cols-2">
            <div class="row">
              <label class="checkbox-row">
                <input type="checkbox" name="track_stock" value="1" <?php echo !empty($_POST) ? (isset($_POST['track_stock']) ? 'checked' : '') : ((int)$product['track_stock'] === 1 ? 'checked' : ''); ?>>
                Track stok produk ini
              </label>
            </div>
            <div class="row">
              <label class="checkbox-row">
                <input type="checkbox" name="allow_direct_purchase" value="1" <?php echo !empty($_POST) ? (isset($_POST['allow_direct_purchase']) ? 'checked' : '') : ((int)$product['allow_direct_purchase'] === 1 ? 'checked' : ''); ?>>
                Boleh dibeli langsung (raw material)
              </label>
              <label class="checkbox-row">
                <input type="checkbox" name="allow_bom" value="1" <?php echo !empty($_POST) ? (isset($_POST['allow_bom']) ? 'checked' : '') : ((int)$product['allow_bom'] === 1 ? 'checked' : ''); ?>>
                Gunakan BOM untuk produksi
              </label>
            </div>
            <div class="row">
              <label class="checkbox-row">
                <input id="show_on_pos" type="checkbox" name="show_on_pos" value="1" <?php echo !empty($_POST) ? (isset($_POST['show_on_pos']) ? 'checked' : '') : ((int)($product['show_on_pos'] ?? 1) === 1 ? 'checked' : ''); ?>>
                Tampilkan di POS
              </label>
            </div>
            <div class="row">
              <label class="checkbox-row">
                <input id="show_on_landing" type="checkbox" name="show_on_landing" value="1" <?php echo !empty($_POST) ? (isset($_POST['show_on_landing']) ? 'checked' : '') : ((int)($product['show_on_landing'] ?? 1) === 1 ? 'checked' : ''); ?>>
                Tampilkan di Landing Page
              </label>
            </div>
          </div>
          <button class="btn" type="submit">Simpan</button>
          <?php if ($id > 0): ?>
            <a class="btn" href="<?php echo e(base_url('admin/bom.php')); ?>">Kelola BOM</a>
            <small>Status BOM aktif: <?php echo $bomStatus > 0 ? 'Ada' : 'Belum ada'; ?></small>
          <?php endif; ?>
        </form>
      </div>
    </div>
  </div>
</div>
<script defer src="<?php echo e(asset_url('assets/app.js')); ?>"></script>
<script>
(function(){
  var typeEl = document.getElementById('product_type');
  var posEl = document.getElementById('show_on_pos');
  var landingEl = document.getElementById('show_on_landing');
  if (!typeEl || !posEl || !landingEl) return;

  function syncVisibilityDefault() {
    if (typeEl.value === 'raw_material') {
      posEl.checked = false;
      landingEl.checked = false;
    } else {
      posEl.checked = true;
      landingEl.checked = true;
    }
  }

  typeEl.addEventListener('change', syncVisibilityDefault);
})();
</script>
</body>
</html>
