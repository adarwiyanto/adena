const { initDb } = require('./db');
const { pullMaster, pushTransactions } = require('./api');
const { store } = require('./config');
const { localDateTimeString } = require('./time');

function toCategoryId(value) {
  if (value === null || value === undefined || value === '') return null;
  const n = Number(value);
  return Number.isFinite(n) ? n : null;
}

function saveMasterData(data) {
  const db = initDb();
  const tx = db.transaction(() => {
    const wipe = (table) => db.prepare(`DELETE FROM ${table}`).run();
    ['products', 'product_categories', 'payment_methods', 'qris_banks', 'payment_channels', 'guides', 'users', 'orders', 'order_items', 'pos_cash_movements'].forEach(wipe);

    const insertProduct = db.prepare(`INSERT INTO products (id, name, price, category, category_id, image_path, is_favorite, is_best_seller, show_on_pos, track_stock, updated_at)
      VALUES (@id, @name, @price, @category, @category_id, @image_path, @is_favorite, @is_best_seller, @show_on_pos, @track_stock, @updated_at)`);
    (data.products || []).forEach((r) => insertProduct.run({
      ...r,
      category_id: toCategoryId(r.category_id ?? r.category)
    }));

    const insertCategory = db.prepare('INSERT INTO product_categories (id,name,image_path) VALUES (?,?,?)');
    (data.categories || []).forEach((r) => insertCategory.run(r.id, r.name, r.image_path || null));

    const insertPm = db.prepare('INSERT INTO payment_methods (code,name,is_active,sort_order,requires_bank) VALUES (?,?,?,?,?)');
    (data.payment_methods || []).forEach((r) => insertPm.run(r.code, r.name, r.is_active ?? 1, r.sort_order ?? 0, r.requires_bank ?? null));

    const insertBank = db.prepare('INSERT INTO qris_banks (id,name,sort_order,is_active) VALUES (?,?,?,?)');
    (data.qris_banks || []).forEach((r) => insertBank.run(r.id, r.name, r.sort_order ?? 0, r.is_active ?? 1));

    const insertChannel = db.prepare('INSERT INTO payment_channels (id,payment_method,channel_name,bank_name,is_active,sort_order) VALUES (?,?,?,?,?,?)');
    (data.payment_channels || []).forEach((r) => insertChannel.run(r.id, r.payment_method || '', r.channel_name || '', r.bank_name || '', r.is_active ?? 1, r.sort_order ?? 0));

    const insertGuide = db.prepare('INSERT INTO guides (id,name,is_active) VALUES (?,?,?)');
    (data.guides || []).forEach((r) => insertGuide.run(r.id, r.name, r.is_active ?? 1));

    const insertUser = db.prepare('INSERT INTO users (id,username,name,role) VALUES (?,?,?,?)');
    (data.cashiers || []).forEach((r) => insertUser.run(r.id, r.username, r.name, r.role));

    const insertOrder = db.prepare('INSERT INTO orders (id,order_code,customer_id,status,created_at,completed_at,customer_name,customer_contact,customer_address,customer_note,total_amount) VALUES (?,?,?,?,?,?,?,?,?,?,?)');
    (data.pending_orders || []).forEach((r) => insertOrder.run(r.id, r.order_code, r.customer_id, r.status, r.created_at, r.completed_at || null, r.customer_name || '', r.contact || '', r.customer_address || '', r.customer_note || '', r.total || 0));

    const insertOi = db.prepare('INSERT INTO order_items (order_id,product_id,qty,price_each,subtotal,product_name) VALUES (?,?,?,?,?,?)');
    (data.pending_order_items || []).forEach((r) => insertOi.run(r.order_id, r.product_id, r.qty, r.price_each || 0, r.subtotal || 0, r.product_name || ''));

    if (data.active_shift) {
      const s = data.active_shift;
      db.prepare(`INSERT OR REPLACE INTO pos_shifts
        (id, shift_code, branch_id, opened_at, opened_by, opening_cash_default, opening_cash_actual, status, closed_at, closed_by, expected_cash_total, counted_cash_total, cash_difference, notes, offline_open_uuid, offline_close_uuid, sync_status, created_at, updated_at)
        VALUES (@id, @shift_code, @branch_id, @opened_at, @opened_by, @opening_cash_default, @opening_cash_actual, @status, @closed_at, @closed_by, @expected_cash_total, @counted_cash_total, @cash_difference, @notes, @offline_open_uuid, @offline_close_uuid, 'synced', @created_at, @updated_at)`)
        .run(s);
    }

    const insertCashMove = db.prepare('INSERT INTO pos_cash_movements (id,shift_id,movement_type,amount,reason,notes,created_at,offline_uuid,sync_status) VALUES (?,?,?,?,?,?,?,?,?)');
    (data.cash_movements || []).forEach((r) => insertCashMove.run(r.id, r.shift_id, r.movement_type, r.amount, r.reason || '', r.notes || '', r.created_at || localDateTimeString(), r.offline_uuid || null, 'synced'));

    const hasWebSale = db.prepare('SELECT 1 FROM sales WHERE web_sale_id = ? LIMIT 1');
    const hasGroupItem = db.prepare('SELECT 1 FROM sales WHERE transaction_group_uuid = ? AND product_id = ? AND sold_at = ? LIMIT 1');
    const insertImportedSale = db.prepare(`INSERT INTO sales
      (web_sale_id, transaction_code, transaction_group_uuid, product_id, qty, price_each, total, payment_method, payment_bank, guide_id, guide_name, created_by, sold_at, local_device_id, local_transaction_id, sync_status)
      VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?)`);
    (data.sales_history || []).forEach((r, idx) => {
      const groupId = String(r.transaction_group_uuid || r.offline_uuid || r.transaction_code || `web-${r.web_sale_id || idx}`);
      const webSaleId = Number(r.web_sale_id || 0);
      if (webSaleId > 0 && hasWebSale.get(webSaleId)) return;
      if (hasGroupItem.get(groupId, r.product_id, r.sold_at)) return;
      insertImportedSale.run(
        webSaleId || null,
        r.transaction_code || groupId,
        groupId,
        r.product_id,
        r.qty || 0,
        r.price_each || 0,
        r.total || 0,
        r.payment_method || '',
        r.payment_bank || null,
        r.guide_id || null,
        r.guide_name || null,
        r.created_by || null,
        r.sold_at || localDateTimeString(),
        'web',
        `${groupId}-${idx + 1}`,
        'imported_from_web'
      );
    });

    const upsertSetting = db.prepare('INSERT INTO settings (key,value) VALUES (?,?) ON CONFLICT(key) DO UPDATE SET value = excluded.value');
    Object.entries(data.settings || {}).forEach(([k, v]) => upsertSetting.run(k, String(v ?? '')));
  });
  tx();
}

