<?php
// File contoh. Installer akan membuat config.php dari template ini.

return [
  'app' => [
    'name' => 'Hope Noodles Belitung',
    'base_url' => 'http://localhost/toko_online', // diset saat instalasi
    'version' => '1.0.0', // cache busting asset
  ],
  'db' => [
    'host' => '127.0.0.1',
    'port' => '3306',
    'name' => 'toko_online',
    'user' => 'root',
    'pass' => '',
    'charset' => 'utf8mb4',
  ],
  'security' => [
    'session_name' => 'TOKOSESS',
    'csp_enforce' => false, // legacy fallback (CSP_ENFORCE env lebih diprioritaskan)
  ],
];
