const state = {
  user: null,
  products: [],
  categories: [],
  guides: [],
  paymentMethods: [],
  banks: [],
  cart: [],
  latestReceipt: null,
  paying: false,
  activeCategory: '',
  theme: {}
};
const bankRequiredCodes = new Set(['qris', 'transfer', 'edc', 'credit_card']);
const $ = (s) => document.querySelector(s);
let statusTimer;
let toastTimer;

function showStatus(message, show = true) {
  const el = $('#app-status');
  if (!el) return;
  el.textContent = message || '';
  el.classList.toggle('show', !!show && !!message);
}

function showToast(message, type = 'error', timeout = 3200) {
  const el = $('#app-toast');
  if (!el || !message) return;
  el.textContent = message;
  el.classList.add('show');
  el.classList.toggle('success', type === 'success');
  clearTimeout(toastTimer);
  toastTimer = setTimeout(() => el.classList.remove('show'), timeout);
}

async function withFeedback(label, fn) {
  clearTimeout(statusTimer);
  showStatus(`${label}...`, true);
  try {
    const result = await fn();
    showStatus(`${label} selesai`, true);
    statusTimer = setTimeout(() => showStatus('', false), 1200);
    return result;
  } catch (error) {
    showStatus('', false);
    showToast(error.message || `${label} gagal`);
    throw error;
  }
}

function switchTab(name) {
  document.querySelectorAll('.tab').forEach((t) => t.classList.toggle('active', t.dataset.tab === name));
  document.querySelectorAll('.tab-panel').forEach((t) => t.classList.toggle('active', t.dataset.panel === name));
}

function rupiah(v) { return `Rp ${Number(v || 0).toLocaleString('id-ID')}`; }

function applyTheme(settings = {}) {
  const root = document.documentElement;
  const theme = {
    '--desktop-primary': settings.theme_primary || '#0f172a',
    '--desktop-secondary': settings.theme_secondary || '#111827',
    '--desktop-accent': settings.theme_accent || '#1d4ed8',
    '--desktop-surface': settings.theme_surface || '#ffffff',
    '--desktop-sidebar': settings.theme_sidebar || '#f8fafc',
    '--desktop-header': settings.theme_header || settings.theme_primary || '#0f172a',
    '--desktop-text': settings.theme_text || '#0f172a',
    '--desktop-muted': settings.theme_muted || '#64748b'
  };
  Object.entries(theme).forEach(([k, v]) => root.style.setProperty(k, v));
  const logo = settings.store_logo || '';
  $('#brand-logo').src = logo ? toAbsoluteImageUrl(logo) : '';
  $('#brand-logo').classList.toggle('hidden', !logo);
}

