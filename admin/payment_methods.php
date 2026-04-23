<?php
require_once __DIR__ . '/../core/db.php';
require_once __DIR__ . '/../core/functions.php';
require_once __DIR__ . '/../core/security.php';
require_once __DIR__ . '/../core/auth.php';
require_once __DIR__ . '/../core/csrf.php';
require_once __DIR__ . '/../core/rbac.php';

start_secure_session();
$me = require_menu_access('settings', 'view');
ensure_payment_methods_table();
ensure_qris_banks_table();

$resolvedRole = (string)(resolve_user_role($me)['role_key'] ?? '');
if (!in_array($resolvedRole, ['owner', 'admin'], true)) {
  redirect(base_url('admin/dashboard.php'));
}

$err = '';
$ok = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
  csrf_check();
  $action = $_POST['action'] ?? '';

  if ($action === 'add') {
    $name = trim((string)($_POST['name'] ?? ''));
    $code = strtolower(trim(preg_replace('/[^a-z0-9_]/i', '_', (string)($_POST['code'] ?? ''))));
    if ($name === '') {
      $err = 'Nama metode pembayaran wajib diisi.';
    } elseif ($code === '') {
      $err = 'Kode metode pembayaran tidak valid.';
    } elseif (in_array($code, ['cash', 'qris', 'edc', 'transfer'], true)) {
      $err = 'Kode "cash", "qris", "edc", dan "transfer" adalah metode sistem dan tidak bisa ditambahkan ulang.';
    } else {
      try {
        $maxOrder = (int)db()->query("SELECT COALESCE(MAX(sort_order),0) FROM payment_methods")->fetchColumn();
        $stmt = db()->prepare("INSERT INTO payment_methods (code, name, is_system, is_active, sort_order) VALUES (?, ?, 0, 1, ?)");
        $stmt->execute([$code, $name, $maxOrder + 1]);
        $ok = 'Metode pembayaran berhasil ditambahkan.';
      } catch (Throwable $e) {
        $err = 'Kode sudah digunakan. Gunakan kode lain.';
      }
    }
  } elseif ($action === 'toggle') {
    $id = (int)($_POST['id'] ?? 0);
    $row = $id > 0 ? db()->query("SELECT * FROM payment_methods WHERE id = $id")->fetch(PDO::FETCH_ASSOC) : null;
    if (!$row) {
      $err = 'Metode tidak ditemukan.';
    } elseif ($row['is_system'] && $row['is_active']) {
      $err = 'Metode sistem tidak bisa dinonaktifkan.';
    } else {
      $newStatus = $row['is_active'] ? 0 : 1;
      db()->prepare("UPDATE payment_methods SET is_active = ? WHERE id = ?")->execute([$newStatus, $id]);
      redirect(base_url('admin/payment_methods.php'));
    }
  } elseif ($action === 'delete') {
    $id = (int)($_POST['id'] ?? 0);
    $row = $id > 0 ? db()->query("SELECT * FROM payment_methods WHERE id = $id")->fetch(PDO::FETCH_ASSOC) : null;
    if (!$row) {
      $err = 'Metode tidak ditemukan.';
    } elseif ($row['is_system']) {
      $err = 'Metode sistem tidak bisa dihapus.';
    } else {
      db()->prepare("DELETE FROM payment_methods WHERE id = ? AND is_system = 0")->execute([$id]);
      redirect(base_url('admin/payment_methods.php'));
    }
  } elseif ($action === 'rename') {
    $id = (int)($_POST['id'] ?? 0);
    $name = trim((string)($_POST['name'] ?? ''));
    if ($id <= 0 || $name === '') {
      $err = 'Nama tidak boleh kosong.';
    } else {
      db()->prepare("UPDATE payment_methods SET name = ? WHERE id = ? AND is_system = 0")->execute([$name, $id]);
      redirect(base_url('admin/payment_methods.php'));
    }

  // Non-cash bank actions
  } elseif ($action === 'add_bank') {
    $name = trim((string)($_POST['bank_name'] ?? ''));
    if ($name === '') {
      $err = 'Nama bank wajib diisi.';
    } else {
      try {
        $maxOrder = (int)db()->query("SELECT COALESCE(MAX(sort_order),0) FROM qris_banks")->fetchColumn();
        db()->prepare("INSERT INTO qris_banks (name, sort_order, is_active) VALUES (?, ?, 1)")
          ->execute([$name, $maxOrder + 1]);
        $ok = 'Bank non-tunai berhasil ditambahkan.';
      } catch (Throwable $e) {
        $err = 'Gagal menambahkan bank: ' . $e->getMessage();
      }
    }
  } elseif ($action === 'edit_bank') {
    $id   = (int)($_POST['id'] ?? 0);
    $name = trim((string)($_POST['bank_name'] ?? ''));
    if ($id <= 0 || $name === '') {
      $err = 'Nama bank tidak boleh kosong.';
    } else {
      db()->prepare("UPDATE qris_banks SET name = ? WHERE id = ?")->execute([$name, $id]);
      redirect(base_url('admin/payment_methods.php'));
    }
  } elseif ($action === 'toggle_bank') {
    $id  = (int)($_POST['id'] ?? 0);
    $row = $id > 0 ? db()->query("SELECT * FROM qris_banks WHERE id = $id")->fetch(PDO::FETCH_ASSOC) : null;
    if (!$row) {
      $err = 'Bank tidak ditemukan.';
    } else {
      db()->prepare("UPDATE qris_banks SET is_active = ? WHERE id = ?")->execute([$row['is_active'] ? 0 : 1, $id]);
      redirect(base_url('admin/payment_methods.php'));
    }
  } elseif ($action === 'delete_bank') {
    $id = (int)($_POST['id'] ?? 0);
    if ($id > 0) {
      db()->prepare("DELETE FROM qris_banks WHERE id = ?")->execute([$id]);
    }
    redirect(base_url('admin/payment_methods.php'));
  }
}

