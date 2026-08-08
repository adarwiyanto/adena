package id.co.adena.pos.bluetooth

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
import java.text.SimpleDateFormat
import java.util.Date
import java.util.Locale

class BluetoothPrinterManager(context: Context) {
    private val appContext = context.applicationContext
    private val bluetoothManager: BluetoothManager? =
        appContext.getSystemService(Context.BLUETOOTH_SERVICE) as? BluetoothManager
    private val adapter: BluetoothAdapter? = bluetoothManager?.adapter
    private var activeSocket: BluetoothSocket? = null
    private val ioMutex = Mutex()
    @Volatile private var lastDebugReport: String = "Belum ada proses printer."
    private val debugLock = Any()
    private var debugLines = mutableListOf<String>()
    private val connectionPrefs = appContext.getSharedPreferences(CONNECTION_PREFS, Context.MODE_PRIVATE)
    @Volatile private var progressListener: ((String) -> Unit)? = null
    @Volatile private var activeAttemptSocket: BluetoothSocket? = null
    @Volatile private var operationCancelled: Boolean = false
    private val bleTransport = BlePrinterTransport(appContext) { message -> debug(message) }

    private sealed class ConnectionMethod {
        data class UuidMethod(val uuid: UUID, val insecure: Boolean) : ConnectionMethod()
        data class ChannelMethod(val channel: Int) : ConnectionMethod()
    }

    fun getLastDebugReport(): String = lastDebugReport

    fun setProgressListener(listener: ((String) -> Unit)?) {
        progressListener = listener
    }

    fun cancelCurrentOperation(reason: String = "Dibatalkan") {
        operationCancelled = true
        debug("CANCEL CURRENT OPERATION: $reason")
        runCatching { activeAttemptSocket?.close() }
            .onSuccess { debug("Active connecting socket closed by cancel/watchdog") }
            .onFailure { debugError("Close active connecting socket", it) }
        activeAttemptSocket = null
        bleTransport.cancel(reason)
        runCatching { activeSocket?.close() }
        activeSocket = null
    }

    private fun startDebug(operation: String, mac: String) {
        operationCancelled = false
        synchronized(debugLock) {
            debugLines = mutableListOf()
            debugLines += "ADENA POS - PRINTER DEBUG"
            debugLines += "Time: ${SimpleDateFormat("yyyy-MM-dd HH:mm:ss", Locale.getDefault()).format(Date())}"
            debugLines += "Operation: $operation"
            debugLines += "Android: ${Build.VERSION.RELEASE} / API ${Build.VERSION.SDK_INT}"
            debugLines += "MAC: $mac"
            debugLines += "BLUETOOTH_CONNECT: ${if (hasConnectPermission()) "GRANTED" else "DENIED"}"
            debugLines += "BLUETOOTH_SCAN: ${if (hasScanPermission()) "GRANTED" else "DENIED"}"
            debugLines += "Bluetooth enabled: ${isBluetoothEnabled()}"
            publishDebugLocked()
        }
        progressListener?.invoke(lastDebugReport)
    }

    private fun debug(message: String) {
        val report: String
        synchronized(debugLock) {
            debugLines += message
            publishDebugLocked()
            report = lastDebugReport
        }
        progressListener?.invoke(report)
        Log.d(TAG, message)
    }

    private fun debugError(stage: String, t: Throwable) {
        val root = generateSequence(t) { it.cause }.lastOrNull() ?: t
        debug("$stage: FAILED | ${t.javaClass.name}: ${t.message ?: "(no message)"}")
        if (root !== t) {
            debug("$stage root cause: ${root.javaClass.name}: ${root.message ?: "(no message)"}")
        }
    }

