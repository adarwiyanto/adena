package id.co.adena.pos.data.model

import org.json.JSONArray
import org.json.JSONObject

data class ReceiptItem(
    val name: String,
    val qty: Double,
    val price: Long,
    val subtotal: Long,
)

data class ReceiptSummaryLine(
    val label: String,
    val value: String,
    val emphasis: Boolean,
)

data class ReceiptPayload(
    val documentType: String,
    val receiptId: String,
    val tanggalJam: String,
    val cashier: String,
    val storeName: String,
    val storeSubtitle: String,
    val storeAddress: String,
    val storePhone: String,
    val footer: String,
    val logoUrl: String,
    val paymentMethod: String,
    val total: Long,
    val bayar: Long,
    val kembalian: Long,
    val paperWidth: Int,
    val reportTitle: String,
    val periodLabel: String,
    val summaryLines: List<ReceiptSummaryLine>,
    val items: List<ReceiptItem>,
) {
    companion object {
        fun fromJson(raw: String): ReceiptPayload {
            if (raw.isBlank()) throw IllegalArgumentException("Payload kosong")
            val obj = JSONObject(raw)
            val documentType = obj.optString("document_type", "receipt").trim().lowercase()
                .ifBlank { "receipt" }

            val itemsArray = obj.optJSONArray("items") ?: JSONArray()
            val items = mutableListOf<ReceiptItem>()
            for (i in 0 until itemsArray.length()) {
                val child = itemsArray.optJSONObject(i) ?: continue
                items += ReceiptItem(
                    name = child.optString("name", "").trim(),
                    qty = child.optDouble("qty", 0.0),
                    price = child.optLong("price", 0L),
                    subtotal = child.optLong("subtotal", 0L),
                )
            }
            if (documentType == "receipt" && items.isEmpty()) {
                throw IllegalArgumentException("Item receipt kosong")
            }

            val summaryArray = obj.optJSONArray("summary_lines") ?: JSONArray()
            val summaryLines = mutableListOf<ReceiptSummaryLine>()
            for (i in 0 until summaryArray.length()) {
                val child = summaryArray.optJSONObject(i) ?: continue
                val label = child.optString("label", "").trim()
                val value = child.optString("value", "").trim()
                if (label.isBlank() && value.isBlank()) continue
                summaryLines += ReceiptSummaryLine(
                    label = label,
                    value = value,
                    emphasis = child.optBoolean("emphasis", false),
                )
            }

            val receiptId = obj.optString("receipt_id", "").trim()
            if (receiptId.isBlank()) throw IllegalArgumentException("receipt_id wajib diisi")

            return ReceiptPayload(
                documentType = documentType,
                receiptId = receiptId,
                tanggalJam = obj.optString("tanggal_jam", "-").trim(),
                cashier = obj.optString("cashier", "-").trim(),
                storeName = obj.optString("store_name", "Adena POS").trim(),
                storeSubtitle = obj.optString("store_subtitle", "").trim(),
                storeAddress = obj.optString("store_address", "").trim(),
                storePhone = obj.optString("store_phone", "").trim(),
                footer = obj.optString("footer", "").trim(),
                logoUrl = obj.optString("logo_url", "").trim(),
                paymentMethod = obj.optString("payment_method", "").trim(),
                total = obj.optLong("total", 0L),
                bayar = obj.optLong("bayar", 0L),
                kembalian = obj.optLong("kembalian", 0L),
                paperWidth = obj.optInt("paper_width", 58),
                reportTitle = obj.optString("report_title", "LAPORAN PENJUALAN").trim(),
                periodLabel = obj.optString("period_label", "").trim(),
                summaryLines = summaryLines,
                items = items,
            )
        }
    }
}
