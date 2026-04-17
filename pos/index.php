<?php
require_once __DIR__ . '/../core/db.php';
require_once __DIR__ . '/../core/functions.php';
require_once __DIR__ . '/../core/security.php';
require_once __DIR__ . '/../core/auth.php';
require_once __DIR__ . '/../core/csrf.php';
require_once __DIR__ . '/../core/inventory.php';
require_once __DIR__ . '/../lib/upload_secure.php';

start_secure_session();
require_login();
ensure_landing_order_tables();
ensure_loyalty_rewards_table();
ensure_sales_transaction_code_column();
ensure_sales_user_column();
ensure_pos_print_jobs_table();
ensure_inventory_module_schema();

$appName = app_config()['app']['name'];
$storeName = setting('store_name', $appName);
$storeSubtitle = setting('store_subtitle', '');
$isAndroidApp = is_android_app_request();
$me = current_user();
$isOwner = (string)($me['role'] ?? '') === 'owner';
$products = db()->query("SELECT id, name, price, image_path, product_type, track_stock, allow_bom FROM products WHERE show_on_pos = 1 ORDER BY name ASC")->fetchAll();
$hasProducts = !empty($products);
$productsById = [];
foreach ($products as $p) {
  $productsById[(int)$p['id']] = $p;
}

$cart = $_SESSION['pos_cart'] ?? [];
$rewardCart = $_SESSION['pos_reward_cart'] ?? [];
$bypassItems = $_SESSION['pos_bypass_items'] ?? [];
$activeOrderId = $_SESSION['pos_order_id'] ?? null;

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
  csrf_check();
  $action = $_POST['action'] ?? '';
  $productId = (int)($_POST['product_id'] ?? 0);
  $rewardId = (int)($_POST['reward_id'] ?? 0);

  try {
    if (in_array($action, ['add','inc','dec','remove','toggle_bypass'], true)) {
      if ($productId <= 0 || empty($productsById[$productId])) {
        throw new Exception('Produk tidak ditemukan.');
      }
    }
    if (in_array($action, ['claim_reward','remove_reward'], true)) {
      if ($rewardId <= 0) {
        throw new Exception('Reward tidak ditemukan.');
      }
      if (empty($activeOrderId)) {
        throw new Exception('Reward hanya tersedia untuk pesanan aktif.');
      }
    }

    if ($action === 'new_transaction') {
      $cart = [];
      $rewardCart = [];
      $bypassItems = [];
      unset($_SESSION['pos_receipt']);
      if (!empty($_SESSION['pos_order_id'])) {
        $orderId = (int)$_SESSION['pos_order_id'];
        $stmt = db()->prepare("UPDATE orders SET status='pending' WHERE id=?");
        $stmt->execute([$orderId]);
        unset($_SESSION['pos_order_id']);
      }
      $_SESSION['pos_notice'] = 'Transaksi baru siap dibuat.';
    } elseif ($action === 'add') {
      $cart[$productId] = ($cart[$productId] ?? 0) + 1;
      $_SESSION['pos_notice'] = 'Produk ditambahkan ke keranjang.';
    } elseif ($action === 'inc') {
      $cart[$productId] = ($cart[$productId] ?? 1) + 1;
      $_SESSION['pos_notice'] = 'Jumlah produk ditambah.';
    } elseif ($action === 'dec') {
      $current = (int)($cart[$productId] ?? 1);
      if ($current <= 1) {
        unset($cart[$productId]);
        unset($bypassItems[$productId]);
      } else {
        $cart[$productId] = $current - 1;
      }
      $_SESSION['pos_notice'] = 'Jumlah produk dikurangi.';
    } elseif ($action === 'remove') {
      unset($cart[$productId]);
      unset($bypassItems[$productId]);
      $_SESSION['pos_notice'] = 'Produk dihapus dari keranjang.';
    } elseif ($action === 'toggle_bypass') {
      if (!$isOwner) {
        throw new Exception('Hanya owner yang dapat bypass BOM/stok.');
      }
      if ($productId <= 0 || empty($productsById[$productId]) || !isset($cart[$productId])) {
        throw new Exception('Produk tidak ditemukan di keranjang.');
      }
      if (!empty($bypassItems[$productId])) {
        unset($bypassItems[$productId]);
        $_SESSION['pos_notice'] = 'Bypass BOM/Stok dinonaktifkan.';
      } else {
        $bypassItems[$productId] = 1;
        $_SESSION['pos_notice'] = 'Bypass BOM/Stok diaktifkan.';
      }
    } elseif ($action === 'load_order') {
      $orderId = (int)($_POST['order_id'] ?? 0);
      if ($orderId <= 0) {
        throw new Exception('Pesanan tidak ditemukan.');
      }
      if (!empty($cart)) {
        throw new Exception('Kosongkan keranjang terlebih dahulu.');
      }
      $db = db();
      $stmt = $db->prepare("
        SELECT o.id, o.order_code, o.status, c.name, COALESCE(c.phone, c.email) AS contact
        FROM orders o
        JOIN customers c ON c.id = o.customer_id
        WHERE o.id = ? AND o.status = 'pending'
        LIMIT 1
      ");
      $stmt->execute([$orderId]);
      $order = $stmt->fetch();
      if (!$order) {
        throw new Exception('Pesanan tidak tersedia.');
      }
      $stmt = $db->prepare("
        SELECT oi.product_id, oi.qty
        FROM order_items oi
        WHERE oi.order_id = ?
      ");
      $stmt->execute([$orderId]);
      $items = $stmt->fetchAll();
      if (empty($items)) {
        throw new Exception('Item pesanan kosong.');
      }
      foreach ($items as $item) {
        $pid = (int)$item['product_id'];
        $qty = (int)$item['qty'];
        if ($pid > 0 && $qty > 0) {
          $cart[$pid] = $qty;
        }
      }
      foreach (array_keys($bypassItems) as $bypassPid) {
        if (empty($cart[(int)$bypassPid])) unset($bypassItems[(int)$bypassPid]);
      }
      $stmt = $db->prepare("UPDATE orders SET status='processing' WHERE id=?");
      $stmt->execute([$orderId]);
      $_SESSION['pos_order_id'] = $orderId;
      $rewardCart = [];
      $_SESSION['pos_notice'] = 'Pesanan ' . $order['order_code'] . ' dimuat ke keranjang.';
    } elseif ($action === 'claim_reward') {
      $db = db();
      $stmt = $db->prepare("
        SELECT o.id, c.id AS customer_id, c.loyalty_points
        FROM orders o
        JOIN customers c ON c.id = o.customer_id
        WHERE o.id = ?
        LIMIT 1
      ");
      $stmt->execute([(int)$activeOrderId]);
      $order = $stmt->fetch();
      if (!$order) {
        throw new Exception('Pesanan tidak ditemukan.');
      }
      $stmt = $db->prepare("
        SELECT lr.id, lr.product_id, lr.points_required, p.name
        FROM loyalty_rewards lr
        JOIN products p ON p.id = lr.product_id
        WHERE lr.id = ?
        LIMIT 1
      ");
      $stmt->execute([$rewardId]);
      $reward = $stmt->fetch();
      if (!$reward) {
        throw new Exception('Reward tidak tersedia.');
      }
      $currentPoints = (int)($order['loyalty_points'] ?? 0);
      $pointsRequired = (int)$reward['points_required'];
      if ($currentPoints < $pointsRequired) {
        throw new Exception('Poin tidak mencukupi untuk klaim reward.');
      }
      $stmt = $db->prepare("UPDATE customers SET loyalty_points = loyalty_points - ? WHERE id = ?");
      $stmt->execute([$pointsRequired, (int)$order['customer_id']]);
      $rewardCart[$rewardId] = ($rewardCart[$rewardId] ?? 0) + 1;
      $_SESSION['pos_notice'] = 'Reward ditambahkan ke keranjang sebagai gratis.';
    } elseif ($action === 'remove_reward') {
      if (empty($rewardCart[$rewardId])) {
        throw new Exception('Reward tidak ada di keranjang.');
      }
      $db = db();
      $stmt = $db->prepare("
        SELECT o.id, c.id AS customer_id
        FROM orders o
        JOIN customers c ON c.id = o.customer_id
        WHERE o.id = ?
        LIMIT 1
      ");
      $stmt->execute([(int)$activeOrderId]);
      $order = $stmt->fetch();
      if (!$order) {
        throw new Exception('Pesanan tidak ditemukan.');
      }
      $stmt = $db->prepare("SELECT points_required FROM loyalty_rewards WHERE id = ? LIMIT 1");
      $stmt->execute([$rewardId]);
      $reward = $stmt->fetch();
      if (!$reward) {
        throw new Exception('Reward tidak tersedia.');
      }
      $qty = (int)$rewardCart[$rewardId];
      unset($rewardCart[$rewardId]);
      $refundPoints = (int)$reward['points_required'] * $qty;
      $stmt = $db->prepare("UPDATE customers SET loyalty_points = loyalty_points + ? WHERE id = ?");
      $stmt->execute([$refundPoints, (int)$order['customer_id']]);
      $_SESSION['pos_notice'] = 'Reward dihapus dari keranjang dan poin dikembalikan.';
    } elseif ($action === 'checkout') {
      if (empty($cart) && empty($rewardCart)) throw new Exception('Keranjang masih kosong.');
      $paymentMethod = $_POST['payment_method'] ?? '';
      if (!in_array($paymentMethod, ['cash', 'qris'], true)) {
        throw new Exception('Pilih metode pembayaran.');
      }
      $paymentProofPath = null;
      if ($paymentMethod === 'qris') {
        if (empty($_FILES['payment_proof']['name'] ?? '')) {
          throw new Exception('Bukti pembayaran QRIS wajib diunggah.');
        }
        $upload = upload_secure($_FILES['payment_proof'], 'image');
        if (empty($upload['ok'])) {
          throw new Exception($upload['error'] ?? 'Gagal mengunggah bukti pembayaran.');
        }
        $paymentProofPath = $upload['name'];
      }
      $db = db();
      $db->beginTransaction();
      $branchId = active_branch_id();
      $transactionCode = 'TRX-' . date('YmdHis') . '-' . strtoupper(bin2hex(random_bytes(2)));
      $stmt = $db->prepare("INSERT INTO sales (transaction_code, product_id, qty, price_each, total, payment_method, payment_proof_path, created_by) VALUES (?,?,?,?,?,?,?,?)");
      $receiptItems = [];
      $receiptTotal = 0.0;
      $autoProductionIds = [];

      foreach ($cart as $pid => $qty) {
        if (empty($productsById[$pid])) continue;
        $product = $productsById[$pid];
        $qty = (float)$qty;
        if ($qty <= 0) continue;
        $isBypass = $isOwner && !empty($bypassItems[(int)$pid]);
        if ($isBypass) {
          continue;
        }
        if ((string)($product['product_type'] ?? 'finished_good') !== 'finished_good' || (int)($product['track_stock'] ?? 1) !== 1) {
          continue;
        }

        $stmtLock = $db->prepare("SELECT id FROM products WHERE id=? FOR UPDATE");
        $stmtLock->execute([(int)$pid]);
        $currentFgStock = branch_stock($branchId, (int)$pid);
        if ($currentFgStock >= $qty) {
          continue;
        }
        $shortage = $qty - $currentFgStock;
        if (setting('pos_autoproduction_enabled', '1') !== '1') {
          throw new Exception('Stok barang jadi tidak cukup dan auto-production POS nonaktif.');
        }
        $bom = get_active_bom_for_product((int)$pid, $branchId);
        if (!$bom) {
          throw new Exception('Stok barang jadi tidak cukup dan BOM aktif tidak ditemukan.');
        }
        $requirements = explode_bom_requirements((int)$bom['id'], $shortage);
        foreach ($requirements as $req) {
          $stmtLock->execute([(int)$req['material_product_id']]);
          $materialStock = branch_stock($branchId, (int)$req['material_product_id']);
          if ($materialStock < (float)$req['required_qty']) {
            throw new Exception('Stok barang jadi kurang dan bahan baku auto-production tidak mencukupi.');
          }
        }
        $productionId = create_production_with_items($db, [
          'production_no' => 'APR-' . date('YmdHis') . '-' . strtoupper(bin2hex(random_bytes(2))),
          'branch_id' => $branchId,
          'bom_id' => (int)$bom['id'],
          'finished_product_id' => (int)$pid,
          'production_date' => date('Y-m-d'),
          'qty_to_produce' => $shortage,
          'status' => 'draft',
          'mode_source' => 'pos_auto',
          'notes' => 'Auto production from POS checkout',
          'created_by' => (int)($me['id'] ?? 0),
        ], $requirements);
        post_production($productionId, (int)($me['id'] ?? 0), 'pos_sale_autoproduction_consume', 'pos_sale_autoproduction_output');
        $autoProductionIds[] = $productionId;
      }

      foreach ($cart as $pid => $qty) {
        if (empty($productsById[$pid])) {
          throw new Exception('Produk tidak ditemukan saat checkout.');
        }
        $isBypass = $isOwner && !empty($bypassItems[(int)$pid]);
        $qty = (int)$qty;
        if ($qty <= 0) {
          throw new Exception('Jumlah produk tidak valid.');
        }
        $price = (float)$productsById[$pid]['price'];
        $total = $price * $qty;
        $stmt->execute([$transactionCode, (int)$pid, $qty, $price, $total, $paymentMethod, $paymentProofPath, (int)($me['id'] ?? 0)]);
        $stmtUpdateBranch = $db->prepare("UPDATE sales SET branch_id=? WHERE id=?");
        $saleId = (int)$db->lastInsertId();
        $stmtUpdateBranch->execute([$branchId, $saleId]);

        if ((string)($productsById[$pid]['product_type'] ?? 'finished_good') !== 'service' && (int)($productsById[$pid]['track_stock'] ?? 1) === 1) {
          $stockNow = branch_stock($branchId, (int)$pid);
          if (!$isBypass && $stockNow < $qty && setting('pos_autoproduction_allow_negative', '0') !== '1') {
            throw new Exception('Stok tidak cukup untuk menyelesaikan penjualan POS.');
          }
          add_stock_ledger([
            'branch_id' => $branchId,
            'product_id' => (int)$pid,
            'trans_type' => 'pos_sale',
            'ref_table' => 'sales',
            'ref_id' => $saleId,
            'qty_in' => 0,
            'qty_out' => $qty,
            'unit_cost' => null,
            'note' => 'Penjualan POS',
            'created_by' => (int)($me['id'] ?? 0),
          ]);
        }
        $receiptItems[] = [
          'name' => $productsById[$pid]['name'],
          'qty' => $qty,
          'price' => $price,
          'subtotal' => $total,
          'is_reward' => false,
        ];
        $receiptTotal += $total;
      }
      if (!empty($rewardCart)) {
        $rewardIds = array_map('intval', array_keys($rewardCart));
        $placeholders = implode(',', array_fill(0, count($rewardIds), '?'));
        $stmtReward = $db->prepare("
          SELECT lr.id, lr.product_id, p.name
          FROM loyalty_rewards lr
          JOIN products p ON p.id = lr.product_id
          WHERE lr.id IN ($placeholders)
        ");
        $stmtReward->execute($rewardIds);
        $rewardRows = [];
        foreach ($stmtReward->fetchAll() as $reward) {
          $rewardRows[(int)$reward['id']] = $reward;
        }
        foreach ($rewardCart as $rid => $qty) {
          $reward = $rewardRows[(int)$rid] ?? null;
          if (!$reward) {
            throw new Exception('Reward tidak ditemukan saat checkout.');
          }
          $pid = (int)$reward['product_id'];
          $qty = (int)$qty;
          if ($qty <= 0) {
            continue;
          }
          $stmt->execute([$transactionCode, $pid, $qty, 0, 0, $paymentMethod, $paymentProofPath, (int)($me['id'] ?? 0)]);
          $saleId = (int)$db->lastInsertId();
          $stmtUpdateBranch = $db->prepare("UPDATE sales SET branch_id=? WHERE id=?");
          $stmtUpdateBranch->execute([$branchId, $saleId]);
          add_stock_ledger([
            'branch_id' => $branchId,
            'product_id' => $pid,
            'trans_type' => 'pos_sale',
            'ref_table' => 'sales',
            'ref_id' => $saleId,
            'qty_in' => 0,
            'qty_out' => $qty,
            'unit_cost' => null,
            'note' => 'Penjualan POS reward',
            'created_by' => (int)($me['id'] ?? 0),
          ]);
          $receiptItems[] = [
            'name' => $reward['name'],
            'qty' => $qty,
            'price' => 0,
            'subtotal' => 0,
            'is_reward' => true,
          ];
        }
      }
      if (!empty($autoProductionIds)) {
        $stmtLink = $db->prepare("INSERT INTO sales_production_links (transaction_code, production_id, branch_id) VALUES (?,?,?)");
        foreach ($autoProductionIds as $productionId) {
          $stmtLink->execute([$transactionCode, (int)$productionId, $branchId]);
        }
      }
      $db->commit();
      if (!empty($_SESSION['pos_order_id'])) {
        $orderId = (int)$_SESSION['pos_order_id'];
        $stmt = $db->prepare("UPDATE orders SET status='completed', completed_at=NOW() WHERE id=?");
        $stmt->execute([$orderId]);
        $stmt = $db->prepare("
          SELECT c.id, c.loyalty_remainder
          FROM orders o
          JOIN customers c ON c.id = o.customer_id
          WHERE o.id = ?
          LIMIT 1
        ");
        $stmt->execute([$orderId]);
        $customer = $stmt->fetch();
        if ($customer) {
          $pointValue = (int)setting('loyalty_point_value', '0');
          if ($pointValue > 0) {
            $remainderMode = (string)setting('loyalty_remainder_mode', 'discard');
            $carryRemainder = $remainderMode === 'carry';
            $customerRemainder = (int)($customer['loyalty_remainder'] ?? 0);
            $totalForPoints = (int)round($receiptTotal) + ($carryRemainder ? $customerRemainder : 0);
            $pointsEarned = intdiv($totalForPoints, $pointValue);
            $newRemainder = $carryRemainder ? ($totalForPoints % $pointValue) : 0;
            $stmt = $db->prepare("
              UPDATE customers
              SET loyalty_points = loyalty_points + ?, loyalty_remainder = ?
              WHERE id = ?
            ");
            $stmt->execute([$pointsEarned, $newRemainder, (int)$customer['id']]);
          }
        }
        unset($_SESSION['pos_order_id']);
      }
      $_SESSION['pos_receipt'] = [
        'id' => $transactionCode,
        'time' => date('d/m/Y H:i'),
        'cashier' => $me['name'] ?? 'Kasir',
        'payment' => $paymentMethod,
        'items' => $receiptItems,
        'total' => $receiptTotal,
        'paid_amount' => $receiptTotal,
      ];

      $printPayload = build_pos_receipt_payload($_SESSION['pos_receipt'], [
        'store_name' => $storeName,
        'store_subtitle' => $storeSubtitle,
        'store_address' => setting('store_address', ''),
        'store_phone' => setting('store_phone', ''),
        'footer' => setting('receipt_footer', ''),
        'store_logo' => setting('store_logo', ''),
        'paid_amount' => $receiptTotal,
      ]);
      $printJob = create_pos_print_job($printPayload, [
        'sale_id' => isset($saleId) ? (int)$saleId : null,
        'created_by' => (int)($me['id'] ?? 0),
        'device_hint' => substr((string)($_SERVER['HTTP_USER_AGENT'] ?? ''), 0, 100),
        'notes' => 'POS receipt bridge job',
      ]);
      if ($printJob && !empty($printJob['job_token'])) {
        $_SESSION['pos_receipt']['print_job_token'] = (string)$printJob['job_token'];
      }

      $cart = [];
      $rewardCart = [];
      $bypassItems = [];
      $_SESSION['pos_notice'] = 'Transaksi berhasil disimpan.';
    }
  } catch (Throwable $e) {
    if (isset($db) && $db->inTransaction()) {
      $db->rollBack();
    }
    $_SESSION['pos_err'] = $e->getMessage();
  }

  foreach (array_keys($bypassItems) as $bypassPid) {
    if (empty($cart[(int)$bypassPid])) unset($bypassItems[(int)$bypassPid]);
  }
  $_SESSION['pos_cart'] = $cart;
  $_SESSION['pos_reward_cart'] = $rewardCart;
  $_SESSION['pos_bypass_items'] = $bypassItems;
  redirect(base_url('pos/index.php'));
}

$notice = $_SESSION['pos_notice'] ?? '';
$err = $_SESSION['pos_err'] ?? '';
$receipt = $_SESSION['pos_receipt'] ?? null;
unset($_SESSION['pos_notice'], $_SESSION['pos_err']);

$activeOrder = null;
if (!empty($activeOrderId)) {
  $stmt = db()->prepare("
    SELECT o.order_code, c.name, COALESCE(c.phone, c.email) AS contact, c.loyalty_points
    FROM orders o
    JOIN customers c ON c.id = o.customer_id
    WHERE o.id = ?
    LIMIT 1
  ");
  $stmt->execute([(int)$activeOrderId]);
  $activeOrder = $stmt->fetch();
}

$rewardOptions = db()->query("
  SELECT lr.id, lr.product_id, lr.points_required, p.name
  FROM loyalty_rewards lr
  JOIN products p ON p.id = lr.product_id
  ORDER BY lr.points_required ASC
")->fetchAll();
$rewardOptionsById = [];
foreach ($rewardOptions as $reward) {
  $rewardOptionsById[(int)$reward['id']] = $reward;
}

$availableRewards = [];
if (!empty($activeOrder) && !empty($rewardOptions)) {
  $points = (int)($activeOrder['loyalty_points'] ?? 0);
  foreach ($rewardOptions as $reward) {
    if ($points >= (int)$reward['points_required']) {
      $availableRewards[] = $reward;
    }
  }
}

$pendingOrders = db()->query("
  SELECT o.id, o.order_code, o.created_at, c.name, COALESCE(c.phone, c.email) AS contact
  FROM orders o
  JOIN customers c ON c.id = o.customer_id
  WHERE o.status = 'pending'
  ORDER BY o.created_at DESC
  LIMIT 20
")->fetchAll();

$pendingOrderItems = [];
if (!empty($pendingOrders)) {
  $orderIds = array_map(fn($row) => (int)$row['id'], $pendingOrders);
  $placeholders = implode(',', array_fill(0, count($orderIds), '?'));
  $stmt = db()->prepare("
    SELECT oi.order_id, oi.qty, p.name
    FROM order_items oi
    JOIN products p ON p.id = oi.product_id
    WHERE oi.order_id IN ($placeholders)
    ORDER BY oi.id ASC
  ");
  $stmt->execute($orderIds);
  foreach ($stmt->fetchAll() as $item) {
    $orderId = (int)$item['order_id'];
    $pendingOrderItems[$orderId][] = [
      'name' => $item['name'],
      'qty' => (int)$item['qty'],
    ];
  }
}

$cartItems = [];
$total = 0.0;
$cartCount = 0;
foreach ($cart as $pid => $qty) {
  if (empty($productsById[$pid])) continue;
  $price = (float)$productsById[$pid]['price'];
  $qty = (int)$qty;
  $subtotal = $price * $qty;
  $total += $subtotal;
  $cartCount += $qty;
  $cartItems[] = [
    'id' => (int)$pid,
    'name' => $productsById[$pid]['name'],
    'price' => $price,
    'qty' => $qty,
    'subtotal' => $subtotal,
    'is_reward' => false,
    'is_bypass' => $isOwner && !empty($bypassItems[(int)$pid]),
  ];
}
if (!empty($rewardCart)) {
  foreach ($rewardCart as $rid => $qty) {
    $reward = $rewardOptionsById[(int)$rid] ?? null;
    if (!$reward) continue;
    $pid = (int)$reward['product_id'];
    if (empty($productsById[$pid])) continue;
    $qty = (int)$qty;
    if ($qty <= 0) continue;
    $cartCount += $qty;
    $cartItems[] = [
      'id' => $pid,
      'reward_id' => (int)$rid,
      'name' => $productsById[$pid]['name'],
      'price' => 0,
      'qty' => $qty,
      'subtotal' => 0,
      'is_reward' => true,
      'points_required' => (int)$reward['points_required'],
    ];
  }
}
?>
<!doctype html>
<html>
<head>
  <meta charset="utf-8">
  <meta name="viewport" content="width=device-width,initial-scale=1">
  <title>POS</title>
  <link rel="icon" href="<?php echo e(favicon_url()); ?>">
  <link rel="apple-touch-icon" href="<?php echo e(favicon_url()); ?>">
  <link rel="manifest" href="<?php echo e(base_url('manifest.php')); ?>">
  <link rel="stylesheet" href="<?php echo e(asset_url('assets/app.css')); ?>">
  <link rel="stylesheet" href="<?php echo e(asset_url('pos/pos.css')); ?>">
</head>
<body data-android-app="<?php echo $isAndroidApp ? '1' : '0'; ?>">
  <div class="pos-page">
    <div class="topbar pos-topbar">
      <div class="title"><?php echo e($appName); ?> POS</div>
      <div class="spacer"></div>
      <div class="pos-user-menu">
        <button class="pos-user-button" type="button" data-toggle-submenu="#pos-user-menu">
          <div class="pos-user">
            <div class="pos-user-name"><?php echo e($me['name'] ?? 'User'); ?></div>
            <div class="pos-user-role"><?php echo e(ucfirst($me['role'] ?? '')); ?></div>
          </div>
          <div class="pos-user-chevron">▾</div>
        </button>
        <div class="pos-user-dropdown submenu" id="pos-user-menu">
          <a href="<?php echo e(base_url('profile.php')); ?>">Edit Profil</a>
          <a href="<?php echo e(base_url('password.php')); ?>">Ubah Password</a>
        </div>
      </div>
      <a class="btn pos-logout" href="<?php echo e(base_url('pos/logout.php')); ?>">Logout</a>
    </div>

    <div class="pos-wrap">
      <?php if ($notice): ?>
        <div class="pos-panel pos-alert pos-alert-success"><?php echo e($notice); ?></div>
      <?php endif; ?>
      <?php if ($err): ?>
        <div class="pos-panel pos-alert pos-alert-error"><?php echo e($err); ?></div>
      <?php endif; ?>
      <div class="pos-panel pos-orders">
        <div class="pos-orders-header">
          <h3>Pesanan Online</h3>
          <span class="pos-orders-count"><?php echo e((string)count($pendingOrders)); ?> pending</span>
        </div>
        <?php if (empty($pendingOrders)): ?>
          <div class="pos-empty">Belum ada pesanan dari landing page.</div>
        <?php else: ?>
          <div class="pos-orders-list">
            <?php foreach ($pendingOrders as $order): ?>
              <div class="pos-order-card">
                <div class="pos-order-main">
                  <div class="pos-order-code"><?php echo e($order['order_code']); ?></div>
                  <div class="pos-order-customer"><?php echo e($order['name']); ?> · <?php echo e($order['contact']); ?></div>
                  <div class="pos-order-time"><?php echo e($order['created_at']); ?></div>
                </div>
                <div class="pos-order-items">
                  <?php foreach (($pendingOrderItems[(int)$order['id']] ?? []) as $item): ?>
                    <div><?php echo e($item['qty']); ?>x <?php echo e($item['name']); ?></div>
                  <?php endforeach; ?>
                </div>
                <form method="post">
                  <input type="hidden" name="_csrf" value="<?php echo e(csrf_token()); ?>">
                  <input type="hidden" name="action" value="load_order">
                  <input type="hidden" name="order_id" value="<?php echo e((string)$order['id']); ?>">
                  <button class="btn pos-btn pos-load-btn" type="submit">Ambil ke Keranjang</button>
                </form>
              </div>
            <?php endforeach; ?>
          </div>
        <?php endif; ?>
      </div>
      <?php if (!empty($receipt)): ?>
        <div class="pos-panel pos-receipt pos-print-area">
          <div class="pos-receipt-header">
            <div>
              <div class="pos-receipt-title"><?php echo e($storeName); ?></div>
              <?php if (!empty($storeSubtitle)): ?>
                <div class="pos-receipt-subtitle"><?php echo e($storeSubtitle); ?></div>
              <?php endif; ?>
            </div>
            <div class="pos-receipt-meta">
              <div><?php echo e($receipt['id']); ?></div>
              <div><?php echo e($receipt['time']); ?></div>
              <div>Kasir: <?php echo e($receipt['cashier']); ?></div>
            </div>
          </div>

          <div class="pos-receipt-items">
            <?php foreach ($receipt['items'] as $item): ?>
              <div class="pos-receipt-row">
                <div>
                  <div class="pos-receipt-item-name"><?php echo e($item['name']); ?></div>
                  <div class="pos-receipt-item-meta">
                    <?php echo e((string)$item['qty']); ?> x
                    <?php if (!empty($item['is_reward'])): ?>
                      Gratis
                    <?php else: ?>
                      Rp <?php echo e(format_number_id((float)$item['price'])); ?>
                    <?php endif; ?>
                  </div>
                </div>
                <div class="pos-receipt-item-subtotal">
                  <?php if (!empty($item['is_reward'])): ?>
                    Gratis
                  <?php else: ?>
                    Rp <?php echo e(format_number_id((float)$item['subtotal'])); ?>
                  <?php endif; ?>
                </div>
              </div>
            <?php endforeach; ?>
          </div>

          <div class="pos-receipt-total">
            <span>Total</span>
            <strong>Rp <?php echo e(format_number_id((float)$receipt['total'])); ?></strong>
          </div>
          <div class="pos-receipt-meta">
            <div>Pembayaran: <?php echo e(strtoupper($receipt['payment'] ?? '-')); ?></div>
          </div>
          <div class="pos-receipt-actions no-print">
            <a class="btn pos-print-btn" href="<?php echo e(base_url('pos/receipt.php?id=' . urlencode($receipt['id']))); ?>">Print Struk 58mm</a>
            <button class="btn pos-print-btn" type="button" data-print-receipt>Cetak Struk</button>
            <form method="post" class="pos-new-transaction-form">
              <input type="hidden" name="_csrf" value="<?php echo e(csrf_token()); ?>">
              <input type="hidden" name="action" value="new_transaction">
              <button class="btn pos-reset-btn" type="submit">Transaksi Baru</button>
            </form>
          </div>
        </div>
      <?php endif; ?>

      <?php if (empty($receipt)): ?>
        <div class="pos-layout">
          <section class="pos-panel pos-products">
            <div class="pos-products-header">
              <div>
                <h3>Produk</h3>
                <small>Pilih produk untuk ditambahkan ke keranjang.</small>
              </div>
              <div class="pos-search">
                <input id="pos-search" type="search" placeholder="Cari produk..." autocomplete="off">
              </div>
            </div>
            <div class="pos-products-grid">
              <?php foreach ($products as $p): ?>
                <div class="pos-product-card" data-name="<?php echo e(strtolower($p['name'])); ?>">
                  <div class="pos-product-thumb">
                    <?php if (!empty($p['image_path'])): ?>
                      <img src="<?php echo e(upload_url($p['image_path'], 'image')); ?>" alt="<?php echo e($p['name']); ?>">
                    <?php else: ?>
                      <div class="pos-product-placeholder">No Image</div>
                    <?php endif; ?>
                  </div>
                  <div class="pos-product-info">
                    <div class="pos-product-name"><?php echo e($p['name']); ?></div>
                    <div class="pos-product-price">Rp <?php echo e(format_number_id((float)$p['price'])); ?></div>
                  </div>
                  <form method="post" class="pos-product-action">
                    <input type="hidden" name="_csrf" value="<?php echo e(csrf_token()); ?>">
                    <input type="hidden" name="action" value="add">
                    <input type="hidden" name="product_id" value="<?php echo e((string)$p['id']); ?>">
                    <button class="btn pos-btn pos-add-btn" type="submit">Tambah</button>
                  </form>
                </div>
              <?php endforeach; ?>
              <?php if ($hasProducts): ?>
                <div class="pos-empty" id="pos-empty">Produk tidak ditemukan.</div>
              <?php else: ?>
                <div class="pos-empty" style="display:block">Belum ada produk.</div>
              <?php endif; ?>
            </div>
          </section>

          <aside class="pos-panel pos-cart">
            <div class="pos-cart-header">
              <div>
                <h3>Keranjang</h3>
                <small>Ringkasan transaksi.</small>
              </div>
              <div class="pos-cart-count"><?php echo e((string)$cartCount); ?> item</div>
            </div>
            <?php if (!empty($activeOrder)): ?>
              <div class="pos-order-banner">
                Pesanan: <strong><?php echo e($activeOrder['order_code']); ?></strong><br>
                <?php echo e($activeOrder['name']); ?> · <?php echo e($activeOrder['contact']); ?><br>
                <span class="pos-order-points">Sisa poin: <?php echo e((string)((int)($activeOrder['loyalty_points'] ?? 0))); ?> poin</span>
              </div>
              <?php if (!empty($availableRewards)): ?>
                <div class="pos-order-banner pos-reward-banner">
                  <strong>Reward tersedia untuk diklaim</strong>
                  <div class="pos-reward-list">
                    <?php foreach ($availableRewards as $reward): ?>
                      <form method="post" class="pos-reward-claim">
                        <input type="hidden" name="_csrf" value="<?php echo e(csrf_token()); ?>">
                        <input type="hidden" name="action" value="claim_reward">
                        <input type="hidden" name="reward_id" value="<?php echo e((string)$reward['id']); ?>">
                        <button class="pos-reward-button" type="submit">
                          <span><?php echo e($reward['name']); ?></span>
                          <span class="pos-reward-points"><?php echo e((string)$reward['points_required']); ?> poin</span>
                        </button>
                      </form>
                    <?php endforeach; ?>
                  </div>
                  <small>Klik reward untuk menambahkan produk gratis ke keranjang.</small>
                </div>
              <?php endif; ?>
            <?php endif; ?>

            <?php if (empty($cartItems)): ?>
              <div class="pos-empty-cart">
                <p><strong>Keranjang masih kosong.</strong></p>
                <small>Tambahkan produk dari daftar di kiri.</small>
              </div>
            <?php else: ?>
              <div class="pos-cart-items">
                <?php foreach ($cartItems as $item): ?>
                  <div class="pos-cart-item<?php echo !empty($item['is_reward']) ? ' pos-cart-item-reward' : ''; ?>">
                    <div class="pos-cart-item-head">
                      <div class="pos-cart-item-name">
                        <?php echo e($item['name']); ?>
                        <?php if (!empty($item['is_reward'])): ?>
                          <span class="pos-reward-badge">Reward</span>
                        <?php elseif (!empty($item['is_bypass'])): ?>
                          <span class="pos-bypass-badge">Bypass BOM/Stok</span>
                        <?php endif; ?>
                      </div>
                      <div class="pos-cart-item-price">
                        <?php if (!empty($item['is_reward'])): ?>
                          Gratis
                        <?php else: ?>
                          Rp <?php echo e(format_number_id($item['price'])); ?>
                        <?php endif; ?>
                      </div>
                    </div>
                    <div class="pos-cart-row">
                      <?php if (!empty($item['is_reward'])): ?>
                        <div class="pos-qty pos-qty-static">
                          <div class="pos-qty-label">Qty</div>
                          <div class="pos-qty-value"><?php echo e((string)$item['qty']); ?></div>
                        </div>
                        <div class="pos-cart-subtotal">Rp 0</div>
                      <?php else: ?>
                        <div class="pos-qty">
                          <form method="post">
                            <input type="hidden" name="_csrf" value="<?php echo e(csrf_token()); ?>">
                            <input type="hidden" name="action" value="dec">
                            <input type="hidden" name="product_id" value="<?php echo e((string)$item['id']); ?>">
                            <button class="btn pos-qty-btn" type="submit">−</button>
                          </form>
                          <div class="pos-qty-value"><?php echo e((string)$item['qty']); ?></div>
                          <form method="post">
                            <input type="hidden" name="_csrf" value="<?php echo e(csrf_token()); ?>">
                            <input type="hidden" name="action" value="inc">
                            <input type="hidden" name="product_id" value="<?php echo e((string)$item['id']); ?>">
                            <button class="btn pos-qty-btn" type="submit">+</button>
                          </form>
                        </div>
                        <div class="pos-cart-subtotal">Rp <?php echo e(format_number_id($item['subtotal'])); ?></div>
                      <?php endif; ?>
                    </div>
                    <?php if (empty($item['is_reward']) && $isOwner): ?>
                      <form method="post" class="pos-bypass-form">
                        <input type="hidden" name="_csrf" value="<?php echo e(csrf_token()); ?>">
                        <input type="hidden" name="action" value="toggle_bypass">
                        <input type="hidden" name="product_id" value="<?php echo e((string)$item['id']); ?>">
                        <label class="pos-bypass-toggle"><input type="checkbox" <?php echo !empty($item['is_bypass']) ? 'checked' : ''; ?> onchange="this.form.submit()"> Lewati BOM &amp; stok</label>
                      </form>
                    <?php endif; ?>
                    <?php if (!empty($item['is_reward'])): ?>
                      <form method="post" class="pos-remove-form">
                        <input type="hidden" name="_csrf" value="<?php echo e(csrf_token()); ?>">
                        <input type="hidden" name="action" value="remove_reward">
                        <input type="hidden" name="reward_id" value="<?php echo e((string)$item['reward_id']); ?>">
                        <button class="btn pos-remove-btn" type="submit">Hapus Reward</button>
                      </form>
                    <?php else: ?>
                      <form method="post" class="pos-remove-form">
                        <input type="hidden" name="_csrf" value="<?php echo e(csrf_token()); ?>">
                        <input type="hidden" name="action" value="remove">
                        <input type="hidden" name="product_id" value="<?php echo e((string)$item['id']); ?>">
                        <button class="btn pos-remove-btn" type="submit">Hapus</button>
                      </form>
                    <?php endif; ?>
                  </div>
                <?php endforeach; ?>
              </div>

              <div class="pos-summary">
                <div class="pos-summary-row">
                  <span>Total</span>
                  <strong>Rp <?php echo e(format_number_id($total)); ?></strong>
                </div>
                <form method="post" class="pos-new-transaction-form">
                  <input type="hidden" name="_csrf" value="<?php echo e(csrf_token()); ?>">
                  <input type="hidden" name="action" value="new_transaction">
                  <button class="btn pos-reset-btn" type="submit">Transaksi Baru</button>
                </form>
                <form method="post" enctype="multipart/form-data">
                  <input type="hidden" name="_csrf" value="<?php echo e(csrf_token()); ?>">
                  <input type="hidden" name="action" value="checkout">
                  <div class="pos-payment">
                    <label>Metode Pembayaran</label>
                    <div class="pos-payment-options">
                      <label class="pos-payment-option">
                        <input type="radio" name="payment_method" value="cash" checked>
                        <span>Tunai</span>
                      </label>
                      <label class="pos-payment-option">
                        <input type="radio" name="payment_method" value="qris">
                        <span>QRIS</span>
                      </label>
                    </div>
                  </div>
                  <div class="pos-qris" data-qris-field hidden>
                    <label for="payment_proof">Foto Bukti QRIS</label>
                    <input class="pos-qris-input" type="file" id="payment_proof" name="payment_proof" accept=".jpg,.jpeg,.png" capture="environment">
                    <label class="btn pos-qris-upload" for="payment_proof">Ambil Foto QRIS</label>
                    <div class="pos-qris-preview" data-qris-preview hidden>
                      <img alt="Preview bukti QRIS">
                      <button type="button" class="btn pos-qris-retake" data-qris-retake>Ulangi Foto</button>
                    </div>
                    <small>Pastikan foto bukti pembayaran jelas sebelum checkout.</small>
                  </div>
                  <button class="btn pos-checkout" type="submit">Checkout</button>
                </form>
              </div>
            <?php endif; ?>
          </aside>
        </div>
      <?php endif; ?>
    </div>
  </div>
  <script defer src="<?php echo e(asset_url('assets/app.js')); ?>"></script>
  <script defer src="<?php echo e(asset_url('pos/pos.js')); ?>"></script>
</body>
</html>
