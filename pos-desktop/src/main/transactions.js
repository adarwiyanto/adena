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

function formatTransactionCode(deviceCode) {
  const now = new Date();
  const pad = (n) => String(n).padStart(2, '0');
  const date = `${now.getFullYear()}${pad(now.getMonth() + 1)}${pad(now.getDate())}`;
  const time = `${pad(now.getHours())}${pad(now.getMinutes())}${pad(now.getSeconds())}`;
  return `TRX-${date}-${time}-post${deviceCode}`;
}

function numberOrZero(value) {
  const n = Number(value);
  return Number.isFinite(n) ? n : 0;
}

function normalizeDiscountType(value) {
  return value === 'percent' ? 'percent' : 'fixed';
}

function itemGross(item) {
  return numberOrZero(item.qty) * numberOrZero(item.price_each);
}

function itemDiscountValue(item) {
  const gross = itemGross(item);
  const amount = Math.max(0, numberOrZero(item.discount_amount));
  const type = normalizeDiscountType(item.discount_type);
  if (!amount || !gross) return 0;
  if (type === 'percent') return Math.min(gross, Math.round(gross * Math.min(100, amount) / 100));
  return Math.min(gross, amount);
}

function itemNet(item) {
  return Math.max(0, itemGross(item) - itemDiscountValue(item));
}

function saveSaleLocally({ user, guide, payment, shift, items, txDiscount }) {
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
  const activeShift = shift || db.prepare("SELECT * FROM pos_shifts WHERE status='open' ORDER BY opened_at DESC, id DESC LIMIT 1").get();
  if (!activeShift) return { ok: false, message: 'Shift belum aktif. Buka shift terlebih dahulu.' };

  const txDiscountAmount = Math.max(0, numberOrZero(txDiscount?.amount));
  const txDiscountType = normalizeDiscountType(txDiscount?.type);

  const insert = db.prepare(`INSERT INTO sales
    (transaction_code, transaction_group_uuid, offline_uuid, product_id, qty, price_each, total,
     discount_amount, discount_type, tx_discount_amount, tx_discount_type,
     payment_method, payment_bank, guide_id, guide_name, created_by, branch_id, shift_id, sold_at,
     local_device_id, local_transaction_id, sync_status, cash_received, cash_change)
    VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?)`);

  const tx = db.transaction(() => {
    for (const item of items) {
      const itemOfflineUuid = uuidv4();
      const discountAmount = Math.max(0, numberOrZero(item.discount_amount));
      const discountType = normalizeDiscountType(item.discount_type);
      insert.run(
        transactionCode,
        transactionGroupUuid,
        itemOfflineUuid,
        item.product_id,
        item.qty,
        item.price_each,
        itemNet({ ...item, discount_amount: discountAmount, discount_type: discountType }),
        discountAmount,
        discountType,
        txDiscountAmount,
        txDiscountType,
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
