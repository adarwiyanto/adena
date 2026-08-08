package id.co.adena.pos.ui

import android.content.Intent
import android.content.res.ColorStateList
import android.graphics.Color
import android.graphics.Typeface
import android.graphics.drawable.Drawable
import android.graphics.drawable.GradientDrawable
import android.net.ConnectivityManager
import android.net.NetworkCapabilities
import android.net.Uri
import android.os.Bundle
import android.os.Handler
import android.os.Looper
import android.provider.Settings
import android.text.TextUtils
import android.view.Gravity
import android.view.View
import android.view.ViewGroup
import android.widget.Button
import android.widget.FrameLayout
import android.widget.GridLayout
import android.widget.ImageView
import android.widget.LinearLayout
import android.widget.ScrollView
import android.widget.TextView
import android.widget.Toast
import androidx.activity.OnBackPressedCallback
import androidx.appcompat.app.AlertDialog
import androidx.appcompat.app.AppCompatActivity
import androidx.core.view.WindowCompat
import androidx.core.view.WindowInsetsCompat
import androidx.core.view.WindowInsetsControllerCompat
import id.co.adena.pos.R
import id.co.adena.pos.data.PrinterPrefs
import id.co.adena.pos.kiosk.KioskManager
import id.co.adena.pos.kiosk.KioskPrefs
import java.text.SimpleDateFormat
import java.util.Date
import java.util.Locale

/**
 * Home launcher Adena - Design 4.
 * Dioptimalkan untuk Infinix XPAD 20 Pro landscape 2000 x 1200 @ 240 dpi.
 */
class AdenaHomeActivity : AppCompatActivity() {
    private lateinit var prefs: KioskPrefs
    private lateinit var printerPrefs: PrinterPrefs
    private lateinit var kioskManager: KioskManager
    private lateinit var rootFrame: FrameLayout
    private lateinit var backgroundImage: ImageView
    private lateinit var timeText: TextView
    private lateinit var dateText: TextView
    private lateinit var connectionText: TextView
    private lateinit var printerText: TextView
    private lateinit var connectionIcon: ImageView
    private lateinit var printerIcon: ImageView
    private var uiReady = false

    private val clockHandler = Handler(Looper.getMainLooper())
    private val clockRunnable = object : Runnable {
        override fun run() {
            updateClockAndStatus()
            clockHandler.postDelayed(this, 1_000L)
        }
    }

    override fun onCreate(savedInstanceState: Bundle?) {
        super.onCreate(savedInstanceState)
        prefs = KioskPrefs(this)
        printerPrefs = PrinterPrefs(this)
        kioskManager = KioskManager(this)

        if (!prefs.isSetupComplete()) {
            startActivity(Intent(this, KioskSetupActivity::class.java))
            finish()
            return
        }

        enableImmersiveMode()
        buildUi()
        uiReady = true
        showLauncherSplash()

        onBackPressedDispatcher.addCallback(this, object : OnBackPressedCallback(true) {
            override fun handleOnBackPressed() {
                Toast.makeText(this@AdenaHomeActivity, "Adena Launcher aktif.", Toast.LENGTH_SHORT).show()
            }
        })
    }

    override fun onResume() {
        super.onResume()
        if (!uiReady || isFinishing || isDestroyed) return

        enableImmersiveMode()
        applyLauncherBackground()
        updateClockAndStatus()
        clockHandler.removeCallbacks(clockRunnable)
        clockHandler.post(clockRunnable)
        runCatching { applyKioskIfNeeded() }
            .onFailure { android.util.Log.e(TAG, "Gagal menerapkan mode kiosk", it) }
    }

    override fun onWindowFocusChanged(hasFocus: Boolean) {
        super.onWindowFocusChanged(hasFocus)
        if (hasFocus && uiReady) enableImmersiveMode()
    }

    override fun onPause() {
        clockHandler.removeCallbacks(clockRunnable)
        super.onPause()
    }

    private fun enableImmersiveMode() {
        WindowCompat.setDecorFitsSystemWindows(window, false)
        window.statusBarColor = Color.TRANSPARENT
        window.navigationBarColor = Color.TRANSPARENT
        WindowInsetsControllerCompat(window, window.decorView).apply {
            hide(WindowInsetsCompat.Type.systemBars())
            systemBarsBehavior = WindowInsetsControllerCompat.BEHAVIOR_SHOW_TRANSIENT_BARS_BY_SWIPE
        }
    }

