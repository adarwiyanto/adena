package id.co.adena.pos.ui
import android.content.Intent
import android.net.ConnectivityManager
import android.net.NetworkCapabilities
import android.os.Bundle
import android.view.View
import androidx.appcompat.app.AppCompatActivity
import androidx.lifecycle.lifecycleScope
import id.co.adena.pos.data.PosApiPrefs
import id.co.adena.pos.data.PosLocalStore
import id.co.adena.pos.databinding.ActivitySyncBinding
import id.co.adena.pos.network.PosApiClient
import kotlinx.coroutines.Dispatchers
import kotlinx.coroutines.launch
import kotlinx.coroutines.withContext
import org.json.JSONObject

class SyncActivity:AppCompatActivity(){private lateinit var b:ActivitySyncBinding;private lateinit var store:PosLocalStore;private lateinit var prefs:PosApiPrefs
 override fun onCreate(s:Bundle?){super.onCreate(s);b=ActivitySyncBinding.inflate(layoutInflater);setContentView(b.root);store=PosLocalStore(this);prefs=PosApiPrefs(this);b.continueOfflineButton.setOnClickListener{openPos()};startSync()}
 private fun startSync(){val count=store.productCount();b.syncProgress.progress=10;b.syncStatus.text="Menyiapkan POS";b.syncDetail.text="$count produk lokal"
   if(!isOnline()||intent.getBooleanExtra("offline_login",false)){if(count>0){b.syncProgress.progress=100;b.syncStatus.text="Mode offline siap";b.syncDetail.text="Menggunakan data sinkronisasi terakhir";b.continueOfflineButton.visibility=View.VISIBLE;openPosDelayed()}else fail("Sinkronisasi pertama memerlukan internet");return}
   lifecycleScope.launch{b.syncProgress.progress=25;b.syncStatus.text="Sinkronisasi master data...";val r=withContext(Dispatchers.IO){runCatching{PosApiClient(prefs).pullMaster()}.getOrElse{org.json.JSONObject().put("ok",false).put("message",it.message)}};if(!r.optBoolean("ok")){if(count>0){b.syncStatus.text="Server tidak tersedia — data lokal siap";b.syncDetail.text=r.optString("message");b.syncProgress.progress=100;b.continueOfflineButton.visibility=View.VISIBLE}else fail(r.optString("message","Sinkronisasi gagal"));return@launch};b.syncProgress.progress=65;b.syncStatus.text="Menyimpan produk, kategori, pembayaran, riwayat...";val payload=r.optJSONObject("data")?:r;val counts=withContext(Dispatchers.IO){store.saveMaster(payload)};b.syncProgress.progress=82;b.syncStatus.text="Mengirim transaksi tertunda...";syncPending();b.syncProgress.progress=100;b.syncStatus.text="Sinkronisasi selesai";b.syncDetail.text="${counts.optInt("products")} produk • ${counts.optInt("categories")} kategori • ${counts.optInt("history")} riwayat";openPosDelayed()}
 }
 private suspend fun syncPending(){withContext(Dispatchers.IO){
   val api=PosApiClient(prefs)
   // A sale can reference a locally opened shift by offline UUID. Create that shift on server first,
   // so /api/sync/push.php can resolve shift_offline_uuid to the correct pos_shifts.id.
   store.queueItems().filter{it.optString("entity_type")=="shift_open"}.forEach{q->
     val u=q.optString("offline_uuid");val p=q.optJSONObject("payload")?:return@forEach
     val res=runCatching{api.shift(JSONObject(p.toString()).put("action","open"))}.getOrNull()
     if(res?.optBoolean("ok")==true)store.markQueue(u,"synced") else if(res!=null)store.markQueue(u,"failed",res.optString("message"))
   }
   val sales=store.buildPendingSalesPayload()
   if((sales.optJSONArray("transactions")?.length() ?: 0) > 0){
     runCatching{api.push(sales)}.getOrNull()?.let{res->
       val results=res.optJSONObject("results")?.optJSONObject("transactions")
       if(results!=null){val it=results.keys();while(it.hasNext()){val u=it.next();val row=results.optJSONObject(u);if(row?.optString("status") in listOf("inserted","exists"))store.markImportedSync(u,row?.optString("transaction_code"))}}
     }
   }
   // Revisions/returns operate on the synced sale; close shift last so all sales are counted first.
   store.queueItems().filter{it.optString("entity_type") in listOf("revision","return","shift_close")}.forEach{q->
     val u=q.optString("offline_uuid");val p=q.optJSONObject("payload")?:return@forEach;val type=q.optString("entity_type")
     val res=runCatching{when(type){"revision"->api.revise(p);"return"->api.returnSale(p);else->api.shift(JSONObject(p.toString()).put("action","close"))}}.getOrNull()
     if(res?.optBoolean("ok")==true)store.markQueue(u,"synced") else if(res!=null)store.markQueue(u,"failed",res.optString("message"))
   }
 }}
 private fun openPosDelayed(){b.root.postDelayed({openPos()},450)};private fun openPos(){if(store.productCount()<=0)return;startActivity(Intent(this,MainActivity::class.java).addFlags(Intent.FLAG_ACTIVITY_CLEAR_TOP));finish()};private fun fail(m:String){b.syncProgress.progress=0;b.syncStatus.text="Sinkronisasi gagal";b.syncDetail.text=m};private fun isOnline():Boolean{val cm=getSystemService(ConnectivityManager::class.java);val n=cm.activeNetwork?:return false;return cm.getNetworkCapabilities(n)?.hasCapability(NetworkCapabilities.NET_CAPABILITY_INTERNET)==true}}
