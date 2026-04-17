<?php
require_once __DIR__ . '/../core/db.php';
require_once __DIR__ . '/../core/functions.php';
require_once __DIR__ . '/../core/security.php';
require_once __DIR__ . '/../core/auth.php';
require_once __DIR__ . '/../core/csrf.php';
require_once __DIR__ . '/../core/inventory.php';

start_secure_session();
require_admin();
ensure_inventory_module_schema();

$me = current_user() ?? [];
$isOwner = (($me['role'] ?? '') === 'owner');
$err = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
  csrf_check();
  try {
    set_setting('production_mode', in_array($_POST['production_mode'] ?? 'auto', ['auto', 'manual_review'], true) ? $_POST['production_mode'] : 'auto');
    set_setting('pos_autoproduction_enabled', isset($_POST['pos_autoproduction_enabled']) ? '1' : '0');
    set_setting('pos_autoproduction_allow_negative', isset($_POST['pos_autoproduction_allow_negative']) ? '1' : '0');
    set_setting('bom_require_exact_material_stock', isset($_POST['bom_require_exact_material_stock']) ? '1' : '0');
    set_setting('purchase_raw_material_only', isset($_POST['purchase_raw_material_only']) ? '1' : '0');
    set_setting('active_branch_id', (string)(int)($_POST['active_branch_id'] ?? active_branch_id()));

    if (isset($_POST['save_number_format'])) {
      if (!$isOwner) {
        throw new Exception('Pengaturan format angka hanya bisa diubah oleh owner.');
      }
      $qtyDecimals = max(0, min(6, (int)($_POST['number_decimal_places_qty'] ?? 2)));
      $moneyDecimals = max(0, min(6, (int)($_POST['number_decimal_places_money'] ?? 2)));
      $decimalSeparator = (string)($_POST['number_decimal_separator'] ?? '.');
      $thousandSeparator = (string)($_POST['number_thousand_separator'] ?? ',');
      if (!in_array($decimalSeparator, ['.', ','], true)) $decimalSeparator = '.';
      if (!in_array($thousandSeparator, [',', '.', ' '], true)) $thousandSeparator = ',';
      if ($decimalSeparator === $thousandSeparator) {
        throw new Exception('Pemisah desimal dan ribuan tidak boleh sama.');
      }
      set_setting('number_decimal_places_qty', (string)$qtyDecimals);
      set_setting('number_decimal_places_money', (string)$moneyDecimals);
      set_setting('number_decimal_separator', $decimalSeparator);
      set_setting('number_thousand_separator', $thousandSeparator);
      set_setting('number_trim_trailing_zero', isset($_POST['number_trim_trailing_zero']) ? '1' : '0');
      set_setting('number_show_unit_after_qty', isset($_POST['number_show_unit_after_qty']) ? '1' : '0');
    }

    redirect(base_url('admin/inventory_settings.php'));
  } catch (Throwable $e) {
    $err = $e->getMessage();
  }
}