    private fun applyKioskIfNeeded() {
        if (!prefs.isKioskEnabled()) return
        if (prefs.isAdminUnlocked()) {
            kioskManager.stopKiosk(this)
            return
        }
        kioskManager.startKiosk(this, prefs.getAllowedPackages())
    }

    private fun buildUi() {
        rootFrame = FrameLayout(this).apply { setBackgroundColor(Color.rgb(31, 22, 18)) }

        backgroundImage = ImageView(this).apply {
            setImageResource(R.drawable.adena_home_ketam_isi)
            scaleType = ImageView.ScaleType.FIT_XY
            layoutParams = FrameLayout.LayoutParams(
                ViewGroup.LayoutParams.MATCH_PARENT,
                ViewGroup.LayoutParams.MATCH_PARENT,
            )
        }
        rootFrame.addView(backgroundImage)

        // Vignette kiri seperti mockup Design 4: jam/status besar tetap terbaca,
        // sedangkan foto dan logo Adena tetap dominan di tengah-kanan.
        rootFrame.addView(View(this).apply {
            background = GradientDrawable(
                GradientDrawable.Orientation.LEFT_RIGHT,
                intArrayOf(
                    Color.argb(184, 20, 13, 10),
                    Color.argb(118, 20, 13, 10),
                    Color.argb(0, 20, 13, 10),
                ),
            )
            layoutParams = FrameLayout.LayoutParams(dp(520), ViewGroup.LayoutParams.MATCH_PARENT, Gravity.START)
        })

        val clockBlock = LinearLayout(this).apply {
            orientation = LinearLayout.VERTICAL
            gravity = Gravity.START
            setPadding(dp(40), dp(34), dp(18), dp(18))
            layoutParams = FrameLayout.LayoutParams(
                dp(430),
                ViewGroup.LayoutParams.WRAP_CONTENT,
                Gravity.TOP or Gravity.START,
            )
        }

        timeText = TextView(this).apply {
            textSize = 56f
            setTypeface(typeface, Typeface.BOLD)
            setTextColor(Color.WHITE)
            includeFontPadding = false
            letterSpacing = -0.015f
        }
        dateText = TextView(this).apply {
            textSize = 19f
            setTypeface(typeface, Typeface.BOLD)
            setTextColor(Color.WHITE)
            setPadding(0, dp(5), 0, dp(16))
        }

        val onlinePill = statusPill(R.drawable.ic_adena_online)
        connectionIcon = onlinePill.icon
        connectionText = onlinePill.label
        onlinePill.container.apply {
            isClickable = true
            isFocusable = true
            foreground = selectableItemBackground()
            setOnClickListener { openWifiPanel() }
        }

        val savedPrinterPill = statusPill(R.drawable.ic_adena_printer)
        printerIcon = savedPrinterPill.icon
        printerText = savedPrinterPill.label
        savedPrinterPill.container.apply {
            isClickable = true
            isFocusable = true
            foreground = selectableItemBackground()
            setOnClickListener {
                startActivity(Intent(this@AdenaHomeActivity, PrinterSettingsActivity::class.java))
            }
        }

        clockBlock.addView(timeText)
        clockBlock.addView(dateText)
        clockBlock.addView(onlinePill.container)
        clockBlock.addView(savedPrinterPill.container)
        rootFrame.addView(clockBlock)

        val commandPanel = LinearLayout(this).apply {
            orientation = LinearLayout.HORIZONTAL
            gravity = Gravity.CENTER
            background = roundedDrawable(
                Color.argb(244, 255, 252, 248),
                dp(27),
                Color.argb(70, 83, 46, 26),
            )
            elevation = dp(12).toFloat()
            setPadding(dp(10), dp(8), dp(10), dp(8))
            layoutParams = FrameLayout.LayoutParams(
                dp(1040),
                dp(140),
                Gravity.BOTTOM or Gravity.CENTER_HORIZONTAL,
            ).apply {
                bottomMargin = dp(34)
            }
        }

        commandPanel.addView(commandButton(
            title = "POS",
            subtitle = "Mulai Transaksi",
            iconRes = R.drawable.ic_adena_pos,
        ) {
            startActivity(Intent(this, SyncActivity::class.java).addFlags(Intent.FLAG_ACTIVITY_CLEAR_TOP))
        })
        commandPanel.addView(verticalDivider())
        commandPanel.addView(commandButton(
            title = "Apps",
            subtitle = "Aplikasi Diizinkan",
            iconRes = R.drawable.ic_adena_apps,
        ) {
            showAppsDialog()
        })
        commandPanel.addView(verticalDivider())
        commandPanel.addView(commandButton(
            title = "Pengaturan",
            subtitle = "Aplikasi & Sistem",
            iconRes = R.drawable.ic_adena_settings,
        ) {
            requireAdminPin("Pengaturan Launcher Adena") {
                startActivity(Intent(this, AdenaLauncherSettingsActivity::class.java))
            }
        })
        commandPanel.addView(verticalDivider())
        commandPanel.addView(commandButton(
            title = "Keluar",
            subtitle = "Mode Admin",
            iconRes = R.drawable.ic_adena_exit,
        ) {
            requireAdminPin("Keluar dari mode launcher") {
                openOriginalLauncher()
            }
        })

        rootFrame.addView(commandPanel)
        setContentView(rootFrame)
        updateClockAndStatus()
    }

