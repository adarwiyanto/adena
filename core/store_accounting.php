<?php
/**
 * Pusat perhitungan keuangan operasional toko.
 *
 * Prinsip:
 * - Tidak membuat atau mengubah struktur database.
 * - Tidak mengubah kontrak API.
 * - Penjualan dihitung per transaksi agar diskon transaksi tidak berulang per item.
 * - Retur diakui pada tanggal retur, bukan menghapus omzet historis tanggal penjualan.
 * - Pembelian persediaan tidak langsung menjadi beban; HPP dihitung dari mutasi stok yang memiliki dasar biaya.
 */
require_once __DIR__ . '/db.php';
require_once __DIR__ . '/functions.php';

function store_acc_table_exists(string $table): bool {
  static $cache = [];
  $key = strtolower($table);
  if (array_key_exists($key, $cache)) return $cache[$key];
  try {
    $stmt = db()->prepare('SELECT COUNT(*) FROM information_schema.TABLES WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME=?');
    $stmt->execute([$table]);
    return $cache[$key] = ((int)$stmt->fetchColumn() > 0);
  } catch (Throwable $e) {
    return $cache[$key] = false;
  }
}

function store_acc_column_exists(string $table, string $column): bool {
  static $cache = [];
  $key = strtolower($table . '.' . $column);
  if (array_key_exists($key, $cache)) return $cache[$key];
  try {
    $stmt = db()->prepare('SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME=? AND COLUMN_NAME=?');
    $stmt->execute([$table, $column]);
    return $cache[$key] = ((int)$stmt->fetchColumn() > 0);
  } catch (Throwable $e) {
    return $cache[$key] = false;
  }
}

function store_acc_alias(string $alias): string {
  $alias = preg_replace('/[^a-zA-Z0-9_]/', '', $alias);
  return $alias !== '' ? $alias : 's';
}

function store_acc_tx_key_expr(string $alias = 's'): string {
  $a = store_acc_alias($alias);
  $parts = [];
  if (store_acc_column_exists('sales', 'transaction_group_uuid')) $parts[] = "NULLIF({$a}.transaction_group_uuid,'')";
  if (store_acc_column_exists('sales', 'transaction_code')) $parts[] = "NULLIF({$a}.transaction_code,'')";
  if (store_acc_column_exists('sales', 'local_transaction_id')) $parts[] = "NULLIF({$a}.local_transaction_id,'')";
  $parts[] = "CONCAT('LEGACY-',{$a}.id)";
  return 'COALESCE(' . implode(',', $parts) . ')';
}

function store_acc_active_filter(string $alias = 's'): string {
  $a = store_acc_alias($alias);
  $where = [];
  if (store_acc_column_exists('sales', 'is_active_revision')) $where[] = "COALESCE({$a}.is_active_revision,1)=1";
  if (store_acc_column_exists('sales', 'revision_status')) $where[] = "COALESCE(NULLIF({$a}.revision_status,''),'active')='active'";
  if (store_acc_column_exists('sales', 'include_in_sales_report')) $where[] = "COALESCE({$a}.include_in_sales_report,1)=1";
  return $where ? implode(' AND ', $where) : '1=1';
}

function store_acc_normalize_datetime(string $value, bool $end = false): string {
  $value = trim($value);
  if ($value === '') return $end ? '9999-12-31 23:59:59' : '1970-01-01 00:00:00';
  if (preg_match('/^\d{4}-\d{2}-\d{2}$/', $value)) return $value . ($end ? ' 23:59:59' : ' 00:00:00');
  $ts = strtotime($value);
  if ($ts === false) return $end ? '9999-12-31 23:59:59' : '1970-01-01 00:00:00';
  return date('Y-m-d H:i:s', $ts);
}

function store_acc_end_exclusive_date(string $end): string {
  $normalized = store_acc_normalize_datetime($end, true);
  $date = substr($normalized, 0, 10);
  $time = substr($normalized, 11, 8);
  return $time === '00:00:00' ? $date : date('Y-m-d', strtotime($date . ' +1 day'));
}

function store_acc_in_range(?string $value, string $start, string $end): bool {
  if (!$value) return false;
  $ts = strtotime($value);
  if ($ts === false) return false;
  return $ts >= strtotime($start) && $ts < strtotime($end);
}

function store_acc_discount_type($value): string {
  return strtolower(trim((string)$value)) === 'percent' ? 'percent' : 'fixed';
}

function store_acc_money($value): float {
  $number = (float)$value;
  if (!is_finite($number)) return 0.0;
  return round($number, 2);
}

