package id.co.adena.pos.data

import android.content.ContentValues
import android.content.Context
import android.database.sqlite.SQLiteDatabase
import android.database.sqlite.SQLiteOpenHelper
import org.json.JSONArray
import org.json.JSONObject
import java.text.SimpleDateFormat
import java.util.Date
import java.util.Locale
import java.util.UUID
import kotlin.math.min
import kotlin.math.roundToLong

class PosLocalStore(context: Context) : SQLiteOpenHelper(context, DB_NAME, null, DB_VERSION) {
    data class Product(val id:Long,val name:String,val price:Long,val categoryId:Long?,val categoryName:String,val trackStock:Boolean,val currentStock:Double?,val imagePath:String="",val favorite:Boolean=false,val bestSeller:Boolean=false)
    data class Category(val id:Long?,val name:String)
    data class Guide(val id:Long,val name:String)
    data class PaymentMethod(val code:String,val name:String,val requiresBank:Boolean)
    data class Bank(val id:Long,val name:String,val method:String="")
    data class HistoryTx(val groupId:String,val code:String,val soldAt:String,val total:Long,val payment:String,val bank:String,val customer:String,val guide:String,val syncStatus:String,val returnStatus:String,val revisionStatus:String,val revisionNo:Int)
    data class HistoryItem(val rowId:Long,val productId:Long,val name:String,val qty:Double,val price:Long,val discountAmount:Double,val discountType:String,val total:Long)

    override fun onCreate(db: SQLiteDatabase) { createSchema(db) }
    private fun createSchema(db:SQLiteDatabase){
        db.execSQL("CREATE TABLE IF NOT EXISTS app_state(state_key TEXT PRIMARY KEY,value_json TEXT NOT NULL,updated_at INTEGER NOT NULL)")
        db.execSQL("CREATE TABLE IF NOT EXISTS products(id INTEGER PRIMARY KEY,name TEXT NOT NULL,price REAL NOT NULL DEFAULT 0,category_id INTEGER,category_name TEXT,image_path TEXT,is_favorite INTEGER DEFAULT 0,is_best_seller INTEGER DEFAULT 0,show_on_pos INTEGER DEFAULT 1,track_stock INTEGER DEFAULT 1,current_stock REAL,updated_at TEXT,cached_at INTEGER NOT NULL)")
        db.execSQL("CREATE TABLE IF NOT EXISTS product_categories(id INTEGER PRIMARY KEY,name TEXT NOT NULL)")
        db.execSQL("CREATE TABLE IF NOT EXISTS guides(id INTEGER PRIMARY KEY,name TEXT NOT NULL,is_active INTEGER DEFAULT 1)")
        db.execSQL("CREATE TABLE IF NOT EXISTS payment_methods(id INTEGER PRIMARY KEY AUTOINCREMENT,code TEXT UNIQUE,name TEXT,is_active INTEGER DEFAULT 1,sort_order INTEGER DEFAULT 0,requires_bank INTEGER DEFAULT 0)")
        db.execSQL("CREATE TABLE IF NOT EXISTS payment_channels(id INTEGER PRIMARY KEY,payment_method TEXT,channel_name TEXT,bank_name TEXT,is_active INTEGER DEFAULT 1,sort_order INTEGER DEFAULT 0)")
        db.execSQL("CREATE TABLE IF NOT EXISTS qris_banks(id INTEGER PRIMARY KEY,name TEXT,sort_order INTEGER DEFAULT 0,is_active INTEGER DEFAULT 1)")
        db.execSQL("CREATE TABLE IF NOT EXISTS settings(key TEXT PRIMARY KEY,value TEXT NOT NULL)")
        db.execSQL("CREATE TABLE IF NOT EXISTS users(id INTEGER PRIMARY KEY,username TEXT,name TEXT,role TEXT,role_name TEXT)")
        db.execSQL("CREATE TABLE IF NOT EXISTS customers(id INTEGER PRIMARY KEY,name TEXT NOT NULL,phone TEXT,gender TEXT,loyalty_points INTEGER NOT NULL DEFAULT 0,loyalty_remainder INTEGER NOT NULL DEFAULT 0,email TEXT)")
        db.execSQL("CREATE TABLE IF NOT EXISTS pos_shifts(id INTEGER PRIMARY KEY AUTOINCREMENT,server_id INTEGER,shift_code TEXT,opened_at TEXT,opened_by INTEGER,opening_cash_actual REAL DEFAULT 0,status TEXT DEFAULT 'open',closed_at TEXT,closed_by INTEGER,counted_cash_total REAL DEFAULT 0,offline_open_uuid TEXT UNIQUE,offline_close_uuid TEXT,sync_status TEXT DEFAULT 'pending')")
        db.execSQL("CREATE TABLE IF NOT EXISTS orders(id INTEGER PRIMARY KEY,order_code TEXT,status TEXT,created_at TEXT,customer_name TEXT,customer_contact TEXT,customer_address TEXT,customer_note TEXT,total_amount REAL DEFAULT 0)")
        db.execSQL("CREATE TABLE IF NOT EXISTS order_items(id INTEGER PRIMARY KEY,order_id INTEGER,product_id INTEGER,qty REAL,price_each REAL,subtotal REAL,product_name TEXT)")
        db.execSQL("CREATE TABLE IF NOT EXISTS sales(id INTEGER PRIMARY KEY AUTOINCREMENT,web_sale_id INTEGER,transaction_code TEXT,transaction_group_uuid TEXT,offline_uuid TEXT,sync_status TEXT NOT NULL DEFAULT 'pending',base_sale_code TEXT,revision_suffix TEXT,revision_no INTEGER NOT NULL DEFAULT 0,is_active_revision INTEGER NOT NULL DEFAULT 1,revised_from_sale_id INTEGER,revision_reason_category TEXT,revision_reason_text TEXT,revised_by_user_id INTEGER,revised_at TEXT,revision_status TEXT NOT NULL DEFAULT 'active',original_sale_id INTEGER,product_id INTEGER NOT NULL,product_name TEXT,branch_id INTEGER,sale_source TEXT NOT NULL DEFAULT 'branch_pos',unit_type TEXT,shift_id INTEGER,shift_offline_uuid TEXT,qty REAL NOT NULL DEFAULT 1,price_each REAL NOT NULL DEFAULT 0,total REAL NOT NULL DEFAULT 0,payment_method TEXT NOT NULL DEFAULT 'cash',payment_proof_path TEXT,created_by INTEGER,return_reason TEXT,returned_at TEXT,sold_at TEXT,payment_bank TEXT,guide_name TEXT,discount_amount REAL NOT NULL DEFAULT 0,discount_type TEXT NOT NULL DEFAULT 'fixed',tx_discount_amount REAL NOT NULL DEFAULT 0,tx_discount_type TEXT NOT NULL DEFAULT 'fixed',include_in_sales_report INTEGER NOT NULL DEFAULT 1,line_subtotal REAL NOT NULL DEFAULT 0,line_net_total REAL NOT NULL DEFAULT 0,pending_order_id INTEGER,local_device_id TEXT,local_transaction_id TEXT,payment_channel_id INTEGER,payment_channel_name TEXT,guide_id INTEGER,customer_name TEXT,customer_phone TEXT,payment_summary TEXT,customer_id INTEGER,returned_by INTEGER,return_status TEXT NOT NULL DEFAULT 'none',sync_error TEXT)")
        db.execSQL("CREATE TABLE IF NOT EXISTS stock_ledger(id INTEGER PRIMARY KEY AUTOINCREMENT,branch_id INTEGER NOT NULL DEFAULT 1,location_id INTEGER,product_id INTEGER NOT NULL,trans_type TEXT NOT NULL,ref_table TEXT NOT NULL DEFAULT 'sales',ref_id INTEGER NOT NULL DEFAULT 0,ref_group TEXT,qty_in REAL NOT NULL DEFAULT 0,qty_out REAL NOT NULL DEFAULT 0,unit_cost REAL,note TEXT,created_by INTEGER,created_at TEXT)")
        db.execSQL("CREATE TABLE IF NOT EXISTS sales_returns(id INTEGER PRIMARY KEY AUTOINCREMENT,offline_uuid TEXT UNIQUE,transaction_group_uuid TEXT,transaction_code TEXT,reason TEXT,total_return REAL DEFAULT 0,created_by INTEGER,created_at TEXT,sync_status TEXT DEFAULT 'pending',sync_error TEXT)")
        db.execSQL("CREATE TABLE IF NOT EXISTS sales_return_items(id INTEGER PRIMARY KEY AUTOINCREMENT,return_offline_uuid TEXT,product_id INTEGER,qty REAL,price_each REAL,subtotal REAL)")
        db.execSQL("CREATE TABLE IF NOT EXISTS sync_queue(id INTEGER PRIMARY KEY AUTOINCREMENT,offline_uuid TEXT UNIQUE NOT NULL,entity_type TEXT NOT NULL,payload_json TEXT NOT NULL,sync_status TEXT NOT NULL DEFAULT 'pending',retry_count INTEGER NOT NULL DEFAULT 0,last_error TEXT,created_at INTEGER NOT NULL,updated_at INTEGER NOT NULL)")
        createIndexes(db)
    }

