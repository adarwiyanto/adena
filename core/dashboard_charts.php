<?php
require_once __DIR__ . '/db.php';
require_once __DIR__ . '/functions.php';

function dashboard_tx_expr(): string {
  return "COALESCE(NULLIF(transaction_code, ''), CONCAT('LEGACY-', id))";
}

function dashboard_chart_resolve_range(array $params = [], string $mode = 'standard'): array {
  date_default_timezone_set('Asia/Jakarta');
  $today = new DateTimeImmutable('today');
  $range = (string)($params['range'] ?? ($mode === 'weekday' ? 'weekly' : 'today'));
  $startInput = (string)($params['start'] ?? '');
  $endInput = (string)($params['end'] ?? '');

  if ($mode === 'weekday') {
    switch ($range) {
      case 'monthly':
        $start = $today->modify('first day of this month');
        $end = $start->modify('+1 month');
        $label = 'Monthly';
        break;
      case 'yoy':
        $start = $today->modify('-12 months');
        $end = $today->modify('+1 day');
        $label = 'YoY';
        break;
      case 'all_time':
        $row = db()->query("SELECT MIN(sold_at) first_date FROM sales WHERE sold_at IS NOT NULL")->fetch();
        $first = (string)($row['first_date'] ?? '');
        $start = $first !== '' ? (new DateTimeImmutable($first))->setTime(0, 0, 0) : $today;
        $end = $today->modify('+1 day');
        $label = 'All time';
        break;
      case 'custom':
        $parsedStart = DateTimeImmutable::createFromFormat('!Y-m-d', $startInput);
        $parsedEnd = DateTimeImmutable::createFromFormat('!Y-m-d', $endInput);
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
          $range = 'weekly';
          $start = $today->modify('-6 days');
          $end = $today->modify('+1 day');
          $label = 'Weekly';
        }
        break;
      case 'weekly':
      default:
        $range = 'weekly';
        $start = $today->modify('-6 days');
        $end = $today->modify('+1 day');
        $label = 'Weekly';
        break;
    }
  } else {
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
        $parsedStart = DateTimeImmutable::createFromFormat('!Y-m-d', $startInput);
        $parsedEnd = DateTimeImmutable::createFromFormat('!Y-m-d', $endInput);
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
  }

  return [
    'range' => $range,
    'start' => $start,
    'end' => $end,
    'label' => $label,
    'days' => max(1, (int)$end->diff($start)->days),
  ];
}

