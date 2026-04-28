const state = { user: null, products: [], categories: [], guides: [], paymentMethods: [], banks: [], cart: [], latestReceipt: null, paying: false, activeCategory: null, theme: {}, syncRetry: 0, syncSuccess: false, apiTokenMasked: '(kosong)', debugMode: false, historyRange: 'today', recapRange: 'today' };
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

  const brandName = String(settings.store_name || 'Adena POS').trim() || 'Adena POS';
  const brandAddress = String(settings.store_address || settings.store_subtitle || 'Desktop cashier system').trim();
  const brandNameEl = $('#brand-name');
  const brandAddressEl = $('#brand-address');
  if (brandNameEl) brandNameEl.textContent = brandName;
  if (brandAddressEl) brandAddressEl.textContent = brandAddress || 'Desktop cashier system';

  const logo = settings.store_logo_local_uri || settings.store_logo_url || settings.store_logo || '';
  const img = $('#brand-logo');
  const fallback = $('#brand-logo-fallback');
  const showFallback = () => {
    if (img) {
      img.removeAttribute('src');
      img.classList.add('hidden');
    }
    if (fallback) fallback.classList.remove('hidden');
  };
  if (img && logo) {
    img.onload = () => {
      img.classList.remove('hidden');
      if (fallback) fallback.classList.add('hidden');
    };
    img.onerror = showFallback;
    img.src = /^file:|^https?:|^data:/i.test(String(logo)) ? logo : toAbsoluteImageUrl(logo);
  } else {
    showFallback();
  }
}


function cartTotal() { return state.cart.reduce((a, b) => a + Number(b.qty || 0) * Number(b.price_each || 0), 0); }
function isCashPayment(code) { const v = String(code || '').toLowerCase(); return v === 'cash' || v === 'tunai' || v.includes('cash') || v.includes('tunai'); }
function updateCashPaymentState() {
  const wrap = $('#cash-payment-wrap');
  const input = $('#cash-received');
  const change = $('#cash-change');
  if (!wrap || !input || !change) return;
  const cash = isCashPayment($('#payment-method')?.value);
  wrap.classList.toggle('hidden', !cash);
  input.required = cash;
  if (!cash) { input.value = ''; change.textContent = 'Kembalian: Rp 0'; change.classList.remove('negative'); return; }
  const received = Number(input.value || 0);
  const diff = received - cartTotal();
  change.textContent = diff >= 0 ? `Kembalian: ${rupiah(diff)}` : `Kurang: ${rupiah(Math.abs(diff))}`;
  change.classList.toggle('negative', diff < 0);
}
function pad2(n) { return String(n).padStart(2, '0'); }
function dateStart(d) { return `${d.getFullYear()}-${pad2(d.getMonth()+1)}-${pad2(d.getDate())} 00:00:00`; }
function dateEnd(d) { return `${d.getFullYear()}-${pad2(d.getMonth()+1)}-${pad2(d.getDate())} 23:59:59`; }
function setHistoryRange(range) {
  state.historyRange = range;
  document.querySelectorAll('.history-range').forEach((b) => b.classList.toggle('active', b.dataset.range === range));
  const from = $('#history-from'); const to = $('#history-to');
  const now = new Date();
  let a = new Date(now); let b = new Date(now);
  if (range === 'custom') { from.disabled = false; to.disabled = false; return; }
  if (range === 'yesterday') { a.setDate(now.getDate()-1); b.setDate(now.getDate()-1); }
  if (range === '7days') { a.setDate(now.getDate()-6); }
  if (range === 'month') { a = new Date(now.getFullYear(), now.getMonth(), 1); }
  from.value = dateStart(a); to.value = dateEnd(b);
  from.disabled = true; to.disabled = true;
}

