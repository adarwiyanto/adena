<?php
require_once __DIR__ . '/db.php';
require_once __DIR__ . '/functions.php';
require_once __DIR__ . '/security.php';

function start_session(): void {
  start_secure_session();
}

function require_login(): void {
  start_session();
  if (empty($_SESSION['user'])) {
    redirect(base_url('adm.php'));
  }
}

function require_admin(): void {
  require_login();
  ensure_owner_role();
  $u = current_user();
  if (($u['role'] ?? '') === 'pegawai') {
    redirect(base_url('pos/index.php'));
  }
  if (!in_array($u['role'] ?? '', ['admin', 'owner', 'superadmin'], true)) {
    http_response_code(403);
    exit('Forbidden');
  }
}

function current_user(): ?array {
  start_session();
  return $_SESSION['user'] ?? null;
}

function login_attempt(string $username, string $password): bool {
  ensure_user_profile_columns();
  $stmt = db()->prepare("SELECT id, username, name, role, email, avatar_path, password_hash FROM users WHERE username=? LIMIT 1");
  $stmt->execute([$username]);
  $u = $stmt->fetch();
  if (!$u) return false;
  $hash = (string)$u['password_hash'];
  $verified = password_verify($password, $hash);
  if (!$verified) {
    $legacyMatch = false;
    if ($hash !== '') {
      if (strlen($hash) === 32 && hash_equals($hash, md5($password))) {
        $legacyMatch = true;
      } elseif (strlen($hash) === 40 && hash_equals($hash, sha1($password))) {
        $legacyMatch = true;
      } elseif (hash_equals($hash, $password)) {
        $legacyMatch = true;
      }
    }
    if (!$legacyMatch) {
      return false;
    }
  }

  start_session();
  session_regenerate_id(true);
  if ($verified && password_needs_rehash($hash, PASSWORD_DEFAULT)) {
    $newHash = password_hash($password, PASSWORD_DEFAULT);
    $stmt = db()->prepare("UPDATE users SET password_hash=? WHERE id=?");
    $stmt->execute([$newHash, (int)$u['id']]);
  }
  if (!$verified) {
    $newHash = password_hash($password, PASSWORD_DEFAULT);
    $stmt = db()->prepare("UPDATE users SET password_hash=? WHERE id=?");
    $stmt->execute([$newHash, (int)$u['id']]);
  }
  unset($u['password_hash']);
  if (($u['role'] ?? '') === 'superadmin') {
    $u['role'] = 'owner';
  }
  $_SESSION['user'] = $u;
  login_clear_failed_attempts();
  return true;
}

function logout(): void {
  start_session();
  $_SESSION = [];
  session_destroy();
}

function login_attempt_key(): string {
  $ip = $_SERVER['REMOTE_ADDR'] ?? 'unknown';
  $ua = $_SERVER['HTTP_USER_AGENT'] ?? 'unknown';
  return hash('sha256', $ip . '|' . $ua);
}

function login_attempt_store_path(): string {
  return sys_get_temp_dir() . '/hope_login_attempts.json';
}

function login_attempt_store_ttl(): int {
  return 900;
}

function login_read_attempts(): array {
  $path = login_attempt_store_path();
  if (!is_file($path)) {
    return [];
  }
  $raw = file_get_contents($path);
  if ($raw === false || $raw === '') {
    return [];
  }
  $data = json_decode($raw, true);
  if (!is_array($data)) {
    return [];
  }
  $now = time();
  $ttl = login_attempt_store_ttl();
  foreach ($data as $key => $info) {
    $last = (int)($info['last'] ?? 0);
    if ($now - $last > $ttl) {
      unset($data[$key]);
    }
  }
  return $data;
}

function login_write_attempts(array $data): void {
  $path = login_attempt_store_path();
  @file_put_contents($path, json_encode($data), LOCK_EX);
}

function login_failed_attempts(): int {
  $data = login_read_attempts();
  $key = login_attempt_key();
  if (!isset($data[$key])) {
    return 0;
  }
  return (int)($data[$key]['count'] ?? 0);
}

function login_record_failed_attempt(): int {
  $data = login_read_attempts();
  $key = login_attempt_key();
  $count = (int)($data[$key]['count'] ?? 0);
  $count++;
  $data[$key] = [
    'count' => $count,
    'last' => time(),
  ];
  login_write_attempts($data);
  return $count;
}

function login_clear_failed_attempts(): void {
  $data = login_read_attempts();
  $key = login_attempt_key();
  if (isset($data[$key])) {
    unset($data[$key]);
    login_write_attempts($data);
  }
}

function login_should_recover(): bool {
  return login_failed_attempts() >= 3;
}
