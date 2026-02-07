<?php
require_once __DIR__ . '/../core/functions.php';
require_once __DIR__ . '/../core/security.php';
require_once __DIR__ . '/../core/auth.php';
start_secure_session();
require_login();
logout();
redirect(base_url('index.php'));
