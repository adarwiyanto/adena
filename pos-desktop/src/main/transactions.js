const { v4: uuidv4 } = require('uuid');
const { initDb } = require('./db');
const { store } = require('./config');
const { localDateTimeString } = require('./time');

function ensureDeviceCode() {
  const raw = String(store.get('deviceCode') || '').trim().toUpperCase();
  if (!/^[A-Z0-9]+$/.test(raw)) {
    return { ok: false, message: 'Kode POS/Device belum disetting di Kasir Desktop.' };
  }
  return { ok: true, value: raw };
}

function formatTransactionCode(deviceCode, localTimestamp = localDateTimeString()) {
  const compact = String(localTimestamp || localDateTimeString()).replace(/[-: ]/g, '');
  const date = compact.slice(0, 8);
  const time = compact.slice(8, 14);
  return `TRX-${date}-${time}-post${deviceCode}`;
}

function saveSaleLocally({ user, guide, payment, shift, items }) {
  const device = ensureDeviceCode();
  if (!device.ok) {
    return { ok: false, message: device.message };
  }

  const db = initDb();
  const transactionUuid = uuidv4();
  const localTransactionId = transactionUuid;
  const transactionGroupUuid = transactionUuid;
  const nowLocal = localDateTimeString();
  const transactionCode = formatTransactionCode(device.value, nowLocal);
  const activeShift = shift || db.prepare("SELECT * FROM pos_shifts WHERE status='open' ORDER BY opened_at DESC, id DESC LIMIT 1").get();
  if (!activeShift) return { ok: false, message: 'Shift belum aktif. Buka shift terlebih dahulu.' };

  const insert = db.prepare(`INSERT INTO sales
    (transaction_code, transaction_group_uuid, offline_uuid, product_id, qty, price_each, total,
     payment_method, payment_bank, guide_id, guide_name, created_by, branch_id, shift_id, sold_at,
     local_device_id, local_transaction_id, sync_status, cash_received, cash_change)
    VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?)`);

  const tx = db.transaction(() => {
    for (const item of items) {
      const itemOfflineUuid = uuidv4();
      insert.run(
        transactionCode,
        transactionGroupUuid,
        itemOfflineUuid,
        item.product_id,
        item.qty,
        item.price_each,
        item.qty * item.price_each,
        payment.method,
        payment.bank_name || null,
        guide?.id || null,
        guide?.name || null,
        user.id,
        activeShift.branch_id || 1,
        activeShift.id,
        nowLocal,
        store.get('deviceId'),
        localTransactionId,
        'pending',
        payment.cash_received ?? null,
        payment.cash_change ?? null
      );
    }
  });

  tx();
  return {
    ok: true,
    localTransactionId,
    transactionGroupUuid,
    transactionCode,
    soldAt: nowLocal
  };
}

module.exports = { saveSaleLocally };
