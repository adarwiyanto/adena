<?php
require_once __DIR__ . '/../../core/api_pairing.php';
pairing_auth('products.read');
$rows=[]; try{ $rows=db()->query("SELECT id,name,category,price,sku,image_path,updated_at FROM products ORDER BY id DESC LIMIT 1000")->fetchAll(PDO::FETCH_ASSOC); }catch(Throwable $e){}
pairing_ok(['data'=>$rows,'count'=>count($rows)]);
