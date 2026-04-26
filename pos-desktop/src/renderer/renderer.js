const state = {
  user: null,
  products: [],
  guides: [],
  paymentMethods: [],
  banks: [],
  cart: [],
  latestReceipt: null,
  paying: false
};

const bankRequiredCodes = new Set(['qris', 'transfer', 'edc', 'credit_card']);

const $ = (s) => document.querySelector(s);

function setLoginStatus(message) {
  $('#login-status').textContent = message || '';
}

function setLoginError(message) {
  $('#login-error').textContent = message || '';
}

function setLoginBusy(isBusy) {
  const btn = $('#btn-login');
  btn.disabled = !!isBusy;
  btn.textContent = isBusy ? 'Memproses login...' : 'Login';
}

function validateLoginInput({ username, password, settings }) {
  if (!String(username || '').trim()) return 'Username wajib diisi';
  if (!String(password || '').trim()) return 'Password wajib diisi';
  const baseUrl = String(settings.apiBaseUrl || '').trim();
  if (!baseUrl) return 'Base URL API belum disetting';
  const token = String(settings.apiToken || '').trim();
  if (!token) return 'Token API belum disetting';

  let parsed;
  try {
    parsed = new URL(baseUrl);
  } catch (_) {
    return 'Base URL API tidak valid. Gunakan http:// atau https://';
  }
  if (!['http:', 'https:'].includes(parsed.protocol)) {
    return 'Protocol salah. Gunakan https://adena.co.id';
  }
  return '';
}

function mapLoginError(errObj) {
  const status = Number(errObj?.status || 0);
  const detail = String(errObj?.detail || '').toLowerCase();
  const message = String(errObj?.message || '');

  if (message) return message;
  if (status === 401 && detail.includes('token')) return 'Token tidak valid';
  if (status === 401) return 'Username/password salah';
  if (status === 404) return 'Endpoint login tidak ditemukan';
  if (status === 0) return 'Server tidak dapat dihubungi';
  return 'Login gagal. Silakan coba lagi.';
}

async function initSettingsDialog() {
  const s = await window.desktopAPI.getSettings();
  $('#api-base-url').value = s.apiBaseUrl || '';
  $('#api-token').value = s.apiToken || '';
  $('#printer-name').value = s.printerName || '';
  $('#receipt-width').value = s.receiptWidthMm || 58;
  $('#receipt-margin').value = s.receiptMarginMm || 2;
}

function renderProducts(filter = '') {
  const wrap = $('#products');
  const q = filter.toLowerCase();
  wrap.innerHTML = '';
  state.products.filter((p) => p.name.toLowerCase().includes(q)).forEach((p) => {
    const div = document.createElement('div');
    div.className = 'product-card';
    div.innerHTML = `<strong>${p.name}</strong><div>Rp ${Number(p.price).toLocaleString('id-ID')}</div>`;
    div.onclick = () => addToCart(p);
    wrap.appendChild(div);
  });
}

function addToCart(product) {
  const found = state.cart.find((x) => x.product_id === product.id);
  if (found) found.qty += 1;
  else state.cart.push({ product_id: product.id, name: product.name, qty: 1, price_each: Number(product.price) });
  renderCart();
}

function renderCart() {
  const wrap = $('#cart-items');
  wrap.innerHTML = state.cart.map((i) => `<div class="cart-row"><span>${i.name} x${i.qty}</span><span>Rp ${(i.qty*i.price_each).toLocaleString('id-ID')}</span></div>`).join('');
}

function renderPaymentOptions() {
  $('#guide').innerHTML = `<option value="">Pilih Guide</option>${state.guides.map((g) => `<option value="${g.id}">${g.name}</option>`).join('')}`;
  $('#payment-method').innerHTML = state.paymentMethods.map((m) => `<option value="${m.code}">${m.name}</option>`).join('');
  $('#payment-bank').innerHTML = `<option value="">Pilih Bank</option>${state.banks.map((b) => `<option value="${b.id}">${b.name}</option>`).join('')}`;
  updateBankState();
}

