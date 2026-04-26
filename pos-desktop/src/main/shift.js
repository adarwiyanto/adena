const crypto = require('crypto');
const { initDb } = require('./db');
const { shiftAction } = require('./api');

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

async function performShift(action, payload = {}) {
  const normalizedPayload = { ...payload, offline_uuid: payload.offline_uuid || uuid() };
  const resp = await shiftAction(action, normalizedPayload);
  if (resp?.ok) {
    const db = initDb();
    db.prepare('UPDATE shift_sync_queue SET sync_status = ?, synced_at = CURRENT_TIMESTAMP, error_message = NULL WHERE offline_uuid = ?')
      .run('synced', normalizedPayload.offline_uuid);
    return { ...resp, sync_status: 'synced' };
  }

  const message = resp?.message || 'Sync shift gagal';
  const offlineUuid = queueShift(action, normalizedPayload, message);
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
