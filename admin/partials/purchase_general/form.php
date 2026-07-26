<section class="card pg-form-card">
  <div class="pg-section-heading">
    <div>
      <h3>Pembelian Umum</h3>
      <p>Catat produk, PLN, komisi Guide, ATK, proyek dapur, jasa, dan pengeluaran lainnya. Semua item wajib memiliki bukti.</p>
    </div>
    <span class="pg-api-badge">Bukti wajib per item</span>
  </div>

  <form method="post" enctype="multipart/form-data" data-purchase-form>
    <input type="hidden" name="_csrf" value="<?php echo e(csrf_token()); ?>">

    <div class="pg-header-grid">
      <label class="pg-field">
        <span>Nomor Purchase</span>
        <input name="purchase_no" value="<?php echo e($formValues['purchase_no']); ?>" maxlength="50" required>
      </label>
      <label class="pg-field">
        <span>Tanggal</span>
        <input type="date" name="purchase_date" value="<?php echo e($formValues['purchase_date']); ?>" required>
      </label>
      <label class="pg-field">
        <span>Cabang</span>
        <select name="branch_id" required>
          <?php foreach ($branches as $branch): ?>
            <option value="<?php echo e((string)$branch['id']); ?>" <?php echo (string)$formValues['branch_id'] === (string)$branch['id'] ? 'selected' : ''; ?>>
              <?php echo e($branch['branch_name'] ?? $branch['name'] ?? ('Cabang ' . $branch['id'])); ?>
            </option>
          <?php endforeach; ?>
        </select>
      </label>
      <label class="pg-field" data-supplier-field>
        <span>Supplier Produk</span>
        <select name="supplier_id" data-supplier-select>
          <option value="">- pilih supplier -</option>
          <?php foreach ($suppliers as $supplier): ?>
            <option value="<?php echo e((string)$supplier['id']); ?>" <?php echo (string)$formValues['supplier_id'] === (string)$supplier['id'] ? 'selected' : ''; ?>><?php echo e($supplier['supplier_name']); ?></option>
          <?php endforeach; ?>
        </select>
        <small>Muncul dan wajib hanya bila ada item Produk / Barang.</small>
      </label>
    </div>

    <label class="pg-field pg-general-note">
      <span>Catatan Umum</span>
      <textarea name="notes" rows="2" placeholder="Catatan transaksi secara umum"><?php echo e($formValues['notes']); ?></textarea>
    </label>

    <div class="pg-items-toolbar">
      <div>
        <h4>Daftar Item</h4>
        <small>Jenis item menentukan field yang ditampilkan. Data tetap disimpan ke struktur pembelian lama.</small>
      </div>
      <button class="btn btn-light" type="button" data-add-item>+ Tambah Item</button>
    </div>

    <div class="pg-items" data-items-container>
      <?php foreach ($formItems as $index => $item): ?>
        <?php include __DIR__ . '/item_card.php'; ?>
      <?php endforeach; ?>
    </div>

    <div class="pg-form-footer">
      <div class="pg-total-box">
        <span>Total Pembelian</span>
        <strong data-grand-total>Rp0</strong>
      </div>
      <button class="btn pg-submit" type="submit">Simpan Pembelian Umum</button>
    </div>
  </form>
</section>

<template id="pg-item-template">
  <?php $index = '__INDEX__'; $item = gp_default_form_item(); include __DIR__ . '/item_card.php'; ?>
</template>
