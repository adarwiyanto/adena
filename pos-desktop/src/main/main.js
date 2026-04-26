const path = require('path');
const { app, BrowserWindow, ipcMain, nativeTheme } = require('electron');
const { initDb } = require('./db');
const { store } = require('./config');
const { testConnection, login, shiftAction } = require('./api');
const { syncMaster, syncPendingTransactions } = require('./sync');
const { saveSaleLocally } = require('./transactions');
const { printReceipt } = require('./print');

let mainWindow;

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

ipcMain.handle('settings:get', () => store.store);
ipcMain.handle('settings:set', (_, patch) => {
  Object.entries(patch || {}).forEach(([k, v]) => store.set(k, v));
  return store.store;
});
ipcMain.handle('settings:printers', async () => {
  if (!mainWindow) return [];
  const printers = await mainWindow.webContents.getPrintersAsync();
  return printers.map((p) => ({ name: p.name, displayName: p.displayName || p.name, isDefault: !!p.isDefault }));
});

ipcMain.handle('api:test', async () => testConnection());
ipcMain.handle('auth:login', async (_, payload) => {
  const resp = await login(payload?.username, payload?.password);
  if (resp?.ok && resp.user) {
    store.set('sessionUser', resp.user);
  }
  return resp;
});
ipcMain.handle('auth:logout', () => {
  store.delete('sessionUser');
  return { ok: true };
});
ipcMain.handle('sync:master', async () => syncMaster());
ipcMain.handle('sync:pending', async () => syncPendingTransactions());
ipcMain.handle('sale:saveLocal', async (_, payload) => saveSaleLocally(payload));

ipcMain.handle('pos:state', () => {
  const db = initDb();
  const products = db.prepare('SELECT id, name, price, category, image_path FROM products ORDER BY name').all();
  const categories = db.prepare('SELECT id, name FROM product_categories ORDER BY name').all();
  const guides = db.prepare('SELECT id, name FROM guides WHERE is_active = 1 ORDER BY name').all();
  const paymentMethods = db.prepare('SELECT code, name FROM payment_methods WHERE is_active = 1 ORDER BY sort_order, id').all();
  const banks = db.prepare('SELECT id, name FROM qris_banks WHERE is_active = 1 ORDER BY sort_order, id').all();
  const activeShift = db.prepare("SELECT * FROM pos_shifts WHERE status='open' ORDER BY id DESC LIMIT 1").get() || null;
  const pendingSyncCount = db.prepare("SELECT COUNT(DISTINCT local_transaction_id) as c FROM sales WHERE sync_status IN ('pending','failed')").get().c;
  return { products, categories, guides, paymentMethods, banks, activeShift, pendingSyncCount, lastSyncAt: store.get('lastSyncAt') };
});

ipcMain.handle('history:list', (_, filters = {}) => {
  try {
    const db = initDb();
    const where = [];
    const params = [];
    if (filters.from) {
      where.push('sold_at >= ?');
      params.push(filters.from);
    }
    if (filters.to) {
      where.push('sold_at <= ?');
      params.push(filters.to);
    }
    if (filters.guideName) {
      where.push('guide_name = ?');
      params.push(filters.guideName);
    }
    if (filters.paymentMethod) {
      where.push('payment_method = ?');
      params.push(filters.paymentMethod);
    }
    if (filters.syncStatus) {
      where.push('sync_status = ?');
      params.push(filters.syncStatus);
    }
    const sqlWhere = where.length ? `WHERE ${where.join(' AND ')}` : '';
    const rows = db.prepare(`SELECT transaction_code, COALESCE(transaction_group_uuid, local_transaction_id, transaction_code) AS transaction_group_id, sold_at, created_by, guide_name, payment_method, payment_bank, sync_status, SUM(total) AS total
      FROM sales ${sqlWhere}
      GROUP BY COALESCE(transaction_group_uuid, local_transaction_id, transaction_code)
      ORDER BY sold_at DESC LIMIT 300`).all(...params);
    const omzetRow = db.prepare(`SELECT COALESCE(SUM(total),0) AS omzet FROM (SELECT SUM(total) AS total FROM sales ${sqlWhere} GROUP BY COALESCE(transaction_group_uuid, local_transaction_id, transaction_code))`).get(...params);
    const omzet = Number(omzetRow?.omzet || 0);
    return { ok: true, rows, omzet };
  } catch (error) {
    console.error('[history:list] failed', error);
    return {
      ok: false,
      message: error.message
    };
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
ipcMain.handle('shift:open', async (_, payload) => shiftAction('open', payload));
ipcMain.handle('shift:close', async (_, payload) => shiftAction('close', payload));

app.on('window-all-closed', () => {
  if (process.platform !== 'darwin') app.quit();
});
