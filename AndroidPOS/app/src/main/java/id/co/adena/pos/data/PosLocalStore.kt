package id.co.adena.pos.data

import android.content.ContentValues
import android.content.Context
import android.database.sqlite.SQLiteDatabase
import android.database.sqlite.SQLiteOpenHelper
import org.json.JSONArray
import org.json.JSONObject
import java.util.UUID

/**
 * Persistent local-first store for Adena POS Android.
 * SQLite is the durable source used by the WebView bridge; WebView localStorage is only a fallback.
 */
class PosLocalStore(context: Context) : SQLiteOpenHelper(context, DB_NAME, null, DB_VERSION) {
    init { setWriteAheadLoggingEnabled(true) }

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
    }

    override fun onUpgrade(db: SQLiteDatabase, oldVersion: Int, newVersion: Int) {
        onCreate(db)
    }

    @Synchronized
    fun putState(key: String, json: String) {
        val now = System.currentTimeMillis()
        writableDatabase.insertWithOnConflict(
            "app_state", null,
            ContentValues().apply {
                put("state_key", key)
                put("value_json", json)
                put("updated_at", now)
            },
            SQLiteDatabase.CONFLICT_REPLACE,
        )
    }

    @Synchronized
    fun getState(key: String): String? {
        readableDatabase.query(
            "app_state", arrayOf("value_json"), "state_key=?", arrayOf(key), null, null, null, "1"
        ).use { c -> return if (c.moveToFirst()) c.getString(0) else null }
    }

    @Synchronized
    fun saveProducts(productsJson: String): Int {
        val products = JSONArray(productsJson)
        val db = writableDatabase
        val now = System.currentTimeMillis()
        db.beginTransaction()
        return try {
            for (i in 0 until products.length()) {
                val p = products.optJSONObject(i) ?: continue
                val id = p.optLong("id", 0L)
                if (id <= 0L) continue
                db.insertWithOnConflict(
                    "products", null,
                    ContentValues().apply {
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
                    },
                    SQLiteDatabase.CONFLICT_REPLACE,
                )
            }
            db.setTransactionSuccessful()
            products.length()
        } finally {
            db.endTransaction()
        }
    }

    @Synchronized
    fun loadProducts(): String {
        val rows = JSONArray()
        readableDatabase.query(
            "products",
            arrayOf("id", "name", "price", "category_id", "category_name", "image_path", "track_stock", "current_stock", "updated_at", "cached_at"),
            null, null, null, null, "name COLLATE NOCASE ASC"
        ).use { c ->
            while (c.moveToNext()) {
                rows.put(JSONObject().apply {
                    put("id", c.getLong(0))
                    put("name", c.getString(1))
                    put("price", c.getDouble(2))
                    if (c.isNull(3)) put("category_id", JSONObject.NULL) else put("category_id", c.getLong(3))
                    put("category_name", c.getString(4) ?: "")
                    put("image_path", c.getString(5) ?: "")
                    put("track_stock", c.getInt(6))
                    if (c.isNull(7)) put("current_stock", JSONObject.NULL) else put("current_stock", c.getDouble(7))
                    put("updated_at", c.getString(8) ?: "")
                    put("cached_at", c.getLong(9))
                })
            }
        }
        return rows.toString()
    }

    @Synchronized
    fun enqueue(entityType: String, payloadJson: String, offlineUuid: String? = null): JSONObject {
        val uuid = offlineUuid?.takeIf { it.isNotBlank() } ?: UUID.randomUUID().toString()
        val now = System.currentTimeMillis()
        val db = writableDatabase
        db.beginTransaction()
        return try {
            val inserted = db.insertWithOnConflict(
                "sync_queue", null,
                ContentValues().apply {
                    put("offline_uuid", uuid)
                    put("entity_type", entityType)
                    put("payload_json", payloadJson)
                    put("sync_status", "pending")
                    put("retry_count", 0)
                    put("last_error", "")
                    put("created_at", now)
                    put("updated_at", now)
                },
                SQLiteDatabase.CONFLICT_IGNORE,
            )
            // Reserve stock immediately for a locally committed sale. This keeps the next cart
            // transaction consistent with the last known stock even while the device is offline.
            if (inserted != -1L && entityType == "sale") {
                val payload = JSONObject(payloadJson)
                val items = payload.optJSONArray("items") ?: JSONArray()
                for (i in 0 until items.length()) {
                    val item = items.optJSONObject(i) ?: continue
                    val productId = item.optLong("product_id", 0L)
                    val qty = item.optDouble("qty", 0.0)
                    if (productId > 0 && qty > 0) {
                        db.execSQL(
                            "UPDATE products SET current_stock=current_stock-? WHERE id=? AND track_stock=1 AND current_stock IS NOT NULL",
                            arrayOf(qty, productId),
                        )
                    }
                }
            }
            db.setTransactionSuccessful()
            JSONObject().put("offline_uuid", uuid).put("entity_type", entityType).put("created_at", now)
        } finally {
            db.endTransaction()
        }
    }

    @Synchronized
    fun loadQueue(): String {
        val rows = JSONArray()
        readableDatabase.query(
            "sync_queue",
            arrayOf("offline_uuid", "entity_type", "payload_json", "sync_status", "retry_count", "last_error", "created_at"),
            "sync_status<>?", arrayOf("synced"), null, null, "created_at ASC"
        ).use { c ->
            while (c.moveToNext()) {
                rows.put(JSONObject().apply {
                    put("offline_uuid", c.getString(0))
                    put("entity_type", c.getString(1))
                    put("payload", runCatching { JSONObject(c.getString(2)) }.getOrElse { JSONObject() })
                    put("sync_status", c.getString(3))
                    put("retry_count", c.getInt(4))
                    put("last_error", c.getString(5) ?: "")
                    put("created_at", c.getLong(6))
                })
            }
        }
        return rows.toString()
    }

    @Synchronized
    fun markQueue(offlineUuid: String, status: String, error: String = "") {
        writableDatabase.update(
            "sync_queue",
            ContentValues().apply {
                put("sync_status", status)
                put("last_error", error)
                put("updated_at", System.currentTimeMillis())
            },
            "offline_uuid=?", arrayOf(offlineUuid)
        )
        if (status == "synced") {
            writableDatabase.delete("sync_queue", "offline_uuid=?", arrayOf(offlineUuid))
        } else {
            writableDatabase.execSQL("UPDATE sync_queue SET retry_count=retry_count+1 WHERE offline_uuid=?", arrayOf(offlineUuid))
        }
    }

    companion object {
        private const val DB_NAME = "adena_pos_offline.db"
        private const val DB_VERSION = 1
    }
}
