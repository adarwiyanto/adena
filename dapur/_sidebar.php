<?php
$activeFile = basename($_SERVER['PHP_SELF'] ?? '');
$unitName = $unit['branch_name'] ?? 'Dapur';
?>
<div class="sidebar">
  <div class="sb-top">
    <div class="profile-card"><button class="profile-trigger" type="button" data-toggle-submenu="#unit-switch"><div class="avatar"><span class="avatar-no-photo">DP</span></div><div class="p-text"><div class="p-title"><?php echo e($unitName); ?></div><div class="p-sub">Halaman Dapur</div></div><div class="p-right"><span class="chev">▾</span></div></button></div>
    <div class="submenu profile-submenu" id="unit-switch">
      <?php foreach ($units as $b): ?><a href="<?php echo e(unit_url('dapur',(int)$b['id'],$activeFile)); ?>"><?php echo e($b['branch_name']); ?></a><?php endforeach; ?>
    </div>
  </div>
  <div class="nav">
    <div class="item"><a class="<?php echo $activeFile==='index.php'?'active':''; ?>" href="<?php echo e(unit_url('dapur',$unitId)); ?>"><div class="mi">🍳</div><div class="label">Dashboard Dapur</div></a></div>
    <div class="item"><a class="<?php echo $activeFile==='production.php'?'active':''; ?>" href="<?php echo e(unit_url('dapur',$unitId,'production.php')); ?>"><div class="mi">⚙️</div><div class="label">Produksi BOM</div></a></div>
    <div class="item"><a class="<?php echo $activeFile==='stocks.php'?'active':''; ?>" href="<?php echo e(unit_url('dapur',$unitId,'stocks.php')); ?>"><div class="mi">📦</div><div class="label">Stok Dapur</div></a></div>
    <div class="item"><a class="<?php echo $activeFile==='transfers.php'?'active':''; ?>" href="<?php echo e(unit_url('dapur',$unitId,'transfers.php')); ?>"><div class="mi">🚚</div><div class="label">Transfer ke Cabang</div></a></div>
    <div class="item"><a href="<?php echo e(base_url('pos/index.php?branch_id='.$unitId.'&sale_source=kitchen_direct')); ?>" target="_blank" rel="noopener"><div class="mi">🧾</div><div class="label">Penjualan Langsung</div></a></div>
    <div class="item"><a class="<?php echo $activeFile==='review_sales.php'?'active':''; ?>" href="<?php echo e(unit_url('dapur',$unitId,'review_sales.php')); ?>"><div class="mi">↩️</div><div class="label">Review Penjualan/Retur</div></a></div>
    <div class="item"><a class="<?php echo $activeFile==='sales.php'?'active':''; ?>" href="<?php echo e(unit_url('dapur',$unitId,'sales.php')); ?>"><div class="mi">💳</div><div class="label">Riwayat Penjualan Dapur</div></a></div>
    <div class="item"><a class="<?php echo $activeFile==='legacy.php'?'active':''; ?>" href="<?php echo e(unit_url('dapur',$unitId,'legacy.php')); ?>"><div class="mi">🗄️</div><div class="label">Data Lama</div></a></div>
    <div class="item"><a href="<?php echo e(base_url('admin/dashboard.php')); ?>"><div class="mi">⚙️</div><div class="label">Admin</div></a></div>
    <div class="item"><a href="<?php echo e(base_url('admin/logout.php')); ?>"><div class="mi">⎋</div><div class="label">Logout</div></a></div>
  </div>
</div>
