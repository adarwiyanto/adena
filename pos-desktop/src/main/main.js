const path = require('path');
const fs = require('fs');
const { app, BrowserWindow, ipcMain, nativeTheme, dialog, session } = require('electron');
const { initDb, closeDb } = require('./db');
const { store, DEFAULT_SETTINGS, getApiConfig } = require('./config');
const { testConnection, login, shiftAction } = require('./api');
const { performShift, retryPendingShiftSync } = require('./shift');
const { syncMaster, syncPendingTransactions, cacheProductImage } = require('./sync');
const { saveSaleLocally } = require('./transactions');
const { printReceipt } = require('./print');

let mainWindow;
let isQuittingConfirmed = false;

function isApiConfigured() {
  const cfg = getApiConfig();
  return !!(cfg.apiBaseUrl && cfg.apiToken);
}

function maskToken(token) {
  const t = String(token || '').trim();
  if (!t) return '(kosong)';
  if (t.length <= 6) return `${t.slice(0, 2)}***`;
  return `${t.slice(0, 4)}***${t.slice(-2)}`;
}

function getPublicSettings() {
  const cfg = getApiConfig();
  const deviceCode = String(store.get('deviceCode') || '').trim();
  const tokenMasked = maskToken(cfg.apiToken);
  return {
    ...store.store,
    apiBaseUrl: cfg.apiBaseUrl,
    apiToken: '',
    hasApiToken: !!cfg.apiToken,
    apiTokenMasked: tokenMasked,
    deviceCode
  };
}

function activeShiftLocal() {
  const db = initDb();
  return db.prepare("SELECT * FROM pos_shifts WHERE status='open' ORDER BY opened_at DESC, id DESC LIMIT 1").get() || null;
}

function discountValue(base, amount, type) {
  const safeBase = Math.max(0, Number(base || 0));
  const safeAmount = Math.max(0, Number(amount || 0));
  if (!safeBase || !safeAmount) return 0;
  if (String(type || 'fixed') === 'percent') return Math.min(safeBase, Math.round((safeBase * Math.min(safeAmount, 100) / 100) * 100) / 100);
  return Math.min(safeBase, safeAmount);
}

function groupSalesRows(rows = []) {
  const map = new Map();
  for (const row of rows) {
    const key = row.transaction_group_uuid || row.local_transaction_id || row.transaction_code || `sale-${row.id}`;
    if (!map.has(key)) {
      map.set(key, {
        key,
        transaction_code: row.transaction_code,
        sold_at: row.sold_at,
        created_by: row.created_by,
        guide_name: row.guide_name,
        payment_method: row.payment_method,
        payment_bank: row.payment_bank,
        sync_status: row.sync_status,
        cash_received: row.cash_received,
        cash_change: row.cash_change,
        tx_discount_amount: Number(row.tx_discount_amount || 0),
        tx_discount_type: String(row.tx_discount_type || 'fixed') === 'percent' ? 'percent' : 'fixed',
        subtotal: 0,
        final_total: 0,
        qty: 0,
        items: []
      });
    }
    const g = map.get(key);
    const lineTotal = Number(row.total || 0);
    g.subtotal += lineTotal;
    g.qty += Number(row.qty || 0);
    g.items.push(row);
    if (row.cash_received !== null && row.cash_received !== undefined) g.cash_received = row.cash_received;
    if (row.cash_change !== null && row.cash_change !== undefined) g.cash_change = row.cash_change;
  }
  for (const g of map.values()) {
    g.tx_discount_value = discountValue(g.subtotal, g.tx_discount_amount, g.tx_discount_type);
    g.final_total = Math.max(0, g.subtotal - g.tx_discount_value);
  }
  return Array.from(map.values());
}

function getGroupedSales(db, whereClause = '', params = []) {
  const rows = db.prepare(`SELECT * FROM sales ${whereClause} ORDER BY sold_at DESC, id ASC`).all(...params);
  return groupSalesRows(rows);
}

