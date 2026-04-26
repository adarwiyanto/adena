const { initDb } = require('./db');
const { pullMaster, pushTransactions } = require('./api');
const { store } = require('./config');
const { localDateTimeString } = require('./time');

const PAYMENT_METHOD_FALLBACK = [
  { code: 'cash', name: 'Cash', is_active: 1, sort_order: 1, requires_bank: 0 },
  { code: 'qris', name: 'QRIS', is_active: 1, sort_order: 2, requires_bank: 1 },
  { code: 'transfer', name: 'Transfer', is_active: 1, sort_order: 3, requires_bank: 1 },
  { code: 'credit_card', name: 'Credit Card', is_active: 1, sort_order: 4, requires_bank: 1 }
];

function toNumeric(value) {
  const n = Number(value);
  return Number.isFinite(n) ? n : null;
}

function normalizeProduct(record = {}) {
  const rawCategoryId = record.category_id;
  const numericCategoryId = toNumeric(rawCategoryId);
  const categoryName = record.category_name
    || record.category
    || (numericCategoryId === null ? (rawCategoryId || null) : null)
    || null;

  return {
    ...record,
    category_id: numericCategoryId,
    category_name: categoryName,
    image_path: record.image_path || record.photo || record.image || record.product_image || record.thumbnail || null
  };
}

function getBanks(data = {}) {
  return Array.isArray(data.banks) ? data.banks : (data.qris_banks || []);
}

