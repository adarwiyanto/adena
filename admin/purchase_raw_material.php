<?php
// Archived module: Pembelian bahan baku tidak digunakan pada mode toko.
// Gunakan admin/purchase_goods.php untuk pembelian barang toko.
http_response_code(410);
require_once __DIR__ . '/../core/functions.php';
echo 'Modul Pembelian Bahan Baku telah diarsipkan. Gunakan menu Pembelian Barang.';
