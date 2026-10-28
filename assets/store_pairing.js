(function () {
  'use strict';
  function init() {
    document.querySelectorAll('[data-sp-open]').forEach(function (button) {
      button.addEventListener('click', function () {
        var modal = document.getElementById(button.getAttribute('data-sp-open'));
        if (modal && typeof modal.showModal === 'function' && !modal.open) modal.showModal();
      });
    });
    document.querySelectorAll('[data-sp-close]').forEach(function (button) {
      button.addEventListener('click', function () {
        var modal = document.getElementById(button.getAttribute('data-sp-close'));
        if (modal && typeof modal.close === 'function' && modal.open) modal.close();
      });
    });
    if (document.body.getAttribute('data-sp-reopen-code') === '1') {
      var code = document.getElementById('code');
      if (code && typeof code.showModal === 'function' && !code.open) code.showModal();
    }
  }
  if (document.readyState === 'loading') document.addEventListener('DOMContentLoaded', init);
  else init();
})();
