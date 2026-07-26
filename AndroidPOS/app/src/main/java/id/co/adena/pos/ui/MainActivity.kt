package id.co.adena.pos.ui

import android.Manifest
import android.graphics.Bitmap
import android.graphics.BitmapFactory
import android.graphics.Matrix
import android.content.ComponentCallbacks2
import android.content.Intent
import android.content.pm.ActivityInfo
import android.content.pm.ApplicationInfo
import android.content.pm.PackageManager
import android.net.Uri
import android.media.ExifInterface
import android.os.Build
import android.os.Bundle
import android.provider.Settings
import android.provider.MediaStore
import android.util.Log
import android.view.MotionEvent
import android.view.View
import android.webkit.CookieManager
import android.webkit.WebChromeClient
import android.webkit.WebResourceRequest
import android.webkit.WebSettings
import android.webkit.WebView
import android.webkit.WebViewClient
import android.webkit.ValueCallback
import android.widget.Toast
import androidx.activity.OnBackPressedCallback
import androidx.activity.result.contract.ActivityResultContracts
import androidx.appcompat.app.AlertDialog
import androidx.appcompat.app.AppCompatActivity
import androidx.core.content.ContextCompat
import androidx.core.content.FileProvider
import androidx.lifecycle.lifecycleScope
import id.co.adena.pos.bluetooth.BluetoothPrinterManager
import id.co.adena.pos.bluetooth.EscPosFormatter
import id.co.adena.pos.data.PrinterPrefs
import id.co.adena.pos.data.model.ReceiptPayload
import id.co.adena.pos.databinding.ActivityMainBinding
import id.co.adena.pos.kiosk.KioskManager
import id.co.adena.pos.kiosk.KioskPrefs
import id.co.adena.pos.network.LogoDownloader
import kotlinx.coroutines.Dispatchers
import kotlinx.coroutines.launch
import kotlinx.coroutines.withContext
import kotlinx.coroutines.runBlocking
import org.json.JSONException
import java.io.File
import java.io.InputStream
import java.io.FileOutputStream
import java.text.SimpleDateFormat
import java.util.Date
import java.util.Locale

class MainActivity : AppCompatActivity() {
    private lateinit var binding: ActivityMainBinding
    private lateinit var printerPrefs: PrinterPrefs
    private lateinit var kioskPrefs: KioskPrefs
    private lateinit var kioskManager: KioskManager
    private lateinit var printerManager: BluetoothPrinterManager
    private val logoDownloader = LogoDownloader()
    @Volatile
    private var currentPageUrlSnapshot: String? = null
    private var pendingFilePathCallback: ValueCallback<Array<Uri>>? = null
    private var pendingFileChooserParams: WebChromeClient.FileChooserParams? = null
    private var pendingCameraImageUri: Uri? = null
    private val kioskTapTimestamps = ArrayDeque<Long>()
    private var exitPinDialogShowing = false

    private val permissionLauncher = registerForActivityResult(
        ActivityResultContracts.RequestMultiplePermissions(),
    ) { result ->
        val denied = result.filterValues { !it }.keys
        if (denied.isNotEmpty()) {
            showToast("Izin Bluetooth dibutuhkan agar printer bisa dipakai.")
        }
    }

    private val cameraPermissionLauncher = registerForActivityResult(
        ActivityResultContracts.RequestPermission(),
    ) { granted ->
        val params = pendingFileChooserParams
        pendingFileChooserParams = null
        if (granted && params != null) {
            launchFileChooser(params)
        } else {
            pendingFilePathCallback?.onReceiveValue(null)
            pendingFilePathCallback = null
            pendingCameraImageUri = null
            if (!granted) showToast("Izin kamera dibutuhkan untuk foto bukti QRIS.")
        }
    }

