<?php
require_once __DIR__ . '/db.php';

function normalize_username(string $username): string {
  return trim($username);
}

function is_valid_username_format(string $username): bool {
  return (bool)preg_match('/^[A-Za-z0-9_.-]+$/', $username);
}

function customer_find_by_username(string $username): ?array {
  $username = normalize_username($username);
  if ($username === '') return null;
  $stmt = db()->prepare("SELECT * FROM customers WHERE username = ? LIMIT 1");
  $stmt->execute([$username]);
  $customer = $stmt->fetch();
  return $customer ?: null;
}

function username_exists_anywhere(string $username, ?string $ignoreType = null, ?int $ignoreId = null): bool {
  $username = normalize_username($username);
  if ($username === '') return false;

  if ($ignoreType !== 'user') {
    $stmt = db()->prepare("SELECT id FROM users WHERE username = ? LIMIT 1");
    $stmt->execute([$username]);
    $row = $stmt->fetch();
    if ($row) return true;
  } else {
    $stmt = db()->prepare("SELECT id FROM users WHERE username = ? AND id <> ? LIMIT 1");
    $stmt->execute([$username, (int)$ignoreId]);
    if ($stmt->fetch()) return true;
  }

  if ($ignoreType !== 'customer') {
    $stmt = db()->prepare("SELECT id FROM customers WHERE username = ? LIMIT 1");
    $stmt->execute([$username]);
    if ($stmt->fetch()) return true;
  } else {
    $stmt = db()->prepare("SELECT id FROM customers WHERE username = ? AND id <> ? LIMIT 1");
    $stmt->execute([$username, (int)$ignoreId]);
    if ($stmt->fetch()) return true;
  }

  return false;
}

function find_account_by_username(string $username): ?array {
  $username = normalize_username($username);
  if ($username === '') return null;

  $stmt = db()->prepare("SELECT * FROM users WHERE username = ? LIMIT 1");
  $stmt->execute([$username]);
  $user = $stmt->fetch();
  if ($user) {
    return ['type' => 'user', 'data' => $user];
  }

  $customer = customer_find_by_username($username);
  if ($customer) {
    return ['type' => 'customer', 'data' => $customer];
  }
  return null;
}

function customer_slug_username_seed(string $value): string {
  $value = strtolower(trim($value));
  $value = preg_replace('/[^a-z0-9_.-]+/i', '.', $value);
  $value = preg_replace('/[.]{2,}/', '.', (string)$value);
  $value = trim((string)$value, '._-');
  return $value !== '' ? $value : 'customer';
}

function customer_build_unique_username(string $seed, int $ignoreCustomerId = 0): string {
  $seed = customer_slug_username_seed($seed);
  $candidate = $seed;
  $suffix = 1;
  while (username_exists_anywhere($candidate, 'customer', $ignoreCustomerId > 0 ? $ignoreCustomerId : null)) {
    $candidate = $seed . $suffix;
    $suffix++;
  }
  return $candidate;
}

function customer_generate_username_from_row(array $customer): string {
  $email = trim((string)($customer['email'] ?? ''));
  if ($email !== '' && strpos($email, '@') !== false) {
    $localPart = explode('@', $email, 2)[0];
    $seed = customer_slug_username_seed($localPart);
    if ($seed !== 'customer' && !username_exists_anywhere($seed, 'customer', (int)($customer['id'] ?? 0))) {
      return $seed;
    }
  }
  $nameSeed = customer_slug_username_seed((string)($customer['name'] ?? 'customer'));
  return customer_build_unique_username($nameSeed, (int)($customer['id'] ?? 0));
}

function customer_backfill_usernames(): int {
  $stmt = db()->query("SELECT id, name, email FROM customers WHERE username IS NULL OR TRIM(username) = '' ORDER BY id ASC");
  $rows = $stmt->fetchAll();
  if (!$rows) return 0;

  $updated = 0;
  $update = db()->prepare("UPDATE customers SET username = ? WHERE id = ?");
  foreach ($rows as $row) {
    $username = customer_generate_username_from_row($row);
    $update->execute([$username, (int)$row['id']]);
    $updated++;
  }
  return $updated;
}
