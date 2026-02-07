<?php
function app_config(): array {
  static $cfg = null;
  if ($cfg !== null) return $cfg;

  $path = __DIR__ . '/../config.php';
  if (!file_exists($path)) {
    // Jika belum diinstal, arahkan ke installer.
    header('Location: install/index.php');
    exit;
  }
  $cfg = require $path;
  return $cfg;
}

function db(): PDO {
  static $pdo = null;
  if ($pdo !== null) return $pdo;

  $cfg = app_config()['db'];
  $dsn = "mysql:host={$cfg['host']};port={$cfg['port']};dbname={$cfg['name']};charset={$cfg['charset']}";
  $pdo = new PDO($dsn, $cfg['user'], $cfg['pass'], [
    PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
    PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
  ]);
  try {
    $pdo->exec("SET time_zone = '+07:00'");
  } catch (Throwable $e) {
    // Abaikan jika server DB tidak mengizinkan set timezone.
  }
  return $pdo;
}
