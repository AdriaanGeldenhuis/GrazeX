package za.co.grazex.app

import android.Manifest
import android.annotation.SuppressLint
import android.content.ActivityNotFoundException
import android.content.Intent
import android.content.pm.PackageManager
import android.graphics.Color
import android.net.Uri
import android.os.Build
import android.os.Bundle
import android.os.Handler
import android.os.Looper
import android.os.SystemClock
import android.provider.MediaStore
import android.util.Base64
import android.view.View
import android.view.ViewGroup
import android.webkit.CookieManager
import android.webkit.GeolocationPermissions
import android.webkit.ValueCallback
import android.webkit.WebChromeClient
import android.webkit.WebSettings
import android.webkit.WebView
import android.widget.Button
import android.widget.FrameLayout
import android.widget.ProgressBar
import android.widget.Toast
import androidx.activity.OnBackPressedCallback
import androidx.activity.SystemBarStyle
import androidx.activity.enableEdgeToEdge
import androidx.activity.result.contract.ActivityResultContracts
import androidx.appcompat.app.AppCompatActivity
import androidx.core.app.NotificationManagerCompat
import androidx.core.content.ContextCompat
import androidx.core.content.FileProvider
import androidx.core.content.edit
import androidx.core.net.toUri
import androidx.core.view.ViewCompat
import androidx.core.view.WindowCompat
import androidx.core.view.WindowInsetsCompat
import androidx.core.view.WindowInsetsControllerCompat
import androidx.core.view.isVisible
import androidx.swiperefreshlayout.widget.SwipeRefreshLayout
import androidx.webkit.WebSettingsCompat
import androidx.webkit.WebViewCompat
import androidx.webkit.WebViewFeature
import com.google.firebase.messaging.FirebaseMessaging
import org.json.JSONObject
import za.co.grazex.app.push.PushSupport
import java.io.File
import java.net.URISyntaxException
import java.util.concurrent.Executors
import kotlin.math.roundToInt

/**
 * The GrazeX app: the website in a WebView, plus what a website can't do on its own — push notifications, saving
 * documents to the phone, links that open the app, the back button and pull-to-refresh.
 */
class MainActivity : AppCompatActivity() {

    companion object {
        /** A page of the site to open (from a notification). */
        const val EXTRA_URL = "za.co.grazex.app.URL"
        private const val PREFS = "grazex"
        private const val PREF_ASKED_NOTIFICATIONS = "asked_notifications"
        private const val MAX_FILE_BYTES = 25 * 1024 * 1024
        private const val STATE_RESTART_URL = "restart_url"
        private const val STATE_PAYING = "paying"

        /** When the page renderer last died (process-wide), so a page that keeps crashing it doesn't loop. */
        private var lastRendererCrash = 0L
    }

    lateinit var policy: UrlPolicy
        private set

    /** Went to the card payment pages and not back on the site yet (see UrlPolicy.decide). */
    var paying = false

    private lateinit var webView: WebView
    private lateinit var swipe: SwipeRefreshLayout
    private lateinit var progress: ProgressBar
    private lateinit var offline: View
    private lateinit var fullscreen: FrameLayout
    private var bridge: NativeBridge? = null
    private var rendererGone = false
    private var restartUrl: String? = null
    private var mainFrameError = false
    private val main = Handler(Looper.getMainLooper())
    private val io = Executors.newSingleThreadExecutor()

    // What the page reported (app-bridge.js): a dialog / drawer / menu is open, pull-to-refresh is allowed
    private var pageOverlay = false
    private var pageAllowsRefresh = true
    private var backPending = false
    private val backTimeout = Runnable {
        if (backPending) {
            backPending = false
            pageOverlay = false
            goBackOrLeave()
        }
    }
    private val backCallback = object : OnBackPressedCallback(false) {
        override fun handleOnBackPressed() = onBack()
    }

    // Full-screen video
    private var customView: View? = null
    private var customViewCallback: WebChromeClient.CustomViewCallback? = null