    private val fileChooserLauncher = registerForActivityResult(
        ActivityResultContracts.StartActivityForResult(),
    ) { result ->
        val callback = pendingFilePathCallback
        if (callback == null) {
            pendingCameraImageUri = null
            return@registerForActivityResult
        }

        val uris = if (result.resultCode == RESULT_OK) {
            val data = result.data
            when {
                data?.clipData != null -> {
                    val clip = data.clipData!!
                    Array(clip.itemCount) { idx -> clip.getItemAt(idx).uri }
                }
                data?.data != null -> arrayOf(data.data!!)
                pendingCameraImageUri != null -> arrayOf(pendingCameraImageUri!!)
                else -> null
            }
        } else {
            null
        }

        if (uris == null) {
            callback.onReceiveValue(null)
            pendingFilePathCallback = null
            pendingCameraImageUri = null
            return@registerForActivityResult
        }

        lifecycleScope.launch {
            val compressedUris = withContext(Dispatchers.IO) {
                compressImageUrisForUpload(uris)
            }

            if (compressedUris == null) {
                showToast("Foto QRIS gagal dikompresi. Silakan pilih foto lain.")
            }

            callback.onReceiveValue(compressedUris)
            pendingFilePathCallback = null
            pendingCameraImageUri = null
        }
    }

    override fun onCreate(savedInstanceState: Bundle?) {
        requestedOrientation = ActivityInfo.SCREEN_ORIENTATION_LANDSCAPE
        super.onCreate(savedInstanceState)

        kioskPrefs = KioskPrefs(this)
        kioskManager = KioskManager(this)
        if (!kioskPrefs.isSetupComplete()) {
            startActivity(Intent(this, KioskSetupActivity::class.java))
            finish()
            return
        }

        binding = ActivityMainBinding.inflate(layoutInflater)
        setContentView(binding.root)

        printerPrefs = PrinterPrefs(this)
        printerManager = BluetoothPrinterManager(this)

        ensureBluetoothPermissions()
        cleanupOldUploadCacheFiles()
        configureWebView()
        configureKioskGestures()
        configureAdminButton()
        autoConnectDefaultPrinter()

        val startUrl = savedInstanceState?.getString(STATE_CURRENT_URL)?.takeIf { isTrustedUrl(it) } ?: LOGIN_URL
        updateCurrentUrlSnapshot(startUrl, "initial_load")
        binding.webView.loadUrl(startUrl)

        onBackPressedDispatcher.addCallback(this, object : OnBackPressedCallback(true) {
            override fun handleOnBackPressed() {
                if (kioskPrefs.isKioskEnabled() && !kioskPrefs.isAdminUnlocked()) {
                    showToast("Mode kiosk aktif. Ketuk pojok kanan bawah 5 kali lalu masukkan PIN untuk keluar.")
                    return
                }
                if (binding.webView.canGoBack()) {
                    binding.webView.goBack()
                } else {
                    finish()
                }
            }
        })
    }

    private fun configureWebView() {
        WebView.setWebContentsDebuggingEnabled(isAppDebuggable())

        CookieManager.getInstance().apply {
            setAcceptCookie(true)
            setAcceptThirdPartyCookies(binding.webView, true)
        }

        binding.webView.apply {
            setRendererPriorityPolicy(WebView.RENDERER_PRIORITY_IMPORTANT, true)
            overScrollMode = View.OVER_SCROLL_NEVER
            isVerticalScrollBarEnabled = false
            isHorizontalScrollBarEnabled = false
        }

        binding.webView.settings.apply {
            javaScriptEnabled = true
            domStorageEnabled = true
            databaseEnabled = true
            cacheMode = WebSettings.LOAD_DEFAULT
            javaScriptCanOpenWindowsAutomatically = false
            setSupportMultipleWindows(false)
            mixedContentMode = WebSettings.MIXED_CONTENT_COMPATIBILITY_MODE
            loadsImagesAutomatically = true
            blockNetworkImage = false
            mediaPlaybackRequiresUserGesture = true
            useWideViewPort = true
            loadWithOverviewMode = true
            textZoom = 100
            if (Build.VERSION.SDK_INT >= Build.VERSION_CODES.O) {
                safeBrowsingEnabled = true
            }
            if (Build.VERSION.SDK_INT >= Build.VERSION_CODES.M) {
                offscreenPreRaster = false
            }
            userAgentString = "${userAgentString ?: ""} AdenaPOSAndroidWebView/1.0 XPAD20Pro"
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

            override fun onShowFileChooser(
                webView: WebView,
                filePathCallback: ValueCallback<Array<Uri>>,
                fileChooserParams: WebChromeClient.FileChooserParams,
            ): Boolean {
                pendingFilePathCallback?.onReceiveValue(null)
                pendingFilePathCallback = filePathCallback

                if (ContextCompat.checkSelfPermission(this@MainActivity, Manifest.permission.CAMERA) != PackageManager.PERMISSION_GRANTED) {
                    pendingFileChooserParams = fileChooserParams
                    cameraPermissionLauncher.launch(Manifest.permission.CAMERA)
                    return true
                }

                launchFileChooser(fileChooserParams)
                return true
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

                if (isTrustedUrl(url)) {
                    view.evaluateJavascript(
                        "window.AdenaAndroidBridgeInfo={ready:true,name:'AndroidBridge',host:'$TRUSTED_HOST'};",
                        null,
                    )
                }
            }
        }
    }

