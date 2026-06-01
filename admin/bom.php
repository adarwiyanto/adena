<?php
require_once __DIR__ . '/../core/functions.php';
http_response_code(410);
?>
<!doctype html><html><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"><title>Modul Diarsipkan</title><link rel="stylesheet" href="<?php echo e(asset_url('assets/app.css')); ?>"></head><body><div class="container"><div class="main"><div class="content"><div class="card"><h3>Modul BOM Diarsipkan</h3><p>Modul BOM tidak digunakan pada mode toko.</p><a class="btn" href="<?php echo e(base_url('admin/dashboard.php')); ?>">Kembali ke Dashboard</a></div></div></div></div></body></html>
