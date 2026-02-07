<?php
require_once __DIR__ . '/security.php';

function csrf_token(): string {
  return csrf_generate_token();
}

function csrf_verify(string $token): bool {
  return csrf_validate($token);
}
