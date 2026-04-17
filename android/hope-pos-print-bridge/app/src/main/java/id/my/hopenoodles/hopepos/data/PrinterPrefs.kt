package id.my.hopenoodles.hopepos.data

import android.content.Context

class PrinterPrefs(context: Context) {
    private val prefs = context.getSharedPreferences("hope_pos_printer", Context.MODE_PRIVATE)

    fun getPrinterMac(): String? = prefs.getString(KEY_PRINTER_MAC, null)

    fun setPrinterMac(mac: String) {
        prefs.edit().putString(KEY_PRINTER_MAC, mac.trim()).apply()
    }

    companion object {
        private const val KEY_PRINTER_MAC = "printer_mac"
    }
}
