package id.co.adena.pos.data

import android.content.ContentValues
import android.content.Context
import android.database.sqlite.SQLiteDatabase
import android.database.sqlite.SQLiteOpenHelper
import org.json.JSONArray
import org.json.JSONObject
import java.util.UUID

/** SQLite is the authoritative runtime store for the native POS. */
class PosLocalStore(context: Context) : SQLiteOpenHelper(context, DB_NAME, null, DB_VERSION) {
    init { setWriteAheadLoggingEnabled(true) }

    data class Product(
        val id: Long,
        val name: String,
        val price: Long,
        val categoryId: Long?,
        val categoryName: String,
        val trackStock: Boolean,
        val currentStock: Double?,
    )

    override fun onConfigure(db: SQLiteDatabase) {
        super.onConfigure(db)
        db.setForeignKeyConstraintsEnabled(true)
    }

    override fun onCreate(db: SQLiteDatabase) {
        db.execSQL("""
            CREATE TABLE IF NOT EXISTS app_state (
                state_key TEXT PRIMARY KEY,
                value_json TEXT NOT NULL,
                updated_at INTEGER NOT NULL
            )
        """.trimIndent())
        db.execSQL("""
            CREATE TABLE IF NOT EXISTS products (
                id INTEGER PRIMARY KEY,
                name TEXT NOT NULL,
                price REAL NOT NULL DEFAULT 0,
                category_id INTEGER,
                category_name TEXT,
                image_path TEXT,
                track_stock INTEGER NOT NULL DEFAULT 1,
                current_stock REAL,
                updated_at TEXT,
                cached_at INTEGER NOT NULL
            )
        """.trimIndent())
        db.execSQL("""
            CREATE TABLE IF NOT EXISTS sales (
                offline_uuid TEXT PRIMARY KEY,
                receipt_id TEXT NOT NULL,
                created_at INTEGER NOT NULL,
                total INTEGER NOT NULL,
                payment_method TEXT NOT NULL,
                paid_amount INTEGER NOT NULL,
                change_amount INTEGER NOT NULL,
                payload_json TEXT NOT NULL,
                sync_status TEXT NOT NULL DEFAULT 'pending'
            )
        """.trimIndent())
        db.execSQL("""
            CREATE TABLE IF NOT EXISTS sale_items (
                id INTEGER PRIMARY KEY AUTOINCREMENT,
                sale_uuid TEXT NOT NULL,
                product_id INTEGER NOT NULL,
                product_name TEXT NOT NULL,
                qty REAL NOT NULL,
                price INTEGER NOT NULL,
                subtotal INTEGER NOT NULL,
                FOREIGN KEY(sale_uuid) REFERENCES sales(offline_uuid) ON DELETE CASCADE
            )
        """.trimIndent())
        db.execSQL("""
            CREATE TABLE IF NOT EXISTS sync_queue (
                id INTEGER PRIMARY KEY AUTOINCREMENT,
                offline_uuid TEXT NOT NULL UNIQUE,
                entity_type TEXT NOT NULL,
                payload_json TEXT NOT NULL,
                sync_status TEXT NOT NULL DEFAULT 'pending',
                retry_count INTEGER NOT NULL DEFAULT 0,
                last_error TEXT,
                created_at INTEGER NOT NULL,
                updated_at INTEGER NOT NULL
            )
        """.trimIndent())
        db.execSQL("CREATE INDEX IF NOT EXISTS idx_sync_queue_status ON sync_queue(sync_status, created_at)")
        db.execSQL("CREATE INDEX IF NOT EXISTS idx_sale_items_sale ON sale_items(sale_uuid)")
    }

    override fun onUpgrade(db: SQLiteDatabase, oldVersion: Int, newVersion: Int) { onCreate(db) }

    @Synchronized fun putState(key: String, json: String) {
        val now = System.currentTimeMillis()
        writableDatabase.insertWithOnConflict("app_state", null, ContentValues().apply {
            put("state_key", key); put("value_json", json); put("updated_at", now)
        }, SQLiteDatabase.CONFLICT_REPLACE)
    }

    @Synchronized fun getState(key: String): String? {
        readableDatabase.query("app_state", arrayOf("value_json"), "state_key=?", arrayOf(key), null, null, null, "1").use { c ->
            return if (c.moveToFirst()) c.getString(0) else null
        }
    }

