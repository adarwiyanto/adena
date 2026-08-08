package id.co.adena.pos.ui

import android.Manifest
import android.content.Intent
import android.content.pm.PackageManager
import android.graphics.Color
import android.graphics.Typeface
import android.graphics.drawable.GradientDrawable
import android.os.Build
import android.os.Bundle
import android.text.Editable
import android.text.TextWatcher
import android.view.Gravity
import android.view.View
import android.view.ViewGroup
import android.widget.Button
import android.widget.LinearLayout
import android.widget.TextView
import android.widget.Toast
import androidx.activity.OnBackPressedCallback
import androidx.activity.result.contract.ActivityResultContracts
import androidx.appcompat.app.AlertDialog
import androidx.appcompat.app.AppCompatActivity
import androidx.core.content.ContextCompat
import androidx.lifecycle.lifecycleScope
import id.co.adena.pos.bluetooth.BluetoothPrinterManager
import id.co.adena.pos.bluetooth.EscPosFormatter
import id.co.adena.pos.data.PosLocalStore
import id.co.adena.pos.data.PrinterPrefs
import id.co.adena.pos.data.model.ReceiptItem
import id.co.adena.pos.data.model.ReceiptPayload
import id.co.adena.pos.databinding.ActivityMainBinding
import id.co.adena.pos.kiosk.KioskPrefs
import kotlinx.coroutines.launch
import org.json.JSONArray
import org.json.JSONObject
import java.text.NumberFormat
import java.text.SimpleDateFormat
import java.util.Date
import java.util.Locale
import kotlin.math.ceil

/** Pure native/offline POS runtime. No WebView and no HTTP request is used here. */
class MainActivity : AppCompatActivity() {
    private lateinit var binding: ActivityMainBinding
    private lateinit var store: PosLocalStore
    private lateinit var printerPrefs: PrinterPrefs
    private lateinit var printerManager: BluetoothPrinterManager
    private lateinit var kioskPrefs: KioskPrefs

    private data class CartLine(val product: PosLocalStore.Product, var qty: Double = 1.0)
    private val cart = linkedMapOf<Long, CartLine>()
    private var selectedCategory: String? = null
    private var searchQuery: String = ""
    private var pendingPrint: ReceiptPayload? = null

    private val bluetoothPermissionLauncher = registerForActivityResult(ActivityResultContracts.RequestPermission()) { granted ->
        if (granted) pendingPrint?.let { printReceipt(it) }
        else toast("Izin Bluetooth diperlukan untuk mencetak.")
        pendingPrint = null
    }

    override fun onCreate(savedInstanceState: Bundle?) {
        super.onCreate(savedInstanceState)
        kioskPrefs = KioskPrefs(this)
        if (!kioskPrefs.isSetupComplete()) {
            startActivity(Intent(this, KioskSetupActivity::class.java)); finish(); return
        }
        binding = ActivityMainBinding.inflate(layoutInflater)
        setContentView(binding.root)
        store = PosLocalStore(applicationContext)
        printerPrefs = PrinterPrefs(this)
        printerManager = BluetoothPrinterManager(this)

        if (store.productCount() <= 0) {
            startActivity(Intent(this, SyncActivity::class.java)); finish(); return
        }

        binding.searchInput.addTextChangedListener(object : TextWatcher {
            override fun beforeTextChanged(s: CharSequence?, start: Int, count: Int, after: Int) = Unit
            override fun onTextChanged(s: CharSequence?, start: Int, before: Int, count: Int) {
                searchQuery = s?.toString().orEmpty(); renderProducts()
            }
            override fun afterTextChanged(s: Editable?) = Unit
        })
        binding.syncButton.setOnClickListener { startActivity(Intent(this, SyncActivity::class.java)) }
        binding.payButton.setOnClickListener { showPaymentDialog() }

        renderCategories(); renderProducts(); renderCart(); updateOfflineStatus()

        onBackPressedDispatcher.addCallback(this, object : OnBackPressedCallback(true) {
            override fun handleOnBackPressed() {
                if (cart.isNotEmpty()) {
                    AlertDialog.Builder(this@MainActivity).setTitle("Keluar POS?")
                        .setMessage("Keranjang saat ini akan dikosongkan.")
                        .setPositiveButton("Keluar") { _, _ -> finish() }
                        .setNegativeButton("Batal", null).show()
                } else finish()
            }
        })
    }

