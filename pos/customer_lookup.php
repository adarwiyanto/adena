<?php
require_once __DIR__ . '/../core/db.php';
require_once __DIR__ . '/../core/functions.php';
require_once __DIR__ . '/../core/security.php';
require_once __DIR__ . '/../core/auth.php';
require_once __DIR__ . '/../core/rbac.php';
header('Content-Type: application/json; charset=utf-8');
start_secure_session(); require_login(); ensure_landing_order_tables(); ensure_rbac_schema(); require_menu_access('pos','view');
$digits = preg_replace('/\D+/', '', (string)($_GET['phone'] ?? '')) ?? '';
if ($digits !== '' && str_starts_with($digits,'0')) $digits='62'.substr($digits,1); elseif ($digits !== '' && !str_starts_with($digits,'62')) $digits='62'.$digits;
if (strlen($digits) < 9) { echo json_encode(['ok'=>true,'found'=>false]); exit; }
$stmt=db()->prepare('SELECT id,name,phone,gender,loyalty_points FROM customers WHERE phone=? LIMIT 1'); $stmt->execute([$digits]); $c=$stmt->fetch();
echo json_encode(['ok'=>true,'found'=>(bool)$c,'customer'=>$c ?: null]);
