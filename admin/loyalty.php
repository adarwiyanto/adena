<?php
require_once __DIR__ . '/../core/db.php';
require_once __DIR__ . '/../core/functions.php';
require_once __DIR__ . '/../core/security.php';
require_once __DIR__ . '/../core/auth.php';
require_once __DIR__ . '/../core/csrf.php';

start_secure_session();
require_admin();
ensure_landing_order_tables();
ensure_loyalty_rewards_table();

$pointValue = (int)setting('loyalty_point_value', '0');
$remainderMode = (string)setting('loyalty_remainder_mode', 'discard');
$remainderMode = in_array($remainderMode, ['discard', 'carry'], true) ? $remainderMode : 'discard';
$err = '';

$products = db()->query("SELECT id, name FROM products ORDER BY name ASC")->fetchAll();
$productsById = [];
foreach ($products as $product) {
  $productsById[(int)$product['id']] = $product;
}

$rewards = db()->query("
  SELECT lr.id, lr.product_id, lr.points_required, p.name
  FROM loyalty_rewards lr
  JOIN products p ON p.id = lr.product_id
  ORDER BY lr.points_required ASC
")->fetchAll();

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
  csrf_check();
  $action = $_POST['action'] ?? '';

  if ($action === 'update_points') {
    $pointInput = (int)($_POST['point_value'] ?? 0);
    $remainderInput = (string)($_POST['remainder_mode'] ?? 'discard');
    if ($pointInput < 0) {
      $err = 'Nilai belanja per poin tidak boleh negatif.';
    } elseif (!in_array($remainderInput, ['discard', 'carry'], true)) {
      $err = 'Mode sisa pembayaran tidak valid.';
    } else {
      set_setting('loyalty_point_value', (string)$pointInput);
      set_setting('loyalty_remainder_mode', $remainderInput);
      redirect(base_url('admin/loyalty.php'));
    }
  } elseif ($action === 'add_reward') {
    $productId = (int)($_POST['product_id'] ?? 0);
    $pointsRequired = (int)($_POST['points_required'] ?? 0);
    if ($productId <= 0 || empty($productsById[$productId])) {
      $err = 'Produk reward tidak ditemukan.';
    } elseif ($pointsRequired <= 0) {
      $err = 'Poin reward harus lebih dari 0.';
    } else {
      $stmt = db()->prepare("
        INSERT INTO loyalty_rewards (product_id, points_required)
        VALUES (?, ?)
        ON DUPLICATE KEY UPDATE points_required = VALUES(points_required)
      ");
      $stmt->execute([$productId, $pointsRequired]);
      redirect(base_url('admin/loyalty.php'));
    }
  } elseif ($action === 'delete_reward') {
    $rewardId = (int)($_POST['reward_id'] ?? 0);
    if ($rewardId > 0) {
      $stmt = db()->prepare("DELETE FROM loyalty_rewards WHERE id = ?");
      $stmt->execute([$rewardId]);
      redirect(base_url('admin/loyalty.php'));
    } else {
      $err = 'Reward tidak ditemukan.';
    }
  }
}

$customCss = setting('custom_css', '');
?>
<!doctype html>
<html>
<head>
  <meta charset="utf-8">
  <meta name="viewport" content="width=device-width,initial-scale=1">
  <title>Loyalti Point</title>
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
    </div>

    <div class="content">
      <div class="card">
        <h3 style="margin-top:0">Pengaturan Loyalti Point</h3>
        <?php if ($err): ?>
          <div class="card" style="border-color:rgba(251,113,133,.35);background:rgba(251,113,133,.10)"><?php echo e($err); ?></div>
        <?php endif; ?>
        <form method="post">
          <input type="hidden" name="_csrf" value="<?php echo e(csrf_token()); ?>">
          <input type="hidden" name="action" value="update_points">
          <div class="row">
            <label>Nilai belanja untuk 1 poin (Rp)</label>
            <input name="point_value" type="number" min="0" value="<?php echo e($_POST['point_value'] ?? (string)$pointValue); ?>" required>
            <small>Contoh: isi 10000 berarti setiap Rp 10.000 mendapatkan 1 poin.</small>
          </div>
          <div class="row">
            <label>Sisa pembayaran untuk poin</label>
            <div class="radio-group">
              <label class="radio-option">
                <input type="radio" name="remainder_mode" value="discard" <?php echo $remainderMode === 'discard' ? 'checked' : ''; ?>>
                <span>Hanguskan sisa</span>
              </label>
              <label class="radio-option">
                <input type="radio" name="remainder_mode" value="carry" <?php echo $remainderMode === 'carry' ? 'checked' : ''; ?>>
                <span>Akumulasi ke transaksi berikutnya</span>
              </label>
            </div>
          </div>
          <button class="btn" type="submit">Simpan</button>
        </form>
        <p><small>Poin dihitung dari total belanja setelah pembayaran diselesaikan di POS.</small></p>
      </div>

      <div class="card">
        <h3 style="margin-top:0">Reward Loyalti</h3>
        <p><small>Tentukan reward berdasarkan produk yang sudah ada di database.</small></p>
        <form method="post">
          <input type="hidden" name="_csrf" value="<?php echo e(csrf_token()); ?>">
          <input type="hidden" name="action" value="add_reward">
          <div class="row">
            <label>Produk reward</label>
            <select name="product_id" required>
              <option value="">Pilih produk</option>
              <?php foreach ($products as $product): ?>
                <option value="<?php echo e((string)$product['id']); ?>">
                  <?php echo e($product['name']); ?>
                </option>
              <?php endforeach; ?>
            </select>
          </div>
          <div class="row">
            <label>Poin yang dibutuhkan</label>
            <input name="points_required" type="number" min="1" required>
          </div>
          <button class="btn" type="submit">Tambah Reward</button>
        </form>
        <?php if (empty($rewards)): ?>
          <p><small>Belum ada reward yang disetel.</small></p>
        <?php else: ?>
          <div class="table-wrap" style="margin-top:12px">
            <table>
              <thead>
                <tr>
                  <th>Produk</th>
                  <th>Poin</th>
                  <th>Aksi</th>
                </tr>
              </thead>
              <tbody>
                <?php foreach ($rewards as $reward): ?>
                  <tr>
                    <td><?php echo e($reward['name']); ?></td>
                    <td><?php echo e((string)$reward['points_required']); ?></td>
                    <td>
                      <form method="post" data-confirm="Hapus reward ini?">
                        <input type="hidden" name="_csrf" value="<?php echo e(csrf_token()); ?>">
                        <input type="hidden" name="action" value="delete_reward">
                        <input type="hidden" name="reward_id" value="<?php echo e((string)$reward['id']); ?>">
                        <button class="btn" type="submit">Hapus</button>
                      </form>
                    </td>
                  </tr>
                <?php endforeach; ?>
              </tbody>
            </table>
          </div>
        <?php endif; ?>
      </div>
    </div>
  </div>
</div>
<script defer src="<?php echo e(asset_url('assets/app.js')); ?>"></script>
</body>
</html>