    private fun configureKioskGestures() {
        binding.root.setOnTouchListener { view, event ->
            if (event.action == MotionEvent.ACTION_UP && kioskPrefs.isKioskEnabled() && isHiddenAdminZoneTap(view, event)) {
                registerHiddenAdminTap()
            }
            false
        }
        binding.webView.setOnTouchListener { view, event ->
            if (event.action == MotionEvent.ACTION_UP && kioskPrefs.isKioskEnabled() && isHiddenAdminZoneTap(view, event)) {
                registerHiddenAdminTap()
            }
            false
        }
    }

    private fun configureAdminButton() {
        binding.adminButton.visibility = View.GONE
    }

    private fun isHiddenAdminZoneTap(view: View, event: MotionEvent): Boolean {
        val zoneSize = dp(HIDDEN_ADMIN_ZONE_DP)
        return event.x >= view.width - zoneSize && event.y >= view.height - zoneSize
    }

    private fun registerHiddenAdminTap() {
        val now = System.currentTimeMillis()
        kioskTapTimestamps.addLast(now)
        while (kioskTapTimestamps.isNotEmpty() && now - kioskTapTimestamps.first() > HIDDEN_ADMIN_TAP_WINDOW_MS) {
            kioskTapTimestamps.removeFirst()
        }
        if (kioskTapTimestamps.size >= HIDDEN_ADMIN_TAP_COUNT) {
            kioskTapTimestamps.clear()
            showAdminPinDialog()
        }
    }

    private fun dp(value: Int): Int = (value * resources.displayMetrics.density).toInt()

    private fun showExitPinDialog() {
        if (exitPinDialogShowing) return
        exitPinDialogShowing = true
        val input = android.widget.EditText(this).apply {
            hint = "PIN"
            inputType = android.text.InputType.TYPE_CLASS_NUMBER or android.text.InputType.TYPE_NUMBER_VARIATION_PASSWORD
            setPadding(48, 12, 48, 12)
        }
        AlertDialog.Builder(this)
            .setTitle("Keluar Mode Kiosk")
            .setMessage("Masukkan PIN untuk membuka mode kiosk Adena.")
            .setView(input)
            .setPositiveButton("Buka") { dialog, _ ->
                val pin = input.text?.toString().orEmpty()
                if (kioskPrefs.verifyPin(pin)) {
                    kioskManager.stopKiosk(this)
                    kioskPrefs.disableKioskKeepSetup()
                    showToast("Mode kiosk dinonaktifkan.")
                    startActivity(Intent(this, KioskSetupActivity::class.java))
                } else {
                    showToast("PIN salah.")
                    if (kioskPrefs.isKioskEnabled()) {
                        kioskManager.startKiosk(this, kioskPrefs.getAllowedPackages())
                    }
                }
                exitPinDialogShowing = false
                dialog.dismiss()
            }
            .setNegativeButton("Batal") { dialog, _ ->
                exitPinDialogShowing = false
                dialog.dismiss()
                if (kioskPrefs.isKioskEnabled()) {
                    kioskManager.startKiosk(this, kioskPrefs.getAllowedPackages())
                }
            }
            .setOnCancelListener {
                exitPinDialogShowing = false
                if (kioskPrefs.isKioskEnabled()) {
                    kioskManager.startKiosk(this, kioskPrefs.getAllowedPackages())
                }
            }
            .show()
    }

