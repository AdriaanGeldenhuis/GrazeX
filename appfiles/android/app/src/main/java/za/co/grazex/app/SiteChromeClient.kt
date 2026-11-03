package za.co.grazex.app

import android.net.Uri
import android.view.View
import android.webkit.GeolocationPermissions
import android.webkit.PermissionRequest
import android.webkit.ValueCallback
import android.webkit.WebChromeClient
import android.webkit.WebView

/** Uploads (proof of payment, photos), "Use my location" at checkout, full-screen video and the loading bar. */
class SiteChromeClient(private val activity: MainActivity) : WebChromeClient() {

    override fun onProgressChanged(view: WebView, newProgress: Int) = activity.onProgress(newProgress)

    override fun onShowFileChooser(
        webView: WebView,
        filePathCallback: ValueCallback<Array<Uri>>,
        fileChooserParams: FileChooserParams,
    ): Boolean = activity.showFileChooser(filePathCallback, fileChooserParams)

    override fun onGeolocationPermissionsShowPrompt(origin: String, callback: GeolocationPermissions.Callback) =
        activity.onGeolocationPrompt(origin, callback)

    /** The site never uses the camera or microphone from a page (uploads use the file chooser). */
    override fun onPermissionRequest(request: PermissionRequest) = request.deny()

    override fun onShowCustomView(view: View, callback: CustomViewCallback) = activity.showCustomView(view, callback)

    override fun onHideCustomView() = activity.hideCustomView()
}