    @Synchronized fun saveProducts(productsJson: String): Int {
        val products = JSONArray(productsJson)
        val db = writableDatabase
        val now = System.currentTimeMillis()
        db.beginTransaction()
        var saved = 0
        return try {
            for (i in 0 until products.length()) {
                val p = products.optJSONObject(i) ?: continue
                val id = p.optLong("id", 0L)
                if (id <= 0L) continue
                db.insertWithOnConflict("products", null, ContentValues().apply {
                    put("id", id)
                    put("name", p.optString("name", ""))
                    put("price", p.optDouble("price", 0.0))
                    if (p.has("category_id") && !p.isNull("category_id")) put("category_id", p.optLong("category_id")) else putNull("category_id")
                    put("category_name", p.optString("category_name", p.optString("category", "")))
                    put("image_path", p.optString("image_path", ""))
                    put("track_stock", if (p.optInt("track_stock", 1) == 0) 0 else 1)
                    if (p.has("current_stock") && !p.isNull("current_stock")) put("current_stock", p.optDouble("current_stock")) else putNull("current_stock")
                    put("updated_at", p.optString("updated_at", ""))
                    put("cached_at", now)
                }, SQLiteDatabase.CONFLICT_REPLACE)
                saved++
            }
            putStateInTransaction(db, "last_product_sync", JSONObject().put("timestamp", now).put("count", saved).toString(), now)
            db.setTransactionSuccessful(); saved
        } finally { db.endTransaction() }
    }

    private fun putStateInTransaction(db: SQLiteDatabase, key: String, value: String, now: Long) {
        db.insertWithOnConflict("app_state", null, ContentValues().apply {
            put("state_key", key); put("value_json", value); put("updated_at", now)
        }, SQLiteDatabase.CONFLICT_REPLACE)
    }

    @Synchronized fun productCount(): Int = readableDatabase.rawQuery("SELECT COUNT(*) FROM products", null).use { c -> if (c.moveToFirst()) c.getInt(0) else 0 }

    @Synchronized fun getProducts(search: String = "", category: String? = null): List<Product> {
        val where = mutableListOf<String>(); val args = mutableListOf<String>()
        if (search.isNotBlank()) { where += "name LIKE ?"; args += "%${search.trim()}%" }
        if (!category.isNullOrBlank()) { where += "category_name=?"; args += category }
        val result = mutableListOf<Product>()
        readableDatabase.query(
            "products", arrayOf("id","name","price","category_id","category_name","track_stock","current_stock"),
            where.takeIf { it.isNotEmpty() }?.joinToString(" AND "), args.takeIf { it.isNotEmpty() }?.toTypedArray(),
            null, null, "name COLLATE NOCASE ASC"
        ).use { c ->
            while (c.moveToNext()) result += Product(
                id=c.getLong(0), name=c.getString(1), price=c.getDouble(2).toLong(),
                categoryId=if(c.isNull(3)) null else c.getLong(3), categoryName=c.getString(4) ?: "",
                trackStock=c.getInt(5)!=0, currentStock=if(c.isNull(6)) null else c.getDouble(6)
            )
        }
        return result
    }

    @Synchronized fun getCategories(): List<String> {
        val out = mutableListOf<String>()
        readableDatabase.rawQuery("SELECT DISTINCT category_name FROM products WHERE category_name IS NOT NULL AND TRIM(category_name)<>'' ORDER BY category_name COLLATE NOCASE", null).use { c ->
            while(c.moveToNext()) out += c.getString(0)
        }
        return out
    }

    @Synchronized fun loadProducts(): String {
        val rows = JSONArray()
        getProducts().forEach { p -> rows.put(JSONObject().apply {
            put("id", p.id); put("name", p.name); put("price", p.price); put("category_id", p.categoryId ?: JSONObject.NULL)
            put("category_name", p.categoryName); put("track_stock", if(p.trackStock) 1 else 0); put("current_stock", p.currentStock ?: JSONObject.NULL)
        }) }
        return rows.toString()
    }

