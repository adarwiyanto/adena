const { v4: uuidv4 } = require('uuid');
const { initDb } = require('./db');
const { store } = require('./config');
const { localDateTimeString } = require('./time');

function ensureDeviceCode() {
  const raw = String(store.get('deviceCode') || '').trim().toUpperCase();
  if (!/^[A-Z0-9]+$/.test(raw)) {
    return { ok: false, message: 'Kode POS/Device belum disetting.' };
  }
  return { ok: true, value: raw };
}

function formatTransactionCode(deviceCode) {
  const now = new Date();
  const pad = (n) => String(n).padStart(2, '0');
  const date = `${now.getFullYear()}${pad(now.getMonth() + 1)}${pad(now.getDate())}`;
  const time = `${pad(now.getHours())}${pad(now.getMinutes())}${pad(now.getSeconds())}`;
  return `TRX-${date}-${time}-post${deviceCode}`;
}

function saveSaleLocally({ user, guide, payment, items }) {
  const device = ensureDeviceCode();
  if (!device.ok) {
    return { ok: false, message: device.message };
  }

  const db = initDb();
  const transactionUuid = uuidv4();
  const localTransactionId = transactionUuid;
  const transactionGroupUuid = transactionUuid;
  const transactionCode = formatTransactionCode(device.value);
  const nowLocal = localDateTimeString();

  const insert = db.prepare(`INSERT INTO sales
    (transaction_code, transaction_group_uuid, offline_uuid, product_id, qty, price_each, total,
     payment_method, payment_bank, guide_id, guide_name, created_by, sold_at,
     local_device_id, local_transaction_id, sync_status)
    VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?)`);

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
        nowLocal,
        store.get('deviceId'),
        localTransactionId,
        'pending'
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