function store_acc_fetch_sale_rows(string $start, string $end, array $options = []): array {
  if (!store_acc_table_exists('sales')) return [];
  $start = store_acc_normalize_datetime($start);
  $end = store_acc_normalize_datetime($end, true);
  $candidateKey = store_acc_tx_key_expr('c');
  $outerKey = store_acc_tx_key_expr('s');
  $candidateWhere = [store_acc_active_filter('c')];
  $outerWhere = [store_acc_active_filter('s')];
  $candidateParams = [];
  $outerParams = [];

  $returnDate = store_acc_column_exists('sales', 'returned_at') ? 'COALESCE(c.returned_at,c.sold_at)' : 'c.sold_at';
  $returnFlags = [];
  if (store_acc_column_exists('sales', 'return_reason')) $returnFlags[] = "(c.return_reason IS NOT NULL AND TRIM(c.return_reason)<>'')";
  if (store_acc_column_exists('sales', 'return_status')) $returnFlags[] = "LOWER(COALESCE(c.return_status,'none'))='returned'";
  $returnFlag = $returnFlags ? '(' . implode(' OR ', $returnFlags) . ')' : '0=1';
  $candidateWhere[] = "((c.sold_at>=? AND c.sold_at<?) OR ({$returnFlag} AND {$returnDate}>=? AND {$returnDate}<?))";
  array_push($candidateParams, $start, $end, $start, $end);

  if (isset($options['branch_id']) && $options['branch_id'] !== null && store_acc_column_exists('sales', 'branch_id')) {
    $branchId = (int)$options['branch_id'];
    $includeLegacy = !empty($options['include_legacy_branch']);
    $candidateWhere[] = $includeLegacy ? '(c.branch_id=? OR c.branch_id IS NULL OR c.branch_id=0)' : 'c.branch_id=?';
    $outerWhere[] = $includeLegacy ? '(s.branch_id=? OR s.branch_id IS NULL OR s.branch_id=0)' : 's.branch_id=?';
    $candidateParams[] = $branchId;
    $outerParams[] = $branchId;
  }
  if (isset($options['shift_id']) && $options['shift_id'] !== null && store_acc_column_exists('sales', 'shift_id')) {
    $candidateWhere[] = 'c.shift_id=?';
    $outerWhere[] = 's.shift_id=?';
    $candidateParams[] = (int)$options['shift_id'];
    $outerParams[] = (int)$options['shift_id'];
  }

  $productJoin = '';
  $productSelect = "NULL AS product_name, 'finished_good' AS product_type, 1 AS track_stock, 1 AS sale_to_base_factor";
  if (store_acc_table_exists('products')) {
    $productJoin = ' LEFT JOIN products p ON p.id=s.product_id ';
    $productSelect = "p.name AS product_name"
      . (store_acc_column_exists('products', 'product_type') ? ',p.product_type' : ",'finished_good' AS product_type")
      . (store_acc_column_exists('products', 'track_stock') ? ',COALESCE(p.track_stock,1) AS track_stock' : ',1 AS track_stock')
      . (store_acc_column_exists('products', 'sale_to_base_factor') ? ',COALESCE(NULLIF(p.sale_to_base_factor,0),1) AS sale_to_base_factor' : ',1 AS sale_to_base_factor');
  }

  $sql = "SELECT s.*, {$outerKey} AS _tx_key, {$productSelect}
          FROM sales s
          JOIN (
            SELECT DISTINCT {$candidateKey} AS tx_key
            FROM sales c
            WHERE " . implode(' AND ', $candidateWhere) . "
          ) selected_tx ON selected_tx.tx_key={$outerKey}
          {$productJoin}
          WHERE " . implode(' AND ', $outerWhere) . "
          ORDER BY s.sold_at ASC,s.id ASC";
  try {
    $stmt = db()->prepare($sql);
    $stmt->execute(array_merge($candidateParams, $outerParams));
    return $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
  } catch (Throwable $e) {
    return [];
  }
}