    private fun statusPill(iconRes: Int): StatusPill {
        val icon = ImageView(this).apply {
            setImageResource(iconRes)
            imageTintList = ColorStateList.valueOf(Color.rgb(25, 126, 55))
            scaleType = ImageView.ScaleType.FIT_CENTER
            layoutParams = LinearLayout.LayoutParams(dp(29), dp(29)).apply {
                setMargins(0, 0, dp(11), 0)
            }
        }
        val label = TextView(this).apply {
            textSize = 17f
            setTextColor(Color.rgb(25, 126, 55))
            gravity = Gravity.CENTER_VERTICAL
            includeFontPadding = false
            maxLines = 1
        }
        val container = LinearLayout(this).apply {
            orientation = LinearLayout.HORIZONTAL
            gravity = Gravity.CENTER_VERTICAL
            background = roundedDrawable(
                Color.argb(244, 255, 255, 255),
                dp(28),
                Color.argb(45, 0, 0, 0),
            )
            setPadding(dp(16), dp(10), dp(18), dp(10))
            layoutParams = LinearLayout.LayoutParams(
                ViewGroup.LayoutParams.WRAP_CONTENT,
                dp(52),
            ).apply {
                setMargins(0, 0, 0, dp(9))
            }
            addView(icon)
            addView(label)
        }
        return StatusPill(container, icon, label)
    }

    private fun commandButton(title: String, subtitle: String, iconRes: Int, action: () -> Unit): LinearLayout =
        LinearLayout(this).apply {
            orientation = LinearLayout.VERTICAL
            gravity = Gravity.CENTER
            isClickable = true
            isFocusable = true
            foreground = selectableItemBackground()
            setPadding(dp(10), dp(4), dp(10), dp(3))
            layoutParams = LinearLayout.LayoutParams(0, ViewGroup.LayoutParams.MATCH_PARENT, 1f)
            setOnClickListener { action() }

            addView(ImageView(context).apply {
                setImageResource(iconRes)
                imageTintList = ColorStateList.valueOf(ADENA_ORANGE)
                scaleType = ImageView.ScaleType.FIT_CENTER
                layoutParams = LinearLayout.LayoutParams(dp(54), dp(54)).apply {
                    setMargins(0, 0, 0, dp(5))
                }
            })
            addView(TextView(context).apply {
                text = title
                textSize = 18f
                setTypeface(typeface, Typeface.BOLD)
                setTextColor(Color.rgb(35, 27, 23))
                gravity = Gravity.CENTER
                maxLines = 1
            })
            addView(TextView(context).apply {
                text = subtitle
                textSize = 12f
                setTextColor(Color.rgb(67, 56, 50))
                gravity = Gravity.CENTER
                maxLines = 1
            })
        }

    private fun verticalDivider(): View = View(this).apply {
        setBackgroundColor(Color.argb(43, 45, 30, 20))
        layoutParams = LinearLayout.LayoutParams(dp(1), dp(92)).apply {
            gravity = Gravity.CENTER_VERTICAL
        }
    }