    private fun createIndexes(db: SQLiteDatabase) {
        // Older Adena POS databases may not have every new column yet.
        // Index creation must never abort migration before ensureColumn() runs.
        runCatching { db.execSQL("CREATE INDEX IF NOT EXISTS idx_products_cat ON products(category_name)") }
        runCatching { db.execSQL("CREATE INDEX IF NOT EXISTS idx_sales_group ON sales(transaction_group_uuid)") }
        runCatching { db.execSQL("CREATE INDEX IF NOT EXISTS idx_sales_sold_at ON sales(sold_at)") }
        runCatching { db.execSQL("CREATE INDEX IF NOT EXISTS idx_sync_queue_status ON sync_queue(sync_status,created_at)") }
    }

    private fun hasColumn(db: SQLiteDatabase, table: String, column: String): Boolean =
        db.rawQuery("PRAGMA table_info(`$table`)", null).use { c ->
            val nameIndex = c.getColumnIndex("name")
            while (c.moveToNext()) if (nameIndex >= 0 && c.getString(nameIndex) == column) return@use true
            false
        }

    private fun ensureColumn(db: SQLiteDatabase, table: String, column: String, definition: String) {
        if (!hasColumn(db, table, column)) db.execSQL("ALTER TABLE `$table` ADD COLUMN `$column` $definition")
    }

    private fun repairCompatibilitySchema(db: SQLiteDatabase) {
        // Products evolved from the original WebView cache table.
        ensureColumn(db,"products","category_id","INTEGER")
        ensureColumn(db,"products","category_name","TEXT")
        ensureColumn(db,"products","image_path","TEXT")
        ensureColumn(db,"products","is_favorite","INTEGER DEFAULT 0")
        ensureColumn(db,"products","is_best_seller","INTEGER DEFAULT 0")
        ensureColumn(db,"products","show_on_pos","INTEGER DEFAULT 1")
        ensureColumn(db,"products","track_stock","INTEGER DEFAULT 1")
        ensureColumn(db,"products","current_stock","REAL")
        ensureColumn(db,"products","updated_at","TEXT")
        ensureColumn(db,"products","cached_at","INTEGER NOT NULL DEFAULT 0")

        // Legacy WebView builds already had a table named `sales`, but its shape was
        // transaction-level. Native desktop-parity uses item-level rows. Keep old data and
        // add every column required by native writes/queries instead of dropping the table.
        val salesColumns = listOf(
            "web_sale_id" to "INTEGER",
            "transaction_code" to "TEXT",
            "base_sale_code" to "TEXT",
            "revision_suffix" to "TEXT",
            "revision_no" to "INTEGER DEFAULT 0",
            "is_active_revision" to "INTEGER DEFAULT 1",
            "revised_from_group" to "TEXT",
            "revision_reason_category" to "TEXT",
            "revision_reason_text" to "TEXT",
            "revision_status" to "TEXT DEFAULT 'active'",
            "transaction_group_uuid" to "TEXT",
            "offline_uuid" to "TEXT",
            // Fundamental item columns were missing in the previous migration and caused
            // SQLITE_ERROR at payment time (notably price_each).
            "product_id" to "INTEGER",
            "product_name" to "TEXT",
            "qty" to "REAL",
            "price_each" to "REAL",
            "total" to "REAL DEFAULT 0",
            "discount_amount" to "REAL DEFAULT 0",
            "discount_type" to "TEXT DEFAULT 'fixed'",
            "tx_discount_amount" to "REAL DEFAULT 0",
            "tx_discount_type" to "TEXT DEFAULT 'fixed'",
            "payment_method" to "TEXT",
            "payment_bank" to "TEXT",
            "guide_id" to "INTEGER",
            "guide_name" to "TEXT",
            "customer_name" to "TEXT",
            "customer_phone" to "TEXT",
            "created_by" to "INTEGER",
            "branch_id" to "INTEGER",
            "shift_id" to "INTEGER",
            "sold_at" to "TEXT",
            "local_device_id" to "TEXT",
            "local_transaction_id" to "TEXT",
            "sync_status" to "TEXT DEFAULT 'pending'",
            "sync_error" to "TEXT",
            "return_reason" to "TEXT",
            "returned_at" to "TEXT",
            "returned_by" to "INTEGER",
            "return_status" to "TEXT DEFAULT 'none'"
        )
        salesColumns.forEach { (name, definition) -> ensureColumn(db,"sales",name,definition) }

        // Old transaction-level rows do not have an item identity. Keep them for safety,
        // but make defaults predictable so native history queries can explicitly ignore them.
        runCatching { db.execSQL("UPDATE sales SET revision_no=COALESCE(revision_no,0), is_active_revision=COALESCE(is_active_revision,1), revision_status=COALESCE(revision_status,'active'), sync_status=COALESCE(sync_status,'pending'), return_status=COALESCE(return_status,'none')") }

        createIndexes(db)
    }

