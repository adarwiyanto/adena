package id.co.adena.pos.kiosk

import android.app.Activity
import android.app.ActivityManager
import android.app.admin.DevicePolicyManager
import android.content.ComponentName
import android.content.Context
import android.content.Intent
import android.content.pm.PackageManager
import android.provider.MediaStore
import android.net.Uri
import android.os.Build
import android.os.PowerManager
import android.os.SystemClock
import android.provider.Settings
import android.view.View
import android.view.WindowInsets
import android.view.WindowInsetsController
import android.view.WindowManager
import android.widget.Toast
import java.util.Locale

class KioskManager(private val context: Context) {
    private val dpm = context.getSystemService(Context.DEVICE_POLICY_SERVICE) as DevicePolicyManager
    private val activityManager = context.getSystemService(Context.ACTIVITY_SERVICE) as ActivityManager
    private val adminComponent = ComponentName(context, AdenaDeviceAdminReceiver::class.java)

    fun isDeviceOwner(): Boolean = dpm.isDeviceOwnerApp(context.packageName)

    fun hasOverlayPermission(): Boolean {
        return if (Build.VERSION.SDK_INT >= Build.VERSION_CODES.M) {
            Settings.canDrawOverlays(context)
        } else {
            true
        }
    }

    fun openOverlaySettings(activity: Activity) {
        val intent = Intent(
            Settings.ACTION_MANAGE_OVERLAY_PERMISSION,
            Uri.parse("package:${context.packageName}"),
        )
        activity.startActivity(intent)
    }

    fun openAppInfoSettings(activity: Activity) {
        val intent = Intent(Settings.ACTION_APPLICATION_DETAILS_SETTINGS).apply {
            data = Uri.parse("package:${context.packageName}")
        }
        activity.startActivity(intent)
    }

    fun openDefaultHomeSettings(activity: Activity) {
        val intent = Intent(Settings.ACTION_HOME_SETTINGS)
        runCatching { activity.startActivity(intent) }
            .recoverCatching { activity.startActivity(Intent(Settings.ACTION_SETTINGS)) }
    }

    fun openBatteryOptimizationSettings(activity: Activity) {
        if (Build.VERSION.SDK_INT >= Build.VERSION_CODES.M) {
            val powerManager = context.getSystemService(Context.POWER_SERVICE) as PowerManager
            if (!powerManager.isIgnoringBatteryOptimizations(context.packageName)) {
                val intent = Intent(Settings.ACTION_REQUEST_IGNORE_BATTERY_OPTIMIZATIONS).apply {
                    data = Uri.parse("package:${context.packageName}")
                }
                runCatching { activity.startActivity(intent) }
                    .recoverCatching { activity.startActivity(Intent(Settings.ACTION_IGNORE_BATTERY_OPTIMIZATION_SETTINGS)) }
                return
            }
        }
        runCatching { activity.startActivity(Intent(Settings.ACTION_IGNORE_BATTERY_OPTIMIZATION_SETTINGS)) }
            .recoverCatching { activity.startActivity(Intent(Settings.ACTION_SETTINGS)) }
    }

    fun openAutoStartSettings(activity: Activity) {
        val candidates = listOf(
            Intent().setClassName("com.transsion.phonemanager", "com.transsion.phonemanager.autostart.AutoStartActivity"),
            Intent().setClassName("com.transsion.phonemanager", "com.transsion.phonemanager.manager.startup.StartupAppListActivity"),
            Intent().setClassName("com.transsion.phonemanager", "com.transsion.phonemanager.activity.StartActivity"),
            Intent().setClassName("com.transsion.phonemanager", "com.transsion.phonemanager.startup.StartupAppListActivity"),
            Intent().setClassName("com.infinix.xhide", "com.infinix.xhide.activity.XHideMainActivity"),
        )

        for (intent in candidates) {
            val canResolve = runCatching {
                intent.resolveActivity(context.packageManager) != null
            }.getOrDefault(false)
            if (!canResolve) continue

            val opened = runCatching {
                activity.startActivity(intent.addFlags(Intent.FLAG_ACTIVITY_NEW_TASK))
            }.isSuccess
            if (opened) return
        }

        Toast.makeText(
            activity,
            "Halaman Auto-start Infinix tidak bisa dibuka otomatis. Silakan aktifkan manual dari Info Aplikasi Adena > Battery/Auto-start.",
            Toast.LENGTH_LONG,
        ).show()
        openAppInfoSettingsSafely(activity)
    }

    private fun openAppInfoSettingsSafely(activity: Activity) {
        val appInfoIntent = Intent(Settings.ACTION_APPLICATION_DETAILS_SETTINGS).apply {
            data = Uri.parse("package:${context.packageName}")
            addFlags(Intent.FLAG_ACTIVITY_NEW_TASK)
        }
        runCatching { activity.startActivity(appInfoIntent) }
            .recoverCatching { activity.startActivity(Intent(Settings.ACTION_SETTINGS).addFlags(Intent.FLAG_ACTIVITY_NEW_TASK)) }
    }