    // Uploads (proof of payment, product photos)
    private var fileCallback: ValueCallback<Array<Uri>>? = null
    private var cameraFile: File? = null
    private var cameraUri: Uri? = null
    private val chooseFile = registerForActivityResult(ActivityResultContracts.StartActivityForResult()) { result ->
        onFileChosen(result.resultCode, result.data)
    }

    // Push notifications: the permission is asked once, after logging in
    private val pendingTokenIds = mutableListOf<String>()
    private val askNotifications = registerForActivityResult(ActivityResultContracts.RequestPermission()) { granted ->
        val ids = pendingTokenIds.toList()
        pendingTokenIds.clear()
        ids.forEach { if (granted) fetchPushToken(it) else replyPush(it, null, "denied") }
    }

    // "Use my location" on the delivery form
    private var geoOrigin: String? = null
    private var geoCallback: GeolocationPermissions.Callback? = null
    private val askLocation = registerForActivityResult(ActivityResultContracts.RequestMultiplePermissions()) { result ->
        geoCallback?.invoke(geoOrigin, result.values.any { it }, false)
        geoCallback = null
        geoOrigin = null
    }

    override fun onCreate(savedInstanceState: Bundle?) {
        enableEdgeToEdge(SystemBarStyle.dark(Color.TRANSPARENT), SystemBarStyle.dark(Color.TRANSPARENT))
        super.onCreate(savedInstanceState)
        setContentView(R.layout.activity_main)
        policy = UrlPolicy(BuildConfig.BASE_URL)

        // The page sits between the status bar, the navigation bar, a notch and the keyboard
        ViewCompat.setOnApplyWindowInsetsListener(findViewById(R.id.root)) { v, insets ->
            val bars = insets.getInsets(
                WindowInsetsCompat.Type.systemBars() or WindowInsetsCompat.Type.displayCutout() or WindowInsetsCompat.Type.ime(),
            )
            v.setPadding(bars.left, bars.top, bars.right, bars.bottom)
            WindowInsetsCompat.CONSUMED
        }
        webView = findViewById(R.id.web)
        swipe = findViewById(R.id.swipe)
        progress = findViewById(R.id.progress)
        offline = findViewById(R.id.offline)
        fullscreen = findViewById(R.id.fullscreen)
        findViewById<Button>(R.id.retry).setOnClickListener {
            offline.isVisible = false
            if (webView.url.isNullOrEmpty()) webView.loadUrl(policy.home) else webView.reload()
        }
        setUpWebView()
        onBackPressedDispatcher.addCallback(this, backCallback)

        paying = savedInstanceState?.getBoolean(STATE_PAYING) ?: false
        // After the page renderer died the WebView state is gone: start again on the page it was showing
        val restart = savedInstanceState?.getString(STATE_RESTART_URL)
        val restored = savedInstanceState != null && restart == null && webView.restoreState(savedInstanceState) != null
        if (!restored) webView.loadUrl(restart?.let { policy.deepLink(it) } ?: urlFromIntent(intent) ?: policy.home)
    }