    override fun onUpgrade(db:SQLiteDatabase,oldVersion:Int,newVersion:Int){
        // Android POS DB is an offline mirror/queue. Adena web is the source of truth.
        // Native schema upgrades are intentionally rebuilt instead of inheriting old WebView constraints.
        listOf("sync_queue","sales_return_items","sales_returns","stock_ledger","sales","order_items","orders","pos_shifts","customers","users","settings","qris_banks","payment_channels","payment_methods","guides","product_categories","products","app_state").forEach {
            db.execSQL("DROP TABLE IF EXISTS `$it`")
        }
        createSchema(db)
    }

    @Synchronized fun putState(key:String,json:String){val n=System.currentTimeMillis();writableDatabase.insertWithOnConflict("app_state",null,ContentValues().apply{put("state_key",key);put("value_json",json);put("updated_at",n)},SQLiteDatabase.CONFLICT_REPLACE)}
    @Synchronized fun getState(key:String):String?=readableDatabase.query("app_state",arrayOf("value_json"),"state_key=?",arrayOf(key),null,null,null,"1").use{if(it.moveToFirst())it.getString(0) else null}
    @Synchronized fun setting(key:String,default:String=""):String=readableDatabase.query("settings",arrayOf("value"),"key=?",arrayOf(key),null,null,null,"1").use{if(it.moveToFirst())it.getString(0) else default}

    @Synchronized fun saveProducts(productsJson:String):Int { val a=JSONArray(productsJson); return saveProductsArray(writableDatabase,a,false) }
    private fun saveProductsArray(db:SQLiteDatabase,a:JSONArray,clear:Boolean):Int { if(clear) db.delete("products",null,null); var saved=0; val now=System.currentTimeMillis(); for(i in 0 until a.length()){val p=a.optJSONObject(i)?:continue;val id=p.optLong("id");if(id<=0)continue; db.insertWithOnConflict("products",null,ContentValues().apply{put("id",id);put("name",p.optString("name"));put("price",p.optDouble("price",0.0));if(p.has("category_id")&&!p.isNull("category_id"))put("category_id",p.optLong("category_id")) else putNull("category_id");put("category_name",p.optString("category_name",p.optString("category")));put("image_path",p.optString("image_path"));put("is_favorite",p.optInt("is_favorite",0));put("is_best_seller",p.optInt("is_best_seller",0));put("show_on_pos",p.optInt("show_on_pos",1));put("track_stock",p.optInt("track_stock",1));if(p.has("current_stock")&&!p.isNull("current_stock"))put("current_stock",p.optDouble("current_stock")) else putNull("current_stock");put("updated_at",p.optString("updated_at"));put("cached_at",now)},SQLiteDatabase.CONFLICT_REPLACE);saved++};return saved }

