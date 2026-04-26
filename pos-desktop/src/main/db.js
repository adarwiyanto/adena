const path = require('path');
const fs = require('fs');
const Database = require('better-sqlite3');
const { app } = require('electron');

let db;

function dbPath() {
  const dir = path.join(app.getPath('userData'), 'data');
  fs.mkdirSync(dir, { recursive: true });
  return path.join(dir, 'pos.sqlite');
}

function initDb() {
  if (db) return db;
  db = new Database(dbPath());
  db.pragma('journal_mode = WAL');
  db.pragma('foreign_keys = ON');

  db.exec(`
    CREATE TABLE IF NOT EXISTS users (
      id INTEGER PRIMARY KEY,
      username TEXT NOT NULL,
      name TEXT NOT NULL,
      role TEXT,
      role_id INTEGER,
      password_hash TEXT,
      created_at TEXT,
      updated_at TEXT
    );

    CREATE TABLE IF NOT EXISTS roles (
      id INTEGER PRIMARY KEY,
      role_key TEXT NOT NULL,
      role_name TEXT NOT NULL,
      is_system INTEGER DEFAULT 0,
      is_active INTEGER DEFAULT 1,
      created_at TEXT,
      updated_at TEXT
    );

    CREATE TABLE IF NOT EXISTS role_permissions (
      id INTEGER PRIMARY KEY,
      role_id INTEGER NOT NULL,
      menu_key TEXT NOT NULL,
      can_view INTEGER DEFAULT 0,
      can_create INTEGER DEFAULT 0,
      can_edit INTEGER DEFAULT 0,
      can_delete INTEGER DEFAULT 0,
      can_print INTEGER DEFAULT 0,
      can_export INTEGER DEFAULT 0,
      can_approve INTEGER DEFAULT 0,
      created_at TEXT,
      updated_at TEXT
    );

    CREATE TABLE IF NOT EXISTS product_categories (
      id INTEGER PRIMARY KEY,
      name TEXT NOT NULL,
      created_at TEXT
    );

    CREATE TABLE IF NOT EXISTS products (
      id INTEGER PRIMARY KEY,
      name TEXT NOT NULL,
      price REAL NOT NULL DEFAULT 0,
      category TEXT,
      category_id INTEGER,
      category_name TEXT,
      image_path TEXT,
      is_favorite INTEGER DEFAULT 0,
      is_best_seller INTEGER DEFAULT 0,
      show_on_pos INTEGER DEFAULT 1,
      product_type TEXT,
      track_stock INTEGER DEFAULT 1,
      allow_bom INTEGER DEFAULT 0,
      updated_at TEXT
    );

    CREATE TABLE IF NOT EXISTS guides (
      id INTEGER PRIMARY KEY,
      name TEXT NOT NULL,
      is_active INTEGER DEFAULT 1,
      created_at TEXT
    );

    CREATE TABLE IF NOT EXISTS payment_methods (
      id INTEGER PRIMARY KEY,
      code TEXT NOT NULL,
      name TEXT NOT NULL,
      is_system INTEGER DEFAULT 0,
      is_active INTEGER DEFAULT 1,
      sort_order INTEGER DEFAULT 0,
      requires_bank INTEGER DEFAULT 0,
      created_at TEXT
    );

    CREATE TABLE IF NOT EXISTS payment_channels (
      id INTEGER PRIMARY KEY,
      payment_method TEXT,
      channel_name TEXT,
      bank_name TEXT,
      is_active INTEGER DEFAULT 1,
      sort_order INTEGER DEFAULT 0
    );

    CREATE TABLE IF NOT EXISTS qris_banks (
      id INTEGER PRIMARY KEY,
      name TEXT NOT NULL,
      sort_order INTEGER DEFAULT 0,
      is_active INTEGER DEFAULT 1,
      created_at TEXT
    );

    CREATE TABLE IF NOT EXISTS settings (
      key TEXT PRIMARY KEY,
      value TEXT NOT NULL
    );

    CREATE TABLE IF NOT EXISTS pos_shifts (
      id INTEGER PRIMARY KEY,
      shift_code TEXT,
      branch_id INTEGER,
      opened_at TEXT,
      opened_by INTEGER,
      opening_cash_default REAL,
      opening_cash_actual REAL,
      status TEXT,
      closed_at TEXT,
      closed_by INTEGER,
      expected_cash_total REAL,
      counted_cash_total REAL,
      cash_difference REAL,
      notes TEXT,
      offline_open_uuid TEXT,
      offline_close_uuid TEXT,
      sync_status TEXT DEFAULT 'synced',
      created_at TEXT,
      updated_at TEXT
    );

    CREATE TABLE IF NOT EXISTS pos_shift_users (
      id INTEGER PRIMARY KEY,
      shift_id INTEGER NOT NULL,
      user_id INTEGER NOT NULL,
      activity_type TEXT,
      created_at TEXT
    );

    CREATE TABLE IF NOT EXISTS pos_cash_movements (
      id INTEGER PRIMARY KEY,
      shift_id INTEGER NOT NULL,
      movement_type TEXT NOT NULL,
      amount REAL NOT NULL,
      reason TEXT,
      notes TEXT,
      created_by INTEGER,
      created_at TEXT,
      offline_uuid TEXT,
      sync_status TEXT DEFAULT 'synced'
    );

    CREATE TABLE IF NOT EXISTS sales (
      id INTEGER PRIMARY KEY,
      web_sale_id INTEGER,
      transaction_code TEXT,
      transaction_group_uuid TEXT,
      offline_uuid TEXT,
      product_id INTEGER NOT NULL,
      qty INTEGER NOT NULL,
      price_each REAL NOT NULL,
      total REAL NOT NULL,
      payment_method TEXT,
      payment_bank TEXT,
      payment_channel_id INTEGER,
      payment_channel_name TEXT,
      guide_id INTEGER,
      guide_name TEXT,
      created_by INTEGER,
      branch_id INTEGER,
      shift_id INTEGER,
      sold_at TEXT,
      discount_amount REAL DEFAULT 0,
      discount_type TEXT DEFAULT 'fixed',
      tx_discount_amount REAL DEFAULT 0,
      tx_discount_type TEXT DEFAULT 'fixed',
      local_device_id TEXT,
      local_transaction_id TEXT,
      sync_status TEXT DEFAULT 'pending',
      sync_error TEXT,
      last_synced_at TEXT
    );

    CREATE TABLE IF NOT EXISTS orders (
      id INTEGER PRIMARY KEY,
      order_code TEXT,
      customer_id INTEGER,
      status TEXT,
      created_at TEXT,
      completed_at TEXT,
      customer_name TEXT,
      customer_contact TEXT,
      customer_address TEXT,
      customer_note TEXT,
      total_amount REAL DEFAULT 0
    );

    CREATE TABLE IF NOT EXISTS order_items (
      id INTEGER PRIMARY KEY,
      order_id INTEGER,
      product_id INTEGER,
      qty INTEGER,
      price_each REAL,
      subtotal REAL,
      product_name TEXT
    );

    CREATE TABLE IF NOT EXISTS stock_ledger (
      id INTEGER PRIMARY KEY,
      branch_id INTEGER,
      product_id INTEGER,
      trans_type TEXT,
      ref_table TEXT,
      ref_id INTEGER,
      qty_in REAL DEFAULT 0,
      qty_out REAL DEFAULT 0,
      unit_cost REAL,
      note TEXT,
      created_by INTEGER,
      created_at TEXT
    );

    CREATE TABLE IF NOT EXISTS shift_sync_queue (
      id INTEGER PRIMARY KEY,
      action TEXT NOT NULL,
      offline_uuid TEXT NOT NULL UNIQUE,
      payload_json TEXT NOT NULL,
      sync_status TEXT DEFAULT 'pending',
      error_message TEXT,
      created_at TEXT DEFAULT CURRENT_TIMESTAMP,
      synced_at TEXT
    );

    CREATE TABLE IF NOT EXISTS pos_sync_queue_log (
      id INTEGER PRIMARY KEY,
      entity_type TEXT NOT NULL,
      offline_uuid TEXT NOT NULL,
      payload_json TEXT,
      processed_at TEXT,
      user_id INTEGER,
      status TEXT DEFAULT 'pending',
      message TEXT,
      created_at TEXT DEFAULT CURRENT_TIMESTAMP
    );

    CREATE UNIQUE INDEX IF NOT EXISTS idx_sales_offline_uuid ON sales(offline_uuid);
    CREATE UNIQUE INDEX IF NOT EXISTS idx_payment_methods_code ON payment_methods(code);
    CREATE UNIQUE INDEX IF NOT EXISTS idx_sales_local_id ON sales(local_device_id, local_transaction_id);
    CREATE INDEX IF NOT EXISTS idx_sales_sync_status ON sales(sync_status);
  `);


  const safeExec = (sql) => {
    try { db.exec(sql); } catch (_) {}
  };
  safeExec('ALTER TABLE products ADD COLUMN image_path TEXT');
  safeExec('ALTER TABLE products ADD COLUMN local_image_path TEXT');
  safeExec('ALTER TABLE products ADD COLUMN image_downloaded_at TEXT');
  safeExec('ALTER TABLE products ADD COLUMN category_id INTEGER');
  safeExec('ALTER TABLE products ADD COLUMN category_name TEXT');
  safeExec('ALTER TABLE product_categories ADD COLUMN image_path TEXT');
  safeExec('ALTER TABLE orders ADD COLUMN customer_name TEXT');
  safeExec('ALTER TABLE orders ADD COLUMN customer_contact TEXT');
  safeExec('ALTER TABLE orders ADD COLUMN customer_address TEXT');
  safeExec('ALTER TABLE orders ADD COLUMN customer_note TEXT');
  safeExec('ALTER TABLE orders ADD COLUMN total_amount REAL DEFAULT 0');
  safeExec('ALTER TABLE order_items ADD COLUMN product_name TEXT');
  safeExec('ALTER TABLE sales ADD COLUMN web_sale_id INTEGER');
  safeExec('CREATE UNIQUE INDEX IF NOT EXISTS idx_sales_web_sale_id ON sales(web_sale_id)');
  safeExec('ALTER TABLE payment_methods ADD COLUMN requires_bank INTEGER DEFAULT 0');
  safeExec('CREATE UNIQUE INDEX IF NOT EXISTS idx_payment_methods_code ON payment_methods(code)');

  return db;
}

module.exports = { initDb };
