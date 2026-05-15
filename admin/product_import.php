<?php
require_once __DIR__ . '/../core/db.php';
require_once __DIR__ . '/../core/functions.php';
require_once __DIR__ . '/../core/security.php';
require_once __DIR__ . '/../core/auth.php';
require_once __DIR__ . '/../core/csrf.php';
require_once __DIR__ . '/../core/rbac.php';
require_once __DIR__ . '/../core/inventory.php';
require_once __DIR__ . '/../core/api_web_products.php';

start_secure_session();
$me = require_menu_access('settings', 'view');
$roleKey = (string)(resolve_user_role($me)['role_key'] ?? '');
if (!in_array($roleKey, ['owner', 'admin'], true)) redirect(base_url('admin/dashboard.php'));
ensure_web_product_api_schema();
ensure_inventory_module_schema();
ensure_products_category_column();
ensure_product_categories_table();

$err = '';
$ok = '';
$previewRows = [];
$previewConnectionId = 0;

function import_permissions_from_post(): array {
  $input = $_POST['permissions'] ?? [];
  if (!is_array($input)) $input = [];
  $allowed = ['products.read','categories.read','product_images.read'];
  return array_values(array_intersect($allowed, array_map('strval', $input)));
}

function get_connection(int $id): ?array {
  $stmt = db()->prepare('SELECT * FROM api_remote_connections WHERE id = ? LIMIT 1');
  $stmt->execute([$id]);
  $row = $stmt->fetch(PDO::FETCH_ASSOC);
  return $row ?: null;
}

function fetch_remote_products_for_connection(array $conn): array {
  $json = remote_api_fetch_json((string)$conn['base_url'], (string)$conn['token'], 'api/web/products.php');
  $data = $json['data'] ?? [];
  if (!is_array($data)) throw new RuntimeException('Format data produk remote tidak valid.');
  return $data;
}

function make_preview_rows(int $connectionId, array $remoteProducts): array {
  $out = [];
  $mapStmt = db()->prepare('SELECT local_product_id, remote_hash FROM product_import_mappings WHERE connection_id = ? AND remote_product_id = ? LIMIT 1');
  $localStmt = db()->prepare('SELECT id, name, category, price FROM products WHERE LOWER(name) = LOWER(?) AND COALESCE(category,\'\') = ? LIMIT 1');
  foreach ($remoteProducts as $r) {
    if (!is_array($r)) continue;
    $remoteId = (int)($r['remote_id'] ?? 0);
    $name = trim((string)($r['name'] ?? ''));
    $category = trim((string)($r['category'] ?? ''));
    if ($remoteId <= 0 || $name === '') continue;
    $hash = (string)($r['hash'] ?? web_product_remote_hash($r));
    $status = 'baru';
    $action = 'Akan ditambahkan';
    $localId = null;
    $mapStmt->execute([$connectionId, $remoteId]);
    $map = $mapStmt->fetch(PDO::FETCH_ASSOC);
    if ($map) {
      $localId = (int)$map['local_product_id'];
      if ((string)($map['remote_hash'] ?? '') === $hash) {
        $status = 'skip';
        $action = 'Tidak berubah';
      } else {
        $status = 'update';
        $action = 'Akan diperbarui';
      }
    } else {
      $localStmt->execute([$name, $category]);
      $local = $localStmt->fetch(PDO::FETCH_ASSOC);
      if ($local) {
        $localId = (int)$local['id'];
        $status = 'cocok_nama';
        $action = 'Cocok nama+kategori, akan dibuat mapping';
      }
    }
    $out[] = ['remote' => $r, 'remote_id' => $remoteId, 'name' => $name, 'category' => $category, 'price' => (float)($r['price'] ?? 0), 'hash' => $hash, 'status' => $status, 'action' => $action, 'local_id' => $localId];
  }
  return $out;
}

