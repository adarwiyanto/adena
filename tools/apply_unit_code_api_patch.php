<?php
require_once __DIR__ . '/../core/db.php';
function col_exists(PDO $pdo, string $table, string $col): bool { $st=$pdo->prepare("SHOW COLUMNS FROM `$table` LIKE ?"); $st->execute([$col]); return (bool)$st->fetch(PDO::FETCH_ASSOC); }
$pdo = db();
if (!col_exists($pdo, 'api_tokens', 'unit_code')) { $pdo->exec("ALTER TABLE api_tokens ADD COLUMN unit_code VARCHAR(40) NULL AFTER branch_id"); }
try { $pdo->exec("ALTER TABLE api_tokens ADD INDEX idx_api_tokens_unit_code (unit_code)"); } catch (Throwable $e) {}
try { $pdo->exec("UPDATE api_tokens SET unit_code=UPPER(device_code) WHERE (unit_code IS NULL OR unit_code='') AND device_code IS NOT NULL AND device_code<>''"); } catch (Throwable $e) {}
try { $pdo->exec("UPDATE api_tokens t LEFT JOIN branches b ON b.id=t.branch_id SET t.unit_code=b.branch_code WHERE (t.unit_code IS NULL OR t.unit_code='') AND b.branch_code IS NOT NULL"); } catch (Throwable $e) {}
$set=$pdo->prepare("INSERT INTO settings (`key`,`value`) VALUES (?,?) ON DUPLICATE KEY UPDATE `value`=VALUES(`value`)");
$active=$pdo->query("SELECT id, branch_code, branch_name, unit_type FROM branches WHERE is_active=1 ORDER BY id LIMIT 1")->fetch(PDO::FETCH_ASSOC) ?: [];
$set->execute(['active_unit_code', (string)($active['branch_code'] ?? 'BLT')]);
$set->execute(['unit_code', (string)($active['branch_code'] ?? 'BLT')]);
$set->execute(['unit_name', (string)($active['branch_name'] ?? 'Belitung')]);
$set->execute(['active_unit_type', (string)($active['unit_type'] ?? 'branch')]);
echo "OK - unit_code API patch applied\n";
