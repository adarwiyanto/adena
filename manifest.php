<?php
require_once __DIR__ . '/core/db.php';
require_once __DIR__ . '/core/functions.php';

$appName = app_config()['app']['name'];
$storeName = setting('store_name', $appName);
$iconUrl = favicon_url();

$manifest = [
  'name' => $storeName,
  'short_name' => $storeName,
  'start_url' => base_url(),
  'display' => 'standalone',
  'background_color' => '#ffffff',
  'theme_color' => '#0a5ea7',
  'icons' => [
    [
      'src' => $iconUrl,
      'sizes' => '192x192',
      'purpose' => 'any maskable',
    ],
    [
      'src' => $iconUrl,
      'sizes' => '512x512',
      'purpose' => 'any maskable',
    ],
  ],
];

header('Content-Type: application/manifest+json');
echo json_encode($manifest, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
