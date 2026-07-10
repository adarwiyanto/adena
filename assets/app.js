(function(){
  const body = document.body;
  if (body) {
    const isAdmin = window.location.pathname.includes('/admin/');
    body.classList.add(isAdmin ? 'is-admin' : 'is-public');
  }

  const btn = document.querySelector('[data-toggle-sidebar]');
  const sidebar = document.querySelector('.sidebar');
  const mobileQuery = window.matchMedia('(max-width: 980px)');
  let overlay = document.querySelector('.sidebar-overlay');
  if (!overlay && sidebar) {
    overlay = document.createElement('div');
    overlay.className = 'sidebar-overlay';
    document.body.appendChild(overlay);
  }

  function closeMobileSidebar(){
    if (!sidebar) return;
    sidebar.classList.remove('mobile-open');
    document.body.classList.remove('sidebar-mobile-open');
  }

  function openMobileSidebar(){
    if (!sidebar) return;
    sidebar.classList.remove('collapsed');
    sidebar.classList.add('mobile-open');
    document.body.classList.add('sidebar-mobile-open');
  }

  function syncSidebarMode(){
    if (!sidebar) return;
    if (mobileQuery.matches) {
      sidebar.classList.remove('collapsed');
      closeMobileSidebar();
    } else {
      sidebar.classList.remove('mobile-open');
      document.body.classList.remove('sidebar-mobile-open');
      if (localStorage.getItem('sidebar_collapsed') === '1') sidebar.classList.add('collapsed');
    }
  }

  if (btn && sidebar){
    btn.addEventListener('click', () => {
      if (mobileQuery.matches) {
        if (sidebar.classList.contains('mobile-open')) closeMobileSidebar();
        else openMobileSidebar();
        return;
      }
      sidebar.classList.toggle('collapsed');
      localStorage.setItem('sidebar_collapsed', sidebar.classList.contains('collapsed') ? '1':'0');
    });
    syncSidebarMode();
    if (mobileQuery.addEventListener) mobileQuery.addEventListener('change', syncSidebarMode);
    else mobileQuery.addListener(syncSidebarMode);
  }
  if (overlay) overlay.addEventListener('click', closeMobileSidebar);
  document.addEventListener('keydown', (event) => {
    if (event.key === 'Escape') closeMobileSidebar();
  });

  function shouldFormatNumericInput(input){
    if (!input || input.closest('form[data-no-auto-number-format]')) return false;
    if (window.location.pathname.includes('/pos/')) return false;
    const type = (input.getAttribute('type') || 'text').toLowerCase();
    if (['hidden','date','time','datetime-local','file','checkbox','radio','password','email','tel','url','search','color'].includes(type)) return false;
    const key = `${input.name || ''} ${input.id || ''} ${input.className || ''}`.toLowerCase();
    if (!key.trim()) return false;
    if (/(percent|persen|rate_pct|pct|ratio|factor|konversi|conversion|decimal|latitude|longitude|phone|wa|token|code|kode|sku|barcode)/.test(key)) return false;
    return /(rupiah|harga|price|cost|amount|nominal|cash|bayar|paid|payment|total|subtotal|discount|diskon|fee|balance|saldo|debit|credit|kredit|opening|counted|expected|difference|selisih|qty|quantity|jumlah|stock|stok)/.test(key);
  }

  function isIntegerNumberInput(input){
    const key = `${input.name || ''} ${input.id || ''} ${input.className || ''}`.toLowerCase();
    return /(qty|quantity|jumlah|stock|stok|selisih|difference)/.test(key) || /(rupiah|harga|price|cost|amount|nominal|cash|bayar|paid|payment|total|subtotal|discount|diskon|fee|balance|saldo|debit|credit|kredit|opening|counted|expected)/.test(key);
  }

  function normalizeNumberString(value, integerOnly){
    let raw = String(value || '').trim();
    if (!raw) return '';
    raw = raw.replace(/,/g, '');
    raw = raw.replace(/[^0-9.\-]/g, '');
    const negative = raw.startsWith('-');
    raw = raw.replace(/-/g, '');
    const parts = raw.split('.');
    let intPart = parts.shift() || '0';
    let decPart = parts.join('');
    intPart = intPart.replace(/^0+(?=\d)/, '');
    if (integerOnly) decPart = '';
    const intFormatted = intPart.replace(/\B(?=(\d{3})+(?!\d))/g, ',');
    return (negative ? '-' : '') + intFormatted + (decPart ? `.${decPart}` : '');
  }

  function cleanFormattedNumber(value){
    return String(value || '').replace(/,/g, '');
  }


  document.querySelectorAll('form[data-confirm]').forEach((form) => {
    form.addEventListener('submit', (event) => {
      const message = form.getAttribute('data-confirm') || 'Yakin?';
      if (!window.confirm(message)) {
        event.preventDefault();
      }
    });
  });

  const numericInputs = Array.from(document.querySelectorAll('input')).filter(shouldFormatNumericInput);
  numericInputs.forEach((input) => {
    if ((input.getAttribute('type') || '').toLowerCase() === 'number') {
      input.setAttribute('type', 'text');
      input.setAttribute('inputmode', 'decimal');
    }
    const integerOnly = isIntegerNumberInput(input);
    input.value = normalizeNumberString(input.value, integerOnly);
    input.addEventListener('input', () => {
      const start = input.selectionStart;
      const beforeLength = input.value.length;
      input.value = normalizeNumberString(input.value, integerOnly);
      const diff = input.value.length - beforeLength;
      if (start !== null) {
        const pos = Math.max(0, start + diff);
        try { input.setSelectionRange(pos, pos); } catch (_) {}
      }
    });
    input.addEventListener('blur', () => {
      input.value = normalizeNumberString(input.value, integerOnly);
    });
  });

  document.querySelectorAll('form').forEach((form) => {
    form.addEventListener('submit', (event) => {
      if (event.defaultPrevented) return;
      form.querySelectorAll('input').forEach((input) => {
        if (shouldFormatNumericInput(input)) input.value = cleanFormattedNumber(input.value);
      });
    });
  });


  document.querySelectorAll('[data-print-window]').forEach((btn) => {
    btn.addEventListener('click', () => {
      window.print();
    });
  });

  document.querySelectorAll('[data-toggle-submenu]').forEach(b=>{
    b.addEventListener('click', ()=>{
      const sel = b.getAttribute('data-toggle-submenu');
      const target = sel ? document.querySelector(sel) : null;
      if (!target) return;
      target.classList.toggle('open');
    });
  });
})();
