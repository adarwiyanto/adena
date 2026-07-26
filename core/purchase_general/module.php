<?php
require_once __DIR__ . '/../db.php';
require_once __DIR__ . '/../functions.php';
require_once __DIR__ . '/../../config/upload.php';

/**
 * Pembelian Umum tetap memakai purchase_headers dan purchase_items legacy.
 * Modul ini hanya menerjemahkan field UI baru ke kolom lama agar endpoint API
 * pembelian tetap membaca transaksi tanpa perubahan kontrak.
 */

function gp_item_types(): array {
  return [
    'product' => ['label' => 'Produk / Barang', 'prefix' => '', 'requires_product' => true],
    'electricity' => ['label' => 'Listrik / PLN', 'prefix' => '[PLN]', 'requires_product' => false],
    'guide' => ['label' => 'Guide', 'prefix' => '[GUIDE]', 'requires_product' => false],
    'office_supplies' => ['label' => 'ATK', 'prefix' => '[ATK]', 'requires_product' => false],
    'kitchen_project' => ['label' => 'Proyek Dapur', 'prefix' => '[PROYEK DAPUR]', 'requires_product' => false],
    'service' => ['label' => 'Jasa', 'prefix' => '[JASA]', 'requires_product' => false],
    'transport' => ['label' => 'Transportasi', 'prefix' => '[TRANSPORTASI]', 'requires_product' => false],
    'maintenance' => ['label' => 'Perawatan / Perbaikan', 'prefix' => '[PERAWATAN]', 'requires_product' => false],
    'other' => ['label' => 'Lainnya', 'prefix' => '[LAINNYA]', 'requires_product' => false],
  ];
}

function gp_generate_purchase_no(PDO $db): string {
  $prefix = 'PG-' . date('Ymd-His');
  for ($i = 0; $i < 10; $i++) {
    $number = $prefix . '-' . strtoupper(bin2hex(random_bytes(2)));
    $stmt = $db->prepare('SELECT id FROM purchase_headers WHERE purchase_no=? LIMIT 1');
    $stmt->execute([$number]);
    if (!$stmt->fetch()) return $number;
  }
  return $prefix . '-' . strtoupper(bin2hex(random_bytes(4)));
}

function gp_suppliers(): array {
  $stmt = db()->query("SELECT id,supplier_code,supplier_name FROM suppliers WHERE is_active=1 AND supplier_code<>'GENERAL_EXPENSE_SYSTEM' ORDER BY supplier_name ASC");
  return $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
}

function gp_products(): array {
  $sql = "SELECT id,name,product_type,allow_direct_purchase,show_on_pos
          FROM products
          WHERE product_type IN ('finished_good','raw_material')
            AND (allow_direct_purchase=1 OR show_on_pos=1 OR allow_bom=1)
          ORDER BY allow_direct_purchase DESC, name ASC";
  $stmt = db()->query($sql);
  return $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
}

function gp_guides(): array {
  try {
    $stmt = db()->query('SELECT id,name FROM guides WHERE is_active=1 ORDER BY name ASC');
    return $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
  } catch (Throwable $e) {
    return [];
  }
}

function gp_system_supplier_id(PDO $db): int {
  $code = 'GENERAL_EXPENSE_SYSTEM';
  $stmt = $db->prepare('SELECT id FROM suppliers WHERE supplier_code=? LIMIT 1');
  $stmt->execute([$code]);
  $id = (int)($stmt->fetchColumn() ?: 0);
  if ($id > 0) return $id;

  try {
    $insert = $db->prepare("INSERT INTO suppliers (supplier_code,supplier_name,is_active) VALUES (?, 'Pengeluaran Umum (Sistem)', 0)");
    $insert->execute([$code]);
    return (int)$db->lastInsertId();
  } catch (Throwable $e) {
    $stmt->execute([$code]);
    $id = (int)($stmt->fetchColumn() ?: 0);
    if ($id > 0) return $id;
    throw new RuntimeException('Supplier teknis untuk pengeluaran umum tidak dapat dibuat.');
  }
}

