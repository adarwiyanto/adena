'use strict';
const { ipcMain }  = require('electron');
const bcrypt       = require('bcryptjs');
const { v4: uuid } = require('./uuid-shim');
const db_module    = require('./db');
const sync_module  = require('./sync');
const {
  getSetting, setSetting, getServerSetting,
  saveLocalUser, getLocalUser, setLocalPasswordHash,
  getActiveShift, openShift, closeShift,
  addCashMovement, getShiftMovements,
  saveTransaction, addCustomerLoyaltyPoints,
  db,
} = db_module;

// Pending checkout dari POS → Payment (disimpan di memori main process)
let _pendingCheckout = null;

// ── Auth ──────────────────────────────────────────────────────────────────────

ipcMain.handle('auth:login', async (_, { username, password }) => {
  username = (username || '').trim().toLowerCase();
  if (!username || !password) return { ok: false, message: 'Username dan password wajib diisi.' };

  // Coba online login dulu
  try {
    const deviceName = require('os').hostname() + '-Adena-POS';
    const res = await sync_module.loginOnline(username, password, deviceName);
    // Simpan token + user ke lokal
    setSetting('device_token', res.token);
    setSetting('current_user', JSON.stringify(res.user));
    saveLocalUser(res.user, res.token);
    // Simpan hash password untuk login offline
    const hash = bcrypt.hashSync(password, 10);
    setLocalPasswordHash(res.user.id, hash);
    // Full sync setelah login pertama online
    await sync_module.runSync(true);
    return { ok: true, user: res.user, online: true };
  } catch (_) {}

  // Offline login: verifikasi password lokal
  const localUser = getLocalUser(username);
  if (!localUser || !localUser.password_hash) {
    return { ok: false, message: 'Tidak dapat terhubung ke server. Login offline membutuhkan login online sebelumnya.' };
  }
  if (!bcrypt.compareSync(password, localUser.password_hash)) {
    return { ok: false, message: 'Username atau password salah.' };
  }

  setSetting('current_user', JSON.stringify({
    id: localUser.id, username: localUser.username,
    name: localUser.name, role: localUser.role,
  }));
  return {
    ok: true,
    user: { id: localUser.id, username: localUser.username, name: localUser.name, role: localUser.role },
    online: false,
  };
});

ipcMain.handle('auth:logout', () => {
  setSetting('current_user', '');
  setSetting('device_token', '');
  return { ok: true };
});

ipcMain.handle('auth:current', () => {
  const raw = getSetting('current_user', '');
  if (!raw) return null;
  try { return JSON.parse(raw); } catch (_) { return null; }
});

// ── Sync ──────────────────────────────────────────────────────────────────────

ipcMain.handle('sync:run', async (_, { full = false } = {}) => {
  return sync_module.runSync(full);
});

ipcMain.handle('sync:status', () => {
  return {
    last_sync_at: getSetting('last_sync_at', null),
    token: !!getSetting('device_token', ''),
  };
});

// ── Data (products, customers, etc.) ─────────────────────────────────────────

ipcMain.handle('data:products', () => {
  return db().prepare("SELECT * FROM products WHERE show_on_pos = 1 ORDER BY is_favorite DESC, name").all();
});

ipcMain.handle('data:categories', () => {
  return db().prepare("SELECT * FROM product_categories ORDER BY name").all();
});

ipcMain.handle('data:customers', () => {
  return db().prepare("SELECT id, name, phone, email, loyalty_points, loyalty_remainder FROM customers ORDER BY name").all();
});

ipcMain.handle('data:payment-methods', () => {
  const rows = db().prepare("SELECT * FROM payment_methods WHERE is_active = 1 ORDER BY sort_order, code").all();
  // Fallback jika belum ada
  if (!rows.length) return [
    { code: 'cash', name: 'Tunai' },
    { code: 'qris', name: 'QRIS' },
    { code: 'edc',  name: 'EDC' },
    { code: 'transfer', name: 'Transfer Bank' },
    { code: 'credit_card', name: 'Kartu Kredit' },
  ];
  return rows;
});

