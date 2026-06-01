document.addEventListener('DOMContentLoaded', function () {
  document.querySelectorAll('.js-confirm-delete-token').forEach(function (form) {
    form.addEventListener('submit', function (event) {
      var ok = window.confirm('Yakin ingin menghapus API ini? Tindakan ini tidak bisa dibatalkan.');
      if (!ok) event.preventDefault();
    });
  });

  var btn = document.getElementById('btnTestApiConnection');
  var form = document.getElementById('receiverForm');
  var result = document.getElementById('apiTestResult');
  if (!btn || !form || !result) return;

  btn.addEventListener('click', function () {
    result.className = 'api-result';
    result.style.display = 'block';
    result.textContent = 'Menguji koneksi...';
    btn.disabled = true;

    var fd = new FormData();
    var csrf = form.querySelector('input[name="_csrf"]');
    var domain = form.querySelector('input[name="remote_base_url"]');
    var token = form.querySelector('input[name="remote_token"]');
    fd.append('_csrf', csrf ? csrf.value : '');
    fd.append('remote_base_url', domain ? domain.value : '');
    fd.append('remote_token', token ? token.value : '');

    fetch(btn.getAttribute('data-endpoint'), {
      method: 'POST',
      credentials: 'same-origin',
      body: fd
    })
      .then(function (res) { return res.json().catch(function () { return { ok: false, message: 'Respons test koneksi bukan JSON valid.' }; }); })
      .then(function (data) {
        if (data && data.ok) {
          result.className = 'api-result ok';
          result.innerHTML = '<strong>Berhasil terhubung.</strong><br>' +
            'Domain: ' + escapeHtml(data.domain || '-') + '<br>' +
            'Token: ' + escapeHtml(data.token_name || '-') + '<br>' +
            'Kode: ' + escapeHtml(data.device_code || '-');
        } else {
          result.className = 'api-result err';
          result.textContent = (data && data.message) ? data.message : 'Koneksi gagal.';
        }
      })
      .catch(function (err) {
        result.className = 'api-result err';
        result.textContent = err && err.message ? err.message : 'Koneksi gagal.';
      })
      .finally(function () {
        btn.disabled = false;
      });
  });
});

function escapeHtml(value) {
  return String(value).replace(/[&<>'"]/g, function (c) {
    return ({ '&': '&amp;', '<': '&lt;', '>': '&gt;', "'": '&#39;', '"': '&quot;' })[c];
  });
}