function gp_validate_date(string $value): string {
  $date = DateTime::createFromFormat('Y-m-d', $value);
  if (!$date || $date->format('Y-m-d') !== $value) {
    throw new InvalidArgumentException('Tanggal pembelian tidak valid.');
  }
  return $value;
}

function gp_limit_text(string $value, int $max): string {
  $value = trim($value);
  if (function_exists('mb_substr')) return mb_substr($value, 0, $max, 'UTF-8');
  return substr($value, 0, $max);
}

function gp_note_lines(array $lines): string {
  $clean = [];
  foreach ($lines as $label => $value) {
    $value = trim((string)$value);
    if ($value === '') continue;
    $clean[] = $label . ': ' . preg_replace('/\s+/u', ' ', $value);
  }
  return gp_limit_text(implode(' | ', $clean), 255);
}

function gp_upload_bucket(array $files, string $key): array {
  if (!isset($files['name'][$key])) return [];

  $names = is_array($files['name'][$key]) ? $files['name'][$key] : [$files['name'][$key]];
  $tmpNames = is_array($files['tmp_name'][$key] ?? null) ? $files['tmp_name'][$key] : [$files['tmp_name'][$key] ?? ''];
  $errors = is_array($files['error'][$key] ?? null) ? $files['error'][$key] : [$files['error'][$key] ?? UPLOAD_ERR_NO_FILE];
  $sizes = is_array($files['size'][$key] ?? null) ? $files['size'][$key] : [$files['size'][$key] ?? 0];

  $allowed = [
    'image/jpeg' => ['jpg', 'jpeg'],
    'image/png' => ['png'],
    'image/webp' => ['webp'],
    'application/pdf' => ['pdf'],
  ];
  $maxSize = 8 * 1024 * 1024;
  $result = [];
  $finfo = new finfo(FILEINFO_MIME_TYPE);

  foreach ($names as $i => $name) {
    $error = (int)($errors[$i] ?? UPLOAD_ERR_NO_FILE);
    if ($error === UPLOAD_ERR_NO_FILE) continue;
    if ($error !== UPLOAD_ERR_OK) throw new RuntimeException('Salah satu bukti transaksi gagal diunggah.');

    $tmp = (string)($tmpNames[$i] ?? '');
    $size = (int)($sizes[$i] ?? 0);
    if ($tmp === '' || !is_uploaded_file($tmp)) throw new RuntimeException('File bukti transaksi tidak valid.');
    if ($size <= 0 || $size > $maxSize) throw new RuntimeException('Ukuran setiap bukti maksimal 8 MB.');

    $original = basename((string)$name);
    $ext = strtolower(pathinfo($original, PATHINFO_EXTENSION));
    $mime = (string)$finfo->file($tmp);
    if (!isset($allowed[$mime]) || !in_array($ext, $allowed[$mime], true)) {
      throw new RuntimeException('Bukti hanya boleh berupa JPG, JPEG, PNG, WEBP, atau PDF.');
    }

    $result[] = [
      'tmp_name' => $tmp,
      'original_name' => $original,
      'extension' => $ext,
      'mime' => $mime,
      'size' => $size,
    ];
  }

  if (count($result) > 5) throw new RuntimeException('Maksimal 5 bukti untuk setiap item.');
  return $result;
}

