package id.co.adena.pos.ui

import android.Manifest
import android.bluetooth.BluetoothDevice
import android.content.pm.PackageManager
import android.content.ClipData
import android.content.ClipboardManager
import android.content.Context
import android.os.Build
import android.os.Bundle
import android.os.SystemClock
import android.util.Log
import android.widget.ArrayAdapter
import android.widget.LinearLayout
import android.widget.ProgressBar
import android.widget.ScrollView
import android.widget.TextView
import android.widget.Toast
import androidx.activity.result.contract.ActivityResultContracts
import androidx.appcompat.app.AlertDialog
import androidx.appcompat.app.AppCompatActivity
import androidx.core.content.ContextCompat
import androidx.lifecycle.lifecycleScope
import id.co.adena.pos.bluetooth.BluetoothPrinterManager
import id.co.adena.pos.data.PrinterPrefs
import id.co.adena.pos.databinding.ActivityPrinterSettingsBinding
import kotlinx.coroutines.Dispatchers
import kotlinx.coroutines.Job
import kotlinx.coroutines.delay
import kotlinx.coroutines.launch
import kotlinx.coroutines.withContext
import java.text.SimpleDateFormat
import java.util.Date
import java.util.Locale

class PrinterSettingsActivity : AppCompatActivity() {
    private lateinit var binding: ActivityPrinterSettingsBinding
    private lateinit var printerManager: BluetoothPrinterManager
    private lateinit var printerPrefs: PrinterPrefs

    private var pairedDevices: List<BluetoothDevice> = emptyList()
    private var pendingPrinterAction: PrinterAction? = null

    private var printerProgressDialog: AlertDialog? = null
    private var printerProgressStatus: TextView? = null
    private var printerProgressDebug: TextView? = null
    private var printerWatchdogJob: Job? = null
    @Volatile private var lastPrinterProgressAt: Long = 0L
    @Volatile private var debugDialogShownForOperation: Boolean = false

    private val connectPermissionLauncher = registerForActivityResult(
        ActivityResultContracts.RequestPermission(),
    ) { granted ->
        if (granted) {
            refreshDevices()
            val action = pendingPrinterAction
            pendingPrinterAction = null
            when (action) {
                PrinterAction.TEST_PRINT -> performTestPrint()
                PrinterAction.RECONNECT -> performReconnect()
                null -> Unit
            }
        } else {
            pendingPrinterAction = null
            toast("Izin Bluetooth diperlukan agar POS dapat terhubung ke printer.")
            refreshDevices()
        }
    }

    override fun onCreate(savedInstanceState: Bundle?) {
        super.onCreate(savedInstanceState)
        binding = ActivityPrinterSettingsBinding.inflate(layoutInflater)
        setContentView(binding.root)

        printerManager = BluetoothPrinterManager(this)
        printerPrefs = PrinterPrefs(this)
        printerManager.setProgressListener { report ->
            runOnUiThread {
                lastPrinterProgressAt = SystemClock.elapsedRealtime()
                val lastLine = report.lineSequence().lastOrNull().orEmpty()
                printerProgressStatus?.text = if (lastLine.isBlank()) "Memproses printer..." else lastLine
                printerProgressDebug?.text = report
            }
        }

        // Jangan meminta permission saat halaman dibuka.
        // Permission CONNECT diminta on-demand ketika user benar-benar memakai printer.
        refreshDevices()
        renderCurrentPrinter()

        binding.listPaired.setOnItemClickListener { _, _, position, _ ->
            val selected = pairedDevices.getOrNull(position) ?: return@setOnItemClickListener
            printerPrefs.setPrinterMac(selected.address)
            renderCurrentPrinter()
            toast("Printer disimpan: ${selected.name ?: selected.address}")
        }

        binding.btnReconnect.setOnClickListener {
            requestConnectPermissionThen(PrinterAction.RECONNECT)
        }

        binding.btnTestPrint.setOnClickListener {
            requestConnectPermissionThen(PrinterAction.TEST_PRINT)
        }
    }

    override fun onResume() {
        super.onResume()
        refreshDevices()
    }

    private fun refreshDevices() {
        val hasPermission = printerManager.hasRequiredBluetoothPermissionsForSettings()
        binding.tvPermissionStatus.text = if (hasPermission) {
            "Izin Bluetooth: granted"
        } else {
            "Izin Bluetooth CONNECT: belum diberikan"
        }
        binding.tvBluetoothStatus.text = if (printerManager.isBluetoothEnabled()) {
            "Bluetooth: aktif"
        } else {
            "Bluetooth: mati"
        }

        if (!hasPermission) {
            binding.tvPairedCount.text = "Paired devices: 0"
            binding.tvEmptyState.text = "Izin Bluetooth CONNECT akan diminta saat Test Print/Reconnect pertama kali."
            pairedDevices = emptyList()
            binding.listPaired.adapter = ArrayAdapter(this, android.R.layout.simple_list_item_1, emptyList<String>())
            renderCurrentPrinter()
            return
        }

        pairedDevices = printerManager.getBondedDevices()
        val labels = pairedDevices.map { "${it.name ?: "Unknown"}\n${it.address}" }
        binding.tvPairedCount.text = "Paired devices: ${pairedDevices.size}"
        binding.tvEmptyState.text = if (pairedDevices.isEmpty()) {
            "Belum ada perangkat Bluetooth yang ter-pair."
        } else {
            "Ketuk perangkat untuk memilih printer aktif."
        }
        binding.listPaired.adapter = ArrayAdapter(this, android.R.layout.simple_list_item_1, labels)
        Log.d(TAG, "refreshDevices paired=${pairedDevices.size}")
        renderCurrentPrinter()
    }

