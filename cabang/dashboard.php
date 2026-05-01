<?php
require_once __DIR__ . '/../core/branch_portal.php';
require_once __DIR__ . '/_layout.php';
$u = branch_portal_current_user();
if (isset($_GET['branch_id'])) { try { branch_portal_set_active_branch($u, (int)$_GET['branch_id']); } catch (Throwable $e) {} }
$branchId = branch_portal_active_branch_id($u);
$branch = branch_portal_branch($branchId) ?: ['branch_name'=>'Halaman Cabang'];
$customCss = setting('custom_css','');
$pendingInputs = 0; $pendingOpnames = 0;
try { $stmt=db()->prepare("SELECT COUNT(*) c FROM branch_stock_inputs WHERE branch_id=? AND status='pending'"); $stmt->execute([$branchId]); $pendingInputs=(int)$stmt->fetch()['c']; } catch(Throwable $e) {}
try { $stmt=db()->prepare("SELECT COUNT(*) c FROM stock_opname_headers WHERE branch_id=? AND status='waiting_approval'"); $stmt->execute([$branchId]); $pendingOpnames=(int)$stmt->fetch()['c']; } catch(Throwable $e) {}
cabang_header('Dashboard Cabang', $branch, $customCss);
?>
<div class="card"><h3>Dashboard Cabang</h3><p style="color:#64748b">Halaman ini khusus input operasional cabang. Stok real-time tidak ditampilkan untuk menjaga fungsi kontrol.</p><div class="grid cols-3"><div class="card"><strong>Cabang Aktif</strong><h3><?php echo e((string)$branch['branch_name']); ?></h3><small><?php echo e((string)($branch['branch_code'] ?? '')); ?></small></div><div class="card"><strong>Stok Masuk Pending</strong><h3><?php echo e((string)$pendingInputs); ?></h3></div><div class="card"><strong>Opname Menunggu Approval</strong><h3><?php echo e((string)$pendingOpnames); ?></h3></div></div></div>
<div class="card"><h3>Aksi Cepat</h3><div style="display:flex;gap:10px;flex-wrap:wrap"><a class="btn" href="<?php echo e(base_url('cabang/stok_masuk.php')); ?>">Input Stok Masuk</a><a class="btn" href="<?php echo e(base_url('cabang/stock_opname.php')); ?>">Input Stock Opname</a><a class="btn btn-light" href="<?php echo e(base_url('cabang/riwayat.php')); ?>">Lihat Riwayat</a></div></div>
<?php cabang_footer(); ?>
