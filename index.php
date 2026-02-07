<?php
require_once __DIR__ . '/config.local.php';
require_once __DIR__ . '/core/db.php';
require_once __DIR__ . '/core/functions.php';
require_once __DIR__ . '/core/security.php';
require_once __DIR__ . '/core/auth.php';
require_once __DIR__ . '/core/csrf.php';
require_once __DIR__ . '/core/customer_auth.php';

try {
  ensure_products_category_column();
  ensure_products_best_seller_column();
  ensure_landing_order_tables();
  $products = db()->query("SELECT * FROM products ORDER BY is_best_seller DESC, id DESC")->fetchAll();
} catch (Throwable $e) {
  header('Location: install/index.php');
  exit;
}
$appName = app_config()['app']['name'];
$storeName = setting('store_name', $appName);
$storeSubtitle = setting('store_subtitle', 'Katalog produk sederhana');
$storeIntro = setting('store_intro', 'Kami adalah usaha yang menghadirkan produk pilihan dengan kualitas terbaik untuk kebutuhan Anda.');
$storeLogo = setting('store_logo', '');
$storePromo = setting('store_promo', '');
$storePromoEnabled = setting('store_promo_enabled', '1') === '1';
$customCss = setting('custom_css', '');
$landingCss = setting('landing_css', '');
$landingHtml = setting('landing_html', '');
$landingOrderEnabled = setting('landing_order_enabled', '1') === '1';
$recaptchaSiteKey = setting('recaptcha_site_key', '');
$recaptchaSecretKey = setting('recaptcha_secret_key', '');
$checkoutRecaptchaAction = 'checkout';
$recaptchaMinScore = 0.5;

start_secure_session();
customer_bootstrap_from_cookie();
$cart = $_SESSION['landing_cart'] ?? [];
$notice = $_SESSION['landing_notice'] ?? '';
$err = $_SESSION['landing_err'] ?? '';
unset($_SESSION['landing_notice'], $_SESSION['landing_err']);

$productsById = [];
foreach ($products as $p) {
  $productsById[(int)$p['id']] = $p;
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
  csrf_check();
  $action = $_POST['action'] ?? '';
  $productId = (int)($_POST['product_id'] ?? 0);
  $customer = customer_current();

  try {
    if (!$landingOrderEnabled && in_array($action, ['add', 'inc', 'dec', 'remove', 'checkout'], true)) {
      throw new Exception('Pesanan online sedang dinonaktifkan.');
    }
    if (in_array($action, ['add', 'inc', 'dec', 'remove'], true)) {
      if ($productId <= 0 || empty($productsById[$productId])) {
        throw new Exception('Produk tidak ditemukan.');
      }
    }

    if ($action === 'add') {
      $cart[$productId] = ($cart[$productId] ?? 0) + 1;
      $notice = 'Produk ditambahkan ke keranjang.';
    } elseif ($action === 'inc') {
      $cart[$productId] = ($cart[$productId] ?? 1) + 1;
      $notice = 'Jumlah produk ditambah.';
    } elseif ($action === 'dec') {
      $current = (int)($cart[$productId] ?? 1);
      if ($current <= 1) {
        unset($cart[$productId]);
      } else {
        $cart[$productId] = $current - 1;
      }
      $notice = 'Jumlah produk dikurangi.';
    } elseif ($action === 'remove') {
      unset($cart[$productId]);
      $notice = 'Produk dihapus dari keranjang.';
    } elseif ($action === 'checkout') {
      if (empty($cart)) {
        throw new Exception('Keranjang masih kosong.');
      }
      if (!$customer) {
        throw new Exception('Silakan login atau daftar terlebih dahulu.');
      }
      if ($recaptchaSecretKey === '') {
        throw new Exception('reCAPTCHA checkout belum diatur oleh admin.');
      }
      $recaptchaToken = (string)($_POST['g-recaptcha-response'] ?? '');
      if (!verify_recaptcha_response(
        $recaptchaToken,
        $recaptchaSecretKey,
        $_SERVER['REMOTE_ADDR'] ?? '',
        $checkoutRecaptchaAction,
        $recaptchaMinScore
      )) {
        throw new Exception('Verifikasi reCAPTCHA checkout gagal.');
      }
      $customerName = trim((string)($customer['name'] ?? ''));
      $customerPhone = trim((string)($customer['phone'] ?? ''));
      if ($customerName === '' || $customerPhone === '') {
        throw new Exception('Profil pelanggan belum lengkap.');
      }
      $db = db();
      $db->beginTransaction();

      $customerId = (int)($customer['id'] ?? 0);
      if ($customerId <= 0) {
        throw new Exception('Pelanggan tidak ditemukan.');
      }

      $orderCode = 'ORD-' . date('YmdHis') . '-' . strtoupper(bin2hex(random_bytes(2)));
      $stmt = $db->prepare("INSERT INTO orders (order_code, customer_id, status) VALUES (?,?, 'pending')");
      $stmt->execute([$orderCode, $customerId]);
      $orderId = (int)$db->lastInsertId();

      $stmt = $db->prepare("INSERT INTO order_items (order_id, product_id, qty, price_each, subtotal) VALUES (?,?,?,?,?)");
      $orderTotal = 0;
      foreach ($cart as $pid => $qty) {
        if (empty($productsById[$pid])) {
          throw new Exception('Produk tidak ditemukan saat checkout.');
        }
        $qty = (int)$qty;
        if ($qty <= 0) {
          throw new Exception('Jumlah produk tidak valid.');
        }
        $price = (float)$productsById[$pid]['price'];
        $subtotal = $price * $qty;
        $stmt->execute([$orderId, (int)$pid, $qty, $price, $subtotal]);
        $orderTotal += $subtotal;
      }

      $db->commit();
      $cart = [];
      $notice = 'Pesanan berhasil dikirim. Kode pesanan: ' . $orderCode;
    }
  } catch (Throwable $e) {
    if (isset($db) && $db->inTransaction()) {
      $db->rollBack();
    }
    $err = $e->getMessage();
  }

  $_SESSION['landing_cart'] = $cart;
  $_SESSION['landing_notice'] = $notice;
  $_SESSION['landing_err'] = $err;
  redirect(base_url('index.php'));
}