    private fun publishDebugLocked() {
        lastDebugReport = debugLines.joinToString("\n")
    }

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
        // Connecting to an already paired/known MAC requires CONNECT; SCAN is only for discovery.
        return hasConnectPermission()
    }

    fun hasRequiredBluetoothPermissionsForConnect(): Boolean {
        if (Build.VERSION.SDK_INT < Build.VERSION_CODES.S) return true
        // Connecting to an already paired/known MAC requires CONNECT; SCAN is only for discovery.
        return hasConnectPermission()
    }

    fun getMissingBluetoothPermissionErrorForConnect(): Pair<String, String>? {
        if (Build.VERSION.SDK_INT < Build.VERSION_CODES.S) return null
        if (!hasConnectPermission()) {
            return "MISSING_CONNECT_PERMISSION" to "Izin BLUETOOTH_CONNECT belum diberikan"
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
        startDebug("RECONNECT", mac)
        runCatching {
            debug("Disconnect existing socket")
            disconnect()
            connect(mac)
            debug("FINAL RESULT: RECONNECT_SUCCESS")
        }.onFailure {
            debugError("RECONNECT", it)
            debug("FINAL RESULT: RECONNECT_FAILED")
        }
    }

    suspend fun print(mac: String, bytes: ByteArray) = withContext(Dispatchers.IO) {
        ioMutex.withLock {
            startDebug("PRINT", mac)
            debug("Payload bytes: ${bytes.size}")
            if (bytes.isEmpty()) {
                throw PrinterException("INVALID_PAYLOAD", "Data print kosong")
            }

            // MP-58C/RPP02N units may expose a BLE/GATT print service even when
            // their Bluetooth device name resembles a classic SPP printer.
            // Prefer BLE when the known service is advertised; fall back to RFCOMM.
            var lastError: Throwable? = null
            val deviceForBle = runCatching { adapter?.getRemoteDevice(mac) }.getOrNull()
            if (deviceForBle != null && shouldPreferBle(deviceForBle)) {
                debug("TRANSPORT: BLE/GATT preferred for this printer")
                try {
                    bleTransport.print(deviceForBle, bytes)
                    debug("FINAL RESULT: PRINT_SUCCESS via BLE_GATT")
                    return@withLock
                } catch (t: Throwable) {
                    lastError = t
                    debugError("BLE_GATT print", t)
                    debug("TRANSPORT: BLE/GATT failed; falling back to RFCOMM/SPP")
                    bleTransport.cancel("BLE fallback to RFCOMM")
                }
            } else {
                debug("TRANSPORT: BLE/GATT not advertised; using RFCOMM/SPP")
            }

            repeat(2) { attempt ->
                try {
                    val socket = if (attempt == 0) ensureConnected(mac) else {
                        disconnect()
                        connect(mac)
                        activeSocket ?: throw PrinterException("CONNECT_FAILED", "Socket printer tidak tersedia")
                    }
                    debug("WRITE attempt ${attempt + 1}: socket connected=${socket.isConnected}")
                    val out = socket.outputStream
                    debug("WRITE attempt ${attempt + 1}: outputStream acquired")
                    out.write(bytes)
                    out.flush()
                    debug("WRITE attempt ${attempt + 1}: SUCCESS")
                    debug("FINAL RESULT: PRINT_SUCCESS")
                    return@withLock
                } catch (t: Throwable) {
                    lastError = t
                    debugError("WRITE attempt ${attempt + 1}", t)
                    Log.e(TAG, "Write printer gagal attempt=${attempt + 1}", t)
                    debug("Closing socket after failed write")
                    disconnect()
                    if (t is PrinterException && t.code == "MISSING_CONNECT_PERMISSION") throw t
                }
            }

            debug("FINAL RESULT: PRINT_FAILED")
            throw PrinterException(
                "PRINT_FAILED",
                "Gagal mengirim data ke printer setelah reconnect",
                lastError,
            )
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
        if (!btAdapter.isEnabled) throw PrinterException("BLUETOOTH_OFF", "Bluetooth sedang mati")
        if (operationCancelled) throw PrinterException("OPERATION_CANCELLED", "Operasi printer dibatalkan")

        if (hasScanPermission()) {
            debug("cancelDiscovery: attempting")
            runCatching { btAdapter.cancelDiscovery() }
                .onSuccess { debug("cancelDiscovery: OK") }
                .onFailure {
                    debugError("cancelDiscovery", it)
                    Log.w(TAG, "cancelDiscovery diabaikan", it)
                }
        } else {
            debug("cancelDiscovery: SKIPPED (SCAN permission not granted)")
        }

        debug("Closing previous active socket before connect")
        disconnect()
        val device = runCatching { btAdapter.getRemoteDevice(mac) }.getOrElse {
            throw PrinterException("DEVICE_NOT_FOUND", "Perangkat Bluetooth tidak ditemukan", it)
        }
        debug("Device address: ${device.address}")
        debug("Device name: ${runCatching { device.name }.getOrNull() ?: "Unknown"}")
        debug("Bond state: ${device.bondState}")

        val cachedUuids = runCatching { device.uuids?.map { it.uuid } ?: emptyList() }
            .getOrElse {
                debugError("Device UUID enumeration", it)
                emptyList()
            }
            .distinct()
        if (cachedUuids.isEmpty()) {
            debug("Device UUIDs: NONE/CACHE EMPTY")
        } else {
            debug("Device UUIDs (${cachedUuids.size}):")
            cachedUuids.forEachIndexed { index, uuid -> debug("  UUID ${index + 1}: $uuid") }
        }

        val saved = readSavedConnectionMethod(mac)
        if (saved != null) {
            debug("Saved connection method: ${describeMethod(saved)}")
            val savedResult = tryMethod(device, saved, "SAVED_${describeMethod(saved)}")
            if (savedResult != null) {
                activeSocket = savedResult
                debug("Saved connection method: CONNECTED")
                return
            }
            debug("Saved connection method failed; clearing saved method")
            clearSavedConnectionMethod(mac)
        } else {
            debug("Saved connection method: NONE")
        }

        val uuidCandidates = buildList {
            cachedUuids.forEach { uuid ->
                add(ConnectionMethod.UuidMethod(uuid, insecure = true))
                add(ConnectionMethod.UuidMethod(uuid, insecure = false))
            }
            if (SPP_UUID !in cachedUuids) {
                add(ConnectionMethod.UuidMethod(SPP_UUID, insecure = true))
                add(ConnectionMethod.UuidMethod(SPP_UUID, insecure = false))
            }
        }

        var lastError: Throwable? = null
        for (method in uuidCandidates) {
            if (operationCancelled) throw PrinterException("OPERATION_CANCELLED", "Operasi printer dibatalkan")
            val label = describeMethod(method)
            val result = tryMethod(device, method, label)
            if (result != null) {
                activeSocket = result
                saveConnectionMethod(mac, method)
                debug("Working connection method saved: $label")
                return
            }
            lastError = lastConnectError
        }

        debug("UUID/SPP methods exhausted; probing RFCOMM channels $MIN_RFCOMM_CHANNEL..$MAX_RFCOMM_CHANNEL")
        for (channel in MIN_RFCOMM_CHANNEL..MAX_RFCOMM_CHANNEL) {
            if (operationCancelled) throw PrinterException("OPERATION_CANCELLED", "Operasi printer dibatalkan")
            val method = ConnectionMethod.ChannelMethod(channel)
            val label = describeMethod(method)
            val result = tryMethod(device, method, label)
            if (result != null) {
                activeSocket = result
                saveConnectionMethod(mac, method)
                debug("Working connection method saved: $label")
                return
            }
            lastError = lastConnectError
        }

        debug("CONNECT: all UUID/SPP/channel methods failed")
        debug("DIAGNOSIS: device is bonded but no RFCOMM transport accepted connection. Check whether printer is connected to another host; if needed unpair/re-pair.")
        throw PrinterException("CONNECT_FAILED", "Gagal konek ke printer melalui UUID/SPP/RFCOMM channel", lastError)
    }

    @Volatile
    private var lastConnectError: Throwable? = null

    @SuppressLint("MissingPermission")
    private fun tryMethod(
        device: BluetoothDevice,
        method: ConnectionMethod,
        label: String,
    ): BluetoothSocket? {
        if (operationCancelled) {
            throw PrinterException("OPERATION_CANCELLED", "Operasi printer dibatalkan")
        }

        debug("$label: creating socket")
        val socket = runCatching { createSocket(device, method) }.getOrElse {
            lastConnectError = it
            debugError("$label createSocket", it)
            return null
        }

        activeAttemptSocket = socket
        debug("$label: socket created; connect() starting")
        return try {
            socket.connect()
            activeAttemptSocket = null
            if (operationCancelled) {
                runCatching { socket.close() }
                throw PrinterException("OPERATION_CANCELLED", "Operasi printer dibatalkan")
            }
            debug("$label: CONNECTED")
            socket
        } catch (t: Throwable) {
            activeAttemptSocket = null
            lastConnectError = t
            debugError("$label connect", t)
            runCatching { socket.close() }
                .onSuccess { debug("$label: failed socket closed") }
            if (operationCancelled) {
                throw PrinterException("OPERATION_CANCELLED", "Operasi printer dibatalkan", t)
            }
            null
        }
    }

    @SuppressLint("MissingPermission")
    private fun createSocket(device: BluetoothDevice, method: ConnectionMethod): BluetoothSocket {
        return when (method) {
            is ConnectionMethod.UuidMethod -> {
                if (method.insecure) {
                    device.createInsecureRfcommSocketToServiceRecord(method.uuid)
                } else {
                    device.createRfcommSocketToServiceRecord(method.uuid)
                }
            }
            is ConnectionMethod.ChannelMethod -> {
                val reflectionMethod = device.javaClass.getMethod(
                    "createRfcommSocket",
                    Int::class.javaPrimitiveType,
                )
                reflectionMethod.invoke(device, method.channel) as BluetoothSocket
            }
        }
    }

    private fun describeMethod(method: ConnectionMethod): String = when (method) {
        is ConnectionMethod.UuidMethod ->
            "${if (method.insecure) "INSECURE" else "SECURE"}_UUID_${method.uuid}"
        is ConnectionMethod.ChannelMethod -> "RFCOMM_CHANNEL_${method.channel}"
    }

    private fun saveConnectionMethod(mac: String, method: ConnectionMethod) {
        val value = when (method) {
            is ConnectionMethod.UuidMethod ->
                "uuid|${if (method.insecure) 1 else 0}|${method.uuid}"
            is ConnectionMethod.ChannelMethod -> "channel|${method.channel}"
        }
        connectionPrefs.edit().putString(connectionKey(mac), value).apply()
    }

    private fun readSavedConnectionMethod(mac: String): ConnectionMethod? {
        val raw = connectionPrefs.getString(connectionKey(mac), null) ?: return null
        val parts = raw.split('|')
        return runCatching {
            when (parts.firstOrNull()) {
                "uuid" -> {
                    if (parts.size != 3) return@runCatching null
                    ConnectionMethod.UuidMethod(
                        uuid = UUID.fromString(parts[2]),
                        insecure = parts[1] == "1",
                    )
                }
                "channel" -> {
                    if (parts.size != 2) return@runCatching null
                    ConnectionMethod.ChannelMethod(parts[1].toInt())
                }
                else -> null
            }
        }.getOrNull()
    }

    private fun clearSavedConnectionMethod(mac: String) {
        connectionPrefs.edit().remove(connectionKey(mac)).apply()
    }

    private fun connectionKey(mac: String): String = "printer_connection_${mac.uppercase(Locale.US)}"

    @SuppressLint("MissingPermission")
    private fun shouldPreferBle(device: BluetoothDevice): Boolean {
        val uuids = runCatching { device.uuids?.map { it.uuid } ?: emptyList() }.getOrDefault(emptyList())
        val knownBleService = BlePrinterTransport.MP58_SERVICE_UUID in uuids ||
            BlePrinterTransport.SERVICE_18F0_UUID in uuids
        val name = runCatching { device.name.orEmpty() }.getOrDefault("").uppercase(Locale.US)
        val matchingName = name.contains("RPP02") || name.contains("MP-58") || name.contains("MP58")
        debug("BLE capability hint: knownService=$knownBleService matchingName=$matchingName")
        return knownBleService || matchingName
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
        private const val CONNECTION_PREFS = "adena_printer_connections"
        private const val MIN_RFCOMM_CHANNEL = 1
        private const val MAX_RFCOMM_CHANNEL = 10
        val SPP_UUID: UUID = UUID.fromString("00001101-0000-1000-8000-00805F9B34FB")
    }
}
