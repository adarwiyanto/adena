# Patch Promosi + Persistensi Sesi POS

## Perubahan
- Launcher menambahkan menu **Promosi** di antara POS dan Apps.
- `PromotionActivity` menampilkan slideshow gambar fullscreen, loop 5 detik.
- Selama PromotionActivity aktif digunakan `FLAG_KEEP_SCREEN_ON`, sehingga layar tidak sleep/mati otomatis. Setting timeout Android tidak diubah permanen.
- `PromotionManagerActivity` tersedia dari Pengaturan Launcher > Promosi > Manager Promosi.
- Manager mendukung multi-select gambar, ubah urutan naik/turun, dan hapus.
- Gambar promosi disalin ke internal storage aplikasi (`files/promotions`) dan urutannya disimpan di SharedPreferences terpisah dari database POS.
- Tombol POS di launcher mengecek sesi `PosApiPrefs.userJson`. Bila sesi masih ada langsung membuka `MainActivity`; bila belum ada membuka `LoginActivity`.
- Hardware/system Back di `MainActivity` sekarang memakai alur keluar POS yang sama dengan tombol Keluar, kembali ke launcher tanpa logout dan tanpa menutup shift.

## Database
Tidak ada migrasi database POS.

## File baru
- app/src/main/java/id/co/adena/pos/data/PromotionStore.kt
- app/src/main/java/id/co/adena/pos/ui/PromotionActivity.kt
- app/src/main/java/id/co/adena/pos/ui/PromotionManagerActivity.kt
- app/src/main/res/drawable/ic_adena_promotion.xml

## File diubah
- app/src/main/java/id/co/adena/pos/ui/AdenaHomeActivity.kt
- app/src/main/java/id/co/adena/pos/ui/AdenaLauncherSettingsActivity.kt
- app/src/main/java/id/co/adena/pos/ui/MainActivity.kt
- app/src/main/AndroidManifest.xml

## Validasi
Manifest XML divalidasi secara sintaksis. Build Gradle tidak dapat dijalankan di environment patch karena Gradle wrapper mencoba mengunduh distribusi dari services.gradle.org sementara environment tidak memiliki akses jaringan.
