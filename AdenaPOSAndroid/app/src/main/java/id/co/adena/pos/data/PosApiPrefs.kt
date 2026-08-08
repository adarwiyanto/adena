package id.co.adena.pos.data

import android.content.Context
import java.security.MessageDigest

class PosApiPrefs(context: Context) {
    private val p = context.getSharedPreferences("adena_pos_api", Context.MODE_PRIVATE)
    var baseUrl: String
        get() = p.getString("base_url", "https://adena.co.id") ?: "https://adena.co.id"
        set(v) { p.edit().putString("base_url", v.trim().trimEnd('/')).apply() }
    var apiToken: String
        get() = p.getString("api_token", "") ?: ""
        set(v) { p.edit().putString("api_token", v.trim()).apply() }
    var deviceCode: String
        get() = p.getString("device_code", "") ?: ""
        set(v) { p.edit().putString("device_code", v.trim().uppercase()).apply() }
    var userJson: String
        get() = p.getString("user_json", "") ?: ""
        set(v) { p.edit().putString("user_json", v).apply() }

    fun cacheOfflineCredential(username: String, password: String) {
        p.edit().putString("offline_user", username.trim().lowercase())
            .putString("offline_hash", sha256(username.trim().lowercase()+"\u0000"+password)).apply()
    }
    fun verifyOfflineCredential(username: String, password: String): Boolean {
        val u = p.getString("offline_user", "") ?: ""
        val h = p.getString("offline_hash", "") ?: ""
        return u == username.trim().lowercase() && h.isNotBlank() && h == sha256(username.trim().lowercase()+"\u0000"+password)
    }
    fun logout() { p.edit().remove("user_json").apply() }
    private fun sha256(s:String):String = MessageDigest.getInstance("SHA-256").digest(s.toByteArray()).joinToString(""){"%02x".format(it)}
}
