'use strict';
const https = require('https');
const http  = require('http');
const {
  getSetting, setSetting, replaceAll, replaceServerSettings,
  replaceCashiers, getOrCreateDeviceId,
  upsertServerShift, getPendingTransactions, markTransactionSynced,
  markTransactionFailed,
  getPendingShifts, markShiftSynced, getPendingMovements, markMovementSynced,
  getLastSyncAt, logSync, db, getSyncQueueStats, logApiRequest, logSyncDebug,
} = require('./db');

function getBaseUrl() {
  const configured = String(getSetting('api_base_url', '') || '').trim();
  return configured || 'https://adena.co.id';
}

function maskToken(token) {
  const raw = String(token || '').trim();
  if (!raw) return '(kosong)';
  if (raw.length <= 10) return `${raw.slice(0, 2)}***${raw.slice(-2)}`;
  return `${raw.slice(0, 6)}******${raw.slice(-4)}`;
}

function safeJson(input, max = 1200) {
  if (input == null) return null;
  const text = typeof input === 'string' ? input : JSON.stringify(input);
  return String(text).replace(/\s+/g, ' ').trim().slice(0, max);
}

function sanitizePayload(payload) {
  if (!payload || typeof payload !== 'object') return payload;
  const copy = JSON.parse(JSON.stringify(payload));
  const mask = (obj, key) => {
    if (Object.prototype.hasOwnProperty.call(obj, key) && obj[key]) obj[key] = '[MASKED]';
  };
  mask(copy, 'token');
  mask(copy, 'password');
  mask(copy, 'api_token');
  if (Array.isArray(copy.transactions)) {
    copy.transactions = copy.transactions.map((tx) => ({
      offline_uuid: tx.offline_uuid,
      local_transaction_id: tx.local_transaction_id,
      local_device_id: tx.local_device_id,
      payment_method: tx.payment_method,
      payment_channel_id: tx.payment_channel_id || null,
      payment_channel_name: tx.payment_channel_name || null,
      total: tx.total,
      items_count: Array.isArray(tx.items) ? tx.items.length : 0,
    }));
  }
  return copy;
}

