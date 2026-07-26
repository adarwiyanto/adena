<?php
require_once __DIR__ . '/../core/db.php';
require_once __DIR__ . '/../core/functions.php';
require_once __DIR__ . '/../core/security.php';
require_once __DIR__ . '/../core/auth.php';
require_once __DIR__ . '/../core/csrf.php';
require_once __DIR__ . '/../core/rbac.php';
require_once __DIR__ . '/../core/inventory.php';
require_once __DIR__ . '/../core/purchase_general/module.php';

start_secure_session();
require_admin();
ensure_rbac_schema();
$me = require_menu_access('purchase', 'view');
csrf_token();

$err = '';
$msg = isset($_GET['saved']) ? 'Pembelian umum berhasil disimpan dan tetap tersedia untuk API pembelian.' : '';
$branches = inventory_branches();
$suppliers = gp_suppliers();
$products = gp_products();
$guides = gp_guides();
$db = db();

$formValues = [
  'purchase_no' => gp_generate_purchase_no($db),
  'purchase_date' => date('Y-m-d'),
  'branch_id' => (string)active_branch_id(),
  'supplier_id' => '',
  'notes' => '',
];
$formItems = [gp_default_form_item()];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
  $storedEvidencePaths = [];
  try {
    csrf_check();

    $formValues = [
      'purchase_no' => trim((string)($_POST['purchase_no'] ?? '')),
      'purchase_date' => (string)($_POST['purchase_date'] ?? date('Y-m-d')),
      'branch_id' => (string)($_POST['branch_id'] ?? active_branch_id()),
      'supplier_id' => (string)($_POST['supplier_id'] ?? ''),
      'notes' => trim((string)($_POST['notes'] ?? '')),
    ];
    $postedItems = is_array($_POST['items'] ?? null) ? $_POST['items'] : [];
    $formItems = $postedItems ? array_values($postedItems) : [gp_default_form_item()];

    $purchaseNo = $formValues['purchase_no'];
    if ($purchaseNo === '') $purchaseNo = gp_generate_purchase_no($db);
    if (strlen($purchaseNo) > 50) throw new InvalidArgumentException('Nomor purchase maksimal 50 karakter.');
    if (!str_starts_with(strtoupper($purchaseNo), 'PG-')) {
      throw new InvalidArgumentException('Nomor Pembelian Umum harus diawali PG-.');
    }

    $branchId = (int)$formValues['branch_id'];
    if ($branchId <= 0) throw new InvalidArgumentException('Cabang wajib dipilih.');
    $branchStmt = $db->prepare('SELECT id FROM branches WHERE id=? AND is_active=1 LIMIT 1');
    $branchStmt->execute([$branchId]);
    if (!$branchStmt->fetchColumn()) throw new InvalidArgumentException('Cabang tidak tersedia.');

    $date = gp_validate_date($formValues['purchase_date']);
    $evidenceFiles = is_array($_FILES['item_evidence'] ?? null) ? $_FILES['item_evidence'] : [];
    $items = gp_collect_items($postedItems, $evidenceFiles, $products, $guides);
    $hasProduct = false;
    $total = 0.0;
    foreach ($items as $purchaseItem) {
      if ($purchaseItem['type'] === 'product') $hasProduct = true;
      $total += (float)$purchaseItem['line_total'];
    }

    $supplierId = (int)$formValues['supplier_id'];
    if ($hasProduct) {
      if ($supplierId <= 0) throw new InvalidArgumentException('Supplier wajib dipilih bila terdapat item Produk / Barang.');
      $supplierStmt = $db->prepare('SELECT id FROM suppliers WHERE id=? AND is_active=1 LIMIT 1');
      $supplierStmt->execute([$supplierId]);
      if (!$supplierStmt->fetchColumn()) throw new InvalidArgumentException('Supplier produk tidak tersedia.');
    }

    $duplicateStmt = $db->prepare('SELECT id FROM purchase_headers WHERE purchase_no=? LIMIT 1');
    $duplicateStmt->execute([$purchaseNo]);
    if ($duplicateStmt->fetchColumn()) throw new InvalidArgumentException('Nomor purchase sudah digunakan.');

    $db->beginTransaction();
    if (!$hasProduct) $supplierId = gp_system_supplier_id($db);

    $headerStmt = $db->prepare("INSERT INTO purchase_headers
      (branch_id,supplier_id,purchase_no,purchase_date,purchase_type,status,subtotal,grand_total,notes,created_by,posted_by,posted_at)
      VALUES (?,?,?,?, 'general','posted',?,?,?,?,?,?)");
    $headerStmt->execute([
      $branchId,
      $supplierId,
      $purchaseNo,
      $date,
      $total,
      $total,
      $formValues['notes'] !== '' ? $formValues['notes'] : null,
      (int)($me['id'] ?? 0),
      (int)($me['id'] ?? 0),
      date('Y-m-d H:i:s'),
    ]);
    $purchaseId = (int)$db->lastInsertId();

    $itemStmt = $db->prepare('INSERT INTO purchase_items (purchase_id,product_id,item_name,qty,unit_cost,line_total,notes) VALUES (?,?,?,?,?,?,?)');
    foreach ($items as $purchaseItem) {
      $itemStmt->execute([
        $purchaseId,
        $purchaseItem['product_id'],
        $purchaseItem['item_name'],
        $purchaseItem['qty'],
        $purchaseItem['unit_cost'],
        $purchaseItem['line_total'],
        $purchaseItem['notes'],
      ]);
      $itemId = (int)$db->lastInsertId();
      $storedEvidencePaths = array_merge($storedEvidencePaths, gp_store_evidences($purchaseId, $itemId, $purchaseItem['evidences']));
    }

    $db->commit();
    redirect(base_url('admin/purchase_general.php?saved=1&detail=' . $purchaseId));
  } catch (Throwable $e) {
    if ($db->inTransaction()) $db->rollBack();
    gp_cleanup_paths($storedEvidencePaths);
    $err = $e->getMessage();
  }
}

