package id.co.adena.pos.ui

import android.content.Intent
import android.graphics.Typeface
import android.graphics.drawable.Drawable
import android.net.Uri
import android.os.Bundle
import android.text.InputType
import android.view.Gravity
import android.view.View
import android.view.ViewGroup
import android.widget.Button
import android.widget.EditText
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
import id.co.adena.pos.kiosk.KioskManager
import id.co.adena.pos.kiosk.KioskPrefs

class AdenaLauncherSettingsActivity : AppCompatActivity() {
    private lateinit var prefs: KioskPrefs
    private lateinit var kioskManager: KioskManager
    private lateinit var backgroundStatus: TextView
    private lateinit var activeAppsGrid: GridLayout
    private lateinit var availableAppsGrid: GridLayout
    private lateinit var activeAppsHint: TextView
    private lateinit var availableAppsHint: TextView
    private var installedApps: List<AppChoice> = emptyList()

    override fun onCreate(savedInstanceState: Bundle?) {
        super.onCreate(savedInstanceState)
        prefs = KioskPrefs(this)
        kioskManager = KioskManager(this)
        buildUi()
        onBackPressedDispatcher.addCallback(this, object : OnBackPressedCallback(true) {
            override fun handleOnBackPressed() { finish() }
        })
    }

    override fun onResume() {
        super.onResume()
        // Jangan full-scan daftar aplikasi setiap resume. Pada Android 15/Infinix,
        // query semua aplikasi + load ikon di main thread dapat memperberat transisi kiosk.
        // Daftar aplikasi tetap dimuat saat halaman dibuat, dan bisa diperbarui manual via tombol refresh.
    }

    private fun buildUi() {
        val root = ScrollView(this).apply { isFillViewport = true }
        val content = LinearLayout(this).apply {
            orientation = LinearLayout.VERTICAL
            setPadding(dp(24), dp(22), dp(24), dp(22))
        }
        root.addView(
            content,
            FrameLayout.LayoutParams(ViewGroup.LayoutParams.MATCH_PARENT, ViewGroup.LayoutParams.WRAP_CONTENT),
        )

        content.addView(TextView(this).apply {
            text = "Pengaturan Launcher Adena"
            textSize = 26f
            setTypeface(typeface, Typeface.BOLD)
        })
        content.addView(TextView(this).apply {
            text = "Atur tampilan launcher, aplikasi yang boleh tampil, dan pengaturan admin."
            textSize = 14f
            setPadding(0, dp(6), 0, dp(14))
        })

        content.addView(statusText())

        content.addView(sectionTitle("Background Launcher"))
        backgroundStatus = TextView(this).apply {
            textSize = 14f
            setPadding(0, 0, 0, dp(6))
        }
        content.addView(backgroundStatus)
        content.addView(bigButton("Pilih Background dari Galeri") { pickLauncherBackground() })
        content.addView(bigButton("Hapus Background Custom") { clearLauncherBackground() })
        refreshBackgroundStatus()

        refreshInstalledApps(showToast = false)

        content.addView(sectionTitle("Aplikasi di Launcher"))
        activeAppsHint = TextView(this).apply {
            text = "Ketuk ikon aplikasi untuk mengeluarkannya dari launcher. Aplikasi tidak di-uninstall."
            textSize = 13f
            setPadding(0, 0, 0, dp(8))
        }
        content.addView(activeAppsHint)
        activeAppsGrid = appGrid()
        content.addView(activeAppsGrid)

        content.addView(sectionTitle("Tambah Aplikasi"))
        availableAppsHint = TextView(this).apply {
            text = "Ketuk ikon aplikasi yang ingin ditampilkan di launcher."
            textSize = 13f
            setPadding(0, 0, 0, dp(8))
        }
        content.addView(availableAppsHint)
        content.addView(bigButton("Refresh Daftar Aplikasi") { refreshInstalledApps(showToast = true) })
        availableAppsGrid = appGrid()
        content.addView(availableAppsGrid)

        content.addView(sectionTitle("Admin"))
        content.addView(bigButton("Ganti PIN Admin") { showChangePinDialog() })
        content.addView(bigButton("Buka Default Home Settings") { kioskManager.openDefaultHomeSettings(this) })
        content.addView(bigButton("Buka Launcher Infinix/Android") { openOriginalLauncherForAdmin() })
        content.addView(bigButton("Buka Printer Settings Adena") { startActivity(Intent(this, PrinterSettingsActivity::class.java)) })
        content.addView(bigButton("Reset Setup Launcher") { confirmResetSetup() })
        content.addView(bigButton("Kembali ke Launcher") { finish() })

        setContentView(root)
        refreshAppGrids()
    }

