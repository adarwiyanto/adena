document.addEventListener('DOMContentLoaded', () => {
  const input = document.querySelector('#pos-search');
  const cards = Array.from(document.querySelectorAll('.pos-product-card'));
  const empty = document.querySelector('#pos-empty');
  const printBtn = document.querySelector('[data-print-receipt]');
  const paymentOptions = Array.from(document.querySelectorAll('input[name="payment_method"]'));

  const shiftModal = document.querySelector('[data-shift-modal]');
  const cashModal = document.querySelector('[data-cash-modal]');
  const closeShiftModal = document.querySelector('.pos-modal[data-close-modal]');
  const openShiftBtn = document.querySelector('[data-open-shift-modal]');
  const openCashBtn = document.querySelector('[data-open-cash-modal]');
  const openCloseBtn = document.querySelector('[data-open-close-modal]');
  const manualSyncBtn = document.querySelector('[data-manual-sync]');
  const openShiftForm = document.querySelector('[data-open-shift-form]');
  const cashForm = document.querySelector('[data-cash-form]');
  const closeShiftForm = document.querySelector('[data-close-shift-form]');
  const checkoutForm = document.querySelector('[data-checkout-form]');

  const qrisBankWrap = document.querySelector('#pos-qris-bank-wrap');
  const updateQrisBankVisibility = () => {
    if (!qrisBankWrap) return;
    const checkedPayment = document.querySelector('input[name="payment_method"]:checked');
    const selectedCode = checkedPayment ? String(checkedPayment.value || '').toLowerCase() : '';
    qrisBankWrap.style.display = selectedCode === 'qris' ? '' : 'none';
  };
  paymentOptions.forEach((option) => option.addEventListener('change', updateQrisBankVisibility));
  updateQrisBankVisibility();

  const showModal = (modal) => { if (modal) modal.hidden = false; };
  const hideModals = () => document.querySelectorAll('.pos-modal').forEach((m) => { m.hidden = true; });
  document.querySelectorAll('[data-dismiss-modal]').forEach((btn) => btn.addEventListener('click', hideModals));
  if (openShiftBtn) openShiftBtn.addEventListener('click', () => showModal(shiftModal));
  if (openCashBtn) openCashBtn.addEventListener('click', () => showModal(cashModal));
  if (openCloseBtn) {
    openCloseBtn.addEventListener('click', () => {
      const hasShift = !!(window.POS_RUNTIME && window.POS_RUNTIME.hasActiveShift);
      if (!hasShift) return;
      showModal(closeShiftModal);
    });
  }

  const initialShiftState = (window.POS_RUNTIME && window.POS_RUNTIME.shiftState) || 'no_active_shift';
  if (initialShiftState === 'no_active_shift') {
    hideModals();
  }

  const postShiftAction = async (action, payload = {}) => {
    const form = new FormData();
    form.append('_csrf', (window.POS_RUNTIME && window.POS_RUNTIME.csrf) || '');
    form.append('action', action);
    Object.keys(payload).forEach((k) => form.append(k, payload[k]));
    const res = await fetch((window.POS_RUNTIME && window.POS_RUNTIME.shiftApiUrl) || 'shift_api.php', {
      method: 'POST',
      body: form,
      headers: { 'X-Requested-With': 'XMLHttpRequest' }
    });
    const body = await res.json();
    if (!body.ok) throw new Error(body.error || 'Gagal');
    return body;
  };

  if (openShiftForm) {
    openShiftForm.addEventListener('submit', async (e) => {
      e.preventDefault();
      const fd = new FormData(openShiftForm);
      try {
        if (!navigator.onLine) {
          if (window.POSOfflineSync) {
            window.POSOfflineSync.enqueue('shift_open', { opening_cash_actual: Number(fd.get('opening_cash_actual') || 0) });
            alert('Offline: pembukaan shift dimasukkan antrian sync.');
            hideModals();
            return;
          }
        }
        await postShiftAction('open', {
          opening_cash_actual: fd.get('opening_cash_actual') || '0'
        });
        window.location.reload();
      } catch (err) {
        alert(err.message);
      }
    });
  }

  if (cashForm) {
    cashForm.addEventListener('submit', async (e) => {
      e.preventDefault();
      const fd = new FormData(cashForm);
      try {
        if (!navigator.onLine && window.POSOfflineSync) {
          window.POSOfflineSync.enqueue('cash_movement', {
            movement_type: String(fd.get('movement_type') || ''),
            amount: Number(fd.get('amount') || 0),
            reason: String(fd.get('reason') || ''),
            notes: String(fd.get('notes') || ''),
          });
          alert('Offline: kas masuk/keluar ditaruh di antrian sync.');
          hideModals();
          return;
        }
        await postShiftAction('cash_movement', {
          movement_type: fd.get('movement_type') || '',
          amount: fd.get('amount') || '0',
          reason: fd.get('reason') || '',
          notes: fd.get('notes') || '',
        });
        window.location.reload();
      } catch (err) {
        alert(err.message);
      }
    });
  }

  if (closeShiftForm) {
    closeShiftForm.addEventListener('submit', async (e) => {
      e.preventDefault();
      const fd = new FormData(closeShiftForm);
      try {
        const queueCount = window.POSOfflineSync ? window.POSOfflineSync.loadQueue().length : 0;
        if (!navigator.onLine && window.POSOfflineSync) {
          window.POSOfflineSync.enqueue('shift_close', {
            counted_cash_total: Number(fd.get('counted_cash_total') || 0),
            notes: String(fd.get('notes') || ''),
            pending_queue_count: queueCount,
          });
          alert('Offline: closing shift masuk antrian sync.');
          hideModals();
          return;
        }
        await postShiftAction('close', {
          counted_cash_total: fd.get('counted_cash_total') || '0',
          notes: fd.get('notes') || '',
          sync_status: queueCount > 0 ? 'partial' : 'synced',
        });
        window.location.reload();
      } catch (err) {
        alert(err.message);
      }
    });
  }

  if (manualSyncBtn) {
    manualSyncBtn.addEventListener('click', async () => {
      if (!window.POSOfflineSync) return;
      const result = await window.POSOfflineSync.syncNow();
      if (result.ok) {
        alert(`Sync selesai (${result.synced || 0} item).`);
        if ((result.synced || 0) > 0) window.location.reload();
      } else {
        alert('Sync gagal: ' + (result.error || 'unknown'));
      }
    });
  }

  if (checkoutForm) {
    checkoutForm.addEventListener('submit', async (e) => {
      const hasShift = !!(window.POS_RUNTIME && window.POS_RUNTIME.hasActiveShift);
      if (!hasShift) {
        e.preventDefault();
        alert('Shift belum aktif. Buka shift terlebih dahulu.');
        return;
      }

      const paymentMethodInput = checkoutForm.querySelector('input[name="payment_method"]:checked');
      const paymentBankInput = checkoutForm.querySelector('input[name="payment_bank"]:checked');
      if (paymentMethodInput && paymentMethodInput.value === 'qris' && !paymentBankInput) {
        e.preventDefault();
        alert('Pilih bank QRIS terlebih dahulu.');
        return;
      }

      if (navigator.onLine) return;

      if (!window.POSOfflineSync) return;
      e.preventDefault();
      try {
        const queued = window.POSOfflineSync.queueSaleFromCurrentForm(checkoutForm);
        const offlineUuidInput = checkoutForm.querySelector('input[name="offline_uuid"]');
        const groupUuidInput = checkoutForm.querySelector('input[name="transaction_group_uuid"]');
        if (offlineUuidInput) offlineUuidInput.value = queued.queue.offline_uuid;
        if (groupUuidInput) groupUuidInput.value = queued.queue.payload.transaction_group_uuid || queued.queue.offline_uuid;

        alert('Transaksi tersimpan offline. Jangan refresh halaman sebelum koneksi kembali.');
        const cartList = document.querySelector('.pos-cart-items');
        if (cartList) cartList.innerHTML = '<div class="pos-empty-cart"><strong>Transaksi offline berhasil disimpan.</strong><br><small>Silakan sinkronkan saat online.</small></div>';
      } catch (err) {
        alert(err.message || 'Gagal menyimpan transaksi offline');
      }
    });
  }

  if (printBtn) {
    printBtn.addEventListener('click', () => {
      window.print();
    });
  }


  if (!input || !cards.length) return;

  const normalize = (value) => value.toLowerCase().trim();

  const filterProducts = () => {
    const query = normalize(input.value);
    let visibleCount = 0;

    cards.forEach((card) => {
      const name = card.dataset.name || '';
      const match = name.includes(query);
      card.style.display = match ? '' : 'none';
      if (match) visibleCount += 1;
    });

    if (empty) {
      empty.style.display = visibleCount ? 'none' : 'block';
    }
  };

  input.addEventListener('input', filterProducts);
  filterProducts();
});
