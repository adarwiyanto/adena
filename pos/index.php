<?php
require_once __DIR__ . '/../core/db.php';
require_once __DIR__ . '/../core/functions.php';
require_once __DIR__ . '/../core/security.php';
require_once __DIR__ . '/../core/auth.php';
require_once __DIR__ . '/../core/csrf.php';
require_once __DIR__ . '/../core/attendance.php';
require_once __DIR__ . '/../lib/upload_secure.php';

start_secure_session();
require_login();
ensure_landing_order_tables();
ensure_loyalty_rewards_table();
ensure_sales_transaction_code_column();
ensure_sales_user_column();
ensure_employee_roles();
ensure_employee_attendance_tables();

$appName = app_config()['app']['name'];
$storeName = setting('store_name', $appName);
$storeSubtitle = setting('store_subtitle', '');
$me = current_user();
$products = db()->query("SELECT id, name, price, image_path FROM products ORDER BY name ASC")->fetchAll();
$hasProducts = !empty($products);
$productsById = [];
foreach ($products as $p) {
  $productsById[(int)$p['id']] = $p;
}

$cart = $_SESSION['pos_cart'] ?? [];
$rewardCart = $_SESSION['pos_reward_cart'] ?? [];
$activeOrderId = $_SESSION['pos_order_id'] ?? null;

$role = (string)($me['role'] ?? '');
$isEmployee = is_employee_role($role);
$isManagerToko = $role === 'manager_toko';
$canProcessPayment = employee_can_process_payment($role);
$attendanceToday = $isEmployee ? attendance_today_for_user((int)($me['id'] ?? 0)) : null;
$hasCheckinToday = !empty($attendanceToday['checkin_time']);
$hasCheckoutToday = !empty($attendanceToday['checkout_time']);

if ($isEmployee && !$hasCheckinToday && ($_GET['attendance_confirm'] ?? '') !== 'sudah') {
  redirect(base_url('pos/attendance_confirm.php'));
}