function updateBankState() {
  const code = $('#payment-method').value;
  $('#payment-bank').disabled = !bankRequiredCodes.has(code);
  if (!bankRequiredCodes.has(code)) $('#payment-bank').value = '';
}

async function loadPosState() {
  const pos = await window.desktopAPI.getPosState();
  state.products = pos.products || [];
  state.guides = pos.guides || [];
  state.paymentMethods = pos.paymentMethods || [];
  state.banks = pos.banks || [];
  $('#sync-count').textContent = `Pending: ${pos.pendingSyncCount || 0}`;
  $('#last-sync').textContent = `Last Sync: ${pos.lastSyncAt || '-'}`;
  renderProducts();
  renderPaymentOptions();
}

function setOnlineBadge() {
  $('#online-badge').textContent = navigator.onLine ? 'online' : 'offline';
  $('#online-badge').classList.toggle('online', navigator.onLine);
}

function resetTransaction() {
  state.cart = [];
  renderCart();
  $('#payment-method').selectedIndex = 0;
  $('#payment-bank').value = '';
  $('#guide').value = '';
  updateBankState();
  $('#btn-print').disabled = true;
  $('#btn-new-transaction').disabled = true;
}

function buildReceiptHtml(meta) {
  return `<html><body>
    <h3>${meta.storeName || 'Store'}</h3>
    <div>No: ${meta.transactionCode}</div>
    <div>Tanggal: ${meta.soldAt}</div>
    <div>Kasir: ${state.user.name}</div>
    <hr/>
    ${state.latestReceipt.items.map((i) => `<div>${i.name} x${i.qty} - Rp ${(i.qty*i.price_each).toLocaleString('id-ID')}</div>`).join('')}
    <hr/>
    <div>Total: Rp ${state.latestReceipt.total.toLocaleString('id-ID')}</div>
    <div>Metode: ${meta.paymentMethod}</div>
    <div>Bank: ${meta.paymentBank || '-'}</div>
  </body></html>`;
}

async function payNow() {
  if (state.paying) return;
  if (!state.cart.length) return alert('Keranjang kosong');

  const paymentMethod = $('#payment-method').value;
  const bankId = $('#payment-bank').value;
  if (bankRequiredCodes.has(paymentMethod) && !bankId) return alert('Bank wajib dipilih untuk non tunai');

  state.paying = true;
  $('#btn-pay').disabled = true;
  try {
    const guide = state.guides.find((g) => String(g.id) === $('#guide').value) || null;
    const bank = state.banks.find((b) => String(b.id) === bankId) || null;
    const localSave = await window.desktopAPI.saveSaleLocal({
      user: state.user,
      guide,
      payment: { method: paymentMethod, bank_id: bank?.id || null, bank_name: bank?.name || null },
      items: state.cart
    });

    state.latestReceipt = {
      transactionCode: localSave.transactionCode,
      soldAt: localSave.soldAt,
      paymentMethod,
      paymentBank: bank?.name || '',
      items: [...state.cart],
      total: state.cart.reduce((a, b) => a + b.qty * b.price_each, 0)
    };

    if (navigator.onLine) {
      try { await window.desktopAPI.syncPending(); } catch (_) {}
    }

    $('#btn-print').disabled = false;
    $('#btn-new-transaction').disabled = false;
    await loadPosState();
    alert('Transaksi tersimpan lokal. Sync dijalankan otomatis.');
  } finally {
    state.paying = false;
    $('#btn-pay').disabled = false;
  }
}

