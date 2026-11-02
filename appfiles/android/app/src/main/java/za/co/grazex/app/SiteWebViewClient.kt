package za.co.grazex.app

import android.annotation.SuppressLint
import android.graphics.Bitmap
import android.net.http.SslError
import android.webkit.RenderProcessGoneDetail
import android.webkit.SslErrorHandler
import android.webkit.WebResourceError
import android.webkit.WebResourceRequest
import android.webkit.WebView
import android.webkit.WebViewClient

/** Page loading: which links stay in the app (UrlPolicy), errors, and a crashed page renderer. */
class SiteWebViewClient(private val activity: MainActivity) : WebViewClient() {

    override fun shouldOverrideUrlLoading(view: WebView, request: WebResourceRequest): Boolean {
        if (!request.isForMainFrame) return false
        val url = request.url.toString()
        // Returning false lets the WebView load it itself, which keeps the Referer the payment pages expect
        return when (val d = activity.policy.decide(url, activity.paying)) {
            UrlPolicy.InApp -> {
                if (activity.policy.isPayment(url)) activity.paying = true
                false
            }
            is UrlPolicy.Rewrite -> {
                view.loadUrl(d.url)
                true
            }
            is UrlPolicy.External -> {
                activity.openExternal(d.url)
                true
            }
            UrlPolicy.Block -> true
        }
    }

    override fun onPageStarted(view: WebView, url: String, favicon: Bitmap?) = activity.onPageStarted(url)

    override fun onPageFinished(view: WebView, url: String) = activity.onPageFinished()

    override fun doUpdateVisitedHistory(view: WebView, url: String?, isReload: Boolean) = activity.updateBack()

    override fun onReceivedError(view: WebView, request: WebResourceRequest, error: WebResourceError) {
        if (request.isForMainFrame) activity.onMainFrameError()
    }

    /** Never continue on a bad certificate (the default, made explicit). */
    @SuppressLint("WebViewClientOnReceivedSslError")
    override fun onReceivedSslError(view: WebView, handler: SslErrorHandler, error: SslError) = handler.cancel()

    override fun onRenderProcessGone(view: WebView, detail: RenderProcessGoneDetail): Boolean {
        activity.onRendererGone()
        return true
    }
}
