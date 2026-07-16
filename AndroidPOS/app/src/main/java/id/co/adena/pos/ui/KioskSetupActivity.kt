package id.co.adena.pos.ui

import android.content.Intent
import android.content.pm.PackageManager
import android.os.Bundle
import android.text.InputType
import android.view.Gravity
import android.view.View
import android.widget.Button
import android.widget.CheckBox
import android.widget.EditText
import android.widget.LinearLayout
import android.widget.RadioButton
import android.widget.RadioGroup
import android.widget.ScrollView
import android.widget.TextView
import android.widget.Toast
import androidx.appcompat.app.AppCompatActivity
import id.co.adena.pos.kiosk.KioskManager
import id.co.adena.pos.kiosk.KioskPrefs

class KioskSetupActivity : AppCompatActivity() {
    private lateinit var prefs: KioskPrefs
    private lateinit var kioskManager: KioskManager
    private lateinit var overlayStatus: TextView
    private lateinit var ownerStatus: TextView
    private lateinit var homeStatus: TextView
    private lateinit var modeGroup: RadioGroup
    private val appCheckBoxes = linkedMapOf<String, CheckBox>()
    private lateinit var pinEdit: EditText
    private lateinit var pinConfirmEdit: EditText
    private lateinit var allowedAppsContainer: LinearLayout

    override fun onCreate(savedInstanceState: Bundle?) {
        super.onCreate(savedInstanceState)
        prefs = KioskPrefs(this)
        kioskManager = KioskManager(this)
        buildUi()
        refreshPermissionStatus()
        updateAllowedAppsVisibility()
    }

    override fun onResume() {
        super.onResume()
        refreshPermissionStatus()
    }

    private fun buildUi() {
        val root = ScrollView(this)
        val content = LinearLayout(this).apply {
            orientation = LinearLayout.VERTICAL
            setPadding(dp(24), dp(24), dp(24), dp(24))
        }
        root.addView(content)

        content.addView(TextView(this).apply {
            text = "Setup Awal Adena Tablet"
            textSize = 24f
            setTypeface(typeface, android.graphics.Typeface.BOLD)
        })
        content.addView(TextView(this).apply {
            text = "Atur mode penggunaan tablet. Pilihan ini tersimpan di dalam aplikasi Adena."
            textSize = 14f
            setPadding(0, dp(8), 0, dp(16))
        })

        overlayStatus = statusTextView()
        ownerStatus = statusTextView()
        homeStatus = statusTextView()
        content.addView(overlayStatus)
        content.addView(ownerStatus)
        content.addView(homeStatus)

        content.addView(Button(this).apply {
            text = "Aktifkan izin tampil di atas aplikasi lain"
            setOnClickListener { kioskManager.openOverlaySettings(this@KioskSetupActivity) }
        })

        content.addView(Button(this).apply {
            text = "Buka Info Aplikasi Adena (izin terbatas Infinix/Android)"
            setOnClickListener { kioskManager.openAppInfoSettings(this@KioskSetupActivity) }
        })

        content.addView(Button(this).apply {
            text = "Aktifkan Auto-start / Startup Manager Infinix"
            setOnClickListener { kioskManager.openAutoStartSettings(this@KioskSetupActivity) }
        })

        content.addView(Button(this).apply {
            text = "Matikan optimasi baterai untuk Adena"
            setOnClickListener { kioskManager.openBatteryOptimizationSettings(this@KioskSetupActivity) }
        })

        content.addView(Button(this).apply {
            text = "Device Admin biasa (opsional, bukan kiosk penuh)"
            setOnClickListener { kioskManager.openDeviceAdminSettings(this@KioskSetupActivity) }
        })

        content.addView(TextView(this).apply {
            text = "Catatan: Adena sekarang dapat dipilih sebagai launcher/Home default. Setelah simpan setup, buka Default Home Settings dan pilih Adena. Auto-start tetap dipertahankan sebagai cadangan. Untuk kiosk penuh yang menahan Home/Recent/Back, factory reset tablet lalu jalankan ADB:\n${kioskManager.getDeviceOwnerCommand()}"
            textSize = 13f
            setPadding(0, dp(8), 0, dp(16))
        })

        content.addView(TextView(this).apply {
            text = "Mode"
            textSize = 16f
            setTypeface(typeface, android.graphics.Typeface.BOLD)
        })

        modeGroup = RadioGroup(this).apply {
            orientation = RadioGroup.VERTICAL
        }
        modeGroup.addView(radio("Normal", MODE_ID_NORMAL))
        modeGroup.addView(radio("Kunci hanya aplikasi Adena", MODE_ID_ADENA_ONLY))
        modeGroup.addView(radio("Launcher Adena + aplikasi tambahan yang diizinkan", MODE_ID_ADENA_PLUS_ALLOWED))
        modeGroup.check(MODE_ID_NORMAL)
        modeGroup.setOnCheckedChangeListener { _, _ -> updateAllowedAppsVisibility() }
        content.addView(modeGroup)

        allowedAppsContainer = LinearLayout(this).apply {
            orientation = LinearLayout.VERTICAL
            setPadding(0, dp(8), 0, dp(8))
        }
        allowedAppsContainer.addView(TextView(this).apply {
            text = "Pilih aplikasi tambahan yang diizinkan. Jumlah aplikasi tidak dibatasi dan dapat diubah kembali dari Pengaturan Launcher Adena."
            setPadding(0, 0, 0, dp(8))
        })
        val choices = loadLaunchableApps()
        if (choices.isEmpty()) {
            allowedAppsContainer.addView(TextView(this).apply {
                text = "Tidak ada aplikasi launcher lain yang ditemukan."
                textSize = 14f
            })
        } else {
            choices.forEach { app ->
                val checkBox = CheckBox(this).apply {
                    text = app.toString()
                    textSize = 15f
                    isChecked = app.packageName in prefs.getAllowedPackages()
                    setPadding(0, dp(4), 0, dp(4))
                }
                appCheckBoxes[app.packageName] = checkBox
                allowedAppsContainer.addView(checkBox)
            }
        }
        content.addView(allowedAppsContainer)

        pinEdit = pinField("PIN keluar kiosk")
        pinConfirmEdit = pinField("Konfirmasi PIN")
        content.addView(pinEdit)
        content.addView(pinConfirmEdit)

        content.addView(Button(this).apply {
            text = "Simpan & Lanjutkan"
            setOnClickListener { saveAndContinue() }
        })

        content.addView(Button(this).apply {
            text = "Reset setup kiosk"
            setOnClickListener {
                prefs.resetSetup()
                Toast.makeText(this@KioskSetupActivity, "Setup kiosk direset.", Toast.LENGTH_LONG).show()
            }
        })

        setContentView(root)
    }

