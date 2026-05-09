<?php
const ADENA_SINGLE_BRANCH_MODE = true;

function adena_single_config(): array {
  static $cfg = null;
  if ($cfg !== null) return $cfg;
  $file = __DIR__ . '/../config.php';
  $raw = is_file($file) ? include $file : [];
  $app = is_array($raw) ? (array)($raw['app'] ?? []) : [];
  $code = strtoupper(trim((string)($app['unit_code'] ?? $app['branch_code'] ?? 'BLT')));
  $name = trim((string)($app['unit_name'] ?? $app['branch_name'] ?? 'Belitung'));
  $unitType = strtolower(trim((string)($app['unit_type'] ?? 'branch')));
  if (!in_array($unitType, ['backoffice','branch','kitchen'], true)) $unitType = 'branch';
  $cfg = ['unit_code'=>$code ?: 'BLT', 'unit_name'=>$name ?: 'Belitung', 'unit_type'=>$unitType];
  return $cfg;
}

function adena_single_branch_code(): string {
  if (function_exists('setting')) {
    $s = strtoupper(trim((string)setting('active_unit_code', setting('unit_code', setting('branch_code', '')))));
    if ($s !== '') return $s;
  }
  return adena_single_config()['unit_code'];
}

function adena_single_branch_name(): string {
  if (function_exists('setting')) {
    $s = trim((string)setting('unit_name', setting('branch_name', '')));
    if ($s !== '') return $s;
  }
  return adena_single_config()['unit_name'];
}

function adena_single_unit_type(): string {
  if (function_exists('setting')) {
    $s = strtolower(trim((string)setting('active_unit_type', setting('system_unit_type', ''))));
    if (in_array($s, ['backoffice','branch','kitchen'], true)) return $s;
  }
  return adena_single_config()['unit_type'];
}

function adena_branch_id_by_code(string $code): int {
  $code = strtoupper(trim($code));
  if ($code === '' || !function_exists('db')) return 0;
  try {
    $st = db()->prepare("SELECT id FROM branches WHERE UPPER(branch_code)=UPPER(?) LIMIT 1");
    $st->execute([$code]);
    return (int)($st->fetchColumn() ?: 0);
  } catch (Throwable $e) { return 0; }
}

function adena_single_branch_id(): int {
  $code = adena_single_branch_code();
  $id = adena_branch_id_by_code($code);
  if ($id > 0) return $id;
  if (function_exists('setting')) return (int)setting('active_branch_id', '0');
  return 0;
}

function adena_single_branch_payload(): array {
  $id = adena_single_branch_id();
  $unitType = adena_single_unit_type();
  return [[
    'id'=>$id,
    'branch_code'=>adena_single_branch_code(),
    'unit_code'=>adena_single_branch_code(),
    'branch_name'=>adena_single_branch_name(),
    'unit_type'=>$unitType === 'backoffice' ? 'backoffice' : ($unitType === 'kitchen' ? 'kitchen' : 'branch'),
    'is_kitchen'=>$unitType === 'kitchen' ? 1 : 0,
    'is_active'=>1,
  ]];
}

function adena_normalize_branch_id($branchId = null): int {
  $id = (int)$branchId;
  return $id > 0 ? $id : adena_single_branch_id();
}

function adena_enforce_single_branch_schema(): void {
  try {
    if (!function_exists('db')) return;
    $pdo = db();
    $pdo->exec("CREATE TABLE IF NOT EXISTS branches (id INT NOT NULL AUTO_INCREMENT PRIMARY KEY, branch_code VARCHAR(40) NOT NULL, branch_name VARCHAR(120) NOT NULL, unit_type VARCHAR(30) NOT NULL DEFAULT 'branch', is_kitchen TINYINT(1) NOT NULL DEFAULT 0, is_active TINYINT(1) NOT NULL DEFAULT 1, sort_order INT NOT NULL DEFAULT 0, created_at TIMESTAMP NULL DEFAULT CURRENT_TIMESTAMP, updated_at TIMESTAMP NULL DEFAULT NULL ON UPDATE CURRENT_TIMESTAMP, UNIQUE KEY uniq_branch_code (branch_code)) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
    $code = adena_single_branch_code();
    $name = adena_single_branch_name();
    $unitType = adena_single_unit_type();
    if ($unitType !== 'backoffice') {
      $branchType = $unitType === 'kitchen' ? 'kitchen' : 'branch';
      $isKitchen = $unitType === 'kitchen' ? 1 : 0;
      $id = adena_branch_id_by_code($code);
      if ($id > 0) {
        $st = $pdo->prepare("UPDATE branches SET branch_name=?, unit_type=?, is_kitchen=?, is_active=1 WHERE id=?");
        $st->execute([$name,$branchType,$isKitchen,$id]);
      } else {
        $st = $pdo->prepare("INSERT INTO branches (branch_code,branch_name,unit_type,is_kitchen,is_active,sort_order) VALUES (?,?,?,?,1,0)");
        $st->execute([$code,$name,$branchType,$isKitchen]);
        $id = (int)$pdo->lastInsertId();
      }
      try { $pdo->prepare("UPDATE branches SET is_active=0 WHERE UPPER(branch_code)<>UPPER(?)")->execute([$code]); } catch (Throwable $e) {}
      try { $pdo->prepare("UPDATE api_tokens SET branch_id=? WHERE (branch_id IS NULL OR branch_id=0) AND (UPPER(device_code)=UPPER(?) OR UPPER(unit_code)=UPPER(?))")->execute([$id,$code,$code]); } catch (Throwable $e) {}
      $settings = ['active_branch_id'=>(string)$id,'active_unit_code'=>$code,'active_unit_type'=>$unitType,'branch_mode'=>'single','branch_code'=>$code,'branch_name'=>$name,'unit_code'=>$code,'unit_name'=>$name];
    } else {
      try { $pdo->exec("UPDATE branches SET is_active=1"); } catch (Throwable $e) {}
      $settings = ['active_branch_id'=>'','active_unit_code'=>$code,'active_unit_type'=>'backoffice','branch_mode'=>'multi','unit_code'=>$code,'unit_name'=>$name];
    }
    foreach ($settings as $k=>$v) {
      try { $q=$pdo->prepare("INSERT INTO settings (`key`,`value`) VALUES (?,?) ON DUPLICATE KEY UPDATE `value`=VALUES(`value`)"); $q->execute([$k,$v]); } catch (Throwable $e) {}
    }
  } catch (Throwable $e) {}
}
