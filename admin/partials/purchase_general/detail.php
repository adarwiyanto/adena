<?php if ($detailHeader): ?>
<section class="card pg-detail-card" id="detail-purchase">
  <div class="pg-section-heading">
    <div>
      <h3>Detail <?php echo e($detailHeader['purchase_no']); ?></h3>
      <p><?php echo e($detailHeader['purchase_date']); ?> · <?php echo e($detailHeader['branch_name'] ?? '-'); ?> · Total Rp<?php echo e(number_format((float)$detailHeader['grand_total'], 0, ',', '.')); ?></p>
    </div>
    <a class="btn btn-light pg-btn-small" href="<?php echo e(base_url('admin/purchase_general.php')); ?>">Tutup Detail</a>
  </div>

  <div class="pg-detail-list">
    <?php foreach ($detailItems as $detailItem): ?>
      <?php $proofs = gp_evidence_files((int)$detailHeader['id'], (int)$detailItem['id']); ?>
      <article class="pg-detail-item">
        <div class="pg-detail-item__main">
          <span class="pg-type-chip"><?php echo e(gp_detect_item_type($detailItem)); ?></span>
          <strong><?php echo e($detailItem['item_name'] ?: '-'); ?></strong>
          <?php if (!empty($detailItem['notes'])): ?><small><?php echo e($detailItem['notes']); ?></small><?php endif; ?>
        </div>
        <div class="pg-detail-item__amount">
          <span><?php echo e(number_format((float)$detailItem['qty'], 4, ',', '.')); ?> × Rp<?php echo e(number_format((float)$detailItem['unit_cost'], 0, ',', '.')); ?></span>
          <strong>Rp<?php echo e(number_format((float)$detailItem['line_total'], 0, ',', '.')); ?></strong>
        </div>
        <div class="pg-proof-list">
          <?php foreach ($proofs as $proof): ?>
            <a href="<?php echo e($proof['url']); ?>" target="_blank" rel="noopener">Lihat <?php echo e($proof['display_name']); ?></a>
          <?php endforeach; ?>
          <?php if (!$proofs): ?><span class="pg-proof-missing">Tidak ada bukti pada transaksi legacy.</span><?php endif; ?>
        </div>
      </article>
    <?php endforeach; ?>
  </div>
</section>
<?php endif; ?>