    private fun statusTextView(): TextView = TextView(this).apply {
        textSize = 15f
        setPadding(0, dp(4), 0, dp(4))
    }

    private fun radio(label: String, idValue: Int): RadioButton {
        return RadioButton(this).apply {
            id = idValue
            text = label
            textSize = 15f
        }
    }

    private fun pinField(hintText: String): EditText {
        return EditText(this).apply {
            hint = hintText
            inputType = InputType.TYPE_CLASS_NUMBER or InputType.TYPE_NUMBER_VARIATION_PASSWORD
            gravity = Gravity.START
        }
    }

    private fun refreshPermissionStatus() {
        overlayStatus.text = if (kioskManager.hasOverlayPermission()) {
            "✓ Izin tampil di atas aplikasi lain: aktif"
        } else {
            "✕ Izin tampil di atas aplikasi lain: belum aktif"
        }
        homeStatus.text = if (kioskManager.isAdenaDefaultHome()) { "✓ Launcher/Home default: Adena" } else { "✕ Launcher/Home default: belum Adena" }
        ownerStatus.text = if (kioskManager.isDeviceOwner()) {
            "✓ Device Owner / kiosk penuh: aktif"
        } else {
            "✕ Device Owner / kiosk penuh: belum aktif"
        }
    }

    private fun updateAllowedAppsVisibility() {
        if (!::allowedAppsContainer.isInitialized) return
        allowedAppsContainer.visibility = if (selectedMode() == KioskPrefs.MODE_ADENA_PLUS_ALLOWED) View.VISIBLE else View.GONE
    }

    private fun selectedMode(): String {
        return when (modeGroup.checkedRadioButtonId) {
            MODE_ID_ADENA_ONLY -> KioskPrefs.MODE_ADENA_ONLY
            MODE_ID_ADENA_PLUS_ALLOWED -> KioskPrefs.MODE_ADENA_PLUS_ALLOWED
            else -> KioskPrefs.MODE_NORMAL
        }
    }

    private fun saveAndContinue() {
        val mode = selectedMode()
        val pin = pinEdit.text?.toString().orEmpty()
        val confirm = pinConfirmEdit.text?.toString().orEmpty()

        if (mode != KioskPrefs.MODE_NORMAL) {
            if (!kioskManager.hasOverlayPermission()) {
                Toast.makeText(this, "Aktifkan izin tampil di atas aplikasi lain terlebih dahulu.", Toast.LENGTH_LONG).show()
                return
            }
            if (pin.length < 4) {
                Toast.makeText(this, "PIN minimal 4 digit.", Toast.LENGTH_LONG).show()
                return
            }
            if (pin != confirm) {
                Toast.makeText(this, "Konfirmasi PIN tidak sama.", Toast.LENGTH_LONG).show()
                return
            }
        }

        val allowedPackages = if (mode == KioskPrefs.MODE_ADENA_PLUS_ALLOWED) {
            appCheckBoxes
                .filterValues { it.isChecked }
                .keys
                .filter { it.isNotBlank() && it != packageName }
                .distinct()
        } else {
            emptyList()
        }

        prefs.saveSetup(mode, pin.ifBlank { "0000" }, allowedPackages)
        startActivity(Intent(this, AdenaHomeActivity::class.java).addFlags(Intent.FLAG_ACTIVITY_CLEAR_TOP))
        finish()
    }

    private fun loadLaunchableApps(): List<AppChoice> {
        val launcherIntent = Intent(Intent.ACTION_MAIN, null).addCategory(Intent.CATEGORY_LAUNCHER)
        val apps = packageManager.queryIntentActivities(launcherIntent, PackageManager.MATCH_DEFAULT_ONLY)
            .mapNotNull { info ->
                val pkg = info.activityInfo?.packageName ?: return@mapNotNull null
                if (pkg == packageName) return@mapNotNull null
                val label = info.loadLabel(packageManager)?.toString().orEmpty().ifBlank { pkg }
                AppChoice(label, pkg)
            }
            .distinctBy { it.packageName }
            .sortedBy { it.label.lowercase() }
        return apps
    }

    private fun dp(value: Int): Int = (value * resources.displayMetrics.density).toInt()

    data class AppChoice(val label: String, val packageName: String) {
        override fun toString(): String = if (packageName.isBlank()) label else "$label ($packageName)"
    }

    companion object {
        private const val MODE_ID_NORMAL = 1001
        private const val MODE_ID_ADENA_ONLY = 1002
        private const val MODE_ID_ADENA_PLUS_ALLOWED = 1003
    }
}