function setQuickRange(range, fromSelector, toSelector, buttonSelector, stateKey) {
  state[stateKey] = range;
  document.querySelectorAll(buttonSelector).forEach((b) => b.classList.toggle('active', b.dataset.range === range));
  const from = $(fromSelector); const to = $(toSelector);
  const now = new Date();
  let a = new Date(now); let b = new Date(now);
  if (range === 'custom') { from.disabled = false; to.disabled = false; return; }
  if (range === 'yesterday') { a.setDate(now.getDate()-1); b.setDate(now.getDate()-1); }
  if (range === '7days') { a.setDate(now.getDate()-6); }
  if (range === 'month') { a = new Date(now.getFullYear(), now.getMonth(), 1); }
  from.value = dateStart(a); to.value = dateEnd(b);
  from.disabled = true; to.disabled = true;
}

function setRecapRange(range) { setQuickRange(range, '#recap-from', '#recap-to', '.recap-range', 'recapRange'); }

function escapeHtml(value) {
  return String(value ?? '').replace(/[&<>"']/g, (c) => ({'&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;',"'":'&#039;'}[c]));
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
  const allBtn = document.querySelector('[data-category=""]');
  if (allBtn) allBtn.classList.toggle('active', !state.activeCategory);
  const cats = (state.categories || []).filter((c) => c && c.name);
  wrap.innerHTML = cats.map((c) => `<button class="category ${(state.activeCategory && String(c.id) === String(state.activeCategory.id)) ? 'active' : ''}" data-category-id="${c.id ?? ''}" data-category-name="${c.name}"><span class="category-icon">▣</span><span>${c.name}</span></button>`).join('') || '<div class="empty small">Kategori belum tersinkron.</div>';
  wrap.querySelectorAll('button').forEach((b) => b.onclick = () => {
    state.activeCategory = { id: b.dataset.categoryId, name: b.dataset.categoryName };
    renderProducts($('#product-search').value);
    renderCategories();
  });
}

function noPhotoHtml() { return `<div class="no-photo">NO PHOTO</div>`; }

function renderUserProfile() {
  const holder = $("#desktop-user-photo");
  if (!holder) return;
  const user = state.user || {};
  const avatar = user.avatar_url || toAbsoluteImageUrl(user.avatar_path || "");
  holder.innerHTML = "";
  holder.classList.remove("has-photo");
  if (avatar) {
    const img = document.createElement("img");
    img.src = avatar;
    img.alt = user.name || "User";
    img.onerror = () => {
      holder.classList.remove("has-photo");
      holder.innerHTML = "No<br>Photo";
    };
    holder.classList.add("has-photo");
    holder.appendChild(img);
  } else {
    holder.innerHTML = "No<br>Photo";
  }
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
    div.innerHTML = `${image ? `<img src="${image}" alt="${p.name}"/>` : noPhotoHtml()}<strong>${p.name}</strong><div>${rupiah(p.price)}</div>`;
    const img = div.querySelector('img');
    if (img) {
      img.onerror = () => {
        const placeholder = document.createElement('div');
        placeholder.className = 'no-photo';
        placeholder.textContent = 'NO PHOTO';
        img.replaceWith(placeholder);
      };
      img.onload = () => cacheProductImageInBackground(p, img);
      cacheProductImageInBackground(p, img);
    }
    div.onclick = () => addToCart(p);
    wrap.appendChild(div);
  });
}

function addToCart(product) {
  const found = state.cart.find((x) => String(x.product_id) === String(product.id));
  if (found) found.qty += 1;
  else state.cart.push({ product_id: product.id, name: product.name, qty: 1, price_each: Number(product.price) });
  renderCart();
}

function updateCartQty(productId, qty) {
  const item = state.cart.find((x) => String(x.product_id) === String(productId));
  if (!item) return;
  const n = Math.max(1, Math.floor(Number(qty) || 1));
  item.qty = n;
  renderCart();
}

function removeCartItem(productId) {
  state.cart = state.cart.filter((x) => String(x.product_id) !== String(productId));
  renderCart();
}

