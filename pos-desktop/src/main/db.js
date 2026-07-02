const path = require('path');
const fs = require('fs');
const Database = require('better-sqlite3');
const { app } = require('electron');

let db;

function dataDirPath() {
  return path.join(app.getPath('userData'), 'data');
}

function dbPath() {
  const dir = dataDirPath();
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

    CREATE TABLE IF NOT EXISTS branches (
      id INTEGER PRIMARY KEY,
      branch_code TEXT,
      branch_name TEXT,
      unit_type TEXT DEFAULT 'branch',
      is_kitchen INTEGER DEFAULT 0,
      is_active INTEGER DEFAULT 1
    );

    CREATE TABLE IF NOT EXISTS branch_product_prices (
      branch_id INTEGER NOT NULL,
      product_id INTEGER NOT NULL,
      price REAL NOT NULL DEFAULT 0,
      is_active INTEGER DEFAULT 1,
      PRIMARY KEY (branch_id, product_id)
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
      sale_source TEXT DEFAULT 'branch_pos',
      unit_type TEXT DEFAULT 'branch',
      shift_id INTEGER,
      sold_at TEXT,
      discount_amount REAL DEFAULT 0,
      discount_type TEXT DEFAULT 'fixed',
      tx_discount_amount REAL DEFAULT 0,
      tx_discount_type TEXT DEFAULT 'fixed',
      customer_name TEXT,
      customer_phone TEXT,
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
  safeExec('ALTER TABLE sales ADD COLUMN cash_received REAL');
  safeExec('ALTER TABLE sales ADD COLUMN cash_change REAL');
  safeExec('ALTER TABLE sales ADD COLUMN customer_name TEXT');
  safeExec('ALTER TABLE sales ADD COLUMN customer_phone TEXT');
  safeExec("ALTER TABLE sales ADD COLUMN sale_source TEXT DEFAULT 'branch_pos'");
  safeExec("ALTER TABLE sales ADD COLUMN unit_type TEXT DEFAULT 'branch'");
  safeExec('ALTER TABLE products ADD COLUMN product_type TEXT');
  safeExec('ALTER TABLE products ADD COLUMN allow_bom INTEGER DEFAULT 0');
  safeExec('CREATE UNIQUE INDEX IF NOT EXISTS idx_sales_web_sale_id ON sales(web_sale_id)');
  safeExec('CREATE INDEX IF NOT EXISTS idx_sales_shift_id ON sales(shift_id)');
  safeExec('CREATE INDEX IF NOT EXISTS idx_sales_sold_at ON sales(sold_at)');
  safeExec('CREATE INDEX IF NOT EXISTS idx_sales_shift_sold_at ON sales(shift_id, sold_at)');
  safeExec('CREATE INDEX IF NOT EXISTS idx_sales_group ON sales(transaction_group_uuid, local_transaction_id, transaction_code)');
  safeExec('CREATE INDEX IF NOT EXISTS idx_sales_customer_name ON sales(customer_name)');
  safeExec('CREATE INDEX IF NOT EXISTS idx_sales_customer_phone ON sales(customer_phone)');
  safeExec('ALTER TABLE payment_methods ADD COLUMN requires_bank INTEGER DEFAULT 0');
  safeExec('CREATE UNIQUE INDEX IF NOT EXISTS idx_payment_methods_code ON payment_methods(code)');
  // Adena POS v1.5 compatibility: migrate existing local SQLite from v1.4.x.
  // CREATE TABLE IF NOT EXISTS does not add new columns to old tables, so sync may fail
  // with: table branches has no column named unit_type.
  safeExec("ALTER TABLE branches ADD COLUMN unit_type TEXT DEFAULT 'branch'");
  safeExec('ALTER TABLE branches ADD COLUMN is_kitchen INTEGER DEFAULT 0');
  safeExec('ALTER TABLE branches ADD COLUMN sort_order INTEGER DEFAULT 0');
  safeExec("UPDATE branches SET unit_type = 'branch' WHERE unit_type IS NULL OR unit_type = ''");
  safeExec('ALTER TABLE sales ADD COLUMN return_reason TEXT');
  safeExec('ALTER TABLE sales ADD COLUMN returned_at TEXT');
  safeExec('ALTER TABLE sales ADD COLUMN returned_by INTEGER');
  safeExec("ALTER TABLE sales ADD COLUMN return_status TEXT DEFAULT 'none'");
  safeExec('ALTER TABLE sales ADD COLUMN return_synced_at TEXT');
  safeExec(`CREATE TABLE IF NOT EXISTS sales_returns (
    id INTEGER PRIMARY KEY,
    offline_uuid TEXT UNIQUE,
    transaction_group_uuid TEXT,
    local_transaction_id TEXT,
    transaction_code TEXT,
    branch_id INTEGER,
    reason TEXT,
    total_return REAL DEFAULT 0,
    created_by INTEGER,
    created_at TEXT,
    sync_status TEXT DEFAULT 'pending',
    sync_error TEXT,
    synced_at TEXT
  )`);
  safeExec(`CREATE TABLE IF NOT EXISTS sales_return_items (
    id INTEGER PRIMARY KEY,
    return_offline_uuid TEXT,
    sale_local_id INTEGER,
    product_id INTEGER,
    qty REAL DEFAULT 0,
    price_each REAL DEFAULT 0,
    subtotal REAL DEFAULT 0
  )`);

  return db;
}

function closeDb() {
  if (!db) return;
  db.close();
  db = null;
}

module.exports = { initDb, closeDb, dbPath, dataDirPath };
