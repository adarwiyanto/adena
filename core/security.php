<?php
require_once __DIR__ . '/functions.php';

function app_version(): string {
  $cfg = app_config();
  $configured = trim((string)($cfg['app']['version'] ?? ''));
  if ($configured !== '') {
    return $configured;
  }
  return (string)($_SERVER['REQUEST_TIME'] ?? time());
}

function csp_nonce(): string {
  static $nonce = null;
  if ($nonce === null) {
    $nonce = base64_encode(random_bytes(16));
  }
  return $nonce;
}

function send_csp_header(): void {
  static $sent = false;
  if ($sent || headers_sent()) {
    return;
  }

  $nonce = csp_nonce();
  $policy = [
    "default-src 'self'",
    "base-uri 'self'",
    "object-src 'none'",
    "frame-ancestors 'none'",
    "script-src 'self' 'nonce-{$nonce}' https://www.google.com/recaptcha/ https://www.gstatic.com/recaptcha/",
    "frame-src https://www.google.com/recaptcha/ https://recaptcha.google.com/recaptcha/",
    "style-src 'self' 'unsafe-inline'",
    "img-src 'self' data: https:",
    "font-src 'self' data:",
    "connect-src 'self' https://www.google.com/recaptcha/ https://www.gstatic.com/recaptcha/",
    "form-action 'self'",
    'upgrade-insecure-requests',
  ];
  $value = implode('; ', $policy);

  header_remove('Content-Security-Policy-Report-Only');
  header('Content-Security-Policy: ' . $value);

  $sent = true;
}

send_csp_header();

function start_secure_session(): void {
  $cfg = app_config();
  $secure = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off')
    || (($_SERVER['HTTP_X_FORWARDED_PROTO'] ?? '') === 'https');
  $cookieParams = session_get_cookie_params();

  if (session_status() === PHP_SESSION_NONE) {
    ini_set('session.use_strict_mode', '1');
    ini_set('session.cookie_httponly', '1');
    session_name($cfg['security']['session_name'] ?? 'HOPESESSID');
    session_set_cookie_params([
      'lifetime' => 0,
      'path' => $cookieParams['path'] ?? '/',
      'domain' => $cookieParams['domain'] ?? '',
      'secure' => $secure,
      'httponly' => true,
      'samesite' => 'Lax',
    ]);
    session_start();
  }

  $now = time();
  $idleTimeout = 1800;
  $absoluteTimeout = 28800;

  if (!isset($_SESSION['_created_at'])) {
    $_SESSION['_created_at'] = $now;
  }
  if (!isset($_SESSION['_last_activity'])) {
    $_SESSION['_last_activity'] = $now;
  }

  $idleExceeded = ($now - (int)$_SESSION['_last_activity']) > $idleTimeout;
  $absoluteExceeded = ($now - (int)$_SESSION['_created_at']) > $absoluteTimeout;

  if ($idleExceeded || $absoluteExceeded) {
    $_SESSION = [];
    if (ini_get('session.use_cookies')) {
      $params = session_get_cookie_params();
      setcookie(session_name(), '', [
        'expires' => time() - 42000,
        'path' => $params['path'] ?? '/',
        'domain' => $params['domain'] ?? '',
        'secure' => $params['secure'] ?? $secure,
        'httponly' => $params['httponly'] ?? true,
        'samesite' => $params['samesite'] ?? 'Lax',
      ]);
    }
    session_destroy();
    session_start();
    $_SESSION['_created_at'] = $now;
  }

  $_SESSION['_last_activity'] = $now;
}

if (!function_exists('e')) {
  function e(string $s): string {
    return htmlspecialchars($s, ENT_QUOTES, 'UTF-8');
  }
}

function csrf_generate_token(): string {
  start_secure_session();
  if (empty($_SESSION['_csrf'])) {
    $_SESSION['_csrf'] = bin2hex(random_bytes(32));
  }
  return $_SESSION['_csrf'];
}

function csrf_validate(string $token): bool {
  start_secure_session();
  return isset($_SESSION['_csrf']) && is_string($token)
    && hash_equals($_SESSION['_csrf'], $token);
}

function csrf_check(): void {
  $token = $_POST['_csrf'] ?? '';
  if (!csrf_validate($token)) {
    http_response_code(403);
    exit('CSRF token invalid.');
  }
}

function rate_limit_store_path(): string {
  return sys_get_temp_dir() . '/hope_rate_limits.json';
}

function rate_limit_read(): array {
  $path = rate_limit_store_path();
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
  return $data;
}

function rate_limit_write(array $data): void {
  @file_put_contents(rate_limit_store_path(), json_encode($data), LOCK_EX);
}

function rate_limit_key(string $scope, string $identifier): string {
  return hash('sha256', $scope . '|' . $identifier);
}

function rate_limit_check(
  string $scope,
  string $identifier,
  int $maxAttempts = 5,
  int $windowSeconds = 900,
  int $blockSeconds = 900
): bool {
  $data = rate_limit_read();
  $key = rate_limit_key($scope, $identifier);
  if (!isset($data[$key])) {
    return true;
  }
  $info = $data[$key];
  $now = time();
  $blockedUntil = (int)($info['blocked_until'] ?? 0);
  if ($blockedUntil > $now) {
    return false;
  }
  $first = (int)($info['first'] ?? $now);
  if (($now - $first) > $windowSeconds) {
    unset($data[$key]);
    rate_limit_write($data);
    return true;
  }
  $count = (int)($info['count'] ?? 0);
  if ($count >= $maxAttempts) {
    return false;
  }
  return true;
}

function rate_limit_record(
  string $scope,
  string $identifier,
  int $maxAttempts = 5,
  int $windowSeconds = 900,
  int $blockSeconds = 900
): int {
  $data = rate_limit_read();
  $key = rate_limit_key($scope, $identifier);
  $now = time();
  $info = $data[$key] ?? ['count' => 0, 'first' => $now, 'last' => $now, 'blocked_until' => 0];

  if (($now - (int)$info['first']) > $windowSeconds) {
    $info = ['count' => 0, 'first' => $now, 'last' => $now, 'blocked_until' => 0];
  }

  $info['count'] = (int)$info['count'] + 1;
  $info['last'] = $now;
  if ($info['count'] >= $maxAttempts) {
    $info['blocked_until'] = $now + $blockSeconds;
  }

  $data[$key] = $info;
  rate_limit_write($data);
  return (int)$info['count'];
}

function rate_limit_clear(string $scope, string $identifier): void {
  $data = rate_limit_read();
  $key = rate_limit_key($scope, $identifier);
  if (isset($data[$key])) {
    unset($data[$key]);
    rate_limit_write($data);
  }
}