    private fun showAppsDialog() {
        val packages = prefs.getAllowedPackages()
            .asSequence()
            .filter { it.isNotBlank() && it != packageName }
            .distinct()
            .mapNotNull { packageName ->
                val launchIntent = packageManager.getLaunchIntentForPackage(packageName) ?: return@mapNotNull null
                val appInfo = runCatching { packageManager.getApplicationInfo(packageName, 0) }.getOrNull()
                    ?: return@mapNotNull null
                val label = runCatching { packageManager.getApplicationLabel(appInfo).toString() }
                    .getOrDefault(packageName)
                val icon = runCatching { packageManager.getApplicationIcon(appInfo) }.getOrNull()
                AllowedApp(label, launchIntent, icon)
            }
            .toList()

        val content = if (packages.isEmpty()) {
            TextView(this).apply {
                text = "Belum ada aplikasi yang ditambahkan."
                textSize = 17f
                gravity = Gravity.CENTER
                setTextColor(Color.rgb(70, 58, 51))
                setPadding(dp(28), dp(42), dp(28), dp(42))
            }
        } else {
            GridLayout(this).apply {
                columnCount = 4
                alignmentMode = GridLayout.ALIGN_BOUNDS
                useDefaultMargins = true
                setPadding(dp(18), dp(12), dp(18), dp(18))
                packages.forEach { app -> addView(allowedAppTile(app)) }
            }
        }

        val scroll = ScrollView(this).apply {
            isFillViewport = true
            addView(content)
        }

        AlertDialog.Builder(this)
            .setTitle("Apps")
            .setView(scroll)
            .setNegativeButton("Tutup", null)
            .show()
            .also { dialog ->
                dialog.window?.setLayout(dp(900), ViewGroup.LayoutParams.WRAP_CONTENT)
            }
    }

    private fun allowedAppTile(app: AllowedApp): View = LinearLayout(this).apply {
        orientation = LinearLayout.VERTICAL
        gravity = Gravity.CENTER
        isClickable = true
        isFocusable = true
        foreground = selectableItemBackground()
        background = roundedDrawable(Color.rgb(255, 252, 248), dp(20), Color.argb(45, 0, 0, 0))
        setPadding(dp(12), dp(15), dp(12), dp(11))
        layoutParams = GridLayout.LayoutParams().apply {
            width = dp(190)
            height = dp(142)
            setMargins(dp(7), dp(7), dp(7), dp(7))
        }
        setOnClickListener {
            runCatching {
                startActivity(app.launchIntent.addFlags(Intent.FLAG_ACTIVITY_RESET_TASK_IF_NEEDED))
            }.onFailure {
                Toast.makeText(this@AdenaHomeActivity, "${app.label} tidak dapat dibuka.", Toast.LENGTH_LONG).show()
            }
        }
        addView(ImageView(context).apply {
            setImageDrawable(app.icon)
            scaleType = ImageView.ScaleType.FIT_CENTER
            layoutParams = LinearLayout.LayoutParams(dp(66), dp(66)).apply {
                setMargins(0, 0, 0, dp(8))
            }
        })
        addView(TextView(context).apply {
            text = app.label
            textSize = 14f
            setTypeface(typeface, Typeface.BOLD)
            setTextColor(Color.rgb(43, 32, 27))
            gravity = Gravity.CENTER
            maxLines = 2
            ellipsize = TextUtils.TruncateAt.END
        })
    }

    private fun openWifiPanel() {
        // Settings Panel berjalan sebagai komponen sistem; lepaskan lock task sementara
        // lalu onResume() akan mengaktifkan kiosk kembali setelah panel ditutup.
        kioskManager.stopKiosk(this)
        val panelIntent = Intent(Settings.Panel.ACTION_WIFI).addFlags(Intent.FLAG_ACTIVITY_NEW_TASK)
        runCatching { startActivity(panelIntent) }
            .recoverCatching { startActivity(Intent(Settings.ACTION_WIFI_SETTINGS)) }
            .onFailure {
                Toast.makeText(this, "Pengaturan Wi-Fi tidak dapat dibuka.", Toast.LENGTH_LONG).show()
            }
    }

