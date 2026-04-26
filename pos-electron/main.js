'use strict';

const { app, BrowserWindow, ipcMain, Menu } = require('electron');
const path   = require('path');
const { execFile } = require('child_process');
const fs     = require('fs');

// Init DB early
const dbMod  = require('./db');
const syncMod = require('./sync');
require('./ipc'); // register all IPC handlers

let mainWindow    = null;
let paymentWindow = null;
let settingsWindow = null;
let syncTimer     = null;

// ── Helpers ───────────────────────────────────────────────────────────────────

function getHelpersPath() {
  return app.isPackaged
    ? path.join(process.resourcesPath, 'helpers')
    : path.join(__dirname, 'helpers');
}

function getAssetsPath() {
  return app.isPackaged
    ? path.join(process.resourcesPath, 'assets')
    : path.join(__dirname, 'assets');
}

function page(name) {
  return path.join(__dirname, 'pages', name);
}

function closeAuxWindows() {
  if (paymentWindow && !paymentWindow.isDestroyed()) {
    paymentWindow.close();
    paymentWindow = null;
  }
  if (settingsWindow && !settingsWindow.isDestroyed()) {
    settingsWindow.close();
    settingsWindow = null;
  }
}

// ── Menu ──────────────────────────────────────────────────────────────────────

function buildMenu() {
  return Menu.buildFromTemplate([
    {
      label: 'Adena POS',
      submenu: [
        { label: 'Sync Manual', accelerator: 'F4', click: () => triggerManualSync() },
        { label: 'Reset API Lokal', click: () => resetLocalSession() },
        { label: 'Pengaturan Printer', accelerator: 'Ctrl+Shift+P', click: () => openSettingsWindow() },
        { type: 'separator' },
        { label: 'Keluar', role: 'quit' },
      ],
    },
  ]);
}

// ── Main window ───────────────────────────────────────────────────────────────

function createMainWindow() {
  mainWindow = new BrowserWindow({
    width: 1280, height: 800,
    minWidth: 1024, minHeight: 700,
    title: 'Adena POS',
    webPreferences: {
      preload: path.join(__dirname, 'pages', 'login-pre.js'),
      nodeIntegration: false,
      contextIsolation: true,
    },
  });

  // Selalu mulai dari halaman login. login page akan memvalidasi sesi/token.
  mainWindow.loadFile(page('login.html'));

  mainWindow.on('closed', () => { mainWindow = null; });
}

// Karena preload tidak bisa diubah setelah window dibuat, kita reuse 1 preload
// yang expose semua IPC channels
function reloadWithPreload(preloadFile, htmlFile) {
  if (!mainWindow || mainWindow.isDestroyed()) {
    createMainWindow();
    if (mainWindow && !mainWindow.isDestroyed()) {
      mainWindow.loadFile(page(htmlFile));
      Menu.setApplicationMenu(buildMenu());
    }
    return;
  }

  const wasMaximized = mainWindow.isMaximized();
  const bounds = mainWindow.getBounds();

  mainWindow.close();
  mainWindow = new BrowserWindow({
    ...bounds,
    title: 'Adena POS',
    webPreferences: {
      preload: path.join(__dirname, 'pages', preloadFile),
      nodeIntegration: false,
      contextIsolation: true,
    },
  });
  if (wasMaximized) mainWindow.maximize();
  mainWindow.loadFile(page(htmlFile));
  mainWindow.on('closed', () => { mainWindow = null; });
  Menu.setApplicationMenu(buildMenu());
}

// IPC: navigasi antar halaman
ipcMain.on('navigate:pos', () => {
  const currentUser = dbMod.getSetting('current_user', '');
  if (!currentUser) {
    if (mainWindow && !mainWindow.isDestroyed()) {
      mainWindow.loadFile(page('login.html'));
      return;
    }
    reloadWithPreload('login-pre.js', 'login.html');
    return;
  }
  reloadWithPreload('pos-pre.js', 'pos.html');
});
ipcMain.on('navigate:login', () => {
  closeAuxWindows();
  reloadWithPreload('login-pre.js', 'login.html');
});
ipcMain.handle('session:reset-local', () => {
  resetLocalSession();
  return { ok: true };
});

