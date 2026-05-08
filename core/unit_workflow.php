<?php
require_once __DIR__ . '/db.php';
require_once __DIR__ . '/functions.php';
require_once __DIR__ . '/inventory.php';

function ensure_unit_workflow_schema(): void {
  static $done = false;
  if ($done) return;
  $done = true;
  try { ensure_inventory_module_schema(); } catch (Throwable $e) {}
  $sqls = [
    "ALTER TABLE branches ADD COLUMN unit_type ENUM('branch','kitchen') NOT NULL DEFAULT 'branch' AFTER branch_name",
    "ALTER TABLE branches ADD COLUMN is_kitchen TINYINT(1) NOT NULL DEFAULT 0 AFTER unit_type",
    "ALTER TABLE branches ADD COLUMN sort_order INT NOT NULL DEFAULT 0 AFTER is_active",
    "ALTER TABLE branches ADD KEY idx_branches_unit_type (unit_type,is_active)",
    "ALTER TABLE sales ADD COLUMN sale_source VARCHAR(30) NOT NULL DEFAULT 'branch_pos' AFTER branch_id",
    "ALTER TABLE sales ADD COLUMN unit_type VARCHAR(30) NULL AFTER sale_source",
    "ALTER TABLE sales ADD KEY idx_sales_unit_date (branch_id,sale_source,sold_at)",
    "ALTER TABLE pos_shifts ADD KEY idx_pos_shifts_branch_status (branch_id,status,opened_at)",
    "ALTER TABLE stock_transfers ADD COLUMN transfer_type VARCHAR(30) NOT NULL DEFAULT 'stock_transfer' AFTER status",
    "ALTER TABLE stock_transfers ADD KEY idx_transfer_from_to_status (from_location_id,to_location_id,status)",
    "ALTER TABLE stock_ledger ADD COLUMN location_id INT NULL AFTER branch_id"
  ];
  foreach ($sqls as $sql) { try { db()->exec($sql); } catch (Throwable $e) {} }
  try { db()->exec("UPDATE branches SET unit_type='kitchen', is_kitchen=1 WHERE LOWER(branch_name) LIKE '%dapur%' OR LOWER(branch_name) LIKE '%kitchen%' OR LOWER(branch_code) LIKE '%DAPUR%' OR LOWER(branch_code) LIKE '%KIT%' OR LOWER(branch_code) LIKE '%KITCHEN%'"); } catch (Throwable $e) {}
  try { db()->exec("UPDATE branches SET unit_type='branch', is_kitchen=0 WHERE unit_type IS NULL OR unit_type='' "); } catch (Throwable $e) {}
  try { db()->exec("INSERT INTO branches (branch_code, branch_name, unit_type, is_kitchen, is_active) SELECT 'DAPUR','Dapur Utama','kitchen',1,1 FROM DUAL WHERE NOT EXISTS (SELECT 1 FROM branches WHERE unit_type='kitchen' OR is_kitchen=1 LIMIT 1)"); } catch (Throwable $e) {}
}

function unit_rows(string $type = ''): array {
  ensure_unit_workflow_schema();
  try {
    if ($type === 'kitchen') {
      $st = db()->query("SELECT * FROM branches WHERE is_active=1 AND (unit_type='kitchen' OR is_kitchen=1) ORDER BY sort_order, branch_name");
    } elseif ($type === 'branch') {
      $st = db()->query("SELECT * FROM branches WHERE is_active=1 AND (unit_type='branch' OR unit_type IS NULL OR unit_type='') AND COALESCE(is_kitchen,0)=0 ORDER BY sort_order, branch_name");
    } else {
      $st = db()->query("SELECT * FROM branches WHERE is_active=1 ORDER BY unit_type DESC, sort_order, branch_name");
    }
    return $st->fetchAll(PDO::FETCH_ASSOC) ?: [];
  } catch (Throwable $e) { return []; }
}

function unit_first_id(string $type = 'branch'): int {
  $rows = unit_rows($type);
  return (int)($rows[0]['id'] ?? active_branch_id());
}

function selected_unit_id(string $type = 'branch'): int {
  $id = (int)($_GET['unit_id'] ?? $_GET['branch_id'] ?? $_POST['unit_id'] ?? $_POST['branch_id'] ?? 0);
  if ($id > 0) return $id;
  return unit_first_id($type);
}

function selected_unit_row(int $id): ?array {
  ensure_unit_workflow_schema();
  try { $st = db()->prepare("SELECT * FROM branches WHERE id=? LIMIT 1"); $st->execute([$id]); $r = $st->fetch(PDO::FETCH_ASSOC); return $r ?: null; } catch (Throwable $e) { return null; }
}

