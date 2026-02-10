<?php
require_once __DIR__ . '/../core/db.php';
require_once __DIR__ . '/../core/functions.php';
require_once __DIR__ . '/../core/security.php';
require_once __DIR__ . '/../core/auth.php';
require_once __DIR__ . '/../core/csrf.php';

start_secure_session();
require_admin();

$me = current_user();
if (!in_array((string)($me['role'] ?? ''), ['owner', 'admin'], true)) {
  http_response_code(403);
  exit('Forbidden');
}

ensure_company_announcements_table();
$err=''; $ok='';
if ($_SERVER['REQUEST_METHOD']==='POST') {
  csrf_check();
  try {
    $title = substr(trim((string)($_POST['title'] ?? '')),0,190);
    $message = trim((string)($_POST['message'] ?? ''));
    $audience = ($_POST['audience'] ?? 'toko') === 'dapur' ? 'dapur' : 'toko';
    if ($title==='' || $message==='') throw new Exception('Judul dan isi wajib diisi.');

    $startsAt = app_now_jakarta('Y-m-d H:i:s');
    $expiresAt = (new DateTimeImmutable($startsAt, new DateTimeZone('Asia/Jakarta')))->modify('+24 hours')->format('Y-m-d H:i:s');
    $stmt = db()->prepare('INSERT INTO company_announcements (title, message, audience, posted_by, starts_at, expires_at) VALUES (?,?,?,?,?,?)');
    $stmt->execute([$title, $message, $audience, (int)($me['id'] ?? 0), $startsAt, $expiresAt]);

    @file_put_contents(sys_get_temp_dir() . '/company_announcement_push.log', '[' . $startsAt . '] push audience=' . $audience . ' title=' . $title . PHP_EOL, FILE_APPEND | LOCK_EX);

    $ok='Pengumuman disimpan (aktif 24 jam) dan push notifikasi dipicu.';
  } catch (Throwable $e) {
    $err=$e->getMessage();
  }
}

$rows = db()->query("SELECT a.*, u.name AS posted_by_name FROM company_announcements a LEFT JOIN users u ON u.id=a.posted_by ORDER BY a.id DESC LIMIT 50")->fetchAll();
?>
<!doctype html><html><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"><title>Pengumuman Perusahaan</title><link rel="stylesheet" href="<?php echo e(asset_url('assets/app.css')); ?>"></head>
<body><div class="container"><?php include __DIR__ . '/partials_sidebar.php'; ?><div class="main"><div class="topbar"><button class="btn" data-toggle-sidebar type="button">Menu</button><div class="badge">Pengumuman Perusahaan</div></div><div class="content">
<div class="grid cols-2"><div class="card"><h3>Buat Pengumuman</h3><?php if($err):?><div class="card" style="background:rgba(251,113,133,.12)"><?php echo e($err); ?></div><?php endif; ?><?php if($ok):?><div class="card" style="background:rgba(52,211,153,.12)"><?php echo e($ok); ?></div><?php endif; ?>
<form method="post"><input type="hidden" name="_csrf" value="<?php echo e(csrf_token()); ?>"><div class="row"><label>Judul</label><input name="title" maxlength="190" required></div><div class="row"><label>Pengumuman untuk</label><select name="audience"><option value="toko">Toko</option><option value="dapur">Dapur</option></select></div><div class="row"><label>Isi</label><textarea name="message" rows="5" required></textarea></div><button class="btn" type="submit">Simpan</button><p><small>Setiap pengumuman aktif selama 24 jam.</small></p></form></div>
<div class="card"><h3>Riwayat</h3><table class="table"><thead><tr><th>Judul</th><th>Target</th><th>Dari</th><th>Aktif s/d</th></tr></thead><tbody><?php foreach($rows as $r): ?><tr><td><?php echo e($r['title']); ?></td><td><?php echo e($r['audience']); ?></td><td><?php echo e($r['posted_by_name'] ?? 'Admin'); ?></td><td><?php echo e($r['expires_at']); ?></td></tr><?php endforeach; ?></tbody></table></div>
</div></div></div></div><script defer src="<?php echo e(asset_url('assets/app.js')); ?>"></script></body></html>