if ($_SERVER['REQUEST_METHOD'] === 'POST') {
  csrf_check();
  $action = $_POST['action'] ?? '';
  $productId = (int)($_POST['product_id'] ?? 0);
  $rewardId = (int)($_POST['reward_id'] ?? 0);

  try {
    if ($isEmployee && !$hasCheckinToday && in_array($action, ['add','inc','dec','remove','load_order','claim_reward','remove_reward','checkout'], true)) {
      throw new Exception('Silakan absen masuk terlebih dahulu sebelum menggunakan POS.');
    }
    if (in_array($action, ['add','inc','dec','remove'], true)) {
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
      } else {
        $cart[$productId] = $current - 1;
      }
      $_SESSION['pos_notice'] = 'Jumlah produk dikurangi.';
    } elseif ($action === 'remove') {
      unset($cart[$productId]);
      $_SESSION['pos_notice'] = 'Produk dihapus dari keranjang.';
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
      if ($canProcessPayment) {
        if (!in_array($paymentMethod, ['cash', 'qris'], true)) {
          throw new Exception('Pilih metode pembayaran.');
        }
      } else {
        $paymentMethod = 'unpaid';
      }
      $paymentProofPath = null;
      if ($canProcessPayment && $paymentMethod === 'qris') {
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
      $transactionCode = 'TRX-' . date('YmdHis') . '-' . strtoupper(bin2hex(random_bytes(2)));
      $stmt = $db->prepare("INSERT INTO sales (transaction_code, product_id, qty, price_each, total, payment_method, payment_proof_path, created_by) VALUES (?,?,?,?,?,?,?,?)");
      $receiptItems = [];
      $receiptTotal = 0.0;
      foreach ($cart as $pid => $qty) {
        if (empty($productsById[$pid])) {
          throw new Exception('Produk tidak ditemukan saat checkout.');
        }
        $qty = (int)$qty;
        if ($qty <= 0) {
          throw new Exception('Jumlah produk tidak valid.');
        }
        $price = (float)$productsById[$pid]['price'];
        $total = $price * $qty;
        $stmt->execute([$transactionCode, (int)$pid, $qty, $price, $total, $paymentMethod, $paymentProofPath, (int)($me['id'] ?? 0)]);
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
          $receiptItems[] = [
            'name' => $reward['name'],
            'qty' => $qty,
            'price' => 0,
            'subtotal' => 0,
            'is_reward' => true,
          ];
        }
      }
      $db->commit();
      if (!empty($_SESSION['pos_order_id'])) {
        $orderId = (int)$_SESSION['pos_order_id'];
        $orderStatus = $canProcessPayment ? 'completed' : 'pending_payment';
        $stmt = $db->prepare("UPDATE orders SET status=?, completed_at=NOW() WHERE id=?");
        $stmt->execute([$orderStatus, $orderId]);
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
      ];
      $cart = [];
      $rewardCart = [];
      $_SESSION['pos_notice'] = 'Transaksi berhasil disimpan.';
    }
  } catch (Throwable $e) {
    if (isset($db) && $db->inTransaction()) {
      $db->rollBack();
    }
    $_SESSION['pos_err'] = $e->getMessage();
  }

  $_SESSION['pos_cart'] = $cart;
  $_SESSION['pos_reward_cart'] = $rewardCart;
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
<body>
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
          <?php if ($isManagerToko): ?>
            <button type="button" class="pos-user-submenu-toggle" data-toggle-submenu="#pos-management-store-submenu">
              Management Toko
              <span class="pos-user-submenu-chevron">▾</span>
            </button>
            <div class="submenu pos-user-submenu" id="pos-management-store-submenu">
              <a href="<?php echo e(base_url('admin/schedule.php')); ?>">Edit Jadwal Kerja Pegawai</a>
              <a href="<?php echo e(base_url('admin/attendance.php')); ?>">Rekapitulasi Absen</a>
            </div>
          <?php endif; ?>
          <a href="<?php echo e(base_url('pos/logout.php')); ?>">Logout</a>
        </div>
      </div>
      <?php if ($isEmployee): ?>
        <?php if (!$hasCheckinToday): ?>
          <a class="btn" href="<?php echo e(base_url('pos/absen.php?type=in')); ?>">Absen Masuk</a>
        <?php elseif (!$hasCheckoutToday): ?>
          <a class="btn" href="<?php echo e(base_url('pos/absen.php?type=out')); ?>">Absen Pulang</a>
        <?php else: ?>
          <button class="btn" type="button" disabled>Absen Lengkap</button>
        <?php endif; ?>
      <?php endif; ?>
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
                      Rp <?php echo e(number_format((float)$item['price'], 0, '.', ',')); ?>
                    <?php endif; ?>
                  </div>
                </div>
                <div class="pos-receipt-item-subtotal">
                  <?php if (!empty($item['is_reward'])): ?>
                    Gratis
                  <?php else: ?>
                    Rp <?php echo e(number_format((float)$item['subtotal'], 0, '.', ',')); ?>
                  <?php endif; ?>
                </div>
              </div>
            <?php endforeach; ?>
          </div>

          <div class="pos-receipt-total">
            <span>Total</span>
            <strong>Rp <?php echo e(number_format((float)$receipt['total'], 0, '.', ',')); ?></strong>
          </div>
          <div class="pos-receipt-meta">
            <div>Pembayaran: <?php echo e(strtoupper($receipt['payment'] ?? '-')); ?></div>
          </div>
          <div class="pos-receipt-actions no-print">
            <a class="btn pos-print-btn" href="<?php echo e(base_url('pos/receipt.php?id=' . urlencode($receipt['id']))); ?>" target="_blank" rel="noopener">Print Struk 58mm</a>
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
                    <div class="pos-product-price">Rp <?php echo e(number_format((float)$p['price'], 0, '.', ',')); ?></div>
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
                        <?php endif; ?>
                      </div>
                      <div class="pos-cart-item-price">
                        <?php if (!empty($item['is_reward'])): ?>
                          Gratis
                        <?php else: ?>
                          Rp <?php echo e(number_format($item['price'], 0, '.', ',')); ?>
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
                        <div class="pos-cart-subtotal">Rp <?php echo e(number_format($item['subtotal'], 0, '.', ',')); ?></div>
                      <?php endif; ?>
                    </div>
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
                  <strong>Rp <?php echo e(number_format($total, 0, '.', ',')); ?></strong>
                </div>
                <form method="post" class="pos-new-transaction-form">
                  <input type="hidden" name="_csrf" value="<?php echo e(csrf_token()); ?>">
                  <input type="hidden" name="action" value="new_transaction">
                  <button class="btn pos-reset-btn" type="submit">Transaksi Baru</button>
                </form>
                <form method="post" enctype="multipart/form-data">
                  <input type="hidden" name="_csrf" value="<?php echo e(csrf_token()); ?>">
                  <input type="hidden" name="action" value="checkout">
                  <?php if ($canProcessPayment): ?>
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
                  <?php else: ?>
                    <div class="pos-alert" style="margin-bottom:10px">Role pegawai non-POS: pembayaran dinonaktifkan, transaksi disimpan sebagai unpaid.</div>
                  <?php endif; ?>
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