    @Synchronized fun saveMaster(payload:JSONObject):JSONObject { val db=writableDatabase;db.beginTransaction();return try{
        val cats=payload.optJSONArray("categories")?:payload.optJSONArray("product_categories")?:JSONArray()
        val catNames=mutableMapOf<Long,String>()
        db.delete("product_categories",null,null)
        for(i in 0 until cats.length()){
            val r=cats.optJSONObject(i)?:continue
            val id=r.optLong("id",i+1L)
            val name=r.optString("name",r.optString("category_name"))
            if(id>0 && name.isNotBlank()) catNames[id]=name
            db.insertWithOnConflict("product_categories",null,ContentValues().apply{put("id",id);put("name",name)},SQLiteDatabase.CONFLICT_REPLACE)
        }

        val stockByProduct=mutableMapOf<Long,Double>()
        val stocks=payload.optJSONArray("stocks")?:JSONArray()
        for(i in 0 until stocks.length()){
            val r=stocks.optJSONObject(i)?:continue
            val productId=r.optLong("product_id")
            if(productId>0) stockByProduct[productId]=r.optDouble("current_stock",0.0)
        }

        val sourceProducts=payload.optJSONArray("products")?:JSONArray()
        val normalizedProducts=JSONArray()
        for(i in 0 until sourceProducts.length()){
            val src=sourceProducts.optJSONObject(i)?:continue
            val p=JSONObject(src.toString())
            val productId=p.optLong("id")
            val categoryId=when {
                p.has("category_id") && !p.isNull("category_id") -> p.optLong("category_id")
                p.has("category") && !p.isNull("category") -> p.optLong("category")
                else -> 0L
            }
            if(categoryId>0){
                p.put("category_id",categoryId)
                catNames[categoryId]?.let{p.put("category_name",it)}
            }
            if(stockByProduct.containsKey(productId)) p.put("current_stock",stockByProduct[productId])
            normalizedProducts.put(p)
        }
        val productCount=saveProductsArray(db,normalizedProducts,true)

        db.delete("guides",null,null); val guides=payload.optJSONArray("guides")?:JSONArray();for(i in 0 until guides.length()){val r=guides.optJSONObject(i)?:continue;db.insertWithOnConflict("guides",null,ContentValues().apply{put("id",r.optLong("id"));put("name",r.optString("name"));put("is_active",r.optInt("is_active",1))},SQLiteDatabase.CONFLICT_REPLACE)}
        db.delete("payment_methods",null,null);val pm=payload.optJSONArray("payment_methods")?:JSONArray();for(i in 0 until pm.length()){val r=pm.optJSONObject(i)?:continue;db.insertWithOnConflict("payment_methods",null,ContentValues().apply{put("id",r.optLong("id",i+1L));put("code",r.optString("code"));put("name",r.optString("name",r.optString("code")));put("is_active",r.optInt("is_active",1));put("sort_order",r.optInt("sort_order",0));put("requires_bank",r.optInt("requires_bank",if(listOf("qris","transfer","edc","credit_card").contains(r.optString("code").lowercase()))1 else 0))},SQLiteDatabase.CONFLICT_REPLACE)}
        db.delete("qris_banks",null,null);val banks=payload.optJSONArray("banks")?:payload.optJSONArray("qris_banks")?:JSONArray();for(i in 0 until banks.length()){val r=banks.optJSONObject(i)?:continue;db.insertWithOnConflict("qris_banks",null,ContentValues().apply{put("id",r.optLong("id",i+1L));put("name",r.optString("name",r.optString("bank_name")));put("sort_order",r.optInt("sort_order",0));put("is_active",r.optInt("is_active",1))},SQLiteDatabase.CONFLICT_REPLACE)}
        val settings=payload.optJSONObject("settings")?:JSONObject();val keys=settings.keys();while(keys.hasNext()){val k=keys.next();db.insertWithOnConflict("settings",null,ContentValues().apply{put("key",k);put("value",settings.opt(k)?.toString()?:"" )},SQLiteDatabase.CONFLICT_REPLACE)}
        if(payload.has("branch_id")) db.insertWithOnConflict("settings",null,ContentValues().apply{put("key","branch_id");put("value",payload.optLong("branch_id",1).toString())},SQLiteDatabase.CONFLICT_REPLACE)
        val users=payload.optJSONArray("cashiers")?:payload.optJSONArray("users")?:JSONArray();for(i in 0 until users.length()){val r=users.optJSONObject(i)?:continue;db.insertWithOnConflict("users",null,ContentValues().apply{put("id",r.optLong("id"));put("username",r.optString("username"));put("name",r.optString("name",r.optString("username")));put("role",r.optString("role"));put("role_name",r.optString("role_name",r.optString("role")))},SQLiteDatabase.CONFLICT_REPLACE)}
        val orders=payload.optJSONArray("pending_orders")?:JSONArray();db.delete("orders",null,null);for(i in 0 until orders.length()){val r=orders.optJSONObject(i)?:continue;db.insertWithOnConflict("orders",null,ContentValues().apply{put("id",r.optLong("id"));put("order_code",r.optString("order_code"));put("status",r.optString("status"));put("created_at",r.optString("created_at"));put("customer_name",r.optString("customer_name"));put("customer_contact",r.optString("contact",r.optString("customer_contact")));put("customer_address",r.optString("customer_address"));put("customer_note",r.optString("customer_note"));put("total_amount",r.optDouble("total",r.optDouble("total_amount",0.0)))},SQLiteDatabase.CONFLICT_REPLACE)}
        val oi=payload.optJSONArray("pending_order_items")?:JSONArray();db.delete("order_items",null,null);for(i in 0 until oi.length()){val r=oi.optJSONObject(i)?:continue;db.insertWithOnConflict("order_items",null,ContentValues().apply{put("id",r.optLong("id"));put("order_id",r.optLong("order_id"));put("product_id",r.optLong("product_id"));put("qty",r.optDouble("qty"));put("price_each",r.optDouble("price_each"));put("subtotal",r.optDouble("subtotal"));put("product_name",r.optString("product_name"))},SQLiteDatabase.CONFLICT_REPLACE)}
        importSalesHistory(db,payload.optJSONArray("sales_history")?:JSONArray())
        importActiveShift(db,payload.optJSONObject("active_shift"))
        putStateTx(db,"last_native_sync",JSONObject().put("timestamp",System.currentTimeMillis()).put("product_count",productCount).toString())
        db.setTransactionSuccessful();JSONObject().put("products",productCount).put("categories",cats.length()).put("guides",guides.length()).put("payment_methods",pm.length()).put("history",payload.optJSONArray("sales_history")?.length()?:0)
    }finally{db.endTransaction()} }

    private fun importActiveShift(db:SQLiteDatabase, shift:JSONObject?){
        if(shift==null) return
        val serverId=shift.optLong("id",0)
        val code=shift.optString("shift_code")
        if(serverId<=0 || code.isBlank()) return
        // Do NOT close another local open shift merely because a sync payload has a different server id.
        // App lifecycle and sync reconciliation must never be interpreted as an explicit Tutup Shift.
        val openUuid=shift.optString("offline_open_uuid")
        val cv=ContentValues().apply{
            put("server_id",serverId);put("shift_code",code);put("opened_at",shift.optString("opened_at"));put("opened_by",shift.optLong("opened_by"));put("opening_cash_actual",shift.optDouble("opening_cash_actual",0.0));put("status",shift.optString("status","open"));put("sync_status","synced")
            if(openUuid.isNotBlank()) put("offline_open_uuid",openUuid)
            if(shift.has("closed_at")&&!shift.isNull("closed_at"))put("closed_at",shift.optString("closed_at"))
            if(shift.has("closed_by")&&!shift.isNull("closed_by"))put("closed_by",shift.optLong("closed_by"))
            if(shift.has("counted_cash_total")&&!shift.isNull("counted_cash_total"))put("counted_cash_total",shift.optDouble("counted_cash_total"))
        }
        var updated=db.update("pos_shifts",cv,"server_id=?",arrayOf(serverId.toString()))
        if(updated==0 && openUuid.isNotBlank()) updated=db.update("pos_shifts",cv,"offline_open_uuid=? AND status='open'",arrayOf(openUuid))
        if(updated==0 && code.isNotBlank()) updated=db.update("pos_shifts",cv,"shift_code=? AND status='open'",arrayOf(code))
        if(updated==0) {
            // Prefer attaching the server shift to the most recent unsynced local open shift.
            // This is the common case after an offline/queued Buka Shift is accepted by server.
            val localId=db.rawQuery("SELECT id FROM pos_shifts WHERE status='open' AND server_id IS NULL AND sync_status='pending' ORDER BY id DESC LIMIT 1",null).use{c->if(c.moveToFirst())c.getLong(0) else 0L}
            if(localId>0) updated=db.update("pos_shifts",cv,"id=?",arrayOf(localId.toString()))
        }
        if(updated==0) db.insert("pos_shifts",null,cv)
        // Clean only a proven duplicate shadow row from older builds (same offline-open UUID).
        // This is reconciliation, not a shift-close action; no close event is generated.
        if(openUuid.isNotBlank()) db.delete("pos_shifts","server_id IS NULL AND offline_open_uuid=?",arrayOf(openUuid))
    }
    private fun putStateTx(db:SQLiteDatabase,key:String,value:String){db.insertWithOnConflict("app_state",null,ContentValues().apply{put("state_key",key);put("value_json",value);put("updated_at",System.currentTimeMillis())},SQLiteDatabase.CONFLICT_REPLACE)}
    private fun importSalesHistory(db:SQLiteDatabase,a:JSONArray){for(i in 0 until a.length()){val r=a.optJSONObject(i)?:continue;val web=r.optLong("web_sale_id",r.optLong("id",0));if(web>0&&db.rawQuery("SELECT 1 FROM sales WHERE web_sale_id=?",arrayOf(web.toString())).use{it.moveToFirst()})continue; val g=r.optString("transaction_group_uuid",r.optString("transaction_code","web-$web"));db.insert("sales",null,ContentValues().apply{if(web>0)put("web_sale_id",web);put("transaction_code",r.optString("transaction_code",g));put("base_sale_code",r.optString("base_sale_code",r.optString("transaction_code",g)));put("revision_suffix",r.optString("revision_suffix"));put("revision_no",r.optInt("revision_no",0));put("is_active_revision",r.optInt("is_active_revision",1));put("revision_status",r.optString("revision_status","active"));put("transaction_group_uuid",g);put("product_id",r.optLong("product_id"));put("product_name",r.optString("product_name",r.optString("name")));put("qty",r.optDouble("qty"));put("price_each",r.optDouble("price_each"));put("total",r.optDouble("total"));put("discount_amount",r.optDouble("discount_amount"));put("discount_type",r.optString("discount_type","fixed"));put("tx_discount_amount",r.optDouble("tx_discount_amount"));put("tx_discount_type",r.optString("tx_discount_type","fixed"));put("payment_method",r.optString("payment_method"));put("payment_bank",r.optString("payment_bank"));put("guide_name",r.optString("guide_name"));put("customer_name",r.optString("customer_name"));put("customer_phone",r.optString("customer_phone"));put("created_by",r.optLong("created_by"));put("sold_at",r.optString("sold_at"));put("sync_status","imported_from_web");put("return_reason",r.optString("return_reason"));put("returned_at",r.optString("returned_at"));put("return_status",if(r.optString("return_reason").isNotBlank())"returned" else "none")})}}

