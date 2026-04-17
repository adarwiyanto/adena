package id.my.hopenoodles.hopepos.ui

import android.Manifest
import android.content.Intent
import android.content.pm.PackageManager
import android.net.Uri
import android.os.Build
import android.os.Bundle
import android.provider.Settings
import android.util.Log
import android.view.View
import android.webkit.CookieManager
import android.webkit.WebChromeClient
import android.webkit.WebResourceRequest
import android.webkit.WebSettings
import android.webkit.WebView
import android.webkit.WebViewClient
import android.widget.Toast
import androidx.activity.OnBackPressedCallback
import androidx.activity.result.contract.ActivityResultContracts
import androidx.appcompat.app.AppCompatActivity
import androidx.core.content.ContextCompat
import androidx.lifecycle.lifecycleScope
import id.my.hopenoodles.hopepos.bluetooth.BluetoothPrinterManager
import id.my.hopenoodles.hopepos.bluetooth.EscPosFormatter
import id.my.hopenoodles.hopepos.data.PrinterPrefs
import id.my.hopenoodles.hopepos.data.model.ReceiptPayload
import id.my.hopenoodles.hopepos.databinding.ActivityMainBinding
import id.my.hopenoodles.hopepos.network.LogoDownloader
import kotlinx.coroutines.Dispatchers
import kotlinx.coroutines.launch
import kotlinx.coroutines.withContext
import kotlinx.coroutines.runBlocking
import org.json.JSONException

class MainActivity : AppCompatActivity() {
    private lateinit var binding: ActivityMainBinding
    private lateinit var printerPrefs: PrinterPrefs
    private lateinit var printerManager: BluetoothPrinterManager
    private val logoDownloader = LogoDownloader()
    @Volatile
    private var currentPageUrlSnapshot: String? = null

    private val permissionLauncher = registerForActivityResult(
        ActivityResultContracts.RequestMultiplePermissions(),
    ) { result ->
        val denied = result.filterValues { !it }.keys
        if (denied.isNotEmpty()) {
            showToast("Izin Bluetooth dibutuhkan agar printer bisa dipakai.")
        }
    }

    override fun onCreate(savedInstanceState: Bundle?) {
        super.onCreate(savedInstanceState)
        binding = ActivityMainBinding.inflate(layoutInflater)
        setContentView(binding.root)

        printerPrefs = PrinterPrefs(this)
        printerManager = BluetoothPrinterManager(this)

        ensureBluetoothPermissions()
        configureWebView()
        autoConnectDefaultPrinter()

        if (savedInstanceState == null) {
            updateCurrentUrlSnapshot(LOGIN_URL, "initial_load")
            binding.webView.loadUrl(LOGIN_URL)
        }

        onBackPressedDispatcher.addCallback(this, object : OnBackPressedCallback(true) {
            override fun handleOnBackPressed() {
                if (binding.webView.canGoBack()) {
                    binding.webView.goBack()
                } else {
                    finish()
                }
            }
        })
    }

    private fun configureWebView() {
        CookieManager.getInstance().apply {
            setAcceptCookie(true)
            setAcceptThirdPartyCookies(binding.webView, true)
        }

        binding.webView.settings.apply {
            javaScriptEnabled = true
            domStorageEnabled = true
            javaScriptCanOpenWindowsAutomatically = false
            setSupportMultipleWindows(false)
            mixedContentMode = WebSettings.MIXED_CONTENT_COMPATIBILITY_MODE
            userAgentString = "${userAgentString ?: ""} HOPePOSAndroidWebView/1.0"
        }

        binding.webView.addJavascriptInterface(
            WebAppBridge(
                getCurrentUrlSnapshot = { currentPageUrlSnapshot },
                isTrustedOrigin = { isTrustedCachedOrigin() },
                onPrintReceipt = { handlePrintReceipt(it) },
                onOpenPrinterSettings = { openPrinterSettingsSafely() },
            ),
            "AndroidBridge",
        )

        binding.webView.webChromeClient = object : WebChromeClient() {
            override fun onProgressChanged(view: WebView, newProgress: Int) {
                binding.webProgress.progress = newProgress
                binding.webProgress.visibility = if (newProgress >= 100) View.GONE else View.VISIBLE
            }
        }

        binding.webView.webViewClient = object : WebViewClient() {
            override fun shouldOverrideUrlLoading(view: WebView, request: WebResourceRequest): Boolean {
                val url = request.url.toString()
                updateCurrentUrlSnapshot(url, "shouldOverrideUrlLoading")
                if (isTrustedUrl(url)) return false
                return runCatching {
                    startActivity(Intent(Intent.ACTION_VIEW, Uri.parse(url)))
                    true
                }.getOrDefault(false)
            }

            override fun onPageFinished(view: WebView, url: String) {
                super.onPageFinished(view, url)
                updateCurrentUrlSnapshot(url, "onPageFinished")

                if (shouldRedirectToPos(url)) {
                    view.loadUrl(POS_URL)
                    return
                }

                if (isTrustedUrl(url)) {
                    view.evaluateJavascript(
                        "window.HopeAndroidBridgeInfo={ready:true,name:'AndroidBridge',host:'$TRUSTED_HOST'};",
                        null,
                    )
                }
            }
        }
    }