function renderCart() {
  const wrap = $('#cart-items');
  if (!state.cart.length) {
    wrap.innerHTML = '<div class="empty small">Keranjang kosong.</div>';
  } else {
    wrap.innerHTML = state.cart.map((i) => `
      <div class="cart-row cart-row-edit">
        <div class="cart-name">${i.name}<small>${rupiah(i.price_each)}</small></div>
        <input class="cart-qty" type="number" min="1" step="1" value="${i.qty}" data-qty-id="${i.product_id}" />
        <strong>${rupiah(i.qty * i.price_each)}</strong>
        <button class="cart-remove" title="Hapus" data-remove-id="${i.product_id}">×</button>
      </div>`).join('');
    wrap.querySelectorAll('[data-qty-id]').forEach((el) => el.onchange = () => updateCartQty(el.dataset.qtyId, el.value));
    wrap.querySelectorAll('[data-remove-id]').forEach((el) => el.onclick = () => removeCartItem(el.dataset.removeId));
  }
  $('#cart-total').textContent = `Total: ${rupiah(cartTotal())}`;
  updateCashPaymentState();
}

function renderPaymentOptions() {
  $('#guide').innerHTML = `<option value="">Pilih Guide</option>${state.guides.map((g) => `<option value="${g.id}">${g.name}</option>`).join('')}`;
  $('#payment-method').innerHTML = state.paymentMethods.map((m) => `<option value="${m.code}">${m.name}</option>`).join('');
  $('#payment-bank').innerHTML = `<option value="">Pilih Bank</option>${state.banks.map((b) => `<option value="${b.id}">${b.name}</option>`).join('')}`;
  $('#history-guide-filter').innerHTML = `<option value="">Semua guide</option>${state.guides.map((g) => `<option value="${g.name}">${g.name}</option>`).join('')}`;
  $('#history-payment-filter').innerHTML = `<option value="">Semua pembayaran</option>${state.paymentMethods.map((m) => `<option value="${m.code}">${m.name}</option>`).join('')}`;
  updateBankState();
}
function updateBankState() { const code = $('#payment-method').value; $('#payment-bank').disabled = !bankRequiredCodes.has(code); if (!bankRequiredCodes.has(code)) $('#payment-bank').value = ''; updateCashPaymentState(); }

async function loadPosState() {
  const pos = await window.desktopAPI.getPosState();
  state.products = pos.products || []; state.categories = pos.categories || []; state.guides = pos.guides || []; state.paymentMethods = pos.paymentMethods || []; state.banks = pos.banks || []; state.theme = pos.syncedSettings || {};
  applyTheme(state.theme);
  $('#sync-count').textContent = `Pending: ${pos.pendingSyncCount || 0} | Shift: ${pos.pendingShiftSync || 0}`;
  state.activeShift = pos.activeShift || null;
  state.shiftSummary = pos.shiftSummary || null;
  const shiftActive = !!state.activeShift;
  $('#shift-status').textContent = shiftActive ? `Shift aktif: ${state.activeShift.shift_code || state.activeShift.id}` : 'Shift: belum aktif';
  $('#btn-shift-toggle').textContent = shiftActive ? 'Tutup Shift' : 'Buka Shift';
  $('#btn-pay').disabled = !shiftActive;
  renderCategories(); renderProducts(); renderPaymentOptions(); renderShiftModals();
  return pos;
}

function renderShiftModals() {
  const defaultOpening = Number(state.theme.pos_default_opening_cash || state.theme.default_opening_cash || 0);
  const openInput = $('#shift-opening-cash');
  if (openInput && !openInput.dataset.touched) openInput.value = String(defaultOpening);
  $('#shift-default-opening').textContent = rupiah(defaultOpening);

  const s = state.shiftSummary || {};
  $('#close-opening-cash').textContent = rupiah(s.opening_cash || 0);
  $('#close-cash-sales').textContent = rupiah(s.cash_sales || 0);
  $('#close-cash-refund').textContent = rupiah(s.cash_refund || 0);
  $('#close-cash-in').textContent = rupiah(s.cash_in || 0);
  $('#close-cash-out').textContent = rupiah(s.cash_out || 0);
  $('#close-non-cash-sales').textContent = rupiah(s.non_cash_sales || 0);
  $('#close-expected-cash').textContent = rupiah(s.expected_cash || 0);
}

