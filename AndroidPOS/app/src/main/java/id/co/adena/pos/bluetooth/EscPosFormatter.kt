package id.co.adena.pos.bluetooth

import android.graphics.Bitmap
import id.co.adena.pos.data.model.ReceiptPayload
import java.io.ByteArrayOutputStream
import java.text.NumberFormat
import java.util.Locale

object EscPosFormatter {
    private const val LINE_58 = 32

    fun formatReceipt(payload: ReceiptPayload, logo: Bitmap?): ByteArray {
        return if (payload.documentType == "sales_report") {
            formatSalesReport(payload, logo)
        } else {
            formatTransactionReceipt(payload, logo)
        }
    }

    private fun formatTransactionReceipt(payload: ReceiptPayload, logo: Bitmap?): ByteArray {
        val out = startDocument(payload, logo)

        out.write(alignLeft())
        out.write(text("ID   : ${payload.receiptId}")); out.write(newLine())
        out.write(text("Tgl  : ${payload.tanggalJam}")); out.write(newLine())
        out.write(text("Kasir: ${payload.cashier}")); out.write(newLine())
        divider(out)

        payload.items.forEach {
            out.write(text(it.name)); out.write(newLine())
            val qty = trimDouble(it.qty)
            val left = "$qty x ${money(it.price)}"
            out.write(text(twoColumn(left, money(it.subtotal))))
            out.write(newLine())
        }

        divider(out)
        out.write(bold(true))
        out.write(text(twoColumn("TOTAL", money(payload.total)))); out.write(newLine())
        out.write(bold(false))
        out.write(text(twoColumn("BAYAR", money(payload.bayar)))); out.write(newLine())
        out.write(text(twoColumn("KEMBALI", money(payload.kembalian)))); out.write(newLine())
        out.write(text("Metode: ${payload.paymentMethod.uppercase(Locale.getDefault())}")); out.write(newLine())
        divider(out)
        writeCenteredLine(out, payload.footer)
        finishDocument(out)
        return out.toByteArray()
    }

    private fun formatSalesReport(payload: ReceiptPayload, logo: Bitmap?): ByteArray {
        val out = startDocument(payload, logo)

        out.write(alignCenter())
        out.write(bold(true))
        out.write(text(payload.reportTitle.ifBlank { "LAPORAN PENJUALAN" }))
        out.write(newLine())
        out.write(bold(false))
        writeCenteredWrapped(out, payload.periodLabel)
        divider(out)

        out.write(alignLeft())
        out.write(text("Dicetak: ${payload.tanggalJam}")); out.write(newLine())
        out.write(text("Oleh   : ${payload.cashier}")); out.write(newLine())
        divider(out)

        payload.summaryLines.forEach { line ->
            if (line.emphasis) {
                divider(out)
                out.write(bold(true))
            }
            out.write(text(twoColumn(line.label, line.value)))
            out.write(newLine())
            if (line.emphasis) {
                out.write(bold(false))
            }
        }
        divider(out)

        out.write(bold(true))
        out.write(text("REKAP PRODUK")); out.write(newLine())
        out.write(bold(false))
        if (payload.items.isEmpty()) {
            out.write(text("Belum ada produk terjual.")); out.write(newLine())
        } else {
            payload.items.forEach { item ->
                writeWrappedLeft(out, item.name)
                val qtyLabel = "${trimDouble(item.qty)} item"
                out.write(text(twoColumn(qtyLabel, money(item.subtotal))))
                out.write(newLine())
            }
        }

        divider(out)
        writeCenteredLine(out, payload.footer)
        finishDocument(out)
        return out.toByteArray()
    }

    private fun startDocument(payload: ReceiptPayload, logo: Bitmap?): ByteArrayOutputStream {
        val out = ByteArrayOutputStream()
        out.write(byteArrayOf(0x1B, 0x40))
        out.write(alignCenter())

        if (logo != null) {
            out.write(bitmapToEscPos(logo))
            out.write(newLine())
        }

        out.write(bold(true))
        out.write(doubleHeight(true))
        out.write(text(payload.storeName.uppercase(Locale.getDefault())))
        out.write(newLine())
        out.write(doubleHeight(false))
        out.write(bold(false))

        writeCenteredWrapped(out, payload.storeSubtitle)
        writeCenteredWrapped(out, payload.storeAddress)
        writeCenteredWrapped(out, payload.storePhone)
        divider(out)
        return out
    }

    private fun finishDocument(out: ByteArrayOutputStream) {
        out.write(newLine())
        out.write(newLine())
        out.write(newLine())
    }