    override fun onResume() { super.onResume(); if (::binding.isInitialized) updateOfflineStatus() }

    private fun updateOfflineStatus() {
        binding.offlineStatus.text = "● OFFLINE READY  •  ${store.productCount()} produk  •  ${store.pendingSyncCount()} pending"
    }

    private fun renderCategories() {
        binding.categoryContainer.removeAllViews()
        binding.categoryContainer.addView(categoryButton("Semua", null))
        store.getCategories().forEach { binding.categoryContainer.addView(categoryButton(it, it)) }
    }

    private fun categoryButton(label: String, value: String?): Button = Button(this).apply {
        text = label; isAllCaps = false
        setPadding(dp(14), 0, dp(14), 0)
        setOnClickListener { selectedCategory = value; renderCategories(); renderProducts() }
        alpha = if (selectedCategory == value) 1f else 0.72f
        setTypeface(typeface, if (selectedCategory == value) Typeface.BOLD else Typeface.NORMAL)
    }

    private fun renderProducts() {
        binding.productGrid.removeAllViews()
        val products = store.getProducts(searchQuery, selectedCategory)
        if (products.isEmpty()) {
            val empty = TextView(this).apply { text="Produk tidak ditemukan"; setPadding(dp(18),dp(28),dp(18),dp(28)); textSize=17f }
            binding.productGrid.addView(empty)
            return
        }
        products.forEach { p ->
            val stockText = when {
                !p.trackStock -> ""
                p.currentStock == null -> ""
                else -> "\nStok ${trimQty(p.currentStock)}"
            }
            val button = Button(this).apply {
                text = "${p.name}\n${money(p.price)}$stockText"
                isAllCaps = false
                gravity = Gravity.CENTER
                minHeight = dp(112)
                setTextColor(Color.rgb(57,36,23))
                background = rounded(Color.WHITE, 14)
                setOnClickListener { addProduct(p) }
            }
            val lp = android.widget.GridLayout.LayoutParams().apply {
                width = 0; height = dp(126); columnSpec = android.widget.GridLayout.spec(android.widget.GridLayout.UNDEFINED, 1f)
                setMargins(dp(5),dp(5),dp(5),dp(5))
            }
            binding.productGrid.addView(button, lp)
        }
    }

    private fun addProduct(product: PosLocalStore.Product) {
        val current = cart[product.id]?.qty ?: 0.0
        if (product.trackStock && product.currentStock != null && current + 1.0 > product.currentStock + 0.0001) {
            toast("Stok ${product.name} tidak mencukupi."); return
        }
        val existing = cart[product.id]
        if (existing == null) cart[product.id] = CartLine(product, 1.0)
        else existing.qty = current + 1.0
        renderCart()
    }

    private fun renderCart() {
        binding.cartContainer.removeAllViews()
        binding.cartEmpty.visibility = if (cart.isEmpty()) View.VISIBLE else View.GONE
        cart.values.forEach { line -> binding.cartContainer.addView(cartRow(line)) }
        val qty = cart.values.sumOf { it.qty }
        val total = cartTotal()
        binding.cartItemCount.text = "${trimQty(qty)} item"
        binding.totalText.text = money(total)
        binding.payButton.isEnabled = cart.isNotEmpty()
    }

