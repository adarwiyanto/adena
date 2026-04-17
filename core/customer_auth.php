<?php
require_once __DIR__ . '/db.php';
require_once __DIR__ . '/functions.php';
require_once __DIR__ . '/auth.php';
require_once __DIR__ . '/security.php';

function customer_cookie_name(): string {
  return 'HOPE_CUSTOMER_TOKEN';
}

function customer_current(): ?array {
  start_session();
  return $_SESSION['customer'] ?? null;
}

function customer_sync_session(array $customer): void {
  start_session();
  unset($customer['password_hash']);
  $_SESSION['customer'] = $customer;
}

function customer_create_session(array $customer): void {
  start_session();
  session_regenerate_id(true);
  $token = bin2hex(random_bytes(32));
  $tokenHash = hash('sha256', $token);
  $expiresAt = (new DateTimeImmutable('+365 days'))->format('Y-m-d H:i:s');

  $stmt = db()->prepare("INSERT INTO customer_sessions (customer_id, token_hash, expires_at) VALUES (?,?,?)");
  $stmt->execute([(int)$customer['id'], $tokenHash, $expiresAt]);

  $secure = !empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off';
  setcookie(customer_cookie_name(), $token, [
    'expires' => time() + 365 * 24 * 60 * 60,
    'path' => '/',
    'secure' => $secure,
    'httponly' => true,
    'samesite' => 'Lax',
  ]);

  customer_sync_session($customer);
}

function customer_bootstrap_from_cookie(): void {
  start_session();
  if (!empty($_SESSION['customer'])) {
    return;
  }
  $token = $_COOKIE[customer_cookie_name()] ?? '';
  if ($token === '') {
    return;
  }
  $tokenHash = hash('sha256', $token);
  $stmt = db()->prepare("
    SELECT cs.id AS session_id, c.*
    FROM customer_sessions cs
    JOIN customers c ON c.id = cs.customer_id
    WHERE cs.token_hash = ? AND (cs.expires_at IS NULL OR cs.expires_at > NOW())
    LIMIT 1
  ");
  $stmt->execute([$tokenHash]);
  $row = $stmt->fetch();
  if (!$row) {
    return;
  }
  $stmt = db()->prepare("UPDATE customer_sessions SET last_used_at=NOW() WHERE id=?");
  $stmt->execute([(int)$row['session_id']]);
  unset($row['session_id']);
  customer_sync_session($row);
}

function customer_login(string $phone, string $password): bool {
  $stmt = db()->prepare("SELECT * FROM customers WHERE phone = ? LIMIT 1");
  $stmt->execute([$phone]);
  $customer = $stmt->fetch();
  if (!$customer) {
    return false;
  }
  $hash = (string)($customer['password_hash'] ?? '');
  if ($hash === '') {
    return false;
  }
  $verified = password_verify($password, $hash);
  if (!$verified) {
    $legacyMatch = false;
    if (strlen($hash) === 32 && hash_equals($hash, md5($password))) {
      $legacyMatch = true;
    } elseif (strlen($hash) === 40 && hash_equals($hash, sha1($password))) {
      $legacyMatch = true;
    } elseif (hash_equals($hash, $password)) {
      $legacyMatch = true;
    }
    if (!$legacyMatch) {
      return false;
    }
  }
  if ($verified && password_needs_rehash($hash, PASSWORD_DEFAULT)) {
    $newHash = password_hash($password, PASSWORD_DEFAULT);
    $stmt = db()->prepare("UPDATE customers SET password_hash=? WHERE id=?");
    $stmt->execute([$newHash, (int)$customer['id']]);
  }
  if (!$verified) {
    $newHash = password_hash($password, PASSWORD_DEFAULT);
    $stmt = db()->prepare("UPDATE customers SET password_hash=? WHERE id=?");
    $stmt->execute([$newHash, (int)$customer['id']]);
  }
  customer_create_session($customer);
  return true;
}

function customer_logout(): void {
  start_session();
  $token = $_COOKIE[customer_cookie_name()] ?? '';
  if ($token !== '') {
    $tokenHash = hash('sha256', $token);
    $stmt = db()->prepare("DELETE FROM customer_sessions WHERE token_hash = ?");
    $stmt->execute([$tokenHash]);
    setcookie(customer_cookie_name(), '', [
      'expires' => time() - 3600,
      'path' => '/',
    ]);
  }
  unset($_SESSION['customer']);
}
