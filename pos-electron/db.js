'use strict';
const path = require('path');
const { app } = require('electron');
const Database = require('better-sqlite3');

let _db = null;
let _dbLogged = false;

function getDbPath() {
  return path.join(app.getPath('userData'), 'adena-pos.db');
}

function db() {
  if (_db) return _db;
  _db = new Database(getDbPath());
  if (!_dbLogged) {
    _dbLogged = true;
    console.info(`[db] Active SQLite path: ${getDbPath()}`);
  }
  _db.pragma('journal_mode = WAL');
  _db.pragma('foreign_keys = ON');
  migrate(_db);
  return _db;
}

// ── Schema ────────────────────────────────────────────────────────────────────

function migrate(d) {
  d.exec(`
    CREATE TABLE IF NOT EXISTS app_settings (
      key   TEXT PRIMARY KEY,
      value TEXT
    );

    CREATE TABLE IF NOT EXISTS server_settings (
      key   TEXT PRIMARY KEY,
      value TEXT
    );

    CREATE TABLE IF NOT EXISTS products (
      id             INTEGER PRIMARY KEY,
      name           TEXT NOT NULL,
      price          REAL NOT NULL DEFAULT 0,
      category       TEXT,
      category_id    INTEGER,
      image_path     TEXT,
      is_favorite    INTEGER DEFAULT 0,
      is_best_seller INTEGER DEFAULT 0,
      show_on_pos    INTEGER DEFAULT 1,
      track_stock    INTEGER DEFAULT 0,
      base_unit      TEXT DEFAULT 'pcs',
      updated_at     TEXT
    );

    CREATE TABLE IF NOT EXISTS product_categories (
      id   INTEGER PRIMARY KEY,
      name TEXT NOT NULL
    );

    CREATE TABLE IF NOT EXISTS customers (
      id                INTEGER PRIMARY KEY,
      name              TEXT NOT NULL,
      phone             TEXT,
      email             TEXT,
      loyalty_points    INTEGER DEFAULT 0,
      loyalty_remainder INTEGER DEFAULT 0,
      updated_at        TEXT
    );

    CREATE TABLE IF NOT EXISTS loyalty_rewards (
      id               INTEGER PRIMARY KEY,
      product_id       INTEGER,
      points_required  INTEGER,
      product_name     TEXT
    );

    CREATE TABLE IF NOT EXISTS payment_methods (
      code       TEXT PRIMARY KEY,
      name       TEXT NOT NULL,
      requires_bank INTEGER DEFAULT 0,
      is_active  INTEGER DEFAULT 1,
      sort_order INTEGER DEFAULT 0
    );

    CREATE TABLE IF NOT EXISTS qris_banks (
      id         INTEGER PRIMARY KEY,
      name       TEXT NOT NULL,
      sort_order INTEGER DEFAULT 0,
      is_active  INTEGER DEFAULT 1
    );

    CREATE TABLE IF NOT EXISTS guides (
      id        INTEGER PRIMARY KEY,
      name      TEXT NOT NULL,
      is_active INTEGER DEFAULT 1
    );

    CREATE TABLE IF NOT EXISTS cashiers (
      id        INTEGER PRIMARY KEY,
      username  TEXT UNIQUE,
      name      TEXT NOT NULL,
      role      TEXT,
      is_active INTEGER DEFAULT 1
    );

    CREATE TABLE IF NOT EXISTS payment_channels (
      id             INTEGER PRIMARY KEY,
      payment_method TEXT,
      channel_name   TEXT,
      bank_name      TEXT,
      is_active      INTEGER DEFAULT 1,
      sort_order     INTEGER DEFAULT 0
    );

    CREATE TABLE IF NOT EXISTS landing_orders (
      id            INTEGER PRIMARY KEY,
      order_code    TEXT,
      customer_id   INTEGER,
      customer_name TEXT,
      contact       TEXT,
      status        TEXT DEFAULT 'pending',
      created_at    TEXT,
      updated_at    TEXT
    );

    CREATE TABLE IF NOT EXISTS landing_order_items (
      id           INTEGER PRIMARY KEY AUTOINCREMENT,
      order_id     INTEGER NOT NULL,
      product_id   INTEGER NOT NULL,
      product_name TEXT,
      qty          INTEGER DEFAULT 1,
      UNIQUE(order_id, product_id)
    );

    CREATE TABLE IF NOT EXISTS pos_shifts (
      id                   INTEGER PRIMARY KEY AUTOINCREMENT,
      server_id            INTEGER,
      offline_uuid         TEXT UNIQUE,
      shift_code           TEXT,
      status               TEXT DEFAULT 'open',
      opened_at            TEXT,
      opened_by            INTEGER,
      opening_cash_actual  REAL DEFAULT 0,
      closed_at            TEXT,
      counted_cash_total   REAL,
      notes                TEXT,
      sync_status          TEXT DEFAULT 'pending'
    );

    CREATE TABLE IF NOT EXISTS cash_movements (
      id                INTEGER PRIMARY KEY AUTOINCREMENT,
      server_id         INTEGER,
      offline_uuid      TEXT UNIQUE,
      shift_id          INTEGER,
      shift_offline_uuid TEXT,
      movement_type     TEXT,
      amount            REAL,
      reason            TEXT,
      notes             TEXT,
      created_at        TEXT,
      sync_status       TEXT DEFAULT 'pending'
    );

    CREATE TABLE IF NOT EXISTS transactions (
      id                    INTEGER PRIMARY KEY AUTOINCREMENT,
      offline_uuid          TEXT UNIQUE,
      transaction_group_uuid TEXT,
      transaction_code      TEXT,
      local_transaction_id  TEXT,
      local_device_id       TEXT,
      shift_id              INTEGER,
      shift_offline_uuid    TEXT,
      customer_id           INTEGER,
      guide_id              INTEGER,
      guide_name            TEXT,
      payment_method        TEXT DEFAULT 'cash',
      payment_channel_id    INTEGER,
      payment_channel_name  TEXT,
      payment_bank          TEXT,
      items_json            TEXT,
      subtotal              REAL DEFAULT 0,
      tx_discount_amount    REAL DEFAULT 0,
      tx_discount_type      TEXT DEFAULT 'fixed',
      total                 REAL DEFAULT 0,
      paid_amount           REAL DEFAULT 0,
      change_amount         REAL DEFAULT 0,
      loyalty_points_earned INTEGER DEFAULT 0,
      sold_at               TEXT,
      created_by            INTEGER,
      sync_status           TEXT DEFAULT 'pending',
      sync_error            TEXT
    );

    CREATE TABLE IF NOT EXISTS sync_log (
      id              INTEGER PRIMARY KEY AUTOINCREMENT,
      direction       TEXT,
      status          TEXT,
      records_pulled  INTEGER DEFAULT 0,
      records_pushed  INTEGER DEFAULT 0,
      error_message   TEXT,
      synced_at       TEXT DEFAULT (datetime('now','localtime'))
    );

    CREATE TABLE IF NOT EXISTS local_users (
      id            INTEGER PRIMARY KEY,
      username      TEXT UNIQUE NOT NULL,
      name          TEXT,
      role          TEXT,
      password_hash TEXT,
      token         TEXT
    );

    CREATE TABLE IF NOT EXISTS api_request_log (
      id            INTEGER PRIMARY KEY AUTOINCREMENT,
      endpoint      TEXT,
      status_code   INTEGER DEFAULT 0,
      error_message TEXT,
      synced_at     TEXT DEFAULT (datetime('now','localtime'))
    );

    CREATE TABLE IF NOT EXISTS transaction_items (
      id                    INTEGER PRIMARY KEY AUTOINCREMENT,
      transaction_offline_uuid TEXT NOT NULL,
      product_id            INTEGER,
      product_name          TEXT,
      qty                   REAL DEFAULT 0,
      price                 REAL DEFAULT 0,
      subtotal              REAL DEFAULT 0
    );

    CREATE TABLE IF NOT EXISTS sync_queue (
      id            INTEGER PRIMARY KEY AUTOINCREMENT,
      entity_type   TEXT NOT NULL,
      local_ref     TEXT NOT NULL,
      status        TEXT DEFAULT 'pending_sync',
      last_error    TEXT,
      created_at    TEXT DEFAULT (datetime('now','localtime')),
      updated_at    TEXT DEFAULT (datetime('now','localtime'))
    );

    CREATE TABLE IF NOT EXISTS sync_logs (
      id              INTEGER PRIMARY KEY AUTOINCREMENT,
      endpoint        TEXT,
      http_status     INTEGER DEFAULT 0,
      response_message TEXT,
      synced_at       TEXT DEFAULT (datetime('now','localtime')),
      records_sent    INTEGER DEFAULT 0,
      records_success INTEGER DEFAULT 0,
      records_failed  INTEGER DEFAULT 0
    );

    CREATE TABLE IF NOT EXISTS sync_debug_logs (
      id               INTEGER PRIMARY KEY AUTOINCREMENT,
      direction        TEXT,
      endpoint         TEXT,
      http_status      INTEGER,
      request_summary  TEXT,
      response_summary TEXT,
      error_message    TEXT,
      created_at       TEXT DEFAULT (datetime('now','localtime'))
    );

  `);

  // additive migrations (aman untuk data existing)
  const ensureColumn = (table, column, ddl) => {
    const cols = d.prepare(`PRAGMA table_info(${table})`).all();
    if (!cols.some((c) => c.name === column)) {
      d.exec(`ALTER TABLE ${table} ADD COLUMN ${ddl}`);
    }
  };
  ensureColumn('payment_methods', 'requires_bank', 'requires_bank INTEGER DEFAULT 0');
  ensureColumn('transactions', 'sync_error', 'sync_error TEXT');
  ensureColumn('transactions', 'local_transaction_id', 'local_transaction_id TEXT');
  ensureColumn('transactions', 'local_device_id', 'local_device_id TEXT');
  ensureColumn('transactions', 'guide_id', 'guide_id INTEGER');
  ensureColumn('transactions', 'payment_channel_id', 'payment_channel_id INTEGER');
  ensureColumn('transactions', 'payment_channel_name', 'payment_channel_name TEXT');
  ensureColumn('transactions', 'sync_status', "sync_status TEXT DEFAULT 'pending_sync'");
}