    @Synchronized fun productCount():Int=readableDatabase.rawQuery("SELECT COUNT(*) FROM products WHERE show_on_pos=1",null).use{if(it.moveToFirst())it.getInt(0) else 0}
    @Synchronized fun getProducts(search:String="",category:String?=null):List<Product>{val w=mutableListOf("show_on_pos=1");val args=mutableListOf<String>();if(search.isNotBlank()){w+="name LIKE ?";args+="%${search.trim()}%"};if(!category.isNullOrBlank()){w+="category_name=?";args+=category};val out=mutableListOf<Product>();readableDatabase.query("products",arrayOf("id","name","price","category_id","category_name","track_stock","current_stock","image_path","is_favorite","is_best_seller"),w.joinToString(" AND "),args.toTypedArray(),null,null,"is_favorite DESC,is_best_seller DESC,name COLLATE NOCASE ASC").use{c->while(c.moveToNext())out+=Product(c.getLong(0),c.getString(1),c.getDouble(2).roundToLong(),if(c.isNull(3))null else c.getLong(3),c.getString(4)?:"",c.getInt(5)!=0,if(c.isNull(6))null else c.getDouble(6),c.getString(7)?:"",c.getInt(8)!=0,c.getInt(9)!=0)};return out}
    @Synchronized fun getCategories():List<String>{val o=mutableListOf<String>();readableDatabase.rawQuery("SELECT name FROM product_categories ORDER BY name COLLATE NOCASE",null).use{while(it.moveToNext())o+=it.getString(0)};if(o.isEmpty())readableDatabase.rawQuery("SELECT DISTINCT category_name FROM products WHERE TRIM(COALESCE(category_name,''))<>'' ORDER BY category_name",null).use{while(it.moveToNext())o+=it.getString(0)};return o.distinct()}
    @Synchronized fun getGuides():List<Guide>{val o=mutableListOf<Guide>();readableDatabase.rawQuery("SELECT id,name FROM guides WHERE is_active=1 ORDER BY name",null).use{while(it.moveToNext())o+=Guide(it.getLong(0),it.getString(1))};return o}
    @Synchronized fun getPaymentMethods():List<PaymentMethod>{val o=mutableListOf<PaymentMethod>();readableDatabase.rawQuery("SELECT code,name,requires_bank FROM payment_methods WHERE is_active=1 ORDER BY sort_order,id",null).use{while(it.moveToNext())o+=PaymentMethod(it.getString(0),it.getString(1),it.getInt(2)!=0)};if(o.isEmpty()){o+=PaymentMethod("cash","Cash",false);o+=PaymentMethod("qris","QRIS",true)};return o}
    @Synchronized fun getBanks():List<Bank>{val o=mutableListOf<Bank>();readableDatabase.rawQuery("SELECT id,name FROM qris_banks WHERE is_active=1 ORDER BY sort_order,id",null).use{while(it.moveToNext())o+=Bank(it.getLong(0),it.getString(1))};return o}
    @Synchronized fun loadProducts():String{val a=JSONArray();getProducts().forEach{p->a.put(JSONObject().put("id",p.id).put("name",p.name).put("price",p.price).put("category_id",p.categoryId?:JSONObject.NULL).put("category_name",p.categoryName).put("track_stock",if(p.trackStock)1 else 0).put("current_stock",p.currentStock?:JSONObject.NULL))};return a.toString()}