function store_acc_prepare_transaction(array $rows): array {
  $prepared = [];
  $gross = 0.0;
  $afterItem = 0.0;
  $storedNetSum = 0.0;
  $hasPositiveStoredNet = false;
  $hasStoredDifference = false;
  $allEligibleRowsHaveStoredNet = true;
  $txDiscountCandidates = [];

  foreach ($rows as $index => $row) {
    $qty = max(0.0, (float)($row['qty'] ?? 0));
    $price = max(0.0, (float)($row['price_each'] ?? 0));
    // Harga kotor baku selalu qty x harga. line_subtotal hanya fallback bila data lama tidak memiliki keduanya.
    $lineGross = store_acc_money($qty * $price);
    if ($lineGross <= 0 && array_key_exists('line_subtotal', $row) && (float)$row['line_subtotal'] > 0) {
      $lineGross = store_acc_money($row['line_subtotal']);
    }

    $lineAfterItem = max(0.0, store_acc_money($row['total'] ?? 0));
    if (!array_key_exists('total', $row)) {
      $discountAmount = max(0.0, (float)($row['discount_amount'] ?? 0));
      $discountType = store_acc_discount_type($row['discount_type'] ?? 'fixed');
      $discountValue = $discountType === 'percent'
        ? round($lineGross * min(100.0, $discountAmount) / 100, 2)
        : min($lineGross, $discountAmount);
      $lineAfterItem = max(0.0, store_acc_money($lineGross - $discountValue));
    }

    // Data legacy kadang menyimpan total lebih besar akibat pembulatan/input manual.
    // Perlakukan nilai terbesar sebagai penjualan kotor agar diskon tidak pernah negatif.
    if ($lineAfterItem > $lineGross) $lineGross = $lineAfterItem;
    $itemDiscount = max(0.0, store_acc_money($lineGross - $lineAfterItem));
    $storedNet = array_key_exists('line_net_total', $row) ? max(0.0, store_acc_money($row['line_net_total'])) : 0.0;
    if ($storedNet > 0) $hasPositiveStoredNet = true;
    if ($lineAfterItem > 0 && $storedNet <= 0) $allEligibleRowsHaveStoredNet = false;
    if (abs($storedNet - $lineAfterItem) > 0.009) $hasStoredDifference = true;

    $txAmount = max(0.0, (float)($row['tx_discount_amount'] ?? 0));
    $txType = store_acc_discount_type($row['tx_discount_type'] ?? 'fixed');
    if ($txAmount > 0) $txDiscountCandidates[] = [$txAmount, $txType];

    $prepared[$index] = $row + [
      '_gross' => $lineGross,
      '_after_item_discount' => $lineAfterItem,
      '_item_discount' => $itemDiscount,
      '_stored_line_net' => $storedNet,
      '_tx_discount_allocated' => 0.0,
      '_line_net' => $lineAfterItem,
    ];
    $gross += $lineGross;
    $afterItem += $lineAfterItem;
    $storedNetSum += $storedNet;
  }

  $gross = store_acc_money($gross);
  $afterItem = store_acc_money($afterItem);
  $useStoredNet = $hasPositiveStoredNet && $hasStoredDifference && $allEligibleRowsHaveStoredNet && $storedNetSum <= ($afterItem + 0.01);
  $txDiscount = 0.0;

  if ($useStoredNet) {
    foreach ($prepared as &$row) {
      $row['_line_net'] = min((float)$row['_after_item_discount'], (float)$row['_stored_line_net']);
      $row['_tx_discount_allocated'] = max(0.0, store_acc_money($row['_after_item_discount'] - $row['_line_net']));
    }
    unset($row);
    $txDiscount = max(0.0, store_acc_money($afterItem - array_sum(array_column($prepared, '_line_net'))));
  } else {
    // Diskon transaksi disimpan berulang pada tiap baris; baca hanya satu kali per transaksi.
    if ($txDiscountCandidates) {
      [$amount, $type] = $txDiscountCandidates[0];
      $candidate = $type === 'percent'
        ? round($afterItem * min(100.0, $amount) / 100, 2)
        : min($afterItem, $amount);
      $txDiscount = store_acc_money($candidate);
    }
    $txDiscount = min($afterItem, $txDiscount);

    $allocated = 0.0;
    $positiveIndexes = array_keys(array_filter($prepared, static fn($r) => (float)$r['_after_item_discount'] > 0));
    $lastPositive = $positiveIndexes ? end($positiveIndexes) : null;
    foreach ($prepared as $index => &$row) {
      if ($txDiscount <= 0 || $afterItem <= 0 || (float)$row['_after_item_discount'] <= 0) {
        $allocation = 0.0;
      } elseif ($index === $lastPositive) {
        $allocation = store_acc_money($txDiscount - $allocated);
      } else {
        $allocation = round($txDiscount * ((float)$row['_after_item_discount'] / $afterItem), 2);
        $allocated += $allocation;
      }
      $allocation = min((float)$row['_after_item_discount'], max(0.0, $allocation));
      $row['_tx_discount_allocated'] = $allocation;
      $row['_line_net'] = max(0.0, store_acc_money($row['_after_item_discount'] - $allocation));
    }
    unset($row);
  }

  $net = store_acc_money(array_sum(array_column($prepared, '_line_net')));
  $itemDiscount = max(0.0, store_acc_money($gross - $afterItem));
  $txDiscount = max(0.0, store_acc_money($afterItem - $net));

  $first = $prepared[0] ?? [];
  $soldAt = null;
  foreach ($prepared as $row) {
    $value = $row['sold_at'] ?? null;
    if ($value && ($soldAt === null || strtotime($value) < strtotime($soldAt))) $soldAt = $value;
  }

  return [
    'key' => (string)($first['_tx_key'] ?? $first['transaction_code'] ?? ('LEGACY-' . ($first['id'] ?? '0'))),
    'transaction_code' => (string)($first['transaction_code'] ?? ''),
    'sold_at' => $soldAt,
    'payment_method' => (string)($first['payment_method'] ?? 'unknown'),
    'payment_bank' => (string)($first['payment_bank'] ?? ($first['payment_channel_name'] ?? '')),
    'created_by' => (int)($first['created_by'] ?? 0),
    'branch_id' => (int)($first['branch_id'] ?? 0),
    'gross' => $gross,
    'item_discount' => $itemDiscount,
    'transaction_discount' => $txDiscount,
    'discount_total' => store_acc_money($itemDiscount + $txDiscount),
    'net_before_return' => $net,
    'rows' => $prepared,
  ];
}