function apiRequest(method, path, token, body = null, extraHeaders = null) {
  return new Promise((resolve, reject) => {
    const url = new URL(getBaseUrl() + path);
    const lib = url.protocol === 'https:' ? https : http;
    const bodyStr = body ? JSON.stringify(body) : null;

    const opts = {
      hostname: url.hostname,
      port: url.port || (url.protocol === 'https:' ? 443 : 80),
      path: url.pathname + url.search,
      method,
      headers: {
        'Content-Type': 'application/json',
        'Authorization': token ? `Bearer ${token}` : '',
        ...(extraHeaders || {}),
        ...(bodyStr ? { 'Content-Length': Buffer.byteLength(bodyStr) } : {}),
      },
      timeout: 20000,
    };

    const reqSummary = {
      method,
      endpoint: url.pathname + url.search,
      token_masked: maskToken(token),
      payload: sanitizePayload(body),
    };

    const req = lib.request(opts, (res) => {
      let data = '';
      res.on('data', (c) => { data += c; });
      res.on('end', () => {
        const statusCode = res.statusCode || 0;
        let parsed = null;
        try { parsed = data ? JSON.parse(data) : null; } catch (_) { parsed = null; }

        const snippet = String(data || '').replace(/\s+/g, ' ').trim().slice(0, 600);
        logSyncDebug({
          direction: method,
          endpoint: url.pathname + url.search,
          http_status: statusCode,
          request_summary: safeJson(reqSummary),
          response_summary: parsed ? safeJson(parsed, 3000) : `non_json:${snippet}`,
          error_message: null,
        });

        if (!parsed) {
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
    console.info('[sync] request', { method, path: url.pathname + url.search, token: maskToken(token) });

    req.on('timeout', () => {
      req.destroy();
      const msg = 'Network error: request timeout';
      logApiRequest({ endpoint: url.pathname, status_code: 0, error_message: msg });
      logSyncDebug({
        direction: method,
        endpoint: url.pathname + url.search,
        http_status: 0,
        request_summary: safeJson(reqSummary),
        response_summary: null,
        error_message: msg,
      });
      const err = new Error(msg);
      err.type = 'network_error';
      reject(err);
    });
    req.on('error', (cause) => {
      const msg = `Network error: ${cause?.message || 'gagal menghubungi server'}`;
      logApiRequest({ endpoint: url.pathname, status_code: 0, error_message: msg });
      logSyncDebug({
        direction: method,
        endpoint: url.pathname + url.search,
        http_status: 0,
        request_summary: safeJson(reqSummary),
        response_summary: null,
        error_message: msg,
      });
      const err = new Error(msg);
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

  let channelsRes = [];
  try {
    channelsRes = await apiRequest('GET', '/api/payment_channels.php', token);
  } catch (_) {
    channelsRes = Array.isArray(d.payment_channels) ? d.payment_channels : [];
  }
  const channelRows = Array.isArray(channelsRes) ? channelsRes : (Array.isArray(channelsRes?.data) ? channelsRes.data : []);
  db().prepare('DELETE FROM payment_channels').run();
  if (channelRows.length) {
    replaceAll('payment_channels', channelRows.map((c) => ({
      id: c.id,
      payment_method: c.method || c.payment_method || null,
      channel_name: c.name || c.channel_name || c.bank_name || null,
      bank_name: c.name || c.bank_name || null,
      is_active: c.is_active == null ? 1 : (c.is_active ? 1 : 0),
      sort_order: c.sort_order || 0,
    })));
    pulled += channelRows.length;
  }

  if (Array.isArray(d.guides)) {
    db().prepare('DELETE FROM guides').run();
    if (d.guides.length) replaceAll('guides', d.guides.map((g) => ({ id: g.id, name: g.name, is_active: g.is_active == null ? 1 : (g.is_active ? 1 : 0) })));
  }
  if (Array.isArray(d.cashiers || d.users)) {
    replaceCashiers(d.cashiers || d.users || []);
  }
  if (Array.isArray(d.qris_banks)) {
    db().prepare('DELETE FROM qris_banks').run();
    if (d.qris_banks.length) replaceAll('qris_banks', d.qris_banks.map((b) => ({ id: b.id, name: b.name, sort_order: b.sort_order || 0, is_active: b.is_active ? 1 : 0 })));
  }
  if (d.settings) replaceServerSettings(d.settings);
  if (d.active_shift) upsertServerShift(d.active_shift);

  const pullSummary = {
    products: Array.isArray(d.products) ? d.products.length : 0,
    payment_methods: Array.isArray(d.payment_methods) ? d.payment_methods.length : 0,
    payment_channels: channelRows.length,
    qris_banks: Array.isArray(d.qris_banks) ? d.qris_banks.length : 0,
    guides: Array.isArray(d.guides) ? d.guides.length : 0,
    cashiers: Array.isArray(d.cashiers || d.users) ? (d.cashiers || d.users).length : 0,
  };
  logSyncDebug({
    direction: 'pull_master',
    endpoint: '/api/sync/pull.php',
    http_status: 200,
    request_summary: safeJson({ since: since || null, fullSync: !!fullSync }),
    response_summary: safeJson(pullSummary),
    error_message: null,
  });

  return { pulled, summary: pullSummary };
}

function normalizePushResults(response) {
  const transactions = {};
  const rawTx = response?.results?.transactions || response?.results?.transaction_results || response?.transactions || {};
  for (const [uuid, value] of Object.entries(rawTx)) {
    if (value && typeof value === 'object') {
      transactions[uuid] = {
        status: value.status || 'unknown',
        transaction_code: value.transaction_code || value.code || null,
        message: value.message || null,
      };
      continue;
    }
    if (typeof value === 'string') {
      if (value === 'exists') transactions[uuid] = { status: 'exists', transaction_code: null, message: null };
      else transactions[uuid] = { status: 'inserted', transaction_code: value, message: null };
    }
  }
  return {
    shifts: response?.results?.shifts || {},
    cash_movements: response?.results?.cash_movements || {},
    transactions,
    summary: response?.summary || null,
  };
}

async function pushToServer(token) {
  const pendingShifts = getPendingShifts();
  const pendingMovements = getPendingMovements();
  const pendingTx = getPendingTransactions();
  if (!pendingShifts.length && !pendingMovements.length && !pendingTx.length) return { pushed: 0, details: {} };

  const deviceId = getOrCreateDeviceId();
  const txPayload = pendingTx.map((tx) => ({
    offline_uuid: tx.offline_uuid,
    transaction_group_uuid: tx.transaction_group_uuid,
    shift_offline_uuid: tx.shift_offline_uuid,
    customer_id: tx.customer_id,
    user_id: tx.created_by,
    guide_id: tx.guide_id || null,
    guide_name: tx.guide_name,
    payment_method: tx.payment_method,
    payment_channel_id: tx.payment_channel_id || null,
    payment_channel_name: tx.payment_channel_name || tx.payment_bank || null,
    payment_bank: tx.payment_bank,
    items: JSON.parse(tx.items_json || '[]'),
    subtotal: tx.subtotal,
    tx_discount_amount: tx.tx_discount_amount,
    tx_discount_type: tx.tx_discount_type,
    total: tx.total,
    paid_amount: tx.paid_amount,
    change_amount: tx.change_amount || 0,
    sold_at: tx.sold_at,
    local_transaction_id: tx.local_transaction_id || tx.offline_uuid,
    local_device_id: tx.local_device_id || deviceId,
  }));

  const body = {
    shifts: pendingShifts.map((s) => ({ offline_uuid: s.offline_uuid, status: s.status, opened_at: s.opened_at, opening_cash_actual: s.opening_cash_actual, closed_at: s.closed_at, counted_cash_total: s.counted_cash_total, notes: s.notes, offline_close_uuid: s.status === 'closed' ? s.offline_uuid + '-close' : null })),
    cash_movements: pendingMovements.map((m) => ({ offline_uuid: m.offline_uuid, shift_offline_uuid: m.shift_offline_uuid, movement_type: m.movement_type, amount: m.amount, reason: m.reason, notes: m.notes, created_at: m.created_at })),
    transactions: txPayload,
  };

  const payloadSummary = {
    transaction_count: txPayload.length,
    offline_uuid_list: txPayload.map((tx) => tx.offline_uuid),
    totals: txPayload.map((tx) => ({
      offline_uuid: tx.offline_uuid,
      total: Number(tx.total || 0),
      payment_method: tx.payment_method,
      payment_channel: tx.payment_channel_name || tx.payment_bank || null,
    })),
  };
  logSyncDebug({
    direction: 'push_prepare',
    endpoint: '/api/sync/push.php?debug=1',
    http_status: 0,
    request_summary: safeJson(payloadSummary, 4000),
    response_summary: null,
    error_message: null,
  });

  let res;
  try {
    res = await apiRequest('POST', '/api/sync/push.php?debug=1', token, body, { 'X-Debug-Sync': '1' });
  } catch (err) {
    const reason = err?.message || 'Push gagal';
    if (err?.type !== 'auth_error') {
      for (const tx of pendingTx) markTransactionFailed(tx.offline_uuid, reason);
    }
    logSyncDebug({
      direction: 'push_result',
      endpoint: '/api/sync/push.php?debug=1',
      http_status: Number(err?.statusCode || 0),
      request_summary: safeJson(payloadSummary, 4000),
      response_summary: safeJson(err?.response || err?.debug || null, 3500),
      error_message: reason,
    });
    throw err;
  }

  const normalized = normalizePushResults(res);
  let pushed = 0;

  for (const [uuid, serverId] of Object.entries(normalized.shifts || {})) {
    markShiftSynced(uuid, serverId);
    pushed++;
  }
  for (const [uuid] of Object.entries(normalized.cash_movements || {})) {
    markMovementSynced(uuid, null);
    pushed++;
  }

  for (const tx of pendingTx) {
    const result = normalized.transactions[tx.offline_uuid];
    if (!result) continue;
    if (result.status === 'inserted' || result.status === 'exists' || result.transaction_code) {
      markTransactionSynced(tx.offline_uuid, result.transaction_code || 'exists');
      pushed++;
    } else {
      markTransactionFailed(tx.offline_uuid, result.message || 'Push transaksi gagal');
    }
  }

  logSyncDebug({
    direction: 'push_result',
    endpoint: '/api/sync/push.php?debug=1',
    http_status: 200,
    request_summary: safeJson(payloadSummary, 4000),
    response_summary: safeJson(res, 3500),
    error_message: null,
  });

  return { pushed, details: normalized };
}

async function runSync(fullSync = false) {
  const token = String(getSetting('device_token', '') || '').trim();
  if (!token) return { ok: false, type: 'auth_error', message: 'API Token belum diisi.' };

  let pulled = 0; let pushed = 0;
  try {
    const pushResult = await pushToServer(token);
    const pullResult = await pullFromServer(token, fullSync);
    pushed = Number(pushResult?.pushed || 0);
    pulled = Number(pullResult?.pulled || 0);
    const stats = getSyncQueueStats();
    logSync({ direction: 'both', status: 'ok', records_pulled: pulled, records_pushed: pushed });
    setSetting('last_sync_at', new Date().toISOString());
    return {
      ok: true,
      pulled,
      pushed,
      queue: stats,
      pull_summary: pullResult?.summary || {},
      push_summary: pushResult?.details?.summary || null,
      push_results: pushResult?.details?.transactions || {},
    };
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

module.exports = { testConnection, pullFromServer, pushToServer, runSync, validateToken, loginUser, getBaseUrl, maskToken };