function showShiftModal(kind) {
  renderShiftModals();
  const id = kind === 'close' ? '#close-shift-modal' : '#open-shift-modal';
  const modal = $(id);
  if (modal) modal.hidden = false;
}

function hideShiftModals() {
  document.querySelectorAll('.pos-modal').forEach((m) => { m.hidden = true; });
}

async function submitOpenShift() {
  const opening = Number($('#shift-opening-cash').value || 0);
  const actionResp = await window.desktopAPI.openShift({ user_id: state.user?.id, opening_cash_actual: opening });
  if (!actionResp?.ok && actionResp?.sync_status !== 'pending') return showToast(actionResp?.message || 'Gagal buka shift');
  hideShiftModals();
  await window.desktopAPI.syncMaster({ incremental: false });
  await loadPosState();
  showToast(actionResp?.sync_status === 'pending' ? 'Buka shift tersimpan lokal dan menunggu sync' : 'Shift dibuka', 'success');
}

async function submitCloseShift() {
  const counted = Number($('#shift-counted-cash').value || 0);
  const notes = $('#shift-close-notes').value || '';
  const closeReport = await window.desktopAPI.getShiftCloseReport({ counted_cash_total: counted, user: state.user });
  const pending = await window.desktopAPI.syncPending();
  await window.desktopAPI.retryPendingShift();
  const actionResp = await window.desktopAPI.closeShift({ user_id: state.user?.id, counted_cash_total: counted, notes, sync_status: pending?.ok ? 'synced' : 'partial' });
  if (!actionResp?.ok && actionResp?.sync_status !== 'pending') return showToast(actionResp?.message || 'Gagal tutup shift');
  hideShiftModals();
  if (closeReport?.ok && closeReport.html) {
    try {
      const settings = await window.desktopAPI.getSettings();
      await window.desktopAPI.printReceipt({ html: closeReport.html, printerName: settings.printerName, silent: true });
    } catch (error) {
      showToast(`Shift ditutup, tetapi print gagal: ${error.message || error}`);
    }
  }
  try { await window.desktopAPI.syncMaster({ incremental: false }); } catch (_) {}
  await loadPosState();
  showToast(actionResp?.sync_status === 'pending' ? 'Closing shift tersimpan lokal dan menunggu sync' : 'Shift ditutup', 'success');
}

async function loadHistory() {
  if (!$('#history-from').value && !$('#history-to').value) setHistoryRange(state.historyRange || 'today');
  const data = await window.desktopAPI.getHistory({ from: $('#history-from').value.trim(), to: $('#history-to').value.trim(), guideName: $('#history-guide-filter').value, paymentMethod: $('#history-payment-filter').value, syncStatus: $('#history-sync-filter').value });
  if (!data?.ok) return;
  $('#history-omzet').textContent = 'Ringkasan Omzet: ' + rupiah(data.omzet);
  $('#history-list').innerHTML = data.rows.map((r) => {
    const meta = [r.sold_at, r.guide_name || '-', [r.payment_method, r.payment_bank].filter(Boolean).join(' '), r.sync_status].filter(Boolean).join(' | ');
    return '<div class="history-item"><div class="history-main"><strong>' + escapeHtml(r.transaction_code) + '</strong><span>' + escapeHtml(meta) + '</span></div><div class="history-side"><strong>' + rupiah(r.total) + '</strong><button class="history-detail-btn" data-id="' + escapeHtml(r.transaction_group_id) + '">Detail</button></div></div>';
  }).join('') || '<div class="empty">Tidak ada transaksi pada filter ini.</div>';
  $('#history-list').querySelectorAll('button[data-id]').forEach((b) => b.onclick = async () => showHistoryDetail(b.dataset.id));
}

