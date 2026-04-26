'use strict';
const https = require('https');
const http  = require('http');
const {
  getSetting, setSetting, replaceAll, replaceServerSettings,
  replaceCashiers, getOrCreateDeviceId,
  upsertServerShift, getPendingTransactions, markTransactionSynced,
  markTransactionFailed,
  getPendingShifts, markShiftSynced, getPendingMovements, markMovementSynced,
  getLastSyncAt, logSync, db, getSyncQueueStats, logApiRequest,
} = require('./db');

function getBaseUrl() {
  const configured = String(getSetting('api_base_url', '') || '').trim();
  return configured || 'https://adena.co.id';
}

function apiRequest(method, path, token, body = null) {
  return new Promise((resolve, reject) => {
    const url = new URL(getBaseUrl() + path);
    const lib = url.protocol === 'https:' ? https : http;
    const bodyStr = body ? JSON.stringify(body) : null;

    const maskedToken = token ? `${String(token).slice(0, 6)}***${String(token).slice(-4)}` : '(empty)';
    const opts = {
      hostname: url.hostname,
      port: url.port || (url.protocol === 'https:' ? 443 : 80),
      path: url.pathname + url.search,
      method,
      headers: {
        'Content-Type': 'application/json',
        'Authorization': token ? `Bearer ${token}` : '',
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
          const snippet = String(data || '').replace(/\s+/g, ' ').trim().slice(0, 200);
          logApiRequest({ endpoint: url.pathname, status_code: statusCode, error_message: `Non JSON: ${snippet}` });
          const err = new Error('Response server bukan JSON. Kemungkinan endpoint redirect/login/error HTML.');
          err.type = 'non_json'; err.statusCode = statusCode; err.debug = snippet;
          reject(err); return;
        }

        if (parsed.ok === false) {
          const message = parsed.message || `Request gagal (HTTP ${statusCode})`;
          logApiRequest({ endpoint: url.pathname, status_code: statusCode, error_message: message });
          const err = new Error(message);
          err.type = 'json_error';
          if (statusCode === 401 || /token tidak valid/i.test(message)) err.type = 'auth_error';
          err.statusCode = statusCode; err.response = parsed;
          reject(err); return;
        }

        if (statusCode >= 400) {
          const message = parsed.message || `HTTP ${statusCode}`;
          logApiRequest({ endpoint: url.pathname, status_code: statusCode, error_message: message });
          const err = new Error(message);
          err.type = 'http_error'; err.statusCode = statusCode; err.response = parsed;
          reject(err); return;
        }

        logApiRequest({ endpoint: url.pathname, status_code: statusCode, error_message: null });
        resolve(parsed);
      });
    });
    console.info('[sync] request', { method, path: url.pathname + url.search, token: maskedToken });

    req.on('timeout', () => {
      req.destroy();
      logApiRequest({ endpoint: url.pathname, status_code: 0, error_message: 'Network error: request timeout' });
      const err = new Error('Network error: request timeout');
      err.type = 'network_error';
      reject(err);
    });
    req.on('error', (cause) => {
      logApiRequest({ endpoint: url.pathname, status_code: 0, error_message: `Network error: ${cause?.message || '-'}` });
      const err = new Error(`Network error: ${cause?.message || 'gagal menghubungi server'}`);
      err.type = 'network_error'; err.cause = cause;
      reject(err);
    });
    if (bodyStr) req.write(bodyStr);
    req.end();
  });
}

async function testConnection(baseUrl, token) {
  const prevBase = getSetting('api_base_url', '');
  const prevToken = getSetting('device_token', '');
  try {
    setSetting('api_base_url', String(baseUrl || '').trim());
    setSetting('device_token', String(token || '').trim());
    const res = await apiRequest('GET', '/api/auth.php', String(token || '').trim());
    return { ok: true, message: 'Koneksi berhasil', data: res };
  } catch (err) {
    return { ok: false, message: err?.message || 'Koneksi gagal', type: err?.type || 'unknown' };
  } finally {
    setSetting('api_base_url', prevBase);
    setSetting('device_token', prevToken);
  }
}