function unit_dashboard_metrics(int $unitId, string $type): array {
  ensure_unit_workflow_schema();
  $out = ['today_sales'=>0,'month_sales'=>0,'today_tx'=>0,'month_tx'=>0,'open_shift'=>null,'stock_low'=>0,'dead_stock'=>[],'payment'=>[],'transfer_pending'=>0,'production_month'=>0,'transfer_month'=>0,'legacy_sales'=>0];
  try {
    $st=db()->prepare("SELECT COALESCE(SUM(total),0) total, COUNT(DISTINCT COALESCE(transaction_group_uuid, transaction_code, id)) tx FROM sales WHERE branch_id=? AND DATE(sold_at)=CURDATE() AND include_in_sales_report=1 AND (return_reason IS NULL OR return_reason='')"); $st->execute([$unitId]); $r=$st->fetch(PDO::FETCH_ASSOC)?:[]; $out['today_sales']=(float)($r['total']??0); $out['today_tx']=(int)($r['tx']??0);
  } catch(Throwable $e) {}
  try {
    $st=db()->prepare("SELECT COALESCE(SUM(total),0) total, COUNT(DISTINCT COALESCE(transaction_group_uuid, transaction_code, id)) tx FROM sales WHERE branch_id=? AND sold_at>=DATE_FORMAT(CURDATE(),'%Y-%m-01') AND include_in_sales_report=1 AND (return_reason IS NULL OR return_reason='')"); $st->execute([$unitId]); $r=$st->fetch(PDO::FETCH_ASSOC)?:[]; $out['month_sales']=(float)($r['total']??0); $out['month_tx']=(int)($r['tx']??0);
  } catch(Throwable $e) {}
  try { $st=db()->prepare("SELECT * FROM pos_shifts WHERE branch_id=? AND status='open' ORDER BY id DESC LIMIT 1"); $st->execute([$unitId]); $out['open_shift']=$st->fetch(PDO::FETCH_ASSOC)?:null; } catch(Throwable $e) {}
  try { $st=db()->prepare("SELECT payment_method, COALESCE(payment_bank,payment_channel_name,'') bank, COALESCE(SUM(total),0) total FROM sales WHERE branch_id=? AND DATE(sold_at)=CURDATE() AND include_in_sales_report=1 GROUP BY payment_method, bank ORDER BY total DESC"); $st->execute([$unitId]); $out['payment']=$st->fetchAll(PDO::FETCH_ASSOC)?:[]; } catch(Throwable $e) {}
  try { $st=db()->prepare("SELECT COUNT(*) c FROM stock_transfers WHERE to_location_id=? AND status IN ('sent','draft')"); $st->execute([$unitId]); $out['transfer_pending']=(int)($st->fetch(PDO::FETCH_ASSOC)['c']??0); } catch(Throwable $e) {}
  try { $st=db()->prepare("SELECT COALESCE(SUM(qty_to_produce),0) q FROM production_headers WHERE branch_id=? AND status='posted' AND production_date>=DATE_FORMAT(CURDATE(),'%Y-%m-01')"); $st->execute([$unitId]); $out['production_month']=(float)($st->fetch(PDO::FETCH_ASSOC)['q']??0); } catch(Throwable $e) {}
  try { $st=db()->prepare("SELECT COUNT(*) c FROM stock_transfers WHERE from_location_id=? AND created_at>=DATE_FORMAT(CURDATE(),'%Y-%m-01')"); $st->execute([$unitId]); $out['transfer_month']=(int)($st->fetch(PDO::FETCH_ASSOC)['c']??0); } catch(Throwable $e) {}
  try { $st=db()->prepare("SELECT COUNT(*) c FROM sales WHERE branch_id IS NULL OR branch_id=0"); $st->execute(); $out['legacy_sales']=(int)($st->fetch(PDO::FETCH_ASSOC)['c']??0); } catch(Throwable $e) {}
  try {
    $st=db()->prepare("SELECT p.id,p.name,COALESCE(SUM(sl.qty_in-sl.qty_out),0) stock, COALESCE(p.reorder_level,0) reorder_level FROM products p LEFT JOIN stock_ledger sl ON sl.product_id=p.id AND sl.branch_id=? WHERE COALESCE(p.track_stock,1)=1 GROUP BY p.id,p.name,p.reorder_level HAVING reorder_level>0 AND stock<=reorder_level LIMIT 20"); $st->execute([$unitId]); $rows=$st->fetchAll(PDO::FETCH_ASSOC)?:[]; $out['stock_low']=count($rows);
  } catch(Throwable $e) {}
  try {
    $st=db()->prepare("SELECT p.name, COALESCE(SUM(s.qty),0) qty FROM products p LEFT JOIN sales s ON s.product_id=p.id AND s.branch_id=? AND s.sold_at>=DATE_SUB(CURDATE(), INTERVAL 30 DAY) WHERE COALESCE(p.show_on_pos,1)=1 GROUP BY p.id,p.name HAVING qty<=0 ORDER BY p.name LIMIT 10"); $st->execute([$unitId]); $out['dead_stock']=$st->fetchAll(PDO::FETCH_ASSOC)?:[];
  } catch(Throwable $e) {}
  return $out;
}

function unit_url(string $section, int $id, string $page = 'index.php'): string {
  $base = $section === 'dapur' ? 'dapur/' : 'cabang/';
  return base_url($base . $page . '?unit_id=' . $id);
}