// ── Settings ──────────────────────────────────────────────────────────────────

function getSetting(key, def = null) {
  const row = db().prepare('SELECT value FROM app_settings WHERE key = ?').get(key);
  return row ? row.value : def;
}

function setSetting(key, value) {
  db().prepare('INSERT OR REPLACE INTO app_settings (key, value) VALUES (?, ?)').run(key, String(value ?? ''));
}

function getOrCreateDeviceId() {
  let value = getSetting('local_device_id', '');
  if (value) return value;
  value = `desktop-${Date.now().toString(36)}-${Math.random().toString(36).slice(2, 8)}`;
  setSetting('local_device_id', value);
  return value;
}

function getServerSetting(key, def = '') {
  const row = db().prepare('SELECT value FROM server_settings WHERE key = ?').get(key);
  return row ? row.value : def;
}

// ── Local user (auth offline) ─────────────────────────────────────────────────

function saveLocalUser(user, token) {
  db().prepare(`
    INSERT OR REPLACE INTO local_users (id, username, name, role, token)
    VALUES (@id, @username, @name, @role, @token)
  `).run({ ...user, token });
}

function getLocalUser(username) {
  return db().prepare('SELECT * FROM local_users WHERE username = ?').get(username) || null;
}

function setLocalPasswordHash(userId, hash) {
  db().prepare('UPDATE local_users SET password_hash = ? WHERE id = ?').run(hash, userId);
}

