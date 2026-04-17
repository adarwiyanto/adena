package id.my.hopenoodles.hopepos.data.model

import org.json.JSONArray
import org.json.JSONObject

data class ReceiptItem(
    val name: String,
    val qty: Double,
    val price: Long,
    val subtotal: Long,
)

data class ReceiptPayload(
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
    val items: List<ReceiptItem>,
) {
    companion object {
        fun fromJson(raw: String): ReceiptPayload {
            if (raw.isBlank()) throw IllegalArgumentException("Payload kosong")
            val obj = JSONObject(raw)

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
            if (items.isEmpty()) throw IllegalArgumentException("Item receipt kosong")

            val receiptId = obj.optString("receipt_id", "").trim()
            if (receiptId.isBlank()) throw IllegalArgumentException("receipt_id wajib diisi")

            return ReceiptPayload(
                receiptId = receiptId,
                tanggalJam = obj.optString("tanggal_jam", "-").trim(),
                cashier = obj.optString("cashier", "-").trim(),
                storeName = obj.optString("store_name", "HOPe POS").trim(),
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
                items = items,
            )
        }
    }
}
