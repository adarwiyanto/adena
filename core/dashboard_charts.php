<?php
require_once __DIR__ . '/db.php';
require_once __DIR__ . '/functions.php';

function dashboard_chart_resolve_range(array $params = []): array {
  date_default_timezone_set('Asia/Jakarta');
  $today = new DateTimeImmutable('today');
  $range = (string)($params['range'] ?? 'today');
  $startInput = (string)($params['start'] ?? '');
  $endInput = (string)($params['end'] ?? '');

  switch ($range) {
    case 'yesterday':
      $start = $today->modify('-1 day');
      $end = $today;
      $label = 'Kemarin';
      break;
    case 'last7':
      $start = $today->modify('-6 days');
      $end = $today->modify('+1 day');
      $label = '7 Hari Terakhir';
      break;
    case 'this_month':
      $start = $today->modify('first day of this month');
      $end = $start->modify('+1 month');
      $label = 'Bulan Ini';
      break;
    case 'last_month':
      $start = $today->modify('first day of last month');
      $end = $start->modify('+1 month');
      $label = 'Bulan Lalu';
      break;
    case 'custom':
      $parsedStart = DateTimeImmutable::createFromFormat('Y-m-d', $startInput);
      $parsedEnd = DateTimeImmutable::createFromFormat('Y-m-d', $endInput);
      if ($parsedStart && $parsedEnd) {
        if ($parsedStart > $parsedEnd) {
          $tmp = $parsedStart;
          $parsedStart = $parsedEnd;
          $parsedEnd = $tmp;
        }
        $start = $parsedStart;
        $end = $parsedEnd->modify('+1 day');
        $label = 'Custom';
      } else {
        $range = 'today';
        $start = $today;
        $end = $today->modify('+1 day');
        $label = 'Hari Ini';
      }
      break;
    case 'today':
    default:
      $range = 'today';
      $start = $today;
      $end = $today->modify('+1 day');
      $label = 'Hari Ini';
      break;
  }

  return [
    'range' => $range,
    'start' => $start,
    'end' => $end,
    'label' => $label,
    'days' => max(1, (int)$end->diff($start)->days),
  ];
}

function dashboard_chart_payload(array $params = []): array {
  $resolved = dashboard_chart_resolve_range($params);
  $start = $resolved['start'];
  $end = $resolved['end'];
  $startStr = $start->format('Y-m-d H:i:s');
  $endStr = $end->format('Y-m-d H:i:s');
  $days = (int)$resolved['days'];

  $hourlyCounts = array_fill(0, 24, 0);
  $stmt = db()->prepare("\n    SELECT HOUR(tx_time) h, COUNT(*) c\n    FROM (\n      SELECT COALESCE(NULLIF(transaction_code, ''), CONCAT('LEGACY-', id)) AS tx_code,\n             MIN(sold_at) AS tx_time\n      FROM sales\n      WHERE return_reason IS NULL\n        AND is_active_revision=1\n        AND sold_at >= ?\n        AND sold_at < ?\n      GROUP BY COALESCE(NULLIF(transaction_code, ''), CONCAT('LEGACY-', id))\n    ) t\n    GROUP BY HOUR(tx_time)\n    ORDER BY h ASC\n  ");
  $stmt->execute([$startStr, $endStr]);
  foreach ($stmt->fetchAll() as $row) {
    $hour = (int)($row['h'] ?? 0);
    if ($hour >= 0 && $hour <= 23) {
      $hourlyCounts[$hour] = (int)($row['c'] ?? 0);
    }
  }

  $hourly = [];
  $maxHourly = 0.0;
  foreach ($hourlyCounts as $hour => $count) {
    $avg = $days > 0 ? $count / $days : 0;
    if ($avg > $maxHourly) $maxHourly = $avg;
    $hourly[] = [
      'hour' => $hour,
      'label' => str_pad((string)$hour, 2, '0', STR_PAD_LEFT) . ':00',
      'count' => $count,
      'avg' => $avg,
      'formatted' => format_number_custom($avg, 1, ['decimal_separator' => '.', 'thousand_separator' => ',', 'trim_trailing_zero' => true]),
    ];
  }

  $dailyMap = [];
  $stmt = db()->prepare("\n    SELECT DATE(tx_time) d, COUNT(*) c\n    FROM (\n      SELECT COALESCE(NULLIF(transaction_code, ''), CONCAT('LEGACY-', id)) AS tx_code,\n             MIN(sold_at) AS tx_time\n      FROM sales\n      WHERE return_reason IS NULL\n        AND is_active_revision=1\n        AND sold_at >= ?\n        AND sold_at < ?\n      GROUP BY COALESCE(NULLIF(transaction_code, ''), CONCAT('LEGACY-', id))\n    ) t\n    GROUP BY DATE(tx_time)\n    ORDER BY d ASC\n  ");
  $stmt->execute([$startStr, $endStr]);
  foreach ($stmt->fetchAll() as $row) {
    $dailyMap[(string)$row['d']] = (int)($row['c'] ?? 0);
  }

  $daily = [];
  $maxDaily = 0;
  for ($day = $start; $day < $end; $day = $day->modify('+1 day')) {
    $key = $day->format('Y-m-d');
    $count = (int)($dailyMap[$key] ?? 0);
    if ($count > $maxDaily) $maxDaily = $count;
    $daily[] = [
      'date' => $key,
      'label' => $day->format('d/m'),
      'count' => $count,
      'formatted' => format_number_custom($count, 0, ['decimal_separator' => '.', 'thousand_separator' => ',']),
    ];
  }

  return [
    'range' => $resolved['range'],
    'label' => $resolved['label'],
    'start' => $start->format('Y-m-d'),
    'end' => $end->modify('-1 day')->format('Y-m-d'),
    'days' => $days,
    'hourly' => $hourly,
    'max_hourly' => $maxHourly,
    'daily' => $daily,
    'max_daily' => $maxDaily,
  ];
}