async function bootstrap() {
  await initSettingsDialog();
  const existingSession = await window.desktopAPI.getSession();
  if (existingSession?.ok && existingSession.user) {
    state.user = existingSession.user;
    $('#user-label').textContent = `${state.user.name} (${state.user.role})`;
    try {
      await window.desktopAPI.syncMaster();
    } catch (e) {
      console.warn('[login] sync master skipped:', e?.message || e);
    }
    await loadPosState();
    $('#login-view').classList.remove('active');
    $('#pos-view').classList.add('active');
  }
  setOnlineBadge();
  window.addEventListener('online', async () => { setOnlineBadge(); await window.desktopAPI.syncPending(); await loadPosState(); });
  window.addEventListener('offline', setOnlineBadge);

  $('#btn-open-api').onclick = () => $('#api-dialog').showModal();
  $('#btn-close-api').onclick = () => $('#api-dialog').close();
  $('#btn-save-api').onclick = async () => {
    await window.desktopAPI.setSettings({
      apiBaseUrl: $('#api-base-url').value,
      apiToken: $('#api-token').value,
      printerName: $('#printer-name').value,
      receiptWidthMm: Number($('#receipt-width').value || 58),
      receiptMarginMm: Number($('#receipt-margin').value || 2)
    });
    $('#api-status').textContent = 'Tersimpan';
  };

  $('#btn-test-api').onclick = async () => {
    try {
      const r = await window.desktopAPI.testConnection();
      $('#api-status').textContent = `OK: ${r.message || 'connected'}`;
    } catch (e) {
      $('#api-status').textContent = `Gagal: ${e.message}`;
    }
  };

  $('#login-form').onsubmit = async (e) => {
    e.preventDefault();
    console.log('[login] clicked');
    setLoginError('');
    setLoginStatus('');
    setLoginBusy(true);

    try {
      const fd = new FormData(e.target);
      const payload = {
        username: String(fd.get('username') || '').trim(),
        password: String(fd.get('password') || '')
      };
      const settings = await window.desktopAPI.getSettings();
      const validationMessage = validateLoginInput({ ...payload, settings });
      if (validationMessage) {
        setLoginError(validationMessage);
        setLoginStatus('API belum disetting / data login belum lengkap');
        return;
      }

      setLoginStatus('Sedang login...');
      const resp = await window.desktopAPI.login(payload);
      if (!resp?.ok) {
        const msg = mapLoginError(resp);
        setLoginError(msg);
        setLoginStatus('Gagal login');
        return;
      }

      state.user = resp.user;
      $('#user-label').textContent = `${state.user.name} (${state.user.role})`;
      setLoginStatus('Login sukses');
      try {
        await window.desktopAPI.syncMaster();
      } catch (syncErr) {
        console.warn('[login] sync master failed:', syncErr?.message || syncErr);
      }
      await loadPosState();
      $('#login-view').classList.remove('active');
      $('#pos-view').classList.add('active');
    } catch (err) {
      console.error('[login] renderer error:', err?.message || err);
      setLoginError(err?.message || 'Server tidak dapat dihubungi');
      setLoginStatus('Gagal login');
    } finally {
      setLoginBusy(false);
    }
  };

  $('#payment-method').onchange = updateBankState;
  $('#btn-pay').onclick = payNow;
  $('#btn-new-transaction').onclick = resetTransaction;
  $('#product-search').oninput = (e) => renderProducts(e.target.value);

  $('#btn-manual-sync').onclick = async () => {
    try {
      await window.desktopAPI.syncPending();
      await loadPosState();
      alert('Manual sync selesai');
    } catch (e) {
      alert(`Manual sync gagal: ${e.message}`);
    }
  };

  $('#btn-print').onclick = async () => {
    const settings = await window.desktopAPI.getSettings();
    const html = buildReceiptHtml({
      ...state.latestReceipt,
      storeName: 'Adena POS',
      paymentMethod: state.latestReceipt.paymentMethod,
      paymentBank: state.latestReceipt.paymentBank
    });
    await window.desktopAPI.printReceipt({ html, printerName: settings.printerName, silent: true });
  };
}

bootstrap();
