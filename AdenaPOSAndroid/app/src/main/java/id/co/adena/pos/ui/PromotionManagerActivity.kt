package id.co.adena.pos.ui

import android.content.Intent
import android.graphics.Typeface
import android.net.Uri
import android.os.Bundle
import android.view.Gravity
import android.view.ViewGroup
import android.widget.*
import androidx.activity.OnBackPressedCallback
import androidx.activity.result.contract.ActivityResultContracts
import androidx.appcompat.app.AlertDialog
import androidx.appcompat.app.AppCompatActivity
import id.co.adena.pos.data.PromotionStore

class PromotionManagerActivity : AppCompatActivity() {
    private lateinit var store: PromotionStore
    private lateinit var list: LinearLayout
    private val picker = registerForActivityResult(ActivityResultContracts.OpenMultipleDocuments()) { uris ->
        var added = 0
        uris.forEach { uri -> if (store.add(uri)) added++ }
        Toast.makeText(this, "$added gambar ditambahkan", Toast.LENGTH_SHORT).show(); render()
    }

    override fun onCreate(savedInstanceState: Bundle?) {
        super.onCreate(savedInstanceState); store = PromotionStore(this)
        window.addFlags(android.view.WindowManager.LayoutParams.FLAG_KEEP_SCREEN_ON)
        val root = LinearLayout(this).apply { orientation = LinearLayout.VERTICAL; setPadding(dp(24), dp(20), dp(24), dp(20)) }
        root.addView(TextView(this).apply { text = "Manager Promosi"; textSize = 27f; setTypeface(typeface, Typeface.BOLD) })
        root.addView(TextView(this).apply { text = "Tambah, hapus, dan atur urutan gambar slideshow."; textSize = 14f; setPadding(0,dp(4),0,dp(12)) })
        root.addView(Button(this).apply { text = "+ Tambah Gambar"; isAllCaps = false; setOnClickListener { picker.launch(arrayOf("image/*")) } })
        val scroll = ScrollView(this); list = LinearLayout(this).apply { orientation = LinearLayout.VERTICAL }; scroll.addView(list)
        root.addView(scroll, LinearLayout.LayoutParams(ViewGroup.LayoutParams.MATCH_PARENT,0,1f))
        root.addView(Button(this).apply { text = "Kembali"; isAllCaps = false; setOnClickListener { finish() } })
        setContentView(root); render()
        onBackPressedDispatcher.addCallback(this, object:OnBackPressedCallback(true){override fun handleOnBackPressed(){finish()}})
    }

    private fun render() {
        list.removeAllViews(); val items=store.items()
        if(items.isEmpty()){ list.addView(TextView(this).apply{text="Belum ada gambar promosi.";textSize=17f;gravity=Gravity.CENTER;setPadding(0,dp(40),0,dp(40))});return }
        items.forEachIndexed { i,item ->
            val row=LinearLayout(this).apply{orientation=LinearLayout.HORIZONTAL;gravity=Gravity.CENTER_VERTICAL;setPadding(0,dp(8),0,dp(8))}
            row.addView(ImageView(this).apply{setImageURI(Uri.fromFile(store.file(item)));scaleType=ImageView.ScaleType.CENTER_CROP},LinearLayout.LayoutParams(dp(150),dp(90)))
            row.addView(TextView(this).apply{text="${i+1}";textSize=20f;gravity=Gravity.CENTER},LinearLayout.LayoutParams(dp(60),ViewGroup.LayoutParams.WRAP_CONTENT))
            row.addView(Button(this).apply{text="↑";isEnabled=i>0;setOnClickListener{store.move(item.id,-1);render()}})
            row.addView(Button(this).apply{text="↓";isEnabled=i<items.lastIndex;setOnClickListener{store.move(item.id,1);render()}})
            row.addView(Button(this).apply{text="Hapus";isAllCaps=false;setOnClickListener{AlertDialog.Builder(this@PromotionManagerActivity).setTitle("Hapus gambar?").setMessage("Gambar nomor ${i+1} akan dihapus dari slideshow.").setPositiveButton("Hapus"){_,_->store.remove(item.id);render()}.setNegativeButton("Batal",null).show()}})
            list.addView(row,LinearLayout.LayoutParams(ViewGroup.LayoutParams.MATCH_PARENT,ViewGroup.LayoutParams.WRAP_CONTENT))
        }
    }
    private fun dp(v:Int)=(v*resources.displayMetrics.density).toInt()
}