    private fun renderCurrentPrinter() {
        val currentMac = printerPrefs.getPrinterMac()
        val label = pairedDevices.firstOrNull { it.address == currentMac }?.name ?: currentMac
        binding.tvCurrentPrinter.text = if (label.isNullOrBlank()) {
            getString(id.co.adena.pos.R.string.printer_status_not_selected)
        } else {
            "Printer aktif: $label"
        }
    }

    private fun requestConnectPermissionThen(action: PrinterAction) {
        if (Build.VERSION.SDK_INT < Build.VERSION_CODES.S ||
            ContextCompat.checkSelfPermission(this, Manifest.permission.BLUETOOTH_CONNECT) == PackageManager.PERMISSION_GRANTED
        ) {
            when (action) {
                PrinterAction.TEST_PRINT -> performTestPrint()
                PrinterAction.RECONNECT -> performReconnect()
            }
            return
        }

        pendingPrinterAction = action
        connectPermissionLauncher.launch(Manifest.permission.BLUETOOTH_CONNECT)
    }

    private fun performReconnect() {
        val mac = printerPrefs.getPrinterMac()
        if (mac.isNullOrBlank()) {
            toast("Pilih printer dulu")
            return
        }
        if (!printerManager.isBluetoothEnabled()) {
            toast("Bluetooth masih mati")
            return
        }

        showPrinterProgress(
            title = "Menghubungkan Printer",
            action = PrinterAction.RECONNECT,
        )

        lifecycleScope.launch {
            val result = withContext(Dispatchers.IO) { printerManager.reconnect(mac) }
            finishPrinterProgress()
            if (result.isSuccess) {
                toast("Reconnect berhasil")
            } else {
                Log.e(TAG, "Reconnect gagal", result.exceptionOrNull())
                if (!debugDialogShownForOperation) {
                    debugDialogShownForOperation = true
                    showPrinterDebugDialog(
                        title = "Reconnect Printer Gagal",
                        retryAction = PrinterAction.RECONNECT,
                    )
                }
            }
        }
    }

    private fun performTestPrint() {
        val mac = printerPrefs.getPrinterMac()
        if (mac.isNullOrBlank()) {
            toast("Pilih printer dulu")
            return
        }
        if (!printerManager.isBluetoothEnabled()) {
            toast("Bluetooth masih mati")
            return
        }

        showPrinterProgress(
            title = "Test Print Berjalan",
            action = PrinterAction.TEST_PRINT,
        )

        lifecycleScope.launch {
            val dateText = SimpleDateFormat("dd/MM/yyyy HH:mm:ss", Locale.getDefault()).format(Date())
            val result = withContext(Dispatchers.IO) {
                runCatching {
                    val bytes = buildMinimalTestPrint(dateText)
                    printerManager.print(mac, bytes)
                }
            }
            finishPrinterProgress()
            if (result.isSuccess) {
                toast("Test print berhasil")
            } else {
                Log.e(TAG, "Test print gagal", result.exceptionOrNull())
                if (!debugDialogShownForOperation) {
                    debugDialogShownForOperation = true
                    showPrinterDebugDialog(
                        title = "Test Print Gagal",
                        retryAction = PrinterAction.TEST_PRINT,
                    )
                }
            }
        }
    }

    private fun buildMinimalTestPrint(dateText: String): ByteArray {
        // Raw ESC/POS minimal untuk mengisolasi transport Bluetooth (BLE/RFCOMM) dari formatter receipt.
        val reset = byteArrayOf(0x1B, 0x40) // ESC @
        val body = "ADENA PRINTER TEST\n$dateText\n\n\n".toByteArray(Charsets.US_ASCII)
        return reset + body
    }

