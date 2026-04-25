'use strict';
const path = require('path');
const { app } = require('electron');
const Database = require('better-sqlite3');

let _db = null;

function getDbPath() {
  return path.join(app.getPath('userData'), 'adena-pos.db');
}

function db() {
  if (_db) return _db;
  _db = new Database(getDbPath());
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
      shift_id              INTEGER,
      shift_offline_uuid    TEXT,
      customer_id           INTEGER,
      guide_name            TEXT,
      payment_method        TEXT DEFAULT 'cash',
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
      sync_status           TEXT DEFAULT 'pending'
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
  `);

  // additive migrations (aman untuk data existing)
  const ensureColumn = (table, column, ddl) => {
    const cols = d.prepare(`PRAGMA table_info(${table})`).all();
    if (!cols.some((c) => c.name === column)) {
      d.exec(`ALTER TABLE ${table} ADD COLUMN ${ddl}`);
    }
  };
  ensureColumn('products', 'image_path', 'image_path TEXT');
  ensureColumn('products', 'show_on_pos', 'show_on_pos INTEGER DEFAULT 1');
  ensureColumn('qris_banks', 'sort_order', 'sort_order INTEGER DEFAULT 0');
  ensureColumn('qris_banks', 'is_active', 'is_active INTEGER DEFAULT 1');
  ensureColumn('guides', 'is_active', 'is_active INTEGER DEFAULT 1');
  ensureColumn('payment_methods', 'requires_bank', 'requires_bank INTEGER DEFAULT 0');
}

// ── Settings ──────────────────────────────────────────────────────────────────

function getSetting(key, def = null) {
  const row = db().prepare('SELECT value FROM app_settings WHERE key = ?').get(key);
  return row ? row.value : def;
}

function setSetting(key, value) {
  db().prepare('INSERT OR REPLACE INTO app_settings (key, value) VALUES (?, ?)').run(key, String(value ?? ''));
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
  db().prepare(`
    INSERT INTO transactions
      (offline_uuid, transaction_group_uuid, shift_id, shift_offline_uuid,
       customer_id, guide_name, payment_method, payment_bank,
       items_json, subtotal, tx_discount_amount, tx_discount_type,
       total, paid_amount, change_amount, loyalty_points_earned,
       sold_at, created_by, sync_status)
    VALUES
      (@offline_uuid, @transaction_group_uuid, @shift_id, @shift_offline_uuid,
       @customer_id, @guide_name, @payment_method, @payment_bank,
       @items_json, @subtotal, @tx_discount_amount, @tx_discount_type,
       @total, @paid_amount, @change_amount, @loyalty_points_earned,
       @sold_at, @created_by, 'pending')
  `).run(data);
}

function getPendingTransactions() {
  return db().prepare("SELECT * FROM transactions WHERE sync_status = 'pending'").all();
}

function markTransactionSynced(offlineUuid, txCode) {
  db().prepare(
    "UPDATE transactions SET sync_status = 'synced', transaction_code = ? WHERE offline_uuid = ?"
  ).run(txCode, offlineUuid);
}

function getPendingShifts() {
  return db().prepare("SELECT * FROM pos_shifts WHERE sync_status = 'pending'").all();
}

function markShiftSynced(offlineUuid, serverId) {
  db().prepare(
    "UPDATE pos_shifts SET sync_status = 'synced', server_id = ? WHERE offline_uuid = ?"
  ).run(serverId, offlineUuid);
}

function getPendingMovements() {
  return db().prepare("SELECT * FROM cash_movements WHERE sync_status = 'pending'").all();
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

function getLastSyncAt() {
  const row = db().prepare(
    "SELECT synced_at FROM sync_log WHERE status = 'ok' ORDER BY id DESC LIMIT 1"
  ).get();
  return row ? row.synced_at : null;
}

module.exports = {
  db, getSetting, setSetting, getServerSetting,
  saveLocalUser, getLocalUser, setLocalPasswordHash,
  replaceAll, replaceServerSettings,
  getActiveShift, openShift, closeShift, upsertServerShift,
  addCashMovement, getShiftMovements,
  saveTransaction, getPendingTransactions, markTransactionSynced,
  getPendingShifts, markShiftSynced,
  getPendingMovements, markMovementSynced,
  addCustomerLoyaltyPoints,
  logSync, getLastSyncAt,
};
