<?php
require_once __DIR__ . '/../../../api/helpers.php';
require_once __DIR__ . '/../../../core/api_permissions.php';

function kitchen_api_auth_json(array $data, int $status = 200): void {
  http_response_code($status);
  header('Content-Type: application/json; charset=utf-8');
  echo json_encode($data, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
  exit;
}
function kitchen_api_bearer(): string {
  $h = $_SERVER['HTTP_AUTHORIZATION'] ?? $_SERVER['REDIRECT_HTTP_AUTHORIZATION'] ?? '';
  if ($h === '' && function_exists('getallheaders')) {
    $headers = getallheaders();
    $h = $headers['Authorization'] ?? $headers['authorization'] ?? '';
  }
  if (preg_match('/Bearer\s+(.+)/i', (string)$h, $m)) return trim($m[1]);
  return trim((string)($_GET['token'] ?? ''));
}
function kitchen_api_permissions_raw_allow(int $tokenId, string $permissionsRaw, array $requiredAny): bool {
  if (!$requiredAny) return true;
  foreach ($requiredAny as $perm) {
    if (api_token_has_permission($tokenId, (string)$perm, $permissionsRaw)) return true;
  }
  return false;
}
function kitchen_api_permission_aliases(string $perm): array {
  $perm = strtolower(trim($perm));
  $map = [
    'stock_transfer' => ['stock_transfer','transfers.receive','transfers.create','kitchen.transfer','kitchen.receive','kitchen.write','transfer.write','stock_transfer.write'],
    'transfers.receive' => ['transfers.receive','stock_transfer','kitchen.receive','kitchen.write','transfer.receive','stock_transfer.write'],
    'transfers.create' => ['transfers.create','stock_transfer','kitchen.transfer','kitchen.write','transfer.write','stock_transfer.write'],
    'products.view' => ['products.view','products.read','product.read','master.view','stock_transfer'],
    'master.view' => ['master.view','products.view','products.read'],
  ];
  return $map[$perm] ?? [$perm];
}
function kitchen_api_legacy_permissions_allow(?string $raw, array $requiredAny): bool {
  if (!$requiredAny) return true;
  $perms = json_decode((string)$raw, true);
  if (!is_array($perms)) $perms = array_map('trim', explode(',', (string)$raw));
  $map = array_flip(array_map(static fn($v) => strtolower(trim((string)$v)), $perms));
  if (isset($map['*'])) return true;
  foreach ($requiredAny as $perm) {
    foreach (kitchen_api_permission_aliases((string)$perm) as $alias) {
      if (isset($map[strtolower($alias)])) return true;
    }
  }
  return false;
}
function kitchen_api_token_is_kitchen(array $row): bool {
  $client = strtolower(trim((string)($row['client_type'] ?? '')));
  $type = strtolower(trim((string)($row['api_type'] ?? '')));
  $mode = strtolower(trim((string)($row['api_mode'] ?? '')));
  return in_array($client, ['kitchen','dapur','api_dapur'], true)
    || in_array($type, ['kitchen','dapur','api_dapur'], true)
    || $mode === 'kitchen';
}
function kitchen_api_heal_kitchen_permissions(array $row, array $requiredAny): string {
  $raw = (string)($row['permissions'] ?? '');
  if (!kitchen_api_token_is_kitchen($row)) return $raw;
  if (kitchen_api_legacy_permissions_allow($raw, $requiredAny)) return $raw;
  $perms = function_exists('api_permissions_decode') ? api_permissions_decode($raw) : [];
  $merged = array_values(array_unique(array_merge($perms, ['master.view','products.view','stocks.view','transfers.view','transfers.receive','transfers.create','stock_transfer','stock_return','logs.view'])));
  $encoded = function_exists('api_permissions_encode') ? api_permissions_encode($merged) : json_encode($merged, JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES);
  try { db()->prepare('UPDATE api_tokens SET permissions=? WHERE id=?')->execute([$encoded, (int)$row['id']]); } catch (Throwable $e) {}
  return $encoded;
}
function kitchen_api_find_token(array $requiredAny = []): array {
  if (function_exists('ensure_api_settings_schema')) ensure_api_settings_schema();
  else if (function_exists('ensure_api_tokens_table')) ensure_api_tokens_table();
  $plain = kitchen_api_bearer();
  if ($plain === '') kitchen_api_auth_json(['ok'=>false,'message'=>'Token kosong','error'=>'Token kosong'],401);

  try {
    if (api_table_exists_schema('kitchen_api_tokens')) {
      $stmt = db()->prepare('SELECT * FROM kitchen_api_tokens WHERE token_hash=? AND is_active=1 LIMIT 1');
      $stmt->execute([hash('sha256', $plain)]);
      $tok = $stmt->fetch(PDO::FETCH_ASSOC);
      if ($tok) {
        if (!kitchen_api_legacy_permissions_allow((string)($tok['permissions_json'] ?? ''), $requiredAny)) {
          kitchen_api_auth_json(['ok'=>false,'message'=>'Permission API Dapur ditolak','error'=>'Permission API Dapur ditolak'],403);
        }
        return ['source'=>'kitchen_api_tokens','id'=>(int)$tok['id'],'name'=>(string)($tok['token_name'] ?? 'Kitchen API'),'raw'=>$tok];
      }
    }
  } catch (Throwable $e) {}

  try {
    ensure_api_tokens_table();
    $rows = db()->query('SELECT id, name, token_hash, permissions, allowed_ips, client_type, api_type, api_mode FROM api_tokens WHERE is_active=1 ORDER BY id DESC')->fetchAll(PDO::FETCH_ASSOC);
    foreach ($rows as $row) {
      if (!password_verify($plain, (string)$row['token_hash'])) continue;
      $token = ['id'=>(int)$row['id'],'name'=>(string)$row['name'],'client_type'=>(string)($row['client_type'] ?? ''),'api_type'=>(string)($row['api_type'] ?? '')];
      if (!api_allowed_ip($row['allowed_ips'] ?? null)) {
        kitchen_api_auth_json(['ok'=>false,'message'=>'IP tidak diizinkan','error'=>'IP tidak diizinkan'],403);
      }
      $permissionsRaw = kitchen_api_heal_kitchen_permissions($row, $requiredAny);
      if (!kitchen_api_permissions_raw_allow((int)$row['id'], $permissionsRaw, $requiredAny) && !kitchen_api_legacy_permissions_allow($permissionsRaw, $requiredAny)) {
        kitchen_api_auth_json(['ok'=>false,'message'=>'Permission API ditolak untuk endpoint kitchen. Gunakan token kategori API ke Dapur dengan permission stock_transfer/transfers.receive.','error'=>'Permission API ditolak untuk endpoint kitchen'],403);
      }
      return ['source'=>'api_tokens','id'=>(int)$row['id'],'name'=>(string)$row['name'],'raw'=>$row];
    }
  } catch (Throwable $e) {}

  kitchen_api_auth_json(['ok'=>false,'message'=>'Token tidak valid','error'=>'Token tidak valid'],401);
}
function kitchen_api_touch_token(array $tok): void {
  try {
    if (($tok['source'] ?? '') === 'kitchen_api_tokens') db()->prepare('UPDATE kitchen_api_tokens SET last_used_at=NOW() WHERE id=?')->execute([(int)$tok['id']]);
    else db()->prepare('UPDATE api_tokens SET last_used_at=NOW() WHERE id=?')->execute([(int)$tok['id']]);
  } catch (Throwable $e) {}
}