    private fun statusText(): TextView {
        val home = if (kioskManager.isAdenaDefaultHome()) "Adena sudah menjadi Home default" else "Adena belum menjadi Home default"
        val owner = if (kioskManager.isDeviceOwner()) "Device Owner aktif" else "Device Owner belum aktif"
        return TextView(this).apply {
            text = "$home\n$owner"
            textSize = 14f
            setPadding(0, 0, 0, dp(10))
        }
    }

    private fun pickLauncherBackground() {
        val intent = Intent(Intent.ACTION_OPEN_DOCUMENT).apply {
            addCategory(Intent.CATEGORY_OPENABLE)
            type = "image/*"
            addFlags(Intent.FLAG_GRANT_READ_URI_PERMISSION)
            addFlags(Intent.FLAG_GRANT_PERSISTABLE_URI_PERMISSION)
        }
        runCatching { startActivityForResult(intent, REQUEST_PICK_BACKGROUND) }
            .onFailure { Toast.makeText(this, "Galeri tidak bisa dibuka.", Toast.LENGTH_LONG).show() }
    }

    @Deprecated("Deprecated in Java")
    override fun onActivityResult(requestCode: Int, resultCode: Int, data: Intent?) {
        super.onActivityResult(requestCode, resultCode, data)
        if (requestCode != REQUEST_PICK_BACKGROUND || resultCode != RESULT_OK) return
        val uri = data?.data ?: return
        val flags = data.flags and Intent.FLAG_GRANT_READ_URI_PERMISSION
        runCatching { contentResolver.takePersistableUriPermission(uri, flags) }
        prefs.setLauncherBackgroundUri(uri.toString())
        refreshBackgroundStatus()
        Toast.makeText(this, "Background launcher disimpan.", Toast.LENGTH_SHORT).show()
    }

    private fun clearLauncherBackground() {
        prefs.getLauncherBackgroundUri()?.let { rawUri ->
            runCatching { contentResolver.releasePersistableUriPermission(Uri.parse(rawUri), Intent.FLAG_GRANT_READ_URI_PERMISSION) }
        }
        prefs.clearLauncherBackgroundUri()
        refreshBackgroundStatus()
        Toast.makeText(this, "Background custom dihapus.", Toast.LENGTH_SHORT).show()
    }

    private fun refreshBackgroundStatus() {
        if (!::backgroundStatus.isInitialized) return
        backgroundStatus.text = if (prefs.getLauncherBackgroundUri().isNullOrBlank()) {
            "Background aktif: Ketam Isi Design 4 (XPAD 20 Pro)."
        } else {
            "Background aktif: gambar custom dari galeri."
        }
    }

    private fun refreshInstalledApps(showToast: Boolean) {
        installedApps = loadLaunchableApps()
        if (::activeAppsGrid.isInitialized && ::availableAppsGrid.isInitialized) {
            refreshAppGrids()
        }
        if (showToast) {
            Toast.makeText(this, "Daftar aplikasi diperbarui.", Toast.LENGTH_SHORT).show()
        }
    }

