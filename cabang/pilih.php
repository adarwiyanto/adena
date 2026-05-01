<?php
require_once __DIR__ . '/../core/branch_portal.php';
require_once __DIR__ . '/../core/csrf.php';
require_once __DIR__ . '/_layout.php';
$u = branch_portal_current_user();
$err='';
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
  csrf_check();
  try {
    branch_portal_set_active_branch($u, (int)($_POST['branch_id'] ?? 0));
    redirect(base_url('cabang/dashboard.php'));
  } catch (Throwable $e) { $err = $e->getMessage(); }
}
$branches = branch_portal_allowed_branches($u);
$activeId = branch_portal_active_branch_id($u);
$branch = branch_portal_branch($activeId) ?: ['branch_name'=>'Halaman Cabang'];
$customCss = setting('custom_css','');
cabang_header('Pilih Cabang', $branch, $customCss);
?>
<div class="card"><h3>Pilih Cabang Aktif</h3><p style="color:#64748b">Perpindahan cabang tidak otomatis. Pilih cabang lalu klik <strong>Terapkan</strong>.</p><?php if($err): ?><div class="card" style="border-color:#fecdd3;background:#fff1f2;color:#9f1239"><?php echo e($err); ?></div><?php endif; ?><form method="post" class="grid cols-3"><input type="hidden" name="_csrf" value="<?php echo e(csrf_token()); ?>"><div class="row"><label>Cabang</label><select name="branch_id"><?php foreach($branches as $b): ?><option value="<?php echo e((string)$b['id']); ?>" <?php echo (int)$b['id']===$activeId?'selected':''; ?>><?php echo e((string)$b['branch_name']); ?></option><?php endforeach; ?></select></div><div class="row" style="align-self:end"><button class="btn" type="submit">Terapkan</button></div></form></div>
<?php cabang_footer(); ?>