    fun openDeviceAdminSettings(activity: Activity) {
        val intent = Intent(DevicePolicyManager.ACTION_ADD_DEVICE_ADMIN).apply {
            putExtra(DevicePolicyManager.EXTRA_DEVICE_ADMIN, adminComponent)
            putExtra(
                DevicePolicyManager.EXTRA_ADD_EXPLANATION,
                "Aktifkan hanya bila diminta. Untuk kiosk penuh, Adena harus menjadi Device Owner lewat ADB/factory reset.",
            )
        }
        activity.startActivity(intent)
    }

    fun applyHomeLauncherAsDeviceOwner() {
        if (!isDeviceOwner()) return
        val filter = android.content.IntentFilter(Intent.ACTION_MAIN).apply {
            addCategory(Intent.CATEGORY_HOME)
            addCategory(Intent.CATEGORY_DEFAULT)
        }
        dpm.addPersistentPreferredActivity(
            adminComponent,
            filter,
            ComponentName(context.packageName, "${context.packageName}.ui.AdenaHomeActivity"),
        )
    }

    fun clearHomeLauncherAsDeviceOwner() {
        if (!isDeviceOwner()) return
        dpm.clearPackagePersistentPreferredActivities(adminComponent, context.packageName)
    }

    fun isAdenaDefaultHome(): Boolean {
        val intent = Intent(Intent.ACTION_MAIN).addCategory(Intent.CATEGORY_HOME)
        val resolveInfo = context.packageManager.resolveActivity(intent, PackageManager.MATCH_DEFAULT_ONLY)
        return resolveInfo?.activityInfo?.packageName == context.packageName
    }

    fun getAlternativeHomeLaunchers(): List<HomeLauncher> {
        val intent = Intent(Intent.ACTION_MAIN).addCategory(Intent.CATEGORY_HOME)
        return context.packageManager.queryIntentActivities(intent, PackageManager.MATCH_DEFAULT_ONLY)
            .mapNotNull { info ->
                val activityInfo = info.activityInfo ?: return@mapNotNull null
                val pkg = activityInfo.packageName ?: return@mapNotNull null
                if (pkg == context.packageName) return@mapNotNull null
                val name = activityInfo.name ?: return@mapNotNull null
                val label = info.loadLabel(context.packageManager)?.toString().orEmpty().ifBlank { pkg }
                HomeLauncher(label, pkg, name)
            }
            .distinctBy { it.packageName + "/" + it.activityName }
            .sortedBy { it.label.lowercase() }
    }

    fun openHomeLauncher(activity: Activity, launcher: HomeLauncher) {
        val intent = Intent(Intent.ACTION_MAIN).apply {
            addCategory(Intent.CATEGORY_HOME)
            flags = Intent.FLAG_ACTIVITY_NEW_TASK
            component = ComponentName(launcher.packageName, launcher.activityName)
        }
        activity.startActivity(intent)
    }

    fun openPreferredOriginalLauncher(activity: Activity): Boolean {
        val launchers = getAlternativeHomeLaunchers()
        val preferredLauncher = launchers.firstOrNull { launcher ->
            val haystack = "${launcher.label} ${launcher.packageName}".lowercase(Locale.ROOT)
            haystack.contains("infinix") ||
                haystack.contains("xos") ||
                haystack.contains("transsion") ||
                haystack.contains("launcher")
        } ?: launchers.firstOrNull()

        if (preferredLauncher == null) return false
        return runCatching {
            openHomeLauncher(activity, preferredLauncher)
        }.isSuccess
    }

    fun applyLockTaskAllowlist(allowedPackages: List<String>) {
        if (!isDeviceOwner()) return
        val packages = buildLockTaskPackages(allowedPackages)
        val signature = packages.sorted().joinToString("|")
        val now = SystemClock.elapsedRealtime()

        // Android 15/XOS lebih sensitif terhadap pemanggilan DPM berulang saat task sedang transisi.
        // Apply ulang hanya saat daftar berubah atau cache sudah cukup lama.
        if (signature == lastAllowlistSignature && now - lastAllowlistAppliedAt < ALLOWLIST_CACHE_MS) {
            return
        }

        runCatching {
            dpm.setLockTaskPackages(adminComponent, packages.toTypedArray())
            applyLockTaskNavigationFeatures()
        }.onSuccess {
            lastAllowlistSignature = signature
            lastAllowlistAppliedAt = now
        }
    }

    fun applyLockTaskNavigationFeatures() {
        if (!isDeviceOwner()) return
        val features = DevicePolicyManager.LOCK_TASK_FEATURE_HOME or
            DevicePolicyManager.LOCK_TASK_FEATURE_OVERVIEW or
            DevicePolicyManager.LOCK_TASK_FEATURE_NOTIFICATIONS or
            DevicePolicyManager.LOCK_TASK_FEATURE_SYSTEM_INFO
        runCatching { dpm.setLockTaskFeatures(adminComponent, features) }
    }