async function showHistoryDetail(transactionGroupId) {
  const d = await window.desktopAPI.getHistoryDetail(transactionGroupId);
  const items = d.items || [];
  if (!items.length) return showToast('Detail transaksi tidak ditemukan');
  const h = items[0];
  const total = items.reduce((a, i) => a + Number(i.total || 0), 0);
  const rows = items.map((i) => '<div class="receipt-line"><span>' + escapeHtml(i.product_name || 'Produk') + ' x' + Number(i.qty || 0) + '<small>' + rupiah(i.price_each || 0) + '</small></span><strong>' + rupiah(i.total || 0) + '</strong></div>').join('');
  $('#history-detail-content').innerHTML = '<div class="receipt-meta"><strong>' + escapeHtml(h.transaction_code || '-') + '</strong><div>Waktu: ' + escapeHtml(h.sold_at || '-') + '</div><div>Guide: ' + escapeHtml(h.guide_name || '-') + '</div><div>Pembayaran: ' + escapeHtml([h.payment_method, h.payment_bank].filter(Boolean).join(' - ') || '-') + '</div><div>Status sync: ' + escapeHtml(h.sync_status || '-') + '</div></div><div class="receipt-items">' + rows + '</div><div class="receipt-total">Total: ' + rupiah(total) + '</div>' + (isCashPayment(h.payment_method) ? '<div>Diterima: ' + rupiah(h.cash_received || total) + '</div><div>Kembalian: ' + rupiah(h.cash_change || 0) + '</div>' : '');
  $('#history-detail-modal').hidden = false;
}

async function loadRecap() {
  if (!$('#recap-from').value && !$('#recap-to').value) setRecapRange(state.recapRange || 'today');
  const data = await window.desktopAPI.getHistoryRecap({ from: $('#recap-from').value.trim(), to: $('#recap-to').value.trim() });
  if (!data?.ok) return;
  const rows = data.rows || [];
  $('#recap-summary').innerHTML = '<div class="recap-total"><div><small>Total Transaksi</small><strong>' + Number(data.total?.trx_count || 0) + '</strong></div><div><small>Total Omzet</small><strong>' + rupiah(data.total?.omzet || 0) + '</strong></div></div>' +
    '<div class="recap-table"><div class="recap-row head"><span>Metode</span><span>Bank</span><span>Transaksi</span><span>Omzet</span></div>' +
    (rows.map((r) => '<div class="recap-row"><span>' + escapeHtml(r.payment_method || '-') + '</span><span>' + escapeHtml(r.payment_bank || '-') + '</span><span>' + Number(r.trx_count || 0) + '</span><strong>' + rupiah(r.total || 0) + '</strong></div>').join('') || '<div class="empty">Belum ada transaksi.</div>') + '</div>';
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
  if (!state.activeShift) return alert('Shift belum aktif. Buka shift terlebih dahulu.');
  if (!state.cart.length) return alert('Keranjang kosong');
  const paymentMethod = $('#payment-method').value;
  const bankId = $('#payment-bank').value;
  if (bankRequiredCodes.has(paymentMethod) && !bankId) return alert('Bank wajib dipilih untuk non tunai');
  const total = cartTotal();
  let cashReceived = null; let cashChange = null;
  if (isCashPayment(paymentMethod)) {
    cashReceived = Number($('#cash-received')?.value || 0);
    if (cashReceived < total) return alert('Uang diterima kurang dari total belanja.');
    cashChange = cashReceived - total;
  }
  state.paying = true; $('#btn-pay').disabled = true;
  try {
    const guide = state.guides.find((g) => String(g.id) === $('#guide').value) || null;
    const bank = state.banks.find((b) => String(b.id) === bankId) || null;
    const localSave = await window.desktopAPI.saveSaleLocal({ user: state.user, guide, payment: { method: paymentMethod, bank_id: bank?.id || null, bank_name: bank?.name || null, cash_received: cashReceived, cash_change: cashChange }, shift: state.activeShift, items: state.cart });
    if (!localSave?.ok) return alert(localSave?.message || 'Gagal simpan transaksi lokal');
    state.latestReceipt = { transactionCode: localSave.transactionCode, soldAt: localSave.soldAt, paymentMethod, paymentBank: bank?.name || '', guideName: guide?.name || '', items: [...state.cart], total, cashReceived, cashChange };
    try { if (navigator.onLine) await window.desktopAPI.syncPending(); } catch (_) {}
    switchTab('receipt'); renderReceipt(); await loadPosState();
  } finally { state.paying = false; $('#btn-pay').disabled = false; }
}