    private fun handlePrintReceipt(payloadRaw: String?): WebAppBridge.BridgeResult {
        Log.d(TAG, "Bridge printReceipt entered")
        val cachedUrl = currentPageUrlSnapshot
        val isTrusted = isTrustedUrl(cachedUrl)
        Log.d(TAG, "Bridge trusted origin check source=cached_url cachedUrl=$cachedUrl trusted=$isTrusted")
        if (!isTrusted) {
            return WebAppBridge.BridgeResult(false, "UNTRUSTED_ORIGIN", "Origin tidak diizinkan")
        }

        val hasPermission = printerManager.hasRequiredBluetoothPermissionsForConnect()
        val bluetoothEnabled = printerManager.isBluetoothEnabled()
        Log.d(TAG, "Bridge printer precheck permission=$hasPermission bluetoothEnabled=$bluetoothEnabled")

        if (!hasPermission) {
            ensureBluetoothPermissions()
            val missing = printerManager.getMissingBluetoothPermissionErrorForConnect()
            return WebAppBridge.BridgeResult(
                false,
                missing?.first ?: "MISSING_PERMISSION",
                missing?.second ?: "Izin Bluetooth belum lengkap",
            )
        }

        if (!bluetoothEnabled) {
            showToast("Bluetooth mati. Aktifkan Bluetooth terlebih dahulu.")
            openBluetoothSettingsSafely()
            return WebAppBridge.BridgeResult(false, "BLUETOOTH_OFF", "Bluetooth belum aktif")
        }

        val printerMac = printerPrefs.getPrinterMac()
        Log.d(TAG, "Bridge selected printer exists=${!printerMac.isNullOrBlank()} mac=$printerMac")
        if (printerMac.isNullOrBlank()) {
            showToast("Printer belum dipilih. Silakan pilih printer.")
            openPrinterSettingsSafely()
            return WebAppBridge.BridgeResult(false, "PRINTER_NOT_SELECTED", "Printer belum dipilih")
        }

        val payload = try {
            val parsed = ReceiptPayload.fromJson(payloadRaw ?: "")
            Log.d(TAG, "Bridge payload parse result=ok items=${parsed.items.size} total=${parsed.total}")
            parsed
        } catch (e: IllegalArgumentException) {
            Log.e(TAG, "Payload print invalid", e)
            return WebAppBridge.BridgeResult(false, "INVALID_PAYLOAD", e.message ?: "Payload tidak valid")
        } catch (e: JSONException) {
            Log.e(TAG, "JSON print invalid", e)
            return WebAppBridge.BridgeResult(false, "INVALID_JSON", "Format JSON receipt tidak valid")
        }

        Log.d(TAG, "Bridge mulai koneksi printer mac=$printerMac")
        val result = runBlocking {
            withContext(Dispatchers.IO) {
                runCatching {
                    val logo = logoDownloader.download(payload.logoUrl)
                    val receiptBytes = EscPosFormatter.formatReceipt(payload, logo)
                    printerManager.print(printerMac, receiptBytes)
                }
            }
        }

        return if (result.isSuccess) {
            Log.d(TAG, "Bridge write printer sukses")
            showToast("Print receipt berhasil")
            WebAppBridge.BridgeResult(true, "PRINT_OK", "Print receipt berhasil")
        } else {
            val error = result.exceptionOrNull()
            Log.e(TAG, "Bridge write printer gagal", error)
            val code = (error as? BluetoothPrinterManager.PrinterException)?.code ?: "PRINT_FAILED"
            val message = error?.message ?: "Gagal mencetak receipt"
            if (code == "MISSING_PERMISSION" || code == "MISSING_CONNECT_PERMISSION" || code == "MISSING_SCAN_PERMISSION") {
                ensureBluetoothPermissions()
            }
            WebAppBridge.BridgeResult(false, code, message)
        }
    }