ipcMain.handle('data:banks', () => {
  return db().prepare("SELECT * FROM qris_banks WHERE is_active = 1 ORDER BY sort_order, name").all();
});

ipcMain.handle('data:guides', () => {
  return db().prepare("SELECT * FROM guides WHERE is_active = 1 ORDER BY name").all();
});

ipcMain.handle('data:loyalty-rewards', () => {
  return db().prepare("SELECT * FROM loyalty_rewards").all();
});

ipcMain.handle('data:server-settings', () => {
  const keys = ['store_name','store_subtitle','store_address','store_phone',
                 'loyalty_point_value','loyalty_remainder_mode','pos_default_opening_cash'];
  const result = {};
  for (const k of keys) result[k] = getServerSetting(k, '');
  return result;
});

// ── Shift ─────────────────────────────────────────────────────────────────────

ipcMain.handle('shift:active', () => getActiveShift());

ipcMain.handle('shift:open', (_, { opening_cash_actual }) => {
  if (getActiveShift()) return { ok: false, message: 'Ada shift yang masih terbuka.' };
  const currentUser = JSON.parse(getSetting('current_user', 'null') || 'null');
  const shift = openShift({
    offline_uuid: uuid(),
    opening_cash_actual: opening_cash_actual || 0,
    opened_by: currentUser?.id || 0,
    opened_at: new Date().toISOString().replace('T', ' ').substring(0, 19),
  });
  return { ok: true, shift };
});

ipcMain.handle('shift:close', (_, { counted_cash_total, notes }) => {
  const shift = getActiveShift();
  if (!shift) return { ok: false, message: 'Tidak ada shift aktif.' };
  closeShift({
    shiftId: shift.id,
    counted_cash_total: counted_cash_total || 0,
    notes: notes || '',
    closed_at: new Date().toISOString().replace('T', ' ').substring(0, 19),
  });
  return { ok: true };
});

ipcMain.handle('shift:summary', () => {
  const shift = getActiveShift();
  if (!shift) return null;

  const movements = getShiftMovements(shift.id);
  const txRows = db().prepare(
    "SELECT payment_method, total FROM transactions WHERE shift_id = ?"
  ).all(shift.id);

  const cashSales    = txRows.filter(t => t.payment_method === 'cash').reduce((s, t) => s + t.total, 0);
  const nonCashSales = txRows.filter(t => t.payment_method !== 'cash').reduce((s, t) => s + t.total, 0);
  const cashIn  = movements.filter(m => m.movement_type === 'in').reduce((s, m) => s + m.amount, 0);
  const cashOut = movements.filter(m => m.movement_type === 'out').reduce((s, m) => s + m.amount, 0);
  const expectedCash = (shift.opening_cash_actual || 0) + cashSales + cashIn - cashOut;

  return { shift, cashSales, nonCashSales, cashIn, cashOut, expectedCash, movements };
});

// ── Cash movements ────────────────────────────────────────────────────────────

ipcMain.handle('cash:add', (_, { movement_type, amount, reason, notes }) => {
  const shift = getActiveShift();
  if (!shift) return { ok: false, message: 'Tidak ada shift aktif.' };
  const currentUser = JSON.parse(getSetting('current_user', 'null') || 'null');
  addCashMovement({
    offline_uuid: uuid(),
    shift_id: shift.id,
    shift_offline_uuid: shift.offline_uuid,
    movement_type,
    amount,
    reason: reason || '',
    notes: notes || '',
    created_at: new Date().toISOString().replace('T', ' ').substring(0, 19),
  });
  return { ok: true };
});

// ── Checkout (POS → Payment → Confirm) ───────────────────────────────────────

ipcMain.handle('checkout:set-pending', (_, cartData) => {
  _pendingCheckout = cartData;
  return { ok: true };
});