    private fun refreshAppGrids() {
        val allowedPackages = prefs.getAllowedPackages().filter { it.isNotBlank() }.distinct()
        val allowedSet = allowedPackages.toSet()
        val activeApps = allowedPackages.map { packageName ->
            AppChoice(getAppLabel(packageName), packageName, getAppIcon(packageName))
        }
        val availableApps = installedApps
            .filter { it.packageName.isNotBlank() && it.packageName != packageName && it.packageName !in allowedSet }
            .sortedBy { it.label.lowercase() }

        activeAppsGrid.removeAllViews()
        if (activeApps.isEmpty()) {
            activeAppsGrid.addView(emptyText("Belum ada aplikasi tambahan. Adena POS tetap tampil sebagai ikon utama."))
        } else {
            activeApps.forEach { app ->
                activeAppsGrid.addView(appTile(app, "Keluarkan") { confirmRemove(app) })
            }
        }

        availableAppsGrid.removeAllViews()
        if (availableApps.isEmpty()) {
            availableAppsGrid.addView(emptyText("Tidak ada aplikasi lain yang bisa ditambahkan."))
        } else {
            availableApps.forEach { app ->
                availableAppsGrid.addView(appTile(app, "Tambah") { confirmAdd(app) })
            }
        }
    }

    private fun confirmAdd(app: AppChoice) {
        if (app.packageName.isBlank()) return
        if (app.packageName == packageName) {
            Toast.makeText(this, "Adena POS sudah tersedia sebagai ikon utama.", Toast.LENGTH_LONG).show()
            return
        }
        AlertDialog.Builder(this)
            .setTitle("Tambah aplikasi")
            .setMessage("Tambahkan ${app.label} ke launcher Adena?")
            .setPositiveButton("Tambah") { dialog, _ ->
                prefs.addAllowedPackage(app.packageName)
                kioskManager.applyLockTaskAllowlist(prefs.getAllowedPackages())
                refreshAppGrids()
                Toast.makeText(this, "${app.label} ditambahkan ke launcher.", Toast.LENGTH_SHORT).show()
                dialog.dismiss()
            }
            .setNegativeButton("Batal", null)
            .show()
    }

    private fun confirmRemove(app: AppChoice) {
        AlertDialog.Builder(this)
            .setTitle("Keluarkan aplikasi")
            .setMessage("Keluarkan ${app.label} dari launcher Adena?\n\nAplikasi tidak akan di-uninstall dari tablet.")
            .setPositiveButton("Keluarkan") { dialog, _ ->
                prefs.removeAllowedPackage(app.packageName)
                kioskManager.applyLockTaskAllowlist(prefs.getAllowedPackages())
                refreshAppGrids()
                Toast.makeText(this, "${app.label} dikeluarkan dari launcher.", Toast.LENGTH_SHORT).show()
                dialog.dismiss()
            }
            .setNegativeButton("Batal", null)
            .show()
    }

    private fun showChangePinDialog() {
        val wrapper = LinearLayout(this).apply {
            orientation = LinearLayout.VERTICAL
            setPadding(dp(8), 0, dp(8), 0)
        }
        val oldPin = pinField("PIN lama")
        val newPin = pinField("PIN baru minimal 4 digit")
        val confirmPin = pinField("Konfirmasi PIN baru")
        wrapper.addView(oldPin)
        wrapper.addView(newPin)
        wrapper.addView(confirmPin)

        AlertDialog.Builder(this)
            .setTitle("Ganti PIN Admin")
            .setView(wrapper)
            .setPositiveButton("Simpan") { dialog, _ ->
                val oldValue = oldPin.text?.toString().orEmpty()
                val newValue = newPin.text?.toString().orEmpty()
                val confirmValue = confirmPin.text?.toString().orEmpty()
                when {
                    !prefs.verifyPin(oldValue) -> Toast.makeText(this, "PIN lama salah.", Toast.LENGTH_LONG).show()
                    newValue.length < 4 -> Toast.makeText(this, "PIN baru minimal 4 digit.", Toast.LENGTH_LONG).show()
                    newValue != confirmValue -> Toast.makeText(this, "Konfirmasi PIN baru tidak sama.", Toast.LENGTH_LONG).show()
                    else -> {
                        prefs.changePin(newValue)
                        Toast.makeText(this, "PIN admin diganti.", Toast.LENGTH_LONG).show()
                    }
                }
                dialog.dismiss()
            }
            .setNegativeButton("Batal", null)
            .show()
    }


