'use strict';
const https = require('https');
const http  = require('http');
const {
  getSetting, setSetting, replaceAll, replaceServerSettings,
  upsertServerShift, getPendingTransactions, markTransactionSynced,
  getPendingShifts, markShiftSynced, getPendingMovements, markMovementSynced,
  getLastSyncAt, logSync, db,
} = require('./db');

const BASE_URL = 'https://adena.co.id';

// ── HTTP helper ───────────────────────────────────────────────────────────────

function apiRequest(method, path, token, body = null) {
  return new Promise((resolve, reject) => {
    const url = new URL(BASE_URL + path);
    const lib = url.protocol === 'https:' ? https : http;
    const bodyStr = body ? JSON.stringify(body) : null;

    const opts = {
      hostname: url.hostname,
      port: url.port || (url.protocol === 'https:' ? 443 : 80),
      path: url.pathname + url.search,
      method,
      headers: {
        'Content-Type': 'application/json',
        'X-Device-Token': token || '',
        ...(bodyStr ? { 'Content-Length': Buffer.byteLength(bodyStr) } : {}),
      },
      timeout: 20000,
    };

    const req = lib.request(opts, (res) => {
      let data = '';
      res.on('data', (c) => { data += c; });
      res.on('end', () => {
        const statusCode = res.statusCode || 0;
        let parsed = null;
        try { parsed = data ? JSON.parse(data) : null; } catch (_) { parsed = null; }

        if (!parsed) {
          const snippet = String(data || '').replace(/\s+/g, ' ').trim().slice(0, 120);
          const err = new Error('Response server bukan JSON. Kemungkinan endpoint redirect/login/error HTML.');
          err.type = 'non_json';
          err.statusCode = statusCode;
          err.debug = snippet;
          reject(err);
          return;
        }

        if (parsed.ok === false) {
          const message = parsed.message || `Request gagal (HTTP ${statusCode})`;
          const err = new Error(message);
          err.type = 'json_error';
          err.statusCode = statusCode;
          err.response = parsed;
          reject(err);
          return;
        }

        if (statusCode >= 400) {
          const err = new Error(parsed.message || `HTTP ${statusCode}`);
          err.type = 'http_error';
          err.statusCode = statusCode;
          err.response = parsed;
          reject(err);
          return;
        }

        resolve(parsed);
      });
    });

    req.on('timeout', () => {
      req.destroy();
      const err = new Error('Network error: request timeout');
      err.type = 'network_error';
      reject(err);
    });
    req.on('error', (cause) => {
      const err = new Error(`Network error: ${cause?.message || 'gagal menghubungi server'}`);
      err.type = 'network_error';
      err.cause = cause;
      reject(err);
    });
    if (bodyStr) req.write(bodyStr);
    req.end();
  });
}

// ── Login (online) ────────────────────────────────────────────────────────────

async function loginOnline(username, password, deviceName) {
  const res = await apiRequest('POST', '/api/auth.php', null, {
    username, password, device_name: deviceName,
  });
  if (!res.ok) throw new Error(res.message || 'Login gagal');
  return res; // { ok, token, user }
}

// ── Pull: download semua data dari server ─────────────────────────────────────

async function pullFromServer(token, fullSync = false) {
  const since = fullSync ? null : getLastSyncAt();
  const qs    = since ? `?since=${encodeURIComponent(since)}` : '';
  const res   = await apiRequest('GET', '/api/sync/pull.php' + qs, token);
  if (!res.ok) throw new Error(res.message || 'Pull gagal');

  const d = res.data || res;
  let pulled = 0;

  if (Array.isArray(d.products)) {
    if (fullSync) db().prepare('DELETE FROM products').run();
    if (d.products.length) {
      replaceAll('products', d.products.map((p) => ({
        id: p.id, name: p.name, price: p.price,
        category: p.category || null, image_path: p.image_path || null,
        is_favorite: p.is_favorite ? 1 : 0,
        is_best_seller: p.is_best_seller ? 1 : 0,
        show_on_pos: p.show_on_pos ? 1 : 0,
        track_stock: p.track_stock ? 1 : 0,
        base_unit: p.base_unit || 'pcs',
        updated_at: p.updated_at || null,
      })));
    }
    pulled += d.products.length;
  }

  if (d.categories?.length) {
    replaceAll('product_categories', d.categories.map((c) => ({ id: c.id, name: c.name })));
    pulled += d.categories.length;
  }

  if (d.customers?.length) {
    replaceAll('customers', d.customers.map((c) => ({
      id: c.id, name: c.name,
      phone: c.phone || null, email: c.email || null,
      loyalty_points: c.loyalty_points || 0,
      loyalty_remainder: c.loyalty_remainder || 0,
      updated_at: c.updated_at || null,
    })));
    pulled += d.customers.length;
  }

  if (d.loyalty_rewards?.length) {
    replaceAll('loyalty_rewards', d.loyalty_rewards.map((r) => ({
      id: r.id, product_id: r.product_id,
      points_required: r.points_required, product_name: r.product_name || '',
    })));
  }

  if (Array.isArray(d.payment_methods)) {
    db().prepare('DELETE FROM payment_methods').run();
    if (d.payment_methods.length) {
      replaceAll('payment_methods', d.payment_methods.map((m) => ({
      code: m.code, name: m.name,
      requires_bank: m.requires_bank ? 1 : 0,
      is_active: m.is_active ? 1 : 0, sort_order: m.sort_order || 0,
      })));
    }
  }

  if (Array.isArray(d.qris_banks)) {
    db().prepare('DELETE FROM qris_banks').run();
    if (d.qris_banks.length) {
      replaceAll('qris_banks', d.qris_banks.map((b) => ({
      id: b.id, name: b.name,
      sort_order: b.sort_order || 0, is_active: b.is_active ? 1 : 0,
      })));
    }
  }

  if (Array.isArray(d.guides)) {
    db().prepare('DELETE FROM guides').run();
    if (d.guides.length) {
      replaceAll('guides', d.guides.map((g) => ({
      id: g.id, name: g.name, is_active: g.is_active ? 1 : 0,
      })));
    }
  }

  if (d.settings) replaceServerSettings(d.settings);

  // Simpan shift aktif dari server
  if (d.active_shift) upsertServerShift(d.active_shift);

  // Cash movements dari server shift aktif
  if (d.cash_movements?.length) {
    const stmt = db().prepare(`
      INSERT OR IGNORE INTO cash_movements
        (server_id, offline_uuid, shift_id, movement_type, amount, reason, notes, created_at, sync_status)
      VALUES (?, ?, ?, ?, ?, ?, ?, ?, 'synced')
    `);
    for (const cm of d.cash_movements) {
      stmt.run(cm.id, cm.offline_uuid || null, cm.shift_id,
        cm.movement_type, cm.amount, cm.reason || '', cm.notes || '', cm.created_at);
    }
    pulled += d.cash_movements.length;
  }

  return pulled;
}