    private fun showAdminPinDialog() {
        if (exitPinDialogShowing) return
        exitPinDialogShowing = true
        val input = android.widget.EditText(this).apply {
            hint = "PIN admin"
            inputType = android.text.InputType.TYPE_CLASS_NUMBER or android.text.InputType.TYPE_NUMBER_VARIATION_PASSWORD
            setPadding(48, 12, 48, 12)
        }
        AlertDialog.Builder(this)
            .setTitle("Admin Adena")
            .setMessage("Masukkan PIN untuk keluar sementara dari Adena dan membuka launcher Infinix/Android.")
            .setView(input)
            .setPositiveButton("Buka") { dialog, _ ->
                val pin = input.text?.toString().orEmpty()
                if (kioskPrefs.verifyPin(pin)) {
                    exitToOriginalLauncherForAdmin()
                } else {
                    showToast("PIN salah.")
                    if (kioskPrefs.isKioskEnabled() && !kioskPrefs.isAdminUnlocked()) {
                        kioskManager.startKiosk(this, kioskPrefs.getAllowedPackages())
                    }
                }
                exitPinDialogShowing = false
                dialog.dismiss()
            }
            .setNegativeButton("Batal") { dialog, _ ->
                exitPinDialogShowing = false
                dialog.dismiss()
                if (kioskPrefs.isKioskEnabled() && !kioskPrefs.isAdminUnlocked()) {
                    kioskManager.startKiosk(this, kioskPrefs.getAllowedPackages())
                }
            }
            .setOnCancelListener {
                exitPinDialogShowing = false
                if (kioskPrefs.isKioskEnabled() && !kioskPrefs.isAdminUnlocked()) {
                    kioskManager.startKiosk(this, kioskPrefs.getAllowedPackages())
                }
            }
            .show()
    }


    private fun exitToOriginalLauncherForAdmin() {
        kioskPrefs.setAdminUnlockedFor(60)
        kioskManager.stopKiosk(this)
        runCatching { kioskManager.clearHomeLauncherAsDeviceOwner() }

        val launchers = kioskManager.getAlternativeHomeLaunchers()
        val preferredLauncher = launchers.firstOrNull { launcher ->
            val haystack = "${launcher.label} ${launcher.packageName}".lowercase(Locale.ROOT)
            haystack.contains("infinix") || haystack.contains("xos") || haystack.contains("launcher")
        } ?: launchers.firstOrNull()

        if (preferredLauncher != null) {
            showToast("Kiosk dibuka sementara. Membuka launcher asli.")
            runCatching {
                kioskManager.openHomeLauncher(this, preferredLauncher)
                moveTaskToBack(true)
            }.onFailure {
                Log.e(TAG, "Gagal membuka launcher asli dari tombol admin", it)
                kioskManager.openDefaultHomeSettings(this)
            }
        } else {
            showToast("Launcher asli tidak ditemukan. Buka pengaturan default launcher.")
            kioskManager.openDefaultHomeSettings(this)
        }
    }

    private fun showAdminPanelDialog() {
        val owner = if (kioskManager.isDeviceOwner()) "aktif" else "belum aktif"
        val home = if (kioskManager.isAdenaDefaultHome()) "Adena" else "bukan Adena"
        val overlay = if (kioskManager.hasOverlayPermission()) "aktif" else "belum aktif"
        val message = "Overlay: $overlay\nDefault launcher: $home\nDevice Owner/kiosk penuh: $owner\n\nAkses admin dibuka sementara 10 menit."
        val actions = arrayOf(
            "Pengaturan kiosk Adena",
            "Pilih default launcher/Home",
            "Buka launcher asli Infinix/Android",
            "Matikan kiosk sementara",
            "Kunci kembali sekarang",
        )
        AlertDialog.Builder(this)
            .setTitle("Area Admin Adena")
            .setMessage(message)
            .setItems(actions) { dialog, which ->
                when (which) {
                    0 -> startActivity(Intent(this, KioskSetupActivity::class.java))
                    1 -> kioskManager.openDefaultHomeSettings(this)
                    2 -> showOriginalLauncherChooser()
                    3 -> {
                        kioskPrefs.setAdminUnlockedFor(10)
                        kioskManager.stopKiosk(this)
                        showToast("Kiosk dibuka sementara 10 menit.")
                    }
                    4 -> {
                        kioskPrefs.clearAdminUnlock()
                        applyKioskIfNeeded()
                        showToast("Mode kiosk dikunci kembali.")
                    }
                }
                dialog.dismiss()
            }
            .setNegativeButton("Tutup", null)
            .show()
    }

