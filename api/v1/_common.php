<?php
require_once __DIR__ . '/../helpers.php';

function api_v1_input(): array {
  $raw = file_get_contents('php://input') ?: '';
  $json = json_decode($raw, true);
  if (is_array($json)) return $json;
  return $_POST ?: [];
}

function api_v1_rows(string $sql, array $params = []): array {
  try { $st = db()->prepare($sql); $st->execute($params); return $st->fetchAll(PDO::FETCH_ASSOC) ?: []; }
  catch (Throwable $e) { api_err('Query gagal: ' . $e->getMessage(), 500); }
}

function api_v1_table_exists(string $table): bool {
  try { $st = db()->prepare('SHOW TABLES LIKE ?'); $st->execute([$table]); return (bool)$st->fetch(PDO::FETCH_NUM); }
  catch (Throwable $e) { return false; }
}

function api_v1_col_exists(string $table, string $col): bool {
  try { $st = db()->prepare("SHOW COLUMNS FROM `$table` LIKE ?"); $st->execute([$col]); return (bool)$st->fetch(PDO::FETCH_ASSOC); }
  catch (Throwable $e) { return false; }
}


function api_v1_branch_id_from_unit_code(?string $unitCode): int {
  $unitCode = strtoupper(trim((string)$unitCode));
  if ($unitCode === '') return 0;
  if (function_exists('adena_branch_id_by_code')) return adena_branch_id_by_code($unitCode);
  try { $st = db()->prepare('SELECT id FROM branches WHERE UPPER(branch_code)=UPPER(?) LIMIT 1'); $st->execute([$unitCode]); return (int)($st->fetchColumn() ?: 0); }
  catch (Throwable $e) { return 0; }
}

function api_v1_branch_id_from_payload(array $row, array $token): int {
  $unitCode = (string)($row['unit_code'] ?? $row['branch_code'] ?? $token['unit_code'] ?? '');
  $id = api_v1_branch_id_from_unit_code($unitCode);
  if ($id > 0) return $id;
  return (int)($row['branch_id'] ?? ($token['branch_id'] ?? 0));
}

function api_v1_filter_branch_sql(array $token, string $alias = ''): array {
  $branchId = (int)($token['branch_id'] ?? 0);
  if ($branchId <= 0) return ['', []];
  $prefix = $alias !== '' ? $alias . '.' : '';
  return [" AND {$prefix}branch_id = ?", [$branchId]];
}

function api_v1_upsert_by_name(string $table, array $data, array $allowed): int {
  $name = trim((string)($data['name'] ?? ''));
  if ($name === '') api_err('Field name wajib diisi.', 422);
  $cols = [];
  $vals = [];
  foreach ($allowed as $col) {
    if (array_key_exists($col, $data) && api_v1_col_exists($table, $col)) { $cols[] = $col; $vals[] = $data[$col]; }
  }
  if (!in_array('name', $cols, true)) { array_unshift($cols, 'name'); array_unshift($vals, $name); }
  $sets = [];
  foreach ($cols as $col) $sets[] = "`$col`=VALUES(`$col`)";
  $sql = "INSERT INTO `$table` (`" . implode('`,`', $cols) . "`) VALUES (" . implode(',', array_fill(0, count($cols), '?')) . ") ON DUPLICATE KEY UPDATE " . implode(',', $sets);
  db()->prepare($sql)->execute($vals);
  return (int)db()->lastInsertId();
}
