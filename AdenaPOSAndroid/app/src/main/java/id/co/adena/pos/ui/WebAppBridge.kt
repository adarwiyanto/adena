package id.co.adena.pos.ui

import android.util.Log
import android.webkit.JavascriptInterface
import id.co.adena.pos.data.PosLocalStore
import org.json.JSONArray
import org.json.JSONObject

class WebAppBridge(
    private val getCurrentUrlSnapshot: () -> String?,
    private val isTrustedOrigin: () -> Boolean,
    private val onPrintReceipt: (String?) -> BridgeResult,
    private val onOpenPrinterSettings: () -> Unit,
    private val localStore: PosLocalStore,
) {
    private fun trusted(): Boolean = try { isTrustedOrigin() } catch (_: Throwable) { false }

    @JavascriptInterface
    fun printReceipt(payloadJson: String?): String {
        return try {
            val cachedUrl = getCurrentUrlSnapshot()
            Log.d(TAG, "printReceipt() entered cachedUrl=$cachedUrl")
            if (!trusted()) return BridgeResult(false, "UNTRUSTED_ORIGIN", "Origin tidak diizinkan").toJson()
            onPrintReceipt(payloadJson).toJson()
        } catch (t: Throwable) {
            Log.e(TAG, "printReceipt fatal exception", t)
            BridgeResult(false, "INTERNAL_ERROR", t.message ?: "Terjadi kesalahan internal bridge").toJson()
        }
    }

    @JavascriptInterface
    fun openPrinterSettings() {
        try {
            if (!trusted()) return
            onOpenPrinterSettings()
        } catch (t: Throwable) {
            Log.e(TAG, "openPrinterSettings fatal exception", t)
        }
    }

    /** Save product master into native SQLite. */
    @JavascriptInterface
    fun cacheProducts(productsJson: String?): String {
        return try {
            if (!trusted()) {
                BridgeResult(false, "UNTRUSTED_ORIGIN", "Origin tidak diizinkan").toJson()
            } else {
                val raw = productsJson ?: "[]"
                JSONArray(raw) // validate first
                val count = localStore.saveProducts(raw)
                JSONObject().put("ok", true).put("code", "PRODUCTS_CACHED").put("count", count).toString()
            }
        } catch (t: Throwable) {
            Log.e(TAG, "cacheProducts failed", t)
            JSONObject().put("ok", false).put("code", "CACHE_FAILED").put("message", t.message ?: "Cache produk gagal").toString()
        }
    }

    @JavascriptInterface
    fun getCachedProducts(): String {
        return try {
            if (!trusted()) "[]" else localStore.loadProducts()
        } catch (t: Throwable) {
            Log.e(TAG, "getCachedProducts failed", t)
            "[]"
        }
    }

    /** Durable JSON state. Used for cart, receipts, customer cache, and sync metadata. */
    @JavascriptInterface
    fun putLocalState(key: String?, valueJson: String?): String {
        return try {
            if (!trusted()) {
                BridgeResult(false, "UNTRUSTED_ORIGIN", "Origin tidak diizinkan").toJson()
            } else {
                val safeKey = key?.trim().orEmpty()
                if (safeKey.isBlank()) {
                    BridgeResult(false, "INVALID_KEY", "Key kosong").toJson()
                } else {
                    val raw = valueJson ?: "null"
                    // Accept any JSON value, but guarantee it parses before storing.
                    JSONObject("{\"value\":$raw}")
                    localStore.putState(safeKey, raw)
                    BridgeResult(true, "STATE_SAVED", "State tersimpan di SQLite").toJson()
                }
            }
        } catch (t: Throwable) {
            Log.e(TAG, "putLocalState failed", t)
            BridgeResult(false, "STATE_SAVE_FAILED", t.message ?: "State gagal disimpan").toJson()
        }
    }

    @JavascriptInterface
    fun getLocalState(key: String?): String {
        return try {
            if (!trusted()) "null" else localStore.getState(key?.trim().orEmpty()) ?: "null"
        } catch (t: Throwable) {
            Log.e(TAG, "getLocalState failed", t)
            "null"
        }
    }

    /** Persist sync work outside WebView localStorage so reload/crash does not lose a sale. */
    @JavascriptInterface
    fun enqueueSync(entityType: String?, payloadJson: String?, offlineUuid: String?): String {
        return try {
            if (!trusted()) {
                BridgeResult(false, "UNTRUSTED_ORIGIN", "Origin tidak diizinkan").toJson()
            } else {
                val type = entityType?.trim().orEmpty()
                if (type.isBlank()) {
                    BridgeResult(false, "INVALID_ENTITY", "entity_type kosong").toJson()
                } else {
                    val raw = payloadJson ?: "{}"
                    JSONObject(raw)
                    JSONObject().put("ok", true).put("item", localStore.enqueue(type, raw, offlineUuid)).toString()
                }
            }
        } catch (t: Throwable) {
            Log.e(TAG, "enqueueSync failed", t)
            JSONObject().put("ok", false).put("code", "QUEUE_FAILED").put("message", t.message ?: "Queue gagal").toString()
        }
    }

    @JavascriptInterface
    fun getSyncQueue(): String {
        return try {
            if (!trusted()) "[]" else localStore.loadQueue()
        } catch (t: Throwable) {
            Log.e(TAG, "getSyncQueue failed", t)
            "[]"
        }
    }

    @JavascriptInterface
    fun markSyncResult(offlineUuid: String?, status: String?, error: String?): String {
        return try {
            if (!trusted()) {
                BridgeResult(false, "UNTRUSTED_ORIGIN", "Origin tidak diizinkan").toJson()
            } else {
                val uuid = offlineUuid?.trim().orEmpty()
                if (uuid.isBlank()) {
                    BridgeResult(false, "INVALID_UUID", "offline_uuid kosong").toJson()
                } else {
                    val normalized = if (status == "synced") "synced" else "failed"
                    localStore.markQueue(uuid, normalized, error.orEmpty())
                    BridgeResult(true, "QUEUE_UPDATED", "Queue diperbarui").toJson()
                }
            }
        } catch (t: Throwable) {
            Log.e(TAG, "markSyncResult failed", t)
            BridgeResult(false, "QUEUE_UPDATE_FAILED", t.message ?: "Queue gagal diperbarui").toJson()
        }
    }

    @JavascriptInterface fun ping(): String = BridgeResult(true, "PONG", "pong").toJson()
    @JavascriptInterface fun isReady(): String = BridgeResult(true, "READY", "Android bridge siap").toJson()
    @JavascriptInterface fun isReadySimple(): Boolean = true

    data class BridgeResult(val ok: Boolean, val code: String, val message: String) {
        fun toJson(): String = JSONObject().put("ok", ok).put("code", code).put("message", message).toString()
    }

    companion object { private const val TAG = "WebAppBridge" }
}
