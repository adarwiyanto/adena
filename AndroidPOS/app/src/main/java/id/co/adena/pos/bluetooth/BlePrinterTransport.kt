package id.co.adena.pos.bluetooth

import android.annotation.SuppressLint
import android.bluetooth.BluetoothDevice
import android.bluetooth.BluetoothGatt
import android.bluetooth.BluetoothGattCallback
import android.bluetooth.BluetoothGattCharacteristic
import android.bluetooth.BluetoothGattService
import android.bluetooth.BluetoothProfile
import android.content.Context
import android.os.Build
import kotlinx.coroutines.CompletableDeferred
import kotlinx.coroutines.delay
import kotlinx.coroutines.withTimeout
import java.io.IOException
import java.util.UUID
import java.util.concurrent.atomic.AtomicBoolean

/**
 * BLE/GATT transport for MP-58C / RPP02N-class mini thermal printers.
 *
 * Known service used by this printer family:
 *   E7810A71-73AE-499D-8C15-FAA9AEF0C3F2
 * Known writable characteristic:
 *   BEF8D6C9-9C21-4C9E-B632-BD58C1009F9F
 */
class BlePrinterTransport(
    context: Context,
    private val log: (String) -> Unit,
) {
    private val appContext = context.applicationContext

    @Volatile
    private var activeGatt: BluetoothGatt? = null

    @Volatile
    private var cancelled = false

    fun cancel(reason: String = "cancelled") {
        cancelled = true
        log("BLE cancel: $reason")
        runCatching { activeGatt?.disconnect() }
        runCatching { activeGatt?.close() }
        activeGatt = null
    }

    @SuppressLint("MissingPermission")
    suspend fun print(device: BluetoothDevice, bytes: ByteArray) {
        cancelled = false
        val connected = CompletableDeferred<Unit>()
        val servicesDiscovered = CompletableDeferred<Unit>()
        val disconnected = AtomicBoolean(false)

        val callback = object : BluetoothGattCallback() {
            override fun onConnectionStateChange(gatt: BluetoothGatt, status: Int, newState: Int) {
                log("BLE onConnectionStateChange: status=$status state=$newState")
                if (status == BluetoothGatt.GATT_SUCCESS && newState == BluetoothProfile.STATE_CONNECTED) {
                    if (!connected.isCompleted) connected.complete(Unit)
                } else if (newState == BluetoothProfile.STATE_DISCONNECTED) {
                    disconnected.set(true)
                    val error = IOException("BLE disconnected: status=$status")
                    if (!connected.isCompleted) connected.completeExceptionally(error)
                    if (!servicesDiscovered.isCompleted) servicesDiscovered.completeExceptionally(error)
                } else if (status != BluetoothGatt.GATT_SUCCESS) {
                    val error = IOException("BLE connection failed: status=$status state=$newState")
                    if (!connected.isCompleted) connected.completeExceptionally(error)
                }
            }

            override fun onServicesDiscovered(gatt: BluetoothGatt, status: Int) {
                log("BLE onServicesDiscovered: status=$status services=${gatt.services?.size ?: 0}")
                if (status == BluetoothGatt.GATT_SUCCESS) {
                    if (!servicesDiscovered.isCompleted) servicesDiscovered.complete(Unit)
                } else if (!servicesDiscovered.isCompleted) {
                    servicesDiscovered.completeExceptionally(IOException("BLE service discovery failed: status=$status"))
                }
            }
        }

        val gatt = try {
            log("BLE connectGatt: starting")
            if (Build.VERSION.SDK_INT >= Build.VERSION_CODES.M) {
                device.connectGatt(appContext, false, callback, BluetoothDevice.TRANSPORT_LE)
            } else {
                @Suppress("DEPRECATION")
                device.connectGatt(appContext, false, callback)
            }
        } catch (t: Throwable) {
            throw IOException("BLE connectGatt create failed: ${t.message}", t)
        }
        activeGatt = gatt

        try {
            withTimeout(CONNECT_TIMEOUT_MS) { connected.await() }
            if (cancelled) throw IOException("BLE operation cancelled")
            log("BLE connected")

            log("BLE discoverServices: starting")
            if (!gatt.discoverServices()) {
                throw IOException("BLE discoverServices() returned false")
            }
            withTimeout(DISCOVERY_TIMEOUT_MS) { servicesDiscovered.await() }
            if (cancelled) throw IOException("BLE operation cancelled")

            logServices(gatt.services)
            val characteristic = findWritableCharacteristic(gatt.services)
                ?: throw IOException("BLE writable characteristic not found")

            val serviceUuid = characteristic.service?.uuid
            log("BLE selected service: $serviceUuid")
            log("BLE selected characteristic: ${characteristic.uuid}")
            log("BLE characteristic properties: 0x${characteristic.properties.toString(16)}")

            val supportsNoResponse =
                characteristic.properties and BluetoothGattCharacteristic.PROPERTY_WRITE_NO_RESPONSE != 0
            val supportsWrite =
                characteristic.properties and BluetoothGattCharacteristic.PROPERTY_WRITE != 0
            if (!supportsNoResponse && !supportsWrite) {
                throw IOException("BLE characteristic is not writable")
            }

            val writeType = if (supportsNoResponse) {
                BluetoothGattCharacteristic.WRITE_TYPE_NO_RESPONSE
            } else {
                BluetoothGattCharacteristic.WRITE_TYPE_DEFAULT
            }

            log("BLE write mode: ${if (supportsNoResponse) "WRITE_NO_RESPONSE" else "WRITE"}")
            log("BLE payload bytes: ${bytes.size}; chunk=$DEFAULT_CHUNK_SIZE")

            var offset = 0
            var chunkIndex = 0
            while (offset < bytes.size) {
                if (cancelled || disconnected.get()) throw IOException("BLE disconnected/cancelled while writing")
                val end = minOf(offset + DEFAULT_CHUNK_SIZE, bytes.size)
                val chunk = bytes.copyOfRange(offset, end)
                chunkIndex++
                log("BLE write chunk $chunkIndex: offset=$offset size=${chunk.size}")

                val accepted = if (Build.VERSION.SDK_INT >= Build.VERSION_CODES.TIRAMISU) {
                    val result = gatt.writeCharacteristic(characteristic, chunk, writeType)
                    result == android.bluetooth.BluetoothStatusCodes.SUCCESS
                } else {
                    @Suppress("DEPRECATION")
                    run {
                        characteristic.writeType = writeType
                        characteristic.value = chunk
                        gatt.writeCharacteristic(characteristic)
                    }
                }

                if (!accepted) {
                    throw IOException("BLE writeCharacteristic rejected chunk $chunkIndex")
                }

                // These low-cost printer modules are more reliable with paced writes.
                delay(if (supportsNoResponse) CHUNK_DELAY_NO_RESPONSE_MS else CHUNK_DELAY_WITH_RESPONSE_MS)
                offset = end
            }

            log("BLE write complete: chunks=$chunkIndex bytes=${bytes.size}")
            delay(POST_WRITE_DELAY_MS)
        } finally {
            log("BLE closing GATT")
            runCatching { gatt.disconnect() }
            runCatching { gatt.close() }
            if (activeGatt === gatt) activeGatt = null
        }
    }

    private fun findWritableCharacteristic(services: List<BluetoothGattService>): BluetoothGattCharacteristic? {
        // 1) Exact MP-58C/RPP02N-family service + characteristic.
        services.firstOrNull { it.uuid == MP58_SERVICE_UUID }
            ?.getCharacteristic(MP58_WRITE_CHARACTERISTIC_UUID)
            ?.takeIf(::isWritable)
            ?.let { return it }

        // 2) Same known service, any writable characteristic.
        services.firstOrNull { it.uuid == MP58_SERVICE_UUID }
            ?.characteristics
            ?.firstOrNull(::isWritable)
            ?.let { return it }

        // 3) Common 0x18F0 service advertised by this unit.
        services.firstOrNull { it.uuid == SERVICE_18F0_UUID }
            ?.characteristics
            ?.firstOrNull(::isWritable)
            ?.let { return it }

        // 4) Generic fallback: any writable GATT characteristic.
        return services.asSequence()
            .flatMap { it.characteristics.asSequence() }
            .firstOrNull(::isWritable)
    }

    private fun isWritable(characteristic: BluetoothGattCharacteristic): Boolean {
        val p = characteristic.properties
        return p and BluetoothGattCharacteristic.PROPERTY_WRITE != 0 ||
            p and BluetoothGattCharacteristic.PROPERTY_WRITE_NO_RESPONSE != 0
    }

    private fun logServices(services: List<BluetoothGattService>) {
        log("BLE services (${services.size}):")
        services.forEachIndexed { serviceIndex, service ->
            log("  BLE service ${serviceIndex + 1}: ${service.uuid}")
            service.characteristics.forEachIndexed { charIndex, ch ->
                log("    char ${charIndex + 1}: ${ch.uuid} props=0x${ch.properties.toString(16)}")
            }
        }
    }

    companion object {
        val MP58_SERVICE_UUID: UUID = UUID.fromString("E7810A71-73AE-499D-8C15-FAA9AEF0C3F2")
        val MP58_WRITE_CHARACTERISTIC_UUID: UUID = UUID.fromString("BEF8D6C9-9C21-4C9E-B632-BD58C1009F9F")
        val SERVICE_18F0_UUID: UUID = UUID.fromString("000018F0-0000-1000-8000-00805F9B34FB")

        private const val CONNECT_TIMEOUT_MS = 12_000L
        private const val DISCOVERY_TIMEOUT_MS = 8_000L
        private const val DEFAULT_CHUNK_SIZE = 20
        private const val CHUNK_DELAY_NO_RESPONSE_MS = 35L
        private const val CHUNK_DELAY_WITH_RESPONSE_MS = 60L
        private const val POST_WRITE_DELAY_MS = 250L
    }
}
