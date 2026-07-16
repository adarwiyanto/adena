package id.co.adena.pos.kiosk

import android.content.BroadcastReceiver
import android.content.Context
import android.content.Intent
import android.os.Build
import android.util.Log
import id.co.adena.pos.ui.AdenaHomeActivity

class BootReceiver : BroadcastReceiver() {
    override fun onReceive(context: Context, intent: Intent) {
        val action = intent.action ?: return
        if (action !in SUPPORTED_ACTIONS) return

        val prefs = KioskPrefs(context)
        if (!prefs.isSetupComplete() || !prefs.isKioskEnabled() || prefs.isAdminUnlocked()) return

        val launchIntent = Intent(context, AdenaHomeActivity::class.java).apply {
            addFlags(Intent.FLAG_ACTIVITY_NEW_TASK)
            addFlags(Intent.FLAG_ACTIVITY_CLEAR_TOP)
            addFlags(Intent.FLAG_ACTIVITY_SINGLE_TOP)
            putExtra(EXTRA_FROM_BOOT_RECEIVER, true)
        }

        runCatching { context.startActivity(launchIntent) }
            .onFailure { error ->
                Log.e(TAG, "Gagal auto-start Adena dari broadcast $action", error)
            }
    }

    companion object {
        private const val TAG = "AdenaBootReceiver"
        const val EXTRA_FROM_BOOT_RECEIVER = "adena_from_boot_receiver"

        private val SUPPORTED_ACTIONS = setOf(
            Intent.ACTION_BOOT_COMPLETED,
            Intent.ACTION_LOCKED_BOOT_COMPLETED,
        )
    }
}