    private fun showOriginalLauncherChooser() {
        val launchers = kioskManager.getAlternativeHomeLaunchers()
        if (launchers.isEmpty()) {
            showToast("Launcher asli tidak ditemukan. Buka pengaturan default launcher.")
            kioskManager.openDefaultHomeSettings(this)
            return
        }
        AlertDialog.Builder(this)
            .setTitle("Pilih Launcher Asli")
            .setItems(launchers.map { it.toString() }.toTypedArray()) { dialog, which ->
                kioskPrefs.setAdminUnlockedFor(10)
                kioskManager.stopKiosk(this)
                runCatching { kioskManager.openHomeLauncher(this, launchers[which]) }
                    .onFailure {
                        Log.e(TAG, "Gagal membuka launcher asli", it)
                        showToast("Launcher asli gagal dibuka. Buka pengaturan default launcher.")
                        kioskManager.openDefaultHomeSettings(this)
                    }
                dialog.dismiss()
            }
            .setNegativeButton("Batal", null)
            .show()
    }

    private fun applyKioskIfNeeded() {
        if (!::kioskPrefs.isInitialized || !kioskPrefs.isKioskEnabled()) return
        if (kioskPrefs.isAdminUnlocked()) {
            kioskManager.stopKiosk(this)
            return
        }
        if (!kioskManager.hasOverlayPermission()) {
            showToast("Izin tampil di atas aplikasi lain belum aktif.")
        }
        kioskManager.startKiosk(this, kioskPrefs.getAllowedPackages())
    }


    private fun launchFileChooser(fileChooserParams: WebChromeClient.FileChooserParams) {
        val cameraIntent = createImageCaptureIntent()
        val acceptTypes = fileChooserParams.acceptTypes?.joinToString(",").orEmpty().lowercase(Locale.ROOT)
        val wantsImage = acceptTypes.isBlank() || acceptTypes.contains("image") || acceptTypes.contains("jpg") || acceptTypes.contains("jpeg") || acceptTypes.contains("png")

        val contentIntent = runCatching { fileChooserParams.createIntent() }
            .getOrElse { Intent(Intent.ACTION_GET_CONTENT).apply { addCategory(Intent.CATEGORY_OPENABLE); type = "image/*" } }

        val intent = if (fileChooserParams.isCaptureEnabled && wantsImage && cameraIntent != null) {
            cameraIntent
        } else {
            Intent(Intent.ACTION_CHOOSER).apply {
                putExtra(Intent.EXTRA_INTENT, contentIntent)
                if (wantsImage && cameraIntent != null) {
                    putExtra(Intent.EXTRA_INITIAL_INTENTS, arrayOf(cameraIntent))
                }
            }
        }

        runCatching { fileChooserLauncher.launch(intent) }
            .onFailure {
                Log.e(TAG, "Gagal membuka kamera/file chooser QRIS", it)
                pendingFilePathCallback?.onReceiveValue(null)
                pendingFilePathCallback = null
                pendingCameraImageUri = null
                showToast("Kamera atau file chooser tidak bisa dibuka.")
            }
    }


    private fun compressImageUrisForUpload(uris: Array<Uri>): Array<Uri>? {
        if (uris.isEmpty()) return null

        return runCatching {
            uris.map { uri -> compressSingleImageForUpload(uri) }.toTypedArray()
        }.onFailure {
            Log.e(TAG, "Gagal kompres foto QRIS sebelum upload", it)
        }.getOrNull()
    }