    private fun cartRow(line: CartLine): View {
        val wrapper = LinearLayout(this).apply {
            orientation = LinearLayout.VERTICAL; setPadding(dp(4),dp(8),dp(4),dp(8))
            background = GradientDrawable().apply { setColor(Color.WHITE); setStroke(dp(1),Color.rgb(232,224,216)) }
        }
        wrapper.addView(TextView(this).apply { text=line.product.name; textSize=16f; setTypeface(typeface,Typeface.BOLD); setTextColor(Color.rgb(57,36,23)) })
        val controls = LinearLayout(this).apply { orientation=LinearLayout.HORIZONTAL; gravity=Gravity.CENTER_VERTICAL }
        val minus=Button(this).apply { text="−"; minWidth=dp(44); setOnClickListener { changeQty(line,-1.0) } }
        val qty=TextView(this).apply { text=trimQty(line.qty); gravity=Gravity.CENTER; textSize=17f; layoutParams=LinearLayout.LayoutParams(dp(48),ViewGroup.LayoutParams.WRAP_CONTENT) }
        val plus=Button(this).apply { text="+"; minWidth=dp(44); setOnClickListener { changeQty(line,1.0) } }
        val subtotal=TextView(this).apply {
            text=money((line.product.price*line.qty).toLong()); gravity=Gravity.END; textSize=16f; setTypeface(typeface,Typeface.BOLD)
            layoutParams=LinearLayout.LayoutParams(0,ViewGroup.LayoutParams.WRAP_CONTENT,1f)
        }
        controls.addView(minus); controls.addView(qty); controls.addView(plus); controls.addView(subtotal); wrapper.addView(controls)
        return wrapper
    }

    private fun changeQty(line: CartLine, delta: Double) {
        val next=line.qty+delta
        if(next <= 0) cart.remove(line.product.id)
        else {
            if(line.product.trackStock && line.product.currentStock != null && next > line.product.currentStock + 0.0001) { toast("Stok tidak mencukupi."); return }
            line.qty=next
        }
        renderCart()
    }

    private fun showPaymentDialog() {
        if(cart.isEmpty()) return
        val methods=arrayOf("Cash","Non-Cash")
        AlertDialog.Builder(this).setTitle("Pilih Pembayaran • ${money(cartTotal())}")
            .setItems(methods) { _, which -> if(which==0) showQuickCashDialog() else completePayment("non-cash",cartTotal()) }.show()
    }

    /** Dynamic quick cash: exact amount + rounded useful denominations. */
    private fun showQuickCashDialog() {
        val total=cartTotal()
        val amounts = quickCashAmounts(total)
        val labels = mutableListOf("Uang Pas")
        labels += amounts.filter { it != total }.map { money(it) }
        labels += "Nominal Lain"
        AlertDialog.Builder(this).setTitle("Cash • Total ${money(total)}")
            .setItems(labels.toTypedArray()) { _, index ->
                when {
                    index==0 -> confirmCash(total)
                    index==labels.lastIndex -> showCustomCashDialog(total)
                    else -> confirmCash(amounts.filter { it != total }[index-1])
                }
            }.show()
    }

    private fun quickCashAmounts(total: Long): List<Long> {
        val candidates=linkedSetOf(total)
        listOf(1_000L,5_000L,10_000L,20_000L,50_000L,100_000L).forEach { step ->
            val rounded = ceil(total.toDouble()/step).toLong()*step
            if(rounded>=total) candidates += rounded
        }
        listOf(50_000L,100_000L,150_000L,200_000L,300_000L,500_000L,1_000_000L).filter { it>=total }.take(4).forEach { candidates += it }
        return candidates.sorted().take(7)
    }

    private fun showCustomCashDialog(total: Long) {
        val input=android.widget.EditText(this).apply { inputType=android.text.InputType.TYPE_CLASS_NUMBER; hint="Nominal diterima"; setPadding(dp(24),dp(10),dp(24),dp(10)) }
        AlertDialog.Builder(this).setTitle("Nominal Cash").setView(input).setPositiveButton("Lanjut") { _, _ ->
            val paid=input.text?.toString()?.replace(".","")?.replace(",","")?.toLongOrNull() ?: 0L
            if(paid<total) toast("Nominal kurang dari total.") else confirmCash(paid)
        }.setNegativeButton("Batal",null).show()
    }

