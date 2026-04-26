const { v4: uuidv4 } = require('uuid');
const { initDb } = require('./db');
const { store } = require('./config');

function saveSaleLocally({ user, guide, payment, items }) {
  const db = initDb();
  const localTransactionId = uuidv4();
  const offlineUuid = uuidv4();
  const transactionCode = `TRX-${Date.now()}`;
  const now = new Date().toISOString();

  const insert = db.prepare(`INSERT INTO sales
    (transaction_code, transaction_group_uuid, offline_uuid, product_id, qty, price_each, total,
     payment_method, payment_bank, guide_id, guide_name, created_by, sold_at,
     local_device_id, local_transaction_id, sync_status)
    VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?)`);

  const tx = db.transaction(() => {
    for (const item of items) {
      insert.run(
        transactionCode,
        localTransactionId,
        offlineUuid,
        item.product_id,
        item.qty,
        item.price_each,
        item.qty * item.price_each,
        payment.method,
        payment.bank_name || null,
        guide?.id || null,
        guide?.name || null,
        user.id,
        now,
        store.get('deviceId'),
        localTransactionId,
        'pending'
      );
    }
  });

  tx();
  return { localTransactionId, offlineUuid, transactionCode, soldAt: now };
}

module.exports = { saveSaleLocally };
