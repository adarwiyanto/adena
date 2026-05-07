<?php
$activeFile = basename($_SERVER['PHP_SELF'] ?? '');
$unitName = $unit['branch_name'] ?? 'Cabang';
?>
<div class="sidebar">
  <div class="sb-top">
    <div class="profile-card"><button class="profile-trigger" type="button" data-toggle-submenu="#unit-switch"><div class="avatar"><span class="avatar-no-photo">CB</span></div><div class="p-text"><div class="p-title"><?php echo e($unitName); ?></div><div class="p-sub">Halaman Cabang</div></div><div class="p-right"><span class="chev">▾</span></div></button></div>
    <div class="submenu profile-submenu" id="unit-switch">
      <?php foreach ($units as $b): ?><a href="<?php echo e(unit_url('cabang',(int)$b['id'],$activeFile)); ?>"><?php echo e($b['branch_name']); ?></a><?php endforeach; ?>
    </div>
  </div>
  <div class="nav">
    <div class="item"><a class="<?php echo $activeFile==='index.php'?'active':''; ?>" href="<?php echo e(unit_url('cabang',$unitId)); ?>"><div class="mi">🏪</div><div class="label">Dashboard Cabang</div></a></div>
    <div class="item"><a href="<?php echo e(base_url('pos/index.php?branch_id='.$unitId)); ?>" target="_blank" rel="noopener"><div class="mi">🧾</div><div class="label">Penjualan Cabang</div></a></div>
    <div class="item"><a class="<?php echo $activeFile==='review_sales.php'?'active':''; ?>" href="<?php echo e(unit_url('cabang',$unitId,'review_sales.php')); ?>"><div class="mi">↩️</div><div class="label">Review Penjualan/Retur</div></a></div>
    <div class="item"><a class="<?php echo $activeFile==='sales.php'?'active':''; ?>" href="<?php echo e(unit_url('cabang',$unitId,'sales.php')); ?>"><div class="mi">💳</div><div class="label">Riwayat Penjualan</div></a></div>
    <div class="item"><a class="<?php echo $activeFile==='shifts.php'?'active':''; ?>" href="<?php echo e(unit_url('cabang',$unitId,'shifts.php')); ?>"><div class="mi">🕘</div><div class="label">Laporan Shift POS</div></a></div>
    <div class="item"><a class="<?php echo $activeFile==='stocks.php'?'active':''; ?>" href="<?php echo e(unit_url('cabang',$unitId,'stocks.php')); ?>"><div class="mi">📦</div><div class="label">Stok Cabang</div></a></div>
    <div class="item"><a class="<?php echo $activeFile==='transfers.php'?'active':''; ?>" href="<?php echo e(unit_url('cabang',$unitId,'transfers.php')); ?>"><div class="mi">🚚</div><div class="label">Terima Transfer</div></a></div>
    <div class="item"><a class="<?php echo $activeFile==='legacy.php'?'active':''; ?>" href="<?php echo e(unit_url('cabang',$unitId,'legacy.php')); ?>"><div class="mi">🗄️</div><div class="label">Data Lama</div></a></div>
    <div class="item"><a href="<?php echo e(base_url('admin/dashboard.php')); ?>"><div class="mi">⚙️</div><div class="label">Admin</div></a></div>
    <div class="item"><a href="<?php echo e(base_url('admin/logout.php')); ?>"><div class="mi">⎋</div><div class="label">Logout</div></a></div>
  </div>
</div>
