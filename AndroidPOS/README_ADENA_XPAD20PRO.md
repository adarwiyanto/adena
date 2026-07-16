# Adena POS Android — Infinix XPAD 20 Pro

Aplikasi Android berbasis WebView untuk https://adena.co.id, diturunkan dari proyek HOPe POS Print Bridge.

## Identitas
- Application ID: `id.co.adena.pos`
- Nama aplikasi: `Adena POS`
- Target: Android API 35
- Minimum: Android 11 / API 30
- Orientasi: `sensorLandscape`
- URL login: `https://adena.co.id/adm.php`
- URL POS: `https://adena.co.id/pos/index.php`
- Trusted host: `adena.co.id`
- User-Agent tambahan: `AdenaPOSAndroidWebView/1.0 XPAD20Pro`

## Fitur
- Launcher/Home mandiri Adena.
- Kiosk mode dan Device Owner.
- Auto-start setelah boot.
- Maksimal dua aplikasi tambahan pada launcher.
- PIN admin dan pengaturan launcher.
- WebView persisten dengan cookie, DOM storage, cache, upload kamera/galeri.
- Bluetooth thermal printer dan ESC/POS.
- Printer terakhir tersimpan.
- Optimal untuk layar landscape tablet XPAD 20 Pro 2000×1200.
- Fitur pelanggan pada POS web menggunakan nomor HP sebagai identitas pencarian.
- Recall nama dan jenis kelamin pelanggan dari server/cache lokal web.
- Dukungan draft dan antrean transaksi offline dari modul web Adena.

## Build APK
Buka folder ini dengan Android Studio terbaru, tunggu Gradle Sync, lalu:

`Build > Build APK(s)`

atau dari terminal:

```bash
./gradlew assembleDebug
```

APK debug:

`app/build/outputs/apk/debug/app-debug.apk`

## Instalasi

```bash
adb install -r app-debug.apk
```

## Device Owner / kiosk penuh
Perangkat harus factory reset dan belum mempunyai akun Google saat perintah dijalankan:

```bash
adb shell dpm set-device-owner id.co.adena.pos/.kiosk.AdenaDeviceAdminReceiver
```

Setelah instalasi, pilih Adena sebagai aplikasi Home default. Pada XOS, aktifkan juga Auto-start dan nonaktifkan battery optimization untuk Adena POS.

## Patch web
Upload isi paket `adena-web-android-patch.zip` ke root web Adena, sehingga file masuk ke folder `pos/`.
