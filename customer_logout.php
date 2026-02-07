<?php
require_once __DIR__ . '/core/db.php';
require_once __DIR__ . '/core/functions.php';
require_once __DIR__ . '/core/security.php';
require_once __DIR__ . '/core/auth.php';
require_once __DIR__ . '/core/customer_auth.php';

start_secure_session();
ensure_landing_order_tables();
customer_logout();
redirect(base_url('index.php'));