$methods   = db()->query("SELECT * FROM payment_methods ORDER BY sort_order ASC, id ASC")->fetchAll(PDO::FETCH_ASSOC);
$qrisBanks = db()->query("SELECT * FROM qris_banks ORDER BY sort_order ASC, id ASC")->fetchAll(PDO::FETCH_ASSOC);
$customCss = setting('custom_css', '');
?>
<!doctype html>
<html>
<head>
  <meta charset="utf-8">
  <meta name="viewport" content="width=device-width,initial-scale=1">
  <title>Metode Pembayaran</title>
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
        <h3 style="margin-top:0">Metode Pembayaran</h3>
        <p><small>Kelola metode pembayaran yang tersedia di POS. Metode sistem (Tunai, QRIS, EDC & Transfer) tidak bisa dihapus.</small></p>
        <?php if ($err): ?>
          <div class="card" style="border-color:rgba(251,113,133,.35);background:rgba(251,113,133,.10)"><?php echo e($err); ?></div>
        <?php endif; ?>
        <?php if ($ok): ?>
          <div class="card" style="border-color:rgba(74,222,128,.35);background:rgba(74,222,128,.10)"><?php echo e($ok); ?></div>
        <?php endif; ?>

        <form method="post" style="margin-bottom:16px">
          <input type="hidden" name="_csrf" value="<?php echo e(csrf_token()); ?>">
          <input type="hidden" name="action" value="add">
          <div class="row">
            <label>Nama Metode</label>
            <input name="name" type="text" placeholder="cth. Transfer Bank" required maxlength="100" value="<?php echo e($_POST['name'] ?? ''); ?>">
          </div>
          <div class="row">
            <label>Kode (huruf/angka/underscore)</label>
            <input name="code" type="text" placeholder="cth. transfer_bank" required maxlength="50" pattern="[a-zA-Z0-9_]+" value="<?php echo e($_POST['code'] ?? ''); ?>">
            <small>Digunakan secara internal. Tidak bisa diubah setelah disimpan.</small>
          </div>
          <button class="btn" type="submit">Tambah Metode</button>
        </form>

        <?php if (empty($methods)): ?>
          <p><small>Belum ada metode pembayaran.</small></p>
        <?php else: ?>
          <div class="table-wrap" style="margin-top:12px">
            <table>
              <thead>
                <tr>
                  <th>Nama</th>
                  <th>Kode</th>
                  <th>Status</th>
                  <th>Aksi</th>
                </tr>
              </thead>
              <tbody>
                <?php foreach ($methods as $m): ?>
                  <tr>
                    <td>
                      <?php if (!$m['is_system']): ?>
                        <form method="post" style="display:flex;gap:6px;align-items:center">
                          <input type="hidden" name="_csrf" value="<?php echo e(csrf_token()); ?>">
                          <input type="hidden" name="action" value="rename">
                          <input type="hidden" name="id" value="<?php echo e((string)$m['id']); ?>">
                          <input type="text" name="name" value="<?php echo e($m['name']); ?>" maxlength="100" required style="width:140px">
                          <button class="btn" type="submit" style="padding:2px 8px;font-size:.8rem">Ubah</button>
                        </form>
                      <?php else: ?>
                        <?php echo e($m['name']); ?> <small style="opacity:.5">(sistem)</small>
                      <?php endif; ?>
                    </td>
                    <td><code><?php echo e($m['code']); ?></code></td>
                    <td><?php echo $m['is_active'] ? '<span style="color:#22c55e">Aktif</span>' : '<span style="opacity:.5">Nonaktif</span>'; ?></td>
                    <td style="display:flex;gap:6px;flex-wrap:wrap">
                      <?php if (!$m['is_system']): ?>
                        <form method="post" style="display:inline">
                          <input type="hidden" name="_csrf" value="<?php echo e(csrf_token()); ?>">
                          <input type="hidden" name="action" value="toggle">
                          <input type="hidden" name="id" value="<?php echo e((string)$m['id']); ?>">
                          <button class="btn" type="submit"><?php echo $m['is_active'] ? 'Nonaktifkan' : 'Aktifkan'; ?></button>
                        </form>
                        <form method="post" style="display:inline" data-confirm="Hapus metode pembayaran ini?">
                          <input type="hidden" name="_csrf" value="<?php echo e(csrf_token()); ?>">
                          <input type="hidden" name="action" value="delete">
                          <input type="hidden" name="id" value="<?php echo e((string)$m['id']); ?>">
                          <button class="btn" type="submit">Hapus</button>
                        </form>
                      <?php else: ?>
                        <span style="opacity:.4;font-size:.85rem">—</span>
                      <?php endif; ?>
                    </td>
                  </tr>
                <?php endforeach; ?>
              </tbody>
            </table>
          </div>
        <?php endif; ?>
      </div>

      <!-- Non-cash Banks -->
      <div class="card" style="margin-top:24px">
        <h3 style="margin-top:0">Bank Non Tunai</h3>
        <p><small>Daftar nama bank yang tampil sebagai pilihan saat kasir memilih QRIS, EDC, atau Transfer di POS.</small></p>

        <form method="post" style="margin-bottom:16px">
          <input type="hidden" name="_csrf" value="<?php echo e(csrf_token()); ?>">
          <input type="hidden" name="action" value="add_bank">
          <div class="row">
            <label>Nama Bank</label>
            <input name="bank_name" type="text" placeholder="cth. BCA, Mandiri, BRI" required maxlength="100">
          </div>
          <button class="btn" type="submit">Tambah Bank</button>
        </form>

        <?php if (empty($qrisBanks)): ?>
          <p><small>Belum ada bank non-tunai. Tambahkan di atas agar kasir bisa memilih bank saat checkout QRIS, EDC, atau Transfer.</small></p>
        <?php else: ?>
          <div class="table-wrap" style="margin-top:12px">
            <table>
              <thead>
                <tr>
                  <th>Nama Bank</th>
                  <th>Status</th>
                  <th>Aksi</th>
                </tr>
              </thead>
              <tbody>
                <?php foreach ($qrisBanks as $b): ?>
                  <tr>
                    <td>
                      <form method="post" style="display:flex;gap:6px;align-items:center">
                        <input type="hidden" name="_csrf" value="<?php echo e(csrf_token()); ?>">
                        <input type="hidden" name="action" value="edit_bank">
                        <input type="hidden" name="id" value="<?php echo e((string)$b['id']); ?>">
                        <input type="text" name="bank_name" value="<?php echo e($b['name']); ?>" maxlength="100" required style="width:150px">
                        <button class="btn" type="submit" style="padding:2px 8px;font-size:.8rem">Ubah</button>
                      </form>
                    </td>
                    <td><?php echo $b['is_active'] ? '<span style="color:#22c55e">Aktif</span>' : '<span style="opacity:.5">Nonaktif</span>'; ?></td>
                    <td style="display:flex;gap:6px;flex-wrap:wrap">
                      <form method="post" style="display:inline">
                        <input type="hidden" name="_csrf" value="<?php echo e(csrf_token()); ?>">
                        <input type="hidden" name="action" value="toggle_bank">
                        <input type="hidden" name="id" value="<?php echo e((string)$b['id']); ?>">
                        <button class="btn" type="submit"><?php echo $b['is_active'] ? 'Nonaktifkan' : 'Aktifkan'; ?></button>
                      </form>
                      <form method="post" style="display:inline" data-confirm="Hapus bank non-tunai ini?">
                        <input type="hidden" name="_csrf" value="<?php echo e(csrf_token()); ?>">
                        <input type="hidden" name="action" value="delete_bank">
                        <input type="hidden" name="id" value="<?php echo e((string)$b['id']); ?>">
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