$branches = inventory_branches();
$customCss = setting('custom_css', '');
?>
<!doctype html><html><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"><title>Setting Produksi/Inventory</title><link rel="icon" href="<?php echo e(favicon_url()); ?>"><link rel="stylesheet" href="<?php echo e(asset_url('assets/app.css')); ?>"><style><?php echo $customCss; ?></style></head><body><div class="container"><?php include __DIR__ . '/partials_sidebar.php'; ?><div class="main"><div class="topbar"><button class="btn" data-toggle-sidebar type="button">Menu</button></div><div class="content"><div class="card"><h3>Setting Produksi / Inventory</h3><?php if($err): ?><div class="card" style="border-color:rgba(251,113,133,.35);background:rgba(251,113,133,.10)"><?php echo e($err); ?></div><?php endif; ?><form method="post"><input type="hidden" name="_csrf" value="<?php echo e(csrf_token()); ?>"><div class="row"><label>Cabang aktif</label><select name="active_branch_id"><?php foreach($branches as $b): ?><option value="<?php echo e((string)$b['id']); ?>" <?php echo (int)$b['id']===active_branch_id()?'selected':''; ?>><?php echo e($b['branch_name']); ?></option><?php endforeach; ?></select></div><div class="row"><label>Production Mode</label><select name="production_mode"><option value="auto" <?php echo setting('production_mode','auto')==='auto'?'selected':''; ?>>auto</option><option value="manual_review" <?php echo setting('production_mode','auto')==='manual_review'?'selected':''; ?>>manual_review</option></select></div><label class="checkbox-row"><input type="checkbox" name="pos_autoproduction_enabled" value="1" <?php echo setting('pos_autoproduction_enabled','1')==='1'?'checked':''; ?>> POS autoproduction enabled</label><label class="checkbox-row"><input type="checkbox" name="pos_autoproduction_allow_negative" value="1" <?php echo setting('pos_autoproduction_allow_negative','0')==='1'?'checked':''; ?>> Allow negative stock</label><label class="checkbox-row"><input type="checkbox" name="bom_require_exact_material_stock" value="1" <?php echo setting('bom_require_exact_material_stock','1')==='1'?'checked':''; ?>> BOM exact stock required</label><label class="checkbox-row"><input type="checkbox" name="purchase_raw_material_only" value="1" <?php echo setting('purchase_raw_material_only','1')==='1'?'checked':''; ?>> Pembelian hanya raw material</label>
<hr>
<h4>Format Angka Global</h4>
<?php if(!$isOwner): ?><p><small>Mode lihat saja: hanya owner yang dapat menyimpan perubahan format angka.</small></p><?php endif; ?>
<div class="grid cols-3">
<div class="row"><label>Desimal Qty</label><input type="number" name="number_decimal_places_qty" min="0" max="6" value="<?php echo e((string)get_number_setting('number_decimal_places_qty', '2')); ?>" <?php echo !$isOwner ? 'disabled' : ''; ?>></div>
<div class="row"><label>Desimal Uang</label><input type="number" name="number_decimal_places_money" min="0" max="6" value="<?php echo e((string)get_number_setting('number_decimal_places_money', '2')); ?>" <?php echo !$isOwner ? 'disabled' : ''; ?>></div>
<div class="row"><label>Pemisah Desimal</label><select name="number_decimal_separator" <?php echo !$isOwner ? 'disabled' : ''; ?>><option value="." <?php echo get_number_setting('number_decimal_separator', '.')==='.'?'selected':''; ?>>.</option><option value="," <?php echo get_number_setting('number_decimal_separator', '.')===','?'selected':''; ?>>,</option></select></div>
<div class="row"><label>Pemisah Ribuan</label><select name="number_thousand_separator" <?php echo !$isOwner ? 'disabled' : ''; ?>><option value="," <?php echo get_number_setting('number_thousand_separator', ',')===','?'selected':''; ?>>,</option><option value="." <?php echo get_number_setting('number_thousand_separator', ',')==='.'?'selected':''; ?>>.</option><option value=" " <?php echo get_number_setting('number_thousand_separator', ',')===' '?'selected':''; ?>>spasi</option></select></div>
</div>
<label class="checkbox-row"><input type="checkbox" name="number_trim_trailing_zero" value="1" <?php echo get_number_setting('number_trim_trailing_zero', '0')==='1'?'checked':''; ?> <?php echo !$isOwner ? 'disabled' : ''; ?>> Hapus nol di belakang desimal</label>
<label class="checkbox-row"><input type="checkbox" name="number_show_unit_after_qty" value="1" <?php echo get_number_setting('number_show_unit_after_qty', '1')==='1'?'checked':''; ?> <?php echo !$isOwner ? 'disabled' : ''; ?>> Tampilkan satuan setelah qty</label>
<button class="btn" type="submit" name="save_number_format" value="1">Simpan Setting</button></form></div></div></div></div><script defer src="<?php echo e(asset_url('assets/app.js')); ?>"></script></body></html>