$rows = [];
try {
  $rows = $db->query("SELECT ph.*, b.branch_name, s.supplier_code, s.supplier_name,
      (SELECT COUNT(*) FROM purchase_items pi WHERE pi.purchase_id=ph.id) AS item_count
    FROM purchase_headers ph
    LEFT JOIN branches b ON b.id=ph.branch_id
    LEFT JOIN suppliers s ON s.id=ph.supplier_id
    WHERE ph.purchase_type='general' AND ph.purchase_no LIKE 'PG-%'
    ORDER BY ph.id DESC LIMIT 100")->fetchAll(PDO::FETCH_ASSOC) ?: [];
} catch (Throwable $e) {
  $err = $err ?: $e->getMessage();
}

$detailHeader = null;
$detailItems = [];
$detailId = (int)($_GET['detail'] ?? 0);
if ($detailId > 0) {
  try {
    $detailStmt = $db->prepare("SELECT ph.*,b.branch_name,s.supplier_code,s.supplier_name
      FROM purchase_headers ph
      LEFT JOIN branches b ON b.id=ph.branch_id
      LEFT JOIN suppliers s ON s.id=ph.supplier_id
      WHERE ph.id=? AND ph.purchase_type='general' AND ph.purchase_no LIKE 'PG-%' LIMIT 1");
    $detailStmt->execute([$detailId]);
    $detailHeader = $detailStmt->fetch(PDO::FETCH_ASSOC) ?: null;
    if ($detailHeader) {
      $detailItemStmt = $db->prepare('SELECT * FROM purchase_items WHERE purchase_id=? ORDER BY id ASC');
      $detailItemStmt->execute([$detailId]);
      $detailItems = $detailItemStmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
    }
  } catch (Throwable $e) {
    $err = $err ?: $e->getMessage();
  }
}

$customCss = setting('custom_css', '');
?>
<!doctype html>
<html lang="id">
<head>
  <meta charset="utf-8">
  <meta name="viewport" content="width=device-width,initial-scale=1">
  <title>Pembelian Umum</title>
  <link rel="icon" href="<?php echo e(favicon_url()); ?>">
  <link rel="stylesheet" href="<?php echo e(asset_url('assets/app.css')); ?>">
  <link rel="stylesheet" href="<?php echo e(asset_url('assets/purchase-general.css')); ?>">
  <style><?php echo $customCss; ?></style>
</head>
<body>
<div class="container">
  <?php include __DIR__ . '/partials_sidebar.php'; ?>
  <div class="main">
    <div class="topbar">
      <button class="btn" data-toggle-sidebar type="button">Menu</button>
      <strong>Pembelian Umum</strong>
    </div>
    <main class="content pg-page">
      <?php if ($err): ?><div class="alert danger"><?php echo e($err); ?></div><?php endif; ?>
      <?php if ($msg): ?><div class="alert success"><?php echo e($msg); ?></div><?php endif; ?>
      <?php include __DIR__ . '/partials/purchase_general/form.php'; ?>
      <?php include __DIR__ . '/partials/purchase_general/detail.php'; ?>
      <?php include __DIR__ . '/partials/purchase_general/history.php'; ?>
    </main>
  </div>
</div>
<script defer src="<?php echo e(asset_url('assets/app.js')); ?>"></script>
<script defer src="<?php echo e(asset_url('assets/purchase-general.js')); ?>"></script>
</body>
</html>