    private fun disc(gross:Long,amount:Double,type:String):Long{if(gross<=0||amount<=0)return 0;return if(type=="percent") min(gross,(gross*(min(100.0,amount)/100.0)).roundToLong()) else min(gross,amount.roundToLong())}
    @Synchronized fun commitDesktopSale(payload:JSONObject):String { val items=payload.optJSONArray("items")?:JSONArray();require(items.length()>0){"Keranjang kosong"};val uuid=payload.optString("offline_uuid").ifBlank{UUID.randomUUID().toString()};val group=payload.optString("transaction_group_uuid",uuid);val code=payload.optString("transaction_code").ifBlank{"TRX-${SimpleDateFormat("yyyyMMdd-HHmmss",Locale.US).format(Date())}"};val sold=payload.optString("sold_at").ifBlank{now()};val db=writableDatabase;db.beginTransaction();try{for(i in 0 until items.length()){val x=items.getJSONObject(i);val pid=x.getLong("product_id");val qty=x.optDouble("qty",1.0);val price=x.optDouble("price_each",x.optDouble("price",0.0)).roundToLong();val gross=(price*qty).roundToLong();val idisc=disc(gross,x.optDouble("discount_amount"),x.optString("discount_type","fixed"));val net=gross-idisc;db.insertOrThrow("sales",null,ContentValues().apply{put("transaction_code",code);put("base_sale_code",code);put("revision_no",0);put("is_active_revision",1);put("revision_status","active");put("transaction_group_uuid",group);if(i==0)put("offline_uuid",uuid);put("product_id",pid);put("product_name",x.optString("name"));put("qty",qty);put("price_each",price);put("total",net);put("discount_amount",x.optDouble("discount_amount"));put("discount_type",x.optString("discount_type","fixed"));put("tx_discount_amount",payload.optDouble("tx_discount_amount"));put("tx_discount_type",payload.optString("tx_discount_type","fixed"));put("payment_method",payload.optString("payment_method"));put("payment_bank",payload.optString("payment_bank"));if(payload.has("guide_id"))put("guide_id",payload.optLong("guide_id"));put("guide_name",payload.optString("guide_name"));put("customer_name",payload.optString("customer_name"));put("customer_phone",payload.optString("customer_phone"));put("created_by",payload.optLong("user_id"));put("branch_id",payload.optLong("branch_id",setting("branch_id","1").toLongOrNull()?:1L));put("sale_source","branch_pos");if(payload.has("shift_id")&&!payload.isNull("shift_id"))put("shift_id",payload.optLong("shift_id"));put("shift_offline_uuid",payload.optString("shift_offline_uuid"));put("sold_at",sold);put("local_device_id",payload.optString("local_device_id"));put("local_transaction_id",payload.optString("local_transaction_id",uuid));put("payment_channel_id",if(payload.has("payment_channel_id")&&!payload.isNull("payment_channel_id"))payload.optLong("payment_channel_id") else null);put("payment_channel_name",payload.optString("payment_channel_name",payload.optString("payment_bank")));if(payload.has("customer_id")&&!payload.isNull("customer_id"))put("customer_id",payload.optLong("customer_id"));put("payment_summary",payload.optJSONArray("payments")?.toString()?:payload.optString("payment_summary"));put("include_in_sales_report",1);put("line_subtotal",gross);put("line_net_total",net);put("sync_status","pending")});adjustStock(db,pid,-qty,"sale",group,"Penjualan $code")};enqueueTx(db,"sale",uuid,payload);db.setTransactionSuccessful()}finally{db.endTransaction()};return uuid }
    private fun adjustStock(db:SQLiteDatabase,pid:Long,delta:Double,type:String,ref:String,note:String){if(delta<0)db.execSQL("UPDATE products SET current_stock=current_stock-? WHERE id=? AND track_stock=1 AND current_stock IS NOT NULL",arrayOf(-delta,pid)) else db.execSQL("UPDATE products SET current_stock=current_stock+? WHERE id=? AND track_stock=1 AND current_stock IS NOT NULL",arrayOf(delta,pid));db.insert("stock_ledger",null,ContentValues().apply{put("product_id",pid);put("trans_type",type);put("ref_group",ref);put("qty_in",if(delta>0)delta else 0.0);put("qty_out",if(delta<0)-delta else 0.0);put("note",note);put("created_at",now())})}
    private fun enqueueTx(db:SQLiteDatabase,type:String,uuid:String,payload:JSONObject){val n=System.currentTimeMillis();db.insertWithOnConflict("sync_queue",null,ContentValues().apply{put("offline_uuid",uuid);put("entity_type",type);put("payload_json",payload.toString());put("sync_status","pending");put("retry_count",0);put("last_error","");put("created_at",n);put("updated_at",n)},SQLiteDatabase.CONFLICT_IGNORE)}

    @Synchronized fun history(from:String="",to:String="",guide:String="",payment:String="",sync:String=""):List<HistoryTx>{val where=mutableListOf("is_active_revision=1","transaction_group_uuid IS NOT NULL","product_id IS NOT NULL","qty IS NOT NULL","price_each IS NOT NULL");val args=mutableListOf<String>();if(from.isNotBlank()){where+="sold_at>=?";args+=from};if(to.isNotBlank()){where+="sold_at<=?";args+=to};if(guide.isNotBlank()){where+="guide_name=?";args+=guide};if(payment.isNotBlank()){where+="payment_method=?";args+=payment};if(sync.isNotBlank()){where+="sync_status=?";args+=sync};val sql="SELECT transaction_group_uuid,MAX(transaction_code),MAX(sold_at),SUM(total),MAX(tx_discount_amount),MAX(tx_discount_type),MAX(payment_method),MAX(payment_bank),MAX(customer_name),MAX(guide_name),MAX(sync_status),MAX(return_status),MAX(revision_status),MAX(revision_no) FROM sales WHERE ${where.joinToString(" AND ")} GROUP BY transaction_group_uuid ORDER BY MAX(sold_at) DESC LIMIT 500";val out=mutableListOf<HistoryTx>();readableDatabase.rawQuery(sql,args.toTypedArray()).use{c->while(c.moveToNext()){val subtotal=c.getDouble(3).roundToLong();val txDisc=disc(subtotal,c.getDouble(4),c.getString(5)?:"fixed");out+=HistoryTx(c.getString(0)?:c.getString(1),c.getString(1),c.getString(2),subtotal-txDisc,c.getString(6)?:"",c.getString(7)?:"",c.getString(8)?:"",c.getString(9)?:"",c.getString(10)?:"",c.getString(11)?:"none",c.getString(12)?:"active",c.getInt(13))}};return out}
    @Synchronized fun historyItems(group:String):List<HistoryItem>{val o=mutableListOf<HistoryItem>();readableDatabase.rawQuery("SELECT s.id,s.product_id,COALESCE(NULLIF(s.product_name,''),p.name,''),s.qty,s.price_each,s.discount_amount,s.discount_type,s.total FROM sales s LEFT JOIN products p ON p.id=s.product_id WHERE s.transaction_group_uuid=? AND s.is_active_revision=1 ORDER BY s.id",arrayOf(group)).use{c->while(c.moveToNext())o+=HistoryItem(c.getLong(0),c.getLong(1),c.getString(2),c.getDouble(3),c.getDouble(4).roundToLong(),c.getDouble(5),c.getString(6)?:"fixed",c.getDouble(7).roundToLong())};return o}
    @Synchronized fun historyPayload(group:String):JSONObject?{val h=history().firstOrNull{it.groupId==group}?:return null;val items=JSONArray();historyItems(group).forEach{items.put(JSONObject().put("product_id",it.productId).put("name",it.name).put("qty",it.qty).put("price_each",it.price).put("discount_amount",it.discountAmount).put("discount_type",it.discountType).put("total",it.total))};return JSONObject().put("transaction_group_uuid",group).put("transaction_code",h.code).put("sold_at",h.soldAt).put("payment_method",h.payment).put("payment_bank",h.bank).put("customer_name",h.customer).put("guide_name",h.guide).put("items",items)}

