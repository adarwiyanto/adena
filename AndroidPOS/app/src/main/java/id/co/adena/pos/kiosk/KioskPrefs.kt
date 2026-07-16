package id.co.adena.pos.kiosk

import android.content.Context
import java.security.MessageDigest

class KioskPrefs(context: Context) {
    private val prefs = context.getSharedPreferences(PREFS_NAME, Context.MODE_PRIVATE)

    fun isSetupComplete(): Boolean = prefs.getBoolean(KEY_SETUP_COMPLETE, false)

    fun getMode(): String = prefs.getString(KEY_MODE, MODE_NORMAL) ?: MODE_NORMAL

    fun isKioskEnabled(): Boolean = getMode() != MODE_NORMAL

    fun getAllowedPackages(): List<String> {
        val raw = prefs.getString(KEY_ALLOWED_PACKAGES, "").orEmpty()
        return raw.split('|').map { it.trim() }.filter { it.isNotEmpty() }.distinct()
    }

    fun setAllowedPackages(allowedPackages: List<String>) {
        prefs.edit()
            .putString(KEY_ALLOWED_PACKAGES, allowedPackages.map { it.trim() }.filter { it.isNotEmpty() }.distinct().joinToString("|"))
            .apply()
    }

    fun addAllowedPackage(packageName: String) {
        if (packageName.isBlank()) return
        setAllowedPackages(getAllowedPackages() + packageName)
    }

    fun removeAllowedPackage(packageName: String) {
        setAllowedPackages(getAllowedPackages().filterNot { it == packageName })
    }

    fun saveSetup(mode: String, pin: String, allowedPackages: List<String>) {
        prefs.edit()
            .putBoolean(KEY_SETUP_COMPLETE, true)
            .putString(KEY_MODE, mode)
            .putString(KEY_PIN_HASH, hashPin(pin))
            .putString(KEY_ALLOWED_PACKAGES, allowedPackages.map { it.trim() }.filter { it.isNotEmpty() }.distinct().joinToString("|"))
            .remove(KEY_ADMIN_UNLOCK_UNTIL)
            .apply()
    }

    fun changePin(newPin: String) {
        prefs.edit().putString(KEY_PIN_HASH, hashPin(newPin)).apply()
    }

    fun verifyPin(pin: String): Boolean {
        val saved = prefs.getString(KEY_PIN_HASH, null) ?: return false
        return saved == hashPin(pin)
    }

    fun disableKioskKeepSetup() {
        prefs.edit().putString(KEY_MODE, MODE_NORMAL).apply()
    }

    fun setAdminUnlockedFor(minutes: Int) {
        val until = System.currentTimeMillis() + minutes.coerceAtLeast(1) * 60_000L
        prefs.edit().putLong(KEY_ADMIN_UNLOCK_UNTIL, until).apply()
    }

    fun clearAdminUnlock() {
        prefs.edit().remove(KEY_ADMIN_UNLOCK_UNTIL).apply()
    }

    fun isAdminUnlocked(): Boolean {
        return prefs.getLong(KEY_ADMIN_UNLOCK_UNTIL, 0L) > System.currentTimeMillis()
    }

    fun getLauncherBackgroundUri(): String? {
        return prefs.getString(KEY_LAUNCHER_BACKGROUND_URI, null)?.takeIf { it.isNotBlank() }
    }

    fun setLauncherBackgroundUri(uri: String) {
        prefs.edit().putString(KEY_LAUNCHER_BACKGROUND_URI, uri).apply()
    }

    fun clearLauncherBackgroundUri() {
        prefs.edit().remove(KEY_LAUNCHER_BACKGROUND_URI).apply()
    }

    fun resetSetup() {
        prefs.edit().clear().apply()
    }

    private fun hashPin(pin: String): String {
        val bytes = MessageDigest.getInstance("SHA-256").digest(pin.toByteArray(Charsets.UTF_8))
        return bytes.joinToString("") { "%02x".format(it) }
    }

    companion object {
        private const val PREFS_NAME = "adena_kiosk_prefs"
        private const val KEY_SETUP_COMPLETE = "setup_complete"
        private const val KEY_MODE = "mode"
        private const val KEY_PIN_HASH = "pin_hash"
        private const val KEY_ALLOWED_PACKAGES = "allowed_packages"
        private const val KEY_ADMIN_UNLOCK_UNTIL = "admin_unlock_until"
        private const val KEY_LAUNCHER_BACKGROUND_URI = "launcher_background_uri"

        const val MODE_NORMAL = "normal"
        const val MODE_ADENA_ONLY = "adena_only"
        const val MODE_ADENA_PLUS_ALLOWED = "adena_plus_allowed"
    }
}
