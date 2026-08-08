package id.co.adena.pos.ui

import android.content.Intent
import android.net.ConnectivityManager
import android.net.NetworkCapabilities
import android.os.Bundle
import android.os.Handler
import android.os.Looper
import android.view.View
import android.webkit.CookieManager
import android.webkit.WebView
import android.webkit.WebViewClient
import androidx.appcompat.app.AppCompatActivity
import id.co.adena.pos.data.PosLocalStore
import id.co.adena.pos.databinding.ActivitySyncBinding

/**
 * Native first-entry synchronization screen.
 * The hidden WebView exists only as a compatibility bootstrap because this Android source
 * has no dedicated JSON master-data endpoint. It is never used to render/operate the POS.
 */
class SyncActivity : AppCompatActivity() {
    private lateinit var binding: ActivitySyncBinding
    private lateinit var store: PosLocalStore
    private val handler = Handler(Looper.getMainLooper())
    private var initialCount = 0
    private var finished = false

    override fun onCreate(savedInstanceState: Bundle?) {
        super.onCreate(savedInstanceState)
        binding = ActivitySyncBinding.inflate(layoutInflater)
        setContentView(binding.root)
        store = PosLocalStore(applicationContext)

        initialCount = store.productCount()
        binding.syncProgress.progress = 8
        binding.syncStatus.text = "Memeriksa database lokal…"
        binding.syncDetail.text = "$initialCount produk tersedia di perangkat"
        binding.continueOfflineButton.setOnClickListener { openNativePos() }

        if (!isOnline()) {
            if (initialCount > 0) {
                showOfflineReady()
                handler.postDelayed({ openNativePos() }, 600)
            } else {
                binding.syncProgress.progress = 0
                binding.syncStatus.text = "Sinkronisasi pertama memerlukan internet"
                binding.syncDetail.text = "Belum ada master produk pada perangkat. Sambungkan internet lalu buka POS kembali."
            }
            return
        }
        startCompatibilityBootstrap()
    }

    @Suppress("SetJavaScriptEnabled")
    private fun startCompatibilityBootstrap() {
        binding.syncProgress.progress = 20
        binding.syncStatus.text = "Mengambil master data POS…"
        binding.syncDetail.text = "Produk lokal: $initialCount"

        CookieManager.getInstance().setAcceptCookie(true)
        binding.bootstrapWebView.settings.apply {
            javaScriptEnabled = true
            domStorageEnabled = true
            databaseEnabled = true
        }
        CookieManager.getInstance().setAcceptThirdPartyCookies(binding.bootstrapWebView, true)

        binding.bootstrapWebView.addJavascriptInterface(
            WebAppBridge(
                getCurrentUrlSnapshot = { binding.bootstrapWebView.url },
                isTrustedOrigin = { binding.bootstrapWebView.url?.startsWith("https://adena.co.id/") == true },
                onPrintReceipt = { WebAppBridge.BridgeResult(false, "SYNC_ONLY", "Printer tidak digunakan saat sinkronisasi") },
                onOpenPrinterSettings = {},
                localStore = store,
            ),
            "AndroidBridge",
        )

        binding.bootstrapWebView.webViewClient = object : WebViewClient() {
            override fun onPageFinished(view: WebView, url: String) {
                if (!url.startsWith("https://adena.co.id/")) return
                binding.syncProgress.progress = 55
                binding.syncStatus.text = "Memproses data master…"
                view.evaluateJavascript("window.AdenaAndroidBridgeInfo={ready:true,name:'AndroidBridge',host:'adena.co.id'};", null)
                pollForProducts(0)
            }
        }
        binding.bootstrapWebView.loadUrl(SYNC_BOOTSTRAP_URL)
        handler.postDelayed({
            if (!finished && store.productCount() > 0) completeSync()
            else if (!finished) showBootstrapNeedsSession()
        }, 12_000)
    }

    private fun pollForProducts(attempt: Int) {
        if (finished) return
        val count = store.productCount()
        val progress = (60 + attempt * 4).coerceAtMost(92)
        binding.syncProgress.progress = progress
        binding.syncDetail.text = "$count produk tersimpan di perangkat"
        if (count > 0 && (count != initialCount || attempt >= 2)) {
            completeSync()
            return
        }
        if (attempt < 7) handler.postDelayed({ pollForProducts(attempt + 1) }, 500)
    }

    private fun completeSync() {
        if (finished) return
        finished = true
        val count = store.productCount()
        store.putState("last_native_sync", "{\"timestamp\":${System.currentTimeMillis()},\"product_count\":$count}")
        binding.syncProgress.progress = 100
        binding.syncStatus.text = "Sinkronisasi selesai"
        binding.syncDetail.text = "$count produk siap digunakan offline"
        handler.postDelayed({ openNativePos() }, 450)
    }

    private fun showOfflineReady() {
        binding.syncProgress.progress = 100
        binding.syncStatus.text = "Mode offline siap"
        binding.syncDetail.text = "Menggunakan $initialCount produk dari database lokal"
        binding.continueOfflineButton.visibility = View.VISIBLE
    }

    private fun showBootstrapNeedsSession() {
        val count = store.productCount()
        if (count > 0) {
            binding.syncProgress.progress = 100
            binding.syncStatus.text = "Data lokal siap"
            binding.syncDetail.text = "$count produk tersedia. Sinkronisasi server belum memperbarui data pada sesi ini."
            binding.continueOfflineButton.visibility = View.VISIBLE
        } else {
            binding.syncProgress.progress = 35
            binding.syncStatus.text = "Master data belum diterima"
            binding.syncDetail.text = "Server saat ini belum mengirim cache produk ke bridge Android. Endpoint/API native perlu tersedia untuk instalasi pertama."
        }
    }

    private fun openNativePos() {
        if (store.productCount() <= 0) return
        finished = true
        startActivity(Intent(this, MainActivity::class.java).addFlags(Intent.FLAG_ACTIVITY_CLEAR_TOP))
        finish()
    }

    private fun isOnline(): Boolean {
        val cm = getSystemService(ConnectivityManager::class.java)
        val nw = cm.activeNetwork ?: return false
        val caps = cm.getNetworkCapabilities(nw) ?: return false
        return caps.hasCapability(NetworkCapabilities.NET_CAPABILITY_INTERNET)
    }

    override fun onDestroy() {
        handler.removeCallbacksAndMessages(null)
        runCatching { binding.bootstrapWebView.destroy() }
        super.onDestroy()
    }

    companion object { private const val SYNC_BOOTSTRAP_URL = "https://adena.co.id/adm.php" }
}