function replaceCashiers(rows = []) {
  db().prepare('DELETE FROM cashiers').run();
  if (!rows.length) return;
  replaceAll('cashiers', rows.map((r) => ({
    id: r.id,
    username: r.username || '',
    name: r.name || '',
    role: r.role || 'kasir',
    is_active: r.is_active == null ? 1 : (r.is_active ? 1 : 0),
  })));
}

// ── Sync: bulk replace ────────────────────────────────────────────────────────

function replaceAll(table, rows) {
  if (!rows || rows.length === 0) return;
  const d = db();
  const cols = Object.keys(rows[0]);
  const placeholders = cols.map(() => '?').join(', ');
  const stmt = d.prepare(
    `INSERT OR REPLACE INTO ${table} (${cols.join(', ')}) VALUES (${placeholders})`
  );
  const insertMany = d.transaction((items) => {
    for (const item of items) stmt.run(cols.map((c) => item[c]));
  });
  insertMany(rows);
}

function replaceServerSettings(obj) {
  const d = db();
  const stmt = d.prepare('INSERT OR REPLACE INTO server_settings (key, value) VALUES (?, ?)');
  const run = d.transaction((entries) => {
    for (const [k, v] of entries) stmt.run(k, String(v ?? ''));
  });
  run(Object.entries(obj));
}

