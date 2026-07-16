# Build Adena POS Android Bridge untuk Android 11

Patch ini menurunkan `minSdk` dari API 31 ke API 30 agar APK bisa diinstall pada Android 11.

File yang diubah:

- `app/build.gradle.kts`
  - `minSdk = 30`
  - `versionCode = 2`
  - `versionName = "1.0.1-android11"`

## Cara compile di Android Studio

1. Buka Android Studio.
2. Pilih **Open**.
3. Arahkan ke folder:
   `android/adena-pos-print-bridge`
4. Tunggu Gradle Sync selesai.
5. Pilih menu **Build > Clean Project**.
6. Pilih **Build > Rebuild Project**.
7. Untuk APK debug: **Build > Build Bundle(s) / APK(s) > Build APK(s)**.
8. File APK biasanya ada di:
   `android/adena-pos-print-bridge/app/build/outputs/apk/debug/app-debug.apk`

## Catatan penting

- Android 11 = API 30, sehingga `minSdk = 30` sudah sesuai.
- Izin Bluetooth Android 11 masih memakai `BLUETOOTH` dan `BLUETOOTH_ADMIN`; izin Android 12+ (`BLUETOOTH_SCAN`, `BLUETOOTH_CONNECT`) tetap dipertahankan agar aplikasi tetap aman di Android 12 ke atas.
- Alur WebView dan print bridge tidak diubah.