function dashboard_hourly_payload(array $params = []): array {
  $resolved = dashboard_chart_resolve_range($params, 'standard');
  $startStr = $resolved['start']->format('Y-m-d H:i:s');
  $endStr = $resolved['end']->format('Y-m-d H:i:s');
  $days = (int)$resolved['days'];
  $txExpr = dashboard_tx_expr();

  $hourlyCounts = array_fill(0, 24, 0);
  $stmt = db()->prepare("\n    SELECT HOUR(tx_time) h, COUNT(*) c\n    FROM (\n      SELECT {$txExpr} AS tx_code, MIN(sold_at) AS tx_time\n      FROM sales\n      WHERE return_reason IS NULL\n        AND is_active_revision=1\n        AND sold_at >= ?\n        AND sold_at < ?\n      GROUP BY {$txExpr}\n    ) t\n    GROUP BY HOUR(tx_time)\n    ORDER BY h ASC\n  ");
  $stmt->execute([$startStr, $endStr]);
  foreach ($stmt->fetchAll() as $row) {
    $hour = (int)($row['h'] ?? 0);
    if ($hour >= 0 && $hour <= 23) $hourlyCounts[$hour] = (int)($row['c'] ?? 0);
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

  return [
    'chart' => 'hourly',
    'range' => $resolved['range'],
    'label' => $resolved['label'],
    'start' => $resolved['start']->format('Y-m-d'),
    'end' => $resolved['end']->modify('-1 day')->format('Y-m-d'),
    'days' => $days,
    'hourly' => $hourly,
    'max_hourly' => $maxHourly,
  ];
}

function dashboard_weekday_occurrences(DateTimeImmutable $start, DateTimeImmutable $end): array {
  $occurrences = array_fill(1, 7, 0);
  for ($day = $start; $day < $end; $day = $day->modify('+1 day')) {
    $dow = (int)$day->format('w') + 1; // PHP 0=Sunday, MySQL DAYOFWEEK 1=Sunday.
    $occurrences[$dow]++;
  }
  return $occurrences;
}

function dashboard_weekday_payload(array $params = []): array {
  $resolved = dashboard_chart_resolve_range($params, 'weekday');
  $startStr = $resolved['start']->format('Y-m-d H:i:s');
  $endStr = $resolved['end']->format('Y-m-d H:i:s');
  $txExpr = dashboard_tx_expr();

  $weekdayLabels = [1 => 'Minggu', 2 => 'Senin', 3 => 'Selasa', 4 => 'Rabu', 5 => 'Kamis', 6 => 'Jumat', 7 => 'Sabtu'];
  $counts = array_fill(1, 7, 0);
  $occurrences = dashboard_weekday_occurrences($resolved['start'], $resolved['end']);

  $stmt = db()->prepare("\n    SELECT DAYOFWEEK(tx_time) weekday_no, COUNT(*) c\n    FROM (\n      SELECT {$txExpr} AS tx_code, MIN(sold_at) AS tx_time\n      FROM sales\n      WHERE return_reason IS NULL\n        AND is_active_revision=1\n        AND sold_at >= ?\n        AND sold_at < ?\n      GROUP BY {$txExpr}\n    ) t\n    GROUP BY DAYOFWEEK(tx_time)\n    ORDER BY weekday_no ASC\n  ");
  $stmt->execute([$startStr, $endStr]);
  foreach ($stmt->fetchAll() as $row) {
    $dayNo = (int)($row['weekday_no'] ?? 0);
    if ($dayNo >= 1 && $dayNo <= 7) $counts[$dayNo] = (int)($row['c'] ?? 0);
  }

  $weekday = [];
  $maxWeekday = 0.0;
  foreach ($weekdayLabels as $dayNo => $label) {
    $count = (int)$counts[$dayNo];
    $avg = $occurrences[$dayNo] > 0 ? $count / $occurrences[$dayNo] : 0;
    if ($avg > $maxWeekday) $maxWeekday = $avg;
    $weekday[] = [
      'weekday' => $dayNo,
      'label' => $label,
      'count' => $count,
      'occurrences' => (int)$occurrences[$dayNo],
      'avg' => $avg,
      'formatted' => format_number_custom($avg, 1, ['decimal_separator' => '.', 'thousand_separator' => ',', 'trim_trailing_zero' => true]),
    ];
  }

  return [
    'chart' => 'weekday',
    'range' => $resolved['range'],
    'label' => $resolved['label'],
    'start' => $resolved['start']->format('Y-m-d'),
    'end' => $resolved['end']->modify('-1 day')->format('Y-m-d'),
    'days' => (int)$resolved['days'],
    'weekday' => $weekday,
    'max_weekday' => $maxWeekday,
  ];
}

function dashboard_chart_payload(array $params = []): array {
  $chart = (string)($params['chart'] ?? 'all');
  if ($chart === 'hourly') return dashboard_hourly_payload($params);
  if ($chart === 'weekday') return dashboard_weekday_payload($params);
  $hourly = dashboard_hourly_payload($params);
  $weekday = dashboard_weekday_payload(['range' => 'weekly']);
  return array_merge($hourly, [
    'weekday' => $weekday['weekday'],
    'max_weekday' => $weekday['max_weekday'],
    'weekday_label' => $weekday['label'],
    'weekday_days' => $weekday['days'],
  ]);
}