    private fun confirmCash(paid: Long) {
        val total=cartTotal(); val change=paid-total
        AlertDialog.Builder(this).setTitle("Konfirmasi Cash")
            .setMessage("Total: ${money(total)}\nDiterima: ${money(paid)}\nKembalian: ${money(change)}")
            .setPositiveButton("BAYAR & CETAK") { _, _ -> completePayment("cash",paid) }
            .setNegativeButton("Batal",null).show()
    }

    private fun completePayment(method: String, paid: Long) {
        val total=cartTotal(); if(paid<total) return
        val receiptId="LOC-${SimpleDateFormat("yyyyMMdd-HHmmss",Locale.US).format(Date())}"
        val itemsJson=JSONArray()
        val receiptItems=cart.values.map { line ->
            val subtotal=(line.product.price*line.qty).toLong()
            itemsJson.put(JSONObject().apply { put("product_id",line.product.id); put("name",line.product.name); put("qty",line.qty); put("price",line.product.price); put("subtotal",subtotal) })
            ReceiptItem(line.product.name,line.qty,line.product.price,subtotal)
        }
        runCatching { store.commitSale(receiptId,total,method,paid,paid-total,itemsJson) }
            .onFailure { toast("Transaksi gagal disimpan: ${it.message}"); return }
        val payload=ReceiptPayload(
            documentType="receipt", receiptId=receiptId, tanggalJam=SimpleDateFormat("dd-MM-yyyy HH:mm:ss",Locale.getDefault()).format(Date()),
            cashier="Kasir", storeName="Adena", storeSubtitle="POS Offline", storeAddress="", storePhone="", footer="Terima kasih",
            logoUrl="", paymentMethod=method, total=total, bayar=paid, kembalian=paid-total, paperWidth=58,
            reportTitle="", periodLabel="", summaryLines=emptyList(), items=receiptItems
        )
        cart.clear(); renderCart(); renderProducts(); updateOfflineStatus()
        toast("Transaksi $receiptId tersimpan offline.")
        maybePrint(payload)
    }

    private fun maybePrint(payload: ReceiptPayload) {
        val mac=printerPrefs.getPrinterMac()
        if(mac.isNullOrBlank()) { toast("Printer belum dipilih. Transaksi tetap tersimpan."); return }
        if(Build.VERSION.SDK_INT>=Build.VERSION_CODES.S && ContextCompat.checkSelfPermission(this,Manifest.permission.BLUETOOTH_CONNECT)!=PackageManager.PERMISSION_GRANTED) {
            pendingPrint=payload; bluetoothPermissionLauncher.launch(Manifest.permission.BLUETOOTH_CONNECT); return
        }
        printReceipt(payload)
    }

    private fun printReceipt(payload: ReceiptPayload) {
        val mac=printerPrefs.getPrinterMac() ?: return
        val bytes=EscPosFormatter.formatReceipt(payload,null)
        lifecycleScope.launch {
            runCatching { printerManager.print(mac,bytes) }
                .onSuccess { toast("Struk tercetak.") }
                .onFailure { toast("Print gagal: ${it.message}. Transaksi tetap aman tersimpan.") }
        }
    }

    private fun cartTotal(): Long = cart.values.sumOf { (it.product.price*it.qty).toLong() }
    private fun money(v: Long): String = "Rp"+NumberFormat.getNumberInstance(Locale("in","ID")).format(v)
    private fun trimQty(v: Double): String = if(v==v.toLong().toDouble()) v.toLong().toString() else String.format(Locale.US,"%.2f",v)
    private fun toast(v:String)=Toast.makeText(this,v,Toast.LENGTH_SHORT).show()
    private fun dp(v:Int)=(v*resources.displayMetrics.density).toInt()
    private fun rounded(color:Int,radius:Int)=GradientDrawable().apply { setColor(color); cornerRadius=dp(radius).toFloat(); setStroke(dp(1),Color.rgb(224,214,205)) }
}
