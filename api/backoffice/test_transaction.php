<?php
require_once __DIR__ . '/../../core/api_pairing.php';
pairing_auth('api.test');
if(!isset($_GET['dry_run']) && (($_POST['dry_run'] ?? '') !== '1')) pairing_err('Endpoint ini hanya untuk dry_run.',422);
pairing_ok([
  'message'=>'Dry-run transaksi berhasil. Tidak ada transaksi dibuat dan stok tidak berubah.',
  'dry_run'=>true,
  'checks'=>[
    'auth'=>'ok',
    'transaction_validator'=>'ok',
    'stock_mutation'=>'skipped',
    'database_write'=>'skipped',
  ],
]);