    @SuppressLint("SetJavaScriptEnabled")
    private fun setUpWebView() {
        WebView.setWebContentsDebuggingEnabled(BuildConfig.DEBUG)
        webView.settings.apply {
            javaScriptEnabled = true
            domStorageEnabled = true
            allowFileAccess = false
            allowContentAccess = false
            mixedContentMode = WebSettings.MIXED_CONTENT_NEVER_ALLOW
            setSupportMultipleWindows(false) // target=_blank opens here; UrlPolicy decides what leaves the app
            javaScriptCanOpenWindowsAutomatically = false
            mediaPlaybackRequiresUserGesture = true
            safeBrowsingEnabled = true
            setGeolocationEnabled(true)
            // Follow the phone's text size (accessibility)
            textZoom = (resources.configuration.fontScale * 100).roundToInt().coerceIn(85, 200)
            // The site recognises the app by this (looks and defaults only — it never grants anything)
            userAgentString = "$userAgentString GrazeXApp/${BuildConfig.VERSION_NAME}"
        }
        // The site is dark already: never let the WebView darken it again
        if (WebViewFeature.isFeatureSupported(WebViewFeature.ALGORITHMIC_DARKENING)) {
            WebSettingsCompat.setAlgorithmicDarkeningAllowed(webView.settings, false)
        }
        CookieManager.getInstance().apply {
            setAcceptCookie(true)
            // Card payments: the bank's 3-D Secure check can run in a frame on the payment page
            setAcceptThirdPartyCookies(webView, true)
        }
        webView.webViewClient = SiteWebViewClient(this)
        webView.webChromeClient = SiteChromeClient(this)
        webView.setDownloadListener(DownloadFallback(this))
        // The page ↔ app bridge, for the site's own pages only (needs WebView 82+; without it the app still works)
        if (WebViewFeature.isFeatureSupported(WebViewFeature.WEB_MESSAGE_LISTENER)) {
            val b = NativeBridge(this)
            WebViewCompat.addWebMessageListener(webView, "GXNative", policy.bridgeOrigins, b)
            bridge = b
        }
        swipe.setColorSchemeColors(ContextCompat.getColor(this, R.color.gx_orange))
        swipe.setProgressBackgroundColorSchemeColor(ContextCompat.getColor(this, R.color.gx_panel))
        // Pull-to-refresh only at the top of a page of the site, never with a dialog open or on checkout / payment
        swipe.setOnChildScrollUpCallback { _, _ ->
            webView.scrollY > 0 || !pageAllowsRefresh || pageOverlay || paying || !policy.isSite(webView.url)
        }
        swipe.setOnRefreshListener { webView.reload() }
    }

    // ------------------------------------------------------------------
    // Page events (SiteWebViewClient / SiteChromeClient / NativeBridge)
    // ------------------------------------------------------------------

    fun onPageStarted(url: String) {
        bridge?.forget()
        mainFrameError = false
        pageOverlay = false
        pageAllowsRefresh = true
        // Also after Back or a restored page (no shouldOverrideUrlLoading then)
        if (policy.isPayment(url)) paying = true else if (policy.isSite(url)) paying = false
        updateBack()
    }

    fun onPageFinished() {
        swipe.isRefreshing = false
        progress.isVisible = false
        offline.isVisible = mainFrameError
        // Save a new login / "keep me logged in" cookie straight away (the app may be closed any moment)
        CookieManager.getInstance().flush()
        updateBack()
    }

    fun onProgress(percent: Int) {
        progress.progress = percent
        progress.isVisible = percent in 1..99
    }

    fun onMainFrameError() {
        mainFrameError = true
        swipe.isRefreshing = false
        offline.isVisible = true
    }

    fun onPageState(overlay: Boolean, refresh: Boolean) {
        pageOverlay = overlay
        pageAllowsRefresh = refresh
        updateBack()
    }

    fun updateBack() {
        // When there is nothing to go back to, the system handles Back (and can show the predictive animation)
        backCallback.isEnabled = customView != null || pageOverlay || webView.canGoBack()
    }

    private fun onBack() {
        when {
            customView != null -> hideCustomView()
            pageOverlay && bridge?.connected == true -> {
                // Let the page close its dialog / cart / menu first
                backPending = true
                bridge?.send(JSONObject().put("type", "back"))
                main.postDelayed(backTimeout, 400)
            }
            else -> goBackOrLeave()
        }
    }

    fun onBackResult(handled: Boolean) {
        if (!backPending) return
        backPending = false
        main.removeCallbacks(backTimeout)
        if (!handled) {
            pageOverlay = false
            goBackOrLeave()
        }
    }

    private fun goBackOrLeave() {
        if (webView.canGoBack()) {
            webView.goBack()
        } else {
            backCallback.isEnabled = false
            onBackPressedDispatcher.onBackPressed()
        }
    }