function toAbsoluteImageUrl(path) {
  const p = String(path || '').trim();
  if (!p) return '';
  if (/^https?:\/\//i.test(p) || p.startsWith('data:')) return p;
  const baseUrl = String($('#api-base-url').value || '').trim().replace(/\/$/, '');
  if (!baseUrl) return p;
  return `${baseUrl}/${p.replace(/^\//, '')}`;
}

function renderCategories() {
  const wrap = $('#categories');
  wrap.innerHTML = state.categories.map((c) => `<button class="category ${String(c.id) === state.activeCategory ? 'active' : ''}" data-category-id="${c.id}">${c.name}</button>`).join('');
  wrap.querySelectorAll('button').forEach((b) => b.onclick = () => { state.activeCategory = b.dataset.categoryId; renderProducts($('#product-search').value); renderCategories(); document.querySelector('[data-category=""]').classList.remove('active'); });
}

function matchesCategory(product, activeCategory) {
  if (!activeCategory) return true;
  const pCat = product.category_id ?? product.category;
  return String(pCat ?? '') === String(activeCategory);
}

function renderProducts(filter = '') {
  const wrap = $('#products');
  const q = filter.toLowerCase();
  const filtered = state.products
    .filter((p) => matchesCategory(p, state.activeCategory))
    .filter((p) => p.name.toLowerCase().includes(q));

  if (!filtered.length) {
    wrap.innerHTML = `<div class="empty">${state.activeCategory ? 'Tidak ada produk pada kategori ini.' : 'Tidak ada produk tersedia.'}</div>`;
    return;
  }

  wrap.innerHTML = '';
  filtered.forEach((p) => {
    const div = document.createElement('div');
    div.className = 'product-card';
    const image = toAbsoluteImageUrl(p.image_path || '');
    div.innerHTML = `<img src="${image}" alt="${p.name}" onerror="this.src='';this.classList.add('is-placeholder')"/><strong>${p.name}</strong><div>${rupiah(p.price)}</div>`;
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
  wrap.innerHTML = state.cart.map((i) => `<div class="cart-row"><span>${i.name} x${i.qty}</span><span>${rupiah(i.qty * i.price_each)}</span></div>`).join('');
  $('#cart-total').textContent = `Total: ${rupiah(state.cart.reduce((a, b) => a + b.qty * b.price_each, 0))}`;
}

function renderPaymentOptions() {
  $('#guide').innerHTML = `<option value="">Pilih Guide</option>${state.guides.map((g) => `<option value="${g.id}">${g.name}</option>`).join('')}`;
  $('#payment-method').innerHTML = state.paymentMethods.map((m) => `<option value="${m.code}">${m.name}</option>`).join('');
  $('#payment-bank').innerHTML = `<option value="">Pilih Bank</option>${state.banks.map((b) => `<option value="${b.id}">${b.name}</option>`).join('')}`;
  $('#history-guide-filter').innerHTML = `<option value="">Semua guide</option>${state.guides.map((g) => `<option value="${g.name}">${g.name}</option>`).join('')}`;
  $('#history-payment-filter').innerHTML = `<option value="">Semua pembayaran</option>${state.paymentMethods.map((m) => `<option value="${m.code}">${m.name}</option>`).join('')}`;
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
  state.categories = pos.categories || [];
  state.guides = pos.guides || [];
  state.paymentMethods = pos.paymentMethods || [];
  state.banks = pos.banks || [];
  state.theme = pos.syncedSettings || {};
  applyTheme(state.theme);
  $('#sync-count').textContent = `Pending: ${pos.pendingSyncCount || 0} | Shift: ${pos.pendingShiftSync || 0}`;
  const shiftActive = !!pos.activeShift;
  $('#shift-status').textContent = shiftActive ? `Shift aktif: ${pos.activeShift.shift_code || pos.activeShift.id}` : 'Shift: tidak aktif';
  $('#btn-shift-toggle').textContent = shiftActive ? 'Tutup Shift' : 'Buka Shift';
  renderCategories();
  renderProducts();
  renderPaymentOptions();
}

function renderReceipt() { /* unchanged core */
  const w = $('#receipt-wrap');
  if (!state.latestReceipt) { w.innerHTML = '<p>Belum ada transaksi.</p>'; return; }
  w.innerHTML = `<h3>Receipt ${state.latestReceipt.transactionCode}</h3>
  <div>Waktu lokal: ${state.latestReceipt.soldAt}</div>
  <div>Kasir: ${state.user?.name || '-'}</div>
  <div>Guide: ${state.latestReceipt.guideName || '-'}</div>
  <div>Metode: ${state.latestReceipt.paymentMethod}</div>
  <div>Bank: ${state.latestReceipt.paymentBank || '-'}</div>
  <hr/>
  ${state.latestReceipt.items.map((i) => `<div class='cart-row'><span>${i.name} x${i.qty}</span><span>${rupiah(i.qty * i.price_each)}</span></div>`).join('')}
  <div class='cart-total'>Total: ${rupiah(state.latestReceipt.total)}</div>
  <button id='btn-print'>Print</button>
  <button id='btn-new-transaction'>Transaksi Baru</button>`;
  $('#btn-print').onclick = async () => {
    const settings = await window.desktopAPI.getSettings();
    try {
      await window.desktopAPI.printReceipt({ html: w.innerHTML, printerName: settings.printerName, silent: true });
      alert('Print sukses');
    } catch (e) { alert(`Print gagal: ${e.message}`); }
  };
  $('#btn-new-transaction').onclick = () => {
    state.cart = []; state.latestReceipt = null; renderCart();
    $('#guide').value = ''; $('#payment-bank').value = ''; $('#payment-method').selectedIndex = 0; updateBankState(); switchTab('pos');
  };
}

async function payNow() {
  if (state.paying) return;
  if (!state.cart.length) return alert('Keranjang kosong');
  const paymentMethod = $('#payment-method').value;
  const bankId = $('#payment-bank').value;
  if (bankRequiredCodes.has(paymentMethod) && !bankId) return alert('Bank wajib dipilih untuk non tunai');

  state.paying = true; $('#btn-pay').disabled = true;
  try {
    const guide = state.guides.find((g) => String(g.id) === $('#guide').value) || null;
    const bank = state.banks.find((b) => String(b.id) === bankId) || null;
    const localSave = await window.desktopAPI.saveSaleLocal({ user: state.user, guide, payment: { method: paymentMethod, bank_id: bank?.id || null, bank_name: bank?.name || null }, items: state.cart });
    state.latestReceipt = { transactionCode: localSave.transactionCode, soldAt: localSave.soldAt, paymentMethod, paymentBank: bank?.name || '', guideName: guide?.name || '', items: [...state.cart], total: state.cart.reduce((a, b) => a + b.qty * b.price_each, 0) };
    try { if (navigator.onLine) await window.desktopAPI.syncPending(); } catch (_) {}
    switchTab('receipt'); renderReceipt(); await loadPosState();
  } finally { state.paying = false; $('#btn-pay').disabled = false; }
}

async function loadHistory() { /* unchanged */
  const data = await window.desktopAPI.getHistory({ from: $('#history-from').value.trim(), to: $('#history-to').value.trim(), guideName: $('#history-guide-filter').value, paymentMethod: $('#history-payment-filter').value, syncStatus: $('#history-sync-filter').value });
  if (!data?.ok) { showToast(data?.message || 'Gagal memuat riwayat'); $('#history-list').innerHTML = ''; $('#history-omzet').textContent = 'Ringkasan Omzet: Rp 0'; return; }
  $('#history-omzet').textContent = `Ringkasan Omzet: ${rupiah(data.omzet)}`;
  $('#history-list').innerHTML = data.rows.map((r) => `<div class='row'><strong>${r.transaction_code}</strong> | ${r.sold_at} | ${r.guide_name || '-'} | ${r.payment_method} ${r.payment_bank || ''} | ${r.sync_status} | ${rupiah(r.total)} <button data-id='${r.transaction_group_id}'>Detail</button></div>`).join('');
  $('#history-list').querySelectorAll('button').forEach((b) => b.onclick = async () => { const d = await window.desktopAPI.getHistoryDetail(b.dataset.id); alert(d.items.map((i) => `${i.product_name} x${i.qty} ${rupiah(i.total)}`).join('\n')); });
}

async function loadOrders() {
  const data = await window.desktopAPI.getOrders();
  const grouped = new Map();
  (data.items || []).forEach((i) => { if (!grouped.has(i.order_id)) grouped.set(i.order_id, []); grouped.get(i.order_id).push(i); });
  $('#orders-list').innerHTML = (data.orders || []).map((o) => `<div class='row'><strong>${o.order_code}</strong> | ${o.created_at} | ${o.customer_name || '-'} | ${o.customer_contact || '-'} | ${o.status}<br/>${(grouped.get(o.id) || []).map((x) => `${x.product_name} x${x.qty}`).join(', ')}</div>`).join('') || 'Belum ada order masuk.';
}

async function initApiDialog() {
  const s = await window.desktopAPI.getSettings();
  $('#api-base-url').value = s.apiBaseUrl || '';
  $('#api-token').value = s.apiToken || '';
  $('#api-status').textContent = '';
}

async function initPrinterDialog() {
  const s = await window.desktopAPI.getSettings();
  $('#receipt-width').value = s.receiptWidthMm || 58;
  $('#receipt-margin').value = s.receiptMarginMm || 2;
  const printers = await window.desktopAPI.getPrinters();
  $('#printer-name').innerHTML = `<option value=''>Default Sistem</option>${printers.map((p) => `<option value="${p.name}">${p.displayName}${p.isDefault ? ' (default)' : ''}</option>`).join('')}`;
  $('#printer-name').value = s.printerName || '';
  $('#printer-status').textContent = '';
}

async function bootstrap() {
  await initApiDialog();
  await initPrinterDialog();
  $('#login-view').classList.add('active');
  $('#pos-view').classList.remove('active');
  $('#online-badge').textContent = navigator.onLine ? 'online' : 'offline';
  $('#online-badge').classList.toggle('online', navigator.onLine);

  document.querySelectorAll('.tab').forEach((t) => t.onclick = async () => { switchTab(t.dataset.tab); if (t.dataset.tab === 'history') await withFeedback('Muat riwayat', loadHistory); if (t.dataset.tab === 'orders') await withFeedback('Muat order', loadOrders); });

  $('#btn-open-api').onclick = async () => { await initApiDialog(); $('#api-dialog').showModal(); };
  $('#btn-open-printer').onclick = async () => { await initPrinterDialog(); $('#printer-dialog').showModal(); };
  $('#btn-close-api').onclick = () => $('#api-dialog').close();
  $('#btn-close-printer').onclick = () => $('#printer-dialog').close();

  $('#btn-save-api').onclick = async () => {
    await withFeedback('Simpan setting API', async () => {
      await window.desktopAPI.setSettings({ apiBaseUrl: $('#api-base-url').value.trim(), apiToken: $('#api-token').value.trim() });
      $('#api-status').textContent = 'Tersimpan';
      showToast('Setting API tersimpan', 'success');
    });
  };

  $('#btn-test-api').onclick = async () => {
    await withFeedback('Test koneksi API', async () => {
      const r = await window.desktopAPI.testConnection({ baseURL: $('#api-base-url').value.trim(), token: $('#api-token').value.trim() });
      $('#api-status').textContent = r.ok ? 'Koneksi OK' : `Gagal (${r.status || 0}): ${r.message}`;
      if (!r.ok) showToast(`${r.message}${r.detail ? ` (${r.detail})` : ''}`);
    });
  };

  $('#btn-save-printer').onclick = async () => {
    await withFeedback('Simpan printer', async () => {
      await window.desktopAPI.setSettings({ printerName: $('#printer-name').value, receiptWidthMm: Number($('#receipt-width').value || 58), receiptMarginMm: Number($('#receipt-margin').value || 2) });
      $('#printer-status').textContent = 'Tersimpan';
      showToast('Setting printer tersimpan', 'success');
    });
  };

  $('#login-form').onsubmit = async (e) => {
    e.preventDefault();
    const fd = new FormData(e.target);
    const resp = await window.desktopAPI.login({ username: fd.get('username'), password: fd.get('password') });
    if (!resp?.ok) return alert(resp.message || 'Login gagal');
    state.user = resp.user;
    $('#user-label').textContent = `${state.user.name} (${state.user.role})`;
    const syncResp = await window.desktopAPI.syncMaster();
    if (!syncResp?.ok) showToast(`${syncResp?.message || 'Sync master gagal'}${syncResp?.endpoint ? ` (${syncResp.endpoint})` : ''}`);
    await window.desktopAPI.retryPendingShift();
    await loadPosState(); await loadHistory(); await loadOrders();
    $('#login-view').classList.remove('active'); $('#pos-view').classList.add('active');
  };

  $('#btn-logout').onclick = async () => { await window.desktopAPI.logout(); state.user = null; state.cart = []; state.latestReceipt = null; $('#login-form').reset(); $('#login-view').classList.add('active'); $('#pos-view').classList.remove('active'); showToast('Logout berhasil', 'success'); };

  $('#btn-shift-toggle').onclick = async () => {
    await withFeedback('Update shift', async () => {
      const status = await window.desktopAPI.shiftStatus();
      if (!status?.ok) throw new Error(status?.message || 'Gagal memuat status shift');
      const hasShift = !!(status?.shift || status?.has_active_shift);
      const actionResp = hasShift
        ? await window.desktopAPI.closeShift({ user_id: state.user?.id, counted_cash_total: 0, notes: 'Closed from POS Desktop' })
        : await window.desktopAPI.openShift({ user_id: state.user?.id, opening_cash_actual: 0 });
      if (!actionResp?.ok && actionResp?.sync_status !== 'pending') throw new Error(actionResp?.message || actionResp?.error || 'Gagal update shift');
      if (actionResp?.sync_status === 'pending') showToast(`Shift pending sync: ${actionResp.message}`, 'error', 4500);
      const syncResp = await window.desktopAPI.syncMaster();
      if (!syncResp?.ok) showToast(syncResp?.message || 'Sync master gagal');
      await loadPosState();
      showToast(hasShift ? 'Tutup shift diproses' : 'Buka shift diproses', 'success');
    });
  };

  $('#payment-method').onchange = updateBankState;
  $('#btn-pay').onclick = async () => withFeedback('Proses pembayaran', payNow);
  $('#product-search').oninput = (e) => renderProducts(e.target.value);
  document.querySelector('[data-category=""]').onclick = () => { state.activeCategory = ''; renderProducts($('#product-search').value); renderCategories(); document.querySelector('[data-category=""]').classList.add('active'); };
  $('#btn-manual-sync').onclick = async () => {
    await withFeedback('Manual sync', async () => {
      const r = await window.desktopAPI.syncPending();
      if (!r?.ok) showToast(r?.message || 'Sync gagal');
      await window.desktopAPI.retryPendingShift();
      await loadPosState(); await loadOrders();
      showToast(r?.ok ? 'Manual sync selesai' : `Manual sync gagal: ${r?.message || '-'}`, r?.ok ? 'success' : 'error');
    });
  };
  $('#btn-load-history').onclick = async () => withFeedback('Muat riwayat', loadHistory);
}

bootstrap().catch((error) => {
  console.error('[renderer] bootstrap failed', error);
  showToast(error.message || 'Inisialisasi aplikasi gagal');
});