ipcMain.handle('checkout:get-pending', () => _pendingCheckout);

ipcMain.handle('checkout:confirm', async (_, paymentData) => {
  const cart = _pendingCheckout;
  if (!cart || !cart.items?.length) return { ok: false, message: 'Data keranjang kosong.' };

  const shift = getActiveShift();
  if (!shift) return { ok: false, message: 'Tidak ada shift aktif.' };

  const currentUser = JSON.parse(getSetting('current_user', 'null') || 'null');
  const loyaltyVal  = parseFloat(getServerSetting('loyalty_point_value', '0')) || 0;
  const now = new Date().toISOString().replace('T', ' ').substring(0, 19);
  const txUuid = uuid();

  const txData = {
    offline_uuid: txUuid,
    transaction_group_uuid: uuid(),
    shift_id: shift.id,
    shift_offline_uuid: shift.offline_uuid,
    customer_id: paymentData.customer_id || null,
    guide_name: paymentData.guide_name || null,
    payment_method: paymentData.payment_method || 'cash',
    payment_bank: paymentData.payment_bank || null,
    items_json: JSON.stringify(cart.items),
    subtotal: cart.subtotal || 0,
    tx_discount_amount: cart.tx_discount_amount || 0,
    tx_discount_type: cart.tx_discount_type || 'fixed',
    total: cart.total || 0,
    paid_amount: paymentData.paid_amount || cart.total,
    change_amount: paymentData.change_amount || 0,
    loyalty_points_earned: 0,
    sold_at: now,
    created_by: currentUser?.id || 0,
  };

  // Hitung loyalty points
  if (paymentData.customer_id && loyaltyVal > 0) {
    txData.loyalty_points_earned = Math.floor(cart.total / loyaltyVal);
    if (txData.loyalty_points_earned > 0) {
      addCustomerLoyaltyPoints(paymentData.customer_id, txData.loyalty_points_earned);
    }
  }

  saveTransaction(txData);
  _pendingCheckout = null;

  // Build receipt payload
  const receipt = buildReceiptPayload(cart, paymentData, currentUser, txUuid);

  return { ok: true, receipt };
});

function buildReceiptPayload(cart, payment, user, txId) {
  const storeName = getServerSetting('store_name', 'Adena POS');
  const now = new Date();
  const pad = (n) => String(n).padStart(2, '0');
  const timeStr = `${pad(now.getDate())}/${pad(now.getMonth()+1)}/${now.getFullYear()} ${pad(now.getHours())}:${pad(now.getMinutes())}`;

  return {
    receipt_id: 'TRX-' + txId.substring(0, 8).toUpperCase(),
    tanggal_jam: timeStr,
    cashier: user?.name || '-',
    guide: payment.guide_name || null,
    store_name: storeName,
    store_subtitle: getServerSetting('store_subtitle', ''),
    store_address: getServerSetting('store_address', ''),
    store_phone: getServerSetting('store_phone', ''),
    footer: getServerSetting('receipt_footer', ''),
    payment_method: payment.payment_method?.toUpperCase() + (payment.payment_bank ? ' - ' + payment.payment_bank : ''),
    items: cart.items,
    subtotal: cart.subtotal,
    tx_discount_amount: cart.tx_discount_amount || 0,
    tx_discount_type: cart.tx_discount_type || 'fixed',
    total: cart.total,
    bayar: payment.paid_amount || cart.total,
    kembalian: payment.change_amount || 0,
  };
}

// ── Printer settings ──────────────────────────────────────────────────────────

ipcMain.handle('settings:get', () => ({
  printerName: getSetting('printerName', ''),
  sendRawCut:  getSetting('sendRawCut', 'true') !== 'false',
}));

ipcMain.handle('settings:save', (_, data) => {
  setSetting('printerName', data.printerName || '');
  setSetting('sendRawCut',  data.sendRawCut ? 'true' : 'false');
  return { ok: true };
});