    fun onRendererGone() {
        // The page's process crashed, or Android stopped it to free memory (often while the camera or a bank app
        // is open): rebuild this screen on the same page — in place, so it also works while the app is in the
        // background. A page that crashes it again within 10 seconds is not reopened (no loop): home instead.
        rendererGone = true
        val now = SystemClock.elapsedRealtime()
        restartUrl = if (lastRendererCrash != 0L && now - lastRendererCrash < 10_000) policy.home else (webView.url ?: policy.home)
        lastRendererCrash = now
        (webView.parent as? ViewGroup)?.removeView(webView)
        webView.destroy()
        recreate()
    }

    // ------------------------------------------------------------------
    // Links that open the app (notification, App Link, grazex://app/…)
    // ------------------------------------------------------------------

    override fun onNewIntent(intent: Intent) {
        super.onNewIntent(intent)
        urlFromIntent(intent)?.let { webView.loadUrl(it) }
    }

    private fun urlFromIntent(intent: Intent?): String? {
        if (intent == null) return null
        intent.getStringExtra(EXTRA_URL)?.let { return policy.deepLink(it) }
        return if (intent.action == Intent.ACTION_VIEW) policy.deepLink(intent.dataString) else null
    }

    /** Phone, email, WhatsApp, maps and other websites open in the right app. */
    fun openExternal(url: String) {
        try {
            val intent = if (url.startsWith("intent:", ignoreCase = true)) {
                Intent.parseUri(url, Intent.URI_INTENT_SCHEME).apply {
                    // A page may only ask for "an app that can open this", never a particular component
                    component = null
                    selector = null
                    addCategory(Intent.CATEGORY_BROWSABLE)
                }
            } else {
                Intent(Intent.ACTION_VIEW, url.toUri()).addCategory(Intent.CATEGORY_BROWSABLE)
            }
            startActivity(intent)
        } catch (_: ActivityNotFoundException) {
            val fallback = if (url.startsWith("intent:", ignoreCase = true)) {
                runCatching { Intent.parseUri(url, Intent.URI_INTENT_SCHEME).getStringExtra("browser_fallback_url") }.getOrNull()
            } else {
                null
            }
            when {
                fallback != null && policy.isSite(fallback) -> webView.loadUrl(fallback)
                fallback != null && fallback.startsWith("https://") -> openExternal(fallback)
                else -> Toast.makeText(this, R.string.no_app_for_link, Toast.LENGTH_SHORT).show()
            }
        } catch (_: URISyntaxException) {
            Toast.makeText(this, R.string.no_app_for_link, Toast.LENGTH_SHORT).show()
        }
    }

    // ------------------------------------------------------------------
    // Push notifications
    // ------------------------------------------------------------------

    fun requestPushToken(id: String) {
        if (!PushSupport.available(this)) {
            replyPush(id, null, "unavailable")
            return
        }
        if (Build.VERSION.SDK_INT >= 33 &&
            ContextCompat.checkSelfPermission(this, Manifest.permission.POST_NOTIFICATIONS) != PackageManager.PERMISSION_GRANTED
        ) {
            val prefs = getSharedPreferences(PREFS, MODE_PRIVATE)
            if (prefs.getBoolean(PREF_ASKED_NOTIFICATIONS, false)) {
                replyPush(id, null, "denied") // asked once; they can switch it on in the phone's settings
                return
            }
            pendingTokenIds += id
            if (pendingTokenIds.size == 1) {
                prefs.edit { putBoolean(PREF_ASKED_NOTIFICATIONS, true) }
                askNotifications.launch(Manifest.permission.POST_NOTIFICATIONS)
            }
            return
        }
        fetchPushToken(id)
    }

