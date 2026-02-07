<?php
require_once __DIR__ . '/../core/db.php';
require_once __DIR__ . '/../core/functions.php';
require_once __DIR__ . '/../core/security.php';
require_once __DIR__ . '/../core/auth.php';
require_once __DIR__ . '/../core/csrf.php';

start_secure_session();
require_admin();
ensure_product_categories_table();
ensure_products_category_column();

$err = '';
$success = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
  csrf_check();
  $action = $_POST['action'] ?? '';

  try {
    if ($action === 'add') {
      $name = trim($_POST['name'] ?? '');
      if ($name === '') throw new Exception('Nama kategori wajib diisi.');

      $stmt = db()->prepare("INSERT INTO product_categories (name) VALUES (?)");
      $stmt->execute([$name]);
      $success = 'Kategori berhasil ditambahkan.';
    }

    if ($action === 'update') {
      $id = (int)($_POST['id'] ?? 0);
      $name = trim($_POST['name'] ?? '');
      if ($name === '') throw new Exception('Nama kategori wajib diisi.');

      $stmt = db()->prepare("UPDATE product_categories SET name=? WHERE id=?");
      $stmt->execute([$name, $id]);
      $success = 'Kategori berhasil diperbarui.';
    }

    if ($action === 'delete') {
      $id = (int)($_POST['id'] ?? 0);
      $stmt = db()->prepare("SELECT name FROM product_categories WHERE id=?");
      $stmt->execute([$id]);
      $category = $stmt->fetch();
      if (!$category) throw new Exception('Kategori tidak ditemukan.');

      $stmt = db()->prepare("SELECT COUNT(*) AS total FROM products WHERE category=?");
      $stmt->execute([$category['name']]);
      $total = (int)($stmt->fetch()['total'] ?? 0);
      if ($total > 0) {
        throw new Exception('Kategori masih digunakan oleh produk.');
      }

      $stmt = db()->prepare("DELETE FROM product_categories WHERE id=?");
      $stmt->execute([$id]);
      $success = 'Kategori berhasil dihapus.';
    }
  } catch (Throwable $e) {
    $err = $e->getMessage() ?: 'Terjadi kesalahan.';
  }
}

$categories = db()->query("SELECT c.id, c.name, COUNT(p.id) AS product_count
  FROM product_categories c
  LEFT JOIN products p ON p.category = c.name
  GROUP BY c.id
  ORDER BY c.name ASC")->fetchAll();
$customCss = setting('custom_css', '');
?>
<!doctype html>
<html>
<head>
  <meta charset="utf-8">
  <meta name="viewport" content="width=device-width,initial-scale=1">
  <title>Kategori Produk</title>
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
      <div class="card">
        <h3 style="margin-top:0">Kategori Produk</h3>
        <?php if ($err): ?>
          <div class="card" style="border-color:rgba(251,113,133,.35);background:rgba(251,113,133,.10)"><?php echo e($err); ?></div>
        <?php endif; ?>
        <?php if ($success): ?>
          <div class="card" style="border-color:rgba(74,222,128,.35);background:rgba(74,222,128,.12)"><?php echo e($success); ?></div>
        <?php endif; ?>

        <form method="post" style="margin-bottom:16px">
          <input type="hidden" name="_csrf" value="<?php echo e(csrf_token()); ?>">
          <input type="hidden" name="action" value="add">
          <div class="row">
            <label>Nama Kategori Baru</label>
            <div style="display:flex;gap:8px;align-items:center;flex-wrap:wrap">
              <input name="name" placeholder="Contoh: Minuman" required>
              <button class="btn" type="submit">Tambah</button>
            </div>
          </div>
        </form>

        <table class="table">
          <thead>
            <tr>
              <th>Nama</th><th>Produk</th><th>Aksi</th>
            </tr>
          </thead>
          <tbody>
            <?php if (empty($categories)): ?>
              <tr>
                <td colspan="3" style="text-align:center;color:var(--muted)">Belum ada kategori.</td>
              </tr>
            <?php endif; ?>
            <?php foreach ($categories as $category): ?>
              <tr>
                <td style="width:50%">
                  <form method="post" style="display:flex;gap:8px;align-items:center;flex-wrap:wrap">
                    <input type="hidden" name="_csrf" value="<?php echo e(csrf_token()); ?>">
                    <input type="hidden" name="action" value="update">
                    <input type="hidden" name="id" value="<?php echo e((string)$category['id']); ?>">
                    <input name="name" value="<?php echo e($category['name']); ?>" required>
                    <button class="btn" type="submit">Simpan</button>
                  </form>
                </td>
                <td><?php echo e((string)$category['product_count']); ?></td>
                <td>
                  <form method="post" data-confirm="Hapus kategori ini?">
                    <input type="hidden" name="_csrf" value="<?php echo e(csrf_token()); ?>">
                    <input type="hidden" name="action" value="delete">
                    <input type="hidden" name="id" value="<?php echo e((string)$category['id']); ?>">
                    <button class="btn danger" type="submit">Hapus</button>
                  </form>
                </td>
              </tr>
            <?php endforeach; ?>
          </tbody>
        </table>
      </div>
    </div>
  </div>
</div>
<script defer src="<?php echo e(asset_url('assets/app.js')); ?>"></script>
</body>
</html>
