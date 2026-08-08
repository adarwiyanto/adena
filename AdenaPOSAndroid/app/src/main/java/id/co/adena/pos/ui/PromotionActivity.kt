package id.co.adena.pos.ui

import android.content.Intent
import android.graphics.Color
import android.os.Bundle
import android.os.Handler
import android.os.Looper
import android.view.Gravity
import android.view.View
import android.view.ViewGroup
import android.view.WindowManager
import android.widget.Button
import android.widget.FrameLayout
import android.widget.ImageView
import android.widget.TextView
import androidx.activity.OnBackPressedCallback
import androidx.appcompat.app.AppCompatActivity
import androidx.core.view.WindowCompat
import androidx.core.view.WindowInsetsCompat
import androidx.core.view.WindowInsetsControllerCompat
import id.co.adena.pos.data.PromotionStore

class PromotionActivity : AppCompatActivity() {
    private lateinit var store: PromotionStore
    private lateinit var image: ImageView
    private lateinit var empty: TextView
    private lateinit var manageButton: Button
    private val handler = Handler(Looper.getMainLooper())
    private var index = 0

    private val next = object : Runnable {
        override fun run() {
            showNext()
            handler.postDelayed(this, SLIDE_INTERVAL_MS)
        }
    }

    private val hideControls = Runnable {
        if (store.items().isNotEmpty()) manageButton.visibility = View.GONE
    }

    override fun onCreate(savedInstanceState: Bundle?) {
        super.onCreate(savedInstanceState)
        store = PromotionStore(this)

        // Slideshow promosi harus tetap menyala selama activity aktif.
        window.addFlags(WindowManager.LayoutParams.FLAG_KEEP_SCREEN_ON)
        immersive()

        val root = FrameLayout(this).apply {
            setBackgroundColor(Color.BLACK)
            setOnClickListener { showManageControl() }
        }

        image = ImageView(this).apply {
            scaleType = ImageView.ScaleType.FIT_CENTER
            setBackgroundColor(Color.BLACK)
            isClickable = false
        }

        empty = TextView(this).apply {
            text = "Belum ada gambar promosi\n\nKetuk layar untuk menambahkan gambar"
            textSize = 24f
            setTextColor(Color.WHITE)
            gravity = Gravity.CENTER
            isClickable = false
        }

        manageButton = Button(this).apply {
            text = "Kelola Gambar"
            isAllCaps = false
            textSize = 16f
            visibility = View.GONE
            setOnClickListener {
                handler.removeCallbacks(hideControls)
                startActivity(Intent(this@PromotionActivity, PromotionManagerActivity::class.java))
            }
        }

        root.addView(
            image,
            FrameLayout.LayoutParams(
                ViewGroup.LayoutParams.MATCH_PARENT,
                ViewGroup.LayoutParams.MATCH_PARENT,
            ),
        )
        root.addView(
            empty,
            FrameLayout.LayoutParams(
                ViewGroup.LayoutParams.MATCH_PARENT,
                ViewGroup.LayoutParams.MATCH_PARENT,
            ),
        )
        root.addView(
            manageButton,
            FrameLayout.LayoutParams(
                ViewGroup.LayoutParams.WRAP_CONTENT,
                ViewGroup.LayoutParams.WRAP_CONTENT,
                Gravity.TOP or Gravity.END,
            ).apply {
                topMargin = dp(24)
                marginEnd = dp(24)
            },
        )

        setContentView(root)
        onBackPressedDispatcher.addCallback(this, object : OnBackPressedCallback(true) {
            override fun handleOnBackPressed() {
                finish()
            }
        })
    }

    override fun onResume() {
        super.onResume()
        immersive()
        index = 0
        handler.removeCallbacks(next)
        handler.removeCallbacks(hideControls)
        showNext()
        handler.postDelayed(next, SLIDE_INTERVAL_MS)

        // Saat belum ada gambar, tombol pengelolaan langsung terlihat agar mudah ditemukan.
        if (store.items().isEmpty()) {
            manageButton.visibility = View.VISIBLE
        } else {
            manageButton.visibility = View.GONE
        }
    }

    override fun onPause() {
        handler.removeCallbacks(next)
        handler.removeCallbacks(hideControls)
        super.onPause()
    }

    override fun onWindowFocusChanged(hasFocus: Boolean) {
        super.onWindowFocusChanged(hasFocus)
        if (hasFocus) immersive()
    }

    private fun showNext() {
        val items = store.items()
        if (items.isEmpty()) {
            image.setImageDrawable(null)
            empty.visibility = View.VISIBLE
            manageButton.visibility = View.VISIBLE
            return
        }

        empty.visibility = View.GONE
        if (index >= items.size) index = 0
        image.setImageURI(android.net.Uri.fromFile(store.file(items[index])))
        index = (index + 1) % items.size
    }

    private fun showManageControl() {
        manageButton.visibility = View.VISIBLE
        handler.removeCallbacks(hideControls)
        if (store.items().isNotEmpty()) {
            handler.postDelayed(hideControls, CONTROL_HIDE_DELAY_MS)
        }
    }

    private fun immersive() {
        WindowCompat.setDecorFitsSystemWindows(window, false)
        WindowInsetsControllerCompat(window, window.decorView).apply {
            hide(WindowInsetsCompat.Type.systemBars())
            systemBarsBehavior = WindowInsetsControllerCompat.BEHAVIOR_SHOW_TRANSIENT_BARS_BY_SWIPE
        }
    }

    private fun dp(value: Int): Int = (value * resources.displayMetrics.density).toInt()

    companion object {
        private const val SLIDE_INTERVAL_MS = 5_000L
        private const val CONTROL_HIDE_DELAY_MS = 4_000L
    }
}
