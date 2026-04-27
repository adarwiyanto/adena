const state = { user: null, products: [], categories: [], guides: [], paymentMethods: [], banks: [], cart: [], latestReceipt: null, paying: false, activeCategory: null, theme: {}, syncRetry: 0, syncSuccess: false, apiTokenMasked: '(kosong)', debugMode: false };
const bankRequiredCodes = new Set(['qris', 'transfer', 'edc', 'credit_card']);
const SYNC_MODULES = ['Koneksi API', 'Produk', 'Kategori', 'Guide', 'Bank/payment', 'Setting/theme/logo', 'Thumbnail produk', 'Shift', 'Riwayat transaksi', 'Order landing page', 'Pending transaksi lokal', 'Pending shift lokal'];
const $ = (s) => document.querySelector(s);
let toastTimer;
const imageCacheQueue = new Set();

function showView(id) { ['login-view', 'sync-view', 'pos-view'].forEach((v) => document.getElementById(v).classList.toggle('active', v === id)); }
function rupiah(v) { return `Rp ${Number(v || 0).toLocaleString('id-ID')}`; }
function showToast(message, type = 'error') { const el = $('#app-toast'); el.textContent = message; el.classList.add('show'); el.classList.toggle('success', type === 'success'); clearTimeout(toastTimer); toastTimer = setTimeout(() => el.classList.remove('show'), 3000); }
function maskToken(token) { const t = String(token || '').trim(); if (!t) return '(kosong)'; if (t.length <= 6) return `${t.slice(0, 2)}***`; return `${t.slice(0, 4)}***${t.slice(-2)}`; }

