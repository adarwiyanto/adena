# HOPe POS Android App (WebView + Native Bluetooth Print)

## Ringkasan
APK Android sekarang berfungsi sebagai host WebView untuk login/POS sekaligus native printer handler (tanpa RTPrinter).

## Build Android
```bash
cd android/hope-pos-print-bridge
./gradlew assembleDebug
```
APK debug:
`android/hope-pos-print-bridge/app/build/outputs/apk/debug/app-debug.apk`

## Alur aplikasi
1. App buka `https://hopenoodles.my.id/adm.php` di WebView.
2. User login.
3. Jika request dari APK, server langsung arahkan ke `pos/index.php`.
4. Saat klik tombol cetak di halaman receipt:
   - JS cek `window.AndroidBridge`.
   - Jika ada, kirim HTML receipt ke native (`printReceipt(html, metaJson)`).
   - Jika tidak ada, fallback ke `window.print()`.
5. Native print pakai Bluetooth classic ESC/POS ke printer yang dipilih.

## Pengaturan printer di APK
- Buka tombol `Pengaturan Printer` dari halaman receipt atau langsung dari app.
- Pilih printer dari paired devices.
- Simpan MAC address ke SharedPreferences.
- Bisa `Reconnect` dan `Test Print` dari halaman settings.

## Catatan
- Pairing printer tetap dilakukan di setting Bluetooth Android terlebih dahulu.
- Jika logo gagal didownload, struk tetap dicetak (tanpa logo).
