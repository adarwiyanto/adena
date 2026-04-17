<?php
require_once __DIR__ . '/core/db.php';
require_once __DIR__ . '/core/functions.php';
require_once __DIR__ . '/core/security.php';
require_once __DIR__ . '/core/auth.php';
require_once __DIR__ . '/core/csrf.php';
require_once __DIR__ . '/core/customer_auth.php';
require_once __DIR__ . '/core/auth_helpers.php';

start_secure_session();
ensure_landing_order_tables();
ensure_loyalty_rewards_table();
customer_bootstrap_from_cookie();

$mode = (($_GET['mode'] ?? 'login') === 'register') ? 'register' : 'login';
$err = '';
$notice = '';
$recaptchaSiteKey = setting('recaptcha_site_key', '');
$recaptchaSecretKey = setting('recaptcha_secret_key', '');
$recaptchaAction = 'customer_register';
$recaptchaMinScore = 0.5;

$me = current_user();
if ($me) {
  redirect(resolve_default_landing_page_for_user($me));
}
$customer = customer_current();
if ($customer) {
  redirect(base_url('customer.php'));
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
  csrf_check();
  $action = (string)($_POST['action'] ?? 'login');
  $mode = $action === 'register' ? 'register' : 'login';
  try {
    if ($action === 'login') {
      $username = normalize_username((string)($_POST['username'] ?? ''));
      $password = (string)($_POST['password'] ?? '');
      if ($username === '' || $password === '') {
        throw new Exception('Username dan password wajib diisi.');
      }
      $rateId = $username . '|' . ($_SERVER['REMOTE_ADDR'] ?? 'unknown');
      if (!rate_limit_check('unified_login', $rateId)) {
        throw new Exception('Terlalu banyak percobaan login. Silakan coba lagi nanti.');
      }

      if (login_attempt($username, $password)) {
        rate_limit_clear('unified_login', $rateId);
        $me = current_user();
        redirect(resolve_default_landing_page_for_user($me));
      }
      if (customer_login_by_username($username, $password)) {
        rate_limit_clear('unified_login', $rateId);
        $_SESSION['customer_notice'] = 'Login berhasil.';
        redirect(base_url('customer.php'));
      }
      rate_limit_record('unified_login', $rateId);
      throw new Exception('Username atau password salah.');
    }

    if ($action === 'register') {
      $name = trim((string)($_POST['name'] ?? ''));
      $username = normalize_username((string)($_POST['username'] ?? ''));
      $email = trim((string)($_POST['email'] ?? ''));
      $phone = trim((string)($_POST['phone'] ?? ''));
      $password = (string)($_POST['password'] ?? '');
      $gender = trim((string)($_POST['gender'] ?? ''));
      $birthDate = trim((string)($_POST['birth_date'] ?? ''));

      if ($name === '') throw new Exception('Nama wajib diisi.');
      if ($username === '') throw new Exception('Username wajib diisi.');
      if (!is_valid_username_format($username)) {
        throw new Exception('Format username tidak valid. Gunakan huruf/angka/underscore/titik/strip tanpa spasi.');
      }
      if (username_exists_anywhere($username)) {
        throw new Exception('Username sudah digunakan. Silakan gunakan username lain.');
      }
      if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
        throw new Exception('Email wajib valid.');
      }
      $stmt = db()->prepare("SELECT id FROM customers WHERE email = ? LIMIT 1");
      $stmt->execute([$email]);
      if ($stmt->fetch()) {
        throw new Exception('Email sudah terdaftar, diminta memasukan email lainnya.');
      }
      if ($phone === '') throw new Exception('Nomor telepon wajib diisi.');
      if (!preg_match('/^[0-9+][0-9\\s\\-]{6,20}$/', $phone)) {
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
      if (!verify_recaptcha_response($recaptchaToken, $recaptchaSecretKey, $_SERVER['REMOTE_ADDR'] ?? '', $recaptchaAction, $recaptchaMinScore)) {
        throw new Exception('Verifikasi reCAPTCHA gagal.');
      }

      $stmt = db()->prepare("SELECT id FROM customers WHERE phone = ? LIMIT 1");
      $stmt->execute([$phone]);
      if ($stmt->fetch()) {
        throw new Exception('Nomor telepon sudah terdaftar.');
      }

      $hash = password_hash($password, PASSWORD_DEFAULT);
      $stmt = db()->prepare("
        INSERT INTO customers (name, username, email, phone, password_hash, gender, birth_date)
        VALUES (?,?,?,?,?,?,?)
      ");
      $stmt->execute([$name, $username, $email, $phone, $hash, $gender, $birthDate]);
      $customerId = (int)db()->lastInsertId();
      $stmt = db()->prepare("SELECT * FROM customers WHERE id = ? LIMIT 1");
      $stmt->execute([$customerId]);
      $customer = $stmt->fetch();
      if ($customer) {
        customer_create_session($customer);
      }
      $_SESSION['customer_notice'] = 'Pendaftaran berhasil. Selamat datang!';
      redirect(base_url('customer.php'));
    }
  } catch (Throwable $e) {
    $err = $e->getMessage();
  }
}

$customCss = setting('custom_css', '');
?>
<!doctype html>
<html>
<head>
  <meta charset="utf-8">
  <meta name="viewport" content="width=device-width,initial-scale=1">
  <title>Masuk / Daftar</title>
  <link rel="icon" href="<?php echo e(favicon_url()); ?>">
  <link rel="stylesheet" href="<?php echo e(asset_url('assets/app.css')); ?>">
  <style><?php echo $customCss; ?></style>
  <style>
    .auth-wrap{max-width:640px;margin:6vh auto}
    .auth-tabs{display:flex;gap:8px;margin-bottom:14px}
    .auth-tab{flex:1;text-align:center;padding:10px;border:1px solid var(--border);border-radius:10px;text-decoration:none}
    .auth-tab.active{background:#0f172a;color:#fff}
  </style>
</head>
<body>
  <div class="auth-wrap">
    <div class="card">
      <div style="display:flex;justify-content:space-between;align-items:center;gap:8px;flex-wrap:wrap">
        <h2 style="margin:0">Masuk / Daftar</h2>
        <a class="btn btn-light" href="<?php echo e(base_url('index.php')); ?>">← Kembali</a>
      </div>
      <?php if ($err): ?>
        <div class="card" style="margin-top:12px;border-color:rgba(251,113,133,.35);background:rgba(251,113,133,.10)"><?php echo e($err); ?></div>
      <?php endif; ?>
      <?php if ($notice): ?>
        <div class="card" style="margin-top:12px;border-color:rgba(16,185,129,.3);background:rgba(16,185,129,.08)"><?php echo e($notice); ?></div>
      <?php endif; ?>
      <div class="auth-tabs" style="margin-top:14px">
        <a class="auth-tab <?php echo $mode === 'login' ? 'active' : ''; ?>" href="<?php echo e(base_url('auth.php?mode=login')); ?>">Masuk</a>
        <a class="auth-tab <?php echo $mode === 'register' ? 'active' : ''; ?>" href="<?php echo e(base_url('auth.php?mode=register')); ?>">Daftar Customer</a>
      </div>

      <?php if ($mode === 'login'): ?>
        <form method="post">
          <input type="hidden" name="_csrf" value="<?php echo e(csrf_token()); ?>">
          <input type="hidden" name="action" value="login">
          <div class="row">
            <label>Username</label>
            <input name="username" autocomplete="username" required>
          </div>
          <div class="row">
            <label>Password</label>
            <input type="password" name="password" autocomplete="current-password" required>
          </div>
          <button class="btn" type="submit">Masuk</button>
        </form>
      <?php else: ?>
        <form method="post" class="customer-register">
          <input type="hidden" name="_csrf" value="<?php echo e(csrf_token()); ?>">
          <input type="hidden" name="action" value="register">
          <div class="row"><label>Nama</label><input name="name" required></div>
          <div class="row"><label>Username</label><input name="username" required></div>
          <div class="row"><label>Email</label><input name="email" type="email" required></div>
          <div class="row"><label>Nomor Telepon</label><input name="phone" type="tel" inputmode="tel" required></div>
          <div class="row"><label>Password</label><input name="password" type="password" minlength="6" required></div>
          <div class="row">
            <label>Jenis Kelamin</label>
            <select name="gender" required>
              <option value="">-- pilih --</option>
              <option value="male">Laki-laki</option>
              <option value="female">Perempuan</option>
              <option value="other">Lainnya</option>
            </select>
          </div>
          <div class="row"><label>Tanggal Lahir</label><input name="birth_date" type="date" required></div>
          <?php if (!empty($recaptchaSiteKey)): ?>
            <input type="hidden" name="g-recaptcha-response" id="recaptcha-register-token">
          <?php else: ?>
            <div class="card" style="margin-top:12px;border-color:rgba(251,113,133,.35);background:rgba(251,113,133,.10)">
              reCAPTCHA belum disetting. Hubungi admin.
            </div>
          <?php endif; ?>
          <button class="btn" type="submit" <?php echo $recaptchaSiteKey === '' ? 'disabled' : ''; ?>>Daftar</button>
        </form>
      <?php endif; ?>
    </div>
  </div>

  <?php if ($mode === 'register' && !empty($recaptchaSiteKey)): ?>
    <script defer src="https://www.google.com/recaptcha/api.js?render=<?php echo e($recaptchaSiteKey); ?>"></script>
    <script nonce="<?php echo e(csp_nonce()); ?>">
      (function () {
        const form = document.querySelector('.customer-register');
        const tokenInput = document.getElementById('recaptcha-register-token');
        if (!form || !tokenInput) return;
        const siteKey = '<?php echo e($recaptchaSiteKey); ?>';
        const action = '<?php echo e($recaptchaAction); ?>';

        function requestToken() {
          return new Promise(function (resolve, reject) {
            if (!window.grecaptcha || !window.grecaptcha.ready) {
              reject(new Error('recaptcha-not-ready'));
              return;
            }
            window.grecaptcha.ready(function () {
              window.grecaptcha.execute(siteKey, { action: action }).then(resolve).catch(reject);
            });
          });
        }

        form.addEventListener('submit', function (event) {
          event.preventDefault();
          requestToken().then(function (token) {
            tokenInput.value = token || '';
            form.submit();
          }).catch(function () {
            alert('reCAPTCHA belum siap. Silakan coba lagi.');
          });
        });
      })();
    </script>
  <?php endif; ?>
</body>
</html>
