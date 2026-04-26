const path = require('path');
const { app, BrowserWindow, ipcMain, nativeTheme } = require('electron');
const { initDb } = require('./db');
const { store } = require('./config');
const { testConnection, login } = require('./api');
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
  createWindow();
});

ipcMain.handle('settings:get', () => store.store);
ipcMain.handle('settings:set', (_, patch) => {
  Object.entries(patch || {}).forEach(([k, v]) => store.set(k, v));
  return store.store;
});

ipcMain.handle('api:test', async () => testConnection());
ipcMain.handle('auth:login', async (_, payload) => {
  const resp = await login(payload?.username, payload?.password);
  if (resp?.ok && resp.user) {
    store.set('sessionUser', resp.user);
    store.set('sessionAt', new Date().toISOString());
  }
  return resp;
});
ipcMain.handle('auth:session', () => {
  const user = store.get('sessionUser');
  if (!user) return { ok: false, user: null };
  return { ok: true, user };
});
ipcMain.handle('sync:master', async () => syncMaster());
ipcMain.handle('sync:pending', async () => syncPendingTransactions());
ipcMain.handle('sale:saveLocal', async (_, payload) => saveSaleLocally(payload));

ipcMain.handle('pos:state', () => {
  const db = initDb();
  const products = db.prepare('SELECT id, name, price, category, image_path FROM products ORDER BY name').all();
  const guides = db.prepare('SELECT id, name FROM guides WHERE is_active = 1 ORDER BY name').all();
  const paymentMethods = db.prepare('SELECT code, name FROM payment_methods WHERE is_active = 1 ORDER BY sort_order, id').all();
  const banks = db.prepare('SELECT id, name FROM qris_banks WHERE is_active = 1 ORDER BY sort_order, id').all();
  const pendingSyncCount = db.prepare("SELECT COUNT(DISTINCT local_transaction_id) as c FROM sales WHERE sync_status IN ('pending','failed')").get().c;
  return { products, guides, paymentMethods, banks, pendingSyncCount, lastSyncAt: store.get('lastSyncAt') };
});

ipcMain.handle('print:receipt', async (_, payload) => printReceipt(payload));

app.on('window-all-closed', () => {
  if (process.platform !== 'darwin') app.quit();
});