// ── Payment window ─────────────────────────────────────────────────────────────

ipcMain.on('open:payment', () => {
  if (paymentWindow && !paymentWindow.isDestroyed()) {
    paymentWindow.focus(); return;
  }
  paymentWindow = new BrowserWindow({
    width: 640, height: 700,
    parent: mainWindow,
    modal: true,
    title: 'Pembayaran — Adena POS',
    resizable: false,
    webPreferences: {
      preload: path.join(__dirname, 'pages', 'payment-pre.js'),
      nodeIntegration: false,
      contextIsolation: true,
    },
  });
  paymentWindow.loadFile(page('payment.html'));
  paymentWindow.setMenu(null);
  paymentWindow.on('closed', () => { paymentWindow = null; });
});

ipcMain.on('close:payment', () => {
  if (paymentWindow && !paymentWindow.isDestroyed()) paymentWindow.close();
});

// Setelah checkout berhasil: print + tutup payment + reload POS
ipcMain.on('checkout:done', async (_, payload) => {
  const receipt = payload?.receipt || null;
  const offlineUuid = payload?.offline_uuid || null;
  if (paymentWindow && !paymentWindow.isDestroyed()) paymentWindow.close();
  if (mainWindow && !mainWindow.isDestroyed()) {
    mainWindow.webContents.send('checkout:status', { text: 'Transaksi tersimpan lokal' });
    mainWindow.webContents.send('checkout:status', { text: 'Sinkronisasi ke server...' });
  }
  const syncResult = await syncMod.runSync(false);
  console.info('[checkout] push result', { offline_uuid: offlineUuid, sync_ok: !!syncResult?.ok, message: syncResult?.message || '' });
  if (mainWindow && !mainWindow.isDestroyed()) {
    const syncLabel = syncResult?.ok ? 'Sinkronisasi berhasil' : 'Sinkronisasi gagal, transaksi disimpan pending';
    mainWindow.webContents.send('checkout:status', { text: syncLabel });
    mainWindow.webContents.send('pos:checkout-finished', {
      synced: !!(syncResult && syncResult.ok),
      offline_uuid: offlineUuid,
      receipt,
      syncMessage: syncResult?.ok ? '' : (syncResult?.message || 'Transaksi tersimpan lokal. Jalankan sync manual.'),
    });
    mainWindow.webContents.send('sync:done', syncResult);
    if (!syncResult?.ok) {
      mainWindow.webContents.send('sync:warning', syncResult?.message || 'Sync gagal sesudah transaksi.');
      if (syncResult?.type === 'auth_error') {
        mainWindow.webContents.send('sync:warning', 'API Token tidak valid. Silakan cek setting API.');
        reloadWithPreload('login-pre.js', 'login.html');
      }
    }
  }
});

ipcMain.handle('print:receipt', async (_, receipt) => {
  if (!receipt) return { ok: false, message: 'Data struk tidak tersedia.' };
  await printReceipt(receipt);
  return { ok: true };
});

// ── Print receipt ─────────────────────────────────────────────────────────────

async function printReceipt(receipt) {
  return new Promise((resolve) => {
    const receiptHtml = generateReceiptHtml(receipt);
    const tmpPath = path.join(app.getPath('temp'), 'adena-receipt.html');
    fs.writeFileSync(tmpPath, receiptHtml, 'utf8');

    const printWin = new BrowserWindow({
      show: false,
      webPreferences: { nodeIntegration: false, contextIsolation: true },
    });
    printWin.loadFile(tmpPath);
    printWin.webContents.once('did-finish-load', () => {
      const printerName = dbMod.getSetting('printerName', '');
      printWin.webContents.print(
        { silent: true, printBackground: false, deviceName: printerName || undefined },
        async (success) => {
          try { printWin.close(); } catch (_) {}
          if (success && dbMod.getSetting('sendRawCut', 'true') !== 'false' && printerName) {
            await sendCutCommand(printerName);
          }
          resolve();
        }
      );
    });
  });
}