    private fun compressSingleImageForUpload(sourceUri: Uri): Uri {
        val bounds = BitmapFactory.Options().apply { inJustDecodeBounds = true }
        openInputStreamOrThrow(sourceUri).use { input ->
            BitmapFactory.decodeStream(input, null, bounds)
        }
        if (bounds.outWidth <= 0 || bounds.outHeight <= 0) {
            return sourceUri
        }

        val decodeOptions = BitmapFactory.Options().apply {
            inSampleSize = calculateInSampleSize(bounds.outWidth, bounds.outHeight, MAX_UPLOAD_IMAGE_DIMENSION)
            inPreferredConfig = Bitmap.Config.RGB_565
        }

        val decodedBitmap = openInputStreamOrThrow(sourceUri).use { input ->
            BitmapFactory.decodeStream(input, null, decodeOptions)
        } ?: error("Foto tidak bisa diproses")

        val scaledBitmap = scaleBitmapIfNeeded(decodedBitmap, MAX_UPLOAD_IMAGE_DIMENSION)
        if (scaledBitmap !== decodedBitmap) decodedBitmap.recycle()

        val rotatedBitmap = rotateBitmapIfNeeded(scaledBitmap, sourceUri)
        if (rotatedBitmap !== scaledBitmap) scaledBitmap.recycle()

        val landscapeBitmap = forceLandscapeBitmap(rotatedBitmap)
        if (landscapeBitmap !== rotatedBitmap) rotatedBitmap.recycle()

        val compressedFile = File.createTempFile(QRIS_COMPRESSED_PREFIX, ".jpg", cacheDir)
        writeCompressedJpeg(landscapeBitmap, compressedFile)
        landscapeBitmap.recycle()

        if (compressedFile.length() > MAX_UPLOAD_IMAGE_BYTES) {
            compressedFile.delete()
            error("Foto QRIS masih lebih dari 1 MB setelah kompresi")
        }

        return FileProvider.getUriForFile(this, "${packageName}.fileprovider", compressedFile)
    }

    private fun openInputStreamOrThrow(uri: Uri): InputStream {
        return contentResolver.openInputStream(uri) ?: error("File foto tidak bisa dibaca")
    }

    private fun getContentLength(uri: Uri): Long {
        return runCatching {
            contentResolver.openAssetFileDescriptor(uri, "r")?.use { descriptor ->
                descriptor.length.takeIf { it > 0 }
            } ?: -1L
        }.getOrDefault(-1L)
    }

    private fun calculateInSampleSize(width: Int, height: Int, maxDimension: Int): Int {
        var sampleSize = 1
        var sampledWidth = width
        var sampledHeight = height
        while (sampledWidth / 2 >= maxDimension && sampledHeight / 2 >= maxDimension) {
            sampleSize *= 2
            sampledWidth /= 2
            sampledHeight /= 2
        }
        return sampleSize.coerceAtLeast(1)
    }

    private fun scaleBitmapIfNeeded(bitmap: Bitmap, maxDimension: Int): Bitmap {
        val maxSide = maxOf(bitmap.width, bitmap.height)
        if (maxSide <= maxDimension) return bitmap

        val scale = maxDimension.toFloat() / maxSide.toFloat()
        val width = (bitmap.width * scale).toInt().coerceAtLeast(1)
        val height = (bitmap.height * scale).toInt().coerceAtLeast(1)
        return Bitmap.createScaledBitmap(bitmap, width, height, true)
    }

    private fun writeCompressedJpeg(bitmap: Bitmap, outputFile: File) {
        var workingBitmap = bitmap
        var scaledBitmap: Bitmap? = null

        try {
            var quality = INITIAL_JPEG_QUALITY
            do {
                FileOutputStream(outputFile, false).use { output ->
                    workingBitmap.compress(Bitmap.CompressFormat.JPEG, quality, output)
                }
                quality -= JPEG_QUALITY_STEP
            } while (outputFile.length() > TARGET_UPLOAD_IMAGE_BYTES && quality >= MIN_JPEG_QUALITY)

            while (outputFile.length() > MAX_UPLOAD_IMAGE_BYTES && maxOf(workingBitmap.width, workingBitmap.height) > MIN_UPLOAD_IMAGE_DIMENSION) {
                val nextMaxSide = (maxOf(workingBitmap.width, workingBitmap.height) * UPLOAD_IMAGE_DOWNSCALE_RATIO).toInt()
                    .coerceAtLeast(MIN_UPLOAD_IMAGE_DIMENSION)
                val resized = scaleBitmapIfNeeded(workingBitmap, nextMaxSide)
                if (resized === workingBitmap) break
                scaledBitmap?.recycle()
                scaledBitmap = resized
                workingBitmap = resized
                quality = INITIAL_JPEG_QUALITY
                do {
                    FileOutputStream(outputFile, false).use { output ->
                        workingBitmap.compress(Bitmap.CompressFormat.JPEG, quality, output)
                    }
                    quality -= JPEG_QUALITY_STEP
                } while (outputFile.length() > TARGET_UPLOAD_IMAGE_BYTES && quality >= MIN_JPEG_QUALITY)
            }
        } finally {
            scaledBitmap?.recycle()
        }
    }