function calculateShiftSummary(shift = null) {
  const db = initDb();
  const active = shift || activeShiftLocal();
  if (!active) {
    return { opening_cash: 0, cash_sales: 0, cash_refund: 0, cash_in: 0, cash_out: 0, non_cash_sales: 0, expected_cash: 0 };
  }
  const openingCash = Number(active.opening_cash_actual ?? active.opening_cash_default ?? 0);
  const salesRows = getGroupedSales(db, 'WHERE shift_id = ?', [active.id]);
  let cashSales = 0;
  let nonCashSales = 0;
  for (const row of salesRows) {
    const method = String(row.payment_method || '').toLowerCase();
    if (method === 'cash' || method === 'tunai') cashSales += Number(row.final_total || 0);
    else nonCashSales += Number(row.final_total || 0);
  }
  const cashIn = db.prepare("SELECT COALESCE(SUM(amount),0) AS total FROM pos_cash_movements WHERE shift_id = ? AND movement_type = 'in'").get(active.id)?.total || 0;
  const cashOut = db.prepare("SELECT COALESCE(SUM(amount),0) AS total FROM pos_cash_movements WHERE shift_id = ? AND movement_type = 'out'").get(active.id)?.total || 0;
  const expectedCash = openingCash + cashSales + Number(cashIn || 0) - Number(cashOut || 0);
  return { opening_cash: openingCash, cash_sales: cashSales, cash_refund: 0, cash_in: Number(cashIn || 0), cash_out: Number(cashOut || 0), non_cash_sales: nonCashSales, expected_cash: expectedCash };
}

function formatNumber(value) {
  return Number(value || 0).toLocaleString('id-ID');
}