    private fun showPrinterProgress(title: String, action: PrinterAction) {
        finishPrinterProgress()
        debugDialogShownForOperation = false
        lastPrinterProgressAt = SystemClock.elapsedRealtime()

        val progressBar = ProgressBar(this).apply {
            isIndeterminate = true
        }
        val statusView = TextView(this).apply {
            text = "Memulai proses printer..."
            textSize = 15f
            setPadding(0, 16, 0, 12)
        }
        val debugView = TextView(this).apply {
            text = "Menunggu log printer..."
            setTextIsSelectable(true)
            textSize = 12f
        }
        val content = LinearLayout(this).apply {
            orientation = LinearLayout.VERTICAL
            setPadding(32, 24, 32, 16)
            addView(progressBar)
            addView(statusView)
            addView(
                ScrollView(this@PrinterSettingsActivity).apply {
                    addView(debugView)
                },
                LinearLayout.LayoutParams(
                    LinearLayout.LayoutParams.MATCH_PARENT,
                    520,
                ),
            )
        }

        printerProgressStatus = statusView
        printerProgressDebug = debugView

        val dialog = AlertDialog.Builder(this)
            .setTitle(title)
            .setView(content)
            .setNegativeButton("Batalkan", null)
            .create()

        dialog.setCanceledOnTouchOutside(false)
        dialog.setOnShowListener {
            dialog.getButton(AlertDialog.BUTTON_NEGATIVE).setOnClickListener {
                printerProgressStatus?.text = "Membatalkan proses printer..."
                printerManager.cancelCurrentOperation("Dibatalkan pengguna")
                printerWatchdogJob?.cancel()
                dialog.dismiss()
                printerProgressDialog = null
                if (!debugDialogShownForOperation) {
                    debugDialogShownForOperation = true
                    showPrinterDebugDialog(
                        title = "Proses Printer Dibatalkan",
                        retryAction = action,
                    )
                }
            }
        }
        dialog.show()
        printerProgressDialog = dialog

        printerWatchdogJob = lifecycleScope.launch {
            while (printerProgressDialog?.isShowing == true) {
                delay(WATCHDOG_POLL_MS)
                val idleFor = SystemClock.elapsedRealtime() - lastPrinterProgressAt
                if (idleFor >= PRINTER_STALL_TIMEOUT_MS) {
                    printerProgressStatus?.text =
                        "Tidak ada progres ${PRINTER_STALL_TIMEOUT_MS / 1000} detik. Socket dihentikan untuk mengambil debug..."
                    printerManager.cancelCurrentOperation(
                        "STALL WATCHDOG: tidak ada progres selama ${PRINTER_STALL_TIMEOUT_MS / 1000} detik",
                    )
                    delay(300)
                    printerProgressDialog?.dismiss()
                    printerProgressDialog = null
                    if (!debugDialogShownForOperation) {
                        debugDialogShownForOperation = true
                        showPrinterDebugDialog(
                            title = "Printer Tidak Merespons",
                            retryAction = action,
                        )
                    }
                    break
                }
            }
        }
    }

    private fun finishPrinterProgress() {
        printerWatchdogJob?.cancel()
        printerWatchdogJob = null
        printerProgressDialog?.dismiss()
        printerProgressDialog = null
        printerProgressStatus = null
        printerProgressDebug = null
    }

    private fun showPrinterDebugDialog(title: String, retryAction: PrinterAction) {
        val report = printerManager.getLastDebugReport()
        val debugView = TextView(this).apply {
            text = report
            setTextIsSelectable(true)
            setPadding(32, 24, 32, 24)
            textSize = 13f
        }

        val dialog = AlertDialog.Builder(this)
            .setTitle(title)
            .setMessage("Teks debug di bawah bisa dipilih/copy. Gunakan Salin Debug untuk menyalin seluruh log.")
            .setView(debugView)
            .setPositiveButton("Salin Debug", null)
            .setNeutralButton("Coba Lagi", null)
            .setNegativeButton("Tutup", null)
            .create()

        dialog.setOnShowListener {
            dialog.getButton(AlertDialog.BUTTON_POSITIVE).setOnClickListener {
                copyDebugToClipboard(report)
                toast("Debug printer disalin")
            }
            dialog.getButton(AlertDialog.BUTTON_NEUTRAL).setOnClickListener {
                dialog.dismiss()
                requestConnectPermissionThen(retryAction)
            }
        }
        dialog.show()
    }

    private fun copyDebugToClipboard(report: String) {
        val clipboard = getSystemService(Context.CLIPBOARD_SERVICE) as ClipboardManager
        clipboard.setPrimaryClip(ClipData.newPlainText("Adena POS Printer Debug", report))
    }

    private fun toast(message: String) {
        Toast.makeText(this, message, Toast.LENGTH_LONG).show()
    }

    override fun onDestroy() {
        printerWatchdogJob?.cancel()
        printerManager.setProgressListener(null)
        if (isFinishing) {
            printerManager.cancelCurrentOperation("Printer settings ditutup")
        }
        printerProgressDialog?.dismiss()
        super.onDestroy()
    }

    private enum class PrinterAction { TEST_PRINT, RECONNECT }

    companion object {
        private const val TAG = "PrinterSettingsAct"
        private const val PRINTER_STALL_TIMEOUT_MS = 15_000L
        private const val WATCHDOG_POLL_MS = 1_000L
    }
}
