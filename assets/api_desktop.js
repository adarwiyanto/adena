document.addEventListener('DOMContentLoaded', function () {
  document.querySelectorAll('.js-confirm-delete-token').forEach(function (form) {
    form.addEventListener('submit', function (event) {
      var ok = window.confirm('Yakin ingin menghapus token POS ini? Tindakan ini tidak bisa dibatalkan.');
      if (!ok) {
        event.preventDefault();
      }
    });
  });
});
