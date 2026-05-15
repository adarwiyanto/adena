<?php
// Archived module: Produksi tidak digunakan pada mode toko.
// File sengaja disisakan sebagai arsip aman agar link lama tidak menimbulkan fatal error.
http_response_code(410);
require_once __DIR__ . '/../core/functions.php';
echo 'Modul Produksi telah diarsipkan karena sistem ini berjalan sebagai toko, bukan dapur.';