// ── Active shift ──────────────────────────────────────────────────────────────

function getActiveShift() {
  return db().prepare("SELECT * FROM pos_shifts WHERE status = 'open' LIMIT 1").get() || null;
}

function openShift({ offline_uuid, opening_cash_actual, opened_by, opened_at }) {
  db().prepare(`
    INSERT INTO pos_shifts (offline_uuid, status, opening_cash_actual, opened_by, opened_at)
    VALUES (?, 'open', ?, ?, ?)
  `).run(offline_uuid, opening_cash_actual, opened_by, opened_at);
  return db().prepare('SELECT * FROM pos_shifts WHERE offline_uuid = ?').get(offline_uuid);
}

function closeShift({ shiftId, counted_cash_total, notes, closed_at }) {
  db().prepare(`
    UPDATE pos_shifts
    SET status = 'closed', counted_cash_total = ?, notes = ?, closed_at = ?
    WHERE id = ?
  `).run(counted_cash_total, notes, closed_at, shiftId);
}

function upsertServerShift(sh) {
  db().prepare(`
    INSERT OR REPLACE INTO pos_shifts
      (id, server_id, offline_uuid, shift_code, status, opened_at,
       opened_by, opening_cash_actual, closed_at, counted_cash_total, sync_status)
    VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, 'synced')
  `).run(
    sh.id, sh.id, sh.offline_open_uuid, sh.shift_code, sh.status,
    sh.opened_at, sh.opened_by, sh.opening_cash_actual,
    sh.closed_at, sh.counted_cash_total,
  );
}

// ── Cash movements ────────────────────────────────────────────────────────────

function addCashMovement(data) {
  db().prepare(`
    INSERT INTO cash_movements
      (offline_uuid, shift_id, shift_offline_uuid, movement_type, amount, reason, notes, created_at)
    VALUES (@offline_uuid, @shift_id, @shift_offline_uuid, @movement_type, @amount, @reason, @notes, @created_at)
  `).run(data);
}

function getShiftMovements(shiftId) {
  return db().prepare('SELECT * FROM cash_movements WHERE shift_id = ? ORDER BY created_at').all(shiftId);
}

// ── Transactions ──────────────────────────────────────────────────────────────

function saveTransaction(data) {
  return db().prepare(`
    INSERT INTO transactions
      (offline_uuid, transaction_group_uuid, shift_id, shift_offline_uuid,
       local_transaction_id, local_device_id,
       customer_id, guide_id, guide_name, payment_method,
       payment_channel_id, payment_channel_name, payment_bank,
       items_json, subtotal, tx_discount_amount, tx_discount_type,
       total, paid_amount, change_amount, loyalty_points_earned,
       sold_at, created_by, sync_status)
    VALUES
      (@offline_uuid, @transaction_group_uuid, @shift_id, @shift_offline_uuid,
       @local_transaction_id, @local_device_id,
       @customer_id, @guide_id, @guide_name, @payment_method,
       @payment_channel_id, @payment_channel_name, @payment_bank,
       @items_json, @subtotal, @tx_discount_amount, @tx_discount_type,
       @total, @paid_amount, @change_amount, @loyalty_points_earned,
       @sold_at, @created_by, 'pending_sync')
  `).run(data);
}

function getTransactionByOfflineUuid(offlineUuid) {
  return db().prepare('SELECT * FROM transactions WHERE offline_uuid = ? LIMIT 1').get(offlineUuid) || null;
}