function switchTab(name) { document.querySelectorAll('.tab').forEach((t) => t.classList.toggle('active', t.dataset.tab === name)); document.querySelectorAll('.tab-panel').forEach((t) => t.classList.toggle('active', t.dataset.panel === name)); }
function renderReceipt() { const w = $('#receipt-wrap'); if (!state.latestReceipt) { w.innerHTML = '<p>Belum ada transaksi.</p>'; return; } w.innerHTML = `<h3>Receipt ${state.latestReceipt.transactionCode}</h3><div>Waktu lokal: ${state.latestReceipt.soldAt}</div><div>Kasir: ${state.user?.name || '-'}</div><div>Guide: ${state.latestReceipt.guideName || '-'}</div><div>Metode: ${state.latestReceipt.paymentMethod}</div><div>Bank: ${state.latestReceipt.paymentBank || '-'}</div><hr/>${state.latestReceipt.items.map((i) => `<div class='cart-row'><span>${i.name} x${i.qty}</span><span>${rupiah(i.qty * i.price_each)}</span></div>`).join('')}<div class='cart-total'>Total: ${rupiah(state.latestReceipt.total)}</div>${isCashPayment(state.latestReceipt.paymentMethod) ? `<div>Diterima: ${rupiah(state.latestReceipt.cashReceived || 0)}</div><div>Kembalian: ${rupiah(state.latestReceipt.cashChange || 0)}</div>` : ''}<button id='btn-print'>Print</button><button id='btn-new-transaction'>Transaksi Baru</button>`; $('#btn-print').onclick = async () => { const settings = await window.desktopAPI.getSettings(); await window.desktopAPI.printReceipt({ html: w.innerHTML, printerName: settings.printerName, silent: true }); }; $('#btn-new-transaction').onclick = () => { state.cart = []; state.latestReceipt = null; renderCart(); switchTab('pos'); }; }

async function initApiDialog() { const s = await window.desktopAPI.getSettings(); $('#api-base-url').value = s.apiBaseUrl || ''; $('#api-token').value = ''; $('#api-token-preview').textContent = s.apiTokenMasked || '(kosong)'; }

function unlockSettingsInputs() {
  const dlg = document.getElementById('settings-dialog');
  if (!dlg) return;
  dlg.classList.remove('settings-busy', 'is-loading', 'error-lock');
  dlg.removeAttribute('inert');
  dlg.removeAttribute('aria-busy');
  dlg.querySelectorAll('input, select, textarea, button').forEach((el) => {
    el.disabled = false;
    el.readOnly = false;
    el.removeAttribute('disabled');
    el.removeAttribute('readonly');
    el.removeAttribute('aria-disabled');
    el.style.pointerEvents = '';
  });
}

