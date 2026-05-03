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

function money(value) { const n = Number(value); return Number.isFinite(n) ? n : 0; }
function discountValue(base, amount, type) {
  const b = money(base); const a = Math.max(0, money(amount));
  return String(type || 'fixed') === 'percent' ? Math.min(b, b * a / 100) : Math.min(b, a);
}
function normalizeItem(item) {
  const qty = Math.max(1, Math.floor(Number(item.qty || 1)));
  const price = money(item.price_each);
  const lineSubtotal = qty * price;
  const itemDiscount = discountValue(lineSubtotal, item.discount_amount || 0, item.discount_type || 'fixed');
  const lineNetTotal = Math.max(0, lineSubtotal - itemDiscount);
  return {
    product_id: Number(item.product_id),
    name: item.name || item.product_name || 'Produk',
    qty,
    price_each: price,
    discount_amount: money(item.discount_amount || 0),
    discount_type: String(item.discount_type || 'fixed'),
    include_in_sales_report: Number(item.include_in_sales_report ?? 1) ? 1 : 0,
    line_subtotal: lineSubtotal,
    total: lineNetTotal,
    line_net_total: lineNetTotal
  };
}
function transactionTotals(items, txDiscountAmount = 0, txDiscountType = 'fixed') {
  const subtotal = items.reduce((a, i) => a + money(i.line_subtotal), 0);
  const itemDiscount = items.reduce((a, i) => a + discountValue(i.line_subtotal, i.discount_amount, i.discount_type), 0);
  const afterItem = Math.max(0, subtotal - itemDiscount);
  const txDiscount = discountValue(afterItem, txDiscountAmount, txDiscountType);
  const total = Math.max(0, afterItem - txDiscount);
  return { subtotal, itemDiscount, txDiscount, total };
}

function saveSaleLocally({ user, guide, payment, shift, items, txDiscountAmount = 0, txDiscountType = 'fixed', localPendingId = null }) {
  const device = ensureDeviceCode();
  if (!device.ok) return { ok: false, message: device.message };
  const normalized = (items || []).map(normalizeItem).filter((i) => i.product_id > 0 && i.qty > 0);
  if (!normalized.length) return { ok: false, message: 'Item transaksi kosong.' };

  const db = initDb();
  const transactionUuid = uuidv4();
  const localTransactionId = transactionUuid;
  const transactionGroupUuid = transactionUuid;
  const transactionCode = formatTransactionCode(device.value);
  const nowLocal = localDateTimeString();
  const activeShift = shift || db.prepare("SELECT * FROM pos_shifts WHERE status='open' ORDER BY opened_at DESC, id DESC LIMIT 1").get();
  if (!activeShift) return { ok: false, message: 'Shift belum aktif. Buka shift terlebih dahulu.' };
  const totals = transactionTotals(normalized, txDiscountAmount, txDiscountType);

  const insert = db.prepare(`INSERT INTO sales
    (transaction_code, transaction_group_uuid, offline_uuid, product_id, qty, price_each, total,
     discount_amount, discount_type, tx_discount_amount, tx_discount_type, include_in_sales_report, line_subtotal, line_net_total, local_pending_id,
     payment_method, payment_bank, guide_id, guide_name, created_by, branch_id, shift_id, sold_at,
     local_device_id, local_transaction_id, sync_status, cash_received, cash_change)
    VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?)`);

  const tx = db.transaction(() => {
    for (const item of normalized) {
      insert.run(
        transactionCode, transactionGroupUuid, uuidv4(), item.product_id, item.qty, item.price_each, item.total,
        item.discount_amount, item.discount_type, money(txDiscountAmount), String(txDiscountType || 'fixed'), item.include_in_sales_report, item.line_subtotal, item.line_net_total, localPendingId,
        payment.method, payment.bank_name || null, guide?.id || null, guide?.name || null, user.id,
        activeShift.branch_id || 1, activeShift.id, nowLocal, store.get('deviceId'), localTransactionId, 'pending',
        payment.cash_received ?? null, payment.cash_change ?? null
      );
    }
    if (localPendingId) db.prepare("UPDATE pending_orders_local SET status='paid', updated_at=? WHERE local_pending_id=?").run(nowLocal, localPendingId);
  });
  tx();
  return { ok: true, localTransactionId, transactionGroupUuid, transactionCode, soldAt: nowLocal, subtotal: totals.subtotal, itemDiscount: totals.itemDiscount, txDiscount: totals.txDiscount, total: totals.total };
}

function savePendingOrder({ user, items, txDiscountAmount = 0, txDiscountType = 'fixed', customerName = '', note = '', localPendingId = null }) {
  const db = initDb();
  const now = localDateTimeString();
  const id = localPendingId || uuidv4();
  const code = `PEND-${now.replace(/[-: ]/g, '').slice(0, 14)}-${String(id).slice(0, 4).toUpperCase()}`;
  const normalized = (items || []).map(normalizeItem).filter((i) => i.product_id > 0 && i.qty > 0);
  if (!normalized.length) return { ok: false, message: 'Keranjang kosong.' };
  const totals = transactionTotals(normalized, txDiscountAmount, txDiscountType);
  const payload = { items: normalized, txDiscountAmount: money(txDiscountAmount), txDiscountType: String(txDiscountType || 'fixed'), customerName, note };
  const tx = db.transaction(() => {
    db.prepare(`INSERT INTO pending_orders_local (local_pending_id,pending_code,customer_name,note,subtotal,discount_amount,discount_type,total,status,payload_json,created_at,updated_at)
      VALUES (?,?,?,?,?,?,?,?,?,?,?,?)
      ON CONFLICT(local_pending_id) DO UPDATE SET customer_name=excluded.customer_name,note=excluded.note,subtotal=excluded.subtotal,discount_amount=excluded.discount_amount,discount_type=excluded.discount_type,total=excluded.total,status='pending',payload_json=excluded.payload_json,updated_at=excluded.updated_at`)
      .run(id, code, customerName, note, totals.subtotal, money(txDiscountAmount), String(txDiscountType || 'fixed'), totals.total, 'pending', JSON.stringify(payload), now, now);
    db.prepare('DELETE FROM pending_order_items_local WHERE local_pending_id=?').run(id);
    const ins = db.prepare(`INSERT INTO pending_order_items_local (local_pending_id,product_id,product_name,qty,price_each,discount_amount,discount_type,total,include_in_sales_report) VALUES (?,?,?,?,?,?,?,?,?)`);
    normalized.forEach((i) => ins.run(id, i.product_id, i.name, i.qty, i.price_each, i.discount_amount, i.discount_type, i.total, i.include_in_sales_report));
  });
  tx();
  return { ok: true, localPendingId: id, pendingCode: code, total: totals.total };
}

function listPendingOrders() {
  const db = initDb();
  return { ok: true, rows: db.prepare("SELECT * FROM pending_orders_local WHERE status='pending' ORDER BY updated_at DESC, id DESC").all() };
}
function deletePendingOrder(localPendingId) {
  const db = initDb();
  db.prepare("UPDATE pending_orders_local SET status='deleted', updated_at=? WHERE local_pending_id=?").run(localDateTimeString(), localPendingId);
  return { ok: true };
}

module.exports = { saveSaleLocally, savePendingOrder, listPendingOrders, deletePendingOrder, transactionTotals };