function getPendingTransactions() {
  return db().prepare("SELECT * FROM transactions WHERE sync_status IN ('pending', 'pending_sync', 'sync_failed')").all();
}

function markTransactionSynced(offlineUuid, txCode) {
  db().prepare(
    "UPDATE transactions SET sync_status = 'synced', transaction_code = ?, sync_error = NULL WHERE offline_uuid = ?"
  ).run(txCode, offlineUuid);
}

function markTransactionFailed(offlineUuid, reason) {
  db().prepare(
    "UPDATE transactions SET sync_status = 'sync_failed', sync_error = ? WHERE offline_uuid = ?"
  ).run((reason || '').slice(0, 500), offlineUuid);
}

function getPendingShifts() {
  return db().prepare("SELECT * FROM pos_shifts WHERE sync_status IN ('pending', 'pending_sync', 'sync_failed')").all();
}

function markShiftSynced(offlineUuid, serverId) {
  db().prepare(
    "UPDATE pos_shifts SET sync_status = 'synced', server_id = ? WHERE offline_uuid = ?"
  ).run(serverId, offlineUuid);
}

function getPendingMovements() {
  return db().prepare("SELECT * FROM cash_movements WHERE sync_status IN ('pending', 'pending_sync', 'sync_failed')").all();
}

function markMovementSynced(offlineUuid, serverId) {
  db().prepare(
    "UPDATE cash_movements SET sync_status = 'synced', server_id = ? WHERE offline_uuid = ?"
  ).run(serverId || 0, offlineUuid);
}

// ── Customers loyalty update ──────────────────────────────────────────────────

function addCustomerLoyaltyPoints(customerId, points) {
  if (!customerId || points <= 0) return;
  db().prepare('UPDATE customers SET loyalty_points = loyalty_points + ? WHERE id = ?').run(points, customerId);
}

function getPendingLandingOrders() {
  return db().prepare(
    "SELECT * FROM landing_orders WHERE status = 'pending' ORDER BY datetime(created_at) DESC, id DESC LIMIT 30"
  ).all();
}

function getLandingOrderItemsByOrderId(orderId) {
  return db().prepare(
    'SELECT order_id, product_id, product_name, qty FROM landing_order_items WHERE order_id = ? ORDER BY id ASC'
  ).all(orderId);
}

function markLandingOrderProcessing(orderId) {
  db().prepare(
    "UPDATE landing_orders SET status = 'processing', updated_at = datetime('now','localtime') WHERE id = ?"
  ).run(orderId);
}

// ── Sync log ──────────────────────────────────────────────────────────────────

function logSync(entry) {
  db().prepare(`
    INSERT INTO sync_log (direction, status, records_pulled, records_pushed, error_message)
    VALUES (@direction, @status, @records_pulled, @records_pushed, @error_message)
  `).run({
    direction: entry.direction || 'both',
    status: entry.status || 'ok',
    records_pulled: entry.records_pulled || 0,
    records_pushed: entry.records_pushed || 0,
    error_message: entry.error_message || null,
  });
}


function logApiRequest(entry) {
  db().prepare(`
    INSERT INTO api_request_log (endpoint, status_code, error_message)
    VALUES (@endpoint, @status_code, @error_message)
  `).run({
    endpoint: entry.endpoint || '-',
    status_code: Number(entry.status_code || 0),
    error_message: entry.error_message ? String(entry.error_message).slice(0, 500) : null,
  });
}

function logSyncDebug(entry) {
  db().prepare(`
    INSERT INTO sync_debug_logs
      (direction, endpoint, http_status, request_summary, response_summary, error_message)
    VALUES
      (@direction, @endpoint, @http_status, @request_summary, @response_summary, @error_message)
  `).run({
    direction: entry.direction || '-',
    endpoint: entry.endpoint || '-',
    http_status: Number(entry.http_status || 0),
    request_summary: entry.request_summary ? String(entry.request_summary).slice(0, 5000) : null,
    response_summary: entry.response_summary ? String(entry.response_summary).slice(0, 5000) : null,
    error_message: entry.error_message ? String(entry.error_message).slice(0, 2000) : null,
  });
}