async function pullFromServer(token, fullSync = false) {
  const since = fullSync ? null : getLastSyncAt();
  const qs = since ? `?since=${encodeURIComponent(since)}` : '';
  const res = await apiRequest('GET', '/api/sync/pull.php' + qs, token);
  const d = res.data || res;
  let pulled = 0;

  if (Array.isArray(d.products)) {
    if (fullSync) db().prepare('DELETE FROM products').run();
    if (d.products.length) replaceAll('products', d.products.map((p) => ({ id: p.id, name: p.name, price: p.price, category: p.category || null, image_path: p.image_path || null, is_favorite: p.is_favorite ? 1 : 0, is_best_seller: p.is_best_seller ? 1 : 0, show_on_pos: p.show_on_pos ? 1 : 0, track_stock: p.track_stock ? 1 : 0, base_unit: p.base_unit || 'pcs', updated_at: p.updated_at || null })));
    pulled += d.products.length;
  }
  if (d.categories?.length) { replaceAll('product_categories', d.categories.map((c) => ({ id: c.id, name: c.name }))); pulled += d.categories.length; }
  if (d.customers?.length) { replaceAll('customers', d.customers.map((c) => ({ id: c.id, name: c.name, phone: c.phone || null, email: c.email || null, loyalty_points: c.loyalty_points || 0, loyalty_remainder: c.loyalty_remainder || 0, updated_at: c.updated_at || null }))); pulled += d.customers.length; }
  if (d.loyalty_rewards?.length) replaceAll('loyalty_rewards', d.loyalty_rewards.map((r) => ({ id: r.id, product_id: r.product_id, points_required: r.points_required, product_name: r.product_name || '' })));

  if (Array.isArray(d.payment_methods)) {
    db().prepare('DELETE FROM payment_methods').run();
    if (d.payment_methods.length) replaceAll('payment_methods', d.payment_methods.map((m) => ({ code: m.code, name: m.name, requires_bank: m.requires_bank ? 1 : 0, is_active: m.is_active ? 1 : 0, sort_order: m.sort_order || 0 })));
  }

  let channels = [];
  try {
    channels = await apiRequest('GET', '/api/payment_channels.php', token);
  } catch (_) {
    channels = Array.isArray(d.payment_channels) ? d.payment_channels : [];
  }
  if (Array.isArray(channels)) {
    db().prepare('DELETE FROM payment_channels').run();
    if (channels.length) {
      replaceAll('payment_channels', channels.map((c) => ({
        id: c.id,
        payment_method: c.method || c.payment_method || null,
        channel_name: c.name || c.channel_name || c.bank_name || null,
        bank_name: c.name || c.bank_name || null,
        is_active: c.is_active == null ? 1 : (c.is_active ? 1 : 0),
        sort_order: c.sort_order || 0,
      })));
      pulled += channels.length;
    }
  }

  if (Array.isArray(d.guides)) {
    db().prepare('DELETE FROM guides').run();
    if (d.guides.length) replaceAll('guides', d.guides.map((g) => ({ id: g.id, name: g.name, is_active: g.is_active == null ? 1 : (g.is_active ? 1 : 0) })));
  }
  if (Array.isArray(d.cashiers || d.users)) {
    replaceCashiers(d.cashiers || d.users || []);
  }
  if (d.settings) replaceServerSettings(d.settings);
  if (d.active_shift) upsertServerShift(d.active_shift);

  return pulled;
}

