(function () {
  'use strict';
  const runtime = window.POS_RUNTIME || {};
  const state = window.POS_STATE || {};
  const key = runtime.cartStorageKey || 'adena_pos_cart_v2';
  const products = new Map((state.products || []).map((p) => [String(p.id), { id: Number(p.id), name: String(p.name || ''), price: Number(p.price || 0) }]));
  let cart = {};
  let dirty = false;
  let syncing = false;

  const parse = (raw, fallback) => { try { return JSON.parse(raw); } catch (_) { return fallback; } };
  const serverCart = () => Object.fromEntries((state.cartItems || []).filter((x) => !x.is_reward).map((x) => [String(x.id), Number(x.qty || 0)]));
  const load = () => {
    const local = parse(localStorage.getItem(key) || 'null', null);
    if (local && local.cart && local.dirty) { dirty = true; return local.cart; }
    return serverCart();
  };
  const save = () => localStorage.setItem(key, JSON.stringify({ cart, dirty, updated_at: Date.now() }));
  const money = (n) => 'Rp ' + Math.max(0, Number(n || 0)).toLocaleString('id-ID');
  const total = () => Object.entries(cart).reduce((sum, [id, qty]) => sum + ((products.get(id)?.price || 0) * Number(qty || 0)), 0);
  const count = () => Object.values(cart).reduce((sum, qty) => sum + Number(qty || 0), 0);
  const escapeHtml = (v) => String(v).replace(/[&<>'"]/g, (c) => ({'&':'&amp;','<':'&lt;','>':'&gt;',"'":'&#39;','"':'&quot;'}[c]));

  function itemHtml(id, qty) {
    const p = products.get(String(id));
    if (!p) return '';
    return `<div class="pos-cart-item" data-local-product-id="${p.id}">
      <div class="pos-cart-item-head"><div class="pos-cart-item-name">${escapeHtml(p.name)}</div><div class="pos-cart-item-price">${money(p.price)}</div></div>
      <div class="pos-cart-row"><div class="pos-qty">
        <button class="btn pos-qty-btn" type="button" data-local-cart-action="dec" data-product-id="${p.id}">−</button>
        <input class="pos-qty-input" type="number" value="${qty}" min="1" max="9999" step="1" inputmode="numeric" data-local-cart-qty data-product-id="${p.id}">
        <button class="btn pos-qty-btn" type="button" data-local-cart-action="inc" data-product-id="${p.id}">+</button>
      </div><div class="pos-cart-subtotal">${money(p.price * qty)}</div></div>
      <button class="btn pos-remove-btn" type="button" data-local-cart-action="remove" data-product-id="${p.id}">Hapus</button>
    </div>`;
  }

  function render() {
    const items = document.querySelector('[data-local-cart-items]');
    const empty = document.querySelector('[data-local-cart-empty]');
    const summary = document.querySelector('[data-local-cart-summary]');
    const countEl = document.querySelector('[data-local-cart-count]');
    const totalEl = document.querySelector('[data-local-cart-total]');
    const hasItems = count() > 0;
    if (items) { items.innerHTML = Object.entries(cart).filter(([,q]) => Number(q)>0).map(([id,q]) => itemHtml(id, Number(q))).join(''); items.hidden = !hasItems; }
    if (empty) empty.hidden = hasItems;
    if (summary) summary.hidden = !hasItems;
    if (countEl) countEl.textContent = `${count()} item`;
    if (totalEl) totalEl.textContent = money(total());
    runtime.cartTotal = total();
    state.cartItems = Object.entries(cart).map(([id, qty]) => ({...products.get(id), qty:Number(qty), subtotal:(products.get(id)?.price||0)*Number(qty), is_reward:false}));
    document.dispatchEvent(new CustomEvent('adena:cart-updated', { detail: { cart, total: total(), count: count() } }));
  }

  function mutate(id, mode, value) {
    id = String(id);
    if (!products.has(id)) return;
    const current = Number(cart[id] || 0);
    if (mode === 'add' || mode === 'inc') cart[id] = Math.min(9999, current + 1);
    if (mode === 'dec') current <= 1 ? delete cart[id] : cart[id] = current - 1;
    if (mode === 'remove') delete cart[id];
    if (mode === 'qty') { const q = Math.min(9999, Math.max(0, Number(value || 0))); q ? cart[id] = q : delete cart[id]; }
    dirty = true; save(); render();
  }

  async function syncCart(options) {
    options = options || {};
    if (!dirty && !options.force) return { ok:true, skipped:true };
    if (syncing || !navigator.onLine) return { ok:false, skipped:true };
    syncing = true;
    try {
      const response = await fetch(runtime.cartStateUrl || 'cart_state.php', {
        method: 'POST', headers: {'Content-Type':'application/json','X-Requested-With':'XMLHttpRequest'},
        credentials: 'same-origin', body: JSON.stringify({_csrf:runtime.csrf || '', cart})
      });
      const body = await response.json();
      if (!response.ok || !body.ok) throw new Error(body.error || 'Sinkronisasi keranjang gagal');
      dirty = false; save(); return body;
    } finally { syncing = false; }
  }

  document.addEventListener('submit', function (event) {
    const form = event.target;
    if (!(form instanceof HTMLFormElement)) return;
    const action = form.querySelector('input[name="action"]')?.value;
    if (form.classList.contains('pos-product-action') && action === 'add') {
      event.preventDefault(); mutate(form.querySelector('[name="product_id"]')?.value, 'add'); return;
    }
    if (action === 'new_transaction') {
      cart = {}; dirty = true; save();
      if (document.querySelector('[data-local-cart-items]')) { event.preventDefault(); render(); syncCart({force:true}); }
      return;
    }
    if (form.matches('[data-checkout-form]')) {
      if (!navigator.onLine) return;
      if (form.dataset.cartReady === '1') { form.dataset.cartReady = ''; return; }
      event.preventDefault();
      const button = form.querySelector('[type="submit"]'); if (button) button.disabled = true;
      syncCart({force:true}).then((result) => {
        if (!result.ok && !navigator.onLine) throw new Error('Checkout memerlukan koneksi untuk validasi stok.');
        form.dataset.cartReady = '1'; form.submit();
      }).catch((e) => alert(e.message || 'Keranjang gagal disiapkan untuk checkout.')).finally(() => { if (button) button.disabled = false; });
    }
  }, true);

  document.addEventListener('click', function (event) {
    const button = event.target.closest('[data-local-cart-action]');
    if (!button) return;
    mutate(button.dataset.productId, button.dataset.localCartAction);
  });
  document.addEventListener('change', function (event) {
    const input = event.target.closest('[data-local-cart-qty]');
    if (input) mutate(input.dataset.productId, 'qty', input.value);
  });
  window.addEventListener('online', () => syncCart());
  window.addEventListener('pagehide', () => { if (dirty && navigator.sendBeacon) navigator.sendBeacon(runtime.cartStateUrl || 'cart_state.php', new Blob([JSON.stringify({_csrf:runtime.csrf || '', cart})], {type:'application/json'})); });
  document.addEventListener('DOMContentLoaded', function () { cart = load(); save(); render(); setInterval(() => syncCart(), 60000); });
  window.POSLocalCart = { syncNow: () => syncCart({force:true}), getCart: () => ({...cart}) };
})();
