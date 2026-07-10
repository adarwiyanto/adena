(function () {
  'use strict';

  function initPairingModals() {
    var activeModal = null;
    var lastTrigger = null;

    function getModal(id) {
      if (!id) return null;
      var modal = document.getElementById(id);
      return modal && modal.classList.contains('pairing-modal') ? modal : null;
    }

    function openModal(id, trigger) {
      var modal = getModal(id);
      if (!modal) return;

      if (activeModal && activeModal !== modal) closeModal(activeModal, false);
      lastTrigger = trigger || document.activeElement;
      activeModal = modal;
      modal.classList.add('open');
      modal.setAttribute('aria-hidden', 'false');
      document.body.classList.add('pairing-modal-open');

      var focusTarget = modal.querySelector('[data-close-modal], button, [href], input, select, textarea');
      if (focusTarget) window.setTimeout(function () { focusTarget.focus(); }, 0);
    }

    function closeModal(modal, restoreFocus) {
      if (!modal) return;
      modal.classList.remove('open');
      modal.setAttribute('aria-hidden', 'true');
      if (activeModal === modal) activeModal = null;
      if (!document.querySelector('.pairing-modal.open')) {
        document.body.classList.remove('pairing-modal-open');
      }
      if (restoreFocus !== false && lastTrigger && typeof lastTrigger.focus === 'function') {
        lastTrigger.focus();
      }
    }

    function appendDetail(grid, label, value) {
      if (value === undefined || value === null || String(value) === '') return;
      var dt = document.createElement('dt');
      var dd = document.createElement('dd');
      dt.textContent = label;
      dd.textContent = String(value);
      grid.appendChild(dt);
      grid.appendChild(dd);
    }

    function showDetail(button) {
      var data = {};
      try {
        data = JSON.parse(button.getAttribute('data-detail') || '{}');
      } catch (error) {
        data = {};
      }

      var grid = document.getElementById('detailGrid');
      if (!grid) return;
      grid.textContent = '';

      var labels = {
        connection_name: 'Nama koneksi',
        requester_name: 'Peminta',
        remote_base_url: 'URL remote',
        requester_base_url: 'URL peminta',
        access_scope: 'Scope',
        requested_scope: 'Scope diminta',
        status: 'Status',
        created_at: 'Dibuat',
        approved_at: 'Disetujui',
        last_test_at: 'Test terakhir',
        last_test_status: 'Status test',
        last_test_message: 'Pesan test',
        last_message: 'Pesan'
      };

      Object.keys(labels).forEach(function (key) {
        appendDetail(grid, labels[key], data[key]);
      });

      if (!grid.children.length) {
        var empty = document.createElement('dd');
        empty.textContent = 'Tidak ada detail.';
        grid.appendChild(empty);
      }
      openModal('detailModal', button);
    }

    document.querySelectorAll('.pairing-modal').forEach(function (modal) {
      modal.setAttribute('role', 'dialog');
      modal.setAttribute('aria-modal', 'true');
      modal.setAttribute('aria-hidden', 'true');
    });

    document.addEventListener('click', function (event) {
      var openButton = event.target.closest('[data-open-modal]');
      if (openButton) {
        event.preventDefault();
        openModal(openButton.getAttribute('data-open-modal'), openButton);
        return;
      }

      var detailButton = event.target.closest('[data-detail]');
      if (detailButton) {
        event.preventDefault();
        showDetail(detailButton);
        return;
      }

      var closeButton = event.target.closest('[data-close-modal]');
      if (closeButton) {
        event.preventDefault();
        closeModal(closeButton.closest('.pairing-modal'));
        return;
      }

      if (event.target.classList && event.target.classList.contains('pairing-modal')) {
        closeModal(event.target);
      }
    });

    document.addEventListener('keydown', function (event) {
      if (event.key === 'Escape' && activeModal) closeModal(activeModal);
    });

    var reopen = document.body.getAttribute('data-pairing-reopen') || '';
    if (reopen) openModal(reopen, null);
  }

  if (document.readyState === 'loading') {
    document.addEventListener('DOMContentLoaded', initPairingModals, { once: true });
  } else {
    initPairingModals();
  }
})();