    private fun openOriginalLauncher() {
        prefs.setAdminUnlockedFor(10)
        kioskManager.stopKiosk(this)
        runCatching { kioskManager.clearHomeLauncherAsDeviceOwner() }
        if (kioskManager.openPreferredOriginalLauncher(this)) {
            moveTaskToBack(true)
        } else {
            Toast.makeText(this, "Launcher Infinix tidak ditemukan. Membuka pengaturan Home.", Toast.LENGTH_LONG).show()
            kioskManager.openDefaultHomeSettings(this)
        }
    }

    private fun updateClockAndStatus() {
        if (!::timeText.isInitialized) return
        val now = Date()
        timeText.text = SimpleDateFormat("HH:mm", Locale("id", "ID")).format(now)
        dateText.text = SimpleDateFormat("EEEE, d MMMM yyyy", Locale("id", "ID")).format(now)

        val online = isOnline()
        val connectionColor = if (online) Color.rgb(25, 126, 55) else Color.rgb(177, 44, 34)
        connectionText.text = if (online) "Online" else "Offline"
        connectionText.setTextColor(connectionColor)
        connectionIcon.imageTintList = ColorStateList.valueOf(connectionColor)

        val printerSaved = !printerPrefs.getPrinterMac().isNullOrBlank()
        val printerColor = if (printerSaved) Color.rgb(25, 126, 55) else Color.rgb(177, 92, 26)
        printerText.text = if (printerSaved) "Printer: Terpilih" else "Printer: Belum dipilih"
        printerText.setTextColor(printerColor)
        printerIcon.imageTintList = ColorStateList.valueOf(printerColor)
    }

    private fun isOnline(): Boolean = runCatching {
        val manager = getSystemService(ConnectivityManager::class.java)
            ?: return@runCatching false
        val network = manager.activeNetwork
            ?: return@runCatching false
        val capabilities = manager.getNetworkCapabilities(network)
            ?: return@runCatching false

        capabilities.hasCapability(NetworkCapabilities.NET_CAPABILITY_INTERNET) &&
            capabilities.hasCapability(NetworkCapabilities.NET_CAPABILITY_VALIDATED)
    }.onFailure {
        android.util.Log.w(TAG, "Gagal membaca status jaringan", it)
    }.getOrDefault(false)

    private fun requireAdminPin(title: String, onSuccess: () -> Unit) {
        var enteredPin = ""
        var completed = false

        val pinDots = TextView(this).apply {
            text = "Masukkan PIN admin"
            textSize = 19f
            setTypeface(typeface, Typeface.BOLD)
            gravity = Gravity.CENTER
            setTextColor(Color.rgb(48, 37, 31))
            setPadding(dp(16), dp(14), dp(16), dp(12))
        }
        val message = TextView(this).apply {
            text = "PIN akan diproses otomatis saat benar."
            textSize = 13f
            gravity = Gravity.CENTER
            setTextColor(Color.rgb(100, 83, 72))
            setPadding(dp(12), 0, dp(12), dp(10))
        }
        val keypad = GridLayout(this).apply {
            columnCount = 3
            rowCount = 4
            alignmentMode = GridLayout.ALIGN_BOUNDS
            setPadding(dp(12), dp(4), dp(12), dp(12))
        }
        val wrapper = LinearLayout(this).apply {
            orientation = LinearLayout.VERTICAL
            setPadding(dp(8), dp(4), dp(8), dp(4))
            addView(pinDots)
            addView(message)
            addView(keypad)
        }

        lateinit var dialog: AlertDialog
        fun refreshDots() {
            pinDots.text = if (enteredPin.isEmpty()) "Masukkan PIN admin" else "● ".repeat(enteredPin.length).trim()
        }
        fun appendDigit(digit: String) {
            if (completed || enteredPin.length >= 12) return
            enteredPin += digit
            refreshDots()
            if (prefs.verifyPin(enteredPin)) {
                completed = true
                dialog.dismiss()
                onSuccess()
            } else if (enteredPin.length >= 12) {
                enteredPin = ""
                refreshDots()
                message.text = "PIN salah. Silakan coba lagi."
                message.setTextColor(Color.rgb(177, 44, 34))
            }
        }
        fun keypadButton(label: String, action: () -> Unit): Button = Button(this).apply {
            text = label
            textSize = 21f
            isAllCaps = false
            setTypeface(typeface, Typeface.BOLD)
            layoutParams = GridLayout.LayoutParams().apply {
                width = dp(112)
                height = dp(62)
                setMargins(dp(5), dp(5), dp(5), dp(5))
            }
            setOnClickListener { action() }
        }

        listOf("1", "2", "3", "4", "5", "6", "7", "8", "9").forEach { digit ->
            keypad.addView(keypadButton(digit) { appendDigit(digit) })
        }
        keypad.addView(keypadButton("Batal") { dialog.dismiss() }.apply { textSize = 14f })
        keypad.addView(keypadButton("0") { appendDigit("0") })
        keypad.addView(keypadButton("⌫") {
            if (enteredPin.isNotEmpty()) enteredPin = enteredPin.dropLast(1)
            message.text = "PIN akan diproses otomatis saat benar."
            message.setTextColor(Color.rgb(100, 83, 72))
            refreshDots()
        })

        dialog = AlertDialog.Builder(this)
            .setTitle(title)
            .setView(wrapper)
            .create()
        dialog.setCanceledOnTouchOutside(false)
        dialog.show()
    }