function generateReceiptHtml(r) {
  const fmt = (n) => Number(n || 0).toLocaleString('id-ID');
  const items = (r.items || []).map((it) => `
    <div class="item">
      <div class="item-name">${esc(it.name)}</div>
      <div class="item-row">
        <span>${fmt(it.qty)} x Rp ${fmt(it.price_each)}</span>
        <span>Rp ${fmt(it.total)}</span>
      </div>
      ${it.discount_amount > 0 ? `<div class="item-disc">Diskon: Rp ${fmt(it.discount_amount)}</div>` : ''}
    </div>`).join('');

  function esc(s) { return String(s||'').replace(/&/g,'&amp;').replace(/</g,'&lt;').replace(/>/g,'&gt;'); }

  return `<!doctype html><html><head><meta charset="utf-8">
  <style>
    @page { size: 58mm auto; margin: 1mm; }
    * { box-sizing: border-box; margin: 0; padding: 0; }
    body { font-family: 'Courier New', monospace; font-size: 11px; width: 56mm; }
    .center { text-align: center; }
    .bold { font-weight: 700; }
    .divider { border-top: 1px dashed #000; margin: 4px 0; }
    .store-name { font-size: 14px; font-weight: 700; text-align: center; }
    .meta, .line { display: flex; justify-content: space-between; font-size: 10px; margin: 2px 0; }
    .item { margin: 4px 0; }
    .item-name { font-weight: 600; }
    .item-row { display: flex; justify-content: space-between; font-size: 10px; }
    .item-disc { font-size: 9px; color: #333; }
    .total-line { display: flex; justify-content: space-between; font-weight: 700; margin: 2px 0; }
    .footer { text-align: center; font-size: 10px; margin-top: 6px; }
    .spacer { height: 6px; }
  </style>
  </head><body>
  ${r.store_logo ? `<div class="center" style="margin-bottom:4px"><img src="${esc(r.store_logo)}" style="max-width:120px;max-height:60px;object-fit:contain"></div>` : ''}
  <div class="store-name">${esc(r.store_name || 'Adena')}</div>
  ${r.store_subtitle ? `<div class="center">${esc(r.store_subtitle)}</div>` : ''}
  ${r.store_address ? `<div class="center" style="font-size:10px">${esc(r.store_address)}</div>` : ''}
  ${r.store_phone ? `<div class="center" style="font-size:10px">Telp: ${esc(r.store_phone)}</div>` : ''}
  <div class="divider"></div>
  <div class="meta"><span>No: ${esc(r.receipt_id)}</span></div>
  <div class="meta"><span>Tanggal: ${esc(r.tanggal_jam)}</span></div>
  <div class="meta"><span>Kasir: ${esc(r.cashier)}</span></div>
  ${r.guide ? `<div class="meta"><span>Guide: ${esc(r.guide)}</span></div>` : ''}
  <div class="divider"></div>
  ${items}
  <div class="divider"></div>
  ${r.tx_discount_amount > 0 ? `
    <div class="line"><span>Subtotal</span><span>Rp ${fmt(r.subtotal)}</span></div>
    <div class="line"><span>Diskon</span><span>-Rp ${fmt(r.tx_discount_amount)}</span></div>
  ` : ''}
  <div class="total-line"><span>TOTAL</span><span>Rp ${fmt(r.total)}</span></div>
  <div class="line"><span>Bayar</span><span>Rp ${fmt(r.bayar)}</span></div>
  <div class="line"><span>Kembali</span><span>Rp ${fmt(r.kembalian)}</span></div>
  <div class="line"><span>Metode</span><span>${esc(r.payment_method)}</span></div>
  <div class="divider"></div>
  ${r.footer ? `<div class="footer">${esc(r.footer)}</div>` : ''}
  <div class="spacer"></div>
  </body></html>`;
}

// ── ESC/POS cut command ───────────────────────────────────────────────────────

async function sendCutCommand(printerName) {
  return new Promise((resolve) => {
    const ps1 = path.join(getHelpersPath(), 'rawprint.ps1');
    if (!fs.existsSync(ps1)) { resolve(); return; }
    execFile('powershell.exe', [
      '-NoProfile', '-NonInteractive', '-ExecutionPolicy', 'Bypass',
      '-File', ps1, '-PrinterName', printerName,
    ], { timeout: 8000, windowsHide: true }, () => resolve());
  });
}

