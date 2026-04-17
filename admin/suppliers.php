<?php
require_once __DIR__ . '/../core/db.php';
require_once __DIR__ . '/../core/functions.php';
require_once __DIR__ . '/../core/security.php';
require_once __DIR__ . '/../core/auth.php';
require_once __DIR__ . '/../core/csrf.php';
require_once __DIR__ . '/../core/inventory.php';

start_secure_session();
require_admin();
ensure_inventory_module_schema();

$err = '';
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
  csrf_check();
  $action = (string)($_POST['action'] ?? 'save');
  try {
    if ($action === 'save') {
      $id = (int)($_POST['id'] ?? 0);
      $code = trim((string)($_POST['supplier_code'] ?? ''));
      $name = trim((string)($_POST['supplier_name'] ?? ''));
      $phone = trim((string)($_POST['phone'] ?? ''));
      $email = trim((string)($_POST['email'] ?? ''));
      $address = trim((string)($_POST['address'] ?? ''));
      $isActive = isset($_POST['is_active']) ? 1 : 0;
      if ($code === '' || $name === '') throw new Exception('Kode dan nama supplier wajib diisi.');

      if ($id > 0) {
        $stmt = db()->prepare("UPDATE suppliers SET supplier_code=?, supplier_name=?, phone=?, email=?, address=?, is_active=? WHERE id=?");
        $stmt->execute([$code, $name, $phone ?: null, $email ?: null, $address ?: null, $isActive, $id]);
      } else {
        $stmt = db()->prepare("INSERT INTO suppliers (supplier_code,supplier_name,phone,email,address,is_active) VALUES (?,?,?,?,?,?)");
        $stmt->execute([$code, $name, $phone ?: null, $email ?: null, $address ?: null, $isActive]);
      }
      redirect(base_url('admin/suppliers.php'));
    }
  } catch (Throwable $e) {
    $err = $e->getMessage();
  }
}

$edit = null;
if ((int)($_GET['id'] ?? 0) > 0) {
  $stmt = db()->prepare("SELECT * FROM suppliers WHERE id=?");
  $stmt->execute([(int)$_GET['id']]);
  $edit = $stmt->fetch() ?: null;
}

$list = db()->query("SELECT * FROM suppliers ORDER BY id DESC")->fetchAll();
$customCss = setting('custom_css', '');
?>
<!doctype html><html><head>
<meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1">
<title>Master Supplier</title>
<link rel="icon" href="<?php echo e(favicon_url()); ?>">
<link rel="stylesheet" href="<?php echo e(asset_url('assets/app.css')); ?>">
<style><?php echo $customCss; ?></style>
</head><body><div class="container"><?php include __DIR__ . '/partials_sidebar.php'; ?><div class="main"><div class="topbar"><button class="btn" data-toggle-sidebar type="button">Menu</button></div>
<div class="content"><div class="card"><h3>Master Supplier</h3><?php if ($err): ?><div class="card" style="border-color:rgba(251,113,133,.35);background:rgba(251,113,133,.10)"><?php echo e($err); ?></div><?php endif; ?>
<form method="post"><input type="hidden" name="_csrf" value="<?php echo e(csrf_token()); ?>"><input type="hidden" name="action" value="save"><input type="hidden" name="id" value="<?php echo e((string)($edit['id'] ?? 0)); ?>">
<div class="grid cols-2"><div class="row"><label>Kode</label><input name="supplier_code" required value="<?php echo e($edit['supplier_code'] ?? ''); ?>"></div><div class="row"><label>Nama</label><input name="supplier_name" required value="<?php echo e($edit['supplier_name'] ?? ''); ?>"></div></div>
<div class="grid cols-2"><div class="row"><label>Phone</label><input name="phone" value="<?php echo e($edit['phone'] ?? ''); ?>"></div><div class="row"><label>Email</label><input name="email" value="<?php echo e($edit['email'] ?? ''); ?>"></div></div>
<div class="row"><label>Alamat</label><textarea name="address"><?php echo e($edit['address'] ?? ''); ?></textarea></div>
<div class="row"><label class="checkbox-row"><input type="checkbox" name="is_active" value="1" <?php echo isset($edit) ? ((int)($edit['is_active'] ?? 1) === 1 ? 'checked' : '') : 'checked'; ?>> Aktif</label></div>
<button class="btn" type="submit">Simpan Supplier</button>
</form></div>
<div class="card"><table class="table"><thead><tr><th>Kode</th><th>Nama</th><th>Kontak</th><th>Status</th><th>Aksi</th></tr></thead><tbody><?php foreach ($list as $row): ?><tr><td><?php echo e($row['supplier_code']); ?></td><td><?php echo e($row['supplier_name']); ?></td><td><?php echo e(trim(($row['phone'] ?? '') . ' ' . ($row['email'] ?? ''))); ?></td><td><?php echo (int)$row['is_active'] === 1 ? 'Aktif' : 'Nonaktif'; ?></td><td><a class="btn" href="<?php echo e(base_url('admin/suppliers.php?id=' . (int)$row['id'])); ?>">Edit</a></td></tr><?php endforeach; ?></tbody></table></div>
</div></div></div></div>
<script defer src="<?php echo e(asset_url('assets/app.js')); ?>"></script></body></html>
