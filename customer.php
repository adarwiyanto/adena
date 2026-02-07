<?php
require_once __DIR__ . '/core/db.php';
require_once __DIR__ . '/core/functions.php';
require_once __DIR__ . '/core/security.php';
require_once __DIR__ . '/core/auth.php';
require_once __DIR__ . '/core/csrf.php';
require_once __DIR__ . '/core/customer_auth.php';

start_secure_session();
ensure_landing_order_tables();
ensure_loyalty_rewards_table();
customer_bootstrap_from_cookie();

$err = '';
$notice = '';

if (!empty($_SESSION['customer_notice'])) {
  $notice = (string)$_SESSION['customer_notice'];
  unset($_SESSION['customer_notice']);
}
if (!empty($_SESSION['customer_err'])) {
  $err = (string)$_SESSION['customer_err'];
  unset($_SESSION['customer_err']);
}

$recaptchaSiteKey = setting('recaptcha_site_key', '');
$recaptchaSecretKey = setting('recaptcha_secret_key', '');
$recaptchaAction = 'customer_register';
$recaptchaMinScore = 0.5;

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
  csrf_check();
  $action = $_POST['action'] ?? '';
  try {
    if ($action === 'register') {
      $name = trim($_POST['name'] ?? '');
      $phone = trim($_POST['phone'] ?? '');
      $password = (string)($_POST['password'] ?? '');
      $gender = trim($_POST['gender'] ?? '');
      $birthDate = trim($_POST['birth_date'] ?? '');

      if ($name === '') throw new Exception('Nama wajib diisi.');
      if ($phone === '') throw new Exception('Nomor telepon wajib diisi.');
      if (!preg_match('/^[0-9+][0-9\s\-]{6,20}$/', $phone)) {
        throw new Exception('Nomor telepon tidak valid.');
      }
      if (strlen($password) < 6) throw new Exception('Password minimal 6 karakter.');
      if (!in_array($gender, ['male', 'female', 'other'], true)) {
        throw new Exception('Pilih jenis kelamin.');
      }
      if ($birthDate === '') throw new Exception('Tanggal lahir wajib diisi.');
      $birth = DateTimeImmutable::createFromFormat('Y-m-d', $birthDate);
      if (!$birth) throw new Exception('Tanggal lahir tidak valid.');
      if ($recaptchaSecretKey === '') {
        throw new Exception('reCAPTCHA belum diatur oleh admin.');
      }
      $recaptchaToken = (string)($_POST['g-recaptcha-response'] ?? '');
      if (!verify_recaptcha_response(
        $recaptchaToken,
        $recaptchaSecretKey,
        $_SERVER['REMOTE_ADDR'] ?? '',
        $recaptchaAction,
        $recaptchaMinScore
      )) {
        throw new Exception('Verifikasi reCAPTCHA gagal.');
      }

      $stmt = db()->prepare("SELECT id, password_hash FROM customers WHERE phone = ? LIMIT 1");
      $stmt->execute([$phone]);
      $existing = $stmt->fetch();

      $hash = password_hash($password, PASSWORD_DEFAULT);
      if ($existing && empty($existing['password_hash'])) {
        $stmt = db()->prepare("
          UPDATE customers
          SET name = ?, password_hash = ?, gender = ?, birth_date = ?
          WHERE id = ?
        ");
        $stmt->execute([$name, $hash, $gender, $birthDate, (int)$existing['id']]);
        $customerId = (int)$existing['id'];
      } elseif ($existing) {
        throw new Exception('Nomor telepon sudah terdaftar.');
      } else {
        $stmt = db()->prepare("INSERT INTO customers (name, phone, password_hash, gender, birth_date) VALUES (?,?,?,?,?)");
        $stmt->execute([$name, $phone, $hash, $gender, $birthDate]);
        $customerId = (int)db()->lastInsertId();
      }

      $stmt = db()->prepare("SELECT * FROM customers WHERE id = ? LIMIT 1");
      $stmt->execute([$customerId]);
      $customer = $stmt->fetch();
      if ($customer) {
        customer_create_session($customer);
      }
      $_SESSION['customer_notice'] = 'Pendaftaran berhasil. Selamat datang!';
      redirect(base_url('customer.php'));
    }

    if ($action === 'login') {
      $phone = trim($_POST['phone'] ?? '');
      $password = (string)($_POST['password'] ?? '');
      if ($phone === '' || $password === '') {
        throw new Exception('Nomor telepon dan password wajib diisi.');
      }
      $rateId = $phone . '|' . ($_SERVER['REMOTE_ADDR'] ?? 'unknown');
      if (!rate_limit_check('customer_login', $rateId)) {
        throw new Exception('Terlalu banyak percobaan login. Silakan coba lagi nanti.');
      }
      if (!customer_login($phone, $password)) {
        rate_limit_record('customer_login', $rateId);
        throw new Exception('Nomor telepon atau password salah.');
      }
      rate_limit_clear('customer_login', $rateId);
      $_SESSION['customer_notice'] = 'Login berhasil.';
      redirect(base_url('customer.php'));
    }
  } catch (Throwable $e) {
    $_SESSION['customer_err'] = $e->getMessage();
    redirect(base_url('customer.php'));
  }
}

