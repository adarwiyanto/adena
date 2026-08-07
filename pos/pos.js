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

  const bankRequiredMethods = new Set(['qris', 'edc', 'transfer']);
  const bankWrap = document.querySelector('#pos-bank-wrap');
  const bankSelect = document.querySelector('#pos-payment-bank');
  const cashWrap = document.querySelector('#pos-cash-wrap');
  const cashInput = document.querySelector('#pos-cash-received');
  const cashChange = document.querySelector('#pos-cash-change');
  const isCashMethod = () => { const code = selectedPaymentCode(); return code === 'cash' || code === 'tunai' || code.includes('cash') || code.includes('tunai'); };
  const formatRupiah = (value) => 'Rp ' + Math.max(0, Number(value || 0)).toLocaleString('id-ID');

  const selectedPaymentCode = () => {
    const checkedPayment = document.querySelector('input[name="payment_method"]:checked');
    return checkedPayment ? String(checkedPayment.value || '').toLowerCase() : '';
  };
  const paymentNeedsBank = () => bankRequiredMethods.has(selectedPaymentCode());
  const updateCashVisibility = () => {
    if (!cashWrap || !cashInput || !cashChange) return;
    const needsCash = isCashMethod();
    cashWrap.style.display = needsCash ? '' : 'none';
    cashInput.required = needsCash;
    if (!needsCash) { cashInput.value = ''; cashChange.textContent = 'Kembalian: Rp 0'; cashChange.classList.remove('negative'); return; }
    const total = Number((window.POS_RUNTIME && window.POS_RUNTIME.cartTotal) || 0);
    const diff = Number(cashInput.value || 0) - total;
    cashChange.textContent = diff >= 0 ? ('Kembalian: ' + formatRupiah(diff)) : ('Kurang: ' + formatRupiah(Math.abs(diff)));
    cashChange.classList.toggle('negative', diff < 0);
  };
  const updateBankVisibility = () => {
    if (!bankWrap) return;
    const needsBank = paymentNeedsBank();
    bankWrap.style.display = needsBank ? '' : 'none';
    if (bankSelect) {
      bankSelect.required = needsBank;
      if (!needsBank) bankSelect.value = '';
    }
  };
  paymentOptions.forEach((option) => option.addEventListener('change', () => { updateBankVisibility(); updateCashVisibility(); }));
  if (cashInput) cashInput.addEventListener('input', updateCashVisibility);
  updateBankVisibility();
  updateCashVisibility();

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
    const text = await res.text();
    let body;
    try {
      body = text ? JSON.parse(text) : {};
    } catch (error) {
      console.error('[shift:web] invalid response', text);
      throw new Error('Server shift mengirim response tidak valid. Cek error PHP/server.');
    }
    if (!res.ok || !body.ok) throw new Error(body.error || body.message || `Gagal (${res.status})`);
    return body;
  };

  if (openShiftForm) {
    openShiftForm.addEventListener('submit', async (e) => {
      e.preventDefault();
      const submitBtn = openShiftForm.querySelector('button[type="submit"]');
      const fd = new FormData(openShiftForm);
      if (submitBtn) submitBtn.disabled = true;
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
        console.error('[shift:web:open] failed', err);
        alert(err.message || 'Gagal buka shift');
      } finally {
        if (submitBtn) submitBtn.disabled = false;
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
      const submitBtn = closeShiftForm.querySelector('button[type="submit"]');
      const fd = new FormData(closeShiftForm);
      if (submitBtn) submitBtn.disabled = true;
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
        console.error('[shift:web:close] failed', err);
        alert(err.message || 'Gagal tutup shift');
      } finally {
        if (submitBtn) submitBtn.disabled = false;
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

      const paymentBank = bankSelect ? String(bankSelect.value || '').trim() : '';
      if (isCashMethod()) {
        const total = Number((window.POS_RUNTIME && window.POS_RUNTIME.cartTotal) || 0);
        const received = Number(cashInput ? cashInput.value || 0 : 0);
        if (received < total) {
          e.preventDefault();
          alert('Uang diterima kurang dari total belanja.');
          return;
        }
      }
      if (paymentNeedsBank() && paymentBank === '') {
        e.preventDefault();
        alert('Pilih bank terlebih dahulu.');
        return;
      }

      const nativeLocalFirst = document.body && document.body.dataset.androidApp === '1' && window.AndroidBridge && typeof window.AndroidBridge.enqueueSync === 'function';
      if (navigator.onLine && !nativeLocalFirst) return;

      if (!window.POSOfflineSync) return;
      e.preventDefault();
      try {
        const queued = window.POSOfflineSync.queueSaleFromCurrentForm(checkoutForm);
        const offlineUuidInput = checkoutForm.querySelector('input[name="offline_uuid"]');
        const groupUuidInput = checkoutForm.querySelector('input[name="transaction_group_uuid"]');
        if (offlineUuidInput) offlineUuidInput.value = queued.queue.offline_uuid;
        if (groupUuidInput) groupUuidInput.value = queued.queue.payload.transaction_group_uuid || queued.queue.offline_uuid;

        if (window.POSLocalCart && typeof window.POSLocalCart.commitSale === 'function') window.POSLocalCart.commitSale();
        else if (window.POSLocalCart && typeof window.POSLocalCart.clear === 'function') window.POSLocalCart.clear();

        // Cetak langsung dari data lokal; proses Bluetooth berjalan async di Android.
        if (window.AndroidBridge && typeof window.AndroidBridge.printReceipt === 'function') {
          try {
            const r = queued.receipt || {};
            const payload = {
              document_type: 'receipt', receipt_id: r.id || queued.queue.offline_uuid,
              tanggal_jam: r.time || new Date().toLocaleString('id-ID'), cashier: r.cashier || 'Kasir',
              store_name: (window.POS_RUNTIME && window.POS_RUNTIME.storeName) || 'Adena POS',
              store_subtitle: (window.POS_RUNTIME && window.POS_RUNTIME.storeSubtitle) || '',
              store_address: (window.POS_RUNTIME && window.POS_RUNTIME.storeAddress) || '',
              store_phone: (window.POS_RUNTIME && window.POS_RUNTIME.storePhone) || '',
              footer: (window.POS_RUNTIME && window.POS_RUNTIME.receiptFooter) || '',
              logo_url: (window.POS_RUNTIME && window.POS_RUNTIME.storeLogoUrl) || '',
              payment_method: r.payment || '', total: Number(r.total || 0),
              bayar: Number(cashInput ? cashInput.value || r.total || 0 : r.total || 0),
              kembalian: Math.max(0, Number(cashInput ? cashInput.value || 0 : 0) - Number(r.total || 0)),
              paper_width: 58, items: r.items || []
            };
            window.AndroidBridge.printReceipt(JSON.stringify(payload));
          } catch (_) {}
        }

        alert(nativeLocalFirst ? 'Transaksi tersimpan di database Android.' : 'Transaksi tersimpan offline.');
        if (navigator.onLine && nativeLocalFirst) {
          setTimeout(() => window.POSOfflineSync.syncNow().catch(() => {}), 50);
        }
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

(function(){
  const normalizePhone=(v)=>{let d=String(v||'').replace(/\D+/g,'');if(!d)return '';if(d.startsWith('0'))d='62'+d.slice(1);else if(!d.startsWith('62'))d='62'+d;return d;};
  document.addEventListener('DOMContentLoaded',()=>{
    const phone=document.getElementById('pos-customer-phone'),name=document.getElementById('pos-customer-name'),gender=document.getElementById('pos-customer-gender'),status=document.getElementById('pos-customer-status'); if(!phone)return; let timer;
    const setStatus=(text,cls)=>{status.textContent=text;status.className='pos-customer-status '+(cls||'');};
    phone.addEventListener('input',()=>{clearTimeout(timer);const normalized=normalizePhone(phone.value);phone.dataset.normalized=normalized;if(normalized.length<9){setStatus('Masukkan nomor HP untuk mengecek membership.');return;} timer=setTimeout(async()=>{
      const cache=JSON.parse(localStorage.getItem('pos_customer_cache_v1')||'{}');
      try{if(!navigator.onLine)throw new Error('offline');const r=await fetch(window.POS_RUNTIME.customerLookupUrl+'?phone='+encodeURIComponent(normalized),{headers:{'X-Requested-With':'XMLHttpRequest'}});const b=await r.json();if(b.found){name.value=b.customer.name||'';gender.value=b.customer.gender||'';cache[normalized]=b.customer;localStorage.setItem('pos_customer_cache_v1',JSON.stringify(cache));setStatus('Membership ditemukan: '+(b.customer.name||''),'is-found');}else{setStatus('Nomor belum terdaftar. Isi nama dan jenis kelamin.','is-new');}}catch(e){const c=cache[normalized];if(c){name.value=c.name||'';gender.value=c.gender||'';setStatus('Membership ditemukan dari cache offline: '+(c.name||''),'is-found');}else setStatus('Offline: nomor belum ada di cache perangkat.','is-new');}
    },350);});
  });
})();