    /** Atomically commits a completed local sale and reserves local stock. */
    @Synchronized fun commitSale(receiptId: String, total: Long, paymentMethod: String, paid: Long, change: Long, items: JSONArray): String {
        require(items.length() > 0) { "Keranjang kosong" }
        val uuid = UUID.randomUUID().toString(); val now = System.currentTimeMillis()
        val payload = JSONObject().apply {
            put("offline_uuid", uuid); put("receipt_id", receiptId); put("created_at", now); put("total", total)
            put("payment_method", paymentMethod); put("bayar", paid); put("kembalian", change); put("items", items)
        }
        val db = writableDatabase; db.beginTransaction()
        try {
            db.insertOrThrow("sales", null, ContentValues().apply {
                put("offline_uuid", uuid); put("receipt_id", receiptId); put("created_at", now); put("total", total)
                put("payment_method", paymentMethod); put("paid_amount", paid); put("change_amount", change)
                put("payload_json", payload.toString()); put("sync_status", "pending")
            })
            for(i in 0 until items.length()) {
                val item = items.getJSONObject(i); val productId=item.getLong("product_id"); val qty=item.getDouble("qty")
                db.insertOrThrow("sale_items", null, ContentValues().apply {
                    put("sale_uuid", uuid); put("product_id", productId); put("product_name", item.optString("name")); put("qty", qty)
                    put("price", item.getLong("price")); put("subtotal", item.getLong("subtotal"))
                })
                db.execSQL("UPDATE products SET current_stock=current_stock-? WHERE id=? AND track_stock=1 AND current_stock IS NOT NULL", arrayOf(qty, productId))
            }
            db.insertOrThrow("sync_queue", null, ContentValues().apply {
                put("offline_uuid", uuid); put("entity_type", "sale"); put("payload_json", payload.toString()); put("sync_status", "pending")
                put("retry_count", 0); put("last_error", ""); put("created_at", now); put("updated_at", now)
            })
            db.setTransactionSuccessful()
        } finally { db.endTransaction() }
        return uuid
    }

    @Synchronized fun enqueue(entityType: String, payloadJson: String, offlineUuid: String? = null): JSONObject {
        val uuid = offlineUuid?.takeIf { it.isNotBlank() } ?: UUID.randomUUID().toString(); val now=System.currentTimeMillis()
        writableDatabase.insertWithOnConflict("sync_queue", null, ContentValues().apply {
            put("offline_uuid",uuid); put("entity_type",entityType); put("payload_json",payloadJson); put("sync_status","pending")
            put("retry_count",0); put("last_error",""); put("created_at",now); put("updated_at",now)
        }, SQLiteDatabase.CONFLICT_IGNORE)
        return JSONObject().put("offline_uuid",uuid).put("entity_type",entityType).put("created_at",now)
    }

    @Synchronized fun loadQueue(): String {
        val rows=JSONArray()
        readableDatabase.query("sync_queue", arrayOf("offline_uuid","entity_type","payload_json","sync_status","retry_count","last_error","created_at"), "sync_status<>?", arrayOf("synced"), null,null,"created_at ASC").use { c ->
            while(c.moveToNext()) rows.put(JSONObject().apply {
                put("offline_uuid",c.getString(0)); put("entity_type",c.getString(1)); put("payload",runCatching{JSONObject(c.getString(2))}.getOrElse{JSONObject()})
                put("sync_status",c.getString(3)); put("retry_count",c.getInt(4)); put("last_error",c.getString(5)?:""); put("created_at",c.getLong(6))
            })
        }; return rows.toString()
    }

    @Synchronized fun pendingSyncCount(): Int = readableDatabase.rawQuery("SELECT COUNT(*) FROM sync_queue WHERE sync_status<>'synced'", null).use { c -> if(c.moveToFirst()) c.getInt(0) else 0 }

    @Synchronized fun markQueue(offlineUuid: String, status: String, error: String = "") {
        val db=writableDatabase
        db.update("sync_queue", ContentValues().apply { put("sync_status",status); put("last_error",error); put("updated_at",System.currentTimeMillis()) }, "offline_uuid=?", arrayOf(offlineUuid))
        db.update("sales", ContentValues().apply { put("sync_status",status) }, "offline_uuid=?", arrayOf(offlineUuid))
        if(status=="synced") db.delete("sync_queue","offline_uuid=?",arrayOf(offlineUuid)) else db.execSQL("UPDATE sync_queue SET retry_count=retry_count+1 WHERE offline_uuid=?",arrayOf(offlineUuid))
    }

    companion object { private const val DB_NAME="adena_pos_offline.db"; private const val DB_VERSION=2 }
}
