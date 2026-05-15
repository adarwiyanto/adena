<?php
require_once __DIR__ . '/../core/functions.php';
http_response_code(410);
?>
<!doctype html>
<html><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"><title>Modul Diarsipkan</title>
<link rel="stylesheet" href="<?php echo e(asset_url('assets/app.css')); ?>"></head>
<body><div class="container"><div class="main"><div class="content"><div class="card">
<h3>Modul Pembelian Bahan Baku Diarsipkan</h3>
<p>Mode sistem saat ini adalah toko. Pembelian bahan baku/dapur tidak digunakan.</p>
<p>Gunakan menu <a class="btn" href="<?php echo e(base_url('admin/purchase_goods.php')); ?>">Pembelian Barang</a>.</p>
</div></div></div></div></body></html>
