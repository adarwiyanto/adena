<?php
require_once __DIR__ . '/../../core/api_web_products.php';
require_web_product_api_token('product_images.read');
require_once __DIR__ . '/../../lib/upload_secure.php';

$id = (int)($_GET['id'] ?? 0);
if ($id <= 0) web_product_api_err('ID produk tidak valid.', 400);
$stmt = db()->prepare('SELECT image_path FROM products WHERE id = ? LIMIT 1');
$stmt->execute([$id]);
$row = $stmt->fetch(PDO::FETCH_ASSOC);
if (!$row || empty($row['image_path'])) web_product_api_err('Gambar produk tidak ditemukan.', 404);

$path = (string)$row['image_path'];
if (upload_is_legacy_path($path)) {
  $full = realpath(__DIR__ . '/../../' . $path);
} else {
  require_once __DIR__ . '/../../config/upload.php';
  $full = UPLOAD_IMG . basename($path);
}
if (!$full || !is_file($full)) web_product_api_err('File gambar produk tidak ditemukan.', 404);
$finfo = new finfo(FILEINFO_MIME_TYPE);
$mime = $finfo->file($full) ?: 'application/octet-stream';
if (!in_array($mime, ['image/jpeg','image/png'], true)) web_product_api_err('Format gambar tidak diizinkan.', 415);
header('Content-Type: ' . $mime);
header('Content-Length: ' . filesize($full));
header('X-Content-Type-Options: nosniff');
readfile($full);
exit;
