const path = require('path');
const fs = require('fs');
const { app, BrowserWindow, ipcMain, nativeTheme, dialog, session } = require('electron');
const { initDb, closeDb } = require('./db');
const { store, DEFAULT_SETTINGS, getApiConfig } = require('./config');
const { testConnection, login, shiftAction } = require('./api');
const { performShift, retryPendingShiftSync } = require('./shift');
const { syncMaster, syncPendingTransactions } = require('./sync');
const { saveSaleLocally } = require('./transactions');
const { printReceipt } = require('./print');

let mainWindow;
let isQuittingConfirmed = false;

function isApiConfigured() {
  const cfg = getApiConfig();
  return !!(cfg.apiBaseUrl && cfg.apiToken);
}

function getPublicSettings() {
  const cfg = getApiConfig();
  const tokenMasked = cfg.apiToken ? `${cfg.apiToken.slice(0, 4)}***${cfg.apiToken.slice(-2)}` : '';
  return {
    ...store.store,
    apiBaseUrl: cfg.apiBaseUrl,
    apiToken: '',
    apiTokenMasked: tokenMasked,
    deviceCode: cfg.deviceCode
  };
}

async function handleSyncBeforeExit() {
  const choice = await dialog.showMessageBox(mainWindow, {
    type: 'question',
    buttons: ['Sinkron Dulu', 'Keluar Tanpa Sinkron', 'Batal'],
    defaultId: 0,
    cancelId: 2,
    title: 'Konfirmasi Keluar',
    message: 'Sinkronisasi dulu sebelum keluar?'
  });

  if (choice.response === 2) return { shouldQuit: false, mode: 'cancel' };
  if (choice.response === 1) return { shouldQuit: true, mode: 'skip_sync' };

  const pendingResp = await syncPendingTransactions();
  const shiftResp = await retryPendingShiftSync();
  if (!pendingResp?.ok || !shiftResp?.ok) {
    await dialog.showMessageBox(mainWindow, {
      type: 'error',
      title: 'Sync Gagal',
      message: 'Sinkronisasi sebelum keluar gagal. Silakan coba lagi atau pilih keluar tanpa sinkron.'
    });
    return { shouldQuit: false, mode: 'sync_failed', pendingResp, shiftResp };
  }
  return { shouldQuit: true, mode: 'synced' };
}

function createWindow() {
  mainWindow = new BrowserWindow({
    width: 1440,
    height: 920,
    minWidth: 1200,
    minHeight: 760,
    autoHideMenuBar: true,
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
    return { ok: false, message: 'Base URL dan Token API wajib diisi.' };
  }
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

  return { ok: true, data: getPublicSettings(), connection: testResp };
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
ipcMain.handle('sale:saveLocal', async (_, payload) => saveSaleLocally(payload));

ipcMain.handle('pos:state', () => {
  const db = initDb();
  const products = db.prepare('SELECT id, name, price, category, category_id, category_name, image_path, local_image_path FROM products ORDER BY name').all();
  const categories = db.prepare('SELECT id, name FROM product_categories ORDER BY name').all();
  const guides = db.prepare('SELECT id, name FROM guides WHERE is_active = 1 ORDER BY name').all();
  const paymentMethods = db.prepare('SELECT code, name FROM payment_methods WHERE is_active = 1 ORDER BY sort_order, id').all();
  const banks = db.prepare('SELECT id, name FROM qris_banks WHERE is_active = 1 ORDER BY sort_order, id').all();
  const activeShift = db.prepare("SELECT * FROM pos_shifts WHERE status='open' ORDER BY id DESC LIMIT 1").get() || null;
  const pendingSyncCount = db.prepare("SELECT COUNT(DISTINCT local_transaction_id) as c FROM sales WHERE sync_status IN ('pending','failed')").get().c;
  const pendingShiftSync = db.prepare("SELECT COUNT(*) as c FROM shift_sync_queue WHERE sync_status = 'pending'").get().c;
  const settingsRows = db.prepare('SELECT key, value FROM settings').all();
  const syncedSettings = Object.fromEntries(settingsRows.map((r) => [r.key, r.value]));
  return { products, categories, guides, paymentMethods, banks, activeShift, pendingSyncCount, pendingShiftSync, syncedSettings, lastSyncAt: null };
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
    const rows = db.prepare(`SELECT transaction_code, COALESCE(transaction_group_uuid, local_transaction_id, transaction_code) AS transaction_group_id, sold_at, created_by, guide_name, payment_method, payment_bank, sync_status, SUM(total) AS total
      FROM sales ${sqlWhere}
      GROUP BY COALESCE(transaction_group_uuid, local_transaction_id, transaction_code)
      ORDER BY sold_at DESC LIMIT 300`).all(...params);
    const omzetRow = db.prepare(`SELECT COALESCE(SUM(total),0) AS omzet FROM (SELECT SUM(total) AS total FROM sales ${sqlWhere} GROUP BY COALESCE(transaction_group_uuid, local_transaction_id, transaction_code))`).get(...params);
    return { ok: true, rows, omzet: Number(omzetRow?.omzet || 0) };
  } catch (error) {
    return { ok: false, message: error.message };
  }
});

ipcMain.handle('history:detail', (_, transactionGroupId) => {
  const db = initDb();
  const items = db.prepare(`SELECT s.transaction_code, s.sold_at, s.guide_name, s.payment_method, s.payment_bank, s.sync_status, s.qty, s.price_each, s.total, p.name AS product_name
    FROM sales s LEFT JOIN products p ON p.id = s.product_id
    WHERE COALESCE(s.transaction_group_uuid, s.local_transaction_id, s.transaction_code) = ?
    ORDER BY s.id`).all(transactionGroupId);
  return { items };
});

ipcMain.handle('orders:list', () => {
  const db = initDb();
  const orders = db.prepare('SELECT id, order_code, status, created_at, customer_name, customer_contact, customer_address, customer_note, total_amount FROM orders ORDER BY created_at DESC LIMIT 200').all();
  const items = db.prepare('SELECT order_id, product_name, qty, subtotal FROM order_items ORDER BY id').all();
  return { orders, items };
});

ipcMain.handle('print:receipt', async (_, payload) => printReceipt(payload));
ipcMain.handle('shift:status', async () => shiftAction('status'));
ipcMain.handle('shift:open', async (_, payload) => performShift('open', payload));
ipcMain.handle('shift:close', async (_, payload) => performShift('close', payload));
ipcMain.handle('shift:retryPending', async () => retryPendingShiftSync());

app.on('window-all-closed', () => {
  if (process.platform !== 'darwin') app.quit();
});
