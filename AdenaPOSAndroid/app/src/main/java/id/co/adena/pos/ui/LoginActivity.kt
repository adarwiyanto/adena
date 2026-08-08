package id.co.adena.pos.ui

import android.content.Intent
import android.net.ConnectivityManager
import android.net.NetworkCapabilities
import android.os.Bundle
import androidx.appcompat.app.AppCompatActivity
import androidx.lifecycle.lifecycleScope
import id.co.adena.pos.data.PosApiPrefs
import id.co.adena.pos.data.PosLocalStore
import id.co.adena.pos.databinding.ActivityLoginBinding
import id.co.adena.pos.network.PosApiClient
import kotlinx.coroutines.Dispatchers
import kotlinx.coroutines.launch
import kotlinx.coroutines.withContext
import org.json.JSONObject

class LoginActivity:AppCompatActivity(){
    private lateinit var b:ActivityLoginBinding;private lateinit var prefs:PosApiPrefs;private lateinit var store:PosLocalStore
    override fun onCreate(s:Bundle?){super.onCreate(s);b=ActivityLoginBinding.inflate(layoutInflater);setContentView(b.root);prefs=PosApiPrefs(this);store=PosLocalStore(this)
        b.btnOpenSettings.setOnClickListener { startActivity(Intent(this, PosApiSettingsActivity::class.java)) }
        b.btnLogin.setOnClickListener { doLogin() }
        b.password.setOnEditorActionListener { _, _, _ -> doLogin(); true }
    }
    private fun doLogin() {
        val u = b.username.text.toString().trim()
        val p = b.password.text.toString()
        if (u.isBlank() || p.isBlank()) {
            b.loginError.text = "Username dan password wajib diisi"
            return
        }

        // Opening SQLite can trigger an upgrade from older Adena POS builds. Never let a
        // migration problem crash the whole process from a Login click.
        val localProductCount = runCatching { store.productCount() }.getOrElse { e ->
            b.loginStatus.text = ""
            b.loginError.text = "Database lokal gagal dibuka: ${e.message ?: e.javaClass.simpleName}"
            return
        }
        b.btnLogin.isEnabled = false
        b.loginStatus.text = "Login..."
        b.loginError.text = ""

        lifecycleScope.launch {
            try {
                val online = isOnline()
                val result = if (online) {
                    withContext(Dispatchers.IO) {
                        runCatching { PosApiClient(prefs).login(u, p) }
                            .getOrElse { JSONObject().put("ok", false).put("message", it.message ?: "Koneksi login gagal") }
                    }
                } else JSONObject().put("ok", false).put("message", "offline")

                if (result.optBoolean("ok")) {
                    val user = result.optJSONObject("user")
                        ?: result.optJSONObject("data")?.optJSONObject("user")
                        ?: JSONObject()
                    prefs.userJson = user.toString()
                    prefs.cacheOfflineCredential(u, p)
                    // Device API credential is provisioned by the server automatically.
                    result.optString("api_token")
                        .takeIf { it.isNotBlank() }?.let { prefs.apiToken = it }
                    val tokenObj = result.optJSONObject("token")
                        ?: result.optJSONObject("data")?.optJSONObject("token")
                    tokenObj?.optString("device_code")
                        ?.takeIf { it.isNotBlank() }?.let { prefs.deviceCode = it }
                    openSync(false)
                } else if (localProductCount > 0 && prefs.verifyOfflineCredential(u, p)) {
                    b.loginStatus.text = "Login offline"
                    openSync(true)
                } else {
                    b.btnLogin.isEnabled = true
                    b.loginStatus.text = ""
                    b.loginError.text = if (!online)
                        "Tidak ada internet dan akun belum pernah login di perangkat ini."
                    else result.optString("message", "Login gagal")
                }
            } catch (e: Exception) {
                b.btnLogin.isEnabled = true
                b.loginStatus.text = ""
                b.loginError.text = "Login gagal: ${e.message ?: e.javaClass.simpleName}"
            }
        }
    }
    private fun openSync(offline:Boolean){startActivity(Intent(this,SyncActivity::class.java).putExtra("offline_login",offline));finish()}
    private fun isOnline():Boolean{val cm=getSystemService(ConnectivityManager::class.java);val n=cm.activeNetwork?:return false;return cm.getNetworkCapabilities(n)?.hasCapability(NetworkCapabilities.NET_CAPABILITY_INTERNET)==true}
}
