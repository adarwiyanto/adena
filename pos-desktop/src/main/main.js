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
const { localDateTimeString } = require('./time');

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

function numberOrZero(value) {
  const n = Number(value);
  return Number.isFinite(n) ? n : 0;
}

function txDiscountValue(total, amount, type) {
  const subtotal = Math.max(0, numberOrZero(total));
  const discAmount = Math.max(0, numberOrZero(amount));
  const discType = type === 'percent' ? 'percent' : 'fixed';
  if (!subtotal || !discAmount) return 0;
  if (discType === 'percent') return Math.min(subtotal, Math.round(subtotal * Math.min(100, discAmount) / 100));
  return Math.min(subtotal, discAmount);
}

function salesTransactionsForWhere(whereSql, params = [], options = {}) {
  const db = initDb();
  const limit = Number(options.limit || 0);
  const limitSql = limit > 0 ? ` LIMIT ${Math.min(2000, Math.floor(limit))}` : '';
  const rows = db.prepare(`SELECT COALESCE(transaction_group_uuid, local_transaction_id, transaction_code) AS tx_key,
      transaction_code, sold_at, created_by, guide_name, payment_method, payment_bank, sync_status,
      cash_received, cash_change, customer_name, customer_phone, total, tx_discount_amount, tx_discount_type
    FROM sales ${whereSql || ''}
    ORDER BY sold_at DESC, id ASC${limitSql}`).all(...params);
  const grouped = new Map();
  for (const row of rows) {
    const key = row.tx_key || row.transaction_code;
    if (!grouped.has(key)) {
      grouped.set(key, { ...row, transaction_group_id: key, subtotal: 0, total: 0, tx_count: 1 });
    }
    const tx = grouped.get(key);
    tx.subtotal += numberOrZero(row.total);
    tx.tx_discount_amount = row.tx_discount_amount || tx.tx_discount_amount || 0;
    tx.tx_discount_type = row.tx_discount_type || tx.tx_discount_type || 'fixed';
    tx.cash_received = row.cash_received ?? tx.cash_received;
    tx.cash_change = row.cash_change ?? tx.cash_change;
    tx.customer_name = tx.customer_name || row.customer_name || '';
    tx.customer_phone = tx.customer_phone || row.customer_phone || '';
  }
  return Array.from(grouped.values()).map((tx) => {
    const discount = txDiscountValue(tx.subtotal, tx.tx_discount_amount, tx.tx_discount_type);
    return { ...tx, total: Math.max(0, tx.subtotal - discount), tx_discount_value: discount };
  });
}

