package id.my.hopenoodles.hopepos.ui

import android.Manifest
import android.bluetooth.BluetoothDevice
import android.content.pm.PackageManager
import android.os.Build
import android.os.Bundle
import android.util.Log
import android.widget.ArrayAdapter
import android.widget.Toast
import androidx.activity.result.contract.ActivityResultContracts
import androidx.appcompat.app.AppCompatActivity
import androidx.core.content.ContextCompat
import androidx.lifecycle.lifecycleScope
import id.my.hopenoodles.hopepos.bluetooth.BluetoothPrinterManager
import id.my.hopenoodles.hopepos.bluetooth.EscPosFormatter
import id.my.hopenoodles.hopepos.data.PrinterPrefs
import id.my.hopenoodles.hopepos.databinding.ActivityPrinterSettingsBinding
import kotlinx.coroutines.Dispatchers
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

    private val permissionLauncher = registerForActivityResult(
        ActivityResultContracts.RequestMultiplePermissions(),
    ) { result ->
        val denied = result.filterValues { !it }.keys
        if (denied.isNotEmpty()) {
            toast("Izin Bluetooth ditolak. Pilih izin agar printer bisa dipakai.")
        }
        refreshDevices()
    }

    override fun onCreate(savedInstanceState: Bundle?) {
        super.onCreate(savedInstanceState)
        binding = ActivityPrinterSettingsBinding.inflate(layoutInflater)
        setContentView(binding.root)

        printerManager = BluetoothPrinterManager(this)
        printerPrefs = PrinterPrefs(this)

        ensureBluetoothPermissions()
        refreshDevices()
        renderCurrentPrinter()

        binding.listPaired.setOnItemClickListener { _, _, position, _ ->
            val selected = pairedDevices.getOrNull(position) ?: return@setOnItemClickListener
            printerPrefs.setPrinterMac(selected.address)
            renderCurrentPrinter()
            toast("Printer disimpan: ${selected.name ?: selected.address}")
        }

        binding.btnReconnect.setOnClickListener {
            val mac = printerPrefs.getPrinterMac()
            if (mac.isNullOrBlank()) {
                toast("Pilih printer dulu")
                return@setOnClickListener
            }
            if (!printerManager.hasRequiredBluetoothPermissionsForConnect()) {
                ensureBluetoothPermissions()
                toast("Izin Bluetooth belum lengkap untuk reconnect printer")
                return@setOnClickListener
            }
            if (!printerManager.isBluetoothEnabled()) {
                toast("Bluetooth masih mati")
                return@setOnClickListener
            }
            lifecycleScope.launch {
                val result = withContext(Dispatchers.IO) { printerManager.reconnect(mac) }
                if (result.isSuccess) toast("Reconnect berhasil")
                else {
                    Log.e(TAG, "Reconnect gagal", result.exceptionOrNull())
                    toast("Reconnect gagal: ${result.exceptionOrNull()?.message}")
                }
            }
        }

        binding.btnTestPrint.setOnClickListener {
            val mac = printerPrefs.getPrinterMac()
            if (mac.isNullOrBlank()) {
                toast("Pilih printer dulu")
                return@setOnClickListener
            }
            if (!printerManager.hasRequiredBluetoothPermissionsForConnect()) {
                ensureBluetoothPermissions()
                toast("Izin Bluetooth belum lengkap untuk test print")
                return@setOnClickListener
            }
            if (!printerManager.isBluetoothEnabled()) {
                toast("Bluetooth masih mati")
                return@setOnClickListener
            }

            lifecycleScope.launch {
                val dateText = SimpleDateFormat("dd/MM/yyyy HH:mm:ss", Locale.getDefault()).format(Date())
                val result = withContext(Dispatchers.IO) {
                    runCatching {
                        val bytes = EscPosFormatter.formatTestPrint(dateText)
                        printerManager.print(mac, bytes)
                    }
                }
                if (result.isSuccess) toast("Test print berhasil")
                else {
                    Log.e(TAG, "Test print gagal", result.exceptionOrNull())
                    toast("Test print gagal: ${result.exceptionOrNull()?.message}")
                }
            }
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
            "Izin Bluetooth: belum lengkap (butuh SCAN + CONNECT)"
        }
        binding.tvBluetoothStatus.text = if (printerManager.isBluetoothEnabled()) {
            "Bluetooth: aktif"
        } else {
            "Bluetooth: mati"
        }

        if (!hasPermission) {
            binding.tvPairedCount.text = "Paired devices: 0"
            binding.tvEmptyState.text = "Izin Bluetooth belum lengkap. Berikan BLUETOOTH_SCAN dan BLUETOOTH_CONNECT."
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
            getString(id.my.hopenoodles.hopepos.R.string.printer_status_not_selected)
        } else {
            "Printer aktif: $label"
        }
    }

    private fun ensureBluetoothPermissions() {
        if (Build.VERSION.SDK_INT < Build.VERSION_CODES.S) return
        val missing = listOf(
            Manifest.permission.BLUETOOTH_SCAN,
            Manifest.permission.BLUETOOTH_CONNECT,
        ).filter {
            ContextCompat.checkSelfPermission(this, it) != PackageManager.PERMISSION_GRANTED
        }
        if (missing.isNotEmpty()) permissionLauncher.launch(missing.toTypedArray())
    }

    private fun toast(message: String) {
        Toast.makeText(this, message, Toast.LENGTH_LONG).show()
    }

    companion object {
        private const val TAG = "PrinterSettingsAct"
    }
}
