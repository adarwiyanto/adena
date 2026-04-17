package id.my.hopenoodles.hopepos.ui

import android.webkit.JavascriptInterface
import android.util.Log
import org.json.JSONObject

class WebAppBridge(
    private val getCurrentUrlSnapshot: () -> String?,
    private val isTrustedOrigin: () -> Boolean,
    private val onPrintReceipt: (String?) -> BridgeResult,
    private val onOpenPrinterSettings: () -> Unit,
) {

    @JavascriptInterface
    fun printReceipt(payloadJson: String?): String {
        return try {
            val cachedUrl = getCurrentUrlSnapshot()
            Log.d(TAG, "printReceipt() entered cachedUrl=$cachedUrl")
            if (!isTrustedOrigin()) {
                Log.w(TAG, "printReceipt ditolak: UNTRUSTED_ORIGIN")
                return BridgeResult(false, "UNTRUSTED_ORIGIN", "Origin tidak diizinkan").toJson()
            }
            onPrintReceipt(payloadJson).toJson()
        } catch (t: Throwable) {
            Log.e(TAG, "printReceipt fatal exception", t)
            BridgeResult(false, "INTERNAL_ERROR", t.message ?: "Terjadi kesalahan internal bridge").toJson()
        }
    }

    @JavascriptInterface
    fun openPrinterSettings() {
        try {
            val cachedUrl = getCurrentUrlSnapshot()
            Log.d(TAG, "openPrinterSettings() entered cachedUrl=$cachedUrl")
            if (!isTrustedOrigin()) {
                Log.w(TAG, "openPrinterSettings ditolak: UNTRUSTED_ORIGIN")
                return
            }
            onOpenPrinterSettings()
        } catch (t: Throwable) {
            Log.e(TAG, "openPrinterSettings fatal exception", t)
        }
    }

    @JavascriptInterface
    fun ping(): String = try {
        BridgeResult(true, "PONG", "pong").toJson()
    } catch (t: Throwable) {
        Log.e(TAG, "ping fatal exception", t)
        BridgeResult(false, "INTERNAL_ERROR", t.message ?: "Terjadi kesalahan internal bridge").toJson()
    }

    @JavascriptInterface
    fun isReady(): String = try {
        BridgeResult(true, "READY", "Android bridge siap").toJson()
    } catch (t: Throwable) {
        Log.e(TAG, "isReady fatal exception", t)
        BridgeResult(false, "INTERNAL_ERROR", t.message ?: "Terjadi kesalahan internal bridge").toJson()
    }

    @JavascriptInterface
    fun isReadySimple(): Boolean = try {
        true
    } catch (t: Throwable) {
        Log.e(TAG, "isReadySimple fatal exception", t)
        false
    }

    data class BridgeResult(
        val ok: Boolean,
        val code: String,
        val message: String,
    ) {
        fun toJson(): String = JSONObject()
            .put("ok", ok)
            .put("code", code)
            .put("message", message)
            .toString()
    }

    companion object {
        private const val TAG = "WebAppBridge"
    }
}