function calculateShiftSummary(shift = null) {
  const db = initDb();
  const active = shift || activeShiftLocal();
  if (!active) {
    return { opening_cash: 0, cash_sales: 0, cash_refund: 0, cash_in: 0, cash_out: 0, non_cash_sales: 0, expected_cash: 0 };
  }
  const openingCash = Number(active.opening_cash_actual ?? active.opening_cash_default ?? 0);
  const txRows = db.prepare(`
    SELECT
      COALESCE(transaction_group_uuid, local_transaction_id, transaction_code) AS tx_key,
      LOWER(COALESCE(payment_method,'')) AS payment_method,
      COALESCE(SUM(total),0) AS subtotal,
      COALESCE(MAX(tx_discount_amount),0) AS tx_discount_amount,
      COALESCE(MAX(tx_discount_type),'fixed') AS tx_discount_type
    FROM sales
    WHERE shift_id = ?
    GROUP BY COALESCE(transaction_group_uuid, local_transaction_id, transaction_code), LOWER(COALESCE(payment_method,''))
  `).all(active.id);
  let cashSales = 0;
  let nonCashSales = 0;
  for (const row of txRows) {
    const total = Math.max(0, numberOrZero(row.subtotal) - txDiscountValue(row.subtotal, row.tx_discount_amount, row.tx_discount_type));
    const method = String(row.payment_method || '').toLowerCase();
    if (method === 'cash' || method === 'tunai') cashSales += total;
    else nonCashSales += total;
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
  const transactions = salesTransactionsForWhere('WHERE shift_id = ?', [shift.id]);
  const transactionCount = transactions.length;
  const itemQty = db.prepare('SELECT COALESCE(SUM(qty),0) AS qty FROM sales WHERE shift_id = ?').get(shift.id)?.qty || 0;
  const totalSales = transactions.reduce((sum, row) => sum + Number(row.total || 0), 0);
  const paymentMap = new Map();
  for (const row of transactions) {
    const label = row.payment_bank || row.payment_method || '-';
    const key = `${row.payment_method || ''}||${label}`;
    if (!paymentMap.has(key)) paymentMap.set(key, { payment_method: row.payment_method, label, total: 0, tx_count: 0 });
    const bucket = paymentMap.get(key);
    bucket.total += Number(row.total || 0);
    bucket.tx_count += 1;
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
    await retryPendingShiftSync({ skipClose: true });
  } catch (error) {
    console.warn('[exit:sync] sync before exit failed; exiting anyway', error.message);
  }
  return { shouldQuit: true, mode: 'synced_or_queued' };
}

function createWindow() {
  const iconPath = path.join(__dirname, '../../assets/icon.ico');
  mainWindow = new BrowserWindow({
    width: 1440,
    height: 920,
    minWidth: 1200,
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

ipcMain.handle('pos:status', () => {
  const db = initDb();
  const activeShift = activeShiftLocal();
  const pendingSyncCount = db.prepare("SELECT COUNT(DISTINCT local_transaction_id) as c FROM sales WHERE sync_status IN ('pending','failed')").get().c;
  const pendingShiftSync = db.prepare("SELECT COUNT(*) as c FROM shift_sync_queue WHERE sync_status = 'pending'").get().c;
  return { activeShift, shiftSummary: calculateShiftSummary(activeShift), pendingSyncCount, pendingShiftSync };
});

ipcMain.handle('customers:recap', (_, filters = {}) => {
  try {
    const db = initDb();
    const q = String(filters.search || '').trim().toLowerCase();
    const sortBy = ['phone', 'last', 'transactions', 'total'].includes(filters.sortBy) ? filters.sortBy : 'name';
    const dir = String(filters.dir || 'asc').toLowerCase() === 'desc' ? 'desc' : 'asc';
    const rows = db.prepare(`
      SELECT
        TRIM(COALESCE(NULLIF(customer_name,''), '(Tanpa nama)')) AS customer_name,
        TRIM(COALESCE(customer_phone,'')) AS customer_phone,
        COUNT(DISTINCT COALESCE(transaction_group_uuid, local_transaction_id, transaction_code)) AS transaction_count,
        COALESCE(SUM(total),0) AS total_spend,
        MAX(sold_at) AS last_transaction_at
      FROM sales
      WHERE (COALESCE(customer_name,'') <> '' OR COALESCE(customer_phone,'') <> '')
      GROUP BY LOWER(TRIM(COALESCE(NULLIF(customer_name,''), '(Tanpa nama)'))), TRIM(COALESCE(customer_phone,''))
    `).all();
    let filtered = rows;
    if (q) {
      filtered = rows.filter((r) => String(r.customer_name || '').toLowerCase().includes(q) || String(r.customer_phone || '').toLowerCase().includes(q));
    }
    const factor = dir === 'desc' ? -1 : 1;
    filtered.sort((a, b) => {
      if (sortBy === 'phone') return factor * String(a.customer_phone || '').localeCompare(String(b.customer_phone || ''), 'id', { numeric: true, sensitivity: 'base' });
      if (sortBy === 'last') return factor * String(a.last_transaction_at || '').localeCompare(String(b.last_transaction_at || ''));
      if (sortBy === 'transactions') return factor * (Number(a.transaction_count || 0) - Number(b.transaction_count || 0));
      if (sortBy === 'total') return factor * (Number(a.total_spend || 0) - Number(b.total_spend || 0));
      return factor * String(a.customer_name || '').localeCompare(String(b.customer_name || ''), 'id', { numeric: true, sensitivity: 'base' });
    });
    return { ok: true, rows: filtered.slice(0, 500), total: { customers: filtered.length, transactions: filtered.reduce((sum, r) => sum + Number(r.transaction_count || 0), 0), spend: filtered.reduce((sum, r) => sum + Number(r.total_spend || 0), 0) } };
  } catch (error) {
    return { ok: false, message: error.message };
  }
});

ipcMain.handle('history:list', (_, filters = {}) => {
  try {
    const where = [];
    const params = [];
    if (filters.from) { where.push('sold_at >= ?'); params.push(filters.from); }
    if (filters.to) { where.push('sold_at <= ?'); params.push(filters.to); }
    if (filters.guideName) { where.push('guide_name = ?'); params.push(filters.guideName); }
    if (filters.paymentMethod) { where.push('payment_method = ?'); params.push(filters.paymentMethod); }
    if (filters.syncStatus) { where.push('sync_status = ?'); params.push(filters.syncStatus); }
    const sqlWhere = where.length ? `WHERE ${where.join(' AND ')}` : '';
    const rows = salesTransactionsForWhere(sqlWhere, params, { limit: 1500 }).slice(0, 300).map((r) => ({
      transaction_code: r.transaction_code,
      transaction_group_id: r.transaction_group_id,
      sold_at: r.sold_at,
      created_by: r.created_by,
      guide_name: r.guide_name,
      payment_method: r.payment_method,
      payment_bank: r.payment_bank,
      sync_status: r.sync_status,
      customer_name: r.customer_name || '',
      customer_phone: r.customer_phone || '',
      cash_received: r.cash_received,
      cash_change: r.cash_change,
      total: r.total
    }));
    return { ok: true, rows, omzet: rows.reduce((sum, r) => sum + Number(r.total || 0), 0) };
  } catch (error) {
    return { ok: false, message: error.message };
  }
});


ipcMain.handle('history:detail', (_, transactionGroupId) => {
  const db = initDb();
  const items = db.prepare(`SELECT s.transaction_code, s.sold_at, s.guide_name, s.payment_method, s.payment_bank, s.sync_status, s.customer_name, s.customer_phone, s.cash_received, s.cash_change, s.qty, s.price_each, s.total, p.name AS product_name
    FROM sales s LEFT JOIN products p ON p.id = s.product_id
    WHERE COALESCE(s.transaction_group_uuid, s.local_transaction_id, s.transaction_code) = ?
    ORDER BY s.id`).all(transactionGroupId);
  return { items };
});

ipcMain.handle('history:recap', (_, filters = {}) => {
  try {
    const where = [];
    const params = [];
    if (filters.from) { where.push('sold_at >= ?'); params.push(filters.from); }
    if (filters.to) { where.push('sold_at <= ?'); params.push(filters.to); }
    const sqlWhere = where.length ? 'WHERE ' + where.join(' AND ') : '';
    const transactions = salesTransactionsForWhere(sqlWhere, params);
    const paymentMap = new Map();
    for (const row of transactions) {
      const key = `${row.payment_method || ''}||${row.payment_bank || ''}`;
      if (!paymentMap.has(key)) paymentMap.set(key, { payment_method: row.payment_method, payment_bank: row.payment_bank || '', trx_count: 0, total: 0 });
      const bucket = paymentMap.get(key);
      bucket.trx_count += 1;
      bucket.total += Number(row.total || 0);
    }
    const rows = Array.from(paymentMap.values()).sort((a, b) => String(a.payment_method || '').localeCompare(String(b.payment_method || '')) || String(a.payment_bank || '').localeCompare(String(b.payment_bank || '')));
    const total = { trx_count: transactions.length, omzet: transactions.reduce((sum, r) => sum + Number(r.total || 0), 0) };
    return { ok: true, rows, total };
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
