<?php
/**
 * GET /api/auth.php  — verifikasi API token desktop.
 */
require_once __DIR__ . '/helpers.php';

if (($_SERVER['REQUEST_METHOD'] ?? 'GET') !== 'GET') {
    api_err('Method tidak diizinkan.', 405);
}

$token = require_api_token();
api_ok(['token' => $token]);
