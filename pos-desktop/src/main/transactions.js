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

function normalizePayments(payment = {}, items = []) {
  const total = items.reduce((sum, item) => sum + Number(item.qty || 0) * Number(item.price_each || 0), 0);
  const raw = Array.isArray(payment.payments) && payment.payments.length ? payment.payments : [{
    method: payment.method,
    bank_id: payment.bank_id || null,
    bank_name: payment.bank_name || null,
    amount: total,
    fee_percent: payment.fee_percent || 0,
    fee_amount: payment.fee_amount || 0,
    charged_amount: payment.charged_amount || total,
    cash_received: payment.cash_received ?? null,
    cash_change: payment.cash_change ?? null
  }];

  return raw.map((p) => ({
    method: String(p.method || '').trim(),
    bank_id: p.bank_id || null,
    bank_name: p.bank_name || null,
    amount: Number(p.amount || 0),
    fee_percent: Number(p.fee_percent || 0),
    fee_amount: Number(p.fee_amount || 0),
    charged_amount: Number(p.charged_amount || p.amount || 0),
    cash_received: p.cash_received === undefined || p.cash_received === null ? null : Number(p.cash_received || 0),
    cash_change: p.cash_change === undefined || p.cash_change === null ? null : Number(p.cash_change || 0)
  })).filter((p) => p.method && p.amount > 0);
}

function paymentSummaryLabel(payments = []) {
  if (!payments.length) return '';
  return payments.map((p) => {
    const label = [p.method, p.bank_name].filter(Boolean).join(' ');
    return `${label}: ${p.amount}`;
  }).join(' | ');
}

function saveSaleLocally({ user, guide, payment = {}, shift, items, customer = {} }) {
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

  const payments = normalizePayments(payment, items);
  if (!payments.length) return { ok: false, message: 'Pembayaran belum diisi.' };

  const total = items.reduce((sum, item) => sum + Number(item.qty || 0) * Number(item.price_each || 0), 0);
  const paidAmount = payments.reduce((sum, p) => sum + Number(p.amount || 0), 0);
  if (paidAmount + 0.001 < total) return { ok: false, message: 'Total alokasi pembayaran kurang dari total belanja.' };

  const primaryPayment = payments.length === 1 ? payments[0] : { method: 'multi', bank_name: paymentSummaryLabel(payments) };
  const cashPayment = payments.find((p) => ['cash', 'tunai'].includes(String(p.method || '').toLowerCase()) || String(p.method || '').toLowerCase().includes('cash') || String(p.method || '').toLowerCase().includes('tunai'));

  const insert = db.prepare(`INSERT INTO sales
    (transaction_code, transaction_group_uuid, offline_uuid, product_id, qty, price_each, total,
     payment_method, payment_bank, guide_id, guide_name, created_by, branch_id, shift_id, sold_at,
     local_device_id, local_transaction_id, sync_status, cash_received, cash_change, customer_name, customer_phone, payment_summary)
    VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?)`);

  const insertPayment = db.prepare(`INSERT INTO sale_payments
    (local_transaction_id, transaction_group_uuid, payment_method, payment_bank, payment_bank_id, amount, fee_percent, fee_amount, charged_amount, cash_received, cash_change, created_at, sync_status)
    VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?)`);

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
        primaryPayment.method,
        primaryPayment.bank_name || null,
        guide?.id || null,
        guide?.name || null,
        user.id,
        activeShift.branch_id || 1,
        activeShift.id,
        nowLocal,
        store.get('deviceId'),
        localTransactionId,
        'pending',
        cashPayment?.cash_received ?? null,
        cashPayment?.cash_change ?? null,
        String(customer?.name || '').trim() || null,
        String(customer?.phone || '').trim() || null,
        paymentSummaryLabel(payments) || null
      );
    }

    for (const p of payments) {
      insertPayment.run(
        localTransactionId,
        transactionGroupUuid,
        p.method,
        p.bank_name || null,
        p.bank_id || null,
        p.amount,
        p.fee_percent || 0,
        p.fee_amount || 0,
        p.charged_amount || p.amount,
        p.cash_received ?? null,
        p.cash_change ?? null,
        nowLocal,
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