function setSettingsBusy(isBusy, message = '') {
  const dlg = document.getElementById('settings-dialog');
  if (!dlg) return;
  dlg.classList.toggle('settings-busy', !!isBusy);
  dlg.setAttribute('aria-busy', isBusy ? 'true' : 'false');
  const saveBtn = document.getElementById('btn-save-api');
  const testBtn = document.getElementById('btn-test-api');
  if (saveBtn) {
    saveBtn.disabled = !!isBusy;
    saveBtn.textContent = isBusy ? 'Memproses...' : 'Save & Test API';
  }
  if (testBtn) testBtn.disabled = !!isBusy;
  if (message && document.getElementById('api-status')) $('#api-status').textContent = message;
  if (!isBusy) unlockSettingsInputs();
}

async function saveApiSettingsAndTest() {
  const baseInput = document.getElementById('api-base-url');
  const tokenInput = document.getElementById('api-token');
  const apiBaseUrl = String(baseInput?.value || '').trim();
  const apiToken = String(tokenInput?.value || '').trim();

  setSettingsBusy(true, 'Menyimpan dan mengetes API...');
  try {
    const result = await window.apiConfig.set({ apiBaseUrl, apiToken });

    if (!result?.ok) {
      const message = result?.message || 'Setting API gagal disimpan';
      $('#api-status').textContent = `Gagal: ${message}`;
      showToast(message);
      return { ok: false, message };
    }

    const testResp = await window.desktopAPI.testConnection({ baseURL: result.apiBaseUrl, token: apiToken });
    if (!testResp?.ok) {
      const message = testResp?.message || 'Test koneksi gagal';
      $('#api-status').textContent = `Setting tersimpan, tetapi test gagal: ${message}`;
      showToast(message);
      return { ok: false, message, settingsSaved: true };
    }

    const verify = await window.apiConfig.get();
    state.apiTokenMasked = result.tokenPreview || maskToken(verify.apiToken);
    $('#api-token').value = '';
    await initApiDialog();
    $('#api-status').textContent = `Setting API tersimpan (${state.apiTokenMasked})`;
    return { ok: true, message: 'Setting API tersimpan', settings: verify };
  } catch (error) {
    const message = error?.message || 'Setting API gagal diproses';
    $('#api-status').textContent = `Gagal: ${message}`;
    showToast(message);
    return { ok: false, message };
  } finally {
    setSettingsBusy(false);
    unlockSettingsInputs();
    setTimeout(unlockSettingsInputs, 0);
  }
}

async function testApiConnection() {
  setSettingsBusy(true, 'Mengetes koneksi API...');
  try {
    const settings = await window.desktopAPI.getSettings();
    if (!settings?.hasApiToken) {
      $('#api-status').textContent = 'Token API belum disetting';
      return { ok: false, message: 'Token API belum disetting' };
    }
    const testResp = await window.desktopAPI.testConnection();
    $('#api-status').textContent = testResp?.ok ? `Koneksi OK (${settings.apiTokenMasked})` : `Gagal: ${testResp?.message || 'Test koneksi gagal'}`;
    return testResp;
  } catch (error) {
    const message = error?.message || 'Test koneksi gagal';
    $('#api-status').textContent = `Gagal: ${message}`;
    return { ok: false, message };
  } finally {
    setSettingsBusy(false);
    unlockSettingsInputs();
    setTimeout(unlockSettingsInputs, 0);
  }
}

async function initPrinterDialog() { const s = await window.desktopAPI.getSettings(); $('#receipt-width').value = s.receiptWidthMm || 58; $('#receipt-margin').value = s.receiptMarginMm || 2; $('#current-device-code').textContent = s.deviceCode || '-'; const printers = await window.desktopAPI.getPrinters(); $('#printer-name').innerHTML = `<option value=''>Default Sistem</option>${printers.map((p) => `<option value="${p.name}">${p.displayName}${p.isDefault ? ' (default)' : ''}</option>`).join('')}`; $('#printer-name').value = s.printerName || ''; }