    private fun fetchPushToken(id: String) {
        if (!NotificationManagerCompat.from(this).areNotificationsEnabled()) {
            replyPush(id, null, "denied")
            return
        }
        // getToken() still works in firebase-messaging 25; its replacement register() hands the token only to the
        // messaging service, while the page needs it here (it links the phone to the login on the site)
        @Suppress("DEPRECATION")
        FirebaseMessaging.getInstance().token.addOnCompleteListener { task ->
            replyPush(id, if (task.isSuccessful) task.result else null, "unavailable")
        }
    }

    private fun replyPush(id: String, token: String?, error: String) {
        val msg = JSONObject().put("type", "push:token").put("id", id)
        if (token != null) msg.put("token", token).put("appVersion", BuildConfig.VERSION_NAME) else msg.put("error", error)
        bridge?.send(msg)
    }

    // ------------------------------------------------------------------
    // Saving files (sent by the page as base64)
    // ------------------------------------------------------------------

    fun saveFile(id: String, name: String, mime: String, base64: String) {
        val safeMime = FileNames.mime(mime)
        val safeName = FileNames.safe(name, safeMime)
        if (!FileNames.allowed(safeMime)) {
            // PDFs, photos, JSON and CSV only — never an app or a web page that would be opened straight away
            bridge?.send(JSONObject().put("type", "file:error").put("id", id).put("message", getString(R.string.cannot_save_type)))
            return
        }
        io.execute {
            val result = runCatching {
                val bytes = Base64.decode(base64, Base64.DEFAULT)
                require(bytes.isNotEmpty() && bytes.size <= MAX_FILE_BYTES) { "the file is empty or too big" }
                FileSaver.save(this, safeName, safeMime, bytes)
            }
            main.post {
                if (isFinishing || isDestroyed) return@post
                result.onSuccess { saved ->
                    bridge?.send(JSONObject().put("type", "file:saved").put("id", id).put("name", saved.name).put("where", saved.where))
                    FileSaver.open(this, saved)
                }.onFailure { e ->
                    bridge?.send(
                        JSONObject().put("type", "file:error").put("id", id).put("message", "Could not save the file (" + (e.message ?: "error") + ")."),
                    )
                }
            }
        }
    }

    // ------------------------------------------------------------------
    // Uploads: files, photos, or a new photo with the camera
    // ------------------------------------------------------------------

    fun showFileChooser(callback: ValueCallback<Array<Uri>>, params: WebChromeClient.FileChooserParams): Boolean {
        fileCallback?.onReceiveValue(null)
        fileCallback = callback
        val accepts = params.acceptTypes.flatMap { it.split(',') }.map { it.trim().lowercase() }.filter { it.contains('/') }
        val pick = Intent(Intent.ACTION_GET_CONTENT).addCategory(Intent.CATEGORY_OPENABLE).apply {
            type = accepts.singleOrNull() ?: "*/*"
            if (accepts.size > 1) putExtra(Intent.EXTRA_MIME_TYPES, accepts.toTypedArray())
            if (params.mode == WebChromeClient.FileChooserParams.MODE_OPEN_MULTIPLE) putExtra(Intent.EXTRA_ALLOW_MULTIPLE, true)
        }
        val camera = if (accepts.isEmpty() || accepts.any { it.startsWith("image/") }) cameraIntent() else null
        val chooser = Intent.createChooser(pick, getString(R.string.choose_file)).apply {
            if (camera != null) putExtra(Intent.EXTRA_INITIAL_INTENTS, arrayOf(camera))
        }
        return try {
            chooseFile.launch(chooser)
            true
        } catch (_: ActivityNotFoundException) {
            fileCallback = null
            false
        }
    }

