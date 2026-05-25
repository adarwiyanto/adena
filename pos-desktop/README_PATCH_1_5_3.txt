Adena POS Desktop Patch v1.5.3

Tujuan:
- Mengembalikan fitur multi payment yang hilang pada patch UI sebelumnya.
- Mempertahankan diskon item dan diskon transaksi dari v1.5.1/v1.5.2.
- Mempertahankan layout keranjang desktop-friendly dan window sizing lebih lega.

File yang diubah:
- package.json
- src/renderer/index.html
- src/renderer/renderer.js
- src/renderer/styles.css
- src/main/main.js
- src/main/transactions.js
- src/main/sync.js

Catatan stabilitas:
- print.js tidak disentuh.
- preload.js tidak disentuh.
- shift.js tidak disentuh.
- engine raw thermal / Windows printer tidak disentuh.
- database lokal/web tidak dibuat ulang.
- installer tidak disentuh.

Backtest static:
- node --check renderer.js OK
- node --check main.js OK
- node --check transactions.js OK
- node --check sync.js OK
- package.json valid JSON
