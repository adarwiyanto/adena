package id.co.adena.pos.ui

import android.os.Bundle
import androidx.appcompat.app.AppCompatActivity
import androidx.lifecycle.lifecycleScope
import id.co.adena.pos.data.PosApiPrefs
import id.co.adena.pos.databinding.ActivityPosApiSettingsBinding
import id.co.adena.pos.network.PosApiClient
import kotlinx.coroutines.Dispatchers
import kotlinx.coroutines.launch
import kotlinx.coroutines.withContext

class PosApiSettingsActivity : AppCompatActivity() {
    private lateinit var b: ActivityPosApiSettingsBinding
    private lateinit var p: PosApiPrefs

    override fun onCreate(s: Bundle?) {
        super.onCreate(s)
        b = ActivityPosApiSettingsBinding.inflate(layoutInflater)
        setContentView(b.root)
        p = PosApiPrefs(this)
        b.apiBaseUrl.setText(p.baseUrl)
        b.testButton.setOnClickListener { testConnection() }
        b.saveButton.setOnClickListener {
            if (saveBaseUrl()) finish()
        }
        b.cancelButton.setOnClickListener { finish() }
    }

    private fun saveBaseUrl(): Boolean {
        var value = b.apiBaseUrl.text.toString().trim().trimEnd('/')
        if (value.isBlank()) {
            b.testStatus.text = "Alamat HTTPS wajib diisi."
            return false
        }
        if (!value.startsWith("https://", ignoreCase = true)) {
            b.testStatus.text = "Gunakan alamat HTTPS, contoh https://adena.co.id"
            return false
        }
        p.baseUrl = value
        return true
    }

    private fun testConnection() {
        if (!saveBaseUrl()) return
        b.testButton.isEnabled = false
        b.testStatus.text = "Menguji koneksi..."
        lifecycleScope.launch {
            val result = withContext(Dispatchers.IO) {
                runCatching { PosApiClient(p).ping() }.getOrNull()
            }
            b.testButton.isEnabled = true
            b.testStatus.text = if (result?.optBoolean("ok") == true) {
                "Server Adena terhubung."
            } else {
                "Koneksi gagal: ${result?.optString("message") ?: "server tidak dapat dihubungi"}"
            }
        }
    }
}