function gp_collect_items(array $rawItems, array $evidenceFiles, array $products, array $guides): array {
  if (!$rawItems) throw new InvalidArgumentException('Minimal satu item pembelian wajib diisi.');

  $types = gp_item_types();
  $productMap = [];
  foreach ($products as $product) $productMap[(int)$product['id']] = $product;
  $guideMap = [];
  foreach ($guides as $guide) $guideMap[(int)$guide['id']] = $guide;

  $items = [];
  foreach ($rawItems as $key => $raw) {
    if (!is_array($raw)) continue;
    $key = (string)$key;
    $type = (string)($raw['type'] ?? '');
    if (!isset($types[$type])) throw new InvalidArgumentException('Jenis pembelian tidak valid.');

    $description = trim((string)($raw['description'] ?? ''));
    $productId = (int)($raw['product_id'] ?? 0);
    $guideId = (int)($raw['guide_id'] ?? 0);
    $guideManual = trim((string)($raw['guide_name_manual'] ?? ''));
    $qty = parse_number_input($raw['qty'] ?? 0);
    $unitCost = parse_number_input($raw['unit_cost'] ?? 0);
    $period = trim((string)($raw['period'] ?? ''));
    $reference = trim((string)($raw['reference_no'] ?? ''));
    $paymentMethod = trim((string)($raw['payment_method'] ?? ''));
    $customerNo = trim((string)($raw['customer_no'] ?? ''));
    $note = trim((string)($raw['notes'] ?? ''));

    if ($qty <= 0) throw new InvalidArgumentException('Qty setiap item harus lebih dari 0.');
    if ($unitCost <= 0) throw new InvalidArgumentException('Harga atau nominal setiap item harus lebih dari 0.');

    $itemName = '';
    $notes = '';
    if ($type === 'product') {
      $product = $productMap[$productId] ?? null;
      if (!$product) throw new InvalidArgumentException('Produk wajib dipilih dan harus tersedia pada daftar pembelian.');
      $itemName = gp_limit_text((string)$product['name'], 190);
      $notes = gp_note_lines(['Jenis' => 'PRODUK', 'Keterangan' => $note]);
    } elseif ($type === 'guide') {
      $guideName = $guideId > 0 ? trim((string)($guideMap[$guideId]['name'] ?? '')) : '';
      if ($guideName === '') $guideName = $guideManual;
      if ($guideName === '') throw new InvalidArgumentException('Nama Guide wajib dipilih atau diisi.');
      $description = $description !== '' ? $description : 'Komisi Guide';
      $itemName = gp_limit_text($types[$type]['prefix'] . ' ' . $description . ' - ' . $guideName, 190);
      $notes = gp_note_lines([
        'Jenis' => 'GUIDE',
        'Guide' => $guideName,
        'Metode' => $paymentMethod,
        'Referensi' => $reference,
        'Keterangan' => $note,
      ]);
    } else {
      if ($description === '') {
        if ($type === 'electricity') {
          $description = 'Tagihan Listrik';
        } else {
          throw new InvalidArgumentException('Nama atau uraian item ' . $types[$type]['label'] . ' wajib diisi.');
        }
      }
      $itemName = gp_limit_text(trim($types[$type]['prefix'] . ' ' . $description), 190);
      $notes = gp_note_lines([
        'Jenis' => strtoupper($type),
        'Periode' => $period,
        'No Pelanggan' => $customerNo,
        'Metode' => $paymentMethod,
        'Referensi' => $reference,
        'Keterangan' => $note,
      ]);
    }

    $evidences = gp_upload_bucket($evidenceFiles, $key);
    if (!$evidences) throw new InvalidArgumentException('Setiap item wajib memiliki minimal satu bukti transaksi.');

    $items[] = [
      'source_key' => $key,
      'type' => $type,
      'product_id' => $type === 'product' ? $productId : null,
      'item_name' => $itemName,
      'qty' => $qty,
      'unit_cost' => $unitCost,
      'line_total' => round($qty * $unitCost, 2),
      'notes' => $notes !== '' ? $notes : null,
      'evidences' => $evidences,
    ];
  }

  if (!$items) throw new InvalidArgumentException('Minimal satu item pembelian wajib diisi.');
  return $items;
}

function gp_evidence_root(): string {
  return rtrim(UPLOAD_DOC, '/\\') . DIRECTORY_SEPARATOR . 'purchase_general';
}