    @Synchronized fun reviseLocal(group:String,newItems:JSONArray,payment:String,bank:String,reasonCategory:String,reasonText:String,userId:Long):JSONObject {val old=history().firstOrNull{it.groupId==group}?:error("Transaksi tidak ditemukan");require(old.returnStatus!="returned"){"Transaksi sudah diretur"};val currentItems=historyItems(group);val newUuid=UUID.randomUUID().toString();val newGroup=UUID.randomUUID().toString();val nextNo=old.revisionNo+1;val suffix=revisionSuffix(nextNo);val base=old.code.replace(Regex("[A-Z]+$"),"");val newCode=base+suffix;val db=writableDatabase;db.beginTransaction();try{currentItems.forEach{adjustStock(db,it.productId,it.qty,"sale_revision_rollback",group,"Rollback revisi ${old.code}")};db.execSQL("UPDATE sales SET is_active_revision=0,revision_status='superseded' WHERE transaction_group_uuid=? AND is_active_revision=1",arrayOf(group));for(i in 0 until newItems.length()){val x=newItems.getJSONObject(i);val pid=x.getLong("product_id");val qty=x.optDouble("qty",1.0);val price=x.optDouble("price_each",x.optDouble("price",0.0)).roundToLong();val gross=(price*qty).roundToLong();val net=gross-disc(gross,x.optDouble("discount_amount"),x.optString("discount_type","fixed"));db.insert("sales",null,ContentValues().apply{put("transaction_code",newCode);put("base_sale_code",base);put("revision_suffix",suffix);put("revision_no",nextNo);put("is_active_revision",1);put("revised_from_group",group);put("revision_reason_category",reasonCategory);put("revision_reason_text",reasonText);put("revision_status","active");put("transaction_group_uuid",newGroup);if(i==0)put("offline_uuid",newUuid);put("product_id",pid);put("product_name",x.optString("name"));put("qty",qty);put("price_each",price);put("total",net);put("discount_amount",x.optDouble("discount_amount"));put("discount_type",x.optString("discount_type","fixed"));put("payment_method",payment);put("payment_bank",bank);put("created_by",userId);put("sold_at",old.soldAt);put("local_transaction_id",newUuid);put("sync_status","pending")});adjustStock(db,pid,-qty,"sale_revision_apply",newGroup,"Apply revisi $newCode")};val p=JSONObject().put("offline_uuid",newUuid).put("sale_code",old.code).put("expected_revision_no",old.revisionNo).put("reason_category",reasonCategory).put("reason_text",reasonText).put("payment_method",payment).put("payment_bank",bank).put("sold_at",old.soldAt).put("user_id",userId).put("items",newItems);enqueueTx(db,"revision",newUuid,p);db.setTransactionSuccessful();return p.put("new_code",newCode).put("new_group",newGroup)}finally{db.endTransaction()} }
    @Synchronized fun returnLocal(group:String,reason:String,userId:Long):JSONObject {require(reason.isNotBlank()){"Alasan retur wajib diisi"};val h=history().firstOrNull{it.groupId==group}?:error("Transaksi tidak ditemukan");require(h.returnStatus!="returned"){"Transaksi sudah diretur"};val items=historyItems(group);val uuid=UUID.randomUUID().toString();val total=items.sumOf{it.total};val db=writableDatabase;db.beginTransaction();try{items.forEach{adjustStock(db,it.productId,it.qty,"sale_return",group,"Retur ${h.code}")};db.execSQL("UPDATE sales SET return_reason=?,returned_at=?,returned_by=?,return_status='returned' WHERE transaction_group_uuid=? AND is_active_revision=1",arrayOf(reason,now(),userId,group));db.insert("sales_returns",null,ContentValues().apply{put("offline_uuid",uuid);put("transaction_group_uuid",group);put("transaction_code",h.code);put("reason",reason);put("total_return",total);put("created_by",userId);put("created_at",now());put("sync_status","pending")});items.forEach{db.insert("sales_return_items",null,ContentValues().apply{put("return_offline_uuid",uuid);put("product_id",it.productId);put("qty",it.qty);put("price_each",it.price);put("subtotal",it.total)})};val p=JSONObject().put("offline_uuid",uuid).put("sale_code",h.code).put("transaction_group_uuid",group).put("reason",reason).put("user_id",userId);enqueueTx(db,"return",uuid,p);db.setTransactionSuccessful();return p}finally{db.endTransaction()} }
    private fun revisionSuffix(n0:Int):String{var n=n0;var s="";while(n>0){n--;s=(('A'.code+n%26).toChar())+s;n/=26};return s}


