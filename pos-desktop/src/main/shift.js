const crypto = require('crypto');
const { initDb } = require('./db');
const { shiftAction } = require('./api');
const { localDateTimeString } = require('./time');
const { store } = require('./config');

function uuid() {
  return crypto.randomUUID();
}

function queueShift(action, payload, errorMessage) {
  const db = initDb();
  const offlineUuid = payload.offline_uuid || uuid();
  db.prepare(`INSERT OR IGNORE INTO shift_sync_queue (action, offline_uuid, payload_json, sync_status, error_message)
    VALUES (?,?,?,?,?)`).run(action, offlineUuid, JSON.stringify({ ...payload, offline_uuid: offlineUuid }), 'pending', errorMessage || null);
  return offlineUuid;
}

function localOpenShift(payload = {}, syncStatus = 'pending', serverShift = null) {
  const db = initDb();
  const now = localDateTimeString();
  const id = serverShift?.id || payload.local_id || (Date.now() * -1);
  const shiftCode = serverShift?.shift_code || `LOCAL-${String(Math.abs(id)).slice(-8)}`;
  db.prepare(`INSERT OR REPLACE INTO pos_shifts
    (id, shift_code, branch_id, opened_at, opened_by, opening_cash_default, opening_cash_actual, status, closed_at, closed_by, expected_cash_total, counted_cash_total, cash_difference, notes, offline_open_uuid, offline_close_uuid, sync_status, created_at, updated_at)
    VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?)`).run(
      id, shiftCode, serverShift?.branch_id || payload.branch_id || 1, serverShift?.opened_at || now, payload.user_id || serverShift?.opened_by || null,
      serverShift?.opening_cash_default ?? payload.opening_cash_actual ?? 0, serverShift?.opening_cash_actual ?? payload.opening_cash_actual ?? 0,
      serverShift?.status || 'open', serverShift?.closed_at || null, serverShift?.closed_by || null, serverShift?.expected_cash_total || null,
      serverShift?.counted_cash_total || null, serverShift?.cash_difference || null, serverShift?.notes || null,
      payload.offline_uuid || serverShift?.offline_open_uuid || null, serverShift?.offline_close_uuid || null, syncStatus, serverShift?.created_at || now, now
    );
  return db.prepare('SELECT * FROM pos_shifts WHERE id = ?').get(id);
}

function localCloseShift(payload = {}, syncStatus = 'pending') {
  const db = initDb();
  const active = db.prepare("SELECT * FROM pos_shifts WHERE status='open' ORDER BY opened_at DESC, id DESC LIMIT 1").get();
  if (!active) return null;
  const now = localDateTimeString();
  const counted = Number(payload.counted_cash_total || 0);
  db.prepare(`UPDATE pos_shifts SET status='closed', closed_at=?, closed_by=?, counted_cash_total=?, notes=?, offline_close_uuid=?, sync_status=?, updated_at=? WHERE id=?`)
    .run(now, payload.user_id || null, counted, payload.notes || '', payload.offline_uuid || null, syncStatus, now, active.id);
  return db.prepare('SELECT * FROM pos_shifts WHERE id = ?').get(active.id);
}

async function performShift(action, payload = {}) {
  const normalizedPayload = { branch_id: Number(payload.branch_id || store.get('branchId') || 1), ...payload, offline_uuid: payload.offline_uuid || uuid() };
  const resp = await shiftAction(action, normalizedPayload);
  if (resp?.ok) {
    const db = initDb();
    db.prepare('UPDATE shift_sync_queue SET sync_status = ?, synced_at = CURRENT_TIMESTAMP, error_message = NULL WHERE offline_uuid = ?')
      .run('synced', normalizedPayload.offline_uuid);
    if (action === 'open') localOpenShift(normalizedPayload, 'synced', resp.shift || null);
    if (action === 'close') localCloseShift(normalizedPayload, 'synced');
    return { ...resp, sync_status: 'synced' };
  }

  const message = resp?.message || 'Sync shift gagal';
  const offlineUuid = queueShift(action, normalizedPayload, message);
  if (action === 'open') localOpenShift({ ...normalizedPayload, offline_uuid: offlineUuid }, 'pending');
  if (action === 'close') localCloseShift({ ...normalizedPayload, offline_uuid: offlineUuid }, 'pending');
  return {
    ok: false,
    message,
    status: resp?.status || 500,
    sync_status: 'pending',
    offline_uuid: offlineUuid
  };
}

async function retryPendingShiftSync() {
  const db = initDb();
  const pending = db.prepare("SELECT * FROM shift_sync_queue WHERE sync_status = 'pending' ORDER BY id ASC").all();
  if (!pending.length) return { ok: true, synced: 0 };

  let synced = 0;
  for (const row of pending) {
    let payload;
    try {
      payload = JSON.parse(row.payload_json || '{}');
    } catch (_) {
      payload = { offline_uuid: row.offline_uuid };
    }

    const resp = await shiftAction(row.action, payload);
    if (resp?.ok) {
      db.prepare('UPDATE shift_sync_queue SET sync_status = ?, synced_at = CURRENT_TIMESTAMP, error_message = NULL WHERE id = ?')
        .run('synced', row.id);
      synced += 1;
    } else {
      db.prepare('UPDATE shift_sync_queue SET error_message = ? WHERE id = ?')
        .run(resp?.message || 'Sync shift gagal', row.id);
    }
  }
  return { ok: true, synced };
}

module.exports = { performShift, retryPendingShiftSync };