function getSyncDebugLogs(limit = 50) {
  const safeLimit = Math.max(1, Math.min(200, Number(limit || 50)));
  return db().prepare(`
    SELECT id, direction, endpoint, http_status, request_summary, response_summary, error_message, created_at
    FROM sync_debug_logs
    ORDER BY id DESC
    LIMIT ?
  `).all(safeLimit);
}

function getPendingTransactionsDebug() {
  return db().prepare(`
    SELECT offline_uuid, sync_status, sync_error, total, payment_method,
           COALESCE(payment_channel_name, payment_bank, '') AS payment_channel,
           sold_at, created_by
    FROM transactions
    WHERE sync_status IN ('pending', 'pending_sync', 'sync_failed')
    ORDER BY id ASC
  `).all();
}

function getDbDiagnostics() {
  const d = db();
  const dbPath = getDbPath();
  const tableCountRow = d.prepare("SELECT COUNT(*) AS total FROM sqlite_master WHERE type = 'table'").get() || {};
  const transactionCountRow = d.prepare('SELECT COUNT(*) AS total FROM transactions').get() || {};
  const pendingCountRow = d.prepare("SELECT COUNT(*) AS total FROM transactions WHERE sync_status IN ('pending', 'pending_sync', 'sync_failed')").get() || {};
  const recentTransactions = d.prepare(`
    SELECT
      offline_uuid,
      COALESCE(transaction_code, '-') AS transaction_code,
      total,
      payment_method,
      COALESCE(payment_channel_name, payment_bank, '-') AS payment_channel,
      sync_status,
      sold_at
    FROM transactions
    ORDER BY id DESC
    LIMIT 20
  `).all();

  let dbSizeBytes = 0;
  try {
    dbSizeBytes = require('fs').statSync(dbPath).size;
  } catch (_) {
    dbSizeBytes = 0;
  }

  return {
    db_path: dbPath,
    db_size_bytes: dbSizeBytes,
    table_count: Number(tableCountRow.total || 0),
    transaction_count: Number(transactionCountRow.total || 0),
    pending_transaction_count: Number(pendingCountRow.total || 0),
    recent_transactions: recentTransactions,
  };
}

function getLatestApiRequest() {
  return db().prepare(`
    SELECT endpoint, status_code, error_message, synced_at
    FROM api_request_log
    ORDER BY id DESC
    LIMIT 1
  `).get() || null;
}

function getLastSyncAt() {
  const row = db().prepare(
    "SELECT synced_at FROM sync_log WHERE status = 'ok' ORDER BY id DESC LIMIT 1"
  ).get();
  return row ? row.synced_at : null;
}

function getSyncQueueStats() {
  const tx = db().prepare(`
    SELECT
      SUM(CASE WHEN sync_status IN ('pending', 'pending_sync') THEN 1 ELSE 0 END) AS pending_count,
      SUM(CASE WHEN sync_status = 'sync_failed' THEN 1 ELSE 0 END) AS failed_count,
      MAX(CASE WHEN sync_status = 'sync_failed' THEN COALESCE(sync_error, '') ELSE NULL END) AS last_error
    FROM transactions
  `).get() || {};
  return {
    pending_count: Number(tx.pending_count || 0),
    failed_count: Number(tx.failed_count || 0),
    last_error: tx.last_error || '',
  };
}

module.exports = {
  db, getDbPath, getSetting, setSetting, getServerSetting,
  getOrCreateDeviceId,
  saveLocalUser, getLocalUser, setLocalPasswordHash,
  replaceCashiers,
  replaceAll, replaceServerSettings,
  getActiveShift, openShift, closeShift, upsertServerShift,
  addCashMovement, getShiftMovements,
  saveTransaction, getPendingTransactions, markTransactionSynced,
  getTransactionByOfflineUuid,
  markTransactionFailed,
  getPendingShifts, markShiftSynced,
  getPendingMovements, markMovementSynced,
  addCustomerLoyaltyPoints,
  getPendingLandingOrders, getLandingOrderItemsByOrderId, markLandingOrderProcessing,
  logSync, getLastSyncAt, getSyncQueueStats,
  logApiRequest, logSyncDebug, getSyncDebugLogs, getPendingTransactionsDebug, getLatestApiRequest,
  getDbDiagnostics,
};