function ensure_category_name(string $name): void {
  $name = trim($name);
  if ($name === '') return;
  try {
    db()->prepare('INSERT IGNORE INTO product_categories (name) VALUES (?)')->execute([$name]);
  } catch (Throwable $e) {}
}

function import_remote_products(array $conn, array $options): array {
  $remoteProducts = fetch_remote_products_for_connection($conn);
  $rows = make_preview_rows((int)$conn['id'], $remoteProducts);
  $new = 0; $updated = 0; $skipped = 0; $conflict = 0; $images = 0; $imageFail = 0;
  $pdo = db();
  $pdo->beginTransaction();
  try {
    foreach ($rows as $row) {
      $r = $row['remote'];
      $remoteId = (int)$row['remote_id'];
      $status = (string)$row['status'];
      $hash = (string)$row['hash'];
      $name = trim((string)$row['name']);
      $category = trim((string)$row['category']);
      if ($name === '' || $remoteId <= 0) { $conflict++; continue; }
      if ($status === 'skip') { $skipped++; continue; }
      if (!empty($options['import_categories'])) ensure_category_name($category);
      $vals = web_product_options_from_remote($r, $options);
      $localId = (int)($row['local_id'] ?? 0);
      $imagePath = null;
      if (!empty($options['import_images']) && !empty($r['image_url'])) {
        $downloaded = web_product_download_image((string)$r['image_url'], (string)$conn['token']);
        if ($downloaded) { $imagePath = $downloaded; $images++; } else { $imageFail++; }
      }

      if ($localId > 0) {
        $sets = ['name=?','category=?','product_type=?','track_stock=?','allow_direct_purchase=?','allow_bom=?','base_unit=?','purchase_unit=?','purchase_to_base_factor=?','sale_unit=?','sale_to_base_factor=?'];
        $params = [$vals['name'], $vals['category'], $vals['product_type'], $vals['track_stock'], $vals['allow_direct_purchase'], $vals['allow_bom'], $vals['base_unit'], $vals['purchase_unit'], $vals['purchase_to_base_factor'], $vals['sale_unit'], $vals['sale_to_base_factor']];
        if ($vals['price'] !== null) { $sets[] = 'price=?'; $params[] = $vals['price']; }
        if ($vals['show_on_pos'] !== null) { $sets[] = 'show_on_pos=?'; $params[] = $vals['show_on_pos']; }
        if ($vals['show_on_landing'] !== null) { $sets[] = 'show_on_landing=?'; $params[] = $vals['show_on_landing']; }
        if ($imagePath !== null) { $sets[] = 'image_path=?'; $params[] = $imagePath; }
        $params[] = $localId;
        $pdo->prepare('UPDATE products SET ' . implode(',', $sets) . ' WHERE id=?')->execute($params);
        $updated++;
      } else {
        $insertPrice = $vals['price'] !== null ? $vals['price'] : web_product_safe_decimal($r['price'] ?? 0);
        $insertShowPos = $vals['show_on_pos'] !== null ? $vals['show_on_pos'] : web_product_safe_bool($r['show_on_pos'] ?? 1, 1);
        $insertShowLanding = $vals['show_on_landing'] !== null ? $vals['show_on_landing'] : web_product_safe_bool($r['show_on_landing'] ?? 1, 1);
        $pdo->prepare('INSERT INTO products (name, category, price, image_path, product_type, track_stock, allow_direct_purchase, allow_bom, show_on_pos, show_on_landing, base_unit, purchase_unit, purchase_to_base_factor, sale_unit, sale_to_base_factor) VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?,?,?)')
          ->execute([$vals['name'], $vals['category'], $insertPrice, $imagePath, $vals['product_type'], $vals['track_stock'], $vals['allow_direct_purchase'], $vals['allow_bom'], $insertShowPos, $insertShowLanding, $vals['base_unit'], $vals['purchase_unit'], $vals['purchase_to_base_factor'], $vals['sale_unit'], $vals['sale_to_base_factor']]);
        $localId = (int)$pdo->lastInsertId();
        $new++;
      }
      $pdo->prepare('INSERT INTO product_import_mappings (connection_id, remote_base_url, remote_product_id, remote_hash, local_product_id, last_imported_at) VALUES (?,?,?,?,?,NOW()) ON DUPLICATE KEY UPDATE remote_hash=VALUES(remote_hash), local_product_id=VALUES(local_product_id), last_imported_at=NOW()')
        ->execute([(int)$conn['id'], normalize_remote_base_url((string)$conn['base_url']), $remoteId, $hash, $localId]);
    }
    $pdo->prepare('UPDATE api_remote_connections SET last_sync_at = NOW() WHERE id = ?')->execute([(int)$conn['id']]);
    $pdo->commit();
  } catch (Throwable $e) {
    $pdo->rollBack();
    throw $e;
  }
  web_product_log_import((int)$conn['id'], 'success', $new, $updated, $skipped, $conflict, 'Gambar berhasil: ' . $images . ', gagal: ' . $imageFail);
  return compact('new','updated','skipped','conflict','images','imageFail');
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
  csrf_check();
  $action = (string)($_POST['action'] ?? '');
  try {
    if ($action === 'save_connection') {
      $id = (int)($_POST['id'] ?? 0);
      $name = trim((string)($_POST['name'] ?? ''));
      $baseUrl = normalize_remote_base_url((string)($_POST['base_url'] ?? ''));
      $token = trim((string)($_POST['token'] ?? ''));
      $perms = import_permissions_from_post();
      if ($name === '') throw new RuntimeException('Nama koneksi wajib diisi.');
      if ($baseUrl === '' || !filter_var($baseUrl, FILTER_VALIDATE_URL)) throw new RuntimeException('Domain sumber tidak valid.');
      if ($token === '') throw new RuntimeException('API token wajib diisi.');
      if ($id > 0) {
        db()->prepare('UPDATE api_remote_connections SET name=?, base_url=?, token=?, permissions=?, is_active=1 WHERE id=?')
          ->execute([$name, $baseUrl, $token, json_encode($perms), $id]);
        $ok = 'Koneksi remote berhasil diperbarui.';
      } else {
        db()->prepare('INSERT INTO api_remote_connections (name, base_url, token, permissions, is_active) VALUES (?,?,?,?,1)')
          ->execute([$name, $baseUrl, $token, json_encode($perms)]);
        $ok = 'Koneksi remote berhasil ditambahkan.';
      }
    } elseif ($action === 'delete_connection') {
      $id = (int)($_POST['id'] ?? 0);
      db()->prepare('DELETE FROM api_remote_connections WHERE id=?')->execute([$id]);
      $ok = 'Koneksi remote berhasil dihapus.';
    } elseif ($action === 'test_connection') {
      $id = (int)($_POST['id'] ?? 0);
      $conn = get_connection($id);
      if (!$conn) throw new RuntimeException('Koneksi tidak ditemukan.');
      $ping = remote_api_fetch_json((string)$conn['base_url'], (string)$conn['token'], 'api/web/ping.php');
      $products = remote_api_fetch_json((string)$conn['base_url'], (string)$conn['token'], 'api/web/products.php');
      $count = (int)($products['count'] ?? count($products['data'] ?? []));
      $msg = 'Koneksi berhasil. Produk terbaca: ' . $count;
      db()->prepare('UPDATE api_remote_connections SET last_test_at=NOW(), last_test_status=?, last_test_message=? WHERE id=?')
        ->execute(['success', $msg, $id]);
      $ok = $msg;
    } elseif ($action === 'import_products') {
      $id = (int)($_POST['connection_id'] ?? 0);
      $conn = get_connection($id);
      if (!$conn) throw new RuntimeException('Koneksi tidak ditemukan.');
      $options = [
        'import_categories' => isset($_POST['import_categories']),
        'import_images' => isset($_POST['import_images']),
        'overwrite_price' => isset($_POST['overwrite_price']),
        'overwrite_visibility' => isset($_POST['overwrite_visibility']),
      ];
      $result = import_remote_products($conn, $options);
      $ok = 'Import selesai. Produk baru: ' . $result['new'] . ', update: ' . $result['updated'] . ', skip: ' . $result['skipped'] . ', konflik: ' . $result['conflict'] . ', gambar berhasil: ' . $result['images'] . ', gambar gagal: ' . $result['imageFail'] . '.';
    }
  } catch (Throwable $e) {
    $err = $e->getMessage() ?: 'Terjadi kesalahan.';
    if (($action ?? '') === 'test_connection') {
      $id = (int)($_POST['id'] ?? 0);
      if ($id > 0) {
        try { db()->prepare('UPDATE api_remote_connections SET last_test_at=NOW(), last_test_status=?, last_test_message=? WHERE id=?')->execute(['failed', $err, $id]); } catch (Throwable $ignore) {}
      }
    }
  }
}

