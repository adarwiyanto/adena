(function () {
  const QUEUE_KEY = 'pos_offline_queue_v1';
  const RECEIPT_KEY = 'pos_offline_receipts_v1';

  const parseJson = (raw, fallback) => {
    try { return JSON.parse(raw); } catch (e) { return fallback; }
  };

  const loadQueue = () => parseJson(localStorage.getItem(QUEUE_KEY) || '[]', []);
  const saveQueue = (queue) => localStorage.setItem(QUEUE_KEY, JSON.stringify(queue));
  const loadReceipts = () => parseJson(localStorage.getItem(RECEIPT_KEY) || '[]', []);
  const saveReceipts = (rows) => localStorage.setItem(RECEIPT_KEY, JSON.stringify(rows));

  const uuid = () => {
    if (window.crypto && window.crypto.randomUUID) return window.crypto.randomUUID();
    return 'of-' + Date.now() + '-' + Math.random().toString(16).slice(2);
  };

  const enqueue = (entityType, payload) => {
    const queue = loadQueue();
    const item = {
      local_id: Date.now() + '-' + Math.random().toString(16).slice(2),
      offline_uuid: uuid(),
      entity_type: entityType,
      payload: payload || {},
      created_at: new Date().toISOString(),
      sync_status: 'pending',
      retry_count: 0,
      last_error: '',
    };
    queue.push(item);
    saveQueue(queue);
    updateIndicators();
    return item;
  };

  const updateIndicators = () => {
    const queue = loadQueue();
    const pending = queue.filter((q) => q.sync_status !== 'synced').length;
    document.querySelectorAll('[data-sync-count]').forEach((el) => {
      el.textContent = String(pending);
    });
    document.querySelectorAll('[data-online-badge]').forEach((el) => {
      el.textContent = navigator.onLine ? 'ONLINE' : 'OFFLINE';
      el.classList.toggle('is-online', navigator.onLine);
      el.classList.toggle('is-offline', !navigator.onLine);
    });
  };

  const markSynced = (offlineUuid) => {
    const queue = loadQueue();
    const idx = queue.findIndex((q) => q.offline_uuid === offlineUuid);
    if (idx >= 0) {
      queue[idx].sync_status = 'synced';
      queue[idx].last_error = '';
      queue[idx].retry_count = (queue[idx].retry_count || 0) + 1;
      saveQueue(queue.filter((q) => q.sync_status !== 'synced'));
      updateIndicators();
    }
  };

  const markFailed = (offlineUuid, message) => {
    const queue = loadQueue();
    const idx = queue.findIndex((q) => q.offline_uuid === offlineUuid);
    if (idx >= 0) {
      queue[idx].sync_status = 'failed';
      queue[idx].last_error = message || 'Sync gagal';
      queue[idx].retry_count = (queue[idx].retry_count || 0) + 1;
      saveQueue(queue);
      updateIndicators();
    }
  };

  const syncNow = async () => {
    if (!navigator.onLine) return { ok: false, error: 'offline' };
    const queue = loadQueue().filter((q) => q.sync_status !== 'synced');
    if (!queue.length) return { ok: true, synced: 0 };

    try {
      const csrf = (window.POS_RUNTIME && window.POS_RUNTIME.csrf) || '';
      const res = await fetch((window.POS_RUNTIME && window.POS_RUNTIME.syncUrl) || 'sync.php', {
        method: 'POST',
        headers: {
          'Content-Type': 'application/json',
          'X-Requested-With': 'XMLHttpRequest'
        },
        body: JSON.stringify({ _csrf: csrf, items: queue.map((q) => ({ entity_type: q.entity_type, offline_uuid: q.offline_uuid, payload: q.payload })) })
      });
      const body = await res.json();
      if (!body.ok) throw new Error(body.error || 'Sync error');

      (body.results || []).forEach((r) => {
        if (['success', 'duplicate'].includes(r.status)) {
          markSynced(r.offline_uuid);
        } else {
          markFailed(r.offline_uuid, r.message || 'Sync gagal');
        }
      });
      return { ok: true, synced: (body.results || []).length };
    } catch (e) {
      return { ok: false, error: e.message || 'sync failed' };
    }
  };

  const queueSaleFromCurrentForm = (form) => {
    const state = window.POS_STATE || {};
    const cartItems = (state.cartItems || []).filter((it) => !it.is_reward).map((it) => ({
      product_id: Number(it.id),
      qty: Number(it.qty),
      price_each: Number(it.price),
    }));
    if (!cartItems.length) throw new Error('Keranjang kosong.');

    const paymentMethodInput = form.querySelector('input[name="payment_method"]:checked');
    const paymentMethod = paymentMethodInput ? paymentMethodInput.value : 'cash';

    const transactionCode = 'TRX-LOCAL-' + Date.now();
    const receipt = {
      id: transactionCode,
      time: new Date().toLocaleString('id-ID'),
      cashier: (window.POS_RUNTIME && window.POS_RUNTIME.userName) || 'Kasir',
      payment: paymentMethod,
      items: cartItems.map((it) => ({
        name: (state.productNames && state.productNames[String(it.product_id)]) || ('Produk #' + it.product_id),
        qty: it.qty,
        price: it.price_each,
        subtotal: it.qty * it.price_each,
      })),
      total: cartItems.reduce((sum, it) => sum + (it.qty * it.price_each), 0)
    };

    const txUuid = uuid();
    const queued = enqueue('sale', {
      transaction_code: transactionCode,
      transaction_group_uuid: txUuid,
      payment_method: paymentMethod,
      items: cartItems,
    });

    const receipts = loadReceipts();
    receipts.unshift({
      offline_uuid: queued.offline_uuid,
      receipt,
      created_at: new Date().toISOString(),
      sync_status: 'pending'
    });
    saveReceipts(receipts.slice(0, 50));

    return { queue: queued, receipt };
  };

  window.POSOfflineSync = {
    enqueue,
    syncNow,
    updateIndicators,
    queueSaleFromCurrentForm,
    loadQueue,
    loadReceipts,
  };

  window.addEventListener('online', () => {
    updateIndicators();
    syncNow();
  });
  window.addEventListener('offline', updateIndicators);
  document.addEventListener('DOMContentLoaded', () => {
    updateIndicators();
    setInterval(() => {
      if (navigator.onLine) syncNow();
    }, 15000);
  });
})();
