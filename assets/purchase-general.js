(() => {
  'use strict';

  const page = document.querySelector('.pg-page');
  if (!page) return;

  const form = page.querySelector('[data-purchase-form]');
  const itemsContainer = page.querySelector('[data-items-container]');
  const addButton = page.querySelector('[data-add-item]');
  const template = document.getElementById('pg-item-template');
  const supplierField = page.querySelector('[data-supplier-field]');
  const supplierSelect = page.querySelector('[data-supplier-select]');
  const grandTotal = page.querySelector('[data-grand-total]');
  let sequence = Date.now();

  const money = new Intl.NumberFormat('id-ID', {
    style: 'currency',
    currency: 'IDR',
    maximumFractionDigits: 0,
  });

  const asNumber = (value) => {
    const normalized = String(value ?? '')
      .trim()
      .replace(/(?:Rp|IDR)/gi, '')
      .replace(/\s+/g, '')
      .replace(/,/g, '')
      .replace(/[^0-9.-]/g, '');
    const parsed = Number.parseFloat(normalized);
    return Number.isFinite(parsed) ? parsed : 0;
  };

  const itemCards = () => Array.from(itemsContainer.querySelectorAll('[data-item-card]'));

  const refreshNumbers = () => {
    itemCards().forEach((card, index) => {
      const number = card.querySelector('[data-item-number]');
      if (number) number.textContent = String(index + 1);
    });
  };

  const updateCard = (card) => {
    const typeSelect = card.querySelector('[data-item-type]');
    const type = typeSelect ? typeSelect.value : 'product';
    card.dataset.itemTypeValue = type;
    const label = typeSelect?.selectedOptions?.[0]?.textContent?.trim() || 'Item Pembelian';
    const title = card.querySelector('[data-item-title]');
    if (title) title.textContent = label;

    card.querySelectorAll('[data-show-for]').forEach((field) => {
      const types = String(field.dataset.showFor || '').split(/\s+/).filter(Boolean);
      const visible = types.includes(type);
      field.hidden = !visible;
      field.querySelectorAll('input,select,textarea').forEach((control) => {
        control.disabled = !visible;
      });
    });

    const productSelect = card.querySelector('[data-product-select]');
    if (productSelect) productSelect.required = type === 'product';
    const description = card.querySelector('[data-description]');
    if (description) {
      description.required = ['office_supplies', 'kitchen_project', 'service', 'transport', 'maintenance', 'other'].includes(type);
    }
    updateTotals();
    updateSupplierVisibility();
  };

  const updateSupplierVisibility = () => {
    const hasProduct = itemCards().some((card) => card.querySelector('[data-item-type]')?.value === 'product');
    if (supplierField) supplierField.hidden = !hasProduct;
    if (supplierSelect) {
      supplierSelect.disabled = !hasProduct;
      supplierSelect.required = hasProduct;
    }
  };

  const updateTotals = () => {
    let total = 0;
    itemCards().forEach((card) => {
      const qty = asNumber(card.querySelector('[data-qty]')?.value);
      const cost = asNumber(card.querySelector('[data-unit-cost]')?.value);
      const line = qty * cost;
      total += line;
      const target = card.querySelector('[data-line-total]');
      if (target) target.textContent = money.format(line);
    });
    if (grandTotal) grandTotal.textContent = money.format(total);
  };

  const updateFileList = (input) => {
    const target = input.closest('.pg-evidence-field')?.querySelector('[data-file-list]');
    if (!target) return;
    const names = Array.from(input.files || []).map((file) => file.name);
    target.textContent = names.length ? names.join(', ') : 'Belum ada file dipilih.';
  };

  const bindCard = (card) => {
    card.querySelector('[data-item-type]')?.addEventListener('change', () => updateCard(card));
    card.querySelector('[data-remove-item]')?.addEventListener('click', () => {
      if (itemCards().length <= 1) {
        window.alert('Minimal satu item harus tersedia.');
        return;
      }
      card.remove();
      refreshNumbers();
      updateSupplierVisibility();
      updateTotals();
    });
    card.querySelectorAll('[data-qty],[data-unit-cost]').forEach((input) => {
      input.addEventListener('input', updateTotals);
    });
    card.querySelector('[data-evidence]')?.addEventListener('change', (event) => updateFileList(event.currentTarget));
    updateCard(card);
  };

  addButton?.addEventListener('click', () => {
    if (!template) return;
    sequence += 1;
    const wrapper = document.createElement('div');
    wrapper.innerHTML = template.innerHTML.replaceAll('__INDEX__', String(sequence)).trim();
    const card = wrapper.firstElementChild;
    if (!card) return;
    itemsContainer.appendChild(card);
    bindCard(card);
    refreshNumbers();
    card.scrollIntoView({ behavior: 'smooth', block: 'center' });
  });

  itemCards().forEach(bindCard);
  refreshNumbers();
  updateSupplierVisibility();
  updateTotals();

  form?.addEventListener('submit', (event) => {
    const invalidEvidence = itemCards().find((card) => {
      const input = card.querySelector('[data-evidence]');
      return input && input.files.length === 0;
    });
    if (invalidEvidence) {
      event.preventDefault();
      window.alert('Setiap item wajib memiliki minimal satu bukti transaksi.');
      invalidEvidence.querySelector('[data-evidence]')?.focus();
    }
  });
})();