function store_acc_sales_metrics(string $start, string $end, array $options = []): array {
  $start = store_acc_normalize_datetime($start);
  $end = store_acc_normalize_datetime($end, true);
  $rows = store_acc_fetch_sale_rows($start, $end, $options);
  $groups = [];
  foreach ($rows as $row) $groups[(string)$row['_tx_key']][] = $row;

  $out = [
    'gross_sales' => 0.0,
    'item_discount' => 0.0,
    'transaction_discount' => 0.0,
    'discount_total' => 0.0,
    'sales_after_discount' => 0.0,
    'returns' => 0.0,
    'net_sales' => 0.0,
    'transactions' => 0,
    'return_transactions' => 0,
    'avg_transaction' => 0.0,
    'payment_breakdown' => [],
    'daily' => [],
    'products' => [],
    'details' => [],
    '_transactions' => [],
  ];

  $payment = [];
  $daily = [];
  $products = [];

  foreach ($groups as $groupRows) {
    $tx = store_acc_prepare_transaction($groupRows);
    $soldInRange = store_acc_in_range($tx['sold_at'], $start, $end);
    $returnAmount = 0.0;
    $returnDate = null;
    $returnedLines = [];

    foreach ($tx['rows'] as $line) {
      $isReturned = false;
      if (array_key_exists('return_reason', $line)) $isReturned = trim((string)$line['return_reason']) !== '';
      if (!$isReturned && array_key_exists('return_status', $line)) $isReturned = strtolower((string)$line['return_status']) === 'returned';
      if (!$isReturned) continue;
      $lineReturnDate = $line['returned_at'] ?? $line['sold_at'] ?? null;
      if (!store_acc_in_range($lineReturnDate, $start, $end)) continue;
      $returnAmount += (float)$line['_line_net'];
      $returnedLines[] = $line;
      if ($lineReturnDate && ($returnDate === null || strtotime($lineReturnDate) < strtotime($returnDate))) $returnDate = $lineReturnDate;
    }
    $returnAmount = store_acc_money($returnAmount);

    $methodKey = strtolower(trim((string)$tx['payment_method'])) ?: 'unknown';
    if (!isset($payment[$methodKey])) {
      $payment[$methodKey] = [
        'payment_method' => $tx['payment_method'] ?: 'unknown',
        'payment_bank' => $tx['payment_bank'],
        'c' => 0,
        'sales_after_discount' => 0.0,
        'returns' => 0.0,
        's' => 0.0,
      ];
    }

    if ($soldInRange) {
      $out['gross_sales'] += $tx['gross'];
      $out['item_discount'] += $tx['item_discount'];
      $out['transaction_discount'] += $tx['transaction_discount'];
      $out['sales_after_discount'] += $tx['net_before_return'];
      $out['transactions']++;
      $payment[$methodKey]['c']++;
      $payment[$methodKey]['sales_after_discount'] += $tx['net_before_return'];

      $day = date('Y-m-d', strtotime((string)$tx['sold_at']));
      if (!isset($daily[$day])) $daily[$day] = 0.0;
      $daily[$day] += $tx['net_before_return'];

      foreach ($tx['rows'] as $line) {
        $pid = (int)($line['product_id'] ?? 0);
        $pkey = $pid > 0 ? (string)$pid : 'unknown-' . ($line['product_name'] ?? 'unknown');
        if (!isset($products[$pkey])) {
          $products[$pkey] = [
            'product_id' => $pid,
            'name' => (string)($line['product_name'] ?? ('Produk #' . $pid)),
            'qty' => 0.0,
            'omzet' => 0.0,
          ];
        }
        $products[$pkey]['qty'] += (float)($line['qty'] ?? 0);
        $products[$pkey]['omzet'] += (float)$line['_line_net'];
      }

      $out['details'][] = [
        'event_type' => 'sale',
        'event_label' => 'Penjualan',
        'event_at' => $tx['sold_at'],
        'transaction_code' => $tx['transaction_code'] ?: $tx['key'],
        'payment_method' => $tx['payment_method'],
        'payment_bank' => $tx['payment_bank'],
        'created_by' => $tx['created_by'],
        'branch_id' => $tx['branch_id'],
        'qty' => array_sum(array_map(static fn($line) => (float)($line['qty'] ?? 0), $tx['rows'])),
        'gross' => $tx['gross'],
        'item_discount' => $tx['item_discount'],
        'transaction_discount' => $tx['transaction_discount'],
        'discount_total' => $tx['discount_total'],
        'return_amount' => 0.0,
        'net' => $tx['net_before_return'],
      ];
    }

    if ($returnAmount > 0) {
      $out['returns'] += $returnAmount;
      $out['return_transactions']++;
      $payment[$methodKey]['returns'] += $returnAmount;
      if ($returnDate) {
        $day = date('Y-m-d', strtotime($returnDate));
        if (!isset($daily[$day])) $daily[$day] = 0.0;
        $daily[$day] -= $returnAmount;
      }

      foreach ($returnedLines as $line) {
        $pid = (int)($line['product_id'] ?? 0);
        $pkey = $pid > 0 ? (string)$pid : 'unknown-' . ($line['product_name'] ?? 'unknown');
        if (!isset($products[$pkey])) {
          $products[$pkey] = [
            'product_id' => $pid,
            'name' => (string)($line['product_name'] ?? ('Produk #' . $pid)),
            'qty' => 0.0,
            'omzet' => 0.0,
          ];
        }
        $products[$pkey]['qty'] -= (float)($line['qty'] ?? 0);
        $products[$pkey]['omzet'] -= (float)$line['_line_net'];
      }

      $out['details'][] = [
        'event_type' => 'return',
        'event_label' => 'Retur',
        'event_at' => $returnDate ?: $tx['sold_at'],
        'transaction_code' => $tx['transaction_code'] ?: $tx['key'],
        'payment_method' => $tx['payment_method'],
        'payment_bank' => $tx['payment_bank'],
        'created_by' => $tx['created_by'],
        'branch_id' => $tx['branch_id'],
        'qty' => -array_sum(array_map(static fn($line) => (float)($line['qty'] ?? 0), $returnedLines)),
        'gross' => 0.0,
        'item_discount' => 0.0,
        'transaction_discount' => 0.0,
        'discount_total' => 0.0,
        'return_amount' => $returnAmount,
        'net' => -$returnAmount,
      ];
    }

    $out['_transactions'][$tx['key']] = $tx + ['return_amount_in_period' => $returnAmount];
  }

  foreach ($payment as &$row) {
    $row['sales_after_discount'] = store_acc_money($row['sales_after_discount']);
    $row['returns'] = store_acc_money($row['returns']);
    $row['s'] = store_acc_money($row['sales_after_discount'] - $row['returns']);
  }
  unset($row);
  usort($payment, static fn($a, $b) => $b['s'] <=> $a['s']);

  foreach ($daily as $date => $amount) $daily[$date] = store_acc_money($amount);
  ksort($daily);
  foreach ($products as &$row) {
    $row['qty'] = round((float)$row['qty'], 4);
    $row['omzet'] = store_acc_money($row['omzet']);
  }
  unset($row);
  usort($products, static function ($a, $b) {
    $qtyCompare = $b['qty'] <=> $a['qty'];
    return $qtyCompare !== 0 ? $qtyCompare : ($b['omzet'] <=> $a['omzet']);
  });
  usort($out['details'], static fn($a, $b) => strtotime((string)$a['event_at']) <=> strtotime((string)$b['event_at']));

  foreach (['gross_sales', 'item_discount', 'transaction_discount', 'sales_after_discount', 'returns'] as $key) {
    $out[$key] = store_acc_money($out[$key]);
  }
  $out['discount_total'] = store_acc_money($out['item_discount'] + $out['transaction_discount']);
  $out['net_sales'] = store_acc_money($out['sales_after_discount'] - $out['returns']);
  $out['avg_transaction'] = $out['transactions'] > 0 ? store_acc_money($out['net_sales'] / $out['transactions']) : 0.0;
  $out['payment_breakdown'] = array_values($payment);
  $out['daily'] = $daily;
  $out['products'] = array_values($products);
  return $out;
}