    fun formatTestPrint(nowText: String): ByteArray {
        val out = ByteArrayOutputStream()
        out.write(byteArrayOf(0x1B, 0x40))
        out.write(alignCenter())
        out.write(bold(true))
        out.write(text("Adena POS")); out.write(newLine())
        out.write(bold(false))
        out.write(text("Test printer Panda PRJ-58D")); out.write(newLine())
        out.write(text(nowText)); out.write(newLine())
        out.write(text("Status: BERHASIL")); out.write(newLine())
        out.write(newLine()); out.write(newLine()); out.write(newLine())
        return out.toByteArray()
    }

    private fun writeCenteredLine(out: ByteArrayOutputStream, value: String) {
        if (value.isBlank()) return
        out.write(alignCenter())
        out.write(text(value.take(LINE_58)))
        out.write(newLine())
    }

    private fun writeCenteredWrapped(out: ByteArrayOutputStream, value: String) {
        if (value.isBlank()) return
        wrapText(value).forEach { writeCenteredLine(out, it) }
    }

    private fun writeWrappedLeft(out: ByteArrayOutputStream, value: String) {
        out.write(alignLeft())
        wrapText(value).forEach {
            out.write(text(it))
            out.write(newLine())
        }
    }

    private fun wrapText(value: String): List<String> {
        val normalized = value.trim().replace(Regex("\\s+"), " ")
        if (normalized.isBlank()) return emptyList()
        val lines = mutableListOf<String>()
        var current = ""
        normalized.split(" ").forEach { word ->
            if (word.length > LINE_58) {
                if (current.isNotBlank()) {
                    lines += current
                    current = ""
                }
                word.chunked(LINE_58).forEach { lines += it }
            } else {
                val candidate = if (current.isBlank()) word else "$current $word"
                if (candidate.length <= LINE_58) {
                    current = candidate
                } else {
                    lines += current
                    current = word
                }
            }
        }
        if (current.isNotBlank()) lines += current
        return lines
    }

    private fun divider(out: ByteArrayOutputStream) {
        out.write(alignLeft())
        out.write(text("-".repeat(LINE_58)))
        out.write(newLine())
    }

    private fun twoColumn(left: String, right: String): String {
        val maxLeft = (LINE_58 - right.length - 1).coerceAtLeast(1)
        val safeLeft = if (left.length > maxLeft) left.take(maxLeft) else left
        val spaces = (LINE_58 - safeLeft.length - right.length).coerceAtLeast(1)
        return safeLeft + " ".repeat(spaces) + right
    }

    private fun money(value: Long): String = NumberFormat.getNumberInstance(Locale("in", "ID")).format(value)

    private fun trimDouble(v: Double): String {
        val longV = v.toLong()
        return if (v == longV.toDouble()) longV.toString() else v.toString()
    }

    private fun text(v: String): ByteArray = v.toByteArray(Charsets.UTF_8)
    private fun newLine(): ByteArray = byteArrayOf(0x0A)
    private fun alignLeft(): ByteArray = byteArrayOf(0x1B, 0x61, 0x00)
    private fun alignCenter(): ByteArray = byteArrayOf(0x1B, 0x61, 0x01)
    private fun bold(on: Boolean): ByteArray = byteArrayOf(0x1B, 0x45, if (on) 1 else 0)
    private fun doubleHeight(on: Boolean): ByteArray = byteArrayOf(0x1D, 0x21, if (on) 0x11 else 0x00)

    private fun bitmapToEscPos(source: Bitmap): ByteArray {
        val targetWidth = 384
        val ratio = targetWidth.toFloat() / source.width.toFloat()
        val targetHeight = (source.height * ratio).toInt().coerceAtLeast(1)
        val bmp = Bitmap.createScaledBitmap(source, targetWidth, targetHeight, true)

        val widthBytes = (bmp.width + 7) / 8
        val image = ByteArray(widthBytes * bmp.height)

        for (y in 0 until bmp.height) {
            for (x in 0 until bmp.width) {
                val color = bmp.getPixel(x, y)
                val r = color shr 16 and 0xFF
                val g = color shr 8 and 0xFF
                val b = color and 0xFF
                val luminance = (r + g + b) / 3
                if (luminance < 128) {
                    val index = y * widthBytes + x / 8
                    image[index] = (image[index].toInt() or (0x80 shr (x % 8))).toByte()
                }
            }
        }

        val out = ByteArrayOutputStream()
        out.write(
            byteArrayOf(
                0x1D,
                0x76,
                0x30,
                0x00,
                (widthBytes and 0xFF).toByte(),
                ((widthBytes shr 8) and 0xFF).toByte(),
                (bmp.height and 0xFF).toByte(),
                ((bmp.height shr 8) and 0xFF).toByte(),
            )
        )
        out.write(image)
        out.write(0x0A)
        return out.toByteArray()
    }
}