    private fun forceLandscapeBitmap(bitmap: Bitmap): Bitmap {
        if (bitmap.width >= bitmap.height) return bitmap
        val matrix = Matrix().apply { postRotate(90f) }
        return Bitmap.createBitmap(bitmap, 0, 0, bitmap.width, bitmap.height, matrix, true)
    }

    private fun rotateBitmapIfNeeded(bitmap: Bitmap, sourceUri: Uri): Bitmap {
        val orientation = runCatching {
            openInputStreamOrThrow(sourceUri).use { input ->
                ExifInterface(input).getAttributeInt(
                    ExifInterface.TAG_ORIENTATION,
                    ExifInterface.ORIENTATION_NORMAL,
                )
            }
        }.getOrDefault(ExifInterface.ORIENTATION_NORMAL)

        val degrees = when (orientation) {
            ExifInterface.ORIENTATION_ROTATE_90 -> 90f
            ExifInterface.ORIENTATION_ROTATE_180 -> 180f
            ExifInterface.ORIENTATION_ROTATE_270 -> 270f
            else -> 0f
        }

        if (degrees == 0f) return bitmap

        val matrix = Matrix().apply { postRotate(degrees) }
        return Bitmap.createBitmap(bitmap, 0, 0, bitmap.width, bitmap.height, matrix, true)
    }

    private fun createImageCaptureIntent(): Intent? {
        val intent = Intent(MediaStore.ACTION_IMAGE_CAPTURE)
        if (intent.resolveActivity(packageManager) == null) return null

        val timeStamp = SimpleDateFormat("yyyyMMdd_HHmmss", Locale.US).format(Date())
        val imageFile = File.createTempFile("qris_${timeStamp}_", ".jpg", externalCacheDir ?: cacheDir)
        val uri = FileProvider.getUriForFile(this, "${packageName}.fileprovider", imageFile)
        pendingCameraImageUri = uri

        return intent.apply {
            putExtra(MediaStore.EXTRA_OUTPUT, uri)
            addFlags(Intent.FLAG_GRANT_READ_URI_PERMISSION or Intent.FLAG_GRANT_WRITE_URI_PERMISSION)
        }
    }

