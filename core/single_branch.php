<?php
const ADENA_SINGLE_BRANCH_MODE = true;
const ADENA_BRANCH_ID = 1;
const ADENA_BRANCH_CODE = 'BLT';
const ADENA_BRANCH_NAME = 'Belitung';
const ADENA_BRANCH_UNIT_TYPE = 'branch';
function adena_single_branch_id(): int { return ADENA_BRANCH_ID; }
function adena_single_branch_code(): string { return ADENA_BRANCH_CODE; }
function adena_single_branch_name(): string { return ADENA_BRANCH_NAME; }
function adena_single_branch_payload(): array {
  return [[
    'id'=>ADENA_BRANCH_ID,
    'branch_code'=>ADENA_BRANCH_CODE,
    'branch_name'=>ADENA_BRANCH_NAME,
    'unit_type'=>ADENA_BRANCH_UNIT_TYPE,
    'is_kitchen'=>0,
    'is_active'=>1,
  ]];
}
function adena_normalize_branch_id($branchId = null): int { return ADENA_BRANCH_ID; }
function adena_enforce_single_branch_schema(): void {
  try {
    if (!function_exists('db')) return;
    $pdo = db();
    $pdo->exec("CREATE TABLE IF NOT EXISTS branches (id INT NOT NULL PRIMARY KEY, branch_code VARCHAR(40) NOT NULL, branch_name VARCHAR(120) NOT NULL, unit_type VARCHAR(30) NOT NULL DEFAULT 'branch', is_kitchen TINYINT(1) NOT NULL DEFAULT 0, is_active TINYINT(1) NOT NULL DEFAULT 1, sort_order INT NOT NULL DEFAULT 0, created_at TIMESTAMP NULL DEFAULT CURRENT_TIMESTAMP, updated_at TIMESTAMP NULL DEFAULT NULL) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
    $st = $pdo->prepare("INSERT INTO branches (id,branch_code,branch_name,unit_type,is_kitchen,is_active,sort_order) VALUES (1,?,?,'branch',0,1,0) ON DUPLICATE KEY UPDATE branch_code=VALUES(branch_code), branch_name=VALUES(branch_name), unit_type='branch', is_kitchen=0, is_active=1");
    $st->execute([ADENA_BRANCH_CODE, ADENA_BRANCH_NAME]);
    $pdo->exec("UPDATE branches SET is_active=0 WHERE id<>1");
    try { $pdo->exec("UPDATE api_tokens SET branch_id=1 WHERE branch_id IS NULL OR branch_id<>1"); } catch (Throwable $e) {}
    foreach (['active_branch_id'=>'1','branch_mode'=>'single','branch_code'=>'BLT','branch_name'=>'Belitung'] as $k=>$v) {
      try { $q=$pdo->prepare("INSERT INTO settings (`key`,`value`) VALUES (?,?) ON DUPLICATE KEY UPDATE `value`=VALUES(`value`)"); $q->execute([$k,$v]); } catch (Throwable $e) {}
    }
  } catch (Throwable $e) {}
}