    private fun openOriginalLauncherForAdmin() {
        prefs.setAdminUnlockedFor(10)
        kioskManager.stopKiosk(this)
        runCatching { kioskManager.clearHomeLauncherAsDeviceOwner() }

        val opened = kioskManager.openPreferredOriginalLauncher(this)
        if (opened) {
            Toast.makeText(this, "Kiosk dibuka sementara. Launcher Infinix/Android dibuka.", Toast.LENGTH_LONG).show()
            moveTaskToBack(true)
        } else {
            Toast.makeText(this, "Launcher asli tidak ditemukan. Membuka Default Home Settings.", Toast.LENGTH_LONG).show()
            kioskManager.openDefaultHomeSettings(this)
        }
    }

    private fun confirmResetSetup() {
        AlertDialog.Builder(this)
            .setTitle("Reset setup launcher")
            .setMessage("Setup Adena Launcher akan dihapus. Setelah itu aplikasi meminta setup awal lagi.")
            .setPositiveButton("Reset") { dialog, _ ->
                prefs.resetSetup()
                Toast.makeText(this, "Setup direset.", Toast.LENGTH_LONG).show()
                startActivity(Intent(this, KioskSetupActivity::class.java).addFlags(Intent.FLAG_ACTIVITY_CLEAR_TOP))
                finish()
                dialog.dismiss()
            }
            .setNegativeButton("Batal", null)
            .show()
    }

    private fun loadLaunchableApps(): List<AppChoice> {
        val apps = linkedMapOf<String, AppChoice>()
        val launcherIntent = Intent(Intent.ACTION_MAIN, null).addCategory(Intent.CATEGORY_LAUNCHER)

        packageManager.queryIntentActivities(launcherIntent, 0).forEach { info ->
            val pkg = info.activityInfo?.packageName ?: return@forEach
            if (pkg.isBlank() || pkg == packageName) return@forEach
            val label = info.loadLabel(packageManager)?.toString().orEmpty().ifBlank { pkg }
            val icon = runCatching { info.loadIcon(packageManager) }.getOrNull()
            apps[pkg] = AppChoice(label, pkg, icon)
        }

        // Fallback untuk launcher activity yang kadang tidak muncul di query awal pada beberapa ROM Android.
        runCatching { packageManager.getInstalledApplications(0) }
            .getOrDefault(emptyList())
            .forEach { appInfo ->
                val pkg = appInfo.packageName ?: return@forEach
                if (pkg.isBlank() || pkg == packageName || apps.containsKey(pkg)) return@forEach
                val launchIntent = packageManager.getLaunchIntentForPackage(pkg) ?: return@forEach
                if (launchIntent.component == null && launchIntent.`package`.isNullOrBlank()) return@forEach
                val label = packageManager.getApplicationLabel(appInfo)?.toString().orEmpty().ifBlank { pkg }
                val icon = runCatching { packageManager.getApplicationIcon(appInfo) }.getOrNull()
                apps[pkg] = AppChoice(label, pkg, icon)
            }

        return apps.values.sortedBy { it.label.lowercase() }
    }

    private fun getAppLabel(packageName: String): String {
        return runCatching {
            val info = packageManager.getApplicationInfo(packageName, 0)
            packageManager.getApplicationLabel(info).toString()
        }.getOrDefault(packageName)
    }

    private fun getAppIcon(packageName: String): Drawable? {
        return runCatching {
            val info = packageManager.getApplicationInfo(packageName, 0)
            packageManager.getApplicationIcon(info)
        }.getOrNull()
    }

    private fun appGrid(): GridLayout = GridLayout(this).apply {
        columnCount = calculateGridColumnCount()
        alignmentMode = GridLayout.ALIGN_BOUNDS
        useDefaultMargins = false
        layoutParams = LinearLayout.LayoutParams(ViewGroup.LayoutParams.MATCH_PARENT, ViewGroup.LayoutParams.WRAP_CONTENT)
    }