/**
 * Mengalokasikan diskon/pajak header pembelian secara proporsional ke tiap baris.
 * Hasil alokasi per header selalu sama dengan grand_total (koreksi pembulatan di baris terakhir).
 */
function store_acc_allocate_purchase_rows(array $rows): array {
  $groups = [];
  foreach ($rows as $row) $groups[(string)($row['id'] ?? $row['purchase_id'] ?? '0')][] = $row;
  $result = [];
  foreach ($groups as $groupRows) {
    $rawTotal = 0.0;
    foreach ($groupRows as $row) $rawTotal += max(0.0, store_acc_money($row['line_total'] ?? 0));
    $rawTotal = store_acc_money($rawTotal);
    $first = $groupRows[0] ?? [];
    $hasGrandTotal = array_key_exists('grand_total', $first) && $first['grand_total'] !== null;
    $target = $hasGrandTotal ? max(0.0, store_acc_money($first['grand_total'])) : $rawTotal;
    $allocated = 0.0;
    $last = count($groupRows) - 1;
    foreach ($groupRows as $index => $row) {
      $raw = max(0.0, store_acc_money($row['line_total'] ?? 0));
      if ($index === $last) {
        $amount = store_acc_money($target - $allocated);
      } elseif ($rawTotal > 0) {
        $amount = round($target * ($raw / $rawTotal), 2);
        $allocated += $amount;
      } else {
        $amount = $index === 0 ? $target : 0.0;
        $allocated += $amount;
      }
      $row['_accounting_amount'] = max(0.0, store_acc_money($amount));
      $result[] = $row;
    }
  }
  return $result;
}

