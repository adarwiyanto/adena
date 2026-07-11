<?php
require_once __DIR__ . '/../../core/api_pairing.php';
pairing_auth('readonly');

function adena_bo_column_exists(string $table, string $column): bool {
  if (function_exists('pairing_column_exists')) return pairing_column_exists($table, $column);
  try {
    $st = db()->prepare('SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME=? AND COLUMN_NAME=?');
    $st->execute([$table, $column]);
    return (int)$st->fetchColumn() > 0;
  } catch (Throwable $e) {
    return false;
  }
}


function adena_bo_table_exists(string $table): bool {
  try {
    $st = db()->prepare('SELECT COUNT(*) FROM information_schema.TABLES WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME=?');
    $st->execute([$table]);
    return (int)$st->fetchColumn() > 0;
  } catch (Throwable $e) {
    return false;
  }
}

function adena_bo_employee_count(): int {
  if (!adena_bo_table_exists('users')) return 0;

  try {
    $joins = [];
    $roleExpressions = [];

    if (adena_bo_column_exists('users', 'role')) {
      $roleExpressions[] = "NULLIF(TRIM(u.role),'')";
    }

    if (adena_bo_column_exists('users', 'role_id') && adena_bo_table_exists('roles') && adena_bo_column_exists('roles', 'id')) {
      $joins[] = 'LEFT JOIN roles r ON r.id=u.role_id';
      if (adena_bo_column_exists('roles', 'role_key')) {
        array_unshift($roleExpressions, "NULLIF(TRIM(r.role_key),'')");
      } elseif (adena_bo_column_exists('roles', 'name')) {
        array_unshift($roleExpressions, "NULLIF(TRIM(r.name),'')");
      }
    }

    $roleExpr = $roleExpressions
      ? ('LOWER(COALESCE(' . implode(',', $roleExpressions) . ",'pegawai_toko'))")
      : "'pegawai_toko'";

    $where = [];
    if (adena_bo_column_exists('users', 'is_active')) {
      $where[] = 'COALESCE(u.is_active,1)=1';
    }
    $where[] = "{$roleExpr} NOT IN ('owner','superadmin')";

    $sql = 'SELECT COUNT(*) FROM users u '
      . implode(' ', $joins)
      . ' WHERE ' . implode(' AND ', $where);

    return (int)db()->query($sql)->fetchColumn();
  } catch (Throwable $e) {
    error_log('[Adena Backoffice Dashboard] employee count: ' . $e->getMessage());
    return 0;
  }
}

function adena_bo_sales_filter_sql(): string {
  $where = [];
  if (adena_bo_column_exists('sales', 'return_reason')) {
    $where[] = "(return_reason IS NULL OR return_reason = '')";
  }
  if (adena_bo_column_exists('sales', 'is_active_revision')) {
    $where[] = "COALESCE(is_active_revision, 1) = 1";
  }
  if (adena_bo_column_exists('sales', 'include_in_sales_report')) {
    $where[] = "COALESCE(include_in_sales_report, 1) = 1";
  }
  return $where ? (' AND ' . implode(' AND ', $where)) : '';
}

function adena_bo_sales_summary(string $startDate, string $endDate): array {
  $out = ['transactions' => 0, 'revenue' => 0.0];
  try {
    $filter = adena_bo_sales_filter_sql();
    $txExpr = "COALESCE(NULLIF(transaction_group_uuid,''), NULLIF(transaction_code,''), CONCAT('LEGACY-', id))";
    if (!adena_bo_column_exists('sales', 'transaction_group_uuid')) {
      $txExpr = "COALESCE(NULLIF(transaction_code,''), CONCAT('LEGACY-', id))";
    }
    $sql = "SELECT COUNT(DISTINCT {$txExpr}) AS transactions, COALESCE(SUM(total),0) AS revenue
            FROM sales
            WHERE sold_at >= ? AND sold_at < ? {$filter}";
    $st = db()->prepare($sql);
    $st->execute([$startDate, $endDate]);
    $row = $st->fetch(PDO::FETCH_ASSOC) ?: [];
    $out['transactions'] = (int)($row['transactions'] ?? 0);
    $out['revenue'] = (float)($row['revenue'] ?? 0);
  } catch (Throwable $e) {
    $out['error'] = $e->getMessage();
  }
  return $out;
}