function openSettingsModal() {
  const dlg = $("#settings-dialog");
  if (!dlg) return;
  dlg.hidden = false;
  dlg.setAttribute("aria-hidden", "false");
  document.body.classList.add("settings-open");
  unlockSettingsInputs();
  setTimeout(() => { unlockSettingsInputs(); $("#api-base-url")?.focus(); }, 80);
}

function closeSettingsModal() {
  const dlg = $("#settings-dialog");
  if (!dlg) return;
  dlg.hidden = true;
  dlg.setAttribute("aria-hidden", "true");
  document.body.classList.remove("settings-open");
}

async function bootstrap() {
  await initSettingsDialog('api'); showView('login-view');
  syncModuleList();
  setSyncDebugVisibility();
  $("#btn-open-settings").onclick = async () => { await initSettingsDialog("api"); openSettingsModal(); };
  $("#btn-close-settings").onclick = closeSettingsModal;
  $("#settings-dialog").addEventListener("click", (ev) => { if (ev.target === $("#settings-dialog")) closeSettingsModal(); });
  $(".settings-dialog-card")?.addEventListener("click", (ev) => ev.stopPropagation());
  document.addEventListener("keydown", (ev) => { if (ev.key === "Escape" && !$("#settings-dialog")?.hidden) closeSettingsModal(); });
  document.querySelectorAll('.settings-tab').forEach((btn) => btn.onclick = () => { unlockSettingsInputs(); switchSettingsTab(btn.dataset.settingsTab); });
  $('#settings-dialog')?.addEventListener('focusin', unlockSettingsInputs);
  $('#settings-dialog')?.addEventListener('pointerdown', unlockSettingsInputs);
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
    $("#user-label").textContent = `${state.user.name} (${state.user.role})`;
    renderUserProfile();
    await runSyncFlow({ allowOffline: true });
  };

  $('#btn-sync-retry').onclick = async () => runSyncFlow({ allowOffline: true });
  $('#btn-sync-copy-debug').onclick = async () => { await navigator.clipboard.writeText($('#sync-debug').value || ''); showToast('Debug dicopy', 'success'); };
  $('#btn-sync-enter-pos').onclick = async () => { if (!state.syncSuccess) return; showView('pos-view'); };

  $('#btn-logout').onclick = async () => {
    const result = await window.desktopAPI.logoutWithPrompt();
    if (!result?.ok) return;
    state.user = null; state.cart = []; state.latestReceipt = null; state.syncSuccess = false; renderUserProfile();
    $('#login-form').reset(); showView('login-view');
  };

  document.querySelectorAll('.tab').forEach((t) => t.onclick = async () => { switchTab(t.dataset.tab); if (t.dataset.tab === 'history') await loadHistory(); if (t.dataset.tab === 'recap') await loadRecap(); if (t.dataset.tab === 'orders') await loadOrders(); });
  $('#btn-shift-toggle').onclick = async () => { await loadPosState(); showShiftModal(state.activeShift ? 'close' : 'open'); };
  $('#btn-open-shift-submit').onclick = submitOpenShift;
  $('#btn-close-shift-submit').onclick = submitCloseShift;
  document.querySelectorAll('[data-dismiss-modal]').forEach((btn) => btn.onclick = hideShiftModals);
  $('#shift-opening-cash').oninput = (e) => { e.target.dataset.touched = '1'; };
  $('#payment-method').onchange = updateBankState;
  $('#cash-received').oninput = updateCashPaymentState;
  document.querySelectorAll('.history-range').forEach((b) => b.onclick = async () => { setHistoryRange(b.dataset.range); if (b.dataset.range !== 'custom') await loadHistory(); });
  document.querySelectorAll('.recap-range').forEach((b) => b.onclick = async () => { setRecapRange(b.dataset.range); if (b.dataset.range !== 'custom') await loadRecap(); });
  setHistoryRange('today'); setRecapRange('today');
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
  $('#btn-load-recap').onclick = loadRecap;
}

bootstrap().catch((error) => showToast(error.message || 'Inisialisasi gagal'));
