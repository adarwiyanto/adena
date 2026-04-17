package id.my.hopenoodles.hopepos.bluetooth

import android.annotation.SuppressLint
import android.bluetooth.BluetoothAdapter
import android.bluetooth.BluetoothDevice
import android.bluetooth.BluetoothManager
import android.bluetooth.BluetoothSocket
import android.content.Context
import android.content.pm.PackageManager
import android.os.Build
import android.util.Log
import androidx.core.content.ContextCompat
import kotlinx.coroutines.Dispatchers
import kotlinx.coroutines.sync.Mutex
import kotlinx.coroutines.sync.withLock
import kotlinx.coroutines.withContext
import java.io.IOException
import java.util.UUID

class BluetoothPrinterManager(context: Context) {
    private val appContext = context.applicationContext
    private val bluetoothManager: BluetoothManager? =
        appContext.getSystemService(Context.BLUETOOTH_SERVICE) as? BluetoothManager
    private val adapter: BluetoothAdapter? = bluetoothManager?.adapter
    private var activeSocket: BluetoothSocket? = null
    private val ioMutex = Mutex()

    fun isBluetoothEnabled(): Boolean = adapter?.isEnabled == true

    fun hasScanPermission(): Boolean {
        if (Build.VERSION.SDK_INT < Build.VERSION_CODES.S) return true
        return ContextCompat.checkSelfPermission(
            appContext,
            android.Manifest.permission.BLUETOOTH_SCAN,
        ) == PackageManager.PERMISSION_GRANTED
    }

    fun hasConnectPermission(): Boolean {
        if (Build.VERSION.SDK_INT < Build.VERSION_CODES.S) return true
        return ContextCompat.checkSelfPermission(
            appContext,
            android.Manifest.permission.BLUETOOTH_CONNECT,
        ) == PackageManager.PERMISSION_GRANTED
    }

    fun hasRequiredBluetoothPermissionsForSettings(): Boolean {
        if (Build.VERSION.SDK_INT < Build.VERSION_CODES.S) return true
        return hasConnectPermission() && hasScanPermission()
    }

    fun hasRequiredBluetoothPermissionsForConnect(): Boolean {
        if (Build.VERSION.SDK_INT < Build.VERSION_CODES.S) return true
        return hasConnectPermission() && hasScanPermission()
    }

    fun getMissingBluetoothPermissionErrorForConnect(): Pair<String, String>? {
        if (Build.VERSION.SDK_INT < Build.VERSION_CODES.S) return null
        if (!hasConnectPermission()) {
            return "MISSING_CONNECT_PERMISSION" to "Izin BLUETOOTH_CONNECT belum diberikan"
        }
        if (!hasScanPermission()) {
            return "MISSING_SCAN_PERMISSION" to "Izin BLUETOOTH_SCAN belum diberikan"
        }
        return null
    }

    @SuppressLint("MissingPermission")
    fun getBondedDevices(): List<BluetoothDevice> {
        if (!hasConnectPermission()) {
            Log.w(TAG, "getBondedDevices ditolak: BLUETOOTH_CONNECT belum granted")
            return emptyList()
        }
        val bonded = adapter?.bondedDevices ?: return emptyList()
        return bonded.sortedBy { it.name ?: it.address }
    }

    suspend fun autoConnect(mac: String): Result<Unit> = withContext(Dispatchers.IO) {
        runCatching { connect(mac) }
    }

    suspend fun reconnect(mac: String): Result<Unit> = withContext(Dispatchers.IO) {
        runCatching {
            disconnect()
            connect(mac)
        }
    }

    suspend fun print(mac: String, bytes: ByteArray) = withContext(Dispatchers.IO) {
        ioMutex.withLock {
            if (bytes.isEmpty()) {
                throw PrinterException("INVALID_PAYLOAD", "Data print kosong")
            }
            val socket = ensureConnected(mac)
            runCatching {
                val out = socket.outputStream
                out.write(bytes)
                out.flush()
            }.onFailure {
                Log.e(TAG, "Write ke printer gagal", it)
                throw PrinterException("PRINT_FAILED", "Gagal mengirim data ke printer", it)
            }
        }
    }

    @SuppressLint("MissingPermission")
    private fun ensureConnected(mac: String): BluetoothSocket {
        if (mac.isBlank()) throw PrinterException("PRINTER_NOT_SELECTED", "MAC printer kosong")
        val current = activeSocket
        if (current != null && current.isConnected) return current
        connect(mac)
        return activeSocket ?: throw PrinterException("CONNECT_FAILED", "Socket printer tidak tersedia")
    }

    @SuppressLint("MissingPermission")
    private fun connect(mac: String) {
        if (mac.isBlank()) throw PrinterException("PRINTER_NOT_SELECTED", "MAC printer belum dipilih")
        val btAdapter = adapter ?: throw PrinterException("BLUETOOTH_UNAVAILABLE", "Perangkat tidak mendukung Bluetooth")
        if (!hasConnectPermission()) {
            throw PrinterException("MISSING_CONNECT_PERMISSION", "Izin BLUETOOTH_CONNECT belum diberikan")
        }
        if (!hasScanPermission()) {
            throw PrinterException("MISSING_SCAN_PERMISSION", "Izin BLUETOOTH_SCAN belum diberikan")
        }
        if (!btAdapter.isEnabled) throw PrinterException("BLUETOOTH_OFF", "Bluetooth sedang mati")

        btAdapter.cancelDiscovery()
        val device = runCatching { btAdapter.getRemoteDevice(mac) }.getOrElse {
            throw PrinterException("DEVICE_NOT_FOUND", "Perangkat Bluetooth tidak ditemukan", it)
        }
        Log.d(TAG, "Mulai koneksi ke printer MAC=${device.address}")

        val attempts = listOf<(BluetoothDevice) -> BluetoothSocket>(
            { it.createRfcommSocketToServiceRecord(SPP_UUID) },
            { it.createInsecureRfcommSocketToServiceRecord(SPP_UUID) },
            { dev ->
                val method = dev.javaClass.getMethod("createRfcommSocket", Int::class.javaPrimitiveType)
                method.invoke(dev, 1) as BluetoothSocket
            },
        )

        var lastError: Throwable? = null
        for ((index, builder) in attempts.withIndex()) {
            val socket = runCatching { builder(device) }.getOrElse {
                lastError = it
                Log.e(TAG, "Gagal membuat socket metode #${index + 1}", it)
                null
            } ?: continue

            runCatching {
                socket.connect()
                activeSocket = socket
                Log.d(TAG, "Koneksi printer sukses via metode #${index + 1}")
                return
            }.onFailure {
                lastError = it
                Log.e(TAG, "Koneksi printer gagal via metode #${index + 1}", it)
                runCatching { socket.close() }
            }
        }

        throw PrinterException("CONNECT_FAILED", "Gagal konek ke printer: ${lastError?.message ?: "unknown"}", lastError)
    }

    fun disconnect() {
        runCatching { activeSocket?.close() }
        activeSocket = null
    }

    class PrinterException(
        val code: String,
        override val message: String,
        cause: Throwable? = null,
    ) : IOException(message, cause)

    companion object {
        private const val TAG = "BluetoothPrinterMgr"
        val SPP_UUID: UUID = UUID.fromString("00001101-0000-1000-8000-00805F9B34FB")
    }
}
