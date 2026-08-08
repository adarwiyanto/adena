package id.co.adena.pos.network

import id.co.adena.pos.data.PosApiPrefs
import okhttp3.MediaType.Companion.toMediaType
import okhttp3.OkHttpClient
import okhttp3.Request
import okhttp3.RequestBody.Companion.toRequestBody
import org.json.JSONObject
import java.util.concurrent.TimeUnit

class PosApiClient(private val prefs: PosApiPrefs) {
    private val jsonType = "application/json; charset=utf-8".toMediaType()
    private val client = OkHttpClient.Builder()
        .connectTimeout(15, TimeUnit.SECONDS)
        .readTimeout(30, TimeUnit.SECONDS)
        .build()

    private fun request(
        path: String,
        method: String = "GET",
        body: JSONObject? = null,
        authRequired: Boolean = true
    ): JSONObject {
        if (authRequired) require(prefs.apiToken.isNotBlank()) { "Sesi API belum tersedia. Silakan login kembali." }
        val builder = Request.Builder()
            .url(prefs.baseUrl.trimEnd('/') + path)
            .header("Accept", "application/json")
        if (prefs.apiToken.isNotBlank()) {
            builder.header("Authorization", "Bearer ${prefs.apiToken}")
        }
        if (method == "POST") builder.post((body ?: JSONObject()).toString().toRequestBody(jsonType))
        val res = client.newCall(builder.build()).execute()
        val text = res.body?.string().orEmpty()
        val obj = runCatching { JSONObject(text) }.getOrElse {
            JSONObject().put("ok", false).put("message", "Respons server bukan JSON (${res.code})")
        }
        obj.put("_http_code", res.code)
        return obj
    }

    /** Public connectivity probe; never exposes or requires the POS token. */
    fun ping(): JSONObject = request("/api/auth.php?ping=1", authRequired = false)

    /**
     * First online login can run without a device token. The server authenticates the
     * Adena username/password and returns a restricted device token. Subsequent calls
     * reuse that token transparently.
     */
    fun login(username: String, password: String): JSONObject = request(
        "/api/auth.php", "POST",
        JSONObject()
            .put("username", username)
            .put("password", password)
            .put("device_code", prefs.deviceCode),
        authRequired = false
    )

    fun pullMaster(): JSONObject = request("/api/sync/pull.php")
    fun push(payload: JSONObject): JSONObject = request("/api/sync/push.php", "POST", payload)
    fun revise(payload: JSONObject): JSONObject = request("/api/sync/revise.php", "POST", payload)
    fun returnSale(payload: JSONObject): JSONObject = request("/api/sync/return.php", "POST", payload)
    fun shift(payload: JSONObject): JSONObject = request("/api/sync/shift.php", "POST", payload)
}