async function pushToServer(token) {
  const pendingShifts = getPendingShifts();
  const pendingMovements = getPendingMovements();
  const pendingTx = getPendingTransactions();
  if (!pendingShifts.length && !pendingMovements.length && !pendingTx.length) return 0;

  const deviceId = getOrCreateDeviceId();
  const body = {
    shifts: pendingShifts.map((s) => ({ offline_uuid: s.offline_uuid, status: s.status, opened_at: s.opened_at, opening_cash_actual: s.opening_cash_actual, closed_at: s.closed_at, counted_cash_total: s.counted_cash_total, notes: s.notes, offline_close_uuid: s.status === 'closed' ? s.offline_uuid + '-close' : null })),
    cash_movements: pendingMovements.map((m) => ({ offline_uuid: m.offline_uuid, shift_offline_uuid: m.shift_offline_uuid, movement_type: m.movement_type, amount: m.amount, reason: m.reason, notes: m.notes, created_at: m.created_at })),
    transactions: pendingTx.map((tx) => ({ offline_uuid: tx.offline_uuid, transaction_group_uuid: tx.transaction_group_uuid, shift_offline_uuid: tx.shift_offline_uuid, customer_id: tx.customer_id, user_id: tx.created_by, guide_id: tx.guide_id || null, guide_name: tx.guide_name, payment_method: tx.payment_method, payment_channel_id: tx.payment_channel_id || null, payment_channel_name: tx.payment_channel_name || tx.payment_bank || null, payment_bank: tx.payment_bank, items: JSON.parse(tx.items_json || '[]'), subtotal: tx.subtotal, tx_discount_amount: tx.tx_discount_amount, tx_discount_type: tx.tx_discount_type, total: tx.total, paid_amount: tx.paid_amount, change_amount: tx.change_amount || 0, sold_at: tx.sold_at, local_transaction_id: tx.local_transaction_id || tx.offline_uuid, local_device_id: tx.local_device_id || deviceId })),
  };

  let res;
  try { res = await apiRequest('POST', '/api/sync/push.php', token, body); }
  catch (err) {
    if (err?.type !== 'auth_error') {
      for (const tx of pendingTx) markTransactionFailed(tx.offline_uuid, err?.message || 'Push gagal');
    }
    throw err;
  }

  const r = res.results || {};
  let pushed = 0;
  for (const [uuid, serverId] of Object.entries(r.shifts || {})) { markShiftSynced(uuid, serverId); pushed++; }
  for (const [uuid] of Object.entries(r.cash_movements || {})) { markMovementSynced(uuid, null); pushed++; }
  for (const [uuid, txCode] of Object.entries(r.transactions || {})) { if (txCode && txCode !== 'exists') markTransactionSynced(uuid, txCode); pushed++; }
  return pushed;
}

async function runSync(fullSync = false) {
  const token = String(getSetting('device_token', '') || '').trim();
  if (!token) return { ok: false, type: 'auth_error', message: 'API Token belum diisi.' };

  let pulled = 0; let pushed = 0;
  try {
    pushed = await pushToServer(token);
    pulled = await pullFromServer(token, fullSync);
    const stats = getSyncQueueStats();
    logSync({ direction: 'both', status: 'ok', records_pulled: pulled, records_pushed: pushed });
    setSetting('last_sync_at', new Date().toISOString());
    return { ok: true, pulled, pushed, queue: stats };
  } catch (err) {
    logSync({ direction: 'both', status: 'error', error_message: err.message, records_pulled: pulled, records_pushed: pushed });
    const queue = getSyncQueueStats();
    if (err?.type === 'auth_error') {
      return { ok: false, type: 'auth_error', message: 'API Token tidak valid. Silakan cek setting API.', queue };
    }
    return { ok: false, type: err?.type || 'unknown_error', message: err.message, queue };
  }
}

async function validateToken(token) {
  try {
    const res = await apiRequest('GET', '/api/auth.php', token);
    return { ok: true, token_info: res.token || null };
  } catch (err) {
    return { ok: false, message: err?.message || 'Token tidak valid', type: err?.type || 'unknown_error' };
  }
}

async function loginUser(username, password) {
  const token = String(getSetting('device_token', '') || '').trim();
  if (!token) return { ok: false, message: 'API Token belum diisi.' };
  try {
    const res = await apiRequest('POST', '/api/auth.php', token, { username, password });
    return { ok: true, user: res.user || null };
  } catch (err) {
    return { ok: false, message: err?.message || 'Login gagal', type: err?.type || 'unknown_error' };
  }
}

module.exports = { testConnection, pullFromServer, pushToServer, runSync, validateToken, loginUser };