$cartItems = [];
$cartTotal = 0.0;
$cartCount = 0;
foreach ($cart as $pid => $qty) {
  if (empty($productsById[$pid])) continue;
  $price = (float)$productsById[$pid]['price'];
  $qty = (int)$qty;
  $subtotal = $price * $qty;
  $cartTotal += $subtotal;
  $cartCount += $qty;
  $cartItems[] = [
    'id' => (int)$pid,
    'name' => $productsById[$pid]['name'],
    'price' => $price,
    'qty' => $qty,
    'subtotal' => $subtotal,
  ];
}

$currentUser = current_user();
$customer = customer_current();
$customerButton = $customer
  ? '<a class="btn" href="' . e(base_url('customer.php')) . '">Akun Saya</a>'
  : '<a class="btn" href="' . e(base_url('customer.php')) . '">Masuk / Daftar</a>';
$adminButton = '';
if ($currentUser && in_array($currentUser['role'] ?? '', ['admin', 'owner'], true)) {
  $adminButton = '<a class="btn btn-light" href="' . e(base_url('admin/dashboard.php')) . '">Admin</a>';
}
$loginButtons = [$customerButton];
if ($adminButton !== '') {
  $loginButtons[] = $adminButton;
}
$loginButton = '<div style="display:flex;gap:8px;flex-wrap:wrap">' . implode('', $loginButtons) . '</div>';
?>
<!doctype html>
<html>
<head>
  <meta charset="utf-8">
  <meta name="viewport" content="width=device-width,initial-scale=1">
  <title><?php echo e($storeName); ?></title>
  <link rel="icon" href="<?php echo e(favicon_url()); ?>">
  <link rel="apple-touch-icon" href="<?php echo e(favicon_url()); ?>">
  <link rel="manifest" href="<?php echo e(base_url('manifest.php')); ?>">
  <link rel="stylesheet" href="<?php echo e(asset_url('assets/app.css')); ?>">
  <style><?php echo $customCss; ?><?php echo $landingCss; ?></style>
