<?php
require_once __DIR__ . '/db.php';
require_once __DIR__ . '/functions.php';
require_once __DIR__ . '/security.php';
require_once __DIR__ . '/rbac.php';

const REMEMBER_LOGIN_LIFETIME = 315360000;

function remember_cookie_name(): string {
  $cfg = app_config();
  return (string)($cfg['security']['remember_cookie_name'] ?? 'HOPE_REMEMBER');
}

function remember_cookie_secure(): bool {
  return (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off')
    || (($_SERVER['HTTP_X_FORWARDED_PROTO'] ?? '') === 'https');
}

function ensure_remember_login_table(): void {
  db()->exec("CREATE TABLE IF NOT EXISTS user_remember_tokens (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    user_id BIGINT NOT NULL,
    selector CHAR(24) NOT NULL,
    validator_hash CHAR(64) NOT NULL,
    password_fingerprint CHAR(64) NOT NULL,
    expires_at DATETIME NOT NULL,
    last_used_at DATETIME NULL,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    UNIQUE KEY uq_user_remember_selector (selector),
    KEY idx_user_remember_user (user_id),
    KEY idx_user_remember_expires (expires_at)
  ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
}

function remember_set_cookie(string $value, int $expires): void {
  if (headers_sent()) return;
  setcookie(remember_cookie_name(), $value, [
    'expires' => $expires,
    'path' => '/',
    'secure' => remember_cookie_secure(),
    'httponly' => true,
    'samesite' => 'Lax',
  ]);
}

function remember_forget_current_device(): void {
  $raw = (string)($_COOKIE[remember_cookie_name()] ?? '');
  $parts = explode(':', $raw, 2);
  if (count($parts) === 2 && preg_match('/^[a-f0-9]{24}$/', $parts[0])) {
    try {
      ensure_remember_login_table();
      $stmt = db()->prepare("DELETE FROM user_remember_tokens WHERE selector=?");
      $stmt->execute([$parts[0]]);
    } catch (Throwable $e) {}
  }
  unset($_COOKIE[remember_cookie_name()]);
  remember_set_cookie('', time() - 42000);
}

function remember_issue_for_user(int $userId, string $passwordHash): void {
  remember_forget_current_device();
  ensure_remember_login_table();
  try { db()->exec("DELETE FROM user_remember_tokens WHERE expires_at <= NOW()"); } catch (Throwable $e) {}

  $selector = bin2hex(random_bytes(12));
  $validator = bin2hex(random_bytes(32));
  $expires = time() + REMEMBER_LOGIN_LIFETIME;
  $stmt = db()->prepare("INSERT INTO user_remember_tokens
    (user_id, selector, validator_hash, password_fingerprint, expires_at)
    VALUES (?, ?, ?, ?, ?)");
  $stmt->execute([
    $userId,
    $selector,
    hash('sha256', $validator),
    hash('sha256', $passwordHash),
    date('Y-m-d H:i:s', $expires),
  ]);
  remember_set_cookie($selector . ':' . $validator, $expires);
}

function remember_restore_user(): ?array {
  static $attempted = false;
  if ($attempted) return null;
  $attempted = true;

  $raw = (string)($_COOKIE[remember_cookie_name()] ?? '');
  $parts = explode(':', $raw, 2);
  if (count($parts) !== 2
      || !preg_match('/^[a-f0-9]{24}$/', $parts[0])
      || !preg_match('/^[a-f0-9]{64}$/', $parts[1])) {
    if ($raw !== '') remember_forget_current_device();
    return null;
  }

  try {
    ensure_remember_login_table();
    ensure_rbac_schema();
    $stmt = db()->prepare("SELECT t.id AS token_id, t.validator_hash, t.password_fingerprint,
      u.id, u.username, u.name, u.role, u.role_id, u.email, u.avatar_path, u.password_hash,
      r.role_key, r.role_name
      FROM user_remember_tokens t
      JOIN users u ON u.id=t.user_id
      LEFT JOIN roles r ON r.id=u.role_id
      WHERE t.selector=? AND t.expires_at > NOW()
      LIMIT 1");
    $stmt->execute([$parts[0]]);
    $u = $stmt->fetch();
    $valid = $u
      && hash_equals((string)$u['validator_hash'], hash('sha256', $parts[1]))
      && hash_equals((string)$u['password_fingerprint'], hash('sha256', (string)$u['password_hash']));
    if (!$valid) {
      remember_forget_current_device();
      return null;
    }

    $resolved = resolve_user_role($u);
    if ((int)($resolved['role_id'] ?? 0) <= 0) {
      remember_forget_current_device();
      return null;
    }
    $tokenId = (int)$u['token_id'];
    unset($u['token_id'], $u['validator_hash'], $u['password_fingerprint'], $u['password_hash']);
    $u['role_id'] = (int)$resolved['role_id'];
    $u['role'] = (string)$resolved['role_key'];
    $u['role_key'] = (string)$resolved['role_key'];
    $u['role_name'] = (string)$resolved['role_name'];

    $expires = time() + REMEMBER_LOGIN_LIFETIME;
    $update = db()->prepare("UPDATE user_remember_tokens SET expires_at=?, last_used_at=NOW() WHERE id=?");
    $update->execute([date('Y-m-d H:i:s', $expires), $tokenId]);
    remember_set_cookie($raw, $expires);
    session_regenerate_id(true);
    $_SESSION['user'] = $u;
    return $u;
  } catch (Throwable $e) {
    return null;
  }
}

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
  ensure_rbac_schema();

  $u = current_user() ?? [];
  $resolved = resolve_user_role($u);
  if ((int)($resolved['role_id'] ?? 0) <= 0 || (string)($resolved['role_key'] ?? '') === '') {
    logout();
    redirect(base_url('adm.php'));
  }

  $_SESSION['user']['role_id'] = (int)$resolved['role_id'];
  $_SESSION['user']['role'] = (string)$resolved['role_key'];
  $_SESSION['user']['role_key'] = (string)$resolved['role_key'];
  $_SESSION['user']['role_name'] = (string)$resolved['role_name'];
}

function current_user(): ?array {
  start_session();
  if (empty($_SESSION['user'])) {
    remember_restore_user();
  }
  return $_SESSION['user'] ?? null;
}

function login_attempt(string $username, string $password, bool $remember = false): bool {
  ensure_user_profile_columns();
  ensure_rbac_schema();
  $stmt = db()->prepare("
    SELECT u.id, u.username, u.name, u.role, u.role_id, u.email, u.avatar_path, u.password_hash,
           r.role_key, r.role_name
    FROM users u
    LEFT JOIN roles r ON r.id = u.role_id
    WHERE u.username=?
    LIMIT 1
  ");
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
    $hash = $newHash;
  }
  if (!$verified) {
    $newHash = password_hash($password, PASSWORD_DEFAULT);
    $stmt = db()->prepare("UPDATE users SET password_hash=? WHERE id=?");
    $stmt->execute([$newHash, (int)$u['id']]);
    $hash = $newHash;
  }
  unset($u['password_hash']);
  $resolved = resolve_user_role($u);
  if ((int)$resolved['role_id'] <= 0) {
    return false;
  }
  $u['role_id'] = (int)$resolved['role_id'];
  $u['role'] = (string)$resolved['role_key'];
  $u['role_key'] = (string)$resolved['role_key'];
  $u['role_name'] = (string)$resolved['role_name'];
  try {
    $stmt = db()->prepare("UPDATE users SET role_id=?, role=? WHERE id=?");
    $stmt->execute([(int)$u['role_id'], (string)$u['role'], (int)$u['id']]);
  } catch (Throwable $e) {}
  $_SESSION['user'] = $u;
  if ($remember) {
    remember_issue_for_user((int)$u['id'], $hash);
  } else {
    remember_forget_current_device();
  }
  login_clear_failed_attempts();
  return true;
}

function logout(): void {
  start_session();
  remember_forget_current_device();
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
