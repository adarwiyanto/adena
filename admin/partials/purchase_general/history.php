<section class="card pg-history-card">
  <div class="pg-section-heading">
    <div>
      <h3>Riwayat Pembelian Umum</h3>
      <p>Menampilkan transaksi Pembelian Umum bernomor PG beserta rincian item dan bukti yang tersimpan.</p>
    </div>
  </div>

  <div class="pg-history-table-wrap">
    <table class="table pg-history-table">
      <thead>
        <tr>
          <th>No Purchase</th>
          <th>Tanggal</th>
          <th>Cabang</th>
          <th>Supplier</th>
          <th>Item</th>
          <th>Total</th>
          <th>Status</th>
          <th>Aksi</th>
        </tr>
      </thead>
      <tbody>
        <?php foreach ($rows as $row): ?>
          <tr>
            <td><strong><?php echo e($row['purchase_no']); ?></strong></td>
            <td><?php echo e($row['purchase_date']); ?></td>
            <td><?php echo e($row['branch_name'] ?? '-'); ?></td>
            <td><?php echo e(($row['supplier_code'] ?? '') === 'GENERAL_EXPENSE_SYSTEM' ? 'Pengeluaran operasional' : ($row['supplier_name'] ?? '-')); ?></td>
            <td><?php echo e((string)($row['item_count'] ?? 0)); ?></td>
            <td>Rp<?php echo e(number_format((float)$row['grand_total'], 0, ',', '.')); ?></td>
            <td><span class="pg-status pg-status--<?php echo e((string)$row['status']); ?>"><?php echo e(ucfirst((string)$row['status'])); ?></span></td>
            <td><a class="btn btn-light pg-btn-small" href="<?php echo e(base_url('admin/purchase_general.php?detail=' . (int)$row['id'])); ?>">Detail</a></td>
          </tr>
        <?php endforeach; ?>
        <?php if (!$rows): ?>
          <tr><td colspan="8" class="pg-empty">Belum ada transaksi Pembelian Umum.</td></tr>
        <?php endif; ?>
      </tbody>
    </table>
  </div>
</section>