async function syncMaster() {
  try {
    const since = store.get('lastSyncAt');
    const resp = await pullMaster(since);
    if (!resp?.ok) {
      const status = Number(resp?.status || 0);
      if (status >= 500) {
        return { ...resp, message: 'Sync gagal: server error di api/sync/pull.php', endpoint: '/api/sync/pull.php' };
      }
      return { ...resp, endpoint: '/api/sync/pull.php' };
    }
    saveMasterData(resp.data || {});
    store.set('lastSyncAt', localDateTimeString());
    return resp;
  } catch (error) {
    console.error('[sync:master] failed', error);
    return {
      ok: false,
      message: (error?.status || 500) >= 500
        ? 'Sync gagal: server error di api/sync/pull.php'
        : 'Sync gagal',
      status: error?.status || 500,
      endpoint: '/api/sync/pull.php'
    };
  }
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
  try {
    const db = initDb();
    const payload = buildPendingPayload();
    if (!payload.transactions.length) return { ok: true, message: 'No pending' };

    const resp = await pushTransactions(payload);
    if (!resp?.ok) return resp;
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
  } catch (error) {
    console.error('[sync:pending] failed', error);
    return {
      ok: false,
      message: 'Sync gagal',
      status: error?.status || 500
    };
  }
}

module.exports = { syncMaster, syncPendingTransactions };