function saveMasterData(data, { fullSync = false } = {}) {
  const db = initDb();
  const tx = db.transaction(() => {
    if (fullSync) {
      ['products', 'product_categories', 'payment_methods', 'qris_banks', 'payment_channels', 'guides', 'users', 'orders', 'order_items', 'pos_cash_movements']
        .forEach((table) => db.prepare(`DELETE FROM ${table}`).run());
    }

    const upsertProduct = db.prepare(`INSERT INTO products (id, name, price, category, category_id, category_name, image_path, is_favorite, is_best_seller, show_on_pos, track_stock, updated_at)
      VALUES (@id, @name, @price, @category, @category_id, @category_name, @image_path, @is_favorite, @is_best_seller, @show_on_pos, @track_stock, @updated_at)
      ON CONFLICT(id) DO UPDATE SET
        name=excluded.name, price=excluded.price, category=excluded.category, category_id=excluded.category_id,
        category_name=excluded.category_name, image_path=excluded.image_path, is_favorite=excluded.is_favorite,
        is_best_seller=excluded.is_best_seller, show_on_pos=excluded.show_on_pos, track_stock=excluded.track_stock,
        updated_at=excluded.updated_at`);
    (data.products || []).forEach((r) => upsertProduct.run(normalizeProduct(r)));

    const upsertCategory = db.prepare('INSERT INTO product_categories (id,name,image_path) VALUES (?,?,?) ON CONFLICT(id) DO UPDATE SET name=excluded.name,image_path=excluded.image_path');
    (data.categories || []).forEach((r) => upsertCategory.run(r.id, r.name, r.image_path || null));

    const methods = (data.payment_methods && data.payment_methods.length) ? data.payment_methods : PAYMENT_METHOD_FALLBACK;
    const upsertPm = db.prepare('INSERT INTO payment_methods (code,name,is_active,sort_order,requires_bank) VALUES (?,?,?,?,?) ON CONFLICT(code) DO UPDATE SET name=excluded.name,is_active=excluded.is_active,sort_order=excluded.sort_order,requires_bank=excluded.requires_bank');
    methods.forEach((r) => upsertPm.run(r.code, r.name, r.is_active ?? 1, r.sort_order ?? 0, r.requires_bank ?? null));

    const upsertBank = db.prepare('INSERT INTO qris_banks (id,name,sort_order,is_active) VALUES (?,?,?,?) ON CONFLICT(id) DO UPDATE SET name=excluded.name,sort_order=excluded.sort_order,is_active=excluded.is_active');
    getBanks(data).forEach((r) => upsertBank.run(r.id, r.name, r.sort_order ?? 0, r.is_active ?? 1));

    const upsertChannel = db.prepare('INSERT INTO payment_channels (id,payment_method,channel_name,bank_name,is_active,sort_order) VALUES (?,?,?,?,?,?) ON CONFLICT(id) DO UPDATE SET payment_method=excluded.payment_method,channel_name=excluded.channel_name,bank_name=excluded.bank_name,is_active=excluded.is_active,sort_order=excluded.sort_order');
    (data.payment_channels || []).forEach((r) => upsertChannel.run(r.id, r.payment_method || '', r.channel_name || '', r.bank_name || '', r.is_active ?? 1, r.sort_order ?? 0));

    const upsertGuide = db.prepare('INSERT INTO guides (id,name,is_active) VALUES (?,?,?) ON CONFLICT(id) DO UPDATE SET name=excluded.name,is_active=excluded.is_active');
    (data.guides || []).forEach((r) => upsertGuide.run(r.id, r.name, r.is_active ?? 1));

    const upsertUser = db.prepare('INSERT INTO users (id,username,name,role) VALUES (?,?,?,?) ON CONFLICT(id) DO UPDATE SET username=excluded.username,name=excluded.name,role=excluded.role');
    (data.cashiers || data.users || []).forEach((r) => upsertUser.run(r.id, r.username, r.name, r.role));

    const upsertOrder = db.prepare('INSERT INTO orders (id,order_code,customer_id,status,created_at,completed_at,customer_name,customer_contact,customer_address,customer_note,total_amount) VALUES (?,?,?,?,?,?,?,?,?,?,?) ON CONFLICT(id) DO UPDATE SET order_code=excluded.order_code,customer_id=excluded.customer_id,status=excluded.status,created_at=excluded.created_at,completed_at=excluded.completed_at,customer_name=excluded.customer_name,customer_contact=excluded.customer_contact,customer_address=excluded.customer_address,customer_note=excluded.customer_note,total_amount=excluded.total_amount');
    (data.pending_orders || []).forEach((r) => upsertOrder.run(r.id, r.order_code, r.customer_id, r.status, r.created_at, r.completed_at || null, r.customer_name || '', r.contact || r.customer_contact || '', r.customer_address || '', r.customer_note || '', r.total || r.total_amount || 0));

    const upsertOi = db.prepare('INSERT INTO order_items (id,order_id,product_id,qty,price_each,subtotal,product_name) VALUES (?,?,?,?,?,?,?) ON CONFLICT(id) DO UPDATE SET order_id=excluded.order_id,product_id=excluded.product_id,qty=excluded.qty,price_each=excluded.price_each,subtotal=excluded.subtotal,product_name=excluded.product_name');
    (data.pending_order_items || []).forEach((r) => upsertOi.run(r.id, r.order_id, r.product_id, r.qty, r.price_each || 0, r.subtotal || 0, r.product_name || ''));

    if (data.active_shift) {
      const s = data.active_shift;
      db.prepare(`INSERT OR REPLACE INTO pos_shifts
        (id, shift_code, branch_id, opened_at, opened_by, opening_cash_default, opening_cash_actual, status, closed_at, closed_by, expected_cash_total, counted_cash_total, cash_difference, notes, offline_open_uuid, offline_close_uuid, sync_status, created_at, updated_at)
        VALUES (@id, @shift_code, @branch_id, @opened_at, @opened_by, @opening_cash_default, @opening_cash_actual, @status, @closed_at, @closed_by, @expected_cash_total, @counted_cash_total, @cash_difference, @notes, @offline_open_uuid, @offline_close_uuid, 'synced', @created_at, @updated_at)`).run(s);
    }

    const upsertShift = db.prepare(`INSERT INTO pos_shifts
      (id, shift_code, branch_id, opened_at, opened_by, opening_cash_default, opening_cash_actual, status, closed_at, closed_by, expected_cash_total, counted_cash_total, cash_difference, notes, offline_open_uuid, offline_close_uuid, sync_status, created_at, updated_at)
      VALUES (@id, @shift_code, @branch_id, @opened_at, @opened_by, @opening_cash_default, @opening_cash_actual, @status, @closed_at, @closed_by, @expected_cash_total, @counted_cash_total, @cash_difference, @notes, @offline_open_uuid, @offline_close_uuid, 'synced', @created_at, @updated_at)
      ON CONFLICT(id) DO UPDATE SET shift_code=excluded.shift_code,branch_id=excluded.branch_id,opened_at=excluded.opened_at,opened_by=excluded.opened_by,opening_cash_default=excluded.opening_cash_default,opening_cash_actual=excluded.opening_cash_actual,status=excluded.status,closed_at=excluded.closed_at,closed_by=excluded.closed_by,expected_cash_total=excluded.expected_cash_total,counted_cash_total=excluded.counted_cash_total,cash_difference=excluded.cash_difference,notes=excluded.notes,offline_open_uuid=excluded.offline_open_uuid,offline_close_uuid=excluded.offline_close_uuid,sync_status='synced',created_at=excluded.created_at,updated_at=excluded.updated_at`);
    (data.shifts || []).forEach((r) => upsertShift.run(r));

    const upsertCashMove = db.prepare('INSERT INTO pos_cash_movements (id,shift_id,movement_type,amount,reason,notes,created_at,offline_uuid,sync_status) VALUES (?,?,?,?,?,?,?,?,?) ON CONFLICT(id) DO UPDATE SET shift_id=excluded.shift_id,movement_type=excluded.movement_type,amount=excluded.amount,reason=excluded.reason,notes=excluded.notes,created_at=excluded.created_at,offline_uuid=excluded.offline_uuid,sync_status=excluded.sync_status');
    (data.cash_movements || []).forEach((r) => upsertCashMove.run(r.id, r.shift_id, r.movement_type, r.amount, r.reason || '', r.notes || '', r.created_at || localDateTimeString(), r.offline_uuid || null, 'synced'));

    const hasWebSale = db.prepare('SELECT 1 FROM sales WHERE web_sale_id = ? LIMIT 1');
    const hasGroupItem = db.prepare('SELECT 1 FROM sales WHERE transaction_group_uuid = ? AND product_id = ? AND sold_at = ? LIMIT 1');
    const insertImportedSale = db.prepare(`INSERT INTO sales
      (web_sale_id, transaction_code, transaction_group_uuid, offline_uuid, product_id, qty, price_each, total, payment_method, payment_bank, guide_id, guide_name, created_by, sold_at, local_device_id, local_transaction_id, sync_status)
      VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?)`);
    (data.sales_history || []).forEach((r, idx) => {
      const groupId = String(r.transaction_group_uuid || r.offline_uuid || r.transaction_code || `web-${r.web_sale_id || idx}`);
      const webSaleId = Number(r.web_sale_id || 0);
      if (webSaleId > 0 && hasWebSale.get(webSaleId)) return;
      if (hasGroupItem.get(groupId, r.product_id, r.sold_at)) return;
      insertImportedSale.run(webSaleId || null, r.transaction_code || groupId, groupId, null, r.product_id, r.qty || 0, r.price_each || 0, r.total || 0, r.payment_method || '', r.payment_bank || null, r.guide_id || null, r.guide_name || null, r.created_by || null, r.sold_at || localDateTimeString(), 'web', `${groupId}-${idx + 1}`, 'imported_from_web');
    });

    const upsertSetting = db.prepare('INSERT INTO settings (key,value) VALUES (?,?) ON CONFLICT(key) DO UPDATE SET value = excluded.value');
    Object.entries(data.settings || {}).forEach(([k, v]) => upsertSetting.run(k, String(v ?? '')));
  });
  tx();
}