    private fun getCameraPackagesForQris(): List<String> {
        val cameraIntent = Intent(MediaStore.ACTION_IMAGE_CAPTURE)
        return context.packageManager.queryIntentActivities(cameraIntent, PackageManager.MATCH_DEFAULT_ONLY)
            .mapNotNull { it.activityInfo?.packageName }
            .filter { it.isNotBlank() }
            .distinct()
    }

    fun startKiosk(activity: Activity, allowedPackages: List<String>) {
        applyLockTaskAllowlist(allowedPackages)
        hideSystemUi(activity)

        if (isLockTaskActive()) return

        val now = SystemClock.elapsedRealtime()
        if (now - lastLockTaskStopAt < LOCK_TASK_RESTART_GRACE_MS) return
        if (now - lastLockTaskStartRequestAt < LOCK_TASK_START_DEBOUNCE_MS) return
        lastLockTaskStartRequestAt = now

        // Beri jeda kecil agar SystemUI/Recents Android 15 selesai transisi sebelum lock task aktif lagi.
        activity.window.decorView.postDelayed({
            val delayedNow = SystemClock.elapsedRealtime()
            if (activity.isFinishing) return@postDelayed
            if (Build.VERSION.SDK_INT >= Build.VERSION_CODES.JELLY_BEAN_MR1 && activity.isDestroyed) return@postDelayed
            if (delayedNow - lastLockTaskStopAt < LOCK_TASK_RESTART_GRACE_MS) return@postDelayed
            if (isLockTaskActive()) return@postDelayed
            runCatching { activity.startLockTask() }
        }, LOCK_TASK_START_DELAY_MS)
    }

    fun stopKiosk(activity: Activity) {
        lastLockTaskStopAt = SystemClock.elapsedRealtime()
        runCatching { activity.stopLockTask() }
        showSystemUi(activity)
    }

    fun hideSystemUi(activity: Activity) {
        activity.window.addFlags(WindowManager.LayoutParams.FLAG_KEEP_SCREEN_ON)
        if (Build.VERSION.SDK_INT >= Build.VERSION_CODES.R) {
            activity.window.insetsController?.let { controller ->
                // Navigation bar sengaja tetap ditampilkan supaya tombol Home/Overview/kotak
                // bisa dipakai untuk kembali ke Adena Launcher saat Lock Task aktif.
                controller.hide(WindowInsets.Type.statusBars())
                controller.show(WindowInsets.Type.navigationBars())
                controller.systemBarsBehavior = WindowInsetsController.BEHAVIOR_SHOW_TRANSIENT_BARS_BY_SWIPE
            }
        } else {
            @Suppress("DEPRECATION")
            activity.window.decorView.systemUiVisibility = (
                View.SYSTEM_UI_FLAG_FULLSCREEN
                    or View.SYSTEM_UI_FLAG_LAYOUT_FULLSCREEN
                    or View.SYSTEM_UI_FLAG_LAYOUT_STABLE
                )
        }
    }

    fun showSystemUi(activity: Activity) {
        activity.window.clearFlags(WindowManager.LayoutParams.FLAG_KEEP_SCREEN_ON)
        if (Build.VERSION.SDK_INT >= Build.VERSION_CODES.R) {
            activity.window.insetsController?.show(WindowInsets.Type.statusBars() or WindowInsets.Type.navigationBars())
        } else {
            @Suppress("DEPRECATION")
            activity.window.decorView.systemUiVisibility = View.SYSTEM_UI_FLAG_VISIBLE
        }
    }

    fun getDeviceOwnerCommand(): String {
        return "adb shell dpm set-device-owner ${context.packageName}/.kiosk.AdenaDeviceAdminReceiver"
    }

    data class HomeLauncher(val label: String, val packageName: String, val activityName: String) {
        override fun toString(): String = "$label ($packageName)"
    }

    fun isLockTaskActive(): Boolean {
        return if (Build.VERSION.SDK_INT >= Build.VERSION_CODES.M) {
            activityManager.lockTaskModeState != ActivityManager.LOCK_TASK_MODE_NONE
        } else {
            false
        }
    }

    private fun buildLockTaskPackages(allowedPackages: List<String>): List<String> {
        return (listOf(context.packageName) + allowedPackages + getCameraPackagesForQris())
            .filter { it.isNotBlank() }
            .distinct()
    }

    companion object {
        private const val LOCK_TASK_START_DELAY_MS = 250L
        private const val LOCK_TASK_START_DEBOUNCE_MS = 1500L
        private const val LOCK_TASK_RESTART_GRACE_MS = 1200L
        private const val ALLOWLIST_CACHE_MS = 30000L
        private var lastLockTaskStartRequestAt = 0L
        private var lastLockTaskStopAt = 0L
        private var lastAllowlistSignature: String? = null
        private var lastAllowlistAppliedAt = 0L
    }

}