function gp_evidence_item_dir(int $purchaseId, int $itemId): string {
  return gp_evidence_root() . DIRECTORY_SEPARATOR . 'purchase-' . $purchaseId . DIRECTORY_SEPARATOR . 'item-' . $itemId;
}

function gp_safe_original_name(string $name, string $extension): string {
  $base = pathinfo(basename($name), PATHINFO_FILENAME);
  $base = preg_replace('/[^A-Za-z0-9._-]+/', '-', $base) ?: 'bukti';
  $base = trim($base, '.-_');
  if ($base === '') $base = 'bukti';
  return substr($base, 0, 80) . '.' . $extension;
}

function gp_store_evidences(int $purchaseId, int $itemId, array $files): array {
  $dir = gp_evidence_item_dir($purchaseId, $itemId);
  if (!is_dir($dir) && !@mkdir($dir, 0750, true) && !is_dir($dir)) {
    throw new RuntimeException('Folder bukti transaksi tidak dapat dibuat.');
  }

  $stored = [];
  try {
    foreach ($files as $file) {
      $safeName = gp_safe_original_name((string)$file['original_name'], (string)$file['extension']);
      $filename = bin2hex(random_bytes(10)) . '__' . $safeName;
      $destination = $dir . DIRECTORY_SEPARATOR . $filename;
      if (!move_uploaded_file((string)$file['tmp_name'], $destination)) {
        throw new RuntimeException('Bukti transaksi gagal disimpan.');
      }
      @chmod($destination, 0640);
      $stored[] = $destination;
    }
    return $stored;
  } catch (Throwable $e) {
    gp_cleanup_paths($stored);
    throw $e;
  }
}

function gp_cleanup_paths(array $paths): void {
  foreach ($paths as $path) {
    if (is_file($path)) @unlink($path);
  }
  $dirs = [];
  foreach ($paths as $path) $dirs[dirname($path)] = true;
  foreach (array_keys($dirs) as $dir) {
    if (is_dir($dir)) @rmdir($dir);
    $purchaseDir = dirname($dir);
    if (is_dir($purchaseDir)) @rmdir($purchaseDir);
  }
}

function gp_evidence_files(int $purchaseId, int $itemId): array {
  $dir = gp_evidence_item_dir($purchaseId, $itemId);
  if (!is_dir($dir)) return [];
  $files = [];
  foreach (scandir($dir) ?: [] as $filename) {
    if ($filename === '.' || $filename === '..') continue;
    $path = $dir . DIRECTORY_SEPARATOR . $filename;
    if (!is_file($path)) continue;
    $display = strpos($filename, '__') !== false ? substr($filename, strpos($filename, '__') + 2) : $filename;
    $files[] = [
      'filename' => $filename,
      'display_name' => $display,
      'size' => (int)filesize($path),
      'url' => base_url('admin/purchase_general_evidence.php?purchase_id=' . $purchaseId . '&item_id=' . $itemId . '&file=' . rawurlencode($filename)),
    ];
  }
  usort($files, static fn(array $a, array $b): int => strcmp($a['display_name'], $b['display_name']));
  return $files;
}

function gp_detect_item_type(array $item): string {
  if ((int)($item['product_id'] ?? 0) > 0) return 'Produk / Barang';
  $name = strtoupper(trim((string)($item['item_name'] ?? '')));
  foreach (gp_item_types() as $key => $definition) {
    $prefix = strtoupper((string)$definition['prefix']);
    if ($prefix !== '' && str_starts_with($name, $prefix)) return (string)$definition['label'];
  }
  return 'Lainnya';
}

function gp_default_form_item(): array {
  return [
    'type' => 'product',
    'product_id' => '',
    'guide_id' => '',
    'guide_name_manual' => '',
    'description' => '',
    'qty' => '1',
    'unit_cost' => '',
    'period' => '',
    'customer_no' => '',
    'payment_method' => '',
    'reference_no' => '',
    'notes' => '',
  ];
}
