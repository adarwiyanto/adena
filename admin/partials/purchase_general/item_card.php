<?php
$item = is_array($item ?? null) ? $item : gp_default_form_item();
$index = (string)($index ?? '0');
$type = (string)($item['type'] ?? 'product');
?>
<article class="pg-item-card" data-item-card data-index="<?php echo e($index); ?>">
  <div class="pg-item-card__header">
    <div>
      <span class="pg-item-number">Item <span data-item-number>1</span></span>
      <strong data-item-title><?php echo e(gp_item_types()[$type]['label'] ?? 'Item Pembelian'); ?></strong>
    </div>
    <button class="btn btn-danger pg-btn-small" type="button" data-remove-item>Hapus</button>
  </div>

  <div class="pg-item-grid">
    <label class="pg-field pg-span-4">
      <span>Jenis Pembelian</span>
      <select name="items[<?php echo e($index); ?>][type]" data-item-type required>
        <?php foreach (gp_item_types() as $key => $definition): ?>
          <option value="<?php echo e($key); ?>" <?php echo $type === $key ? 'selected' : ''; ?>><?php echo e($definition['label']); ?></option>
        <?php endforeach; ?>
      </select>
    </label>

    <label class="pg-field pg-span-8" data-show-for="product">
      <span>Produk / Barang</span>
      <select name="items[<?php echo e($index); ?>][product_id]" data-product-select>
        <option value="">- pilih produk -</option>
        <?php foreach ($products as $product): ?>
          <option value="<?php echo e((string)$product['id']); ?>" <?php echo (string)($item['product_id'] ?? '') === (string)$product['id'] ? 'selected' : ''; ?>>
            <?php echo e($product['name']); ?><?php echo !empty($product['allow_direct_purchase']) ? ' · pembelian langsung' : ''; ?>
          </option>
        <?php endforeach; ?>
      </select>
      <small>Produk dipakai sebagai referensi transaksi. Pembelian Umum tidak menambah stok.</small>
    </label>

    <label class="pg-field pg-span-4" data-show-for="guide">
      <span>Guide Terdaftar</span>
      <select name="items[<?php echo e($index); ?>][guide_id]">
        <option value="">- pilih guide / isi manual -</option>
        <?php foreach ($guides as $guide): ?>
          <option value="<?php echo e((string)$guide['id']); ?>" <?php echo (string)($item['guide_id'] ?? '') === (string)$guide['id'] ? 'selected' : ''; ?>><?php echo e($guide['name']); ?></option>
        <?php endforeach; ?>
      </select>
    </label>

    <label class="pg-field pg-span-4" data-show-for="guide">
      <span>Nama Guide Manual</span>
      <input name="items[<?php echo e($index); ?>][guide_name_manual]" value="<?php echo e((string)($item['guide_name_manual'] ?? '')); ?>" placeholder="Isi bila tidak ada di daftar">
    </label>

    <label class="pg-field pg-span-8" data-show-for="electricity guide office_supplies kitchen_project service transport maintenance other">
      <span>Nama / Uraian</span>
      <input name="items[<?php echo e($index); ?>][description]" value="<?php echo e((string)($item['description'] ?? '')); ?>" placeholder="Contoh: Komisi Guide / tagihan listrik Juli / kertas A4 / perbaikan exhaust" data-description>
    </label>

    <label class="pg-field pg-span-2">
      <span>Qty</span>
      <input name="items[<?php echo e($index); ?>][qty]" type="number" min="0.0001" step="0.0001" value="<?php echo e((string)($item['qty'] ?? '1')); ?>" data-qty required>
    </label>

    <label class="pg-field pg-span-4">
      <span>Harga / Nominal</span>
      <input name="items[<?php echo e($index); ?>][unit_cost]" type="number" min="0.01" step="0.01" value="<?php echo e((string)($item['unit_cost'] ?? '')); ?>" placeholder="0" data-unit-cost required>
    </label>

    <div class="pg-field pg-span-2 pg-line-total">
      <span>Total Item</span>
      <strong data-line-total>Rp0</strong>
    </div>

    <label class="pg-field pg-span-4" data-show-for="electricity">
      <span>Periode Tagihan</span>
      <input name="items[<?php echo e($index); ?>][period]" value="<?php echo e((string)($item['period'] ?? '')); ?>" placeholder="Contoh: Juli 2026">
    </label>

    <label class="pg-field pg-span-4" data-show-for="electricity">
      <span>Nomor Pelanggan</span>
      <input name="items[<?php echo e($index); ?>][customer_no]" value="<?php echo e((string)($item['customer_no'] ?? '')); ?>" placeholder="ID pelanggan / meter">
    </label>

    <label class="pg-field pg-span-4" data-show-for="electricity guide office_supplies kitchen_project service transport maintenance other">
      <span>Metode Pembayaran / Pemberian</span>
      <select name="items[<?php echo e($index); ?>][payment_method]">
        <?php $payment = (string)($item['payment_method'] ?? ''); ?>
        <option value="">- pilih bila ada -</option>
        <option value="Transfer" <?php echo $payment === 'Transfer' ? 'selected' : ''; ?>>Transfer</option>
        <option value="Tunai" <?php echo $payment === 'Tunai' ? 'selected' : ''; ?>>Tunai</option>
        <option value="Kartu / EDC" <?php echo $payment === 'Kartu / EDC' ? 'selected' : ''; ?>>Kartu / EDC</option>
        <option value="QRIS" <?php echo $payment === 'QRIS' ? 'selected' : ''; ?>>QRIS</option>
        <option value="Lainnya" <?php echo $payment === 'Lainnya' ? 'selected' : ''; ?>>Lainnya</option>
      </select>
    </label>

    <label class="pg-field pg-span-4" data-show-for="electricity guide office_supplies kitchen_project service transport maintenance other">
      <span>Nomor Referensi</span>
      <input name="items[<?php echo e($index); ?>][reference_no]" value="<?php echo e((string)($item['reference_no'] ?? '')); ?>" placeholder="No. transfer / struk / referensi">
    </label>

    <label class="pg-field pg-span-8">
      <span>Catatan Item</span>
      <input name="items[<?php echo e($index); ?>][notes]" value="<?php echo e((string)($item['notes'] ?? '')); ?>" placeholder="Keterangan tambahan">
    </label>

    <label class="pg-field pg-span-4 pg-evidence-field">
      <span>Bukti Transaksi <b>*</b></span>
      <input name="item_evidence[<?php echo e($index); ?>][]" type="file" accept=".jpg,.jpeg,.png,.webp,.pdf,image/jpeg,image/png,image/webp,application/pdf" multiple data-evidence required>
      <small>Wajib. JPG, PNG, WEBP, atau PDF; maksimal 5 file, masing-masing 8 MB.</small>
      <span class="pg-file-list" data-file-list>Belum ada file dipilih.</span>
    </label>
  </div>
</article>