    private fun cameraIntent(): Intent? = try {
        val dir = File(cacheDir, "camera").apply { mkdirs() }
        dir.listFiles()?.forEach { if (it.lastModified() < System.currentTimeMillis() - 86_400_000L) it.delete() }
        val file = File.createTempFile("photo-", ".jpg", dir)
        val uri = FileProvider.getUriForFile(this, "$packageName.files", file)
        cameraFile = file
        cameraUri = uri
        Intent(MediaStore.ACTION_IMAGE_CAPTURE)
            .putExtra(MediaStore.EXTRA_OUTPUT, uri)
            .addFlags(Intent.FLAG_GRANT_WRITE_URI_PERMISSION or Intent.FLAG_GRANT_READ_URI_PERMISSION)
    } catch (_: Exception) {
        null
    }

    private fun onFileChosen(resultCode: Int, data: Intent?) {
        val cb = fileCallback ?: return
        fileCallback = null
        var uris: Array<Uri>? = null
        if (resultCode == RESULT_OK) {
            val clip = data?.clipData
            uris = when {
                clip != null && clip.itemCount > 0 -> Array(clip.itemCount) { clip.getItemAt(it).uri }
                data?.data != null -> arrayOf(data.data!!)
                cameraUri != null && (cameraFile?.length() ?: 0L) > 0L -> arrayOf(cameraUri!!)
                else -> null
            }
        }
        cameraUri = null
        cameraFile = null
        cb.onReceiveValue(uris)
    }

    // ------------------------------------------------------------------
    // Location (delivery form) and full-screen video
    // ------------------------------------------------------------------

    fun onGeolocationPrompt(origin: String, callback: GeolocationPermissions.Callback) {
        if (!policy.isSite(origin)) {
            callback.invoke(origin, false, false)
            return
        }
        val perms = arrayOf(Manifest.permission.ACCESS_FINE_LOCATION, Manifest.permission.ACCESS_COARSE_LOCATION)
        if (perms.any { ContextCompat.checkSelfPermission(this, it) == PackageManager.PERMISSION_GRANTED }) {
            callback.invoke(origin, true, false)
            return
        }
        geoCallback?.invoke(geoOrigin, false, false)
        geoOrigin = origin
        geoCallback = callback
        askLocation.launch(perms)
    }

    fun showCustomView(view: View, callback: WebChromeClient.CustomViewCallback) {
        if (customView != null) {
            callback.onCustomViewHidden()
            return
        }
        customView = view
        customViewCallback = callback
        fullscreen.addView(view, FrameLayout.LayoutParams(ViewGroup.LayoutParams.MATCH_PARENT, ViewGroup.LayoutParams.MATCH_PARENT))
        fullscreen.isVisible = true
        WindowCompat.getInsetsController(window, window.decorView).apply {
            hide(WindowInsetsCompat.Type.systemBars())
            systemBarsBehavior = WindowInsetsControllerCompat.BEHAVIOR_SHOW_TRANSIENT_BARS_BY_SWIPE
        }
        updateBack()
    }

    fun hideCustomView() {
        val view = customView ?: return
        fullscreen.removeView(view)
        fullscreen.isVisible = false
        customView = null
        WindowCompat.getInsetsController(window, window.decorView).show(WindowInsetsCompat.Type.systemBars())
        customViewCallback?.onCustomViewHidden()
        customViewCallback = null
        updateBack()
    }

    // ------------------------------------------------------------------
    // Lifecycle
    // ------------------------------------------------------------------

    override fun onResume() {
        super.onResume()
        if (!rendererGone) {
            webView.onResume()
            updateBack()
        }
    }

    override fun onPause() {
        CookieManager.getInstance().flush()
        if (!rendererGone) webView.onPause()
        super.onPause()
    }

    override fun onSaveInstanceState(outState: Bundle) {
        super.onSaveInstanceState(outState)
        outState.putBoolean(STATE_PAYING, paying)
        if (rendererGone) outState.putString(STATE_RESTART_URL, restartUrl ?: policy.home) else webView.saveState(outState)
    }

    override fun onDestroy() {
        main.removeCallbacksAndMessages(null)
        io.shutdown()
        if (!rendererGone) {
            webView.stopLoading()
            webView.destroy()
        }
        super.onDestroy()
    }
}
