<?php
require_once __DIR__ . '/../core/db.php';
require_once __DIR__ . '/../core/functions.php';
require_once __DIR__ . '/../core/security.php';
require_once __DIR__ . '/../core/auth.php';
require_once __DIR__ . '/../core/rbac.php';
require_once __DIR__ . '/../core/purchase_general/module.php';

start_secure_session();
require_admin();
ensure_rbac_schema();
require_menu_access('purchase', 'view');

$purchaseId = (int)($_GET['purchase_id'] ?? 0);
$itemId = (int)($_GET['item_id'] ?? 0);
$filename = basename((string)($_GET['file'] ?? ''));
if ($purchaseId <= 0 || $itemId <= 0 || $filename === '') {
  http_response_code(400);
  exit('Permintaan bukti tidak valid.');
}

$stmt = db()->prepare("SELECT pi.id
  FROM purchase_items pi
  INNER JOIN purchase_headers ph ON ph.id=pi.purchase_id
  WHERE pi.id=? AND pi.purchase_id=? AND ph.purchase_type='general' AND ph.purchase_no LIKE 'PG-%'
  LIMIT 1");
$stmt->execute([$itemId, $purchaseId]);
if (!$stmt->fetchColumn()) {
  http_response_code(404);
  exit('Bukti tidak ditemukan.');
}

$dir = realpath(gp_evidence_item_dir($purchaseId, $itemId));
$path = $dir ? realpath($dir . DIRECTORY_SEPARATOR . $filename) : false;
if (!$dir || !$path || !is_file($path) || strpos($path, $dir . DIRECTORY_SEPARATOR) !== 0) {
  http_response_code(404);
  exit('Bukti tidak ditemukan.');
}

$finfo = new finfo(FILEINFO_MIME_TYPE);
$mime = (string)$finfo->file($path);
$allowed = ['image/jpeg', 'image/png', 'image/webp', 'application/pdf'];
if (!in_array($mime, $allowed, true)) {
  http_response_code(415);
  exit('Format bukti tidak didukung.');
}

$displayName = strpos($filename, '__') !== false ? substr($filename, strpos($filename, '__') + 2) : $filename;
header('Content-Type: ' . $mime);
header('Content-Length: ' . filesize($path));
header('Content-Disposition: inline; filename="' . str_replace('"', '', $displayName) . '"');
header('X-Content-Type-Options: nosniff');
header('Cache-Control: private, max-age=300');
readfile($path);
