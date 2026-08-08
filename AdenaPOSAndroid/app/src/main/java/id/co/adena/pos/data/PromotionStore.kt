package id.co.adena.pos.data

import android.content.Context
import android.net.Uri
import org.json.JSONArray
import java.io.File
import java.util.UUID

class PromotionStore(private val context: Context) {
    data class Item(val id: String, val fileName: String)

    private val prefs = context.getSharedPreferences("adena_promotions", Context.MODE_PRIVATE)
    private val dir = File(context.filesDir, "promotions").apply { mkdirs() }

    fun items(): MutableList<Item> {
        val raw = prefs.getString("items", "[]") ?: "[]"
        return runCatching {
            val a = JSONArray(raw)
            MutableList(a.length()) { i ->
                val o = a.getJSONObject(i)
                Item(o.getString("id"), o.getString("file"))
            }.filter { file(it).exists() }.toMutableList()
        }.getOrDefault(mutableListOf())
    }

    fun file(item: Item): File = File(dir, item.fileName)

    fun add(uri: Uri): Boolean = runCatching {
        val type = context.contentResolver.getType(uri).orEmpty()
        val ext = when {
            type.contains("png") -> ".png"
            type.contains("webp") -> ".webp"
            else -> ".jpg"
        }
        val item = Item(UUID.randomUUID().toString(), "promo_${System.currentTimeMillis()}_${UUID.randomUUID().toString().take(6)}$ext")
        context.contentResolver.openInputStream(uri)?.use { input ->
            file(item).outputStream().use { output -> input.copyTo(output) }
        } ?: error("Gambar tidak dapat dibaca")
        val list = items(); list.add(item); save(list); true
    }.getOrDefault(false)

    fun remove(id: String) {
        val list = items(); val item = list.firstOrNull { it.id == id } ?: return
        runCatching { file(item).delete() }; list.removeAll { it.id == id }; save(list)
    }

    fun move(id: String, delta: Int) {
        val list = items(); val from = list.indexOfFirst { it.id == id }; if (from < 0) return
        val to = (from + delta).coerceIn(0, list.lastIndex); if (from == to) return
        val item = list.removeAt(from); list.add(to, item); save(list)
    }

    private fun save(list: List<Item>) {
        val a = JSONArray(); list.forEach { a.put(org.json.JSONObject().put("id", it.id).put("file", it.fileName)) }
        prefs.edit().putString("items", a.toString()).apply()
    }
}
