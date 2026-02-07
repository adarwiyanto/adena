document.addEventListener('DOMContentLoaded', () => {
  const input = document.querySelector('#pos-search');
  const cards = Array.from(document.querySelectorAll('.pos-product-card'));
  const empty = document.querySelector('#pos-empty');
  const printBtn = document.querySelector('[data-print-receipt]');
  const paymentOptions = Array.from(document.querySelectorAll('input[name="payment_method"]'));
  const qrisField = document.querySelector('[data-qris-field]');
  const qrisInput = document.querySelector('#payment_proof');
  const qrisPreview = document.querySelector('[data-qris-preview]');
  const qrisImg = qrisPreview ? qrisPreview.querySelector('img') : null;
  const qrisRetake = document.querySelector('[data-qris-retake]');
  if (printBtn) {
    printBtn.addEventListener('click', () => {
      window.print();
    });
  }

  const toggleQris = (method) => {
    if (!qrisField) return;
    const isQris = method === 'qris';
    qrisField.hidden = !isQris;
    if (qrisInput) {
      qrisInput.required = isQris;
    }
  };

  if (paymentOptions.length) {
    const selected = paymentOptions.find((opt) => opt.checked);
    toggleQris(selected ? selected.value : '');
    paymentOptions.forEach((opt) => {
      opt.addEventListener('change', () => toggleQris(opt.value));
    });
  }

  const resetQrisPreview = () => {
    if (qrisInput) qrisInput.value = '';
    if (qrisPreview) qrisPreview.hidden = true;
    if (qrisImg) qrisImg.src = '';
  };

  if (qrisInput) {
    qrisInput.addEventListener('change', () => {
      const file = qrisInput.files && qrisInput.files[0];
      if (!file || !qrisPreview || !qrisImg) return;
      qrisImg.src = URL.createObjectURL(file);
      qrisPreview.hidden = false;
    });
  }

  if (qrisRetake) {
    qrisRetake.addEventListener('click', () => {
      resetQrisPreview();
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
