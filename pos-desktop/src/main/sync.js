const { initDb } = require('./db');
const { pullMaster, pushTransactions } = require('./api');
const { store } = require('./config');
const { localDateTimeString } = require('./time');

function saveMasterData(data) {
  const db = initDb();
  const tx = db.transaction(() => {
    const wipe = (table) => db.prepare(`DELETE FROM ${table}`).run();
    ['products', 'product_categories', 'payment_methods', 'qris_banks', 'guides', 'users', 'orders', 'order_items'].forEach(wipe);

    const insertProduct = db.prepare(`INSERT INTO products (id, name, price, category, image_path, is_favorite, is_best_seller, show_on_pos, track_stock, updated_at)
      VALUES (@id, @name, @price, @category, @image_path, @is_favorite, @is_best_seller, @show_on_pos, @track_stock, @updated_at)`);
    (data.products || []).forEach((r) => insertProduct.run(r));

    const insertCategory = db.prepare('INSERT INTO product_categories (id,name,image_path) VALUES (?,?,?)');
    (data.categories || []).forEach((r) => insertCategory.run(r.id, r.name, r.image_path || null));

    const insertPm = db.prepare('INSERT INTO payment_methods (code,name,is_active,sort_order) VALUES (?,?,?,?)');
    (data.payment_methods || []).forEach((r) => insertPm.run(r.code, r.name, r.is_active ?? 1, r.sort_order ?? 0));

    const insertBank = db.prepare('INSERT INTO qris_banks (id,name,sort_order,is_active) VALUES (?,?,?,?)');
    (data.qris_banks || []).forEach((r) => insertBank.run(r.id, r.name, r.sort_order ?? 0, r.is_active ?? 1));

    const insertGuide = db.prepare('INSERT INTO guides (id,name,is_active) VALUES (?,?,?)');
    (data.guides || []).forEach((r) => insertGuide.run(r.id, r.name, r.is_active ?? 1));

    const insertUser = db.prepare('INSERT INTO users (id,username,name,role) VALUES (?,?,?,?)');
    (data.cashiers || []).forEach((r) => insertUser.run(r.id, r.username, r.name, r.role));

    const insertOrder = db.prepare('INSERT INTO orders (id,order_code,customer_id,status,created_at,completed_at,customer_name,customer_contact,customer_address,customer_note,total_amount) VALUES (?,?,?,?,?,?,?,?,?,?,?)');
    (data.pending_orders || []).forEach((r) => insertOrder.run(r.id, r.order_code, r.customer_id, r.status, r.created_at, r.completed_at || null, r.customer_name || '', r.contact || '', r.customer_address || '', r.customer_note || '', r.total || 0));

    const insertOi = db.prepare('INSERT INTO order_items (order_id,product_id,qty,price_each,subtotal,product_name) VALUES (?,?,?,?,?,?)');
    (data.pending_order_items || []).forEach((r) => insertOi.run(r.order_id, r.product_id, r.qty, r.price_each || 0, r.subtotal || 0, r.product_name || ''));

    const upsertSetting = db.prepare('INSERT INTO settings (key,value) VALUES (?,?) ON CONFLICT(key) DO UPDATE SET value = excluded.value');
    Object.entries(data.settings || {}).forEach(([k, v]) => upsertSetting.run(k, String(v ?? '')));
  });
  tx();
}

async function syncMaster() {
  const since = store.get('lastSyncAt');
  const resp = await pullMaster(since);
  saveMasterData(resp.data || {});
  store.set('lastSyncAt', localDateTimeString());
  return resp;
}

function buildPendingPayload() {
  const db = initDb();
  const sales = db.prepare("SELECT * FROM sales WHERE sync_status IN ('pending','failed') ORDER BY id ASC").all();
  const grouped = new Map();

  for (const row of sales) {
    if (!grouped.has(row.local_transaction_id)) {
      grouped.set(row.local_transaction_id, {
        offline_uuid: row.offline_uuid,
        local_device_id: row.local_device_id,
        local_transaction_id: row.local_transaction_id,
        payment_method: row.payment_method,
        payment_bank: row.payment_bank,
        guide_id: row.guide_id,
        guide_name: row.guide_name,
        user_id: row.created_by,
        sold_at: row.sold_at,
        source: 'desktop',
        items: []
      });
    }
    grouped.get(row.local_transaction_id).items.push({
      product_id: row.product_id,
      qty: row.qty,
      price_each: row.price_each,
      total: row.total
    });
  }

  return { shifts: [], cash_movements: [], transactions: Array.from(grouped.values()) };
}

async function syncPendingTransactions() {
  const db = initDb();
  const payload = buildPendingPayload();
  if (!payload.transactions.length) return { ok: true, message: 'No pending' };

  const resp = await pushTransactions(payload);
  const tx = db.transaction(() => {
    for (const [uuid, result] of Object.entries(resp.results?.transactions || {})) {
      const isSuccess = result.status === 'inserted' || result.status === 'exists';
      db.prepare('UPDATE sales SET sync_status = ?, sync_error = ?, last_synced_at = CURRENT_TIMESTAMP WHERE offline_uuid = ?')
        .run(isSuccess ? 'synced' : 'failed', isSuccess ? null : (result.message || 'sync failed'), uuid);
      db.prepare('INSERT INTO pos_sync_queue_log (entity_type, offline_uuid, payload_json, processed_at, status, message) VALUES (?,?,?,?,?,?)')
        .run('sale', uuid, JSON.stringify(result), localDateTimeString(), isSuccess ? 'success' : 'failed', result.message || null);
    }
  });
  tx();
  return resp;
}

module.exports = { syncMaster, syncPendingTransactions };