    private fun isAppDebuggable(): Boolean {
        return (applicationInfo.flags and ApplicationInfo.FLAG_DEBUGGABLE) != 0
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
            Log.d(TAG, "Bridge payload parse result=ok type=${parsed.documentType} items=${parsed.items.size} total=${parsed.total}")
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
            val successMessage = if (payload.documentType == "sales_report") "Print laporan berhasil" else "Print receipt berhasil"
            showToast(successMessage)
            WebAppBridge.BridgeResult(true, "PRINT_OK", successMessage)
        } else {
            val error = result.exceptionOrNull()
            Log.e(TAG, "Bridge write printer gagal", error)
            val code = (error as? BluetoothPrinterManager.PrinterException)?.code ?: "PRINT_FAILED"
            val message = error?.message ?: if (payload.documentType == "sales_report") "Gagal mencetak laporan" else "Gagal mencetak receipt"
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

    private fun cleanupOldUploadCacheFiles() {
        lifecycleScope.launch(Dispatchers.IO) {
            cleanupSafeUploadCacheIfNeeded()
        }
    }

    private fun cleanupSafeUploadCacheIfNeeded() {
        val now = System.currentTimeMillis()
        val cacheFiles = listOfNotNull(cacheDir, externalCacheDir)
            .flatMap { dir -> dir.listFiles()?.toList().orEmpty() }
            .filter { file -> file.isFile && isSafeUploadCacheFile(file) }
            .distinctBy { it.absolutePath }

        val totalBytes = cacheFiles.sumOf { it.length().coerceAtLeast(0L) }
        val maxAgeMs = when {
            totalBytes >= SAFE_CACHE_HARD_LIMIT_BYTES -> SAFE_CACHE_HARD_MAX_AGE_MS
            totalBytes >= SAFE_CACHE_SOFT_LIMIT_BYTES -> SAFE_CACHE_SOFT_MAX_AGE_MS
            else -> QRIS_CACHE_MAX_AGE_MS
        }

        var deletedBytes = 0L
        var deletedCount = 0
        cacheFiles.forEach { file ->
            if (now - file.lastModified() > maxAgeMs) {
                val length = file.length().coerceAtLeast(0L)
                if (runCatching { file.delete() }.getOrDefault(false)) {
                    deletedBytes += length
                    deletedCount += 1
                }
            }
        }

        if (deletedCount > 0) {
            Log.d(TAG, "Auto cleanup cache aman deletedCount=$deletedCount deletedBytes=$deletedBytes totalBefore=$totalBytes")
        }
    }

    private fun isSafeUploadCacheFile(file: File): Boolean {
        val name = file.name.lowercase(Locale.ROOT)
        return name.startsWith(QRIS_CACHE_PREFIX) ||
            name.startsWith(QRIS_COMPRESSED_PREFIX) ||
            name.startsWith("adena_upload_") ||
            name.startsWith("compressed_") ||
            name.startsWith("webview_temp_")
    }

    override fun onSaveInstanceState(outState: Bundle) {
        outState.putString(STATE_CURRENT_URL, currentPageUrlSnapshot)
        super.onSaveInstanceState(outState)
    }

    override fun onResume() {
        super.onResume()
        if (!::binding.isInitialized) return
        binding.webView.onResume()
        binding.webView.resumeTimers()
        applyKioskIfNeeded()
    }

    override fun onPause() {
        if (::binding.isInitialized) {
            binding.webView.onPause()
            binding.webView.pauseTimers()
        }
        super.onPause()
    }

    override fun onTrimMemory(level: Int) {
        super.onTrimMemory(level)
        if (::binding.isInitialized && level >= ComponentCallbacks2.TRIM_MEMORY_RUNNING_LOW) {
            binding.webView.clearCache(false)
        }
    }

    override fun onDestroy() {
        pendingFilePathCallback?.onReceiveValue(null)
        pendingFilePathCallback = null
        pendingCameraImageUri = null
        if (::printerManager.isInitialized) {
            printerManager.disconnect()
        }
        if (::binding.isInitialized) {
            binding.webView.apply {
                stopLoading()
                webChromeClient = null
                webViewClient = WebViewClient()
                removeJavascriptInterface("AndroidBridge")
                destroy()
            }
        }
        super.onDestroy()
    }

    companion object {
        private const val TAG = "MainActivity"
        private const val LOGIN_URL = "https://adena.co.id/adm.php"
        private const val TRUSTED_HOST = "adena.co.id"
        private const val STATE_CURRENT_URL = "adena_current_url"
        private const val MAX_UPLOAD_IMAGE_BYTES = 1 * 1024 * 1024
        private const val TARGET_UPLOAD_IMAGE_BYTES = 900 * 1024
        private const val MAX_UPLOAD_IMAGE_DIMENSION = 1280
        private const val MIN_UPLOAD_IMAGE_DIMENSION = 720
        private const val INITIAL_JPEG_QUALITY = 84
        private const val MIN_JPEG_QUALITY = 46
        private const val JPEG_QUALITY_STEP = 8
        private const val UPLOAD_IMAGE_DOWNSCALE_RATIO = 0.85f
        private const val QRIS_CACHE_PREFIX = "qris_"
        private const val QRIS_COMPRESSED_PREFIX = "qris_upload_compressed_"
        private const val QRIS_CACHE_MAX_AGE_MS = 24 * 60 * 60 * 1000L
        private const val SAFE_CACHE_SOFT_LIMIT_BYTES = 50L * 1024L * 1024L
        private const val SAFE_CACHE_HARD_LIMIT_BYTES = 100L * 1024L * 1024L
        private const val SAFE_CACHE_SOFT_MAX_AGE_MS = 24 * 60 * 60 * 1000L
        private const val SAFE_CACHE_HARD_MAX_AGE_MS = 6 * 60 * 60 * 1000L
        private const val HIDDEN_ADMIN_ZONE_DP = 112
        private const val HIDDEN_ADMIN_TAP_COUNT = 5
        private const val HIDDEN_ADMIN_TAP_WINDOW_MS = 2200L
    }
}