function toAbsoluteImageUrl(path) {
  const p = String(path || '').trim();
  if (!p) return '';
  if (/^https?:\/\//i.test(p) || p.startsWith('data:') || p.startsWith('file://')) return p;
  const baseUrl = String($('#api-base-url').value || '').trim().replace(/\/$/, '');
  return baseUrl ? `${baseUrl}/${p.replace(/^\//, '')}` : p;
}

function productImageOnlineUrl(product) {
  if (!product?.image_path) return '';
  const raw = String(product.image_path || '').trim();
  if (!raw) return '';
  if (/^https?:\/\//i.test(raw) || raw.startsWith('data:')) return raw;
  const baseUrl = String($('#api-base-url').value || '').trim().replace(/\/$/, '');
  if (!baseUrl) return raw;

  // Prefer the token-protected media endpoint for private_uploads images.
  const qs = new URLSearchParams({ id: String(product.id) });
  qs.set('v', raw);
  return `${baseUrl}/api/media/product-image.php?${qs.toString()}`;
}

async function cacheProductImageInBackground(product, imgEl) {
  if (!product?.id || !product?.image_path || product.local_image_path) return;
  const key = `${product.id}:${product.image_path}`;
  if (imageCacheQueue.has(key)) return;
  imageCacheQueue.add(key);
  try {
    const res = await window.desktopAPI.cacheProductImage({ productId: product.id, imagePath: product.image_path });
    if (res?.ok && res.local_image_path) {
      product.local_image_path = res.local_image_path;
      if (imgEl && document.body.contains(imgEl)) {
        imgEl.src = res.local_image_path;
        imgEl.classList.remove('is-placeholder');
      }
    }
  } catch (error) {
    console.warn('[image:cache] renderer failed', error);
  } finally {
    imageCacheQueue.delete(key);
  }
}

function applyTheme(settings = {}) {
  const root = document.documentElement;
  const theme = { '--desktop-primary': settings.theme_primary || '#0f172a', '--desktop-secondary': settings.theme_secondary || '#111827', '--desktop-accent': settings.theme_accent || '#1d4ed8', '--desktop-surface': settings.theme_surface || '#ffffff', '--desktop-sidebar': settings.theme_sidebar || '#f8fafc', '--desktop-header': settings.theme_header || settings.theme_primary || '#0f172a', '--desktop-text': settings.theme_text || '#0f172a', '--desktop-muted': settings.theme_muted || '#64748b' };
  Object.entries(theme).forEach(([k, v]) => root.style.setProperty(k, v));
  const logo = settings.store_logo || '';
  $('#brand-logo').src = logo ? toAbsoluteImageUrl(logo) : '';
  $('#brand-logo').classList.toggle('hidden', !logo);
}

function matchesCategory(product, activeCategory) {
  if (!activeCategory) return true;
  const productCatId = product.category_id != null ? String(product.category_id) : '';
  const productCatName = String(product.category_name || product.category || '').trim().toLowerCase();
  const activeId = activeCategory.id != null ? String(activeCategory.id) : '';
  const activeName = String(activeCategory.name || '').trim().toLowerCase();
  if (activeId && productCatId && activeId === productCatId) return true;
  if (activeName && productCatName && activeName === productCatName) return true;
  return false;
}

function renderCategories() {
  const wrap = $('#categories');
  wrap.innerHTML = state.categories.map((c) => `<button class="category ${(state.activeCategory && String(c.id) === String(state.activeCategory.id)) ? 'active' : ''}" data-category-id="${c.id}" data-category-name="${c.name}">${c.name}</button>`).join('');
  wrap.querySelectorAll('button').forEach((b) => b.onclick = () => { state.activeCategory = { id: b.dataset.categoryId, name: b.dataset.categoryName }; renderProducts($('#product-search').value); renderCategories(); document.querySelector('[data-category=""]').classList.remove('active'); });
}

function renderProducts(filter = '') {
  const wrap = $('#products');
  const q = filter.toLowerCase();
  const filtered = state.products.filter((p) => matchesCategory(p, state.activeCategory)).filter((p) => p.name.toLowerCase().includes(q));
  if (!filtered.length) { wrap.innerHTML = `<div class="empty">${state.activeCategory ? 'Tidak ada produk pada kategori ini.' : 'Tidak ada produk tersedia.'}</div>`; return; }
  wrap.innerHTML = '';
  filtered.forEach((p) => {
    const div = document.createElement('div');
    const image = p.local_image_path || productImageOnlineUrl(p) || toAbsoluteImageUrl(p.image_path || '');
    div.className = 'product-card';
    div.innerHTML = `<img src="${image}" alt="${p.name}"/><strong>${p.name}</strong><div>${rupiah(p.price)}</div>`;
    const img = div.querySelector('img');
    img.onerror = () => { img.removeAttribute('src'); img.classList.add('is-placeholder'); };
    img.onload = () => cacheProductImageInBackground(p, img);

    // Do not wait for <img> load. If the image endpoint needs Authorization and
    // the browser image request cannot send it, the main process will still cache
    // it using the saved API token and then swap the image source to file://.
    cacheProductImageInBackground(p, img);

    div.onclick = () => addToCart(p);
    wrap.appendChild(div);
  });
}

function addToCart(product) { const found = state.cart.find((x) => x.product_id === product.id); if (found) found.qty += 1; else state.cart.push({ product_id: product.id, name: product.name, qty: 1, price_each: Number(product.price) }); renderCart(); }
function renderCart() { $('#cart-items').innerHTML = state.cart.map((i) => `<div class="cart-row"><span>${i.name} x${i.qty}</span><span>${rupiah(i.qty * i.price_each)}</span></div>`).join(''); $('#cart-total').textContent = `Total: ${rupiah(state.cart.reduce((a, b) => a + b.qty * b.price_each, 0))}`; }

function renderPaymentOptions() {
  $('#guide').innerHTML = `<option value="">Pilih Guide</option>${state.guides.map((g) => `<option value="${g.id}">${g.name}</option>`).join('')}`;
  $('#payment-method').innerHTML = state.paymentMethods.map((m) => `<option value="${m.code}">${m.name}</option>`).join('');
  $('#payment-bank').innerHTML = `<option value="">Pilih Bank</option>${state.banks.map((b) => `<option value="${b.id}">${b.name}</option>`).join('')}`;
  $('#history-guide-filter').innerHTML = `<option value="">Semua guide</option>${state.guides.map((g) => `<option value="${g.name}">${g.name}</option>`).join('')}`;
  $('#history-payment-filter').innerHTML = `<option value="">Semua pembayaran</option>${state.paymentMethods.map((m) => `<option value="${m.code}">${m.name}</option>`).join('')}`;
  updateBankState();
}
function updateBankState() { const code = $('#payment-method').value; $('#payment-bank').disabled = !bankRequiredCodes.has(code); if (!bankRequiredCodes.has(code)) $('#payment-bank').value = ''; }

async function loadPosState() {
  const pos = await window.desktopAPI.getPosState();
  state.products = pos.products || []; state.categories = pos.categories || []; state.guides = pos.guides || []; state.paymentMethods = pos.paymentMethods || []; state.banks = pos.banks || []; state.theme = pos.syncedSettings || {};
  applyTheme(state.theme);
  $('#sync-count').textContent = `Pending: ${pos.pendingSyncCount || 0} | Shift: ${pos.pendingShiftSync || 0}`;
  const shiftActive = !!pos.activeShift;
  $('#shift-status').textContent = shiftActive ? `Shift aktif: ${pos.activeShift.shift_code || pos.activeShift.id}` : 'Shift: tidak aktif';
  $('#btn-shift-toggle').textContent = shiftActive ? 'Tutup Shift' : 'Buka Shift';
  renderCategories(); renderProducts(); renderPaymentOptions();
  return pos;
}

async function loadHistory() {
  const data = await window.desktopAPI.getHistory({ from: $('#history-from').value.trim(), to: $('#history-to').value.trim(), guideName: $('#history-guide-filter').value, paymentMethod: $('#history-payment-filter').value, syncStatus: $('#history-sync-filter').value });
  if (!data?.ok) return;
  $('#history-omzet').textContent = `Ringkasan Omzet: ${rupiah(data.omzet)}`;
  $('#history-list').innerHTML = data.rows.map((r) => `<div class='row'><strong>${r.transaction_code}</strong> | ${r.sold_at} | ${r.guide_name || '-'} | ${r.payment_method} ${r.payment_bank || ''} | ${r.sync_status} | ${rupiah(r.total)} <button data-id='${r.transaction_group_id}'>Detail</button></div>`).join('');
  $('#history-list').querySelectorAll('button').forEach((b) => b.onclick = async () => { const d = await window.desktopAPI.getHistoryDetail(b.dataset.id); alert(d.items.map((i) => `${i.product_name} x${i.qty} ${rupiah(i.total)}`).join('\n')); });
}

async function loadOrders() { const data = await window.desktopAPI.getOrders(); const grouped = new Map(); (data.items || []).forEach((i) => { if (!grouped.has(i.order_id)) grouped.set(i.order_id, []); grouped.get(i.order_id).push(i); }); $('#orders-list').innerHTML = (data.orders || []).map((o) => `<div class='row'><strong>${o.order_code}</strong> | ${o.created_at} | ${o.customer_name || '-'} | ${o.customer_contact || '-'} | ${o.status}<br/>${(grouped.get(o.id) || []).map((x) => `${x.product_name} x${x.qty}`).join(', ')}</div>`).join('') || 'Belum ada order masuk.'; }

function syncModuleList(statusMap = {}) { $('#sync-module-status').innerHTML = SYNC_MODULES.map((m) => `<li>${m}: <strong>${statusMap[m] || 'menunggu'}</strong></li>`).join(''); }
function setSyncProgress(percent, text) { $('#sync-progress').value = percent; $('#sync-status-text').textContent = text; }
function setSyncDebugVisibility() {
  const panel = $('#sync-debug-panel');
  if (!panel) return;
  panel.classList.toggle('hidden', !state.debugMode);
}
function switchSettingsTab(name) {
  document.querySelectorAll('.settings-tab').forEach((btn) => btn.classList.toggle('active', btn.dataset.settingsTab === name));
  document.querySelectorAll('.settings-panel').forEach((panel) => panel.classList.toggle('active', panel.dataset.settingsPanel === name));
}
async function initSettingsDialog(activeTab = 'api') {
  await initApiDialog();
  await initPrinterDialog();
  const settings = await window.desktopAPI.getSettings();
  state.debugMode = !!settings.debugMode;
  $('#debug-mode').checked = state.debugMode;
  setSyncDebugVisibility();
  switchSettingsTab(activeTab);
}


function buildSyncDebug(error, resp, moduleName = 'unknown') {
  const compactBody = (() => {
    const payload = resp?.response || resp || null;
    if (!payload) return null;
    const text = typeof payload === 'string' ? payload : JSON.stringify(payload);
    return text.length > 500 ? `${text.slice(0, 500)}...` : text;
  })();
  const maskedToken = String(resp?.settings?.apiTokenMasked || state.apiTokenMasked || '');
  return JSON.stringify({
    timestamp: new Date().toISOString(),
    failed_module: moduleName,
    endpoint: resp?.endpoint || error?.endpoint || null,
    http_status: resp?.status || error?.status || null,
    response_body: compactBody,
    error_message: error?.message || resp?.message || null,
    stack_short: (error?.stack || '').split('\n').slice(0, 4).join('\n') || null,
    token: maskedToken || '(kosong)',
    settings: { apiBaseUrl: $('#api-base-url').value.trim() }
  }, null, 2);
}

async function runSyncFlow({ allowOffline = false } = {}) {
  let attempt = 0;
  let lastError = null;
  while (attempt < 3) {
    attempt += 1;
    const moduleStatus = {};
    state.syncSuccess = false;
    $('#btn-sync-enter-pos').disabled = true;
    $('#sync-retry-count').textContent = `Percobaan ${attempt}/3`;
    try {
    const cfg = await window.apiConfig.get();
    state.apiTokenMasked = maskToken(cfg.apiToken);
    if (!cfg.apiToken) {
      throw new Error('Token API belum disetting. Buka Setting API dan simpan token terlebih dahulu.');
    }

    showView('sync-view');
    setSyncDebugVisibility();
    syncModuleList(moduleStatus);
    setSyncProgress(5, 'Cek koneksi API...');
    const conn = await window.desktopAPI.testConnection();
    if (!conn?.ok) throw Object.assign(new Error(conn?.message || 'Koneksi API gagal'), { module: 'Koneksi API', resp: conn });
    if (conn?.token?.device_code) {
      await window.desktopAPI.setSettings({ deviceCode: String(conn.token.device_code).trim().toUpperCase() });
    }
    moduleStatus['Koneksi API'] = 'ok';
    syncModuleList(moduleStatus);

    setSyncProgress(35, 'Sinkronisasi master data...');
    const syncResp = await window.desktopAPI.syncMaster({ incremental: false });
    if (!syncResp?.ok) throw Object.assign(new Error(syncResp?.message || 'Sync gagal'), { module: 'Produk', resp: syncResp });
    moduleStatus['Produk'] = `ok (${syncResp.counts?.products || 0})`;
    moduleStatus['Kategori'] = `ok (${syncResp.counts?.categories || 0})`;
    moduleStatus['Guide'] = `ok (${syncResp.counts?.guides || 0})`;
    moduleStatus['Bank/payment'] = `ok (${syncResp.counts?.banks || 0}/${syncResp.counts?.payment_methods || 0})`;
    moduleStatus['Setting/theme/logo'] = 'ok';
    moduleStatus['Thumbnail produk'] = `ok (${syncResp.counts?.thumbnails_downloaded || 0}, gagal ${syncResp.counts?.thumbnails_failed || 0})`;
    moduleStatus['Shift'] = `ok (${syncResp.counts?.shifts || 0})`;
    moduleStatus['Riwayat transaksi'] = `ok (${syncResp.counts?.sales_history || 0})`;
    moduleStatus['Order landing page'] = `ok (${syncResp.counts?.pending_orders || 0})`;
    syncModuleList(moduleStatus);

    setSyncProgress(65, 'Sync pending transaksi lokal...');
    const pendingResp = await window.desktopAPI.syncPending();
    moduleStatus['Pending transaksi lokal'] = pendingResp?.ok ? 'ok' : `gagal: ${pendingResp?.message || '-'}`;

    setSyncProgress(80, 'Retry pending shift lokal...');
    const shiftResp = await window.desktopAPI.retryPendingShift();
    moduleStatus['Pending shift lokal'] = shiftResp?.ok ? `ok (${shiftResp.synced || 0})` : 'gagal';
    syncModuleList(moduleStatus);

    await loadPosState(); await loadHistory(); await loadOrders();
    setSyncProgress(100, 'Sinkronisasi selesai.');
    $('#sync-debug').value = '';
    state.syncSuccess = true;
    $('#btn-sync-enter-pos').disabled = false;
    showToast('Sinkronisasi berhasil', 'success');
    if (!state.debugMode) {
      showView('pos-view');
    }
      return;
    } catch (error) {
      lastError = error;
      setSyncProgress(100, `Sinkronisasi gagal: ${error.message}`);
      $('#sync-debug').value = buildSyncDebug(error, error.resp || null, error.module || 'Unknown');
      $('#sync-debug-panel').classList.remove('hidden');
      state.syncSuccess = false;
      showToast(error.message || 'Sinkronisasi gagal');
    }
  }
  if (lastError) { $('#sync-debug').value = buildSyncDebug(lastError, lastError.resp || null, lastError.module || 'Unknown'); $('#sync-debug-panel').classList.remove('hidden'); }
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
    if (!localSave?.ok) return alert(localSave?.message || 'Gagal simpan transaksi lokal');
    state.latestReceipt = { transactionCode: localSave.transactionCode, soldAt: localSave.soldAt, paymentMethod, paymentBank: bank?.name || '', guideName: guide?.name || '', items: [...state.cart], total: state.cart.reduce((a, b) => a + b.qty * b.price_each, 0) };
    try { if (navigator.onLine) await window.desktopAPI.syncPending(); } catch (_) {}
    switchTab('receipt'); renderReceipt(); await loadPosState();
  } finally { state.paying = false; $('#btn-pay').disabled = false; }
}

function switchTab(name) { document.querySelectorAll('.tab').forEach((t) => t.classList.toggle('active', t.dataset.tab === name)); document.querySelectorAll('.tab-panel').forEach((t) => t.classList.toggle('active', t.dataset.panel === name)); }
function renderReceipt() { const w = $('#receipt-wrap'); if (!state.latestReceipt) { w.innerHTML = '<p>Belum ada transaksi.</p>'; return; } w.innerHTML = `<h3>Receipt ${state.latestReceipt.transactionCode}</h3><div>Waktu lokal: ${state.latestReceipt.soldAt}</div><div>Kasir: ${state.user?.name || '-'}</div><div>Guide: ${state.latestReceipt.guideName || '-'}</div><div>Metode: ${state.latestReceipt.paymentMethod}</div><div>Bank: ${state.latestReceipt.paymentBank || '-'}</div><hr/>${state.latestReceipt.items.map((i) => `<div class='cart-row'><span>${i.name} x${i.qty}</span><span>${rupiah(i.qty * i.price_each)}</span></div>`).join('')}<div class='cart-total'>Total: ${rupiah(state.latestReceipt.total)}</div><button id='btn-print'>Print</button><button id='btn-new-transaction'>Transaksi Baru</button>`; $('#btn-print').onclick = async () => { const settings = await window.desktopAPI.getSettings(); await window.desktopAPI.printReceipt({ html: w.innerHTML, printerName: settings.printerName, silent: true }); }; $('#btn-new-transaction').onclick = () => { state.cart = []; state.latestReceipt = null; renderCart(); switchTab('pos'); }; }

async function initApiDialog() { const s = await window.desktopAPI.getSettings(); $('#api-base-url').value = s.apiBaseUrl || ''; $('#api-token').value = ''; $('#api-token-preview').textContent = s.apiTokenMasked || '(kosong)'; }

async function saveApiSettingsAndTest() {
  const apiBaseUrl = document.getElementById('api-base-url').value.trim();
  const apiToken = document.getElementById('api-token').value.trim();

  const result = await window.apiConfig.set({ apiBaseUrl, apiToken });

  if (!result.ok) {
    alert(result.message);
    $('#api-status').textContent = `Gagal: ${result.message}`;
    return result;
  }

  alert(`Setting API tersimpan. Token: ${result.tokenPreview}`);

  const verify = await window.apiConfig.get();
  console.log('[config:verify]', {
    apiBaseUrl: verify.apiBaseUrl,
    token: verify.apiToken ? verify.apiToken.slice(0,4) + '***' + verify.apiToken.slice(-2) : '(kosong)'
  });

  $('#api-token').value = '';
  await initApiDialog();
  $('#api-status').textContent = `Setting API tersimpan (${result.tokenPreview})`;
  return { ok: true, message: 'Setting API tersimpan', settings: verify };
}

async function testApiConnection() {
  const settings = await window.desktopAPI.getSettings();
  if (!settings?.hasApiToken) {
    $('#api-status').textContent = 'Token API belum disetting';
    return { ok: false, message: 'Token API belum disetting' };
  }
  const testResp = await window.desktopAPI.testConnection();
  $('#api-status').textContent = testResp?.ok ? `Koneksi OK (${settings.apiTokenMasked})` : `Gagal: ${testResp?.message || 'Test koneksi gagal'}`;
  return testResp;
}

async function initPrinterDialog() { const s = await window.desktopAPI.getSettings(); $('#receipt-width').value = s.receiptWidthMm || 58; $('#receipt-margin').value = s.receiptMarginMm || 2; $('#current-device-code').textContent = s.deviceCode || '-'; const printers = await window.desktopAPI.getPrinters(); $('#printer-name').innerHTML = `<option value=''>Default Sistem</option>${printers.map((p) => `<option value="${p.name}">${p.displayName}${p.isDefault ? ' (default)' : ''}</option>`).join('')}`; $('#printer-name').value = s.printerName || ''; }

async function bootstrap() {
  await initSettingsDialog('api'); showView('login-view');
  syncModuleList();
  setSyncDebugVisibility();
  $('#btn-open-settings').onclick = async () => { await initSettingsDialog('api'); $('#settings-dialog').showModal(); };
  $('#btn-close-settings').onclick = () => $('#settings-dialog').close();
  document.querySelectorAll('.settings-tab').forEach((btn) => btn.onclick = () => switchSettingsTab(btn.dataset.settingsTab));
  $('#btn-save-api').onclick = async () => { const resp = await saveApiSettingsAndTest(); if (resp?.ok) showToast('Setting API tersimpan', 'success'); };
  $('#btn-test-api').onclick = async () => { const resp = await testApiConnection(); if (resp?.ok) showToast('Test koneksi OK', 'success'); };
  $('#btn-save-printer').onclick = async () => {
    await window.desktopAPI.setSettings({ printerName: $('#printer-name').value, receiptWidthMm: Number($('#receipt-width').value || 58), receiptMarginMm: Number($('#receipt-margin').value || 2) });
    showToast('Setting printer/program tersimpan', 'success');
  };
  $('#btn-save-debug').onclick = async () => {
    state.debugMode = !!$('#debug-mode').checked;
    await window.desktopAPI.setSettings({ debugMode: state.debugMode });
    setSyncDebugVisibility();
    $('#debug-status').textContent = state.debugMode ? 'Debug Mode aktif' : 'Debug Mode nonaktif';
    showToast('Setting debug tersimpan', 'success');
  };
  $('#btn-reset-app-data').onclick = async () => {
    const warning = 'Semua data lokal, token API, printer, produk, transaksi lokal, dan cache akan dihapus. Data server tidak terpengaruh.';
    if (!confirm(warning)) return;
    await window.desktopAPI.resetAllAppData();
  };

  $('#login-form').onsubmit = async (e) => {
    e.preventDefault();
    const currentSettings = await window.desktopAPI.getSettings();
    if (!currentSettings?.apiBaseUrl || !currentSettings?.hasApiToken) return alert('Token API belum disetting');
    const fd = new FormData(e.target);
    const resp = await window.desktopAPI.login({ username: fd.get('username'), password: fd.get('password') });
    if (!resp?.ok) return alert(resp.message || 'Login gagal');
    state.user = resp.user;
    $('#user-label').textContent = `${state.user.name} (${state.user.role})`;
    await runSyncFlow({ allowOffline: true });
  };

  $('#btn-sync-retry').onclick = async () => runSyncFlow({ allowOffline: true });
  $('#btn-sync-copy-debug').onclick = async () => { await navigator.clipboard.writeText($('#sync-debug').value || ''); showToast('Debug dicopy', 'success'); };
  $('#btn-sync-enter-pos').onclick = async () => { if (!state.syncSuccess) return; showView('pos-view'); };

  $('#btn-logout').onclick = async () => {
    const result = await window.desktopAPI.logoutWithPrompt();
    if (!result?.ok) return;
    state.user = null; state.cart = []; state.latestReceipt = null; state.syncSuccess = false;
    $('#login-form').reset(); showView('login-view');
  };

  document.querySelectorAll('.tab').forEach((t) => t.onclick = async () => { switchTab(t.dataset.tab); if (t.dataset.tab === 'history') await loadHistory(); if (t.dataset.tab === 'orders') await loadOrders(); });
  $('#btn-shift-toggle').onclick = async () => { const status = await window.desktopAPI.shiftStatus(); const hasShift = !!(status?.shift || status?.has_active_shift); const actionResp = hasShift ? await window.desktopAPI.closeShift({ user_id: state.user?.id, counted_cash_total: 0, notes: 'Closed from POS Desktop' }) : await window.desktopAPI.openShift({ user_id: state.user?.id, opening_cash_actual: 0 }); if (!actionResp?.ok && actionResp?.sync_status !== 'pending') return showToast(actionResp?.message || 'Gagal update shift'); await window.desktopAPI.syncMaster({ incremental: false }); await loadPosState(); };
  $('#payment-method').onchange = updateBankState;
  $('#btn-pay').onclick = payNow;
  $('#product-search').oninput = (e) => renderProducts(e.target.value);
  document.querySelector('[data-category=""]').onclick = () => { state.activeCategory = null; renderProducts($('#product-search').value); renderCategories(); document.querySelector('[data-category=""]').classList.add('active'); };
  $('#btn-manual-sync').onclick = async () => {
    await window.desktopAPI.syncPending();
    await window.desktopAPI.retryPendingShift();
    await window.desktopAPI.syncMaster({ incremental: false });
    await loadPosState();
  };
  $('#btn-load-history').onclick = loadHistory;
}

bootstrap().catch((error) => showToast(error.message || 'Inisialisasi gagal'));