function store_acc_purchase_unit_cost_map(string $end, array $options = []): array {
  if (!store_acc_table_exists('purchase_headers') || !store_acc_table_exists('purchase_items')) return [];
  $endDate = store_acc_end_exclusive_date($end);
  $where = ["ph.purchase_date<?", "ph.status='posted'", 'pi.product_id IS NOT NULL', 'pi.product_id>0'];
  $params = [$endDate];
  if (isset($options['branch_id']) && $options['branch_id'] !== null && store_acc_column_exists('purchase_headers', 'branch_id')) {
    $where[] = 'ph.branch_id=?';
    $params[] = (int)$options['branch_id'];
  }
  try {
    $stmt = db()->prepare("SELECT ph.id,ph.branch_id,ph.grand_total,pi.id purchase_item_id,pi.product_id,pi.qty,pi.line_total
      FROM purchase_headers ph JOIN purchase_items pi ON pi.purchase_id=ph.id
      WHERE " . implode(' AND ', $where) . ' ORDER BY ph.id,pi.id');
    $stmt->execute($params);
    $allocatedRows = store_acc_allocate_purchase_rows($stmt->fetchAll(PDO::FETCH_ASSOC) ?: []);
    $totals = [];
    foreach ($allocatedRows as $row) {
      $key = (int)($row['branch_id'] ?? 0) . ':' . (int)($row['id'] ?? 0) . ':' . (int)($row['product_id'] ?? 0);
      if (!isset($totals[$key])) $totals[$key] = ['amount' => 0.0, 'qty' => 0.0];
      $totals[$key]['amount'] += (float)($row['_accounting_amount'] ?? 0);
      $totals[$key]['qty'] += max(0.0, (float)($row['qty'] ?? 0));
    }
    $map = [];
    foreach ($totals as $key => $value) {
      if ($value['qty'] > 0.000001) $map[$key] = store_acc_money($value['amount'] / $value['qty']);
    }
    return $map;
  } catch (Throwable $e) {
    return [];
  }
}

function store_acc_purchase_is_internal(array $row): bool {
  $haystack = strtolower(implode(' ', [
    (string)($row['notes'] ?? ''),
    (string)($row['item_name'] ?? ''),
    (string)($row['item_notes'] ?? ''),
    (string)($row['supplier_name'] ?? ''),
  ]));
  foreach (['dapur adena', 'dapur', 'kitchen', 'internal transfer', 'transfer internal'] as $needle) {
    if (str_contains($haystack, $needle)) return true;
  }
  return false;
}

function store_acc_expense_category(string $itemName, string $fallback = 'Lainnya'): string {
  $name = strtoupper(trim($itemName));
  $map = [
    '[PLN]' => 'Listrik / PLN',
    '[GUIDE]' => 'Guide',
    '[ATK]' => 'ATK',
    '[PROYEK DAPUR]' => 'Proyek Dapur',
    '[JASA]' => 'Jasa',
    '[TRANSPORTASI]' => 'Transportasi',
    '[PERAWATAN]' => 'Perawatan / Perbaikan',
    '[LAINNYA]' => 'Lainnya',
  ];
  foreach ($map as $prefix => $label) if (str_starts_with($name, $prefix)) return $label;
  return $fallback !== '' ? $fallback : 'Lainnya';
}

function store_acc_store_costs(string $start, string $end, array $options = []): array {
  $startDate = substr(store_acc_normalize_datetime($start), 0, 10);
  $endExclusive = store_acc_end_exclusive_date($end);
  $out = [
    'inventory_purchases' => 0.0,
    'internal_inventory_purchases' => 0.0,
    'external_inventory_purchases' => 0.0,
    'operating_expenses' => 0.0,
    'general_purchase_expenses' => 0.0,
    'expense_records' => 0.0,
    'payment_request_pending' => 0.0,
    'payment_request_paid' => 0.0,
    'expense_breakdown' => [],
  ];
  $branchId = isset($options['branch_id']) ? (int)$options['branch_id'] : null;
  $breakdown = [];

  if (store_acc_table_exists('purchase_headers') && store_acc_table_exists('purchase_items')) {
    $where = ["ph.purchase_date>=?", "ph.purchase_date<?", "ph.status='posted'"];
    $params = [$startDate, $endExclusive];
    if ($branchId !== null && store_acc_column_exists('purchase_headers', 'branch_id')) {
      $where[] = 'ph.branch_id=?';
      $params[] = $branchId;
    }
    $supplierJoin = store_acc_table_exists('suppliers') ? ' LEFT JOIN suppliers sp ON sp.id=ph.supplier_id ' : '';
    $supplierSelect = store_acc_table_exists('suppliers') ? ',sp.supplier_name' : ",'' AS supplier_name";
    try {
      $stmt = db()->prepare("SELECT ph.id,ph.purchase_no,ph.purchase_date,ph.purchase_type,ph.notes,ph.grand_total,pi.id purchase_item_id,pi.product_id,pi.item_name,pi.notes item_notes,pi.qty,pi.line_total {$supplierSelect}
        FROM purchase_headers ph JOIN purchase_items pi ON pi.purchase_id=ph.id {$supplierJoin}
        WHERE " . implode(' AND ', $where) . " ORDER BY ph.id,pi.id");
      $stmt->execute($params);
      $purchaseRows = store_acc_allocate_purchase_rows($stmt->fetchAll(PDO::FETCH_ASSOC) ?: []);
      foreach ($purchaseRows as $row) {
        $amount = max(0.0, store_acc_money($row['_accounting_amount'] ?? $row['line_total'] ?? 0));
        $isInventory = (int)($row['product_id'] ?? 0) > 0;
        if ($isInventory) {
          $out['inventory_purchases'] += $amount;
          if (store_acc_purchase_is_internal($row)) $out['internal_inventory_purchases'] += $amount;
          else $out['external_inventory_purchases'] += $amount;
        } else {
          $category = store_acc_expense_category((string)($row['item_name'] ?? ''));
          $out['general_purchase_expenses'] += $amount;
          $breakdown[$category] = ($breakdown[$category] ?? 0.0) + $amount;
        }
      }
    } catch (Throwable $e) {}
  }

  if (store_acc_table_exists('expenses')) {
    $where = ['expense_date>=?', 'expense_date<?', "status IN ('approved','paid')"];
    $params = [$startDate, $endExclusive];
    if (store_acc_column_exists('expenses', 'deleted_at')) $where[] = 'deleted_at IS NULL';
    if ($branchId !== null && store_acc_column_exists('expenses', 'branch_id')) {
      $where[] = 'branch_id=?';
      $params[] = $branchId;
    }
    try {
      $stmt = db()->prepare('SELECT amount,category_name_snapshot FROM expenses WHERE ' . implode(' AND ', $where));
      $stmt->execute($params);
      foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
        $amount = max(0.0, store_acc_money($row['amount'] ?? 0));
        $category = trim((string)($row['category_name_snapshot'] ?? 'Lainnya')) ?: 'Lainnya';
        $out['expense_records'] += $amount;
        $breakdown[$category] = ($breakdown[$category] ?? 0.0) + $amount;
      }
    } catch (Throwable $e) {}
  }

  if (store_acc_table_exists('payment_requests')) {
    $where = ['request_date>=?', 'request_date<?'];
    $params = [$startDate, $endExclusive];
    if (store_acc_column_exists('payment_requests', 'deleted_at')) $where[] = 'deleted_at IS NULL';
    try {
      $stmt = db()->prepare('SELECT amount,status FROM payment_requests WHERE ' . implode(' AND ', $where));
      $stmt->execute($params);
      foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
        $amount = max(0.0, store_acc_money($row['amount'] ?? 0));
        $status = strtolower((string)($row['status'] ?? ''));
        if (in_array($status, ['draft', 'submitted', 'approved'], true)) $out['payment_request_pending'] += $amount;
        if ($status === 'paid') $out['payment_request_paid'] += $amount;
      }
    } catch (Throwable $e) {}
  }

  foreach ($out as $key => $value) if (is_float($value)) $out[$key] = store_acc_money($value);
  $out['operating_expenses'] = store_acc_money($out['general_purchase_expenses'] + $out['expense_records']);
  arsort($breakdown);
  $out['expense_breakdown'] = array_map(static fn($category, $amount) => ['category' => $category, 'amount' => store_acc_money($amount)], array_keys($breakdown), array_values($breakdown));
  return $out;
}