async function syncMaster(options = {}) {
  try {
    const allowIncremental = !!store.get('allowIncrementalSyncOnce');
    const requestedIncremental = options.incremental === true || allowIncremental;
    const since = requestedIncremental ? store.get('lastSyncAt') : null;
    const fullSync = !(requestedIncremental && since);

    const resp = await pullMaster(fullSync ? null : since);
    if (!resp?.ok) return { ...resp, endpoint: '/api/sync/pull.php', fullSync };

    const payload = resp.data || {};
    saveMasterData(payload, { fullSync });

    if (resp.server_time) store.set('lastSyncAt', resp.server_time);
    if (allowIncremental) store.delete('allowIncrementalSyncOnce');

    return {
      ...resp,
      fullSync,
      counts: {
        products: (payload.products || []).length,
        categories: (payload.categories || []).length,
        guides: (payload.guides || []).length,
        banks: getBanks(payload).length,
        payment_methods: (payload.payment_methods || []).length,
        shifts: (payload.shifts || []).length,
        sales_history: (payload.sales_history || []).length,
        pending_orders: (payload.pending_orders || []).length
      }
    };
  } catch (error) {
    console.error('[sync:master] failed', error);
    return { ok: false, message: 'Sync gagal', status: error?.status || 500, endpoint: '/api/sync/pull.php' };
  }
}

function buildPendingPayload() {
  const db = initDb();
  const sales = db.prepare("SELECT * FROM sales WHERE sync_status IN ('pending','failed') ORDER BY id ASC").all();
  const grouped = new Map();

  for (const row of sales) {
    const key = row.local_transaction_id || row.transaction_group_uuid;
    if (!key) continue;
    if (!grouped.has(key)) {
      grouped.set(key, {
        transaction_code: row.transaction_code,
        offline_uuid: key,
        transaction_group_uuid: row.transaction_group_uuid || key,
        local_device_id: row.local_device_id,
        local_transaction_id: row.local_transaction_id || key,
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
    grouped.get(key).items.push({ product_id: row.product_id, qty: row.qty, price_each: row.price_each, total: row.total });
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
        const txn = payload.transactions.find((t) => t.local_transaction_id === uuid || t.offline_uuid === uuid);
        if (!txn) continue;
        db.prepare('UPDATE sales SET sync_status = ?, sync_error = ?, last_synced_at = CURRENT_TIMESTAMP WHERE local_transaction_id = ?')
          .run(isSuccess ? 'synced' : 'failed', isSuccess ? null : (result.message || 'sync failed'), txn.local_transaction_id);
        db.prepare('INSERT INTO pos_sync_queue_log (entity_type, offline_uuid, payload_json, processed_at, status, message) VALUES (?,?,?,?,?,?)')
          .run('sale', txn.local_transaction_id, JSON.stringify(result), localDateTimeString(), isSuccess ? 'success' : 'failed', result.message || null);
      }
    });
    tx();
    return resp;
  } catch (error) {
    console.error('[sync:pending] failed', error);
    return { ok: false, message: 'Sync gagal', status: error?.status || 500 };
  }
}

module.exports = { syncMaster, syncPendingTransactions };
