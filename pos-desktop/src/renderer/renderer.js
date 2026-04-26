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
    const fd = new FormData(e.target);
    const resp = await window.desktopAPI.login({ username: fd.get('username'), password: fd.get('password') });
    state.user = resp.data.user;
    $('#user-label').textContent = `${state.user.name} (${state.user.role})`;
    await window.desktopAPI.syncMaster();
    await loadPosState();
    $('#login-view').classList.remove('active');
    $('#pos-view').classList.add('active');
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
