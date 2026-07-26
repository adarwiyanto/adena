<?php
require_once __DIR__ . '/../core/db.php';
require_once __DIR__ . '/../core/functions.php';
require_once __DIR__ . '/../core/security.php';
require_once __DIR__ . '/../core/auth.php';
require_once __DIR__ . '/../core/csrf.php';
require_once __DIR__ . '/../core/rbac.php';

start_secure_session();
require_login();
$me = require_menu_access('pos', 'view');
require_action_access('pos', 'create');
header('Content-Type: application/json; charset=utf-8');

try {
  if ($_SERVER['REQUEST_METHOD'] !== 'POST') throw new RuntimeException('Method tidak diizinkan.');
  $body = json_decode(file_get_contents('php://input') ?: '{}', true);
  if (!is_array($body)) throw new RuntimeException('Payload tidak valid.');
  $token = (string)($body['_csrf'] ?? '');
  if (!hash_equals((string)csrf_token(), $token)) throw new RuntimeException('CSRF tidak valid.');

  $rawCart = $body['cart'] ?? [];
  if (!is_array($rawCart)) throw new RuntimeException('Data keranjang tidak valid.');
  $cart = [];
  foreach ($rawCart as $pid => $qty) {
    $productId = (int)$pid;
    $quantity = min(9999, max(0, (int)$qty));
    if ($productId > 0 && $quantity > 0) $cart[$productId] = $quantity;
  }

  if ($cart) {
    $ids = array_keys($cart);
    $placeholders = implode(',', array_fill(0, count($ids), '?'));
    $stmt = db()->prepare("SELECT id FROM products WHERE show_on_pos=1 AND id IN ($placeholders)");
    $stmt->execute($ids);
    $valid = array_map('intval', array_column($stmt->fetchAll(), 'id'));
    $validSet = array_fill_keys($valid, true);
    $cart = array_filter($cart, fn($qty, $pid) => isset($validSet[(int)$pid]), ARRAY_FILTER_USE_BOTH);
  }

  $_SESSION['pos_cart'] = $cart;
  foreach (['pos_bypass_items', 'pos_item_discounts'] as $sessionKey) {
    $current = $_SESSION[$sessionKey] ?? [];
    if (is_array($current)) {
      $_SESSION[$sessionKey] = array_intersect_key($current, $cart);
    }
  }
  session_write_close();
  echo json_encode(['ok'=>true, 'count'=>array_sum($cart), 'synced_at'=>gmdate('c')]);
} catch (Throwable $e) {
  http_response_code(422);
  echo json_encode(['ok'=>false, 'error'=>$e->getMessage()]);
}
