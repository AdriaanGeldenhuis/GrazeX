package za.co.grazex.app

import android.net.Uri
import android.webkit.WebView
import androidx.webkit.JavaScriptReplyProxy
import androidx.webkit.WebMessageCompat
import androidx.webkit.WebViewCompat
import androidx.webkit.WebViewFeature
import org.json.JSONException
import org.json.JSONObject

/**
 * The page ↔ app channel ("GXNative" in assets/js/app-bridge.js). It is injected only into pages of the site's own
 * origins (www and without www), never into payment or video frames, and only the top page is listened to.
 * The protocol (JSON strings) is described at the top of app-bridge.js.
 */
class NativeBridge(private val activity: MainActivity) : WebViewCompat.WebMessageListener {
    private var reply: JavaScriptReplyProxy? = null

    /** The current page has talked to the app (so it can be asked to close its dialog on Back). */
    val connected: Boolean get() = reply != null

    override fun onPostMessage(
        view: WebView,
        message: WebMessageCompat,
        sourceOrigin: Uri,
        isMainFrame: Boolean,
        replyProxy: JavaScriptReplyProxy,
    ) {
        if (!isMainFrame || sourceOrigin.toString().trimEnd('/') !in activity.policy.bridgeOrigins) return
        reply = replyProxy
        val msg = try {
            JSONObject(message.data ?: return)
        } catch (_: JSONException) {
            return
        }
        val id = msg.optString("id")
        when (msg.optString("type")) {
            "state" -> activity.onPageState(msg.optBoolean("overlay"), msg.optBoolean("refresh", true))
            "push:token" -> activity.requestPushToken(id)
            "file:save" -> activity.saveFile(id, msg.optString("name"), msg.optString("mime"), msg.optString("data"))
            "back:result" -> activity.onBackResult(msg.optBoolean("handled"))
        }
    }

    fun send(msg: JSONObject) {
        if (!WebViewFeature.isFeatureSupported(WebViewFeature.WEB_MESSAGE_LISTENER)) return
        try {
            reply?.postMessage(msg.toString())
        } catch (_: Exception) {
            // The page went away
        }
    }

    /** A new page is loading: answers must not go to the old one. */
    fun forget() {
        reply = null
    }
}
