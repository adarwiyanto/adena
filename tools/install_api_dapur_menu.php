<?php
/**
 * Installer kecil untuk menambahkan menu Admin > Setting API Dapur.
 * Jalankan satu kali dari root website toko:
 * php tools/install_api_dapur_menu.php
 * atau buka via browser lalu hapus file ini setelah selesai.
 */
$root = dirname(__DIR__);
$sidebar = $root . '/admin/partials_sidebar.php';
$snippet = "          <?php if (has_menu_access(\$u, 'settings')): ?><a href=\"<?php echo e(base_url('admin/api_dapur.php')); ?>\">Setting API Dapur</a><?php endif; ?>";

header('Content-Type: text/plain; charset=utf-8');

if (!is_file($sidebar)) {
  http_response_code(500);
  echo "ERROR: admin/partials_sidebar.php tidak ditemukan.\n";
  exit;
}

$src = file_get_contents($sidebar);
if ($src === false) {
  http_response_code(500);
  echo "ERROR: gagal membaca admin/partials_sidebar.php.\n";
  exit;
}

if (strpos($src, 'admin/api_dapur.php') !== false) {
  echo "OK: menu Setting API Dapur sudah ada. Tidak ada perubahan.\n";
  exit;
}

$targets = [
  "          <?php if (has_menu_access(\$u, 'settings')): ?><a href=\"<?php echo e(base_url('admin/api_desktop.php')); ?>\">Kasir Desktop</a><?php endif; ?>",
  "          <?php if (current_user_is_owner()): ?>\n            <a href=\"<?php echo e(base_url('admin/backup.php')); ?>\">Backup Database</a>\n          <?php endif; ?>",
];

$new = null;
foreach ($targets as $target) {
  $pos = strpos($src, $target);
  if ($pos !== false) {
    $new = substr($src, 0, $pos) . $snippet . "\n" . substr($src, $pos);
    break;
  }
}

if ($new === null) {
  $needle = "        </div>\n      </div>\n    <?php endif; ?>";
  $pos = strpos($src, $needle);
  if ($pos !== false) {
    $new = substr($src, 0, $pos) . $snippet . "\n" . substr($src, $pos);
  }
}

if ($new === null) {
  http_response_code(500);
  echo "ERROR: titik sisip submenu Admin tidak ditemukan. Tambahkan manual snippet dari docs/SIDEBAR_SNIPPET.txt.\n";
  exit;
}

$backup = $sidebar . '.bak_api_dapur_' . date('Ymd_His');
if (!copy($sidebar, $backup)) {
  http_response_code(500);
  echo "ERROR: gagal membuat backup sidebar.\n";
  exit;
}

if (file_put_contents($sidebar, $new) === false) {
  copy($backup, $sidebar);
  http_response_code(500);
  echo "ERROR: gagal menulis sidebar. Backup dikembalikan.\n";
  exit;
}

echo "OK: menu Admin > Setting API Dapur berhasil ditambahkan.\n";
echo "Backup: " . basename($backup) . "\n";
echo "Catatan: hapus file tools/install_api_dapur_menu.php setelah selesai.\n";