    data class Shift(val id:Long,val serverId:Long?,val code:String,val openedAt:String,val openingCash:Long,val status:String,val offlineOpenUuid:String){
        val effectiveId:Long get()=serverId?:id
    }
    @Synchronized fun activeShift():Shift?=readableDatabase.rawQuery("SELECT id,server_id,COALESCE(shift_code,''),COALESCE(opened_at,''),COALESCE(opening_cash_actual,0),status,COALESCE(offline_open_uuid,'') FROM pos_shifts WHERE status='open' ORDER BY CASE WHEN server_id IS NOT NULL THEN 0 ELSE 1 END,id DESC LIMIT 1",null).use{c->if(c.moveToFirst())Shift(c.getLong(0),if(c.isNull(1))null else c.getLong(1),c.getString(2),c.getString(3),c.getDouble(4).roundToLong(),c.getString(5),c.getString(6)) else null}
    @Synchronized fun openShift(userId:Long,openingCash:Long):Shift{activeShift()?.let{return it};val uuid=UUID.randomUUID().toString();val code="SHIFT-${SimpleDateFormat("yyyyMMdd-HHmmss",Locale.US).format(Date())}";val db=writableDatabase;val id=db.insert("pos_shifts",null,ContentValues().apply{put("shift_code",code);put("opened_at",now());put("opened_by",userId);put("opening_cash_actual",openingCash);put("status","open");put("offline_open_uuid",uuid);put("sync_status","pending")});enqueueTx(db,"shift_open",uuid,JSONObject().put("offline_uuid",uuid).put("status","open").put("opened_at",now()).put("opening_cash_actual",openingCash).put("user_id",userId));return Shift(id,null,code,now(),openingCash,"open",uuid)}
    @Synchronized fun closeShift(userId:Long,countedCash:Long):JSONObject{val sh=activeShift()?:error("Shift belum aktif");val uuid=UUID.randomUUID().toString();val p=JSONObject().put("offline_uuid",uuid).put("shift_id",sh.effectiveId).put("status","closed").put("closed_at",now()).put("counted_cash_total",countedCash).put("user_id",userId);writableDatabase.execSQL("UPDATE pos_shifts SET status='closed',closed_at=?,closed_by=?,counted_cash_total=?,offline_close_uuid=?,sync_status='pending' WHERE id=?",arrayOf(now(),userId,countedCash,uuid,sh.id));enqueueTx(writableDatabase,"shift_close",uuid,p);return p}

    @Synchronized fun recap(from:String="",to:String=""):Map<String,Long>{val rows=history(from,to);return rows.groupBy{it.payment+(if(it.bank.isBlank())"" else " / ${it.bank}")}.mapValues{e->e.value.filter{it.returnStatus!="returned"}.sumOf{it.total}}}
    @Synchronized fun customerRecap():List<JSONObject>{val o=mutableListOf<JSONObject>();readableDatabase.rawQuery("SELECT customer_name,customer_phone,COUNT(DISTINCT transaction_group_uuid),SUM(total),MAX(sold_at) FROM sales WHERE is_active_revision=1 AND TRIM(COALESCE(customer_name,''))<>'' GROUP BY customer_name,customer_phone ORDER BY customer_name",null).use{c->while(c.moveToNext())o+=JSONObject().put("name",c.getString(0)).put("phone",c.getString(1)?:"").put("transactions",c.getInt(2)).put("total",c.getDouble(3).roundToLong()).put("last",c.getString(4))};return o}
    @Synchronized fun orders():List<JSONObject>{val o=mutableListOf<JSONObject>();readableDatabase.rawQuery("SELECT id,order_code,status,created_at,customer_name,customer_contact,customer_address,customer_note,total_amount FROM orders ORDER BY created_at DESC",null).use{c->while(c.moveToNext())o+=JSONObject().put("id",c.getLong(0)).put("order_code",c.getString(1)).put("status",c.getString(2)).put("created_at",c.getString(3)).put("customer_name",c.getString(4)).put("customer_contact",c.getString(5)).put("customer_address",c.getString(6)).put("customer_note",c.getString(7)).put("total",c.getDouble(8).roundToLong())};return o}

    @Synchronized fun enqueue(entityType:String,payloadJson:String,offlineUuid:String?=null):JSONObject{val uuid=offlineUuid?.takeIf{it.isNotBlank()}?:UUID.randomUUID().toString();enqueueTx(writableDatabase,entityType,uuid,JSONObject(payloadJson));return JSONObject().put("offline_uuid",uuid).put("entity_type",entityType)}
    @Synchronized fun loadQueue():String{val a=JSONArray();readableDatabase.rawQuery("SELECT offline_uuid,entity_type,payload_json,sync_status,retry_count,last_error,created_at FROM sync_queue WHERE sync_status<>'synced' ORDER BY created_at",null).use{c->while(c.moveToNext())a.put(JSONObject().put("offline_uuid",c.getString(0)).put("entity_type",c.getString(1)).put("payload",JSONObject(c.getString(2))).put("sync_status",c.getString(3)).put("retry_count",c.getInt(4)).put("last_error",c.getString(5)?:"").put("created_at",c.getLong(6)))};return a.toString()}
    @Synchronized fun queueItems():List<JSONObject>{val a=JSONArray(loadQueue());return (0 until a.length()).map{a.getJSONObject(it)}}
    @Synchronized fun pendingSyncCount():Int=readableDatabase.rawQuery("SELECT COUNT(*) FROM sync_queue WHERE sync_status<>'synced'",null).use{if(it.moveToFirst())it.getInt(0) else 0}
    @Synchronized fun markQueue(uuid:String,status:String,error:String=""){val db=writableDatabase;if(status=="synced")db.delete("sync_queue","offline_uuid=?",arrayOf(uuid)) else db.execSQL("UPDATE sync_queue SET sync_status=?,retry_count=retry_count+1,last_error=?,updated_at=? WHERE offline_uuid=?",arrayOf(status,error,System.currentTimeMillis(),uuid));db.execSQL("UPDATE sales SET sync_status=?,sync_error=? WHERE transaction_group_uuid=(SELECT transaction_group_uuid FROM sales WHERE offline_uuid=? LIMIT 1)",arrayOf(status,error,uuid));db.execSQL("UPDATE sales_returns SET sync_status=?,sync_error=? WHERE offline_uuid=?",arrayOf(status,error,uuid));db.execSQL("UPDATE pos_shifts SET sync_status=? WHERE offline_open_uuid=? OR offline_close_uuid=?",arrayOf(status,uuid,uuid))}
    @Synchronized fun markImportedSync(uuid:String,transactionCode:String?=null){writableDatabase.execSQL("UPDATE sales SET sync_status='synced',transaction_code=COALESCE(NULLIF(?,''),transaction_code) WHERE offline_uuid=?",arrayOf(transactionCode?:"",uuid));markQueue(uuid,"synced")}
    @Synchronized fun buildPendingSalesPayload():JSONObject {
        val transactions=JSONArray()
        readableDatabase.rawQuery("SELECT payload_json FROM sync_queue WHERE entity_type='sale' AND sync_status<>'synced' ORDER BY created_at",null).use { c ->
            while(c.moveToNext()) runCatching { JSONObject(c.getString(0)) }.getOrNull()?.let { transactions.put(it) }
        }
        return JSONObject().put("transactions",transactions).put("shifts",JSONArray()).put("cash_movements",JSONArray())
    }
    private fun now():String=SimpleDateFormat("yyyy-MM-dd HH:mm:ss",Locale.US).format(Date())

    companion object{private const val DB_NAME="adena_pos_android_native_v1.db";private const val DB_VERSION=1}
}