    private fun autoConnectDefaultPrinter() {
        val mac = printerPrefs.getPrinterMac() ?: return
        if (!printerManager.hasRequiredBluetoothPermissionsForConnect()) return
        if (!printerManager.isBluetoothEnabled()) return

        lifecycleScope.launch {
            withContext(Dispatchers.IO) { printerManager.autoConnect(mac) }
        }
    }

    private fun openPrinterSettingsSafely() {
        val cachedUrl = currentPageUrlSnapshot
        val trusted = isTrustedUrl(cachedUrl)
        Log.d(TAG, "Bridge open settings requested source=cached_url cachedUrl=$cachedUrl trusted=$trusted")
        if (!trusted) {
            Log.w(TAG, "Membuka settings ditolak: UNTRUSTED_ORIGIN")
            return
        }
        runOnUiThread {
            runCatching {
                Log.d(TAG, "Membuka PrinterSettingsActivity")
                startActivity(Intent(this, PrinterSettingsActivity::class.java))
                Log.d(TAG, "Membuka PrinterSettingsActivity sukses")
            }.onFailure {
                Log.e(TAG, "Gagal membuka PrinterSettingsActivity", it)
            }
        }
    }

    private fun ensureBluetoothPermissions() {
        if (Build.VERSION.SDK_INT < Build.VERSION_CODES.S) return
        val required = listOf(
            Manifest.permission.BLUETOOTH_SCAN,
            Manifest.permission.BLUETOOTH_CONNECT,
        )
        val missing = required.filter {
            ContextCompat.checkSelfPermission(this, it) != PackageManager.PERMISSION_GRANTED
        }
        if (missing.isNotEmpty()) {
            permissionLauncher.launch(missing.toTypedArray())
        }
    }

    private fun openBluetoothSettingsSafely() {
        runOnUiThread {
            runCatching {
                startActivity(Intent(Settings.ACTION_BLUETOOTH_SETTINGS))
            }.onFailure {
                Log.e(TAG, "Gagal membuka bluetooth settings", it)
            }
        }
    }

    private fun shouldRedirectToPos(url: String): Boolean {
        val parsed = Uri.parse(url)
        val host = parsed.host ?: return false
        val path = parsed.path ?: return false
        if (host != TRUSTED_HOST) return false
        return path.startsWith("/admin") || path.startsWith("/dashboard")
    }

    private fun isTrustedUrl(url: String?): Boolean {
        if (url.isNullOrBlank()) return false
        val uri = Uri.parse(url)
        return uri.scheme == "https" && uri.host == TRUSTED_HOST
    }

    private fun updateCurrentUrlSnapshot(url: String?, source: String) {
        currentPageUrlSnapshot = url
        Log.d(TAG, "Update cached current url source=$source url=$url")
    }

    private fun isTrustedCachedOrigin(): Boolean {
        val cachedUrl = currentPageUrlSnapshot
        val trusted = isTrustedUrl(cachedUrl)
        Log.d(TAG, "Trusted origin check source=cached_url cachedUrl=$cachedUrl trusted=$trusted")
        return trusted
    }

    private fun showToast(message: String) {
        runOnUiThread { Toast.makeText(this, message, Toast.LENGTH_LONG).show() }
    }

    override fun onDestroy() {
        printerManager.disconnect()
        super.onDestroy()
    }

    companion object {
        private const val TAG = "MainActivity"
        private const val LOGIN_URL = "https://hopenoodles.my.id/adm.php"
        private const val POS_URL = "https://hopenoodles.my.id/pos/index.php"
        private const val TRUSTED_HOST = "hopenoodles.my.id"
    }
}
