# Adena POS Android — Native Offline Patch

Version: 1.2.0-native-offline (versionCode 6)

## Perubahan
- Tombol POS pada Adena Launcher sekarang membuka `SyncActivity` native lebih dahulu.
- Halaman sinkronisasi menampilkan progress dan status jumlah produk lokal.
- Jika offline dan cache produk tersedia, POS langsung dapat menggunakan data lokal.
- `MainActivity` diganti menjadi POS Android native; tidak merender WebView dan tidak melakukan HTTP saat transaksi.
- Product grid, pencarian, kategori, cart, perubahan qty, total dan validasi stok berjalan dari SQLite.
- Database dinaikkan ke v2 dengan tabel `sales` dan `sale_items`.
- Commit transaksi, pengurangan stok lokal, dan insert ke `sync_queue` dilakukan dalam satu SQLite transaction.
- Status header menampilkan jumlah produk dan transaksi pending sync.
- Pembayaran Cash memiliki Quick Cash dinamis: Uang Pas, pembulatan nominal relevan, dan Nominal Lain.
- Cash menampilkan Diterima dan Kembalian sebelum `BAYAR & CETAK`.
- Receipt tetap menggunakan formatter ESC/POS dan Bluetooth printer native yang sudah ada.

## Catatan sinkronisasi
Source Android yang diberikan tidak mempunyai endpoint REST/JSON master-data tersendiri. Karena itu `SyncActivity` menggunakan WebView tersembunyi hanya sebagai compatibility bootstrap terhadap bridge `cacheProducts()` pada `https://adena.co.id/adm.php`. WebView tersebut tidak dipakai sebagai UI POS maupun transaksi.

Agar instalasi pertama benar-benar bebas WebView 100%, server perlu menyediakan endpoint API native untuk auth + master data. Setelah endpoint tersedia, bootstrap tersembunyi dapat diganti dengan OkHttp tanpa mengubah MainActivity/native cart.

## Validasi
- XML layout dan AndroidManifest berhasil diparse.
- MainActivity runtime tidak memanggil loadUrl/evaluateJavascript/HTTP.
- Gradle build tidak dapat diselesaikan di environment patch karena Gradle wrapper perlu mengunduh distribusi dari services.gradle.org dan jaringan runtime diblokir.
