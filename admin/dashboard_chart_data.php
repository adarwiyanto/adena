<?php
require_once __DIR__ . '/../core/db.php';
require_once __DIR__ . '/../core/functions.php';
require_once __DIR__ . '/../core/security.php';
require_once __DIR__ . '/../core/auth.php';
require_once __DIR__ . '/../core/rbac.php';
require_once __DIR__ . '/../core/dashboard_charts.php';

start_secure_session();
require_login();
ensure_rbac_schema();
require_menu_access('dashboard', 'view');

header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store');

echo json_encode(dashboard_chart_payload($_GET), JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
