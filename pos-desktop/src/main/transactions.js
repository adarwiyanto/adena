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

function saveSaleLocally({ user, guide, payment, shift, items, tx_discount_amount = 0, tx_discount_type = 'fixed' }) {
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

  const branchId = Number(activeShift.branch_id || store.get('branchId') || 1);
  const saleSource = String(store.get('saleSource') || 'branch_pos');
  const unitType = saleSource === 'kitchen_direct' ? 'kitchen' : 'branch';

  const insert = db.prepare(`INSERT INTO sales
    (transaction_code, transaction_group_uuid, offline_uuid, product_id, qty, price_each, total,
     payment_method, payment_bank, guide_id, guide_name, created_by, branch_id, sale_source, unit_type, shift_id, sold_at,
     discount_amount, discount_type, tx_discount_amount, tx_discount_type,
     local_device_id, local_transaction_id, sync_status, cash_received, cash_change)
    VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?)`);
  const insertLedger = db.prepare(`INSERT INTO stock_ledger
    (branch_id, product_id, trans_type, ref_table, ref_id, qty_in, qty_out, unit_cost, note, created_by, created_at)
    VALUES (?,?,?,?,?,?,?,?,?,?,?)`);
  const productTracksStock = db.prepare('SELECT COALESCE(track_stock, 1) AS track_stock FROM products WHERE id = ? LIMIT 1');

  const tx = db.transaction(() => {
    for (const item of items) {
      const itemOfflineUuid = uuidv4();
      const info = insert.run(
        transactionCode,
        transactionGroupUuid,
        itemOfflineUuid,
        item.product_id,
        item.qty,
        item.price_each,
        Number(item.line_total ?? item.total ?? (Number(item.qty || 0) * Number(item.price_each || 0))),
        payment.method,
        payment.bank_name || null,
        guide?.id || null,
        guide?.name || null,
        user.id,
        branchId,
        saleSource,
        unitType,
        activeShift.id,
        nowLocal,
        Number(item.discount_amount || 0),
        String(item.discount_type || 'fixed') === 'percent' ? 'percent' : 'fixed',
        Number(tx_discount_amount || 0),
        String(tx_discount_type || 'fixed') === 'percent' ? 'percent' : 'fixed',
        store.get('deviceId'),
        localTransactionId,
        'pending',
        payment.cash_received ?? null,
        payment.cash_change ?? null
      );
      const saleLocalId = Number(info.lastInsertRowid || 0);
      const p = productTracksStock.get(item.product_id);
      if (saleLocalId > 0 && (!p || Number(p.track_stock ?? 1) === 1)) {
        insertLedger.run(
          branchId,
          item.product_id,
          'pos_sale_local',
          'sales',
          saleLocalId,
          0,
          Number(item.qty || 0),
          null,
          `Penjualan lokal ${transactionCode}`,
          user.id,
          nowLocal
        );
      }
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