// IPC: test print (dari settings)
ipcMain.handle('settings:getPrinters', async () => {
  if (!mainWindow || mainWindow.isDestroyed()) return [];
  try { return (await mainWindow.webContents.getPrintersAsync()).map((p) => ({ name: p.name, isDefault: p.isDefault })); }
  catch (_) { return []; }
});

ipcMain.handle('settings:testPrint', async (_, printerName) => {
  const testHtml = path.join(getAssetsPath(), 'test-receipt.html');
  if (!fs.existsSync(testHtml)) return { ok: false, message: 'File test tidak ditemukan.' };
  return new Promise((resolve) => {
    const w = new BrowserWindow({ show: false, webPreferences: { nodeIntegration: false, contextIsolation: true } });
    w.loadFile(testHtml);
    w.webContents.once('did-finish-load', () => {
      setTimeout(() => {
        w.webContents.print({ silent: true, deviceName: printerName || undefined }, async (ok, reason) => {
          try { w.close(); } catch (_) {}
          if (ok && printerName) await sendCutCommand(printerName);
          resolve({ ok, message: ok ? 'Test print berhasil.' : ('Gagal: ' + reason) });
        });
      }, 500);
    });
  });
});

// ── Settings window ───────────────────────────────────────────────────────────

function openSettingsWindow() {
  if (settingsWindow && !settingsWindow.isDestroyed()) { settingsWindow.focus(); return; }
  settingsWindow = new BrowserWindow({
    width: 500, height: 440,
    parent: mainWindow, modal: false,
    title: 'Pengaturan Printer — Adena POS',
    resizable: false,
    webPreferences: {
      preload: path.join(__dirname, 'settings-pre.js'),
      nodeIntegration: false, contextIsolation: true,
    },
  });
  settingsWindow.loadFile(path.join(__dirname, 'settings.html'));
  settingsWindow.setMenu(null);
  settingsWindow.on('closed', () => { settingsWindow = null; });
}

// ── Sync ──────────────────────────────────────────────────────────────────────

function startAutoSync() {
  if (syncTimer) clearInterval(syncTimer);
  syncTimer = setInterval(async () => {
    const result = await syncMod.runSync(false).catch(() => ({ ok: false }));
    if (mainWindow && !mainWindow.isDestroyed() && result && !result.ok && result.type === 'auth_error') {
      mainWindow.webContents.send('sync:warning', 'API Token tidak valid. Silakan cek setting API.');
      reloadWithPreload('login-pre.js', 'login.html');
    }
  }, 10 * 60 * 1000); // 10 menit
}

async function runStartupSync() {
  const result = await syncMod.runSync(true);
  if (mainWindow && !mainWindow.isDestroyed()) {
    mainWindow.webContents.send('sync:done', result);
    if (!result.ok) {
      mainWindow.webContents.send('sync:warning', 'Sync gagal, memakai data lokal.');
    }
  }
}

function resetLocalSession() {
  dbMod.setSetting('current_user', '');
  dbMod.setSetting('device_token', '');
  dbMod.setSetting('current_user_info', '');
  dbMod.setSetting('api_base_url', '');
  closeAuxWindows();
  if (mainWindow && !mainWindow.isDestroyed()) {
    reloadWithPreload('login-pre.js', 'login.html');
  }
}

async function triggerManualSync() {
  if (mainWindow) mainWindow.webContents.send('sync:start');
  const result = await syncMod.runSync(true);
  if (mainWindow) mainWindow.webContents.send('sync:done', result);
}

ipcMain.handle('sync:manual', () => syncMod.runSync(true));

// ── App lifecycle ─────────────────────────────────────────────────────────────

app.whenReady().then(async () => {
  dbMod.db();
  console.info(`[startup] Active DB Path: ${dbMod.getDbPath()}`);
  Menu.setApplicationMenu(buildMenu());
  createMainWindow();
  startAutoSync();
});

app.on('before-quit', async () => {
  // Sync saat tutup
  try { await syncMod.runSync(false); } catch (_) {}
  if (syncTimer) clearInterval(syncTimer);
});

app.on('window-all-closed', () => {
  if (process.platform !== 'darwin') app.quit();
});