$today = date('Y-m-d');
$tomorrow = date('Y-m-d', strtotime($today . ' +1 day'));
$monthStart = date('Y-m-01');
$monthEnd = date('Y-m-d', strtotime($monthStart . ' +1 month'));

$todaySales = adena_bo_sales_summary($today, $tomorrow);
$monthSales = adena_bo_sales_summary($monthStart, $monthEnd);

$storeName = 'Adena Store';
try {
  if (function_exists('setting')) {
    $storeName = setting('store_name', $storeName);
  }
} catch (Throwable $e) {}

$employeesCount = adena_bo_employee_count();

$pendingDistributions = 0;
try {
  $tableExists = (int)db()->query("SELECT COUNT(*) FROM information_schema.TABLES WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='kitchen_api_receive_logs'")->fetchColumn() > 0;
  if ($tableExists) {
    $pendingDistributions = (int)db()->query("SELECT COUNT(*) FROM kitchen_api_receive_logs WHERE status='pending_confirmation'")->fetchColumn();
  }
} catch (Throwable $e) {}

$data = [
  'store_name' => $storeName,
  'connection_label' => $storeName,

  // Employee metric consumed by the Back Office dashboard.
  'employees_count' => $employeesCount,
  'employee_count' => $employeesCount,
  'active_employees' => $employeesCount,

  // Source of truth for Dapur -> Toko pending is the receiving store.
  'pending_distributions' => $pendingDistributions,
  'distribution_pending' => $pendingDistributions,

  // Explicit fields used by Back Office dashboard.
  'transactions_today' => (int)$todaySales['transactions'],
  'sales_today' => (int)$todaySales['transactions'], // Backward-compatible: this is transaction count, not revenue.
  'revenue_today' => (float)$todaySales['revenue'],
  'omset_today' => (float)$todaySales['revenue'],

  'transactions_month' => (int)$monthSales['transactions'],
  'sales_month_count' => (int)$monthSales['transactions'],
  'revenue_month' => (float)$monthSales['revenue'],
  'omset_month' => (float)$monthSales['revenue'],
  'omset_bulan_ini' => (float)$monthSales['revenue'],
  'monthly_revenue' => (float)$monthSales['revenue'],

  // Nested aliases for newer dashboard parsers.
  'today' => [
    'transactions' => (int)$todaySales['transactions'],
    'revenue' => (float)$todaySales['revenue'],
    'omset' => (float)$todaySales['revenue'],
  ],
  'month' => [
    'transactions' => (int)$monthSales['transactions'],
    'revenue' => (float)$monthSales['revenue'],
    'omset' => (float)$monthSales['revenue'],
  ],
  'sales' => [
    'today' => (float)$todaySales['revenue'],
    'this_month' => (float)$monthSales['revenue'],
    'today_count' => (int)$todaySales['transactions'],
    'month_count' => (int)$monthSales['transactions'],
  ],

  'products' => 0,
  'pending_pairing' => pairing_pending_count(),
  'period' => [
    'today' => $today,
    'month_start' => $monthStart,
    'month_end_exclusive' => $monthEnd,
  ],
];

try {
  $data['products'] = (int)db()->query('SELECT COUNT(*) FROM products')->fetchColumn();
  $data['active_products'] = $data['products'];
} catch (Throwable $e) {}

$errors = [];
if (!empty($todaySales['error'])) $errors['today_sales'] = $todaySales['error'];
if (!empty($monthSales['error'])) $errors['month_sales'] = $monthSales['error'];
if ($errors) $data['errors'] = $errors;

pairing_ok(['data' => $data]);