function store_acc_hpp_metrics(string $start, string $end, array $sales, array $options = []): array {
  $out = [
    'hpp_sales' => 0.0,
    'hpp_returns' => 0.0,
    'hpp_net' => 0.0,
    'covered_lines' => 0,
    'required_lines' => 0,
    'coverage_percent' => 0.0,
    'is_complete' => false,
  ];
  if (!store_acc_table_exists('stock_ledger') || empty($sales['_transactions'])) return $out;

  $targetSaleIds = [];
  $lineIndex = [];
  foreach ($sales['_transactions'] as $tx) {
    foreach ($tx['rows'] as $line) {
      $saleId = (int)($line['id'] ?? 0);
      if ($saleId <= 0) continue;
      $productType = strtolower((string)($line['product_type'] ?? 'finished_good'));
      $trackStock = (int)($line['track_stock'] ?? 1) === 1;
      if (!$trackStock || $productType === 'service') {
        $lineIndex[$saleId] = ['cost' => 0.0, 'covered' => true, 'line' => $line];
        continue;
      }
      $targetSaleIds[$saleId] = true;
      $lineIndex[$saleId] = ['cost' => null, 'covered' => false, 'line' => $line];
    }
  }

  if ($targetSaleIds) {
    $endDt = store_acc_normalize_datetime($end, true);
    $where = ['sl.created_at<?'];
    $params = [$endDt];
    if (isset($options['branch_id']) && $options['branch_id'] !== null && store_acc_column_exists('stock_ledger', 'branch_id')) {
      $where[] = 'sl.branch_id=?';
      $params[] = (int)$options['branch_id'];
    }
    $purchaseUnitCosts = store_acc_purchase_unit_cost_map($endDt, $options);
    try {
      $stmt = db()->prepare('SELECT sl.id,sl.branch_id,sl.product_id,sl.trans_type,sl.ref_table,sl.ref_id,sl.qty_in,sl.qty_out,sl.unit_cost,sl.created_at FROM stock_ledger sl WHERE ' . implode(' AND ', $where) . ' ORDER BY sl.created_at ASC,sl.id ASC');
      $stmt->execute($params);
      $balances = [];
      foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $movement) {
        $branch = (int)($movement['branch_id'] ?? 0);
        $product = (int)($movement['product_id'] ?? 0);
        $key = $branch . ':' . $product;
        if (!isset($balances[$key])) $balances[$key] = ['qty' => 0.0, 'value' => 0.0, 'avg' => 0.0];
        $bal =& $balances[$key];
        $qtyIn = max(0.0, (float)($movement['qty_in'] ?? 0));
        $qtyOut = max(0.0, (float)($movement['qty_out'] ?? 0));
        $givenCost = max(0.0, (float)($movement['unit_cost'] ?? 0));
        $refTable = strtolower(trim((string)($movement['ref_table'] ?? '')));
        if ($qtyIn > 0 && in_array($refTable, ['purchase_headers','purchase_header','purchases','purchase'], true)) {
          $purchaseKey = $branch . ':' . (int)($movement['ref_id'] ?? 0) . ':' . $product;
          if (isset($purchaseUnitCosts[$purchaseKey])) $givenCost = max(0.0, (float)$purchaseUnitCosts[$purchaseKey]);
        }
        $currentAvg = $bal['qty'] > 0.000001 ? ($bal['value'] / $bal['qty']) : (float)$bal['avg'];
        if ($givenCost <= 0) $givenCost = max(0.0, $currentAvg);

        if ($qtyIn > 0) {
          $bal['qty'] += $qtyIn;
          $bal['value'] += $qtyIn * $givenCost;
          if ($bal['qty'] > 0.000001) $bal['avg'] = $bal['value'] / $bal['qty'];
        }
        if ($qtyOut > 0) {
          $cost = $givenCost > 0 ? $givenCost : max(0.0, (float)$bal['avg']);
          if (strtolower((string)$movement['ref_table']) === 'sales') {
            $saleId = (int)($movement['ref_id'] ?? 0);
            if (isset($targetSaleIds[$saleId])) {
              $existing = $lineIndex[$saleId]['cost'];
              $lineIndex[$saleId]['cost'] = ($existing === null ? 0.0 : (float)$existing) + ($qtyOut * $cost);
              if ($cost > 0) $lineIndex[$saleId]['covered'] = true;
            }
          }
          $bal['qty'] -= $qtyOut;
          $bal['value'] -= $qtyOut * $cost;
          if ($bal['qty'] > 0.000001) {
            $bal['avg'] = max(0.0, $bal['value'] / $bal['qty']);
          } else {
            $bal['qty'] = max(0.0, $bal['qty']);
            $bal['value'] = max(0.0, $bal['value']);
          }
        }
        unset($bal);
      }
    } catch (Throwable $e) {}
  }

  $start = store_acc_normalize_datetime($start);
  $end = store_acc_normalize_datetime($end, true);
  foreach ($sales['_transactions'] as $tx) {
    $soldInRange = store_acc_in_range($tx['sold_at'], $start, $end);
    foreach ($tx['rows'] as $line) {
      $saleId = (int)($line['id'] ?? 0);
      if ($saleId <= 0 || !isset($lineIndex[$saleId])) continue;
      $entry = $lineIndex[$saleId];
      $productType = strtolower((string)($line['product_type'] ?? 'finished_good'));
      $trackStock = (int)($line['track_stock'] ?? 1) === 1;
      $requiresCost = $trackStock && $productType !== 'service';
      if ($soldInRange && $requiresCost) {
        $out['required_lines']++;
        if ($entry['covered']) $out['covered_lines']++;
      }
      $cost = max(0.0, (float)($entry['cost'] ?? 0));
      if ($soldInRange) $out['hpp_sales'] += $cost;

      $isReturned = trim((string)($line['return_reason'] ?? '')) !== '' || strtolower((string)($line['return_status'] ?? 'none')) === 'returned';
      $returnDate = $line['returned_at'] ?? $line['sold_at'] ?? null;
      if ($isReturned && store_acc_in_range($returnDate, $start, $end)) $out['hpp_returns'] += $cost;
    }
  }

  $out['hpp_sales'] = store_acc_money($out['hpp_sales']);
  $out['hpp_returns'] = store_acc_money($out['hpp_returns']);
  $out['hpp_net'] = store_acc_money($out['hpp_sales'] - $out['hpp_returns']);
  $out['coverage_percent'] = $out['required_lines'] > 0 ? round(($out['covered_lines'] / $out['required_lines']) * 100, 1) : 100.0;
  $out['is_complete'] = $out['coverage_percent'] >= 99.9;
  return $out;
}

function store_accounting_metrics(string $start, string $end, array $options = []): array {
  $sales = store_acc_sales_metrics($start, $end, $options);
  $costs = store_acc_store_costs($start, $end, $options);
  $hpp = store_acc_hpp_metrics($start, $end, $sales, $options);
  $grossProfit = store_acc_money($sales['net_sales'] - $hpp['hpp_net']);
  $operatingProfit = store_acc_money($grossProfit - $costs['operating_expenses']);
  return [
    'sales' => $sales,
    'costs' => $costs,
    'hpp' => $hpp,
    'gross_profit' => $grossProfit,
    'operating_profit' => $operatingProfit,
    'profit_is_complete' => (bool)$hpp['is_complete'],
  ];
}