$customer = customer_current();
if ($customer) {
  $stmt = db()->prepare("SELECT * FROM customers WHERE id = ? LIMIT 1");
  $stmt->execute([(int)$customer['id']]);
  $customer = $stmt->fetch() ?: $customer;
}

$rewards = db()->query("
  SELECT lr.id, lr.points_required, p.name
  FROM loyalty_rewards lr
  JOIN products p ON p.id = lr.product_id
  ORDER BY lr.points_required ASC
")->fetchAll();

$genderLabels = [
  'male' => 'Laki-laki',
  'female' => 'Perempuan',
  'other' => 'Lainnya',
];
$customCss = setting('custom_css', '');
?>
<!doctype html>
<html>
<head>
  <meta charset="utf-8">
  <meta name="viewport" content="width=device-width,initial-scale=1">
  <title>Akun Pelanggan</title>
  <link rel="icon" href="<?php echo e(favicon_url()); ?>">
  <link rel="stylesheet" href="<?php echo e(asset_url('assets/app.css')); ?>">
  <style><?php echo $customCss; ?></style>
  <style>
    .customer-hero {
      display: flex;
      align-items: center;
      justify-content: space-between;
      gap: 12px;
      flex-wrap: wrap;
    }
    .customer-grid {
      display: grid;
      gap: 16px;
      grid-template-columns: repeat(auto-fit, minmax(260px, 1fr));
    }
    .customer-meta {
      display: grid;
      gap: 8px;
    }
    .customer-badge {
      display: inline-flex;
      align-items: center;
      gap: 6px;
      padding: 6px 10px;
      border-radius: 999px;
      background: rgba(59,130,246,.12);
      color: #1d4ed8;
      font-weight: 600;
    }
  </style>
</head>
<body>
  <div class="container">
    <div class="main">
      <div class="topbar">
        <a class="btn" href="<?php echo e(base_url('index.php')); ?>">← Kembali</a>
      </div>

      <div class="content">
        <div class="card customer-hero">
          <div>
            <h2 style="margin:0">Akun Pelanggan</h2>
            <p style="margin:6px 0 0;color:var(--muted)">Kelola akun dan cek poin loyalti.</p>
          </div>
          <?php if ($customer): ?>
            <a class="btn" href="<?php echo e(base_url('customer_logout.php')); ?>">Logout</a>
          <?php endif; ?>
        </div>

        <?php if ($notice): ?>
          <div class="card" style="margin-top:12px;border-color:rgba(16,185,129,.3);background:rgba(16,185,129,.08)"><?php echo e($notice); ?></div>
        <?php endif; ?>
        <?php if ($err): ?>
          <div class="card" style="margin-top:12px;border-color:rgba(251,113,133,.35);background:rgba(251,113,133,.10)"><?php echo e($err); ?></div>
        <?php endif; ?>

        <?php if ($customer): ?>
          <div class="customer-grid" style="margin-top:16px">
            <div class="card">
              <h3 style="margin-top:0">Profil</h3>
              <div class="customer-meta">
                <div><strong>Nama:</strong> <?php echo e($customer['name'] ?? '-'); ?></div>
                <div><strong>Nomor Telepon:</strong> <?php echo e($customer['phone'] ?? '-'); ?></div>
                <div><strong>Jenis Kelamin:</strong> <?php echo e($genderLabels[$customer['gender'] ?? ''] ?? '-'); ?></div>
                <div><strong>Tanggal Lahir:</strong> <?php echo e($customer['birth_date'] ?? '-'); ?></div>
              </div>
            </div>
            <div class="card">
              <h3 style="margin-top:0">Poin Loyalti</h3>
              <div style="font-size:28px;font-weight:700;margin-bottom:8px">
                <?php echo e((string)($customer['loyalty_points'] ?? 0)); ?> poin
              </div>
              <div class="customer-badge">Gunakan poin untuk klaim reward di POS.</div>
            </div>
          </div>

          <div class="card" style="margin-top:16px">
            <h3 style="margin-top:0">Reward yang Tersedia</h3>
            <?php if (empty($rewards)): ?>
              <p style="margin:0;color:var(--muted)">Belum ada reward yang tersedia.</p>
            <?php else: ?>
              <table>
                <thead>
                  <tr>
                    <th>Reward</th>
                    <th>Poin Dibutuhkan</th>
                  </tr>
                </thead>
                <tbody>
                  <?php foreach ($rewards as $reward): ?>
                    <tr>
                      <td><?php echo e($reward['name']); ?></td>
                      <td><?php echo e((string)$reward['points_required']); ?> poin</td>
                    </tr>
                  <?php endforeach; ?>
                </tbody>
              </table>
            <?php endif; ?>
          </div>
        <?php else: ?>
          <div class="customer-grid" style="margin-top:16px">
            <div class="card">
              <h3 style="margin-top:0">Login Pelanggan</h3>
              <form method="post">
                <input type="hidden" name="_csrf" value="<?php echo e(csrf_token()); ?>">
                <input type="hidden" name="action" value="login">
                <div class="row">
                  <label>Nomor Telepon</label>
                  <input name="phone" type="tel" inputmode="tel" required>
                </div>
                <div class="row">
                  <label>Password</label>
                  <input name="password" type="password" required>
                </div>
                <button class="btn" type="submit">Masuk</button>
              </form>
            </div>

            <div class="card">
              <h3 style="margin-top:0">Daftar Pelanggan</h3>
              <form method="post" class="customer-register">
                <input type="hidden" name="_csrf" value="<?php echo e(csrf_token()); ?>">
                <input type="hidden" name="action" value="register">
                <div class="row">
                  <label>Nama</label>
                  <input name="name" required>
                </div>
                <div class="row">
                  <label>Nomor Telepon</label>
                  <input name="phone" type="tel" inputmode="tel" required>
                </div>
                <div class="row">
                  <label>Password</label>
                  <input name="password" type="password" minlength="6" required>
                </div>
                <div class="row">
                  <label>Jenis Kelamin</label>
                  <select name="gender" required>
                    <option value="">-- pilih --</option>
                    <option value="male">Laki-laki</option>
                    <option value="female">Perempuan</option>
                    <option value="other">Lainnya</option>
                  </select>
                </div>
                <div class="row">
                  <label>Tanggal Lahir</label>
                  <input name="birth_date" type="date" required>
                </div>
                <?php if (!empty($recaptchaSiteKey)): ?>
                  <input type="hidden" name="g-recaptcha-response" id="recaptcha-register-token">
                <?php else: ?>
                  <div class="card" style="margin-top:12px;border-color:rgba(251,113,133,.35);background:rgba(251,113,133,.10)">
                    reCAPTCHA belum disetting. Hubungi admin.
                  </div>
                <?php endif; ?>
                <button class="btn" type="submit" <?php echo $recaptchaSiteKey === '' ? 'disabled' : ''; ?>>Daftar</button>
              </form>
            </div>
          </div>
        <?php endif; ?>
      </div>
    </div>
  </div>
  <script defer src="<?php echo e(asset_url('assets/app.js')); ?>"></script>
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
        const form = document.querySelector('.customer-register');
        const tokenInput = document.getElementById('recaptcha-register-token');
        const canStoreToken = !!form && !!tokenInput;
        const siteKey = '<?php echo e($recaptchaSiteKey); ?>';
        const action = '<?php echo e($recaptchaAction); ?>';
        const timeoutMs = 8000;
        const refreshMs = 90 * 1000;
        let tokenPromise = null;
        let lastTokenAt = 0;

        function setSubmitBusy(isBusy) {
          if (!form) return;
          const submitButton = form.querySelector('button[type="submit"]');
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
          if (!opts.force && lastTokenAt > 0 && Date.now() - lastTokenAt < refreshMs) {
            return Promise.resolve(tokenInput ? tokenInput.value : 'ok');
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
                  if (canStoreToken) {
                    tokenInput.value = token;
                    form.dataset.recaptchaReady = '1';
                    form.dataset.recaptchaReadyAt = String(lastTokenAt);
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
          // Best effort on page load to trigger badge visibility.
        });

        window.setInterval(function () {
          if (form && form.dataset.submitting === '1') return;
          requestToken({ force: true }).catch(function () {
            // Silent retry for token refresh.
          });
        }, refreshMs);

        if (!canStoreToken) return;

        form.addEventListener('submit', function (event) {
          if (form.dataset.submitting === '1') {
            event.preventDefault();
            return;
          }

          event.preventDefault();
          form.dataset.submitting = '1';
          form.dataset.recaptchaReady = '';
          setSubmitBusy(true);

          requestToken({ force: true })
            .then(function (token) {
              if (!token || !tokenInput.value) {
                throw new Error('recaptcha-empty-token');
              }
              form.submit();
            })
            .catch(function (error) {
              form.dataset.submitting = '';
              form.dataset.recaptchaReady = '';
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
