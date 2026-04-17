<?php
require_once __DIR__ . '/../config/upload.php';

function upload_secure(array $file, string $type = 'image'): array {
  if (!isset($file['error']) || $file['error'] !== UPLOAD_ERR_OK) {
    return ['ok' => false, 'error' => 'Upload gagal.'];
  }

  $type = $type === 'doc' ? 'doc' : 'image';
  $maxSize = $type === 'doc' ? 5 * 1024 * 1024 : 2 * 1024 * 1024;
  $allowedExt = $type === 'doc' ? ['pdf'] : ['jpg', 'jpeg', 'png'];
  $allowedMime = $type === 'doc'
    ? ['application/pdf']
    : ['image/jpeg', 'image/png'];

  $size = (int)($file['size'] ?? 0);
  if ($size <= 0 || $size > $maxSize) {
    return ['ok' => false, 'error' => 'Ukuran file tidak valid.'];
  }

  $name = (string)($file['name'] ?? '');
  $ext = strtolower(pathinfo($name, PATHINFO_EXTENSION));
  if (!in_array($ext, $allowedExt, true)) {
    return ['ok' => false, 'error' => 'Format file tidak diizinkan.'];
  }

  $tmp = (string)($file['tmp_name'] ?? '');
  if ($tmp === '' || !is_uploaded_file($tmp)) {
    return ['ok' => false, 'error' => 'File upload tidak valid.'];
  }

  $finfo = new finfo(FILEINFO_MIME_TYPE);
  $mime = $finfo->file($tmp);
  if (!in_array($mime, $allowedMime, true)) {
    return ['ok' => false, 'error' => 'MIME file tidak valid.'];
  }

  $newName = bin2hex(random_bytes(16)) . '.' . $ext;
  $targetDir = $type === 'doc' ? UPLOAD_DOC : UPLOAD_IMG;
  $dest = $targetDir . $newName;

  if (!move_uploaded_file($tmp, $dest)) {
    return ['ok' => false, 'error' => 'Gagal menyimpan file upload.'];
  }

  return [
    'ok' => true,
    'name' => $newName,
    'path' => $dest,
    'mime' => $mime,
    'ext' => $ext,
    'size' => $size,
  ];
}

function upload_secure_delete(string $filename, string $type = 'image'): void {
  if ($filename === '') {
    return;
  }
  $type = $type === 'doc' ? 'doc' : 'image';
  $baseDir = $type === 'doc' ? UPLOAD_DOC : UPLOAD_IMG;
  $path = $baseDir . basename($filename);
  if (is_file($path)) {
    @unlink($path);
  }
}