</head>
<body>
  <?php
    $productCards = '';
    ob_start();
  ?>
    <div class="grid cols-2 landing-products" style="margin-top:16px">
      <?php foreach ($products as $p): ?>
        <?php $categoryLabel = $p['category'] ?: 'Tanpa kategori'; ?>
        <?php if ($landingOrderEnabled): ?>
          <form method="post" class="landing-product-form">
            <input type="hidden" name="_csrf" value="<?php echo e(csrf_token()); ?>">
            <input type="hidden" name="action" value="add">
            <input type="hidden" name="product_id" value="<?php echo e((string)$p['id']); ?>">
            <button class="card landing-product-card" type="submit">
              <span class="landing-product-body">
                <?php if (!empty($p['image_path'])): ?>
                  <img class="thumb" src="<?php echo e(upload_url($p['image_path'], 'image')); ?>" alt="">
                <?php else: ?>
                  <span class="thumb landing-product-thumb-fallback">No Img</span>
                <?php endif; ?>
                <span class="landing-product-info">
                  <span class="landing-product-name"><?php echo e($p['name']); ?></span>
                  <span class="landing-product-meta">
                    <span><?php echo e($categoryLabel); ?></span>
                    <?php if (!empty($p['is_best_seller'])): ?>
                      <span class="landing-product-highlight">Best Seller</span>
                    <?php endif; ?>
                  </span>
                  <span class="badge">Rp <?php echo e(number_format((float)$p['price'], 0, '.', ',')); ?></span>
                </span>
              </span>
            </button>
          </form>
        <?php else: ?>
          <div class="landing-product-form">
            <div class="card landing-product-card landing-product-card-disabled">
              <span class="landing-product-body">
                <?php if (!empty($p['image_path'])): ?>
                  <img class="thumb" src="<?php echo e(upload_url($p['image_path'], 'image')); ?>" alt="">
                <?php else: ?>
                  <span class="thumb landing-product-thumb-fallback">No Img</span>
                <?php endif; ?>
                <span class="landing-product-info">
                  <span class="landing-product-name"><?php echo e($p['name']); ?></span>
                  <span class="landing-product-meta">
                    <span><?php echo e($categoryLabel); ?></span>
                    <?php if (!empty($p['is_best_seller'])): ?>
                      <span class="landing-product-highlight">Best Seller</span>
                    <?php endif; ?>
                  </span>
                  <span class="badge">Rp <?php echo e(number_format((float)$p['price'], 0, '.', ',')); ?></span>
                </span>
              </span>
            </div>
          </div>
        <?php endif; ?>
      <?php endforeach; ?>
    </div>
  <?php
    $productCards = ob_get_clean();
    $noticeBlock = '';
    if ($notice) {
      $noticeBlock = '<div class="card landing-alert landing-alert-success">' . e($notice) . '</div>';
    } elseif ($err) {
      $noticeBlock = '<div class="card landing-alert landing-alert-error">' . e($err) . '</div>';
    }

    ob_start();
  ?>
    <?php if (!$landingOrderEnabled): ?>
      <div class="card landing-alert">
        Pesanan online sedang dinonaktifkan. Silakan hubungi admin untuk pemesanan.
      </div>
    <?php else: ?>
      <div class="card landing-cart">
        <h3 style="margin-top:0">Keranjang</h3>
        <?php if (empty($cartItems)): ?>
          <p style="margin:0;color:var(--muted)">Keranjang masih kosong. Klik produk untuk menambah.</p>
        <?php else: ?>
          <div class="landing-cart-items">
            <?php foreach ($cartItems as $item): ?>
              <div class="landing-cart-item">
                <div>
                  <div class="landing-cart-name"><?php echo e($item['name']); ?></div>
                  <div class="landing-cart-price">Rp <?php echo e(number_format((float)$item['price'], 0, '.', ',')); ?></div>
                </div>
                <div class="landing-cart-actions">
                  <form method="post">
                    <input type="hidden" name="_csrf" value="<?php echo e(csrf_token()); ?>">
                    <input type="hidden" name="action" value="dec">
                    <input type="hidden" name="product_id" value="<?php echo e((string)$item['id']); ?>">
                    <button class="btn btn-light" type="submit">−</button>
                  </form>
                  <div class="landing-cart-qty"><?php echo e((string)$item['qty']); ?></div>
                  <form method="post">
                    <input type="hidden" name="_csrf" value="<?php echo e(csrf_token()); ?>">
                    <input type="hidden" name="action" value="inc">
                    <input type="hidden" name="product_id" value="<?php echo e((string)$item['id']); ?>">
                    <button class="btn btn-light" type="submit">+</button>
                  </form>
                  <form method="post">
                    <input type="hidden" name="_csrf" value="<?php echo e(csrf_token()); ?>">
                    <input type="hidden" name="action" value="remove">
                    <input type="hidden" name="product_id" value="<?php echo e((string)$item['id']); ?>">
                    <button class="btn btn-ghost" type="submit">Hapus</button>
                  </form>
                </div>
                <div class="landing-cart-subtotal">Rp <?php echo e(number_format((float)$item['subtotal'], 0, '.', ',')); ?></div>
              </div>
            <?php endforeach; ?>
          </div>
          <div class="landing-cart-summary">
            <div>Total (<?php echo e((string)$cartCount); ?> item)</div>
            <strong>Rp <?php echo e(number_format((float)$cartTotal, 0, '.', ',')); ?></strong>
          </div>
          <?php if (!$customer): ?>
            <div class="card landing-alert" style="margin-top:12px">
              Silakan <a href="<?php echo e(base_url('customer.php')); ?>">masuk atau daftar</a> terlebih dahulu untuk checkout.
            </div>
          <?php else: ?>
            <form method="post" class="landing-checkout">
              <input type="hidden" name="_csrf" value="<?php echo e(csrf_token()); ?>">
              <input type="hidden" name="action" value="checkout">
              <input type="hidden" name="g-recaptcha-response" id="recaptcha-checkout-token">
              <div class="row">
                <label>Pelanggan</label>
                <div>
                  <strong><?php echo e($customer['name']); ?></strong>
                  <div style="color:var(--muted)"><?php echo e($customer['phone']); ?></div>
                </div>
              </div>
              <button class="btn landing-checkout-btn" type="submit" <?php echo $recaptchaSiteKey === '' ? 'disabled' : ''; ?>>Kirim Pesanan</button>
              <?php if ($recaptchaSiteKey === ''): ?>
                <div class="card" style="margin-top:12px;border-color:rgba(251,113,133,.35);background:rgba(251,113,133,.10)">
                  reCAPTCHA checkout belum disetting. Hubungi admin.
                </div>
              <?php endif; ?>
            </form>
          <?php endif; ?>
        <?php endif; ?>
      </div>
    <?php endif; ?>
  <?php
    $cartBlock = ob_get_clean();
    $logoBlock = '';
    $storeLogoUrl = '';
    if (!empty($storeLogo)) {
      $storeLogoUrl = upload_url($storeLogo, 'image');
      $logoBlock = '<img src="' . e($storeLogoUrl) . '" alt="' . e($storeName) . '" style="width:56px;height:56px;object-fit:cover;border-radius:12px;border:1px solid var(--border)">';
    }
    $landingTemplate = $landingHtml !== '' ? $landingHtml : landing_default_html();
    echo strtr($landingTemplate, [
      '{{store_name}}' => e($storeName),
      '{{store_subtitle}}' => e($storeSubtitle),
      '{{store_intro}}' => e($storeIntro),
      '{{store_promo}}' => nl2br(e($storePromo)),
      '{{store_logo}}' => e($storeLogoUrl),
      '{{store_logo_block}}' => $logoBlock,
      '{{login_button}}' => $loginButton,
      '{{login_url}}' => e(base_url('adm.php')),
      '{{notice}}' => $noticeBlock,
      '{{products}}' => $productCards,
      '{{cart}}' => $cartBlock,
      '{{promo_section}}' => ($storePromoEnabled && trim($storePromo) !== '')
        ? '<div class="card landing-promo" style="margin-top:16px"><div class="landing-promo-title">PROMO</div><div class="landing-promo-text">' . nl2br(e($storePromo)) . '</div></div>'
        : '',
    ]);
  ?>
  <?php if (!empty($recaptchaSiteKey)): ?>
    <style>
      .grecaptcha-badge {
        display: block !important;
        visibility: visible !important;
        opacity: 1 !important;
        pointer-events: auto !important;
        z-index: 9999;
      }
    </style>
    <script defer src="https://www.google.com/recaptcha/api.js?render=<?php echo e($recaptchaSiteKey); ?>"></script>
    <script nonce="<?php echo e(csp_nonce()); ?>">
      (function () {
        const form = document.querySelector('.landing-checkout');
        const tokenInput = document.getElementById('recaptcha-checkout-token');
        const siteKey = '<?php echo e($recaptchaSiteKey); ?>';
        const action = '<?php echo e($checkoutRecaptchaAction); ?>';
        const timeoutMs = 8000;
        const refreshMs = 90 * 1000;
        let tokenPromise = null;
        let lastTokenAt = 0;

        function setSubmitBusy(isBusy) {
          if (!form) return;
          const submitButton = form.querySelector('.landing-checkout-btn');
          if (!submitButton) return;
          if (typeof submitButton.dataset.originalText === 'undefined') {
            submitButton.dataset.originalText = submitButton.textContent;
          }
          submitButton.disabled = isBusy;
          submitButton.textContent = isBusy ? 'Memverifikasi...' : submitButton.dataset.originalText;
        }

        function requestToken(options) {
          const opts = options || {};
          if (typeof window.grecaptcha === 'undefined' || typeof window.grecaptcha.ready !== 'function') {
            return Promise.reject(new Error('recaptcha-not-ready'));
          }
          if (tokenPromise) {
            return tokenPromise;
          }
          if (!opts.force && lastTokenAt > 0 && Date.now() - lastTokenAt < refreshMs && tokenInput && tokenInput.value !== '') {
            return Promise.resolve(tokenInput.value);
          }

          tokenPromise = new Promise(function (resolve, reject) {
            let timeoutId = window.setTimeout(function () {
              timeoutId = null;
              reject(new Error('recaptcha-timeout'));
            }, timeoutMs);

            window.grecaptcha.ready(function () {
              window.grecaptcha.execute(siteKey, { action: action })
                .then(function (token) {
                  if (!token) {
                    throw new Error('recaptcha-empty-token');
                  }
                  if (timeoutId !== null) {
                    window.clearTimeout(timeoutId);
                  }
                  lastTokenAt = Date.now();
                  if (tokenInput) {
                    tokenInput.value = token;
                  }
                  resolve(token);
                })
                .catch(function (error) {
                  if (timeoutId !== null) {
                    window.clearTimeout(timeoutId);
                  }
                  reject(error);
                });
            });
          }).finally(function () {
            tokenPromise = null;
          });

          return tokenPromise;
        }

        requestToken().catch(function () {
          // Best effort for badge visibility.
        });

        window.setInterval(function () {
          if (!form || form.dataset.submitting === '1') return;
          requestToken({ force: true }).catch(function () {
            // Silent retry for background refresh.
          });
        }, refreshMs);

        if (!form || !tokenInput) return;

        form.addEventListener('submit', function (event) {
          if (form.dataset.submitting === '1') {
            event.preventDefault();
            return;
          }

          if (tokenInput.value !== '' && lastTokenAt > 0 && Date.now() - lastTokenAt < refreshMs) {
            return;
          }

          event.preventDefault();
          form.dataset.submitting = '1';
          setSubmitBusy(true);

          requestToken({ force: true })
            .then(function (token) {
              if (!token || tokenInput.value === '') {
                throw new Error('recaptcha-empty-token');
              }
              form.submit();
            })
            .catch(function (error) {
              form.dataset.submitting = '';
              setSubmitBusy(false);
              if (error && error.message === 'recaptcha-timeout') {
                alert('reCAPTCHA lambat merespons. Coba refresh halaman atau nonaktifkan adblock, lalu coba lagi.');
                return;
              }
              alert('reCAPTCHA belum siap. Silakan coba beberapa detik lagi.');
            });
        });
      })();
    </script>
  <?php endif; ?>
</body>
</html>