// ── Push: upload pending data ke server ───────────────────────────────────────

async function pushToServer(token) {
  const pendingShifts = getPendingShifts();
  const pendingMovements = getPendingMovements();
  const pendingTx = getPendingTransactions();

  if (!pendingShifts.length && !pendingMovements.length && !pendingTx.length) {
    return 0;
  }

  const body = {
    shifts: pendingShifts.map((s) => ({
      offline_uuid: s.offline_uuid,
      status: s.status,
      opened_at: s.opened_at,
      opening_cash_actual: s.opening_cash_actual,
      closed_at: s.closed_at,
      counted_cash_total: s.counted_cash_total,
      notes: s.notes,
      offline_close_uuid: s.status === 'closed' ? s.offline_uuid + '-close' : null,
    })),
    cash_movements: pendingMovements.map((m) => ({
      offline_uuid: m.offline_uuid,
      shift_offline_uuid: m.shift_offline_uuid,
      movement_type: m.movement_type,
      amount: m.amount,
      reason: m.reason,
      notes: m.notes,
      created_at: m.created_at,
    })),
    transactions: pendingTx.map((tx) => ({
      offline_uuid: tx.offline_uuid,
      transaction_group_uuid: tx.transaction_group_uuid,
      shift_offline_uuid: tx.shift_offline_uuid,
      customer_id: tx.customer_id,
      guide_name: tx.guide_name,
      payment_method: tx.payment_method,
      payment_bank: tx.payment_bank,
      items: JSON.parse(tx.items_json || '[]'),
      subtotal: tx.subtotal,
      tx_discount_amount: tx.tx_discount_amount,
      tx_discount_type: tx.tx_discount_type,
      total: tx.total,
      paid_amount: tx.paid_amount,
      sold_at: tx.sold_at,
    })),
  };

  const res = await apiRequest('POST', '/api/sync/push.php', token, body);
  if (!res.ok) throw new Error(res.message || 'Push gagal');

  const r = res.results || {};
  let pushed = 0;

  for (const [uuid, serverId] of Object.entries(r.shifts || {})) {
    markShiftSynced(uuid, serverId);
    pushed++;
  }
  for (const [uuid] of Object.entries(r.cash_movements || {})) {
    markMovementSynced(uuid, null);
    pushed++;
  }
  for (const [uuid, txCode] of Object.entries(r.transactions || {})) {
    if (txCode && txCode !== 'exists') markTransactionSynced(uuid, txCode);
    pushed++;
  }

  return pushed;
}

// ── Full sync (pull + push) ───────────────────────────────────────────────────

async function runSync(fullSync = false) {
  const token = getSetting('device_token', '');
  if (!token) return { ok: false, message: 'Belum login' };

  let pulled = 0, pushed = 0;
  try {
    pushed  = await pushToServer(token);
    pulled  = await pullFromServer(token, fullSync);
    logSync({ direction: 'both', status: 'ok', records_pulled: pulled, records_pushed: pushed });
    setSetting('last_sync_at', new Date().toISOString());
    return { ok: true, pulled, pushed };
  } catch (err) {
    const debug = err?.type === 'non_json' && err?.debug ? ` | ${err.debug}` : '';
    logSync({ direction: 'both', status: 'error', error_message: err.message, records_pulled: pulled, records_pushed: pushed });
    return { ok: false, silent: false, type: err?.type || 'unknown_error', message: `${err.message}${debug}` };
  }
}

module.exports = { loginOnline, pullFromServer, pushToServer, runSync };