if (isset($_GET['preview'])) {
  $previewConnectionId = (int)($_GET['connection_id'] ?? 0);
  try {
    $conn = get_connection($previewConnectionId);
    if (!$conn) throw new RuntimeException('Koneksi tidak ditemukan.');
    $previewRows = make_preview_rows((int)$conn['id'], fetch_remote_products_for_connection($conn));
  } catch (Throwable $e) {
    $err = $e->getMessage() ?: 'Gagal mengambil preview produk.';
  }
}

$connections = db()->query('SELECT * FROM api_remote_connections ORDER BY id DESC')->fetchAll(PDO::FETCH_ASSOC);
$logs = db()->query('SELECT l.*, c.name AS connection_name FROM product_import_logs l LEFT JOIN api_remote_connections c ON c.id=l.connection_id ORDER BY l.id DESC LIMIT 20')->fetchAll(PDO::FETCH_ASSOC);
$customCss = setting('custom_css', '');
?>
<!doctype html>
<html>
<head>
  <meta charset="utf-8">
  <meta name="viewport" content="width=device-width,initial-scale=1">
  <title>Impor Produk</title>
  <link rel="icon" href="<?php echo e(favicon_url()); ?>">
  <link rel="stylesheet" href="<?php echo e(asset_url('assets/app.css')); ?>">
  <style>
    <?php echo $customCss; ?>
    .import-grid{display:grid;grid-template-columns:minmax(300px,420px) 1fr;gap:16px;align-items:start}.perm-box{display:grid;gap:8px}.check-card{display:flex;gap:10px;align-items:flex-start;border:1px solid var(--border);border-radius:12px;padding:10px;background:rgba(255,255,255,.65)}.status{display:inline-flex;border-radius:999px;padding:3px 9px;font-size:12px;font-weight:700}.s-baru{background:#dbeafe;color:#1e40af}.s-update,.s-cocok_nama{background:#fef3c7;color:#92400e}.s-skip{background:#dcfce7;color:#166534}.s-konflik{background:#fee2e2;color:#991b1b}.desktop-table{overflow:auto}.desktop-table table{min-width:900px}.btnline{display:flex;gap:8px;align-items:center;flex-wrap:wrap}@media (max-width:920px){.import-grid{grid-template-columns:1fr}.desktop-table table{min-width:0}.desktop-table table,.desktop-table thead,.desktop-table tbody,.desktop-table th,.desktop-table td,.desktop-table tr{display:block}.desktop-table thead{display:none}.desktop-table tr{border:1px solid var(--border);border-radius:14px;margin-bottom:10px;padding:8px;background:#fff}.desktop-table td{border:0!important;display:flex;justify-content:space-between;gap:12px}.desktop-table td::before{content:attr(data-label);font-weight:700;color:var(--muted)}.btnline{display:grid}.btnline .btn{width:100%;text-align:center;justify-content:center}}
  </style>
</head>
<body>
<div class="container">
  <?php include __DIR__ . '/partials_sidebar.php'; ?>
  <div class="main">
    <div class="topbar"><button class="btn" data-toggle-sidebar type="button">Menu</button><a class="btn" href="<?php echo e(base_url('admin/api_web_products.php')); ?>">API Antar Website</a></div>
    <div class="content">
      <div class="card"><h3 style="margin-top:0">Impor Produk dari Website Lain</h3><p><small>Alur ini khusus web-to-web. Stok tidak ikut diimpor; stok tetap melalui transfer/penerimaan stok agar kartu stok tetap bersih.</small></p></div>
      <?php if ($err): ?><div class="card" style="border-color:#fca5a5;background:#fef2f2"><?php echo e($err); ?></div><?php endif; ?>
      <?php if ($ok): ?><div class="card" style="border-color:#86efac;background:#ecfdf5"><?php echo e($ok); ?></div><?php endif; ?>

      <div class="import-grid">
        <div class="card">
          <h3 style="margin-top:0">Koneksi Penerima</h3>
          <form method="post">
            <input type="hidden" name="_csrf" value="<?php echo e(csrf_token()); ?>">
            <input type="hidden" name="action" value="save_connection">
            <div class="row"><label>Nama koneksi</label><input name="name" required placeholder="Contoh: Cabang Belitung"></div>
            <div class="row"><label>Domain sumber</label><input name="base_url" required placeholder="https://domain-sumber.com"></div>
            <div class="row"><label>API token sumber</label><textarea name="token" required rows="3" placeholder="Token dari API Antar Website di web sumber"></textarea></div>
            <label>Permission yang digunakan</label>
            <div class="perm-box" style="margin-top:8px">
              <label class="check-card"><input type="checkbox" name="permissions[]" value="products.read" checked><span><strong>Impor produk</strong><br><small>products.read</small></span></label>
              <label class="check-card"><input type="checkbox" name="permissions[]" value="categories.read" checked><span><strong>Impor kategori</strong><br><small>categories.read</small></span></label>
              <label class="check-card"><input type="checkbox" name="permissions[]" value="product_images.read" checked><span><strong>Impor gambar</strong><br><small>product_images.read</small></span></label>
            </div>
            <button class="btn" type="submit" style="margin-top:12px">Simpan Koneksi</button>
          </form>
        </div>

        <div class="card">
          <h3 style="margin-top:0">Daftar Koneksi</h3>
          <div class="desktop-table"><table class="table">
            <thead><tr><th>Nama</th><th>Domain</th><th>Test</th><th>Sync</th><th>Aksi</th></tr></thead>
            <tbody>
            <?php foreach ($connections as $c): ?>
              <tr>
                <td data-label="Nama"><?php echo e((string)$c['name']); ?></td>
                <td data-label="Domain"><small><?php echo e((string)$c['base_url']); ?></small></td>
                <td data-label="Test"><small><?php echo e((string)($c['last_test_status'] ?: '-')); ?><br><?php echo e((string)($c['last_test_message'] ?: '')); ?></small></td>
                <td data-label="Sync"><small><?php echo e((string)($c['last_sync_at'] ?: '-')); ?></small></td>
                <td data-label="Aksi"><div class="btnline">
                  <form method="post"><input type="hidden" name="_csrf" value="<?php echo e(csrf_token()); ?>"><input type="hidden" name="action" value="test_connection"><input type="hidden" name="id" value="<?php echo e((string)$c['id']); ?>"><button class="btn" type="submit">Test</button></form>
                  <a class="btn" href="<?php echo e(base_url('admin/product_import.php?preview=1&connection_id=' . (int)$c['id'])); ?>">Preview</a>
                  <form method="post" data-confirm="Hapus koneksi ini?"><input type="hidden" name="_csrf" value="<?php echo e(csrf_token()); ?>"><input type="hidden" name="action" value="delete_connection"><input type="hidden" name="id" value="<?php echo e((string)$c['id']); ?>"><button class="btn danger" type="submit">Hapus</button></form>
                </div></td>
              </tr>
            <?php endforeach; ?>
            <?php if (!$connections): ?><tr><td colspan="5" style="text-align:center;color:var(--muted)">Belum ada koneksi remote.</td></tr><?php endif; ?>
            </tbody>
          </table></div>
        </div>
      </div>

      <?php if ($previewConnectionId > 0): ?>
        <div class="card" style="margin-top:16px">
          <h3 style="margin-top:0">Preview Produk</h3>
          <form method="post" data-confirm="Import produk dari koneksi ini sekarang?">
            <input type="hidden" name="_csrf" value="<?php echo e(csrf_token()); ?>">
            <input type="hidden" name="action" value="import_products">
            <input type="hidden" name="connection_id" value="<?php echo e((string)$previewConnectionId); ?>">
            <div class="btnline" style="margin-bottom:12px">
              <label class="check-card"><input type="checkbox" name="import_categories" checked><span>Impor kategori baru</span></label>
              <label class="check-card"><input type="checkbox" name="import_images" checked><span>Impor gambar produk</span></label>
              <label class="check-card"><input type="checkbox" name="overwrite_price"><span>Timpa harga lokal</span></label>
              <label class="check-card"><input type="checkbox" name="overwrite_visibility"><span>Timpa status POS/Landing lokal</span></label>
            </div>
            <button class="btn" type="submit">Import Produk</button>
          </form>
          <div class="desktop-table" style="margin-top:12px"><table class="table">
            <thead><tr><th>Status</th><th>Produk</th><th>Kategori</th><th>Harga</th><th>Aksi</th></tr></thead>
            <tbody>
            <?php foreach ($previewRows as $r): $status = (string)$r['status']; ?>
              <tr>
                <td data-label="Status"><span class="status s-<?php echo e($status); ?>"><?php echo e($status); ?></span></td>
                <td data-label="Produk"><?php echo e((string)$r['name']); ?></td>
                <td data-label="Kategori"><?php echo e((string)($r['category'] ?: '-')); ?></td>
                <td data-label="Harga">Rp <?php echo e(format_money((float)$r['price'])); ?></td>
                <td data-label="Aksi"><?php echo e((string)$r['action']); ?></td>
              </tr>
            <?php endforeach; ?>
            <?php if (!$previewRows): ?><tr><td colspan="5" style="text-align:center;color:var(--muted)">Tidak ada produk yang terbaca.</td></tr><?php endif; ?>
            </tbody>
          </table></div>
        </div>
      <?php endif; ?>

      <div class="card" style="margin-top:16px">
        <h3 style="margin-top:0">Log Import Terakhir</h3>
        <div class="desktop-table"><table class="table">
          <thead><tr><th>Waktu</th><th>Koneksi</th><th>Status</th><th>Baru</th><th>Update</th><th>Skip</th><th>Konflik</th><th>Pesan</th></tr></thead>
          <tbody>
          <?php foreach ($logs as $l): ?>
            <tr><td data-label="Waktu"><?php echo e((string)$l['created_at']); ?></td><td data-label="Koneksi"><?php echo e((string)($l['connection_name'] ?: '-')); ?></td><td data-label="Status"><?php echo e((string)$l['import_status']); ?></td><td data-label="Baru"><?php echo e((string)$l['total_new']); ?></td><td data-label="Update"><?php echo e((string)$l['total_updated']); ?></td><td data-label="Skip"><?php echo e((string)$l['total_skipped']); ?></td><td data-label="Konflik"><?php echo e((string)$l['total_conflict']); ?></td><td data-label="Pesan"><small><?php echo e((string)$l['message']); ?></small></td></tr>
          <?php endforeach; ?>
          <?php if (!$logs): ?><tr><td colspan="8" style="text-align:center;color:var(--muted)">Belum ada log import.</td></tr><?php endif; ?>
          </tbody>
        </table></div>
      </div>
    </div>
  </div>
</div>
<script defer src="<?php echo e(asset_url('assets/app.js')); ?>"></script>
</body>
</html>
