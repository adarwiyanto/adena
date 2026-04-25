<?php
require_once __DIR__ . '/../core/db.php';
require_once __DIR__ . '/../core/functions.php';
require_once __DIR__ . '/../core/security.php';
require_once __DIR__ . '/../core/auth.php';
require_once __DIR__ . '/../core/csrf.php';
require_once __DIR__ . '/../core/rbac.php';

start_secure_session();
$me = require_menu_access('settings', 'view');
ensure_guides_table();

$resolvedRole = (string)(resolve_user_role($me)['role_key'] ?? '');
if (!in_array($resolvedRole, ['owner', 'admin'], true)) {
  redirect(base_url('admin/dashboard.php'));
}

$err = '';
$ok  = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
  csrf_check();
  $action = $_POST['action'] ?? '';

  if ($action === 'add') {
    $name = trim((string)($_POST['name'] ?? ''));
    if ($name === '') {
      $err = 'Nama guide wajib diisi.';
    } else {
      try {
        db()->prepare("INSERT INTO guides (name, is_active) VALUES (?, 1)")->execute([$name]);
        $ok = 'Guide berhasil ditambahkan.';
      } catch (Throwable $e) {
        $err = 'Nama guide sudah ada.';
      }
    }
  } elseif ($action === 'edit') {
    $id   = (int)($_POST['id'] ?? 0);
    $name = trim((string)($_POST['name'] ?? ''));
    if ($id <= 0 || $name === '') {
      $err = 'Nama tidak boleh kosong.';
    } else {
      db()->prepare("UPDATE guides SET name = ? WHERE id = ?")->execute([$name, $id]);
      redirect(base_url('admin/guides.php'));
    }
  } elseif ($action === 'toggle') {
    $id  = (int)($_POST['id'] ?? 0);
    $row = $id > 0 ? db()->query("SELECT * FROM guides WHERE id = $id")->fetch(PDO::FETCH_ASSOC) : null;
    if (!$row) {
      $err = 'Guide tidak ditemukan.';
    } else {
      db()->prepare("UPDATE guides SET is_active = ? WHERE id = ?")->execute([$row['is_active'] ? 0 : 1, $id]);
      redirect(base_url('admin/guides.php'));
    }
  } elseif ($action === 'delete') {
    $id = (int)($_POST['id'] ?? 0);
    if ($id > 0) {
      db()->prepare("DELETE FROM guides WHERE id = ?")->execute([$id]);
    }
    redirect(base_url('admin/guides.php'));
  }
}

$guides    = db()->query("SELECT * FROM guides ORDER BY name ASC")->fetchAll(PDO::FETCH_ASSOC);
$customCss = setting('custom_css', '');
?>
<!doctype html>
<html>
<head>
  <meta charset="utf-8">
  <meta name="viewport" content="width=device-width,initial-scale=1">
  <title>Daftar Guide</title>
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
        <h3 style="margin-top:0">Daftar Guide</h3>
        <p><small>Kelola daftar nama guide yang dapat dipilih di POS saat checkout. Nama guide akan muncul di struk.</small></p>
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
            <label>Nama Guide</label>
            <input name="name" type="text" placeholder="cth. Budi Santoso" required maxlength="100" value="<?php echo e($_POST['name'] ?? ''); ?>">
          </div>
          <button class="btn" type="submit">Tambah Guide</button>
        </form>

        <?php if (empty($guides)): ?>
          <p><small>Belum ada guide terdaftar.</small></p>
        <?php else: ?>
          <div class="table-wrap" style="margin-top:12px">
            <table>
              <thead>
                <tr>
                  <th>Nama</th>
                  <th>Status</th>
                  <th>Aksi</th>
                </tr>
              </thead>
              <tbody>
                <?php foreach ($guides as $g): ?>
                  <tr>
                    <td>
                      <form method="post" style="display:flex;gap:6px;align-items:center">
                        <input type="hidden" name="_csrf" value="<?php echo e(csrf_token()); ?>">
                        <input type="hidden" name="action" value="edit">
                        <input type="hidden" name="id" value="<?php echo e((string)$g['id']); ?>">
                        <input type="text" name="name" value="<?php echo e($g['name']); ?>" maxlength="100" required style="width:200px">
                        <button class="btn" type="submit" style="padding:2px 8px;font-size:.8rem">Ubah</button>
                      </form>
                    </td>
                    <td><?php echo $g['is_active'] ? '<span style="color:#22c55e">Aktif</span>' : '<span style="opacity:.5">Nonaktif</span>'; ?></td>
                    <td style="display:flex;gap:6px;flex-wrap:wrap">
                      <form method="post">
                        <input type="hidden" name="_csrf" value="<?php echo e(csrf_token()); ?>">
                        <input type="hidden" name="action" value="toggle">
                        <input type="hidden" name="id" value="<?php echo e((string)$g['id']); ?>">
                        <button class="btn" type="submit" style="font-size:.8rem"><?php echo $g['is_active'] ? 'Nonaktifkan' : 'Aktifkan'; ?></button>
                      </form>
                      <form method="post" onsubmit="return confirm('Hapus guide ini?')">
                        <input type="hidden" name="_csrf" value="<?php echo e(csrf_token()); ?>">
                        <input type="hidden" name="action" value="delete">
                        <input type="hidden" name="id" value="<?php echo e((string)$g['id']); ?>">
                        <button class="btn" type="submit" style="background:#fff1f2;border-color:#fecdd3;color:#be123c;font-size:.8rem">Hapus</button>
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
<script src="<?php echo e(asset_url('assets/app.js')); ?>"></script>
</body>
</html>