function escapeHtml(value) {
  return String(value ?? '').replace(/[&<>'"]/g, (ch) => ({ '&': '&amp;', '<': '&lt;', '>': '&gt;', "'": '&#39;', '"': '&quot;' }[ch]));
}

function fileUriIfExists(filePath) {
  const raw = String(filePath || '').trim();
  if (!raw || !fs.existsSync(raw)) return '';
  return `file://${raw.replace(/\\/g, '/')}`;
}

function getStoreIdentity() {
  const settings = getPublicSettings();
  const cachePath = String(settings.storeCachePath || '').trim();
  let cached = {};
  try {
    if (cachePath && fs.existsSync(cachePath)) cached = JSON.parse(fs.readFileSync(cachePath, 'utf8'));
  } catch (_) {}
  return {
    name: cached.name || settings.storeName || 'Adena',
    address: cached.address || settings.storeAddress || '',
    phone: cached.phone || '',
    logoPath: cached.logo_path || settings.storeLogoPath || ''
  };
}

function getShiftClosePrintData(countedCashTotal = null, user = null) {
  const db = initDb();
  const shift = activeShiftLocal();
  if (!shift) return { ok: false, message: 'Shift belum aktif' };
  const summary = calculateShiftSummary(shift);
  const storeIdentity = getStoreIdentity();
  const cashier = user?.name || db.prepare('SELECT name FROM users WHERE id = ?').get(shift.opened_by)?.name || '-';
  const groupedSales = getGroupedSales(db, 'WHERE shift_id = ?', [shift.id]);
  const transactionCount = groupedSales.length;
  const itemQty = groupedSales.reduce((sum, row) => sum + Number(row.qty || 0), 0);
  const totalSales = groupedSales.reduce((sum, row) => sum + Number(row.final_total || 0), 0);
  const paymentMap = new Map();
  for (const row of groupedSales) {
    const label = row.payment_bank || row.payment_method || '-';
    const key = `${row.payment_method || ''}::${label}`;
    if (!paymentMap.has(key)) paymentMap.set(key, { payment_method: row.payment_method, label, total: 0, tx_count: 0 });
    const rec = paymentMap.get(key);
    rec.total += Number(row.final_total || 0);
    rec.tx_count += 1;
  }
  const paymentRows = Array.from(paymentMap.values()).sort((a, b) => String(a.payment_method || '').localeCompare(String(b.payment_method || '')) || String(a.label || '').localeCompare(String(b.label || '')));
  const counted = countedCashTotal === null || countedCashTotal === undefined ? Number(summary.expected_cash || 0) : Number(countedCashTotal || 0);
  const nonCashTotal = paymentRows.reduce((sum, row) => {
    const method = String(row.payment_method || '').toLowerCase();
    return (method === 'cash' || method === 'tunai') ? sum : sum + Number(row.total || 0);
  }, 0);
  return {
    ok: true,
    store: { ...storeIdentity, logoUri: fileUriIfExists(storeIdentity.logoPath) },
    shift,
    cashier,
    printedAt: localDateTimeString(),
    transactionCount,
    itemQty,
    totalSales: Number(totalSales || 0),
    summary,
    countedCash: counted,
    cashDifference: counted - Number(summary.expected_cash || 0),
    paymentRows,
    totalExpected: Number(summary.expected_cash || 0) + nonCashTotal,
    totalActual: counted + nonCashTotal,
    totalDifference: counted - Number(summary.expected_cash || 0)
  };
}

function buildShiftClosePrintHtml(data) {
  const logo = data.store.logoUri
    ? `<img class="receipt-logo" src="${escapeHtml(data.store.logoUri)}" alt="${escapeHtml(data.store.name)}"/>`
    : `<div class="receipt-logo-text">ADENA</div>`;
  const address = data.store.address ? `<div class="receipt-address">${escapeHtml(data.store.address)}</div>` : '';
  const paymentSections = (data.paymentRows || []).map((row) => {
    const label = String(row.label || row.payment_method || '-').toUpperCase();
    return `<div class="section"><div class="row"><span>${escapeHtml(label)}</span><span>${formatNumber(row.total)}</span></div><div class="row sub"><span>Penjualan</span><span>${formatNumber(row.total)}</span></div><div class="row sub"><span>Pembatalan</span><span>0</span></div></div>`;
  }).join('');
  return `<!doctype html><html><head><meta charset="utf-8"><style>
    @page { margin: 2mm; size: 58mm auto; }
    body { width: 54mm; margin: 0 auto; font-family: "Courier New", monospace; font-size: 10px; color: #111; }
    .center { text-align: center; }
    .receipt-logo { display:block; max-width: 30mm; max-height: 16mm; object-fit: contain; margin: 0 auto 2mm; }
    .receipt-logo-text { text-align:center; font-weight:bold; font-size:14px; letter-spacing:1px; margin-bottom:1mm; }
    .receipt-address { text-align:center; font-size:9px; line-height:1.25; margin-bottom:2mm; white-space:normal; }
    .title { text-align:center; margin: 1mm 0 2mm; }
    .row { display:flex; justify-content:space-between; gap:5px; line-height:1.35; }
    .row span:first-child { white-space: nowrap; }
    .row span:last-child { text-align:right; margin-left:auto; }
    .sub span:first-child { padding-left: 3mm; }
    .sep { border-top: 1px dashed #111; margin: 2mm 0; }
    .section { margin: 1mm 0; }
  </style></head><body>
    ${logo}${address}
    <div class="title">${escapeHtml(data.store.name || 'Adena')}<br/>Penutupan Penjualan</div>
    <div class="row"><span>Dicetak</span><span>${escapeHtml(data.printedAt)}</span></div>
    <br/>
    <div class="row"><span>Kasir</span><span>${escapeHtml(data.cashier)}</span></div>
    <div class="row"><span>Mulai Shift</span><span>${escapeHtml(data.shift.opened_at || '-')}</span></div>
    <div class="row"><span>Akhir Shift</span><span>${escapeHtml(data.printedAt)}</span></div>
    <div class="row"><span>Tanggal Jual</span><span>${escapeHtml((data.printedAt || '').slice(0,10))}</span></div>
    <div class="row"><span>Jumlah Tamu</span><span>${formatNumber(data.transactionCount)}</span></div>
    <div class="row"><span>Resi</span><span>${formatNumber(data.itemQty)} pack(s)</span></div>
    <div class="row"><span>Pengembalian</span><span>0</span></div>
    <br/>
    <div class="row"><span>Total Penjualan</span><span>${formatNumber(data.totalSales)}</span></div>
    <div class="sep"></div>
    <div class="row"><span>Subtotal</span><span>${formatNumber(data.totalSales)}</span></div>
    <br/>
    <div class="row"><span>Kas Diharapkan</span><span>${formatNumber(data.summary.expected_cash)}</span></div>
    <div class="sep"></div>
    <div class="row"><span>Awal di Laci</span><span>${formatNumber(data.summary.opening_cash)}</span></div>
    <div class="row"><span>Penjualan Tunai</span><span>${formatNumber(data.summary.cash_sales)}</span></div>
    <div class="row"><span>Pengembalian Tunai</span><span>${formatNumber(data.summary.cash_refund)}</span></div>
    <div class="row"><span>Pembatalan Tunai</span><span>0</span></div>
    <div class="row"><span>Kas Masuk-Keluar</span><span>${formatNumber((data.summary.cash_in || 0) - (data.summary.cash_out || 0))}</span></div>
    <br/>
    <div class="row"><span>Kas Aktual</span><span>${formatNumber(data.countedCash)}</span></div>
    <div class="sep"></div>
    <div class="row"><span>Kas Selisih</span><span>${formatNumber(data.cashDifference)}</span></div>
    <div class="sep"></div>
    ${paymentSections}
    <div class="sep"></div>
    <div class="row"><span>Total Diharapkan</span><span>${formatNumber(data.totalExpected)}</span></div>
    <div class="sep"></div>
    <div class="row"><span>Total Aktual</span><span>${formatNumber(data.totalActual)}</span></div>
    <div class="sep"></div>
    <div class="row"><span>Total Selisih</span><span>${formatNumber(data.totalDifference)}</span></div>
  </body></html>`;
}

async function handleSyncBeforeExit() {
  try {
    await syncPendingTransactions();
    await retryPendingShiftSync();
  } catch (error) {
    console.warn('[exit:sync] sync before exit failed; exiting anyway', error.message);
  }
  return { shouldQuit: true, mode: 'synced_or_queued' };
}

function createWindow() {
  const iconPath = path.join(__dirname, '../../assets/icon.ico');
  mainWindow = new BrowserWindow({
    width: 1600,
    height: 920,
    minWidth: 1280,
    minHeight: 760,
    autoHideMenuBar: true,
    icon: fs.existsSync(iconPath) ? iconPath : undefined,
    webPreferences: {
      preload: path.join(__dirname, 'preload.js'),
      contextIsolation: true,
      nodeIntegration: false
    }
  });

  nativeTheme.themeSource = 'light';
  mainWindow.loadFile(path.join(__dirname, '../renderer/index.html'));
  mainWindow.on('close', async (event) => {
    if (isQuittingConfirmed) return;
    event.preventDefault();
    const decision = await handleSyncBeforeExit();
    if (!decision.shouldQuit) return;
    isQuittingConfirmed = true;
    mainWindow.close();
  });
}

async function resetAllAppData() {
  const userDataPath = app.getPath('userData');
  closeDb();

  try {
    await session.defaultSession.clearStorageData();
    await session.defaultSession.clearCache();
  } catch (_) {}

  store.clear();
  Object.entries(DEFAULT_SETTINGS).forEach(([key, value]) => store.set(key, value));
  store.delete('sessionUser');
  store.delete('allowIncrementalSyncOnce');
  store.delete('lastSyncAt');

  fs.rmSync(userDataPath, { recursive: true, force: true });
  fs.mkdirSync(userDataPath, { recursive: true });

  app.relaunch();
  app.exit(0);
  return { ok: true };
}

app.whenReady().then(() => {
  initDb();
  store.delete('sessionUser');
  createWindow();
});

process.on('unhandledRejection', (reason) => {
  console.error('[main] unhandledPromiseRejection', reason);
});

process.on('uncaughtException', (error) => {
  console.error('[main] uncaughtException', error);
});

ipcMain.handle('settings:get', () => getPublicSettings());
ipcMain.handle('config:getApi', async () => getApiConfig());
ipcMain.handle('config:setApi', async (_event, data) => {
  const apiBaseUrl = String(data?.apiBaseUrl || '').trim().replace(/\/$/, '');
  const apiToken = String(data?.apiToken || '').trim();

  if (!apiBaseUrl) {
    return { ok: false, message: 'Base URL API belum disetting' };
  }

  if (!/^https?:\/\//i.test(apiBaseUrl)) {
    return { ok: false, message: 'Base URL harus diawali http:// atau https://' };
  }

  if (!apiToken) {
    return { ok: false, message: 'Token API wajib diisi' };
  }

  store.set('apiBaseUrl', apiBaseUrl);
  store.set('apiToken', apiToken);

  const saved = getApiConfig();

  return {
    ok: true,
    apiBaseUrl: saved.apiBaseUrl,
    tokenPreview: saved.apiToken
      ? `${saved.apiToken.slice(0, 4)}***${saved.apiToken.slice(-2)}`
      : '(kosong)'
  };
});
ipcMain.handle('settings:set', (_, patch) => {
  const normalized = { ...(patch || {}) };
  if (Object.prototype.hasOwnProperty.call(normalized, 'deviceCode')) {
    normalized.deviceCode = String(normalized.deviceCode || '').trim().toUpperCase().replace(/\s+/g, '');
  }
  Object.entries(normalized).forEach(([k, v]) => store.set(k, v));
  return getPublicSettings();
});
ipcMain.handle('settings:saveApi', async (_, payload) => {
  const apiBaseUrl = String(payload?.apiBaseUrl || '').trim();
  const apiToken = String(payload?.apiToken || '').trim();
  if (!apiBaseUrl || !apiToken) {
    return { ok: false, message: 'Token API belum disetting' };
  }
  console.log('[settings:saveApi]', 'tokenLength', apiToken.length, 'token', maskToken(apiToken));
  store.set('apiBaseUrl', apiBaseUrl);
  store.set('apiToken', apiToken);
  store.set('deviceCode', '');
  store.delete('lastSyncAt');
  store.delete('allowIncrementalSyncOnce');

  const testResp = await testConnection({ baseURL: apiBaseUrl, token: apiToken });
  if (!testResp?.ok) return testResp;

  if (testResp?.token?.device_code) {
    store.set('deviceCode', String(testResp.token.device_code).trim().toUpperCase());
  }

  return { ok: true, data: getPublicSettings() };
});
ipcMain.handle('settings:printers', async () => {
  if (!mainWindow) return [];
  const printers = await mainWindow.webContents.getPrintersAsync();
  return printers.map((p) => ({ name: p.name, displayName: p.displayName || p.name, isDefault: !!p.isDefault }));
});
ipcMain.handle('app:reset-all', async () => resetAllAppData());

ipcMain.handle('api:test', async (_, overrides) => testConnection(overrides || {}));
ipcMain.handle('auth:login', async (_, payload) => {
  if (!isApiConfigured()) {
    return { ok: false, message: 'Setting API belum lengkap. Isi Base URL dan Token API dahulu.' };
  }
  const resp = await login(payload?.username, payload?.password);
  if (resp?.ok && resp.user) store.set('sessionUser', resp.user);
  if (resp?.ok && resp?.device_code) store.set('deviceCode', String(resp.device_code).trim().toUpperCase());
  return resp;
});
ipcMain.handle('auth:logout', () => {
  store.delete('sessionUser');
  return { ok: true };
});
ipcMain.handle('auth:logoutWithPrompt', async () => {
  const decision = await handleSyncBeforeExit();
  if (!decision.shouldQuit) return { ok: false, cancelled: true };
  store.delete('sessionUser');
  return { ok: true, mode: decision.mode };
});
ipcMain.handle('sync:master', async (_, options) => syncMaster(options || {}));
ipcMain.handle('sync:pending', async () => syncPendingTransactions());
ipcMain.handle('image:cacheProduct', async (_, payload) => cacheProductImage(payload?.productId, payload?.imagePath));
ipcMain.handle('sale:saveLocal', async (_, payload) => saveSaleLocally(payload));

ipcMain.handle('pos:state', () => {
  const db = initDb();
  const products = db.prepare('SELECT id, name, price, category, category_id, category_name, image_path, local_image_path FROM products ORDER BY name').all();
  const categories = db.prepare('SELECT id, name FROM product_categories ORDER BY name').all();
  const guides = db.prepare('SELECT id, name FROM guides WHERE is_active = 1 ORDER BY name').all();
  const paymentMethods = db.prepare('SELECT code, name FROM payment_methods WHERE is_active = 1 ORDER BY sort_order, id').all();
  const banks = db.prepare('SELECT id, name FROM qris_banks WHERE is_active = 1 ORDER BY sort_order, id').all();
  const activeShift = activeShiftLocal();
  const pendingSyncCount = db.prepare("SELECT COUNT(DISTINCT local_transaction_id) as c FROM sales WHERE sync_status IN ('pending','failed')").get().c;
  const pendingShiftSync = db.prepare("SELECT COUNT(*) as c FROM shift_sync_queue WHERE sync_status = 'pending'").get().c;
  const settingsRows = db.prepare('SELECT key, value FROM settings').all();
  const syncedSettings = Object.fromEntries(settingsRows.map((r) => [r.key, r.value]));
  const storeIdentity = getStoreIdentity();
  syncedSettings.store_name = syncedSettings.store_name || storeIdentity.name || 'Adena';
  syncedSettings.store_address = syncedSettings.store_address || storeIdentity.address || '';
  syncedSettings.store_logo_local_uri = fileUriIfExists(storeIdentity.logoPath);
  syncedSettings.store_logo_local_path = storeIdentity.logoPath || '';
  return { products, categories, guides, paymentMethods, banks, activeShift, shiftSummary: calculateShiftSummary(activeShift), pendingSyncCount, pendingShiftSync, syncedSettings, lastSyncAt: null };
});

ipcMain.handle('history:list', (_, filters = {}) => {
  try {
    const db = initDb();
    const where = [];
    const params = [];
    if (filters.from) { where.push('sold_at >= ?'); params.push(filters.from); }
    if (filters.to) { where.push('sold_at <= ?'); params.push(filters.to); }
    if (filters.guideName) { where.push('guide_name = ?'); params.push(filters.guideName); }
    if (filters.paymentMethod) { where.push('payment_method = ?'); params.push(filters.paymentMethod); }
    if (filters.syncStatus) { where.push('sync_status = ?'); params.push(filters.syncStatus); }
    const sqlWhere = where.length ? `WHERE ${where.join(' AND ')}` : '';
    const grouped = getGroupedSales(db, sqlWhere, params).slice(0, 300);
    const rows = grouped.map((r) => ({ transaction_code: r.transaction_code, transaction_group_id: r.key, sold_at: r.sold_at, created_by: r.created_by, guide_name: r.guide_name, payment_method: r.payment_method, payment_bank: r.payment_bank, sync_status: r.sync_status, cash_received: r.cash_received, cash_change: r.cash_change, total: r.final_total }));
    const omzet = grouped.reduce((sum, row) => sum + Number(row.final_total || 0), 0);
    return { ok: true, rows, omzet };
  } catch (error) {
    return { ok: false, message: error.message };
  }
});

ipcMain.handle('history:detail', (_, transactionGroupId) => {
  const db = initDb();
  const items = db.prepare(`SELECT s.transaction_code, s.sold_at, s.guide_name, s.payment_method, s.payment_bank, s.sync_status, s.cash_received, s.cash_change, s.qty, s.price_each, s.total, s.discount_amount, s.discount_type, s.tx_discount_amount, s.tx_discount_type, p.name AS product_name
    FROM sales s LEFT JOIN products p ON p.id = s.product_id
    WHERE COALESCE(s.transaction_group_uuid, s.local_transaction_id, s.transaction_code) = ?
    ORDER BY s.id`).all(transactionGroupId);
  return { items };
});

ipcMain.handle('history:recap', (_, filters = {}) => {
  try {
    const db = initDb();
    const where = [];
    const params = [];
    if (filters.from) { where.push('sold_at >= ?'); params.push(filters.from); }
    if (filters.to) { where.push('sold_at <= ?'); params.push(filters.to); }
    const sqlWhere = where.length ? 'WHERE ' + where.join(' AND ') : '';
    const grouped = getGroupedSales(db, sqlWhere, params);
    const recap = new Map();
    for (const row of grouped) {
      const bank = row.payment_bank || '';
      const key = `${row.payment_method || ''}::${bank}`;
      if (!recap.has(key)) recap.set(key, { payment_method: row.payment_method, payment_bank: bank, trx_count: 0, total: 0 });
      const rec = recap.get(key);
      rec.trx_count += 1;
      rec.total += Number(row.final_total || 0);
    }
    const rows = Array.from(recap.values()).sort((a, b) => String(a.payment_method || '').localeCompare(String(b.payment_method || '')) || String(a.payment_bank || '').localeCompare(String(b.payment_bank || '')));
    return { ok: true, rows, total: { trx_count: grouped.length, omzet: grouped.reduce((sum, row) => sum + Number(row.final_total || 0), 0) } };
  } catch (error) { return { ok: false, message: error.message }; }
});

ipcMain.handle('orders:list', () => {
  const db = initDb();
  const orders = db.prepare('SELECT id, order_code, status, created_at, customer_name, customer_contact, customer_address, customer_note, total_amount FROM orders ORDER BY created_at DESC LIMIT 200').all();
  const items = db.prepare('SELECT order_id, product_name, qty, subtotal FROM order_items ORDER BY id').all();
  return { orders, items };
});

ipcMain.handle('print:receipt', async (_, payload) => printReceipt(payload));
ipcMain.handle('shift:status', async () => { const shift = activeShiftLocal(); return { ok: true, shift, has_active_shift: !!shift, state: shift ? 'active_shift_exists' : 'no_active_shift', summary: calculateShiftSummary(shift) }; });
ipcMain.handle('shift:closeReport', async (_, payload = {}) => {
  const data = getShiftClosePrintData(payload.counted_cash_total, payload.user || null);
  if (!data.ok) return data;
  return { ...data, html: buildShiftClosePrintHtml(data) };
});
ipcMain.handle('shift:open', async (_, payload) => performShift('open', payload));
ipcMain.handle('shift:close', async (_, payload) => performShift('close', payload));
ipcMain.handle('shift:retryPending', async () => retryPendingShiftSync());

app.on('window-all-closed', () => {
  if (process.platform !== 'darwin') app.quit();
});
