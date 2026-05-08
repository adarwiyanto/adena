<?php
require_once __DIR__ . '/../core/db.php';
require_once __DIR__ . '/../core/functions.php';
require_once __DIR__ . '/../core/auth.php';
require_once __DIR__ . '/../core/rbac.php';
require_once __DIR__ . '/../core/branch_portal.php';
start_secure_session();
require_login();
$u = current_user() ?: [];
$role = (string)(resolve_user_role($u)['role_key'] ?? '');
if (!in_array($role, ['owner','admin'], true)) { http_response_code(403); exit('Forbidden'); }
ensure_branch_price_schema();
header('Content-Type: text/plain; charset=utf-8');
echo "Patch 1.4.3 branch portal schema OK.\n";
echo "- api_tokens.branch_id checked\n";
echo "- sales.branch_id checked\n";
echo "- pos_shifts.branch_id checked\n";
echo "- branch_product_prices checked\n";
echo "- stock_locations for active branches checked\n";
