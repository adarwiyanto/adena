<?php
require_once __DIR__ . '/db.php';
require_once __DIR__ . '/functions.php';
require_once __DIR__ . '/../config/upload.php';

function attendance_now(): DateTimeImmutable {
  return new DateTimeImmutable('now', new DateTimeZone('Asia/Jakarta'));
}

function attendance_today_for_user(int $userId): ?array {
  $stmt = db()->prepare("SELECT * FROM employee_attendance WHERE user_id=? AND attend_date=? LIMIT 1");
  $stmt->execute([$userId, app_today_jakarta()]);
  $row = $stmt->fetch();
  return $row ?: null;
}

function attendance_upload_dir(string $dateYmd): string {
  $year = substr($dateYmd, 0, 4);
  $month = substr($dateYmd, 5, 2);
  $dir = UPLOAD_BASE . 'attendance' . DIRECTORY_SEPARATOR . $year . DIRECTORY_SEPARATOR . $month . DIRECTORY_SEPARATOR;
  if (!is_dir($dir)) {
    @mkdir($dir, 0750, true);
  }
  return $dir;
}

function resolve_schedule_for_date(int $userId, string $date): array {
  $stmt = db()->prepare("SELECT user_id, schedule_date, start_time, end_time, grace_minutes, is_off, allow_checkin_before_minutes, overtime_before_minutes, overtime_after_minutes FROM employee_schedule_overrides WHERE user_id=? AND schedule_date=? LIMIT 1");
  $stmt->execute([$userId, $date]);
  $override = $stmt->fetch();
  if ($override) {
    return ['source' => 'override'] + $override;
  }

  $weekday = (int)(new DateTimeImmutable($date, new DateTimeZone('Asia/Jakarta')))->format('N');
  $stmt = db()->prepare("SELECT user_id, weekday, start_time, end_time, grace_minutes, is_off, allow_checkin_before_minutes, overtime_before_minutes, overtime_after_minutes FROM employee_schedule_weekly WHERE user_id=? AND weekday=? LIMIT 1");
  $stmt->execute([$userId, $weekday]);
  $weekly = $stmt->fetch();
  if ($weekly) {
    return ['source' => 'weekly'] + $weekly;
  }

  return ['source' => 'none', 'start_time' => null, 'end_time' => null, 'grace_minutes' => 0, 'is_off' => 0, 'allow_checkin_before_minutes' => 0, 'overtime_before_minutes' => 0, 'overtime_after_minutes' => 0];
}

function getScheduleForDate(int $userId, string $date): array {
  return resolve_schedule_for_date($userId, $date);
}