    private fun appTile(app: AppChoice, actionLabel: String, action: () -> Unit): LinearLayout {
        return LinearLayout(this).apply {
            orientation = LinearLayout.VERTICAL
            gravity = Gravity.CENTER
            isClickable = true
            isFocusable = true
            setPadding(dp(8), dp(10), dp(8), dp(10))
            background = selectableItemBackground()
            setOnClickListener { action() }

            val tileWidth = calculateTileWidth()
            layoutParams = GridLayout.LayoutParams().apply {
                width = tileWidth
                height = ViewGroup.LayoutParams.WRAP_CONTENT
                setMargins(dp(4), dp(4), dp(4), dp(10))
            }

            addView(ImageView(this@AdenaLauncherSettingsActivity).apply {
                setImageDrawable(app.icon ?: defaultAppIcon())
                scaleType = ImageView.ScaleType.FIT_CENTER
                layoutParams = LinearLayout.LayoutParams(dp(58), dp(58)).apply {
                    gravity = Gravity.CENTER_HORIZONTAL
                }
            })
            addView(TextView(this@AdenaLauncherSettingsActivity).apply {
                text = app.label
                textSize = 13f
                gravity = Gravity.CENTER
                maxLines = 2
                setPadding(0, dp(6), 0, 0)
                layoutParams = LinearLayout.LayoutParams(ViewGroup.LayoutParams.MATCH_PARENT, ViewGroup.LayoutParams.WRAP_CONTENT)
            })
            addView(TextView(this@AdenaLauncherSettingsActivity).apply {
                text = actionLabel
                textSize = 11f
                gravity = Gravity.CENTER
                alpha = 0.72f
                setPadding(0, dp(3), 0, 0)
                layoutParams = LinearLayout.LayoutParams(ViewGroup.LayoutParams.MATCH_PARENT, ViewGroup.LayoutParams.WRAP_CONTENT)
            })
        }
    }

    private fun emptyText(message: String): TextView = TextView(this).apply {
        text = message
        textSize = 14f
        setPadding(0, dp(8), 0, dp(12))
        layoutParams = GridLayout.LayoutParams().apply {
            width = ViewGroup.LayoutParams.MATCH_PARENT
            height = ViewGroup.LayoutParams.WRAP_CONTENT
        }
    }

    private fun selectableItemBackground(): Drawable? {
        val attrs = intArrayOf(android.R.attr.selectableItemBackgroundBorderless)
        val typedArray = obtainStyledAttributes(attrs)
        val drawable = typedArray.getDrawable(0)
        typedArray.recycle()
        return drawable
    }

    private fun defaultAppIcon(): Drawable? = runCatching {
        packageManager.defaultActivityIcon
    }.getOrNull()

    private fun calculateGridColumnCount(): Int {
        val screenWidthDp = resources.configuration.screenWidthDp
        return when {
            screenWidthDp >= 900 -> 6
            screenWidthDp >= 700 -> 5
            screenWidthDp >= 520 -> 4
            else -> 3
        }
    }

    private fun calculateTileWidth(): Int {
        val columns = calculateGridColumnCount().coerceAtLeast(1)
        val availableWidth = resources.displayMetrics.widthPixels - dp(48)
        return (availableWidth / columns).coerceAtLeast(dp(92))
    }

    private fun pinField(hintText: String): EditText = EditText(this).apply {
        hint = hintText
        inputType = InputType.TYPE_CLASS_NUMBER or InputType.TYPE_NUMBER_VARIATION_PASSWORD
        gravity = Gravity.START
    }

    private fun sectionTitle(textValue: String): TextView = TextView(this).apply {
        text = textValue
        textSize = 17f
        setTypeface(typeface, Typeface.BOLD)
        setPadding(0, dp(14), 0, dp(6))
    }

    private fun bigButton(textValue: String, action: () -> Unit): Button = Button(this).apply {
        text = textValue
        textSize = 16f
        setAllCaps(false)
        minHeight = dp(56)
        layoutParams = LinearLayout.LayoutParams(ViewGroup.LayoutParams.MATCH_PARENT, ViewGroup.LayoutParams.WRAP_CONTENT).apply {
            setMargins(0, dp(5), 0, dp(5))
        }
        setOnClickListener { action() }
    }

    private fun dp(value: Int): Int = (value * resources.displayMetrics.density).toInt()

    companion object {
        private const val REQUEST_PICK_BACKGROUND = 9126
    }

    data class AppChoice(val label: String, val packageName: String, val icon: Drawable? = null)
}
