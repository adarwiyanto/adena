<?php
/**
 * Secure product image endpoint for POS Desktop.
 *
 * Why this endpoint exists:
 * Product images are stored outside public_html in private_uploads, so Electron
 * cannot and should not access them directly. The desktop app downloads images
 * through this token-protected endpoint and caches them locally.
 */

require_once __DIR__ . '/../helpers.php';
require_once __DIR__ . '/../../config/upload.php';

$token = require_api_token();

function media_fail_image(string $message, int $status = 404): void {
    http_response_code($status);
    header('Content-Type: application/json; charset=utf-8');
    header('X-Content-Type-Options: nosniff');
    echo json_encode(['ok' => false, 'message' => $message], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    exit;
}

function media_safe_filename(?string $raw): string {
    $raw = trim((string)$raw);
    if ($raw === '') return '';

    // Full URL should not be served from private storage by this endpoint.
    if (preg_match('~^https?://~i', $raw) || str_starts_with($raw, 'data:')) {
        return '';
    }

    $raw = str_replace('\\', '/', $raw);

    // Common legacy/public forms. Keep only the final filename for secure private lookup.
    $raw = preg_replace('~^/?(?:uploads|private_uploads)/[^/]+/(?:images|image)/~i', '', $raw);
    $raw = preg_replace('~^/?(?:images|image)/~i', '', $raw);

    $name = basename($raw);
    if ($name === '' || $name === '.' || $name === '..') return '';
    if (!preg_match('/^[A-Za-z0-9._ -]+$/', $name)) return '';
    return $name;
}

function media_candidate_dirs(): array {
    $dirs = [];
    if (defined('UPLOAD_IMG')) {
        $dirs[] = UPLOAD_IMG;
    }

    // Fallbacks for cPanel layouts. This keeps the endpoint usable even if
    // HOPE_UPLOAD_BASE/config.local.php has not been updated yet.
    $root = realpath(__DIR__ . '/../..');
    $rootPath = $root ? str_replace('\\', '/', $root) : '';
    if ($rootPath && preg_match('~^/home/([^/]+)/public_html(?:/.*)?$~', $rootPath, $m)) {
        $home = '/home/' . $m[1];
        $dirs[] = $home . '/private_uploads/ketam_isi_adena/images/';
        $dirs[] = $home . '/private_uploads/adena/images/';
        $dirs[] = $home . '/private_uploads/hope/images/';
    }

    // Local/XAMPP fallbacks.
    $dirs[] = dirname((string)$rootPath) . '/private_uploads/ketam_isi_adena/images/';
    $dirs[] = dirname((string)$rootPath) . '/private_uploads/adena/images/';
    $dirs[] = dirname((string)$rootPath) . '/private_uploads/hope/images/';

    $clean = [];
    foreach ($dirs as $dir) {
        $dir = rtrim(str_replace('\\', '/', (string)$dir), '/') . '/';
        if ($dir !== '/' && !in_array($dir, $clean, true)) $clean[] = $dir;
    }
    return $clean;
}

function media_find_file(string $filename): ?string {
    foreach (media_candidate_dirs() as $dir) {
        $candidate = $dir . $filename;
        $realDir = realpath($dir);
        $realFile = realpath($candidate);
        if (!$realDir || !$realFile) continue;
        $realDir = rtrim(str_replace('\\', '/', $realDir), '/') . '/';
        $realFileNorm = str_replace('\\', '/', $realFile);
        if (str_starts_with($realFileNorm, $realDir) && is_file($realFileNorm) && is_readable($realFileNorm)) {
            return $realFileNorm;
        }
    }
    return null;
}

$id = (int)($_GET['id'] ?? 0);
if ($id <= 0) {
    media_fail_image('ID produk tidak valid', 422);
}

$stmt = db()->prepare('SELECT id, image_path, updated_at FROM products WHERE id = ? LIMIT 1');
$stmt->execute([$id]);
$product = $stmt->fetch(PDO::FETCH_ASSOC);
if (!$product) {
    media_fail_image('Produk tidak ditemukan', 404);
}

$filename = media_safe_filename($product['image_path'] ?? '');
if ($filename === '') {
    media_fail_image('Produk belum memiliki gambar valid', 404);
}

$file = media_find_file($filename);
if (!$file) {
    media_fail_image('File gambar produk tidak ditemukan', 404);
}

$mime = function_exists('mime_content_type') ? mime_content_type($file) : '';
$allowed = [
    'image/jpeg' => 'jpg',
    'image/png' => 'png',
    'image/webp' => 'webp',
    'image/gif' => 'gif',
];
if (!isset($allowed[$mime])) {
    $ext = strtolower(pathinfo($file, PATHINFO_EXTENSION));
    $mimeByExt = [
        'jpg' => 'image/jpeg',
        'jpeg' => 'image/jpeg',
        'png' => 'image/png',
        'webp' => 'image/webp',
        'gif' => 'image/gif',
    ];
    $mime = $mimeByExt[$ext] ?? 'application/octet-stream';
}
if (!isset($allowed[$mime])) {
    media_fail_image('Tipe file gambar tidak didukung', 415);
}

$etag = '"product-' . $id . '-' . md5($filename . '|' . filesize($file) . '|' . filemtime($file)) . '"';
header('Content-Type: ' . $mime);
header('Content-Length: ' . filesize($file));
header('Cache-Control: private, max-age=86400');
header('ETag: ' . $etag);
header('X-Content-Type-Options: nosniff');
header('Content-Disposition: inline; filename="' . rawurlencode($filename) . '"');

if (trim((string)($_SERVER['HTTP_IF_NONE_MATCH'] ?? '')) === $etag) {
    http_response_code(304);
    exit;
}

readfile($file);
exit;