    private fun showLauncherSplash() {
        val splash = FrameLayout(this).apply {
            setBackgroundColor(Color.rgb(248, 244, 238))
            alpha = 1f
            isClickable = true
            elevation = dp(12).toFloat()
        }
        splash.addView(ImageView(this).apply {
            setImageResource(R.drawable.adena_launcher_splash)
            adjustViewBounds = true
            scaleType = ImageView.ScaleType.FIT_CENTER
            layoutParams = FrameLayout.LayoutParams(dp(330), dp(330), Gravity.CENTER)
        })
        rootFrame.addView(
            splash,
            FrameLayout.LayoutParams(
                ViewGroup.LayoutParams.MATCH_PARENT,
                ViewGroup.LayoutParams.MATCH_PARENT,
            ),
        )
        splash.postDelayed({
            splash.animate()
                .alpha(0f)
                .setDuration(350)
                .withEndAction { rootFrame.removeView(splash) }
                .start()
        }, 800)
    }

    private fun applyLauncherBackground() {
        if (!uiReady || !::backgroundImage.isInitialized) return

        val customUri = prefs.getLauncherBackgroundUri()
        if (customUri.isNullOrBlank()) {
            useDefaultLauncherBackground()
            return
        }

        val loaded = runCatching {
            val uri = Uri.parse(customUri)
            contentResolver.openInputStream(uri)?.use { input ->
                val drawable = Drawable.createFromStream(input, uri.toString())
                    ?: error("Background tidak dapat dibaca")
                backgroundImage.scaleType = ImageView.ScaleType.CENTER_CROP
                backgroundImage.setImageDrawable(drawable)
                backgroundImage.visibility = View.VISIBLE
            } ?: error("Background tidak ditemukan")
            true
        }.getOrElse {
            android.util.Log.w(TAG, "Background kustom tidak valid; menggunakan default", it)
            false
        }

        if (!loaded) {
            prefs.clearLauncherBackgroundUri()
            useDefaultLauncherBackground()
        }
    }

    private fun useDefaultLauncherBackground() {
        if (!::backgroundImage.isInitialized) return
        backgroundImage.scaleType = ImageView.ScaleType.FIT_XY
        backgroundImage.setImageResource(R.drawable.adena_home_ketam_isi)
        backgroundImage.visibility = View.VISIBLE
    }

    private fun selectableItemBackground(): Drawable? {
        val attrs = intArrayOf(android.R.attr.selectableItemBackground)
        val typedArray = obtainStyledAttributes(attrs)
        return typedArray.getDrawable(0).also { typedArray.recycle() }
    }

    private fun roundedDrawable(
        color: Int,
        radius: Int,
        strokeColor: Int = Color.TRANSPARENT,
    ): GradientDrawable = GradientDrawable().apply {
        setColor(color)
        cornerRadius = radius.toFloat()
        if (strokeColor != Color.TRANSPARENT) setStroke(dp(1), strokeColor)
    }

    private fun dp(value: Int): Int = (value * resources.displayMetrics.density).toInt()

    private data class StatusPill(
        val container: LinearLayout,
        val icon: ImageView,
        val label: TextView,
    )

    private data class AllowedApp(
        val label: String,
        val launchIntent: Intent,
        val icon: Drawable?,
    )

    companion object {
        private const val TAG = "AdenaHomeActivity"
        private val ADENA_ORANGE = Color.rgb(221, 82, 31)
    }
}
